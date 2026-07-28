<?php
    if ($action === 'git_connect') {
        $name = $_POST['name'] ?? '';
        $tab = 'proyecto'; $redirName = $name;
        $url = trim($_POST['url'] ?? '');
        $siteKey = resolve_site_key($cfg['sites'], $name);
        if ($siteKey === null) { $msg = 'error:Proyecto no válido.'; }
        elseif (!preg_match('#^(https?://|git@)#', $url)) { $msg = 'error:Introduce una URL de Git válida.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $dir = project_dir($WWW, $cfg['sites'][$name], $name);
            if (!is_dir($dir)) { $msg = 'error:No se encontró la carpeta del proyecto.'; }
            else {
                $ok = true; $steps = [];
                if (!is_dir($dir.'/.git')) {
                    [$s,,$e] = git_exec_verbose($dir, 'init');
                    if (!$s) { $ok=false; $steps[]='git init: '.($e?:'fallo'); }
                }
                // Sin al menos un commit no hay HEAD, y la ficha lo seguiria mostrando como
                // "no es un repositorio Git": se hace un commit inicial con identidad propia
                // por-llamada (-c), sin depender de que la cuenta SYSTEM tenga configurado
                // user.name/user.email (git commit fallaria con "Please tell me who you are").
                if ($ok && trim((string)git_exec($dir, 'rev-parse HEAD')) === '') {
                    git_exec_verbose($dir, 'add -A');
                    [$s,,$e] = git_exec_verbose($dir, '-c user.name=lua-server -c user.email=dev@localhost commit -m "Commit inicial"');
                    if (!$s && stripos($e,'nothing to commit')===false) { $ok=false; $steps[]='commit inicial: '.($e?:'fallo'); }
                }
                if ($ok) {
                    [$s,,$eAdd] = git_exec_verbose($dir, 'remote add origin '.escapeshellarg($url));
                    if (!$s) {
                        [$s,,$eSet] = git_exec_verbose($dir, 'remote set-url origin '.escapeshellarg($url));
                        if (!$s) { $ok=false; $steps[]='remote: '.($eSet?:$eAdd?:'fallo'); }
                    }
                }
                $msg = $ok
                    ? 'applied:Repositorio Git conectado a '.$url.'.'
                    : 'error:No se pudo conectar el repositorio: '.implode(' / ', $steps);
            }
        }
    }
    elseif ($action === 'ftp_save') {
        $name = $_POST['name'] ?? '';
        $tab = 'proyecto'; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        if ($siteKey === null) { $msg = 'error:Proyecto no válido.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $existing = ftp_config_get($ROOT, $name) ?: [];
            $newPass = (string)($_POST['ftp_pass'] ?? '');
            $port = (int)($_POST['ftp_port'] ?? 21); if ($port <= 0) { $port = 21; }
            $conf = [
                'host'    => trim((string)($_POST['ftp_host'] ?? '')),
                'port'    => $port,
                'user'    => trim((string)($_POST['ftp_user'] ?? '')),
                'pass'    => $newPass !== '' ? $newPass : ($existing['pass'] ?? ''),
                'path'    => trim((string)($_POST['ftp_path'] ?? '/')) ?: '/',
                'ssl'     => ($_POST['ftp_ssl'] ?? '') === '1',
                'exclude' => trim((string)($_POST['ftp_exclude'] ?? '.git, node_modules, .idea')),
            ];
            @mkdir($ROOT.'/config/ftp', 0777, true);
            file_put_contents(ftp_config_path($ROOT,$name), json_encode($conf, JSON_PRETTY_PRINT));
            $msg = 'applied:Configuración FTP guardada.';
        }
    }
    elseif ($action === 'ftp_deploy') {
        $name = $_POST['name'] ?? '';
        $tab = 'proyecto'; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        $conf = $siteKey !== null ? ftp_config_get($ROOT, $siteKey) : null;
        if ($siteKey === null) { $msg = 'error:Proyecto no válido.'; }
        elseif (!$conf || $conf['host'] === '') { $msg = 'error:Configura primero el host/usuario FTP.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $id = 'ftp-'.$name.'-'.time();
            $job = [
                'id'=>$id, 'name'=>$name, 'php'=>($cfg['defaultPhp']??'8.4'), 'type'=>'ftp_deploy', 'url'=>'',
                'ftpHost'=>$conf['host'], 'ftpPort'=>$conf['port'] ?? 21, 'ftpUser'=>$conf['user'] ?? '',
                'ftpPass'=>$conf['pass'] ?? '', 'ftpPath'=>$conf['path'] ?? '/', 'ftpSsl'=>!empty($conf['ssl']),
                'ftpExclude'=>$conf['exclude'] ?? '',
            ];
            @mkdir($ROOT.'/tmp/jobs', 0777, true);
            file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
            $msg = 'job:Desplegando "'.$name.'" por FTP… mira el progreso abajo.';
        }
    }
