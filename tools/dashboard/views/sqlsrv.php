  <?php if ($tab==='sqlsrv'): /* ---------- PESTAÑA SQL SERVER ---------- */
      $sqlServers = sqlsrv_servers($ROOT);
      $sqlSel  = (string)($_GET['conn'] ?? '');
      $sqlSrv  = valid_sqlsrv_id($sqlSel) ? sqlsrv_find($ROOT, $sqlSel) : null;
      if (!$sqlSrv && $sqlServers) { $sqlSrv = $sqlServers[0]; }
      $sqlEditSrv = valid_sqlsrv_id((string)($_GET['edit'] ?? '')) ? sqlsrv_find($ROOT, $_GET['edit']) : null;
      $sqlForm = $sqlEditSrv !== null || isset($_GET['nueva']) || !$sqlServers;
      $sqlDrv  = sqlsrv_driver_kind() === 'sqlsrv' ? 'pdo_sqlsrv' : 'pdo_odbc · '.sqlsrv_odbc_driver();
      $sqlOk   = extension_loaded('pdo_odbc') || extension_loaded('pdo_sqlsrv'); ?>

    <?php if (!$sqlOk): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:6px">Falta el driver de SQL Server</div>
        <div class="muted">Ni <code>pdo_sqlsrv</code> ni <code>pdo_odbc</code> están cargados en el PHP del panel
          (<?= e(PHP_VERSION) ?>). Añade <code>pdo_odbc</code> en <a href="?tab=php">Versiones PHP</a> y reinicia el servidor.</div>
      </div>
    <?php else: ?>

      <div class="card row" style="flex-wrap:wrap;gap:8px">
        <div style="min-width:220px">
          <div style="font-weight:600">Servidores SQL Server</div>
          <div class="muted" style="margin-top:4px">Conecta con un SQL Server existente (local o de red). Driver: <code><?= e($sqlDrv) ?></code></div>
        </div>
        <div class="spacer"></div>
        <?php foreach ($sqlServers as $s): ?>
          <a class="btn <?= ($sqlSrv && $s['id']===$sqlSrv['id'] && !$sqlForm) ? '' : 'ghost' ?> sm"
             href="?tab=sqlsrv&conn=<?= e(rawurlencode($s['id'])) ?>"><?= e($s['label']) ?></a>
        <?php endforeach; ?>
        <a class="btn ghost sm" href="?tab=sqlsrv&nueva=1">+ Añadir conexión</a>
      </div>

      <?php if ($sqlForm): ?>
        <div class="card">
          <div style="font-weight:600;margin-bottom:12px"><?= $sqlEditSrv ? 'Editar conexión' : 'Nueva conexión' ?></div>
          <form method="post" class="inline">
            <input type="hidden" name="action" value="sqlsrv_save">
            <?php if ($sqlEditSrv): ?><input type="hidden" name="id" value="<?= e($sqlEditSrv['id']) ?>"><?php endif; ?>
            <div><label>Nombre</label><input type="text" name="label" placeholder="Producción" value="<?= e($sqlEditSrv['label'] ?? '') ?>"></div>
            <div><label>Host o IP</label><input type="text" name="host" placeholder="127.0.0.1" value="<?= e($sqlEditSrv['host'] ?? '') ?>" required></div>
            <div style="max-width:110px"><label>Puerto</label><input type="text" name="port" value="<?= e((string)($sqlEditSrv['port'] ?? 1433)) ?>" required></div>
            <div><label>Usuario</label><input type="text" name="user" placeholder="sa" value="<?= e($sqlEditSrv['user'] ?? '') ?>" required></div>
            <div><label>Contraseña</label>
              <input type="password" name="pass" autocomplete="new-password" placeholder="<?= $sqlEditSrv ? 'dejar vacío para no cambiarla' : '' ?>" <?= $sqlEditSrv ? '' : 'required' ?>>
            </div>
            <div><label>Certificado</label>
              <select name="trust">
                <option value="1" <?= (!$sqlEditSrv || !empty($sqlEditSrv['trust'])) ? 'selected' : '' ?>>Confiar sin validar</option>
                <option value="0" <?= ($sqlEditSrv && empty($sqlEditSrv['trust'])) ? 'selected' : '' ?>>Validar el certificado</option>
              </select>
            </div>
            <button class="btn" type="submit">Guardar y probar</button>
            <?php if ($sqlEditSrv): ?><a class="btn ghost" href="?tab=sqlsrv&conn=<?= e(rawurlencode($sqlEditSrv['id'])) ?>">Cancelar</a><?php endif; ?>
          </form>
          <div class="muted" style="margin-top:10px;font-size:12px">
            La contraseña se guarda en claro en <code>config\sqlsrv-servers.json</code> (fuera de git), igual que
            <code>mysql_root.pass</code>. El panel solo escucha en <code>127.0.0.1</code>.
            <?php if (sqlsrv_driver_kind() !== 'sqlsrv'): ?> Con <code>pdo_odbc</code>, "Validar el certificado" requiere que el certificado del servidor sea de confianza para Windows.<?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($sqlSrv && !$sqlForm): ?>
        <div class="card row" style="gap:8px;flex-wrap:wrap">
          <div class="muted" style="font-size:12.5px">
            <b style="color:var(--tx)"><?= e($sqlSrv['label']) ?></b> &middot;
            <code><?= e($sqlSrv['host']) ?>:<?= e((string)$sqlSrv['port']) ?></code> &middot; usuario <code><?= e($sqlSrv['user']) ?></code>
          </div>
          <div class="spacer"></div>
          <form method="post" style="display:inline"><input type="hidden" name="action" value="sqlsrv_test">
            <input type="hidden" name="id" value="<?= e($sqlSrv['id']) ?>">
            <button class="btn ghost sm" type="submit">Probar conexión</button></form>
          <a class="btn ghost sm" href="?tab=sqlsrv&edit=<?= e(rawurlencode($sqlSrv['id'])) ?>">Editar</a>
          <button type="button" class="btn danger sm" onclick="luaAskDelConn('<?= e($sqlSrv['id']) ?>','<?= e(addslashes($sqlSrv['label'])) ?>')">Eliminar</button>
        </div>

        <div class="sqlx" id="sqlx" data-conn="<?= e($sqlSrv['id']) ?>">
          <div class="sqlx-side">
            <div class="card">
              <label style="font-size:12px;color:var(--mut)">Base de datos</label>
              <select id="sqDb" style="width:100%;margin-top:4px"><option value="">cargando…</option></select>
              <input type="search" id="sqFilter" placeholder="Filtrar tablas…" style="width:100%;margin-top:8px;font-size:12.5px">
              <div class="sqlx-tables" id="sqTables"><div class="sqlx-empty">Elige una base de datos.</div></div>
            </div>
          </div>

          <div class="sqlx-main">
            <div class="card">
              <div class="sqlx-views">
                <button type="button" data-view="datos" class="on">Datos</button>
                <button type="button" data-view="estructura">Estructura</button>
                <button type="button" data-view="sql">SQL</button>
              </div>
              <div id="sqMsg"></div>
              <div id="sqPanelDatos">
                <div class="sqlx-empty" id="sqDatosVacio">Elige una tabla en la barra lateral.</div>
                <div id="sqDatosWrap" hidden>
                  <div class="row" style="gap:8px;margin-bottom:10px;flex-wrap:wrap">
                    <span id="sqTitulo" style="font-weight:600;font-family:ui-monospace,Consolas,monospace;font-size:13px"></span>
                    <div class="spacer"></div>
                    <button type="button" class="btn ghost sm" id="sqNuevaFila">+ Nueva fila</button>
                    <button type="button" class="btn ghost sm" id="sqRecargar">Recargar</button>
                  </div>
                  <div class="sqlgrid"><table class="sqltbl" id="sqTabla"><thead></thead><tbody></tbody></table></div>
                  <div class="sqlpager">
                    <button type="button" class="btn ghost sm" id="sqPrev">&larr; Anterior</button>
                    <button type="button" class="btn ghost sm" id="sqNext">Siguiente &rarr;</button>
                    <span id="sqInfo"></span>
                    <div class="spacer"></div>
                    <label style="font-size:12px">Filas
                      <select id="sqPer" style="margin-left:4px">
                        <option>25</option><option selected>50</option><option>100</option><option>250</option>
                      </select>
                    </label>
                  </div>
                </div>
              </div>
              <div id="sqPanelEstructura" hidden><div class="sqlx-empty">Elige una tabla en la barra lateral.</div></div>
              <div id="sqPanelSql" hidden>
                <div id="sqlEditorHost"><textarea id="sqEditor"></textarea></div>
                <div class="row" style="gap:8px;margin-top:10px;flex-wrap:wrap">
                  <button type="button" class="btn" id="sqRun">Ejecutar</button>
                  <span class="muted" style="font-size:12px">Ctrl+Enter</span>
                  <div class="spacer"></div>
                  <select id="sqHist" style="max-width:320px;font-size:12px"><option value="">Historial…</option></select>
                </div>
                <?php if (sqlsrv_driver_kind() !== 'sqlsrv'): ?>
                  <div class="muted" style="font-size:11.5px;margin-top:8px">
                    Con <code>pdo_odbc</code>, el texto que devuelve una consulta libre pasa por la conversión ANSI del driver:
                    se recupera todo lo que sea Windows-1252 (acentos, ñ, €), pero un carácter fuera de esa tabla llegaría como <code>?</code>.
                    La pestaña <b>Datos</b> no tiene esa limitación: ahí el texto viaja en binario y es exacto.
                  </div>
                <?php endif; ?>
                <div id="sqResultados" style="margin-top:12px"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Modal: editar / insertar fila -->
        <div id="sqRowModal" class="modal-overlay" hidden onclick="if(event.target===this)sqCloseRow()">
          <div class="modal-box" role="dialog" aria-modal="true" style="max-width:680px;text-align:left">
            <div class="row" style="margin-bottom:12px">
              <h3 id="sqRowTitle" style="margin:0;font-size:16px">Editar fila</h3>
              <div class="spacer"></div>
              <button type="button" class="lockbtn" onclick="sqCloseRow()" title="Cerrar" aria-label="Cerrar">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
            <div id="sqRowMsg"></div>
            <div id="sqRowFields" style="max-height:56vh;overflow:auto"></div>
            <div class="modal-actions" style="margin-top:14px">
              <button type="button" class="btn ghost" onclick="sqCloseRow()">Cancelar</button>
              <button type="button" class="btn" id="sqRowSave">Guardar</button>
            </div>
          </div>
        </div>

        <!-- Modal: confirmar borrado de fila -->
        <div id="sqDelModal" class="modal-overlay" hidden onclick="if(event.target===this)sqCloseDel()">
          <div class="modal-box" role="dialog" aria-modal="true">
            <div class="modal-ic">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>
              </svg>
            </div>
            <h3>¿Borrar esta fila?</h3>
            <p class="modal-tx">Se eliminará permanentemente la fila <strong id="sqDelWhere"></strong>. No se puede deshacer.</p>
            <div class="modal-actions">
              <button type="button" class="btn ghost" onclick="sqCloseDel()">Cancelar</button>
              <button type="button" class="btn danger" id="sqDelOk">Sí, borrar</button>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Modal: confirmar borrado de conexion -->
      <div id="sqConnModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDelConn()">
        <div class="modal-box" role="dialog" aria-modal="true">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            </svg>
          </div>
          <h3>¿Eliminar la conexión?</h3>
          <p class="modal-tx">Se quitará <strong id="sqConnName"></strong> de la lista del panel. <b>No se toca nada en el servidor</b>: solo se borran los datos de conexión guardados aquí.</p>
          <form method="post" class="modal-actions">
            <input type="hidden" name="action" value="sqlsrv_del">
            <input type="hidden" name="id" id="sqConnId">
            <button type="button" class="btn ghost" onclick="luaCloseDelConn()">Cancelar</button>
            <button type="submit" class="btn danger">Sí, eliminar</button>
          </form>
        </div>
      </div>
      <script>
        function luaAskDelConn(id, label){
          document.getElementById('sqConnName').textContent = label;
          document.getElementById('sqConnId').value = id;
          document.getElementById('sqConnModal').hidden = false;
          document.addEventListener('keydown', luaEscDelConn);
        }
        function luaCloseDelConn(){
          document.getElementById('sqConnModal').hidden = true;
          document.removeEventListener('keydown', luaEscDelConn);
        }
        function luaEscDelConn(e){ if(e.key==='Escape') luaCloseDelConn(); }
      </script>

      <?php if ($sqlSrv && !$sqlForm): ?>
      <link rel="stylesheet" href="assets/codemirror/lib/codemirror.css">
      <script src="assets/codemirror/lib/codemirror.js"></script>
      <script src="assets/codemirror/addon/edit/matchbrackets.js"></script>
      <script src="assets/codemirror/mode/sql/sql.js"></script>
      <script>
      (function(){
        var root = document.getElementById('sqlx');
        if (!root) return;
        var S = { conn: root.dataset.conn, db:'', schema:'', table:'', kind:'', label:'',
                  page:1, per:50, sort:'', dir:'asc', cols:[], pk:[], editable:false, rows:[] };
        var elDb=document.getElementById('sqDb'), elTables=document.getElementById('sqTables'),
            elFilter=document.getElementById('sqFilter'), elMsg=document.getElementById('sqMsg'),
            elTabla=document.getElementById('sqTabla'), elInfo=document.getElementById('sqInfo'),
            elTitulo=document.getElementById('sqTitulo'), elWrap=document.getElementById('sqDatosWrap'),
            elVacio=document.getElementById('sqDatosVacio');
        var todasTablas = [];

        function api(params, body){
          var qs = new URLSearchParams(Object.assign({ajax:'sqlsrv', conn:S.conn}, params));
          var opt = body ? {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams(body)} : {};
          return fetch('?'+qs.toString(), opt).then(function(r){ return r.json(); });
        }
        // Los mensajes siempre por textContent: traen texto del servidor SQL (y de las tablas
        // del usuario), que puede contener < > y comillas.
        function msg(txt, tipo){
          elMsg.innerHTML='';
          if(!txt) return;
          var d=document.createElement('div'); d.className='sqlmsg '+(tipo||'err'); d.textContent=txt;
          elMsg.appendChild(d);
        }
        function celda(v, meta){
          var td=document.createElement('td');
          if(v===null){ var s=document.createElement('span'); s.className='sqlnull'; s.textContent='NULL'; td.appendChild(s); return td; }
          if(meta && meta.bin){ var b=document.createElement('span'); b.className='sqlbin'; b.textContent='0x'+v; td.appendChild(b); td.title='0x'+v; return td; }
          var t=String(v);
          td.textContent = t.length>300 ? t.slice(0,300)+'…' : t;
          if(t.length>60) td.title=t;
          return td;
        }

        // ---- barra lateral ----
        function cargarDbs(){
          api({op:'dbs'}).then(function(j){
            elDb.innerHTML='';
            if(j.error){ msg(j.error); elDb.innerHTML='<option value="">(error)</option>'; return; }
            var o=document.createElement('option'); o.value=''; o.textContent='elige…'; elDb.appendChild(o);
            (j.dbs||[]).forEach(function(d){
              var op=document.createElement('option'); op.value=d.name;
              op.textContent = d.name + (d.sys ? '  (sistema)' : '');
              elDb.appendChild(op);
            });
            var guardada = sessionStorage.getItem('sqdb_'+S.conn);
            if(guardada && (j.dbs||[]).some(function(d){return d.name===guardada;})){ elDb.value=guardada; cargarTablas(); }
          }).catch(function(){ msg('No se pudo contactar con el panel.'); });
        }
        function cargarTablas(){
          S.db = elDb.value; S.table=''; S.schema='';
          sessionStorage.setItem('sqdb_'+S.conn, S.db);
          elWrap.hidden=true; elVacio.hidden=false;
          if(!S.db){ elTables.innerHTML='<div class="sqlx-empty">Elige una base de datos.</div>'; return; }
          elTables.innerHTML='<div class="sqlx-empty">cargando…</div>';
          api({op:'tables', db:S.db}).then(function(j){
            if(j.error){ elTables.innerHTML=''; msg(j.error); return; }
            todasTablas = j.tables||[];
            pintarTablas();
          });
        }
        function pintarTablas(){
          var f=(elFilter.value||'').toLowerCase();
          elTables.innerHTML='';
          var vis = todasTablas.filter(function(t){ return !f || (t.schema+'.'+t.name).toLowerCase().indexOf(f)>=0; });
          if(!vis.length){ elTables.innerHTML='<div class="sqlx-empty">Sin coincidencias.</div>'; return; }
          vis.forEach(function(t){
            var b=document.createElement('button');
            b.type='button'; b.className='sqlx-t'+(t.kind==='view'?' view':'');
            if(S.table===t.name && S.schema===t.schema) b.classList.add('on');
            var ic=document.createElement('span'); ic.className='ico'; ic.textContent = t.kind==='view' ? '◫' : '▤';
            b.appendChild(ic);
            var nm=document.createElement('span');
            nm.textContent = (t.schema==='dbo'? '' : t.schema+'.') + t.name;
            b.appendChild(nm);
            var n=document.createElement('span'); n.className='n';
            n.textContent = t.kind==='view' ? 'vista' : (t.rows>=0 ? t.rows.toLocaleString('es-ES') : '');
            b.appendChild(n);
            b.onclick=function(){ abrirTabla(t); };
            elTables.appendChild(b);
          });
        }
        function abrirTabla(t){
          S.schema=t.schema; S.table=t.name; S.kind=t.kind; S.page=1; S.sort=''; S.dir='asc';
          S.label=(t.schema==='dbo'?'':t.schema+'.')+t.name;
          pintarTablas();
          if(vistaActual==='estructura') cargarEstructura(); else { mostrarVista('datos'); cargarFilas(); }
        }

        // ---- datos ----
        function cargarFilas(){
          if(!S.table) return;
          msg('');
          elVacio.hidden=true; elWrap.hidden=false;
          elTitulo.textContent=S.label;
          api({op:'rows', db:S.db, schema:S.schema, table:S.table, kind:S.kind,
               page:S.page, per:S.per, sort:S.sort, dir:S.dir}).then(function(j){
            if(j.error){ msg(j.error); return; }
            S.cols=j.cols; S.pk=j.pk||[]; S.editable=!!j.editable; S.rows=j.rows||[];
            S.sort=j.sort; S.dir=j.dir;
            if(j.motivo) msg(j.motivo, 'warn');
            pintarFilas(j);
            document.getElementById('sqNuevaFila').style.display = S.editable ? '' : 'none';
          });
        }
        function pintarFilas(j){
          var thead=elTabla.tHead, tb=elTabla.tBodies[0];
          thead.innerHTML=''; tb.innerHTML='';
          var tr=document.createElement('tr');
          if(S.editable){ var thA=document.createElement('th'); thA.textContent=''; thA.style.cursor='default'; tr.appendChild(thA); }
          S.cols.forEach(function(c){
            var th=document.createElement('th'); th.title=c.type;
            th.appendChild(document.createTextNode(c.name));
            if(S.pk.indexOf(c.name)>=0){ var k=document.createElement('span'); k.className='pkmark'; k.textContent='PK'; th.appendChild(k); }
            if(c.name===S.sort){ var d=document.createElement('span'); d.className='dirmark'; d.textContent = S.dir==='asc'?'▲':'▼'; th.appendChild(d); }
            th.onclick=function(){ if(S.sort===c.name){ S.dir = S.dir==='asc'?'desc':'asc'; } else { S.sort=c.name; S.dir='asc'; } S.page=1; cargarFilas(); };
            tr.appendChild(th);
          });
          thead.appendChild(tr);
          S.rows.forEach(function(fila, idx){
            var r=document.createElement('tr');
            if(S.editable){
              var td=document.createElement('td'); td.className='acts';
              var be=document.createElement('button'); be.type='button'; be.className='sqlrowbtn'; be.textContent='Editar';
              be.onclick=function(){ abrirFila(idx); };
              var bd=document.createElement('button'); bd.type='button'; bd.className='sqlrowbtn del'; bd.textContent='Borrar';
              bd.style.marginLeft='4px';
              bd.onclick=function(){ pedirBorrado(idx); };
              td.appendChild(be); td.appendChild(bd); r.appendChild(td);
            }
            fila.forEach(function(v,i){ r.appendChild(celda(v, S.cols[i])); });
            tb.appendChild(r);
          });
          var desde=(j.page-1)*j.per+1, hasta=Math.min(j.page*j.per, j.total);
          elInfo.textContent = j.total ? (desde+'–'+hasta+' de '+(j.aprox?'~':'')+j.total.toLocaleString('es-ES')) : 'sin filas';
          document.getElementById('sqPrev').disabled = j.page<=1;
          document.getElementById('sqNext').disabled = hasta>=j.total;
        }

        // ---- estructura ----
        function cargarEstructura(){
          var p=document.getElementById('sqPanelEstructura');
          if(!S.table){ p.innerHTML='<div class="sqlx-empty">Elige una tabla en la barra lateral.</div>'; return; }
          p.innerHTML='<div class="sqlx-empty">cargando…</div>';
          api({op:'struct', db:S.db, schema:S.schema, table:S.table}).then(function(j){
            p.innerHTML='';
            if(j.error){ msg(j.error); return; }
            var h=document.createElement('div');
            h.style.cssText='font-weight:600;font-family:ui-monospace,Consolas,monospace;font-size:13px;margin-bottom:10px';
            h.textContent=S.label; p.appendChild(h);
            var g=document.createElement('div'); g.className='sqlgrid';
            var t=document.createElement('table'); t.className='sqltbl';
            var th=document.createElement('thead'); var tr=document.createElement('tr');
            ['Columna','Tipo','Nulos','Por defecto','Notas'].forEach(function(x){
              var c=document.createElement('th'); c.textContent=x; c.style.cursor='default'; tr.appendChild(c);
            });
            th.appendChild(tr); t.appendChild(th);
            var tb=document.createElement('tbody');
            (j.cols||[]).forEach(function(c){
              var r=document.createElement('tr');
              function td(txt, cls){ var d=document.createElement('td'); if(cls) d.className=cls; d.textContent=txt; r.appendChild(d); return d; }
              var d0=td(c.name); if((j.pk||[]).indexOf(c.name)>=0){ var k=document.createElement('span'); k.className='pkmark'; k.textContent=' PK'; d0.appendChild(k); }
              td(c.type);
              td(c.nullable ? 'sí' : 'no');
              if(c.default===null||c.default===undefined) td('—','sqlnull'); else td(c.default);
              var notas=[]; if(c.identity) notas.push('IDENTITY'); if(c.computed) notas.push('calculada');
              td(notas.join(', ') || '—');
              tb.appendChild(r);
            });
            t.appendChild(tb); g.appendChild(t); p.appendChild(g);
            var idx=j.indexes||[];
            var ht=document.createElement('div');
            ht.style.cssText='font-weight:600;font-size:13px;margin:16px 0 8px';
            ht.textContent='Índices ('+idx.length+')'; p.appendChild(ht);
            if(!idx.length){ var e0=document.createElement('div'); e0.className='sqlx-empty'; e0.textContent='Esta tabla no tiene índices.'; p.appendChild(e0); return; }
            var g2=document.createElement('div'); g2.className='sqlgrid';
            var t2=document.createElement('table'); t2.className='sqltbl';
            var th2=document.createElement('thead'); var tr2=document.createElement('tr');
            ['Índice','Columnas','Único','Tipo'].forEach(function(x){ var c=document.createElement('th'); c.textContent=x; c.style.cursor='default'; tr2.appendChild(c); });
            th2.appendChild(tr2); t2.appendChild(th2);
            var tb2=document.createElement('tbody');
            idx.forEach(function(i){
              var r=document.createElement('tr');
              function td(txt){ var d=document.createElement('td'); d.textContent=txt; r.appendChild(d); }
              td(i.name + (i.pk?'  (clave primaria)':''));
              td((i.cols||[]).join(', '));
              td(i.unique?'sí':'no');
              td(i.type);
              tb2.appendChild(r);
            });
            t2.appendChild(tb2); g2.appendChild(t2); p.appendChild(g2);
          });
        }

        // ---- edicion de filas ----
        var filaEditada = null;
        function pkDe(idx){
          var o={};
          S.pk.forEach(function(k){
            var i = S.cols.findIndex(function(c){ return c.name===k; });
            o[k] = i>=0 ? S.rows[idx][i] : null;
          });
          return o;
        }
        function abrirFila(idx){
          filaEditada = (idx===null) ? null : {idx:idx, pk:pkDe(idx)};
          document.getElementById('sqRowTitle').textContent = (idx===null?'Nueva fila en ':'Editar fila de ')+S.label;
          document.getElementById('sqRowMsg').innerHTML='';
          var cont=document.getElementById('sqRowFields'); cont.innerHTML='';
          S.cols.forEach(function(c,i){
            var val = idx===null ? null : S.rows[idx][i];
            var f=document.createElement('div'); f.className='sqlfield';
            var lab=document.createElement('label');
            lab.textContent=c.name+'  ·  '+c.type+(c.nullable?'':'  · obligatorio');
            f.appendChild(lab);
            if(c.identity || c.computed){
              var ro=document.createElement('div'); ro.className='meta';
              ro.textContent = (c.identity?'IDENTITY':'Calculada')+': lo genera el servidor'+(val!==null&&idx!==null?'  (actual: '+val+')':'');
              f.appendChild(ro); cont.appendChild(f); return;
            }
            var line=document.createElement('div'); line.className='rowline';
            var largo = /max|text|xml/i.test(c.type) || (val!==null && String(val).length>120);
            var input = document.createElement(largo?'textarea':'input');
            if(!largo) input.type='text';
            input.dataset.col=c.name;
            input.value = val===null ? '' : String(val);
            line.appendChild(input);
            if(c.nullable){
              var w=document.createElement('label'); w.className='sqlnullbox';
              var cb=document.createElement('input'); cb.type='checkbox'; cb.dataset.nullFor=c.name;
              cb.checked = (val===null);
              input.disabled = cb.checked;
              cb.onchange=function(){ input.disabled=cb.checked; if(cb.checked) input.value=''; };
              w.appendChild(cb); w.appendChild(document.createTextNode('NULL'));
              line.appendChild(w);
            }
            f.appendChild(line);
            if(c.bin){ var m=document.createElement('div'); m.className='meta'; m.textContent='Binario: en hexadecimal (p. ej. 0xDEADBEEF)'; f.appendChild(m); }
            cont.appendChild(f);
          });
          document.getElementById('sqRowModal').hidden=false;
          document.addEventListener('keydown', escRow);
        }
        window.sqCloseRow=function(){ document.getElementById('sqRowModal').hidden=true; document.removeEventListener('keydown', escRow); };
        function escRow(e){ if(e.key==='Escape') sqCloseRow(); }
        document.getElementById('sqRowSave').onclick=function(){
          var btn=this; var vals={}, nulls=[];
          document.querySelectorAll('#sqRowFields [data-col]').forEach(function(inp){
            var cb=document.querySelector('#sqRowFields [data-null-for="'+CSS.escape(inp.dataset.col)+'"]');
            if(cb && cb.checked) nulls.push(inp.dataset.col); else vals[inp.dataset.col]=inp.value;
          });
          btn.disabled=true;
          var body={op:'row_save', modo: filaEditada?'update':'insert', vals:JSON.stringify(vals), nulls:JSON.stringify(nulls)};
          if(filaEditada) body.pk=JSON.stringify(filaEditada.pk);
          api({op:'row_save', db:S.db, schema:S.schema, table:S.table}, body).then(function(j){
            btn.disabled=false;
            if(j.error){
              var m=document.getElementById('sqRowMsg'); m.innerHTML='';
              var d=document.createElement('div'); d.className='sqlmsg err'; d.textContent=j.error; m.appendChild(d);
              return;
            }
            sqCloseRow();
            cargarFilas();
            if(j.aviso) msg(j.aviso,'warn'); else msg(filaEditada?'Fila actualizada.':'Fila insertada.','ok');
          }).catch(function(){ btn.disabled=false; });
        };
        document.getElementById('sqNuevaFila').onclick=function(){ abrirFila(null); };

        var borrando=null;
        function pedirBorrado(idx){
          borrando=pkDe(idx);
          document.getElementById('sqDelWhere').textContent = Object.keys(borrando).map(function(k){ return k+'='+(borrando[k]===null?'NULL':borrando[k]); }).join(', ');
          document.getElementById('sqDelModal').hidden=false;
          document.addEventListener('keydown', escDel);
        }
        window.sqCloseDel=function(){ document.getElementById('sqDelModal').hidden=true; document.removeEventListener('keydown', escDel); };
        function escDel(e){ if(e.key==='Escape') sqCloseDel(); }
        document.getElementById('sqDelOk').onclick=function(){
          if(!borrando) return;
          var btn=this; btn.disabled=true;
          api({op:'row_del', db:S.db, schema:S.schema, table:S.table},
              {op:'row_del', pk:JSON.stringify(borrando)}).then(function(j){
            btn.disabled=false; sqCloseDel();
            if(j.error){ msg(j.error); return; }
            cargarFilas();
            msg(j.aviso || 'Fila borrada.', j.aviso?'warn':'ok');
          }).catch(function(){ btn.disabled=false; });
        };

        // ---- consola SQL ----
        var cm=null, HKEY='sqlsrv_hist_'+S.conn;
        function initEditor(){
          if(cm) return;
          cm = CodeMirror.fromTextArea(document.getElementById('sqEditor'), {
            mode:'text/x-mssql', theme:'lua', lineNumbers:true, matchBrackets:true, lineWrapping:true
          });
          cm.setValue('SELECT TOP 100 * FROM ');
          cm.on('keydown', function(inst, e){
            if((e.ctrlKey||e.metaKey) && e.key==='Enter'){ e.preventDefault(); ejecutar(); }
          });
          pintarHistorial();
        }
        function historial(){ try{ return JSON.parse(localStorage.getItem(HKEY)||'[]'); }catch(e){ return []; } }
        function pintarHistorial(){
          var sel=document.getElementById('sqHist'); sel.innerHTML='';
          var o=document.createElement('option'); o.value=''; o.textContent='Historial…'; sel.appendChild(o);
          historial().forEach(function(q){
            var op=document.createElement('option'); op.value=q;
            op.textContent = q.length>70 ? q.slice(0,70)+'…' : q;
            sel.appendChild(op);
          });
          sel.onchange=function(){ if(sel.value){ cm.setValue(sel.value); sel.value=''; cm.focus(); } };
        }
        function guardarHist(q){
          var h=historial().filter(function(x){ return x!==q; });
          h.unshift(q); h=h.slice(0,25);
          try{ localStorage.setItem(HKEY, JSON.stringify(h)); }catch(e){}
          pintarHistorial();
        }
        function ejecutar(){
          var sql=cm.getValue().trim();
          if(!sql) return;
          var out=document.getElementById('sqResultados');
          out.innerHTML=''; msg('');
          var wait=document.createElement('div'); wait.className='sqlx-empty'; wait.textContent='ejecutando…'; out.appendChild(wait);
          api({op:'query', db:S.db}, {op:'query', sql:sql}).then(function(j){
            out.innerHTML='';
            if(j.error){ msg(j.error); return; }
            guardarHist(sql);
            var res=document.createElement('div'); res.className='sqlmsg ok';
            res.textContent = (j.sets.length? j.sets.length+' conjunto(s) de resultados. ' : '')
                            + (j.afectadas? j.afectadas+' fila(s) afectada(s). ' : '')
                            + j.ms+' ms';
            out.appendChild(res);
            (j.sets||[]).forEach(function(set){
              if(set.truncado){
                var w=document.createElement('div'); w.className='sqlmsg warn';
                w.textContent='Mostrando solo las primeras 1000 filas.'; out.appendChild(w);
              }
              var g=document.createElement('div'); g.className='sqlgrid'; g.style.marginBottom='12px';
              var t=document.createElement('table'); t.className='sqltbl';
              var th=document.createElement('thead'), tr=document.createElement('tr');
              set.cols.forEach(function(c){ var x=document.createElement('th'); x.textContent=c; x.style.cursor='default'; tr.appendChild(x); });
              th.appendChild(tr); t.appendChild(th);
              var tb=document.createElement('tbody');
              set.rows.forEach(function(f){
                var r=document.createElement('tr');
                f.forEach(function(v){ r.appendChild(celda(v,null)); });
                tb.appendChild(r);
              });
              t.appendChild(tb); g.appendChild(t); out.appendChild(g);
            });
          }).catch(function(){ out.innerHTML=''; msg('No se pudo contactar con el panel.'); });
        }
        document.getElementById('sqRun').onclick=ejecutar;

        // ---- pestanas internas ----
        var vistaActual='datos';
        function mostrarVista(v){
          vistaActual=v;
          document.querySelectorAll('.sqlx-views button').forEach(function(b){ b.classList.toggle('on', b.dataset.view===v); });
          document.getElementById('sqPanelDatos').hidden      = v!=='datos';
          document.getElementById('sqPanelEstructura').hidden = v!=='estructura';
          document.getElementById('sqPanelSql').hidden        = v!=='sql';
          if(v==='sql'){ initEditor(); setTimeout(function(){ cm.refresh(); cm.focus(); }, 10); }
          if(v==='estructura') cargarEstructura();
          if(v==='datos' && S.table) cargarFilas();
        }
        document.querySelectorAll('.sqlx-views button').forEach(function(b){
          b.onclick=function(){ mostrarVista(b.dataset.view); };
        });

        elDb.onchange=cargarTablas;
        elFilter.oninput=pintarTablas;
        document.getElementById('sqRecargar').onclick=cargarFilas;
        document.getElementById('sqPrev').onclick=function(){ if(S.page>1){ S.page--; cargarFilas(); } };
        document.getElementById('sqNext').onclick=function(){ S.page++; cargarFilas(); };
        document.getElementById('sqPer').onchange=function(){ S.per=parseInt(this.value,10)||50; S.page=1; cargarFilas(); };
        cargarDbs();
      })();
      </script>
      <?php endif; ?>

    <?php endif; ?>


<?php endif; ?>
