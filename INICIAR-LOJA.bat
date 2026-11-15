@echo off
setlocal
cd /d "%~dp0"
set "POKEANYA_PHP_EXE="
for /f "delims=" %%P in ('where php.exe 2^>nul') do if not defined POKEANYA_PHP_EXE set "POKEANYA_PHP_EXE=%%P"
if not defined POKEANYA_PHP_EXE (
    echo PHP nao encontrado. Instale PHP 8.2 ou mais recente e adicione-o ao PATH.
    pause
    exit /b 1
)
for %%P in ("%POKEANYA_PHP_EXE%") do set "POKEANYA_PHP_DIR=%%~dpP"
"%POKEANYA_PHP_EXE%" -n -d extension_dir="%POKEANYA_PHP_DIR%ext" -d extension=pdo_sqlite -r "exit(extension_loaded('pdo_sqlite')  ? 0 : 1);"
if errorlevel 1 (
    echo Nao foi possivel carregar PDO SQLite do PHP.
    echo Confira a pasta ext da instalacao do PHP.
    pause
    exit /b 1
)
if not defined POKEANYA_AUTH_CONFIG if exist "%LOCALAPPDATA%\PokeAnyaStore\auth.config.php" set "POKEANYA_AUTH_CONFIG=%LOCALAPPDATA%\PokeAnyaStore\auth.config.php"
"%POKEANYA_PHP_EXE%" -n -d extension_dir="%POKEANYA_PHP_DIR%ext" -d extension=pdo_sqlite server/database.php init
if errorlevel 1 (
    echo Confira a pasta privada configurada para os bancos de dados.
    pause
    exit /b 1
)
echo.
echo PokeAnya Store - servidor local com loja publica e painel protegido
echo Loja:   http://127.0.0.1:8000
echo Conta:  http://127.0.0.1:8000/conta.html
echo Painel: http://127.0.0.1:8000/admin.html
echo Primeiro acesso administrativo: execute CONFIGURAR-ADMIN.bat.
echo.
echo Use os enderecos acima; o Live Server nao executa o login real.
echo Mantenha esta janela aberta. Ctrl+C encerra o servidor.
echo.
"%POKEANYA_PHP_EXE%" -n -d extension_dir="%POKEANYA_PHP_DIR%ext" -d extension=pdo_sqlite -S 127.0.0.1:8000 server/router.php
pause
