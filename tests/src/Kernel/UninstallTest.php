<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_weather_hold\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests uninstalling removes the module's State entries.
 *
 * @group farm_weather_hold
 */
#[RunTestsInSeparateProcesses]
final class UninstallTest extends FarmWeatherHoldKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
  }

  /**
   * Uninstall deletes all farm_weather_hold.* State keys.
   */
  public function testUninstallCleansState(): void {
    $state = $this->container->get('state');
    $state->set('farm_weather_hold.last_run', 12345);
    $this->container->get('module_installer')->uninstall(['farm_weather_hold']);
    $this->assertFalse($this->container->get('module_handler')->moduleExists('farm_weather_hold'));
    $this->assertNull($state->get('farm_weather_hold.last_run'));
  }

}
