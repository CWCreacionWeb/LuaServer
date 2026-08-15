<?php
// ---------------- AJAX: revelar el contenido de una nota bloqueada ----------------
// La ficha de proyecto NUNCA imprime el titulo/cuerpo real de una nota bloqueada en el HTML
// (si no, "ver codigo fuente" se lo saltaria entero durante una captura de pantalla, que es
// justo la amenaza que este candado quiere cubrir). El contenido solo sale de aqui, tras
// verificar la contraseña, y solo para ESTA carga de pagina: no se persiste "visto" en
// ningun sitio, así que recargar la ficha vuelve a pedirla.
if (($_GET['ajax'] ?? '') === 'note_reveal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $nCfg  = read_json($CFG_FILE) ?: ['sites'=>[]];
    $nName = (string)($_POST['name'] ?? '');
    $nKey  = resolve_site_key($nCfg['sites'] ?? [], $nName);
    if ($nKey === null) { echo json_encode(['ok'=>false,'error'=>'Proyecto no válido.']); exit; }
    $notes = notes_read($ROOT, $nKey);
    $i = notes_index_of($notes, (string)($_POST['id'] ?? ''));
    if ($i === null) { echo json_encode(['ok'=>false,'error'=>'La nota ya no existe.']); exit; }
    $n = $notes[$i];
    if (!empty($n['locked']) && !notes_check_pass($n, (string)($_POST['password'] ?? ''))) {
        echo json_encode(['ok'=>false,'error'=>'Contraseña incorrecta.']); exit;
    }
    echo json_encode(['ok'=>true, 'title'=>$n['title'], 'body'=>$n['body']]);
    exit;
}
