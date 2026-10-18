# Project knowledge

## What this is
**SIAP** — Sistem Presensi Siswa (student attendance system) based on QR Code + Device Binding.
PHP native (no framework), MySQL/MariaDB, Bootstrap 5, QR Code. Runs in mobile browsers and
an Android WebView wrapper (`SIAPApp`). Key anti-cheat feature: 1 siswa = 1 perangkat (device binding)
to prevent proxy attendance ("titip presensi").

## Quickstart (XAMPP local)
- Copy app to `C:\xampp\htdocs\siap\`, start Apache + MySQL.
- Create empty DB (e.g. `siapabsen`) in phpMyAdmin, import `database/database.sql`.
- Configure `config/database.php` (DB_HOST/DB_NAME/DB_USER/DB_PASS, `BASE_URL` = `http://localhost/siap`).
- Login: `http://localhost/siap/login.php` with `admin` / `admin123` (⚠️ change immediately).
- No build/lint/test tooling exists — plain PHP. Verify changes by loading pages in the browser.
- Camera & GPS require HTTPS; test from another device via HTTPS tunnel (e.g. ngrok).

## Architecture
- `index.php` — front controller: clean-URL router + public beranda. Route map is the
  `RUTE_MODUL` constant in `includes/functions.php`; generate links with `modul_url('modul', [params])`, never manual `?param=`.
- `panel/` — admin/operator pages (siswa CRUD/import, kelas, QR, rekap, logs, pengaturan, pengguna, hak akses).
- `presensi/` — public student pages (scan QR gerbang, konfirmasi, daftar_hp, berhasil).
- `api/` — JSON endpoints: scan, presensi, pasangan, cek_perangkat, beranda.
- `includes/` — `auth.php` (login/session, role permissions enforced in `require_login()`),
  `device_binding.php`, `presensi_core.php`, `qr_dinamis.php`, `keamanan.php` (security headers/CSRF),
  `functions.php`, `xlsx_reader.php`, header/footer.
- `assets/js/` — `device.js` (device UUID in localStorage + cookie, 400-day backup), `app.js`;
  vendor libs (bootstrap, qrcode.js, html5-qrcode) bundled locally in `assets/js/vendor/` — no CDN.
- `database/` — `database.sql` (full schema + default admin + initial settings) + migration files
  (e.g. `migration_device_hash_v2.sql` — run once, ignore "Duplicate key name").
- `uploads/` — student photos; PHP execution disabled there.

## Data flow (presensi)
Admin shows dynamic QR Gerbang (`/qr/gerbang`, rotates every 120s) → student scans on their
registered device → device_hash (sha256 of `'v2|' + uuid` from localStorage/cookie) matched server-side →
konfirmasi → attendance saved with **server time**, never device time. Unregistered-device attempts
are rejected and logged in `security_logs`. Pairing methods: QR Pasangan or 6-digit kode pasangan
(10 min TTL, locked after 5 wrong tries, only hashes stored).

## Conventions & gotchas
- All queries must use PDO prepared statements; all admin forms need CSRF tokens; output escaped with `htmlspecialchars()`.
- `{nis}` in URLs is the student **NIS**, not DB id — URLs change if NIS changes.
- Pagination param is `limit` (10/20/50/100); legacy `per_page` still accepted.
- Never create routes starting with real folder names (`api`, `assets`, `config`, `includes`, `panel`, `presensi`, `uploads`, `database`).
- Old URLs (`?modul=...`, `/panel/x.php`) 301-redirect to clean URLs; keep that behavior for GET (POST never redirected).
- `User-Agent` must NOT be part of the device hash (UA changes on WebView updates); hash scheme is v2 = sha256(UUID), with automatic migration from v1.
- Core validations (QR token, device binding, jam masuk/terlambat, double-presence, geofence) are **server-side**; jam masuk `07:00`, batas terlambat `07:15` by default (configurable in Pengaturan).
- Roles: `admin` (all permissions) vs `operator` (per hak-akses menu); enforced in `includes/auth.php` for both router and direct `/panel/x.php` access.
- `.user.ini` sets `open_basedir` to the site folder + `/tmp/` — update the path if deploying to a different folder/domain, or the app errors.
- Deployment server is aaPanel/LiteSpeed with `.htaccess` (needs mod_rewrite); for Nginx use `try_files $uri $uri/ $uri.php /index.php?$query_string;`.
- Petunjuk: dokumentasi lengkap install & alur ada di `README.md` (bahasa Indonesia).
