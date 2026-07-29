<?php
// ---------------- MySQL (MariaDB): listar/crear/eliminar bases de datos ----------------
function valid_dbname($n){ return (bool)preg_match('/^[a-zA-Z0-9_]{1,64}$/', (string)$n); }
function valid_mysql_user($n){ return (bool)preg_match('/^[a-zA-Z0-9_]{1,32}$/', (string)$n); }
function valid_mysql_host($h){ return in_array($h, ['127.0.0.1','localhost','%'], true); }
// Contraseña de root guardada aparte del sitio (config\mysql_root.pass), fuera de git.
function mysql_root_pass($root){
    $f = $root.'/config/mysql_root.pass';
    return is_file($f) ? trim((string)@file_get_contents($f)) : '';
}
// Contraseñas de los usuarios MySQL creados desde el panel (config\mysql_users.pass.json,
// fuera de git, mismo espiritu que mysql_root.pass): se guardan SOLO para poder ofrecerlos
// en el desplegable de conexiones de phpMyAdmin (pma_sync_servers) sin volver a teclearlas.
// Clave "usuario@host" porque el mismo nombre puede existir en mas de un host.
function mysql_users_passwords($root){
    $f = $root.'/config/mysql_users.pass.json';
    if (!is_file($f)) return [];
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function mysql_user_save_password($root, $user, $host, $pass){
    $d = mysql_users_passwords($root);
    $d[$user.'@'.$host] = $pass;
    @file_put_contents($root.'/config/mysql_users.pass.json', json_encode($d));
}
function mysql_user_forget_password($root, $user, $host){
    $d = mysql_users_passwords($root);
    unset($d[$user.'@'.$host]);
    @file_put_contents($root.'/config/mysql_users.pass.json', json_encode($d));
}
// Reconstruye, dentro de un bloque delimitado por marcadores, las conexiones de phpMyAdmin
// (auth_type=config, con user/password ya puestos) para root y cada usuario MySQL creado
// desde el panel -- asi el desplegable de servidores de phpMyAdmin entra directo, sin volver
// a escribir credenciales. Solo toca lo que hay ENTRE los marcadores: cualquier conexion
// que el usuario haya anadido a mano al archivo (fuera de ellos) se deja intacta. No-op si
// phpMyAdmin no esta instalado, o si el archivo no tiene ni siquiera "$i = 0;" (estructura
// demasiado distinta de lo esperado -- mejor no tocar nada que arriesgarse a romperlo).
function pma_sync_servers($root){
    $f = $root.'/tools/phpmyadmin/config.inc.php';
    if (!is_file($f)) return;
    $c = @file_get_contents($f);
    if ($c === false) return;

    $rootPass = mysql_root_pass($root);
    $entries = [['verbose'=>'root (local)', 'host'=>'127.0.0.1', 'port'=>'3306', 'user'=>'root', 'pass'=>$rootPass, 'allowNoPass'=>$rootPass==='']];

    $liveUsers = [];
    try { foreach (mysql_users() ?: [] as $u) { $liveUsers[$u['user'].'@'.$u['host']] = true; } } catch (Throwable $e) {}
    foreach (mysql_users_passwords($root) as $key => $pass) {
        if (!isset($liveUsers[$key])) continue; // cuenta ya borrada de MySQL -> no ofrecerla
        [$u, $h] = array_pad(explode('@', $key, 2), 2, '127.0.0.1');
        if (strcasecmp($u, 'root') === 0) continue; // root ya tiene su propio slot arriba
        $entries[] = ['verbose'=>$u, 'host'=>$h, 'port'=>'3306', 'user'=>$u, 'pass'=>$pass, 'allowNoPass'=>$pass===''];
    }

    $lines = ["// ===== lua-server: conexiones auto-generadas (NO editar a mano, se sobrescriben) ====="];
    foreach ($entries as $e) {
        $q = function($s){ return "'".addcslashes((string)$s, "\\'")."'"; };
        $lines[] = '$i++;';
        $lines[] = "\$cfg['Servers'][\$i]['verbose'] = ".$q($e['verbose']).';';
        $lines[] = "\$cfg['Servers'][\$i]['host'] = ".$q($e['host']).';';
        $lines[] = "\$cfg['Servers'][\$i]['port'] = ".$q($e['port']).';';
        $lines[] = "\$cfg['Servers'][\$i]['auth_type'] = 'config';";
        $lines[] = "\$cfg['Servers'][\$i]['user'] = ".$q($e['user']).';';
        $lines[] = "\$cfg['Servers'][\$i]['password'] = ".$q($e['pass']).';';
        $lines[] = "\$cfg['Servers'][\$i]['AllowNoPassword'] = ".($e['allowNoPass']?'true':'false').';';
    }
    $lines[] = "// ===== fin conexiones lua-server =====";
    $block = implode("\n", $lines);

    // preg_replace_callback (no preg_replace): el reemplazo se devuelve literal, si no PHP
    // intenta interpretar los "$i"/"$cfg" del bloque como referencias de grupo ($1, etc.).
    $marker = '/\/\/ ===== lua-server: conexiones auto-generadas.*?\/\/ ===== fin conexiones lua-server =====/s';
    if (preg_match($marker, $c)) {
        $c = preg_replace_callback($marker, function($m) use ($block){ return $block; }, $c, 1);
    } elseif (preg_match('/\$i\s*=\s*0;/', $c)) {
        $c = preg_replace_callback('/(\$i\s*=\s*0;)/', function($m) use ($block){ return $m[1]."\n\n".$block; }, $c, 1);
    } else {
        return;
    }
    @file_put_contents($f, $c);
}
// Sincroniza el nombre de marca (junto al logo, en la cabecera de navegacion de
// phpMyAdmin) con el que se acaba de guardar en el panel. El nombre va "horneado"
// como texto literal en el tema (content:"..." de un ::after, ver
// config\phpmyadmin-theme\override.css) porque CSS no puede leer config de PHP en
// caliente: aqui se parchea el css ya generado en disco. No-op si phpMyAdmin no
// esta instalado (Install-CatalogItem ya usa el nombre correcto en instalaciones
// nuevas, ver config\install-lib.ps1).
function pma_sync_brand_name($root, $name){
    $f = $root.'/tools/phpmyadmin/themes/lua/css/theme.css';
    if (!is_file($f)) return;
    $c = @file_get_contents($f);
    if ($c === false) return;
    $lit = '"'.addcslashes($name !== '' ? $name : 'lua-server', "\\\"").'"';
    $c = preg_replace_callback('/(#pma_navigation_header\s+#pmalogo::after\{\s*content:)"[^"]*"/',
            function($m) use ($lit){ return $m[1].$lit; }, $c, 1);
    @file_put_contents($f, $c);
}
function mysql_pdo(){
    global $ROOT;
    $pass = mysql_root_pass($ROOT);
    return new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>3]);
}
// null = no se pudo conectar (MySQL apagado); array = bases de datos de usuario (sin esquemas de sistema)
function mysql_databases(){
    static $sys = ['information_schema','performance_schema','mysql','sys'];
    try {
        $pdo = mysql_pdo();
        $out = [];
        foreach ($pdo->query('SHOW DATABASES') as $row) { if (!in_array($row[0], $sys, true)) $out[] = $row[0]; }
        sort($out);
        return $out;
    } catch (Throwable $e) { return null; }
}
// null = no se pudo conectar; array de ['user'=>..,'host'=>..] (sin cuentas de sistema)
function mysql_users(){
    static $sys = ['mysql.sys','mariadb.sys','mysql.infoschema','mysql.session'];
    try {
        $pdo = mysql_pdo();
        $out = [];
        foreach ($pdo->query('SELECT User, Host FROM mysql.user') as $row) {
            // Host vacio = rol interno (p.ej. PUBLIC en MariaDB 10.11+), no una cuenta con login real:
            // nunca se crea desde este panel y "Eliminar" fallaria (valid_mysql_host lo rechaza).
            if ($row['User'] === '' || $row['Host'] === '' || in_array($row['User'], $sys, true)) continue;
            $out[] = ['user'=>$row['User'], 'host'=>$row['Host']];
        }
        usort($out, function($a,$b){ return [$a['user'],$a['host']] <=> [$b['user'],$b['host']]; });
        return $out;
    } catch (Throwable $e) { return null; }
}
// Deduce a que bases de datos tiene acceso un usuario, leyendo SHOW GRANTS (evita depender
// de columnas internas que cambian entre versiones de MariaDB/MySQL). null si falla.
function mysql_user_scope($pdo, $user, $host){
    try {
        $rows = $pdo->query('SHOW GRANTS FOR '.$pdo->quote($user).'@'.$pdo->quote($host))->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return null; }
    $all = false; $dbs = [];
    foreach ($rows as $line) {
        // La primera fila de toda cuenta es "GRANT USAGE ON *.* TO ..." (solo para colgar la
        // contraseña): no cuenta como acceso real, por eso se excluye explicitamente.
        if (preg_match('/^GRANT\s+(.+?)\s+ON\s+\*\.\*/i', $line, $m)) {
            if (strcasecmp(trim($m[1]), 'USAGE') !== 0) $all = true;
        } elseif (preg_match('/^GRANT\s+.+?\s+ON\s+`([^`]+)`\.\*/i', $line, $m)) {
            $dbs[] = $m[1];
        }
    }
    return ['all'=>$all, 'dbs'=>array_values(array_unique($dbs))];
}

