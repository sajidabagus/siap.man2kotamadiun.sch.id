# Regresi identitas perangkat & masuk otomatis

Jalankan pada **worker/container disposable**, bukan server produksi:

```sh
node tests/safari/unit.cjs
NODE_PATH=/tmp/siap-ui-tools/node_modules python3 tests/safari/run.py /tmp/safari-results
```

Prasyarat: Node, Python3, PHP CLI dengan PDO MySQL/mbstring/GD, MariaDB lokal root via socket
`/run/mysqld/mysqld.sock`, `curl`, Chromium `/usr/bin/chromium`, Playwright.
Playwright dipasang di luar repo: `npm install --prefix /tmp/siap-ui-tools playwright`.
Override lokasi Chromium dengan `CHROMIUM_PATH`.

Runner membuat DB acak `siap_safari_<acak>_test`, memuat **CREATE/ALTER saja** dari schema, lalu
membuat akun/perangkat sintetis; tidak mengimpor INSERT, config atau unggahan asli. App disalin ke
direktori temporer dan config diganti fixture. Database/app dihapus dalam `finally`.
`fixture.php` hanya disalin/dijalankan oleh runner dan menolak eksekusi HTTP.
Route kontrol `/_safari` di bawah `/portal` hanya dihasilkan di app temporer, bukan endpoint produksi.

## Cakupan
- Fungsi JS asli: sync fail-closed saat konflik; pilih cadangan server sebelum sinkronisasi,
  recovery, dua storage hilang/ditolak, kandidat ambigu, singleflight/cross-tab perubahan.
- Maksimal dua usaha otomatis per tahap, timeout termasuk pembacaan JSON, transport/5xx/429,
  JSON/schema invalid, penolakan kredensial, retry manual paralel dan hasil terlambat.
- HTTP PHP + DB: resolver generik tidak login/menulis cookie/mendaftarkan atau mereset binding,
  POST/Origin/input/dua ACTIVE/BLOCKED; auto-login tetap memeriksa akun/siswa/perangkat aktif,
  password sementara, idle 7200, sesi versi, session-cookie lifetime0, password aksi sensitif,
  logout CSRF/manual dan login password dari browser lain.
- Chromium 390/1366 menggunakan PHP gerbang/login/aktivasi asli dan fixture sintetis:
  retry/status, konflik nyata melalui endpoint, form password tetap dapat dipakai, pendaftaran
  belum tervalidasi ditahan, tidak overflow, keyboard focus.

Untuk before/after opsional, sediakan file **dari base commit** di worker, lalu:

```sh
SIAP_SAFARI_BEFORE_GATE=/tmp/safari-base-gerbang.php \
SIAP_SAFARI_BEFORE_LOGIN=/tmp/safari-base-login.php \
SIAP_SAFARI_BEFORE_DEVICE=/tmp/safari-base-device.js \
NODE_PATH=/tmp/siap-ui-tools/node_modules python3 tests/safari/run.py /tmp/safari-results
```

Screenshot hanya memakai data sintetis. `browser-results.json` menyimpan juga warning native
Chromium yang persis berbunyi “Transition was aborted because of invalid state. ViewTransition
opt-in disabled” ketika navigasi dialihkan ke landing sintetis; warning ini dipisah dari exception
aplikasi (yang tetap menggagalkan tes). Ini bukan emulasi kebijakan ITP atau tes iPhone fisik.
Jangan menganggap mengganti User-Agent Chromium dengan iPhone sebagai uji Safari.
