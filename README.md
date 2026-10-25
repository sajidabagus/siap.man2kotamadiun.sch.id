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
- **Portal Siswa** (`/portal`): siswa masuk dengan NISN lewat halaman login yang sama dengan petugas, untuk melihat kehadiran sendiri, **mengajukan izin/sakit**, dan menerima notifikasi keputusan petugas.

**Panel admin / operator**

- Dashboard, Papan Kehadiran Langsung (`papan`), Data Siswa (detail, tambah, ubah, hapus, import Excel/CSV).
- Master Kelas dan Kenaikan Kelas.
- QR Siswa, QR Kelas, QR Gerbang (lihat, cetak, regenerate).
- Presensi harian (ekspor & cetak), Rekap bulanan (cetak).
- Manajemen Perangkat dan Reset Massal. Kode untuk mendaftarkan perangkat kini satu dengan **kode aktivasi** di menu Akun Siswa.
- **Akun Siswa**: menerbitkan kode aktivasi, reset, dan blokir akun portal siswa (lihat bagian 8b).
- **Pengajuan Izin**: antrean persetujuan izin/sakit siswa untuk petugas piket (lihat bagian 8c).
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
├── portal_siswa/        → halaman portal siswa (aktivasi, beranda, izin, notifikasi, password); hanya lewat router
├── presensi/            → halaman publik siswa (scan, konfirmasi, berhasil)
├── api/                 → endpoint JSON: scan, presensi, cek_perangkat, beranda
├── includes/            → auth, functions, device_binding, presensi_core, qr_dinamis,
│                          keamanan, xlsx_reader, header, footer
├── assets/              → css, js (device.js, app.js), vendor (bootstrap, qrcode, html5-qrcode)
├── database/            → database.sql + file migrasi
├── uploads/             → foto siswa (PHP dinonaktifkan di folder ini)
├── .well-known/assetlinks.json   → verifikasi aplikasi Android
└── 403.html, 404.html, 502.html, 503.html (gangguan database)
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

| Halaman                  | URL                                                                                                                                                                             |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Dashboard                | `/dashboard`                                                                                                                                                                    |
| Daftar siswa             | `/siswa` — filter: `/siswa?q=sajida&kelas=X%20B`, `/siswa?kelas=X%20B&limit=20`                                                                                                 |
| Detail siswa             | `/siswa/2026001`                                                                                                                                                                |
| Tambah siswa             | `/siswa/tambah`                                                                                                                                                                 |
| Edit siswa               | `/siswa/2026001/edit`                                                                                                                                                           |
| QR Code siswa            | `/siswa/2026001/qr`                                                                                                                                                             |
| Import / hapus siswa     | `/siswa/import`, `/siswa/hapus` (POST)                                                                                                                                          |
| Data kelas               | `/kelas` · kenaikan kelas: `/kelas/kenaikan`                                                                                                                                    |
| Riwayat absensi          | `/absensi` · ekspor `/absensi/ekspor?type=csv` · cetak `/absensi/cetak`                                                                                                         |
| Rekap                    | `/rekap` · cetak `/rekap/cetak`                                                                                                                                                 |
| Papan langsung           | `/papan`                                                                                                                                                                        |
| Perangkat                | `/perangkat`                                                                                                                                                                    |
| QR                       | `/qr/siswa` · `/qr/siswa/cetak` · `/qr/kelas` · `/qr/kelas/cetak` · `/qr/gerbang`                                                                                               |
| Log                      | `/log/keamanan` · `/log/aktivitas` · `/log/cetak`                                                                                                                               |
| Pengaturan               | `/pengaturan`                                                                                                                                                                   |
| Akun siswa (admin)       | `/akun-siswa`                                                                                                                                                                   |
| Pengajuan izin (petugas) | `/izin` · tinjau `/izin/{id}` · lampiran `/izin/{id}/lampiran`                                                                                                                  |
| Portal siswa             | `/portal` · `/portal/aktivasi` · `/portal/password` · `/portal/izin` · `/portal/izin/baru` · `/portal/izin/{id}` · `/portal/notifikasi` (masuk lewat `/login`, lihat bagian 8b) |
| Pengguna & hak akses     | `/pengguna` · `/pengguna/hak-akses` · profil `/profil`                                                                                                                          |
| Beranda publik, login    | `/` · `/login`                                                                                                                                                                  |
| Presensi siswa (publik)  | `/presensi/` (tidak berubah, QR kartu siswa tetap berlaku)                                                                                                                      |

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
- Perangkat baru didaftarkan lewat salah satu cara (**kode pasangan dan QR pasangan sudah dihapus**; satu kode untuk semuanya):
  1. **Kode aktivasi akun portal** (menu _Akun Siswa_) — mendaftarkan akun _dan_ perangkat dalam satu tindakan, lalu siswa
     langsung masuk ke portal (lihat bagian 8b).
  2. **Tombol "Daftarkan perangkat ini"** di portal siswa, dari dalam aplikasi SIAP (hanya bila pendaftaran tidak diwajibkan kode).
  3. **Otomatis saat presensi pertama** (hanya bila pendaftaran tidak diwajibkan kode).
- Kode aktivasi berlaku 7 hari, terkunci setelah 5 kali salah. Yang tersimpan di database hanya hash.
- Perangkat hanya untuk **presensi**. Akun portal tidak terikat perangkat dan boleh dibuka di perangkat mana pun.
- Pengaturan **"Wajib kode aktivasi dari petugas"**: bila aktif, perangkat baru tidak didaftarkan otomatis maupun lewat tombol di
  portal; satu-satunya jalan adalah aktivasi dengan kode dari Akun Siswa. Ganti HP pada mode ini otomatis menerbitkan kode baru.
- Admin dapat **memblokir**, **mengaktifkan kembali**, atau **mereset** perangkat
  (satu per satu atau massal per kelas/jenjang/pilihan). Reset individual dibatasi per bulan;
  reset massal tidak menghabiskan jatah tersebut.
- Semua kejadian (match, mismatch, blokir, reset, pendaftaran gagal, presensi sukses/ditolak)
  tercatat di Log Keamanan (tabel `security_logs`).

**Migrasi dari versi lama**
Jalankan `database/migration_device_hash_v2.sql` sekali (abaikan "Duplicate key name").
Siswa yang sudah terdaftar **tidak perlu daftar ulang**. Baris tanpa `device_uuid`
(seharusnya tidak ada) perlu direset lalu didaftarkan ulang.

**Keterbatasan (jujur)**
Ini bukan hardware ID permanen. Perangkat dianggap baru bila siswa menghapus data aplikasi/browser
(localStorage **dan** cookie), memakai incognito, ganti browser, atau ganti HP. Untuk keamanan
lebih kuat, gabungkan dengan QR dinamis gerbang, GPS/geofencing, dan validasi IP.

---

## 8b. PORTAL SISWA (AKUN & KODE AKTIVASI)

Siswa dapat memiliki akun sendiri di `/portal` (username = **NISN**). Halaman masuknya **hanya satu**, yaitu
`/login` (kolom _Username / NISN_): sistem membedakan sendiri. Bila ada petugas dengan username itu, ia masuk sebagai
petugas; bila tidak, input dianggap NISN siswa dan diarahkan ke `/portal` (sesi terpisah). Bila username petugas
kebetulan sama dengan sebuah NISN, **petugas yang menang**, jadi hindari username petugas berupa angka.
Siswa **tidak mendaftar sendiri** dan
**admin tidak pernah membuat/mengetahui password**:

1. Admin membuka **Akun Siswa** → _Buat kode_ (satuan, dicentang, atau seluruh kelas/sekolah).
2. Sistem menampilkan **kode aktivasi 8 karakter** (mis. `K7M2-9QXA`) **sekali saja** — cetak kartunya atau unduh CSV.
   Yang tersimpan di database hanya hash (PBKDF2 + garam); kode berlaku **7 hari** dan hanya untuk satu siswa.
3. Siswa membuka `/portal/aktivasi`, mengisi NISN + kode, lalu **membuat password sendiri**
   (minimal 8 karakter, huruf + angka, tidak boleh sama dengan NISN/NIS atau password umum).
4. Lupa password / akun diambil alih → admin menekan **Reset**: password lama dihapus, semua sesi berhenti,
   siswa aktivasi ulang dengan kode baru.

**Aktivasi akun = daftar perangkat (satu langkah).** Saat siswa memasukkan kode aktivasi dan membuat password,
perangkat yang dipakai ikut didaftarkan untuk presensi, dengan aturan berikut:

- **Hanya dari dalam aplikasi SIAP** (User-Agent memuat `SIAPApp/`). Yang terdaftar adalah penyimpanan _aplikasi_; bila
  siswa mengaktifkan lewat Chrome, yang terdaftar Chrome, lalu presensi di aplikasi dianggap "perangkat tidak dikenal".
  Karena itu di browser biasa akun tetap dibuat tetapi perangkat **tidak** didaftarkan; siswa diberi petunjuk membuka
  aplikasi, masuk, lalu menekan **Daftarkan perangkat ini** (kartu _Perangkat presensi_ di beranda portal).
- Kotak **"Jadikan HP ini perangkat presensi saya"** (tercentang di aplikasi) bisa dilepas bila itu bukan HP siswa sendiri.
- Perangkat didaftarkan **dalam transaksi yang sama** dengan penetapan password dan hanya setelah kode terbukti benar dan
  password lolos syarat. Kode salah atau password lemah tidak mendaftarkan apa pun; galat server membatalkan seluruhnya
  sehingga kode belum terpakai.
- **Tidak pernah menimpa** perangkat yang sudah ada (siswa lama tetap memakai perangkatnya) dan tetap satu perangkat untuk
  satu siswa. Perangkat milik siswa lain / yang diblokir tidak didaftarkan; akun tetap aktif dan kejadiannya tercatat di
  Log Keamanan tanpa menyebut nama siswa lain di layar.
- Portal memeriksa kecocokan penyimpanan perangkat dan memberi peringatan bila siswa membuka dari Chrome padahal yang
  terdaftar aplikasi. Bila petugas mewajibkan **kode aktivasi**, tombol pendaftaran mandiri di portal dimatikan.
- **Ganti HP:** di Akun Siswa tekan **Ganti HP** (memakai kuota reset bulanan). Akun aktif (mode wajib kode mati): siswa
  mendaftarkan HP baru dari portal tanpa kode. Akun menunggu aktivasi, atau mode wajib kode aktif: perangkat direset sekaligus
  terbit kode aktivasi baru (password lama dihapus, siswa aktivasi ulang di aplikasi SIAP).
- QR kartu siswa tetap hanya untuk presensi; sengaja **tidak** dijadikan syarat aktivasi karena berupa token tetap yang
  mudah difoto atau dipinjam.

**Pemisahan dari admin**

- Sesi sendiri (cookie `SIAP_SISWA`, path `/portal`); cookie admin tidak dibaca portal dan sebaliknya.
- Tabel sendiri (`siswa_akun`), bukan tabel `users` → tidak ada jalan naik ke panel admin.
- Pembatas percobaan sendiri (`portal_attempts`) agar kegagalan siswa di Wi-Fi sekolah tidak mengunci login admin.

**Keamanan yang diterapkan**

- Gagal login dibatasi per NISN (5x / 15 menit) dan per IP (100x); salah kode aktivasi 5x mengunci 15 menit.
- Pesan galat sengaja umum, dan pesan serta waktu respons jalur petugas, siswa, dan username tak dikenal disamakan
  (terukur ±380 ms semua), supaya username petugas maupun NISN yang punya akun tidak bisa ditebak.
- Login siswa **bukan pengganti Device Binding**: presensi tetap lewat perangkat terdaftar.
- Akun otomatis tidak bisa dipakai bila siswa dinonaktifkan atau diblokir; sesi lama langsung berakhir.
- Mengganti password mengeluarkan perangkat lain. Keluar hanya lewat POST + token CSRF.
- Siswa **tanpa NISN atau dengan NISN ganda** tidak bisa dibuatkan akun (ditandai di daftar).
- Admin dapat menutup seluruh portal sementara lewat tombol _Tutup portal_.

**Izin (menu Hak Akses):** `akun_siswa.lihat` (melihat daftar) dan `akun_siswa.kelola` (menerbitkan kode, reset,
blokir, buka/tutup portal). Izin ini **tidak** diberikan otomatis ke Operator; berikan lewat Hak Akses.

Alamat lama `/portal/masuk` otomatis dialihkan ke `/login`. File `portal_siswa/masuk.php` dari versi sebelumnya sudah tidak dipakai dan boleh dihapus.

**Instalasi:** tabel dibuat otomatis saat pertama dipakai. Bila akun database tidak punya hak `CREATE`,
jalankan `database/migration_akun_siswa.sql` sekali.

**Batas yang perlu diketahui:** sesi PHP berakhir sesuai `session.gc_maxlifetime` server (biasanya ±24 menit tanpa
aktivitas), jadi siswa bisa diminta masuk ulang; dan penguncian per NISN berarti orang lain bisa membuat satu siswa
terkunci sementara dengan menebak password berulang (pemulihannya otomatis setelah 15 menit).

---

## 8c. PENGAJUAN IZIN / SAKIT & NOTIFIKASI

**Alur:** siswa mengajukan di `/portal/izin/baru` → petugas piket memutuskan di `/izin` → siswa mendapat
notifikasi di portal. Izin yang **disetujui** ditulis ke tabel `presensi` (status `IZIN`/`SAKIT`, metode
`PENGAJUAN`, tanpa jam masuk) untuk **hari efektif** dalam rentang, sehingga Data Presensi, Rekap, Dashboard, dan
ekspor yang sudah ada otomatis ikut tanpa diubah.

**Aturan pengajuan** (konstanta di `includes/izin.php`): mundur maksimal 3 hari, maju maksimal 30 hari; satu pengajuan
maksimal 14 hari; **sakit lebih dari 1 hari sekolah wajib melampirkan surat** (JPG/PNG/WEBP/PDF, maks. 3 MB); satu
pengajuan aktif per tanggal; maksimal 5 pengajuan menunggu sekaligus; siswa boleh membatalkan selama masih menunggu.

**Keputusan petugas:** _Setujui_ (catatan opsional), _Tolak_ (alasan **wajib**, dibaca siswa), _Cabut persetujuan_
(alasan wajib; hanya baris presensi `PENGAJUAN` yang dihapus, presensi kehadiran asli tidak pernah disentuh), dan
_Setujui yang dicentang_ untuk persetujuan massal. Semuanya tercatat di Log Aktivitas.

**Bila siswa yang izinnya disetujui tetap hadir:** presensi QR tetap diterima dan **HADIR yang berlaku**, baris izin
hari itu diganti, dan izin hari itu ditandai `dipakai_hadir`.

**Notifikasi:** siswa melihat lencana di menu **Notifikasi** dan banner di beranda (disetujui/ditolak/dicabut);
petugas melihat lencana jumlah di menu **Pengajuan Izin** dan pengingat di Dashboard. Notifikasi di dalam portal saja;
belum ada WhatsApp/email. Maksimal 100 notifikasi terbaru per siswa disimpan.

**Izin akses (Hak Akses):** `izin.lihat` (melihat pengajuan & lampiran) dan `izin.proses` (setujui/tolak/cabut,
hapus lampiran lama). **Tidak diberikan otomatis ke Operator**, jadi centang untuk petugas piket di menu Hak Akses.

**Lampiran = data kesehatan.** Disimpan di `uploads/izin/` dengan nama acak, **tidak bisa dibuka langsung dari web**
(`.htaccess` menolak; pada **Nginx** tambahkan `location ^~ /uploads/izin/ { deny all; }`), hanya lewat skrip yang
memeriksa hak akses: pemilik pengajuan dan petugas ber-izin `izin.lihat`. PDF dikirim sebagai unduhan, gambar
dengan `nosniff` + CSP. Setiap pembukaan lampiran oleh petugas dicatat di **Log Keamanan**
(`IZIN_LAMPIRAN_DILIHAT`). Tombol **Hapus lampiran lama** (halaman Pengajuan Izin) menghapus berkas dari
pengajuan yang sudah diproses lebih dari N hari (bawaan 90) — data pengajuannya tetap ada. Tentukan kebijakan
retensi bersama pihak madrasah.

**Instalasi:** tabel `izin_pengajuan`, `izin_hari`, `portal_notifikasi` dibuat otomatis saat pertama dipakai; bila
akun database tidak punya hak `CREATE`, jalankan `database/migration_izin.sql`. Pastikan `upload_max_filesize` dan
`post_max_size` minimal `4M` dan folder `uploads/` dapat ditulis server web.

---

## 9. LIBRARY QR CODE — DI-BUNDLE LOKAL

`qrcode.js` (generate) dan `html5-qrcode.min.js` (scan kamera) sudah ada di
`assets/js/vendor/`, begitu juga Bootstrap, Bootstrap Icons, dan font. Tidak ada
ketergantungan CDN, sehingga tetap berfungsi bila jaringan sekolah membatasi CDN atau
server tanpa internet. Untuk memperbarui, cukup ganti file di folder `vendor/`.

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
- **Header keamanan & cookie sesi** (`HttpOnly`, `SameSite=Lax`, `Secure` di HTTPS, strict mode,
  regenerasi ID sesi berkala) diatur di `includes/keamanan.php`. CSP belum dipasang karena
  aplikasi memakai script/style inline.
- Semua validasi krusial (token QR, device binding, jam, presensi ganda, geofence) dilakukan
  **di server**. Jam & tanggal presensi selalu dari waktu server.
- Hapus siswa hanya lewat POST + token CSRF.
- **Halaman gangguan database (503):** bila koneksi database gagal, pengguna melihat halaman rapi
  `503.html` (bukan teks polos), dengan tombol "Coba Lagi" dan percobaan ulang otomatis tiap 30 detik.
  Detail galat **tidak** ditampilkan; hanya _kode kejadian_ acak (mis. `DB-8D9316`) yang muncul di halaman
  dan **ditulis juga ke error log server**, sehingga laporan pengguna bisa dicocokkan dengan lognya.
  Permintaan API / AJAX dijawab JSON (`{"success":false,"code":"DB_UNAVAILABLE",...}`), dan popup formulir
  hanya menampilkan pesan singkat. Logikanya ada di `tampilkan_error_database()` di `config/database.php`.
- Ganti password `admin` bawaan dan password database sebelum dipakai produksi.
