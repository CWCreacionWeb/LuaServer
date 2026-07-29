<?php
    if ($action === 'mailpit') {
        $tab='config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) {
            @file_put_contents($ROOT.'/config/mailpit.on','1');
            if (is_file($ROOT.'/bin/mailpit/mailpit.exe')) { lua_apply(); $msg='applied:Mailpit activado. Buzón en http://localhost:8025'; }
            else {
                $id='mailpit-'.time();
                $job=['id'=>$id,'name'=>'mailpit','php'=>($cfg['defaultPhp']??'8.4'),'type'=>'mailpit','url'=>''];
                @mkdir($ROOT.'/tmp/jobs',0777,true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Descargando y activando Mailpit…';
            }
        } else { @unlink($ROOT.'/config/mailpit.on'); lua_apply(); $msg='applied:Mailpit desactivado.'; }
    }
    elseif ($action === 'mariadb') {
        $tab = ($_POST['from_tab'] ?? '') === 'proyectos' ? 'proyectos' : 'config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) {
            @file_put_contents($ROOT.'/config/mariadb.on','1');
            if (is_file($ROOT.'/bin/mariadb/bin/mysqld.exe')) {
                $msg='info:MySQL (MariaDB) activándose. Conecta en 127.0.0.1:3306, usuario root, sin contraseña.';
            } else {
                $id='mariadb-'.time();
                $job=['id'=>$id,'name'=>'mariadb','php'=>($cfg['defaultPhp']??'8.4'),'type'=>'mariadb','url'=>''];
                @mkdir($ROOT.'/tmp/jobs',0777,true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Descargando e instalando MariaDB (11.8 LTS, ~90 MB)… puede tardar un par de minutos.';
            }
        } else { @unlink($ROOT.'/config/mariadb.on'); $msg='info:MySQL (MariaDB) desactivándose.'; }
    }
    elseif ($action === 'postgres') {
        $tab = ($_POST['from_tab'] ?? '') === 'proyectos' ? 'proyectos' : 'config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) {
            @file_put_contents($ROOT.'/config/postgres.on','1');
            if (is_file($ROOT.'/bin/postgres/bin/pg_ctl.exe')) {
                $msg='info:PostgreSQL activándose. Conecta en 127.0.0.1:5432, usuario postgres, sin contraseña.';
            } else {
                $id='postgres-'.time();
                $job=['id'=>$id,'name'=>'postgres','php'=>($cfg['defaultPhp']??'8.4'),'type'=>'postgres','url'=>''];
                @mkdir($ROOT.'/tmp/jobs',0777,true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Descargando e instalando PostgreSQL 16 (~350 MB)… puede tardar unos minutos.';
            }
        } else { @unlink($ROOT.'/config/postgres.on'); $msg='info:PostgreSQL desactivándose.'; }
    }
    elseif ($action === 'mongodb') {
        $tab = ($_POST['from_tab'] ?? '') === 'proyectos' ? 'proyectos' : 'config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) {
            @file_put_contents($ROOT.'/config/mongodb.on','1');
            // Comprueba tambien mongo-express, no solo mongod.exe: son dos pasos independientes
            // del mismo job (ver case 'mongodb' de Run-Job) y uno puede faltar sin el otro
            // (p.ej. tras un fallo del build de mongo-express con MongoDB ya instalado). Si solo
            // se mirara mongod.exe, "Activar" se quedaria callado sin arreglar nada en ese caso.
            if (is_file($ROOT.'/bin/mongodb/bin/mongod.exe') && is_file($ROOT.'/bin/mongo-express/app.js')) {
                $msg='info:MongoDB activándose. Conecta en 127.0.0.1:27017, sin autenticación.';
            } else {
                $id='mongodb-'.time();
                $job=['id'=>$id,'name'=>'mongodb','php'=>($cfg['defaultPhp']??'8.4'),'type'=>'mongodb','url'=>''];
                @mkdir($ROOT.'/tmp/jobs',0777,true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Descargando e instalando MongoDB + Node.js + mongo-express (~400-500 MB en total)… puede tardar varios minutos.';
            }
        } else { @unlink($ROOT.'/config/mongodb.on'); $msg='info:MongoDB desactivándose.'; }
    }
    elseif ($action === 'redis') {
        $tab = ($_POST['from_tab'] ?? '') === 'proyectos' ? 'proyectos' : 'config';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) {
            @file_put_contents($ROOT.'/config/redis.on','1');
            // Igual que MongoDB: el job tiene dos partes independientes (servidor + extension de
            // PHP), asi que no basta con mirar el .exe. Si el servidor ya esta pero falta la
            // extension en alguna version, hay que volver a lanzar el job para completarla.
            $rExe  = is_file($ROOT.'/bin/redis/redis-server.exe');
            $rExtOk = false;
            if ($rExe) {
                // Basta con que la version por defecto la tenga: es la que usan el panel y los
                // proyectos nuevos. El job, si se lanza, completa igualmente todas las demas.
                $rDef = $cfg['defaultPhp'] ?? '8.4';
                $rExtOk = is_file($ROOT.'/bin/php/'.$rDef.'/ext/php_redis.dll');
            }
            if ($rExe && $rExtOk) {
                $msg='info:Redis activándose. Conecta en 127.0.0.1:6379, sin contraseña.';
            } else {
                // 'build' solo se usa si el servidor no esta instalado todavia; una vez instalado
                // se respeta el que haya (config\redis\build.txt) y el job solo completa la extension.
                $rBuild = ($_POST['build'] ?? '') === 'native5' ? 'native5' : 'redis8';
                $id='redis-'.time();
                $job=['id'=>$id,'name'=>'redis','php'=>($cfg['defaultPhp']??'8.4'),'type'=>'redis','url'=>'','build'=>$rBuild];
                @mkdir($ROOT.'/tmp/jobs',0777,true);
                file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                $msg='job:Descargando Redis ('.($rBuild==='native5'?'5.0.14.1 nativo':'8.8.1').') y la extensión php_redis para cada versión de PHP… puede tardar unos minutos.';
            }
        } else { @unlink($ROOT.'/config/redis.on'); $msg='info:Redis desactivándose.'; }
    }
    elseif ($action === 'startup') {
        $tab='config';
        $enable = ($_POST['enable'] ?? '') === '1';
        // Instalar/quitar un servicio de Windows y una tarea programada requiere admin
        // siempre (activar Y desactivar); el watcher lo recoge y se relanza elevado (UAC).
        if ($enable) { @file_put_contents($ROOT.'/tmp/startup-on.flag',(string)time()); $msg='info:Activando arranque con Windows: acepta el aviso de Windows (UAC). Instala el servicio de Apache y la tarea programada del watcher.'; }
        else { @file_put_contents($ROOT.'/tmp/startup-off.flag',(string)time()); $msg='info:Desactivando arranque con Windows: acepta el aviso de Windows (UAC).'; }
    }
