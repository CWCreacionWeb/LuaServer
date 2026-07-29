<?php
// ---------------- Docker (opcional: solo se muestra si se detecta instalado) ----------------
// Docker Desktop es un instalador de sistema pesado (WSL2/Hyper-V, admin, reinicio) muy
// distinto de MariaDB/MongoDB (binarios portables que este mismo proyecto descarga y
// lanza): por eso aqui NO se instala nada, solo se detecta lo que ya haya en la maquina
// y se gestiona (contenedores). Comandos rapidos (ps/start/stop/...) van por proc_open
// en forma de array -- igual de seguro que git_exec (el proceso termina solo y se drenan
// los pipes hasta EOF, no cuelga el worker de mod_fcgid) pero sin la pesadilla de comillas
// de cmd.exe que tendria un string con los templates --format de Docker ('{{'/'}}').
// Lanzar Docker Desktop en si (una app persistente, no un comando que termina) es la
// EXCEPCION: eso va por COM WScript.Shell.Run fire-and-forget, igual que apagar/reiniciar.
function docker_binary(){
    static $bin = null;
    if ($bin !== null) return $bin;
    $candidates = array_filter([
        getenv('ProgramFiles') ? getenv('ProgramFiles').'\Docker\Docker\resources\bin\docker.exe' : null,
        getenv('ProgramW6432') ? getenv('ProgramW6432').'\Docker\Docker\resources\bin\docker.exe' : null,
        'C:\\Program Files\\Docker\\Docker\\resources\\bin\\docker.exe',
    ]);
    foreach ($candidates as $cand) { if (is_file($cand)) return $bin = $cand; }
    $probe = @proc_open(['docker','version','--format','{{.Client.Version}}'], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (is_resource($probe)) {
        @stream_get_contents($pipes[1]); @fclose($pipes[1]);
        @stream_get_contents($pipes[2]); @fclose($pipes[2]);
        if (proc_close($probe) === 0) return $bin = 'docker';
    }
    return $bin = false;
}
function docker_desktop_exe(){
    $candidates = array_filter([
        getenv('ProgramFiles') ? getenv('ProgramFiles').'\Docker\Docker\Docker Desktop.exe' : null,
        getenv('ProgramW6432') ? getenv('ProgramW6432').'\Docker\Docker\Docker Desktop.exe' : null,
        'C:\\Program Files\\Docker\\Docker\\Docker Desktop.exe',
    ]);
    foreach ($candidates as $cand) { if (is_file($cand)) return $cand; }
    return null;
}
function docker_installed(){ return docker_binary() !== false; }
// El pipe con nombre \\.\pipe\docker_engine solo existe mientras dockerd esta escuchando:
// sondearlo es instantaneo (no lanza ningun proceso), a diferencia de "docker version".
function docker_running(){ return @file_exists('\\\\.\\pipe\\docker_engine'); }
function docker_exec($args){
    $bin = docker_binary();
    if ($bin === false) return null;
    $proc = @proc_open(array_merge([$bin], $args), [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    return ['ok'=>$code===0, 'out'=>$out, 'err'=>$err];
}
// Listado de contenedores (activos + parados). null = docker no disponible / fallo el comando.
function docker_containers(){
    $r = docker_exec(['ps','-a','--format',"{{.ID}}\t{{.Image}}\t{{.Names}}\t{{.Status}}\t{{.Ports}}"]);
    if ($r===null || !$r['ok']) return null;
    $trimmed = trim($r['out']);
    if ($trimmed==='') return [];
    $out=[];
    foreach (explode("\n", $trimmed) as $line) {
        $p = explode("\t", rtrim($line, "\r"));
        $out[] = ['id'=>$p[0]??'', 'image'=>$p[1]??'', 'name'=>$p[2]??'', 'status'=>$p[3]??'', 'ports'=>$p[4]??''];
    }
    return $out;
}

