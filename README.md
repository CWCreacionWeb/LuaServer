# lua-server

Servidor web local **portable** para la agencia LUA que **solo ejecuta PHP**.
Estilo XAMPP/Laragon pero a medida, con **varias versiones de PHP a la vez** y cada
proyecto usando la que necesite. Sin Docker, sin VMs: PHP nativo leyendo directo de
disco (mucho más rápido que las carpetas compartidas de Docker en Windows).

> La base de datos **no** forma parte de este stack: vive en Docker. Si un proyecto
> necesita MySQL, conéctalo desde PHP a `127.0.0.1:3306` (tu contenedor).

## Qué incluye

- **Apache 2.4** + **mod_fcgid** (enruta cada proyecto a su versión de PHP)
- **PHP 7.4, 8.1, 8.2, 8.3, 8.4, 8.5** (NTS x64) conviviendo
- **Panel web** en `http://localhost` para gestionar todo con el ratón
- Script de control `lua.ps1` para quien prefiera la terminal

## Panel web (http://localhost)

Con el servidor arrancado, abre **http://localhost** (solo accesible desde esta
máquina). Desde ahí puedes, sin tocar la terminal:

- **Proyectos**: crear (en blanco, **Laravel**, **WordPress**, **Symfony**, **Slim**
  o **clonando un repo de Git**), eliminar y cambiar la versión de PHP de cada uno.
  Las creaciones pesadas se hacen en segundo plano con progreso visible.
- **Versiones PHP**: editar los valores del `php.ini` de cada versión
  (memoria, tamaños de subida, timezone, errores…) más directivas libres, y
  **activar/desactivar Xdebug** por versión (descarga la DLL correcta y lo deja
  configurado para depurar paso a paso en el puerto **9003**, VS Code/PhpStorm).
- **Logs**: ver en vivo los logs de Apache y de cada proyecto desde el navegador,
  con auto-refresco y opción de vaciar.
- **HTTPS local**: botón para activar certificados de confianza (mkcert) y servir
  `https://<proyecto>.lua.test` con candado verde. Al activarlo, Windows pide
  permiso (UAC) para instalar la CA local una sola vez.
- **Mailpit**: botón para activar una bandeja de correo local que captura los emails
  que envían tus proyectos PHP (SMTP `127.0.0.1:1025`); buzón web en `http://localhost:8025`.

Los cambios se aplican solos: un proceso *watcher* (que arranca con `lua.ps1 start`)
recarga Apache en un par de segundos. Los ajustes de `php.ini` se guardan como
*overrides* en `config\php\<version>.overrides.ini` y **sobreviven** a las
actualizaciones del stack.

## Requisitos

- Windows 10/11 x64
- Microsoft Visual C++ Redistributable v14 x64 (`downloads\vc_redist.x64.exe`;
  instálalo si Apache/PHP no arrancan por falta de DLL)

## Uso diario

```powershell
.\lua.ps1 start                    # arranca (sin admin)
.\lua.ps1 status                   # estado y lista de sitios
.\lua.ps1 add-site micliente 8.3   # nuevo proyecto en www\micliente con PHP 8.3
.\lua.ps1 switch-php micliente 8.5 # cambiar su version de PHP al instante
.\lua.ps1 list-php                 # versiones de PHP disponibles
.\lua.ps1 stop
```

Cada proyecto vive en `www\<nombre>\` y se sirve en `http://<nombre>.lua.test`.
Si tiene carpeta `public\` (Laravel/Symfony) se usa como raíz automáticamente.

> Para abrir los dominios `.lua.test` en el navegador hay que añadirlos al archivo
> `hosts` (una vez, como admin): lo hace `add-site`/`reload` si ejecutas como admin,
> o `.\lua.ps1 setup`. El panel `http://localhost` funciona sin nada.

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
.\bootstrap.ps1     # descarga Apache + las 6 versiones de PHP, y ejecuta init
.\lua.ps1 start
```

## Proyectos existentes y dominios personalizados

Para servir una carpeta que ya tienes en `www\` (p.ej. un WordPress con nombre de
dominio), añádela a `config\sites.json`. Admite un `domain` opcional cuando el
nombre de la carpeta no sirve como dominio:

```json
{
  "sites": {
    "cliente.com": { "php": "8.3", "domain": "cliente.lua.test" }
  }
}
```

Luego `.\lua.ps1 reload`. `config\sites.json` es propio de cada máquina (no se
versiona); tienes una plantilla en `config\sites.example.json`.

## Servidor de equipo (opcional)

Para que Apache arranque solo al encender el PC y sea accesible por la red de la oficina:
```powershell
.\lua.ps1 setup     # ADMIN: Apache como servicio + firewall (80/443) + hosts
```

## Estructura

```
lua-server\
  lua.ps1            control (start/stop/add-site/switch-php/init...)
  bootstrap.ps1      descarga los binarios (PC nuevo)
  bin\               apache, php\<version>            (no versionado)
  www\               tus proyectos
  config\            httpd-lua.conf, plantillas, sites.json
  tools\dashboard\   panel de inicio
  logs\ tmp\         runtime (no versionado)
```
