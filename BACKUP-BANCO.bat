@echo off
setlocal
chcp 65001 >nul
cd /d "%~dp0"
set "POKEANYA_PHP_EXE="
for /f "delims=" %%P in ('where php.exe 2^>nul') do if not defined POKEANYA_PHP_EXE set "POKEANYA_PHP_EXE=%%P"
if not defined POKEANYA_PHP_EXE (
    echo PHP nao encontrado.
    pause
    exit /b 1
)
for %%P in ("%POKEANYA_PHP_EXE%") do set "POKEANYA_PHP_DIR=%%~dpP"
"%POKEANYA_PHP_EXE%" -n -d extension_dir="%POKEANYA_PHP_DIR%ext" -d extension=pdo_sqlite server/database.php backup
set "POKEANYA_BACKUP_RESULT=%ERRORLEVEL%"
pause
exit /b %POKEANYA_BACKUP_RESULT%
