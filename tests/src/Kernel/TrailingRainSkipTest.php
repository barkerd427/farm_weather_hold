<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_weather_hold\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests rule 1: trailing-rain auto-skip, category filtering, throttling.
 *
 * @group farm_weather_hold
 */
#[RunTestsInSeparateProcesses]
class TrailingRainSkipTest extends FarmWeatherHoldKernelTestBase {

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
   * Runs the runner with frozen time and a set trailing-rain amount.
   */
  private function runWithRain(float $inches, int $now = self::NOW): void {
    $this->setFrozenTime($now);
    $this->setWeather(new TestWeatherProvider(trailingInches: $inches));
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
   * Skip fires when trailing rain equals the threshold exactly.
   */
  public function testSkipFiresAtThreshold(): void {
    $log = $this->createLog([
      'name' => 'Water the 5 Bobo hydrangeas',
      'timestamp' => self::NOW - 600,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
    ]);
    $this->runWithRain(1.0);
    $log = $this->reload($log);
    $this->assertSame('done', $log->get('status')->value);
    $this->assertStringContainsString(
      'Auto-skipped — 1.00 in of rain in the past 7 days (farm_weather_hold)',
      (string) $log->get('notes')->value,
    );
    $data = json_decode((string) $log->get('data')->value, TRUE);
    $this->assertSame('skipped', $data['weather_hold']['action']);
    $this->assertSame('trailing_rain', $data['weather_hold']['reason']);
    $this->assertSame(1.0, $data['weather_hold']['rain_in']);
    $this->assertSame(7, $data['weather_hold']['window_days']);
    $this->assertNotEmpty($data['weather_hold']['checked']);
  }

  /**
   * Skip fires over threshold and its note shows the real amount.
   */
  public function testSkipFiresOverThreshold(): void {
    $log = $this->createLog([
      'timestamp' => self::NOW - 600,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
    ]);
    $this->runWithRain(1.43);
    $log = $this->reload($log);
    $this->assertSame('done', $log->get('status')->value);
    $this->assertStringContainsString('1.43 in of rain', (string) $log->get('notes')->value);
  }

  /**
   * No skip under threshold; the log is untouched.
   */
  public function testNoSkipUnderThreshold(): void {
    $log = $this->createLog([
      'timestamp' => self::NOW - 600,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
      'notes' => ['value' => 'original', 'format' => 'default'],
    ]);
    $this->runWithRain(0.99);
    $log = $this->reload($log);
    $this->assertSame('pending', $log->get('status')->value);
    $this->assertSame('original', $log->get('notes')->value);
    $this->assertTrue($log->get('data')->isEmpty());
  }

  /**
   * A due log slightly in the future (inside DUE_WINDOW) is still rule 1.
   */
  public function testDueWindowIncludesNearFuture(): void {
    $log = $this->createLog([
      'timestamp' => self::NOW + 1800,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
    ]);
    $this->runWithRain(2.0);
    $this->assertSame('done', $this->reload($log)->get('status')->value);
  }

  /**
   * Category filtering: other-category and uncategorized logs never touched.
   */
  public function testCategoryFiltering(): void {
    $other = $this->createCategoryTerm('harvest');
    $otherCategory = $this->createLog([
      'name' => 'Pick blueberries',
      'timestamp' => self::NOW - 600,
      'status' => 'pending',
      'category' => [$other->id()],
    ]);
    $uncategorized = $this->createLog([
      'name' => 'Winter prep — final deep water + disconnect hose',
      'timestamp' => self::NOW - 600,
      'status' => 'pending',
    ]);
    $this->runWithRain(5.0);
    $this->assertSame('pending', $this->reload($otherCategory)->get('status')->value);
    $this->assertSame('pending', $this->reload($uncategorized)->get('status')->value);
  }

  /**
   * Existing JSON data is merged, not clobbered.
   */
  public function testDataMergePreservesOtherKeys(): void {
    $log = $this->createLog([
      'timestamp' => self::NOW - 600,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
      'data' => '{"other_module":{"keep":true}}',
    ]);
    $this->runWithRain(2.0);
    $data = json_decode((string) $this->reload($log)->get('data')->value, TRUE);
    $this->assertTrue($data['other_module']['keep']);
    $this->assertSame('skipped', $data['weather_hold']['action']);
  }

  /**
   * Non-JSON data is left untouched; the log still completes with a note.
   */
  public function testNonJsonDataLeftAlone(): void {
    $log = $this->createLog([
      'timestamp' => self::NOW - 600,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
      'data' => 'legacy opaque blob',
    ]);
    $this->runWithRain(2.0);
    $log = $this->reload($log);
    $this->assertSame('done', $log->get('status')->value);
    $this->assertSame('legacy opaque blob', $log->get('data')->value);
    $this->assertStringContainsString('Auto-skipped', (string) $log->get('notes')->value);
  }

  /**
   * Disabling rule 1 leaves due logs alone.
   */
  public function testRuleDisabled(): void {
    $this->config('farm_weather_hold.settings')->set('trailing_enabled', FALSE)->save();
    $log = $this->createLog([
      'timestamp' => self::NOW - 600,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
    ]);
    $this->runWithRain(5.0);
    $this->assertSame('pending', $this->reload($log)->get('status')->value);
  }

  /**
   * Throttle: at most one evaluation per calendar hour.
   */
  public function testHourlyThrottle(): void {
    $first = $this->createLog([
      'timestamp' => self::NOW - 600,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
    ]);
    $this->runWithRain(2.0);
    $this->assertSame('done', $this->reload($first)->get('status')->value);

    // Second run 5 minutes later, same calendar hour: nothing happens.
    $second = $this->createLog([
      'timestamp' => self::NOW - 600,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
    ]);
    $this->runWithRain(2.0, self::NOW + 300);
    $this->assertSame('pending', $this->reload($second)->get('status')->value);

    // Next calendar hour: it runs again.
    $this->runWithRain(2.0, self::NOW + 3600);
    $this->assertSame('done', $this->reload($second)->get('status')->value);
  }

}
