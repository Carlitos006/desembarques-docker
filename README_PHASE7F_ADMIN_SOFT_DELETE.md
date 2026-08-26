# Fase 7F — Administrative Soft Delete

Esta fase permite retirar Avisos creados por error sin destruir el expediente documental. La baja vive exclusivamente en la tabla padre `desembarques`; no modifica `record_scope` y no elimina PDFs, fotos, documentos, mercancías, pedimentos, finalizaciones, Alcances, acuses ni auditoría.

## Contrato de seguridad

- Sólo el rol `admin` ve y ejecuta **Eliminar aviso** y **Restaurar aviso**.
- Ambos endpoints son `POST`, validan sesión, rol y CSRF del lado servidor.
- La baja exige motivo y la confirmación exacta `ELIMINAR <notice_number>`.
- La restauración exige motivo y `RESTAURAR <notice_number>`.
- La escritura y su auditoría se confirman en la misma transacción. Si la auditoría falla, la operación se revierte.
- No existe eliminación física de `desembarques` en esta fase.
- Un Aviso eliminado sólo puede abrirse por un admin desde `aviso-papelera.php`, usando la vista explícita de sólo lectura.

## SQL exacto para producción

Ejecuta completo, sin recortar, el archivo:

`database/migrations/20260824_administrative_soft_delete_phase7f.sql`

La migración no consulta `information_schema`, por lo que funciona con usuarios restringidos de hosting compartido. Incluye comprobaciones `SHOW` antes y después del cambio y crea exactamente:

```sql
deleted_at DATETIME NULL
deleted_by INT UNSIGNED NULL
delete_reason VARCHAR(500) NULL
INDEX idx_desembarques_deleted_at (deleted_at)
FOREIGN KEY deleted_by REFERENCES users(id) ON DELETE SET NULL
```

No borra ni actualiza filas existentes. Ejecuta el `ALTER TABLE` una sola vez cuando la comprobación previa no muestre ninguna de las tres columnas. Si la comprobación previa devuelve una instalación parcial, detente y aplica únicamente los componentes faltantes; no vuelvas a ejecutar el bloque completo.

Orden recomendado en producción:

1. Respaldar la base de datos.
2. Ejecutar primero las tres consultas de **COMPROBACIÓN PREVIA** de la migración.
3. Si no existen las columnas, índice ni FK 7F, ejecutar completo el `ALTER TABLE` y las comprobaciones posteriores.
4. Subir los archivos del parche conservando sus rutas.
5. Reiniciar PHP/OPcache si el hosting lo utiliza.
6. Forzar recarga del navegador; el service worker cambia de `v30` a `v33` y el script administrativo usa `?v=33` para evitar configuraciones desfasadas.

## Cobertura funcional

Los registros con `deleted_at IS NOT NULL` quedan fuera de:

- `DashboardAvisoMetrics`, KPIs, series, rankings, filtros y recientes;
- Reportes, listados y exportaciones que consumen `api/desembarques/list.php`;
- Control Tower y Analytics;
- Client Portal y confirmación de hitos;
- API v1 y APIs operativas del expediente;
- observaciones no leídas, búsquedas y selectores operativos;
- descargas directas para usuarios no administradores.

La Papelera es la única consulta normal con `deleted_at IS NOT NULL`. El importador histórico sí consulta ambos universos para conservar la detección de duplicados. Si el duplicado está eliminado, un `admin` puede restaurarlo. Cuando el registro eliminado era sólo una prueba y el PDF correcto es distinto, tanto `admin` como `usuario` pueden elegir **Importar como aviso nuevo (conservar eliminado)**; esta excepción exige motivo, vuelve a comprobar el duplicado dentro de la transacción y queda auditada. El rol `cliente` no tiene acceso al importador ni a sus endpoints. Un PDF idéntico por SHA-256 nunca puede duplicarse.

Las consultas de siguiente referencia incluyen eliminados de forma intencional: una baja lógica nunca permite reutilizar una identidad documental.

## Validación local

Después de aplicar la migración en la BD local:

```powershell
php tools/validate_phase7f_soft_delete.php --require-db
```

Debe terminar con:

```text
RESULTADO GLOBAL: PASS
```

Validación funcional recomendada:

1. Como `usuario` y `cliente`, confirma que no exista el botón y que los endpoints admin respondan 403.
2. Como `admin`, elimina un Aviso de prueba con motivo y confirmación exacta.
3. Comprueba que desaparezca de Dashboard, Reportes, exportaciones, Control Tower, Portal y API v1.
4. Prueba su URL normal: debe responder **Expediente no disponible**; desde Papelera, **Ver** debe abrirlo en sólo lectura.
5. Confirma en BD que las filas hijas y archivos siguen presentes y que `audit_logs.action = 'soft_delete'` existe.
6. Como `usuario`, intenta importar el mismo número: debe aparecer **Eliminado · restaurar** y la opción **Importar como aviso nuevo (conservar eliminado)**, pero no el acceso a Papelera.
7. Selecciona la excepción sin motivo: debe impedir guardar la revisión.
8. Agrega un motivo y confirma la importación con un PDF distinto: debe crear un `id` nuevo y conservar el anterior en Papelera.
9. Comprueba en `audit_logs` que la revisión y la importación registran `import_new_override_deleted`, el `id` eliminado y el motivo.
10. Como `cliente`, confirma que la página y los endpoints de importación respondan sin acceso.
11. Como `admin`, comprueba por separado que **Restaurar** conserva el `id` original y registra `audit_logs.action = 'restore'`.

Consultas de apoyo:

```sql
SELECT id, referencia, deleted_at, deleted_by, delete_reason
FROM desembarques
WHERE id = <ID_DE_PRUEBA>;

SELECT action, entity_type, entity_id, payload, created_at
FROM audit_logs
WHERE entity_type = 'desembarque'
  AND entity_id = '<ID_DE_PRUEBA>'
  AND action IN ('soft_delete', 'restore')
ORDER BY id DESC;
```

## Reversión operativa

Para revertir una baja no ejecutes SQL manual: usa **Papelera administrativa → Restaurar** para conservar la auditoría. No se incluye una migración destructiva de rollback; las columnas pueden permanecer instaladas aunque se retire temporalmente la interfaz.
