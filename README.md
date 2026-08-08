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
- **Solar-relative windows** (`auto_schedule`) — `window_start`/`window_end` can track the day's actual sunrise/sunset, with optional minute offsets (`'sunrise'`, `'sunset-60'`, `'sunrise+30'`), so run windows stay anchored to daylight all year round. When the forecast has no solar data, sensible defaults are used instead (never a skip).
- **Heat-weighted placement** (`auto_schedule`) — the optional `placement_curve: 'bell'` places a zone's runs by inverting the hourly ET₀ curve, clustering them in the peak-evapotranspiration afternoon hours instead of spacing them evenly across the window (same runs, same total water).
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

# Simulate 5:30 AM to preview an auto_schedule zone's morning run (testing only)
docker compose run --rm wachio php /app/run.php --dry-run --zone 3 \
  --now="2026-08-08 05:30" --recompute-schedule

# Force-preview one zone (still no watering, since 'dry_run' defaults to true)
docker compose run --rm wachio php /app/run.php --zone 4
```

> `--zone N` forces that single zone to run once, regardless of its schedule. For an `auto_schedule` zone run outside one of its scheduled times, the duration used is the **first entry** of that day's schedule — which, if the zone is cycle-split, may be a short sub-cycle rather than a full-length run.
> `--recompute-schedule` discards today's cached `auto_schedule` run lists and rebuilds them from a fresh weather fetch (see [Auto schedule](#auto-schedule)). When combined with `--zone N`, only that zone is rebuilt.
> `--now="YYYY-MM-DD HH:MM"` — simulate a specific date/time to test schedule logic (e.g. verifying an `auto_schedule` zone's computed run times actually fire) without waiting for the real clock or touching the container's system time. It overrides the clock for **scheduling decisions only**; cache `computed_at` and weather `cached_at` timestamps still reflect real time, and `ScheduleCache::cleanup()` still uses real file mtimes. **Testing aid only — not intended for production cron use** — always combine with `--dry-run` to avoid accidentally watering.

## How it works

The container runs BusyBox `crond` and executes `run.php` every minute. The script:

1. Computes the local time from the configured timezone and checks which zones are **due** (`schedule.php` config only, or the cached `auto_schedule` run list — no network calls, *except* the first tick of a day when an `auto_schedule` zone's schedule hasn't been computed yet, which fetches the controller and forecast once to build it).
2. If nothing is due **and nothing is queued for retry** (see [Collision handling](#collision-handling)), it prints `Nothing scheduled` and exits immediately.
3. Otherwise it fetches the controller (person → device) and checks for an active rain delay.
4. Pulls the forecast from [Open-Meteo](https://open-meteo.com) (free, no API key) using the controller's latitude/longitude. Transient failures (429/5xx) are retried; if the forecast is still unavailable it falls back to a cached copy from the last 12 hours, or runs at full temperature (see [Rain & weather](#rain--weather)). The forecast also provides daily reference evapotranspiration (ET₀), used by `auto_schedule` zones.
5. Skips if rain is forecast within the check window, or a zone's temperature is below its `min_temp`.
6. Computes each due zone's runtime and checks the controller's live state via `getDeviceState` immediately before calling `start_multiple`. (In dry-run this check is skipped — no unnecessary API call — unless `--simulate-busy` is set.)
7. If the controller is anything but `IDLE` (e.g. a manual zone is running), the due zones are **queued for retry** instead of lost — never a second overlapping `start_multiple` (see [Collision handling](#collision-handling)).
8. Retries any queued runs from earlier ticks (using their frozen durations), then calls `PUT /public/zone/start_multiple` — **unless** dry-run is active (see `'dry_run'`), in which case it prints the would-be payload. Same-tick zones (fresh and retried together) are batched into that one call and run sequentially by `sortOrder`.

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
- **`window_start`** — required when `auto_schedule` is set. Time of the zone's first run of the day, either as `HH:MM` (e.g. `'05:30'`) or as a solar keyword: `'sunrise'`, `'sunset'`, or either with a minute offset (`'sunrise+30'`, `'sunset-60'`). Solar keywords resolve against the forecast's sunrise/sunset for the day; when unavailable, `06:00` is used and a warning is logged (never a skip). Subsequent runs are spaced by the computed interval.
- **`window_end`** — optional. Time of the zone's last run of the day (e.g. `'19:00'`), accepted as `HH:MM` or a solar keyword like `window_start` (default when solar data is unavailable: `19:00`). When set, the equation-determined run count is preserved but the runs are compressed into the `window_start`–`window_end` span instead of spilling toward midnight at the raw computed interval. Must be later than `window_start` (no overnight windows); if a solar `sunset` would land before a `sunrise` start, the zone is skipped with a clear message. Does **not** change total daily water — see [Auto schedule](#auto-schedule).
- **`placement_curve`** — optional. How the zone's runs are placed within a `window_end` span: `'uniform'` (default) spaces them evenly; `'bell'` inverts the day's hourly ET₀ curve so runs cluster in the peak-evapotranspiration hours (see [Heat-weighted placement](#heat-weighted-placement)). An unknown value logs a warning and falls back to `'uniform'`. Ignored when `window_end` is not set (nothing to bias — runs already follow the interval).
- **`max_runs`** — optional. Safety cap on the number of scheduled runs (and split sub-cycles) per day for an `auto_schedule` zone. Default `6`. When `window_end` is set it caps the number of run starts; split sub-cycles still complete even if they push past `window_end` (a warning is logged). Note: when `max_runs` actually caps the equation-derived count, total daily water is reduced accordingly — the equation determines the natural count, and `max_runs` is the ceiling that overrides it.
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
- **`window_end`** — optionally set a last-run time (e.g. `'19:00'`) to keep all runs within daylight hours. The interval equation still decides **how many** runs (and therefore **how much** total water) — `round(24 ÷ interval_hr)`, capped by `max_runs` — but those runs are placed **evenly across `window_start`–`window_end`** instead of at raw `interval_hr` spacing, which can land a run near midnight. Example: bucket 0.04 in, Kc 0.95, ET₀ 0.221 in/day → interval ≈ 4.57 h → 5 runs. Without `window_end` they land at `05:30, 10:04, 14:39, 19:13, 23:47`; with `window_end: '19:00'` they compress to `05:30, 08:53, 12:15, 15:38, 19:00`. Same 5 runs, same per-run duration, same total daily water — only placement changes. **`window_end` never reduces total water**: that's still governed by Root Depth / Allowed Depletion / Crop Coefficient, not by when runs are allowed to happen. (The one exception is `max_runs`: if the equation-derived run count exceeds it, the count is capped and total daily water is reduced accordingly — this is a `max_runs` ceiling, not a `window_end` effect.)
- **Solar-relative windows** — either boundary accepts `sunrise`/`sunset` with an optional minute offset: `window_start: 'sunrise'`, `window_end: 'sunset-60'`, `window_start: 'sunrise+30'`. The keyword resolves against the forecast's sunrise/sunset for the day (before any placement math), so a window like `'sunrise'`–`'sunset-60'` hugs daylight all year: summer `06:08`–`18:48`, winter `07:25`–`15:48`. If the forecast has no solar data, the boundary falls back to its fixed default (`06:00`/`19:00`) with a warning — the zone still schedules, it never gets skipped. Mixing solar and fixed across the two boundaries is fine. Validation (and `max_runs`/`window_end` semantics) runs on the *resolved* times.
- **`placement_curve: 'bell'`** — instead of spacing runs evenly across the window, the run times are placed by inverting the day's hourly ET₀ curve, clustering them in the peak-evapotranspiration hours. See [Heat-weighted placement](#heat-weighted-placement).
- Durations are frozen at schedule-build time: temperature scaling, `max_runtime`, and cycle-splitting are all applied when the day's schedule is computed (using that morning's forecast temperature), so a hot afternoon won't lengthen that afternoon's run. This is what keeps split sub-cycles consistent within a day.
- **Daily caching** — the computed run list is written to `/tmp/wachio_schedule_<zone>_<date>.json` and reused by every cron tick that day, so a mid-day forecast change can't shift the run times. Each entry records the effective `placement` (`'uniform'`/`'bell'`, reflecting degradation) and the *resolved* `window_start`/`window_end` (after any solar keywords) so the cache is self-describing. The first tick of a day that needs a schedule does the real computation (one controller + one weather fetch); stale caches older than 2 days are cleaned up on each run. `--recompute-schedule` forces a rebuild for testing (all auto_schedule zones, or just `--zone N` when both flags are passed).
- **Testing with `--now`** — combining `--now="YYYY-MM-DD HH:MM"` with `--recompute-schedule` (and `--dry-run`) is the recommended way to verify a zone's full daily schedule at a specific simulated time without waiting for the real clock. Note that simulating a future date writes that date's cache file, which the real cron will reuse on that day — benign (it's built from the forecast for that date), but delete `/tmp/wachio_schedule_*_<date>.json` after testing if you don't want that.
- **ET₀ units** — Open-Meteo returns `et0_fao_evapotranspiration` in **millimeters** by default; the script requests it in **inches** (`precipitation_unit=inch`) and also converts `mm → in` (÷ 25.4) defensively if the response reports mm. The value shown in the `[interval]` log line is already in inches/day.
- **Missing ET₀** — if the forecast is unavailable or today's ET₀ is missing/≤0, a conservative fallback of **0.20 in/day** is used and logged (`[weather] no ET0 for today — using fallback 0.20 in/day`), so a weather outage can't crash the run.
- **Validation** — if `auto_schedule` is set but `window_start` is missing, `window_end` is not later than `window_start`, the controller reports no crop coefficient (`customCrop.coefficient` missing/0), or the bucket inputs can't be resolved, the zone is skipped with a clear `[interval]`/`[schedule]` message, and an empty result is cached so the message is logged once per day rather than every minute.

## Heat-weighted placement

With `window_end` set, a zone's runs can be placed **evenly** across the window (`placement_curve` unset or `'uniform'`) or **heat-weighted** (`placement_curve: 'bell'`): run times are derived from the day's hourly ET₀ forecast so they cluster in the hours with the highest evaporative demand, where the water is needed most.

Conceptually the forecast's hourly ET₀ over the window looks like a bell — near zero at night, peaking in mid-afternoon:

```
ET₀
  ▲
  │            ·       ·
  │          ·   ·   ·   ·            ← runs (uniform) land at
  │         ·     · ·     ·              even intervals: 05:30  08:15  11:00  13:45  16:30
  │    ·   ·       ·       ·
  │  ·  ·             ·     ·  ·
  │ ·                     ·   ·
  └──────────────────────────────────▶  ← runs (bell) land where the
    05  08  11  14  17  20  23  hour      area under the curve is equal
```

The implementation builds the window's cumulative distribution (each minute weighted by that hour's ET₀ ÷ 60) and places the `n` runs at the minute where each `i/(n-1)` fraction of the total window ET₀ has accumulated (first run at `window_start`, last at `window_end`). Because each minute gets an equal slice of its hour, boundaries that cut mid-hour (e.g. a `16:30` end) are weighted proportionally.

- **Example** — window `05:30`–`16:30`, 5 runs, with hourly ET₀ (inches) `[0,0,0,0,0,0,0,0, 0.01, 0.02, 0.03, 0.04, 0.05, 0.06, 0.06, 0.05, 0.04, 0.03, ...]` over the window. The uniform placement is `05:30, 08:15, 11:00, 13:45, 16:30`. Bell inverts the curve's cumulative total (0.34 in across the window) at the 0/25/50/75/100% marks → `05:30, 11:37, 13:19, 14:44, 16:30`. Same 5 runs, same per-run duration → same total water; only the timing shifts toward the peak hours.
- **Real forecast** — a warm summer day's actual ET₀ curve placed zone 3's runs (window `05:30`–`16:30`) at `05:30, 11:40, 13:23, 14:53, 16:30`, i.e. the four mid-day hours get the spacing while the run at `08:15` (uniform) moves up to `11:40`. The exact shape varies with the day's forecast — a cooler/overcast day with a flatter ET₀ curve produces nearly-uniform placement.
- **Hourly data is only requested when it can be used** — Open-Meteo's hourly `et0_fao_evapotranspiration` is added to the forecast request only when at least one **enabled `auto_schedule` zone that has a `window_end`** actually uses `placement_curve: 'bell'`. A disabled zone, a non-auto_schedule zone, a bell zone without `window_end`, or a zone with any other curve value never triggers the extra payload — Open-Meteo is not asked for data nothing will use.
- **Degradation** — if hourly ET₀ is unavailable or is 0 across the window, the zone falls back to uniform placement with a warning (`[schedule] ... using uniform placement`); it never skips the zone. Unknown `placement_curve` values behave the same way.
- **Composition** — bell placement runs on the *resolved* window, so it composes freely with solar keywords (`window_start: 'sunrise'`, `window_end: 'sunset'` → bell places runs within the actual daylight span) and with `max_cycle_minutes` splitting (sub-cycles are expanded per run time exactly as for uniform placement).

## Cycle & soak

Rachio's `start_multiple` API takes one duration per zone and has no native cycle-and-soak support, so splitting is emulated at the schedule layer: a run longer than `max_cycle_minutes` is broken into roughly-equal sub-cycles (each ≤ the cap), and each sub-cycle becomes its own scheduled run time spaced by *run duration + `soak_minutes`*.

- **`auto_schedule` zones — fully automatic.** Splitting happens when the day's schedule is built, so the cached run list already contains the sub-cycles as distinct entries (each carrying its explicit sub-cycle duration), and each fires its own API call via a normal cron tick.
- **Manually-scheduled zones (static `times`)** — the schedule is user-authored, so the script cannot inject extra run times without violating your `times`. Instead, a run that would exceed `max_cycle_minutes` is **capped at the cap and logged** (`[cycle] ... capping run`), accepting possible under-delivery that day. If a manual zone regularly needs splitting, convert it to `auto_schedule`, or author multiple `times` entries yourself with a smaller `runtime_basis`/`fixed_runtime`.

## Collision handling

Each cron tick is a short-lived process that identifies due zones, sends one `start_multiple` PUT, and exits — it never waits for the physical watering to finish. A collision (two overlapping `start_multiple` calls) **can't** happen, because the tick checks `getDeviceState` immediately before sending and refuses to send if the controller isn't `IDLE`. Without this feature, though, that refusal meant the tick's runs were **silently lost for the day** — no retry, no catch-up. With solar-anchored and fixed-clock zones, run times can drift into alignment over the season, making a lost run a real risk.

Instead of skipping, the tick now **queues for retry**:

- **Busy → queued.** When the controller reports anything but `IDLE`, every zone that was about to be sent is written to `/tmp/wachio_pending.json` (zone, frozen duration, original due time, and an expiry timestamp) and logged: `[pending] Zone 4: queued 250s run (was due 05:30) for retry`.
- **Retried every subsequent tick.** Each tick starts by dropping expired entries (`PendingQueue::prune`) and then merges any remaining queued runs into the batch alongside freshly-due zones — they pass through the same `getDeviceState` check, so a still-busy controller simply keeps them queued. A retried run uses its **frozen** duration (exactly what was computed at its original due time — never recomputed), so it delivers the same water the equation originally decided on.
- **Expiry (default 15 min).** A queued run waits at most `PENDING_MAX_AGE_S` for the controller to free up. If it expires, it's logged and dropped — `[pending] Zone 4: queued run for 05:30 expired unfired after 900s — controller never became idle in time` — never fired late. An occasional lost run from a genuinely stuck controller is an acceptable, visible failure; firing hours late is worse.
- **One entry per zone.** If a zone is already queued and becomes due again, the newer occurrence is dropped with a warning (`already queued — skipping newly-due occurrence`) rather than stacking, so a zone can never be watered twice from queue pile-up. The pending entry that's removed only after a successful send (or dry-run would-send).
- **`/tmp` lifecycle.** The queue lives in `/tmp` like the schedule and weather caches, so a container recreate wipes it along with everything else — no stale entries survive pointing at zone numbers that may have changed in `schedule.php`.
- **`--simulate-busy` (testing).** Forces `getDeviceState` to report `WATERING` so the queue path can be exercised end-to-end. Because the state is never `IDLE`, nothing can ever be sent while it's active — it's safe even in real mode. In dry-run without it, the state check is skipped entirely (no unnecessary API call) and the tick proceeds as if idle, so `--simulate-busy` is the only way dry-run shows the busy/queue decision.

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
