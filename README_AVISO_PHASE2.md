# Desembarques — Fase 2 Aviso simplificado + perfiles Rig/Proyecto

## Objetivo

Reducir el flujo `Reportes → Generar aviso` para que un desembarque Excel-first no vuelva a solicitar el Excel ni obligue al usuario a editar el texto legal completo en cada emisión.

La pantalla principal del Aviso ahora se concentra en:

1. Datos fuente ya guardados desde el alta.
2. Perfil Rig / Proyecto.
3. Código, número y fecha del oficio.
4. Validación de que existan mercancías, importadores y datos del Rig.
5. Generación del PDF.

El Excel de reemplazo, importadores faltantes y textos legales completos quedan dentro de **Opciones avanzadas / migración legacy**.

## Cambios principales

### Perfiles Rig / Proyecto

Nueva tabla `aviso_profiles` con:

- alcance global o por cliente;
- Rig / buque de perforación;
- IMO del Rig;
- campo;
- área del Rig;
- comitente;
- contactos legales destinatario/firmante;
- prefijo de oficio;
- perfil predeterminado por cliente.

La primera vez que se guarda un perfil para un cliente desde el modal, se convierte automáticamente en el perfil predeterminado de ese cliente. Los perfiles siguientes no reemplazan el predeterminado.

Se incluye un perfil **global, no predeterminado**, basado exclusivamente en el PDF 060-26 usado como referencia de QA:

- `DEEPWATER THALASSA`
- IMO `9675169`
- Campo `TRION`
- Área `CUBIERTA`
- Comitente `GLOBALSANTAFE DRILLING MEXICO, S. DE R.L. DE C.V.`

Al ser global y no predeterminado, nunca se aplica automáticamente a un cliente.

### Nuevo endpoint

`api/desembarques/aviso/profiles.php`

- `GET`: devuelve perfiles globales y del cliente del desembarque.
- `POST`: usuarios internos pueden guardar el Rig/comitente actual como perfil reutilizable del cliente.
- perfiles globales sólo pueden ser creados por `admin`.

### Snapshot del perfil

`desembarque_aviso_details.profile_id` registra qué perfil sirvió de base, pero el Aviso sigue guardando `rig_name`, `rig_imo`, `rig_field`, `rig_area`, `comitente` y los textos legales como snapshot.

Por ello, cambiar un perfil en el futuro no modifica un Aviso histórico ya guardado.

### Modal simplificado

El modal principal ya no muestra de inicio:

- selector de Excel;
- importadores por pedimento;
- título legal completo;
- introducción;
- cuerpo;
- operaciones;
- listado textual de mercancías;
- documentación;
- cierre;
- firma editable.

Todo lo anterior permanece disponible en el acordeón **Opciones avanzadas / migración legacy**.

### Registros legacy

Si un registro todavía no tiene `desembarque_aviso_items`, el sistema abre automáticamente las opciones avanzadas y solicita cargar el Excel una sola vez.

Los registros Excel-first muestran directamente la información guardada y no vuelven a pedir el archivo.

### Validación antes del PDF

Se verifica:

- datos estructurados de mercancías;
- razones sociales de importadores;
- nombre del Rig;
- IMO del Rig;
- campo;
- comitente.

El PDF no se genera mientras falte alguno de esos datos.

### Corrección del destinatario

Se corrigió el prefijo duplicado que podía producir `C. C. MTRO...` cuando el contacto ya incluía `C.` en su nombre.

### Fecha del oficio

Cuando el Aviso todavía no tiene una fecha legal guardada, se toma como valor inicial `FECHA DE EMBARQUE` del Excel estructurado. El usuario puede modificarla desde el campo visible si el oficio requiere otra fecha.

## Instalación

### 1. Copiar el parche

Reemplaza los archivos conservando sus rutas.

### 2. Aplicar la migración

Desde PowerShell:

```powershell
docker compose cp `
  .\database\migrations\20260818_aviso_profiles_phase2.sql `
  db:/tmp/20260818_aviso_profiles_phase2.sql

docker compose exec db mysql -uroot -p desembarques
```

Dentro de MySQL:

```sql
SOURCE /tmp/20260818_aviso_profiles_phase2.sql;
```

Verifica:

```sql
SHOW TABLES LIKE 'aviso_profiles';
DESCRIBE desembarque_aviso_details;
SELECT id, name, client_id, rig_name, rig_imo, rig_field, is_default
FROM aviso_profiles;
```

### 3. Docker

No cambió `Dockerfile` ni Composer. No es necesario hacer build.

```powershell
docker compose restart app
```

### 4. Navegador

El Service Worker pasa de `v6` a `v7` y `reports.js` entra al precache.

Haz una recarga fuerte después de la primera carga:

```text
Ctrl + Shift + R
```

## Prueba recomendada

1. Abre `Reportes`.
2. En el registro Excel-first, pulsa `Generar aviso`.
3. Debe aparecer `Excel listo · 8 mercancías` sin pedir nuevamente el Excel.
4. Selecciona `Deepwater Thalassa · Trion` para la prueba 060-26.
5. Verifica:
   - Rig: `DEEPWATER THALASSA`
   - IMO: `9675169`
   - Campo: `TRION`
   - Área: `CUBIERTA`
   - Comitente: `GLOBALSANTAFE DRILLING MEXICO, S. DE R.L. DE C.V.`
6. Debe aparecer la validación verde `Listo`.
7. Genera el PDF.
8. Para convertir esos valores en perfil propio de Woodside, usa `Guardar actual como perfil` y asigna un nombre. Al ser el primer perfil de ese cliente quedará predeterminado.

## Archivos de esta fase

- `database/migrations/20260818_aviso_profiles_phase2.sql`
- `api/desembarques/aviso/profiles.php`
- `api/desembarques/aviso/save.php`
- `public/reportes.php`
- `public/assets/js/reports.js`
- `public/service-worker.js`

## Fuera de alcance de esta fase

- administración completa CRUD de perfiles desde un módulo independiente;
- asociación visual de fotografías a mercancías;
- anexo fotográfico automático;
- eliminación física del parser PDF legacy;
- cambio de backend de PDF-lib a otro motor.

Esos puntos pueden abordarse después de validar el PDF 060-26 con el flujo simplificado.
