<?php

declare(strict_types=1);

/**
 * Catálogo documental del Aviso de Desembarque.
 * El estado operativo del desembarque vive en desembarque_statuses y es independiente.
 *
 * @return array<string,array{label_es:string,label_en:string,badge:string}>
 */
function aviso_status_catalog(): array
{
    return [
        'draft' => [
            'label_es' => 'Borrador',
            'label_en' => 'Draft',
            'badge' => 'text-bg-secondary',
        ],
        'issued' => [
            'label_es' => 'Emitido',
            'label_en' => 'Issued',
            'badge' => 'text-bg-primary',
        ],
        'presented' => [
            'label_es' => 'Presentado',
            'label_en' => 'Presented',
            'badge' => 'text-bg-success',
        ],
        'replaced' => [
            'label_es' => 'Reemplazado',
            'label_en' => 'Replaced',
            'badge' => 'text-bg-warning text-dark',
        ],
        'cancelled' => [
            'label_es' => 'Cancelado',
            'label_en' => 'Cancelled',
            'badge' => 'text-bg-danger',
        ],
    ];
}

function aviso_status_normalize(mixed $value): string
{
    $status = strtolower(trim((string) $value));
    return array_key_exists($status, aviso_status_catalog()) ? $status : 'draft';
}

/** @return array{slug:string,label:string,label_es:string,label_en:string,badge:string} */
function aviso_status_meta(mixed $value, string $language = 'es'): array
{
    $slug = aviso_status_normalize($value);
    $catalog = aviso_status_catalog();
    $row = $catalog[$slug];
    $label = $language === 'en' ? $row['label_en'] : $row['label_es'];

    return [
        'slug' => $slug,
        'label' => $label,
        'label_es' => $row['label_es'],
        'label_en' => $row['label_en'],
        'badge' => $row['badge'],
    ];
}

/**
 * Transiciones manuales autorizadas. La transición a EMITIDO se produce al archivar un PDF.
 *
 * @return list<string>
 */
function aviso_status_manual_transitions(string $status, string $role): array
{
    $status = aviso_status_normalize($status);

    $transitions = match ($status) {
        'draft' => ['cancelled'],
        'issued' => ['presented', 'replaced', 'cancelled'],
        'presented' => ['replaced', 'cancelled'],
        'replaced' => ['cancelled'],
        'cancelled' => $role === 'admin' ? ['draft'] : [],
        default => [],
    };

    return $transitions;
}

function aviso_status_requires_reason(string $fromStatus, string $toStatus): bool
{
    $fromStatus = aviso_status_normalize($fromStatus);
    $toStatus = aviso_status_normalize($toStatus);

    return in_array($toStatus, ['replaced', 'cancelled'], true)
        || ($fromStatus === 'cancelled' && $toStatus === 'draft');
}

function aviso_status_requires_effective_at(string $toStatus): bool
{
    return aviso_status_normalize($toStatus) === 'presented';
}

function aviso_status_can_generate(string $status): bool
{
    return aviso_status_normalize($status) !== 'cancelled';
}

function aviso_status_auto_after_version(string $status): string
{
    $status = aviso_status_normalize($status);
    if ($status === 'cancelled') {
        return 'cancelled';
    }

    return 'issued';
}
