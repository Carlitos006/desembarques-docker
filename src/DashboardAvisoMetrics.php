<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/dashboard.php';

/**
 * Authoritative KPI query layer for the Avisos executive dashboard.
 *
 * Phase 6A intentionally keeps this service independent from the UI/API so the
 * numerical contract can be verified from CLI before charts are built.
 */
final class DashboardAvisoMetrics
{
    private mysqli $db;

    /** @var array<string,bool> */
    private array $tableCache = [];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function snapshot(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);

        $summary = $this->fetchAvisoSummary($filters);
        $versions = $this->fetchVersionSummary($filters);
        $merchandise = $this->fetchMerchandiseSummary($filters);
        $pedimentos = $this->fetchPedimentoSummary($filters);
        $cycles = $this->fetchCycleSummary($filters);
        $monthly = $this->fetchMonthlySeries($filters);
        $aging = $this->fetchAgingBuckets($filters);
        $clients = $this->fetchClientRanking($filters);
        $rigs = $this->fetchRigRanking($filters);

        $kpis = array_merge($summary, $versions, $merchandise, $pedimentos, $cycles);

        return [
            'contract_version' => '6A.1',
            'generated_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'filters' => $filters,
            'date_basis' => getAvisoDashboardConfig()['date_basis'],
            'kpis' => $kpis,
            'monthly' => $monthly,
            'aging' => $aging,
            'rankings' => [
                'clients' => $clients,
                'rigs' => $rigs,
            ],
            'integrity' => $this->validateSnapshot($kpis),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $config = getAvisoDashboardConfig();

        $scope = trim((string) ($filters['record_scope'] ?? $config['default_scope']));
        if (! in_array($scope, $config['allowed_scopes'], true)) {
            $scope = (string) $config['default_scope'];
        }

        $origin = trim((string) ($filters['origin'] ?? 'all'));
        if (! in_array($origin, $config['allowed_origins'], true)) {
            $origin = 'all';
        }

        $status = trim((string) ($filters['aviso_status'] ?? 'all'));
        if (! in_array($status, $config['allowed_aviso_statuses'], true)) {
            $status = 'all';
        }

        $dateFrom = $this->normalizeDate($filters['date_from'] ?? null);
        $dateTo = $this->normalizeDate($filters['date_to'] ?? null);

        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $clientId = isset($filters['client_id']) ? (int) $filters['client_id'] : 0;
        $rigName = trim((string) ($filters['rig_name'] ?? ''));

        return [
            'record_scope' => $scope,
            'origin' => $origin,
            'aviso_status' => $status,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'client_id' => $clientId > 0 ? $clientId : null,
            'rig_name' => $rigName !== '' ? $rigName : null,
        ];
    }

    private function normalizeDate(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (! $date || $date->format('Y-m-d') !== $raw) {
            return null;
        }

        return $raw;
    }

    /**
     * Builds a reusable CTE that guarantees exactly one row per Aviso.
     *
     * @param array<string,mixed> $filters
     * @param string $types
     * @param list<mixed> $params
     */
    private function filteredAvisosCte(array $filters, string &$types, array &$params): string
    {
        $where = $this->buildWhereForCte($filters, $types, $params);

        return <<<SQL
            WITH filtered_avisos AS (
                SELECT
                    d.id,
                    d.client_id,
                    c.name AS client_name,
                    d.fecha_desembarque,
                    ad.office_date,
                    COALESCE(ad.office_date, d.fecha_desembarque) AS metric_date,
                    TRIM(COALESCE(ad.rig_name, '')) AS rig_name,
                    TRIM(COALESCE(ad.rig_field, '')) AS rig_field,
                    COALESCE(NULLIF(TRIM(ad.aviso_status), ''), 'draft') AS aviso_status,
                    COALESCE(ad.source_type, 'system') AS source_type,
                    CASE
                        WHEN ad.source_type = 'historical_import' THEN 'historical'
                        ELSE 'system'
                    END AS origin
                FROM desembarques d
                INNER JOIN desembarque_aviso_details ad ON ad.desembarque_id = d.id
                LEFT JOIN clients c ON c.id = d.client_id
                WHERE {$where}
            )
        SQL;
    }

    /** @param list<mixed> $params */
    private function queryPrepared(string $sql, string $types, array $params): mysqli_result|bool
    {
        $statement = $this->db->prepare($sql);

        if ($types !== '' && $params !== []) {
            $bindArgs = [$types];
            foreach ($params as $index => $_) {
                $bindArgs[] = &$params[$index];
            }
            call_user_func_array([$statement, 'bind_param'], $bindArgs);
        }

        $statement->execute();
        $result = $statement->get_result();
        // mysqli_result remains usable after statement close with mysqlnd buffered results.
        $statement->close();

        return $result;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,int>
     */
    private function fetchAvisoSummary(array $filters): array
    {
        $types = '';
        $params = [];
        $cte = $this->filteredAvisosCte($filters, $types, $params);

        $sql = $cte . <<<SQL
            SELECT
                COUNT(*) AS avisos_total,
                COALESCE(SUM(origin = 'historical'), 0) AS avisos_historical,
                COALESCE(SUM(origin = 'system'), 0) AS avisos_system,
                COALESCE(SUM(aviso_status = 'draft'), 0) AS avisos_draft,
                COALESCE(SUM(aviso_status = 'issued'), 0) AS avisos_issued,
                COALESCE(SUM(aviso_status = 'presented'), 0) AS avisos_presented,
                COALESCE(SUM(aviso_status = 'replaced'), 0) AS avisos_replaced,
                COALESCE(SUM(aviso_status = 'cancelled'), 0) AS avisos_cancelled,
                COUNT(DISTINCT CASE WHEN client_id IS NOT NULL THEN client_id END) AS clients_active,
                COUNT(DISTINCT CASE WHEN rig_name <> '' THEN rig_name END) AS rigs_active
            FROM filtered_avisos
        SQL;

        $row = $this->singleRow($this->queryPrepared($sql, $types, $params));

        return [
            'avisos_total' => (int) ($row['avisos_total'] ?? 0),
            'avisos_historical' => (int) ($row['avisos_historical'] ?? 0),
            'avisos_system' => (int) ($row['avisos_system'] ?? 0),
            'avisos_draft' => (int) ($row['avisos_draft'] ?? 0),
            'avisos_issued' => (int) ($row['avisos_issued'] ?? 0),
            'avisos_presented' => (int) ($row['avisos_presented'] ?? 0),
            'avisos_replaced' => (int) ($row['avisos_replaced'] ?? 0),
            'avisos_cancelled' => (int) ($row['avisos_cancelled'] ?? 0),
            'clients_active' => (int) ($row['clients_active'] ?? 0),
            'rigs_active' => (int) ($row['rigs_active'] ?? 0),
        ];
    }

    /**
     * Internal helper identical to filteredAvisosCte() WHERE assembly, split out
     * because the CTE template contains a %s placeholder.
     *
     * @param array<string,mixed> $filters
     * @param string $types
     * @param list<mixed> $params
     */
    private function buildWhereForCte(array $filters, string &$types, array &$params): string
    {
        $types = '';
        $params = [];
        // Fase 7F: el universo ejecutivo siempre contiene sólo Avisos activos.
        // record_scope sigue siendo una dimensión independiente y nunca simula una baja.
        $where = ['d.deleted_at IS NULL'];

        if (($filters['record_scope'] ?? 'production') !== 'all') {
            $where[] = 'd.record_scope = ?';
            $types .= 's';
            $params[] = (string) $filters['record_scope'];
        }
        if (! empty($filters['date_from'])) {
            $where[] = 'COALESCE(ad.office_date, d.fecha_desembarque) >= ?';
            $types .= 's';
            $params[] = (string) $filters['date_from'];
        }
        if (! empty($filters['date_to'])) {
            $where[] = 'COALESCE(ad.office_date, d.fecha_desembarque) <= ?';
            $types .= 's';
            $params[] = (string) $filters['date_to'];
        }
        if (! empty($filters['client_id'])) {
            $where[] = 'd.client_id = ?';
            $types .= 'i';
            $params[] = (int) $filters['client_id'];
        }
        if (! empty($filters['rig_name'])) {
            $where[] = 'TRIM(COALESCE(ad.rig_name, \'\')) = ?';
            $types .= 's';
            $params[] = (string) $filters['rig_name'];
        }
        if (($filters['aviso_status'] ?? 'all') !== 'all') {
            $where[] = 'COALESCE(NULLIF(TRIM(ad.aviso_status), \'\'), \'draft\') = ?';
            $types .= 's';
            $params[] = (string) $filters['aviso_status'];
        }
        if (($filters['origin'] ?? 'all') === 'historical') {
            $where[] = "ad.source_type = 'historical_import'";
        } elseif (($filters['origin'] ?? 'all') === 'system') {
            $where[] = "COALESCE(ad.source_type, 'system') <> 'historical_import'";
        }

        return implode("\n AND ", $where);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{pdf_versions:int}
     */
    private function fetchVersionSummary(array $filters): array
    {
        $types = '';
        $params = [];
        $cte = $this->filteredAvisosCte($filters, $types, $params);
        $sql = $cte . <<<SQL
            SELECT COUNT(*) AS pdf_versions
            FROM desembarque_aviso_versions v
            INNER JOIN filtered_avisos fa ON fa.id = v.desembarque_id
        SQL;
        $row = $this->singleRow($this->queryPrepared($sql, $types, $params));

        return ['pdf_versions' => (int) ($row['pdf_versions'] ?? 0)];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,int|float>
     */
    private function fetchMerchandiseSummary(array $filters): array
    {
        $types = '';
        $params = [];
        $cte = $this->filteredAvisosCte($filters, $types, $params);
        $movementCte = $this->movementTotalsCte();

        $sql = $cte . ',' . $movementCte . <<<SQL
            , item_balances AS (
                SELECT
                    i.id,
                    i.desembarque_id,
                    GREATEST(COALESCE(i.cantidad, 0), 0) AS original_qty,
                    GREATEST(COALESCE(mt.exported_qty, 0), 0) AS exported_qty,
                    GREATEST(
                        GREATEST(COALESCE(i.cantidad, 0), 0) - GREATEST(COALESCE(mt.exported_qty, 0), 0),
                        0
                    ) AS stored_qty
                FROM desembarque_aviso_items i
                INNER JOIN filtered_avisos fa ON fa.id = i.desembarque_id
                LEFT JOIN movement_totals mt ON mt.aviso_item_id = i.id
            )
            SELECT
                COUNT(*) AS merchandise_rows,
                COALESCE(SUM(original_qty), 0) AS pieces_original,
                COALESCE(SUM(exported_qty), 0) AS pieces_exported,
                COALESCE(SUM(stored_qty), 0) AS pieces_stored,
                COALESCE(SUM(exported_qty = 0), 0) AS rows_stored,
                COALESCE(SUM(exported_qty > 0 AND exported_qty < original_qty), 0) AS rows_partial,
                COALESCE(SUM(original_qty > 0 AND exported_qty >= original_qty), 0) AS rows_exported,
                COALESCE(SUM(exported_qty > original_qty), 0) AS rows_over_exported
            FROM item_balances
        SQL;

        $row = $this->singleRow($this->queryPrepared($sql, $types, $params));

        return [
            'merchandise_rows' => (int) ($row['merchandise_rows'] ?? 0),
            'pieces_original' => $this->number($row['pieces_original'] ?? 0),
            'pieces_exported' => $this->number($row['pieces_exported'] ?? 0),
            'pieces_stored' => $this->number($row['pieces_stored'] ?? 0),
            'rows_stored' => (int) ($row['rows_stored'] ?? 0),
            'rows_partial' => (int) ($row['rows_partial'] ?? 0),
            'rows_exported' => (int) ($row['rows_exported'] ?? 0),
            'rows_over_exported' => (int) ($row['rows_over_exported'] ?? 0),
        ];
    }

    private function movementTotalsCte(): string
    {
        if (! $this->tableExists('desembarque_item_finalization_lines') || ! $this->tableExists('desembarque_item_finalizations')) {
            return <<<SQL
                movement_totals AS (
                    SELECT NULL AS aviso_item_id, CAST(0 AS DECIMAL(14,3)) AS exported_qty
                    WHERE 1 = 0
                )
            SQL;
        }

        return <<<SQL
            movement_totals AS (
                SELECT
                    l.aviso_item_id,
                    SUM(COALESCE(l.quantity_exported, 0)) AS exported_qty
                FROM desembarque_item_finalization_lines l
                INNER JOIN desembarque_item_finalizations f ON f.id = l.finalization_id
                WHERE f.voided_at IS NULL
                GROUP BY l.aviso_item_id
            )
        SQL;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{pedimentos_unique:int,pedimento_links:int}
     */
    private function fetchPedimentoSummary(array $filters): array
    {
        $types = '';
        $params = [];
        $cte = $this->filteredAvisosCte($filters, $types, $params);

        $relationsUnion = '';
        if ($this->tableExists('desembarque_aviso_item_pedimentos')) {
            $relationsUnion = <<<SQL
                UNION ALL
                SELECT i.desembarque_id, ip.clave AS cve_pedimento, ip.num_pedimento
                FROM desembarque_aviso_item_pedimentos ip
                INNER JOIN desembarque_aviso_items i ON i.id = ip.aviso_item_id
                INNER JOIN filtered_avisos fa2 ON fa2.id = i.desembarque_id
            SQL;
        }

        $sql = $cte . <<<SQL
            , all_pedimentos AS (
                SELECT ph.desembarque_id, ph.cve_pedimento, ph.num_pedimento
                FROM desembarque_pedimento_headers ph
                INNER JOIN filtered_avisos fa ON fa.id = ph.desembarque_id
                {$relationsUnion}
            )
            SELECT
                COUNT(DISTINCT CASE
                    WHEN TRIM(COALESCE(p.num_pedimento, '')) <> ''
                    THEN CONCAT(UPPER(TRIM(COALESCE(p.cve_pedimento, ''))), '|', REPLACE(TRIM(p.num_pedimento), ' ', ''))
                    ELSE NULL
                END) AS pedimentos_unique,
                COUNT(DISTINCT CASE
                    WHEN TRIM(COALESCE(p.num_pedimento, '')) <> ''
                    THEN CONCAT(p.desembarque_id, '|', UPPER(TRIM(COALESCE(p.cve_pedimento, ''))), '|', REPLACE(TRIM(p.num_pedimento), ' ', ''))
                    ELSE NULL
                END) AS pedimento_links
            FROM all_pedimentos p
        SQL;

        $row = $this->singleRow($this->queryPrepared($sql, $types, $params));

        return [
            'pedimentos_unique' => (int) ($row['pedimentos_unique'] ?? 0),
            'pedimento_links' => (int) ($row['pedimento_links'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,int|float|null>
     */
    private function fetchCycleSummary(array $filters): array
    {
        $types = '';
        $params = [];
        $cte = $this->filteredAvisosCte($filters, $types, $params);

        $statusHistory = $this->tableExists('desembarque_aviso_status_history')
            ? <<<SQL
                , first_presented AS (
                    SELECT
                        h.desembarque_id,
                        MIN(COALESCE(h.effective_at, h.changed_at)) AS presented_at
                    FROM desembarque_aviso_status_history h
                    WHERE h.to_status = 'presented'
                    GROUP BY h.desembarque_id
                )
            SQL
            : <<<SQL
                , first_presented AS (
                    SELECT NULL AS desembarque_id, NULL AS presented_at
                    WHERE 1 = 0
                )
            SQL;

        $sql = $cte . $statusHistory . <<<SQL
            SELECT
                AVG(CASE
                    WHEN fa.office_date IS NOT NULL AND fa.fecha_desembarque IS NOT NULL
                    THEN DATEDIFF(fa.office_date, fa.fecha_desembarque)
                    ELSE NULL
                END) AS avg_days_landing_to_notice,
                AVG(CASE
                    WHEN fa.office_date IS NOT NULL AND fp.presented_at IS NOT NULL
                    THEN DATEDIFF(DATE(fp.presented_at), fa.office_date)
                    ELSE NULL
                END) AS avg_days_notice_to_presented,
                SUM(fa.office_date IS NOT NULL AND fa.fecha_desembarque IS NOT NULL) AS landing_notice_samples,
                SUM(fa.office_date IS NOT NULL AND fp.presented_at IS NOT NULL) AS notice_presented_samples
            FROM filtered_avisos fa
            LEFT JOIN first_presented fp ON fp.desembarque_id = fa.id
        SQL;

        $row = $this->singleRow($this->queryPrepared($sql, $types, $params));

        return [
            'avg_days_landing_to_notice' => $row['avg_days_landing_to_notice'] !== null
                ? round((float) $row['avg_days_landing_to_notice'], 2)
                : null,
            'avg_days_notice_to_presented' => $row['avg_days_notice_to_presented'] !== null
                ? round((float) $row['avg_days_notice_to_presented'], 2)
                : null,
            'landing_notice_samples' => (int) ($row['landing_notice_samples'] ?? 0),
            'notice_presented_samples' => (int) ($row['notice_presented_samples'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function fetchMonthlySeries(array $filters): array
    {
        $types = '';
        $params = [];
        $cte = $this->filteredAvisosCte($filters, $types, $params);

        $sql = $cte . <<<SQL
            SELECT
                DATE_FORMAT(metric_date, '%Y-%m') AS month,
                COUNT(*) AS avisos,
                COALESCE(SUM(origin = 'historical'), 0) AS historical_count,
                COALESCE(SUM(origin = 'system'), 0) AS system_count,
                COALESCE(SUM(aviso_status = 'presented'), 0) AS presented,
                COALESCE(SUM(aviso_status = 'issued'), 0) AS issued
            FROM filtered_avisos
            WHERE metric_date IS NOT NULL
            GROUP BY DATE_FORMAT(metric_date, '%Y-%m')
            ORDER BY month ASC
        SQL;

        return $this->allRows($this->queryPrepared($sql, $types, $params), static function (array $row): array {
            return [
                'month' => (string) ($row['month'] ?? ''),
                'avisos' => (int) ($row['avisos'] ?? 0),
                'historical' => (int) ($row['historical_count'] ?? 0),
                'system' => (int) ($row['system_count'] ?? 0),
                'presented' => (int) ($row['presented'] ?? 0),
                'issued' => (int) ($row['issued'] ?? 0),
            ];
        });
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function fetchAgingBuckets(array $filters): array
    {
        $types = '';
        $params = [];
        $cte = $this->filteredAvisosCte($filters, $types, $params);
        $movementCte = $this->movementTotalsCte();

        $sql = $cte . ',' . $movementCte . <<<SQL
            , stored_items AS (
                SELECT
                    i.id,
                    fa.fecha_desembarque,
                    GREATEST(
                        GREATEST(COALESCE(i.cantidad, 0), 0) - GREATEST(COALESCE(mt.exported_qty, 0), 0),
                        0
                    ) AS stored_qty
                FROM desembarque_aviso_items i
                INNER JOIN filtered_avisos fa ON fa.id = i.desembarque_id
                LEFT JOIN movement_totals mt ON mt.aviso_item_id = i.id
            )
            SELECT
                CASE
                    WHEN fecha_desembarque IS NULL THEN 'unknown'
                    WHEN DATEDIFF(CURRENT_DATE, fecha_desembarque) <= 30 THEN '0_30'
                    WHEN DATEDIFF(CURRENT_DATE, fecha_desembarque) <= 60 THEN '31_60'
                    WHEN DATEDIFF(CURRENT_DATE, fecha_desembarque) <= 90 THEN '61_90'
                    ELSE '91_plus'
                END AS bucket,
                COUNT(CASE WHEN stored_qty > 0 THEN 1 END) AS rows_count,
                COALESCE(SUM(stored_qty), 0) AS pieces_stored
            FROM stored_items
            WHERE stored_qty > 0
            GROUP BY bucket
        SQL;

        $raw = $this->allRows($this->queryPrepared($sql, $types, $params), fn(array $row): array => [
            'bucket' => (string) ($row['bucket'] ?? 'unknown'),
            'rows_count' => (int) ($row['rows_count'] ?? 0),
            'pieces_stored' => $this->number($row['pieces_stored'] ?? 0),
        ]);

        $byId = [];
        foreach ($raw as $row) {
            $byId[$row['bucket']] = $row;
        }

        $result = [];
        foreach (getAvisoDashboardConfig()['aging_buckets'] as $bucket) {
            $id = (string) $bucket['id'];
            $result[] = [
                'id' => $id,
                'label' => (string) $bucket['label'],
                'rows_count' => (int) ($byId[$id]['rows_count'] ?? 0),
                'pieces_stored' => $this->number($byId[$id]['pieces_stored'] ?? 0),
            ];
        }

        if (isset($byId['unknown'])) {
            $result[] = [
                'id' => 'unknown',
                'label' => 'Fecha desconocida',
                'rows_count' => (int) $byId['unknown']['rows_count'],
                'pieces_stored' => $this->number($byId['unknown']['pieces_stored']),
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function fetchClientRanking(array $filters): array
    {
        return $this->fetchDimensionRanking($filters, 'client');
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function fetchRigRanking(array $filters): array
    {
        return $this->fetchDimensionRanking($filters, 'rig');
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function fetchDimensionRanking(array $filters, string $dimension): array
    {
        $types = '';
        $params = [];
        $cte = $this->filteredAvisosCte($filters, $types, $params);
        $movementCte = $this->movementTotalsCte();

        $dimensionExpr = $dimension === 'client'
            ? "COALESCE(NULLIF(TRIM(fa.client_name), ''), 'Sin cliente')"
            : "COALESCE(NULLIF(TRIM(fa.rig_name), ''), 'Sin Rig')";

        $sql = $cte . ',' . $movementCte . <<<SQL
            , item_balances AS (
                SELECT
                    i.desembarque_id,
                    GREATEST(COALESCE(i.cantidad, 0), 0) AS original_qty,
                    GREATEST(COALESCE(mt.exported_qty, 0), 0) AS exported_qty,
                    GREATEST(
                        GREATEST(COALESCE(i.cantidad, 0), 0) - GREATEST(COALESCE(mt.exported_qty, 0), 0),
                        0
                    ) AS stored_qty
                FROM desembarque_aviso_items i
                INNER JOIN filtered_avisos fa2 ON fa2.id = i.desembarque_id
                LEFT JOIN movement_totals mt ON mt.aviso_item_id = i.id
            ), item_by_aviso AS (
                SELECT
                    desembarque_id,
                    COUNT(*) AS rows_count,
                    COALESCE(SUM(original_qty), 0) AS pieces_original,
                    COALESCE(SUM(exported_qty), 0) AS pieces_exported,
                    COALESCE(SUM(stored_qty), 0) AS pieces_stored
                FROM item_balances
                GROUP BY desembarque_id
            )
            SELECT
                {$dimensionExpr} AS label,
                COUNT(*) AS avisos,
                COALESCE(SUM(iba.rows_count), 0) AS merchandise_rows,
                COALESCE(SUM(iba.pieces_original), 0) AS pieces_original,
                COALESCE(SUM(iba.pieces_exported), 0) AS pieces_exported,
                COALESCE(SUM(iba.pieces_stored), 0) AS pieces_stored
            FROM filtered_avisos fa
            LEFT JOIN item_by_aviso iba ON iba.desembarque_id = fa.id
            GROUP BY label
            ORDER BY avisos DESC, label ASC
            LIMIT 20
        SQL;

        return $this->allRows($this->queryPrepared($sql, $types, $params), fn(array $row): array => [
            'label' => (string) ($row['label'] ?? ''),
            'avisos' => (int) ($row['avisos'] ?? 0),
            'merchandise_rows' => (int) ($row['merchandise_rows'] ?? 0),
            'pieces_original' => $this->number($row['pieces_original'] ?? 0),
            'pieces_exported' => $this->number($row['pieces_exported'] ?? 0),
            'pieces_stored' => $this->number($row['pieces_stored'] ?? 0),
        ]);
    }

    /**
     * Returns the most recent Avisos under the same authoritative 6A filters.
     * This is presentation support for Phase 6B and does not define new KPIs.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function recentAvisos(array $filters = [], int $limit = 10): array
    {
        $filters = $this->normalizeFilters($filters);
        $limit = max(1, min($limit, 25));

        $types = '';
        $params = [];
        $cte = $this->filteredAvisosCte($filters, $types, $params);

        $sql = $cte . <<<SQL
            , version_summary AS (
                SELECT
                    desembarque_id,
                    COUNT(*) AS version_count,
                    MAX(version_no) AS latest_version_no,
                    MAX(generated_at) AS latest_version_at
                FROM desembarque_aviso_versions
                GROUP BY desembarque_id
            )
            SELECT
                fa.id,
                fa.client_id,
                fa.client_name,
                fa.metric_date,
                fa.fecha_desembarque,
                fa.rig_name,
                fa.rig_field,
                fa.aviso_status,
                fa.origin,
                ad.notice_number,
                ad.document_code,
                COALESCE(vs.version_count, 0) AS version_count,
                vs.latest_version_no,
                vs.latest_version_at
            FROM filtered_avisos fa
            INNER JOIN desembarque_aviso_details ad ON ad.desembarque_id = fa.id
            LEFT JOIN version_summary vs ON vs.desembarque_id = fa.id
            ORDER BY
                fa.metric_date IS NULL ASC,
                fa.metric_date DESC,
                fa.id DESC
            LIMIT {$limit}
        SQL;

        return $this->allRows($this->queryPrepared($sql, $types, $params), static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'client_id' => isset($row['client_id']) && $row['client_id'] !== null ? (int) $row['client_id'] : null,
                'client_name' => (string) ($row['client_name'] ?? ''),
                'notice_number' => (string) ($row['notice_number'] ?? ''),
                'document_code' => (string) ($row['document_code'] ?? ''),
                'metric_date' => isset($row['metric_date']) && $row['metric_date'] !== null ? (string) $row['metric_date'] : null,
                'landing_date' => isset($row['fecha_desembarque']) && $row['fecha_desembarque'] !== null
                    ? (string) $row['fecha_desembarque']
                    : null,
                'rig_name' => (string) ($row['rig_name'] ?? ''),
                'rig_field' => (string) ($row['rig_field'] ?? ''),
                'aviso_status' => (string) ($row['aviso_status'] ?? 'draft'),
                'origin' => (string) ($row['origin'] ?? 'system'),
                'version_count' => (int) ($row['version_count'] ?? 0),
                'latest_version_no' => isset($row['latest_version_no']) && $row['latest_version_no'] !== null
                    ? (int) $row['latest_version_no']
                    : null,
                'latest_version_at' => isset($row['latest_version_at']) && $row['latest_version_at'] !== null
                    ? (string) $row['latest_version_at']
                    : null,
            ];
        });
    }

    /**
     * Returns filter catalogs for the selected record scope. Catalog values are
     * intentionally independent from the other active filters so selections do
     * not disappear while the executive user explores the dashboard.
     *
     * @return array<string,mixed>
     */
    public function filterCatalog(string $recordScope = 'production'): array
    {
        $filters = $this->normalizeFilters(['record_scope' => $recordScope]);
        $types = '';
        $params = [];
        $cte = $this->filteredAvisosCte($filters, $types, $params);

        $dateSql = $cte . <<<SQL
            SELECT
                MIN(metric_date) AS min_date,
                MAX(metric_date) AS max_date
            FROM filtered_avisos
            WHERE metric_date IS NOT NULL
        SQL;
        $dateRow = $this->singleRow($this->queryPrepared($dateSql, $types, $params));

        $types = '';
        $params = [];
        $cte = $this->filteredAvisosCte($filters, $types, $params);
        $clientSql = $cte . <<<SQL
            SELECT
                client_id AS id,
                COALESCE(NULLIF(TRIM(client_name), ''), 'Sin cliente') AS label,
                COUNT(*) AS avisos
            FROM filtered_avisos
            WHERE client_id IS NOT NULL
            GROUP BY client_id, label
            ORDER BY label ASC
        SQL;
        $clients = $this->allRows($this->queryPrepared($clientSql, $types, $params), static fn(array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'label' => (string) ($row['label'] ?? ''),
            'avisos' => (int) ($row['avisos'] ?? 0),
        ]);

        $types = '';
        $params = [];
        $cte = $this->filteredAvisosCte($filters, $types, $params);
        $rigSql = $cte . <<<SQL
            SELECT
                rig_name AS value,
                rig_name AS label,
                COUNT(*) AS avisos
            FROM filtered_avisos
            WHERE rig_name <> ''
            GROUP BY rig_name
            ORDER BY rig_name ASC
        SQL;
        $rigs = $this->allRows($this->queryPrepared($rigSql, $types, $params), static fn(array $row): array => [
            'value' => (string) ($row['value'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'avisos' => (int) ($row['avisos'] ?? 0),
        ]);

        $years = [];
        $minDate = isset($dateRow['min_date']) && $dateRow['min_date'] !== null ? (string) $dateRow['min_date'] : null;
        $maxDate = isset($dateRow['max_date']) && $dateRow['max_date'] !== null ? (string) $dateRow['max_date'] : null;
        if ($minDate !== null && $maxDate !== null) {
            $minYear = (int) substr($minDate, 0, 4);
            $maxYear = (int) substr($maxDate, 0, 4);
            if ($minYear > 0 && $maxYear >= $minYear) {
                for ($year = $maxYear; $year >= $minYear; --$year) {
                    $years[] = $year;
                }
            }
        }

        return [
            'record_scope' => $filters['record_scope'],
            'date_bounds' => [
                'min' => $minDate,
                'max' => $maxDate,
            ],
            'years' => $years,
            'clients' => $clients,
            'rigs' => $rigs,
        ];
    }

    /** @return array<string,mixed> */
    private function validateSnapshot(array $kpis): array
    {
        $checks = [];

        $this->check(
            $checks,
            'origin_partition',
            (int) ($kpis['avisos_total'] ?? 0) === (int) ($kpis['avisos_historical'] ?? 0) + (int) ($kpis['avisos_system'] ?? 0),
            'Total avisos = históricos + sistema'
        );

        $statusSum = (int) ($kpis['avisos_draft'] ?? 0)
            + (int) ($kpis['avisos_issued'] ?? 0)
            + (int) ($kpis['avisos_presented'] ?? 0)
            + (int) ($kpis['avisos_replaced'] ?? 0)
            + (int) ($kpis['avisos_cancelled'] ?? 0);
        $this->check(
            $checks,
            'status_partition',
            (int) ($kpis['avisos_total'] ?? 0) === $statusSum,
            'Cada aviso pertenece a un único estado documental conocido'
        );

        $rowStatusSum = (int) ($kpis['rows_stored'] ?? 0)
            + (int) ($kpis['rows_partial'] ?? 0)
            + (int) ($kpis['rows_exported'] ?? 0);
        $this->check(
            $checks,
            'merchandise_partition',
            (int) ($kpis['merchandise_rows'] ?? 0) === $rowStatusSum,
            'Cada renglón está almacenado, parcial o exportado'
        );

        $original = (float) ($kpis['pieces_original'] ?? 0);
        $exported = (float) ($kpis['pieces_exported'] ?? 0);
        $stored = (float) ($kpis['pieces_stored'] ?? 0);
        $this->check(
            $checks,
            'piece_balance',
            abs($original - ($exported + $stored)) < 0.001,
            'Piezas originales = exportadas + almacenadas'
        );

        $this->check(
            $checks,
            'no_over_export',
            (int) ($kpis['rows_over_exported'] ?? 0) === 0,
            'Ningún renglón tiene más piezas exportadas que originales'
        );

        $failed = array_values(array_filter($checks, static fn(array $check): bool => ! $check['pass']));

        return [
            'pass' => $failed === [],
            'checks' => $checks,
            'failed_count' => count($failed),
        ];
    }

    /** @param list<array<string,mixed>> $checks */
    private function check(array &$checks, string $id, bool $pass, string $message): void
    {
        $checks[] = ['id' => $id, 'pass' => $pass, 'message' => $message];
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }

        $statement = $this->db->prepare(
            'SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $statement->bind_param('s', $table);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();

        return $this->tableCache[$table] = ((int) ($row['total'] ?? 0)) > 0;
    }

    /** @return array<string,mixed> */
    private function singleRow(mysqli_result|bool $result): array
    {
        if (! $result instanceof mysqli_result) {
            return [];
        }

        $row = $result->fetch_assoc() ?: [];
        $result->free();

        return $row;
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $mapper
     * @return list<array<string,mixed>>
     */
    private function allRows(mysqli_result|bool $result, callable $mapper): array
    {
        if (! $result instanceof mysqli_result) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $mapper($row);
        }
        $result->free();

        return $rows;
    }

    private function number(mixed $value): int|float
    {
        $number = (float) $value;
        if (abs($number - round($number)) < 0.000001) {
            return (int) round($number);
        }

        return round($number, 3);
    }
}
