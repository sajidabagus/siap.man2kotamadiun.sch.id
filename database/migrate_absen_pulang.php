<?php
/** CLI only. Tidak menjalankan INSERT dari dump; additive, idempoten. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
$check = in_array('--check', $argv, true);
try {
    $db = getDB();
    foreach ([
        'settings' => [
            'pulang_buka' => "TIME NOT NULL DEFAULT '13:00:00'",
            'pulang_tutup' => "TIME NOT NULL DEFAULT '16:00:00'",
            'terlambat_aktif' => 'TINYINT(1) NOT NULL DEFAULT 1',
        ],
        'presensi' => ['jam_pulang' => 'TIME NULL DEFAULT NULL'],
    ] as $table => $columns) {
        $known = $db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($columns as $name => $definition) {
            if (in_array($name, $known, true)) { echo "OK $table.$name\n"; continue; }
            if ($check) { fwrite(STDERR, "BELUM ADA $table.$name: backup DB lalu jalankan php database/migrate_absen_pulang.php (tanpa --check) dengan hak ALTER.\n"); exit(1); }
            $db->exec("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
            echo "DITAMBAH $table.$name\n";
        }
    }
    echo "Migrasi absen pulang siap. Periksa/simpan Jam absensi dan Agenda sebelum membuka scan.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "GAGAL: periksa koneksi config/database.php dan hak SELECT/ALTER pada settings/presensi. Detail kesalahan disembunyikan agar kredensial tidak tercetak.\n");
    exit(1);
}
