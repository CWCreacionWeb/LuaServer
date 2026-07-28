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
# Evita "detected dubious ownership": el watcher/tarea de arranque corren como
# SYSTEM (ver startup-enable), cuenta distinta de quien clono el repo. Se pasa
# por argumento en vez de "git config --global --add safe.directory" para no
# depender de tocar el perfil de SYSTEM en cada maquina.
$GitSafeDir = "safe.directory=" + ($Root -replace '\\', '/')
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
# --- Redis (portable) ---
# Redis no tiene build oficial para Windows, asi que se elige entre dos ports de la comunidad
# al instalar (el panel manda 'build' en el job, ver el case "redis" de Run-Job):
#   'redis8'  -> redis-windows/redis-windows 8.8.1, Redis moderno pero sobre una capa msys2
#                (trae sus DLLs al lado del .exe; por eso se ejecuta con el cwd en su carpeta).
#   'native5' -> tporadowski/redis 5.0.14.1, port Win32 nativo de verdad y sin dependencias,
#                pero congelado en Redis 5 (ultima release de 2022).
# Cual quedo instalado se recuerda en config\redis\build.txt: hace falta para el aviso del panel
# y para no reinstalar el otro por error al reactivar el motor.
$RedisDir       = Join-Path $Bin  "redis"
$RedisExe       = Join-Path $RedisDir "redis-server.exe"
$RedisDataDir   = Join-Path $Root "data\redis"
$RedisLogDir    = Join-Path $Root "logs\redis"
$RedisConf      = Join-Path $Root "config\redis\redis.conf"
$RedisBuildFile = Join-Path $Root "config\redis\build.txt"
$RedisFlag      = Join-Path $Root "config\redis.on"
$RedisPort      = 6379
# --- Supervisor de procesos por proyecto (colas, scheduler, Vite...) ---
$ProcsFile   = Join-Path $Root "config\procs.json"
$ProcsRunDir = Join-Path $TmpDir "procs"
$ProcsLogDir = Join-Path $Root "logs\procs"
# --- Node.js portable (runtime unicamente para mongo-express) ---
$NodeDir        = Join-Path $Bin  "node"
$NodeExe        = Join-Path $NodeDir "node.exe"
$NpmCmd         = Join-Path $NodeDir "npm.cmd"
# --- mongo-express (GUI web de MongoDB, corre sobre Node) ---
# Se instala clonando el repo (ver case "mongodb" de Run-Job), no "npm install mongo-express":
# el tag "latest" de npm apunta hoy a un release candidate (1.1.0-rc-4) cuyo tarball publicado
# no incluye build-assets.json (falta en su whitelist "files"), asi que una instalacion normal
# revienta al arrancar con ENOENT. Clonando el repo y compilando nosotros mismos (npm install
# genera ese archivo via el script "prepublish", que npm tambien ejecuta en instalaciones
# locales) se evita depender de ese tarball roto. Tag fijo en vez de una rama para que el
# instalador sea reproducible pase lo que pase con el estado del repo en GitHub.
$MongoExpress        = Join-Path $Root "bin\mongo-express"
$MongoExpressApp     = Join-Path $MongoExpress "app.js"
$MongoExpressTag     = "v1.1.0-rc-4"
$MongoExpressPidFile = Join-Path $TmpDir "mongo-express.pid"
$MongoExpressPort    = 8081
# --- WP-CLI (automatiza el alta guiada de WordPress: wp-config.php + wp core install) ---
$WpCli = Join-Path $Bin "wp-cli\wp-cli.phar"
# --- Exponer en la red local (abrir puerto en el Firewall de Windows) ---
$LanExposeFlag = Join-Path $Root "config\lanexpose.on"
$LanIpFile     = Join-Path $Root "config\lan-ip.txt"
$FwRulePrefix  = "lua-server"
# --- Actualizaciones (la plataforma es un repo de git) ---
# El fetch va por SSH contra origin, asi que SOLO puede hacerlo el watcher: corre en la sesion
# del usuario y tiene sus claves. El panel (Apache como SYSTEM) no las tiene, por eso se limita
# a leer el estado que este proceso deja escrito y a pedir acciones por archivo-senal.
$UpdateCfgFile    = Join-Path $Root "config\update.json"
$UpdateStatusFile = Join-Path $TmpDir "update-status.json"

$SvcApache  = "luaApache"
$DefaultTld = "lua.test"
$HostsBegin = "# === lua-server BEGIN (no editar a mano) ==="
$HostsEnd   = "# === lua-server END ==="

# extensiones PHP a habilitar (solo si existe su DLL). mysqli/pdo_mysql incluidas
# por si tus proyectos conectan a un MySQL (p.ej. en Docker) via 127.0.0.1.
# com_dotnet lo usa el panel para lanzar la recarga de Apache en segundo plano.
# pdo_odbc: lo necesita la pestana SQL Server. Va aqui (lista VERSIONADA) y no en
# config\php\extra-extensions.json, que esta en .gitignore: si estuviera alli, al actualizar
# la plataforma los demas equipos se quedarian sin el driver y la pestana no arrancaria.
# Set-PhpInis solo activa las que tengan su DLL, y php_pdo_odbc.dll viene con PHP en Windows.
# redis: la DLL no viene con PHP, la instala el motor de Redis desde PECL (ver el case "redis"
# de Run-Job). Va aqui igualmente para que Set-PhpInis la active en cuanto aparezca -- y va en
# ESTA lista, no en extra-extensions.json, por lo dicho arriba sobre pdo_odbc.
$WantExts   = @('curl','intl','mbstring','exif','mysqli','openssl','pdo_mysql','pdo_pgsql','pgsql','pdo_sqlite','sqlite3','zip','fileinfo','sodium','soap','bz2','com_dotnet','pdo_odbc','redis')

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
# Si 'path' esta definido se devuelve SIEMPRE, aunque Test-Path falle (disco externo/red
# desmontado): asi el DocumentRoot apunta a la ruta real (httpd -t solo avisa, no es fatal)
# y el 404 delata que el disco esta offline. Antes caia en silencio a www\<name>, sirviendo
# 404 sin pista, o -peor- el contenido de OTRO proyecto que existiera en www\<name>.
function Get-SiteBase($site, $name) {
    if ($site -and ($site.PSObject.Properties.Name -contains 'path') -and $site.path) { return $site.path }
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
    if (Service-Exists $SvcApache) {
        # Sin admin, Restart-Service sobre el servicio lanza excepcion (acceso denegado al SCM).
        # Antes eso abortaba en seco al LLAMANTE (Cmd-Apply se quedaba a medias tras regenerar
        # los php.ini, sin avisar de nada). Se degrada a aviso: quien SI puede reiniciarlo es el
        # propio panel (PHP corre bajo el servicio, como SYSTEM) o una consola elevada.
        try { Restart-Service $SvcApache -ErrorAction Stop }
        catch { Warn "Apache es un servicio y esta consola no esta elevada: no se pudo reiniciar. Usa el boton Reiniciar del panel (o abre PowerShell como administrador) para aplicar los cambios." }
        return
    }
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

# Escribe UTF-8 SIN BOM. Apache 2.4 en Windows lee su config como UTF-8, y los archivos
# de codigo (.php/.env) son fuente UTF-8: un BOM al inicio rompe <?php (headers already
# sent) o la 1a clave del .env. Set-Content -Encoding ascii convertia acentos de la RUTA
# absoluta embebida en '?' (rompiendo instalaciones bajo C:\Users\Vazquez\...), y -Encoding
# utf8 mete BOM; este helper evita ambos. Acepta string o string[] (une con CRLF).
function Write-Utf8NoBom($path, $content) {
    $text = if ($content -is [System.Array]) { [string]::Join("`r`n", $content) + "`r`n" } else { [string]$content }
    [System.IO.File]::WriteAllText($path, $text, (New-Object System.Text.UTF8Encoding($false)))
}
# Para ProcessStartInfo.Arguments (string) en vez de .ArgumentList: en esta PowerShell 5.1,
# ArgumentList devuelve $null (no soportado aqui), asi que hay que construir la linea de
# comandos a mano. Solo hace falta para el streaming a mariadb.exe (db_import_file) -- el
# resto del script ya invoca procesos via "& $exe @args", que no tiene este problema.
function Quote-Win32Arg($s) {
    $s = [string]$s
    if ($s -eq '') { return '""' }
    if ($s -notmatch '[\s"]') { return $s }
    return '"' + ($s -replace '"','\"') + '"'
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
    Write-Utf8NoBom $HttpdConf $c
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
        # -Encoding Default (ANSI del sistema, Windows-1252 aqui): PHP en Windows resuelve
        # rutas del sistema (extension_dir, tmp, session) por el codepage ANSI, no por UTF-8.
        # Preserva acentos de la ruta como bytes ANSI y no mete BOM (que romperia el parser).
        Set-Content -Path $ini -Value (@($lines) + $b.ToArray()) -Encoding Default
    }
    Ok "php.ini regenerados ($((Get-PhpVersions) -join ', '))"
}

# Escribe config\apache\ssl.conf: carga mod_ssl + Listen 443 solo si HTTPS esta activo.
function Set-Ssl {
    $on = (Test-Path $HttpsFlag) -and (Test-Path $SslCert) -and (Test-Path $SslKey)
    if ($on) {
        # ssl.conf se incluye ANTES que los vhosts de proyecto (httpd-lua.conf), asi que el
        # vhost :443 del panel de aqui es el PRIMERO -> se convierte en el default de HTTPS.
        # Sin este bloque, el primer proyecto alfabetico era el default en :443: el panel era
        # inalcanzable por HTTPS y cualquier Host desconocido (incl. desde LAN si se expone
        # 443) caia en ese proyecto SIN el filtro de IP del panel. Replica la restriccion a
        # loopback del vhost :80 del panel (httpd-lua.conf) y añade SSL. Heredoc @'...'@
        # (literal): ${LUAROOT} se escribe tal cual para que lo expanda Apache.
        Write-Utf8NoBom $SslConf @'
# Generado por lua.ps1 -- HTTPS activo
LoadModule ssl_module modules/mod_ssl.so
LoadModule socache_shmcb_module modules/mod_socache_shmcb.so
Listen 443
SSLCipherSuite HIGH:!aNULL:!MD5
SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
SSLSessionCache "shmcb:${LUAROOT}/tmp/ssl_scache(512000)"
SSLSessionCacheTimeout 300

# Vhost :443 por defecto: el panel (solo localhost). Debe ir el PRIMERO en :443.
<VirtualHost *:443>
    ServerName localhost
    ServerAlias lua.test 127.0.0.1
    DocumentRoot "${LUAROOT}/tools/dashboard"
    FcgidInitialEnv PHPRC "${LUAROOT}/bin/php/8.4"
    SSLEngine on
    SSLCertificateFile "${LUAROOT}/data/ssl/lua.pem"
    SSLCertificateKeyFile "${LUAROOT}/data/ssl/lua-key.pem"
    <Directory "${LUAROOT}/tools/dashboard">
        Options +ExecCGI +FollowSymLinks
        AllowOverride All
        DirectoryIndex index.php index.html
        FcgidWrapper "${LUAROOT}/bin/php/8.4/php-cgi.exe" .php
        <RequireAny>
            Require ip 127.0.0.1
            Require ip ::1
        </RequireAny>
    </Directory>
</VirtualHost>
'@
    } else {
        Write-Utf8NoBom $SslConf "# HTTPS desactivado"
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
    # phpMyAdmin es una consola de administracion de BD (root, sin contrasena): debe quedar
    # SIEMPRE restringida a loopback, igual que el panel, aunque se active "Exponer en LAN"
    # (que abre el puerto 80 del Firewall para los PROYECTOS). Se aplica como post-reemplazo
    # sobre el 'Require all granted' de la plantilla (ambos bloques :80 y :443) en vez de un
    # token en la plantilla: asi la plantilla es valida por si sola aunque un watcher con
    # codigo viejo la regenere (evita dejar un token sin sustituir que rompe Apache).
    if ($name -eq 'phpmyadmin') {
        $req = "<RequireAny>`r`n            Require ip 127.0.0.1`r`n            Require ip ::1`r`n        </RequireAny>"
        $out = $out.Replace('Require all granted', $req)
    }
    Write-Utf8NoBom (Join-Path $VhostDir "$name.conf") $out
}
function Regenerate-Vhosts {
    if (-not (Test-Path $VhostDir)) { New-Item -ItemType Directory -Force -Path $VhostDir | Out-Null }
    # Parsear sites.json ANTES de borrar los vhosts: si el JSON esta corrupto (edicion a
    # mano, escritura a medias), Get-Config lanza y abortamos SIN borrar, conservando los
    # vhosts actuales. Antes se borraban todos y luego fallaba el parseo -> cero vhosts ->
    # todos los proyectos caian al vhost por defecto hasta arreglar el JSON.
    try { $cfg = Get-Config } catch { Warn "sites.json invalido: se conservan los vhosts actuales ($($_.Exception.Message))"; return }
    Get-ChildItem $VhostDir -Filter *.conf -ErrorAction SilentlyContinue | Remove-Item -Force
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
# ¿Algun sitio distinto de $exceptName ya usa este dominio (o su alias www.)? Devuelve su
# nombre o $null. Dos vhosts con el mismo ServerName no dan error en Apache: sirve el que
# carga primero por orden de fichero y el otro proyecto queda muerto en silencio.
function Get-DomainClash($cfg, $domain, $exceptName) {
    $domain = ([string]$domain).ToLower(); $tld = Get-Tld
    foreach ($p in $cfg.sites.PSObject.Properties.Name) {
        if ($p -eq $exceptName) { continue }
        $s = $cfg.sites.$p
        $eff = if ($s -and ($s.PSObject.Properties.Name -contains 'domain') -and $s.domain) { ([string]$s.domain).ToLower() } else { "$p.$tld".ToLower() }
        if ($eff -eq $domain -or "www.$eff" -eq $domain -or $eff -eq "www.$domain") { return $p }
    }
    return $null
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
# Procesos cuyo ejecutable cuelga de $dir: identifica NUESTRAS instancias por ruta del
# binario, no por nombre de proceso global. Asi el watcher nunca toca un mysqld/mailpit
# ajeno del sistema (XAMPP, servicio propio del usuario, etc.). Mismo espiritu que
# Postgres-Up (que usa postmaster.pid). Nota: $_.Path puede ser inaccesible para procesos
# de otra cuenta; en ese caso quedan excluidos, que es justo lo deseado (no son nuestros).
function Get-LuaProcess($name, $dir) {
    $dirN = ([string]$dir).TrimEnd('\','/')
    Get-Process $name -ErrorAction SilentlyContinue | Where-Object {
        $exe = $null; try { $exe = $_.Path } catch {}
        $exe -and $exe.StartsWith($dirN, [System.StringComparison]::OrdinalIgnoreCase)
    }
}
function Mailpit-Up { [bool](Get-LuaProcess 'mailpit' (Join-Path $Bin 'mailpit')) }
function Start-Mailpit {
    if (-not (Test-Path $Mailpit)) { return }
    if (Mailpit-Up) { return }
    $db = Join-Path $Root "data\mailpit.db"
    Start-Process -FilePath $Mailpit -WindowStyle Hidden -ArgumentList @('--smtp','127.0.0.1:1025','--listen','127.0.0.1:8025','--db-file',"`"$db`"")
}
function Stop-Mailpit { Get-LuaProcess 'mailpit' (Join-Path $Bin 'mailpit') | Stop-Process -Force -ErrorAction SilentlyContinue }

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
    Set-Content -Path $MyIni -Value $c -Encoding Default
}
function MariaDb-Up { [bool](Get-LuaProcess 'mysqld' $MariaDb) }
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
    Get-LuaProcess 'mysqld' $MariaDb | Stop-Process -Force -ErrorAction SilentlyContinue
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
    # Adopcion de huerfanos: si un mongod ya escucha nuestro puerto pero sin mongod.pid (p.ej.
    # un stop anterior borro el pid sin lograr matar el proceso), lanzar otro seria inutil (el
    # puerto esta cogido) y el watcher se pasaria la vida engendrando mongods condenados. Se
    # identifica por el LISTENER del puerto + nombre de proceso, no por ruta del binario: un
    # mongod arrancado por el watcher de SYSTEM tiene la ruta ILEGIBLE desde una sesion normal
    # (asi se descubrio este caso). La ruta se usa solo como filtro de exclusion cuando SI es
    # legible y apunta fuera de bin\mongodb (un mongod ajeno del usuario: ese no se toca).
    $lst = Get-NetTCPConnection -LocalPort $MongoPort -State Listen -ErrorAction SilentlyContinue |
        Where-Object { $_.LocalAddress -eq '127.0.0.1' -or $_.LocalAddress -eq '0.0.0.0' } | Select-Object -First 1
    if ($lst) {
        $p = Get-Process -Id $lst.OwningProcess -ErrorAction SilentlyContinue
        if ($p -and $p.ProcessName -eq 'mongod') {
            $exe = $null; try { $exe = $p.Path } catch {}
            if (-not $exe -or $exe.StartsWith($MongoDb, [System.StringComparison]::OrdinalIgnoreCase)) {
                New-Item -ItemType Directory -Force -Path $MongoDataDir | Out-Null
                Set-Content -Path (Join-Path $MongoDataDir "mongod.pid") -Value $p.Id -Encoding ascii
                return
            }
        }
        # El puerto lo tiene otra cosa (un contenedor, un mongod ajeno): arrancar seria inutil.
        return
    }
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
    # Borrar el pid SOLO si el proceso ya no esta: si el kill fallo (p.ej. lo arranco un watcher
    # de SYSTEM y esta consola no puede matarlo), borrar el pid dejaria un mongod HUERFANO que
    # bloquea el puerto sin que nadie lo reconozca como propio -- justo el estado que la
    # adopcion de Start-MongoDb tiene que reparar despues.
    if (-not (MongoDb-Up)) { Remove-Item $pidFile -Force -ErrorAction SilentlyContinue }
    else { Warn "No se pudo detener MongoDB (PID $((Get-Content $pidFile -TotalCount 1 -ErrorAction SilentlyContinue))): puede requerir una consola elevada." }
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
    # OJO: esta version (1.1.0-rc-*, reescritura a ESM) cambio silenciosamente la API de
    # variables de entorno documentada historicamente por la imagen Docker oficial:
    # - Ya NO arma la connection string a partir de ME_CONFIG_MONGODB_SERVER/_PORT (los
    #   ignora sin avisar); hace falta darsela ya construida en ME_CONFIG_MONGODB_URL.
    # - Ya NO lee ME_CONFIG_SITE_PORT (usa el "PORT" generico de Node/Express).
    # - No existe ME_CONFIG_SITE_HOST: el host de escucha solo se puede fijar via la
    #   variable "VCAP_APP_HOST" (residuo de su soporte a Cloud Foundry). Sin esto usa su
    #   default 'localhost', que en esta clase de maquinas Windows resuelve a la IPv6 ::1
    #   (ver trampa nº2 de CLAUDE.md) y deja 127.0.0.1:8081 sin nadie escuchando aunque el
    #   proceso este vivo.
    # - express-session exige ahora un secreto explicito o tira 500 en toda peticion; sin
    #   autenticacion real de por medio (BASICAUTH_ENABLED=false) su valor es irrelevante,
    #   solo hace falta que exista.
    $env:ME_CONFIG_MONGODB_URL         = "mongodb://127.0.0.1:$MongoPort"
    $env:VCAP_APP_HOST                 = "127.0.0.1"
    $env:PORT                          = "$MongoExpressPort"
    $env:ME_CONFIG_BASICAUTH_ENABLED   = "false"
    $env:ME_CONFIG_MONGODB_ENABLE_ADMIN= "true"
    $env:ME_CONFIG_SITE_SESSIONSECRET  = "lua-server-mongo-express"
    $proc = Start-Process -FilePath $NodeExe -ArgumentList @("`"$MongoExpressApp`"") -WindowStyle Hidden -PassThru -WorkingDirectory $MongoExpress
    Set-Content -Path $MongoExpressPidFile -Value $proc.Id -Encoding ascii
}
function Stop-MongoExpress {
    if (Test-Path $MongoExpressPidFile) {
        $thePid = Get-Content $MongoExpressPidFile -TotalCount 1 -ErrorAction SilentlyContinue
        if ($thePid) { Stop-Process -Id ([int]$thePid) -Force -ErrorAction SilentlyContinue }
    }
    Remove-Item $MongoExpressPidFile -Force -ErrorAction SilentlyContinue
}

# ---------------- Redis (portable, mismo patron que MongoDB/PostgreSQL) ----------------
# "Up" se comprueba por el .pid que NOSOTROS le pedimos escribir en redis.conf, no por nombre
# de proceso: en esta maquina puede haber otro redis-server.exe (Docker, WSL, instalacion
# ajena) y matarlo o darlo por nuestro seria un desastre. Mismo criterio que Postgres-Up
# (postmaster.pid) y MongoDb-Up.
function Redis-Build {
    if (Test-Path $RedisBuildFile) { return (Get-Content $RedisBuildFile -Raw).Trim() }
    return ""
}
# URL del php_redis.dll oficial de PECL para una version de PHP. Tres cosas tienen que casar
# EXACTAS o PHP no carga la DLL (y ademas falla en silencio en el log de Apache, no en pantalla):
#   1. La version de PHP.
#   2. NTS (non-thread-safe): es lo que usa este servidor. Se sirve con mod_fcgid + php-cgi.exe,
#      no mod_php, asi que los builds son NTS -- comprobable porque bin\php\<ver>\ tiene php8.dll
#      y NO php8ts.dll. Un .dll TS aqui no carga.
#   3. El toolset de VC con el que se compilo PHP: vc14 (7.1), vc15 (7.2-7.4), vs16 (8.0-8.3),
#      vs17 (8.4+).
# Y la version de phpredis no es libre: la rama 6.x soporta 7.4 y 8.x, pero para 7.1-7.3 hay que
# bajar a ramas viejas (la ultima que publico DLL para cada una). De ahi el mapa explicito.
$PhpRedisBuilds = @{
    '7.1' = '5.1.1/php_redis-5.1.1-7.1-nts-vc14-x64.zip'
    '7.2' = '5.1.1/php_redis-5.1.1-7.2-nts-vc15-x64.zip'
    '7.3' = '5.3.4/php_redis-5.3.4-7.3-nts-vc15-x64.zip'
    '7.4' = '6.3.0/php_redis-6.3.0-7.4-nts-vc15-x64.zip'
    '8.0' = '6.3.0/php_redis-6.3.0-8.0-nts-vs16-x64.zip'
    '8.1' = '6.3.0/php_redis-6.3.0-8.1-nts-vs16-x64.zip'
    '8.2' = '6.3.0/php_redis-6.3.0-8.2-nts-vs16-x64.zip'
    '8.3' = '6.3.0/php_redis-6.3.0-8.3-nts-vs16-x64.zip'
    '8.4' = '6.3.0/php_redis-6.3.0-8.4-nts-vs17-x64.zip'
    '8.5' = '6.3.0/php_redis-6.3.0-8.5-nts-vs17-x64.zip'
}
function Get-PhpRedisUrl($ver) {
    if (-not $PhpRedisBuilds.ContainsKey("$ver")) { return $null }
    return "https://windows.php.net/downloads/pecl/releases/redis/$($PhpRedisBuilds[$ver])"
}
function Set-RedisConf {
    New-Item -ItemType Directory -Force -Path (Split-Path $RedisConf) | Out-Null
    New-Item -ItemType Directory -Force -Path $RedisDataDir | Out-Null
    New-Item -ItemType Directory -Force -Path $RedisLogDir  | Out-Null
    # Rutas con barra hacia adelante (Fwd): el parser de redis.conf trata la barra invertida
    # como escape, y una ruta tipo C:\personal\... acaba interpretada mal.
    $dd      = Fwd $RedisDataDir
    $log     = Fwd (Join-Path $RedisLogDir "redis.log")
    $pidPath = Fwd (Join-Path $RedisDataDir "redis.pid")
    Set-Content -Path $RedisConf -Encoding ascii -Value @(
        "# Generado por lua-server -- no editar a mano (se sobrescribe en cada init/start)",
        "bind 127.0.0.1",
        "port $RedisPort",
        # Sin contrasena, igual que el root de MariaDB y el trust de PostgreSQL: solo escucha
        # en 127.0.0.1, asi que no sale de esta maquina.
        "protected-mode yes",
        "dir $dd",
        "logfile `"$log`"",
        "pidfile `"$pidPath`"",
        # daemonize no existe en Windows (ninguno de los dos ports lo soporta): el proceso se
        # lanza oculto con Start-Process y se controla por su PID, como mongod.
        "daemonize no",
        # Persistencia RDB con los intervalos por defecto de Redis. Para un almacen de
        # desarrollo (cache/sesiones/colas) sobra, y evita perder las claves al reiniciar.
        "save 900 1",
        "save 300 10",
        "save 60 10000",
        "dbfilename dump.rdb",
        # Sin esto, en Windows un fallo al persistir el RDB deja a Redis rechazando escrituras
        # con MISCONF hasta que se arregle a mano -- comportamiento pesimo en local.
        "stop-writes-on-bgsave-error no"
    )
}
function Redis-Up {
    $pidFile = Join-Path $RedisDataDir "redis.pid"
    if (-not (Test-Path $pidFile)) { return $false }
    $thePid = Get-Content $pidFile -TotalCount 1 -ErrorAction SilentlyContinue
    if (-not $thePid) { return $false }
    $p = Get-Process -Id ([int]$thePid) -ErrorAction SilentlyContinue
    return ($p -and $p.ProcessName -eq 'redis-server')
}
function Start-Redis {
    if (-not (Test-Path $RedisExe)) { return }
    if (Redis-Up) { return }
    Set-RedisConf
    # -WorkingDirectory en su propia carpeta: el build msys2 (redis8) carga sus DLLs de al lado
    # del .exe y no arranca si el cwd es otro.
    Start-Process -FilePath $RedisExe -WindowStyle Hidden -ArgumentList @("`"$(Fwd $RedisConf)`"") -WorkingDirectory $RedisDir
}
# ---------------- Supervisor de procesos por proyecto ----------------
# Mantiene vivos procesos largos de los proyectos (colas de Laravel, scheduler, Vite/npm...)
# definidos en config\procs.json (NO versionado: referencia proyectos de esta maquina, igual
# que sites.json). El panel solo edita ese json y deja flags; quien arranca, vigila y reinicia
# es el watcher -- que para eso ya es un supervisor.
#
# Estado en tmp\procs\: <id>.pid ("pid;starttime" -- la hora de arranque en FileTime detecta
# PIDs reciclados, mismo espiritu que los pidfile de los motores), <id>.restart (flag del
# panel), state.json (lo escribe el watcher SOLO al cambiar; el panel lo lee para pintar
# badges sin ejecutar tasklist por cada proceso).
function Get-Procs {
    if (-not (Test-Path $ProcsFile)) { return @() }
    try {
        $j = Get-Content $ProcsFile -Raw | ConvertFrom-Json
        if ($j -is [array]) { return @($j) } else { return @() }
    } catch { return @() }
}
function Proc-PidFile($id) { Join-Path $ProcsRunDir "$id.pid" }
function Proc-Up($id) {
    $pf = Proc-PidFile $id
    if (-not (Test-Path $pf)) { return $false }
    $parts = ((Get-Content $pf -Raw -ErrorAction SilentlyContinue).Trim()) -split ';'
    if ($parts.Count -lt 2) { return $false }
    $p = Get-Process -Id ([int]$parts[0]) -ErrorAction SilentlyContinue
    if (-not $p) { return $false }
    # PID reciclado por Windows != nuestro proceso: la hora de arranque tiene que casar
    # (tolerancia 2s en ticks de FileTime). Si StartTime no es legible (proceso de otra
    # cuenta), se da por bueno: el PID existia y lo escribimos nosotros.
    try { return ([math]::Abs($p.StartTime.ToFileTimeUtc() - [long]$parts[1]) -lt 20000000) } catch { return $true }
}
function Start-Proc($def) {
    $id = "$($def.id)"
    New-Item -ItemType Directory -Force -Path $ProcsRunDir | Out-Null
    New-Item -ItemType Directory -Force -Path $ProcsLogDir | Out-Null
    $cfg = Get-Config
    $projName = "$($def.project)"
    if (-not ($cfg.sites.PSObject.Properties.Name -contains $projName)) { return $false }
    $cwd = Get-SiteBase $cfg.sites.$projName $projName
    if (-not (Test-Path $cwd)) { return $false }
    $log = Join-Path $ProcsLogDir "$id.log"
    # Wrapper .cmd con las mismas soluciones ya ganadas por el runner/terminal (trampa nº5):
    # chcp 65001, HOME propio para composer/npm (autocontenido y valido tambien como SYSTEM),
    # bin\php\<ver> del proyecto al frente del PATH y PHPRC apuntandole (PHPRC heredado GANA a
    # "junto al .exe": sin el set, "php" del proyecto leeria el php.ini de otra version).
    $homeDir = Join-Path $Root "tmp\home"
    New-Item -ItemType Directory -Force -Path (Join-Path $homeDir "AppData\Roaming") | Out-Null
    New-Item -ItemType Directory -Force -Path (Join-Path $homeDir "composer") | Out-Null
    $w  = "@echo off`r`n"
    $w += "chcp 65001 >NUL`r`n"
    $w += "set `"APPDATA=$homeDir\AppData\Roaming`"`r`n"
    $w += "set `"COMPOSER_HOME=$homeDir\composer`"`r`n"
    $pathParts = @()
    $phpVer = "$($def.php)"
    if ($phpVer -match '^\d\.\d$' -and (Test-Path (Join-Path $PhpBase $phpVer))) {
        $pathParts += (Join-Path $PhpBase $phpVer)
        $w += "set `"PHPRC=$(Join-Path $PhpBase $phpVer)`"`r`n"
    }
    # PATH de maquina desde el registro: el watcher puede correr como SYSTEM, cuyo PATH de
    # sesion no incluye las entradas tipicas del usuario; el de HKLM cubre node/git/etc.
    try {
        $mp = [Environment]::GetEnvironmentVariable('Path','Machine')
        if ($mp) { $pathParts += $mp }
    } catch {}
    if ($pathParts.Count -gt 0) { $w += "set `"PATH=$($pathParts -join ';');%PATH%`"`r`n" }
    $w += "cd /d `"$cwd`"`r`n"
    $w += "$($def.cmd)`r`n"
    $cmdf = Join-Path $ProcsRunDir "$id.cmd"
    # Write-Utf8NoBom y NO Set-Content -Encoding UTF8: en PS 5.1 eso escribe BOM, y cmd.exe no
    # lo entiende -- la primera linea del wrapper llega como "ï»¿@echo off" y falla.
    Write-Utf8NoBom $cmdf $w
    Add-Content -Path $log -Value "[lua] $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') arrancando: $($def.cmd)"
    # cmd /c con el wrapper y el log en append (>>): los reinicios se acumulan en el mismo log.
    $args = '/c ""{0}" >> "{1}" 2>&1"' -f $cmdf, $log
    $p = Start-Process -FilePath "cmd.exe" -ArgumentList $args -WindowStyle Hidden -PassThru
    $stamp = 0; try { $stamp = $p.StartTime.ToFileTimeUtc() } catch {}
    Set-Content -Path (Proc-PidFile $id) -Value "$($p.Id);$stamp" -Encoding ascii
    return $true
}
function Stop-Proc($id) {
    $pf = Proc-PidFile $id
    if (Test-Path $pf) {
        $parts = ((Get-Content $pf -Raw -ErrorAction SilentlyContinue).Trim()) -split ';'
        # Solo se llama a taskkill si el PID sigue vivo. Sobre un PID muerto, taskkill escribe
        # en stderr ("no se encontro el proceso") y, con el $ErrorActionPreference = "Stop"
        # global de este script + la redireccion 2>&1, PowerShell 5.1 lo convierte en EXCEPCION
        # terminante: el catch del bucle del watcher la atrapaba y se saltaba el resto de la
        # vuelta (jobs, actualizaciones...) en cada iteracion, para siempre.
        if ($parts.Count -ge 1 -and $parts[0] -and (Get-Process -Id ([int]$parts[0]) -ErrorAction SilentlyContinue)) {
            $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
            # /T mata el ARBOL entero: sin el quedaria vivo el php.exe/node.exe hijo del cmd.exe
            # (la misma leccion que term_stop en el panel).
            & taskkill /F /T /PID $parts[0] *> $null
            $ErrorActionPreference = $prev
            $log = Join-Path $ProcsLogDir "$id.log"
            Add-Content -Path $log -Value "[lua] $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') detenido." -ErrorAction SilentlyContinue
        }
        Remove-Item $pf -Force -ErrorAction SilentlyContinue
    }
}
function Stop-AllProcs {
    if (-not (Test-Path $ProcsRunDir)) { return }
    foreach ($pf in (Get-ChildItem $ProcsRunDir -Filter *.pid -ErrorAction SilentlyContinue)) {
        Stop-Proc $pf.BaseName
    }
    Remove-Item (Join-Path $ProcsRunDir "state.json") -Force -ErrorAction SilentlyContinue
}

function Stop-Redis {
    # Se intenta primero un apagado limpio con redis-cli (vuelca el RDB antes de salir); si no
    # esta o no responde, se mata por el PID propio. Con persistencia RDB perder el ultimo
    # snapshot en un almacen de desarrollo es aceptable, igual que en Stop-MariaDb/Stop-MongoDb.
    $pidFile = Join-Path $RedisDataDir "redis.pid"
    if (Redis-Up) {
        $cli = Join-Path $RedisDir "redis-cli.exe"
        if (Test-Path $cli) { & $cli -h 127.0.0.1 -p $RedisPort shutdown nosave 2>$null | Out-Null }
        for ($i=0; $i -lt 20; $i++) { if (-not (Redis-Up)) { break }; Start-Sleep -Milliseconds 250 }
        if (Redis-Up) {
            $thePid = Get-Content $pidFile -TotalCount 1 -ErrorAction SilentlyContinue
            if ($thePid) { Stop-Process -Id ([int]$thePid) -Force -ErrorAction SilentlyContinue }
            for ($i=0; $i -lt 20; $i++) { if (-not (Redis-Up)) { break }; Start-Sleep -Milliseconds 250 }
        }
    }
    Remove-Item $pidFile -Force -ErrorAction SilentlyContinue
}

function Cmd-Start {
    # -ErrorAction SilentlyContinue: igual que Cmd-Stop con Stop-Service. Una vez Apache es
    # servicio de Windows, Start-Service pide permiso de gestion del SCM incluso si el servicio
    # ya esta arriba (falla con "acceso denegado" desde una PowerShell no elevada) -- sin esto,
    # el error paraba aqui todo el resto de Cmd-Start (watcher, Mailpit, MariaDB...) en seco.
    if (Service-Exists $SvcApache) {
        Start-Service $SvcApache -ErrorAction SilentlyContinue
        if (Apache-Up) { Ok "Apache (servicio) arriba" } else { Info "Apache (servicio): no se pudo gestionar sin permisos de administrador, pero sigue arriba si ya lo estaba" }
    }
    elseif (Apache-Up) { Info "Apache ya estaba arriba" }
    else { Start-Process -FilePath $Httpd -WindowStyle Hidden; Ok "Apache arrancado" }
    Start-Watcher
    if (Test-Path $MailpitFlag) { Start-Mailpit }
    if (Test-Path $MariaDbFlag) { Start-MariaDb }
    if (Test-Path $PostgresFlag) { Start-Postgres }
    if (Test-Path $MongoDbFlag) { Start-MongoDb; if (MongoDb-Up) { Start-MongoExpress } }
    if (Test-Path $RedisFlag) { Start-Redis }
    Write-Host ""; Ok "Panel:  http://localhost"
    Cmd-ListSites
}
function Cmd-Stop {
    if (Service-Exists $SvcApache) { Stop-Service $SvcApache -Force -ErrorAction SilentlyContinue } else { Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force }
    # Parar el watcher. Si corre como SYSTEM (tarea programada de "Arrancar con Windows") una
    # consola no elevada NO puede matarlo: antes ese fallo se tragaba en silencio y el watcher
    # viejo seguia vivo con su codigo cacheado, robando jobs a cualquier watcher nuevo (ver el
    # comentario largo en Cmd-Watch). Ahora se avisa. Aun asi no es grave: desde este commit el
    # watcher se autorecarga al detectar que lua.ps1 ha cambiado.
    $pf = Join-Path $TmpDir "watch.pid"
    if (Test-Path $pf) {
        $wp = Get-Content $pf -ErrorAction SilentlyContinue
        if ($wp) {
            Stop-Process -Id ([int]$wp) -Force -ErrorAction SilentlyContinue
            Start-Sleep -Milliseconds 300
            if (Get-Process -Id ([int]$wp) -ErrorAction SilentlyContinue) {
                Warn "No se pudo detener el watcher (PID $wp): seguramente corre como SYSTEM. Se pondra al dia solo al detectar cambios en lua.ps1."
            }
        }
        Remove-Item $pf -Force -ErrorAction SilentlyContinue
    }
    # Sin esto el badge del panel seguiria en verde hasta que el latido caduque (15s).
    Remove-Item (Join-Path $TmpDir "watch.beat") -Force -ErrorAction SilentlyContinue
    Stop-Mailpit
    Stop-MariaDb
    Stop-MongoExpress
    Stop-MongoDb
    Stop-Redis
    Stop-AllProcs
    Ok "Apache detenido."
}

# Watcher: proceso independiente que aplica los cambios pedidos desde el panel web.
# El panel solo crea archivos-senal en tmp\; este proceso los ejecuta (no es hijo de Apache).
function Cmd-Watch {
    $pf = Join-Path $TmpDir "watch.pid"; Set-Content -Path $pf -Value $PID -Encoding ascii
    # Fecha de lua.ps1 tal y como estaba al cargar ESTE proceso. PowerShell compila el script
    # entero en memoria al arrancar: a partir de aqui, editar el archivo no cambia nada de lo
    # que ejecuta este watcher (la trampa nº1 de CLAUDE.md). Mas abajo, en el bucle, se compara
    # con la fecha actual y el watcher se sustituye por uno nuevo en cuanto detecta un cambio.
    #
    # Esto importa mucho mas de lo que parece: con "Arrancar con Windows" activo el watcher es
    # una tarea programada de SYSTEM, y entonces `lua.ps1 stop` desde una consola normal NO
    # puede matarlo (acceso denegado, y el error va a /dev/null por -ErrorAction
    # SilentlyContinue). El watcher viejo sobrevive, `lua.ps1 start` arranca ADEMAS otro como
    # usuario, y los dos compiten por los jobs -- Process-Jobs borra el .job en cuanto lo lee,
    # asi que gana el primero que pase por ahi. Si gana el de SYSTEM (con codigo de hace dias),
    # las features nuevas fallan con "Tipo desconocido: <tipo>" aunque el codigo en disco este
    # perfecto y aunque acabes de reiniciar. Autorecargandose, cualquier watcher -- incluido el
    # de SYSTEM, que se relanza a si mismo con sus mismos privilegios -- se pone al dia solo.
    $selfStamp = try { (Get-Item $PSCommandPath).LastWriteTimeUtc } catch { [datetime]::MinValue }
    $wBeat  = Join-Path $TmpDir "watch.beat"
    $fApply = Join-Path $TmpDir "apply.flag"
    $fHosts = Join-Path $TmpDir "hosts.flag"
    $fHttps = Join-Path $TmpDir "https.flag"
    $fStartupOn  = Join-Path $TmpDir "startup-on.flag"
    $fStartupOff = Join-Path $TmpDir "startup-off.flag"
    $fLanOn      = Join-Path $TmpDir "lanexpose-on.flag"
    $fLanOff     = Join-Path $TmpDir "lanexpose-off.flag"
    # Backoff de arranque de BD: si MariaDB/Postgres no logran mantenerse arriba (puerto
    # ocupado, datadir corrupto), no reintentar cada 1s (spawn de procesos condenados +
    # reescritura de my.ini en bucle). Se reintenta como mucho cada 30s.
    $nextMariaTry = [datetime]::MinValue
    $nextPgTry    = [datetime]::MinValue
    $nextRedisTry = [datetime]::MinValue
    $nextMongoTry = [datetime]::MinValue
    # Memoria del supervisor de procesos (por id): ultima hora de arranque, fallos rapidos
    # seguidos y proximo intento. Vive en el proceso del watcher: si el watcher se relanza
    # (autorecarga), el contador se resetea, lo cual es aceptable -- como mucho un ciclo mas
    # de reintentos.
    $procMem   = @{}
    $procState = ""
    $fUpdChk      = Join-Path $TmpDir "update-check.flag"
    $fUpdNow      = Join-Path $TmpDir "update-now.flag"
    # Primera comprobacion a los 30s de arrancar (no en el mismo instante: el arranque ya
    # tiene bastante trabajo, y si no hay red todavia daria un error inutil).
    $nextUpdTry   = [datetime]::Now.AddSeconds(30)
    while ($true) {
        try {
            # Autorecarga: si lua.ps1 ha cambiado desde que arranco este proceso, relanzarse y
            # salir. Se compara contra la fecha capturada al arrancar (no contra la anterior
            # vuelta del bucle), asi el relevo ya nace al dia y no se produce un bucle de
            # reinicios. Va lo PRIMERO del bucle para no dejar a medias un job con codigo viejo.
            $nowStamp = try { (Get-Item $PSCommandPath).LastWriteTimeUtc } catch { $selfStamp }
            if ($nowStamp -ne $selfStamp) {
                Start-Process powershell -WindowStyle Hidden -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File',"`"$PSCommandPath`"",'watch')
                return
            }
            # Latido. El panel lo usa para saber si hay watcher (ver watcher_alive en index.php):
            # el PID no vale porque el de SYSTEM no es consultable desde el panel y porque
            # watch.pid solo guarda el del ultimo watcher arrancado. Lo escribe quien de verdad
            # esta ejecutando el bucle, que es justo la pregunta que se quiere responder.
            Set-Content -Path $wBeat -Value ([DateTimeOffset]::UtcNow.ToUnixTimeSeconds()) -Encoding ascii
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
            # Reconciliar MariaDB con su flag (con backoff si no arranca)
            $mdOn = Test-Path $MariaDbFlag
            if ($mdOn -and (Test-Path $Mysqld) -and -not (MariaDb-Up)) {
                if ([datetime]::Now -ge $nextMariaTry) { Start-MariaDb; $nextMariaTry = [datetime]::Now.AddSeconds(30) }
            } elseif ($mdOn -and (MariaDb-Up)) { $nextMariaTry = [datetime]::MinValue }
            if (-not $mdOn -and (MariaDb-Up)) { Stop-MariaDb }
            # Reconciliar PostgreSQL con su flag (con backoff si no arranca)
            $pgOn = Test-Path $PostgresFlag
            if ($pgOn -and (Test-Path $PgCtl) -and -not (Postgres-Up)) {
                if ([datetime]::Now -ge $nextPgTry) { Start-Postgres; $nextPgTry = [datetime]::Now.AddSeconds(30) }
            } elseif ($pgOn -and (Postgres-Up)) { $nextPgTry = [datetime]::MinValue }
            if (-not $pgOn -and (Postgres-Up)) { Stop-Postgres }
            # Reconciliar MongoDB (+ mongo-express) con su flag (con backoff, como los demas
            # motores: sin el, un mongod que no puede arrancar se relanzaba cada segundo y
            # ademas reescribia mongod.cfg en bucle)
            $mongoOn = Test-Path $MongoDbFlag
            if ($mongoOn -and (Test-Path $Mongod) -and -not (MongoDb-Up)) {
                if ([datetime]::Now -ge $nextMongoTry) { Start-MongoDb; $nextMongoTry = [datetime]::Now.AddSeconds(30) }
            } elseif ($mongoOn -and (MongoDb-Up)) { $nextMongoTry = [datetime]::MinValue }
            if (-not $mongoOn -and (MongoDb-Up)) { Stop-MongoDb }
            if ($mongoOn -and (MongoDb-Up) -and -not (MongoExpress-Up)) { Start-MongoExpress }
            if ((-not $mongoOn -or -not (MongoDb-Up)) -and (MongoExpress-Up)) { Stop-MongoExpress }
            # Reconciliar Redis con su flag (con backoff si no arranca, como MariaDB/PostgreSQL:
            # sin el, un build que revienta al arrancar se reintentaria cada segundo para siempre)
            $rdOn = Test-Path $RedisFlag
            if ($rdOn -and (Test-Path $RedisExe) -and -not (Redis-Up)) {
                if ([datetime]::Now -ge $nextRedisTry) { Start-Redis; $nextRedisTry = [datetime]::Now.AddSeconds(30) }
            } elseif ($rdOn -and (Redis-Up)) { $nextRedisTry = [datetime]::MinValue }
            if (-not $rdOn -and (Redis-Up)) { Stop-Redis }
            # ---- Supervisor de procesos por proyecto ----
            # Reconcilia lo definido en config\procs.json con la realidad. Backoff exponencial
            # anti crash-loop: si un proceso muere a los pocos segundos de arrancar (comando
            # roto, puerto ocupado), reintentar cada 1s solo llenaria el log y la CPU -- se
            # espera 5s, 10s, 20s... hasta 60s. Si aguanta >15s, el contador se resetea.
            $defs = Get-Procs
            $st = @{}
            $defIds = @()
            foreach ($d in $defs) {
                $id = "$($d.id)"; if (-not $id) { continue }
                $defIds += $id
                if (-not $procMem.ContainsKey($id)) { $procMem[$id] = @{ last=[datetime]::MinValue; fails=0; next=[datetime]::MinValue } }
                $m = $procMem[$id]
                $rst = Join-Path $ProcsRunDir "$id.restart"
                if (Test-Path $rst) { Remove-Item $rst -Force -ErrorAction SilentlyContinue; Stop-Proc $id; $m.fails = 0; $m.next = [datetime]::MinValue }
                $up = Proc-Up $id
                if ($d.enabled -and -not $up) {
                    if ([datetime]::Now -ge $m.next) {
                        # ¿Muerte rapida? (habia arrancado hace <15s). MinValue = primer arranque.
                        if ($m.last -ne [datetime]::MinValue -and ([datetime]::Now - $m.last).TotalSeconds -lt 15) { $m.fails++ } else { $m.fails = 0 }
                        $delay = [math]::Min(60, 5 * [math]::Pow(2, $m.fails))
                        $m.next = [datetime]::Now.AddSeconds($delay)
                        $m.last = [datetime]::Now
                        if (-not (Start-Proc $d)) {
                            # Proyecto desregistrado o carpeta desaparecida: no insistir cada 1s.
                            $m.next = [datetime]::Now.AddSeconds(60)
                        }
                        $up = Proc-Up $id
                    }
                } elseif (-not $d.enabled -and $up) {
                    Stop-Proc $id; $up = $false; $m.fails = 0; $m.next = [datetime]::MinValue
                } elseif ($d.enabled -and $up -and $m.fails -gt 0 -and ([datetime]::Now - $m.last).TotalSeconds -ge 15) {
                    $m.fails = 0   # lleva >15s vivo: el crash-loop se acabo
                }
                $pidNow = 0
                if ($up) { $parts = ((Get-Content (Proc-PidFile $id) -Raw -ErrorAction SilentlyContinue).Trim()) -split ';'; if ($parts.Count -ge 1) { $pidNow = [int]$parts[0] } }
                # Epocas via DateTimeOffset y no Get-Date -UFormat %s: con locale espanol este
                # ultimo devuelve decimales con COMA y el parse revienta o da valores absurdos.
                # m.last puede ser MinValue si el watcher acaba de autorecargarse con el proceso
                # ya corriendo: en ese caso "since" se queda a 0 (el panel omite el "desde").
                $st[$id] = @{
                    running = [bool]$up
                    pid     = $pidNow
                    fails   = [int]$m.fails
                    next    = $(if ($d.enabled -and -not $up -and $m.next -gt [datetime]::MinValue) { [DateTimeOffset]::new($m.next.ToUniversalTime(), [timespan]::Zero).ToUnixTimeSeconds() } else { 0 })
                    since   = $(if ($up -and $m.last -gt [datetime]::MinValue) { [DateTimeOffset]::new($m.last.ToUniversalTime(), [timespan]::Zero).ToUnixTimeSeconds() } else { 0 })
                }
            }
            # Huerfanos: pid de ids que ya no estan en procs.json (definicion borrada) -> matar.
            if (Test-Path $ProcsRunDir) {
                foreach ($pf in (Get-ChildItem $ProcsRunDir -Filter *.pid -ErrorAction SilentlyContinue)) {
                    if ($defIds -notcontains $pf.BaseName) { Stop-Proc $pf.BaseName; $procMem.Remove($pf.BaseName) }
                }
            }
            # state.json solo si cambio (el panel lo lee para pintar badges sin tasklist).
            $stJson = ($st | ConvertTo-Json -Compress -Depth 4)
            if ($stJson -ne $procState) {
                New-Item -ItemType Directory -Force -Path $ProcsRunDir | Out-Null
                Write-Utf8NoBom (Join-Path $ProcsRunDir "state.json") $stJson
                $procState = $stJson
            }
            # Dialogo nativo "Elegir carpeta": el panel corre bajo el servicio de Apache (sesion 0,
            # sin escritorio), asi que no puede mostrar UI el mismo -- lo pide aqui, en el watcher,
            # que corre en la sesion interactiva del usuario. El panel solo espera el resultado
            # haciendo polling AJAX sobre el .res que se escribe abajo.
            $pfDir = Join-Path $TmpDir "pickfolder"
            if (Test-Path $pfDir) {
                foreach ($pfReq in (Get-ChildItem $pfDir -Filter *.req -ErrorAction SilentlyContinue)) {
                    $pfId = [System.IO.Path]::GetFileNameWithoutExtension($pfReq.Name)
                    Remove-Item $pfReq.FullName -Force -ErrorAction SilentlyContinue
                    $pfOut = try {
                        Add-Type -AssemblyName System.Windows.Forms | Out-Null
                        # Formulario invisible "topmost" solo para forzar que el dialogo salga al
                        # frente -- sin owner, ShowDialog() desde un host sin ventana visible a
                        # veces se abre detras de otras ventanas y parece que "no ha pasado nada".
                        $pfOwner = New-Object System.Windows.Forms.Form
                        $pfOwner.TopMost = $true; $pfOwner.ShowInTaskbar = $false
                        $pfOwner.StartPosition = 'CenterScreen'; $pfOwner.Width = 0; $pfOwner.Height = 0
                        $pfOwner.Show(); $pfOwner.Activate()
                        $pfDlg = New-Object System.Windows.Forms.FolderBrowserDialog
                        $pfDlg.Description = "Elige la carpeta con los archivos .sql"
                        $pfDlg.ShowNewFolderButton = $false
                        $pfResult = $pfDlg.ShowDialog($pfOwner)
                        $pfOwner.Close()
                        if ($pfResult -eq [System.Windows.Forms.DialogResult]::OK) {
                            @{ status = 'done'; path = $pfDlg.SelectedPath } | ConvertTo-Json -Compress
                        } else {
                            @{ status = 'cancelled' } | ConvertTo-Json -Compress
                        }
                    } catch {
                        @{ status = 'error'; msg = $_.Exception.Message } | ConvertTo-Json -Compress
                    }
                    # Write-Utf8NoBom, no Set-Content -Encoding utf8: este metia BOM y el
                    # json_decode() del lado PHP fallaba con el JSON perfectamente valido que
                    # tenia detras ("respuesta ilegible") -- mismo gotcha ya conocido en .env.
                    Write-Utf8NoBom (Join-Path $pfDir "$pfId.res") $pfOut
                }
            }
            # --- Actualizaciones ---
            # Comprobacion periodica + peticiones puntuales del panel. Si el propio lua.ps1 se
            # ha actualizado, este proceso relanza uno nuevo y termina: seguir vivo significaria
            # seguir ejecutando el codigo antiguo.
            $updCfg = Get-UpdateConfig
            $pideChk = Test-Path $fUpdChk
            $pideNow = Test-Path $fUpdNow
            if ($pideChk) { Remove-Item $fUpdChk -Force -ErrorAction SilentlyContinue }
            if ($pideNow) { Remove-Item $fUpdNow -Force -ErrorAction SilentlyContinue }
            if ($pideNow) {
                $r = Update-Apply
                if ($r -eq 'relanzar') {
                    Start-Process powershell -WindowStyle Hidden -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File',"`"$PSCommandPath`"",'watch')
                    return
                }
            }
            elseif ($pideChk -or [datetime]::Now -ge $nextUpdTry) {
                $nextUpdTry = [datetime]::Now.AddHours($updCfg.cada_horas)
                $est = Update-Check
                if ($updCfg.auto -and -not $est.error -and $est.detras -gt 0 -and -not $est.sucio -and $est.delante -eq 0) {
                    $r = Update-Apply
                    if ($r -eq 'relanzar') {
                        Start-Process powershell -WindowStyle Hidden -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File',"`"$PSCommandPath`"",'watch')
                        return
                    }
                }
            }
            Process-Jobs
        } catch {
            # Antes esto se tragaba en silencio: un fallo aqui (p.ej. Restart-Service
            # denegado porque Apache es servicio y este watcher no esta elevado) no
            # dejaba ningun rastro. Se registra para poder diagnosticarlo.
            try {
                New-Item -ItemType Directory -Force -Path $ApacheLog | Out-Null
                "$(Get-Date -Format o)  watch-loop error: $($_.Exception.Message)" | Add-Content (Join-Path $ApacheLog "watcher-error.log")
            } catch {}
        }
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
    # Respaldar la config de Apache que vamos a regenerar. Si la nueva no valida, se restaura:
    # asi el disco nunca queda con config rota que tumbe Apache en el proximo reinicio/reboot
    # (Apache sigue sirviendo con la vieja en memoria y el usuario no se entera hasta que,
    # horas despues, un reinicio relee el disco roto y NO arranca — sin causa aparente).
    $bak = Join-Path $TmpDir "apply-bak"
    if (Test-Path $bak) { Remove-Item $bak -Recurse -Force -ErrorAction SilentlyContinue }
    New-Item -ItemType Directory -Force -Path $bak | Out-Null
    if (Test-Path $VhostDir) { Copy-Item $VhostDir (Join-Path $bak "vhosts") -Recurse -Force -ErrorAction SilentlyContinue }
    if (Test-Path $SslConf)  { Copy-Item $SslConf  (Join-Path $bak "ssl.conf") -Force -ErrorAction SilentlyContinue }
    Set-Ssl
    Regenerate-Vhosts
    if (-not (Test-HttpdConfig)) {
        "$(Get-Date -Format o)  apply: CONFIG INVALIDA, restaurando backup" | Add-Content $log
        if (Test-Path (Join-Path $bak "vhosts")) {
            Get-ChildItem $VhostDir -Filter *.conf -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue
            Copy-Item (Join-Path $bak "vhosts\*") $VhostDir -Recurse -Force -ErrorAction SilentlyContinue
        }
        if (Test-Path (Join-Path $bak "ssl.conf")) { Copy-Item (Join-Path $bak "ssl.conf") $SslConf -Force -ErrorAction SilentlyContinue }
        Remove-Item $bak -Recurse -Force -ErrorAction SilentlyContinue
        Err "Config invalida: se revirtieron los cambios de Apache (el disco queda en el ultimo estado valido)."
        return
    }
    Remove-Item $bak -Recurse -Force -ErrorAction SilentlyContinue
    Start-Sleep -Milliseconds 800   # deja que el navegador reciba la respuesta antes de reiniciar
    Restart-Apache
    "$(Get-Date -Format o)  apply: done" | Add-Content $log
    Ok "Cambios aplicados."
}

# ============================================================
#  ACTUALIZACIONES (la plataforma es un repo de git)
# ============================================================
# Config: config\update.json -> { "auto": bool, "cada_horas": int }
function Get-UpdateConfig {
    $def = @{ auto = $false; cada_horas = 6 }
    if (-not (Test-Path $UpdateCfgFile)) { return $def }
    try {
        $j = Get-Content $UpdateCfgFile -Raw | ConvertFrom-Json
        return @{
            auto       = [bool]$j.auto
            cada_horas = if ($j.cada_horas -and [int]$j.cada_horas -ge 1) { [int]$j.cada_horas } else { 6 }
        }
    } catch { return $def }
}
function Save-UpdateConfig($cfg) {
    Write-Utf8NoBom $UpdateCfgFile (($cfg | ConvertTo-Json -Compress))
}
# Version legible. Sin etiquetas en el repo se usa el nº de commit + el hash corto ("r33 3de3c0a");
# si algun dia se etiqueta una version, git describe manda y se muestra la etiqueta.
function Get-LuaVersion {
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try {
        $tag = (& git -c $GitSafeDir -C "$Root" describe --tags --abbrev=0 2>$null | Select-Object -First 1)
        $sha = (& git -c $GitSafeDir -C "$Root" rev-parse --short HEAD 2>$null | Select-Object -First 1)
        $n   = (& git -c $GitSafeDir -C "$Root" rev-list --count HEAD 2>$null | Select-Object -First 1)
        if ($tag) { return "$tag" }
        if ($sha) { return "r$n $sha" }
        return "desconocida"
    } catch { return "desconocida" } finally { $ErrorActionPreference = $prev }
}
function Write-UpdateStatus($o) {
    New-Item -ItemType Directory -Force -Path $TmpDir | Out-Null
    Write-Utf8NoBom $UpdateStatusFile (($o | ConvertTo-Json -Compress))
}
# Consulta el remoto y deja el resultado en tmp\update-status.json. No modifica el repo:
# 'git fetch' solo trae referencias, nunca toca los archivos de trabajo.
function Update-Check {
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $o = @{ comprobado = (Get-Date -Format o); version = (Get-LuaVersion); error = $null
            detras = 0; delante = 0; sucio = $false; remoto = $null; mensaje = $null }
    try {
        $fetch = (& git -c $GitSafeDir -C "$Root" fetch --quiet origin 2>&1)
        if ($LASTEXITCODE -ne 0) {
            # Tipico: sin clave SSH cargada, sin red, o el remoto pide autenticacion.
            $o.error = "No se pudo consultar el remoto: $fetch"
            Write-UpdateStatus $o; return $o
        }
        $up = (& git -c $GitSafeDir -C "$Root" rev-parse --abbrev-ref '@{u}' 2>$null | Select-Object -First 1)
        if (-not $up) { $o.error = 'La rama actual no sigue a ninguna rama remota.'; Write-UpdateStatus $o; return $o }
        $o.remoto  = "$up"
        $o.detras  = [int]((& git -c $GitSafeDir -C "$Root" rev-list --count "HEAD..$up" 2>$null | Select-Object -First 1))
        $o.delante = [int]((& git -c $GitSafeDir -C "$Root" rev-list --count "$up..HEAD" 2>$null | Select-Object -First 1))
        $o.sucio   = [bool]((& git -c $GitSafeDir -C "$Root" status --porcelain 2>$null) -ne $null -and (& git -c $GitSafeDir -C "$Root" status --porcelain 2>$null).Count -gt 0)
        if ($o.detras -gt 0) {
            $o.mensaje = ((& git -c $GitSafeDir -C "$Root" log --format='%h %s' -n 5 "HEAD..$up" 2>$null) -join "`n")
        }
    } catch { $o.error = $_.Exception.Message }
    finally { $ErrorActionPreference = $prev }
    Write-UpdateStatus $o
    return $o
}
# Aplica la actualizacion. Deliberadamente conservador: --ff-only y NUNCA con cambios locales
# sin confirmar, para no provocar conflictos ni perder trabajo del usuario. La config de cada
# maquina (sites.json, *.pass, sqlsrv-servers.json, www\...) no esta versionada, asi que un
# pull no la toca.
function Update-Apply {
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try {
        $est = Update-Check
        if ($est.error)      { Err "Actualizacion cancelada: $($est.error)"; return $false }
        if ($est.detras -eq 0) { Ok "Ya estas en la ultima version ($($est.version))."; return $true }
        if ($est.sucio)      { Err "Hay cambios locales sin confirmar: no se actualiza para no pisarlos. Confirmalos o descartalos primero."; return $false }
        if ($est.delante -gt 0) { Err "Tu rama tiene $($est.delante) commit(s) propios sin subir: actualiza a mano para decidir como integrarlos."; return $false }

        $antesLua = (Get-FileHash (Join-Path $Root "lua.ps1") -Algorithm MD5).Hash
        Info "Actualizando desde $($est.remoto)..."
        $out = (& git -c $GitSafeDir -C "$Root" merge --ff-only "$($est.remoto)" 2>&1)
        if ($LASTEXITCODE -ne 0) { Err "No se pudo actualizar: $out"; return $false }
        Ok "Actualizado a $(Get-LuaVersion)."

        Cmd-Apply   # regenera php.ini/vhosts y reinicia Apache si la config es valida
        Update-Check | Out-Null

        # Trampa nº1 de CLAUDE.md: el watcher tiene el codigo viejo cargado en memoria. Si el
        # propio lua.ps1 ha cambiado, hay que relanzarlo o las novedades no existirian hasta
        # el siguiente arranque manual.
        $despuesLua = (Get-FileHash (Join-Path $Root "lua.ps1") -Algorithm MD5).Hash
        if ($antesLua -ne $despuesLua) { return 'relanzar' }
        return $true
    } catch { Err "Error al actualizar: $($_.Exception.Message)"; return $false }
    finally { $ErrorActionPreference = $prev }
}

# ============================================================
#  Sistema de TAREAS (crear proyectos: plantillas, WordPress, git)
#  El panel deja un .job en tmp\jobs\; el watcher lo ejecuta aqui.
# ============================================================
function Set-JobStatus($id, $name, $type, $state, $msg, $pct=$null) {
    $jd = Join-Path $TmpDir "jobs"; New-Item -ItemType Directory -Force -Path $jd | Out-Null
    $o = @{ id=$id; name=$name; type=$type; state=$state; msg=$msg; time=(Get-Date -Format "HH:mm:ss") }
    if ($null -ne $pct) { $o.pct = $pct }
    [System.IO.File]::WriteAllText((Join-Path $jd "$id.status"), ($o | ConvertTo-Json -Compress), (New-Object System.Text.UTF8Encoding($false)))
}
function Add-SiteToConfig($name, $php) {
    $cfg = Get-Config
    if (-not ($cfg.sites.PSObject.Properties.Name -contains $name)) { $cfg.sites | Add-Member -NotePropertyName $name -NotePropertyValue ([pscustomobject]@{ php=$php }) -Force }
    else { $cfg.sites.$name.php = $php }
    Save-Config $cfg
}
# Deja constancia en sites.json de que este proyecto tiene una BD (y opcionalmente un usuario
# de MySQL propio) creados POR LA PLATAFORMA al darlo de alta -- es lo unico que permite que
# "Eliminar proyecto" (accion 'delete' en index.php) pueda borrar tambien la BD/usuario sin
# arriesgarse a acertar por casualidad el nombre de una BD de otro proyecto: solo se borra lo
# que quedo anotado aqui en el momento de crearla, nunca por coincidencia de nombre.
function Set-SiteDb($name, $dbname, $dbuser) {
    $cfg = Get-Config
    if (-not ($cfg.sites.PSObject.Properties.Name -contains $name)) { return }
    if (-not ($cfg.sites.$name -is [System.Management.Automation.PSCustomObject])) { $cfg.sites.$name = [pscustomobject]@{ php = $cfg.sites.$name } }
    $cfg.sites.$name | Add-Member -NotePropertyName 'db' -NotePropertyValue $dbname -Force
    if ($dbuser) { $cfg.sites.$name | Add-Member -NotePropertyName 'dbuser' -NotePropertyValue $dbuser -Force }
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
    # UTF-8 SIN BOM: Set-Content -Encoding utf8 metia BOM y corrompia la 1a clave del .env
    # (env('APP_NAME')=null; phpdotenv antiguo de Laravel 5.x reventaba al parsear).
    Write-Utf8NoBom $envFile $out
}
# Crea (si no existe) una base de datos MySQL a juego con el proyecto. Silencioso si MariaDB no esta arriba.
function New-ProjectDb($dbname, $projectDir, $projectType) {
    if (-not (MariaDb-Up)) { return $null }
    $mariadbExe = Join-Path $MariaDb "bin\mariadb.exe"
    if (-not (Test-Path $mariadbExe)) { return $null }
    # Leer la contrasena de root si el usuario la fijo desde el panel (config\mysql_root.pass).
    # Antes se conectaba SIEMPRE sin contrasena: con root protegido, mariadb devolvia
    # "Access denied", pero como no se comprobaba el exit code, la funcion devolvia exito y
    # el panel mostraba "[BD: x]" aunque la BD no existiera (y ademas .env quedaba sin clave).
    $rootPassFile = Join-Path $Root "config\mysql_root.pass"
    $rootPass = if (Test-Path $rootPassFile) { (Get-Content $rootPassFile -Raw).Trim() } else { "" }
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try {
        $sql = 'CREATE DATABASE IF NOT EXISTS `' + $dbname + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        $args = @('--host=127.0.0.1','--port=3306','--user=root')
        if ($rootPass -ne '') { $args += "--password=$rootPass" }
        $args += @('-e', $sql)
        $null = & $mariadbExe @args 2>&1
        if ($LASTEXITCODE -ne 0) { return $null }   # BD no creada: el llamador avisara en vez de exito falso
        if ($projectType -eq 'laravel') {
            $envFile = Join-Path $projectDir ".env"
            Set-EnvVar $envFile "DB_CONNECTION" "mysql"
            Set-EnvVar $envFile "DB_HOST" "127.0.0.1"
            Set-EnvVar $envFile "DB_PORT" "3306"
            Set-EnvVar $envFile "DB_DATABASE" $dbname
            Set-EnvVar $envFile "DB_USERNAME" "root"
            Set-EnvVar $envFile "DB_PASSWORD" $rootPass
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
# Descarga wp-cli.phar la primera vez que hace falta (no forma parte del catalogo de
# instalacion inicial -- mismo patron de "descarga en cuanto se necesita" que Node.js para
# mongo-express, ver el case "mongodb" de Run-Job). Devuelve $true si al acabar existe.
function Ensure-WpCli($log) {
    if (Test-Path $WpCli) { return $true }
    New-Item -ItemType Directory -Force -Path (Split-Path $WpCli) | Out-Null
    "Descargando WP-CLI..." | Add-Content $log
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    try { Invoke-WebRequest "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" -OutFile $WpCli -UseBasicParsing -TimeoutSec 300 } catch {}
    if ((-not (Test-Path $WpCli)) -or ((Get-Item $WpCli).Length -lt 100000)) { Remove-Item $WpCli -Force -ErrorAction SilentlyContinue; return $false }
    "WP-CLI descargado." | Add-Content $log
    return $true
}
# Instala php_<ext>.dll en bin\php\<ver>\ext\ desde una URL que puede ser el .dll suelto O un
# .zip. Lo segundo hace falta porque PECL (windows.php.net) distribuye SIEMPRE en zip -- con el
# .dll acompanado de docs, LICENSE y su .pdb -- asi que sin esto no se puede instalar ninguna
# extension oficial de PECL, solo .dll sueltos alojados a mano. Devuelve $true si acaba con el
# .dll en su sitio.
function Install-PhpExt($ver, $extName, $url, $log) {
    $extDir = Join-Path $PhpBase "$ver\ext"
    if (-not (Test-Path $extDir)) { "  ! PHP $ver no tiene carpeta ext\ (no instalado?)" | Add-Content $log; return $false }
    $dest = Join-Path $extDir "php_$extName.dll"
    $esZip = ($url -match '\.zip($|\?)')
    if (-not $esZip) {
        "  Descargando php_$extName.dll para PHP $ver..." | Add-Content $log
        & curl.exe -s -L -o "$dest" "$url" 2>&1 | Add-Content $log
        if ((-not (Test-Path $dest)) -or ((Get-Item $dest).Length -lt 1024)) {
            Remove-Item $dest -Force -ErrorAction SilentlyContinue
            "  ! No se descargo el .dll (revisa la URL)" | Add-Content $log; return $false
        }
    } else {
        $work = Join-Path $TmpDir ("ext-" + [System.IO.Path]::GetRandomFileName())
        $zip  = "$work.zip"
        "  Descargando php_$extName.dll (zip de PECL) para PHP $ver..." | Add-Content $log
        & curl.exe -s -L -o "$zip" "$url" 2>&1 | Add-Content $log
        if ((-not (Test-Path $zip)) -or ((Get-Item $zip).Length -lt 1024)) {
            Remove-Item $zip -Force -ErrorAction SilentlyContinue
            "  ! No se descargo el zip (revisa la URL)" | Add-Content $log; return $false
        }
        try { Expand-Archive $zip $work -Force } catch { "  ! El zip no se pudo descomprimir: $($_.Exception.Message)" | Add-Content $log }
        # -Recurse: algunos zips de PECL meten el .dll en la raiz y otros dentro de una carpeta.
        $dll = Get-ChildItem $work -Filter "php_$extName.dll" -Recurse -File -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($dll) { Copy-Item $dll.FullName $dest -Force }
        Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue
        Remove-Item $zip  -Force -ErrorAction SilentlyContinue
        if (-not $dll) { "  ! El zip no contenia php_$extName.dll" | Add-Content $log; return $false }
    }
    "  php_$extName.dll instalada en PHP $ver ($([math]::Round((Get-Item $dest).Length/1KB)) KB)." | Add-Content $log
    return $true
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
            "blank"     { New-Item -ItemType Directory -Force -Path $dir | Out-Null; Write-Utf8NoBom (Join-Path $dir "index.php") "<?php`r`nphpinfo();`r`n" }
            "laravel"   { & $phpExe $composer create-project laravel/laravel "$dir" --no-interaction 2>&1 | Add-Content $log; if ($LASTEXITCODE -ne 0) { $ok=$false; $err="Composer fallo (ver log)" } }
            "symfony"   { & $phpExe $composer create-project symfony/skeleton "$dir" --no-interaction 2>&1 | Add-Content $log; if ($LASTEXITCODE -ne 0) { $ok=$false; $err="Composer fallo (ver log)" } }
            "slim"      { & $phpExe $composer create-project slim/slim-skeleton "$dir" --no-interaction 2>&1 | Add-Content $log; if ($LASTEXITCODE -ne 0) { $ok=$false; $err="Composer fallo (ver log)" } }
            "wordpress" {
                Download-WordPress $dir $log
                # $job.wpDbName solo viene si el panel ya creo la BD/usuario de MySQL (siempre lo
                # hace hoy para "wordpress", ver action=create en index.php) -- comprobado por si
                # algun dia vuelve a existir un alta de WordPress "en blanco" sin wizard.
                if ($job.wpDbName) {
                    $wpDbName = "$($job.wpDbName)"; $wpDbUser = "$($job.wpDbUser)"; $wpDbPass = "$($job.wpDbPass)"
                    $wpTitle = "$($job.wpTitle)"; $wpAdminUser = "$($job.wpAdminUser)"; $wpAdminPass = "$($job.wpAdminPass)"; $wpAdminEmail = "$($job.wpAdminEmail)"
                    if (-not (Ensure-WpCli $log)) { $ok=$false; $err="No se pudo descargar WP-CLI (ver log)" }
                    else {
                        "Escribiendo wp-config.php..." | Add-Content $log
                        $cfgArgs = @('config','create',"--path=$dir","--dbname=$wpDbName","--dbuser=$wpDbUser","--dbpass=$wpDbPass",'--dbhost=127.0.0.1','--skip-check','--force')
                        & $phpExe $WpCli @cfgArgs 2>&1 | Add-Content $log
                        if ($LASTEXITCODE -ne 0) { $ok=$false; $err="wp config create fallo (ver log)" }
                        else {
                            $wpUrl = "http://$name.$(Get-Tld)"
                            "Instalando WordPress ($wpUrl)..." | Add-Content $log
                            $instArgs = @('core','install',"--path=$dir","--url=$wpUrl","--title=$wpTitle","--admin_user=$wpAdminUser","--admin_password=$wpAdminPass","--admin_email=$wpAdminEmail",'--skip-email')
                            & $phpExe $WpCli @instArgs 2>&1 | Add-Content $log
                            if ($LASTEXITCODE -ne 0) { $ok=$false; $err="wp core install fallo (ver log)" }
                        }
                    }
                }
            }
            "git"       { & git clone "$url" "$dir" 2>&1 | Add-Content $log; if ($LASTEXITCODE -ne 0) { $ok=$false; $err="git clone fallo (ver log)" } elseif (Test-Path (Join-Path $dir "composer.json")) { "composer install..." | Add-Content $log; & $phpExe $composer install --no-interaction --working-dir="$dir" 2>&1 | Add-Content $log } }
            "xdebug"    { $dest = Join-Path $PhpBase "$php\ext\php_xdebug.dll"; "Descargando Xdebug: $url" | Add-Content $log; & curl.exe -s -L -o "$dest" "$url" 2>&1 | Add-Content $log; if ((-not (Test-Path $dest)) -or ((Get-Item $dest).Length -lt 20000)) { $ok=$false; $err="No se descargo la DLL de Xdebug"; Remove-Item $dest -Force -ErrorAction SilentlyContinue } else { "Xdebug descargado ($([math]::Round((Get-Item $dest).Length/1KB)) KB)." | Add-Content $log } }
            "phpext"    { "Instalando extension '$extName' desde: $url" | Add-Content $log; if (-not (Install-PhpExt $php $extName $url $log)) { $ok=$false; $err="No se pudo instalar la extension (revisa la URL / el log)" } }
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
                # mongo-express (GUI web): se clona su repo y se compila en vez de "npm install
                # mongo-express" -- el tag "latest" de npm apunta hoy a un release candidate
                # (1.1.0-rc-4) cuyo tarball publicado NO trae build-assets.json (falta de su
                # whitelist "files"), asi que revienta con ENOENT nada mas arrancar. Un tag fijo
                # de git en vez de "latest" hace ademas el instalador reproducible.
                # "npm install" (sin --production, en el propio directorio del repo) instala
                # tambien las devDependencies (webpack...) y de paso ejecuta el script
                # "prepublish" -- que pese al nombre, npm lo corre igualmente en instalaciones
                # locales -- generando build-assets.json y el bundle de public\. Una vez
                # generado, esas devDependencies ya no hacen falta para ejecutar la app: se
                # podan despues con "npm prune" para no dejar ~250 MB de mas en el equipo.
                if ($ok -and -not (Test-Path $MongoExpressApp)) {
                    Remove-Item $MongoExpress -Recurse -Force -ErrorAction SilentlyContinue
                    "Clonando mongo-express ($MongoExpressTag)..." | Add-Content $log
                    & git clone --quiet --depth 1 --branch $MongoExpressTag "https://github.com/mongo-express/mongo-express.git" "$MongoExpress" 2>&1 | Add-Content $log
                    if (-not (Test-Path (Join-Path $MongoExpress "package.json"))) { $ok=$false; $err="No se pudo clonar mongo-express (ver log)" }
                    else {
                        "Compilando mongo-express (npm install, puede tardar)..." | Add-Content $log
                        Push-Location $MongoExpress
                        try { & $NpmCmd install --no-audit --no-fund 2>&1 | Add-Content $log }
                        finally { Pop-Location }
                        if (-not (Test-Path (Join-Path $MongoExpress "build-assets.json"))) { $ok=$false; $err="Fallo la compilacion de mongo-express (ver log)" }
                        else {
                            "mongo-express compilado; podando dependencias de desarrollo..." | Add-Content $log
                            Push-Location $MongoExpress
                            try { & $NpmCmd prune --omit=dev 2>&1 | Add-Content $log }
                            finally { Pop-Location }
                        }
                    }
                    if ($ok -and -not (Test-Path $MongoExpressApp)) { $ok=$false; $err="No se instalo mongo-express (ver log)" }
                    elseif ($ok) { "mongo-express instalado." | Add-Content $log }
                }
            }
            "redis"     {
                # Dos partes independientes: el servidor (un port de la comunidad, elegido por el
                # usuario en el panel y recordado en config\redis\build.txt) y la extension
                # php_redis.dll para CADA version de PHP instalada.
                $rBuild = "$($job.build)"; if ($rBuild -ne 'native5') { $rBuild = 'redis8' }
                if (-not (Test-Path $RedisExe)) {
                    # redis8  = redis-windows/redis-windows 8.8.1 (variante msys2: mas ligera que la
                    #           de cygwin y sin el wrapper de servicio, que aqui no se usa).
                    # native5 = tporadowski/redis 5.0.14.1, port Win32 nativo.
                    $rUrl = if ($rBuild -eq 'native5') {
                        "https://github.com/tporadowski/redis/releases/download/v5.0.14.1/Redis-x64-5.0.14.1.zip"
                    } else {
                        "https://github.com/redis-windows/redis-windows/releases/download/8.8.1/Redis-8.8.1-Windows-x64-msys2.zip"
                    }
                    New-Item -ItemType Directory -Force -Path $RedisDir | Out-Null
                    $zip = Join-Path $RedisDir "redis.zip"
                    "Descargando Redis ($rBuild)..." | Add-Content $log
                    & curl.exe -s -L -o "$zip" "$rUrl" 2>&1 | Add-Content $log
                    if (Test-Path $zip) {
                        $work = Join-Path $TmpDir ("rd-" + [System.IO.Path]::GetRandomFileName())
                        Expand-Archive $zip $work -Force
                        # Los dos zips no tienen la misma forma (uno deja los .exe en la raiz, el
                        # otro dentro de una carpeta con el nombre de la release), asi que en vez de
                        # asumir una estructura se busca redis-server.exe y se toma SU carpeta como
                        # raiz. Sirve para cualquiera de los dos y para futuros cambios de empaquetado.
                        $srv = Get-ChildItem $work -Filter "redis-server.exe" -Recurse -File -ErrorAction SilentlyContinue | Select-Object -First 1
                        if ($srv) {
                            Get-ChildItem $RedisDir -Force -ErrorAction SilentlyContinue | Where-Object { $_.Name -ne 'redis.zip' } | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
                            Get-ChildItem $srv.Directory.FullName -Force | Move-Item -Destination $RedisDir -Force
                        }
                        Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue
                        Remove-Item $zip  -Force -ErrorAction SilentlyContinue
                    }
                    if (-not (Test-Path $RedisExe)) { $ok=$false; $err="No se descargo Redis (ver log)" }
                    else {
                        New-Item -ItemType Directory -Force -Path (Split-Path $RedisBuildFile) | Out-Null
                        Set-Content -Path $RedisBuildFile -Value $rBuild -Encoding ascii
                        "Redis ($rBuild) descargado." | Add-Content $log
                    }
                }
                # Extension de PHP, una por version instalada. No se aborta el job si alguna falla:
                # tener Redis en 6 de 7 versiones es mejor que no tenerlo en ninguna, y Set-PhpInis
                # solo activa la extension en las versiones donde el .dll exista de verdad.
                if ($ok) {
                    $rHechas = 0; $rFallos = @()
                    foreach ($pv in (Get-PhpVersions)) {
                        if (Test-Path (Join-Path $PhpBase "$pv\ext\php_redis.dll")) { $rHechas++; continue }
                        $eUrl = Get-PhpRedisUrl $pv
                        if (-not $eUrl) { "  ! PECL no publica php_redis para PHP ${pv}: se omite." | Add-Content $log; $rFallos += $pv; continue }
                        if (Install-PhpExt $pv 'redis' $eUrl $log) { $rHechas++ } else { $rFallos += $pv }
                    }
                    "Extension redis instalada en $rHechas version(es) de PHP." | Add-Content $log
                    if ($rFallos.Count -gt 0) { "Sin extension redis: $($rFallos -join ', ')." | Add-Content $log }
                    if ($rHechas -eq 0) { $ok=$false; $err="No se pudo instalar php_redis.dll en ninguna version de PHP (ver log)" }
                }
            }
            "db_import_dir" {
                $dbname = "$($job.dbname)"; $srcDir = "$($job.dir)"
                $mariadbExe = Join-Path $MariaDb "bin\mariadb.exe"
                if (-not (Test-Path $mariadbExe)) { $ok=$false; $err="MariaDB no esta instalado" }
                elseif (-not (Test-Path -LiteralPath $srcDir -PathType Container)) { $ok=$false; $err="La carpeta '$srcDir' no existe en el servidor" }
                else {
                    $rootPassFile = Join-Path $Root "config\mysql_root.pass"
                    $rootPass = if (Test-Path $rootPassFile) { (Get-Content $rootPassFile -Raw).Trim() } else { "" }
                    $sqlFiles = Get-ChildItem -LiteralPath $srcDir -Filter *.sql -File | Sort-Object Name
                    if (-not $sqlFiles) { $ok=$false; $err="No hay archivos .sql en esa carpeta" }
                    else {
                        $total = $sqlFiles.Count; $i = 0; $failCount = 0
                        "Importando $total archivo(s) .sql en `"$dbname`" desde $srcDir..." | Add-Content $log
                        $mdArgs = @('--host=127.0.0.1','--port=3306','--user=root')
                        if ($rootPass -ne '') { $mdArgs += "--password=$rootPass" }
                        # "source <ruta>" (en vez de piping por stdin) deja que sea el propio cliente
                        # mariadb.exe quien lea el fichero -- evita cargar el .sql entero en memoria de
                        # PowerShell para luego canalizarlo (algunos dumps de esta carpeta pasan de 80 MB).
                        foreach ($f in $sqlFiles) {
                            $i++
                            Set-JobStatus $id $name $type "running" "Importando $i/$total`: $($f.Name)" ([math]::Floor(($i-1)*100/$total))
                            $fFwd = $f.FullName -replace '\\','/'
                            $out = & $mariadbExe @mdArgs -e "source $fFwd" $dbname 2>&1
                            if ($LASTEXITCODE -ne 0) { $failCount++; "[$i/$total] FALLO: $($f.Name) -> $out" | Add-Content $log }
                            else { "[$i/$total] OK: $($f.Name)" | Add-Content $log }
                        }
                        if ($failCount -gt 0) { $ok=$false; $err="$failCount de $total archivo(s) fallaron (ver log)" }
                    }
                }
            }
            "db_import_file" {
                # Import de un .sql subido desde el panel (boton "Importar" de una BD). Se hace
                # como job (igual que db_import_dir) para no bloquear el worker de PHP con
                # archivos grandes, y streameado a mano (en vez de "-e source <ruta>") para poder
                # reportar progreso real en bytes -- el pipe entre PowerShell y mariadb.exe actua
                # de cuello de botella natural, asi que "bytes enviados" se mantiene cerca de
                # "bytes realmente procesados" (salvo dumps dominados por un INSERT gigante de una
                # sola tabla, donde el cliente puede tragarse el stdin antes de terminar de
                # ejecutarlo -- el % puede llegar a 100 un poco antes de que el proceso acabe).
                $dbname = "$($job.dbname)"; $srcFile = "$($job.file)"
                try {
                    $mariadbExe = Join-Path $MariaDb "bin\mariadb.exe"
                    if (-not (Test-Path $mariadbExe)) { $ok=$false; $err="MariaDB no esta instalado" }
                    elseif (-not (Test-Path -LiteralPath $srcFile -PathType Leaf)) { $ok=$false; $err="El archivo subido ya no existe" }
                    else {
                        $rootPassFile = Join-Path $Root "config\mysql_root.pass"
                        $rootPass = if (Test-Path $rootPassFile) { (Get-Content $rootPassFile -Raw).Trim() } else { "" }
                        $totalBytes = (Get-Item -LiteralPath $srcFile).Length
                        "Importando $([math]::Round($totalBytes/1MB,1)) MB en `"$dbname`"..." | Add-Content $log
                        $psi = New-Object System.Diagnostics.ProcessStartInfo
                        $psi.FileName = $mariadbExe
                        $argParts = @('--host=127.0.0.1','--port=3306','--user=root')
                        if ($rootPass -ne '') { $argParts += (Quote-Win32Arg "--password=$rootPass") }
                        $argParts += (Quote-Win32Arg $dbname)
                        $psi.Arguments = $argParts -join ' '
                        $psi.RedirectStandardInput = $true
                        $psi.RedirectStandardError = $true
                        $psi.UseShellExecute = $false
                        $proc = [System.Diagnostics.Process]::Start($psi)
                        $errTask = $proc.StandardError.ReadToEndAsync()
                        $inStream = $proc.StandardInput.BaseStream
                        $fileStream = [System.IO.File]::OpenRead($srcFile)
                        $buffer = New-Object byte[] (1MB)
                        $sent = 0L; $lastPct = -1
                        try {
                            while (($read = $fileStream.Read($buffer,0,$buffer.Length)) -gt 0) {
                                $inStream.Write($buffer,0,$read)
                                $sent += $read
                                $pct = if ($totalBytes -gt 0) { [math]::Floor($sent*100/$totalBytes) } else { 99 }
                                if ($pct -ne $lastPct) { Set-JobStatus $id $name $type "running" "Importando... $pct%" $pct; $lastPct = $pct }
                            }
                        } finally { $fileStream.Close(); $proc.StandardInput.Close() }
                        $proc.WaitForExit()
                        $stderrText = $errTask.GetAwaiter().GetResult()
                        if ($proc.ExitCode -ne 0) { $ok=$false; $err="mariadb fallo: $stderrText" }
                        else { "Importacion completa ($([math]::Round($totalBytes/1MB,1)) MB)." | Add-Content $log }
                    }
                } finally {
                    Remove-Item -LiteralPath $srcFile -Force -ErrorAction SilentlyContinue
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
        if ($ok -and ($type -ne 'xdebug') -and ($type -ne 'phpext') -and ($type -ne 'mailpit') -and ($type -ne 'mariadb') -and ($type -ne 'postgres') -and ($type -ne 'mongodb') -and ($type -ne 'redis') -and ($type -ne 'ftp_deploy') -and ($type -ne 'db_import_dir') -and ($type -ne 'db_import_file') -and -not (Test-Path $dir)) { $ok=$false; $err="No se creo la carpeta del proyecto" }
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
        } elseif ($type -eq 'redis') {
            # Set-PhpInis + Restart-Apache: acaba de aparecer php_redis.dll en bin\php\<ver>\ext\,
            # pero hasta regenerar los php.ini y reiniciar Apache la extension no existe para las
            # apps (los php-cgi.exe vivos siguen con el ini viejo cargado).
            Set-PhpInis | Out-Null
            if (Test-HttpdConfig) { Restart-Apache }
            Start-Redis
            if (Redis-Up) { Set-JobStatus $id $name $type "done" "Redis activo en 127.0.0.1:$RedisPort (sin contrasena). Extension php_redis lista." }
            else { Set-JobStatus $id $name $type "error" "Redis se descargo pero no arranco (revisa logs\redis\redis.log)" }
        } elseif ($type -eq 'ftp_deploy') {
            Set-JobStatus $id $name $type "done" "Desplegado por FTP a $ftpHost ($total archivo(s))"
        } elseif ($type -eq 'db_import_dir') {
            Set-JobStatus $id $name $type "done" "Importados $total archivo(s) .sql en `"$dbname`"" 100
        } elseif ($type -eq 'db_import_file') {
            Set-JobStatus $id $name $type "done" "Importado en `"$dbname`" ($([math]::Round($totalBytes/1MB,1)) MB)" 100
        } else {
            Add-SiteToConfig $name $php
            Set-PhpInis | Out-Null
            Regenerate-Vhosts
            if (Test-HttpdConfig) { Restart-Apache }
            # Proyecto nuevo = dominio nuevo: sin esto el hosts de Windows se queda
            # desactualizado hasta que alguien pulse "Sincronizar dominios" a mano
            # (el mismo mecanismo que usa el panel via lua_hosts(), un archivo-senal
            # que este mismo bucle de watch recoge en su siguiente vuelta).
            Set-Content -Path (Join-Path $TmpDir "hosts.flag") -Value ([string](Get-Date).Ticks) -Encoding ascii
            $dbNote = ""
            # "wordpress" no entra aqui: su BD/usuario los crea ya el panel (action=create en
            # index.php) con los valores exactos del wizard, no un nombre autogenerado con root.
            if ($withdb -and $type -ne 'git' -and $type -ne 'wordpress') {
                $dbname = ($name -replace '[^a-zA-Z0-9_]','_')
                if (New-ProjectDb $dbname $dir $type) { $dbNote = " [BD: $dbname]"; Set-SiteDb $name $dbname $null }
                else { $dbNote = " [aviso: no se pudo crear la BD, MySQL sigue apagado o no instalado]" }
            }
            if ($type -eq 'wordpress' -and $job.wpDbName) {
                Set-SiteDb $name "$($job.wpDbName)" "$($job.wpDbUser)"
                Set-JobStatus $id $name $type "done" "WordPress listo -> http://$name.$(Get-Tld) [BD: $($job.wpDbName)] -- admin: $($job.wpAdminUser) / $($job.wpAdminPass)"
            } else {
                Set-JobStatus $id $name $type "done" "Listo -> http://$name.$(Get-Tld)$dbNote"
            }
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
    # Recolectar las lineas del usuario (fuera de nuestro bloque) SIN perder ninguna si el
    # marcador END falta (crash a mitad de escritura, o borrado a mano dejando solo BEGIN):
    # bufferizamos lo que hay dentro del bloque y, si nunca vemos END, lo devolvemos a $kept.
    # Antes, un bloque sin cerrar marcaba "dentro" el resto del archivo y descartaba todas
    # las entradas manuales del usuario a partir de ahi.
    $kept = @(); $block = @(); $inside = $false; $sawEnd = $false
    foreach ($l in $content) {
        if ($l -eq $HostsBegin) { $inside = $true; continue }
        if ($l -eq $HostsEnd)   { $inside = $false; $sawEnd = $true; continue }
        if ($inside) { $block += $l } else { $kept += $l }
    }
    if ($inside -and -not $sawEnd) { $kept += $block }   # bloque sin cerrar: no perder al usuario
    # UTF-8 SIN BOM: no corromper lineas no-ASCII ajenas (IDN, comentarios con acentos) que
    # -Encoding ascii convertia en '?'; y sin BOM para no confundir al parser de hosts.
    Write-Utf8NoBom $HostsFile (@($kept) + $HostsBegin + $entries + $HostsEnd)
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
    $clash = Get-DomainClash $cfg "$name.$(Get-Tld)" $name
    if ($clash) { Err "El dominio $name.$(Get-Tld) ya lo usa el proyecto '$clash'."; return }
    $dir = Join-Path $Www $name
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
        Write-Utf8NoBom (Join-Path $dir "index.php") "<?php`r`nphpinfo();`r`n"
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
    # Validar el dominio con la misma regla que el panel (valid_domain): el CLI no lo hacia
    # y un dominio con espacios/acentos generaba un vhost invalido que tumbaba TODO Apache.
    if ($domain -and $domain -notmatch '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$') { Err "Dominio no valido (ej.: portal.ersm.test)."; return }
    # -LiteralPath: sin el, una ruta con corchetes (app[dev]) se interpreta como comodin.
    if (-not (Test-Path -LiteralPath $path)) { Err "La ruta no existe: $path"; return }
    $cfg = Get-Config
    if (-not $php) { $php = $cfg.defaultPhp }
    $av = Get-PhpVersions
    if ($av -and ($av -notcontains $php)) { Err "PHP $php no instalado. Disponibles: $($av -join ', ')"; return }
    $clashDom = if ($domain) { $domain } else { "$name.$(Get-Tld)" }
    $clash = Get-DomainClash $cfg $clashDom $name
    if ($clash) { Err "El dominio $clashDom ya lo usa el proyecto '$clash'."; return }
    $full = (Resolve-Path -LiteralPath $path).Path
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
    $rdTxt = "apagado"; $rdC = "Yellow"; if (Redis-Up) { $rdTxt="corriendo (127.0.0.1:$RedisPort, build $(Redis-Build))"; $rdC="Green" }
    Write-Host "  Redis           : " -NoNewline; Write-Host $rdTxt -ForegroundColor $rdC
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
    # Borrar el cert anterior ANTES de regenerar: si mkcert falla, no debe quedar en disco
    # un cert viejo (p.ej. del TLD anterior tras cambiar de dominio) que el guard Test-Path
    # daria por bueno, dejando HTTPS "activo" con un certificado que no casa -> aviso de
    # certificado en el navegador mientras el panel dice "activado".
    Remove-Item $SslCert,$SslKey -Force -ErrorAction SilentlyContinue
    & $Mkcert -cert-file "$SslCert" -key-file "$SslKey" "*.$tld" "$tld" "localhost" "127.0.0.1" "::1"
    if ($LASTEXITCODE -ne 0) { Err "mkcert fallo (codigo $LASTEXITCODE); HTTPS no activado."; $ErrorActionPreference=$prev; return }
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
    "version"     { Write-Output (Get-LuaVersion) }
    "update-check" {
        $e = Update-Check
        if ($e.error) { Err $e.error }
        elseif ($e.detras -gt 0) { Info "Hay $($e.detras) actualizacion(es) disponible(s). Version actual: $($e.version)"; Write-Host $e.mensaje }
        else { Ok "Estas en la ultima version ($($e.version))." }
    }
    "update-now"  { $r = Update-Apply; if ($r -eq 'relanzar') { Warn "lua.ps1 ha cambiado: reinicia con .\lua.ps1 stop y .\lua.ps1 start" } }
    default       { Cmd-Help }
}
