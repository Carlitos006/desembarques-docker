# Validación de la preparación Docker

Fecha de preparación: 20 de julio de 2026.

## Validaciones completadas

- `compose.yml` analizado correctamente como YAML.
- Servicios definidos: `app`, `db` y `phpmyadmin` opcional.
- 72 archivos PHP validados con `php -l`, sin errores de sintaxis.
- `docker/entrypoint.sh` validado con `sh -n`.
- `composer.json` validado como JSON.
- Virtual host de Apache validado con `apache2ctl -t`.
- Configuración SMTP generada y legible por el usuario `www-data`.
- Prueba HTTP local con Apache:
  - `/` → 302 hacia login cuando no existe sesión.
  - `/login.php` → 200.
  - `/assets/css/styles.css` → 200.
  - `/api/auth/login.php` → 405 mediante GET, confirmando que el alias `/api` funciona y el endpoint exige su método esperado.
  - `/table/index.html` → 200.
  - `/config/database.php` → 404.
  - `/vendor/autoload.php` → 404.

## Limitación de la validación

El entorno usado para preparar el paquete no dispone del ejecutable Docker. Por ello no fue posible ejecutar `docker compose build` ni levantar MySQL dentro de contenedores aquí. La sintaxis, rutas, permisos, extensiones requeridas y configuración de los servicios sí fueron revisadas estáticamente y mediante una prueba real de Apache/PHP.

La prueba integral debe realizarse en Docker Desktop con:

```powershell
docker compose up -d --build
docker compose ps
docker compose logs -f app
```
