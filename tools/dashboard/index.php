<?php
// ============================================================
//  lua-server :: panel de gestion (solo localhost)
//  - Proyectos: crear / eliminar / cambiar version de PHP
//  - PHP: editar overrides del php.ini de cada version
// ============================================================
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

// Sonda de salud: si esta pagina responde, Apache esta arriba. La usan las recargas tras
// una accion que reinicia Apache, para no navegar mientras Apache aun esta caido (lo que
// mostraba "connection refused"). Debe ir lo primero, sin dependencias.
if (isset($_GET['ping'])) { header('Content-Type: text/plain; charset=utf-8'); echo 'ok'; exit; }

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
  'error_reporting'     => ['label' => 'Nivel de errores',        'type' => 'select', 'ph' => '', 'options' => [
      'E_ALL'                                  => 'Todo (E_ALL)',
      'E_ALL & ~E_DEPRECATED & ~E_NOTICE'      => 'Todo menos avisos y deprecaciones (recomendado)',
      'E_ALL & ~E_DEPRECATED'                  => 'Todo menos deprecaciones',
      'E_ERROR | E_WARNING | E_PARSE'          => 'Solo errores y warnings',
      'E_ERROR | E_PARSE'                      => 'Solo errores fatales',
      '0'                                      => 'Ninguno',
  ]],
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
// Comandos personalizados guardados en el runner ("Ejecutar en <proyecto>"), globales
// (validos para cualquier proyecto, no por-proyecto): config/run-presets.json.
function run_presets_file($root){ return $root.'/config/run-presets.json'; }
function run_presets_load($root){
    $d = read_json(run_presets_file($root));
    return is_array($d) ? array_values(array_filter($d, 'is_string')) : [];
}
function valid_name($n){ return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/', $n); }
// Nombre de carpeta real (puede tener mayusculas/espacios/unicode, a diferencia de
// valid_name) que existe literalmente como hijo directo de www\. Se usa para validar
// $_POST['name'] en acciones sobre carpetas sin registrar sin exigir que el nombre de
// carpeta sea ademas un slug valido (evita path traversal comprobando contra scandir,
// no con regex: cualquier entrada de scandir($www) es por definicion un hijo directo).
function is_www_child_dir($www, $name){
    // Rechazar traversal ANTES de tocar disco: scandir($www) SIEMPRE incluye '.' y '..',
    // asi que comprobar contra scandir NO basta (name='..' pasaria y $www/.. es la raiz
    // del stack). Rechazamos '.'/'..' y cualquier separador de ruta explicitamente.
    if ($name === '' || $name === '.' || $name === '..' || strpbrk($name, "/\\") !== false) return false;
    if (!is_dir("$www/$name")) return false;
    $entries = @scandir($www);
    return $entries !== false && in_array($name, $entries, true);
}
// Convierte un nombre de carpeta cualquiera en una clave valida para sites.json
// (minusculas, solo [a-z0-9_-]), sin colisionar con claves ya existentes.
function slug_from_name($name, $sites){
    $s = strtolower($name);
    $s = preg_replace('/[^a-z0-9_-]+/', '-', $s);
    $s = trim(preg_replace('/-+/', '-', $s), '-_');
    if ($s === '' || !preg_match('/^[a-z0-9]/', $s)) { $s = 'p-'.$s; }
    $s = substr($s, 0, 41);
    $key = $s; $i = 2;
    while (isset($sites[$key])) { $suffix = '-'.$i; $key = substr($s, 0, 41-strlen($suffix)).$suffix; $i++; }
    return $key;
}
// Carpetas www\<x> que ya estan en uso por algun sitio registrado (por clave directa
// o por 'path'), indexadas por ruta real. Evita que una carpeta adoptada con una clave
// distinta a su nombre real (ver slug_from_name) siga apareciendo como "sin registrar".
function registered_dirs($www, $sites){
    $used = [];
    foreach ($sites as $sName=>$sInfo) {
        $r = realpath(project_dir($www, $sInfo, $sName));
        if ($r !== false) $used[$r] = true;
    }
    return $used;
}
function valid_domain($d){ return (bool)preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $d); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
// Dominio efectivo de un sitio: su 'domain' explicito o '<clave>.<tld>' por defecto.
function effective_domain($info, $key, $tld){
    if (is_array($info) && !empty($info['domain'])) return strtolower($info['domain']);
    return strtolower($key.'.'.$tld);
}
// ¿Algun OTRO sitio (distinto de $exceptKey) ya usa este dominio, o el alias www.?
// Devuelve la clave del sitio en conflicto, o null. Evita dos vhosts con el mismo
// ServerName (Apache no da error: sirve el que carga primero por orden de fichero, y el
// otro proyecto queda muerto en silencio).
function domain_in_use($sites, $domain, $exceptKey, $tld){
    $domain = strtolower($domain);
    foreach ($sites as $k=>$info){
        if ($k === $exceptKey) continue;
        $eff = effective_domain($info, $k, $tld);
        if ($eff === $domain || ('www.'.$eff) === $domain || $eff === ('www.'.$domain)) return $k;
    }
    return null;
}
// ¿Hay algo escuchando en 127.0.0.1:$port? Sondeo de socket corto (200 ms), SIN lanzar
// subproceso (mod_fcgid + shell_exec bajo Apache en Windows puede colgar el worker, ver
// CLAUDE.md). Para derivar el estado REAL de MySQL/PostgreSQL/Mailpit, no solo el flag.
function svc_alive($port){ $c=@fsockopen('127.0.0.1',(int)$port,$errno,$errstr,0.2); if($c){ fclose($c); return true; } return false; }
// Estado de un servicio a partir del flag (deseado) + proceso real: tri-estado. Un flag
// huerfano (activado pero el proceso caido/watcher parado) ya NO miente diciendo ACTIVO.
function svc_status($on, $port){
    if (!$on) return ['err','INACTIVO'];
    return svc_alive($port) ? ['ok','ACTIVO'] : ['warn','ACTIVÁNDOSE…'];
}
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
// Como git_exec pero conserva stderr y el exito/fallo por separado, para poder explicar
// POR QUE ha fallado un paso (usado por "Conectar repositorio Git": init/commit/remote
// necesitan feedback, no solo null-si-algo-fue-mal).
function git_exec_verbose($dir, $args){
    $bin = git_binary();
    if ($bin === false) return [false, '', 'git no está disponible en esta máquina.'];
    $descriptors = [1=>['pipe','w'], 2=>['pipe','w']];
    $safe = '-c '.escapeshellarg('safe.directory='.$dir);
    $proc = @proc_open($bin.' '.$safe.' '.$args, $descriptors, $pipes, $dir);
    if (!is_resource($proc)) return [false, '', 'No se pudo ejecutar git.'];
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code === 0, $out, trim($err)];
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

// ---------------- Docker (opcional: solo se muestra si se detecta instalado) ----------------
// Docker Desktop es un instalador de sistema pesado (WSL2/Hyper-V, admin, reinicio) muy
// distinto de MariaDB/MongoDB (binarios portables que este mismo proyecto descarga y
// lanza): por eso aqui NO se instala nada, solo se detecta lo que ya haya en la maquina
// y se gestiona (contenedores). Comandos rapidos (ps/start/stop/...) van por proc_open
// en forma de array -- igual de seguro que git_exec (el proceso termina solo y se drenan
// los pipes hasta EOF, no cuelga el worker de mod_fcgid) pero sin la pesadilla de comillas
// de cmd.exe que tendria un string con los templates --format de Docker ('{{'/'}}').
// Lanzar Docker Desktop en si (una app persistente, no un comando que termina) es la
// EXCEPCION: eso va por COM WScript.Shell.Run fire-and-forget, igual que apagar/reiniciar.
function docker_binary(){
    static $bin = null;
    if ($bin !== null) return $bin;
    $candidates = array_filter([
        getenv('ProgramFiles') ? getenv('ProgramFiles').'\Docker\Docker\resources\bin\docker.exe' : null,
        getenv('ProgramW6432') ? getenv('ProgramW6432').'\Docker\Docker\resources\bin\docker.exe' : null,
        'C:\\Program Files\\Docker\\Docker\\resources\\bin\\docker.exe',
    ]);
    foreach ($candidates as $cand) { if (is_file($cand)) return $bin = $cand; }
    $probe = @proc_open(['docker','version','--format','{{.Client.Version}}'], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (is_resource($probe)) {
        @stream_get_contents($pipes[1]); @fclose($pipes[1]);
        @stream_get_contents($pipes[2]); @fclose($pipes[2]);
        if (proc_close($probe) === 0) return $bin = 'docker';
    }
    return $bin = false;
}
function docker_desktop_exe(){
    $candidates = array_filter([
        getenv('ProgramFiles') ? getenv('ProgramFiles').'\Docker\Docker\Docker Desktop.exe' : null,
        getenv('ProgramW6432') ? getenv('ProgramW6432').'\Docker\Docker\Docker Desktop.exe' : null,
        'C:\\Program Files\\Docker\\Docker\\Docker Desktop.exe',
    ]);
    foreach ($candidates as $cand) { if (is_file($cand)) return $cand; }
    return null;
}
function docker_installed(){ return docker_binary() !== false; }
// El pipe con nombre \\.\pipe\docker_engine solo existe mientras dockerd esta escuchando:
// sondearlo es instantaneo (no lanza ningun proceso), a diferencia de "docker version".
function docker_running(){ return @file_exists('\\\\.\\pipe\\docker_engine'); }
function docker_exec($args){
    $bin = docker_binary();
    if ($bin === false) return null;
    $proc = @proc_open(array_merge([$bin], $args), [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    return ['ok'=>$code===0, 'out'=>$out, 'err'=>$err];
}
// Listado de contenedores (activos + parados). null = docker no disponible / fallo el comando.
function docker_containers(){
    $r = docker_exec(['ps','-a','--format',"{{.ID}}\t{{.Image}}\t{{.Names}}\t{{.Status}}\t{{.Ports}}"]);
    if ($r===null || !$r['ok']) return null;
    $trimmed = trim($r['out']);
    if ($trimmed==='') return [];
    $out=[];
    foreach (explode("\n", $trimmed) as $line) {
        $p = explode("\t", rtrim($line, "\r"));
        $out[] = ['id'=>$p[0]??'', 'image'=>$p[1]??'', 'name'=>$p[2]??'', 'status'=>$p[3]??'', 'ports'=>$p[4]??''];
    }
    return $out;
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
    $used = registered_dirs($www, $sites);
    if (is_dir($www)) {
        foreach (scandir($www) as $d) {
            if ($d==='.'||$d==='..') continue;
            $full = "$www/$d";
            if (!is_dir($full)) continue;
            if (isset($sites[$d])) continue;
            $r = realpath($full);
            if ($r !== false && isset($used[$r])) continue;
            $out[]=$d;
        }
    }
    sort($out);
    return $out;
}
// Proyectos registrados en sites.json cuya carpeta ya no existe (borrada a mano
// desde fuera del panel, p.ej. el Explorador de Windows). El panel nunca borra
// solo un sitio de sites.json: hace falta "Sincronizar proyectos".
function missing_projects($www, $sites){
    $out = [];
    // 'path' fuera de www\ = proyecto EXTERNO de verdad (disco USB/red que puede estar
    // desmontado): no auto-desregistrar por carpeta ausente, se gestiona a mano. 'path'
    // DENTRO de www\ (caso de un proyecto adoptado con clave normalizada en minusculas,
    // p.ej. "arquitecturaTgin" -> clave "arquitecturatgin" + path a la carpeta real) no es
    // "externo": vive en el mismo disco que todo lo demas, asi que SI se comprueba si su
    // carpeta desaparecio. Comparacion por texto (no realpath): si la carpeta ya no existe,
    // realpath() devolveria false para ambos casos y no podriamos distinguirlos.
    $wwwNorm = rtrim(str_replace('\\','/',$www),'/').'/';
    foreach ($sites as $name=>$info) {
        $path = (is_array($info) && !empty($info['path'])) ? $info['path'] : null;
        if ($path !== null) {
            $pathNorm = str_replace('\\','/',$path);
            if (stripos($pathNorm, $wwwNorm) !== 0) continue; // externo de verdad: se salta
            if (!is_dir($path)) $out[] = $name;
            continue;
        }
        if (!is_dir($www.'/'.$name)) $out[] = $name;
    }
    sort($out);
    return $out;
}
// Busca la clave real de sites.json para un nombre de proyecto que puede venir de
// un formulario/URL con mayusculas distintas (p.ej. "arquitecturaTgin", el nombre
// real de la carpeta en Windows, cuando la clave registrada es "arquitecturatgin"
// tras normalizar via slug_from_name). isset($sites[$name]) es sensible a mayusculas
// y falla en silencio con "Proyecto no valido" ante el minimo desajuste de casing o
// espacios; esto lo resuelve intentando primero la coincidencia exacta y si no,
// una comparacion insensible a mayusculas/minusculas y a espacios sueltos.
function resolve_site_key($sites, $name){
    $name = trim((string)$name);
    if ($name === '') return null;
    if (isset($sites[$name])) return $name;
    $lower = strtolower($name);
    foreach ($sites as $key=>$info) {
        if (strtolower($key) === $lower) return $key;
    }
    return null;
}
// Detecta el framework de un proyecto por sus archivos caracteristicos. Se llama solo
// al integrar (no en cada carga): el resultado se guarda en sites.json como 'type'.
// Adivina el "tipo" de un proyecto mirando sus archivos/manifiestos. Solo informativo
// (una etiqueta en la card): el servidor solo sirve PHP, pero el usuario puede tener
// tambien front-ends JS o apps Python en sus carpetas y viene bien identificarlos.
// Orden: PHP -> Python -> JS/Node. En JS se comprueba primero lo mas especifico
// (Angular/Next/Nuxt) antes que su base (React/Vue), y Vite/Node como ultimo recurso.
// Parser pragmatico de constraints de Composer para PHP (^8.1, >=7.4, >=7.4 <8.0, 8.1.*,
// ~8.1, 8.1.0, "8.1.0 || 8.2.0"...). No es un resolver semver completo: si no reconoce la
// constraint, devuelve null (se usa la version por defecto del panel, sin romper nada).
// Elige, de entre las versiones instaladas, la MAS ALTA que cumple la constraint.
function pick_php_for_constraint($constraint, $installedVers){
    $min = null; $max = null;
    foreach (preg_split('/[|,\s]+/', trim((string)$constraint)) as $part) {
        if ($part === '' || $part === '||') continue;
        if (!preg_match('/^(\^|~|>=|>|<=|<)?\s*(\d+)(?:\.(\d+))?/', $part, $m)) continue;
        $op = $m[1] ?? ''; $ver = $m[2].'.'.($m[3] ?? '0');
        if ($op === '<' || $op === '<=') { $max = $ver; } else { $min = $ver; }
    }
    if ($min === null) return null;
    $candidates = array_values(array_filter($installedVers, function($v) use ($min, $max) {
        if (version_compare($v, $min, '<')) return false;
        if ($max !== null && version_compare($v, $max, '>=')) return false;
        return true;
    }));
    if (!$candidates) return null;
    usort($candidates, fn($a,$b)=>version_compare($b,$a));
    return $candidates[0];
}
// Adivina la version de PHP de un proyecto (para preseleccionarla al integrarlo) a partir
// de .php-version (version exacta) o de composer.json: config.platform.php (exacta, la que
// Composer forzaria) o require.php (rango, ver pick_php_for_constraint). Devuelve una de las
// versiones REALMENTE INSTALADAS en $installedVers, o null si no hay pista o ninguna sirve.
function detect_project_php($dir, $installedVers){
    $pvFile = "$dir/.php-version";
    if (is_file($pvFile)) {
        $v = trim((string)@file_get_contents($pvFile));
        if (preg_match('/^(\d+\.\d+)/', $v, $m) && in_array($m[1], $installedVers, true)) return $m[1];
    }
    if (!is_file("$dir/composer.json")) return null;
    $data = json_decode((string)@file_get_contents("$dir/composer.json"), true);
    if (!is_array($data)) return null;
    $platform = $data['config']['platform']['php'] ?? null;
    if (is_string($platform) && preg_match('/^(\d+\.\d+)/', $platform, $m) && in_array($m[1], $installedVers, true)) return $m[1];
    $constraint = $data['require']['php'] ?? null;
    if (!is_string($constraint) || $constraint === '') return null;
    return pick_php_for_constraint($constraint, $installedVers);
}
function detect_project_type($dir){
    // --- PHP ---
    if (is_file("$dir/wp-load.php") || is_file("$dir/wp-config.php") || is_file("$dir/wp-config-sample.php")) return 'wordpress';
    if (is_file("$dir/artisan")) return 'laravel';
    if (is_file("$dir/composer.json")) {
        $data = json_decode((string)@file_get_contents("$dir/composer.json"), true);
        $require = array_merge((array)($data['require'] ?? []), (array)($data['require-dev'] ?? []));
        foreach (array_map('strtolower', array_keys($require)) as $pkg) {
            if ($pkg === 'laravel/framework') return 'laravel';
            if (strpos($pkg, 'symfony/') === 0)  return 'symfony';
            if (strpos($pkg, 'slim/slim') === 0)  return 'slim';
        }
    }
    // --- Python ---
    if (is_file("$dir/manage.py")) return 'django';
    $py = '';
    foreach (['requirements.txt','pyproject.toml','Pipfile','environment.yml'] as $pf) {
        if (is_file("$dir/$pf")) $py .= "\n".strtolower((string)@file_get_contents("$dir/$pf"));
    }
    if ($py !== '') {
        if (strpos($py,'django')  !== false) return 'django';
        if (strpos($py,'fastapi') !== false) return 'fastapi';
        if (strpos($py,'flask')   !== false) return 'flask';
        return 'python';
    }
    // --- JavaScript / Node (package.json) ---
    if (is_file("$dir/package.json")) {
        $data = json_decode((string)@file_get_contents("$dir/package.json"), true);
        // Se miran dependencies + devDependencies + peerDependencies: muchos scaffolds (p.ej.
        // los de Vite) declaran react/vue como peer y solo dejan en devDependencies el plugin
        // (@vitejs/plugin-react, @vitejs/plugin-vue), que es la senal precisa del framework.
        $deps = array_change_key_case(array_merge(
            (array)($data['dependencies'] ?? []), (array)($data['devDependencies'] ?? []),
            (array)($data['peerDependencies'] ?? [])), CASE_LOWER);
        $has = function($k) use ($deps){ return isset($deps[$k]); };
        if ($has('@angular/core'))                                              return 'angular';
        if ($has('next'))                                                       return 'nextjs';
        if ($has('nuxt') || $has('nuxt3'))                                      return 'nuxt';
        if ($has('svelte') || $has('@sveltejs/kit') || $has('@sveltejs/vite-plugin-svelte')) return 'svelte';
        if ($has('astro'))                                                      return 'astro';
        if ($has('vue') || $has('@vitejs/plugin-vue'))                          return 'vue';
        if ($has('react') || $has('react-dom') || $has('@vitejs/plugin-react')) return 'react';
        if ($has('vite'))                                                       return 'vite';
        return 'node';
    }
    return null;
}
// Etiqueta legible por tipo (o null si es desconocido). Fuente unica para las cards.
function project_type_label($type){
    $map = [
        'wordpress'=>'WordPress','laravel'=>'Laravel','symfony'=>'Symfony','slim'=>'Slim',
        'angular'=>'Angular','nextjs'=>'Next.js','nuxt'=>'Nuxt','svelte'=>'Svelte','astro'=>'Astro',
        'vue'=>'Vue','react'=>'React','vite'=>'Vite','node'=>'Node',
        'django'=>'Django','flask'=>'Flask','fastapi'=>'FastAPI','python'=>'Python',
    ];
    return $map[$type] ?? null;
}
// Icono SVG monolinea (stroke, sin marcas/logos exactos) para el badge de tipo de
// proyecto: usa currentColor para heredar el color ya definido en .typetag-<tipo>.
// Metafora reconocible por ecosistema en vez de un logo pixel-perfect (evita depender de
// reproducir marcas registradas de memoria).
function project_type_icon($type){
    $svg = [
        'wordpress' => '<circle cx="12" cy="12" r="9"/><path d="M5 9.5l2.3 6.5 2-5 2 5 2.3-6.5"/>',
        'laravel'   => '<path d="M4 19V8l8-5 8 5v11"/><path d="M4 8l8 5 8-5"/><path d="M12 13v6"/>',
        'symfony'   => '<path d="M12 3l7 3.5v5.5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6.5z"/>',
        'slim'      => '<path d="M20 4c-6.5 0-15 4-17 13 3-2 6.5-2 9-.7C11 14 12 11 12 11c-3 1-5.5 0-6.8-1.7C8 8 13 5 20 4z"/>',
        'angular'   => '<path d="M12 3l8 3-1.2 10L12 21l-6.8-5L4 6z"/><path d="M12 6.5L8 16m4-9.5L16 16M9.3 13h5.4"/>',
        'nextjs'    => '<circle cx="12" cy="12" r="9"/><path d="M9 8.5v7M9 8.5l6.5 7.5M15.5 8.5v5"/>',
        'nuxt'      => '<path d="M4 19h5.5L14 8.5 18.5 19H21"/><path d="M9.5 19L14 10l1.7 3.8"/>',
        'svelte'    => '<path d="M17 6.5c-2-2-5-2-7 0l-4 4c-1.7 1.7-1.7 4.5 0 6.2M7 17.5c2 2 5 2 7 0l4-4c1.7-1.7 1.7-4.5 0-6.2"/><path d="M8.3 15.7l7.4-7.4"/>',
        'astro'     => '<path d="M12 3c2.5 4 4 9.5 4 13.5a4 4 0 0 1-8 0C8 12.5 9.5 7 12 3z"/><path d="M9.5 15.5h5"/><circle cx="12" cy="19.5" r="1"/>',
        'vue'       => '<path d="M3 5h4l5 9 5-9h4L12 20z"/><path d="M8.5 5h3L12 8l.5-3h3L12 12z"/>',
        'react'     => '<circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/><ellipse cx="12" cy="12" rx="9" ry="4"/><ellipse cx="12" cy="12" rx="9" ry="4" transform="rotate(60 12 12)"/><ellipse cx="12" cy="12" rx="9" ry="4" transform="rotate(120 12 12)"/>',
        'vite'      => '<path d="M13 3L5 13h5.5L10 21l8.5-11H13z"/>',
        'node'      => '<path d="M12 3l7.5 4.3v9.4L12 21l-7.5-4.3V7.3z"/><path d="M9 12h6M12 9v6"/>',
        'django'    => '<path d="M6 4h6a5 5 0 0 1 5 5v11H11a5 5 0 0 1-5-5z"/><path d="M6 9h5"/>',
        'flask'     => '<path d="M10 3h4M10.5 3v6L5.5 18a2 2 0 0 0 1.8 3h9.4a2 2 0 0 0 1.8-3L13.5 9V3"/><path d="M8 15h8"/>',
        'fastapi'   => '<path d="M6 5l6 7-6 7"/><path d="M13 5l6 7-6 7"/>',
        'python'    => '<path d="M12 3c-3 0-4 1-4 3v2h4"/><path d="M9 8H6a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h2"/><path d="M12 21c3 0 4-1 4-3v-2h-4"/><path d="M15 16h3a2 2 0 0 0 2-2V11a2 2 0 0 0-2-2h-2"/><circle cx="9.5" cy="6" r=".6" fill="currentColor" stroke="none"/><circle cx="14.5" cy="18" r=".6" fill="currentColor" stroke="none"/>',
    ];
    if (!isset($svg[$type])) return '';
    return '<svg class="typeicon" viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'.$svg[$type].'</svg>';
}
// Tarjeta de un proyecto (usada tanto en "Destacados" como en "Proyectos", para no
// duplicar el HTML entre las dos secciones). Usa globales ya establecidas en la seccion
// GET (render): $WWW, $ROOT, $tld, $vers, $termOn.
function render_site_card($name, $info){
    global $WWW, $ROOT, $tld, $vers, $termOn;
    $ver = is_array($info)?($info['php']??'?'):$info;
    $dom = (is_array($info) && !empty($info['domain'])) ? $info['domain'] : $name.'.'.$tld;
    $pdir = project_dir($WWW, $info, $name);
    $extPath = (is_array($info) && !empty($info['path'])) ? $info['path'] : null;
    $locked = project_locked($pdir);
    $pinned = is_array($info) && !empty($info['pinned']);
    $hasCover = (bool)cover_path($ROOT,$name);
    $hasComposer = is_file($pdir.'/composer.json');
    $hasNpm = is_file($pdir.'/package.json');
    $hasArtisan = is_file($pdir.'/artisan');
    $pType = is_array($info) ? ($info['type'] ?? null) : null;
    $pTypeLabel = project_type_label($pType);
    $dbName = is_array($info) ? (string)($info['db'] ?? '') : '';
    ?>
          <div class="sitecard<?= $locked?' is-locked':'' ?><?= $pinned?' is-pinned':'' ?>">
            <form method="post" enctype="multipart/form-data" class="coverform" id="cover-<?= e($name) ?>">
              <input type="hidden" name="action" value="cover">
              <input type="hidden" name="name" value="<?= e($name) ?>">
              <input type="file" name="img" accept="image/*" hidden onchange="this.form.requestSubmit()">
              <button type="button" class="cover<?= $hasCover?' has':' empty' ?><?= (!$hasCover && $pType) ? ' type-'.e($pType) : '' ?>" title="<?= $hasCover?'Cambiar carátula':'Subir carátula' ?>"
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
            <form method="post" class="pinform">
              <input type="hidden" name="action" value="<?= $pinned?'unpin':'pin' ?>">
              <input type="hidden" name="name" value="<?= e($name) ?>">
              <button type="submit" class="pinbtn<?= $pinned?' is-pinned':'' ?>" title="<?= $pinned?'Quitar de Destacados':'Añadir a Destacados' ?>" aria-label="<?= $pinned?'Quitar de Destacados':'Añadir a Destacados' ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="<?= $pinned?'currentColor':'none' ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/></svg>
              </button>
            </form>
            <div class="cardbody">
              <div class="name" title="<?= e($name) ?>"><?= e($name) ?></div>
              <?php if ($pTypeLabel || $extPath): ?>
              <div class="tagrow">
                <?php if($pTypeLabel): ?><span class="typetag typetag-<?= e($pType) ?>"><?= project_type_icon($pType) ?><?= e($pTypeLabel) ?></span><?php endif; ?>
                <?php if($extPath): ?><span class="exttag" title="Proyecto externo: <?= e($extPath) ?>">&#8599; externo</span><?php endif; ?>
              </div>
              <?php endif; ?>
              <a class="url" href="http://<?= e($dom) ?>" target="_blank"><?= e($dom) ?> &#8599;</a>
            </div>
            <div class="cardfooter">
              <form method="post" class="phpselform">
                <input type="hidden" name="action" value="switch">
                <input type="hidden" name="name" value="<?= e($name) ?>">
                <select name="php" class="phpsel" onchange="this.form.dataset.loadingText='Cambiando a PHP '+this.value+'…';this.form.requestSubmit()">
                  <?php foreach ($vers as $v): ?>
                    <option value="<?= e($v) ?>" <?= $v===$ver?'selected':'' ?>>PHP <?= e($v) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
              <div class="cardactions">
                <a class="lockbtn" href="?tab=proyecto&name=<?= e(rawurlencode($name)) ?>" title="Ver detalles del proyecto" aria-label="Ver detalles del proyecto">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.5 9a2.5 2.5 0 0 1 5 0c0 1.3-.7 1.9-1.4 2.4-.6.5-1.1.9-1.1 1.6"/><line x1="12" y1="17" x2="12" y2="17.01"/></svg>
                </a>
                <?php if ($termOn && ($hasComposer || $hasNpm || $hasArtisan)): ?>
                  <button type="button" class="runbtn lua-runbtn" title="Ejecutar Composer/NPM/Artisan" aria-label="Ejecutar Composer/NPM/Artisan" data-name="<?= e($name) ?>" data-path="<?= e(term_win($pdir)) ?>" data-composer="<?= $hasComposer?'1':'0' ?>" data-npm="<?= $hasNpm?'1':'0' ?>" data-artisan="<?= $hasArtisan?'1':'0' ?>" data-php="<?= e($ver) ?>">
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
                  <button type="button" class="trashbtn" title="Eliminar" aria-label="Eliminar" onclick="luaAskDelete('<?= e($name) ?>', <?= $extPath!==null?'true':'false' ?>, <?= $dbName!==''?'true':'false' ?>, '<?= e($dbName) ?>')">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                  </button>
                <?php endif; ?>
              </div>
            </div>
          </div>
    <?php
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
// Contraseñas de los usuarios MySQL creados desde el panel (config\mysql_users.pass.json,
// fuera de git, mismo espiritu que mysql_root.pass): se guardan SOLO para poder ofrecerlos
// en el desplegable de conexiones de phpMyAdmin (pma_sync_servers) sin volver a teclearlas.
// Clave "usuario@host" porque el mismo nombre puede existir en mas de un host.
function mysql_users_passwords($root){
    $f = $root.'/config/mysql_users.pass.json';
    if (!is_file($f)) return [];
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function mysql_user_save_password($root, $user, $host, $pass){
    $d = mysql_users_passwords($root);
    $d[$user.'@'.$host] = $pass;
    @file_put_contents($root.'/config/mysql_users.pass.json', json_encode($d));
}
function mysql_user_forget_password($root, $user, $host){
    $d = mysql_users_passwords($root);
    unset($d[$user.'@'.$host]);
    @file_put_contents($root.'/config/mysql_users.pass.json', json_encode($d));
}
// Reconstruye, dentro de un bloque delimitado por marcadores, las conexiones de phpMyAdmin
// (auth_type=config, con user/password ya puestos) para root y cada usuario MySQL creado
// desde el panel -- asi el desplegable de servidores de phpMyAdmin entra directo, sin volver
// a escribir credenciales. Solo toca lo que hay ENTRE los marcadores: cualquier conexion
// que el usuario haya anadido a mano al archivo (fuera de ellos) se deja intacta. No-op si
// phpMyAdmin no esta instalado, o si el archivo no tiene ni siquiera "$i = 0;" (estructura
// demasiado distinta de lo esperado -- mejor no tocar nada que arriesgarse a romperlo).
function pma_sync_servers($root){
    $f = $root.'/tools/phpmyadmin/config.inc.php';
    if (!is_file($f)) return;
    $c = @file_get_contents($f);
    if ($c === false) return;

    $rootPass = mysql_root_pass($root);
    $entries = [['verbose'=>'root (local)', 'host'=>'127.0.0.1', 'port'=>'3306', 'user'=>'root', 'pass'=>$rootPass, 'allowNoPass'=>$rootPass==='']];

    $liveUsers = [];
    try { foreach (mysql_users() ?: [] as $u) { $liveUsers[$u['user'].'@'.$u['host']] = true; } } catch (Throwable $e) {}
    foreach (mysql_users_passwords($root) as $key => $pass) {
        if (!isset($liveUsers[$key])) continue; // cuenta ya borrada de MySQL -> no ofrecerla
        [$u, $h] = array_pad(explode('@', $key, 2), 2, '127.0.0.1');
        if (strcasecmp($u, 'root') === 0) continue; // root ya tiene su propio slot arriba
        $entries[] = ['verbose'=>$u, 'host'=>$h, 'port'=>'3306', 'user'=>$u, 'pass'=>$pass, 'allowNoPass'=>$pass===''];
    }

    $lines = ["// ===== lua-server: conexiones auto-generadas (NO editar a mano, se sobrescriben) ====="];
    foreach ($entries as $e) {
        $q = function($s){ return "'".addcslashes((string)$s, "\\'")."'"; };
        $lines[] = '$i++;';
        $lines[] = "\$cfg['Servers'][\$i]['verbose'] = ".$q($e['verbose']).';';
        $lines[] = "\$cfg['Servers'][\$i]['host'] = ".$q($e['host']).';';
        $lines[] = "\$cfg['Servers'][\$i]['port'] = ".$q($e['port']).';';
        $lines[] = "\$cfg['Servers'][\$i]['auth_type'] = 'config';";
        $lines[] = "\$cfg['Servers'][\$i]['user'] = ".$q($e['user']).';';
        $lines[] = "\$cfg['Servers'][\$i]['password'] = ".$q($e['pass']).';';
        $lines[] = "\$cfg['Servers'][\$i]['AllowNoPassword'] = ".($e['allowNoPass']?'true':'false').';';
    }
    $lines[] = "// ===== fin conexiones lua-server =====";
    $block = implode("\n", $lines);

    // preg_replace_callback (no preg_replace): el reemplazo se devuelve literal, si no PHP
    // intenta interpretar los "$i"/"$cfg" del bloque como referencias de grupo ($1, etc.).
    $marker = '/\/\/ ===== lua-server: conexiones auto-generadas.*?\/\/ ===== fin conexiones lua-server =====/s';
    if (preg_match($marker, $c)) {
        $c = preg_replace_callback($marker, function($m) use ($block){ return $block; }, $c, 1);
    } elseif (preg_match('/\$i\s*=\s*0;/', $c)) {
        $c = preg_replace_callback('/(\$i\s*=\s*0;)/', function($m) use ($block){ return $m[1]."\n\n".$block; }, $c, 1);
    } else {
        return;
    }
    @file_put_contents($f, $c);
}
// Sincroniza el nombre de marca (junto al logo, en la cabecera de navegacion de
// phpMyAdmin) con el que se acaba de guardar en el panel. El nombre va "horneado"
// como texto literal en el tema (content:"..." de un ::after, ver
// config\phpmyadmin-theme\override.css) porque CSS no puede leer config de PHP en
// caliente: aqui se parchea el css ya generado en disco. No-op si phpMyAdmin no
// esta instalado (Install-CatalogItem ya usa el nombre correcto en instalaciones
// nuevas, ver config\install-lib.ps1).
function pma_sync_brand_name($root, $name){
    $f = $root.'/tools/phpmyadmin/themes/lua/css/theme.css';
    if (!is_file($f)) return;
    $c = @file_get_contents($f);
    if ($c === false) return;
    $lit = '"'.addcslashes($name !== '' ? $name : 'lua-server', "\\\"").'"';
    $c = preg_replace_callback('/(#pma_navigation_header\s+#pmalogo::after\{\s*content:)"[^"]*"/',
            function($m) use ($lit){ return $m[1].$lit; }, $c, 1);
    @file_put_contents($f, $c);
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
            // Host vacio = rol interno (p.ej. PUBLIC en MariaDB 10.11+), no una cuenta con login real:
            // nunca se crea desde este panel y "Eliminar" fallaria (valid_mysql_host lo rechaza).
            if ($row['User'] === '' || $row['Host'] === '' || in_array($row['User'], $sys, true)) continue;
            $out[] = ['user'=>$row['User'], 'host'=>$row['Host']];
        }
        usort($out, function($a,$b){ return [$a['user'],$a['host']] <=> [$b['user'],$b['host']]; });
        return $out;
    } catch (Throwable $e) { return null; }
}
// Deduce a que bases de datos tiene acceso un usuario, leyendo SHOW GRANTS (evita depender
// de columnas internas que cambian entre versiones de MariaDB/MySQL). null si falla.
function mysql_user_scope($pdo, $user, $host){
    try {
        $rows = $pdo->query('SHOW GRANTS FOR '.$pdo->quote($user).'@'.$pdo->quote($host))->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return null; }
    $all = false; $dbs = [];
    foreach ($rows as $line) {
        // La primera fila de toda cuenta es "GRANT USAGE ON *.* TO ..." (solo para colgar la
        // contraseña): no cuenta como acceso real, por eso se excluye explicitamente.
        if (preg_match('/^GRANT\s+(.+?)\s+ON\s+\*\.\*/i', $line, $m)) {
            if (strcasecmp(trim($m[1]), 'USAGE') !== 0) $all = true;
        } elseif (preg_match('/^GRANT\s+.+?\s+ON\s+`([^`]+)`\.\*/i', $line, $m)) {
            $dbs[] = $m[1];
        }
    }
    return ['all'=>$all, 'dbs'=>array_values(array_unique($dbs))];
}

// ---------------- PostgreSQL (mismo patron que MySQL, via pdo_pgsql) ----------------
// Prefijo pgsrv_ para no chocar con las funciones nativas pg_* de la extension pgsql.
// Identificador Postgres: empieza por letra/_ y no lleva mas que letras/numeros/_.
function valid_pg_ident($n){ return (bool)preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/', (string)$n); }
function pgsrv_pass($root){ $f=$root.'/config/postgres_root.pass'; return is_file($f)?trim((string)@file_get_contents($f)):''; }
function pgsrv_pdo($db='postgres'){
    global $ROOT;
    if (!extension_loaded('pdo_pgsql')) { throw new RuntimeException('La extension pdo_pgsql no esta cargada.'); }
    $pass = pgsrv_pass($ROOT);
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname='.$db, 'postgres', $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>3]);
}
// null = no se pudo conectar; array de nombres de bases de datos de usuario.
function pgsrv_databases(){
    try {
        $pdo = pgsrv_pdo();
        $out = [];
        // se ocultan las plantillas y la BD de mantenimiento 'postgres' (equivale a los
        // esquemas de sistema que se ocultan en MySQL).
        $sql = "SELECT datname FROM pg_database WHERE datistemplate=false AND datname<>'postgres' ORDER BY datname";
        foreach ($pdo->query($sql) as $row) { $out[] = $row['datname']; }
        return $out;
    } catch (Throwable $e) { return null; }
}
// null = no se pudo conectar; array de ['name'=>..,'super'=>bool,'login'=>bool] (sin roles internos pg_*).
function pgsrv_roles(){
    try {
        $pdo = pgsrv_pdo();
        $out = [];
        $sql = "SELECT rolname, rolsuper, rolcanlogin FROM pg_roles WHERE rolname NOT LIKE 'pg\\_%' ORDER BY rolname";
        foreach ($pdo->query($sql) as $row) {
            $out[] = ['name'=>$row['rolname'], 'super'=>(bool)$row['rolsuper'], 'login'=>(bool)$row['rolcanlogin']];
        }
        return $out;
    } catch (Throwable $e) { return null; }
}

// ---------------- Redis: conexiones guardadas + cliente RESP propio ----------------
// Mismo modelo que SQL Server (ver mas abajo): NO se gestiona un motor propio aqui, se conecta
// a un Redis existente -- el de un contenedor de Docker, uno nativo, o uno de red. Por eso lo
// primero es una lista de conexiones guardadas y no un flag de encendido.
//
// Se habla el protocolo a pelo por fsockopen en vez de usar la extension php_redis. Motivos:
//  1. php_redis NO viene con PHP en Windows y su instalacion depende de que casen version, NTS
//     y toolset de VC (ver el mapa $PhpRedisBuilds en lua.ps1). Si el gestor dependiera de ella,
//     no funcionaria hasta tenerla instalada en la version que sirve el panel.
//  2. RESP es trivial: 5 tipos de respuesta y los comandos son arrays de bulk strings. Salen
//     ~60 lineas y funciona en cualquier PHP, con o sin extension.
// La extension sigue siendo util para las APPS del usuario, pero este gestor no la necesita.
// ---------------- Doctor: diagnostico automatico de las trampas conocidas ----------------
// Convierte el conocimiento acumulado en CLAUDE.md (puertos robados por Docker, watchers
// fantasma, flags huerfanos, carpetas movidas...) en comprobaciones de un vistazo. Solo LEE:
// netstat/tasklist via @exec (mismo precedente que watcher_alive; nunca shell_exec de
// powershell, ver trampa nº5).
function doctor_listeners(){
    $out = []; $lines = [];
    @exec('netstat -ano -p TCP 2>NUL', $lines);
    $lines2 = [];
    @exec('netstat -ano -p TCPv6 2>NUL', $lines2);
    foreach (array_merge($lines, $lines2) as $l) {
        if (stripos($l, 'LISTENING') === false) continue;
        // "  TCP    127.0.0.1:80    0.0.0.0:0    LISTENING    1234"  (IPv6: "[::]:80")
        if (!preg_match('/^\s*TCP\s+(\S+):(\d+)\s+\S+\s+LISTENING\s+(\d+)/i', $l, $m)) continue;
        $out[] = ['addr'=>$m[1], 'port'=>(int)$m[2], 'pid'=>(int)$m[3], 'v6'=>(strpos($m[1],'[')!==false || strpos($m[1],':')!==false && strpos($m[1],'.')===false)];
    }
    return $out;
}
function doctor_procnames(){
    $map = []; $lines = [];
    @exec('tasklist /FO CSV /NH 2>NUL', $lines);
    foreach ($lines as $l) {
        $c = str_getcsv($l);
        if (count($c) >= 2 && is_numeric($c[1])) { $map[(int)$c[1]] = $c[0]; }
    }
    return $map;
}

// ---------------- Supervisor de procesos por proyecto ----------------
// El panel solo EDITA config\procs.json y deja flags; arrancar/vigilar/reiniciar lo hace el
// watcher (ver el bloque del supervisor en Cmd-Watch de lua.ps1). El estado en vivo se lee de
// tmp\procs\state.json, que el watcher reescribe solo cuando cambia.
function procs_file($root){ return $root.'/config/procs.json'; }
function procs_load($root){
    if (!is_file(procs_file($root))) return [];
    $d = json_decode((string)@file_get_contents(procs_file($root)), true);
    return is_array($d) ? $d : [];
}
function procs_save($root, $list){
    @file_put_contents(procs_file($root), json_encode(array_values($list), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function valid_proc_id($n){ return (bool)preg_match('/^[a-f0-9]{12}$/', (string)$n); }
function procs_state($root){
    $d = json_decode((string)@file_get_contents($root.'/tmp/procs/state.json'), true);
    return is_array($d) ? $d : [];
}

function redis_file($root){ return $root.'/config/redis-servers.json'; }
function redis_servers($root){
    $f = redis_file($root);
    if (!is_file($f)) return [];
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function redis_save_servers($root, $list){
    @mkdir(dirname(redis_file($root)), 0777, true);
    @file_put_contents(redis_file($root), json_encode(array_values($list), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function redis_find($root, $id){
    foreach (redis_servers($root) as $s) { if (($s['id'] ?? '') === $id) return $s; }
    return null;
}
function valid_redis_id($n){ return (bool)preg_match('/^[a-f0-9]{12}$/', (string)$n); }

// Abre el socket y autentica/selecciona base. Devuelve el recurso o lanza RuntimeException.
function redis_connect($srv, $db = 0) {
    $host = (string)($srv['host'] ?? '127.0.0.1');
    $port = (int)($srv['port'] ?? 6379);
    $errno = 0; $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, 3.0);
    if (!$fp) { throw new RuntimeException('No se pudo conectar a '.$host.':'.$port.($errstr!==''?' ('.$errstr.')':'')); }
    stream_set_timeout($fp, 5);
    $pass = (string)($srv['pass'] ?? '');
    if ($pass !== '') {
        $user = (string)($srv['user'] ?? '');
        // Redis 6+ admite AUTH <user> <pass> (ACLs); sin usuario es el AUTH clasico.
        $r = redis_cmd($fp, $user !== '' ? ['AUTH', $user, $pass] : ['AUTH', $pass]);
        if ($r instanceof RedisErr) { fclose($fp); throw new RuntimeException('Autenticación rechazada: '.$r->msg); }
    }
    if ($db > 0) {
        $r = redis_cmd($fp, ['SELECT', (string)$db]);
        if ($r instanceof RedisErr) { fclose($fp); throw new RuntimeException('No se pudo seleccionar la base '.$db.': '.$r->msg); }
    }
    return $fp;
}
// Los errores del servidor (-ERR ...) se devuelven como objeto en vez de lanzar: en la consola
// de comandos un error es un resultado legitimo que hay que mostrar, no una excepcion.
class RedisErr { public $msg; function __construct($m){ $this->msg = $m; } }

function redis_cmd($fp, array $args) {
    // Peticion RESP: array de bulk strings. Vale para cualquier comando y evita tener que
    // escapar nada (la longitud va por delante, asi que un valor con \r\n o espacios es seguro).
    $out = '*'.count($args)."\r\n";
    foreach ($args as $a) { $a = (string)$a; $out .= '$'.strlen($a)."\r\n".$a."\r\n"; }
    if (@fwrite($fp, $out) === false) { throw new RuntimeException('Se perdió la conexión al enviar el comando.'); }
    return redis_read($fp);
}
function redis_read($fp) {
    $line = fgets($fp);
    if ($line === false || $line === '') {
        $meta = stream_get_meta_data($fp);
        throw new RuntimeException(!empty($meta['timed_out']) ? 'El servidor no respondió (timeout).' : 'El servidor cerró la conexión.');
    }
    $type = $line[0];
    $body = substr($line, 1, -2);   // quita el prefijo y el \r\n
    switch ($type) {
        case '+': return $body;                    // simple string
        case '-': return new RedisErr($body);      // error
        case ':': return (int)$body;               // integer
        case '$':                                  // bulk string
            $len = (int)$body;
            if ($len === -1) return null;          // nil
            $data = '';
            // fread puede devolver menos de lo pedido: hay que insistir hasta juntar $len.
            while (strlen($data) < $len) {
                $chunk = fread($fp, $len - strlen($data));
                if ($chunk === false || $chunk === '') { throw new RuntimeException('Respuesta incompleta del servidor.'); }
                $data .= $chunk;
            }
            fread($fp, 2);                         // el \r\n final
            return $data;
        case '*':                                  // array (puede venir anidado)
            $n = (int)$body;
            if ($n === -1) return null;
            $arr = [];
            for ($i = 0; $i < $n; $i++) { $arr[] = redis_read($fp); }
            return $arr;
        default:
            throw new RuntimeException('Respuesta RESP no reconocida (prefijo "'.$type.'").');
    }
}
// Ejecuta un comando y lanza si el servidor devuelve error. Para los sitios donde un -ERR sí es
// un fallo de verdad (leer una clave, listar bases...) y no algo que mostrar tal cual.
function redis_must($fp, array $args) {
    $r = redis_cmd($fp, $args);
    if ($r instanceof RedisErr) { throw new RuntimeException($r->msg); }
    return $r;
}
// Parte una linea escrita por el usuario en la consola en argumentos, respetando comillas
// simples y dobles (para valores con espacios) igual que hace redis-cli. Devuelve [] si quedan
// comillas sin cerrar, para poder avisar en vez de mandar un comando a medias.
function redis_split_cmd($line) {
    $args = []; $cur = ''; $q = null; $has = false;
    $len = strlen($line);
    for ($i = 0; $i < $len; $i++) {
        $c = $line[$i];
        if ($q !== null) {
            if ($c === $q) { $q = null; continue; }
            // \" y \' dentro de comillas: se toma el caracter literal.
            if ($c === '\\' && $i + 1 < $len) { $cur .= $line[++$i]; continue; }
            $cur .= $c;
            continue;
        }
        if ($c === '"' || $c === "'") { $q = $c; $has = true; continue; }
        if ($c === ' ' || $c === "\t") {
            if ($cur !== '' || $has) { $args[] = $cur; $cur = ''; $has = false; }
            continue;
        }
        $cur .= $c;
    }
    if ($q !== null) return [];              // comilla sin cerrar
    if ($cur !== '' || $has) $args[] = $cur;
    return $args;
}
// Prepara una respuesta de Redis para json_encode. Los arrays anidados se dejan tal cual (el
// front los pinta recursivamente) y los nulls se marcan para poder distinguir un nil de Redis
// de una cadena vacia, que en Redis NO es lo mismo.
function redis_json_safe($v) {
    if (is_array($v)) { return array_map('redis_json_safe', $v); }
    if ($v === null)  { return ['__nil' => true]; }
    return $v;
}
// Convierte la respuesta de INFO (texto plano "clave:valor" por lineas) en array asociativo.
function redis_parse_info($txt) {
    $out = [];
    foreach (preg_split('/\r?\n/', (string)$txt) as $l) {
        if ($l === '' || $l[0] === '#') continue;
        $p = strpos($l, ':');
        if ($p === false) continue;
        $out[substr($l, 0, $p)] = substr($l, $p + 1);
    }
    return $out;
}

// ---------------- SQL Server (Microsoft): conexiones guardadas y metadatos ----------------
// A diferencia de MySQL/Postgres/Mongo, aqui NO gestionamos un motor propio: SQL Server no se
// instala con la plataforma, se conecta a uno existente (local o de red). Por eso lo primero
// es una lista de conexiones guardadas, no un flag de encendido.
//
// El fichero lleva la contraseña en claro, igual que config\mysql_root.pass y
// config\mysql_users.pass.json (mismo modelo de amenaza: el panel solo escucha en 127.0.0.1).
// Va fuera de git, como el resto de config de cada maquina.
function sqlsrv_file($root){ return $root.'/config/sqlsrv-servers.json'; }
function sqlsrv_servers($root){
    $f = sqlsrv_file($root);
    if (!is_file($f)) return [];
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function sqlsrv_save_servers($root, $list){
    @mkdir(dirname(sqlsrv_file($root)), 0777, true);
    @file_put_contents(sqlsrv_file($root), json_encode(array_values($list), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function sqlsrv_find($root, $id){
    foreach (sqlsrv_servers($root) as $s) { if (($s['id'] ?? '') === $id) return $s; }
    return null;
}
function valid_sqlsrv_id($n){ return (bool)preg_match('/^[a-f0-9]{12}$/', (string)$n); }
// Nombre de base de datos / esquema / tabla de SQL Server. Se validan ADEMAS de citarlos con
// corchetes (sqlsrv_qi): los identificadores no pueden ir como parametro preparado, asi que la
// unica defensa real es esa doble barrera.
function valid_sqlsrv_ident($n){ return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_$#@ .\-]{0,126}$/u', (string)$n); }
// Cita un identificador: [nombre], escapando el ] duplicandolo (regla de T-SQL).
function sqlsrv_qi($n){ return '['.str_replace(']', ']]', (string)$n).']'; }

// Driver ODBC a usar. Se prueba por el registro (COM RegRead, sin lanzar subprocesos: ver la
// trampa nº5 de CLAUDE.md sobre exec() bajo mod_fcgid) porque el nombre EXACTO varia segun lo
// que haya instalado la maquina y un DSN con un driver inexistente falla con un error opaco.
function sqlsrv_odbc_driver(){
    static $cached = null;
    if ($cached !== null) return $cached;
    $candidates = ['ODBC Driver 18 for SQL Server','ODBC Driver 17 for SQL Server','ODBC Driver 13 for SQL Server','SQL Server Native Client 11.0','SQL Server'];
    if (class_exists('COM')) {
        try {
            $sh = new COM('WScript.Shell');
            foreach ($candidates as $c) {
                try {
                    $v = $sh->RegRead('HKLM\\SOFTWARE\\ODBC\\ODBCINST.INI\\ODBC Drivers\\'.$c);
                    if (trim((string)$v) !== '') { return $cached = $c; }
                } catch (Throwable $e) { /* ese driver no esta: siguiente */ }
            }
        } catch (Throwable $e) { /* sin COM: nos quedamos con el fallback de abajo */ }
    }
    return $cached = 'ODBC Driver 17 for SQL Server';
}
function sqlsrv_driver_kind(){ return extension_loaded('pdo_sqlsrv') ? 'sqlsrv' : 'odbc'; }
// Conexion a un servidor guardado. $db = null -> se conecta a la BD por defecto del login.
// pdo_sqlsrv (driver nativo de Microsoft) se usa si algun dia se instala; si no, pdo_odbc, que
// ya viene con PHP en Windows y solo hay que activar.
function sqlsrv_pdo($srv, $db = null){
    $host = (string)($srv['host'] ?? '127.0.0.1');
    $port = (int)($srv['port'] ?? 1433) ?: 1433;
    $user = (string)($srv['user'] ?? '');
    $pass = (string)($srv['pass'] ?? '');
    $trust = !empty($srv['trust']);
    if ($db !== null && $db !== '' && !valid_sqlsrv_ident($db)) { throw new RuntimeException('Nombre de base de datos no válido.'); }
    // MARS (varios conjuntos de resultados activos a la vez): sin esto, tener un cursor sin
    // agotar bloquea la siguiente consulta en la MISMA conexion con "La conexion esta ocupada
    // con los resultados de otro comando". Le pasa al explorador constantemente (contar filas y
    // luego leerlas). Aun asi se cierran los cursores a mano, que es lo correcto igualmente.
    if (sqlsrv_driver_kind() === 'sqlsrv') {
        $dsn = 'sqlsrv:Server='.$host.','.$port.';LoginTimeout=8;MultipleActiveResultSets=true';
        if ($db) $dsn .= ';Database='.$db;
        if ($trust) $dsn .= ';TrustServerCertificate=1';
    } else {
        $dsn = 'odbc:Driver={'.sqlsrv_odbc_driver().'};Server='.$host.','.$port.';LoginTimeout=8;MARS_Connection=yes;';
        if ($db) $dsn .= 'Database='.$db.';';
        if ($trust) $dsn .= 'TrustServerCertificate=yes;';
    }
    return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
// [ok, mensaje]: se usa tanto en "Probar conexion" como al guardar una conexion nueva.
function sqlsrv_test($srv){
    try {
        $pdo = sqlsrv_pdo($srv);
        $v = (string)$pdo->query('SELECT @@VERSION')->fetchColumn();
        $v = trim(explode("\n", $v)[0]);
        return [true, $v];
    } catch (Throwable $e) { return [false, $e->getMessage()]; }
}
// Bases de datos accesibles. HAS_DBACCESS filtra las que el login no puede abrir: sin esto
// aparecian en la lista y luego reventaban al pinchar (tipico con logins de solo una BD).
function sqlsrv_databases($pdo){
    $sql = "SELECT name, CASE WHEN database_id <= 4 THEN 1 ELSE 0 END AS es_sistema
            FROM sys.databases
            WHERE state = 0 AND HAS_DBACCESS(name) = 1
            ORDER BY CASE WHEN database_id <= 4 THEN 1 ELSE 0 END, name";
    $out = [];
    foreach ($pdo->query($sql) as $r) { $out[] = ['name'=>$r['name'], 'sys'=>(bool)$r['es_sistema']]; }
    return $out;
}
// Tablas y vistas con su nº de filas APROXIMADO (sys.partitions, instantaneo). El recuento
// exacto solo se hace al abrir una tabla concreta: un COUNT(*) por tabla en una BD con cientos
// de tablas tardaria demasiado para pintar la barra lateral.
function sqlsrv_tables($pdo){
    $sql = "SELECT s.name AS sch, t.name AS tbl, 'table' AS kind,
                   ISNULL(SUM(CASE WHEN p.index_id IN (0,1) THEN p.rows END), 0) AS nrows
            FROM sys.tables t
            JOIN sys.schemas s ON s.schema_id = t.schema_id
            LEFT JOIN sys.partitions p ON p.object_id = t.object_id
            GROUP BY s.name, t.name
            UNION ALL
            SELECT s.name, v.name, 'view', -1
            FROM sys.views v JOIN sys.schemas s ON s.schema_id = v.schema_id
            ORDER BY sch, tbl";
    $out = [];
    foreach ($pdo->query($sql) as $r) {
        $out[] = ['schema'=>$r['sch'], 'name'=>$r['tbl'], 'kind'=>$r['kind'], 'rows'=>(int)$r['nrows']];
    }
    return $out;
}
function sqlsrv_columns($pdo, $schema, $table){
    $sql = "SELECT c.name, ty.name AS tipo, c.max_length, c.precision, c.scale,
                   c.is_nullable, c.is_identity, c.is_computed, dc.definition AS def
            FROM sys.columns c
            JOIN sys.types ty ON ty.user_type_id = c.user_type_id
            LEFT JOIN sys.default_constraints dc ON dc.object_id = c.default_object_id
            WHERE c.object_id = OBJECT_ID(?)
            ORDER BY c.column_id";
    $st = $pdo->prepare($sql);
    $st->execute([$schema.'.'.$table]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'name'      => $r['name'],
            'type'      => $r['tipo'],
            'len'       => (int)$r['max_length'],
            'precision' => (int)$r['precision'],
            'scale'     => (int)$r['scale'],
            'nullable'  => (bool)$r['is_nullable'],
            'identity'  => (bool)$r['is_identity'],
            'computed'  => (bool)$r['is_computed'],
            'default'   => $r['def'],
        ];
    }
    return $out;
}
// Columnas de la clave primaria, en orden. Lista vacia = tabla SIN clave primaria: es lo que
// decide si se puede editar fila a fila (sin PK no hay WHERE que identifique una sola fila).
function sqlsrv_pk($pdo, $schema, $table){
    $sql = "SELECT c.name
            FROM sys.indexes i
            JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
            JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
            WHERE i.is_primary_key = 1 AND i.object_id = OBJECT_ID(?)
            ORDER BY ic.key_ordinal";
    $st = $pdo->prepare($sql);
    $st->execute([$schema.'.'.$table]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}
function sqlsrv_indexes($pdo, $schema, $table){
    $sql = "SELECT i.name, i.is_unique, i.is_primary_key, i.type_desc, c.name AS col, ic.is_descending_key AS desc_
            FROM sys.indexes i
            JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
            JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
            WHERE i.object_id = OBJECT_ID(?) AND i.type > 0
            ORDER BY i.name, ic.key_ordinal";
    $st = $pdo->prepare($sql);
    $st->execute([$schema.'.'.$table]);
    $by = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = $r['name'];
        if (!isset($by[$k])) $by[$k] = ['name'=>$k, 'unique'=>(bool)$r['is_unique'], 'pk'=>(bool)$r['is_primary_key'], 'type'=>$r['type_desc'], 'cols'=>[]];
        $by[$k]['cols'][] = $r['col'].($r['desc_'] ? ' DESC' : '');
    }
    return array_values($by);
}
// Tipos cuyo valor NO es texto legible: se muestran como hex y no se editan a ciegas.
function sqlsrv_is_binary_type($t){ return in_array(strtolower((string)$t), ['binary','varbinary','image','timestamp','rowversion'], true); }
// ---- Texto y codificacion: el rodeo por hexadecimal ----
// pdo_odbc convierte el texto al codepage ANSI de Windows (1252 aqui) EN AMBOS SENTIDOS, y lo
// que no cabe en 1252 se pierde antes de que PHP lo vea (comprobado: 中 llega como '?', y al
// enviar, 'ñ' llega al servidor como dos caracteres sueltos). Eso, en un editor de filas, es
// corrupcion silenciosa de datos.
//
// Solucion sin depender de otro driver: mover el texto como BINARIO. El binario viaja en
// hexadecimal ASCII puro, inmune a cualquier codepage, y se convierte UTF-16 <-> UTF-8 en PHP.
// Verificado sin perdidas con acentos, €, chino, emoji (pares suplentes), saltos de linea,
// comillas y textos de 40.000 caracteres.
//
// Si algun dia se instala pdo_sqlsrv (que maneja UTF-8 nativamente), sqlsrv_hex_text() pasa a
// false y todo el rodeo se desactiva solo.
function sqlsrv_hex_text(){ return sqlsrv_driver_kind() === 'odbc'; }
function sqlsrv_is_text_type($t){
    return in_array(strtolower((string)$t), ['char','nchar','varchar','nvarchar','text','ntext','xml','sysname'], true);
}
// Tipos que pdo_odbc no devuelve tal cual (un "SELECT doc" sobre xml devuelve NULL aunque haya
// valor) y que tampoco admiten CAST directo: se piden con .ToString().
function sqlsrv_needs_tostring($t){
    return in_array(strtolower((string)$t), ['geography','geometry','hierarchyid'], true);
}
function sqlsrv_needs_cast($t){
    return in_array(strtolower((string)$t), ['xml','sql_variant'], true) || sqlsrv_needs_tostring($t);
}
// hex(UTF-16LE) -> UTF-8. Conserva la diferencia entre NULL y cadena vacia.
function sqlsrv_dec_text($v){
    if ($v === null) return null;
    if ($v === '')   return '';
    $bin = @hex2bin($v);
    if ($bin === false) return $v; // no venia en hex: se devuelve tal cual
    return mb_convert_encoding($bin, 'UTF-8', 'UTF-16LE');
}
// UTF-8 -> hex(UTF-16LE), para mandarlo como parametro.
function sqlsrv_enc_text($s){ return bin2hex(mb_convert_encoding((string)$s, 'UTF-16LE', 'UTF-8')); }
// Marcador de parametro para escribir texto: el CAST a varchar es imprescindible, porque el
// parametro llega como nvarchar y CONVERT(...,2) NO interpreta el hexadecimal sobre nvarchar
// (se limita a reinterpretar sus bytes). Comprobado.
function sqlsrv_text_placeholder(){ return 'CONVERT(nvarchar(max), CONVERT(varbinary(max), CAST(? AS varchar(max)), 2))'; }
// ¿El valor de esta columna viaja hexadecimado? (y por tanto hay que decodificarlo al leer)
function sqlsrv_col_is_hex($c){
    if (sqlsrv_is_binary_type($c['type'])) return false;   // el binario ya viene en hex, se muestra tal cual
    return sqlsrv_hex_text() && (sqlsrv_is_text_type($c['type']) || sqlsrv_needs_cast($c['type']));
}
// Expresion de una columna para la lista del SELECT, ya citada y con alias estable.
function sqlsrv_select_expr($c){
    $q = sqlsrv_qi($c['name']);
    $txt = sqlsrv_needs_tostring($c['type']) ? $q.'.ToString()' : 'CONVERT(nvarchar(max), '.$q.')';
    if (sqlsrv_col_is_hex($c)) { return 'CONVERT(varbinary(max), '.$txt.') AS '.$q; }
    if (sqlsrv_needs_cast($c['type'])) { return $txt.' AS '.$q; }
    return $q;
}
function sqlsrv_select_list($cols){
    if (!$cols) return '*';
    return implode(', ', array_map('sqlsrv_select_expr', $cols));
}
// Decodifica in situ las columnas hexadecimadas de un lote de filas (FETCH_NUM).
function sqlsrv_decode_rows(&$rows, $cols){
    $hex = [];
    foreach ($cols as $i => $c) { if (sqlsrv_col_is_hex($c)) $hex[] = $i; }
    if (!$hex) return;
    foreach ($rows as &$r) { foreach ($hex as $i) { if (isset($r[$i]) || $r[$i] === null) $r[$i] = sqlsrv_dec_text($r[$i]); } }
    unset($r);
}
// Etiqueta de tipo tal y como la escribirias en un CREATE TABLE (nvarchar(120), decimal(12,2)...).
function sqlsrv_type_label($c){
    $t = strtolower($c['type']);
    if (in_array($t, ['nvarchar','nchar'], true))            { $n = $c['len'] < 0 ? 'MAX' : (int)($c['len']/2); return $t.'('.$n.')'; }
    if (in_array($t, ['varchar','char','varbinary','binary'], true)) { $n = $c['len'] < 0 ? 'MAX' : $c['len']; return $t.'('.$n.')'; }
    if (in_array($t, ['decimal','numeric'], true))           { return $t.'('.$c['precision'].','.$c['scale'].')'; }
    if (in_array($t, ['datetime2','time','datetimeoffset'], true) && $c['scale'] !== 7) { return $t.'('.$c['scale'].')'; }
    return $t;
}

// ---------------- Terminal (sin PTY: ejecuta comandos, streamea su salida) ----------------
// Cada comando se lanza DESATENDIDO via COM(WScript.Shell) contra un .cmd generado,
// que redirige stdout+stderr a un .out. El panel hace polling del .out por offset.
// El cwd persiste entre comandos (el .cmd vuelca su directorio final a next.cwd).
function term_enabled($root){ return is_file($root.'/config/terminal.on'); }
// Apache corre como servicio (LocalSystem) desde que arrancó: si Composer/Node/etc. se
// instalan DESPUÉS, su PATH heredado se queda "congelado" sin esos directorios hasta
// reiniciar la máquina. Releemos el PATH de máquina en caliente desde el registro para que
// cada comando vea instalaciones nuevas sin depender de reiniciar el servicio.
// OJO: se hace vía COM (WScript.Shell), NUNCA con exec()/shell_exec() para esto — lanzar un
// subproceso propio (p.ej. powershell.exe) desde un worker de PHP bajo mod_fcgid en Windows
// puede colgar o matar ese worker (hereda los pipes del FastCGI); COM no lanza ningún proceso.
function term_fresh_machine_path(){
    try {
        $sh = new COM('WScript.Shell');
        $raw = $sh->RegRead('HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\Environment\Path');
        $expanded = trim((string)$sh->ExpandEnvironmentStrings($raw));
        if ($expanded === '') { return null; }
        // COM devuelve la cadena en el codepage ANSI del sistema (p.ej. Windows-1252 en
        // instalaciones en espanol), no en UTF-8. El wrapper .cmd hace "chcp 65001" antes de
        // esta linea, asi que si va sin convertir (p.ej. una ruta con "Vázquez"), el byte no-UTF8
        // rompe el parseo del resto del .cmd y todo el comando falla con un error de ruta.
        $utf8 = @mb_convert_encoding($expanded, 'UTF-8', 'Windows-1252');
        return ($utf8 !== false && $utf8 !== '') ? $utf8 : $expanded;
    } catch (Throwable $e) {
        return null;
    }
}
function term_valid_sid($s){ return (bool)preg_match('/^[a-f0-9]{8,40}$/', (string)$s); }
function term_dir($root,$sid){ return $root.'/tmp/terminal/'.$sid; }
function term_default_cwd($root){ $w=$root.'/www'; return str_replace('/', '\\', is_dir($w)?$w:$root); }
function term_get_cwd($root,$sid,$fallback=''){
    $f = term_dir($root,$sid).'/cwd';
    if (is_file($f)) { $c=trim((string)@file_get_contents($f)); if ($c!=='' && is_dir($c)) return $c; }
    // Sin cwd persistido todavia para esta sesion (primer comando): si nos pasaron un
    // directorio de partida valido (p.ej. el de un proyecto concreto), arrancamos ahi.
    if ($fallback!=='' && is_dir($fallback)) return str_replace('/', '\\', $fallback);
    return term_default_cwd($root);
}
function term_win($p){ return str_replace('/', '\\', $p); }

// Widget de terminal reutilizable: se instancia con un prefijo de IDs propio para poder
// incrustarlo tanto en la pestana Terminal (cwd = www) como en la ficha de un proyecto
// concreto (cwd = carpeta del proyecto), sin duplicar el marcado ni el JS.
function render_terminal_widget($prefix, $initialCwd, $autofocus=true){
    $cwdWin = str_replace('/', '\\', $initialCwd);
    ob_start(); ?>
    <div class="termwrap">
      <div class="termbar">
        <span class="muted" id="<?= e($prefix) ?>cwd">…</span>
        <div class="spacer"></div>
        <button class="btn ghost sm" id="<?= e($prefix) ?>stop" disabled>Detener</button>
        <button class="btn ghost sm" id="<?= e($prefix) ?>clear">Limpiar</button>
      </div>
      <div id="<?= e($prefix) ?>out" class="termout" aria-live="polite"></div>
      <div class="termin">
        <span class="termprompt">&gt;</span>
        <input id="<?= e($prefix) ?>cmd" class="termcmd-input" type="text" autocomplete="off" autocapitalize="off" spellcheck="false"
               placeholder="escribe un comando y pulsa Enter (p.ej. git status)" <?= $autofocus?'autofocus':'' ?>>
      </div>
    </div>
    <script>
    (function(){
      var PFX=<?= json_encode($prefix) ?>, INIT_CWD=<?= json_encode($cwdWin) ?>, AUTOFOCUS=<?= $autofocus?'true':'false' ?>;
      var out=document.getElementById(PFX+'out');
      var inp=document.getElementById(PFX+'cmd');
      var cwdEl=document.getElementById(PFX+'cwd');
      var stopBtn=document.getElementById(PFX+'stop');
      var clearBtn=document.getElementById(PFX+'clear');
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
        var body='action=term_run&sid='+sid+'&cmd='+encodeURIComponent(cmd)+'&cwd0='+encodeURIComponent(INIT_CWD);
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
      cwdEl.textContent=INIT_CWD;
      if (AUTOFOCUS) inp.focus();
    })();
    </script>
    <?php
    return ob_get_clean();
}

// ---------------- Extensiones PHP de terceros (registro) ----------------
// Nombres registrados desde el panel (p.ej. "pdo_sqlsrv"); lua.ps1 los fusiona
// con $WantExts en Set-PhpInis. Solo el nombre: la presencia real del .dll
// (bin\php\<ver>\ext\php_<nombre>.dll) es lo que decide si se activa o no
// para cada version instalada.
function extra_ext_file($root){ return $root.'/config/php/extra-extensions.json'; }
function extra_extensions($root){
    $j = json_decode((string)@file_get_contents(extra_ext_file($root)), true);
    return is_array($j) ? $j : [];
}
function save_extra_extensions($root, $list){
    @mkdir($root.'/config/php', 0777, true);
    file_put_contents(extra_ext_file($root), json_encode(array_values(array_unique($list))));
}

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

// ---------------- Marca / identidad de la plataforma ----------------
// Nombre y logo personalizables desde "Configuracion del servidor". El nombre vive en
// sites.json ($cfg['brand']['name']); el logo se guarda en data\brand\logo.<ext> (runtime,
// fuera de git) y se sirve via ?brandlogo. Si no hay logo propio, se usa el de marca por
// defecto (assets\logo.svg). Reutiliza cover_exts()/cover_mime() para validar/servir.
function brand_name($cfg){ $n = trim((string)($cfg['brand']['name'] ?? '')); return $n!=='' ? $n : 'lua-server'; }

// ---------------- Actualizaciones de la plataforma (repo de git) ----------------
// El panel NO consulta el remoto: el remoto es SSH y Apache corre como SYSTEM, sin las claves
// del usuario. Quien hace el 'git fetch' es el watcher (ver Update-Check en lua.ps1) y deja el
// resultado aqui; el panel se limita a leerlo y a pedir acciones por archivo-senal.
function update_status($root){
    $f = $root.'/tmp/update-status.json';
    if (!is_file($f)) return null;
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function update_config($root){
    $f = $root.'/config/update.json';
    $def = ['auto'=>false, 'cada_horas'=>6];
    if (!is_file($f)) return $def;
    $d = json_decode((string)@file_get_contents($f), true);
    if (!is_array($d)) return $def;
    return ['auto'=>!empty($d['auto']), 'cada_horas'=>max(1, (int)($d['cada_horas'] ?? 6))];
}
// Version a mostrar. Se prefiere la que dejo el watcher; si aun no ha corrido, se deduce
// leyendo .git a mano (sin lanzar git: es una lectura de dos archivos, y asi la cabecera no
// depende de un subproceso en cada carga de pagina).
function lua_version($root){
    $st = update_status($root);
    if ($st && !empty($st['version'])) return (string)$st['version'];
    $head = @file_get_contents($root.'/.git/HEAD');
    if ($head === false) return '';
    $head = trim($head);
    if (strpos($head, 'ref: ') === 0) {
        $ref = substr($head, 5);
        $sha = @file_get_contents($root.'/.git/'.$ref);
        if ($sha === false) {
            // Referencia empaquetada (git gc): se busca en packed-refs.
            foreach (@file($root.'/.git/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
                if (substr($l, 0, 1) === '#') continue;
                $p = explode(' ', $l, 2);
                if (count($p) === 2 && trim($p[1]) === $ref) { $sha = $p[0]; break; }
            }
        }
        return $sha ? substr(trim($sha), 0, 7) : '';
    }
    return substr($head, 0, 7);
}
function brand_logo_path($root){
    foreach (cover_exts() as $e) { $f=$root.'/data/brand/logo.'.$e; if (is_file($f)) return $f; }
    return null;
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
// Agrupa los .log de logs/apache por proyecto, a partir del sufijo de nombre que pone
// vhost.tpl ("<proyecto>-error.log" / "-access.log" / "-ssl-error.log"). Lo que no encaja
// con ningun sufijo conocido (error.log, access.log, apply.log, watcher-error.log...) es
// un log de sistema/Apache, no de un proyecto -> agrupado bajo el pseudo-proyecto '(sistema)'.
// Deriva [proyecto, kind] del nombre de un .log a partir de su sufijo (ver vhost.tpl).
// Se usa tanto para agrupar el listado (logs_group_by_project) como para saber a que
// proyecto pertenecia un archivo ya borrado (ajax 'logdelete'), donde el fichero ya no
// existe y por tanto no se puede derivar el proyecto desde $logFiles.
function log_file_project($lf){
    $suffixes = ['-ssl-error.log','-ssl-access.log','-error.log','-access.log'];
    foreach ($suffixes as $suf) {
        if (strlen($lf) > strlen($suf) && substr($lf, -strlen($suf)) === $suf) {
            return [substr($lf, 0, -strlen($suf)), substr($suf, 1, -4)]; // "-error.log" -> "error"
        }
    }
    return ['(sistema)', preg_replace('/\.log$/', '', $lf)];
}
function logs_group_by_project($logFiles){
    $byProject = [];
    foreach ($logFiles as $lf) {
        [$proj, $kind] = log_file_project($lf);
        $byProject[$proj][] = ['file'=>$lf, 'kind'=>$kind];
    }
    uksort($byProject, function($a,$b){
        if ($a==='(sistema)') return 1; if ($b==='(sistema)') return -1;
        return strnatcasecmp($a,$b);
    });
    return $byProject;
}
function log_kind_label($k){
    $map = ['error'=>'Error','access'=>'Acceso','ssl-error'=>'Error (SSL)','ssl-access'=>'Acceso (SSL)'];
    return $map[$k] ?? $k;
}
function log_project_label($p){ return $p==='(sistema)' ? 'Sistema (Apache)' : $p; }
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
// Crea el marcador de bloqueo en un proyecto. Se usa al integrar/adoptar/registrar
// proyectos ya existentes (que no crea la plataforma desde cero): por defecto quedan
// bloqueados, para no borrar sin querer codigo real de otra parte.
function lock_project_dir($dir){
    if (!is_dir($dir)) return;
    @file_put_contents($dir.'/'.LUA_LOCK_MARKER, "; lua-server :: proyecto bloqueado\r\n; Mientras exista un archivo .lua en la raiz de este proyecto,\r\n; no se puede eliminar desde el panel (http://localhost).\r\n");
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
// Tarjeta de progreso reutilizada por los jobs de import (carpeta de dumps y archivo .sql
// unico): barra real si el job reporta 'pct' (ambos lo hacen mientras corre), log al final.
function render_import_job_card($root, $j){
    $st = $j['state'] ?? '?';
    $cls = ['done'=>'ok','error'=>'err','running'=>'run','queued'=>'warn'];
    $c = $cls[$st] ?? 'run';
    $tail = in_array($st, ['running','error','queued'], true) ? job_log_tail($root, $j['id'] ?? '') : '';
    $pct = isset($j['pct']) ? max(0, min(100, (int)$j['pct'])) : null;
    ob_start(); ?>
    <div class="card" style="padding:12px 16px;margin-top:12px">
      <div class="row">
        <span class="jstate <?= $c ?>"><?= e(strtoupper($st)) ?></span>
        <span style="font-weight:700"><?= e($j['dbname'] ?? $j['name'] ?? '') ?></span>
        <span class="muted"><?= isset($j['time']) ? e($j['time']) : '' ?></span>
        <div class="spacer"></div>
        <span class="muted"><?= e($j['msg'] ?? '') ?></span>
      </div>
      <?php if ($pct !== null && in_array($st, ['running','queued'], true)): ?>
        <div class="progressbar"><div class="progressbar-fill" style="width:<?= $pct ?>%"></div></div>
        <span class="progresspct"><?= $pct ?>%</span>
      <?php elseif ($st === 'error' && $pct !== null): ?>
        <div class="progressbar"><div class="progressbar-fill err" style="width:<?= $pct ?>%"></div></div>
      <?php endif; ?>
      <?php if ($tail): ?><pre class="joblog"><?= e($tail) ?></pre><?php endif; ?>
    </div>
    <?php return ob_get_clean();
}
// El watcher es un proceso PowerShell independiente (arrancado por 'lua.ps1 start'),
// no un hijo de Apache: se comprueba igual que hace lua.ps1 (pid en tmp/watch.pid + tasklist).
function watcher_alive($root){
    // Se mira el LATIDO (tmp\watch.beat, que el watcher toca en cada vuelta de su bucle) antes
    // que el PID. El PID no sirve de indicador fiable por dos motivos:
    //   1. Con "Arrancar con Windows" el watcher corre como SYSTEM, y desde aqui no se puede
    //      consultar ese proceso -> parecia muerto estando vivo.
    //   2. watch.pid guarda solo el del ULTIMO watcher que arranco. Con dos vivos (el de SYSTEM
    //      y el que lanza 'lua.ps1 start') el archivo deja de reflejar la realidad, y si el
    //      ultimo muere el badge decia "inactivo" mientras el otro seguia procesando jobs.
    // El latido no tiene ninguno de los dos problemas: lo escribe quien de verdad esta vivo.
    $bf = $root.'/tmp/watch.beat';
    if (is_file($bf)) {
        $beat = (int)trim((string)@file_get_contents($bf));
        // 15s de margen: el bucle late cada ~1s, pero una vuelta puede tardar si esta aplicando
        // cambios o reiniciando Apache, y no queremos parpadeos en el badge por eso.
        if ($beat > 0 && (time() - $beat) <= 15) return true;
    }
    // Compatibilidad con watchers arrancados ANTES de que existiera el latido (siguen con su
    // codigo viejo cargado en memoria y nunca escribiran watch.beat).
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
        // Una caratula puede ser un SVG con <script>/on*: sin esto, navegar directo a
        // ?cover=... ejecutaria ese JS en el origen del panel (XSS almacenado). 'sandbox'
        // (sin allow-scripts) bloquea la ejecucion pero preserva el render como <img>.
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        readfile($f); exit;
    }
    http_response_code(404); exit;
}

// ---------------- Servir el logo de la plataforma: ?brandlogo ----------------
// Devuelve el logo propio (data\brand\logo.*) si existe; si no, cae al logo de marca
// por defecto. Se usa tanto en el header como de favicon cuando hay logo propio.
if (isset($_GET['brandlogo'])) {
    $f = brand_logo_path($ROOT);
    if (!$f) { $def = __DIR__.'/assets/logo.svg'; if (is_file($def)) $f = $def; }
    if ($f) {
        header('Content-Type: '.cover_mime($f));
        header('Cache-Control: no-cache');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:");
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

// ---------------- Exportar base de datos PostgreSQL: ?export_pg=<nombre> ----------------
if (isset($_GET['export_pg'])) {
    $db = (string)$_GET['export_pg'];
    $dumpExe = $ROOT.'/bin/postgres/bin/pg_dump.exe';
    if (!valid_pg_ident($db)) { http_response_code(400); exit('Nombre de base de datos no válido.'); }
    if (!is_file($dumpExe)) { http_response_code(503); exit('PostgreSQL no está instalado.'); }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$db.'-'.date('Y-m-d_His').'.sql"');
    $pass = pgsrv_pass($ROOT);
    if ($pass !== '') { putenv('PGPASSWORD='.$pass); }
    $cmd = '"'.$dumpExe.'" --host=127.0.0.1 --port=5432 --username=postgres --no-password '.escapeshellarg($db);
    passthru($cmd);
    exit;
}

// ---------------- AJAX: dialogo nativo "Elegir carpeta" (via el watcher, ver mas abajo) ----------------
// El panel corre bajo el servicio de Apache (sesion 0, sin escritorio): no puede mostrar un
// FolderBrowserDialog el mismo. En vez de eso deja una peticion en tmp/pickfolder/ y el watcher
// (que si corre en la sesion interactiva del usuario) la recoge, muestra el dialogo nativo y
// escribe el resultado; este endpoint solo hace polling sobre ese resultado.
if (($_GET['ajax'] ?? '') === 'pickfolder_start') {
    header('Content-Type: application/json; charset=utf-8');
    if (!watcher_alive($ROOT)) { echo json_encode(['error'=>'El watcher no está activo: no se puede abrir el selector de carpetas.']); exit; }
    $pfDir = $ROOT.'/tmp/pickfolder';
    @mkdir($pfDir, 0777, true);
    $pfId = bin2hex(random_bytes(8));
    file_put_contents($pfDir.'/'.$pfId.'.req', '');
    echo json_encode(['id'=>$pfId]);
    exit;
}
if (($_GET['ajax'] ?? '') === 'pickfolder_poll') {
    header('Content-Type: application/json; charset=utf-8');
    $pfId = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{16}$/', $pfId)) { echo json_encode(['status'=>'error','msg'=>'id no válido']); exit; }
    $pfRes = $ROOT.'/tmp/pickfolder/'.$pfId.'.res';
    if (!is_file($pfRes)) { echo json_encode(['status'=>'pending']); exit; }
    $pfData = json_decode((string)@file_get_contents($pfRes), true);
    @unlink($pfRes);
    echo json_encode($pfData ?: ['status'=>'error','msg'=>'respuesta ilegible']);
    exit;
}

// ---------------- AJAX: un nivel del arbol de archivos de un proyecto (carga perezosa) ----------------
if (($_GET['ajax'] ?? '') === 'tree') {
    header('Content-Type: application/json; charset=utf-8');
    $tName = (string)($_GET['name'] ?? '');
    $tRel  = (string)($_GET['rel'] ?? '');
    $tCfg   = read_json($CFG_FILE) ?: ['sites'=>[]];
    $tSites = $tCfg['sites'] ?? [];
    $tKey = resolve_site_key($tSites, $tName);
    if ($tKey === null) { echo json_encode(['error'=>'Proyecto no válido.']); exit; }
    $tName = $tKey;
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
    $fKey = resolve_site_key($fSites, $fName);
    if ($fKey === null) { echo json_encode(['error'=>'Proyecto no válido.']); exit; }
    $fName = $fKey;
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
    // El editor trabaja en UTF-8. Si el archivo no es UTF-8 valido (proyectos PHP legacy en
    // Windows-1252/ISO-8859-1, tipicos en apps españolas antiguas), lo convertimos a UTF-8
    // para editar y recordamos la codificacion original en 'enc' para reescribirlo igual al
    // guardar (round-trip). Antes json_encode devolvia false ante bytes no-UTF8 -> cuerpo
    // vacio -> el editor mostraba un falso "error de red" y NINGUN archivo legacy se abria.
    $enc = 'UTF-8';
    if (!mb_check_encoding($fData, 'UTF-8')) {
        $fData = mb_convert_encoding($fData, 'UTF-8', 'Windows-1252');
        $enc = 'Windows-1252';
    }
    $payload = json_encode(['content'=>$fData, 'enc'=>$enc], JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false) { echo json_encode(['error'=>'No se pudo codificar el contenido del archivo.']); exit; }
    echo $payload;
    exit;
}

// ---------------- AJAX: guardar un archivo editado desde el arbol del proyecto ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'file_write') {
    header('Content-Type: application/json; charset=utf-8');
    $fName = (string)($_POST['name'] ?? '');
    $fRel  = (string)($_POST['rel'] ?? '');
    $fContent = (string)($_POST['content'] ?? '');
    $fEnc  = (string)($_POST['enc'] ?? 'UTF-8');
    $fCfg   = read_json($CFG_FILE) ?: ['sites'=>[]];
    $fSites = $fCfg['sites'] ?? [];
    $fKey = resolve_site_key($fSites, $fName);
    if ($fKey === null) { echo json_encode(['error'=>'Proyecto no válido.']); exit; }
    $fName = $fKey;
    $fBase = realpath(project_dir($WWW, $fSites[$fName], $fName));
    if ($fBase === false) { echo json_encode(['error'=>'No se encontró el proyecto.']); exit; }
    $fTarget = realpath($fBase.'/'.$fRel);
    $fBaseN = rtrim(str_replace('\\','/',$fBase),'/');
    $fTargetN = $fTarget!==false ? rtrim(str_replace('\\','/',$fTarget),'/') : '';
    $fInside = $fTarget!==false && is_file($fTarget) && ($fTargetN===$fBaseN || strpos($fTargetN, $fBaseN.'/')===0);
    if (!$fInside) { echo json_encode(['error'=>'Ruta no válida.']); exit; }
    if (strlen($fContent) > 2*1024*1024) { echo json_encode(['error'=>'Contenido demasiado grande (>2 MB).']); exit; }
    // Round-trip: si el archivo era Windows-1252 al abrirlo, se reescribe en Windows-1252
    // (no convertimos su codificacion en silencio, que podria romper una app legacy que
    // asume latin1). El editor devuelve el 'enc' que le dio file_read.
    $toWrite = (strtoupper($fEnc) === 'WINDOWS-1252') ? mb_convert_encoding($fContent, 'Windows-1252', 'UTF-8') : $fContent;
    if (@file_put_contents($fTarget, $toWrite) === false) { echo json_encode(['error'=>'No se pudo guardar el archivo (¿permisos?).']); exit; }
    echo json_encode(['ok'=>true]);
    exit;
}

// ---------------- AJAX: borrar/vaciar un log de la pestaña Logs (JSON, no PRG) ----------------
// Igual razon que el explorador de SQL Server (ver mas abajo): el desplegable de
// proyecto/archivo es demasiado interactivo para el patron PRG del resto del panel. Con
// PRG, tras borrar habia que "adivinar" en el redirect que proyecto/archivo tocaba mostrar
// despues -- y esa heuristica se quedaba corta en mas de un caso (p.ej. al borrar el ultimo
// .log de un proyecto), perdiendo la seleccion del usuario en cada recarga. Por AJAX, el
// propio cliente decide que mostrar despues sin depender de ninguna heuristica del servidor.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['ajax'] ?? '', ['logdelete','logclear'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $op = $_POST['ajax'];
    $lf = safe_logname($_POST['log'] ?? '');
    if ($lf === '' || !is_file($ROOT.'/logs/apache/'.$lf)) { echo json_encode(['error'=>'Ese log ya no existe.']); exit; }

    if ($op === 'logclear') {
        @file_put_contents($ROOT.'/logs/apache/'.$lf, '');
        echo json_encode(['ok'=>true]);
        exit;
    }

    // logdelete
    @unlink($ROOT.'/logs/apache/'.$lf);
    [$lProj] = log_file_project($lf);
    $lRemaining = [];
    foreach (glob($ROOT.'/logs/apache/*.log') as $f) { $lRemaining[] = basename($f); }
    $lProjFiles = logs_group_by_project($lRemaining)[$lProj] ?? [];
    $lNext = '';
    foreach ($lProjFiles as $f) { if ($f['kind']==='error') { $lNext = $f['file']; break; } }
    if ($lNext === '' && $lProjFiles) { $lNext = $lProjFiles[0]['file']; }
    echo json_encode([
        'ok'      => true,
        'files'   => array_map(function($f){ return ['file'=>$f['file'], 'label'=>log_kind_label($f['kind'])]; }, $lProjFiles),
        'next'    => $lNext,
        'content' => $lNext !== '' ? highlight_error_log(tail_file($ROOT.'/logs/apache/'.$lNext, 300)) : '',
    ]);
    exit;
}

// ---------------- AJAX del supervisor de procesos (estado en vivo + log) ----------------
if (($_REQUEST['ajax'] ?? '') === 'procs') {
    header('Content-Type: application/json; charset=utf-8');
    $pop = (string)($_REQUEST['op'] ?? '');
    if ($pop === 'state') {
        // Se devuelven definiciones + estado del watcher mezclados: el JS repinta los badges
        // con esto cada pocos segundos sin recargar la pagina.
        $state = procs_state($ROOT);
        $out = [];
        foreach (procs_load($ROOT) as $p) {
            $id = (string)($p['id'] ?? '');
            $s = $state[$id] ?? null;
            $out[] = [
                'id'      => $id,
                'enabled' => (bool)($p['enabled'] ?? false),
                'running' => (bool)($s['running'] ?? false),
                'pid'     => (int)($s['pid'] ?? 0),
                'fails'   => (int)($s['fails'] ?? 0),
                'next'    => (int)($s['next'] ?? 0),
                'since'   => (int)($s['since'] ?? 0),
            ];
        }
        echo json_encode(['ok'=>true, 'watcher'=>watcher_alive($ROOT), 'procs'=>$out, 'now'=>time()]);
        exit;
    }
    if ($pop === 'log') {
        $id = (string)($_REQUEST['id'] ?? '');
        if (!valid_proc_id($id)) { echo json_encode(['error'=>'Proceso no válido.']); exit; }
        $f = $ROOT.'/logs/procs/'.$id.'.log';
        // highlight_error_log devuelve HTML ya escapado (mismo visor que la pestana Logs).
        echo json_encode(['ok'=>true, 'html'=>highlight_error_log(tail_file($f, 300)), 'exists'=>is_file($f)], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    echo json_encode(['error'=>'Operación no válida.']); exit;
}

// ---------------- Endpoints AJAX del gestor de Redis (JSON, no PRG) ----------------
// Misma razon que el explorador de SQL Server: navegar claves, paginar con cursor y editar
// valores es demasiado interactivo para el patron PRG. En RESP no hace falta escapar nada (los
// argumentos van con su longitud por delante), asi que no hay equivalente a la inyeccion SQL:
// la validacion aqui es de coherencia (ids, numeros, tipos), no de escapado.
if (($_REQUEST['ajax'] ?? '') === 'redis') {
    header('Content-Type: application/json; charset=utf-8');
    $rreply = function($o){ echo json_encode($o, JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE); exit; };
    $op = (string)($_REQUEST['op'] ?? '');

    $rsrv = valid_redis_id((string)($_REQUEST['conn'] ?? '')) ? redis_find($ROOT, $_REQUEST['conn']) : null;
    if (!$rsrv) { $rreply(['error'=>'Conexión no válida o ya eliminada.']); }
    $rdb = max(0, min(255, (int)($_REQUEST['db'] ?? 0)));

    try {
        $fp = redis_connect($rsrv, $rdb);
    } catch (Throwable $e) {
        $rreply(['error'=>$e->getMessage()]);
    }

    // Une la respuesta plana de HGETALL / ZRANGE WITHSCORES (campo, valor, campo, valor...) en
    // pares. Redis siempre devuelve esos comandos aplanados, nunca como array de pares.
    $rpairs = function($flat) {
        $out = [];
        $flat = is_array($flat) ? $flat : [];
        for ($i = 0; $i + 1 < count($flat); $i += 2) { $out[] = ['k'=>$flat[$i], 'v'=>$flat[$i+1]]; }
        return $out;
    };

    try {
        switch ($op) {

        case 'test':
            $info = redis_parse_info(redis_must($fp, ['INFO','server']));
            $rreply(['ok'=>true, 'version'=>($info['redis_version'] ?? '?'), 'mode'=>($info['redis_mode'] ?? '?')]);

        // Lista de bases con su numero de claves. El total de bases sale de CONFIG GET databases
        // (16 por defecto); si CONFIG esta deshabilitado en el servidor se asumen 16 y ya.
        case 'dbs':
            $cfgDb = redis_cmd($fp, ['CONFIG','GET','databases']);
            $nDbs = (is_array($cfgDb) && isset($cfgDb[1])) ? (int)$cfgDb[1] : 16;
            if ($nDbs < 1 || $nDbs > 256) $nDbs = 16;
            // INFO keyspace solo lista las bases NO vacias ("db0:keys=9,expires=7,...").
            $ks = redis_parse_info(redis_must($fp, ['INFO','keyspace']));
            $counts = [];
            foreach ($ks as $k => $v) {
                if (!preg_match('/^db(\d+)$/', $k, $m)) continue;
                $keys = 0;
                if (preg_match('/keys=(\d+)/', $v, $mm)) $keys = (int)$mm[1];
                $counts[(int)$m[1]] = $keys;
            }
            $list = [];
            for ($i = 0; $i < $nDbs; $i++) { $list[] = ['db'=>$i, 'keys'=>($counts[$i] ?? 0)]; }
            $rreply(['ok'=>true, 'dbs'=>$list]);

        // Recorrido de claves con SCAN. Se usa SCAN y NUNCA KEYS: KEYS recorre todo el keyspace
        // de golpe y bloquea el servidor, que aqui puede ser uno compartido con las apps del
        // usuario. El cursor lo lleva el cliente (0 = empezar, 0 devuelto = fin).
        case 'scan':
            $cursor = (string)($_REQUEST['cursor'] ?? '0');
            if (!preg_match('/^\d+$/', $cursor)) $cursor = '0';
            $match  = (string)($_REQUEST['match'] ?? '');
            $count  = max(10, min(1000, (int)($_REQUEST['count'] ?? 100)));
            $args = ['SCAN', $cursor, 'COUNT', (string)$count];
            if ($match !== '') { $args[] = 'MATCH'; $args[] = $match; }
            $res = redis_must($fp, $args);
            $next = is_array($res) ? (string)($res[0] ?? '0') : '0';
            $keys = (is_array($res) && isset($res[1]) && is_array($res[1])) ? $res[1] : [];
            // Tipo y TTL de cada clave. Son 2 comandos extra por clave; con COUNT<=1000 y una
            // conexion ya abierta el coste es despreciable y evita tener que abrir la clave para
            // saber que es. Una clave puede expirar entre el SCAN y esto: TYPE devuelve 'none'.
            $out = [];
            foreach ($keys as $k) {
                $t = redis_cmd($fp, ['TYPE', $k]);
                if ($t instanceof RedisErr || $t === 'none') continue;
                $ttl = redis_cmd($fp, ['TTL', $k]);
                $out[] = ['key'=>$k, 'type'=>(string)$t, 'ttl'=>($ttl instanceof RedisErr ? -1 : (int)$ttl)];
            }
            $rreply(['ok'=>true, 'cursor'=>$next, 'done'=>($next === '0'), 'keys'=>$out]);

        // Valor completo de una clave, con la forma que corresponda a su tipo.
        case 'key':
            $key = (string)($_REQUEST['key'] ?? '');
            if ($key === '') { $rreply(['error'=>'Falta la clave.']); }
            $type = redis_must($fp, ['TYPE', $key]);
            if ($type === 'none') { $rreply(['error'=>'La clave ya no existe (¿ha expirado?).']); }
            $ttl = (int)redis_must($fp, ['TTL', $key]);
            $o = ['ok'=>true, 'key'=>$key, 'type'=>$type, 'ttl'=>$ttl];
            switch ($type) {
                case 'string':
                    $v = redis_must($fp, ['GET', $key]);
                    $o['len'] = strlen((string)$v);
                    // Los valores enormes (sesiones serializadas, cachés de vistas) petarían el
                    // navegador: se manda un trozo y se avisa. El editor se bloquea en ese caso
                    // para no guardar el valor truncado encima del original.
                    $o['truncated'] = $o['len'] > 262144;
                    $o['value'] = $o['truncated'] ? substr((string)$v, 0, 262144) : (string)$v;
                    break;
                case 'hash':
                    $o['count'] = (int)redis_must($fp, ['HLEN', $key]);
                    $o['items'] = $rpairs(redis_must($fp, ['HGETALL', $key]));
                    break;
                case 'list':
                    $o['count'] = (int)redis_must($fp, ['LLEN', $key]);
                    $o['items'] = redis_must($fp, ['LRANGE', $key, '0', '999']);
                    break;
                case 'set':
                    $o['count'] = (int)redis_must($fp, ['SCARD', $key]);
                    $o['items'] = redis_must($fp, ['SRANDMEMBER', $key, '1000']);
                    break;
                case 'zset':
                    $o['count'] = (int)redis_must($fp, ['ZCARD', $key]);
                    $o['items'] = $rpairs(redis_must($fp, ['ZRANGE', $key, '0', '999', 'WITHSCORES']));
                    break;
                default:
                    // stream y cualquier tipo futuro: se informa, no se intenta representar.
                    $o['items'] = [];
                    $o['unsupported'] = true;
            }
            $rreply($o);

        // Edicion. Cada tipo tiene su comando; no hay un "set" generico en Redis.
        case 'edit':
            $key  = (string)($_REQUEST['key'] ?? '');
            $type = (string)($_REQUEST['type'] ?? '');
            $val  = (string)($_REQUEST['value'] ?? '');
            $fld  = (string)($_REQUEST['field'] ?? '');
            if ($key === '') { $rreply(['error'=>'Falta la clave.']); }
            switch ($type) {
                case 'string': $r = redis_cmd($fp, ['SET', $key, $val]); break;
                case 'hash':   $r = redis_cmd($fp, ['HSET', $key, $fld, $val]); break;
                // LSET necesita un indice existente: no sirve para anadir, solo para modificar.
                case 'list':   $r = redis_cmd($fp, ['LSET', $key, (string)(int)$fld, $val]); break;
                // Un set no tiene "modificar": se quita el viejo y se anade el nuevo.
                case 'set':    redis_cmd($fp, ['SREM', $key, $fld]); $r = redis_cmd($fp, ['SADD', $key, $val]); break;
                case 'zset':   $r = redis_cmd($fp, ['ZADD', $key, $val, $fld]); break;  // value = score
                default:       $rreply(['error'=>'Ese tipo no se puede editar aquí.']);
            }
            if ($r instanceof RedisErr) { $rreply(['error'=>$r->msg]); }
            $rreply(['ok'=>true]);

        case 'additem':
            $key  = (string)($_REQUEST['key'] ?? '');
            $type = (string)($_REQUEST['type'] ?? '');
            $val  = (string)($_REQUEST['value'] ?? '');
            $fld  = (string)($_REQUEST['field'] ?? '');
            if ($key === '') { $rreply(['error'=>'Falta la clave.']); }
            switch ($type) {
                case 'hash': $r = redis_cmd($fp, ['HSET', $key, $fld, $val]); break;
                case 'list': $r = redis_cmd($fp, ['RPUSH', $key, $val]); break;
                case 'set':  $r = redis_cmd($fp, ['SADD', $key, $val]); break;
                case 'zset': $r = redis_cmd($fp, ['ZADD', $key, ($val !== '' ? $val : '0'), $fld]); break;
                default:     $rreply(['error'=>'Ese tipo no admite añadir elementos aquí.']);
            }
            if ($r instanceof RedisErr) { $rreply(['error'=>$r->msg]); }
            $rreply(['ok'=>true]);

        case 'delitem':
            $key  = (string)($_REQUEST['key'] ?? '');
            $type = (string)($_REQUEST['type'] ?? '');
            $fld  = (string)($_REQUEST['field'] ?? '');
            if ($key === '') { $rreply(['error'=>'Falta la clave.']); }
            switch ($type) {
                case 'hash': $r = redis_cmd($fp, ['HDEL', $key, $fld]); break;
                // En una lista no se puede borrar por indice: se marca con un centinela y se
                // quita. Es el idioma habitual de Redis para esto (LSET + LREM).
                case 'list': redis_cmd($fp, ['LSET', $key, (string)(int)$fld, '__lua_del__']); $r = redis_cmd($fp, ['LREM', $key, '1', '__lua_del__']); break;
                case 'set':  $r = redis_cmd($fp, ['SREM', $key, $fld]); break;
                case 'zset': $r = redis_cmd($fp, ['ZREM', $key, $fld]); break;
                default:     $rreply(['error'=>'Ese tipo no admite borrar elementos aquí.']);
            }
            if ($r instanceof RedisErr) { $rreply(['error'=>$r->msg]); }
            $rreply(['ok'=>true]);

        case 'del':
            $keys = $_REQUEST['keys'] ?? [];
            if (is_string($keys)) $keys = [$keys];
            if (!is_array($keys) || !$keys) { $rreply(['error'=>'No se indicó ninguna clave.']); }
            $n = redis_must($fp, array_merge(['DEL'], array_map('strval', $keys)));
            $rreply(['ok'=>true, 'deleted'=>(int)$n]);

        case 'ttl':
            $key = (string)($_REQUEST['key'] ?? '');
            $sec = (int)($_REQUEST['seconds'] ?? -1);
            if ($key === '') { $rreply(['error'=>'Falta la clave.']); }
            // -1 (o menos) = quitar la expiracion. EXPIRE con 0 o negativo BORRA la clave, que
            // no es lo que quiere quien escribe "sin expiracion": ahi va PERSIST.
            $r = $sec > 0 ? redis_cmd($fp, ['EXPIRE', $key, (string)$sec]) : redis_cmd($fp, ['PERSIST', $key]);
            if ($r instanceof RedisErr) { $rreply(['error'=>$r->msg]); }
            $rreply(['ok'=>true]);

        case 'rename':
            $key = (string)($_REQUEST['key'] ?? '');
            $to  = (string)($_REQUEST['to'] ?? '');
            if ($key === '' || $to === '') { $rreply(['error'=>'Falta el nombre de origen o de destino.']); }
            // RENAMENX en vez de RENAME: RENAME pisa el destino sin avisar si ya existe.
            $r = redis_cmd($fp, ['RENAMENX', $key, $to]);
            if ($r instanceof RedisErr) { $rreply(['error'=>$r->msg]); }
            if ((int)$r === 0) { $rreply(['error'=>'Ya existe una clave llamada "'.$to.'".']); }
            $rreply(['ok'=>true]);

        case 'flushdb':
            $r = redis_cmd($fp, ['FLUSHDB']);
            if ($r instanceof RedisErr) { $rreply(['error'=>$r->msg]); }
            $rreply(['ok'=>true]);

        case 'info':
            $raw = redis_must($fp, ['INFO']);
            $i = redis_parse_info($raw);
            $rreply(['ok'=>true, 'info'=>$i, 'raw'=>(string)$raw]);

        // Consola de comandos. Equivalente a la consola SQL de la pestana SQL Server: se ejecuta
        // lo que el usuario escriba y los errores del servidor se muestran como resultado.
        case 'cmd':
            $line = trim((string)($_REQUEST['line'] ?? ''));
            if ($line === '') { $rreply(['error'=>'Escribe un comando.']); }
            $args = redis_split_cmd($line);
            if (!$args) { $rreply(['error'=>'No se entendió el comando (¿comillas sin cerrar?).']); }
            $verb = strtoupper($args[0]);
            // SHUTDOWN se bloquea: apagaria el servidor (que aqui puede ser un contenedor
            // compartido con las apps) y desde el panel no hay forma de volver a levantarlo.
            if ($verb === 'SHUTDOWN') { $rreply(['error'=>'SHUTDOWN está bloqueado desde el panel: apagaría el servidor y no se puede rearrancar desde aquí.']); }
            // Comandos que dejan la conexion en otro modo y romperian el ciclo peticion/respuesta.
            if (in_array($verb, ['SUBSCRIBE','PSUBSCRIBE','MONITOR','SSUBSCRIBE'], true)) {
                $rreply(['error'=>$verb.' deja la conexión escuchando indefinidamente: no encaja en este gestor.']);
            }
            $r = redis_cmd($fp, $args);
            if ($r instanceof RedisErr) { $rreply(['ok'=>true, 'err'=>$r->msg]); }
            $rreply(['ok'=>true, 'result'=>redis_json_safe($r)]);

        default:
            $rreply(['error'=>'Operación no válida.']);
        }
    } catch (Throwable $e) {
        $rreply(['error'=>$e->getMessage()]);
    } finally {
        if (isset($fp) && is_resource($fp)) { @fclose($fp); }
    }
}

// ---------------- Endpoints AJAX del explorador de SQL Server (JSON, no PRG) ----------------
// El explorador es demasiado interactivo para el patron PRG del resto del panel (cambiar de
// tabla, paginar, ordenar) -> se sirve por AJAX. Todo identificador se valida Y se cita con
// corchetes; todo valor viaja como parametro preparado.
if (($_REQUEST['ajax'] ?? '') === 'sqlsrv') {
    header('Content-Type: application/json; charset=utf-8');
    $sreply = function($o){ echo json_encode($o, JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE); exit; };
    $op = (string)($_REQUEST['op'] ?? '');

    $srv = valid_sqlsrv_id((string)($_REQUEST['conn'] ?? '')) ? sqlsrv_find($ROOT, $_REQUEST['conn']) : null;
    if (!$srv) { $sreply(['error'=>'Conexión no válida o ya eliminada.']); }

    $db     = (string)($_REQUEST['db'] ?? '');
    $schema = (string)($_REQUEST['schema'] ?? '');
    $table  = (string)($_REQUEST['table'] ?? '');
    if ($db !== '' && !valid_sqlsrv_ident($db))         { $sreply(['error'=>'Base de datos no válida.']); }
    if ($schema !== '' && !valid_sqlsrv_ident($schema)) { $sreply(['error'=>'Esquema no válido.']); }
    if ($table !== '' && !valid_sqlsrv_ident($table))   { $sreply(['error'=>'Tabla no válida.']); }

    try {
        $pdo = sqlsrv_pdo($srv, $db !== '' ? $db : null);

        if ($op === 'dbs') {
            $sreply(['dbs' => sqlsrv_databases($pdo)]);
        }

        if ($op === 'tables') {
            $sreply(['tables' => sqlsrv_tables($pdo)]);
        }

        if ($op === 'struct') {
            $cols = sqlsrv_columns($pdo, $schema, $table);
            if (!$cols) { $sreply(['error'=>'La tabla no existe o no es accesible.']); }
            $out = [];
            foreach ($cols as $c) {
                $out[] = [
                    'name'=>$c['name'], 'type'=>sqlsrv_type_label($c), 'nullable'=>$c['nullable'],
                    'identity'=>$c['identity'], 'computed'=>$c['computed'], 'default'=>$c['default'],
                ];
            }
            $sreply(['cols'=>$out, 'pk'=>sqlsrv_pk($pdo, $schema, $table), 'indexes'=>sqlsrv_indexes($pdo, $schema, $table)]);
        }

        if ($op === 'rows') {
            $kind = (string)($_REQUEST['kind'] ?? 'table');
            $cols = sqlsrv_columns($pdo, $schema, $table);
            if (!$cols) { $sreply(['error'=>'La tabla no existe o no es accesible.']); }
            $pk   = sqlsrv_pk($pdo, $schema, $table);
            $names = array_column($cols, 'name');

            $per  = max(10, min(500, (int)($_REQUEST['per'] ?? 50)));
            $page = max(1, (int)($_REQUEST['page'] ?? 1));
            $sort = (string)($_REQUEST['sort'] ?? '');
            $dir  = strtolower((string)($_REQUEST['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            // OFFSET/FETCH exige ORDER BY. Si no se pide orden concreto se usa la clave primaria
            // y, a falta de ella, la primera columna: cualquier cosa menos un orden indefinido,
            // que haria que paginar devolviese filas repetidas o saltadas.
            if ($sort === '' || !in_array($sort, $names, true)) { $sort = $pk ? $pk[0] : $names[0]; }
            $orderBy = sqlsrv_qi($sort).' '.$dir;

            $obj = sqlsrv_qi($schema).'.'.sqlsrv_qi($table);
            // Recuento exacto solo si la estimacion no es enorme: un COUNT(*) sobre decenas de
            // millones de filas bloquearia la peticion. Por encima del umbral se usa la
            // estimacion de sys.partitions y se marca como aproximada.
            // closeCursor() tras cada lectura parcial: fetchColumn() deja el cursor abierto y el
            // siguiente execute() sobre la misma conexion fallaria (ver MARS en sqlsrv_pdo).
            $est = 0;
            if ($kind !== 'view') {
                $stE = $pdo->prepare("SELECT ISNULL(SUM(CASE WHEN index_id IN (0,1) THEN rows END),0) FROM sys.partitions WHERE object_id = OBJECT_ID(?)");
                $stE->execute([$schema.'.'.$table]);
                $est = (int)$stE->fetchColumn();
                $stE->closeCursor();
            }
            $aprox = ($est > 2000000);
            if ($aprox) { $total = $est; }
            else {
                $stC = $pdo->query('SELECT COUNT(*) FROM '.$obj);
                $total = (int)$stC->fetchColumn();
                $stC->closeCursor();
            }

            $off = ($page - 1) * $per;
            $sql = 'SELECT '.sqlsrv_select_list($cols).' FROM '.$obj.' ORDER BY '.$orderBy.
                   ' OFFSET '.(int)$off.' ROWS FETCH NEXT '.(int)$per.' ROWS ONLY';
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
            sqlsrv_decode_rows($rows, $cols);

            $meta = [];
            foreach ($cols as $c) {
                $meta[] = ['name'=>$c['name'], 'type'=>sqlsrv_type_label($c), 'bin'=>sqlsrv_is_binary_type($c['type']),
                           'nullable'=>$c['nullable'], 'identity'=>$c['identity'], 'computed'=>$c['computed']];
            }
            // Sin PK no hay forma segura de identificar UNA fila -> edicion desactivada (no se
            // inventa un WHERE por todas las columnas, que borraria duplicados sin avisar).
            $editable = ($kind !== 'view') && !empty($pk);
            $motivo = $kind === 'view' ? 'Es una vista: solo lectura.'
                    : (empty($pk) ? 'La tabla no tiene clave primaria: no se puede identificar una fila concreta, así que la edición está desactivada.' : '');
            $sreply(['cols'=>$meta, 'rows'=>$rows, 'total'=>$total, 'aprox'=>$aprox, 'page'=>$page, 'per'=>$per,
                     'sort'=>$sort, 'dir'=>strtolower($dir), 'pk'=>$pk, 'editable'=>$editable, 'motivo'=>$motivo]);
        }

        // ---- Consola SQL: ejecuta lo que se escriba, tal cual ----
        if ($op === 'query') {
            $sql = (string)($_POST['sql'] ?? '');
            if (trim($sql) === '') { $sreply(['error'=>'Consulta vacía.']); }
            $t0 = microtime(true);
            // Si la consulta lleva algo fuera de ASCII (p.ej. WHERE nombre = 'Peña'), enviarla
            // tal cual la corromperia por el mismo motivo que los datos. Se manda hexadecimada
            // via sp_executesql. Las consultas ASCII (la mayoria) van directas, sin cambiar su
            // ambito de ejecucion.
            if (sqlsrv_hex_text() && preg_match('/[^\x00-\x7F]/', $sql)) {
                $st = $pdo->prepare('DECLARE @q nvarchar(max) = '.sqlsrv_text_placeholder().'; EXEC sp_executesql @q;');
                $st->execute([sqlsrv_enc_text($sql)]);
            } else {
                $st = $pdo->query($sql);
            }
            $sets = []; $afect = 0;
            // Un batch puede devolver varios conjuntos de resultados (y sentencias sin
            // resultados en medio): se recorren todos con nextRowset.
            do {
                try {
                    if ($st->columnCount() > 0) {
                        $rows = $st->fetchAll(PDO::FETCH_NUM);
                        $cols = [];
                        for ($i = 0; $i < $st->columnCount(); $i++) {
                            $m = @$st->getColumnMeta($i);
                            $cols[] = ($m && !empty($m['name'])) ? $m['name'] : ('col'.($i+1));
                        }
                        $rows = array_slice($rows, 0, 1000);
                        // A diferencia del explorador (que pide el texto hexadecimado columna a
                        // columna porque conoce sus tipos), aqui la consulta es libre y su
                        // resultado llega ya convertido al codepage ANSI por el driver. Se
                        // recupera lo que sea Windows-1252; lo que el driver ya haya perdido no
                        // se puede rescatar, y por eso la UI lo advierte.
                        if (sqlsrv_hex_text()) {
                            foreach ($rows as &$fr) {
                                foreach ($fr as &$cv) {
                                    if (is_string($cv) && !mb_check_encoding($cv, 'UTF-8')) { $cv = mb_convert_encoding($cv, 'UTF-8', 'Windows-1252'); }
                                }
                                unset($cv);
                            }
                            unset($fr);
                        }
                        $sets[] = ['cols'=>$cols, 'rows'=>$rows, 'truncado'=>count($rows) >= 1000];
                    } else {
                        $afect += $st->rowCount();
                    }
                } catch (Throwable $e) { /* conjunto sin resultados utilizables: se ignora */ }
            } while ($st->nextRowset());
            $sreply(['sets'=>$sets, 'afectadas'=>$afect, 'ms'=>(int)round((microtime(true)-$t0)*1000)]);
        }

        // ---- Edicion de filas ----
        // La fila a tocar SIEMPRE se localiza por su clave primaria; los valores de la PK
        // llegan tal y como se leyeron (clave 'pk' del POST). Nunca se genera un WHERE por
        // el resto de columnas.
        if ($op === 'row_save' || $op === 'row_del') {
            $cols = sqlsrv_columns($pdo, $schema, $table);
            if (!$cols) { $sreply(['error'=>'La tabla no existe o no es accesible.']); }
            $pk = sqlsrv_pk($pdo, $schema, $table);
            $byName = []; foreach ($cols as $c) { $byName[$c['name']] = $c; }
            $obj = sqlsrv_qi($schema).'.'.sqlsrv_qi($table);
            $modo = (string)($_POST['modo'] ?? 'update'); // update | insert

            if ($modo !== 'insert' && empty($pk)) {
                $sreply(['error'=>'Esta tabla no tiene clave primaria: no se puede editar ni borrar una fila concreta desde aquí.']);
            }

            // WHERE de la clave primaria, con sus valores como parametros.
            $where = ''; $wargs = [];
            if ($modo !== 'insert') {
                $pkVals = json_decode((string)($_POST['pk'] ?? '{}'), true);
                if (!is_array($pkVals)) { $sreply(['error'=>'Clave primaria no recibida.']); }
                $partes = [];
                foreach ($pk as $k) {
                    if (!array_key_exists($k, $pkVals)) { $sreply(['error'=>'Falta el valor de la clave "'.$k.'".']); }
                    $v = $pkVals[$k];
                    $tipo = $byName[$k]['type'] ?? '';
                    if ($v === null) { $partes[] = sqlsrv_qi($k).' IS NULL'; }
                    // Un varbinary en la PK llega en hex: hay que reconvertirlo para comparar.
                    elseif (sqlsrv_is_binary_type($tipo)) { $partes[] = sqlsrv_qi($k).' = CONVERT(varbinary(max), CAST(? AS varchar(max)), 2)'; $wargs[] = $v; }
                    // Una PK de texto tambien va hexadecimada: si se enviara tal cual, un valor
                    // con acentos no encontraria su fila (o peor, encontraria otra).
                    elseif (sqlsrv_hex_text() && sqlsrv_is_text_type($tipo)) { $partes[] = sqlsrv_qi($k).' = '.sqlsrv_text_placeholder(); $wargs[] = sqlsrv_enc_text($v); }
                    else { $partes[] = sqlsrv_qi($k).' = ?'; $wargs[] = $v; }
                }
                $where = ' WHERE '.implode(' AND ', $partes);
            }

            if ($op === 'row_del') {
                $st = $pdo->prepare('DELETE FROM '.$obj.$where);
                $st->execute($wargs);
                $n = $st->rowCount();
                if ($n === 0) { $sreply(['error'=>'No se borró ninguna fila: puede que ya no exista (¿la cambió otra sesión?).']); }
                if ($n > 1)   { $sreply(['ok'=>true, 'aviso'=>'Se borraron '.$n.' filas (la clave primaria no era única).']); }
                $sreply(['ok'=>true, 'n'=>$n]);
            }

            // row_save: 'vals' trae solo las columnas editadas; 'nulls' cuales van a NULL
            // (imprescindible para poder distinguir NULL de cadena vacia).
            $vals  = json_decode((string)($_POST['vals'] ?? '{}'), true);
            $nulls = json_decode((string)($_POST['nulls'] ?? '[]'), true);
            if (!is_array($vals))  { $vals = []; }
            if (!is_array($nulls)) { $nulls = []; }

            $sets = []; $args = []; $insCols = []; $insPh = [];
            foreach ($cols as $c) {
                $n = $c['name'];
                // IDENTITY y calculadas las pone el servidor: nunca se escriben.
                if ($c['identity'] || $c['computed']) continue;
                $esNull = in_array($n, $nulls, true);
                if (!$esNull && !array_key_exists($n, $vals)) continue;
                $ph = '?';
                $v  = $esNull ? null : (string)$vals[$n];
                if (!$esNull && sqlsrv_is_binary_type($c['type'])) {
                    // El explorador muestra los binarios en hex; se reconvierten al guardar.
                    $v = preg_replace('/^0x/i', '', trim($v));
                    if ($v !== '' && !preg_match('/^[0-9a-fA-F]*$/', $v)) { $sreply(['error'=>'La columna "'.$n.'" es binaria: usa hexadecimal (p. ej. 0xDEADBEEF).']); }
                    $ph = 'CONVERT(varbinary(max), CAST(? AS varchar(max)), 2)';
                } elseif (!$esNull && sqlsrv_hex_text() && sqlsrv_is_text_type($c['type'])) {
                    // Texto hexadecimado: sin esto, guardar "Peña" dejaria "PeÃ±a" en la tabla.
                    $ph = sqlsrv_text_placeholder();
                    $v  = sqlsrv_enc_text($v);
                }
                if ($esNull && !$c['nullable']) { $sreply(['error'=>'La columna "'.$n.'" no admite NULL.']); }
                $sets[] = sqlsrv_qi($n).' = '.$ph;
                $insCols[] = sqlsrv_qi($n); $insPh[] = $ph;
                $args[] = $v;
            }
            if (!$sets) { $sreply(['error'=>'No hay ningún campo que guardar.']); }

            if ($modo === 'insert') {
                // OUTPUT INSERTED: pdo_odbc no soporta lastInsertId() (lanza excepcion) y
                // SCOPE_IDENTITY() en una consulta aparte vuelve vacia (otro ambito).
                $out = $pk ? ' OUTPUT '.implode(', ', array_map(function($k){ return 'INSERTED.'.sqlsrv_qi($k); }, $pk)) : '';
                $sqlIns = 'INSERT INTO '.$obj.' ('.implode(', ', $insCols).')'.$out.' VALUES ('.implode(', ', $insPh).')';
                $st = $pdo->prepare($sqlIns);
                $st->execute($args);
                $nuevo = $out ? $st->fetch(PDO::FETCH_ASSOC) : null;
                $sreply(['ok'=>true, 'nuevo'=>$nuevo ?: null]);
            }

            $st = $pdo->prepare('UPDATE '.$obj.' SET '.implode(', ', $sets).$where);
            $st->execute(array_merge($args, $wargs));
            $n = $st->rowCount();
            if ($n > 1) { $sreply(['ok'=>true, 'aviso'=>'Se actualizaron '.$n.' filas (la clave primaria no era única).']); }
            $sreply(['ok'=>true, 'n'=>$n]);
        }

        $sreply(['error'=>'Operación no reconocida.']);
    } catch (Throwable $e) {
        $sreply(['error'=>$e->getMessage()]);
    }
}

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

// ---------------- Comandos personalizados del runner (globales, no por sesion) ----------------
if ($__ta==='run_preset_add' || $__ta==='run_preset_del') {
    header('Content-Type: application/json; charset=utf-8');
    $reply = function($o){ echo json_encode($o); exit; };
    if (!term_enabled($ROOT)) { $reply(['error'=>'La terminal está desactivada. Actívala en Configuración del servidor.']); }

    $cmd  = trim((string)($_POST['cmd'] ?? ''));
    $list = run_presets_load($ROOT);

    if ($__ta==='run_preset_add') {
        if ($cmd==='') { $reply(['error'=>'Comando vacío.']); }
        if (mb_strlen($cmd) > 200) { $reply(['error'=>'Comando demasiado largo (máx. 200).']); }
        if (!in_array($cmd, $list, true)) {
            if (count($list) >= 30) { $reply(['error'=>'Máximo 30 comandos guardados: elimina alguno antes de añadir otro.']); }
            $list[] = $cmd;
            write_json(run_presets_file($ROOT), $list);
        }
    } else {
        $list = array_values(array_diff($list, [$cmd]));
        write_json(run_presets_file($ROOT), $list);
    }
    $reply(['ok'=>true, 'presets'=>$list]);
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
            $msg='applied:'.count($gone).' proyecto(s) quitado(s) de la lista (su carpeta ya no existe en disco).';
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
    elseif ($action === 'git_connect') {
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
    elseif ($action === 'cover') {
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
            lua_hosts(); // cambia el dominio de TODOS los proyectos: hay que resincronizar hosts si o si
            $httpsOn = is_file($ROOT.'/config/https.on');
            if ($httpsOn) { @file_put_contents($ROOT.'/tmp/https.flag',(string)time()); }
            $msg = 'applied:Dominio cambiado a "'.$new.'". Sincronizando hosts (acepta el aviso de Windows/UAC que va a aparecer).'.($httpsOn?' El certificado HTTPS se está regenerando.':'');
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
    elseif ($action === 'postgres') {
        $tab = ($_POST['from_tab'] ?? '') === 'proyectos' ? 'proyectos' : 'config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) {
            @file_put_contents($ROOT.'/config/postgres.on','1');
            if (is_file($ROOT.'/bin/postgres/bin/pg_ctl.exe')) {
                $msg='info:PostgreSQL activándose. Conecta en 127.0.0.1:5432, usuario postgres, sin contraseña.';
            } else {
                $id='postgres-'.time();
                $job=['id'=>$id,'name'=>'postgres','php'=>($cfg['defaultPhp']??'8.4'),'type'=>'postgres','url'=>''];
                @mkdir($ROOT.'/tmp/jobs',0777,true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Descargando e instalando PostgreSQL 16 (~350 MB)… puede tardar unos minutos.';
            }
        } else { @unlink($ROOT.'/config/postgres.on'); $msg='info:PostgreSQL desactivándose.'; }
    }
    elseif ($action === 'mongodb') {
        $tab = ($_POST['from_tab'] ?? '') === 'proyectos' ? 'proyectos' : 'config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) {
            @file_put_contents($ROOT.'/config/mongodb.on','1');
            // Comprueba tambien mongo-express, no solo mongod.exe: son dos pasos independientes
            // del mismo job (ver case 'mongodb' de Run-Job) y uno puede faltar sin el otro
            // (p.ej. tras un fallo del build de mongo-express con MongoDB ya instalado). Si solo
            // se mirara mongod.exe, "Activar" se quedaria callado sin arreglar nada en ese caso.
            if (is_file($ROOT.'/bin/mongodb/bin/mongod.exe') && is_file($ROOT.'/bin/mongo-express/app.js')) {
                $msg='info:MongoDB activándose. Conecta en 127.0.0.1:27017, sin autenticación.';
            } else {
                $id='mongodb-'.time();
                $job=['id'=>$id,'name'=>'mongodb','php'=>($cfg['defaultPhp']??'8.4'),'type'=>'mongodb','url'=>''];
                @mkdir($ROOT.'/tmp/jobs',0777,true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Descargando e instalando MongoDB + Node.js + mongo-express (~400-500 MB en total)… puede tardar varios minutos.';
            }
        } else { @unlink($ROOT.'/config/mongodb.on'); $msg='info:MongoDB desactivándose.'; }
    }
    elseif ($action === 'redis') {
        $tab = ($_POST['from_tab'] ?? '') === 'proyectos' ? 'proyectos' : 'config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) {
            @file_put_contents($ROOT.'/config/redis.on','1');
            // Igual que MongoDB: el job tiene dos partes independientes (servidor + extension de
            // PHP), asi que no basta con mirar el .exe. Si el servidor ya esta pero falta la
            // extension en alguna version, hay que volver a lanzar el job para completarla.
            $rExe  = is_file($ROOT.'/bin/redis/redis-server.exe');
            $rExtOk = false;
            if ($rExe) {
                // Basta con que la version por defecto la tenga: es la que usan el panel y los
                // proyectos nuevos. El job, si se lanza, completa igualmente todas las demas.
                $rDef = $cfg['defaultPhp'] ?? '8.4';
                $rExtOk = is_file($ROOT.'/bin/php/'.$rDef.'/ext/php_redis.dll');
            }
            if ($rExe && $rExtOk) {
                $msg='info:Redis activándose. Conecta en 127.0.0.1:6379, sin contraseña.';
            } else {
                // 'build' solo se usa si el servidor no esta instalado todavia; una vez instalado
                // se respeta el que haya (config\redis\build.txt) y el job solo completa la extension.
                $rBuild = ($_POST['build'] ?? '') === 'native5' ? 'native5' : 'redis8';
                $id='redis-'.time();
                $job=['id'=>$id,'name'=>'redis','php'=>($cfg['defaultPhp']??'8.4'),'type'=>'redis','url'=>'','build'=>$rBuild];
                @mkdir($ROOT.'/tmp/jobs',0777,true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Descargando Redis ('.($rBuild==='native5'?'5.0.14.1 nativo':'8.8.1').') y la extensión php_redis para cada versión de PHP… puede tardar unos minutos.';
            }
        } else { @unlink($ROOT.'/config/redis.on'); $msg='info:Redis desactivándose.'; }
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
    // Import de un .sql subido (boton "Importar" de una BD). Se hace como job en segundo plano
    // (igual que db_import_dir, ver mas abajo) en vez de bloquear este worker de Apache con
    // proc_open+stream_get_contents: un .sql grande podia superar max_execution_time, y de
    // paso permite reportar progreso real (% de bytes) en vez de solo "correcto"/"fallo" al final.
    elseif ($action === 'db_import') {
        $tab = 'bd';
        $db = $_POST['dbname'] ?? '';
        if (!valid_dbname($db)) { $msg='error:Nombre de base de datos no válido.'; }
        elseif (empty($_FILES['sqlfile']) || ($_FILES['sqlfile']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $msg='error:No se recibió el archivo .sql.'; }
        else {
            $id = 'dbimportfile-'.time();
            @mkdir($ROOT.'/tmp/imports', 0777, true);
            $dest = $ROOT.'/tmp/imports/'.$id.'.sql';
            if (!move_uploaded_file($_FILES['sqlfile']['tmp_name'], $dest)) { $msg='error:No se pudo guardar el archivo subido.'; }
            else {
                $job = ['id'=>$id,'type'=>'db_import_file','name'=>$db,'dbname'=>$db,'file'=>str_replace('\\','/',$dest)];
                @mkdir($ROOT.'/tmp/jobs', 0777, true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Importando archivo en "'.$db.'"… mira el progreso abajo.';
            }
        }
    }
    // Importa una carpeta con un .sql por tabla (p.ej. mysqldump --tab o un export similar,
    // sin un unico dump completo) en una BD ya existente. Se hace como job en segundo plano
    // (lo ejecuta el watcher, no este propio worker de Apache): puede tratarse de decenas de
    // archivos y cientos de MB en total, muy por encima de max_execution_time/post_max_size.
    elseif ($action === 'db_import_dir') {
        $tab = 'bd';
        $db  = trim($_POST['dbname'] ?? '');
        $dir = rtrim(str_replace('\\','/', trim($_POST['dir'] ?? '')), '/');
        if (!valid_dbname($db)) { $msg='error:Nombre de base de datos no válido.'; }
        elseif (!in_array($db, mysql_databases() ?: [], true)) { $msg='error:Esa base de datos no existe todavía -- créala primero arriba.'; }
        elseif ($dir === '' || !is_dir($dir)) { $msg='error:Esa carpeta no existe en este servidor.'; }
        else {
            $sqlFiles = glob($dir.'/*.sql');
            if (!$sqlFiles) { $msg='error:No hay archivos .sql en esa carpeta.'; }
            else {
                $id = 'dbimport-'.time();
                $job = ['id'=>$id,'type'=>'db_import_dir','name'=>$db,'dbname'=>$db,'dir'=>$dir];
                @mkdir($ROOT.'/tmp/jobs', 0777, true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Importando '.count($sqlFiles).' archivos .sql en "'.$db.'"… mira el progreso abajo.';
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
            pma_sync_servers($ROOT); // que phpMyAdmin siga entrando tras el cambio
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
                // Se guarda para poder ofrecer esta cuenta en el desplegable de conexiones de
                // phpMyAdmin (pma_sync_servers) sin volver a teclear la contraseña.
                mysql_user_save_password($ROOT, $u, $h, $p);
                pma_sync_servers($ROOT);
                $msg='applied:Usuario "'.$u.'@'.$h.'" creado, con acceso a '.($scope==='db'?('"'.$db.'"'):'todas las bases de datos').'.';
                // GRANT sobre una BD que aun no existe no da error en MariaDB (el permiso queda
                // guardado y se aplica solo en cuanto la BD se crea) -- se avisa para que no
                // parezca que la asociacion "no ha hecho nada" si el nombre estaba mal escrito.
                if ($scope === 'db' && !in_array($db, mysql_databases() ?: [], true)) {
                    $msg .= ' Aviso: la base de datos "'.$db.'" todavía no existe -- el acceso se activará en cuanto la crees (revisa que el nombre esté bien escrito).';
                }
            } catch (Throwable $e) { $msg='error:No se pudo crear el usuario: '.$e->getMessage(); }
        }
    }
    elseif ($action === 'mysql_user_delete') {
        $tab='bd';
        $u = trim($_POST['username'] ?? '');
        $h = $_POST['host'] ?? '';
        if (!valid_mysql_user($u) || !valid_mysql_host($h)) { $msg='error:Usuario u host no válido.'; }
        else {
            try {
                mysql_pdo()->exec("DROP USER '".$u."'@'".$h."'");
                mysql_user_forget_password($ROOT, $u, $h);
                pma_sync_servers($ROOT);
                $msg='applied:Usuario "'.$u.'@'.$h.'" eliminado.';
            }
            catch (Throwable $e) { $msg='error:No se pudo eliminar: '.$e->getMessage(); }
        }
    }
    // ---- PostgreSQL: gestion de bases de datos y roles (pestana Bases de datos) ----
    elseif ($action === 'pg_db_create') {
        $tab='bd'; $tab_engine='pg';
        $db = trim($_POST['dbname'] ?? '');
        if (!valid_pg_ident($db)) { $msg='error:Nombre de base de datos no válido (empieza por letra, luego letras/números/_).'; }
        else {
            try { pgsrv_pdo()->exec('CREATE DATABASE "'.$db.'" ENCODING \'UTF8\'');
                  $msg='info:Base de datos "'.$db.'" creada.'; }
            catch (Throwable $e) { $msg='error:No se pudo crear: '.$e->getMessage(); }
        }
    }
    elseif ($action === 'pg_db_drop') {
        $tab='bd'; $tab_engine='pg';
        $db = $_POST['dbname'] ?? '';
        if (!valid_pg_ident($db)) { $msg='error:Nombre de base de datos no válido.'; }
        else {
            try { pgsrv_pdo()->exec('DROP DATABASE "'.$db.'" WITH (FORCE)');
                  $msg='info:Base de datos "'.$db.'" eliminada.'; }
            catch (Throwable $e) { $msg='error:No se pudo eliminar: '.$e->getMessage(); }
        }
    }
    elseif ($action === 'pg_db_import') {
        $tab='bd'; $tab_engine='pg';
        $db = $_POST['dbname'] ?? '';
        $psqlExe = $ROOT.'/bin/postgres/bin/psql.exe';
        if (!valid_pg_ident($db)) { $msg='error:Nombre de base de datos no válido.'; }
        elseif (empty($_FILES['sqlfile']) || ($_FILES['sqlfile']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $msg='error:No se recibió el archivo .sql.'; }
        elseif (!is_file($psqlExe)) { $msg='error:PostgreSQL no está instalado.'; }
        else {
            $pass = pgsrv_pass($ROOT);
            $env = $pass !== '' ? ['PGPASSWORD'=>$pass] : null;
            $cmd = '"'.$psqlExe.'" --host=127.0.0.1 --port=5432 --username=postgres --no-password --dbname='.escapeshellarg($db);
            $descriptors = [0=>['file',$_FILES['sqlfile']['tmp_name'],'r'], 1=>['pipe','w'], 2=>['pipe','w']];
            $proc = @proc_open($cmd, $descriptors, $pipes, null, $env);
            if (!is_resource($proc)) { $msg='error:No se pudo ejecutar psql.exe.'; }
            else {
                $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
                $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
                $code = proc_close($proc);
                if ($code === 0) { $msg='info:Importado en "'.$db.'" correctamente.'; }
                else { $msg='error:Fallo al importar: '.trim($err ?: $out ?: 'código '.$code); }
            }
        }
    }
    elseif ($action === 'pg_role_create') {
        $tab='bd'; $tab_engine='pg';
        $u = trim($_POST['username'] ?? '');
        $p = (string)($_POST['password'] ?? '');
        $scope = ($_POST['scope'] ?? 'db') === 'all' ? 'all' : 'db';
        $db = trim($_POST['dbname'] ?? '');
        if (!valid_pg_ident($u)) { $msg='error:Rol no válido (empieza por letra, luego letras/números/_).'; }
        elseif ($p === '') { $msg='error:La contraseña no puede estar vacía.'; }
        elseif ($scope === 'db' && !valid_pg_ident($db)) { $msg='error:Nombre de base de datos no válido.'; }
        else {
            try {
                $pdo = pgsrv_pdo();
                $pdo->exec('CREATE ROLE "'.$u.'" LOGIN PASSWORD '.$pdo->quote($p));
                if ($scope === 'db') {
                    // Dueño de esa BD: control total sobre ella (crear esquemas, tablas, etc.).
                    $pdo->exec('GRANT ALL PRIVILEGES ON DATABASE "'.$db.'" TO "'.$u.'"');
                    $pdo->exec('ALTER DATABASE "'.$db.'" OWNER TO "'.$u.'"');
                    $msg='applied:Rol "'.$u.'" creado y asignado como dueño de "'.$db.'".';
                } else {
                    // Usuario general: puede crear (y por tanto poseer) sus propias BD.
                    $pdo->exec('ALTER ROLE "'.$u.'" CREATEDB');
                    $msg='applied:Rol "'.$u.'" creado (puede crear sus propias bases de datos).';
                }
            } catch (Throwable $e) { $msg='error:No se pudo crear el rol: '.$e->getMessage(); }
        }
    }
    elseif ($action === 'pg_role_delete') {
        $tab='bd'; $tab_engine='pg';
        $u = trim($_POST['username'] ?? '');
        if (!valid_pg_ident($u)) { $msg='error:Rol no válido.'; }
        elseif (strcasecmp($u,'postgres')===0) { $msg='error:No se puede eliminar el superusuario postgres.'; }
        else {
            try { pgsrv_pdo()->exec('DROP ROLE "'.$u.'"'); $msg='applied:Rol "'.$u.'" eliminado.'; }
            catch (Throwable $e) { $msg='error:No se pudo eliminar (¿es dueño de objetos?): '.$e->getMessage(); }
        }
    }
    // ---- SQL Server: alta/baja de conexiones guardadas (pestana SQL Server) ----
    elseif ($action === 'sqlsrv_save') {
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
    // ---- Supervisor de procesos ----
    // Mismas puertas de seguridad que la Terminal (config\terminal.on): definir un proceso es
    // ejecutar un comando arbitrario, y con "Exponer en la red local" el panel puede estar
    // accesible desde otras maquinas. Ver/consultar no se bloquea; crear/tocar si.
    elseif (in_array($action, ['proc_save','proc_del','proc_toggle','proc_restart'], true) && !term_enabled($ROOT)) {
        $tab='procs';
        $msg='error:El supervisor ejecuta comandos: activa la Terminal en Configuración del servidor para poder gestionar procesos.';
    }
    elseif ($action === 'proc_save') {
        $tab='procs';
        $id      = trim($_POST['id'] ?? '');
        $project = trim($_POST['project'] ?? '');
        $label   = trim($_POST['label'] ?? '');
        $cmd     = trim($_POST['cmd'] ?? '');
        $php     = trim($_POST['php'] ?? '');
        $editing = $id !== '' && valid_proc_id($id);
        $sitesAll = $cfg['sites'] ?? [];
        if (!isset($sitesAll[$project]))          { $msg='error:Ese proyecto no está registrado.'; }
        elseif ($cmd === '')                      { $msg='error:Escribe el comando a mantener corriendo.'; }
        elseif (mb_strlen($cmd) > 300)            { $msg='error:Comando demasiado largo (máx. 300).'; }
        elseif (mb_strlen($label) > 40)           { $msg='error:Nombre demasiado largo (máx. 40).'; }
        elseif ($php !== '' && !preg_match('/^\d\.\d$/', $php)) { $msg='error:Versión de PHP no válida.'; }
        else {
            $list = procs_load($ROOT);
            $entry = null;
            if ($editing) { foreach ($list as $i => $p) { if (($p['id'] ?? '') === $id) { $entry = $i; break; } } }
            $def = [
                'id'      => $editing && $entry !== null ? $id : bin2hex(random_bytes(6)),
                'project' => $project,
                'label'   => $label !== '' ? $label : $cmd,
                'cmd'     => $cmd,
                'php'     => $php,
                // Nuevo proceso SIEMPRE parado: que arrancarlo sea un acto consciente, no un
                // efecto colateral de guardar el formulario.
                'enabled' => $editing && $entry !== null ? (bool)($list[$entry]['enabled'] ?? false) : false,
            ];
            if ($entry !== null) { $list[$entry] = $def; } else { $list[] = $def; }
            procs_save($ROOT, $list);
            // Editar un proceso corriendo debe aplicar el comando nuevo: se pide reinicio.
            if ($editing && $entry !== null && $def['enabled']) { @touch($ROOT.'/tmp/procs/'.$def['id'].'.restart'); }
            $msg='applied:Proceso "'.$def['label'].'" guardado.'.($editing?'':' Arráncalo cuando quieras con su botón.');
        }
    }
    elseif ($action === 'proc_del') {
        $tab='procs';
        $id = trim($_POST['id'] ?? '');
        if (!valid_proc_id($id)) { $msg='error:Proceso no válido.'; }
        else {
            // Basta quitarlo del json: el watcher mata como "huerfano" cualquier proceso vivo
            // cuyo id ya no exista en procs.json (ver el bloque del supervisor en Cmd-Watch).
            procs_save($ROOT, array_values(array_filter(procs_load($ROOT), function($p) use ($id){ return ($p['id'] ?? '') !== $id; })));
            $msg='applied:Proceso eliminado. Si estaba corriendo, el watcher lo detendrá en un momento.';
        }
    }
    elseif ($action === 'proc_toggle') {
        $tab='procs';
        $id = trim($_POST['id'] ?? '');
        $on = ($_POST['enable'] ?? '') === '1';
        $list = procs_load($ROOT); $found = false;
        foreach ($list as $i => $p) { if (($p['id'] ?? '') === $id) { $list[$i]['enabled'] = $on; $found = true; break; } }
        if (!$found) { $msg='error:Ese proceso ya no existe.'; }
        else {
            procs_save($ROOT, $list);
            if (!watcher_alive($ROOT)) { $msg='error:Guardado, pero el watcher no está activo: nadie va a '.($on?'arrancarlo':'pararlo').'. Arráncalo con .\lua.ps1 start'; }
            else { $msg='applied:'.($on ? 'Arrancando el proceso…' : 'Deteniendo el proceso…'); }
        }
    }
    elseif ($action === 'proc_restart') {
        $tab='procs';
        $id = trim($_POST['id'] ?? '');
        if (!valid_proc_id($id)) { $msg='error:Proceso no válido.'; }
        elseif (!watcher_alive($ROOT)) { $msg='error:El watcher no está activo: no se puede reiniciar. Arráncalo con .\lua.ps1 start'; }
        else {
            @mkdir($ROOT.'/tmp/procs', 0777, true);
            @touch($ROOT.'/tmp/procs/'.$id.'.restart');
            $msg='applied:Reiniciando el proceso…';
        }
    }
    // ---- Actualizaciones de la plataforma ----
    // El panel no puede hacer 'git fetch' (remoto SSH, y aqui corremos como SYSTEM): deja un
    // archivo-senal y el watcher lo recoge, igual que con HTTPS o la sincronizacion de hosts.
    elseif ($action === 'update_cfg') {
        $tab='config';
        $auto  = ($_POST['auto'] ?? '') === '1';
        $horas = max(1, min(168, (int)($_POST['cada_horas'] ?? 6)));
        @mkdir($ROOT.'/config', 0777, true);
        @file_put_contents($ROOT.'/config/update.json', json_encode(['auto'=>$auto, 'cada_horas'=>$horas]));
        $msg = 'applied:Actualizaciones automáticas '.($auto?'activadas':'desactivadas').'. Comprobación cada '.$horas.' h.';
    }
    elseif ($action === 'update_check') {
        $tab='config';
        @mkdir($ROOT.'/tmp', 0777, true);
        @file_put_contents($ROOT.'/tmp/update-check.flag', '1');
        $msg = watcher_alive($ROOT)
            ? 'info:Buscando actualizaciones… se actualizará en unos segundos.'
            : 'error:El watcher no está activo: no se puede consultar el repositorio. Arráncalo con .\lua.ps1 start';
    }
    elseif ($action === 'update_now') {
        $tab='config';
        @mkdir($ROOT.'/tmp', 0777, true);
        @file_put_contents($ROOT.'/tmp/update-now.flag', '1');
        $msg = watcher_alive($ROOT)
            ? 'info:Actualizando… Apache se reiniciará solo al terminar.'
            : 'error:El watcher no está activo: no se puede actualizar. Arráncalo con .\lua.ps1 start';
    }
    elseif ($action === 'terminal') {
        $tab='config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) { @file_put_contents($ROOT.'/config/terminal.on','1'); $msg='applied:Terminal activada. Ejecuta comandos desde la pestaña Terminal.'; }
        else { @unlink($ROOT.'/config/terminal.on'); $msg='applied:Terminal desactivada.'; }
    }
    elseif ($action === 'docker_start_desktop') {
        $tab='docker';
        $exe = docker_desktop_exe();
        if ($exe === null) { $msg='error:No se encontró Docker Desktop instalado.'; }
        else {
            try { $sh = new COM('WScript.Shell'); $sh->Run('"'.$exe.'"', 1, false); $msg='info:Arrancando Docker Desktop… puede tardar un minuto en estar listo.'; }
            catch (Throwable $e) { $msg='error:No se pudo lanzar Docker Desktop: '.$e->getMessage(); }
        }
    }
    elseif ($action === 'docker_container') {
        $tab='docker';
        $op = $_POST['op'] ?? '';
        $id = trim($_POST['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{6,64}$/i', $id)) { $msg='error:Contenedor no válido.'; }
        elseif (!in_array($op, ['start','stop','restart','rm'], true)) { $msg='error:Acción no válida.'; }
        else {
            $r = docker_exec($op==='rm' ? ['rm','-f',$id] : [$op,$id]);
            if ($r===null) { $msg='error:Docker no está disponible.'; }
            elseif (!$r['ok']) { $msg='error:'.trim($r['err'] !== '' ? $r['err'] : $r['out']); }
            else { $msg='applied:Hecho.'; }
        }
    }

    // Si "Crear proyecto"/"Registrar proyecto externo" fallo (mensaje de error), reabrir el
    // modal al recargar -- si no, el usuario pierde de vista el formulario que acaba de
    // rellenar y tiene que volver a abrirlo el mismo desde cero.
    $reopenModal = in_array($action, ['create','add_external'], true) && strpos((string)$msg, 'error:') === 0;

    // Guardado con el icono de disquete de las cards de ajustes: mismo handler de siempre,
    // solo cambia la respuesta. Se contesta JSON en vez de redirigir para que la página no
    // recargue y el icono pueda ponerse verde en sitio. 'reload' marca las acciones cuyo
    // efecto no se puede reflejar sin repintar la página entera (set_tld cambia el dominio
    // de todos los proyectos y dispara la sincronización de hosts con UAC).
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        [$aType,$aText] = array_pad(explode(':',(string)$msg,2),2,'');
        echo json_encode([
            'ok'     => $aType !== 'error',
            'type'   => $aType,
            'msg'    => $aText,
            'reload' => in_array($action, ['set_tld'], true),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: ?tab='.$tab.(isset($ver)?'&ver='.urlencode($ver):'').($redirName?'&name='.urlencode($redirName):'').(isset($tab_engine)?'&engine='.urlencode($tab_engine):'').($reopenModal?'&reopen=newproject':'').'&msg='.urlencode($msg));
    exit;
}

// ---------------- GET (render) ----------------
$cfg = read_json($CFG_FILE) ?: ['defaultPhp'=>'8.4','tld'=>'lua.test','sites'=>[]];
$brandName = brand_name($cfg);
$luaVer    = lua_version($ROOT);
$updSt     = update_status($ROOT);
$updCfg    = update_config($ROOT);
$updDetras = (int)($updSt['detras'] ?? 0);
$updHay    = $updDetras > 0;
$brandLogo = brand_logo_path($ROOT);      // ruta del logo propio, o null si usa el de por defecto
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
$reopenNewProject = ($_GET['reopen'] ?? '') === 'newproject';
$curPhp = PHP_VERSION;
$jobs = read_jobs($ROOT.'/tmp/jobs');
$anyJobRun = false; foreach($jobs as $jj){ if(in_array(($jj['state']??''),['running','queued'],true)){$anyJobRun=true;break;} }
$anyDbImportRun = false; foreach($jobs as $jj){ if(in_array(($jj['type']??''),['db_import_dir','db_import_file'],true) && in_array(($jj['state']??''),['running','queued'],true)){$anyDbImportRun=true;break;} }
$watcherAlive = watcher_alive($ROOT);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($brandLogo): ?><link rel="icon" href="?brandlogo&t=<?= filemtime($brandLogo) ?>"><?php else: ?><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><?php endif; ?>
<title><?= e($brandName) ?></title>
<?php if ($mtype==='applied'): ?><script>
// Recarga resiliente: en vez de un temporizador fijo (que caia sobre Apache mientras se
// reiniciaba -> connection refused), sondeamos ?ping=1 hasta que responda y solo entonces
// navegamos. Tope ~31s por si algo se atasca (recarga igualmente).
(function(){var t='?tab=<?= e($tab) ?>',n=0;
function go(){location.href=t;}
function ping(){fetch('?ping=1',{cache:'no-store'}).then(function(r){if(r.ok){go();}else{retry();}}).catch(retry);}
function retry(){if(++n>60){go();return;}setTimeout(ping,500);}
setTimeout(ping,1500);})();
</script><?php endif; ?>
<?php if ($mtype==='info'): ?><script>setTimeout(function(){location.href='?tab=<?= e($tab) ?>';},7000);</script><?php endif; ?>
<?php if (($tab==='proyectos' || $tab==='config' || $tab==='proyecto') && ($anyJobRun || $mtype==='job')): ?><meta http-equiv="refresh" content="3"><?php endif; ?>
<?php if ($tab==='bd' && ($anyDbImportRun || $mtype==='job')): ?><meta http-equiv="refresh" content="3"><?php endif; ?>
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
  /* Version de la plataforma, junto al titulo. Se tine de ambar cuando hay actualizaciones. */
  .verchip{display:inline-block;vertical-align:middle;margin-left:9px;padding:2px 8px;border-radius:999px;
           border:1px solid var(--line);background:var(--in);color:var(--mut);
           font-size:11px;font-weight:600;font-family:ui-monospace,Consolas,monospace;
           text-decoration:none;letter-spacing:.2px;transition:color .12s,border-color .12s}
  .verchip:hover{color:var(--ac);border-color:var(--ac)}
  .verchip.hay{color:var(--warn);border-color:var(--warn);background:rgba(210,153,34,.12)}
  .verchip.hay:hover{filter:brightness(1.1)}
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

  .btn{background:var(--ac);background-image:linear-gradient(135deg,var(--brand-start),var(--brand-end));color:#fff;border:1px solid transparent;border-radius:5px;padding:6px 13px;font-size:13px;font-family:inherit;line-height:1.4;font-weight:600;cursor:pointer;transition:filter .12s,background .12s,color .12s,border-color .12s}
  .btn:hover{filter:brightness(1.08)}
  .btn.sm{padding:4px 10px;font-size:13px}
  .btn.ghost{background-image:linear-gradient(135deg,var(--brand-start),var(--brand-end));border-color:transparent;color:#fff}
  .btn.ghost:hover{filter:brightness(1.08)}
  .btn.danger{background-image:linear-gradient(135deg,var(--err),var(--err-dark));border-color:transparent;color:#fff}
  .btn.danger:hover{filter:brightness(1.08)}
  .btn-git{display:inline-flex;align-items:center;gap:8px;background:#161b22;color:#fff;border:1px solid #30363d;border-radius:5px;padding:6px 13px;font-size:13px;font-family:inherit;line-height:1.4;font-weight:600;cursor:pointer;transition:background-color .12s}
  .btn-git:hover{background:#22272e}
  .btn-git.sm{padding:4px 10px}
  .btn-git:disabled{opacity:.55;cursor:default}

  .dbrow{display:flex;align-items:center;flex-wrap:wrap;gap:16px;padding:14px 0;border-top:1px solid var(--line)}
  .dbrow:first-of-type{border-top:none}
  .dbrow .dbname{font-weight:600;font-family:ui-monospace,Consolas,monospace;font-size:13px}
  .dbactions{display:flex;align-items:center;gap:20px;min-width:560px;justify-content:flex-end}
  .dbimport{display:flex;align-items:center;gap:10px}
  .filepick{position:relative;display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border:1px dashed var(--line);border-radius:5px;color:var(--mut);font-size:12px;cursor:pointer;max-width:190px;min-width:0;transition:color .12s,border-color .12s,background-color .12s}
  .filepick:hover{color:var(--ac);border-color:var(--ac);background:rgba(110,168,254,.06)}
  .filepick.has-file{color:var(--tx);border-style:solid}
  .filepick svg{flex:0 0 auto}
  .filepick-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0}
  .filepick input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%}

  .topgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;align-items:start}
  .topgrid .card{margin-bottom:0}
  @media (max-width:1000px){ .topgrid{grid-template-columns:repeat(2,1fr)} }
  @media (max-width:640px){ .topgrid{grid-template-columns:1fr} }

  .pgrid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}
  .pgrid2 .card{margin-bottom:0}

  /* fila de 3 tarjetas de configuracion (identidad, dominio local, dominios) */
  .cfg3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px}
  .cfg3 .card{margin-bottom:0;display:flex;flex-direction:column}
  .cfg3 .cfg3-body{flex:1}
  .cfg3 .cfg3-actions{margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .cfg3 label{display:block;font-size:12px;color:var(--mut);margin:0 0 4px}
  @media (max-width:900px){ .cfg3{grid-template-columns:1fr} }
  /* Icono de disquete = guardar los ajustes de esa card (sin boton en el pie). Guarda por
     AJAX y se pone verde al confirmar el servidor; rojo si el guardado se rechaza. */
  .card.cardsave{position:relative}
  .card.cardsave .cardsave-title{padding-right:38px}
  .savebtn{position:absolute;top:14px;right:14px;display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;padding:0;border:1px solid var(--line);border-radius:7px;background:var(--in);color:var(--mut);cursor:pointer;transition:color .15s,border-color .15s,background-color .15s}
  .savebtn:hover{color:var(--tx);border-color:var(--mut)}
  .savebtn.ok{color:var(--ok);border-color:var(--ok);background:rgba(63,185,80,.14)}
  .savebtn.err{color:var(--err);border-color:var(--err);background:rgba(248,81,73,.14)}
  .savebtn[disabled]{opacity:.55;cursor:default}
  .savemsg{font-size:11.5px;margin-top:8px}
  .savemsg.ok{color:var(--ok)}
  .savemsg.err{color:var(--err)}
  @media (max-width:900px){ .pgrid2{grid-template-columns:1fr} }

  .sitegrid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:14px}
  .sitecard{position:relative;background:var(--card);border:1px solid var(--line);border-radius:8px;padding:14px 16px;display:flex;flex-direction:column;gap:10px;min-width:0}
  .sitecard.is-locked{border-color:var(--warn)}
  .sitecard .cardbody{display:flex;flex-direction:column;gap:4px;min-width:0;flex:1}
  .sitecard .name{font-weight:700;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
  .sitecard .url{color:var(--mut);font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}
  .sitecard .btn{width:100%;text-align:center}
  .cardfooter{margin:0 -16px -14px;padding:8px 16px;border-top:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:6px}
  .cardfooter .phpselform{margin:0;flex:0 0 auto;display:flex;align-items:center;gap:6px;min-width:0}
  .cardfooter select.phpsel{width:auto;padding:5px 6px;font-size:11px;border-radius:4px}
  .cardactions{margin:0;flex:0 0 auto;display:flex;gap:6px}
  .lockform{margin:0}
  .lockbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0;background:var(--card);border:1px solid var(--line);border-radius:5px;color:var(--mut);cursor:pointer;transition:color .12s,border-color .12s,background-color .12s}
  .lockbtn:hover{color:var(--ac);border-color:var(--ac)}
  .lockbtn.on{color:var(--ac);border-color:var(--ac);background:rgba(110,168,254,.12)}
  /* Modal "fijado a la derecha": pasa de dialogo centrado a panel a pantalla completa
     pegado al borde derecho, con el resto de la pagina interactuable (overlay sin fondo
     ni bloqueo de clics) -- pensado para dejarlo abierto mientras se sigue trabajando. */
  .modal-overlay.docked{background:transparent;justify-content:flex-end;align-items:stretch;padding:0;pointer-events:none}
  .modal-overlay.docked .modal-box{pointer-events:auto}
  .modal-box.docked{width:440px!important;max-width:440px!important;height:100vh!important;max-height:100vh!important;margin:0!important;border-radius:0!important;box-shadow:-12px 0 34px rgba(0,0,0,.4);display:flex;flex-direction:column}
  .modal-box.docked .termout{flex:1 1 auto;height:auto!important}
  .sitecard.is-locked .lockbtn{color:var(--warn);border-color:var(--warn);background:rgba(210,153,34,.12)}
  .trashbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0;border:1px solid transparent;border-radius:5px;color:#fff;cursor:pointer;background:linear-gradient(135deg,#ff8a80,var(--err));transition:filter .12s,transform .12s}
  .trashbtn:hover{filter:brightness(1.12)}
  .trashbtn:active{transform:scale(.94)}
  .pwrbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0;border:1px solid transparent;border-radius:5px;color:#fff;cursor:pointer;background:linear-gradient(135deg,#ff8a80,var(--err));transition:filter .12s,transform .12s}
  .pwrbtn:hover{filter:brightness(1.12)}
  .pwrbtn:active{transform:scale(.94)}
  .toollink{display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:6px 10px;border:1px solid var(--line);border-radius:5px;color:var(--mut);font-size:12px;font-weight:600;text-decoration:none;transition:color .12s,border-color .12s,background-color .12s}
  .toollink:hover{color:var(--ac);border-color:var(--ac);background:rgba(110,168,254,.08)}
  .runlink{display:inline-flex;align-items:center;gap:6px;background:none;border:none;padding:3px 2px;color:var(--mut);font-family:ui-monospace,Consolas,monospace;font-size:13px;cursor:pointer;text-decoration:none;transition:color .12s}
  .runlink:hover{color:var(--ac);text-decoration:underline}
  .runlink svg{flex:0 0 auto;opacity:.7;transition:opacity .12s}
  .runlink:hover svg{opacity:1}
  .runlink:disabled{opacity:.5;cursor:default;text-decoration:none}
  .runlink.off{opacity:.4;pointer-events:none;text-decoration:none}
  .runlink-wrap{display:inline-flex;align-items:center;gap:2px}
  .runlink-del{background:none;border:none;color:var(--mut);font-size:14px;line-height:1;cursor:pointer;padding:3px 5px;border-radius:4px;transition:color .12s,background-color .12s}
  .runlink-del:hover{color:var(--err);background:rgba(248,81,73,.10)}
  .runlink-danger{color:var(--err)}
  .runlink-danger:hover{color:var(--err)}
  .runbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0 0 0 1px;background:var(--card);border:1px solid var(--line);border-radius:5px;color:var(--mut);cursor:pointer;transition:color .12s,border-color .12s,background-color .12s}
  .runbtn:hover{color:var(--ac);border-color:var(--ac)}
  .runbtn.running{color:var(--ok);border-color:var(--ok);background:rgba(63,185,80,.14)}
  .runbtn.running:hover{color:var(--ok);border-color:var(--ok)}
  .sitecard.is-locked .lockbtn:hover{color:var(--err);border-color:var(--err);background:rgba(248,81,73,.12)}
  .sitecard.unregistered{background:var(--line);border-style:dashed;border-color:var(--line);opacity:.55}
  .sitecard.unregistered .name{color:var(--mut);font-weight:600}
  .exttag{font-size:9px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--ac);background:rgba(110,168,254,.14);padding:1px 5px;border-radius:999px;vertical-align:middle}
  .typetag{display:inline-flex;align-items:center;gap:3px;font-size:9px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;padding:1px 6px 1px 5px;border-radius:999px;vertical-align:middle;color:var(--mut);background:rgba(139,144,160,.16)}
  .typetag .typeicon{flex:0 0 auto}
  .typetag-wordpress{color:var(--ac);background:rgba(110,168,254,.14)}
  .typetag-laravel{color:var(--err);background:rgba(248,81,73,.14)}
  .typetag-symfony{color:var(--ok);background:rgba(63,185,80,.14)}
  .typetag-slim{color:var(--warn);background:rgba(210,153,34,.14)}
  .typetag-react{color:#3aa6c0;background:rgba(97,218,251,.16)}
  .typetag-vue{color:#35a173;background:rgba(66,184,131,.16)}
  .typetag-angular{color:#e23237;background:rgba(221,0,49,.14)}
  .typetag-nextjs{color:var(--tx);background:rgba(128,128,128,.18)}
  .typetag-nuxt{color:#00b877;background:rgba(0,220,130,.14)}
  .typetag-svelte{color:#ff5722;background:rgba(255,62,0,.14)}
  .typetag-astro{color:#b070f7;background:rgba(168,85,247,.16)}
  .typetag-vite{color:#9b6efe;background:rgba(155,110,254,.16)}
  .typetag-node{color:#57a83f;background:rgba(87,168,63,.16)}
  .typetag-django{color:#2ba977;background:rgba(43,169,119,.16)}
  .typetag-flask{color:var(--mut);background:rgba(139,144,160,.2)}
  .typetag-fastapi{color:#12a99b;background:rgba(0,150,136,.16)}
  .typetag-python{color:#4b8bbe;background:rgba(55,118,171,.18)}
  .tagrow{display:flex;align-items:center;gap:4px;flex-wrap:wrap}
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
  /* Tinte de la banda por tipo de proyecto (solo cuando no hay caratula subida) */
  .cover.empty.type-laravel{background-image:linear-gradient(135deg,rgba(248,81,73,.22),rgba(248,81,73,.06))}
  .cover.empty.type-wordpress{background-image:linear-gradient(135deg,rgba(110,168,254,.22),rgba(110,168,254,.06))}
  .cover.empty.type-symfony{background-image:linear-gradient(135deg,rgba(63,185,80,.20),rgba(63,185,80,.06))}
  .cover.empty.type-slim{background-image:linear-gradient(135deg,rgba(210,153,34,.20),rgba(210,153,34,.06))}
  .cover.empty.type-react{background-image:linear-gradient(135deg,rgba(97,218,251,.22),rgba(97,218,251,.05))}
  .cover.empty.type-vue{background-image:linear-gradient(135deg,rgba(66,184,131,.20),rgba(66,184,131,.05))}
  .cover.empty.type-angular{background-image:linear-gradient(135deg,rgba(221,0,49,.20),rgba(176,0,224,.06))}
  .cover.empty.type-nextjs{background-image:linear-gradient(135deg,rgba(128,128,128,.22),rgba(128,128,128,.05))}
  .cover.empty.type-nuxt{background-image:linear-gradient(135deg,rgba(0,220,130,.20),rgba(0,220,130,.05))}
  .cover.empty.type-svelte{background-image:linear-gradient(135deg,rgba(255,62,0,.20),rgba(255,62,0,.05))}
  .cover.empty.type-astro{background-image:linear-gradient(135deg,rgba(168,85,247,.22),rgba(255,120,60,.08))}
  .cover.empty.type-vite{background-image:linear-gradient(135deg,rgba(155,110,254,.22),rgba(255,206,0,.08))}
  .cover.empty.type-node{background-image:linear-gradient(135deg,rgba(87,168,63,.20),rgba(87,168,63,.05))}
  .cover.empty.type-django{background-image:linear-gradient(135deg,rgba(43,169,119,.20),rgba(43,169,119,.05))}
  .cover.empty.type-flask{background-image:linear-gradient(135deg,rgba(139,144,160,.20),rgba(139,144,160,.05))}
  .cover.empty.type-fastapi{background-image:linear-gradient(135deg,rgba(0,150,136,.20),rgba(0,150,136,.05))}
  .cover.empty.type-python{background-image:linear-gradient(135deg,rgba(55,118,171,.20),rgba(255,212,59,.08))}
  .cover-hint{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:6px;font-size:11px;font-weight:600;letter-spacing:.2px}
  .cover.empty .cover-hint{color:var(--mut)}
  .cover.has .cover-hint{color:#fff;background:rgba(10,12,18,.5);opacity:0;transition:opacity .12s}
  .cover.has:hover .cover-hint{opacity:1}
  .coverdel{position:absolute;top:8px;left:8px;margin:0;z-index:3}
  .pinform{position:absolute;top:8px;right:8px;margin:0;z-index:3}
  .pinbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0;border:1px solid transparent;border-radius:5px;color:#fff;background:rgba(10,12,18,.55);cursor:pointer;transition:color .12s,background-color .12s,transform .12s}
  .pinbtn:hover{background:rgba(10,12,18,.75)}
  .pinbtn:active{transform:scale(.9)}
  .pinbtn.is-pinned{background:linear-gradient(135deg,#6ea8fe,#9b6efe)}
  .sitecard.is-pinned{border-color:#9b6efe}
  .coverdelbtn{display:flex;align-items:center;justify-content:center;width:22px;height:22px;padding:0;border:0;border-radius:5px;background:rgba(10,12,18,.55);color:#fff;font-size:15px;line-height:1;cursor:pointer}
  .coverdelbtn:hover{background:var(--err)}

  /* Selector de vista (cuadricula/lista) */
  .viewtoggle{display:flex;gap:2px;background:var(--card);border:1px solid var(--line);border-radius:6px;padding:2px}
  .viewtoggle button{display:flex;align-items:center;justify-content:center;width:28px;height:26px;padding:0;border:0;border-radius:4px;background:transparent;color:var(--mut);cursor:pointer;transition:color .12s,background-color .12s}
  .viewtoggle button:hover{color:var(--ac)}
  .viewtoggle button.on{background:var(--ac);color:#fff}

  .sitegrid.list{grid-template-columns:1fr}
  .sitegrid.list .sitecard{flex-direction:row;align-items:center;gap:14px;padding:10px 16px}
  .sitegrid.list .sitecard .coverform,
  .sitegrid.list .sitecard .coverdel{display:none}
  .sitegrid.list .sitecard .pinform{position:static}
  .sitegrid.list .sitecard .cardbody{flex-direction:row;align-items:center;gap:10px;flex:1;min-width:0}
  .sitegrid.list .sitecard .name{flex:0 0 220px}
  .sitegrid.list .sitecard .tagrow{flex:0 0 auto}
  .sitegrid.list .sitecard .url{flex:1}
  .sitegrid.list .sitecard form{width:auto;margin:0}
  .sitegrid.list .sitecard .btn{width:auto}
  .sitegrid.list .sitecard .cardfooter{margin:0;padding:0;border:0;flex:0 0 auto}
  .sitegrid.list .sitecard select.phpsel{width:auto}

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
  .modal-actions .btn{width:auto;padding:6px 15px}

  .loader-overlay{position:fixed;inset:0;background:rgba(6,7,10,.5);display:flex;align-items:center;justify-content:center;z-index:200}
  .loader-overlay[hidden]{display:none}
  .loader-box{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:20px 28px;box-shadow:0 20px 60px rgba(0,0,0,.45);display:flex;align-items:center;gap:14px;max-width:min(90vw,420px)}
  .loader-spin{width:22px;height:22px;flex:0 0 auto;border-radius:999px;border:3px solid var(--line);border-top-color:var(--ac);animation:loaderspin .7s linear infinite}
  .loader-tx{font-size:14px;font-weight:600;color:var(--tx);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  @keyframes loaderspin{to{transform:rotate(360deg)}}
  .btn-spin{display:inline-block;width:12px;height:12px;border-radius:999px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;animation:loaderspin .7s linear infinite;vertical-align:-2px;margin-right:6px}

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
  .progressbar{position:relative;height:8px;border-radius:999px;background:var(--in);border:1px solid var(--line);overflow:hidden;margin-top:8px}
  .progressbar-fill{height:100%;border-radius:999px;background-image:linear-gradient(135deg,var(--brand-start),var(--brand-end));transition:width .3s ease}
  .progressbar-fill.err{background-image:linear-gradient(135deg,var(--err),var(--err-dark))}
  .progresspct{font-size:11px;color:var(--mut);margin-top:4px;display:block}

  /* ---------- Explorador de SQL Server ---------- */
  .sqlx{display:flex;gap:14px;align-items:flex-start}
  .sqlx-side{flex:0 0 270px;width:270px;position:sticky;top:12px}
  .sqlx-main{flex:1;min-width:0}
  .sqlx-side .card,.sqlx-main .card{margin:0}
  .sqlx-tables{max-height:56vh;overflow:auto;margin:8px -6px 0}
  .sqlx-t{display:flex;align-items:center;gap:7px;width:100%;text-align:left;background:none;border:0;color:var(--tx);
          font:inherit;font-size:12.5px;padding:5px 8px;border-radius:5px;cursor:pointer;font-family:ui-monospace,Consolas,monospace}
  .sqlx-t:hover{background:rgba(110,168,254,.10)}
  .sqlx-t.on{background:rgba(110,168,254,.16);color:var(--ac);font-weight:600}
  .sqlx-t .n{margin-left:auto;font-size:10.5px;color:var(--mut);font-family:inherit}
  .sqlx-t.view .ico{opacity:.55}
  .sqlx-empty{color:var(--mut);font-size:12px;padding:10px 8px}
  .sqlx-views{display:flex;gap:16px;border-bottom:1px solid var(--line);margin:-4px -4px 12px;padding:0 4px}
  .sqlx-views button{background:none;border:0;border-bottom:2px solid transparent;color:var(--mut);font:inherit;
                     font-size:13px;font-weight:600;padding:8px 2px;margin-bottom:-1px;cursor:pointer}
  .sqlx-views button:hover{color:var(--tx)}
  .sqlx-views button.on{color:var(--ac);border-bottom-color:var(--ac)}
  /* ---- gestor de Redis (reutiliza .sqlx / .sqltbl; solo lo propio va aqui) ---- */
  .rdb{display:flex;flex-direction:column;gap:1px;margin:8px -6px 0;max-height:46vh;overflow:auto}
  .rdb button{display:flex;align-items:center;gap:7px;width:100%;text-align:left;background:none;border:0;color:var(--tx);
              font:inherit;font-size:12.5px;padding:5px 10px;border-radius:5px;cursor:pointer}
  .rdb button:hover{background:rgba(110,168,254,.10)}
  .rdb button.on{background:rgba(110,168,254,.16);color:var(--ac);font-weight:600}
  .rdb button .n{margin-left:auto;font-size:10.5px;color:var(--mut);font-family:ui-monospace,Consolas,monospace}
  .rdb button.vacia{color:var(--mut)}
  /* Etiqueta de tipo de clave. Cada tipo su color para reconocerlo de un vistazo en la lista. */
  .rtype{font-size:10px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;padding:1px 5px;border-radius:3px;
         font-family:inherit;flex:0 0 auto}
  .rtype.string{background:rgba(110,168,254,.18);color:var(--ac)}
  .rtype.hash{background:rgba(155,110,254,.18);color:#b18cff}
  .rtype.list{background:rgba(63,185,80,.18);color:var(--ok)}
  .rtype.set{background:rgba(210,153,34,.18);color:var(--warn)}
  .rtype.zset{background:rgba(248,81,73,.16);color:var(--err)}
  .rtype.stream{background:rgba(125,125,125,.18);color:var(--mut)}
  .rkeys{max-height:52vh;overflow:auto;border:1px solid var(--line);border-radius:6px;background:var(--in)}
  .rkey{display:flex;align-items:center;gap:8px;padding:5px 9px;border-bottom:1px solid var(--line);cursor:pointer}
  .rkey:last-child{border-bottom:none}
  .rkey:hover{background:rgba(110,168,254,.08)}
  .rkey.on{background:rgba(110,168,254,.16)}
  .rkey .kn{font-family:ui-monospace,Consolas,monospace;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0}
  .rkey .kt{font-size:10.5px;color:var(--mut);font-family:ui-monospace,Consolas,monospace;flex:0 0 auto}
  .rval{width:100%;min-height:190px;font-family:ui-monospace,Consolas,monospace;font-size:12.5px;line-height:1.5;
        background:var(--in);color:var(--tx);border:1px solid var(--line);border-radius:6px;padding:9px;resize:vertical}
  .rcons{background:var(--in);border:1px solid var(--line);border-radius:6px;padding:10px;max-height:32vh;overflow:auto;
         font-family:ui-monospace,Consolas,monospace;font-size:12.5px;white-space:pre-wrap;line-height:1.5}
  .rcons .cin{color:var(--ac)}
  .rcons .cerr{color:var(--err)}
  .rcons .cnil{color:var(--mut);font-style:italic}
  .rinfo{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
  .rinfo .b{background:var(--in);border:1px solid var(--line);border-radius:6px;padding:9px 11px}
  .rinfo .b .l{font-size:10.5px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}
  .rinfo .b .v{font-size:15px;font-weight:600;margin-top:2px;font-family:ui-monospace,Consolas,monospace}
  .sqlgrid{overflow:auto;max-height:60vh;border:1px solid var(--line);border-radius:6px;background:var(--in)}
  table.sqltbl{border-collapse:separate;border-spacing:0;width:100%;font-size:12px;font-family:ui-monospace,Consolas,monospace}
  table.sqltbl th,table.sqltbl td{padding:5px 9px;border-bottom:1px solid var(--line);white-space:nowrap;
                                  max-width:340px;overflow:hidden;text-overflow:ellipsis;vertical-align:top}
  table.sqltbl thead th{position:sticky;top:0;z-index:1;background:var(--card);border-bottom:1px solid var(--line);
                        text-align:left;font-weight:600;font-family:inherit;font-size:11.5px;color:var(--mut);cursor:pointer;user-select:none}
  table.sqltbl thead th:hover{color:var(--tx)}
  table.sqltbl thead th .pkmark{color:var(--warn);margin-left:4px}
  table.sqltbl thead th .dirmark{color:var(--ac);margin-left:3px}
  table.sqltbl tbody tr:hover{background:rgba(110,168,254,.06)}
  table.sqltbl td.acts{white-space:nowrap;position:sticky;left:0;background:var(--in)}
  table.sqltbl tbody tr:hover td.acts{background:#eef2f8}
  @media (prefers-color-scheme:dark){table.sqltbl tbody tr:hover td.acts{background:#1b2027}}
  :root[data-theme="dark"] table.sqltbl tbody tr:hover td.acts{background:#1b2027}
  :root[data-theme="light"] table.sqltbl tbody tr:hover td.acts{background:#eef2f8}
  .sqlnull{color:var(--mut);font-style:italic;opacity:.75}
  .sqlbin{color:var(--warn)}
  .sqlrowbtn{background:none;border:1px solid var(--line);border-radius:4px;color:var(--mut);cursor:pointer;
             padding:1px 6px;font-size:11px;font-family:inherit;line-height:1.5}
  .sqlrowbtn:hover{color:var(--ac);border-color:var(--ac)}
  .sqlrowbtn.del:hover{color:var(--err);border-color:var(--err)}
  .sqlpager{display:flex;align-items:center;gap:10px;margin-top:10px;font-size:12px;color:var(--mut);flex-wrap:wrap}
  .sqlfield{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
  .sqlfield label{font-size:12px;color:var(--mut)}
  .sqlfield .rowline{display:flex;align-items:center;gap:8px}
  .sqlfield input[type=text],.sqlfield textarea{width:100%;font-family:ui-monospace,Consolas,monospace;font-size:12.5px}
  .sqlfield textarea{min-height:80px;resize:vertical}
  .sqlfield .meta{font-size:11px;color:var(--mut)}
  .sqlnullbox{display:flex;align-items:center;gap:5px;font-size:11.5px;color:var(--mut);white-space:nowrap;cursor:pointer}
  #sqlEditorHost .CodeMirror{height:190px;border:1px solid var(--line);border-radius:6px;font-size:13px}
  .sqlmsg{font-size:12px;padding:8px 11px;border-radius:6px;border:1px solid;margin-bottom:10px}
  .sqlmsg.err{background:rgba(248,81,73,.12);border-color:var(--err);color:var(--err)}
  .sqlmsg.ok{background:rgba(63,185,80,.12);border-color:var(--ok);color:var(--ok)}
  .sqlmsg.warn{background:rgba(210,153,34,.12);border-color:var(--warn);color:var(--warn)}
  .msgtext{font-size:12.5px;margin-bottom:6px}
  .msgtext.err{color:var(--err)}
  .msgtext.ok{color:var(--ok)}
  .msgtext.warn{color:var(--warn)}
  .logview{background:var(--in);border:1px solid var(--line);border-radius:3px;padding:10px;font-family:ui-monospace,Consolas,monospace;font-size:13px;white-space:pre-wrap;max-height:62vh;overflow:auto;color:var(--mut)}
  .logview .log-fatal{color:var(--err);font-weight:700}
  .logview .log-warning{color:var(--warn);font-weight:600}
  .logview .log-deprecated{color:var(--mut);opacity:.6}
  .logview .log-notice{color:var(--ac)}
  .logview .log-info{color:var(--tx)}

  /* ---------- Selector de log con buscador (pestaña Logs) ---------- */
  .logpicker{position:relative;width:260px;max-width:100%}
  .logpicker-input{width:100%}
  .logpicker-list{position:absolute;z-index:20;top:calc(100% + 4px);left:0;right:0;max-height:280px;overflow:auto;background:var(--card);border:1px solid var(--line);border-radius:6px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
  .logpicker-opt{padding:7px 10px;cursor:pointer;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .logpicker-opt:hover,.logpicker-opt.on{background:rgba(110,168,254,.14);color:var(--ac)}
  .logpicker-opt.sel{font-weight:600}
  .logpicker-empty{padding:7px 10px;color:var(--mut);font-size:13px}
  .loglink{color:var(--ac);font-size:13px;text-decoration:none}
  .loglink:hover{text-decoration:underline}
  .loglink.active{color:var(--tx);font-weight:700;text-decoration:none;cursor:default}
  .loglink-sep{color:var(--mut);font-size:12px}

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
  .termcmd-input{flex:1;width:100%;background:transparent;border:none;color:var(--tx);font-family:ui-monospace,Consolas,monospace;font-size:13px;padding:4px 0}
  .termcmd-input:focus{outline:none}
  .termcmd-input:disabled{opacity:.5}
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
    <div class="logo"><img src="<?= $brandLogo ? '?brandlogo&t='.filemtime($brandLogo) : 'assets/logo.svg' ?>" alt="<?= e($brandName) ?>"></div>
    <div>
      <h1><?= e($brandName) ?><?php if ($luaVer !== ''): ?><a class="verchip<?= $updHay ? ' hay' : '' ?>" href="?tab=config#actualizaciones"
        title="<?= $updHay ? e($updDetras.' actualización(es) disponible(s) — pulsa para ver') : 'Versión de la plataforma' ?>"><?= e($luaVer) ?><?php if ($updHay): ?> &bull; <?= (int)$updDetras ?> nueva(s)<?php endif; ?></a><?php endif; ?></h1>
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
      <?php /* SQL Server y Redis no tienen pestana propia: se entra por sus enlaces en
               "Bases de datos", que queda resaltada tambien dentro de esos gestores. */ ?>
      <a href="?tab=bd" class="<?= in_array($tab,['bd','sqlsrv','redis'],true)?'on':'' ?>">Bases de datos</a>
      <a href="?tab=procs" class="<?= $tab==='procs'?'on':'' ?>">Procesos</a>
      <a href="?tab=doctor" class="<?= $tab==='doctor'?'on':'' ?>">Doctor</a>
      <?php if (docker_installed()): ?>
        <a href="?tab=docker" class="<?= $tab==='docker'?'on':'' ?>">Docker</a>
      <?php endif; ?>
      <a href="?tab=logs" class="<?= $tab==='logs'?'on':'' ?>">Logs</a>
      <a href="?tab=terminal" class="<?= $tab==='terminal'?'on':'' ?>">Terminal</a>
      <a href="?tab=config" class="<?= $tab==='config'?'on':'' ?>">Configuración del servidor</a>
      <a href="?tab=docs" class="<?= $tab==='docs'?'on':'' ?>">Documentación</a>
    </div>
  </div>

  <div class="content">

  <?php if ($mtext): ?>
    <div class="banner <?= e($mtype) ?>">
      <?= e($mtext) ?>
      <?php if ($mtype==='applied'): ?> <span class="muted">— la página se actualizará sola en cuanto el servidor responda.</span><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab==='proyectos'): ?>

    <?php
      $mariaOn = is_file($ROOT.'/config/mariadb.on'); [$mariaCls,$mariaLbl] = svc_status($mariaOn, 3306); $termOn = is_file($ROOT.'/config/terminal.on');
      // Valores por defecto de las contrasenas del wizard de WordPress: se generan en cada
      // carga del formulario (no se guardan en ningun sitio hasta que el usuario le da a
      // "Crear") para que ya se vea algo razonable sin escribir nada, pero siguen siendo
      // 100% editables -- la BD se crea con lo que haya en el campo al enviar, sea el valor
      // generado o uno propio.
      $wpDefDbPass = bin2hex(random_bytes(6)); $wpDefAdminPass = bin2hex(random_bytes(6));
    ?>
    <div class="row" style="margin-bottom:14px">
      <button type="button" class="btn" onclick="luaOpenNewProject()">+ Nuevo proyecto</button>
    </div>

    <div class="topgrid">
      <div class="card" style="display:flex;flex-direction:column">
        <div class="row" style="gap:6px">
          <div style="font-weight:600">Servidor MySQL (MariaDB) <span class="jstate <?= $mariaCls ?>" style="margin-left:6px"><?= $mariaLbl ?></span></div>
          <div class="spacer"></div>
          <a class="lockbtn" href="?tab=bd" title="Configuración de bases de datos" aria-label="Configuración de bases de datos">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          </a>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="mariadb">
            <input type="hidden" name="enable" value="<?= $mariaOn?'0':'1' ?>">
            <input type="hidden" name="from_tab" value="proyectos">
            <button type="submit" class="pwrbtn" title="<?= $mariaOn?'Desactivar servidor MySQL':'Crear / activar servidor MySQL' ?>" aria-label="<?= $mariaOn?'Desactivar servidor MySQL':'Crear / activar servidor MySQL' ?>">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
            </button>
          </form>
        </div>
        <div class="muted" style="margin-top:6px">Nativo en <code>127.0.0.1:3306</code>, usuario <code>root</code> <?= mysql_root_pass($ROOT)!==''?'con contraseña':'sin contraseña' ?>.</div>
        <div class="spacer"></div>
        <?php if ($mariaOn): ?>
          <div class="row" style="gap:8px;margin-top:10px">
            <a class="toollink" href="http://<?= e($phpmyadminDom) ?>/" target="_blank" style="flex:1">phpMyAdmin &#8599;</a>
            <a class="toollink" href="/adminer.php?server=127.0.0.1&username=root" target="_blank" style="flex:1" title="Adminer pide contraseña: crea un usuario con clave para tu proyecto, o usa bin\mariadb\bin\mariadb.exe.">Adminer &#8599;</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Modal: alta de proyecto, en dos partes -- arriba crear uno nuevo (con la instalacion
         guiada de WordPress), abajo (colapsado) registrar uno ya existente en otra carpeta. -->
    <div id="newProjectModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseNewProject()">
      <div class="modal-box" role="dialog" aria-modal="true" style="max-width:680px;max-height:85vh;overflow-y:auto;text-align:left">
        <div class="row" style="margin-bottom:14px">
          <h3 style="margin:0;font-size:16px">Nuevo proyecto</h3>
          <div class="spacer"></div>
          <button type="button" class="lockbtn" onclick="luaCloseNewProject()" title="Cerrar" aria-label="Cerrar">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>

        <form method="post">
          <input type="hidden" name="action" value="create">
          <div class="inline">
            <div>
              <label>Nombre del proyecto</label>
              <input name="name" id="newprojname" placeholder="micliente" pattern="[a-z0-9][a-z0-9_-]*" required>
            </div>
            <div>
              <label>Tipo</label>
              <select name="type" id="newprojtype" onchange="luaNewProjTypeChange(this.value)">
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
          </div>
          <div id="gitrow" style="display:none;margin-top:12px">
            <label>URL del repositorio Git</label>
            <input name="url" placeholder="https://github.com/usuario/repo.git" style="width:100%">
          </div>
          <?php if ($mariaOn): ?>
            <label id="withdbrow" style="display:flex;align-items:center;gap:6px;margin-top:12px;font-weight:400;cursor:pointer">
              <input type="checkbox" name="withdb" value="1" checked style="width:auto">
              Crear base de datos MySQL a juego (mismo nombre)
            </label>
          <?php endif; ?>

          <?php if ($mariaOn): ?>
            <div id="wprow" style="display:none;margin-top:16px;padding-top:14px;border-top:1px solid var(--line)">
              <div class="muted" style="font-size:12px;margin-bottom:10px">Instalación guiada: la base de datos, el usuario de MySQL y el sitio se crean solos con estos datos exactos -- no hace falta pasar por la pantalla de instalación de WordPress.</div>
              <div style="font-weight:600;margin-bottom:8px;font-size:13px">Paso 2 — Base de datos</div>
              <div class="inline">
                <div><label>Nombre de la BD</label><input name="wp_dbname" id="wpdbname" placeholder="micliente" pattern="[a-zA-Z0-9_]{1,64}"></div>
                <div><label>Usuario de la BD</label><input name="wp_dbuser" id="wpdbuser" placeholder="wp_micliente" pattern="[a-zA-Z0-9_]{1,32}"></div>
                <div><label>Contraseña de la BD</label><input type="text" name="wp_dbpass" value="<?= e($wpDefDbPass) ?>" autocomplete="off"></div>
              </div>
              <div style="font-weight:600;margin:14px 0 8px;font-size:13px">Paso 3 — Sitio</div>
              <div class="inline">
                <div><label>Título del sitio</label><input name="wp_title" id="wptitle" placeholder="Mi sitio WordPress"></div>
                <div><label>Usuario admin</label><input name="wp_adminuser" value="admin"></div>
                <div><label>Contraseña admin</label><input type="text" name="wp_adminpass" value="<?= e($wpDefAdminPass) ?>" autocomplete="off"></div>
                <div><label>Email admin</label><input type="email" name="wp_adminemail" placeholder="tu@email.com"></div>
              </div>
            </div>
          <?php else: ?>
            <div id="wprow" class="muted" style="display:none;margin-top:12px;font-size:12px">Activa MariaDB en <a href="?tab=config">Configuración del servidor</a> para poder crear proyectos WordPress: la instalación guiada necesita crear su base de datos.</div>
          <?php endif; ?>
          <div class="row" style="margin-top:16px">
            <div class="spacer"></div>
            <button class="btn" type="submit">+ Crear</button>
          </div>
        </form>
        <div class="muted" style="margin-top:10px">Laravel/Symfony/Slim usan Composer; WordPress hace una instalación guiada completa (BD + usuario MySQL + sitio, listo para entrar a <code>/wp-admin</code>); Git clona el repo (y ejecuta <code>composer install</code> si hay <code>composer.json</code>). Se hace en segundo plano.<?= $mariaOn?' En Laravel, la conexión se escribe sola en el <code>.env</code>.':'' ?></div>

        <details class="extform" style="margin-top:20px;padding-top:16px;border-top:1px solid var(--line)">
          <summary>Registrar proyecto existente en otra carpeta del disco <span class="muted">(p.ej. <code>C:\proyectos\micliente</code> con dominio propio)</span></summary>
          <form method="post" style="margin-top:14px">
            <input type="hidden" name="action" value="add_external">
            <div class="inline">
              <div>
                <label>Nombre (identificador)</label>
                <input name="name" placeholder="micliente" pattern="[a-z0-9][a-z0-9_-]*" required>
              </div>
              <div style="flex:1;min-width:280px">
                <label>Ruta de la carpeta en disco</label>
                <input name="path" placeholder="C:\proyectos\micliente" style="width:100%" required>
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
      </div>
    </div>
    <script>
      function luaOpenNewProject(){
        document.getElementById('newProjectModal').hidden = false;
        document.addEventListener('keydown', luaEscNewProject);
      }
      function luaCloseNewProject(){
        document.getElementById('newProjectModal').hidden = true;
        document.removeEventListener('keydown', luaEscNewProject);
      }
      function luaEscNewProject(e){ if(e.key==='Escape') luaCloseNewProject(); }
      function luaNewProjTypeChange(t){
        var isGit = (t === 'git'), isWp = (t === 'wordpress');
        var gitrow = document.getElementById('gitrow'); if (gitrow) gitrow.style.display = isGit ? 'block' : 'none';
        var wprow = document.getElementById('wprow'); if (wprow) wprow.style.display = isWp ? 'block' : 'none';
        var withdbrow = document.getElementById('withdbrow'); if (withdbrow) withdbrow.style.display = isWp ? 'none' : 'flex';
        document.querySelectorAll('#wprow input[name^="wp_"]').forEach(function(el){
          if (isWp) el.setAttribute('required','required'); else el.removeAttribute('required');
        });
      }
      (function(){
        // Autocompleta nombre de BD/usuario/titulo a partir del nombre del proyecto, pero
        // solo mientras el usuario no haya tocado esos campos a mano (dataset.touched):
        // una vez editados, dejan de seguir al nombre del proyecto.
        var nameEl = document.getElementById('newprojname');
        var dbn = document.getElementById('wpdbname'), dbu = document.getElementById('wpdbuser'), ttl = document.getElementById('wptitle');
        if (!nameEl) return;
        [dbn, dbu, ttl].forEach(function(el){ if (el) el.addEventListener('input', function(){ this.dataset.touched='1'; }); });
        nameEl.addEventListener('input', function(){
          var v = this.value.trim();
          var slug = v.replace(/[^a-zA-Z0-9_]/g, '_');
          if (dbn && !dbn.dataset.touched) dbn.value = slug;
          if (dbu && !dbu.dataset.touched) dbu.value = slug ? ('wp_' + slug).slice(0, 32) : '';
          if (ttl && !ttl.dataset.touched) ttl.value = v;
        });
      })();
      <?php if ($reopenNewProject): ?>
      luaOpenNewProject();
      <?php endif; ?>
    </script>

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
          ['secDestacados','secProyectos','secUnreg'].forEach(function(secId){
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

    <?php $sitesPinned = array_filter($sitesView, function($i){ return is_array($i) && !empty($i['pinned']); }); ?>
    <?php if ($sitesPinned): ?>
    <details class="sectioncollapse" id="secDestacados" open>
      <summary>Destacados <span class="op">(<?= count($sitesPinned) ?>)</span><span class="arrow"></span></summary>
      <div class="pane">
        <div class="sitegrid">
          <?php foreach ($sitesPinned as $name => $info): render_site_card($name, $info); endforeach; ?>
        </div>
      </div>
    </details>
    <?php endif; ?>

    <?php $sitesSinTipo = 0; foreach ($sitesView as $sInfo) { if (is_array($sInfo) && empty($sInfo['type']) && empty($sInfo['typeChecked'])) $sitesSinTipo++; } ?>
    <?php $sitesFaltantes = missing_projects($WWW, $sitesView); ?>
    <details class="sectioncollapse" id="secProyectos" open>
      <summary>Proyectos <span class="op">(<?= count($sitesView) ?>)</span>
        <?php if ($sitesSinTipo > 0): ?>
        <form method="post" title="Detecta el framework (PHP, JavaScript o Python) de los proyectos ya registrados">
          <input type="hidden" name="action" value="detect_types">
          <!-- Un <button type=submit> dentro de <summary> no envia en Chrome (el summary
               se queda el clic para abrir/cerrar el <details>): forzamos el submit por JS. -->
          <button type="button" class="btn ghost sm" onclick="event.stopPropagation();event.preventDefault();this.closest('form').requestSubmit()">Detectar tipos (<?= $sitesSinTipo ?>)</button>
        </form>
        <?php endif; ?>
        <?php if ($sitesFaltantes): ?>
        <form method="post" title="Quita de la lista los proyectos cuya carpeta ya no existe en www\ (borrada fuera del panel)" onsubmit="event.stopPropagation()">
          <input type="hidden" name="action" value="sync_projects">
          <button type="button" class="btn ghost sm" onclick="event.stopPropagation();event.preventDefault();if(confirm('Se quitarán '+<?= count($sitesFaltantes) ?>+' proyecto(s) cuya carpeta ya no existe: '+<?= json_encode(implode(', ', $sitesFaltantes)) ?>+'. La carpeta ya no está, así que no hay nada que borrar en disco. ¿Continuar?'))this.closest('form').requestSubmit()">Sincronizar proyectos (<?= count($sitesFaltantes) ?>)</button>
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
        <?php foreach ($sitesView as $name => $info): render_site_card($name, $info); endforeach; ?>
      </div>
    <?php endif; ?>
      </div>
    </details>

    <?php if ($unreg): ?>
    <details class="sectioncollapse" id="secUnreg">
      <summary>Sin registrar <span class="op">(<?= count($unreg) ?>) — carpetas detectadas en <code>www\</code> pendientes de integrar</span>
        <form method="post" onclick="event.stopPropagation()" onsubmit="return confirm('Integrar las <?= count($unreg) ?> carpetas sin registrar (PHP <?= e($defaultPhp) ?> por defecto; se usa la versión de composer.json cuando se pueda detectar)?')">
          <input type="hidden" name="action" value="integrate_all">
          <input type="hidden" name="php" value="<?= e($defaultPhp) ?>">
          <button class="btn ghost sm" type="submit">Integrar todo</button>
        </form>
        <span class="arrow"></span>
      </summary>
      <div class="pane">
      <div class="sitegrid">
        <?php foreach ($unreg as $name):
              $dPhp = detect_project_php("$WWW/$name", $vers);
              $selPhp = $dPhp ?: $defaultPhp; ?>
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
                <select name="php" class="phpsel" title="<?= $dPhp?'Detectada de composer.json':'Versión por defecto (sin pista en el proyecto)' ?>">
                  <?php foreach ($vers as $v): ?>
                    <option value="<?= e($v) ?>" <?= $v===$selPhp?'selected':'' ?>>PHP <?= e($v) ?><?= ($v===$dPhp)?' (detectada)':'' ?></option>
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
        <p class="modal-tx">Se quitará <strong id="delName"></strong> del panel y se recargará Apache. <span id="delConsequence"></span></p>
        <form method="post" class="modal-actions">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="name" id="delNameInput">
          <button type="button" class="btn ghost" onclick="luaCloseDelete()">Cancelar</button>
          <button type="submit" class="btn danger">Sí, eliminar</button>
        </form>
      </div>
    </div>
    <script>
      // isExternal/hasDb/dbName vienen de datos ya validados por valid_name()/valid_dbname()
      // (solo letras/numeros/_/-), nunca texto libre -- por eso es seguro construir este HTML
      // por concatenacion simple, sin pasar por textContent.
      function luaAskDelete(name, isExternal, hasDb, dbName){
        document.getElementById('delName').textContent = name;
        var cons = document.getElementById('delConsequence');
        if (isExternal) {
          cons.innerHTML = 'Es una carpeta externa (fuera de <code>www\\</code>): <strong>no se toca en disco</strong>.'
            + (hasDb ? ' Se eliminará la base de datos "'+dbName+'".' : '');
        } else {
          cons.innerHTML = 'Se borrará también <strong>la carpeta del proyecto</strong> (<code>www\\'+name+'</code>) y todo su contenido, de forma <strong>permanente</strong>.'
            + (hasDb ? ' También se eliminará la base de datos "'+dbName+'" y su usuario de MySQL.' : '');
        }
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

  <?php elseif ($tab==='proyecto'): /* ---------- FICHA DE PROYECTO ---------- */
      $pName = (string)($_GET['name'] ?? '');
      // Acepta un name= con distinto casing/espacios que la clave real de sites.json
      // (p.ej. "arquitecturaTgin", el nombre tal cual en el Explorador de Windows, cuando
      // la clave registrada quedo en minusculas via slug_from_name): sin esto, la ficha
      // y cualquier accion que se dispare desde ella fallaban en silencio.
      $pKey = resolve_site_key($sites, $pName);
      if ($pKey !== null) { $pName = $pKey; }
      $pInfo = $pKey !== null ? $sites[$pKey] : null; ?>

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
        $pHasArtisan = is_file($pDir.'/artisan');
        $termOn = is_file($ROOT.'/config/terminal.on');
        $pGit = is_dir($pDir) ? git_info($pDir) : null;
        $pErrLog = tail_file($ROOT.'/logs/apache/'.$pName.'-error.log', 200);
        $pType = is_array($pInfo) ? ($pInfo['type'] ?? null) : null;
        $pTypeLabel = project_type_label($pType); ?>

      <div class="card row" style="align-items:flex-start;flex-wrap:wrap;gap:16px">
        <?php if ($pCoverFile): ?>
          <div style="width:110px;height:74px;border-radius:6px;flex:0 0 auto;background-size:cover;background-position:center;background-image:url('?cover=<?= e(rawurlencode($pName)) ?>&t=<?= filemtime($pCoverFile) ?>')"></div>
        <?php endif; ?>
        <div style="min-width:240px;flex:1">
          <div class="row" style="gap:8px">
            <span style="font-size:20px;font-weight:700"><?= e($pName) ?></span>
            <?php if ($pTypeLabel): ?><span class="typetag typetag-<?= e($pType) ?>"><?= project_type_icon($pType) ?><?= e($pTypeLabel) ?></span><?php endif; ?>
            <?php if ($pExtPath): ?><span class="exttag" title="Proyecto externo: <?= e($pExtPath) ?>">ext</span><?php endif; ?>
            <span class="jstate <?= $pLocked?'warn':'ok' ?>"><?= $pLocked?'Bloqueado':'Desbloqueado' ?></span>
            <span class="jstate run">PHP <?= e($pVer) ?></span>
            <?php if ($termOn && ($pHasComposer || $pHasNpm || $pHasArtisan)): ?>
              <button type="button" class="runbtn lua-runbtn" title="Ejecutar Composer/NPM/Artisan" aria-label="Ejecutar Composer/NPM/Artisan" data-name="<?= e($pName) ?>" data-path="<?= e(term_win($pDir)) ?>" data-composer="<?= $pHasComposer?'1':'0' ?>" data-npm="<?= $pHasNpm?'1':'0' ?>" data-artisan="<?= $pHasArtisan?'1':'0' ?>" data-php="<?= e($pVer) ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              </button>
            <?php endif; ?>
          </div>
          <a class="url" href="http://<?= e($pDom) ?>" target="_blank" style="display:inline-block;margin-top:6px">http://<?= e($pDom) ?> &#8599;</a>
          <form method="post" class="inline" style="margin-top:6px;gap:6px">
            <input type="hidden" name="action" value="set_domain">
            <input type="hidden" name="name" value="<?= e($pName) ?>">
            <input name="domain" value="<?= e($pInfo['domain'] ?? '') ?>" placeholder="<?= e($pName.'.'.$tld) ?> (por defecto)" style="width:230px;font-size:12px">
            <button class="btn ghost sm" type="submit" title="Deja el campo vacío para volver al dominio por defecto">Guardar dominio</button>
          </form>
          <div class="muted" style="margin-top:8px;font-size:12px;font-family:ui-monospace,Consolas,monospace" title="<?= e($pDir) ?>"><?= e($pDir) ?></div>
          <div class="row" style="margin-top:10px;gap:6px">
            <?php if ($pHasComposer): ?><span class="tag">composer.json</span><?php endif; ?>
            <?php if ($pHasNpm): ?><span class="tag">package.json</span><?php endif; ?>
            <?php if ($pHasArtisan): ?><span class="tag">artisan</span><?php endif; ?>
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
          <div class="card">
            <div class="muted">Este proyecto no es un repositorio Git (o <code>git</code> no está disponible en esta máquina).</div>
            <form method="post" class="inline" style="margin-top:12px">
              <input type="hidden" name="action" value="git_connect">
              <input type="hidden" name="name" value="<?= e($pName) ?>">
              <input name="url" placeholder="https://github.com/usuario/repo.git" style="flex:1;min-width:240px" required>
              <button class="btn-git" type="submit" title="Inicializa el repo si hace falta, con un commit inicial, y añade este remoto">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="3" x2="6" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg>
                Conectar repositorio
              </button>
            </form>
          </div>
        <?php endif; ?>

        <div class="card">
          <div class="row" style="margin-bottom:10px">
            <div style="font-weight:600">Log de errores de este proyecto</div>
            <span class="muted" style="font-size:12px"><?= e($pName) ?>-error.log</span>
            <div class="spacer"></div>
            <a class="btn ghost sm" href="?tab=logs&log=<?= urlencode($pName.'-error.log') ?>">Ver completo</a>
            <?php if ($pErrLog!==''): ?>
              <button type="button" class="btn ghost sm" onclick="luaAskClearProjLog()">Vaciar</button>
            <?php endif; ?>
          </div>
          <?php if ($pErrLog===''): ?>
            <div class="muted">Sin errores recientes.</div>
          <?php else: ?>
            <pre class="logview"><?= highlight_error_log($pErrLog) ?></pre>
          <?php endif; ?>
        </div>
      </div>

      <!-- Modal de confirmacion de vaciado del log de errores de este proyecto -->
      <div id="clearProjLogModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseClearProjLog()">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="clearProjLogTitle">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
              <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
          </div>
          <h3 id="clearProjLogTitle">¿Vaciar el log?</h3>
          <p class="modal-tx">Se borrará todo el contenido de <strong><?= e($pName) ?>-error.log</strong>. Esto no se puede deshacer.</p>
          <form method="post" class="modal-actions">
            <input type="hidden" name="action" value="clearlog">
            <input type="hidden" name="log" value="<?= e($pName.'-error.log') ?>">
            <input type="hidden" name="back" value="?tab=proyecto&name=<?= e(rawurlencode($pName)) ?>">
            <button type="button" class="btn ghost" onclick="luaCloseClearProjLog()">Cancelar</button>
            <button type="submit" class="btn danger">Sí, vaciar</button>
          </form>
        </div>
      </div>
      <script>
        function luaAskClearProjLog(){
          document.getElementById('clearProjLogModal').hidden = false;
          document.addEventListener('keydown', luaEscClearProjLog);
        }
        function luaCloseClearProjLog(){
          document.getElementById('clearProjLogModal').hidden = true;
          document.removeEventListener('keydown', luaEscClearProjLog);
        }
        function luaEscClearProjLog(e){ if(e.key==='Escape') luaCloseClearProjLog(); }
      </script>

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

      <?php $pTermOn = term_enabled($ROOT); ?>
      <?php if ($pTermOn && is_dir($pDir)): ?>
        <div class="card">
          <div style="font-weight:600;margin-bottom:10px">Terminal <span class="muted" style="font-weight:400;font-size:12px">— arranca ya en la carpeta de este proyecto</span></div>
          <?= render_terminal_widget('pterm', $pDir, false) ?>
        </div>
      <?php elseif (!$pTermOn): ?>
        <div class="card">
          <div style="font-weight:600;margin-bottom:6px">Terminal</div>
          <div class="muted">Actívala en <a href="?tab=config">Configuración del servidor</a> para ejecutar comandos aquí mismo, arrancando directamente en la carpeta de este proyecto.</div>
        </div>
      <?php endif; ?>

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
          var modal=null, titleEl=null, host=null, area=null, status=null, saveBtn=null, cm=null, curName=null, curRel=null, curEnc='UTF-8';

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
              body:'action=file_write&name='+encodeURIComponent(curName)+'&rel='+encodeURIComponent(curRel)+'&enc='+encodeURIComponent(curEnc)+'&content='+encodeURIComponent(cm.getValue())})
              .then(function(r){ return r.json(); })
              .then(function(j){ status.textContent = j.error ? j.error : 'Guardado.'; })
              .catch(function(){ status.textContent = 'Error de red al guardar.'; });
          }
          window.luaOpenFileEditor = function(name, rel, label){
            if (!modal) init();
            curName = name; curRel = rel; curEnc = 'UTF-8';
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
                  curEnc = j.enc || 'UTF-8';
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

          <?php $extraList = extra_extensions($ROOT); sort($extraList); ?>
          <div class="row" style="margin-bottom:4px">
            <span style="font-weight:600">Extensiones adicionales</span>
          </div>
          <?php if ($extraList): ?>
            <div style="margin-bottom:10px">
              <?php foreach ($extraList as $en): $edll = is_file($PHP_BASE.'/'.$v.'/ext/php_'.$en.'.dll'); ?>
                <div class="row" style="gap:8px;margin-bottom:4px">
                  <code><?= e($en) ?></code>
                  <span class="jstate <?= $edll?'ok':'err' ?>"><?= $edll?'INSTALADA':'NO INSTALADA' ?></span>
                  <div class="spacer"></div>
                  <?php if ($edll): ?><button type="button" class="btn danger sm" onclick="luaAskDelExt('<?= e($v) ?>','<?= e($en) ?>')">Quitar</button><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data" class="row" style="gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px">
            <input type="hidden" name="action" value="phpext_add">
            <input type="hidden" name="ver" value="<?= e($v) ?>">
            <div>
              <label>Nombre <span class="muted">(p.ej. pdo_sqlsrv)</span></label>
              <input name="name" placeholder="pdo_sqlsrv" pattern="[a-z][a-z0-9_]*" required>
            </div>
            <div>
              <label>URL directa al .dll <span class="muted">(opcional si subes archivo)</span></label>
              <input name="url" type="url" placeholder="https://…/php_xxx.dll" style="min-width:260px">
            </div>
            <div>
              <label>o sube el .dll</label>
              <div class="row" style="gap:6px">
                <input type="file" name="dll" id="dllInput-<?= e($v) ?>" accept=".dll" hidden onchange="document.getElementById('dllName-<?= e($v) ?>').textContent = this.files[0] ? this.files[0].name : 'Ningún archivo'">
                <button type="button" class="btn ghost sm" onclick="document.getElementById('dllInput-<?= e($v) ?>').click()">Elegir archivo</button>
                <span id="dllName-<?= e($v) ?>" class="muted" style="font-size:12px">Ningún archivo</span>
              </div>
            </div>
            <button class="btn sm" type="submit">Añadir extensión</button>
          </form>

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
                  <?php elseif ($meta['type']==='select'):
                    $curNorm = preg_replace('/\s+/', '', $cur);
                    $known = false;
                    foreach ($meta['options'] as $optVal=>$optLabel) { if ($curNorm !== '' && preg_replace('/\s+/', '', $optVal) === $curNorm) { $known = true; break; } } ?>
                    <select name="ini[<?= e($k) ?>]" style="width:100%">
                      <option value="">(por defecto de PHP)</option>
                      <?php foreach ($meta['options'] as $optVal=>$optLabel):
                        $match = $curNorm !== '' && preg_replace('/\s+/', '', $optVal) === $curNorm; ?>
                        <option value="<?= e($optVal) ?>" <?= $match?'selected':'' ?>><?= e($optLabel) ?></option>
                      <?php endforeach; ?>
                      <?php if ($cur !== '' && !$known): ?>
                        <option value="<?= e($cur) ?>" selected>Personalizado: <?= e($cur) ?></option>
                      <?php endif; ?>
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

    <!-- Modal de confirmacion para quitar una extension PHP de terceros -->
    <div id="delExtModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDelExt()">
      <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="delExtTitle">
        <div class="modal-ic">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
        </div>
        <h3 id="delExtTitle">¿Quitar extensión?</h3>
        <p class="modal-tx">Se borrará <strong id="delExtName"></strong> de PHP <strong id="delExtVer"></strong> y se aplicarán los cambios.</p>
        <form method="post" class="modal-actions">
          <input type="hidden" name="action" value="phpext_remove">
          <input type="hidden" name="ver" id="delExtVerInput">
          <input type="hidden" name="name" id="delExtNameInput">
          <button type="button" class="btn ghost" onclick="luaCloseDelExt()">Cancelar</button>
          <button type="submit" class="btn danger">Sí, quitar</button>
        </form>
      </div>
    </div>
    <script>
      function luaAskDelExt(ver, name){
        document.getElementById('delExtName').textContent = name;
        document.getElementById('delExtVer').textContent = ver;
        document.getElementById('delExtVerInput').value = ver;
        document.getElementById('delExtNameInput').value = name;
        document.getElementById('delExtModal').hidden = false;
        document.addEventListener('keydown', luaEscDelExt);
      }
      function luaCloseDelExt(){
        document.getElementById('delExtModal').hidden = true;
        document.removeEventListener('keydown', luaEscDelExt);
      }
      function luaEscDelExt(e){ if(e.key==='Escape') luaCloseDelExt(); }
    </script>

  <?php elseif ($tab==='logs'): /* ---------- PESTAÑA LOGS ---------- */
      $logDir = $ROOT.'/logs/apache';
      $logFiles = [];
      foreach (glob($logDir.'/*.log') as $f) $logFiles[] = basename($f);
      sort($logFiles);
      $byProject = logs_group_by_project($logFiles);
      $projects = array_keys($byProject);

      // Se guarda si la URL traia ?project=/?log= ANTES de invalidarlos: hace falta para
      // distinguir mas abajo "primera visita a la pestana" de "me pedian un proyecto que
      // se ha quedado sin archivos" (p.ej. tras borrar su ultimo .log) -- de lo contrario
      // ambos casos se veian iguales (sel==='' && selProject==='') y el segundo saltaba
      // por sorpresa a los logs de (sistema) en vez de quedarse en el estado vacio.
      $hadLogParam     = isset($_GET['log']) && $_GET['log'] !== '';
      $hadProjectParam = isset($_GET['project']) && $_GET['project'] !== '';
      $sel = safe_logname($_GET['log'] ?? '');
      if ($sel !== '' && !in_array($sel, $logFiles, true)) $sel = '';
      $selProject = (string)($_GET['project'] ?? '');
      if ($sel !== '') {
          // el archivo manda: derivar su proyecto real, por si la URL trae uno inconsistente
          foreach ($byProject as $p => $files) {
              foreach ($files as $f) { if ($f['file'] === $sel) { $selProject = $p; break 2; } }
          }
      } elseif ($selProject === '' || !isset($byProject[$selProject])) {
          $selProject = '';
      }
      // Sin ?project= ni ?log= (primera visita a la pestaña): mismo valor por defecto de
      // siempre (error.log de sistema), solo que ahora expresado en el modelo proyecto+archivo.
      if ($sel === '' && $selProject === '' && $byProject && !$hadLogParam && !$hadProjectParam) {
          $selProject = isset($byProject['(sistema)']) ? '(sistema)' : $projects[0];
          foreach ($byProject[$selProject] as $f) { if ($f['kind']==='error') { $sel = $f['file']; break; } }
          if ($sel === '' && isset($byProject[$selProject][0])) $sel = $byProject[$selProject][0]['file'];
      }

      $refresh = (($_GET['refresh']??'')==='1');
      $content = $sel !== '' ? tail_file($logDir.'/'.$sel, 300) : '';
  ?>
    <div class="row" style="margin-bottom:14px;gap:8px 16px;flex-wrap:wrap;align-items:flex-start">
      <div class="logpicker">
        <input type="text" id="logProjectInput" class="logpicker-input" autocomplete="off" spellcheck="false"
               placeholder="Buscar proyecto&hellip; (<?= count($projects) ?>)" value="<?= $selProject!==''?e(log_project_label($selProject)):'' ?>">
        <div id="logProjectList" class="logpicker-list" hidden></div>
      </div>
      <div class="row" id="logFileLinks" style="gap:8px;flex-wrap:wrap;align-items:center;display:<?= $selProject===''?'none':'flex' ?>">
        <?php foreach (($byProject[$selProject] ?? []) as $i => $f): ?>
          <?php if ($i > 0): ?><span class="loglink-sep">&middot;</span><?php endif; ?>
          <a href="?tab=logs&project=<?= urlencode($selProject) ?>&log=<?= urlencode($f['file']) ?><?= $refresh?'&refresh=1':'' ?>"
             class="loglink<?= $f['file']===$sel?' active':'' ?>"><?= e(log_kind_label($f['kind'])) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="spacer"></div>
      <div id="logActions" style="display:<?= $sel===''?'none':'flex' ?>;gap:8px;align-items:center">
        <a id="logAutoRefreshLink" href="?tab=logs&project=<?= urlencode($selProject) ?>&log=<?= urlencode($sel) ?><?= $refresh?'':'&refresh=1' ?>" class="btn ghost sm"><?= $refresh?'Auto-refresco ON':'Auto-refresco' ?></a>
        <button type="button" class="btn ghost sm" id="logClearBtn">Vaciar</button>
        <button type="button" class="btn danger sm" id="logDeleteBtn">Eliminar</button>
        <span id="logActionStatus" class="muted" style="font-size:12px"></span>
      </div>
    </div>
    <div id="logEmptyCard" class="card muted" style="<?= $sel!==''?'display:none':'' ?>">Elige un proyecto y luego un archivo de log para ver su contenido.</div>
    <pre class="logview" id="logViewPre" style="<?= $sel===''?'display:none':'' ?>"><?= $content!=='' ? highlight_error_log($content) : '(vac&iacute;o)' ?></pre>

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
        <div class="modal-actions">
          <button type="button" class="btn ghost" onclick="luaCloseDeleteLog()">Cancelar</button>
          <button type="button" class="btn danger" id="delLogConfirm">Sí, eliminar</button>
        </div>
      </div>
    </div>

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
        <div class="modal-actions">
          <button type="button" class="btn ghost" onclick="luaCloseClearLog()">Cancelar</button>
          <button type="button" class="btn danger" id="clearLogConfirm">Sí, vaciar</button>
        </div>
      </div>
    </div>

    <script>
      (function(){
        var PROJECTS=<?= json_encode(array_map(function($p){ return ['key'=>$p,'label'=>log_project_label($p)]; }, $projects), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
        var curProject=<?= json_encode($selProject) ?>, curLog=<?= json_encode($sel) ?>, REFRESH=<?= $refresh?'true':'false' ?>;
        var inp=document.getElementById('logProjectInput'), list=document.getElementById('logProjectList');
        var linksRow=document.getElementById('logFileLinks');
        var actionsBox=document.getElementById('logActions');
        var emptyCard=document.getElementById('logEmptyCard');
        var viewPre=document.getElementById('logViewPre');
        var autoLink=document.getElementById('logAutoRefreshLink');
        var statusEl=document.getElementById('logActionStatus');
        var items=[], active=-1;
        function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
        function projectLabel(key){
          for (var i=0;i<PROJECTS.length;i++){ if (PROJECTS[i].key===key) return PROJECTS[i].label; }
          return key;
        }
        function go(key){ location.href='?tab=logs&project='+encodeURIComponent(key)+(REFRESH?'&refresh=1':''); }
        function render(filter){
          var f=(filter||'').toLowerCase();
          items = PROJECTS.filter(function(p){ return p.label.toLowerCase().indexOf(f)!==-1; });
          active=-1;
          if(!items.length){ list.innerHTML='<div class="logpicker-empty">Sin coincidencias</div>'; list.hidden=false; return; }
          list.innerHTML = items.map(function(p){
            return '<div class="logpicker-opt'+(p.key===curProject?' sel':'')+'" data-key="'+esc(p.key)+'">'+esc(p.label)+'</div>';
          }).join('');
          list.hidden=false;
        }
        function highlight(i){
          Array.from(list.querySelectorAll('.logpicker-opt')).forEach(function(el,j){ el.classList.toggle('on', j===i); });
          active=i;
          var el=list.children[i]; if(el && el.scrollIntoView) el.scrollIntoView({block:'nearest'});
        }
        inp.addEventListener('focus', function(){ render(''); inp.select(); });
        inp.addEventListener('input', function(){ render(inp.value); });
        inp.addEventListener('keydown', function(e){
          if(list.hidden){ if(e.key==='ArrowDown'||e.key==='ArrowUp'){ render(''); e.preventDefault(); } return; }
          if(e.key==='ArrowDown'){ e.preventDefault(); highlight(Math.min(active+1, items.length-1)); }
          else if(e.key==='ArrowUp'){ e.preventDefault(); highlight(Math.max(active-1,0)); }
          else if(e.key==='Enter'){ e.preventDefault(); if(items[active]) go(items[active].key); else if(items.length===1) go(items[0].key); }
          else if(e.key==='Escape'){ list.hidden=true; inp.value=projectLabel(curProject); inp.blur(); }
        });
        list.addEventListener('mousedown', function(e){
          var opt=e.target.closest('.logpicker-opt'); if(!opt||!opt.dataset.key) return;
          go(opt.dataset.key);
        });
        document.addEventListener('click', function(e){ if(!e.target.closest('.logpicker')){ list.hidden=true; inp.value=projectLabel(curProject); } });

        // ---- Borrar/vaciar sin recargar: la seleccion la decide el cliente con lo que el
        // servidor devuelve en la respuesta, no una heuristica de redirect adivinando que
        // proyecto/archivo tocaria despues (eso era lo que perdia la seleccion al borrar).
        function renderLinks(files){
          linksRow.innerHTML = files.map(function(f,i){
            var sep = i>0 ? '<span class="loglink-sep">&middot;</span>' : '';
            var active = f.file===curLog ? ' active' : '';
            return sep+'<a href="?tab=logs&project='+encodeURIComponent(curProject)+'&log='+encodeURIComponent(f.file)+'" class="loglink'+active+'">'+esc(f.label)+'</a>';
          }).join('');
          linksRow.style.display = files.length ? 'flex' : 'none';
        }
        function selectLog(file, content){
          curLog = file;
          if (file === '') {
            actionsBox.style.display = 'none';
            emptyCard.style.display = '';
            viewPre.style.display = 'none';
          } else {
            actionsBox.style.display = 'flex';
            emptyCard.style.display = 'none';
            viewPre.style.display = '';
            viewPre.innerHTML = content !== '' ? content : '(vacío)';
            autoLink.href = '?tab=logs&project='+encodeURIComponent(curProject)+'&log='+encodeURIComponent(file)+(REFRESH?'&refresh=1':'');
          }
          var qs = '?tab=logs&project='+encodeURIComponent(curProject)+(file!==''?'&log='+encodeURIComponent(file):'');
          history.replaceState(null, '', qs);
        }
        function flash(msg){
          statusEl.textContent = msg;
          setTimeout(function(){ if (statusEl.textContent===msg) statusEl.textContent=''; }, 2500);
        }
        function post(op, log){
          return fetch('?', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'ajax='+op+'&log='+encodeURIComponent(log)}).then(function(r){ return r.json(); });
        }
        document.getElementById('delLogConfirm').addEventListener('click', function(){
          var log = curLog;
          luaCloseDeleteLog();
          post('logdelete', log).then(function(j){
            if (j.error) { flash(j.error); return; }
            renderLinks(j.files);
            selectLog(j.next, j.content);
            if (!j.files.length) {
              PROJECTS = PROJECTS.filter(function(p){ return p.key !== curProject; });
              curProject = '';
              inp.value = '';
              inp.placeholder = 'Buscar proyecto… (' + PROJECTS.length + ')';
              history.replaceState(null, '', '?tab=logs');
            }
            flash('Eliminado.');
          }).catch(function(){ flash('Error de red al eliminar.'); });
        });
        document.getElementById('clearLogConfirm').addEventListener('click', function(){
          var log = curLog;
          luaCloseClearLog();
          post('logclear', log).then(function(j){
            if (j.error) { flash(j.error); return; }
            if (log === curLog) { viewPre.innerHTML = '(vacío)'; }
            flash('Vaciado.');
          }).catch(function(){ flash('Error de red al vaciar.'); });
        });
        document.getElementById('logDeleteBtn').addEventListener('click', function(){ luaAskDeleteLog(curLog); });
        document.getElementById('logClearBtn').addEventListener('click', function(){ luaAskClearLog(curLog); });
      })();

      function luaAskDeleteLog(name){
        document.getElementById('delLogName').textContent = name;
        document.getElementById('delLogModal').hidden = false;
        document.addEventListener('keydown', luaEscDeleteLog);
      }
      function luaCloseDeleteLog(){
        document.getElementById('delLogModal').hidden = true;
        document.removeEventListener('keydown', luaEscDeleteLog);
      }
      function luaEscDeleteLog(e){ if(e.key==='Escape') luaCloseDeleteLog(); }

      function luaAskClearLog(name){
        document.getElementById('clearLogName').textContent = name;
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

    <?php
      $updErr    = $updSt['error'] ?? null;
      $updSucio  = !empty($updSt['sucio']);
      $updDelant = (int)($updSt['delante'] ?? 0);
      $updCuando = !empty($updSt['comprobado']) ? @strtotime($updSt['comprobado']) : 0;
    ?>
    <div class="cfg3">

      <div class="card cardsave" id="actualizaciones">
        <button type="button" class="savebtn" data-form="updCfgForm" title="Guardar">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
          </svg>
        </button>
        <div class="cfg3-body">
          <div class="cardsave-title" style="font-weight:600;margin-bottom:4px">Actualizaciones de la plataforma</div>
          <div class="muted" style="margin-bottom:12px">
            Versión instalada: <code><?= $luaVer !== '' ? e($luaVer) : 'desconocida' ?></code>
            <?php if ($updCuando): ?> &middot; comprobado <?= e(date('d/m/Y H:i', $updCuando)) ?><?php endif; ?>
          </div>

          <?php if ($updSt === null): ?>
            <div class="muted" style="font-size:12.5px">Aún no se ha comprobado. El watcher lo hace solo al arrancar y cada <?= (int)$updCfg['cada_horas'] ?> h.</div>
          <?php elseif ($updErr): ?>
            <div class="msgtext err">No se pudo consultar el repositorio: <?= e((string)$updErr) ?></div>
            <div class="muted" style="font-size:12px">El <code>fetch</code> lo hace el watcher con tus claves SSH. Si falla, comprueba que <code>git fetch</code> funciona a mano en esta carpeta.</div>
          <?php elseif ($updHay): ?>
            <div class="msgtext warn">Hay <?= (int)$updDetras ?> actualización(es) disponible(s) en <code><?= e((string)($updSt['remoto'] ?? 'origin')) ?></code>.</div>
            <?php if (!empty($updSt['mensaje'])): ?><pre class="joblog" style="margin-top:0"><?= e((string)$updSt['mensaje']) ?></pre><?php endif; ?>
          <?php else: ?>
            <div class="msgtext ok">Estás en la última versión.</div>
          <?php endif; ?>

          <?php if ($updSucio): ?>
            <div class="msgtext warn">Hay cambios locales sin confirmar en la carpeta de la plataforma. <b>No se actualizará automáticamente</b> para no pisarlos: confírmalos o descártalos primero.</div>
          <?php endif; ?>
          <?php if ($updDelant > 0): ?>
            <div class="msgtext warn">Tu copia va <?= (int)$updDelant ?> commit(s) por delante del remoto. La actualización automática se salta este caso para no decidir por ti cómo integrarlos.</div>
          <?php endif; ?>

          <details class="extform" style="margin-top:8px">
            <summary style="padding:0;font-size:12.5px;font-weight:400;color:var(--mut)">Actualizaciones automáticas</summary>
            <div style="padding-top:10px">
              <form method="post" id="updCfgForm">
                <input type="hidden" name="action" value="update_cfg">
                <label>Actualizaciones automáticas</label>
                <select name="auto">
                  <option value="0" <?= $updCfg['auto'] ? '' : 'selected' ?>>Solo avisar</option>
                  <option value="1" <?= $updCfg['auto'] ? 'selected' : '' ?>>Instalar automáticamente</option>
                </select>
                <label style="margin-top:8px">Comprobar cada</label>
                <select name="cada_horas">
                  <?php foreach ([1,3,6,12,24,72,168] as $h): ?>
                    <option value="<?= $h ?>" <?= (int)$updCfg['cada_horas']===$h ? 'selected' : '' ?>><?= $h < 24 ? $h.' h' : ($h/24).' día(s)' ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
              <div class="muted" style="margin-top:10px;font-size:11px">
                Se actualiza con <code>git merge --ff-only</code> desde <code>origin</code>, sin tocar tu configuración de esta máquina (<code>sites.json</code>, contraseñas, conexiones, <code>www\</code>).
              </div>
            </div>
          </details>
        </div>
        <div class="cfg3-actions">
          <form method="post" style="display:inline"><input type="hidden" name="action" value="update_check">
            <button class="btn ghost sm" type="submit">Buscar ahora</button></form>
          <?php if ($updHay && !$updSucio && $updDelant === 0): ?>
            <form method="post" style="display:inline"><input type="hidden" name="action" value="update_now">
              <button class="btn sm" type="submit">Actualizar a la última</button></form>
          <?php endif; ?>
        </div>
      </div>

      <div class="card cardsave">
        <button type="button" class="savebtn" data-form="brandNameForm" title="Guardar nombre">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
          </svg>
        </button>
        <div class="cfg3-body">
          <div class="cardsave-title" style="font-weight:600;margin-bottom:4px">Identidad de la plataforma</div>
          <div class="muted" style="margin-bottom:12px">Nombre y logo que aparecen en la cabecera, la pestaña del navegador y el pie. Solo afecta a este panel.</div>
          <form method="post" id="brandNameForm">
            <input type="hidden" name="action" value="set_brand">
            <label>Nombre</label>
            <input name="brand_name" value="<?= e($cfg['brand']['name'] ?? '') ?>" placeholder="lua-server" maxlength="40" style="width:100%">
          </form>
          <div class="muted" style="margin-top:6px;font-size:11px">Vacío = <code>lua-server</code>.</div>
          <div style="display:flex;align-items:center;gap:12px;margin-top:14px">
            <div class="logo" style="width:44px;height:44px;flex:0 0 auto;border:1px solid var(--line);border-radius:10px;padding:5px;background:var(--in)">
              <img src="<?= $brandLogo ? '?brandlogo&t='.filemtime($brandLogo) : 'assets/logo.svg' ?>" alt="logo" style="width:100%;height:100%;object-fit:contain">
            </div>
            <div>
              <form method="post" enctype="multipart/form-data" style="display:inline">
                <input type="hidden" name="action" value="brand_logo">
                <input type="file" name="img" accept="image/*" hidden onchange="this.form.requestSubmit()">
                <button type="button" class="btn ghost sm" onclick="this.parentNode.querySelector('input[type=file]').click()">Cambiar logo</button>
              </form>
              <?php if ($brandLogo): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="brand_logo_reset">
                <button class="btn ghost sm" type="submit">Restablecer</button>
              </form>
              <?php endif; ?>
              <div class="muted" style="margin-top:6px;font-size:11px">PNG, SVG, JPG… máx. 5 MB</div>
            </div>
          </div>
        </div>
      </div>

      <div class="card cardsave">
        <button type="button" class="savebtn" data-form="tldForm" title="Guardar dominio">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
          </svg>
        </button>
        <div class="cfg3-body">
          <div class="cardsave-title" style="font-weight:600;margin-bottom:4px">Dominio local</div>
          <div class="muted" style="margin-bottom:12px">Tus proyectos se sirven en <code>&lt;nombre&gt;.<?= e($tld) ?></code>. Recomendado <code>test</code> (reservado para pruebas). Evita <code>dev</code> (Chrome fuerza HTTPS) y <code>local</code> (lo usa mDNS de Windows).</div>
          <form method="post" id="tldForm">
            <input type="hidden" name="action" value="set_tld">
            <label>Dominio (TLD)</label>
            <input name="tld" value="<?= e($tld) ?>" placeholder="test" style="width:100%">
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Dominios <code>.<?= e($tld) ?></code> en el navegador</div>
          <div class="muted">Para que <code>&lt;nombre&gt;.<?= e($tld) ?></code> abra en el navegador hay que registrarlos en Windows (una vez). Si <code>localhost</code> te carga otra cosa (p. ej. Docker/Portainer por IPv6), usa <code><?= e($tld) ?></code> a secas: siempre te trae aquí.</div>
        </div>
        <div class="cfg3-actions">
          <form method="post">
            <input type="hidden" name="action" value="hosts">
            <button class="btn ghost" type="submit">Sincronizar dominios</button>
          </form>
        </div>
      </div>

    </div>

    <?php
      $httpsOn  = is_file($ROOT.'/config/https.on') && is_file($ROOT.'/data/ssl/lua.pem');
      $lanOn    = is_file($ROOT.'/config/lanexpose.on');
      $mailOn   = is_file($ROOT.'/config/mailpit.on');
      $mariaOn  = is_file($ROOT.'/config/mariadb.on');
      $pgOn     = is_file($ROOT.'/config/postgres.on');
      // Badges derivados del proceso real (no solo del flag): un flag huerfano no miente.
      [$mailCls,$mailLbl]   = svc_status($mailOn, 1025);
      [$mariaCls,$mariaLbl] = svc_status($mariaOn, 3306);
      [$pgCls,$pgLbl]       = svc_status($pgOn, 5432);
      $mongoOn  = is_file($ROOT.'/config/mongodb.on');
      $redisOn  = is_file($ROOT.'/config/redis.on');
      [$redisCls,$redisLbl] = svc_status($redisOn, 6379);
      // Build instalado ('redis8' / 'native5') y en que versiones de PHP quedo la extension: las
      // dos cosas se muestran en la card porque el motor puede estar arriba y aun asi faltarle la
      // extension a alguna version (son pasos independientes del job).
      $redisBuild = trim((string)@file_get_contents($ROOT.'/config/redis/build.txt'));
      $redisExtVers = [];
      foreach ($vers as $rv) { if (is_file($PHP_BASE.'/'.$rv.'/ext/php_redis.dll')) $redisExtVers[] = $rv; }
      $termOn   = is_file($ROOT.'/config/terminal.on');
      $startupOn= startup_enabled($ROOT);
      $lanIps = array_values(array_filter(array_map('trim', explode(',', (string)@file_get_contents($ROOT.'/config/lan-ip.txt'))),
                    function($x){ return $x!=='' && filter_var($x, FILTER_VALIDATE_IP); }));
      if (!$lanIps) {
          // Respaldo si el watcher aun no ha escrito las IPs: resolver el hostname (sin subproceso).
          $guess = @gethostbyname(@php_uname('n'));
          if ($guess && filter_var($guess, FILTER_VALIDATE_IP) && strpos($guess,'127.')!==0) $lanIps = [$guess];
      }
      $lanIp0 = $lanIps[0] ?? '<tu-IP-LAN>';
    ?>
    <div class="cfg3">

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">HTTPS local <span class="jstate <?= $httpsOn?'ok':'err' ?>"><?= $httpsOn?'ACTIVO':'INACTIVO' ?></span></div>
          <div class="muted">Certificados de confianza para <code>https://&lt;proyecto&gt;.<?= e($tld) ?></code> (candado verde). Al activar, Windows pedirá permiso para instalar la CA (una vez).</div>
        </div>
        <div class="cfg3-actions">
          <form method="post">
            <input type="hidden" name="action" value="https">
            <input type="hidden" name="enable" value="<?= $httpsOn?'0':'1' ?>">
            <button class="btn <?= $httpsOn?'danger':'ghost' ?>" type="submit"><?= $httpsOn?'Desactivar':'Activar' ?> HTTPS</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Exponer en la red local (LAN) <span class="jstate <?= $lanOn?'ok':'err' ?>"><?= $lanOn?'ACTIVO':'INACTIVO' ?></span></div>
          <div class="muted">Abre el puerto <?= $httpsOn?'80/443':'80' ?> en el Firewall de Windows (solo para tu subred local) para que otros dispositivos de tu misma red/WiFi puedan abrir tus proyectos. Al activar, Windows pedirá permiso (UAC). El panel de administración sigue restringido a esta máquina.</div>
          <?php if ($lanOn && $lanIps): ?>
            <div class="muted" style="margin-top:8px;font-size:12px">Tu IP en la red local: <?php foreach ($lanIps as $i=>$ip): ?><code><?= e($ip) ?></code><?= $i<count($lanIps)-1?' · ':'' ?><?php endforeach; ?>. Desde otro equipo, añade a <em>su</em> <code>hosts</code>:<br>
              <code><?= e($lanIp0) ?>&nbsp;&nbsp;&lt;proyecto&gt;.<?= e($tld) ?></code></div>
          <?php endif; ?>
        </div>
        <div class="cfg3-actions">
          <form method="post">
            <input type="hidden" name="action" value="lanexpose">
            <input type="hidden" name="enable" value="<?= $lanOn?'0':'1' ?>">
            <button class="btn <?= $lanOn?'danger':'ghost' ?>" type="submit"><?= $lanOn?'Dejar de exponer':'Exponer' ?></button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Mailpit <span class="jstate <?= $mailCls ?>"><?= $mailLbl ?></span></div>
          <div class="muted">Atrapa los emails que envían tus proyectos PHP (SMTP <code>127.0.0.1:1025</code>) y los muestra en un buzón web. No salen a internet.</div>
        </div>
        <div class="cfg3-actions">
          <?php if ($mailOn): ?><a class="btn ghost" href="http://localhost:8025" target="_blank">Abrir buzón &#8599;</a><?php endif; ?>
          <form method="post">
            <input type="hidden" name="action" value="mailpit">
            <input type="hidden" name="enable" value="<?= $mailOn?'0':'1' ?>">
            <button class="btn <?= $mailOn?'danger':'ghost' ?>" type="submit"><?= $mailOn?'Desactivar':'Activar' ?> Mailpit</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Servidor MySQL (MariaDB) <span class="jstate <?= $mariaCls ?>"><?= $mariaLbl ?></span></div>
          <div class="muted">Nativo (MariaDB 11.8 LTS) en <code>127.0.0.1:3306</code>, usuario <code>root</code> <?= mysql_root_pass($ROOT)!==''?'con contraseña':'sin contraseña' ?>. Solo accesible desde esta máquina. Gestiona <code>root</code> y crea usuarios en <a href="?tab=bd">Bases de datos</a>.</div>
        </div>
        <div class="cfg3-actions">
          <?php if ($mariaOn): ?><a class="btn ghost" href="?tab=bd">Bases de datos</a> <a class="btn ghost" href="http://<?= e($phpmyadminDom) ?>/" target="_blank">phpMyAdmin &#8599;</a> <a class="btn ghost" href="/adminer.php?server=127.0.0.1&username=root" target="_blank">Adminer &#8599;</a><?php endif; ?>
          <form method="post">
            <input type="hidden" name="action" value="mariadb">
            <input type="hidden" name="enable" value="<?= $mariaOn?'0':'1' ?>">
            <button class="btn <?= $mariaOn?'danger':'ghost' ?>" type="submit"><?= $mariaOn?'Desactivar':'Activar' ?> MySQL</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Servidor PostgreSQL <span class="jstate <?= $pgCls ?>"><?= $pgLbl ?></span></div>
          <div class="muted">Nativo (PostgreSQL 16) en <code>127.0.0.1:5432</code>, usuario <code>postgres</code> sin contraseña. Solo accesible desde esta máquina. Crea bases de datos y roles en <a href="?tab=bd&engine=pg">Bases de datos</a>.</div>
        </div>
        <div class="cfg3-actions">
          <?php if ($pgOn): ?><a class="btn ghost" href="?tab=bd&engine=pg">Bases de datos</a> <a class="btn ghost" href="/adminer.php?pgsql=127.0.0.1&username=postgres&db=postgres" target="_blank">Adminer &#8599;</a><?php endif; ?>
          <form method="post">
            <input type="hidden" name="action" value="postgres">
            <input type="hidden" name="enable" value="<?= $pgOn?'0':'1' ?>">
            <button class="btn <?= $pgOn?'danger':'ghost' ?>" type="submit"><?= $pgOn?'Desactivar':'Activar' ?> PostgreSQL</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Servidor MongoDB <span class="jstate <?= $mongoOn?'ok':'err' ?>"><?= $mongoOn?'ACTIVO':'INACTIVO' ?></span></div>
          <div class="muted">Nativo (MongoDB Community) en <code>127.0.0.1:27017</code>, sin autenticación. Solo accesible desde esta máquina. Gestión visual vía <code>mongo-express</code> (Node.js, se instala junto al motor).</div>
        </div>
        <div class="cfg3-actions">
          <?php if ($mongoOn): ?><a class="btn ghost" href="http://127.0.0.1:8081/" target="_blank">mongo-express &#8599;</a><?php endif; ?>
          <form method="post">
            <input type="hidden" name="action" value="mongodb">
            <input type="hidden" name="enable" value="<?= $mongoOn?'0':'1' ?>">
            <button class="btn <?= $mongoOn?'danger':'ghost' ?>" type="submit"><?= $mongoOn?'Desactivar':'Activar' ?> MongoDB</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Servidor Redis <span class="jstate <?= $redisCls ?>"><?= $redisLbl ?></span></div>
          <div class="muted">Almacén en memoria (caché, sesiones, colas) en <code>127.0.0.1:6379</code>, sin contraseña. Solo accesible desde esta máquina. Se instala también la extensión <code>php_redis</code> en cada versión de PHP.</div>
          <?php if ($redisOn || $redisBuild !== ''): ?>
            <div class="muted" style="margin-top:8px;font-size:11.5px">
              <?php if ($redisBuild !== ''): ?>
                Build: <code><?= $redisBuild==='native5' ? 'tporadowski 5.0.14.1 (nativo)' : 'redis-windows 8.8.1' ?></code><br>
              <?php endif; ?>
              <?php if ($redisExtVers): ?>
                Extensión en PHP <?= e(implode(', ', $redisExtVers)) ?>.
              <?php else: ?>
                <span class="msgtext warn" style="margin:0">La extensión <code>php_redis</code> aún no está en ninguna versión de PHP.</span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if (!$redisOn && $redisBuild === ''): ?>
            <div style="margin-top:12px">
              <label>Build de Redis para Windows</label>
              <select name="build" id="redisBuildSel">
                <option value="redis8">Redis 8.8.1 — al día, sobre capa msys2</option>
                <option value="native5">Redis 5.0.14.1 — nativo, congelado en 2022</option>
              </select>
              <div class="muted" style="margin-top:6px;font-size:11px">Redis no publica builds oficiales para Windows: ambas son ports de la comunidad. Solo se pregunta la primera vez.</div>
            </div>
          <?php endif; ?>
        </div>
        <div class="cfg3-actions">
          <form method="post">
            <input type="hidden" name="action" value="redis">
            <input type="hidden" name="enable" value="<?= $redisOn?'0':'1' ?>">
            <?php if (!$redisOn && $redisBuild === ''): ?>
              <!-- El <select> vive arriba, fuera de este <form>: se copia aqui al enviar para no
                   partir el layout de la card (cuerpo arriba, acciones abajo). -->
              <input type="hidden" name="build" id="redisBuildHidden" value="redis8">
            <?php endif; ?>
            <button class="btn <?= $redisOn?'danger':'ghost' ?>" type="submit"><?= $redisOn?'Desactivar':'Activar' ?> Redis</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Terminal <span class="jstate <?= $termOn?'ok':'err' ?>"><?= $termOn?'ACTIVA':'INACTIVA' ?></span></div>
          <div class="muted">Ejecuta comandos (composer, git, npm, artisan…) desde el navegador con la misma cuenta que Apache. Desactivada por defecto por seguridad: solo actívala si confías en quién tiene acceso a esta máquina.</div>
        </div>
        <div class="cfg3-actions">
          <form method="post">
            <input type="hidden" name="action" value="terminal">
            <input type="hidden" name="enable" value="<?= $termOn?'0':'1' ?>">
            <button class="btn <?= $termOn?'danger':'ghost' ?>" type="submit"><?= $termOn?'Desactivar':'Activar' ?> Terminal</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Arrancar con Windows <span class="jstate <?= $startupOn?'ok':'err' ?>"><?= $startupOn?'ACTIVO':'INACTIVO' ?></span></div>
          <div class="muted">Instala Apache como servicio de Windows (arranque automático) y el watcher como tarea programada (arranca sin necesidad de iniciar sesión). Al activar o desactivar, Windows pedirá permiso (UAC).</div>
        </div>
        <div class="cfg3-actions">
          <form method="post">
            <input type="hidden" name="action" value="startup">
            <input type="hidden" name="enable" value="<?= $startupOn?'0':'1' ?>">
            <button class="btn <?= $startupOn?'danger':'ghost' ?>" type="submit"><?= $startupOn?'Desactivar':'Activar' ?></button>
          </form>
        </div>
      </div>

    </div>

    <script>
      // El selector de build de Redis esta en el cuerpo de la card y el boton en su pie (dos
      // sitios distintos), asi que el valor se copia al campo oculto del form al cambiarlo.
      (function(){
        var sel = document.getElementById('redisBuildSel'), hid = document.getElementById('redisBuildHidden');
        if (sel && hid) { sel.addEventListener('change', function(){ hid.value = sel.value; }); }
      })();

      // Guardado de las cards de ajustes por el icono de disquete. Va por AJAX (el backend
      // responde JSON cuando recibe ajax=1, ver el hook junto al redirect de PRG) para poder
      // confirmar en verde sobre el propio icono sin recargar y perder el aviso.
      (function(){
        document.addEventListener('click', function(e){
          var btn = e.target.closest('.savebtn'); if (!btn) return;
          var form = document.getElementById(btn.dataset.form); if (!form) return;
          var card = btn.closest('.card');
          var out  = card.querySelector('.savemsg');
          if (!out) { out = document.createElement('div'); out.className = 'savemsg'; card.querySelector('.cfg3-body').appendChild(out); }
          var body = new URLSearchParams(new FormData(form)); body.set('ajax','1');
          btn.classList.remove('ok','err'); btn.disabled = true; out.textContent = '';
          fetch('?', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
            .then(function(r){ return r.json(); })
            .then(function(j){
              btn.disabled = false;
              btn.classList.add(j.ok ? 'ok' : 'err');
              out.className = 'savemsg ' + (j.ok ? 'ok' : 'err');
              out.textContent = j.msg || (j.ok ? 'Guardado.' : 'No se pudo guardar.');
              // El nombre de marca sale en la cabecera y en el titulo de la pestaña: se
              // refrescan aqui para que el cambio se vea sin tener que recargar a mano.
              // Ojo: el <h1> lleva detras la etiqueta de version (<a class="verchip">), asi
              // que se reescribe solo su primer nodo de texto, no el textContent entero.
              if (j.ok && form.id === 'brandNameForm') {
                var nuevo = (form.querySelector('[name=brand_name]').value || '').trim() || 'lua-server';
                var h = document.querySelector('header h1');
                if (h && h.firstChild && h.firstChild.nodeType === 3) { h.firstChild.nodeValue = nuevo; }
                var lg = document.querySelector('header .logo img'); if (lg) lg.alt = nuevo;
                document.title = nuevo;
              }
              if (j.ok && j.reload) { setTimeout(function(){ location.href = '?tab=config'; }, 1200); }
              else if (j.ok) { setTimeout(function(){ btn.classList.remove('ok'); out.textContent = ''; }, 4000); }
            })
            .catch(function(){
              btn.disabled = false; btn.classList.add('err');
              out.className = 'savemsg err'; out.textContent = 'Error de red al guardar.';
            });
        });
      })();
    </script>

  <?php elseif ($tab==='bd'): /* ---------- PESTAÑA BASES DE DATOS ---------- */
      $mariaOn = is_file($ROOT.'/config/mariadb.on');
      $pgOn    = is_file($ROOT.'/config/postgres.on');
      $mongoOn = is_file($ROOT.'/config/mongodb.on');
      $rootHasPass = mysql_root_pass($ROOT) !== '';
      $mysqlUsers = $mariaOn ? mysql_users() : null;
      $mysqlScopePdo = $mysqlUsers ? (function(){ try { return mysql_pdo(); } catch (Throwable $e) { return null; } })() : null;
      // Motor mostrado: ?engine=pg|mysql. Por defecto MySQL, salvo que solo Postgres este activo.
      $reqEngine = $_GET['engine'] ?? '';
      $dbEngine = $reqEngine==='pg' ? 'pg' : ($reqEngine==='mysql' ? 'mysql' : (($pgOn && !$mariaOn) ? 'pg' : 'mysql')); ?>

    <div class="row" style="gap:8px;margin-bottom:16px">
      <a class="btn <?= $dbEngine==='mysql'?'':'ghost' ?> sm" href="?tab=bd&engine=mysql">MySQL / MariaDB<?= $mariaOn?'':' · inactivo' ?></a>
      <a class="btn <?= $dbEngine==='pg'?'':'ghost' ?> sm" href="?tab=bd&engine=pg">PostgreSQL<?= $pgOn?'':' · inactivo' ?></a>
      <?php if ($mongoOn): ?>
        <a class="btn ghost sm" href="http://127.0.0.1:8081/" target="_blank">MongoDB (mongo-express &#8599;)</a>
      <?php else: ?>
        <a class="btn ghost sm" href="?tab=config">MongoDB · inactivo</a>
      <?php endif; ?>
      <?php
        // Redis no tiene flag de encendido que mirar (no gestionamos el motor): lo que cuenta es
        // si hay alguna conexion guardada, igual que en SQL Server.
        $rdConns = redis_servers($ROOT);
      ?>
      <a class="btn ghost sm" href="?tab=redis">Redis<?= $rdConns ? ' ('.count($rdConns).')' : ' · sin conexiones' ?></a>
      <a class="btn ghost sm" href="?tab=sqlsrv">SQL Server</a>
    </div>
    <?php if ($mongoOn): ?>
      <div class="muted" style="margin-bottom:16px;font-size:12px">MongoDB no usa SQL, así que no tiene un listado de bases de datos aquí: gestiónalo desde <b>mongo-express</b> (arriba).</div>
    <?php endif; ?>
    <div class="muted" style="margin-bottom:16px;font-size:12px">Redis tampoco usa SQL: se gestiona en su propia pestaña <a href="?tab=redis"><b>Redis</b></a> (explorador de claves, consola y estado del servidor).</div>

    <?php if ($dbEngine==='pg'): /* ===== PostgreSQL ===== */ ?>

      <?php if (!$pgOn): ?>
        <div class="card">
          <div style="font-weight:600;margin-bottom:6px">PostgreSQL está desactivado</div>
          <div class="muted">Actívalo desde <a href="?tab=config">Configuración del servidor</a> para gestionar bases de datos y roles. Se sirve en <code>127.0.0.1:5432</code>, usuario <code>postgres</code>, sin contraseña.</div>
        </div>
      <?php else:
          $pgReady = extension_loaded('pdo_pgsql');
          $pgDbList = $pgReady ? pgsrv_databases() : null;
          $pgRoles  = $pgReady ? pgsrv_roles() : null; ?>

        <div class="card row">
          <div>
            <div style="font-weight:600">Herramientas de administración</div>
            <div class="muted">Adminer (ya integrado) habla PostgreSQL de forma nativa: gestiona tablas, datos y consultas de forma visual.</div>
          </div>
          <div class="spacer"></div>
          <a class="btn ghost" href="/adminer.php?pgsql=127.0.0.1&username=postgres&db=postgres" target="_blank">Adminer &#8599;</a>
        </div>

        <?php if (!$pgReady): ?>
          <div class="card muted">La extensión <code>pdo_pgsql</code> de PHP aún no está activa. Se habilita al activar PostgreSQL por primera vez (o al reiniciar el servidor). Recarga en unos segundos.</div>
        <?php endif; ?>

        <div class="card">
          <div class="row" style="margin-bottom:12px">
            <h2 style="margin:0;font-size:15px">Bases de datos</h2>
            <div class="spacer"></div>
            <form method="post" class="row" style="gap:6px">
              <input type="hidden" name="action" value="pg_db_create">
              <input name="dbname" placeholder="nombre_basedatos" pattern="[a-zA-Z_][a-zA-Z0-9_]{0,62}" style="width:200px" required>
              <button class="btn ghost sm" type="submit">+ Crear BD</button>
            </form>
          </div>
          <?php if ($pgDbList === null): ?>
            <div class="muted">No se pudo conectar con PostgreSQL (¿acaba de activarse? espera unos segundos y recarga).</div>
          <?php elseif (!$pgDbList): ?>
            <div class="muted">No hay bases de datos todavía. Crea la primera arriba.</div>
          <?php else: foreach ($pgDbList as $db): ?>
            <div class="dbrow">
              <div class="dbname"><?= e($db) ?></div>
              <div class="spacer"></div>
              <div class="dbactions">
                <a class="btn ghost sm no-loader" href="?export_pg=<?= e(rawurlencode($db)) ?>">Exportar</a>
                <form method="post" enctype="multipart/form-data" class="dbimport row" style="gap:6px" onsubmit="return luaAskImportPg(event, this, '<?= e($db) ?>')">
                  <input type="hidden" name="action" value="pg_db_import">
                  <input type="hidden" name="dbname" value="<?= e($db) ?>">
                  <label class="filepick">
                    <input type="file" name="sqlfile" accept=".sql" required onchange="luaFilePickName(this)">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span class="filepick-name">Elegir .sql&hellip;</span>
                  </label>
                  <button class="btn ghost sm" type="submit">Importar</button>
                </form>
                <button type="button" class="btn danger sm" onclick="luaAskDropPg('<?= e($db) ?>')">Eliminar</button>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>

        <div class="card">
          <div style="font-weight:600;margin-bottom:12px">Roles / usuarios</div>
          <form method="post" class="inline" style="margin-bottom:16px">
            <input type="hidden" name="action" value="pg_role_create">
            <div>
              <label>Rol</label>
              <input name="username" placeholder="app" pattern="[a-zA-Z_][a-zA-Z0-9_]{0,62}" required>
            </div>
            <div>
              <label>Contraseña</label>
              <input type="text" name="password" placeholder="contraseña" autocomplete="off" required>
            </div>
            <div>
              <label>Acceso a</label>
              <select name="scope" onchange="document.getElementById('pguserdbrow').style.display=(this.value==='db')?'block':'none'">
                <option value="db">Una base de datos… (dueño)</option>
                <option value="all">Puede crear sus propias BD</option>
              </select>
            </div>
            <div id="pguserdbrow">
              <label>Base de datos</label>
              <input name="dbname" placeholder="micliente" pattern="[a-zA-Z_][a-zA-Z0-9_]{0,62}">
            </div>
            <button class="btn" type="submit">+ Crear rol</button>
          </form>

          <?php if ($pgRoles === null): ?>
            <div class="muted">No se pudo conectar con PostgreSQL para listar roles (¿acaba de activarse? espera unos segundos y recarga).</div>
          <?php elseif (!$pgRoles): ?>
            <div class="muted">No hay roles todavía. Crea el primero arriba.</div>
          <?php else: foreach ($pgRoles as $r): ?>
            <div class="dbrow">
              <div class="dbname"><?= e($r['name']) ?><?php if($r['super']): ?> <span class="jstate warn">superusuario</span><?php elseif(!$r['login']): ?> <span class="muted">(sin login)</span><?php endif; ?></div>
              <div class="spacer"></div>
              <?php if (strcasecmp($r['name'],'postgres') !== 0): ?>
                <button type="button" class="btn danger sm" onclick="luaAskDeletePgRole('<?= e($r['name']) ?>')">Eliminar</button>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
          <div class="muted" style="margin-top:10px;font-size:12px">Estos credenciales hay que asignarlos a mano en el <code>.env</code>/config de cada proyecto. El superusuario <code>postgres</code> no lleva contraseña (solo accesible desde 127.0.0.1).</div>
        </div>

        <!-- Modal: borrar base de datos PostgreSQL -->
        <div id="delPgDbModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDropPg()">
          <div class="modal-box" role="dialog" aria-modal="true">
            <div class="modal-ic"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></div>
            <h3>¿Eliminar base de datos?</h3>
            <p class="modal-tx">Se borrará <strong id="delPgDbName"></strong> y <strong>todo su contenido</strong> de forma permanente. Esto no se puede deshacer.</p>
            <form method="post" class="modal-actions">
              <input type="hidden" name="action" value="pg_db_drop">
              <input type="hidden" name="dbname" id="delPgDbInput">
              <button type="button" class="btn ghost" onclick="luaCloseDropPg()">Cancelar</button>
              <button type="submit" class="btn danger">Sí, eliminar</button>
            </form>
          </div>
        </div>
        <!-- Modal: borrar rol PostgreSQL -->
        <div id="delPgRoleModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDeletePgRole()">
          <div class="modal-box" role="dialog" aria-modal="true">
            <div class="modal-ic"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></div>
            <h3>¿Eliminar rol?</h3>
            <p class="modal-tx">Se eliminará el rol <strong id="delPgRoleName"></strong> de PostgreSQL. Fallará si el rol es dueño de objetos (bases de datos, tablas…).</p>
            <form method="post" class="modal-actions">
              <input type="hidden" name="action" value="pg_role_delete">
              <input type="hidden" name="username" id="delPgRoleInput">
              <button type="button" class="btn ghost" onclick="luaCloseDeletePgRole()">Cancelar</button>
              <button type="submit" class="btn danger">Sí, eliminar</button>
            </form>
          </div>
        </div>
        <!-- Modal: importar backup PostgreSQL -->
        <div id="importPgModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseImportPg()">
          <div class="modal-box" role="dialog" aria-modal="true">
            <div class="modal-ic"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg></div>
            <h3>¿Importar backup?</h3>
            <p class="modal-tx">Se ejecutará el <code>.sql</code> en <strong id="importPgName"></strong>. Si incluye objetos con el mismo nombre, <strong>pueden sobrescribirse o dar error</strong>.</p>
            <div class="modal-actions">
              <button type="button" class="btn ghost" id="importPgCancelBtn" onclick="luaCloseImportPg()">Cancelar</button>
              <button type="button" class="btn danger" id="importPgConfirmBtn" onclick="luaConfirmImportPg()">Sí, importar</button>
            </div>
          </div>
        </div>
        <script>
          function luaAskDropPg(n){ document.getElementById('delPgDbName').textContent=n; document.getElementById('delPgDbInput').value=n; document.getElementById('delPgDbModal').hidden=false; document.addEventListener('keydown',luaEscDropPg); }
          function luaCloseDropPg(){ document.getElementById('delPgDbModal').hidden=true; document.removeEventListener('keydown',luaEscDropPg); }
          function luaEscDropPg(e){ if(e.key==='Escape') luaCloseDropPg(); }
          function luaAskDeletePgRole(n){ document.getElementById('delPgRoleName').textContent=n; document.getElementById('delPgRoleInput').value=n; document.getElementById('delPgRoleModal').hidden=false; document.addEventListener('keydown',luaEscDeletePgRole); }
          function luaCloseDeletePgRole(){ document.getElementById('delPgRoleModal').hidden=true; document.removeEventListener('keydown',luaEscDeletePgRole); }
          function luaEscDeletePgRole(e){ if(e.key==='Escape') luaCloseDeletePgRole(); }
          var luaImportPgForm=null;
          function luaAskImportPg(ev, form, db){ ev.preventDefault(); luaImportPgForm=form; document.getElementById('importPgName').textContent=db; document.getElementById('importPgModal').hidden=false; document.addEventListener('keydown',luaEscImportPg); return false; }
          function luaConfirmImportPg(){
            if (!luaImportPgForm) { luaCloseImportPg(); return; }
            var btn = document.getElementById('importPgConfirmBtn');
            document.getElementById('importPgCancelBtn').disabled = true;
            btn.disabled = true;
            btn.innerHTML = '<span class="btn-spin"></span>Importando&hellip;';
            document.removeEventListener('keydown', luaEscImportPg);
            luaImportPgForm.requestSubmit();
            setTimeout(function(){
              btn.disabled = false; btn.innerHTML = 'Sí, importar';
              document.getElementById('importPgCancelBtn').disabled = false;
              luaCloseImportPg();
            }, 20000);
          }
          function luaCloseImportPg(){ document.getElementById('importPgModal').hidden=true; document.removeEventListener('keydown',luaEscImportPg); }
          function luaEscImportPg(e){ if(e.key==='Escape') luaCloseImportPg(); }
        </script>

      <?php endif; /* $pgOn */ ?>

    <?php elseif (!$mariaOn): ?>
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

      <?php $dbList = mysql_databases();
      // Ultimo job de import de archivo por BD (read_jobs ya viene ordenado por mas reciente).
      $fileJobsByDb = [];
      foreach ($jobs as $jj) {
          if (($jj['type']??'')!=='db_import_file') continue;
          $jjDb = $jj['dbname'] ?? $jj['name'] ?? '';
          if ($jjDb !== '' && !isset($fileJobsByDb[$jjDb])) $fileJobsByDb[$jjDb] = $jj;
      } ?>
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
            <div class="dbactions">
              <a class="btn ghost sm no-loader" href="?export_db=<?= e(rawurlencode($db)) ?>">Exportar</a>
              <form method="post" enctype="multipart/form-data" class="dbimport row" style="gap:6px" onsubmit="return luaAskImportDb(event, this, '<?= e($db) ?>')">
                <input type="hidden" name="action" value="db_import">
                <input type="hidden" name="dbname" value="<?= e($db) ?>">
                <label class="filepick">
                  <input type="file" name="sqlfile" accept=".sql" required onchange="luaFilePickName(this)">
                  <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                  <span class="filepick-name">Elegir .sql&hellip;</span>
                </label>
                <button class="btn ghost sm" type="submit">Importar</button>
              </form>
              <button type="button" class="btn danger sm" onclick="luaAskDropDb('<?= e($db) ?>')">Eliminar</button>
            </div>
          </div>
          <?php if (isset($fileJobsByDb[$db])): ?>
            <div style="margin:0 0 4px">
              <?= render_import_job_card($ROOT, $fileJobsByDb[$db]) ?>
            </div>
          <?php endif; ?>
        <?php endforeach; endif; ?>
      </div>

      <div class="card">
        <div style="font-weight:600">Importar carpeta de dumps</div>
        <div class="muted" style="margin-top:6px">Para exports con un <code>.sql</code> por tabla (en vez de un único dump completo): indica la carpeta en este servidor y la base de datos destino, y se importan todos en orden. Se ejecuta en segundo plano (puede tardar con carpetas grandes).</div>
        <form method="post" class="inline" style="margin-top:12px" onsubmit="return luaAskImportDir(event, this)">
          <input type="hidden" name="action" value="db_import_dir">
          <div>
            <label>Base de datos</label>
            <select name="dbname" required>
              <option value="" disabled selected>elige…</option>
              <?php foreach ($dbList ?: [] as $dbOpt): ?><option value="<?= e($dbOpt) ?>"><?= e($dbOpt) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div style="flex:1;min-width:280px">
            <label>Carpeta con los .sql</label>
            <div class="row" style="gap:6px">
              <input type="text" name="dir" id="dbImportDirInput" placeholder="C:\ruta\a\la\carpeta" required style="flex:1">
              <button type="button" class="btn ghost sm" id="dbImportDirPick" onclick="luaPickFolder(this,'dbImportDirInput')" <?= $watcherAlive?'':'disabled title="El watcher no está activo"' ?>>Elegir…</button>
            </div>
          </div>
          <button class="btn" type="submit">Importar carpeta</button>
        </form>
        <?php $dirJobs = array_values(array_filter($jobs, function($j){ return ($j['type']??'')==='db_import_dir'; })); ?>
        <?php foreach (array_slice($dirJobs,0,5) as $j): echo render_import_job_card($ROOT, $j); endforeach; ?>
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
            <input name="dbname" placeholder="micliente" list="mysqlDbList">
            <datalist id="mysqlDbList">
              <?php foreach ($dbList ?: [] as $dbOpt): ?><option value="<?= e($dbOpt) ?>"><?php endforeach; ?>
            </datalist>
          </div>
          <button class="btn" type="submit">+ Crear usuario</button>
        </form>

        <?php if ($mysqlUsers === null): ?>
          <div class="muted">No se pudo conectar con MySQL para listar usuarios (¿acaba de activarse? espera unos segundos y recarga).</div>
        <?php elseif (!$mysqlUsers): ?>
          <div class="muted">No hay usuarios de aplicación todavía. Crea el primero arriba.</div>
        <?php else: foreach ($mysqlUsers as $u):
          $scope = $mysqlScopePdo ? mysql_user_scope($mysqlScopePdo, $u['user'], $u['host']) : null; ?>
          <div class="dbrow">
            <div class="dbname"><?= e($u['user']) ?><span class="muted">@<?= e($u['host']) ?></span></div>
            <?php if ($scope !== null): ?>
              <span class="muted" style="font-size:12px">
                <?php if ($scope['all']): ?>acceso a todas las BD
                <?php elseif ($scope['dbs']): ?>acceso a: <?= e(implode(', ', $scope['dbs'])) ?>
                <?php else: ?>sin acceso a ninguna BD todavía
                <?php endif; ?>
              </span>
            <?php endif; ?>
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
            <button type="button" class="btn ghost" id="importDbCancelBtn" onclick="luaCloseImportDb()">Cancelar</button>
            <button type="button" class="btn danger" id="importDbConfirmBtn" onclick="luaConfirmImportDb()">Sí, importar</button>
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
          if (!luaImportDbForm) { luaCloseImportDb(); return; }
          var btn = document.getElementById('importDbConfirmBtn');
          document.getElementById('importDbCancelBtn').disabled = true;
          btn.disabled = true;
          btn.innerHTML = '<span class="btn-spin"></span>Importando&hellip;';
          document.removeEventListener('keydown', luaEscImportDb);
          // requestSubmit() (no submit()): dispara el evento 'submit' de verdad, para que
          // el loader global aparezca durante la importacion real (que puede tardar si el
          // .sql es grande) en vez de no mostrarse nunca. El modal se deja abierto (con el
          // boton en marcha) hasta que la navegacion real lo sustituya.
          luaImportDbForm.requestSubmit();
          // Red de seguridad: si la pagina no llega a navegar, no dejar el boton colgado.
          setTimeout(function(){
            btn.disabled = false; btn.innerHTML = 'Sí, importar';
            document.getElementById('importDbCancelBtn').disabled = false;
            luaCloseImportDb();
          }, 20000);
        }
        function luaCloseImportDb(){
          document.getElementById('importDbModal').hidden = true;
          document.removeEventListener('keydown', luaEscImportDb);
        }
        function luaEscImportDb(e){ if(e.key==='Escape') luaCloseImportDb(); }
      </script>

      <!-- Modal de confirmacion de importar carpeta de dumps (puede sobrescribir tablas existentes) -->
      <div id="importDirModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseImportDir()">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="importDirTitle">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            </svg>
          </div>
          <h3 id="importDirTitle">¿Importar carpeta?</h3>
          <p class="modal-tx">Se importarán todos los <code>.sql</code> de <code id="importDirPath"></code> en <strong id="importDirDb"></strong>, en segundo plano. Si incluyen tablas con el mismo nombre, <strong>se sobrescribirán</strong>.</p>
          <div class="modal-actions">
            <button type="button" class="btn ghost" id="importDirCancelBtn" onclick="luaCloseImportDir()">Cancelar</button>
            <button type="button" class="btn danger" id="importDirConfirmBtn" onclick="luaConfirmImportDir()">Sí, importar</button>
          </div>
        </div>
      </div>
      <script>
        var luaImportDirForm = null;
        function luaAskImportDir(ev, form){
          var db = form.dbname.value, dir = form.dir.value;
          if (!db || !dir) return true; // deja que el 'required' nativo se encargue
          ev.preventDefault();
          luaImportDirForm = form;
          document.getElementById('importDirDb').textContent = db;
          document.getElementById('importDirPath').textContent = dir;
          document.getElementById('importDirModal').hidden = false;
          document.addEventListener('keydown', luaEscImportDir);
          return false;
        }
        function luaConfirmImportDir(){
          if (!luaImportDirForm) { luaCloseImportDir(); return; }
          var btn = document.getElementById('importDirConfirmBtn');
          document.getElementById('importDirCancelBtn').disabled = true;
          btn.disabled = true;
          btn.innerHTML = '<span class="btn-spin"></span>Importando&hellip;';
          document.removeEventListener('keydown', luaEscImportDir);
          luaImportDirForm.requestSubmit();
          setTimeout(function(){
            btn.disabled = false; btn.innerHTML = 'Sí, importar';
            document.getElementById('importDirCancelBtn').disabled = false;
            luaCloseImportDir();
          }, 20000);
        }
        function luaCloseImportDir(){
          document.getElementById('importDirModal').hidden = true;
          document.removeEventListener('keydown', luaEscImportDir);
        }
        function luaEscImportDir(e){ if(e.key==='Escape') luaCloseImportDir(); }
      </script>

    <?php endif; ?>

  <?php elseif ($tab==='doctor'): /* ---------- PESTAÑA DOCTOR (diagnostico) ---------- */
      // Cada comprobacion apunta [grupo, estado ok|warn|err|info, titulo, detalle]. Se calcula
      // todo en servidor al cargar: son lecturas rapidas (netstat+tasklist ~200ms y ficheros).
      $checks = [];
      $addChk = function($grupo,$estado,$titulo,$detalle='') use (&$checks){ $checks[] = ['g'=>$grupo,'s'=>$estado,'t'=>$titulo,'d'=>$detalle]; };
      $lst   = doctor_listeners();
      $pname = doctor_procnames();
      $who   = function($port, $soloV6=null) use ($lst,$pname){
          $r = [];
          foreach ($lst as $l) {
              if ($l['port'] !== $port) continue;
              if ($soloV6 !== null && $l['v6'] !== $soloV6) continue;
              $r[] = ['n'=>($pname[$l['pid']] ?? ('PID '.$l['pid'])), 'pid'=>$l['pid'], 'addr'=>$l['addr'], 'v6'=>$l['v6']];
          }
          return $r;
      };

      // ---- Watcher ----
      $beatF = $ROOT.'/tmp/watch.beat';
      $beatAge = is_file($beatF) ? (time() - (int)trim((string)@file_get_contents($beatF))) : -1;
      if ($beatAge >= 0 && $beatAge <= 15) {
          $addChk('Watcher','ok','Watcher activo', 'Latido hace '.$beatAge.'s.');
      } else {
          $d = $beatAge < 0 ? 'Nunca ha latido (o se limpió tmp\).' : 'Último latido hace '.$beatAge.'s.';
          $d .= ' Sin watcher no hay jobs, ni HTTPS/hosts, ni supervisor de procesos. Arráncalo con <code>.\lua.ps1 start</code>.';
          if (startup_enabled($ROOT)) { $d .= ' <b>Ojo</b>: "Arrancar con Windows" está activo — puede haber un watcher de SYSTEM con código antiguo (no late). Reinícialo desde una consola elevada: <code>Stop-ScheduledTask -TaskName lua-server-watcher; Start-ScheduledTask -TaskName lua-server-watcher</code> (trampa nº1 de CLAUDE.md).'; }
          $addChk('Watcher','err','Watcher sin latido', $d);
      }

      // ---- Apache / puerto 80 y 443 ----
      // OJO: en Windows un mismo puerto puede tener VARIOS listeners IPv4 a la vez (0.0.0.0 de
      // un contenedor de Docker + 127.0.0.1 de Apache conviven; para 127.0.0.1 gana el bind mas
      // especifico). Por eso se evaluan TODOS los listeners, no el primero que da netstat.
      $puerto = function($port, $esperado, $v6=false) use ($who){
          $ls = $who($port, $v6);
          $mios = array_filter($ls, function($l) use ($esperado){ return stripos($l['n'], $esperado) !== false; });
          $otros = array_filter($ls, function($l) use ($esperado){ return stripos($l['n'], $esperado) === false; });
          return [$ls, array_values($mios), array_values($otros)];
      };
      [$l80, $ap80, $ot80] = $puerto(80, 'httpd');
      if ($ap80) {
          $d = 'Escucha <code>'.e($ap80[0]['addr']).'</code>.';
          if ($ot80) { $d .= ' También escucha <code>'.e($ot80[0]['n']).'</code> en <code>'.e($ot80[0]['addr']).'</code>: convive porque para <code>127.0.0.1</code> gana el bind más específico (Apache), pero desde la LAN responderá el otro.'; }
          $addChk('Red y puertos', $ot80 ? 'warn' : 'ok', 'Puerto 80 (IPv4): Apache', $d);
      }
      elseif ($l80) { $addChk('Red y puertos','err','Puerto 80 (IPv4) ocupado por otro proceso', 'Lo tiene <code>'.e($l80[0]['n']).'</code> (PID '.$l80[0]['pid'].'): Apache no puede servir en el 80.'); }
      else { $addChk('Red y puertos','err','Nadie escucha el puerto 80 en IPv4', '¿Apache caído? Revisa <code>logs\apache\error.log</code>.'); }
      [$l80v6, $ap80v6, $ot80v6] = $puerto(80, 'httpd', true);
      if ($ot80v6) {
          $addChk('Red y puertos','warn','Puerto 80 en IPv6 ocupado por '.e($ot80v6[0]['n']), 'Windows resuelve <code>localhost</code> mezclando IPv4 e IPv6, así que <code>http://localhost</code> puede llevarte a otro sitio (Portainer/Docker). Usa <code>http://127.0.0.1</code> o <code>http://'.e($tld).'</code> (trampa nº2 de CLAUDE.md).');
      }
      if (is_file($ROOT.'/config/https.on')) {
          [$l443, $ap443, $ot443] = $puerto(443, 'httpd');
          if ($ap443) { $addChk('Red y puertos', $ot443?'warn':'ok', 'Puerto 443 (HTTPS): Apache', $ot443 ? 'También lo escucha <code>'.e($ot443[0]['n']).'</code> en <code>'.e($ot443[0]['addr']).'</code>.' : ''); }
          elseif ($l443) { $addChk('Red y puertos','err','Puerto 443 ocupado por '.e($l443[0]['n']),'HTTPS está activado pero el puerto lo tiene otro proceso.'); }
          else { $addChk('Red y puertos','warn','HTTPS activado pero nadie escucha el 443','¿Se quedó a medias la configuración? Prueba a desactivar y reactivar HTTPS.'); }
      }

      // ---- Motores: flag vs binario vs puerto ----
      $motores = [
          ['MySQL (MariaDB)','mariadb.on', $ROOT.'/bin/mariadb/bin/mysqld.exe', 3306, 'mysqld'],
          ['PostgreSQL','postgres.on', $ROOT.'/bin/postgres/bin/pg_ctl.exe', 5432, 'postgres'],
          ['MongoDB','mongodb.on', $ROOT.'/bin/mongodb/bin/mongod.exe', 27017, 'mongod'],
          ['Redis (nativo)','redis.on', $ROOT.'/bin/redis/redis-server.exe', 6379, 'redis-server'],
          ['Mailpit','mailpit.on', $ROOT.'/bin/mailpit/mailpit.exe', 8025, 'mailpit'],
      ];
      foreach ($motores as [$mNom,$mFlag,$mBin,$mPort,$mProc]) {
          $on = is_file($ROOT.'/config/'.$mFlag);
          if (!$on) continue;   // apagado: nada que diagnosticar
          if (!is_file($mBin)) {
              $addChk('Motores','err',$mNom.': activado pero NO instalado', 'Existe <code>config\\'.$mFlag.'</code> pero falta <code>'.e(basename($mBin)).'</code>: la instalación falló o quedó a medias. Desactívalo y actívalo de nuevo desde Configuración, y mira <code>logs\jobs\</code>.');
              continue;
          }
          [$wl, $wMio, $wOtro] = $puerto($mPort, $mProc);
          if (!$wl) { $addChk('Motores','warn',$mNom.': nada escucha el puerto '.$mPort, 'El watcher lo arranca solo (con reintentos cada 30s si falla). Si no levanta, mira su log en <code>logs\</code>.'); }
          elseif ($wMio && $wOtro) { $addChk('Motores','warn',$mNom.' corriendo en el '.$mPort.', pero COMPARTIDO', 'También lo escucha <code>'.e($wOtro[0]['n']).'</code> en <code>'.e($wOtro[0]['addr']).'</code> (¿un contenedor de Docker?). Conectando a <code>127.0.0.1:'.$mPort.'</code> responde el nativo (bind más específico), pero es fácil confundirse de servidor: considera dejar solo uno.'); }
          elseif ($wMio) { $addChk('Motores','ok',$mNom.' corriendo en el '.$mPort, ''); }
          else { $addChk('Motores','err',$mNom.': el puerto '.$mPort.' lo tiene <code>'.e($wOtro[0]['n']).'</code>', 'Con el puerto ocupado (¿un contenedor de Docker?) el motor nativo NO va a poder arrancar, y el watcher lo reintentará para siempre. O paras ese proceso, o desactivas el motor nativo en Configuración.'); }
      }
      if (is_file($ROOT.'/config/mongodb.on') && is_file($ROOT.'/bin/mongo-express/app.js')) {
          $w81 = $who(8081, false);
          if ($w81 && stripos($w81[0]['n'],'node') !== false) { $addChk('Motores','ok','mongo-express corriendo en el 8081',''); }
          elseif ($w81) { $addChk('Motores','err','El puerto 8081 lo tiene <code>'.e($w81[0]['n']).'</code>','mongo-express no podrá escuchar ahí.'); }
          else { $addChk('Motores','warn','mongo-express: nada escucha el 8081','El watcher lo arranca cuando MongoDB está arriba.'); }
      }

      // ---- Config generada con rutas de OTRA carpeta (repo movido sin re-init) ----
      $rootFwd = str_replace('\\','/',$ROOT);
      foreach ([['config/mariadb/my.ini','MariaDB'],['config/mongodb/mongod.cfg','MongoDB'],['config/redis/redis.conf','Redis']] as [$cfgRel,$cfgNom]) {
          $f = $ROOT.'/'.$cfgRel;
          if (!is_file($f)) continue;
          $txt = (string)@file_get_contents($f);
          // Busca rutas absolutas de Windows y comprueba que TODAS cuelgan de la carpeta actual.
          if (preg_match_all('~[A-Za-z]:[/\\\\][^\s"\']+~', $txt, $mm)) {
              $ajenas = array_filter($mm[0], function($p) use ($rootFwd){ return stripos(str_replace('\\','/',$p), $rootFwd) !== 0; });
              if ($ajenas) { $addChk('Config','err',$cfgNom.': su config apunta a otra carpeta', 'En <code>'.e($cfgRel).'</code> hay rutas fuera de <code>'.e($ROOT).'</code> (p.ej. <code>'.e(reset($ajenas)).'</code>). ¿Se movió la carpeta de la plataforma? Ejecuta <code>.\lua.ps1 init</code> y <code>start</code>.'); }
              else { $addChk('Config','ok',$cfgNom.': rutas de su config correctas',''); }
          }
      }

      // ---- Proyectos y vhosts ----
      $vhostFiles = array_map(function($f){ return basename($f, '.conf'); }, (array)glob($ROOT.'/config/apache/vhosts/*.conf'));
      $domEff = [];
      foreach ($sites as $sn => $sv) {
          if (!in_array($sn, $vhostFiles, true)) { $addChk('Proyectos','err','El proyecto "'.e($sn).'" no tiene vhost', 'Está en <code>sites.json</code> pero falta <code>config\apache\vhosts\\'.e($sn).'.conf</code>: regenera con <code>.\lua.ps1 reload</code> (o el botón Aplicar del panel).'); }
          $base = project_dir($WWW, $sv, $sn);
          if (!is_dir($base)) { $addChk('Proyectos','err','La carpeta de "'.e($sn).'" no existe', '<code>'.e($base).'</code> no está en el disco: Apache devolverá 403/404. Si lo borraste a propósito, elimina el proyecto del panel.'); }
          $dom = strtolower(!empty($sv['domain']) ? $sv['domain'] : $sn.'.'.$tld);
          if (isset($domEff[$dom])) { $addChk('Proyectos','err','Dominio duplicado: <code>'.e($dom).'</code>', 'Lo usan "'.e($domEff[$dom]).'" y "'.e($sn).'". Apache sirve el que carga primero y el otro queda muerto en silencio.'); }
          $domEff[$dom] = $sn;
      }
      foreach ($vhostFiles as $vf) {
          if (!isset($sites[$vf])) { $addChk('Proyectos','warn','Vhost huérfano: <code>'.e($vf).'.conf</code>', 'No corresponde a ningún proyecto registrado. Un <code>.\lua.ps1 reload</code> lo limpia.'); }
      }
      if (!array_filter($checks, function($c){ return $c['g']==='Proyectos'; })) { $addChk('Proyectos','ok','Proyectos y vhosts cuadran', count($sites).' proyecto(s), '.count($vhostFiles).' vhost(s).'); }

      // ---- hosts de Windows ----
      $hostsTxt = (string)@file_get_contents(getenv('WINDIR').'/System32/drivers/etc/hosts');
      $faltanHosts = [];
      foreach ($domEff as $dom => $sn) { if ($hostsTxt !== '' && stripos($hostsTxt, $dom) === false) { $faltanHosts[] = $dom; } }
      if ($hostsTxt === '') { $addChk('Proyectos','warn','No se pudo leer el archivo hosts de Windows',''); }
      elseif ($faltanHosts) { $addChk('Proyectos','warn','Dominios sin registrar en el hosts de Windows', '<code>'.e(implode('</code>, <code>', array_slice($faltanHosts,0,6))).'</code>'.(count($faltanHosts)>6?' y '.(count($faltanHosts)-6).' más':'').' no abrirán en el navegador. Pulsa <b>Sincronizar dominios</b> en Configuración.'); }
      else { $addChk('Proyectos','ok','Todos los dominios están en el hosts de Windows',''); }

      // ---- PHP ----
      foreach ($vers as $v) {
          $mal = [];
          if (!is_file($PHP_BASE.'/'.$v.'/php-cgi.exe')) $mal[] = 'php-cgi.exe';
          if (!is_file($PHP_BASE.'/'.$v.'/php.ini'))     $mal[] = 'php.ini';
          if (!is_file($ROOT.'/config/php/'.$v.'.overrides.ini')) $mal[] = $v.'.overrides.ini';
          if ($mal) { $addChk('PHP','err','PHP '.e($v).' incompleto', 'Falta: <code>'.e(implode('</code>, <code>',$mal)).'</code>. Un <code>.\lua.ps1 init</code> regenera los ini.'); }
      }
      if (!array_filter($checks, function($c){ return $c['g']==='PHP'; })) { $addChk('PHP','ok','Versiones de PHP completas', implode(', ', $vers).'.'); }

      // ---- Sistema ----
      $free = @disk_free_space($ROOT);
      if ($free !== false) {
          $gb = $free / 1073741824;
          if ($gb < 1)      { $addChk('Sistema','err','Disco casi lleno', number_format($gb,1).' GB libres: MySQL/MongoDB pueden corromperse al quedarse sin espacio.'); }
          elseif ($gb < 5)  { $addChk('Sistema','warn','Poco espacio en disco', number_format($gb,1).' GB libres.'); }
          else              { $addChk('Sistema','ok','Espacio en disco', number_format($gb,0).' GB libres.'); }
      }
      $gordos = [];
      foreach ((array)glob($ROOT.'/logs/*/*.log') as $lf) { if (@filesize($lf) > 104857600) { $gordos[] = basename(dirname($lf)).'/'.basename($lf).' ('.round(filesize($lf)/1048576).' MB)'; } }
      if ($gordos) { $addChk('Sistema','warn','Logs muy grandes', e(implode(', ', array_slice($gordos,0,5))).'. Vacíalos desde la pestaña Logs.'); }
      $updDoc = update_status($ROOT);
      if (!empty($updDoc['sucio'])) { $addChk('Sistema','info','Cambios locales sin confirmar en la plataforma', 'La actualización automática se bloquea sola para no pisarlos (pestaña Configuración → Actualizaciones).'); }
      if ((int)($updDoc['detras'] ?? 0) > 0) { $addChk('Sistema','info','Hay '.(int)$updDoc['detras'].' actualización(es) de la plataforma', 'Instálala desde Configuración → Actualizaciones.'); }

      // Orden: errores primero dentro de cada grupo, grupos en orden de aparicion.
      $orden = ['err'=>0,'warn'=>1,'info'=>2,'ok'=>3];
      $grupos = [];
      foreach ($checks as $c) { $grupos[$c['g']][] = $c; }
      foreach ($grupos as &$gl) { usort($gl, function($a,$b) use ($orden){ return $orden[$a['s']] <=> $orden[$b['s']]; }); }
      unset($gl);
      $nErr = count(array_filter($checks, function($c){ return $c['s']==='err'; }));
      $nWarn = count(array_filter($checks, function($c){ return $c['s']==='warn'; }));
  ?>

    <div class="card row" style="flex-wrap:wrap;gap:8px">
      <div style="min-width:260px">
        <div style="font-weight:600">Doctor</div>
        <div class="muted" style="margin-top:4px">Comprobación automática de los problemas conocidos de la plataforma: puertos robados, watcher caído, motores a medias, vhosts descuadrados…</div>
      </div>
      <div class="spacer"></div>
      <?php if ($nErr): ?><span class="jstate err"><?= $nErr ?> ERROR(ES)</span><?php endif; ?>
      <?php if ($nWarn): ?><span class="jstate" style="color:var(--warn);border-color:var(--warn)"><?= $nWarn ?> AVISO(S)</span><?php endif; ?>
      <?php if (!$nErr && !$nWarn): ?><span class="jstate ok">TODO EN ORDEN</span><?php endif; ?>
      <a class="btn ghost sm" href="?tab=doctor">Volver a comprobar</a>
    </div>

    <?php foreach ($grupos as $gNom => $gChecks): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:10px"><?= e($gNom) ?></div>
        <?php foreach ($gChecks as $c): ?>
          <div class="row" style="gap:10px;align-items:flex-start;padding:7px 0;border-top:1px solid var(--line)">
            <?php if ($c['s']==='ok'): ?>
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--ok)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;margin-top:2px"><polyline points="20 6 9 17 4 12"/></svg>
            <?php elseif ($c['s']==='err'): ?>
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--err)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <?php elseif ($c['s']==='warn'): ?>
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--warn)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;margin-top:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <?php else: ?>
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--ac)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <?php endif; ?>
            <div style="min-width:0">
              <div style="font-size:13px;font-weight:600"><?= $c['t'] /* puede llevar <code> propio, ya escapado al construirse */ ?></div>
              <?php if ($c['d'] !== ''): ?><div class="muted" style="font-size:12px;margin-top:2px"><?= $c['d'] ?></div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

  <?php elseif ($tab==='procs'): /* ---------- PESTAÑA PROCESOS (supervisor) ---------- */
      $procs = procs_load($ROOT);
      $procState = procs_state($ROOT);
      $procEdit = null;
      if (valid_proc_id((string)($_GET['edit'] ?? ''))) {
          foreach ($procs as $p) { if (($p['id'] ?? '') === $_GET['edit']) { $procEdit = $p; break; } }
      }
      $procForm = $procEdit !== null || isset($_GET['nuevo']) || !$procs;
      $procTermOn = term_enabled($ROOT);
      // Agrupar por proyecto para el listado
      $procsByProj = [];
      foreach ($procs as $p) { $procsByProj[(string)($p['project'] ?? '?')][] = $p; }
      ksort($procsByProj);
  ?>

    <div class="card row" style="flex-wrap:wrap;gap:8px">
      <div style="min-width:260px">
        <div style="font-weight:600">Procesos supervisados</div>
        <div class="muted" style="margin-top:4px">Comandos largos de tus proyectos (colas, scheduler, Vite…) que el watcher mantiene corriendo y reinicia si se caen. Con log propio.</div>
      </div>
      <div class="spacer"></div>
      <?php if (!$procForm): ?><a class="btn ghost sm" href="?tab=procs&nuevo=1">+ Añadir proceso</a><?php endif; ?>
    </div>

    <?php if (!$watcherAlive): ?>
      <div class="card"><div class="msgtext err" style="margin:0">El watcher no está activo: nadie arranca ni vigila los procesos. Arráncalo con <code>.\lua.ps1 start</code>.</div></div>
    <?php endif; ?>
    <?php if (!$procTermOn): ?>
      <div class="card"><div class="msgtext warn" style="margin:0">El supervisor ejecuta comandos, así que usa la misma llave de seguridad que la Terminal: actívala en <a href="?tab=config">Configuración del servidor</a> para poder crear, arrancar o parar procesos. (Ver estado y logs sí está permitido.)</div></div>
    <?php endif; ?>

    <?php if ($procForm): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:12px"><?= $procEdit ? 'Editar proceso' : 'Nuevo proceso' ?></div>
        <form method="post" class="inline">
          <input type="hidden" name="action" value="proc_save">
          <?php if ($procEdit): ?><input type="hidden" name="id" value="<?= e($procEdit['id']) ?>"><?php endif; ?>
          <div>
            <label>Proyecto</label>
            <select name="project" required>
              <?php foreach (array_keys($sitesView) as $sn): ?>
                <option value="<?= e($sn) ?>" <?= ($procEdit['project'] ?? '')===$sn?'selected':'' ?>><?= e($sn) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div><label>Nombre</label><input type="text" name="label" placeholder="Cola" maxlength="40" value="<?= e($procEdit['label'] ?? '') ?>"></div>
          <div style="flex:1;min-width:280px">
            <label>Comando <span class="muted">(corre en la raíz del proyecto)</span></label>
            <input type="text" name="cmd" list="procPresets" placeholder="php artisan queue:work" required maxlength="300" style="width:100%" value="<?= e($procEdit['cmd'] ?? '') ?>">
            <datalist id="procPresets">
              <option value="php artisan queue:work --tries=3"></option>
              <option value="php artisan schedule:work"></option>
              <option value="php artisan horizon"></option>
              <option value="php artisan reverb:start"></option>
              <option value="npm run dev"></option>
              <option value="npm run watch"></option>
            </datalist>
          </div>
          <div style="max-width:130px">
            <label>PHP</label>
            <select name="php">
              <option value="">(el del PATH)</option>
              <?php foreach ($vers as $v): ?><option value="<?= e($v) ?>" <?= ($procEdit['php'] ?? '')===$v?'selected':'' ?>>PHP <?= e($v) ?></option><?php endforeach; ?>
            </select>
          </div>
          <button class="btn" type="submit" <?= $procTermOn?'':'disabled' ?>><?= $procEdit ? 'Guardar cambios' : 'Guardar proceso' ?></button>
          <?php if ($procs): ?><a class="btn ghost" href="?tab=procs">Cancelar</a><?php endif; ?>
        </form>
        <div class="muted" style="margin-top:10px;font-size:11.5px">
          El comando hereda el PHP elegido al frente del <code>PATH</code> (con su <code>php.ini</code>), igual que el runner. Los procesos nuevos se crean <b>parados</b>.
          Se detienen todos con <code>lua.ps1 stop</code> y vuelven solos con <code>start</code> si estaban activados.
        </div>
      </div>
    <?php endif; ?>

    <?php foreach ($procsByProj as $pj => $plist): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:10px"><?= e($pj) ?></div>
        <?php foreach ($plist as $p): $pid = (string)$p['id']; $ps = $procState[$pid] ?? []; $run = !empty($ps['running']); ?>
          <div class="row" style="gap:10px;flex-wrap:wrap;align-items:center;padding:8px 0;border-top:1px solid var(--line)" data-procrow="<?= e($pid) ?>">
            <span class="jstate <?= $run?'run':(!empty($p['enabled'])?'err':'') ?>" data-badge><?= $run ? 'CORRIENDO' : (!empty($p['enabled']) ? 'CAÍDO' : 'PARADO') ?></span>
            <div style="min-width:140px;font-weight:600;font-size:13px"><?= e($p['label']) ?></div>
            <code style="font-size:11.5px;opacity:.85;flex:1;min-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($p['cmd']) ?></code>
            <?php if (($p['php'] ?? '') !== ''): ?><span class="muted" style="font-size:11px">PHP <?= e($p['php']) ?></span><?php endif; ?>
            <span class="muted" style="font-size:11px" data-meta></span>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="proc_toggle">
              <input type="hidden" name="id" value="<?= e($pid) ?>">
              <input type="hidden" name="enable" value="<?= !empty($p['enabled'])?'0':'1' ?>">
              <button class="btn <?= !empty($p['enabled'])?'danger':'' ?> sm" type="submit" <?= $procTermOn?'':'disabled' ?> data-togglebtn><?= !empty($p['enabled'])?'Parar':'Arrancar' ?></button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="proc_restart">
              <input type="hidden" name="id" value="<?= e($pid) ?>">
              <button class="btn ghost sm" type="submit" <?= ($procTermOn && !empty($p['enabled']))?'':'disabled' ?>>Reiniciar</button>
            </form>
            <button type="button" class="btn ghost sm" data-logbtn="<?= e($pid) ?>">Log</button>
            <a class="btn ghost sm" href="?tab=procs&edit=<?= e($pid) ?>">Editar</a>
            <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar la definición de este proceso? Si está corriendo, se detendrá.')">
              <input type="hidden" name="action" value="proc_del">
              <input type="hidden" name="id" value="<?= e($pid) ?>">
              <button class="btn danger sm" type="submit" <?= $procTermOn?'':'disabled' ?>>Eliminar</button>
            </form>
          </div>
          <pre class="logview" data-logpane="<?= e($pid) ?>" hidden style="margin:0 0 8px;max-height:40vh"></pre>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <script>
    (function(){
      // ---- badges en vivo: se repintan con ?ajax=procs&op=state sin recargar la pagina ----
      function tick() {
        fetch('?ajax=procs&op=state').then(function(r){ return r.json(); }).then(function(j){
          if (!j.ok) return;
          j.procs.forEach(function(p){
            var row = document.querySelector('[data-procrow="'+p.id+'"]'); if (!row) return;
            var b = row.querySelector('[data-badge]'), m = row.querySelector('[data-meta]');
            if (p.running) {
              b.textContent = 'CORRIENDO'; b.className = 'jstate run';
              m.textContent = 'PID ' + p.pid + (p.since ? ' · desde ' + new Date(p.since*1000).toLocaleTimeString() : '');
            } else if (p.enabled) {
              b.textContent = p.fails > 0 ? 'REINTENTANDO' : 'ARRANCANDO…';
              b.className = 'jstate err';
              var wait = p.next > j.now ? (p.next - j.now) : 0;
              m.textContent = p.fails > 0 ? (p.fails + ' caída(s) seguidas' + (wait ? ' · reintento en ' + wait + 's' : '')) : '';
            } else {
              b.textContent = 'PARADO'; b.className = 'jstate';
              m.textContent = '';
            }
          });
        }).catch(function(){});
      }
      setInterval(tick, 3000); tick();

      // ---- visor de log: uno abierto a la vez, con auto-refresco mientras este visible ----
      var openLog = null, logTimer = null;
      function refreshLog() {
        if (!openLog) return;
        fetch('?ajax=procs&op=log&id=' + openLog).then(function(r){ return r.json(); }).then(function(j){
          var pane = document.querySelector('[data-logpane="'+openLog+'"]'); if (!pane) return;
          pane.innerHTML = j.exists ? (j.html || '(vacío)') : '(sin log todavía: el proceso aún no ha arrancado nunca)';
          pane.scrollTop = pane.scrollHeight;
        }).catch(function(){});
      }
      document.querySelectorAll('[data-logbtn]').forEach(function(btn){
        btn.addEventListener('click', function(){
          var id = btn.dataset.logbtn;
          var pane = document.querySelector('[data-logpane="'+id+'"]');
          if (openLog === id) {           // segundo clic: cerrar
            pane.hidden = true; openLog = null;
            if (logTimer) { clearInterval(logTimer); logTimer = null; }
            return;
          }
          document.querySelectorAll('[data-logpane]').forEach(function(x){ x.hidden = true; });
          openLog = id; pane.hidden = false;
          pane.innerHTML = 'cargando…';
          refreshLog();
          if (logTimer) clearInterval(logTimer);
          logTimer = setInterval(refreshLog, 2000);
        });
      });
    })();
    </script>

  <?php elseif ($tab==='redis'): /* ---------- PESTAÑA REDIS ---------- */
      // Mismo esquema que SQL Server: lista de conexiones guardadas arriba, y debajo el
      // explorador (barra lateral de bases + claves + valor). Todo el trabajo real lo hace
      // ?ajax=redis; aqui solo se pinta el armazon y se le pasa la conexion elegida al JS.
      $rdServers = redis_servers($ROOT);
      $rdSel  = (string)($_GET['conn'] ?? '');
      $rdSrv  = valid_redis_id($rdSel) ? redis_find($ROOT, $rdSel) : null;
      if (!$rdSrv && $rdServers) { $rdSrv = $rdServers[0]; }
      $rdEditSrv = valid_redis_id((string)($_GET['edit'] ?? '')) ? redis_find($ROOT, $_GET['edit']) : null;
      $rdForm = $rdEditSrv !== null || isset($_GET['nueva']) || !$rdServers; ?>

    <div class="card row" style="flex-wrap:wrap;gap:8px">
      <div style="min-width:220px">
        <div style="font-weight:600">Servidores Redis</div>
        <div class="muted" style="margin-top:4px">Conecta con un Redis existente (un contenedor de Docker, uno nativo o uno de red). No hace falta la extensión <code>php_redis</code>: el panel habla el protocolo directamente.</div>
      </div>
      <div class="spacer"></div>
      <?php foreach ($rdServers as $s): ?>
        <a class="btn <?= ($rdSrv && $s['id']===$rdSrv['id'] && !$rdForm) ? '' : 'ghost' ?> sm"
           href="?tab=redis&conn=<?= e(rawurlencode($s['id'])) ?>"><?= e($s['label']) ?></a>
      <?php endforeach; ?>
      <a class="btn ghost sm" href="?tab=redis&nueva=1">+ Añadir conexión</a>
    </div>

    <?php if ($rdForm): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:12px"><?= $rdEditSrv ? 'Editar conexión' : 'Nueva conexión' ?></div>
        <form method="post" class="inline">
          <input type="hidden" name="action" value="redis_save">
          <?php if ($rdEditSrv): ?><input type="hidden" name="id" value="<?= e($rdEditSrv['id']) ?>"><?php endif; ?>
          <div><label>Nombre</label><input type="text" name="label" placeholder="Docker local" value="<?= e($rdEditSrv['label'] ?? '') ?>"></div>
          <div><label>Host o IP</label><input type="text" name="host" placeholder="127.0.0.1" value="<?= e($rdEditSrv['host'] ?? '127.0.0.1') ?>" required></div>
          <div style="max-width:110px"><label>Puerto</label><input type="text" name="port" value="<?= e((string)($rdEditSrv['port'] ?? 6379)) ?>" required></div>
          <div><label>Usuario <span class="muted">(ACL, Redis 6+)</span></label><input type="text" name="user" placeholder="opcional" value="<?= e($rdEditSrv['user'] ?? '') ?>"></div>
          <div><label>Contraseña</label>
            <input type="password" name="pass" autocomplete="new-password" placeholder="<?= $rdEditSrv ? 'dejar vacío para no cambiarla' : 'si no tiene, déjalo vacío' ?>">
          </div>
          <button class="btn" type="submit"><?= $rdEditSrv ? 'Guardar cambios' : 'Guardar conexión' ?></button>
          <?php if ($rdEditSrv): ?><a class="btn ghost" href="?tab=redis&conn=<?= e(rawurlencode($rdEditSrv['id'])) ?>">Cancelar</a><?php endif; ?>
          <?php if (!$rdEditSrv && $rdServers): ?><a class="btn ghost" href="?tab=redis">Cancelar</a><?php endif; ?>
        </form>
        <div class="muted" style="margin-top:10px;font-size:11.5px">La contraseña se guarda en claro en <code>config\redis-servers.json</code> (fuera de git), igual que las de MySQL y SQL Server.</div>
      </div>
    <?php endif; ?>

    <?php if ($rdSrv && !$rdForm): ?>
      <div class="sqlx" id="rdApp" data-conn="<?= e($rdSrv['id']) ?>">
        <div class="sqlx-side">
          <div class="card">
            <div class="row" style="gap:6px;align-items:center">
              <div style="font-weight:600;font-size:13px"><?= e($rdSrv['label']) ?></div>
              <div class="spacer"></div>
              <a class="btn ghost sm" href="?tab=redis&edit=<?= e(rawurlencode($rdSrv['id'])) ?>">Editar</a>
            </div>
            <div class="muted" style="margin-top:3px;font-size:11.5px">
              <code><?= e($rdSrv['host'].':'.$rdSrv['port']) ?></code> · <span id="rdVer">…</span>
            </div>
            <div class="row" style="margin-top:12px;align-items:center">
              <span style="font-weight:600;font-size:12px">Bases</span>
              <div class="spacer"></div>
              <button type="button" class="btn ghost sm" id="rdReload" title="Recargar bases y claves">Refrescar</button>
            </div>
            <div class="rdb" id="rdDbs"><div class="sqlx-empty">cargando…</div></div>
          </div>
        </div>

        <div class="sqlx-main">
          <div class="card">
            <div class="sqlx-views">
              <button type="button" data-view="keys" class="on">Claves</button>
              <button type="button" data-view="cons">Consola</button>
              <button type="button" data-view="info">Servidor</button>
            </div>

            <!-- ---- vista Claves ---- -->
            <div id="rdViewKeys">
              <div class="row" style="gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px">
                <div style="flex:1;min-width:200px">
                  <label>Patrón <span class="muted">(comodín <code>*</code>)</span></label>
                  <input type="text" id="rdMatch" placeholder="*" style="width:100%">
                </div>
                <div style="max-width:110px">
                  <label>Por página</label>
                  <select id="rdCount">
                    <option value="50">50</option>
                    <option value="100" selected>100</option>
                    <option value="500">500</option>
                  </select>
                </div>
                <button type="button" class="btn sm" id="rdSearch">Buscar</button>
                <div class="spacer"></div>
                <button type="button" class="btn danger sm" id="rdFlush">Vaciar base</button>
              </div>
              <div class="rkeys" id="rdKeys"><div class="sqlx-empty">elige una base</div></div>
              <div class="row" style="margin-top:8px;align-items:center">
                <span class="muted" id="rdKeysMeta" style="font-size:11.5px"></span>
                <div class="spacer"></div>
                <button type="button" class="btn ghost sm" id="rdMore" hidden>Cargar más</button>
              </div>

              <!-- detalle de la clave seleccionada -->
              <div id="rdDetail" hidden style="margin-top:16px;border-top:1px solid var(--line);padding-top:14px">
                <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
                  <span class="rtype" id="rdDType">—</span>
                  <code id="rdDKey" style="font-size:12.5px;word-break:break-all"></code>
                  <div class="spacer"></div>
                  <span class="muted" id="rdDMeta" style="font-size:11.5px"></span>
                </div>
                <div class="row" style="gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:10px">
                  <div style="max-width:150px">
                    <label>TTL (segundos)</label>
                    <input type="text" id="rdTtl" placeholder="sin expiración">
                  </div>
                  <button type="button" class="btn ghost sm" id="rdTtlSave">Aplicar TTL</button>
                  <div style="flex:1;min-width:180px">
                    <label>Renombrar a</label>
                    <input type="text" id="rdRename" placeholder="nuevo:nombre" style="width:100%">
                  </div>
                  <button type="button" class="btn ghost sm" id="rdRenameSave">Renombrar</button>
                  <div class="spacer"></div>
                  <button type="button" class="btn danger sm" id="rdDel">Eliminar clave</button>
                </div>
                <div id="rdEditor" style="margin-top:12px"></div>
                <div class="msgtext" id="rdDMsg" style="margin-top:8px"></div>
              </div>
            </div>

            <!-- ---- vista Consola ---- -->
            <div id="rdViewCons" hidden>
              <div class="muted" style="font-size:12px;margin-bottom:8px">
                Se ejecuta contra la base seleccionada. Los argumentos con espacios van entre comillas, como en <code>redis-cli</code>.
                <code>SHUTDOWN</code> y los comandos que dejan la conexión escuchando (<code>SUBSCRIBE</code>, <code>MONITOR</code>…) están bloqueados.
              </div>
              <div class="rcons" id="rdConsOut"><span class="muted">Escribe un comando y pulsa Enter. Flechas ↑/↓ para el historial.</span></div>
              <div class="row" style="gap:8px;margin-top:8px">
                <input type="text" id="rdConsIn" placeholder="GET mi:clave" autocomplete="off" spellcheck="false"
                       style="flex:1;font-family:ui-monospace,Consolas,monospace">
                <button type="button" class="btn sm" id="rdConsRun">Ejecutar</button>
                <button type="button" class="btn ghost sm" id="rdConsClear">Limpiar</button>
              </div>
            </div>

            <!-- ---- vista Servidor ---- -->
            <div id="rdViewInfo" hidden>
              <div class="rinfo" id="rdInfoBoxes"><div class="sqlx-empty">cargando…</div></div>
              <details style="margin-top:14px">
                <summary style="cursor:pointer;font-size:12.5px;color:var(--mut)">INFO completo</summary>
                <pre class="rcons" style="margin-top:8px;max-height:44vh" id="rdInfoRaw"></pre>
              </details>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal: eliminar clave -->
      <div id="rdDelModal" class="modal-overlay" hidden onclick="if(event.target===this)rdCloseDel()">
        <div class="modal-box" role="dialog" aria-modal="true">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
              <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
          </div>
          <h3>¿Eliminar la clave?</h3>
          <p class="modal-tx">Se borrará <strong id="rdDelName"></strong> del servidor. Esto no se puede deshacer.</p>
          <div class="modal-actions">
            <button type="button" class="btn ghost" onclick="rdCloseDel()">Cancelar</button>
            <button type="button" class="btn danger" id="rdDelYes">Sí, eliminar</button>
          </div>
        </div>
      </div>

      <!-- Modal: vaciar base -->
      <div id="rdFlushModal" class="modal-overlay" hidden onclick="if(event.target===this)rdCloseFlush()">
        <div class="modal-box" role="dialog" aria-modal="true">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
              <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
          </div>
          <h3>¿Vaciar la base <span id="rdFlushDb"></span>?</h3>
          <p class="modal-tx">Se borrarán <strong id="rdFlushN"></strong> del servidor <strong><?= e($rdSrv['label']) ?></strong>.
             Si alguna aplicación usa esta base como caché o para sesiones, lo notará. No se puede deshacer.</p>
          <div class="modal-actions">
            <button type="button" class="btn ghost" onclick="rdCloseFlush()">Cancelar</button>
            <button type="button" class="btn danger" id="rdFlushYes">Sí, vaciar</button>
          </div>
        </div>
      </div>

      <div class="card" style="margin-top:14px">
        <div class="row" style="align-items:center">
          <div>
            <div style="font-weight:600;font-size:13px">Eliminar esta conexión</div>
            <div class="muted" style="font-size:11.5px;margin-top:3px">Solo borra los datos de acceso guardados aquí; no toca el servidor de Redis.</div>
          </div>
          <div class="spacer"></div>
          <form method="post" onsubmit="return confirm('¿Eliminar la conexión guardada?')">
            <input type="hidden" name="action" value="redis_del">
            <input type="hidden" name="id" value="<?= e($rdSrv['id']) ?>">
            <button class="btn danger sm" type="submit">Eliminar conexión</button>
          </form>
        </div>
      </div>

    <script>
    (function(){
      var CONN = document.getElementById('rdApp').dataset.conn;
      var db = 0, cursor = '0', curKey = null, curType = null, hist = [], histIx = -1;
      var $ = function(id){ return document.getElementById(id); };
      function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

      // Todas las llamadas van por POST: una clave puede ser larga o traer caracteres raros y
      // en el query string acabaria recortada o mal codificada por el camino.
      function api(op, extra) {
        var b = new URLSearchParams();
        b.set('op', op); b.set('db', db);
        if (extra) { for (var k in extra) {
          if (Array.isArray(extra[k])) { extra[k].forEach(function(v){ b.append(k+'[]', v); }); }
          else { b.set(k, extra[k]); }
        } }
        return fetch('?ajax=redis&conn=' + encodeURIComponent(CONN), {
          method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=utf-8'}, body:b.toString()
        }).then(function(r){ return r.json(); });
      }
      function dmsg(txt, ok) {
        var el = $('rdDMsg');
        el.className = 'msgtext ' + (ok ? 'ok' : 'err');
        el.textContent = txt || '';
        if (txt && ok) setTimeout(function(){ if (el.textContent===txt) el.textContent=''; }, 3000);
      }
      function ttlTxt(t){ return t < 0 ? 'sin expiración' : t + 's'; }

      // ---- pestañas internas ----
      document.querySelectorAll('.sqlx-views button').forEach(function(b){
        b.addEventListener('click', function(){
          var v = b.dataset.view;
          document.querySelectorAll('.sqlx-views button').forEach(function(x){ x.classList.toggle('on', x===b); });
          $('rdViewKeys').hidden = v!=='keys';
          $('rdViewCons').hidden = v!=='cons';
          $('rdViewInfo').hidden = v!=='info';
          if (v==='info') loadInfo();
        });
      });

      // ---- bases ----
      function loadDbs() {
        api('dbs').then(function(j){
          if (j.error) { $('rdDbs').innerHTML = '<div class="sqlx-empty">'+esc(j.error)+'</div>'; return; }
          $('rdDbs').innerHTML = j.dbs.map(function(d){
            return '<button type="button" data-db="'+d.db+'" class="'+(d.db===db?'on':'')+(d.keys===0?' vacia':'')+'">'
                 + 'db'+d.db+'<span class="n">'+d.keys+'</span></button>';
          }).join('');
          $('rdDbs').querySelectorAll('button').forEach(function(b){
            b.addEventListener('click', function(){
              db = parseInt(b.dataset.db,10);
              $('rdDbs').querySelectorAll('button').forEach(function(x){ x.classList.toggle('on', x===b); });
              hideDetail(); search();
            });
          });
        });
      }
      api('test').then(function(j){ $('rdVer').textContent = j.error ? 'sin conexión' : ('Redis '+j.version+' · '+j.mode); });

      // ---- claves ----
      function renderKeys(keys, append) {
        var html = keys.map(function(k){
          return '<div class="rkey" data-key="'+esc(k.key)+'" data-type="'+esc(k.type)+'">'
               + '<span class="rtype '+esc(k.type)+'">'+esc(k.type)+'</span>'
               + '<span class="kn">'+esc(k.key)+'</span>'
               + '<span class="kt">'+(k.ttl<0?'—':k.ttl+'s')+'</span></div>';
        }).join('');
        if (append) { $('rdKeys').insertAdjacentHTML('beforeend', html); }
        else { $('rdKeys').innerHTML = html || '<div class="sqlx-empty">Sin claves que coincidan.</div>'; }
        $('rdKeys').querySelectorAll('.rkey:not([data-bound])').forEach(function(el){
          el.setAttribute('data-bound','1');
          el.addEventListener('click', function(){
            $('rdKeys').querySelectorAll('.rkey').forEach(function(x){ x.classList.remove('on'); });
            el.classList.add('on');
            openKey(el.dataset.key);
          });
        });
      }
      function search(append) {
        if (!append) { cursor = '0'; }
        api('scan', { cursor: cursor, match: $('rdMatch').value.trim(), count: $('rdCount').value })
          .then(function(j){
            if (j.error) { $('rdKeys').innerHTML = '<div class="sqlx-empty">'+esc(j.error)+'</div>'; return; }
            cursor = j.cursor;
            renderKeys(j.keys, append);
            $('rdMore').hidden = j.done;
            var n = $('rdKeys').querySelectorAll('.rkey').length;
            // SCAN no sabe cuantas claves hay en total hasta terminar: se dice lo mostrado, no
            // un total inventado. Ademas SCAN puede devolver lotes vacios y aun no haber acabado.
            $('rdKeysMeta').textContent = n + ' clave(s) mostradas' + (j.done ? ' · recorrido completo' : ' · quedan más');
          });
      }
      $('rdSearch').addEventListener('click', function(){ search(false); });
      $('rdMatch').addEventListener('keydown', function(e){ if (e.key==='Enter') search(false); });
      $('rdCount').addEventListener('change', function(){ search(false); });
      $('rdMore').addEventListener('click', function(){ search(true); });
      $('rdReload').addEventListener('click', function(){ loadDbs(); search(false); hideDetail(); });

      // ---- detalle / editor ----
      function hideDetail(){ $('rdDetail').hidden = true; curKey = null; curType = null; }
      function openKey(key) {
        api('key', { key: key }).then(function(j){
          if (j.error) { dmsg(j.error, false); $('rdDetail').hidden = false; $('rdEditor').innerHTML=''; return; }
          curKey = j.key; curType = j.type;
          $('rdDetail').hidden = false;
          $('rdDType').textContent = j.type;
          $('rdDType').className = 'rtype ' + j.type;
          $('rdDKey').textContent = j.key;
          $('rdTtl').value = j.ttl < 0 ? '' : j.ttl;
          $('rdRename').value = '';
          dmsg('', true);
          var meta = 'TTL: ' + ttlTxt(j.ttl);
          if (j.type==='string') { meta += ' · ' + j.len + ' bytes'; }
          else if (typeof j.count === 'number') { meta += ' · ' + j.count + ' elemento(s)'; }
          $('rdDMeta').textContent = meta;
          renderEditor(j);
        });
      }
      function renderEditor(j) {
        var h = '';
        if (j.unsupported) {
          h = '<div class="muted" style="font-size:12px">El tipo <code>'+esc(j.type)+'</code> no se puede editar aquí todavía. '
            + 'Puedes consultarlo desde la <b>Consola</b> (por ejemplo <code>XRANGE '+esc(j.key)+' - +</code>).</div>';
        } else if (j.type === 'string') {
          h = '<label>Valor</label><textarea class="rval" id="rdStr"'+(j.truncated?' readonly':'')+'>'+esc(j.value)+'</textarea>';
          if (j.truncated) {
            h += '<div class="msgtext warn" style="margin-top:6px">Valor demasiado grande ('+j.len+' bytes): se muestran los primeros 256 KB '
               + 'y la edición está desactivada para no guardar encima una versión recortada.</div>';
          } else {
            h += '<div style="margin-top:8px"><button type="button" class="btn sm" id="rdStrSave">Guardar valor</button></div>';
          }
        } else {
          // hash/zset traen pares {k,v}; list/set traen valores planos (el "campo" es el indice
          // en las listas y el propio valor en los sets).
          var pares = (j.type==='hash' || j.type==='zset');
          var c1 = j.type==='hash' ? 'Campo' : (j.type==='zset' ? 'Miembro' : (j.type==='list' ? '#' : 'Valor'));
          var c2 = j.type==='zset' ? 'Score' : 'Valor';
          h += '<div class="sqlgrid" style="max-height:40vh"><table class="sqltbl"><thead><tr>'
             + '<th style="width:34%">'+c1+'</th><th>'+(pares||j.type==='list'?c2:'')+'</th><th style="width:120px"></th>'
             + '</tr></thead><tbody>';
          (j.items||[]).forEach(function(it, i){
            var campo, valor;
            if (pares)                 { campo = it.k; valor = it.v; }
            else if (j.type==='list')  { campo = String(i); valor = it; }
            else                       { campo = it; valor = ''; }
            // En un set el miembro ES el valor: se pinta solo en la primera columna, no repetido.
            h += '<tr><td><code>'+esc(campo)+'</code></td>'
               + '<td>'+(j.type==='set' ? '' : '<code>'+esc(valor)+'</code>')+'</td>'
               + '<td><button type="button" class="btn ghost sm rdEd" data-f="'+esc(campo)+'" data-v="'+esc(j.type==='set'?campo:valor)+'">Editar</button> '
               + '<button type="button" class="btn danger sm rdRm" data-f="'+esc(campo)+'">Quitar</button></td></tr>';
          });
          h += '</tbody></table></div>';
          if ((j.count||0) > (j.items||[]).length) {
            h += '<div class="muted" style="margin-top:6px;font-size:11.5px">Mostrando '+(j.items||[]).length+' de '+j.count
               + ' elementos (se limita a 1000 para no bloquear el navegador).</div>';
          }
          h += '<div class="row" style="gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:10px">';
          // El campo extra solo tiene sentido en hash (nombre del campo) y zset (miembro). En una
          // lista se anade con RPUSH al final -- pedir un indice enganaria -- y en un set el
          // miembro es el propio valor.
          if (j.type==='hash' || j.type==='zset') { h += '<div><label>'+c1+'</label><input type="text" id="rdNewF"></div>'; }
          h += '<div style="flex:1;min-width:160px"><label>'+(j.type==='zset'?'Score':'Valor')+'</label><input type="text" id="rdNewV" style="width:100%"></div>'
             + '<button type="button" class="btn sm" id="rdAdd">'+(j.type==='list'?'Añadir al final':'Añadir')+'</button></div>';
        }
        $('rdEditor').innerHTML = h;

        if ($('rdStrSave')) {
          $('rdStrSave').addEventListener('click', function(){
            api('edit', { key: curKey, type: 'string', value: $('rdStr').value })
              .then(function(r){ dmsg(r.error || 'Valor guardado.', !r.error); });
          });
        }
        if ($('rdAdd')) {
          $('rdAdd').addEventListener('click', function(){
            var f = $('rdNewF') ? $('rdNewF').value : '';
            var v = $('rdNewV').value;
            // En un set el "valor" es el propio miembro; en un zset el campo es el miembro y el
            // valor es el score (asi es como los espera el endpoint).
            if (curType==='set') { f = ''; }
            api('additem', { key: curKey, type: curType, field: f, value: v })
              .then(function(r){ if (r.error) { dmsg(r.error, false); } else { openKey(curKey); dmsg('Añadido.', true); } });
          });
        }
        $('rdEditor').querySelectorAll('.rdEd').forEach(function(b){
          b.addEventListener('click', function(){
            var actual = b.dataset.v;
            var etiqueta = curType==='zset' ? 'Nuevo score para "'+b.dataset.f+'"' : 'Nuevo valor para "'+b.dataset.f+'"';
            var nv = window.prompt(etiqueta, actual);
            if (nv === null) return;
            api('edit', { key: curKey, type: curType, field: b.dataset.f, value: nv })
              .then(function(r){ if (r.error) { dmsg(r.error, false); } else { openKey(curKey); dmsg('Guardado.', true); } });
          });
        });
        $('rdEditor').querySelectorAll('.rdRm').forEach(function(b){
          b.addEventListener('click', function(){
            api('delitem', { key: curKey, type: curType, field: b.dataset.f })
              .then(function(r){ if (r.error) { dmsg(r.error, false); } else { openKey(curKey); dmsg('Elemento quitado.', true); } });
          });
        });
      }

      $('rdTtlSave').addEventListener('click', function(){
        var v = $('rdTtl').value.trim();
        // Vacio = sin expiracion. El endpoint traduce <=0 a PERSIST, porque EXPIRE 0 borraria
        // la clave, que no es lo que espera nadie al vaciar este campo.
        var sec = v === '' ? -1 : parseInt(v, 10);
        if (v !== '' && (isNaN(sec) || sec < 1)) { dmsg('El TTL tiene que ser un número de segundos mayor que 0, o vacío para quitarlo.', false); return; }
        api('ttl', { key: curKey, seconds: sec }).then(function(r){
          if (r.error) { dmsg(r.error, false); return; }
          dmsg(sec > 0 ? 'TTL puesto en ' + sec + 's.' : 'Expiración quitada.', true);
          openKey(curKey); search(false);
        });
      });
      $('rdRenameSave').addEventListener('click', function(){
        var to = $('rdRename').value.trim();
        if (to === '') { dmsg('Escribe el nombre nuevo.', false); return; }
        api('rename', { key: curKey, to: to }).then(function(r){
          if (r.error) { dmsg(r.error, false); return; }
          dmsg('Renombrada.', true); search(false); openKey(to);
        });
      });

      // ---- borrar clave (modal propio, no confirm()) ----
      var delKey = null;
      $('rdDel').addEventListener('click', function(){
        delKey = curKey;
        $('rdDelName').textContent = curKey;
        $('rdDelModal').hidden = false;
        document.addEventListener('keydown', escDel);
      });
      function escDel(e){ if (e.key==='Escape') rdCloseDel(); }
      window.rdCloseDel = function(){ $('rdDelModal').hidden = true; document.removeEventListener('keydown', escDel); };
      $('rdDelYes').addEventListener('click', function(){
        rdCloseDel();
        api('del', { keys: [delKey] }).then(function(r){
          if (r.error) { dmsg(r.error, false); return; }
          hideDetail(); loadDbs(); search(false);
        });
      });

      // ---- vaciar base ----
      $('rdFlush').addEventListener('click', function(){
        var b = $('rdDbs').querySelector('button.on');
        var n = b ? b.querySelector('.n').textContent : '?';
        $('rdFlushDb').textContent = 'db' + db;
        $('rdFlushN').textContent = n + ' clave(s)';
        $('rdFlushModal').hidden = false;
        document.addEventListener('keydown', escFlush);
      });
      function escFlush(e){ if (e.key==='Escape') rdCloseFlush(); }
      window.rdCloseFlush = function(){ $('rdFlushModal').hidden = true; document.removeEventListener('keydown', escFlush); };
      $('rdFlushYes').addEventListener('click', function(){
        rdCloseFlush();
        api('flushdb').then(function(){ hideDetail(); loadDbs(); search(false); });
      });

      // ---- consola ----
      function consPrint(html){ var o=$('rdConsOut'); o.insertAdjacentHTML('beforeend', html); o.scrollTop = o.scrollHeight; }
      function fmt(v, depth) {
        depth = depth || 0;
        if (v === null) return '<span class="cnil">(nil)</span>';
        if (typeof v === 'object' && v.__nil) return '<span class="cnil">(nil)</span>';
        if (Array.isArray(v)) {
          if (!v.length) return '<span class="cnil">(lista vacía)</span>';
          return v.map(function(x,i){ return '\n' + '  '.repeat(depth+1) + (i+1) + ') ' + fmt(x, depth+1); }).join('');
        }
        return esc(v);
      }
      function runCmd() {
        var line = $rdIn().value.trim();
        if (!line) return;
        hist.push(line); histIx = hist.length;
        $rdIn().value = '';
        if ($('rdConsOut').querySelector('.muted')) { $('rdConsOut').innerHTML = ''; }
        consPrint('<div><span class="cin">db'+db+'&gt; '+esc(line)+'</span></div>');
        api('cmd', { line: line }).then(function(r){
          if (r.error)     { consPrint('<div class="cerr">'+esc(r.error)+'</div>'); return; }
          if (r.err)       { consPrint('<div class="cerr">(error) '+esc(r.err)+'</div>'); return; }
          consPrint('<div>'+fmt(r.result)+'</div>');
          // Un comando de la consola puede haber creado o borrado claves: refrescar contadores.
          loadDbs();
        });
      }
      function $rdIn(){ return $('rdConsIn'); }
      $('rdConsRun').addEventListener('click', runCmd);
      $('rdConsClear').addEventListener('click', function(){ $('rdConsOut').innerHTML = '<span class="muted">Consola limpia.</span>'; });
      $rdIn().addEventListener('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); runCmd(); }
        else if (e.key === 'ArrowUp')   { e.preventDefault(); if (histIx > 0) { $rdIn().value = hist[--histIx]; } }
        else if (e.key === 'ArrowDown') { e.preventDefault(); if (histIx < hist.length-1) { $rdIn().value = hist[++histIx]; } else { histIx = hist.length; $rdIn().value = ''; } }
      });

      // ---- servidor ----
      function loadInfo() {
        api('info').then(function(j){
          if (j.error) { $('rdInfoBoxes').innerHTML = '<div class="sqlx-empty">'+esc(j.error)+'</div>'; return; }
          var i = j.info;
          var hits = parseInt(i.keyspace_hits||0,10), miss = parseInt(i.keyspace_misses||0,10);
          var ratio = (hits+miss) > 0 ? Math.round(hits*100/(hits+miss)) + '%' : '—';
          var up = parseInt(i.uptime_in_seconds||0,10);
          var upTxt = up >= 86400 ? Math.floor(up/86400)+'d' : (up >= 3600 ? Math.floor(up/3600)+'h' : Math.floor(up/60)+'m');
          var cajas = [
            ['Versión', i.redis_version || '?'],
            ['Modo', i.redis_mode || '?'],
            ['Memoria usada', i.used_memory_human || '?'],
            ['Pico de memoria', i.used_memory_peak_human || '?'],
            ['Clientes', i.connected_clients || '0'],
            ['Uptime', upTxt],
            ['Aciertos de caché', ratio],
            ['Comandos procesados', i.total_commands_processed || '0'],
            ['Claves con TTL vencido', i.expired_keys || '0'],
            ['Claves desalojadas', i.evicted_keys || '0']
          ];
          $('rdInfoBoxes').innerHTML = cajas.map(function(c){
            return '<div class="b"><div class="l">'+esc(c[0])+'</div><div class="v">'+esc(c[1])+'</div></div>';
          }).join('');
          $('rdInfoRaw').textContent = j.raw;
        });
      }

      loadDbs();
      search(false);
    })();
    </script>
    <?php endif; ?>

  <?php elseif ($tab==='sqlsrv'): /* ---------- PESTAÑA SQL SERVER ---------- */
      $sqlServers = sqlsrv_servers($ROOT);
      $sqlSel  = (string)($_GET['conn'] ?? '');
      $sqlSrv  = valid_sqlsrv_id($sqlSel) ? sqlsrv_find($ROOT, $sqlSel) : null;
      if (!$sqlSrv && $sqlServers) { $sqlSrv = $sqlServers[0]; }
      $sqlEditSrv = valid_sqlsrv_id((string)($_GET['edit'] ?? '')) ? sqlsrv_find($ROOT, $_GET['edit']) : null;
      $sqlForm = $sqlEditSrv !== null || isset($_GET['nueva']) || !$sqlServers;
      $sqlDrv  = sqlsrv_driver_kind() === 'sqlsrv' ? 'pdo_sqlsrv' : 'pdo_odbc · '.sqlsrv_odbc_driver();
      $sqlOk   = extension_loaded('pdo_odbc') || extension_loaded('pdo_sqlsrv'); ?>

    <?php if (!$sqlOk): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:6px">Falta el driver de SQL Server</div>
        <div class="muted">Ni <code>pdo_sqlsrv</code> ni <code>pdo_odbc</code> están cargados en el PHP del panel
          (<?= e(PHP_VERSION) ?>). Añade <code>pdo_odbc</code> en <a href="?tab=php">Versiones PHP</a> y reinicia el servidor.</div>
      </div>
    <?php else: ?>

      <div class="card row" style="flex-wrap:wrap;gap:8px">
        <div style="min-width:220px">
          <div style="font-weight:600">Servidores SQL Server</div>
          <div class="muted" style="margin-top:4px">Conecta con un SQL Server existente (local o de red). Driver: <code><?= e($sqlDrv) ?></code></div>
        </div>
        <div class="spacer"></div>
        <?php foreach ($sqlServers as $s): ?>
          <a class="btn <?= ($sqlSrv && $s['id']===$sqlSrv['id'] && !$sqlForm) ? '' : 'ghost' ?> sm"
             href="?tab=sqlsrv&conn=<?= e(rawurlencode($s['id'])) ?>"><?= e($s['label']) ?></a>
        <?php endforeach; ?>
        <a class="btn ghost sm" href="?tab=sqlsrv&nueva=1">+ Añadir conexión</a>
      </div>

      <?php if ($sqlForm): ?>
        <div class="card">
          <div style="font-weight:600;margin-bottom:12px"><?= $sqlEditSrv ? 'Editar conexión' : 'Nueva conexión' ?></div>
          <form method="post" class="inline">
            <input type="hidden" name="action" value="sqlsrv_save">
            <?php if ($sqlEditSrv): ?><input type="hidden" name="id" value="<?= e($sqlEditSrv['id']) ?>"><?php endif; ?>
            <div><label>Nombre</label><input type="text" name="label" placeholder="Producción" value="<?= e($sqlEditSrv['label'] ?? '') ?>"></div>
            <div><label>Host o IP</label><input type="text" name="host" placeholder="127.0.0.1" value="<?= e($sqlEditSrv['host'] ?? '') ?>" required></div>
            <div style="max-width:110px"><label>Puerto</label><input type="text" name="port" value="<?= e((string)($sqlEditSrv['port'] ?? 1433)) ?>" required></div>
            <div><label>Usuario</label><input type="text" name="user" placeholder="sa" value="<?= e($sqlEditSrv['user'] ?? '') ?>" required></div>
            <div><label>Contraseña</label>
              <input type="password" name="pass" autocomplete="new-password" placeholder="<?= $sqlEditSrv ? 'dejar vacío para no cambiarla' : '' ?>" <?= $sqlEditSrv ? '' : 'required' ?>>
            </div>
            <div><label>Certificado</label>
              <select name="trust">
                <option value="1" <?= (!$sqlEditSrv || !empty($sqlEditSrv['trust'])) ? 'selected' : '' ?>>Confiar sin validar</option>
                <option value="0" <?= ($sqlEditSrv && empty($sqlEditSrv['trust'])) ? 'selected' : '' ?>>Validar el certificado</option>
              </select>
            </div>
            <button class="btn" type="submit">Guardar y probar</button>
            <?php if ($sqlEditSrv): ?><a class="btn ghost" href="?tab=sqlsrv&conn=<?= e(rawurlencode($sqlEditSrv['id'])) ?>">Cancelar</a><?php endif; ?>
          </form>
          <div class="muted" style="margin-top:10px;font-size:12px">
            La contraseña se guarda en claro en <code>config\sqlsrv-servers.json</code> (fuera de git), igual que
            <code>mysql_root.pass</code>. El panel solo escucha en <code>127.0.0.1</code>.
            <?php if (sqlsrv_driver_kind() !== 'sqlsrv'): ?> Con <code>pdo_odbc</code>, "Validar el certificado" requiere que el certificado del servidor sea de confianza para Windows.<?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($sqlSrv && !$sqlForm): ?>
        <div class="card row" style="gap:8px;flex-wrap:wrap">
          <div class="muted" style="font-size:12.5px">
            <b style="color:var(--tx)"><?= e($sqlSrv['label']) ?></b> &middot;
            <code><?= e($sqlSrv['host']) ?>:<?= e((string)$sqlSrv['port']) ?></code> &middot; usuario <code><?= e($sqlSrv['user']) ?></code>
          </div>
          <div class="spacer"></div>
          <form method="post" style="display:inline"><input type="hidden" name="action" value="sqlsrv_test">
            <input type="hidden" name="id" value="<?= e($sqlSrv['id']) ?>">
            <button class="btn ghost sm" type="submit">Probar conexión</button></form>
          <a class="btn ghost sm" href="?tab=sqlsrv&edit=<?= e(rawurlencode($sqlSrv['id'])) ?>">Editar</a>
          <button type="button" class="btn danger sm" onclick="luaAskDelConn('<?= e($sqlSrv['id']) ?>','<?= e(addslashes($sqlSrv['label'])) ?>')">Eliminar</button>
        </div>

        <div class="sqlx" id="sqlx" data-conn="<?= e($sqlSrv['id']) ?>">
          <div class="sqlx-side">
            <div class="card">
              <label style="font-size:12px;color:var(--mut)">Base de datos</label>
              <select id="sqDb" style="width:100%;margin-top:4px"><option value="">cargando…</option></select>
              <input type="search" id="sqFilter" placeholder="Filtrar tablas…" style="width:100%;margin-top:8px;font-size:12.5px">
              <div class="sqlx-tables" id="sqTables"><div class="sqlx-empty">Elige una base de datos.</div></div>
            </div>
          </div>

          <div class="sqlx-main">
            <div class="card">
              <div class="sqlx-views">
                <button type="button" data-view="datos" class="on">Datos</button>
                <button type="button" data-view="estructura">Estructura</button>
                <button type="button" data-view="sql">SQL</button>
              </div>
              <div id="sqMsg"></div>
              <div id="sqPanelDatos">
                <div class="sqlx-empty" id="sqDatosVacio">Elige una tabla en la barra lateral.</div>
                <div id="sqDatosWrap" hidden>
                  <div class="row" style="gap:8px;margin-bottom:10px;flex-wrap:wrap">
                    <span id="sqTitulo" style="font-weight:600;font-family:ui-monospace,Consolas,monospace;font-size:13px"></span>
                    <div class="spacer"></div>
                    <button type="button" class="btn ghost sm" id="sqNuevaFila">+ Nueva fila</button>
                    <button type="button" class="btn ghost sm" id="sqRecargar">Recargar</button>
                  </div>
                  <div class="sqlgrid"><table class="sqltbl" id="sqTabla"><thead></thead><tbody></tbody></table></div>
                  <div class="sqlpager">
                    <button type="button" class="btn ghost sm" id="sqPrev">&larr; Anterior</button>
                    <button type="button" class="btn ghost sm" id="sqNext">Siguiente &rarr;</button>
                    <span id="sqInfo"></span>
                    <div class="spacer"></div>
                    <label style="font-size:12px">Filas
                      <select id="sqPer" style="margin-left:4px">
                        <option>25</option><option selected>50</option><option>100</option><option>250</option>
                      </select>
                    </label>
                  </div>
                </div>
              </div>
              <div id="sqPanelEstructura" hidden><div class="sqlx-empty">Elige una tabla en la barra lateral.</div></div>
              <div id="sqPanelSql" hidden>
                <div id="sqlEditorHost"><textarea id="sqEditor"></textarea></div>
                <div class="row" style="gap:8px;margin-top:10px;flex-wrap:wrap">
                  <button type="button" class="btn" id="sqRun">Ejecutar</button>
                  <span class="muted" style="font-size:12px">Ctrl+Enter</span>
                  <div class="spacer"></div>
                  <select id="sqHist" style="max-width:320px;font-size:12px"><option value="">Historial…</option></select>
                </div>
                <?php if (sqlsrv_driver_kind() !== 'sqlsrv'): ?>
                  <div class="muted" style="font-size:11.5px;margin-top:8px">
                    Con <code>pdo_odbc</code>, el texto que devuelve una consulta libre pasa por la conversión ANSI del driver:
                    se recupera todo lo que sea Windows-1252 (acentos, ñ, €), pero un carácter fuera de esa tabla llegaría como <code>?</code>.
                    La pestaña <b>Datos</b> no tiene esa limitación: ahí el texto viaja en binario y es exacto.
                  </div>
                <?php endif; ?>
                <div id="sqResultados" style="margin-top:12px"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Modal: editar / insertar fila -->
        <div id="sqRowModal" class="modal-overlay" hidden onclick="if(event.target===this)sqCloseRow()">
          <div class="modal-box" role="dialog" aria-modal="true" style="max-width:680px;text-align:left">
            <div class="row" style="margin-bottom:12px">
              <h3 id="sqRowTitle" style="margin:0;font-size:16px">Editar fila</h3>
              <div class="spacer"></div>
              <button type="button" class="lockbtn" onclick="sqCloseRow()" title="Cerrar" aria-label="Cerrar">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
            <div id="sqRowMsg"></div>
            <div id="sqRowFields" style="max-height:56vh;overflow:auto"></div>
            <div class="modal-actions" style="margin-top:14px">
              <button type="button" class="btn ghost" onclick="sqCloseRow()">Cancelar</button>
              <button type="button" class="btn" id="sqRowSave">Guardar</button>
            </div>
          </div>
        </div>

        <!-- Modal: confirmar borrado de fila -->
        <div id="sqDelModal" class="modal-overlay" hidden onclick="if(event.target===this)sqCloseDel()">
          <div class="modal-box" role="dialog" aria-modal="true">
            <div class="modal-ic">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>
              </svg>
            </div>
            <h3>¿Borrar esta fila?</h3>
            <p class="modal-tx">Se eliminará permanentemente la fila <strong id="sqDelWhere"></strong>. No se puede deshacer.</p>
            <div class="modal-actions">
              <button type="button" class="btn ghost" onclick="sqCloseDel()">Cancelar</button>
              <button type="button" class="btn danger" id="sqDelOk">Sí, borrar</button>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Modal: confirmar borrado de conexion -->
      <div id="sqConnModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDelConn()">
        <div class="modal-box" role="dialog" aria-modal="true">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            </svg>
          </div>
          <h3>¿Eliminar la conexión?</h3>
          <p class="modal-tx">Se quitará <strong id="sqConnName"></strong> de la lista del panel. <b>No se toca nada en el servidor</b>: solo se borran los datos de conexión guardados aquí.</p>
          <form method="post" class="modal-actions">
            <input type="hidden" name="action" value="sqlsrv_del">
            <input type="hidden" name="id" id="sqConnId">
            <button type="button" class="btn ghost" onclick="luaCloseDelConn()">Cancelar</button>
            <button type="submit" class="btn danger">Sí, eliminar</button>
          </form>
        </div>
      </div>
      <script>
        function luaAskDelConn(id, label){
          document.getElementById('sqConnName').textContent = label;
          document.getElementById('sqConnId').value = id;
          document.getElementById('sqConnModal').hidden = false;
          document.addEventListener('keydown', luaEscDelConn);
        }
        function luaCloseDelConn(){
          document.getElementById('sqConnModal').hidden = true;
          document.removeEventListener('keydown', luaEscDelConn);
        }
        function luaEscDelConn(e){ if(e.key==='Escape') luaCloseDelConn(); }
      </script>

      <?php if ($sqlSrv && !$sqlForm): ?>
      <link rel="stylesheet" href="assets/codemirror/lib/codemirror.css">
      <script src="assets/codemirror/lib/codemirror.js"></script>
      <script src="assets/codemirror/addon/edit/matchbrackets.js"></script>
      <script src="assets/codemirror/mode/sql/sql.js"></script>
      <script>
      (function(){
        var root = document.getElementById('sqlx');
        if (!root) return;
        var S = { conn: root.dataset.conn, db:'', schema:'', table:'', kind:'', label:'',
                  page:1, per:50, sort:'', dir:'asc', cols:[], pk:[], editable:false, rows:[] };
        var elDb=document.getElementById('sqDb'), elTables=document.getElementById('sqTables'),
            elFilter=document.getElementById('sqFilter'), elMsg=document.getElementById('sqMsg'),
            elTabla=document.getElementById('sqTabla'), elInfo=document.getElementById('sqInfo'),
            elTitulo=document.getElementById('sqTitulo'), elWrap=document.getElementById('sqDatosWrap'),
            elVacio=document.getElementById('sqDatosVacio');
        var todasTablas = [];

        function api(params, body){
          var qs = new URLSearchParams(Object.assign({ajax:'sqlsrv', conn:S.conn}, params));
          var opt = body ? {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams(body)} : {};
          return fetch('?'+qs.toString(), opt).then(function(r){ return r.json(); });
        }
        // Los mensajes siempre por textContent: traen texto del servidor SQL (y de las tablas
        // del usuario), que puede contener < > y comillas.
        function msg(txt, tipo){
          elMsg.innerHTML='';
          if(!txt) return;
          var d=document.createElement('div'); d.className='sqlmsg '+(tipo||'err'); d.textContent=txt;
          elMsg.appendChild(d);
        }
        function celda(v, meta){
          var td=document.createElement('td');
          if(v===null){ var s=document.createElement('span'); s.className='sqlnull'; s.textContent='NULL'; td.appendChild(s); return td; }
          if(meta && meta.bin){ var b=document.createElement('span'); b.className='sqlbin'; b.textContent='0x'+v; td.appendChild(b); td.title='0x'+v; return td; }
          var t=String(v);
          td.textContent = t.length>300 ? t.slice(0,300)+'…' : t;
          if(t.length>60) td.title=t;
          return td;
        }

        // ---- barra lateral ----
        function cargarDbs(){
          api({op:'dbs'}).then(function(j){
            elDb.innerHTML='';
            if(j.error){ msg(j.error); elDb.innerHTML='<option value="">(error)</option>'; return; }
            var o=document.createElement('option'); o.value=''; o.textContent='elige…'; elDb.appendChild(o);
            (j.dbs||[]).forEach(function(d){
              var op=document.createElement('option'); op.value=d.name;
              op.textContent = d.name + (d.sys ? '  (sistema)' : '');
              elDb.appendChild(op);
            });
            var guardada = sessionStorage.getItem('sqdb_'+S.conn);
            if(guardada && (j.dbs||[]).some(function(d){return d.name===guardada;})){ elDb.value=guardada; cargarTablas(); }
          }).catch(function(){ msg('No se pudo contactar con el panel.'); });
        }
        function cargarTablas(){
          S.db = elDb.value; S.table=''; S.schema='';
          sessionStorage.setItem('sqdb_'+S.conn, S.db);
          elWrap.hidden=true; elVacio.hidden=false;
          if(!S.db){ elTables.innerHTML='<div class="sqlx-empty">Elige una base de datos.</div>'; return; }
          elTables.innerHTML='<div class="sqlx-empty">cargando…</div>';
          api({op:'tables', db:S.db}).then(function(j){
            if(j.error){ elTables.innerHTML=''; msg(j.error); return; }
            todasTablas = j.tables||[];
            pintarTablas();
          });
        }
        function pintarTablas(){
          var f=(elFilter.value||'').toLowerCase();
          elTables.innerHTML='';
          var vis = todasTablas.filter(function(t){ return !f || (t.schema+'.'+t.name).toLowerCase().indexOf(f)>=0; });
          if(!vis.length){ elTables.innerHTML='<div class="sqlx-empty">Sin coincidencias.</div>'; return; }
          vis.forEach(function(t){
            var b=document.createElement('button');
            b.type='button'; b.className='sqlx-t'+(t.kind==='view'?' view':'');
            if(S.table===t.name && S.schema===t.schema) b.classList.add('on');
            var ic=document.createElement('span'); ic.className='ico'; ic.textContent = t.kind==='view' ? '◫' : '▤';
            b.appendChild(ic);
            var nm=document.createElement('span');
            nm.textContent = (t.schema==='dbo'? '' : t.schema+'.') + t.name;
            b.appendChild(nm);
            var n=document.createElement('span'); n.className='n';
            n.textContent = t.kind==='view' ? 'vista' : (t.rows>=0 ? t.rows.toLocaleString('es-ES') : '');
            b.appendChild(n);
            b.onclick=function(){ abrirTabla(t); };
            elTables.appendChild(b);
          });
        }
        function abrirTabla(t){
          S.schema=t.schema; S.table=t.name; S.kind=t.kind; S.page=1; S.sort=''; S.dir='asc';
          S.label=(t.schema==='dbo'?'':t.schema+'.')+t.name;
          pintarTablas();
          if(vistaActual==='estructura') cargarEstructura(); else { mostrarVista('datos'); cargarFilas(); }
        }

        // ---- datos ----
        function cargarFilas(){
          if(!S.table) return;
          msg('');
          elVacio.hidden=true; elWrap.hidden=false;
          elTitulo.textContent=S.label;
          api({op:'rows', db:S.db, schema:S.schema, table:S.table, kind:S.kind,
               page:S.page, per:S.per, sort:S.sort, dir:S.dir}).then(function(j){
            if(j.error){ msg(j.error); return; }
            S.cols=j.cols; S.pk=j.pk||[]; S.editable=!!j.editable; S.rows=j.rows||[];
            S.sort=j.sort; S.dir=j.dir;
            if(j.motivo) msg(j.motivo, 'warn');
            pintarFilas(j);
            document.getElementById('sqNuevaFila').style.display = S.editable ? '' : 'none';
          });
        }
        function pintarFilas(j){
          var thead=elTabla.tHead, tb=elTabla.tBodies[0];
          thead.innerHTML=''; tb.innerHTML='';
          var tr=document.createElement('tr');
          if(S.editable){ var thA=document.createElement('th'); thA.textContent=''; thA.style.cursor='default'; tr.appendChild(thA); }
          S.cols.forEach(function(c){
            var th=document.createElement('th'); th.title=c.type;
            th.appendChild(document.createTextNode(c.name));
            if(S.pk.indexOf(c.name)>=0){ var k=document.createElement('span'); k.className='pkmark'; k.textContent='PK'; th.appendChild(k); }
            if(c.name===S.sort){ var d=document.createElement('span'); d.className='dirmark'; d.textContent = S.dir==='asc'?'▲':'▼'; th.appendChild(d); }
            th.onclick=function(){ if(S.sort===c.name){ S.dir = S.dir==='asc'?'desc':'asc'; } else { S.sort=c.name; S.dir='asc'; } S.page=1; cargarFilas(); };
            tr.appendChild(th);
          });
          thead.appendChild(tr);
          S.rows.forEach(function(fila, idx){
            var r=document.createElement('tr');
            if(S.editable){
              var td=document.createElement('td'); td.className='acts';
              var be=document.createElement('button'); be.type='button'; be.className='sqlrowbtn'; be.textContent='Editar';
              be.onclick=function(){ abrirFila(idx); };
              var bd=document.createElement('button'); bd.type='button'; bd.className='sqlrowbtn del'; bd.textContent='Borrar';
              bd.style.marginLeft='4px';
              bd.onclick=function(){ pedirBorrado(idx); };
              td.appendChild(be); td.appendChild(bd); r.appendChild(td);
            }
            fila.forEach(function(v,i){ r.appendChild(celda(v, S.cols[i])); });
            tb.appendChild(r);
          });
          var desde=(j.page-1)*j.per+1, hasta=Math.min(j.page*j.per, j.total);
          elInfo.textContent = j.total ? (desde+'–'+hasta+' de '+(j.aprox?'~':'')+j.total.toLocaleString('es-ES')) : 'sin filas';
          document.getElementById('sqPrev').disabled = j.page<=1;
          document.getElementById('sqNext').disabled = hasta>=j.total;
        }

        // ---- estructura ----
        function cargarEstructura(){
          var p=document.getElementById('sqPanelEstructura');
          if(!S.table){ p.innerHTML='<div class="sqlx-empty">Elige una tabla en la barra lateral.</div>'; return; }
          p.innerHTML='<div class="sqlx-empty">cargando…</div>';
          api({op:'struct', db:S.db, schema:S.schema, table:S.table}).then(function(j){
            p.innerHTML='';
            if(j.error){ msg(j.error); return; }
            var h=document.createElement('div');
            h.style.cssText='font-weight:600;font-family:ui-monospace,Consolas,monospace;font-size:13px;margin-bottom:10px';
            h.textContent=S.label; p.appendChild(h);
            var g=document.createElement('div'); g.className='sqlgrid';
            var t=document.createElement('table'); t.className='sqltbl';
            var th=document.createElement('thead'); var tr=document.createElement('tr');
            ['Columna','Tipo','Nulos','Por defecto','Notas'].forEach(function(x){
              var c=document.createElement('th'); c.textContent=x; c.style.cursor='default'; tr.appendChild(c);
            });
            th.appendChild(tr); t.appendChild(th);
            var tb=document.createElement('tbody');
            (j.cols||[]).forEach(function(c){
              var r=document.createElement('tr');
              function td(txt, cls){ var d=document.createElement('td'); if(cls) d.className=cls; d.textContent=txt; r.appendChild(d); return d; }
              var d0=td(c.name); if((j.pk||[]).indexOf(c.name)>=0){ var k=document.createElement('span'); k.className='pkmark'; k.textContent=' PK'; d0.appendChild(k); }
              td(c.type);
              td(c.nullable ? 'sí' : 'no');
              if(c.default===null||c.default===undefined) td('—','sqlnull'); else td(c.default);
              var notas=[]; if(c.identity) notas.push('IDENTITY'); if(c.computed) notas.push('calculada');
              td(notas.join(', ') || '—');
              tb.appendChild(r);
            });
            t.appendChild(tb); g.appendChild(t); p.appendChild(g);
            var idx=j.indexes||[];
            var ht=document.createElement('div');
            ht.style.cssText='font-weight:600;font-size:13px;margin:16px 0 8px';
            ht.textContent='Índices ('+idx.length+')'; p.appendChild(ht);
            if(!idx.length){ var e0=document.createElement('div'); e0.className='sqlx-empty'; e0.textContent='Esta tabla no tiene índices.'; p.appendChild(e0); return; }
            var g2=document.createElement('div'); g2.className='sqlgrid';
            var t2=document.createElement('table'); t2.className='sqltbl';
            var th2=document.createElement('thead'); var tr2=document.createElement('tr');
            ['Índice','Columnas','Único','Tipo'].forEach(function(x){ var c=document.createElement('th'); c.textContent=x; c.style.cursor='default'; tr2.appendChild(c); });
            th2.appendChild(tr2); t2.appendChild(th2);
            var tb2=document.createElement('tbody');
            idx.forEach(function(i){
              var r=document.createElement('tr');
              function td(txt){ var d=document.createElement('td'); d.textContent=txt; r.appendChild(d); }
              td(i.name + (i.pk?'  (clave primaria)':''));
              td((i.cols||[]).join(', '));
              td(i.unique?'sí':'no');
              td(i.type);
              tb2.appendChild(r);
            });
            t2.appendChild(tb2); g2.appendChild(t2); p.appendChild(g2);
          });
        }

        // ---- edicion de filas ----
        var filaEditada = null;
        function pkDe(idx){
          var o={};
          S.pk.forEach(function(k){
            var i = S.cols.findIndex(function(c){ return c.name===k; });
            o[k] = i>=0 ? S.rows[idx][i] : null;
          });
          return o;
        }
        function abrirFila(idx){
          filaEditada = (idx===null) ? null : {idx:idx, pk:pkDe(idx)};
          document.getElementById('sqRowTitle').textContent = (idx===null?'Nueva fila en ':'Editar fila de ')+S.label;
          document.getElementById('sqRowMsg').innerHTML='';
          var cont=document.getElementById('sqRowFields'); cont.innerHTML='';
          S.cols.forEach(function(c,i){
            var val = idx===null ? null : S.rows[idx][i];
            var f=document.createElement('div'); f.className='sqlfield';
            var lab=document.createElement('label');
            lab.textContent=c.name+'  ·  '+c.type+(c.nullable?'':'  · obligatorio');
            f.appendChild(lab);
            if(c.identity || c.computed){
              var ro=document.createElement('div'); ro.className='meta';
              ro.textContent = (c.identity?'IDENTITY':'Calculada')+': lo genera el servidor'+(val!==null&&idx!==null?'  (actual: '+val+')':'');
              f.appendChild(ro); cont.appendChild(f); return;
            }
            var line=document.createElement('div'); line.className='rowline';
            var largo = /max|text|xml/i.test(c.type) || (val!==null && String(val).length>120);
            var input = document.createElement(largo?'textarea':'input');
            if(!largo) input.type='text';
            input.dataset.col=c.name;
            input.value = val===null ? '' : String(val);
            line.appendChild(input);
            if(c.nullable){
              var w=document.createElement('label'); w.className='sqlnullbox';
              var cb=document.createElement('input'); cb.type='checkbox'; cb.dataset.nullFor=c.name;
              cb.checked = (val===null);
              input.disabled = cb.checked;
              cb.onchange=function(){ input.disabled=cb.checked; if(cb.checked) input.value=''; };
              w.appendChild(cb); w.appendChild(document.createTextNode('NULL'));
              line.appendChild(w);
            }
            f.appendChild(line);
            if(c.bin){ var m=document.createElement('div'); m.className='meta'; m.textContent='Binario: en hexadecimal (p. ej. 0xDEADBEEF)'; f.appendChild(m); }
            cont.appendChild(f);
          });
          document.getElementById('sqRowModal').hidden=false;
          document.addEventListener('keydown', escRow);
        }
        window.sqCloseRow=function(){ document.getElementById('sqRowModal').hidden=true; document.removeEventListener('keydown', escRow); };
        function escRow(e){ if(e.key==='Escape') sqCloseRow(); }
        document.getElementById('sqRowSave').onclick=function(){
          var btn=this; var vals={}, nulls=[];
          document.querySelectorAll('#sqRowFields [data-col]').forEach(function(inp){
            var cb=document.querySelector('#sqRowFields [data-null-for="'+CSS.escape(inp.dataset.col)+'"]');
            if(cb && cb.checked) nulls.push(inp.dataset.col); else vals[inp.dataset.col]=inp.value;
          });
          btn.disabled=true;
          var body={op:'row_save', modo: filaEditada?'update':'insert', vals:JSON.stringify(vals), nulls:JSON.stringify(nulls)};
          if(filaEditada) body.pk=JSON.stringify(filaEditada.pk);
          api({op:'row_save', db:S.db, schema:S.schema, table:S.table}, body).then(function(j){
            btn.disabled=false;
            if(j.error){
              var m=document.getElementById('sqRowMsg'); m.innerHTML='';
              var d=document.createElement('div'); d.className='sqlmsg err'; d.textContent=j.error; m.appendChild(d);
              return;
            }
            sqCloseRow();
            cargarFilas();
            if(j.aviso) msg(j.aviso,'warn'); else msg(filaEditada?'Fila actualizada.':'Fila insertada.','ok');
          }).catch(function(){ btn.disabled=false; });
        };
        document.getElementById('sqNuevaFila').onclick=function(){ abrirFila(null); };

        var borrando=null;
        function pedirBorrado(idx){
          borrando=pkDe(idx);
          document.getElementById('sqDelWhere').textContent = Object.keys(borrando).map(function(k){ return k+'='+(borrando[k]===null?'NULL':borrando[k]); }).join(', ');
          document.getElementById('sqDelModal').hidden=false;
          document.addEventListener('keydown', escDel);
        }
        window.sqCloseDel=function(){ document.getElementById('sqDelModal').hidden=true; document.removeEventListener('keydown', escDel); };
        function escDel(e){ if(e.key==='Escape') sqCloseDel(); }
        document.getElementById('sqDelOk').onclick=function(){
          if(!borrando) return;
          var btn=this; btn.disabled=true;
          api({op:'row_del', db:S.db, schema:S.schema, table:S.table},
              {op:'row_del', pk:JSON.stringify(borrando)}).then(function(j){
            btn.disabled=false; sqCloseDel();
            if(j.error){ msg(j.error); return; }
            cargarFilas();
            msg(j.aviso || 'Fila borrada.', j.aviso?'warn':'ok');
          }).catch(function(){ btn.disabled=false; });
        };

        // ---- consola SQL ----
        var cm=null, HKEY='sqlsrv_hist_'+S.conn;
        function initEditor(){
          if(cm) return;
          cm = CodeMirror.fromTextArea(document.getElementById('sqEditor'), {
            mode:'text/x-mssql', theme:'lua', lineNumbers:true, matchBrackets:true, lineWrapping:true
          });
          cm.setValue('SELECT TOP 100 * FROM ');
          cm.on('keydown', function(inst, e){
            if((e.ctrlKey||e.metaKey) && e.key==='Enter'){ e.preventDefault(); ejecutar(); }
          });
          pintarHistorial();
        }
        function historial(){ try{ return JSON.parse(localStorage.getItem(HKEY)||'[]'); }catch(e){ return []; } }
        function pintarHistorial(){
          var sel=document.getElementById('sqHist'); sel.innerHTML='';
          var o=document.createElement('option'); o.value=''; o.textContent='Historial…'; sel.appendChild(o);
          historial().forEach(function(q){
            var op=document.createElement('option'); op.value=q;
            op.textContent = q.length>70 ? q.slice(0,70)+'…' : q;
            sel.appendChild(op);
          });
          sel.onchange=function(){ if(sel.value){ cm.setValue(sel.value); sel.value=''; cm.focus(); } };
        }
        function guardarHist(q){
          var h=historial().filter(function(x){ return x!==q; });
          h.unshift(q); h=h.slice(0,25);
          try{ localStorage.setItem(HKEY, JSON.stringify(h)); }catch(e){}
          pintarHistorial();
        }
        function ejecutar(){
          var sql=cm.getValue().trim();
          if(!sql) return;
          var out=document.getElementById('sqResultados');
          out.innerHTML=''; msg('');
          var wait=document.createElement('div'); wait.className='sqlx-empty'; wait.textContent='ejecutando…'; out.appendChild(wait);
          api({op:'query', db:S.db}, {op:'query', sql:sql}).then(function(j){
            out.innerHTML='';
            if(j.error){ msg(j.error); return; }
            guardarHist(sql);
            var res=document.createElement('div'); res.className='sqlmsg ok';
            res.textContent = (j.sets.length? j.sets.length+' conjunto(s) de resultados. ' : '')
                            + (j.afectadas? j.afectadas+' fila(s) afectada(s). ' : '')
                            + j.ms+' ms';
            out.appendChild(res);
            (j.sets||[]).forEach(function(set){
              if(set.truncado){
                var w=document.createElement('div'); w.className='sqlmsg warn';
                w.textContent='Mostrando solo las primeras 1000 filas.'; out.appendChild(w);
              }
              var g=document.createElement('div'); g.className='sqlgrid'; g.style.marginBottom='12px';
              var t=document.createElement('table'); t.className='sqltbl';
              var th=document.createElement('thead'), tr=document.createElement('tr');
              set.cols.forEach(function(c){ var x=document.createElement('th'); x.textContent=c; x.style.cursor='default'; tr.appendChild(x); });
              th.appendChild(tr); t.appendChild(th);
              var tb=document.createElement('tbody');
              set.rows.forEach(function(f){
                var r=document.createElement('tr');
                f.forEach(function(v){ r.appendChild(celda(v,null)); });
                tb.appendChild(r);
              });
              t.appendChild(tb); g.appendChild(t); out.appendChild(g);
            });
          }).catch(function(){ out.innerHTML=''; msg('No se pudo contactar con el panel.'); });
        }
        document.getElementById('sqRun').onclick=ejecutar;

        // ---- pestanas internas ----
        var vistaActual='datos';
        function mostrarVista(v){
          vistaActual=v;
          document.querySelectorAll('.sqlx-views button').forEach(function(b){ b.classList.toggle('on', b.dataset.view===v); });
          document.getElementById('sqPanelDatos').hidden      = v!=='datos';
          document.getElementById('sqPanelEstructura').hidden = v!=='estructura';
          document.getElementById('sqPanelSql').hidden        = v!=='sql';
          if(v==='sql'){ initEditor(); setTimeout(function(){ cm.refresh(); cm.focus(); }, 10); }
          if(v==='estructura') cargarEstructura();
          if(v==='datos' && S.table) cargarFilas();
        }
        document.querySelectorAll('.sqlx-views button').forEach(function(b){
          b.onclick=function(){ mostrarVista(b.dataset.view); };
        });

        elDb.onchange=cargarTablas;
        elFilter.oninput=pintarTablas;
        document.getElementById('sqRecargar').onclick=cargarFilas;
        document.getElementById('sqPrev').onclick=function(){ if(S.page>1){ S.page--; cargarFilas(); } };
        document.getElementById('sqNext').onclick=function(){ S.page++; cargarFilas(); };
        document.getElementById('sqPer').onchange=function(){ S.per=parseInt(this.value,10)||50; S.page=1; cargarFilas(); };
        cargarDbs();
      })();
      </script>
      <?php endif; ?>

    <?php endif; ?>

  <?php elseif ($tab==='docker'): /* ---------- PESTAÑA DOCKER (solo si se detecta instalado) ---------- */
      $dockerUp = docker_running();
      $containers = $dockerUp ? docker_containers() : null; ?>

    <?php if (!$dockerUp): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:6px">Docker Desktop no está arrancado</div>
        <div class="muted" style="margin-bottom:14px">Se detectó Docker instalado en esta máquina, pero el motor no responde ahora mismo. Arráncalo y recarga esta página en cuanto esté listo (puede tardar un minuto).</div>
        <form method="post">
          <input type="hidden" name="action" value="docker_start_desktop">
          <button class="btn" type="submit">Iniciar Docker Desktop</button>
        </form>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="row" style="margin-bottom:12px;gap:12px;flex-wrap:wrap">
          <h2 style="margin:0;font-size:15px">Contenedores</h2>
          <input type="text" id="dockerSearchInput" placeholder="Buscar contenedor&hellip;" autocomplete="off" spellcheck="false" style="width:220px">
          <div class="spacer"></div>
          <a class="btn ghost sm" href="?tab=docker">Refrescar</a>
        </div>
        <div id="dockerRows">
        <?php if ($containers === null): ?>
          <div class="muted">No se pudo listar los contenedores (&iquest;acaba de arrancar Docker? espera unos segundos y recarga).</div>
        <?php elseif (!$containers): ?>
          <div class="muted">No hay contenedores todav&iacute;a.</div>
        <?php else: foreach ($containers as $c): $up = stripos($c['status'],'Up ')===0; ?>
          <div class="dbrow" data-search="<?= e(strtolower($c['name'].' '.$c['image'])) ?>">
            <div>
              <div class="dbname"><?= e($c['name']) ?></div>
              <div class="muted" style="font-size:12px;max-width:480px;line-height:1.5;word-break:break-word"><?= e($c['image']) ?><?= $c['ports']!==''? ' &middot; '.e($c['ports']) : '' ?></div>
            </div>
            <div class="spacer"></div>
            <span class="jstate <?= $up?'ok':'err' ?>"><?= e($c['status']) ?></span>
            <div class="dbactions">
              <?php if ($up): ?>
                <form method="post"><input type="hidden" name="action" value="docker_container"><input type="hidden" name="op" value="restart"><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="btn ghost sm" type="submit">Reiniciar</button></form>
                <form method="post"><input type="hidden" name="action" value="docker_container"><input type="hidden" name="op" value="stop"><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="btn ghost sm" type="submit">Parar</button></form>
                <button type="button" class="btn ghost sm" onclick="luaOpenDockerTerm('<?= e($c['id']) ?>','<?= e(addslashes($c['name'])) ?>')">Terminal</button>
              <?php else: ?>
                <form method="post"><input type="hidden" name="action" value="docker_container"><input type="hidden" name="op" value="start"><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="btn ghost sm" type="submit">Arrancar</button></form>
              <?php endif; ?>
              <button type="button" class="btn danger sm" onclick="luaAskRmContainer('<?= e($c['id']) ?>','<?= e(addslashes($c['name'])) ?>')">Eliminar</button>
            </div>
          </div>
        <?php endforeach; endif; ?>
        </div>
      </div>
      <script>
        (function(){
          var inp=document.getElementById('dockerSearchInput');
          if(!inp) return;
          inp.addEventListener('input', function(){
            var q=inp.value.toLowerCase();
            Array.from(document.querySelectorAll('#dockerRows .dbrow')).forEach(function(row){
              row.style.display = (row.dataset.search||'').indexOf(q)===-1 ? 'none' : '';
            });
          });
        })();
      </script>

      <!-- Modal de confirmacion de eliminar contenedor -->
      <div id="rmContainerModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseRmContainer()">
        <div class="modal-box" role="dialog" aria-modal="true">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
              <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
          </div>
          <h3>&iquest;Eliminar el contenedor?</h3>
          <p class="modal-tx">Se eliminar&aacute; <strong id="rmContainerName"></strong> (forzado, aunque est&eacute; en marcha). Los datos que no est&eacute;n en un volumen se perder&aacute;n.</p>
          <form method="post" class="modal-actions">
            <input type="hidden" name="action" value="docker_container">
            <input type="hidden" name="op" value="rm">
            <input type="hidden" name="id" id="rmContainerId">
            <button type="button" class="btn ghost" onclick="luaCloseRmContainer()">Cancelar</button>
            <button type="submit" class="btn danger">S&iacute;, eliminar</button>
          </form>
        </div>
      </div>
      <script>
        function luaAskRmContainer(id,name){
          document.getElementById('rmContainerName').textContent = name;
          document.getElementById('rmContainerId').value = id;
          document.getElementById('rmContainerModal').hidden = false;
          document.addEventListener('keydown', luaEscRmContainer);
        }
        function luaCloseRmContainer(){
          document.getElementById('rmContainerModal').hidden = true;
          document.removeEventListener('keydown', luaEscRmContainer);
        }
        function luaEscRmContainer(e){ if(e.key==='Escape') luaCloseRmContainer(); }
      </script>

      <!-- Modal: terminal dentro de un contenedor (docker exec) -->
      <div id="dockerTermModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDockerTerm()">
        <div class="modal-box" role="dialog" aria-modal="true" style="max-width:720px;text-align:left">
          <div class="row" style="margin-bottom:10px">
            <h3 id="dockerTermTitle" style="margin:0;font-size:16px">Terminal</h3>
            <div class="spacer"></div>
            <button type="button" class="btn ghost sm" id="dockerTermStop" disabled>Detener</button>
            <button type="button" class="lockbtn" id="dockerTermDockBtn" title="Fijar a la derecha" aria-label="Fijar a la derecha">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="15" y1="4" x2="15" y2="20"/></svg>
            </button>
            <button type="button" class="btn ghost sm" onclick="luaCloseDockerTerm()">Cerrar</button>
          </div>
          <div id="dockerTermOut" class="termout" style="height:320px;border:1px solid var(--line);border-radius:6px;background:var(--in)"></div>
          <div class="termin" style="margin-top:8px">
            <span class="termprompt">&gt;</span>
            <input type="text" id="dockerTermCmd" class="termcmd-input" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="comando dentro del contenedor, p.ej. ls -la">
          </div>
        </div>
      </div>
      <script>
        (function(){
          var modal=document.getElementById('dockerTermModal'), title=document.getElementById('dockerTermTitle'),
              out=document.getElementById('dockerTermOut'), inp=document.getElementById('dockerTermCmd'),
              stopBtn=document.getElementById('dockerTermStop'),
              dockBtn=document.getElementById('dockerTermDockBtn'), box=modal.querySelector('.modal-box');
          var sid=null, containerId=null, running=false, curRun=null;
          var DOCK_KEY='lua_dock_dockerterm';
          function setDocked(on){
            modal.classList.toggle('docked', on);
            box.classList.toggle('docked', on);
            dockBtn.classList.toggle('on', on);
            try{ localStorage.setItem(DOCK_KEY, on?'1':'0'); }catch(e){}
          }
          dockBtn.onclick=function(){ setDocked(!box.classList.contains('docked')); };
          var dockSaved='0'; try{ dockSaved=localStorage.getItem(DOCK_KEY)||'0'; }catch(e){}
          setDocked(dockSaved==='1');
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
          function finish(){ running=false; curRun=null; stopBtn.disabled=true; inp.disabled=false; inp.focus(); }
          function run(cmd){
            running=true; inp.disabled=true; stopBtn.disabled=false;
            append('<span class="a-prompt">&gt; </span>'+esc(cmd)+'\n');
            fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
              body:'action=docker_term_run&sid='+sid+'&container='+encodeURIComponent(containerId)+'&cmd='+encodeURIComponent(cmd)})
            .then(r=>r.json()).then(function(j){
              if(j.error){ append('<span class="a-r">'+esc(j.error)+'</span>\n'); finish(); return; }
              curRun=j.runid; poll(j.runid, 0);
            }).catch(function(){ append('<span class="a-r">[no se pudo lanzar]</span>\n'); finish(); });
          }
          inp.addEventListener('keydown', function(e){
            if(e.key==='Enter'){
              var cmd=inp.value; if(!cmd.trim()||running) return;
              inp.value='';
              run(cmd);
            }
          });
          stopBtn.onclick=function(){
            if(!running||!curRun) return;
            fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
              body:'action=term_stop&sid='+sid+'&runid='+curRun}).then(function(){});
          };
          window.luaOpenDockerTerm=function(id, name){
            containerId=id;
            sid=(function(){var a=new Uint8Array(10);crypto.getRandomValues(a);return Array.from(a).map(b=>b.toString(16).padStart(2,'0')).join('');})();
            title.textContent='Terminal: '+name;
            out.innerHTML=''; running=false; curRun=null; stopBtn.disabled=true; inp.value=''; inp.disabled=false;
            modal.hidden=false;
            document.addEventListener('keydown', luaEscDockerTerm);
            inp.focus();
          };
          window.luaCloseDockerTerm=function(){
            if(running && curRun){ fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=term_stop&sid='+sid+'&runid='+curRun}).then(function(){}); }
            modal.hidden=true;
            document.removeEventListener('keydown', luaEscDockerTerm);
          };
          function luaEscDockerTerm(e){ if(e.key==='Escape') luaCloseDockerTerm(); }
        })();
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
      <?= render_terminal_widget('term', term_default_cwd($ROOT)) ?>
      <div class="muted" style="margin-top:10px;font-size:12px">
        Sesión con directorio persistente (<code>cd</code> se mantiene). No es un PTY:
        programas interactivos a pantalla completa (vim, nano, prompts) no funcionan.
        Historial con ↑/↓ · Ctrl+L limpia.
      </div>
    <?php endif; ?>

  <?php elseif ($tab==='docs'): /* ---------- PESTAÑA DOCUMENTACIÓN ---------- */ ?>

    <style>
      /* Documento con indice fijo a la izquierda (como una doc de verdad), en vez de
         tarjetas sueltas: mas facil de leer de arriba a abajo o de saltar por el indice. */
      .docs{max-width:1180px;display:flex;gap:36px;align-items:flex-start}
      .docs-side{width:220px;flex:0 0 220px;position:sticky;top:0;align-self:flex-start;max-height:100vh;overflow-y:auto;padding-bottom:20px}
      .docs-side .side-title{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--mut);margin:0 0 10px;padding:0 10px;font-weight:700}
      .docs-search{position:relative;margin:0 0 14px}
      .docs-search input{width:100%;padding:8px 28px 8px 10px;font-size:13px;border:1px solid var(--line);border-radius:7px;background:var(--in);color:var(--tx)}
      .docs-search input:focus{outline:none;border-color:var(--ac)}
      .docs-search .clr{position:absolute;right:5px;top:50%;transform:translateY(-50%);border:none;background:none;color:var(--mut);cursor:pointer;font-size:15px;line-height:1;padding:4px;display:none}
      .docs-search.has-query .clr{display:block}
      .docs-search-hint{font-size:11px;color:var(--mut);padding:6px 10px 2px}
      .docs-side nav{display:flex;flex-direction:column;gap:1px}
      .docs-side nav a{padding:6px 10px;border-radius:6px;font-size:13px;color:var(--mut);border-left:2px solid transparent;line-height:1.4;text-decoration:none}
      .docs-side nav a:hover{color:var(--tx);background:var(--card)}
      .docs-side nav a.active{color:var(--ac);background:rgba(110,168,254,.1);border-left-color:var(--ac);font-weight:600}
      .docs-side nav a.nomatch{display:none}
      .docs-main section.nomatch{display:none}
      .docs-noresults{display:none;padding:30px 0;color:var(--mut);font-size:14px}
      .docs mark.docs-hl{background:rgba(255,196,0,.45);color:inherit;padding:0 1px;border-radius:2px}
      .docs-main{flex:1;min-width:0;max-width:800px}
      .docs-main h2{font-size:22px;margin:0 0 4px;text-transform:none;letter-spacing:0;color:var(--tx);font-weight:700}
      .docs-main .lead{color:var(--mut);font-size:14px;margin:0 0 8px}
      .docs-main section{scroll-margin-top:16px;padding:24px 0;border-bottom:1px solid var(--line)}
      .docs-main section:last-of-type{border-bottom:none;padding-bottom:4px}
      .docs h3{font-size:17px;margin:0 0 8px;display:flex;align-items:center;gap:9px}
      .docs h3 .n{width:26px;height:26px;flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;font-size:12px;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--brand-start),var(--brand-end))}
      .docs h4{font-size:13.5px;margin:18px 0 6px;color:var(--tx)}
      .docs p{margin:8px 0;line-height:1.65;font-size:14px;color:var(--tx)}
      .docs ul{margin:8px 0;padding-left:22px;line-height:1.7;font-size:14px}
      .docs li{margin:4px 0}
      .docs code{background:var(--in);border:1px solid var(--line);border-radius:4px;padding:1px 5px;font-family:ui-monospace,Consolas,monospace;font-size:12.5px}
      .docs pre{background:var(--in);border:1px solid var(--line);border-radius:6px;padding:10px 12px;overflow-x:auto;font-family:ui-monospace,Consolas,monospace;font-size:12.5px;line-height:1.5;margin:10px 0}
      .docs pre code{background:none;border:none;padding:0}
      .docs .note{border-left:3px solid var(--warn);background:rgba(210,153,34,.08);padding:8px 12px;border-radius:0 6px 6px 0;margin:10px 0;font-size:13px}
      .docs .tip{border-left:3px solid var(--ok);background:rgba(63,185,80,.08);padding:8px 12px;border-radius:0 6px 6px 0;margin:10px 0;font-size:13px}
      @media (max-width:820px){ .docs{flex-direction:column} .docs-side{position:static;width:100%;flex:none;max-height:none;overflow-y:visible} .docs-side nav{flex-direction:row;flex-wrap:wrap} .docs-side nav a{border-left:none;border-bottom:2px solid transparent} .docs-side nav a.active{border-left:none;border-bottom-color:var(--ac)} }
    </style>

    <div class="docs">
      <aside class="docs-side">
        <div class="docs-search" id="docsSearchBox">
          <input type="search" id="docsSearch" placeholder="Buscar en la documentación…" autocomplete="off" spellcheck="false">
          <button type="button" class="clr" id="docsSearchClear" title="Borrar búsqueda" aria-label="Borrar búsqueda">&times;</button>
        </div>
        <div class="docs-search-hint" id="docsSearchHint"></div>
        <div class="side-title">En esta página</div>
        <nav id="docsNav">
          <a href="#intro">Qué es</a>
          <a href="#proyectos">Proyectos</a>
          <a href="#ficha">Ficha de proyecto</a>
          <a href="#php">Versiones de PHP</a>
          <a href="#bd">Bases de datos</a>
          <a href="#https">HTTPS local</a>
          <a href="#mailpit">Mailpit (correo)</a>
          <a href="#terminal">Terminal y runner</a>
          <a href="#lan">Exponer en LAN</a>
          <a href="#startup">Arrancar con Windows</a>
          <a href="#dominios">Dominios y hosts</a>
          <a href="#marca">Identidad / marca</a>
          <a href="#cli">Comandos (CLI)</a>
          <a href="#trampas">Problemas frecuentes</a>
        </nav>
      </aside>

      <div class="docs-main">
      <h2>Documentación de <?= e($brandName) ?></h2>
      <p class="lead">Guía de uso de esta plataforma: un servidor PHP local portable para Windows (Apache + mod_fcgid con varias versiones de PHP a la vez) con este panel de administración web.</p>

      <div class="docs-noresults" id="docsNoResults">Sin resultados para "<span id="docsNoResultsTerm"></span>". Prueba con otra palabra.</div>

      <section id="intro">
        <h3><span class="n">i</span> Qué es esta plataforma</h3>
        <p><?= e($brandName) ?> es un entorno de desarrollo PHP local para Windows, pensado para ser <strong>portable</strong> (vive en una carpeta, sin instalar nada en el sistema) y arrancar sin permisos de administrador salvo cuando de verdad hace falta (HTTPS, editar el archivo <code>hosts</code>, instalar el servicio de Windows o abrir el Firewall).</p>
        <ul>
          <li><strong>Apache + mod_fcgid</strong> sirviendo cada proyecto en su propio dominio local.</li>
          <li><strong>Varias versiones de PHP a la vez</strong> (7.1 a 8.5): cada proyecto elige la suya.</li>
          <li><strong>MariaDB</strong>, <strong>PostgreSQL</strong> y <strong>MongoDB</strong> nativos opcionales, con phpMyAdmin/Adminer/mongo-express integrados.</li>
          <li>Este <strong>panel web</strong> para gestionarlo todo sin tocar archivos de configuración a mano.</li>
        </ul>
        <p>El panel solo es accesible desde esta misma máquina (<code>http://127.0.0.1</code> o <code>http://<?= e($tld) ?></code>).</p>
      </section>

      <section id="proyectos">
        <h3><span class="n">1</span> Proyectos</h3>
        <p>En la pestaña <strong>Proyectos</strong> se listan todos tus sitios. Cada uno se sirve en <code>&lt;nombre&gt;.<?= e($tld) ?></code>.</p>
        <h4>Crear un proyecto</h4>
        <p>Usa el formulario de alta: eliges nombre y versión de PHP. Puedes crear un proyecto vacío o desde una plantilla (por ejemplo WordPress, que se descarga y prepara solo). La carpeta se crea dentro de <code>www\</code>.</p>
        <h4>Proyectos externos</h4>
        <p>Un proyecto puede vivir <em>fuera</em> de <code>www\</code> (por ejemplo en <code>C:\proyectos\mi-app</code>). Se registran con la ruta completa y se marcan con la etiqueta <span class="tag">externo</span>. Si detecta una carpeta <code>public/</code> (Laravel/Symfony) la usa como raíz automáticamente.</p>
        <h4>Adoptar carpetas sin registrar</h4>
        <p>Si dejas una carpeta dentro de <code>www\</code> que no está dada de alta, el panel la detecta y ofrece un botón <strong>Adoptar</strong> para registrarla como proyecto.</p>
        <h4>Cambiar la versión de PHP</h4>
        <p>Cada card tiene un selector de versión de PHP. Al cambiarlo, se regenera la configuración y Apache se recarga solo.</p>
        <h4>Carátula, bloqueo y borrado</h4>
        <ul>
          <li><strong>Carátula:</strong> puedes subir una imagen de portada para identificar el proyecto de un vistazo.</li>
          <li><strong>Bloqueo:</strong> el candado protege el proyecto contra el borrado. Por dentro crea un archivo <code>.lua</code> en la raíz; mientras exista <em>cualquier</em> archivo <code>.lua</code> ahí, el proyecto no se puede eliminar.</li>
          <li><strong>Eliminar:</strong> solo desregistra el sitio del panel; <strong>no borra la carpeta del disco</strong>. Tus archivos siguen ahí.</li>
        </ul>
      </section>

      <section id="ficha">
        <h3><span class="n">2</span> Ficha de proyecto</h3>
        <p>Pulsa el icono de detalle de una card para abrir su ficha, con todo lo del proyecto en un sitio:</p>
        <ul>
          <li><strong>Git:</strong> rama actual, si hay cambios sin commitear y los últimos commits.</li>
          <li><strong>Log de errores:</strong> las últimas líneas del log de Apache de ese proyecto.</li>
          <li><strong>Archivos:</strong> árbol navegable. Al pulsar un archivo se abre un editor de código (con resaltado) para editarlo y guardarlo en el momento.</li>
          <li><strong>Desplegar por FTP:</strong> configura host/usuario/ruta y sube el proyecto a tu hosting con un clic.</li>
          <li><strong>Terminal:</strong> si la terminal está activada, aquí tienes una que <strong>arranca ya en la carpeta del proyecto</strong>.</li>
        </ul>
      </section>

      <section id="php">
        <h3><span class="n">3</span> Versiones de PHP</h3>
        <p>En <strong>Versiones PHP</strong> editas el <code>php.ini</code> de cada versión instalada. Los cambios se guardan como <em>overrides</em> (sobreviven a las regeneraciones) y se aplican recargando Apache automáticamente.</p>
        <ul>
          <li>Ajustes rápidos: zona horaria, límite de memoria, tamaño de subida, tiempo de ejecución, mostrar errores…</li>
          <li>Directivas libres adicionales (una por línea, formato <code>clave = valor</code>).</li>
          <li><strong>Xdebug:</strong> se activa/desactiva por versión con un botón (depuración paso a paso en el puerto <code>9003</code> para VS Code o PhpStorm).</li>
          <li><strong>Extensiones adicionales:</strong> instala cualquier extensión de terceros (p.ej. <code>pdo_sqlsrv</code> de Microsoft para SQL Server) subiendo el <code>.dll</code> ya extraído o pegando una URL directa. Se activa sola en cuanto el archivo existe para esa versión.</li>
        </ul>
      </section>

      <section id="bd">
        <h3><span class="n">4</span> Bases de datos</h3>
        <p>La plataforma trae varios motores de base de datos, todos opcionales y nativos (portables, se descargan al activarlos). En la pestaña <strong>Bases de datos</strong> hay un selector arriba para cambiar entre <strong>MySQL / MariaDB</strong> y <strong>PostgreSQL</strong> (MongoDB se gestiona aparte, ver más abajo).</p>
        <h4>MySQL (MariaDB)</h4>
        <p>MariaDB nativo en <code>127.0.0.1:3306</code>, usuario <code>root</code> (sin contraseña por defecto). Solo accesible desde esta máquina.</p>
        <ul>
          <li>Crear/eliminar bases de datos y crear usuarios de aplicación.</li>
          <li>Gestionar la contraseña de <code>root</code>.</li>
          <li><strong>Exportar</strong> (backup <code>.sql</code>) e <strong>importar</strong> una base de datos.</li>
          <li><strong>phpMyAdmin</strong> y <strong>Adminer</strong> integrados para trabajar visualmente.</li>
        </ul>
        <h4>PostgreSQL</h4>
        <p>PostgreSQL 16 nativo en <code>127.0.0.1:5432</code>, usuario <code>postgres</code> (sin contraseña, autenticación <em>trust</em> solo en localhost). Actívalo en <strong>Configuración del servidor</strong>.</p>
        <ul>
          <li>Crear/eliminar bases de datos y roles (con contraseña) desde el panel.</li>
          <li>Al crear un rol para una base de datos concreta, se le asigna como <strong>dueño</strong> (control total sobre ella).</li>
          <li><strong>Exportar</strong> (<code>pg_dump</code>) e <strong>importar</strong> (<code>psql</code>) una base de datos.</li>
          <li><strong>Adminer</strong> integrado (habla PostgreSQL de forma nativa) para gestionar tablas y datos.</li>
        </ul>
        <h4>MongoDB</h4>
        <p>MongoDB Community nativo en <code>127.0.0.1:27017</code>, sin autenticación (solo accesible desde esta máquina). Se activa desde <strong>Configuración del servidor</strong> con un botón: en la misma descarga se instala también un runtime de Node.js portable y <strong>mongo-express</strong>, su gestor visual.</p>
        <ul>
          <li>No pasa por Apache ni tiene dominio propio: <strong>mongo-express</strong> se abre directo en <code>http://127.0.0.1:8081/</code>, sin login.</li>
          <li>mongo-express arranca y se detiene junto con el motor (un único botón para ambos).</li>
        </ul>
        <p class="tip">Nota: <em>phpMyAdmin</em> solo sirve para MySQL; para PostgreSQL el gestor visual es Adminer, ya incluido; para MongoDB es <em>mongo-express</em>.</p>
        <p>Desde tus proyectos conéctate con host <code>127.0.0.1</code>, puerto <code>3306</code> (MySQL, usuario <code>root</code>), <code>5432</code> (PostgreSQL, usuario <code>postgres</code>) o <code>27017</code> (MongoDB, sin autenticación).</p>
      </section>

      <section id="https">
        <h3><span class="n">5</span> HTTPS local</h3>
        <p>Activa <strong>HTTPS local</strong> en Configuración para servir tus proyectos en <code>https://&lt;proyecto&gt;.<?= e($tld) ?></code> con certificados de confianza (candado verde, sin avisos del navegador). La primera vez, Windows pedirá permiso para instalar la autoridad certificadora (CA) en el almacén de confianza.</p>
      </section>

      <section id="mailpit">
        <h3><span class="n">6</span> Mailpit (captura de correo)</h3>
        <p>Con Mailpit activado, todos los correos que envíen tus proyectos con <code>mail()</code> se <strong>atrapan</strong> (no salen a internet) y se ven en un buzón web en <code>http://localhost:8025</code>. Ideal para probar emails de registro, recuperación de contraseña, etc. sin spamear a nadie.</p>
      </section>

      <section id="terminal">
        <h3><span class="n">7</span> Terminal y runner de comandos</h3>
        <p>La <strong>Terminal</strong> viene desactivada por seguridad (permite ejecutar cualquier comando con los permisos de Apache). Actívala solo si confías en quién tiene acceso al panel.</p>
        <ul>
          <li>Mantiene el directorio de trabajo entre comandos (<code>cd</code> persiste) y colorea la salida.</li>
          <li>No es una terminal interactiva completa (PTY): programas a pantalla completa como <code>vim</code> o <code>nano</code> no funcionan.</li>
          <li>Historial con las flechas ↑/↓ y <code>Ctrl+L</code> para limpiar.</li>
        </ul>
        <h4>Ejecutar Composer / NPM / Artisan en un proyecto</h4>
        <p>En cada card con <code>composer.json</code>, <code>package.json</code> o <code>artisan</code> aparece un botón de <strong>play</strong> (también en la ficha del proyecto) que abre un modal para lanzar comandos sobre ese proyecto (<code>composer install</code>, <code>npm run dev</code>, <code>php artisan migrate</code>…). Puedes escribir <strong>comandos personalizados y guardarlos</strong> como accesos rápidos reutilizables. El runner usa el PHP propio del proyecto automáticamente. Los comandos destructivos (como <code>migrate:fresh</code>) se marcan en rojo y piden confirmación antes de ejecutarse.</p>
      </section>

      <section id="lan">
        <h3><span class="n">8</span> Exponer en la red local (LAN)</h3>
        <p>El toggle <strong>Exponer en la red local</strong> (en Configuración) abre el puerto <?= is_file($ROOT.'/config/https.on')?'80/443':'80' ?> en el Firewall de Windows, <strong>limitado a tu subred local</strong>, para que otros dispositivos de tu misma red o WiFi puedan abrir tus proyectos. Windows pedirá permiso (UAC) al activarlo.</p>
        <p>El panel te mostrará tu <strong>IP en la red local</strong>. Como los otros equipos no resuelven los dominios <code>.<?= e($tld) ?></code>, en <em>ese</em> equipo hay que añadir al archivo <code>hosts</code> una línea por proyecto:</p>
        <pre><code>&lt;tu-IP-LAN&gt;   miproyecto.<?= e($tld) ?></code></pre>
        <p>y luego abrir <code>http://miproyecto.<?= e($tld) ?></code> desde ahí.</p>
        <div class="tip">El panel de administración sigue restringido a esta máquina (<code>127.0.0.1</code>) aunque el puerto esté abierto: los demás equipos pueden ver tus proyectos, pero no este panel.</div>
      </section>

      <section id="startup">
        <h3><span class="n">9</span> Arrancar con Windows</h3>
        <p>Instala Apache como <strong>servicio de Windows</strong> (arranque automático) y el watcher como tarea programada, para que la plataforma esté disponible nada más encender el equipo, sin iniciar sesión. Windows pedirá permiso (UAC) al activarlo o desactivarlo.</p>
      </section>

      <section id="dominios">
        <h3><span class="n">10</span> Dominios y archivo hosts</h3>
        <p>El dominio local por defecto es <code><?= e($tld) ?></code> (recomendado: <code>test</code>, reservado oficialmente para pruebas). Para que <code>&lt;proyecto&gt;.<?= e($tld) ?></code> abra en el navegador, hay que registrar los dominios en el archivo <code>hosts</code> de Windows: pulsa <strong>Sincronizar dominios</strong> en Configuración (pide UAC una vez).</p>
        <div class="note">Si <code>localhost</code> te carga otra cosa (por ejemplo Docker/Portainer, que puede ocupar el puerto 80 por IPv6), usa <code>http://127.0.0.1</code> o <code>http://<?= e($tld) ?></code> a secas — siempre te traen aquí.</div>
      </section>

      <section id="marca">
        <h3><span class="n">11</span> Identidad / marca</h3>
        <p>En Configuración puedes cambiar el <strong>nombre</strong> y el <strong>logo</strong> de la plataforma. Aparecen en la cabecera, en la pestaña del navegador y en el pie. Deja el nombre vacío para volver a <code>lua-server</code>, o restablece el logo al de por defecto cuando quieras.</p>
      </section>

      <section id="cli">
        <h3><span class="n">12</span> Comandos (CLI)</h3>
        <p>Todo lo del panel también se puede hacer desde PowerShell con <code>lua.ps1</code>. Los más útiles:</p>
        <pre><code>.\lua.ps1 start        # arranca Apache + watcher
.\lua.ps1 stop         # para todo
.\lua.ps1 restart      # reinicia Apache
.\lua.ps1 status       # estado del stack
.\lua.ps1 add-site &lt;nombre&gt; [php]
.\lua.ps1 add-external &lt;nombre&gt; &lt;ruta&gt; [dominio] [php]
.\lua.ps1 switch-php &lt;nombre&gt; &lt;version&gt;
.\lua.ps1 reload       # regenera vhosts + hosts + reinicia</code></pre>
      </section>

      <section id="trampas">
        <h3><span class="n">!</span> Problemas frecuentes</h3>
        <h4><code>localhost</code> me carga otra cosa</h4>
        <p>En equipos con Docker Desktop, el puerto 80 puede estar ocupado por IPv6. Usa <code>http://127.0.0.1</code> o <code>http://<?= e($tld) ?></code>.</p>
        <h4>Cambié algo y "no hace nada"</h4>
        <p>El botón <strong>Reiniciar</strong> del panel reinicia Apache. Si el cambio afecta al comportamiento en segundo plano (watcher) y editaste el script <code>lua.ps1</code>, hay que pararlo y arrancarlo del todo:</p>
        <pre><code>.\lua.ps1 stop
.\lua.ps1 start</code></pre>
        <h4>Un proyecto no se deja borrar</h4>
        <p>Está bloqueado: hay un archivo <code>.lua</code> en su carpeta. Quítalo (o usa el botón de desbloqueo) para poder eliminarlo del panel.</p>
        <h4>Composer/PHP "no se encuentra"</h4>
        <p>El runner y la terminal usan el PHP propio de la plataforma; no necesitas un PHP global instalado en Windows.</p>
      </section>

      </div><!-- /.docs-main -->
    </div><!-- /.docs -->

    <script>
    (function(){
      var nav = document.getElementById('docsNav');
      if (!nav) return;
      var links = Array.prototype.slice.call(nav.querySelectorAll('a'));
      var sections = links.map(function(a){ return document.getElementById(a.getAttribute('href').slice(1)); });
      var scroller = document.querySelector('.content') || window;

      // --- resalta la seccion activa del indice segun el scroll ---
      function onScroll(){
        var refY = (scroller === window ? 0 : scroller.getBoundingClientRect().top) + 40;
        var current = null;
        for (var i = 0; i < sections.length; i++) {
          var s = sections[i];
          if (s && s.classList.contains('nomatch')) continue;
          if (s && s.getBoundingClientRect().top - refY <= 0) current = s;
        }
        links.forEach(function(a, i){ a.classList.toggle('active', sections[i] === current); });
      }
      scroller.addEventListener('scroll', onScroll, { passive: true });

      // --- buscador: filtra secciones/indice y resalta coincidencias ---
      var input   = document.getElementById('docsSearch');
      var clearBt = document.getElementById('docsSearchClear');
      var box     = document.getElementById('docsSearchBox');
      var hint    = document.getElementById('docsSearchHint');
      var noRes   = document.getElementById('docsNoResults');
      var noResTerm = document.getElementById('docsNoResultsTerm');
      function norm(s){
        return (s || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
      }
      function clearHighlights(root){
        root.querySelectorAll('mark.docs-hl').forEach(function(m){
          var t = document.createTextNode(m.textContent);
          m.parentNode.replaceChild(t, m);
        });
        root.normalize();
      }
      function highlight(root, rawTerm){
        if (!rawTerm) return;
        var re;
        try { re = new RegExp('(' + rawTerm.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'ig'); } catch(e){ return; }
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
          acceptNode: function(n){
            var p = n.parentNode;
            if (!n.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
            if (p && (p.tagName === 'SCRIPT' || p.tagName === 'STYLE' || p.tagName === 'MARK')) return NodeFilter.FILTER_REJECT;
            return re.test(n.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
          }
        });
        var hits = [];
        var n; while ((n = walker.nextNode())) hits.push(n);
        hits.forEach(function(textNode){
          re.lastIndex = 0;
          var frag = document.createDocumentFragment();
          var last = 0, m;
          while ((m = re.exec(textNode.nodeValue))) {
            frag.appendChild(document.createTextNode(textNode.nodeValue.slice(last, m.index)));
            var mk = document.createElement('mark'); mk.className = 'docs-hl'; mk.textContent = m[0];
            frag.appendChild(mk);
            last = m.index + m[0].length;
          }
          frag.appendChild(document.createTextNode(textNode.nodeValue.slice(last)));
          textNode.parentNode.replaceChild(frag, textNode);
        });
      }
      function runSearch(){
        var raw = input.value.trim();
        var q = norm(raw);
        box.classList.toggle('has-query', raw !== '');
        var visibleCount = 0;
        sections.forEach(function(sec, i){
          clearHighlights(sec);
          var match = q === '' || norm(sec.textContent).indexOf(q) !== -1;
          sec.classList.toggle('nomatch', !match);
          links[i].classList.toggle('nomatch', !match);
          if (match) { visibleCount++; if (raw) highlight(sec, raw); }
        });
        noRes.style.display = (raw && visibleCount === 0) ? 'block' : 'none';
        if (raw && visibleCount === 0 && noResTerm) noResTerm.textContent = raw;
        hint.textContent = raw ? (visibleCount + ' de ' + sections.length + ' secciones') : '';
        onScroll();
      }
      input.addEventListener('input', runSearch);
      clearBt.addEventListener('click', function(){ input.value = ''; runSearch(); input.focus(); });
      input.addEventListener('keydown', function(e){ if (e.key === 'Escape') { input.value=''; runSearch(); input.blur(); } });
      // Atajo tipo "doc site": "/" enfoca el buscador si no estas ya escribiendo en algo.
      document.addEventListener('keydown', function(e){
        if (e.key !== '/' || e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) return;
        if (!document.getElementById('docsSearch')) return; // solo si la pestana Documentacion esta activa
        e.preventDefault();
        input.focus();
      });

      onScroll();
    })();
    </script>

  <?php endif; ?>

  </div>

  <?php $luaRunnerOn = is_file($ROOT.'/config/terminal.on'); if ($luaRunnerOn && in_array($tab, ['proyectos','proyecto'], true)): $runPresets = run_presets_load($ROOT); ?>
  <!-- Modal: runner de Composer/NPM/Artisan por proyecto (reutiliza los endpoints de la Terminal).
       Compartido entre la lista de proyectos y la ficha de un proyecto: un solo modal, un solo
       listener delegado, sin importar desde que pestana se abrio. -->
  <div id="runnerModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseRunner()">
    <div class="modal-box" role="dialog" aria-modal="true" style="max-width:640px;text-align:left">
      <div class="row" style="margin-bottom:10px">
        <h3 id="runnerTitle" style="margin:0;font-size:16px">Ejecutar</h3>
        <div class="spacer"></div>
        <a href="javascript:void(0)" id="runnerStop" class="runlink off">Detener</a>
        <button type="button" class="lockbtn" id="runnerDockBtn" title="Fijar a la derecha" aria-label="Fijar a la derecha">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="15" y1="4" x2="15" y2="20"/></svg>
        </button>
        <button type="button" class="lockbtn" onclick="luaCloseRunner()" title="Cerrar" aria-label="Cerrar">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div id="runnerBtns" class="row" style="gap:6px 16px;margin-bottom:10px;flex-wrap:wrap"></div>
      <div class="row" style="gap:6px;margin-bottom:10px">
        <input type="text" id="runnerCustomCmd" placeholder="Comando personalizado, p.ej. npm run dev" style="flex:1" maxlength="200">
        <button type="button" class="btn-git sm" id="runnerRunBtn" title="Ejecutar una vez, sin guardarlo">Ejecutar</button>
        <button type="button" class="btn ghost sm" id="runnerAddBtn" title="Guardar como acceso rápido y ejecutarlo">+ Guardar</button>
      </div>
      <div id="runnerOut" class="termout" style="height:280px;border:1px solid var(--line);border-radius:6px;background:var(--in)"></div>
      <div class="row" style="margin-top:6px;justify-content:flex-end">
        <a href="javascript:void(0)" id="runnerClear" class="runlink">Limpiar consola</a>
      </div>
    </div>
  </div>
  <script>
    (function(){
      var modal=document.getElementById('runnerModal'), title=document.getElementById('runnerTitle'),
          btnsEl=document.getElementById('runnerBtns'), out=document.getElementById('runnerOut'),
          stopBtn=document.getElementById('runnerStop'), clearBtn=document.getElementById('runnerClear'),
          addBtn=document.getElementById('runnerAddBtn'), runOnceBtn=document.getElementById('runnerRunBtn'),
          customInput=document.getElementById('runnerCustomCmd'),
          dockBtn=document.getElementById('runnerDockBtn'), box=modal.querySelector('.modal-box');
      // runs: comandos en marcha por proyecto (clave = ruta). Sobreviven a cerrar el
      // modal -- el comando sigue en marcha en segundo plano (proceso propio de Windows,
      // WScript.Shell.Run, independiente de esta página) y el play de la card se marca
      // en verde mientras dure.
      var runs={}, curPath=null, curName=null, curPhpVer=null, curBuiltins=[];
      var savedPresets=<?= json_encode($runPresets, JSON_UNESCAPED_SLASHES) ?>;
      var LS_KEY='lua_runner_jobs';
      var DOCK_KEY='lua_dock_runner';
      // "Fijar a la derecha": recuerda la preferencia entre aperturas (localStorage), no
      // solo mientras el modal esta abierto -- si el usuario lo fijo la ultima vez, lo
      // vuelve a abrir fijado.
      function setDocked(on){
        modal.classList.toggle('docked', on);
        box.classList.toggle('docked', on);
        dockBtn.classList.toggle('on', on);
        try{ localStorage.setItem(DOCK_KEY, on?'1':'0'); }catch(e){}
      }
      dockBtn.onclick=function(){ setDocked(!box.classList.contains('docked')); };
      var dockSaved='0'; try{ dockSaved=localStorage.getItem(DOCK_KEY)||'0'; }catch(e){}
      setDocked(dockSaved==='1');

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
      function setButtons(disabled){ Array.from(btnsEl.querySelectorAll('button')).forEach(function(b){ b.disabled=disabled; }); }
      function findBtn(p){ return Array.from(document.querySelectorAll('.lua-runbtn')).find(function(b){ return b.dataset.path===p; }) || null; }
      function markBtn(p, on){ var b=findBtn(p); if(b) b.classList.toggle('running', !!on); }
      function saveJobs(){
        var o={};
        Object.keys(runs).forEach(function(p){ var r=runs[p]; if(r.runid) o[p]={sid:r.sid,runid:r.runid,name:r.name,phpVer:r.phpVer,cmd:r.cmd}; });
        try{ localStorage.setItem(LS_KEY, JSON.stringify(o)); }catch(e){}
      }
      function renderOut(p){ if(curPath===p && !modal.hidden){ out.innerHTML=runs[p].html; out.scrollTop=out.scrollHeight; } }

      // Icono de terminal (estatico, sin datos de usuario -> innerHTML es seguro aqui).
      var TERM_ICON='<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>';
      // El texto del comando se anade aparte con createTextNode (nunca con innerHTML):
      // los comandos guardados son texto libre del usuario y podrian llevar '<'/'>'/comillas
      // que romperian el HTML (o un atributo onclick="..." armado a mano) si se insertaran tal cual.
      function runlink(text, onClick, title, danger){
        var b=document.createElement('button');
        b.type='button'; b.className='runlink'+(danger?' runlink-danger':''); if(title) b.title=title;
        b.innerHTML=TERM_ICON; b.appendChild(document.createTextNode(text));
        b.onclick=onClick;
        return b;
      }
      function renderBtns(){
        btnsEl.innerHTML='';
        curBuiltins.forEach(function(p){
          var danger=!!p[2];
          btnsEl.appendChild(runlink(p[0], function(){
            if(danger && !confirm('¿Seguro que quieres ejecutar "'+p[1]+'" en '+curName+'? No se puede deshacer.')) return;
            luaRunPreset(p[1]);
          }, danger?'Accion destructiva':undefined, danger));
        });
        savedPresets.forEach(function(cmd){
          var wrap=document.createElement('span');
          wrap.className='runlink-wrap';
          wrap.appendChild(runlink(cmd, function(){ luaRunPreset(cmd); }, 'Ejecutar'));
          var d=document.createElement('button');
          d.type='button'; d.className='runlink-del'; d.textContent='×'; d.title='Eliminar acceso rapido';
          d.onclick=function(){ luaDelPreset(cmd); };
          wrap.appendChild(d);
          btnsEl.appendChild(wrap);
        });
      }

      function finishRun(p){
        delete runs[p];
        saveJobs();
        markBtn(p, false);
        if(curPath===p){ stopBtn.classList.add('off'); setButtons(false); }
      }
      function pollRun(p){
        var r=runs[p]; if(!r) return;
        fetch('?action=term_poll&sid='+r.sid+'&runid='+r.runid+'&off='+r.off)
        .then(function(resp){ return resp.json(); })
        .then(function(j){
          r=runs[p]; if(!r) return;
          if(j.error){ r.html+='<span class="a-r">'+esc(j.error)+'</span>\n'; renderOut(p); finishRun(p); return; }
          if(j.data){ r.html+=ansiToHtml(j.data); r.off=j.off; }
          if(j.done){
            if(r.html && !r.html.endsWith('\n')) r.html+='\n';
            r.html+='<span class="'+(j.code?'a-r':'a-g')+'">[salida '+(j.code||0)+']</span>\n';
            renderOut(p); finishRun(p);
          } else { renderOut(p); setTimeout(function(){ pollRun(p); }, 500); }
        }).catch(function(){
          r=runs[p]; if(!r) return;
          r.fails=(r.fails||0)+1;
          if(r.fails>=5){ r.html+='<span class="a-r">[error de red]</span>\n'; renderOut(p); finishRun(p); return; }
          setTimeout(function(){ pollRun(p); }, 700);
        });
      }
      function startRun(p, name, phpVer, cmd){
        if(runs[p]) return;
        var sid=(function(){var a=new Uint8Array(10);crypto.getRandomValues(a);return Array.from(a).map(b=>b.toString(16).padStart(2,'0')).join('');})();
        runs[p]={sid:sid, runid:null, name:name, phpVer:phpVer, cmd:cmd, off:0,
          html:'<span class="a-prompt">&gt; </span>'+esc(cmd)+'\n'};
        markBtn(p, true);
        if(curPath===p){ setButtons(true); stopBtn.classList.remove('off'); }
        renderOut(p);
        var full='cd /d "'+p+'" && '+cmd;
        fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body:'action=term_run&sid='+sid+'&php='+encodeURIComponent(phpVer||'')+'&cmd='+encodeURIComponent(full)})
        .then(function(resp){ return resp.json(); })
        .then(function(j){
          var r=runs[p]; if(!r) return;
          if(j.error){ r.html+='<span class="a-r">'+esc(j.error)+'</span>\n'; renderOut(p); finishRun(p); return; }
          r.runid=j.runid; saveJobs(); pollRun(p);
        }).catch(function(){
          var r=runs[p]; if(!r) return;
          r.html+='<span class="a-r">[no se pudo lanzar]</span>\n'; renderOut(p); finishRun(p);
        });
      }

      window.luaRunPreset=function(cmd){
        if(!curPath || runs[curPath]) return;
        startRun(curPath, curName, curPhpVer, cmd);
      };
      stopBtn.onclick=function(){
        var r=curPath && runs[curPath];
        if(!r || !r.runid) return;
        stopBtn.classList.add('off');
        fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body:'action=term_stop&sid='+r.sid+'&runid='+r.runid}).then(function(){});
      };
      // Solo vacia el papel visible (y el acumulado en memoria, para que el proximo
      // poll no lo vuelva a pintar entero): no toca el comando que siga en marcha.
      clearBtn.onclick=function(){
        out.innerHTML='';
        if(curPath && runs[curPath]) runs[curPath].html='';
      };

      function luaDelPreset(cmd){
        fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body:'action=run_preset_del&cmd='+encodeURIComponent(cmd)})
        .then(r=>r.json()).then(function(j){
          if(j.presets){ savedPresets=j.presets; renderBtns(); }
        });
      }
      // Ejecuta el comando del cuadro tal cual, sin pasar por run_preset_add: para
      // comandos puntuales que no hace falta guardar como acceso rapido.
      runOnceBtn.onclick=function(){
        var cmd=customInput.value.trim();
        if(!cmd || !curPath || runs[curPath]) return;
        startRun(curPath, curName, curPhpVer, cmd);
      };
      addBtn.onclick=function(){
        var cmd=customInput.value.trim();
        if(!cmd || (curPath && runs[curPath])) return;
        addBtn.disabled=true;
        fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body:'action=run_preset_add&cmd='+encodeURIComponent(cmd)})
        .then(r=>r.json()).then(function(j){
          addBtn.disabled=false;
          if(j.error){ out.insertAdjacentHTML('beforeend','<span class="a-r">'+esc(j.error)+'</span>\n'); return; }
          savedPresets=j.presets; customInput.value=''; renderBtns();
          luaRunPreset(cmd);
        }).catch(function(){ addBtn.disabled=false; });
      };
      customInput.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); addBtn.click(); } });

      window.luaOpenRunner=function(name, projectPath, hasComposer, hasNpm, phpVersion, hasArtisan){
        curPath=projectPath; curName=name; curPhpVer=phpVersion||null;
        title.textContent='Ejecutar en '+name;
        customInput.value='';
        curBuiltins=[];
        if(hasComposer){ curBuiltins.push(['composer install','composer install'],['composer update','composer update']); }
        if(hasNpm){ curBuiltins.push(['npm install','npm install'],['npm run build','npm run build']); }
        if(hasArtisan){
          curBuiltins.push(
            ['artisan migrate','php artisan migrate'],
            ['artisan migrate:fresh --seed','php artisan migrate:fresh --seed', true],
            ['artisan optimize:clear','php artisan optimize:clear'],
            ['artisan route:list','php artisan route:list'],
            ['artisan queue:work','php artisan queue:work']
          );
        }
        renderBtns();
        var r=runs[curPath];
        if(r){ out.innerHTML=r.html; out.scrollTop=out.scrollHeight; setButtons(true); stopBtn.classList.remove('off'); }
        else { out.innerHTML=''; setButtons(false); stopBtn.classList.add('off'); }
        modal.hidden=false;
        document.addEventListener('keydown', luaEscRunner);
      };
      // Delegado (no onclick inline): la ruta del proyecto lleva backslashes, y un
      // atributo onclick="...'C:\personal\...'" se compila como CODIGO JS, donde \p, \L,
      // \w, \a etc. son secuencias de escape que el navegador consume en silencio (la
      // barra desaparece) — "C:\personal\LuaServer" acababa llegando como
      // "C:personalLuaServer" y el "cd /d" fallaba siempre. Con data-* (texto plano, sin
      // parseo JS) el path llega intacto.
      document.addEventListener('click', function(e){
        var btn = e.target.closest('.lua-runbtn');
        if (!btn) return;
        luaOpenRunner(btn.dataset.name, btn.dataset.path, btn.dataset.composer==='1', btn.dataset.npm==='1', btn.dataset.php, btn.dataset.artisan==='1');
      });
      // Cerrar el modal ya NO mata el comando: sigue en marcha en segundo plano (el
      // play de la card queda en verde) y se puede reabrir luego para verlo o pararlo.
      window.luaCloseRunner=function(){
        modal.hidden=true;
        document.removeEventListener('keydown', luaEscRunner);
      };
      function luaEscRunner(e){ if(e.key==='Escape') luaCloseRunner(); }

      // Reengancha, al cargar la página, los comandos que quedaron corriendo en segundo
      // plano de una carga anterior (recarga, cambio de pestaña...): el proceso de
      // Windows es independiente de esta página (WScript.Shell.Run), solo hace falta
      // recuperar el sid/runid guardados para retomar el polling.
      (function reconnect(){
        var saved={};
        try{ saved=JSON.parse(localStorage.getItem(LS_KEY)||'{}')||{}; }catch(e){}
        Object.keys(saved).forEach(function(p){
          var j=saved[p];
          if(!j||!j.sid||!j.runid) return;
          runs[p]={sid:j.sid, runid:j.runid, name:j.name, phpVer:j.phpVer, cmd:j.cmd, off:0,
            html:'<span class="a-prompt">&gt; </span>'+esc(j.cmd||'')+'\n'};
          markBtn(p, true);
          pollRun(p);
        });
      })();
    })();
  </script>
  <?php endif; ?>

  <script>
    // Control de "elegir archivo" a medida (import de backups .sql): actualiza el nombre
    // mostrado y marca el control como "con archivo" para el estilo solido del borde.
    function luaFilePickName(input){
      var label = input.closest('.filepick');
      var nameEl = label.querySelector('.filepick-name');
      var f = input.files && input.files[0];
      nameEl.textContent = f ? f.name : 'Elegir .sql…';
      label.classList.toggle('has-file', !!f);
    }
    // Dialogo nativo "Elegir carpeta": pide al watcher que lo abra (ver ajax=pickfolder_start/
    // poll en el backend) y espera el resultado con polling. Puede tardar ~1s en aparecer
    // (el watcher revisa la peticion cada segundo), y se espera hasta 5 minutos por si el
    // usuario tarda en navegar hasta la carpeta correcta.
    function luaPickFolder(btn, inputId){
      var input = document.getElementById(inputId);
      var orig = btn.textContent;
      btn.disabled = true; btn.textContent = 'Abriendo…';
      function fail(msg){ btn.disabled = false; btn.textContent = orig; if (msg) alert(msg); }
      fetch('?ajax=pickfolder_start').then(function(r){ return r.json(); }).then(function(data){
        if (!data || data.error) { fail(data && data.error ? data.error : 'No se pudo pedir el selector de carpetas.'); return; }
        var tries = 0, maxTries = 430; // ~5 min a 700ms
        var iv = setInterval(function(){
          tries++;
          fetch('?ajax=pickfolder_poll&id='+encodeURIComponent(data.id)).then(function(r){ return r.json(); }).then(function(d){
            if (d.status === 'pending') {
              if (tries >= maxTries) { clearInterval(iv); fail('El watcher no respondió a tiempo.'); }
              return;
            }
            clearInterval(iv);
            btn.disabled = false; btn.textContent = orig;
            if (d.status === 'done' && d.path) { input.value = d.path; }
            else if (d.status === 'error') { alert('Error al abrir el selector: ' + (d.msg || 'desconocido')); }
          }).catch(function(){ clearInterval(iv); fail('Se perdió la conexión con el panel.'); });
        }, 700);
      }).catch(function(){ fail('No se pudo contactar con el panel.'); });
    }
  </script>

  <footer><?= e($brandName) ?> &middot; Apache + mod_fcgid &middot; panel solo accesible desde esta máquina</footer>

  <!-- Loader global: se muestra al pulsar cualquier boton/enlace que dispare una accion real
       (envio de formulario o navegacion), con el texto del propio boton/enlace pulsado. -->
  <div id="globalLoader" class="loader-overlay" hidden>
    <div class="loader-box">
      <div class="loader-spin"></div>
      <div class="loader-tx" id="globalLoaderText">Procesando…</div>
    </div>
  </div>
  <script>
    (function(){
      var overlay = document.getElementById('globalLoader');
      var textEl = document.getElementById('globalLoaderText');
      function labelFor(el){
        if (!el) return null;
        if (el.dataset && el.dataset.loadingText) return el.dataset.loadingText;
        var aria = el.getAttribute('aria-label') || el.getAttribute('title');
        if (aria) return aria;
        var txt = (el.textContent || '').replace(/\s+/g, ' ').trim();
        return txt || null;
      }
      var hideTimer = null;
      function show(el){
        textEl.textContent = labelFor(el) || 'Procesando…';
        overlay.hidden = false;
        // Red de seguridad: si por lo que sea la pagina no llega a navegar (error de
        // servidor, validacion que bloquea el envio tras mostrar el loader, etc.) el
        // aviso no debe quedarse pegado para siempre.
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function(){ overlay.hidden = true; }, 20000);
      }
      document.addEventListener('submit', function(e){
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || form.matches('.no-loader')) return;
        // Fase de burbuja + defaultPrevented: si el onsubmit del formulario cancelo el envio
        // (p.ej. porque abre un modal de confirmacion, o envia por AJAX), NO mostramos el
        // loader — antes saltaba en captura, antes de la cancelacion, y la pastilla se
        // quedaba flotando sobre el modal de confirmar.
        if (e.defaultPrevented) return;
        var submitter = e.submitter || form.querySelector('button[type=submit], button:not([type])');
        show(submitter || form);
      }, false);
      document.addEventListener('click', function(e){
        var a = e.target.closest('a[href]');
        if (!a || a.matches('.no-loader')) return;
        if (a.target === '_blank' || a.hasAttribute('download')) return;
        var href = a.getAttribute('href') || '';
        if (!href || href[0] === '#' || href.indexOf('javascript:') === 0) return;
        show(a);
      }, true);
      // El navegador puede restaurar la pagina desde el bfcache (boton Atras/Adelante)
      // con el DOM congelado tal cual estaba al salir, incluido el loader visible: hay
      // que ocultarlo siempre que la pagina se muestra, sea carga nueva o restaurada.
      window.addEventListener('pageshow', function(){
        clearTimeout(hideTimer);
        overlay.hidden = true;
      });
    })();
  </script>
</body>
</html>
