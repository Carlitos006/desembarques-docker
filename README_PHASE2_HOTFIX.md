# Desembarques · Fase 2 Hotfix de esquema

Este parche corrige la incompatibilidad detectada después de ejecutar parcialmente `20260818_aviso_profiles_phase2.sql` sobre la BD local real.

## Causa

La BD ya utiliza `desembarque_aviso_details.aviso_profile_id` y la tabla `aviso_profiles` provenía de un contrato anterior con la columna `campo`. La primera migración de Fase 2 intentaba crear `profile_id`, requería `rig_field` y `updated_by`, y utilizaba una variante de `ADD COLUMN IF NOT EXISTS` que no fue aceptada en la ejecución observada.

## Contrato definitivo

- BD: `desembarque_aviso_details.aviso_profile_id`.
- API/JavaScript: se mantiene el nombre lógico `profile_id`.
- `aviso_profiles.campo` se migra a `rig_field`.
- `aviso_profiles.client_id` pasa a nullable para permitir perfiles globales.
- No se introduce `updated_by`; no es necesario para el flujo actual.
- No se elimina ninguna tabla ni registro.

## Aplicación

1. Sustituir los archivos del parche conservando sus rutas.
2. Copiar la migración al contenedor:

```powershell
docker compose cp `
  .\database\migrations\20260818_aviso_profiles_phase2_hotfix.sql `
  db:/tmp/20260818_aviso_profiles_phase2_hotfix.sql
```

3. Entrar a MySQL:

```powershell
docker compose exec db mysql -uroot -p desembarques
```

4. Ejecutar:

```sql
SOURCE /tmp/20260818_aviso_profiles_phase2_hotfix.sql;
```

5. Verificar:

```sql
DESCRIBE aviso_profiles;
DESCRIBE desembarque_aviso_details;
SELECT id, name, client_id, rig_name, rig_imo, rig_field, rig_area, is_default FROM aviso_profiles;
```

6. Reiniciar solamente `app` y refrescar el navegador:

```powershell
docker compose restart app
```

El Service Worker cambia de `v7` a `v8`.
