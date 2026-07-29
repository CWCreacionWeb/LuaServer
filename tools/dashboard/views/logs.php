  <?php if ($tab==='logs'): /* ---------- PESTAÑA LOGS ---------- */
      $logDir = $ROOT.'/logs/apache';
      $logFiles = [];
      foreach (glob($logDir.'/*.log') as $f) $logFiles[] = basename($f);
      sort($logFiles);
      $byProject = logs_group_by_project($logFiles);
      $projects = array_keys($byProject);

      // Se guarda si la URL traia ?project=/?log= ANTES de invalidarlos: hace falta para
      // distinguir mas abajo "primera visita a la pestana" de "me pedian un proyecto que
      // se ha quedado sin archivos" (p.ej. tras borrar su ultimo .log) -- de lo contrario
      // ambos casos se veian iguales (sel==='' && selProject==='') y el segundo saltaba
      // por sorpresa a los logs de (sistema) en vez de quedarse en el estado vacio.
      $hadLogParam     = isset($_GET['log']) && $_GET['log'] !== '';
      $hadProjectParam = isset($_GET['project']) && $_GET['project'] !== '';
      $sel = safe_logname($_GET['log'] ?? '');
      if ($sel !== '' && !in_array($sel, $logFiles, true)) $sel = '';
      $selProject = (string)($_GET['project'] ?? '');
      if ($sel !== '') {
          // el archivo manda: derivar su proyecto real, por si la URL trae uno inconsistente
          foreach ($byProject as $p => $files) {
              foreach ($files as $f) { if ($f['file'] === $sel) { $selProject = $p; break 2; } }
          }
      } elseif ($selProject === '' || !isset($byProject[$selProject])) {
          $selProject = '';
      }
      // Sin ?project= ni ?log= (primera visita a la pestaña): mismo valor por defecto de
      // siempre (error.log de sistema), solo que ahora expresado en el modelo proyecto+archivo.
      if ($sel === '' && $selProject === '' && $byProject && !$hadLogParam && !$hadProjectParam) {
          $selProject = isset($byProject['(sistema)']) ? '(sistema)' : $projects[0];
          foreach ($byProject[$selProject] as $f) { if ($f['kind']==='error') { $sel = $f['file']; break; } }
          if ($sel === '' && isset($byProject[$selProject][0])) $sel = $byProject[$selProject][0]['file'];
      }

      $refresh = (($_GET['refresh']??'')==='1');
      $content = $sel !== '' ? tail_file($logDir.'/'.$sel, 300) : '';
  ?>
    <div class="row" style="margin-bottom:14px;gap:8px 16px;flex-wrap:wrap;align-items:flex-start">
      <div class="logpicker">
        <input type="text" id="logProjectInput" class="logpicker-input" autocomplete="off" spellcheck="false"
               placeholder="Buscar proyecto&hellip; (<?= count($projects) ?>)" value="<?= $selProject!==''?e(log_project_label($selProject)):'' ?>">
        <div id="logProjectList" class="logpicker-list" hidden></div>
      </div>
      <div class="row" id="logFileLinks" style="gap:8px;flex-wrap:wrap;align-items:center;display:<?= $selProject===''?'none':'flex' ?>">
        <?php foreach (($byProject[$selProject] ?? []) as $i => $f): ?>
          <?php if ($i > 0): ?><span class="loglink-sep">&middot;</span><?php endif; ?>
          <a href="?tab=logs&project=<?= urlencode($selProject) ?>&log=<?= urlencode($f['file']) ?><?= $refresh?'&refresh=1':'' ?>"
             class="loglink<?= $f['file']===$sel?' active':'' ?>"><?= e(log_kind_label($f['kind'])) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="spacer"></div>
      <div id="logActions" style="display:<?= $sel===''?'none':'flex' ?>;gap:8px;align-items:center">
        <a id="logAutoRefreshLink" href="?tab=logs&project=<?= urlencode($selProject) ?>&log=<?= urlencode($sel) ?><?= $refresh?'':'&refresh=1' ?>" class="btn ghost sm"><?= $refresh?'Auto-refresco ON':'Auto-refresco' ?></a>
        <button type="button" class="btn ghost sm" id="logClearBtn">Vaciar</button>
        <button type="button" class="btn danger sm" id="logDeleteBtn">Eliminar</button>
        <span id="logActionStatus" class="muted" style="font-size:12px"></span>
      </div>
    </div>
    <div id="logEmptyCard" class="card muted" style="<?= $sel!==''?'display:none':'' ?>">Elige un proyecto y luego un archivo de log para ver su contenido.</div>
    <pre class="logview" id="logViewPre" style="<?= $sel===''?'display:none':'' ?>"><?= $content!=='' ? highlight_error_log($content) : '(vac&iacute;o)' ?></pre>

    <!-- Modal de confirmacion de borrado de archivo de log -->
    <div id="delLogModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDeleteLog()">
      <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="delLogTitle">
        <div class="modal-ic">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
        </div>
        <h3 id="delLogTitle">¿Eliminar el archivo de log?</h3>
        <p class="modal-tx">Se borrará <strong id="delLogName"></strong> del disco de forma permanente. Si el servicio que lo genera sigue activo, puede volver a crearse solo.</p>
        <div class="modal-actions">
          <button type="button" class="btn ghost" onclick="luaCloseDeleteLog()">Cancelar</button>
          <button type="button" class="btn danger" id="delLogConfirm">Sí, eliminar</button>
        </div>
      </div>
    </div>

    <!-- Modal de confirmacion de vaciado de log -->
    <div id="clearLogModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseClearLog()">
      <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="clearLogTitle">
        <div class="modal-ic">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
        </div>
        <h3 id="clearLogTitle">¿Vaciar el log?</h3>
        <p class="modal-tx">Se borrará todo el contenido de <strong id="clearLogName"></strong>. Esto no se puede deshacer.</p>
        <div class="modal-actions">
          <button type="button" class="btn ghost" onclick="luaCloseClearLog()">Cancelar</button>
          <button type="button" class="btn danger" id="clearLogConfirm">Sí, vaciar</button>
        </div>
      </div>
    </div>

    <script>
      (function(){
        var PROJECTS=<?= json_encode(array_map(function($p){ return ['key'=>$p,'label'=>log_project_label($p)]; }, $projects), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
        var curProject=<?= json_encode($selProject) ?>, curLog=<?= json_encode($sel) ?>, REFRESH=<?= $refresh?'true':'false' ?>;
        var inp=document.getElementById('logProjectInput'), list=document.getElementById('logProjectList');
        var linksRow=document.getElementById('logFileLinks');
        var actionsBox=document.getElementById('logActions');
        var emptyCard=document.getElementById('logEmptyCard');
        var viewPre=document.getElementById('logViewPre');
        var autoLink=document.getElementById('logAutoRefreshLink');
        var statusEl=document.getElementById('logActionStatus');
        var items=[], active=-1;
        function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
        function projectLabel(key){
          for (var i=0;i<PROJECTS.length;i++){ if (PROJECTS[i].key===key) return PROJECTS[i].label; }
          return key;
        }
        function go(key){ location.href='?tab=logs&project='+encodeURIComponent(key)+(REFRESH?'&refresh=1':''); }
        function render(filter){
          var f=(filter||'').toLowerCase();
          items = PROJECTS.filter(function(p){ return p.label.toLowerCase().indexOf(f)!==-1; });
          active=-1;
          if(!items.length){ list.innerHTML='<div class="logpicker-empty">Sin coincidencias</div>'; list.hidden=false; return; }
          list.innerHTML = items.map(function(p){
            return '<div class="logpicker-opt'+(p.key===curProject?' sel':'')+'" data-key="'+esc(p.key)+'">'+esc(p.label)+'</div>';
          }).join('');
          list.hidden=false;
        }
        function highlight(i){
          Array.from(list.querySelectorAll('.logpicker-opt')).forEach(function(el,j){ el.classList.toggle('on', j===i); });
          active=i;
          var el=list.children[i]; if(el && el.scrollIntoView) el.scrollIntoView({block:'nearest'});
        }
        inp.addEventListener('focus', function(){ render(''); inp.select(); });
        inp.addEventListener('input', function(){ render(inp.value); });
        inp.addEventListener('keydown', function(e){
          if(list.hidden){ if(e.key==='ArrowDown'||e.key==='ArrowUp'){ render(''); e.preventDefault(); } return; }
          if(e.key==='ArrowDown'){ e.preventDefault(); highlight(Math.min(active+1, items.length-1)); }
          else if(e.key==='ArrowUp'){ e.preventDefault(); highlight(Math.max(active-1,0)); }
          else if(e.key==='Enter'){ e.preventDefault(); if(items[active]) go(items[active].key); else if(items.length===1) go(items[0].key); }
          else if(e.key==='Escape'){ list.hidden=true; inp.value=projectLabel(curProject); inp.blur(); }
        });
        list.addEventListener('mousedown', function(e){
          var opt=e.target.closest('.logpicker-opt'); if(!opt||!opt.dataset.key) return;
          go(opt.dataset.key);
        });
        document.addEventListener('click', function(e){ if(!e.target.closest('.logpicker')){ list.hidden=true; inp.value=projectLabel(curProject); } });

        // ---- Borrar/vaciar sin recargar: la seleccion la decide el cliente con lo que el
        // servidor devuelve en la respuesta, no una heuristica de redirect adivinando que
        // proyecto/archivo tocaria despues (eso era lo que perdia la seleccion al borrar).
        function renderLinks(files){
          linksRow.innerHTML = files.map(function(f,i){
            var sep = i>0 ? '<span class="loglink-sep">&middot;</span>' : '';
            var active = f.file===curLog ? ' active' : '';
            return sep+'<a href="?tab=logs&project='+encodeURIComponent(curProject)+'&log='+encodeURIComponent(f.file)+'" class="loglink'+active+'">'+esc(f.label)+'</a>';
          }).join('');
          linksRow.style.display = files.length ? 'flex' : 'none';
        }
        function selectLog(file, content){
          curLog = file;
          if (file === '') {
            actionsBox.style.display = 'none';
            emptyCard.style.display = '';
            viewPre.style.display = 'none';
          } else {
            actionsBox.style.display = 'flex';
            emptyCard.style.display = 'none';
            viewPre.style.display = '';
            viewPre.innerHTML = content !== '' ? content : '(vacío)';
            autoLink.href = '?tab=logs&project='+encodeURIComponent(curProject)+'&log='+encodeURIComponent(file)+(REFRESH?'&refresh=1':'');
          }
          var qs = '?tab=logs&project='+encodeURIComponent(curProject)+(file!==''?'&log='+encodeURIComponent(file):'');
          history.replaceState(null, '', qs);
        }
        function flash(msg){
          statusEl.textContent = msg;
          setTimeout(function(){ if (statusEl.textContent===msg) statusEl.textContent=''; }, 2500);
        }
        function post(op, log){
          return fetch('?', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'ajax='+op+'&log='+encodeURIComponent(log)}).then(function(r){ return r.json(); });
        }
        document.getElementById('delLogConfirm').addEventListener('click', function(){
          var log = curLog;
          luaCloseDeleteLog();
          post('logdelete', log).then(function(j){
            if (j.error) { flash(j.error); return; }
            renderLinks(j.files);
            selectLog(j.next, j.content);
            if (!j.files.length) {
              PROJECTS = PROJECTS.filter(function(p){ return p.key !== curProject; });
              curProject = '';
              inp.value = '';
              inp.placeholder = 'Buscar proyecto… (' + PROJECTS.length + ')';
              history.replaceState(null, '', '?tab=logs');
            }
            flash('Eliminado.');
          }).catch(function(){ flash('Error de red al eliminar.'); });
        });
        document.getElementById('clearLogConfirm').addEventListener('click', function(){
          var log = curLog;
          luaCloseClearLog();
          post('logclear', log).then(function(j){
            if (j.error) { flash(j.error); return; }
            if (log === curLog) { viewPre.innerHTML = '(vacío)'; }
            flash('Vaciado.');
          }).catch(function(){ flash('Error de red al vaciar.'); });
        });
        document.getElementById('logDeleteBtn').addEventListener('click', function(){ luaAskDeleteLog(curLog); });
        document.getElementById('logClearBtn').addEventListener('click', function(){ luaAskClearLog(curLog); });
      })();

      function luaAskDeleteLog(name){
        document.getElementById('delLogName').textContent = name;
        document.getElementById('delLogModal').hidden = false;
        document.addEventListener('keydown', luaEscDeleteLog);
      }
      function luaCloseDeleteLog(){
        document.getElementById('delLogModal').hidden = true;
        document.removeEventListener('keydown', luaEscDeleteLog);
      }
      function luaEscDeleteLog(e){ if(e.key==='Escape') luaCloseDeleteLog(); }

      function luaAskClearLog(name){
        document.getElementById('clearLogName').textContent = name;
        document.getElementById('clearLogModal').hidden = false;
        document.addEventListener('keydown', luaEscClearLog);
      }
      function luaCloseClearLog(){
        document.getElementById('clearLogModal').hidden = true;
        document.removeEventListener('keydown', luaEscClearLog);
      }
      function luaEscClearLog(e){ if(e.key==='Escape') luaCloseClearLog(); }
    </script>


<?php endif; ?>
