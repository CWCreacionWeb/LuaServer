  <?php if ($tab==='bd'): /* ---------- PESTAÑA BASES DE DATOS ---------- */
      $mariaOn = is_file($ROOT.'/config/mariadb.on');
      $pgOn    = is_file($ROOT.'/config/postgres.on');
      $mongoOn = is_file($ROOT.'/config/mongodb.on');
      $rootHasPass = mysql_root_pass($ROOT) !== '';
      $mysqlUsers = $mariaOn ? mysql_users() : null;
      $mysqlScopePdo = $mysqlUsers ? (function(){ try { return mysql_pdo(); } catch (Throwable $e) { return null; } })() : null;
      // Se calcula una vez por usuario (SHOW GRANTS) y se reutiliza tanto en la fila de la
      // tabla como en su modal de edicion -- evita repetir esa consulta por usuario.
      $muRows = [];
      foreach ($mysqlUsers ?: [] as $u) {
          $isRoot = strcasecmp($u['user'],'root') === 0;
          $scope = ($mysqlScopePdo && !$isRoot) ? mysql_user_scope($mysqlScopePdo, $u['user'], $u['host']) : null;
          $muRows[] = ['u'=>$u, 'isRoot'=>$isRoot, 'scope'=>$scope, 'rowId'=>'mu_'.md5($u['user'].'@'.$u['host'])];
      }
      // Motor mostrado: ?engine=pg|mysql. Por defecto MySQL, salvo que solo Postgres este activo.
      $reqEngine = $_GET['engine'] ?? '';
      $dbEngine = $reqEngine==='pg' ? 'pg' : ($reqEngine==='mysql' ? 'mysql' : (($pgOn && !$mariaOn) ? 'pg' : 'mysql')); ?>

    <div class="row" style="gap:8px;margin-bottom:16px">
      <a class="btn <?= $dbEngine==='mysql'?'':'ghost' ?> sm" href="?tab=bd&engine=mysql">MySQL / MariaDB<?= $mariaOn?'':' · inactivo' ?></a>
      <a class="btn <?= $dbEngine==='pg'?'':'ghost' ?> sm" href="?tab=bd&engine=pg">PostgreSQL<?= $pgOn?'':' · inactivo' ?></a>
      <?php if ($mongoOn): ?>
        <a class="btn ghost sm" href="http://127.0.0.1:8081/" target="_blank" title="MongoDB no usa SQL, así que no tiene un listado de bases de datos aquí: gestiónalo desde mongo-express.">MongoDB (mongo-express &#8599;)</a>
      <?php else: ?>
        <a class="btn ghost sm" href="?tab=config">MongoDB · inactivo</a>
      <?php endif; ?>
      <?php
        // Redis no tiene flag de encendido que mirar (no gestionamos el motor): lo que cuenta es
        // si hay alguna conexion guardada, igual que en SQL Server.
        $rdConns = redis_servers($ROOT);
      ?>
      <a class="btn ghost sm" href="?tab=redis" title="Redis tampoco usa SQL: se gestiona en su propia pestaña Redis (explorador de claves, consola y estado del servidor).">Redis<?= $rdConns ? ' ('.count($rdConns).')' : ' · sin conexiones' ?></a>
      <a class="btn ghost sm" href="?tab=sqlsrv">SQL Server</a>
    </div>

    <?php if ($dbEngine==='pg'): /* ===== PostgreSQL ===== */ ?>

      <?php if (!$pgOn): ?>
        <div class="card">
          <div style="font-weight:600;margin-bottom:6px">PostgreSQL está desactivado</div>
          <div class="muted">Actívalo desde <a href="?tab=config">Configuración del servidor</a> para gestionar bases de datos y roles. Se sirve en <code>127.0.0.1:5432</code>, usuario <code>postgres</code>, sin contraseña.</div>
        </div>
      <?php else:
          $pgReady = extension_loaded('pdo_pgsql');
          $pgDbList = $pgReady ? pgsrv_databases() : null;
          $pgRoles  = $pgReady ? pgsrv_roles() : null; ?>

        <div class="card row">
          <div>
            <div style="font-weight:600">Herramientas de administración</div>
            <div class="muted">Adminer (ya integrado) habla PostgreSQL de forma nativa: gestiona tablas, datos y consultas de forma visual.</div>
          </div>
          <div class="spacer"></div>
          <a class="btn ghost" href="/adminer.php?pgsql=127.0.0.1&username=postgres&db=postgres" target="_blank">Adminer &#8599;</a>
        </div>

        <?php if (!$pgReady): ?>
          <div class="card muted">La extensión <code>pdo_pgsql</code> de PHP aún no está activa. Se habilita al activar PostgreSQL por primera vez (o al reiniciar el servidor). Recarga en unos segundos.</div>
        <?php endif; ?>

        <div class="card">
          <div class="row" style="margin-bottom:12px">
            <h2 style="margin:0;font-size:15px">Bases de datos</h2>
            <div class="spacer"></div>
            <form method="post" class="row" style="gap:6px">
              <input type="hidden" name="action" value="pg_db_create">
              <input name="dbname" placeholder="nombre_basedatos" pattern="[a-zA-Z_][a-zA-Z0-9_]{0,62}" style="width:200px" required>
              <button class="btn ghost sm" type="submit">+ Crear BD</button>
            </form>
          </div>
          <?php if ($pgDbList === null): ?>
            <div class="muted">No se pudo conectar con PostgreSQL (¿acaba de activarse? espera unos segundos y recarga).</div>
          <?php elseif (!$pgDbList): ?>
            <div class="muted">No hay bases de datos todavía. Crea la primera arriba.</div>
          <?php else: foreach ($pgDbList as $db): ?>
            <div class="dbrow">
              <div class="dbname"><?= e($db) ?></div>
              <div class="spacer"></div>
              <div class="dbactions">
                <a class="btn ghost sm no-loader" href="?export_pg=<?= e(rawurlencode($db)) ?>">Exportar</a>
                <form method="post" enctype="multipart/form-data" class="dbimport row" style="gap:6px" onsubmit="return luaAskImportPg(event, this, '<?= e($db) ?>')">
                  <input type="hidden" name="action" value="pg_db_import">
                  <input type="hidden" name="dbname" value="<?= e($db) ?>">
                  <label class="filepick">
                    <input type="file" name="sqlfile" accept=".sql" required onchange="luaFilePickName(this)">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span class="filepick-name">Elegir .sql&hellip;</span>
                  </label>
                  <button class="btn ghost sm" type="submit">Importar</button>
                </form>
                <button type="button" class="btn danger sm" onclick="luaAskDropPg('<?= e($db) ?>')">Eliminar</button>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>

        <div class="card">
          <div style="font-weight:600;margin-bottom:12px">Roles / usuarios</div>
          <form method="post" class="inline" style="margin-bottom:16px">
            <input type="hidden" name="action" value="pg_role_create">
            <div>
              <label>Rol</label>
              <input name="username" placeholder="app" pattern="[a-zA-Z_][a-zA-Z0-9_]{0,62}" required>
            </div>
            <div>
              <label>Contraseña</label>
              <input type="text" name="password" placeholder="contraseña" autocomplete="off" required>
            </div>
            <div>
              <label>Acceso a</label>
              <select name="scope" onchange="document.getElementById('pguserdbrow').style.display=(this.value==='db')?'block':'none'">
                <option value="db">Una base de datos… (dueño)</option>
                <option value="all">Puede crear sus propias BD</option>
              </select>
            </div>
            <div id="pguserdbrow">
              <label>Base de datos</label>
              <input name="dbname" placeholder="micliente" pattern="[a-zA-Z_][a-zA-Z0-9_]{0,62}">
            </div>
            <button class="btn" type="submit">+ Crear rol</button>
          </form>

          <?php if ($pgRoles === null): ?>
            <div class="muted">No se pudo conectar con PostgreSQL para listar roles (¿acaba de activarse? espera unos segundos y recarga).</div>
          <?php elseif (!$pgRoles): ?>
            <div class="muted">No hay roles todavía. Crea el primero arriba.</div>
          <?php else: foreach ($pgRoles as $r): ?>
            <div class="dbrow">
              <div class="dbname"><?= e($r['name']) ?><?php if($r['super']): ?> <span class="jstate warn">superusuario</span><?php elseif(!$r['login']): ?> <span class="muted">(sin login)</span><?php endif; ?></div>
              <div class="spacer"></div>
              <?php if (strcasecmp($r['name'],'postgres') !== 0): ?>
                <button type="button" class="btn danger sm" onclick="luaAskDeletePgRole('<?= e($r['name']) ?>')">Eliminar</button>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
          <div class="muted" style="margin-top:10px;font-size:12px">Estos credenciales hay que asignarlos a mano en el <code>.env</code>/config de cada proyecto. El superusuario <code>postgres</code> no lleva contraseña (solo accesible desde 127.0.0.1).</div>
        </div>

        <!-- Modal: borrar base de datos PostgreSQL -->
        <div id="delPgDbModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDropPg()">
          <div class="modal-box" role="dialog" aria-modal="true">
            <div class="modal-ic"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></div>
            <h3>¿Eliminar base de datos?</h3>
            <p class="modal-tx">Se borrará <strong id="delPgDbName"></strong> y <strong>todo su contenido</strong> de forma permanente. Esto no se puede deshacer.</p>
            <form method="post" class="modal-actions">
              <input type="hidden" name="action" value="pg_db_drop">
              <input type="hidden" name="dbname" id="delPgDbInput">
              <button type="button" class="btn ghost" onclick="luaCloseDropPg()">Cancelar</button>
              <button type="submit" class="btn danger">Sí, eliminar</button>
            </form>
          </div>
        </div>
        <!-- Modal: borrar rol PostgreSQL -->
        <div id="delPgRoleModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDeletePgRole()">
          <div class="modal-box" role="dialog" aria-modal="true">
            <div class="modal-ic"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></div>
            <h3>¿Eliminar rol?</h3>
            <p class="modal-tx">Se eliminará el rol <strong id="delPgRoleName"></strong> de PostgreSQL. Fallará si el rol es dueño de objetos (bases de datos, tablas…).</p>
            <form method="post" class="modal-actions">
              <input type="hidden" name="action" value="pg_role_delete">
              <input type="hidden" name="username" id="delPgRoleInput">
              <button type="button" class="btn ghost" onclick="luaCloseDeletePgRole()">Cancelar</button>
              <button type="submit" class="btn danger">Sí, eliminar</button>
            </form>
          </div>
        </div>
        <!-- Modal: importar backup PostgreSQL -->
        <div id="importPgModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseImportPg()">
          <div class="modal-box" role="dialog" aria-modal="true">
            <div class="modal-ic"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg></div>
            <h3>¿Importar backup?</h3>
            <p class="modal-tx">Se ejecutará el <code>.sql</code> en <strong id="importPgName"></strong>. Si incluye objetos con el mismo nombre, <strong>pueden sobrescribirse o dar error</strong>.</p>
            <div class="modal-actions">
              <button type="button" class="btn ghost" id="importPgCancelBtn" onclick="luaCloseImportPg()">Cancelar</button>
              <button type="button" class="btn danger" id="importPgConfirmBtn" onclick="luaConfirmImportPg()">Sí, importar</button>
            </div>
          </div>
        </div>
        <script>
          function luaAskDropPg(n){ document.getElementById('delPgDbName').textContent=n; document.getElementById('delPgDbInput').value=n; document.getElementById('delPgDbModal').hidden=false; document.addEventListener('keydown',luaEscDropPg); }
          function luaCloseDropPg(){ document.getElementById('delPgDbModal').hidden=true; document.removeEventListener('keydown',luaEscDropPg); }
          function luaEscDropPg(e){ if(e.key==='Escape') luaCloseDropPg(); }
          function luaAskDeletePgRole(n){ document.getElementById('delPgRoleName').textContent=n; document.getElementById('delPgRoleInput').value=n; document.getElementById('delPgRoleModal').hidden=false; document.addEventListener('keydown',luaEscDeletePgRole); }
          function luaCloseDeletePgRole(){ document.getElementById('delPgRoleModal').hidden=true; document.removeEventListener('keydown',luaEscDeletePgRole); }
          function luaEscDeletePgRole(e){ if(e.key==='Escape') luaCloseDeletePgRole(); }
          var luaImportPgForm=null;
          function luaAskImportPg(ev, form, db){ ev.preventDefault(); luaImportPgForm=form; document.getElementById('importPgName').textContent=db; document.getElementById('importPgModal').hidden=false; document.addEventListener('keydown',luaEscImportPg); return false; }
          function luaConfirmImportPg(){
            if (!luaImportPgForm) { luaCloseImportPg(); return; }
            var btn = document.getElementById('importPgConfirmBtn');
            document.getElementById('importPgCancelBtn').disabled = true;
            btn.disabled = true;
            btn.innerHTML = '<span class="btn-spin"></span>Importando&hellip;';
            document.removeEventListener('keydown', luaEscImportPg);
            luaImportPgForm.requestSubmit();
            setTimeout(function(){
              btn.disabled = false; btn.innerHTML = 'Sí, importar';
              document.getElementById('importPgCancelBtn').disabled = false;
              luaCloseImportPg();
            }, 20000);
          }
          function luaCloseImportPg(){ document.getElementById('importPgModal').hidden=true; document.removeEventListener('keydown',luaEscImportPg); }
          function luaEscImportPg(e){ if(e.key==='Escape') luaCloseImportPg(); }
        </script>

      <?php endif; /* $pgOn */ ?>

    <?php elseif (!$mariaOn): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:6px">MySQL (MariaDB) está desactivado</div>
        <div class="muted">Activa el servidor MySQL desde <a href="?tab=proyectos">Proyectos</a> o <a href="?tab=config">Configuración del servidor</a> para gestionar bases de datos, usuarios y la contraseña de <code>root</code>.</div>
      </div>
    <?php else: ?>

      <?php $dbList = mysql_databases();
      // Ultimo job de import de archivo por BD (read_jobs ya viene ordenado por mas reciente).
      $fileJobsByDb = [];
      foreach ($jobs as $jj) {
          if (($jj['type']??'')!=='db_import_file') continue;
          $jjDb = $jj['dbname'] ?? $jj['name'] ?? '';
          if ($jjDb !== '' && !isset($fileJobsByDb[$jjDb])) $fileJobsByDb[$jjDb] = $jj;
      }
      $charsets = mysql_charsets(); ?>
      <div class="pgrid2">
      <div class="card">
        <div style="font-weight:600">Importar carpeta de dumps</div>
        <div class="muted" style="margin-top:6px">Para exports con un <code>.sql</code> por tabla (en vez de un único dump completo): indica la carpeta en este servidor y la base de datos destino, y se importan todos en orden. Se ejecuta en segundo plano (puede tardar con carpetas grandes).</div>
        <form method="post" class="inline" style="margin-top:12px" onsubmit="return luaAskImportDir(event, this)">
          <input type="hidden" name="action" value="db_import_dir">
          <div>
            <label>Base de datos</label>
            <select name="dbname" required>
              <option value="" disabled selected>elige…</option>
              <?php foreach ($dbList ?: [] as $dbOpt): ?><option value="<?= e($dbOpt) ?>"><?= e($dbOpt) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div style="flex:1;min-width:280px">
            <label>Carpeta con los .sql</label>
            <div class="row" style="gap:6px">
              <input type="text" name="dir" id="dbImportDirInput" placeholder="C:\ruta\a\la\carpeta" required style="flex:1">
              <button type="button" class="btn ghost sm" id="dbImportDirPick" onclick="luaPickFolder(this,'dbImportDirInput')">Elegir…</button>
            </div>
          </div>
          <button class="btn" type="submit">Importar carpeta</button>
        </form>
        <?php $dirJobs = array_values(array_filter($jobs, function($j){ return ($j['type']??'')==='db_import_dir'; })); ?>
        <div id="dirJobsList" data-import-job="dir">
          <?php foreach (array_slice($dirJobs,0,5) as $j): echo render_import_job_card($ROOT, $j); endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div style="font-weight:600">Contraseña de root</div>
        <div class="muted" style="margin-top:6px">Actualmente: <b><?= $rootHasPass?'con contraseña':'sin contraseña' ?></b>. Cambia la contraseña con la que conecta el panel (<code>root@127.0.0.1</code>); déjala en blanco para quitarla.</div>
        <form method="post" class="inline" style="margin-top:12px">
          <input type="hidden" name="action" value="mysql_root_pass">
          <div>
            <label>Nueva contraseña</label>
            <input type="text" name="new_pass" placeholder="dejar vacío para quitarla" autocomplete="off">
          </div>
          <button class="btn" type="submit">Actualizar contraseña</button>
        </form>
      </div>
      </div>

      <div class="pgrid2">
      <div class="card">
        <div class="row" style="margin-bottom:12px">
          <h2 style="margin:0;font-size:15px">Bases de datos</h2>
          <div class="spacer"></div>
          <form method="post" class="row" style="gap:6px">
            <input type="hidden" name="action" value="db_create">
            <input name="dbname" placeholder="nombre_basedatos" pattern="[a-zA-Z0-9_]{1,64}" style="width:200px" required>
            <select name="charset" title="Codificación">
              <?php foreach ($charsets as $cs => $csLabel): ?><option value="<?= e($cs) ?>" <?= $cs==='utf8mb4'?'selected':'' ?>><?= e($csLabel) ?></option><?php endforeach; ?>
            </select>
            <button class="btn ghost sm" type="submit">+ Crear BD</button>
          </form>
          <a class="btn ghost sm" href="http://<?= e($phpmyadminDom) ?>/" target="_blank">phpMyAdmin &#8599;</a>
          <a class="btn ghost sm" href="/adminer.php?server=127.0.0.1&username=root" target="_blank">Adminer &#8599;</a>
        </div>
        <div class="dblist">
        <?php if ($dbList === null): ?>
          <div class="muted">No se pudo conectar con MySQL (¿acaba de activarse? espera unos segundos y recarga).</div>
        <?php elseif (!$dbList): ?>
          <div class="muted">No hay bases de datos todavía. Crea la primera arriba.</div>
        <?php else: foreach ($dbList as $db): ?>
          <div class="dbrow">
            <div class="dbname"><?= e($db) ?></div>
            <div class="spacer"></div>
            <div class="dbactions">
              <a class="btn ghost sm no-loader" href="?export_db=<?= e(rawurlencode($db)) ?>">Exportar</a>
              <form method="post" enctype="multipart/form-data" class="dbimport row" style="gap:6px" onsubmit="return luaAskImportDb(event, this, '<?= e($db) ?>')">
                <input type="hidden" name="action" value="db_import">
                <input type="hidden" name="dbname" value="<?= e($db) ?>">
                <label class="filepick">
                  <input type="file" name="sqlfile" accept=".sql" required onchange="luaFilePickName(this)">
                  <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                  <span class="filepick-name">Elegir .sql&hellip;</span>
                </label>
                <button class="btn ghost sm" type="submit">Importar</button>
              </form>
              <button type="button" class="btn danger sm" onclick="luaAskDropDb('<?= e($db) ?>')">Eliminar</button>
            </div>
          </div>
          <?php if (isset($fileJobsByDb[$db])): ?>
            <div style="margin:0 0 4px" id="fileJobCard-<?= e($db) ?>" data-import-job="file">
              <?= render_import_job_card($ROOT, $fileJobsByDb[$db]) ?>
            </div>
          <?php endif; ?>
        <?php endforeach; endif; ?>
        </div>
      </div>

      <div class="card">
        <div style="font-weight:600;margin-bottom:12px">Usuarios MySQL</div>

        <form method="post" class="inline" style="margin-bottom:16px">
          <input type="hidden" name="action" value="mysql_user_create">
          <div>
            <label>Usuario</label>
            <input name="username" placeholder="app" pattern="[a-zA-Z0-9_]{1,32}" required>
          </div>
          <div>
            <label>Contraseña</label>
            <input type="text" name="password" placeholder="contraseña" autocomplete="off" required>
          </div>
          <div>
            <label>Host</label>
            <select name="host">
              <option value="127.0.0.1">127.0.0.1</option>
              <option value="localhost">localhost</option>
              <option value="%">% (cualquier host)</option>
            </select>
          </div>
          <div>
            <label>Acceso a</label>
            <select name="scope" onchange="document.getElementById('userdbrow').style.display=(this.value==='db')?'block':'none'">
              <option value="db" selected>Una base de datos…</option>
              <option value="all">Todas las bases de datos</option>
            </select>
          </div>
          <div id="userdbrow">
            <label>Base de datos</label>
            <input name="dbname" placeholder="micliente" list="mysqlDbList">
            <datalist id="mysqlDbList">
              <?php foreach ($dbList ?: [] as $dbOpt): ?><option value="<?= e($dbOpt) ?>"><?php endforeach; ?>
            </datalist>
          </div>
          <button class="btn" type="submit">+ Crear usuario</button>
        </form>

        <div class="dblist">
        <?php if ($mysqlUsers === null): ?>
          <div class="muted">No se pudo conectar con MySQL para listar usuarios (¿acaba de activarse? espera unos segundos y recarga).</div>
        <?php elseif (!$mysqlUsers): ?>
          <div class="muted">No hay usuarios de aplicación todavía. Crea el primero arriba.</div>
        <?php else: ?>
          <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
              <tr>
                <th style="text-align:left;padding:6px 10px 8px 4px;font-size:11.5px;font-weight:600;color:var(--mut);border-bottom:1px solid var(--line)">Usuario</th>
                <th style="text-align:left;padding:6px 10px 8px;font-size:11.5px;font-weight:600;color:var(--mut);border-bottom:1px solid var(--line)">Bases de datos</th>
                <th style="border-bottom:1px solid var(--line)"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($muRows as $row): $u=$row['u']; $isRoot=$row['isRoot']; $scope=$row['scope']; $rowId=$row['rowId']; ?>
                <tr>
                  <td style="padding:9px 10px 9px 4px;font-family:ui-monospace,Consolas,monospace;font-weight:600;border-bottom:1px solid var(--line)"><?= e($u['user']) ?><span class="muted">@<?= e($u['host']) ?></span></td>
                  <td class="muted" style="padding:9px 10px;border-bottom:1px solid var(--line)">
                    <?php if ($isRoot): ?>todas las BD (superusuario)
                    <?php elseif ($scope === null): ?>&mdash;
                    <?php elseif ($scope['all']): ?>todas las BD
                    <?php elseif ($scope['dbs']): ?><?= e(implode(', ', $scope['dbs'])) ?>
                    <?php else: ?>sin acceso todavía
                    <?php endif; ?>
                  </td>
                  <td style="padding:9px 4px 9px 10px;text-align:right;white-space:nowrap;border-bottom:1px solid var(--line)">
                    <?php if (!$isRoot): ?>
                      <?php if ($scope !== null): ?>
                        <button type="button" class="btn ghost sm" onclick="document.getElementById('<?= $rowId ?>').hidden=false">Editar</button>
                      <?php endif; ?>
                      <button type="button" class="btn danger sm" onclick="luaAskDeleteMysqlUser('<?= e($u['user']) ?>','<?= e($u['host']) ?>')">Eliminar</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        </div>
        <div class="muted" style="margin-top:10px;font-size:12px">Estos credenciales hay que asignarlos a mano en el <code>.env</code>/config de cada proyecto.</div>
      </div>
      </div>

      <?php foreach ($muRows as $row):
        if ($row['isRoot'] || $row['scope'] === null) continue;
        $u = $row['u']; $scope = $row['scope']; $rowId = $row['rowId']; ?>
      <!-- Modal de edicion de acceso del usuario MySQL (una por usuario: los datos de cada uno
           -- chips de BD, formularios -- ya vienen renderizados desde PHP, así no hace falta
           JS para rellenar un modal compartido con el usuario que se acaba de pulsar). -->
      <div id="<?= $rowId ?>" class="modal-overlay" hidden onclick="if(event.target===this)this.hidden=true">
        <div class="modal-box" role="dialog" aria-modal="true" style="max-width:440px;text-align:left">
          <h3 style="margin:0 0 4px;font-size:15px">Editar usuario</h3>
          <div class="muted" style="font-size:12.5px;margin-bottom:16px;font-family:ui-monospace,Consolas,monospace"><?= e($u['user']) ?>@<?= e($u['host']) ?></div>

          <div style="font-weight:600;font-size:12.5px;margin-bottom:8px">Acceso a bases de datos</div>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;margin-bottom:14px">
            <?php if ($scope['all']): ?>
              <span class="tag">todas las BD</span>
              <form method="post" style="display:inline-flex">
                <input type="hidden" name="action" value="mysql_user_revoke_all">
                <input type="hidden" name="username" value="<?= e($u['user']) ?>">
                <input type="hidden" name="host" value="<?= e($u['host']) ?>">
                <button type="submit" class="btn ghost sm" onclick="return confirm('¿Quitar el acceso de &quot;<?= e($u['user']) ?>&quot; a TODAS las bases de datos?')">Restringir a BD concretas</button>
              </form>
            <?php else: ?>
              <?php if (!$scope['dbs']): ?><span class="muted">sin acceso a ninguna BD todavía</span><?php endif; ?>
              <?php foreach ($scope['dbs'] as $sdb): ?>
                <form method="post" style="display:inline-flex" onsubmit="return confirm('¿Quitar el acceso de &quot;<?= e($u['user']) ?>&quot; a &quot;<?= e($sdb) ?>&quot;?')">
                  <input type="hidden" name="action" value="mysql_user_revoke_db">
                  <input type="hidden" name="username" value="<?= e($u['user']) ?>">
                  <input type="hidden" name="host" value="<?= e($u['host']) ?>">
                  <input type="hidden" name="dbname" value="<?= e($sdb) ?>">
                  <button type="submit" class="tag" title="Quitar acceso a &quot;<?= e($sdb) ?>&quot;"><?= e($sdb) ?> &times;</button>
                </form>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <?php if (!$scope['all']): ?>
            <form method="post" class="row" style="gap:6px;margin-bottom:10px">
              <input type="hidden" name="action" value="mysql_user_grant_db">
              <input type="hidden" name="username" value="<?= e($u['user']) ?>">
              <input type="hidden" name="host" value="<?= e($u['host']) ?>">
              <input name="dbname" placeholder="+ base de datos" list="mysqlDbList" style="flex:1;min-width:0">
              <button type="submit" class="btn ghost sm">Añadir</button>
            </form>
            <form method="post" style="margin-bottom:20px">
              <input type="hidden" name="action" value="mysql_user_grant_all">
              <input type="hidden" name="username" value="<?= e($u['user']) ?>">
              <input type="hidden" name="host" value="<?= e($u['host']) ?>">
              <button type="submit" class="btn ghost sm" style="width:100%" onclick="return confirm('¿Dar acceso a &quot;<?= e($u['user']) ?>&quot; a TODAS las bases de datos?')">Dar acceso a todas las BD</button>
            </form>
          <?php endif; ?>

          <div style="font-weight:600;font-size:12.5px;margin-bottom:8px">Contraseña</div>
          <form method="post" class="row" style="gap:6px;margin-bottom:20px">
            <input type="hidden" name="action" value="mysql_user_password">
            <input type="hidden" name="username" value="<?= e($u['user']) ?>">
            <input type="hidden" name="host" value="<?= e($u['host']) ?>">
            <input type="text" name="password" placeholder="nueva contraseña" autocomplete="off" required style="flex:1;min-width:0">
            <button type="submit" class="btn ghost sm">Actualizar</button>
          </form>

          <div class="modal-actions">
            <button type="button" class="btn ghost" onclick="document.getElementById('<?= $rowId ?>').hidden=true">Cerrar</button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Modal de confirmacion de borrado de usuario MySQL -->
      <div id="delMysqlUserModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDeleteMysqlUser()">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="delMysqlUserTitle">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
              <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
          </div>
          <h3 id="delMysqlUserTitle">¿Eliminar usuario?</h3>
          <p class="modal-tx">Se eliminará el usuario <strong id="delMysqlUserName"></strong> de MySQL de forma permanente.</p>
          <form method="post" class="modal-actions">
            <input type="hidden" name="action" value="mysql_user_delete">
            <input type="hidden" name="username" id="delMysqlUserNameInput">
            <input type="hidden" name="host" id="delMysqlUserHostInput">
            <button type="button" class="btn ghost" onclick="luaCloseDeleteMysqlUser()">Cancelar</button>
            <button type="submit" class="btn danger">Sí, eliminar</button>
          </form>
        </div>
      </div>
      <script>
        function luaAskDeleteMysqlUser(user, host){
          document.getElementById('delMysqlUserName').textContent = user+'@'+host;
          document.getElementById('delMysqlUserNameInput').value = user;
          document.getElementById('delMysqlUserHostInput').value = host;
          document.getElementById('delMysqlUserModal').hidden = false;
          document.addEventListener('keydown', luaEscDeleteMysqlUser);
        }
        function luaCloseDeleteMysqlUser(){
          document.getElementById('delMysqlUserModal').hidden = true;
          document.removeEventListener('keydown', luaEscDeleteMysqlUser);
        }
        function luaEscDeleteMysqlUser(e){ if(e.key==='Escape') luaCloseDeleteMysqlUser(); }
      </script>

      <!-- Modal de confirmacion de borrado de base de datos -->
      <div id="delDbModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDropDb()">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="delDbTitle">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
              <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
          </div>
          <h3 id="delDbTitle">¿Eliminar base de datos?</h3>
          <p class="modal-tx">Se borrará <strong id="delDbName"></strong> y <strong>todas sus tablas</strong> de forma permanente. Esto no se puede deshacer.</p>
          <form method="post" class="modal-actions">
            <input type="hidden" name="action" value="db_drop">
            <input type="hidden" name="dbname" id="delDbNameInput">
            <button type="button" class="btn ghost" onclick="luaCloseDropDb()">Cancelar</button>
            <button type="submit" class="btn danger">Sí, eliminar</button>
          </form>
        </div>
      </div>
      <script>
        function luaAskDropDb(name){
          document.getElementById('delDbName').textContent = name;
          document.getElementById('delDbNameInput').value = name;
          document.getElementById('delDbModal').hidden = false;
          document.addEventListener('keydown', luaEscDropDb);
        }
        function luaCloseDropDb(){
          document.getElementById('delDbModal').hidden = true;
          document.removeEventListener('keydown', luaEscDropDb);
        }
        function luaEscDropDb(e){ if(e.key==='Escape') luaCloseDropDb(); }
      </script>

      <!-- Modal: selector de carpeta propio (arbol de directorios servido por PHP, ver
           ajax=browsedir) -- reemplaza al dialogo nativo de Windows, que fallaba cuando el
           watcher no tiene sesion de escritorio (p.ej. corriendo como tarea de SYSTEM con
           "Arrancar con Windows" activo). -->
      <div id="folderPickerModal" class="modal-overlay" hidden onclick="if(event.target===this)luaFolderPickerClose()">
        <div class="modal-box" role="dialog" aria-modal="true" style="max-width:460px;text-align:left">
          <h3 style="margin:0 0 12px;font-size:15px">Elegir carpeta</h3>
          <div class="row" style="gap:6px">
            <input type="text" id="folderPickerPath" placeholder="C:\ruta\a\la\carpeta" style="flex:1" onkeydown="if(event.key==='Enter'){event.preventDefault();luaFolderPickerGo();}">
            <button type="button" class="btn ghost sm" onclick="luaFolderPickerGo()">Ir</button>
          </div>
          <div class="folderlist" id="folderPickerList"></div>
          <div class="modal-actions" style="margin-top:16px;justify-content:space-between">
            <button type="button" class="btn ghost sm" id="folderPickerUp">&uarr; Subir</button>
            <div style="display:flex;gap:8px">
              <button type="button" class="btn ghost" onclick="luaFolderPickerClose()">Cancelar</button>
              <button type="button" class="btn" id="folderPickerUse">Usar esta carpeta</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal de confirmacion de importar backup (puede sobrescribir tablas existentes) -->
      <div id="importDbModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseImportDb()">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="importDbTitle">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            </svg>
          </div>
          <h3 id="importDbTitle">¿Importar backup?</h3>
          <p class="modal-tx">Se importará el archivo en <strong id="importDbName"></strong>. Si el <code>.sql</code> incluye tablas con el mismo nombre, <strong>se sobrescribirán</strong>.</p>
          <div class="modal-actions">
            <button type="button" class="btn ghost" id="importDbCancelBtn" onclick="luaCloseImportDb()">Cancelar</button>
            <button type="button" class="btn danger" id="importDbConfirmBtn" onclick="luaConfirmImportDb()">Sí, importar</button>
          </div>
        </div>
      </div>
      <script>
        var luaImportDbForm = null;
        // Sin este flag, requestSubmit() de mas abajo dispara el 'submit' del formulario de
        // verdad -- que vuelve a pasar por ESTE MISMO onsubmit (luaAskImportDb), que vuelve a
        // hacer preventDefault() porque nada distingue "primera vez, hay que confirmar" de
        // "ya confirmado, dejar pasar": el envio quedaba bloqueado para siempre y "Si,
        // importar" no llegaba a mandar nada al servidor (el modal solo se cerraba solo a los
        // 20s por la red de seguridad de abajo, dando la falsa impresion de que "ya iba").
        var luaImportDbConfirmed = false;
        function luaAskImportDb(ev, form, dbname){
          if (luaImportDbConfirmed) { luaImportDbConfirmed = false; return true; }
          ev.preventDefault();
          luaImportDbForm = form;
          document.getElementById('importDbName').textContent = dbname;
          document.getElementById('importDbModal').hidden = false;
          document.addEventListener('keydown', luaEscImportDb);
          return false;
        }
        function luaConfirmImportDb(){
          if (!luaImportDbForm) { luaCloseImportDb(); return; }
          var btn = document.getElementById('importDbConfirmBtn');
          document.getElementById('importDbCancelBtn').disabled = true;
          btn.disabled = true;
          btn.innerHTML = '<span class="btn-spin"></span>Importando&hellip;';
          document.removeEventListener('keydown', luaEscImportDb);
          // requestSubmit() (no submit()): dispara el evento 'submit' de verdad, para que
          // el loader global aparezca durante la importacion real (que puede tardar si el
          // .sql es grande) en vez de no mostrarse nunca. El modal se deja abierto (con el
          // boton en marcha) hasta que la navegacion real lo sustituya.
          luaImportDbConfirmed = true;
          luaImportDbForm.requestSubmit();
          // Red de seguridad: si la pagina no llega a navegar, no dejar el boton colgado.
          setTimeout(function(){
            btn.disabled = false; btn.innerHTML = 'Sí, importar';
            document.getElementById('importDbCancelBtn').disabled = false;
            luaCloseImportDb();
          }, 20000);
        }
        function luaCloseImportDb(){
          document.getElementById('importDbModal').hidden = true;
          document.removeEventListener('keydown', luaEscImportDb);
        }
        function luaEscImportDb(e){ if(e.key==='Escape') luaCloseImportDb(); }
      </script>

      <!-- Modal de confirmacion de importar carpeta de dumps (puede sobrescribir tablas existentes) -->
      <div id="importDirModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseImportDir()">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="importDirTitle">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            </svg>
          </div>
          <h3 id="importDirTitle">¿Importar carpeta?</h3>
          <p class="modal-tx">Se importarán todos los <code>.sql</code> de <code id="importDirPath"></code> en <strong id="importDirDb"></strong>, en segundo plano. Si incluyen tablas con el mismo nombre, <strong>se sobrescribirán</strong>.</p>
          <div class="modal-actions">
            <button type="button" class="btn ghost" id="importDirCancelBtn" onclick="luaCloseImportDir()">Cancelar</button>
            <button type="button" class="btn danger" id="importDirConfirmBtn" onclick="luaConfirmImportDir()">Sí, importar</button>
          </div>
        </div>
      </div>
      <script>
        var luaImportDirForm = null;
        // Mismo flag que luaImportDbConfirmed y por el mismo motivo: requestSubmit() de mas
        // abajo vuelve a pasar por este onsubmit, que sin el flag haria preventDefault() otra
        // vez y el envio no llegaria NUNCA al servidor -- "Si, importar" se quedaria en un
        // callejon sin salida silencioso (el modal se cerraba solo a los 20s como si nada).
        var luaImportDirConfirmed = false;
        function luaAskImportDir(ev, form){
          if (luaImportDirConfirmed) { luaImportDirConfirmed = false; return true; }
          var db = form.dbname.value, dir = form.dir.value;
          if (!db || !dir) return true; // deja que el 'required' nativo se encargue
          ev.preventDefault();
          luaImportDirForm = form;
          document.getElementById('importDirDb').textContent = db;
          document.getElementById('importDirPath').textContent = dir;
          document.getElementById('importDirModal').hidden = false;
          document.addEventListener('keydown', luaEscImportDir);
          return false;
        }
        function luaConfirmImportDir(){
          if (!luaImportDirForm) { luaCloseImportDir(); return; }
          var btn = document.getElementById('importDirConfirmBtn');
          document.getElementById('importDirCancelBtn').disabled = true;
          btn.disabled = true;
          btn.innerHTML = '<span class="btn-spin"></span>Importando&hellip;';
          document.removeEventListener('keydown', luaEscImportDir);
          luaImportDirConfirmed = true;
          luaImportDirForm.requestSubmit();
          setTimeout(function(){
            btn.disabled = false; btn.innerHTML = 'Sí, importar';
            document.getElementById('importDirCancelBtn').disabled = false;
            luaCloseImportDir();
          }, 20000);
        }
        function luaCloseImportDir(){
          document.getElementById('importDirModal').hidden = true;
          document.removeEventListener('keydown', luaEscImportDir);
        }
        function luaEscImportDir(e){ if(e.key==='Escape') luaCloseImportDir(); }
      </script>

      <!-- Progreso de los imports (carpeta o archivo unico) en vivo, sin recargar la pagina:
           antes esto era un <meta refresh> de la pagina entera cada 3s mientras hubiera un
           import en marcha (perdia el scroll y cualquier formulario a medio rellenar). Ahora
           se pide solo el HTML ya renderizado de las tarjetas afectadas (ver ajax=import_jobs,
           que reutiliza render_import_job_card) y se reemplaza en el sitio. Si la pagina se
           recarga a mano mientras tanto, el progreso servido en esa recarga ya sale correcto
           igual que siempre (vive en tmp/jobs/*.status, no en el estado de este script) y el
           polling retoma solo desde ahi. -->
      <script>
        (function(){
          var polling = null;
          function applyImportUpdate(data){
            Object.keys(data.fileCards).forEach(function(db){
              var el = document.getElementById('fileJobCard-' + db);
              // El contenedor solo existe si ya habia un import para esa BD al cargar la
              // pagina; si arranca uno nuevo para otra BD, se vera en la proxima recarga real
              // (el propio envio del formulario ya recarga la pagina).
              if (el) el.innerHTML = data.fileCards[db];
            });
            var dirList = document.getElementById('dirJobsList');
            if (dirList && data.dirHtml !== undefined) dirList.innerHTML = data.dirHtml;
          }
          function pollImportJobs(){
            fetch('?ajax=import_jobs').then(function(r){ return r.json(); }).then(function(data){
              applyImportUpdate(data);
              if (!data.anyRunning && polling) { clearInterval(polling); polling = null; }
            }).catch(function(){ /* se reintenta en el siguiente tick */ });
          }
          if (document.getElementById('dirJobsList')) {
            pollImportJobs();
            polling = setInterval(pollImportJobs, 1500);
          }
        })();
      </script>

    <?php endif; ?>


<?php endif; ?>
