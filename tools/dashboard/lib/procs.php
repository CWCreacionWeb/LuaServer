<?php
// ---------------- Supervisor de procesos por proyecto ----------------
// El panel solo EDITA config\procs.json y deja flags; arrancar/vigilar/reiniciar lo hace el
// watcher (ver el bloque del supervisor en Cmd-Watch de lua.ps1). El estado en vivo se lee de
// tmp\procs\state.json, que el watcher reescribe solo cuando cambia.
function procs_file($root){ return $root.'/config/procs.json'; }
function procs_load($root){
    if (!is_file(procs_file($root))) return [];
    $d = json_decode((string)@file_get_contents(procs_file($root)), true);
    return is_array($d) ? $d : [];
}
function procs_save($root, $list){
    @file_put_contents(procs_file($root), json_encode(array_values($list), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function valid_proc_id($n){ return (bool)preg_match('/^[a-f0-9]{12}$/', (string)$n); }
function procs_state($root){
    $d = json_decode((string)@file_get_contents($root.'/tmp/procs/state.json'), true);
    return is_array($d) ? $d : [];
}

