<?php

declare(strict_types=1);

function normalize_directory_path(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    $hasUncPrefix = DIRECTORY_SEPARATOR === '\\' && strncmp($path, '\\', 2) === 0;

    if ($hasUncPrefix) {
        $path = substr($path, 2);
    }

    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

    $doubleSeparator = DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR;
    while (strpos($path, $doubleSeparator) !== false) {
        $path = str_replace($doubleSeparator, DIRECTORY_SEPARATOR, $path);
    }

    if ($hasUncPrefix) {
        $path = '\\' . '\\' . ltrim($path, DIRECTORY_SEPARATOR);
    }

    $trimmed = rtrim($path, DIRECTORY_SEPARATOR);

    if ($trimmed === '' && $path !== '') {
        return DIRECTORY_SEPARATOR;
    }

    if (DIRECTORY_SEPARATOR === '\\' && strlen($trimmed) === 2 && ctype_alpha($trimmed[0]) && $trimmed[1] === ':') {
        return $trimmed . DIRECTORY_SEPARATOR;
    }

    return $trimmed;
}
/**
 * Retrieve the file storage configuration.
 *
 * @return array{storage_dir: string, default_storage_dir: string, max_size: int, max_files_per_request: int, allowed_extensions: string[], allowed_mime_types: array<string, string[]>}
 */
function file_storage_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $defaultDirectory = normalize_directory_path(
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads'
    );

    $storageDirectory = normalize_directory_path((string) getenv('FILE_STORAGE_PATH'));

    if ($storageDirectory === '') {
        $storageDirectory = $defaultDirectory;
    }

    $config = [
        'storage_dir' => $storageDirectory,
        'default_storage_dir' => $defaultDirectory,
        'max_size' => 200 * 1024 * 1024, // 200 MB
        'max_files_per_request' => 10,
        'allowed_extensions' => [
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'csv',
            'txt',
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
        ],
        'allowed_mime_types' => [
            'pdf' => ['application/pdf'],
            'doc' => [
                'application/msword',
                'application/vnd.ms-word',
                'application/vnd.ms-office',
            ],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => [
                'application/vnd.ms-excel',
                'application/excel',
                'application/x-excel',
            ],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'csv' => ['text/csv', 'application/csv', 'application/vnd.ms-excel', 'text/plain'],
            'txt' => ['text/plain'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
        ],
    ];

    return $config;
}

function ensure_file_storage_directory(?array $config = null): string
{
    $config = $config ?? file_storage_config();
    $configuredDirectory = normalize_directory_path((string) ($config['storage_dir'] ?? ''));
    $defaultDirectory = normalize_directory_path((string) ($config['default_storage_dir'] ?? ''));

    $directoriesToTry = array_values(array_unique(array_filter([
        $configuredDirectory,
        $defaultDirectory !== $configuredDirectory ? $defaultDirectory : '',
    ], static fn ($path) => $path !== '')));

    if ($directoriesToTry === []) {
        throw new RuntimeException('Storage directory is not configured.');
    }

    $errors = [];

    foreach ($directoriesToTry as $directory) {
        if (! is_dir($directory)) {
            if (! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                $errors[] = sprintf('Unable to create storage directory: %s', $directory);
                continue;
            }
        }

        if (! is_writable($directory)) {
            $errors[] = sprintf('Storage directory is not writable: %s', $directory);
            continue;
        }

        return $directory;
    }

    throw new RuntimeException(implode(' ', $errors));
}

/**
 * Normalize the $_FILES array for easier iteration.
 *
 * @param array<string, mixed> $files
 * @return array<int, array{name: string, type: string, tmp_name: string, error: int, size: int}>
 */
function normalize_uploaded_files_array(array $files): array
{
    $normalized = [];

    if (! isset($files['name'])) {
        return $normalized;
    }

    if (is_array($files['name'])) {
        $count = count($files['name']);

        for ($index = 0; $index < $count; $index++) {
            $normalized[] = [
                'name' => (string) ($files['name'][$index] ?? ''),
                'type' => (string) ($files['type'][$index] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$index] ?? ''),
                'error' => (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($files['size'][$index] ?? 0),
            ];
        }

        return $normalized;
    }

    $normalized[] = [
        'name' => (string) ($files['name'] ?? ''),
        'type' => (string) ($files['type'] ?? ''),
        'tmp_name' => (string) ($files['tmp_name'] ?? ''),
        'error' => (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int) ($files['size'] ?? 0),
    ];

    return $normalized;
}

/**
 * Generate a safe filename from the original value.
 */
function sanitize_uploaded_filename(string $originalName, string $extension): string
{
    $nameWithoutExtension = (string) pathinfo($originalName, PATHINFO_FILENAME);

    $filtered = filter_var(
        $nameWithoutExtension,
        FILTER_UNSAFE_RAW,
        ['flags' => FILTER_FLAG_STRIP_LOW]
    );

    if (! is_string($filtered)) {
        $filtered = '';
    }

    $filtered = str_replace(['\\', '/'], '-', $filtered);

    $allowedPunctuation = ['-', '_', '.', ' '];
    $sanitized = '';
    $length = mb_strlen($filtered, 'UTF-8');

    for ($index = 0; $index < $length; $index++) {
        $character = mb_substr($filtered, $index, 1, 'UTF-8');

        if ($character === '') {
            continue;
        }

        if (strlen($character) === 1) {
            if (ctype_alnum($character) || in_array($character, $allowedPunctuation, true)) {
                $sanitized .= $character;
                continue;
            }
        }

        $sanitized .= '_';
    }

    $sanitized = trim($sanitized, " .-_");

    if ($sanitized === '') {
        $sanitized = 'archivo';
    }

    $maxBaseLength = 200;
    if (mb_strlen($sanitized, 'UTF-8') > $maxBaseLength) {
        $sanitized = mb_substr($sanitized, 0, $maxBaseLength, 'UTF-8');
    }

    return $sanitized . '.' . $extension;
}

function detect_uploaded_mime_type(string $temporaryPath): string
{
    $mimeType = '';

    if (extension_loaded('fileinfo')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo !== false) {
            $detected = finfo_file($finfo, $temporaryPath);
            if (is_string($detected)) {
                $mimeType = $detected;
            }
            finfo_close($finfo);
        }
    }

    if ($mimeType === '' && function_exists('mime_content_type')) {
        $detected = mime_content_type($temporaryPath);
        if (is_string($detected)) {
            $mimeType = $detected;
        }
    }

    if ($mimeType === '' || $mimeType === 'application/octet-stream') {
        $handle = @fopen($temporaryPath, 'rb');
        if (is_resource($handle)) {
            $signature = fread($handle, 5);
            @fclose($handle);

            if (is_string($signature) && substr($signature, 0, 5) === '%PDF-') {
                $mimeType = 'application/pdf';
            }
        }
    }

    if ($mimeType === '') {
        $mimeType = 'application/octet-stream';
    }

    return $mimeType;
}

/**
 * Determine a valid extension based on the configuration.
 */
function determine_uploaded_extension(string $extension, string $mimeType, array $config): string
{
    $extension = strtolower(trim($extension));
    $allowedExtensions = $config['allowed_extensions'] ?? [];
    $allowedMimeTypes = $config['allowed_mime_types'] ?? [];

    if ($extension !== '' && in_array($extension, $allowedExtensions, true)) {
        $mimeList = $allowedMimeTypes[$extension] ?? [];
        if ($mimeList === [] || in_array($mimeType, $mimeList, true)) {
            return $extension;
        }
    }

    foreach ($allowedMimeTypes as $allowedExtension => $mimeList) {
        if (in_array($mimeType, $mimeList, true)) {
            return $allowedExtension;
        }
    }

    return '';
}

/**
 * Validate an uploaded file and return its metadata.
 *
 * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
 * @return array{original_name: string, mime_type: string, size: int, extension: string}
 */
function validate_uploaded_file(array $file, ?array $config = null): array
{
    $config = $config ?? file_storage_config();

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        switch ($error) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new RuntimeException('The file exceeds the maximum allowed size.');
            case UPLOAD_ERR_PARTIAL:
                throw new RuntimeException('The file upload was incomplete.');
            case UPLOAD_ERR_NO_FILE:
                throw new RuntimeException('No file was uploaded.');
            case UPLOAD_ERR_NO_TMP_DIR:
                throw new RuntimeException('Temporary folder is missing.');
            case UPLOAD_ERR_CANT_WRITE:
                throw new RuntimeException('Unable to write the uploaded file to disk.');
            case UPLOAD_ERR_EXTENSION:
                throw new RuntimeException('A PHP extension stopped the file upload.');
            default:
                throw new RuntimeException('Unexpected file upload error.');
        }
    }

    $size = isset($file['size']) ? (int) $file['size'] : 0;

    if ($size <= 0) {
        throw new RuntimeException('The uploaded file is empty.');
    }

    $maxSize = isset($config['max_size']) ? (int) $config['max_size'] : 0;
    if ($maxSize > 0 && $size > $maxSize) {
        throw new RuntimeException('The file exceeds the maximum allowed size.');
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    if ($temporaryPath === '' || ! is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('The uploaded file is invalid.');
    }

    $mimeType = detect_uploaded_mime_type($temporaryPath);
    $providedExtension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $extension = determine_uploaded_extension($providedExtension, $mimeType, $config);

    if ($extension === '' && isset($file['type']) && $file['type'] !== '') {
        $extension = determine_uploaded_extension($providedExtension, (string) $file['type'], $config);
    }

    if ($extension === '') {
        throw new RuntimeException('The file type is not supported.');
    }

    $originalName = (string) ($file['name'] ?? '');
    if ($originalName === '') {
        $originalName = 'archivo.' . $extension;
    }

    $sanitizedName = sanitize_uploaded_filename($originalName, $extension);

    return [
        'original_name' => $sanitizedName,
        'mime_type' => $mimeType,
        'size' => $size,
        'extension' => $extension,
    ];
}

/**
 * Move an uploaded file to storage.
 *
 * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
 * @param array{original_name: string, mime_type: string, size: int, extension: string} $metadata
 * @return array{original_name: string, mime_type: string, size: int, extension: string, stored_name: string, storage_path: string}
 */
function store_uploaded_file(array $file, array $metadata, ?array $config = null): array
{
    $config = $config ?? file_storage_config();
    $directory = ensure_file_storage_directory($config);
    $extension = $metadata['extension'];

    $attempts = 0;
    $storedName = '';

    while ($attempts < 5) {
        $attempts++;

        try {
            $random = bin2hex(random_bytes(16));
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to generate a secure filename for the upload.', 0, $exception);
        }

        $candidate = $random . '.' . $extension;
        $candidatePath = $directory . DIRECTORY_SEPARATOR . $candidate;

        if (! file_exists($candidatePath)) {
            $storedName = $candidate;
            $storagePath = $candidatePath;
            break;
        }
    }

    if ($storedName === '') {
        throw new RuntimeException('Unable to allocate a unique filename for the uploaded file.');
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    if ($temporaryPath === '' || ! is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('The uploaded file is no longer available.');
    }

    if (! move_uploaded_file($temporaryPath, $storagePath)) {
        throw new RuntimeException('Failed to move the uploaded file to the storage directory.');
    }

    return [
        'original_name' => $metadata['original_name'],
        'mime_type' => $metadata['mime_type'],
        'size' => $metadata['size'],
        'extension' => $metadata['extension'],
        'stored_name' => $storedName,
        'storage_path' => $storagePath,
    ];
}

function get_stored_file_path(string $storedName, ?array $config = null): string
{
    $config = $config ?? file_storage_config();
    $directory = rtrim($config['storage_dir'], DIRECTORY_SEPARATOR);
    $safeName = basename($storedName);

    return $directory . DIRECTORY_SEPARATOR . $safeName;
}

function delete_stored_file(string $storedName, ?array $config = null): bool
{
    $path = get_stored_file_path($storedName, $config);

    if (! is_file($path)) {
        return true;
    }

    return @unlink($path);
}
