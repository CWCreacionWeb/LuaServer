<?php
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

