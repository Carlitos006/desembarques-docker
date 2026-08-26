# Sistema de registro de desembarques

Aplicación web sencilla para capturar información de desembarques marítimos. Incluye autenticación por roles y un panel de administración de usuarios.

## Requisitos

- PHP 8.1 o superior con extensión `mysqli`
- Servidor MySQL o MariaDB
- Servidor web configurado para apuntar al directorio `public/`

## Instalación como aplicación (PWA)

El proyecto incluye un manifiesto web, iconos y un service worker que permiten instalar la aplicación tanto en tabletas como en teléfonos inteligentes compatibles con Progressive Web Apps. En Android y en navegadores de escritorio que soportan PWA se mostrará el aviso de instalación cuando se acceda al sitio en modo seguro (HTTPS). En iOS, la instalación se realiza mediante la opción "Añadir a pantalla de inicio" desde Safari. No es necesario ningún paso adicional para que funcione en celulares: los mismos metadatos utilizados para tabletas son detectados también por los teléfonos que cumplen con los requisitos mínimos del navegador.

## Instalación

1. Clona el repositorio y coloca los archivos en tu servidor.
2. Ejecuta `composer install` para descargar las dependencias PHP (incluida `smalot/pdfparser`, utilizada para leer los PDF).
3. Crea una base de datos vacía (por defecto `desembarques`).
4. Importa el archivo [`database/schema.sql`](database/schema.sql) para crear las tablas necesarias.
5. Configura las variables de entorno para la conexión a la base de datos si es necesario:
   - `DB_HOST`
   - `DB_PORT`
   - `DB_DATABASE`
   - `DB_USERNAME`
   - `DB_PASSWORD`
6. Configura las variables de correo si deseas habilitar el restablecimiento de contraseña por email:
   - `APP_URL` (URL base pública del sistema, usada para generar los enlaces de restablecimiento)
   - `SMTP_HOST`
   - `SMTP_PORT`
   - `SMTP_USERNAME`
   - `SMTP_PASSWORD`
   - `SMTP_ENCRYPTION` (por ejemplo `tls` o `ssl`, según tu servidor)
   - `MAIL_FROM_ADDRESS`
   - `MAIL_FROM_NAME`
   - `PASSWORD_RESET_TOKEN_EXPIRATION_MINUTES` (opcional, minutos de vigencia del enlace; por defecto 60)
7. (Opcional) Ajusta el procesamiento automático de pedimentos desde PDF estableciendo las variables `PEDIMENTOS_PDF_PAGE_FROM` y `PEDIMENTOS_PDF_PAGE_TO` para acotar el rango de páginas procesadas.

   > El sistema utiliza exclusivamente la librería `smalot/pdfparser` para extraer texto de los PDF. Asegúrate de ejecutar `composer install` después de desplegar para que la dependencia quede disponible.

## Inicio de sesión y roles

- El esquema crea automáticamente un usuario administrador por defecto:
  - Correo: `admin@example.com`
  - Contraseña: `admin123`
- El administrador puede generar cuentas adicionales desde la opción **Administrar usuarios**.
- Roles disponibles:
  - **Administrador**: puede registrar desembarques y crear/gestionar usuarios.
  - **Usuario**: puede registrar desembarques.
  - **Cliente**: acceso de solo lectura; no puede registrar desembarques.

## Recuperación de contraseña

1. En la página de inicio de sesión selecciona «¿Olvidaste tu contraseña?».
2. Ingresa el correo electrónico asociado a tu cuenta. Si existe un usuario registrado, recibirás un enlace temporal para crear una nueva contraseña.
3. El enlace expira según el valor de `PASSWORD_RESET_TOKEN_EXPIRATION_MINUTES`. Tras definir una nueva contraseña serás redirigido nuevamente al inicio de sesión.

> Nota: el envío de correos utiliza la función `mail()` de PHP. Asegúrate de que tu servidor tenga configurado un agente de transporte (sendmail/SMTP) acorde a las variables anteriores.

## Uso

1. Accede a `public/login.php` para iniciar sesión.
2. Una vez autenticado, los perfiles con permisos pueden registrar nuevos desembarques desde `public/index.php`.
3. Consulta el apartado de **Reportes** (`public/reportes.php`) para revisar los desembarques registrados. Los clientes solo podrán ver los registros asignados a su cuenta.
4. Para cerrar sesión utiliza el enlace "Cerrar sesión" disponible en la barra superior.

## API REST v1

El sistema expone un conjunto de endpoints independientes de la sesión en el directorio [`api/v1/`](api/v1). Para utilizarlos:

1. Ingresa a **Mi perfil** y genera un token personal en la nueva sección *Tokens de acceso personal*.
2. Conserva el valor mostrado una sola vez; los tokens solo pueden consultarse al momento de crearse.
3. Envía el token en cada solicitud mediante el encabezado `Authorization: Bearer <token>`.
4. Cada token está limitado a 120 solicitudes por minuto. El encabezado de respuesta incluye `X-RateLimit-Limit`, `X-RateLimit-Remaining` y `X-RateLimit-Reset`.

### Alcances disponibles

| Alcance               | Descripción                                                |
|-----------------------|------------------------------------------------------------|
| `desembarques:read`   | Permite listar y consultar desembarques registrados.       |
| `desembarques:write`  | Permite crear y actualizar desembarques, incluyendo archivos adjuntos. |

### Endpoints principales

- `GET api/v1/desembarques/index.php` (requiere `desembarques:read`): acepta filtros opcionales `fecha_inicio`, `fecha_fin`, `cliente_id` y `status_id`. Devuelve un objeto JSON con `data.items` y `meta.totals`.
- `POST api/v1/desembarques/store.php` (requiere `desembarques:write`): recibe los mismos campos que el formulario web (`referencia`, `descripcion`, `destino`, `folio_aviso`, `fecha_desembarque`, `fecha_embarque`, `barco`, `cliente`, `cliente_id`, `status_id`) y admite archivos mediante `multipart/form-data` (`attachments[]`).
- `PUT api/v1/desembarques/update.php` (requiere `desembarques:write`): actualiza un desembarque existente. Acepta el identificador `id`, los campos anteriores y permite adjuntar nuevos archivos o marcar los existentes para eliminar con `delete_attachments[]`.

Todas las respuestas siguen la estructura:

```json
{
  "status": "ok",
  "data": { ... },
  "meta": {
    "rate_limit": { "limit": 120, "remaining": 118, "reset": 1716493200 }
  }
}
```

En caso de error se devuelve `status: "error"`, un mensaje descriptivo y, cuando aplica, un objeto `errors` con los campos inválidos.

Ejemplo de consulta autenticada:

```bash
curl \
  -H "Authorization: Bearer <TU_TOKEN>" \
  "https://tu-dominio/api/v1/desembarques/index.php?fecha_inicio=2024-01-01"
```

Creación mediante JSON (sin adjuntos):

```bash
curl \
  -H "Authorization: Bearer <TU_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "referencia": "FOO-123",
    "descripcion": "Descarga programada",
    "destino": "Ensenada",
    "folio_aviso": "AV-2024-01",
    "fecha_desembarque": "2024-05-15",
    "barco": "Poseidón",
    "cliente": "Cliente demo",
    "status_id": 1
  }' \
  https://tu-dominio/api/v1/desembarques/store.php
```

La actividad realizada con tokens también se registra en la tabla `audit_logs`, indicando el token utilizado, la ruta solicitada y la dirección IP.

## Administración de estados

- El perfil administrador cuenta con el acceso **Administrar estados** en la barra de navegación, el cual abre `public/statuses.php`.
- Desde esa vista se listan todos los estados configurados, con indicadores de actividad y del estado predeterminado.
- El formulario valida que el identificador utilice minúsculas, números, guiones o guiones bajos y que ambos nombres (español e inglés) sean obligatorios.
- Los interruptores de la tabla permiten activar/desactivar estados y marcar el predeterminado. No es posible desactivar el estado marcado como predeterminado ni dejar al sistema sin un estado base.
- Los mensajes de error devueltos por el servidor se muestran en pantalla para facilitar el diagnóstico.
- El script [`database/schema.sql`](database/schema.sql) incluye ahora los estados adicionales `cancelled` y `on_hold` además de los iniciales (`pending`, `in_progress`, `completed`).

## Registros de auditoría

- Cada alta o actualización realizada desde los módulos de desembarques, clientes y usuarios genera una entrada en la tabla `audit_logs`.
- El helper [`config/audit.php`](config/audit.php) controla la escritura de estos eventos y contiene un mecanismo de contingencia que evita que un error al registrar la auditoría falle la operación principal.
- Los detalles (usuario que ejecutó la acción, entidad afectada y diferencias antes/después) pueden revisarse desde `public/audit_logs.php`. Esta vista es de acceso exclusivo para administradores e incluye filtros por usuario, acción y término de búsqueda.

### Retención y rotación

- La tabla `audit_logs` puede crecer rápidamente en entornos de producción. Se recomienda conservar los registros por al menos 180 días y ejecutar tareas periódicas que archiven o eliminen las entradas más antiguas.
- Un enfoque común es programar un `cron` que exporte los registros a un almacenamiento frío y posteriormente ejecute una sentencia como `DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY);`.
- Ajusta la ventana de retención según los requisitos legales o de auditoría de tu organización.

## Estructura principal

- `public/`: archivos accesibles desde el navegador (páginas, assets, scripts).
- `api/`: endpoints PHP que reciben las solicitudes AJAX del sitio.
- `config/database.php`: helper para obtener la conexión a la base de datos.
- `database/schema.sql`: definición de tablas y usuario administrador inicial.

## Ejecución con Docker

El proyecto incluye una configuración para desarrollo local con Docker Desktop, Apache, PHP 8.3 y MySQL 8.0.

Consulta [`DOCKER.md`](DOCKER.md) para el arranque, la migración desde XAMPP, phpMyAdmin, SMTP y los comandos de mantenimiento.
