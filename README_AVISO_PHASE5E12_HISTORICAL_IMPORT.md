# Fase 5E1 / 5E2 — Importación histórica: foundation + extractor dual

Esta fase inicia la migración masiva de Avisos ya realizados sin volver a ejecutar el flujo Excel-first.

## Principio de seguridad

**5E1/5E2 no crea desembarques definitivos.** Sólo crea un lote de staging y una bandeja de revisión. La escritura definitiva en `desembarques`, `desembarque_aviso_details`, mercancías, pedimentos y `v001` histórica se conectará en 5E3 después de validar el extractor con documentos reales.

## Fuentes admitidas

- PDF con capa de texto: se extrae con `pdftotext` y fallback a `smalot/pdfparser`.
- PDF escaneado: se detecta automáticamente.
- DOCX: puede acompañar a un PDF escaneado. Se usa únicamente para **extraer datos**, mientras el PDF final seguirá siendo el documento histórico original.

Los archivos se emparejan por número de Aviso/manifiesto (`061-26`, `060-26`, etc.) y, como fallback, por nombre de archivo.

## Caso de referencia: AVISO DESEMBARQUE 061-26.pdf

El PDF recibido es un escaneo de 3 páginas sin capa de texto. En esta fase debe clasificarse como:

- Aviso detectado por nombre: `061-26`
- Código sugerido: `MADE-061-26`
- `is_scanned_pdf = 1`
- Estado: `needs_companion`
- Mensaje: añadir el Word original o habilitar OCR en una fase posterior

Si se añade un DOCX del mismo `061-26` al mismo lote, el sistema usa el Word como fuente de extracción y mantiene el PDF como original histórico.

## Caso de referencia con PDF textual: 060-26

El extractor ya identifica de forma conservadora:

- `MADE-060-26`
- fecha oficio `2026-07-28`
- Rig `DEEPWATER THALASSA`
- IMO Rig `9675169`
- campo `TRION`
- comitente `GLOBALSANTAFE DRILLING MEXICO, S. DE R.L. DE C.V.`
- pedimentos A1 `20 81 3501 0000120` y BH `26 81 1948 6000314`
- fecha embarque `2026-07-28`
- desembarque/ETA `2026-07-29 08:00`
- firmante `Javier Gerez Bazan / Patente 1948`

La extracción de columnas complejas de tablas se marca para revisión cuando `pdftotext -layout` entrelaza contenido. El objetivo es **no inventar ni aprobar datos contaminados**. Un DOCX compañero mejora esos campos.

## Tablas nuevas

- `aviso_import_batches`
- `aviso_import_rows`

Los archivos temporales quedan fuera de `public/` en:

`storage/aviso-imports/<batch_public_id>/`

## Instalación

```powershell
docker compose cp `
  .\database\migrations\20260819_historical_import_phase5e12.sql `
  db:/tmp/20260819_historical_import_phase5e12.sql

docker compose exec db mysql -uroot -p desembarques
```

Dentro de MySQL:

```sql
SOURCE /tmp/20260819_historical_import_phase5e12.sql;
```

Luego:

```powershell
docker compose restart app
```

No requiere rebuild de Docker ni cambios de Composer.

El Service Worker cambia de `v14` a `v15`; hacer `Ctrl + Shift + R`.

## Uso

1. Entrar a **Reportes → Importar históricos**.
2. Seleccionar el cliente del sistema.
3. Seleccionar el estado documental sugerido del lote.
4. Cargar hasta 20 PDF/DOCX.
5. Para escaneos, añadir el DOCX original en el mismo lote cuando exista.
6. Pulsar **Analizar lote**.
7. Revisar la bandeja. **No se crea ningún aviso definitivo en esta fase.**

## Estados de staging

- `ready`: suficiente extracción para revisión final.
- `review_required`: datos parciales o campos críticos dudosos.
- `needs_companion`: PDF escaneado sin Word compañero.
- `needs_pdf`: Word sin PDF final.
- `duplicate`: SHA-256 o número de Aviso ya existente para el cliente.

Un duplicado activo sigue bloqueado. Si sólo existe un expediente eliminado con el mismo número, el administrador puede restaurarlo; tanto `admin` como `usuario` pueden autorizar **Importar como aviso nuevo (conservar eliminado)** con motivo obligatorio. El rol `cliente` no tiene acceso. La excepción no aplica cuando el SHA-256 del PDF es idéntico.

## Validación CLI

```powershell
docker compose exec app php tools/test_historical_aviso_parser.php "/var/www/html/ruta/al/archivo.pdf"
```

## Próxima subfase 5E3

- edición de campos en staging;
- confirmación por fila o masiva;
- creación transaccional del expediente histórico completo;
- PDF original como `v001` inmutable con `source_type = historical_import`;
- estado inicial confirmado por el usuario;
- historial y trazabilidad del lote.
