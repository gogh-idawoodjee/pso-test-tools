# PSO Test Tools — Theme (Out of Jam family)

Restyle the app to match IFS CMDB. Light is the default; dark is a `[data-theme="dark"]` variant. Keep all existing layout/behaviour; change only styling, logo and favicon.

## Fonts
```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
```
- `--font-display: 'Anton', sans-serif;` — wordmark, page titles (H1), large stat numbers. Always uppercase. Never for body or buttons.
- `--font-sans: 'IBM Plex Sans', system-ui, sans-serif;` — all UI and body text. Weights 400/500/600.
- `--font-mono: 'IBM Plex Mono', ui-monospace, monospace;` — nav counts, IDs, filenames, JSON/XML, timestamps.

## Tokens
```css
:root {
  --violet: #2A1B4F;   --lime: #C7F53A;   --iris: #8567DA;   --bone: #F6F3EC;

  --bg: #F6F3EC;            /* page */
  --surface: #FFFFFF;       /* cards, inputs, tab bar */
  --surface-2: #FBF9F5;     /* nested panels, table header */
  --border: #E7E1D6;
  --border-strong: #D3CBBB; /* inputs, dashed dropzone, secondary btn */
  --text: #1B1830;
  --text-muted: #6B6578;
  --link: #5B3FBF;

  --chrome-bg: #2A1B4F;     /* top bar + sidebar */
  --chrome-text: #F6F3EC;
  --chrome-muted: #A99FC4;  /* section labels, counts */
  --nav-active-bg: #C7F53A;
  --nav-active-text: #2A1B4F;

  --primary-bg: #2A1B4F;    --primary-text: #C7F53A;  --primary-hover: #3A2869;
  --accent: #8567DA;        /* toggles on, charts, focus */
  --tab-active-bg: #EFEAFB; --tab-active-text: #5B3FBF;
  --focus-ring: 0 0 0 3px rgba(133,103,218,.35);

  --success: #1C7C45; --success-bg: #E3F4E8;
  --warning: #A15C07; --warning-bg: #FCEFD9;
  --danger:  #B42318; --danger-bg:  #FDE7E4;
  --info:    #5B3FBF; --info-bg:    #EFEAFB;

  --radius-sm: 4px;  /* badges */
  --radius:    6px;  /* buttons, inputs, nav items, tabs */
  --radius-lg: 10px; /* cards */
  --shadow-card: 0 1px 2px rgba(42,27,79,.06);
}

[data-theme="dark"] {
  /* Content is near-neutral charcoal (changed 2026-10-16: the violet-tinted surfaces read as "too purple");
     only the top bar and sidebar keep the brand violet. */
  --bg: #141318;
  --surface: #1C1B22;
  --surface-2: #24232B;
  --border: #2E2D37;
  --border-strong: #3F3D4A;
  --text: #F6F3EC;
  --text-muted: #A4A1B0;
  --link: #B9A4F5;

  --chrome-bg: #2A1B4F;     /* top bar stays brand violet */
  --sidebar-bg: #1F1640;    /* sidebar darker than top bar in dark mode */

  --primary-bg: #C7F53A;    --primary-text: #2A1B4F;  --primary-hover: #D6FA66;
  --tab-active-bg: #2A2838; --tab-active-text: #B9A4F5;

  --success: #6EE7A0; --success-bg: #143826;
  --warning: #FBBF5A; --warning-bg: #3A2A10;
  --danger:  #FF8A7A; --danger-bg:  #3D1712;
  --info:    #B9A4F5; --info-bg:    #2A2838;
  --shadow-card: none;
}
```
Light sidebar uses `--chrome-bg`; dark uses `--sidebar-bg` with a 1px `--border` right edge.

## Logo (header, top-left)
Live text, not an image. Replace the current purple circle icon + "PSO Test Tools" text.
```html
<a class="brand" href="/">
  <span class="brand-word">PSO <span class="brand-accent">TEST TOOLS</span></span>
  <span class="brand-by">BY OUT OF JAM</span>
</a>
```
```css
.brand { display:flex; flex-direction:column; gap:3px; text-decoration:none; }
.brand-word { font-family:var(--font-display); font-size:28px; line-height:1; letter-spacing:.005em; color:var(--bone); }
.brand-accent { color:var(--lime); }
.brand-by { font-family:var(--font-sans); font-weight:600; font-size:9px; letter-spacing:.18em; color:var(--bone); }
```
On a light background (login page, emails): violet text, and "TEST TOOLS" gets a lime box (`background:var(--lime); color:var(--violet); padding:0 .16em; border-radius:4px`). Never put lime text on bone/white — it fails contrast.

## Favicon
Copy `favicon.svg`, `favicon-32.png`, `favicon-180.png` (rename to `apple-touch-icon.png`) and `favicon-512.png` to `public/`. Generate `favicon.ico` (16+32) from the SVG.
```html
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon-32.png" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="theme-color" content="#2A1B4F">
```
Favicon is the lime tile with violet P. `pso-mark-violet.svg` is the alternate for light surfaces.

## Logo files (`logo/`)
Text outlined to paths, no font needed. PNGs at 4x.
- `pso-logo-dark.svg` — primary lockup (mark + wordmark) on #120C24 card. Login screen, README, slides.
- `pso-logo-dark-transparent.svg` — same lockup, no background. Use on any dark surface.
- `pso-wordmark-header.svg` — wordmark only, for the app top bar if you prefer an image over live text.

## Component rules
- **Top bar**: 64px, `--chrome-bg`, logo left, avatar right (`#09090B` circle). No bottom border.
- **Sidebar**: `--chrome-bg` (light) / `--sidebar-bg` (dark). Items 13–14px Plex Sans, `--chrome-text`, padding 8px 10px, radius 6px. Active item = solid `--nav-active-bg` with `--nav-active-text`, weight 500 — matches CMDB. Hover: `rgba(255,255,255,.06)`. Section headers ("Setup", "Admin") 11px uppercase, letter-spacing .06em, `--chrome-muted`. Counts in Plex Mono 11px, `--chrome-muted`; drop the bordered pill boxes.
- **Page title**: Anton, uppercase, 34–40px, `--text`. Subtitle Plex Sans 16px `--text-muted`.
- **Cards**: `--surface`, 1px `--border`, `--radius-lg`, `--shadow-card`, padding 20–24px. Card titles Plex Sans 16px/600.
- **Segmented tabs**: container `--surface` + 1px `--border`, radius 8px, padding 4px; active tab `--tab-active-bg` / `--tab-active-text`, weight 500.
- **Buttons**: primary `--primary-bg` / `--primary-text`, 500 weight, radius 6px, padding 9px 16px. Secondary: transparent, 1px `--border-strong`, `--text`. Destructive: `--danger` bg, white text.
- **Inputs**: `--surface`, 1px `--border-strong`, radius 6px; focus `--accent` border + `--focus-ring`. Helper text 13px `--text-muted`.
- **Dropzone**: 1px dashed `--border-strong`, radius 8px, "Browse" in `--link` weight 500.
- **Toggles**: on = `--accent` track, white knob; off = `--border-strong` track.
- **Badges/status**: 12px, radius 4px, padding 3px 8px, `--{status}` text on `--{status}-bg`.
- **Links**: `--link`, underline on hover only. Filenames/IDs in Plex Mono.
- Icons: keep the current outline icon set, 1.5px stroke, colour inherits text.
