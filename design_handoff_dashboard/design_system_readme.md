# LuaServer Design System

## Product Overview

**lua-server** is a portable local PHP web server for the **LUA agency** (agencia LUA). It runs multiple PHP versions simultaneously (7.4, 8.1, 8.2, 8.3, 8.4, 8.5) on Windows 10/11 using Apache 2.4 + mod_fcgid — no Docker, no VMs. The web panel at `http://localhost` manages projects, PHP configurations, HTTPS (mkcert), Xdebug, and Mailpit from a browser.

**Sources:**
- Codebase at `LuaServer/` (PowerShell CLI `lua.ps1`, web panel `tools/dashboard/index.php`, config templates)
- No Figma file provided; no GitHub URL provided

---

## Content Fundamentals

**Language:** Spanish (es). All UI copy is Spanish — no English mixed in.

**Tone:** Technical, concise, direct. Developer-facing. No marketing language.

**Voice:**
- Second person informal: *"Crea el primero arriba"*, *"Acepta el aviso de Windows"*
- Imperative CTAs: *"Crear"*, *"Eliminar"*, *"Activar HTTPS"*
- Parenthetical consequences: *(la carpeta se conserva)*, *(sin admin)*
- Passive for automatic actions: *"Los cambios se aplican solos"*

**Casing:** Sentence case for labels and descriptions. ALL CAPS for status badges (DONE, ERROR, RUNNING, ACTIVO, INACTIVO).

**Error messages:** Declarative + actionable: *"Versión de PHP no instalada. Disponibles: 8.1, 8.2…"*

**Placeholders:** Realistic examples: *micliente*, *Europe/Madrid*, *https://github.com/usuario/repo.git*

**Emoji:** None. Plain text and Unicode symbols only (↗ for external links, ▶/⏸ for toggle actions).

---

## Visual Foundations

### Colors
Dark mode by default; light mode via `prefers-color-scheme: light`.

**Dark palette:**
| Token | Value | Use |
|---|---|---|
| `--color-bg` | `#0f1117` | Page background |
| `--color-surface` | `#1a1d27` | Card backgrounds |
| `--color-input-bg` | `#11141c` | Input/select backgrounds |
| `--color-border` | `#2a2f3d` | All borders and dividers |
| `--color-text` | `#e6e8ee` | Primary text |
| `--color-text-muted` | `#8b90a0` | Secondary text, labels |
| `--color-accent` | `#6ea8fe` | Active tabs, links, primary buttons |
| `--color-brand-start` | `#6ea8fe` | Brand gradient start |
| `--color-brand-end` | `#9b6efe` | Brand gradient end |

**Semantic:**
- Success `#3fb950` (green) — active states, DONE badges
- Warning `#d29922` (amber) — QUEUED/running states
- Error `#f85149` (red) — ERROR badges, danger buttons, INACTIVO

**Light mode** overrides: bg `#f4f6fb`, surface `#fff`, accent `#2b6cff`.

### Typography
System fonts only — no custom webfonts.
- UI: `system-ui, 'Segoe UI', Roboto, sans-serif`
- Logs/code: `ui-monospace, Consolas, 'Courier New', monospace`

Scale: 11px (xs, badge labels) → 13px (base, code) → 14px (md, primary UI text) → 16px (lg, project names) → 21px (2xl, page title).

Weights: 400 normal · 600 semibold (buttons, tab labels) · 700 bold (item names, headings) · 800 extrabold (logo mark).

### Spacing & Density
Compact developer-tool density. Key values: 6px tab gap · 8–12px card internal gaps · 18–20px card padding · 34px page outer padding.

### Borders & Radius
All borders: `1px solid var(--color-border)`. No decorative borders.
- Buttons / inputs / selects: `9px` (`--radius-md`)
- Cards: `14px` (`--radius-xl`)
- Accordions: `12px` (`--radius-lg`)
- Badges / pills: `999px` (`--radius-pill`)
- Log/code blocks: `6–8px`

### Shadows
None. Depth via background color contrast (bg vs surface).

### Backgrounds
Solid flat colors only. No images, textures, or gradients — except the **brand logo gradient** (`135deg, #6ea8fe → #9b6efe`) used exclusively on the "LUA" mark box.

### Cards
`--color-surface` background · 1px `--color-border` border · 14px radius · 18px/20px padding. Frequently used as flex rows (`align-items: center; gap: 12px`).

### Hover / Press States
- Primary buttons: `filter: brightness(1.08)`
- Danger buttons: fill with `--color-error`, text white
- Ghost buttons: border and text shift to `--color-accent`
- Links: color shift to `--color-accent`
- No scale/shrink animations on press

### Motion
Minimal. Only `transition: color/filter/background 0.12s` on interactive elements. No page transitions or loading animations beyond status banners.

### Transparency
No blur or backdrop-filter. Badge backgrounds use low-opacity fills (e.g. `rgba(110,168,254,.12)`).

---

## Iconography

No icon font, SVG icon set, or icon library is used. All actions use text-only button labels. Status is communicated via colored pill badges. External links use the Unicode arrow ↗ (`&#8599;`).

**No logo SVG found** in the provided codebase. The brand mark is CSS-rendered — see `assets/README.md`.

---

## Manifest / Index

```
lua-server Design System
├── styles.css                      Global CSS entry point
├── tokens/
│   ├── colors.css                  Dark + light color tokens
│   ├── typography.css              Font stacks + scale + weights
│   ├── spacing.css                 Spacing scale
│   └── radii.css                   Border radius scale
├── components/
│   ├── core/                       Button, Badge, Card
│   ├── forms/                      Input, Select
│   ├── navigation/                 Tabs
│   └── feedback/                   StatusBanner, LogViewer
├── guidelines/                     Foundation specimen cards
├── ui_kits/
│   └── dashboard/                  Interactive dashboard recreation
├── assets/
│   └── README.md                   No logo file; see note
├── thumbnail.html                  Design system tile
├── readme.md                       This file
└── SKILL.md                        Claude Code skill descriptor
```

### Components

| Component | Path | Description |
|---|---|---|
| Button | components/core/ | Primary / ghost / danger button |
| Badge | components/core/ | Pill status badge |
| Card | components/core/ | Surface container |
| Input | components/forms/ | Labeled text input |
| Select | components/forms/ | Labeled dropdown |
| Tabs | components/navigation/ | Horizontal tab strip |
| StatusBanner | components/feedback/ | Operation feedback banner |
| LogViewer | components/feedback/ | Scrollable monospace log viewer |

**Intentional additions:** None. All components correspond directly to UI patterns in the source dashboard.

**No logo SVG was found** in the provided codebase — the brand mark is a CSS gradient box. Request a real `logo.svg` from the team.

**No custom webfonts** — the design uses system fonts only.
