<?php
function unregistered_projects($www, $sites){
    $out=[];
    $used = registered_dirs($www, $sites);
    if (is_dir($www)) {
        foreach (scandir($www) as $d) {
            if ($d==='.'||$d==='..') continue;
            $full = "$www/$d";
            if (!is_dir($full)) continue;
            if (isset($sites[$d])) continue;
            $r = realpath($full);
            if ($r !== false && isset($used[$r])) continue;
            $out[]=$d;
        }
    }
    sort($out);
    return $out;
}
// Proyectos registrados en sites.json cuya carpeta ya no existe (borrada a mano
// desde fuera del panel, p.ej. el Explorador de Windows). El panel nunca borra
// solo un sitio de sites.json: hace falta "Sincronizar proyectos".
function missing_projects($www, $sites){
    $out = [];
    // 'path' fuera de www\ = proyecto EXTERNO de verdad (disco USB/red que puede estar
    // desmontado): no auto-desregistrar por carpeta ausente, se gestiona a mano. 'path'
    // DENTRO de www\ (caso de un proyecto adoptado con clave normalizada en minusculas,
    // p.ej. "arquitecturaTgin" -> clave "arquitecturatgin" + path a la carpeta real) no es
    // "externo": vive en el mismo disco que todo lo demas, asi que SI se comprueba si su
    // carpeta desaparecio. Comparacion por texto (no realpath): si la carpeta ya no existe,
    // realpath() devolveria false para ambos casos y no podriamos distinguirlos.
    $wwwNorm = rtrim(str_replace('\\','/',$www),'/').'/';
    foreach ($sites as $name=>$info) {
        $path = (is_array($info) && !empty($info['path'])) ? $info['path'] : null;
        if ($path !== null) {
            $pathNorm = str_replace('\\','/',$path);
            if (stripos($pathNorm, $wwwNorm) !== 0) continue; // externo de verdad: se salta
            if (!is_dir($path)) $out[] = $name;
            continue;
        }
        if (!is_dir($www.'/'.$name)) $out[] = $name;
    }
    sort($out);
    return $out;
}
// Busca la clave real de sites.json para un nombre de proyecto que puede venir de
// un formulario/URL con mayusculas distintas (p.ej. "arquitecturaTgin", el nombre
// real de la carpeta en Windows, cuando la clave registrada es "arquitecturatgin"
// tras normalizar via slug_from_name). isset($sites[$name]) es sensible a mayusculas
// y falla en silencio con "Proyecto no valido" ante el minimo desajuste de casing o
// espacios; esto lo resuelve intentando primero la coincidencia exacta y si no,
// una comparacion insensible a mayusculas/minusculas y a espacios sueltos.
function resolve_site_key($sites, $name){
    $name = trim((string)$name);
    if ($name === '') return null;
    if (isset($sites[$name])) return $name;
    $lower = strtolower($name);
    foreach ($sites as $key=>$info) {
        if (strtolower($key) === $lower) return $key;
    }
    return null;
}
// Detecta el framework de un proyecto por sus archivos caracteristicos. Se llama solo
// al integrar (no en cada carga): el resultado se guarda en sites.json como 'type'.
// Adivina el "tipo" de un proyecto mirando sus archivos/manifiestos. Solo informativo
// (una etiqueta en la card): el servidor solo sirve PHP, pero el usuario puede tener
// tambien front-ends JS o apps Python en sus carpetas y viene bien identificarlos.
// Orden: PHP -> Python -> JS/Node. En JS se comprueba primero lo mas especifico
// (Angular/Next/Nuxt) antes que su base (React/Vue), y Vite/Node como ultimo recurso.
// Parser pragmatico de constraints de Composer para PHP (^8.1, >=7.4, >=7.4 <8.0, 8.1.*,
// ~8.1, 8.1.0, "8.1.0 || 8.2.0"...). No es un resolver semver completo: si no reconoce la
// constraint, devuelve null (se usa la version por defecto del panel, sin romper nada).
// Elige, de entre las versiones instaladas, la MAS ALTA que cumple la constraint.
function pick_php_for_constraint($constraint, $installedVers){
    $min = null; $max = null;
    foreach (preg_split('/[|,\s]+/', trim((string)$constraint)) as $part) {
        if ($part === '' || $part === '||') continue;
        if (!preg_match('/^(\^|~|>=|>|<=|<)?\s*(\d+)(?:\.(\d+))?/', $part, $m)) continue;
        $op = $m[1] ?? ''; $ver = $m[2].'.'.($m[3] ?? '0');
        if ($op === '<' || $op === '<=') { $max = $ver; } else { $min = $ver; }
    }
    if ($min === null) return null;
    $candidates = array_values(array_filter($installedVers, function($v) use ($min, $max) {
        if (version_compare($v, $min, '<')) return false;
        if ($max !== null && version_compare($v, $max, '>=')) return false;
        return true;
    }));
    if (!$candidates) return null;
    usort($candidates, fn($a,$b)=>version_compare($b,$a));
    return $candidates[0];
}
// Adivina la version de PHP de un proyecto (para preseleccionarla al integrarlo) a partir
// de .php-version (version exacta) o de composer.json: config.platform.php (exacta, la que
// Composer forzaria) o require.php (rango, ver pick_php_for_constraint). Devuelve una de las
// versiones REALMENTE INSTALADAS en $installedVers, o null si no hay pista o ninguna sirve.
function detect_project_php($dir, $installedVers){
    $pvFile = "$dir/.php-version";
    if (is_file($pvFile)) {
        $v = trim((string)@file_get_contents($pvFile));
        if (preg_match('/^(\d+\.\d+)/', $v, $m) && in_array($m[1], $installedVers, true)) return $m[1];
    }
    if (!is_file("$dir/composer.json")) return null;
    $data = json_decode((string)@file_get_contents("$dir/composer.json"), true);
    if (!is_array($data)) return null;
    $platform = $data['config']['platform']['php'] ?? null;
    if (is_string($platform) && preg_match('/^(\d+\.\d+)/', $platform, $m) && in_array($m[1], $installedVers, true)) return $m[1];
    $constraint = $data['require']['php'] ?? null;
    if (!is_string($constraint) || $constraint === '') return null;
    return pick_php_for_constraint($constraint, $installedVers);
}
// WordPress puede vivir un nivel mas adentro del proyecto (zip descomprimido con su propia
// carpeta, WordPress metido en public/, etc.), a diferencia de Laravel/Symfony/Node cuyos
// marcadores (artisan, composer.json, package.json) casi siempre estan en la raiz del repo.
function has_wp_markers($dir){
    foreach (['wp-load.php','wp-config.php','wp-config-sample.php'] as $f) {
        if (is_file("$dir/$f")) return true;
    }
    return false;
}
function detect_project_type($dir){
    // --- PHP ---
    if (has_wp_markers($dir)) return 'wordpress';
    if (is_dir($dir)) {
        foreach (scandir($dir) as $sub) {
            if ($sub[0] === '.') continue;
            $subDir = "$dir/$sub";
            if (is_dir($subDir) && has_wp_markers($subDir)) return 'wordpress';
        }
    }
    if (is_file("$dir/artisan")) return 'laravel';
    if (is_file("$dir/composer.json")) {
        $data = json_decode((string)@file_get_contents("$dir/composer.json"), true);
        $require = array_merge((array)($data['require'] ?? []), (array)($data['require-dev'] ?? []));
        foreach (array_map('strtolower', array_keys($require)) as $pkg) {
            if ($pkg === 'laravel/framework') return 'laravel';
            if (strpos($pkg, 'symfony/') === 0)  return 'symfony';
            if (strpos($pkg, 'slim/slim') === 0)  return 'slim';
        }
    }
    // --- Python ---
    if (is_file("$dir/manage.py")) return 'django';
    $py = '';
    foreach (['requirements.txt','pyproject.toml','Pipfile','environment.yml'] as $pf) {
        if (is_file("$dir/$pf")) $py .= "\n".strtolower((string)@file_get_contents("$dir/$pf"));
    }
    if ($py !== '') {
        if (strpos($py,'django')  !== false) return 'django';
        if (strpos($py,'fastapi') !== false) return 'fastapi';
        if (strpos($py,'flask')   !== false) return 'flask';
        return 'python';
    }
    // --- JavaScript / Node (package.json) ---
    if (is_file("$dir/package.json")) {
        $data = json_decode((string)@file_get_contents("$dir/package.json"), true);
        // Se miran dependencies + devDependencies + peerDependencies: muchos scaffolds (p.ej.
        // los de Vite) declaran react/vue como peer y solo dejan en devDependencies el plugin
        // (@vitejs/plugin-react, @vitejs/plugin-vue), que es la senal precisa del framework.
        $deps = array_change_key_case(array_merge(
            (array)($data['dependencies'] ?? []), (array)($data['devDependencies'] ?? []),
            (array)($data['peerDependencies'] ?? [])), CASE_LOWER);
        $has = function($k) use ($deps){ return isset($deps[$k]); };
        if ($has('@angular/core'))                                              return 'angular';
        if ($has('next'))                                                       return 'nextjs';
        if ($has('nuxt') || $has('nuxt3'))                                      return 'nuxt';
        if ($has('svelte') || $has('@sveltejs/kit') || $has('@sveltejs/vite-plugin-svelte')) return 'svelte';
        if ($has('astro'))                                                      return 'astro';
        if ($has('vue') || $has('@vitejs/plugin-vue'))                          return 'vue';
        if ($has('react') || $has('react-dom') || $has('@vitejs/plugin-react')) return 'react';
        if ($has('vite'))                                                       return 'vite';
        return 'node';
    }
    return null;
}
// Etiqueta legible por tipo (o null si es desconocido). Fuente unica para las cards.
function project_type_label($type){
    $map = [
        'wordpress'=>'WordPress','laravel'=>'Laravel','symfony'=>'Symfony','slim'=>'Slim',
        'angular'=>'Angular','nextjs'=>'Next.js','nuxt'=>'Nuxt','svelte'=>'Svelte','astro'=>'Astro',
        'vue'=>'Vue','react'=>'React','vite'=>'Vite','node'=>'Node',
        'django'=>'Django','flask'=>'Flask','fastapi'=>'FastAPI','python'=>'Python',
    ];
    return $map[$type] ?? null;
}
// Icono SVG monolinea (stroke, sin marcas/logos exactos) para el badge de tipo de
// proyecto: usa currentColor para heredar el color ya definido en .typetag-<tipo>.
// Metafora reconocible por ecosistema en vez de un logo pixel-perfect (evita depender de
// reproducir marcas registradas de memoria).
function project_type_icon($type){
    $svg = [
        'wordpress' => '<circle cx="12" cy="12" r="9"/><path d="M5 9.5l2.3 6.5 2-5 2 5 2.3-6.5"/>',
        'laravel'   => '<path d="M4 19V8l8-5 8 5v11"/><path d="M4 8l8 5 8-5"/><path d="M12 13v6"/>',
        'symfony'   => '<path d="M12 3l7 3.5v5.5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6.5z"/>',
        'slim'      => '<path d="M20 4c-6.5 0-15 4-17 13 3-2 6.5-2 9-.7C11 14 12 11 12 11c-3 1-5.5 0-6.8-1.7C8 8 13 5 20 4z"/>',
        'angular'   => '<path d="M12 3l8 3-1.2 10L12 21l-6.8-5L4 6z"/><path d="M12 6.5L8 16m4-9.5L16 16M9.3 13h5.4"/>',
        'nextjs'    => '<circle cx="12" cy="12" r="9"/><path d="M9 8.5v7M9 8.5l6.5 7.5M15.5 8.5v5"/>',
        'nuxt'      => '<path d="M4 19h5.5L14 8.5 18.5 19H21"/><path d="M9.5 19L14 10l1.7 3.8"/>',
        'svelte'    => '<path d="M17 6.5c-2-2-5-2-7 0l-4 4c-1.7 1.7-1.7 4.5 0 6.2M7 17.5c2 2 5 2 7 0l4-4c1.7-1.7 1.7-4.5 0-6.2"/><path d="M8.3 15.7l7.4-7.4"/>',
        'astro'     => '<path d="M12 3c2.5 4 4 9.5 4 13.5a4 4 0 0 1-8 0C8 12.5 9.5 7 12 3z"/><path d="M9.5 15.5h5"/><circle cx="12" cy="19.5" r="1"/>',
        'vue'       => '<path d="M3 5h4l5 9 5-9h4L12 20z"/><path d="M8.5 5h3L12 8l.5-3h3L12 12z"/>',
        'react'     => '<circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/><ellipse cx="12" cy="12" rx="9" ry="4"/><ellipse cx="12" cy="12" rx="9" ry="4" transform="rotate(60 12 12)"/><ellipse cx="12" cy="12" rx="9" ry="4" transform="rotate(120 12 12)"/>',
        'vite'      => '<path d="M13 3L5 13h5.5L10 21l8.5-11H13z"/>',
        'node'      => '<path d="M12 3l7.5 4.3v9.4L12 21l-7.5-4.3V7.3z"/><path d="M9 12h6M12 9v6"/>',
        'django'    => '<path d="M6 4h6a5 5 0 0 1 5 5v11H11a5 5 0 0 1-5-5z"/><path d="M6 9h5"/>',
        'flask'     => '<path d="M10 3h4M10.5 3v6L5.5 18a2 2 0 0 0 1.8 3h9.4a2 2 0 0 0 1.8-3L13.5 9V3"/><path d="M8 15h8"/>',
        'fastapi'   => '<path d="M6 5l6 7-6 7"/><path d="M13 5l6 7-6 7"/>',
        'python'    => '<path d="M12 3c-3 0-4 1-4 3v2h4"/><path d="M9 8H6a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h2"/><path d="M12 21c3 0 4-1 4-3v-2h-4"/><path d="M15 16h3a2 2 0 0 0 2-2V11a2 2 0 0 0-2-2h-2"/><circle cx="9.5" cy="6" r=".6" fill="currentColor" stroke="none"/><circle cx="14.5" cy="18" r=".6" fill="currentColor" stroke="none"/>',
    ];
    if (!isset($svg[$type])) return '';
    return '<svg class="typeicon" viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'.$svg[$type].'</svg>';
}
// Tarjeta de un proyecto (usada tanto en "Destacados" como en "Proyectos", para no
// duplicar el HTML entre las dos secciones). Usa globales ya establecidas en la seccion
// GET (render): $WWW, $ROOT, $tld, $vers, $termOn.
function render_site_card($name, $info){
    global $WWW, $ROOT, $tld, $vers, $termOn;
    $ver = is_array($info)?($info['php']??'?'):$info;
    $dom = (is_array($info) && !empty($info['domain'])) ? $info['domain'] : $name.'.'.$tld;
    $pdir = project_dir($WWW, $info, $name);
    $extPath = (is_array($info) && !empty($info['path'])) ? $info['path'] : null;
    $locked = project_locked($pdir);
    $pinned = is_array($info) && !empty($info['pinned']);
    $hasCover = (bool)cover_path($ROOT,$name);
    $hasComposer = is_file($pdir.'/composer.json');
    $hasNpm = is_file($pdir.'/package.json');
    $hasArtisan = is_file($pdir.'/artisan');
    $pType = is_array($info) ? ($info['type'] ?? null) : null;
    $pTypeLabel = project_type_label($pType);
    $dbName = is_array($info) ? (string)($info['db'] ?? '') : '';
    ?>
          <div class="sitecard<?= $locked?' is-locked':'' ?><?= $pinned?' is-pinned':'' ?>">
            <form method="post" enctype="multipart/form-data" class="coverform" id="cover-<?= e($name) ?>">
              <input type="hidden" name="action" value="cover">
              <input type="hidden" name="name" value="<?= e($name) ?>">
              <input type="file" name="img" accept="image/*" hidden onchange="this.form.requestSubmit()">
              <button type="button" class="cover<?= $hasCover?' has':' empty' ?><?= (!$hasCover && $pType) ? ' type-'.e($pType) : '' ?>" title="<?= $hasCover?'Cambiar carátula':'Subir carátula' ?>"
                      onclick="this.parentNode.querySelector('input[type=file]').click()"
                      <?= $hasCover?'style="background-image:url(\'?cover='.e(rawurlencode($name)).'&t='.(cover_path($ROOT,$name)?filemtime(cover_path($ROOT,$name)):0).'\')"':'' ?>>
                <span class="cover-hint">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                  <?= $hasCover?'Cambiar':'Carátula' ?>
                </span>
              </button>
            </form>
            <?php if ($hasCover): ?>
              <form method="post" class="coverdel"><input type="hidden" name="action" value="cover_remove"><input type="hidden" name="name" value="<?= e($name) ?>"><button type="submit" class="coverdelbtn" title="Quitar carátula">&times;</button></form>
            <?php endif; ?>
            <form method="post" class="pinform">
              <input type="hidden" name="action" value="<?= $pinned?'unpin':'pin' ?>">
              <input type="hidden" name="name" value="<?= e($name) ?>">
              <button type="submit" class="pinbtn<?= $pinned?' is-pinned':'' ?>" title="<?= $pinned?'Quitar de Destacados':'Añadir a Destacados' ?>" aria-label="<?= $pinned?'Quitar de Destacados':'Añadir a Destacados' ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="<?= $pinned?'currentColor':'none' ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/></svg>
              </button>
            </form>
            <div class="cardbody">
              <div class="name" title="<?= e($name) ?>"><?= e($name) ?></div>
              <?php if ($pTypeLabel || $extPath): ?>
              <div class="tagrow">
                <?php if($pTypeLabel): ?><span class="typetag typetag-<?= e($pType) ?>"><?= project_type_icon($pType) ?><?= e($pTypeLabel) ?></span><?php endif; ?>
                <?php if($extPath): ?><span class="exttag" title="Proyecto externo: <?= e($extPath) ?>">&#8599; externo</span><?php endif; ?>
              </div>
              <?php endif; ?>
              <a class="url" href="http://<?= e($dom) ?>" target="_blank"><?= e($dom) ?> &#8599;</a>
            </div>
            <div class="cardfooter">
              <form method="post" class="phpselform">
                <input type="hidden" name="action" value="switch">
                <input type="hidden" name="name" value="<?= e($name) ?>">
                <select name="php" class="phpsel" onchange="this.form.dataset.loadingText='Cambiando a PHP '+this.value+'…';this.form.requestSubmit()">
                  <?php foreach ($vers as $v): ?>
                    <option value="<?= e($v) ?>" <?= $v===$ver?'selected':'' ?>>PHP <?= e($v) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
              <div class="cardactions">
                <a class="lockbtn" href="?tab=proyecto&name=<?= e(rawurlencode($name)) ?>" title="Ver detalles del proyecto" aria-label="Ver detalles del proyecto">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.5 9a2.5 2.5 0 0 1 5 0c0 1.3-.7 1.9-1.4 2.4-.6.5-1.1.9-1.1 1.6"/><line x1="12" y1="17" x2="12" y2="17.01"/></svg>
                </a>
                <?php if ($termOn && ($hasComposer || $hasNpm || $hasArtisan)): ?>
                  <button type="button" class="runbtn lua-runbtn" title="Ejecutar Composer/NPM/Artisan" aria-label="Ejecutar Composer/NPM/Artisan" data-name="<?= e($name) ?>" data-path="<?= e(term_win($pdir)) ?>" data-composer="<?= $hasComposer?'1':'0' ?>" data-npm="<?= $hasNpm?'1':'0' ?>" data-artisan="<?= $hasArtisan?'1':'0' ?>" data-php="<?= e($ver) ?>">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                  </button>
                <?php endif; ?>
                <form method="post" class="lockform">
                  <input type="hidden" name="action" value="<?= $locked?'unlock':'lock' ?>">
                  <input type="hidden" name="name" value="<?= e($name) ?>">
                  <button type="submit" class="lockbtn" title="<?= $locked?'Desbloquear (permitirá eliminar el proyecto)':'Bloquear (impide eliminar el proyecto)' ?>" aria-label="<?= $locked?'Desbloquear':'Bloquear' ?>">
                    <?php if ($locked): ?>
                      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                    <?php else: ?>
                      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/></svg>
                    <?php endif; ?>
                  </button>
                </form>
                <?php if (!$locked): ?>
                  <button type="button" class="trashbtn" title="Eliminar" aria-label="Eliminar" onclick="luaAskDelete('<?= e($name) ?>', <?= $extPath!==null?'true':'false' ?>, <?= $dbName!==''?'true':'false' ?>, '<?= e($dbName) ?>')">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                  </button>
                <?php endif; ?>
              </div>
            </div>
          </div>
    <?php
}
// Borrado recursivo de una carpeta sin registrar (no hay sites.json que "desregistrar": es on/off).
function rrmdir($dir){
    if (!is_dir($dir)) return true;
    foreach (scandir($dir) as $f) {
        if ($f==='.'||$f==='..') continue;
        $p = $dir.'/'.$f;
        if (is_dir($p) && !is_link($p)) rrmdir($p); else @unlink($p);
    }
    return @rmdir($dir);
}
// El panel NO lanza procesos: solo deja un archivo-senal en tmp\ que el watcher
// (proceso independiente arrancado por 'lua.ps1 start') ejecuta en ~1 segundo.
function lua_flag($name){ global $ROOT; @file_put_contents($ROOT.'/tmp/'.$name.'.flag', (string)time()); }
function lua_apply(){ lua_flag('apply'); }
function lua_hosts(){ lua_flag('hosts'); }

