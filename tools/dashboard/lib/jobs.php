<?php
function project_locked($dir){
    if(!is_dir($dir)) return false;
    foreach(scandir($dir) as $f){
        if($f==='.'||$f==='..') continue;
        if(is_file($dir.'/'.$f) && strtolower(substr($f,-4))==='.lua') return true;
    }
    return false;
}
// Crea el marcador de bloqueo en un proyecto. Se usa al integrar/adoptar/registrar
// proyectos ya existentes (que no crea la plataforma desde cero): por defecto quedan
// bloqueados, para no borrar sin querer codigo real de otra parte.
function lock_project_dir($dir){
    if (!is_dir($dir)) return;
    @file_put_contents($dir.'/'.LUA_LOCK_MARKER, "; lua-server :: proyecto bloqueado\r\n; Mientras exista un archivo .lua en la raiz de este proyecto,\r\n; no se puede eliminar desde el panel (http://localhost).\r\n");
}
function read_jobs($dir){
    $out=[];
    if(is_dir($dir)){
        foreach(glob($dir.'/*.status') as $f){
            $j=json_decode(@file_get_contents($f),true);
            if($j){ $j['_mtime']=@filemtime($f); $out[]=$j; }
        }
        foreach(glob($dir.'/*.job') as $f){ // en cola (aun sin status)
            $b=basename($f,'.job'); $has=false;
            foreach($out as $o){ if(($o['id']??'')===$b){$has=true;break;} }
            if(!$has){ $out[]=['id'=>$b,'name'=>$b,'type'=>'?','state'=>'queued','msg'=>'En cola...','_mtime'=>@filemtime($f)]; }
        }
        usort($out, function($a,$b){ return ($b['_mtime']??0)-($a['_mtime']??0); });
    }
    return $out;
}
function job_log_tail($root,$id,$n=16){
    $f=$root.'/logs/jobs/'.$id.'.log';
    if(!is_file($f)) return '';
    $lines=@file($f,FILE_IGNORE_NEW_LINES); if(!$lines) return '';
    return implode("\n", array_slice($lines,-$n));
}
// Tarjeta de progreso reutilizada por los jobs de import (carpeta de dumps y archivo .sql
// unico): barra real si el job reporta 'pct' (ambos lo hacen mientras corre), log al final.
function render_import_job_card($root, $j){
    $st = $j['state'] ?? '?';
    $cls = ['done'=>'ok','error'=>'err','running'=>'run','queued'=>'warn'];
    $c = $cls[$st] ?? 'run';
    $tail = in_array($st, ['running','error','queued'], true) ? job_log_tail($root, $j['id'] ?? '') : '';
    $pct = isset($j['pct']) ? max(0, min(100, (int)$j['pct'])) : null;
    ob_start(); ?>
    <div class="card" style="padding:12px 16px;margin-top:12px">
      <div class="row">
        <span class="jstate <?= $c ?>"><?= e(strtoupper($st)) ?></span>
        <span style="font-weight:700"><?= e($j['dbname'] ?? $j['name'] ?? '') ?></span>
        <span class="muted"><?= isset($j['time']) ? e($j['time']) : '' ?></span>
        <div class="spacer"></div>
        <span class="muted"><?= e($j['msg'] ?? '') ?></span>
      </div>
      <?php if ($pct !== null && in_array($st, ['running','queued'], true)): ?>
        <div class="progressbar"><div class="progressbar-fill" style="width:<?= $pct ?>%"></div></div>
        <span class="progresspct"><?= $pct ?>%</span>
      <?php elseif ($st === 'error' && $pct !== null): ?>
        <div class="progressbar"><div class="progressbar-fill err" style="width:<?= $pct ?>%"></div></div>
      <?php endif; ?>
      <?php if ($tail): ?><pre class="joblog"><?= e($tail) ?></pre><?php endif; ?>
    </div>
    <?php return ob_get_clean();
}
// El watcher es un proceso PowerShell independiente (arrancado por 'lua.ps1 start'),
// no un hijo de Apache: se comprueba igual que hace lua.ps1 (pid en tmp/watch.pid + tasklist).
function watcher_alive($root){
    // Se mira el LATIDO (tmp\watch.beat, que el watcher toca en cada vuelta de su bucle) antes
    // que el PID. El PID no sirve de indicador fiable por dos motivos:
    //   1. Con "Arrancar con Windows" el watcher corre como SYSTEM, y desde aqui no se puede
    //      consultar ese proceso -> parecia muerto estando vivo.
    //   2. watch.pid guarda solo el del ULTIMO watcher que arranco. Con dos vivos (el de SYSTEM
    //      y el que lanza 'lua.ps1 start') el archivo deja de reflejar la realidad, y si el
    //      ultimo muere el badge decia "inactivo" mientras el otro seguia procesando jobs.
    // El latido no tiene ninguno de los dos problemas: lo escribe quien de verdad esta vivo.
    $bf = $root.'/tmp/watch.beat';
    if (is_file($bf)) {
        $beat = (int)trim((string)@file_get_contents($bf));
        // 15s de margen: el bucle late cada ~1s, pero una vuelta puede tardar si esta aplicando
        // cambios o reiniciando Apache, y no queremos parpadeos en el badge por eso.
        if ($beat > 0 && (time() - $beat) <= 15) return true;
    }
    // Compatibilidad con watchers arrancados ANTES de que existiera el latido (siguen con su
    // codigo viejo cargado en memoria y nunca escribiran watch.beat).
    $pf = $root.'/tmp/watch.pid';
    if (!is_file($pf)) return false;
    $pid = (int)trim((string)@file_get_contents($pf));
    if ($pid <= 0) return false;
    $out = [];
    @exec('tasklist /FI "PID eq '.$pid.'" 2>NUL', $out);
    foreach ($out as $line) { if (strpos($line, (string)$pid) !== false) return true; }
    return false;
}
// Consulta el estado real del arranque con Windows (servicio Apache + tarea del
// watcher), no un simple archivo de flag: llama a 'lua.ps1 startup-status' (solo
// lectura, no requiere admin).
function startup_enabled($root){
    $luaWin = str_replace('/', '\\', $root).'\\lua.ps1';
    $out = [];
    @exec('powershell -NoProfile -ExecutionPolicy Bypass -File "'.$luaWin.'" startup-status 2>NUL', $out);
    return trim((string)end($out)) === 'on';
}
function parse_overrides($file, $curatedKeys){
    $vals=[]; $extra=[];
    if(is_file($file)){
        foreach(file($file, FILE_IGNORE_NEW_LINES) as $ln){
            $t=trim($ln);
            if($t===''||$t[0]===';'||$t[0]==='#'){ continue; }
            if(preg_match('/^([a-zA-Z0-9_.]+)\s*=\s*(.*)$/',$t,$m) && in_array($m[1],$curatedKeys,true)){ $vals[$m[1]]=trim($m[2]); continue; }
            $extra[]=$ln;
        }
    }
    return [$vals,$extra];
}
