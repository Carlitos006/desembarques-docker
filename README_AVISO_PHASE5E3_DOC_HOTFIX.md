# Fase 5E3.1 — Hotfix Word `.doc` histórico

## Motivo

Los Word históricos reales de los Avisos 017-26 a 021-26 están en formato binario Microsoft Word 97-2003 (`.doc` / OLE2), no en `.docx`.
El importador 5E3 sólo conocía `.docx`, por lo que los `.doc` se trataban incorrectamente como si fueran PDF escaneados y terminaban en filas separadas `needs_companion`.

También se corrigió el warning de `$defaultAvisoStatus` dentro del closure que crea las filas del lote.

## Cambios

- Acepta PDF + DOC + DOCX.
- Empareja automáticamente `AVISO DESEMBARQUE 020-26.pdf` con `FORMATO DE DESEMBARQUE ......020-26.doc` usando el número del aviso.
- Los `.doc` se leen con `antiword` usando salida DocBook para conservar la separación de las 4 columnas del oficio.
- La tabla fotográfica de 3 columnas se excluye del parser para no duplicar mercancías/piezas.
- Se extraen de los Word antiguos: MADE, fecha, Rig, IMO Rig, campo, área, comitente/importador, transporte, IMO transporte, consignataria, fechas, pedimentos, mercancías, cantidades, seriales y partidas cuando están presentes.
- Se conserva el PDF escaneado como documento oficial histórico; Word sólo alimenta la extracción.
- Un renglón histórico amparado por varios pedimentos puede conservarse sin forzar una relación falsa 1:1 entre mercancía y pedimento.
- Service Worker v17.

## Docker

Este hotfix **sí modifica Dockerfile** porque instala `antiword`. Hay que reconstruir `app` una vez:

```powershell
cd C:\Dev\desembarques
docker compose up -d --build app
```

No usar `down -v`.

Después verificar:

```powershell
docker compose exec app antiword -h
docker compose ps
```

## Prueba recomendada

Crear un lote nuevo con los 10 archivos del ZIP:

- PDF + DOC de 017-26
- PDF + DOC de 018-26
- PDF + DOC de 019-26
- PDF + DOC de 020-26
- PDF + DOC de 021-26

Resultado esperado: **5 avisos detectados**, no 10.

Validación realizada con los archivos reales entregados:

| Aviso | PDF | Word | Renglones | Piezas | Pedimentos |
|---|---|---|---:|---:|---:|
| 017-26 | scan | `.doc` | 1 | 7 | 2 |
| 018-26 | scan | `.doc` | 3 | 3 | 2 |
| 019-26 | scan | `.doc` | 3 | 6 | 2 |
| 020-26 | scan | `.doc` | 3 | 3 | 2 |
| 021-26 | scan | `.doc` | 2 | 2 | 2 |

En 017-26 el único renglón de 7 tanques está amparado por dos pedimentos. El sistema conserva el renglón como uno solo y los dos pedimentos a nivel del aviso, en lugar de inventar dos mercancías.

## Diagnóstico local

Dentro del contenedor:

```bash
php tools/test_historical_word_doc.php "/ruta/al/FORMATO DE DESEMBARQUE ......021-26.doc"
```
