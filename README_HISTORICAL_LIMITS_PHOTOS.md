# Hardening — importación histórica 250/300 MB + fotos nativas

## 1. Límites de importación histórica

Se centralizó el contrato en `config/historical_import.php`:

- 20 archivos por lote
- 250 MB máximo por archivo
- 300 MB máximo por lote

Lo consumen tanto PHP como JavaScript.

El backend `api/desembarques/aviso/import/upload.php` ahora valida también el tamaño TOTAL del lote; antes sólo validaba el tamaño individual.

### Docker local

`docker/php.ini` queda con margen por encima del contrato de aplicación:

- `upload_max_filesize = 300M`
- `post_max_size = 384M`
- `max_file_uploads = 20`
- `max_input_time = 600`

Como cambia `docker/php.ini`, reconstruye `app`:

```powershell
docker compose up -d --build --force-recreate app
```

Verifica:

```powershell
docker compose exec app php -i | Select-String "upload_max_filesize|post_max_size|max_file_uploads|max_input_time"
```

### Producción

El hosting también debe permitir al menos:

- `upload_max_filesize >= 300M`
- `post_max_size >= 384M`
- `max_file_uploads >= 20`

El código de la aplicación no puede superar un límite inferior impuesto por PHP/hosting.

## 2. Fotos en expedientes históricos

No requiere migración SQL. El esquema actual ya tiene:

- `desembarque_files` (`purpose = photo`)
- `desembarque_aviso_images`
- FK opcional a `desembarque_aviso_items`

Se agrega en `aviso-expediente.php > Fotos` el botón **Añadir fotos** para usuarios internos, tanto en avisos históricos como de sistema.

La carga permite:

- JPG / JPEG
- PNG
- GIF
- WEBP
- hasta 20 fotos por operación
- hasta 25 MB por foto
- pie de foto individual
- asociación opcional a una mercancía

El PDF histórico original permanece inmutable. Las imágenes extraídas del Word se almacenan como fotografías nativas independientes y aparecen en la misma galería usada por avisos creados por sistema.

## 3. Archivos principales

- `config/historical_import.php`
- `api/desembarques/aviso/import/_bootstrap.php`
- `api/desembarques/aviso/import/upload.php`
- `public/aviso-importar.php`
- `public/assets/js/aviso-import.js`
- `docker/php.ini`
- `api/desembarques/aviso/photo_upload.php`
- `api/desembarques/aviso/image.php`
- `config/files.php`
- `public/aviso-expediente.php`
- `public/assets/js/aviso-expediente-photos.js`
- `public/service-worker.js`
- `tools/validate_historical_photos_limits.php`

## 4. Validación

```powershell
docker compose exec app php /var/www/html/tools/validate_historical_photos_limits.php
```

Debe terminar en:

```text
RESULTADO GLOBAL: PASS
```

## 5. Base de datos

No ejecutar SQL nuevo para esta mejora. El dump revisado ya contiene las estructuras necesarias para fotografías.
