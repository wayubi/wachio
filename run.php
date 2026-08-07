#!/usr/bin/php
<?php

namespace W;

/**
 * Wachio - scheduled watering for Rachio irrigation controllers.
 *
 * A per-zone schedule drives when zones water; runtimes are computed from the
 * forecast temperature; watering is skipped on rain, cold, or an active rain
 * delay on the controller. MIT licensed - see LICENSE.
 */

class Model
{
	private static $rain_check_days = 2; // max 10
	private static $temperature_floor = 55; // F, no watering at or below this
	private static $temperature_full  = 90; // F, 100% of runtime_basis at this

	private static $calendar = [
		1  => ['temperature_basis' => 'Low',  'multiplier' => 1.0],
		2  => ['temperature_basis' => 'Low',  'multiplier' => 1.0],
		3  => ['temperature_basis' => 'Avg',  'multiplier' => 1.0],
		4  => ['temperature_basis' => 'Avg',  'multiplier' => 1.0],
		5  => ['temperature_basis' => 'Avg',  'multiplier' => 1.0],
		6  => ['temperature_basis' => 'High', 'multiplier' => 1.0],
		7  => ['temperature_basis' => 'High', 'multiplier' => 1.0],
		8  => ['temperature_basis' => 'High', 'multiplier' => 1.0],
		9  => ['temperature_basis' => 'Avg',  'multiplier' => 1.0],
		10 => ['temperature_basis' => 'Avg',  'multiplier' => 1.0],
		11 => ['temperature_basis' => 'Avg',  'multiplier' => 1.0],
		12 => ['temperature_basis' => 'Low',  'multiplier' => 1.0],
	];

	public static function getRainCheckDays()
	{
		return (int) static::$rain_check_days;
	}

	public static function getTemperatureFull()
	{
		return (int) static::$temperature_full;
	}

	public static function getTemperatureBasis($timezone)
	{
		date_default_timezone_set($timezone);
		return static::$calendar[date('n')]['temperature_basis'];
	}

	public static function getRunDose($basis, $temperature)
	{
		$factor = ((float) $temperature - static::$temperature_floor)
		        / (static::$temperature_full - static::$temperature_floor);
		$factor = max(0.0, min(1.0, $factor));

		$multiplier = (float) static::$calendar[date('n')]['multiplier'];
		return (float) ( $basis * $factor * $multiplier );
	}
}

class Rachio
{
	private static $timezone = 'America/Denver'; // fallback if schedule.php omits it
	private static $dry_run = true; // fallback if schedule.php omits it

	private static $zones = []; // populated from schedule.php at runtime

	private static $device = null; // cached device object for this process
	private static $recompute_schedule = false; // forced by --recompute-schedule
	private static $auto_schedule_built = false; // true once today's caches are (re)built this process

	/**
	 * Loads the schedule (timezone, dry_run + zones) from schedule.php, falling
	 * back to schedule.example.php if it is missing. See schedule.example.php
	 * for the full set of options.
	 */
	private static function schedule()
	{
		static $schedule = null;

		if ($schedule === null) {
			$local    = __DIR__ . '/schedule.php';
			$schedule = is_file($local) ? require $local : require __DIR__ . '/schedule.example.php';
		}

		return $schedule;
	}

	public static function run()
	{
		$options = static::parseArgs();

		$lock = @fopen('/tmp/wachio.lock', 'c');
		if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
			echo '=== Another instance is already running ===' . PHP_EOL;
			exit(0);
		}

		$schedule = static::schedule();
		static::$timezone = $schedule['timezone'] ?? static::$timezone;
		static::$zones    = $schedule['zones'] ?? [];
		static::$dry_run  = $schedule['dry_run'] ?? static::$dry_run;
		static::$recompute_schedule = (bool) $options['recompute_schedule'];

		date_default_timezone_set(static::$timezone);
		$now      = time();
		$now_time = date('H:i', $now);
		$dow      = (int) date('N', $now); // 1=Mon .. 7=Sun

		$api_token = getenv('RACHIO_TOKEN');

		ScheduleCache::cleanup();

		if (static::needsAutoScheduleBuild($options['zone'])) {
			if (empty($api_token)) {
				fwrite(STDERR, 'RACHIO_TOKEN environment variable is not set' . PHP_EOL);
				exit(1);
			}
			static::$device = static::fetchDevice($api_token);
			Weather::request((float) static::$device->latitude, (float) static::$device->longitude, static::$timezone);
			static::buildAutoScheduleCaches($options['zone']);
		}

		$due = static::dueZones($options['zone'], $now_time, $dow);

		if (empty($due)) {
			echo sprintf('[%s] Nothing scheduled', $now_time) . PHP_EOL;
			exit(0);
		}

		if (empty($api_token)) {
			fwrite(STDERR, 'RACHIO_TOKEN environment variable is not set' . PHP_EOL);
			exit(1);
		}

		$headers = [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $api_token,
		];

		try {
			if (static::$device === null) {
				static::$device = static::fetchDevice($api_token);
			}
			$device    = static::$device;
			$device_id = (string) $device->id;
			$rain_delay_expiration = (int) ( $device->rainDelayExpirationDate ?? 0 );

			if ($rain_delay_expiration > $now) {
				echo '=== Stopping: Rain Delay active on controller ===' . PHP_EOL;
				exit(0);
			}

			if (!Weather::isLoaded()) {
				Weather::request((float) $device->latitude, (float) $device->longitude, static::$timezone);
			}

			if (Weather::rainForecast()) {
				echo '=== Stopping: Rain forecast within ' . Model::getRainCheckDays() . ' days ===' . PHP_EOL;
				exit(0);
			}

			$temperature_basis = Model::getTemperatureBasis(static::$timezone);
			$temperature       = (int) Weather::getTemperature($temperature_basis);

			$start_zones = static::buildZones($due, $device, $temperature, $now_time, $options['zone']);

			if (empty($start_zones)) {
				echo '=== No zones to water ===' . PHP_EOL;
				exit(0);
			}

			$payload = json_encode(['zones' => $start_zones]);

			$dry_run = static::$dry_run || $options['dry_run'];
			if ($dry_run) {
				echo sprintf('[dry-run] temperature(%s) = %dF', $temperature_basis, $temperature) . PHP_EOL;
				echo '[dry-run] would PUT ' . $payload . PHP_EOL;
				exit(0);
			}

			$state = static::deviceState($device_id, $headers);
			echo '[status] controller state: ' . $state . PHP_EOL;
			if ($state !== 'IDLE') {
				echo '=== Stopping: controller is not idle (a zone may be running) ===' . PHP_EOL;
				exit(0);
			}

			Curl::request('https://api.rach.io/1/public/zone/start_multiple', $headers, 'PUT', $payload);
			echo '=== Done: Lawn Watered ===' . PHP_EOL;
		} catch (\RuntimeException $e) {
			fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
			exit(1);
		}
	}

	private static function fetchDevice($api_token)
	{
		$headers = [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $api_token,
		];

		$result    = Curl::request('https://api.rach.io/1/public/person/info', $headers);
		$person_id = (string) $result->id;
		$result    = Curl::request('https://api.rach.io/1/public/person/' . $person_id, $headers);

		return $result->devices[0];
	}

	private static function needsAutoScheduleBuild($override)
	{
		foreach (static::$zones as $number => $zone) {
			if (empty($zone['enabled']) || empty($zone['auto_schedule'])) continue;
			if ($override !== null && (int) $number !== $override) continue;
			if (static::$recompute_schedule) return true;
			if (ScheduleCache::read($number, date('Y-m-d')) === null) return true;
		}

		return false;
	}

	private static function buildAutoScheduleCaches($override)
	{
		foreach (static::$zones as $number => $zone) {
			if (empty($zone['enabled']) || empty($zone['auto_schedule'])) continue;
			if ($override !== null && (int) $number !== $override) continue;
			static::dailyScheduleFor($number);
		}
		static::$auto_schedule_built = true;
	}

	private static function parseArgs()
	{
		$dry_run = false;
		$zone    = null;
		$recompute_schedule = false;

		$args = $_SERVER['argv'];
		for ($i = 1; $i < count($args); $i++) {
			if ($args[$i] === '--dry-run') {
				$dry_run = true;
			} elseif ($args[$i] === '--recompute-schedule') {
				$recompute_schedule = true;
			} elseif (preg_match('/^--zone=(\d+)$/', $args[$i], $m)) {
				$zone = (int) $m[1];
			} elseif ($args[$i] === '--zone' && isset($args[$i + 1])) {
				$zone = (int) $args[$i + 1];
				$i++;
			}
		}

		return ['dry_run' => $dry_run, 'zone' => $zone, 'recompute_schedule' => $recompute_schedule];
	}

	private static function dueZones($override, $time, $dow)
	{
		if ($override !== null) {
			if (!isset(static::$zones[$override])) {
				fwrite(STDERR, 'Unknown zone: ' . $override . PHP_EOL);
				exit(1);
			}
			return [$override];
		}

		$due = [];
		foreach (static::$zones as $number => $zone) {
			if (empty($zone['enabled'])) continue;

			if (!empty($zone['auto_schedule'])) {
				foreach (static::dailyScheduleFor($number) as $run) {
					if ($run['time'] === $time) {
						$due[] = $number;
						break;
					}
				}
				continue;
			}

			foreach (static::schedulesForZone($zone) as $schedule) {
				if (static::scheduleMatches($schedule, $time, $dow)) {
					$due[] = $number;
					break;
				}
			}
		}

		return $due;
	}

	private static function schedulesForZone($zone)
	{
		if (isset($zone['schedules'])) {
			$schedules = [];
			foreach ($zone['schedules'] as $schedule) {
				$schedules[] = [
					'times' => $schedule['times'] ?? [],
					'days'  => $schedule['days'] ?? null,
				];
			}
			return $schedules;
		}

		return [
			[
				'times' => $zone['times'] ?? [],
				'days'  => $zone['days'] ?? null,
			],
		];
	}

	private static function scheduleMatches($schedule, $time, $dow)
	{
		if (empty($schedule['times'])) return false;
		if (!in_array($time, $schedule['times'], true)) return false;
		if ($schedule['days'] !== null && !in_array($dow, $schedule['days'], true)) return false;
		return true;
	}

	private static function deviceState($device_id, $headers)
	{
		$result = Curl::request('https://cloud-rest.rach.io/device/getDeviceState/' . $device_id, $headers);
		$state  = $result->state->state ?? $result->state ?? null;
		if (!is_string($state)) {
			echo '[status] getDeviceState unexpected: ' . json_encode($result) . PHP_EOL;
			return '';
		}
		return $state;
	}

	private static function autoBasis($device_zone, $zone)
	{
		$resolved = static::resolveZoneInputs($device_zone, $zone, [
			'available_water',
			'root_depth',
			'allowed_depletion',
			'nozzle_rate',
		]);
		$values  = $resolved['values'];
		$invalid = $resolved['invalid'];

		$eff = $zone['efficiency'] ?? $device_zone->efficiency ?? null;
		if ($eff === null) {
			$eff = 1.0;
		} else {
			$eff = (float) $eff;
		}
		$values['efficiency'] = $eff;
		if ($eff <= 0) {
			$invalid[] = 'efficiency';
		}

		$parts = [];
		foreach ($values as $label => $value) {
			$parts[] = in_array($label, $invalid, true) ? $label . '=MISSING' : $label . '=' . $value;
		}

		$name = $zone['name'] ?? 'Zone';
		if ($invalid) {
			echo '[auto] ' . $name . ': ' . implode(', ', $parts)
			     . ' → could not compute basis (invalid: ' . implode(', ', $invalid) . ')' . PHP_EOL;
			return null;
		}

		$water  = $values['available_water'];
		$depth  = $values['root_depth'];
		$mad    = $values['allowed_depletion'];
		$rate   = $values['nozzle_rate'];

		$bucket = $water * $depth * $mad;
		$basis  = $bucket / $rate / $eff * 60;

		echo sprintf(
			'[auto] %s: %.2f in/in × %d in × %d%% ÷ %.2f in/hr ÷ %.2f eff → %.2fm basis',
			$name,
			$water,
			$depth,
			(int) round($mad * 100),
			$rate,
			$eff,
			$basis
		) . PHP_EOL;

		return $basis;
	}

	private static function zoneInput($device_zone, $zone, $key)
	{
		if (isset($zone[$key])) {
			return (float) $zone[$key];
		}

		$paths = [
			'available_water'   => ['availableWater'],
			'root_depth'        => ['rootZoneDepth'],
			'allowed_depletion' => ['managementAllowedDepletion'],
			'nozzle_rate'       => ['customNozzle', 'inchesPerHour'],
			'crop_coefficient'  => ['customCrop', 'coefficient'],
		];
		$path = $paths[$key] ?? null;
		if ($path === null) return 0.0;

		$value = $device_zone;
		foreach ($path as $part) {
			$value = $value->$part ?? null;
			if ($value === null) return 0.0;
		}

		return (float) $value;
	}

	private static function resolveZoneInputs($device_zone, $zone, array $keys)
	{
		$values  = [];
		$invalid = [];
		foreach ($keys as $key) {
			$values[$key] = static::zoneInput($device_zone, $zone, $key);
			if ($values[$key] <= 0) {
				$invalid[] = $key;
			}
		}

		return ['values' => $values, 'invalid' => $invalid];
	}

	private static function autoInterval($device_zone, $zone)
	{
		$name = $zone['name'] ?? 'Zone';

		$kc = static::zoneInput($device_zone, $zone, 'crop_coefficient');
		if ($kc <= 0) {
			echo '[interval] ' . $name . ': crop_coefficient missing or 0 on the controller (required when auto_schedule is set) — skipping zone' . PHP_EOL;
			return null;
		}

		$resolved = static::resolveZoneInputs($device_zone, $zone, [
			'available_water',
			'root_depth',
			'allowed_depletion',
		]);
		if ($resolved['invalid']) {
			echo '[interval] ' . $name . ': bucket inputs missing (invalid: ' . implode(', ', $resolved['invalid']) . ') — skipping zone' . PHP_EOL;
			return null;
		}

		$water  = $resolved['values']['available_water'];
		$depth  = $resolved['values']['root_depth'];
		$mad    = $resolved['values']['allowed_depletion'];
		$bucket = $water * $depth * $mad;

		$et0        = Weather::getET0();
		$daily_loss = $kc * $et0;
		if ($daily_loss <= 0) {
			echo '[interval] ' . $name . ': daily loss is 0 (Kc ' . $kc . ' × ET0 ' . $et0 . ') — skipping zone' . PHP_EOL;
			return null;
		}

		$interval_hr = ($bucket / $daily_loss) * 24;
		if ($interval_hr < 0.5) {
			$interval_hr = 0.5;
		}

		echo sprintf(
			'[interval] %s: bucket=%.3fin ÷ (Kc=%.2f × ET0=%.2fin/day) → interval=%.1fh',
			$name,
			$bucket,
			$kc,
			$et0,
			$interval_hr
		) . PHP_EOL;

		return $interval_hr;
	}

	private static function splitCycles($duration_s, $max_cycle_minutes, $soak_minutes)
	{
		$max_cycle_s = (int) round($max_cycle_minutes * 60);
		if ($duration_s <= $max_cycle_s) {
			return [$duration_s];
		}

		$n    = (int) ceil($duration_s / $max_cycle_s);
		$base = (int) floor($duration_s / $n);
		$rem  = $duration_s - $base * $n;

		$cycles = [];
		for ($i = 0; $i < $n; $i++) {
			$cycles[] = $base + ($i < $rem ? 1 : 0);
		}

		return $cycles;
	}

	private static function minutesToTime($minutes)
	{
		$minutes = (int) round($minutes);
		return sprintf('%02d:%02d', (int) floor($minutes / 60), $minutes % 60);
	}

	private static function buildDailySchedule($device_zone, $zone, $interval_hr)
	{
		$name   = $zone['name'] ?? 'Zone';
		$window = $zone['window_start'];

		$parts = explode(':', $window);
		$start = (int) $parts[0] * 60 + (int) $parts[1];

		$max_runs = isset($zone['max_runs']) ? (int) $zone['max_runs'] : 6;
		if ($max_runs < 1) $max_runs = 1;

		$max_cycle_minutes = isset($zone['max_cycle_minutes']) ? (float) $zone['max_cycle_minutes'] : 10;
		$soak_minutes      = isset($zone['soak_minutes']) ? (float) $zone['soak_minutes'] : 20;

		$basis_m = null;
		$auto    = false;
		if (!empty($zone['auto_runtime'])) {
			$basis_m = static::autoBasis($device_zone, $zone);
			if ($basis_m === null) {
				echo 'Skip ' . $name . ': could not compute runtime from zone settings' . PHP_EOL;
				return [];
			}
			$auto = true;
		} elseif (isset($zone['fixed_runtime'])) {
			$basis_m = (float) $zone['fixed_runtime'];
		} elseif (isset($zone['runtime_basis'])) {
			$basis_m = (float) $zone['runtime_basis'];
		} else {
			echo 'Skip ' . $name . ': no duration source (auto_runtime/runtime_basis/fixed_runtime) for auto_schedule' . PHP_EOL;
			return [];
		}

		$temperature_basis = Model::getTemperatureBasis(static::$timezone);
		$temperature       = (int) Weather::getTemperature($temperature_basis);

		$runs    = [];
		$now_min = (float) $start;
		$step    = $interval_hr * 60;

		while (count($runs) < $max_runs && $now_min < 24 * 60) {
			if ($auto || !isset($zone['fixed_runtime'])) {
				$per_run = (float) Model::getRunDose($basis_m, $temperature);
				if (isset($zone['max_runtime']) && $per_run > (float) $zone['max_runtime']) {
					$per_run = (float) $zone['max_runtime'];
				}
			} else {
				$per_run = (float) $basis_m;
			}

			$duration_s = (int) round($per_run * 60);

			if ($duration_s < 1) {
				echo sprintf('[schedule] %s: skip %s (computed runtime is 0)', $name, static::minutesToTime($now_min)) . PHP_EOL;
				$now_min += $step;
				continue;
			}

			$t = $now_min;
			foreach (static::splitCycles($duration_s, $max_cycle_minutes, $soak_minutes) as $cycle_s) {
				if (count($runs) >= $max_runs) break;
				$runs[] = [
					'time'       => static::minutesToTime($t),
					'duration_s' => $cycle_s,
				];
				$t += $cycle_s / 60 + $soak_minutes;
			}

			$now_min = max($now_min + $step, $t);
		}

		return $runs;
	}

	private static function dailyScheduleFor($number)
	{
		$zone = static::$zones[$number] ?? null;
		if ($zone === null || empty($zone['enabled']) || empty($zone['auto_schedule'])) {
			return [];
		}

		$name = $zone['name'] ?? 'Zone';
		$date = date('Y-m-d');

		if (static::$recompute_schedule && !static::$auto_schedule_built) {
			@unlink(ScheduleCache::file($number, $date));
		}

		$cached = ScheduleCache::read($number, $date);
		if ($cached !== null) {
			return $cached['runs'];
		}

		if (empty($zone['window_start'])) {
			echo '[schedule] ' . $name . ': auto_schedule set but window_start missing — skipping zone' . PHP_EOL;
			ScheduleCache::write($number, $date, [
				'computed_at' => time(),
				'interval_hr' => 0,
				'runs'        => [],
			]);
			return [];
		}

		if (static::$device === null || !Weather::isLoaded()) {
			echo '[schedule] ' . $name . ': cannot compute schedule (device/weather unavailable) — skipping zone' . PHP_EOL;
			return [];
		}

		$device_zone = static::deviceZoneFor(static::$device, $number);
		if ($device_zone === null) {
			echo 'Skip ' . $name . ': zone number ' . $number . ' not found on device' . PHP_EOL;
			ScheduleCache::write($number, $date, [
				'computed_at' => time(),
				'interval_hr' => 0,
				'runs'        => [],
			]);
			return [];
		}

		$interval_hr = static::autoInterval($device_zone, $zone);
		if ($interval_hr === null) {
			ScheduleCache::write($number, $date, [
				'computed_at' => time(),
				'interval_hr' => 0,
				'runs'        => [],
			]);
			return [];
		}

		$runs = static::buildDailySchedule($device_zone, $zone, $interval_hr);

		ScheduleCache::write($number, $date, [
			'computed_at' => time(),
			'interval_hr' => $interval_hr,
			'runs'        => $runs,
		]);

		return $runs;
	}

	private static function deviceZoneFor($device, $number)
	{
		foreach ($device->zones as $z) {
			if ((int) $z->zoneNumber === (int) $number) {
				return $z;
			}
		}

		return null;
	}

	private static function buildZones($due, $device, $temperature, $now_time, $override)
	{
		$start_zones = [];
		$sort_order  = 1;

		foreach ($due as $number) {
			$zone = static::$zones[$number];

			if (empty($zone['enabled'])) {
				echo 'Skip ' . $zone['name'] . ': not enabled in schedule' . PHP_EOL;
				continue;
			}
			if (empty($zone['auto_schedule']) && empty($zone['auto_runtime']) && !isset($zone['fixed_runtime']) && (float) ($zone['runtime_basis'] ?? 0) <= 0) {
				echo 'Skip ' . $zone['name'] . ': runtime_basis is 0' . PHP_EOL;
				continue;
			}
			if (isset($zone['min_temp']) && $temperature < (int) $zone['min_temp']) {
				echo sprintf('Skip %s: %dF below min_temp %dF', $zone['name'], $temperature, $zone['min_temp']) . PHP_EOL;
				continue;
			}

			$device_zone = static::deviceZoneFor($device, $number);
			if ($device_zone === null) {
				echo 'Skip ' . $zone['name'] . ': zone number ' . $number . ' not found on device' . PHP_EOL;
				continue;
			}
			if (!$device_zone->enabled) {
				echo 'Skip ' . $zone['name'] . ': disabled on device' . PHP_EOL;
				continue;
			}

			if (!empty($zone['auto_schedule'])) {
				$runs     = static::dailyScheduleFor($number);
				$duration = null;
				foreach ($runs as $run) {
					if ($run['time'] === $now_time) {
						$duration = (int) $run['duration_s'];
						break;
					}
				}
				if ($duration === null && $override !== null && $runs) {
					// --zone N forces the zone regardless of the clock; the first
					// entry of the day's schedule may be a split sub-cycle rather
					// than a full-length run, so this is not a "typical" duration.
					$duration = (int) $runs[0]['duration_s'];
					echo sprintf(
						'[zone-override] %s: no run at %s (--zone override) — using first scheduled duration %ds',
						$zone['name'],
						$now_time,
						$duration
					) . PHP_EOL;
				}
				if ($duration === null || $duration < 1) {
					echo 'Skip ' . $zone['name'] . ': no scheduled run matched this tick' . PHP_EOL;
					continue;
				}

				$start_zones[] = [
					'id'        => (string) $device_zone->id,
					'duration'  => $duration,
					'sortOrder' => $sort_order++,
				];
				echo sprintf('Queue %s: scheduled run → %ds', $zone['name'], $duration) . PHP_EOL;
				continue;
			}

			$basis_m = null;
			$auto    = false;

			if (!empty($zone['auto_runtime'])) {
				$basis_m = static::autoBasis($device_zone, $zone);
				if ($basis_m === null) {
					echo 'Skip ' . $zone['name'] . ': could not compute runtime from zone settings' . PHP_EOL;
					continue;
				}
				$auto = true;
			} elseif (isset($zone['fixed_runtime'])) {
				$basis_m = (float) $zone['fixed_runtime'];
			} else {
				$basis_m = (float) $zone['runtime_basis'];
			}

			if ($auto || !isset($zone['fixed_runtime'])) {
				$per_run = (float) Model::getRunDose($basis_m, $temperature);
				$factor  = $basis_m > 0 ? $per_run / $basis_m : 0;
				$capped  = false;
				if (isset($zone['max_runtime']) && $per_run > (float) $zone['max_runtime']) {
					$per_run = (float) $zone['max_runtime'];
					$capped  = true;
				}
				$duration = (int) round($per_run * 60);
				echo sprintf(
					'[basis] %s: %.2fm basis × temp %.2f @ %dF → %.2fm → %ds',
					$zone['name'] ?? 'Zone',
					$basis_m,
					$factor,
					$temperature,
					$per_run,
					$duration
				) . PHP_EOL;
				if ($capped) {
					echo '[basis] ' . $zone['name'] . ': capped by max_runtime ' . $zone['max_runtime'] . 'm' . PHP_EOL;
				}
			} else {
				$duration = (int) round($basis_m * 60);
				echo '[basis] ' . $zone['name'] . ': fixed ' . round($basis_m, 2) . 'm → ' . $duration . 's' . PHP_EOL;
			}
			if ($duration < 1) {
				echo 'Skip ' . $zone['name'] . ': computed runtime is 0' . PHP_EOL;
				continue;
			}

			$max_cycle_minutes = isset($zone['max_cycle_minutes']) ? (float) $zone['max_cycle_minutes'] : 10;
			if ($duration > $max_cycle_minutes * 60) {
				echo sprintf('[cycle] %s: %ds exceeds max_cycle_minutes %dm — capping run', $zone['name'], $duration, $max_cycle_minutes) . PHP_EOL;
				$duration = (int) round($max_cycle_minutes * 60);
			}

			$start_zones[] = [
				'id'        => (string) $device_zone->id,
				'duration'  => $duration,
				'sortOrder' => $sort_order++,
			];

			echo sprintf('Queue %s: %.2fm @ %dF → %ds', $zone['name'], $basis_m, $temperature, $duration) . PHP_EOL;
		}

		return $start_zones;
	}
}

class Weather
{
	private static $data = [];
	private static $fallback_full = false;
	private static $loaded = false;

	private static $et0_fallback = 0.20; // inches/day, used when ET0 is missing

	private const CACHE_FILE    = '/tmp/wachio_weather.json';
	private const CACHE_MAX_AGE = 12 * 3600; // 12 hours

	public static function request($latitude, $longitude, $timezone)
	{
		$url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
			'latitude'            => $latitude,
			'longitude'           => $longitude,
			'daily'               => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max,et0_fao_evapotranspiration',
			'temperature_unit'    => 'fahrenheit',
			'precipitation_unit'  => 'inch', // et0_fao_evapotranspiration is mm unless this is set
			'timezone'            => $timezone,
			'forecast_days'       => 7,
		]);

		try {
			$result = static::fetch($url);
			$data   = static::buildDaily($result);

			static::$data = $data;
			static::$fallback_full = false;
			static::$loaded = true;
			static::writeCache($data);
			return;
		} catch (\RuntimeException $e) {
			$cached = static::readCache();
			if ($cached !== null) {
				static::$data = $cached['data'];
				static::$fallback_full = false;
				static::$loaded = true;
				$age_h = (int) ceil((time() - $cached['ts']) / 3600);
				echo sprintf('[weather] using cached forecast (%dh old)', $age_h) . PHP_EOL;
				return;
			}

			static::$data = [];
			static::$fallback_full = true;
			static::$loaded = true;
			echo '[weather] no fresh forecast available — running at full dose' . PHP_EOL;
		}
	}

	public static function isLoaded()
	{
		return static::$loaded;
	}

	private static function fetch($url)
	{
		$transient = [429, 500, 502, 503, 504];
		$attempts  = 3;

		for ($try = 1; $try <= $attempts; $try++) {
			try {
				return Curl::request($url);
			} catch (HttpException $e) {
				if (in_array($e->getCode(), $transient, true) && $try < $attempts) {
					sleep($try * 2);
					continue;
				}
				throw $e;
			}
		}
	}

	private static function buildDaily($data)
	{
		if (!isset($data->daily) || !isset($data->daily->time)) {
			throw new \RuntimeException('Unexpected weather response');
		}

		$daily = $data->daily;
		$unit  = strtolower((string) ( $data->daily_units->et0_fao_evapotranspiration ?? 'mm' ));
		$data  = [];

		for ($i = 0; $i < count($daily->time); $i++) {
			$date   = (string) $daily->time[$i];
			$high   = (int) $daily->temperature_2m_max[$i];
			$low    = (int) $daily->temperature_2m_min[$i];
			$precip = (int) ( $daily->precipitation_probability_max[$i] ?? 0 );
			$et0    = (float) ( $daily->et0_fao_evapotranspiration[$i] ?? 0 );
			if ($unit === 'mm') {
				$et0 = $et0 / 25.4;
			}

			$data[$date] = [
				'high' => $high,
				'low'  => $low,
				'avg'  => (int) (($high + $low) / 2),
				'rain' => $precip > 50,
				'et0'  => $et0,
			];
		}

		return $data;
	}

	private static function writeCache($data)
	{
		@file_put_contents(static::CACHE_FILE, json_encode([
			'cached_at' => time(),
			'data'      => $data,
		]));
	}

	private static function readCache()
	{
		$raw = @file_get_contents(static::CACHE_FILE);
		$j   = json_decode($raw === false ? '' : $raw, true);
		if (!is_array($j) || !isset($j['cached_at'], $j['data']) || !is_array($j['data'])) {
			return null;
		}
		if (time() - (int) $j['cached_at'] > static::CACHE_MAX_AGE) {
			return null;
		}

		return ['ts' => (int) $j['cached_at'], 'data' => $j['data']];
	}

	public static function rainForecast()
	{
		if (static::$fallback_full) return false;

		$i = 0;
		foreach (static::$data as $data) {
			if ($i == Model::getRainCheckDays()) break;
			if ($data['rain']) return true;
			$i++;
		}

		return false;
	}

	public static function getTemperature($basis)
	{
		if (static::$fallback_full) {
			return (int) Model::getTemperatureFull();
		}

		$key = date('Y-m-d');
		if (!isset(static::$data[$key]) || !isset(static::$data[$key][strtolower($basis)])) {
			throw new \RuntimeException('No temperature data for ' . $key);
		}

		return (int) static::$data[$key][strtolower($basis)];
	}

	public static function getET0()
	{
		if (static::$fallback_full) {
			echo sprintf('[weather] no ET0 for today — using fallback %.2f in/day', static::$et0_fallback) . PHP_EOL;
			return (float) static::$et0_fallback;
		}

		$key = date('Y-m-d');
		$et0 = isset(static::$data[$key]['et0']) ? (float) static::$data[$key]['et0'] : 0;

		if ($et0 <= 0) {
			echo sprintf('[weather] no ET0 for today — using fallback %.2f in/day', static::$et0_fallback) . PHP_EOL;
			return (float) static::$et0_fallback;
		}

		return $et0;
	}
}

class ScheduleCache
{
	/**
	 * Per-zone, per-day JSON cache of the computed auto_schedule run list.
	 * Schema: { "computed_at": int, "interval_hr": float,
	 *           "runs": [ { "time": "HH:MM", "duration_s": int }, ... ] }
	 */
	public static function file($zone, $date)
	{
		return sprintf('/tmp/wachio_schedule_%d_%s.json', (int) $zone, $date);
	}

	public static function read($zone, $date)
	{
		$raw = @file_get_contents(static::file($zone, $date));
		$j   = json_decode($raw === false ? '' : $raw, true);
		if (!is_array($j) || !isset($j['runs']) || !is_array($j['runs'])) {
			return null;
		}

		return $j;
	}

	public static function write($zone, $date, array $data)
	{
		@file_put_contents(static::file($zone, $date), json_encode($data));
	}

	public static function cleanup($max_age = 2 * 86400)
	{
		foreach (glob('/tmp/wachio_schedule_*.json') ?: [] as $file) {
			if (time() - @filemtime($file) > $max_age) {
				@unlink($file);
			}
		}
	}
}

class HttpException extends \RuntimeException
{
}

class Curl
{
	public static function request($url, $headers = [], $method = 'GET', $postfields = null)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		if (!empty($headers)) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}
		if ($method == 'PUT') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
		}
		$result = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$error  = curl_error($ch);
		curl_close($ch);

		if ($result === false) {
			throw new \RuntimeException('cURL error: ' . $error);
		}
		if ($status < 200 || $status >= 300) {
			throw new HttpException('HTTP ' . $status . ' for ' . $url . ': ' . $result, $status);
		}

		return json_decode($result);
	}
}

\W\Rachio::run();
