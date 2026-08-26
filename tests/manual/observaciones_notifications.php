<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../api/desembarques/unread_observaciones_helpers.php';

$options = getopt('', [
    'role::',
    'id::',
    'name::',
    'email::',
    'last-seen::',
    'language::'
]);

$role = isset($options['role']) ? (string) $options['role'] : 'usuario';
$id = isset($options['id']) ? (int) $options['id'] : 0;
$name = isset($options['name']) ? (string) $options['name'] : '';
$email = isset($options['email']) ? (string) $options['email'] : '';
$language = isset($options['language']) ? (string) $options['language'] : 'es';

$lastSeen = [];

if (isset($options['last-seen'])) {
    $decoded = json_decode((string) $options['last-seen'], true);

    if (is_array($decoded)) {
        $lastSeen = $decoded;
    }
}

try {
    $connection = getDatabaseConnection();
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'Database connection error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$user = [
    'id' => $id,
    'role' => $role,
    'name' => $name,
    'email' => $email,
];

try {
    $items = fetchUnreadObservacionesItems($connection, $user, $lastSeen, $language);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error fetching unread observations: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$output = [
    'user' => $user,
    'language' => $language,
    'last_seen' => $lastSeen,
    'items' => $items,
];

$jsonOptions = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$json = json_encode($output, $jsonOptions);

if ($json === false) {
    fwrite(STDERR, "Failed to encode output as JSON." . PHP_EOL);
    exit(1);
}

echo $json . PHP_EOL;
