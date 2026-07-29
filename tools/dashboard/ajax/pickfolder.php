<?php
// ---------------- AJAX: dialogo nativo "Elegir carpeta" (via el watcher, ver mas abajo) ----------------
// El panel corre bajo el servicio de Apache (sesion 0, sin escritorio): no puede mostrar un
// FolderBrowserDialog el mismo. En vez de eso deja una peticion en tmp/pickfolder/ y el watcher
// (que si corre en la sesion interactiva del usuario) la recoge, muestra el dialogo nativo y
// escribe el resultado; este endpoint solo hace polling sobre ese resultado.
if (($_GET['ajax'] ?? '') === 'pickfolder_start') {
    header('Content-Type: application/json; charset=utf-8');
    if (!watcher_alive($ROOT)) { echo json_encode(['error'=>'El watcher no está activo: no se puede abrir el selector de carpetas.']); exit; }
    $pfDir = $ROOT.'/tmp/pickfolder';
    @mkdir($pfDir, 0777, true);
    $pfId = bin2hex(random_bytes(8));
    file_put_contents($pfDir.'/'.$pfId.'.req', '');
    echo json_encode(['id'=>$pfId]);
    exit;
}
if (($_GET['ajax'] ?? '') === 'pickfolder_poll') {
    header('Content-Type: application/json; charset=utf-8');
    $pfId = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{16}$/', $pfId)) { echo json_encode(['status'=>'error','msg'=>'id no válido']); exit; }
    $pfRes = $ROOT.'/tmp/pickfolder/'.$pfId.'.res';
    if (!is_file($pfRes)) { echo json_encode(['status'=>'pending']); exit; }
    $pfData = json_decode((string)@file_get_contents($pfRes), true);
    @unlink($pfRes);
    echo json_encode($pfData ?: ['status'=>'error','msg'=>'respuesta ilegible']);
    exit;
}

