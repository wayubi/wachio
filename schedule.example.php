<?php

/**
 * Template for your local schedule.
 *
 * Copy this file to schedule.php (which is gitignored) and edit that copy.
 * This example file is committed so that anyone cloning the repo knows the
 * format. The script falls back to this file only if schedule.php is missing.
 *
 * Returns: [ 'timezone' => string, 'dry_run' => bool, 'zones' => [ zoneNumber => config ] ]
 *
 * - dry_run : true = compute and print what would be sent, never touch Rachio.
 *             Set to false to actually water.
 *
 * Zone options:
 *
 * - enabled        : required to run. Only zones with 'enabled' => true are
 *                    handled by this script; absent/false = disabled (e.g.
 *                    zones managed by the Rachio app)
 * - auto_runtime   : compute runtime_basis automatically from the zone's
 *                    advanced settings on the controller (available water ×
 *                    root depth × allowed depletion ÷ nozzle rate ÷
 *                    efficiency × 60), scaled by temperature like
 *                    runtime_basis. Requires the controller's zone settings
 *                    to be populated. Optional per-zone overrides for
 *                    missing/zero values: 'available_water' (in/in),
 *                    'root_depth' (in), 'allowed_depletion' (fraction),
 *                    'nozzle_rate' (in/hr), 'efficiency' (fraction).
 *                    Efficiency defaults to 1.0 (no loss) when absent; an
 *                    explicit 0 is treated as bad input (zone skipped)
 * - runtime_basis : minutes per run at the full temperature (90F). Each
 *                   scheduled run gets this amount, scaled linearly from 0 at
 *                   the floor (55F) to 100% at the full temperature. Run a
 *                   zone 5 times a day and it gets that much water each time
 *                   (ignored when auto_runtime is set). May be fractional
 * - fixed_runtime : optional fixed minutes per run (bypasses temperature
 *                   model; ignores runtime_basis). May be fractional
 * - max_runtime   : optional per-run cap in minutes (may be fractional)
 * - min_temp      : optional F skip floor (do not water below this)
 * - schedules     : list of schedule blocks, each with:
 *     - times : list of HH:MM start times
 *     - days  : optional weekdays 1-7 (1=Mon..7=Sun), or null for every day
 * - times / days : shorthand for a single schedule block
 * - auto_schedule : compute run FREQUENCY from the zone's bucket size and
 *                   reference evapotranspiration (ET0) instead of 'times'.
 *                   Ignores 'times'/'days'. Requires 'window_start'. The crop
 *                   coefficient (Kc) is read from the controller's customCrop
 *                   (customCrop.coefficient); a missing/zero Kc skips the
 *                   zone. Pairs with auto_runtime for a fully automatic zone,
 *                   or with runtime_basis/fixed_runtime for automatic frequency
 *                   + manual duration.
 * - window_start : HH:MM of the first run of the day (required with
 *                  auto_schedule); later runs are spaced by the interval
 * - max_runs     : safety cap on runs (and split sub-cycles) per day
 *                  (auto_schedule only; default 6)
 * - max_cycle_minutes : single runs longer than this (min) are split into
 *                  sub-cycles with soak gaps (default 10). Automatic for
 *                  auto_schedule zones; manual zones are capped with a warning
 * - soak_minutes : gap (min) between split sub-cycles (default 20)
 *
 * Set times to [] (and runtime_basis to 0) to disable a zone. Zone keys must
 * match the zone numbers reported by your controller.
 *
 * Zones 2-8 below are commented out as examples; uncomment and edit as needed.
 */

return [
	'timezone' => 'America/Denver', // your local timezone
	'dry_run'  => true,             // true = never send requests to Rachio

	'zones' => [
		1 => [
			'name'          => 'Zone 1 - Basic',       // simple, every day
			'enabled'       => true,                   // explicit: required to run
			'runtime_basis' => 10,                     // 10 min at 90F
			'max_runtime'   => 30,                     // never over 30 min
			'min_temp'      => 35,                     // skip below 35F
			'times'         => ['06:00'],
			'days'          => null,                   // every day
		],

		// 2 => [  // TWICE A DAY — multiple times in one schedule
		//     'name'          => 'Zone 2 - Twice Daily',
		//     'runtime_basis' => 12,
		//     'max_runtime'   => 20,
		//     'times'         => ['06:00', '19:00'],  // morning + evening
		//     'days'          => null,
		// ],

		// 3 => [  // WEEKDAY vs WEEKEND — schedules with different day sets
		//     'name'          => 'Zone 3 - Workweek & Weekend',
		//     'runtime_basis' => 15,
		//     'schedules'     => [
		//         ['times' => ['05:30'],        'days' => [1, 2, 3, 4, 5]],  // Mon-Fri
		//         ['times' => ['08:00'],        'days' => [6, 7]],          // Sat-Sun
		//     ],
		// ],

		// 4 => [  // FIXED RUNTIME — always the same, ignores weather
		//     'name'          => 'Zone 4 - Fixed Time',
		//     'fixed_runtime' => 3,                   // exactly 3 min
		//     'times'         => ['07:00'],
		//     'days'          => [1, 4],              // Mon & Thu
		// ],

		// 5 => [  // MULTIPLE SCHEDULES — different times on different days
		//     'name'          => 'Zone 5 - Twice on Weekdays',
		//     'runtime_basis' => 22,
		//     'max_runtime'   => 45,
		//     'min_temp'      => 35,
		//     'schedules'     => [
		//         ['times' => ['06:00', '18:00'], 'days' => [2, 4, 6]],  // Tue/Thu/Sat x2
		//         ['times' => ['09:00'],          'days' => [6]],        // Sat morning too
		//     ],
		// ],

		// 6 => [  // THIRSTY & SPARSE — big basis, capped, once a week
		//     'name'          => 'Zone 6 - Weekly Deep Water',
		//     'runtime_basis' => 98,
		//     'max_runtime'   => 60,
		//     'min_temp'      => 40,
		//     'times'         => ['07:00'],
		//     'days'          => [7],                 // Sunday only
		// ],

		// 7 => [  // FROST-SENSITIVE — min_temp guard + specific days
		//     'name'          => 'Zone 7 - Frost Sensitive',
		//     'runtime_basis' => 8,
		//     'min_temp'      => 50,                  // skip below 50F
		//     'times'         => ['06:30'],
		//     'days'          => [2, 5],              // Tue & Fri
		// ],

		// 8 => [  // DISABLED — empty times (and basis 0) turns the zone off
		//     'name'          => 'Zone 8 - Disabled',
		//     'enabled'       => false,               // opt out of the script entirely
		//     'runtime_basis' => 0,
		//     'times'         => [],
		//     'days'          => null,
		// ],

		// 9 => [  // AUTO RUNTIME — compute basis from the controller's zone settings
		//     'name'          => 'Zone 9 - Auto',
		//     'enabled'       => true,
		//     'auto_runtime'  => true,                // basis = water×depth×depletion ÷ nozzle ÷ efficiency × 60
		//     // 'available_water' => 0.16,           // optional overrides if API values are missing/zero
		//     // 'root_depth'      => 2,
		//     // 'allowed_depletion' => 0.25,
		//     // 'nozzle_rate'     => 1.57,
		//     // 'efficiency'     => 0.8,             // defaults to 1.0 if absent; explicit 0 skips the zone
		//     'times'         => ['07:00'],
		//     'days'          => [1, 3, 5],
		// ],

		// 10 => [  // FULLY AUTOMATIC — auto_runtime (duration) + auto_schedule (frequency)
		//     'name'             => 'Zone 10 - Fully Automatic',
		//     'enabled'          => true,
		//     'auto_runtime'     => true,             // duration from controller settings
		//     'auto_schedule'    => true,             // frequency from bucket ÷ (Kc × ET0)
		//     // Kc comes from the controller (customCrop.coefficient); no config needed
		//     'window_start'     => '05:30',          // first run of the day (required)
		//     'max_runs'         => 6,                // optional safety cap (default 6)
		//     'max_cycle_minutes' => 10,              // optional cycle split threshold (default 10)
		//     'soak_minutes'     => 20,               // optional soak gap (default 20)
		// ],

		// 11 => [  // AUTO FREQUENCY + MANUAL DURATION
		//     'name'             => 'Zone 11 - Auto Freq, Fixed Time',
		//     'enabled'          => true,
		//     'runtime_basis'    => 8,                // manual duration, temperature-scaled
		//     'auto_schedule'    => true,             // frequency computed from ET0
		//     'window_start'     => '06:00',
		// ],
	],
];
