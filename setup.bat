@echo off
setlocal
cd /d "%~dp0"

for %%D in ("storage\tmp" "tools\imagemagick" "tools\ffmpeg" "tools\pandoc" "tools\poppler") do (
    if not exist %%D mkdir %%D
)

echo Pastas do UniversalConverter preparadas.
echo Coloque os executaveis oficiais nas pastas descritas em tools\README.md.
echo Confirme upload_max_filesize=100M, post_max_size=110M e proc_open ativo no php.ini.

where php >nul 2>&1
if errorlevel 1 (
    echo Aviso: PHP nao foi encontrado no PATH.
    echo Execute php cli.php doctor depois de instalar o PHP/XAMPP.
    exit /b 0
)

php cli.php doctor
exit /b %errorlevel%
