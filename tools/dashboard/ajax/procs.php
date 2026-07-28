<?php
// ---------------- AJAX del supervisor de procesos (estado en vivo + log) ----------------
if (($_REQUEST['ajax'] ?? '') === 'procs') {
    header('Content-Type: application/json; charset=utf-8');
    $pop = (string)($_REQUEST['op'] ?? '');
    if ($pop === 'state') {
        // Se devuelven definiciones + estado del watcher mezclados: el JS repinta los badges
        // con esto cada pocos segundos sin recargar la pagina.
        $state = procs_state($ROOT);
        $out = [];
        foreach (procs_load($ROOT) as $p) {
            $id = (string)($p['id'] ?? '');
            $s = $state[$id] ?? null;
            $out[] = [
                'id'      => $id,
                'enabled' => (bool)($p['enabled'] ?? false),
                'running' => (bool)($s['running'] ?? false),
                'pid'     => (int)($s['pid'] ?? 0),
                'fails'   => (int)($s['fails'] ?? 0),
                'next'    => (int)($s['next'] ?? 0),
                'since'   => (int)($s['since'] ?? 0),
            ];
        }
        echo json_encode(['ok'=>true, 'watcher'=>watcher_alive($ROOT), 'procs'=>$out, 'now'=>time()]);
        exit;
    }
    if ($pop === 'log') {
        $id = (string)($_REQUEST['id'] ?? '');
        if (!valid_proc_id($id)) { echo json_encode(['error'=>'Proceso no válido.']); exit; }
        $f = $ROOT.'/logs/procs/'.$id.'.log';
        // highlight_error_log devuelve HTML ya escapado (mismo visor que la pestana Logs).
        echo json_encode(['ok'=>true, 'html'=>highlight_error_log(tail_file($f, 300)), 'exists'=>is_file($f)], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    echo json_encode(['error'=>'Operación no válida.']); exit;
}

