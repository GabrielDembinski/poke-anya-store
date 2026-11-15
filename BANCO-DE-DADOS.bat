@echo off
setlocal
chcp 65001 >nul
cd /d "%~dp0"
set "POKEANYA_PHP_EXE="
for /f "delims=" %%P in ('where php.exe 2^>nul') do if not defined POKEANYA_PHP_EXE set "POKEANYA_PHP_EXE=%%P"
if not defined POKEANYA_PHP_EXE (
    echo PHP nao encontrado. Use a mesma instalacao do INICIAR-LOJA.bat.
    pause
    exit /b 1
)
for %%P in ("%POKEANYA_PHP_EXE%") do set "POKEANYA_PHP_DIR=%%~dpP"
echo.
echo PokeAnya Store - bancos de dados compartilhados
echo Inicializar preserva as contas e os registros existentes.
echo.
"%POKEANYA_PHP_EXE%" -n -d extension_dir="%POKEANYA_PHP_DIR%ext" -d extension=pdo_sqlite server/database.php init
if errorlevel 1 (
    pause
    exit /b 1
)
"%POKEANYA_PHP_EXE%" -n -d extension_dir="%POKEANYA_PHP_DIR%ext" -d extension=pdo_sqlite server/database.php status
echo.
echo Para um backup privado, execute BACKUP-BANCO.bat.
pause
