<?php
    if ($action === 'logs_delete_bulk') {
        $tab = 'logs';
        $names = $_POST['logs'] ?? [];
        if (!is_array($names)) { $names = []; }
        $deleted = 0; $skipped = 0;
        foreach ($names as $n) {
            $lf = safe_logname((string)$n);
            if ($lf === '' || !is_file($ROOT.'/logs/apache/'.$lf)) { $skipped++; continue; }
            if (@unlink($ROOT.'/logs/apache/'.$lf)) { $deleted++; } else { $skipped++; }
        }
        if ($deleted === 0 && $skipped === 0) { $msg = 'error:No se seleccionó ningún archivo.'; }
        elseif ($deleted === 0) { $msg = 'error:No se pudo eliminar ningún archivo (¿permisos, o el servicio los tiene abiertos?).'; }
        else {
            $msg = 'applied:'.$deleted.' archivo(s) de log eliminado(s).';
            if ($skipped) { $msg .= ' ('.$skipped.' no se pudieron eliminar.)'; }
        }
    }
