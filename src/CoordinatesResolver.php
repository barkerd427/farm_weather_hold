<?php

declare(strict_types=1);

namespace Drupal\farm_weather_hold;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Resolves the farm's coordinates and timezone.
 *
 * farmOS stores no site-wide farm coordinate, so lat/lon come from module
 * config; the timezone comes from the site (system.date), falling back to
 * America/New_York.
 */
final class CoordinatesResolver {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The farm's coordinates and timezone.
   */
  public function resolve(): FarmCoordinates {
    $settings = $this->configFactory->get('farm_weather_hold.settings');
    $tz = $this->configFactory->get('system.date')->get('timezone.default') ?: 'America/New_York';
    return new FarmCoordinates(
      (float) $settings->get('latitude'),
      (float) $settings->get('longitude'),
      $tz,
    );
  }

}
