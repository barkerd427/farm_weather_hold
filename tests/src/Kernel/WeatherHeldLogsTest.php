<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_weather_hold\Kernel;

use Drupal\farm_digest\DigestQuery;
use Drupal\farm_digest\DigestSeverity;
use Drupal\farm_digest\DigestWindow;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the "Handled by weather" digest category plugin.
 *
 * @group farm_weather_hold
 */
#[RunTestsInSeparateProcesses]
class WeatherHeldLogsTest extends FarmWeatherHoldKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'text',
    'datetime',
    'filter',
    'entity',
    'state_machine',
    'log',
    'taxonomy',
    'farm_weather_hold_test',
    'farm_weather_hold',
    'farm_digest',
  ];

  /**
   * Collects only weather-held logs changed inside the lookback window.
   */
  public function testCollectsHeldLogsInWindow(): void {
    $user = User::create(['name' => 'laura']);
    $user->save();
    $uid = (int) $user->id();

    $now = $this->container->get('datetime.time')->getRequestTime();
    $skipped = $this->createLog([
      'name' => 'Water the 5 Bobo hydrangeas',
      'timestamp' => $now - 7200,
      'status' => 'done',
      'category' => [$this->irrigationTerm->id()],
      'owner' => [$uid],
      'data' => '{"weather_hold":{"action":"skipped","reason":"trailing_rain","rain_in":1.4,"window_days":7,"checked":"2026-07-21T10:00:00-04:00"}}',
    ]);
    $deferred = $this->createLog([
      'name' => 'Water the 4 hinoki cypress',
      'timestamp' => $now + 86400,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
      'owner' => [$uid],
      'data' => '{"weather_hold":{"action":"deferred","reason":"forecast_rain","rain_in":0.8,"rain_by":"2026-07-22","defer_count":1,"checked":"2026-07-21T10:00:00-04:00"}}',
    ]);
    // Noise: same owner, no weather_hold data — never listed.
    $this->createLog([
      'name' => 'Untouched task',
      'timestamp' => $now - 3600,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
      'owner' => [$uid],
    ]);
    // Noise: held log owned by someone else — excluded under owner scope.
    $other = User::create(['name' => 'dan']);
    $other->save();
    $this->createLog([
      'name' => 'Other owner held log',
      'timestamp' => $now - 3600,
      'status' => 'done',
      'owner' => [(int) $other->id()],
      'data' => '{"weather_hold":{"action":"skipped","rain_in":1.4,"window_days":7}}',
    ]);

    $window = new DigestWindow($now - 3600, $now + 10, 'lookback');
    $query = new DigestQuery($window, [], 'owner');
    $plugin = $this->container->get('plugin.manager.digest_category')->createInstance('weather_held_logs');
    $items = $plugin->collect($uid, $query);

    $this->assertCount(2, $items);
    $labels = array_map(static fn (object $item): string => $item->label, $items);
    $this->assertContains('Water the 5 Bobo hydrangeas — skipped (1.40 in of rain in the past 7 days)', $labels);
    $this->assertContains('Water the 4 hinoki cypress — deferred to 2026-07-22 (0.80 in forecast)', $labels);
    foreach ($items as $item) {
      $this->assertSame(DigestSeverity::Info, $item->severity);
      $this->assertSame('weather_held_logs', $item->categoryId);
    }
    // Both logs share the same 'changed' second, so relative order under
    // the changed ASC sort is not guaranteed — compare as a set.
    $this->assertEqualsCanonicalizing([(int) $skipped->id(), (int) $deferred->id()], array_map(
      static fn (object $item): int => $item->entityId,
      $items,
    ));
  }

  /**
   * The plugin is discoverable with the expected label.
   */
  public function testPluginDefinition(): void {
    $definitions = $this->container->get('plugin.manager.digest_category')->getDefinitions();
    $this->assertArrayHasKey('weather_held_logs', $definitions);
    $this->assertSame('Handled by weather', (string) $definitions['weather_held_logs']['label']);
  }

}
