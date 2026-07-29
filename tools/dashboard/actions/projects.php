<?php
    if ($action === 'create') {
        $name = strtolower(trim($_POST['name'] ?? ''));
        $php  = $_POST['php'] ?? ($cfg['defaultPhp'] ?? '8.4');
        $type = $_POST['type'] ?? 'blank';
        $url  = trim($_POST['url'] ?? '');
        $validTypes = ['blank','laravel','wordpress','symfony','slim','git'];
        if (!valid_name($name)) { $msg='error:Nombre no válido (usa minúsculas, números, - o _).'; }
        elseif (isset($cfg['sites'][$name]) || is_dir("$WWW/$name")) { $msg='error:Ya existe un proyecto o carpeta "'.$name.'".'; }
        elseif (!in_array($type,$validTypes,true)) { $msg='error:Tipo de proyecto no válido.'; }
        elseif ($type==='git' && !preg_match('#^(https?://|git@)#',$url)) { $msg='error:Introduce una URL de Git válida.'; }
        elseif ($vers && !in_array($php,$vers,true)) { $msg='error:Versión de PHP no instalada.'; }
        elseif ($type==='wordpress' && !is_file($ROOT.'/config/mariadb.on')) { $msg='error:Activa MariaDB en Configuración del servidor antes de crear un proyecto WordPress.'; }
        else {
            $withdb = ($_POST['withdb'] ?? '') === '1';
            $job = ['id'=>null,'name'=>$name,'php'=>$php,'type'=>$type,'url'=>$url,'withdb'=>$withdb];
            $ready = true;
            // WordPress: la "instalacion guiada" crea la BD y un usuario de MySQL propio (aislado
            // a esa BD, no root) con los valores EXACTOS del formulario -- no un nombre autogenerado
            // -- y lo hace ya, de forma sincrona (mismo patron que las acciones db_create/
            // mysql_user_create de la pestana Bases de datos), para que un fallo (BD/usuario ya
            // existente, contrasena no valida...) se vea al instante en vez de a mitad de un job en
            // segundo plano. El job en si (mas abajo) solo se encarga de lo lento: descargar
            // WordPress y, con wp-cli, escribir wp-config.php e instalar el sitio en esa BD ya lista.
            if ($type === 'wordpress') {
                $wpDb    = trim($_POST['wp_dbname'] ?? '');
                $wpUser  = trim($_POST['wp_dbuser'] ?? '');
                $wpPass  = (string)($_POST['wp_dbpass'] ?? '');
                $wpTitle = trim($_POST['wp_title'] ?? '');
                $wpAU    = trim($_POST['wp_adminuser'] ?? '');
                $wpAP    = (string)($_POST['wp_adminpass'] ?? '');
                $wpAE    = trim($_POST['wp_adminemail'] ?? '');
                $noQuotes = function($s){ return strpos($s,'"')===false && strpos($s,"\n")===false; };
                if (!valid_dbname($wpDb)) { $msg='error:Nombre de base de datos no válido (letras, números, _).'; $ready=false; }
                elseif (!valid_mysql_user($wpUser)) { $msg='error:Usuario de base de datos no válido (letras, números, _).'; $ready=false; }
                elseif ($wpPass==='' || !$noQuotes($wpPass)) { $msg='error:Contraseña de base de datos no válida (no puede ir vacía ni llevar comillas dobles).'; $ready=false; }
                elseif ($wpTitle==='' || !$noQuotes($wpTitle)) { $msg='error:Introduce un título de sitio válido (sin comillas dobles).'; $ready=false; }
                elseif (!preg_match('/^[\w.\-]{1,60}$/', $wpAU)) { $msg='error:Usuario admin no válido.'; $ready=false; }
                elseif ($wpAP==='' || !$noQuotes($wpAP)) { $msg='error:Contraseña de admin no válida (no puede ir vacía ni llevar comillas dobles).'; $ready=false; }
                elseif (!filter_var($wpAE, FILTER_VALIDATE_EMAIL)) { $msg='error:Introduce un email de admin válido.'; $ready=false; }
                else {
                    try {
                        $pdo = mysql_pdo();
                        $pdo->exec('CREATE DATABASE `'.$wpDb.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                        $pdo->exec("CREATE USER '".$wpUser."'@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($wpPass));
                        $pdo->exec("GRANT ALL PRIVILEGES ON `".$wpDb."`.* TO '".$wpUser."'@'127.0.0.1'");
                        $pdo->exec('FLUSH PRIVILEGES');
                        // Igual que mysql_user_create: se guarda para que el desplegable de
                        // conexiones de phpMyAdmin la ofrezca sin volver a teclearla.
                        mysql_user_save_password($ROOT, $wpUser, '127.0.0.1', $wpPass);
                        pma_sync_servers($ROOT);
                        $job['wpDbName']=$wpDb; $job['wpDbUser']=$wpUser; $job['wpDbPass']=$wpPass;
                        $job['wpTitle']=$wpTitle; $job['wpAdminUser']=$wpAU; $job['wpAdminPass']=$wpAP; $job['wpAdminEmail']=$wpAE;
                    } catch (Throwable $e) { $msg='error:No se pudo preparar la base de datos: '.$e->getMessage(); $ready=false; }
                }
            }
            if ($ready) {
                $id = $name.'-'.time();
                $job['id'] = $id;
                @mkdir($ROOT.'/tmp/jobs', 0777, true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $labels=['blank'=>'PHP en blanco','laravel'=>'Laravel','wordpress'=>'WordPress','symfony'=>'Symfony','slim'=>'Slim','git'=>'clon de Git'];
                $msg='job:Creando "'.$name.'" ('.$labels[$type].')… mira el progreso abajo.';
            }
        }
    }
    elseif ($action === 'add_external') {
        $name   = strtolower(trim($_POST['name'] ?? ''));
        $path   = trim($_POST['path'] ?? '');
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $php    = $_POST['php'] ?? ($cfg['defaultPhp'] ?? '8.4');
        $pathN  = rtrim(str_replace('\\','/',$path), '/');
        if (!valid_name($name)) { $msg='error:Nombre no válido (minúsculas, números, - o _).'; }
        elseif (isset($cfg['sites'][$name])) { $msg='error:Ya existe un proyecto "'.$name.'".'; }
        elseif ($path==='' || !is_dir($pathN)) { $msg='error:La ruta no existe o no es una carpeta: '.$path; }
        elseif ($domain!=='' && !valid_domain($domain)) { $msg='error:Dominio no válido (ej.: portal.ersm.test).'; }
        elseif ($vers && !in_array($php,$vers,true)) { $msg='error:Versión de PHP no instalada.'; }
        elseif (($clash = domain_in_use($cfg['sites'], ($domain!==''?$domain:$name.'.'.($cfg['tld']??'lua.test')), null, $cfg['tld']??'lua.test')) !== null) { $msg='error:Ese dominio ya lo usa el proyecto "'.$clash.'".'; }
        else {
            $entry = ['php'=>$php, 'path'=>$pathN];
            if ($domain!=='') $entry['domain']=$domain;
            $cfg['sites'][$name]=$entry; write_json($CFG_FILE,$cfg); lua_apply(); lua_hosts();
            lock_project_dir($pathN); // proyecto externo ya existente: bloqueado por defecto
            $dom = $domain!=='' ? $domain : $name.'.'.($cfg['tld']??'lua.test');
            $hasPublic = is_dir($pathN.'/public');
            $msg='applied:Proyecto externo "'.$name.'" registrado y bloqueado -> http://'.$dom.' [PHP '.$php.']'.($hasPublic?' (docroot: public/)':'').'. Sincronizando hosts (acepta el aviso de Windows/UAC).';
        }
    }
    elseif ($action === 'set_domain') {
        $name = $_POST['name'] ?? '';
        $tab = 'proyecto'; $redirName = $name;
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $tld = $cfg['tld'] ?? 'lua.test';
        $siteKey = resolve_site_key($cfg['sites'], $name);
        if ($siteKey === null) { $msg = 'error:Proyecto no válido.'; }
        elseif ($domain !== '' && !valid_domain($domain)) { $msg = 'error:Dominio no válido (ej.: portal.'.$tld.').'; }
        elseif ($domain !== '' && ($clash = domain_in_use($cfg['sites'], $domain, $siteKey, $tld)) !== null) { $msg = 'error:Ese dominio ya lo usa el proyecto "'.$clash.'".'; }
        else {
            $name = $siteKey; $redirName = $name;
            if (!is_array($cfg['sites'][$name])) { $cfg['sites'][$name] = ['php'=>$cfg['sites'][$name]]; }
            if ($domain === '') { unset($cfg['sites'][$name]['domain']); }
            else { $cfg['sites'][$name]['domain'] = $domain; }
            write_json($CFG_FILE, $cfg); lua_apply(); lua_hosts();
            $shownDomain = $domain !== '' ? $domain : $name.'.'.$tld;
            // El certificado HTTPS es un unico wildcard *.$tld, y un comodin cubre UNA sola
            // etiqueta: cubre x.$tld pero NO x.y.$tld. Solo marcamos "cubierto" un dominio
            // que sea exactamente una etiqueta bajo $tld (o vacio/= $tld). Antes bastaba con
            // que terminara en .$tld, marcando por error subdominios de 2+ etiquetas.
            $httpsOn = is_file($ROOT.'/config/https.on');
            $suffix = '.'.$tld;
            $endsTld = (strlen($domain) > strlen($suffix)) && (substr($domain, -strlen($suffix)) === $suffix);
            $label = $endsTld ? substr($domain, 0, -strlen($suffix)) : '';
            $coveredByWildcard = ($domain === '') || ($domain === $tld) || ($endsTld && $label !== '' && strpos($label, '.') === false);
            $warn = ($httpsOn && !$coveredByWildcard) ? ' Aviso: con HTTPS activo, este dominio no cuelga de ".'.$tld.'" así que el certificado no lo cubrirá (saldrá aviso de certificado no válido en el navegador).' : '';
            $msg = 'applied:Dominio de "'.$name.'" -> '.$shownDomain.'. Sincronizando hosts (acepta el aviso de Windows/UAC).'.$warn;
        }
    }
    elseif ($action === 'clearjobs') {
        foreach (glob($ROOT.'/tmp/jobs/*.status') as $f) @unlink($f);
        $msg='info:Historial de tareas limpiado.';
    }
    elseif ($action === 'xdebug') {
        $ver = $_POST['ver'] ?? '';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($vers && in_array($ver,$vers,true)) {
            $marker = $OVR_DIR.'/'.$ver.'.xdebug.on';
            $dll = $PHP_BASE.'/'.$ver.'/ext/php_xdebug.dll';
            if ($enable) {
                @mkdir($OVR_DIR,0777,true); @file_put_contents($marker,'1');
                if (is_file($dll)) { lua_apply(); $msg='applied:Xdebug activado en PHP '.$ver.'.'; }
                elseif (!empty($XDEBUG_URLS[$ver])) {
                    $id='xdebug-'.$ver.'-'.time();
                    $job=['id'=>$id,'name'=>'xdebug-'.$ver,'php'=>$ver,'type'=>'xdebug','url'=>$XDEBUG_URLS[$ver]];
                    @mkdir($ROOT.'/tmp/jobs',0777,true);
                    file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                    $msg='job:Descargando Xdebug para PHP '.$ver.'…';
                } else { @unlink($marker); $msg='error:No hay URL de Xdebug configurada para PHP '.$ver.'.'; }
            } else { @unlink($marker); lua_apply(); $msg='applied:Xdebug desactivado en PHP '.$ver.'.'; }
        } else { $msg='error:Versión no válida.'; }
        header('Location: ?tab=php&ver='.urlencode($ver).'&msg='.urlencode($msg)); exit;
    }
    elseif ($action === 'phpext_add') {
        $ver = $_POST['ver'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        if (!$vers || !in_array($ver,$vers,true)) { $msg='error:Versión no válida.'; }
        elseif (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) { $msg='error:Nombre de extensión no válido (minúsculas, números y guion bajo, empezando por letra).'; }
        else {
            $dest = $PHP_BASE.'/'.$ver.'/ext/php_'.$name.'.dll';
            $hasFile = !empty($_FILES['dll']) && ($_FILES['dll']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
            if ($hasFile) {
                $tmp = $_FILES['dll']['tmp_name']; $size = $_FILES['dll']['size'];
                $head = @file_get_contents($tmp, false, null, 0, 2);
                if ($size < 1024 || $size > 50*1024*1024) { $msg='error:Tamaño de archivo no válido (esperado un .dll).'; }
                elseif ($head !== 'MZ') { $msg='error:El archivo no parece un .dll de Windows (cabecera no válida).'; }
                else {
                    @mkdir(dirname($dest), 0777, true);
                    if (@move_uploaded_file($tmp, $dest)) {
                        $list = extra_extensions($ROOT); $list[] = $name; save_extra_extensions($ROOT, $list);
                        lua_apply();
                        $msg='applied:Extensión "'.$name.'" instalada para PHP '.$ver.'.';
                    } else { $msg='error:No se pudo guardar el .dll.'; }
                }
            } elseif ($url !== '') {
                $list = extra_extensions($ROOT); $list[] = $name; save_extra_extensions($ROOT, $list);
                $id='phpext-'.$name.'-'.$ver.'-'.time();
                $job=['id'=>$id,'name'=>'phpext-'.$name.'-'.$ver,'php'=>$ver,'type'=>'phpext','url'=>$url,'extName'=>$name];
                @mkdir($ROOT.'/tmp/jobs',0777,true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Descargando extensión "'.$name.'"…';
            } else {
                $msg='error:Sube un .dll o pega una URL directa.';
            }
        }
        header('Location: ?tab=php&ver='.urlencode($ver).'&msg='.urlencode($msg)); exit;
    }
    elseif ($action === 'phpext_remove') {
        $ver = $_POST['ver'] ?? '';
        $name = $_POST['name'] ?? '';
        if (!$vers || !in_array($ver,$vers,true)) { $msg='error:Versión no válida.'; }
        else {
            @unlink($PHP_BASE.'/'.$ver.'/ext/php_'.$name.'.dll');
            $stillUsed = false;
            foreach ($vers as $v2) { if (is_file($PHP_BASE.'/'.$v2.'/ext/php_'.$name.'.dll')) { $stillUsed = true; break; } }
            if (!$stillUsed) {
                $list = array_values(array_diff(extra_extensions($ROOT), [$name]));
                save_extra_extensions($ROOT, $list);
            }
            lua_apply();
            $msg='applied:Extensión "'.$name.'" quitada de PHP '.$ver.'.';
        }
        header('Location: ?tab=php&ver='.urlencode($ver).'&msg='.urlencode($msg)); exit;
    }
    elseif ($action === 'clearlog') {
        $lf = safe_logname($_POST['log'] ?? '');
        if ($lf && is_file($ROOT.'/logs/apache/'.$lf)) { @file_put_contents($ROOT.'/logs/apache/'.$lf, ''); $msg='info:Log '.$lf.' vaciado.'; }
        $back = (string)($_POST['back'] ?? '');
        if ($back !== '' && strpos($back, '?tab=proyecto&name=') === 0) { header('Location: '.$back.'&msg='.urlencode($msg)); exit; }
        $tab='logs';
        header('Location: ?tab=logs&log='.urlencode($lf)); exit;
    }
    elseif ($action === 'switch') {
        $name=$_POST['name']??''; $php=$_POST['php']??'';
        $siteKey = resolve_site_key($cfg['sites'], $name);
        if ($siteKey !== null && (!$vers || in_array($php,$vers,true))) {
            $name = $siteKey;
            // Normalizar formato escalar legacy ("miweb":"8.4") antes de indexar ['php'],
            // igual que set_domain/detect_types: sin esto era un TypeError 500.
            if (!is_array($cfg['sites'][$name])) { $cfg['sites'][$name] = ['php'=>$cfg['sites'][$name]]; }
            $cfg['sites'][$name]['php']=$php; write_json($CFG_FILE,$cfg); lua_apply();
            $msg='applied:"'.$name.'" ahora usa PHP '.$php.'.';
        } else { $msg='error:No se pudo cambiar la versión.'; }
    }
    elseif ($action === 'delete') {
        $name=$_POST['name']??'';
        $siteKey = resolve_site_key($cfg['sites'], $name);
        if ($siteKey === null) { $msg='error:No existe ese proyecto.'; }
        elseif (project_locked(project_dir($WWW, $cfg['sites'][$siteKey], $siteKey))) { $msg='error:"'.$siteKey.'" está bloqueado (tiene un archivo .lua). Desbloquéalo antes de eliminarlo.'; }
        else {
            $name = $siteKey;
            $info = $cfg['sites'][$name];
            // Externo = la carpeta ya existia fuera de www\ antes de registrarla (ver
            // add_external): nunca se toca en disco, solo se desregistra -- es la carpeta de
            // un proyecto/repo que le importa al usuario independientemente de lua-server.
            $isExternal = is_array($info) && !empty($info['path']);
            $dir = project_dir($WWW, $info, $name);
            $dbName = is_array($info) ? (string)($info['db'] ?? '') : '';
            $dbUser = is_array($info) ? (string)($info['dbuser'] ?? '') : '';
            unset($cfg['sites'][$name]); write_json($CFG_FILE,$cfg); lua_apply();
            $extra = [];
            if (!$isExternal && is_dir($dir)) {
                $extra[] = rrmdir($dir) ? 'carpeta borrada' : 'aviso: no se pudo borrar toda la carpeta (revisa permisos)';
            }
            // Solo se borra la BD/usuario si quedaron anotados en sites.json al crear el
            // proyecto (Set-SiteDb en lua.ps1) -- nunca por coincidencia de nombre, para no
            // arriesgarse a borrar la BD de otro proyecto con un nombre parecido.
            if ($dbName !== '' && valid_dbname($dbName)) {
                try {
                    $pdo = mysql_pdo();
                    $pdo->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
                    if ($dbUser !== '' && valid_mysql_user($dbUser)) {
                        $pdo->exec("DROP USER IF EXISTS '".$dbUser."'@'127.0.0.1'");
                        mysql_user_forget_password($ROOT, $dbUser, '127.0.0.1');
                        pma_sync_servers($ROOT);
                    }
                    $extra[] = 'BD "'.$dbName.'" eliminada';
                } catch (Throwable $e) { $extra[] = 'aviso: no se pudo eliminar la BD "'.$dbName.'" ('.$e->getMessage().')'; }
            }
            $tail = $isExternal
                ? ' (carpeta externa: se conserva en disco'.($extra?'; '.implode('; ',$extra):'').')'
                : ($extra ? ' ('.implode('; ',$extra).')' : ' (la carpeta ya no existía)');
            $msg='applied:Proyecto "'.$name.'" eliminado'.$tail.'.';
        }
    }
    elseif ($action === 'integrate') {
        $name=$_POST['name']??'';
        $php=$_POST['php']??($cfg['defaultPhp']??'8.4');
        if (!is_www_child_dir($WWW, $name)) { $msg='error:No existe la carpeta www\\'.$name.'.'; }
        elseif ($vers && !in_array($php,$vers,true)) { $msg='error:Versión de PHP no instalada.'; }
        else {
            // El nombre de carpeta puede no ser un slug valido (mayusculas, espacios...):
            // se usa como clave si ya lo es, si no se genera una y se guarda 'path' para
            // que apunte a la carpeta real (igual que un proyecto externo).
            $key = valid_name($name) ? $name : slug_from_name($name, $cfg['sites']);
            if (isset($cfg['sites'][$key])) { $msg='error:"'.$key.'" ya está registrado.'; }
            else {
                $realDir = "$WWW/$name";
                $site = ($key === $name) ? ['php'=>$php] : ['php'=>$php, 'path'=>$realDir];
                $type = detect_project_type($realDir);
                if ($type) { $site['type'] = $type; }
                $cfg['sites'][$key] = $site; write_json($CFG_FILE,$cfg); lua_apply(); lua_hosts();
                lock_project_dir($realDir); // proyecto ya existente: bloqueado por defecto
                $typeLabel = ['wordpress'=>'WordPress','laravel'=>'Laravel','symfony'=>'Symfony'][$type] ?? null;
                $msg='applied:"'.$name.'" integrado'.($key!==$name?' como "'.$key.'"':'').' y bloqueado'.($typeLabel?' ('.$typeLabel.' detectado)':'').'. Sincronizando hosts (acepta el aviso de Windows/UAC).';
            }
        }
    }
    elseif ($action === 'integrate_all') {
        $fallbackPhp = $_POST['php'] ?? ($cfg['defaultPhp'] ?? '8.4');
        $todo = unregistered_projects($WWW, $cfg['sites']);
        $renamed = 0; $detected = 0;
        foreach ($todo as $name) {
            $key = valid_name($name) ? $name : slug_from_name($name, $cfg['sites']);
            if ($key !== $name) { $renamed++; }
            $realDir = "$WWW/$name";
            // Version por proyecto: si composer.json/.php-version da una pista y esta
            // instalada, se usa esa; si no, la que se eligio para "Integrar todo".
            $dPhp = detect_project_php($realDir, $vers);
            if ($dPhp) { $php = $dPhp; $detected++; } else { $php = $fallbackPhp; }
            $site = ($key === $name) ? ['php'=>$php] : ['php'=>$php, 'path'=>$realDir];
            $type = detect_project_type($realDir);
            if ($type) { $site['type'] = $type; }
            $cfg['sites'][$key] = $site;
        }
        if ($todo) {
            write_json($CFG_FILE,$cfg); lua_apply(); lua_hosts();
            foreach ($todo as $name) { lock_project_dir("$WWW/$name"); } // bloqueados por defecto
            $msg='applied:'.count($todo).' proyecto(s) integrado(s) y bloqueado(s)'.($detected?' ('.$detected.' con versión de PHP detectada de composer.json)':'').($renamed?' ('.$renamed.' con clave ajustada, nombre de carpeta no valido)':'').'. Sincronizando hosts (acepta el aviso de Windows/UAC).';
        }
        else { $msg='error:No había nada que integrar.'; }
    }
    elseif ($action === 'sync_projects') {
        // Inverso de "Integrar todo": quita de sites.json los proyectos cuya carpeta
        // ya no existe (borrada a mano fuera del panel). No toca proyectos bloqueados
        // porque project_locked() ya devuelve false si la carpeta no existe: no hay
        // nada que desbloquear a mano, se puede quitar sin más.
        $gone = missing_projects($WWW, $cfg['sites']);
        if ($gone) {
            foreach ($gone as $name) { unset($cfg['sites'][$name]); }
            write_json($CFG_FILE,$cfg); lua_apply();
            $msg='applied:'.count($gone).' proyecto(s) quitado(s) de la lista por no tener ya carpeta en disco: '.implode(', ', $gone).'.';
        } else {
            $msg='info:Todos los proyectos registrados tienen su carpeta en disco. Nada que sincronizar.';
        }
    }
    elseif ($action === 'detect_types') {
        $tab = 'proyectos';
        $n = 0; $checked = 0;
        foreach ($cfg['sites'] as $sName => &$sInfo) {
            if (!is_array($sInfo)) { $sInfo = ['php'=>$sInfo]; }
            if (!empty($sInfo['type'])) continue;
            $sDir = project_dir($WWW, $sInfo, $sName);
            $t = detect_project_type($sDir);
            if ($t) { $sInfo['type'] = $t; unset($sInfo['typeChecked']); $n++; }
            // No es WordPress/Laravel/Symfony: lo marcamos como "ya revisado" para que el
            // aviso "Detectar tipos (N)" deje de contarlo (si no, nunca se quitaria).
            elseif (empty($sInfo['typeChecked'])) { $sInfo['typeChecked'] = true; $checked++; }
        }
        unset($sInfo);
        if ($n > 0 || $checked > 0) { write_json($CFG_FILE,$cfg); }
        if     ($n > 0)       { $msg='applied:'.$n.' proyecto(s) detectado(s) (PHP, JavaScript o Python).'; }
        elseif ($checked > 0) { $msg='applied:Sin framework conocido en '.$checked.' proyecto(s): no se volverá a avisar de estos.'; }
        else                  { $msg='info:No hay proyectos pendientes de detectar.'; }
    }
    elseif ($action === 'delete_unregistered') {
        $name=$_POST['name']??'';
        $dir = "$WWW/$name";
        $isChild = is_www_child_dir($WWW, $name);
        $realDir = $isChild ? realpath($dir) : false;
        // Defensa en profundidad ante rrmdir: exigir que la ruta resuelta sea hijo ESTRICTO
        // de www\ (nunca la propia www ni un ancestro). Si is_www_child_dir fallara, esto
        // impide igualmente borrar la raiz del stack.
        $wwwReal = realpath($WWW);
        $insideWww = $realDir !== false && $wwwReal !== false && $realDir !== $wwwReal
                     && strpos($realDir, $wwwReal.DIRECTORY_SEPARATOR) === 0;
        $isRegisteredDir = isset($cfg['sites'][$name]) || ($realDir !== false && isset(registered_dirs($WWW, $cfg['sites'])[$realDir]));
        if (!$isChild && !is_dir($dir)) { clearstatcache(); $msg='info:Esa carpeta ya no existe en www\\.'; }
        elseif (!$isChild || !$insideWww || $isRegisteredDir) { $msg='error:Nombre no válido.'; }
        elseif (project_locked($dir)) { $msg='error:"'.$name.'" tiene un archivo .lua que la protege. Quítalo a mano para poder borrarla.'; }
        else {
            rrmdir($dir); clearstatcache();
            $msg='applied:Carpeta "'.$name.'" eliminada de www\\.';
        }
    }
