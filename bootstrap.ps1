<#
============================================================
 lua-server :: bootstrap  (solo Apache + PHP)
 Descarga y prepara los binarios (Apache + PHP x6) en esta
 carpeta. Ejecutalo en un PC nuevo tras clonar el repo, o
 para reinstalar los binarios.

   powershell -ExecutionPolicy Bypass -File .\bootstrap.ps1

 Al terminar:  .\lua.ps1 start   y abre http://localhost
============================================================
#>
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$root = $PSScriptRoot
$dl   = Join-Path $root "downloads"
$ua   = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36"

function Say($m){ Write-Host "[bootstrap] $m" -ForegroundColor Cyan }

$dirs = @("bin\apache","bin\php","www","config\apache\vhosts","config\apache\templates","logs\apache","logs\php","tools\dashboard","downloads","tmp")
foreach ($d in $dirs) { New-Item -ItemType Directory -Force -Path (Join-Path $root $d) | Out-Null }

# --- descargas (fuentes oficiales, versiones verificadas 2026-07) ---
$items = @(
  @{ n="vc_redist.x64.exe";                  u="https://aka.ms/vc14/vc_redist.x64.exe" },
  @{ n="httpd-2.4.68-260617-Win64-VS18.zip"; u="https://www.apachelounge.com/download/VS18/binaries/httpd-2.4.68-260617-Win64-VS18.zip" },
  @{ n="mod_fcgid-2.3.10-win64-VS18.zip";    u="https://www.apachelounge.com/download/VS18/modules/mod_fcgid-2.3.10-win64-VS18.zip" },
  @{ n="php-8.5.8-nts-Win32-vs17-x64.zip";   u="https://windows.php.net/downloads/releases/php-8.5.8-nts-Win32-vs17-x64.zip";  php="8.5" },
  @{ n="php-8.4.23-nts-Win32-vs17-x64.zip";  u="https://windows.php.net/downloads/releases/php-8.4.23-nts-Win32-vs17-x64.zip"; php="8.4" },
  @{ n="php-8.3.32-nts-Win32-vs16-x64.zip";  u="https://windows.php.net/downloads/releases/php-8.3.32-nts-Win32-vs16-x64.zip"; php="8.3" },
  @{ n="php-8.2.32-nts-Win32-vs16-x64.zip";  u="https://windows.php.net/downloads/releases/php-8.2.32-nts-Win32-vs16-x64.zip"; php="8.2" },
  @{ n="php-8.1.34-nts-Win32-vs16-x64.zip";  u="https://windows.php.net/downloads/releases/archives/php-8.1.34-nts-Win32-vs16-x64.zip"; php="8.1" },
  @{ n="php-7.4.33-nts-Win32-vc15-x64.zip";  u="https://windows.php.net/downloads/releases/archives/php-7.4.33-nts-Win32-vc15-x64.zip"; php="7.4" }
)
foreach ($it in $items) {
    $out = Join-Path $dl $it.n
    if (Test-Path $out) { Say "ya existe $($it.n), se omite" ; continue }
    Say "descargando $($it.n)..."
    Invoke-WebRequest -Uri $it.u -OutFile $out -UserAgent $ua -Headers @{ "Referer"="https://www.apachelounge.com/" } -TimeoutSec 600
}

# --- extraccion ---
$tmp = Join-Path $dl "_x"; if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }; New-Item -ItemType Directory -Force -Path $tmp | Out-Null

Say "extrayendo Apache..."
$apw = Join-Path $tmp "ap"; Expand-Archive (Join-Path $dl "httpd-2.4.68-260617-Win64-VS18.zip") $apw -Force
Get-ChildItem (Join-Path $apw "Apache24") -Force | Move-Item -Destination (Join-Path $root "bin\apache") -Force

Say "extrayendo mod_fcgid..."
$fxw = Join-Path $tmp "fx"; Expand-Archive (Join-Path $dl "mod_fcgid-2.3.10-win64-VS18.zip") $fxw -Force
$so = Get-ChildItem $fxw -Recurse -Filter "mod_fcgid.so" | Select-Object -First 1
Copy-Item $so.FullName (Join-Path $root "bin\apache\modules\mod_fcgid.so") -Force

foreach ($it in ($items | Where-Object { $_.php })) {
    Say "extrayendo PHP $($it.php)..."
    $dest = Join-Path $root "bin\php\$($it.php)"
    if (Test-Path $dest) { Get-ChildItem $dest -Force | Remove-Item -Recurse -Force }
    Expand-Archive (Join-Path $dl $it.n) $dest -Force
}
Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue

Say "descargando Composer..."
New-Item -ItemType Directory -Force -Path (Join-Path $root "bin\composer") | Out-Null
& curl.exe -s -L -o (Join-Path $root "bin\composer\composer.phar") "https://getcomposer.org/composer-stable.phar"

Say "descargando mkcert (HTTPS local)..."
New-Item -ItemType Directory -Force -Path (Join-Path $root "bin\mkcert") | Out-Null
& curl.exe -s -L -o (Join-Path $root "bin\mkcert\mkcert.exe") "https://github.com/FiloSottile/mkcert/releases/download/v1.4.4/mkcert-v1.4.4-windows-amd64.exe"

Say "descargando Mailpit (captura de correo)..."
New-Item -ItemType Directory -Force -Path (Join-Path $root "bin\mailpit") | Out-Null
& curl.exe -s -L -o (Join-Path $root "bin\mailpit\mailpit.zip") "https://github.com/axllent/mailpit/releases/latest/download/mailpit-windows-amd64.zip"
Expand-Archive (Join-Path $root "bin\mailpit\mailpit.zip") (Join-Path $root "bin\mailpit") -Force
Remove-Item (Join-Path $root "bin\mailpit\mailpit.zip") -Force -ErrorAction SilentlyContinue

Say "aplicando configuracion (lua.ps1 init)..."
& (Join-Path $root "lua.ps1") init

Write-Host ""
Write-Host "[bootstrap] LISTO. Ejecuta:  .\lua.ps1 start   y abre http://localhost" -ForegroundColor Green
Write-Host "[bootstrap] Si Apache/PHP no arrancan por DLL faltante, instala downloads\vc_redist.x64.exe" -ForegroundColor Yellow
