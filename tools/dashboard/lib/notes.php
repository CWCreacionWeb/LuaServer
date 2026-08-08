<?php
// ---------------- Notas tipo post-it en la ficha de proyecto ----------------
// Para apuntar lo que no cabe en sites.json y no vive en el codigo del proyecto: accesos al
// backoffice, datos de contacto del cliente, pasos de despliegue, avisos ("ojo, la pasarela
// esta en modo test")...
//
// Viven en data\notes\ y NO en config\ a proposito: /data/ esta ignorado por git ENTERO, asi
// que una nota con contrasenas no se puede colar en un commit ni acabar en el repo de un
// companero al actualizar. config\ solo esta ignorado archivo a archivo, y ahi es cuestion de
// tiempo que alguien anada una regla nueva y se deje esta fuera.
//
// Un archivo JSON por proyecto, no uno global: guardar una nota no reescribe las de los demas
// proyectos, y al borrar un proyecto basta con borrar su archivo.

function notes_colors(){
    return ['amber'=>'Amarillo', 'lime'=>'Verde', 'sky'=>'Azul', 'rose'=>'Rosa', 'violet'=>'Violeta'];
}
function notes_valid_color($c){ return array_key_exists((string)$c, notes_colors()) ? (string)$c : 'amber'; }

// Limites: una nota es un post-it, no un documento. Recortar en vez de rechazar evita perder
// lo que el usuario acaba de escribir por pasarse de largo.
const NOTES_MAX_TITLE = 120;
const NOTES_MAX_BODY  = 20000;

// Nombre de archivo seguro a partir de la clave del proyecto. Las claves normales ya son
// [a-z0-9_-] (lo validan el CLI y slug_from_name), pero sites.json se puede editar a mano:
// cualquier clave rara cae a un sha1, que no puede escaparse de la carpeta con ../ ni chocar
// con la de otro proyecto.
function notes_file($root, $name){
    $name = (string)$name;
    $safe = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,60}$/', $name) ? $name : 'x'.sha1($name);
    return $root.'/data/notes/'.$safe.'.json';
}

// Devuelve SIEMPRE una lista normalizada (cada nota con todas sus claves y un color valido),
// para que la vista no tenga que defenderse de un JSON editado a mano o de notas guardadas
// por una version anterior con menos campos.
function notes_read($root, $name){
    $d = read_json(notes_file($root, $name));
    if (!is_array($d)) return [];
    $out = [];
    foreach ($d as $n) {
        if (!is_array($n) || !isset($n['id'])) continue;
        $out[] = [
            'id'      => (string)$n['id'],
            'title'   => (string)($n['title'] ?? ''),
            'body'    => (string)($n['body'] ?? ''),
            'color'   => notes_valid_color($n['color'] ?? 'amber'),
            'created' => (int)($n['created'] ?? 0),
            'updated' => (int)($n['updated'] ?? 0),
        ];
    }
    return $out;
}

function notes_write($root, $name, $notes){
    $dir = $root.'/data/notes';
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) return false;
    write_json(notes_file($root, $name), array_values($notes));
    return is_file(notes_file($root, $name));
}

function notes_new_id(){ return bin2hex(random_bytes(8)); }

// Normaliza el texto que llega del formulario: los <textarea> mandan CRLF, y guardar dos veces
// la misma nota no debe cambiar el archivo. mb_substr (no substr) para no cortar un caracter
// multibyte por la mitad y dejar el JSON con un byte suelto invalido.
function notes_clean_text($s, $max){
    $s = str_replace(["\r\n", "\r"], "\n", (string)$s);
    return mb_substr($s, 0, $max);
}

function notes_index_of($notes, $id){
    foreach ($notes as $i => $n) { if ($n['id'] === (string)$id) return $i; }
    return null;
}

// Fecha corta para el pie del post-it. Sin "hace X minutos": el panel se sirve con PRG y
// recarga entera, asi que un relativo se queda congelado hasta la siguiente recarga y
// confunde mas que ayuda.
function notes_when($n){
    $ts = $n['updated'] ?: $n['created'];
    if (!$ts) return '';
    return ($n['updated'] && $n['updated'] !== $n['created'] ? 'Editada ' : 'Creada ').date('d/m/Y H:i', $ts);
}
