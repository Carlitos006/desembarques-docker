# Hotfix de compatibilidad de hosting: `/lib` -> `/src`

La carpeta interna del importador historico fue renombrada de `lib/` a `src/` porque el servidor de produccion no permite subir una carpeta de proyecto llamada `lib`.

## Cambio de rutas

- `lib/HistoricalAvisoExtractor.php` -> `src/HistoricalAvisoExtractor.php`
- `lib/HistoricalAvisoParser.php` -> `src/HistoricalAvisoParser.php`

Se actualizaron las referencias en:

- `api/desembarques/aviso/import/_bootstrap.php`
- `tools/test_historical_word_doc.php`
- `tools/test_historical_aviso_parser.php`

`HistoricalAvisoExtractor.php` y `HistoricalAvisoParser.php` conservan sus nombres de clase y comportamiento; solo cambia su ubicacion fisica.

## Produccion

Subir la carpeta `src/` en lugar de `lib/`. No es necesario cambiar base de datos, Docker, Composer ni ejecutar migraciones.

El archivo `src/.htaccess` bloquea acceso HTTP directo a las clases si el hosting expone la raiz del proyecto.

## Local Docker

No requiere rebuild porque no cambian Dockerfile ni dependencias. Con bind mount basta con:

```powershell
docker compose restart app
```

Prueba rapida:

```powershell
docker compose exec app php -l /var/www/html/src/HistoricalAvisoExtractor.php
docker compose exec app php -l /var/www/html/src/HistoricalAvisoParser.php
```

Y para comprobar el importador historico:

```powershell
docker compose exec app php /var/www/html/tools/validate_historical_imports.php --all
```
