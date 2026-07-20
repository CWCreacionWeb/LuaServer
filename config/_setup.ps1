<#
============================================================
 lua-server :: instalador (una sola vez) — REQUIERE ADMIN
 Lo invoca:  .\lua.ps1 setup
 Hace:
   1. Inicializa el datadir de MariaDB (si esta vacio)
   2. Instala Apache y MariaDB como servicios de Windows (auto-arranque)
   3. Asegura MariaDB (password root + usuario de equipo por LAN)
   4. Crea reglas de firewall acotadas a la red local (perfil Privado)
   5. Escribe las entradas del archivo hosts
   6. Arranca los servicios
============================================================
#>
$ErrorActionPreference = "Stop"

$Root      = Split-Path $PSScriptRoot -Parent
$Bin       = Join-Path $Root "bin"
$Httpd     = Join-Path $Bin "apache\bin\httpd.exe"
$MariaBin  = Join-Path $Bin "mariadb\bin"
$InstallDb = Join-Path $MariaBin "mariadb-install-db.exe"
$MariaD    = Join-Path $MariaBin "mariadbd.exe"
$MysqlExe  = Join-Path $MariaBin "mariadb.exe"
$MyIni     = Join-Path $Root "config\mariadb\my.ini"
$DataDir   = Join-Path $Root "data\mariadb"
$SvcApache = "luaApache"
$SvcMaria  = "luaMariaDB"

function Info($m){ Write-Host "[setup] $m" -ForegroundColor Cyan }
function Ok($m){ Write-Host "[ok]    $m" -ForegroundColor Green }
function Warn($m){ Write-Host "[!]     $m" -ForegroundColor Yellow }

$id = [Security.Principal.WindowsIdentity]::GetCurrent()
if (-not (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Warn "Ejecuta este comando como administrador."; exit 1
}

# ---------- 1. datadir MariaDB ----------
if (-not (Test-Path (Join-Path $DataDir "mysql"))) {
    Info "Inicializando datadir de MariaDB..."
    & $InstallDb --datadir="$DataDir" --config="$MyIni" --port=3306 --default-user
    Ok "Datadir inicializado."
} else { Info "Datadir de MariaDB ya existe, se omite." }

# ---------- 2. servicios ----------
if (-not (Get-Service $SvcMaria -ErrorAction SilentlyContinue)) {
    Info "Instalando servicio $SvcMaria..."
    & $MariaD --install $SvcMaria --defaults-file="$MyIni"
    Ok "Servicio $SvcMaria instalado."
} else { Info "Servicio $SvcMaria ya existe." }

if (-not (Get-Service $SvcApache -ErrorAction SilentlyContinue)) {
    Info "Instalando servicio $SvcApache..."
    & $Httpd -k install -n $SvcApache
    Ok "Servicio $SvcApache instalado."
} else { Info "Servicio $SvcApache ya existe." }

# auto-arranque + recuperacion (reinicio ante fallo)
foreach ($s in @($SvcApache,$SvcMaria)) {
    Set-Service -Name $s -StartupType Automatic
    & sc.exe failure $s reset= 86400 actions= restart/5000/restart/5000/restart/5000 | Out-Null
}
Ok "Servicios en Automatic + reinicio ante fallo."

# ---------- 3. arrancar MariaDB y asegurar ----------
Start-Service $SvcMaria
Start-Sleep -Seconds 3

$secured = Join-Path $Root "data\.secured"
if (-not (Test-Path $secured)) {
    Info "Vamos a asegurar MariaDB."
    $rootPw = Read-Host "Define la contrasena de root de MariaDB" -AsSecureString
    $rootPwPlain = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($rootPw))
    $teamPw = Read-Host "Contrasena para el usuario de equipo 'lua' (acceso por LAN)" -AsSecureString
    $teamPwPlain = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($teamPw))

    $sql = @"
ALTER USER 'root'@'localhost' IDENTIFIED BY '$rootPwPlain';
DELETE FROM mysql.global_priv WHERE User='';
DROP DATABASE IF EXISTS test;
CREATE USER IF NOT EXISTS 'lua'@'192.168.%.%' IDENTIFIED BY '$teamPwPlain';
GRANT ALL PRIVILEGES ON *.* TO 'lua'@'192.168.%.%' WITH GRANT OPTION;
CREATE USER IF NOT EXISTS 'lua'@'10.%.%.%' IDENTIFIED BY '$teamPwPlain';
GRANT ALL PRIVILEGES ON *.* TO 'lua'@'10.%.%.%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
"@
    $sql | & $MysqlExe -u root
    New-Item -ItemType File -Path $secured -Force | Out-Null
    Ok "MariaDB asegurada: root con password, usuario de equipo 'lua' creado para la LAN."
    Warn "Guarda estas credenciales en tu gestor de contrasenas."
} else { Info "MariaDB ya estaba asegurada, se omite." }

# ---------- 4. firewall (solo perfil Privado + subred local) ----------
Info "Creando reglas de firewall (perfil Privado, subred local)..."
$rules = @(
    @{ n="lua-server HTTP";    port=80 },
    @{ n="lua-server HTTPS";   port=443 },
    @{ n="lua-server MariaDB"; port=3306 }
)
foreach ($r in $rules) {
    Get-NetFirewallRule -DisplayName $r.n -ErrorAction SilentlyContinue | Remove-NetFirewallRule -ErrorAction SilentlyContinue
    New-NetFirewallRule -DisplayName $r.n -Direction Inbound -Action Allow -Protocol TCP `
        -LocalPort $r.port -Profile Private -RemoteAddress LocalSubnet | Out-Null
}
Ok "Firewall: 80, 443 y 3306 abiertos SOLO en la red local (perfil Privado)."
Warn "Verifica que tu conexion de red este marcada como 'Privada' (Get-NetConnectionProfile)."

# ---------- 5. hosts + 6. arrancar Apache ----------
& (Join-Path $Root "lua.ps1") reload
Start-Service $SvcApache -ErrorAction SilentlyContinue

Ok "Setup completo. Comprueba con:  .\lua.ps1 status"
