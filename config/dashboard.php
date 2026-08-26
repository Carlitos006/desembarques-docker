<?php

declare(strict_types=1);

/**
 * Contract for the executive Desembarques dashboard (Phase 6A).
 *
 * IMPORTANT SEMANTICS
 * - One aviso = one desembarque that has desembarque_aviso_details.
 * - PDF versions are counted separately and never inflate aviso totals.
 * - Historical origin comes from desembarque_aviso_details.source_type = historical_import.
 * - Dashboard date = office_date when available, otherwise fecha_desembarque.
 * - Merchandise rows and pieces are different metrics.
 * - Exported pieces are derived only from non-voided finalization movements.
 * - Production dashboards exclude record_scope = qa by default.
 *
 * @return array<string,mixed>
 */
function getAvisoDashboardConfig(): array
{
    return [
        'default_scope' => 'production',
        'allowed_scopes' => ['production', 'qa', 'all'],
        'allowed_origins' => ['all', 'system', 'historical'],
        'allowed_aviso_statuses' => [
            'all',
            'draft',
            'issued',
            'presented',
            'replaced',
            'cancelled',
        ],
        'date_basis' => [
            'primary' => 'desembarque_aviso_details.office_date',
            'fallback' => 'desembarques.fecha_desembarque',
            'label' => 'Fecha del aviso; si no existe, fecha de desembarque',
        ],
        'aging_buckets' => [
            ['id' => '0_30', 'label' => '0–30 días', 'min_days' => 0, 'max_days' => 30],
            ['id' => '31_60', 'label' => '31–60 días', 'min_days' => 31, 'max_days' => 60],
            ['id' => '61_90', 'label' => '61–90 días', 'min_days' => 61, 'max_days' => 90],
            ['id' => '91_plus', 'label' => '+90 días', 'min_days' => 91, 'max_days' => null],
        ],
        'kpis' => [
            'avisos_total' => 'Avisos totales',
            'avisos_historical' => 'Avisos históricos importados',
            'avisos_system' => 'Avisos generados por sistema',
            'avisos_draft' => 'Borradores',
            'avisos_issued' => 'Emitidos',
            'avisos_presented' => 'Presentados',
            'avisos_replaced' => 'Reemplazados',
            'avisos_cancelled' => 'Cancelados',
            'pdf_versions' => 'Versiones PDF emitidas',
            'merchandise_rows' => 'Renglones de mercancía',
            'pieces_original' => 'Piezas desembarcadas',
            'pieces_exported' => 'Piezas exportadas',
            'pieces_stored' => 'Piezas en almacén',
            'rows_stored' => 'Mercancías almacenadas',
            'rows_partial' => 'Mercancías parciales',
            'rows_exported' => 'Mercancías exportadas',
            'pedimentos_unique' => 'Pedimentos únicos',
            'pedimento_links' => 'Pedimentos vinculados a avisos',
            'clients_active' => 'Clientes con actividad',
            'rigs_active' => 'Rigs / proyectos con actividad',
            'avg_days_landing_to_notice' => 'Promedio desembarque → aviso (días)',
            'avg_days_notice_to_presented' => 'Promedio aviso → presentación (días)',
        ],
    ];
}
