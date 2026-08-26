# Fase 5A — Expediente del Aviso (read-only)

## Objetivo

Crear una vista propia y auditable para cada Aviso de Desembarque sin modificar todavía el estado del aviso ni la gestión documental. Esta fase es deliberadamente **read-only**.

La gestión documental editable queda para Fase 5B y tendrá como regla de negocio que **añadir documentos no dependerá del estado del Aviso**.

## Nuevo flujo

En `Reportes`, la columna Aviso ahora ofrece:

- **Expediente** → abre `aviso-expediente.php?id={desembarque_id}`.
- **Aviso** → conserva el generador actual para usuarios internos.

Los usuarios cliente pueden consultar el expediente de sus propios desembarques, pero no reciben el botón de generación.

## Nueva página

`public/aviso-expediente.php`

Secciones:

1. **Resumen**
   - Cliente y referencia.
   - Estado operativo actual del desembarque.
   - Manifiesto, transporte e IMO.
   - Fechas y ubicación.
   - Perfil Rig/proyecto.
   - Excel fuente y SHA-256.

2. **Documentos**
   - Lista de `desembarque_files`.
   - Identifica Excel fuente, fotografías del anexo, PDFs y documentos generales.
   - Descarga privada usando el endpoint existente.
   - Botón `Añadir documentos` visible pero deshabilitado en 5A, como señal de la Fase 5B.

3. **Mercancías**
   - Datos estructurados de `desembarque_aviso_items`.
   - Pedimento, clave, importador, cantidad, serial y marca.

4. **Fotos**
   - Galería del anexo fotográfico.
   - Relación con mercancía cuando existe.

5. **PDFs emitidos**
   - Versiones inmutables de `desembarque_aviso_versions`.
   - SHA-256 de PDF y snapshot.
   - Descarga desde `version_download.php`.

6. **Historial**
   - Timeline derivado de `audit_logs`.
   - Alta/actualización del desembarque.
   - Cambios documentales ya auditados.
   - Actualizaciones del Aviso.
   - Cambios de estado operativo.
   - Emisiones de PDF.

## Base de datos

**No hay migración SQL en Fase 5A.**

La vista consume exclusivamente estructuras aprobadas en las fases anteriores:

- `desembarques`
- `desembarque_aviso_details`
- `desembarque_aviso_items`
- `desembarque_aviso_images`
- `desembarque_files`
- `desembarque_aviso_versions`
- `desembarque_pedimento_headers`
- `aviso_profiles`
- `audit_logs`

## Compatibilidad y seguridad

- No se cambia el generador PDF.
- No se altera ninguna versión emitida.
- No se modifica el esquema MySQL.
- La descarga de archivos sigue usando endpoints autenticados existentes.
- El acceso del rol `cliente` conserva el aislamiento por cliente existente.
- El estado mostrado en 5A se etiqueta como **estado operativo del desembarque**. El state machine propio del Aviso pertenece a Fase 5C.

## Service Worker

Cache version: `v10 → v11`.

Se añade al precache:

- `assets/css/aviso-expediente.css`

## Instalación

No ejecutar migraciones.

1. Copiar el parche sobre el proyecto actual respetando rutas.
2. Reiniciar el contenedor de aplicación:

```powershell
docker compose restart app
```

3. En el navegador hacer `Ctrl + Shift + R`.

## Prueba de aceptación 5A

1. Abrir `Reportes`.
2. Confirmar que un registro muestra `Expediente` y `Aviso` para usuario interno.
3. Abrir `Expediente`.
4. Validar contadores contra la BD: mercancías, pedimentos, fotos, documentos y versiones.
5. Descargar un documento desde la pestaña Documentos.
6. Abrir una foto y verificar que la URL privada exige sesión.
7. Descargar `v001`/`v002` desde PDFs emitidos.
8. Confirmar que el timeline incluye al menos creación/actualización, emisión PDF y movimientos documentales existentes.
9. Volver a Reportes y comprobar que el generador del Aviso continúa funcionando.
10. Si se usa rol cliente, confirmar que no puede abrir un expediente ajeno.
