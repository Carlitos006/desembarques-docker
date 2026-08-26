# Fase 4 — Historial inmutable de emisiones PDF

Esta fase añade versionado histórico al flujo del Aviso de Desembarque.

## Objetivo

Cada vez que un usuario interno genera un Aviso PDF correctamente:

1. Se guardan primero los datos actuales del aviso en MySQL.
2. El PDF se genera en el navegador con `pdf-lib`.
3. Antes de cerrar el modal, el PDF se envía al backend y se archiva en almacenamiento privado.
4. El backend asigna un número secuencial por desembarque (`v001`, `v002`, ...).
5. Se calcula y guarda SHA-256 del PDF.
6. Se toma en servidor un snapshot exacto de desembarque, aviso, perfil, mercancías y relaciones del anexo fotográfico.
7. Se calcula SHA-256 del snapshot.
8. Sólo después de archivar correctamente, el navegador descarga el PDF recién generado.

Si el archivado falla, el PDF sigue descargándose para no perder el trabajo, pero el modal permanece abierto y muestra la advertencia.

## Archivos nuevos

- `database/migrations/20260818_aviso_versions_phase4.sql`
- `api/desembarques/aviso/versions.php`
- `api/desembarques/aviso/version_download.php`

## Archivos modificados

- `public/reportes.php`
- `public/assets/js/reports.js`
- `public/assets/js/aviso-pdf.js`
- `public/service-worker.js`

El Service Worker cambia de `v9` a `v10`.

## Tabla nueva

`desembarque_aviso_versions`

Campos principales:

- `desembarque_id`
- `version_no`
- `aviso_profile_id`
- `document_code`
- `notice_number`
- `source_excel_sha256`
- `item_count`
- `photo_count`
- `page_count`
- `original_name`
- `stored_name`
- `size`
- `pdf_sha256`
- `snapshot_json`
- `snapshot_sha256`
- `generated_by`
- `generated_at`

No existe endpoint para editar o eliminar versiones históricas. La descarga vuelve a calcular el SHA-256 del archivo físico y devuelve HTTP 409 si la integridad no coincide con la huella registrada.

## Instalación local Docker

Copiar la migración al contenedor:

```powershell
docker compose cp `
  .\database\migrations\20260818_aviso_versions_phase4.sql `
  db:/tmp/20260818_aviso_versions_phase4.sql
```

Entrar a MySQL:

```powershell
docker compose exec db mysql -uroot -p desembarques
```

Ejecutar:

```sql
SOURCE /tmp/20260818_aviso_versions_phase4.sql;
```

Validar:

```sql
SHOW CREATE TABLE desembarque_aviso_versions\G

SELECT
    desembarque_id,
    version_no,
    document_code,
    notice_number,
    item_count,
    photo_count,
    page_count,
    size,
    LEFT(pdf_sha256, 16) AS pdf_hash,
    LEFT(snapshot_sha256, 16) AS snapshot_hash,
    generated_by,
    generated_at
FROM desembarque_aviso_versions
ORDER BY id DESC;
```

No requiere `docker compose up --build` porque no cambian Dockerfile, Composer ni `php.ini`.

```powershell
docker compose restart app
```

Después usar `Ctrl + Shift + R` en el navegador.

## Prueba de aceptación recomendada

1. Abrir **Reportes → Generar aviso** del desembarque de prueba.
2. Confirmar que la sección **Historial de emisiones PDF** indique `0` si todavía no existen versiones.
3. Generar el PDF.
4. Verificar que el PDF se descargue normalmente.
5. Volver a abrir **Generar aviso**.
6. Confirmar que aparezca **Versión 1** con fecha, usuario, páginas, mercancías, fotografías, tamaño y SHA-256.
7. Descargar la Versión 1 desde el historial y verificar que abra correctamente.
8. Cambiar un dato controlado, por ejemplo el área del Rig, guardar/generar nuevamente.
9. Confirmar que aparezcan **Versión 2** y **Versión 1** y que ambas puedan descargarse independientemente.
10. Confirmar en MySQL que existen dos filas distintas y que sus hashes reflejan el contenido emitido.

## Nota sobre tamaño

El Docker actual configura `upload_max_filesize = 12M`. Por ello el archivado histórico admite PDF de hasta 12 MiB. El generador ya comprime/redimensiona fotografías antes de incrustarlas. Si en el futuro se requieren avisos de más de 12 MiB, habrá que aumentar `upload_max_filesize` y recrear la imagen del contenedor `app`.

## Alcance de la inmutabilidad

La aplicación no ofrece acciones de actualización o borrado de versiones. Cada emisión crea una fila nueva y un archivo privado nuevo. El SHA-256 permite detectar alteraciones del archivo almacenado durante la descarga. Un administrador con acceso directo a MySQL o al filesystem sigue teniendo capacidad operacional sobre la infraestructura; esto no pretende ser almacenamiento WORM regulatorio.
