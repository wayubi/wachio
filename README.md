# Wachio

Scheduled, temperature-aware watering for [Rachio](https://rach.io) irrigation controllers. Each zone runs on its own schedule (any number of start times, any set of days), and runtimes scale with the forecast temperature.

The controller's own scheduling is bypassed in favor of a configurable per-zone model — run entirely from a tiny (~24 MB) Alpine container that wakes up every minute, decides what (if anything) needs watering, and makes **zero** API calls when nothing is due.

## Features

- **Per-zone schedules** — every zone can have its own list of start times and weekdays. Water one zone twice a day, another once a week, another not at all.
- **Temperature-based runtimes** — hotter days water longer, scaling linearly from 0 at 55°F to 100% of the basis at 90°F (using daily high/low/avg per month).
- **Rain-aware** — skips watering when the forecast predicts rain (>50% chance within 2 days) or a rain delay is active on the controller.
- **Cold protection** — optional per-zone `min_temp` floor (no watering below a temperature).
- **Safety caps** — optional per-zone `max_runtime`; checks the controller isn't already watering before starting.
- **Fixed runtimes** — optional per-zone `fixed_runtime` for zones that must always run the same duration, bypassing temperature.
- **API-friendly** — no polling. When nothing is scheduled it exits before making any request (Rachio allows 3,500 req/day).
- **Dry-run mode** — **on by default** (`'dry_run' => true` in `schedule.php`): the script prints exactly what it would send and never touches Rachio, until you flip it to `false`.

## Requirements

- Docker with the Compose plugin
- A Rachio account + [API token](https://rachio.readme.io/reference/authentication)
- PHP 8.3 is provided by the container (nothing to install locally)

## Quick start

```sh
# 1. Set your API token (kept out of git) — edit compose.override.yml and
#    replace the placeholder:
#      RACHIO_TOKEN=your-rachio-api-key-here

# 2. Create your own schedule (gitignored) from the template
cp schedule.example.php schedule.php

# 3. Build and start
docker compose up -d

# 4. Watch it run (the cron fires the script every minute)
docker compose logs -f
```

`compose.override.yml` is gitignored so your token is never committed. If you clone the repo and don't create an override, `compose.yml` falls back to the placeholder value and the script will report that `RACHIO_TOKEN` is not set.

### Verify before you water

Dry-run is **on by default**, so nothing is ever sent to Rachio until you flip `'dry_run' => true` to `false` (in `schedule.php`). These commands still let you inspect behavior:

```sh
# Show what would happen right now (safe, makes no changes)
docker compose run --rm wachio php /app/run.php --dry-run

# Force-preview one zone (still no watering)
docker compose run --rm wachio php /app/run.php --dry-run --zone 4

# Force-preview one zone (still no watering, since 'dry_run' defaults to true)
docker compose run --rm wachio php /app/run.php --zone 4
```

> `--zone N` forces that single zone to run once, regardless of its schedule.

## How it works

The container runs BusyBox `crond` and executes `run.php` every minute. The script:

1. Computes the local time from the configured timezone and checks which zones are **due** (`schedule.php` config only — no network calls).
2. If nothing is due, it prints `Nothing scheduled` and exits immediately.
3. Otherwise it fetches the controller (person → device), checks for an active rain delay or an already-running schedule.
4. Pulls the forecast from [Open-Meteo](https://open-meteo.com) (free, no API key) using the controller's latitude/longitude.
5. Skips if rain is forecast within the check window, or a zone's temperature is below its `min_temp`.
6. Computes each due zone's runtime, then calls `PUT /public/zone/start_multiple` — **unless** dry-run is active (see `'dry_run'`), in which case it prints the would-be payload and exits.

## Configuration

The **schedule** (timezone, dry-run, zones) lives in `schedule.php`, which is **gitignored** — change it freely and it takes effect on the next cron tick without a restart. It's never committed or pushed. `schedule.example.php` is the committed, documented template (and the fallback if `schedule.php` is missing); copy it to `schedule.php` and edit. It ships with eight example zones (zone 1 active, 2–8 commented out) covering every option.

Everything else (API safety, weather, runtime-model knobs) lives in `run.php` under the `W\Rachio` and `W\Model` classes.

### Class-level settings

```php
private static $timezone = 'America/Denver';      // fallback only — effective value lives in schedule.php
private static $rain_check_days = 2;              // skip watering if rain forecast within N days (W\Model)
private static $dry_run = true;                   // fallback only — effective value lives in schedule.php
private static $temperature_floor = 55;           // °F, no watering at or below this (W\Model)
private static $temperature_full  = 90;           // °F, 100% of runtime_basis at this (W\Model)
```

- **`$timezone`** — in `run.php` this is only the **fallback**; the effective timezone is set in `schedule.php`, which overrides it. Schedules are evaluated in that timezone with no API lookups, so it must match where you want watering times interpreted.
- **`$dry_run`** — in `run.php` this is only the **fallback**; the effective value is `'dry_run'` in `schedule.php`. When `true`, the script computes everything but prints the would-be payload instead of calling `start_multiple`. **Defaults to `true`** so nothing waters until you flip it to `false`. The `--dry-run` CLI flag works independently and forces dry-run even when this is `false`.
- **`$rain_check_days`** — lives in `W\Model`; the number of forecast days examined for rain. See [Rain & weather](#rain--weather).
- **`$temperature_floor` / `$temperature_full`** — live in `W\Model`; the temperature curve anchors. Watering stops entirely at or below the floor, and reaches 100% of `runtime_basis` at the full temperature.

### Zones

In `schedule.php` (gitignored — see [Configuration](#configuration)), under `'zones'`:

```php
'zones' => [
    1 => [
        'name'          => 'Zone 1 - Parkway',
        'enabled'       => true,               // explicit: required to run
        'runtime_basis' => 8,                // minutes at the full temp (90°F)
        'max_runtime'   => 30,               // optional per-run cap (minutes)
        'min_temp'      => 35,               // optional skip below this temp (°F)
        'times'         => ['06:00'],        // one or more HH:MM start times
        'days'          => null,             // null = every day, or [1,3,5] = Mon/Wed/Fri
    ],
    // ...
],
```

Every option:

- **`name`** — optional label used in log output. Cosmetic only.
- **`enabled`** — **required to run.** Only zones with `'enabled' => true` are handled by this script; absent or `false` disables the zone (e.g. a zone you manage through the Rachio app). Disabled zones are skipped even if they have `times` set, and `--zone N` overrides skip too.
- **`times`** — required. Any number of `HH:MM` start times; the same set applies on every scheduled day. An empty array disables the zone.
- **`days`** — optional. `null` = every day, or an array of weekdays where `1`=Mon … `7`=Sun.
- **`runtime_basis`** — the watering duration **per run** at the *full* temperature (`$temperature_full`, 90°F). Every scheduled run gets this amount, scaled linearly from `0` at the *floor* (`$temperature_floor`, 55°F) to 100% at the full temperature (see [Runtime model](#runtime-model)). A zone with `runtime_basis` 3 running 5 times a day gets ~3 minutes per run (≈15 min/day). Set to `0` (and/or empty `times`) to disable a zone.
- **`fixed_runtime`** — optional. A fixed duration in minutes that **bypasses the temperature model entirely** and ignores `runtime_basis`. Use for a zone that must always run the same amount (e.g. `'fixed_runtime' => 1` = exactly 1 minute regardless of weather). Cannot be combined with a temperature-based basis — pick one.
- **`max_runtime`** — optional. Caps a temperature-computed per-run duration in minutes. Not applied to `fixed_runtime` zones.
- **`min_temp`** — optional. Skips the zone when the day's temperature is below this value (°F). Useful to avoid watering near freezing.
- **`schedules`** — optional. A list of schedule blocks, each with its own `times` and `days`, for multiple distinct schedules on one zone (see below). Mutually exclusive with the flat `times`/`days` shorthand.

#### Multiple schedules per zone

Use `schedules` to give a zone several distinct schedules, each with its own times and days:

```php
4 => [
    'name'          => 'Zone 4 - Back Yard',
    'enabled'       => true,
    'runtime_basis' => 22,
    'max_runtime'   => 45,
    'min_temp'      => 35,
    'schedules'     => [
        ['times' => ['06:00', '18:00'], 'days' => [2, 4, 6]],  // Tue/Thu/Sat, twice a day
        ['times' => ['09:00'],          'days' => [6]],        // Sat morning only
    ],
],
```

The flat `times` + `days` form is shorthand for a single schedule block; the two are equivalent.

#### Disabling a zone

Set `'enabled' => true` on only the zones you want this script to handle — every other zone (absent `enabled`, or `'enabled' => false`) is skipped. You can also set `times` to `[]` (with `runtime_basis` 0) or omit the zone from the `'zones'` array entirely. Zones that are disabled on the controller itself are also skipped automatically.

Zone keys must match the zone numbers reported by your controller (`GET /public/person/:id` or the Rachio app).

## Runtime model

```
factor     = clamp((temperature − floor) / (full − floor), 0, 1)   (floor=55°F, full=90°F)
per_run    = runtime_basis × factor × month_multiplier
duration   = min(per_run, max_runtime)
```

- `temperature` is the forecast high, avg, or low depending on the month (see `W\Model::$calendar` — summer uses daily high, winter uses daily low).
- `month_multiplier` is a per-month tuning knob (defaults to `1.0`).
- The **floor** (55°F) means no watering at or below that temperature — this also supersedes a per-zone `min_temp` unless you want a stricter zone override. The **full** temperature (90°F) gives 100% of `runtime_basis`; hotter days plateau there rather than running longer.
- **Example:** at 88°F, `factor = (88−55)/(90−55) = 0.94`, so a zone with `runtime_basis` 5 gets 4.7 min ≈ 283s **per run**.
- **Per-run basis** — `runtime_basis` is a **per-run** amount, not a daily total. Each scheduled run gets the full scaled dose, so more runs/day means more total water (e.g. `runtime_basis` 3 × 5 runs/day ≈ 15 min/day at the full temperature).
- **`fixed_runtime`** — zones with a `fixed_runtime` skip this whole model and use the fixed minutes directly.

## Rain & weather

- **Source:** [Open-Meteo](https://open-meteo.com) forecast for the controller's coordinates — free, no account or key needed.
- **Rain skip:** watering is skipped if precipitation probability exceeds 50% on any day within `rain_check_days` (default 2).
- **Rain delay:** if a rain delay is active on the controller (`rainDelayExpirationDate`), watering is skipped.

## Project layout

```
run.php               The whole application (schedule logic, weather, API client) — mounted into the container
schedule.php          YOUR schedule (timezone + zones) — gitignored, mounted into the container
schedule.example.php  Documented template/fallback for schedule.php — committed
compose.yml           Base compose file (placeholder token, mounts run.php + schedule files into the container)
compose.override.yml  Your local token (gitignored)
Dockerfile            Alpine + PHP 8.3 CLI + cURL, runs BusyBox crond
crontab               Runs `php /app/run.php` every minute
```

## Troubleshooting

- **`RACHIO_TOKEN environment variable is not set`** — add your token to `compose.override.yml` (or set `RACHIO_TOKEN` in your environment) and recreate the container: `docker compose up -d`.
- **`HTTP 401 ... The client is not authorized`** — the token is wrong or was revoked.
- **Nothing ever waters** — check your `days`/`times` against the configured `$timezone`, and the zone numbers against your controller. Use `--dry-run` to see what the script is deciding.
- **It only ever dry-runs** — `'dry_run'` defaults to `true`; flip it to `false` (in `schedule.php`) to actually send requests to Rachio.
- **`start_multiple` rejects the payload** — see the note below.

## Known caveat

Rachio's API docs are self-contradictory about the `start_multiple` body: the OpenAPI schema says `zoneRunDurations`, while the official curl sample sends `{"zones": [...]}`. This project uses the curl-sample format. If the API rejects it, it's a one-line change in `run.php` (`'zones' =>` → `'zoneRunDurations' =>`).

## License

MIT — see [LICENSE](LICENSE).
