# Sistem Desain UI/UX SIAP (Adaptasi SIPIKET)

Dokumen ini merupakan terjemahan bahasa visual aplikasi **SIPIKET** (Laravel + React, `D:\laragon\www\sipiket`) ke dalam implementasi murni HTML + CSS custom + utility **Bootstrap 5** untuk aplikasi **SIAP** (PHP Native). Tidak ada React, Vue, Tailwind, atau build Node.js. Semua warna, tipografi, radius, bayangan, dan spacing ditiru dari referensi SIPIKET lalu dipetakan ke variabel CSS `:root` dan class bawaan Bootstrap 5.

---

## 1. Color Palette (Variabel CSS)

Semua warna diambil dari token `lp.*` di `tailwind.config.js` SIPIKET dan implementasi di `resources/css/app.css`. Aplikasi SIAP menggunakan latar **terang** (tidak ada dark mode).

| Token | Penggunaan | HEX | RGB | Catatan |
|-------|------------|-----|-----|---------|
| `--siap-bg` | Latar halaman / body | `#F3F5F8` | 243, 245, 248 | slate-50-ish, latar utama seluruh halaman |
| `--siap-ink` | Judul, teks utama | `#020617` | 2, 6, 23 | slate-950, hampir hitam |
| `--siap-body` | Paragraf / teks badan | `#475569` | 71, 85, 105 | slate-600 |
| `--siap-muted` | Teks tersier / keterangan | `#64748B` | 100, 116, 139 | slate-500 |
| `--siap-faint` | Label mono, teks redup | `#94A3B8` | 148, 163, 184 | slate-400 |
| `--siap-line` | Garis pemisah / border lembut | `#E2E8F0` | 226, 232, 240 | slate-200 |
| `--siap-blue` | Aksen biru | `#3B82F6` | 59, 130, 246 | blue-500 |
| `--siap-ink2` | Aksi utama / teks pada elemen biru | `#2563EB` | 37, 99, 235 | blue-600 |
| `--siap-blue-grad-start` | Gradien panel/tombol biru (awal) | `#4A8BF7` | 74, 139, 247 | — |
| `--siap-blue-grad-end` | Gradien panel/tombol biru (akhir) | `#2260E3` | 34, 96, 227 | — |

**Warna status / alert** (dipakai badge & notifikasi, mengikuti palet Filament yang diterapkan SIPIKET):

| Status | HEX bg | HEX teks | HEX border | Digunakan untuk |
|--------|--------|----------|------------|-----------------|
| success | `#ECFDF5` (emerald-50) | `#065F46` (emerald-700) | `#A7F3D0` (emerald-200) | Izin disetujui, berhasil |
| warning | `#FFFBEB` (amber-50) | `#713F12` (amber-700) | `#FED7AA` (amber-200) | Peringatan, menunggu |
| danger | `#FFF1F2` (rose-50) | `#9D174D` (rose-700) | `#FEA8A8` (rose-200) | Ditolak, error, hapus |
| info | `#EFF6FF` (blue-50) | `#1D4ED8` (ink2 / blue-600) | `#DBEAFE` (blue-100) | Informasi, primary |

---

## 2. Typography

SIPIKET menggunakan font **Geist** dengan fallback **Inter** (sans-serif) dan **JetBrains Mono** (monospace). Untuk SIAP (tanpa Google Fonts build), gunakan font sistem yang mirip: `system-ui, -apple-system, Segoe UI, Roboto, Inter, "Helvetica Neue", sans-serif` untuk sans-serif, dan `ui-monospace, SFMono-Regular, "SF Mono", Consolas, "Liberation Mono", monospace` untuk mono.

Tangka ukuran aplikasi (`sp-*` di SIPIKET) dipetakan ke ukuran tetap (rem) agar data padat tetap terbaca:

| Jenis | Ukuran (rem / px) | Berat | Leading | Warna | Penggunaan |
|-------|-------------------|-------|---------|-------|------------|
| Eyebrow | 0.6875rem (11px) | medium | 1.2 | `--siap-blue` | Label mono huruf besar di atas judul |
| Display | 2.5rem (40px) → 3rem (48px) sm | normal | none | `--siap-ink` | Angka statistik besar |
| H1 | 1.75rem (28px) → 1.875rem (30px) sm | normal | 1.1 | `--siap-ink` | Judul halaman (PageHeader) |
| H2 | 1.25rem (20px) → 1.375rem (22px) sm | semibold | tight | `--siap-ink` | Judul section (SectionHead) |
| H3 | 1.0625rem (17px) | semibold | snug | `--siap-ink` | Judul kartu (CardHead) |
| H4 | 0.9375rem (15px) | semibold | snug | `--siap-ink` | Sub-judul kecil |
| Copy | 0.875rem (14px) | normal | relaxed | `--siap-body` | Paragraf badan |
| Copy-sm | 0.8125rem (13px) | normal | relaxed | `--siap-muted` | Keterangan kecil |
| Mono-xs | 0.625rem (10px) | medium | 1.2 | `--siap-faint` | Label mono kecil, huruf besar, spasi 0.2em |

Contoh pemetaan ke Bootstrap 5 + CSS custom:

```css
:root {
  --siap-font-sans: system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, sans-serif;
  --siap-font-mono: ui-monospace, SFMono-Regular, "SF Mono", Consolas, "Liberation Mono", monospace;
}
.siap-eyebrow { @apply font-mono text-[11px] font-medium uppercase text-siap-blue; letter-spacing: 0.2em; }
.siap-h1      { @apply text-[1.75rem] font-normal leading-[1.1] text-siap-ink; letter-spacing: -0.03em; }
.siap-h2      { @apply text-xl font-semibold leading-tight text-siap-ink; letter-spacing: -0.02em; }
.siap-h3      { @apply text-[1.0625rem] font-semibold leading-snug text-siap-ink; letter-spacing: -0.01em; }
.siap-copy    { @apply text-sm leading-relaxed text-siap-body; }
.siap-copy-sm { @apply text-[0.8125rem] leading-relaxed text-siap-muted; }
.siap-mono-xs { @apply font-mono text-[10px] uppercase text-siap-faint; letter-spacing: 0.2em; }
```

---

## 3. Komponen Inti (Spesifikasi & Pemetaan Bootstrap 5)

### Card

**Spesifikasi SIPIKET (`sp-card`):**
- `rounded-[20px]`, `border border-white`, `bg-white/75`
- Box-shadow berlapis: `0 1px 2px rgba(15,23,42,0.04)` + `0 14px 30px -12px rgba(15,23,42,0.12)`
- Padding: `p-5 sm:p-6`
- Varian klikable (`sp-card-link`): hover `-translate-y-0.5`, `border-blue-100`, `bg-white`, shadow biru diperkuat

**Pemetaan Bootstrap 5:**

| Asal SIPIKET | Class Bootstrap 5 / CSS custom SIAP |
|--------------|-------------------------------------|
| `sp-card` | `card siap-card` + custom CSS (`--siap-card-radius: 20px`, `bg-white/75`, `border: 1px solid var(--siap-line)`) |
| `sp-card-pad` | `p-5 sm:p-6` (utility Bootstrap) |
| `sp-card-link` hover | `siap-card-link` + `:hover` custom (transform, border-blue-100, bg-white, shadow) |
| `sp-panel-blue` (gradien) | `siap-panel-blue` + custom CSS gradien `#4A8BF7 → #3B82F6 → #2260E3` |
| `sp-window` (jendela macOS) | `siap-window` + custom CSS (`rounded-[20px]`, `border-white/80`, `bg-white`) |

### Button

**Spesifikasi SIPIKET (`sp-btn`):**
- `inline-flex min-h-[44px] items-center justify-center gap-2 whitespace-nowrap rounded-pill px-5 py-2.5 text-sm font-medium leading-tight transition duration-200`
- Ambang tap target 44px
- Focus-visible: `ring-2 ring-lp-blue ring-offset-2 ring-offset-lp-bg`

**Pemetaan Bootstrap 5:**

| Asal SIPIKET | Class Bootstrap 5 / CSS custom SIAP |
|--------------|-------------------------------------|
| `sp-btn` | `btn siap-btn` + custom CSS (rounded-pill, `min-height: 44px`, `px-5`, `py-2.5`, transition) |
| `sp-btn-blue` | `btn btn-primary siap-btn-blue` + custom gradien background (override `btn-primary`) |
| `sp-btn-white` | `btn btn-light siap-btn-white` + `border border-white bg-white text-siap-ink` |
| `sp-btn-ghost` | `btn siap-btn-ghost` + `border border-siap-line bg-transparent text-siap-body` |
| `sp-btn-danger` | `btn btn-danger siap-btn-danger` + `bg-rose-600` (override) |
| Disabled | `disabled` + `opacity-50 cursor-not-allowed` (bawaan Bootstrap) |

### Input Form

**Spesifikasi SIPIKET (`sp-input`):**
- `block w-full rounded-[14px] border-lp-line bg-white text-sm text-lp-ink placeholder-slate-400 shadow-sm transition duration-200`
- Focus: `border-lp-blue ring-2 ring-blue-100`
- Disabled: `bg-slate-50 text-lp-muted cursor-not-allowed`
- Label (`sp-label`): `block font-mono text-[11px] font-medium uppercase text-lp-muted letter-spacing 0.16em`

**Pemetaan Bootstrap 5:**

| Asal SIPIKET | Class Bootstrap 5 / CSS custom SIAP |
|--------------|-------------------------------------|
| `sp-input` | `form-control siap-input` + custom CSS (`border-radius: 14px`, `border: 1px solid var(--siap-line)`, `bg-white`, `text-sm`, shadow-sm) |
| `sp-input:focus` | `siap-input:focus` + `border-color: var(--siap-blue); box-shadow: 0 0 0 2px rgba(59,130,246,0.15)` |
| `sp-label` | `form-label siap-label` + custom CSS (mono, 11px, uppercase, muted) |
| Input group | `input-group input-group-sm` (Bootstrap bawaan) |

### Lainnya (Badge, Chip, Note, Tombol navigasi)

| Komponen SIPIKET | Spesifikasi | Pemetaan Bootstrap 5 |
|------------------|-------------|----------------------|
| `sp-badge` | `inline-flex items-center rounded-pill border px-2.5 py-0.5 font-mono text-[11px] font-medium uppercase` | `badge siap-badge` + custom CSS (rounded-pill, mono 11px) |
| `sp-chip` | `inline-flex items-center gap-2 rounded-pill bg-blue-50 px-3 py-1 text-[0.8125rem] font-medium` | `badge siap-chip` + `bg-blue-50 text-siap-ink2` |
| `sp-note` | `flex items-start gap-3 rounded-[16px] border px-4 py-3 text-sm` | `alert siap-note` + custom CSS (rounded-16px, border, padding) |
| `NavLink` | `rounded-pill px-3.5 py-2 text-sm font-medium`, active `bg-blue-50 text-lp-ink2`, inactive `text-slate-600 hover:bg-white hover:text-lp-ink` | `nav-link siap-nav-link` + custom CSS |
| `Tile` (petak ikon) | `grid h-11 w-11 shrink-0 place-items-center rounded-[14px] bg-blue-50 text-lp-blue` | `siap-tile` + custom CSS |

---

## 4. Struktur Layout

SIPIKET menggunakan **topbar horizontal** (tidak ada sidebar vertikal permanen) dengan menu navigasi di sebelah kanan dan dropdown profil. Pada layar kecil muncul hamburger menu (overlay drawer).

### 4.1 Halaman Utama (sp-page)

| Elemen | Spesifikasi SIPIKET | Implementasi SIAP (Bootstrap 5) |
|--------|---------------------|---------------------------------|
| Halaman | `sp-page`: `min-h-screen bg-lp-bg text-lp-body` | `.siap-page { min-height: 100vh; background: var(--siap-bg); color: var(--siap-body); }` |
| Kilau biru puncak | `radial-gradient(120% 90% at 50% 0%, rgba(219,234,254,0.75) 0%, rgba(243,245,248,0) 62%)`, `fixed inset-x-0 top-0 h-[360px]` | `.siap-glow-top { position: fixed; inset-x: 0; top: 0; height: 360px; background: radial-gradient(...); z-index: -1; }` |
| Container lebar | `sp-shell`: `mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8` | `.siap-shell { max-width: var(--bs-container-xl); padding: 0 1rem; margin: 0 auto; }` (pakai `container-xl` Bootstrap) |
| Kolom sempit | `sp-shell-narrow`: `max-w-[42rem]` | `.siap-shell-narrow { max-width: 42rem; }` |

### 4.2 Header / Topbar (AuthenticatedLayout)

| Elemen | Spesifikasi SIPIKET | Implementasi SIAP |
|--------|---------------------|-------------------|
| Header | `sticky top-0 z-30 pt-3 sm:pt-5` | `position: sticky; top: 0; z-index: 1030; padding-top: 0.75rem sm: 1.25rem;` |
| Nav (badan header) | `rounded-[24px] border border-white/70 bg-white/70 shadow-[...] backdrop-blur-xl` | `.siap-nav { border-radius: 24px; border: 1px solid rgba(255,255,255,0.7); background: rgba(255,255,255,0.7); box-shadow: ...; backdrop-filter: blur(12px); }` |
| Brand (logo + judul) | Logo `h-10 w-10 rounded-full border border-lp-line bg-white object-contain p-0.5`, judul `text-base font-semibold text-lp-ink`, subtitle `text-xs text-lp-muted` | `.siap-brand img { width: 2.5rem; height: 2.5rem; border-radius: 50%; border: 1px solid var(--siap-line); padding: 0.125rem; }` |
| Menu desktop | `flex items-center gap-1 lg:flex`, NavLink `rounded-pill px-3.5 py-2 text-sm font-medium` | `.siap-nav-link { border-radius: 9999px; padding: 0.5rem 0.875rem; font-size: 0.875rem; font-weight: 500; }` aktif: `bg-blue-50 text-siap-ink2` |
| Dropdown profil | Tombol `rounded-pill border border-lp-line bg-white py-1.5 ... hover:border-blue-200`, avatar `h-7 w-7 rounded-full bg-blue-50 font-mono text-[11px]` | `.siap-user-btn { border-radius: 9999px; border: 1px solid var(--siap-line); background: #fff; padding: 0.375rem; }` avatar: `.siap-avatar { width: 1.75rem; height: 1.75rem; border-radius: 50%; background: #eff6ff; color: var(--siap-ink2); font-family: var(--siap-font-mono); font-size: 0.6875rem; }` |
| Hamburger (mobile) | `h-11 w-11 grid place-items-center rounded-[14px] border border-lp-line bg-white text-siap-ink lg:hidden` | `.siap-menu-btn { width: 2.75rem; height: 2.75rem; border-radius: 14px; border: 1px solid var(--siap-line); }` lg:hidden |
| Drawer mobile | `border-t border-lp-line/70 px-3 pb-4 pt-3 lg:hidden` | `.siap-drawer { border-top: 1px solid rgba(...); padding: ...; }` tampil lewat class utilitas `d-lg-none` |

### 4.3 Konten Utama (main)

| Elemen | Spesifikasi SIPIKET | Implementasi SIAP |
|--------|---------------------|-------------------|
| Main | `sp-shell relative pb-16 pt-8 sm:pt-12` | `.siap-main { padding-top: 2rem sm: 3rem; padding-bottom: 4rem; }` |
| Ritme section | `sp-section > * + *`: `mt-7 sm:mt-9` | `.siap-section > * + * { margin-top: 1.75rem sm: 2.25rem; }` |
| Ritme stack | `sp-stack > * + *`: `mt-4` | `.siap-stack > * + * { margin-top: 1rem; }` |
| Ritme stack-sm | `sp-stack-sm > * + *`: `mt-2.5` | `.siap-stack-sm > * + * { margin-top: 0.625rem; }` |

### 4.4 PageShell Varian (pembagi kolom)

| Varian | Spesifikasi | Digunakan untuk |
|--------|-------------|-----------------|
| `single` | Kolom baca `max-w-[42rem]`, header menyempit sejajar | Halaman naratif (Auth, Approval) |
| `split` | Grid 12 kolom, isi 7–8 kolom + aside sticky 4–5 kolom (lg+) | Perizinan/Create, Persetujuan/Show, Profile/Edit |
| `feed` | Lebah penuh `sp-shell`, grid bebas | Dashboard, Index daftar |

### 4.5 Bottom Action Bar (mobile)

| Elemen | Spesifikasi | Implementasi SIAP |
|--------|-------------|-------------------|
| ActionBar | `sticky bottom-0 z-20 border-t bg-lp-bg/85 px-4 pt-3 backdrop-blur-md`, lg:static transparan | `.siap-action-bar { position: sticky; bottom: 0; z-index: 1030; border-top: 1px solid rgba(...); background: rgba(243,245,248,0.85); padding: 0.75rem; backdrop-filter: blur(12px); }` lg: `position: static; background: transparent;` |

---

## 5. CSS Overrides (Blok CSS :root Siap Pakai)

Blok CSS berikut dapat disalin ke `assets/css/presensi.css` atau `app.css` milik SIAP dan di-`@import` / `<link>` setelah Bootstrap 5 CSS. Semua token menggunakan prefix `siap-` agar tidak bentrok dengan variabel Bootstrap (`--bs-*`).

```css
/* =========================================================================
   SISTEM DESAIN UI/UX SIAP — Adaptasi SIPIKET
   Tempatkan file ini di assets/css/presensi.css (atau app.css) milik SIAP,
   setelah bootstrap.min.css.
   ========================================================================= */

:root {
  /* ── Warna ── */
  --siap-bg:       #F3F5F8;
  --siap-ink:      #020617;
  --siap-body:     #475569;
  --siap-muted:    #64748B;
  --siap-faint:    #94A3B8;
  --siap-line:     #E2E8F0;
  --siap-blue:     #3B82F6;
  --siap-ink2:     #2563EB;
  --siap-blue-start: #4A8BF7;
  --siap-blue-end:   #2260E3;

  /* Status / alert */
  --siap-success-bg: #ECFDF5;
  --siap-success-text: #065F46;
  --siap-success-border: #A7F3D0;
  --siap-warning-bg: #FFFBEB;
  --siap-warning-text: #713F12;
  --siap-warning-border: #FED7AA;
  --siap-danger-bg: #FFF1F2;
  --siap-danger-text: #9D174D;
  --siap-danger-border: #FEA8A8;
  --siap-info-bg: #EFF6FF;
  --siap-info-text: #1D4ED8;
  --siap-info-border: #DBEAFE;

  /* ── Tipografi ── */
  --siap-font-sans: system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, sans-serif;
  --siap-font-mono: ui-monospace, SFMono-Regular, "SF Mono", Consolas, "Liberation Mono", monospace;

  /* ── Ukuran ── */
  --siap-radius-card: 20px;
  --siap-radius-pill: 9999px;
  --siap-radius-window: 20px;
  --siap-radius-mini: 14px;
  --siap-radius-nav: 24px;
  --siap-tap-target: 44px;

  /* ── Shadow ── */
  --siap-shadow-card: 0 1px 2px rgba(15, 23, 42, 0.04), 0 14px 30px -12px rgba(15, 23, 42, 0.12);
  --siap-shadow-nav: 0 1px 2px rgba(15, 23, 42, 0.04), 0 18px 40px -24px rgba(15, 23, 42, 0.18);
  --siap-shadow-btn-blue: 0 12px 26px -10px rgba(37, 99, 235, 0.55);
}

/* ── Base ── */
.siap-page {
  min-height: 100vh;
  background-color: var(--siap-bg);
  color: var(--siap-body);
  font-family: var(--siap-font-sans);
}

body {
  background-color: var(--siap-bg);
  color: var(--siap-body);
  font-family: var(--siap-font-sans);
}

/* ── Glow top ── */
.siap-glow-top {
  position: fixed;
  inset-x: 0;
  top: 0;
  height: 360px;
  z-index: -1;
  pointer-events: none;
  background: radial-gradient(120% 90% at 50% 0%, rgba(219, 234, 254, 0.75) 0%, rgba(243, 245, 248, 0) 62%);
}

/* ── Shell / container ── */
.siap-shell {
  width: 100%;
  margin-left: auto;
  margin-right: auto;
  padding-left: 1rem;
  padding-right: 1rem;
}
@media (min-width: 576px) { .siap-shell { padding-left: 1.5rem; padding-right: 1.5rem; } }
@media (min-width: 992px) { .siap-shell { padding-left: 2rem; padding-right: 2rem; } }
.siap-shell-narrow { max-width: 42rem; }

/* ── Ritme vertikal ── */
.siap-section > * + * { margin-top: 1.75rem; }
@media (min-width: 576px) { .siap-section > * + * { margin-top: 2.25rem; } }
.siap-stack > * + *   { margin-top: 1rem; }
.siap-stack-sm > * + * { margin-top: 0.625rem; }

/* ── Tipografi ── */
.siap-eyebrow {
  font-family: var(--siap-font-mono);
  font-size: 0.6875rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.2em;
  line-height: 1.2;
  color: var(--siap-blue);
}
.siap-h1 { font-size: 1.75rem; font-weight: 400; line-height: 1.1; letter-spacing: -0.03em; color: var(--siap-ink); }
@media (min-width: 576px) { .siap-h1 { font-size: 1.875rem; } }
.siap-h2 { font-size: 1.25rem; font-weight: 600; letter-spacing: -0.02em; color: var(--siap-ink); }
@media (min-width: 576px) { .siap-h2 { font-size: 1.375rem; } }
.siap-h3 { font-size: 1.0625rem; font-weight: 600; letter-spacing: -0.01em; color: var(--siap-ink); }
.siap-h4 { font-size: 0.9375rem; font-weight: 600; letter-spacing: -0.01em; color: var(--siap-ink); }
.siap-copy  { font-size: 0.875rem; line-height: 1.78; color: var(--siap-body); }
.siap-copy-sm { font-size: 0.8125rem; line-height: 1.78; color: var(--siap-muted); }
.siap-mono-xs { font-family: var(--siap-font-mono); font-size: 0.625rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.2em; line-height: 1.2; color: var(--siap-faint); }

/* ── Card ── */
.siap-card {
  border-radius: var(--siap-radius-card);
  border: 1px solid var(--siap-line);
  background-color: rgba(255, 255, 255, 0.75);
  box-shadow: var(--siap-shadow-card);
}
.siap-card-pad { padding: 1.25rem; }
@media (min-width: 576px) { .siap-card-pad { padding: 1.5rem; } }
.siap-card-link { display: block; transition: all 0.2s; }
.siap-card-link:hover {
  transform: translateY(-0.125rem);
  border-color: #DBEAFE;
  background-color: #fff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05), 0 22px 44px -16px rgba(37, 99, 235, 0.28);
}

/* ── Button ── */
.siap-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: var(--siap-tap-target);
  gap: 0.5rem;
  white-space: nowrap;
  border-radius: var(--siap-radius-pill);
  padding: 0.625rem 1.25rem;
  font-size: 0.875rem;
  font-weight: 500;
  line-height: 1.25;
  transition: all 0.2s;
  cursor: pointer;
}
.siap-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 2px var(--siap-blue), 0 0 0 4px var(--siap-bg);
}
.siap-btn:disabled { cursor: not-allowed; opacity: 0.5; transform: none; }

.siap-btn-blue {
  background: linear-gradient(135deg, var(--siap-blue-start) 0%, var(--siap-blue) 45%, var(--siap-ink2) 100%);
  border: none;
  color: #fff;
  font-weight: 600;
  box-shadow: var(--siap-shadow-btn-blue);
}
.siap-btn-blue:hover:not(:disabled) {
  box-shadow: 0 16px 32px -10px rgba(37, 99, 235, 0.65);
  transform: translateY(-1px);
}
.siap-btn-white {
  border: 1px solid var(--siap-line);
  background-color: #fff;
  color: var(--siap-ink);
}
.siap-btn-white:hover:not(:disabled) { transform: translateY(-1px); }
.siap-btn-ghost {
  border: 1px solid var(--siap-line);
  background-color: transparent;
  color: var(--siap-body);
}
.siap-btn-ghost:hover:not(:disabled) {
  border-color: #DBEAFE;
  background-color: #fff;
  color: var(--siap-ink);
}
.siap-btn-danger {
  background-color: #f04438; /* rose-600 dekat */
  border: none;
  color: #fff;
  font-weight: 600;
  box-shadow: 0 12px 26px -12px rgba(225, 29, 72, 0.5);
}
.siap-btn-danger:hover:not(:disabled) { background-color: #f43f5e; transform: translateY(-1px); }

/* ── Input ── */
.siap-input {
  display: block;
  width: 100%;
  border-radius: var(--siap-radius-mini);
  border: 1px solid var(--siap-line);
  background-color: #fff;
  color: var(--siap-ink);
  font-size: 0.875rem;
  padding: 0.625rem 1rem;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
  transition: all 0.2s;
}
.siap-input::placeholder { color: #94A3B8; }
.siap-input:focus {
  border-color: var(--siap-blue);
  box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15);
  outline: none;
}
.siap-input:disabled {
  cursor: not-allowed;
  background-color: #f8fafc;
  color: var(--siap-muted);
}
.siap-label {
  display: block;
  font-family: var(--siap-font-mono);
  font-size: 0.6875rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.16em;
  color: var(--siap-muted);
  margin-bottom: 0.5rem;
}

/* ── Nav ── */
.siap-nav {
  position: sticky;
  top: 0;
  z-index: 1030;
  border-radius: var(--siap-radius-nav);
  border: 1px solid rgba(255, 255, 255, 0.7);
  background-color: rgba(255, 255, 255, 0.7);
  box-shadow: var(--siap-shadow-nav);
  backdrop-filter: blur(12px);
  padding-top: 0.75rem;
  padding-bottom: 0.75rem;
}
@media (min-width: 576px) { .siap-nav { padding-top: 1.25rem; padding-bottom: 1.25rem; } }

.siap-brand { display: inline-flex; align-items: center; gap: 0.75rem; transition: opacity 0.2s; }
.siap-brand:hover { opacity: 0.8; }
.siap-brand img { width: 2.5rem; height: 2.5rem; border-radius: 50%; border: 1px solid var(--siap-line); background: #fff; object-fit: contain; padding: 0.125rem; }
.siap-brand-title { font-size: 1rem; font-weight: 600; color: var(--siap-ink); line-height: 1.25; }
.siap-brand-sub { font-size: 0.75rem; color: var(--siap-muted); }

.siap-nav-link {
  border-radius: var(--siap-radius-pill);
  padding: 0.5rem 0.875rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: #475569;
  transition: all 0.2s;
}
.siap-nav-link:hover { background-color: #fff; color: var(--siap-ink); }
.siap-nav-link.active { background-color: #eff6ff; color: var(--siap-ink2); }

.siap-user-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.625rem;
  border-radius: var(--siap-radius-pill);
  border: 1px solid var(--siap-line);
  background-color: #fff;
  padding: 0.375rem 1.5rem 0.375rem 0.375rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--siap-ink);
  transition: all 0.2s;
}
.siap-user-btn:hover { border-color: #DBEAFE; }
.siap-avatar {
  width: 1.75rem;
  height: 1.75rem;
  border-radius: 50%;
  background-color: #eff6ff;
  color: var(--siap-ink2);
  font-family: var(--siap-font-mono);
  font-size: 0.6875rem;
  display: grid;
  place-items: center;
}

.siap-menu-btn {
  width: 2.75rem;
  height: 2.75rem;
  display: grid;
  place-items: center;
  border-radius: var(--siap-radius-mini);
  border: 1px solid var(--siap-line);
  background-color: #fff;
  color: var(--siap-ink);
  cursor: pointer;
  transition: all 0.2s;
}

/* ── Badge / Chip / Note ── */
.siap-badge {
  display: inline-flex;
  align-items: center;
  border-radius: var(--siap-radius-pill);
  border: 1px solid transparent;
  padding: 0.125rem 0.625rem;
  font-family: var(--siap-font-mono);
  font-size: 0.6875rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}
.siap-badge-success { border-color: var(--siap-success-border); background-color: var(--siap-success-bg); color: var(--siap-success-text); }
.siap-badge-warning { border-color: var(--siap-warning-border); background-color: var(--siap-warning-bg); color: var(--siap-warning-text); }
.siap-badge-danger  { border-color: var(--siap-danger-border);  background-color: var(--siap-danger-bg);  color: var(--siap-danger-text); }
.siap-badge-info    { border-color: var(--siap-info-border);    background-color: var(--siap-info-bg);    color: var(--siap-info-text); }

.siap-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  border-radius: var(--siap-radius-pill);
  background-color: #eff6ff;
  padding: 0.25rem 0.75rem;
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--siap-ink2);
}
.siap-chip.active { background-color: var(--siap-ink); color: #fff; }

.siap-note {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  border-radius: 16px;
  border: 1px solid transparent;
  padding: 0.75rem 1rem;
  font-size: 0.875rem;
}
.siap-note-blue { border-color: #DBEAFE; background-color: rgba(59, 130, 246, 0.05); color: var(--siap-ink2); }
.siap-note-green { border-color: var(--siap-success-border); background-color: var(--siap-success-bg); color: var(--siap-success-text); }
.siap-note-red   { border-color: var(--siap-danger-border);  background-color: var(--siap-danger-bg);  color: var(--siap-danger-text); }
.siap-note-amber { border-color: var(--siap-warning-border); background-color: var(--siap-warning-bg); color: var(--siap-warning-text); }

/* ── Panel gradien ── */
.siap-panel-blue {
  border-radius: 24px;
  color: #fff;
  background: linear-gradient(135deg, var(--siap-blue-start) 0%, var(--siap-blue) 42%, var(--siap-blue-end) 100%);
  box-shadow: 0 26px 60px -28px rgba(37, 99, 235, 0.55);
}
.siap-panel-pad { padding: 1.25rem; }
@media (min-width: 576px) { .siap-panel-pad { padding: 1.75rem; } }

/* ── Jendela (macOS dots) ── */
.siap-window {
  border-radius: var(--siap-radius-window);
  border: 1px solid rgba(255, 255, 255, 0.8);
  background-color: #fff;
  box-shadow: 0 2px 4px rgba(15, 23, 42, 0.04), 0 26px 52px -24px rgba(15, 23, 42, 0.18);
}
.siap-dots { display: flex; align-items: center; gap: 0.375rem; }
.siap-dots span { width: 0.625rem; height: 0.625rem; border-radius: 50%; }
.siap-dots .red   { background-color: #FF5F57; }
.siap-dots .yellow{ background-color: #FEBC2E; }
.siap-dots .green { background-color: #28C840; }

/* ── Action bar (mobile sticky bottom) ── */
.siap-action-bar {
  position: sticky;
  bottom: 0;
  z-index: 1030;
  border-top: 1px solid rgba(226, 232, 240, 0.5);
  background-color: rgba(243, 245, 248, 0.85);
  padding: 0.75rem 1rem;
  backdrop-filter: blur(12px);
}
@media (min-width: 992px) {
  .siap-action-bar {
    position: static;
    border: none;
    background: transparent;
    padding: 0;
    backdrop-filter: none;
  }
}

/* =========================================================================
   Akhir blok CSS SIAP
   ========================================================================= */
```

---

## Ringkasan Tema Desain SIPIKET

Tema utama SIPIKET adalah antarmuka **terang, modern, dan bersih** dengan latar abu-abu muda (`#F3F5F8`), aksen biru konsisten (`#3B82F6` / `#2563EB`), dan kartu putih dengan border halus serta bayangan berlapis yang memberi kesan mengambang. Tipografi menggunakan font sans-serif modern (Geist/Inter) untuk konten dan mono untuk label, dengan hierarki judul yang jelas serta tombol berbentuk pil (rounded-pill) dan ambang sentuh 44px yang ramah layar sentuh.

---

Dokumen desain.md berhasil dibuat berdasarkan referensi aplikasi SIPIKET. Silakan periksa isinya. Jika panduan desain sudah sesuai, ketik 'Lanjut' dan sebutkan halaman SIAP mana yang ingin dirombak terlebih dahulu (misalnya beranda, halaman login, atau dashboard admin).