<?php
/** CLI saja. Backup dahulu. --check memeriksa kesiapan TANPA membuat/mengubah tabel. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/izin.php';
if (in_array('--check', $argv, true)) {
    $db = getDB(); $kurang = [];
    foreach (['izin_pengajuan' => ['jenis','mode_durasi','jam_mulai','jam_selesai'], 'izin_hari' => ['kelas_dispensasi']] as $t => $fields) {
        foreach ($fields as $field) {
            try { $col = $db->query("SHOW COLUMNS FROM $t WHERE Field='$field'")->fetch(); }
            catch (Throwable $e) { $col = false; }
            if (!$col || ($field === 'jenis' && strpos($col['Type'], "'dispensasi'") === false)) $kurang[] = "$t.$field";
        }
    }
    if ($kurang) { fwrite(STDERR, 'Belum siap: ' . implode(', ', $kurang) . ". Backup DB lalu jalankan tanpa --check memakai akun DB berhak CREATE/ALTER.\n"); exit(1); }
    echo "Skema dispensasi lengkap; pemeriksaan read-only selesai.\n"; exit;
}
if (!izin_siapkan_tabel()) {
    fwrite(STDERR, "Migrasi gagal. Periksa koneksi DB dan hak CREATE/ALTER; lihat log PHP. Data lama tidak dihapus.\n"); exit(1);
}
echo "Skema dispensasi siap. Eksekusi ulang aman; data izin/sakit dan presensi lama dipertahankan.\n";
