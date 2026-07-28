<?php
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

