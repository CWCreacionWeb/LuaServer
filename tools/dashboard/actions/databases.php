<?php
    if ($action === 'db_create') {
        $tab = 'bd';
        $db = trim($_POST['dbname'] ?? '');
        $charset = $_POST['charset'] ?? 'utf8mb4';
        if (!valid_dbname($db)) { $msg='error:Nombre de base de datos no válido (letras, números, _).'; }
        elseif (!array_key_exists($charset, mysql_charsets())) { $msg='error:Codificación no válida.'; }
        else {
            try { mysql_pdo()->exec('CREATE DATABASE `'.$db.'` CHARACTER SET '.$charset);
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
    // Import de un .sql subido (boton "Importar" de una BD). Se hace como job en segundo plano
    // (igual que db_import_dir, ver mas abajo) en vez de bloquear este worker de Apache con
    // proc_open+stream_get_contents: un .sql grande podia superar max_execution_time, y de
    // paso permite reportar progreso real (% de bytes) en vez de solo "correcto"/"fallo" al final.
    elseif ($action === 'db_import') {
        $tab = 'bd';
        $db = $_POST['dbname'] ?? '';
        if (!valid_dbname($db)) { $msg='error:Nombre de base de datos no válido.'; }
        elseif (empty($_FILES['sqlfile']) || ($_FILES['sqlfile']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $msg='error:No se recibió el archivo .sql.'; }
        else {
            $id = 'dbimportfile-'.time();
            @mkdir($ROOT.'/tmp/imports', 0777, true);
            $dest = $ROOT.'/tmp/imports/'.$id.'.sql';
            if (!move_uploaded_file($_FILES['sqlfile']['tmp_name'], $dest)) { $msg='error:No se pudo guardar el archivo subido.'; }
            else {
                $job = ['id'=>$id,'type'=>'db_import_file','name'=>$db,'dbname'=>$db,'file'=>str_replace('\\','/',$dest)];
                @mkdir($ROOT.'/tmp/jobs', 0777, true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Importando archivo en "'.$db.'"… mira el progreso abajo.';
            }
        }
    }
    // Importa una carpeta con un .sql por tabla (p.ej. mysqldump --tab o un export similar,
    // sin un unico dump completo) en una BD ya existente. Se hace como job en segundo plano
    // (lo ejecuta el watcher, no este propio worker de Apache): puede tratarse de decenas de
    // archivos y cientos de MB en total, muy por encima de max_execution_time/post_max_size.
    elseif ($action === 'db_import_dir') {
        $tab = 'bd';
        $db  = trim($_POST['dbname'] ?? '');
        $dir = rtrim(str_replace('\\','/', trim($_POST['dir'] ?? '')), '/');
        if (!valid_dbname($db)) { $msg='error:Nombre de base de datos no válido.'; }
        elseif (!in_array($db, mysql_databases() ?: [], true)) { $msg='error:Esa base de datos no existe todavía -- créala primero arriba.'; }
        elseif ($dir === '' || !is_dir($dir)) { $msg='error:Esa carpeta no existe en este servidor.'; }
        else {
            $sqlFiles = glob($dir.'/*.sql');
            if (!$sqlFiles) { $msg='error:No hay archivos .sql en esa carpeta.'; }
            else {
                $id = 'dbimport-'.time();
                $job = ['id'=>$id,'type'=>'db_import_dir','name'=>$db,'dbname'=>$db,'dir'=>$dir];
                @mkdir($ROOT.'/tmp/jobs', 0777, true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Importando '.count($sqlFiles).' archivos .sql en "'.$db.'"… mira el progreso abajo.';
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
            pma_sync_servers($ROOT); // que phpMyAdmin siga entrando tras el cambio
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
                // Se guarda para poder ofrecer esta cuenta en el desplegable de conexiones de
                // phpMyAdmin (pma_sync_servers) sin volver a teclear la contraseña.
                mysql_user_save_password($ROOT, $u, $h, $p);
                pma_sync_servers($ROOT);
                $msg='applied:Usuario "'.$u.'@'.$h.'" creado, con acceso a '.($scope==='db'?('"'.$db.'"'):'todas las bases de datos').'.';
                // GRANT sobre una BD que aun no existe no da error en MariaDB (el permiso queda
                // guardado y se aplica solo en cuanto la BD se crea) -- se avisa para que no
                // parezca que la asociacion "no ha hecho nada" si el nombre estaba mal escrito.
                if ($scope === 'db' && !in_array($db, mysql_databases() ?: [], true)) {
                    $msg .= ' Aviso: la base de datos "'.$db.'" todavía no existe -- el acceso se activará en cuanto la crees (revisa que el nombre esté bien escrito).';
                }
            } catch (Throwable $e) { $msg='error:No se pudo crear el usuario: '.$e->getMessage(); }
        }
    }
    elseif ($action === 'mysql_user_delete') {
        $tab='bd';
        $u = trim($_POST['username'] ?? '');
        $h = $_POST['host'] ?? '';
        if (!valid_mysql_user($u) || !valid_mysql_host($h)) { $msg='error:Usuario u host no válido.'; }
        else {
            try {
                mysql_pdo()->exec("DROP USER '".$u."'@'".$h."'");
                mysql_user_forget_password($ROOT, $u, $h);
                pma_sync_servers($ROOT);
                $msg='applied:Usuario "'.$u.'@'.$h.'" eliminado.';
            }
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
    // ---- SQL Server: alta/baja de conexiones guardadas (pestana SQL Server) ----
