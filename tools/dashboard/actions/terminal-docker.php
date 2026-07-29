<?php
    if ($action === 'terminal') {
        $tab='config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) { @file_put_contents($ROOT.'/config/terminal.on','1'); $msg='applied:Terminal activada. Ejecuta comandos desde la pestaña Terminal.'; }
        else { @unlink($ROOT.'/config/terminal.on'); $msg='applied:Terminal desactivada.'; }
    }
    elseif ($action === 'docker_start_desktop') {
        $tab='docker';
        $exe = docker_desktop_exe();
        if ($exe === null) { $msg='error:No se encontró Docker Desktop instalado.'; }
        else {
            try { $sh = new COM('WScript.Shell'); $sh->Run('"'.$exe.'"', 1, false); $msg='info:Arrancando Docker Desktop… puede tardar un minuto en estar listo.'; }
            catch (Throwable $e) { $msg='error:No se pudo lanzar Docker Desktop: '.$e->getMessage(); }
        }
    }
    elseif ($action === 'docker_container') {
        $tab='docker';
        $op = $_POST['op'] ?? '';
        $id = trim($_POST['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{6,64}$/i', $id)) { $msg='error:Contenedor no válido.'; }
        elseif (!in_array($op, ['start','stop','restart','rm'], true)) { $msg='error:Acción no válida.'; }
        else {
            $r = docker_exec($op==='rm' ? ['rm','-f',$id] : [$op,$id]);
            if ($r===null) { $msg='error:Docker no está disponible.'; }
            elseif (!$r['ok']) { $msg='error:'.trim($r['err'] !== '' ? $r['err'] : $r['out']); }
            else { $msg='applied:Hecho.'; }
        }
    }
