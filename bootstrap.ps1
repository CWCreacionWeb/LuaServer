<#
============================================================
 lua-server :: bootstrap  (sin interfaz, instala todo)
 Descarga y prepara TODOS los binarios del catalogo (Apache +
 PHP 7.1-8.5 + MariaDB + Mailpit + mkcert + Composer +
 phpMyAdmin) en esta carpeta. Pensado para automatizar / CI o
 para quien prefiera terminal a interfaz grafica.

   powershell -ExecutionPolicy Bypass -File .\bootstrap.ps1

 Si prefieres elegir que instalar (versiones de PHP, MariaDB,
 Mailpit, HTTPS, phpMyAdmin) con un asistente grafico, usa en su
 lugar:
   .\install.ps1

 Al terminar:  .\lua.ps1 start   y abre http://localhost
============================================================
#>
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$root = $PSScriptRoot
$dl   = Join-Path $root "downloads"

function Say($m){ Write-Host "[bootstrap] $m" -ForegroundColor Cyan }

$dirs = @("bin\apache","bin\php","www","config\apache\vhosts","config\apache\templates","logs\apache","logs\php","tools\dashboard","downloads","tmp")
foreach ($d in $dirs) { New-Item -ItemType Directory -Force -Path (Join-Path $root $d) | Out-Null }

. (Join-Path $root "config\install-lib.ps1")

$items = Sort-CatalogItems (Get-InstallCatalog)

foreach ($it in $items) {
    Say "descargando $($it.Label)..."
    Invoke-CatalogDownload -Item $it -DownloadsDir $dl | Out-Null
}

foreach ($it in $items) {
    Say "instalando $($it.Label)..."
    Install-CatalogItem -Item $it -Root $root -DownloadsDir $dl
}

Say "aplicando configuracion (lua.ps1 init)..."
& (Join-Path $root "lua.ps1") init

Say "registrando phpMyAdmin como sitio..."
Register-PhpMyAdminSite -Root $root

Write-Host ""
Write-Host "[bootstrap] LISTO. Ejecuta:  .\lua.ps1 start   y abre http://localhost" -ForegroundColor Green
Write-Host "[bootstrap] Si Apache/PHP no arrancan por DLL faltante, instala downloads\vc_redist.x64.exe" -ForegroundColor Yellow
