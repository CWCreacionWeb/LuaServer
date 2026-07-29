<?php
// ---------------- AJAX: un nivel del arbol de archivos de un proyecto (carga perezosa) ----------------
if (($_GET['ajax'] ?? '') === 'tree') {
    header('Content-Type: application/json; charset=utf-8');
    $tName = (string)($_GET['name'] ?? '');
    $tRel  = (string)($_GET['rel'] ?? '');
    $tCfg   = read_json($CFG_FILE) ?: ['sites'=>[]];
    $tSites = $tCfg['sites'] ?? [];
    $tKey = resolve_site_key($tSites, $tName);
    if ($tKey === null) { echo json_encode(['error'=>'Proyecto no válido.']); exit; }
    $tName = $tKey;
    $tBase = realpath(project_dir($WWW, $tSites[$tName], $tName));
    if ($tBase === false) { echo json_encode(['error'=>'No se encontró el proyecto.']); exit; }
    $tTarget = realpath($tBase.'/'.$tRel);
    $tBaseN = rtrim(str_replace('\\','/',$tBase),'/');
    $tTargetN = $tTarget!==false ? rtrim(str_replace('\\','/',$tTarget),'/') : '';
    $tInside = $tTarget!==false && is_dir($tTarget) && ($tTargetN===$tBaseN || strpos($tTargetN, $tBaseN.'/')===0);
    if (!$tInside) { echo json_encode(['error'=>'Ruta no válida.']); exit; }
    $tCount = 0;
    echo json_encode(['html'=>tree_node_html($tTarget, $tRel, true, $tCount, 2000)]);
    exit;
}

// ---------------- AJAX: leer un archivo del proyecto para el editor de codigo ----------------
if (($_GET['ajax'] ?? '') === 'file_read') {
    header('Content-Type: application/json; charset=utf-8');
    $fName = (string)($_GET['name'] ?? '');
    $fRel  = (string)($_GET['rel'] ?? '');
    $fCfg   = read_json($CFG_FILE) ?: ['sites'=>[]];
    $fSites = $fCfg['sites'] ?? [];
    $fKey = resolve_site_key($fSites, $fName);
    if ($fKey === null) { echo json_encode(['error'=>'Proyecto no válido.']); exit; }
    $fName = $fKey;
    $fBase = realpath(project_dir($WWW, $fSites[$fName], $fName));
    if ($fBase === false) { echo json_encode(['error'=>'No se encontró el proyecto.']); exit; }
    $fTarget = realpath($fBase.'/'.$fRel);
    $fBaseN = rtrim(str_replace('\\','/',$fBase),'/');
    $fTargetN = $fTarget!==false ? rtrim(str_replace('\\','/',$fTarget),'/') : '';
    $fInside = $fTarget!==false && is_file($fTarget) && ($fTargetN===$fBaseN || strpos($fTargetN, $fBaseN.'/')===0);
    if (!$fInside) { echo json_encode(['error'=>'Ruta no válida.']); exit; }
    if (filesize($fTarget) > 2*1024*1024) { echo json_encode(['error'=>'Archivo demasiado grande para editar aquí (>2 MB).']); exit; }
    $fData = @file_get_contents($fTarget);
    if ($fData === false) { echo json_encode(['error'=>'No se pudo leer el archivo.']); exit; }
    if (strpos($fData, "\0") !== false) { echo json_encode(['error'=>'Parece un archivo binario: no se puede editar como texto.']); exit; }
    // El editor trabaja en UTF-8. Si el archivo no es UTF-8 valido (proyectos PHP legacy en
    // Windows-1252/ISO-8859-1, tipicos en apps españolas antiguas), lo convertimos a UTF-8
    // para editar y recordamos la codificacion original en 'enc' para reescribirlo igual al
    // guardar (round-trip). Antes json_encode devolvia false ante bytes no-UTF8 -> cuerpo
    // vacio -> el editor mostraba un falso "error de red" y NINGUN archivo legacy se abria.
    $enc = 'UTF-8';
    if (!mb_check_encoding($fData, 'UTF-8')) {
        $fData = mb_convert_encoding($fData, 'UTF-8', 'Windows-1252');
        $enc = 'Windows-1252';
    }
    $payload = json_encode(['content'=>$fData, 'enc'=>$enc], JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false) { echo json_encode(['error'=>'No se pudo codificar el contenido del archivo.']); exit; }
    echo $payload;
    exit;
}

// ---------------- AJAX: guardar un archivo editado desde el arbol del proyecto ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'file_write') {
    header('Content-Type: application/json; charset=utf-8');
    $fName = (string)($_POST['name'] ?? '');
    $fRel  = (string)($_POST['rel'] ?? '');
    $fContent = (string)($_POST['content'] ?? '');
    $fEnc  = (string)($_POST['enc'] ?? 'UTF-8');
    $fCfg   = read_json($CFG_FILE) ?: ['sites'=>[]];
    $fSites = $fCfg['sites'] ?? [];
    $fKey = resolve_site_key($fSites, $fName);
    if ($fKey === null) { echo json_encode(['error'=>'Proyecto no válido.']); exit; }
    $fName = $fKey;
    $fBase = realpath(project_dir($WWW, $fSites[$fName], $fName));
    if ($fBase === false) { echo json_encode(['error'=>'No se encontró el proyecto.']); exit; }
    $fTarget = realpath($fBase.'/'.$fRel);
    $fBaseN = rtrim(str_replace('\\','/',$fBase),'/');
    $fTargetN = $fTarget!==false ? rtrim(str_replace('\\','/',$fTarget),'/') : '';
    $fInside = $fTarget!==false && is_file($fTarget) && ($fTargetN===$fBaseN || strpos($fTargetN, $fBaseN.'/')===0);
    if (!$fInside) { echo json_encode(['error'=>'Ruta no válida.']); exit; }
    if (strlen($fContent) > 2*1024*1024) { echo json_encode(['error'=>'Contenido demasiado grande (>2 MB).']); exit; }
    // Round-trip: si el archivo era Windows-1252 al abrirlo, se reescribe en Windows-1252
    // (no convertimos su codificacion en silencio, que podria romper una app legacy que
    // asume latin1). El editor devuelve el 'enc' que le dio file_read.
    $toWrite = (strtoupper($fEnc) === 'WINDOWS-1252') ? mb_convert_encoding($fContent, 'Windows-1252', 'UTF-8') : $fContent;
    if (@file_put_contents($fTarget, $toWrite) === false) { echo json_encode(['error'=>'No se pudo guardar el archivo (¿permisos?).']); exit; }
    echo json_encode(['ok'=>true]);
    exit;
}

