<?php

declare(strict_types=1);

namespace Drupal\farm_weather_hold\Cron;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Evaluates pending categorized logs against trailing and forecast rain.
 */
final class WeatherHoldRunner {

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Entry point from hook_cron().
   */
  public function run(): void {
  }

}
