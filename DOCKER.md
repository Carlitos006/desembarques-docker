# Desembarques 2 — ejecución local con Docker

Esta configuración reemplaza Apache y MySQL de XAMPP para el desarrollo local. El código fuente sigue montado desde Windows, por lo que los cambios realizados en VS Code se reflejan inmediatamente al actualizar el navegador.

## 1. Requisitos

- Docker Desktop para Windows.
- Docker Desktop iniciado y usando contenedores Linux.
- Puertos locales disponibles:
  - `8080`: aplicación.
  - `3307`: MySQL desde herramientas externas.
  - `8081`: phpMyAdmin opcional.

## 2. Primer arranque

Desde PowerShell, dentro de la carpeta del proyecto:

```powershell
cd C:\Dev\desembarques
docker compose up -d --build
```

Revisar el estado:

```powershell
docker compose ps
```

Abrir la aplicación:

```text
http://localhost:8080
```

Credenciales iniciales creadas por `database/schema.sql`:

```text
Correo: admin@example.com
Contraseña: admin123
```

Cambia esa contraseña inmediatamente después de validar el acceso.

## 3. Ver registros

Aplicación PHP/Apache:

```powershell
docker compose logs -f app
```

MySQL:

```powershell
docker compose logs -f db
```

Todos los servicios:

```powershell
docker compose logs -f
```

## 4. phpMyAdmin opcional

Iniciar la herramienta:

```powershell
docker compose --profile tools up -d
```

Abrir:

```text
http://localhost:8081
```

Servidor de MySQL dentro de phpMyAdmin:

```text
db
```

Puedes entrar con el usuario de la aplicación definido en `.env` o con `root` y `MYSQL_ROOT_PASSWORD`.

## 5. Acceder a MySQL desde Windows

Desde MySQL Workbench, DBeaver u otra herramienta:

```text
Host: 127.0.0.1
Puerto: 3307
Base: desembarques
Usuario: desembarques_user
Contraseña: desembarques_dev_password
```

El puerto interno entre contenedores sigue siendo `3306`; la aplicación utiliza automáticamente el host `db`.

## 6. Importar la base actual de XAMPP

Primero exporta la base desde phpMyAdmin de XAMPP y guarda el archivo, por ejemplo, como:

```text
database/desembarques_xampp.sql
```

Para reemplazar completamente la base Docker por esa copia, elimina primero el volumen de pruebas:

```powershell
docker compose down -v
docker compose up -d db
docker compose ps
```

Espera a que el servicio `db` aparezca como `healthy`. Después copia e importa el respaldo:

```powershell
docker compose cp .\database\desembarques_xampp.sql db:/tmp/desembarques_xampp.sql
docker compose exec db sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS $MYSQL_DATABASE; CREATE DATABASE $MYSQL_DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'
docker compose exec db sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" < /tmp/desembarques_xampp.sql'
docker compose up -d app
```

Los permisos del usuario de aplicación se conservan para la base con el mismo nombre. No ejecutes `down -v` después de comenzar a usar datos que quieras conservar, porque elimina el volumen de MySQL.

## 7. Migrar archivos subidos desde XAMPP

Copia los archivos físicos existentes a:

```text
storage/uploads/
```

La carpeta está montada dentro del contenedor como:

```text
/var/www/html/storage/uploads
```

Los archivos permanecerán en la carpeta del proyecto aunque el contenedor sea recreado.

## 8. Detener o reiniciar

Detener conservando base y archivos:

```powershell
docker compose down
```

Reiniciar:

```powershell
docker compose restart
```

Reconstruir después de modificar el `Dockerfile`, Apache o PHP:

```powershell
docker compose up -d --build
```

## 9. Reinicio completamente limpio

Esto elimina contenedores y la base de datos Docker:

```powershell
docker compose down -v --remove-orphans
docker compose up -d --build
```

Los archivos de `storage/uploads/` no se eliminan porque están almacenados en el proyecto.

## 10. Composer dentro del contenedor

Instalar o actualizar dependencias:

```powershell
docker compose exec app composer install
docker compose exec app composer update
```

Para producción se debe conservar `composer.lock` y preferir `composer install --no-dev --optimize-autoloader`.

## 11. Correo SMTP

El contenedor incluye `msmtp`, de forma que el código existente que usa `mail()` puede enviar mediante SMTP sin reescribir la aplicación.

Configura en `.env`:

```dotenv
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USERNAME=usuario@example.com
SMTP_PASSWORD=tu_clave
SMTP_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME=Registro de Desembarques
```

Después reinicia la aplicación:

```powershell
docker compose up -d --force-recreate app
```

## 12. Notas de seguridad

La configuración incluida es para desarrollo local:

- Los puertos solo se publican en `127.0.0.1`.
- PHP muestra errores para facilitar las pruebas.
- Las claves del `.env` son claves locales de ejemplo.
- Para producción se deben usar secretos reales, HTTPS y `display_errors=Off`.
