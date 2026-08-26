# Navbar Premium — Desembarques

## Objetivo
Reorganiza la navegación compartida para evitar barras de dos o tres filas y darle una jerarquía más profesional.

## Nuevo esquema
- Marca a la izquierda.
- Navegación principal visible en una sola banda: Dashboard / Nuevo desembarque / Reportes / Analítica, según los links disponibles en cada página.
- `Operación` agrupa Importar históricos y Control de operaciones.
- `Administración` agrupa Clientes, Estados, Usuarios y Auditoría.
- Tema en botón compacto.
- Perfil y Cerrar sesión quedan dentro del menú de cuenta.
- En tablet/móvil la barra colapsa limpiamente en secciones.

El componente **no inventa permisos ni enlaces**: sólo reorganiza los `$navLinks` que cada página ya autoriza y suministra.

## Archivos
- `public/partials/nav.php`
- `public/partials/styles.php`
- `public/assets/css/navbar-premium.css`
- `public/service-worker.js`

## Instalación
Copiar los archivos conservando rutas. No hay SQL ni cambios de Docker.

Local:
```powershell
docker compose restart app
```

Después realizar una recarga fuerte (`Ctrl + Shift + R`). El Service Worker pasa de `v27` a `v28`.

## Validación sugerida
Probar como:
- Administrador en Dashboard y Reportes.
- Usuario interno.
- Cliente, si utiliza portal.
- 1920px, 1366px, tablet y móvil.
- Light / dark mode.
