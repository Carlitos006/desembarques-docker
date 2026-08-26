# Auditoría de caché para pantallas dinámicas — Desembarques

## Alcance revisado

Se revisaron:

- 13 pantallas PHP dinámicas.
- 37 endpoints PHP bajo `/api`.
- 3 procesadores PHP bajo `/table`.
- 9 llamadas AJAX GET realizadas desde JavaScript.
- La conexión SSE de observaciones.
- La navegación PWA y el ciclo de actualización del Service Worker.

## Pantallas dinámicas cubiertas

| Pantalla | Fuente dinámica principal | Protección aplicada |
|---|---|---|
| `index.php` | clientes, estados y formulario | navegación `no-store`, caché HTTP desactivada |
| `reportes.php` | clientes, estados, reportes, archivos y observaciones | página y APIs `no-store`; AJAX GET sin caché |
| `analytics.php` | endpoint de analítica | AJAX GET sin caché y API `no-store` |
| `control_tower.php` | estados y tablero | `fetch(..., cache: 'no-store')` |
| `client-portal.php` | embarques, mensajes y preferencias | AJAX GET sin caché |
| `clients.php` | catálogo de clientes | página PHP `no-store` |
| `statuses.php` | catálogo de estados | página PHP y API `no-store` |
| `users.php` | usuarios y clientes disponibles | página PHP y API `no-store` |
| `audit_logs.php` | bitácora y filtros | página PHP `no-store` |
| `myprofile.php` | perfil y tokens API | AJAX GET sin caché |
| `login.php` | sesión y mensajes de acceso | página PHP `no-store` |
| `password_reset_request.php` | token CSRF y respuesta del servidor | página y API `no-store` |
| `password_reset.php` | token CSRF y validación de token | página y API `no-store` |

También quedan cubiertos `table/index.html`, `table/index2.html`, `table/parse.php`, `table/parse2.php` y `table/pedimentos.php`.

## Hallazgos

1. El Service Worker anterior podía entregar HTML PHP almacenado antes de consultar nuevamente MySQL.
2. Las llamadas GET de jQuery dependían del comportamiento de caché del navegador y del servidor.
3. El tablero utilizaba `fetch()` GET sin declarar `cache: 'no-store'`.
4. Apache no enviaba una política global explícita de `no-store` para todas las respuestas PHP.
5. El Service Worker necesitaba una estrategia compatible con instalaciones en subcarpetas.
6. La conexión SSE de observaciones no debe pasar por la estrategia normal del Service Worker.

## Cambios incluidos

### `public/service-worker.js`

- Caché actualizada a `v4`.
- Las navegaciones, archivos PHP, `/api/` y `/table/` siempre van a red con `cache: 'no-store'`.
- Compatibilidad con una instalación en la raíz o dentro de una subcarpeta.
- La conexión SSE se deja pasar directamente a la red.
- Solo CSS, JavaScript, imágenes, fuentes y manifest pueden almacenarse.
- No se guardan respuestas estáticas marcadas como `private` o `no-store`.
- Se eliminan automáticamente cachés anteriores.

### `public/partials/scripts.php`

- `$.ajaxSetup({ cache: false })` para todos los AJAX GET de jQuery.
- `updateViaCache: 'none'` para comprobar siempre el Service Worker.
- Recarga automática una sola vez cuando una nueva versión toma el control.

### `public/assets/js/control-tower.js`

- El GET del tablero usa `cache: 'no-store'`.

### `docker/apache-vhost.conf`

- Todas las respuestas PHP reciben `Cache-Control: no-store`.
- `/table` recibe la misma política, incluidos sus HTML.
- `service-worker.js` nunca se almacena mediante caché HTTP.
- Se conserva el tratamiento especial para SSE.

## Instalación

Copiar los cuatro archivos incluidos sobre el proyecto, conservando sus rutas.

Como `docker/apache-vhost.conf` se copia durante la construcción de la imagen, reconstruir la aplicación:

```powershell
docker compose down
docker compose build --no-cache app
docker compose up -d
docker compose exec app apache2ctl -t
```

Resultado esperado del último comando:

```text
Syntax OK
```

## Verificación rápida

```powershell
curl.exe -s -D - -o NUL http://127.0.0.1:8080/login.php
curl.exe -s -D - -o NUL http://127.0.0.1:8080/service-worker.js
```

Ambas respuestas deben incluir una política `Cache-Control` con `no-store`.

Después probar la siguiente matriz:

1. Crear o modificar un cliente y abrir `index.php`.
2. Crear o modificar un estado y abrir `statuses.php`, `index.php` y `control_tower.php`.
3. Crear o modificar un usuario y abrir `users.php`.
4. Crear un desembarque y revisar `reportes.php`, `analytics.php`, `control_tower.php` y `client-portal.php`.
5. Agregar una observación y comprobar actualización normal y SSE.
6. Procesar un PDF desde `/table/index.html`.

No debería ser necesario limpiar manualmente la caché: `v4` elimina la versión anterior y recarga la página al tomar control.
