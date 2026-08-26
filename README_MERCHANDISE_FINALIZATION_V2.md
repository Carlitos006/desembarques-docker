# Finalización de Mercancías V2

Esta actualización reemplaza el modelo V1 de datos aduanales generales por un modelo **por mercancía y por movimiento**.

## Reglas funcionales

- Una mercancía puede participar en tantas salidas como sea necesario hasta agotar su saldo.
- Una salida puede incluir una o varias mercancías.
- Cada mercancía seleccionada captura su propia cantidad de salida y sus propios campos:
  - PEDIMENTO R1 PARA AGREGAR
  - PEDIMENTO RETORNO PARCIAL H1
  - MERCANCÍA EN PEDIMENTO H1
  - PEDIMENTO R1 DESAGREGADO
  - PEDIMENTO A3
- `ALMACENADA`, `PARCIAL` y `EXPORTADA` se calculan a partir del saldo; no se capturan manualmente.
- La mercancía original nunca desaparece del Aviso.
- Los movimientos no se eliminan. Una corrección se realiza mediante **Anular movimiento**, con motivo obligatorio.
- Una anulación devuelve automáticamente sus cantidades al saldo disponible sin borrar el historial.

## Compatibilidad con V1

La migración conserva las columnas generales V1 en `desembarque_item_finalizations`, pero el código V2 ya no las escribe.

Si existen movimientos V1, sus cinco datos aduanales se copian a cada línea correspondiente en `desembarque_item_finalization_lines` para conservar lo ya capturado.

## Archivos del parche

- `database/migrations/20260820_merchandise_finalization_v2.sql`
- `api/desembarques/aviso/merchandise_finalize.php`
- `api/desembarques/aviso/merchandise_finalization_void.php`
- `api/desembarques/aviso/save.php`
- `public/aviso-expediente.php`
- `public/assets/js/aviso-expediente-merchandise.js`
- `public/assets/css/aviso-expediente.css`
- `public/service-worker.js`

## Instalación

1. Copia el parche sobre el proyecto conservando las rutas.
2. Aplica **únicamente** la migración V2:

```powershell
docker compose cp `
  .\database\migrations\20260820_merchandise_finalization_v2.sql `
  db:/tmp/20260820_merchandise_finalization_v2.sql

docker compose exec db mysql -uroot -p desembarques
```

En MySQL:

```sql
SOURCE /tmp/20260820_merchandise_finalization_v2.sql;
```

3. Sal de MySQL y reinicia la aplicación:

```powershell
docker compose restart app
```

No se requiere rebuild de Docker.

4. Haz `Ctrl + Shift + R`. El Service Worker sube a `v23`.

## Prueba recomendada A — salidas parciales sucesivas

Usa una mercancía con 6 piezas:

- Salida 1: 2 piezas + datos aduanales A.
- Salida 2: 1 pieza + datos aduanales B.
- Salida 3: 3 piezas + datos aduanales C.

Esperado:

- tras salida 1: `2 salida / 4 almacén / PARCIAL`
- tras salida 2: `3 salida / 3 almacén / PARCIAL`
- tras salida 3: `6 salida / 0 almacén / EXPORTADA`

Los tres movimientos deben permanecer visibles en Historial.

## Prueba recomendada B — varias mercancías en un mismo evento

Selecciona dos mercancías en una sola salida y captura datos aduanales diferentes para cada una.

Esperado: un solo evento con dos líneas independientes; cada línea debe conservar sus cinco campos propios.

## Prueba recomendada C — anulación

Anula una de las salidas parciales indicando motivo.

Esperado:

- el evento queda visible como `ANULADA`;
- las piezas regresan al saldo disponible;
- el evento no se elimina;
- aparece el motivo, usuario y fecha de anulación;
- se registra auditoría.

## Consultas de verificación

```sql
SELECT
    f.id AS movimiento,
    f.export_date,
    f.voided_at,
    f.void_reason,
    l.aviso_item_id,
    l.quantity_exported,
    l.pedimento_r1_agregar,
    l.pedimento_retorno_parcial_h1,
    l.mercancia_pedimento_h1,
    l.pedimento_r1_desagregado,
    l.pedimento_a3
FROM desembarque_item_finalizations f
INNER JOIN desembarque_item_finalization_lines l
    ON l.finalization_id = f.id
WHERE f.desembarque_id = TU_ID
ORDER BY f.id, l.id;
```

Saldo vigente por mercancía:

```sql
SELECT
    i.id,
    i.descripcion,
    i.cantidad AS original,
    COALESCE(SUM(CASE WHEN f.voided_at IS NULL THEN l.quantity_exported ELSE 0 END), 0) AS salida,
    i.cantidad - COALESCE(SUM(CASE WHEN f.voided_at IS NULL THEN l.quantity_exported ELSE 0 END), 0) AS almacen
FROM desembarque_aviso_items i
LEFT JOIN desembarque_item_finalization_lines l
    ON l.aviso_item_id = i.id
LEFT JOIN desembarque_item_finalizations f
    ON f.id = l.finalization_id
WHERE i.desembarque_id = TU_ID
GROUP BY i.id, i.descripcion, i.cantidad
ORDER BY i.sort_order, i.id;
```
