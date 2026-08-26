# Traducción integral de interfaz ES/EN

Este parche completa la experiencia en inglés usando el idioma seleccionado en el inicio de sesión y conservado en la sesión del usuario.

## Cobertura

- Catálogos español e inglés con las mismas claves.
- Páginas principales, Dashboard, Reportes y formularios existentes.
- Exportaciones PDF/Excel del Dashboard y notificaciones por correo.
- Expediente del Aviso: fotos, Addenda (Alcances), PDFs, baja administrativa y restauración.
- Importación histórica, incluyendo revisión, duplicados, validación e importación.
- Respuestas operativas de las APIs de Avisos sin traducir datos capturados por el usuario.
- APIs de Dashboard, Papelera administrativa, importadores de pedimentos y análisis de PDF.
- Extractor heredado de pedimentos abierto desde Nuevo desembarque y Reportes.
- Confirmaciones administrativas localizadas: `DELETE <notice number>` y `RESTORE <notice number>` en inglés; `ELIMINAR` y `RESTAURAR` en español.
- Excepción bilingüe para que `admin` y `usuario` importen un aviso correcto conservando en Papelera el registro de prueba eliminado; `cliente` permanece sin acceso.
- Caché del navegador actualizada a `v43` (los recursos de traducción conservan su identificador `v42`).

Los textos legales que forman parte del formato oficial mexicano del Aviso y del Alcance permanecen en español dentro del PDF. La interfaz informa esta excepción cuando está en inglés; los estados de proceso, controles y mensajes de error del generador sí respetan el idioma. Esto evita alterar el contenido documental oficial o introducir una traducción jurídica no autorizada.

La terminología profesional en inglés usa **Unloading Notice**, **Addendum/Addenda**, **Customs entry**, **merchandise line**, **release/departure** y **reference number**, según el contexto. No se traducen nombres propios ni datos capturados por el usuario.

## Base de datos

No hay migración ni SQL para este parche. No se agregan, eliminan ni modifican tablas o datos.

## Instalación en producción

1. Haz un respaldo de los archivos actuales.
2. Descomprime el ZIP del parche sobre la raíz del proyecto, conservando las carpetas.
3. Reemplaza todos los archivos incluidos.
4. No ejecutes SQL.
5. Cierra sesión, vuelve a ingresar seleccionando **English** y recorre los módulos indicados abajo.
6. Si una pestaña ya estaba abierta, ciérrala y vuelve a abrirla. El service worker `v43` elimina la caché anterior automáticamente; también puedes hacer una recarga forzada una vez.

## Validación local

Desde la raíz del proyecto ejecuta:

```bash
php tools/validate_full_i18n.php
php tools/validate_aviso_photo_delete.php
php tools/validate_phase7f_soft_delete.php
```

El primer comando debe terminar con `24 OK, 0 error(es)`. Además de paridad de claves y variables, revisa textos de JavaScript y PHP, respuestas de API, terminología, comandos administrativos, contenido dinámico del extractor, correos, exportaciones del Dashboard, caché bilingüe y la excepción jurídica del PDF. El validador de Fase 7F puede omitir únicamente la comprobación de base de datos si MySQL local no está disponible.

Después valida manualmente con dos sesiones, una en Español y otra en English:

1. Login, recuperación de contraseña y navegación principal.
2. Dashboard, Reportes, exportaciones y búsqueda.
3. Nuevo desembarque y extractor PDF de pedimentos.
4. Expediente: Summary, Documents, Merchandise, Photos, Addenda, Issued PDFs e History.
5. Como admin: baja lógica, Papelera y restauración.
6. Importación histórica: carga, revisión, validación, duplicado eliminado e importación.
7. Portal de cliente, modo sin conexión y respuestas de error de APIs.

## Nota de seguridad

La traducción operativa se aplica únicamente a campos de respuesta como `message`, `detail`, `error` y `label`. Nombres de clientes, descripciones de mercancía, números de Aviso, pedimentos y demás datos del negocio se conservan exactamente como fueron capturados.
