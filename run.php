#!/usr/bin/php
<?php

namespace W;

/**
 * Wachio - scheduled watering for Rachio irrigation controllers.
 *
 * A per-zone schedule drives when zones water; runtimes are computed from the
 * forecast temperature; watering is skipped on rain, cold, or an active rain
 * delay on the controller.
 *
 * LICENSE: The MIT License (MIT)
 *
 * Copyright (c) 2015 Waheed Ayubi
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

class Model
{
	private static $debug = false;
	private static $rain_check_days = 2; // max 10
	private static $temperature_floor = 55; // F, no watering at or below this
	private static $temperature_full  = 90; // F, 100% of runtime_basis at this

	private static $calendar = [
		1  => ['name' => 'January',   'temperature_basis' => 'Low',  'multiplier' => 1.0],
		2  => ['name' => 'February',  'temperature_basis' => 'Low',  'multiplier' => 1.0],
		3  => ['name' => 'March',     'temperature_basis' => 'Avg',  'multiplier' => 1.0],
		4  => ['name' => 'April',     'temperature_basis' => 'Avg',  'multiplier' => 1.0],
		5  => ['name' => 'May',       'temperature_basis' => 'Avg',  'multiplier' => 1.0],
		6  => ['name' => 'June',      'temperature_basis' => 'High', 'multiplier' => 1.0],
		7  => ['name' => 'July',      'temperature_basis' => 'High', 'multiplier' => 1.0],
		8  => ['name' => 'August',    'temperature_basis' => 'High', 'multiplier' => 1.0],
		9  => ['name' => 'September', 'temperature_basis' => 'Avg',  'multiplier' => 1.0],
		10 => ['name' => 'October',   'temperature_basis' => 'Avg',  'multiplier' => 1.0],
		11 => ['name' => 'November',  'temperature_basis' => 'Avg',  'multiplier' => 1.0],
		12 => ['name' => 'December',  'temperature_basis' => 'Low',  'multiplier' => 1.0],
	];

	public static function getRainCheckDays()
	{
		return (int) static::$rain_check_days;
	}

	public static function getTemperatureBasis($timezone)
	{
		date_default_timezone_set($timezone);
		return static::$calendar[date('n')]['temperature_basis'];
	}

	public static function getDailyDose($basis, $temperature)
	{
		if (static::$debug) return 1;

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
	private static $rain_delay_days = 7; // max 7
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
		$now_date = date('Y-m-d', $now);
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

			$schedule = Curl::request('https://api.rach.io/1/public/device/' . $device_id . '/current_schedule', $headers);
			if (static::isWatering($schedule)) {
				echo '=== Stopping: controller is already watering ===' . PHP_EOL;
				exit(0);
			}

			Weather::request($latitude, $longitude, static::$timezone);

			if (Weather::rainForecast()) {
				echo '=== Stopping: Rain forecast within ' . Model::getRainCheckDays() . ' days ===' . PHP_EOL;
				exit(0);
			}

			$temperature_basis = Model::getTemperatureBasis(static::$timezone);
			$temperature       = (int) Weather::getTemperature($temperature_basis);

			$start_zones = static::buildZones($due, $device, $temperature, $dow, $options['zone'] !== null);

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

	private static function runsToday($zone, $dow)
	{
		$runs = 0;
		foreach (static::schedulesForZone($zone) as $schedule) {
			if ($schedule['days'] !== null && !in_array($dow, $schedule['days'], true)) continue;
			$runs += count($schedule['times']);
		}
		return $runs;
	}

	private static function isWatering($schedule)
	{
		if (!$schedule instanceof \stdClass) return false;
		if (!isset($schedule->zones)) return false;
		return count($schedule->zones) > 0;
	}

	private static function buildZones($due, $device, $temperature, $dow, $is_override)
	{
		$start_zones = [];
		$sort_order  = 1;

		foreach ($due as $number) {
			$zone = static::$zones[$number];

			if (empty($zone['enabled'])) {
				echo 'Skip ' . $zone['name'] . ': not enabled in schedule' . PHP_EOL;
				continue;
			}
			if (!isset($zone['fixed_runtime']) && (int) $zone['runtime_basis'] <= 0) {
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

			if (isset($zone['fixed_runtime'])) {
				$duration = (int) $zone['fixed_runtime'] * 60;
				$basis    = (int) $zone['fixed_runtime'] . 'm';
			} else {
				$daily_dose = (float) Model::getDailyDose($zone['runtime_basis'], $temperature);
				$runs_today = $is_override ? 1 : static::runsToday($zone, $dow);
				$per_run    = $daily_dose / max(1, $runs_today);
				if (isset($zone['max_runtime']) && $per_run > (int) $zone['max_runtime']) {
					$per_run = (float) $zone['max_runtime'];
				}

				$basis    = (int) $zone['runtime_basis'] . 'm';
				$duration = (int) round($per_run * 60);
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

			echo sprintf('Queue %s: %s @ %dF → %ds', $zone['name'], $basis, $temperature, $duration) . PHP_EOL;
		}

		return $start_zones;
	}
}

class Weather
{
	private static $data = [];

	public static function request($latitude, $longitude, $timezone)
	{
		date_default_timezone_set($timezone);

		$url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
			'latitude'         => $latitude,
			'longitude'        => $longitude,
			'daily'            => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max',
			'temperature_unit' => 'fahrenheit',
			'timezone'         => $timezone,
			'forecast_days'    => 7,
		]);

		$result = Curl::request($url);
		if (!isset($result->daily) || !isset($result->daily->time)) {
			throw new \RuntimeException('Unexpected weather response');
		}

		$daily = $result->daily;
		$data  = [];

		for ($i = 0; $i < count($daily->time); $i++) {
			$date  = (string) $daily->time[$i];
			$high  = (int) $daily->temperature_2m_max[$i];
			$low   = (int) $daily->temperature_2m_min[$i];
			$precip = (int) ( $daily->precipitation_probability_max[$i] ?? 0 );

			$data[$date] = [
				'high' => $high,
				'low'  => $low,
				'avg'  => (int) (($high + $low) / 2),
				'rain' => $precip > 50,
			];
		}

		static::$data = $data;
	}

	public static function rainForecast()
	{
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
		$key = date('Y-m-d');
		if (!isset(static::$data[$key]) || !isset(static::$data[$key][strtolower($basis)])) {
			throw new \RuntimeException('No temperature data for ' . $key);
		}

		return (int) static::$data[$key][strtolower($basis)];
	}
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
			throw new \RuntimeException('HTTP ' . $status . ' for ' . $url . ': ' . $result);
		}

		return json_decode($result);
	}
}

\W\Rachio::run();
