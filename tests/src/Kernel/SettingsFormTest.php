<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_weather_hold\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\farm_weather_hold\Form\SettingsForm;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the admin settings form saves every configured knob.
 *
 * @group farm_weather_hold
 */
#[RunTestsInSeparateProcesses]
class SettingsFormTest extends FarmWeatherHoldKernelTestBase {

  /**
   * Submitting the form persists all settings.
   */
  public function testSubmitPersistsSettings(): void {
    $form_state = new FormState();
    $form_state->setValues([
      'categories' => 'irrigation, foliar feed',
      'trailing_enabled' => 0,
      'trailing_threshold_in' => '1.5',
      'lookback_days' => '10',
      'forecast_enabled' => 1,
      'forecast_threshold_in' => '0.75',
      'forecast_probability' => '70',
      'lookahead_hours' => '72',
      'max_defers' => '3',
      'latitude' => '43.2',
      'longitude' => '-71.5',
    ]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);

    $config = $this->config('farm_weather_hold.settings');
    $this->assertSame(['irrigation', 'foliar feed'], $config->get('categories'));
    $this->assertFalse($config->get('trailing_enabled'));
    $this->assertSame(1.5, $config->get('trailing_threshold_in'));
    $this->assertSame(10, $config->get('lookback_days'));
    $this->assertTrue($config->get('forecast_enabled'));
    $this->assertSame(0.75, $config->get('forecast_threshold_in'));
    $this->assertSame(70, $config->get('forecast_probability'));
    $this->assertSame(72, $config->get('lookahead_hours'));
    $this->assertSame(3, $config->get('max_defers'));
    $this->assertSame(43.2, $config->get('latitude'));
    $this->assertSame(-71.5, $config->get('longitude'));
  }

  /**
   * The settings route exists with the farm admin permission.
   */
  public function testRoute(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('farm_weather_hold.settings');
    $this->assertSame('/admin/config/farm/weather-hold', $route->getPath());
    $this->assertSame('administer farm settings', $route->getRequirement('_permission'));
  }

}
