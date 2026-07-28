  <?php if ($tab==='proyecto'): /* ---------- FICHA DE PROYECTO ---------- */
      $pName = (string)($_GET['name'] ?? '');
      // Acepta un name= con distinto casing/espacios que la clave real de sites.json
      // (p.ej. "arquitecturaTgin", el nombre tal cual en el Explorador de Windows, cuando
      // la clave registrada quedo en minusculas via slug_from_name): sin esto, la ficha
      // y cualquier accion que se dispare desde ella fallaban en silencio.
      $pKey = resolve_site_key($sites, $pName);
      if ($pKey !== null) { $pName = $pKey; }
      $pInfo = $pKey !== null ? $sites[$pKey] : null; ?>

    <a href="?tab=proyectos" class="muted" style="display:inline-block;margin-bottom:14px">&larr; Volver a proyectos</a>

    <?php if ($pInfo === null): ?>
      <div class="card muted">No se encontró el proyecto "<?= e($pName) ?>".</div>
    <?php else:
        $pVer = is_array($pInfo) ? ($pInfo['php'] ?? '?') : $pInfo;
        $pDom = (is_array($pInfo) && !empty($pInfo['domain'])) ? $pInfo['domain'] : $pName.'.'.$tld;
        $pExtPath = (is_array($pInfo) && !empty($pInfo['path'])) ? $pInfo['path'] : null;
        $pDir = project_dir($WWW, $pInfo, $pName);
        $pLocked = project_locked($pDir);
        $pCoverFile = cover_path($ROOT, $pName);
        $pHasComposer = is_file($pDir.'/composer.json');
        $pHasNpm = is_file($pDir.'/package.json');
        $pHasArtisan = is_file($pDir.'/artisan');
        $termOn = is_file($ROOT.'/config/terminal.on');
        $pGit = is_dir($pDir) ? git_info($pDir) : null;
        $pErrLog = tail_file($ROOT.'/logs/apache/'.$pName.'-error.log', 200);
        $pType = is_array($pInfo) ? ($pInfo['type'] ?? null) : null;
        $pTypeLabel = project_type_label($pType); ?>

      <div class="card row" style="align-items:flex-start;flex-wrap:wrap;gap:16px">
        <?php if ($pCoverFile): ?>
          <div style="width:110px;height:74px;border-radius:6px;flex:0 0 auto;background-size:cover;background-position:center;background-image:url('?cover=<?= e(rawurlencode($pName)) ?>&t=<?= filemtime($pCoverFile) ?>')"></div>
        <?php endif; ?>
        <div style="min-width:240px;flex:1">
          <div class="row" style="gap:8px">
            <span style="font-size:20px;font-weight:700"><?= e($pName) ?></span>
            <?php if ($pTypeLabel): ?><span class="typetag typetag-<?= e($pType) ?>"><?= project_type_icon($pType) ?><?= e($pTypeLabel) ?></span><?php endif; ?>
            <?php if ($pExtPath): ?><span class="exttag" title="Proyecto externo: <?= e($pExtPath) ?>">ext</span><?php endif; ?>
            <span class="jstate <?= $pLocked?'warn':'ok' ?>"><?= $pLocked?'Bloqueado':'Desbloqueado' ?></span>
            <span class="jstate run">PHP <?= e($pVer) ?></span>
            <?php if ($termOn && ($pHasComposer || $pHasNpm || $pHasArtisan)): ?>
              <button type="button" class="runbtn lua-runbtn" title="Ejecutar Composer/NPM/Artisan" aria-label="Ejecutar Composer/NPM/Artisan" data-name="<?= e($pName) ?>" data-path="<?= e(term_win($pDir)) ?>" data-composer="<?= $pHasComposer?'1':'0' ?>" data-npm="<?= $pHasNpm?'1':'0' ?>" data-artisan="<?= $pHasArtisan?'1':'0' ?>" data-php="<?= e($pVer) ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              </button>
            <?php endif; ?>
          </div>
          <a class="url" href="http://<?= e($pDom) ?>" target="_blank" style="display:inline-block;margin-top:6px">http://<?= e($pDom) ?> &#8599;</a>
          <form method="post" class="inline" style="margin-top:6px;gap:6px">
            <input type="hidden" name="action" value="set_domain">
            <input type="hidden" name="name" value="<?= e($pName) ?>">
            <input name="domain" value="<?= e($pInfo['domain'] ?? '') ?>" placeholder="<?= e($pName.'.'.$tld) ?> (por defecto)" style="width:230px;font-size:12px">
            <button class="btn ghost sm" type="submit" title="Deja el campo vacío para volver al dominio por defecto">Guardar dominio</button>
          </form>
          <div class="muted" style="margin-top:8px;font-size:12px;font-family:ui-monospace,Consolas,monospace" title="<?= e($pDir) ?>"><?= e($pDir) ?></div>
          <div class="row" style="margin-top:10px;gap:6px">
            <?php if ($pHasComposer): ?><span class="tag">composer.json</span><?php endif; ?>
            <?php if ($pHasNpm): ?><span class="tag">package.json</span><?php endif; ?>
            <?php if ($pHasArtisan): ?><span class="tag">artisan</span><?php endif; ?>
            <?php if ($pGit): ?><span class="tag">git &middot; <?= e($pGit['branch']) ?></span><?php endif; ?>
          </div>
        </div>
      </div>

      <div class="pgrid2">
        <?php if ($pGit): ?>
          <div class="card">
            <div class="row" style="margin-bottom:10px">
              <div style="font-weight:600">Git</div>
              <span class="jstate ok"><?= e($pGit['branch']) ?></span>
              <?php if ($pGit['dirty']>0): ?><span class="jstate warn"><?= (int)$pGit['dirty'] ?> cambio(s) sin commitear</span><?php else: ?><span class="jstate ok">Limpio</span><?php endif; ?>
              <div class="spacer"></div>
              <?php if ($pGit['remote']!==''): ?><span class="muted" style="font-size:12px"><?= e($pGit['remote']) ?></span><?php endif; ?>
            </div>
            <?php if (!$pGit['commits']): ?>
              <div class="muted">Sin commits todavía.</div>
            <?php else: ?>
              <div class="logview" style="font-family:inherit">
                <?php foreach ($pGit['commits'] as $c): ?>
                  <div class="row" style="gap:10px;padding:5px 0;border-bottom:1px solid var(--line)">
                    <code style="font-size:11px"><?= e($c['hash']) ?></code>
                    <span style="flex:1;color:var(--tx)"><?= e($c['subject']) ?></span>
                    <span class="muted" style="font-size:11px;white-space:nowrap"><?= e($c['author']) ?> &middot; <?= e($c['date']) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="card">
            <div class="muted">Este proyecto no es un repositorio Git (o <code>git</code> no está disponible en esta máquina).</div>
            <form method="post" class="inline" style="margin-top:12px">
              <input type="hidden" name="action" value="git_connect">
              <input type="hidden" name="name" value="<?= e($pName) ?>">
              <input name="url" placeholder="https://github.com/usuario/repo.git" style="flex:1;min-width:240px" required>
              <button class="btn-git" type="submit" title="Inicializa el repo si hace falta, con un commit inicial, y añade este remoto">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="3" x2="6" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg>
                Conectar repositorio
              </button>
            </form>
          </div>
        <?php endif; ?>

        <div class="card">
          <div class="row" style="margin-bottom:10px">
            <div style="font-weight:600">Log de errores de este proyecto</div>
            <span class="muted" style="font-size:12px"><?= e($pName) ?>-error.log</span>
            <div class="spacer"></div>
            <a class="btn ghost sm" href="?tab=logs&log=<?= urlencode($pName.'-error.log') ?>">Ver completo</a>
            <?php if ($pErrLog!==''): ?>
              <button type="button" class="btn ghost sm" onclick="luaAskClearProjLog()">Vaciar</button>
            <?php endif; ?>
          </div>
          <?php if ($pErrLog===''): ?>
            <div class="muted">Sin errores recientes.</div>
          <?php else: ?>
            <pre class="logview"><?= highlight_error_log($pErrLog) ?></pre>
          <?php endif; ?>
        </div>
      </div>

      <!-- Modal de confirmacion de vaciado del log de errores de este proyecto -->
      <div id="clearProjLogModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseClearProjLog()">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="clearProjLogTitle">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
              <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
          </div>
          <h3 id="clearProjLogTitle">¿Vaciar el log?</h3>
          <p class="modal-tx">Se borrará todo el contenido de <strong><?= e($pName) ?>-error.log</strong>. Esto no se puede deshacer.</p>
          <form method="post" class="modal-actions">
            <input type="hidden" name="action" value="clearlog">
            <input type="hidden" name="log" value="<?= e($pName.'-error.log') ?>">
            <input type="hidden" name="back" value="?tab=proyecto&name=<?= e(rawurlencode($pName)) ?>">
            <button type="button" class="btn ghost" onclick="luaCloseClearProjLog()">Cancelar</button>
            <button type="submit" class="btn danger">Sí, vaciar</button>
          </form>
        </div>
      </div>
      <script>
        function luaAskClearProjLog(){
          document.getElementById('clearProjLogModal').hidden = false;
          document.addEventListener('keydown', luaEscClearProjLog);
        }
        function luaCloseClearProjLog(){
          document.getElementById('clearProjLogModal').hidden = true;
          document.removeEventListener('keydown', luaEscClearProjLog);
        }
        function luaEscClearProjLog(e){ if(e.key==='Escape') luaCloseClearProjLog(); }
      </script>

      <div class="pgrid2">
      <div class="card">
        <div style="font-weight:600;margin-bottom:10px">Archivos</div>
        <?php if (!is_dir($pDir)): ?>
          <div class="muted">No se encontró la carpeta del proyecto.</div>
        <?php else: $tCount=0; ?>
          <div class="tree" id="projTree"><?= tree_node_html($pDir, '', true, $tCount, 4000) ?></div>
          <script>
          (function(){
            var root = document.getElementById('projTree');
            if(!root) return;
            root.addEventListener('click', function(ev){
              var frow = ev.target.closest('.trow.tfile');
              if (frow && root.contains(frow)) {
                var rel = frow.getAttribute('data-rel');
                var label = frow.querySelector('span').textContent;
                luaOpenFileEditor(<?= json_encode($pName) ?>, rel, label);
                return;
              }
              var row = ev.target.closest('.trow.tdir');
              if(!row || !root.contains(row)) return;
              var box = row.nextElementSibling;
              var willOpen = !row.classList.contains('open');
              row.classList.toggle('open', willOpen);
              if (box) box.hidden = !willOpen;
              if (willOpen && row.dataset.lazy==='1' && !row.dataset.loaded) {
                row.dataset.loaded='1';
                var rel = row.getAttribute('data-rel');
                fetch('?ajax=tree&name=<?= rawurlencode($pName) ?>&rel='+encodeURIComponent(rel))
                  .then(function(r){ return r.json(); })
                  .then(function(j){ box.innerHTML = j.html || '<div class="tnode-more">(vacío)</div>'; })
                  .catch(function(){ box.innerHTML = '<div class="tnode-more">Error al cargar.</div>'; });
              }
            });
          })();
          </script>
        <?php endif; ?>
      </div>

      <?php
        $pFtp = ftp_config_get($ROOT, $pName) ?: ['host'=>'','port'=>21,'user'=>'','pass'=>'','path'=>'/','ssl'=>false,'exclude'=>'.git, node_modules, .idea'];
        $pFtpJobs = array_values(array_filter($jobs, function($j) use ($pName){ return ($j['type']??'')==='ftp_deploy' && ($j['name']??'')===$pName; }));
        $pFtpJob = $pFtpJobs[0] ?? null;
      ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:10px">Desplegar por FTP</div>
        <form method="post" class="inline">
          <input type="hidden" name="action" value="ftp_save">
          <input type="hidden" name="name" value="<?= e($pName) ?>">
          <div><label>Host</label><input name="ftp_host" value="<?= e($pFtp['host']) ?>" placeholder="ftp.tuhosting.com" style="width:200px"></div>
          <div><label>Puerto</label><input name="ftp_port" value="<?= e($pFtp['port']) ?>" style="width:70px"></div>
          <div><label>Usuario</label><input name="ftp_user" value="<?= e($pFtp['user']) ?>" style="width:150px"></div>
          <div><label>Contraseña</label><input type="password" name="ftp_pass" placeholder="<?= ($pFtp['pass']??'')!==''?'••••••• (sin cambios)':'contraseña' ?>" autocomplete="off" style="width:150px"></div>
          <div><label>Ruta remota</label><input name="ftp_path" value="<?= e($pFtp['path']) ?>" style="width:150px"></div>
          <label style="display:flex;align-items:center;gap:6px;font-weight:400;margin-top:22px">
            <input type="checkbox" name="ftp_ssl" value="1" <?= !empty($pFtp['ssl'])?'checked':'' ?> style="width:auto"> FTPS (TLS)
          </label>
          <div style="flex:1;min-width:220px"><label>Excluir (coincide con parte de la ruta)</label><input name="ftp_exclude" value="<?= e($pFtp['exclude']) ?>" style="width:100%"></div>
          <button class="btn ghost" type="submit">Guardar</button>
        </form>
        <form method="post" style="margin-top:12px" onsubmit="return confirm('¿Desplegar ahora los archivos de <?= e($pName) ?> por FTP a <?= e($pFtp['host']) ?>?')">
          <input type="hidden" name="action" value="ftp_deploy">
          <input type="hidden" name="name" value="<?= e($pName) ?>">
          <button class="btn" type="submit" <?= ($pFtp['host']??'')===''?'disabled':'' ?>>Desplegar ahora</button>
        </form>
        <div class="muted" style="margin-top:10px;font-size:12px">Sube todos los archivos del proyecto a la ruta remota indicada (crea carpetas si no existen). La contraseña se guarda en texto plano en <code>config\ftp\</code> (fuera de git), solo en esta máquina.</div>
        <?php if ($pFtpJob):
              $st=$pFtpJob['state']??'?'; $cls=['done'=>'ok','error'=>'err','running'=>'run','queued'=>'warn']; $c=$cls[$st]??'run';
              $tail = in_array($st,['running','error','queued'],true) ? job_log_tail($ROOT,$pFtpJob['id']??'') : ''; ?>
          <div class="row" style="margin-top:14px">
            <span class="jstate <?= $c ?>"><?= e(strtoupper($st)) ?></span>
            <span class="muted"><?= e($pFtpJob['msg']??'') ?></span>
          </div>
          <?php if ($tail): ?><pre class="joblog"><?= e($tail) ?></pre><?php endif; ?>
        <?php endif; ?>
      </div>
      </div>

      <?php $pTermOn = term_enabled($ROOT); ?>
      <?php if ($pTermOn && is_dir($pDir)): ?>
        <div class="card">
          <div style="font-weight:600;margin-bottom:10px">Terminal <span class="muted" style="font-weight:400;font-size:12px">— arranca ya en la carpeta de este proyecto</span></div>
          <?= render_terminal_widget('pterm', $pDir, false) ?>
        </div>
      <?php elseif (!$pTermOn): ?>
        <div class="card">
          <div style="font-weight:600;margin-bottom:6px">Terminal</div>
          <div class="muted">Actívala en <a href="?tab=config">Configuración del servidor</a> para ejecutar comandos aquí mismo, arrancando directamente en la carpeta de este proyecto.</div>
        </div>
      <?php endif; ?>

      <!-- Editor de codigo (CodeMirror 5, autoalojado: sin CDN ni build step) -->
      <link rel="stylesheet" href="assets/codemirror/lib/codemirror.css">
      <script src="assets/codemirror/lib/codemirror.js"></script>
      <script src="assets/codemirror/addon/edit/matchbrackets.js"></script>
      <script src="assets/codemirror/mode/xml/xml.js"></script>
      <script src="assets/codemirror/mode/javascript/javascript.js"></script>
      <script src="assets/codemirror/mode/css/css.js"></script>
      <script src="assets/codemirror/mode/htmlmixed/htmlmixed.js"></script>
      <script src="assets/codemirror/mode/clike/clike.js"></script>
      <script src="assets/codemirror/mode/php/php.js"></script>
      <script src="assets/codemirror/mode/sql/sql.js"></script>
      <script src="assets/codemirror/mode/markdown/markdown.js"></script>
      <script src="assets/codemirror/mode/shell/shell.js"></script>

      <!-- Modal: editor de codigo (clic en un archivo del arbol) -->
      <div id="fileEditorModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseFileEditor()">
        <div class="modal-box" role="dialog" aria-modal="true" style="max-width:1000px;width:95vw;text-align:left">
          <div class="row" style="margin-bottom:10px">
            <h3 id="fileEditorTitle" style="margin:0;font-size:14px;font-family:ui-monospace,Consolas,monospace;font-weight:600"></h3>
            <div class="spacer"></div>
            <span class="muted" id="fileEditorStatus" style="font-size:12px"></span>
            <button type="button" class="btn ghost sm" onclick="luaCloseFileEditor()">Cerrar</button>
            <button type="button" class="btn sm" id="fileEditorSave">Guardar</button>
          </div>
          <div id="fileEditorHost" style="height:60vh"><textarea id="fileEditorArea" spellcheck="false" autocapitalize="off" autocomplete="off"></textarea></div>
          <div class="muted" style="margin-top:8px;font-size:11px">Ctrl+S para guardar.</div>
        </div>
      </div>
      <script>
        (function(){
          var modal=null, titleEl=null, host=null, area=null, status=null, saveBtn=null, cm=null, curName=null, curRel=null, curEnc='UTF-8';

          function modeForFile(name){
            var ext = (name.split('.').pop() || '').toLowerCase();
            var map = {
              php:'application/x-httpd-php', phtml:'application/x-httpd-php',
              html:'text/html', htm:'text/html',
              js:'text/javascript', mjs:'text/javascript', cjs:'text/javascript',
              json:'application/json',
              css:'text/css',
              xml:'application/xml', svg:'application/xml',
              md:'text/markdown', markdown:'text/markdown',
              sql:'text/x-mysql',
              sh:'text/x-sh', bash:'text/x-sh'
            };
            return map[ext] || null;
          }

          function init(){
            modal = document.getElementById('fileEditorModal');
            titleEl = document.getElementById('fileEditorTitle');
            host = document.getElementById('fileEditorHost');
            area = document.getElementById('fileEditorArea');
            status = document.getElementById('fileEditorStatus');
            saveBtn = document.getElementById('fileEditorSave');
            saveBtn.addEventListener('click', save);
            cm = CodeMirror.fromTextArea(area, {
              lineNumbers: true,
              theme: 'lua',
              matchBrackets: true,
              indentUnit: 4,
              tabSize: 4,
              indentWithTabs: true,
              lineWrapping: false,
              extraKeys: { 'Ctrl-S': function(){ save(); return false; }, 'Cmd-S': function(){ save(); return false; } }
            });
            cm.setSize('100%', '100%');
          }

          function save(){
            if (!curName || cm.getOption('readOnly')) return;
            status.textContent = 'Guardando…';
            fetch('?', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
              body:'action=file_write&name='+encodeURIComponent(curName)+'&rel='+encodeURIComponent(curRel)+'&enc='+encodeURIComponent(curEnc)+'&content='+encodeURIComponent(cm.getValue())})
              .then(function(r){ return r.json(); })
              .then(function(j){ status.textContent = j.error ? j.error : 'Guardado.'; })
              .catch(function(){ status.textContent = 'Error de red al guardar.'; });
          }
          window.luaOpenFileEditor = function(name, rel, label){
            if (!modal) init();
            curName = name; curRel = rel; curEnc = 'UTF-8';
            titleEl.textContent = label || rel;
            cm.setOption('readOnly', 'nocursor');
            cm.setValue('Cargando…');
            status.textContent = '';
            modal.hidden = false;
            document.addEventListener('keydown', luaEscFileEditor);
            setTimeout(function(){ cm.refresh(); }, 0);
            fetch('?ajax=file_read&name='+encodeURIComponent(name)+'&rel='+encodeURIComponent(rel))
              .then(function(r){ return r.json(); })
              .then(function(j){
                cm.setOption('readOnly', false);
                if (j.error) { cm.setValue(''); status.textContent = j.error; }
                else {
                  curEnc = j.enc || 'UTF-8';
                  cm.setOption('mode', modeForFile(label || rel));
                  cm.setValue(j.content);
                  cm.clearHistory();
                  status.textContent = '';
                  cm.focus();
                }
              })
              .catch(function(){ cm.setOption('readOnly', false); status.textContent = 'Error de red al cargar.'; });
          };
          window.luaCloseFileEditor = function(){
            if (modal) modal.hidden = true;
            document.removeEventListener('keydown', luaEscFileEditor);
          };
          function luaEscFileEditor(e){ if (e.key === 'Escape') luaCloseFileEditor(); }
        })();
      </script>

    <?php endif; ?>


<?php endif; ?>
