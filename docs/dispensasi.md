# Pengajuan dispensasi

## Aturan

- Jenis ketiga di menu **Izin → Dispensasi**; keterangan kegiatan 10–500 karakter dan
  **foto dokumen JPG/JPEG/PNG/WebP wajib**. PDF tetap diizinkan untuk izin/sakit lama, bukan dispensasi.
- Durasi: **jam** (satu tanggal, selesai > mulai, tidak melewati tengah malam),
  **satu hari penuh** (satu tanggal), **beberapa hari** (awal/akhir inklusif).
- Batas existing tetap: mundur 3 hari, maju 30 hari, rentang maksimal 14 hari, 5 pengajuan menunggu.
- Izin/sakit tetap tidak bisa diajukan pada tanggal sudah presensi. Dispensasi boleh setelah hadir.
- Pengajuan aktif yang waktunya beririsan ditolak; dua dispensasi jam yang bersambung/tidak beririsan boleh.
  Full-day dan izin/sakit aktif mengunci pengajuan lain pada tanggal tercakup.
- Tidak ada auto-approve. Petugas berhak `izin.proses` menyetujui/menolak/mencabut lewat alur existing + CSRF.
  Siswa hanya dapat melihat lampiran miliknya dan membatalkan pengajuannya sendiri selama menunggu.

## Dampak absensi (keputusan pemilik)

Dispensasi disimpan sebagai **overlay kegiatan** di `izin_pengajuan` dan `izin_hari`,
**bukan** nilai baru pada ENUM `presensi.status`. Hitungan Hadir/Izin/Sakit tidak ditambah atau dipalsukan.
Siswa hadir + dispensasi ditampilkan pada keduanya secara eksplisit.

- Jam tertentu: **tidak** membebaskan Alpa satu hari; tidak mengunci scan. Siswa tetap mengikuti aturan scan/cutoff existing.
- Satu/beberapa hari **disetujui**: mengecualikan Alpa otomatis pada tanggal tercakup;
  persetujuan menghapus hanya baris `status=ALPA,metode=OTOMATIS` di hari efektif tercakup.
  HADIR/TERLAMBAT/IZIN/SAKIT/ALPA manual serta metadata presensi asli tidak dihapus/diganti.
- Dicabut: evaluasi kembali Alpa hanya untuk hari efektif tercatat, lewat cutoff per kelas,
  siswa aktif/sudah terdaftar, belum punya presensi asli/pembebasan lain, dan ada scan asli pada hari itu
  (pengaman hari tanpa scan existing tetap berlaku). Masa depan/sebelum cutoff/libur tidak langsung diberi Alpa.
- Kelas pada hari dispensasi di-snapshot, mengikuti snapshot presensi bila sudah ada.
- Home/riwayat siswa dan lampiran laporan harian/bulanan/cetak menampilkan dispensasi terpisah.
  CSV/Excel harian menambah kolom **Dispensasi**; tanpa baris presensi, status/jam/metode dibiarkan kosong,
  bukan membuat hadir/izin palsu. Filter status ekspor hanya mengikutsertakan baris presensi yang sesuai;
  lampiran tabel dispensasi di layar/cetak adalah daftar terpisah berdasarkan tanggal/kelas/jurusan.

## Deploy aman — tidak dijalankan otomatis oleh PR

1. Backup database **dan uploads/izin** memakai mekanisme server yang sudah disetujui; jangan kirim dump ke GitHub/chat.
2. Uji pada staging PHP 8.1+ dan MySQL/MariaDB. Kebutuhan existing tetap: PDO MySQL, direktori uploads/izin writable,
   document access melalui route terautentikasi, uploads/izin tidak dapat dilayani langsung oleh Apache/Nginx.
3. Pasang seluruh file PR secara utuh pada jendela pemeliharaan (jangan campur file versi lama/baru).
4. Periksa tanpa menulis: `php database/migrate_dispensasi.php --check`.
   Exit 1 dengan daftar kolom belum siap adalah normal sebelum migrasi; petunjuk perbaikan tercetak.
5. Dengan akun DB migrasi yang memiliki CREATE/ALTER, jalankan `php database/migrate_dispensasi.php`.
   Jalankan ulang `--check`; harus exit 0. Runtime memakai pola auto-migrasi existing, tetapi migrasi CLI terlebih dahulu disarankan.
   Migrasi hanya menambah enum `dispensasi`, kolom mode/jam, dan snapshot kelas di izin_hari; tidak DROP/TRUNCATE data lama.
   MySQL DDL tidak selalu transactional: jika gagal sebagian, betulkan izin DB lalu jalankan ulang (idempotent).
6. Verifikasi folder unggahan aman/writable dan batas request server: hasil lampiran maks. 3 MB.
   Foto mentah diterima hingga 15 MB hanya bila mesin kompres existing tersedia; konfigurasikan upload/post limits
   sesuai kebutuhan (post lebih besar dari upload), atau gunakan file yang sudah <3 MB. fileinfo opsional karena fallback existing;
   GD/Imagick disarankan untuk kompresi foto. Untuk dispensasi, ekstensi nama file harus cocok dengan MIME sebenarnya. Validasi izin/sakit existing dipertahankan.
7. Smoke-test staging: izin/sakit lama, dispensasi jam setelah hadir, foto wajib, approve/tolak/cabut,
   full-day sesudah cutoff, hadir asli tetap, riwayat dan CSV/cetak; uji kamera/GPS/WebView pada perangkat sekolah.
8. Setelah lolos baru deploy produksi oleh pemilik/server operator. PR ini **tidak** deploy aaPanel/merge otomatis.

Rollback kode tidak memerlukan penghapusan kolom/enum. **Jangan rollback buta ke versi sebelum dispensasi**:
query auto-Alpa dan pemblokir scan lama tidak memahami dispensasi jam; kembali ke kode lama dapat salah membebaskan
Alpa/memblokir scan. Jika rollback diperlukan, tutup penerimaan pengajuan dan rencanakan versi kompatibilitas
bersama pemilik sambil mempertahankan data dispensasi yang sudah disetujui.

## Audit dan privasi

Foto disimpan dengan nama acak, MIME/ext/ukuran/dimensi divalidasi server, dan dilayani lewat endpoint pemilik/petugas
terotorisasi dengan `nosniff`. Endpoint langsung `panel/izin_lampiran.php` juga diberi auth guard (bukan hanya router).
Retensi lampiran mengikuti kebijakan izin existing. Log audit persetujuan/pembukaan tetap digunakan.
