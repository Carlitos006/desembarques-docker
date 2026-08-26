# Respaldos locales de Desembarques

El proyecto incluye una automatización para crear respaldos rotativos dentro de
OneDrive empresarial. Cada respaldo contiene:

- `database.sql`: estructura y datos actuales de MySQL.
- `uploads.zip`: fotografías y documentos operativos.
- `aviso-imports.zip`: archivos de importaciones históricas pendientes.
- `source-working-tree.zip`: código confirmado y cambios locales no ignorados.
- `repository.bundle`: historial completo del repositorio Git.
- `git-status.txt`: estado del repositorio al respaldar.
- `manifest.json`: conteos y versión exacta del código.
- `SHA256SUMS.txt`: firmas para verificar integridad.

El archivo `.env` se excluye deliberadamente. Sus credenciales deben guardarse
en un gestor empresarial de contraseñas o en un archivo cifrado independiente.

La tarea diaria tambien sincroniza con la rama `main` del repositorio privado
de GitHub despues de terminar el respaldo de OneDrive. Antes de confirmar los
cambios bloquea `.env`, respaldos, archivos operativos, llaves privadas,
archivos grandes y patrones comunes de secretos. Si GitHub contiene cambios
que requieren integracion manual, conserva el trabajo local y registra el
error sin sobrescribir el repositorio remoto.

## Ejecutar manualmente

Desde PowerShell:

```powershell
Set-Location "C:\Dev\desembarques"
.\scripts\backup-onedrive.ps1
```

Para ejecutar manualmente el respaldo y despues sincronizar GitHub:

```powershell
.\scripts\backup-onedrive.ps1 -SyncGitHub
```

## Instalar la tarea diaria

El horario predeterminado es 19:00:

```powershell
Set-Location "C:\Dev\desembarques"
.\scripts\install-backup-task.ps1
```

Para elegir otro horario:

```powershell
.\scripts\install-backup-task.ps1 -DailyTime "18:30"
```

La tarea sólo puede acceder a OneDrive cuando el usuario tiene una sesión
iniciada. Docker Desktop y el servicio `db` también deben estar activos. Si el
equipo estaba apagado a la hora programada, Windows intentará ejecutar la tarea
al volver a iniciar sesión.

Por cada computadora se conservan solamente dos carpetas: `latest-EQUIPO` y
`previous-EQUIPO`. El respaldo nuevo se construye y valida antes de rotarlas,
por lo que el almacenamiento no crece diariamente y siempre existe una copia
anterior para recuperación.

El resultado de la última ejecución de cada computadora queda registrado en
`last-backup-status-NOMBRE-DE-EQUIPO.json`, dentro de
`OneDrive\DockerBackups\desembarques`.

La sincronización con GitHub mantiene sus propios archivos
`last-github-sync-NOMBRE-DE-EQUIPO.json` y
`github-sync-NOMBRE-DE-EQUIPO.log` en la misma carpeta de respaldos.
