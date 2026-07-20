<?php
// ============================================================
//  lua-server :: panel de gestion (solo localhost)
//  - Proyectos: crear / eliminar / cambiar version de PHP
//  - PHP: editar overrides del php.ini de cada version
// ============================================================
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$ROOT     = dirname(__DIR__, 2);
$CFG_FILE = $ROOT . '/config/sites.json';
$PHP_BASE = $ROOT . '/bin/php';
$OVR_DIR  = $ROOT . '/config/php';
$WWW      = $ROOT . '/www';

$CURATED = [
  'date.timezone'       => ['label' => 'Zona horaria',            'type' => 'text',  'ph' => 'Europe/Madrid'],
  'memory_limit'        => ['label' => 'Límite de memoria',       'type' => 'text',  'ph' => '512M'],
  'upload_max_filesize' => ['label' => 'Tamaño máx. de subida',   'type' => 'text',  'ph' => '128M'],
  'post_max_size'       => ['label' => 'Tamaño máx. de POST',     'type' => 'text',  'ph' => '128M'],
  'max_execution_time'  => ['label' => 'Tiempo máx. ejecución (s)','type' => 'text', 'ph' => '120'],
  'max_input_vars'      => ['label' => 'Máx. variables de entrada','type' => 'text', 'ph' => '5000'],
  'display_errors'      => ['label' => 'Mostrar errores',         'type' => 'onoff', 'ph' => ''],
  'error_reporting'     => ['label' => 'Nivel de errores',        'type' => 'text',  'ph' => 'E_ALL'],
];

// URLs de las DLL de Xdebug por version de PHP (Windows NTS x64). Se rellenan tras verificar.
$XDEBUG_URLS = [
  '7.4' => 'https://xdebug.org/files/php_xdebug-3.1.6-7.4-vc15-x86_64.dll',
  '8.1' => 'https://xdebug.org/files/php_xdebug-3.5.3-8.1-nts-vs16-x86_64.dll',
  '8.2' => 'https://xdebug.org/files/php_xdebug-3.5.3-8.2-nts-vs16-x86_64.dll',
  '8.3' => 'https://xdebug.org/files/php_xdebug-3.5.3-8.3-nts-vs16-x86_64.dll',
  '8.4' => 'https://xdebug.org/files/php_xdebug-3.5.3-8.4-nts-vs17-x86_64.dll',
  '8.5' => 'https://xdebug.org/files/php_xdebug-3.5.3-8.5-nts-vs17-x86_64.dll',
];

function read_json($f){ $r=@file_get_contents($f); if($r===false)return null; $r=preg_replace('/^\xEF\xBB\xBF/','',$r); return json_decode($r,true); }
function write_json($f,$d){ file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); }
function valid_name($n){ return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/', $n); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function php_versions($base){
    $v=[]; if(is_dir($base)){ foreach(scandir($base) as $d){ if($d[0]==='.')continue; if(is_file("$base/$d/php-cgi.exe")) $v[]=$d; } } natsort($v); return array_values($v);
}
// El panel NO lanza procesos: solo deja un archivo-senal en tmp\ que el watcher
// (proceso independiente arrancado por 'lua.ps1 start') ejecuta en ~1 segundo.
function lua_flag($name){ @file_put_contents(dirname(__DIR__,2).'/tmp/'.$name.'.flag', (string)time()); }
function lua_apply(){ lua_flag('apply'); }
function lua_hosts(){ lua_flag('hosts'); }

function tail_file($f,$n=250){ if(!is_file($f)) return ''; $lines=@file($f,FILE_IGNORE_NEW_LINES); if($lines===false) return ''; return implode("\n",array_slice($lines,-$n)); }
function safe_logname($n){ return preg_match('/^[a-z0-9._-]+\.log$/i',$n) ? $n : ''; }
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

// ---------------- POST (patron PRG) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $cfg = read_json($CFG_FILE) ?: ['defaultPhp'=>'8.4','tld'=>'lua.test','sites'=>[]];
    if(!isset($cfg['sites'])||!is_array($cfg['sites'])) $cfg['sites']=[];
    $vers = php_versions($PHP_BASE);
    $tab='proyectos'; $msg='';

    if ($action === 'create') {
        $name = strtolower(trim($_POST['name'] ?? ''));
        $php  = $_POST['php'] ?? ($cfg['defaultPhp'] ?? '8.4');
        $type = $_POST['type'] ?? 'blank';
        $url  = trim($_POST['url'] ?? '');
        $validTypes = ['blank','laravel','wordpress','symfony','slim','git'];
        if (!valid_name($name)) { $msg='error:Nombre no válido (usa minúsculas, números, - o _).'; }
        elseif (isset($cfg['sites'][$name]) || is_dir("$WWW/$name")) { $msg='error:Ya existe un proyecto o carpeta "'.$name.'".'; }
        elseif (!in_array($type,$validTypes,true)) { $msg='error:Tipo de proyecto no válido.'; }
        elseif ($type==='git' && !preg_match('#^(https?://|git@)#',$url)) { $msg='error:Introduce una URL de Git válida.'; }
        elseif ($vers && !in_array($php,$vers,true)) { $msg='error:Versión de PHP no instalada.'; }
        else {
            $id = $name.'-'.time();
            $job = ['id'=>$id,'name'=>$name,'php'=>$php,'type'=>$type,'url'=>$url];
            @mkdir($ROOT.'/tmp/jobs', 0777, true);
            file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
            $labels=['blank'=>'PHP en blanco','laravel'=>'Laravel','wordpress'=>'WordPress','symfony'=>'Symfony','slim'=>'Slim','git'=>'clon de Git'];
            $msg='job:Creando "'.$name.'" ('.$labels[$type].')… mira el progreso abajo.';
        }
    }
    elseif ($action === 'clearjobs') {
        foreach (glob($ROOT.'/tmp/jobs/*.status') as $f) @unlink($f);
        $msg='info:Historial de tareas limpiado.';
    }
    elseif ($action === 'xdebug') {
        $ver = $_POST['ver'] ?? '';
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($vers && in_array($ver,$vers,true)) {
            $marker = $OVR_DIR.'/'.$ver.'.xdebug.on';
            $dll = $PHP_BASE.'/'.$ver.'/ext/php_xdebug.dll';
            if ($enable) {
                @mkdir($OVR_DIR,0777,true); @file_put_contents($marker,'1');
                if (is_file($dll)) { lua_apply(); $msg='applied:Xdebug activado en PHP '.$ver.'.'; }
                elseif (!empty($XDEBUG_URLS[$ver])) {
                    $id='xdebug-'.$ver.'-'.time();
                    $job=['id'=>$id,'name'=>'xdebug-'.$ver,'php'=>$ver,'type'=>'xdebug','url'=>$XDEBUG_URLS[$ver]];
                    @mkdir($ROOT.'/tmp/jobs',0777,true);
                    file_put_contents($ROOT.'/tmp/jobs/'.$id.'.job', json_encode($job));
                    $msg='job:Descargando Xdebug para PHP '.$ver.'…';
                } else { @unlink($marker); $msg='error:No hay URL de Xdebug configurada para PHP '.$ver.'.'; }
            } else { @unlink($marker); lua_apply(); $msg='applied:Xdebug desactivado en PHP '.$ver.'.'; }
        } else { $msg='error:Versión no válida.'; }
        header('Location: ?tab=php&ver='.urlencode($ver).'&msg='.urlencode($msg)); exit;
    }
    elseif ($action === 'clearlog') {
        $lf = safe_logname($_POST['log'] ?? '');
        if ($lf && is_file($ROOT.'/logs/apache/'.$lf)) { @file_put_contents($ROOT.'/logs/apache/'.$lf, ''); $msg='info:Log '.$lf.' vaciado.'; }
        $tab='logs';
        header('Location: ?tab=logs&log='.urlencode($lf)); exit;
    }
    elseif ($action === 'switch') {
        $name=$_POST['name']??''; $php=$_POST['php']??'';
        if (isset($cfg['sites'][$name]) && (!$vers || in_array($php,$vers,true))) {
            $cfg['sites'][$name]['php']=$php; write_json($CFG_FILE,$cfg); lua_apply();
            $msg='applied:"'.$name.'" ahora usa PHP '.$php.'.';
        } else { $msg='error:No se pudo cambiar la versión.'; }
    }
    elseif ($action === 'delete') {
        $name=$_POST['name']??'';
        if (isset($cfg['sites'][$name])) {
            unset($cfg['sites'][$name]); write_json($CFG_FILE,$cfg); lua_apply();
            $msg='applied:Proyecto "'.$name.'" eliminado (la carpeta www\\'.$name.' se conserva).';
        } else { $msg='error:No existe ese proyecto.'; }
    }
    elseif ($action === 'phpini') {
        $tab='php';
        $ver=$_POST['ver']??'';
        if ($vers && in_array($ver,$vers,true)) {
            $ini = $_POST['ini'] ?? [];
            $lines = ['; Ajustes editables desde el panel (http://localhost). Se aplican al final: ganan.'];
            foreach ($CURATED as $k=>$meta) {
                if (isset($ini[$k]) && trim($ini[$k])!=='') $lines[] = $k.' = '.trim($ini[$k]);
            }
            $extra = $_POST['extra'] ?? '';
            if (trim($extra)!=='') {
                $lines[]=''; $lines[]='; --- directivas adicionales ---';
                foreach (preg_split('/\r?\n/',$extra) as $el){ if(trim($el)!=='') $lines[]=rtrim($el); }
            }
            @mkdir($OVR_DIR,0777,true);
            file_put_contents("$OVR_DIR/$ver.overrides.ini", implode("\r\n",$lines)."\r\n");
            lua_apply();
            $msg='applied:php.ini de PHP '.$ver.' guardado.';
        } else { $msg='error:Versión no válida.'; }
    }
    elseif ($action === 'hosts') { lua_hosts(); $msg='info:Sincronizando dominios: acepta el aviso de Windows (UAC).'; }
    elseif ($action === 'https') {
        $enable = ($_POST['enable'] ?? '') === '1';
        if ($enable) { @file_put_contents($ROOT.'/tmp/https.flag',(string)time()); $msg='info:Activando HTTPS: acepta el aviso de Windows (UAC) para instalar la CA. Recarga en unos segundos.'; }
        else { @unlink($ROOT.'/config/https.on'); lua_apply(); $msg='applied:HTTPS desactivado.'; }
    }

    header('Location: ?tab='.$tab.(isset($ver)?'&ver='.urlencode($ver):'').'&msg='.urlencode($msg));
    exit;
}

// ---------------- GET (render) ----------------
$cfg = read_json($CFG_FILE) ?: ['defaultPhp'=>'8.4','tld'=>'lua.test','sites'=>[]];
$tld = $cfg['tld'] ?? 'lua.test';
$sites = $cfg['sites'] ?? [];
$defaultPhp = $cfg['defaultPhp'] ?? '8.4';
$vers = php_versions($PHP_BASE);
$tab = $_GET['tab'] ?? 'proyectos';
$msg = $_GET['msg'] ?? '';
[$mtype,$mtext] = array_pad(explode(':',$msg,2),2,'');
$curPhp = PHP_VERSION;
$jobs = read_jobs($ROOT.'/tmp/jobs');
$anyJobRun = false; foreach($jobs as $jj){ if(in_array(($jj['state']??''),['running','queued'],true)){$anyJobRun=true;break;} }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>lua-server</title>
<?php if ($mtype==='applied'): ?><script>setTimeout(function(){location.href='?tab=<?= e($tab) ?>';},4200);</script><?php endif; ?>
<?php if ($tab==='proyectos' && ($anyJobRun || $mtype==='job')): ?><meta http-equiv="refresh" content="3"><?php endif; ?>
<?php if ($tab==='logs' && (($_GET['refresh']??'')==='1')): ?><meta http-equiv="refresh" content="4"><?php endif; ?>
<style>
  :root{ --bg:#0f1117; --card:#1a1d27; --line:#2a2f3d; --tx:#e6e8ee; --mut:#8b90a0; --ac:#6ea8fe; --ok:#3fb950; --warn:#d29922; --err:#f85149; --in:#11141c; }
  @media (prefers-color-scheme:light){ :root{ --bg:#f4f6fb; --card:#fff; --line:#e3e7f0; --tx:#1a1d27; --mut:#5b6172; --ac:#2b6cff; --in:#fff; } }
  *{box-sizing:border-box} body{margin:0;font-family:system-ui,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--tx)}
  .wrap{max-width:900px;margin:0 auto;padding:34px 20px 60px}
  header{display:flex;align-items:center;gap:16px;margin-bottom:6px}
  .logo{width:50px;height:50px;border-radius:12px;background:linear-gradient(135deg,var(--ac),#9b6efe);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:19px;color:#fff;letter-spacing:1px}
  h1{margin:0;font-size:21px} .sub{color:var(--mut);font-size:13px;margin-top:2px}
  .tabs{display:flex;gap:6px;margin:22px 0 18px;border-bottom:1px solid var(--line)}
  .tabs a{padding:9px 16px;color:var(--mut);text-decoration:none;font-weight:600;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-1px}
  .tabs a.on{color:var(--ac);border-color:var(--ac)}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px 20px;margin-bottom:14px}
  .row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .row .name{font-weight:700;font-size:16px;min-width:150px}
  .row .url{color:var(--mut);font-size:13px;text-decoration:none} .row .url:hover{color:var(--ac)}
  .spacer{flex:1}
  label{display:block;font-size:12px;color:var(--mut);margin:0 0 4px}
  input,select,textarea{background:var(--in);color:var(--tx);border:1px solid var(--line);border-radius:9px;padding:8px 10px;font-size:14px;font-family:inherit}
  input:focus,select,textarea:focus{outline:none;border-color:var(--ac)}
  textarea{width:100%;min-height:90px;font-family:ui-monospace,Consolas,monospace;font-size:13px}
  .btn{background:var(--ac);color:#fff;border:none;border-radius:9px;padding:9px 16px;font-size:14px;font-weight:600;cursor:pointer}
  .btn:hover{filter:brightness(1.08)}
  .btn.ghost{background:transparent;border:1px solid var(--line);color:var(--tx)}
  .btn.danger{background:transparent;border:1px solid var(--line);color:var(--err)}
  .btn.danger:hover{background:var(--err);color:#fff;border-color:var(--err)}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px}
  .tag{display:inline-block;font-size:12px;color:var(--ac);background:rgba(110,168,254,.12);padding:2px 9px;border-radius:999px}
  .banner{padding:11px 15px;border-radius:10px;margin-bottom:16px;font-size:14px;border:1px solid}
  .banner.applied{background:rgba(63,185,80,.12);border-color:var(--ok);color:var(--ok)}
  .banner.info{background:rgba(110,168,254,.12);border-color:var(--ac);color:var(--ac)}
  .banner.error{background:rgba(248,81,73,.12);border-color:var(--err);color:var(--err)}
  .banner.job{background:rgba(210,153,34,.12);border-color:var(--warn);color:var(--warn)}
  .jstate{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;letter-spacing:.3px}
  .jstate.ok{background:rgba(63,185,80,.15);color:var(--ok)}
  .jstate.err{background:rgba(248,81,73,.15);color:var(--err)}
  .jstate.run{background:rgba(110,168,254,.15);color:var(--ac)}
  .joblog{background:var(--in);border:1px solid var(--line);border-radius:8px;padding:10px;margin:10px 0 0;font-family:ui-monospace,Consolas,monospace;font-size:12px;white-space:pre-wrap;max-height:180px;overflow:auto;color:var(--mut)}
  details{border:1px solid var(--line);border-radius:12px;margin-bottom:12px;background:var(--card)}
  summary{padding:14px 18px;cursor:pointer;font-weight:700;font-size:16px;list-style:none;display:flex;align-items:center;gap:10px}
  summary::-webkit-details-marker{display:none}
  summary .op{font-size:12px;color:var(--mut);font-weight:500}
  .pane{padding:4px 18px 18px}
  h2{font-size:13px;color:var(--mut);text-transform:uppercase;letter-spacing:.5px;margin:26px 0 12px}
  code{background:rgba(128,128,128,.16);padding:2px 6px;border-radius:6px;font-size:13px}
  .muted{color:var(--mut);font-size:13px}
  .inline{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap}
  footer{margin-top:34px;color:var(--mut);font-size:12px;text-align:center}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="logo">LUA</div>
    <div>
      <h1>lua-server</h1>
      <div class="sub">Servidor PHP local &middot; <?= count($sites) ?> proyecto(s) &middot; PHP: <?= e(implode(', ',$vers)) ?></div>
    </div>
  </header>

  <div class="tabs">
    <a href="?tab=proyectos" class="<?= $tab==='proyectos'?'on':'' ?>">Proyectos</a>
    <a href="?tab=php" class="<?= $tab==='php'?'on':'' ?>">Versiones PHP</a>
    <a href="?tab=logs" class="<?= $tab==='logs'?'on':'' ?>">Logs</a>
  </div>

  <?php if ($mtext): ?>
    <div class="banner <?= e($mtype) ?>">
      <?= e($mtext) ?>
      <?php if ($mtype==='applied'): ?> <span class="muted">— Apache se está recargando, la página se actualizará sola.</span><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab==='proyectos'): ?>

    <div class="card">
      <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="inline">
          <div>
            <label>Nombre del proyecto</label>
            <input name="name" placeholder="micliente" pattern="[a-z0-9][a-z0-9_-]*" required>
          </div>
          <div>
            <label>Tipo</label>
            <select name="type" onchange="document.getElementById('gitrow').style.display=(this.value==='git')?'block':'none'">
              <option value="blank">PHP en blanco</option>
              <option value="laravel">Laravel</option>
              <option value="wordpress">WordPress</option>
              <option value="symfony">Symfony</option>
              <option value="slim">Slim</option>
              <option value="git">Desde Git…</option>
            </select>
          </div>
          <div>
            <label>Versión de PHP</label>
            <select name="php">
              <?php foreach ($vers as $v): ?>
                <option value="<?= e($v) ?>" <?= $v===$defaultPhp?'selected':'' ?>>PHP <?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn" type="submit">+ Crear</button>
        </div>
        <div id="gitrow" style="display:none;margin-top:12px">
          <label>URL del repositorio Git</label>
          <input name="url" placeholder="https://github.com/usuario/repo.git" style="width:100%">
        </div>
      </form>
      <div class="muted" style="margin-top:10px">Laravel/Symfony/Slim usan Composer; WordPress se descarga; Git clona el repo (y ejecuta <code>composer install</code> si hay <code>composer.json</code>). Se hace en segundo plano.</div>
    </div>

    <?php if ($jobs): ?>
      <div class="row" style="margin:22px 0 10px">
        <h2 style="margin:0">Tareas</h2>
        <div class="spacer"></div>
        <form method="post"><input type="hidden" name="action" value="clearjobs"><button class="btn ghost" style="padding:5px 12px">Limpiar historial</button></form>
      </div>
      <?php foreach (array_slice($jobs,0,8) as $j):
            $st=$j['state']??'?'; $cls=['done'=>'ok','error'=>'err','running'=>'run','queued'=>'run'];
            $c=$cls[$st]??'run';
            $tail = in_array($st,['running','error','queued'],true) ? job_log_tail($ROOT, $j['id']??'') : ''; ?>
        <div class="card" style="padding:12px 16px">
          <div class="row">
            <span class="jstate <?= $c ?>"><?= e(strtoupper($st)) ?></span>
            <span style="font-weight:700"><?= e($j['name']??'') ?></span>
            <span class="muted"><?= e($j['type']??'') ?><?= isset($j['time'])?' · '.e($j['time']):'' ?></span>
            <div class="spacer"></div>
            <span class="muted"><?= e($j['msg']??'') ?></span>
          </div>
          <?php if ($tail): ?><pre class="joblog"><?= e($tail) ?></pre><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <h2>Proyectos</h2>
    <?php if (!$sites): ?>
      <div class="card muted">Aún no hay proyectos. Crea el primero arriba.</div>
    <?php else: foreach ($sites as $name => $info): $ver = is_array($info)?($info['php']??'?'):$info; ?>
      <div class="card row">
        <div class="name"><?= e($name) ?></div>
        <a class="url" href="http://<?= e($name) ?>.<?= e($tld) ?>" target="_blank"><?= e($name) ?>.<?= e($tld) ?> &#8599;</a>
        <div class="spacer"></div>
        <form method="post" class="inline" style="gap:6px">
          <input type="hidden" name="action" value="switch">
          <input type="hidden" name="name" value="<?= e($name) ?>">
          <select name="php" onchange="this.form.submit()">
            <?php foreach ($vers as $v): ?>
              <option value="<?= e($v) ?>" <?= $v===$ver?'selected':'' ?>>PHP <?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <form method="post" onsubmit="return confirm('¿Eliminar el proyecto <?= e($name) ?>? (la carpeta se conserva)')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="name" value="<?= e($name) ?>">
          <button class="btn danger" type="submit">Eliminar</button>
        </form>
      </div>
    <?php endforeach; endif; ?>

    <div class="card row">
      <div>
        <div style="font-weight:600">Dominios <code>.<?= e($tld) ?></code> en el navegador</div>
        <div class="muted">Para que <code>&lt;nombre&gt;.<?= e($tld) ?></code> abra en el navegador hay que registrarlos en Windows (una vez).</div>
      </div>
      <div class="spacer"></div>
      <form method="post">
        <input type="hidden" name="action" value="hosts">
        <button class="btn ghost" type="submit">Sincronizar dominios</button>
      </form>
    </div>

    <?php $httpsOn = is_file($ROOT.'/config/https.on') && is_file($ROOT.'/data/ssl/lua.pem'); ?>
    <div class="card row">
      <div>
        <div style="font-weight:600">HTTPS local <span class="jstate <?= $httpsOn?'ok':'err' ?>" style="margin-left:6px"><?= $httpsOn?'ACTIVO':'INACTIVO' ?></span></div>
        <div class="muted">Certificados de confianza para <code>https://&lt;proyecto&gt;.<?= e($tld) ?></code> (candado verde). Al activar, Windows pedirá permiso para instalar la CA (una vez).</div>
      </div>
      <div class="spacer"></div>
      <form method="post">
        <input type="hidden" name="action" value="https">
        <input type="hidden" name="enable" value="<?= $httpsOn?'0':'1' ?>">
        <button class="btn <?= $httpsOn?'danger':'ghost' ?>" type="submit"><?= $httpsOn?'Desactivar':'Activar' ?> HTTPS</button>
      </form>
    </div>

  <?php elseif ($tab==='php'): /* ---------- PESTAÑA PHP ---------- */ ?>

    <h2>Editar php.ini por versión</h2>
    <div class="muted" style="margin-bottom:14px">Los cambios se guardan como <em>overrides</em> (sobreviven a actualizaciones) y se aplican recargando Apache automáticamente.</div>

    <?php if (!$vers): ?>
      <div class="card muted">No hay versiones de PHP instaladas.</div>
    <?php else: $openVer = $_GET['ver'] ?? ''; foreach ($vers as $v):
        [$vals,$extra] = parse_overrides("$OVR_DIR/$v.overrides.ini", array_keys($CURATED)); ?>
      <details <?= $v===$openVer?'open':'' ?>>
        <summary>PHP <?= e($v) ?> <span class="op">&mdash; config/php/<?= e($v) ?>.overrides.ini</span></summary>
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

  <?php elseif ($tab==='logs'): /* ---------- PESTAÑA LOGS ---------- */
      $logDir = $ROOT.'/logs/apache';
      $logFiles = [];
      foreach (glob($logDir.'/*.log') as $f) $logFiles[] = basename($f);
      sort($logFiles);
      if (!$logFiles) $logFiles = ['error.log'];
      $sel = safe_logname($_GET['log'] ?? '');
      if (!$sel || !in_array($sel,$logFiles,true)) $sel = in_array('error.log',$logFiles,true)?'error.log':$logFiles[0];
      $refresh = (($_GET['refresh']??'')==='1');
      $content = tail_file($logDir.'/'.$sel, 300);
  ?>
    <div class="row" style="margin-bottom:14px;gap:8px;flex-wrap:wrap">
      <?php foreach ($logFiles as $lf): ?>
        <a href="?tab=logs&log=<?= urlencode($lf) ?><?= $refresh?'&refresh=1':'' ?>" class="btn <?= $lf===$sel?'':'ghost' ?>" style="padding:6px 12px;font-size:13px"><?= e($lf) ?></a>
      <?php endforeach; ?>
      <div class="spacer"></div>
      <a href="?tab=logs&log=<?= urlencode($sel) ?><?= $refresh?'':'&refresh=1' ?>" class="btn ghost" style="padding:6px 12px;font-size:13px"><?= $refresh?'⏸ Auto-refresco ON':'▶ Auto-refresco' ?></a>
      <form method="post" onsubmit="return confirm('¿Vaciar <?= e($sel) ?>?')" style="display:inline">
        <input type="hidden" name="action" value="clearlog"><input type="hidden" name="log" value="<?= e($sel) ?>">
        <button class="btn ghost" style="padding:6px 12px;font-size:13px">Vaciar</button>
      </form>
    </div>
    <pre class="joblog" style="max-height:62vh"><?= $content!=='' ? e($content) : '(vacío)' ?></pre>

  <?php endif; ?>

  <footer>lua-server &middot; Apache + mod_fcgid &middot; panel solo accesible desde esta máquina</footer>
</div>
</body>
</html>
