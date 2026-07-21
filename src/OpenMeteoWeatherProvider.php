<?php

declare(strict_types=1);

namespace Drupal\farm_weather_hold;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Open-Meteo precipitation source (no API key).
 *
 * Trailing rain deliberately uses the forecast endpoint's past_days rather
 * than the archive endpoint: the historical archive lags real time by ~5
 * days, which would blind a 7-day trailing window to the most recent rain.
 * Responses are cached for an hour so cron re-runs don't hammer the API.
 */
final class OpenMeteoWeatherProvider implements WeatherProviderInterface {

  private const BASE_URL = 'https://api.open-meteo.com/v1/forecast';
  private const CACHE_TTL = 3600;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function trailingRainInches(FarmCoordinates $coords, int $days): float {
    $data = $this->fetch($coords, [
      'daily' => 'precipitation_sum',
      'past_days' => $days,
      'forecast_days' => 1,
    ]);
    $sums = $data['daily']['precipitation_sum'] ?? [];
    // The final entry is today's (partial) total; include it — rain that
    // already fell today counts toward the trailing window.
    return array_sum(array_map('floatval', array_filter($sums, is_numeric(...))));
  }

  /**
   * {@inheritdoc}
   */
  public function rainForecast(FarmCoordinates $coords, int $hours): RainForecast {
    // Open-Meteo caps forecast_days at 16; scale it to the requested
    // horizon (plus a day of margin) instead of hardcoding 72 h, so a
    // lookahead_hours beyond 72 doesn't silently truncate the forecast.
    $forecastDays = min(16, (int) ceil($hours / 24) + 1);
    $data = $this->fetch($coords, [
      'hourly' => 'precipitation,precipitation_probability',
      'forecast_days' => $forecastDays,
    ]);
    $tz = new \DateTimeZone($coords->timezone);
    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($tz);
    $horizonEnd = $now->modify("+{$hours} hours");

    $total = 0.0;
    $maxProbability = 0;
    $rainEnd = NULL;
    $times = $data['hourly']['time'] ?? [];
    foreach ($times as $i => $timeString) {
      $hourStart = new \DateTimeImmutable($timeString, $tz);
      if ($hourStart < $now->modify('-1 hour') || $hourStart > $horizonEnd) {
        continue;
      }
      $precip = (float) ($data['hourly']['precipitation'][$i] ?? 0.0);
      $probability = (int) ($data['hourly']['precipitation_probability'][$i] ?? 0);
      $total += $precip;
      $maxProbability = max($maxProbability, $probability);
      if ($precip > 0.0) {
        $rainEnd = $hourStart->modify('+1 hour');
      }
    }
    return new RainForecast($total, $maxProbability, $rainEnd);
  }

  /**
   * Fetches (or returns cached) JSON from Open-Meteo.
   *
   * Runs inside hook_cron, so a hung or rate-limited endpoint must never
   * stall cron: failures are logged and an empty array is returned instead
   * of propagating. The trailing/forecast callers' `?? []` / `?? 0.0`
   * guards make an empty result safe (no skips or defers happen). Failures
   * are deliberately NOT cached, so the next cron tick retries.
   *
   * @param \Drupal\farm_weather_hold\FarmCoordinates $coords
   *   The farm coordinates.
   * @param array $params
   *   Endpoint-specific query parameters.
   *
   * @return array
   *   The decoded response body, or [] on failure.
   */
  private function fetch(FarmCoordinates $coords, array $params): array {
    $query = $params + [
      'latitude' => $coords->latitude,
      'longitude' => $coords->longitude,
      'precipitation_unit' => 'inch',
      'timezone' => $coords->timezone,
    ];
    ksort($query);
    $cid = 'farm_weather_hold:open_meteo:' . hash('sha256', serialize($query));
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    try {
      $response = $this->httpClient->request('GET', self::BASE_URL, [
        'query' => $query,
        'timeout' => 10,
        'connect_timeout' => 5,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($data)) {
        throw new \RuntimeException('Open-Meteo returned a non-JSON response.');
      }
    }
    catch (GuzzleException | \RuntimeException $e) {
      $this->loggerFactory->get('farm_weather_hold')
        ->warning('Open-Meteo request failed: @message', ['@message' => $e->getMessage()]);
      return [];
    }
    $this->cache->set($cid, $data, $this->time->getRequestTime() + self::CACHE_TTL);
    return $data;
  }

}
