<?php
// ---------------- Exportar un proyecto entero (.zip con archivos + dump de su BD) ----------------
// El .zip lo genera el WATCHER (job 'export_project' en lua.ps1), nunca el panel: comprimir
// miles de archivos y volcar la base de datos se sale del tiempo de una peticion bajo
// mod_fcgid, y ademas mariadb-dump.exe/pg_dump.exe serian subprocesos lanzados desde PHP
// (justo lo que desaconseja la trampa nº5 de CLAUDE.md).
function exports_dir($root){ return $root.'/data/exports'; }
// Unica defensa entre "descargar/borrar un export" y un path traversal, asi que se exige el
// formato COMPLETO que generamos nosotros (<proyecto>-<fecha>_<hora>.zip), no solo basename().
function valid_export_file($f){ return (bool)preg_match('/^[A-Za-z0-9_.-]{1,64}-\d{4}-\d{2}-\d{2}_\d{6}\.zip$/', (string)$f); }
// Nombre de proyecto -> prefijo usable en el nombre del .zip (las claves antiguas de
// sites.json pueden traer mayusculas o puntos; cualquier otra cosa se sustituye).
function export_slug($name){ return substr(preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string)$name), 0, 40); }
// Exports existentes, del proyecto indicado o de todos, del mas reciente al mas antiguo.
function exports_list($root, $name = null){
    $prefix = $name !== null ? export_slug($name).'-' : null;
    $out = [];
    foreach (glob(exports_dir($root).'/*.zip') ?: [] as $f) {
        $b = basename($f);
        if (!valid_export_file($b)) continue;
        if ($prefix !== null && strpos($b, $prefix) !== 0) continue;
        $out[] = ['file'=>$b, 'size'=>(int)@filesize($f), 'time'=>(int)@filemtime($f)];
    }
    usort($out, function($a,$b){ return $b['time'] <=> $a['time']; });
    return $out;
}
function export_size_human($bytes){
    if ($bytes >= 1073741824) return round($bytes/1073741824, 1).' GB';
    if ($bytes >= 1048576)    return round($bytes/1048576, 1).' MB';
    if ($bytes >= 1024)       return round($bytes/1024).' KB';
    return $bytes.' B';
}
