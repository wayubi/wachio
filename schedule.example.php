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
 * - runtime_basis : minutes of water at the full temperature (90F). Runtime
 *                   scales linearly from 0 at the floor (55F) to 100% at the
 *                   full temperature
 * - fixed_runtime : optional fixed minutes per run (bypasses temperature
 *                   model; ignores runtime_basis)
 * - max_runtime   : optional per-run cap in minutes
 * - min_temp      : optional F skip floor (do not water below this)
 * - schedules     : list of schedule blocks, each with:
 *     - times : list of HH:MM start times
 *     - days  : optional weekdays 1-7 (1=Mon..7=Sun), or null for every day
 * - times / days : shorthand for a single schedule block
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
	],
];
