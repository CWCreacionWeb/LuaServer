<?php
    if ($action === 'update_cfg') {
        $tab='config';
        $auto  = ($_POST['auto'] ?? '') === '1';
        $horas = max(1, min(168, (int)($_POST['cada_horas'] ?? 6)));
        @mkdir($ROOT.'/config', 0777, true);
        @file_put_contents($ROOT.'/config/update.json', json_encode(['auto'=>$auto, 'cada_horas'=>$horas]));
        $msg = 'applied:Actualizaciones automáticas '.($auto?'activadas':'desactivadas').'. Comprobación cada '.$horas.' h.';
    }
    elseif ($action === 'update_check') {
        $tab='config';
        @mkdir($ROOT.'/tmp', 0777, true);
        @file_put_contents($ROOT.'/tmp/update-check.flag', '1');
        $msg = watcher_alive($ROOT)
            ? 'info:Buscando actualizaciones… se actualizará en unos segundos.'
            : 'error:El watcher no está activo: no se puede consultar el repositorio. Arráncalo con .\lua.ps1 start';
    }
    elseif ($action === 'update_now') {
        $tab='config';
        @mkdir($ROOT.'/tmp', 0777, true);
        @file_put_contents($ROOT.'/tmp/update-now.flag', '1');
        $msg = watcher_alive($ROOT)
            ? 'info:Actualizando… Apache se reiniciará solo al terminar.'
            : 'error:El watcher no está activo: no se puede actualizar. Arráncalo con .\lua.ps1 start';
    }
