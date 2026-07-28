<?php
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

