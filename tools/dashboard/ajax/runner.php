<?php
// ---------------- Comandos personalizados del runner (globales, no por sesion) ----------------
if ($__ta==='run_preset_add' || $__ta==='run_preset_del') {
    header('Content-Type: application/json; charset=utf-8');
    $reply = function($o){ echo json_encode($o); exit; };
    if (!term_enabled($ROOT)) { $reply(['error'=>'La terminal está desactivada. Actívala en Configuración del servidor.']); }

    $cmd  = trim((string)($_POST['cmd'] ?? ''));
    $list = run_presets_load($ROOT);

    if ($__ta==='run_preset_add') {
        if ($cmd==='') { $reply(['error'=>'Comando vacío.']); }
        if (mb_strlen($cmd) > 200) { $reply(['error'=>'Comando demasiado largo (máx. 200).']); }
        if (!in_array($cmd, $list, true)) {
            if (count($list) >= 30) { $reply(['error'=>'Máximo 30 comandos guardados: elimina alguno antes de añadir otro.']); }
            $list[] = $cmd;
            write_json(run_presets_file($ROOT), $list);
        }
    } else {
        $list = array_values(array_diff($list, [$cmd]));
        write_json(run_presets_file($ROOT), $list);
    }
    $reply(['ok'=>true, 'presets'=>$list]);
}

