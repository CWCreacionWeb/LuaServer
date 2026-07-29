<?php
    if ($action === 'sqlsrv_save') {
        $tab='sqlsrv';
        $id    = trim($_POST['id'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $host  = trim($_POST['host'] ?? '');
        $port  = (int)($_POST['port'] ?? 1433);
        $user  = trim($_POST['user'] ?? '');
        $pass  = (string)($_POST['pass'] ?? '');
        $trust = ($_POST['trust'] ?? '') === '1';
        $editing = $id !== '' && valid_sqlsrv_id($id) && sqlsrv_find($ROOT, $id) !== null;
        if ($host === '')                       { $msg='error:Indica el host o la IP del servidor.'; }
        elseif ($port < 1 || $port > 65535)     { $msg='error:Puerto no válido.'; }
        elseif ($user === '')                   { $msg='error:Indica el usuario.'; }
        else {
            $list = sqlsrv_servers($ROOT);
            // Al editar sin tocar el campo de contraseña se conserva la que ya habia: el
            // formulario nunca reenvia la contraseña guardada al navegador.
            if ($editing && $pass === '') {
                $prev = sqlsrv_find($ROOT, $id);
                $pass = (string)($prev['pass'] ?? '');
            }
            $entry = [
                'id'    => $editing ? $id : bin2hex(random_bytes(6)),
                'label' => $label !== '' ? $label : $host,
                'host'  => $host, 'port' => $port, 'user' => $user, 'pass' => $pass, 'trust' => $trust,
            ];
            [$ok, $info] = sqlsrv_test($entry);
            if ($editing) {
                foreach ($list as $i => $s) { if (($s['id'] ?? '') === $id) { $list[$i] = $entry; break; } }
            } else { $list[] = $entry; }
            sqlsrv_save_servers($ROOT, $list);
            $verbo = $editing ? 'actualizada' : 'guardada';
            $msg = $ok
                ? 'applied:Conexión "'.$entry['label'].'" '.$verbo.'. '.$info
                : 'info:Conexión "'.$entry['label'].'" '.$verbo.', pero NO se pudo conectar: '.$info;
        }
    }
    elseif ($action === 'sqlsrv_test') {
        $tab='sqlsrv';
        $id = trim($_POST['id'] ?? '');
        $srv = valid_sqlsrv_id($id) ? sqlsrv_find($ROOT, $id) : null;
        if (!$srv) { $msg='error:Esa conexión ya no existe.'; }
        else {
            [$ok, $info] = sqlsrv_test($srv);
            $msg = $ok ? 'applied:Conexión correcta. '.$info : 'error:No se pudo conectar: '.$info;
        }
    }
    elseif ($action === 'sqlsrv_del') {
        $tab='sqlsrv';
        $id = trim($_POST['id'] ?? '');
        if (!valid_sqlsrv_id($id)) { $msg='error:Conexión no válida.'; }
        else {
            $list = array_values(array_filter(sqlsrv_servers($ROOT), function($s) use ($id){ return ($s['id'] ?? '') !== $id; }));
            sqlsrv_save_servers($ROOT, $list);
            $msg='applied:Conexión eliminada.';
        }
    }
    // ---- Conexiones de Redis (mismo modelo que las de SQL Server) ----
    elseif ($action === 'redis_save') {
        $tab='redis';
        $id    = trim($_POST['id'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $host  = trim($_POST['host'] ?? '');
        $port  = (int)($_POST['port'] ?? 6379);
        $user  = trim($_POST['user'] ?? '');
        $pass  = (string)($_POST['pass'] ?? '');
        $editing = $id !== '' && valid_redis_id($id) && redis_find($ROOT, $id) !== null;
        if ($host === '')                   { $msg='error:Indica el host o la IP del servidor.'; }
        elseif ($port < 1 || $port > 65535) { $msg='error:Puerto no válido.'; }
        else {
            $list = redis_servers($ROOT);
            // Igual que en SQL Server: si al editar no se escribe contraseña, se conserva la
            // guardada (el formulario nunca la reenvia al navegador).
            if ($editing && $pass === '') {
                $prev = redis_find($ROOT, $id);
                $pass = (string)($prev['pass'] ?? '');
            }
            $entry = [
                'id'    => $editing ? $id : bin2hex(random_bytes(6)),
                'label' => $label !== '' ? $label : $host.':'.$port,
                'host'  => $host, 'port' => $port, 'user' => $user, 'pass' => $pass,
            ];
            // Se prueba la conexion al guardar para avisar en el momento, pero se guarda igual:
            // el servidor puede estar apagado ahora y arrancar despues.
            $okC = false; $infoC = '';
            try {
                $tfp = redis_connect($entry, 0);
                $ti = redis_parse_info(redis_cmd($tfp, ['INFO','server']));
                @fclose($tfp);
                $okC = true; $infoC = 'Redis '.($ti['redis_version'] ?? '?').' ('.($ti['redis_mode'] ?? 'standalone').').';
            } catch (Throwable $e) { $infoC = $e->getMessage(); }
            if ($editing) {
                foreach ($list as $i => $s) { if (($s['id'] ?? '') === $id) { $list[$i] = $entry; break; } }
            } else { $list[] = $entry; }
            redis_save_servers($ROOT, $list);
            $verbo = $editing ? 'actualizada' : 'guardada';
            $msg = $okC
                ? 'applied:Conexión "'.$entry['label'].'" '.$verbo.'. '.$infoC
                : 'info:Conexión "'.$entry['label'].'" '.$verbo.', pero NO se pudo conectar: '.$infoC;
        }
    }
    elseif ($action === 'redis_del') {
        $tab='redis';
        $id = trim($_POST['id'] ?? '');
        if (!valid_redis_id($id)) { $msg='error:Conexión no válida.'; }
        else {
            $list = array_values(array_filter(redis_servers($ROOT), function($s) use ($id){ return ($s['id'] ?? '') !== $id; }));
            redis_save_servers($ROOT, $list);
            $msg='applied:Conexión eliminada.';
        }
    }
