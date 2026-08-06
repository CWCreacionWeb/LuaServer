# lua-server — contexto para Claude Code

Servidor PHP local portable para Windows (Apache + mod_fcgid + múltiples
versiones de PHP simultáneas, 7.1–8.5), con panel de administración web en
PHP vanilla y un tema propio de phpMyAdmin. Todo pensado para arrancar sin
admin salvo cuando hace falta (HTTPS, hosts, servicio de Windows).

## Arquitectura clave

- **`lua.ps1`** — CLI principal (init/start/stop/restart/reload/status/
  add-site/add-external/switch-php/setup/startup-enable/...). Toda la
  lógica de generación de vhosts, php.ini, etc. vive aquí.
- **`tools/dashboard/`** — el panel web (patrón PRG para POST, sin build
  step ni JS framework — solo PHP + CSS + JS vanilla inline). Troceado en
  2026-07-28 (era un único `index.php` de ~9500 líneas): ahora `index.php`
  (~1265 líneas) es solo el orquestador —
  bootstrap → `require_once` de `lib/` → `include` de `ajax/` → dispatch
  POST (`include` de `actions/`) → render (`include` de `views/`) → layout
  compartido (header, terminal global, footer, scripts) — y el resto vive
  en:
  - **`lib/*.php`** — funciones puras agrupadas por característica (sites,
    mysql, postgres, redis, sqlsrv, sysmon-doctor, procs, terminal,
    docker, git, logs, jobs...), cargadas con `require_once` al principio.
  - **`ajax/*.php`** — endpoints AJAX/GET "crudos" (cover, brandlogo,
    export_db/pg, pickfolder, árbol de archivos/editor, logs, procs,
    redis, sqlsrv, terminal, comandos del runner), uno por característica.
  - **`actions/*.php`** — el dispatch de `$_POST['action']` (patrón PRG),
    uno por característica (power, proyectos, git/ftp, marca+tarjetas,
    php/config, servicios, bases de datos, conexiones sqlsrv/redis,
    procesos, actualizaciones, terminal/docker).
  - **`views/*.php`** — el render de cada pestaña (`$tab`), uno por
    pestaña.

  **Gotcha al añadir ficheros nuevos aquí:** la cadena original era un
  único `if/elseif/.../elseif` (para `$action` o para `$tab`); al partirla
  en varios ficheros cada uno pasó a ser un `if` independiente (válido
  porque `$action`/`$tab` nunca se reasignan y las condiciones son
  mutuamente excluyentes). Dos trampas reales que esto destapó, a vigilar
  si se vuelve a tocar esta zona:
  1. **`__DIR__` cambia de valor** al mover código a una subcarpeta (en
     `lib/`/`ajax/`/`actions/`/`views/` ya NO es `tools/dashboard/`) — usar
     siempre `$ROOT` (ya en scope, puesto por `index.php`), nunca
     `__DIR__`/`dirname(__DIR__,N)`, para rutas del proyecto.
  2. **Una guarda de seguridad o código compartido puede colgar de un
     `elseif` que "pertenece" a otro fichero.** Antes de cortar un bloque,
     comprobar que ninguna condición intermedia (algo que no sea
     `$action === 'x'`/`$tab === 'x'` puro, p.ej. un `in_array(...) && ...`)
     esté hecha para bloquear/preceder una acción cuyo manejador real cae
     en OTRO fichero — hay que mover la guarda junto a su manejador. Y
     comprobar que no haya código compartido (sin condición propia) colgando
     al final de la cadena original antes de su `}`/`endif` de cierre: ese
     código es de TODA la cadena, no del último `elseif`, y debe quedarse en
     `index.php`.
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

### El caso grave: con "Arrancar con Windows" activo, `stop`/`start` NO bastan

**Corregido el 2026-07-28**: lo de arriba es verdad, pero la receta
(`stop` + `start`) **falla en silencio** si "Arrancar con Windows" está
activo. En ese caso el watcher es una **tarea programada de `SYSTEM`**
(`lua-server-watcher`), y entonces:

- Una consola **no elevada no puede matarlo** (acceso denegado) — y
  `Cmd-Stop` se lo tragaba con `-ErrorAction SilentlyContinue`, así que
  `lua.ps1 stop` decía "Apache detenido" como si todo hubiera ido bien.
- `lua.ps1 start` arranca **además** otro watcher como el usuario, así que
  quedan **dos** compitiendo.
- `Process-Jobs` **borra el `.job` en cuanto lo lee**, así que se lo queda
  el primero que pase por el bucle. Si gana el de `SYSTEM` (con código de
  hace días), la feature nueva falla con **`Tipo desconocido: <tipo>`**
  aunque el código en disco esté perfecto y aunque acabes de reiniciar.
- Y es **invisible**: `Get-CimInstance Win32_Process` no puede leer el
  `CommandLine` de un proceso de `SYSTEM`, así que filtrar por
  `*lua.ps1*watch*` **no lo encuentra**. Parece que no hay ningún watcher
  y aun así los jobs se procesan.

Así se diagnosticó (y es la forma rápida de confirmarlo): dejar un `.job`
a mano en `tmp\jobs\` sin ningún watcher visible. Si el `.job` desaparece
y aparece su `.status`, hay un watcher fantasma. Para confirmar que es la
tarea de `SYSTEM`, **`schtasks /query /tn "lua-server-watcher"`** →
`Acceso denegado` (existe pero no se puede leer). Ojo: `Get-ScheduledTask`
para ese mismo caso dice **"no se encontraron objetos"**, que se
confunde con "no existe" (ver el gotcha de PowerShell más abajo).

**Arreglo implementado — el watcher ahora se autorecarga.** `Cmd-Watch`
guarda el `LastWriteTimeUtc` de `lua.ps1` al arrancar y, al principio de
cada vuelta del bucle, lo compara con el actual: si cambió, se relanza y
sale. Cualquier watcher se pone al día solo, incluido el de `SYSTEM` (se
relanza a sí mismo con sus mismos privilegios). Se compara contra la
fecha de **arranque**, no contra la vuelta anterior, para que el relevo
nazca al día y no haya bucle de reinicios. Además `Cmd-Stop` ahora
**avisa** si no ha podido matar al watcher en vez de callárselo.

Consecuencia práctica: editar `lua.ps1` ya surte efecto solo, en ~1s, sin
reiniciar nada. La única excepción es un watcher que venga de **antes** de
este cambio (su código no sabe autorecargarse): ese hay que matarlo una
vez desde una consola **elevada**.

### El badge "Watcher inactivo" mentía: ahora va por latido, no por PID

Mismo origen. `watcher_alive()` (en `index.php`) miraba `tmp\watch.pid` y
comprobaba ese PID con `tasklist`. Falla en los dos casos de arriba:

- El watcher de `SYSTEM` **no es consultable** desde el panel → parecía
  muerto estando vivo y procesando jobs.
- `watch.pid` guarda solo el del **último** watcher que arrancó. Con dos
  vivos deja de reflejar la realidad: si moría el último, el badge decía
  "inactivo" mientras el otro seguía trabajando.

Arreglado con un **latido**: `Cmd-Watch` escribe `tmp\watch.beat` (epoch en
segundos) en cada vuelta del bucle, y `watcher_alive()` da por vivo al
watcher si ese archivo tiene menos de 15s (margen para vueltas lentas que
estén aplicando cambios o reiniciando Apache). Lo escribe **quien de verdad
está ejecutando el bucle**, que es justo lo que se quiere saber, sin
depender de la cuenta ni de cuántos haya. `Cmd-Stop` borra el latido para
que el badge se apague al instante. Se conserva la comprobación del PID
como respaldo, solo para watchers anteriores al latido.

### Y la detección de motores "por ruta del binario" mentía por lo mismo

Corregido el 2026-08-06. Tercera víctima del mismo origen, ahora en
`Get-LuaProcess` (`lua.ps1`), que identificaba nuestras instancias filtrando
por `$_.Path`:

```powershell
$exe = $null; try { $exe = $_.Path } catch {}
$exe -and $exe.StartsWith($dirN, ...)
```

**`$_.Path` vale `$null` para un proceso de otra cuenta** — y ni siquiera
lanza, así que el `try{}catch{}` no se entera: la `ScriptProperty` de
PowerShell se traga el acceso denegado y devuelve nada. Vienen igual de
vacíos `MainModule.FileName` y el `ExecutablePath`/`CommandLine` de
`Win32_Process` (con `GetOwner` devolviendo `ReturnValue=2`). Con "Arrancar
con Windows" los motores los lanza el watcher como SYSTEM, o sea que son
exactamente ese caso, y el comentario original ("quedan excluidos, que es
justo lo deseado") era **falso**: se estaba excluyendo un proceso propio.

Lo confuso es que **depende de quién pregunta**: el watcher de SYSTEM sí lee
la ruta del mysqld que él mismo lanzó, pero la consola del usuario no. De ahí
el síntoma imposible de `.\lua.ps1 status` diciendo "MySQL (MariaDB):
apagado" con la BD sirviendo consultas. Y cuando el que no puede leerla es el
watcher, es peor: relanza otro `mysqld` cada 30s contra el mismo datadir, que
aborta solo con `InnoDB: The data file './ibdata1' must be writable`
(6422 de esos errores, a 30s exactos, entre el 2026-07-27 y el 2026-07-30).
Además dejaba `Stop-MariaDb` en un no-op silencioso y hacía que
`New-ProjectDb` se saltara la creación de la BD del proyecto.

**Arreglo:** `MariaDb-Up` pasa por `Get-MariaDbProcess`, que prueba la ruta y,
si no, **el `.pid` que mysqld escribe dentro de nuestro datadir** (por defecto
`<datadir>\<hostname>.pid`, no hay que configurar nada). Quien escribió ese
archivo está usando NUESTRO datadir, así que es nuestro por definición, y
leerlo no necesita permisos sobre el proceso — mismo criterio que
`Postgres-Up`/`MongoDb-Up`/`Redis-Up`. Se recorren todos los `*.pid` (el
nombre depende del hostname) validando que el PID siga vivo y siga siendo un
`mysqld`.

Dos cosas que hay que respetar si se toca esto:

- **El puerto NO se consulta en `*-Up`.** `Get-NetTCPConnection` cuesta
  **~1-4s** en esta máquina (medido) y el watcher llama a `MariaDb-Up` hasta
  3 veces por vuelta, cada segundo. Esa comprobación vive en `Start-MariaDb`,
  detrás del backoff de 30s: si algo ya escucha, no lanza un mysqld condenado.
- **Solo cuenta el listener de `127.0.0.1`.** En el 3306 de esta máquina hay
  tres a la vez (`::` de Docker, `::1` de wslrelay, `127.0.0.1` del mysqld
  nativo) y para `127.0.0.1` gana el bind más específico, así que los de
  Docker no ocupan el puerto ni deben colar como falso positivo.

**Pendiente, mismo bug:** `Mailpit-Up`/`Stop-Mailpit` siguen usando
`Get-LuaProcess` a secas y el mailpit de esta máquina ya tiene la ruta
ilegible. Mailpit no escribe pidfile, así que su arreglo no es simétrico
(haría falta un `.pid` propio al lanzarlo, como mongo-express).

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
- **`mcp__claude-in-chrome__*` tampoco es fiable para esto** (corregido en
  la sesión del 2026-07-27; antes esta nota decía justo lo contrario). Aun
  reportando `isLocal: true`, su salida de red puede ir por un proxy que no
  llega a esta máquina: `http://127.0.0.1/` devolvió el `timeout.html` de
  **Portainer** (el mismo artefacto que el Bash tool) y
  `https://portal.ersm.test/` un **502 de `nginx/1.30.4`** — cuando el
  Apache real se anuncia como `Apache/2.4.68 (Win64) mod_fcgid/...` y
  respondía 200 a la vez desde PowerShell.
- **Cómo distinguirlo en 2 segundos:** mirar la cabecera `Server` de la
  respuesta. Si no pone `Apache/…​ mod_fcgid/…`, no estás hablando con este
  servidor, da igual lo que parezca la URL.
- Conclusión práctica: para medir lo que ve el navegador real **hay que
  pedírselo al usuario** (p. ej. un snippet para su consola de DevTools).
  Desde aquí, lo máximo fiable es `curl.exe`/`Invoke-WebRequest` vía la
  herramienta **PowerShell** — que sí corre en el host real, pero no es un
  navegador y por tanto no reproduce lo que hace Chrome.

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

## ⚠️ Trampa nº6: `pdo_odbc` destroza el texto en AMBOS sentidos

La pestaña **SQL Server** habla con el servidor por `pdo_odbc` (+ *ODBC Driver 17 for SQL
Server*), porque es lo único que viene ya con PHP en Windows — solo hay que activar
`pdo_odbc` en `config\php\extra-extensions.json`. Pero ese driver **convierte el texto al
codepage ANSI del sistema (Windows-1252 aquí), al leer y al escribir**:

- Al **leer**: un `NVARCHAR` con `中` llega a PHP como `?`. El carácter se pierde *antes* de
  que PHP lo vea, así que no hay forma de recuperarlo después. Medido: `ñ`→`0xF1`,
  `€`→`0x80`, `中`→`0x3F`.
- Al **escribir**: mandar `'ñ'` como parámetro hace que SQL Server reciba **dos** caracteres
  (los bytes UTF-8 sueltos, códigos 195 y 177).

En un editor de filas eso es **corrupción silenciosa**: lees `?`, guardas, y el dato original
queda destruido. Solución implementada (sin depender de otro driver): **mover el texto como
binario**, que viaja en hexadecimal ASCII puro e inmune a cualquier codepage.

- Leer: `CONVERT(varbinary(max), CONVERT(nvarchar(max), [col])) AS [col]` → en PHP
  `hex2bin` + `mb_convert_encoding(..., 'UTF-8', 'UTF-16LE')`.
- Escribir: se manda el hex y `CONVERT(nvarchar(max), CONVERT(varbinary(max), CAST(? AS varchar(max)), 2))`.
  **El `CAST(? AS varchar(max))` es imprescindible**: el parámetro llega como `nvarchar` y
  `CONVERT(...,2)` NO interpreta hexadecimal sobre `nvarchar` (se limita a reinterpretar sus
  bytes, y acabas guardando el propio texto del hex).

Verificado sin pérdidas con acentos, `€`, chino, emoji (pares suplentes), saltos de línea,
comillas y textos de 40.000 caracteres. Todo esto se desactiva solo si algún día se instala
`pdo_sqlsrv` (ver `sqlsrv_hex_text()`), que maneja UTF-8 de forma nativa.

Otros detalles del mismo driver, ya resueltos, que conviene no volver a descubrir:

- **`lastInsertId()` lanza excepción** (no soportado). Para recuperar la clave nueva se usa
  `INSERT ... OUTPUT INSERTED.<pk>`; `SCOPE_IDENTITY()` en una consulta aparte vuelve vacía
  porque es otro ámbito.
- **Las columnas `xml` devuelven `NULL`** aunque tengan valor: hay que pedirlas con `CONVERT`
  explícito. Por eso el explorador nunca hace `SELECT *`, construye la lista de columnas.
  `geography`/`geometry`/`hierarchyid` necesitan `.ToString()`.
- **"La conexión está ocupada con los resultados de otro comando"**: un cursor sin agotar
  bloquea la siguiente consulta. Se resuelve con `MARS_Connection=yes` en el DSN y
  `closeCursor()` tras cada lectura parcial (`fetchColumn()` deja el cursor abierto).
- Ojo al probar codificaciones: pasar un literal `N'ñ'` **dentro del SQL** no demuestra nada
  (te devuelve los bytes que tú mismo enviaste). Hay que leer datos realmente almacenados, o
  generarlos en el servidor con `NCHAR(...)`.
- Y un despiste de T-SQL puro: `REPLICATE(N'x', 5000)` devuelve **4.000** caracteres, no
  5.000, salvo que el primer argumento ya sea `MAX` (`REPLICATE(CAST(N'x' AS nvarchar(max)), 5000)`).

## ⚠️ Trampa nº7: "la pagina llega cortada" que NO era el servidor (`</script>` en los datos)

Investigado a fondo el 2026-07-27. Se perdieron horas persiguiendo un truncado de
respuesta en Apache/mod_fcgid que **nunca existio**: el fallo estaba en la app
(`ersmportal`), no en este servidor. Queda escrito con el metodo que lo resolvio.

**Sintoma:** la vista mas grande de esa app
(`/seguimiento/cuadro-mando-movilidad`, ~130 KB de HTML+JS inline, PHP 7.1) no
arrancaba su JS. En consola: `Uncaught SyntaxError: Unexpected end of input`. El
`<script>` inline estaba, efectivamente, **sin terminar**.

**La paradoja que lo resuelve todo:** la respuesta HTTP llegaba **completa**
(cerraba `</html>`, DOM 132784 ≈ FETCH 132744 bytes, los 152 backticks del fuente
presentes en el documento) y **aun asi** el `<script>` estaba incompleto. Las dos
cosas a la vez solo pasan si el **parser de HTML cierra el elemento `<script>`
antes de tiempo**.

La contabilidad de backticks lo demuestra sin lugar a dudas:

| | dentro del `<script>` | fuera |
|---|---|---|
| Fuente `.blade.php` (L420–L1960) | **152** | 0 |
| Renderizado en el navegador | **137** | **15** |

15 backticks que en el fuente estan dentro del script aparecen **fuera** en el DOM:
el parser corto el elemento a mitad y el resto del JS se derramo al documento como
texto HTML. Lo que quedo dentro del script tenia una plantilla sin cerrar → el
`SyntaxError`.

**Causa (confirmada):** el paquete **`genealabs/laravel-caffeine`** (keepalive de
sesion) inyecta su `<script>` haciendo `str_replace('</body>', ...)` sobre el HTML
final — y `str_replace` sustituye **todas** las ocurrencias, no la ultima. Esa vista
construye un informe HTML descargable dentro de una template literal de JS
(`const html = ` + backtick + `...</body></html>` + backtick + `;` → `new Blob([html])`),
asi que en la salida hay **dos** `</body>`: el del informe (dentro del JS, L1766) y el
real del documento (L1961). Caffeine inyecto en los dos, y el `</script>` que metio en
el de dentro cerro el `<script>` real abierto en la L420.

**Arreglo (en la app, no aqui):** escapar la barra dentro de la template literal,
`</body>` → `<\/body>`. En JS es exactamente la misma cadena (`\/` es un no-op), o sea
que el informe descargado no cambia ni un byte, pero el `str_replace` de PHP ya no
casa. Alternativas: excluir la ruta del middleware de caffeine, o quitar el paquete.
Escaneadas las 3500 vistas blade del proyecto, **solo esa** tiene el patron.

**Como diagnosticar esto en 30 segundos** (consola del navegador, sobre la pagina real):

```js
// 1. ¿El script esta completo? Compila sin ejecutar:
new Function(document.querySelectorAll('script:not([src])')[0].textContent)

// 2. ¿Y la RESPUESTA esta completa? Compara documento contra fetch de la misma URL.
//    Si la respuesta cierra </html> pero el script no compila -> NO es el servidor,
//    es el parser cerrando el <script>. Busca el `</script` intruso:
(async () => {
  const t = await (await fetch(location.href, {credentials:'include'})).text();
  const h = []; let i = -1;
  while ((i = t.indexOf('</script', i + 1)) !== -1) h.push(JSON.stringify(t.slice(i-150, i+30)));
  return h;   // cualquiera que no sea un cierre legitimo es el culpable
})()
```

**La leccion de metodo, que es lo que de verdad costo caro:** "respuesta completa +
script incompleto" apunta **siempre** al parser de HTML, nunca a la red ni al
servidor. Comprobar esas dos cosas por separado **antes** de tocar nada de Apache
habria resuelto esto en minutos.

**Callejones sin salida recorridos, para no repetirlos:**

- **La paridad de backticks no demuestra nada por si sola.** La pista inicial fue
  "el fuente tiene 152 (par) y lo servido 137 (impar), luego viene cortado". Pero se
  comparaba **un solo `<script>`** contra el **fichero entero** — peras con manzanas.
  Y un backtick suelto en una cadena o un comentario descuadra la paridad sin que
  falte un byte. Aqui resulto haber un problema real, pero **por otro motivo**: la
  cifra correcta a comparar era dentro-del-script vs fuera-del-script (137+15).
- **`FcgidOutputBufferSize`**: `httpd-lua.conf` no lo fijaba y corria con el default
  de mod_fcgid (**64 KB**). Se subio a 16 MB. **No arreglo este bug** (no habia bug
  de servidor), se deja como endurecimiento preventivo de coste/riesgo cero: 64 KB
  es bajo para paginas grandes y hay reportes reales de mod_fcgid en Windows con
  renders a medias.
- **opcache**: sigue correctamente desactivado en PHP 7.1 (ver seccion PHP < 7.2).
  No era una reaparicion de aquel bug.
- **Reproduccion sintetica**: sitio de prueba aislado bajo PHP 7.1 (mismo Apache,
  mismo mod_fcgid) con acentos UTF-8, backticks, cientos de `echo`, `flush()`,
  `session_start()`, HTTP y HTTPS, de 50 KB a ~7 MB — **50+ intentos, ni un corte**.
  Nunca iba a reproducirse: al test le faltaba un `</script>` dentro de una plantilla.
  Cuando un sintoma no se reproduce ni forzandolo, sospechar del **modelo mental**,
  no insistir con mas tamano.
- **ESET Security**: se confirmo que **si** hace MITM de todo el HTTPS local (por TLS
  en crudo a `127.0.0.1:443` con SNI `portal.ersm.test`, el certificado llega
  re-firmado por `CN=ESET SSL Filter CA`, no por la CA de `mkcert`). Pero era
  **inocente**, y ademas mala hipotesis de entrada: el usuario llevaba anos con ESET
  y Vagrant/VirtualBox sin este problema, o sea que ESET es una **constante** entre
  el escenario que funcionaba y el que fallaba. En diagnostico diferencial se
  persigue la variable que cambio. (Y aqui **no habia ninguna variable de entorno**:
  la combinacion vista+caffeine rompe en cualquier servidor, Windows o Linux. Por eso
  ninguna hipotesis de infraestructura — buffer, TLS, antivirus, version de PHP —
  podia explicarlo jamas. Cuando el sintoma es identico en todos los entornos, deja
  de mirar el entorno.)

Nota de medicion: incluir **siempre** `location.href` y `document.title` en cualquier
snippet de diagnostico. En esta investigacion se midio sin querer **la pagina de
login** (la sesion se habia perdido al reiniciar Apache) y sus numeros se dieron por
buenos durante una ronda entera.

## ⚠️ Trampa nº8: git y "dubious ownership" cuando el watcher corre como SYSTEM

Con **"Arrancar con Windows"** activo, el watcher corre como tarea programada
`SYSTEM`, cuenta distinta de quien clono el repo. Git desde 2.35 se niega a operar
sobre un repo de otro dueño (protección `safe.directory`), así que
`Update-Check`/`Update-Apply` (pestaña Configuración → Actualizaciones) fallaban en
cualquier máquina de un compañero con este mensaje:

```
fatal: detected dubious ownership in repository at 'C:/server/LuaServer'
'C:/server/LuaServer' is owned by: EGonzalez/Eduard Gonzalez (S-1-5-21-...)
but the current user is: NT AUTHORITY/SYSTEM (S-1-5-18)
```

La solución que sugiere el propio git (`git config --global --add safe.directory ...`)
no vale aquí: habría que ejecutarla **con el perfil de SYSTEM**, a mano, en cada
máquina — nada portable. Arreglado pasando la excepción **por argumento** en cada
invocación de git sobre `$Root`, sin tocar ninguna config global: `$GitSafeDir`
(`lua.ps1`, junto a las demás rutas) vale `"safe.directory=" + ($Root -replace
'\\','/')` (mismo formato que exige git, barras hacia adelante), y todas las
llamadas usan `& git -c $GitSafeDir -C "$Root" ...` en vez de `& git -C "$Root" ...`.
Así funciona sin setup extra tanto si `lua.ps1` lo lanza el usuario interactivo como
si lo lanza el watcher como SYSTEM.

## ⚠️ Trampa nº9: un comodín `*.<tld>` NO vale como certificado (HTTPS nunca funcionó)

Corregido el 2026-08-06. `Cmd-HttpsSetup` emitía **un solo certificado comodín**:

```powershell
& $Mkcert -cert-file ... "*.$tld" "$tld" "localhost" "127.0.0.1" "::1"
```

Eso no cubría **ningún** proyecto. Dos reglas del validador, y la primera es la que
hacía daño de verdad:

1. **Un comodín exige al menos DOS etiquetas a su derecha.** `*.local` y `*.test` son
   comodines sobre un TLD entero, y Schannel/CryptoAPI (el validador de Windows, que usan
   el navegador, `curl` y .NET) **los rechaza de plano**. O sea que fallaba hasta
   `alergenator.local`, el caso "fácil" para el que estaba pensado el comodín.
2. **Un comodín casa UNA sola etiqueta.** Aun sin la regla anterior, `*.local` no cubre
   `app.ersm.local` ni `ueb_buceo.lua.local`, y no cubre nada de otro TLD como
   `portal.ersm.test`. Tampoco las variantes `www.<dominio>` que el vhost :80 y el `hosts`
   sí declaran.

Medido con un servidor TLS local (sin Apache ni antivirus de por medio, para aislar): con
el certificado viejo **los 7 proyectos daban `RemoteCertificateNameMismatch`** y solo
validaba `localhost` (que estaba como nombre explícito). Con los nombres explícitos, los 7
validan. El panel decía "candado verde" desde el primer día y nunca lo hubo.

**Arreglo:** `Get-SslNames` construye la lista a partir de los dominios **reales** de
`sites.json` (cada `Get-SiteDomain` + su `www.`) más los del panel, y `Update-SslCert`
reemite el certificado si esa lista ya no casa con `data\ssl\names.txt`. Se llama desde
`Cmd-Apply`, así que un proyecto nuevo o un dominio editado entran solos — antes el
certificado se emitía **solo** al activar HTTPS y cualquier proyecto posterior se quedaba
fuera para siempre. Emitir una hoja **no necesita admin** (solo lo necesita
`mkcert -install`, que toca el almacén de confianza), por eso puede hacerlo el watcher sin
UAC. Se emite a `.new` y se mueve encima solo si mkcert va bien, para que un fallo no deje
al servidor sin certificado.

Dos cosas que hay que respetar si se vuelve a tocar esto:

- **`CAROOT` hay que fijarlo a mano.** mkcert guarda su CA en `%LOCALAPPDATA%\mkcert`, que
  es **una ruta distinta por cuenta**: el watcher como SYSTEM no ve la del usuario y mkcert
  se inventaría una CA nueva **en silencio** — y esa nadie la ha instalado en el almacén, o
  sea certificados rechazados por CA desconocida (peor que el aviso original). `Use-MkcertCaRoot`
  fija `CAROOT` a `data\ssl\ca` (igual para todas las cuentas) y migra la del usuario la
  primera vez para no volver a pedir UAC. Y `Update-SslCert` **se niega a emitir** si ahí no
  hay CA, en vez de dejar que mkcert cree una.
- **Al medir, espera a que Apache acabe de reiniciar.** ESET hace MITM del HTTPS local
  (trampa nº7) y re-firma la hoja preservando los SAN; si mides justo durante el reinicio,
  su filtro devuelve un momento la hoja re-firmada **anterior** y salen
  `RemoteCertificateChainErrors`/`NameMismatch` falsos. Un par de segundos después sale
  `None`. Casi se dio por regresión un cambio que estaba bien.

## Gotchas de PowerShell

- **`taskkill` (o cualquier exe) sobre stderr + `$ErrorActionPreference = "Stop"` + `2>&1` =
  excepción terminante** en PS 5.1. `lua.ps1` fija EAP=Stop global, así que un
  `& taskkill /F /PID <muerto> 2>&1 | Out-Null` no "falla en silencio": LANZA. En el bucle del
  watcher, el catch la atrapaba y se saltaba el resto de la vuelta (jobs, actualizaciones...)
  en cada iteración — el supervisor de procesos estuvo matando de hambre al resto del watcher
  por un pid file huérfano. Receta: comprobar antes con `Get-Process -Id X -EA SilentlyContinue`
  que el PID vive, y envolver la llamada con EAP='Continue' + `*> $null`.
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

- **Redis nativo** (`config/redis.on`, `127.0.0.1:6379`, sin contraseña) — mismo
  patrón flag+watcher que los demás motores (`Set-RedisConf`/`Redis-Up`/
  `Start-Redis`/`Stop-Redis`, "up" por el `pidfile` que le fijamos nosotros, con
  backoff de 30s en el watcher como MariaDB/PostgreSQL). Dos cosas propias:
  - **Redis no publica builds oficiales para Windows**, así que el panel deja
    **elegir el port** la primera vez y lo recuerda en `config\redis\build.txt`:
    `redis8` (redis-windows/redis-windows 8.8.1, al día pero sobre una capa
    **msys2** — de ahí que `Start-Redis` lance el proceso con el cwd en
    `bin\redis\`, o no encuentra sus DLLs) o `native5` (tporadowski 5.0.14.1,
    port Win32 nativo pero congelado en 2022).
  - La extensión **`php_redis` se instala por cada versión de PHP**, y ahí las
    tres cosas tienen que casar exactas o la DLL no carga: versión de PHP, **NTS**
    (este servidor usa mod_fcgid + `php-cgi.exe`, no mod_php — se ve en que
    `bin\php\<ver>\` tiene `php8.dll` y NO `php8ts.dll`) y el toolset de VC
    (`vc14` en 7.1, `vc15` en 7.2–7.4, `vs16` en 8.0–8.3, `vs17` en 8.4+).
    Además la rama de phpredis no es libre: la 6.x cubre 7.4 y 8.x, pero 7.1–7.3
    necesitan ramas viejas. Por eso hay un mapa explícito (`$PhpRedisBuilds` +
    `Get-PhpRedisUrl`) en vez de construir la URL al vuelo. Va en **`$WantExts`**
    (versionado), y `Set-PhpInis` ya la activa solo en las versiones donde el
    `.dll` exista de verdad, con la sintaxis vieja en 7.1.
  - De paso, PECL **distribuye siempre en `.zip`** (con el `.dll` junto a docs y
    su `.pdb`), no en `.dll` suelto. El job `phpext` solo sabía de `.dll`, así que
    no podía instalar **ninguna** extensión oficial de PECL: ahora hay una función
    `Install-PhpExt` que acepta las dos formas y la usan tanto `phpext` como el
    motor de Redis.

- **Supervisor de procesos por proyecto** (pestaña **Procesos**) — mantiene vivos comandos
  largos de los proyectos (colas de Laravel, `schedule:work`, Vite/npm, Reverb...) con log
  propio y reinicio automático. Definiciones en `config\procs.json` (**fuera de git**, como
  `sites.json`: referencian proyectos de esta máquina). El panel solo edita ese json y deja
  flags (`tmp\procs\<id>.restart`); quien arranca/vigila/reinicia es **el watcher**, que
  reconcilia el json con la realidad en cada vuelta. Detalles con motivo:
  - El wrapper `.cmd` reutiliza las soluciones de la trampa nº5: `chcp 65001`, HOME propio
    (`tmp\home`) para composer/npm, `bin\php\<ver>` del proyecto al frente del `PATH` **y
    `PHPRC` apuntándole** (verificado: proceso con PHP 7.1 real mientras el panel corre 8.4).
    Se escribe con `Write-Utf8NoBom`: `Set-Content -Encoding UTF8` en PS 5.1 mete BOM y cmd.exe
    falla con `"ï»¿@echo" no se reconoce...`.
  - "Up" por `<id>.pid` con `pid;starttime` (FileTime): la hora de arranque detecta PIDs
    reciclados por Windows. Parar usa `taskkill /F /T` (el árbol entero, verificado que el
    PING.EXE/php.exe hijo muere, no solo el cmd.exe).
  - **Backoff anti crash-loop**: si el proceso muere a <15s de arrancar, se reintenta a los
    5s→10s→20s→...→60s (verificado con `cmd /c exit 1`). Si aguanta >15s, contador a cero.
  - Estado para el panel en `tmp\procs\state.json`, que el watcher **solo escribe si cambió**;
    el panel pinta badges en vivo leyéndolo (AJAX `?ajax=procs&op=state`), sin `tasklist`.
  - Borrar una definición basta: el watcher mata como **huérfano** cualquier pid cuyo id ya no
    esté en `procs.json`.
  - Crear/tocar procesos usa la misma llave de seguridad que la Terminal (`config\terminal.on`):
    definir un proceso ES ejecutar un comando arbitrario, y el panel puede estar expuesto a la
    LAN. Ver estado y logs no se bloquea.

- **Pestaña Redis** — gestor propio, mismo modelo que la de SQL Server: **no gestiona un motor
  propio**, se conecta a un Redis existente con conexiones guardadas en
  `config\redis-servers.json` (fuera de git, contraseña en claro como las demás). Explorador de
  claves con `SCAN` (patrón + paginación por cursor), visor/editor por tipo (string, hash, list,
  set, zset), TTL, renombrar, borrar, vaciar base, consola de comandos con historial, y panel de
  estado con `INFO`. Decisiones que conviene no volver a discutir:
  - **Se habla RESP a pelo por `fsockopen`, sin `php_redis`** (`redis_connect`/`redis_cmd`/
    `redis_read`). Si dependiera de la extensión, el gestor no funcionaría hasta tenerla
    instalada justo en la versión de PHP que sirve el panel — y en Windows eso implica que casen
    versión, NTS y toolset de VC. RESP son 5 tipos de respuesta: sale más barato implementarlo.
    Verificado que el round-trip de acentos, `€`, chino, emoji, saltos de línea y valores de
    200 KB sale **idéntico byte a byte** (RESP lleva la longitud por delante, así que no hay
    nada del infierno de codificaciones de la trampa nº6).
  - **`SCAN`, nunca `KEYS`**: `KEYS` recorre todo el keyspace de golpe y bloquea el servidor, que
    aquí suele ser uno compartido con las apps del usuario. El cursor lo lleva el cliente.
  - **`RENAMENX` en vez de `RENAME`**: `RENAME` pisa el destino sin avisar.
  - **TTL vacío → `PERSIST`, no `EXPIRE 0`**: `EXPIRE` con 0 o negativo **borra** la clave, que no
    es lo que espera nadie al vaciar ese campo.
  - Los errores del servidor (`-ERR …`) se devuelven como objeto `RedisErr` en vez de lanzar: en
    la consola un error es un resultado que hay que mostrar, no una excepción. Y se distingue el
    `nil` de Redis de la cadena vacía (`{"__nil":true}`), porque no son lo mismo.
  - `SHUTDOWN` está bloqueado (apagaría el servidor sin forma de rearrancarlo desde el panel) y
    también `SUBSCRIBE`/`MONITOR` y compañía, que dejan la conexión escuchando para siempre.
  - Los strings de más de 256 KB se muestran recortados y **con la edición desactivada**, para no
    guardar encima una versión truncada del original.

- **Terminal global en la cabecera** — icono en la barra superior (junto a
  reiniciar/apagar) que muestra/oculta una terminal persistente **en cualquier pestaña**, no
  solo en la pestaña "Terminal". Reutiliza `render_terminal_widget()` con un prefijo propio
  (`gterm`, sesión independiente de la de la pestaña Terminal si también está abierta — no
  hay colisión de ids porque los prefijos difieren). Dos modos, elegidos por el usuario y
  recordados en `localStorage` (no hace falta servidor: es una preferencia de UI, como el
  dock del runner): **abajo a todo el ancho** (por defecto, alto ajustable a mano) o **a la
  derecha** (calco exacto del patrón ya existente `.modal-box.docked` del runner — incluso
  reutiliza la clase `.lockbtn` y el icono). Solo el modo "abajo" reserva espacio en
  `.content` (via `ResizeObserver`, se actualiza solo al redimensionar); el modo "derecha"
  se superpone al contenido sin desplazarlo, igual que ya hacía el runner. Se abre sola (sin
  robar el foco) al recargar la página si el usuario la dejó abierta la última vez, porque
  cada pestaña del panel es una recarga completa, no una SPA. El icono de la cabecera solo
  se pinta si `config/terminal.on` existe; si no, es un enlace mudo a Configuración.

- **Pestaña Recursos** — CPU/RAM/disco/red del sistema real (no un contenedor) con gauges y
  sparklines, más la huella de memoria de los propios motores/watcher de lua-server. Todo por
  **WMI vía COM** (`winmgmts://./root/cimv2`), nunca `shell_exec()` de PowerShell (trampa nº5).
  Dos decisiones no obvias:
  - **El contador "Formatted" de CPU tarda ~6s; el "Raw" del mismo dato, ~50ms.**
    `Win32_PerfFormattedData_PerfOS_Processor` calcula el % él mismo pero es lentísimo en esta
    máquina (probablemente su proveedor WMI recalcula sobre su propia ventana de muestreo).
    `Win32_PerfRawData_PerfOS_Processor` da los contadores crudos al instante; el % se calcula
    con la fórmula oficial `PERF_100NSEC_TIMER_INV`: `(1 - (P2-P1)/(T2-T1)) * 100` entre dos
    lecturas de `PercentProcessorTime`/`Timestamp_Sys100NS`. Para no bloquear la petición con
    un `usleep`, la lectura "anterior" es la de la petición AJAX de antes (`tmp/sysmon-prev.json`,
    caduca a los 30s): con el cliente sondeando cada ~2,5s sale gratis y preciso. **Verificado
    contra una lectura de la misma clase WMI hecha por PowerShell/CIM en paralelo (sin pasar
    por PHP): valores coincidentes.** Una "comprobación" ingenua con `Get-Process | Measure
    TotalProcessorTime` como referencia dio sistemáticamente ~2× menos — es la propia
    referencia la que está mal: `Get-Process` no expone el tiempo de CPU de `System`/`Idle`
    (interrupciones, DPCs), así que infravalora el total. El contador de perfmon (lo que usa
    también el Administrador de tareas) es la fuente correcta.
  - **La huella de "lua-server"** es una única consulta a `Win32_Process` que combina los
    binarios conocidos (`httpd.exe`, `php-cgi.exe`, `mysqld.exe`, `postgres.exe`, `mongod.exe`,
    `redis-server.exe`, `node.exe`) **y** `powershell.exe`, filtrando el watcher después por
    `CommandLine` (contiene `lua.ps1` y `watch`). Iba a ser una consulta aparte para el
    watcher, pero fusionarla en la misma query casi duplicó la velocidad (~1,9 s → ~0,75 s):
    lo caro de `Win32_Process` con filtro es recorrer la tabla de procesos, no el filtro en sí.
  - Paleta categórica de 8 tonos (`--cat-1`…`--cat-8`) tomada tal cual del set ya validado
    como seguro para daltonismo de la skill de dataviz, en vez de elegir colores a ojo para un
    gráfico con varias series a la vez.

- **Pestaña Doctor** — diagnóstico automático que convierte las trampas de este documento en
  comprobaciones de un vistazo: puertos (80/443/motores) con **quién** los ocupa vía
  netstat+tasklist, watcher por latido, motores activados-pero-no-instalados o con el puerto
  robado, config generada apuntando a otra carpeta (repo movido sin `init`), vhosts
  descuadrados con `sites.json`, dominios sin sincronizar al `hosts`, versiones de PHP
  incompletas, disco y logs gigantes. Lección clave de su primera pasada: **en Windows un
  mismo puerto puede tener varios listeners IPv4 a la vez** (el `0.0.0.0` de un contenedor de
  Docker convive con el `127.0.0.1` de Apache/motor nativo, y para `127.0.0.1` gana el bind
  más específico) — cualquier comprobación de puertos debe evaluar TODOS los listeners, no el
  primero que devuelva netstat, o acusa a Apache de no tener el 80 mientras sirve la propia
  página. En esta máquina el 3306 y el 27017 están compartidos así (nativo + contenedor).
  Su primera pasada real también destapó un **mongod huérfano**: un `stop` borró `mongod.pid`
  sin lograr matar el proceso (era de SYSTEM, lanzado por el watcher fantasma), y el watcher
  se pasó horas engendrando mongods condenados mientras mongo-express no arrancaba nunca.
  Arreglado triple: `Stop-MongoDb` ya solo borra el pid si el proceso murió de verdad,
  `Start-MongoDb` **adopta** al huérfano si algo llamado `mongod` escucha nuestro puerto
  (identificado por el listener, NO por ruta del binario: la ruta de un proceso de SYSTEM es
  ilegible desde una sesión normal), y la reconciliación de MongoDB tiene por fin el backoff
  de 30s que ya tenían los demás motores. SQL Server y Redis ya no tienen pestaña propia en la
  barra: se entra por sus enlaces en "Bases de datos" (que queda resaltada dentro de ellos).

- **Pestaña SQL Server** — gestor propio tipo phpMyAdmin para **Microsoft SQL Server**
  (que NO se instala con la plataforma: se conecta a uno existente, local o de red). Barra
  lateral BD→tablas, explorador de filas paginado y ordenable, vista de estructura
  (columnas/índices), consola SQL con CodeMirror, y edición de filas (insertar/editar/borrar).
  Las conexiones se guardan en `config\sqlsrv-servers.json` (fuera de git, contraseña en
  claro igual que `mysql_root.pass`). **La edición se desactiva sola** en vistas y en tablas
  sin clave primaria: sin PK no hay `WHERE` que identifique una sola fila. Ver la trampa nº6
  sobre codificación: es lo más delicado de toda la pestaña.
- **Exportar proyecto** (ficha de proyecto) — genera un `.zip` en `data\exports\` con la
  carpeta del proyecto y, opcionalmente, el volcado `.sql` de una base de datos (MySQL o
  PostgreSQL) dentro del mismo archivo. Lo hace el **watcher** (job `export_project`), no el
  panel: comprimir miles de archivos y volcar la BD se sale del tiempo de una petición bajo
  mod_fcgid, y lanzar `mariadb-dump.exe` desde PHP es justo lo que desaconseja la trampa nº5.
  Detalles que costaron un intento: `Add-Type -AssemblyName System.IO.Compression.FileSystem`
  trae `ZipFile`/`ZipFileExtensions` pero **no** `ZipArchiveMode` (ese está en
  `System.IO.Compression` a secas, hay que cargar las dos); y el dump se pide con
  `--result-file`, nunca con `> archivo`, porque la redirección de PowerShell escribe el `.sql`
  en UTF-16 y además cuela dentro las líneas de stderr. Cada archivo se añade al zip dentro de
  su propio `try` (en Windows es normal toparse con rutas de más de 260 caracteres o archivos
  abiertos por otro proceso: se anotan como `OMITIDO` en el log y el export sigue). Los
  exclusos son por subcadena de la ruta relativa, igual que en "Desplegar por FTP".

- **Actualización de la plataforma** — la versión sale junto al título del panel (etiqueta de
  git si existe, si no `r<nº commits> <sha>`), y se vuelve ámbar cuando hay novedades. El
  **watcher** hace `git fetch` cada X horas (`config\update.json`, fuera de git) y deja el
  resultado en `tmp\update-status.json`; el panel solo lee ese archivo y pide acciones por
  flag (`update-check.flag`, `update-now.flag`). **El panel no puede hacer el fetch él mismo**:
  el remoto es SSH y Apache corre como SYSTEM, sin las claves del usuario.
  La actualización usa `git merge --ff-only` y se niega a correr si hay cambios locales sin
  confirmar o commits propios por delante. Tras actualizar ejecuta `Cmd-Apply` y, si el
  propio `lua.ps1` ha cambiado, **relanza el watcher** (trampa nº1).
  Ojo al añadir features que necesiten una extensión de PHP: va en `$WantExts` (versionado),
  nunca en `config\php\extra-extensions.json` (ignorado por git), o los demás equipos se
  quedan sin ella al actualizar.

## Convenciones de UI ya establecidas

- Banners de feedback con tipos `applied`/`info`/`job`/`error`, con
  auto-recarga por JS (`applied` a los 4.2s, `info` a los 7s).
- Modal de confirmación propio (overlay + box, mostrar/ocultar por JS,
  cierre con Escape) en vez de `confirm()` nativo — usado para borrar
  proyecto, apagar y reiniciar.
- Nunca emojis en la UI — iconos SVG inline stroke-based, minimalistas.
