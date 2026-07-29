  <?php if ($tab==='docker'): /* ---------- PESTAÑA DOCKER (solo si se detecta instalado) ---------- */
      $dockerUp = docker_running();
      $containers = $dockerUp ? docker_containers() : null; ?>

    <?php if (!$dockerUp): ?>
      <div class="card">
        <div style="font-weight:600;margin-bottom:6px">Docker Desktop no está arrancado</div>
        <div class="muted" style="margin-bottom:14px">Se detectó Docker instalado en esta máquina, pero el motor no responde ahora mismo. Arráncalo y recarga esta página en cuanto esté listo (puede tardar un minuto).</div>
        <form method="post">
          <input type="hidden" name="action" value="docker_start_desktop">
          <button class="btn" type="submit">Iniciar Docker Desktop</button>
        </form>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="row" style="margin-bottom:12px;gap:12px;flex-wrap:wrap">
          <h2 style="margin:0;font-size:15px">Contenedores</h2>
          <input type="text" id="dockerSearchInput" placeholder="Buscar contenedor&hellip;" autocomplete="off" spellcheck="false" style="width:220px">
          <div class="spacer"></div>
          <a class="btn ghost sm" href="?tab=docker">Refrescar</a>
        </div>
        <div id="dockerRows">
        <?php if ($containers === null): ?>
          <div class="muted">No se pudo listar los contenedores (&iquest;acaba de arrancar Docker? espera unos segundos y recarga).</div>
        <?php elseif (!$containers): ?>
          <div class="muted">No hay contenedores todav&iacute;a.</div>
        <?php else: foreach ($containers as $c): $up = stripos($c['status'],'Up ')===0; ?>
          <div class="dbrow" data-search="<?= e(strtolower($c['name'].' '.$c['image'])) ?>">
            <div>
              <div class="dbname"><?= e($c['name']) ?></div>
              <div class="muted" style="font-size:12px;max-width:480px;line-height:1.5;word-break:break-word"><?= e($c['image']) ?><?= $c['ports']!==''? ' &middot; '.e($c['ports']) : '' ?></div>
            </div>
            <div class="spacer"></div>
            <span class="jstate <?= $up?'ok':'err' ?>"><?= e($c['status']) ?></span>
            <div class="dbactions">
              <?php if ($up): ?>
                <form method="post"><input type="hidden" name="action" value="docker_container"><input type="hidden" name="op" value="restart"><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="btn ghost sm" type="submit">Reiniciar</button></form>
                <form method="post"><input type="hidden" name="action" value="docker_container"><input type="hidden" name="op" value="stop"><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="btn ghost sm" type="submit">Parar</button></form>
                <button type="button" class="btn ghost sm" onclick="luaOpenDockerTerm('<?= e($c['id']) ?>','<?= e(addslashes($c['name'])) ?>')">Terminal</button>
              <?php else: ?>
                <form method="post"><input type="hidden" name="action" value="docker_container"><input type="hidden" name="op" value="start"><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="btn ghost sm" type="submit">Arrancar</button></form>
              <?php endif; ?>
              <button type="button" class="btn danger sm" onclick="luaAskRmContainer('<?= e($c['id']) ?>','<?= e(addslashes($c['name'])) ?>')">Eliminar</button>
            </div>
          </div>
        <?php endforeach; endif; ?>
        </div>
      </div>
      <script>
        (function(){
          var inp=document.getElementById('dockerSearchInput');
          if(!inp) return;
          inp.addEventListener('input', function(){
            var q=inp.value.toLowerCase();
            Array.from(document.querySelectorAll('#dockerRows .dbrow')).forEach(function(row){
              row.style.display = (row.dataset.search||'').indexOf(q)===-1 ? 'none' : '';
            });
          });
        })();
      </script>

      <!-- Modal de confirmacion de eliminar contenedor -->
      <div id="rmContainerModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseRmContainer()">
        <div class="modal-box" role="dialog" aria-modal="true">
          <div class="modal-ic">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
              <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
          </div>
          <h3>&iquest;Eliminar el contenedor?</h3>
          <p class="modal-tx">Se eliminar&aacute; <strong id="rmContainerName"></strong> (forzado, aunque est&eacute; en marcha). Los datos que no est&eacute;n en un volumen se perder&aacute;n.</p>
          <form method="post" class="modal-actions">
            <input type="hidden" name="action" value="docker_container">
            <input type="hidden" name="op" value="rm">
            <input type="hidden" name="id" id="rmContainerId">
            <button type="button" class="btn ghost" onclick="luaCloseRmContainer()">Cancelar</button>
            <button type="submit" class="btn danger">S&iacute;, eliminar</button>
          </form>
        </div>
      </div>
      <script>
        function luaAskRmContainer(id,name){
          document.getElementById('rmContainerName').textContent = name;
          document.getElementById('rmContainerId').value = id;
          document.getElementById('rmContainerModal').hidden = false;
          document.addEventListener('keydown', luaEscRmContainer);
        }
        function luaCloseRmContainer(){
          document.getElementById('rmContainerModal').hidden = true;
          document.removeEventListener('keydown', luaEscRmContainer);
        }
        function luaEscRmContainer(e){ if(e.key==='Escape') luaCloseRmContainer(); }
      </script>

      <!-- Modal: terminal dentro de un contenedor (docker exec) -->
      <div id="dockerTermModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseDockerTerm()">
        <div class="modal-box" role="dialog" aria-modal="true" style="max-width:720px;text-align:left">
          <div class="row" style="margin-bottom:10px">
            <h3 id="dockerTermTitle" style="margin:0;font-size:16px">Terminal</h3>
            <div class="spacer"></div>
            <button type="button" class="btn ghost sm" id="dockerTermStop" disabled>Detener</button>
            <button type="button" class="lockbtn" id="dockerTermDockBtn" title="Fijar a la derecha" aria-label="Fijar a la derecha">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="15" y1="4" x2="15" y2="20"/></svg>
            </button>
            <button type="button" class="btn ghost sm" onclick="luaCloseDockerTerm()">Cerrar</button>
          </div>
          <div id="dockerTermOut" class="termout" style="height:320px;border:1px solid var(--line);border-radius:6px;background:var(--in)"></div>
          <div class="termin" style="margin-top:8px">
            <span class="termprompt">&gt;</span>
            <input type="text" id="dockerTermCmd" class="termcmd-input" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="comando dentro del contenedor, p.ej. ls -la">
          </div>
        </div>
      </div>
      <script>
        (function(){
          var modal=document.getElementById('dockerTermModal'), title=document.getElementById('dockerTermTitle'),
              out=document.getElementById('dockerTermOut'), inp=document.getElementById('dockerTermCmd'),
              stopBtn=document.getElementById('dockerTermStop'),
              dockBtn=document.getElementById('dockerTermDockBtn'), box=modal.querySelector('.modal-box');
          var sid=null, containerId=null, running=false, curRun=null;
          var DOCK_KEY='lua_dock_dockerterm';
          function setDocked(on){
            modal.classList.toggle('docked', on);
            box.classList.toggle('docked', on);
            dockBtn.classList.toggle('on', on);
            try{ localStorage.setItem(DOCK_KEY, on?'1':'0'); }catch(e){}
          }
          dockBtn.onclick=function(){ setDocked(!box.classList.contains('docked')); };
          var dockSaved='0'; try{ dockSaved=localStorage.getItem(DOCK_KEY)||'0'; }catch(e){}
          setDocked(dockSaved==='1');
          function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
          var ANSI={30:'k',31:'r',32:'g',33:'y',34:'b',35:'m',36:'c',37:'w',90:'K',91:'R',92:'G',93:'Y',94:'B',95:'M',96:'C',97:'W'};
          function ansiToHtml(s){
            var res='', open=false, cls='', bold=false;
            var re=/\x1b\[([0-9;]*)m/g, last=0, m;
            function span(t){ if(!t)return; if(open){res+='<span class="a-'+cls+(bold?' a-bold':'')+'">'+esc(t)+'</span>';} else {res+=esc(t);} }
            while((m=re.exec(s))!==null){
              span(s.slice(last,m.index)); last=re.lastIndex;
              var codes=m[1].split(';').filter(x=>x!=='').map(Number); if(codes.length===0)codes=[0];
              codes.forEach(function(c){ if(c===0){open=false;cls='';bold=false;} else if(c===1){bold=true;} else if(ANSI[c]){cls=ANSI[c];open=true;} });
            }
            span(s.slice(last));
            return res;
          }
          function append(html){ out.insertAdjacentHTML('beforeend', html); out.scrollTop=out.scrollHeight; }
          function poll(runid, off, fails){
            fails=fails||0;
            fetch('?action=term_poll&sid='+sid+'&runid='+runid+'&off='+off)
            .then(r=>r.json()).then(function(j){
              if(j.error){ append('<span class="a-r">'+esc(j.error)+'</span>\n'); finish(); return; }
              if(j.data){ append(ansiToHtml(j.data)); }
              if(j.done){
                if(out.textContent && !out.textContent.endsWith('\n')) append('\n');
                append('<span class="'+(j.code?'a-r':'a-g')+'">[salida '+(j.code||0)+']</span>\n');
                finish();
              } else { setTimeout(function(){ poll(runid, j.off, 0); }, 300); }
            }).catch(function(){
              if(fails>=5){ append('<span class="a-r">[error de red]</span>\n'); finish(); return; }
              setTimeout(function(){ poll(runid, off, fails+1); }, 500);
            });
          }
          function finish(){ running=false; curRun=null; stopBtn.disabled=true; inp.disabled=false; inp.focus(); }
          function run(cmd){
            running=true; inp.disabled=true; stopBtn.disabled=false;
            append('<span class="a-prompt">&gt; </span>'+esc(cmd)+'\n');
            fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
              body:'action=docker_term_run&sid='+sid+'&container='+encodeURIComponent(containerId)+'&cmd='+encodeURIComponent(cmd)})
            .then(r=>r.json()).then(function(j){
              if(j.error){ append('<span class="a-r">'+esc(j.error)+'</span>\n'); finish(); return; }
              curRun=j.runid; poll(j.runid, 0);
            }).catch(function(){ append('<span class="a-r">[no se pudo lanzar]</span>\n'); finish(); });
          }
          inp.addEventListener('keydown', function(e){
            if(e.key==='Enter'){
              var cmd=inp.value; if(!cmd.trim()||running) return;
              inp.value='';
              run(cmd);
            }
          });
          stopBtn.onclick=function(){
            if(!running||!curRun) return;
            fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
              body:'action=term_stop&sid='+sid+'&runid='+curRun}).then(function(){});
          };
          window.luaOpenDockerTerm=function(id, name){
            containerId=id;
            sid=(function(){var a=new Uint8Array(10);crypto.getRandomValues(a);return Array.from(a).map(b=>b.toString(16).padStart(2,'0')).join('');})();
            title.textContent='Terminal: '+name;
            out.innerHTML=''; running=false; curRun=null; stopBtn.disabled=true; inp.value=''; inp.disabled=false;
            modal.hidden=false;
            document.addEventListener('keydown', luaEscDockerTerm);
            inp.focus();
          };
          window.luaCloseDockerTerm=function(){
            if(running && curRun){ fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=term_stop&sid='+sid+'&runid='+curRun}).then(function(){}); }
            modal.hidden=true;
            document.removeEventListener('keydown', luaEscDockerTerm);
          };
          function luaEscDockerTerm(e){ if(e.key==='Escape') luaCloseDockerTerm(); }
        })();
      </script>
    <?php endif; ?>


<?php endif; ?>
