# Fase 5E4 — UX histórica de Avisos

## Objetivo

Distinguir de forma explícita y estable los Avisos creados dentro del sistema de los Avisos históricos importados, sin dividir el expediente en dos flujos funcionales distintos.

La fuente autoritativa del origen del Aviso es:

- `desembarque_aviso_details.source_type = historical_import` → **HISTÓRICO**.
- cualquier otro valor / registro legacy sin `source_type` → **SISTEMA**.

El origen pertenece al expediente y no cambia aunque posteriormente se genere una nueva versión PDF desde el sistema.

## Cambios incluidos

### Reportes

Se incorpora el filtro **Origen del aviso**:

- Todos los orígenes.
- Generados por sistema.
- Históricos importados.

`api/desembarques/list.php` aplica el filtro server-side y devuelve además:

- `aviso_source_type`
- `aviso_origin` (`system` / `historical`)
- `is_historical_aviso`
- `aviso_status`
- `aviso_notice_number`
- `aviso_document_code`

Cada fila muestra un badge **SISTEMA** o **HISTÓRICO** dentro de la columna Aviso. El origen también se incluye en exportaciones PDF/Excel de Reportes.

### Expediente

El encabezado siempre muestra el origen del expediente.

En Resumen se agrega una tarjeta **Origen del aviso**:

- HISTÓRICO: indica que el registro proviene de un Aviso anterior al sistema y ofrece descarga directa del PDF original histórico.
- SISTEMA: indica que el Aviso se creó y gestiona directamente en Desembarques.

### PDFs emitidos

Cada versión queda identificada por su procedencia:

- `historical_import` → **ORIGINAL HISTÓRICO** y fecha etiquetada como `Importado`.
- otras versiones → **GENERADO POR SISTEMA** y fecha etiquetada como `Emitido`.

Esto permite que un Aviso histórico tenga, por ejemplo:

- v001 — ORIGINAL HISTÓRICO
- v002 — GENERADO POR SISTEMA

sin perder la procedencia original del expediente.

### Fotos históricas

Los Avisos históricos no simulan archivos JPG/PNG que no existen. Si el Aviso cuenta con PDF histórico original, la pestaña Fotos muestra un bloque **ANEXO FOTOGRÁFICO HISTÓRICO** con acceso al PDF original.

Las imágenes incorporadas en el escaneo/documento histórico se consideran parte del PDF original y no fotografías nativas independientes. Cualquier fotografía JPG/PNG añadida posteriormente al expediente se muestra de forma separada debajo.

Cuando un histórico no tiene fotografías nativas, el indicador superior muestra `PDF` / `Anexo fotográfico histórico` en lugar de inducir a interpretar `0` como ausencia de evidencia fotográfica en el documento original.

### Historial

El timeline ahora incorpora los eventos `historical_aviso_import` y los muestra como **Aviso histórico importado**, incluyendo número de Aviso y nombre del PDF fuente cuando están disponibles en auditoría.

## Base de datos

**No existe migración SQL en Fase 5E4.**

Se reutilizan las columnas creadas en Fase 5E3:

- `desembarque_aviso_details.source_type`
- `desembarque_aviso_versions.source_type`

## Instalación

1. Copiar los archivos del parche conservando sus rutas.
2. No ejecutar ninguna migración.
3. Reiniciar la aplicación:

```powershell
docker compose restart app
```

4. El Service Worker cambia de `v17` a `v18`; hacer `Ctrl + Shift + R` en el navegador.

No es necesario reconstruir Docker porque esta fase no modifica `Dockerfile`, dependencias ni extensiones.

## Prueba de aceptación

### Reportes

1. `Origen = Todos`: deben aparecer históricos y sistema.
2. `Origen = Históricos importados`: deben aparecer 017-26, 018-26, 019-26, 020-26, 021-26, 061-26 (según la BD validada).
3. `Origen = Generados por sistema`: debe aparecer 060-26 y cualquier otro Aviso nativo.
4. Las filas deben mostrar badge `HISTÓRICO` / `SISTEMA`.
5. Exportar PDF o Excel y verificar que exista la columna `Origen del aviso`.

### Expediente histórico

Abrir, por ejemplo, 017-26 o 061-26:

- badge `HISTÓRICO` visible;
- tarjeta `Origen del aviso` visible;
- botón `Descargar original histórico` disponible;
- v001 marcada `ORIGINAL HISTÓRICO`;
- pestaña Fotos muestra el bloque `ANEXO FOTOGRÁFICO HISTÓRICO` y no inventa JPG/PNG nativos;
- Historial contiene `Aviso histórico importado` cuando exista el evento de auditoría.

### Expediente de sistema

Abrir 060-26:

- badge `SISTEMA` visible;
- tarjeta de origen indica gestión directa en Desembarques;
- versiones marcadas `GENERADO POR SISTEMA`;
- fotos nativas continúan funcionando sin cambios.

## Estado esperado al cierre

La procedencia del Aviso queda explícita y lista para los KPIs de Fase 6:

- total de Avisos;
- Avisos históricos;
- Avisos generados por sistema;
- estados documentales;
- renglones de mercancía;
- piezas;
- pedimentos;
- versiones PDF.

No se debe usar el `source_type` de la versión más reciente para decidir el origen del expediente: el origen consolidado siempre se deriva de `desembarque_aviso_details.source_type`.
