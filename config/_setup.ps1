<#
============================================================
 lua-server :: setup (opcional) — REQUIERE ADMIN
 Lo invoca:  .\lua.ps1 setup
 Convierte el servidor PHP en un servicio del equipo:
   1. Instala Apache como servicio de Windows (auto-arranque)
   2. Abre el puerto 80 (y 443) en el firewall, solo red local
   3. Escribe las entradas del archivo hosts para los dominios .lua.test
 (No toca bases de datos: la BD vive en Docker.)
============================================================
#>
$ErrorActionPreference = "Stop"
$Root      = Split-Path $PSScriptRoot -Parent
$Httpd     = Join-Path $Root "bin\apache\bin\httpd.exe"
$SvcApache = "luaApache"

function Info($m){ Write-Host "[setup] $m" -ForegroundColor Cyan }
function Ok($m){ Write-Host "[ok]    $m" -ForegroundColor Green }
function Warn($m){ Write-Host "[!]     $m" -ForegroundColor Yellow }

$id = [Security.Principal.WindowsIdentity]::GetCurrent()
if (-not (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Warn "Ejecuta como administrador."; exit 1
}

# --- 1. Apache como servicio ---
Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force
if (-not (Get-Service $SvcApache -ErrorAction SilentlyContinue)) {
    Info "Instalando servicio $SvcApache..."
    & $Httpd -k install -n $SvcApache
    Ok "Servicio $SvcApache instalado."
} else { Info "Servicio $SvcApache ya existe." }
Set-Service -Name $SvcApache -StartupType Automatic
& sc.exe failure $SvcApache reset= 86400 actions= restart/5000/restart/5000/restart/5000 | Out-Null
Ok "Apache en arranque automatico + reinicio ante fallo."

# --- 2. firewall (solo red local, perfil Privado) ---
Info "Reglas de firewall (perfil Privado, subred local)..."
foreach ($r in @(@{n="lua-server HTTP";port=80}, @{n="lua-server HTTPS";port=443})) {
    Get-NetFirewallRule -DisplayName $r.n -ErrorAction SilentlyContinue | Remove-NetFirewallRule -ErrorAction SilentlyContinue
    New-NetFirewallRule -DisplayName $r.n -Direction Inbound -Action Allow -Protocol TCP -LocalPort $r.port -Profile Private -RemoteAddress LocalSubnet | Out-Null
}
Ok "Puertos 80 y 443 abiertos solo en la red local."
Warn "Comprueba que tu red este marcada como 'Privada' (Get-NetConnectionProfile)."

# --- 3. hosts + arrancar ---
& (Join-Path $Root "lua.ps1") reload
Start-Service $SvcApache -ErrorAction SilentlyContinue
Ok "Setup completo. Comprueba con:  .\lua.ps1 status"
