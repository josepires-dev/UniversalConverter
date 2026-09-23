[CmdletBinding()]
param(
    [switch]$SkipDoctor
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $Root

$directories = @(
    'storage\tmp',
    'tools\imagemagick',
    'tools\ffmpeg',
    'tools\pandoc',
    'tools\poppler'
)

foreach ($directory in $directories) {
    $path = Join-Path $Root $directory
    New-Item -ItemType Directory -Force -Path $path | Out-Null
}

Write-Host 'Pastas do UniversalConverter preparadas.' -ForegroundColor Green
Write-Host 'Coloque os executáveis oficiais nas pastas descritas em tools\README.md.'
Write-Host 'Confirme upload_max_filesize=100M, post_max_size=110M e proc_open ativo no php.ini.'

if (-not $SkipDoctor) {
    if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
        Write-Warning 'PHP não foi encontrado no PATH. Execute php cli.php doctor depois de instalar o PHP/XAMPP.'
        exit 0
    }
    & php (Join-Path $Root 'cli.php') doctor
    if ($LASTEXITCODE -ne 0) {
        Write-Warning 'O diagnóstico encontrou dependências pendentes. Consulte tools\README.md.'
        exit $LASTEXITCODE
    }
}
