-- Migrasi GLM #4 — device_wajib_kode default ON (18 Oktober 2026).
-- Jalankan manual bila aplikasi lama belum memiliki kolom device_wajib_kode,
-- ATAU untuk menerapkan kembali kebijakan "pairing selalu lewat admin".
--
-- 1) Tambah kolom bila belum ada.
--    Bila muncul error "Duplicate column name", kolom sudah ada — lanjutkan ke 2.
ALTER TABLE settings ADD COLUMN device_wajib_kode TINYINT(1) NOT NULL DEFAULT 1;

-- 2) Terapkan kebijakan ke baris settings yang ada (0 -> 1).
--    LEWATI langkah ini bila admin sengaja mematikan mode Wajib Kode/QR Pasangan.
UPDATE settings SET device_wajib_kode = 1 WHERE device_wajib_kode = 0;

-- 3) Cache settings dibersihkan otomatis saat admin menyimpan Pengaturan
--    (hapus_cache_settings()). Bila tidak melalui panel, hapus file di
--    var/cache/ agar nilai baru langsung terbaca.
