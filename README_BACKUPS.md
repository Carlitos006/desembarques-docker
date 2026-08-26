# Respaldos locales de Desembarques

El proyecto incluye una automatización para crear respaldos fechados dentro de
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

## Ejecutar manualmente

Desde PowerShell:

```powershell
Set-Location "C:\Dev\desembarques"
.\scripts\backup-onedrive.ps1
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

El resultado de la última ejecución de cada computadora queda registrado en
`last-backup-status-NOMBRE-DE-EQUIPO.json`, dentro de
`OneDrive\DockerBackups\desembarques`. Las carpetas fechadas también incluyen
el nombre del equipo para evitar conflictos si las dos computadoras respaldan
al mismo tiempo.
