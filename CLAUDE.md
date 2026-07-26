# lua-server — contexto para Claude Code

Servidor PHP local portable para Windows (Apache + mod_fcgid + múltiples
versiones de PHP simultáneas, 7.1–8.5), con panel de administración web en
PHP vanilla y un tema propio de phpMyAdmin. Todo pensado para arrancar sin
admin salvo cuando hace falta (HTTPS, hosts, servicio de Windows).

## Arquitectura clave

- **`lua.ps1`** — CLI principal (init/start/stop/restart/reload/status/
  add-site/add-external/switch-php/setup/startup-enable/...). Toda la
  lógica de generación de vhosts, php.ini, etc. vive aquí.
- **`tools/dashboard/index.php`** — el panel web (un solo archivo grande,
  patrón PRG para POST, sin build step ni JS framework — solo PHP + CSS +
  JS vanilla inline).
- **El watcher** (`lua.ps1 watch`) es un **proceso PowerShell aparte**,
  arrancado por `Cmd-Start`, que hace polling cada 1s de archivos-señal en
  `tmp/*.flag` para ejecutar acciones privilegiadas que el panel no puede
  hacer directamente (regenerar vhosts, HTTPS, sync de hosts, arrancar/parar
  Mailpit/MariaDB, arranque-con-Windows). El panel nunca ejecuta estas
  acciones él mismo: solo dispara flags y el watcher las recoge.
- **`config/sites.json`** — no versionado (cada máquina el suyo). Cada
  sitio puede tener `php`, `domain` (opcional) y `path` (opcional, para
  proyectos **externos** fuera de `www\`, ver más abajo).
- **`www/`** — ignorado por completo en git (`/www/*`). Cada proyecto ahí
  dentro tiene su propio repo; lua-server no versiona código de proyectos.
  Solo debe contener proyectos del usuario: phpMyAdmin vive en
  `tools/phpmyadmin/` (fuera de `www\`, vía el campo `path` en
  `sites.json`, igual que un proyecto externo) para que `www\` quede
  limpio. Tampoco está en este repo (`/tools/phpmyadmin/` en
  `.gitignore`), pero el tema `lua` **sí** — su parte realmente custom
  (todo lo demás es idéntico de fábrica al tema `pmahomme`: los 254
  iconos y `screen.png` no se versionan, se copian tal cual en cada
  instalación) vive en `config/phpmyadmin-theme/` (`theme.json`,
  `override.css` — el bloque de reskin, no el `theme.css` de
  `pmahomme` — y las dos fuentes autoalojadas). `logo.svg`/`favicon.svg`
  se reutilizan directamente de `tools/dashboard/assets/` (mismos
  archivos que el logo del panel, ya versionados, sin duplicar). Al
  (re)instalar phpMyAdmin (`Install-CatalogItem`, caso `'phpmyadmin'`,
  en `config/install-lib.ps1`, usado tanto por `install.ps1` como por
  `bootstrap.ps1`), el tema `lua` se reconstruye solo: copia
  `themes/pmahomme/` → `themes/lua/`, pisa `theme.json`/fuentes/logo con
  las versiones de lua-server, y concatena el `theme.css` recién
  copiado (fábrica) con `override.css` (custom). `config.inc.php` fija
  además `ThemeDefault = 'lua'` para que arranque ya con ese tema, sin
  tener que elegirlo a mano en el selector de temas de phpMyAdmin.

## ⚠️ Trampa nº1: el watcher cachea el código en memoria

Si editas `lua.ps1` con el watcher ya corriendo, **el watcher sigue
usando el código viejo** hasta que se reinicia. El botón "Reiniciar" del
panel (y `lua.ps1 restart`) **solo reinicia Apache, no el watcher**. Para
que cambios en `lua.ps1` surtan efecto de verdad:
```powershell
.\lua.ps1 stop
.\lua.ps1 start
```
Este bug se repitió varias veces esta sesión (features que "no hacían
nada" porque el watcher viejo no conocía la acción nueva).

## ⚠️ Trampa nº2: `localhost` no es fiable en esta máquina

Docker Desktop (`com.docker.backend`/`wslrelay`) ocupa el puerto 80 en
IPv6 (`::1` y `::`), sirviendo Portainer u otro contenedor. Windows
resuelve `localhost` mezclando IPv4 **e** IPv6 pase lo que pase — **ni
siquiera fijar `127.0.0.1 localhost` en el `hosts` lo arregla del todo**
(Windows trata `localhost` como nombre especial y no lo respeta al 100%,
confirmado con `Resolve-DnsName` devolviendo igualmente el registro AAAA).

**Soluciones que sí funcionan, verificadas:**
- `http://127.0.0.1` — siempre fiable.
- `http://lua.test` a secas — como no es un nombre reservado, el `hosts`
  sí se respeta. Se añade solo al pulsar "Sincronizar dominios" (ver
  `Update-Hosts` en `lua.ps1`).

No merece la pena seguir intentando arreglar `localhost` en sí; usar
`127.0.0.1` o `lua.test` en su lugar.

## ⚠️ Trampa nº3: herramientas de navegador/shell en sandbox

- El **Bash tool** de esta sesión corre en un entorno aislado, no
  necesariamente el Windows real. `curl http://localhost/` desde Bash
  puede devolver algo completamente distinto (p.ej. un gateway nginx con
  `timeout.html`) a lo que ve el usuario en su Chrome real. Los comandos
  tipo `ipconfig /flushdns`, `Get-Service`, `Get-ScheduledTask`, etc.
  ejecutados vía **Bash** pueden no reflejar ni afectar al host real —
  usar la herramienta **PowerShell** para eso.
- La **Browser pane** (`mcp__Claude_Browser__*`) también es un entorno
  aislado sin acceso al `localhost` real de esta máquina.
- Para probar de verdad en el navegador del usuario: usar
  **`mcp__claude-in-chrome__*`** (su Chrome real, vía extensión).

## ⚠️ Trampa nº4: nunca clicar por coordenadas en el navegador real

Un desajuste entre el tamaño del screenshot (p.ej. 1568px) y el viewport
real (p.ej. 1920px) hizo que clics "a ciegas" por coordenadas cayeran en
botones equivocados — **esto borró dos proyectos registrados por
accidente** (las carpetas en disco no se tocan al "eliminar", solo se
desregistran, así que fue recuperable, pero el susto fue real).

**Regla desde ahora: usar siempre `read_page`/`find` para obtener un
`ref` y clicar por referencia, nunca por coordenadas (x,y).**

## ⚠️ Trampa nº5: el runner "Ejecutar en &lt;proyecto&gt;" (composer/npm) llevaba tres bugs a la vez

El botón de play de cada proyecto (`tools/dashboard/index.php`, `luaOpenRunner`)
fallaba con "El sistema no puede encontrar la ruta especificada" para
absolutamente cualquier comando. Costó bastante depurar porque eran **tres
bugs independientes apilados**, y arreglar solo uno seguía dando el mismo
error:

1. **La ruta del proyecto llegaba con las barras `\` borradas.** El botón
   pasaba la ruta dentro de un atributo `onclick="luaOpenRunner('C:\personal\...')"`.
   Ese atributo se compila como **código JS**, y ahí `\p`, `\L`, `\w`, `\a`
   son secuencias de escape que el navegador consume en silencio (la barra
   desaparece): `C:\personal\LuaServer` llegaba al backend como
   `C:personalLuaServer`, y el `cd /d` fallaba siempre — para *cualquier*
   proyecto, no solo con nombres de usuario raros. Arreglado pasando la ruta
   por `data-*` (texto plano, sin parseo JS) y un listener delegado en vez
   de `onclick` inline. **Regla: nunca metas una ruta de Windows (con `\`)
   dentro de un atributo `onclick="...'...'"` — usa `data-*`.**
2. **mod_fcgid + `shell_exec()`/`exec()` propio en Windows = riesgo real de
   colgar el worker.** Para refrescar el `PATH` (ver más abajo) probé primero
   lanzando `powershell.exe` con `shell_exec()` desde PHP — el request volvía
   con respuesta vacía (el worker moría a mitad de petición), igual que el
   bug ya conocido de opcache+PHP viejo. La app ya evita esto para lanzar
   comandos (usa COM `WScript.Shell.Run`, nunca `exec`/`proc_open`); el
   mismo cuidado aplica a *cualquier* subproceso propio que lance PHP bajo
   Apache en Windows, no solo al comando final del usuario. Arreglado leyendo
   el PATH vía `WScript.Shell.RegRead` (COM, sin subproceso) en vez de
   `shell_exec`.
3. **COM devuelve texto en el codepage ANSI del sistema (Windows-1252 aquí),
   no UTF-8.** El wrapper `.cmd` hace `chcp 65001` (UTF-8) al principio; si
   luego se mete una cadena ANSI sin convertir (p.ej. una ruta con
   "Vázquez"), el byte no-UTF8 rompe el parseo del resto del `.cmd` y el
   comando entero falla. Arreglado con
   `mb_convert_encoding($s, 'UTF-8', 'Windows-1252')` sobre cualquier cadena
   que venga de COM y vaya a parar a un `.cmd` ya en modo `chcp 65001`.

Además, ese runner ahora manda la versión de PHP del proyecto (`bin\php\<ver>`)
al principio del `PATH` de cada comando, para que `composer`/`php` usen
**el PHP propio de lua-server** en vez de depender de un PHP global del
sistema (que en esta máquina ni siquiera existe pese a estar en el PATH).

## Gotchas de PowerShell

- `$cfg.sites.PSObject.Properties.Name.Contains($name)` revienta con
  `$null` cuando la colección está vacía (PowerShell devuelve `$null` en
  vez de array vacío al enumerar miembros de una colección vacía). Usar
  siempre `-contains` en su lugar: `($cfg.sites.PSObject.Properties.Name -contains $name)`.
- No usar `$pid` como nombre de variable propia — colisiona (sin
  distinguir mayúsculas) con la variable automática de solo-lectura `$PID`.
- Una tarea programada registrada con `-Principal (UserId SYSTEM, RunLevel
  Highest)` da **"Acceso denegado"** al consultarla (`Get-ScheduledTask`,
  `schtasks /query`) desde una sesión **no elevada** — fácil confundirlo
  con "no existe" si se usa `-ErrorAction SilentlyContinue` y se trata el
  resultado nulo como ausencia. Hay que comprobarlo elevado, o fiarse de
  la propia consulta del panel (que corre como SYSTEM una vez Apache es
  servicio, y por tanto sí tiene permiso).
- Una vez Apache está instalado como **servicio de Windows** (`luaApache`),
  una PowerShell normal (no elevada) ya **no puede** `Restart-Service`/
  `Stop-Service` sobre él (acceso denegado). Pero el propio panel
  (PHP corriendo bajo el servicio, como SYSTEM) sí puede reiniciarlo desde
  dentro — por eso el botón "Reiniciar" del panel funciona aunque una
  consola de admin normal no pueda.

## PHP < 7.2 en Windows: dos bugs reales, ya arreglados en `Set-PhpInis`

1. **Sintaxis de extensión distinta**: PHP < 7.2 exige el nombre completo
   de la DLL (`extension=php_curl.dll`), mientras que 7.2+ acepta la forma
   corta (`extension=curl`). `Set-PhpInis` detecta la versión
   (`$oldStyle = [version]$ver -lt [version]'7.2'`) y ajusta el formato.
2. **opcache + mod_fcgid + PHP antiguo en Windows = crash**: con opcache
   activo, `php-cgi.exe` moría a mitad de petición bajo mod_fcgid
   (`End of script output before headers`, `OS 109 broken pipe` en el log
   de Apache) — pero funcionaba bien en CLI/CGI aislado, lo que despistaba
   bastante. Arreglo: opcache se omite por completo para PHP < 7.2 en el
   php.ini generado.

## Sistema de diseño / marca

- Paleta y mecanismo de tema **igual en el panel y en phpMyAdmin**: oscuro
  por defecto, claro vía `@media (prefers-color-scheme: light)` — no es
  "modo oscuro fijo", se adapta al sistema. Ojo: el sistema de este usuario
  está en modo **claro**, así que lo que se ve al probar es la variante
  clara.
- Degradado de marca `135deg, #6ea8fe → #9b6efe` — usado en el logo y (por
  petición del usuario) en **todos los botones normales** de toda la
  plataforma (panel + phpMyAdmin). Los botones de peligro/destructivos
  usan un degradado en tonos rojos (`#f85149 → #b3261e`) en vez del
  degradado de marca, para no perder la señal visual de "esto borra algo".
- Logo/favicon reales (monograma "LUA" vectorial) vienen de
  `design_handoff_dashboard/assets/` — copiados a `tools/dashboard/assets/`
  (panel) y `tools/phpmyadmin/themes/lua/` (phpMyAdmin). La carpeta
  `design_handoff_dashboard/` en sí queda fuera de git (material de
  referencia, no código).
- Tipografía del tema de phpMyAdmin: Space Grotesk (UI) + JetBrains Mono
  (código/SQL), autoalojadas en `themes/lua/fonts/` (sin CDN externo).
- El tema `lua` de phpMyAdmin se regenera concatenando el `theme.css`
  original de `pmahomme` + un bloque de override (mismo patrón de tokens
  que el panel). Cualquier retoque de estilo de phpMyAdmin pasa por ese
  override, no por editar `theme.css` a mano (se pisa al regenerar).

## Features añadidas en esta sesión (por si tocan código relacionado)

- **Terminal web** (pestaña Terminal) — polling AJAX, colores ANSI, cwd
  persistente. Apagada por defecto (`config/terminal.on`, toggle de
  seguridad).
- **Proyectos externos** — un sitio puede vivir fuera de `www\` via el
  campo `path` en `sites.json`. CLI: `lua.ps1 add-external <nombre> <ruta>
  [dominio] [php]`. Detecta `public/` automáticamente (Laravel/Symfony).
- **Adoptar proyectos sin registrar** — el panel detecta carpetas en
  `www\` que no están en `sites.json` y ofrece un botón "Adoptar".
- **Bloqueo de proyectos** — cualquier archivo `*.lua` en la raíz de un
  proyecto impide borrarlo (aplicado en servidor, no solo en la UI).
- **Carátula de proyecto** — subida de imagen (`data/covers/`, validada
  con `getimagesize`/sniff de SVG, servida via `?cover=<nombre>`).
- **Botones de apagar/reiniciar** en el header — lanzan `lua.ps1
  stop/restart` en un proceso desatendido (COM `WScript.Shell` + `.cmd`
  con un pequeño respiro) para que la respuesta HTTP llegue antes de que
  Apache caiga.
- **"Arrancar con Windows"** (pestaña Configuración) — instala Apache como
  servicio de Windows (arranque automático) + registra una tarea
  programada (SYSTEM, `AtStartup`) para el watcher. Mismo patrón de
  flag+watcher+relanzamiento elevado que HTTPS/hosts-sync.
- **MariaDB nativo** + Adminer como alternativa a phpMyAdmin
  (`config/mariadb.on`, root sin contraseña en `127.0.0.1:3306`).
- **PHP 7.1.33** añadido (necesario para apps legacy tipo Laravel 5.x).
- **MongoDB nativo** (`config/mongodb.on`, `127.0.0.1:27017`, sin
  autenticación) + **mongo-express** como gestor visual — primer uso de
  **Node.js portable** en el repo (`bin\node\`), instalado vía `npm` al
  activar el motor. Mismo patrón flag+watcher que MariaDB/PostgreSQL
  (`Start-MongoDb`/`Stop-MongoDb`/`Start-MongoExpress`/`Stop-MongoExpress`
  en `lua.ps1`), pero con una diferencia clave: como Apache **no tiene
  `mod_proxy` cargado** (nunca se ha usado en este repo) y mongo-express
  habla HTTP propio (Node), no se le da dominio/vhost como a phpMyAdmin —
  se expone directo en `http://127.0.0.1:8081/`, igual de "sin fricción"
  que MariaDB en su puerto 3306. mongo-express sigue al mismo flag que el
  motor (un solo botón enciende/apaga ambos). "Up" de MongoDB se comprueba
  vía `processManagement.pidFilePath` en `mongod.cfg` (generado por
  `Set-MongoConf`), no por `mongod.lock` (formato no documentado) ni por
  nombre de proceso global — mismo criterio que `Postgres-Up` con
  `postmaster.pid`.

## Convenciones de UI ya establecidas

- Banners de feedback con tipos `applied`/`info`/`job`/`error`, con
  auto-recarga por JS (`applied` a los 4.2s, `info` a los 7s).
- Modal de confirmación propio (overlay + box, mostrar/ocultar por JS,
  cierre con Escape) en vez de `confirm()` nativo — usado para borrar
  proyecto, apagar y reiniciar.
- Nunca emojis en la UI — iconos SVG inline stroke-based, minimalistas.
