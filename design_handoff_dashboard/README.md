# Handoff: lua-server Dashboard

## Overview
Rediseño completo del panel de administración local de lua-server. Panel web en `http://localhost` para gestionar proyectos PHP, versiones de PHP, HTTPS local y Mailpit. Se sirve solo en localhost — no necesita autenticación ni responsividad móvil.

## Sobre los archivos de diseño
Los archivos incluidos son **prototipos de referencia en HTML/JSX** — no código de producción. La tarea es recrear estas interfaces en el stack de PHP/HTML/CSS del proyecto original (`tools/dashboard/index.php`), manteniendo los mismos patrones (PHP renderizado en servidor, formularios POST con redirección PRG).

## Fidelidad
**High-fidelity.** Colores, tipografía, espaciado e interacciones son definitivos. El desarrollador debe recrear el diseño píxel a píxel usando el stack existente (PHP + HTML + CSS vanilla). El mockup React es solo referencia visual; no copiar el código JSX directamente.

---

## Pantallas / Vistas

### 1. Layout general (todas las pestañas)

**Estructura:** flex column, altura 100vh, overflow hidden.

```
body (flex col, 100vh, overflow:hidden)
├── header         (flex row, padding: 10px 40px, bg: #1a1d27, border-bottom: 1px #2a2f3d)
├── tab-bar        (padding: 0 40px, bg: #1a1d27, border-bottom: 1px #2a2f3d)
├── content        (flex:1, overflow-y:auto, padding: 28px 40px 48px)
└── footer         (padding: 8px 40px, border-top: 1px #2a2f3d, text-align:center)
```

**Header:**
- Logo box: 44×44px, border-radius 6px, gradient `135deg #6ea8fe→#9b6efe`, texto "LUA", font-weight 800, font-size 17px, color #fff, letter-spacing 1px
- Título: "lua-server", font-size 19px, font-weight 700, color #e6e8ee
- Subtítulo: "Servidor PHP local · N proyecto(s) · PHP: versiones", font-size 12px, color #8b90a0
- Derecha: badges de estado (Apache UP → verde, Watcher activo → azul)

**Tab bar:**
- Tabs: padding 9px 16px, font-size 14px, font-weight 600
- Activo: color #6ea8fe, border-bottom 2px solid #6ea8fe
- Inactivo: color #8b90a0, border-bottom 2px solid transparent

**Footer:** "lua-server · Apache + mod_fcgid · panel solo accesible desde esta máquina", font-size 12px, color #8b90a0

---

### 2. Pestaña Proyectos

#### Formulario de creación
Card (bg #1a1d27, border 1px #2a2f3d, border-radius 8px, padding 18px 20px):
- Fila flex (align-items: flex-end, gap 8px): input Nombre + select Tipo + select PHP + botón "+ Crear"
- Fila Git (display:none por defecto, visible cuando tipo=git): input URL a ancho completo
- Texto de ayuda: font-size 12px, color #8b90a0

#### Grid de proyectos (6 columnas)
`display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px`

Cada card de proyecto:
- bg #1a1d27, border 1px #2a2f3d, border-radius 8px, padding 14px 16px
- flex column, gap 10px
- Nombre: font-weight 700, font-size 14px, overflow ellipsis
- Dominio: font-size 11px, color #8b90a0, overflow ellipsis
- Select de PHP: ancho 100%
- Botón Eliminar: variant danger, ancho 100%

#### Sección Tareas
- Encabezado: font-size 12px, uppercase, letter-spacing 0.5px, color #8b90a0 + botón "Limpiar historial" (ghost) a la derecha
- Card por tarea (padding 12px 16px): badge estado + nombre + tipo/hora + mensaje
- Badge estados: DONE (verde), RUNNING (azul), ERROR (rojo), QUEUED (ámbar)
- Log pre (solo en RUNNING): bg #11141c, border 1px #2a2f3d, font-mono 11px, max-height 72px, overflow auto

#### Cards del sistema (Dominios / HTTPS / Mailpit)
Cada una: flex row, align-items center, gap 12px, padding 18px 20px
- Título bold + descripción muted a la izquierda
- Botón de acción a la derecha
- HTTPS y Mailpit muestran badge ACTIVO/INACTIVO junto al título

---

### 3. Pestaña Versiones PHP

- Descripción muted arriba
- Un acordeón colapsable por versión de PHP (7.4, 8.1, 8.2, 8.3, 8.4, 8.5)
- Acordeón: border 1px #2a2f3d, border-radius 6px, bg #1a1d27, overflow hidden
- Summary: padding 14px 18px, font-weight 700, font-size 16px, cursor pointer, flecha ▲/▼ a la derecha
- Contenido abierto: padding 4px 18px 18px
  - Fila Xdebug: badge estado + botón activar/desactivar
  - Grid 6 columnas (auto-fill, min 190px): inputs para cada directiva php.ini
  - Textarea para directivas adicionales: font-mono, min-height 70px
  - Botón "Guardar y aplicar"

---

### 4. Pestaña Logs

- Fila de botones: uno por cada archivo .log (variant primary si seleccionado, ghost si no)
- Botones "Auto-refresco" y "Vaciar" a la derecha (ghost)
- Pre con contenido del log: bg #11141c, border 1px #2a2f3d, border-radius 3px, padding 10px, font-mono 13px, color #8b90a0, max-height 62vh, overflow-y auto, white-space pre-wrap

---

## Interacciones y comportamiento

| Acción | Comportamiento |
|---|---|
| Cambiar PHP de un sitio | POST action=switch → redirect → banner "aplicado" → Apache recarga en ~1s |
| Eliminar proyecto | Confirmación JS → POST action=delete → banner "aplicado" |
| Crear proyecto | POST → job en cola → meta refresh cada 3s hasta completar |
| Activar HTTPS | POST → UAC de Windows → banner info → recarga en 7s |
| Activar Mailpit | POST → descarga si no hay exe → banner job |
| Guardar php.ini | POST action=phpini → banner "aplicado" → Apache recarga |
| Toggle Xdebug | POST action=xdebug → redirect a ?tab=php&ver=X |
| Vaciar log | POST action=clearlog → redirect a ?tab=logs |
| Sincronizar dominios | POST action=hosts → UAC de Windows |

**Banners de feedback:**
- `applied` (verde): recarga automática en 4.2s
- `info` (azul): recarga en 7s
- `job` (ámbar): meta refresh cada 3s
- `error` (rojo): sin recarga automática

---

## Design Tokens

### Colores (modo oscuro — por defecto)
| Token | Valor | Uso |
|---|---|---|
| `--color-bg` | `#0f1117` | Fondo de página |
| `--color-surface` | `#1a1d27` | Cards, header, tab bar |
| `--color-input-bg` | `#11141c` | Inputs, selects, logs |
| `--color-border` | `#2a2f3d` | Todos los bordes |
| `--color-text` | `#e6e8ee` | Texto principal |
| `--color-text-muted` | `#8b90a0` | Texto secundario |
| `--color-accent` | `#6ea8fe` | Tabs activos, links, botón primary |
| `--color-success` | `#3fb950` | Badge ok, banner applied |
| `--color-warning` | `#d29922` | Badge queued/warning |
| `--color-error` | `#f85149` | Badge error, botón danger |

### Tipografía
- UI: `system-ui, 'Segoe UI', Roboto, sans-serif`
- Mono/logs: `ui-monospace, Consolas, 'Courier New', monospace`

| Tamaño | Valor | Uso |
|---|---|---|
| xs | 11px | Badges, dominios |
| sm | 12px | Labels, footer, subtítulos |
| base | 13px | Logs, código |
| md | 14px | UI principal, botones, tabs |
| lg | 16px | Nombres de proyecto, títulos de acordeón |
| xl | 19px | Logo mark |
| 2xl | 21px | Título de página |

Pesos: 400 normal · 600 semibold (botones, tabs) · 700 bold (nombres, h1) · 800 extrabold (logo)

### Espaciado clave
- Card padding: 18px 20px (estándar), 12px 16px (compacto), 14px 16px (grid card)
- Gap entre elementos de fila: 8–12px
- Padding del contenido: 28px 40px
- Padding del header: 10px 40px

### Border radius (valores reducidos del original)
| Token | Valor | Uso |
|---|---|---|
| xs | 3px | Bloques de código/log |
| sm | 4px | — |
| md | 5px | Inputs, selects, botones |
| lg | 6px | Logo box, acordeones |
| xl | 8px | Cards |
| pill | 999px | Badges |

### Botones
Todos los botones tienen `border: 1px solid` para caja consistente:
- Primary: bg `#6ea8fe`, color #fff, border transparent
- Ghost: bg transparent, color text, border `#2a2f3d`
- Danger: bg transparent, color `#f85149`, border `#2a2f3d` → hover: bg `#f85149`, color #fff
- Tamaño md: padding 8px 16px · Tamaño sm: padding 4px 10px

---

## Assets

- **Logo mark:** CSS-rendered — no hay SVG. Caja 44×44px con gradient `135deg #6ea8fe→#9b6efe` + texto "LUA" en blanco.
- **Iconografía:** ninguna. Solo texto y Unicode (↗ para links externos, ▲▼ para acordeones).

---

## Archivos incluidos

| Archivo | Descripción |
|---|---|
| `dashboard/index.html` | Prototipo interactivo completo (React/Babel, referencia visual) |
| `styles.css` | Tokens CSS (importar como referencia) |
| `tokens/colors.css` | Variables de color |
| `tokens/typography.css` | Variables de tipografía |
| `tokens/spacing.css` | Variables de espaciado |
| `tokens/radii.css` | Variables de border-radius |
| `readme.md` | Design system completo con guías de estilo |

La implementación target es `tools/dashboard/index.php` en el repositorio lua-server.
