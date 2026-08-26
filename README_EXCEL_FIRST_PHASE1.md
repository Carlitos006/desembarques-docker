# Desembarques — Fase Excel-first 1

## Objetivo

Simplificar el alta de un desembarque para que el Excel sea la fuente operativa principal. El usuario ya no captura manualmente manifiesto, pedimentos, fechas del aviso, descripción, destino ni medio de transporte, y tampoco necesita procesar el PDF del pedimento durante el alta normal.

## Nuevo flujo

1. Seleccionar cliente.
2. El sistema genera la referencia automáticamente.
3. Cargar el Excel de desembarque.
4. Se leen automáticamente manifiesto, transporte, IMO, consignataria, fecha de embarque del aviso, fecha/ETA de desembarque, domicilios y mercancías.
5. Se agrupan los pedimentos y se busca la razón social en el histórico. Si no existe una coincidencia única, el usuario captura únicamente la razón social faltante.
6. CIPL y adjuntos quedan opcionales.
7. Registrar desembarque.
8. El backend guarda en una sola transacción el registro principal, pedimentos/importadores, archivo Excel original y `desembarque_aviso_details` / `desembarque_aviso_items`.
9. Reportes reutiliza esos datos guardados; el campo Excel del modal de Aviso queda como mecanismo de reemplazo/migración para registros legacy.

## Archivos principales modificados

- `public/index.php`
- `public/assets/js/excel-first.js` (nuevo)
- `public/assets/js/reports.js`
- `public/reportes.php`
- `public/service-worker.js`
- `api/desembarques/store.php`
- `api/desembarques/pedimento_importers.php` (nuevo)
- `database/migrations/20260818_excel_first_phase1.sql` (nuevo)

## Importante sobre las fechas

`FECHA DE EMBARQUE` del Excel se guarda en `desembarque_aviso_details.fecha_embarque` porque describe la operación marítima del Aviso. No se copia a `desembarques.fecha_embarque`, ya que el sistema legacy utiliza ese campo para una etapa posterior del expediente. Esto evita mezclar dos significados diferentes.

## Importante sobre Rig vs transporte

El sistema ya no usa `barco`/IMO del medio de transporte como fallback del Rig al abrir el Aviso. El Rig debe venir de un perfil específico o capturarse en el Aviso. Esto evita confundir, por ejemplo, HOS BROWNING (transporte) con DEEPWATER THALASSA (rig).

## Instalación local

### 1. Copiar archivos

Reemplazar los archivos del proyecto con los de esta entrega.

### 2. Aplicar migración

La base local real del proyecto es `desembarques`.

Desde PowerShell:

```powershell
docker compose cp `
  .\database\migrations\20260818_excel_first_phase1.sql `
  db:/tmp/20260818_excel_first_phase1.sql

docker compose exec db mysql -uroot -p desembarques
```

Dentro de MySQL:

```sql
SOURCE /tmp/20260818_excel_first_phase1.sql;
```

Alternativamente se puede ejecutar el archivo desde phpMyAdmin sobre la base `desembarques`.

### 3. Reiniciar app

No cambió `Dockerfile` ni Composer. No es necesario reconstruir la imagen.

```powershell
docker compose restart app
```

### 4. Recargar navegador

El Service Worker sube de `v5` a `v6`. Después del primer acceso hacer `Ctrl + Shift + R` si el navegador mantiene recursos anteriores.

## Prueba esperada con `base desembarques.xlsx`

Debe detectar:

- Manifiesto: `060-26`
- Transporte: `EMBARCACION HOS BROWNING`
- IMO: `9587398`
- 8 mercancías
- Pedimento A1: `20 81 3501 0000120`
- Pedimento BH: `26 81 1948 6000314`
- Fecha de embarque del aviso: `17/08/2026`
- Desembarque / ETA: `24/08/2026 08:00`

Si el histórico no conoce los importadores, el formulario solicitará únicamente sus razones sociales. Una vez guardados, los siguientes registros con el mismo pedimento pueden reutilizarlos automáticamente.

## Compatibilidad legacy

El parser PDF de pedimentos no se eliminó físicamente todavía. Ya no participa en el alta normal y puede conservarse temporalmente para registros históricos. La eliminación definitiva debe hacerse después de validar varios desembarques reales.
