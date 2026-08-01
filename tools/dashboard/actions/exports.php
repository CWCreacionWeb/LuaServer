<?php
    if ($action === 'export_project') {
        $name = $_POST['name'] ?? '';
        $tab = 'proyecto'; $redirName = $name;
        $siteKey = resolve_site_key($cfg['sites'], $name);
        $db = trim((string)($_POST['db'] ?? ''));
        // "motor:base" en un solo campo para que el desplegable pueda ofrecer MySQL y
        // PostgreSQL a la vez sin un segundo control que mantener sincronizado.
        [$engine, $dbname] = $db !== '' ? array_pad(explode(':', $db, 2), 2, '') : ['', ''];
        if ($siteKey === null) { $msg='error:Proyecto no válido.'; }
        elseif ($db !== '' && !in_array($engine, ['mysql','pgsql'], true)) { $msg='error:Motor de base de datos no válido.'; }
        elseif ($engine === 'mysql' && !valid_dbname($dbname)) { $msg='error:Nombre de base de datos MySQL no válido.'; }
        elseif ($engine === 'pgsql' && !valid_pg_ident($dbname)) { $msg='error:Nombre de base de datos PostgreSQL no válido.'; }
        else {
            $name = $siteKey; $redirName = $name;
            $dir = project_dir($WWW, $cfg['sites'][$name], $name);
            if (!is_dir($dir)) { $msg='error:No se encontró la carpeta del proyecto.'; }
            elseif (!watcher_alive($ROOT)) {
                $msg='error:El watcher no está activo: no se puede generar el export hasta que lo arranques con .\lua.ps1 start.';
            } else {
                $file = export_slug($name).'-'.date('Y-m-d_His').'.zip';
                $id = 'export-'.$name.'-'.time();
                $job = [
                    'id'=>$id, 'name'=>$name, 'php'=>($cfg['defaultPhp']??'8.4'), 'type'=>'export_project', 'url'=>'',
                    'dir'=>$dir, 'out'=>exports_dir($ROOT).'/'.$file,
                    'exclude'=>trim((string)($_POST['exclude'] ?? '')),
                    'dbname'=>$dbname, 'dbengine'=>$engine,
                ];
                @mkdir($ROOT.'/tmp/jobs', 0777, true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Exportando "'.$name.'"'.($dbname!==''?' con la base de datos "'.$dbname.'"':'').'… mira el progreso abajo.';
            }
        }
    }
    elseif ($action === 'export_delete') {
        $name = $_POST['name'] ?? '';
        $tab = 'proyecto'; $redirName = $name;
        $file = (string)($_POST['file'] ?? '');
        if (!valid_export_file($file)) { $msg='error:Nombre de export no válido.'; }
        else {
            @unlink(exports_dir($ROOT).'/'.$file);
            $msg='info:Export "'.$file.'" eliminado.';
        }
    }
