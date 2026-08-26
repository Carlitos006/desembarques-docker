# Aviso de desembarque — Excel → BD → PDF

Implementación preparada para sustituir el generador genérico de `reports.js` por un flujo basado en la plantilla PDF corporativa y datos estructurados del Excel de desembarque.

## Qué cambia

1. El modal **Generar aviso** permite cargar `.xlsx/.xls`.
2. `assets/js/aviso-pdf.js` lee la primera hoja con SheetJS y extrae:
   - manifiesto
   - medio de transporte
   - IMO
   - consignataria
   - fecha de embarque
   - lugar y fecha/ETA de desembarque
   - domicilios de almacenamiento/reparación
   - mercancías: descripción, serial, marca, clave, pedimento, partida y cantidad
3. El importador se relaciona por `clave + número de pedimento` con `desembarque_pedimento_headers`. Si no existe relación, el modal obliga a completar la razón social antes de generar.
4. La información se guarda en `desembarque_aviso_details` y `desembarque_aviso_items`.
5. PDF-lib usa `public/assets/pdf/aviso-desembarque-plantilla.pdf` como fondo real de cada página Letter y dibuja encima el oficio y la tabla de 4 columnas del documento de referencia.
6. La paginación se calcula por altura de texto; no por cantidad fija de mercancías.

## Instalación / actualización de BD

Ejecutar una sola vez en la base de datos del proyecto:

```sql
SOURCE database/migrations/20260818_add_aviso_excel_pdf.sql;
```

En phpMyAdmin también puede abrirse el archivo `database/migrations/20260818_add_aviso_excel_pdf.sql` y ejecutarse completo.

Para una instalación desde cero, `database/schema.sql` ya incluye las dos tablas nuevas.

La misma migración actualiza **solo los defaults heredados conocidos** de `aviso_contacts` (Aduana de Altamira / patente 3908) al perfil que aparece en el PDF de referencia 060-26 (Aduana de Tampico / Javier Gerez Bazan / patente 1948). Si esos contactos ya fueron personalizados con otros valores, la migración no los toca.

## Archivos añadidos

- `database/migrations/20260818_add_aviso_excel_pdf.sql`
- `api/desembarques/aviso/_bootstrap.php`
- `api/desembarques/aviso/load.php`
- `api/desembarques/aviso/save.php`
- `public/assets/js/aviso-pdf.js`
- `public/assets/pdf/aviso-desembarque-plantilla.pdf`

## Archivos modificados

- `api/desembarques/list.php`
  - ahora expone también `cve_pedimento`, `razon_social`, `fecha_entrada` y `fecha_pago` de cada encabezado de pedimento.
- `public/reportes.php`
  - nuevo bloque de carga/vista previa del Excel;
  - captura de rig, IMO, campo, área, comitente y código del oficio;
  - carga PDF-lib y el generador dedicado;
  - configura endpoints del aviso y la plantilla PDF.
- `public/assets/js/reports.js`
  - el botón de aviso carga/restaura datos estructurados;
  - importa Excel y relaciona importadores;
  - persiste el snapshot del aviso;
  - `generateAvisoPdf()` delega a `AvisoPdfGenerator` en lugar de reconstruir un A4 genérico con jsPDF.
- `database/schema.sql`
  - incluye las tablas nuevas para instalaciones limpias.
- `public/service-worker.js`
  - sube el caché estático a `v5` y precarga el generador/plantilla para evitar que una versión vieja de `reports.js` interfiera después del despliegue.

## Flujo de uso

1. Ir a **Reportes**.
2. Pulsar **Generar aviso** en el desembarque.
3. Cargar el Excel de desembarque.
4. Revisar el manifiesto, embarcación/IMO y mercancías detectadas.
5. Verificar la razón social de cada pedimento. Si no se encontró automáticamente, capturarla.
6. Completar/revisar los campos del rig y el texto legal.
7. Pulsar **Generar**.
8. El sistema guarda primero el Excel normalizado + snapshot del oficio y después descarga el PDF.

## Alcance de esta entrega

Se reproduce el **oficio, la tabla principal, continuación multipágina, relación de documentos y firma** sobre la plantilla original. El anexo fotográfico de las páginas 3–4 del PDF de muestra no se genera todavía porque las fotografías no vienen en el Excel; conviene resolverlo posteriormente vinculando `desembarque_files` a mercancías específicas.

## Validaciones realizadas

- `php -l` OK en `public/reportes.php`, `api/desembarques/list.php` y los tres archivos nuevos de `api/desembarques/aviso/`.
- `node --check` OK en `public/assets/js/reports.js` y `public/assets/js/aviso-pdf.js`.
- La copia de la plantilla incluida en el proyecto conserva exactamente el SHA-256 del PDF proporcionado: `07c8161d0ec33afcf0370fbbfdf9cb2ad9b4ccfd79393782264b48947468750f`.

## Prueba funcional recomendada

Después de aplicar la migración, probar primero con `base desembarques.xlsx`. Deben detectarse 8 mercancías y dos grupos de pedimento principales (A1 y BH). Si las razones sociales no existen todavía en `desembarque_pedimento_headers`, capturarlas en el modal; quedarán guardadas en los renglones del aviso.
