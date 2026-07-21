<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_weather_hold\Kernel;

use Drupal\farm_weather_hold\FarmCoordinates;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Open-Meteo weather provider: request shape and caching.
 *
 * @group farm_weather_hold
 */
#[RunTestsInSeparateProcesses]
class OpenMeteoProviderTest extends FarmWeatherHoldKernelTestBase {

  /**
   * Requests made through the mocked HTTP client.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $requests = [];

  /**
   * Installs a Guzzle MockHandler returning the given JSON bodies in order.
   */
  private function mockHttp(array $jsonBodies): void {
    $mock = new MockHandler(array_map(
      static fn (array $body): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode($body)),
      $jsonBodies,
    ));
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($this->requests));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    // Force re-instantiation with the mocked client.
    $this->container->set('farm_weather_hold.weather_provider', NULL);
  }

  /**
   * Trailing rain sums daily precipitation from the past_days window.
   */
  public function testTrailingRainRequestAndSum(): void {
    $this->mockHttp([
      [
        'daily' => [
          'time' => [
            '2026-07-14', '2026-07-15', '2026-07-16', '2026-07-17',
            '2026-07-18', '2026-07-19', '2026-07-20', '2026-07-21',
          ],
          'precipitation_sum' => [0.1, 0.0, 0.55, 0.0, 0.3, 0.25, 0.0, 0.0],
        ],
      ],
    ]);
    $coords = new FarmCoordinates(42.5, -72.5, 'America/New_York');
    $provider = $this->container->get('farm_weather_hold.weather_provider');
    // Today's (last) partial value is included: 0.1+0.55+0.3+0.25 = 1.2.
    $this->assertEqualsWithDelta(1.2, $provider->trailingRainInches($coords, 7), 0.001);

    $uri = (string) $this->requests[0]['request']->getUri();
    $this->assertStringContainsString('api.open-meteo.com/v1/forecast', $uri);
    $this->assertStringContainsString('latitude=42.5', $uri);
    $this->assertStringContainsString('longitude=-72.5', $uri);
    $this->assertStringContainsString('past_days=7', $uri);
    $this->assertStringContainsString('daily=precipitation_sum', $uri);
    $this->assertStringContainsString('precipitation_unit=inch', $uri);
    $this->assertStringContainsString('timezone=America%2FNew_York', $uri);
  }

  /**
   * Forecast aggregates hourly precip, max probability, and rain end.
   */
  public function testForecastAggregation(): void {
    // Freeze at 2026-07-21 09:00 EDT (13:00 UTC) so the mocked hours fall
    // inside the [now − 1 h, now + horizon] window on any real run date.
    $this->setFrozenTime(1784638800);
    $this->mockHttp([
      [
        'hourly' => [
          'time' => ['2026-07-21T10:00', '2026-07-21T11:00', '2026-07-21T12:00', '2026-07-21T13:00'],
          'precipitation' => [0.0, 0.3, 0.4, 0.0],
          'precipitation_probability' => [10, 70, 80, 20],
        ],
      ],
    ]);
    $coords = new FarmCoordinates(42.5, -72.5, 'America/New_York');
    $forecast = $this->container->get('farm_weather_hold.weather_provider')->rainForecast($coords, 48);
    $this->assertEqualsWithDelta(0.7, $forecast->totalInches, 0.001);
    $this->assertSame(80, $forecast->maxProbabilityPercent);
    // Last wet hour starts 12:00 local; rainEnd is the END of that hour.
    $this->assertSame('2026-07-21T13:00:00-04:00', $forecast->rainEnd->format('c'));

    $uri = (string) $this->requests[0]['request']->getUri();
    $this->assertStringContainsString('hourly=precipitation%2Cprecipitation_probability', $uri);
    $this->assertStringContainsString('forecast_days=3', $uri);
  }

  /**
   * A dry forecast has a NULL rainEnd.
   */
  public function testDryForecastHasNullRainEnd(): void {
    $this->setFrozenTime(1784638800);
    $this->mockHttp([
      [
        'hourly' => [
          'time' => ['2026-07-21T10:00', '2026-07-21T11:00'],
          'precipitation' => [0.0, 0.0],
          'precipitation_probability' => [5, 10],
        ],
      ],
    ]);
    $coords = new FarmCoordinates(42.5, -72.5, 'America/New_York');
    $forecast = $this->container->get('farm_weather_hold.weather_provider')->rainForecast($coords, 48);
    $this->assertSame(0.0, $forecast->totalInches);
    $this->assertNull($forecast->rainEnd);
  }

  /**
   * Responses are cached: a second identical call makes no second request.
   */
  public function testResponsesAreCached(): void {
    $this->mockHttp([
      ['daily' => ['time' => ['2026-07-21'], 'precipitation_sum' => [0.5]]],
    ]);
    $coords = new FarmCoordinates(42.5, -72.5, 'America/New_York');
    $provider = $this->container->get('farm_weather_hold.weather_provider');
    $provider->trailingRainInches($coords, 7);
    $provider->trailingRainInches($coords, 7);
    $this->assertCount(1, $this->requests);
  }

  /**
   * The coordinates resolver merges config coords with the site timezone.
   */
  public function testCoordinatesResolver(): void {
    $this->config('system.date')->set('timezone.default', 'America/New_York')->save();
    $coords = $this->container->get('farm_weather_hold.coordinates_resolver')->resolve();
    $this->assertSame(42.5, $coords->latitude);
    $this->assertSame(-72.5, $coords->longitude);
    $this->assertSame('America/New_York', $coords->timezone);

    $this->config('farm_weather_hold.settings')
      ->set('latitude', 40.0)->set('longitude', -75.0)->save();
    $coords = $this->container->get('farm_weather_hold.coordinates_resolver')->resolve();
    $this->assertSame(40.0, $coords->latitude);
    $this->assertSame(-75.0, $coords->longitude);
  }

}
