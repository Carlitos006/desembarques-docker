# Fase 5C — Estados documentales del Aviso

Fecha: 2026-08-18

## Objetivo

Separar formalmente el **estado documental del Aviso de Desembarque** del estado operativo del desembarque.

La gestión documental de Fase 5B permanece deliberadamente independiente: **PDF/DOC/DOCX pueden añadirse y reemplazarse en cualquier estado del Aviso**.

## Estados

- `draft` — Borrador
- `issued` — Emitido
- `presented` — Presentado
- `replaced` — Reemplazado
- `cancelled` — Cancelado

## Reglas de transición

### Automática

Al archivar un PDF como nueva versión histórica:

- `draft` → `issued`
- `presented` → `issued`
- `replaced` → `issued`
- `issued` permanece `issued`
- `cancelled` bloquea la emisión hasta que un administrador lo reabra a `draft`

Así, una nueva versión emitida después de una presentación o reemplazo queda claramente identificada como una nueva emisión pendiente de presentación.

### Manual

- `draft` → `cancelled`
- `issued` → `presented`, `replaced`, `cancelled`
- `presented` → `replaced`, `cancelled`
- `replaced` → `cancelled`
- `cancelled` → `draft` **sólo administrador**

`replaced`, `cancelled` y la reapertura `cancelled → draft` requieren motivo.

`presented` requiere fecha y hora de presentación; la observación es opcional.

## Persistencia

Se agregan a `desembarque_aviso_details`:

- `aviso_status`
- `aviso_status_effective_at`
- `aviso_status_changed_at`
- `aviso_status_changed_by`

Se crea:

- `desembarque_aviso_status_history`

Cada transición registra estado anterior/nuevo, motivo, fecha efectiva, origen, versión PDF relacionada, usuario y fecha de cambio.

Los avisos que ya tenían una versión PDF antes de Fase 5C se migran automáticamente a `issued`, conservando como fecha de transición la primera emisión histórica.

## Expediente

La cabecera del expediente ahora muestra dos estados separados:

- **Estado documental del Aviso**
- **Estado operativo del desembarque**

En Resumen hay una tarjeta propia del ciclo documental.

Los usuarios internos pueden usar **Cambiar estado del aviso** según las transiciones disponibles. Un aviso cancelado conserva documentos, fotos y PDFs históricos, pero no permite modificar los datos fuente ni emitir otra versión hasta ser reabierto a Borrador por un administrador.

## Historial

Las transiciones aparecen en la pestaña **Historial** y además generan eventos en `audit_logs` con `entity_type = aviso_status`.

## Archivos principales

- `config/aviso_status.php`
- `api/desembarques/aviso/_bootstrap.php`
- `api/desembarques/aviso/status.php`
- `api/desembarques/aviso/load.php`
- `api/desembarques/aviso/save.php`
- `api/desembarques/aviso/versions.php`
- `public/aviso-expediente.php`
- `public/assets/js/aviso-expediente-status.js`
- `public/assets/js/reports.js`
- `public/assets/css/aviso-expediente.css`
- `public/service-worker.js`
- `database/migrations/20260818_aviso_status_phase5c.sql`

## Instalación sobre Fase 5B

1. Copiar el parche conservando las rutas.
2. Copiar la migración al contenedor DB:

```powershell
docker compose cp `
  .\database\migrations\20260818_aviso_status_phase5c.sql `
  db:/tmp/20260818_aviso_status_phase5c.sql
```

3. Entrar a MySQL:

```powershell
docker compose exec db mysql -uroot -p desembarques
```

4. Ejecutar:

```sql
SOURCE /tmp/20260818_aviso_status_phase5c.sql;
```

5. Salir y reiniciar sólo la aplicación:

```powershell
docker compose restart app
```

6. Hacer `Ctrl + Shift + R`. El Service Worker usa `v14`.

No se requiere rebuild de Docker. No usar `docker compose down -v`.

## Prueba de aceptación recomendada

1. Abrir un expediente que ya tenga al menos un PDF histórico. Debe aparecer como **Emitido**.
2. Cambiarlo a **Presentado**, indicando fecha/hora.
3. Añadir un PDF en Documentos. Debe seguir funcionando en Presentado.
4. Generar una nueva versión PDF. El estado debe volver automáticamente a **Emitido**.
5. Cambiar a **Reemplazado** con motivo y generar otra versión. Debe volver a **Emitido**.
6. Cambiar a **Cancelado** con motivo. Documentos debe seguir permitiendo altas/reemplazos, pero Generar nueva versión debe quedar bloqueado.
7. Como administrador, reabrir `Cancelado → Borrador` con motivo.
8. Generar una nueva versión: `Borrador → Emitido` automáticamente.
9. Confirmar en Historial todas las transiciones y sus usuarios/fechas.
