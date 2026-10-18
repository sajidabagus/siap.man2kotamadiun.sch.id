-- =====================================================================
-- SKEMA DATABASE: SIAP ABSEN (Presensi Siswa QR Code + Device Binding)
-- Struktur terbaru (disalin dari ekspor produksi, TANPA data siswa/log).
--
-- CARA PAKAI: buat database kosong (mis. `siapabsen`), pilih database itu di
-- phpMyAdmin, lalu Import file ini. Nama database harus sama dengan DB_NAME
-- di config/database.php. Tabel tambahan (role_permissions, login_attempts,
-- device_pairing_codes) juga dibuat otomatis oleh aplikasi bila belum ada.
--
-- Akun awal: username `admin`, password `admin123`
-- PENTING: SEGERA ganti password ini setelah login pertama!
-- =====================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `activity_logs` (
  `id` bigint UNSIGNED NOT NULL,
  `admin_id` int UNSIGNED DEFAULT NULL,
  `admin_nama` varchar(100) DEFAULT NULL,
  `aksi` varchar(20) NOT NULL,
  `modul` varchar(40) NOT NULL,
  `keterangan` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_bindings` (
  `id` int UNSIGNED NOT NULL,
  `siswa_id` int UNSIGNED NOT NULL,
  `device_uuid` varchar(64) DEFAULT NULL COMMENT 'UUID mentah utk referensi (opsional, boleh NULL demi privasi)',
  `device_hash` varchar(64) NOT NULL COMMENT 'sha256 dari device identifier',
  `device_name` varchar(100) DEFAULT NULL,
  `browser` varchar(100) DEFAULT NULL,
  `os` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `status` enum('UNREGISTERED','ACTIVE','TRUSTED','BLOCKED') NOT NULL DEFAULT 'ACTIVE',
  `registered_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime DEFAULT NULL,
  `reset_at` datetime DEFAULT NULL,
  `reset_by` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `device_reset_logs` (
  `id` int UNSIGNED NOT NULL,
  `siswa_id` int UNSIGNED NOT NULL,
  `old_device_hash` varchar(64) DEFAULT NULL,
  `admin_id` int UNSIGNED DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `presensi` (
  `id` int UNSIGNED NOT NULL,
  `siswa_id` int UNSIGNED NOT NULL,
  `kelas_saat_presensi` varchar(30) NOT NULL,
  `tanggal` date NOT NULL,
  `jam_masuk` time NOT NULL,
  `status` enum('HADIR','TERLAMBAT','IZIN','SAKIT','ALPA') NOT NULL DEFAULT 'HADIR',
  `metode` varchar(20) NOT NULL DEFAULT 'QR',
  `device_info` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `qr_dinamis` (
  `id` int UNSIGNED NOT NULL,
  `token` varchar(64) NOT NULL,
  `lokasi` varchar(100) NOT NULL DEFAULT 'Gerbang Utama',
  `kelas_target` varchar(20) DEFAULT NULL COMMENT 'NULL = QR gerbang (bebas semua kelas), diisi = QR khusus kelas tsb',
  `dibuat_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `kadaluarsa_at` datetime NOT NULL,
  `status` enum('AKTIF','EXPIRED') NOT NULL DEFAULT 'AKTIF'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `security_logs` (
  `id` bigint UNSIGNED NOT NULL,
  `siswa_id` int UNSIGNED DEFAULT NULL,
  `event` varchar(50) NOT NULL,
  `device_hash` varchar(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `settings` (
  `id` int UNSIGNED NOT NULL,
  `nama_sekolah` varchar(150) NOT NULL DEFAULT 'Nama Sekolah',
  `logo` varchar(255) DEFAULT NULL,
  `tahun_pelajaran` varchar(20) NOT NULL DEFAULT '2026/2027',
  `jam_masuk` time NOT NULL DEFAULT '07:00:00',
  `batas_terlambat` time NOT NULL DEFAULT '07:15:00',
  `latitude_sekolah` decimal(10,7) DEFAULT NULL,
  `longitude_sekolah` decimal(10,7) DEFAULT NULL,
  `radius_meter` int UNSIGNED NOT NULL DEFAULT '100',
  `gps_aktif` tinyint(1) NOT NULL DEFAULT '0',
  `validasi_ip_aktif` tinyint(1) NOT NULL DEFAULT '0',
  `device_binding_aktif` tinyint(1) NOT NULL DEFAULT '1',
  `device_wajib_kode` tinyint(1) NOT NULL DEFAULT '1',
  `qr_dinamis_durasi_detik` int UNSIGNED NOT NULL DEFAULT '120',
  `maks_reset_device_per_bulan` int UNSIGNED NOT NULL DEFAULT '2',
  `pesan_sukses` varchar(255) NOT NULL DEFAULT 'Presensi berhasil dicatat. Selamat belajar!',
  `warna_tema` varchar(20) NOT NULL DEFAULT '#0d6efd',
  `log_retensi_hari` int UNSIGNED NOT NULL DEFAULT '0' COMMENT '0 = nonaktif (tidak pernah dihapus otomatis), selain itu = umur maks log keamanan dalam hari sebelum dihapus otomatis',
  `log_terakhir_dibersihkan` datetime DEFAULT NULL COMMENT 'kapan pembersihan otomatis terakhir dijalankan, dipakai internal utk membatasi cek maks 1x/hari',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `hari_efektif` varchar(20) NOT NULL DEFAULT '1,2,3,4,5',
  `alamat_sekolah` text,
  `nama_kepala` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `siswa` (
  `id` int UNSIGNED NOT NULL,
  `nis` varchar(30) NOT NULL,
  `nisn` varchar(30) DEFAULT NULL,
  `nama` varchar(150) NOT NULL,
  `jenis_kelamin` enum('L','P') NOT NULL DEFAULT 'L',
  `tempat_lahir` varchar(100) DEFAULT NULL,
  `tanggal_lahir` date DEFAULT NULL,
  `kelas` varchar(20) NOT NULL,
  `jurusan` varchar(50) DEFAULT NULL,
  `tahun_pelajaran` varchar(20) NOT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `qr_token` varchar(64) NOT NULL,
  `status` enum('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `nama` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin') NOT NULL DEFAULT 'admin',
  `status` enum('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_act_aksi` (`aksi`),
  ADD KEY `idx_act_modul` (`modul`),
  ADD KEY `idx_act_created` (`created_at`);

ALTER TABLE `device_bindings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_siswa_device` (`siswa_id`),
  ADD UNIQUE KEY `uniq_device_hash` (`device_hash`),
  ADD KEY `fk_device_reset_by` (`reset_by`),
  ADD KEY `idx_device_status` (`status`);

ALTER TABLE `device_reset_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_reset_admin` (`admin_id`),
  ADD KEY `idx_reset_siswa_date` (`siswa_id`,`created_at`);

ALTER TABLE `presensi`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_siswa_tanggal` (`siswa_id`,`tanggal`),
  ADD KEY `idx_presensi_tanggal` (`tanggal`),
  ADD KEY `idx_presensi_status` (`status`),
  ADD KEY `idx_presensi_kelas_saat` (`kelas_saat_presensi`);

ALTER TABLE `qr_dinamis`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_qrdinamis_token` (`token`),
  ADD KEY `idx_qrdinamis_status` (`status`),
  ADD KEY `idx_qrdinamis_kelas_target` (`kelas_target`);

ALTER TABLE `security_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_seclog_event` (`event`),
  ADD KEY `idx_seclog_siswa_date` (`siswa_id`,`created_at`);

ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `siswa`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nis` (`nis`),
  ADD UNIQUE KEY `qr_token` (`qr_token`),
  ADD KEY `idx_kelas` (`kelas`),
  ADD KEY `idx_jurusan` (`jurusan`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_qr_token` (`qr_token`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

ALTER TABLE `activity_logs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

ALTER TABLE `device_bindings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

ALTER TABLE `device_reset_logs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `presensi`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

ALTER TABLE `qr_dinamis`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=168;

ALTER TABLE `security_logs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=196;

ALTER TABLE `settings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `siswa`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1345;

ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `device_bindings`
  ADD CONSTRAINT `fk_device_reset_by` FOREIGN KEY (`reset_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_device_siswa` FOREIGN KEY (`siswa_id`) REFERENCES `siswa` (`id`) ON DELETE CASCADE;

ALTER TABLE `device_reset_logs`
  ADD CONSTRAINT `fk_reset_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_reset_siswa` FOREIGN KEY (`siswa_id`) REFERENCES `siswa` (`id`) ON DELETE CASCADE;

ALTER TABLE `presensi`
  ADD CONSTRAINT `fk_presensi_siswa` FOREIGN KEY (`siswa_id`) REFERENCES `siswa` (`id`) ON DELETE CASCADE;

ALTER TABLE `security_logs`
  ADD CONSTRAINT `fk_seclog_siswa` FOREIGN KEY (`siswa_id`) REFERENCES `siswa` (`id`) ON DELETE SET NULL;

-- Pembatas percobaan login (anti brute force)
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `created_at` DATETIME NOT NULL,
  INDEX `idx_la_user` (`username`, `created_at`),
  INDEX `idx_la_ip` (`ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data awal
INSERT INTO `users` (`nama`, `username`, `password`, `role`, `status`) VALUES
('Administrator', 'admin', '$2b$10$b41vg2ko8zqtu.jWTfT48eXiFP5YLFg8U8IxUn7HI76kcI5upq7Ha', 'admin', 'aktif');

-- Koordinat sengaja NULL (belum diisi). JANGAN isi dummy seperti -7.1234567 /
-- 111.1234567 — koordinat palsu yang terisi membuat validasi radius (saat GPS
-- diaktifkan) menolak SEMUA siswa dari lokasi asli sekolah.
INSERT INTO `settings`
  (`nama_sekolah`, `tahun_pelajaran`, `jam_masuk`, `batas_terlambat`,
   `latitude_sekolah`, `longitude_sekolah`, `radius_meter`, `gps_aktif`)
VALUES
  ('SMA Negeri 1 Contoh', '2026/2027', '07:00:00', '07:15:00',
   NULL, NULL, 100, 0);

SET FOREIGN_KEY_CHECKS = 1;
