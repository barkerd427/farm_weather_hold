<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_weather_hold\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Smoke test: the test harness gives logs category/notes/data fields.
 *
 * @group farm_weather_hold
 */
#[RunTestsInSeparateProcesses]
class LogFieldsTest extends FarmWeatherHoldKernelTestBase {

  /**
   * A categorized pending log round-trips its farmOS-style fields.
   */
  public function testCategorizedLogRoundTrip(): void {
    $log = $this->createLog([
      'name' => 'Water the 5 Bobo hydrangeas',
      'timestamp' => 1_750_000_000,
      'status' => 'pending',
      'category' => [$this->irrigationTerm->id()],
      'notes' => ['value' => 'Skip if it rained an inch.', 'format' => 'default'],
      'data' => '{"other_module":{"keep":true}}',
    ]);
    $this->assertSame('pending', $log->get('status')->value);
    $this->assertSame($this->irrigationTerm->id(), $log->get('category')->target_id);
    $this->assertStringContainsString('rained', $log->get('notes')->value);
    $this->assertSame('{"other_module":{"keep":true}}', $log->get('data')->value);
  }

}
