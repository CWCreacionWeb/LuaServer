<?php
// ---------------- Exportar base de datos MySQL: ?export_db=<nombre> ----------------
if (isset($_GET['export_db'])) {
    $db = (string)$_GET['export_db'];
    $dumpExe = $ROOT.'/bin/mariadb/bin/mariadb-dump.exe';
    if (!valid_dbname($db)) { http_response_code(400); exit('Nombre de base de datos no válido.'); }
    if (!is_file($dumpExe)) { http_response_code(503); exit('MariaDB no está instalado.'); }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$db.'-'.date('Y-m-d_His').'.sql"');
    $rootPass = mysql_root_pass($ROOT);
    $passArg = $rootPass !== '' ? ' --password='.escapeshellarg($rootPass) : '';
    $cmd = '"'.$dumpExe.'" --host=127.0.0.1 --port=3306 --user=root'.$passArg.' --single-transaction --routines --events '.escapeshellarg($db);
    passthru($cmd);
    exit;
}

// ---------------- Descargar un export de proyecto ya generado: ?export_zip=<archivo> ----------------
if (isset($_GET['export_zip'])) {
    $zipName = (string)$_GET['export_zip'];
    if (!valid_export_file($zipName)) { http_response_code(400); exit('Nombre de export no válido.'); }
    $zipPath = exports_dir($ROOT).'/'.$zipName;
    if (!is_file($zipPath)) { http_response_code(404); exit('Ese export ya no existe.'); }
    header('Content-Type: application/zip');
    header('Content-Length: '.filesize($zipPath));
    header('Content-Disposition: attachment; filename="'.$zipName.'"');
    while (ob_get_level()) { ob_end_clean(); }
    readfile($zipPath);
    exit;
}

// ---------------- Exportar base de datos PostgreSQL: ?export_pg=<nombre> ----------------
if (isset($_GET['export_pg'])) {
    $db = (string)$_GET['export_pg'];
    $dumpExe = $ROOT.'/bin/postgres/bin/pg_dump.exe';
    if (!valid_pg_ident($db)) { http_response_code(400); exit('Nombre de base de datos no válido.'); }
    if (!is_file($dumpExe)) { http_response_code(503); exit('PostgreSQL no está instalado.'); }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$db.'-'.date('Y-m-d_His').'.sql"');
    $pass = pgsrv_pass($ROOT);
    if ($pass !== '') { putenv('PGPASSWORD='.$pass); }
    $cmd = '"'.$dumpExe.'" --host=127.0.0.1 --port=5432 --username=postgres --no-password '.escapeshellarg($db);
    passthru($cmd);
    exit;
}

