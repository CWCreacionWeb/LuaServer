# lua-server

Entorno de servidor web local **portable** para la agencia LUA. Estilo XAMPP/Laragon
pero montado a medida, con **varias versiones de PHP a la vez** y cada proyecto
usando la versión que necesite. Sin Docker, sin VMs: PHP nativo leyendo directo de
disco (mucho más rápido que carpetas compartidas de Docker en Windows).

## Qué incluye

- **Apache 2.4** + **mod_fcgid** (enruta cada proyecto a su versión de PHP)
- **PHP 7.4, 8.1, 8.2, 8.3, 8.4, 8.5** (NTS x64) conviviendo
- **MariaDB 11.8 LTS** (opcional) + **Adminer** para la base de datos
- Panel web en `http://localhost` y un único script de control: `lua.ps1`

## Requisitos

- Windows 10/11 x64
- Microsoft Visual C++ Redistributable v14 x64 (incluido en `downloads\vc_redist.x64.exe`;
  instálalo si Apache/PHP no arrancan por falta de DLL)

## Uso diario

```powershell
.\lua.ps1 start                 # arranca (sin admin, modo consola)
.\lua.ps1 status                # estado y lista de sitios
.\lua.ps1 add-site micliente 8.3   # nuevo proyecto en www\micliente con PHP 8.3
.\lua.ps1 switch-php micliente 8.5 # cambiar su version de PHP al instante
.\lua.ps1 list-php              # versiones de PHP disponibles
.\lua.ps1 stop                  # parar
```

Cada proyecto vive en `www\<nombre>\` y se sirve en `http://<nombre>.lua.test`.
Si tiene carpeta `public\` (Laravel/Symfony) se usa como raíz automáticamente.

> Para abrir los dominios `.lua.test` en el navegador hay que añadirlos al archivo
> `hosts` (una vez, como admin). Lo hace `add-site`/`reload` si ejecutas como admin,
> o `.\lua.ps1 setup`. También puedes abrir el panel en `http://localhost` sin nada.

## Portabilidad

Todo está autocontenido en esta carpeta y las rutas se recalculan solas.

**Opción A — copiar la carpeta** (a otro PC o USB):
```powershell
# en el PC nuevo, dentro de la carpeta copiada:
.\lua.ps1 init      # re-aplica las rutas a la nueva ubicacion
.\lua.ps1 start
```

**Opción B — repositorio git** (recomendado para el equipo; los binarios NO van en git):
```powershell
git clone <tu-repo> lua-server
cd lua-server
.\bootstrap.ps1     # descarga Apache/PHP/MariaDB/Adminer y ejecuta init
.\lua.ps1 start
```

## Base de datos (opcional)

Para tener MariaDB como servicio del equipo, con acceso por la red local:
```powershell
.\lua.ps1 setup     # ADMIN: instala servicios, firewall y asegura MariaDB
```
Conéctate siempre a la BD por **`127.0.0.1`** (no `localhost`), y desde otros equipos
por la **IP LAN** del servidor. Gestor web en `http://localhost/adminer`.

> Si tienes **Docker** corriendo con MySQL/MariaDB, ocupa el 3306 en IPv6 y puede
> confundir a `localhost`. Usar `127.0.0.1` lo evita. Si no vas a usar la BD, ignórala.

## Estructura

```
lua-server\
  lua.ps1            control (start/stop/add-site/switch-php/init...)
  bootstrap.ps1      descarga los binarios (PC nuevo)
  bin\               apache, php\<version>, mariadb   (no versionado)
  www\               tus proyectos
  config\            httpd-lua.conf, plantillas, my.ini, sites.json
  tools\             adminer.php, dashboard\
  data\ logs\ tmp\   runtime (no versionado)
```
