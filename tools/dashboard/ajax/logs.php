<?php
// ---------------- AJAX: borrar/vaciar un log de la pestaña Logs (JSON, no PRG) ----------------
// Igual razon que el explorador de SQL Server (ver mas abajo): el desplegable de
// proyecto/archivo es demasiado interactivo para el patron PRG del resto del panel. Con
// PRG, tras borrar habia que "adivinar" en el redirect que proyecto/archivo tocaba mostrar
// despues -- y esa heuristica se quedaba corta en mas de un caso (p.ej. al borrar el ultimo
// .log de un proyecto), perdiendo la seleccion del usuario en cada recarga. Por AJAX, el
// propio cliente decide que mostrar despues sin depender de ninguna heuristica del servidor.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['ajax'] ?? '', ['logdelete','logclear'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $op = $_POST['ajax'];
    $lf = safe_logname($_POST['log'] ?? '');
    if ($lf === '' || !is_file($ROOT.'/logs/apache/'.$lf)) { echo json_encode(['error'=>'Ese log ya no existe.']); exit; }

    if ($op === 'logclear') {
        @file_put_contents($ROOT.'/logs/apache/'.$lf, '');
        echo json_encode(['ok'=>true]);
        exit;
    }

    // logdelete
    @unlink($ROOT.'/logs/apache/'.$lf);
    [$lProj] = log_file_project($lf);
    $lRemaining = [];
    foreach (glob($ROOT.'/logs/apache/*.log') as $f) { $lRemaining[] = basename($f); }
    $lProjFiles = logs_group_by_project($lRemaining)[$lProj] ?? [];
    $lNext = '';
    foreach ($lProjFiles as $f) { if ($f['kind']==='error') { $lNext = $f['file']; break; } }
    if ($lNext === '' && $lProjFiles) { $lNext = $lProjFiles[0]['file']; }
    echo json_encode([
        'ok'      => true,
        'files'   => array_map(function($f){ return ['file'=>$f['file'], 'label'=>log_kind_label($f['kind'])]; }, $lProjFiles),
        'next'    => $lNext,
        'content' => $lNext !== '' ? highlight_error_log(tail_file($ROOT.'/logs/apache/'.$lNext, 300)) : '',
    ]);
    exit;
}

