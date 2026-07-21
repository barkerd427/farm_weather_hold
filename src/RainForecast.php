<?php

declare(strict_types=1);

namespace Drupal\farm_weather_hold;

/**
 * Aggregated rain forecast over a look-ahead horizon.
 */
final class RainForecast {

  /**
   * Constructs a RainForecast.
   *
   * @param float $totalInches
   *   Total forecast precipitation (inches) inside the horizon.
   * @param int $maxProbabilityPercent
   *   The highest hourly precipitation probability inside the horizon.
   * @param \DateTimeImmutable|null $rainEnd
   *   End of the last hour with precipitation > 0, in farm timezone; NULL
   *   when no rain is forecast inside the horizon.
   */
  public function __construct(
    public readonly float $totalInches,
    public readonly int $maxProbabilityPercent,
    public readonly ?\DateTimeImmutable $rainEnd,
  ) {}

}
