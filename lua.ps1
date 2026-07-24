<#
============================================================
 lua-server :: servidor PHP local (portable) — solo Apache + PHP
 Uso:   .\lua.ps1 <comando> [argumentos]

 PRIMEROS PASOS EN UN PC NUEVO:
   .\lua.ps1 init          Ajusta todas las rutas a esta carpeta (portable)
   .\lua.ps1 start         Arranca (sin admin)
   http://localhost        Abre el panel

 COMANDOS:
   init                      Re-aplica rutas a la carpeta actual (tras mover/clonar)
   start | stop | restart    Arranca / para / reinicia Apache
   reload                    Regenera vhosts desde sites.json y recarga
   status                    Estado, versiones PHP y sitios
   add-site <nombre> [ver]   Crea un proyecto (carpeta + vhost). ver = version PHP
   add-external <n> <ruta> [dom] [ver]  Registra un proyecto externo (fuera de www)
   remove-site <nombre>      Elimina el vhost (NO borra la carpeta)
   list-sites                Lista proyectos y su version de PHP
   switch-php <nombre> <ver> Cambia la version de PHP de un proyecto
   list-php                  Lista las versiones de PHP instaladas
   hosts                     Lineas hosts para tus companeros (con la IP del server)
   setup                     [ADMIN] Instala Apache como servicio + firewall + hosts
   startup-enable            [ADMIN] Arranque con Windows (servicio Apache + tarea del watcher)
   startup-disable           [ADMIN] Desactiva el arranque con Windows (vuelve a manual)
   startup-status            Comprueba si el arranque con Windows esta activo
   logs                      Ultimas lineas del log de Apache
============================================================
#>

param(
    [Parameter(Position = 0)][string]$Command = "help",
    [Parameter(Position = 1)][string]$Arg1,
    [Parameter(Position = 2)][string]$Arg2,
    [Parameter(Position = 3)][string]$Arg3,
    [Parameter(Position = 4)][string]$Arg4
)
$ErrorActionPreference = "Stop"

# --- Rutas (derivadas de la ubicacion del script => PORTABLE) ---
$Root       = $PSScriptRoot
$Bin        = Join-Path $Root "bin"
$Apache     = Join-Path $Bin  "apache"
$Httpd      = Join-Path $Apache "bin\httpd.exe"
$HttpdConf  = Join-Path $Apache "conf\httpd.conf"
$PhpBase    = Join-Path $Bin  "php"
$Www        = Join-Path $Root "www"
$VhostDir   = Join-Path $Root "config\apache\vhosts"
$Template   = Join-Path $Root "config\apache\templates\vhost.tpl"
$SitesJson  = Join-Path $Root "config\sites.json"
$ApacheLog  = Join-Path $Root "logs\apache"
$TmpDir     = Join-Path $Root "tmp"
$HostsFile  = Join-Path $env:WINDIR "System32\drivers\etc\hosts"
# --- HTTPS (mkcert + mod_ssl) ---
$SslDir     = Join-Path $Root "data\ssl"
$Mkcert     = Join-Path $Bin  "mkcert\mkcert.exe"
$HttpsFlag  = Join-Path $Root "config\https.on"
$SslConf    = Join-Path $Root "config\apache\ssl.conf"
$SslCert    = Join-Path $SslDir "lua.pem"
$SslKey     = Join-Path $SslDir "lua-key.pem"
# --- Mailpit (captura de correo) ---
$Mailpit     = Join-Path $Bin  "mailpit\mailpit.exe"
$MailpitFlag = Join-Path $Root "config\mailpit.on"
# --- MySQL (MariaDB) ---
$MariaDb       = Join-Path $Bin  "mariadb"
$Mysqld        = Join-Path $MariaDb "bin\mysqld.exe"
$MariaInstall  = Join-Path $MariaDb "bin\mariadb-install-db.exe"
$MariaAdmin    = Join-Path $MariaDb "bin\mariadb-admin.exe"
$MyIni         = Join-Path $Root "config\mariadb\my.ini"
$MariaDataDir  = Join-Path $Root "data\mariadb"
$MariaLogDir   = Join-Path $Root "logs\mariadb"
$MariaDbFlag   = Join-Path $Root "config\mariadb.on"
# --- PostgreSQL (portable, binarios EnterpriseDB) ---
$Postgres      = Join-Path $Bin  "postgres"
$PgCtl         = Join-Path $Postgres "bin\pg_ctl.exe"
$Initdb        = Join-Path $Postgres "bin\initdb.exe"
$PgDataDir     = Join-Path $Root "data\postgres"
$PgLogDir      = Join-Path $Root "logs\postgres"
$PostgresFlag  = Join-Path $Root "config\postgres.on"
$PgPort        = 5432
# --- MongoDB (Community Server, portable) ---
$MongoDb        = Join-Path $Bin  "mongodb"
$Mongod         = Join-Path $MongoDb "bin\mongod.exe"
$MongoDataDir   = Join-Path $Root "data\mongodb"
$MongoLogDir    = Join-Path $Root "logs\mongodb"
$MongoConf      = Join-Path $Root "config\mongodb\mongod.cfg"
$MongoDbFlag    = Join-Path $Root "config\mongodb.on"
$MongoPort      = 27017
# --- Node.js portable (runtime unicamente para mongo-express) ---
$NodeDir        = Join-Path $Bin  "node"
$NodeExe        = Join-Path $NodeDir "node.exe"
$NpmCmd         = Join-Path $NodeDir "npm.cmd"
# --- mongo-express (GUI web de MongoDB, corre sobre Node) ---
$MongoExpress        = Join-Path $Root "bin\mongo-express"
$MongoExpressApp     = Join-Path $MongoExpress "node_modules\mongo-express\app.js"
$MongoExpressPidFile = Join-Path $TmpDir "mongo-express.pid"
$MongoExpressPort    = 8081
# --- Exponer en la red local (abrir puerto en el Firewall de Windows) ---
$LanExposeFlag = Join-Path $Root "config\lanexpose.on"
$LanIpFile     = Join-Path $Root "config\lan-ip.txt"
$FwRulePrefix  = "lua-server"

$SvcApache  = "luaApache"
$DefaultTld = "lua.test"
$HostsBegin = "# === lua-server BEGIN (no editar a mano) ==="
$HostsEnd   = "# === lua-server END ==="

# extensiones PHP a habilitar (solo si existe su DLL). mysqli/pdo_mysql incluidas
# por si tus proyectos conectan a un MySQL (p.ej. en Docker) via 127.0.0.1.
# com_dotnet lo usa el panel para lanzar la recarga de Apache en segundo plano.
$WantExts   = @('curl','intl','mbstring','exif','mysqli','openssl','pdo_mysql','pdo_pgsql','pgsql','pdo_sqlite','sqlite3','zip','fileinfo','sodium','soap','bz2','com_dotnet')

function Info($m){ Write-Host "[lua] $m" -ForegroundColor Cyan }
function Ok($m){ Write-Host "[ok]  $m" -ForegroundColor Green }
function Warn($m){ Write-Host "[!]   $m" -ForegroundColor Yellow }
function Err($m){ Write-Host "[x]   $m" -ForegroundColor Red }

function Test-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}
function Require-Admin {
    if (-not (Test-Admin)) {
        Warn "Este comando necesita administrador. Relanzando elevado..."
        $a = @('-NoProfile','-ExecutionPolicy','Bypass','-File',"`"$PSCommandPath`"",$Command)
        if ($Arg1) { $a += $Arg1 }; if ($Arg2) { $a += $Arg2 }
        Start-Process powershell -Verb RunAs -ArgumentList $a
        exit
    }
}

function Get-Config {
    if (-not (Test-Path $SitesJson)) { return ([pscustomobject]@{ defaultPhp='8.4'; tld=$DefaultTld; sites=([pscustomobject]@{}) }) }
    Get-Content $SitesJson -Raw | ConvertFrom-Json
}
function Save-Config($cfg) {
    $json = $cfg | ConvertTo-Json -Depth 6
    [System.IO.File]::WriteAllText($SitesJson, $json, (New-Object System.Text.UTF8Encoding($false)))
}
# Dominio local (.test por defecto): configurable desde el panel, guardado en sites.json.
function Get-Tld {
    $c = Get-Config
    if ($c.tld) { return $c.tld } else { return $DefaultTld }
}
function Get-PhpVersions {
    if (-not (Test-Path $PhpBase)) { return @() }
    Get-ChildItem $PhpBase -Directory | Where-Object { Test-Path (Join-Path $_.FullName "php-cgi.exe") } | ForEach-Object { $_.Name } | Sort-Object
}
function Get-DocRoot($base) {
    # $base = carpeta raiz del proyecto (www\<name> o una ruta externa).
    # Si tiene public\ (Laravel/Symfony) se usa como docroot.
    $pub = Join-Path $base "public"
    if (Test-Path $pub) { return $pub } else { return $base }
}
# Carpeta raiz de un sitio: su 'path' (ruta externa) si esta definido, si no www\<name>.
function Get-SiteBase($site, $name) {
    if ($site -and ($site.PSObject.Properties.Name -contains 'path') -and $site.path -and (Test-Path $site.path)) { return $site.path }
    return (Join-Path $Www $name)
}
function Get-LanIp {
    $ip = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike "127.*" -and $_.IPAddress -notlike "169.254.*" -and $_.IPAddress -notlike "172.*" -and $_.PrefixOrigin -ne "WellKnown" } |
        Select-Object -First 1
    if ($ip) { return $ip.IPAddress } else { return "127.0.0.1" }
}
function Service-Exists($name) { [bool](Get-Service -Name $name -ErrorAction SilentlyContinue) }
function Fwd($p) { return ($p -replace '\\','/') }
function Apache-Up { [bool](Get-Process httpd -ErrorAction SilentlyContinue) }
function Port80-Free { -not (Get-NetTCPConnection -LocalPort 80 -State Listen -ErrorAction SilentlyContinue) }
# Reinicio robusto: usa el servicio si existe; en consola espera a que el puerto 80 quede libre.
function Restart-Apache {
    if (Service-Exists $SvcApache) { Restart-Service $SvcApache; return }
    Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force
    for ($i=0; $i -lt 24; $i++) { Start-Sleep -Milliseconds 250; if (Port80-Free) { break } }
    Start-Process -FilePath $Httpd -WindowStyle Hidden
}
# Valida la config de Apache SIN lanzar excepcion (httpd -t escribe en stderr:
# con ErrorActionPreference=Stop eso rompe el script, por eso aislamos aqui).
function Test-HttpdConfig {
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $null = & $Httpd -t 2>&1
    $ok = ($LASTEXITCODE -eq 0)
    $ErrorActionPreference = $prev
    return $ok
}

# ============================================================
#  INIT: re-aplica rutas a la carpeta actual (portable)
# ============================================================
function Set-HttpdConf {
    if (-not (Test-Path $HttpdConf)) { Warn "No existe httpd.conf (falta bin\apache). Ejecuta bootstrap.ps1"; return }
    $c = Get-Content $HttpdConf -Raw
    $srv = Fwd $Apache
    $lua = Fwd $Root
    $c = $c -replace '(?m)^\s*Define\s+SRVROOT\s+".*"', "Define SRVROOT `"$srv`""
    $c = $c -replace '(?m)^\s*Define\s+LUAROOT\s+".*"', "Define LUAROOT `"$lua`""
    $c = $c.Replace('# LoadModule rewrite_module modules/mod_rewrite.so', 'LoadModule rewrite_module modules/mod_rewrite.so')
    $c = $c.Replace('# LoadModule vhost_alias_module modules/mod_vhost_alias.so', 'LoadModule vhost_alias_module modules/mod_vhost_alias.so')
    # modulos que usan los .htaccess de WordPress/proyectos (cache y compresion)
    $c = $c -replace '(?m)^#\s*LoadModule expires_module', 'LoadModule expires_module'
    $c = $c -replace '(?m)^#\s*LoadModule deflate_module', 'LoadModule deflate_module'
    $c = $c.Replace('#ServerName www.example.com:80', 'ServerName localhost:80')
    if ($c -notmatch 'httpd-lua\.conf') {
        $c = $c + "`r`n`r`n# ================= lua-server =================`r`nDefine LUAROOT `"$lua`"`r`nInclude `"`${LUAROOT}/config/apache/httpd-lua.conf`"`r`n"
    }
    Set-Content -Path $HttpdConf -Value $c -Encoding ascii
    Ok "httpd.conf apuntando a: $srv"
}

$ExtraExtFile = Join-Path $Root "config\php\extra-extensions.json"
# Extensiones de terceros registradas desde el panel (nombre -> .dll esperado
# php_<nombre>.dll por version, ver bin\php\<ver>\ext\). Complementa $WantExts
# sin tocar codigo cada vez que se instala una nueva.
function Get-ExtraExtensions {
    if (-not (Test-Path $ExtraExtFile)) { return @() }
    try {
        $j = Get-Content $ExtraExtFile -Raw | ConvertFrom-Json
        if ($j -is [array]) { return @($j) } else { return @() }
    } catch { return @() }
}
function Set-PhpInis {
    foreach ($pd in (Get-ChildItem $PhpBase -Directory | Where-Object { Test-Path "$($_.FullName)\php-cgi.exe" })) {
        $ver = $pd.Name; $dir = $pd.FullName
        $ext = Join-Path $dir "ext"; $ini = Join-Path $dir "php.ini"
        # PHP < 7.2 exige el nombre completo de la DLL (extension=php_curl.dll);
        # 7.2+ acepta la forma corta (extension=curl).
        $oldStyle = $false; try { $oldStyle = ([version]$ver -lt [version]'7.2') } catch {}
        $extName = { param($n) if ($oldStyle) { "php_$n.dll" } else { $n } }
        $dev = Join-Path $dir "php.ini-development"; $prod = Join-Path $dir "php.ini-production"
        if (Test-Path $dev) { Copy-Item $dev $ini -Force } elseif (Test-Path $prod) { Copy-Item $prod $ini -Force } else { Set-Content $ini "" -Encoding ascii }
        $lines = Get-Content $ini | ForEach-Object { if ($_ -match '^\s*(zend_)?extension\s*=') { ';' + $_ } else { $_ } }
        $enable = New-Object System.Collections.Generic.List[string]
        foreach ($e in (@($WantExts) + @(Get-ExtraExtensions) | Select-Object -Unique)) { if (Test-Path (Join-Path $ext "php_$e.dll")) { $enable.Add("extension=$(& $extName $e)") } }
        if     (Test-Path (Join-Path $ext "php_gd.dll"))  { $enable.Add("extension=$(& $extName 'gd')") }
        elseif (Test-Path (Join-Path $ext "php_gd2.dll")) { $enable.Add("extension=$(& $extName 'gd2')") }
        $hasOp = Test-Path (Join-Path $ext "php_opcache.dll")
        $tmpF = Fwd $TmpDir
        $b = New-Object System.Collections.Generic.List[string]
        $b.Add(""); $b.Add("; ===== lua-server ====="); $b.Add("extension_dir = `"$(Fwd $ext)`"")
        $b.AddRange([string[]]$enable)
        # opcache: se omite en PHP < 7.2. Con mod_fcgid (php-cgi persistente) en Windows,
        # opcache en esas versiones antiguas provoca crashes del proceso php-cgi
        # ("End of script output before headers" / OS 109 broken pipe).
        if ($hasOp -and -not $oldStyle) { $b.Add("zend_extension=$(& $extName 'opcache')"); $b.Add("opcache.enable = 1"); $b.Add("opcache.enable_cli = 0"); $b.Add("opcache.validate_timestamps = 1"); $b.Add("opcache.revalidate_freq = 0") }
        $b.Add("cgi.fix_pathinfo = 1")
        $b.Add("upload_tmp_dir = `"$tmpF`""); $b.Add("sys_temp_dir = `"$tmpF`""); $b.Add("session.save_path = `"$tmpF`"")
        # --- overrides editables desde el panel (sobreviven a las regeneraciones) ---
        $ovrDir = Join-Path $Root "config\php"; New-Item -ItemType Directory -Force -Path $ovrDir | Out-Null
        $ovr = Join-Path $ovrDir "$ver.overrides.ini"
        if (-not (Test-Path $ovr)) {
            Set-Content -Path $ovr -Encoding ascii -Value @(
              "; Ajustes editables desde el panel (http://localhost). Se aplican al final: ganan.",
              "date.timezone = Europe/Madrid",
              "memory_limit = 512M",
              "upload_max_filesize = 128M",
              "post_max_size = 128M",
              "max_execution_time = 120",
              "max_input_vars = 5000",
              "display_errors = On",
              "error_reporting = E_ALL")
        }
        $b.Add(""); $b.Add("; ===== overrides: config/php/$ver.overrides.ini =====")
        $b.AddRange([string[]](Get-Content $ovr))
        # Xdebug: activo si existe el marcador Y la DLL
        $xon = Join-Path $ovrDir "$ver.xdebug.on"
        if ((Test-Path $xon) -and (Test-Path (Join-Path $ext "php_xdebug.dll"))) {
            $b.Add(""); $b.Add("; ===== Xdebug (panel) =====")
            $b.Add("zend_extension=$(& $extName 'xdebug')")
            $b.Add("xdebug.mode=debug")
            $b.Add("xdebug.start_with_request=yes")
            $b.Add("xdebug.client_host=127.0.0.1")
            $b.Add("xdebug.client_port=9003")
            $b.Add("xdebug.idekey=VSCODE")
        }
        # Mailpit: rutear mail() de PHP al buzon local (SMTP 1025)
        if (Test-Path $MailpitFlag) {
            $b.Add(""); $b.Add("; ===== Mailpit (captura de correo) =====")
            $b.Add("SMTP = 127.0.0.1")
            $b.Add("smtp_port = 1025")
            $b.Add("sendmail_from = dev@$(Get-Tld)")
        }
        Set-Content -Path $ini -Value (@($lines) + $b.ToArray()) -Encoding ascii
    }
    Ok "php.ini regenerados ($((Get-PhpVersions) -join ', '))"
}

# Escribe config\apache\ssl.conf: carga mod_ssl + Listen 443 solo si HTTPS esta activo.
function Set-Ssl {
    $on = (Test-Path $HttpsFlag) -and (Test-Path $SslCert) -and (Test-Path $SslKey)
    if ($on) {
        Set-Content -Path $SslConf -Encoding ascii -Value @'
# Generado por lua.ps1 -- HTTPS activo
LoadModule ssl_module modules/mod_ssl.so
LoadModule socache_shmcb_module modules/mod_socache_shmcb.so
Listen 443
SSLCipherSuite HIGH:!aNULL:!MD5
SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
SSLSessionCache "shmcb:${LUAROOT}/tmp/ssl_scache(512000)"
SSLSessionCacheTimeout 300
'@
    } else {
        Set-Content -Path $SslConf -Value "# HTTPS desactivado" -Encoding ascii
    }
}
function New-VhostFile($name, $php, $domain, $base) {
    if (-not $domain) { $domain = "$name.$(Get-Tld)" }
    if (-not $base)   { $base = Join-Path $Www $name }
    $docroot = Fwd (Get-DocRoot $base)
    $phpdir  = Fwd (Join-Path $PhpBase $php)
    $phpcgi  = Fwd (Join-Path $PhpBase "$php\php-cgi.exe")
    $logdir  = Fwd $ApacheLog
    $tpl = Get-Content $Template -Raw
    $out = $tpl.Replace('{NAME}',$name).Replace('{DOMAIN}',$domain).Replace('{PHPVER}',$php).Replace('{DOCROOT}',$docroot).Replace('{PHPDIR}',$phpdir).Replace('{PHPCGI}',$phpcgi).Replace('{LOGDIR}',$logdir)
    if ((Test-Path $HttpsFlag) -and (Test-Path $SslCert) -and (Test-Path $SslKey)) {
        $cert = Fwd $SslCert; $key = Fwd $SslKey
        $out = $out + @"

<VirtualHost *:443>
    ServerName $domain
    ServerAlias www.$domain
    DocumentRoot "$docroot"
    FcgidInitialEnv PHPRC "$phpdir"
    SSLEngine on
    SSLCertificateFile "$cert"
    SSLCertificateKeyFile "$key"
    <Directory "$docroot">
        Options +ExecCGI +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
        FcgidWrapper "$phpcgi" .php
    </Directory>
    ErrorLog  "$logdir/$name-ssl-error.log"
</VirtualHost>
"@
    }
    Set-Content -Path (Join-Path $VhostDir "$name.conf") -Value $out -Encoding ascii
}
function Regenerate-Vhosts {
    if (-not (Test-Path $VhostDir)) { New-Item -ItemType Directory -Force -Path $VhostDir | Out-Null }
    Get-ChildItem $VhostDir -Filter *.conf -ErrorAction SilentlyContinue | Remove-Item -Force
    $cfg = Get-Config
    foreach ($p in $cfg.sites.PSObject.Properties.Name) {
        $s = $cfg.sites.$p; $dom = $null
        if (($s.PSObject.Properties.Name -contains 'domain') -and $s.domain) { $dom = $s.domain }
        $base = Get-SiteBase $s $p
        New-VhostFile $p $s.php $dom $base
    }
}
function Get-SiteDomain($cfg, $name) {
    $s = $cfg.sites.$name
    if ($s -and ($s.PSObject.Properties.Name -contains 'domain') -and $s.domain) { return $s.domain }
    return "$name.$(Get-Tld)"
}

function Cmd-Init {
    Info "Ajustando el stack a: $Root"
    foreach ($d in @($VhostDir,$ApacheLog,$TmpDir,$SslDir,(Join-Path $Root 'logs\php'),$MariaLogDir,$MongoLogDir)) { New-Item -ItemType Directory -Force -Path $d | Out-Null }
    if (-not (Test-Path $SitesJson)) { Save-Config (Get-Config) }
    Set-HttpdConf
    Set-PhpInis
    Set-Ssl
    Set-MariaDbIni
    Set-MongoConf
    Regenerate-Vhosts
    if (Test-Path $Httpd) { Info "Validando Apache..."; if (Test-HttpdConfig) { Ok "Config de Apache: OK" } else { Err "Revisa la config de Apache (.\lua.ps1 logs)" } }
    Ok "Init completo. Arranca con:  .\lua.ps1 start"
}

# ============================================================
#  Arranque (servicio si existe; si no, modo consola sin admin)
# ============================================================
function Watcher-Alive {
    $pf = Join-Path $TmpDir "watch.pid"
    if (Test-Path $pf) { $wp = Get-Content $pf -ErrorAction SilentlyContinue; if ($wp -and (Get-Process -Id ([int]$wp) -ErrorAction SilentlyContinue)) { return $true } }
    return $false
}
function Start-Watcher {
    if (Watcher-Alive) { return }
    Start-Process powershell -WindowStyle Hidden -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File',"`"$PSCommandPath`"",'watch')
}
function Mailpit-Up { [bool](Get-Process mailpit -ErrorAction SilentlyContinue) }
function Start-Mailpit {
    if (-not (Test-Path $Mailpit)) { return }
    if (Mailpit-Up) { return }
    $db = Join-Path $Root "data\mailpit.db"
    Start-Process -FilePath $Mailpit -WindowStyle Hidden -ArgumentList @('--smtp','127.0.0.1:1025','--listen','127.0.0.1:8025','--db-file',"`"$db`"")
}
function Stop-Mailpit { Get-Process mailpit -ErrorAction SilentlyContinue | Stop-Process -Force }

# Reescribe config\mariadb\my.ini con las rutas absolutas de ESTA instalacion
# (portable: se recalculan en cada init/start, igual que httpd.conf).
function Set-MariaDbIni {
    if (-not (Test-Path $MyIni)) { return }
    New-Item -ItemType Directory -Force -Path $MariaLogDir | Out-Null
    $dd   = Fwd $MariaDataDir
    $sock = Fwd (Join-Path $TmpDir "mysql.sock")
    $log  = Fwd (Join-Path $MariaLogDir "error.log")
    $c = Get-Content $MyIni -Raw
    $c = $c -replace '(?m)^(\s*datadir\s*=).*',       "`$1 $dd"
    $c = $c -replace '(?m)^(\s*socket\s*=).*',        "`$1 $sock"
    $c = $c -replace '(?m)^(\s*log-error\s*=).*',     "`$1 $log"
    $c = $c -replace '(?m)^(\s*bind-address\s*=).*',  '${1} 127.0.0.1'
    Set-Content -Path $MyIni -Value $c -Encoding ascii
}
function MariaDb-Up { [bool](Get-Process mysqld -ErrorAction SilentlyContinue) }
function MariaDb-Initialized { Test-Path (Join-Path $MariaDataDir "mysql") }
function Initialize-MariaDb {
    if (MariaDb-Initialized) { return $true }
    if (-not (Test-Path $MariaInstall)) { return $false }
    New-Item -ItemType Directory -Force -Path $MariaDataDir | Out-Null
    New-Item -ItemType Directory -Force -Path $MariaLogDir  | Out-Null
    $log = Join-Path $MariaLogDir "install.log"
    & $MariaInstall "--datadir=$MariaDataDir" *> $log
    return (MariaDb-Initialized)
}
function Start-MariaDb {
    if (-not (Test-Path $Mysqld)) { return }
    if (MariaDb-Up) { return }
    Set-MariaDbIni
    if (-not (Initialize-MariaDb)) { return }
    Start-Process -FilePath $Mysqld -WindowStyle Hidden -ArgumentList @("--defaults-file=`"$MyIni`"")
}
function Stop-MariaDb {
    if ((Test-Path $MariaAdmin) -and (MariaDb-Up)) {
        $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        $null = & $MariaAdmin --host=127.0.0.1 --port=3306 --user=root shutdown 2>&1
        $ErrorActionPreference = $prev
        for ($i=0; $i -lt 20; $i++) { if (-not (MariaDb-Up)) { break }; Start-Sleep -Milliseconds 250 }
    }
    Get-Process mysqld -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
}

# ---------------- PostgreSQL (portable, mismo patron que MariaDB) ----------------
# "Up" se comprueba por el postmaster.pid de NUESTRO datadir (no por nombre de proceso
# global) para no confundirse ni interferir con un PostgreSQL del sistema que el usuario
# pueda tener instalado aparte.
function Postgres-Up {
    $pidFile = Join-Path $PgDataDir "postmaster.pid"
    if (-not (Test-Path $pidFile)) { return $false }
    $thePid = Get-Content $pidFile -TotalCount 1 -ErrorAction SilentlyContinue
    if (-not $thePid) { return $false }
    $p = Get-Process -Id ([int]$thePid) -ErrorAction SilentlyContinue
    return ($p -and $p.ProcessName -eq 'postgres')
}
function Postgres-Initialized { Test-Path (Join-Path $PgDataDir "PG_VERSION") }
function Set-PostgresConf {
    $conf = Join-Path $PgDataDir "postgresql.conf"
    if (Test-Path $conf) {
        $c = Get-Content $conf -Raw
        $c = $c -replace '(?m)^\s*#?\s*listen_addresses\s*=.*', "listen_addresses = '127.0.0.1'"
        $c = $c -replace '(?m)^\s*#?\s*port\s*=.*',             "port = $PgPort"
        Set-Content -Path $conf -Value $c -Encoding ascii
    }
    # Solo loopback, autenticacion trust (entorno de desarrollo local; mismo modelo que
    # el root sin contrasena de MariaDB). Si el usuario pone contrasena a un rol, la usa
    # para conectar pero trust la acepta igual: es un servidor solo accesible en 127.0.0.1.
    $hba = Join-Path $PgDataDir "pg_hba.conf"
    Set-Content -Path $hba -Encoding ascii -Value @(
        "# Generado por lua-server -- solo loopback, autenticacion trust (dev local)",
        "local   all   all                   trust",
        "host    all   all   127.0.0.1/32    trust",
        "host    all   all   ::1/128         trust"
    )
}
function Initialize-Postgres {
    if (Postgres-Initialized) { return $true }
    if (-not (Test-Path $Initdb)) { return $false }
    New-Item -ItemType Directory -Force -Path $PgDataDir | Out-Null
    New-Item -ItemType Directory -Force -Path $PgLogDir  | Out-Null
    $log = Join-Path $PgLogDir "initdb.log"
    # -U postgres: superusuario. --auth=trust: sin contrasena en local.
    & $Initdb "-D" "$PgDataDir" "-U" "postgres" "-E" "UTF8" "--locale=C" "--auth=trust" *> $log
    if (-not (Postgres-Initialized)) { return $false }
    Set-PostgresConf
    return $true
}
function Start-Postgres {
    if (-not (Test-Path $PgCtl)) { return }
    if (Postgres-Up) { return }
    if (-not (Initialize-Postgres)) { return }
    New-Item -ItemType Directory -Force -Path $PgLogDir | Out-Null
    # pg_ctl arranca postgres.exe con un token restringido en Windows, asi que funciona
    # aunque el watcher corra como SYSTEM (postgres.exe se niega a correr como admin).
    $srvLog = Join-Path $PgLogDir "postgres.log"
    & $PgCtl "-D" "$PgDataDir" "-l" "$srvLog" "-w" "-t" "30" "start" *> (Join-Path $PgLogDir "pgctl.log")
}
function Stop-Postgres {
    # Solo paramos NUESTRA instancia via pg_ctl -D (nunca un postgres del sistema).
    if ((Test-Path $PgCtl) -and (Postgres-Up)) {
        & $PgCtl "-D" "$PgDataDir" "-m" "fast" "-w" "-t" "20" "stop" *> (Join-Path $PgLogDir "pgctl.log")
        for ($i=0; $i -lt 20; $i++) { if (-not (Postgres-Up)) { break }; Start-Sleep -Milliseconds 250 }
    }
}

# ---------------- MongoDB (portable, mismo patron que PostgreSQL) ----------------
# "Up" se comprueba por el .pid que NOSOTROS le pedimos escribir via
# processManagement.pidFilePath en mongod.cfg (no por nombre de proceso global ni por
# el mongod.lock del datadir, cuyo formato no esta documentado) para no confundirnos
# con otro proceso mongod.exe que el usuario pueda tener corriendo aparte.
function Set-MongoConf {
    New-Item -ItemType Directory -Force -Path (Split-Path $MongoConf) | Out-Null
    New-Item -ItemType Directory -Force -Path $MongoDataDir | Out-Null
    New-Item -ItemType Directory -Force -Path $MongoLogDir  | Out-Null
    $dd      = Fwd $MongoDataDir
    $log     = Fwd (Join-Path $MongoLogDir "mongod.log")
    $pidPath = Fwd (Join-Path $MongoDataDir "mongod.pid")
    Set-Content -Path $MongoConf -Encoding ascii -Value @(
        "# Generado por lua-server -- no editar a mano (se sobrescribe en cada init/start)",
        "storage:",
        "  dbPath: $dd",
        "systemLog:",
        "  destination: file",
        "  path: $log",
        "  logAppend: true",
        "net:",
        "  bindIp: 127.0.0.1",
        "  port: $MongoPort",
        "processManagement:",
        "  pidFilePath: $pidPath"
    )
}
function MongoDb-Up {
    $pidFile = Join-Path $MongoDataDir "mongod.pid"
    if (-not (Test-Path $pidFile)) { return $false }
    $thePid = Get-Content $pidFile -TotalCount 1 -ErrorAction SilentlyContinue
    if (-not $thePid) { return $false }
    $p = Get-Process -Id ([int]$thePid) -ErrorAction SilentlyContinue
    return ($p -and $p.ProcessName -eq 'mongod')
}
function Start-MongoDb {
    if (-not (Test-Path $Mongod)) { return }
    if (MongoDb-Up) { return }
    Set-MongoConf
    Start-Process -FilePath $Mongod -WindowStyle Hidden -ArgumentList @("--config", "`"$MongoConf`"")
}
function Stop-MongoDb {
    # No hay mongosh/cliente bundleado para pedir un shutdown limpio (mongo-express no
    # lo trae). Matamos por PID propio -- WiredTiger con journaling (activo por defecto)
    # hace esto seguro para un almacen de desarrollo de un solo nodo, mismo nivel de
    # rigor que el fallback final de Stop-MariaDb.
    $pidFile = Join-Path $MongoDataDir "mongod.pid"
    if ((Test-Path $pidFile) -and (MongoDb-Up)) {
        $thePid = Get-Content $pidFile -TotalCount 1 -ErrorAction SilentlyContinue
        if ($thePid) { Stop-Process -Id ([int]$thePid) -Force -ErrorAction SilentlyContinue }
        for ($i=0; $i -lt 20; $i++) { if (-not (MongoDb-Up)) { break }; Start-Sleep -Milliseconds 250 }
    }
    Remove-Item $pidFile -Force -ErrorAction SilentlyContinue
}

# ---------------- mongo-express (GUI web de MongoDB, sobre Node) ----------------
# "Up" se comprueba por un .pid propio que escribimos nosotros al arrancarlo (igual
# de robusto que el enfoque de Postgres/Mongo) para no confundirnos con cualquier
# otro proceso node.exe que el usuario tenga corriendo por su cuenta.
function MongoExpress-Up {
    if (-not (Test-Path $MongoExpressPidFile)) { return $false }
    $thePid = Get-Content $MongoExpressPidFile -TotalCount 1 -ErrorAction SilentlyContinue
    if (-not $thePid) { return $false }
    $p = Get-Process -Id ([int]$thePid) -ErrorAction SilentlyContinue
    return ($p -and $p.ProcessName -eq 'node')
}
function Start-MongoExpress {
    if (-not (Test-Path $NodeExe)) { return }
    if (-not (Test-Path $MongoExpressApp)) { return }
    if (-not (MongoDb-Up)) { return }
    if (MongoExpress-Up) { return }
    # Sin autenticacion (ME_CONFIG_BASICAUTH_ENABLED=false): mismo modelo que el root
    # sin contrasena de MariaDB y el trust de PostgreSQL -- solo accesible en 127.0.0.1.
    $env:ME_CONFIG_MONGODB_SERVER      = "127.0.0.1"
    $env:ME_CONFIG_MONGODB_PORT        = "$MongoPort"
    $env:ME_CONFIG_SITE_PORT           = "$MongoExpressPort"
    $env:ME_CONFIG_BASICAUTH_ENABLED   = "false"
    $env:ME_CONFIG_MONGODB_ENABLE_ADMIN= "true"
    $proc = Start-Process -FilePath $NodeExe -ArgumentList @("`"$MongoExpressApp`"") -WindowStyle Hidden -PassThru
    Set-Content -Path $MongoExpressPidFile -Value $proc.Id -Encoding ascii
}
function Stop-MongoExpress {
    if (Test-Path $MongoExpressPidFile) {
        $thePid = Get-Content $MongoExpressPidFile -TotalCount 1 -ErrorAction SilentlyContinue
        if ($thePid) { Stop-Process -Id ([int]$thePid) -Force -ErrorAction SilentlyContinue }
    }
    Remove-Item $MongoExpressPidFile -Force -ErrorAction SilentlyContinue
}

function Cmd-Start {
    if (Service-Exists $SvcApache) { Start-Service $SvcApache; Ok "Apache (servicio) arriba" }
    elseif (Apache-Up) { Info "Apache ya estaba arriba" }
    else { Start-Process -FilePath $Httpd -WindowStyle Hidden; Ok "Apache arrancado" }
    Start-Watcher
    if (Test-Path $MailpitFlag) { Start-Mailpit }
    if (Test-Path $MariaDbFlag) { Start-MariaDb }
    if (Test-Path $PostgresFlag) { Start-Postgres }
    if (Test-Path $MongoDbFlag) { Start-MongoDb; if (MongoDb-Up) { Start-MongoExpress } }
    Write-Host ""; Ok "Panel:  http://localhost"
    Cmd-ListSites
}
function Cmd-Stop {
    if (Service-Exists $SvcApache) { Stop-Service $SvcApache -Force -ErrorAction SilentlyContinue } else { Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force }
    $pf = Join-Path $TmpDir "watch.pid"
    if (Test-Path $pf) { $wp = Get-Content $pf -ErrorAction SilentlyContinue; if ($wp) { Stop-Process -Id ([int]$wp) -Force -ErrorAction SilentlyContinue }; Remove-Item $pf -Force -ErrorAction SilentlyContinue }
    Stop-Mailpit
    Stop-MariaDb
    Stop-MongoExpress
    Stop-MongoDb
    Ok "Apache detenido."
}

# Watcher: proceso independiente que aplica los cambios pedidos desde el panel web.
# El panel solo crea archivos-senal en tmp\; este proceso los ejecuta (no es hijo de Apache).
function Cmd-Watch {
    $pf = Join-Path $TmpDir "watch.pid"; Set-Content -Path $pf -Value $PID -Encoding ascii
    $fApply = Join-Path $TmpDir "apply.flag"
    $fHosts = Join-Path $TmpDir "hosts.flag"
    $fHttps = Join-Path $TmpDir "https.flag"
    $fStartupOn  = Join-Path $TmpDir "startup-on.flag"
    $fStartupOff = Join-Path $TmpDir "startup-off.flag"
    $fLanOn      = Join-Path $TmpDir "lanexpose-on.flag"
    $fLanOff     = Join-Path $TmpDir "lanexpose-off.flag"
    while ($true) {
        try {
            if (Test-Path $fApply) { Remove-Item $fApply -Force -ErrorAction SilentlyContinue; Cmd-Apply }
            if (Test-Path $fHosts) { Remove-Item $fHosts -Force -ErrorAction SilentlyContinue; Start-Process powershell -Verb RunAs -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File',"`"$PSCommandPath`"",'hosts-sync') }
            if (Test-Path $fHttps) { Remove-Item $fHttps -Force -ErrorAction SilentlyContinue; Start-Process powershell -Verb RunAs -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File',"`"$PSCommandPath`"",'https-setup') }
            if (Test-Path $fStartupOn)  { Remove-Item $fStartupOn  -Force -ErrorAction SilentlyContinue; Start-Process powershell -Verb RunAs -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File',"`"$PSCommandPath`"",'startup-enable') }
            if (Test-Path $fStartupOff) { Remove-Item $fStartupOff -Force -ErrorAction SilentlyContinue; Start-Process powershell -Verb RunAs -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File',"`"$PSCommandPath`"",'startup-disable') }
            if (Test-Path $fLanOn)  { Remove-Item $fLanOn  -Force -ErrorAction SilentlyContinue; Start-Process powershell -Verb RunAs -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File',"`"$PSCommandPath`"",'lan-expose') }
            if (Test-Path $fLanOff) { Remove-Item $fLanOff -Force -ErrorAction SilentlyContinue; Start-Process powershell -Verb RunAs -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File',"`"$PSCommandPath`"",'lan-unexpose') }
            # Reconciliar Mailpit con su flag
            $mpOn = Test-Path $MailpitFlag
            if ($mpOn -and (Test-Path $Mailpit) -and -not (Mailpit-Up)) { Start-Mailpit }
            if (-not $mpOn -and (Mailpit-Up)) { Stop-Mailpit }
            # Reconciliar MariaDB con su flag
            $mdOn = Test-Path $MariaDbFlag
            if ($mdOn -and (Test-Path $Mysqld) -and -not (MariaDb-Up)) { Start-MariaDb }
            if (-not $mdOn -and (MariaDb-Up)) { Stop-MariaDb }
            # Reconciliar PostgreSQL con su flag
            $pgOn = Test-Path $PostgresFlag
            if ($pgOn -and (Test-Path $PgCtl) -and -not (Postgres-Up)) { Start-Postgres }
            if (-not $pgOn -and (Postgres-Up)) { Stop-Postgres }
            # Reconciliar MongoDB (+ mongo-express) con su flag
            $mongoOn = Test-Path $MongoDbFlag
            if ($mongoOn -and (Test-Path $Mongod) -and -not (MongoDb-Up)) { Start-MongoDb }
            if (-not $mongoOn -and (MongoDb-Up)) { Stop-MongoDb }
            if ($mongoOn -and (MongoDb-Up) -and -not (MongoExpress-Up)) { Start-MongoExpress }
            if ((-not $mongoOn -or -not (MongoDb-Up)) -and (MongoExpress-Up)) { Stop-MongoExpress }
            Process-Jobs
        } catch {}
        Start-Sleep -Seconds 1
    }
}
function Cmd-Restart {
    if (-not (Test-HttpdConfig)) { Err "Config invalida, no se reinicia."; return }
    Restart-Apache
    Ok "Apache reiniciado."
}
function Cmd-Reload {
    Info "Regenerando vhosts..."
    Regenerate-Vhosts
    if (Test-Admin) { Update-Hosts } else { Warn "Sin admin: no se actualizo hosts. Anade los dominios manualmente o corre 'setup'." }
    if (-not (Test-HttpdConfig)) { Err "Config invalida: reload abortado."; return }
    Restart-Apache
    Ok "Recargado."
}

# Recarga usada por el PANEL (se invoca en segundo plano). Aplica php.ini + vhosts y reinicia.
function Cmd-Apply {
    $log = Join-Path $ApacheLog "apply.log"
    "$(Get-Date -Format o)  apply: start" | Add-Content $log
    Set-PhpInis | Out-Null
    Set-Ssl
    Regenerate-Vhosts
    if (-not (Test-HttpdConfig)) { "$(Get-Date -Format o)  apply: CONFIG INVALIDA, abortado" | Add-Content $log; return }
    Start-Sleep -Milliseconds 800   # deja que el navegador reciba la respuesta antes de reiniciar
    Restart-Apache
    "$(Get-Date -Format o)  apply: done" | Add-Content $log
    Ok "Cambios aplicados."
}

# ============================================================
#  Sistema de TAREAS (crear proyectos: plantillas, WordPress, git)
#  El panel deja un .job en tmp\jobs\; el watcher lo ejecuta aqui.
# ============================================================
function Set-JobStatus($id, $name, $type, $state, $msg) {
    $jd = Join-Path $TmpDir "jobs"; New-Item -ItemType Directory -Force -Path $jd | Out-Null
    $o = @{ id=$id; name=$name; type=$type; state=$state; msg=$msg; time=(Get-Date -Format "HH:mm:ss") }
    [System.IO.File]::WriteAllText((Join-Path $jd "$id.status"), ($o | ConvertTo-Json -Compress), (New-Object System.Text.UTF8Encoding($false)))
}
function Add-SiteToConfig($name, $php) {
    $cfg = Get-Config
    if (-not ($cfg.sites.PSObject.Properties.Name -contains $name)) { $cfg.sites | Add-Member -NotePropertyName $name -NotePropertyValue ([pscustomobject]@{ php=$php }) -Force }
    else { $cfg.sites.$name.php = $php }
    Save-Config $cfg
}
# Pone/reemplaza una variable en un .env (formato KEY=valor). Si no existe, la anade al final.
function Set-EnvVar($envFile, $key, $value) {
    if (-not (Test-Path $envFile)) { return }
    $lines = Get-Content $envFile
    $found = $false
    # tambien reemplaza la linea si viene comentada (# KEY=...), tipico de los .env de ejemplo
    $out = foreach ($l in $lines) {
        if ($l -match "^\s*#?\s*$key\s*=") { $found = $true; "$key=$value" } else { $l }
    }
    if (-not $found) { $out += "$key=$value" }
    Set-Content -Path $envFile -Value $out -Encoding utf8
}
# Crea (si no existe) una base de datos MySQL a juego con el proyecto. Silencioso si MariaDB no esta arriba.
function New-ProjectDb($dbname, $projectDir, $projectType) {
    if (-not (MariaDb-Up)) { return $null }
    $mariadbExe = Join-Path $MariaDb "bin\mariadb.exe"
    if (-not (Test-Path $mariadbExe)) { return $null }
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try {
        $sql = 'CREATE DATABASE IF NOT EXISTS `' + $dbname + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        $null = & $mariadbExe --host=127.0.0.1 --port=3306 --user=root -e $sql 2>&1
        if ($projectType -eq 'laravel') {
            $envFile = Join-Path $projectDir ".env"
            Set-EnvVar $envFile "DB_CONNECTION" "mysql"
            Set-EnvVar $envFile "DB_HOST" "127.0.0.1"
            Set-EnvVar $envFile "DB_PORT" "3306"
            Set-EnvVar $envFile "DB_DATABASE" $dbname
            Set-EnvVar $envFile "DB_USERNAME" "root"
            Set-EnvVar $envFile "DB_PASSWORD" ""
        }
        return $dbname
    } catch { return $null }
    finally { $ErrorActionPreference = $prev }
}
function Download-WordPress($dir, $log) {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $zip = Join-Path $TmpDir "wp-latest.zip"
    "Descargando WordPress..." | Add-Content $log
    Invoke-WebRequest "https://wordpress.org/latest.zip" -OutFile $zip -UseBasicParsing -TimeoutSec 300
    $work = Join-Path $TmpDir ("wp-" + [System.IO.Path]::GetRandomFileName())
    Expand-Archive $zip $work -Force
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    Get-ChildItem (Join-Path $work "wordpress") -Force | Move-Item -Destination $dir -Force
    Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue; Remove-Item $zip -Force -ErrorAction SilentlyContinue
    "WordPress descomprimido." | Add-Content $log
}
function Run-Job($id, $job) {
    $name="$($job.name)"; $type="$($job.type)"; $php="$($job.php)"; $url="$($job.url)"; $withdb=[bool]$job.withdb; $extName="$($job.extName)"
    $logDir = Join-Path $Root "logs\jobs"; New-Item -ItemType Directory -Force -Path $logDir | Out-Null
    $log = Join-Path $logDir "$id.log"
    $dir = Join-Path $Www $name
    $phpExe = Join-Path $PhpBase "$php\php.exe"
    $composer = Join-Path $Root "bin\composer\composer.phar"
    Set-JobStatus $id $name $type "running" "Creando..."
    "== $type :: $name (PHP $php) ==" | Out-File $log -Encoding utf8
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $ok = $true; $err = ""
    try {
        switch ($type) {
            "blank"     { New-Item -ItemType Directory -Force -Path $dir | Out-Null; Set-Content (Join-Path $dir "index.php") "<?php`r`nphpinfo();" -Encoding utf8 }
            "laravel"   { & $phpExe $composer create-project laravel/laravel "$dir" --no-interaction 2>&1 | Add-Content $log; if ($LASTEXITCODE -ne 0) { $ok=$false; $err="Composer fallo (ver log)" } }
            "symfony"   { & $phpExe $composer create-project symfony/skeleton "$dir" --no-interaction 2>&1 | Add-Content $log; if ($LASTEXITCODE -ne 0) { $ok=$false; $err="Composer fallo (ver log)" } }
            "slim"      { & $phpExe $composer create-project slim/slim-skeleton "$dir" --no-interaction 2>&1 | Add-Content $log; if ($LASTEXITCODE -ne 0) { $ok=$false; $err="Composer fallo (ver log)" } }
            "wordpress" { Download-WordPress $dir $log }
            "git"       { & git clone "$url" "$dir" 2>&1 | Add-Content $log; if ($LASTEXITCODE -ne 0) { $ok=$false; $err="git clone fallo (ver log)" } elseif (Test-Path (Join-Path $dir "composer.json")) { "composer install..." | Add-Content $log; & $phpExe $composer install --no-interaction --working-dir="$dir" 2>&1 | Add-Content $log } }
            "xdebug"    { $dest = Join-Path $PhpBase "$php\ext\php_xdebug.dll"; "Descargando Xdebug: $url" | Add-Content $log; & curl.exe -s -L -o "$dest" "$url" 2>&1 | Add-Content $log; if ((-not (Test-Path $dest)) -or ((Get-Item $dest).Length -lt 20000)) { $ok=$false; $err="No se descargo la DLL de Xdebug"; Remove-Item $dest -Force -ErrorAction SilentlyContinue } else { "Xdebug descargado ($([math]::Round((Get-Item $dest).Length/1KB)) KB)." | Add-Content $log } }
            "phpext"    { $dest = Join-Path $PhpBase "$php\ext\php_$extName.dll"; "Descargando extension '$extName': $url" | Add-Content $log; & curl.exe -s -L -o "$dest" "$url" 2>&1 | Add-Content $log; if ((-not (Test-Path $dest)) -or ((Get-Item $dest).Length -lt 1024)) { $ok=$false; $err="No se descargo el .dll (revisa la URL)"; Remove-Item $dest -Force -ErrorAction SilentlyContinue } else { "Extension '$extName' descargada ($([math]::Round((Get-Item $dest).Length/1KB)) KB)." | Add-Content $log } }
            "mailpit"   { $mpDir = Join-Path $Bin "mailpit"; New-Item -ItemType Directory -Force -Path $mpDir | Out-Null; $zip = Join-Path $mpDir "mailpit.zip"; "Descargando Mailpit..." | Add-Content $log; & curl.exe -s -L -o "$zip" "https://github.com/axllent/mailpit/releases/latest/download/mailpit-windows-amd64.zip" 2>&1 | Add-Content $log; if (Test-Path $zip) { Expand-Archive $zip $mpDir -Force; Remove-Item $zip -Force -ErrorAction SilentlyContinue }; if (-not (Test-Path $Mailpit)) { $ok=$false; $err="No se descargo Mailpit" } else { "Mailpit descargado." | Add-Content $log } }
            "mariadb"   {
                $mdDir = Join-Path $Bin "mariadb"; New-Item -ItemType Directory -Force -Path $mdDir | Out-Null
                $zip = Join-Path $mdDir "mariadb.zip"
                "Descargando MariaDB 11.8 LTS (esto puede tardar)..." | Add-Content $log
                & curl.exe -s -L -o "$zip" "https://archive.mariadb.org/mariadb-11.8.8/winx64-packages/mariadb-11.8.8-winx64.zip" 2>&1 | Add-Content $log
                if (Test-Path $zip) {
                    $work = Join-Path $TmpDir ("md-" + [System.IO.Path]::GetRandomFileName())
                    Expand-Archive $zip $work -Force
                    $inner = Get-ChildItem $work -Directory | Select-Object -First 1
                    if ($inner) {
                        Get-ChildItem $mdDir -Force -ErrorAction SilentlyContinue | Where-Object { $_.Name -ne 'mariadb.zip' } | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
                        Get-ChildItem $inner.FullName -Force | Move-Item -Destination $mdDir -Force
                    }
                    Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue
                    Remove-Item $zip -Force -ErrorAction SilentlyContinue
                }
                if (-not (Test-Path $Mysqld)) { $ok=$false; $err="No se descargo MariaDB" } else { "MariaDB descargado." | Add-Content $log }
            }
            "postgres"  {
                $pgDir = Join-Path $Bin "postgres"; New-Item -ItemType Directory -Force -Path $pgDir | Out-Null
                $zip = Join-Path $pgDir "postgres.zip"
                "Descargando PostgreSQL 16 (esto puede tardar, ~350 MB)..." | Add-Content $log
                & curl.exe -s -L -o "$zip" "https://get.enterprisedb.com/postgresql/postgresql-16.14-2-windows-x64-binaries.zip" 2>&1 | Add-Content $log
                if (Test-Path $zip) {
                    $work = Join-Path $TmpDir ("pg-" + [System.IO.Path]::GetRandomFileName())
                    Expand-Archive $zip $work -Force
                    # el zip de EDB trae una carpeta raiz "pgsql" con bin/, lib/, share/...
                    $inner = Get-ChildItem $work -Directory | Select-Object -First 1
                    if ($inner) {
                        Get-ChildItem $pgDir -Force -ErrorAction SilentlyContinue | Where-Object { $_.Name -ne 'postgres.zip' } | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
                        Get-ChildItem $inner.FullName -Force | Move-Item -Destination $pgDir -Force
                    }
                    Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue
                    Remove-Item $zip -Force -ErrorAction SilentlyContinue
                }
                if (-not (Test-Path $PgCtl)) { $ok=$false; $err="No se descargo PostgreSQL" } else { "PostgreSQL descargado." | Add-Content $log }
            }
            "mongodb"   {
                $mgDir = Join-Path $Bin "mongodb"; New-Item -ItemType Directory -Force -Path $mgDir | Out-Null
                $zip = Join-Path $mgDir "mongodb.zip"
                "Descargando MongoDB Community 8.0 (esto puede tardar, ~450 MB)..." | Add-Content $log
                & curl.exe -s -L -o "$zip" "https://fastdl.mongodb.org/windows/mongodb-windows-x86_64-8.0.4.zip" 2>&1 | Add-Content $log
                if (Test-Path $zip) {
                    $work = Join-Path $TmpDir ("mg-" + [System.IO.Path]::GetRandomFileName())
                    Expand-Archive $zip $work -Force
                    # el zip trae una carpeta raiz "mongodb-win32-x86_64-..." con bin/
                    $inner = Get-ChildItem $work -Directory | Select-Object -First 1
                    if ($inner) {
                        Get-ChildItem $mgDir -Force -ErrorAction SilentlyContinue | Where-Object { $_.Name -ne 'mongodb.zip' } | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
                        Get-ChildItem $inner.FullName -Force | Move-Item -Destination $mgDir -Force
                    }
                    Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue
                    Remove-Item $zip -Force -ErrorAction SilentlyContinue
                }
                if (-not (Test-Path $Mongod)) { $ok=$false; $err="No se descargo MongoDB" } else { "MongoDB descargado." | Add-Content $log }
                # Node.js portable, solo si hace falta (runtime para mongo-express)
                if ($ok -and -not (Test-Path $NodeExe)) {
                    New-Item -ItemType Directory -Force -Path $NodeDir | Out-Null
                    $nzip = Join-Path $NodeDir "node.zip"
                    "Descargando Node.js LTS (runtime para mongo-express)..." | Add-Content $log
                    & curl.exe -s -L -o "$nzip" "https://nodejs.org/dist/v22.13.1/node-v22.13.1-win-x64.zip" 2>&1 | Add-Content $log
                    if (Test-Path $nzip) {
                        $nwork = Join-Path $TmpDir ("node-" + [System.IO.Path]::GetRandomFileName())
                        Expand-Archive $nzip $nwork -Force
                        $ninner = Get-ChildItem $nwork -Directory | Select-Object -First 1
                        if ($ninner) {
                            Get-ChildItem $NodeDir -Force -ErrorAction SilentlyContinue | Where-Object { $_.Name -ne 'node.zip' } | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
                            Get-ChildItem $ninner.FullName -Force | Move-Item -Destination $NodeDir -Force
                        }
                        Remove-Item $nwork -Recurse -Force -ErrorAction SilentlyContinue
                        Remove-Item $nzip -Force -ErrorAction SilentlyContinue
                    }
                    if (-not (Test-Path $NodeExe)) { $ok=$false; $err="No se descargo Node.js" } else { "Node.js descargado." | Add-Content $log }
                }
                # mongo-express (GUI web), instalado via npm del Node bundleado
                if ($ok -and -not (Test-Path $MongoExpressApp)) {
                    New-Item -ItemType Directory -Force -Path $MongoExpress | Out-Null
                    "Instalando mongo-express (npm)..." | Add-Content $log
                    & $NpmCmd install mongo-express --no-save --no-audit --no-fund --production --prefix "$MongoExpress" 2>&1 | Add-Content $log
                    if (-not (Test-Path $MongoExpressApp)) { $ok=$false; $err="No se instalo mongo-express (ver log)" } else { "mongo-express instalado." | Add-Content $log }
                }
            }
            "ftp_deploy" {
                $ftpHost = "$($job.ftpHost)"; $ftpPort = "$($job.ftpPort)"; $ftpUser = "$($job.ftpUser)"; $ftpPass = "$($job.ftpPass)"
                $ftpPath = "$($job.ftpPath)".Trim('/'); $ftpSsl = [bool]$job.ftpSsl
                $exclude = @('.git') + @("$($job.ftpExclude)" -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
                if (-not (Test-Path $dir)) { $ok=$false; $err="No existe la carpeta del proyecto" }
                else {
                    $files = Get-ChildItem $dir -Recurse -File | Where-Object {
                        $rel = $_.FullName.Substring($dir.Length+1) -replace '\\','/'
                        $skip = $false
                        foreach ($pat in $exclude) { if ($rel -like "*$pat*") { $skip = $true; break } }
                        -not $skip
                    }
                    $total = $files.Count; $i = 0; $failCount = 0
                    "Subiendo $total archivo(s) a $ftpHost..." | Add-Content $log
                    foreach ($f in $files) {
                        $i++
                        $rel = $f.FullName.Substring($dir.Length+1) -replace '\\','/'
                        $segments = (($ftpPath + '/' + $rel).Trim('/') -split '/') | ForEach-Object { [uri]::EscapeDataString($_) }
                        $remoteUrl = "ftp://" + $ftpHost + ":" + $ftpPort + "/" + ($segments -join '/')
                        $curlArgs = @('-s','-S','--ftp-create-dirs','-T', $f.FullName, $remoteUrl, '--user', "${ftpUser}:${ftpPass}", '--connect-timeout','15')
                        if ($ftpSsl) { $curlArgs += '--ssl-reqd' }
                        $curlOut = & curl.exe @curlArgs 2>&1
                        if ($LASTEXITCODE -ne 0) { $failCount++; "[$i/$total] FALLO: $rel -> $curlOut" | Add-Content $log }
                        elseif ($i % 10 -eq 0 -or $i -eq $total) { "[$i/$total] subido: $rel" | Add-Content $log }
                    }
                    if ($failCount -gt 0) { $ok=$false; $err="$failCount de $total archivo(s) fallaron (ver log)" }
                }
            }
            default     { $ok=$false; $err="Tipo desconocido: $type" }
        }
        if ($ok -and ($type -ne 'xdebug') -and ($type -ne 'phpext') -and ($type -ne 'mailpit') -and ($type -ne 'mariadb') -and ($type -ne 'postgres') -and ($type -ne 'mongodb') -and ($type -ne 'ftp_deploy') -and -not (Test-Path $dir)) { $ok=$false; $err="No se creo la carpeta del proyecto" }
    } catch { $ok=$false; $err=$_.Exception.Message }
    $ErrorActionPreference = $prev
    if ($ok) {
        if ($type -eq 'xdebug') {
            Set-PhpInis | Out-Null
            if (Test-HttpdConfig) { Restart-Apache }
            Set-JobStatus $id $name $type "done" "Xdebug activado en PHP $php"
        } elseif ($type -eq 'phpext') {
            Set-PhpInis | Out-Null
            if (Test-HttpdConfig) { Restart-Apache }
            Set-JobStatus $id $name $type "done" "Extension '$extName' instalada para PHP $php."
        } elseif ($type -eq 'mailpit') {
            Set-PhpInis | Out-Null
            Start-Mailpit
            if (Test-HttpdConfig) { Restart-Apache }
            Set-JobStatus $id $name $type "done" "Mailpit activo: buzon en http://localhost:8025"
        } elseif ($type -eq 'mariadb') {
            Start-MariaDb
            if (MariaDb-Up) { Set-JobStatus $id $name $type "done" "MySQL activo en 127.0.0.1:3306 (usuario root, sin contrasena)" }
            else { Set-JobStatus $id $name $type "error" "MariaDB se descargo pero no arranco (revisa logs\mariadb\error.log)" }
        } elseif ($type -eq 'postgres') {
            # Habilitar pdo_pgsql en los php.ini (la DLL ya viene con PHP) y reiniciar Apache
            # para que el panel pueda conectar; luego arrancar el servidor.
            Set-PhpInis | Out-Null
            if (Test-HttpdConfig) { Restart-Apache }
            Start-Postgres
            if (Postgres-Up) { Set-JobStatus $id $name $type "done" "PostgreSQL activo en 127.0.0.1:5432 (usuario postgres, sin contrasena)" }
            else { Set-JobStatus $id $name $type "error" "PostgreSQL se descargo pero no arranco (revisa logs\postgres)" }
        } elseif ($type -eq 'mongodb') {
            Start-MongoDb
            if (MongoDb-Up) { Start-MongoExpress }
            if ((MongoDb-Up) -and (MongoExpress-Up)) { Set-JobStatus $id $name $type "done" "MongoDB activo en 127.0.0.1:27017 (sin autenticacion). mongo-express en http://127.0.0.1:8081/" }
            else { Set-JobStatus $id $name $type "error" "MongoDB se descargo pero no arranco del todo (revisa logs\mongodb y logs\jobs)" }
        } elseif ($type -eq 'ftp_deploy') {
            Set-JobStatus $id $name $type "done" "Desplegado por FTP a $ftpHost ($total archivo(s))"
        } else {
            Add-SiteToConfig $name $php
            Set-PhpInis | Out-Null
            Regenerate-Vhosts
            if (Test-HttpdConfig) { Restart-Apache }
            $dbNote = ""
            if ($withdb -and $type -ne 'git') {
                $dbname = ($name -replace '[^a-zA-Z0-9_]','_')
                if (New-ProjectDb $dbname $dir $type) { $dbNote = " [BD: $dbname]" }
                else { $dbNote = " [aviso: no se pudo crear la BD, MySQL sigue apagado o no instalado]" }
            }
            Set-JobStatus $id $name $type "done" "Listo -> http://$name.$(Get-Tld)$dbNote"
        }
        "== DONE ==" | Add-Content $log
    } else {
        Set-JobStatus $id $name $type "error" $err
        "== ERROR: $err ==" | Add-Content $log
    }
}
function Process-Jobs {
    $jd = Join-Path $TmpDir "jobs"
    if (-not (Test-Path $jd)) { return }
    foreach ($jf in (Get-ChildItem $jd -Filter *.job -ErrorAction SilentlyContinue)) {
        $id = $jf.BaseName; $job = $null
        try { $job = Get-Content $jf.FullName -Raw | ConvertFrom-Json } catch {}
        Remove-Item $jf.FullName -Force -ErrorAction SilentlyContinue
        if ($job) { try { Run-Job $id $job } catch { Set-JobStatus $id "$($job.name)" "$($job.type)" "error" $_.Exception.Message } }
    }
}

function Update-Hosts {
    if (-not (Test-Admin)) { return }
    $cfg = Get-Config
    # 'localhost' es un nombre especial en Windows: el resolutor lo trata aparte y NO
    # respeta del todo el hosts (sigue devolviendo tambien ::1 aunque fijemos 127.0.0.1
    # aqui), asi que con Docker Desktop ocupando ::1:80 (Portainer u otro contenedor) el
    # navegador puede seguir cayendo ahi. Dejamos la entrada (no hace dano) pero la
    # alternativa que SI funciona siempre es "lua.test" a secas (no es un nombre
    # reservado, respeta el hosts al 100%) -> tambien sirve el panel.
    $tld = if ($cfg.tld) { $cfg.tld } else { $DefaultTld }
    $entries = @("127.0.0.1 localhost", "127.0.0.1 localhost.$tld", "127.0.0.1 $tld")
    foreach ($p in $cfg.sites.PSObject.Properties.Name) { $dom = Get-SiteDomain $cfg $p; $entries += "127.0.0.1 $dom www.$dom" }
    $content = Get-Content $HostsFile -ErrorAction SilentlyContinue
    $kept = @(); $inside = $false
    foreach ($l in $content) {
        if ($l -eq $HostsBegin) { $inside = $true; continue }
        if ($l -eq $HostsEnd)   { $inside = $false; continue }
        if (-not $inside) { $kept += $l }
    }
    Set-Content -Path $HostsFile -Value (@($kept) + $HostsBegin + $entries + $HostsEnd) -Encoding ascii
    ipconfig /flushdns | Out-Null
}

# ============================================================
#  Gestion de sitios
# ============================================================
function Cmd-AddSite($name, $php) {
    if (-not $name) { Err "Uso: .\lua.ps1 add-site <nombre> [version-php]"; return }
    $cfg = Get-Config
    if (-not $php) { $php = $cfg.defaultPhp }
    $av = Get-PhpVersions
    if ($av -and ($av -notcontains $php)) { Err "PHP $php no instalado. Disponibles: $($av -join ', ')"; return }
    $dir = Join-Path $Www $name
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
        Set-Content -Path (Join-Path $dir "index.php") -Value "<?php`r`nphpinfo();`r`n" -Encoding utf8
        Ok "Carpeta creada: www\$name (con index.php)"
    }
    if (-not ($cfg.sites.PSObject.Properties.Name -contains $name)) { $cfg.sites | Add-Member -NotePropertyName $name -NotePropertyValue ([pscustomobject]@{ php = $php }) -Force }
    else { $cfg.sites.$name.php = $php }
    Save-Config $cfg
    Cmd-Reload
    $tld = Get-Tld
    Ok "Sitio '$name' -> http://$name.$tld  [PHP $php]"
    if (-not (Test-Admin)) { Warn "Para abrirlo en el navegador anade a hosts (como admin):  127.0.0.1 $name.$tld" }
}
# Registra un proyecto que vive FUERA de www\ (ruta externa) con dominio propio.
function Cmd-AddExternal($name, $path, $domain, $php) {
    if (-not $name -or -not $path) { Err "Uso: .\lua.ps1 add-external <nombre> <ruta> [dominio] [version-php]"; return }
    if ($name -notmatch '^[a-z0-9][a-z0-9_-]{0,40}$') { Err "Nombre no valido (minusculas, numeros, - o _)."; return }
    if (-not (Test-Path $path)) { Err "La ruta no existe: $path"; return }
    $cfg = Get-Config
    if (-not $php) { $php = $cfg.defaultPhp }
    $av = Get-PhpVersions
    if ($av -and ($av -notcontains $php)) { Err "PHP $php no instalado. Disponibles: $($av -join ', ')"; return }
    $full = (Resolve-Path $path).Path
    $obj = [pscustomobject]@{ php = $php; path = (Fwd $full) }
    if ($domain) { $obj | Add-Member -NotePropertyName domain -NotePropertyValue $domain -Force }
    if ($cfg.sites.PSObject.Properties.Name -contains $name) { $cfg.sites.PSObject.Properties.Remove($name) }
    $cfg.sites | Add-Member -NotePropertyName $name -NotePropertyValue $obj -Force
    Save-Config $cfg
    Cmd-Reload
    $dom = if ($domain) { $domain } else { "$name.$(Get-Tld)" }
    Ok "Proyecto externo '$name' -> http://$dom  [PHP $php]  ($full)"
    if (-not (Test-Admin)) { Warn "Anade a hosts (como admin):  127.0.0.1 $dom" }
}
function Cmd-RemoveSite($name) {
    if (-not $name) { Err "Uso: .\lua.ps1 remove-site <nombre>"; return }
    $cfg = Get-Config
    if (($cfg.sites.PSObject.Properties.Name -contains $name)) { $cfg.sites.PSObject.Properties.Remove($name); Save-Config $cfg; Cmd-Reload; Ok "Sitio '$name' eliminado (carpeta intacta)." }
    else { Warn "No existe '$name'." }
}
function Cmd-SwitchPhp($name, $php) {
    if (-not $name -or -not $php) { Err "Uso: .\lua.ps1 switch-php <nombre> <version>"; return }
    $av = Get-PhpVersions
    if ($av -and ($av -notcontains $php)) { Err "PHP $php no instalado. Disponibles: $($av -join ', ')"; return }
    $cfg = Get-Config
    if (-not ($cfg.sites.PSObject.Properties.Name -contains $name)) { Err "No existe '$name'."; return }
    $cfg.sites.$name.php = $php; Save-Config $cfg; Cmd-Reload; Ok "'$name' ahora usa PHP $php."
}
function Cmd-ListSites {
    $cfg = Get-Config; $names = $cfg.sites.PSObject.Properties.Name
    if ($names.Count -eq 0) { Info "Sin sitios. Crea uno:  .\lua.ps1 add-site micliente"; return }
    foreach ($p in $names) { Write-Host ("  {0,-22} PHP {1,-5} http://{2}" -f $p,$cfg.sites.$p.php,(Get-SiteDomain $cfg $p)) }
}
function Cmd-ListPhp {
    $v = Get-PhpVersions
    if ($v.Count -eq 0) { Warn "No hay PHP en $PhpBase (ejecuta bootstrap.ps1)"; return }
    foreach ($x in $v) { Write-Host "  PHP $x" }
}
function Cmd-Status {
    Write-Host ""; Write-Host "  lua-server (solo PHP)  |  $Root" -ForegroundColor White
    Write-Host "  ------------------------------------------------"
    Write-Host ("  IP LAN          : {0}" -f (Get-LanIp))
    $apTxt = "parado"; $apC = "Yellow"; if (Apache-Up -or ((Service-Exists $SvcApache) -and (Get-Service $SvcApache).Status -eq 'Running')) { $apTxt="corriendo"; $apC="Green" }
    Write-Host "  Apache          : " -NoNewline; Write-Host $apTxt -ForegroundColor $apC
    Write-Host ("  PHP instalados  : {0}" -f ((Get-PhpVersions) -join ', '))
    $mdTxt = "apagado"; $mdC = "Yellow"; if (MariaDb-Up) { $mdTxt="corriendo (127.0.0.1:3306)"; $mdC="Green" }
    Write-Host "  MySQL (MariaDB) : " -NoNewline; Write-Host $mdTxt -ForegroundColor $mdC
    $mgTxt = "apagado"; $mgC = "Yellow"; if (MongoDb-Up) { $mgTxt="corriendo (127.0.0.1:27017)"; $mgC="Green" }
    Write-Host "  MongoDB         : " -NoNewline; Write-Host $mgTxt -ForegroundColor $mgC
    Write-Host "  Sitios:"; Cmd-ListSites; Write-Host ""
}
function Cmd-Hosts {
    $lan = Get-LanIp; $cfg = Get-Config
    Write-Host "`nTus companeros deben anadir esto a C:\Windows\System32\drivers\etc\hosts (como admin):`n"
    Write-Host $HostsBegin -ForegroundColor DarkGray
    foreach ($p in $cfg.sites.PSObject.Properties.Name) { $dom = Get-SiteDomain $cfg $p; Write-Host ("{0} {1} www.{1}" -f $lan,$dom) }
    Write-Host $HostsEnd -ForegroundColor DarkGray; Write-Host ""
}
function Cmd-Logs { Get-Content (Join-Path $ApacheLog "error.log") -Tail 40 -ErrorAction SilentlyContinue }
function Cmd-HttpsSetup {
    $tld = Get-Tld
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    New-Item -ItemType Directory -Force -Path $SslDir | Out-Null
    if (-not (Test-Path $Mkcert)) { Err "Falta mkcert (bin\mkcert\mkcert.exe). Ejecuta bootstrap.ps1."; $ErrorActionPreference=$prev; return }
    Info "Instalando CA local de confianza (mkcert -install)..."
    & $Mkcert -install
    Info "Generando certificado para *.$tld ..."
    & $Mkcert -cert-file "$SslCert" -key-file "$SslKey" "*.$tld" "$tld" "localhost" "127.0.0.1" "::1"
    $ErrorActionPreference = $prev
    if ((Test-Path $SslCert) -and (Test-Path $SslKey)) {
        Set-Content -Path $HttpsFlag -Value "1" -Encoding ascii
        Set-Ssl; Regenerate-Vhosts
        if (Test-HttpdConfig) { Restart-Apache; Ok "HTTPS activado: los sitios responden en https://<proyecto>.$tld con candado verde." }
        else { Err "La config SSL no es valida; revisa .\lua.ps1 logs" }
    } else { Err "No se genero el certificado." }
}
function Cmd-HttpsOff {
    Remove-Item $HttpsFlag -Force -ErrorAction SilentlyContinue
    Set-Ssl; Regenerate-Vhosts
    if (Test-HttpdConfig) { Restart-Apache }
    Ok "HTTPS desactivado."
}

# ============================================================
#  Arranque con Windows (toggle desde el panel: Configuracion del servidor)
#  - Apache como servicio de Windows (arranque automatico)
#  - El watcher como tarea programada (arranca sin necesidad de sesion)
# ============================================================
$WatcherTaskName = "lua-server-watcher"
function Cmd-StartupEnable {
    Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    if (-not (Service-Exists $SvcApache)) { Info "Instalando servicio $SvcApache..."; & $Httpd -k install -n $SvcApache }
    Set-Service -Name $SvcApache -StartupType Automatic
    & sc.exe failure $SvcApache reset= 86400 actions= restart/5000/restart/5000/restart/5000 | Out-Null
    Start-Service $SvcApache -ErrorAction SilentlyContinue
    Ok "Apache: servicio instalado, arranque automatico."

    Unregister-ScheduledTask -TaskName $WatcherTaskName -Confirm:$false -ErrorAction SilentlyContinue
    $action    = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$PSCommandPath`" watch"
    $trigger   = New-ScheduledTaskTrigger -AtStartup
    $principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
    $settings  = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit ([TimeSpan]::Zero)
    Register-ScheduledTask -TaskName $WatcherTaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
    Start-ScheduledTask -TaskName $WatcherTaskName -ErrorAction SilentlyContinue
    Ok "Watcher: tarea programada creada (arranca con Windows, sin iniciar sesion)."
    Ok "Arranque con Windows: ACTIVADO."
}
function Cmd-StartupDisable {
    if (Service-Exists $SvcApache) { Set-Service -Name $SvcApache -StartupType Manual; Ok "Apache: arranque vuelto a manual." }
    Unregister-ScheduledTask -TaskName $WatcherTaskName -Confirm:$false -ErrorAction SilentlyContinue
    Ok "Watcher: tarea programada eliminada."
    Ok "Arranque con Windows: DESACTIVADO (arranca a mano con .\lua.ps1 start)."
}
# Estado real (para el panel): 'on' solo si el servicio Y la tarea estan activos.
function Cmd-StartupStatus {
    $svc = if (Service-Exists $SvcApache) { Get-Service $SvcApache } else { $null }
    $task = Get-ScheduledTask -TaskName $WatcherTaskName -ErrorAction SilentlyContinue
    if ($svc -and $svc.StartType -eq 'Automatic' -and $task -and $task.State -ne 'Disabled') { Write-Output "on" } else { Write-Output "off" }
}

# ============================================================
#  Exponer en la red local (toggle desde el panel: Configuracion del servidor)
#  Abre el/los puerto(s) de Apache en el Firewall de Windows, SOLO para la subred
#  local (-RemoteAddress LocalSubnet) y sin tocar el perfil publico. El panel de
#  administracion sigue restringido a 127.0.0.1 por la config de Apache, asi que
#  esto expone los PROYECTOS a la LAN, no el panel.
# ============================================================
# Detecta las IPv4 de red local (descarta loopback y APIPA 169.254.x).
function Get-LanIPv4 {
    Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
        Sort-Object -Property @{Expression={$_.InterfaceMetric}} |
        Select-Object -ExpandProperty IPAddress
}
function Remove-LuaFwRules {
    foreach ($p in @('80','443')) {
        Get-NetFirewallRule -DisplayName "$FwRulePrefix HTTP $p" -ErrorAction SilentlyContinue |
            Remove-NetFirewallRule -ErrorAction SilentlyContinue
    }
}
function Cmd-LanExpose {
    if (-not (Test-Admin)) { Err "Necesita administrador para tocar el Firewall."; return }
    $ports = @('80')
    if (Test-Path $HttpsFlag) { $ports += '443' }
    Remove-LuaFwRules   # limpiar reglas previas para no duplicar / recoger cambio de puertos
    foreach ($p in $ports) {
        New-NetFirewallRule -DisplayName "$FwRulePrefix HTTP $p" -Description "lua-server: acceso desde la red local" `
            -Direction Inbound -Action Allow -Protocol TCP -LocalPort $p `
            -Profile Private,Domain -RemoteAddress LocalSubnet -ErrorAction SilentlyContinue | Out-Null
    }
    $ips = @(Get-LanIPv4)
    if ($ips.Count) { Set-Content -Path $LanIpFile -Value ($ips -join ',') -Encoding ascii }
    Set-Content -Path $LanExposeFlag -Value "1" -Encoding ascii
    Ok "Puerto(s) $($ports -join ', ') abiertos en el Firewall (solo subred local). IP(s) LAN: $($ips -join ', ')"
}
function Cmd-LanUnexpose {
    if (-not (Test-Admin)) { Err "Necesita administrador para tocar el Firewall."; return }
    Remove-LuaFwRules
    Remove-Item $LanExposeFlag -Force -ErrorAction SilentlyContinue
    Remove-Item $LanIpFile     -Force -ErrorAction SilentlyContinue
    Ok "Puertos cerrados en el Firewall. Ya no se expone a la red local."
}

function Cmd-Help { Get-Content $PSCommandPath -TotalCount 40 | ForEach-Object { $_ } }

switch ($Command.ToLower()) {
    "init"        { Cmd-Init }
    "start"       { Cmd-Start }
    "stop"        { Cmd-Stop }
    "restart"     { Cmd-Restart }
    "reload"      { Cmd-Reload }
    "status"      { Cmd-Status }
    "add-site"    { Cmd-AddSite $Arg1 $Arg2 }
    "add-external" { Cmd-AddExternal $Arg1 $Arg2 $Arg3 $Arg4 }
    "remove-site" { Cmd-RemoveSite $Arg1 }
    "switch-php"  { Cmd-SwitchPhp $Arg1 $Arg2 }
    "list-sites"  { Cmd-ListSites }
    "list-php"    { Cmd-ListPhp }
    "hosts"       { Cmd-Hosts }
    "apply"       { Cmd-Apply }
    "watch"       { Cmd-Watch }
    "https-setup" { Require-Admin; Cmd-HttpsSetup }
    "https-off"   { Cmd-HttpsOff }
    "hosts-sync"  { Require-Admin; Update-Hosts; Ok "Dominios sincronizados en el archivo hosts." }
    "setup"       { Require-Admin; & (Join-Path $Root "config\_setup.ps1") }
    "startup-enable"  { Require-Admin; Cmd-StartupEnable }
    "startup-disable" { Require-Admin; Cmd-StartupDisable }
    "startup-status"  { Cmd-StartupStatus }
    "lan-expose"      { Require-Admin; Cmd-LanExpose }
    "lan-unexpose"    { Require-Admin; Cmd-LanUnexpose }
    "logs"        { Cmd-Logs }
    default       { Cmd-Help }
}
