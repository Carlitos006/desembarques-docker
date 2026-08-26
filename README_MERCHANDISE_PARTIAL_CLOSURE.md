# Cierre documental parcial de mercancías

Esta ampliación separa la **salida física** de la mercancía del **cierre documental** de sus pedimentos.

- **Cierre completo:** registra la salida y exige al menos un dato aduanal por cada mercancía.
- **Cierre parcial:** registra la salida física aunque todavía falten pedimentos. Exige una observación que identifique lo pendiente.
- **Completar pedimentos:** actualiza el mismo movimiento desde el historial. No crea otra salida y no modifica `quantity_exported`.
- Los roles internos `admin` y `usuario` pueden registrar y completar cierres. El rol `cliente` no tiene acceso a estas acciones.
- La anulación existente conserva su comportamiento: el evento permanece auditado y sus cantidades regresan al saldo disponible.

## Archivos de esta ampliación

Archivos de aplicación que deben actualizarse en producción:

- `api/desembarques/aviso/merchandise_finalize.php`
- `api/desembarques/aviso/merchandise_finalization_complete.php` (nuevo)
- `config/operational_i18n.php`
- `public/aviso-expediente.php`
- `public/assets/js/aviso-expediente-merchandise.js`
- `public/service-worker.js`

Archivos de despliegue y validación:

- `database/migrations/20260826_merchandise_partial_closure.sql` (nuevo)
- `tools/validate_merchandise_partial_closure.php` (nuevo)
- `tools/validate_full_i18n.php`
- `tools/validate_aviso_photo_delete.php`
- `tools/validate_phase7f_soft_delete.php`
- `README_FULL_I18N_ENGLISH.md`
- `README_MERCHANDISE_PARTIAL_CLOSURE.md` (nuevo)

## Orden de actualización en producción

1. Haz respaldo de la base de datos.
2. Ejecuta la migración SQL siguiente **una sola vez**.
3. Sube los archivos PHP, JS, configuración, README y service worker incluidos en el parche.
4. Abre el sitio nuevamente; el service worker `v43` retirará la caché anterior.

La migración no usa `information_schema`, por lo que funciona con la cuenta restringida que ya administra esta base de datos.

## SQL exacto para producción

```sql
ALTER TABLE desembarque_item_finalizations
    ADD COLUMN closure_status VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'complete' AFTER notes,
    ADD COLUMN closure_completed_at DATETIME NULL AFTER closure_status,
    ADD COLUMN closure_completed_by INT UNSIGNED NULL AFTER closure_completed_at,
    ADD KEY idx_item_finalizations_closure (desembarque_id, closure_status, voided_at),
    ADD KEY idx_item_finalizations_completed_by (closure_completed_by),
    ADD CONSTRAINT fk_item_finalizations_completed_by
        FOREIGN KEY (closure_completed_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE desembarque_item_finalizations
SET
    closure_status = 'complete',
    closure_completed_at = COALESCE(closure_completed_at, created_at),
    closure_completed_by = COALESCE(closure_completed_by, created_by)
WHERE closure_status = 'complete';

SHOW COLUMNS FROM desembarque_item_finalizations LIKE 'closure_status';
SHOW COLUMNS FROM desembarque_item_finalizations LIKE 'closure_completed_at';
SHOW COLUMNS FROM desembarque_item_finalizations LIKE 'closure_completed_by';
SHOW INDEX FROM desembarque_item_finalizations WHERE Key_name = 'idx_item_finalizations_closure';
```

El mismo contenido está en `database/migrations/20260826_merchandise_partial_closure.sql`.

## Validación local

```powershell
php tools/validate_merchandise_partial_closure.php
php -l api/desembarques/aviso/merchandise_finalize.php
php -l api/desembarques/aviso/merchandise_finalization_complete.php
php -l public/aviso-expediente.php
node --check public/assets/js/aviso-expediente-merchandise.js
```

Prueba funcional recomendada:

1. En un expediente con saldo disponible, abre **Finalizar mercancía**.
2. Selecciona **Cierre parcial — pedimentos pendientes**, deja los campos aduanales vacíos, captura una observación y guarda.
3. Comprueba que la cantidad salga del saldo una sola vez y que el historial muestre **CIERRE PARCIAL**.
4. Pulsa **Completar pedimentos**, captura al menos un dato aduanal por cada mercancía y guarda.
5. Comprueba que el historial cambie a **CIERRE COMPLETO**, que el saldo no vuelva a disminuir y que la acción aparezca en **Historial**.
6. Repite la prueba con idioma inglés y verifica los textos **Partial closure**, **Complete customs documentation** y **Complete closure**.
