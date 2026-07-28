  <?php if ($tab==='php'): /* ---------- PESTAÑA PHP ---------- */ ?>

    <h2>Editar php.ini por versión</h2>
    <div class="muted" style="margin-bottom:14px">Los cambios se guardan como <em>overrides</em> (sobreviven a actualizaciones) y se aplican recargando Apache automáticamente.</div>

    <?php if (!$vers): ?>
      <div class="card muted">No hay versiones de PHP instaladas.</div>
    <?php else: $openVer = $_GET['ver'] ?? ''; foreach ($vers as $v):
        [$vals,$extra] = parse_overrides("$OVR_DIR/$v.overrides.ini", array_keys($CURATED)); ?>
      <details <?= $v===$openVer?'open':'' ?>>
        <summary>PHP <?= e($v) ?> <span class="op">&mdash; config/php/<?= e($v) ?>.overrides.ini</span><span class="arrow"></span></summary>
        <div class="pane">
          <?php $xon = is_file($OVR_DIR.'/'.$v.'.xdebug.on'); $xdll = is_file($PHP_BASE.'/'.$v.'/ext/php_xdebug.dll'); $xactive=($xon&&$xdll); $xnourl=(empty($XDEBUG_URLS[$v]) && !$xdll); ?>
          <div class="row" style="margin-bottom:4px">
            <span style="font-weight:600">Xdebug</span>
            <span class="jstate <?= $xactive?'ok':'err' ?>"><?= $xactive?'ACTIVADO':'DESACTIVADO' ?></span>
            <?php if ($xon && !$xdll): ?><span class="muted">descargando DLL…</span><?php endif; ?>
            <div class="spacer"></div>
            <form method="post">
              <input type="hidden" name="action" value="xdebug">
              <input type="hidden" name="ver" value="<?= e($v) ?>">
              <input type="hidden" name="enable" value="<?= $xactive?'0':'1' ?>">
              <button class="btn <?= $xactive?'danger':'' ?>" <?= $xnourl?'disabled':'' ?>><?= $xactive?'Desactivar':'Activar' ?> Xdebug</button>
            </form>
          </div>
          <div class="muted" style="margin-bottom:16px;font-size:12px">Depuración paso a paso en el puerto <b>9003</b> (VS Code / PhpStorm)<?= $xnourl?' · <em>sin DLL disponible para esta versión</em>':'' ?>.</div>

          <?php $extraList = extra_extensions($ROOT); sort($extraList); ?>
          <div class="row" style="margin-bottom:4px">
            <span style="font-weight:600">Extensiones adicionales</span>
          </div>
          <?php if ($extraList): ?>
            <div style="margin-bottom:10px">
              <?php foreach ($extraList as $en): $edll = is_file($PHP_BASE.'/'.$v.'/ext/php_'.$en.'.dll'); ?>
                <div class="row" style="gap:8px;margin-bottom:4px">
                  <code><?= e($en) ?></code>
                  <span class="jstate <?= $edll?'ok':'err' ?>"><?= $edll?'INSTALADA':'NO INSTALADA' ?></span>
                  <div class="spacer"></div>
                  <?php if ($edll): ?><button type="button" class="btn danger sm" onclick="luaAskDelExt('<?= e($v) ?>','<?= e($en) ?>')">Quitar</button><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data" class="row" style="gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px">
            <input type="hidden" name="action" value="phpext_add">
            <input type="hidden" name="ver" value="<?= e($v) ?>">
            <div>
              <label>Nombre <span class="muted">(p.ej. pdo_sqlsrv)</span></label>
              <input name="name" placeholder="pdo_sqlsrv" pattern="[a-z][a-z0-9_]*" required>
            </div>
            <div>
              <label>URL directa al .dll <span class="muted">(opcional si subes archivo)</span></label>
              <input name="url" type="url" placeholder="https://…/php_xxx.dll" style="min-width:260px">
            </div>
            <div>
              <label>o sube el .dll</label>
              <div class="row" style="gap:6px">
                <input type="file" name="dll" id="dllInput-<?= e($v) ?>" accept=".dll" hidden onchange="document.getElementById('dllName-<?= e($v) ?>').textContent = this.files[0] ? this.files[0].name : 'Ningún archivo'">
                <button type="button" class="btn ghost sm" onclick="document.getElementById('dllInput-<?= e($v) ?>').click()">Elegir archivo</button>
                <span id="dllName-<?= e($v) ?>" class="muted" style="font-size:12px">Ningún archivo</span>
              </div>
            </div>
            <button class="btn sm" type="submit">Añadir extensión</button>
          </form>

          <form method="post">
            <input type="hidden" name="action" value="phpini">
            <input type="hidden" name="ver" value="<?= e($v) ?>">
            <div class="grid">
              <?php foreach ($CURATED as $k=>$meta): $cur = $vals[$k] ?? ''; ?>
                <div>
                  <label><?= e($meta['label']) ?> <span class="muted">(<?= e($k) ?>)</span></label>
                  <?php if ($meta['type']==='onoff'): ?>
                    <select name="ini[<?= e($k) ?>]" style="width:100%">
                      <option value="On"  <?= strcasecmp($cur,'On')===0?'selected':''  ?>>On</option>
                      <option value="Off" <?= strcasecmp($cur,'Off')===0?'selected':'' ?>>Off</option>
                    </select>
                  <?php elseif ($meta['type']==='select'):
                    $curNorm = preg_replace('/\s+/', '', $cur);
                    $known = false;
                    foreach ($meta['options'] as $optVal=>$optLabel) { if ($curNorm !== '' && preg_replace('/\s+/', '', $optVal) === $curNorm) { $known = true; break; } } ?>
                    <select name="ini[<?= e($k) ?>]" style="width:100%">
                      <option value="">(por defecto de PHP)</option>
                      <?php foreach ($meta['options'] as $optVal=>$optLabel):
                        $match = $curNorm !== '' && preg_replace('/\s+/', '', $optVal) === $curNorm; ?>
                        <option value="<?= e($optVal) ?>" <?= $match?'selected':'' ?>><?= e($optLabel) ?></option>
                      <?php endforeach; ?>
                      <?php if ($cur !== '' && !$known): ?>
                        <option value="<?= e($cur) ?>" selected>Personalizado: <?= e($cur) ?></option>
                      <?php endif; ?>
                    </select>
                  <?php else: ?>
                    <input name="ini[<?= e($k) ?>]" value="<?= e($cur) ?>" placeholder="<?= e($meta['ph']) ?>" style="width:100%">
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <div style="margin-top:14px">
              <label>Directivas adicionales (una por línea, formato <code>clave = valor</code>)</label>
              <textarea name="extra" placeholder="; ejemplo&#10;opcache.jit = 1255&#10;realpath_cache_size = 4096k"><?= e(implode("\n",$extra)) ?></textarea>
            </div>
            <div style="margin-top:14px"><button class="btn" type="submit">Guardar y aplicar</button></div>
          </form>
        </div>
      </details>
    <?php endforeach; endif; ?>

    <!-- Modal de confirmacion para quitar una extension PHP de terceros -->
    <div id="delExtModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDelExt()">
      <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="delExtTitle">
        <div class="modal-ic">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
        </div>
        <h3 id="delExtTitle">¿Quitar extensión?</h3>
        <p class="modal-tx">Se borrará <strong id="delExtName"></strong> de PHP <strong id="delExtVer"></strong> y se aplicarán los cambios.</p>
        <form method="post" class="modal-actions">
          <input type="hidden" name="action" value="phpext_remove">
          <input type="hidden" name="ver" id="delExtVerInput">
          <input type="hidden" name="name" id="delExtNameInput">
          <button type="button" class="btn ghost" onclick="luaCloseDelExt()">Cancelar</button>
          <button type="submit" class="btn danger">Sí, quitar</button>
        </form>
      </div>
    </div>
    <script>
      function luaAskDelExt(ver, name){
        document.getElementById('delExtName').textContent = name;
        document.getElementById('delExtVer').textContent = ver;
        document.getElementById('delExtVerInput').value = ver;
        document.getElementById('delExtNameInput').value = name;
        document.getElementById('delExtModal').hidden = false;
        document.addEventListener('keydown', luaEscDelExt);
      }
      function luaCloseDelExt(){
        document.getElementById('delExtModal').hidden = true;
        document.removeEventListener('keydown', luaEscDelExt);
      }
      function luaEscDelExt(e){ if(e.key==='Escape') luaCloseDelExt(); }
    </script>


<?php endif; ?>
