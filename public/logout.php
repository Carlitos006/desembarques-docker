<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/i18n.php';

$language = null;
if (isset($_SESSION['language'])) {
    $language = normalizeLanguage((string) $_SESSION['language']);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], (bool) $params['httponly']);
}

session_destroy();

$query = ['status' => 'logged_out'];

if ($language !== null) {
    $query['lang'] = $language;
}

header('Location: login.php?' . http_build_query($query));
exit;
