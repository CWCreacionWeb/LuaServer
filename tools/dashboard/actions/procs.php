<?php
    // Mismas puertas de seguridad que la Terminal (config\terminal.on): definir un proceso es
    // ejecutar un comando arbitrario, y con "Exponer en la red local" el panel puede estar
    // accesible desde otras maquinas. Ver/consultar no se bloquea; crear/tocar si.
    if (in_array($action, ['proc_save','proc_del','proc_toggle','proc_restart'], true) && !term_enabled($ROOT)) {
        $tab='procs';
        $msg='error:El supervisor ejecuta comandos: activa la Terminal en Configuración del servidor para poder gestionar procesos.';
    }
    elseif ($action === 'proc_save') {
        $tab='procs';
        $id      = trim($_POST['id'] ?? '');
        $project = trim($_POST['project'] ?? '');
        $label   = trim($_POST['label'] ?? '');
        $cmd     = trim($_POST['cmd'] ?? '');
        $php     = trim($_POST['php'] ?? '');
        $editing = $id !== '' && valid_proc_id($id);
        $sitesAll = $cfg['sites'] ?? [];
        if (!isset($sitesAll[$project]))          { $msg='error:Ese proyecto no está registrado.'; }
        elseif ($cmd === '')                      { $msg='error:Escribe el comando a mantener corriendo.'; }
        elseif (mb_strlen($cmd) > 300)            { $msg='error:Comando demasiado largo (máx. 300).'; }
        elseif (mb_strlen($label) > 40)           { $msg='error:Nombre demasiado largo (máx. 40).'; }
        elseif ($php !== '' && !preg_match('/^\d\.\d$/', $php)) { $msg='error:Versión de PHP no válida.'; }
        else {
            $list = procs_load($ROOT);
            $entry = null;
            if ($editing) { foreach ($list as $i => $p) { if (($p['id'] ?? '') === $id) { $entry = $i; break; } } }
            $def = [
                'id'      => $editing && $entry !== null ? $id : bin2hex(random_bytes(6)),
                'project' => $project,
                'label'   => $label !== '' ? $label : $cmd,
                'cmd'     => $cmd,
                'php'     => $php,
                // Nuevo proceso SIEMPRE parado: que arrancarlo sea un acto consciente, no un
                // efecto colateral de guardar el formulario.
                'enabled' => $editing && $entry !== null ? (bool)($list[$entry]['enabled'] ?? false) : false,
            ];
            if ($entry !== null) { $list[$entry] = $def; } else { $list[] = $def; }
            procs_save($ROOT, $list);
            // Editar un proceso corriendo debe aplicar el comando nuevo: se pide reinicio.
            if ($editing && $entry !== null && $def['enabled']) { @touch($ROOT.'/tmp/procs/'.$def['id'].'.restart'); }
            $msg='applied:Proceso "'.$def['label'].'" guardado.'.($editing?'':' Arráncalo cuando quieras con su botón.');
        }
    }
    elseif ($action === 'proc_del') {
        $tab='procs';
        $id = trim($_POST['id'] ?? '');
        if (!valid_proc_id($id)) { $msg='error:Proceso no válido.'; }
        else {
            // Basta quitarlo del json: el watcher mata como "huerfano" cualquier proceso vivo
            // cuyo id ya no exista en procs.json (ver el bloque del supervisor en Cmd-Watch).
            procs_save($ROOT, array_values(array_filter(procs_load($ROOT), function($p) use ($id){ return ($p['id'] ?? '') !== $id; })));
            $msg='applied:Proceso eliminado. Si estaba corriendo, el watcher lo detendrá en un momento.';
        }
    }
    elseif ($action === 'proc_toggle') {
        $tab='procs';
        $id = trim($_POST['id'] ?? '');
        $on = ($_POST['enable'] ?? '') === '1';
        $list = procs_load($ROOT); $found = false;
        foreach ($list as $i => $p) { if (($p['id'] ?? '') === $id) { $list[$i]['enabled'] = $on; $found = true; break; } }
        if (!$found) { $msg='error:Ese proceso ya no existe.'; }
        else {
            procs_save($ROOT, $list);
            if (!watcher_alive($ROOT)) { $msg='error:Guardado, pero el watcher no está activo: nadie va a '.($on?'arrancarlo':'pararlo').'. Arráncalo con .\lua.ps1 start'; }
            else { $msg='applied:'.($on ? 'Arrancando el proceso…' : 'Deteniendo el proceso…'); }
        }
    }
    elseif ($action === 'proc_restart') {
        $tab='procs';
        $id = trim($_POST['id'] ?? '');
        if (!valid_proc_id($id)) { $msg='error:Proceso no válido.'; }
        elseif (!watcher_alive($ROOT)) { $msg='error:El watcher no está activo: no se puede reiniciar. Arráncalo con .\lua.ps1 start'; }
        else {
            @mkdir($ROOT.'/tmp/procs', 0777, true);
            @touch($ROOT.'/tmp/procs/'.$id.'.restart');
            $msg='applied:Reiniciando el proceso…';
        }
    }
    // ---- Actualizaciones de la plataforma ----
    // El panel no puede hacer 'git fetch' (remoto SSH, y aqui corremos como SYSTEM): deja un
    // archivo-senal y el watcher lo recoge, igual que con HTTPS o la sincronizacion de hosts.
