<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_weather_hold\Unit;

use Drupal\farm_weather_hold\DeferCalculator;
use Drupal\Tests\UnitTestCase;

/**
 * Tests defer-date math, especially across DST transitions.
 *
 * @group farm_weather_hold
 */
class DeferCalculatorTest extends UnitTestCase {

  /**
   * The calculator under test.
   */
  private DeferCalculator $calculator;

  /**
   * The farm timezone used throughout.
   */
  private \DateTimeZone $tz;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->calculator = new DeferCalculator();
    $this->tz = new \DateTimeZone('America/New_York');
  }

  /**
   * Plain case: day after rain end, original local time-of-day preserved.
   */
  public function testDayAfterRainEndKeepsTimeOfDay(): void {
    $original = new \DateTimeImmutable('2026-07-22 06:30:00', $this->tz);
    $rainEnd = new \DateTimeImmutable('2026-07-23 15:00:00', $this->tz);
    $result = $this->calculator->deferredTimestamp($original->getTimestamp(), $rainEnd, $this->tz);
    $local = (new \DateTimeImmutable("@{$result}"))->setTimezone($this->tz);
    $this->assertSame('2026-07-24 06:30:00', $local->format('Y-m-d H:i:s'));
  }

  /**
   * Spring forward: target day is only 23 h long; local date math holds.
   *
   * 2026-03-08 is the US spring-forward date. A naive "+ N*86400 seconds"
   * would land at the wrong local hour; the calculator must produce the
   * correct local calendar day.
   */
  public function testSpringForwardKeepsLocalDate(): void {
    $original = new \DateTimeImmutable('2026-03-06 06:30:00', $this->tz);
    $rainEnd = new \DateTimeImmutable('2026-03-07 20:00:00', $this->tz);
    $result = $this->calculator->deferredTimestamp($original->getTimestamp(), $rainEnd, $this->tz);
    $local = (new \DateTimeImmutable("@{$result}"))->setTimezone($this->tz);
    $this->assertSame('2026-03-08', $local->format('Y-m-d'));
    $this->assertSame('06:30:00', $local->format('H:i:s'));
    // EDT after the transition.
    $this->assertSame('-04:00', $local->format('P'));
  }

  /**
   * Fall back: 25-hour day; still lands on the correct local date and time.
   *
   * 2026-11-01 is the US fall-back date.
   */
  public function testFallBackKeepsLocalDate(): void {
    $original = new \DateTimeImmutable('2026-10-30 06:30:00', $this->tz);
    $rainEnd = new \DateTimeImmutable('2026-10-31 22:00:00', $this->tz);
    $result = $this->calculator->deferredTimestamp($original->getTimestamp(), $rainEnd, $this->tz);
    $local = (new \DateTimeImmutable("@{$result}"))->setTimezone($this->tz);
    $this->assertSame('2026-11-01 06:30:00', $local->format('Y-m-d H:i:s'));
  }

  /**
   * A rainEnd exactly at midnight belongs to the day it starts.
   *
   * Rain ending at 2026-07-23T00:00 means the last wet hour was 23:00–24:00
   * on the 22nd; midnight's date is already the 23rd, so "day after" is the
   * 24th — deliberately conservative by one day at this boundary.
   */
  public function testMidnightRainEndIsConservative(): void {
    $original = new \DateTimeImmutable('2026-07-22 06:30:00', $this->tz);
    $rainEnd = new \DateTimeImmutable('2026-07-23 00:00:00', $this->tz);
    $result = $this->calculator->deferredTimestamp($original->getTimestamp(), $rainEnd, $this->tz);
    $local = (new \DateTimeImmutable("@{$result}"))->setTimezone($this->tz);
    $this->assertSame('2026-07-24', $local->format('Y-m-d'));
  }

}
