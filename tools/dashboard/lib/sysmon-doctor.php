<?php
// ---------------- Recursos del sistema (WMI via COM, sin subprocesos) ----------------
// Igual que term_fresh_machine_path (trampa nº5): nunca shell_exec()/exec() de powershell.exe
// para leer metricas -- bajo mod_fcgid eso arriesga colgar el worker. Todo via COM winmgmts.
function sysmon_wmi(){
    static $w = null;
    if ($w === null) { $w = new COM('winmgmts://./root/cimv2'); }
    return $w;
}
function sysmon_q($sql){
    $out = [];
    foreach (sysmon_wmi()->ExecQuery($sql) as $r) { $out[] = $r; }
    return $out;
}
// Nombres de proceso -> [color de la paleta categorica, etiqueta]. El orden es el de un
// conjunto de 8 tonos ya validado como seguro para daltonismo (ver skill de dataviz): no se
// inventan colores a ojo para un grafico con varias series a la vez.
function sysmon_proc_map(){
    return [
        'httpd.exe'        => ['cat-1', 'Apache'],
        'php-cgi.exe'      => ['cat-2', 'PHP-CGI'],
        'mysqld.exe'       => ['cat-3', 'MariaDB'],
        'postgres.exe'     => ['cat-4', 'PostgreSQL'],
        'mongod.exe'       => ['cat-5', 'MongoDB'],
        'redis-server.exe' => ['cat-6', 'Redis'],
        'node.exe'         => ['cat-7', 'Node (mongo-express)'],
    ];
}
if (($_REQUEST['ajax'] ?? '') === 'sysmon') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $out = ['ok'=>true, 'ts'=>time()];

        // ---- CPU: contador crudo (rapido, ~50ms) + delta contra la muestra anterior ----
        // La version "Formatted" del mismo contador (la que ya hace el % por ti) tarda ~6s en
        // esta maquina -- probablemente el proveedor WMI recalcula sobre una ventana de muestreo
        // propia. El crudo + formula oficial (PERF_100NSEC_TIMER_INV) es case ~50ms y exacta:
        // %cpu = (1 - (P2-P1)/(T2-T1)) * 100, con P=PercentProcessorTime y T=Timestamp_Sys100NS
        // de la MISMA instancia de contador en dos lecturas. Sin bloquear la peticion con un
        // usleep: se guarda la lectura y se compara contra la de la petición anterior (el
        // cliente ya sondea cada ~2s, que es intervalo de sobra).
        $cpuPct = null;
        $prevF = $ROOT.'/tmp/sysmon-prev.json';
        foreach (sysmon_q("SELECT PercentProcessorTime, Timestamp_Sys100NS FROM Win32_PerfRawData_PerfOS_Processor WHERE Name='_Total'") as $r) {
            $p2 = (float)$r->PercentProcessorTime; $t2 = (float)$r->Timestamp_Sys100NS;
            $prev = is_file($prevF) ? json_decode((string)@file_get_contents($prevF), true) : null;
            if (is_array($prev) && !empty($prev['t']) && (time() - (int)($prev['saved']??0)) <= 30 && ($t2 - (float)$prev['t']) > 0) {
                $cpuPct = round((1 - (($p2 - (float)$prev['p']) / ($t2 - (float)$prev['t']))) * 100, 1);
                $cpuPct = max(0, min(100, $cpuPct));
            }
            @file_put_contents($prevF, json_encode(['p'=>$p2, 't'=>$t2, 'saved'=>time()]));
        }
        $out['cpu'] = ['pct'=>$cpuPct];

        // ---- RAM + hora de arranque (mismo Win32_OperatingSystem, sin consulta aparte) ----
        foreach (sysmon_q("SELECT TotalVisibleMemorySize, FreePhysicalMemory, LastBootUpTime FROM Win32_OperatingSystem") as $r) {
            $totKB = (float)$r->TotalVisibleMemorySize; $freeKB = (float)$r->FreePhysicalMemory;
            $out['ram'] = ['totalGB'=>round($totKB/1048576,1), 'usedGB'=>round(($totKB-$freeKB)/1048576,1), 'pct'=>$totKB>0?round((($totKB-$freeKB)/$totKB)*100,1):0];
            // LastBootUpTime viene en formato WMI: "20260726093114.500000+120"
            if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', (string)$r->LastBootUpTime, $bm)) {
                $boot = mktime((int)$bm[4],(int)$bm[5],(int)$bm[6],(int)$bm[2],(int)$bm[3],(int)$bm[1]);
                $out['uptimeSec'] = max(0, time() - $boot);
            }
        }

        // ---- Disco: todas las unidades fijas; se marca la que aloja esta instalacion ----
        $rootDrive = strtoupper(substr(str_replace('/','\\',$ROOT), 0, 2)); // "C:"
        $disks = [];
        foreach (sysmon_q("SELECT DeviceID, Size, FreeSpace FROM Win32_LogicalDisk WHERE DriveType=3") as $r) {
            $sz = (float)$r->Size; $fr = (float)$r->FreeSpace;
            $disks[] = ['drive'=>(string)$r->DeviceID, 'totalGB'=>round($sz/1073741824,1), 'freeGB'=>round($fr/1073741824,1), 'pct'=>$sz>0?round((($sz-$fr)/$sz)*100,1):0, 'root'=>(strtoupper((string)$r->DeviceID)===$rootDrive)];
        }
        usort($disks, function($a,$b){ return $b['root'] <=> $a['root']; });
        $out['disks'] = $disks;

        // ---- Red: agregado de todas las interfaces (la "Formatted" de red SI es rapida) ----
        $rx = 0; $tx = 0;
        foreach (sysmon_q("SELECT BytesReceivedPersec, BytesSentPersec FROM Win32_PerfFormattedData_Tcpip_NetworkInterface") as $r) {
            $rx += (float)$r->BytesReceivedPersec; $tx += (float)$r->BytesSentPersec;
        }
        $out['net'] = ['rxKBs'=>round($rx/1024,1), 'txKBs'=>round($tx/1024,1)];

        // ---- Huella de la propia plataforma: una sola consulta a Win32_Process combinando
        // los binarios conocidos + powershell.exe (para el watcher, filtrado despues por
        // CommandLine -- fusionar en una query en vez de dos evita recorrer Win32_Process
        // dos veces, que es lo que de verdad cuesta tiempo aqui, no el filtro en si).
        $map = sysmon_proc_map();
        $agg = [];
        $where = [];
        foreach (array_keys($map) as $n) { $where[] = "Name='".$n."'"; }
        $where[] = "Name='powershell.exe'";
        foreach (sysmon_q("SELECT Name, WorkingSetSize, CommandLine FROM Win32_Process WHERE ".implode(' OR ', $where)) as $r) {
            $n = (string)$r->Name; $mb = (float)$r->WorkingSetSize / 1048576;
            if ($n === 'powershell.exe') {
                $cl = (string)$r->CommandLine;
                if (stripos($cl, 'lua.ps1') === false || stripos($cl, 'watch') === false) continue;
                $n = '__watcher__';
            }
            if (!isset($agg[$n])) { $agg[$n] = ['mb'=>0.0, 'n'=>0]; }
            $agg[$n]['mb'] += $mb; $agg[$n]['n']++;
        }
        $procs = [];
        foreach ($map as $bin => [$color, $label]) {
            if (!isset($agg[$bin])) continue;
            $procs[] = ['label'=>$label, 'color'=>$color, 'mb'=>round($agg[$bin]['mb']), 'count'=>$agg[$bin]['n']];
        }
        if (isset($agg['__watcher__'])) { $procs[] = ['label'=>'Watcher (PowerShell)', 'color'=>'cat-8', 'mb'=>round($agg['__watcher__']['mb']), 'count'=>$agg['__watcher__']['n']]; }
        $out['procs'] = $procs;

        echo json_encode($out);
    } catch (Throwable $e) {
        echo json_encode(['error'=>'No se pudo leer WMI: '.$e->getMessage()]);
    }
    exit;
}

// ---------------- Doctor: diagnostico automatico de las trampas conocidas ----------------
// Convierte el conocimiento acumulado en CLAUDE.md (puertos robados por Docker, watchers
// fantasma, flags huerfanos, carpetas movidas...) en comprobaciones de un vistazo. Solo LEE:
// netstat/tasklist via @exec (mismo precedente que watcher_alive; nunca shell_exec de
// powershell, ver trampa nº5).
function doctor_listeners(){
    $out = []; $lines = [];
    @exec('netstat -ano -p TCP 2>NUL', $lines);
    $lines2 = [];
    @exec('netstat -ano -p TCPv6 2>NUL', $lines2);
    foreach (array_merge($lines, $lines2) as $l) {
        if (stripos($l, 'LISTENING') === false) continue;
        // "  TCP    127.0.0.1:80    0.0.0.0:0    LISTENING    1234"  (IPv6: "[::]:80")
        if (!preg_match('/^\s*TCP\s+(\S+):(\d+)\s+\S+\s+LISTENING\s+(\d+)/i', $l, $m)) continue;
        $out[] = ['addr'=>$m[1], 'port'=>(int)$m[2], 'pid'=>(int)$m[3], 'v6'=>(strpos($m[1],'[')!==false || strpos($m[1],':')!==false && strpos($m[1],'.')===false)];
    }
    return $out;
}
function doctor_procnames(){
    $map = []; $lines = [];
    @exec('tasklist /FO CSV /NH 2>NUL', $lines);
    foreach ($lines as $l) {
        $c = str_getcsv($l);
        if (count($c) >= 2 && is_numeric($c[1])) { $map[(int)$c[1]] = $c[0]; }
    }
    return $map;
}

