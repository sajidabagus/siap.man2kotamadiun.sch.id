-- Fitur: Agenda Sekolah — jadwalkan libur / ujian madrasah / kegiatan di luar kelas agar siswa tidak tercatat Alpa keliru.
-- Tabel & kolom dibuat otomatis oleh aplikasi saat dipakai; file ini hanya untuk menjalankan manual lewat phpMyAdmin.
-- Aman dijalankan berulang kali (IF NOT EXISTS). Tidak mengubah tabel presensi/siswa.

CREATE TABLE IF NOT EXISTS `agenda_sekolah` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `judul` varchar(120) NOT NULL,
  `jenis` enum('libur','ujian','kegiatan') NOT NULL,
  `tanggal_mulai` date NOT NULL,
  `tanggal_selesai` date NOT NULL,
  `kelas` varchar(600) DEFAULT NULL COMMENT 'kosong = semua kelas; selain itu daftar kelas dipisah koma',
  `jam_buka` time DEFAULT NULL,
  `batas_terlambat` time DEFAULT NULL,
  `batas_absen` time DEFAULT NULL,
  `catatan` varchar(300) DEFAULT NULL,
  `aktif` tinyint(1) NOT NULL DEFAULT 1,
  `dibuat_oleh` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agenda_tanggal` (`tanggal_mulai`,`tanggal_selesai`),
  KEY `idx_agenda_aktif` (`aktif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Penanda jam proses Alpa otomatis terakhir (agar kelas dengan batas absen lebih siang ikut diproses pada hari yang sama).
-- Jalankan sekali; bila kolom sudah ada, MySQL/MariaDB menolak dengan pesan "Duplicate column" (abaikan).
ALTER TABLE `settings` ADD COLUMN `alpa_otomatis_jam` TIME NULL;
