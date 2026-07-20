# === Auto-generado por lua.ps1 — NO editar (se sobrescribe en cada reload) ===
# Proyecto: {NAME}   PHP: {PHPVER}
<VirtualHost *:80>
    ServerName {NAME}.lua.test
    ServerAlias www.{NAME}.lua.test *.{NAME}.lua.test
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
