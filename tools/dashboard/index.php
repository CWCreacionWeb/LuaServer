<?php
// ============================================================
//  lua-server :: panel de gestion (solo localhost)
//  - Proyectos: crear / eliminar / cambiar version de PHP
//  - PHP: editar overrides del php.ini de cada version
// ============================================================
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

// Sonda de salud: si esta pagina responde, Apache esta arriba. La usan las recargas tras
// una accion que reinicia Apache, para no navegar mientras Apache aun esta caido (lo que
// mostraba "connection refused"). Debe ir lo primero, sin dependencias.
if (isset($_GET['ping'])) { header('Content-Type: text/plain; charset=utf-8'); echo 'ok'; exit; }

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
  'error_reporting'     => ['label' => 'Nivel de errores',        'type' => 'select', 'ph' => '', 'options' => [
      'E_ALL'                                  => 'Todo (E_ALL)',
      'E_ALL & ~E_DEPRECATED & ~E_NOTICE'      => 'Todo menos avisos y deprecaciones (recomendado)',
      'E_ALL & ~E_DEPRECATED'                  => 'Todo menos deprecaciones',
      'E_ERROR | E_WARNING | E_PARSE'          => 'Solo errores y warnings',
      'E_ERROR | E_PARSE'                      => 'Solo errores fatales',
      '0'                                      => 'Ninguno',
  ]],
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


// ---------------- Libreria de funciones (tools/dashboard/lib/) ----------------
require_once __DIR__.'/lib/sites.php';
require_once __DIR__.'/lib/project-detail.php';
require_once __DIR__.'/lib/docker.php';
require_once __DIR__.'/lib/file-tree.php';
require_once __DIR__.'/lib/sites-detect.php';
require_once __DIR__.'/lib/mysql.php';
require_once __DIR__.'/lib/postgres.php';
require_once __DIR__.'/lib/sysmon-doctor.php';
require_once __DIR__.'/lib/procs.php';
require_once __DIR__.'/lib/redis.php';
require_once __DIR__.'/lib/sqlsrv.php';
require_once __DIR__.'/lib/terminal.php';
require_once __DIR__.'/lib/misc-features.php';
require_once __DIR__.'/lib/logs.php';
require_once __DIR__.'/lib/jobs.php';


// ---------------- Endpoints AJAX y GET crudos (tools/dashboard/ajax/) ----------------
include __DIR__.'/ajax/cover-brand.php';
include __DIR__.'/ajax/export-db.php';
include __DIR__.'/ajax/pickfolder.php';
include __DIR__.'/ajax/file-tree.php';
include __DIR__.'/ajax/logs.php';
include __DIR__.'/ajax/procs.php';
include __DIR__.'/ajax/redis.php';
include __DIR__.'/ajax/sqlsrv.php';
include __DIR__.'/ajax/terminal.php';
include __DIR__.'/ajax/runner.php';
// ---------------- POST (patron PRG) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $cfg = read_json($CFG_FILE) ?: ['defaultPhp'=>'8.4','tld'=>'lua.test','sites'=>[]];
    if(!isset($cfg['sites'])||!is_array($cfg['sites'])) $cfg['sites']=[];
    $vers = php_versions($PHP_BASE);
    $tab='proyectos'; $msg=''; $redirName=null;


    // ---------------- Dispatch de acciones (tools/dashboard/actions/) ----------------
    include __DIR__.'/actions/power.php';
    include __DIR__.'/actions/projects.php';
    include __DIR__.'/actions/git-ftp.php';
    include __DIR__.'/actions/branding-and-cards.php';
    include __DIR__.'/actions/php-and-config.php';
    include __DIR__.'/actions/services.php';
    include __DIR__.'/actions/databases.php';
    include __DIR__.'/actions/sqlsrv-redis-conns.php';
    include __DIR__.'/actions/procs.php';
    include __DIR__.'/actions/update.php';
    include __DIR__.'/actions/terminal-docker.php';

    // Si "Crear proyecto"/"Registrar proyecto externo" fallo (mensaje de error), reabrir el
    // modal al recargar -- si no, el usuario pierde de vista el formulario que acaba de
    // rellenar y tiene que volver a abrirlo el mismo desde cero.
    $reopenModal = in_array($action, ['create','add_external'], true) && strpos((string)$msg, 'error:') === 0;

    // Guardado con el icono de disquete de las cards de ajustes: mismo handler de siempre,
    // solo cambia la respuesta. Se contesta JSON en vez de redirigir para que la página no
    // recargue y el icono pueda ponerse verde en sitio. 'reload' marca las acciones cuyo
    // efecto no se puede reflejar sin repintar la página entera (set_tld cambia el dominio
    // de todos los proyectos y dispara la sincronización de hosts con UAC).
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        [$aType,$aText] = array_pad(explode(':',(string)$msg,2),2,'');
        echo json_encode([
            'ok'     => $aType !== 'error',
            'type'   => $aType,
            'msg'    => $aText,
            'reload' => in_array($action, ['set_tld'], true),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: ?tab='.$tab.(isset($ver)?'&ver='.urlencode($ver):'').($redirName?'&name='.urlencode($redirName):'').(isset($tab_engine)?'&engine='.urlencode($tab_engine):'').($reopenModal?'&reopen=newproject':'').'&msg='.urlencode($msg));
    exit;
}

// ---------------- GET (render) ----------------
$cfg = read_json($CFG_FILE) ?: ['defaultPhp'=>'8.4','tld'=>'lua.test','sites'=>[]];
$brandName = brand_name($cfg);
$luaVer    = lua_version($ROOT);
$updSt     = update_status($ROOT);
$updCfg    = update_config($ROOT);
$updDetras = (int)($updSt['detras'] ?? 0);
$updHay    = $updDetras > 0;
$brandLogo = brand_logo_path($ROOT);      // ruta del logo propio, o null si usa el de por defecto
$tld = $cfg['tld'] ?? 'lua.test';
$sites = $cfg['sites'] ?? [];
// phpMyAdmin es una herramienta de la plataforma (enlazada en "Bases de datos"),
// no un proyecto del usuario: se registra igual (vhost, php.ini...) pero se oculta
// de la lista de proyectos. Sigue contando como "registrado" para unregistered_projects().
$sitesView = array_diff_key($sites, ['phpmyadmin'=>true]);
$phpmyadminDom = !empty($sites['phpmyadmin']['domain']) ? $sites['phpmyadmin']['domain'] : 'phpmyadmin.'.($cfg['tld'] ?? 'lua.test');
$defaultPhp = $cfg['defaultPhp'] ?? '8.4';
$vers = php_versions($PHP_BASE);
// mod_fcgid mantiene procesos php-cgi vivos entre peticiones: sin esto, is_dir()
// puede seguir devolviendo cache de una carpeta borrada por fuera del panel.
clearstatcache();
$unreg = unregistered_projects($WWW, $sites);
$tab = $_GET['tab'] ?? 'proyectos';
$msg = $_GET['msg'] ?? '';
[$mtype,$mtext] = array_pad(explode(':',$msg,2),2,'');
$reopenNewProject = ($_GET['reopen'] ?? '') === 'newproject';
$curPhp = PHP_VERSION;
$jobs = read_jobs($ROOT.'/tmp/jobs');
$anyJobRun = false; foreach($jobs as $jj){ if(in_array(($jj['state']??''),['running','queued'],true)){$anyJobRun=true;break;} }
$anyDbImportRun = false; foreach($jobs as $jj){ if(in_array(($jj['type']??''),['db_import_dir','db_import_file'],true) && in_array(($jj['state']??''),['running','queued'],true)){$anyDbImportRun=true;break;} }
$watcherAlive = watcher_alive($ROOT);
// Para el icono de terminal global de la cabecera: se calcula aqui (una vez, antes de
// cualquier pestana) porque la cabecera se pinta para las 14 pestanas por igual, no solo
// para la pestana "Terminal" (que ya calculaba su propia copia local de este mismo flag).
$termOnHdr = is_file($ROOT.'/config/terminal.on');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($brandLogo): ?><link rel="icon" href="?brandlogo&t=<?= filemtime($brandLogo) ?>"><?php else: ?><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><?php endif; ?>
<title><?= e($brandName) ?></title>
<?php if ($mtype==='applied'): ?><script>
// Recarga resiliente: en vez de un temporizador fijo (que caia sobre Apache mientras se
// reiniciaba -> connection refused), sondeamos ?ping=1 hasta que responda y solo entonces
// navegamos. Tope ~31s por si algo se atasca (recarga igualmente).
(function(){var t='?tab=<?= e($tab) ?>',n=0;
function go(){location.href=t;}
function ping(){fetch('?ping=1',{cache:'no-store'}).then(function(r){if(r.ok){go();}else{retry();}}).catch(retry);}
function retry(){if(++n>60){go();return;}setTimeout(ping,500);}
setTimeout(ping,1500);})();
</script><?php endif; ?>
<?php if ($mtype==='info'): ?><script>setTimeout(function(){location.href='?tab=<?= e($tab) ?>';},7000);</script><?php endif; ?>
<?php if (($tab==='proyectos' || $tab==='config' || $tab==='proyecto') && ($anyJobRun || $mtype==='job')): ?><meta http-equiv="refresh" content="3"><?php endif; ?>
<?php if ($tab==='bd' && ($anyDbImportRun || $mtype==='job')): ?><meta http-equiv="refresh" content="3"><?php endif; ?>
<?php if ($tab==='logs' && (($_GET['refresh']??'')==='1')): ?><meta http-equiv="refresh" content="4"><?php endif; ?>
<style>
  :root{
    --bg:#0f1117; --card:#1a1d27; --line:#2a2f3d; --tx:#e6e8ee; --mut:#8b90a0;
    --ac:#6ea8fe; --ac-hover:#5a97f0; --ok:#3fb950; --warn:#d29922; --err:#f85149; --err-dark:#b3261e; --in:#11141c;
    --brand-start:#6ea8fe; --brand-end:#9b6efe;
  }
  @media (prefers-color-scheme:light){
    :root{ --bg:#f4f6fb; --card:#fff; --line:#e3e7f0; --tx:#1a1d27; --mut:#5b6172; --ac:#2b6cff; --ac-hover:#1a5ae8; --in:#fff; }
  }
  *{box-sizing:border-box}
  html{height:100%}
  body{margin:0;height:100vh;overflow:hidden;display:flex;flex-direction:column;background:var(--bg);color:var(--tx);font-family:system-ui,'Segoe UI',Roboto,sans-serif}
  a{color:var(--ac);text-decoration:none}
  a:hover{color:var(--ac-hover)}

  header{display:flex;align-items:center;gap:14px;padding:10px 40px;background:var(--card);border-bottom:1px solid var(--line);flex-shrink:0}
  .logo{width:44px;height:44px;flex-shrink:0;display:flex;align-items:center;justify-content:center}
  .logo img{width:100%;height:100%;display:block}
  h1{margin:0;font-size:19px;font-weight:700;line-height:1.2}
  .sub{color:var(--mut);font-size:12px;margin-top:1px}
  /* Version de la plataforma, junto al titulo. Se tine de ambar cuando hay actualizaciones. */
  .verchip{display:inline-block;vertical-align:middle;margin-left:9px;padding:2px 8px;border-radius:999px;
           border:1px solid var(--line);background:var(--in);color:var(--mut);
           font-size:11px;font-weight:600;font-family:ui-monospace,Consolas,monospace;
           text-decoration:none;letter-spacing:.2px;transition:color .12s,border-color .12s}
  .verchip:hover{color:var(--ac);border-color:var(--ac)}
  .verchip.hay{color:var(--warn);border-color:var(--warn);background:rgba(210,153,34,.12)}
  .verchip.hay:hover{filter:brightness(1.1)}
  .spacer{flex:1}
  .badges{display:flex;gap:6px;align-items:center;flex-shrink:0}
  .iconbtn{display:flex;align-items:center;justify-content:center;width:34px;height:34px;flex-shrink:0;background:transparent;border:1px solid var(--line);border-radius:8px;color:var(--mut);cursor:pointer;transition:color .12s,border-color .12s,background-color .12s}
  .restartbtn{margin-left:12px}
  .restartbtn:hover{color:var(--ac);border-color:var(--ac);background:rgba(110,168,254,.10)}
  .powerbtn{margin-left:6px}
  .powerbtn:hover{color:var(--err);border-color:var(--err);background:rgba(248,81,73,.10)}
  .gtermbtn.on{color:var(--ac);border-color:var(--ac);background:rgba(110,168,254,.14)}

  .tabbar{padding:0 40px;background:var(--card);border-bottom:1px solid var(--line);flex-shrink:0}
  .tabs{display:flex;gap:6px}
  .tabs a{padding:9px 16px;color:var(--mut);text-decoration:none;font-weight:600;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-1px;display:inline-block}
  .tabs a.on{color:var(--ac);border-color:var(--ac)}

  .content{flex:1;overflow-y:auto;padding:28px 40px 48px}

  .card{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:18px 20px;margin-bottom:14px}
  .row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .row .name{font-weight:700;font-size:16px;min-width:150px}
  .row .url{color:var(--mut);font-size:13px} .row .url:hover{color:var(--ac)}

  label{display:block;font-size:12px;color:var(--mut);margin:0 0 4px}
  input,select,textarea{background:var(--in);color:var(--tx);border:1px solid var(--line);border-radius:5px;padding:8px 10px;font-size:14px;font-family:inherit;line-height:1.4}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--ac)}
  textarea{width:100%;min-height:300px;font-family:ui-monospace,Consolas,monospace;font-size:13px;resize:vertical}

  .btn{background:var(--ac);background-image:linear-gradient(135deg,var(--brand-start),var(--brand-end));color:#fff;border:1px solid transparent;border-radius:5px;padding:6px 13px;font-size:13px;font-family:inherit;line-height:1.4;font-weight:600;cursor:pointer;transition:filter .12s,background .12s,color .12s,border-color .12s}
  .btn:hover{filter:brightness(1.08)}
  .btn.sm{padding:4px 10px;font-size:13px}
  .btn.ghost{background-image:linear-gradient(135deg,var(--brand-start),var(--brand-end));border-color:transparent;color:#fff}
  .btn.ghost:hover{filter:brightness(1.08)}
  .btn.danger{background-image:linear-gradient(135deg,var(--err),var(--err-dark));border-color:transparent;color:#fff}
  .btn.danger:hover{filter:brightness(1.08)}
  .btn-git{display:inline-flex;align-items:center;gap:8px;background:#161b22;color:#fff;border:1px solid #30363d;border-radius:5px;padding:6px 13px;font-size:13px;font-family:inherit;line-height:1.4;font-weight:600;cursor:pointer;transition:background-color .12s}
  .btn-git:hover{background:#22272e}
  .btn-git.sm{padding:4px 10px}
  .btn-git:disabled{opacity:.55;cursor:default}

  .dbrow{display:flex;align-items:center;flex-wrap:wrap;gap:16px;padding:14px 0;border-top:1px solid var(--line)}
  .dbrow:first-of-type{border-top:none}
  .dbrow .dbname{font-weight:600;font-family:ui-monospace,Consolas,monospace;font-size:13px}
  .dbactions{display:flex;align-items:center;gap:20px;min-width:560px;justify-content:flex-end}
  .dbimport{display:flex;align-items:center;gap:10px}
  .filepick{position:relative;display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border:1px dashed var(--line);border-radius:5px;color:var(--mut);font-size:12px;cursor:pointer;max-width:190px;min-width:0;transition:color .12s,border-color .12s,background-color .12s}
  .filepick:hover{color:var(--ac);border-color:var(--ac);background:rgba(110,168,254,.06)}
  .filepick.has-file{color:var(--tx);border-style:solid}
  .filepick svg{flex:0 0 auto}
  .filepick-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0}
  .filepick input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%}

  .topgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;align-items:start}
  .topgrid .card{margin-bottom:0}
  @media (max-width:1000px){ .topgrid{grid-template-columns:repeat(2,1fr)} }
  @media (max-width:640px){ .topgrid{grid-template-columns:1fr} }

  .pgrid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}
  .pgrid2 .card{margin-bottom:0}

  /* fila de 3 tarjetas de configuracion (identidad, dominio local, dominios) */
  .cfg3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px}
  .cfg3 .card{margin-bottom:0;display:flex;flex-direction:column}
  .cfg3 .cfg3-body{flex:1}
  .cfg3 .cfg3-actions{margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .cfg3 label{display:block;font-size:12px;color:var(--mut);margin:0 0 4px}
  @media (max-width:900px){ .cfg3{grid-template-columns:1fr} }
  /* Icono de disquete = guardar los ajustes de esa card (sin boton en el pie). Guarda por
     AJAX y se pone verde al confirmar el servidor; rojo si el guardado se rechaza. */
  .card.cardsave{position:relative}
  .card.cardsave .cardsave-title{padding-right:38px}
  .savebtn{position:absolute;top:14px;right:14px;display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;padding:0;border:1px solid var(--line);border-radius:7px;background:var(--in);color:var(--mut);cursor:pointer;transition:color .15s,border-color .15s,background-color .15s}
  .savebtn:hover{color:var(--tx);border-color:var(--mut)}
  .savebtn.ok{color:var(--ok);border-color:var(--ok);background:rgba(63,185,80,.14)}
  .savebtn.err{color:var(--err);border-color:var(--err);background:rgba(248,81,73,.14)}
  .savebtn[disabled]{opacity:.55;cursor:default}
  .savemsg{font-size:11.5px;margin-top:8px}
  .savemsg.ok{color:var(--ok)}
  .savemsg.err{color:var(--err)}
  @media (max-width:900px){ .pgrid2{grid-template-columns:1fr} }

  .sitegrid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:14px}
  .sitecard{position:relative;background:var(--card);border:1px solid var(--line);border-radius:8px;padding:14px 16px;display:flex;flex-direction:column;gap:10px;min-width:0}
  .sitecard.is-locked{border-color:var(--warn)}
  .sitecard .cardbody{display:flex;flex-direction:column;gap:4px;min-width:0;flex:1}
  .sitecard .name{font-weight:700;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
  .sitecard .url{color:var(--mut);font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}
  .sitecard .btn{width:100%;text-align:center}
  .cardfooter{margin:0 -16px -14px;padding:8px 16px;border-top:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:6px}
  .cardfooter .phpselform{margin:0;flex:0 0 auto;display:flex;align-items:center;gap:6px;min-width:0}
  .cardfooter select.phpsel{width:auto;padding:5px 6px;font-size:11px;border-radius:4px}
  .cardactions{margin:0;flex:0 0 auto;display:flex;gap:6px}
  .lockform{margin:0}
  .lockbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0;background:var(--card);border:1px solid var(--line);border-radius:5px;color:var(--mut);cursor:pointer;transition:color .12s,border-color .12s,background-color .12s}
  .lockbtn:hover{color:var(--ac);border-color:var(--ac)}
  .lockbtn.on{color:var(--ac);border-color:var(--ac);background:rgba(110,168,254,.12)}
  /* Modal "fijado a la derecha": pasa de dialogo centrado a panel a pantalla completa
     pegado al borde derecho, con el resto de la pagina interactuable (overlay sin fondo
     ni bloqueo de clics) -- pensado para dejarlo abierto mientras se sigue trabajando. */
  .modal-overlay.docked{background:transparent;justify-content:flex-end;align-items:stretch;padding:0;pointer-events:none}
  .modal-overlay.docked .modal-box{pointer-events:auto}
  .modal-box.docked{width:440px!important;max-width:440px!important;height:100vh!important;max-height:100vh!important;margin:0!important;border-radius:0!important;box-shadow:-12px 0 34px rgba(0,0,0,.4);display:flex;flex-direction:column}
  .modal-box.docked .termout{flex:1 1 auto;height:auto!important}
  .sitecard.is-locked .lockbtn{color:var(--warn);border-color:var(--warn);background:rgba(210,153,34,.12)}
  .trashbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0;border:1px solid transparent;border-radius:5px;color:#fff;cursor:pointer;background:linear-gradient(135deg,#ff8a80,var(--err));transition:filter .12s,transform .12s}
  .trashbtn:hover{filter:brightness(1.12)}
  .trashbtn:active{transform:scale(.94)}
  .pwrbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0;border:1px solid transparent;border-radius:5px;color:#fff;cursor:pointer;background:linear-gradient(135deg,#ff8a80,var(--err));transition:filter .12s,transform .12s}
  .pwrbtn:hover{filter:brightness(1.12)}
  .pwrbtn:active{transform:scale(.94)}
  .toollink{display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:6px 10px;border:1px solid var(--line);border-radius:5px;color:var(--mut);font-size:12px;font-weight:600;text-decoration:none;transition:color .12s,border-color .12s,background-color .12s}
  .toollink:hover{color:var(--ac);border-color:var(--ac);background:rgba(110,168,254,.08)}
  .runlink{display:inline-flex;align-items:center;gap:6px;background:none;border:none;padding:3px 2px;color:var(--mut);font-family:ui-monospace,Consolas,monospace;font-size:13px;cursor:pointer;text-decoration:none;transition:color .12s}
  .runlink:hover{color:var(--ac);text-decoration:underline}
  .runlink svg{flex:0 0 auto;opacity:.7;transition:opacity .12s}
  .runlink:hover svg{opacity:1}
  .runlink:disabled{opacity:.5;cursor:default;text-decoration:none}
  .runlink.off{opacity:.4;pointer-events:none;text-decoration:none}
  .runlink-wrap{display:inline-flex;align-items:center;gap:2px}
  .runlink-del{background:none;border:none;color:var(--mut);font-size:14px;line-height:1;cursor:pointer;padding:3px 5px;border-radius:4px;transition:color .12s,background-color .12s}
  .runlink-del:hover{color:var(--err);background:rgba(248,81,73,.10)}
  .runlink-danger{color:var(--err)}
  .runlink-danger:hover{color:var(--err)}
  .runbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0 0 0 1px;background:var(--card);border:1px solid var(--line);border-radius:5px;color:var(--mut);cursor:pointer;transition:color .12s,border-color .12s,background-color .12s}
  .runbtn:hover{color:var(--ac);border-color:var(--ac)}
  .runbtn.running{color:var(--ok);border-color:var(--ok);background:rgba(63,185,80,.14)}
  .runbtn.running:hover{color:var(--ok);border-color:var(--ok)}
  .sitecard.is-locked .lockbtn:hover{color:var(--err);border-color:var(--err);background:rgba(248,81,73,.12)}
  .sitecard.unregistered{background:var(--line);border-style:dashed;border-color:var(--line);opacity:.55}
  .sitecard.unregistered .name{color:var(--mut);font-weight:600}
  .exttag{font-size:9px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--ac);background:rgba(110,168,254,.14);padding:1px 5px;border-radius:999px;vertical-align:middle}
  .typetag{display:inline-flex;align-items:center;gap:3px;font-size:9px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;padding:1px 6px 1px 5px;border-radius:999px;vertical-align:middle;color:var(--mut);background:rgba(139,144,160,.16)}
  .typetag .typeicon{flex:0 0 auto}
  .typetag-wordpress{color:var(--ac);background:rgba(110,168,254,.14)}
  .typetag-laravel{color:var(--err);background:rgba(248,81,73,.14)}
  .typetag-symfony{color:var(--ok);background:rgba(63,185,80,.14)}
  .typetag-slim{color:var(--warn);background:rgba(210,153,34,.14)}
  .typetag-react{color:#3aa6c0;background:rgba(97,218,251,.16)}
  .typetag-vue{color:#35a173;background:rgba(66,184,131,.16)}
  .typetag-angular{color:#e23237;background:rgba(221,0,49,.14)}
  .typetag-nextjs{color:var(--tx);background:rgba(128,128,128,.18)}
  .typetag-nuxt{color:#00b877;background:rgba(0,220,130,.14)}
  .typetag-svelte{color:#ff5722;background:rgba(255,62,0,.14)}
  .typetag-astro{color:#b070f7;background:rgba(168,85,247,.16)}
  .typetag-vite{color:#9b6efe;background:rgba(155,110,254,.16)}
  .typetag-node{color:#57a83f;background:rgba(87,168,63,.16)}
  .typetag-django{color:#2ba977;background:rgba(43,169,119,.16)}
  .typetag-flask{color:var(--mut);background:rgba(139,144,160,.2)}
  .typetag-fastapi{color:#12a99b;background:rgba(0,150,136,.16)}
  .typetag-python{color:#4b8bbe;background:rgba(55,118,171,.18)}
  .tagrow{display:flex;align-items:center;gap:4px;flex-wrap:wrap}
  details.extform{padding:0}
  details.extform>summary{padding:16px 20px;cursor:pointer;font-weight:600;font-size:14px;list-style:none}
  details.extform>summary::-webkit-details-marker{display:none}
  details.extform>summary::before{content:'+ ';color:var(--ac);font-weight:700}
  details.extform[open]>summary::before{content:'– '}
  details.extform>form{padding:0 20px 18px}
  /* Caratula */
  .coverform{margin:-14px -16px 4px;display:block}
  .cover{position:relative;display:block;width:100%;height:78px;padding:0;border:0;cursor:pointer;border-radius:8px 8px 0 0;background-color:var(--in);background-size:cover;background-position:center;background-repeat:no-repeat;border-bottom:1px solid var(--line)}
  .cover.empty{background-image:linear-gradient(135deg,rgba(110,168,254,.08),rgba(155,110,254,.08))}
  /* Tinte de la banda por tipo de proyecto (solo cuando no hay caratula subida) */
  .cover.empty.type-laravel{background-image:linear-gradient(135deg,rgba(248,81,73,.22),rgba(248,81,73,.06))}
  .cover.empty.type-wordpress{background-image:linear-gradient(135deg,rgba(110,168,254,.22),rgba(110,168,254,.06))}
  .cover.empty.type-symfony{background-image:linear-gradient(135deg,rgba(63,185,80,.20),rgba(63,185,80,.06))}
  .cover.empty.type-slim{background-image:linear-gradient(135deg,rgba(210,153,34,.20),rgba(210,153,34,.06))}
  .cover.empty.type-react{background-image:linear-gradient(135deg,rgba(97,218,251,.22),rgba(97,218,251,.05))}
  .cover.empty.type-vue{background-image:linear-gradient(135deg,rgba(66,184,131,.20),rgba(66,184,131,.05))}
  .cover.empty.type-angular{background-image:linear-gradient(135deg,rgba(221,0,49,.20),rgba(176,0,224,.06))}
  .cover.empty.type-nextjs{background-image:linear-gradient(135deg,rgba(128,128,128,.22),rgba(128,128,128,.05))}
  .cover.empty.type-nuxt{background-image:linear-gradient(135deg,rgba(0,220,130,.20),rgba(0,220,130,.05))}
  .cover.empty.type-svelte{background-image:linear-gradient(135deg,rgba(255,62,0,.20),rgba(255,62,0,.05))}
  .cover.empty.type-astro{background-image:linear-gradient(135deg,rgba(168,85,247,.22),rgba(255,120,60,.08))}
  .cover.empty.type-vite{background-image:linear-gradient(135deg,rgba(155,110,254,.22),rgba(255,206,0,.08))}
  .cover.empty.type-node{background-image:linear-gradient(135deg,rgba(87,168,63,.20),rgba(87,168,63,.05))}
  .cover.empty.type-django{background-image:linear-gradient(135deg,rgba(43,169,119,.20),rgba(43,169,119,.05))}
  .cover.empty.type-flask{background-image:linear-gradient(135deg,rgba(139,144,160,.20),rgba(139,144,160,.05))}
  .cover.empty.type-fastapi{background-image:linear-gradient(135deg,rgba(0,150,136,.20),rgba(0,150,136,.05))}
  .cover.empty.type-python{background-image:linear-gradient(135deg,rgba(55,118,171,.20),rgba(255,212,59,.08))}
  .cover-hint{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:6px;font-size:11px;font-weight:600;letter-spacing:.2px}
  .cover.empty .cover-hint{color:var(--mut)}
  .cover.has .cover-hint{color:#fff;background:rgba(10,12,18,.5);opacity:0;transition:opacity .12s}
  .cover.has:hover .cover-hint{opacity:1}
  .coverdel{position:absolute;top:8px;left:8px;margin:0;z-index:3}
  .pinform{position:absolute;top:8px;right:8px;margin:0;z-index:3}
  .pinbtn{display:flex;align-items:center;justify-content:center;width:24px;height:24px;padding:0;border:1px solid transparent;border-radius:5px;color:#fff;background:rgba(10,12,18,.55);cursor:pointer;transition:color .12s,background-color .12s,transform .12s}
  .pinbtn:hover{background:rgba(10,12,18,.75)}
  .pinbtn:active{transform:scale(.9)}
  .pinbtn.is-pinned{background:linear-gradient(135deg,#6ea8fe,#9b6efe)}
  .sitecard.is-pinned{border-color:#9b6efe}
  .coverdelbtn{display:flex;align-items:center;justify-content:center;width:22px;height:22px;padding:0;border:0;border-radius:5px;background:rgba(10,12,18,.55);color:#fff;font-size:15px;line-height:1;cursor:pointer}
  .coverdelbtn:hover{background:var(--err)}

  /* Selector de vista (cuadricula/lista) */
  .viewtoggle{display:flex;gap:2px;background:var(--card);border:1px solid var(--line);border-radius:6px;padding:2px}
  .viewtoggle button{display:flex;align-items:center;justify-content:center;width:28px;height:26px;padding:0;border:0;border-radius:4px;background:transparent;color:var(--mut);cursor:pointer;transition:color .12s,background-color .12s}
  .viewtoggle button:hover{color:var(--ac)}
  .viewtoggle button.on{background:var(--ac);color:#fff}

  .sitegrid.list{grid-template-columns:1fr}
  .sitegrid.list .sitecard{flex-direction:row;align-items:center;gap:14px;padding:10px 16px}
  .sitegrid.list .sitecard .coverform,
  .sitegrid.list .sitecard .coverdel{display:none}
  .sitegrid.list .sitecard .pinform{position:static}
  .sitegrid.list .sitecard .cardbody{flex-direction:row;align-items:center;gap:10px;flex:1;min-width:0}
  .sitegrid.list .sitecard .name{flex:0 0 220px}
  .sitegrid.list .sitecard .tagrow{flex:0 0 auto}
  .sitegrid.list .sitecard .url{flex:1}
  .sitegrid.list .sitecard form{width:auto;margin:0}
  .sitegrid.list .sitecard .btn{width:auto}
  .sitegrid.list .sitecard .cardfooter{margin:0;padding:0;border:0;flex:0 0 auto}
  .sitegrid.list .sitecard select.phpsel{width:auto}

  /* ---------- Modal de confirmacion ---------- */
  .modal-overlay{position:fixed;inset:0;background:rgba(6,7,10,.6);display:flex;align-items:center;justify-content:center;z-index:100;padding:20px}
  .modal-overlay[hidden]{display:none}
  .modal-box{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:26px 26px 22px;max-width:400px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.45)}
  .modal-ic{width:48px;height:48px;border-radius:999px;background:rgba(248,81,73,.12);color:var(--err);display:flex;align-items:center;justify-content:center;font-size:22px;margin:0 auto 14px}
  .modal-ic-off{background:rgba(139,144,160,.15);color:var(--mut)}
  .modal-ic-info{background:rgba(110,168,254,.14);color:var(--ac)}
  .modal-box h3{margin:0 0 10px;font-size:17px;font-weight:700}
  .modal-tx{color:var(--mut);font-size:13px;line-height:1.5;margin:0 0 20px}
  .modal-tx strong{color:var(--tx)}
  .modal-actions{display:flex;gap:8px;justify-content:center}
  .modal-actions .btn{width:auto;padding:6px 15px}

  .loader-overlay{position:fixed;inset:0;background:rgba(6,7,10,.5);display:flex;align-items:center;justify-content:center;z-index:200}
  .loader-overlay[hidden]{display:none}
  .loader-box{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:20px 28px;box-shadow:0 20px 60px rgba(0,0,0,.45);display:flex;align-items:center;gap:14px;max-width:min(90vw,420px)}
  .loader-spin{width:22px;height:22px;flex:0 0 auto;border-radius:999px;border:3px solid var(--line);border-top-color:var(--ac);animation:loaderspin .7s linear infinite}
  .loader-tx{font-size:14px;font-weight:600;color:var(--tx);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  @keyframes loaderspin{to{transform:rotate(360deg)}}
  .btn-spin{display:inline-block;width:12px;height:12px;border-radius:999px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;animation:loaderspin .7s linear infinite;vertical-align:-2px;margin-right:6px}

  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px 12px}
  .grid label{min-height:2.6em}
  .tag{display:inline-block;font-size:12px;color:var(--ac);background:rgba(110,168,254,.12);padding:2px 9px;border-radius:999px}

  .banner{padding:11px 15px;border-radius:8px;margin-bottom:16px;font-size:14px;border:1px solid}
  .banner.applied{background:rgba(63,185,80,.12);border-color:var(--ok);color:var(--ok)}
  .banner.info{background:rgba(110,168,254,.12);border-color:var(--ac);color:var(--ac)}
  .banner.error{background:rgba(248,81,73,.12);border-color:var(--err);color:var(--err)}
  .banner.job{background:rgba(210,153,34,.12);border-color:var(--warn);color:var(--warn)}

  .jstate{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;letter-spacing:.3px;display:inline-block}
  .jstate.ok{background:rgba(63,185,80,.15);color:var(--ok)}
  .jstate.err{background:rgba(248,81,73,.15);color:var(--err)}
  .jstate.run{background:rgba(110,168,254,.15);color:var(--ac)}
  .jstate.warn{background:rgba(210,153,34,.15);color:var(--warn)}
  .jstate.orange{background:rgba(247,127,0,.16);color:#f77f00}
  a.jstate{text-decoration:none;cursor:pointer;transition:filter .12s}
  a.jstate:hover{filter:brightness(1.2)}

  .joblog{background:var(--in);border:1px solid var(--line);border-radius:3px;padding:10px;margin:10px 0 0;font-family:ui-monospace,Consolas,monospace;font-size:11px;white-space:pre-wrap;max-height:72px;overflow:auto;color:var(--mut)}
  .progressbar{position:relative;height:8px;border-radius:999px;background:var(--in);border:1px solid var(--line);overflow:hidden;margin-top:8px}
  .progressbar-fill{height:100%;border-radius:999px;background-image:linear-gradient(135deg,var(--brand-start),var(--brand-end));transition:width .3s ease}
  .progressbar-fill.err{background-image:linear-gradient(135deg,var(--err),var(--err-dark))}
  .progresspct{font-size:11px;color:var(--mut);margin-top:4px;display:block}

  /* ---------- Explorador de SQL Server ---------- */
  .sqlx{display:flex;gap:14px;align-items:flex-start}
  .sqlx-side{flex:0 0 270px;width:270px;position:sticky;top:12px}
  .sqlx-main{flex:1;min-width:0}
  .sqlx-side .card,.sqlx-main .card{margin:0}
  .sqlx-tables{max-height:56vh;overflow:auto;margin:8px -6px 0}
  .sqlx-t{display:flex;align-items:center;gap:7px;width:100%;text-align:left;background:none;border:0;color:var(--tx);
          font:inherit;font-size:12.5px;padding:5px 8px;border-radius:5px;cursor:pointer;font-family:ui-monospace,Consolas,monospace}
  .sqlx-t:hover{background:rgba(110,168,254,.10)}
  .sqlx-t.on{background:rgba(110,168,254,.16);color:var(--ac);font-weight:600}
  .sqlx-t .n{margin-left:auto;font-size:10.5px;color:var(--mut);font-family:inherit}
  .sqlx-t.view .ico{opacity:.55}
  .sqlx-empty{color:var(--mut);font-size:12px;padding:10px 8px}
  .sqlx-views{display:flex;gap:16px;border-bottom:1px solid var(--line);margin:-4px -4px 12px;padding:0 4px}
  .sqlx-views button{background:none;border:0;border-bottom:2px solid transparent;color:var(--mut);font:inherit;
                     font-size:13px;font-weight:600;padding:8px 2px;margin-bottom:-1px;cursor:pointer}
  .sqlx-views button:hover{color:var(--tx)}
  .sqlx-views button.on{color:var(--ac);border-bottom-color:var(--ac)}
  /* ---- gestor de Redis (reutiliza .sqlx / .sqltbl; solo lo propio va aqui) ---- */
  .rdb{display:flex;flex-direction:column;gap:1px;margin:8px -6px 0;max-height:46vh;overflow:auto}
  .rdb button{display:flex;align-items:center;gap:7px;width:100%;text-align:left;background:none;border:0;color:var(--tx);
              font:inherit;font-size:12.5px;padding:5px 10px;border-radius:5px;cursor:pointer}
  .rdb button:hover{background:rgba(110,168,254,.10)}
  .rdb button.on{background:rgba(110,168,254,.16);color:var(--ac);font-weight:600}
  .rdb button .n{margin-left:auto;font-size:10.5px;color:var(--mut);font-family:ui-monospace,Consolas,monospace}
  .rdb button.vacia{color:var(--mut)}
  /* Etiqueta de tipo de clave. Cada tipo su color para reconocerlo de un vistazo en la lista. */
  .rtype{font-size:10px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;padding:1px 5px;border-radius:3px;
         font-family:inherit;flex:0 0 auto}
  .rtype.string{background:rgba(110,168,254,.18);color:var(--ac)}
  .rtype.hash{background:rgba(155,110,254,.18);color:#b18cff}
  .rtype.list{background:rgba(63,185,80,.18);color:var(--ok)}
  .rtype.set{background:rgba(210,153,34,.18);color:var(--warn)}
  .rtype.zset{background:rgba(248,81,73,.16);color:var(--err)}
  .rtype.stream{background:rgba(125,125,125,.18);color:var(--mut)}
  .rkeys{max-height:52vh;overflow:auto;border:1px solid var(--line);border-radius:6px;background:var(--in)}
  .rkey{display:flex;align-items:center;gap:8px;padding:5px 9px;border-bottom:1px solid var(--line);cursor:pointer}
  .rkey:last-child{border-bottom:none}
  .rkey:hover{background:rgba(110,168,254,.08)}
  .rkey.on{background:rgba(110,168,254,.16)}
  .rkey .kn{font-family:ui-monospace,Consolas,monospace;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0}
  .rkey .kt{font-size:10.5px;color:var(--mut);font-family:ui-monospace,Consolas,monospace;flex:0 0 auto}
  .rval{width:100%;min-height:190px;font-family:ui-monospace,Consolas,monospace;font-size:12.5px;line-height:1.5;
        background:var(--in);color:var(--tx);border:1px solid var(--line);border-radius:6px;padding:9px;resize:vertical}
  .rcons{background:var(--in);border:1px solid var(--line);border-radius:6px;padding:10px;max-height:32vh;overflow:auto;
         font-family:ui-monospace,Consolas,monospace;font-size:12.5px;white-space:pre-wrap;line-height:1.5}
  .rcons .cin{color:var(--ac)}
  .rcons .cerr{color:var(--err)}
  .rcons .cnil{color:var(--mut);font-style:italic}
  .rinfo{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
  .rinfo .b{background:var(--in);border:1px solid var(--line);border-radius:6px;padding:9px 11px}
  .rinfo .b .l{font-size:10.5px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}
  .rinfo .b .v{font-size:15px;font-weight:600;margin-top:2px;font-family:ui-monospace,Consolas,monospace}

  /* ---- pestaña Recursos: paleta categorica de 8 tonos ya validada como segura para
     daltonismo (skill de dataviz, orden fijo -- nunca se reordena por serie), mas los
     estados de siempre (--ok/--warn/--err) para los gauges. Dark/light con las mismas
     variables --ac/--card/--in/--line/--mut/--tx del resto del panel. ---- */
  :root{ --cat-1:#2a78d6; --cat-2:#eb6834; --cat-3:#1baf7a; --cat-4:#eda100; --cat-5:#e87ba4; --cat-6:#008300; --cat-7:#4a3aa7; --cat-8:#e34948; }
  @media (prefers-color-scheme:light){ :root{ --cat-1:#2a78d6; --cat-2:#eb6834; --cat-3:#1baf7a; --cat-4:#eda100; --cat-5:#e87ba4; --cat-6:#008300; --cat-7:#4a3aa7; --cat-8:#e34948; } }
  .sysgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px}
  @media (max-width:900px){ .sysgrid{grid-template-columns:1fr} }
  .gaugecard{display:flex;flex-direction:column;align-items:center;text-align:center;gap:2px}
  .gaugecard .lbl{font-weight:600;font-size:13px;margin-bottom:8px}
  .gaugewrap{position:relative;width:132px;height:132px}
  .gaugewrap svg{transform:rotate(-90deg)}
  .gaugewrap .track{fill:none;stroke:var(--line);stroke-width:10}
  .gaugewrap .fill{fill:none;stroke-width:10;stroke-linecap:round;transition:stroke-dashoffset .5s ease,stroke .3s ease}
  .gaugenum{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
  .gaugenum b{font-size:24px;line-height:1.1;font-family:ui-monospace,Consolas,monospace}
  .gaugenum span{font-size:10.5px;color:var(--mut);margin-top:1px}
  .gaugesub{font-size:11.5px;color:var(--mut);margin-top:8px;min-height:15px}
  .spark{width:100%;height:34px;margin-top:6px;display:block}
  .spark path.area{opacity:.16}
  .spark path.line{fill:none;stroke-width:1.6}
  .netlegend{display:flex;gap:16px;justify-content:center;margin-top:6px;font-size:11.5px}
  .netlegend .dot{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:5px;vertical-align:1px}
  .procbars{display:flex;flex-direction:column;gap:9px}
  .procbar-row{display:flex;align-items:center;gap:10px}
  .procbar-name{width:150px;flex:0 0 auto;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .procbar-track{flex:1;height:9px;border-radius:5px;background:var(--in);border:1px solid var(--line);overflow:hidden}
  .procbar-fill{height:100%;border-radius:5px;transition:width .5s ease}
  .procbar-val{width:64px;flex:0 0 auto;text-align:right;font-size:11.5px;color:var(--mut);font-family:ui-monospace,Consolas,monospace}
  .sysfoot{display:flex;flex-wrap:wrap;gap:16px;font-size:12px;color:var(--mut)}

  .sqlgrid{overflow:auto;max-height:60vh;border:1px solid var(--line);border-radius:6px;background:var(--in)}
  table.sqltbl{border-collapse:separate;border-spacing:0;width:100%;font-size:12px;font-family:ui-monospace,Consolas,monospace}
  table.sqltbl th,table.sqltbl td{padding:5px 9px;border-bottom:1px solid var(--line);white-space:nowrap;
                                  max-width:340px;overflow:hidden;text-overflow:ellipsis;vertical-align:top}
  table.sqltbl thead th{position:sticky;top:0;z-index:1;background:var(--card);border-bottom:1px solid var(--line);
                        text-align:left;font-weight:600;font-family:inherit;font-size:11.5px;color:var(--mut);cursor:pointer;user-select:none}
  table.sqltbl thead th:hover{color:var(--tx)}
  table.sqltbl thead th .pkmark{color:var(--warn);margin-left:4px}
  table.sqltbl thead th .dirmark{color:var(--ac);margin-left:3px}
  table.sqltbl tbody tr:hover{background:rgba(110,168,254,.06)}
  table.sqltbl td.acts{white-space:nowrap;position:sticky;left:0;background:var(--in)}
  table.sqltbl tbody tr:hover td.acts{background:#eef2f8}
  @media (prefers-color-scheme:dark){table.sqltbl tbody tr:hover td.acts{background:#1b2027}}
  :root[data-theme="dark"] table.sqltbl tbody tr:hover td.acts{background:#1b2027}
  :root[data-theme="light"] table.sqltbl tbody tr:hover td.acts{background:#eef2f8}
  .sqlnull{color:var(--mut);font-style:italic;opacity:.75}
  .sqlbin{color:var(--warn)}
  .sqlrowbtn{background:none;border:1px solid var(--line);border-radius:4px;color:var(--mut);cursor:pointer;
             padding:1px 6px;font-size:11px;font-family:inherit;line-height:1.5}
  .sqlrowbtn:hover{color:var(--ac);border-color:var(--ac)}
  .sqlrowbtn.del:hover{color:var(--err);border-color:var(--err)}
  .sqlpager{display:flex;align-items:center;gap:10px;margin-top:10px;font-size:12px;color:var(--mut);flex-wrap:wrap}
  .sqlfield{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
  .sqlfield label{font-size:12px;color:var(--mut)}
  .sqlfield .rowline{display:flex;align-items:center;gap:8px}
  .sqlfield input[type=text],.sqlfield textarea{width:100%;font-family:ui-monospace,Consolas,monospace;font-size:12.5px}
  .sqlfield textarea{min-height:80px;resize:vertical}
  .sqlfield .meta{font-size:11px;color:var(--mut)}
  .sqlnullbox{display:flex;align-items:center;gap:5px;font-size:11.5px;color:var(--mut);white-space:nowrap;cursor:pointer}
  #sqlEditorHost .CodeMirror{height:190px;border:1px solid var(--line);border-radius:6px;font-size:13px}
  .sqlmsg{font-size:12px;padding:8px 11px;border-radius:6px;border:1px solid;margin-bottom:10px}
  .sqlmsg.err{background:rgba(248,81,73,.12);border-color:var(--err);color:var(--err)}
  .sqlmsg.ok{background:rgba(63,185,80,.12);border-color:var(--ok);color:var(--ok)}
  .sqlmsg.warn{background:rgba(210,153,34,.12);border-color:var(--warn);color:var(--warn)}
  .msgtext{font-size:12.5px;margin-bottom:6px}
  .msgtext.err{color:var(--err)}
  .msgtext.ok{color:var(--ok)}
  .msgtext.warn{color:var(--warn)}
  .logview{background:var(--in);border:1px solid var(--line);border-radius:3px;padding:10px;font-family:ui-monospace,Consolas,monospace;font-size:13px;white-space:pre-wrap;max-height:62vh;overflow:auto;color:var(--mut)}
  .logview .log-fatal{color:var(--err);font-weight:700}
  .logview .log-warning{color:var(--warn);font-weight:600}
  .logview .log-deprecated{color:var(--mut);opacity:.6}
  .logview .log-notice{color:var(--ac)}
  .logview .log-info{color:var(--tx)}

  /* ---------- Selector de log con buscador (pestaña Logs) ---------- */
  .logpicker{position:relative;width:260px;max-width:100%}
  .logpicker-input{width:100%}
  .logpicker-list{position:absolute;z-index:20;top:calc(100% + 4px);left:0;right:0;max-height:280px;overflow:auto;background:var(--card);border:1px solid var(--line);border-radius:6px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
  .logpicker-opt{padding:7px 10px;cursor:pointer;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .logpicker-opt:hover,.logpicker-opt.on{background:rgba(110,168,254,.14);color:var(--ac)}
  .logpicker-opt.sel{font-weight:600}
  .logpicker-empty{padding:7px 10px;color:var(--mut);font-size:13px}
  .loglink{color:var(--ac);font-size:13px;text-decoration:none}
  .loglink:hover{text-decoration:underline}
  .loglink.active{color:var(--tx);font-weight:700;text-decoration:none;cursor:default}
  .loglink-sep{color:var(--mut);font-size:12px}

  /* ---------- Editor de codigo (tema propio de CodeMirror, sigue la paleta del panel) ---------- */
  #fileEditorHost .CodeMirror{height:100%;background:var(--in);color:var(--tx);border:1px solid var(--line);border-radius:6px;font-family:ui-monospace,Consolas,'Courier New',monospace;font-size:13px;line-height:1.5}
  .cm-s-lua.CodeMirror{background:var(--in);color:var(--tx)}
  .cm-s-lua .CodeMirror-gutters{background:var(--card);border-right:1px solid var(--line)}
  .cm-s-lua .CodeMirror-linenumber{color:var(--mut)}
  .cm-s-lua .CodeMirror-cursor{border-left:1px solid var(--tx)}
  .cm-s-lua div.CodeMirror-selected{background:rgba(110,168,254,.25)}
  .cm-s-lua .CodeMirror-activeline-background{background:rgba(110,168,254,.06)}
  .cm-s-lua .CodeMirror-matchingbracket{color:var(--ok) !important;outline:1px solid var(--ok)}
  .cm-s-lua .cm-keyword{color:var(--ac);font-weight:600}
  .cm-s-lua .cm-atom{color:var(--ac)}
  .cm-s-lua .cm-number{color:var(--warn)}
  .cm-s-lua .cm-def{color:var(--tx);font-weight:600}
  .cm-s-lua .cm-variable{color:var(--tx)}
  .cm-s-lua .cm-variable-2{color:var(--tx)}
  .cm-s-lua .cm-variable-3{color:var(--ac)}
  .cm-s-lua .cm-property{color:var(--tx)}
  .cm-s-lua .cm-operator{color:var(--mut)}
  .cm-s-lua .cm-comment{color:var(--mut);opacity:.8;font-style:italic}
  .cm-s-lua .cm-string{color:var(--ok)}
  .cm-s-lua .cm-string-2{color:var(--ok)}
  .cm-s-lua .cm-meta{color:var(--mut)}
  .cm-s-lua .cm-tag{color:var(--ac)}
  .cm-s-lua .cm-attribute{color:var(--warn)}
  .cm-s-lua .cm-qualifier{color:var(--warn)}
  .cm-s-lua .cm-builtin{color:var(--ac)}
  .cm-s-lua .cm-bracket{color:var(--mut)}
  .cm-s-lua .cm-error{color:var(--err)}
  .cm-s-lua .cm-invalidchar{color:var(--err)}

  /* ---------- Ficha de proyecto: arbol de archivos ---------- */
  .tree{font-size:13px;font-family:ui-monospace,Consolas,monospace;max-height:60vh;overflow:auto}
  .trow{display:flex;align-items:center;gap:6px;padding:3px 4px;border-radius:4px}
  .trow.tdir{cursor:pointer;user-select:none}
  .trow.tdir:hover{background:var(--in)}
  .trow .tchev{flex:0 0 auto;color:var(--mut);transition:transform .12s}
  .trow.tdir.open>.tchev{transform:rotate(90deg)}
  .trow .ticon{flex:0 0 auto;color:var(--mut)}
  .trow.tfile{color:var(--mut);cursor:pointer;user-select:none}
  .trow.tfile:hover{background:var(--in);color:var(--tx)}
  .tchildren{margin-left:9px;padding-left:11px;border-left:1px dashed var(--line)}
  .tnode-more{color:var(--mut);font-size:12px;padding:4px 0 4px 20px;font-style:italic}

  details{border:1px solid var(--line);border-radius:6px;margin-bottom:12px;background:var(--card);overflow:hidden}
  summary{padding:14px 18px;cursor:pointer;font-weight:700;font-size:16px;list-style:none;display:flex;align-items:center;gap:10px}
  summary::-webkit-details-marker{display:none}
  summary .op{font-size:12px;color:var(--mut);font-weight:500}
  summary .arrow{margin-left:auto;font-size:12px;color:var(--mut)}
  summary .arrow::after{content:'▼'}
  details[open] summary .arrow::after{content:'▲'}
  .pane{padding:4px 18px 18px}

  h2{font-size:12px;color:var(--mut);text-transform:uppercase;letter-spacing:.5px;margin:26px 0 12px}
  code{background:rgba(128,128,128,.16);padding:2px 6px;border-radius:3px;font-size:13px}
  .muted{color:var(--mut);font-size:12px}
  .inline{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap}

  /* ---------- Terminal ---------- */
  .termwrap{display:flex;flex-direction:column;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:var(--in)}
  .termbar{display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--card);border-bottom:1px solid var(--line);font-family:ui-monospace,Consolas,monospace;font-size:12px}
  .termout{padding:12px 14px;font-family:ui-monospace,Consolas,'Courier New',monospace;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word;overflow-y:auto;height:58vh;color:var(--tx)}
  .termin{display:flex;align-items:center;gap:8px;padding:8px 14px;border-top:1px solid var(--line);background:var(--card)}
  .termprompt{color:var(--ac);font-family:ui-monospace,Consolas,monospace;font-weight:700}
  .termcmd-input{flex:1;width:100%;background:transparent;border:none;color:var(--tx);font-family:ui-monospace,Consolas,monospace;font-size:13px;padding:4px 0}

  /* ---- Terminal global de la cabecera: barra persistente entre pestanas (no un modal --
     no oscurece la pagina ni bloquea clics fuera de su caja, igual que el runner "fijado a
     la derecha", cuya misma idea de anclaje reutiliza). Dos modos, elegidos por el usuario y
     recordados en localStorage: abajo a todo el ancho (por defecto) o a la derecha (calcado
     del modal del runner). ---- */
  .gtermbar{position:fixed;inset:0;z-index:90;display:flex;pointer-events:none;background:transparent}
  .gtermbar[hidden]{display:none}
  .gtermbar .gtermbox{pointer-events:auto;display:flex;flex-direction:column;background:var(--card);border:1px solid var(--line);box-shadow:0 -10px 30px rgba(0,0,0,.35)}
  .gtermbar .gtermbox .termwrap{flex:1;border:none;border-radius:0;background:transparent}
  .gtermbar .gtermbox .termout{flex:1 1 auto;height:auto!important}
  .gtermbar .termbar-top{display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid var(--line);flex:0 0 auto}
  .gtermbar .termbar-top b{font-size:12.5px}
  /* Abajo (por defecto al abrir): a todo el ancho, alto ajustable a mano (resize:vertical). */
  .gtermbar.dock-bottom{align-items:flex-end;justify-content:stretch}
  .gtermbar.dock-bottom .gtermbox{width:100%;height:320px;min-height:160px;max-height:82vh;border-width:1px 0 0;box-shadow:0 -10px 30px rgba(0,0,0,.35);resize:vertical;overflow:auto}
  /* Derecha: mismo patron que .modal-box.docked del runner (panel a pantalla completa
     pegado al borde, ancho fijo, sin poder redimensionar). */
  .gtermbar.dock-right{align-items:stretch;justify-content:flex-end}
  .gtermbar.dock-right .gtermbox{width:440px;height:100vh;border-width:0 0 0 1px;box-shadow:-12px 0 34px rgba(0,0,0,.4)}
  .termcmd-input:focus{outline:none}
  .termcmd-input:disabled{opacity:.5}
  .termout .a-prompt{color:var(--ac);font-weight:700}
  .termout .a-bold{font-weight:700}
  .a-k{color:#5b6172}.a-r{color:var(--err)}.a-g{color:var(--ok)}.a-y{color:var(--warn)}
  .a-b{color:var(--ac)}.a-m{color:#9b6efe}.a-c{color:#3fc7d4}.a-w{color:var(--tx)}
  .a-K{color:#8b90a0}.a-R{color:#ff7b72}.a-G{color:#56d364}.a-Y{color:#e3b341}
  .a-B{color:#79c0ff}.a-M{color:#d2a8ff}.a-C{color:#56d4dd}.a-W{color:#fff}

  footer{padding:8px 40px;color:var(--mut);font-size:12px;text-align:center;border-top:1px solid var(--line);flex-shrink:0}
</style>
</head>
<body>
  <header>
    <div class="logo"><img src="<?= $brandLogo ? '?brandlogo&t='.filemtime($brandLogo) : 'assets/logo.svg' ?>" alt="<?= e($brandName) ?>"></div>
    <div>
      <h1><?= e($brandName) ?><?php if ($luaVer !== ''): ?><a class="verchip<?= $updHay ? ' hay' : '' ?>" href="?tab=config#actualizaciones"
        title="<?= $updHay ? e($updDetras.' actualización(es) disponible(s) — pulsa para ver') : 'Versión de la plataforma' ?>"><?= e($luaVer) ?><?php if ($updHay): ?> &bull; <?= (int)$updDetras ?> nueva(s)<?php endif; ?></a><?php endif; ?></h1>
      <div class="sub">Servidor PHP local &middot; <?= count($sitesView) ?> proyecto(s) &middot; PHP: <?= e(implode(', ',$vers)) ?></div>
    </div>
    <div class="spacer"></div>
    <div class="badges">
      <span class="jstate ok">Apache UP</span>
      <span class="jstate <?= $watcherAlive?'run':'err' ?>"><?= $watcherAlive?'Watcher activo':'Watcher inactivo' ?></span>
      <a class="jstate orange" href="http://<?= e($phpmyadminDom) ?>/" target="_blank" title="Abrir phpMyAdmin">phpMyAdmin &#8599;</a>
    </div>
    <?php if ($termOnHdr): ?>
      <button type="button" class="iconbtn gtermbtn" id="gtermToggleBtn" title="Terminal" aria-label="Mostrar/ocultar la terminal">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="6 9 10 12 6 15"/><line x1="12" y1="15" x2="18" y2="15"/></svg>
      </button>
    <?php else: ?>
      <a class="iconbtn gtermbtn" href="?tab=config" title="Terminal desactivada — actívala en Configuración del servidor" aria-label="Terminal desactivada">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="6 9 10 12 6 15"/><line x1="12" y1="15" x2="18" y2="15"/></svg>
      </a>
    <?php endif; ?>
    <button type="button" class="iconbtn restartbtn" title="Reiniciar el servidor" aria-label="Reiniciar el servidor" onclick="luaAskRestart()">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/></svg>
    </button>
    <button type="button" class="iconbtn powerbtn" title="Apagar el servidor" aria-label="Apagar el servidor" onclick="luaAskShutdown()">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
    </button>
  </header>

  <!-- Modal de confirmación de apagado -->
  <div id="offModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseShutdown()">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="offTitle">
      <div class="modal-ic modal-ic-off">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
      </div>
      <h3 id="offTitle">¿Apagar el servidor?</h3>
      <p class="modal-tx">Se detendrán Apache, el watcher, Mailpit y MySQL. Los sitios dejarán de responder hasta que vuelvas a arrancar con <code>.\lua.ps1 start</code>.</p>
      <form method="post" class="modal-actions">
        <input type="hidden" name="action" value="shutdown">
        <button type="button" class="btn ghost" onclick="luaCloseShutdown()">Cancelar</button>
        <button type="submit" class="btn danger">Sí, apagar</button>
      </form>
    </div>
  </div>
  <!-- Modal de confirmación de reinicio -->
  <div id="rebootModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseRestart()">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="rebootTitle">
      <div class="modal-ic modal-ic-info">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/></svg>
      </div>
      <h3 id="rebootTitle">¿Reiniciar el servidor?</h3>
      <p class="modal-tx">Apache se detendrá y volverá a arrancar en unos segundos. Los sitios no responderán durante el reinicio. La página se recargará sola al terminar.</p>
      <form method="post" class="modal-actions">
        <input type="hidden" name="action" value="restart">
        <button type="button" class="btn ghost" onclick="luaCloseRestart()">Cancelar</button>
        <button type="submit" class="btn">Sí, reiniciar</button>
      </form>
    </div>
  </div>
  <script>
    function luaAskShutdown(){ document.getElementById('offModal').hidden=false; document.addEventListener('keydown',luaEscOff); }
    function luaCloseShutdown(){ document.getElementById('offModal').hidden=true; document.removeEventListener('keydown',luaEscOff); }
    function luaAskRestart(){ document.getElementById('rebootModal').hidden=false; document.addEventListener('keydown',luaEscReboot); }
    function luaCloseRestart(){ document.getElementById('rebootModal').hidden=true; document.removeEventListener('keydown',luaEscReboot); }
    function luaEscOff(e){ if(e.key==='Escape') luaCloseShutdown(); }
    function luaEscReboot(e){ if(e.key==='Escape') luaCloseRestart(); }
  </script>

  <div class="tabbar">
    <div class="tabs">
      <a href="?tab=proyectos" class="<?= ($tab==='proyectos'||$tab==='proyecto')?'on':'' ?>">Proyectos</a>
      <a href="?tab=php" class="<?= $tab==='php'?'on':'' ?>">Versiones PHP</a>
      <?php /* SQL Server y Redis no tienen pestana propia: se entra por sus enlaces en
               "Bases de datos", que queda resaltada tambien dentro de esos gestores. */ ?>
      <a href="?tab=bd" class="<?= in_array($tab,['bd','sqlsrv','redis'],true)?'on':'' ?>">Bases de datos</a>
      <a href="?tab=procs" class="<?= $tab==='procs'?'on':'' ?>">Procesos</a>
      <a href="?tab=sysmon" class="<?= $tab==='sysmon'?'on':'' ?>">Recursos</a>
      <a href="?tab=doctor" class="<?= $tab==='doctor'?'on':'' ?>">Doctor</a>
      <?php if (docker_installed()): ?>
        <a href="?tab=docker" class="<?= $tab==='docker'?'on':'' ?>">Docker</a>
      <?php endif; ?>
      <a href="?tab=logs" class="<?= $tab==='logs'?'on':'' ?>">Logs</a>
      <a href="?tab=terminal" class="<?= $tab==='terminal'?'on':'' ?>">Terminal</a>
      <a href="?tab=config" class="<?= $tab==='config'?'on':'' ?>">Configuración del servidor</a>
      <a href="?tab=docs" class="<?= $tab==='docs'?'on':'' ?>">Documentación</a>
    </div>
  </div>

  <div class="content">

  <?php if ($mtext): ?>
    <div class="banner <?= e($mtype) ?>">
      <?= e($mtext) ?>
      <?php if ($mtype==='applied'): ?> <span class="muted">— la página se actualizará sola en cuanto el servidor responda.</span><?php endif; ?>
    </div>
  <?php endif; ?>


  <?php // ---------------- Vistas por pestaña (tools/dashboard/views/) ---------------- ?>
  <?php include __DIR__.'/views/proyectos.php'; ?>
  <?php include __DIR__.'/views/proyecto.php'; ?>
  <?php include __DIR__.'/views/php.php'; ?>
  <?php include __DIR__.'/views/logs.php'; ?>
  <?php include __DIR__.'/views/config.php'; ?>
  <?php include __DIR__.'/views/bd.php'; ?>
  <?php include __DIR__.'/views/sysmon.php'; ?>
  <?php include __DIR__.'/views/doctor.php'; ?>
  <?php include __DIR__.'/views/procs.php'; ?>
  <?php include __DIR__.'/views/redis.php'; ?>
  <?php include __DIR__.'/views/sqlsrv.php'; ?>
  <?php include __DIR__.'/views/docker.php'; ?>
  <?php include __DIR__.'/views/terminal.php'; ?>
  <?php include __DIR__.'/views/docs.php'; ?>

  </div>

  <?php if ($termOnHdr): ?>
  <!-- Terminal global: unica en todo el panel (no depende de la pestana activa), a
       diferencia del runner de abajo que solo existe en Proyectos. Sesion propia (prefijo
       "gterm"), independiente de la de la pestana Terminal si tambien esta abierta. -->
  <div id="gtermBar" class="gtermbar dock-bottom" hidden>
    <div class="gtermbox">
      <div class="termbar-top">
        <b>Terminal</b>
        <div class="spacer"></div>
        <button type="button" class="lockbtn" id="gtermDockBtn" title="Fijar a la derecha" aria-label="Fijar a la derecha">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="15" y1="4" x2="15" y2="20"/></svg>
        </button>
        <button type="button" class="lockbtn" id="gtermCloseBtn" title="Cerrar" aria-label="Cerrar">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <?= render_terminal_widget('gterm', term_default_cwd($ROOT), false) ?>
    </div>
  </div>
  <script>
  (function(){
    var bar=document.getElementById('gtermBar'), box=bar.querySelector('.gtermbox'),
        toggleBtn=document.getElementById('gtermToggleBtn'), dockBtn=document.getElementById('gtermDockBtn'),
        closeBtn=document.getElementById('gtermCloseBtn'), input=document.getElementById('gtermcmd'),
        content=document.querySelector('.content');
    var OPEN_KEY='lua_gterm_open', DOCK_KEY='lua_gterm_dock';
    var ro = null;

    // Reserva espacio abajo en el contenido SOLO en modo "abajo" (en modo "derecha" el panel
    // se superpone al contenido sin desplazarlo, igual que hace el runner fijado a la derecha
    // -- ese solapamiento ya es el comportamiento aceptado en este panel).
    function reserveSpace(){
      if (bar.hidden || !bar.classList.contains('dock-bottom')) { content.style.paddingBottom = ''; return; }
      content.style.paddingBottom = box.getBoundingClientRect().height + 'px';
    }
    if (window.ResizeObserver) { ro = new ResizeObserver(reserveSpace); ro.observe(box); }

    function setDock(mode){
      bar.classList.toggle('dock-bottom', mode==='right' ? false : true);
      bar.classList.toggle('dock-right', mode==='right');
      dockBtn.classList.toggle('on', mode==='right');
      try{ localStorage.setItem(DOCK_KEY, mode); }catch(e){}
      reserveSpace();
    }
    function setOpen(on, opts){
      opts = opts || {};
      bar.hidden = !on;
      toggleBtn.classList.toggle('on', on);
      try{ localStorage.setItem(OPEN_KEY, on?'1':'0'); }catch(e){}
      reserveSpace();
      if (on && !opts.silent && input) { setTimeout(function(){ input.focus(); }, 30); }
    }
    toggleBtn.addEventListener('click', function(){ setOpen(bar.hidden); });
    closeBtn.addEventListener('click', function(){ setOpen(false); });
    dockBtn.addEventListener('click', function(){ setDock(bar.classList.contains('dock-right') ? 'bottom' : 'right'); });
    document.addEventListener('keydown', function(e){ if (e.key==='Escape' && !bar.hidden) setOpen(false); });

    // Restaurar preferencia entre navegaciones (cada pestana es una recarga de pagina
    // entera): si el usuario la dejo abierta, sigue abierta -- sin robar el foco, que seria
    // molesto en cada cambio de pestana.
    var dockSaved='bottom'; try{ dockSaved = localStorage.getItem(DOCK_KEY) || 'bottom'; }catch(e){}
    setDock(dockSaved);
    var openSaved='0'; try{ openSaved = localStorage.getItem(OPEN_KEY) || '0'; }catch(e){}
    if (openSaved==='1') setOpen(true, {silent:true});
  })();
  </script>
  <?php endif; ?>

  <?php $luaRunnerOn = is_file($ROOT.'/config/terminal.on'); if ($luaRunnerOn && in_array($tab, ['proyectos','proyecto'], true)): $runPresets = run_presets_load($ROOT); ?>
  <!-- Modal: runner de Composer/NPM/Artisan por proyecto (reutiliza los endpoints de la Terminal).
       Compartido entre la lista de proyectos y la ficha de un proyecto: un solo modal, un solo
       listener delegado, sin importar desde que pestana se abrio. -->
  <div id="runnerModal" class="modal-overlay" hidden onclick="if(event.target===this)luaCloseRunner()">
    <div class="modal-box" role="dialog" aria-modal="true" style="max-width:640px;text-align:left">
      <div class="row" style="margin-bottom:10px">
        <h3 id="runnerTitle" style="margin:0;font-size:16px">Ejecutar</h3>
        <div class="spacer"></div>
        <a href="javascript:void(0)" id="runnerStop" class="runlink off">Detener</a>
        <button type="button" class="lockbtn" id="runnerDockBtn" title="Fijar a la derecha" aria-label="Fijar a la derecha">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="15" y1="4" x2="15" y2="20"/></svg>
        </button>
        <button type="button" class="lockbtn" onclick="luaCloseRunner()" title="Cerrar" aria-label="Cerrar">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div id="runnerBtns" class="row" style="gap:6px 16px;margin-bottom:10px;flex-wrap:wrap"></div>
      <div class="row" style="gap:6px;margin-bottom:10px">
        <input type="text" id="runnerCustomCmd" placeholder="Comando personalizado, p.ej. npm run dev" style="flex:1" maxlength="200">
        <button type="button" class="btn-git sm" id="runnerRunBtn" title="Ejecutar una vez, sin guardarlo">Ejecutar</button>
        <button type="button" class="btn ghost sm" id="runnerAddBtn" title="Guardar como acceso rápido y ejecutarlo">+ Guardar</button>
      </div>
      <div id="runnerOut" class="termout" style="height:280px;border:1px solid var(--line);border-radius:6px;background:var(--in)"></div>
      <div class="row" style="margin-top:6px;justify-content:flex-end">
        <a href="javascript:void(0)" id="runnerClear" class="runlink">Limpiar consola</a>
      </div>
    </div>
  </div>
  <script>
    (function(){
      var modal=document.getElementById('runnerModal'), title=document.getElementById('runnerTitle'),
          btnsEl=document.getElementById('runnerBtns'), out=document.getElementById('runnerOut'),
          stopBtn=document.getElementById('runnerStop'), clearBtn=document.getElementById('runnerClear'),
          addBtn=document.getElementById('runnerAddBtn'), runOnceBtn=document.getElementById('runnerRunBtn'),
          customInput=document.getElementById('runnerCustomCmd'),
          dockBtn=document.getElementById('runnerDockBtn'), box=modal.querySelector('.modal-box');
      // runs: comandos en marcha por proyecto (clave = ruta). Sobreviven a cerrar el
      // modal -- el comando sigue en marcha en segundo plano (proceso propio de Windows,
      // WScript.Shell.Run, independiente de esta página) y el play de la card se marca
      // en verde mientras dure.
      var runs={}, curPath=null, curName=null, curPhpVer=null, curBuiltins=[];
      var savedPresets=<?= json_encode($runPresets, JSON_UNESCAPED_SLASHES) ?>;
      var LS_KEY='lua_runner_jobs';
      var DOCK_KEY='lua_dock_runner';
      // "Fijar a la derecha": recuerda la preferencia entre aperturas (localStorage), no
      // solo mientras el modal esta abierto -- si el usuario lo fijo la ultima vez, lo
      // vuelve a abrir fijado.
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
      function setButtons(disabled){ Array.from(btnsEl.querySelectorAll('button')).forEach(function(b){ b.disabled=disabled; }); }
      function findBtn(p){ return Array.from(document.querySelectorAll('.lua-runbtn')).find(function(b){ return b.dataset.path===p; }) || null; }
      function markBtn(p, on){ var b=findBtn(p); if(b) b.classList.toggle('running', !!on); }
      function saveJobs(){
        var o={};
        Object.keys(runs).forEach(function(p){ var r=runs[p]; if(r.runid) o[p]={sid:r.sid,runid:r.runid,name:r.name,phpVer:r.phpVer,cmd:r.cmd}; });
        try{ localStorage.setItem(LS_KEY, JSON.stringify(o)); }catch(e){}
      }
      function renderOut(p){ if(curPath===p && !modal.hidden){ out.innerHTML=runs[p].html; out.scrollTop=out.scrollHeight; } }

      // Icono de terminal (estatico, sin datos de usuario -> innerHTML es seguro aqui).
      var TERM_ICON='<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>';
      // El texto del comando se anade aparte con createTextNode (nunca con innerHTML):
      // los comandos guardados son texto libre del usuario y podrian llevar '<'/'>'/comillas
      // que romperian el HTML (o un atributo onclick="..." armado a mano) si se insertaran tal cual.
      function runlink(text, onClick, title, danger){
        var b=document.createElement('button');
        b.type='button'; b.className='runlink'+(danger?' runlink-danger':''); if(title) b.title=title;
        b.innerHTML=TERM_ICON; b.appendChild(document.createTextNode(text));
        b.onclick=onClick;
        return b;
      }
      function renderBtns(){
        btnsEl.innerHTML='';
        curBuiltins.forEach(function(p){
          var danger=!!p[2];
          btnsEl.appendChild(runlink(p[0], function(){
            if(danger && !confirm('¿Seguro que quieres ejecutar "'+p[1]+'" en '+curName+'? No se puede deshacer.')) return;
            luaRunPreset(p[1]);
          }, danger?'Accion destructiva':undefined, danger));
        });
        savedPresets.forEach(function(cmd){
          var wrap=document.createElement('span');
          wrap.className='runlink-wrap';
          wrap.appendChild(runlink(cmd, function(){ luaRunPreset(cmd); }, 'Ejecutar'));
          var d=document.createElement('button');
          d.type='button'; d.className='runlink-del'; d.textContent='×'; d.title='Eliminar acceso rapido';
          d.onclick=function(){ luaDelPreset(cmd); };
          wrap.appendChild(d);
          btnsEl.appendChild(wrap);
        });
      }

      function finishRun(p){
        delete runs[p];
        saveJobs();
        markBtn(p, false);
        if(curPath===p){ stopBtn.classList.add('off'); setButtons(false); }
      }
      function pollRun(p){
        var r=runs[p]; if(!r) return;
        fetch('?action=term_poll&sid='+r.sid+'&runid='+r.runid+'&off='+r.off)
        .then(function(resp){ return resp.json(); })
        .then(function(j){
          r=runs[p]; if(!r) return;
          if(j.error){ r.html+='<span class="a-r">'+esc(j.error)+'</span>\n'; renderOut(p); finishRun(p); return; }
          if(j.data){ r.html+=ansiToHtml(j.data); r.off=j.off; }
          if(j.done){
            if(r.html && !r.html.endsWith('\n')) r.html+='\n';
            r.html+='<span class="'+(j.code?'a-r':'a-g')+'">[salida '+(j.code||0)+']</span>\n';
            renderOut(p); finishRun(p);
          } else { renderOut(p); setTimeout(function(){ pollRun(p); }, 500); }
        }).catch(function(){
          r=runs[p]; if(!r) return;
          r.fails=(r.fails||0)+1;
          if(r.fails>=5){ r.html+='<span class="a-r">[error de red]</span>\n'; renderOut(p); finishRun(p); return; }
          setTimeout(function(){ pollRun(p); }, 700);
        });
      }
      function startRun(p, name, phpVer, cmd){
        if(runs[p]) return;
        var sid=(function(){var a=new Uint8Array(10);crypto.getRandomValues(a);return Array.from(a).map(b=>b.toString(16).padStart(2,'0')).join('');})();
        runs[p]={sid:sid, runid:null, name:name, phpVer:phpVer, cmd:cmd, off:0,
          html:'<span class="a-prompt">&gt; </span>'+esc(cmd)+'\n'};
        markBtn(p, true);
        if(curPath===p){ setButtons(true); stopBtn.classList.remove('off'); }
        renderOut(p);
        var full='cd /d "'+p+'" && '+cmd;
        fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body:'action=term_run&sid='+sid+'&php='+encodeURIComponent(phpVer||'')+'&cmd='+encodeURIComponent(full)})
        .then(function(resp){ return resp.json(); })
        .then(function(j){
          var r=runs[p]; if(!r) return;
          if(j.error){ r.html+='<span class="a-r">'+esc(j.error)+'</span>\n'; renderOut(p); finishRun(p); return; }
          r.runid=j.runid; saveJobs(); pollRun(p);
        }).catch(function(){
          var r=runs[p]; if(!r) return;
          r.html+='<span class="a-r">[no se pudo lanzar]</span>\n'; renderOut(p); finishRun(p);
        });
      }

      window.luaRunPreset=function(cmd){
        if(!curPath || runs[curPath]) return;
        startRun(curPath, curName, curPhpVer, cmd);
      };
      stopBtn.onclick=function(){
        var r=curPath && runs[curPath];
        if(!r || !r.runid) return;
        stopBtn.classList.add('off');
        fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body:'action=term_stop&sid='+r.sid+'&runid='+r.runid}).then(function(){});
      };
      // Solo vacia el papel visible (y el acumulado en memoria, para que el proximo
      // poll no lo vuelva a pintar entero): no toca el comando que siga en marcha.
      clearBtn.onclick=function(){
        out.innerHTML='';
        if(curPath && runs[curPath]) runs[curPath].html='';
      };

      function luaDelPreset(cmd){
        fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body:'action=run_preset_del&cmd='+encodeURIComponent(cmd)})
        .then(r=>r.json()).then(function(j){
          if(j.presets){ savedPresets=j.presets; renderBtns(); }
        });
      }
      // Ejecuta el comando del cuadro tal cual, sin pasar por run_preset_add: para
      // comandos puntuales que no hace falta guardar como acceso rapido.
      runOnceBtn.onclick=function(){
        var cmd=customInput.value.trim();
        if(!cmd || !curPath || runs[curPath]) return;
        startRun(curPath, curName, curPhpVer, cmd);
      };
      addBtn.onclick=function(){
        var cmd=customInput.value.trim();
        if(!cmd || (curPath && runs[curPath])) return;
        addBtn.disabled=true;
        fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body:'action=run_preset_add&cmd='+encodeURIComponent(cmd)})
        .then(r=>r.json()).then(function(j){
          addBtn.disabled=false;
          if(j.error){ out.insertAdjacentHTML('beforeend','<span class="a-r">'+esc(j.error)+'</span>\n'); return; }
          savedPresets=j.presets; customInput.value=''; renderBtns();
          luaRunPreset(cmd);
        }).catch(function(){ addBtn.disabled=false; });
      };
      customInput.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); addBtn.click(); } });

      window.luaOpenRunner=function(name, projectPath, hasComposer, hasNpm, phpVersion, hasArtisan){
        curPath=projectPath; curName=name; curPhpVer=phpVersion||null;
        title.textContent='Ejecutar en '+name;
        customInput.value='';
        curBuiltins=[];
        if(hasComposer){ curBuiltins.push(['composer install','composer install'],['composer update','composer update']); }
        if(hasNpm){ curBuiltins.push(['npm install','npm install'],['npm run build','npm run build']); }
        if(hasArtisan){
          curBuiltins.push(
            ['artisan migrate','php artisan migrate'],
            ['artisan migrate:fresh --seed','php artisan migrate:fresh --seed', true],
            ['artisan optimize:clear','php artisan optimize:clear'],
            ['artisan route:list','php artisan route:list'],
            ['artisan queue:work','php artisan queue:work']
          );
        }
        renderBtns();
        var r=runs[curPath];
        if(r){ out.innerHTML=r.html; out.scrollTop=out.scrollHeight; setButtons(true); stopBtn.classList.remove('off'); }
        else { out.innerHTML=''; setButtons(false); stopBtn.classList.add('off'); }
        modal.hidden=false;
        document.addEventListener('keydown', luaEscRunner);
      };
      // Delegado (no onclick inline): la ruta del proyecto lleva backslashes, y un
      // atributo onclick="...'C:\personal\...'" se compila como CODIGO JS, donde \p, \L,
      // \w, \a etc. son secuencias de escape que el navegador consume en silencio (la
      // barra desaparece) — "C:\personal\LuaServer" acababa llegando como
      // "C:personalLuaServer" y el "cd /d" fallaba siempre. Con data-* (texto plano, sin
      // parseo JS) el path llega intacto.
      document.addEventListener('click', function(e){
        var btn = e.target.closest('.lua-runbtn');
        if (!btn) return;
        luaOpenRunner(btn.dataset.name, btn.dataset.path, btn.dataset.composer==='1', btn.dataset.npm==='1', btn.dataset.php, btn.dataset.artisan==='1');
      });
      // Cerrar el modal ya NO mata el comando: sigue en marcha en segundo plano (el
      // play de la card queda en verde) y se puede reabrir luego para verlo o pararlo.
      window.luaCloseRunner=function(){
        modal.hidden=true;
        document.removeEventListener('keydown', luaEscRunner);
      };
      function luaEscRunner(e){ if(e.key==='Escape') luaCloseRunner(); }

      // Reengancha, al cargar la página, los comandos que quedaron corriendo en segundo
      // plano de una carga anterior (recarga, cambio de pestaña...): el proceso de
      // Windows es independiente de esta página (WScript.Shell.Run), solo hace falta
      // recuperar el sid/runid guardados para retomar el polling.
      (function reconnect(){
        var saved={};
        try{ saved=JSON.parse(localStorage.getItem(LS_KEY)||'{}')||{}; }catch(e){}
        Object.keys(saved).forEach(function(p){
          var j=saved[p];
          if(!j||!j.sid||!j.runid) return;
          runs[p]={sid:j.sid, runid:j.runid, name:j.name, phpVer:j.phpVer, cmd:j.cmd, off:0,
            html:'<span class="a-prompt">&gt; </span>'+esc(j.cmd||'')+'\n'};
          markBtn(p, true);
          pollRun(p);
        });
      })();
    })();
  </script>
  <?php endif; ?>

  <script>
    // Control de "elegir archivo" a medida (import de backups .sql): actualiza el nombre
    // mostrado y marca el control como "con archivo" para el estilo solido del borde.
    function luaFilePickName(input){
      var label = input.closest('.filepick');
      var nameEl = label.querySelector('.filepick-name');
      var f = input.files && input.files[0];
      nameEl.textContent = f ? f.name : 'Elegir .sql…';
      label.classList.toggle('has-file', !!f);
    }
    // Dialogo nativo "Elegir carpeta": pide al watcher que lo abra (ver ajax=pickfolder_start/
    // poll en el backend) y espera el resultado con polling. Puede tardar ~1s en aparecer
    // (el watcher revisa la peticion cada segundo), y se espera hasta 5 minutos por si el
    // usuario tarda en navegar hasta la carpeta correcta.
    function luaPickFolder(btn, inputId){
      var input = document.getElementById(inputId);
      var orig = btn.textContent;
      btn.disabled = true; btn.textContent = 'Abriendo…';
      function fail(msg){ btn.disabled = false; btn.textContent = orig; if (msg) alert(msg); }
      fetch('?ajax=pickfolder_start').then(function(r){ return r.json(); }).then(function(data){
        if (!data || data.error) { fail(data && data.error ? data.error : 'No se pudo pedir el selector de carpetas.'); return; }
        var tries = 0, maxTries = 430; // ~5 min a 700ms
        var iv = setInterval(function(){
          tries++;
          fetch('?ajax=pickfolder_poll&id='+encodeURIComponent(data.id)).then(function(r){ return r.json(); }).then(function(d){
            if (d.status === 'pending') {
              if (tries >= maxTries) { clearInterval(iv); fail('El watcher no respondió a tiempo.'); }
              return;
            }
            clearInterval(iv);
            btn.disabled = false; btn.textContent = orig;
            if (d.status === 'done' && d.path) { input.value = d.path; }
            else if (d.status === 'error') { alert('Error al abrir el selector: ' + (d.msg || 'desconocido')); }
          }).catch(function(){ clearInterval(iv); fail('Se perdió la conexión con el panel.'); });
        }, 700);
      }).catch(function(){ fail('No se pudo contactar con el panel.'); });
    }
  </script>

  <footer><?= e($brandName) ?> &middot; Apache + mod_fcgid &middot; panel solo accesible desde esta máquina</footer>

  <!-- Loader global: se muestra al pulsar cualquier boton/enlace que dispare una accion real
       (envio de formulario o navegacion), con el texto del propio boton/enlace pulsado. -->
  <div id="globalLoader" class="loader-overlay" hidden>
    <div class="loader-box">
      <div class="loader-spin"></div>
      <div class="loader-tx" id="globalLoaderText">Procesando…</div>
    </div>
  </div>
  <script>
    (function(){
      var overlay = document.getElementById('globalLoader');
      var textEl = document.getElementById('globalLoaderText');
      function labelFor(el){
        if (!el) return null;
        if (el.dataset && el.dataset.loadingText) return el.dataset.loadingText;
        var aria = el.getAttribute('aria-label') || el.getAttribute('title');
        if (aria) return aria;
        var txt = (el.textContent || '').replace(/\s+/g, ' ').trim();
        return txt || null;
      }
      var hideTimer = null;
      function show(el){
        textEl.textContent = labelFor(el) || 'Procesando…';
        overlay.hidden = false;
        // Red de seguridad: si por lo que sea la pagina no llega a navegar (error de
        // servidor, validacion que bloquea el envio tras mostrar el loader, etc.) el
        // aviso no debe quedarse pegado para siempre.
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function(){ overlay.hidden = true; }, 20000);
      }
      document.addEventListener('submit', function(e){
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || form.matches('.no-loader')) return;
        // Fase de burbuja + defaultPrevented: si el onsubmit del formulario cancelo el envio
        // (p.ej. porque abre un modal de confirmacion, o envia por AJAX), NO mostramos el
        // loader — antes saltaba en captura, antes de la cancelacion, y la pastilla se
        // quedaba flotando sobre el modal de confirmar.
        if (e.defaultPrevented) return;
        var submitter = e.submitter || form.querySelector('button[type=submit], button:not([type])');
        show(submitter || form);
      }, false);
      document.addEventListener('click', function(e){
        var a = e.target.closest('a[href]');
        if (!a || a.matches('.no-loader')) return;
        if (a.target === '_blank' || a.hasAttribute('download')) return;
        var href = a.getAttribute('href') || '';
        if (!href || href[0] === '#' || href.indexOf('javascript:') === 0) return;
        show(a);
      }, true);
      // El navegador puede restaurar la pagina desde el bfcache (boton Atras/Adelante)
      // con el DOM congelado tal cual estaba al salir, incluido el loader visible: hay
      // que ocultarlo siempre que la pagina se muestra, sea carga nueva o restaurada.
      window.addEventListener('pageshow', function(){
        clearTimeout(hideTimer);
        overlay.hidden = true;
      });
    })();
  </script>
</body>
</html>
