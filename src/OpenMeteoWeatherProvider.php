<?php

declare(strict_types=1);

namespace Drupal\farm_weather_hold;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\ClientInterface;

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
    $data = $this->fetch($coords, [
      'hourly' => 'precipitation,precipitation_probability',
      'forecast_days' => 3,
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
   * @param \Drupal\farm_weather_hold\FarmCoordinates $coords
   *   The farm coordinates.
   * @param array $params
   *   Endpoint-specific query parameters.
   *
   * @return array
   *   The decoded response body.
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
    $response = $this->httpClient->request('GET', self::BASE_URL, ['query' => $query]);
    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data)) {
      throw new \RuntimeException('Open-Meteo returned a non-JSON response.');
    }
    $this->cache->set($cid, $data, $this->time->getRequestTime() + self::CACHE_TTL);
    return $data;
  }

}
