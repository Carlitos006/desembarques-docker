# Corrección de esquema: columnas pedimento, cipl y manifiesto

El código de `store.php`, `list.php` y `update.php` usa tres columnas de compatibilidad en la tabla `desembarques`, pero el `database/schema.sql` original no las creaba.

## Aplicar a una base existente

Desde PowerShell, en la raíz del proyecto:

```powershell
Get-Content .\database\migrations\20260720_add_desembarques_reference_columns.sql -Raw |
  docker compose exec -T db mysql -uroot -p$env:MYSQL_ROOT_PASSWORD desembarques
```

Si la variable no está cargada en PowerShell, usa el valor de `MYSQL_ROOT_PASSWORD` definido en `.env`:

```powershell
Get-Content .\database\migrations\20260720_add_desembarques_reference_columns.sql -Raw |
  docker compose exec -T db mysql -uroot -pdesembarques_root_dev_password desembarques
```

También puede importarse el SQL desde phpMyAdmin.

## Archivos incluidos

- `database/schema.sql`: corregido para instalaciones nuevas.
- `database/migrations/20260720_add_desembarques_reference_columns.sql`: migración idempotente para la base actual.
- `api/desembarques/store.php`: elimina la duplicación del detalle de error en la respuesta.
