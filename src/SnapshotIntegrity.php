<?php

declare(strict_types=1);

/**
 * Representación canónica para snapshots JSON.
 *
 * MySQL almacena JSON en formato binario y puede devolver las claves en un
 * orden distinto al texto originalmente insertado. Para que el SHA-256 sea
 * reproducible normalizamos recursivamente las claves de objetos; las listas
 * conservan su orden original.
 */
function aviso_snapshot_canonicalize(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        $normalized = [];
        foreach ($value as $item) {
            $normalized[] = aviso_snapshot_canonicalize($item);
        }
        return $normalized;
    }

    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = aviso_snapshot_canonicalize($item);
    }

    return $value;
}

function aviso_snapshot_canonical_json(mixed $value): string
{
    return json_encode(
        aviso_snapshot_canonicalize($value),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function aviso_snapshot_hash(mixed $value): string
{
    return hash('sha256', aviso_snapshot_canonical_json($value));
}

/**
 * @return array{valid_json:bool,hash_ok:bool,canonical_sha256:?string,error:?string,data:mixed}
 */
function aviso_snapshot_verify(string $json, string $expectedSha): array
{
    $json = trim($json);
    $expectedSha = strtolower(trim($expectedSha));

    if ($json === '' || strtolower($json) === 'null') {
        return [
            'valid_json' => false,
            'hash_ok' => false,
            'canonical_sha256' => null,
            'error' => 'Snapshot ausente.',
            'data' => null,
        ];
    }

    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $canonicalSha = aviso_snapshot_hash($data);
    } catch (Throwable $exception) {
        return [
            'valid_json' => false,
            'hash_ok' => false,
            'canonical_sha256' => null,
            'error' => 'Snapshot JSON inválido: ' . $exception->getMessage(),
            'data' => null,
        ];
    }

    return [
        'valid_json' => true,
        'hash_ok' => $expectedSha !== '' && hash_equals($expectedSha, strtolower($canonicalSha)),
        'canonical_sha256' => strtolower($canonicalSha),
        'error' => null,
        'data' => $data,
    ];
}
