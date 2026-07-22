# lua-server

Servidor web local **portable** para la agencia LUA que **solo ejecuta PHP**.
Estilo XAMPP/Laragon pero a medida, con **varias versiones de PHP a la vez** y cada
proyecto usando la que necesite. Sin Docker, sin VMs: PHP nativo leyendo directo de
disco (mucho más rápido que las carpetas compartidas de Docker en Windows).

> La base de datos no tiene por qué vivir en este stack: si un proyecto usa MySQL
> en Docker, conéctalo desde PHP a `127.0.0.1:3306` (tu contenedor). Pero si
> prefieres no depender de Docker, el panel también puede levantarte un **MySQL
> nativo (MariaDB)** en dos clics — ver más abajo.

## Qué incluye

- **Apache 2.4** + **mod_fcgid** (enruta cada proyecto a su versión de PHP)
- **PHP 7.1, 7.4, 8.1, 8.2, 8.3, 8.4, 8.5** (NTS x64) conviviendo
- **Panel web** en `http://localhost` (o `http://lua.test`) para gestionar todo con el ratón
- **phpMyAdmin** con un tema propio de marca, y **MariaDB nativo + Adminer** como alternativa
- **Terminal web** para ejecutar comandos (composer, git, npm, artisan…) desde el navegador
- Script de control `lua.ps1` para quien prefiera la terminal

## Panel web (http://localhost o http://lua.test)

Con el servidor arrancado, abre **http://localhost** (solo accesible desde esta
máquina). Si `localhost` te carga otra cosa (Docker Desktop/Portainer suelen
ocupar el mismo puerto por IPv6), usa **`http://lua.test`** — no es un nombre
reservado y siempre resuelve al servidor correcto. El panel tiene 5 pestañas:

### Proyectos
- Crear proyectos (en blanco, **Laravel**, **WordPress**, **Symfony**, **Slim**
  o **clonando un repo de Git**), eliminar y cambiar la versión de PHP de cada uno.
  Las creaciones pesadas se hacen en segundo plano con progreso visible.
- **Registrar proyecto existente en otra carpeta del disco**: sirve cualquier
  carpeta fuera de `www\` (p.ej. `C:\proyectos\miapp`) con su propio dominio,
  detectando `public\` automáticamente (Laravel/Symfony). Ver también
  `lua.ps1 add-external` más abajo.
- **Sin registrar**: si hay carpetas en `www\` que no aparecen como proyecto
  (copiadas a mano, restauradas de un backup…), el panel las detecta y ofrece
  un botón **Adoptar** por carpeta (eliges la versión de PHP, no toca el
  contenido existente).
- **Bloquear proyecto**: cualquier archivo `*.lua` en la raíz de un proyecto
  impide eliminarlo desde el panel (protección aplicada en servidor, no solo
  visual) — icono de candado en cada tarjeta.
- **Carátula**: sube una imagen de portada por proyecto (clic sobre la banda
  superior de la tarjeta).
- Servidor MySQL (MariaDB) y Mailpit tienen sus botones de activar/desactivar
  también accesibles desde aquí.

### Versiones PHP
Editar los valores del `php.ini` de cada versión (memoria, tamaños de subida,
timezone, errores…) más directivas libres, y **activar/desactivar Xdebug** por
versión (descarga la DLL correcta y lo deja configurado para depurar paso a
paso en el puerto **9003**, VS Code/PhpStorm).

### Logs
Ver en vivo los logs de Apache y de cada proyecto desde el navegador, con
auto-refresco y opción de vaciar.

### Terminal
Ejecuta comandos con salida en vivo (colores ANSI, `cd` persistente entre
comandos). **Desactivada por defecto** por seguridad — actívala desde
"Configuración del servidor" solo si confías en quién tiene acceso a esta
máquina, ya que ejecuta con los mismos permisos que Apache.

### Configuración del servidor
- **Dominios `.lua.test`**: sincroniza el archivo `hosts` de Windows (pide UAC).
- **HTTPS local**: certificados de confianza (mkcert) para servir
  `https://<proyecto>.lua.test` con candado verde. Windows pide permiso (UAC)
  para instalar la CA local una sola vez.
- **Mailpit**: bandeja de correo local que captura los emails que envían tus
  proyectos PHP (SMTP `127.0.0.1:1025`); buzón web en `http://localhost:8025`.
- **Servidor MySQL (MariaDB)**: nativo (11.8 LTS) en `127.0.0.1:3306`, usuario
  `root` sin contraseña. Administrable con **Adminer** (`/adminer.php`) o con
  **phpMyAdmin** (proyecto aparte, ver abajo).
- **Terminal**: activar/desactivar (ver arriba).
- **Arrancar con Windows**: instala Apache como servicio de Windows (arranque
  automático) y el watcher como tarea programada (arranca sin necesidad de
  iniciar sesión), para que todo el stack sobreviva a un reinicio del PC sin
  tener que ejecutar `lua.ps1 start` a mano. Pide UAC al activar y al
  desactivar.

Los botones de **apagar** y **reiniciar** el servidor (arriba a la derecha,
junto al logo) hacen exactamente eso: paran o reinician Apache/watcher/Mailpit
con confirmación previa.

Los cambios se aplican solos: un proceso *watcher* (que arranca con `lua.ps1 start`,
o con Windows si activas esa opción) recarga Apache en un par de segundos. Los
ajustes de `php.ini` se guardan como *overrides* en
`config\php\<version>.overrides.ini` y **sobreviven** a las actualizaciones del stack.

> ⚠️ Si editas `lua.ps1` con el watcher ya corriendo, tiene que reiniciarse
> para usar el código nuevo — `.\lua.ps1 stop` y luego `.\lua.ps1 start`
> (el botón "Reiniciar" del panel solo reinicia Apache, no el watcher).

## phpMyAdmin

Se sirve como un proyecto más del panel (con su propio PHP y dominio), con un
tema propio a juego con la marca del panel (mismo mecanismo de colores
claro/oscuro, tipografía Space Grotesk + JetBrains Mono autoalojadas). No
viene en este repo — es contenido de `www\` (no versionado, cada máquina
lo instala/reinstala aparte, incluyendo el tema si se reinstala desde cero).

## Requisitos

- Windows 10/11 x64
- Microsoft Visual C++ Redistributable v14 x64 (`downloads\vc_redist.x64.exe`;
  instálalo si Apache/PHP no arrancan por falta de DLL)

## Uso diario

```powershell
.\lua.ps1 start                    # arranca (sin admin)
.\lua.ps1 status                   # estado y lista de sitios
.\lua.ps1 add-site micliente 8.3   # nuevo proyecto en www\micliente con PHP 8.3
.\lua.ps1 add-external ersmportal "C:\proyectos\ersmportal" portal.ersm.test 7.4
                                    # proyecto externo (fuera de www\), dominio propio
.\lua.ps1 switch-php micliente 8.5 # cambiar su version de PHP al instante
.\lua.ps1 list-php                 # versiones de PHP disponibles
.\lua.ps1 stop
```

Cada proyecto vive en `www\<nombre>\` y se sirve en `http://<nombre>.lua.test`.
Si tiene carpeta `public\` (Laravel/Symfony) se usa como raíz automáticamente.
Los proyectos **externos** (fuera de `www\`) funcionan igual, apuntando el
vhost directamente a su ruta real.

> Para abrir los dominios `.lua.test` en el navegador hay que añadirlos al archivo
> `hosts` (una vez, como admin): lo hace `add-site`/`reload` si ejecutas como admin,
> el botón "Sincronizar dominios" del panel, o `.\lua.ps1 setup`. El panel
> `http://localhost` / `http://lua.test` funciona sin nada.

## Portabilidad

Todo está autocontenido en esta carpeta y las rutas se recalculan solas.

**Opción A — copiar la carpeta** (a otro PC o USB):
```powershell
.\lua.ps1 init      # re-aplica las rutas a la nueva ubicacion
.\lua.ps1 start
```

**Opción B — repositorio git** (recomendado; los binarios NO van en git):
```powershell
git clone <tu-repo> lua-server
cd lua-server
.\bootstrap.ps1     # descarga Apache + las 7 versiones de PHP + MariaDB + Mailpit + mkcert + Composer, y ejecuta init
.\lua.ps1 start
```

## Proyectos existentes y dominios personalizados

Para servir una carpeta que ya tienes en `www\` con un dominio personalizado
(que la carpeta no puede tener como nombre), o una carpeta que vive **fuera**
de `www\`, lo más rápido es el panel (pestaña Proyectos → "Registrar proyecto
existente en otra carpeta del disco", o el botón **Adoptar** si ya está en
`www\`) o el comando `lua.ps1 add-external`.

También se puede editar `config\sites.json` a mano, admite `domain` (dominio
personalizado) y `path` (ruta externa) opcionales:

```json
{
  "sites": {
    "cliente.com": { "php": "8.3", "domain": "cliente.lua.test" },
    "ersmportal":  { "php": "7.4", "path": "C:/proyectos/ersmportal", "domain": "portal.ersm.test" }
  }
}
```

Luego `.\lua.ps1 reload`. `config\sites.json` es propio de cada máquina (no se
versiona); tienes una plantilla en `config\sites.example.json`.

## Servidor de equipo (opcional)

Para que Apache arranque solo al encender el PC (sin sesión iniciada) y todo
el stack sobreviva a un reinicio, la forma recomendada es el toggle
**"Arrancar con Windows"** del panel (pestaña Configuración del servidor) —
instala Apache como servicio y el watcher como tarea programada.

Por CLI equivale a:
```powershell
.\lua.ps1 startup-enable    # ADMIN: servicio Apache (auto) + tarea programada del watcher
.\lua.ps1 startup-disable   # ADMIN: vuelve al modo manual (lua.ps1 start)
.\lua.ps1 startup-status    # comprueba el estado actual
```

Si además quieres que el servidor sea accesible desde la red de la oficina
(no solo esta máquina), añade el firewall y el hosts para tus compañeros:
```powershell
.\lua.ps1 setup     # ADMIN: Apache como servicio + firewall (80/443, red local) + hosts
```

## Estructura

```
lua-server\
  lua.ps1              control (start/stop/add-site/add-external/switch-php/startup-enable...)
  bootstrap.ps1        descarga los binarios (PC nuevo)
  bin\                 apache, php\<version>, mariadb, mailpit, mkcert, composer  (no versionado)
  www\                 tus proyectos, incl. phpmyadmin/                          (no versionado)
  data\                covers\ (carátulas), mariadb\ (datos), ssl\ (mkcert)       (no versionado)
  config\
    sites.json          registro de proyectos (no versionado; plantilla: sites.example.json)
    php\*.overrides.ini  ajustes de php.ini por version (persisten)
    terminal.on          flag: terminal activada
    mariadb.on           flag: MariaDB activo
    https.on             flag: HTTPS activo
  tools\dashboard\      panel web (index.php, assets\, adminer.php)
  logs\ tmp\            runtime (no versionado)
```
