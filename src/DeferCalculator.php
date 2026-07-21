<?php

declare(strict_types=1);

namespace Drupal\farm_weather_hold;

/**
 * Computes the deferred due timestamp for a forecast-rain defer.
 *
 * The new due moment is the calendar day AFTER the day the forecast rain
 * ends (in the farm timezone), at the original log's local time-of-day.
 * All math is done on local wall-clock dates via DateTimeImmutable so DST
 * transitions cannot shift the target day.
 */
final class DeferCalculator {

  /**
   * The deferred due timestamp.
   *
   * @param int $originalTimestamp
   *   The log's current due timestamp (UTC epoch seconds).
   * @param \DateTimeImmutable $rainEnd
   *   End of the last forecast rain hour.
   * @param \DateTimeZone $tz
   *   The farm timezone.
   *
   * @return int
   *   The new due timestamp (UTC epoch seconds).
   */
  public function deferredTimestamp(int $originalTimestamp, \DateTimeImmutable $rainEnd, \DateTimeZone $tz): int {
    $original = (new \DateTimeImmutable("@{$originalTimestamp}"))->setTimezone($tz);
    $dayAfter = $rainEnd->setTimezone($tz)->modify('+1 day')->format('Y-m-d');
    $deferred = new \DateTimeImmutable($dayAfter . ' ' . $original->format('H:i:s'), $tz);
    return $deferred->getTimestamp();
  }

}
