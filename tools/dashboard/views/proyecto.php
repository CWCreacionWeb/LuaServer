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
        $pWpRoot = wp_root_dir($pDir);
        $termOn = is_file($ROOT.'/config/terminal.on');
        $pGit = is_dir($pDir) ? git_info($pDir) : null;
        $pErrLog = tail_file($ROOT.'/logs/apache/'.$pName.'-error.log', 200);
        $pType = is_array($pInfo) ? ($pInfo['type'] ?? null) : null;
        $pTypeLabel = project_type_label($pType);
        $pExports = exports_list($ROOT, $pName);
        $pExportJobs = array_values(array_filter($jobs, function($j) use ($pName){ return ($j['type']??'')==='export_project' && ($j['name']??'')===$pName; }));
        $pExportJob = $pExportJobs[0] ?? null;
        // La BD anotada en sites.json (la que creo la plataforma con el proyecto) se preselecciona;
        // el resto del desplegable deja elegir cualquier otra, o ninguna.
        $pSiteDb = is_array($pInfo) ? (string)($pInfo['db'] ?? '') : '';
        $pMyDbs = mysql_databases() ?: [];
        $pPgDbs = pgsrv_databases() ?: [];
        // Sin anotacion en sites.json (proyectos integrados a mano) se preselecciona la BD que
        // se llama igual que el proyecto. Aqui adivinar por nombre es inofensivo -- es solo el
        // valor por defecto de un desplegable que el usuario ve y puede cambiar -- al contrario
        // que al ELIMINAR un proyecto, donde solo se toca la BD anotada (ver action 'delete').
        if ($pSiteDb === '' && in_array($pName, $pMyDbs, true)) { $pSiteDb = $pName; } ?>

      <div class="pgrid2">
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

      <div class="card">
        <div style="font-weight:600;margin-bottom:10px">Exportar proyecto</div>
        <form method="post" class="inline">
          <input type="hidden" name="action" value="export_project">
          <input type="hidden" name="name" value="<?= e($pName) ?>">
          <div style="flex:1;min-width:240px">
            <label>Excluir (coincide con parte de la ruta)</label>
            <input name="exclude" value=".git, node_modules, .idea" style="width:100%">
          </div>
          <div>
            <label>Base de datos a incluir</label>
            <select name="db" style="min-width:220px">
              <option value="">Ninguna (solo archivos)</option>
              <?php if ($pMyDbs): ?>
                <optgroup label="MySQL / MariaDB">
                  <?php foreach ($pMyDbs as $d): ?>
                    <option value="mysql:<?= e($d) ?>" <?= $d===$pSiteDb?'selected':'' ?>><?= e($d) ?><?= $d===$pSiteDb?' (la de este proyecto)':'' ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
              <?php if ($pPgDbs): ?>
                <optgroup label="PostgreSQL">
                  <?php foreach ($pPgDbs as $d): ?>
                    <option value="pgsql:<?= e($d) ?>"><?= e($d) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
            </select>
          </div>
          <button class="btn" type="submit" data-loading-text="Exportando…">Exportar</button>
        </form>
        <div class="muted" style="margin-top:10px;font-size:12px">Genera un <code>.zip</code> en <code>data\exports\</code> con la carpeta del proyecto y, si eliges una, el volcado <code>.sql</code> de su base de datos dentro. Lo hace el watcher en segundo plano.</div>
        <?php if ($pExportJob): ?>
          <?= render_import_job_card($ROOT, $pExportJob) ?>
        <?php endif; ?>
        <?php if ($pExports): ?>
          <div style="margin-top:14px">
            <?php foreach ($pExports as $ex): ?>
              <div class="row" style="gap:10px;padding:6px 0;border-bottom:1px solid var(--line)">
                <code style="font-size:12px"><?= e($ex['file']) ?></code>
                <span class="muted" style="font-size:12px"><?= e(export_size_human($ex['size'])) ?> &middot; <?= e(date('d/m/Y H:i', $ex['time'])) ?></span>
                <div class="spacer"></div>
                <a class="btn ghost sm" href="?export_zip=<?= e(rawurlencode($ex['file'])) ?>">Descargar</a>
                <form method="post" style="margin:0" onsubmit="return confirm('¿Eliminar el export <?= e($ex['file']) ?>?')">
                  <input type="hidden" name="action" value="export_delete">
                  <input type="hidden" name="name" value="<?= e($pName) ?>">
                  <input type="hidden" name="file" value="<?= e($ex['file']) ?>">
                  <button type="submit" class="btn danger sm">Eliminar</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      </div>

      <?php $pNotes = notes_read($ROOT, $pName); ?>
      <div class="card">
        <div class="row" style="margin-bottom:12px">
          <div style="font-weight:600">Notas</div>
          <?php if ($pNotes): ?><span class="muted" style="font-size:12px"><?= count($pNotes) ?> nota<?= count($pNotes)===1?'':'s' ?></span><?php endif; ?>
          <div class="spacer"></div>
          <span class="muted" style="font-size:12px">Accesos, avisos, lo que sea. Se guardan en <code>data\notes\</code>, fuera de git.</span>
        </div>
        <div class="pnwall">
          <!-- Post-it nuevo. Va el primero (no al final) para que anadir no obligue a bajar
               hasta el fondo del tablero cuando ya hay muchas notas. -->
          <form method="post" class="pnote pnew">
            <input type="hidden" name="action" value="note_add">
            <input type="hidden" name="name" value="<?= e($pName) ?>">
            <input class="pnote-title" name="title" maxlength="<?= NOTES_MAX_TITLE ?>" placeholder="Título de la nota" autocomplete="off">
            <textarea class="pnote-body" name="body" maxlength="<?= NOTES_MAX_BODY ?>" placeholder="Escribe aquí…&#10;usuario / contraseña, notas de despliegue…" spellcheck="false" autocomplete="off"></textarea>
            <div class="pnote-foot">
              <div class="pnote-dots">
                <?php foreach (notes_colors() as $ck=>$cl): ?>
                  <label title="<?= e($cl) ?>">
                    <input type="radio" name="color" value="<?= e($ck) ?>" <?= $ck==='amber'?'checked':'' ?>>
                    <span class="pnote-dot pnd-<?= e($ck) ?>"></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="pnote-acts" style="opacity:1">
                <button type="submit" class="pnote-act" title="Añadir nota" aria-label="Añadir nota">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </button>
              </div>
            </div>
          </form>

          <?php foreach ($pNotes as $n): ?>
            <form method="post" class="pnote pnote-<?= e($n['color']) ?>" id="noteF<?= e($n['id']) ?>">
              <input type="hidden" name="action" value="note_save">
              <input type="hidden" name="name" value="<?= e($pName) ?>">
              <input type="hidden" name="id" value="<?= e($n['id']) ?>">
              <input class="pnote-title" name="title" maxlength="<?= NOTES_MAX_TITLE ?>" value="<?= e($n['title']) ?>" placeholder="Sin título" autocomplete="off">
              <textarea class="pnote-body" name="body" maxlength="<?= NOTES_MAX_BODY ?>" placeholder="Vacía" spellcheck="false" autocomplete="off"><?= e($n['body']) ?></textarea>
              <div class="pnote-foot">
                <div class="pnote-dots">
                  <?php foreach (notes_colors() as $ck=>$cl): ?>
                    <label title="<?= e($cl) ?>">
                      <input type="radio" name="color" value="<?= e($ck) ?>" <?= $ck===$n['color']?'checked':'' ?>>
                      <span class="pnote-dot pnd-<?= e($ck) ?>"></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <span class="pnote-when"><?= e(notes_when($n)) ?></span>
                <div class="pnote-acts">
                  <button type="button" class="pnote-act lua-note-copy" title="Copiar el contenido" aria-label="Copiar el contenido">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                  </button>
                  <button type="submit" class="pnote-act" title="Guardar cambios" aria-label="Guardar cambios">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                  </button>
                  <!-- Borrar necesita su propio form (no se pueden anidar): vive fuera del
                       tablero y se enlaza por el atributo form=, mismo truco que el editor
                       de .env de mas abajo. -->
                  <button type="submit" form="noteD<?= e($n['id']) ?>" class="pnote-act del" title="Eliminar nota" aria-label="Eliminar nota"
                          onclick="return confirm('¿Eliminar esta nota? No se puede deshacer.')">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                  </button>
                </div>
              </div>
            </form>
          <?php endforeach; ?>
        </div>
        <?php foreach ($pNotes as $n): ?>
          <form method="post" id="noteD<?= e($n['id']) ?>" style="display:none">
            <input type="hidden" name="action" value="note_delete">
            <input type="hidden" name="name" value="<?= e($pName) ?>">
            <input type="hidden" name="id" value="<?= e($n['id']) ?>">
          </form>
        <?php endforeach; ?>
        <script>
          (function(){
            // Copiar: navigator.clipboard solo existe en contexto seguro, y el panel se sirve
            // por http -- en 127.0.0.1/localhost cuenta como seguro, pero en http://lua.test NO
            // (ver trampa nº2 de CLAUDE.md, que recomienda justo esa URL). De ahi el respaldo
            // con execCommand, que ahi sigue siendo la unica via.
            function copy(text, btn){
              var done = function(ok){
                var old = btn.getAttribute('title');
                btn.setAttribute('title', ok ? 'Copiado' : 'No se pudo copiar');
                btn.style.color = ok ? '' : 'var(--err)';
                setTimeout(function(){ btn.setAttribute('title', old); btn.style.color=''; }, 1400);
              };
              if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function(){ done(true); }, function(){ done(false); });
                return;
              }
              var ta = document.createElement('textarea');
              ta.value = text;
              ta.setAttribute('readonly','');
              ta.style.position='fixed'; ta.style.top='-1000px';
              document.body.appendChild(ta);
              ta.select();
              var ok = false;
              try { ok = document.execCommand('copy'); } catch(e){ ok = false; }
              document.body.removeChild(ta);
              done(ok);
            }
            document.addEventListener('click', function(ev){
              var btn = ev.target.closest ? ev.target.closest('.lua-note-copy') : null;
              if (!btn) return;
              var note = btn.closest('.pnote');
              if (!note) return;
              var body = note.querySelector('.pnote-body');
              copy(body ? body.value : '', btn);
            });
            // El alto del textarea se ajusta al contenido al cargar, para que una nota corta no
            // deje medio post-it vacio y una larga no obligue a hacer scroll dentro del papel.
            document.querySelectorAll('.pnote-body').forEach(function(ta){
              if (!ta.value) return;
              ta.style.height = 'auto';
              ta.style.height = Math.min(ta.scrollHeight + 2, 420) + 'px';
            });
          })();
        </script>
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

      <div class="pgrid2">
      <?php if ($pHasArtisan):
        $pEnvData = env_read_lines($pDir);
        $pEnvExample = is_file(env_example_path($pDir));
      ?>
      <div class="card">
        <div class="row" style="margin-bottom:10px">
          <div style="font-weight:600">Variables de entorno (.env)</div>
          <?php if ($pEnvData): ?><span class="muted" style="font-size:12px"><?= count(env_parse_rows($pEnvData['lines'])) ?> variables</span><?php endif; ?>
        </div>
        <?php if (!$pEnvData && !$pEnvExample): ?>
          <div class="muted">Este proyecto no tiene <code>.env</code> ni <code>.env.example</code>.</div>
        <?php elseif (!$pEnvData): ?>
          <div class="muted" style="margin-bottom:10px">No hay <code>.env</code> todavía, pero sí <code>.env.example</code>.</div>
          <form method="post">
            <input type="hidden" name="action" value="env_from_example">
            <input type="hidden" name="name" value="<?= e($pName) ?>">
            <button class="btn" type="submit">Crear .env desde .env.example</button>
          </form>
        <?php else: ?>
          <!-- Las filas KEY=VALOR viven fuera de <form id=envSaveForm> (no se pueden anidar
               formularios): cada input de valor se asocia al form por id via el atributo
               form=, y el mini-form de borrado de cada fila convive como hermano suyo. -->
          <form method="post" id="envSaveForm">
            <input type="hidden" name="action" value="env_save">
            <input type="hidden" name="name" value="<?= e($pName) ?>">
          </form>
          <div style="display:flex;flex-direction:column;gap:3px;max-height:50vh;overflow:auto;margin-bottom:14px">
            <?php foreach ($pEnvData['lines'] as $i => $line):
              $m = env_match_kv($line); ?>
              <?php if ($m): ?>
                <div class="row" style="gap:8px;flex-wrap:nowrap">
                  <label style="min-width:220px;max-width:220px;margin:0;font-family:ui-monospace,Consolas,monospace;font-size:12.5px;color:var(--tx);overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($m['key']) ?>"><?= e($m['key']) ?></label>
                  <input form="envSaveForm" name="val[<?= (int)$i ?>]" value="<?= e($m['value']) ?>" style="flex:1;font-family:ui-monospace,Consolas,monospace;font-size:12.5px">
                  <form method="post" style="margin:0" onsubmit="return confirm('¿Eliminar <?= e($m['key']) ?> de .env?')">
                    <input type="hidden" name="action" value="env_delete">
                    <input type="hidden" name="name" value="<?= e($pName) ?>">
                    <input type="hidden" name="line" value="<?= (int)$i ?>">
                    <button type="submit" class="trashbtn" title="Eliminar variable" aria-label="Eliminar variable">
                      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                    </button>
                  </form>
                </div>
              <?php elseif (trim($line) === ''): ?>
                <div style="height:6px"></div>
              <?php else: ?>
                <div class="muted" style="font-family:ui-monospace,Consolas,monospace;font-size:11.5px;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($line) ?></div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <button class="btn" type="submit" form="envSaveForm">Guardar cambios</button>
          <form method="post" class="row" style="gap:8px;margin-top:16px;padding-top:14px;border-top:1px solid var(--line)">
            <input type="hidden" name="action" value="env_add">
            <input type="hidden" name="name" value="<?= e($pName) ?>">
            <div style="flex:0 0 220px"><label>Nueva variable</label><input name="key" placeholder="NUEVA_VARIABLE" pattern="[A-Za-z_][A-Za-z0-9_]*" style="width:100%;font-family:ui-monospace,Consolas,monospace" required></div>
            <div style="flex:1"><label>Valor</label><input name="value" placeholder="valor" style="width:100%;font-family:ui-monospace,Consolas,monospace"></div>
            <button class="btn ghost sm" type="submit" style="margin-top:22px">Añadir variable</button>
          </form>
          <div class="muted" style="margin-top:10px;font-size:12px">Edita <code>.env</code> directamente en disco. Si la configuración de Laravel está cacheada (<code>artisan config:cache</code>), regénerala para que estos cambios surtan efecto.</div>
        <?php endif; ?>
      </div>

      <?php elseif ($pWpRoot !== null):
        $pWpCfgFile = wp_config_file($pWpRoot);
        $pWpHasConfig = is_file($pWpCfgFile);
        $pWpContent = $pWpHasConfig ? (string)@file_get_contents($pWpCfgFile) : '';
        $pWpLog = tail_file(wp_debug_log_path($pWpRoot), 200);
      ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:10px">WordPress</div>
        <?php if (!$pWpHasConfig): ?>
          <div class="muted">No se encontró <code>wp-config.php</code> en este proyecto (solo hay <code>wp-config-sample.php</code>: complétalo a mano con los datos de la base de datos para poder gestionar estas opciones).</div>
        <?php else: ?>
          <div class="muted" style="margin-bottom:8px;font-size:12px">Depuración (constantes de <code>wp-config.php</code>) &middot; clic para activar/desactivar</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px">
            <?php foreach (wp_debug_constants() as $k=>$label):
              $val = wp_config_get_bool($pWpContent, $k);
              $custom = !is_bool($val) && $val !== null;
              $on = $val === true; ?>
              <?php if ($custom): ?>
                <span class="jstate warn" title="<?= e($label) ?> — valor personalizado en wp-config.php (<?= e($val) ?>): no se toca desde aquí"><?= e($k) ?>: personalizado</span>
              <?php else: ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="wp_debug_toggle">
                  <input type="hidden" name="name" value="<?= e($pName) ?>">
                  <input type="hidden" name="key" value="<?= e($k) ?>">
                  <input type="hidden" name="enable" value="<?= $on?'0':'1' ?>">
                  <button type="submit" class="jstate <?= $on?'ok':'warn' ?>" title="<?= e($label) ?> — clic para <?= $on?'desactivar':'activar' ?>"><?= e($k) ?></button>
                </form>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <?php if ($pWpLog !== ''): ?>
            <div class="row" style="margin-bottom:8px">
              <span class="muted" style="font-size:12px">wp-content/debug.log</span>
              <div class="spacer"></div>
              <form method="post" onsubmit="return confirm('¿Vaciar debug.log de WordPress?')">
                <input type="hidden" name="action" value="wp_debug_log_clear">
                <input type="hidden" name="name" value="<?= e($pName) ?>">
                <button type="submit" class="btn ghost sm">Vaciar</button>
              </form>
            </div>
            <pre class="logview"><?= highlight_error_log($pWpLog) ?></pre>
          <?php elseif (wp_config_get_bool($pWpContent, 'WP_DEBUG_LOG') === true): ?>
            <div class="muted" style="font-size:12px">WP_DEBUG_LOG está activado, pero <code>debug.log</code> todavía está vacío o no existe.</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php $pTermOn = term_enabled($ROOT); $pLeftCard = $pHasArtisan || $pWpRoot !== null; ?>
      <?php if ($pTermOn && is_dir($pDir)): ?>
        <div class="card"<?= $pLeftCard ? '' : ' style="grid-column:1/-1"' ?>>
          <div style="font-weight:600;margin-bottom:10px">Terminal <span class="muted" style="font-weight:400;font-size:12px">— arranca ya en la carpeta de este proyecto</span></div>
          <?= render_terminal_widget('pterm', $pDir, false) ?>
        </div>
      <?php elseif (!$pTermOn): ?>
        <div class="card"<?= $pLeftCard ? '' : ' style="grid-column:1/-1"' ?>>
          <div style="font-weight:600;margin-bottom:6px">Terminal</div>
          <div class="muted">Actívala en <a href="?tab=config">Configuración del servidor</a> para ejecutar comandos aquí mismo, arrancando directamente en la carpeta de este proyecto.</div>
        </div>
      <?php endif; ?>
      </div>

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
