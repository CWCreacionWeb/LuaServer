<?php
// ============================================================
//  lua-server :: panel (solo PHP)
// ============================================================
$root   = dirname(__DIR__, 2);
$cfgRaw = @file_get_contents($root . '/config/sites.json');
if ($cfgRaw !== false) { $cfgRaw = preg_replace('/^\xEF\xBB\xBF/', '', $cfgRaw); } // quitar BOM
$cfg    = $cfgRaw ? json_decode($cfgRaw, true) : null;
if (!is_array($cfg)) { $cfg = ['sites' => [], 'tld' => 'lua.test', 'defaultPhp' => '8.4']; }
$tld    = $cfg['tld'] ?? 'lua.test';
$sites  = $cfg['sites'] ?? [];

$phpBase = $root . '/bin/php';
$phpVers = [];
if (is_dir($phpBase)) {
    foreach (scandir($phpBase) as $d) {
        if ($d[0] === '.') continue;
        if (is_file("$phpBase/$d/php-cgi.exe")) $phpVers[] = $d;
    }
    natsort($phpVers);
}
$curPhp = PHP_VERSION;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>lua-server</title>
<style>
  :root{ --bg:#0f1117; --card:#1a1d27; --line:#2a2f3d; --tx:#e6e8ee; --mut:#8b90a0; --ac:#6ea8fe; --ok:#3fb950; }
  @media (prefers-color-scheme:light){ :root{ --bg:#f4f6fb; --card:#fff; --line:#e3e7f0; --tx:#1a1d27; --mut:#5b6172; --ac:#2b6cff; } }
  *{box-sizing:border-box} body{margin:0;font-family:system-ui,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--tx)}
  .wrap{max-width:960px;margin:0 auto;padding:40px 20px}
  header{display:flex;align-items:center;gap:16px;margin-bottom:8px}
  .logo{width:52px;height:52px;border-radius:12px;background:linear-gradient(135deg,var(--ac),#9b6efe);
        display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;color:#fff;letter-spacing:1px}
  h1{margin:0;font-size:22px} .sub{color:var(--mut);font-size:14px;margin-top:2px}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-top:24px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;transition:.15s}
  .card:hover{border-color:var(--ac);transform:translateY(-2px)}
  .card a{color:var(--tx);text-decoration:none;font-weight:600;font-size:16px}
  .tag{display:inline-block;font-size:12px;color:var(--ac);background:rgba(110,168,254,.12);padding:2px 8px;border-radius:999px;margin-top:8px}
  .bar{display:flex;flex-wrap:wrap;gap:10px;margin-top:20px}
  .pill{background:var(--card);border:1px solid var(--line);border-radius:999px;padding:6px 14px;font-size:13px;color:var(--mut)}
  .pill b{color:var(--tx)}
  .dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px;vertical-align:middle;background:var(--ok)}
  .empty{background:var(--card);border:1px dashed var(--line);border-radius:14px;padding:30px;text-align:center;color:var(--mut);margin-top:24px}
  code{background:rgba(128,128,128,.15);padding:2px 6px;border-radius:6px;font-size:13px}
  h2{font-size:14px;color:var(--mut);text-transform:uppercase;letter-spacing:.5px;margin:34px 0 0}
  footer{margin-top:40px;color:var(--mut);font-size:12px;text-align:center}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="logo">LUA</div>
    <div>
      <h1>lua-server</h1>
      <div class="sub">Servidor PHP local &middot; multi-versi&oacute;n &middot; <?= count($sites) ?> proyecto(s)</div>
    </div>
  </header>

  <div class="bar">
    <span class="pill"><span class="dot"></span>Apache <b>en linea</b></span>
    <span class="pill">Panel sobre <b>PHP <?= htmlspecialchars($curPhp) ?></b></span>
    <span class="pill">PHP disponibles: <b><?= htmlspecialchars(implode(', ', $phpVers) ?: '—') ?></b></span>
  </div>

  <h2>Proyectos</h2>
  <?php if (!$sites): ?>
    <div class="empty">
      A&uacute;n no hay proyectos.<br><br>
      Crea uno desde PowerShell:<br>
      <code>.\lua.ps1 add-site micliente 8.4</code>
    </div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($sites as $name => $info):
            $ver = is_array($info) ? ($info['php'] ?? '?') : $info; ?>
        <div class="card">
          <a href="http://<?= htmlspecialchars($name) ?>.<?= htmlspecialchars($tld) ?>" target="_blank"><?= htmlspecialchars($name) ?> &rarr;</a>
          <div class="sub" style="font-size:12px;margin-top:4px"><?= htmlspecialchars($name) ?>.<?= htmlspecialchars($tld) ?></div>
          <span class="tag">PHP <?= htmlspecialchars($ver) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <footer>lua-server &middot; Apache + mod_fcgid &middot; gestiona con <code>.\lua.ps1</code></footer>
</div>
</body>
</html>
