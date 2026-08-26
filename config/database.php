<?php

declare(strict_types=1);


/**
 * Obtiene una conexión reutilizable a la base de datos.
 */
function getDatabaseConnection(): mysqli
{
    static $connection = null;

    if ($connection instanceof mysqli) {
        return $connection;
    }

    $host = getenv('DB_HOST') ?: 'localhost';
    $username = getenv('DB_USERNAME') ?: 'u101247446_adm_cnavarrete';
    $password = getenv('DB_PASSWORD') ?: 'Nav@rrete007*';
    $database = getenv('DB_DATABASE') ?: 'u101247446_desembarques';
    $port = getenv('DB_PORT') ? (int) getenv('DB_PORT') : 3306;

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $connection = new mysqli($host, $username, $password, $database, $port);
        $connection->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $exception) {
        $message = 'Error al conectar con la base de datos: ' . $exception->getMessage();

        if (function_exists('translate')) {
            $message = translate('database.connection_error', ['error' => $exception->getMessage()]);
        }

        throw new RuntimeException($message, 0, $exception);
    }

    return $connection;
}
