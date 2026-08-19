<?php
// ---------------- AJAX: progreso en vivo de los jobs de import de BD ----------------
// Sustituye al meta-refresh de pagina completa que usaba antes la pestaña Bases de datos
// mientras corria un import (?tab=bd cada 3s, perdiendo el scroll y cualquier formulario a
// medio rellenar en la misma pagina): el cliente pide solo el HTML ya renderizado de las
// tarjetas de progreso que le interesan y las reemplaza en el DOM. Reutiliza
// render_import_job_card (la misma funcion que usa el render normal) para no duplicar la
// logica de la barra de progreso en dos sitios.
if (($_GET['ajax'] ?? '') === 'import_jobs') {
    header('Content-Type: application/json; charset=utf-8');
    $jJobs = read_jobs($ROOT.'/tmp/jobs');
    $anyRunning = false;

    // Un import de archivo unico por BD (la ficha de cada BD en la lista de arriba).
    $jFileByDb = [];
    foreach ($jJobs as $jj) {
        if (($jj['type']??'')!=='db_import_file') continue;
        $jjDb = $jj['dbname'] ?? $jj['name'] ?? '';
        if ($jjDb !== '' && !isset($jFileByDb[$jjDb])) $jFileByDb[$jjDb] = $jj;
    }
    $fileCards = [];
    foreach ($jFileByDb as $jDb => $jJob) {
        $fileCards[$jDb] = render_import_job_card($ROOT, $jJob);
        if (in_array($jJob['state']??'', ['running','queued'], true)) $anyRunning = true;
    }

    // Imports de carpeta (hasta 5 mas recientes, igual que el render normal).
    $jDirJobs = array_values(array_filter($jJobs, function($j){ return ($j['type']??'')==='db_import_dir'; }));
    $dirHtml = '';
    foreach (array_slice($jDirJobs, 0, 5) as $jJob) {
        $dirHtml .= render_import_job_card($ROOT, $jJob);
        if (in_array($jJob['state']??'', ['running','queued'], true)) $anyRunning = true;
    }

    echo json_encode(['fileCards'=>$fileCards, 'dirHtml'=>$dirHtml, 'anyRunning'=>$anyRunning]);
    exit;
}
