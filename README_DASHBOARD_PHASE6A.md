# Fase 6A — Dashboard Data Contract & KPI Foundation

## Objetivo

Congelar **qué significa cada número** antes de construir gráficas. Esta fase no agrega todavía el Dashboard visual; agrega el contrato numérico, el scope production/QA y un validador reproducible contra MySQL.

## Reglas congeladas

1. **Un Aviso cuenta una sola vez.** Las versiones `v001`, `v002`, etc. se contabilizan aparte.
2. **Histórico** = `desembarque_aviso_details.source_type = historical_import`.
3. **Sistema** = cualquier otro origen del expediente.
4. **Fecha canónica del Dashboard** = `office_date`; si no existe, `fecha_desembarque`.
5. **Renglones de mercancía ≠ piezas.** Un renglón con cantidad 7 cuenta como 1 renglón y 7 piezas.
6. **Piezas exportadas** = suma de `quantity_exported` de movimientos **no anulados**.
7. **Piezas almacenadas** = piezas originales − piezas exportadas.
8. `ALMACENADA / PARCIAL / EXPORTADA` se deriva matemáticamente; no se captura para el Dashboard.
9. **Pedimento único** se normaliza por clave + número; `pedimento_links` conserva la cantidad de relaciones aviso/pedimento.
10. Los reportes directivos usan `record_scope = production` por defecto.

## Cambio de BD

La migración agrega únicamente:

```text
desembarques.record_scope VARCHAR(20) NOT NULL DEFAULT 'production'
```

y un índice `(record_scope, fecha_desembarque)`.

No elimina ni modifica datos de negocio existentes.

### Aplicación

```powershell
docker compose cp `
  .\database\migrations\20260820_dashboard_phase6a.sql `
  db:/tmp/20260820_dashboard_phase6a.sql

docker compose exec db mysql -uroot -p desembarques
```

Dentro de MySQL:

```sql
SOURCE /tmp/20260820_dashboard_phase6a.sql;
```

## Marcar fixtures o pruebas como QA

La migración **no adivina** qué registro es prueba. Todos los existentes permanecen `production` hasta que tú decidas lo contrario.

Ejemplo controlado:

```sql
UPDATE desembarques
SET record_scope = 'qa'
WHERE id = TU_ID_A_CONFIRMAR;
```

Para regresarlo:

```sql
UPDATE desembarques
SET record_scope = 'production'
WHERE id = TU_ID_A_CONFIRMAR;
```

Nunca es necesario borrar el expediente para excluirlo del Dashboard directivo.

## Validador de KPIs

Después de copiar el parche:

```powershell
docker compose exec app php /var/www/html/tools/validate_dashboard_kpis.php
```

El comando usa únicamente `record_scope=production` por defecto.

Opciones:

```powershell
# Incluir QA para diagnóstico
docker compose exec app php /var/www/html/tools/validate_dashboard_kpis.php --scope=all

# Sólo históricos
docker compose exec app php /var/www/html/tools/validate_dashboard_kpis.php --origin=historical

# Sólo sistema
docker compose exec app php /var/www/html/tools/validate_dashboard_kpis.php --origin=system

# Periodo
docker compose exec app php /var/www/html/tools/validate_dashboard_kpis.php --from=2026-01-01 --to=2026-12-31

# Cliente
docker compose exec app php /var/www/html/tools/validate_dashboard_kpis.php --client=1

# JSON
docker compose exec app php /var/www/html/tools/validate_dashboard_kpis.php --json
```

## KPIs del contrato 6A

### Avisos
- Avisos totales
- Históricos
- Sistema
- Borradores
- Emitidos
- Presentados
- Reemplazados
- Cancelados
- Versiones PDF

### Mercancía
- Renglones
- Piezas desembarcadas
- Piezas exportadas
- Piezas en almacén
- Renglones almacenados
- Renglones parciales
- Renglones exportados

### Dimensiones
- Pedimentos únicos
- Vínculos aviso/pedimento
- Clientes activos
- Rigs/proyectos activos

### Tiempos
- Promedio desembarque → fecha del aviso
- Promedio fecha del aviso → primera presentación

### Antigüedad de mercancía en almacén
- 0–30 días
- 31–60 días
- 61–90 días
- +90 días

## Checks automáticos

El validador debe terminar en `RESULTADO GLOBAL: PASS` y verifica:

```text
Avisos = históricos + sistema
Avisos = suma de estados documentales
Renglones = almacenados + parciales + exportados
Piezas originales = exportadas + almacenadas
Ningún renglón tiene sobreexportación
```

Si aparece un `FAIL`, **no debemos construir encima de esa cifra**; se corrige el dato o la definición primero.

## Consultas manuales

Se incluye:

```text
database/queries/20260820_dashboard_phase6a_manual_checks.sql
```

para contrastar las cifras directamente desde MySQL/phpMyAdmin.

## Qué NO hace todavía 6A

- No crea gráficas.
- No reemplaza `analytics.php`.
- No modifica Reportes.
- No expone una nueva API pública.
- No cambia permisos.

Eso se hará en **6B — API agregada del Dashboard** después de validar estas cifras en local y producción.
