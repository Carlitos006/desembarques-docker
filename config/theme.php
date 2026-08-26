<?php

declare(strict_types=1);

function normalizeTheme(?string $theme): string
{
    $normalized = strtolower((string) $theme);

    return in_array($normalized, ['light', 'dark'], true) ? $normalized : 'light';
}

/**
 * @param array<string, mixed>|null $user
 *
 * @return array{theme: string, cookie_key: string|null}
 */
function getUserThemePreference(?array $user): array
{
    $userId = isset($user['id']) ? (int) $user['id'] : 0;
    $cookieKey = $userId > 0 ? 'theme_preference_' . $userId : null;
    $theme = 'light';

    if ($cookieKey !== null && isset($_COOKIE[$cookieKey])) {
        $theme = normalizeTheme($_COOKIE[$cookieKey]);
    }

    return [
        'theme' => $theme,
        'cookie_key' => $cookieKey,
    ];
}
