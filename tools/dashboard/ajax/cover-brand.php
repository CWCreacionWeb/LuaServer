<?php
// ---------------- Servir la caratula de un proyecto: ?cover=<name> ----------------
if (isset($_GET['cover'])) {
    $name = (string)$_GET['cover'];
    $f = valid_name($name) ? cover_path($ROOT,$name) : null;
    if ($f) {
        header('Content-Type: '.cover_mime($f));
        header('Cache-Control: no-cache');
        // Una caratula puede ser un SVG con <script>/on*: sin esto, navegar directo a
        // ?cover=... ejecutaria ese JS en el origen del panel (XSS almacenado). 'sandbox'
        // (sin allow-scripts) bloquea la ejecucion pero preserva el render como <img>.
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        readfile($f); exit;
    }
    http_response_code(404); exit;
}

// ---------------- Servir el logo de la plataforma: ?brandlogo ----------------
// Devuelve el logo propio (data\brand\logo.*) si existe; si no, cae al logo de marca
// por defecto. Se usa tanto en el header como de favicon cuando hay logo propio.
if (isset($_GET['brandlogo'])) {
    $f = brand_logo_path($ROOT);
    if (!$f) { $def = $ROOT.'/tools/dashboard/assets/logo.svg'; if (is_file($def)) $f = $def; }
    if ($f) {
        header('Content-Type: '.cover_mime($f));
        header('Cache-Control: no-cache');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        readfile($f); exit;
    }
    http_response_code(404); exit;
}

