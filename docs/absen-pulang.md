# Absen datang dan pulang

## Aturan
- Sesi ditentukan **jam server saat konfirmasi/simpan**, bukan pilihan atau jam dari klien. Token QR, device binding, status siswa, kelas QR dan GPS existing tetap diperiksa untuk kedua sesi.
- Datang: `buka <= jam < tutup`. Alpa otomatis tetap mengikuti **tutup datang**, default existing 10:00, bukan tutup pulang. Pengaman hari tanpa scan/kalender/dispensasi existing tidak berubah.
- Terlambat opsional: kosong = semua scan datang dalam jendela berstatus HADIR. Tepat di batas `HH:MM:00` masih HADIR; sesudahnya TERLAMBAT. Tidak berarti seluruh menit berikutnya diberi toleransi.
- Pulang: `buka <= jam < tutup`, buka harus **lebih besar** dari tutup datang; tidak mendukung lintas tengah malam.
- Pulang memerlukan catatan HADIR/TERLAMBAT hari yang sama dengan jam datang nyata. Tidak membuat baris baru/mengubah jam/status/metode datang. Conditional UPDATE pada `jam_pulang IS NULL` menolak duplikasi.
- Izin/sakit menunggu/disetujui, dispensasi penuh disetujui, Alpa, tidak datang, hari libur, perangkat diblokir atau GPS gagal tidak bisa pulang.
- Dispensasi per jam tidak menggantikan scan datang/pulang dan tidak membuka hak pulang dini di luar jendela. Tidak mengubah ketentuan Alpa dispensasi.
- Tidak scan pulang **tidak otomatis Alpa** atau mengurangi hitungan HADIR. Jam pulang lama kosong tetap NULL, bukan diisi tebakan. Jumlah pulang bulanan terpisah dari status kehadiran.
- Tidak mengirim notifikasi inbox untuk setiap scan harian (menghindari spam); hasil scan dan Log Keamanan existing mencatat keberhasilan pulang.

## Agenda Sekolah
Agenda tetap dapat mengganti jam datang per kelas/hari; pulang memakai pengaturan global. Jika tutup datang agenda >= buka pulang, jendela pulang kelas/tanggal itu dinonaktifkan fail-closed, bukan menggeser jam/menebak sesi. Periksa agenda dan atur jadwal yang tidak beririsan sebelum rilis. Libur/agenda pembukaan hari non-efektif tetap mengikuti alur existing.

## Migrasi/rilis
1. Backup database dan source, uji di staging dengan PHP/PDO MySQL yang sama seperti produksi. Jangan import ulang `database.sql` untuk instalasi existing (dump dapat berisi data awal).
2. Dengan config DB staging/produksi yang sudah benar dan hak SELECT/ALTER:
   ```sh
   php -l database/migrate_absen_pulang.php
   php database/migrate_absen_pulang.php --check  # exit1 + petunjuk bila kolom belum ada; tidak menulis
   php database/migrate_absen_pulang.php
   php database/migrate_absen_pulang.php --check  # exit0 setelah lengkap
   ```
   CLI-only, additive dan idempoten: `presensi.jam_pulang TIME NULL`; `settings.pulang_buka`, `pulang_tutup`, `terlambat_aktif`. Tidak mengubah data datang lama, ENUM status atau jenis pengajuan.
3. Migrasikan dahulu dalam maintenance window, kemudian rilis seluruh PHP/helper/aset baru bersamaan. Kode lama mengabaikan kolom baru; rollback kode tidak perlu DROP kolom/data.
4. Pengaturan → Absensi → **Jam absensi**: pastikan datang existing dan default pulang **13:00–16:00** sesuai sekolah; simpan. Jangan menyalin jam contoh screenshot tanpa pemeriksaan. Kosongkan “Terlambat setelah” hanya jika ingin menonaktifkan status terlambat.
5. Uji QR nyata, perangkat terdaftar, GPS, datang/pulang dan ekspor di staging, kemudian produksi dengan akun uji yang diizinkan. Tidak ada deployment otomatis oleh agen.

## UI / design notes
App surface operasional: urutan tugas adalah set jendela datang → pulang → cek linimasa/ringkasan → simpan form existing. Arah tenang/teknis, mempertahankan Bootstrap, sidebar dan kartu portal existing, tidak melakukan redesign.
Referensi: gambar “Jam absensi” pengguna untuk hirarki dua panel/linimasa, UI SIAP existing untuk font/kartu/aksi, prinsip Material/HIG untuk fokus dan target sentuh.
Pilihan: dua panel netral 20px gap, mobile satu kolom; kartu 16px radius borderless, panel12px; font13/14/16/20px; input44px; biru aksen pulang, hijau tepat waktu, amber terlambat, arsir hanya untuk area ditolak. Garis waktu menyesuaikan rentang di luar 05–17 bila input memerlukannya. Field teks berpola JJ:MM menampilkan24jam secara eksplisit (bukan AM/PM picker OS); keyboard numerik1300 otomatis menjadi13:00. Validasi JS dan server menolak jam di luar00:00–23:59.
Review visual pertama menemukan tick mobile meluber dan caption bulanan terlalu dominan: tick diposisikan pada persentase sebenarnya, label sempit memakai legenda, caption diperkecil. Screenshot final diambil setelah revisi, tanpa selection/focus di input dan memakai locale Indonesia.

## Tes di worker disposable
```sh
NODE_PATH=/tmp/siap-ui-tools/node_modules python3 tests/pulang/run.py /tmp/pulang-results
NODE_PATH=/tmp/siap-ui-tools/node_modules python3 tests/dispensasi/run.py /tmp/pulang-dispensasi-results
NODE_PATH=/tmp/siap-ui-tools/node_modules node tests/ui/siswa-desktop.cjs /tmp/pulang-desktop-results
node tests/safari/unit.cjs
NODE_PATH=/tmp/siap-ui-tools/node_modules python3 tests/safari/run.py /tmp/pulang-safari-results
```
PHP CLI dengan pdo_mysql/mbstring/gd, MariaDB lokal/socket `/run/mysqld/mysqld.sock` (root tanpa password hanya pada worker disposable), Node dan Chromium/Playwright diperlukan. Runner membuat DB acak `_test`, menyalin source tanpa config/uploads asli, hanya membaca CREATE/ALTER schema dari dump, membuat fixture sintetis, lalu menghapus DB/app di finally. Tes jam HTTP memakai jam nyata; jalankan antara00:20–23:20 Asia/Jakarta. Tes boundary tepat detik memakai fungsi produksi dengan argumen waktu eksplisit, bukan memalsukan jam produksi.
Before/after opsional: `SIAP_PULANG_BEFORE=/path/dir` berisi `pengaturan.php` dan `beranda.php` base. Jangan commit config/data produksi sebagai fixture.

## Batas
Chromium390/1366 bukan uji Safari/iPhone/Android WebView fisik, kamera QR/GPS fisik, stress koneksi/concurrency produksi, aaPanel atau konfigurasi DB produksi. Jam konfirmasi melintasi batas sesi dievaluasi ulang server; bukan menggunakan waktu scan awal yang sudah lewat. Logika conditional UPDATE diuji duplikasi berurutan; bukan klaim load-test konkurensi.
