<?php

declare(strict_types=1);

namespace Drupal\farm_weather_hold\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures Farm Weather Hold site-wide settings.
 */
final class SettingsForm extends ConfigFormBase {

  private const SETTINGS = 'farm_weather_hold.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'farm_weather_hold_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::SETTINGS);

    $form['categories'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Log categories to watch'),
      '#description' => $this->t('Comma-separated log category term names. Only pending logs carrying one of these categories are ever completed or deferred.'),
      '#default_value' => implode(', ', $config->get('categories') ?: []),
      '#required' => TRUE,
    ];

    $form['trailing'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Trailing-rain skip'),
    ];
    $form['trailing']['trailing_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable trailing-rain skip'),
      '#default_value' => $config->get('trailing_enabled'),
    ];
    $form['trailing']['trailing_threshold_in'] = [
      '#type' => 'number',
      '#title' => $this->t('Rain threshold (inches)'),
      '#description' => $this->t('Mark a due log done when at least this much rain fell in the lookback window.'),
      '#min' => 0,
      '#step' => 0.01,
      '#default_value' => $config->get('trailing_threshold_in'),
    ];
    $form['trailing']['lookback_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Lookback window (days)'),
      '#min' => 1,
      '#step' => 1,
      '#default_value' => $config->get('lookback_days'),
    ];

    $form['forecast'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Forecast defer'),
    ];
    $form['forecast']['forecast_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable forecast defer'),
      '#default_value' => $config->get('forecast_enabled'),
    ];
    $form['forecast']['forecast_threshold_in'] = [
      '#type' => 'number',
      '#title' => $this->t('Forecast rain threshold (inches)'),
      '#min' => 0,
      '#step' => 0.01,
      '#default_value' => $config->get('forecast_threshold_in'),
    ];
    $form['forecast']['forecast_probability'] = [
      '#type' => 'number',
      '#title' => $this->t('Probability threshold (%)'),
      '#min' => 0,
      '#max' => 100,
      '#step' => 1,
      '#default_value' => $config->get('forecast_probability'),
    ];
    $form['forecast']['lookahead_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Look-ahead horizon (hours)'),
      '#min' => 1,
      '#step' => 1,
      '#default_value' => $config->get('lookahead_hours'),
    ];
    $form['forecast']['max_defers'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum defers per log'),
      '#description' => $this->t('After this many defers the log is left due for a person to decide.'),
      '#min' => 0,
      '#step' => 1,
      '#default_value' => $config->get('max_defers'),
    ];

    $form['coordinates'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Farm coordinates'),
      '#description' => $this->t('farmOS stores no site-wide farm coordinate, so the weather location is configured here. The timezone follows the site timezone.'),
    ];
    $form['coordinates']['latitude'] = [
      '#type' => 'number',
      '#title' => $this->t('Latitude'),
      '#min' => -90,
      '#max' => 90,
      '#step' => 'any',
      '#default_value' => $config->get('latitude'),
    ];
    $form['coordinates']['longitude'] = [
      '#type' => 'number',
      '#title' => $this->t('Longitude'),
      '#min' => -180,
      '#max' => 180,
      '#step' => 'any',
      '#default_value' => $config->get('longitude'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $categories = array_values(array_filter(array_map(
      trim(...),
      explode(',', (string) $form_state->getValue('categories')),
    )));
    $this->config(self::SETTINGS)
      ->set('categories', $categories)
      ->set('trailing_enabled', (bool) $form_state->getValue('trailing_enabled'))
      ->set('trailing_threshold_in', (float) $form_state->getValue('trailing_threshold_in'))
      ->set('lookback_days', (int) $form_state->getValue('lookback_days'))
      ->set('forecast_enabled', (bool) $form_state->getValue('forecast_enabled'))
      ->set('forecast_threshold_in', (float) $form_state->getValue('forecast_threshold_in'))
      ->set('forecast_probability', (int) $form_state->getValue('forecast_probability'))
      ->set('lookahead_hours', (int) $form_state->getValue('lookahead_hours'))
      ->set('max_defers', (int) $form_state->getValue('max_defers'))
      ->set('latitude', (float) $form_state->getValue('latitude'))
      ->set('longitude', (float) $form_state->getValue('longitude'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
