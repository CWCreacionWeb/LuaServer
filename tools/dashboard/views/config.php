  <?php if ($tab==='config'): /* ---------- PESTAÑA CONFIGURACIÓN DEL SERVIDOR ---------- */ ?>

    <?php
      $updErr    = $updSt['error'] ?? null;
      $updSucio  = !empty($updSt['sucio']);
      $updDelant = (int)($updSt['delante'] ?? 0);
      $updCuando = !empty($updSt['comprobado']) ? @strtotime($updSt['comprobado']) : 0;
    ?>
    <div class="cfg3">

      <div class="card cardsave" id="actualizaciones">
        <button type="button" class="savebtn" data-form="updCfgForm" title="Guardar">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
          </svg>
        </button>
        <div class="cfg3-body">
          <div class="cardsave-title" style="font-weight:600;margin-bottom:4px">Actualizaciones de la plataforma</div>
          <div class="muted" style="margin-bottom:12px">
            Versión instalada: <code><?= $luaVer !== '' ? e($luaVer) : 'desconocida' ?></code>
            <?php if ($updCuando): ?> &middot; comprobado <?= e(date('d/m/Y H:i', $updCuando)) ?><?php endif; ?>
          </div>

          <?php if ($updSt === null): ?>
            <div class="muted" style="font-size:12.5px">Aún no se ha comprobado. El watcher lo hace solo al arrancar y cada <?= (int)$updCfg['cada_horas'] ?> h.</div>
          <?php elseif ($updErr): ?>
            <div class="msgtext err">No se pudo consultar el repositorio: <?= e((string)$updErr) ?></div>
            <div class="muted" style="font-size:12px">El <code>fetch</code> lo hace el watcher con tus claves SSH. Si falla, comprueba que <code>git fetch</code> funciona a mano en esta carpeta.</div>
          <?php elseif ($updHay): ?>
            <div class="msgtext warn">Hay <?= (int)$updDetras ?> actualización(es) disponible(s) en <code><?= e((string)($updSt['remoto'] ?? 'origin')) ?></code>.</div>
            <?php if (!empty($updSt['mensaje'])): ?><pre class="joblog" style="margin-top:0"><?= e((string)$updSt['mensaje']) ?></pre><?php endif; ?>
          <?php else: ?>
            <div class="msgtext ok">Estás en la última versión.</div>
          <?php endif; ?>

          <?php if ($updSucio): ?>
            <div class="msgtext warn">Hay cambios locales sin confirmar en la carpeta de la plataforma. <b>No se actualizará automáticamente</b> para no pisarlos: confírmalos o descártalos primero.</div>
          <?php endif; ?>
          <?php if ($updDelant > 0): ?>
            <div class="msgtext warn">Tu copia va <?= (int)$updDelant ?> commit(s) por delante del remoto. La actualización automática se salta este caso para no decidir por ti cómo integrarlos.</div>
          <?php endif; ?>

          <details class="extform" style="margin-top:8px">
            <summary style="padding:0;font-size:12.5px;font-weight:400;color:var(--mut)">Actualizaciones automáticas</summary>
            <div style="padding-top:10px">
              <form method="post" id="updCfgForm">
                <input type="hidden" name="action" value="update_cfg">
                <label>Actualizaciones automáticas</label>
                <select name="auto">
                  <option value="0" <?= $updCfg['auto'] ? '' : 'selected' ?>>Solo avisar</option>
                  <option value="1" <?= $updCfg['auto'] ? 'selected' : '' ?>>Instalar automáticamente</option>
                </select>
                <label style="margin-top:8px">Comprobar cada</label>
                <select name="cada_horas">
                  <?php foreach ([1,3,6,12,24,72,168] as $h): ?>
                    <option value="<?= $h ?>" <?= (int)$updCfg['cada_horas']===$h ? 'selected' : '' ?>><?= $h < 24 ? $h.' h' : ($h/24).' día(s)' ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
              <div class="muted" style="margin-top:10px;font-size:11px">
                Se actualiza con <code>git merge --ff-only</code> desde <code>origin</code>, sin tocar tu configuración de esta máquina (<code>sites.json</code>, contraseñas, conexiones, <code>www\</code>).
              </div>
            </div>
          </details>
        </div>
        <div class="cfg3-actions">
          <form method="post" style="display:inline"><input type="hidden" name="action" value="update_check">
            <button class="btn ghost sm" type="submit">Buscar ahora</button></form>
          <?php if ($updHay && !$updSucio && $updDelant === 0): ?>
            <form method="post" style="display:inline"><input type="hidden" name="action" value="update_now">
              <button class="btn sm" type="submit">Actualizar a la última</button></form>
          <?php endif; ?>
        </div>
      </div>

      <div class="card cardsave">
        <button type="button" class="savebtn" data-form="brandNameForm" title="Guardar nombre">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
          </svg>
        </button>
        <div class="cfg3-body">
          <div class="cardsave-title" style="font-weight:600;margin-bottom:4px">Identidad de la plataforma</div>
          <div class="muted" style="margin-bottom:12px">Nombre y logo que aparecen en la cabecera, la pestaña del navegador y el pie. Solo afecta a este panel.</div>
          <form method="post" id="brandNameForm">
            <input type="hidden" name="action" value="set_brand">
            <label>Nombre</label>
            <input name="brand_name" value="<?= e($cfg['brand']['name'] ?? '') ?>" placeholder="lua-server" maxlength="40" style="width:100%">
          </form>
          <div class="muted" style="margin-top:6px;font-size:11px">Vacío = <code>lua-server</code>.</div>
          <div style="display:flex;align-items:center;gap:12px;margin-top:14px">
            <div class="logo" style="width:44px;height:44px;flex:0 0 auto;border:1px solid var(--line);border-radius:10px;padding:5px;background:var(--in)">
              <img src="<?= $brandLogo ? '?brandlogo&t='.filemtime($brandLogo) : 'assets/logo.svg' ?>" alt="logo" style="width:100%;height:100%;object-fit:contain">
            </div>
            <div>
              <form method="post" enctype="multipart/form-data" style="display:inline">
                <input type="hidden" name="action" value="brand_logo">
                <input type="file" name="img" accept="image/*" hidden onchange="this.form.requestSubmit()">
                <button type="button" class="btn ghost sm" onclick="this.parentNode.querySelector('input[type=file]').click()">Cambiar logo</button>
              </form>
              <?php if ($brandLogo): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="brand_logo_reset">
                <button class="btn ghost sm" type="submit">Restablecer</button>
              </form>
              <?php endif; ?>
              <div class="muted" style="margin-top:6px;font-size:11px">PNG, SVG, JPG… máx. 5 MB</div>
            </div>
          </div>
        </div>
      </div>

      <div class="card cardsave">
        <button type="button" class="savebtn" data-form="tldForm" title="Guardar dominio">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
          </svg>
        </button>
        <div class="cfg3-body">
          <div class="cardsave-title" style="font-weight:600;margin-bottom:4px">Dominio local</div>
          <div class="muted" style="margin-bottom:12px">Tus proyectos se sirven en <code>&lt;nombre&gt;.<?= e($tld) ?></code>. Alternativa reservada oficialmente para pruebas: <code>test</code>. Evita <code>dev</code> (Chrome fuerza HTTPS). <code>local</code> lo usa mDNS de Windows (resolución algo menos fiable), pero funciona.</div>
          <form method="post" id="tldForm">
            <input type="hidden" name="action" value="set_tld">
            <label>Dominio (TLD)</label>
            <input name="tld" value="<?= e($tld) ?>" placeholder="test" style="width:100%">
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Dominios <code>.<?= e($tld) ?></code> en el navegador</div>
          <div class="muted">Para que <code>&lt;nombre&gt;.<?= e($tld) ?></code> abra en el navegador hay que registrarlos en Windows (una vez). Si <code>localhost</code> te carga otra cosa (p. ej. Docker/Portainer por IPv6), usa <code><?= e($tld) ?></code> a secas: siempre te trae aquí.</div>
        </div>
        <div class="cfg3-actions">
          <form method="post">
            <input type="hidden" name="action" value="hosts">
            <button class="btn ghost" type="submit">Sincronizar dominios</button>
          </form>
        </div>
      </div>

    </div>

    <?php
      $httpsOn  = is_file($ROOT.'/config/https.on') && is_file($ROOT.'/data/ssl/lua.pem');
      $lanOn    = is_file($ROOT.'/config/lanexpose.on');
      $mailOn   = is_file($ROOT.'/config/mailpit.on');
      $mariaOn  = is_file($ROOT.'/config/mariadb.on');
      $pgOn     = is_file($ROOT.'/config/postgres.on');
      // Badges derivados del proceso real (no solo del flag): un flag huerfano no miente.
      [$mailCls,$mailLbl]   = svc_status($mailOn, 1025);
      [$mariaCls,$mariaLbl] = svc_status($mariaOn, 3306);
      [$pgCls,$pgLbl]       = svc_status($pgOn, 5432);
      $mongoOn  = is_file($ROOT.'/config/mongodb.on');
      $redisOn  = is_file($ROOT.'/config/redis.on');
      [$redisCls,$redisLbl] = svc_status($redisOn, 6379);
      // Build instalado ('redis8' / 'native5') y en que versiones de PHP quedo la extension: las
      // dos cosas se muestran en la card porque el motor puede estar arriba y aun asi faltarle la
      // extension a alguna version (son pasos independientes del job).
      $redisBuild = trim((string)@file_get_contents($ROOT.'/config/redis/build.txt'));
      $redisExtVers = [];
      foreach ($vers as $rv) { if (is_file($PHP_BASE.'/'.$rv.'/ext/php_redis.dll')) $redisExtVers[] = $rv; }
      $termOn   = is_file($ROOT.'/config/terminal.on');
      $startupOn= startup_enabled($ROOT);
      $lanIps = array_values(array_filter(array_map('trim', explode(',', (string)@file_get_contents($ROOT.'/config/lan-ip.txt'))),
                    function($x){ return $x!=='' && filter_var($x, FILTER_VALIDATE_IP); }));
      if (!$lanIps) {
          // Respaldo si el watcher aun no ha escrito las IPs: resolver el hostname (sin subproceso).
          $guess = @gethostbyname(@php_uname('n'));
          if ($guess && filter_var($guess, FILTER_VALIDATE_IP) && strpos($guess,'127.')!==0) $lanIps = [$guess];
      }
      $lanIp0 = $lanIps[0] ?? '<tu-IP-LAN>';
    ?>
    <div class="cfg3">

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">HTTPS local <span class="jstate <?= $httpsOn?'ok':'err' ?>"><?= $httpsOn?'ACTIVO':'INACTIVO' ?></span></div>
          <div class="muted">Certificados de confianza para <code>https://&lt;proyecto&gt;.<?= e($tld) ?></code> (candado verde). Al activar, Windows pedirá permiso para instalar la CA (una vez).</div>
        </div>
        <div class="cfg3-actions">
          <form method="post">
            <input type="hidden" name="action" value="https">
            <input type="hidden" name="enable" value="<?= $httpsOn?'0':'1' ?>">
            <button class="btn <?= $httpsOn?'danger':'ghost' ?>" type="submit"><?= $httpsOn?'Desactivar':'Activar' ?> HTTPS</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Exponer en la red local (LAN) <span class="jstate <?= $lanOn?'ok':'err' ?>"><?= $lanOn?'ACTIVO':'INACTIVO' ?></span></div>
          <div class="muted">Abre el puerto <?= $httpsOn?'80/443':'80' ?> en el Firewall de Windows (solo para tu subred local) para que otros dispositivos de tu misma red/WiFi puedan abrir tus proyectos. Al activar, Windows pedirá permiso (UAC). El panel de administración sigue restringido a esta máquina.</div>
          <?php if ($lanOn && $lanIps): ?>
            <div class="muted" style="margin-top:8px;font-size:12px">Tu IP en la red local: <?php foreach ($lanIps as $i=>$ip): ?><code><?= e($ip) ?></code><?= $i<count($lanIps)-1?' · ':'' ?><?php endforeach; ?>. Desde otro equipo, añade a <em>su</em> <code>hosts</code>:<br>
              <code><?= e($lanIp0) ?>&nbsp;&nbsp;&lt;proyecto&gt;.<?= e($tld) ?></code></div>
          <?php endif; ?>
        </div>
        <div class="cfg3-actions">
          <form method="post">
            <input type="hidden" name="action" value="lanexpose">
            <input type="hidden" name="enable" value="<?= $lanOn?'0':'1' ?>">
            <button class="btn <?= $lanOn?'danger':'ghost' ?>" type="submit"><?= $lanOn?'Dejar de exponer':'Exponer' ?></button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Mailpit <span class="jstate <?= $mailCls ?>"><?= $mailLbl ?></span></div>
          <div class="muted">Atrapa los emails que envían tus proyectos PHP (SMTP <code>127.0.0.1:1025</code>) y los muestra en un buzón web. No salen a internet.</div>
        </div>
        <div class="cfg3-actions">
          <?php if ($mailOn): ?><a class="btn ghost" href="http://localhost:8025" target="_blank">Abrir buzón &#8599;</a><?php endif; ?>
          <form method="post">
            <input type="hidden" name="action" value="mailpit">
            <input type="hidden" name="enable" value="<?= $mailOn?'0':'1' ?>">
            <button class="btn <?= $mailOn?'danger':'ghost' ?>" type="submit"><?= $mailOn?'Desactivar':'Activar' ?> Mailpit</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Servidor MySQL (MariaDB) <span class="jstate <?= $mariaCls ?>"><?= $mariaLbl ?></span></div>
          <div class="muted">Nativo (MariaDB 11.8 LTS) en <code>127.0.0.1:3306</code>, usuario <code>root</code> <?= mysql_root_pass($ROOT)!==''?'con contraseña':'sin contraseña' ?>. Solo accesible desde esta máquina. Gestiona <code>root</code> y crea usuarios en <a href="?tab=bd">Bases de datos</a>.</div>
        </div>
        <div class="cfg3-actions">
          <?php if ($mariaOn): ?><a class="btn ghost" href="?tab=bd">Bases de datos</a> <a class="btn ghost" href="http://<?= e($phpmyadminDom) ?>/" target="_blank">phpMyAdmin &#8599;</a> <a class="btn ghost" href="/adminer.php?server=127.0.0.1&username=root" target="_blank">Adminer &#8599;</a><?php endif; ?>
          <form method="post">
            <input type="hidden" name="action" value="mariadb">
            <input type="hidden" name="enable" value="<?= $mariaOn?'0':'1' ?>">
            <button class="btn <?= $mariaOn?'danger':'ghost' ?>" type="submit"><?= $mariaOn?'Desactivar':'Activar' ?> MySQL</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Servidor PostgreSQL <span class="jstate <?= $pgCls ?>"><?= $pgLbl ?></span></div>
          <div class="muted">Nativo (PostgreSQL 16) en <code>127.0.0.1:5432</code>, usuario <code>postgres</code> sin contraseña. Solo accesible desde esta máquina. Crea bases de datos y roles en <a href="?tab=bd&engine=pg">Bases de datos</a>.</div>
        </div>
        <div class="cfg3-actions">
          <?php if ($pgOn): ?><a class="btn ghost" href="?tab=bd&engine=pg">Bases de datos</a> <a class="btn ghost" href="/adminer.php?pgsql=127.0.0.1&username=postgres&db=postgres" target="_blank">Adminer &#8599;</a><?php endif; ?>
          <form method="post">
            <input type="hidden" name="action" value="postgres">
            <input type="hidden" name="enable" value="<?= $pgOn?'0':'1' ?>">
            <button class="btn <?= $pgOn?'danger':'ghost' ?>" type="submit"><?= $pgOn?'Desactivar':'Activar' ?> PostgreSQL</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Servidor MongoDB <span class="jstate <?= $mongoOn?'ok':'err' ?>"><?= $mongoOn?'ACTIVO':'INACTIVO' ?></span></div>
          <div class="muted">Nativo (MongoDB Community) en <code>127.0.0.1:27017</code>, sin autenticación. Solo accesible desde esta máquina. Gestión visual vía <code>mongo-express</code> (Node.js, se instala junto al motor).</div>
        </div>
        <div class="cfg3-actions">
          <?php if ($mongoOn): ?><a class="btn ghost" href="http://127.0.0.1:8081/" target="_blank">mongo-express &#8599;</a><?php endif; ?>
          <form method="post">
            <input type="hidden" name="action" value="mongodb">
            <input type="hidden" name="enable" value="<?= $mongoOn?'0':'1' ?>">
            <button class="btn <?= $mongoOn?'danger':'ghost' ?>" type="submit"><?= $mongoOn?'Desactivar':'Activar' ?> MongoDB</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Servidor Redis <span class="jstate <?= $redisCls ?>"><?= $redisLbl ?></span></div>
          <div class="muted">Almacén en memoria (caché, sesiones, colas) en <code>127.0.0.1:6379</code>, sin contraseña. Solo accesible desde esta máquina. Se instala también la extensión <code>php_redis</code> en cada versión de PHP.</div>
          <?php if ($redisOn || $redisBuild !== ''): ?>
            <div class="muted" style="margin-top:8px;font-size:11.5px">
              <?php if ($redisBuild !== ''): ?>
                Build: <code><?= $redisBuild==='native5' ? 'tporadowski 5.0.14.1 (nativo)' : 'redis-windows 8.8.1' ?></code><br>
              <?php endif; ?>
              <?php if ($redisExtVers): ?>
                Extensión en PHP <?= e(implode(', ', $redisExtVers)) ?>.
              <?php else: ?>
                <span class="msgtext warn" style="margin:0">La extensión <code>php_redis</code> aún no está en ninguna versión de PHP.</span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if (!$redisOn && $redisBuild === ''): ?>
            <div style="margin-top:12px">
              <label>Build de Redis para Windows</label>
              <select name="build" id="redisBuildSel">
                <option value="redis8">Redis 8.8.1 — al día, sobre capa msys2</option>
                <option value="native5">Redis 5.0.14.1 — nativo, congelado en 2022</option>
              </select>
              <div class="muted" style="margin-top:6px;font-size:11px">Redis no publica builds oficiales para Windows: ambas son ports de la comunidad. Solo se pregunta la primera vez.</div>
            </div>
          <?php endif; ?>
        </div>
        <div class="cfg3-actions">
          <form method="post">
            <input type="hidden" name="action" value="redis">
            <input type="hidden" name="enable" value="<?= $redisOn?'0':'1' ?>">
            <?php if (!$redisOn && $redisBuild === ''): ?>
              <!-- El <select> vive arriba, fuera de este <form>: se copia aqui al enviar para no
                   partir el layout de la card (cuerpo arriba, acciones abajo). -->
              <input type="hidden" name="build" id="redisBuildHidden" value="redis8">
            <?php endif; ?>
            <button class="btn <?= $redisOn?'danger':'ghost' ?>" type="submit"><?= $redisOn?'Desactivar':'Activar' ?> Redis</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Terminal <span class="jstate <?= $termOn?'ok':'err' ?>"><?= $termOn?'ACTIVA':'INACTIVA' ?></span></div>
          <div class="muted">Ejecuta comandos (composer, git, npm, artisan…) desde el navegador con la misma cuenta que Apache. Desactivada por defecto por seguridad: solo actívala si confías en quién tiene acceso a esta máquina.</div>
        </div>
        <div class="cfg3-actions">
          <form method="post">
            <input type="hidden" name="action" value="terminal">
            <input type="hidden" name="enable" value="<?= $termOn?'0':'1' ?>">
            <button class="btn <?= $termOn?'danger':'ghost' ?>" type="submit"><?= $termOn?'Desactivar':'Activar' ?> Terminal</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="cfg3-body">
          <div style="font-weight:600;margin-bottom:4px">Arrancar con Windows <span class="jstate <?= $startupOn?'ok':'err' ?>"><?= $startupOn?'ACTIVO':'INACTIVO' ?></span></div>
          <div class="muted">Instala Apache como servicio de Windows (arranque automático) y el watcher como tarea programada (arranca sin necesidad de iniciar sesión). Al activar o desactivar, Windows pedirá permiso (UAC).</div>
        </div>
        <div class="cfg3-actions">
          <form method="post">
            <input type="hidden" name="action" value="startup">
            <input type="hidden" name="enable" value="<?= $startupOn?'0':'1' ?>">
            <button class="btn <?= $startupOn?'danger':'ghost' ?>" type="submit"><?= $startupOn?'Desactivar':'Activar' ?></button>
          </form>
        </div>
      </div>

    </div>

    <script>
      // El selector de build de Redis esta en el cuerpo de la card y el boton en su pie (dos
      // sitios distintos), asi que el valor se copia al campo oculto del form al cambiarlo.
      (function(){
        var sel = document.getElementById('redisBuildSel'), hid = document.getElementById('redisBuildHidden');
        if (sel && hid) { sel.addEventListener('change', function(){ hid.value = sel.value; }); }
      })();

      // Guardado de las cards de ajustes por el icono de disquete. Va por AJAX (el backend
      // responde JSON cuando recibe ajax=1, ver el hook junto al redirect de PRG) para poder
      // confirmar en verde sobre el propio icono sin recargar y perder el aviso.
      (function(){
        document.addEventListener('click', function(e){
          var btn = e.target.closest('.savebtn'); if (!btn) return;
          var form = document.getElementById(btn.dataset.form); if (!form) return;
          var card = btn.closest('.card');
          var out  = card.querySelector('.savemsg');
          if (!out) { out = document.createElement('div'); out.className = 'savemsg'; card.querySelector('.cfg3-body').appendChild(out); }
          var body = new URLSearchParams(new FormData(form)); body.set('ajax','1');
          btn.classList.remove('ok','err'); btn.disabled = true; out.textContent = '';
          fetch('?', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
            .then(function(r){ return r.json(); })
            .then(function(j){
              btn.disabled = false;
              btn.classList.add(j.ok ? 'ok' : 'err');
              out.className = 'savemsg ' + (j.ok ? 'ok' : 'err');
              out.textContent = j.msg || (j.ok ? 'Guardado.' : 'No se pudo guardar.');
              // El nombre de marca sale en la cabecera y en el titulo de la pestaña: se
              // refrescan aqui para que el cambio se vea sin tener que recargar a mano.
              // Ojo: el <h1> lleva detras la etiqueta de version (<a class="verchip">), asi
              // que se reescribe solo su primer nodo de texto, no el textContent entero.
              if (j.ok && form.id === 'brandNameForm') {
                var nuevo = (form.querySelector('[name=brand_name]').value || '').trim() || 'lua-server';
                var h = document.querySelector('header h1');
                if (h && h.firstChild && h.firstChild.nodeType === 3) { h.firstChild.nodeValue = nuevo; }
                var lg = document.querySelector('header .logo img'); if (lg) lg.alt = nuevo;
                document.title = nuevo;
              }
              if (j.ok && j.reload) { setTimeout(function(){ location.href = '?tab=config'; }, 1200); }
              else if (j.ok) { setTimeout(function(){ btn.classList.remove('ok'); out.textContent = ''; }, 4000); }
            })
            .catch(function(){
              btn.disabled = false; btn.classList.add('err');
              out.className = 'savemsg err'; out.textContent = 'Error de red al guardar.';
            });
        });
      })();
    </script>


<?php endif; ?>
