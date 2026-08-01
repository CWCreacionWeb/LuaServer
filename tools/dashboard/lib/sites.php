<?php
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

// Todas las extensiones que trae la instalacion de PHP para una version (bundladas de fabrica
// + las que el panel ha ido añadiendo a mano en config/php/extra-extensions.json), leyendo
// literalmente los .dll presentes en bin\php\<ver>\ext\ -- no una lista fija en PHP, para no
// tener que mantenerla en dos sitios a la vez que $WantExts (lua.ps1).
function php_ext_scan($base, $ver){
    $dir = "$base/$ver/ext";
    $names = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if (preg_match('/^php_(.+)\.dll$/i', $f, $m)) $names[] = strtolower($m[1]);
        }
    }
    sort($names);
    return $names;
}

// Extensiones realmente activas ahora mismo para una version: se lee el php.ini generado (no
// $WantExts/extra_extensions en PHP, para no duplicar esa logica) buscando las lineas
// "extension=..."/"zend_extension=..." SIN comentar que deja Set-PhpInis al final del archivo.
// Devuelve un array asociativo (nombre => true) para lookup O(1) con isset().
function php_ext_enabled($base, $ver){
    $ini = "$base/$ver/php.ini";
    $enabled = [];
    $txt = @file_get_contents($ini);
    if ($txt === false) return $enabled;
    if (preg_match_all('/^\s*(?:zend_extension|extension)\s*=\s*("?)([^\r\n;"]+)\1/mi', $txt, $ms)) {
        foreach ($ms[2] as $raw) {
            $n = strtolower(trim($raw));
            // Estilo antiguo (PHP < 7.2): "php_curl.dll" en vez de "curl".
            if (preg_match('/^php_(.+)\.dll$/i', $n, $m2)) $n = strtolower($m2[1]);
            $enabled[$n] = true;
        }
    }
    return $enabled;
}

