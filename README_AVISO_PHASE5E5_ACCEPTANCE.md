# Fase 5E5 — Acceptance & Hardening de importación histórica

## Objetivo

Cerrar la importación histórica antes del Dashboard Ejecutivo con validaciones automáticas que impidan que un lote aparentemente correcto deje expedientes incompletos o PDFs alterados.

Esta fase **no cambia el esquema MySQL** y **no requiere migración SQL**.

## Qué agrega

### 1. Compuerta de integridad dentro del commit

`api/desembarques/aviso/import/commit.php` ejecuta una validación 5E5 dentro de la misma transacción antes de confirmar cada aviso. Si un expediente nuevo falla una condición crítica, la transacción completa se revierte y los PDFs copiados se eliminan del storage definitivo.

Se valida, entre otros puntos:

- expediente y `desembarque_aviso_details` existentes;
- `source_type = historical_import` para expedientes históricos nuevos;
- relación con `aviso_import_rows`;
- versión PDF histórica existente y ligada a la fila de staging;
- archivo físico existente;
- tamaño físico = tamaño registrado;
- SHA-256 físico = SHA-256 registrado;
- SHA-256 del PDF no duplicado en `desembarque_aviso_versions`;
- snapshot presente y con SHA-256 válido;
- `COUNT(desembarque_aviso_items) = version.item_count`;
- `SUM(cantidad) = version.piece_count`;
- pedimentos y manifiesto estructurados;
- conteos del snapshot consistentes con la BD;
- historial documental con estado final igual al estado actual;
- número de aviso único por cliente.

Para `link_version`, se validan sólo las invariantes que corresponden a la nueva versión histórica, sin exigir que el expediente destino sea de origen histórico.

### 2. Validación post-importación por lote

Nuevo endpoint:

`GET /api/desembarques/aviso/import/validate.php?batch=<public_id>`

La pantalla **Importar históricos** incorpora el botón **Validar lote 5E5** y un panel con:

- PASS / REVISAR / FAIL por aviso;
- cantidad total de checks;
- verificaciones generales del lote;
- detalle de cada verificación;
- acceso directo al expediente resultante.

Al terminar un commit desde la interfaz, la validación se ejecuta automáticamente.

### 3. Hardening de archivos del lote

La carga rechaza antes del análisis:

- el mismo archivo exacto repetido en el mismo lote (SHA-256 duplicado);
- más de un PDF candidato para el mismo número de aviso;
- más de un Word candidato para el mismo número de aviso.

Esto evita emparejamientos ambiguos y doble conteo accidental.

### 4. Diagnóstico CLI

Herramienta nueva:

```bash
php tools/validate_historical_imports.php --batch=<public_id>
php tools/validate_historical_imports.php --batch=<public_id> --json
php tools/validate_historical_imports.php --all
```

El proceso devuelve exit code `0` cuando no hay fallas de integridad y `1` cuando encuentra al menos una.

## Instalación

1. Copiar el parche respetando las rutas.
2. No ejecutar ninguna migración SQL.
3. Reiniciar `app`:

```powershell
docker compose restart app
```

4. Refrescar con `Ctrl + Shift + R`.

Service Worker: **v19**.

> Si todavía no habías aplicado el hotfix DOC/antiword de 5E3.1, primero debes tener esa versión del contenedor. 5E5 no modifica Dockerfile.

## Acceptance recomendado

No aumentamos el límite de 20 archivos por lote deliberadamente. Para probar 15–30 parejas PDF+Word, usa 2–3 lotes de hasta 10 avisos cada uno. Esto mantiene memoria, `post_max_size` y tiempos de análisis bajo control.

### Matriz sugerida

Incluye en los lotes:

- avisos con 1, 2 y múltiples renglones;
- cantidades de piezas distintas al número de renglones;
- uno y múltiples pedimentos;
- PDF escaneado + `.doc`;
- PDF textual;
- documentos de 2, 3 y 4+ páginas;
- seriales largos;
- una carga deliberadamente duplicada para comprobar rechazo;
- un aviso ya existente para comprobar la ruta de duplicado/omitir/vincular.

### Criterio de cierre 5E5

Un lote importado se considera apto para alimentar el Dashboard cuando:

- la validación muestra **0 FAIL**;
- cada histórico nuevo tiene `v001 historical_import`;
- todos los hashes físicos coinciden;
- no hay SHA-256 duplicados;
- no hay más de un aviso **activo** por `cliente + notice_number`; un expediente de prueba eliminado puede conservarse únicamente mediante la excepción administrativa motivada y auditada;
- `item_count` coincide con renglones reales;
- `piece_count` coincide con `SUM(cantidad)`;
- existe al menos un pedimento y un manifiesto;
- existe historial documental coherente;
- la fila de staging apunta al expediente y a la versión correctos.

## Sin cambios de negocio

5E5 no cambia:

- estados documentales;
- generación PDF normal;
- expedientes;
- documentos de 5B;
- fotos;
- lógica Histórico/Sistema de 5E4.

Es una fase de **acceptance, fail-closed e integridad**, previa a Fase 6A Dashboard Ejecutivo.
