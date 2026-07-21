<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_weather_hold\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests module installation and default configuration.
 *
 * @group farm_weather_hold
 */
class InstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['farm_weather_hold'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['farm_weather_hold']);
  }

  /**
   * The module installs.
   */
  public function testModuleInstalls(): void {
    $this->assertTrue($this->container->get('module_handler')->moduleExists('farm_weather_hold'));
  }

  /**
   * Default settings match the spec.
   */
  public function testDefaultSettings(): void {
    $config = $this->config('farm_weather_hold.settings');
    $this->assertSame(['irrigation'], $config->get('categories'));
    $this->assertTrue($config->get('trailing_enabled'));
    $this->assertSame(1.0, $config->get('trailing_threshold_in'));
    $this->assertSame(7, $config->get('lookback_days'));
    $this->assertTrue($config->get('forecast_enabled'));
    $this->assertSame(0.5, $config->get('forecast_threshold_in'));
    $this->assertSame(60, $config->get('forecast_probability'));
    $this->assertSame(48, $config->get('lookahead_hours'));
    $this->assertSame(2, $config->get('max_defers'));
    $this->assertSame(42.5, $config->get('latitude'));
    $this->assertSame(-72.5, $config->get('longitude'));
  }

  /**
   * The help page mentions the module and its config path.
   */
  public function testHelpPage(): void {
    $output = farm_weather_hold_help('help.page.farm_weather_hold', $this->container->get('current_route_match'));
    $this->assertStringContainsString('weather', $output);
    $this->assertStringContainsString('/admin/config/farm/weather-hold', $output);
  }

}
