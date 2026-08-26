# Fase 5B — Gestión documental del expediente

## Objetivo

Convertir la pestaña **Documentos** del expediente del Aviso de Desembarque en un gestor documental real, manteniendo estas reglas:

- Documentos = **PDF / DOC / DOCX**.
- Fotografías continúan exclusivamente en **Fotos**.
- El Excel fuente continúa siendo un insumo privado del sistema y no se expone ni descarga desde la interfaz.
- La carga y el reemplazo de documentos **no dependen del estado del Aviso**.
- Los documentos del expediente **no se eliminan**: se reemplazan de forma controlada y se conserva la versión anterior.
- Cada documento nuevo registra SHA-256.
- La descarga valida SHA-256 cuando existe una huella registrada.
- Las altas y reemplazos quedan en `audit_logs`.

## Cambios principales

### Base de datos

La migración `database/migrations/20260818_aviso_documents_phase5b.sql` amplía `desembarque_files` con:

- `purpose`
- `document_type`
- `description`
- `document_date`
- `sha256`
- `is_active`
- `replaces_file_id`
- `replaced_at`
- `replaced_by`

La migración es conservadora e idempotente mediante `information_schema`; no usa `ADD COLUMN IF NOT EXISTS`.

Backfill:

- Excel fuente → `purpose=source_excel`
- Fotografías relacionadas al anexo → `purpose=photo`
- PDF / DOC / DOCX existentes → `purpose=case_document`, `document_type=other`
- Otros archivos → `purpose=attachment`

Los documentos legacy existentes no reciben un SHA-256 inventado desde SQL; permanecen identificados como legacy hasta que sean reemplazados por una versión gestionada por Fase 5B.

### Tipos documentales

- Pedimento
- Manifiesto
- CIPL
- Acuse
- Oficio
- Factura
- Documento aduanal
- Otro

### Reemplazo controlado

Un reemplazo crea una nueva fila en `desembarque_files`:

```text
Documento viejo   is_active=0
       ↑
       │ replaces_file_id
Documento nuevo   is_active=1
```

El archivo físico anterior permanece intacto y descargable desde el expediente.

### Integridad

Los documentos nuevos guardan SHA-256 después de almacenarse. El endpoint genérico de descarga recalcula el SHA-256 antes de entregar un archivo que tenga una huella registrada. Si hay una diferencia responde HTTP 409 y no entrega el archivo.

`document_verify.php` permite verificar explícitamente la integridad desde el modal Detalles.

### Privacidad del Excel

El Excel fuente:

- se marca `purpose=source_excel`;
- se excluye de la API de listado de adjuntos;
- no aparece en Documentos;
- el endpoint de descarga devuelve 404 incluso para administradores;
- no se puede eliminar mediante los endpoints normales de adjuntos.

## Endpoints nuevos

- `api/desembarques/files/document_upload.php`
- `api/desembarques/files/document_replace.php`
- `api/desembarques/files/document_verify.php`
- helper privado `api/desembarques/files/_documents.php`

## Archivos UI

- `public/aviso-expediente.php`
- `public/assets/js/aviso-expediente-documents.js`
- `public/assets/css/aviso-expediente.css`

El Service Worker pasa a `v13`.

## Instalación local

1. Reemplazar los archivos del parche conservando rutas.
2. Copiar la migración al contenedor:

```powershell
docker compose cp `
  .\database\migrations\20260818_aviso_documents_phase5b.sql `
  db:/tmp/20260818_aviso_documents_phase5b.sql
```

3. Ejecutarla:

```powershell
docker compose exec db mysql -uroot -p desembarques
```

Dentro de MySQL:

```sql
SOURCE /tmp/20260818_aviso_documents_phase5b.sql;
```

4. Reiniciar solamente `app`:

```powershell
docker compose restart app
```

5. En el navegador: `Ctrl + Shift + R`.

No usar `docker compose down -v`.

## Prueba de aceptación recomendada

1. Abrir Expediente → Documentos.
2. Subir un PDF como `Pedimento`, con descripción y fecha.
3. Subir un DOCX como `Oficio`.
4. Confirmar que ambos aparecen `VIGENTE`.
5. Abrir Detalles y verificar SHA-256.
6. Descargar el PDF y DOCX.
7. Reemplazar el PDF por una versión corregida.
8. Confirmar:
   - nuevo PDF = `VIGENTE`;
   - anterior = `REEMPLAZADO`;
   - ambos siguen descargables;
   - la tabla muestra la relación de reemplazo.
9. Recargar el expediente y confirmar persistencia.
10. Revisar Historial: debe registrar alta y reemplazo.
11. Cambiar el estado operativo del desembarque y volver a subir otro documento: la carga debe seguir permitida.
12. Confirmar que Fotos no aparecen en Documentos y que el Excel fuente no aparece en ninguna lista de documentos/adjuntos ni puede descargarse.

## Fuera de alcance

Fase 5B no crea todavía el state machine propio del Aviso (`Borrador`, `Emitido`, `Presentado`, `Reemplazado`, `Cancelado`). Eso corresponde a Fase 5C. La regla ya queda congelada: **ningún estado del Aviso bloqueará la incorporación o reemplazo documental**.
