<?php
// ============================================================
//  lua-server :: panel de gestion (solo localhost)
//  - Proyectos: crear / eliminar / cambiar version de PHP
//  - PHP: editar overrides del php.ini de cada version
// ============================================================
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$ROOT     = dirname(__DIR__, 2);
$CFG_FILE = $ROOT . '/config/sites.json';
$PHP_BASE = $ROOT . '/bin/php';
$OVR_DIR  = $ROOT . '/config/php';
$WWW      = $ROOT . '/www';

$CURATED = [
  'date.timezone'       => ['label' => 'Zona horaria',            'type' => 'text',  'ph' => 'Europe/Madrid'],
  'memory_limit'        => ['label' => 'Límite de memoria',       'type' => 'text',  'ph' => '512M'],
  'upload_max_filesize' => ['label' => 'Tamaño máx. de subida',   'type' => 'text',  'ph' => '128M'],
  'post_max_size'       => ['label' => 'Tamaño máx. de POST',     'type' => 'text',  'ph' => '128M'],
  'max_execution_time'  => ['label' => 'Tiempo máx. ejecución (s)','type' => 'text', 'ph' => '120'],
  'max_input_vars'      => ['label' => 'Máx. variables de entrada','type' => 'text', 'ph' => '5000'],
  'display_errors'      => ['label' => 'Mostrar errores',         'type' => 'onoff', 'ph' => ''],
  'error_reporting'     => ['label' => 'Nivel de errores',        'type' => 'text',  'ph' => 'E_ALL'],
];

// URLs de las DLL de Xdebug por version de PHP (Windows NTS x64). Se rellenan tras verificar.
$XDEBUG_URLS = [
  '7.4' => 'https://xdebug.org/files/php_xdebug-3.1.6-7.4-vc15-x86_64.dll',
  '8.1' => 'https://xdebug.org/files/php_xdebug-3.5.3-8.1-nts-vs16-x86_64.dll',
  '8.2' => 'https://xdebug.org/files/php_xdebug-3.5.3-8.2-nts-vs16-x86_64.dll',
  '8.3' => 'https://xdebug.org/files/php_xdebug-3.5.3-8.3-nts-vs16-x86_64.dll',
  '8.4' => 'https://xdebug.org/files/php_xdebug-3.5.3-8.4-nts-vs17-x86_64.dll',
  '8.5' => 'https://xdebug.org/files/php_xdebug-3.5.3-8.5-nts-vs17-x86_64.dll',
];

function read_json($f){ $r=@file_get_contents($f); if($r===false)return null; $r=preg_replace('/^\xEF\xBB\xBF/','',$r); return json_decode($r,true); }
function write_json($f,$d){ file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); }
function valid_name($n){ return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/', $n); }
function valid_domain($d){ return (bool)preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $d); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
// Carpeta raiz de un proyecto: su 'path' (ruta externa) si esta definido, si no www\<name>.
function project_dir($WWW, $info, $name){
    if (is_array($info) && !empty($info['path']) && is_dir($info['path'])) return $info['path'];
    return $WWW.'/'.$name;
}

function php_versions($base){
    $v=[]; if(is_dir($base)){ foreach(scandir($base) as $d){ if($d[0]==='.')continue; if(is_file("$base/$d/php-cgi.exe")) $v[]=$d; } } natsort($v); return array_values($v);
}

// ---------------- Ficha de proyecto: info ampliada, git y arbol de archivos ----------------
// Apache corre como servicio de Windows (cuenta SYSTEM) una vez activado "Arrancar con
// Windows": esa cuenta tiene su propio PATH, que normalmente NO incluye el git.exe que
// sí ve una consola interactiva del usuario. Por eso 'git' a secas puede fallar aunque
// git funcione perfectamente a mano; si falla, se prueban las rutas de instalacion tipicas.
function git_binary(){
    static $bin = null;
    if ($bin !== null) return $bin;
    $probe = @proc_open('git --version', [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (is_resource($probe)) {
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($probe) === 0) return $bin = 'git';
    }
    $candidates = array_filter([
        getenv('ProgramFiles') ? getenv('ProgramFiles').'\Git\cmd\git.exe' : null,
        getenv('ProgramFiles') ? getenv('ProgramFiles').'\Git\bin\git.exe' : null,
        getenv('ProgramFiles(x86)') ? getenv('ProgramFiles(x86)').'\Git\cmd\git.exe' : null,
        getenv('ProgramW6432') ? getenv('ProgramW6432').'\Git\cmd\git.exe' : null,
        'C:\\Program Files\\Git\\cmd\\git.exe',
    ]);
    foreach ($candidates as $cand) { if (is_file($cand)) return $bin = '"'.$cand.'"'; }
    return $bin = false;
}
function git_exec($dir, $args){
    $bin = git_binary();
    if ($bin === false) return null;
    $descriptors = [1=>['pipe','w'], 2=>['pipe','w']];
    // Apache corre como SYSTEM (servicio de Windows); las carpetas de los proyectos son
    // del usuario interactivo, y git rechaza operar en repos de otro dueno ("dubious
    // ownership") salvo que se confie explicitamente. Se hace por-llamada (-c), sin tocar
    // la config global de git de la cuenta SYSTEM.
    $safe = '-c '.escapeshellarg('safe.directory='.$dir);
    $proc = @proc_open($bin.' '.$safe.' '.$args, $descriptors, $pipes, $dir);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return $code===0 ? $out : null;
}
// null = no es un repo git (o no se encontro git.exe); array = branch/dirty/commits/remote
function git_info($dir){
    if (!is_dir($dir.'/.git')) return null;
    $branch = trim((string)git_exec($dir, 'rev-parse --abbrev-ref HEAD'));
    if ($branch === '') return null;
    $statusRaw = git_exec($dir, 'status --porcelain');
    $dirty = $statusRaw!==null ? count(array_filter(explode("\n", trim($statusRaw)), fn($l)=>$l!=='')) : 0;
    $logRaw = git_exec($dir, 'log -n 30 --date=relative --pretty=format:%h%x09%an%x09%ad%x09%s');
    $commits = [];
    if ($logRaw !== null && $logRaw !== '') {
        foreach (explode("\n", $logRaw) as $line) {
            $parts = explode("\t", $line, 4);
            if (count($parts)===4) $commits[] = ['hash'=>$parts[0],'author'=>$parts[1],'date'=>$parts[2],'subject'=>$parts[3]];
        }
    }
    $remote = trim((string)git_exec($dir, 'remote get-url origin'));
    return ['branch'=>$branch, 'dirty'=>$dirty, 'commits'=>$commits, 'remote'=>$remote];
}

function ticon_chev(){ return '<svg class="tchev" viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>'; }
function ticon_folder(){ return '<svg class="ticon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/></svg>'; }
function ticon_file(){ return '<svg class="ticon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'; }

// Arbol de archivos de un proyecto. $lazyMode=true trata TODAS las subcarpetas como
// cerradas/perezosas (usado en la respuesta AJAX de un solo nivel); en modo normal
// solo vendor/node_modules/.git empiezan cerradas (se cargan al hacer clic).
function tree_node_html($abs, $rel, $lazyMode, &$count, $cap){
    $entries = @scandir($abs);
    if ($entries === false) return '<div class="tnode-more">No se pudo leer la carpeta.</div>';
    $dirs = []; $files = [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        if (is_dir($abs.'/'.$e)) $dirs[] = $e; else $files[] = $e;
    }
    natcasesort($dirs); natcasesort($files);
    $out = '';
    foreach ($dirs as $e) {
        if ($count >= $cap) { $out .= '<div class="tnode-more">&hellip; truncado, hay más entradas de las mostradas</div>'; return $out; }
        $count++;
        $childRel = ($rel !== '' ? $rel.'/' : '').$e;
        $heavy = in_array(strtolower($e), ['vendor','node_modules','.git'], true);
        $lazy = $lazyMode || $heavy;
        if ($lazy) {
            $out .= '<div class="tnode"><div class="trow tdir" data-lazy="1" data-rel="'.e($childRel).'">'.ticon_chev().ticon_folder().'<span>'.e($e).'</span></div><div class="tchildren" hidden></div></div>';
        } else {
            $out .= '<div class="tnode"><div class="trow tdir open">'.ticon_chev().ticon_folder().'<span>'.e($e).'</span></div><div class="tchildren">';
            $out .= tree_node_html($abs.'/'.$e, $childRel, false, $count, $cap);
            $out .= '</div></div>';
        }
    }
    foreach ($files as $e) {
        if ($count >= $cap) { $out .= '<div class="tnode-more">&hellip; truncado, hay más entradas de las mostradas</div>'; return $out; }
        $count++;
        $childRel = ($rel !== '' ? $rel.'/' : '').$e;
        $out .= '<div class="trow tfile" data-rel="'.e($childRel).'" title="Clic para editar">'.ticon_file().'<span>'.e($e).'</span></div>';
    }
    return $out;
}
// Carpetas en www\ que no estan registradas en sites.json (creadas a mano,
// copiadas de otra maquina, etc.). No se publican solas: hay que integrarlas.
function unregistered_projects($www, $sites){
    $out=[];
    if (is_dir($www)) {
        foreach (scandir($www) as $d) {
            if ($d==='.'||$d==='..') continue;
            if (!is_dir("$www/$d")) continue;
            if (isset($sites[$d])) continue;
            if (!valid_name($d)) continue;
            $out[]=$d;
        }
    }
    sort($out);
    return $out;
}
// Detecta el framework de un proyecto por sus archivos caracteristicos. Se llama solo
// al integrar (no en cada carga): el resultado se guarda en sites.json como 'type'.
function detect_project_type($dir){
    if (is_file("$dir/wp-load.php") || is_file("$dir/wp-config.php") || is_file("$dir/wp-config-sample.php")) return 'wordpress';
    if (is_file("$dir/artisan")) return 'laravel';
    $composerFile = "$dir/composer.json";
    if (is_file($composerFile)) {
        $data = json_decode((string)@file_get_contents($composerFile), true);
        $require = array_merge((array)($data['require'] ?? []), (array)($data['require-dev'] ?? []));
        foreach (array_keys($require) as $pkg) {
            if ($pkg === 'laravel/framework') return 'laravel';
            if (strpos($pkg, 'symfony/') === 0) return 'symfony';
        }
    }
    return null;
}
// Borrado recursivo de una carpeta sin registrar (no hay sites.json que "desregistrar": es on/off).
function rrmdir($dir){
    if (!is_dir($dir)) return true;
    foreach (scandir($dir) as $f) {
        if ($f==='.'||$f==='..') continue;
        $p = $dir.'/'.$f;
        if (is_dir($p) && !is_link($p)) rrmdir($p); else @unlink($p);
    }
    return @rmdir($dir);
}
// El panel NO lanza procesos: solo deja un archivo-senal en tmp\ que el watcher
// (proceso independiente arrancado por 'lua.ps1 start') ejecuta en ~1 segundo.
function lua_flag($name){ @file_put_contents(dirname(__DIR__,2).'/tmp/'.$name.'.flag', (string)time()); }
function lua_apply(){ lua_flag('apply'); }
function lua_hosts(){ lua_flag('hosts'); }

// ---------------- MySQL (MariaDB): listar/crear/eliminar bases de datos ----------------
function valid_dbname($n){ return (bool)preg_match('/^[a-zA-Z0-9_]{1,64}$/', (string)$n); }
function valid_mysql_user($n){ return (bool)preg_match('/^[a-zA-Z0-9_]{1,32}$/', (string)$n); }
function valid_mysql_host($h){ return in_array($h, ['127.0.0.1','localhost','%'], true); }
// Contraseña de root guardada aparte del sitio (config\mysql_root.pass), fuera de git.
function mysql_root_pass($root){
    $f = $root.'/config/mysql_root.pass';
    return is_file($f) ? trim((string)@file_get_contents($f)) : '';
}
function mysql_pdo(){
    global $ROOT;
    $pass = mysql_root_pass($ROOT);
    return new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>3]);
}
// null = no se pudo conectar (MySQL apagado); array = bases de datos de usuario (sin esquemas de sistema)
function mysql_databases(){
    static $sys = ['information_schema','performance_schema','mysql','sys'];
    try {
        $pdo = mysql_pdo();
        $out = [];
        foreach ($pdo->query('SHOW DATABASES') as $row) { if (!in_array($row[0], $sys, true)) $out[] = $row[0]; }
        sort($out);
        return $out;
    } catch (Throwable $e) { return null; }
}
// null = no se pudo conectar; array de ['user'=>..,'host'=>..] (sin cuentas de sistema)
function mysql_users(){
    static $sys = ['mysql.sys','mariadb.sys','mysql.infoschema','mysql.session'];
    try {
        $pdo = mysql_pdo();
        $out = [];
        foreach ($pdo->query('SELECT User, Host FROM mysql.user') as $row) {
            if ($row['User'] === '' || in_array($row['User'], $sys, true)) continue;
            $out[] = ['user'=>$row['User'], 'host'=>$row['Host']];
        }
        usort($out, function($a,$b){ return [$a['user'],$a['host']] <=> [$b['user'],$b['host']]; });
        return $out;
    } catch (Throwable $e) { return null; }
}

// ---------------- Terminal (sin PTY: ejecuta comandos, streamea su salida) ----------------
// Cada comando se lanza DESATENDIDO via COM(WScript.Shell) contra un .cmd generado,
// que redirige stdout+stderr a un .out. El panel hace polling del .out por offset.
// El cwd persiste entre comandos (el .cmd vuelca su directorio final a next.cwd).
function term_enabled($root){ return is_file($root.'/config/terminal.on'); }
function term_valid_sid($s){ return (bool)preg_match('/^[a-f0-9]{8,40}$/', (string)$s); }
function term_dir($root,$sid){ return $root.'/tmp/terminal/'.$sid; }
function term_default_cwd($root){ $w=$root.'/www'; return str_replace('/', '\\', is_dir($w)?$w:$root); }
function term_get_cwd($root,$sid){
    $f = term_dir($root,$sid).'/cwd';
    if (is_file($f)) { $c=trim((string)@file_get_contents($f)); if ($c!=='' && is_dir($c)) return $c; }
    return term_default_cwd($root);
}
function term_win($p){ return str_replace('/', '\\', $p); }

// ---------------- Caratula (cover) por proyecto ----------------
// Se guarda en data\covers\<name>.<ext> (runtime, fuera del docroot y de git).
// Se sirve via ?cover=<name>. Un solo archivo por proyecto.
function cover_exts(){ return ['jpg','jpeg','png','webp','gif','svg']; }
function cover_path($root,$name){
    foreach (cover_exts() as $e) { $f=$root.'/data/covers/'.$name.'.'.$e; if (is_file($f)) return $f; }
    return null;
}
function cover_mime($f){
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','svg'=>'image/svg+xml'];
    return $map[$ext] ?? 'application/octet-stream';
}

// ---------------- Despliegue por FTP: config guardada por proyecto (fuera de git) ----------------
function ftp_config_path($root,$name){ return $root.'/config/ftp/'.$name.'.json'; }
function ftp_config_get($root,$name){
    $f = ftp_config_path($root,$name);
    if (!is_file($f)) return null;
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

function tail_file($f,$n=250){ if(!is_file($f)) return ''; $lines=@file($f,FILE_IGNORE_NEW_LINES); if($lines===false) return ''; return implode("\n",array_slice($lines,-$n)); }
// Resalta por severidad los logs de error de Apache/PHP (fatal/warning/deprecated/notice).
// Devuelve HTML ya escapado: no volver a pasar por e().
function highlight_error_log($text){
    if ($text === '') return '';
    $lines = explode("\n", $text);
    $out = [];
    foreach ($lines as $line) {
        if (preg_match('/\b(Fatal error|Parse error|Uncaught)\b/i', $line)) { $cls = 'log-fatal'; }
        elseif (preg_match('/\bWarning\b/i', $line)) { $cls = 'log-warning'; }
        elseif (preg_match('/\bDeprecated\b/i', $line)) { $cls = 'log-deprecated'; }
        elseif (preg_match('/\bNotice\b/i', $line)) { $cls = 'log-notice'; }
        else { $cls = 'log-info'; }
        $out[] = '<span class="'.$cls.'">'.e($line).'</span>';
    }
    return implode("\n", $out);
}
function safe_logname($n){ return preg_match('/^[a-z0-9._-]+\.log$/i',$n) ? $n : ''; }
// Bloqueo de proyecto: existe si la raiz del proyecto contiene CUALQUIER archivo *.lua.
// El panel crea/quita el marcador .locked.lua, pero cualquier .lua puesto a mano
// tambien protege el proyecto contra el borrado.
define('LUA_LOCK_MARKER', '.locked.lua');
function project_locked($dir){
    if(!is_dir($dir)) return false;
    foreach(scandir($dir) as $f){
        if($f==='.'||$f==='..') continue;
        if(is_file($dir.'/'.$f) && strtolower(substr($f,-4))==='.lua') return true;
    }
    return false;
}
function read_jobs($dir){
    $out=[];
    if(is_dir($dir)){
        foreach(glob($dir.'/*.status') as $f){
            $j=json_decode(@file_get_contents($f),true);
            if($j){ $j['_mtime']=@filemtime($f); $out[]=$j; }
        }
        foreach(glob($dir.'/*.job') as $f){ // en cola (aun sin status)
            $b=basename($f,'.job'); $has=false;
            foreach($out as $o){ if(($o['id']??'')===$b){$has=true;break;} }
            if(!$has){ $out[]=['id'=>$b,'name'=>$b,'type'=>'?','state'=>'queued','msg'=>'En cola...','_mtime'=>@filemtime($f)]; }
        }
        usort($out, function($a,$b){ return ($b['_mtime']??0)-($a['_mtime']??0); });
    }
    return $out;
}
function job_log_tail($root,$id,$n=16){
    $f=$root.'/logs/jobs/'.$id.'.log';
    if(!is_file($f)) return '';
    $lines=@file($f,FILE_IGNORE_NEW_LINES); if(!$lines) return '';
    return implode("\n", array_slice($lines,-$n));
}
// El watcher es un proceso PowerShell independiente (arrancado por 'lua.ps1 start'),
// no un hijo de Apache: se comprueba igual que hace lua.ps1 (pid en tmp/watch.pid + tasklist).
function watcher_alive($root){
    $pf = $root.'/tmp/watch.pid';
    if (!is_file($pf)) return false;
    $pid = (int)trim((string)@file_get_contents($pf));
    if ($pid <= 0) return false;
    $out = [];
    @exec('tasklist /FI "PID eq '.$pid.'" 2>NUL', $out);
    foreach ($out as $line) { if (strpos($line, (string)$pid) !== false) return true; }
    return false;
}
// Consulta el estado real del arranque con Windows (servicio Apache + tarea del
// watcher), no un simple archivo de flag: llama a 'lua.ps1 startup-status' (solo
// lectura, no requiere admin).
function startup_enabled($root){
    $luaWin = str_replace('/', '\\', $root).'\\lua.ps1';
    $out = [];
    @exec('powershell -NoProfile -ExecutionPolicy Bypass -File "'.$luaWin.'" startup-status 2>NUL', $out);
    return trim((string)end($out)) === 'on';
}
function parse_overrides($file, $curatedKeys){
    $vals=[]; $extra=[];
    if(is_file($file)){
        foreach(file($file, FILE_IGNORE_NEW_LINES) as $ln){
            $t=trim($ln);
            if($t===''||$t[0]===';'||$t[0]==='#'){ continue; }
            if(preg_match('/^([a-zA-Z0-9_.]+)\s*=\s*(.*)$/',$t,$m) && in_array($m[1],$curatedKeys,true)){ $vals[$m[1]]=trim($m[2]); continue; }
            $extra[]=$ln;
        }
    }
    return [$vals,$extra];
}

// ---------------- Servir la caratula de un proyecto: ?cover=<name> ----------------
if (isset($_GET['cover'])) {
    $name = (string)$_GET['cover'];
    $f = valid_name($name) ? cover_path($ROOT,$name) : null;
    if ($f) {
        header('Content-Type: '.cover_mime($f));
        header('Cache-Control: no-cache');
        readfile($f); exit;
    }
    http_response_code(404); exit;
}

// ---------------- Exportar base de datos MySQL: ?export_db=<nombre> ----------------
if (isset($_GET['export_db'])) {
    $db = (string)$_GET['export_db'];
    $dumpExe = $ROOT.'/bin/mariadb/bin/mariadb-dump.exe';
    if (!valid_dbname($db)) { http_response_code(400); exit('Nombre de base de datos no válido.'); }
    if (!is_file($dumpExe)) { http_response_code(503); exit('MariaDB no está instalado.'); }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$db.'-'.date('Y-m-d_His').'.sql"');
    $rootPass = mysql_root_pass($ROOT);
    $passArg = $rootPass !== '' ? ' --password='.escapeshellarg($rootPass) : '';
    $cmd = '"'.$dumpExe.'" --host=127.0.0.1 --port=3306 --user=root'.$passArg.' --single-transaction --routines --events '.escapeshellarg($db);
    passthru($cmd);
    exit;
}

// ---------------- AJAX: un nivel del arbol de archivos de un proyecto (carga perezosa) ----------------
if (($_GET['ajax'] ?? '') === 'tree') {
    header('Content-Type: application/json; charset=utf-8');
    $tName = (string)($_GET['name'] ?? '');
    $tRel  = (string)($_GET['rel'] ?? '');
    $tCfg   = read_json($CFG_FILE) ?: ['sites'=>[]];
    $tSites = $tCfg['sites'] ?? [];
    if (!valid_name($tName) || !isset($tSites[$tName])) { echo json_encode(['error'=>'Proyecto no válido.']); exit; }
    $tBase = realpath(project_dir($WWW, $tSites[$tName], $tName));
    if ($tBase === false) { echo json_encode(['error'=>'No se encontró el proyecto.']); exit; }
    $tTarget = realpath($tBase.'/'.$tRel);
    $tBaseN = rtrim(str_replace('\\','/',$tBase),'/');
    $tTargetN = $tTarget!==false ? rtrim(str_replace('\\','/',$tTarget),'/') : '';
    $tInside = $tTarget!==false && is_dir($tTarget) && ($tTargetN===$tBaseN || strpos($tTargetN, $tBaseN.'/')===0);
    if (!$tInside) { echo json_encode(['error'=>'Ruta no válida.']); exit; }
    $tCount = 0;
    echo json_encode(['html'=>tree_node_html($tTarget, $tRel, true, $tCount, 2000)]);
    exit;
}

// ---------------- AJAX: leer un archivo del proyecto para el editor de codigo ----------------
if (($_GET['ajax'] ?? '') === 'file_read') {
    header('Content-Type: application/json; charset=utf-8');
    $fName = (string)($_GET['name'] ?? '');
    $fRel  = (string)($_GET['rel'] ?? '');
    $fCfg   = read_json($CFG_FILE) ?: ['sites'=>[]];
    $fSites = $fCfg['sites'] ?? [];
    if (!valid_name($fName) || !isset($fSites[$fName])) { echo json_encode(['error'=>'Proyecto no válido.']); exit; }
    $fBase = realpath(project_dir($WWW, $fSites[$fName], $fName));
    if ($fBase === false) { echo json_encode(['error'=>'No se encontró el proyecto.']); exit; }
    $fTarget = realpath($fBase.'/'.$fRel);
    $fBaseN = rtrim(str_replace('\\','/',$fBase),'/');
    $fTargetN = $fTarget!==false ? rtrim(str_replace('\\','/',$fTarget),'/') : '';
    $fInside = $fTarget!==false && is_file($fTarget) && ($fTargetN===$fBaseN || strpos($fTargetN, $fBaseN.'/')===0);
    if (!$fInside) { echo json_encode(['error'=>'Ruta no válida.']); exit; }
    if (filesize($fTarget) > 2*1024*1024) { echo json_encode(['error'=>'Archivo demasiado grande para editar aquí (>2 MB).']); exit; }
    $fData = @file_get_contents($fTarget);
    if ($fData === false) { echo json_encode(['error'=>'No se pudo leer el archivo.']); exit; }
    if (strpos($fData, "\0") !== false) { echo json_encode(['error'=>'Parece un archivo binario: no se puede editar como texto.']); exit; }
    echo json_encode(['content'=>$fData]);
    exit;
}

// ---------------- AJAX: guardar un archivo editado desde el arbol del proyecto ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'file_write') {
    header('Content-Type: application/json; charset=utf-8');
    $fName = (string)($_POST['name'] ?? '');
    $fRel  = (string)($_POST['rel'] ?? '');
    $fContent = (string)($_POST['content'] ?? '');
    $fCfg   = read_json($CFG_FILE) ?: ['sites'=>[]];
    $fSites = $fCfg['sites'] ?? [];
    if (!valid_name($fName) || !isset($fSites[$fName])) { echo json_encode(['error'=>'Proyecto no válido.']); exit; }
    $fBase = realpath(project_dir($WWW, $fSites[$fName], $fName));
    if ($fBase === false) { echo json_encode(['error'=>'No se encontró el proyecto.']); exit; }
    $fTarget = realpath($fBase.'/'.$fRel);
    $fBaseN = rtrim(str_replace('\\','/',$fBase),'/');
    $fTargetN = $fTarget!==false ? rtrim(str_replace('\\','/',$fTarget),'/') : '';
    $fInside = $fTarget!==false && is_file($fTarget) && ($fTargetN===$fBaseN || strpos($fTargetN, $fBaseN.'/')===0);
    if (!$fInside) { echo json_encode(['error'=>'Ruta no válida.']); exit; }
    if (strlen($fContent) > 2*1024*1024) { echo json_encode(['error'=>'Contenido demasiado grande (>2 MB).']); exit; }
    if (@file_put_contents($fTarget, $fContent) === false) { echo json_encode(['error'=>'No se pudo guardar el archivo (¿permisos?).']); exit; }
    echo json_encode(['ok'=>true]);
    exit;
}

// ---------------- Endpoints AJAX de la terminal (devuelven JSON, no PRG) ----------------
$__ta = $_REQUEST['action'] ?? '';
if ($__ta==='term_run' || $__ta==='term_poll' || $__ta==='term_stop') {
    header('Content-Type: application/json; charset=utf-8');
    $reply = function($o){ echo json_encode($o); exit; };

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
        $cwd   = term_get_cwd($ROOT,$sid);
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
            $sh->Run($launch, 0, false);   // ventana oculta, sin esperar (no bloquea Apache)
        } catch (Throwable $e) {
            $reply(['error'=>'No se pudo lanzar el comando: '.$e->getMessage()]);
        }
        $reply(['runid'=>$runid, 'cwd'=>$cwd]);
    }

    if ($__ta==='term_poll') {
        $runid = $_REQUEST['runid'] ?? '';
        if (!preg_match('/^[a-f0-9]{16}$/', $runid)) { $reply(['error'=>'runid no válido.']); }
        $off  = max(0, (int)($_REQUEST['off'] ?? 0));
        $outf = $dir.'/'.$runid.'.out';
        $data = '';
        if (is_file($outf)) {
            $fh = @fopen($outf,'rb');
            if ($fh) { if ($off>0) fseek($fh,$off); $data=stream_get_contents($fh); fclose($fh); }
        }
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
        }
        $reply(['data'=>$data, 'off'=>$newoff, 'done'=>$done, 'code'=>$code, 'cwd'=>$cwd]);
    }

    if ($__ta==='term_stop') {
        $runid = $_REQUEST['runid'] ?? '';
        if (!preg_match('/^[a-f0-9]{16}$/', $runid)) { $reply(['error'=>'runid no válido.']); }
        // matar el arbol de procesos por el titulo de ventana unico del wrapper
        @exec('taskkill /F /T /FI "WINDOWTITLE eq lua_'.$runid.'*" 2>&1');
        // al matar el proceso, el wrapper no escribe la marca de fin: la añadimos
        // nosotros para que el polling del panel termine limpio (código 130 = interrumpido).
        $outf = $dir.'/'.$runid.'.out';
        if (is_file($outf)) { @file_put_contents($outf, "\r\n[detenido]\r\n__LUA_DONE__130", FILE_APPEND); }
        $reply(['stopped'=>true]);
    }
}

// ---------------- POST (patron PRG) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $cfg = read_json($CFG_FILE) ?: ['defaultPhp'=>'8.4','tld'=>'lua.test','sites'=>[]];
    if(!isset($cfg['sites'])||!is_array($cfg['sites'])) $cfg['sites']=[];
    $vers = php_versions($PHP_BASE);
    $tab='proyectos'; $msg=''; $redirName=null;

    if ($action === 'shutdown') {
        // Apagar el servidor. Como al parar Apache muere este propio PHP, lanzamos
        // 'lua.ps1 stop' en un proceso desatendido (con un respiro para que esta
        // respuesta llegue al navegador) y devolvemos una página de despedida.
        $luaWin = str_replace('/', '\\', $ROOT).'\\lua.ps1';
        $cmdf = $ROOT.'/tmp/_shutdown.cmd';
        @mkdir($ROOT.'/tmp', 0777, true);
        $wr  = "@echo off\r\n";
        $wr .= "ping -n 3 127.0.0.1 >NUL\r\n";  // ~2s para que el navegador reciba la página
        $wr .= "powershell -NoProfile -ExecutionPolicy Bypass -File \"".$luaWin."\" stop\r\n";
        @file_put_contents($cmdf, $wr);
        try { $sh = new COM('WScript.Shell'); $sh->Run('cmd /c "'.str_replace('/', '\\', $cmdf).'"', 0, false); } catch (Throwable $e) {}
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><title>lua-server — apagando</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<style>
  :root{ --bg:#0f1117; --card:#1a1d27; --line:#2a2f3d; --tx:#e6e8ee; --mut:#8b90a0; --ac:#6ea8fe; }
  @media (prefers-color-scheme:light){ :root{ --bg:#f4f6fb; --card:#fff; --line:#e3e7f0; --tx:#1a1d27; --mut:#5b6172; --ac:#2b6cff; } }
  html,body{height:100%;margin:0}
  body{background:var(--bg);color:var(--tx);font-family:system-ui,'Segoe UI',Roboto,sans-serif;display:flex;align-items:center;justify-content:center}
  .box{text-align:center;max-width:420px;padding:32px}
  .ic{width:56px;height:56px;border-radius:999px;background:rgba(139,144,160,.15);color:var(--mut);display:flex;align-items:center;justify-content:center;margin:0 auto 18px}
  h1{font-size:20px;margin:0 0 10px}
  p{color:var(--mut);font-size:14px;line-height:1.5;margin:0 0 6px}
  code{background:rgba(128,128,128,.16);padding:2px 7px;border-radius:5px;font-size:13px}
</style></head><body>
  <div class="box">
    <div class="ic"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg></div>
    <h1>Apagando el servidor…</h1>
    <p>Apache, el watcher, Mailpit y MySQL se están deteniendo. Esta página ya no responderá.</p>
    <p style="margin-top:14px">Para volver a arrancar, en una terminal:<br><code>.\lua.ps1 start</code></p>
  </div>
</body></html>
        <?php
        exit;
    }

    if ($action === 'restart') {
        // Reiniciar Apache. El proceso PHP muere al reiniciar; lo lanzamos desatendido
        // y devolvemos una página que se recarga sola cuando Apache vuelve.
        $luaWin = str_replace('/', '\\', $ROOT).'\\lua.ps1';
        $cmdf = $ROOT.'/tmp/_restart.cmd';
        @mkdir($ROOT.'/tmp', 0777, true);
        $wr  = "@echo off\r\n";
        $wr .= "ping -n 2 127.0.0.1 >NUL\r\n";  // deja llegar esta respuesta antes de tumbar Apache
        $wr .= "powershell -NoProfile -ExecutionPolicy Bypass -File \"".$luaWin."\" restart\r\n";
        @file_put_contents($cmdf, $wr);
        try { $sh = new COM('WScript.Shell'); $sh->Run('cmd /c "'.str_replace('/', '\\', $cmdf).'"', 0, false); } catch (Throwable $e) {}
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><title>lua-server — reiniciando</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<meta http-equiv="refresh" content="9;url=?tab=proyectos">
<style>
  :root{ --bg:#0f1117; --card:#1a1d27; --line:#2a2f3d; --tx:#e6e8ee; --mut:#8b90a0; --ac:#6ea8fe; }
  @media (prefers-color-scheme:light){ :root{ --bg:#f4f6fb; --card:#fff; --line:#e3e7f0; --tx:#1a1d27; --mut:#5b6172; --ac:#2b6cff; } }
  html,body{height:100%;margin:0}
  body{background:var(--bg);color:var(--tx);font-family:system-ui,'Segoe UI',Roboto,sans-serif;display:flex;align-items:center;justify-content:center}
  .box{text-align:center;max-width:420px;padding:32px}
  .ic{width:56px;height:56px;border-radius:999px;background:rgba(110,168,254,.14);color:var(--ac);display:flex;align-items:center;justify-content:center;margin:0 auto 18px}
  .ic svg{animation:spin 1.1s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
  h1{font-size:20px;margin:0 0 10px}
  p{color:var(--mut);font-size:14px;line-height:1.5;margin:0}
</style></head><body>
  <div class="box">
    <div class="ic"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/></svg></div>
    <h1>Reiniciando el servidor…</h1>
    <p>Apache está reiniciándose. Esta página se recargará sola en unos segundos.</p>
  </div>
</body></html>
        <?php
        exit;
    }

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
        else {
            $id = $name.'-'.time();
            $withdb = ($_POST['withdb'] ?? '') === '1';
            $job = ['id'=>$id,'name'=>$name,'php'=>$php,'type'=>$type,'url'=>$url,'withdb'=>$withdb];
            @mkdir($ROOT.'/tmp/jobs', 0777, true);
            file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
            $labels=['blank'=>'PHP en blanco','laravel'=>'Laravel','wordpress'=>'WordPress','symfony'=>'Symfony','slim'=>'Slim','git'=>'clon de Git'];
            $msg='job:Creando "'.$name.'" ('.$labels[$type].')… mira el progreso abajo.';
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
        else {
            $entry = ['php'=>$php, 'path'=>$pathN];
            if ($domain!=='') $entry['domain']=$domain;
            $cfg['sites'][$name]=$entry; write_json($CFG_FILE,$cfg); lua_apply();
            $dom = $domain!=='' ? $domain : $name.'.'.($cfg['tld']??'lua.test');
            $hasPublic = is_dir($pathN.'/public');
            $msg='applied:Proyecto externo "'.$name.'" registrado -> http://'.$dom.' [PHP '.$php.']'.($hasPublic?' (docroot: public/)':'').'. Si el dominio no abre, pulsa "Sincronizar dominios".';
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
    elseif ($action === 'clearlog') {
        $lf = safe_logname($_POST['log'] ?? '');
        if ($lf && is_file($ROOT.'/logs/apache/'.$lf)) { @file_put_contents($ROOT.'/logs/apache/'.$lf, ''); $msg='info:Log '.$lf.' vaciado.'; }
        $tab='logs';
        header('Location: ?tab=logs&log='.urlencode($lf)); exit;
    }
    elseif ($action === 'deletelog') {
        $lf = safe_logname($_POST['log'] ?? '');
        $tab='logs';
        if ($lf && is_file($ROOT.'/logs/apache/'.$lf)) { @unlink($ROOT.'/logs/apache/'.$lf); $msg='applied:Log '.$lf.' eliminado.'; }
        else { $msg='error:Ese log ya no existe.'; }
        header('Location: ?tab=logs&msg='.urlencode($msg)); exit;
    }
    elseif ($action === 'switch') {
        $name=$_POST['name']??''; $php=$_POST['php']??'';
        if (isset($cfg['sites'][$name]) && (!$vers || in_array($php,$vers,true))) {
            $cfg['sites'][$name]['php']=$php; write_json($CFG_FILE,$cfg); lua_apply();
            $msg='applied:"'.$name.'" ahora usa PHP '.$php.'.';
        } else { $msg='error:No se pudo cambiar la versión.'; }
    }
    elseif ($action === 'delete') {
        $name=$_POST['name']??'';
        if (!isset($cfg['sites'][$name])) { $msg='error:No existe ese proyecto.'; }
        elseif (project_locked("$WWW/$name")) { $msg='error:"'.$name.'" está bloqueado (tiene un archivo .lua). Desbloquéalo antes de eliminarlo.'; }
        else {
            unset($cfg['sites'][$name]); write_json($CFG_FILE,$cfg); lua_apply();
            $msg='applied:Proyecto "'.$name.'" eliminado (la carpeta www\\'.$name.' se conserva).';
        }
    }
    elseif ($action === 'integrate') {
        $name=$_POST['name']??'';
        $php=$_POST['php']??($cfg['defaultPhp']??'8.4');
        if (!valid_name($name)) { $msg='error:Nombre de carpeta no válido.'; }
        elseif (isset($cfg['sites'][$name])) { $msg='error:"'.$name.'" ya está registrado.'; }
        elseif (!is_dir("$WWW/$name")) { $msg='error:No existe la carpeta www\\'.$name.'.'; }
        elseif ($vers && !in_array($php,$vers,true)) { $msg='error:Versión de PHP no instalada.'; }
        else {
            $site = ['php'=>$php];
            $type = detect_project_type("$WWW/$name");
            if ($type) { $site['type'] = $type; }
            $cfg['sites'][$name] = $site; write_json($CFG_FILE,$cfg); lua_apply();
            $typeLabel = ['wordpress'=>'WordPress','laravel'=>'Laravel','symfony'=>'Symfony'][$type] ?? null;
            $msg='applied:"'.$name.'" integrado'.($typeLabel?' ('.$typeLabel.' detectado)':'').'. Sincroniza dominios si no abre.';
        }
    }
    elseif ($action === 'integrate_all') {
        $php = $_POST['php'] ?? ($cfg['defaultPhp'] ?? '8.4');
        $todo = unregistered_projects($WWW, $cfg['sites']);
        foreach ($todo as $name) {
            $site = ['php'=>$php];
            $type = detect_project_type("$WWW/$name");
            if ($type) { $site['type'] = $type; }
            $cfg['sites'][$name] = $site;
        }
        if ($todo) { write_json($CFG_FILE,$cfg); lua_apply(); $msg='applied:'.count($todo).' proyecto(s) integrado(s). Sincroniza dominios si no abren.'; }
        else { $msg='error:No había nada que integrar.'; }
    }
    elseif ($action === 'detect_types') {
        $tab = 'proyectos';
        $n = 0;
        foreach ($cfg['sites'] as $sName => &$sInfo) {
            if (!is_array($sInfo)) { $sInfo = ['php'=>$sInfo]; }
            if (!empty($sInfo['type'])) continue;
            $sDir = project_dir($WWW, $sInfo, $sName);
            $t = detect_project_type($sDir);
            if ($t) { $sInfo['type'] = $t; $n++; }
        }
        unset($sInfo);
        if ($n > 0) { write_json($CFG_FILE,$cfg); $msg='applied:'.$n.' proyecto(s) detectado(s) (WordPress/Laravel/Symfony).'; }
        else { $msg='info:No se detectó ningún framework en los proyectos sin tipo.'; }
    }
    elseif ($action === 'delete_unregistered') {
        $name=$_POST['name']??'';
        $dir = "$WWW/$name";
        if (!valid_name($name) || isset($cfg['sites'][$name])) { $msg='error:Nombre no válido.'; }
        elseif (!is_dir($dir)) { clearstatcache(); $msg='info:Esa carpeta ya no existe en www\\.'; }
        elseif (project_locked($dir)) { $msg='error:"'.$name.'" tiene un archivo .lua que la protege. Quítalo a mano para poder borrarla.'; }
        else {
            rrmdir($dir); clearstatcache();
            $msg='applied:Carpeta "'.$name.'" eliminada de www\\.';
        }
    }
    elseif ($action === 'ftp_save') {
        $name = $_POST['name'] ?? '';
        $tab = 'proyecto'; $redirName = $name;
        if (!valid_name($name) || !isset($cfg['sites'][$name])) { $msg = 'error:Proyecto no válido.'; }
        else {
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
        $conf = ftp_config_get($ROOT, $name);
        if (!valid_name($name) || !isset($cfg['sites'][$name])) { $msg = 'error:Proyecto no válido.'; }
        elseif (!$conf || $conf['host'] === '') { $msg = 'error:Configura primero el host/usuario FTP.'; }
        else {
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
    elseif ($action === 'cover') {
        $name=$_POST['name']??'';
        if (!isset($cfg['sites'][$name])) { $msg='error:No existe ese proyecto.'; }
        elseif (empty($_FILES['img']) || ($_FILES['img']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) {
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
                $dir=$ROOT.'/data/covers'; @mkdir($dir,0777,true);
                foreach (cover_exts() as $e) @unlink($dir.'/'.$name.'.'.$e); // quitar la anterior
                if (@move_uploaded_file($tmp, $dir.'/'.$name.'.'.$ext)) { $msg='applied:Carátula de "'.$name.'" actualizada.'; }
                else { $msg='error:No se pudo guardar la imagen.'; }
            }
        }
    }
    elseif ($action === 'cover_remove') {
        $name=$_POST['name']??'';
        if (isset($cfg['sites'][$name])) {
            foreach (cover_exts() as $e) @unlink($ROOT.'/data/covers/'.$name.'.'.$e);
            $msg='applied:Carátula de "'.$name.'" eliminada.';
        } else { $msg='error:No existe ese proyecto.'; }
    }
    elseif ($action === 'lock') {
        $name=$_POST['name']??'';
        if (isset($cfg['sites'][$name]) && is_dir("$WWW/$name")) {
            $marker = "$WWW/$name/".LUA_LOCK_MARKER;
            @file_put_contents($marker, "; lua-server :: proyecto bloqueado\r\n; Mientras exista un archivo .lua en la raiz de este proyecto,\r\n; no se puede eliminar desde el panel (http://localhost).\r\n");
            if (is_file($marker)) { $msg='applied:Proyecto "'.$name.'" bloqueado. No se podrá eliminar mientras exista el archivo .lua.'; }
            else { $msg='error:No se pudo crear el archivo de bloqueo en www\\'.$name.'.'; }
        } else { $msg='error:No existe ese proyecto.'; }
    }
    elseif ($action === 'unlock') {
        $name=$_POST['name']??'';
        if (isset($cfg['sites'][$name]) && is_dir("$WWW/$name")) {
            $marker = "$WWW/$name/".LUA_LOCK_MARKER;
            if (is_file($marker)) @unlink($marker);
            if (project_locked("$WWW/$name")) { $msg='info:Quité el marcador, pero "'.$name.'" sigue bloqueado: hay otro archivo .lua en su carpeta.'; }
            else { $msg='applied:Proyecto "'.$name.'" desbloqueado. Ya se puede eliminar.'; }
        } else { $msg='error:No existe ese proyecto.'; }
    }
    elseif ($action === 'phpini') {
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
            $httpsOn = is_file($ROOT.'/config/https.on');
            if ($httpsOn) { @file_put_contents($ROOT.'/tmp/https.flag',(string)time()); }
            $msg = 'applied:Dominio cambiado a "'.$new.'". Pulsa "Sincronizar dominios" para que Windows lo reconozca.'.($httpsOn?' El certificado HTTPS se está regenerando.':'');
        }
    }
    elseif ($action === 'https') {
        $tab='config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) { @file_put_contents($ROOT.'/tmp/https.flag',(string)time()); $msg='info:Activando HTTPS: acepta el aviso de Windows (UAC) para instalar la CA. Recarga en unos segundos.'; }
        else { @unlink($ROOT.'/config/https.on'); lua_apply(); $msg='applied:HTTPS desactivado.'; }
    }
    elseif ($action === 'mailpit') {
        $tab='config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) {
            @file_put_contents($ROOT.'/config/mailpit.on','1');
            if (is_file($ROOT.'/bin/mailpit/mailpit.exe')) { lua_apply(); $msg='applied:Mailpit activado. Buzón en http://localhost:8025'; }
            else {
                $id='mailpit-'.time();
                $job=['id'=>$id,'name'=>'mailpit','php'=>($cfg['defaultPhp']??'8.4'),'type'=>'mailpit','url'=>''];
                @mkdir($ROOT.'/tmp/jobs',0777,true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Descargando y activando Mailpit…';
            }
        } else { @unlink($ROOT.'/config/mailpit.on'); lua_apply(); $msg='applied:Mailpit desactivado.'; }
    }
    elseif ($action === 'mariadb') {
        $tab = ($_POST['from_tab'] ?? '') === 'proyectos' ? 'proyectos' : 'config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) {
            @file_put_contents($ROOT.'/config/mariadb.on','1');
            if (is_file($ROOT.'/bin/mariadb/bin/mysqld.exe')) {
                $msg='info:MySQL (MariaDB) activándose. Conecta en 127.0.0.1:3306, usuario root, sin contraseña.';
            } else {
                $id='mariadb-'.time();
                $job=['id'=>$id,'name'=>'mariadb','php'=>($cfg['defaultPhp']??'8.4'),'type'=>'mariadb','url'=>''];
                @mkdir($ROOT.'/tmp/jobs',0777,true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Descargando e instalando MariaDB (11.8 LTS, ~90 MB)… puede tardar un par de minutos.';
            }
        } else { @unlink($ROOT.'/config/mariadb.on'); $msg='info:MySQL (MariaDB) desactivándose.'; }
    }
    elseif ($action === 'startup') {
        $tab='config';
        $enable = ($_POST['enable'] ?? '') === '1';
        // Instalar/quitar un servicio de Windows y una tarea programada requiere admin
        // siempre (activar Y desactivar); el watcher lo recoge y se relanza elevado (UAC).
        if ($enable) { @file_put_contents($ROOT.'/tmp/startup-on.flag',(string)time()); $msg='info:Activando arranque con Windows: acepta el aviso de Windows (UAC). Instala el servicio de Apache y la tarea programada del watcher.'; }
        else { @file_put_contents($ROOT.'/tmp/startup-off.flag',(string)time()); $msg='info:Desactivando arranque con Windows: acepta el aviso de Windows (UAC).'; }
    }
    elseif ($action === 'db_create') {
        $tab = 'bd';
        $db = trim($_POST['dbname'] ?? '');
        if (!valid_dbname($db)) { $msg='error:Nombre de base de datos no válido (letras, números, _).'; }
        else {
            try { mysql_pdo()->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                  $msg='info:Base de datos "'.$db.'" creada.'; }
            catch (Throwable $e) { $msg='error:No se pudo crear: '.$e->getMessage(); }
        }
    }
    elseif ($action === 'db_drop') {
        $tab = 'bd';
        $db = $_POST['dbname'] ?? '';
        if (!valid_dbname($db)) { $msg='error:Nombre de base de datos no válido.'; }
        else {
            try { mysql_pdo()->exec('DROP DATABASE `'.$db.'`');
                  $msg='info:Base de datos "'.$db.'" eliminada.'; }
            catch (Throwable $e) { $msg='error:No se pudo eliminar: '.$e->getMessage(); }
        }
    }
    elseif ($action === 'db_import') {
        $tab = 'bd';
        $db = $_POST['dbname'] ?? '';
        $mysqlExe = $ROOT.'/bin/mariadb/bin/mariadb.exe';
        if (!valid_dbname($db)) { $msg='error:Nombre de base de datos no válido.'; }
        elseif (empty($_FILES['sqlfile']) || ($_FILES['sqlfile']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $msg='error:No se recibió el archivo .sql.'; }
        elseif (!is_file($mysqlExe)) { $msg='error:MariaDB no está instalado.'; }
        else {
            $rootPass = mysql_root_pass($ROOT);
            $passArg = $rootPass !== '' ? ' --password='.escapeshellarg($rootPass) : '';
            $cmd = '"'.$mysqlExe.'" --host=127.0.0.1 --port=3306 --user=root'.$passArg.' '.escapeshellarg($db);
            $descriptors = [0=>['file',$_FILES['sqlfile']['tmp_name'],'r'], 1=>['pipe','w'], 2=>['pipe','w']];
            $proc = @proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) { $msg='error:No se pudo ejecutar mariadb.exe.'; }
            else {
                $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
                $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
                $code = proc_close($proc);
                if ($code === 0) { $msg='info:Importado en "'.$db.'" correctamente.'; }
                else { $msg='error:Fallo al importar: '.trim($err ?: $out ?: 'código '.$code); }
            }
        }
    }
    elseif ($action === 'mysql_root_pass') {
        $tab='bd';
        $new = (string)($_POST['new_pass'] ?? '');
        try {
            mysql_pdo()->exec("ALTER USER CURRENT_USER() IDENTIFIED BY ".mysql_pdo()->quote($new));
            if ($new === '') { @unlink($ROOT.'/config/mysql_root.pass'); }
            else { @file_put_contents($ROOT.'/config/mysql_root.pass', $new); }
            $msg = $new===''? 'applied:Contraseña de root eliminada.' : 'applied:Contraseña de root actualizada.';
        } catch (Throwable $e) { $msg='error:No se pudo cambiar la contraseña: '.$e->getMessage(); }
    }
    elseif ($action === 'mysql_user_create') {
        $tab='bd';
        $u = trim($_POST['username'] ?? '');
        $h = $_POST['host'] ?? '127.0.0.1';
        $p = (string)($_POST['password'] ?? '');
        $scope = ($_POST['scope'] ?? 'all') === 'db' ? 'db' : 'all';
        $db = trim($_POST['dbname'] ?? '');
        if (!valid_mysql_user($u)) { $msg='error:Usuario no válido (letras, números, _).'; }
        elseif (!valid_mysql_host($h)) { $msg='error:Host no válido.'; }
        elseif ($p === '') { $msg='error:La contraseña no puede estar vacía.'; }
        elseif ($scope === 'db' && !valid_dbname($db)) { $msg='error:Nombre de base de datos no válido.'; }
        else {
            try {
                $pdo = mysql_pdo();
                $pdo->exec("CREATE USER '".$u."'@'".$h."' IDENTIFIED BY ".$pdo->quote($p));
                $target = $scope==='db' ? ('`'.$db.'`.*') : '*.*';
                $pdo->exec("GRANT ALL PRIVILEGES ON ".$target." TO '".$u."'@'".$h."'");
                $pdo->exec('FLUSH PRIVILEGES');
                $msg='applied:Usuario "'.$u.'@'.$h.'" creado.';
            } catch (Throwable $e) { $msg='error:No se pudo crear el usuario: '.$e->getMessage(); }
        }
    }
    elseif ($action === 'mysql_user_delete') {
        $tab='bd';
        $u = trim($_POST['username'] ?? '');
        $h = $_POST['host'] ?? '';
        if (!valid_mysql_user($u) || !valid_mysql_host($h)) { $msg='error:Usuario u host no válido.'; }
        else {
            try { mysql_pdo()->exec("DROP USER '".$u."'@'".$h."'"); $msg='applied:Usuario "'.$u.'@'.$h.'" eliminado.'; }
            catch (Throwable $e) { $msg='error:No se pudo eliminar: '.$e->getMessage(); }
        }
    }
    elseif ($action === 'terminal') {
        $tab='config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) { @file_put_contents($ROOT.'/config/terminal.on','1'); $msg='applied:Terminal activada. Ejecuta comandos desde la pestaña Terminal.'; }
        else { @unlink($ROOT.'/config/terminal.on'); $msg='applied:Terminal desactivada.'; }
    }

    header('Location: ?tab='.$tab.(isset($ver)?'&ver='.urlencode($ver):'').($redirName?'&name='.urlencode($redirName):'').'&msg='.urlencode($msg));
    exit;
}

// ---------------- GET (render) ----------------
$cfg = read_json($CFG_FILE) ?: ['defaultPhp'=>'8.4','tld'=>'lua.test','sites'=>[]];
$tld = $cfg['tld'] ?? 'lua.test';
$sites = $cfg['sites'] ?? [];
// phpMyAdmin es una herramienta de la plataforma (enlazada en "Bases de datos"),
// no un proyecto del usuario: se registra igual (vhost, php.ini...) pero se oculta
// de la lista de proyectos. Sigue contando como "registrado" para unregistered_projects().
$sitesView = array_diff_key($sites, ['phpmyadmin'=>true]);
$phpmyadminDom = !empty($sites['phpmyadmin']['domain']) ? $sites['phpmyadmin']['domain'] : 'phpmyadmin.'.($cfg['tld'] ?? 'lua.test');
$defaultPhp = $cfg['defaultPhp'] ?? '8.4';
$vers = php_versions($PHP_BASE);
// mod_fcgid mantiene procesos php-cgi vivos entre peticiones: sin esto, is_dir()
// puede seguir devolviendo cache de una carpeta borrada por fuera del panel.
clearstatcache();
$unreg = unregistered_projects($WWW, $sites);
$tab = $_GET['tab'] ?? 'proyectos';
$msg = $_GET['msg'] ?? '';
[$mtype,$mtext] = array_pad(explode(':',$msg,2),2,'');
$curPhp = PHP_VERSION;
$jobs = read_jobs($ROOT.'/tmp/jobs');
$anyJobRun = false; foreach($jobs as $jj){ if(in_array(($jj['state']??''),['running','queued'],true)){$anyJobRun=true;break;} }
$watcherAlive = watcher_alive($ROOT);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<title>lua-server</title>
<?php if ($mtype==='applied'): ?><script>setTimeout(function(){location.href='?tab=<?= e($tab) ?>';},4200);</script><?php endif; ?>
<?php if ($mtype==='info'): ?><script>setTimeout(function(){location.href='?tab=<?= e($tab) ?>';},7000);</script><?php endif; ?>
<?php if (($tab==='proyectos' || $tab==='config' || $tab==='proyecto') && ($anyJobRun || $mtype==='job')): ?><meta http-equiv="refresh" content="3"><?php endif; ?>
<?php if ($tab==='logs' && (($_GET['refresh']??'')==='1')): ?><meta http-equiv="refresh" content="4"><?php endif; ?>
<style>
  :root{
    --bg:#0f1117; --card:#1a1d27; --line:#2a2f3d; --tx:#e6e8ee; --mut:#8b90a0;
    --ac:#6ea8fe; --ac-hover:#5a97f0; --ok:#3fb950; --warn:#d29922; --err:#f85149; --err-dark:#b3261e; --in:#11141c;
    --brand-start:#6ea8fe; --brand-end:#9b6efe;
  }
  @media (prefers-color-scheme:light){
    :root{ --bg:#f4f6fb; --card:#fff; --line:#e3e7f0; --tx:#1a1d27; --mut:#5b6172; --ac:#2b6cff; --ac-hover:#1a5ae8; --in:#fff; }
  }
  *{box-sizing:border-box}
  html{height:100%}
  body{margin:0;height:100vh;overflow:hidden;display:flex;flex-direction:column;background:var(--bg);color:var(--tx);font-family:system-ui,'Segoe UI',Roboto,sans-serif}
  a{color:var(--ac);text-decoration:none}
  a:hover{color:var(--ac-hover)}

  header{display:flex;align-items:center;gap:14px;padding:10px 40px;background:var(--card);border-bottom:1px solid var(--line);flex-shrink:0}
  .logo{width:44px;height:44px;flex-shrink:0;display:flex;align-items:center;justify-content:center}
  .logo img{width:100%;height:100%;display:block}
  h1{margin:0;font-size:19px;font-weight:700;line-height:1.2}
  .sub{color:var(--mut);font-size:12px;margin-top:1px}
  .spacer{flex:1}
  .badges{display:flex;gap:6px;align-items:center;flex-shrink:0}
  .iconbtn{display:flex;align-items:center;justify-content:center;width:34px;height:34px;flex-shrink:0;background:transparent;border:1px solid var(--line);border-radius:8px;color:var(--mut);cursor:pointer;transition:color .12s,border-color .12s,background-color .12s}
  .restartbtn{margin-left:12px}
  .restartbtn:hover{color:var(--ac);border-color:var(--ac);background:rgba(110,168,254,.10)}
  .powerbtn{margin-left:6px}
  .powerbtn:hover{color:var(--err);border-color:var(--err);background:rgba(248,81,73,.10)}

  .tabbar{padding:0 40px;background:var(--card);border-bottom:1px solid var(--line);flex-shrink:0}
  .tabs{display:flex;gap:6px}
  .tabs a{padding:9px 16px;color:var(--mut);text-decoration:none;font-weight:600;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-1px;display:inline-block}
  .tabs a.on{color:var(--ac);border-color:var(--ac)}

  .content{flex:1;overflow-y:auto;padding:28px 40px 48px}

  .card{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:18px 20px;margin-bottom:14px}
  .row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .row .name{font-weight:700;font-size:16px;min-width:150px}
  .row .url{color:var(--mut);font-size:13px} .row .url:hover{color:var(--ac)}

  label{display:block;font-size:12px;color:var(--mut);margin:0 0 4px}
  input,select,textarea{background:var(--in);color:var(--tx);border:1px solid var(--line);border-radius:5px;padding:8px 10px;font-size:14px;font-family:inherit;line-height:1.4}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--ac)}
  textarea{width:100%;min-height:300px;font-family:ui-monospace,Consolas,monospace;font-size:13px;resize:vertical}

  .btn{background:var(--ac);background-image:linear-gradient(135deg,var(--brand-start),var(--brand-end));color:#fff;border:1px solid transparent;border-radius:5px;padding:8px 16px;font-size:14px;font-family:inherit;line-height:1.4;font-weight:600;cursor:pointer;transition:filter .12s,background .12s,color .12s,border-color .12s}
  .btn:hover{filter:brightness(1.08)}
  .btn.sm{padding:4px 10px;font-size:13px}
  .btn.ghost{background-image:linear-gradient(135deg,var(--brand-start),var(--brand-end));border-color:transparent;color:#fff}
  .btn.ghost:hover{filter:brightness(1.08)}
  .btn.danger{background-image:linear-gradient(135deg,var(--err),var(--err-dark));border-color:transparent;color:#fff}
  .btn.danger:hover{filter:brightness(1.08)}

  .dbrow{display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--line)}
  .dbrow:first-of-type{border-top:none}
  .dbrow .dbname{font-weight:600;font-family:ui-monospace,Consolas,monospace;font-size:13px}
  .dbimport{display:flex;align-items:center;gap:6px}
  .dbimport input[type=file]{max-width:150px;font-size:12px;color:var(--mut)}

  .topgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;align-items:start}
  .topgrid .card{margin-bottom:0}
  @media (max-width:1000px){ .topgrid{grid-template-columns:repeat(2,1fr)} }
  @media (max-width:640px){ .topgrid{grid-template-columns:1fr} }

  .pgrid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}
  .pgrid2 .card{margin-bottom:0}
  @media (max-width:900px){ .pgrid2{grid-template-columns:1fr} }

  .sitegrid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:14px}
  .sitecard{position:relative;background:var(--card);border:1px solid var(--line);border-radius:8px;padding:14px 16px;display:flex;flex-direction:column;gap:10px;min-width:0}
  .sitecard.is-locked{border-color:var(--warn)}
  .sitecard .namerow{display:flex;align-items:center;gap:6px;padding-right:84px;min-width:0}
  .sitecard .name{font-weight:700;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0}
  .sitecard .url{color:var(--mut);font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}
  .sitecard .btn{width:100%;text-align:center}
  .sitecard .phpselform{margin:0;flex:0 0 auto;display:flex;align-items:center;gap:6px}
  .sitecard .phpselform .btn{width:auto}
  .sitecard select.phpsel{width:auto;flex:0 0 auto;padding:2px 5px;font-size:11px;border-radius:4px}
  .cardactions{position:absolute;top:10px;right:10px;margin:0;z-index:3;display:flex;gap:6px}
  .lockform{margin:0}
  .lockbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0;background:var(--card);border:1px solid var(--line);border-radius:5px;color:var(--mut);cursor:pointer;transition:color .12s,border-color .12s,background-color .12s}
  .lockbtn:hover{color:var(--ac);border-color:var(--ac)}
  .sitecard.is-locked .lockbtn{color:var(--warn);border-color:var(--warn);background:rgba(210,153,34,.12)}
  .trashbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0;border:1px solid transparent;border-radius:5px;color:#fff;cursor:pointer;background:linear-gradient(135deg,#ff8a80,var(--err));transition:filter .12s,transform .12s}
  .trashbtn:hover{filter:brightness(1.12)}
  .trashbtn:active{transform:scale(.94)}
  .runbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0 0 0 1px;background:var(--card);border:1px solid var(--line);border-radius:5px;color:var(--mut);cursor:pointer;transition:color .12s,border-color .12s}
  .runbtn:hover{color:var(--ac);border-color:var(--ac)}
  .sitecard.is-locked .lockbtn:hover{color:var(--err);border-color:var(--err);background:rgba(248,81,73,.12)}
  .sitecard.unregistered{background:var(--line);border-style:dashed;border-color:var(--line);opacity:.55}
  .sitecard.unregistered .name{color:var(--mut);font-weight:600}
  .exttag{font-size:9px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--ac);background:rgba(110,168,254,.14);padding:1px 5px;border-radius:999px;vertical-align:middle}
  .typetag{font-size:9px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;padding:1px 5px;border-radius:999px;vertical-align:middle}
  .typetag-wordpress{color:var(--ac);background:rgba(110,168,254,.14)}
  .typetag-laravel{color:var(--err);background:rgba(248,81,73,.14)}
  .typetag-symfony{color:var(--ok);background:rgba(63,185,80,.14)}
  .extpath{color:var(--mut);font-size:10px;font-family:ui-monospace,Consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:-4px}
  details.extform{padding:0}
  details.extform>summary{padding:16px 20px;cursor:pointer;font-weight:600;font-size:14px;list-style:none}
  details.extform>summary::-webkit-details-marker{display:none}
  details.extform>summary::before{content:'+ ';color:var(--ac);font-weight:700}
  details.extform[open]>summary::before{content:'– '}
  details.extform>form{padding:0 20px 18px}
  /* Caratula */
  .coverform{margin:-14px -16px 4px;display:block}
  .cover{position:relative;display:block;width:100%;height:78px;padding:0;border:0;cursor:pointer;border-radius:8px 8px 0 0;background-color:var(--in);background-size:cover;background-position:center;background-repeat:no-repeat;border-bottom:1px solid var(--line)}
  .cover.empty{background-image:linear-gradient(135deg,rgba(110,168,254,.08),rgba(155,110,254,.08))}
  .cover-hint{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:6px;font-size:11px;font-weight:600;letter-spacing:.2px}
  .cover.empty .cover-hint{color:var(--mut)}
  .cover.has .cover-hint{color:#fff;background:rgba(10,12,18,.5);opacity:0;transition:opacity .12s}
  .cover.has:hover .cover-hint{opacity:1}
  .coverdel{position:absolute;top:8px;left:8px;margin:0;z-index:3}
  .coverdelbtn{display:flex;align-items:center;justify-content:center;width:22px;height:22px;padding:0;border:0;border-radius:5px;background:rgba(10,12,18,.55);color:#fff;font-size:15px;line-height:1;cursor:pointer}
  .coverdelbtn:hover{background:var(--err)}

  /* Selector de vista (cuadricula/lista) */
  .viewtoggle{display:flex;gap:2px;background:var(--card);border:1px solid var(--line);border-radius:6px;padding:2px}
  .viewtoggle button{display:flex;align-items:center;justify-content:center;width:28px;height:26px;padding:0;border:0;border-radius:4px;background:transparent;color:var(--mut);cursor:pointer;transition:color .12s,background-color .12s}
  .viewtoggle button:hover{color:var(--ac)}
  .viewtoggle button.on{background:var(--ac);color:#fff}

  .sitegrid.list{grid-template-columns:1fr}
  .sitegrid.list .sitecard{flex-direction:row;align-items:center;gap:14px;padding:10px 104px 10px 16px}
  .sitegrid.list .sitecard .coverform,
  .sitegrid.list .sitecard .coverdel,
  .sitegrid.list .sitecard .extpath{display:none}
  .sitegrid.list .sitecard .namerow{flex:0 0 260px;padding-right:0}
  .sitegrid.list .sitecard .url{flex:1}
  .sitegrid.list .sitecard form{width:auto;margin:0}
  .sitegrid.list .sitecard .btn{width:auto}
  .sitegrid.list .sitecard .cardactions{top:50%;transform:translateY(-50%)}

  /* ---------- Modal de confirmacion ---------- */
  .modal-overlay{position:fixed;inset:0;background:rgba(6,7,10,.6);display:flex;align-items:center;justify-content:center;z-index:100;padding:20px}
  .modal-overlay[hidden]{display:none}
  .modal-box{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:26px 26px 22px;max-width:400px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.45)}
  .modal-ic{width:48px;height:48px;border-radius:999px;background:rgba(248,81,73,.12);color:var(--err);display:flex;align-items:center;justify-content:center;font-size:22px;margin:0 auto 14px}
  .modal-ic-off{background:rgba(139,144,160,.15);color:var(--mut)}
  .modal-ic-info{background:rgba(110,168,254,.14);color:var(--ac)}
  .modal-box h3{margin:0 0 10px;font-size:17px;font-weight:700}
  .modal-tx{color:var(--mut);font-size:13px;line-height:1.5;margin:0 0 20px}
  .modal-tx strong{color:var(--tx)}
  .modal-actions{display:flex;gap:8px;justify-content:center}
  .modal-actions .btn{width:auto;padding:8px 18px}

  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px 12px}
  .grid label{min-height:2.6em}
  .tag{display:inline-block;font-size:12px;color:var(--ac);background:rgba(110,168,254,.12);padding:2px 9px;border-radius:999px}

  .banner{padding:11px 15px;border-radius:8px;margin-bottom:16px;font-size:14px;border:1px solid}
  .banner.applied{background:rgba(63,185,80,.12);border-color:var(--ok);color:var(--ok)}
  .banner.info{background:rgba(110,168,254,.12);border-color:var(--ac);color:var(--ac)}
  .banner.error{background:rgba(248,81,73,.12);border-color:var(--err);color:var(--err)}
  .banner.job{background:rgba(210,153,34,.12);border-color:var(--warn);color:var(--warn)}

  .jstate{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;letter-spacing:.3px;display:inline-block}
  .jstate.ok{background:rgba(63,185,80,.15);color:var(--ok)}
  .jstate.err{background:rgba(248,81,73,.15);color:var(--err)}
  .jstate.run{background:rgba(110,168,254,.15);color:var(--ac)}
  .jstate.warn{background:rgba(210,153,34,.15);color:var(--warn)}
  .jstate.orange{background:rgba(247,127,0,.16);color:#f77f00}
  a.jstate{text-decoration:none;cursor:pointer;transition:filter .12s}
  a.jstate:hover{filter:brightness(1.2)}

  .joblog{background:var(--in);border:1px solid var(--line);border-radius:3px;padding:10px;margin:10px 0 0;font-family:ui-monospace,Consolas,monospace;font-size:11px;white-space:pre-wrap;max-height:72px;overflow:auto;color:var(--mut)}
  .logview{background:var(--in);border:1px solid var(--line);border-radius:3px;padding:10px;font-family:ui-monospace,Consolas,monospace;font-size:13px;white-space:pre-wrap;max-height:62vh;overflow:auto;color:var(--mut)}
  .logview .log-fatal{color:var(--err);font-weight:700}
  .logview .log-warning{color:var(--warn);font-weight:600}
  .logview .log-deprecated{color:var(--mut);opacity:.6}
  .logview .log-notice{color:var(--ac)}
  .logview .log-info{color:var(--tx)}

  /* ---------- Editor de codigo (tema propio de CodeMirror, sigue la paleta del panel) ---------- */
  #fileEditorHost .CodeMirror{height:100%;background:var(--in);color:var(--tx);border:1px solid var(--line);border-radius:6px;font-family:ui-monospace,Consolas,'Courier New',monospace;font-size:13px;line-height:1.5}
  .cm-s-lua.CodeMirror{background:var(--in);color:var(--tx)}
  .cm-s-lua .CodeMirror-gutters{background:var(--card);border-right:1px solid var(--line)}
  .cm-s-lua .CodeMirror-linenumber{color:var(--mut)}
  .cm-s-lua .CodeMirror-cursor{border-left:1px solid var(--tx)}
  .cm-s-lua div.CodeMirror-selected{background:rgba(110,168,254,.25)}
  .cm-s-lua .CodeMirror-activeline-background{background:rgba(110,168,254,.06)}
  .cm-s-lua .CodeMirror-matchingbracket{color:var(--ok) !important;outline:1px solid var(--ok)}
  .cm-s-lua .cm-keyword{color:var(--ac);font-weight:600}
  .cm-s-lua .cm-atom{color:var(--ac)}
  .cm-s-lua .cm-number{color:var(--warn)}
  .cm-s-lua .cm-def{color:var(--tx);font-weight:600}
  .cm-s-lua .cm-variable{color:var(--tx)}
  .cm-s-lua .cm-variable-2{color:var(--tx)}
  .cm-s-lua .cm-variable-3{color:var(--ac)}
  .cm-s-lua .cm-property{color:var(--tx)}
  .cm-s-lua .cm-operator{color:var(--mut)}
  .cm-s-lua .cm-comment{color:var(--mut);opacity:.8;font-style:italic}
  .cm-s-lua .cm-string{color:var(--ok)}
  .cm-s-lua .cm-string-2{color:var(--ok)}
  .cm-s-lua .cm-meta{color:var(--mut)}
  .cm-s-lua .cm-tag{color:var(--ac)}
  .cm-s-lua .cm-attribute{color:var(--warn)}
  .cm-s-lua .cm-qualifier{color:var(--warn)}
  .cm-s-lua .cm-builtin{color:var(--ac)}
  .cm-s-lua .cm-bracket{color:var(--mut)}
  .cm-s-lua .cm-error{color:var(--err)}
  .cm-s-lua .cm-invalidchar{color:var(--err)}

  /* ---------- Ficha de proyecto: arbol de archivos ---------- */
  .tree{font-size:13px;font-family:ui-monospace,Consolas,monospace;max-height:60vh;overflow:auto}
  .trow{display:flex;align-items:center;gap:6px;padding:3px 4px;border-radius:4px}
  .trow.tdir{cursor:pointer;user-select:none}
  .trow.tdir:hover{background:var(--in)}
  .trow .tchev{flex:0 0 auto;color:var(--mut);transition:transform .12s}
  .trow.tdir.open>.tchev{transform:rotate(90deg)}
  .trow .ticon{flex:0 0 auto;color:var(--mut)}
  .trow.tfile{color:var(--mut);cursor:pointer;user-select:none}
  .trow.tfile:hover{background:var(--in);color:var(--tx)}
  .tchildren{margin-left:9px;padding-left:11px;border-left:1px dashed var(--line)}
  .tnode-more{color:var(--mut);font-size:12px;padding:4px 0 4px 20px;font-style:italic}

  details{border:1px solid var(--line);border-radius:6px;margin-bottom:12px;background:var(--card);overflow:hidden}
  summary{padding:14px 18px;cursor:pointer;font-weight:700;font-size:16px;list-style:none;display:flex;align-items:center;gap:10px}
  summary::-webkit-details-marker{display:none}
  summary .op{font-size:12px;color:var(--mut);font-weight:500}
  summary .arrow{margin-left:auto;font-size:12px;color:var(--mut)}
  summary .arrow::after{content:'▼'}
  details[open] summary .arrow::after{content:'▲'}
  .pane{padding:4px 18px 18px}

  h2{font-size:12px;color:var(--mut);text-transform:uppercase;letter-spacing:.5px;margin:26px 0 12px}
  code{background:rgba(128,128,128,.16);padding:2px 6px;border-radius:3px;font-size:13px}
  .muted{color:var(--mut);font-size:12px}
  .inline{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap}

  /* ---------- Terminal ---------- */
  .termwrap{display:flex;flex-direction:column;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:var(--in)}
  .termbar{display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--card);border-bottom:1px solid var(--line);font-family:ui-monospace,Consolas,monospace;font-size:12px}
  .termout{padding:12px 14px;font-family:ui-monospace,Consolas,'Courier New',monospace;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word;overflow-y:auto;height:58vh;color:var(--tx)}
  .termin{display:flex;align-items:center;gap:8px;padding:8px 14px;border-top:1px solid var(--line);background:var(--card)}
  .termprompt{color:var(--ac);font-family:ui-monospace,Consolas,monospace;font-weight:700}
  #termcmd{flex:1;background:transparent;border:none;color:var(--tx);font-family:ui-monospace,Consolas,monospace;font-size:13px;padding:4px 0}
  #termcmd:focus{outline:none}
  #termcmd:disabled{opacity:.5}
  .termout .a-prompt{color:var(--ac);font-weight:700}
  .termout .a-bold{font-weight:700}
  .a-k{color:#5b6172}.a-r{color:var(--err)}.a-g{color:var(--ok)}.a-y{color:var(--warn)}
  .a-b{color:var(--ac)}.a-m{color:#9b6efe}.a-c{color:#3fc7d4}.a-w{color:var(--tx)}
  .a-K{color:#8b90a0}.a-R{color:#ff7b72}.a-G{color:#56d364}.a-Y{color:#e3b341}
  .a-B{color:#79c0ff}.a-M{color:#d2a8ff}.a-C{color:#56d4dd}.a-W{color:#fff}

  footer{padding:8px 40px;color:var(--mut);font-size:12px;text-align:center;border-top:1px solid var(--line);flex-shrink:0}
</style>
</head>
<body>
  <header>
    <div class="logo"><img src="assets/logo.svg" alt="lua-server"></div>
    <div>
      <h1>lua-server</h1>
      <div class="sub">Servidor PHP local &middot; <?= count($sitesView) ?> proyecto(s) &middot; PHP: <?= e(implode(', ',$vers)) ?></div>
    </div>
    <div class="spacer"></div>
    <div class="badges">
      <span class="jstate ok">Apache UP</span>
      <span class="jstate <?= $watcherAlive?'run':'err' ?>"><?= $watcherAlive?'Watcher activo':'Watcher inactivo' ?></span>
      <a class="jstate orange" href="http://<?= e($phpmyadminDom) ?>/" target="_blank" title="Abrir phpMyAdmin">phpMyAdmin &#8599;</a>
    </div>
    <button type="button" class="iconbtn restartbtn" title="Reiniciar el servidor" aria-label="Reiniciar el servidor" onclick="luaAskRestart()">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/></svg>
    </button>
    <button type="button" class="iconbtn powerbtn" title="Apagar el servidor" aria-label="Apagar el servidor" onclick="luaAskShutdown()">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
    </button>
  </header>

  <!-- Modal de confirmación de apagado -->
  <div id="offModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseShutdown()">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="offTitle">
      <div class="modal-ic modal-ic-off">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
      </div>
      <h3 id="offTitle">¿Apagar el servidor?</h3>
      <p class="modal-tx">Se detendrán Apache, el watcher, Mailpit y MySQL. Los sitios dejarán de responder hasta que vuelvas a arrancar con <code>.\lua.ps1 start</code>.</p>
      <form method="post" class="modal-actions">
        <input type="hidden" name="action" value="shutdown">
        <button type="button" class="btn ghost" onclick="luaCloseShutdown()">Cancelar</button>
        <button type="submit" class="btn danger">Sí, apagar</button>
      </form>
    </div>
  </div>
  <!-- Modal de confirmación de reinicio -->
  <div id="rebootModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseRestart()">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="rebootTitle">
      <div class="modal-ic modal-ic-info">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/></svg>
      </div>
      <h3 id="rebootTitle">¿Reiniciar el servidor?</h3>
      <p class="modal-tx">Apache se detendrá y volverá a arrancar en unos segundos. Los sitios no responderán durante el reinicio. La página se recargará sola al terminar.</p>
      <form method="post" class="modal-actions">
        <input type="hidden" name="action" value="restart">
        <button type="button" class="btn ghost" onclick="luaCloseRestart()">Cancelar</button>
        <button type="submit" class="btn">Sí, reiniciar</button>
      </form>
    </div>
  </div>
  <script>
    function luaAskShutdown(){ document.getElementById('offModal').hidden=false; document.addEventListener('keydown',luaEscOff); }
    function luaCloseShutdown(){ document.getElementById('offModal').hidden=true; document.removeEventListener('keydown',luaEscOff); }
    function luaAskRestart(){ document.getElementById('rebootModal').hidden=false; document.addEventListener('keydown',luaEscReboot); }
    function luaCloseRestart(){ document.getElementById('rebootModal').hidden=true; document.removeEventListener('keydown',luaEscReboot); }
    function luaEscOff(e){ if(e.key==='Escape') luaCloseShutdown(); }
    function luaEscReboot(e){ if(e.key==='Escape') luaCloseRestart(); }
  </script>

  <div class="tabbar">
    <div class="tabs">
      <a href="?tab=proyectos" class="<?= ($tab==='proyectos'||$tab==='proyecto')?'on':'' ?>">Proyectos</a>
      <a href="?tab=php" class="<?= $tab==='php'?'on':'' ?>">Versiones PHP</a>
      <a href="?tab=bd" class="<?= $tab==='bd'?'on':'' ?>">Bases de datos</a>
      <a href="?tab=logs" class="<?= $tab==='logs'?'on':'' ?>">Logs</a>
      <a href="?tab=terminal" class="<?= $tab==='terminal'?'on':'' ?>">Terminal</a>
      <a href="?tab=config" class="<?= $tab==='config'?'on':'' ?>">Configuración del servidor</a>
    </div>
  </div>

  <div class="content">

  <?php if ($mtext): ?>
    <div class="banner <?= e($mtype) ?>">
      <?= e($mtext) ?>
      <?php if ($mtype==='applied'): ?> <span class="muted">— Apache se está recargando, la página se actualizará sola.</span><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab==='proyectos'): ?>

    <?php $mariaOn = is_file($ROOT.'/config/mariadb.on'); $termOn = is_file($ROOT.'/config/terminal.on'); ?>
    <div class="topgrid">
      <div class="card" style="grid-column:span 2">
        <form method="post">
          <input type="hidden" name="action" value="create">
          <div class="inline">
            <div>
              <label>Nombre del proyecto</label>
              <input name="name" placeholder="micliente" pattern="[a-z0-9][a-z0-9_-]*" required>
            </div>
            <div>
              <label>Tipo</label>
              <select name="type" onchange="document.getElementById('gitrow').style.display=(this.value==='git')?'block':'none'">
                <option value="blank">PHP en blanco</option>
                <option value="laravel">Laravel</option>
                <option value="wordpress">WordPress</option>
                <option value="symfony">Symfony</option>
                <option value="slim">Slim</option>
                <option value="git">Desde Git…</option>
              </select>
            </div>
            <div>
              <label>Versión de PHP</label>
              <select name="php">
                <?php foreach ($vers as $v): ?>
                  <option value="<?= e($v) ?>" <?= $v===$defaultPhp?'selected':'' ?>>PHP <?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn" type="submit">+ Crear</button>
          </div>
          <div id="gitrow" style="display:none;margin-top:12px">
            <label>URL del repositorio Git</label>
            <input name="url" placeholder="https://github.com/usuario/repo.git" style="width:100%">
          </div>
          <?php if ($mariaOn): ?>
            <label style="display:flex;align-items:center;gap:6px;margin-top:12px;font-weight:400;cursor:pointer">
              <input type="checkbox" name="withdb" value="1" checked style="width:auto">
              Crear base de datos MySQL a juego (mismo nombre)
            </label>
          <?php endif; ?>
        </form>
        <div class="muted" style="margin-top:10px">Laravel/Symfony/Slim usan Composer; WordPress se descarga; Git clona el repo (y ejecuta <code>composer install</code> si hay <code>composer.json</code>). Se hace en segundo plano.<?= $mariaOn?' En Laravel, la conexión se escribe sola en el <code>.env</code>.':'' ?></div>
      </div>

      <div class="card" style="display:flex;flex-direction:column">
        <div class="row" style="gap:6px">
          <div style="font-weight:600">Servidor MySQL (MariaDB) <span class="jstate <?= $mariaOn?'ok':'err' ?>" style="margin-left:6px"><?= $mariaOn?'ACTIVO':'INACTIVO' ?></span></div>
          <div class="spacer"></div>
          <a class="lockbtn" href="?tab=bd" title="Configuración de bases de datos" aria-label="Configuración de bases de datos">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          </a>
        </div>
        <div class="muted" style="margin-top:6px">Nativo en <code>127.0.0.1:3306</code>, usuario <code>root</code> <?= mysql_root_pass($ROOT)!==''?'con contraseña':'sin contraseña' ?>.</div>
        <div class="spacer"></div>
        <?php if ($mariaOn): ?>
          <div class="row" style="gap:8px;margin-top:10px">
            <a class="btn ghost sm" href="http://<?= e($phpmyadminDom) ?>/" target="_blank" style="flex:1;text-align:center">phpMyAdmin &#8599;</a>
            <a class="btn ghost sm" href="/adminer.php?server=127.0.0.1&username=root" target="_blank" style="flex:1;text-align:center">Adminer &#8599;</a>
          </div>
          <div class="muted" style="font-size:11px;margin-top:6px">Adminer pide contraseña: crea un usuario con clave para tu proyecto, o usa <code>bin\mariadb\bin\mariadb.exe</code>.</div>
        <?php endif; ?>
        <form method="post" style="margin-top:8px">
          <input type="hidden" name="action" value="mariadb">
          <input type="hidden" name="enable" value="<?= $mariaOn?'0':'1' ?>">
          <input type="hidden" name="from_tab" value="proyectos">
          <button class="btn <?= $mariaOn?'danger':'ghost' ?>" type="submit" style="width:100%"><?= $mariaOn?'Desactivar':'Crear / activar' ?> servidor MySQL</button>
        </form>
      </div>
    </div>

    <details class="card extform">
      <summary>Registrar proyecto existente en otra carpeta del disco <span class="muted">(p.ej. <code>C:\proyectos\ersmportal</code> con dominio propio)</span></summary>
      <form method="post" style="margin-top:14px">
        <input type="hidden" name="action" value="add_external">
        <div class="inline">
          <div>
            <label>Nombre (identificador)</label>
            <input name="name" placeholder="ersmportal" pattern="[a-z0-9][a-z0-9_-]*" required>
          </div>
          <div style="flex:1;min-width:280px">
            <label>Ruta de la carpeta en disco</label>
            <input name="path" placeholder="C:\proyectos\ersmportal" style="width:100%" required>
          </div>
          <div>
            <label>Dominio</label>
            <input name="domain" placeholder="portal.ersm.test">
          </div>
          <div>
            <label>PHP</label>
            <select name="php">
              <?php foreach ($vers as $v): ?>
                <option value="<?= e($v) ?>" <?= $v===$defaultPhp?'selected':'' ?>>PHP <?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn" type="submit">Registrar</button>
        </div>
        <div class="muted" style="margin-top:10px">No copia ni mueve nada: apunta el vhost a esa carpeta (usa <code>public/</code> si existe, para Laravel/Symfony). Si pones un dominio propio, luego pulsa <b>Sincronizar dominios</b> en Configuración del servidor para registrarlo en Windows.</div>
      </form>
    </details>

    <?php if ($jobs): ?>
      <div class="row" style="margin:22px 0 10px">
        <h2 style="margin:0">Tareas</h2>
        <div class="spacer"></div>
        <form method="post"><input type="hidden" name="action" value="clearjobs"><button class="btn ghost sm">Limpiar historial</button></form>
      </div>
      <?php foreach (array_slice($jobs,0,8) as $j):
            $st=$j['state']??'?'; $cls=['done'=>'ok','error'=>'err','running'=>'run','queued'=>'warn'];
            $c=$cls[$st]??'run';
            $tail = in_array($st,['running','error','queued'],true) ? job_log_tail($ROOT, $j['id']??'') : ''; ?>
        <div class="card" style="padding:12px 16px">
          <div class="row">
            <span class="jstate <?= $c ?>"><?= e(strtoupper($st)) ?></span>
            <span style="font-weight:700"><?= e($j['name']??'') ?></span>
            <span class="muted"><?= e($j['type']??'') ?><?= isset($j['time'])?' · '.e($j['time']):'' ?></span>
            <div class="spacer"></div>
            <span class="muted"><?= e($j['msg']??'') ?></span>
          </div>
          <?php if ($tail): ?><pre class="joblog"><?= e($tail) ?></pre><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="row" style="margin-bottom:14px;gap:10px">
      <input type="search" id="projectSearch" placeholder="Buscar proyecto por nombre o dominio…" style="flex:1;max-width:320px">
      <span class="muted" id="projectSearchCount" style="font-size:12px"></span>
    </div>
    <script>
      (function(){
        var input = document.getElementById('projectSearch');
        var countEl = document.getElementById('projectSearchCount');
        if (!input) return;
        function norm(s){ return (s||'').toLowerCase(); }
        function filter(){
          var q = norm(input.value.trim());
          var totalShown = 0;
          ['secProyectos','secUnreg'].forEach(function(secId){
            var sec = document.getElementById(secId);
            if (!sec) return;
            var shown = 0;
            sec.querySelectorAll('.sitecard').forEach(function(card){
              var name = card.querySelector('.name');
              var url = card.querySelector('.url');
              var text = norm((name?name.textContent:'') + ' ' + (url?url.textContent:''));
              var match = !q || text.indexOf(q) !== -1;
              card.style.display = match ? '' : 'none';
              if (match) { shown++; totalShown++; }
            });
            if (q && shown > 0) { sec.open = true; }
          });
          countEl.textContent = q ? (totalShown + ' resultado(s)') : '';
        }
        input.addEventListener('input', filter);
      })();
    </script>

    <?php $sitesSinTipo = 0; foreach ($sitesView as $sInfo) { if (is_array($sInfo) && empty($sInfo['type'])) $sitesSinTipo++; } ?>
    <details class="sectioncollapse" id="secProyectos" open>
      <summary>Proyectos <span class="op">(<?= count($sitesView) ?>)</span>
        <?php if ($sitesSinTipo > 0): ?>
        <form method="post" onclick="event.stopPropagation()" title="Detecta WordPress/Laravel/Symfony en los proyectos ya registrados">
          <input type="hidden" name="action" value="detect_types">
          <button type="submit" class="btn ghost sm">Detectar tipos (<?= $sitesSinTipo ?>)</button>
        </form>
        <?php endif; ?>
        <div class="viewtoggle" onclick="event.stopPropagation()">
          <button type="button" class="viewbtn" data-view="grid" title="Vista de cuadrícula" aria-label="Vista de cuadrícula">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          </button>
          <button type="button" class="viewbtn" data-view="list" title="Vista de lista" aria-label="Vista de lista">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
          </button>
        </div>
        <span class="arrow"></span>
      </summary>
      <div class="pane">
    <?php if (!$sitesView): ?>
      <div class="card muted">Aún no hay proyectos. Crea el primero arriba.</div>
    <?php else: ?>
      <div class="sitegrid">
        <?php foreach ($sitesView as $name => $info):
              $ver = is_array($info)?($info['php']??'?'):$info;
              $dom = (is_array($info) && !empty($info['domain'])) ? $info['domain'] : $name.'.'.$tld;
              $pdir = project_dir($WWW, $info, $name);
              $extPath = (is_array($info) && !empty($info['path'])) ? $info['path'] : null;
              $locked = project_locked($pdir);
              $hasCover = (bool)cover_path($ROOT,$name);
              $hasComposer = is_file($pdir.'/composer.json');
              $hasNpm = is_file($pdir.'/package.json');
              $pType = is_array($info) ? ($info['type'] ?? null) : null;
              $pTypeLabel = ['wordpress'=>'WordPress','laravel'=>'Laravel','symfony'=>'Symfony'][$pType] ?? null; ?>
          <div class="sitecard<?= $locked?' is-locked':'' ?>">
            <div class="cardactions">
              <a class="lockbtn" href="?tab=proyecto&name=<?= e(rawurlencode($name)) ?>" title="Ver detalles del proyecto" aria-label="Ver detalles del proyecto">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.5 9a2.5 2.5 0 0 1 5 0c0 1.3-.7 1.9-1.4 2.4-.6.5-1.1.9-1.1 1.6"/><line x1="12" y1="17" x2="12" y2="17.01"/></svg>
              </a>
              <?php if ($termOn && ($hasComposer || $hasNpm)): ?>
                <button type="button" class="runbtn" title="Ejecutar Composer/NPM" aria-label="Ejecutar Composer/NPM" onclick="luaOpenRunner('<?= e($name) ?>','<?= e(term_win($pdir)) ?>',<?= $hasComposer?'true':'false' ?>,<?= $hasNpm?'true':'false' ?>)">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                </button>
              <?php endif; ?>
              <form method="post" class="lockform">
                <input type="hidden" name="action" value="<?= $locked?'unlock':'lock' ?>">
                <input type="hidden" name="name" value="<?= e($name) ?>">
                <button type="submit" class="lockbtn" title="<?= $locked?'Desbloquear (permitirá eliminar el proyecto)':'Bloquear (impide eliminar el proyecto)' ?>" aria-label="<?= $locked?'Desbloquear':'Bloquear' ?>">
                  <?php if ($locked): ?>
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                  <?php else: ?>
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/></svg>
                  <?php endif; ?>
                </button>
              </form>
              <?php if (!$locked): ?>
                <button type="button" class="trashbtn" title="Eliminar" aria-label="Eliminar" onclick="luaAskDelete('<?= e($name) ?>')">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </button>
              <?php endif; ?>
            </div>
            <form method="post" enctype="multipart/form-data" class="coverform" id="cover-<?= e($name) ?>">
              <input type="hidden" name="action" value="cover">
              <input type="hidden" name="name" value="<?= e($name) ?>">
              <input type="file" name="img" accept="image/*" hidden onchange="this.form.submit()">
              <button type="button" class="cover<?= $hasCover?' has':' empty' ?>" title="<?= $hasCover?'Cambiar carátula':'Subir carátula' ?>"
                      onclick="this.parentNode.querySelector('input[type=file]').click()"
                      <?= $hasCover?'style="background-image:url(\'?cover='.e(rawurlencode($name)).'&t='.(cover_path($ROOT,$name)?filemtime(cover_path($ROOT,$name)):0).'\')"':'' ?>>
                <span class="cover-hint">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                  <?= $hasCover?'Cambiar':'Carátula' ?>
                </span>
              </button>
            </form>
            <?php if ($hasCover): ?>
              <form method="post" class="coverdel"><input type="hidden" name="action" value="cover_remove"><input type="hidden" name="name" value="<?= e($name) ?>"><button type="submit" class="coverdelbtn" title="Quitar carátula">&times;</button></form>
            <?php endif; ?>
            <div class="namerow">
              <div class="name" title="<?= e($name) ?>"><?= e($name) ?><?php if($pTypeLabel): ?> <span class="typetag typetag-<?= e($pType) ?>"><?= e($pTypeLabel) ?></span><?php endif; ?><?php if($extPath): ?> <span class="exttag" title="Proyecto externo: <?= e($extPath) ?>">ext</span><?php endif; ?></div>
              <form method="post" class="phpselform">
                <input type="hidden" name="action" value="switch">
                <input type="hidden" name="name" value="<?= e($name) ?>">
                <select name="php" class="phpsel" onchange="this.form.submit()">
                  <?php foreach ($vers as $v): ?>
                    <option value="<?= e($v) ?>" <?= $v===$ver?'selected':'' ?>>PHP <?= e($v) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </div>
            <a class="url" href="http://<?= e($dom) ?>" target="_blank"><?= e($dom) ?> &#8599;</a>
            <?php if($extPath): ?><div class="extpath" title="<?= e($extPath) ?>"><?= e($extPath) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
      </div>
    </details>

    <?php if ($unreg): ?>
    <details class="sectioncollapse" id="secUnreg">
      <summary>Sin registrar <span class="op">(<?= count($unreg) ?>) — carpetas en <code>www\</code> que no aparecen arriba</span>
        <form method="post" onclick="event.stopPropagation()" onsubmit="return confirm('Integrar las <?= count($unreg) ?> carpetas sin registrar con PHP <?= e($defaultPhp) ?>?')">
          <input type="hidden" name="action" value="integrate_all">
          <input type="hidden" name="php" value="<?= e($defaultPhp) ?>">
          <button class="btn ghost sm" type="submit">Integrar todo</button>
        </form>
        <span class="arrow"></span>
      </summary>
      <div class="pane">
      <div class="sitegrid">
        <?php foreach ($unreg as $name): ?>
          <div class="sitecard unregistered">
            <div class="cardactions">
              <button type="button" class="trashbtn" title="Eliminar carpeta" aria-label="Eliminar carpeta" onclick="luaAskDeleteUnreg('<?= e($name) ?>')">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
              </button>
            </div>
            <div class="namerow">
              <div class="name" title="<?= e($name) ?>"><?= e($name) ?></div>
              <form method="post" class="phpselform">
                <input type="hidden" name="action" value="integrate">
                <input type="hidden" name="name" value="<?= e($name) ?>">
                <select name="php" class="phpsel">
                  <?php foreach ($vers as $v): ?>
                    <option value="<?= e($v) ?>" <?= $v===$defaultPhp?'selected':'' ?>>PHP <?= e($v) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn sm" type="submit" title="Integrar como proyecto">Integrar</button>
              </form>
            </div>
            <div class="url muted" style="font-family:ui-monospace,Consolas,monospace">www\<?= e($name) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      </div>
    </details>
    <?php endif; ?>

    <script>
      (function(){
        document.querySelectorAll('details.sectioncollapse').forEach(function(d){
          var key = 'lua_sec_' + d.id;
          var saved = localStorage.getItem(key);
          if (saved !== null) { d.open = (saved === '1'); }
          d.addEventListener('toggle', function(){ localStorage.setItem(key, d.open ? '1' : '0'); });
        });
      })();
    </script>
    <script>
      (function(){
        var KEY='lua_sites_view';
        function apply(v){
          document.querySelectorAll('.sitegrid').forEach(function(g){ g.classList.toggle('list', v==='list'); });
          document.querySelectorAll('.viewbtn').forEach(function(b){ b.classList.toggle('on', b.dataset.view===v); });
        }
        apply(localStorage.getItem(KEY) || 'grid');
        document.querySelectorAll('.viewbtn').forEach(function(b){
          b.addEventListener('click', function(){ localStorage.setItem(KEY, b.dataset.view); apply(b.dataset.view); });
        });
      })();
    </script>

    <!-- Modal de confirmacion de borrado -->
    <div id="delModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDelete()">
      <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="delTitle">
        <div class="modal-ic">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
        </div>
        <h3 id="delTitle">¿Eliminar proyecto?</h3>
        <p class="modal-tx">Se quitará <strong id="delName"></strong> del panel y se recargará Apache.
          La carpeta <code>www\<span id="delFolder"></span></code> y todos sus archivos <strong>se conservan</strong> en disco.</p>
        <form method="post" class="modal-actions">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="name" id="delNameInput">
          <button type="button" class="btn ghost" onclick="luaCloseDelete()">Cancelar</button>
          <button type="submit" class="btn danger">Sí, eliminar</button>
        </form>
      </div>
    </div>
    <script>
      function luaAskDelete(name){
        document.getElementById('delName').textContent = name;
        document.getElementById('delFolder').textContent = name;
        document.getElementById('delNameInput').value = name;
        document.getElementById('delModal').hidden = false;
        document.addEventListener('keydown', luaEscDelete);
      }
      function luaCloseDelete(){
        document.getElementById('delModal').hidden = true;
        document.removeEventListener('keydown', luaEscDelete);
      }
      function luaEscDelete(e){ if(e.key==='Escape') luaCloseDelete(); }
    </script>

    <!-- Modal de confirmacion de borrado de una carpeta SIN registrar (borrado real, sin red de seguridad) -->
    <div id="delUnregModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDeleteUnreg()">
      <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="delUnregTitle">
        <div class="modal-ic">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
        </div>
        <h3 id="delUnregTitle">¿Eliminar carpeta?</h3>
        <p class="modal-tx">Se borrará <code>www\<strong id="delUnregName"></strong></code> y <strong>todo su contenido</strong> del disco, de forma permanente. Como no está registrada como proyecto, esto no se puede deshacer.</p>
        <form method="post" class="modal-actions">
          <input type="hidden" name="action" value="delete_unregistered">
          <input type="hidden" name="name" id="delUnregNameInput">
          <button type="button" class="btn ghost" onclick="luaCloseDeleteUnreg()">Cancelar</button>
          <button type="submit" class="btn danger">Sí, eliminar</button>
        </form>
      </div>
    </div>
    <script>
      function luaAskDeleteUnreg(name){
        document.getElementById('delUnregName').textContent = name;
        document.getElementById('delUnregNameInput').value = name;
        document.getElementById('delUnregModal').hidden = false;
        document.addEventListener('keydown', luaEscDeleteUnreg);
      }
      function luaCloseDeleteUnreg(){
        document.getElementById('delUnregModal').hidden = true;
        document.removeEventListener('keydown', luaEscDeleteUnreg);
      }
      function luaEscDeleteUnreg(e){ if(e.key==='Escape') luaCloseDeleteUnreg(); }
    </script>

    <!-- Modal: runner de Composer/NPM por proyecto (reutiliza los endpoints de la Terminal) -->
    <div id="runnerModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseRunner()">
      <div class="modal-box" role="dialog" aria-modal="true" style="max-width:640px;text-align:left">
        <div class="row" style="margin-bottom:10px">
          <h3 id="runnerTitle" style="margin:0;font-size:16px">Ejecutar</h3>
          <div class="spacer"></div>
          <button type="button" class="btn ghost sm" id="runnerStop" disabled>Detener</button>
          <button type="button" class="btn ghost sm" onclick="luaCloseRunner()">Cerrar</button>
        </div>
        <div id="runnerBtns" class="row" style="gap:6px;margin-bottom:10px;flex-wrap:wrap"></div>
        <div id="runnerOut" class="termout" style="height:280px;border:1px solid var(--line);border-radius:6px;background:var(--in)"></div>
      </div>
    </div>
    <script>
      (function(){
        var modal=document.getElementById('runnerModal'), title=document.getElementById('runnerTitle'),
            btnsEl=document.getElementById('runnerBtns'), out=document.getElementById('runnerOut'),
            stopBtn=document.getElementById('runnerStop');
        var sid=null, path=null, running=false, curRun=null;

        function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
        var ANSI={30:'k',31:'r',32:'g',33:'y',34:'b',35:'m',36:'c',37:'w',90:'K',91:'R',92:'G',93:'Y',94:'B',95:'M',96:'C',97:'W'};
        function ansiToHtml(s){
          var res='', open=false, cls='', bold=false;
          var re=/\x1b\[([0-9;]*)m/g, last=0, m;
          function span(t){ if(!t)return; if(open){res+='<span class="a-'+cls+(bold?' a-bold':'')+'">'+esc(t)+'</span>';} else {res+=esc(t);} }
          while((m=re.exec(s))!==null){
            span(s.slice(last,m.index)); last=re.lastIndex;
            var codes=m[1].split(';').filter(x=>x!=='').map(Number); if(codes.length===0)codes=[0];
            codes.forEach(function(c){ if(c===0){open=false;cls='';bold=false;} else if(c===1){bold=true;} else if(ANSI[c]){cls=ANSI[c];open=true;} });
          }
          span(s.slice(last));
          return res;
        }
        function append(html){ out.insertAdjacentHTML('beforeend', html); out.scrollTop=out.scrollHeight; }
        function setButtons(disabled){ Array.from(btnsEl.querySelectorAll('button')).forEach(function(b){ b.disabled=disabled; }); }

        function poll(runid, off, fails){
          fails=fails||0;
          fetch('?action=term_poll&sid='+sid+'&runid='+runid+'&off='+off)
          .then(r=>r.json()).then(function(j){
            if(j.error){ append('<span class="a-r">'+esc(j.error)+'</span>\n'); finish(); return; }
            if(j.data){ append(ansiToHtml(j.data)); }
            if(j.done){
              if(out.textContent && !out.textContent.endsWith('\n')) append('\n');
              append('<span class="'+(j.code?'a-r':'a-g')+'">[salida '+(j.code||0)+']</span>\n');
              finish();
            } else { setTimeout(function(){ poll(runid, j.off, 0); }, 300); }
          }).catch(function(){
            if(fails>=5){ append('<span class="a-r">[error de red]</span>\n'); finish(); return; }
            setTimeout(function(){ poll(runid, off, fails+1); }, 500);
          });
        }
        function finish(){ running=false; curRun=null; stopBtn.disabled=true; setButtons(false); }

        window.luaRunPreset=function(cmd){
          if(running) return;
          running=true; setButtons(true); stopBtn.disabled=false;
          append('<span class="a-prompt">&gt; </span>'+esc(cmd)+'\n');
          var full='cd /d "'+path+'" && '+cmd;
          fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'action=term_run&sid='+sid+'&cmd='+encodeURIComponent(full)})
          .then(r=>r.json()).then(function(j){
            if(j.error){ append('<span class="a-r">'+esc(j.error)+'</span>\n'); finish(); return; }
            curRun=j.runid; poll(j.runid, 0);
          }).catch(function(){ append('<span class="a-r">[no se pudo lanzar]</span>\n'); finish(); });
        };
        stopBtn.onclick=function(){
          if(!running||!curRun) return;
          fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'action=term_stop&sid='+sid+'&runid='+curRun}).then(()=>{});
        };

        window.luaOpenRunner=function(name, projectPath, hasComposer, hasNpm){
          path=projectPath;
          sid=(function(){var a=new Uint8Array(10);crypto.getRandomValues(a);return Array.from(a).map(b=>b.toString(16).padStart(2,'0')).join('');})();
          title.textContent='Ejecutar en '+name;
          out.innerHTML=''; running=false; curRun=null; stopBtn.disabled=true;
          var presets=[];
          if(hasComposer){ presets.push(['composer install','composer install'],['composer update','composer update']); }
          if(hasNpm){ presets.push(['npm install','npm install'],['npm run build','npm run build']); }
          btnsEl.innerHTML=presets.map(function(p){ return '<button type="button" class="btn ghost sm" onclick="luaRunPreset('+JSON.stringify(p[1])+')">'+esc(p[0])+'</button>'; }).join('');
          modal.hidden=false;
          document.addEventListener('keydown', luaEscRunner);
        };
        window.luaCloseRunner=function(){
          if(running && curRun){ fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=term_stop&sid='+sid+'&runid='+curRun}).then(()=>{}); }
          modal.hidden=true;
          document.removeEventListener('keydown', luaEscRunner);
        };
        function luaEscRunner(e){ if(e.key==='Escape') luaCloseRunner(); }
      })();
    </script>

  <?php elseif ($tab==='proyecto'): /* ---------- FICHA DE PROYECTO ---------- */
      $pName = (string)($_GET['name'] ?? '');
      $pInfo = (valid_name($pName) && isset($sites[$pName])) ? $sites[$pName] : null; ?>

    <a href="?tab=proyectos" class="muted" style="display:inline-block;margin-bottom:14px">&larr; Volver a proyectos</a>

    <?php if ($pInfo === null): ?>
      <div class="card muted">No se encontró el proyecto "<?= e($pName) ?>".</div>
    <?php else:
        $pVer = is_array($pInfo) ? ($pInfo['php'] ?? '?') : $pInfo;
        $pDom = (is_array($pInfo) && !empty($pInfo['domain'])) ? $pInfo['domain'] : $pName.'.'.$tld;
        $pExtPath = (is_array($pInfo) && !empty($pInfo['path'])) ? $pInfo['path'] : null;
        $pDir = project_dir($WWW, $pInfo, $pName);
        $pLocked = project_locked($pDir);
        $pCoverFile = cover_path($ROOT, $pName);
        $pHasComposer = is_file($pDir.'/composer.json');
        $pHasNpm = is_file($pDir.'/package.json');
        $pGit = is_dir($pDir) ? git_info($pDir) : null;
        $pErrLog = tail_file($ROOT.'/logs/apache/'.$pName.'-error.log', 200);
        $pType = is_array($pInfo) ? ($pInfo['type'] ?? null) : null;
        $pTypeLabel = ['wordpress'=>'WordPress','laravel'=>'Laravel','symfony'=>'Symfony'][$pType] ?? null; ?>

      <div class="card row" style="align-items:flex-start;flex-wrap:wrap;gap:16px">
        <?php if ($pCoverFile): ?>
          <div style="width:110px;height:74px;border-radius:6px;flex:0 0 auto;background-size:cover;background-position:center;background-image:url('?cover=<?= e(rawurlencode($pName)) ?>&t=<?= filemtime($pCoverFile) ?>')"></div>
        <?php endif; ?>
        <div style="min-width:240px;flex:1">
          <div class="row" style="gap:8px">
            <span style="font-size:20px;font-weight:700"><?= e($pName) ?></span>
            <?php if ($pTypeLabel): ?><span class="typetag typetag-<?= e($pType) ?>"><?= e($pTypeLabel) ?></span><?php endif; ?>
            <?php if ($pExtPath): ?><span class="exttag" title="Proyecto externo: <?= e($pExtPath) ?>">ext</span><?php endif; ?>
            <span class="jstate <?= $pLocked?'warn':'ok' ?>"><?= $pLocked?'Bloqueado':'Desbloqueado' ?></span>
            <span class="jstate run">PHP <?= e($pVer) ?></span>
          </div>
          <a class="url" href="http://<?= e($pDom) ?>" target="_blank" style="display:inline-block;margin-top:6px">http://<?= e($pDom) ?> &#8599;</a>
          <div class="muted" style="margin-top:8px;font-size:12px;font-family:ui-monospace,Consolas,monospace" title="<?= e($pDir) ?>"><?= e($pDir) ?></div>
          <div class="row" style="margin-top:10px;gap:6px">
            <?php if ($pHasComposer): ?><span class="tag">composer.json</span><?php endif; ?>
            <?php if ($pHasNpm): ?><span class="tag">package.json</span><?php endif; ?>
            <?php if ($pGit): ?><span class="tag">git &middot; <?= e($pGit['branch']) ?></span><?php endif; ?>
          </div>
        </div>
      </div>

      <div class="pgrid2">
        <?php if ($pGit): ?>
          <div class="card">
            <div class="row" style="margin-bottom:10px">
              <div style="font-weight:600">Git</div>
              <span class="jstate ok"><?= e($pGit['branch']) ?></span>
              <?php if ($pGit['dirty']>0): ?><span class="jstate warn"><?= (int)$pGit['dirty'] ?> cambio(s) sin commitear</span><?php else: ?><span class="jstate ok">Limpio</span><?php endif; ?>
              <div class="spacer"></div>
              <?php if ($pGit['remote']!==''): ?><span class="muted" style="font-size:12px"><?= e($pGit['remote']) ?></span><?php endif; ?>
            </div>
            <?php if (!$pGit['commits']): ?>
              <div class="muted">Sin commits todavía.</div>
            <?php else: ?>
              <div class="logview" style="font-family:inherit">
                <?php foreach ($pGit['commits'] as $c): ?>
                  <div class="row" style="gap:10px;padding:5px 0;border-bottom:1px solid var(--line)">
                    <code style="font-size:11px"><?= e($c['hash']) ?></code>
                    <span style="flex:1;color:var(--tx)"><?= e($c['subject']) ?></span>
                    <span class="muted" style="font-size:11px;white-space:nowrap"><?= e($c['author']) ?> &middot; <?= e($c['date']) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="card muted">Este proyecto no es un repositorio Git (o <code>git</code> no está disponible en esta máquina).</div>
        <?php endif; ?>

        <div class="card">
          <div class="row" style="margin-bottom:10px">
            <div style="font-weight:600">Log de errores de este proyecto</div>
            <span class="muted" style="font-size:12px"><?= e($pName) ?>-error.log</span>
            <div class="spacer"></div>
            <a class="btn ghost sm" href="?tab=logs&log=<?= urlencode($pName.'-error.log') ?>">Ver completo</a>
          </div>
          <?php if ($pErrLog===''): ?>
            <div class="muted">Sin errores recientes.</div>
          <?php else: ?>
            <pre class="logview"><?= highlight_error_log($pErrLog) ?></pre>
          <?php endif; ?>
        </div>
      </div>

      <div class="pgrid2">
      <div class="card">
        <div style="font-weight:600;margin-bottom:10px">Archivos</div>
        <?php if (!is_dir($pDir)): ?>
          <div class="muted">No se encontró la carpeta del proyecto.</div>
        <?php else: $tCount=0; ?>
          <div class="tree" id="projTree"><?= tree_node_html($pDir, '', true, $tCount, 4000) ?></div>
          <script>
          (function(){
            var root = document.getElementById('projTree');
            if(!root) return;
            root.addEventListener('click', function(ev){
              var frow = ev.target.closest('.trow.tfile');
              if (frow && root.contains(frow)) {
                var rel = frow.getAttribute('data-rel');
                var label = frow.querySelector('span').textContent;
                luaOpenFileEditor(<?= json_encode($pName) ?>, rel, label);
                return;
              }
              var row = ev.target.closest('.trow.tdir');
              if(!row || !root.contains(row)) return;
              var box = row.nextElementSibling;
              var willOpen = !row.classList.contains('open');
              row.classList.toggle('open', willOpen);
              if (box) box.hidden = !willOpen;
              if (willOpen && row.dataset.lazy==='1' && !row.dataset.loaded) {
                row.dataset.loaded='1';
                var rel = row.getAttribute('data-rel');
                fetch('?ajax=tree&name=<?= rawurlencode($pName) ?>&rel='+encodeURIComponent(rel))
                  .then(function(r){ return r.json(); })
                  .then(function(j){ box.innerHTML = j.html || '<div class="tnode-more">(vacío)</div>'; })
                  .catch(function(){ box.innerHTML = '<div class="tnode-more">Error al cargar.</div>'; });
              }
            });
          })();
          </script>
        <?php endif; ?>
      </div>

      <?php
        $pFtp = ftp_config_get($ROOT, $pName) ?: ['host'=>'','port'=>21,'user'=>'','pass'=>'','path'=>'/','ssl'=>false,'exclude'=>'.git, node_modules, .idea'];
        $pFtpJobs = array_values(array_filter($jobs, function($j) use ($pName){ return ($j['type']??'')==='ftp_deploy' && ($j['name']??'')===$pName; }));
        $pFtpJob = $pFtpJobs[0] ?? null;
      ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:10px">Desplegar por FTP</div>
        <form method="post" class="inline">
          <input type="hidden" name="action" value="ftp_save">
          <input type="hidden" name="name" value="<?= e($pName) ?>">
          <div><label>Host</label><input name="ftp_host" value="<?= e($pFtp['host']) ?>" placeholder="ftp.tuhosting.com" style="width:200px"></div>
          <div><label>Puerto</label><input name="ftp_port" value="<?= e($pFtp['port']) ?>" style="width:70px"></div>
          <div><label>Usuario</label><input name="ftp_user" value="<?= e($pFtp['user']) ?>" style="width:150px"></div>
          <div><label>Contraseña</label><input type="password" name="ftp_pass" placeholder="<?= ($pFtp['pass']??'')!==''?'••••••• (sin cambios)':'contraseña' ?>" autocomplete="off" style="width:150px"></div>
          <div><label>Ruta remota</label><input name="ftp_path" value="<?= e($pFtp['path']) ?>" style="width:150px"></div>
          <label style="display:flex;align-items:center;gap:6px;font-weight:400;margin-top:22px">
            <input type="checkbox" name="ftp_ssl" value="1" <?= !empty($pFtp['ssl'])?'checked':'' ?> style="width:auto"> FTPS (TLS)
          </label>
          <div style="flex:1;min-width:220px"><label>Excluir (coincide con parte de la ruta)</label><input name="ftp_exclude" value="<?= e($pFtp['exclude']) ?>" style="width:100%"></div>
          <button class="btn ghost" type="submit">Guardar</button>
        </form>
        <form method="post" style="margin-top:12px" onsubmit="return confirm('¿Desplegar ahora los archivos de <?= e($pName) ?> por FTP a <?= e($pFtp['host']) ?>?')">
          <input type="hidden" name="action" value="ftp_deploy">
          <input type="hidden" name="name" value="<?= e($pName) ?>">
          <button class="btn" type="submit" <?= ($pFtp['host']??'')===''?'disabled':'' ?>>Desplegar ahora</button>
        </form>
        <div class="muted" style="margin-top:10px;font-size:12px">Sube todos los archivos del proyecto a la ruta remota indicada (crea carpetas si no existen). La contraseña se guarda en texto plano en <code>config\ftp\</code> (fuera de git), solo en esta máquina.</div>
        <?php if ($pFtpJob):
              $st=$pFtpJob['state']??'?'; $cls=['done'=>'ok','error'=>'err','running'=>'run','queued'=>'warn']; $c=$cls[$st]??'run';
              $tail = in_array($st,['running','error','queued'],true) ? job_log_tail($ROOT,$pFtpJob['id']??'') : ''; ?>
          <div class="row" style="margin-top:14px">
            <span class="jstate <?= $c ?>"><?= e(strtoupper($st)) ?></span>
            <span class="muted"><?= e($pFtpJob['msg']??'') ?></span>
          </div>
          <?php if ($tail): ?><pre class="joblog"><?= e($tail) ?></pre><?php endif; ?>
        <?php endif; ?>
      </div>
      </div>

      <!-- Editor de codigo (CodeMirror 5, autoalojado: sin CDN ni build step) -->
      <link rel="stylesheet" href="assets/codemirror/lib/codemirror.css">
      <script src="assets/codemirror/lib/codemirror.js"></script>
      <script src="assets/codemirror/addon/edit/matchbrackets.js"></script>
      <script src="assets/codemirror/mode/xml/xml.js"></script>
      <script src="assets/codemirror/mode/javascript/javascript.js"></script>
      <script src="assets/codemirror/mode/css/css.js"></script>
      <script src="assets/codemirror/mode/htmlmixed/htmlmixed.js"></script>
      <script src="assets/codemirror/mode/clike/clike.js"></script>
      <script src="assets/codemirror/mode/php/php.js"></script>
      <script src="assets/codemirror/mode/sql/sql.js"></script>
      <script src="assets/codemirror/mode/markdown/markdown.js"></script>
      <script src="assets/codemirror/mode/shell/shell.js"></script>

      <!-- Modal: editor de codigo (clic en un archivo del arbol) -->
      <div id="fileEditorModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseFileEditor()">
        <div class="modal-box" role="dialog" aria-modal="true" style="max-width:1000px;width:95vw;text-align:left">
          <div class="row" style="margin-bottom:10px">
            <h3 id="fileEditorTitle" style="margin:0;font-size:14px;font-family:ui-monospace,Consolas,monospace;font-weight:600"></h3>
            <div class="spacer"></div>
            <span class="muted" id="fileEditorStatus" style="font-size:12px"></span>
            <button type="button" class="btn ghost sm" onclick="luaCloseFileEditor()">Cerrar</button>
            <button type="button" class="btn sm" id="fileEditorSave">Guardar</button>
          </div>
          <div id="fileEditorHost" style="height:60vh"><textarea id="fileEditorArea" spellcheck="false" autocapitalize="off" autocomplete="off"></textarea></div>
          <div class="muted" style="margin-top:8px;font-size:11px">Ctrl+S para guardar.</div>
        </div>
      </div>
      <script>
        (function(){
          var modal=null, titleEl=null, host=null, area=null, status=null, saveBtn=null, cm=null, curName=null, curRel=null;

          function modeForFile(name){
            var ext = (name.split('.').pop() || '').toLowerCase();
            var map = {
              php:'application/x-httpd-php', phtml:'application/x-httpd-php',
              html:'text/html', htm:'text/html',
              js:'text/javascript', mjs:'text/javascript', cjs:'text/javascript',
              json:'application/json',
              css:'text/css',
              xml:'application/xml', svg:'application/xml',
              md:'text/markdown', markdown:'text/markdown',
              sql:'text/x-mysql',
              sh:'text/x-sh', bash:'text/x-sh'
            };
            return map[ext] || null;
          }

          function init(){
            modal = document.getElementById('fileEditorModal');
            titleEl = document.getElementById('fileEditorTitle');
            host = document.getElementById('fileEditorHost');
            area = document.getElementById('fileEditorArea');
            status = document.getElementById('fileEditorStatus');
            saveBtn = document.getElementById('fileEditorSave');
            saveBtn.addEventListener('click', save);
            cm = CodeMirror.fromTextArea(area, {
              lineNumbers: true,
              theme: 'lua',
              matchBrackets: true,
              indentUnit: 4,
              tabSize: 4,
              indentWithTabs: true,
              lineWrapping: false,
              extraKeys: { 'Ctrl-S': function(){ save(); return false; }, 'Cmd-S': function(){ save(); return false; } }
            });
            cm.setSize('100%', '100%');
          }

          function save(){
            if (!curName || cm.getOption('readOnly')) return;
            status.textContent = 'Guardando…';
            fetch('?', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
              body:'action=file_write&name='+encodeURIComponent(curName)+'&rel='+encodeURIComponent(curRel)+'&content='+encodeURIComponent(cm.getValue())})
              .then(function(r){ return r.json(); })
              .then(function(j){ status.textContent = j.error ? j.error : 'Guardado.'; })
              .catch(function(){ status.textContent = 'Error de red al guardar.'; });
          }
          window.luaOpenFileEditor = function(name, rel, label){
            if (!modal) init();
            curName = name; curRel = rel;
            titleEl.textContent = label || rel;
            cm.setOption('readOnly', 'nocursor');
            cm.setValue('Cargando…');
            status.textContent = '';
            modal.hidden = false;
            document.addEventListener('keydown', luaEscFileEditor);
            setTimeout(function(){ cm.refresh(); }, 0);
            fetch('?ajax=file_read&name='+encodeURIComponent(name)+'&rel='+encodeURIComponent(rel))
              .then(function(r){ return r.json(); })
              .then(function(j){
                cm.setOption('readOnly', false);
                if (j.error) { cm.setValue(''); status.textContent = j.error; }
                else {
                  cm.setOption('mode', modeForFile(label || rel));
                  cm.setValue(j.content);
                  cm.clearHistory();
                  status.textContent = '';
                  cm.focus();
                }
              })
              .catch(function(){ cm.setOption('readOnly', false); status.textContent = 'Error de red al cargar.'; });
          };
          window.luaCloseFileEditor = function(){
            if (modal) modal.hidden = true;
            document.removeEventListener('keydown', luaEscFileEditor);
          };
          function luaEscFileEditor(e){ if (e.key === 'Escape') luaCloseFileEditor(); }
        })();
      </script>

    <?php endif; ?>

  <?php elseif ($tab==='php'): /* ---------- PESTAÑA PHP ---------- */ ?>

    <h2>Editar php.ini por versión</h2>
    <div class="muted" style="margin-bottom:14px">Los cambios se guardan como <em>overrides</em> (sobreviven a actualizaciones) y se aplican recargando Apache automáticamente.</div>

    <?php if (!$vers): ?>
      <div class="card muted">No hay versiones de PHP instaladas.</div>
    <?php else: $openVer = $_GET['ver'] ?? ''; foreach ($vers as $v):
        [$vals,$extra] = parse_overrides("$OVR_DIR/$v.overrides.ini", array_keys($CURATED)); ?>
      <details <?= $v===$openVer?'open':'' ?>>
        <summary>PHP <?= e($v) ?> <span class="op">&mdash; config/php/<?= e($v) ?>.overrides.ini</span><span class="arrow"></span></summary>
        <div class="pane">
          <?php $xon = is_file($OVR_DIR.'/'.$v.'.xdebug.on'); $xdll = is_file($PHP_BASE.'/'.$v.'/ext/php_xdebug.dll'); $xactive=($xon&&$xdll); $xnourl=(empty($XDEBUG_URLS[$v]) && !$xdll); ?>
          <div class="row" style="margin-bottom:4px">
            <span style="font-weight:600">Xdebug</span>
            <span class="jstate <?= $xactive?'ok':'err' ?>"><?= $xactive?'ACTIVADO':'DESACTIVADO' ?></span>
            <?php if ($xon && !$xdll): ?><span class="muted">descargando DLL…</span><?php endif; ?>
            <div class="spacer"></div>
            <form method="post">
              <input type="hidden" name="action" value="xdebug">
              <input type="hidden" name="ver" value="<?= e($v) ?>">
              <input type="hidden" name="enable" value="<?= $xactive?'0':'1' ?>">
              <button class="btn <?= $xactive?'danger':'' ?>" <?= $xnourl?'disabled':'' ?>><?= $xactive?'Desactivar':'Activar' ?> Xdebug</button>
            </form>
          </div>
          <div class="muted" style="margin-bottom:16px;font-size:12px">Depuración paso a paso en el puerto <b>9003</b> (VS Code / PhpStorm)<?= $xnourl?' · <em>sin DLL disponible para esta versión</em>':'' ?>.</div>
          <form method="post">
            <input type="hidden" name="action" value="phpini">
            <input type="hidden" name="ver" value="<?= e($v) ?>">
            <div class="grid">
              <?php foreach ($CURATED as $k=>$meta): $cur = $vals[$k] ?? ''; ?>
                <div>
                  <label><?= e($meta['label']) ?> <span class="muted">(<?= e($k) ?>)</span></label>
                  <?php if ($meta['type']==='onoff'): ?>
                    <select name="ini[<?= e($k) ?>]" style="width:100%">
                      <option value="On"  <?= strcasecmp($cur,'On')===0?'selected':''  ?>>On</option>
                      <option value="Off" <?= strcasecmp($cur,'Off')===0?'selected':'' ?>>Off</option>
                    </select>
                  <?php else: ?>
                    <input name="ini[<?= e($k) ?>]" value="<?= e($cur) ?>" placeholder="<?= e($meta['ph']) ?>" style="width:100%">
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <div style="margin-top:14px">
              <label>Directivas adicionales (una por línea, formato <code>clave = valor</code>)</label>
              <textarea name="extra" placeholder="; ejemplo&#10;opcache.jit = 1255&#10;realpath_cache_size = 4096k"><?= e(implode("\n",$extra)) ?></textarea>
            </div>
            <div style="margin-top:14px"><button class="btn" type="submit">Guardar y aplicar</button></div>
          </form>
        </div>
      </details>
    <?php endforeach; endif; ?>

  <?php elseif ($tab==='logs'): /* ---------- PESTAÑA LOGS ---------- */
      $logDir = $ROOT.'/logs/apache';
      $logFiles = [];
      foreach (glob($logDir.'/*.log') as $f) $logFiles[] = basename($f);
      sort($logFiles);
      if (!$logFiles) $logFiles = ['error.log'];
      $sel = safe_logname($_GET['log'] ?? '');
      if (!$sel || !in_array($sel,$logFiles,true)) $sel = in_array('error.log',$logFiles,true)?'error.log':$logFiles[0];
      $refresh = (($_GET['refresh']??'')==='1');
      $content = tail_file($logDir.'/'.$sel, 300);
  ?>
    <div class="row" style="margin-bottom:14px;gap:8px;flex-wrap:wrap">
      <?php foreach ($logFiles as $lf): ?>
        <a href="?tab=logs&log=<?= urlencode($lf) ?><?= $refresh?'&refresh=1':'' ?>" class="btn <?= $lf===$sel?'':'ghost' ?> sm"><?= e($lf) ?></a>
      <?php endforeach; ?>
      <div class="spacer"></div>
      <a href="?tab=logs&log=<?= urlencode($sel) ?><?= $refresh?'':'&refresh=1' ?>" class="btn ghost sm"><?= $refresh?'⏸ Auto-refresco ON':'▶ Auto-refresco' ?></a>
      <button type="button" class="btn ghost sm" onclick="luaAskClearLog('<?= e($sel) ?>')">Vaciar</button>
      <button type="button" class="btn danger sm" onclick="luaAskDeleteLog('<?= e($sel) ?>')">Eliminar</button>
    </div>
    <pre class="logview"><?= $content!=='' ? highlight_error_log($content) : '(vacío)' ?></pre>

    <!-- Modal de confirmacion de borrado de archivo de log -->
    <div id="delLogModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDeleteLog()">
      <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="delLogTitle">
        <div class="modal-ic">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
        </div>
        <h3 id="delLogTitle">¿Eliminar el archivo de log?</h3>
        <p class="modal-tx">Se borrará <strong id="delLogName"></strong> del disco de forma permanente. Si el servicio que lo genera sigue activo, puede volver a crearse solo.</p>
        <form method="post" class="modal-actions">
          <input type="hidden" name="action" value="deletelog">
          <input type="hidden" name="log" id="delLogInput">
          <button type="button" class="btn ghost" onclick="luaCloseDeleteLog()">Cancelar</button>
          <button type="submit" class="btn danger">Sí, eliminar</button>
        </form>
      </div>
    </div>
    <script>
      function luaAskDeleteLog(name){
        document.getElementById('delLogName').textContent = name;
        document.getElementById('delLogInput').value = name;
        document.getElementById('delLogModal').hidden = false;
        document.addEventListener('keydown', luaEscDeleteLog);
      }
      function luaCloseDeleteLog(){
        document.getElementById('delLogModal').hidden = true;
        document.removeEventListener('keydown', luaEscDeleteLog);
      }
      function luaEscDeleteLog(e){ if(e.key==='Escape') luaCloseDeleteLog(); }
    </script>

    <!-- Modal de confirmacion de vaciado de log -->
    <div id="clearLogModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseClearLog()">
      <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="clearLogTitle">
        <div class="modal-ic">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
        </div>
        <h3 id="clearLogTitle">¿Vaciar el log?</h3>
        <p class="modal-tx">Se borrará todo el contenido de <strong id="clearLogName"></strong>. Esto no se puede deshacer.</p>
        <form method="post" class="modal-actions">
          <input type="hidden" name="action" value="clearlog">
          <input type="hidden" name="log" id="clearLogInput">
          <button type="button" class="btn ghost" onclick="luaCloseClearLog()">Cancelar</button>
          <button type="submit" class="btn danger">Sí, vaciar</button>
        </form>
      </div>
    </div>
    <script>
      function luaAskClearLog(name){
        document.getElementById('clearLogName').textContent = name;
        document.getElementById('clearLogInput').value = name;
        document.getElementById('clearLogModal').hidden = false;
        document.addEventListener('keydown', luaEscClearLog);
      }
      function luaCloseClearLog(){
        document.getElementById('clearLogModal').hidden = true;
        document.removeEventListener('keydown', luaEscClearLog);
      }
      function luaEscClearLog(e){ if(e.key==='Escape') luaCloseClearLog(); }
    </script>

  <?php elseif ($tab==='config'): /* ---------- PESTAÑA CONFIGURACIÓN DEL SERVIDOR ---------- */ ?>

    <div class="card row">
      <div>
        <div style="font-weight:600">Dominio local</div>
        <div class="muted">Tus proyectos se sirven en <code>&lt;nombre&gt;.<?= e($tld) ?></code>. Recomendado dejarlo en <code>test</code> (reservado oficialmente para pruebas, nunca será un dominio real). Evita <code>dev</code> (Chrome fuerza HTTPS ahí por defecto) y <code>local</code> (lo usa Bonjour/mDNS de Windows y puede dar conflictos).</div>
      </div>
      <div class="spacer"></div>
      <form method="post" class="row" style="gap:8px">
        <input type="hidden" name="action" value="set_tld">
        <input name="tld" value="<?= e($tld) ?>" placeholder="test" style="width:160px">
        <button class="btn ghost" type="submit">Guardar</button>
      </form>
    </div>

    <div class="card row">
      <div>
        <div style="font-weight:600">Dominios <code>.<?= e($tld) ?></code> en el navegador</div>
        <div class="muted">Para que <code>&lt;nombre&gt;.<?= e($tld) ?></code> abra en el navegador hay que registrarlos en Windows (una vez). Si <code>localhost</code> te carga otra cosa (p. ej. Docker/Portainer, que ocupa el mismo puerto por IPv6), usa <code><?= e($tld) ?></code> a secas — a diferencia de <code>localhost</code>, no es un nombre especial y siempre te trae aquí.</div>
      </div>
      <div class="spacer"></div>
      <form method="post">
        <input type="hidden" name="action" value="hosts">
        <button class="btn ghost" type="submit">Sincronizar dominios</button>
      </form>
    </div>

    <?php $httpsOn = is_file($ROOT.'/config/https.on') && is_file($ROOT.'/data/ssl/lua.pem'); ?>
    <div class="card row">
      <div>
        <div style="font-weight:600">HTTPS local <span class="jstate <?= $httpsOn?'ok':'err' ?>" style="margin-left:6px"><?= $httpsOn?'ACTIVO':'INACTIVO' ?></span></div>
        <div class="muted">Certificados de confianza para <code>https://&lt;proyecto&gt;.<?= e($tld) ?></code> (candado verde). Al activar, Windows pedirá permiso para instalar la CA (una vez).</div>
      </div>
      <div class="spacer"></div>
      <form method="post">
        <input type="hidden" name="action" value="https">
        <input type="hidden" name="enable" value="<?= $httpsOn?'0':'1' ?>">
        <button class="btn <?= $httpsOn?'danger':'ghost' ?>" type="submit"><?= $httpsOn?'Desactivar':'Activar' ?> HTTPS</button>
      </form>
    </div>

    <?php $mailOn = is_file($ROOT.'/config/mailpit.on'); ?>
    <div class="card row">
      <div>
        <div style="font-weight:600">Mailpit <span class="jstate <?= $mailOn?'ok':'err' ?>" style="margin-left:6px"><?= $mailOn?'ACTIVO':'INACTIVO' ?></span></div>
        <div class="muted">Atrapa los emails que envían tus proyectos PHP (SMTP <code>127.0.0.1:1025</code>) y los muestra en un buzón web. No salen a internet.</div>
      </div>
      <div class="spacer"></div>
      <?php if ($mailOn): ?><a class="btn ghost" href="http://localhost:8025" target="_blank">Abrir buzón &#8599;</a><?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="mailpit">
        <input type="hidden" name="enable" value="<?= $mailOn?'0':'1' ?>">
        <button class="btn <?= $mailOn?'danger':'ghost' ?>" type="submit"><?= $mailOn?'Desactivar':'Activar' ?> Mailpit</button>
      </form>
    </div>

    <?php $mariaOn = is_file($ROOT.'/config/mariadb.on'); ?>
    <div class="card row">
      <div>
        <div style="font-weight:600">Servidor MySQL (MariaDB) <span class="jstate <?= $mariaOn?'ok':'err' ?>" style="margin-left:6px"><?= $mariaOn?'ACTIVO':'INACTIVO' ?></span></div>
        <div class="muted">Nativo (MariaDB 11.8 LTS) en <code>127.0.0.1:3306</code>, usuario <code>root</code> <?= mysql_root_pass($ROOT)!==''?'con contraseña':'sin contraseña' ?> (conecta así desde tus proyectos PHP, tu IDE o <code>bin\mariadb\bin\mariadb.exe</code>). Solo accesible desde esta máquina. Gestiona la contraseña de <code>root</code> y crea usuarios de aplicación en <a href="?tab=bd">Bases de datos</a>.</div>
      </div>
      <div class="spacer"></div>
      <?php if ($mariaOn): ?><a class="btn ghost" href="?tab=bd">Bases de datos</a> <a class="btn ghost" href="http://<?= e($phpmyadminDom) ?>/" target="_blank">phpMyAdmin &#8599;</a> <a class="btn ghost" href="/adminer.php?server=127.0.0.1&username=root" target="_blank">Adminer &#8599;</a><?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="mariadb">
        <input type="hidden" name="enable" value="<?= $mariaOn?'0':'1' ?>">
        <button class="btn <?= $mariaOn?'danger':'ghost' ?>" type="submit"><?= $mariaOn?'Desactivar':'Activar' ?> MySQL</button>
      </form>
    </div>

    <?php $termOn = is_file($ROOT.'/config/terminal.on'); ?>
    <div class="card row">
      <div>
        <div style="font-weight:600">Terminal <span class="jstate <?= $termOn?'ok':'err' ?>" style="margin-left:6px"><?= $termOn?'ACTIVA':'INACTIVA' ?></span></div>
        <div class="muted">Ejecuta comandos (composer, git, npm, artisan…) desde el navegador con la misma cuenta que Apache. Desactivada por defecto por seguridad: solo actívala si confías en quién tiene acceso a esta máquina.</div>
      </div>
      <div class="spacer"></div>
      <form method="post">
        <input type="hidden" name="action" value="terminal">
        <input type="hidden" name="enable" value="<?= $termOn?'0':'1' ?>">
        <button class="btn <?= $termOn?'danger':'ghost' ?>" type="submit"><?= $termOn?'Desactivar':'Activar' ?> Terminal</button>
      </form>
    </div>

    <?php $startupOn = startup_enabled($ROOT); ?>
    <div class="card row">
      <div>
        <div style="font-weight:600">Arrancar con Windows <span class="jstate <?= $startupOn?'ok':'err' ?>" style="margin-left:6px"><?= $startupOn?'ACTIVO':'INACTIVO' ?></span></div>
        <div class="muted">Instala Apache como servicio de Windows (arranque automático) y el watcher como tarea programada (arranca sin necesidad de iniciar sesión). Al activar o desactivar, Windows pedirá permiso (UAC).</div>
      </div>
      <div class="spacer"></div>
      <form method="post">
        <input type="hidden" name="action" value="startup">
        <input type="hidden" name="enable" value="<?= $startupOn?'0':'1' ?>">
        <button class="btn <?= $startupOn?'danger':'ghost' ?>" type="submit"><?= $startupOn?'Desactivar':'Activar' ?></button>
      </form>
    </div>

  <?php elseif ($tab==='bd'): /* ---------- PESTAÑA BASES DE DATOS ---------- */
      $mariaOn = is_file($ROOT.'/config/mariadb.on');
      $rootHasPass = mysql_root_pass($ROOT) !== '';
      $mysqlUsers = $mariaOn ? mysql_users() : null; ?>

    <?php if (!$mariaOn): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:6px">MySQL (MariaDB) está desactivado</div>
        <div class="muted">Activa el servidor MySQL desde <a href="?tab=proyectos">Proyectos</a> o <a href="?tab=config">Configuración del servidor</a> para gestionar bases de datos, usuarios y la contraseña de <code>root</code>.</div>
      </div>
    <?php else: ?>

      <div class="card row">
        <div>
          <div style="font-weight:600">Herramientas de administración</div>
          <div class="muted">phpMyAdmin (con tema propio) y Adminer, ya integrados en la plataforma.</div>
        </div>
        <div class="spacer"></div>
        <a class="btn ghost" href="http://<?= e($phpmyadminDom) ?>/" target="_blank">phpMyAdmin &#8599;</a>
        <a class="btn ghost" href="/adminer.php?server=127.0.0.1&username=root" target="_blank">Adminer &#8599;</a>
      </div>

      <?php $dbList = mysql_databases(); ?>
      <div class="card">
        <div class="row" style="margin-bottom:12px">
          <h2 style="margin:0;font-size:15px">Bases de datos</h2>
          <div class="spacer"></div>
          <form method="post" class="row" style="gap:6px">
            <input type="hidden" name="action" value="db_create">
            <input name="dbname" placeholder="nombre_basedatos" pattern="[a-zA-Z0-9_]{1,64}" style="width:200px" required>
            <button class="btn ghost sm" type="submit">+ Crear BD</button>
          </form>
        </div>
        <?php if ($dbList === null): ?>
          <div class="muted">No se pudo conectar con MySQL (¿acaba de activarse? espera unos segundos y recarga).</div>
        <?php elseif (!$dbList): ?>
          <div class="muted">No hay bases de datos todavía. Crea la primera arriba.</div>
        <?php else: foreach ($dbList as $db): ?>
          <div class="dbrow">
            <div class="dbname"><?= e($db) ?></div>
            <div class="spacer"></div>
            <a class="btn ghost sm" href="?export_db=<?= e(rawurlencode($db)) ?>">Exportar</a>
            <form method="post" enctype="multipart/form-data" class="dbimport" onsubmit="return luaAskImportDb(event, this, '<?= e($db) ?>')">
              <input type="hidden" name="action" value="db_import">
              <input type="hidden" name="dbname" value="<?= e($db) ?>">
              <input type="file" name="sqlfile" accept=".sql" required>
              <button class="btn ghost sm" type="submit">Importar</button>
            </form>
            <button type="button" class="btn danger sm" onclick="luaAskDropDb('<?= e($db) ?>')">Eliminar</button>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="card">
        <div style="font-weight:600">Contraseña de root</div>
        <div class="muted" style="margin-top:6px">Actualmente: <b><?= $rootHasPass?'con contraseña':'sin contraseña' ?></b>. Cambia la contraseña con la que conecta el panel (<code>root@127.0.0.1</code>); déjala en blanco para quitarla.</div>
        <form method="post" class="inline" style="margin-top:12px">
          <input type="hidden" name="action" value="mysql_root_pass">
          <div>
            <label>Nueva contraseña</label>
            <input type="text" name="new_pass" placeholder="dejar vacío para quitarla" autocomplete="off">
          </div>
          <button class="btn" type="submit">Actualizar contraseña</button>
        </form>
      </div>

      <div class="card">
        <div style="font-weight:600;margin-bottom:12px">Usuarios MySQL</div>

        <form method="post" class="inline" style="margin-bottom:16px">
          <input type="hidden" name="action" value="mysql_user_create">
          <div>
            <label>Usuario</label>
            <input name="username" placeholder="app" pattern="[a-zA-Z0-9_]{1,32}" required>
          </div>
          <div>
            <label>Contraseña</label>
            <input type="text" name="password" placeholder="contraseña" autocomplete="off" required>
          </div>
          <div>
            <label>Host</label>
            <select name="host">
              <option value="127.0.0.1">127.0.0.1</option>
              <option value="localhost">localhost</option>
              <option value="%">% (cualquier host)</option>
            </select>
          </div>
          <div>
            <label>Acceso a</label>
            <select name="scope" onchange="document.getElementById('userdbrow').style.display=(this.value==='db')?'block':'none'">
              <option value="all">Todas las bases de datos</option>
              <option value="db">Una base de datos…</option>
            </select>
          </div>
          <div id="userdbrow" style="display:none">
            <label>Base de datos</label>
            <input name="dbname" placeholder="micliente">
          </div>
          <button class="btn" type="submit">+ Crear usuario</button>
        </form>

        <?php if ($mysqlUsers === null): ?>
          <div class="muted">No se pudo conectar con MySQL para listar usuarios (¿acaba de activarse? espera unos segundos y recarga).</div>
        <?php elseif (!$mysqlUsers): ?>
          <div class="muted">No hay usuarios de aplicación todavía. Crea el primero arriba.</div>
        <?php else: foreach ($mysqlUsers as $u): ?>
          <div class="dbrow">
            <div class="dbname"><?= e($u['user']) ?><span class="muted">@<?= e($u['host']) ?></span></div>
            <div class="spacer"></div>
            <?php if (strcasecmp($u['user'],'root') !== 0): ?>
              <button type="button" class="btn danger sm" onclick="luaAskDeleteMysqlUser('<?= e($u['user']) ?>','<?= e($u['host']) ?>')">Eliminar</button>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
        <div class="muted" style="margin-top:10px;font-size:12px">Estos credenciales hay que asignarlos a mano en el <code>.env</code>/config de cada proyecto.</div>
      </div>

      <!-- Modal de confirmacion de borrado de usuario MySQL -->
      <div id="delMysqlUserModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDeleteMysqlUser()">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="delMysqlUserTitle">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
              <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
          </div>
          <h3 id="delMysqlUserTitle">¿Eliminar usuario?</h3>
          <p class="modal-tx">Se eliminará el usuario <strong id="delMysqlUserName"></strong> de MySQL de forma permanente.</p>
          <form method="post" class="modal-actions">
            <input type="hidden" name="action" value="mysql_user_delete">
            <input type="hidden" name="username" id="delMysqlUserNameInput">
            <input type="hidden" name="host" id="delMysqlUserHostInput">
            <button type="button" class="btn ghost" onclick="luaCloseDeleteMysqlUser()">Cancelar</button>
            <button type="submit" class="btn danger">Sí, eliminar</button>
          </form>
        </div>
      </div>
      <script>
        function luaAskDeleteMysqlUser(user, host){
          document.getElementById('delMysqlUserName').textContent = user+'@'+host;
          document.getElementById('delMysqlUserNameInput').value = user;
          document.getElementById('delMysqlUserHostInput').value = host;
          document.getElementById('delMysqlUserModal').hidden = false;
          document.addEventListener('keydown', luaEscDeleteMysqlUser);
        }
        function luaCloseDeleteMysqlUser(){
          document.getElementById('delMysqlUserModal').hidden = true;
          document.removeEventListener('keydown', luaEscDeleteMysqlUser);
        }
        function luaEscDeleteMysqlUser(e){ if(e.key==='Escape') luaCloseDeleteMysqlUser(); }
      </script>

      <!-- Modal de confirmacion de borrado de base de datos -->
      <div id="delDbModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDropDb()">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="delDbTitle">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
              <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
          </div>
          <h3 id="delDbTitle">¿Eliminar base de datos?</h3>
          <p class="modal-tx">Se borrará <strong id="delDbName"></strong> y <strong>todas sus tablas</strong> de forma permanente. Esto no se puede deshacer.</p>
          <form method="post" class="modal-actions">
            <input type="hidden" name="action" value="db_drop">
            <input type="hidden" name="dbname" id="delDbNameInput">
            <button type="button" class="btn ghost" onclick="luaCloseDropDb()">Cancelar</button>
            <button type="submit" class="btn danger">Sí, eliminar</button>
          </form>
        </div>
      </div>
      <script>
        function luaAskDropDb(name){
          document.getElementById('delDbName').textContent = name;
          document.getElementById('delDbNameInput').value = name;
          document.getElementById('delDbModal').hidden = false;
          document.addEventListener('keydown', luaEscDropDb);
        }
        function luaCloseDropDb(){
          document.getElementById('delDbModal').hidden = true;
          document.removeEventListener('keydown', luaEscDropDb);
        }
        function luaEscDropDb(e){ if(e.key==='Escape') luaCloseDropDb(); }
      </script>

      <!-- Modal de confirmacion de importar backup (puede sobrescribir tablas existentes) -->
      <div id="importDbModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseImportDb()">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="importDbTitle">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            </svg>
          </div>
          <h3 id="importDbTitle">¿Importar backup?</h3>
          <p class="modal-tx">Se importará el archivo en <strong id="importDbName"></strong>. Si el <code>.sql</code> incluye tablas con el mismo nombre, <strong>se sobrescribirán</strong>.</p>
          <div class="modal-actions">
            <button type="button" class="btn ghost" onclick="luaCloseImportDb()">Cancelar</button>
            <button type="button" class="btn danger" onclick="luaConfirmImportDb()">Sí, importar</button>
          </div>
        </div>
      </div>
      <script>
        var luaImportDbForm = null;
        function luaAskImportDb(ev, form, dbname){
          ev.preventDefault();
          luaImportDbForm = form;
          document.getElementById('importDbName').textContent = dbname;
          document.getElementById('importDbModal').hidden = false;
          document.addEventListener('keydown', luaEscImportDb);
          return false;
        }
        function luaConfirmImportDb(){
          luaCloseImportDb();
          if (luaImportDbForm) luaImportDbForm.submit();
        }
        function luaCloseImportDb(){
          document.getElementById('importDbModal').hidden = true;
          document.removeEventListener('keydown', luaEscImportDb);
        }
        function luaEscImportDb(e){ if(e.key==='Escape') luaCloseImportDb(); }
      </script>

    <?php endif; ?>

  <?php elseif ($tab==='terminal'): /* ---------- PESTAÑA TERMINAL ---------- */
      $termOn = is_file($ROOT.'/config/terminal.on'); ?>

    <?php if (!$termOn): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:6px">La terminal está desactivada</div>
        <div class="muted" style="margin-bottom:14px">Por seguridad, la terminal viene apagada. Permite ejecutar cualquier comando en esta máquina con los permisos de Apache. Actívala solo si confías en quién puede acceder a este panel.</div>
        <form method="post">
          <input type="hidden" name="action" value="terminal">
          <input type="hidden" name="enable" value="1">
          <button class="btn" type="submit">Activar terminal</button>
        </form>
      </div>
    <?php else: ?>
      <div class="termwrap">
        <div class="termbar">
          <span class="muted" id="termcwd">…</span>
          <div class="spacer"></div>
          <button class="btn ghost sm" id="termstop" disabled>Detener</button>
          <button class="btn ghost sm" id="termclear">Limpiar</button>
        </div>
        <div id="termout" class="termout" aria-live="polite"></div>
        <div class="termin">
          <span class="termprompt">&gt;</span>
          <input id="termcmd" type="text" autocomplete="off" autocapitalize="off" spellcheck="false"
                 placeholder="escribe un comando y pulsa Enter (p.ej. git status)" autofocus>
        </div>
      </div>
      <div class="muted" style="margin-top:10px;font-size:12px">
        Sesión con directorio persistente (<code>cd</code> se mantiene). No es un PTY:
        programas interactivos a pantalla completa (vim, nano, prompts) no funcionan.
        Historial con ↑/↓ · Ctrl+L limpia.
      </div>
      <script>
      (function(){
        var out=document.getElementById('termout');
        var inp=document.getElementById('termcmd');
        var cwdEl=document.getElementById('termcwd');
        var stopBtn=document.getElementById('termstop');
        var clearBtn=document.getElementById('termclear');
        var sid=(function(){var a=new Uint8Array(10);crypto.getRandomValues(a);return Array.from(a).map(b=>b.toString(16).padStart(2,'0')).join('');})();
        var hist=[], hi=-1, running=false, curRun=null;

        // --- parser ANSI SGR basico -> spans con clase de color ---
        var ANSI={30:'k',31:'r',32:'g',33:'y',34:'b',35:'m',36:'c',37:'w',90:'K',91:'R',92:'G',93:'Y',94:'B',95:'M',96:'C',97:'W'};
        function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
        function ansiToHtml(s){
          var res='', open=false, cls='', bold=false;
          var re=/\x1b\[([0-9;]*)m/g, last=0, m;
          function span(t){ if(!t)return; if(open){res+='<span class="a-'+cls+(bold?' a-bold':'')+'">'+esc(t)+'</span>';} else {res+=esc(t);} }
          while((m=re.exec(s))!==null){
            span(s.slice(last,m.index)); last=re.lastIndex;
            var codes=m[1].split(';').filter(x=>x!=='').map(Number); if(codes.length===0)codes=[0];
            codes.forEach(function(c){
              if(c===0){open=false;cls='';bold=false;}
              else if(c===1){bold=true;}
              else if(ANSI[c]){cls=ANSI[c];open=true;}
            });
          }
          span(s.slice(last));
          return res;
        }
        function append(html){ out.insertAdjacentHTML('beforeend', html); out.scrollTop=out.scrollHeight; }
        function setCwd(c){ if(c){cwdEl.textContent=c;} }

        clearBtn.onclick=function(){ out.innerHTML=''; inp.focus(); };
        stopBtn.onclick=function(){
          if(!running||!curRun)return;
          fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'action=term_stop&sid='+sid+'&runid='+curRun}).then(()=>{});
        };

        function poll(runid, off, fails){
          fails = fails||0;
          fetch('?action=term_poll&sid='+sid+'&runid='+runid+'&off='+off)
          .then(r=>r.json()).then(function(j){
            if(j.error){ append('<span class="a-r">'+esc(j.error)+'</span>\n'); finish(); return; }
            if(j.data){ append(ansiToHtml(j.data)); }
            if(j.done){
              if(out.textContent && !out.textContent.endsWith('\n')) append('\n');
              if(j.code && j.code!==0){ append('<span class="a-r">[salida '+j.code+']</span>\n'); }
              if(j.cwd) setCwd(j.cwd);
              finish();
            } else {
              setTimeout(function(){ poll(runid, j.off, 0); }, 300);
            }
          }).catch(function(){
            // un poll suelto puede fallar (mod_fcgid saturado); reintentar antes de rendirse
            if(fails >= 5){ append('<span class="a-r">[error de red: se perdió la conexión con el comando]</span>\n'); finish(); return; }
            setTimeout(function(){ poll(runid, off, fails+1); }, 500);
          });
        }
        function finish(){ running=false; curRun=null; stopBtn.disabled=true; inp.disabled=false; inp.focus(); }

        function run(cmd){
          running=true; inp.disabled=true; stopBtn.disabled=false;
          append('<span class="a-prompt">'+esc(cwdEl.textContent)+'&gt; </span>'+esc(cmd)+'\n');
          var body='action=term_run&sid='+sid+'&cmd='+encodeURIComponent(cmd);
          fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
          .then(r=>r.json()).then(function(j){
            if(j.error){ append('<span class="a-r">'+esc(j.error)+'</span>\n'); finish(); return; }
            curRun=j.runid; if(j.cwd) setCwd(j.cwd);
            poll(j.runid, 0);
          }).catch(function(){ append('<span class="a-r">[no se pudo lanzar]</span>\n'); finish(); });
        }

        inp.addEventListener('keydown', function(e){
          if(e.key==='Enter'){
            var cmd=inp.value; if(!cmd.trim()||running) return;
            hist.push(cmd); hi=hist.length; inp.value='';
            if(cmd.trim()==='clear'||cmd.trim()==='cls'){ out.innerHTML=''; return; }
            run(cmd);
          } else if(e.key==='ArrowUp'){ if(hi>0){hi--; inp.value=hist[hi]; e.preventDefault();} }
          else if(e.key==='ArrowDown'){ if(hi<hist.length-1){hi++; inp.value=hist[hi];} else {hi=hist.length; inp.value='';} }
          else if(e.key==='l' && e.ctrlKey){ out.innerHTML=''; e.preventDefault(); }
        });
        cwdEl.textContent=<?= json_encode(term_win(term_default_cwd($ROOT))) ?>;
        inp.focus();
      })();
      </script>
    <?php endif; ?>

  <?php endif; ?>

  </div>

  <footer>lua-server &middot; Apache + mod_fcgid &middot; panel solo accesible desde esta máquina</footer>
</body>
</html>
