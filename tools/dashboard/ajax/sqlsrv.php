<?php
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

