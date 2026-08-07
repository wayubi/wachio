# Wachio

Scheduled, temperature-aware watering for [Rachio](https://rach.io) irrigation controllers. Each zone runs on its own schedule (any number of start times, any set of days), and runtimes scale with the forecast temperature.

The controller's own scheduling is bypassed in favor of a configurable per-zone model — run entirely from a tiny (~24 MB) Alpine container that wakes up every minute, decides what (if anything) needs watering, and makes **zero** API calls when nothing is due.

## Features

- **Per-zone schedules** — every zone can have its own list of start times and weekdays. Water one zone twice a day, another once a week, another not at all.
- **Temperature-based runtimes** — hotter days water longer, scaling linearly from 0 at 55°F to 100% of the basis at 90°F (using daily high/low/avg per month).
- **Rain-aware** — skips watering when the forecast predicts rain (>50% chance within 2 days) or a rain delay is active on the controller.
- **Cold protection** — optional per-zone `min_temp` floor (no watering below a temperature).
- **Safety caps** — optional per-zone `max_runtime`; verifies the controller is `IDLE` (via `getDeviceState`) right before starting, so manual/other zones are never interrupted.
- **Fixed runtimes** — optional per-zone `fixed_runtime` for zones that must always run the same duration, bypassing temperature.
- **Automatic frequency** (`auto_schedule`) — computes *how often* a zone should run from the same controller settings used by `auto_runtime` plus reference evapotranspiration (ET₀) and a crop coefficient, replacing the manual `times` array with a computed daily schedule.
- **Cycle & soak** — runs that exceed `max_cycle_minutes` are split into shorter sub-runs with soak gaps (fully automatic for `auto_schedule` zones; capped with a warning for manually-scheduled ones).
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

# Force-recompute an auto_schedule zone's daily schedule (debugging/testing)
docker compose run --rm wachio php /app/run.php --dry-run --zone 4 --recompute-schedule

# Force-preview one zone (still no watering, since 'dry_run' defaults to true)
docker compose run --rm wachio php /app/run.php --zone 4
```

> `--zone N` forces that single zone to run once, regardless of its schedule. For an `auto_schedule` zone run outside one of its scheduled times, the duration used is the **first entry** of that day's schedule — which, if the zone is cycle-split, may be a short sub-cycle rather than a full-length run.
> `--recompute-schedule` discards today's cached `auto_schedule` run lists and rebuilds them from a fresh weather fetch (see [Auto schedule](#auto-schedule)). When combined with `--zone N`, only that zone is rebuilt.

## How it works

The container runs BusyBox `crond` and executes `run.php` every minute. The script:

1. Computes the local time from the configured timezone and checks which zones are **due** (`schedule.php` config only, or the cached `auto_schedule` run list — no network calls, *except* the first tick of a day when an `auto_schedule` zone's schedule hasn't been computed yet, which fetches the controller and forecast once to build it).
2. If nothing is due, it prints `Nothing scheduled` and exits immediately.
3. Otherwise it fetches the controller (person → device) and checks for an active rain delay.
4. Pulls the forecast from [Open-Meteo](https://open-meteo.com) (free, no API key) using the controller's latitude/longitude. Transient failures (429/5xx) are retried; if the forecast is still unavailable it falls back to a cached copy from the last 12 hours, or runs at full temperature (see [Rain & weather](#rain--weather)). The forecast also provides daily reference evapotranspiration (ET₀), used by `auto_schedule` zones.
5. Skips if rain is forecast within the check window, or a zone's temperature is below its `min_temp`.
6. Computes each due zone's runtime, then — immediately before calling `start_multiple` — checks the controller's live state via `getDeviceState`; if it's anything but `IDLE` (e.g. a manual zone is running), it aborts and leaves it alone.
7. Calls `PUT /public/zone/start_multiple` — **unless** dry-run is active (see `'dry_run'`), in which case it prints the would-be payload and exits. Same-tick zones are batched into that one call and run sequentially by `sortOrder`.

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
- **`auto_runtime`** — optional. When `true`, computes the zone's basis automatically from its advanced settings on the controller (`availableWater × rootZoneDepth × managementAllowedDepletion ÷ nozzleRate ÷ efficiency × 60`) instead of `runtime_basis`. The result is temperature-scaled exactly like `runtime_basis`. Optional per-zone overrides fill in missing/zero API values: `available_water` (in/in), `root_depth` (in), `allowed_depletion` (fraction), `nozzle_rate` (in/hr), `efficiency` (fraction). Efficiency behaves differently from the other four: a **missing** value defaults to `1.0` (no loss — the calc reduces to the plain formula), while an **explicit `0`** is treated as bad input and the zone is skipped. If any of the other required inputs can't be resolved, the zone is skipped with a message. Each due tick logs the fetched values and the computed basis: `[auto]` prints the resolved inputs and basis (or lists which inputs are missing), and `[basis]` prints the basis, temperature factor, and final duration — run with `--dry-run` (or `dry_run` in the schedule) to see these without watering.
- **`runtime_basis`** — the watering duration **per run** at the *full* temperature (`$temperature_full`, 90°F). Every scheduled run gets this amount, scaled linearly from `0` at the *floor* (`$temperature_floor`, 55°F) to 100% at the full temperature (see [Runtime model](#runtime-model)). A zone with `runtime_basis` 3 running 5 times a day gets ~3 minutes per run (≈15 min/day). May be fractional (e.g. `3.5`). Set to `0` (and/or empty `times`) to disable a zone.
- **`fixed_runtime`** — optional. A fixed duration in minutes that **bypasses the temperature model entirely** and ignores `runtime_basis`. Use for a zone that must always run the same amount (e.g. `'fixed_runtime' => 1` = exactly 1 minute regardless of weather). May be fractional (e.g. `0.5` = 30s). Cannot be combined with a temperature-based basis — pick one.
- **`max_runtime`** — optional. Caps a temperature-computed per-run duration in minutes. May be fractional (e.g. `3.5`). Not applied to `fixed_runtime` zones.
- **`min_temp`** — optional. Skips the zone when the day's temperature is below this value (°F). Useful to avoid watering near freezing.
- **`schedules`** — optional. A list of schedule blocks, each with its own `times` and `days`, for multiple distinct schedules on one zone (see below). Mutually exclusive with the flat `times`/`days` shorthand.
- **`auto_schedule`** — optional. When `true`, ignores `times`/`days` and computes the zone's daily run times from its bucket size and evapotranspiration instead (see [Auto schedule](#auto-schedule)). Requires `window_start`. The crop coefficient (Kc) is read from the controller's `customCrop.coefficient`; no zone-config value is needed. Independent of the duration source: combine with `auto_runtime` for a fully automatic zone, or with `runtime_basis`/`fixed_runtime` for automatic frequency with a manual duration.
- **`window_start`** — required when `auto_schedule` is set. `HH:MM` time of the zone's first run of the day (e.g. `'05:30'`). Subsequent runs are spaced by the computed interval.
- **`max_runs`** — optional. Safety cap on the number of scheduled runs (and split sub-cycles) per day for an `auto_schedule` zone. Default `6`.
- **`max_cycle_minutes`** — optional. Any single run longer than this (minutes) is split into shorter sub-cycles with soak gaps. Default `10`. For `auto_schedule` zones the split is automatic (each sub-cycle becomes its own scheduled run, spaced by run duration + soak); for manually-scheduled zones a run over the cap is **capped with a warning** instead of split (see [Cycle & soak](#cycle--soak)).
- **`soak_minutes`** — optional. Gap (minutes) between split sub-cycles. Default `20`.

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
- **`auto_runtime`** — with `'auto_runtime' => true`, `runtime_basis` is replaced by a value computed from the zone's controller settings:
  ```
  basis = availableWater × rootZoneDepth × managementAllowedDepletion ÷ nozzleRate ÷ efficiency × 60
  ```
  i.e. the water depth per cycle (bucket size) divided by how fast the zone's nozzle applies it, corrected for irrigation efficiency — e.g. 0.16 × 2 × 0.25 = 0.08 in, at 1.57 in/hr with 0.8 efficiency → ~3.82 min basis (fixed spray), at 0.65 in/hr → ~8.57 min (rotary). The basis stays a float (minutes); the only rounding is to whole seconds when the run duration is sent to the controller. A missing `efficiency` defaults to 1.0 (no correction); an explicit 0 skips the zone. See the zone options for the per-zone override keys.
- **`fixed_runtime`** — zones with a `fixed_runtime` skip this whole model and use the fixed minutes directly.

## Auto schedule

`auto_runtime` answers "how long should one watering event run?" `auto_schedule` answers the other half: **"how often should it run?"** It replaces a zone's static `times` array with a schedule computed once per day from the same bucket inputs and reference evapotranspiration (ET₀):

```
bucket      = availableWater × rootZoneDepth × managementAllowedDepletion   (inches)
daily_loss  = cropCoefficient × ET₀                                        (inches/day)
interval_hr = (bucket ÷ daily_loss) × 24
```

The bucket is the water depth applied per cycle (identical to the `auto_runtime` basis' numerator). ET₀ is today's reference evapotranspiration from the Open-Meteo forecast; `cropCoefficient` (Kc) scales it to the actual crop's water use, so the interval is the time it takes the bucket to drain. The interval is clamped to a minimum of 0.5 h.

- **Example:** bucket 0.08 in, Kc 0.65, ET₀ 0.21 in/day → daily loss 0.137 in/day → interval ≈ **14.1 h** → from a `05:30` window start the zone runs at 05:30 and 19:34.
- Run times start at `window_start` and are spaced by `interval_hr`, stopping at midnight or `max_runs` (default 6), rounded to the nearest minute. Zones water every day — there is no per-week `days` concept for `auto_schedule`.
- Durations are frozen at schedule-build time: temperature scaling, `max_runtime`, and cycle-splitting are all applied when the day's schedule is computed (using that morning's forecast temperature), so a hot afternoon won't lengthen that afternoon's run. This is what keeps split sub-cycles consistent within a day.
- **Daily caching** — the computed run list is written to `/tmp/wachio_schedule_<zone>_<date>.json` and reused by every cron tick that day, so a mid-day forecast change can't shift the run times. The first tick of a day that needs a schedule does the real computation (one controller + one weather fetch); stale caches older than 2 days are cleaned up on each run. `--recompute-schedule` forces a rebuild for testing (all auto_schedule zones, or just `--zone N` when both flags are passed).
- **ET₀ units** — Open-Meteo returns `et0_fao_evapotranspiration` in **millimeters** by default; the script requests it in **inches** (`precipitation_unit=inch`) and also converts `mm → in` (÷ 25.4) defensively if the response reports mm. The value shown in the `[interval]` log line is already in inches/day.
- **Missing ET₀** — if the forecast is unavailable or today's ET₀ is missing/≤0, a conservative fallback of **0.20 in/day** is used and logged (`[weather] no ET0 for today — using fallback 0.20 in/day`), so a weather outage can't crash the run.
- **Validation** — if `auto_schedule` is set but `window_start` is missing, the controller reports no crop coefficient (`customCrop.coefficient` missing/0), or the bucket inputs can't be resolved, the zone is skipped with a clear `[interval]`/`[schedule]` message, and an empty result is cached so the message is logged once per day rather than every minute.

## Cycle & soak

Rachio's `start_multiple` API takes one duration per zone and has no native cycle-and-soak support, so splitting is emulated at the schedule layer: a run longer than `max_cycle_minutes` is broken into roughly-equal sub-cycles (each ≤ the cap), and each sub-cycle becomes its own scheduled run time spaced by *run duration + `soak_minutes`*.

- **`auto_schedule` zones — fully automatic.** Splitting happens when the day's schedule is built, so the cached run list already contains the sub-cycles as distinct entries (each carrying its explicit sub-cycle duration), and each fires its own API call via a normal cron tick.
- **Manually-scheduled zones (static `times`)** — the schedule is user-authored, so the script cannot inject extra run times without violating your `times`. Instead, a run that would exceed `max_cycle_minutes` is **capped at the cap and logged** (`[cycle] ... capping run`), accepting possible under-delivery that day. If a manual zone regularly needs splitting, convert it to `auto_schedule`, or author multiple `times` entries yourself with a smaller `runtime_basis`/`fixed_runtime`.

## Rain & weather

- **Source:** [Open-Meteo](https://open-meteo.com) forecast for the controller's coordinates — free, no account or key needed. Also supplies daily reference evapotranspiration (ET₀) for `auto_schedule` zones (requested in inches; see [Auto schedule](#auto-schedule)).
- **Rain skip:** watering is skipped if precipitation probability exceeds 50% on any day within `rain_check_days` (default 2).
- **Rain delay:** if a rain delay is active on the controller (`rainDelayExpirationDate`), watering is skipped.
- **Resilience:** every successful forecast is cached to `/tmp/wachio_weather.json` (with a timestamp). Transient Open-Meteo failures (HTTP 429/500/502/503/504) are retried up to 3× with short backoff. If the forecast is still unavailable, the script uses the cached copy when it's **≤12h old** (rain check and temperature scaling still apply); otherwise it waters at **full dose** — the temperature model is treated as at the full temperature (no rain check, since no forecast is available). This means a temporary weather outage never causes a missed watering, and never crashes the tick.

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
- **An `auto_schedule` zone never runs** — check the `[interval]`/`[schedule]` log lines: the zone is skipped if `window_start` is missing, the controller reports no crop coefficient (`customCrop.coefficient` missing/0), or its bucket inputs (available water / root depth / allowed depletion) can't be resolved. If you fix the config mid-day, run `--recompute-schedule` (optionally scoped to a zone with `--zone N`) to rebuild today's schedule (the cached empty result otherwise lasts until the next day).
- **It only ever dry-runs** — `'dry_run'` defaults to `true`; flip it to `false` (in `schedule.php`) to actually send requests to Rachio.
- **`start_multiple` rejects the payload** — see the note below.

## Known caveat

Rachio's API docs are self-contradictory about the `start_multiple` body: the OpenAPI schema says `zoneRunDurations`, while the official curl sample sends `{"zones": [...]}`. This project uses the curl-sample format. If the API rejects it, it's a one-line change in `run.php` (`'zones' =>` → `'zoneRunDurations' =>`).

## License

MIT — see [LICENSE](LICENSE).
