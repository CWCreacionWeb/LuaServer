  <?php if ($tab==='docs'): /* ---------- PESTAÑA DOCUMENTACIÓN ---------- */ ?>

    <style>
      /* Documento con indice fijo a la izquierda (como una doc de verdad), en vez de
         tarjetas sueltas: mas facil de leer de arriba a abajo o de saltar por el indice. */
      .docs{max-width:1180px;display:flex;gap:36px;align-items:flex-start}
      .docs-side{width:220px;flex:0 0 220px;position:sticky;top:0;align-self:flex-start;max-height:100vh;overflow-y:auto;padding-bottom:20px}
      .docs-side .side-title{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--mut);margin:0 0 10px;padding:0 10px;font-weight:700}
      .docs-search{position:relative;margin:0 0 14px}
      .docs-search input{width:100%;padding:8px 28px 8px 10px;font-size:13px;border:1px solid var(--line);border-radius:7px;background:var(--in);color:var(--tx)}
      .docs-search input:focus{outline:none;border-color:var(--ac)}
      .docs-search .clr{position:absolute;right:5px;top:50%;transform:translateY(-50%);border:none;background:none;color:var(--mut);cursor:pointer;font-size:15px;line-height:1;padding:4px;display:none}
      .docs-search.has-query .clr{display:block}
      .docs-search-hint{font-size:11px;color:var(--mut);padding:6px 10px 2px}
      .docs-side nav{display:flex;flex-direction:column;gap:1px}
      .docs-side nav a{padding:6px 10px;border-radius:6px;font-size:13px;color:var(--mut);border-left:2px solid transparent;line-height:1.4;text-decoration:none}
      .docs-side nav a:hover{color:var(--tx);background:var(--card)}
      .docs-side nav a.active{color:var(--ac);background:rgba(110,168,254,.1);border-left-color:var(--ac);font-weight:600}
      .docs-side nav a.nomatch{display:none}
      .docs-main section.nomatch{display:none}
      .docs-noresults{display:none;padding:30px 0;color:var(--mut);font-size:14px}
      .docs mark.docs-hl{background:rgba(255,196,0,.45);color:inherit;padding:0 1px;border-radius:2px}
      .docs-main{flex:1;min-width:0;max-width:800px}
      .docs-main h2{font-size:22px;margin:0 0 4px;text-transform:none;letter-spacing:0;color:var(--tx);font-weight:700}
      .docs-main .lead{color:var(--mut);font-size:14px;margin:0 0 8px}
      .docs-main section{scroll-margin-top:16px;padding:24px 0;border-bottom:1px solid var(--line)}
      .docs-main section:last-of-type{border-bottom:none;padding-bottom:4px}
      .docs h3{font-size:17px;margin:0 0 8px;display:flex;align-items:center;gap:9px}
      .docs h3 .n{width:26px;height:26px;flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;font-size:12px;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--brand-start),var(--brand-end))}
      .docs h4{font-size:13.5px;margin:18px 0 6px;color:var(--tx)}
      .docs p{margin:8px 0;line-height:1.65;font-size:14px;color:var(--tx)}
      .docs ul{margin:8px 0;padding-left:22px;line-height:1.7;font-size:14px}
      .docs li{margin:4px 0}
      .docs code{background:var(--in);border:1px solid var(--line);border-radius:4px;padding:1px 5px;font-family:ui-monospace,Consolas,monospace;font-size:12.5px}
      .docs pre{background:var(--in);border:1px solid var(--line);border-radius:6px;padding:10px 12px;overflow-x:auto;font-family:ui-monospace,Consolas,monospace;font-size:12.5px;line-height:1.5;margin:10px 0}
      .docs pre code{background:none;border:none;padding:0}
      .docs .note{border-left:3px solid var(--warn);background:rgba(210,153,34,.08);padding:8px 12px;border-radius:0 6px 6px 0;margin:10px 0;font-size:13px}
      .docs .tip{border-left:3px solid var(--ok);background:rgba(63,185,80,.08);padding:8px 12px;border-radius:0 6px 6px 0;margin:10px 0;font-size:13px}
      @media (max-width:820px){ .docs{flex-direction:column} .docs-side{position:static;width:100%;flex:none;max-height:none;overflow-y:visible} .docs-side nav{flex-direction:row;flex-wrap:wrap} .docs-side nav a{border-left:none;border-bottom:2px solid transparent} .docs-side nav a.active{border-left:none;border-bottom-color:var(--ac)} }
    </style>

    <div class="docs">
      <aside class="docs-side">
        <div class="docs-search" id="docsSearchBox">
          <input type="search" id="docsSearch" placeholder="Buscar en la documentación…" autocomplete="off" spellcheck="false">
          <button type="button" class="clr" id="docsSearchClear" title="Borrar búsqueda" aria-label="Borrar búsqueda">&times;</button>
        </div>
        <div class="docs-search-hint" id="docsSearchHint"></div>
        <div class="side-title">En esta página</div>
        <nav id="docsNav">
          <a href="#intro">Qué es</a>
          <a href="#proyectos">Proyectos</a>
          <a href="#ficha">Ficha de proyecto</a>
          <a href="#php">Versiones de PHP</a>
          <a href="#bd">Bases de datos</a>
          <a href="#https">HTTPS local</a>
          <a href="#mailpit">Mailpit (correo)</a>
          <a href="#terminal">Terminal y runner</a>
          <a href="#lan">Exponer en LAN</a>
          <a href="#startup">Arrancar con Windows</a>
          <a href="#dominios">Dominios y hosts</a>
          <a href="#marca">Identidad / marca</a>
          <a href="#cli">Comandos (CLI)</a>
          <a href="#trampas">Problemas frecuentes</a>
        </nav>
      </aside>

      <div class="docs-main">
      <h2>Documentación de <?= e($brandName) ?></h2>
      <p class="lead">Guía de uso de esta plataforma: un servidor PHP local portable para Windows (Apache + mod_fcgid con varias versiones de PHP a la vez) con este panel de administración web.</p>

      <div class="docs-noresults" id="docsNoResults">Sin resultados para "<span id="docsNoResultsTerm"></span>". Prueba con otra palabra.</div>

      <section id="intro">
        <h3><span class="n">i</span> Qué es esta plataforma</h3>
        <p><?= e($brandName) ?> es un entorno de desarrollo PHP local para Windows, pensado para ser <strong>portable</strong> (vive en una carpeta, sin instalar nada en el sistema) y arrancar sin permisos de administrador salvo cuando de verdad hace falta (HTTPS, editar el archivo <code>hosts</code>, instalar el servicio de Windows o abrir el Firewall).</p>
        <ul>
          <li><strong>Apache + mod_fcgid</strong> sirviendo cada proyecto en su propio dominio local.</li>
          <li><strong>Varias versiones de PHP a la vez</strong> (7.1 a 8.5): cada proyecto elige la suya.</li>
          <li><strong>MariaDB</strong>, <strong>PostgreSQL</strong> y <strong>MongoDB</strong> nativos opcionales, con phpMyAdmin/Adminer/mongo-express integrados.</li>
          <li>Este <strong>panel web</strong> para gestionarlo todo sin tocar archivos de configuración a mano.</li>
        </ul>
        <p>El panel solo es accesible desde esta misma máquina (<code>http://127.0.0.1</code> o <code>http://<?= e($tld) ?></code>).</p>
      </section>

      <section id="proyectos">
        <h3><span class="n">1</span> Proyectos</h3>
        <p>En la pestaña <strong>Proyectos</strong> se listan todos tus sitios. Cada uno se sirve en <code>&lt;nombre&gt;.<?= e($tld) ?></code>.</p>
        <h4>Crear un proyecto</h4>
        <p>Usa el formulario de alta: eliges nombre y versión de PHP. Puedes crear un proyecto vacío o desde una plantilla (por ejemplo WordPress, que se descarga y prepara solo). La carpeta se crea dentro de <code>www\</code>.</p>
        <h4>Proyectos externos</h4>
        <p>Un proyecto puede vivir <em>fuera</em> de <code>www\</code> (por ejemplo en <code>C:\proyectos\mi-app</code>). Se registran con la ruta completa y se marcan con la etiqueta <span class="tag">externo</span>. Si detecta una carpeta <code>public/</code> (Laravel/Symfony) la usa como raíz automáticamente.</p>
        <h4>Adoptar carpetas sin registrar</h4>
        <p>Si dejas una carpeta dentro de <code>www\</code> que no está dada de alta, el panel la detecta y ofrece un botón <strong>Adoptar</strong> para registrarla como proyecto.</p>
        <h4>Cambiar la versión de PHP</h4>
        <p>Cada card tiene un selector de versión de PHP. Al cambiarlo, se regenera la configuración y Apache se recarga solo.</p>
        <h4>Carátula, bloqueo y borrado</h4>
        <ul>
          <li><strong>Carátula:</strong> puedes subir una imagen de portada para identificar el proyecto de un vistazo.</li>
          <li><strong>Bloqueo:</strong> el candado protege el proyecto contra el borrado. Por dentro crea un archivo <code>.lua</code> en la raíz; mientras exista <em>cualquier</em> archivo <code>.lua</code> ahí, el proyecto no se puede eliminar.</li>
          <li><strong>Eliminar:</strong> solo desregistra el sitio del panel; <strong>no borra la carpeta del disco</strong>. Tus archivos siguen ahí.</li>
        </ul>
      </section>

      <section id="ficha">
        <h3><span class="n">2</span> Ficha de proyecto</h3>
        <p>Pulsa el icono de detalle de una card para abrir su ficha, con todo lo del proyecto en un sitio:</p>
        <ul>
          <li><strong>Git:</strong> rama actual, si hay cambios sin commitear y los últimos commits.</li>
          <li><strong>Log de errores:</strong> las últimas líneas del log de Apache de ese proyecto.</li>
          <li><strong>Archivos:</strong> árbol navegable. Al pulsar un archivo se abre un editor de código (con resaltado) para editarlo y guardarlo en el momento.</li>
          <li><strong>Desplegar por FTP:</strong> configura host/usuario/ruta y sube el proyecto a tu hosting con un clic.</li>
          <li><strong>Terminal:</strong> si la terminal está activada, aquí tienes una que <strong>arranca ya en la carpeta del proyecto</strong>.</li>
        </ul>
      </section>

      <section id="php">
        <h3><span class="n">3</span> Versiones de PHP</h3>
        <p>En <strong>Versiones PHP</strong> editas el <code>php.ini</code> de cada versión instalada. Los cambios se guardan como <em>overrides</em> (sobreviven a las regeneraciones) y se aplican recargando Apache automáticamente.</p>
        <ul>
          <li>Ajustes rápidos: zona horaria, límite de memoria, tamaño de subida, tiempo de ejecución, mostrar errores…</li>
          <li>Directivas libres adicionales (una por línea, formato <code>clave = valor</code>).</li>
          <li><strong>Xdebug:</strong> se activa/desactiva por versión con un botón (depuración paso a paso en el puerto <code>9003</code> para VS Code o PhpStorm).</li>
          <li><strong>Extensiones adicionales:</strong> instala cualquier extensión de terceros (p.ej. <code>pdo_sqlsrv</code> de Microsoft para SQL Server) subiendo el <code>.dll</code> ya extraído o pegando una URL directa. Se activa sola en cuanto el archivo existe para esa versión.</li>
        </ul>
      </section>

      <section id="bd">
        <h3><span class="n">4</span> Bases de datos</h3>
        <p>La plataforma trae varios motores de base de datos, todos opcionales y nativos (portables, se descargan al activarlos). En la pestaña <strong>Bases de datos</strong> hay un selector arriba para cambiar entre <strong>MySQL / MariaDB</strong> y <strong>PostgreSQL</strong> (MongoDB se gestiona aparte, ver más abajo).</p>
        <h4>MySQL (MariaDB)</h4>
        <p>MariaDB nativo en <code>127.0.0.1:3306</code>, usuario <code>root</code> (sin contraseña por defecto). Solo accesible desde esta máquina.</p>
        <ul>
          <li>Crear/eliminar bases de datos y crear usuarios de aplicación.</li>
          <li>Gestionar la contraseña de <code>root</code>.</li>
          <li><strong>Exportar</strong> (backup <code>.sql</code>) e <strong>importar</strong> una base de datos.</li>
          <li><strong>phpMyAdmin</strong> y <strong>Adminer</strong> integrados para trabajar visualmente.</li>
        </ul>
        <h4>PostgreSQL</h4>
        <p>PostgreSQL 16 nativo en <code>127.0.0.1:5432</code>, usuario <code>postgres</code> (sin contraseña, autenticación <em>trust</em> solo en localhost). Actívalo en <strong>Configuración del servidor</strong>.</p>
        <ul>
          <li>Crear/eliminar bases de datos y roles (con contraseña) desde el panel.</li>
          <li>Al crear un rol para una base de datos concreta, se le asigna como <strong>dueño</strong> (control total sobre ella).</li>
          <li><strong>Exportar</strong> (<code>pg_dump</code>) e <strong>importar</strong> (<code>psql</code>) una base de datos.</li>
          <li><strong>Adminer</strong> integrado (habla PostgreSQL de forma nativa) para gestionar tablas y datos.</li>
        </ul>
        <h4>MongoDB</h4>
        <p>MongoDB Community nativo en <code>127.0.0.1:27017</code>, sin autenticación (solo accesible desde esta máquina). Se activa desde <strong>Configuración del servidor</strong> con un botón: en la misma descarga se instala también un runtime de Node.js portable y <strong>mongo-express</strong>, su gestor visual.</p>
        <ul>
          <li>No pasa por Apache ni tiene dominio propio: <strong>mongo-express</strong> se abre directo en <code>http://127.0.0.1:8081/</code>, sin login.</li>
          <li>mongo-express arranca y se detiene junto con el motor (un único botón para ambos).</li>
        </ul>
        <p class="tip">Nota: <em>phpMyAdmin</em> solo sirve para MySQL; para PostgreSQL el gestor visual es Adminer, ya incluido; para MongoDB es <em>mongo-express</em>.</p>
        <p>Desde tus proyectos conéctate con host <code>127.0.0.1</code>, puerto <code>3306</code> (MySQL, usuario <code>root</code>), <code>5432</code> (PostgreSQL, usuario <code>postgres</code>) o <code>27017</code> (MongoDB, sin autenticación).</p>
      </section>

      <section id="https">
        <h3><span class="n">5</span> HTTPS local</h3>
        <p>Activa <strong>HTTPS local</strong> en Configuración para servir tus proyectos en <code>https://&lt;proyecto&gt;.<?= e($tld) ?></code> con certificados de confianza (candado verde, sin avisos del navegador). La primera vez, Windows pedirá permiso para instalar la autoridad certificadora (CA) en el almacén de confianza.</p>
      </section>

      <section id="mailpit">
        <h3><span class="n">6</span> Mailpit (captura de correo)</h3>
        <p>Con Mailpit activado, todos los correos que envíen tus proyectos con <code>mail()</code> se <strong>atrapan</strong> (no salen a internet) y se ven en un buzón web en <code>http://localhost:8025</code>. Ideal para probar emails de registro, recuperación de contraseña, etc. sin spamear a nadie.</p>
      </section>

      <section id="terminal">
        <h3><span class="n">7</span> Terminal y runner de comandos</h3>
        <p>La <strong>Terminal</strong> viene desactivada por seguridad (permite ejecutar cualquier comando con los permisos de Apache). Actívala solo si confías en quién tiene acceso al panel.</p>
        <ul>
          <li>Mantiene el directorio de trabajo entre comandos (<code>cd</code> persiste) y colorea la salida.</li>
          <li>No es una terminal interactiva completa (PTY): programas a pantalla completa como <code>vim</code> o <code>nano</code> no funcionan.</li>
          <li>Historial con las flechas ↑/↓ y <code>Ctrl+L</code> para limpiar.</li>
        </ul>
        <h4>Ejecutar Composer / NPM / Artisan en un proyecto</h4>
        <p>En cada card con <code>composer.json</code>, <code>package.json</code> o <code>artisan</code> aparece un botón de <strong>play</strong> (también en la ficha del proyecto) que abre un modal para lanzar comandos sobre ese proyecto (<code>composer install</code>, <code>npm run dev</code>, <code>php artisan migrate</code>…). Puedes escribir <strong>comandos personalizados y guardarlos</strong> como accesos rápidos reutilizables. El runner usa el PHP propio del proyecto automáticamente. Los comandos destructivos (como <code>migrate:fresh</code>) se marcan en rojo y piden confirmación antes de ejecutarse.</p>
      </section>

      <section id="lan">
        <h3><span class="n">8</span> Exponer en la red local (LAN)</h3>
        <p>El toggle <strong>Exponer en la red local</strong> (en Configuración) abre el puerto <?= is_file($ROOT.'/config/https.on')?'80/443':'80' ?> en el Firewall de Windows, <strong>limitado a tu subred local</strong>, para que otros dispositivos de tu misma red o WiFi puedan abrir tus proyectos. Windows pedirá permiso (UAC) al activarlo.</p>
        <p>El panel te mostrará tu <strong>IP en la red local</strong>. Como los otros equipos no resuelven los dominios <code>.<?= e($tld) ?></code>, en <em>ese</em> equipo hay que añadir al archivo <code>hosts</code> una línea por proyecto:</p>
        <pre><code>&lt;tu-IP-LAN&gt;   miproyecto.<?= e($tld) ?></code></pre>
        <p>y luego abrir <code>http://miproyecto.<?= e($tld) ?></code> desde ahí.</p>
        <div class="tip">El panel de administración sigue restringido a esta máquina (<code>127.0.0.1</code>) aunque el puerto esté abierto: los demás equipos pueden ver tus proyectos, pero no este panel.</div>
      </section>

      <section id="startup">
        <h3><span class="n">9</span> Arrancar con Windows</h3>
        <p>Instala Apache como <strong>servicio de Windows</strong> (arranque automático) y el watcher como tarea programada, para que la plataforma esté disponible nada más encender el equipo, sin iniciar sesión. Windows pedirá permiso (UAC) al activarlo o desactivarlo.</p>
      </section>

      <section id="dominios">
        <h3><span class="n">10</span> Dominios y archivo hosts</h3>
        <p>El dominio local por defecto es <code><?= e($tld) ?></code> (recomendado: <code>test</code>, reservado oficialmente para pruebas). Para que <code>&lt;proyecto&gt;.<?= e($tld) ?></code> abra en el navegador, hay que registrar los dominios en el archivo <code>hosts</code> de Windows: pulsa <strong>Sincronizar dominios</strong> en Configuración (pide UAC una vez).</p>
        <div class="note">Si <code>localhost</code> te carga otra cosa (por ejemplo Docker/Portainer, que puede ocupar el puerto 80 por IPv6), usa <code>http://127.0.0.1</code> o <code>http://<?= e($tld) ?></code> a secas — siempre te traen aquí.</div>
      </section>

      <section id="marca">
        <h3><span class="n">11</span> Identidad / marca</h3>
        <p>En Configuración puedes cambiar el <strong>nombre</strong> y el <strong>logo</strong> de la plataforma. Aparecen en la cabecera, en la pestaña del navegador y en el pie. Deja el nombre vacío para volver a <code>lua-server</code>, o restablece el logo al de por defecto cuando quieras.</p>
      </section>

      <section id="cli">
        <h3><span class="n">12</span> Comandos (CLI)</h3>
        <p>Todo lo del panel también se puede hacer desde PowerShell con <code>lua.ps1</code>. Los más útiles:</p>
        <pre><code>.\lua.ps1 start        # arranca Apache + watcher
.\lua.ps1 stop         # para todo
.\lua.ps1 restart      # reinicia Apache
.\lua.ps1 status       # estado del stack
.\lua.ps1 add-site &lt;nombre&gt; [php]
.\lua.ps1 add-external &lt;nombre&gt; &lt;ruta&gt; [dominio] [php]
.\lua.ps1 switch-php &lt;nombre&gt; &lt;version&gt;
.\lua.ps1 reload       # regenera vhosts + hosts + reinicia</code></pre>
      </section>

      <section id="trampas">
        <h3><span class="n">!</span> Problemas frecuentes</h3>
        <h4><code>localhost</code> me carga otra cosa</h4>
        <p>En equipos con Docker Desktop, el puerto 80 puede estar ocupado por IPv6. Usa <code>http://127.0.0.1</code> o <code>http://<?= e($tld) ?></code>.</p>
        <h4>Cambié algo y "no hace nada"</h4>
        <p>El botón <strong>Reiniciar</strong> del panel reinicia Apache. Si el cambio afecta al comportamiento en segundo plano (watcher) y editaste el script <code>lua.ps1</code>, hay que pararlo y arrancarlo del todo:</p>
        <pre><code>.\lua.ps1 stop
.\lua.ps1 start</code></pre>
        <h4>Un proyecto no se deja borrar</h4>
        <p>Está bloqueado: hay un archivo <code>.lua</code> en su carpeta. Quítalo (o usa el botón de desbloqueo) para poder eliminarlo del panel.</p>
        <h4>Composer/PHP "no se encuentra"</h4>
        <p>El runner y la terminal usan el PHP propio de la plataforma; no necesitas un PHP global instalado en Windows.</p>
      </section>

      </div><!-- /.docs-main -->
    </div><!-- /.docs -->

    <script>
    (function(){
      var nav = document.getElementById('docsNav');
      if (!nav) return;
      var links = Array.prototype.slice.call(nav.querySelectorAll('a'));
      var sections = links.map(function(a){ return document.getElementById(a.getAttribute('href').slice(1)); });
      var scroller = document.querySelector('.content') || window;

      // --- resalta la seccion activa del indice segun el scroll ---
      function onScroll(){
        var refY = (scroller === window ? 0 : scroller.getBoundingClientRect().top) + 40;
        var current = null;
        for (var i = 0; i < sections.length; i++) {
          var s = sections[i];
          if (s && s.classList.contains('nomatch')) continue;
          if (s && s.getBoundingClientRect().top - refY <= 0) current = s;
        }
        links.forEach(function(a, i){ a.classList.toggle('active', sections[i] === current); });
      }
      scroller.addEventListener('scroll', onScroll, { passive: true });

      // --- buscador: filtra secciones/indice y resalta coincidencias ---
      var input   = document.getElementById('docsSearch');
      var clearBt = document.getElementById('docsSearchClear');
      var box     = document.getElementById('docsSearchBox');
      var hint    = document.getElementById('docsSearchHint');
      var noRes   = document.getElementById('docsNoResults');
      var noResTerm = document.getElementById('docsNoResultsTerm');
      function norm(s){
        return (s || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
      }
      function clearHighlights(root){
        root.querySelectorAll('mark.docs-hl').forEach(function(m){
          var t = document.createTextNode(m.textContent);
          m.parentNode.replaceChild(t, m);
        });
        root.normalize();
      }
      function highlight(root, rawTerm){
        if (!rawTerm) return;
        var re;
        try { re = new RegExp('(' + rawTerm.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'ig'); } catch(e){ return; }
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
          acceptNode: function(n){
            var p = n.parentNode;
            if (!n.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
            if (p && (p.tagName === 'SCRIPT' || p.tagName === 'STYLE' || p.tagName === 'MARK')) return NodeFilter.FILTER_REJECT;
            return re.test(n.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
          }
        });
        var hits = [];
        var n; while ((n = walker.nextNode())) hits.push(n);
        hits.forEach(function(textNode){
          re.lastIndex = 0;
          var frag = document.createDocumentFragment();
          var last = 0, m;
          while ((m = re.exec(textNode.nodeValue))) {
            frag.appendChild(document.createTextNode(textNode.nodeValue.slice(last, m.index)));
            var mk = document.createElement('mark'); mk.className = 'docs-hl'; mk.textContent = m[0];
            frag.appendChild(mk);
            last = m.index + m[0].length;
          }
          frag.appendChild(document.createTextNode(textNode.nodeValue.slice(last)));
          textNode.parentNode.replaceChild(frag, textNode);
        });
      }
      function runSearch(){
        var raw = input.value.trim();
        var q = norm(raw);
        box.classList.toggle('has-query', raw !== '');
        var visibleCount = 0;
        sections.forEach(function(sec, i){
          clearHighlights(sec);
          var match = q === '' || norm(sec.textContent).indexOf(q) !== -1;
          sec.classList.toggle('nomatch', !match);
          links[i].classList.toggle('nomatch', !match);
          if (match) { visibleCount++; if (raw) highlight(sec, raw); }
        });
        noRes.style.display = (raw && visibleCount === 0) ? 'block' : 'none';
        if (raw && visibleCount === 0 && noResTerm) noResTerm.textContent = raw;
        hint.textContent = raw ? (visibleCount + ' de ' + sections.length + ' secciones') : '';
        onScroll();
      }
      input.addEventListener('input', runSearch);
      clearBt.addEventListener('click', function(){ input.value = ''; runSearch(); input.focus(); });
      input.addEventListener('keydown', function(e){ if (e.key === 'Escape') { input.value=''; runSearch(); input.blur(); } });
      // Atajo tipo "doc site": "/" enfoca el buscador si no estas ya escribiendo en algo.
      document.addEventListener('keydown', function(e){
        if (e.key !== '/' || e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) return;
        if (!document.getElementById('docsSearch')) return; // solo si la pestana Documentacion esta activa
        e.preventDefault();
        input.focus();
      });

      onScroll();
    })();
    </script>


<?php endif; ?>
