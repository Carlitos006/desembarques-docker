-- Fase 6A — Consultas manuales de contraste para KPIs.
-- Por defecto excluyen record_scope <> production.

-- 1) Universo de Avisos. Un aviso = un desembarque con aviso_details.
SELECT
    COUNT(*) AS avisos_total,
    SUM(ad.source_type = 'historical_import') AS historicos,
    SUM(COALESCE(ad.source_type, 'system') <> 'historical_import') AS sistema,
    SUM(ad.aviso_status = 'draft') AS borradores,
    SUM(ad.aviso_status = 'issued') AS emitidos,
    SUM(ad.aviso_status = 'presented') AS presentados,
    SUM(ad.aviso_status = 'replaced') AS reemplazados,
    SUM(ad.aviso_status = 'cancelled') AS cancelados
FROM desembarques d
INNER JOIN desembarque_aviso_details ad ON ad.desembarque_id = d.id
WHERE d.deleted_at IS NULL
  AND d.record_scope = 'production';

-- 2) Avisos vs versiones PDF. Las versiones NO deben inflar avisos_total.
SELECT
    COUNT(DISTINCT d.id) AS avisos,
    COUNT(v.id) AS versiones_pdf
FROM desembarques d
INNER JOIN desembarque_aviso_details ad ON ad.desembarque_id = d.id
LEFT JOIN desembarque_aviso_versions v ON v.desembarque_id = d.id
WHERE d.deleted_at IS NULL
  AND d.record_scope = 'production';

-- 3) Mercancía original / exportada / almacenada.
WITH movimientos AS (
    SELECT
        l.aviso_item_id,
        SUM(l.quantity_exported) AS exported_qty
    FROM desembarque_item_finalization_lines l
    INNER JOIN desembarque_item_finalizations f ON f.id = l.finalization_id
    WHERE f.voided_at IS NULL
    GROUP BY l.aviso_item_id
), saldos AS (
    SELECT
        i.id,
        GREATEST(COALESCE(i.cantidad, 0), 0) AS original_qty,
        GREATEST(COALESCE(m.exported_qty, 0), 0) AS exported_qty,
        GREATEST(
            GREATEST(COALESCE(i.cantidad, 0), 0) - GREATEST(COALESCE(m.exported_qty, 0), 0),
            0
        ) AS stored_qty
    FROM desembarque_aviso_items i
    INNER JOIN desembarques d ON d.id = i.desembarque_id
    INNER JOIN desembarque_aviso_details ad ON ad.desembarque_id = d.id
    LEFT JOIN movimientos m ON m.aviso_item_id = i.id
    WHERE d.deleted_at IS NULL
      AND d.record_scope = 'production'
)
SELECT
    COUNT(*) AS renglones,
    SUM(original_qty) AS piezas_desembarcadas,
    SUM(exported_qty) AS piezas_exportadas,
    SUM(stored_qty) AS piezas_almacenadas,
    SUM(exported_qty = 0) AS renglones_almacenados,
    SUM(exported_qty > 0 AND exported_qty < original_qty) AS renglones_parciales,
    SUM(original_qty > 0 AND exported_qty >= original_qty) AS renglones_exportados,
    SUM(exported_qty > original_qty) AS errores_sobreexportacion
FROM saldos;

-- 4) Pedimentos únicos y vínculos aviso/pedimento.
SELECT
    COUNT(DISTINCT CONCAT(
        UPPER(TRIM(COALESCE(ph.cve_pedimento, ''))), '|',
        REPLACE(TRIM(ph.num_pedimento), ' ', '')
    )) AS pedimentos_unicos,
    COUNT(DISTINCT CONCAT(
        ph.desembarque_id, '|',
        UPPER(TRIM(COALESCE(ph.cve_pedimento, ''))), '|',
        REPLACE(TRIM(ph.num_pedimento), ' ', '')
    )) AS vinculos_aviso_pedimento
FROM desembarque_pedimento_headers ph
INNER JOIN desembarques d ON d.id = ph.desembarque_id
INNER JOIN desembarque_aviso_details ad ON ad.desembarque_id = d.id
WHERE d.deleted_at IS NULL
  AND d.record_scope = 'production'
  AND TRIM(COALESCE(ph.num_pedimento, '')) <> '';

-- 5) Distribución por scope. Útil para confirmar que QA no entra al dashboard directivo.
SELECT record_scope, COUNT(*) AS registros
FROM desembarques
WHERE deleted_at IS NULL
GROUP BY record_scope
ORDER BY record_scope;
