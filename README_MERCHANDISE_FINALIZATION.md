# Fase 5F — Finalización / salida de mercancías

Esta fase agrega al Expediente del Aviso un control de ciclo físico de mercancía sin modificar ni eliminar los renglones originales del Aviso.

## Regla funcional

Cada mercancía conserva siempre su renglón original y su cantidad original. Las salidas se registran como eventos independientes.

Estados derivados:

- **ALMACENADA**: no existe cantidad finalizada.
- **PARCIAL**: una parte ya salió y todavía existe saldo en almacén.
- **EXPORTADA**: la cantidad acumulada finalizada alcanzó la cantidad original.

Una misma mercancía puede participar en varias finalizaciones hasta agotar su saldo.

## Información capturada en `Finalizar`

- Fecha de salida / exportación.
- PEDIMENTO R1 PARA AGREGAR.
- PEDIMENTO RETORNO PARCIAL H1.
- MERCANCÍA EN PEDIMENTO H1.
- PEDIMENTO R1 DESAGREGADO.
- PEDIMENTO A3.
- Observaciones opcionales.
- Una o varias mercancías.
- Cantidad que sale por cada mercancía seleccionada.

Los cinco campos aduanales se muestran exactamente con la nomenclatura solicitada. Se requiere al menos uno por evento; los demás pueden quedar vacíos cuando no apliquen.

## Modelo de datos

Se agregan dos tablas:

- `desembarque_item_finalizations`: encabezado de cada salida/finalización.
- `desembarque_item_finalization_lines`: mercancías y cantidades involucradas en cada evento.

El estado no se almacena como una bandera mutable en `desembarque_aviso_items`; se deriva del historial acumulado. Esto conserva trazabilidad y permite salidas parciales.

## Hardening

`api/desembarques/aviso/save.php` impide:

- eliminar del Aviso una mercancía que ya tenga salidas registradas;
- reducir su cantidad original por debajo de la cantidad ya finalizada.

La API de finalización vuelve a calcular el saldo dentro de una transacción y bloquea el renglón antes de insertar. Esto evita sobre-exportación por dos solicitudes concurrentes.

## Auditoría

Cada evento genera `audit_logs.entity_type = aviso_merchandise_finalization` y aparece en la pestaña **Historial** del expediente.

## Instalación

1. Copiar el parche conservando rutas.
2. Aplicar la migración antes de abrir nuevamente el Expediente:

```powershell
docker compose cp `
  .\database\migrations\20260820_merchandise_finalization.sql `
  db:/tmp/20260820_merchandise_finalization.sql

docker compose exec db mysql -uroot -p desembarques
```

Dentro de MySQL:

```sql
SOURCE /tmp/20260820_merchandise_finalization.sql;
```

3. Salir y reiniciar app:

```powershell
docker compose restart app
```

4. Hacer `Ctrl + Shift + R` en Chrome. El Service Worker cambia de `v19` a `v20`.

No requiere rebuild de Docker ni cambios de Composer.

## Prueba recomendada

Con una mercancía de cantidad 7:

1. Finalizar 3 piezas.
2. Verificar estado **PARCIAL**, Salida = 3 y En almacén = 4.
3. Finalizar las 4 restantes en un segundo evento.
4. Verificar estado **EXPORTADA**, Salida = 7 y En almacén = 0.
5. Confirmar que el renglón sigue visible.
6. Revisar ambos eventos en Historial de finalizaciones y en la pestaña Historial general.

También probar seleccionar varias mercancías en una sola finalización.
