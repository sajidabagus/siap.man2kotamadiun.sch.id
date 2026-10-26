-- Fitur: admin/operator dapat mengubah status presensi siswa & menambah presensi manual (halaman Data Presensi).
-- Tabel audit dibuat otomatis oleh aplikasi saat pertama dipakai; file ini hanya untuk menjalankan manual lewat
-- phpMyAdmin. Aman dijalankan berulang kali. Tidak ada perubahan pada tabel yang sudah ada (tanpa ALTER).

CREATE TABLE IF NOT EXISTS `presensi_koreksi` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `presensi_id` int UNSIGNED DEFAULT NULL,
  `siswa_id` int UNSIGNED NOT NULL,
  `tanggal` date NOT NULL,
  `aksi` enum('ubah','tambah') NOT NULL,
  `status_lama` varchar(10) DEFAULT NULL,
  `status_baru` varchar(10) NOT NULL,
  `jam_lama` time DEFAULT NULL,
  `jam_baru` time NOT NULL,
  `alasan` varchar(300) NOT NULL,
  `diubah_oleh` int UNSIGNED DEFAULT NULL,
  `diubah_nama` varchar(100) DEFAULT NULL,
  `diubah_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_koreksi_siswa` (`siswa_id`,`tanggal`),
  KEY `idx_koreksi_waktu` (`diubah_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catatan: izin panel "presensi.ubah" diberikan otomatis oleh aplikasi sekali saja kepada role yang sudah memiliki
-- izin siswa.ubah; atur selanjutnya lewat menu Hak Akses. Admin selalu memiliki semua izin.
