<?php

declare(strict_types=1);

session_start();

$targetPath = 'public/login.php';

if (isset($_SESSION['user'])) {
    $targetPath = 'public/dashboard-avisos.php';
}

header('Location: ' . $targetPath);
exit;
