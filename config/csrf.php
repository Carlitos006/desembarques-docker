<?php

declare(strict_types=1);

if (! function_exists('generate_csrf_token_value')) {
    /**
     * Generate a cryptographically secure CSRF token value.
     */
    function generate_csrf_token_value(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (Throwable $exception) {
            return hash('sha256', uniqid('csrf_', true) . microtime(true));
        }
    }
}

if (! function_exists('csrf_token')) {
    /**
     * Retrieve the CSRF token for the current session, generating one if needed.
     */
    function csrf_token(): string
    {
        if (! isset($_SESSION['csrf_token']) || ! is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
            $_SESSION['csrf_token'] = generate_csrf_token_value();
        }

        return $_SESSION['csrf_token'];
    }
}

if (! function_exists('validate_csrf_token')) {
    /**
     * Validate the provided CSRF token against the session token.
     */
    function validate_csrf_token(?string $token): bool
    {
        if (! isset($_SESSION['csrf_token']) || ! is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
            return false;
        }

        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], (string) $token);
    }
}
