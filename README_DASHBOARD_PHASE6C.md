# Fase 6C — Dashboard Ejecutivo Visual

## Objetivo

Construir la interfaz ejecutiva de Avisos de Desembarque consumiendo **exclusivamente** el endpoint agregado aprobado en Fase 6B:

`GET /api/desembarques/dashboard/summary.php`

La UI no redefine ni recalcula KPIs de negocio. Los valores provienen del contrato 6A/6B y la API continúa operando `fail-closed` si la integridad deja de pasar.

## Archivos

- `public/dashboard-avisos.php`
- `public/assets/css/dashboard-avisos.css`
- `public/assets/js/dashboard-avisos.js`
- `public/partials/nav.php`
- `public/service-worker.js`
- `tools/validate_dashboard_ui.php`

No hay migración SQL en 6C.

## Componentes visuales

### Filtros globales

- Año / rango de fechas
- Cliente
- Rig / Proyecto
- Estado documental
- Origen: Sistema / Histórico
- Alcance `production / qa / all` sólo para admin

Todos los componentes usan una sola respuesta 6B y se actualizan con la misma combinación de filtros.

### KPIs principales

- Avisos totales
- Presentados
- Piezas en almacén
- Piezas exportadas
- Mercancías parciales
- Versiones PDF

### KPIs secundarios

- Pedimentos únicos
- Clientes activos
- Rigs activos
- Renglones de mercancía
- Piezas originales

### Visualizaciones

- Actividad mensual: Históricos vs Sistema
- Estado documental
- Balance de piezas exportadas / almacenadas
- Antigüedad de mercancía almacenada
- Ranking por cliente
- Ranking por Rig / Proyecto
- Atención ejecutiva: borradores, emitidos, parciales y piezas +90 días
- Tiempos de ciclo
- Tabla de avisos recientes con drill-down directo al expediente

## Seguridad

`dashboard-avisos.php` sólo permite roles:

- `admin`
- `usuario`

Los clientes siguen sin acceso al dashboard ejecutivo.

La API 6B mantiene el control server-side; ocultar elementos de UI no sustituye autorización.

## Integración de navegación

`public/partials/nav.php` añade automáticamente **Dashboard ejecutivo** a todos los usuarios internos, sin tener que modificar uno por uno los `$navLinks` de cada página.

## Cache

El Service Worker pasa de `v23` a `v24` y precachea:

- `assets/css/dashboard-avisos.css`
- `assets/js/dashboard-avisos.js`

## Instalación

1. Copiar el contenido del parche sobre la raíz del proyecto manteniendo rutas.
2. No ejecutar migraciones.
3. No reconstruir Docker.
4. Reiniciar `app`:

```powershell
docker compose restart app
```

5. Hacer `Ctrl + Shift + R` en el navegador.

## Validación CLI

```powershell
docker compose exec app php `
  /var/www/html/tools/validate_dashboard_ui.php
```

Resultado esperado:

```text
=== FASE 6C · DASHBOARD VISUAL CONTRACT ===
...
PASS · KPI UI disponible: avisos_total
PASS · KPI UI disponible: pieces_stored
PASS · Serie mensual disponible
PASS · Serie de antigüedad disponible
PASS · Breakdown de estados disponible
PASS · Ranking de clientes disponible
PASS · Integridad 6A permanece PASS
...
RESULTADO GLOBAL: PASS
```

También:

```powershell
docker compose exec app php `
  /var/www/html/tools/validate_dashboard_ui.php `
  --origin=historical
```

## Prueba humana 6C

1. Abrir `http://localhost:8080/dashboard-avisos.php`.
2. Confirmar que carga sin errores y muestra el badge **Integridad KPI validada**.
3. Comparar Avisos/Piezas contra `validate_dashboard_kpis.php`.
4. Probar filtros de Cliente, Rig, Estado, Origen y fechas.
5. Confirmar que todos los gráficos y rankings cambian al mismo tiempo.
6. Abrir un Aviso desde **Avisos recientes** y verificar que navega al expediente correcto.
7. Probar tema oscuro.
8. Probar ancho tablet/móvil y confirmar que no existe scroll horizontal del layout principal.
9. Como admin, probar `production`, `qa` y `production + qa`.
10. Como usuario interno normal, confirmar que no aparece el selector QA.

## Fuera de alcance de 6C

Se reserva para 6D:

- Reporte Ejecutivo PDF
- Exportación ejecutiva Excel
- Programación de reportes
- Drill-down avanzado de mercancía +90 días
- Comparativas contra periodo anterior / targets
