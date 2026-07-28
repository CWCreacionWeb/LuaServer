<?php
    if ($action === 'shutdown') {
        // Apagar el servidor. Como al parar Apache muere este propio PHP, lanzamos
        // 'lua.ps1 stop' en un proceso desatendido (con un respiro para que esta
        // respuesta llegue al navegador) y devolvemos una página de despedida.
        $luaWin = str_replace('/', '\\', $ROOT).'\\lua.ps1';
        $cmdf = $ROOT.'/tmp/_shutdown.cmd';
        @mkdir($ROOT.'/tmp', 0777, true);
        $wr  = "@echo off\r\n";
        $wr .= "ping -n 3 127.0.0.1 >NUL\r\n";  // ~2s para que el navegador reciba la página
        $wr .= "powershell -NoProfile -ExecutionPolicy Bypass -File \"".$luaWin."\" stop\r\n";
        @file_put_contents($cmdf, $wr);
        try { $sh = new COM('WScript.Shell'); $sh->Run('cmd /c "'.str_replace('/', '\\', $cmdf).'"', 0, false); } catch (Throwable $e) {}
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><title>lua-server — apagando</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<style>
  :root{ --bg:#0f1117; --card:#1a1d27; --line:#2a2f3d; --tx:#e6e8ee; --mut:#8b90a0; --ac:#6ea8fe; }
  @media (prefers-color-scheme:light){ :root{ --bg:#f4f6fb; --card:#fff; --line:#e3e7f0; --tx:#1a1d27; --mut:#5b6172; --ac:#2b6cff; } }
  html,body{height:100%;margin:0}
  body{background:var(--bg);color:var(--tx);font-family:system-ui,'Segoe UI',Roboto,sans-serif;display:flex;align-items:center;justify-content:center}
  .box{text-align:center;max-width:420px;padding:32px}
  .ic{width:56px;height:56px;border-radius:999px;background:rgba(139,144,160,.15);color:var(--mut);display:flex;align-items:center;justify-content:center;margin:0 auto 18px}
  h1{font-size:20px;margin:0 0 10px}
  p{color:var(--mut);font-size:14px;line-height:1.5;margin:0 0 6px}
  code{background:rgba(128,128,128,.16);padding:2px 7px;border-radius:5px;font-size:13px}
</style></head><body>
  <div class="box">
    <div class="ic"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg></div>
    <h1>Apagando el servidor…</h1>
    <p>Apache, el watcher, Mailpit y MySQL se están deteniendo. Esta página ya no responderá.</p>
    <p style="margin-top:14px">Para volver a arrancar, en una terminal:<br><code>.\lua.ps1 start</code></p>
  </div>
</body></html>
        <?php
        exit;
    }

    if ($action === 'restart') {
        // Reiniciar Apache. El proceso PHP muere al reiniciar; lo lanzamos desatendido
        // y devolvemos una página que se recarga sola cuando Apache vuelve.
        $luaWin = str_replace('/', '\\', $ROOT).'\\lua.ps1';
        $cmdf = $ROOT.'/tmp/_restart.cmd';
        @mkdir($ROOT.'/tmp', 0777, true);
        $wr  = "@echo off\r\n";
        $wr .= "ping -n 2 127.0.0.1 >NUL\r\n";  // deja llegar esta respuesta antes de tumbar Apache
        $wr .= "powershell -NoProfile -ExecutionPolicy Bypass -File \"".$luaWin."\" restart\r\n";
        @file_put_contents($cmdf, $wr);
        try { $sh = new COM('WScript.Shell'); $sh->Run('cmd /c "'.str_replace('/', '\\', $cmdf).'"', 0, false); } catch (Throwable $e) {}
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><title>lua-server — reiniciando</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<meta http-equiv="refresh" content="9;url=?tab=proyectos">
<style>
  :root{ --bg:#0f1117; --card:#1a1d27; --line:#2a2f3d; --tx:#e6e8ee; --mut:#8b90a0; --ac:#6ea8fe; }
  @media (prefers-color-scheme:light){ :root{ --bg:#f4f6fb; --card:#fff; --line:#e3e7f0; --tx:#1a1d27; --mut:#5b6172; --ac:#2b6cff; } }
  html,body{height:100%;margin:0}
  body{background:var(--bg);color:var(--tx);font-family:system-ui,'Segoe UI',Roboto,sans-serif;display:flex;align-items:center;justify-content:center}
  .box{text-align:center;max-width:420px;padding:32px}
  .ic{width:56px;height:56px;border-radius:999px;background:rgba(110,168,254,.14);color:var(--ac);display:flex;align-items:center;justify-content:center;margin:0 auto 18px}
  .ic svg{animation:spin 1.1s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
  h1{font-size:20px;margin:0 0 10px}
  p{color:var(--mut);font-size:14px;line-height:1.5;margin:0}
</style></head><body>
  <div class="box">
    <div class="ic"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/></svg></div>
    <h1>Reiniciando el servidor…</h1>
    <p>Apache está reiniciándose. Esta página se recargará sola en unos segundos.</p>
  </div>
</body></html>
        <?php
        exit;
    }
