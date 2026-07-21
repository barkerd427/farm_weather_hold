<?php

declare(strict_types=1);

namespace Drupal\farm_weather_hold;

/**
 * Where the farm is: coordinates plus its IANA timezone.
 */
final class FarmCoordinates {

  public function __construct(
    public readonly float $latitude,
    public readonly float $longitude,
    public readonly string $timezone,
  ) {}

}
