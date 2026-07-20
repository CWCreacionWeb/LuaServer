# === Auto-generado por lua.ps1 — NO editar (se sobrescribe en cada reload) ===
# Proyecto: {NAME}   PHP: {PHPVER}
<VirtualHost *:80>
    ServerName {DOMAIN}
    ServerAlias www.{DOMAIN}
    DocumentRoot "{DOCROOT}"
    FcgidInitialEnv PHPRC "{PHPDIR}"

    <Directory "{DOCROOT}">
        Options +ExecCGI +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
        FcgidWrapper "{PHPCGI}" .php
    </Directory>

    ErrorLog  "{LOGDIR}/{NAME}-error.log"
    CustomLog "{LOGDIR}/{NAME}-access.log" combined
</VirtualHost>
