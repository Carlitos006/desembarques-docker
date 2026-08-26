<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/files.php';

/** @return array<string,string> */
function expediente_document_type_catalog(string $language = 'es'): array
{
    if ($language === 'en') {
        return [
            'pedimento' => 'Customs entry',
            'manifiesto' => 'Manifest',
            'cipl' => 'CIPL',
            'acuse' => 'Acknowledgment',
            'oficio' => 'Official letter',
            'factura' => 'Invoice',
            'aduanal' => 'Customs document',
            'other' => 'Other',

        ];
    }

    return [
        'pedimento' => 'Pedimento',
        'manifiesto' => 'Manifiesto',
        'cipl' => 'CIPL',
        'acuse' => 'Acuse',
        'oficio' => 'Oficio',
        'factura' => 'Factura',
        'aduanal' => 'Documento aduanal',
        'rectificacion' => 'Rectificación Agregada',
        'rectificacion_desagregada' => 'Rectificación Desagregada',
        'expo' => 'Pedimento H1',
        'impo' => 'Pedimento A3',
        'aviso' => 'Acuse de Aviso',
        'other' => 'Otro',
    ];
}

function expediente_document_type_is_valid(string $type): bool
{
    return array_key_exists($type, expediente_document_type_catalog('es'));
}

function expediente_document_normalize_description(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, 500, 'UTF-8');
    }

    return substr($value, 0, 500);
}

function expediente_document_normalize_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (! $date || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
        return null;
    }

    return $date->format('Y-m-d') === $value ? $value : null;
}

/** @return array<string,mixed>|null */
function expediente_document_load_desembarque(mysqli $connection, int $desembarqueId): ?array
{
    $result = $connection->execute_query(
        'SELECT d.id, d.cliente, d.client_id, d.deleted_at, c.user_id AS client_user_id, c.name AS client_name, c.email AS client_email '
        . 'FROM desembarques d LEFT JOIN clients c ON c.id = d.client_id WHERE d.id = ? LIMIT 1',
        [$desembarqueId]
    );
    $record = $result instanceof mysqli_result ? ($result->fetch_assoc() ?: null) : null;
    if (is_array($record) && ! empty($record['deleted_at']) && ! expediente_document_deleted_admin_view_requested()) {
        return null;
    }

    return $record;
}

function expediente_document_deleted_admin_view_requested(): bool
{
    return (string) ($_SESSION['user']['role'] ?? '') === 'admin'
        && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
        && (string) ($_GET['deleted'] ?? '') === '1';
}

function expediente_document_user_can_access(array $record, string $userRole, int $userId, string $userName, string $userEmail): bool
{
    if (in_array($userRole, ['admin', 'usuario'], true)) {
        return true;
    }

    if ($userRole !== 'cliente') {
        return false;
    }

    if ((int) ($record['client_user_id'] ?? 0) === $userId && $userId > 0) {
        return true;
    }

    $normalize = static function (string $value): string {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    };

    $sessionName = $normalize($userName);
    $sessionEmail = $normalize($userEmail);
    $clientName = $normalize((string) ($record['client_name'] ?? ''));
    $clientEmail = $normalize((string) ($record['client_email'] ?? ''));
    $legacyClient = $normalize((string) ($record['cliente'] ?? ''));

    return ($sessionEmail !== '' && ($sessionEmail === $clientEmail || $sessionEmail === $legacyClient))
        || ($sessionName !== '' && ($sessionName === $clientName || $sessionName === $legacyClient));
}

function expediente_document_user_can_write(string $userRole): bool
{
    return in_array($userRole, ['admin', 'usuario'], true);
}

/** @return array<string,mixed>|null */
function expediente_document_load_file(mysqli $connection, int $fileId): ?array
{
    $result = $connection->execute_query(
        'SELECT f.*, d.cliente, d.client_id, d.deleted_at, c.user_id AS client_user_id, c.name AS client_name, c.email AS client_email '
        . 'FROM desembarque_files f INNER JOIN desembarques d ON d.id = f.desembarque_id '
        . 'LEFT JOIN clients c ON c.id = d.client_id WHERE f.id = ? LIMIT 1',
        [$fileId]
    );

    $file = $result instanceof mysqli_result ? ($result->fetch_assoc() ?: null) : null;
    if (is_array($file) && ! empty($file['deleted_at']) && ! expediente_document_deleted_admin_view_requested()) {
        return null;
    }

    return $file;
}

function expediente_document_is_supported_file(array $file): bool
{
    return in_array(strtolower(trim((string) ($file['extension'] ?? ''))), ['pdf', 'doc', 'docx'], true)
        && (string) ($file['purpose'] ?? '') !== 'source_excel';
}

function expediente_document_hash_file(string $storedName, ?array $fileConfig = null): string
{
    $fileConfig = $fileConfig ?? file_storage_config();
    $path = get_stored_file_path($storedName, $fileConfig);
    if (! is_file($path) || ! is_readable($path)) {
        throw new RuntimeException('The stored document is not available.');
    }

    $hash = hash_file('sha256', $path);
    if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
        throw new RuntimeException('Unable to calculate document integrity hash.');
    }

    return $hash;
}
