<?php
// Convierte segundos a una duracion legible ("17 h 53 min", "2 min", "45 s"...). Como mucho
// dos unidades (la mayor que quepa + la siguiente), para que "hace X" se lea de un vistazo
// en vez de un numero de segundos en crudo (p.ej. el latido del watcher en la pestana Doctor).
function human_duration($seconds){
    $seconds = max(0, (int)$seconds);
    $units = [['d', 86400], ['h', 3600], ['min', 60], ['s', 1]];
    $parts = [];
    foreach ($units as $u) {
        [$label, $size] = $u;
        if ($seconds >= $size) {
            $n = intdiv($seconds, $size);
            $parts[] = $n.' '.$label;
            $seconds -= $n * $size;
            if (count($parts) === 2) break;
        }
    }
    return $parts ? implode(' ', $parts) : '0 s';
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

