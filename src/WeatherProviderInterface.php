<?php

declare(strict_types=1);

namespace Drupal\farm_weather_hold;

/**
 * Contract for a precipitation data source.
 *
 * Swappable so tests can mock it and other providers can be added.
 */
interface WeatherProviderInterface {

  /**
   * Total precipitation (inches) over the trailing window, including today.
   */
  public function trailingRainInches(FarmCoordinates $coords, int $days): float;

  /**
   * Aggregated rain forecast inside the next $hours hours.
   */
  public function rainForecast(FarmCoordinates $coords, int $hours): RainForecast;

}
