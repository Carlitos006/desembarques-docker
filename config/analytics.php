<?php

declare(strict_types=1);

/**
 * Returns the advanced analytics configuration.
 *
 * @return array{
 *     sla: array{target_hours: int, warning_hours: int},
 *     range_options: array<int, int>,
 *     default_range_days: int,
 *     dock_capacity: array{
 *         default_capacity: int,
 *         docks: array<string, array{name: string, capacity: int, aliases: array<int, string>}>
 *     },
 *     weather: array{
 *         forecast_days: int,
 *         provider?: string,
 *         windy_api_key?: string|null,
 *         windy_model?: string,
 *         locations: array<int, array{code: string, name: string, base_temperature: float}>
 *     },
 *     widgets: array<int, array{id: string, default_enabled: bool}>
 * }
 */
function getAnalyticsConfig(): array
{
    $windyApiKey = getenv('WINDY_API_KEY');

    return [
        'sla' => [
            // Target SLA expressed in hours (e.g. 72h = 3 days)
            'target_hours' => 72,
            'warning_hours' => 96,
        ],
        'range_options' => [30, 60, 90],
        'default_range_days' => 60,
        'dock_capacity' => [
            'default_capacity' => 6,
            'docks' => [
                'ensenada_norte' => [
                    'name' => 'Muelle Norte',
                    'capacity' => 6,
                    'aliases' => ['Muelle Norte', 'Norte', 'North Pier'],
                ],
                'ensenada_sur' => [
                    'name' => 'Muelle Sur',
                    'capacity' => 5,
                    'aliases' => ['Muelle Sur', 'Sur', 'South Pier'],
                ],
                'ensenada_principal' => [
                    'name' => 'Muelle Principal',
                    'capacity' => 8,
                    'aliases' => ['Muelle Principal', 'Principal', 'Central Pier'],
                ],
                'ensenada_oriente' => [
                    'name' => 'Muelle Oriente',
                    'capacity' => 4,
                    'aliases' => ['Muelle Oriente', 'Oriente', 'East Pier'],
                ],
            ],
        ],
        'weather' => [
            'forecast_days' => 5,
            'provider' => 'windy',
            'windy_api_key' => $windyApiKey !== false ? (string) $windyApiKey : null,
            'windy_model' => 'gfs',
            'locations' => [
                [
                    'code' => 'tampico_tamaulipas',
                    'name' => 'Tampico, Tamaulipas',
                    'latitude' => 22.2553,
                    'longitude' => -97.8686,
                    'base_temperature' => 28.0,
                ],
                [
                    'code' => 'altamira_tamaulipas',
                    'name' => 'Altamira, Tamaulipas',
                    'latitude' => 22.3925,
                    'longitude' => -97.9259,
                    'base_temperature' => 27.5,
                ],
                [
                    'code' => 'matamoros_tamaulipas',
                    'name' => 'Matamoros, Tamaulipas',
                    'latitude' => 25.8690,
                    'longitude' => -97.5027,
                    'base_temperature' => 26.0,
                ],
                [
                    'code' => 'brownsville_texas',
                    'name' => 'Brownsville, Texas',
                    'latitude' => 25.9017,
                    'longitude' => -97.4975,
                    'base_temperature' => 25.5,
                ],
            ],
        ],
        'widgets' => [
            [
                'id' => 'sla-overview',
                'default_enabled' => true,
            ],
            [
                'id' => 'dock-capacity',
                'default_enabled' => true,
            ],
            [
                'id' => 'weather-forecast',
                'default_enabled' => true,
            ],
        ],
    ];
}

/**
 * Normalizes a string value for comparisons.
 */
function analytics_normalize_string(string $value): string
{
    $normalized = trim($value);

    if ($normalized === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        $normalized = mb_strtolower($normalized, 'UTF-8');
    } else {
        $normalized = strtolower($normalized);
    }

    return preg_replace('/\s+/', ' ', $normalized);
}

/**
 * Generates a slug-like identifier from the given value.
 */
function analytics_slug(string $value): string
{
    $normalized = analytics_normalize_string($value);
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $normalized);

    return trim((string) $slug, '-') ?: 'unknown';
}