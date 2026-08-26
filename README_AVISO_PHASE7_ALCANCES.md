# Fase 7 - Alcances del Aviso de Desembarque

Implementa Alcances como documentos hijos del Aviso original.

## Reglas funcionales

- El botón **Generar alcance** vive en `Expediente > Mercancías`.
- Un Alcance selecciona una o varias mercancías existentes; no las elimina ni altera del Aviso original.
- Pueden existir varios Alcances para un mismo Aviso.
- El folio y la fecha/hora del acuse **no se capturan al generar el Alcance**.
- El Alcance se emite en estado `issued` / EMITIDO.
- Cuando la autoridad recibe el documento, se usa **Registrar acuse** para capturar folio, fecha/hora, observaciones y evidencia opcional PDF/JPG/PNG.
- Al registrar el acuse, el Alcance pasa a `presented` / PRESENTADO.
- Una nueva versión PDF es inmutable y devuelve el Alcance a EMITIDO; el acuse anterior sigue vinculado a la versión anterior.
- Los PDFs se almacenan en storage privado y se verifican por SHA-256 al descargarse.
- Generar un Alcance no cambia el estado de almacenamiento/exportación de la mercancía; Finalización y Alcances son procesos independientes.

## Estructura nueva

- `desembarque_aviso_alcances`
- `desembarque_aviso_alcance_items`
- `desembarque_aviso_alcance_versions`
- `desembarque_aviso_alcance_receipts`

## Archivos principales

- `database/migrations/20260821_aviso_alcances_phase7.sql`
- `api/desembarques/aviso/_alcance.php`
- `api/desembarques/aviso/alcance_generate.php`
- `api/desembarques/aviso/alcance_version.php`
- `api/desembarques/aviso/alcance_version_download.php`
- `api/desembarques/aviso/alcance_receipt.php`
- `public/assets/js/alcance-pdf.js`
- `public/assets/js/aviso-expediente-alcances.js`
- `public/aviso-expediente.php`
- `tools/validate_alcances.php`

## PDF

El generador reutiliza el motor `AvisoPdfGenerator` y la plantilla corporativa actual `assets/pdf/aviso-desembarque-plantilla.pdf`.

El ejemplo `ALCANCE 033-26.pdf` se utilizó para el contrato de contenido:

- `SE PRESENTA ALCANCE AL AVISO DE DESEMBARQUE...`
- `Presento Alcance de Aviso de DESEMBARQUE...`
- Sólo las mercancías seleccionadas aparecen en la tabla.
- Los pedimentos del cierre se deduplican a partir de esas mercancías.
- Las fotografías ya relacionadas con las mercancías seleccionadas se reutilizan en el anexo.

El membrete visual será el de la plantilla corporativa actual del proyecto. Si se requiere reproducir exactamente el membrete histórico del ejemplo, se necesita una plantilla PDF vacía de ese formato.

## Instalación

1. Respaldar BD.
2. Copiar el parche conservando rutas.
3. Copiar la migración al contenedor:

```powershell
docker compose cp `
  .\database\migrations\20260821_aviso_alcances_phase7.sql `
  db:/tmp/20260821_aviso_alcances_phase7.sql
```

4. Ejecutar:

```powershell
docker compose exec db mysql -uroot -p desembarques
```

```sql
SOURCE /tmp/20260821_aviso_alcances_phase7.sql;
```

5. Reiniciar aplicación:

```powershell
docker compose restart app
```

6. `Ctrl + Shift + R` (Service Worker `v26`).

## Acceptance

1. Abrir un expediente con mercancías.
2. `Mercancías > Generar alcance`.
3. Seleccionar 1-3 mercancías y generar.
4. Confirmar que:
   - el Aviso original conserva todas las mercancías;
   - aparece la pestaña `Alcances`;
   - Alcance #1 está EMITIDO;
   - v001 descarga y su SHA es válido;
   - el PDF incluye únicamente la mercancía seleccionada.
5. Registrar acuse con folio + fecha/hora después de la recepción por autoridad.
6. Confirmar estado PRESENTADO.
7. Generar Nueva versión:
   - v001 permanece;
   - se crea v002;
   - estado vuelve a EMITIDO;
   - el acuse de v001 permanece histórico.
8. Registrar acuse de v002.
9. Ejecutar:

```powershell
docker compose exec app php /var/www/html/tools/validate_alcances.php
```

Resultado esperado: `RESULTADO GLOBAL: PASS`.
