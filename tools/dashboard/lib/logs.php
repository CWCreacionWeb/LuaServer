<?php
function tail_file($f,$n=250){ if(!is_file($f)) return ''; $lines=@file($f,FILE_IGNORE_NEW_LINES); if($lines===false) return ''; return implode("\n",array_slice($lines,-$n)); }
// Resalta por severidad los logs de error de Apache/PHP (fatal/warning/deprecated/notice).
// Devuelve HTML ya escapado: no volver a pasar por e().
function highlight_error_log($text){
    if ($text === '') return '';
    $lines = explode("\n", $text);
    $out = [];
    foreach ($lines as $line) {
        if (preg_match('/\b(Fatal error|Parse error|Uncaught)\b/i', $line)) { $cls = 'log-fatal'; }
        elseif (preg_match('/\bWarning\b/i', $line)) { $cls = 'log-warning'; }
        elseif (preg_match('/\bDeprecated\b/i', $line)) { $cls = 'log-deprecated'; }
        elseif (preg_match('/\bNotice\b/i', $line)) { $cls = 'log-notice'; }
        else { $cls = 'log-info'; }
        $out[] = '<span class="'.$cls.'">'.e($line).'</span>';
    }
    return implode("\n", $out);
}
function safe_logname($n){ return preg_match('/^[a-z0-9._-]+\.log$/i',$n) ? $n : ''; }
// Agrupa los .log de logs/apache por proyecto, a partir del sufijo de nombre que pone
// vhost.tpl ("<proyecto>-error.log" / "-access.log" / "-ssl-error.log"). Lo que no encaja
// con ningun sufijo conocido (error.log, access.log, apply.log, watcher-error.log...) es
// un log de sistema/Apache, no de un proyecto -> agrupado bajo el pseudo-proyecto '(sistema)'.
// Deriva [proyecto, kind] del nombre de un .log a partir de su sufijo (ver vhost.tpl).
// Se usa tanto para agrupar el listado (logs_group_by_project) como para saber a que
// proyecto pertenecia un archivo ya borrado (ajax 'logdelete'), donde el fichero ya no
// existe y por tanto no se puede derivar el proyecto desde $logFiles.
function log_file_project($lf){
    $suffixes = ['-ssl-error.log','-ssl-access.log','-error.log','-access.log'];
    foreach ($suffixes as $suf) {
        if (strlen($lf) > strlen($suf) && substr($lf, -strlen($suf)) === $suf) {
            return [substr($lf, 0, -strlen($suf)), substr($suf, 1, -4)]; // "-error.log" -> "error"
        }
    }
    return ['(sistema)', preg_replace('/\.log$/', '', $lf)];
}
function logs_group_by_project($logFiles){
    $byProject = [];
    foreach ($logFiles as $lf) {
        [$proj, $kind] = log_file_project($lf);
        $byProject[$proj][] = ['file'=>$lf, 'kind'=>$kind];
    }
    uksort($byProject, function($a,$b){
        if ($a==='(sistema)') return 1; if ($b==='(sistema)') return -1;
        return strnatcasecmp($a,$b);
    });
    return $byProject;
}
function log_kind_label($k){
    $map = ['error'=>'Error','access'=>'Acceso','ssl-error'=>'Error (SSL)','ssl-access'=>'Acceso (SSL)'];
    return $map[$k] ?? $k;
}
function log_project_label($p){ return $p==='(sistema)' ? 'Sistema (Apache)' : $p; }
// Bloqueo de proyecto: existe si la raiz del proyecto contiene CUALQUIER archivo *.lua.
// El panel crea/quita el marcador .locked.lua, pero cualquier .lua puesto a mano
// tambien protege el proyecto contra el borrado.
define('LUA_LOCK_MARKER', '.locked.lua');
