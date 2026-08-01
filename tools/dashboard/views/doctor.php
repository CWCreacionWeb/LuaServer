  <?php if ($tab==='doctor'): /* ---------- PESTAÑA DOCTOR (diagnostico) ---------- */
      // Cada comprobacion apunta [grupo, estado ok|warn|err|info, titulo, detalle]. Se calcula
      // todo en servidor al cargar: son lecturas rapidas (netstat+tasklist ~200ms y ficheros).
      $checks = [];
      $addChk = function($grupo,$estado,$titulo,$detalle='') use (&$checks){ $checks[] = ['g'=>$grupo,'s'=>$estado,'t'=>$titulo,'d'=>$detalle]; };
      $lst   = doctor_listeners();
      $pname = doctor_procnames();
      $who   = function($port, $soloV6=null) use ($lst,$pname){
          $r = [];
          foreach ($lst as $l) {
              if ($l['port'] !== $port) continue;
              if ($soloV6 !== null && $l['v6'] !== $soloV6) continue;
              $r[] = ['n'=>($pname[$l['pid']] ?? ('PID '.$l['pid'])), 'pid'=>$l['pid'], 'addr'=>$l['addr'], 'v6'=>$l['v6']];
          }
          return $r;
      };

      // ---- Watcher ----
      $beatF = $ROOT.'/tmp/watch.beat';
      $beatAge = is_file($beatF) ? (time() - (int)trim((string)@file_get_contents($beatF))) : -1;
      if ($beatAge >= 0 && $beatAge <= 15) {
          $addChk('Watcher','ok','Watcher activo', 'Latido hace '.$beatAge.'s.');
      } else {
          $d = $beatAge < 0 ? 'Nunca ha latido (o se limpió tmp\).' : 'Último latido hace '.human_duration($beatAge).'.';
          $d .= ' Sin watcher no hay jobs, ni HTTPS/hosts, ni supervisor de procesos.';
          if (startup_enabled($ROOT)) { $d .= ' <b>Ojo</b>: "Arrancar con Windows" está activo — puede haber un watcher de SYSTEM con código antiguo (no late). Reinícialo desde una consola elevada: <code>Stop-ScheduledTask -TaskName lua-server-watcher; Start-ScheduledTask -TaskName lua-server-watcher</code> (trampa nº1 de CLAUDE.md).'; }
          else { $d .= ' <form method="post" class="inline" style="display:inline;margin-top:6px"><input type="hidden" name="action" value="start_watcher"><button class="btn ghost sm" type="submit">Arrancar watcher</button></form>'; }
          $addChk('Watcher','err','Watcher sin latido', $d);
      }

      // ---- Apache / puerto 80 y 443 ----
      // OJO: en Windows un mismo puerto puede tener VARIOS listeners IPv4 a la vez (0.0.0.0 de
      // un contenedor de Docker + 127.0.0.1 de Apache conviven; para 127.0.0.1 gana el bind mas
      // especifico). Por eso se evaluan TODOS los listeners, no el primero que da netstat.
      $puerto = function($port, $esperado, $v6=false) use ($who){
          $ls = $who($port, $v6);
          $mios = array_filter($ls, function($l) use ($esperado){ return stripos($l['n'], $esperado) !== false; });
          $otros = array_filter($ls, function($l) use ($esperado){ return stripos($l['n'], $esperado) === false; });
          return [$ls, array_values($mios), array_values($otros)];
      };
      [$l80, $ap80, $ot80] = $puerto(80, 'httpd');
      if ($ap80) {
          $d = 'Escucha <code>'.e($ap80[0]['addr']).'</code>.';
          if ($ot80) { $d .= ' También escucha <code>'.e($ot80[0]['n']).'</code> en <code>'.e($ot80[0]['addr']).'</code>: convive porque para <code>127.0.0.1</code> gana el bind más específico (Apache), pero desde la LAN responderá el otro.'; }
          $addChk('Red y puertos', $ot80 ? 'warn' : 'ok', 'Puerto 80 (IPv4): Apache', $d);
      }
      elseif ($l80) { $addChk('Red y puertos','err','Puerto 80 (IPv4) ocupado por otro proceso', 'Lo tiene <code>'.e($l80[0]['n']).'</code> (PID '.$l80[0]['pid'].'): Apache no puede servir en el 80.'); }
      else { $addChk('Red y puertos','err','Nadie escucha el puerto 80 en IPv4', '¿Apache caído? Revisa <code>logs\apache\error.log</code>.'); }
      [$l80v6, $ap80v6, $ot80v6] = $puerto(80, 'httpd', true);
      if ($ot80v6) {
          $addChk('Red y puertos','warn','Puerto 80 en IPv6 ocupado por '.e($ot80v6[0]['n']), 'Windows resuelve <code>localhost</code> mezclando IPv4 e IPv6, así que <code>http://localhost</code> puede llevarte a otro sitio (Portainer/Docker). Usa <code>http://127.0.0.1</code> o <code>http://'.e($tld).'</code> (trampa nº2 de CLAUDE.md).');
      }
      if (is_file($ROOT.'/config/https.on')) {
          [$l443, $ap443, $ot443] = $puerto(443, 'httpd');
          if ($ap443) { $addChk('Red y puertos', $ot443?'warn':'ok', 'Puerto 443 (HTTPS): Apache', $ot443 ? 'También lo escucha <code>'.e($ot443[0]['n']).'</code> en <code>'.e($ot443[0]['addr']).'</code>.' : ''); }
          elseif ($l443) { $addChk('Red y puertos','err','Puerto 443 ocupado por '.e($l443[0]['n']),'HTTPS está activado pero el puerto lo tiene otro proceso.'); }
          else { $addChk('Red y puertos','warn','HTTPS activado pero nadie escucha el 443','¿Se quedó a medias la configuración? Prueba a desactivar y reactivar HTTPS.'); }
      }

      // ---- Motores: flag vs binario vs puerto ----
      $motores = [
          ['MySQL (MariaDB)','mariadb.on', $ROOT.'/bin/mariadb/bin/mysqld.exe', 3306, 'mysqld'],
          ['PostgreSQL','postgres.on', $ROOT.'/bin/postgres/bin/pg_ctl.exe', 5432, 'postgres'],
          ['MongoDB','mongodb.on', $ROOT.'/bin/mongodb/bin/mongod.exe', 27017, 'mongod'],
          ['Redis (nativo)','redis.on', $ROOT.'/bin/redis/redis-server.exe', 6379, 'redis-server'],
          ['Mailpit','mailpit.on', $ROOT.'/bin/mailpit/mailpit.exe', 8025, 'mailpit'],
      ];
      foreach ($motores as [$mNom,$mFlag,$mBin,$mPort,$mProc]) {
          $on = is_file($ROOT.'/config/'.$mFlag);
          if (!$on) continue;   // apagado: nada que diagnosticar
          if (!is_file($mBin)) {
              $addChk('Motores','err',$mNom.': activado pero NO instalado', 'Existe <code>config\\'.$mFlag.'</code> pero falta <code>'.e(basename($mBin)).'</code>: la instalación falló o quedó a medias. Desactívalo y actívalo de nuevo desde Configuración, y mira <code>logs\jobs\</code>.');
              continue;
          }
          [$wl, $wMio, $wOtro] = $puerto($mPort, $mProc);
          if (!$wl) { $addChk('Motores','warn',$mNom.': nada escucha el puerto '.$mPort, 'El watcher lo arranca solo (con reintentos cada 30s si falla). Si no levanta, mira su log en <code>logs\</code>.'); }
          elseif ($wMio && $wOtro) { $addChk('Motores','warn',$mNom.' corriendo en el '.$mPort.', pero COMPARTIDO', 'También lo escucha <code>'.e($wOtro[0]['n']).'</code> en <code>'.e($wOtro[0]['addr']).'</code> (¿un contenedor de Docker?). Conectando a <code>127.0.0.1:'.$mPort.'</code> responde el nativo (bind más específico), pero es fácil confundirse de servidor: considera dejar solo uno.'); }
          elseif ($wMio) { $addChk('Motores','ok',$mNom.' corriendo en el '.$mPort, ''); }
          else { $addChk('Motores','err',$mNom.': el puerto '.$mPort.' lo tiene <code>'.e($wOtro[0]['n']).'</code>', 'Con el puerto ocupado (¿un contenedor de Docker?) el motor nativo NO va a poder arrancar, y el watcher lo reintentará para siempre. O paras ese proceso, o desactivas el motor nativo en Configuración.'); }
      }
      if (is_file($ROOT.'/config/mongodb.on') && is_file($ROOT.'/bin/mongo-express/app.js')) {
          $w81 = $who(8081, false);
          if ($w81 && stripos($w81[0]['n'],'node') !== false) { $addChk('Motores','ok','mongo-express corriendo en el 8081',''); }
          elseif ($w81) { $addChk('Motores','err','El puerto 8081 lo tiene <code>'.e($w81[0]['n']).'</code>','mongo-express no podrá escuchar ahí.'); }
          else { $addChk('Motores','warn','mongo-express: nada escucha el 8081','El watcher lo arranca cuando MongoDB está arriba.'); }
      }

      // ---- Config generada con rutas de OTRA carpeta (repo movido sin re-init) ----
      $rootFwd = str_replace('\\','/',$ROOT);
      foreach ([['config/mariadb/my.ini','MariaDB'],['config/mongodb/mongod.cfg','MongoDB'],['config/redis/redis.conf','Redis']] as [$cfgRel,$cfgNom]) {
          $f = $ROOT.'/'.$cfgRel;
          if (!is_file($f)) continue;
          $txt = (string)@file_get_contents($f);
          // Busca rutas absolutas de Windows y comprueba que TODAS cuelgan de la carpeta actual.
          if (preg_match_all('~[A-Za-z]:[/\\\\][^\s"\']+~', $txt, $mm)) {
              $ajenas = array_filter($mm[0], function($p) use ($rootFwd){ return stripos(str_replace('\\','/',$p), $rootFwd) !== 0; });
              if ($ajenas) { $addChk('Config','err',$cfgNom.': su config apunta a otra carpeta', 'En <code>'.e($cfgRel).'</code> hay rutas fuera de <code>'.e($ROOT).'</code> (p.ej. <code>'.e(reset($ajenas)).'</code>). ¿Se movió la carpeta de la plataforma? Ejecuta <code>.\lua.ps1 init</code> y <code>start</code>.'); }
              else { $addChk('Config','ok',$cfgNom.': rutas de su config correctas',''); }
          }
      }

      // ---- Proyectos y vhosts ----
      $vhostFiles = array_map(function($f){ return basename($f, '.conf'); }, (array)glob($ROOT.'/config/apache/vhosts/*.conf'));
      $domEff = [];
      foreach ($sites as $sn => $sv) {
          if (!in_array($sn, $vhostFiles, true)) { $addChk('Proyectos','err','El proyecto "'.e($sn).'" no tiene vhost', 'Está en <code>sites.json</code> pero falta <code>config\apache\vhosts\\'.e($sn).'.conf</code>: regenera con <code>.\lua.ps1 reload</code> (o el botón Aplicar del panel).'); }
          $base = project_dir($WWW, $sv, $sn);
          if (!is_dir($base)) { $addChk('Proyectos','err','La carpeta de "'.e($sn).'" no existe', '<code>'.e($base).'</code> no está en el disco: Apache devolverá 403/404. Si lo borraste a propósito, elimina el proyecto del panel.'); }
          $dom = strtolower(!empty($sv['domain']) ? $sv['domain'] : $sn.'.'.$tld);
          if (isset($domEff[$dom])) { $addChk('Proyectos','err','Dominio duplicado: <code>'.e($dom).'</code>', 'Lo usan "'.e($domEff[$dom]).'" y "'.e($sn).'". Apache sirve el que carga primero y el otro queda muerto en silencio.'); }
          $domEff[$dom] = $sn;
      }
      foreach ($vhostFiles as $vf) {
          if (!isset($sites[$vf])) { $addChk('Proyectos','warn','Vhost huérfano: <code>'.e($vf).'.conf</code>', 'No corresponde a ningún proyecto registrado. Un <code>.\lua.ps1 reload</code> lo limpia.'); }
      }
      if (!array_filter($checks, function($c){ return $c['g']==='Proyectos'; })) { $addChk('Proyectos','ok','Proyectos y vhosts cuadran', count($sites).' proyecto(s), '.count($vhostFiles).' vhost(s).'); }

      // ---- hosts de Windows ----
      $hostsTxt = (string)@file_get_contents(getenv('WINDIR').'/System32/drivers/etc/hosts');
      $faltanHosts = [];
      foreach ($domEff as $dom => $sn) { if ($hostsTxt !== '' && stripos($hostsTxt, $dom) === false) { $faltanHosts[] = $dom; } }
      if ($hostsTxt === '') { $addChk('Proyectos','warn','No se pudo leer el archivo hosts de Windows',''); }
      elseif ($faltanHosts) { $addChk('Proyectos','warn','Dominios sin registrar en el hosts de Windows', '<code>'.e(implode('</code>, <code>', array_slice($faltanHosts,0,6))).'</code>'.(count($faltanHosts)>6?' y '.(count($faltanHosts)-6).' más':'').' no abrirán en el navegador. Pulsa <b>Sincronizar dominios</b> en Configuración.'); }
      else { $addChk('Proyectos','ok','Todos los dominios están en el hosts de Windows',''); }

      // ---- PHP ----
      foreach ($vers as $v) {
          $mal = [];
          if (!is_file($PHP_BASE.'/'.$v.'/php-cgi.exe')) $mal[] = 'php-cgi.exe';
          if (!is_file($PHP_BASE.'/'.$v.'/php.ini'))     $mal[] = 'php.ini';
          if (!is_file($ROOT.'/config/php/'.$v.'.overrides.ini')) $mal[] = $v.'.overrides.ini';
          if ($mal) { $addChk('PHP','err','PHP '.e($v).' incompleto', 'Falta: <code>'.e(implode('</code>, <code>',$mal)).'</code>. Un <code>.\lua.ps1 init</code> regenera los ini.'); }
      }
      if (!array_filter($checks, function($c){ return $c['g']==='PHP'; })) { $addChk('PHP','ok','Versiones de PHP completas', implode(', ', $vers).'.'); }

      // ---- Sistema ----
      $free = @disk_free_space($ROOT);
      if ($free !== false) {
          $gb = $free / 1073741824;
          if ($gb < 1)      { $addChk('Sistema','err','Disco casi lleno', number_format($gb,1).' GB libres: MySQL/MongoDB pueden corromperse al quedarse sin espacio.'); }
          elseif ($gb < 5)  { $addChk('Sistema','warn','Poco espacio en disco', number_format($gb,1).' GB libres.'); }
          else              { $addChk('Sistema','ok','Espacio en disco', number_format($gb,0).' GB libres.'); }
      }
      $gordos = [];
      foreach ((array)glob($ROOT.'/logs/*/*.log') as $lf) { if (@filesize($lf) > 104857600) { $gordos[] = basename(dirname($lf)).'/'.basename($lf).' ('.round(filesize($lf)/1048576).' MB)'; } }
      if ($gordos) { $addChk('Sistema','warn','Logs muy grandes', e(implode(', ', array_slice($gordos,0,5))).'. Vacíalos desde la pestaña Logs.'); }
      $updDoc = update_status($ROOT);
      if (!empty($updDoc['sucio'])) { $addChk('Sistema','info','Cambios locales sin confirmar en la plataforma', 'La actualización automática se bloquea sola para no pisarlos (pestaña Configuración → Actualizaciones).'); }
      if ((int)($updDoc['detras'] ?? 0) > 0) { $addChk('Sistema','info','Hay '.(int)$updDoc['detras'].' actualización(es) de la plataforma', 'Instálala desde Configuración → Actualizaciones.'); }

      // Orden: errores primero dentro de cada grupo, grupos en orden de aparicion.
      $orden = ['err'=>0,'warn'=>1,'info'=>2,'ok'=>3];
      $grupos = [];
      foreach ($checks as $c) { $grupos[$c['g']][] = $c; }
      foreach ($grupos as &$gl) { usort($gl, function($a,$b) use ($orden){ return $orden[$a['s']] <=> $orden[$b['s']]; }); }
      unset($gl);
      $nErr = count(array_filter($checks, function($c){ return $c['s']==='err'; }));
      $nWarn = count(array_filter($checks, function($c){ return $c['s']==='warn'; }));
  ?>

    <div class="card row" style="flex-wrap:wrap;gap:8px">
      <div style="min-width:260px">
        <div style="font-weight:600">Doctor</div>
        <div class="muted" style="margin-top:4px">Comprobación automática de los problemas conocidos de la plataforma: puertos robados, watcher caído, motores a medias, vhosts descuadrados…</div>
      </div>
      <div class="spacer"></div>
      <?php if ($nErr): ?><span class="jstate err"><?= $nErr ?> ERROR(ES)</span><?php endif; ?>
      <?php if ($nWarn): ?><span class="jstate" style="color:var(--warn);border-color:var(--warn)"><?= $nWarn ?> AVISO(S)</span><?php endif; ?>
      <?php if (!$nErr && !$nWarn): ?><span class="jstate ok">TODO EN ORDEN</span><?php endif; ?>
      <a class="btn ghost sm" href="?tab=doctor">Volver a comprobar</a>
    </div>

    <div class="pgrid2">
    <?php foreach ($grupos as $gNom => $gChecks): ?>
      <div class="card" style="margin-bottom:0">
        <div style="font-weight:600;margin-bottom:10px"><?= e($gNom) ?></div>
        <?php foreach ($gChecks as $c): ?>
          <div class="row" style="gap:10px;align-items:flex-start;padding:7px 0;border-top:1px solid var(--line)">
            <?php if ($c['s']==='ok'): ?>
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--ok)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;margin-top:2px"><polyline points="20 6 9 17 4 12"/></svg>
            <?php elseif ($c['s']==='err'): ?>
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--err)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <?php elseif ($c['s']==='warn'): ?>
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--warn)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;margin-top:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <?php else: ?>
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="var(--ac)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <?php endif; ?>
            <div style="min-width:0">
              <div style="font-size:13px;font-weight:600"><?= $c['t'] /* puede llevar <code> propio, ya escapado al construirse */ ?></div>
              <?php if ($c['d'] !== ''): ?><div class="muted" style="font-size:12px;margin-top:2px"><?= $c['d'] ?></div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    </div>


<?php endif; ?>
