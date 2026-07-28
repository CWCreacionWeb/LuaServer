  <?php if ($tab==='sysmon'): /* ---------- PESTAÑA RECURSOS (WMI en vivo) ---------- */ ?>

    <div class="card row" style="flex-wrap:wrap;gap:8px;margin-bottom:14px">
      <div style="min-width:260px">
        <div style="font-weight:600">Recursos del sistema</div>
        <div class="muted" style="margin-top:4px">La máquina que aloja esta instalación — no solo lo que usa lua-server. Se actualiza solo cada <span id="sysPollTxt">2,5</span>s.</div>
      </div>
      <div class="spacer"></div>
      <span class="muted" id="sysUpdated" style="font-size:11.5px"></span>
    </div>

    <div class="sysgrid">
      <div class="card gaugecard">
        <div class="lbl">CPU</div>
        <div class="gaugewrap"><svg viewBox="0 0 132 132" width="132" height="132">
          <circle class="track" cx="66" cy="66" r="56"/>
          <circle class="fill" id="gCpuFill" cx="66" cy="66" r="56" stroke="var(--ac)"/>
        </svg><div class="gaugenum"><b id="gCpuNum">—</b><span>%</span></div></div>
        <div class="gaugesub" id="gCpuSub"></div>
        <svg class="spark" id="sCpu" viewBox="0 0 300 34" preserveAspectRatio="none"></svg>
      </div>

      <div class="card gaugecard">
        <div class="lbl">Memoria</div>
        <div class="gaugewrap"><svg viewBox="0 0 132 132" width="132" height="132">
          <circle class="track" cx="66" cy="66" r="56"/>
          <circle class="fill" id="gRamFill" cx="66" cy="66" r="56" stroke="var(--ac)"/>
        </svg><div class="gaugenum"><b id="gRamNum">—</b><span>%</span></div></div>
        <div class="gaugesub" id="gRamSub"></div>
        <svg class="spark" id="sRam" viewBox="0 0 300 34" preserveAspectRatio="none"></svg>
      </div>

      <div class="card gaugecard">
        <div class="lbl" id="gDiskLbl">Disco</div>
        <div class="gaugewrap"><svg viewBox="0 0 132 132" width="132" height="132">
          <circle class="track" cx="66" cy="66" r="56"/>
          <circle class="fill" id="gDiskFill" cx="66" cy="66" r="56" stroke="var(--ac)"/>
        </svg><div class="gaugenum"><b id="gDiskNum">—</b><span>%</span></div></div>
        <div class="gaugesub" id="gDiskSub"></div>
      </div>
    </div>

    <div class="pgrid2">
      <div class="card" style="margin-bottom:0">
        <div style="font-weight:600;margin-bottom:2px">Red (todas las interfaces)</div>
        <div class="muted" style="font-size:11.5px">Suma de todos los adaptadores, no solo el que usan tus proyectos.</div>
        <svg class="spark" id="sNet" viewBox="0 0 300 40" preserveAspectRatio="none" style="height:60px;margin-top:10px"></svg>
        <div class="netlegend">
          <span><i class="dot" style="background:var(--cat-1)"></i>Bajada <b id="netRx">—</b></span>
          <span><i class="dot" style="background:var(--cat-2)"></i>Subida <b id="netTx">—</b></span>
        </div>
      </div>

      <div class="card" style="margin-bottom:0">
        <div style="font-weight:600;margin-bottom:2px">Huella de lua-server</div>
        <div class="muted" style="font-size:11.5px;margin-bottom:10px">Memoria de los motores y el watcher que gestiona esta plataforma (no de tus proyectos PHP en sí).</div>
        <div class="procbars" id="procBars"><div class="sqlx-empty">cargando…</div></div>
      </div>
    </div>

    <div class="card sysfoot" id="sysFoot"></div>

    <script>
    (function(){
      var CAT = ['var(--cat-1)','var(--cat-2)','var(--cat-3)','var(--cat-4)','var(--cat-5)','var(--cat-6)','var(--cat-7)','var(--cat-8)'];
      var hist = { cpu: [], ram: [], rx: [], tx: [] };
      var MAXH = 90; // ~90 muestras a 2.5s = ~3.75 min de historial, reinicia al recargar la pagina
      var POLL_MS = 2500;

      // ---- gauge circular: stroke-dasharray sobre un circulo de r=56 (perimetro ~351.86) ----
      var CIRC = 2 * Math.PI * 56;
      function setGauge(fillEl, numEl, pct, statusVar) {
        if (pct === null || pct === undefined) { numEl.textContent = '—'; return; }
        pct = Math.max(0, Math.min(100, pct));
        var off = CIRC - (pct/100)*CIRC;
        fillEl.style.strokeDasharray = CIRC.toFixed(1);
        fillEl.style.strokeDashoffset = off.toFixed(1);
        fillEl.style.stroke = statusVar;
        numEl.textContent = (Math.round(pct*10)/10).toString().replace('.0','');
      }
      function statusColor(pct, warnAt, errAt) {
        if (pct >= errAt) return 'var(--err)';
        if (pct >= warnAt) return 'var(--warn)';
        return 'var(--ok)';
      }

      // ---- sparkline: polilinea + relleno hasta la base, escalada al maximo visto ----
      function drawSpark(svgEl, series, color) {
        var w = 300, h = svgEl.viewBox.baseVal.height || 34;
        if (!series.length) { svgEl.innerHTML = ''; return; }
        var max = Math.max.apply(null, series.concat([1])) * 1.15;
        var n = series.length;
        var pts = series.map(function(v,i){
          var x = n>1 ? (i/(n-1))*w : w;
          var y = h - (v/max)*h;
          return [x, isFinite(y)?y:h];
        });
        var line = pts.map(function(p,i){ return (i===0?'M':'L') + p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ');
        var area = line + ' L' + w + ',' + h + ' L0,' + h + ' Z';
        svgEl.innerHTML = '<path class="area" d="' + area + '" fill="' + color + '"></path>'
                         + '<path class="line" d="' + line + '" stroke="' + color + '"></path>';
      }

      function fmtKBs(v) { return v >= 1024 ? (v/1024).toFixed(1) + ' MB/s' : Math.round(v) + ' KB/s'; }
      function fmtUptime(s) {
        var d = Math.floor(s/86400), h = Math.floor((s%86400)/3600), m = Math.floor((s%3600)/60);
        if (d > 0) return d + 'd ' + h + 'h';
        if (h > 0) return h + 'h ' + m + 'm';
        return m + 'm';
      }

      function tick() {
        fetch('?ajax=sysmon').then(function(r){ return r.json(); }).then(function(j){
          if (!j.ok) { document.getElementById('sysFoot').textContent = j.error || 'Error leyendo WMI.'; return; }

          if (j.cpu.pct !== null) {
            hist.cpu.push(j.cpu.pct); if (hist.cpu.length > MAXH) hist.cpu.shift();
            setGauge(document.getElementById('gCpuFill'), document.getElementById('gCpuNum'), j.cpu.pct, statusColor(j.cpu.pct,60,85));
            drawSpark(document.getElementById('sCpu'), hist.cpu, 'var(--ac)');
          }
          document.getElementById('gCpuSub').textContent = hist.cpu.length ? 'media '+Math.round(hist.cpu.reduce(function(a,b){return a+b;},0)/hist.cpu.length)+'%' : 'calculando…';

          hist.ram.push(j.ram.pct); if (hist.ram.length > MAXH) hist.ram.shift();
          setGauge(document.getElementById('gRamFill'), document.getElementById('gRamNum'), j.ram.pct, statusColor(j.ram.pct,70,90));
          document.getElementById('gRamSub').textContent = j.ram.usedGB + ' / ' + j.ram.totalGB + ' GB';
          drawSpark(document.getElementById('sRam'), hist.ram, 'var(--ac)');

          var root = (j.disks||[]).filter(function(d){return d.root;})[0] || j.disks[0];
          if (root) {
            document.getElementById('gDiskLbl').textContent = 'Disco ' + root.drive;
            setGauge(document.getElementById('gDiskFill'), document.getElementById('gDiskNum'), root.pct, statusColor(root.pct,80,93));
            document.getElementById('gDiskSub').textContent = root.freeGB + ' GB libres de ' + root.totalGB + ' GB';
          }

          hist.rx.push(j.net.rxKBs); if (hist.rx.length > MAXH) hist.rx.shift();
          hist.tx.push(j.net.txKBs); if (hist.tx.length > MAXH) hist.tx.shift();
          document.getElementById('netRx').textContent = fmtKBs(j.net.rxKBs);
          document.getElementById('netTx').textContent = fmtKBs(j.net.txKBs);
          var maxNet = Math.max.apply(null, hist.rx.concat(hist.tx).concat([1]));
          var svgN = document.getElementById('sNet'), h = 40, w = 300;
          function path(series){
            var n = series.length; if (!n) return '';
            return series.map(function(v,i){ var x=n>1?(i/(n-1))*w:w; var y=h-(v/(maxNet*1.15))*h; return (i===0?'M':'L')+x.toFixed(1)+','+(isFinite(y)?y:h).toFixed(1); }).join(' ');
          }
          svgN.innerHTML = '<path class="line" d="'+path(hist.rx)+'" stroke="var(--cat-1)"></path>'
                          + '<path class="line" d="'+path(hist.tx)+'" stroke="var(--cat-2)"></path>';

          var maxMb = Math.max.apply(null, (j.procs||[]).map(function(p){return p.mb;}).concat([1]));
          var pb = document.getElementById('procBars');
          if (!j.procs || !j.procs.length) { pb.innerHTML = '<div class="sqlx-empty">Ningún motor propio corriendo ahora mismo.</div>'; }
          else {
            pb.innerHTML = j.procs.map(function(p){
              var w = Math.max(4, Math.round((p.mb/maxMb)*100));
              return '<div class="procbar-row"><div class="procbar-name">'+p.label+(p.count>1?' ×'+p.count:'')+'</div>'
                   + '<div class="procbar-track"><div class="procbar-fill" style="width:'+w+'%;background:var(--'+p.color+')"></div></div>'
                   + '<div class="procbar-val">'+p.mb+' MB</div></div>';
            }).join('');
          }

          var foot = [];
          if (j.uptimeSec !== undefined) { foot.push('Encendido desde hace <b>'+fmtUptime(j.uptimeSec)+'</b>'); }
          (j.disks||[]).forEach(function(d){ if (!d.root) foot.push('<code>'+d.drive+'</code> '+d.freeGB+'/'+d.totalGB+' GB libres'); });
          document.getElementById('sysFoot').innerHTML = foot.join(' &middot; ');
          document.getElementById('sysUpdated').textContent = 'actualizado ' + new Date(j.ts*1000).toLocaleTimeString();
        }).catch(function(){ document.getElementById('sysUpdated').textContent = 'sin respuesta'; });
      }
      tick();
      var timer = setInterval(tick, POLL_MS);
      // No seguir sondeando si el usuario se va a otra pestana del navegador (ahorra WMI de
      // sobra) ni si navega a otra pestana del panel (el script muere con la pagina, pero por
      // si acaso queda visible en el historial de vuelta atras del navegador).
      document.addEventListener('visibilitychange', function(){
        if (document.hidden) { clearInterval(timer); } else { timer = setInterval(tick, POLL_MS); tick(); }
      });
    })();
    </script>


<?php endif; ?>
