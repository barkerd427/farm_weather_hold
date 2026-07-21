<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_weather_hold\Kernel;

use Drupal\farm_weather_hold\FarmCoordinates;
use Drupal\farm_weather_hold\RainForecast;
use Drupal\farm_weather_hold\WeatherProviderInterface;

/**
 * Settable in-memory weather provider for kernel tests.
 */
final class TestWeatherProvider implements WeatherProviderInterface {

  public function __construct(
    public float $trailingInches = 0.0,
    public ?RainForecast $forecast = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function trailingRainInches(FarmCoordinates $coords, int $days): float {
    return $this->trailingInches;
  }

  /**
   * {@inheritdoc}
   */
  public function rainForecast(FarmCoordinates $coords, int $hours): RainForecast {
    return $this->forecast ?? new RainForecast(0.0, 0, NULL);
  }

}
