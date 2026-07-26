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
    $pType = is_array($info) ? ($info['type'] ?? null) : null;
    $pTypeLabel = project_type_label($pType);
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
                <?php if ($termOn && ($hasComposer || $hasNpm)): ?>
                  <button type="button" class="runbtn lua-runbtn" title="Ejecutar Composer/NPM" aria-label="Ejecutar Composer/NPM" data-name="<?= e($name) ?>" data-path="<?= e(term_win($pdir)) ?>" data-composer="<?= $hasComposer?'1':'0' ?>" data-npm="<?= $hasNpm?'1':'0' ?>" data-php="<?= e($ver) ?>">
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
// Sincroniza el config.inc.php de phpMyAdmin (auth_type=config) con la contraseña de root.
// Parchea IN SITU las dos líneas (no regenera: perdería el blowfish_secret aleatorio). Sin
// esto, tras fijar contraseña phpMyAdmin seguía enviando '' y MariaDB lo rechazaba. No-op
// si phpMyAdmin no está instalado.
function pma_sync_root_pass($root, $pass){
    $f = $root.'/tools/phpmyadmin/config.inc.php';
    if (!is_file($f)) return;
    $c = @file_get_contents($f);
    if ($c === false) return;
    $lit   = "'".addcslashes($pass, "\\'")."'";
    $allow = $pass === '' ? 'true' : 'false';
    // preg_replace_callback: el reemplazo se devuelve literal (no se parsean $1/\ del valor).
    $c = preg_replace_callback("/(\\\$cfg\\['Servers'\\]\\[\\\$i\\]\\['password'\\]\\s*=\\s*)[^\\r\\n]*;/",
            function($m) use ($lit){ return $m[1].$lit.';'; }, $c, 1);
    $c = preg_replace_callback("/(\\\$cfg\\['Servers'\\]\\[\\\$i\\]\\['AllowNoPassword'\\]\\s*=\\s*)[^\\r\\n]*;/",
            function($m) use ($allow){ return $m[1].$allow.';'; }, $c, 1);
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
            if ($row['User'] === '' || in_array($row['User'], $sys, true)) continue;
            $out[] = ['user'=>$row['User'], 'host'=>$row['Host']];
        }
        usort($out, function($a,$b){ return [$a['user'],$a['host']] <=> [$b['user'],$b['host']]; });
        return $out;
    } catch (Throwable $e) { return null; }
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

// ---------------- Endpoints AJAX de la terminal (devuelven JSON, no PRG) ----------------
$__ta = $_REQUEST['action'] ?? '';
if ($__ta==='term_run' || $__ta==='term_poll' || $__ta==='term_stop') {
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
        if (preg_match('/^\d\.\d$/', $reqPhp) && is_dir($PHP_BASE.'/'.$reqPhp)) { $pathParts[] = term_win($PHP_BASE.'/'.$reqPhp); }
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
            unset($cfg['sites'][$name]); write_json($CFG_FILE,$cfg); lua_apply();
            $msg='applied:Proyecto "'.$name.'" eliminado (la carpeta del proyecto se conserva).';
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
            if (is_file($ROOT.'/bin/mongodb/bin/mongod.exe')) {
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
            pma_sync_root_pass($ROOT, $new); // que phpMyAdmin siga entrando tras el cambio
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
    elseif ($action === 'terminal') {
        $tab='config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) { @file_put_contents($ROOT.'/config/terminal.on','1'); $msg='applied:Terminal activada. Ejecuta comandos desde la pestaña Terminal.'; }
        else { @unlink($ROOT.'/config/terminal.on'); $msg='applied:Terminal desactivada.'; }
    }

    header('Location: ?tab='.$tab.(isset($ver)?'&ver='.urlencode($ver):'').($redirName?'&name='.urlencode($redirName):'').(isset($tab_engine)?'&engine='.urlencode($tab_engine):'').'&msg='.urlencode($msg));
    exit;
}

// ---------------- GET (render) ----------------
$cfg = read_json($CFG_FILE) ?: ['defaultPhp'=>'8.4','tld'=>'lua.test','sites'=>[]];
$brandName = brand_name($cfg);
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
  .btn-git{display:inline-flex;align-items:center;gap:8px;background:#161b22;color:#fff;border:1px solid #30363d;border-radius:5px;padding:8px 16px;font-size:14px;font-family:inherit;line-height:1.4;font-weight:600;cursor:pointer;transition:background-color .12s}
  .btn-git:hover{background:#22272e}

  .dbrow{display:flex;align-items:center;flex-wrap:wrap;gap:16px;padding:14px 0;border-top:1px solid var(--line)}
  .dbrow:first-of-type{border-top:none}
  .dbrow .dbname{font-weight:600;font-family:ui-monospace,Consolas,monospace;font-size:13px}
  .dbactions{display:flex;align-items:center;gap:16px;min-width:420px;justify-content:flex-end}
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
  .sitecard.is-locked .lockbtn{color:var(--warn);border-color:var(--warn);background:rgba(210,153,34,.12)}
  .trashbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0;border:1px solid transparent;border-radius:5px;color:#fff;cursor:pointer;background:linear-gradient(135deg,#ff8a80,var(--err));transition:filter .12s,transform .12s}
  .trashbtn:hover{filter:brightness(1.12)}
  .trashbtn:active{transform:scale(.94)}
  .pwrbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0;border:1px solid transparent;border-radius:5px;color:#fff;cursor:pointer;background:linear-gradient(135deg,#ff8a80,var(--err));transition:filter .12s,transform .12s}
  .pwrbtn:hover{filter:brightness(1.12)}
  .pwrbtn:active{transform:scale(.94)}
  .toollink{display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:6px 10px;border:1px solid var(--line);border-radius:5px;color:var(--mut);font-size:12px;font-weight:600;text-decoration:none;transition:color .12s,border-color .12s,background-color .12s}
  .toollink:hover{color:var(--ac);border-color:var(--ac);background:rgba(110,168,254,.08)}
  .runbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0 0 0 1px;background:var(--card);border:1px solid var(--line);border-radius:5px;color:var(--mut);cursor:pointer;transition:color .12s,border-color .12s}
  .runbtn:hover{color:var(--ac);border-color:var(--ac)}
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
  .modal-actions .btn{width:auto;padding:8px 18px}

  .loader-overlay{position:fixed;inset:0;background:rgba(6,7,10,.5);display:flex;align-items:center;justify-content:center;z-index:200}
  .loader-overlay[hidden]{display:none}
  .loader-box{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:20px 28px;box-shadow:0 20px 60px rgba(0,0,0,.45);display:flex;align-items:center;gap:14px;max-width:min(90vw,420px)}
  .loader-spin{width:22px;height:22px;flex:0 0 auto;border-radius:999px;border:3px solid var(--line);border-top-color:var(--ac);animation:loaderspin .7s linear infinite}
  .loader-tx{font-size:14px;font-weight:600;color:var(--tx);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  @keyframes loaderspin{to{transform:rotate(360deg)}}

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
      <h1><?= e($brandName) ?></h1>
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

    <?php $mariaOn = is_file($ROOT.'/config/mariadb.on'); [$mariaCls,$mariaLbl] = svc_status($mariaOn, 3306); $termOn = is_file($ROOT.'/config/terminal.on'); $runPresets = run_presets_load($ROOT); ?>
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
        <div class="row" style="gap:6px;margin-bottom:10px">
          <input type="text" id="runnerCustomCmd" placeholder="Comando personalizado, p.ej. npm run dev" style="flex:1" maxlength="200">
          <button type="button" class="btn ghost sm" id="runnerAddBtn" title="Guardar como acceso rápido y ejecutarlo">+ Guardar</button>
        </div>
        <div id="runnerOut" class="termout" style="height:280px;border:1px solid var(--line);border-radius:6px;background:var(--in)"></div>
      </div>
    </div>
    <script>
      (function(){
        var modal=document.getElementById('runnerModal'), title=document.getElementById('runnerTitle'),
            btnsEl=document.getElementById('runnerBtns'), out=document.getElementById('runnerOut'),
            stopBtn=document.getElementById('runnerStop'),
            addBtn=document.getElementById('runnerAddBtn'), customInput=document.getElementById('runnerCustomCmd');
        var sid=null, path=null, phpVer=null, running=false, curRun=null, curBuiltins=[];
        var savedPresets=<?= json_encode($runPresets, JSON_UNESCAPED_SLASHES) ?>;

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

        // Construida con la API del DOM (textContent + closures), no innerHTML: los comandos
        // guardados son texto libre del usuario y podrian llevar comillas u otros caracteres
        // que romperian un atributo onclick="..." armado a mano.
        function renderBtns(){
          btnsEl.innerHTML='';
          curBuiltins.forEach(function(p){
            var b=document.createElement('button');
            b.type='button'; b.className='btn ghost sm'; b.textContent=p[0];
            b.onclick=function(){ luaRunPreset(p[1]); };
            btnsEl.appendChild(b);
          });
          savedPresets.forEach(function(cmd){
            var wrap=document.createElement('span');
            wrap.style.display='inline-flex'; wrap.style.gap='2px';
            var b=document.createElement('button');
            b.type='button'; b.className='btn ghost sm'; b.textContent=cmd; b.title='Ejecutar';
            b.onclick=function(){ luaRunPreset(cmd); };
            var d=document.createElement('button');
            d.type='button'; d.className='btn ghost sm'; d.textContent='×'; d.title='Eliminar acceso rapido';
            d.onclick=function(){ luaDelPreset(cmd); };
            wrap.appendChild(b); wrap.appendChild(d);
            btnsEl.appendChild(wrap);
          });
        }

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
            body:'action=term_run&sid='+sid+'&php='+encodeURIComponent(phpVer||'')+'&cmd='+encodeURIComponent(full)})
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

        function luaDelPreset(cmd){
          fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'action=run_preset_del&cmd='+encodeURIComponent(cmd)})
          .then(r=>r.json()).then(function(j){
            if(j.presets){ savedPresets=j.presets; renderBtns(); }
          });
        }
        addBtn.onclick=function(){
          var cmd=customInput.value.trim();
          if(!cmd || running) return;
          addBtn.disabled=true;
          fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'action=run_preset_add&cmd='+encodeURIComponent(cmd)})
          .then(r=>r.json()).then(function(j){
            addBtn.disabled=false;
            if(j.error){ append('<span class="a-r">'+esc(j.error)+'</span>\n'); return; }
            savedPresets=j.presets; customInput.value=''; renderBtns();
            luaRunPreset(cmd);
          }).catch(function(){ addBtn.disabled=false; });
        };
        customInput.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); addBtn.click(); } });

        window.luaOpenRunner=function(name, projectPath, hasComposer, hasNpm, phpVersion){
          path=projectPath; phpVer=phpVersion||null;
          sid=(function(){var a=new Uint8Array(10);crypto.getRandomValues(a);return Array.from(a).map(b=>b.toString(16).padStart(2,'0')).join('');})();
          title.textContent='Ejecutar en '+name;
          out.innerHTML=''; running=false; curRun=null; stopBtn.disabled=true; customInput.value='';
          curBuiltins=[];
          if(hasComposer){ curBuiltins.push(['composer install','composer install'],['composer update','composer update']); }
          if(hasNpm){ curBuiltins.push(['npm install','npm install'],['npm run build','npm run build']); }
          renderBtns();
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
          luaOpenRunner(btn.dataset.name, btn.dataset.path, btn.dataset.composer==='1', btn.dataset.npm==='1', btn.dataset.php);
        });
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

    <div class="cfg3">

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Identidad de la plataforma</div>
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
        <div class="cfg3-actions">
          <button class="btn ghost" type="submit" form="brandNameForm">Guardar nombre</button>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Dominio local</div>
          <div class="muted" style="margin-bottom:12px">Tus proyectos se sirven en <code>&lt;nombre&gt;.<?= e($tld) ?></code>. Recomendado <code>test</code> (reservado para pruebas). Evita <code>dev</code> (Chrome fuerza HTTPS) y <code>local</code> (lo usa mDNS de Windows).</div>
          <form method="post" id="tldForm">
            <input type="hidden" name="action" value="set_tld">
            <label>Dominio (TLD)</label>
            <input name="tld" value="<?= e($tld) ?>" placeholder="test" style="width:100%">
          </form>
        </div>
        <div class="cfg3-actions">
          <button class="btn ghost" type="submit" form="tldForm">Guardar</button>
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

  <?php elseif ($tab==='bd'): /* ---------- PESTAÑA BASES DE DATOS ---------- */
      $mariaOn = is_file($ROOT.'/config/mariadb.on');
      $pgOn    = is_file($ROOT.'/config/postgres.on');
      $mongoOn = is_file($ROOT.'/config/mongodb.on');
      $rootHasPass = mysql_root_pass($ROOT) !== '';
      $mysqlUsers = $mariaOn ? mysql_users() : null;
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
    </div>
    <?php if ($mongoOn): ?>
      <div class="muted" style="margin-bottom:16px;font-size:12px">MongoDB no usa SQL, así que no tiene un listado de bases de datos aquí: gestiónalo desde <b>mongo-express</b> (arriba).</div>
    <?php endif; ?>

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
              <button type="button" class="btn ghost" onclick="luaCloseImportPg()">Cancelar</button>
              <button type="button" class="btn danger" onclick="luaConfirmImportPg()">Sí, importar</button>
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
          function luaConfirmImportPg(){ luaCloseImportPg(); if(luaImportPgForm) luaImportPgForm.requestSubmit(); }
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
          // requestSubmit() (no submit()): dispara el evento 'submit' de verdad, para que
          // el loader global aparezca durante la importacion real (que puede tardar si el
          // .sql es grande) en vez de no mostrarse nunca.
          if (luaImportDbForm) luaImportDbForm.requestSubmit();
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
        <h4>Ejecutar Composer / NPM en un proyecto</h4>
        <p>En cada card con <code>composer.json</code> o <code>package.json</code> aparece un botón de <strong>play</strong> que abre un modal para lanzar comandos sobre ese proyecto (<code>composer install</code>, <code>npm run dev</code>…). Puedes escribir <strong>comandos personalizados y guardarlos</strong> como accesos rápidos reutilizables. El runner usa el PHP propio del proyecto automáticamente.</p>
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
