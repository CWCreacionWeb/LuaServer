<?php
    if ($action === 'phpini') {
        $tab='php';
        $ver=$_POST['ver']??'';
        if ($vers && in_array($ver,$vers,true)) {
            $ini = $_POST['ini'] ?? [];
            $lines = ['; Ajustes editables desde el panel (http://localhost). Se aplican al final: ganan.'];
            foreach ($CURATED as $k=>$meta) {
                if (isset($ini[$k]) && trim($ini[$k])!=='') $lines[] = $k.' = '.trim($ini[$k]);
            }
            $extra = $_POST['extra'] ?? '';
            if (trim($extra)!=='') {
                $lines[]=''; $lines[]='; --- directivas adicionales ---';
                foreach (preg_split('/\r?\n/',$extra) as $el){ if(trim($el)!=='') $lines[]=rtrim($el); }
            }
            @mkdir($OVR_DIR,0777,true);
            file_put_contents("$OVR_DIR/$ver.overrides.ini", implode("\r\n",$lines)."\r\n");
            lua_apply();
            $msg='applied:php.ini de PHP '.$ver.' guardado.';
        } else { $msg='error:Versión no válida.'; }
    }
    elseif ($action === 'hosts') { $tab='config'; lua_hosts(); $msg='info:Sincronizando dominios: acepta el aviso de Windows (UAC).'; }
    elseif ($action === 'set_tld') {
        $tab = 'config';
        $new = strtolower(trim($_POST['tld'] ?? ''));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $new)) {
            $msg = 'error:Dominio no válido (letras, números, guiones y puntos).';
        } else {
            $cfg['tld'] = $new;
            write_json($CFG_FILE, $cfg);
            lua_apply();
            lua_hosts(); // cambia el dominio de TODOS los proyectos: hay que resincronizar hosts si o si
            $httpsOn = is_file($ROOT.'/config/https.on');
            if ($httpsOn) { @file_put_contents($ROOT.'/tmp/https.flag',(string)time()); }
            $msg = 'applied:Dominio cambiado a "'.$new.'". Sincronizando hosts (acepta el aviso de Windows/UAC que va a aparecer).'.($httpsOn?' El certificado HTTPS se está regenerando.':'');
        }
    }
    elseif ($action === 'https') {
        $tab='config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) { @file_put_contents($ROOT.'/tmp/https.flag',(string)time()); $msg='info:Activando HTTPS: acepta el aviso de Windows (UAC) para instalar la CA. Recarga en unos segundos.'; }
        else { @unlink($ROOT.'/config/https.on'); lua_apply(); $msg='applied:HTTPS desactivado.'; }
    }
