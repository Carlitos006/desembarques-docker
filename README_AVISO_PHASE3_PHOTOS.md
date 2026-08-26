# Desembarques — Fase 3: Anexo fotográfico del Aviso

## Objetivo

Esta fase completa el flujo del Aviso de Desembarque con un anexo fotográfico estructurado, reutilizando los archivos privados del expediente y la plantilla PDF corporativa.

El flujo queda así:

1. El desembarque ya contiene sus datos Excel y mercancías en BD.
2. Desde **Reportes → Generar aviso**, el usuario puede:
   - subir fotografías nuevas; o
   - agregar imágenes que ya estén adjuntas al desembarque.
3. Cada fotografía puede quedar:
   - como fotografía general; o
   - relacionada con una mercancía específica.
4. El usuario puede editar el pie de foto y ordenar las fotografías.
5. Al generar el PDF, se añade automáticamente el bloque **MANIFIESTO / MERCANCIAS / IMAGENES** sobre la plantilla oficial.
6. Si el anexo ocupa varias hojas, se crean páginas de continuación automáticamente y el cierre/documentación continúa en la última página cuando existe espacio.

## Archivos principales

### Backend

- `api/desembarques/aviso/load.php`
  - devuelve `details`, `items` y ahora también `photos`.
- `api/desembarques/aviso/save.php`
  - persiste orden, caption y relación foto ↔ mercancía;
  - reconcilia mercancías preservando sus IDs para no romper asociaciones fotográficas.
- `api/desembarques/aviso/image.php`
  - endpoint autenticado para previsualizar imágenes privadas del expediente.
- `api/desembarques/files/delete.php`
  - limpia la asociación del anexo cuando se elimina un archivo.

### Frontend

- `public/reportes.php`
  - nueva sección **Anexo fotográfico** dentro del modal Generar aviso.
- `public/assets/js/reports.js`
  - carga/subida de fotos;
  - selección de imágenes existentes;
  - relación con mercancías;
  - captions;
  - reordenamiento;
  - persistencia del anexo.
- `public/assets/js/aviso-pdf.js`
  - genera las páginas del anexo sobre la plantilla PDF original.
- `public/service-worker.js`
  - cache version `v9`.

### Base de datos

- `database/migrations/20260818_aviso_photos_phase3.sql`
  - asegura la tabla `desembarque_aviso_images`;
  - añade índices/FKs faltantes de forma defensiva;
  - añade índice `(desembarque_id, source_row)` para reconciliar mercancías sin destruir sus IDs.

## Aplicación en Docker

No se cambió `Dockerfile`, `compose.yml`, `composer.json` ni `composer.lock`, por lo que no se requiere rebuild.

### 1. Copiar la migración al contenedor

```powershell
docker compose cp `
  .\database\migrations\20260818_aviso_photos_phase3.sql `
  db:/tmp/20260818_aviso_photos_phase3.sql
```

### 2. Ejecutar la migración

```powershell
docker compose exec db mysql -uroot -p desembarques
```

Dentro de MySQL:

```sql
SOURCE /tmp/20260818_aviso_photos_phase3.sql;
```

La migración no elimina desembarques, mercancías ni archivos.

### 3. Reiniciar la aplicación

```powershell
exit
docker compose restart app
```

Después, en Chrome, hacer `Ctrl + Shift + R` para renovar los assets del Service Worker `v9`.

## Prueba funcional recomendada

En **Reportes → Generar aviso**:

1. Confirma que el Excel y las mercancías del desembarque se cargan desde BD.
2. En **Anexo fotográfico**, carga varias imágenes JPG/PNG/GIF o agrega imágenes existentes.
3. Relaciona algunas fotografías con mercancías y deja otras como **General / sin asignar**.
4. Cambia el orden con **Subir/Bajar**.
5. Modifica uno o dos pies de foto.
6. Genera el PDF.
7. Comprueba que el anexo use columnas:
   - MANIFIESTO
   - MERCANCIAS
   - IMAGENES
8. Vuelve a abrir el modal y comprueba que orden, caption y relación con mercancía permanezcan guardados.

> Quitar una foto del anexo no borra el archivo del expediente. Sólo elimina su participación en el PDF.

## Consulta de validación

Sustituir `TU_DESEMBARQUE_ID`:

```sql
SELECT
    ai.id,
    ai.sort_order,
    ai.file_id,
    f.original_name,
    ai.aviso_item_id,
    i.source_row,
    i.descripcion,
    ai.caption
FROM desembarque_aviso_images ai
INNER JOIN desembarque_files f
    ON f.id = ai.file_id
LEFT JOIN desembarque_aviso_items i
    ON i.id = ai.aviso_item_id
WHERE ai.desembarque_id = TU_DESEMBARQUE_ID
ORDER BY ai.sort_order, ai.id;
```

## Formatos soportados

- JPG / JPEG
- PNG
- GIF (se incorpora como imagen estática al PDF)

El generador normaliza las imágenes a JPEG antes de incrustarlas en el PDF para evitar incompatibilidades y contener el tamaño del documento.

## Alcance de esta fase

Incluido:
- fotos privadas del expediente;
- selección/reutilización de adjuntos existentes;
- relación foto ↔ mercancía;
- captions;
- orden;
- persistencia en BD;
- generación automática del anexo PDF;
- multipágina;
- compatibilidad con avisos sin fotografías.

Pendiente para una fase posterior, si se desea:
- editor/crop manual de fotografías;
- rotación manual;
- portada independiente del anexo;
- historial/versionado de PDFs generados.
