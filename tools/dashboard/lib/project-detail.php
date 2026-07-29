<?php
// ---------------- Ficha de proyecto: info ampliada, git y arbol de archivos ----------------
// Apache corre como servicio de Windows (cuenta SYSTEM) una vez activado "Arrancar con
// Windows": esa cuenta tiene su propio PATH, que normalmente NO incluye el git.exe que
// sí ve una consola interactiva del usuario. Por eso 'git' a secas puede fallar aunque
// git funcione perfectamente a mano; si falla, se prueban las rutas de instalacion tipicas.
function git_binary(){
    static $bin = null;
    if ($bin !== null) return $bin;
    $probe = @proc_open('git --version', [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (is_resource($probe)) {
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($probe) === 0) return $bin = 'git';
    }
    $candidates = array_filter([
        getenv('ProgramFiles') ? getenv('ProgramFiles').'\Git\cmd\git.exe' : null,
        getenv('ProgramFiles') ? getenv('ProgramFiles').'\Git\bin\git.exe' : null,
        getenv('ProgramFiles(x86)') ? getenv('ProgramFiles(x86)').'\Git\cmd\git.exe' : null,
        getenv('ProgramW6432') ? getenv('ProgramW6432').'\Git\cmd\git.exe' : null,
        'C:\\Program Files\\Git\\cmd\\git.exe',
    ]);
    foreach ($candidates as $cand) { if (is_file($cand)) return $bin = '"'.$cand.'"'; }
    return $bin = false;
}
function git_exec($dir, $args){
    $bin = git_binary();
    if ($bin === false) return null;
    $descriptors = [1=>['pipe','w'], 2=>['pipe','w']];
    // Apache corre como SYSTEM (servicio de Windows); las carpetas de los proyectos son
    // del usuario interactivo, y git rechaza operar en repos de otro dueno ("dubious
    // ownership") salvo que se confie explicitamente. Se hace por-llamada (-c), sin tocar
    // la config global de git de la cuenta SYSTEM.
    $safe = '-c '.escapeshellarg('safe.directory='.$dir);
    $proc = @proc_open($bin.' '.$safe.' '.$args, $descriptors, $pipes, $dir);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return $code===0 ? $out : null;
}
// Como git_exec pero conserva stderr y el exito/fallo por separado, para poder explicar
// POR QUE ha fallado un paso (usado por "Conectar repositorio Git": init/commit/remote
// necesitan feedback, no solo null-si-algo-fue-mal).
function git_exec_verbose($dir, $args){
    $bin = git_binary();
    if ($bin === false) return [false, '', 'git no está disponible en esta máquina.'];
    $descriptors = [1=>['pipe','w'], 2=>['pipe','w']];
    $safe = '-c '.escapeshellarg('safe.directory='.$dir);
    $proc = @proc_open($bin.' '.$safe.' '.$args, $descriptors, $pipes, $dir);
    if (!is_resource($proc)) return [false, '', 'No se pudo ejecutar git.'];
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code === 0, $out, trim($err)];
}
// null = no es un repo git (o no se encontro git.exe); array = branch/dirty/commits/remote
function git_info($dir){
    if (!is_dir($dir.'/.git')) return null;
    $branch = trim((string)git_exec($dir, 'rev-parse --abbrev-ref HEAD'));
    if ($branch === '') return null;
    $statusRaw = git_exec($dir, 'status --porcelain');
    $dirty = $statusRaw!==null ? count(array_filter(explode("\n", trim($statusRaw)), fn($l)=>$l!=='')) : 0;
    $logRaw = git_exec($dir, 'log -n 30 --date=relative --pretty=format:%h%x09%an%x09%ad%x09%s');
    $commits = [];
    if ($logRaw !== null && $logRaw !== '') {
        foreach (explode("\n", $logRaw) as $line) {
            $parts = explode("\t", $line, 4);
            if (count($parts)===4) $commits[] = ['hash'=>$parts[0],'author'=>$parts[1],'date'=>$parts[2],'subject'=>$parts[3]];
        }
    }
    $remote = trim((string)git_exec($dir, 'remote get-url origin'));
    return ['branch'=>$branch, 'dirty'=>$dirty, 'commits'=>$commits, 'remote'=>$remote];
}

