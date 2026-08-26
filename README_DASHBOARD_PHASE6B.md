# Fase 6B — API Agregada del Dashboard Ejecutivo

## Objetivo

Exponer el contrato KPI aprobado en Fase 6A mediante **un único endpoint read-only** que pueda alimentar la interfaz visual de Fase 6C sin recalcular métricas en JavaScript.

## Endpoint

```text
GET /api/desembarques/dashboard/summary.php
```

Sólo usuarios internos `admin` y `usuario` pueden consultarlo. Los clientes reciben HTTP 403.

## Filtros soportados

| Query param | Ejemplo | Notas |
|---|---|---|
| `date_from` | `2026-01-01` | YYYY-MM-DD |
| `date_to` | `2026-12-31` | YYYY-MM-DD |
| `client_id` | `1` | entero positivo |
| `rig_name` | `DEEPWATER THALASSA` | match exacto normalizado en 6A |
| `origin` | `historical` | `all`, `system`, `historical` |
| `aviso_status` | `presented` | `all`, `draft`, `issued`, `presented`, `replaced`, `cancelled` |
| `record_scope` | `production` | `production` para todos; `qa/all` sólo admin |

También se aceptan los aliases `from`, `to`, `rig`, `status` y `scope`.

## Seguridad / fail closed

- Sesión obligatoria.
- Sólo `admin` / `usuario`.
- `usuario` siempre queda forzado a `record_scope=production`.
- Sólo `admin` puede consultar `qa` o `all`.
- Respuestas dinámicas con `Cache-Control: no-store`.
- Si la validación de integridad 6A deja de ser PASS, el endpoint responde **HTTP 409** y no entrega cifras para el Dashboard.

## Contratos

- Contrato métrico: `6A.1`.
- Contrato API: `6B.1`.

La API no inventa KPIs nuevos. Reutiliza `DashboardAvisoMetrics` y únicamente prepara:

- KPIs aprobados;
- breakdown de estados;
- breakdown de origen;
- breakdown de mercancía;
- breakdown de piezas;
- series mensuales;
- aging;
- rankings por cliente y Rig;
- últimos 12 Avisos del universo filtrado;
- catálogo de filtros;
- integridad 6A.

## Respuesta resumida

```json
{
  "success": true,
  "request_id": "...",
  "data": {
    "api_contract_version": "6B.1",
    "metric_contract_version": "6A.1",
    "filters": {},
    "permissions": {},
    "filter_catalog": {
      "date_bounds": {},
      "years": [],
      "clients": [],
      "rigs": []
    },
    "kpis": {},
    "breakdowns": {
      "status": [],
      "origin": [],
      "merchandise_rows": [],
      "pieces": []
    },
    "cycle_times": {},
    "series": {
      "monthly": [],
      "aging": []
    },
    "rankings": {
      "clients": [],
      "rigs": []
    },
    "recent_avisos": [],
    "integrity": {
      "pass": true
    }
  }
}
```

## Instalación

No hay migración SQL nueva en 6B.

Copiar el parche sobre el proyecto **después de haber aplicado Fase 6A + hotfix SQL 6A**.

```powershell
docker compose restart app
```

No se requiere rebuild de Docker ni cambio de Service Worker.

## Validación CLI

```powershell
docker compose exec app php `
  /var/www/html/tools/validate_dashboard_api.php
```

Resultado esperado:

```text
=== FASE 6B · DASHBOARD AGGREGATE API ===
API: 6B.1
Contrato KPI: 6A.1
...
PASS · La integridad KPI de Fase 6A permanece PASS
PASS · Breakdown de estados = avisos totales
PASS · Breakdown de origen = avisos totales
PASS · Breakdown de piezas = piezas desembarcadas
...
RESULTADO GLOBAL: PASS
```

Filtros de ejemplo:

```powershell
docker compose exec app php `
  /var/www/html/tools/validate_dashboard_api.php `
  --origin=historical `
  --status=presented
```

JSON completo:

```powershell
docker compose exec app php `
  /var/www/html/tools/validate_dashboard_api.php `
  --json
```

## Validación HTTP

Con una sesión interna activa en el navegador:

```text
/api/desembarques/dashboard/summary.php
```

Ejemplo:

```text
/api/desembarques/dashboard/summary.php?origin=historical&aviso_status=presented
```

## Criterio de cierre 6B

- `validate_dashboard_api.php` = PASS.
- Endpoint HTTP = 200 para admin/usuario.
- Cliente = 403.
- Filtro inválido = 422.
- `qa/all` solicitado por usuario = 403.
- Integridad 6A rota = 409 fail-closed.
- JSON y validador 6A reportan las mismas cifras base.

Al cerrar 6B se inicia Fase 6C: dashboard visual Corporate Premium consumiendo exclusivamente este endpoint.
