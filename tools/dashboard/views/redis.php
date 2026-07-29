  <?php if ($tab==='redis'): /* ---------- PESTAÑA REDIS ---------- */
      // Mismo esquema que SQL Server: lista de conexiones guardadas arriba, y debajo el
      // explorador (barra lateral de bases + claves + valor). Todo el trabajo real lo hace
      // ?ajax=redis; aqui solo se pinta el armazon y se le pasa la conexion elegida al JS.
      $rdServers = redis_servers($ROOT);
      $rdSel  = (string)($_GET['conn'] ?? '');
      $rdSrv  = valid_redis_id($rdSel) ? redis_find($ROOT, $rdSel) : null;
      if (!$rdSrv && $rdServers) { $rdSrv = $rdServers[0]; }
      $rdEditSrv = valid_redis_id((string)($_GET['edit'] ?? '')) ? redis_find($ROOT, $_GET['edit']) : null;
      $rdForm = $rdEditSrv !== null || isset($_GET['nueva']) || !$rdServers; ?>

    <div class="card row" style="flex-wrap:wrap;gap:8px">
      <div style="min-width:220px">
        <div style="font-weight:600">Servidores Redis</div>
        <div class="muted" style="margin-top:4px">Conecta con un Redis existente (un contenedor de Docker, uno nativo o uno de red). No hace falta la extensión <code>php_redis</code>: el panel habla el protocolo directamente.</div>
      </div>
      <div class="spacer"></div>
      <?php foreach ($rdServers as $s): ?>
        <a class="btn <?= ($rdSrv && $s['id']===$rdSrv['id'] && !$rdForm) ? '' : 'ghost' ?> sm"
           href="?tab=redis&conn=<?= e(rawurlencode($s['id'])) ?>"><?= e($s['label']) ?></a>
      <?php endforeach; ?>
      <a class="btn ghost sm" href="?tab=redis&nueva=1">+ Añadir conexión</a>
    </div>

    <?php if ($rdForm): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:12px"><?= $rdEditSrv ? 'Editar conexión' : 'Nueva conexión' ?></div>
        <form method="post" class="inline">
          <input type="hidden" name="action" value="redis_save">
          <?php if ($rdEditSrv): ?><input type="hidden" name="id" value="<?= e($rdEditSrv['id']) ?>"><?php endif; ?>
          <div><label>Nombre</label><input type="text" name="label" placeholder="Docker local" value="<?= e($rdEditSrv['label'] ?? '') ?>"></div>
          <div><label>Host o IP</label><input type="text" name="host" placeholder="127.0.0.1" value="<?= e($rdEditSrv['host'] ?? '127.0.0.1') ?>" required></div>
          <div style="max-width:110px"><label>Puerto</label><input type="text" name="port" value="<?= e((string)($rdEditSrv['port'] ?? 6379)) ?>" required></div>
          <div><label>Usuario <span class="muted">(ACL, Redis 6+)</span></label><input type="text" name="user" placeholder="opcional" value="<?= e($rdEditSrv['user'] ?? '') ?>"></div>
          <div><label>Contraseña</label>
            <input type="password" name="pass" autocomplete="new-password" placeholder="<?= $rdEditSrv ? 'dejar vacío para no cambiarla' : 'si no tiene, déjalo vacío' ?>">
          </div>
          <button class="btn" type="submit"><?= $rdEditSrv ? 'Guardar cambios' : 'Guardar conexión' ?></button>
          <?php if ($rdEditSrv): ?><a class="btn ghost" href="?tab=redis&conn=<?= e(rawurlencode($rdEditSrv['id'])) ?>">Cancelar</a><?php endif; ?>
          <?php if (!$rdEditSrv && $rdServers): ?><a class="btn ghost" href="?tab=redis">Cancelar</a><?php endif; ?>
        </form>
        <div class="muted" style="margin-top:10px;font-size:11.5px">La contraseña se guarda en claro en <code>config\redis-servers.json</code> (fuera de git), igual que las de MySQL y SQL Server.</div>
      </div>
    <?php endif; ?>

    <?php if ($rdSrv && !$rdForm): ?>
      <div class="sqlx" id="rdApp" data-conn="<?= e($rdSrv['id']) ?>">
        <div class="sqlx-side">
          <div class="card">
            <div class="row" style="gap:6px;align-items:center">
              <div style="font-weight:600;font-size:13px"><?= e($rdSrv['label']) ?></div>
              <div class="spacer"></div>
              <a class="btn ghost sm" href="?tab=redis&edit=<?= e(rawurlencode($rdSrv['id'])) ?>">Editar</a>
            </div>
            <div class="muted" style="margin-top:3px;font-size:11.5px">
              <code><?= e($rdSrv['host'].':'.$rdSrv['port']) ?></code> · <span id="rdVer">…</span>
            </div>
            <div class="row" style="margin-top:12px;align-items:center">
              <span style="font-weight:600;font-size:12px">Bases</span>
              <div class="spacer"></div>
              <button type="button" class="btn ghost sm" id="rdReload" title="Recargar bases y claves">Refrescar</button>
            </div>
            <div class="rdb" id="rdDbs"><div class="sqlx-empty">cargando…</div></div>
          </div>
        </div>

        <div class="sqlx-main">
          <div class="card">
            <div class="sqlx-views">
              <button type="button" data-view="keys" class="on">Claves</button>
              <button type="button" data-view="cons">Consola</button>
              <button type="button" data-view="info">Servidor</button>
            </div>

            <!-- ---- vista Claves ---- -->
            <div id="rdViewKeys">
              <div class="row" style="gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px">
                <div style="flex:1;min-width:200px">
                  <label>Patrón <span class="muted">(comodín <code>*</code>)</span></label>
                  <input type="text" id="rdMatch" placeholder="*" style="width:100%">
                </div>
                <div style="max-width:110px">
                  <label>Por página</label>
                  <select id="rdCount">
                    <option value="50">50</option>
                    <option value="100" selected>100</option>
                    <option value="500">500</option>
                  </select>
                </div>
                <button type="button" class="btn sm" id="rdSearch">Buscar</button>
                <div class="spacer"></div>
                <button type="button" class="btn danger sm" id="rdFlush">Vaciar base</button>
              </div>
              <div class="rkeys" id="rdKeys"><div class="sqlx-empty">elige una base</div></div>
              <div class="row" style="margin-top:8px;align-items:center">
                <span class="muted" id="rdKeysMeta" style="font-size:11.5px"></span>
                <div class="spacer"></div>
                <button type="button" class="btn ghost sm" id="rdMore" hidden>Cargar más</button>
              </div>

              <!-- detalle de la clave seleccionada -->
              <div id="rdDetail" hidden style="margin-top:16px;border-top:1px solid var(--line);padding-top:14px">
                <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
                  <span class="rtype" id="rdDType">—</span>
                  <code id="rdDKey" style="font-size:12.5px;word-break:break-all"></code>
                  <div class="spacer"></div>
                  <span class="muted" id="rdDMeta" style="font-size:11.5px"></span>
                </div>
                <div class="row" style="gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:10px">
                  <div style="max-width:150px">
                    <label>TTL (segundos)</label>
                    <input type="text" id="rdTtl" placeholder="sin expiración">
                  </div>
                  <button type="button" class="btn ghost sm" id="rdTtlSave">Aplicar TTL</button>
                  <div style="flex:1;min-width:180px">
                    <label>Renombrar a</label>
                    <input type="text" id="rdRename" placeholder="nuevo:nombre" style="width:100%">
                  </div>
                  <button type="button" class="btn ghost sm" id="rdRenameSave">Renombrar</button>
                  <div class="spacer"></div>
                  <button type="button" class="btn danger sm" id="rdDel">Eliminar clave</button>
                </div>
                <div id="rdEditor" style="margin-top:12px"></div>
                <div class="msgtext" id="rdDMsg" style="margin-top:8px"></div>
              </div>
            </div>

            <!-- ---- vista Consola ---- -->
            <div id="rdViewCons" hidden>
              <div class="muted" style="font-size:12px;margin-bottom:8px">
                Se ejecuta contra la base seleccionada. Los argumentos con espacios van entre comillas, como en <code>redis-cli</code>.
                <code>SHUTDOWN</code> y los comandos que dejan la conexión escuchando (<code>SUBSCRIBE</code>, <code>MONITOR</code>…) están bloqueados.
              </div>
              <div class="rcons" id="rdConsOut"><span class="muted">Escribe un comando y pulsa Enter. Flechas ↑/↓ para el historial.</span></div>
              <div class="row" style="gap:8px;margin-top:8px">
                <input type="text" id="rdConsIn" placeholder="GET mi:clave" autocomplete="off" spellcheck="false"
                       style="flex:1;font-family:ui-monospace,Consolas,monospace">
                <button type="button" class="btn sm" id="rdConsRun">Ejecutar</button>
                <button type="button" class="btn ghost sm" id="rdConsClear">Limpiar</button>
              </div>
            </div>

            <!-- ---- vista Servidor ---- -->
            <div id="rdViewInfo" hidden>
              <div class="rinfo" id="rdInfoBoxes"><div class="sqlx-empty">cargando…</div></div>
              <details style="margin-top:14px">
                <summary style="cursor:pointer;font-size:12.5px;color:var(--mut)">INFO completo</summary>
                <pre class="rcons" style="margin-top:8px;max-height:44vh" id="rdInfoRaw"></pre>
              </details>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal: eliminar clave -->
      <div id="rdDelModal" class="modal-overlay" hidden onclick="if(event.target===this)rdCloseDel()">
        <div class="modal-box" role="dialog" aria-modal="true">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
              <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
          </div>
          <h3>¿Eliminar la clave?</h3>
          <p class="modal-tx">Se borrará <strong id="rdDelName"></strong> del servidor. Esto no se puede deshacer.</p>
          <div class="modal-actions">
            <button type="button" class="btn ghost" onclick="rdCloseDel()">Cancelar</button>
            <button type="button" class="btn danger" id="rdDelYes">Sí, eliminar</button>
          </div>
        </div>
      </div>

      <!-- Modal: vaciar base -->
      <div id="rdFlushModal" class="modal-overlay" hidden onclick="if(event.target===this)rdCloseFlush()">
        <div class="modal-box" role="dialog" aria-modal="true">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
              <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
          </div>
          <h3>¿Vaciar la base <span id="rdFlushDb"></span>?</h3>
          <p class="modal-tx">Se borrarán <strong id="rdFlushN"></strong> del servidor <strong><?= e($rdSrv['label']) ?></strong>.
             Si alguna aplicación usa esta base como caché o para sesiones, lo notará. No se puede deshacer.</p>
          <div class="modal-actions">
            <button type="button" class="btn ghost" onclick="rdCloseFlush()">Cancelar</button>
            <button type="button" class="btn danger" id="rdFlushYes">Sí, vaciar</button>
          </div>
        </div>
      </div>

      <div class="card" style="margin-top:14px">
        <div class="row" style="align-items:center">
          <div>
            <div style="font-weight:600;font-size:13px">Eliminar esta conexión</div>
            <div class="muted" style="font-size:11.5px;margin-top:3px">Solo borra los datos de acceso guardados aquí; no toca el servidor de Redis.</div>
          </div>
          <div class="spacer"></div>
          <form method="post" onsubmit="return confirm('¿Eliminar la conexión guardada?')">
            <input type="hidden" name="action" value="redis_del">
            <input type="hidden" name="id" value="<?= e($rdSrv['id']) ?>">
            <button class="btn danger sm" type="submit">Eliminar conexión</button>
          </form>
        </div>
      </div>

    <script>
    (function(){
      var CONN = document.getElementById('rdApp').dataset.conn;
      var db = 0, cursor = '0', curKey = null, curType = null, hist = [], histIx = -1;
      var $ = function(id){ return document.getElementById(id); };
      function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

      // Todas las llamadas van por POST: una clave puede ser larga o traer caracteres raros y
      // en el query string acabaria recortada o mal codificada por el camino.
      function api(op, extra) {
        var b = new URLSearchParams();
        b.set('op', op); b.set('db', db);
        if (extra) { for (var k in extra) {
          if (Array.isArray(extra[k])) { extra[k].forEach(function(v){ b.append(k+'[]', v); }); }
          else { b.set(k, extra[k]); }
        } }
        return fetch('?ajax=redis&conn=' + encodeURIComponent(CONN), {
          method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=utf-8'}, body:b.toString()
        }).then(function(r){ return r.json(); });
      }
      function dmsg(txt, ok) {
        var el = $('rdDMsg');
        el.className = 'msgtext ' + (ok ? 'ok' : 'err');
        el.textContent = txt || '';
        if (txt && ok) setTimeout(function(){ if (el.textContent===txt) el.textContent=''; }, 3000);
      }
      function ttlTxt(t){ return t < 0 ? 'sin expiración' : t + 's'; }

      // ---- pestañas internas ----
      document.querySelectorAll('.sqlx-views button').forEach(function(b){
        b.addEventListener('click', function(){
          var v = b.dataset.view;
          document.querySelectorAll('.sqlx-views button').forEach(function(x){ x.classList.toggle('on', x===b); });
          $('rdViewKeys').hidden = v!=='keys';
          $('rdViewCons').hidden = v!=='cons';
          $('rdViewInfo').hidden = v!=='info';
          if (v==='info') loadInfo();
        });
      });

      // ---- bases ----
      function loadDbs() {
        api('dbs').then(function(j){
          if (j.error) { $('rdDbs').innerHTML = '<div class="sqlx-empty">'+esc(j.error)+'</div>'; return; }
          $('rdDbs').innerHTML = j.dbs.map(function(d){
            return '<button type="button" data-db="'+d.db+'" class="'+(d.db===db?'on':'')+(d.keys===0?' vacia':'')+'">'
                 + 'db'+d.db+'<span class="n">'+d.keys+'</span></button>';
          }).join('');
          $('rdDbs').querySelectorAll('button').forEach(function(b){
            b.addEventListener('click', function(){
              db = parseInt(b.dataset.db,10);
              $('rdDbs').querySelectorAll('button').forEach(function(x){ x.classList.toggle('on', x===b); });
              hideDetail(); search();
            });
          });
        });
      }
      api('test').then(function(j){ $('rdVer').textContent = j.error ? 'sin conexión' : ('Redis '+j.version+' · '+j.mode); });

      // ---- claves ----
      function renderKeys(keys, append) {
        var html = keys.map(function(k){
          return '<div class="rkey" data-key="'+esc(k.key)+'" data-type="'+esc(k.type)+'">'
               + '<span class="rtype '+esc(k.type)+'">'+esc(k.type)+'</span>'
               + '<span class="kn">'+esc(k.key)+'</span>'
               + '<span class="kt">'+(k.ttl<0?'—':k.ttl+'s')+'</span></div>';
        }).join('');
        if (append) { $('rdKeys').insertAdjacentHTML('beforeend', html); }
        else { $('rdKeys').innerHTML = html || '<div class="sqlx-empty">Sin claves que coincidan.</div>'; }
        $('rdKeys').querySelectorAll('.rkey:not([data-bound])').forEach(function(el){
          el.setAttribute('data-bound','1');
          el.addEventListener('click', function(){
            $('rdKeys').querySelectorAll('.rkey').forEach(function(x){ x.classList.remove('on'); });
            el.classList.add('on');
            openKey(el.dataset.key);
          });
        });
      }
      function search(append) {
        if (!append) { cursor = '0'; }
        api('scan', { cursor: cursor, match: $('rdMatch').value.trim(), count: $('rdCount').value })
          .then(function(j){
            if (j.error) { $('rdKeys').innerHTML = '<div class="sqlx-empty">'+esc(j.error)+'</div>'; return; }
            cursor = j.cursor;
            renderKeys(j.keys, append);
            $('rdMore').hidden = j.done;
            var n = $('rdKeys').querySelectorAll('.rkey').length;
            // SCAN no sabe cuantas claves hay en total hasta terminar: se dice lo mostrado, no
            // un total inventado. Ademas SCAN puede devolver lotes vacios y aun no haber acabado.
            $('rdKeysMeta').textContent = n + ' clave(s) mostradas' + (j.done ? ' · recorrido completo' : ' · quedan más');
          });
      }
      $('rdSearch').addEventListener('click', function(){ search(false); });
      $('rdMatch').addEventListener('keydown', function(e){ if (e.key==='Enter') search(false); });
      $('rdCount').addEventListener('change', function(){ search(false); });
      $('rdMore').addEventListener('click', function(){ search(true); });
      $('rdReload').addEventListener('click', function(){ loadDbs(); search(false); hideDetail(); });

      // ---- detalle / editor ----
      function hideDetail(){ $('rdDetail').hidden = true; curKey = null; curType = null; }
      function openKey(key) {
        api('key', { key: key }).then(function(j){
          if (j.error) { dmsg(j.error, false); $('rdDetail').hidden = false; $('rdEditor').innerHTML=''; return; }
          curKey = j.key; curType = j.type;
          $('rdDetail').hidden = false;
          $('rdDType').textContent = j.type;
          $('rdDType').className = 'rtype ' + j.type;
          $('rdDKey').textContent = j.key;
          $('rdTtl').value = j.ttl < 0 ? '' : j.ttl;
          $('rdRename').value = '';
          dmsg('', true);
          var meta = 'TTL: ' + ttlTxt(j.ttl);
          if (j.type==='string') { meta += ' · ' + j.len + ' bytes'; }
          else if (typeof j.count === 'number') { meta += ' · ' + j.count + ' elemento(s)'; }
          $('rdDMeta').textContent = meta;
          renderEditor(j);
        });
      }
      function renderEditor(j) {
        var h = '';
        if (j.unsupported) {
          h = '<div class="muted" style="font-size:12px">El tipo <code>'+esc(j.type)+'</code> no se puede editar aquí todavía. '
            + 'Puedes consultarlo desde la <b>Consola</b> (por ejemplo <code>XRANGE '+esc(j.key)+' - +</code>).</div>';
        } else if (j.type === 'string') {
          h = '<label>Valor</label><textarea class="rval" id="rdStr"'+(j.truncated?' readonly':'')+'>'+esc(j.value)+'</textarea>';
          if (j.truncated) {
            h += '<div class="msgtext warn" style="margin-top:6px">Valor demasiado grande ('+j.len+' bytes): se muestran los primeros 256 KB '
               + 'y la edición está desactivada para no guardar encima una versión recortada.</div>';
          } else {
            h += '<div style="margin-top:8px"><button type="button" class="btn sm" id="rdStrSave">Guardar valor</button></div>';
          }
        } else {
          // hash/zset traen pares {k,v}; list/set traen valores planos (el "campo" es el indice
          // en las listas y el propio valor en los sets).
          var pares = (j.type==='hash' || j.type==='zset');
          var c1 = j.type==='hash' ? 'Campo' : (j.type==='zset' ? 'Miembro' : (j.type==='list' ? '#' : 'Valor'));
          var c2 = j.type==='zset' ? 'Score' : 'Valor';
          h += '<div class="sqlgrid" style="max-height:40vh"><table class="sqltbl"><thead><tr>'
             + '<th style="width:34%">'+c1+'</th><th>'+(pares||j.type==='list'?c2:'')+'</th><th style="width:120px"></th>'
             + '</tr></thead><tbody>';
          (j.items||[]).forEach(function(it, i){
            var campo, valor;
            if (pares)                 { campo = it.k; valor = it.v; }
            else if (j.type==='list')  { campo = String(i); valor = it; }
            else                       { campo = it; valor = ''; }
            // En un set el miembro ES el valor: se pinta solo en la primera columna, no repetido.
            h += '<tr><td><code>'+esc(campo)+'</code></td>'
               + '<td>'+(j.type==='set' ? '' : '<code>'+esc(valor)+'</code>')+'</td>'
               + '<td><button type="button" class="btn ghost sm rdEd" data-f="'+esc(campo)+'" data-v="'+esc(j.type==='set'?campo:valor)+'">Editar</button> '
               + '<button type="button" class="btn danger sm rdRm" data-f="'+esc(campo)+'">Quitar</button></td></tr>';
          });
          h += '</tbody></table></div>';
          if ((j.count||0) > (j.items||[]).length) {
            h += '<div class="muted" style="margin-top:6px;font-size:11.5px">Mostrando '+(j.items||[]).length+' de '+j.count
               + ' elementos (se limita a 1000 para no bloquear el navegador).</div>';
          }
          h += '<div class="row" style="gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:10px">';
          // El campo extra solo tiene sentido en hash (nombre del campo) y zset (miembro). En una
          // lista se anade con RPUSH al final -- pedir un indice enganaria -- y en un set el
          // miembro es el propio valor.
          if (j.type==='hash' || j.type==='zset') { h += '<div><label>'+c1+'</label><input type="text" id="rdNewF"></div>'; }
          h += '<div style="flex:1;min-width:160px"><label>'+(j.type==='zset'?'Score':'Valor')+'</label><input type="text" id="rdNewV" style="width:100%"></div>'
             + '<button type="button" class="btn sm" id="rdAdd">'+(j.type==='list'?'Añadir al final':'Añadir')+'</button></div>';
        }
        $('rdEditor').innerHTML = h;

        if ($('rdStrSave')) {
          $('rdStrSave').addEventListener('click', function(){
            api('edit', { key: curKey, type: 'string', value: $('rdStr').value })
              .then(function(r){ dmsg(r.error || 'Valor guardado.', !r.error); });
          });
        }
        if ($('rdAdd')) {
          $('rdAdd').addEventListener('click', function(){
            var f = $('rdNewF') ? $('rdNewF').value : '';
            var v = $('rdNewV').value;
            // En un set el "valor" es el propio miembro; en un zset el campo es el miembro y el
            // valor es el score (asi es como los espera el endpoint).
            if (curType==='set') { f = ''; }
            api('additem', { key: curKey, type: curType, field: f, value: v })
              .then(function(r){ if (r.error) { dmsg(r.error, false); } else { openKey(curKey); dmsg('Añadido.', true); } });
          });
        }
        $('rdEditor').querySelectorAll('.rdEd').forEach(function(b){
          b.addEventListener('click', function(){
            var actual = b.dataset.v;
            var etiqueta = curType==='zset' ? 'Nuevo score para "'+b.dataset.f+'"' : 'Nuevo valor para "'+b.dataset.f+'"';
            var nv = window.prompt(etiqueta, actual);
            if (nv === null) return;
            api('edit', { key: curKey, type: curType, field: b.dataset.f, value: nv })
              .then(function(r){ if (r.error) { dmsg(r.error, false); } else { openKey(curKey); dmsg('Guardado.', true); } });
          });
        });
        $('rdEditor').querySelectorAll('.rdRm').forEach(function(b){
          b.addEventListener('click', function(){
            api('delitem', { key: curKey, type: curType, field: b.dataset.f })
              .then(function(r){ if (r.error) { dmsg(r.error, false); } else { openKey(curKey); dmsg('Elemento quitado.', true); } });
          });
        });
      }

      $('rdTtlSave').addEventListener('click', function(){
        var v = $('rdTtl').value.trim();
        // Vacio = sin expiracion. El endpoint traduce <=0 a PERSIST, porque EXPIRE 0 borraria
        // la clave, que no es lo que espera nadie al vaciar este campo.
        var sec = v === '' ? -1 : parseInt(v, 10);
        if (v !== '' && (isNaN(sec) || sec < 1)) { dmsg('El TTL tiene que ser un número de segundos mayor que 0, o vacío para quitarlo.', false); return; }
        api('ttl', { key: curKey, seconds: sec }).then(function(r){
          if (r.error) { dmsg(r.error, false); return; }
          dmsg(sec > 0 ? 'TTL puesto en ' + sec + 's.' : 'Expiración quitada.', true);
          openKey(curKey); search(false);
        });
      });
      $('rdRenameSave').addEventListener('click', function(){
        var to = $('rdRename').value.trim();
        if (to === '') { dmsg('Escribe el nombre nuevo.', false); return; }
        api('rename', { key: curKey, to: to }).then(function(r){
          if (r.error) { dmsg(r.error, false); return; }
          dmsg('Renombrada.', true); search(false); openKey(to);
        });
      });

      // ---- borrar clave (modal propio, no confirm()) ----
      var delKey = null;
      $('rdDel').addEventListener('click', function(){
        delKey = curKey;
        $('rdDelName').textContent = curKey;
        $('rdDelModal').hidden = false;
        document.addEventListener('keydown', escDel);
      });
      function escDel(e){ if (e.key==='Escape') rdCloseDel(); }
      window.rdCloseDel = function(){ $('rdDelModal').hidden = true; document.removeEventListener('keydown', escDel); };
      $('rdDelYes').addEventListener('click', function(){
        rdCloseDel();
        api('del', { keys: [delKey] }).then(function(r){
          if (r.error) { dmsg(r.error, false); return; }
          hideDetail(); loadDbs(); search(false);
        });
      });

      // ---- vaciar base ----
      $('rdFlush').addEventListener('click', function(){
        var b = $('rdDbs').querySelector('button.on');
        var n = b ? b.querySelector('.n').textContent : '?';
        $('rdFlushDb').textContent = 'db' + db;
        $('rdFlushN').textContent = n + ' clave(s)';
        $('rdFlushModal').hidden = false;
        document.addEventListener('keydown', escFlush);
      });
      function escFlush(e){ if (e.key==='Escape') rdCloseFlush(); }
      window.rdCloseFlush = function(){ $('rdFlushModal').hidden = true; document.removeEventListener('keydown', escFlush); };
      $('rdFlushYes').addEventListener('click', function(){
        rdCloseFlush();
        api('flushdb').then(function(){ hideDetail(); loadDbs(); search(false); });
      });

      // ---- consola ----
      function consPrint(html){ var o=$('rdConsOut'); o.insertAdjacentHTML('beforeend', html); o.scrollTop = o.scrollHeight; }
      function fmt(v, depth) {
        depth = depth || 0;
        if (v === null) return '<span class="cnil">(nil)</span>';
        if (typeof v === 'object' && v.__nil) return '<span class="cnil">(nil)</span>';
        if (Array.isArray(v)) {
          if (!v.length) return '<span class="cnil">(lista vacía)</span>';
          return v.map(function(x,i){ return '\n' + '  '.repeat(depth+1) + (i+1) + ') ' + fmt(x, depth+1); }).join('');
        }
        return esc(v);
      }
      function runCmd() {
        var line = $rdIn().value.trim();
        if (!line) return;
        hist.push(line); histIx = hist.length;
        $rdIn().value = '';
        if ($('rdConsOut').querySelector('.muted')) { $('rdConsOut').innerHTML = ''; }
        consPrint('<div><span class="cin">db'+db+'&gt; '+esc(line)+'</span></div>');
        api('cmd', { line: line }).then(function(r){
          if (r.error)     { consPrint('<div class="cerr">'+esc(r.error)+'</div>'); return; }
          if (r.err)       { consPrint('<div class="cerr">(error) '+esc(r.err)+'</div>'); return; }
          consPrint('<div>'+fmt(r.result)+'</div>');
          // Un comando de la consola puede haber creado o borrado claves: refrescar contadores.
          loadDbs();
        });
      }
      function $rdIn(){ return $('rdConsIn'); }
      $('rdConsRun').addEventListener('click', runCmd);
      $('rdConsClear').addEventListener('click', function(){ $('rdConsOut').innerHTML = '<span class="muted">Consola limpia.</span>'; });
      $rdIn().addEventListener('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); runCmd(); }
        else if (e.key === 'ArrowUp')   { e.preventDefault(); if (histIx > 0) { $rdIn().value = hist[--histIx]; } }
        else if (e.key === 'ArrowDown') { e.preventDefault(); if (histIx < hist.length-1) { $rdIn().value = hist[++histIx]; } else { histIx = hist.length; $rdIn().value = ''; } }
      });

      // ---- servidor ----
      function loadInfo() {
        api('info').then(function(j){
          if (j.error) { $('rdInfoBoxes').innerHTML = '<div class="sqlx-empty">'+esc(j.error)+'</div>'; return; }
          var i = j.info;
          var hits = parseInt(i.keyspace_hits||0,10), miss = parseInt(i.keyspace_misses||0,10);
          var ratio = (hits+miss) > 0 ? Math.round(hits*100/(hits+miss)) + '%' : '—';
          var up = parseInt(i.uptime_in_seconds||0,10);
          var upTxt = up >= 86400 ? Math.floor(up/86400)+'d' : (up >= 3600 ? Math.floor(up/3600)+'h' : Math.floor(up/60)+'m');
          var cajas = [
            ['Versión', i.redis_version || '?'],
            ['Modo', i.redis_mode || '?'],
            ['Memoria usada', i.used_memory_human || '?'],
            ['Pico de memoria', i.used_memory_peak_human || '?'],
            ['Clientes', i.connected_clients || '0'],
            ['Uptime', upTxt],
            ['Aciertos de caché', ratio],
            ['Comandos procesados', i.total_commands_processed || '0'],
            ['Claves con TTL vencido', i.expired_keys || '0'],
            ['Claves desalojadas', i.evicted_keys || '0']
          ];
          $('rdInfoBoxes').innerHTML = cajas.map(function(c){
            return '<div class="b"><div class="l">'+esc(c[0])+'</div><div class="v">'+esc(c[1])+'</div></div>';
          }).join('');
          $('rdInfoRaw').textContent = j.raw;
        });
      }

      loadDbs();
      search(false);
    })();
    </script>
    <?php endif; ?>


<?php endif; ?>
