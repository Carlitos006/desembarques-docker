# Eliminación controlada de fotografías nativas

Esta actualización añade **Eliminar foto** a cada fotografía nativa del apartado `aviso-expediente.php?tab=fotos`.

## Seguridad y comportamiento

- Disponible para los mismos roles internos (`admin` y `usuario`) que pueden subir fotografías.
- El endpoint acepta únicamente `POST`, exige sesión, rol, CSRF y un expediente activo.
- Cada botón recibe el token CSRF generado en la misma respuesta PHP y el cliente lo envía tanto en el cuerpo como en `X-CSRF-Token`; esto evita depender de una configuración JavaScript desfasada.
- Después de eliminar, la navegación conserva el ID tomado del propio botón y regresa a `aviso-expediente.php?id=...&tab=fotos`; no redirige a Reportes ni modifica el estado del expediente.
- La asociación fotográfica se bloquea dentro de una transacción antes de eliminarse.
- El archivo se elimina de `desembarque_files` y del almacenamiento sólo cuando no tiene otra asociación legacy.
- La acción queda registrada en `audit_logs` como `delete / desembarque_aviso_photo` con sus metadatos y SHA-256.
- Los PDFs históricos, versiones emitidas y sus snapshots permanecen inmutables.
- Un Aviso en Papelera continúa siendo de sólo lectura y no permite eliminar fotografías.

## Archivos de producción

```text
api/desembarques/aviso/photo_delete.php
public/aviso-expediente.php
public/assets/js/aviso-expediente-photos.js
public/service-worker.js
```

No requiere migración SQL. Tras publicar, reinicia OPcache si aplica y fuerza una recarga del navegador. El service worker cambia a `v37` y la interfaz solicita `aviso-expediente-photos.js?v=37`.

## Validación

```powershell
php tools/validate_aviso_photo_delete.php
```

Prueba con una fotografía nativa de descarte: confirma que desaparezca del anexo, de `desembarque_aviso_images`, y que exista su auditoría. No uses como prueba una evidencia que deba conservarse.
