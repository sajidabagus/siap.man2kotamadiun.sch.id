# SIAP — QR Code Student Attendance (PHP native, no framework)

PHP native (no framework, no Composer/npm) + MySQL/MariaDB attendance app with QR Code + device binding.
Runs under Apache/LiteSpeed (XAMPP locally, aaPanel in production). Verify changes by loading pages in the browser — there is no build, lint, or test tooling.

## Setup (local)
1. Copy repo to a docroot (e.g. `C:\xampp\htdocs\siap\`). Start Apache + MySQL in XAMPP.
2. In phpMyAdmin: create empty DB (e.g. `siapabsen`), import `database/database.sql`.
3. Edit `config/database.php`: DB_HOST/DB_NAME/DB_USER/DB_PASS and `BASE_URL` (local `http://localhost/…`, production HTTPS domain). It sets `date_default_timezone_set('Asia/Jakarta')` — keep it consistent with the server; attendance validation uses server time.
4. Open `<BASE_URL>/login.php`. Default admin: `admin` / `admin123` — change immediately after first login.

## Entrypoints
- `index.php` — front controller / clean-URL router. Route map is the `RUTE_MODUL` constant in `includes/functions.php`.
- `panel/*.php` — admin/operator pages (31 modules: siswa, kelas, QR, presensi, rekap, perangkat, logs, pengaturan, pengguna, hak akses, …). Each starts with `require_once ../includes/header.php` → `require_login()`, which enforces session + role permission.
- `presensi/*.php` — public student pages (scan, konfirmasi, daftar_hp, berhasil), no login.
- `api/*.php` — JSON endpoints: scan, presensi, pasangan, cek_perangkat, beranda, quick.
- `includes/` — `auth.php` (session, `can()`, `can_modul()`, `require_login()`), `functions.php` (`modul_url()`, `e()`, `getDB()`, settings cache), `keamanan.php` (security headers/cookies), `device_binding.php`, `presensi_core.php`, `qr_dinamis.php`, `ratelimit.php`, `xlsx_reader.php`.
- `database/database.sql` — full schema + default admin account.

## Routing rules (easy to get wrong)
- Generate panel links **only** with `modul_url('nama_modul', ['param' => 'nilai'])` — never manual `?param=` or `&x=1`.
- `{nis}` in URLs is the student **NIS**, not the database `id`. URLs change if NIS changes.
- Pagination param is `limit` (10/20/50/100); legacy `per_page` still accepted.
- Never create routes starting with real folder names: `api`, `assets`, `config`, `includes`, `panel`, `presensi`, `uploads`, `database`.
- Old `index.php?modul=...` and `/panel/x.php` GET URLs 301-redirect to clean URLs. POST is never redirected.
- Permission checks run in both `index.php` (`MODUL_IZIN`) and `includes/auth.php` (`require_login()`); direct `/panel/x.php` access is guarded too.

## Security conventions (mandatory)
- All DB queries: PDO prepared statements via `getDB()` (`ATTR_EMULATE_PREPARES => false`). Never interpolate variables into SQL.
- Every admin form: `csrf_field()` + `csrf_require()` on POST.
- Output escaping: `e($value)` = `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- Device hash: `sha256('v2|' + uuid)` — **never** include User-Agent (changes on WebView updates and breaks binding). Hash v3 adds an HMAC secret when the client sends one.
- Attendance time is always **server time** (`Asia/Jakarta`; MySQL session `+07:00` in `getDB()`). Never trust device time/IP for critical validation.
- Behind a reverse proxy (Cloudflare, aaPanel Nginx): set `TRUSTED_PROXIES` in `config/database.php` to exact proxy IPs (comma-separated, no CIDR), or per-IP rate limits collapse to one global bucket and spoofed XFF headers break login limits. Leave empty for XAMPP/localhost.

## Database gotchas
- Settings table auto-migrates new columns on first read; call `hapus_cache_settings()` after writing settings (cache TTL 60s; file `var/cache/settings.json`, APCu if available).
- Rate-limit counters fall back to files in `var/cache/ratelimit/` when APCu is missing — no config needed.
- Run `database/migrasi_device_wajib_kode.sql` manually on older installs to add `device_wajib_kode` (ignore "Duplicate column name").
- Login attempt limits: 5 failures per username or 20 per IP per 15 minutes (table `login_attempts` auto-created).

## Files & runtime gotchas
- `.user.ini` sets `open_basedir` to the site folder + `/tmp/`. **Update its path on deploy**, or PHP errors.
- `uploads/siswa/` stores student photos (max 2MB, MIME-checked via finfo, renamed). PHP execution is disabled there.
- No CDN: vendor JS/CSS (Bootstrap, qrcode.js, html5-qrcode, Chart.js, Montserrat) live in `assets/js/vendor/` and `assets/css/vendor/`. Update by replacing files there.
- Camera & GPS require HTTPS; test from another phone via HTTPS tunnel (ngrok).
- `sw.js` + `manifest.json` = PWA; `.well-known/assetlinks.json` = Android app verification (currently empty).

## Deploy (aaPanel)
- PHP 8.1/8.2 with `pdo_mysql`, `fileinfo`, `mbstring`, `gd` (optional); `upload_max_filesize`/`post_max_size` ≥ 4M.
- `.htaccess` handles clean URLs via `mod_rewrite`. For Nginx: `location / { try_files $uri $uri/ $uri.php /index.php?$query_string; }`
- Set `chown -R www:www uploads` and `chmod -R 755 uploads`; enable HTTPS (camera/GPS fail on HTTP).
- Set `BASE_URL` to the HTTPS domain and `TRUSTED_PROXIES` if behind Nginx/Cloudflare.
