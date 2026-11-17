# Regresi tampilan siswa (tanpa DB produksi)

Prasyarat: PHP CLI 8.1+ + mbstring, Node.js 18+, Chromium, Playwright.

```sh
npm install --prefix /tmp/siap-ui-tools --no-save playwright
NODE_PATH=/tmp/siap-ui-tools/node_modules node tests/ui/siswa-desktop.cjs /tmp/siap-ui-results
```

Gunakan `CHROMIUM_PATH=/path/to/chromium` bila browser bukan `/usr/bin/chromium`.
Tidak ada dependency runtime aplikasi yang ditambahkan.

- `render-siswa.php` hanya dapat dijalankan lewat CLI; memakai templat **beranda.php aktual** dan
  kerangka `_layout.php` aktual untuk Riwayat/Izin/Profil. Bukan isi/form/alur server halaman tersebut.
- Seluruh request browser di-intercept; fixture 40 siswa sintetis dan respons API hanya untuk tes.
  Tidak memuat konfigurasi DB, tidak login, tidak menulis DB atau memanggil server sekolah.
- Enam viewport: 1440×900, 1366×768, 1024×768, 1024×600, 768×1024, 390×844.
- Memastikan baris terakhir daftar kelas terjangkau, nilai ringkasan mengikuti respons API,
  label kartu tidak tertimpa dekorasi, sidebar/nav bawah sesuai breakpoint, tidak ada overflow horizontal,
  tidak ada exception JS pada Home, menu aktif serta tombol keluar di kerangka tetap terjangkau.
- Menyimpan screenshot dan `checks.json` di direktori output. Jam browser dibuat tetap agar tinjauan mudah.
- Tidak menggantikan tes MySQL, login, pengajuan izin, unggah foto/surat, kamera/GPS, dan Android WebView
  pada lingkungan staging. Halaman scan hanya tercakup lint PHP, bukan kamera/konfirmasi end-to-end.
