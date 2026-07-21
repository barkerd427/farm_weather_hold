# Farm Weather Hold

A farmOS contrib module that makes pending categorized logs weather-aware:
tasks that nature has already done (or is about to do) get completed or
deferred automatically instead of nagging the assignee.

## Requirements

- Drupal `^10.3 || ^11`, PHP 8.3+, and farmOS (`farm_log_category` provides the log
  category field used to identify watched logs; `farm_setup` provides the
  "administer farm settings" permission used by the admin UI).
- Open-Meteo is used as the weather source, with no API key required.

## How it decides

| Situation | Condition (defaults) | Action |
|---|---|---|
| Due log, recent rain | Pending log with a watched category is due (timestamp ≤ now + 1 h) and trailing rain over the past 7 days ≥ 1.0 in | Marked **done**; note `Auto-skipped — {X.XX} in of rain in the past {N} days (farm_weather_hold)` |
| Due log, dry week | Same, but trailing rain < 1.0 in | Left due — the person waters |
| Coming due, rain forecast | Pending watched log due within 48 h, forecast ≥ 0.5 in at ≥ 60 % probability inside that horizon | **Deferred**: timestamp moved to the day after the rain ends; note `Deferred — {X.XX} in forecast by {date} (farm_weather_hold)` |
| Already deferred twice | A log at the max-defer cap (2) comes due again with rain forecast | Left due — a human decides |
| Forecast busted | A deferred log comes due and the rain never fell | Left due — the person waters |
| Uncategorized / other category | Any pending log without a watched category term | **Never touched** |

Both rules run every cron tick (throttled to once per calendar hour) and are
independently toggleable. A log only ever qualifies if it carries one of the
configured `log_category` term names — anything else, including logs with no
category at all, is left completely alone.

## The data block

Every action stamps a machine-readable block into the log's `data` field
(existing JSON keys are preserved; non-JSON data is never overwritten):

    {"weather_hold": {
      "action": "skipped" | "deferred",
      "reason": "trailing_rain" | "forecast_rain",
      "rain_in": 1.40,
      "window_days": 7,          // skipped only
      "rain_by": "2026-07-22",   // deferred only
      "defer_count": 1,          // deferred only
      "checked": "2026-07-21T10:00:00-04:00"
    }}

This block is what lets `farm_digest` (or any other consumer) tell the
difference between a log that was skipped versus deferred, without re-parsing
the human-readable note, and is also how the max-defer cap is enforced —
`defer_count` is read back on every forecast-defer pass.

## Weather source

Open-Meteo forecast API, `precipitation_unit=inch`, site timezone.
Trailing rain deliberately uses the forecast endpoint's `past_days` rather
than the archive endpoint: the historical archive lags real time by ~5 days,
which would blind the trailing window to the rain that matters most.
Responses are cached for an hour so repeated cron ticks within that window
don't hammer the API. Other providers can be added by implementing
`WeatherProviderInterface` and swapping the `farm_weather_hold.weather_provider`
service.

## Configuration

Visit `/admin/config/farm/weather-hold` (Administration > Configuration >
Farm > Farm Weather Hold) to configure:

| Setting | Description |
|---|---|
| Log categories to watch | Comma-separated `log_category` term names; only pending logs carrying one of these are ever completed or deferred |
| Enable trailing-rain skip | Toggles rule 1 |
| Rain threshold (inches) | Trailing-rain skip: minimum rainfall in the lookback window to mark a due log done |
| Lookback window (days) | Trailing-rain skip: how many days back to sum rainfall |
| Enable forecast defer | Toggles rule 2 |
| Forecast rain threshold (inches) | Forecast defer: minimum forecast rainfall to defer a coming-due log |
| Probability threshold (%) | Forecast defer: minimum forecast probability required |
| Look-ahead horizon (hours) | Forecast defer: how far ahead a log counts as "coming due" |
| Maximum defers per log | Forecast defer: after this many defers, the log is left due for a person to decide |
| Latitude / Longitude | farmOS stores no site-wide farm coordinate, so the weather location is configured here; the timezone follows the site timezone |

## Scheduling

Evaluation runs on Drupal's cron, throttled with Drupal State to at most one
evaluation per calendar hour (farm timezone) — the same throttling pattern
`farm_digest` uses for its sends. A crontab that runs cron hourly is enough to
keep the throttle ticking:

```
0 * * * * /path/to/drush --uri=https://your-farm.example cron
```

## farm_digest integration

If `farm_digest` is installed, a "Handled by weather" digest category lists
every log that was skipped or deferred since the last digest window, so the
assignee sees "watering skipped — 1.40 in of rain" instead of a task silently
disappearing. The module works fine without `farm_digest` installed — the
dependency is soft; the digest category plugin is only loaded by
`farm_digest`'s own plugin manager.

## Non-goals

Threshold rain math only — there is no per-plant soil moisture modeling.
Uncategorized logs are never suppressed, deferred, or otherwise touched.
