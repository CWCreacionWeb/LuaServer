<#
============================================================
 lua-server :: panel de control (portable)
 Uso:   .\lua.ps1 <comando> [argumentos]

 PRIMEROS PASOS EN UN PC NUEVO:
   .\lua.ps1 init          Ajusta todas las rutas a esta carpeta (portable)
   .\lua.ps1 start         Arranca (modo consola, sin admin)
   http://localhost        Abre el panel

 COMANDOS:
   init                      Re-aplica rutas a la carpeta actual (hazlo tras mover/clonar)
   start | stop | restart    Arranca / para / reinicia (usa servicios si existen, si no modo consola)
   reload                    Regenera vhosts desde sites.json y recarga
   status                    Estado de procesos, puertos, versiones PHP y sitios
   add-site <nombre> [ver]   Crea un proyecto (carpeta + vhost). ver = version PHP (def. defaultPhp)
   remove-site <nombre>      Elimina el vhost (NO borra la carpeta)
   list-sites                Lista proyectos y su version de PHP
   switch-php <nombre> <ver> Cambia la version de PHP de un proyecto
   list-php                  Lista las versiones de PHP instaladas
   hosts                     Lineas hosts para tus companeros (con la IP del server)
   setup                     [ADMIN] Instala servicios + firewall + MariaDB (para servidor de equipo)
   db [shell]                Abre Adminer (web) o el cliente mysql
   logs [apache|mariadb]     Ultimas lineas del log
============================================================
#>

param(
    [Parameter(Position = 0)][string]$Command = "help",
    [Parameter(Position = 1)][string]$Arg1,
    [Parameter(Position = 2)][string]$Arg2
)
$ErrorActionPreference = "Stop"

# --- Rutas (derivadas de la ubicacion del script => PORTABLE) ---
$Root       = $PSScriptRoot
$Bin        = Join-Path $Root "bin"
$Apache     = Join-Path $Bin  "apache"
$Httpd      = Join-Path $Apache "bin\httpd.exe"
$HttpdConf  = Join-Path $Apache "conf\httpd.conf"
$PhpBase    = Join-Path $Bin  "php"
$MariaBin   = Join-Path $Bin  "mariadb\bin"
$MariaD     = Join-Path $MariaBin "mariadbd.exe"
$MysqlExe   = Join-Path $MariaBin "mariadb.exe"
$PluginDir  = (Join-Path $Bin "mariadb\lib\plugin") -replace '\\','/'
$Www        = Join-Path $Root "www"
$VhostDir   = Join-Path $Root "config\apache\vhosts"
$Template   = Join-Path $Root "config\apache\templates\vhost.tpl"
$SitesJson  = Join-Path $Root "config\sites.json"
$MyIni      = Join-Path $Root "config\mariadb\my.ini"
$DataDir    = Join-Path $Root "data\mariadb"
$ApacheLog  = Join-Path $Root "logs\apache"
$MariaLog   = Join-Path $Root "logs\mariadb\error.log"
$TmpDir     = Join-Path $Root "tmp"
$HostsFile  = Join-Path $env:WINDIR "System32\drivers\etc\hosts"

$SvcApache  = "luaApache"
$SvcMaria   = "luaMariaDB"
$Tld        = "lua.test"
$HostsBegin = "# === lua-server BEGIN (no editar a mano) ==="
$HostsEnd   = "# === lua-server END ==="

# extensiones PHP a habilitar (solo si existe su DLL)
$WantExts   = @('curl','intl','mbstring','exif','mysqli','openssl','pdo_mysql','pdo_sqlite','sqlite3','zip','fileinfo','sodium','soap','bz2')

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

function Get-Config { Get-Content $SitesJson -Raw | ConvertFrom-Json }
function Save-Config($cfg) {
    $json = $cfg | ConvertTo-Json -Depth 6
    [System.IO.File]::WriteAllText($SitesJson, $json, (New-Object System.Text.UTF8Encoding($false)))
}
function Get-PhpVersions {
    if (-not (Test-Path $PhpBase)) { return @() }
    Get-ChildItem $PhpBase -Directory | Where-Object { Test-Path (Join-Path $_.FullName "php-cgi.exe") } | ForEach-Object { $_.Name } | Sort-Object
}
function Get-DocRoot($name) {
    $base = Join-Path $Www $name
    $pub  = Join-Path $base "public"
    if (Test-Path $pub) { return $pub } else { return $base }
}
function Get-LanIp {
    $ip = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike "127.*" -and $_.IPAddress -notlike "169.254.*" -and $_.IPAddress -notlike "172.*" -and $_.PrefixOrigin -ne "WellKnown" } |
        Select-Object -First 1
    if ($ip) { return $ip.IPAddress } else { return "127.0.0.1" }
}
function Service-Exists($name) { [bool](Get-Service -Name $name -ErrorAction SilentlyContinue) }
function Fwd($p) { return ($p -replace '\\','/') }

# ============================================================
#  INIT: re-aplica todas las rutas a la carpeta actual (portable)
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
    $c = $c.Replace('#ServerName www.example.com:80', 'ServerName localhost:80')
    if ($c -notmatch 'httpd-lua\.conf') {
        $c = $c + "`r`n`r`n# ================= lua-server =================`r`nDefine LUAROOT `"$lua`"`r`nInclude `"`${LUAROOT}/config/apache/httpd-lua.conf`"`r`n"
    }
    Set-Content -Path $HttpdConf -Value $c -Encoding ascii
    Ok "httpd.conf apuntando a: $srv"
}

function Set-PhpInis {
    foreach ($pd in (Get-ChildItem $PhpBase -Directory | Where-Object { Test-Path "$($_.FullName)\php-cgi.exe" })) {
        $ver = $pd.Name; $dir = $pd.FullName
        $ext = Join-Path $dir "ext"; $ini = Join-Path $dir "php.ini"
        $dev = Join-Path $dir "php.ini-development"; $prod = Join-Path $dir "php.ini-production"
        if (Test-Path $dev) { Copy-Item $dev $ini -Force } elseif (Test-Path $prod) { Copy-Item $prod $ini -Force } else { Set-Content $ini "" -Encoding ascii }
        $lines = Get-Content $ini | ForEach-Object { if ($_ -match '^\s*(zend_)?extension\s*=') { ';' + $_ } else { $_ } }
        $enable = New-Object System.Collections.Generic.List[string]
        foreach ($e in $WantExts) { if (Test-Path (Join-Path $ext "php_$e.dll")) { $enable.Add("extension=$e") } }
        if     (Test-Path (Join-Path $ext "php_gd.dll"))  { $enable.Add("extension=gd") }
        elseif (Test-Path (Join-Path $ext "php_gd2.dll")) { $enable.Add("extension=gd2") }
        $hasOp = Test-Path (Join-Path $ext "php_opcache.dll")
        $tmpF = Fwd $TmpDir
        $b = New-Object System.Collections.Generic.List[string]
        $b.Add(""); $b.Add("; ===== lua-server ====="); $b.Add("extension_dir = `"$(Fwd $ext)`"")
        $b.AddRange([string[]]$enable)
        if ($hasOp) { $b.Add("zend_extension=opcache"); $b.Add("opcache.enable = 1"); $b.Add("opcache.enable_cli = 0"); $b.Add("opcache.validate_timestamps = 1"); $b.Add("opcache.revalidate_freq = 0") }
        $b.Add("date.timezone = Europe/Madrid"); $b.Add("memory_limit = 512M")
        $b.Add("upload_max_filesize = 128M"); $b.Add("post_max_size = 128M"); $b.Add("max_execution_time = 120")
        $b.Add("cgi.fix_pathinfo = 1"); $b.Add("display_errors = On"); $b.Add("error_reporting = E_ALL")
        $b.Add("upload_tmp_dir = `"$tmpF`""); $b.Add("sys_temp_dir = `"$tmpF`""); $b.Add("session.save_path = `"$tmpF`"")
        Set-Content -Path $ini -Value (@($lines) + $b.ToArray()) -Encoding ascii
    }
    Ok "php.ini regenerados ($((Get-PhpVersions) -join ', '))"
}

function Set-MariaIni {
    if (-not (Test-Path $MyIni)) { return }
    $c = Get-Content $MyIni -Raw
    $c = $c -replace '(?m)^\s*datadir\s*=.*',  ("datadir                 = " + (Fwd $DataDir))
    $c = $c -replace '(?m)^\s*socket\s*=.*',   ("socket                  = " + (Fwd (Join-Path $TmpDir 'mysql.sock')))
    $c = $c -replace '(?m)^\s*log-error\s*=.*',("log-error               = " + (Fwd $MariaLog))
    Set-Content -Path $MyIni -Value $c -Encoding ascii
    Ok "my.ini apuntando a: $(Fwd $DataDir)"
}

function New-VhostFile($name, $php) {
    $docroot = Fwd (Get-DocRoot $name)
    $phpdir  = Fwd (Join-Path $PhpBase $php)
    $phpcgi  = Fwd (Join-Path $PhpBase "$php\php-cgi.exe")
    $logdir  = Fwd $ApacheLog
    $tpl = Get-Content $Template -Raw
    $out = $tpl.Replace('{NAME}',$name).Replace('{PHPVER}',$php).Replace('{DOCROOT}',$docroot).Replace('{PHPDIR}',$phpdir).Replace('{PHPCGI}',$phpcgi).Replace('{LOGDIR}',$logdir)
    Set-Content -Path (Join-Path $VhostDir "$name.conf") -Value $out -Encoding ascii
}
function Regenerate-Vhosts {
    if (-not (Test-Path $VhostDir)) { New-Item -ItemType Directory -Force -Path $VhostDir | Out-Null }
    Get-ChildItem $VhostDir -Filter *.conf -ErrorAction SilentlyContinue | Remove-Item -Force
    $cfg = Get-Config
    foreach ($p in $cfg.sites.PSObject.Properties.Name) { New-VhostFile $p $cfg.sites.$p.php }
}

function Cmd-Init {
    Info "Ajustando el stack a: $Root"
    foreach ($d in @($VhostDir,$ApacheLog,$TmpDir,$DataDir,(Join-Path $Root 'logs\mariadb'),(Join-Path $Root 'logs\php'))) {
        New-Item -ItemType Directory -Force -Path $d | Out-Null
    }
    Set-HttpdConf
    Set-PhpInis
    Set-MariaIni
    Regenerate-Vhosts
    if (Test-Path $Httpd) { Info "Validando Apache..."; & $Httpd -t }
    Ok "Init completo. Arranca con:  .\lua.ps1 start"
}

# ============================================================
#  Arranque (servicios si existen; si no, modo consola sin admin)
# ============================================================
function Apache-Up { [bool](Get-Process httpd -ErrorAction SilentlyContinue) }
function Maria-Up  { [bool](Get-Process mariadbd -ErrorAction SilentlyContinue) }

function Cmd-Start {
    if (Service-Exists $SvcApache) { Start-Service $SvcApache; Ok "Apache (servicio) arriba" }
    else {
        if (Apache-Up) { Info "Apache ya estaba arriba" }
        else { Start-Process -FilePath $Httpd -WindowStyle Hidden; Ok "Apache (consola) arrancado" }
    }
    if (Test-Path $MariaD) {
        if (Service-Exists $SvcMaria) { Start-Service $SvcMaria; Ok "MariaDB (servicio) arriba" }
        elseif (-not (Maria-Up)) {
            if (Test-Path (Join-Path $DataDir 'mysql')) { Start-Process -FilePath $MariaD -ArgumentList "--defaults-file=`"$MyIni`"" -WindowStyle Hidden; Ok "MariaDB (consola) arrancada" }
            else { Warn "MariaDB sin inicializar (opcional). Ejecuta 'setup' si quieres base de datos." }
        }
    }
    Write-Host ""
    Ok "Panel:  http://localhost"
    Cmd-ListSites
}
function Cmd-Stop {
    if (Service-Exists $SvcApache) { Stop-Service $SvcApache -Force -ErrorAction SilentlyContinue } else { Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force }
    if (Service-Exists $SvcMaria)  { Stop-Service $SvcMaria  -Force -ErrorAction SilentlyContinue } else { Get-Process mariadbd -ErrorAction SilentlyContinue | Stop-Process -Force }
    Ok "Detenido."
}
function Cmd-Restart {
    if (-not (& $Httpd -t)) { Err "Config invalida, no se reinicia."; return }
    Cmd-Stop; Start-Sleep -Milliseconds 800; Cmd-Start
}
function Cmd-Reload {
    Info "Regenerando vhosts..."
    Regenerate-Vhosts
    if (Test-Admin) { Update-Hosts } else { Warn "Sin admin: no se actualizo hosts. Anade los dominios manualmente o corre 'setup'." }
    & $Httpd -t | Out-Host
    if ($LASTEXITCODE -ne 0) { Err "Config invalida: reload abortado."; return }
    if (Service-Exists $SvcApache) { Restart-Service $SvcApache } elseif (Apache-Up) { Get-Process httpd | Stop-Process -Force; Start-Sleep -Milliseconds 500; Start-Process -FilePath $Httpd -WindowStyle Hidden }
    Ok "Recargado."
}

function Update-Hosts {
    if (-not (Test-Admin)) { return }
    $cfg = Get-Config
    $entries = @("127.0.0.1 localhost.$Tld")
    foreach ($p in $cfg.sites.PSObject.Properties.Name) { $entries += "127.0.0.1 $p.$Tld www.$p.$Tld" }
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
    if (-not $cfg.sites.PSObject.Properties.Name.Contains($name)) { $cfg.sites | Add-Member -NotePropertyName $name -NotePropertyValue ([pscustomobject]@{ php = $php }) -Force }
    else { $cfg.sites.$name.php = $php }
    Save-Config $cfg
    Cmd-Reload
    Ok "Sitio '$name' -> http://$name.$Tld  [PHP $php]"
    if (-not (Test-Admin)) { Warn "Para abrirlo en el navegador anade a hosts (como admin):  127.0.0.1 $name.$Tld" }
}
function Cmd-RemoveSite($name) {
    if (-not $name) { Err "Uso: .\lua.ps1 remove-site <nombre>"; return }
    $cfg = Get-Config
    if ($cfg.sites.PSObject.Properties.Name.Contains($name)) { $cfg.sites.PSObject.Properties.Remove($name); Save-Config $cfg; Cmd-Reload; Ok "Sitio '$name' eliminado (carpeta intacta)." }
    else { Warn "No existe '$name'." }
}
function Cmd-SwitchPhp($name, $php) {
    if (-not $name -or -not $php) { Err "Uso: .\lua.ps1 switch-php <nombre> <version>"; return }
    $av = Get-PhpVersions
    if ($av -and ($av -notcontains $php)) { Err "PHP $php no instalado. Disponibles: $($av -join ', ')"; return }
    $cfg = Get-Config
    if (-not $cfg.sites.PSObject.Properties.Name.Contains($name)) { Err "No existe '$name'."; return }
    $cfg.sites.$name.php = $php; Save-Config $cfg; Cmd-Reload; Ok "'$name' ahora usa PHP $php."
}
function Cmd-ListSites {
    $cfg = Get-Config; $names = $cfg.sites.PSObject.Properties.Name
    if ($names.Count -eq 0) { Info "Sin sitios. Crea uno:  .\lua.ps1 add-site micliente"; return }
    foreach ($p in $names) { Write-Host ("  {0,-18} PHP {1,-5} http://{2}.{3}" -f $p,$cfg.sites.$p.php,$p,$Tld) }
}
function Cmd-ListPhp {
    $v = Get-PhpVersions
    if ($v.Count -eq 0) { Warn "No hay PHP en $PhpBase (ejecuta bootstrap.ps1)"; return }
    foreach ($x in $v) { Write-Host "  PHP $x" }
}
function Cmd-Status {
    Write-Host ""; Write-Host "  lua-server  |  $Root" -ForegroundColor White
    Write-Host "  ------------------------------------------------"
    Write-Host ("  IP LAN                : {0}" -f (Get-LanIp))
    $apTxt = "parado"; $apC = "Yellow"; if (Apache-Up -or ((Service-Exists $SvcApache) -and (Get-Service $SvcApache).Status -eq 'Running')) { $apTxt="corriendo"; $apC="Green" }
    Write-Host "  Apache               : " -NoNewline; Write-Host $apTxt -ForegroundColor $apC
    $dbTxt = "parada"; $dbC = "Yellow"; if (Maria-Up -or ((Service-Exists $SvcMaria) -and (Get-Service $SvcMaria).Status -eq 'Running')) { $dbTxt="corriendo"; $dbC="Green" }
    Write-Host "  MariaDB              : " -NoNewline; Write-Host $dbTxt -ForegroundColor $dbC
    Write-Host ("  PHP instalados       : {0}" -f ((Get-PhpVersions) -join ', '))
    Write-Host "  Sitios:"; Cmd-ListSites; Write-Host ""
}
function Cmd-Hosts {
    $lan = Get-LanIp; $cfg = Get-Config
    Write-Host "`nTus companeros deben anadir esto a C:\Windows\System32\drivers\etc\hosts (como admin):`n"
    Write-Host $HostsBegin -ForegroundColor DarkGray
    foreach ($p in $cfg.sites.PSObject.Properties.Name) { Write-Host ("{0} {1}.{2} www.{1}.{2}" -f $lan,$p,$Tld) }
    Write-Host $HostsEnd -ForegroundColor DarkGray; Write-Host ""
}
function Cmd-Db($mode) {
    if ($mode -eq "shell") { & $MysqlExe --plugin-dir="$PluginDir" -h 127.0.0.1 -P 3306 -u root -p }
    else { Start-Process "http://localhost/adminer"; Ok "Adminer en http://localhost/adminer (servidor: 127.0.0.1)" }
}
function Cmd-Logs($which) {
    if ($which -eq "mariadb") { Get-Content $MariaLog -Tail 40 -ErrorAction SilentlyContinue }
    else { Get-Content (Join-Path $ApacheLog "error.log") -Tail 40 -ErrorAction SilentlyContinue }
}
function Cmd-Help { Get-Content $PSCommandPath -TotalCount 42 | ForEach-Object { $_ } }

switch ($Command.ToLower()) {
    "init"        { Cmd-Init }
    "start"       { Cmd-Start }
    "stop"        { Cmd-Stop }
    "restart"     { Cmd-Restart }
    "reload"      { Cmd-Reload }
    "status"      { Cmd-Status }
    "add-site"    { Cmd-AddSite $Arg1 $Arg2 }
    "remove-site" { Cmd-RemoveSite $Arg1 }
    "switch-php"  { Cmd-SwitchPhp $Arg1 $Arg2 }
    "list-sites"  { Cmd-ListSites }
    "list-php"    { Cmd-ListPhp }
    "hosts"       { Cmd-Hosts }
    "setup"       { Require-Admin; & (Join-Path $Root "config\_setup.ps1") }
    "db"          { Cmd-Db $Arg1 }
    "logs"        { Cmd-Logs $Arg1 }
    default       { Cmd-Help }
}
