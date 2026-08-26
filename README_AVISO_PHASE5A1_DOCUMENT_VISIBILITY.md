# Fase 5A.1 — Visibilidad documental del expediente

## Objetivo

Ajustar la vista read-only del expediente para separar claramente tres tipos de insumo:

- **Documentos del expediente:** únicamente PDF y Word (`.pdf`, `.doc`, `.docx`).
- **Fotografías:** se muestran exclusivamente en la pestaña **Fotos**.
- **Excel fuente:** es un insumo técnico privado utilizado para estructurar los datos y generar el Aviso; no se muestra en Resumen, métricas ni Documentos y no se descarga mediante el endpoint genérico de archivos.

## Cambios

### `public/aviso-expediente.php`

- Documentos filtra a PDF/DOC/DOCX.
- Fotografías quedan fuera de Documentos.
- Excel fuente queda fuera de Documentos.
- Se elimina la tarjeta `Trazabilidad de origen` que mostraba nombre, SHA-256 y fecha del Excel.
- Se elimina la métrica `Excel fuente` del encabezado.
- El contador Documentos cuenta solamente PDF/Word.

### `api/desembarques/files/download.php`

El archivo que coincide con `desembarque_aviso_details.source_excel_name` responde como no disponible desde el endpoint genérico de descarga para cualquier rol. El archivo sigue almacenado privadamente para los procesos internos que lo necesiten.

### `public/service-worker.js`

Cache `v11 → v12`.

## Base de datos

No hay cambios de esquema ni migración.

## Instalación

1. Copiar el parche sobre el proyecto respetando rutas.
2. Reiniciar únicamente `app`:

```powershell
docker compose restart app
```

3. En el navegador: `Ctrl + Shift + R`.

## Prueba de aceptación

1. Abrir un expediente con fotos + Excel fuente.
2. Documentos no debe mostrar ninguna foto ni el Excel.
3. Fotos debe seguir mostrando todas las imágenes.
4. Resumen no debe mostrar nombre/hash/estado del Excel fuente.
5. El contador Documentos debe contar sólo PDF/DOC/DOCX.
6. Una URL directa al `files/download.php?id=...` correspondiente al Excel fuente debe responder 404.
7. PDFs históricos y generación del Aviso deben continuar funcionando.
