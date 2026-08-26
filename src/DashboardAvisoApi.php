<?php

declare(strict_types=1);

require_once __DIR__ . '/DashboardAvisoMetrics.php';

/**
 * Phase 6B response assembler.
 *
 * It deliberately does not redefine business KPIs: all numerical values come
 * from DashboardAvisoMetrics (Phase 6A). This class only shapes the payload
 * required by the future Phase 6C visual dashboard.
 */
final class DashboardAvisoApi
{
    private DashboardAvisoMetrics $metrics;

    public function __construct(DashboardAvisoMetrics $metrics)
    {
        $this->metrics = $metrics;
    }

    /**
     * @param array<string,mixed> $filters
     * @param array{id?:int,name?:string,email?:string,role?:string} $user
     * @return array<string,mixed>
     */
    public function build(array $filters, array $user): array
    {
        $snapshot = $this->metrics->snapshot($filters);
        $resolvedFilters = is_array($snapshot['filters'] ?? null) ? $snapshot['filters'] : [];
        $kpis = is_array($snapshot['kpis'] ?? null) ? $snapshot['kpis'] : [];
        $scope = (string) ($resolvedFilters['record_scope'] ?? 'production');

        return [
            'api_contract_version' => '6B.1',
            'metric_contract_version' => (string) ($snapshot['contract_version'] ?? '6A.1'),
            'generated_at' => (string) ($snapshot['generated_at'] ?? (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM)),
            'date_basis' => $snapshot['date_basis'] ?? [],
            'filters' => $resolvedFilters,
            'permissions' => $this->permissionPayload($user),
            'filter_catalog' => $this->metrics->filterCatalog($scope),
            'kpis' => $kpis,
            'breakdowns' => [
                'status' => $this->statusBreakdown($kpis),
                'origin' => $this->originBreakdown($kpis),
                'merchandise_rows' => $this->merchandiseRowBreakdown($kpis),
                'pieces' => $this->pieceBreakdown($kpis),
            ],
            'cycle_times' => [
                'landing_to_notice_days' => $kpis['avg_days_landing_to_notice'] ?? null,
                'landing_to_notice_samples' => (int) ($kpis['landing_notice_samples'] ?? 0),
                'notice_to_presented_days' => $kpis['avg_days_notice_to_presented'] ?? null,
                'notice_to_presented_samples' => (int) ($kpis['notice_presented_samples'] ?? 0),
            ],
            'series' => [
                'monthly' => is_array($snapshot['monthly'] ?? null) ? $snapshot['monthly'] : [],
                'aging' => is_array($snapshot['aging'] ?? null) ? $snapshot['aging'] : [],
            ],
            'rankings' => is_array($snapshot['rankings'] ?? null) ? $snapshot['rankings'] : [
                'clients' => [],
                'rigs' => [],
            ],
            'recent_avisos' => $this->metrics->recentAvisos($resolvedFilters, 12),
            'integrity' => is_array($snapshot['integrity'] ?? null) ? $snapshot['integrity'] : [
                'pass' => false,
                'checks' => [],
                'failed_count' => 1,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function permissionPayload(array $user): array
    {
        $role = trim((string) ($user['role'] ?? ''));

        return [
            'role' => $role,
            'can_view_dashboard' => in_array($role, ['admin', 'usuario'], true),
            'can_view_qa' => $role === 'admin',
            'can_view_all_scopes' => $role === 'admin',
        ];
    }

    /** @return list<array{id:string,label:string,value:int}> */
    private function statusBreakdown(array $kpis): array
    {
        return [
            ['id' => 'draft', 'label' => 'Borrador', 'value' => (int) ($kpis['avisos_draft'] ?? 0)],
            ['id' => 'issued', 'label' => 'Emitido', 'value' => (int) ($kpis['avisos_issued'] ?? 0)],
            ['id' => 'presented', 'label' => 'Presentado', 'value' => (int) ($kpis['avisos_presented'] ?? 0)],
            ['id' => 'replaced', 'label' => 'Reemplazado', 'value' => (int) ($kpis['avisos_replaced'] ?? 0)],
            ['id' => 'cancelled', 'label' => 'Cancelado', 'value' => (int) ($kpis['avisos_cancelled'] ?? 0)],
        ];
    }

    /** @return list<array{id:string,label:string,value:int}> */
    private function originBreakdown(array $kpis): array
    {
        return [
            ['id' => 'system', 'label' => 'Sistema', 'value' => (int) ($kpis['avisos_system'] ?? 0)],
            ['id' => 'historical', 'label' => 'Histórico', 'value' => (int) ($kpis['avisos_historical'] ?? 0)],
        ];
    }

    /** @return list<array{id:string,label:string,value:int}> */
    private function merchandiseRowBreakdown(array $kpis): array
    {
        return [
            ['id' => 'stored', 'label' => 'Almacenada', 'value' => (int) ($kpis['rows_stored'] ?? 0)],
            ['id' => 'partial', 'label' => 'Parcial', 'value' => (int) ($kpis['rows_partial'] ?? 0)],
            ['id' => 'exported', 'label' => 'Exportada', 'value' => (int) ($kpis['rows_exported'] ?? 0)],
        ];
    }

    /** @return list<array{id:string,label:string,value:int|float}> */
    private function pieceBreakdown(array $kpis): array
    {
        return [
            ['id' => 'exported', 'label' => 'Exportadas', 'value' => $kpis['pieces_exported'] ?? 0],
            ['id' => 'stored', 'label' => 'En almacén', 'value' => $kpis['pieces_stored'] ?? 0],
        ];
    }
}
