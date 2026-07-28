<?php
// ---------------- PostgreSQL (mismo patron que MySQL, via pdo_pgsql) ----------------
// Prefijo pgsrv_ para no chocar con las funciones nativas pg_* de la extension pgsql.
// Identificador Postgres: empieza por letra/_ y no lleva mas que letras/numeros/_.
function valid_pg_ident($n){ return (bool)preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/', (string)$n); }
function pgsrv_pass($root){ $f=$root.'/config/postgres_root.pass'; return is_file($f)?trim((string)@file_get_contents($f)):''; }
function pgsrv_pdo($db='postgres'){
    global $ROOT;
    if (!extension_loaded('pdo_pgsql')) { throw new RuntimeException('La extension pdo_pgsql no esta cargada.'); }
    $pass = pgsrv_pass($ROOT);
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname='.$db, 'postgres', $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>3]);
}
// null = no se pudo conectar; array de nombres de bases de datos de usuario.
function pgsrv_databases(){
    try {
        $pdo = pgsrv_pdo();
        $out = [];
        // se ocultan las plantillas y la BD de mantenimiento 'postgres' (equivale a los
        // esquemas de sistema que se ocultan en MySQL).
        $sql = "SELECT datname FROM pg_database WHERE datistemplate=false AND datname<>'postgres' ORDER BY datname";
        foreach ($pdo->query($sql) as $row) { $out[] = $row['datname']; }
        return $out;
    } catch (Throwable $e) { return null; }
}
// null = no se pudo conectar; array de ['name'=>..,'super'=>bool,'login'=>bool] (sin roles internos pg_*).
function pgsrv_roles(){
    try {
        $pdo = pgsrv_pdo();
        $out = [];
        $sql = "SELECT rolname, rolsuper, rolcanlogin FROM pg_roles WHERE rolname NOT LIKE 'pg\\_%' ORDER BY rolname";
        foreach ($pdo->query($sql) as $row) {
            $out[] = ['name'=>$row['rolname'], 'super'=>(bool)$row['rolsuper'], 'login'=>(bool)$row['rolcanlogin']];
        }
        return $out;
    } catch (Throwable $e) { return null; }
}

