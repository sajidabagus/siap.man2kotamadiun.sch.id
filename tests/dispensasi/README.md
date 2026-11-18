# Tes dispensasi — lingkungan disposable saja

Jalankan di **worker/container terisolasi**, bukan server produksi. Prasyarat: PHP CLI + pdo_mysql/mbstring/GD
(GD opsional untuk alur fallback), MariaDB lokal berjalan dengan root unix socket `/run/mysqld/mysqld.sock`,
Node.js, curl, Chromium dan Playwright. Tidak perlu kredensial/DB produksi.

```sh
npm install --prefix /tmp/siap-ui-tools --no-save playwright
NODE_PATH=/tmp/siap-ui-tools/node_modules python3 tests/dispensasi/run.py /tmp/dispensasi-results
```

Runner menyalin kode ke direktori sementara, **mengganti config dengan konfigurasi tes sintetis**, mengekstrak
hanya CREATE/ALTER dari dump schema repo (tidak INSERT/data), membuat DB nama acak `siap_dispensasi_<acak>_test`,
dan membersihkan app + DB saat selesai/gagal. Tidak menyalin uploads atau memanggil situs sekolah.
Database dibuat/dihapus melalui root socket lokal; jika tidak tersedia, siapkan worker/container MariaDB disposable.
Jangan menjalankan `integration.php`/router uji sebagai endpoint produksi; router hanya dibentuk dalam direktori sementara,
berjalan di loopback dan memeriksa `cli-server` + IP loopback.

Semua pengajuan baru wajib lampiran (izin/sakit termasuk satu hari, dispensasi foto saja).
Tes mencakup POST tanpa file tiap kategori, PNG/PDF izin/sakit, required UI serta approval data lama tanpa lampiran.

Cakupan: migrasi legacy/idempotent, validasi tanggal/durasi/kegiatan, konflik, hadir asli, approve/tolak/cabut,
Alpa full-day/multiday vs partial, agenda libur/cutoff/no-scan guard, upload multipart PHP nyata, ekstensi/MIME/ukuran/PDF,
rute siswa/petugas aktual + CSRF/ownership/anonymous guard, CSV dan history, serta UI mobile/desktop.
Screenshot memakai siswa/dokumen sintetis. Tes ini bukan uji kamera/GPS atau Android WebView di perangkat fisik,
bukan load/concurrency test, dan bukan verifikasi konfigurasi Apache/Nginx/aaPanel.

Regresi desktop PR #5 tetap bisa dijalankan terpisah:
`NODE_PATH=/tmp/siap-ui-tools/node_modules node tests/ui/siswa-desktop.cjs /tmp/desktop-results`.
