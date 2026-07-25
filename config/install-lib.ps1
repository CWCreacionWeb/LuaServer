<#
============================================================
 lua-server :: catalogo de instalacion (compartido)

 Fuente unica de las URLs/versiones de los binarios que hacen
 falta en un PC nuevo. La usan bootstrap.ps1 (instala todo, sin
 interfaz) e install.ps1 (asistente grafico con opcionales) para
 no duplicar la lista y no desincronizarse cuando se actualice
 una version.

 Dot-source este archivo, no lo ejecutes suelto:
   . (Join-Path $PSScriptRoot "install-lib.ps1")
============================================================
#>

function Get-InstallCatalog {
    @(
        # --- core: siempre se instalan (orden importa: httpd antes que fcgid) ---
        [pscustomobject]@{ Id = 'vcredist'; Order = 1;  Group = 'core'; Required = $true;  PhpVersion = $null; Label = 'Visual C++ Redistributable'; File = 'vc_redist.x64.exe';                  Url = 'https://aka.ms/vc14/vc_redist.x64.exe' }
        [pscustomobject]@{ Id = 'httpd';    Order = 2;  Group = 'core'; Required = $true;  PhpVersion = $null; Label = 'Apache 2.4';                 File = 'httpd-2.4.68-260617-Win64-VS18.zip'; Url = 'https://www.apachelounge.com/download/VS18/binaries/httpd-2.4.68-260617-Win64-VS18.zip' }
        [pscustomobject]@{ Id = 'fcgid';    Order = 3;  Group = 'core'; Required = $true;  PhpVersion = $null; Label = 'mod_fcgid';                  File = 'mod_fcgid-2.3.10-win64-VS18.zip';    Url = 'https://www.apachelounge.com/download/VS18/modules/mod_fcgid-2.3.10-win64-VS18.zip' }
        [pscustomobject]@{ Id = 'composer'; Order = 4;  Group = 'core'; Required = $true;  PhpVersion = $null; Label = 'Composer';                   File = 'composer.phar';                      Url = 'https://getcomposer.org/composer-stable.phar' }

        # --- versiones de PHP (8.4 obligatoria, resto opcionales) ---
        [pscustomobject]@{ Id = 'php85'; Order = 10; Group = 'php'; Required = $false; PhpVersion = '8.5'; Label = 'PHP 8.5';               File = 'php-8.5.8-nts-Win32-vs17-x64.zip';  Url = 'https://windows.php.net/downloads/releases/php-8.5.8-nts-Win32-vs17-x64.zip' }
        [pscustomobject]@{ Id = 'php84'; Order = 11; Group = 'php'; Required = $true;  PhpVersion = '8.4'; Label = 'PHP 8.4 (recomendada)';  File = 'php-8.4.23-nts-Win32-vs17-x64.zip'; Url = 'https://windows.php.net/downloads/releases/php-8.4.23-nts-Win32-vs17-x64.zip' }
        [pscustomobject]@{ Id = 'php83'; Order = 12; Group = 'php'; Required = $false; PhpVersion = '8.3'; Label = 'PHP 8.3';               File = 'php-8.3.32-nts-Win32-vs16-x64.zip'; Url = 'https://windows.php.net/downloads/releases/php-8.3.32-nts-Win32-vs16-x64.zip' }
        [pscustomobject]@{ Id = 'php82'; Order = 13; Group = 'php'; Required = $false; PhpVersion = '8.2'; Label = 'PHP 8.2';               File = 'php-8.2.32-nts-Win32-vs16-x64.zip'; Url = 'https://windows.php.net/downloads/releases/php-8.2.32-nts-Win32-vs16-x64.zip' }
        [pscustomobject]@{ Id = 'php81'; Order = 14; Group = 'php'; Required = $false; PhpVersion = '8.1'; Label = 'PHP 8.1';               File = 'php-8.1.34-nts-Win32-vs16-x64.zip'; Url = 'https://windows.php.net/downloads/releases/archives/php-8.1.34-nts-Win32-vs16-x64.zip' }
        [pscustomobject]@{ Id = 'php74'; Order = 15; Group = 'php'; Required = $false; PhpVersion = '7.4'; Label = 'PHP 7.4';               File = 'php-7.4.33-nts-Win32-vc15-x64.zip'; Url = 'https://windows.php.net/downloads/releases/archives/php-7.4.33-nts-Win32-vc15-x64.zip' }
        [pscustomobject]@{ Id = 'php71'; Order = 16; Group = 'php'; Required = $false; PhpVersion = '7.1'; Label = 'PHP 7.1';               File = 'php-7.1.33-nts-Win32-VC14-x64.zip'; Url = 'https://windows.php.net/downloads/releases/archives/php-7.1.33-nts-Win32-VC14-x64.zip' }

        # --- opcionales ---
        [pscustomobject]@{ Id = 'mariadb';    Order = 20; Group = 'mariadb';    Required = $false; PhpVersion = $null; Label = 'MariaDB 11.8 (MySQL nativo)'; File = 'mariadb-11.8.8-winx64.zip';           Url = 'https://archive.mariadb.org/mariadb-11.8.8/winx64-packages/mariadb-11.8.8-winx64.zip' }
        [pscustomobject]@{ Id = 'postgres';   Order = 24; Group = 'postgres';   Required = $false; PhpVersion = $null; Label = 'PostgreSQL 16.14 (nativo)';  File = 'postgresql-16.14-2-windows-x64-binaries.zip'; Url = 'https://get.enterprisedb.com/postgresql/postgresql-16.14-2-windows-x64-binaries.zip' }
        [pscustomobject]@{ Id = 'mailpit';    Order = 21; Group = 'mailpit';    Required = $false; PhpVersion = $null; Label = 'Mailpit (captura de correo)'; File = 'mailpit-windows-amd64.zip';           Url = 'https://github.com/axllent/mailpit/releases/latest/download/mailpit-windows-amd64.zip' }
        [pscustomobject]@{ Id = 'mkcert';     Order = 22; Group = 'https';      Required = $false; PhpVersion = $null; Label = 'mkcert (HTTPS local)';        File = 'mkcert.exe';                          Url = 'https://github.com/FiloSottile/mkcert/releases/download/v1.4.4/mkcert-v1.4.4-windows-amd64.exe' }
        [pscustomobject]@{ Id = 'phpmyadmin'; Order = 23; Group = 'phpmyadmin'; Required = $false; PhpVersion = $null; Label = 'phpMyAdmin';                  File = 'phpMyAdmin-5.2.3-english.zip';        Url = 'https://files.phpmyadmin.net/phpMyAdmin/5.2.3/phpMyAdmin-5.2.3-english.zip' }
    )
}

# Sort-Object en Windows PowerShell 5.1 no es estable: no basta con agrupar,
# cada item lleva su propio Order unico para fijar la secuencia de instalacion
# (p.ej. httpd tiene que extraerse antes que fcgid, que copia su .so dentro).
function Sort-CatalogItems {
    param([Parameter(Mandatory)] $Items)
    $Items | Sort-Object Order
}

# Descarga un item a $DownloadsDir (cache: si ya existe, no vuelve a bajarlo).
function Invoke-CatalogDownload {
    param([Parameter(Mandatory)] $Item, [Parameter(Mandatory)] [string]$DownloadsDir)
    $out = Join-Path $DownloadsDir $Item.File
    if (Test-Path $out) { return $out }
    $ua = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36"
    $headers = @{}
    if ($Item.Url -like '*apachelounge.com*') { $headers['Referer'] = 'https://www.apachelounge.com/' }
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -Uri $Item.Url -OutFile $out -UserAgent $ua -Headers $headers -TimeoutSec 600
    return $out
}

# Coloca/extrae un item ya descargado en su sitio final bajo $Root.
function Install-CatalogItem {
    param([Parameter(Mandatory)] $Item, [Parameter(Mandatory)] [string]$Root, [Parameter(Mandatory)] [string]$DownloadsDir)
    $src = Join-Path $DownloadsDir $Item.File

    switch ($Item.Group) {
        'core' {
            switch ($Item.Id) {
                'vcredist' {
                    # Solo se deja cacheado en downloads\; se instala a mano si hace falta (ver README).
                }
                'httpd' {
                    $tmp = Join-Path $DownloadsDir "_x_httpd"
                    if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
                    Expand-Archive $src $tmp -Force
                    $dest = Join-Path $Root "bin\apache"
                    New-Item -ItemType Directory -Force -Path $dest | Out-Null
                    Get-ChildItem (Join-Path $tmp "Apache24") -Force | Move-Item -Destination $dest -Force
                    Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
                }
                'fcgid' {
                    $tmp = Join-Path $DownloadsDir "_x_fcgid"
                    if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
                    Expand-Archive $src $tmp -Force
                    $so = Get-ChildItem $tmp -Recurse -Filter "mod_fcgid.so" | Select-Object -First 1
                    Copy-Item $so.FullName (Join-Path $Root "bin\apache\modules\mod_fcgid.so") -Force
                    Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
                }
                'composer' {
                    $dest = Join-Path $Root "bin\composer"
                    New-Item -ItemType Directory -Force -Path $dest | Out-Null
                    Copy-Item $src (Join-Path $dest "composer.phar") -Force
                }
            }
        }
        'php' {
            $dest = Join-Path $Root "bin\php\$($Item.PhpVersion)"
            if (Test-Path $dest) { Get-ChildItem $dest -Force | Remove-Item -Recurse -Force }
            Expand-Archive $src $dest -Force
        }
        'mariadb' {
            $tmp = Join-Path $DownloadsDir "_x_mariadb"
            if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
            Expand-Archive $src $tmp -Force
            $dest = Join-Path $Root "bin\mariadb"
            if (Test-Path $dest) { Get-ChildItem $dest -Force | Remove-Item -Recurse -Force } else { New-Item -ItemType Directory -Force -Path $dest | Out-Null }
            Get-ChildItem $tmp -Directory | Select-Object -First 1 | Get-ChildItem -Force | Move-Item -Destination $dest -Force
            Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
        }
        'postgres' {
            # El zip de EnterpriseDB trae una carpeta raiz "pgsql" con bin/, lib/, share/...
            $tmp = Join-Path $DownloadsDir "_x_postgres"
            if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
            Expand-Archive $src $tmp -Force
            $dest = Join-Path $Root "bin\postgres"
            if (Test-Path $dest) { Get-ChildItem $dest -Force | Remove-Item -Recurse -Force } else { New-Item -ItemType Directory -Force -Path $dest | Out-Null }
            Get-ChildItem $tmp -Directory | Select-Object -First 1 | Get-ChildItem -Force | Move-Item -Destination $dest -Force
            Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
        }
        'mailpit' {
            $dest = Join-Path $Root "bin\mailpit"
            New-Item -ItemType Directory -Force -Path $dest | Out-Null
            Expand-Archive $src $dest -Force
        }
        'https' {
            $dest = Join-Path $Root "bin\mkcert"
            New-Item -ItemType Directory -Force -Path $dest | Out-Null
            Copy-Item $src (Join-Path $dest "mkcert.exe") -Force
        }
        'phpmyadmin' {
            $dest = Join-Path $Root "tools\phpmyadmin"
            if (Test-Path $dest) { Remove-Item $dest -Recurse -Force }
            $tmp = Join-Path $DownloadsDir "_x_pma"
            if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
            Expand-Archive $src $tmp -Force
            $inner = Get-ChildItem $tmp -Directory | Select-Object -First 1
            Move-Item $inner.FullName $dest -Force
            Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue

            # Reconstruye el tema "lua" (personalizado) a partir del tema de fabrica
            # "pmahomme" que acaba de llegar en el zip: iconos y screen.png son
            # identicos a los de fabrica (no hace falta versionarlos), solo
            # theme.json/fonts/override.css son de lua-server (versionados en
            # config\phpmyadmin-theme\) y logo/favicon se reusan de tools\dashboard\assets\
            # (ya versionados, son los mismos archivos).
            $themeSrc = Join-Path $dest "themes\pmahomme"
            $themeDst = Join-Path $dest "themes\lua"
            $luaThemeCfg = Join-Path $Root "config\phpmyadmin-theme"
            if ((Test-Path $themeSrc) -and (Test-Path $luaThemeCfg)) {
                Copy-Item $themeSrc $themeDst -Recurse -Force
                Copy-Item (Join-Path $luaThemeCfg "theme.json") (Join-Path $themeDst "theme.json") -Force
                Copy-Item (Join-Path $Root "tools\dashboard\assets\logo.svg")    (Join-Path $themeDst "logo.svg") -Force
                Copy-Item (Join-Path $Root "tools\dashboard\assets\favicon.svg") (Join-Path $themeDst "favicon.svg") -Force
                New-Item -ItemType Directory -Force -Path (Join-Path $themeDst "fonts") | Out-Null
                Copy-Item (Join-Path $luaThemeCfg "fonts\*") (Join-Path $themeDst "fonts") -Force
                # -Encoding UTF8 explicito al leer: el theme.css de pmahomme es UTF-8 SIN
                # BOM, y Get-Content sin -Encoding lo interpreta con el codepage ANSI del
                # sistema, corrompiendo cualquier caracter no-ASCII (p.ej. un guion largo
                # queda como "â€”"). WriteAllText + UTF8Encoding($false) al escribir: sin
                # BOM (Set-Content -Encoding utf8 en PowerShell 5.1 SI escribe BOM, y el
                # theme.css original no lo lleva).
                $vendorCss   = Get-Content (Join-Path $themeDst "css\theme.css") -Raw -Encoding UTF8
                $overrideCss = Get-Content (Join-Path $luaThemeCfg "override.css") -Raw -Encoding UTF8
                [System.IO.File]::WriteAllText((Join-Path $themeDst "css\theme.css"), ($vendorCss + "`n" + $overrideCss), (New-Object System.Text.UTF8Encoding($false)))
            }

            $secret = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 32 | ForEach-Object { [char]$_ })
            $cfgPhp = @"
<?php
`$cfg['blowfish_secret'] = '$secret';
`$i = 0;
`$i++;
`$cfg['Servers'][`$i]['auth_type'] = 'config';
`$cfg['Servers'][`$i]['host'] = '127.0.0.1';
`$cfg['Servers'][`$i]['user'] = 'root';
`$cfg['Servers'][`$i]['password'] = '';
`$cfg['Servers'][`$i]['AllowNoPassword'] = true;
`$cfg['UploadDir'] = '';
`$cfg['SaveDir'] = '';
`$cfg['ThemeDefault'] = 'lua';
`$cfg['ThemeManager'] = true;
"@
            [System.IO.File]::WriteAllText((Join-Path $dest "config.inc.php"), $cfgPhp, (New-Object System.Text.UTF8Encoding($false)))
        }
    }
}

# Registra phpMyAdmin como sitio externo (PHP 8.4, dominio por defecto) si aun no lo esta.
function Register-PhpMyAdminSite {
    param([Parameter(Mandatory)] [string]$Root)
    $sitesJson = Join-Path $Root "config\sites.json"
    $already = $false
    if (Test-Path $sitesJson) {
        try {
            $cfg = Get-Content $sitesJson -Raw | ConvertFrom-Json
            if ($cfg.sites.PSObject.Properties.Name -contains 'phpmyadmin') { $already = $true }
        } catch {}
    }
    if ($already) { return }
    & (Join-Path $Root "lua.ps1") add-external phpmyadmin (Join-Path $Root "tools\phpmyadmin") "" "8.4" | Out-Null
}
