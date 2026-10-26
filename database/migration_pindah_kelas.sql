-- Fitur: siswa mengajukan pindah kelas (perlu persetujuan petugas/admin) + ganti foto profil.
-- Tabel dibuat otomatis oleh aplikasi saat halaman pertama dibuka; file ini hanya untuk menjalankan manual
-- lewat phpMyAdmin. Aman dijalankan berulang kali. Tidak ada perubahan pada tabel yang sudah ada (tanpa ALTER).

CREATE TABLE IF NOT EXISTS `pindah_kelas_pengajuan` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `siswa_id` int UNSIGNED NOT NULL,
  `kelas_asal` varchar(20) NOT NULL,
  `kelas_tujuan` varchar(20) NOT NULL,
  `alasan` varchar(300) NOT NULL,
  `status` enum('menunggu','disetujui','ditolak','dibatalkan') NOT NULL DEFAULT 'menunggu',
  `diajukan_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `diproses_oleh` int UNSIGNED DEFAULT NULL,
  `diproses_nama` varchar(100) DEFAULT NULL,
  `diproses_at` datetime DEFAULT NULL,
  `catatan_petugas` varchar(300) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pk_status` (`status`,`diajukan_at`),
  KEY `idx_pk_siswa` (`siswa_id`,`status`),
  CONSTRAINT `fk_pindah_siswa` FOREIGN KEY (`siswa_id`) REFERENCES `siswa` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catatan: izin panel (pindah_kelas.lihat / pindah_kelas.proses) diberikan otomatis oleh aplikasi sekali saja kepada
-- role yang sudah memiliki izin siswa.ubah; atur selanjutnya lewat menu Hak Akses. Admin selalu memiliki semua izin.
