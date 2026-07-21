# Changelog

All notable changes to this project are documented here.

## Unreleased

### Added
- Trailing-rain skip: due watched logs auto-complete when trailing rainfall
  meets the threshold, with note + machine-readable data block.
- Forecast defer: coming-due watched logs move past forecast rain, capped
  at a configurable number of defers.
- Swappable `WeatherProviderInterface` with a cached Open-Meteo implementation
  (no API key).
- Admin settings form at `/admin/config/farm/weather-hold`.
- "Handled by weather" farm_digest category (soft dependency).
- GPL-2.0 LICENSE.txt, phpcs.xml.dist, phpstan.neon.dist, GitHub Actions CI.
