# Corrección de lista de clientes congelada por Service Worker

## Causa

El Service Worker anterior guardaba en caché cualquier solicitud GET, incluyendo
`index.php`, `clients.php` y otras páginas generadas por PHP. Si el panel se abría
antes de registrar un cliente, la respuesta HTML sin clientes quedaba almacenada y
se seguía mostrando aunque la tabla `clients` ya tuviera registros.

## Archivos incluidos

- `public/service-worker.js`
  - Incrementa la caché a `v3`.
  - Nunca almacena páginas PHP, navegaciones ni rutas `/api/`.
  - Conserva caché únicamente para CSS, JavaScript, imágenes, fuentes y manifest.
  - Activa inmediatamente la nueva versión y elimina cachés estáticas antiguas.
- `public/partials/scripts.php`
  - Registra el Service Worker con `updateViaCache: 'none'`.
  - Solicita una comprobación de actualización en cada carga.

## Aplicación

Reemplaza ambos archivos conservando su ruta. Como el proyecto está montado como
volumen en Docker, no es necesario reconstruir la imagen.

Después elimina una vez el Service Worker y cachés antiguos desde la consola del
navegador:

```javascript
Promise.all([
  navigator.serviceWorker.getRegistrations().then((registrations) =>
    Promise.all(registrations.map((registration) => registration.unregister()))
  ),
  caches.keys().then((keys) => Promise.all(keys.map((key) => caches.delete(key))))
]).then(() => location.reload());
```

Después de recargar, la lista de clientes se obtiene nuevamente desde PHP/MySQL.
