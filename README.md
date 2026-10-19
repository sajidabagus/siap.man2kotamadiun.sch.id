# SIAP — Sistem Presensi Siswa Berbasis QR Code + Device Binding

Aplikasi presensi sekolah berbasis **PHP Native (tanpa framework)**, MySQL/MariaDB,
Bootstrap 5, dan QR Code, dilengkapi **Device Binding (1 Siswa = 1 Perangkat)**
untuk mencegah titip presensi. Berjalan di browser HP maupun di aplikasi Android
pembungkus WebView (`SIAPApp`).

**Terakhir diperbarui:** 16 Oktober 2026

---

## 1. RINGKASAN ALUR PRESENSI (QR Gerbang)

1. Admin menampilkan **QR Gerbang** (`panel/qr_gerbang.php`) di layar/monitor sekolah.
   QR berganti otomatis (QR dinamis, default 120 detik).
2. Siswa membuka halaman depan situs (atau aplikasi SIAP) di HP masing-masing.
3. Siswa men-scan QR gerbang yang tampil di layar sekolah (bukti berada di lokasi).
4. Sistem mengenali siswa dari **perangkat yang sudah terdaftar**. Jika cocok, siswa
   langsung masuk ke halaman konfirmasi.
5. Jika perangkat belum terdaftar, siswa mendaftarkan perangkat (lihat bagian 8).
6. Siswa menekan **KONFIRMASI PRESENSI**.
7. Sistem menyimpan presensi dengan jam & tanggal dari **server**, bukan dari HP siswa.

Presensi dari HP yang tidak terdaftar ditolak dan dicatat di Log Keamanan.

---

## 2. FITUR UTAMA

**Halaman publik (tanpa login)**
- Beranda: ringkasan kehadiran hari ini dan riwayat realtime. Perangkat belum dikenali
  melihat nama yang disamarkan ("Abhista K. A."); perangkat terdaftar melihat data
  kelasnya sendiri.
- Presensi, konfirmasi, dan pendaftaran perangkat (`/presensi/`).

**Panel admin / operator**
- Dashboard, Papan Kehadiran Langsung (`papan`), Data Siswa (detail, tambah, ubah, hapus, import Excel/CSV).
- Master Kelas dan Kenaikan Kelas.
- QR Siswa, QR Kelas, QR Gerbang (lihat, cetak, regenerate).
- Presensi harian (ekspor & cetak), Rekap bulanan (cetak).
- Manajemen Perangkat, Kode/QR Pasangan, Kode Pasangan Massal, Reset Massal.
- Log Keamanan, Log Aktivitas, Log Cetak.
- Pengaturan, Pengguna, dan **Hak Akses per role** (`admin` = semua izin;
  `operator` diatur lewat menu Hak Akses).

---

## 3. STRUKTUR FOLDER

```
├── index.php            → router URL bersih (/siswa, /absensi, ...) + beranda publik
├── beranda.php, login.php, logout.php
├── config/database.php
├── panel/               → halaman panel admin & operator
├── presensi/            → halaman publik siswa (scan, konfirmasi, daftar_hp, berhasil)
├── api/                 → endpoint JSON: scan, presensi, pasangan, cek_perangkat, beranda
├── includes/            → auth, functions, device_binding, presensi_core, qr_dinamis,
│                          keamanan, xlsx_reader, header, footer
├── assets/              → css, js (device.js, app.js), vendor (bootstrap, qrcode, html5-qrcode)
├── database/            → database.sql + file migrasi
├── uploads/             → foto siswa (PHP dinonaktifkan di folder ini)
├── .well-known/assetlinks.json   → verifikasi aplikasi Android
└── 403.html, 404.html, 502.html
```

---

## 4. INSTALASI — XAMPP (Lokal)

1. Salin folder aplikasi ke `C:\xampp\htdocs\siap\`.
2. Jalankan **Apache** dan **MySQL** dari XAMPP.
3. Buka `http://localhost/phpmyadmin`, buat database kosong (mis. `siapabsen`).
4. Pilih database itu → **Import** → pilih `database/database.sql` → **Go**.
   (Membuat seluruh tabel, akun admin default, dan pengaturan awal. Tanpa data siswa dummy.)
5. Sesuaikan `config/database.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'siapabsen');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('BASE_URL', 'http://localhost/siap');
   ```
6. Buka `http://localhost/siap/login.php` dan login dengan akun default (bagian 7).

**Kamera & lokasi:** browser mengizinkan kamera/GPS di `http://localhost`. Untuk uji
dari HP lain gunakan tunnel HTTPS (mis. `ngrok`), karena kamera & GPS mewajibkan HTTPS.

---

## 5. INSTALASI — aaPanel (Hosting/VPS Produksi)

1. **Buat Website** di aaPanel (domain sekolah, PHP **8.1/8.2**).
2. **Buat Database**, catat nama, user, dan password.
3. **Upload source** ke `/www/wwwroot/<domain-anda>/` lalu ekstrak.
4. **Konfigurasi** `config/database.php` (kredensial DB) dan set `BASE_URL` ke domain HTTPS,
   mis. `https://siap.man2kotamadiun.sch.id`. Import `database/database.sql` lewat phpMyAdmin.
5. **Permission** folder `uploads/`:
   ```
   chown -R www:www /www/wwwroot/<domain-anda>/uploads
   chmod -R 755 /www/wwwroot/<domain-anda>/uploads
   ```
6. **PHP**: aktifkan `pdo_mysql`, `fileinfo`, `mbstring`, `gd` (opsional);
   `upload_max_filesize` dan `post_max_size` minimal `4M`.
7. **open_basedir**: file `.user.ini` membatasi PHP ke folder situs dan `/tmp/`.
   **Ubah path di `.user.ini` jika domain/folder Anda berbeda**, atau aplikasi akan error.
8. **SSL/HTTPS (WAJIB)**: aktifkan Let's Encrypt + Force HTTPS. Kamera dan Geolocation
   tidak berjalan di halaman non-HTTPS.
9. **URL bersih**: `.htaccess` menyembunyikan `.php` dari address bar (butuh `mod_rewrite`).

---

## 6. URL BERSIH (ROUTING)

Seluruh halaman panel memakai URL bersih tanpa `.php` dan tanpa `?modul=`.
Semua dilayani `index.php` (front controller) lewat aturan di `.htaccess`.

| Halaman | URL |
|---|---|
| Dashboard | `/dashboard` |
| Daftar siswa | `/siswa` — filter: `/siswa?q=sajida&kelas=X%20B`, `/siswa?kelas=X%20B&limit=20` |
| Detail siswa | `/siswa/2026001` |
| Tambah siswa | `/siswa/tambah` |
| Edit siswa | `/siswa/2026001/edit` |
| QR Code siswa | `/siswa/2026001/qr` |
| Import / hapus siswa | `/siswa/import`, `/siswa/hapus` (POST) |
| Data kelas | `/kelas` · kenaikan kelas: `/kelas/kenaikan` |
| Riwayat absensi | `/absensi` · ekspor `/absensi/ekspor?type=csv` · cetak `/absensi/cetak` |
| Rekap | `/rekap` · cetak `/rekap/cetak` |
| Papan langsung | `/papan` |
| Perangkat | `/perangkat` · kode pasangan `/perangkat/kode?siswa_id=…` · massal `/perangkat/kode-massal` |
| QR | `/qr/siswa` · `/qr/siswa/cetak` · `/qr/kelas` · `/qr/kelas/cetak` · `/qr/gerbang` |
| Log | `/log/keamanan` · `/log/aktivitas` · `/log/cetak` |
| Pengaturan | `/pengaturan` |
| Pengguna & hak akses | `/pengguna` · `/pengguna/hak-akses` · profil `/profil` |
| Beranda publik, login | `/` · `/login` |
| Presensi siswa (publik) | `/presensi/` (tidak berubah, QR kartu siswa tetap berlaku) |

**Aturan penting**
- `{nis}` pada URL adalah **NIS siswa**, bukan id database. Jika NIS diubah, URL siswa itu ikut berubah.
- Jumlah baris per halaman memakai parameter **`limit`** (pilihan 10/20/50/100). Nama lama `per_page` masih diterima.
- URL lama (`index.php?modul=siswa&...`, `/panel/siswa.php`) otomatis dialihkan (301) ke URL bersih, jadi bookmark lama tidak rusak.
- Peta rute ada di satu tempat: konstanta `RUTE_MODUL` di `includes/functions.php`. Untuk membuat tautan **selalu**
  pakai `modul_url('nama_modul', ['param' => 'nilai'])`; jangan menempelkan `&param=...` secara manual.
- Jangan membuat rute berawalan nama folder nyata (`api`, `assets`, `config`, `includes`, `panel`, `presensi`, `uploads`, `database`).
- Server **Apache/LiteSpeed** (aaPanel default) cukup memakai `.htaccess` bawaan. Untuk **Nginx**, tambahkan:
  ```nginx
  location / { try_files $uri $uri/ $uri.php /index.php?$query_string; }
  ```

---

## 7. AKUN & DATA DEFAULT

- **Admin:** `admin` / `admin123` — ⚠️ **ganti segera** setelah login pertama (menu Profil).
- Tidak ada data siswa bawaan; tambahkan lewat Data Siswa atau Import.
- **Pengaturan awal:** jam masuk `07:00`, batas terlambat `07:15`, GPS nonaktif,
  validasi IP nonaktif, Device Binding **aktif**, QR gerbang 120 detik,
  maksimal reset perangkat 2x/bulan per siswa, hari efektif Senin–Jumat.
- Retensi Log Keamanan bisa diatur di Pengaturan (0 = tidak pernah dihapus otomatis).

---

## 8. DEVICE BINDING (Poin Penting)

**Identitas perangkat**
- Browser/WebView membuat **UUID acak** sekali, disimpan di `localStorage` **dan cookie**
  (400 hari) sebagai cadangan; salah satu yang hilang dipulihkan dari yang lain
  (`assets/js/device.js`).
- Server menyimpan **hash SHA-256** dari UUID (`device_hash` = `sha256('v2|' + uuid)`),
  dibandingkan saat presensi.
- **User-Agent TIDAK ikut di-hash.** UA berubah setiap Chrome/Android System WebView
  ter-update (mis. `154.0.8037.106` → `.107`); jika ikut di-hash, perangkat sah
  akan dianggap "belum terdaftar". Skema lama (v1 = UUID + UA) dimigrasikan otomatis.

**Aturan**
- 1 siswa = 1 perangkat (`UNIQUE(siswa_id)`), dan 1 perangkat = 1 siswa (`UNIQUE(device_hash)`).
- Perangkat baru didaftarkan lewat salah satu cara (dibuat admin, sekali pakai):
  1. **QR Pasangan** — siswa scan QR dari admin (`presensi/daftar_hp.php`).
  2. **Kode Pasangan 6 angka** — diketik siswa saat konfirmasi presensi.
- Berlaku 10 menit (pembuatan massal bisa tanpa batas waktu), terkunci setelah 5 kali salah.
  Yang tersimpan di database hanya hash.
- Pengaturan **"Wajib Kode Pasangan"** (default **aktif** sejak 18-10-2026, GLM #4): perangkat
  baru tidak lagi didaftarkan otomatis — wajib QR/Kode Pasangan buatan admin. Hanya admin yang
  menyetelnya ke nonaktif di Pengaturan yang mematikannya. Notifikasi in-app: antrean
  "anomali perangkat" dan percobaan kode gagal (>= 5x/24 jam) muncul di Dashboard agar
  dapat segera ditinjau lewat Log Keamanan.
- Admin dapat **memblokir**, **mengaktifkan kembali**, atau **mereset** perangkat
  (satu per satu atau massal per kelas/jenjang/pilihan). Reset individual dibatasi per bulan;
  reset massal tidak menghabiskan jatah tersebut.
- Semua kejadian (match, mismatch, blokir, reset, pairing gagal, presensi sukses/ditolak)
  tercatat di `security_logs`.

**Migrasi dari versi lama**
Jalankan `database/migration_device_hash_v2.sql` sekali (abaikan "Duplicate key name").
Siswa yang sudah terdaftar **tidak perlu daftar ulang**. Baris tanpa `device_uuid`
(seharusnya tidak ada) perlu direset lalu didaftarkan ulang.

**Keterbatasan (jujur)**
Ini bukan hardware ID permanen. Perangkat dianggap baru bila siswa menghapus data aplikasi/browser
(localStorage **dan** cookie), memakai incognito, ganti browser, atau ganti HP. Untuk keamanan
lebih kuat, gabungkan dengan QR dinamis gerbang, GPS/geofencing, dan validasi IP.

---

## 9. LIBRARY — DI-BUNDLE LOKAL (TANPA CDN)

`qrcode.js` (generate) dan `html5-qrcode.min.js` (scan kamera) sudah ada di
`assets/js/vendor/`, begitu juga Bootstrap CSS + JS (`bootstrap.min.css`,
`bootstrap.bundle.min.js`), Bootstrap Icons (`bootstrap-icons.min.css` + font),
Chart.js (`chart.umd.min.js`), dan font Montserrat (subset latin, di
`assets/css/vendor/montserrat.css` + `fonts/montserrat-latin.woff2`). **Tidak ada
ketergantungan CDN sama sekali**, sehingga tetap berfungsi bila jaringan sekolah
membatasi CDN atau server tanpa internet — sekaligus menyederhanakan CSP ketat
(`default-src 'self'`) di masa depan. Untuk memperbarui, cukup ganti file di
folder `vendor/`.

---

## 10. KEAMANAN YANG DITERAPKAN

- PDO Prepared Statements di seluruh query.
- `password_hash()` / `password_verify()` untuk akun.
- CSRF token pada seluruh form admin.
- Validasi & sanitasi input; `htmlspecialchars()` pada output.
- Upload foto: cek MIME asli (`finfo`), batas 2MB, nama file di-generate ulang.
- `uploads/` tidak dapat mengeksekusi PHP; `config/`, `includes/`, `database/` dilindungi `.htaccess`;
  file `.sql` dan `.md` tidak dapat diakses dari browser.
- **Izin per role ditegakkan di `require_login()`** (`includes/auth.php`) untuk semua jalur
  (router maupun akses langsung ke `/panel/xxx.php`).
- **Pembatas login:** 5 gagal per username atau 20 gagal per IP dalam 15 menit
  mengunci login sementara (tabel `login_attempts`, dibuat otomatis).
- **Rate limit API dua lapis:** perangkat (ketat) + IP (longgar) — presensi & presensi kilat
  6/menit/perangkat + 300/menit/IP; scan 20/menit/perangkat + 600/menit/IP. Memakai APCu bila
  tersedia, atau fallback penghitung berkas di `var/cache/ratelimit` (otomatis, tanpa konfigurasi).
  **Di belakang reverse proxy (Cloudflare, aaPanel/Nginx proxy) WAJIB mengisi `TRUSTED_PROXIES`
  di `config/database.php`** (contoh Nginx satu mesin: `define('TRUSTED_PROXIES', '127.0.0.1, ::1');`).
  Isi dengan **IP persis** (dipisah koma), bukan rentang CIDR — pencocokan memakai `in_array`.
  Bila lupa, `get_client_ip()` melihat IP proxy yang sama untuk seluruh sekolah: lapis per-IP
  menjadi satu ember global dan kolom `ip_address` di log tidak lagi bermakna.
- **Header keamanan & cookie sesi** (`HttpOnly`, `SameSite=Lax`, `Secure` di HTTPS, strict mode,
  regenerasi ID sesi berkala) diatur di `includes/keamanan.php`. CSP belum dipasang karena
  aplikasi memakai script/style inline.
- Semua validasi krusial (token QR, device binding, jam, presensi ganda, geofence) dilakukan
  **di server**. Jam & tanggal presensi selalu dari waktu server.
- Hapus siswa hanya lewat POST + token CSRF.
- Ganti password `admin` bawaan dan password database sebelum dipakai produksi.
