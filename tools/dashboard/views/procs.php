  <?php if ($tab==='procs'): /* ---------- PESTAÑA PROCESOS (supervisor) ---------- */
      $procs = procs_load($ROOT);
      $procState = procs_state($ROOT);
      $procEdit = null;
      if (valid_proc_id((string)($_GET['edit'] ?? ''))) {
          foreach ($procs as $p) { if (($p['id'] ?? '') === $_GET['edit']) { $procEdit = $p; break; } }
      }
      $procForm = $procEdit !== null || isset($_GET['nuevo']) || !$procs;
      $procTermOn = term_enabled($ROOT);
      // Agrupar por proyecto para el listado
      $procsByProj = [];
      foreach ($procs as $p) { $procsByProj[(string)($p['project'] ?? '?')][] = $p; }
      ksort($procsByProj);
  ?>

    <div class="card row" style="flex-wrap:wrap;gap:8px">
      <div style="min-width:260px">
        <div style="font-weight:600">Procesos supervisados</div>
        <div class="muted" style="margin-top:4px">Comandos largos de tus proyectos (colas, scheduler, Vite…) que el watcher mantiene corriendo y reinicia si se caen. Con log propio.</div>
      </div>
      <div class="spacer"></div>
      <?php if (!$procForm): ?><a class="btn ghost sm" href="?tab=procs&nuevo=1">+ Añadir proceso</a><?php endif; ?>
    </div>

    <?php if (!$watcherAlive): ?>
      <div class="card"><div class="msgtext err" style="margin:0">El watcher no está activo: nadie arranca ni vigila los procesos. Arráncalo con <code>.\lua.ps1 start</code>.</div></div>
    <?php endif; ?>
    <?php if (!$procTermOn): ?>
      <div class="card"><div class="msgtext warn" style="margin:0">El supervisor ejecuta comandos, así que usa la misma llave de seguridad que la Terminal: actívala en <a href="?tab=config">Configuración del servidor</a> para poder crear, arrancar o parar procesos. (Ver estado y logs sí está permitido.)</div></div>
    <?php endif; ?>

    <?php if ($procForm): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:12px"><?= $procEdit ? 'Editar proceso' : 'Nuevo proceso' ?></div>
        <form method="post" class="inline">
          <input type="hidden" name="action" value="proc_save">
          <?php if ($procEdit): ?><input type="hidden" name="id" value="<?= e($procEdit['id']) ?>"><?php endif; ?>
          <div>
            <label>Proyecto</label>
            <select name="project" required>
              <?php foreach (array_keys($sitesView) as $sn): ?>
                <option value="<?= e($sn) ?>" <?= ($procEdit['project'] ?? '')===$sn?'selected':'' ?>><?= e($sn) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div><label>Nombre</label><input type="text" name="label" placeholder="Cola" maxlength="40" value="<?= e($procEdit['label'] ?? '') ?>"></div>
          <div style="flex:1;min-width:280px">
            <label>Comando <span class="muted">(corre en la raíz del proyecto)</span></label>
            <input type="text" name="cmd" list="procPresets" placeholder="php artisan queue:work" required maxlength="300" style="width:100%" value="<?= e($procEdit['cmd'] ?? '') ?>">
            <datalist id="procPresets">
              <option value="php artisan queue:work --tries=3"></option>
              <option value="php artisan schedule:work"></option>
              <option value="php artisan horizon"></option>
              <option value="php artisan reverb:start"></option>
              <option value="npm run dev"></option>
              <option value="npm run watch"></option>
            </datalist>
          </div>
          <div style="max-width:130px">
            <label>PHP</label>
            <select name="php">
              <option value="">(el del PATH)</option>
              <?php foreach ($vers as $v): ?><option value="<?= e($v) ?>" <?= ($procEdit['php'] ?? '')===$v?'selected':'' ?>>PHP <?= e($v) ?></option><?php endforeach; ?>
            </select>
          </div>
          <button class="btn" type="submit" <?= $procTermOn?'':'disabled' ?>><?= $procEdit ? 'Guardar cambios' : 'Guardar proceso' ?></button>
          <?php if ($procs): ?><a class="btn ghost" href="?tab=procs">Cancelar</a><?php endif; ?>
        </form>
        <div class="muted" style="margin-top:10px;font-size:11.5px">
          El comando hereda el PHP elegido al frente del <code>PATH</code> (con su <code>php.ini</code>), igual que el runner. Los procesos nuevos se crean <b>parados</b>.
          Se detienen todos con <code>lua.ps1 stop</code> y vuelven solos con <code>start</code> si estaban activados.
        </div>
      </div>
    <?php endif; ?>

    <?php foreach ($procsByProj as $pj => $plist): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:10px"><?= e($pj) ?></div>
        <?php foreach ($plist as $p): $pid = (string)$p['id']; $ps = $procState[$pid] ?? []; $run = !empty($ps['running']); ?>
          <div class="row" style="gap:10px;flex-wrap:wrap;align-items:center;padding:8px 0;border-top:1px solid var(--line)" data-procrow="<?= e($pid) ?>">
            <span class="jstate <?= $run?'run':(!empty($p['enabled'])?'err':'') ?>" data-badge><?= $run ? 'CORRIENDO' : (!empty($p['enabled']) ? 'CAÍDO' : 'PARADO') ?></span>
            <div style="min-width:140px;font-weight:600;font-size:13px"><?= e($p['label']) ?></div>
            <code style="font-size:11.5px;opacity:.85;flex:1;min-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($p['cmd']) ?></code>
            <?php if (($p['php'] ?? '') !== ''): ?><span class="muted" style="font-size:11px">PHP <?= e($p['php']) ?></span><?php endif; ?>
            <span class="muted" style="font-size:11px" data-meta></span>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="proc_toggle">
              <input type="hidden" name="id" value="<?= e($pid) ?>">
              <input type="hidden" name="enable" value="<?= !empty($p['enabled'])?'0':'1' ?>">
              <button class="btn <?= !empty($p['enabled'])?'danger':'' ?> sm" type="submit" <?= $procTermOn?'':'disabled' ?> data-togglebtn><?= !empty($p['enabled'])?'Parar':'Arrancar' ?></button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="proc_restart">
              <input type="hidden" name="id" value="<?= e($pid) ?>">
              <button class="btn ghost sm" type="submit" <?= ($procTermOn && !empty($p['enabled']))?'':'disabled' ?>>Reiniciar</button>
            </form>
            <button type="button" class="btn ghost sm" data-logbtn="<?= e($pid) ?>">Log</button>
            <a class="btn ghost sm" href="?tab=procs&edit=<?= e($pid) ?>">Editar</a>
            <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar la definición de este proceso? Si está corriendo, se detendrá.')">
              <input type="hidden" name="action" value="proc_del">
              <input type="hidden" name="id" value="<?= e($pid) ?>">
              <button class="btn danger sm" type="submit" <?= $procTermOn?'':'disabled' ?>>Eliminar</button>
            </form>
          </div>
          <pre class="logview" data-logpane="<?= e($pid) ?>" hidden style="margin:0 0 8px;max-height:40vh"></pre>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <script>
    (function(){
      // ---- badges en vivo: se repintan con ?ajax=procs&op=state sin recargar la pagina ----
      function tick() {
        fetch('?ajax=procs&op=state').then(function(r){ return r.json(); }).then(function(j){
          if (!j.ok) return;
          j.procs.forEach(function(p){
            var row = document.querySelector('[data-procrow="'+p.id+'"]'); if (!row) return;
            var b = row.querySelector('[data-badge]'), m = row.querySelector('[data-meta]');
            if (p.running) {
              b.textContent = 'CORRIENDO'; b.className = 'jstate run';
              m.textContent = 'PID ' + p.pid + (p.since ? ' · desde ' + new Date(p.since*1000).toLocaleTimeString() : '');
            } else if (p.enabled) {
              b.textContent = p.fails > 0 ? 'REINTENTANDO' : 'ARRANCANDO…';
              b.className = 'jstate err';
              var wait = p.next > j.now ? (p.next - j.now) : 0;
              m.textContent = p.fails > 0 ? (p.fails + ' caída(s) seguidas' + (wait ? ' · reintento en ' + wait + 's' : '')) : '';
            } else {
              b.textContent = 'PARADO'; b.className = 'jstate';
              m.textContent = '';
            }
          });
        }).catch(function(){});
      }
      setInterval(tick, 3000); tick();

      // ---- visor de log: uno abierto a la vez, con auto-refresco mientras este visible ----
      var openLog = null, logTimer = null;
      function refreshLog() {
        if (!openLog) return;
        fetch('?ajax=procs&op=log&id=' + openLog).then(function(r){ return r.json(); }).then(function(j){
          var pane = document.querySelector('[data-logpane="'+openLog+'"]'); if (!pane) return;
          pane.innerHTML = j.exists ? (j.html || '(vacío)') : '(sin log todavía: el proceso aún no ha arrancado nunca)';
          pane.scrollTop = pane.scrollHeight;
        }).catch(function(){});
      }
      document.querySelectorAll('[data-logbtn]').forEach(function(btn){
        btn.addEventListener('click', function(){
          var id = btn.dataset.logbtn;
          var pane = document.querySelector('[data-logpane="'+id+'"]');
          if (openLog === id) {           // segundo clic: cerrar
            pane.hidden = true; openLog = null;
            if (logTimer) { clearInterval(logTimer); logTimer = null; }
            return;
          }
          document.querySelectorAll('[data-logpane]').forEach(function(x){ x.hidden = true; });
          openLog = id; pane.hidden = false;
          pane.innerHTML = 'cargando…';
          refreshLog();
          if (logTimer) clearInterval(logTimer);
          logTimer = setInterval(refreshLog, 2000);
        });
      });
    })();
    </script>


<?php endif; ?>
