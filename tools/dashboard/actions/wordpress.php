<?php
    if ($action === 'wp_debug_toggle') {
        $tab = 'proyecto'; $name = $_POST['name'] ?? ''; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        $key = (string)($_POST['key'] ?? '');
        $enable = ($_POST['enable'] ?? '') === '1';
        $dir = $siteKey !== null ? project_dir($WWW, $cfg['sites'][$siteKey], $siteKey) : null;
        $wpRoot = $dir !== null ? wp_root_dir($dir) : null;
        $cfgFile = $wpRoot !== null ? wp_config_file($wpRoot) : null;
        if ($siteKey === null) { $msg='error:Proyecto no válido.'; }
        elseif (!wp_valid_debug_key($key)) { $msg='error:Constante no válida.'; }
        elseif ($wpRoot === null || !is_file($cfgFile)) { $msg='error:No se encontró wp-config.php en este proyecto.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $content = @file_get_contents($cfgFile);
            if ($content === false) { $msg='error:No se pudo leer wp-config.php.'; }
            else {
                $cur = wp_config_get_bool($content, $key);
                if (!is_bool($cur) && $cur !== null) {
                    $msg='error:"'.$key.'" tiene un valor personalizado en wp-config.php: no se toca desde aquí.';
                } else {
                    $new = wp_config_set_bool($content, $key, $enable);
                    $msg = @file_put_contents($cfgFile, $new) !== false
                        ? 'applied:'.$key.' '.($enable?'activada':'desactivada').'.'
                        : 'error:No se pudo guardar wp-config.php (¿permisos?).';
                }
            }
        }
    }
    elseif ($action === 'wp_debug_log_clear') {
        $tab = 'proyecto'; $name = $_POST['name'] ?? ''; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        $dir = $siteKey !== null ? project_dir($WWW, $cfg['sites'][$siteKey], $siteKey) : null;
        $wpRoot = $dir !== null ? wp_root_dir($dir) : null;
        if ($siteKey === null) { $msg='error:Proyecto no válido.'; }
        elseif ($wpRoot === null) { $msg='error:No se encontró WordPress en este proyecto.'; }
        else {
            $name = $siteKey; $redirName = $name;
            @file_put_contents(wp_debug_log_path($wpRoot), '');
            $msg = 'info:debug.log de WordPress vaciado.';
        }
    }
