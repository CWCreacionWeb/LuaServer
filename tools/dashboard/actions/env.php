<?php
    if ($action === 'env_save') {
        $tab = 'proyecto'; $name = $_POST['name'] ?? ''; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        $dir  = $siteKey !== null ? project_dir($WWW, $cfg['sites'][$siteKey], $siteKey) : null;
        $data = $dir !== null ? env_read_lines($dir) : null;
        if ($siteKey === null) { $msg='error:Proyecto no válido.'; }
        elseif ($data === null) { $msg='error:No se encontró el archivo .env de este proyecto.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $vals = $_POST['val'] ?? [];
            $lines = $data['lines'];
            foreach ($lines as $i => $line) {
                if (!array_key_exists((string)$i, $vals)) continue;
                $m = env_match_kv($line);
                if (!$m) continue; // la fila ya no casa con KEY=VALOR (archivo tocado por fuera entretanto): se deja tal cual
                $newVal = str_replace(["\r","\n"], '', (string)$vals[$i]);
                $lines[$i] = $m['indent'].$m['key'].$m['eq'].$newVal;
            }
            $msg = env_write_lines($dir, $lines, $data['eol'], $data['trailing_nl'], $data['enc'])
                ? 'applied:Variables de entorno guardadas. Si el proyecto tiene la configuración cacheada (config:cache), regenérala para que se note el cambio.'
                : 'error:No se pudo guardar el archivo .env (¿permisos?).';
        }
    }
    elseif ($action === 'env_add') {
        $tab = 'proyecto'; $name = $_POST['name'] ?? ''; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        $key   = trim((string)($_POST['key'] ?? ''));
        $value = str_replace(["\r","\n"], '', (string)($_POST['value'] ?? ''));
        $dir  = $siteKey !== null ? project_dir($WWW, $cfg['sites'][$siteKey], $siteKey) : null;
        $data = $dir !== null ? env_read_lines($dir) : null;
        $dup = false;
        if ($data !== null) {
            foreach ($data['lines'] as $line) {
                $m = env_match_kv($line);
                if ($m && strcasecmp($m['key'], $key) === 0) { $dup = true; break; }
            }
        }
        if ($siteKey === null) { $msg='error:Proyecto no válido.'; }
        elseif (!valid_env_key($key)) { $msg='error:Nombre de variable no válido (letras, números y _, empezando por letra o _).'; }
        elseif ($data === null) { $msg='error:No se encontró el archivo .env de este proyecto.'; }
        elseif ($dup) { $msg='error:La variable "'.$key.'" ya existe.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $lines = $data['lines'];
            $lines[] = $key.'='.$value;
            $msg = env_write_lines($dir, $lines, $data['eol'], $data['trailing_nl'], $data['enc'])
                ? 'applied:Variable "'.$key.'" añadida.'
                : 'error:No se pudo guardar el archivo .env (¿permisos?).';
        }
    }
    elseif ($action === 'env_delete') {
        $tab = 'proyecto'; $name = $_POST['name'] ?? ''; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        $line = (int)($_POST['line'] ?? -1);
        $dir  = $siteKey !== null ? project_dir($WWW, $cfg['sites'][$siteKey], $siteKey) : null;
        $data = $dir !== null ? env_read_lines($dir) : null;
        if ($siteKey === null) { $msg='error:Proyecto no válido.'; }
        elseif ($data === null) { $msg='error:No se encontró el archivo .env de este proyecto.'; }
        elseif (!isset($data['lines'][$line]) || !env_match_kv($data['lines'][$line])) { $msg='error:Variable no encontrada (¿el archivo cambió entretanto?).'; }
        else {
            $name = $siteKey; $redirName = $name;
            $lines = $data['lines'];
            array_splice($lines, $line, 1);
            $msg = env_write_lines($dir, $lines, $data['eol'], $data['trailing_nl'], $data['enc'])
                ? 'applied:Variable eliminada.'
                : 'error:No se pudo guardar el archivo .env (¿permisos?).';
        }
    }
    elseif ($action === 'env_from_example') {
        $tab = 'proyecto'; $name = $_POST['name'] ?? ''; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        $dir = $siteKey !== null ? project_dir($WWW, $cfg['sites'][$siteKey], $siteKey) : null;
        if ($siteKey === null) { $msg='error:Proyecto no válido.'; }
        elseif ($dir === null || is_file(env_path($dir))) { $msg='error:Ya existe un .env en este proyecto.'; }
        elseif (!is_file(env_example_path($dir))) { $msg='error:No se encontró .env.example.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $msg = @copy(env_example_path($dir), env_path($dir))
                ? 'applied:.env creado a partir de .env.example.'
                : 'error:No se pudo copiar .env.example.';
        }
    }
