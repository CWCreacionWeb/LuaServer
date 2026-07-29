<?php
// ---------------- Terminal (sin PTY: ejecuta comandos, streamea su salida) ----------------
// Cada comando se lanza DESATENDIDO via COM(WScript.Shell) contra un .cmd generado,
// que redirige stdout+stderr a un .out. El panel hace polling del .out por offset.
// El cwd persiste entre comandos (el .cmd vuelca su directorio final a next.cwd).
function term_enabled($root){ return is_file($root.'/config/terminal.on'); }
// Apache corre como servicio (LocalSystem) desde que arrancó: si Composer/Node/etc. se
// instalan DESPUÉS, su PATH heredado se queda "congelado" sin esos directorios hasta
// reiniciar la máquina. Releemos el PATH de máquina en caliente desde el registro para que
// cada comando vea instalaciones nuevas sin depender de reiniciar el servicio.
// OJO: se hace vía COM (WScript.Shell), NUNCA con exec()/shell_exec() para esto — lanzar un
// subproceso propio (p.ej. powershell.exe) desde un worker de PHP bajo mod_fcgid en Windows
// puede colgar o matar ese worker (hereda los pipes del FastCGI); COM no lanza ningún proceso.
function term_fresh_machine_path(){
    try {
        $sh = new COM('WScript.Shell');
        $raw = $sh->RegRead('HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\Environment\Path');
        $expanded = trim((string)$sh->ExpandEnvironmentStrings($raw));
        if ($expanded === '') { return null; }
        // COM devuelve la cadena en el codepage ANSI del sistema (p.ej. Windows-1252 en
        // instalaciones en espanol), no en UTF-8. El wrapper .cmd hace "chcp 65001" antes de
        // esta linea, asi que si va sin convertir (p.ej. una ruta con "Vázquez"), el byte no-UTF8
        // rompe el parseo del resto del .cmd y todo el comando falla con un error de ruta.
        $utf8 = @mb_convert_encoding($expanded, 'UTF-8', 'Windows-1252');
        return ($utf8 !== false && $utf8 !== '') ? $utf8 : $expanded;
    } catch (Throwable $e) {
        return null;
    }
}
function term_valid_sid($s){ return (bool)preg_match('/^[a-f0-9]{8,40}$/', (string)$s); }
function term_dir($root,$sid){ return $root.'/tmp/terminal/'.$sid; }
function term_default_cwd($root){ $w=$root.'/www'; return str_replace('/', '\\', is_dir($w)?$w:$root); }
function term_get_cwd($root,$sid,$fallback=''){
    $f = term_dir($root,$sid).'/cwd';
    if (is_file($f)) { $c=trim((string)@file_get_contents($f)); if ($c!=='' && is_dir($c)) return $c; }
    // Sin cwd persistido todavia para esta sesion (primer comando): si nos pasaron un
    // directorio de partida valido (p.ej. el de un proyecto concreto), arrancamos ahi.
    if ($fallback!=='' && is_dir($fallback)) return str_replace('/', '\\', $fallback);
    return term_default_cwd($root);
}
function term_win($p){ return str_replace('/', '\\', $p); }

// Widget de terminal reutilizable: se instancia con un prefijo de IDs propio para poder
// incrustarlo tanto en la pestana Terminal (cwd = www) como en la ficha de un proyecto
// concreto (cwd = carpeta del proyecto), sin duplicar el marcado ni el JS.
function render_terminal_widget($prefix, $initialCwd, $autofocus=true){
    $cwdWin = str_replace('/', '\\', $initialCwd);
    ob_start(); ?>
    <div class="termwrap">
      <div class="termbar">
        <span class="muted" id="<?= e($prefix) ?>cwd">…</span>
        <div class="spacer"></div>
        <button class="btn ghost sm" id="<?= e($prefix) ?>stop" disabled>Detener</button>
        <button class="btn ghost sm" id="<?= e($prefix) ?>clear">Limpiar</button>
      </div>
      <div id="<?= e($prefix) ?>out" class="termout" aria-live="polite"></div>
      <div class="termin">
        <span class="termprompt">&gt;</span>
        <input id="<?= e($prefix) ?>cmd" class="termcmd-input" type="text" autocomplete="off" autocapitalize="off" spellcheck="false"
               placeholder="escribe un comando y pulsa Enter (p.ej. git status)" <?= $autofocus?'autofocus':'' ?>>
      </div>
    </div>
    <script>
    (function(){
      var PFX=<?= json_encode($prefix) ?>, INIT_CWD=<?= json_encode($cwdWin) ?>, AUTOFOCUS=<?= $autofocus?'true':'false' ?>;
      var out=document.getElementById(PFX+'out');
      var inp=document.getElementById(PFX+'cmd');
      var cwdEl=document.getElementById(PFX+'cwd');
      var stopBtn=document.getElementById(PFX+'stop');
      var clearBtn=document.getElementById(PFX+'clear');
      var sid=(function(){var a=new Uint8Array(10);crypto.getRandomValues(a);return Array.from(a).map(b=>b.toString(16).padStart(2,'0')).join('');})();
      var hist=[], hi=-1, running=false, curRun=null;

      // --- parser ANSI SGR basico -> spans con clase de color ---
      var ANSI={30:'k',31:'r',32:'g',33:'y',34:'b',35:'m',36:'c',37:'w',90:'K',91:'R',92:'G',93:'Y',94:'B',95:'M',96:'C',97:'W'};
      function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
      function ansiToHtml(s){
        var res='', open=false, cls='', bold=false;
        var re=/\x1b\[([0-9;]*)m/g, last=0, m;
        function span(t){ if(!t)return; if(open){res+='<span class="a-'+cls+(bold?' a-bold':'')+'">'+esc(t)+'</span>';} else {res+=esc(t);} }
        while((m=re.exec(s))!==null){
          span(s.slice(last,m.index)); last=re.lastIndex;
          var codes=m[1].split(';').filter(x=>x!=='').map(Number); if(codes.length===0)codes=[0];
          codes.forEach(function(c){
            if(c===0){open=false;cls='';bold=false;}
            else if(c===1){bold=true;}
            else if(ANSI[c]){cls=ANSI[c];open=true;}
          });
        }
        span(s.slice(last));
        return res;
      }
      function append(html){ out.insertAdjacentHTML('beforeend', html); out.scrollTop=out.scrollHeight; }
      function setCwd(c){ if(c){cwdEl.textContent=c;} }

      clearBtn.onclick=function(){ out.innerHTML=''; inp.focus(); };
      stopBtn.onclick=function(){
        if(!running||!curRun)return;
        fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body:'action=term_stop&sid='+sid+'&runid='+curRun}).then(()=>{});
      };

      function poll(runid, off, fails){
        fails = fails||0;
        fetch('?action=term_poll&sid='+sid+'&runid='+runid+'&off='+off)
        .then(r=>r.json()).then(function(j){
          if(j.error){ append('<span class="a-r">'+esc(j.error)+'</span>\n'); finish(); return; }
          if(j.data){ append(ansiToHtml(j.data)); }
          if(j.done){
            if(out.textContent && !out.textContent.endsWith('\n')) append('\n');
            if(j.code && j.code!==0){ append('<span class="a-r">[salida '+j.code+']</span>\n'); }
            if(j.cwd) setCwd(j.cwd);
            finish();
          } else {
            setTimeout(function(){ poll(runid, j.off, 0); }, 300);
          }
        }).catch(function(){
          // un poll suelto puede fallar (mod_fcgid saturado); reintentar antes de rendirse
          if(fails >= 5){ append('<span class="a-r">[error de red: se perdió la conexión con el comando]</span>\n'); finish(); return; }
          setTimeout(function(){ poll(runid, off, fails+1); }, 500);
        });
      }
      function finish(){ running=false; curRun=null; stopBtn.disabled=true; inp.disabled=false; inp.focus(); }

      function run(cmd){
        running=true; inp.disabled=true; stopBtn.disabled=false;
        append('<span class="a-prompt">'+esc(cwdEl.textContent)+'&gt; </span>'+esc(cmd)+'\n');
        var body='action=term_run&sid='+sid+'&cmd='+encodeURIComponent(cmd)+'&cwd0='+encodeURIComponent(INIT_CWD);
        fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
        .then(r=>r.json()).then(function(j){
          if(j.error){ append('<span class="a-r">'+esc(j.error)+'</span>\n'); finish(); return; }
          curRun=j.runid; if(j.cwd) setCwd(j.cwd);
          poll(j.runid, 0);
        }).catch(function(){ append('<span class="a-r">[no se pudo lanzar]</span>\n'); finish(); });
      }

      inp.addEventListener('keydown', function(e){
        if(e.key==='Enter'){
          var cmd=inp.value; if(!cmd.trim()||running) return;
          hist.push(cmd); hi=hist.length; inp.value='';
          if(cmd.trim()==='clear'||cmd.trim()==='cls'){ out.innerHTML=''; return; }
          run(cmd);
        } else if(e.key==='ArrowUp'){ if(hi>0){hi--; inp.value=hist[hi]; e.preventDefault();} }
        else if(e.key==='ArrowDown'){ if(hi<hist.length-1){hi++; inp.value=hist[hi];} else {hi=hist.length; inp.value='';} }
        else if(e.key==='l' && e.ctrlKey){ out.innerHTML=''; e.preventDefault(); }
      });
      cwdEl.textContent=INIT_CWD;
      if (AUTOFOCUS) inp.focus();
    })();
    </script>
    <?php
    return ob_get_clean();
}

