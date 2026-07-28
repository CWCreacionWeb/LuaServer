<?php
    if ($action === 'cover') {
        $name=$_POST['name']??'';
        $siteKey = resolve_site_key($cfg['sites'], $name);
        if ($siteKey === null) { $msg='error:No existe ese proyecto.'; }
        elseif (empty($_FILES['img']) || ($_FILES['img']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) {
            $msg='error:No se recibió la imagen (¿demasiado grande? máx. según php.ini).';
        } else {
            $name = $siteKey;
            $tmp=$_FILES['img']['tmp_name']; $size=$_FILES['img']['size'];
            $orig=strtolower($_FILES['img']['name']);
            $ext=pathinfo($orig, PATHINFO_EXTENSION); if($ext==='jpeg')$ext='jpg';
            $okImg=false; $info=@getimagesize($tmp);
            if ($info!==false) $okImg=true;
            elseif ($ext==='svg') { $head=@file_get_contents($tmp,false,null,0,512); if($head!==false && stripos($head,'<svg')!==false) $okImg=true; }
            if ($size > 5*1024*1024) { $msg='error:La imagen supera 5 MB.'; }
            elseif (!in_array($ext, cover_exts(), true) || !$okImg) { $msg='error:Formato no válido. Usa JPG, PNG, WEBP, GIF o SVG.'; }
            else {
                $dir=$ROOT.'/data/covers'; @mkdir($dir,0777,true);
                foreach (cover_exts() as $e) @unlink($dir.'/'.$name.'.'.$e); // quitar la anterior
                if (@move_uploaded_file($tmp, $dir.'/'.$name.'.'.$ext)) { $msg='applied:Carátula de "'.$name.'" actualizada.'; }
                else { $msg='error:No se pudo guardar la imagen.'; }
            }
        }
    }
    elseif ($action === 'cover_remove') {
        $name=$_POST['name']??'';
        $siteKey = resolve_site_key($cfg['sites'], $name);
        if ($siteKey !== null) {
            $name = $siteKey;
            foreach (cover_exts() as $e) @unlink($ROOT.'/data/covers/'.$name.'.'.$e);
            $msg='applied:Carátula de "'.$name.'" eliminada.';
        } else { $msg='error:No existe ese proyecto.'; }
    }
    elseif ($action === 'set_brand') {
        $tab='config';
        $bn = trim((string)($_POST['brand_name'] ?? ''));
        if (mb_strlen($bn) > 40) { $msg='error:El nombre es demasiado largo (máx. 40 caracteres).'; }
        else {
            if (!isset($cfg['brand']) || !is_array($cfg['brand'])) $cfg['brand'] = [];
            $cfg['brand']['name'] = $bn;   // vacio => vuelve a "lua-server"
            write_json($CFG_FILE, $cfg);
            pma_sync_brand_name($ROOT, $bn); // que la cabecera de phpMyAdmin no se quede con el nombre viejo
            $msg = $bn!=='' ? 'applied:Nombre de la plataforma cambiado a "'.$bn.'".' : 'applied:Nombre restablecido a "lua-server".';
        }
    }
    elseif ($action === 'brand_logo') {
        $tab='config';
        if (empty($_FILES['img']) || ($_FILES['img']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) {
            $msg='error:No se recibió la imagen (¿demasiado grande? máx. según php.ini).';
        } else {
            $tmp=$_FILES['img']['tmp_name']; $size=$_FILES['img']['size'];
            $orig=strtolower($_FILES['img']['name']);
            $ext=pathinfo($orig, PATHINFO_EXTENSION); if($ext==='jpeg')$ext='jpg';
            $okImg=false; $info=@getimagesize($tmp);
            if ($info!==false) $okImg=true;
            elseif ($ext==='svg') { $head=@file_get_contents($tmp,false,null,0,512); if($head!==false && stripos($head,'<svg')!==false) $okImg=true; }
            if ($size > 5*1024*1024) { $msg='error:La imagen supera 5 MB.'; }
            elseif (!in_array($ext, cover_exts(), true) || !$okImg) { $msg='error:Formato no válido. Usa JPG, PNG, WEBP, GIF o SVG.'; }
            else {
                $dir=$ROOT.'/data/brand'; @mkdir($dir,0777,true);
                foreach (cover_exts() as $e) @unlink($dir.'/logo.'.$e); // quitar el anterior
                if (@move_uploaded_file($tmp, $dir.'/logo.'.$ext)) { $msg='applied:Logo de la plataforma actualizado.'; }
                else { $msg='error:No se pudo guardar la imagen.'; }
            }
        }
    }
    elseif ($action === 'brand_logo_reset') {
        $tab='config';
        foreach (cover_exts() as $e) @unlink($ROOT.'/data/brand/logo.'.$e);
        $msg='applied:Logo restablecido al de por defecto.';
    }
    elseif ($action === 'lanexpose') {
        $tab='config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) { @file_put_contents($ROOT.'/tmp/lanexpose-on.flag',(string)time());  $msg='info:Abriendo el puerto en el Firewall de Windows: acepta el aviso (UAC). Recarga en unos segundos.'; }
        else         { @file_put_contents($ROOT.'/tmp/lanexpose-off.flag',(string)time()); $msg='info:Cerrando el puerto en el Firewall de Windows: acepta el aviso (UAC).'; }
    }
    elseif ($action === 'lock') {
        $name=$_POST['name']??'';
        $siteKey = resolve_site_key($cfg['sites'], $name);
        if ($siteKey !== null) { $name = $siteKey; }
        $pDir = $siteKey !== null ? project_dir($WWW, $cfg['sites'][$name], $name) : null;
        if ($pDir && is_dir($pDir)) {
            $marker = $pDir.'/'.LUA_LOCK_MARKER;
            @file_put_contents($marker, "; lua-server :: proyecto bloqueado\r\n; Mientras exista un archivo .lua en la raiz de este proyecto,\r\n; no se puede eliminar desde el panel (http://localhost).\r\n");
            if (is_file($marker)) { $msg='applied:Proyecto "'.$name.'" bloqueado. No se podrá eliminar mientras exista el archivo .lua.'; }
            else { $msg='error:No se pudo crear el archivo de bloqueo en '.$pDir.'.'; }
        } else { $msg='error:No existe ese proyecto.'; }
    }
    elseif ($action === 'unlock') {
        $name=$_POST['name']??'';
        $siteKey = resolve_site_key($cfg['sites'], $name);
        if ($siteKey !== null) { $name = $siteKey; }
        $pDir = $siteKey !== null ? project_dir($WWW, $cfg['sites'][$name], $name) : null;
        if ($pDir && is_dir($pDir)) {
            $marker = $pDir.'/'.LUA_LOCK_MARKER;
            if (is_file($marker)) @unlink($marker);
            if (project_locked($pDir)) { $msg='info:Quité el marcador, pero "'.$name.'" sigue bloqueado: hay otro archivo .lua en su carpeta.'; }
            else { $msg='applied:Proyecto "'.$name.'" desbloqueado. Ya se puede eliminar.'; }
        } else { $msg='error:No existe ese proyecto.'; }
    }
    elseif ($action === 'pin' || $action === 'unpin') {
        // Destacar/quitar de destacados: solo cambia sites.json (orden/visual), no toca
        // Apache/vhosts -> no hace falta lua_apply().
        $name = $_POST['name'] ?? '';
        $siteKey = resolve_site_key($cfg['sites'], $name);
        if ($siteKey === null) { $msg = 'error:No existe ese proyecto.'; }
        else {
            $name = $siteKey;
            if (!is_array($cfg['sites'][$name])) { $cfg['sites'][$name] = ['php'=>$cfg['sites'][$name]]; }
            if ($action === 'pin') { $cfg['sites'][$name]['pinned'] = true; }
            else { unset($cfg['sites'][$name]['pinned']); }
            write_json($CFG_FILE, $cfg);
            $msg = 'applied:"'.$name.'" '.($action==='pin'?'añadido a':'quitado de').' Destacados.';
        }
    }
