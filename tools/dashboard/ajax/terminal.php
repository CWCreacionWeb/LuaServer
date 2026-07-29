<?php
// ---------------- Endpoints AJAX de la terminal (devuelven JSON, no PRG) ----------------
$__ta = $_REQUEST['action'] ?? '';
if ($__ta==='term_run' || $__ta==='term_poll' || $__ta==='term_stop' || $__ta==='docker_term_run') {
    header('Content-Type: application/json; charset=utf-8');
    // JSON_INVALID_UTF8_SUBSTITUTE: la salida de comandos puede traer bytes no-UTF8 (muchas
    // herramientas de Windows escriben en OEM/ANSI pese al chcp 65001) o un carácter UTF-8
    // multibyte cortado en el límite de lectura. Sin el flag, json_encode devuelve false ->
    // cuerpo vacío -> el poll JS lo toma por "[error de red]" y abandona un comando que sigue vivo.
    $reply = function($o){ echo json_encode($o, JSON_INVALID_UTF8_SUBSTITUTE); exit; };

    if (!term_enabled($ROOT)) { $reply(['error'=>'La terminal está desactivada. Actívala en Configuración del servidor.']); }
    $sid = $_REQUEST['sid'] ?? '';
    if (!term_valid_sid($sid)) { $reply(['error'=>'Sesión no válida.']); }
    $dir = term_dir($ROOT,$sid);
    @mkdir($dir, 0777, true);

    if ($__ta==='term_run') {
        $cmd = (string)($_POST['cmd'] ?? '');
        if (trim($cmd)==='') { $reply(['error'=>'Comando vacío.']); }
        if (strlen($cmd) > 8000) { $reply(['error'=>'Comando demasiado largo.']); }
        // limpieza: borrar sesiones antiguas (>1 dia) para que tmp\terminal no crezca sin fin
        foreach ((array)@glob($ROOT.'/tmp/terminal/*', GLOB_ONLYDIR) as $old) {
            if (@filemtime($old) < time()-86400) { foreach ((array)@glob($old.'/*') as $f) @unlink($f); @rmdir($old); }
        }
        $runid = bin2hex(random_bytes(8));
        $cwd   = term_get_cwd($ROOT,$sid,(string)($_POST['cwd0'] ?? ''));
        $cmdf  = $dir.'/'.$runid.'.cmd';
        $outf  = $dir.'/'.$runid.'.out';
        $cwdf  = $dir.'/'.$runid.'.cwd';
        // Wrapper .cmd: titulo unico (para poder matar el arbol), cwd persistente,
        // captura de exit code y del nuevo cwd, marca de fin.
        // Home propio para Composer/NPM: en este entorno el proceso de Apache no siempre
        // tiene APPDATA (composer/npm lo necesitan en Windows para su cache/config), y de
        // paso mantiene todo autocontenido en la carpeta del stack en vez del perfil de Windows.
        $homeDir = $ROOT.'/tmp/home';
        @mkdir($homeDir.'/AppData/Roaming', 0777, true);
        @mkdir($homeDir.'/composer', 0777, true);
        $wr  = "@echo off\r\n";
        $wr .= "title lua_".$runid."\r\n";
        $wr .= "chcp 65001 >NUL\r\n";
        $wr .= "set \"APPDATA=".term_win($homeDir)."\\AppData\\Roaming\"\r\n";
        $wr .= "set \"COMPOSER_HOME=".term_win($homeDir)."\\composer\"\r\n";
        // Si el runner conoce la version de PHP del proyecto (pasada desde luaOpenRunner), su
        // carpeta bin\php\<ver> va primero en el PATH: asi "composer"/"php" usan el PHP propio
        // del proyecto (siempre presente) en vez de depender de un PHP global del sistema
        // (que puede no existir o estar roto, como en esta misma maquina).
        $pathParts = [];
        $reqPhp = (string)($_POST['php'] ?? '');
        if (preg_match('/^\d\.\d$/', $reqPhp) && is_dir($PHP_BASE.'/'.$reqPhp)) {
            $pathParts[] = term_win($PHP_BASE.'/'.$reqPhp);
            // El propio worker de Apache que atiende este request (panel = PHP 8.4, ver
            // FcgidInitialEnv PHPRC en httpd-lua.conf) hereda su PHPRC al proceso hijo que
            // lanza WScript.Shell.Exec -- y PHPRC GANA a "misma carpeta que el .exe" en la
            // busqueda de php.ini. Sin este set, "php"/composer del proyecto encontraban el
            // .exe correcto por PATH pero leian igualmente el php.ini/extensiones del PANEL
            // (8.4), no las de la version del proyecto, aunque el runner pidiera otra.
            $wr .= "set \"PHPRC=".term_win($PHP_BASE.'/'.$reqPhp)."\"\r\n";
        }
        $freshPath = term_fresh_machine_path();
        if ($freshPath) { $pathParts[] = $freshPath; }
        if ($pathParts) { $wr .= "set \"PATH=".implode(';', $pathParts).";%PATH%\"\r\n"; }
        $wr .= "cd /d \"".term_win($cwd)."\"\r\n";
        // En Windows, composer/npm/git-alias/etc. son shims .bat/.cmd: invocarlos SIN "call"
        // dentro de un script hace que el control nunca vuelva (el wrapper se queda "colgado"
        // sin escribir la marca de fin). Los envolvemos en un cmd /c anidado para que siempre
        // vuelva el control, salvo un "cd"/"pushd"/"popd" simple (sin encadenar), que se deja
        // tal cual para que el cambio de directorio persista entre comandos de la sesion.
        $cmdTrim = trim($cmd);
        if (preg_match('/^(cd|pushd|popd)(\s|$)/i', $cmdTrim) && !preg_match('/&&|\|\||[&|]/', $cmdTrim)) {
            $wr .= $cmdTrim."\r\n";
        } else {
            $wr .= 'call cmd /c "'.$cmdTrim.'"'."\r\n";
        }
        $wr .= "set __LUA_EC=%ERRORLEVEL%\r\n";
        $wr .= "cd > \"".term_win($cwdf)."\"\r\n";
        $wr .= "echo __LUA_DONE__%__LUA_EC%\r\n";
        file_put_contents($cmdf, $wr);
        @file_put_contents($outf, '');
        $launch = 'cmd /c ""'.term_win($cmdf).'" > "'.term_win($outf).'" 2>&1"';
        try {
            $sh = new COM('WScript.Shell');
            // Exec() (no Run()): ademas de lanzar sin esperar, devuelve un objeto con
            // ProcessID -- lo guardamos para poder matar el arbol por PID en term_stop.
            // El intento anterior (taskkill por titulo de ventana, "title lua_<runid>")
            // nunca mataba nada de verdad: un proceso con stdout/stderr redirigidos a
            // fichero y lanzado oculto no expone un titulo de ventana localizable por
            // tasklist/taskkill (confirmado: "tasklist /FI WINDOWTITLE eq ..." no
            // encontraba nada aunque el proceso seguia vivo), asi que "Detener" no
            // detenia nada en la practica.
            $exec = $sh->Exec($launch);
            @file_put_contents($dir.'/'.$runid.'.pid', (string)$exec->ProcessID);
        } catch (Throwable $e) {
            $reply(['error'=>'No se pudo lanzar el comando: '.$e->getMessage()]);
        }
        $reply(['runid'=>$runid, 'cwd'=>$cwd]);
    }

    if ($__ta==='docker_term_run') {
        $cmd = (string)($_POST['cmd'] ?? '');
        if (trim($cmd)==='') { $reply(['error'=>'Comando vacío.']); }
        if (strlen($cmd) > 4000) { $reply(['error'=>'Comando demasiado largo.']); }
        $container = (string)($_POST['container'] ?? '');
        if (!preg_match('/^[a-f0-9]{6,64}$/i', $container)) { $reply(['error'=>'Contenedor no válido.']); }
        if (!docker_running()) { $reply(['error'=>'Docker no está arrancado.']); }
        $dockerBin = docker_binary();
        if ($dockerBin === false) { $reply(['error'=>'Docker no está disponible.']); }
        foreach ((array)@glob($ROOT.'/tmp/terminal/*', GLOB_ONLYDIR) as $old) {
            if (@filemtime($old) < time()-86400) { foreach ((array)@glob($old.'/*') as $f) @unlink($f); @rmdir($old); }
        }
        $runid  = bin2hex(random_bytes(8));
        $cmdf   = $dir.'/'.$runid.'.cmd';
        $outf   = $dir.'/'.$runid.'.out';
        $stdinf = $dir.'/'.$runid.'.stdin';
        // El comando del usuario va tal cual (sin comillas propias) a un fichero, y ese
        // fichero se redirige como stdin de "sh" dentro del contenedor: evita por completo
        // el infierno de comillas anidadas (cmd.exe -> docker exec -> sh -c "...") que tendria
        // construir la linea a mano -- aqui no hace falta escapar NADA del texto del usuario.
        file_put_contents($stdinf, rtrim($cmd, "\r\n")."\n");
        $wr  = "@echo off\r\n";
        $wr .= "title lua_".$runid."\r\n";
        $wr .= "chcp 65001 >NUL\r\n";
        $wr .= '"'.$dockerBin.'" exec -i '.$container.' sh < "'.term_win($stdinf).'"'."\r\n";
        $wr .= "set __LUA_EC=%ERRORLEVEL%\r\n";
        $wr .= "echo __LUA_DONE__%__LUA_EC%\r\n";
        file_put_contents($cmdf, $wr);
        @file_put_contents($outf, '');
        $launch = 'cmd /c ""'.term_win($cmdf).'" > "'.term_win($outf).'" 2>&1"';
        try {
            $sh = new COM('WScript.Shell');
            // Exec() en vez de Run(): ver comentario en term_run sobre por que hace
            // falta el PID (ProcessID) para poder matar el arbol de verdad en term_stop.
            $exec = $sh->Exec($launch);
            @file_put_contents($dir.'/'.$runid.'.pid', (string)$exec->ProcessID);
        } catch (Throwable $e) {
            $reply(['error'=>'No se pudo lanzar el comando: '.$e->getMessage()]);
        }
        $reply(['runid'=>$runid]);
    }

    if ($__ta==='term_poll') {
        $runid = $_REQUEST['runid'] ?? '';
        if (!preg_match('/^[a-f0-9]{16}$/', $runid)) { $reply(['error'=>'runid no válido.']); }
        $off  = max(0, (int)($_REQUEST['off'] ?? 0));
        $outf = $dir.'/'.$runid.'.out';
        // El fichero de salida solo falta si el runid nunca existió o si otro poll ya
        // detectó el fin y lo limpió (p.ej. al reconectar tras recargar la página con el
        // comando ya terminado antes): sin esto, el polling en segundo plano se quedaría
        // esperando para siempre una marca de fin que nunca va a llegar.
        if (!is_file($outf)) { $reply(['data'=>'', 'off'=>$off, 'done'=>true, 'code'=>null, 'cwd'=>null]); }
        $data = '';
        $fh = @fopen($outf,'rb');
        if ($fh) { if ($off>0) fseek($fh,$off); $data=stream_get_contents($fh); fclose($fh); }
        // Recortar del final una secuencia UTF-8 multibyte cortada en el límite de lectura:
        // sus bytes se leerán completos en el próximo poll (no avanzamos $newoff sobre ellos),
        // evitando un carácter de reemplazo basura en la costura. Los bytes ASCII (incl. la
        // marca __LUA_DONE__) son de 1 byte, así que esto nunca toca la detección de fin.
        $len = strlen($data); $hold = 0;
        for ($i = $len - 1; $i >= 0 && $i >= $len - 3; $i--) {
            $b = ord($data[$i]);
            if (($b & 0xC0) === 0x80) { continue; } // 10xxxxxx: byte de continuación
            if     (($b & 0xE0) === 0xC0) $need = 2;
            elseif (($b & 0xF0) === 0xE0) $need = 3;
            elseif (($b & 0xF8) === 0xF0) $need = 4;
            else   $need = 1;                       // ASCII o líder inválido: nada que retener
            if ($need > 1 && ($len - $i) < $need) { $hold = $len - $i; }
            break;
        }
        if ($hold > 0) { $data = substr($data, 0, $len - $hold); }
        $newoff = $off + strlen($data);
        // ¿terminó? detectar la marca de fin
        $done=false; $code=null;
        if (preg_match('/__LUA_DONE__(\d+)\s*$/', $data, $m)) {
            $done=true; $code=(int)$m[1];
            $data = preg_replace('/\r?\n?__LUA_DONE__\d+\s*$/', '', $data);
        }
        $cwd=null;
        if ($done) {
            $cwdf = $dir.'/'.$runid.'.cwd';
            if (is_file($cwdf)) { $c=trim((string)@file_get_contents($cwdf)); if ($c!=='' && is_dir($c)) { @file_put_contents($dir.'/cwd',$c); $cwd=$c; } }
            @unlink($cmdf ?? ($dir.'/'.$runid.'.cmd')); @unlink($outf); @unlink($dir.'/'.$runid.'.cwd');
            @unlink($dir.'/'.$runid.'.pid'); @unlink($dir.'/'.$runid.'.stdin');
        }
        $reply(['data'=>$data, 'off'=>$newoff, 'done'=>$done, 'code'=>$code, 'cwd'=>$cwd]);
    }

    if ($__ta==='term_stop') {
        $runid = $_REQUEST['runid'] ?? '';
        if (!preg_match('/^[a-f0-9]{16}$/', $runid)) { $reply(['error'=>'runid no válido.']); }
        // Matar el arbol de procesos por PID real (ver comentario en term_run/Exec()).
        $pidf = $dir.'/'.$runid.'.pid';
        $pid = is_file($pidf) ? (int)trim((string)@file_get_contents($pidf)) : 0;
        if ($pid > 0) { @exec('taskkill /F /T /PID '.$pid.' 2>&1'); }
        // al matar el proceso, el wrapper no escribe la marca de fin: la añadimos
        // nosotros para que el polling del panel termine limpio (código 130 = interrumpido).
        $outf = $dir.'/'.$runid.'.out';
        if (is_file($outf)) { @file_put_contents($outf, "\r\n[detenido]\r\n__LUA_DONE__130", FILE_APPEND); }
        $reply(['stopped'=>true]);
    }
}

