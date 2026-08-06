<?php
// ---------------- Editor de variables de entorno (.env) para proyectos Laravel ----------------
// Gatillo en la ficha de proyecto: $pHasArtisan (ya calculado ahi para el runner Composer/
// NPM/Artisan), no el 'type' guardado en sites.json -- ese solo se rellena al adoptar/detectar
// y puede faltar en proyectos integrados a mano, mientras que la presencia de "artisan" es una
// senal directa y siempre fiable de que el proyecto es Laravel.
//
// Cada linea del .env se trata como texto opaco salvo que case con KEY=VALOR: solo entonces se
// ofrece una fila editable (el resto -- comentarios, lineas en blanco, secciones -- se muestra
// tal cual, de solo lectura, para no perder la organizacion del archivo). Al guardar se
// reescribe SOLO la parte del valor de las lineas tocadas, conservando indentacion/espacios
// alrededor del "=" del original -- igual de exigente con el fiel round-trip que el editor de
// codigo general (ver ajax/file-tree.php), incluida la misma conversion de codepage si el
// archivo no es UTF-8 valido (proyectos legacy en Windows-1252).

function env_path($dir){ return $dir.'/.env'; }
function env_example_path($dir){ return $dir.'/.env.example'; }

// ¿La linea es "KEY = VALOR"? Devuelve la indentacion, la clave, el separador (con sus
// espacios) y el valor por separado, para poder reconstruir la linea cambiando solo el valor.
function env_match_kv($line){
    if (preg_match('/^(\s*)([A-Za-z_][A-Za-z0-9_.]*)(\s*=\s*)(.*)$/', $line, $m)) {
        return ['indent'=>$m[1], 'key'=>$m[2], 'eq'=>$m[3], 'value'=>$m[4]];
    }
    return null;
}
function valid_env_key($k){ return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$k); }

// Lee el .env de un proyecto preservando su estructura exacta: fin de linea (CRLF/LF),
// si el archivo termina en salto de linea, y la codificacion original (para reescribirla
// igual al guardar). Devuelve null si no existe .env.
function env_read_lines($dir){
    $f = env_path($dir);
    if (!is_file($f)) return null;
    $raw = @file_get_contents($f);
    if ($raw === false) return null;
    $enc = 'UTF-8';
    if (!mb_check_encoding($raw, 'UTF-8')) { $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'); $enc = 'Windows-1252'; }
    $eol = (strpos($raw, "\r\n") !== false) ? "\r\n" : "\n";
    $trailingNl = (substr($raw, -strlen($eol)) === $eol);
    $body = $trailingNl ? substr($raw, 0, -strlen($eol)) : $raw;
    $lines = $body === '' ? [] : preg_split('/\r\n|\n/', $body);
    return ['lines'=>$lines, 'eol'=>$eol, 'trailing_nl'=>$trailingNl, 'enc'=>$enc];
}
function env_write_lines($dir, $lines, $eol, $trailingNl, $enc = 'UTF-8'){
    $out = implode($eol, $lines);
    if ($trailingNl) $out .= $eol;
    if (strtoupper($enc) === 'WINDOWS-1252') $out = mb_convert_encoding($out, 'Windows-1252', 'UTF-8');
    return @file_put_contents(env_path($dir), $out) !== false;
}
// Filas editables (KEY/VALOR) de un .env ya leido con env_read_lines(), en el mismo
// orden del archivo. 'i' es el indice de linea real -- se usa para localizarla al
// guardar/borrar, no se renumera nunca.
function env_parse_rows($lines){
    $rows = [];
    foreach ($lines as $i => $line) {
        $m = env_match_kv($line);
        if ($m) $rows[] = ['i'=>$i, 'key'=>$m['key'], 'value'=>$m['value']];
    }
    return $rows;
}
