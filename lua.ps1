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
   remove-site <nombre>      Elimina el vhost (NO borra la carpeta)
   list-sites                Lista proyectos y su version de PHP
   switch-php <nombre> <ver> Cambia la version de PHP de un proyecto
   list-php                  Lista las versiones de PHP instaladas
   hosts                     Lineas hosts para tus companeros (con la IP del server)
   setup                     [ADMIN] Instala Apache como servicio + firewall + hosts
   logs                      Ultimas lineas del log de Apache
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
$Www        = Join-Path $Root "www"
$VhostDir   = Join-Path $Root "config\apache\vhosts"
$Template   = Join-Path $Root "config\apache\templates\vhost.tpl"
$SitesJson  = Join-Path $Root "config\sites.json"
$ApacheLog  = Join-Path $Root "logs\apache"
$TmpDir     = Join-Path $Root "tmp"
$HostsFile  = Join-Path $env:WINDIR "System32\drivers\etc\hosts"

$SvcApache  = "luaApache"
$Tld        = "lua.test"
$HostsBegin = "# === lua-server BEGIN (no editar a mano) ==="
$HostsEnd   = "# === lua-server END ==="

# extensiones PHP a habilitar (solo si existe su DLL). mysqli/pdo_mysql incluidas
# por si tus proyectos conectan a un MySQL (p.ej. en Docker) via 127.0.0.1.
# com_dotnet lo usa el panel para lanzar la recarga de Apache en segundo plano.
$WantExts   = @('curl','intl','mbstring','exif','mysqli','openssl','pdo_mysql','pdo_sqlite','sqlite3','zip','fileinfo','sodium','soap','bz2','com_dotnet')

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
            $b.Add("zend_extension=xdebug")
            $b.Add("xdebug.mode=debug")
            $b.Add("xdebug.start_with_request=yes")
            $b.Add("xdebug.client_host=127.0.0.1")
            $b.Add("xdebug.client_port=9003")
            $b.Add("xdebug.idekey=VSCODE")
        }
        Set-Content -Path $ini -Value (@($lines) + $b.ToArray()) -Encoding ascii
    }
    Ok "php.ini regenerados ($((Get-PhpVersions) -join ', '))"
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
    foreach ($d in @($VhostDir,$ApacheLog,$TmpDir,(Join-Path $Root 'logs\php'))) { New-Item -ItemType Directory -Force -Path $d | Out-Null }
    Set-HttpdConf
    Set-PhpInis
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

function Cmd-Start {
    if (Service-Exists $SvcApache) { Start-Service $SvcApache; Ok "Apache (servicio) arriba" }
    elseif (Apache-Up) { Info "Apache ya estaba arriba" }
    else { Start-Process -FilePath $Httpd -WindowStyle Hidden; Ok "Apache arrancado" }
    Start-Watcher
    Write-Host ""; Ok "Panel:  http://localhost"
    Cmd-ListSites
}
function Cmd-Stop {
    if (Service-Exists $SvcApache) { Stop-Service $SvcApache -Force -ErrorAction SilentlyContinue } else { Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force }
    $pf = Join-Path $TmpDir "watch.pid"
    if (Test-Path $pf) { $wp = Get-Content $pf -ErrorAction SilentlyContinue; if ($wp) { Stop-Process -Id ([int]$wp) -Force -ErrorAction SilentlyContinue }; Remove-Item $pf -Force -ErrorAction SilentlyContinue }
    Ok "Apache detenido."
}

# Watcher: proceso independiente que aplica los cambios pedidos desde el panel web.
# El panel solo crea archivos-senal en tmp\; este proceso los ejecuta (no es hijo de Apache).
function Cmd-Watch {
    $pf = Join-Path $TmpDir "watch.pid"; Set-Content -Path $pf -Value $PID -Encoding ascii
    $fApply = Join-Path $TmpDir "apply.flag"
    $fHosts = Join-Path $TmpDir "hosts.flag"
    while ($true) {
        try {
            if (Test-Path $fApply) { Remove-Item $fApply -Force -ErrorAction SilentlyContinue; Cmd-Apply }
            if (Test-Path $fHosts) { Remove-Item $fHosts -Force -ErrorAction SilentlyContinue; Start-Process powershell -Verb RunAs -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File',"`"$PSCommandPath`"",'hosts-sync') }
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
    if (-not $cfg.sites.PSObject.Properties.Name.Contains($name)) { $cfg.sites | Add-Member -NotePropertyName $name -NotePropertyValue ([pscustomobject]@{ php=$php }) -Force }
    else { $cfg.sites.$name.php = $php }
    Save-Config $cfg
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
    $name="$($job.name)"; $type="$($job.type)"; $php="$($job.php)"; $url="$($job.url)"
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
            default     { $ok=$false; $err="Tipo desconocido: $type" }
        }
        if ($ok -and ($type -ne 'xdebug') -and -not (Test-Path $dir)) { $ok=$false; $err="No se creo la carpeta del proyecto" }
    } catch { $ok=$false; $err=$_.Exception.Message }
    $ErrorActionPreference = $prev
    if ($ok) {
        if ($type -eq 'xdebug') {
            Set-PhpInis | Out-Null
            if (Test-HttpdConfig) { Restart-Apache }
            Set-JobStatus $id $name $type "done" "Xdebug activado en PHP $php"
        } else {
            Add-SiteToConfig $name $php
            Set-PhpInis | Out-Null
            Regenerate-Vhosts
            if (Test-HttpdConfig) { Restart-Apache }
            Set-JobStatus $id $name $type "done" "Listo -> http://$name.$Tld"
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
    Write-Host ""; Write-Host "  lua-server (solo PHP)  |  $Root" -ForegroundColor White
    Write-Host "  ------------------------------------------------"
    Write-Host ("  IP LAN          : {0}" -f (Get-LanIp))
    $apTxt = "parado"; $apC = "Yellow"; if (Apache-Up -or ((Service-Exists $SvcApache) -and (Get-Service $SvcApache).Status -eq 'Running')) { $apTxt="corriendo"; $apC="Green" }
    Write-Host "  Apache          : " -NoNewline; Write-Host $apTxt -ForegroundColor $apC
    Write-Host ("  PHP instalados  : {0}" -f ((Get-PhpVersions) -join ', '))
    Write-Host "  Sitios:"; Cmd-ListSites; Write-Host ""
}
function Cmd-Hosts {
    $lan = Get-LanIp; $cfg = Get-Config
    Write-Host "`nTus companeros deben anadir esto a C:\Windows\System32\drivers\etc\hosts (como admin):`n"
    Write-Host $HostsBegin -ForegroundColor DarkGray
    foreach ($p in $cfg.sites.PSObject.Properties.Name) { Write-Host ("{0} {1}.{2} www.{1}.{2}" -f $lan,$p,$Tld) }
    Write-Host $HostsEnd -ForegroundColor DarkGray; Write-Host ""
}
function Cmd-Logs { Get-Content (Join-Path $ApacheLog "error.log") -Tail 40 -ErrorAction SilentlyContinue }
function Cmd-Help { Get-Content $PSCommandPath -TotalCount 40 | ForEach-Object { $_ } }

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
    "apply"       { Cmd-Apply }
    "watch"       { Cmd-Watch }
    "hosts-sync"  { Require-Admin; Update-Hosts; Ok "Dominios sincronizados en el archivo hosts." }
    "setup"       { Require-Admin; & (Join-Path $Root "config\_setup.ps1") }
    "logs"        { Cmd-Logs }
    default       { Cmd-Help }
}
