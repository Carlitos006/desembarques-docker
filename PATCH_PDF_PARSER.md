# Corrección del parser de partidas de pedimento

## Archivos incluidos

- `table/parse.php`: reemplazo del parser actual.

## Instalación

1. Haz una copia de seguridad de `table/parse.php`.
2. Copia el archivo incluido a `C:\Dev\desembarques\table\parse.php` y acepta reemplazarlo.
3. No es necesario reconstruir la imagen porque `compose.yml` monta el proyecto como volumen (`./:/var/www/html`).
4. Recarga `http://127.0.0.1:8080/table/index.html` y procesa nuevamente el PDF.

## Ajuste opcional

Para evitar procesar la portada dentro de la extracción de partidas, cambia en `.env`:

```env
PEDIMENTOS_PDF_PAGE_FROM=2
PEDIMENTOS_PDF_PAGE_TO=20
```

Después recrea solamente la aplicación:

```powershell
docker compose up -d --force-recreate app
```

## Resultado esperado con 6004438_pedimento.pdf

- 6 partidas detectadas.
- SEC: 001, 002, 003, 004, 005 y 006.
- Fracción: 56081102 99.
- Descripción: REDES PARA LA PESCA.
- Cantidades UMC: 1642.000, 2196.500, 1855.000, 1035.000, 1529.000 y 1755.000.
