<?php

declare(strict_types=1);

session_start();

if (! isset($_SESSION['user']) || ! is_array($_SESSION['user'])) {
    header('Location: login.php?status=unauthorized');
    exit;
}

$userRole = trim((string) ($_SESSION['user']['role'] ?? ''));

if (in_array($userRole, ['admin', 'usuario'], true)) {
    header('Location: dashboard-avisos.php');
    exit;
}

if ($userRole === 'cliente') {
    header('Location: client-portal.php');
    exit;
}

// Fail closed for unknown/invalid roles.
unset($_SESSION['user']);
header('Location: login.php?status=unauthorized');
exit;
