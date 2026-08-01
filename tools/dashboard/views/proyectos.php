  <?php if ($tab==='proyectos'): ?>

    <?php
      $mariaOn = is_file($ROOT.'/config/mariadb.on'); [$mariaCls,$mariaLbl] = svc_status($mariaOn, 3306); $termOn = is_file($ROOT.'/config/terminal.on');
      // Valores por defecto de las contrasenas del wizard de WordPress: se generan en cada
      // carga del formulario (no se guardan en ningun sitio hasta que el usuario le da a
      // "Crear") para que ya se vea algo razonable sin escribir nada, pero siguen siendo
      // 100% editables -- la BD se crea con lo que haya en el campo al enviar, sea el valor
      // generado o uno propio.
      $wpDefDbPass = bin2hex(random_bytes(6)); $wpDefAdminPass = bin2hex(random_bytes(6));
    ?>
    <div class="row" style="margin-bottom:14px">
      <button type="button" class="btn" onclick="luaOpenNewProject()">+ Nuevo proyecto</button>
    </div>

    <div class="topgrid">
      <div class="card" style="display:flex;flex-direction:column">
        <div class="row" style="gap:6px">
          <div style="font-weight:600">Servidor MySQL (MariaDB) <span class="jstate <?= $mariaCls ?>" style="margin-left:6px"><?= $mariaLbl ?></span></div>
          <div class="spacer"></div>
          <a class="lockbtn" href="?tab=bd" title="Configuración de bases de datos" aria-label="Configuración de bases de datos">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          </a>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="mariadb">
            <input type="hidden" name="enable" value="<?= $mariaOn?'0':'1' ?>">
            <input type="hidden" name="from_tab" value="proyectos">
            <button type="submit" class="pwrbtn" title="<?= $mariaOn?'Desactivar servidor MySQL':'Crear / activar servidor MySQL' ?>" aria-label="<?= $mariaOn?'Desactivar servidor MySQL':'Crear / activar servidor MySQL' ?>">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
            </button>
          </form>
        </div>
        <div class="muted" style="margin-top:6px">Nativo en <code>127.0.0.1:3306</code>, usuario <code>root</code> <?= mysql_root_pass($ROOT)!==''?'con contraseña':'sin contraseña' ?>.</div>
        <div class="spacer"></div>
        <?php if ($mariaOn): ?>
          <div class="row" style="gap:8px;margin-top:10px">
            <a class="toollink" href="http://<?= e($phpmyadminDom) ?>/" target="_blank" style="flex:1">phpMyAdmin &#8599;</a>
            <a class="toollink" href="/adminer.php?server=127.0.0.1&username=root" target="_blank" style="flex:1" title="Adminer pide contraseña: crea un usuario con clave para tu proyecto, o usa bin\mariadb\bin\mariadb.exe.">Adminer &#8599;</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Modal: alta de proyecto, en dos partes -- arriba crear uno nuevo (con la instalacion
         guiada de WordPress), abajo (colapsado) registrar uno ya existente en otra carpeta. -->
    <div id="newProjectModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseNewProject()">
      <div class="modal-box" role="dialog" aria-modal="true" style="max-width:680px;max-height:85vh;overflow-y:auto;text-align:left">
        <div class="row" style="margin-bottom:14px">
          <h3 style="margin:0;font-size:16px">Nuevo proyecto</h3>
          <div class="spacer"></div>
          <button type="button" class="lockbtn" onclick="luaCloseNewProject()" title="Cerrar" aria-label="Cerrar">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>

        <form method="post">
          <input type="hidden" name="action" value="create">
          <div class="inline">
            <div>
              <label>Nombre del proyecto</label>
              <input name="name" id="newprojname" placeholder="micliente" pattern="[a-z0-9][a-z0-9_-]*" required>
            </div>
            <div>
              <label>Tipo</label>
              <select name="type" id="newprojtype" onchange="luaNewProjTypeChange(this.value)">
                <option value="blank">PHP en blanco</option>
                <option value="laravel">Laravel</option>
                <option value="wordpress">WordPress</option>
                <option value="symfony">Symfony</option>
                <option value="slim">Slim</option>
                <option value="git">Desde Git…</option>
              </select>
            </div>
            <div>
              <label>Versión de PHP</label>
              <select name="php">
                <?php foreach ($vers as $v): ?>
                  <option value="<?= e($v) ?>" <?= $v===$defaultPhp?'selected':'' ?>>PHP <?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div id="gitrow" style="display:none;margin-top:12px">
            <label>URL del repositorio Git</label>
            <input name="url" placeholder="https://github.com/usuario/repo.git" style="width:100%">
          </div>
          <?php if ($mariaOn): ?>
            <label id="withdbrow" style="display:flex;align-items:center;gap:6px;margin-top:12px;font-weight:400;cursor:pointer">
              <input type="checkbox" name="withdb" value="1" checked style="width:auto">
              Crear base de datos MySQL a juego (mismo nombre)
            </label>
          <?php endif; ?>

          <?php if ($mariaOn): ?>
            <div id="wprow" style="display:none;margin-top:16px;padding-top:14px;border-top:1px solid var(--line)">
              <div class="muted" style="font-size:12px;margin-bottom:10px">Instalación guiada: la base de datos, el usuario de MySQL y el sitio se crean solos con estos datos exactos -- no hace falta pasar por la pantalla de instalación de WordPress.</div>
              <div style="font-weight:600;margin-bottom:8px;font-size:13px">Paso 2 — Base de datos</div>
              <div class="inline">
                <div><label>Nombre de la BD</label><input name="wp_dbname" id="wpdbname" placeholder="micliente" pattern="[a-zA-Z0-9_]{1,64}"></div>
                <div><label>Usuario de la BD</label><input name="wp_dbuser" id="wpdbuser" placeholder="wp_micliente" pattern="[a-zA-Z0-9_]{1,32}"></div>
                <div><label>Contraseña de la BD</label><input type="text" name="wp_dbpass" value="<?= e($wpDefDbPass) ?>" autocomplete="off"></div>
              </div>
              <div style="font-weight:600;margin:14px 0 8px;font-size:13px">Paso 3 — Sitio</div>
              <div class="inline">
                <div><label>Título del sitio</label><input name="wp_title" id="wptitle" placeholder="Mi sitio WordPress"></div>
                <div><label>Usuario admin</label><input name="wp_adminuser" value="admin"></div>
                <div><label>Contraseña admin</label><input type="text" name="wp_adminpass" value="<?= e($wpDefAdminPass) ?>" autocomplete="off"></div>
                <div><label>Email admin</label><input type="email" name="wp_adminemail" placeholder="tu@email.com"></div>
              </div>
            </div>
          <?php else: ?>
            <div id="wprow" class="muted" style="display:none;margin-top:12px;font-size:12px">Activa MariaDB en <a href="?tab=config">Configuración del servidor</a> para poder crear proyectos WordPress: la instalación guiada necesita crear su base de datos.</div>
          <?php endif; ?>
          <div class="row" style="margin-top:16px">
            <div class="spacer"></div>
            <button class="btn" type="submit">+ Crear</button>
          </div>
        </form>
        <div class="muted" style="margin-top:10px">Laravel/Symfony/Slim usan Composer; WordPress hace una instalación guiada completa (BD + usuario MySQL + sitio, listo para entrar a <code>/wp-admin</code>); Git clona el repo (y ejecuta <code>composer install</code> si hay <code>composer.json</code>). Se hace en segundo plano.<?= $mariaOn?' En Laravel, la conexión se escribe sola en el <code>.env</code>.':'' ?></div>

        <details class="extform" style="margin-top:20px;padding-top:16px;border-top:1px solid var(--line)">
          <summary>Registrar proyecto existente en otra carpeta del disco <span class="muted">(p.ej. <code>C:\proyectos\micliente</code> con dominio propio)</span></summary>
          <form method="post" style="margin-top:14px">
            <input type="hidden" name="action" value="add_external">
            <div class="inline">
              <div>
                <label>Nombre (identificador)</label>
                <input name="name" placeholder="micliente" pattern="[a-z0-9][a-z0-9_-]*" required>
              </div>
              <div style="flex:1;min-width:280px">
                <label>Ruta de la carpeta en disco</label>
                <input name="path" placeholder="C:\proyectos\micliente" style="width:100%" required>
              </div>
              <div>
                <label>Dominio</label>
                <input name="domain" placeholder="portal.ersm.test">
              </div>
              <div>
                <label>PHP</label>
                <select name="php">
                  <?php foreach ($vers as $v): ?>
                    <option value="<?= e($v) ?>" <?= $v===$defaultPhp?'selected':'' ?>>PHP <?= e($v) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn" type="submit">Registrar</button>
            </div>
            <div class="muted" style="margin-top:10px">No copia ni mueve nada: apunta el vhost a esa carpeta (usa <code>public/</code> si existe, para Laravel/Symfony). Si pones un dominio propio, luego pulsa <b>Sincronizar dominios</b> en Configuración del servidor para registrarlo en Windows.</div>
          </form>
        </details>
      </div>
    </div>
    <script>
      function luaOpenNewProject(){
        document.getElementById('newProjectModal').hidden = false;
        document.addEventListener('keydown', luaEscNewProject);
      }
      function luaCloseNewProject(){
        document.getElementById('newProjectModal').hidden = true;
        document.removeEventListener('keydown', luaEscNewProject);
      }
      function luaEscNewProject(e){ if(e.key==='Escape') luaCloseNewProject(); }
      function luaNewProjTypeChange(t){
        var isGit = (t === 'git'), isWp = (t === 'wordpress');
        var gitrow = document.getElementById('gitrow'); if (gitrow) gitrow.style.display = isGit ? 'block' : 'none';
        var wprow = document.getElementById('wprow'); if (wprow) wprow.style.display = isWp ? 'block' : 'none';
        var withdbrow = document.getElementById('withdbrow'); if (withdbrow) withdbrow.style.display = isWp ? 'none' : 'flex';
        document.querySelectorAll('#wprow input[name^="wp_"]').forEach(function(el){
          if (isWp) el.setAttribute('required','required'); else el.removeAttribute('required');
        });
      }
      (function(){
        // Autocompleta nombre de BD/usuario/titulo a partir del nombre del proyecto, pero
        // solo mientras el usuario no haya tocado esos campos a mano (dataset.touched):
        // una vez editados, dejan de seguir al nombre del proyecto.
        var nameEl = document.getElementById('newprojname');
        var dbn = document.getElementById('wpdbname'), dbu = document.getElementById('wpdbuser'), ttl = document.getElementById('wptitle');
        if (!nameEl) return;
        [dbn, dbu, ttl].forEach(function(el){ if (el) el.addEventListener('input', function(){ this.dataset.touched='1'; }); });
        nameEl.addEventListener('input', function(){
          var v = this.value.trim();
          var slug = v.replace(/[^a-zA-Z0-9_]/g, '_');
          if (dbn && !dbn.dataset.touched) dbn.value = slug;
          if (dbu && !dbu.dataset.touched) dbu.value = slug ? ('wp_' + slug).slice(0, 32) : '';
          if (ttl && !ttl.dataset.touched) ttl.value = v;
        });
      })();
      <?php if ($reopenNewProject): ?>
      luaOpenNewProject();
      <?php endif; ?>
    </script>

    <?php if ($jobs): ?>
      <div class="row" style="margin:22px 0 10px">
        <h2 style="margin:0">Tareas</h2>
        <div class="spacer"></div>
        <form method="post"><input type="hidden" name="action" value="clearjobs"><button class="btn ghost sm">Limpiar historial</button></form>
      </div>
      <?php foreach (array_slice($jobs,0,8) as $j):
            $st=$j['state']??'?'; $cls=['done'=>'ok','error'=>'err','running'=>'run','queued'=>'warn'];
            $c=$cls[$st]??'run';
            $tail = in_array($st,['running','error','queued'],true) ? job_log_tail($ROOT, $j['id']??'') : ''; ?>
        <div class="card" style="padding:12px 16px">
          <div class="row">
            <span class="jstate <?= $c ?>"><?= e(strtoupper($st)) ?></span>
            <span style="font-weight:700"><?= e($j['name']??'') ?></span>
            <span class="muted"><?= e($j['type']??'') ?><?= isset($j['time'])?' · '.e($j['time']):'' ?></span>
            <div class="spacer"></div>
            <span class="muted"><?= e($j['msg']??'') ?></span>
          </div>
          <?php if ($tail): ?><pre class="joblog"><?= e($tail) ?></pre><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="row" style="margin-bottom:14px;gap:10px">
      <input type="search" id="projectSearch" placeholder="Buscar proyecto por nombre o dominio…" style="flex:1;max-width:320px">
      <span class="muted" id="projectSearchCount" style="font-size:12px"></span>
    </div>
    <script>
      (function(){
        var input = document.getElementById('projectSearch');
        var countEl = document.getElementById('projectSearchCount');
        if (!input) return;
        function norm(s){ return (s||'').toLowerCase(); }
        function filter(){
          var q = norm(input.value.trim());
          var totalShown = 0;
          ['secDestacados','secProyectos','secUnreg'].forEach(function(secId){
            var sec = document.getElementById(secId);
            if (!sec) return;
            var shown = 0;
            sec.querySelectorAll('.sitecard').forEach(function(card){
              var name = card.querySelector('.name');
              var url = card.querySelector('.url');
              var text = norm((name?name.textContent:'') + ' ' + (url?url.textContent:''));
              var match = !q || text.indexOf(q) !== -1;
              card.style.display = match ? '' : 'none';
              if (match) { shown++; totalShown++; }
            });
            if (q && shown > 0) { sec.open = true; }
          });
          countEl.textContent = q ? (totalShown + ' resultado(s)') : '';
        }
        input.addEventListener('input', filter);
      })();
    </script>

    <?php $sitesPinned = array_filter($sitesView, function($i){ return is_array($i) && !empty($i['pinned']); }); ?>
    <?php if ($sitesPinned): ?>
    <details class="sectioncollapse" id="secDestacados" open>
      <summary>Destacados <span class="op">(<?= count($sitesPinned) ?>)</span><span class="arrow"></span></summary>
      <div class="pane">
        <div class="sitegrid">
          <?php foreach ($sitesPinned as $name => $info): render_site_card($name, $info); endforeach; ?>
        </div>
      </div>
    </details>
    <?php endif; ?>

    <?php $sitesUnpinned = array_filter($sitesView, function($i){ return !(is_array($i) && !empty($i['pinned'])); }); ?>
    <?php $sitesSinTipo = 0; foreach ($sitesView as $sInfo) { if (is_array($sInfo) && empty($sInfo['type']) && empty($sInfo['typeChecked'])) $sitesSinTipo++; } ?>
    <?php $sitesFaltantes = missing_projects($WWW, $sitesView); ?>
    <details class="sectioncollapse" id="secProyectos" open>
      <summary>Proyectos <span class="op">(<?= count($sitesUnpinned) ?>)</span>
        <?php if ($sitesSinTipo > 0): ?>
        <form method="post" title="Detecta el framework (PHP, JavaScript o Python) de los proyectos ya registrados">
          <input type="hidden" name="action" value="detect_types">
          <!-- Un <button type=submit> dentro de <summary> no envia en Chrome (el summary
               se queda el clic para abrir/cerrar el <details>): forzamos el submit por JS. -->
          <button type="button" class="btn ghost sm" onclick="event.stopPropagation();event.preventDefault();this.closest('form').requestSubmit()">Detectar tipos (<?= $sitesSinTipo ?>)</button>
        </form>
        <?php endif; ?>
        <?php if ($sitesFaltantes): ?>
        <button type="button" class="btn ghost sm" title="Quita de la lista los proyectos cuya carpeta ya no existe en www\ (borrada fuera del panel)" data-names="<?= e(implode(', ', $sitesFaltantes)) ?>" onclick="event.stopPropagation();luaAskSyncProjects(this)">Sincronizar proyectos (<?= count($sitesFaltantes) ?>)</button>
        <?php endif; ?>
        <div class="viewtoggle" onclick="event.stopPropagation()">
          <button type="button" class="viewbtn" data-view="grid" title="Vista de cuadrícula" aria-label="Vista de cuadrícula">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          </button>
          <button type="button" class="viewbtn" data-view="list" title="Vista de lista" aria-label="Vista de lista">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
          </button>
        </div>
        <span class="arrow"></span>
      </summary>
      <div class="pane">
    <?php if (!$sitesUnpinned): ?>
      <div class="card muted"><?= $sitesPinned ? 'El resto de proyectos está destacado arriba.' : 'Aún no hay proyectos. Crea el primero arriba.' ?></div>
    <?php else: ?>
      <div class="sitegrid">
        <?php foreach ($sitesUnpinned as $name => $info): render_site_card($name, $info); endforeach; ?>
      </div>
    <?php endif; ?>
      </div>
    </details>

    <?php if ($unreg): ?>
    <details class="sectioncollapse" id="secUnreg">
      <summary>Sin registrar <span class="op">(<?= count($unreg) ?>) — carpetas detectadas en <code>www\</code> pendientes de integrar</span>
        <form method="post" onclick="event.stopPropagation()" onsubmit="return confirm('Integrar las <?= count($unreg) ?> carpetas sin registrar (PHP <?= e($defaultPhp) ?> por defecto; se usa la versión de composer.json cuando se pueda detectar)?')">
          <input type="hidden" name="action" value="integrate_all">
          <input type="hidden" name="php" value="<?= e($defaultPhp) ?>">
          <button class="btn ghost sm" type="submit">Integrar todo</button>
        </form>
        <span class="arrow"></span>
      </summary>
      <div class="pane">
      <div class="sitegrid">
        <?php foreach ($unreg as $name):
              $dPhp = detect_project_php("$WWW/$name", $vers);
              $selPhp = $dPhp ?: $defaultPhp; ?>
          <div class="sitecard unregistered">
            <div class="cardactions">
              <button type="button" class="trashbtn" title="Eliminar carpeta" aria-label="Eliminar carpeta" onclick="luaAskDeleteUnreg('<?= e($name) ?>')">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
              </button>
            </div>
            <div class="namerow">
              <div class="name" title="<?= e($name) ?>"><?= e($name) ?></div>
              <form method="post" class="phpselform">
                <input type="hidden" name="action" value="integrate">
                <input type="hidden" name="name" value="<?= e($name) ?>">
                <select name="php" class="phpsel" title="<?= $dPhp?'Detectada de composer.json':'Versión por defecto (sin pista en el proyecto)' ?>">
                  <?php foreach ($vers as $v): ?>
                    <option value="<?= e($v) ?>" <?= $v===$selPhp?'selected':'' ?>>PHP <?= e($v) ?><?= ($v===$dPhp)?' (detectada)':'' ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn sm" type="submit" title="Integrar como proyecto">Integrar</button>
              </form>
            </div>
            <div class="url muted" style="font-family:ui-monospace,Consolas,monospace">www\<?= e($name) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      </div>
    </details>
    <?php endif; ?>

    <script>
      (function(){
        document.querySelectorAll('details.sectioncollapse').forEach(function(d){
          var key = 'lua_sec_' + d.id;
          var saved = localStorage.getItem(key);
          if (saved !== null) { d.open = (saved === '1'); }
          d.addEventListener('toggle', function(){ localStorage.setItem(key, d.open ? '1' : '0'); });
        });
      })();
    </script>
    <script>
      (function(){
        var KEY='lua_sites_view';
        function apply(v){
          document.querySelectorAll('.sitegrid').forEach(function(g){ g.classList.toggle('list', v==='list'); });
          document.querySelectorAll('.viewbtn').forEach(function(b){ b.classList.toggle('on', b.dataset.view===v); });
        }
        apply(localStorage.getItem(KEY) || 'grid');
        document.querySelectorAll('.viewbtn').forEach(function(b){
          b.addEventListener('click', function(){ localStorage.setItem(KEY, b.dataset.view); apply(b.dataset.view); });
        });
      })();
    </script>

    <!-- Modal de confirmacion de borrado -->
    <div id="delModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDelete()">
      <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="delTitle">
        <div class="modal-ic">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
        </div>
        <h3 id="delTitle">¿Eliminar proyecto?</h3>
        <p class="modal-tx">Se quitará <strong id="delName"></strong> del panel y se recargará Apache. <span id="delConsequence"></span></p>
        <form method="post" class="modal-actions">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="name" id="delNameInput">
          <button type="button" class="btn ghost" onclick="luaCloseDelete()">Cancelar</button>
          <button type="submit" class="btn danger">Sí, eliminar</button>
        </form>
      </div>
    </div>
    <script>
      // isExternal/hasDb/dbName vienen de datos ya validados por valid_name()/valid_dbname()
      // (solo letras/numeros/_/-), nunca texto libre -- por eso es seguro construir este HTML
      // por concatenacion simple, sin pasar por textContent.
      function luaAskDelete(name, isExternal, hasDb, dbName){
        document.getElementById('delName').textContent = name;
        var cons = document.getElementById('delConsequence');
        if (isExternal) {
          cons.innerHTML = 'Es una carpeta externa (fuera de <code>www\\</code>): <strong>no se toca en disco</strong>.'
            + (hasDb ? ' Se eliminará la base de datos "'+dbName+'".' : '');
        } else {
          cons.innerHTML = 'Se borrará también <strong>la carpeta del proyecto</strong> (<code>www\\'+name+'</code>) y todo su contenido, de forma <strong>permanente</strong>.'
            + (hasDb ? ' También se eliminará la base de datos "'+dbName+'" y su usuario de MySQL.' : '');
        }
        document.getElementById('delNameInput').value = name;
        document.getElementById('delModal').hidden = false;
        document.addEventListener('keydown', luaEscDelete);
      }
      function luaCloseDelete(){
        document.getElementById('delModal').hidden = true;
        document.removeEventListener('keydown', luaEscDelete);
      }
      function luaEscDelete(e){ if(e.key==='Escape') luaCloseDelete(); }
    </script>

    <!-- Modal de confirmacion de borrado de una carpeta SIN registrar (borrado real, sin red de seguridad) -->
    <div id="delUnregModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDeleteUnreg()">
      <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="delUnregTitle">
        <div class="modal-ic">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
        </div>
        <h3 id="delUnregTitle">¿Eliminar carpeta?</h3>
        <p class="modal-tx">Se borrará <code>www\<strong id="delUnregName"></strong></code> y <strong>todo su contenido</strong> del disco, de forma permanente. Como no está registrada como proyecto, esto no se puede deshacer.</p>
        <form method="post" class="modal-actions">
          <input type="hidden" name="action" value="delete_unregistered">
          <input type="hidden" name="name" id="delUnregNameInput">
          <button type="button" class="btn ghost" onclick="luaCloseDeleteUnreg()">Cancelar</button>
          <button type="submit" class="btn danger">Sí, eliminar</button>
        </form>
      </div>
    </div>
    <script>
      function luaAskDeleteUnreg(name){
        document.getElementById('delUnregName').textContent = name;
        document.getElementById('delUnregNameInput').value = name;
        document.getElementById('delUnregModal').hidden = false;
        document.addEventListener('keydown', luaEscDeleteUnreg);
      }
      function luaCloseDeleteUnreg(){
        document.getElementById('delUnregModal').hidden = true;
        document.removeEventListener('keydown', luaEscDeleteUnreg);
      }
      function luaEscDeleteUnreg(e){ if(e.key==='Escape') luaCloseDeleteUnreg(); }
    </script>

    <!-- Modal de confirmacion de "Sincronizar proyectos": no toca disco, solo quita
         entradas de sites.json cuya carpeta ya no existe -- por eso icono/boton "info",
         no "danger" (mismo criterio que el modal de reinicio). -->
    <div id="syncProjModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseSyncProjects()">
      <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="syncProjTitle">
        <div class="modal-ic modal-ic-info">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/></svg>
        </div>
        <h3 id="syncProjTitle">¿Sincronizar proyectos?</h3>
        <p class="modal-tx">Se quitará de la lista <strong id="syncProjNames"></strong> por no tener ya carpeta en <code>www\</code>. La carpeta ya no está, así que no hay nada que borrar en disco.</p>
        <form method="post" class="modal-actions">
          <input type="hidden" name="action" value="sync_projects">
          <button type="button" class="btn ghost" onclick="luaCloseSyncProjects()">Cancelar</button>
          <button type="submit" class="btn" data-loading-text="Sincronizando…">Sí, sincronizar</button>
        </form>
      </div>
    </div>
    <script>
      function luaAskSyncProjects(btn){
        document.getElementById('syncProjNames').textContent = btn.dataset.names || '';
        document.getElementById('syncProjModal').hidden = false;
        document.addEventListener('keydown', luaEscSyncProjects);
      }
      function luaCloseSyncProjects(){
        document.getElementById('syncProjModal').hidden = true;
        document.removeEventListener('keydown', luaEscSyncProjects);
      }
      function luaEscSyncProjects(e){ if(e.key==='Escape') luaCloseSyncProjects(); }
    </script>


<?php endif; ?>
