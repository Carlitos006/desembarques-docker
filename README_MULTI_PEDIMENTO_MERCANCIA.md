# Hardening transversal — Múltiples pedimentos por mercancía

## Problema resuelto
El Aviso 028-26 demuestra que un mismo renglón de mercancía puede estar relacionado con varios pedimentos. El caso `FUNDAS / SOFT COVER` contiene 92 piezas y seis pedimentos BH/R1. El modelo anterior sólo admitía `clave + num_pedimento` singular y por eso la UI dejaba ambos campos vacíos.

## Nuevo contrato
- `desembarque_aviso_items` continúa siendo la mercancía original.
- `desembarque_aviso_item_pedimentos` representa la relación 1:N entre mercancía y pedimentos.
- Las columnas legacy `clave` y `num_pedimento` se conservan:
  - si hay exactamente 1 relación, se reflejan allí por compatibilidad;
  - si hay más de 1, quedan NULL/vacías y la fuente autoritativa es la tabla hija.
- Los pedimentos globales del Aviso siguen guardándose en `desembarque_pedimento_headers`.

## Componentes actualizados
- extractor histórico DOC/DOCX;
- parser global de claves, incluyendo `BH/R1`;
- revisión de importación histórica;
- commit transaccional;
- guardado/carga del Aviso;
- expediente y Finalización V2;
- generador PDF del Aviso;
- generador PDF de Alcances;
- Dashboard KPI de pedimentos;
- Service Worker.

## Instalación
1. Copia los archivos del parche.
2. Aplica **antes de abrir el expediente/importador**:

```sql
SOURCE database/migrations/20260821_item_multi_pedimentos.sql;
```

3. Reinicia la app local si aplica:

```powershell
docker compose restart app
```

4. Fuerza recarga del navegador. El cache pasa a `v27`.

## Importación del 028-26
Los lotes ya analizados conservan el JSON anterior en staging. Después de instalar el parche crea **un lote nuevo** con el PDF + DOCX 028-26.

Resultado esperado:
- 3 renglones;
- 94 piezas;
- 8 pedimentos globales;
- renglón `FUNDAS / SOFT COVER`: 6 pedimentos `BH/R1`.

## Validación

```powershell
docker compose exec app php /var/www/html/tools/validate_item_pedimentos.php
```

Debe terminar en `RESULTADO GLOBAL: PASS`.

## Fixtures validados durante el desarrollo

- `028-26`: PASS — 3 renglones, 94 piezas, 8 pedimentos globales; `FUNDAS / SOFT COVER` conserva 6 relaciones `BH/R1`.
- `023-26`: PASS — 2 renglones, 197 piezas, 2 pedimentos; sin regresión del parser DOCX estructurado.

## Producción
La migración es aditiva. No importes un dump local sobre producción. Ejecuta únicamente `20260821_item_multi_pedimentos.sql` después de subir el código.
