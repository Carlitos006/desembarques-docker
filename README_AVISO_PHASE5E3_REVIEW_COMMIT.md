# Desembarques — Fase 5E3 · Review & Commit histórico

Esta fase conecta la bandeja de staging de 5E1/5E2 con la creación controlada de expedientes históricos reales.

## Principios

- El **PDF final histórico es el documento oficial** y se conserva exactamente como fue cargado.
- El DOCX, cuando existe, sirve únicamente como fuente auxiliar de extracción para PDFs escaneados.
- Nada se registra definitivamente hasta que un usuario interno guarda la revisión y confirma la importación.
- El commit de cada selección es atómico: si falla una fila del grupo seleccionado, se revierte todo el grupo.
- `item_count` significa **renglones de mercancía**; `piece_count` significa **suma de cantidades/piezas**. Nunca se usan como la misma métrica.
- Los expedientes históricos se identifican con `source_type = historical_import`.

## Cambios principales

### Revisión editable

`public/aviso-importar.php` ahora permite revisar/corregir:

- Aviso/manifiesto y código MADE.
- Fecha del oficio.
- Destinatario y firmante.
- Rig, IMO, campo, área y comitente.
- Transporte, IMO, consignataria y fechas operativas.
- Lugar/domicilios.
- Pedimentos e importadores.
- Renglones de mercancía, cantidad, serial, marca, pedimento y partida.
- Estado documental y fecha efectiva.
- Acción de duplicado: omitir o vincular como versión cuando corresponda.

### Commit definitivo

`api/desembarques/aviso/import/commit.php` crea, dentro de una transacción:

- `desembarques`
- `desembarque_aviso_details`
- `desembarque_aviso_items`
- `desembarque_pedimento_headers`
- `desembarque_pedimentos`
- `desembarque_manifests`
- `desembarque_aviso_versions` (PDF original como v001 histórica)
- `desembarque_aviso_status_history`
- eventos de auditoría

El PDF se copia al almacenamiento privado definitivo y se vuelve a validar por SHA-256 antes del commit.

### Duplicados

Se detectan por:

1. SHA-256 de PDF ya archivado.
2. Cliente + número de Aviso existente.

Un PDF idéntico sólo puede omitirse. Un Aviso con el mismo número pero PDF diferente puede omitirse o vincularse como una versión histórica adicional al expediente existente.

## Migración

Archivo:

`database/migrations/20260819_historical_import_phase5e3.sql`

Añade staging de revisión/commit, trazabilidad histórica en el Aviso y metadatos históricos en versiones PDF (`piece_count`, `document_date`, `source_type`, `source_import_row_id`).

La migración es idempotente para una instalación que ya tenga 5E1/5E2 y no elimina registros existentes.

## Instalación recomendada

Desde `C:\Dev\desembarques`:

```powershell
docker compose stop app
```

Copia los archivos del parche y luego:

```powershell
docker compose cp `
  .\database\migrations\20260819_historical_import_phase5e3.sql `
  db:/tmp/20260819_historical_import_phase5e3.sql

docker compose exec db mysql -uroot -p desembarques
```

En MySQL:

```sql
SOURCE /tmp/20260819_historical_import_phase5e3.sql;
```

Verificación sugerida:

```sql
DESCRIBE aviso_import_rows;
DESCRIBE desembarque_aviso_versions;

SELECT id, public_id, status, total_rows, ready_rows, review_rows,
       duplicate_rows, imported_rows, skipped_rows, failed_rows
FROM aviso_import_batches
ORDER BY id DESC
LIMIT 10;
```

Después:

```powershell
docker compose start app
docker compose ps
```

El Service Worker cambia de `v15` a `v16`; en Chrome usa `Ctrl + Shift + R`.

No es necesario reconstruir Docker ni Composer y no debe usarse `docker compose down -v`.

## Prueba de aceptación 061-26

Para el lote ya creado con `AVISO DESEMBARQUE 061-26.pdf` + DOCX:

1. Reabre el lote con `aviso-importar.php?batch=<id>`.
2. La revisión debe mostrar **1 renglón de mercancía y 2 piezas**, no 2 mercancías.
3. Confirma los datos extraídos y guarda la revisión.
4. La fila debe pasar a **Listo para importar**.
5. Selecciónala y confirma la importación.
6. Abre el expediente creado.
7. Debe mostrar badge **HISTÓRICO** y el PDF v001 como **ORIGINAL HISTÓRICO**.
8. Verifica:
   - 061-26 / MADE-061-26
   - DEEPWATER THALASSA / IMO 9675169 / TRION
   - HOS RENAISSANCE / IMO 9647667
   - BH · 26 81 1948 6000406
   - 1 renglón / 2 piezas
   - estado documental seleccionado
   - descarga íntegra del PDF histórico original.

## Alcance pendiente para 5E4

- Presentación especial del anexo fotográfico que ya viene incorporado dentro de PDFs escaneados.
- Limpieza/retención del staging después de políticas de conservación.
- Filtros globales `Sistema / Histórico` en vistas y reportes.
