<?php
// ---------------- Opciones de WordPress en la ficha de proyecto (debug/errores) ----------------
// Gatillo: la propia carpeta del proyecto (o una subcarpeta suya) tiene marcadores de
// WordPress -- mismo criterio que detect_project_type()/has_wp_markers() en sites-detect.php,
// para que "es WordPress" nunca se contradiga entre la etiqueta de tipo y esta card.
//
// Las constantes de depuracion de wp-config.php se leen/escriben con una edicion de texto
// puntual (regex sobre el define(...) existente, o insertado justo antes del marcador
// "That's all, stop editing!" si no existia) -- no se reescribe el archivo entero como el
// editor de .env de Laravel, porque wp-config.php no tiene una estructura KEY=VALOR uniforme
// que se preste a ello.

// Carpeta REAL donde vive WordPress dentro del proyecto (puede ser el propio $dir, o un nivel
// mas adentro si el .zip se descomprimio con su propia carpeta, WordPress en public/, etc.).
function wp_root_dir($dir){
    if (has_wp_markers($dir)) return $dir;
    if (is_dir($dir)) {
        foreach (scandir($dir) as $sub) {
            if ($sub[0] === '.') continue;
            $subDir = "$dir/$sub";
            if (is_dir($subDir) && has_wp_markers($subDir)) return $subDir;
        }
    }
    return null;
}
function wp_config_file($wpRoot){ return $wpRoot.'/wp-config.php'; }
function wp_debug_log_path($wpRoot){ return $wpRoot.'/wp-content/debug.log'; }

// Las constantes de depuracion que ofrece la card, en el orden en que se muestran.
function wp_debug_constants(){
    return [
        'WP_DEBUG'         => 'Modo debug (necesario para que las otras constantes surtan efecto)',
        'WP_DEBUG_DISPLAY' => 'Mostrar errores en pantalla',
        'WP_DEBUG_LOG'     => 'Guardar errores en wp-content/debug.log',
        'SCRIPT_DEBUG'     => 'Usar JS/CSS del núcleo sin minificar',
    ];
}
function wp_valid_debug_key($k){ return array_key_exists($k, wp_debug_constants()); }

function wp_define_regex($key){
    return '/define\s*\(\s*[\'"]'.preg_quote($key,'/').'[\'"]\s*,\s*([^)]*?)\s*\)\s*;/i';
}
// true/false si esta definida con ese valor booleano literal, la cadena cruda si trae otra
// cosa (p.ej. una ruta de log personalizada), o null si no esta definida en absoluto.
function wp_config_get_bool($content, $key){
    if (!preg_match(wp_define_regex($key), $content, $m)) return null;
    $val = trim($m[1]);
    if (preg_match('/^true$/i', $val)) return true;
    if (preg_match('/^false$/i', $val)) return false;
    return $val;
}
// Activa/desactiva una constante booleana: si ya estaba definida se sustituye SOLO esa linea;
// si no existia se inserta justo antes de "That's all, stop editing!" (mismo sitio donde
// WP-CLI y el propio wp-config-sample.php la esperan), o al principio del archivo como
// ultimo recurso si ese marcador no existe.
function wp_config_set_bool($content, $key, $bool){
    $line = "define( '".$key."', ".($bool ? 'true' : 'false')." );";
    if (preg_match(wp_define_regex($key), $content)) {
        return preg_replace(wp_define_regex($key), $line, $content, 1);
    }
    $marker = "/* That's all, stop editing!";
    $pos = stripos($content, $marker);
    if ($pos !== false) return substr($content, 0, $pos).$line."\n\n".substr($content, $pos);
    return preg_replace('/^<\?php/', "<?php\n".$line, $content, 1);
}
