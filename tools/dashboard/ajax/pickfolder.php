<?php
// ---------------- AJAX: selector de carpeta propio (arbol de directorios via PHP) ----------------
// Antes esto pedia un dialogo nativo de Windows al watcher (unico proceso que, en teoria, corre
// en la sesion interactiva del usuario -- el panel corre bajo el servicio de Apache, sesion 0,
// sin escritorio). Pero con "Arrancar con Windows" activo el watcher pasa a ser una tarea
// programada de SYSTEM (ver CLAUDE.md, trampa nº1) que TAMBIEN corre en sesion 0: ShowDialog()
// fallaba igual ahi, con "la aplicación no está en modo UserInteractive". No hay ninguna sesion
// interactiva de la que tirar en ese caso -- se sustituye el dialogo nativo por un explorador de
// carpetas propio servido por PHP, que no necesita escritorio ni watcher, solo listar carpetas.
if (($_GET['ajax'] ?? '') === 'browsedir') {
    header('Content-Type: application/json; charset=utf-8');
    $bPath = trim((string)($_GET['path'] ?? ''));
    if ($bPath === '') {
        // Sin ruta de partida: se listan las unidades del sistema, como raiz del arbol.
        $drives = [];
        foreach (range('A','Z') as $letter) {
            $d = $letter.':\\';
            if (@is_dir($d)) $drives[] = $d;
        }
        echo json_encode(['path'=>'', 'parent'=>null, 'dirs'=>$drives]);
        exit;
    }
    $real = @realpath($bPath);
    if ($real === false || !is_dir($real)) { echo json_encode(['error'=>'Esa carpeta no existe.']); exit; }
    $entries = @scandir($real);
    if ($entries === false) { echo json_encode(['error'=>'No se pudo leer esa carpeta (¿permisos?).']); exit; }
    $dirs = [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        if (@is_dir($real.DIRECTORY_SEPARATOR.$e)) $dirs[] = $e;
    }
    sort($dirs, SORT_STRING | SORT_FLAG_CASE);
    // dirname() de una raiz de unidad ("C:\") se devuelve a si misma en PHP -- ahi ya no se
    // puede "subir" mas, se vuelve al listado de unidades (parent = null).
    $parent = strlen(rtrim($real, '\\/')) <= 2 ? null : dirname($real);
    echo json_encode(['path'=>$real, 'parent'=>$parent, 'dirs'=>$dirs]);
    exit;
}
