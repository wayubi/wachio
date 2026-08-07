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

		date_default_timezone_set(static::$timezone);
		$now      = time();
		$now_time = date('H:i', $now);
		$dow      = (int) date('N', $now); // 1=Mon .. 7=Sun

		$due = static::dueZones($options['zone'], $now_time, $dow);

		if (empty($due)) {
			echo sprintf('[%s] Nothing scheduled', $now_time) . PHP_EOL;
			exit(0);
		}

		$api_token = getenv('RACHIO_TOKEN');
		if (empty($api_token)) {
			fwrite(STDERR, 'RACHIO_TOKEN environment variable is not set' . PHP_EOL);
			exit(1);
		}

		$headers = [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $api_token,
		];

		try {
			$result    = Curl::request('https://api.rach.io/1/public/person/info', $headers);
			$person_id = (string) $result->id;

			$result  = Curl::request('https://api.rach.io/1/public/person/' . $person_id, $headers);
			$device  = $result->devices[0];
			$device_id = (string) $device->id;
			$latitude  = (float) $device->latitude;
			$longitude = (float) $device->longitude;
			$rain_delay_expiration = (int) ( $device->rainDelayExpirationDate ?? 0 );

			if ($rain_delay_expiration > $now) {
				echo '=== Stopping: Rain Delay active on controller ===' . PHP_EOL;
				exit(0);
			}

			Weather::request($latitude, $longitude, static::$timezone);

			if (Weather::rainForecast()) {
				echo '=== Stopping: Rain forecast within ' . Model::getRainCheckDays() . ' days ===' . PHP_EOL;
				exit(0);
			}

			$temperature_basis = Model::getTemperatureBasis(static::$timezone);
			$temperature       = (int) Weather::getTemperature($temperature_basis);

			$start_zones = static::buildZones($due, $device, $temperature);

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

	private static function parseArgs()
	{
		$dry_run = false;
		$zone    = null;

		$args = $_SERVER['argv'];
		for ($i = 1; $i < count($args); $i++) {
			if ($args[$i] === '--dry-run') {
				$dry_run = true;
			} elseif (preg_match('/^--zone=(\d+)$/', $args[$i], $m)) {
				$zone = (int) $m[1];
			} elseif ($args[$i] === '--zone' && isset($args[$i + 1])) {
				$zone = (int) $args[$i + 1];
				$i++;
			}
		}

		return ['dry_run' => $dry_run, 'zone' => $zone];
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
		$water = (float) ( $zone['available_water'] ?? $device_zone->availableWater ?? 0 );
		$depth = (float) ( $zone['root_depth'] ?? $device_zone->rootZoneDepth ?? 0 );
		$mad   = (float) ( $zone['allowed_depletion'] ?? $device_zone->managementAllowedDepletion ?? 0 );
		$rate  = (float) ( $zone['nozzle_rate'] ?? $device_zone->customNozzle->inchesPerHour ?? 0 );
		$eff   = $zone['efficiency'] ?? $device_zone->efficiency ?? null;
		if ($eff === null) {
			$eff = 1.0;
		} else {
			$eff = (float) $eff;
		}

		if ($water <= 0 || $depth <= 0 || $mad <= 0 || $rate <= 0 || $eff <= 0) {
			return null;
		}

		$bucket  = $water * $depth * $mad;
		$minutes = max(1, (int) round($bucket / $rate / $eff * 60));

		echo sprintf(
			'[auto] %s: %.2f in/in × %d in × %d%% ÷ %.2f in/hr ÷ %.2f eff → %dm basis',
			$zone['name'] ?? 'Zone',
			$water,
			$depth,
			(int) round($mad * 100),
			$rate,
			$eff,
			$minutes
		) . PHP_EOL;

		return $minutes;
	}

	private static function buildZones($due, $device, $temperature)
	{
		$start_zones = [];
		$sort_order  = 1;

		foreach ($due as $number) {
			$zone = static::$zones[$number];

			if (empty($zone['enabled'])) {
				echo 'Skip ' . $zone['name'] . ': not enabled in schedule' . PHP_EOL;
				continue;
			}
			if (empty($zone['auto_runtime']) && !isset($zone['fixed_runtime']) && (int) ($zone['runtime_basis'] ?? 0) <= 0) {
				echo 'Skip ' . $zone['name'] . ': runtime_basis is 0' . PHP_EOL;
				continue;
			}
			if (isset($zone['min_temp']) && $temperature < (int) $zone['min_temp']) {
				echo sprintf('Skip %s: %dF below min_temp %dF', $zone['name'], $temperature, $zone['min_temp']) . PHP_EOL;
				continue;
			}

			$device_zone = null;
			foreach ($device->zones as $z) {
				if ((int) $z->zoneNumber === $number) {
					$device_zone = $z;
					break;
				}
			}
			if ($device_zone === null) {
				echo 'Skip ' . $zone['name'] . ': zone number ' . $number . ' not found on device' . PHP_EOL;
				continue;
			}
			if (!$device_zone->enabled) {
				echo 'Skip ' . $zone['name'] . ': disabled on device' . PHP_EOL;
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
				$basis_m = (int) $zone['fixed_runtime'];
			} else {
				$basis_m = (int) $zone['runtime_basis'];
			}

			if ($auto || !isset($zone['fixed_runtime'])) {
				$per_run = (float) Model::getRunDose($basis_m, $temperature);
				if (isset($zone['max_runtime']) && $per_run > (int) $zone['max_runtime']) {
					$per_run = (float) $zone['max_runtime'];
				}
				$duration = (int) round($per_run * 60);
			} else {
				$duration = $basis_m * 60;
			}
			if ($duration < 1) {
				echo 'Skip ' . $zone['name'] . ': computed runtime is 0' . PHP_EOL;
				continue;
			}

			$start_zones[] = [
				'id'        => (string) $device_zone->id,
				'duration'  => $duration,
				'sortOrder' => $sort_order++,
			];

			echo sprintf('Queue %s: %dm @ %dF → %ds', $zone['name'], $basis_m, $temperature, $duration) . PHP_EOL;
		}

		return $start_zones;
	}
}

class Weather
{
	private static $data = [];
	private static $fallback_full = false;

	private const CACHE_FILE    = '/tmp/wachio_weather.json';
	private const CACHE_MAX_AGE = 12 * 3600; // 12 hours

	public static function request($latitude, $longitude, $timezone)
	{
		$url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
			'latitude'         => $latitude,
			'longitude'        => $longitude,
			'daily'            => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max',
			'temperature_unit' => 'fahrenheit',
			'timezone'         => $timezone,
			'forecast_days'    => 7,
		]);

		try {
			$result = static::fetch($url);
			$data   = static::buildDaily($result);

			static::$data = $data;
			static::$fallback_full = false;
			static::writeCache($data);
			return;
		} catch (\RuntimeException $e) {
			$cached = static::readCache();
			if ($cached !== null) {
				static::$data = $cached['data'];
				static::$fallback_full = false;
				$age_h = (int) ceil((time() - $cached['ts']) / 3600);
				echo sprintf('[weather] using cached forecast (%dh old)', $age_h) . PHP_EOL;
				return;
			}

			static::$data = [];
			static::$fallback_full = true;
			echo '[weather] no fresh forecast available — running at full dose' . PHP_EOL;
		}
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
		$data  = [];

		for ($i = 0; $i < count($daily->time); $i++) {
			$date   = (string) $daily->time[$i];
			$high   = (int) $daily->temperature_2m_max[$i];
			$low    = (int) $daily->temperature_2m_min[$i];
			$precip = (int) ( $daily->precipitation_probability_max[$i] ?? 0 );

			$data[$date] = [
				'high' => $high,
				'low'  => $low,
				'avg'  => (int) (($high + $low) / 2),
				'rain' => $precip > 50,
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
