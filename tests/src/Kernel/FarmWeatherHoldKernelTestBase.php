<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_weather_hold\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\log\Entity\LogType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Base for kernel tests that need categorized farmOS-style logs.
 *
 * @group farm_weather_hold
 */
#[RunTestsInSeparateProcesses]
abstract class FarmWeatherHoldKernelTestBase extends KernelTestBase {

  /**
   * The irrigation log_category term used as the default watched category.
   */
  protected TermInterface $irrigationTerm;

  /**
   * {@inheritdoc}
   *
   * Minimal set that satisfies the log entity plus taxonomy for categories.
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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('log');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['filter', 'log', 'farm_weather_hold']);
    // The log status field resolves its workflow via LogType, so a LogType
    // config entity must exist before any log can be created.
    LogType::create([
      'id' => 'activity',
      'label' => 'Activity',
      'workflow' => 'log_default',
      'new_revision' => TRUE,
    ])->save();
    Vocabulary::create([
      'vid' => 'log_category',
      'name' => 'Log category',
    ])->save();
    $this->irrigationTerm = $this->createCategoryTerm('irrigation');
  }

  /**
   * Creates and saves a log_category term.
   */
  protected function createCategoryTerm(string $name): TermInterface {
    $term = Term::create([
      'vid' => 'log_category',
      'name' => $name,
    ]);
    $term->save();
    return $term;
  }

  /**
   * Creates and saves a log entity.
   *
   * @param array $values
   *   Field values; defaults are provided for 'type' and 'name'.
   *
   * @return \Drupal\log\Entity\LogInterface
   *   The saved log.
   */
  protected function createLog(array $values): object {
    $storage = $this->container->get('entity_type.manager')->getStorage('log');
    $log = $storage->create($values + [
      'type' => 'activity',
      'name' => 'Test log',
    ]);
    $log->save();
    return $log;
  }

  /**
   * Replaces the weather provider with a settable test double.
   */
  protected function setWeather(TestWeatherProvider $provider): void {
    $this->container->set('farm_weather_hold.weather_provider', $provider);
    // Force runner re-instantiation with the replaced provider.
    $this->container->set('farm_weather_hold.runner', NULL);
  }

  /**
   * Freezes datetime.time at a fixed request time.
   */
  protected function setFrozenTime(int $timestamp): void {
    $time = $this->createMock(\Drupal\Component\Datetime\TimeInterface::class);
    $time->method('getRequestTime')->willReturn($timestamp);
    $time->method('getCurrentTime')->willReturn($timestamp);
    $this->container->set('datetime.time', $time);
    $this->container->set('farm_weather_hold.runner', NULL);
    $this->container->set('farm_weather_hold.weather_provider', NULL);
  }

}
