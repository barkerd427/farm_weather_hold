<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_weather_hold\Kernel;

use Drupal\farm_weather_hold\RainForecast;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests rule 2: forecast defer, timestamp math, and the max-defer cap.
 *
 * @group farm_weather_hold
 */
#[RunTestsInSeparateProcesses]
class ForecastDeferTest extends FarmWeatherHoldKernelTestBase {

  /**
   * A fixed "now": 2026-07-21 14:30:00 America/New_York (18:30 UTC).
   */
  private const NOW = 1784658600;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('system.date')->set('timezone.default', 'America/New_York')->save();
  }

  /**
   * Runs the runner with a frozen clock and a given forecast.
   */
  private function runWithForecast(?RainForecast $forecast, float $trailing = 0.0, int $now = self::NOW): void {
    $this->setFrozenTime($now);
    $this->setWeather(new TestWeatherProvider(trailingInches: $trailing, forecast: $forecast));
    $this->container->get('farm_weather_hold.runner')->run();
  }

  /**
   * Reloads a log from storage.
   */
  private function reload(object $log): object {
    $storage = $this->container->get('entity_type.manager')->getStorage('log');
    $storage->resetCache([$log->id()]);
    return $storage->load($log->id());
  }

  /**
   * A rain forecast ending 2026-07-22 15:00 EDT (0.8 in, 75 %).
   */
  private function wetForecast(): RainForecast {
    $rainEnd = new \DateTimeImmutable('2026-07-22 15:00:00', new \DateTimeZone('America/New_York'));
    return new RainForecast(0.8, 75, $rainEnd);
  }

  /**
   * A coming-due log is deferred to the day after the rain ends.
   */
  public function testDefersComingDueLog(): void {
    // Due tomorrow 14:30 EDT — inside the 48 h horizon, outside DUE_WINDOW.
    $log = $this->createLog([
      'name' => 'Water the 4 hinoki cypress',
      'timestamp' => self::NOW + 86400,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
    ]);
    $this->runWithForecast($this->wetForecast());
    $log = $this->reload($log);
    $this->assertSame('pending', $log->get('status')->value);
    // Rain ends 07-22; day after is 07-23 at the original 14:30 EDT.
    $this->assertSame(self::NOW + 2 * 86400, (int) $log->get('timestamp')->value);
    $this->assertStringContainsString(
      'Deferred — 0.80 in forecast by 2026-07-22 (farm_weather_hold)',
      (string) $log->get('notes')->value,
    );
    $data = json_decode((string) $log->get('data')->value, TRUE);
    $this->assertSame('deferred', $data['weather_hold']['action']);
    $this->assertSame('forecast_rain', $data['weather_hold']['reason']);
    $this->assertSame(0.8, $data['weather_hold']['rain_in']);
    $this->assertSame(1, $data['weather_hold']['defer_count']);
    $this->assertNotEmpty($data['weather_hold']['checked']);
  }

  /**
   * No defer when forecast rain is under the inch threshold.
   */
  public function testNoDeferUnderThreshold(): void {
    $log = $this->createLog([
      'timestamp' => self::NOW + 86400,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
    ]);
    $rainEnd = new \DateTimeImmutable('2026-07-22 15:00:00', new \DateTimeZone('America/New_York'));
    $this->runWithForecast(new RainForecast(0.4, 90, $rainEnd));
    $this->assertSame(self::NOW + 86400, (int) $this->reload($log)->get('timestamp')->value);
  }

  /**
   * No defer when probability is under the threshold.
   */
  public function testNoDeferLowProbability(): void {
    $log = $this->createLog([
      'timestamp' => self::NOW + 86400,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
    ]);
    $rainEnd = new \DateTimeImmutable('2026-07-22 15:00:00', new \DateTimeZone('America/New_York'));
    $this->runWithForecast(new RainForecast(0.8, 50, $rainEnd));
    $this->assertSame(self::NOW + 86400, (int) $this->reload($log)->get('timestamp')->value);
  }

  /**
   * A log already due is rule-1 territory: never deferred.
   */
  public function testDueNowLogIsNotDeferred(): void {
    $log = $this->createLog([
      'timestamp' => self::NOW - 600,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
    ]);
    $this->runWithForecast($this->wetForecast(), trailing: 0.0);
    $log = $this->reload($log);
    $this->assertSame('pending', $log->get('status')->value);
    $this->assertSame(self::NOW - 600, (int) $log->get('timestamp')->value);
  }

  /**
   * A log beyond the look-ahead horizon is untouched.
   */
  public function testBeyondHorizonUntouched(): void {
    $log = $this->createLog([
      'timestamp' => self::NOW + 60 * 3600,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
    ]);
    $this->runWithForecast($this->wetForecast());
    $this->assertSame(self::NOW + 60 * 3600, (int) $this->reload($log)->get('timestamp')->value);
  }

  /**
   * Defer increments defer_count; the cap stops further defers.
   */
  public function testMaxDeferCap(): void {
    $atCap = $this->createLog([
      'timestamp' => self::NOW + 86400,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
      'data' => '{"weather_hold":{"action":"deferred","defer_count":2}}',
    ]);
    $belowCap = $this->createLog([
      'timestamp' => self::NOW + 86400,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
      'data' => '{"weather_hold":{"action":"deferred","defer_count":1}}',
    ]);
    $this->runWithForecast($this->wetForecast());
    // At the cap (max_defers = 2): left due for the human to decide.
    $this->assertSame(self::NOW + 86400, (int) $this->reload($atCap)->get('timestamp')->value);
    // Below the cap: deferred again, count now 2.
    $reloaded = $this->reload($belowCap);
    $this->assertSame(self::NOW + 2 * 86400, (int) $reloaded->get('timestamp')->value);
    $data = json_decode((string) $reloaded->get('data')->value, TRUE);
    $this->assertSame(2, $data['weather_hold']['defer_count']);
  }

  /**
   * Disabling rule 2 leaves coming-due logs alone.
   */
  public function testRuleDisabled(): void {
    $this->config('farm_weather_hold.settings')->set('forecast_enabled', FALSE)->save();
    $log = $this->createLog([
      'timestamp' => self::NOW + 86400,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
    ]);
    $this->runWithForecast($this->wetForecast());
    $this->assertSame(self::NOW + 86400, (int) $this->reload($log)->get('timestamp')->value);
  }

}
