<?php
function ticon_chev(){ return '<svg class="tchev" viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>'; }
function ticon_folder(){ return '<svg class="ticon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/></svg>'; }
function ticon_file(){ return '<svg class="ticon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'; }

// Arbol de archivos de un proyecto. $lazyMode=true trata TODAS las subcarpetas como
// cerradas/perezosas (usado en la respuesta AJAX de un solo nivel); en modo normal
// solo vendor/node_modules/.git empiezan cerradas (se cargan al hacer clic).
function tree_node_html($abs, $rel, $lazyMode, &$count, $cap){
    $entries = @scandir($abs);
    if ($entries === false) return '<div class="tnode-more">No se pudo leer la carpeta.</div>';
    $dirs = []; $files = [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        if (is_dir($abs.'/'.$e)) $dirs[] = $e; else $files[] = $e;
    }
    natcasesort($dirs); natcasesort($files);
    $out = '';
    foreach ($dirs as $e) {
        if ($count >= $cap) { $out .= '<div class="tnode-more">&hellip; truncado, hay más entradas de las mostradas</div>'; return $out; }
        $count++;
        $childRel = ($rel !== '' ? $rel.'/' : '').$e;
        $heavy = in_array(strtolower($e), ['vendor','node_modules','.git'], true);
        $lazy = $lazyMode || $heavy;
        if ($lazy) {
            $out .= '<div class="tnode"><div class="trow tdir" data-lazy="1" data-rel="'.e($childRel).'">'.ticon_chev().ticon_folder().'<span>'.e($e).'</span></div><div class="tchildren" hidden></div></div>';
        } else {
            $out .= '<div class="tnode"><div class="trow tdir open">'.ticon_chev().ticon_folder().'<span>'.e($e).'</span></div><div class="tchildren">';
            $out .= tree_node_html($abs.'/'.$e, $childRel, false, $count, $cap);
            $out .= '</div></div>';
        }
    }
    foreach ($files as $e) {
        if ($count >= $cap) { $out .= '<div class="tnode-more">&hellip; truncado, hay más entradas de las mostradas</div>'; return $out; }
        $count++;
        $childRel = ($rel !== '' ? $rel.'/' : '').$e;
        $out .= '<div class="trow tfile" data-rel="'.e($childRel).'" title="Clic para editar">'.ticon_file().'<span>'.e($e).'</span></div>';
    }
    return $out;
}
// Carpetas en www\ que no estan registradas en sites.json (creadas a mano,
// copiadas de otra maquina, etc.). No se publican solas: hay que integrarlas.
