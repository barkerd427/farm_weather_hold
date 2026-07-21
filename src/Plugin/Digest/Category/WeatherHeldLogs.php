<?php

declare(strict_types=1);

namespace Drupal\farm_weather_hold\Plugin\Digest\Category;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\farm_digest\Attribute\DigestCategory;
use Drupal\farm_digest\DigestItem;
use Drupal\farm_digest\DigestQuery;
use Drupal\farm_digest\DigestSeverity;
use Drupal\farm_digest\Plugin\Digest\Category\DigestCategoryBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Logs farm_weather_hold skipped or deferred within the window.
 *
 * Designed for lookback digests: it reports actions whose logs changed
 * inside (window->start, window->end], so the assignee sees "watering
 * skipped — 1.4 in of rain" instead of tasks silently vanishing.
 *
 * This class is only loaded by farm_digest's plugin manager; when
 * farm_digest is not installed nothing references it, keeping the
 * dependency soft.
 */
#[DigestCategory(
  id: 'weather_held_logs',
  label: new TranslatableMarkup('Handled by weather'),
  weight: 30,
)]
final class WeatherHeldLogs extends DigestCategoryBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function collect(int $uid, DigestQuery $q): array {
    $storage = $this->entityTypeManager->getStorage('log');
    $scopeField = $this->configFactory
      ->get('farm_digest.settings')
      ->get('scope_field') ?: 'owner';
    // Access checks are skipped: this runs in a cron context and owner
    // scoping is applied via applyScope(). The data CONTAINS pre-filter is
    // coarse; the decode below is authoritative.
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('data', 'weather_hold', 'CONTAINS')
      ->condition('changed', $q->window->start, '>')
      ->condition('changed', $q->window->end, '<=')
      ->sort('changed', 'ASC');
    $this->applyScope($query, $q, $uid, $scopeField);
    $ids = $query->execute();

    $items = [];
    foreach ($storage->loadMultiple($ids) as $log) {
      $decoded = json_decode((string) $log->get('data')->value, TRUE);
      $held = is_array($decoded) ? ($decoded['weather_hold'] ?? NULL) : NULL;
      if (!is_array($held) || !isset($held['action'])) {
        continue;
      }
      $summary = $held['action'] === 'deferred'
        ? sprintf('deferred to %s (%.2f in forecast)', $held['rain_by'] ?? '?', (float) ($held['rain_in'] ?? 0))
        : sprintf('skipped (%.2f in of rain in the past %d days)', (float) ($held['rain_in'] ?? 0), (int) ($held['window_days'] ?? 0));
      $items[] = new DigestItem(
        label: $log->label() . ' — ' . $summary,
        url: $log->toUrl(),
        dueDate: (int) $log->get('timestamp')->value,
        severity: DigestSeverity::Info,
        categoryId: 'weather_held_logs',
        entityType: 'log',
        entityId: (int) $log->id(),
      );
    }
    return $items;
  }

}
