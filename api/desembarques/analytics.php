<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/i18n.php';
require_once __DIR__ . '/../../config/analytics.php';
require_once __DIR__ . '/../../config/database.php';

$currentLanguage = getAppLanguage();

header('Content-Type: application/json; charset=utf-8');

if (! isset($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => translate('common.session_missing_user', [], $currentLanguage),
    ]);
    exit;
}

$user = $_SESSION['user'];
$userRole = (string) ($user['role'] ?? '');

if (! in_array($userRole, ['admin', 'usuario'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => translate('common.authorization_error', [], $currentLanguage),
    ]);
    exit;
}

/**
 * Sends a JSON error response and stops execution.
 *
 * @param array<string, mixed> $payload
 */
function analyticsRespondWithError(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

try {
    $config = getAnalyticsConfig();
} catch (Throwable $exception) {
    analyticsRespondWithError(500, [
        'success' => false,
        'message' => translate('analytics.errors.config', ['error' => $exception->getMessage()], $currentLanguage),
    ]);
}

$rangeOptions = $config['range_options'] ?? [60];
$defaultRange = (int) ($config['default_range_days'] ?? 60);
$rangeOptions = array_values(array_unique(array_map('intval', $rangeOptions)));

if ($defaultRange <= 0) {
    $defaultRange = 60;
}

$requestedRange = isset($_GET['range']) ? (int) $_GET['range'] : $defaultRange;

if (! in_array($requestedRange, $rangeOptions, true)) {
    $requestedRange = $defaultRange;
}

$rangeDays = max(7, min($requestedRange, 180));

try {
    $endDate = new DateTimeImmutable('today');
    $startDate = $endDate->modify('-' . ($rangeDays - 1) . ' days');
} catch (Throwable $exception) {
    analyticsRespondWithError(500, [
        'success' => false,
        'message' => translate('analytics.errors.date_range', ['error' => $exception->getMessage()], $currentLanguage),
    ]);
}

try {
    $connection = getDatabaseConnection();
} catch (Throwable $exception) {
    analyticsRespondWithError(500, [
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
}

/**
 * @return list<array{
 *     id: int,
 *     referencia: string,
 *     destino: string,
 *     dias_transcurridos: int,
 *     dias_fuera: int,
 *     fecha_desembarque: string|null,
 *     fecha_embarque: string|null,
 *     status_slug: string,
 *     status_name_es: string,
 *     status_name_en: string
 * }>
 */
function analyticsFetchRecords(mysqli $connection, DateTimeImmutable $startDate, DateTimeImmutable $endDate): array
{
    $sql = <<<SQL
        SELECT
            d.id,
            d.referencia,
            d.destino,
            d.dias_transcurridos,
            d.dias_fuera,
            d.fecha_desembarque,
            d.fecha_embarque,
            s.slug AS status_slug,
            s.name_es AS status_name_es,
            s.name_en AS status_name_en
        FROM desembarques d
        INNER JOIN desembarque_statuses s ON s.id = d.status_id
        WHERE d.deleted_at IS NULL AND d.fecha_desembarque >= ? AND d.fecha_desembarque <= ?
        ORDER BY d.fecha_desembarque ASC
    SQL;

    $statement = $connection->prepare($sql);
    $start = $startDate->format('Y-m-d');
    $end = $endDate->format('Y-m-d');
    $statement->bind_param('ss', $start, $end);
    $statement->execute();
    $result = $statement->get_result();
    $records = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $records[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : 0,
                'referencia' => (string) ($row['referencia'] ?? ''),
                'destino' => (string) ($row['destino'] ?? ''),
                'dias_transcurridos' => isset($row['dias_transcurridos']) ? (int) $row['dias_transcurridos'] : 0,
                'dias_fuera' => isset($row['dias_fuera']) ? (int) $row['dias_fuera'] : 0,
                'fecha_desembarque' => isset($row['fecha_desembarque']) ? (string) $row['fecha_desembarque'] : null,
                'fecha_embarque' => isset($row['fecha_embarque']) ? (string) $row['fecha_embarque'] : null,
                'status_slug' => (string) ($row['status_slug'] ?? ''),
                'status_name_es' => (string) ($row['status_name_es'] ?? ''),
                'status_name_en' => (string) ($row['status_name_en'] ?? ''),
            ];
        }

        $result->free();
    }

    $statement->close();

    return $records;
}

/**
 * @return array<string, int>
 */
function analyticsFetchActiveByDock(mysqli $connection): array
{
    $sql = <<<SQL
        SELECT d.destino, COUNT(*) AS total
        FROM desembarques d
        INNER JOIN desembarque_statuses s ON s.id = d.status_id
        WHERE d.deleted_at IS NULL AND s.slug NOT IN ('completed', 'cancelled')
        GROUP BY d.destino
    SQL;

    $result = $connection->query($sql);
    $counts = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $destino = (string) ($row['destino'] ?? '');
            $counts[$destino] = isset($row['total']) ? (int) $row['total'] : 0;
        }

        $result->free();
    }

    return $counts;
}

/**
 * @param array<string, array{name: string, capacity: int, aliases: array<int, string>}> $docks
 *
 * @return array{docks: array<string, array{name: string, capacity: int}>, aliases: array<string, string>}
 */
function analyticsBuildDockMaps(array $docks): array
{
    $docksBySlug = [];
    $aliases = [];

    foreach ($docks as $slug => $dock) {
        $name = (string) ($dock['name'] ?? $slug);
        $capacity = isset($dock['capacity']) ? (int) $dock['capacity'] : 0;
        $docksBySlug[$slug] = [
            'name' => $name,
            'capacity' => max(0, $capacity),
        ];

        if (isset($dock['aliases']) && is_array($dock['aliases'])) {
            foreach ($dock['aliases'] as $alias) {
                $normalized = analytics_normalize_string((string) $alias);

                if ($normalized !== '') {
                    $aliases[$normalized] = $slug;
                }
            }
        }

        $normalizedName = analytics_normalize_string($name);

        if ($normalizedName !== '') {
            $aliases[$normalizedName] = $slug;
        }
    }

    return [
        'docks' => $docksBySlug,
        'aliases' => $aliases,
    ];
}

/**
 * @param array<string, string> $aliases
 */
function analyticsResolveDockSlug(string $destino, array $aliases): string
{
    $normalized = analytics_normalize_string($destino);

    if ($normalized !== '' && isset($aliases[$normalized])) {
        return $aliases[$normalized];
    }

    return analytics_slug($destino);
}

/**
 * @param list<array{id: int, destino: string, dias_transcurridos: int, dias_fuera: int, fecha_desembarque: string|null, fecha_embarque: string|null, status_slug: string, status_name_es: string, status_name_en: string}> $records
 * @param array{target_hours: int, warning_hours: int} $slaConfig
 *
 * @return array<string, mixed>
 */
function analyticsComputeSla(array $records, array $slaConfig, string $language): array
{
    $targetHours = isset($slaConfig['target_hours']) ? (int) $slaConfig['target_hours'] : 72;
    $warningHours = isset($slaConfig['warning_hours']) ? (int) $slaConfig['warning_hours'] : $targetHours;

    if ($targetHours <= 0) {
        $targetHours = 72;
    }

    $targetDays = $targetHours / 24;
    $warningDays = $warningHours / 24;
    $total = count($records);
    $compliant = 0;
    $totalDiasTranscurridos = 0.0;
    $totalDiasFuera = 0.0;
    $breaches = [];
    $breachesByStatus = [];
    $trendBuckets = [];
    $dailyCounts = [];

    foreach ($records as $row) {
        $diasTranscurridos = (int) $row['dias_transcurridos'];
        $diasFuera = (int) $row['dias_fuera'];
        $totalDiasTranscurridos += $diasTranscurridos;
        $totalDiasFuera += $diasFuera;
        $fechaDesembarque = $row['fecha_desembarque'];
        $statusSlug = $row['status_slug'];
        $statusName = $language === 'en' ? $row['status_name_en'] : $row['status_name_es'];
        $statusName = $statusName !== '' ? $statusName : $statusSlug;
        $isCompliant = $diasTranscurridos <= $targetDays;

        if ($isCompliant) {
            $compliant++;
        } else {
            $breaches[] = [
                'id' => $row['id'],
                'referencia' => (string) ($row['referencia'] ?? ''),
                'destino' => $row['destino'],
                'dias_transcurridos' => $diasTranscurridos,
                'dias_fuera' => $diasFuera,
                'status' => [
                    'slug' => $statusSlug,
                    'label' => $statusName,
                ],
                'fecha_desembarque' => $fechaDesembarque,
                'fecha_embarque' => $row['fecha_embarque'],
                'severity' => $diasTranscurridos >= $warningDays ? 'critical' : 'warning',
            ];

            if (! isset($breachesByStatus[$statusSlug])) {
                $breachesByStatus[$statusSlug] = [
                    'label' => $statusName,
                    'count' => 0,
                ];
            }

            $breachesByStatus[$statusSlug]['count']++;
        }

        if ($fechaDesembarque) {
            $dailyCounts[$fechaDesembarque] = ($dailyCounts[$fechaDesembarque] ?? 0) + 1;

            try {
                $date = new DateTimeImmutable($fechaDesembarque);
                $weekKey = $date->format('o-W');

                if (! isset($trendBuckets[$weekKey])) {
                    $trendBuckets[$weekKey] = [
                        'compliant' => 0,
                        'total' => 0,
                        'week' => $weekKey,
                        'label' => $date->modify('monday this week')->format('d M'),
                    ];
                }

                $trendBuckets[$weekKey]['total']++;

                if ($isCompliant) {
                    $trendBuckets[$weekKey]['compliant']++;
                }
            } catch (Throwable $exception) {
                // Ignore invalid date formats in trend calculations.
            }
        }
    }

    usort($breaches, static function ($a, $b): int {
        return $b['dias_transcurridos'] <=> $a['dias_transcurridos'];
    });

    $breaches = array_slice($breaches, 0, 5);

    if ($breachesByStatus !== []) {
        uasort($breachesByStatus, static function ($a, $b): int {
            return ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
        });
    }

    $trendSeries = [];

    if ($trendBuckets !== []) {
        ksort($trendBuckets);

        foreach ($trendBuckets as $bucket) {
            $totalBucket = (int) ($bucket['total'] ?? 0);
            $compliantBucket = (int) ($bucket['compliant'] ?? 0);
            $rate = $totalBucket > 0 ? $compliantBucket / $totalBucket : 0.0;
            $trendSeries[] = [
                'week' => (string) ($bucket['week'] ?? ''),
                'label' => (string) ($bucket['label'] ?? ''),
                'compliance' => round($rate, 4),
                'total' => $totalBucket,
            ];
        }
    }

    $avgDiasTranscurridos = $total > 0 ? $totalDiasTranscurridos / $total : 0.0;
    $avgDiasFuera = $total > 0 ? $totalDiasFuera / $total : 0.0;
    $complianceRate = $total > 0 ? $compliant / $total : 0.0;

    return [
        'target_hours' => $targetHours,
        'warning_hours' => $warningHours,
        'target_days' => round($targetDays, 2),
        'compliance_rate' => round($complianceRate, 4),
        'total_records' => $total,
        'breach_count' => count($records) - $compliant,
        'average_dias_transcurridos' => round($avgDiasTranscurridos, 2),
        'average_dias_fuera' => round($avgDiasFuera, 2),
        'breaches' => $breaches,
        'breaches_by_status' => array_values($breachesByStatus),
        'trend' => $trendSeries,
        'daily_counts' => $dailyCounts,
    ];
}

/**
 * @param array<string, int> $activeByDock
 * @param array{default_capacity: int, docks: array<string, array{name: string, capacity: int, aliases: array<int, string>}>} $dockConfig
 *
 * @return array<string, mixed>
 */
function analyticsComputeDockCapacity(array $activeByDock, array $dockConfig): array
{
    $defaultCapacity = isset($dockConfig['default_capacity']) ? (int) $dockConfig['default_capacity'] : 6;
    $maps = analyticsBuildDockMaps($dockConfig['docks'] ?? []);
    $docksBySlug = $maps['docks'];
    $aliases = $maps['aliases'];
    $docks = [];
    $totalCapacity = 0;
    $totalActive = 0;

    foreach ($activeByDock as $destino => $count) {
        $slug = analyticsResolveDockSlug($destino, $aliases);
        $config = $docksBySlug[$slug] ?? null;
        $capacity = $config['capacity'] ?? $defaultCapacity;
        $name = $config['name'] ?? $destino;

        $utilization = $capacity > 0 ? $count / $capacity : 0.0;
        $status = 'normal';

        if ($utilization >= 1.0) {
            $status = 'critical';
        } elseif ($utilization >= 0.75) {
            $status = 'warning';
        }

        $docks[$slug] = [
            'slug' => $slug,
            'name' => $name,
            'capacity' => $capacity,
            'active' => $count,
            'utilization' => round($utilization, 4),
            'status' => $status,
        ];

        $totalCapacity += $capacity;
        $totalActive += $count;
    }

    foreach ($docksBySlug as $slug => $config) {
        if (isset($docks[$slug])) {
            continue;
        }

        $capacity = $config['capacity'] ?? $defaultCapacity;
        $docks[$slug] = [
            'slug' => $slug,
            'name' => $config['name'] ?? $slug,
            'capacity' => $capacity,
            'active' => 0,
            'utilization' => 0.0,
            'status' => 'normal',
        ];

        $totalCapacity += $capacity;
    }

    usort($docks, static function ($a, $b): int {
        return $b['utilization'] <=> $a['utilization'];
    });

    return [
        'docks' => $docks,
        'total_capacity' => $totalCapacity,
        'total_active' => $totalActive,
        'overall_utilization' => $totalCapacity > 0 ? round($totalActive / $totalCapacity, 4) : 0.0,
    ];
}

/**
 * @param array{forecast_days: int, locations: array<int, array{code: string, name: string, latitude?: float, longitude?: float, base_temperature?: float}>} $weatherConfig
 */
function analyticsGenerateWeather(array $weatherConfig): array
{
    $days = isset($weatherConfig['forecast_days']) ? (int) $weatherConfig['forecast_days'] : 5;
    $days = max(1, min($days, 10));
    $locations = [];
    $generatedAt = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
    $provider = strtolower((string) ($weatherConfig['provider'] ?? 'synthetic'));
    $windyApiKey = isset($weatherConfig['windy_api_key']) ? (string) $weatherConfig['windy_api_key'] : '';
    $windyApiKey = trim($windyApiKey);
    $windyModel = isset($weatherConfig['windy_model']) ? (string) $weatherConfig['windy_model'] : 'gfs';

    foreach ($weatherConfig['locations'] ?? [] as $location) {
        $code = analytics_slug((string) ($location['code'] ?? 'location'));
        $name = (string) ($location['name'] ?? $code);
        $latitude = isset($location['latitude']) ? (float) $location['latitude'] : null;
        $longitude = isset($location['longitude']) ? (float) $location['longitude'] : null;
        $baseTemperature = isset($location['base_temperature']) ? (float) $location['base_temperature'] : 23.0;

        $forecasts = [];

        if ($provider === 'windy' && $windyApiKey !== '' && $latitude !== null && $longitude !== null) {
            $forecasts = analyticsFetchForecastFromWindy(
                $latitude,
                $longitude,
                $days,
                $windyApiKey,
                [
                    'model' => $windyModel,
                ]
            );
        }

        if ($forecasts === []) {
            // If the external provider failed or is unavailable, fall back to synthetic data to keep the UI consistent.
            $forecasts = analyticsGenerateSyntheticWeather($code, $baseTemperature, $days);
        }

        $locations[] = [
            'code' => $code,
            'name' => $name,
            'forecasts' => $forecasts,
        ];
    }

    return [
        'generated_at' => $generatedAt,
        'locations' => $locations,
    ];
}

/**
 * @return list<array{date: string, condition: string, temperature_max: float, temperature_min: float, wind_speed: float, rain_probability: float}>
 */
function analyticsFetchForecastFromWindy(float $latitude, float $longitude, int $days, string $apiKey, array $options = []): array
{
    if ($apiKey === '') {
        return [];
    }

    $model = isset($options['model']) ? (string) $options['model'] : 'gfs';
    $parameters = $options['parameters'] ?? ['temp', 'wind', 'wind_u', 'wind_v', 'rh', 'prate'];

    if (! is_array($parameters) || $parameters === []) {
        $parameters = ['temp', 'wind', 'wind_u', 'wind_v', 'rh', 'prate'];
    }

    $payload = [
        'lat' => $latitude,
        'lon' => $longitude,
        'model' => $model,
        'parameters' => array_values(array_unique(array_map('strval', $parameters))),
        'levels' => ['surface'],
        'key' => $apiKey,
    ];

    $body = json_encode($payload);

    if (! is_string($body)) {
        return [];
    }

    $handle = curl_init('https://api.windy.com/api/point-forecast/v2');

    if ($handle === false) {
        return [];
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_FAILONERROR => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: DesembarquesAnalytics/1.0',
        ],
    ]);

    $response = curl_exec($handle);

    if ($response === false) {
        curl_close($handle);

        return [];
    }

    $statusCode = curl_getinfo($handle, CURLINFO_HTTP_CODE) ?: 0;
    curl_close($handle);

    if ($statusCode < 200 || $statusCode >= 300) {
        return [];
    }

    /** @var array<string, mixed> $result */
    $result = json_decode($response, true);

    if (! is_array($result)) {
        return [];
    }

    /** @var list<int|float>|null $timestamps */
    $timestamps = isset($result['ts']) && is_array($result['ts']) ? $result['ts'] : [];

    if ($timestamps === []) {
        return [];
    }

    /** @var array<string, string>|null $units */
    $units = isset($result['units']) && is_array($result['units']) ? $result['units'] : [];
    $temperatureUnit = isset($units['temp']) ? (string) $units['temp'] : 'C';
    $windUnit = isset($units['wind']) ? (string) $units['wind'] : 'm/s';
    $precipitationUnit = isset($units['prate']) ? (string) $units['prate'] : 'mm/h';
    $humidityUnit = isset($units['rh']) ? (string) $units['rh'] : '%';

    $temperatureSeries = analyticsWindySeries($result, 'temp', 'surface');
    $windSeries = analyticsWindySeries($result, 'wind', 'surface');
    $windUSeries = analyticsWindySeries($result, 'wind_u', 'surface');
    $windVSeries = analyticsWindySeries($result, 'wind_v', 'surface');
    $humiditySeries = analyticsWindySeries($result, 'rh', 'surface');
    $precipitationSeries = analyticsWindySeries($result, 'prate', 'surface');

    $daily = [];
    $total = count($timestamps);

    for ($index = 0; $index < $total; $index++) {
        $timestamp = isset($timestamps[$index]) ? (float) $timestamps[$index] : null;

        if ($timestamp === null) {
            continue;
        }

        try {
            $date = (new DateTimeImmutable('@' . (int) round($timestamp)))->setTimezone(new DateTimeZone('UTC-6'))->format('Y-m-d');
        } catch (Throwable $exception) {
            $date = gmdate('Y-m-d', (int) round($timestamp));
        }

        if (! isset($daily[$date])) {
            $daily[$date] = [
                'temp_max' => null,
                'temp_min' => null,
                'wind_max' => 0.0,
                'humidity_max' => 0.0,
                'precipitation_max' => 0.0,
            ];
        }

        $temperature = analyticsWindyValueAt($temperatureSeries, $index);

        if ($temperature !== null) {
            $temperature = analyticsConvertTemperature($temperature, $temperatureUnit);

            if ($daily[$date]['temp_max'] === null || $temperature > $daily[$date]['temp_max']) {
                $daily[$date]['temp_max'] = $temperature;
            }

            if ($daily[$date]['temp_min'] === null || $temperature < $daily[$date]['temp_min']) {
                $daily[$date]['temp_min'] = $temperature;
            }
        }

        $windSpeed = analyticsWindyValueAt($windSeries, $index);

        if ($windSpeed === null && $windUSeries !== [] && $windVSeries !== []) {
            $windU = analyticsWindyValueAt($windUSeries, $index);
            $windV = analyticsWindyValueAt($windVSeries, $index);

            if ($windU !== null && $windV !== null) {
                $windSpeed = sqrt(($windU ** 2) + ($windV ** 2));
            }
        }

        if ($windSpeed !== null) {
            $windSpeed = analyticsConvertWindSpeed($windSpeed, $windUnit);
            $daily[$date]['wind_max'] = max($daily[$date]['wind_max'], $windSpeed);
        }

        $humidity = analyticsWindyValueAt($humiditySeries, $index);

        if ($humidity !== null) {
            $humidity = analyticsConvertHumidity($humidity, $humidityUnit);
            $daily[$date]['humidity_max'] = max($daily[$date]['humidity_max'], $humidity);
        }

        $precipitation = analyticsWindyValueAt($precipitationSeries, $index);

        if ($precipitation !== null) {
            $precipitation = analyticsConvertPrecipitation($precipitation, $precipitationUnit);
            $daily[$date]['precipitation_max'] = max($daily[$date]['precipitation_max'], $precipitation);
        }
    }

    if ($daily === []) {
        return [];
    }

    ksort($daily);

    $forecasts = [];

    foreach ($daily as $date => $values) {
        if ($values['temp_max'] === null || $values['temp_min'] === null) {
            continue;
        }

        $temperatureMax = (float) $values['temp_max'];
        $temperatureMin = (float) $values['temp_min'];
        $windSpeed = (float) $values['wind_max'];
        $humidity = max(0.0, min(1.0, (float) $values['humidity_max']));
        $precipitation = max(0.0, (float) $values['precipitation_max']);
        $rainFromPrecip = min(1.0, $precipitation / 5.0);
        $rainFromHumidity = min(1.0, $humidity * 0.85);
        $rainProbability = max($rainFromPrecip, $rainFromHumidity);

        $forecasts[] = [
            'date' => $date,
            'condition' => analyticsWeatherConditionFromMetrics(
                $temperatureMin,
                $temperatureMax,
                $windSpeed,
                $rainProbability,
                $precipitation,
                $humidity
            ),
            'temperature_max' => round($temperatureMax, 1),
            'temperature_min' => round($temperatureMin, 1),
            'wind_speed' => round($windSpeed, 1),
            'rain_probability' => round($rainProbability, 2),
        ];

        if (count($forecasts) >= $days) {
            break;
        }
    }

    return $forecasts;
}

/**
 * @param array<string, mixed> $payload
 *
 * @return list<float|int>
 */
function analyticsWindySeries(array $payload, string $parameter, string $level): array
{
    $candidates = [
        $parameter . '-' . $level,
        $parameter . '_' . $level,
        $parameter . $level,
        $parameter,
    ];

    foreach ($candidates as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            /** @var list<float|int> $series */
            $series = array_values($payload[$key]);

            return $series;
        }
    }

    return [];
}

/**
 * @param list<float|int> $series
 */
function analyticsWindyValueAt(array $series, int $index): ?float
{
    if (! array_key_exists($index, $series)) {
        return null;
    }

    $value = $series[$index];

    if (! is_int($value) && ! is_float($value)) {
        return null;
    }

    return (float) $value;
}

function analyticsConvertTemperature(float $value, string $unit): float
{
    $unit = strtolower(trim($unit));

    if ($unit === 'k' || $unit === 'kelvin') {
        return $value - 273.15;
    }

    if ($unit === 'f' || $unit === 'fahrenheit') {
        return ($value - 32.0) * (5 / 9);
    }

    return $value;
}

function analyticsConvertWindSpeed(float $value, string $unit): float
{
    $unit = strtolower(trim($unit));

    if ($unit === 'm/s' || $unit === 'ms' || $unit === 'meters per second') {
        return $value * 3.6; // km/h
    }

    if ($unit === 'kt' || $unit === 'kts' || $unit === 'knots') {
        return $value * 1.852;
    }

    if ($unit === 'mph') {
        return $value * 1.60934;
    }

    return $value;
}

function analyticsConvertHumidity(float $value, string $unit): float
{
    $unit = strtolower(trim($unit));

    if ($unit === '%' || $unit === 'percent' || $unit === 'percentage') {
        return max(0.0, min(1.0, $value / 100));
    }

    return max(0.0, min(1.0, $value));
}

function analyticsConvertPrecipitation(float $value, string $unit): float
{
    $unit = strtolower(trim($unit));

    if ($unit === 'kg/(m^2*s)' || $unit === 'kg/m2/s') {
        // Kilogram per square meter per second approximates mm/s. Convert to mm/h.
        return $value * 3600;
    }

    if ($unit === 'mm/h' || $unit === 'mmhr' || $unit === 'mm per hour') {
        return $value;
    }

    if ($unit === 'in/h' || $unit === 'inches/hour') {
        return $value * 25.4;
    }

    return $value;
}

function analyticsWeatherConditionFromMetrics(
    float $temperatureMin,
    float $temperatureMax,
    float $windSpeed,
    float $rainProbability,
    float $precipitationIntensity,
    float $humidity
): string {
    $windSpeed = max(0.0, $windSpeed);
    $rainProbability = max(0.0, min(1.0, $rainProbability));
    $precipitationIntensity = max(0.0, $precipitationIntensity);
    $humidity = max(0.0, min(1.0, $humidity));

    if ($temperatureMax <= 2.0 && ($precipitationIntensity > 0.5 || $rainProbability > 0.5)) {
        return 'snow';
    }

    if ($humidity > 0.9 && $rainProbability < 0.35 && $windSpeed < 15.0) {
        return 'fog';
    }

    if ($rainProbability >= 0.85 || $precipitationIntensity >= 10.0) {
        return 'storm';
    }

    if ($rainProbability >= 0.6 || $precipitationIntensity >= 2.5) {
        return 'rain';
    }

    if ($windSpeed >= 65.0) {
        return 'storm';
    }

    if ($windSpeed >= 45.0) {
        return 'windy';
    }

    if ($rainProbability >= 0.4 || $precipitationIntensity >= 1.0) {
        return 'cloudy';
    }

    if ($rainProbability >= 0.25 || $humidity >= 0.75) {
        return 'cloudy';
    }

    return 'sunny';
}

/**
 * @return list<array{date: string, condition: string, temperature_max: float, temperature_min: float, wind_speed: float, rain_probability: float}>
 */
function analyticsGenerateSyntheticWeather(string $code, float $baseTemperature, int $days): array
{
    $phase = crc32($code);
    $forecasts = [];

    for ($index = 0; $index < $days; $index++) {
        $angle = deg2rad(($phase % 360) + ($index * 18));
        $temperatureMax = $baseTemperature + sin($angle) * (3.5 + ($phase % 5) * 0.3);
        $temperatureMin = $temperatureMax - (3 + (($phase + $index) % 3));
        $windSpeed = abs(12 + cos($angle + 0.5) * 4.5);
        $rainProbability = (sin($angle + 1.2) + 1) / 2; // 0 to 1
        $condition = 'sunny';

        if ($rainProbability > 0.75) {
            $condition = 'storm';
        } elseif ($rainProbability > 0.55) {
            $condition = 'rain';
        } elseif ($windSpeed > 14.5) {
            $condition = 'windy';
        } elseif ($rainProbability > 0.35) {
            $condition = 'cloudy';
        }

        try {
            $date = (new DateTimeImmutable('today'))->modify('+' . $index . ' days')->format('Y-m-d');
        } catch (Throwable $exception) {
            $date = (new DateTime())->modify('+' . $index . ' days')->format('Y-m-d');
        }

        $forecasts[] = [
            'date' => $date,
            'condition' => $condition,
            'temperature_max' => round($temperatureMax, 1),
            'temperature_min' => round($temperatureMin, 1),
            'wind_speed' => round($windSpeed, 1),
            'rain_probability' => round(max(0.0, min(1.0, $rainProbability)), 2),
        ];
    }

    return $forecasts;
}

/**
 * @param list<float|int> $values
 */
function analyticsLinearRegression(array $values): array
{
    $n = count($values);

    if ($n < 2) {
        $average = $n === 0 ? 0.0 : (float) $values[0];

        return [
            'slope' => 0.0,
            'intercept' => $average,
        ];
    }

    $xSum = 0.0;
    $ySum = 0.0;
    $xySum = 0.0;
    $xSquaredSum = 0.0;

    for ($i = 0; $i < $n; $i++) {
        $x = (float) $i;
        $y = (float) $values[$i];
        $xSum += $x;
        $ySum += $y;
        $xySum += $x * $y;
        $xSquaredSum += $x * $x;
    }

    $denominator = ($n * $xSquaredSum) - ($xSum * $xSum);

    if (abs($denominator) < 1e-9) {
        $average = $n > 0 ? $ySum / $n : 0.0;

        return [
            'slope' => 0.0,
            'intercept' => $average,
        ];
    }

    $slope = (($n * $xySum) - ($xSum * $ySum)) / $denominator;
    $intercept = ($ySum - ($slope * $xSum)) / $n;

    return [
        'slope' => $slope,
        'intercept' => $intercept,
    ];
}

/**
 * @param array<string, int> $dailyCounts
 */
function analyticsComputeProjections(array $dailyCounts, int $projectionDays = 7): array
{
    if ($dailyCounts === []) {
        return [
            'expected_total' => 0,
            'trend' => 'stable',
            'projection' => [],
        ];
    }

    ksort($dailyCounts);
    $dates = array_keys($dailyCounts);
    $values = array_values($dailyCounts);
    $firstDate = reset($dates);
    $lastDate = end($dates);

    try {
        $start = new DateTimeImmutable((string) $firstDate);
        $end = new DateTimeImmutable((string) $lastDate);
    } catch (Throwable $exception) {
        $start = new DateTimeImmutable('today');
        $end = $start;
    }

    $fullRange = [];

    for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
        $key = $date->format('Y-m-d');
        $fullRange[] = $dailyCounts[$key] ?? 0;
    }

    $values = $fullRange;
    $regression = analyticsLinearRegression($values);
    $slope = (float) $regression['slope'];
    $intercept = (float) $regression['intercept'];
    $projection = [];
    $expectedTotal = 0.0;

    for ($index = 0; $index < $projectionDays; $index++) {
        $x = count($values) + $index;
        $predicted = max(0.0, $slope * $x + $intercept);
        $expectedTotal += $predicted;

        $date = $end->modify('+' . ($index + 1) . ' days')->format('Y-m-d');

        $projection[] = [
            'date' => $date,
            'expected' => round($predicted, 2),
        ];
    }

    $trend = 'stable';

    if ($slope > 0.2) {
        $trend = 'up';
    } elseif ($slope < -0.2) {
        $trend = 'down';
    }

    return [
        'expected_total' => (int) round($expectedTotal),
        'trend' => $trend,
        'projection' => $projection,
    ];
}

try {
    $records = analyticsFetchRecords($connection, $startDate, $endDate);
    $activeByDock = analyticsFetchActiveByDock($connection);
} catch (Throwable $exception) {
    analyticsRespondWithError(500, [
        'success' => false,
        'message' => translate('analytics.errors.query', ['error' => $exception->getMessage()], $currentLanguage),
    ]);
}

$slaMetrics = analyticsComputeSla($records, $config['sla'] ?? [], $currentLanguage);
$dockCapacity = analyticsComputeDockCapacity($activeByDock, $config['dock_capacity'] ?? []);
$weather = analyticsGenerateWeather($config['weather'] ?? []);
$projections = analyticsComputeProjections($slaMetrics['daily_counts'] ?? []);

$widgetDefinitions = [];

foreach ($config['widgets'] ?? [] as $widget) {
    $widgetId = (string) ($widget['id'] ?? '');

    if ($widgetId === '') {
        continue;
    }

    $widgetDefinitions[] = [
        'id' => $widgetId,
        'label' => translate('analytics.widgets.' . $widgetId . '.title', [], $currentLanguage),
        'description' => translate('analytics.widgets.' . $widgetId . '.description', [], $currentLanguage),
        'default_enabled' => (bool) ($widget['default_enabled'] ?? false),
    ];
}

$response = [
    'success' => true,
    'range' => [
        'requested_days' => $rangeDays,
        'start_date' => $startDate->format('Y-m-d'),
        'end_date' => $endDate->format('Y-m-d'),
    ],
    'widgets' => $widgetDefinitions,
    'analytics' => [
        'sla' => array_diff_key($slaMetrics, ['daily_counts' => true]),
        'dock_capacity' => $dockCapacity,
        'weather' => $weather,
        'projections' => $projections,
    ],
];

echo json_encode($response);
