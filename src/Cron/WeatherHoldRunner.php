<?php

declare(strict_types=1);

namespace Drupal\farm_weather_hold\Cron;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\farm_weather_hold\CoordinatesResolver;
use Drupal\farm_weather_hold\DeferCalculator;
use Drupal\farm_weather_hold\FarmCoordinates;
use Drupal\farm_weather_hold\WeatherProviderInterface;

/**
 * Evaluates pending categorized logs against trailing and forecast rain.
 *
 * Runs from hook_cron(), throttled with State to at most one evaluation per
 * calendar hour (farm timezone), like farm_digest's sender.
 */
final class WeatherHoldRunner {

  /**
   * Seconds into the future a log still counts as "due" for rule 1.
   *
   * One hour: exactly the cron/throttle tick granularity.
   */
  public const DUE_WINDOW = 3600;

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly WeatherProviderInterface $weatherProvider,
    private readonly CoordinatesResolver $coordinatesResolver,
    private readonly DeferCalculator $deferCalculator,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Entry point from hook_cron().
   */
  public function run(): void {
    $config = $this->configFactory->get('farm_weather_hold.settings');
    if (!$config->get('trailing_enabled') && !$config->get('forecast_enabled')) {
      return;
    }
    $now = $this->time->getRequestTime();
    $coords = $this->coordinatesResolver->resolve();
    if (!$this->dueNow($now, $coords)) {
      return;
    }
    $tids = $this->watchedTermIds($config);
    if ($tids === []) {
      $this->loggerFactory->get('farm_weather_hold')
        ->warning('No configured log_category terms resolved; nothing to do.');
      return;
    }
    if ($config->get('trailing_enabled')) {
      $this->trailingRainSkip($config, $coords, $tids, $now);
    }
    if ($config->get('forecast_enabled')) {
      $this->forecastDefer($config, $coords, $tids, $now);
    }
    $this->state->set('farm_weather_hold.last_run', $now);
  }

  /**
   * Throttle: at most one evaluation per calendar hour, farm timezone.
   */
  private function dueNow(int $now, FarmCoordinates $coords): bool {
    $lastRun = (int) $this->state->get('farm_weather_hold.last_run', 0);
    if ($lastRun === 0) {
      return TRUE;
    }
    $tz = new \DateTimeZone($coords->timezone);
    $lastLocal = (new \DateTimeImmutable("@{$lastRun}"))->setTimezone($tz);
    $nowLocal = (new \DateTimeImmutable("@{$now}"))->setTimezone($tz);
    return $lastLocal->format('Y-m-d H') !== $nowLocal->format('Y-m-d H');
  }

  /**
   * Resolves configured category names to term ids in log_category.
   *
   * Exact-name resolution within the vocabulary — this is term matching,
   * never log-name matching.
   *
   * @return int[]
   *   The term ids.
   */
  private function watchedTermIds(ImmutableConfig $config): array {
    $names = $config->get('categories') ?: [];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = [];
    foreach ($names as $name) {
      foreach ($storage->loadByProperties(['vid' => 'log_category', 'name' => $name]) as $term) {
        $tids[] = (int) $term->id();
      }
    }
    return $tids;
  }

  /**
   * Rule 1: complete due logs when trailing rain meets the threshold.
   */
  private function trailingRainSkip(ImmutableConfig $config, FarmCoordinates $coords, array $tids, int $now): void {
    $ids = $this->entityTypeManager->getStorage('log')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 'pending')
      ->condition('category', $tids, 'IN')
      ->condition('timestamp', $now + self::DUE_WINDOW, '<=')
      ->sort('timestamp', 'ASC')
      ->execute();
    if ($ids === []) {
      return;
    }
    $days = (int) $config->get('lookback_days');
    $rain = $this->weatherProvider->trailingRainInches($coords, $days);
    if ($rain < (float) $config->get('trailing_threshold_in')) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('log');
    foreach ($storage->loadMultiple($ids) as $log) {
      $log->set('status', 'done');
      $this->appendNote($log, sprintf(
        'Auto-skipped — %.2f in of rain in the past %d days (farm_weather_hold)',
        $rain,
        $days,
      ));
      $this->mergeWeatherHoldData($log, [
        'action' => 'skipped',
        'reason' => 'trailing_rain',
        'rain_in' => round($rain, 2),
        'window_days' => $days,
        'checked' => $this->isoNow($now, $coords),
      ]);
      $log->setNewRevision(TRUE);
      $log->save();
      $this->loggerFactory->get('farm_weather_hold')
        ->info('Auto-skipped log @id (@label): @rain in of rain.', [
          '@id' => $log->id(),
          '@label' => $log->label(),
          '@rain' => round($rain, 2),
        ]);
    }
  }

  /**
   * Rule 2: defer coming-due logs past forecast rain, capped per log.
   */
  private function forecastDefer(ImmutableConfig $config, FarmCoordinates $coords, array $tids, int $now): void {
    $lookaheadHours = (int) $config->get('lookahead_hours');
    $ids = $this->entityTypeManager->getStorage('log')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 'pending')
      ->condition('category', $tids, 'IN')
      ->condition('timestamp', $now + self::DUE_WINDOW, '>')
      ->condition('timestamp', $now + $lookaheadHours * 3600, '<=')
      ->sort('timestamp', 'ASC')
      ->execute();
    if ($ids === []) {
      return;
    }
    $forecast = $this->weatherProvider->rainForecast($coords, $lookaheadHours);
    if ($forecast->rainEnd === NULL
      || $forecast->totalInches < (float) $config->get('forecast_threshold_in')
      || $forecast->maxProbabilityPercent < (int) $config->get('forecast_probability')) {
      return;
    }
    $tz = new \DateTimeZone($coords->timezone);
    $maxDefers = (int) $config->get('max_defers');
    $storage = $this->entityTypeManager->getStorage('log');
    foreach ($storage->loadMultiple($ids) as $log) {
      $deferCount = (int) ($this->weatherHoldData($log)['defer_count'] ?? 0);
      if ($deferCount >= $maxDefers) {
        // Cap reached: leave the log due and let the human decide.
        continue;
      }
      $newTimestamp = $this->deferCalculator->deferredTimestamp(
        (int) $log->get('timestamp')->value,
        $forecast->rainEnd,
        $tz,
      );
      if ($newTimestamp <= (int) $log->get('timestamp')->value) {
        // Rain ends before the log was due anyway; deferring would move it
        // earlier. Leave it — rule 1 will evaluate it when it comes due.
        continue;
      }
      $log->set('timestamp', $newTimestamp);
      $rainByDate = $forecast->rainEnd->setTimezone($tz)->format('Y-m-d');
      $this->appendNote($log, sprintf(
        'Deferred — %.2f in forecast by %s (farm_weather_hold)',
        $forecast->totalInches,
        $rainByDate,
      ));
      $this->mergeWeatherHoldData($log, [
        'action' => 'deferred',
        'reason' => 'forecast_rain',
        'rain_in' => round($forecast->totalInches, 2),
        'rain_by' => $rainByDate,
        'defer_count' => $deferCount + 1,
        'checked' => $this->isoNow($now, $coords),
      ]);
      $log->setNewRevision(TRUE);
      $log->save();
      $this->loggerFactory->get('farm_weather_hold')
        ->info('Deferred log @id (@label) to @date: @rain in forecast.', [
          '@id' => $log->id(),
          '@label' => $log->label(),
          '@date' => $rainByDate,
          '@rain' => round($forecast->totalInches, 2),
        ]);
    }
  }

  /**
   * The existing weather_hold block from a log's data field, or [].
   */
  private function weatherHoldData(object $log): array {
    $raw = $log->get('data')->value;
    if ($raw === NULL || $raw === '') {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) && is_array($decoded['weather_hold'] ?? NULL)
      ? $decoded['weather_hold']
      : [];
  }

  /**
   * The check time as ISO 8601 in the farm timezone.
   */
  private function isoNow(int $now, FarmCoordinates $coords): string {
    return (new \DateTimeImmutable("@{$now}"))
      ->setTimezone(new \DateTimeZone($coords->timezone))
      ->format('c');
  }

  /**
   * Appends a line to a log's notes, preserving existing text and format.
   */
  private function appendNote(object $log, string $message): void {
    $existing = $log->get('notes')->value;
    $format = $log->get('notes')->format ?? 'default';
    $log->set('notes', [
      'value' => $existing ? $existing . "\n\n" . $message : $message,
      'format' => $format,
    ]);
  }

  /**
   * Merges a weather_hold block into the log's data JSON.
   *
   * Never destroys non-JSON data another module may have stored: in that
   * case data is left untouched and a warning is logged (the note still
   * records the action).
   */
  private function mergeWeatherHoldData(object $log, array $block): void {
    $raw = $log->get('data')->value;
    $data = [];
    if ($raw !== NULL && $raw !== '') {
      // Decode without assoc first: json_decode($raw, TRUE) cannot tell a
      // JSON array ("[1,2,3]") from a JSON object ("{}"), and a scalar
      // ("5", "true") is not JSON we should merge into either. Only a real
      // JSON object is safe to treat as this log's data map.
      if (!is_object(json_decode($raw))) {
        $this->loggerFactory->get('farm_weather_hold')
          ->warning('Log @id data field is not a JSON object; leaving it untouched.', ['@id' => $log->id()]);
        return;
      }
      $data = json_decode($raw, TRUE);
    }
    $data['weather_hold'] = $block + ($data['weather_hold'] ?? []);
    $log->set('data', json_encode($data, JSON_PRESERVE_ZERO_FRACTION));
  }

}
