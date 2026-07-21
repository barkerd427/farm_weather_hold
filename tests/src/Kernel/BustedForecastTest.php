<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_weather_hold\Kernel;

use Drupal\farm_weather_hold\RainForecast;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * End-to-end: defer → rain materializes (skip) or busts (human waters).
 *
 * @group farm_weather_hold
 */
#[RunTestsInSeparateProcesses]
class BustedForecastTest extends FarmWeatherHoldKernelTestBase {

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
   * Runs the runner at a moment with given weather.
   */
  private function runAt(int $now, float $trailing, ?RainForecast $forecast): void {
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
   * Defer, then the rain materializes: rule 1 completes the log.
   */
  public function testDeferThenRainMaterializesCompletes(): void {
    $log = $this->createLog([
      'timestamp' => self::NOW + 86400,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
    ]);
    // Day 0: heavy rain forecast for tomorrow → defer to day +2.
    $rainEnd = new \DateTimeImmutable('2026-07-22 15:00:00', new \DateTimeZone('America/New_York'));
    $this->runAt(self::NOW, 0.0, new RainForecast(0.9, 80, $rainEnd));
    $this->assertSame(self::NOW + 2 * 86400, (int) $this->reload($log)->get('timestamp')->value);

    // Day +2 (log now due): the rain fell — 1.2 in trailing → auto-skip.
    $this->runAt(self::NOW + 2 * 86400 + 300, 1.2, NULL);
    $log = $this->reload($log);
    $this->assertSame('done', $log->get('status')->value);
    $notes = (string) $log->get('notes')->value;
    $this->assertStringContainsString('Deferred — 0.90 in forecast by 2026-07-22', $notes);
    $this->assertStringContainsString('Auto-skipped — 1.20 in of rain in the past 7 days', $notes);
    $data = json_decode((string) $log->get('data')->value, TRUE);
    $this->assertSame('skipped', $data['weather_hold']['action']);
    // The defer history survives the merge.
    $this->assertSame(1, $data['weather_hold']['defer_count']);
  }

  /**
   * Defer, then the forecast busts: the log comes due and is left for the
   * human, and once they mark it done the module never touches it again.
   */
  public function testDeferThenBustLeavesLogDue(): void {
    $log = $this->createLog([
      'timestamp' => self::NOW + 86400,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
    ]);
    $rainEnd = new \DateTimeImmutable('2026-07-22 15:00:00', new \DateTimeZone('America/New_York'));
    $this->runAt(self::NOW, 0.0, new RainForecast(0.9, 80, $rainEnd));
    $deferredTo = (int) $this->reload($log)->get('timestamp')->value;
    $this->assertSame(self::NOW + 2 * 86400, $deferredTo);

    // Day +2: the rain never came (0.2 in trailing, dry forecast). Rule 1
    // must NOT complete; rule 2 must not re-defer (no rain end). The log
    // stays pending and due — the person waters.
    $this->runAt(self::NOW + 2 * 86400 + 300, 0.2, NULL);
    $log = $this->reload($log);
    $this->assertSame('pending', $log->get('status')->value);
    $this->assertSame($deferredTo, (int) $log->get('timestamp')->value);

    // The human waters and marks it done.
    $log->set('status', 'done');
    $log->save();

    // A later rainy run leaves the done log exactly as the human saved it.
    $notesBefore = (string) $this->reload($log)->get('notes')->value;
    $this->runAt(self::NOW + 2 * 86400 + 3900, 3.0, NULL);
    $log = $this->reload($log);
    $this->assertSame('done', $log->get('status')->value);
    $this->assertSame($notesBefore, (string) $log->get('notes')->value);
  }

}
