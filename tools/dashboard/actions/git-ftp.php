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
    elseif ($action === 'git_pull') {
        // Mismo criterio conservador que la auto-actualizacion de la propia plataforma
        // (Update-Apply en lua.ps1): fetch + merge --ff-only, nunca fusiona de verdad ni
        // reescribe historia, y se niega de plano si hay cambios locales sin commitear.
        $name = $_POST['name'] ?? '';
        $tab = 'proyecto'; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        if ($siteKey === null) { $msg = 'error:Proyecto no válido.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $dir = project_dir($WWW, $cfg['sites'][$name], $name);
            if (!is_dir($dir.'/.git')) { $msg = 'error:Este proyecto no es un repositorio Git.'; }
            else {
                $statusRaw = git_exec($dir, 'status --porcelain');
                $remote = trim((string)git_exec($dir, 'remote get-url origin'));
                if ($statusRaw !== null && trim($statusRaw) !== '') {
                    $msg = 'error:Hay cambios sin commitear: commitéalos o descártalos antes de actualizar, para no perderlos ni generar conflictos.';
                } elseif ($remote === '') {
                    $msg = 'error:Este proyecto no tiene un remoto "origin" configurado.';
                } else {
                    [$sf,,$ef] = git_exec_verbose($dir, 'fetch --quiet origin');
                    if (!$sf) { $msg = 'error:No se pudo consultar el remoto: '.($ef ?: 'fallo desconocido').' (¿SSH sin claves cargadas para esta cuenta, o sin red?)'; }
                    else {
                        $upstream = trim((string)git_exec($dir, 'rev-parse --abbrev-ref @{u}'));
                        if ($upstream === '') { $msg = 'error:La rama actual no sigue a ninguna rama remota.'; }
                        else {
                            [$sm,,$em] = git_exec_verbose($dir, 'merge --ff-only '.escapeshellarg($upstream));
                            $msg = $sm
                                ? 'applied:"'.$name.'" actualizado desde '.$upstream.'.'
                                : 'error:No se pudo actualizar en avance rápido (probablemente tienes commits propios sin subir, o hay conflictos): '.($em ?: 'fallo desconocido');
                        }
                    }
                }
            }
        }
    }
    elseif ($action === 'git_commit_push') {
        $name = $_POST['name'] ?? '';
        $tab = 'proyecto'; $redirName = $name;
        $message = trim((string)($_POST['message'] ?? ''));
        $siteKey = resolve_site_key($cfg['sites'], $name);
        if ($siteKey === null) { $msg = 'error:Proyecto no válido.'; }
        elseif ($message === '') { $msg = 'error:Escribe un mensaje de commit.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $dir = project_dir($WWW, $cfg['sites'][$name], $name);
            if (!is_dir($dir.'/.git')) { $msg = 'error:Este proyecto no es un repositorio Git.'; }
            else {
                $statusRaw = git_exec($dir, 'status --porcelain');
                if ($statusRaw === null || trim($statusRaw) === '') { $msg = 'error:No hay cambios sin commitear.'; }
                else {
                    [$sa,,$ea] = git_exec_verbose($dir, 'add -A');
                    if (!$sa) { $msg = 'error:No se pudo preparar los cambios (git add): '.($ea ?: 'fallo desconocido'); }
                    else {
                        [$sc,,$ec] = git_exec_verbose($dir, 'commit -m '.escapeshellarg($message));
                        if (!$sc) { $msg = 'error:No se pudo commitear (revisa que git tenga nombre/email configurados en esta máquina): '.($ec ?: 'fallo desconocido'); }
                        else {
                            $remote = trim((string)git_exec($dir, 'remote get-url origin'));
                            $branch = trim((string)git_exec($dir, 'rev-parse --abbrev-ref HEAD'));
                            if ($remote === '') { $msg = 'applied:Commit hecho en local. Conecta un repositorio remoto para poder subirlo.'; }
                            else {
                                [$sp,,$ep] = git_exec_verbose($dir, 'push origin '.escapeshellarg($branch));
                                $msg = $sp
                                    ? 'applied:Commit hecho y subido a origin/'.$branch.'.'
                                    : 'error:Commit hecho, pero no se pudo hacer push: '.($ep ?: 'fallo desconocido').' (¿SSH sin claves cargadas para esta cuenta, o el remoto rechaza el push?)';
                            }
                        }
                    }
                }
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
