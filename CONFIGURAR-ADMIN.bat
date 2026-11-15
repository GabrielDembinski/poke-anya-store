@echo off
setlocal
chcp 65001 >nul
cd /d "%~dp0"
set "POKEANYA_SETUP_ROOT=%~dp0"
echo.
echo PokeAnya Store - configurar o administrador unico
echo Defina os dados do responsavel pela loja. A senha ficara oculta.
echo Use de 12 a 128 caracteres e guarde sua senha em local seguro.
echo Uma conta existente nao sera substituida.
echo.
powershell.exe -NoProfile -Command "$ErrorActionPreference='Stop'; $b1=[IntPtr]::Zero; $b2=[IntPtr]::Zero; $result=1; $previousInputEncoding=[Console]::InputEncoding; try { $php=(Get-Command php.exe -ErrorAction Stop).Source; $adminName=Read-Host 'Nome do administrador'; $adminEmail=Read-Host 'E-mail de acesso'; $s1=Read-Host 'Senha' -AsSecureString; $s2=Read-Host 'Confirme a senha' -AsSecureString; $b1=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($s1); $b2=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($s2); $data=@{name=$adminName;email=$adminEmail;password=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($b1);passwordConfirmation=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($b2)}; $payload=$data | ConvertTo-Json -Compress; $info=New-Object Diagnostics.ProcessStartInfo; $info.FileName=$php; $q=[char]34; $ext=Join-Path (Split-Path -Parent $php) 'ext'; $info.Arguments='-n -d '+$q+'extension_dir='+$ext+$q+' -d extension=pdo_sqlite server/configure-admin.php --stdin-json'; $info.WorkingDirectory=$env:POKEANYA_SETUP_ROOT; $info.UseShellExecute=$false; $info.CreateNoWindow=$true; $info.RedirectStandardInput=$true; $info.RedirectStandardOutput=$true; $info.RedirectStandardError=$true; $info.StandardOutputEncoding=[Text.Encoding]::UTF8; $info.StandardErrorEncoding=[Text.Encoding]::UTF8; $defaultConfig=Join-Path $env:LOCALAPPDATA 'PokeAnyaStore\auth.config.php'; if (-not $env:POKEANYA_AUTH_CONFIG -and (Test-Path -LiteralPath $defaultConfig -PathType Leaf)) { $info.EnvironmentVariables['POKEANYA_AUTH_CONFIG']=$defaultConfig }; $process=New-Object Diagnostics.Process; $process.StartInfo=$info; [Console]::InputEncoding=New-Object Text.UTF8Encoding($false); [void]$process.Start(); $process.StandardInput.Write($payload); $process.StandardInput.Close(); $output=$process.StandardOutput.ReadToEnd(); $problem=$process.StandardError.ReadToEnd(); $process.WaitForExit(); if ($output) { Write-Host $output.Trim() }; if ($problem) { Write-Host $problem.Trim() }; $result=$process.ExitCode; $process.Dispose() } catch { Write-Host 'Nao foi possivel configurar o acesso. Confira o PHP e a pasta privada do projeto.' } finally { [Console]::InputEncoding=$previousInputEncoding; if ($b1 -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b1) }; if ($b2 -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b2) }; if ($s1) { $s1.Dispose() }; if ($s2) { $s2.Dispose() }; $data=$null; $payload=$null }; exit $result"
set "POKEANYA_SETUP_RESULT=%ERRORLEVEL%"
echo.
pause
exit /b %POKEANYA_SETUP_RESULT%
