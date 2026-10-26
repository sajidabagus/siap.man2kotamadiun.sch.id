<?php
/**
 * KOREKSI PRESENSI MANUAL (admin / operator dengan izin "presensi.ubah")
 *
 *  - presensi_ubah_status()   : ubah status (dan jam) baris presensi yang sudah ada
 *  - presensi_tambah_manual() : buat baris presensi untuk siswa yang belum punya baris pada tanggal itu
 *
 * Aturan: alasan wajib (min. 5 karakter); metode baris menjadi 'MANUAL'; setiap perubahan dicatat di tabel
 * presensi_koreksi (audit: siapa, kapan, status/jam lama -> baru, alasan) dan di Log Aktivitas; siswa menerima
 * notifikasi di portal. Tabel dibuat otomatis; SQL manual: database/migration_presensi_koreksi.sql
 */
require_once __DIR__ . '/izin.php';   // portal_notif_tambah(), izin_nama_admin(), tabel notifikasi

const PRESENSI_STATUS_LIST  = ['HADIR', 'TERLAMBAT', 'IZIN', 'SAKIT', 'ALPA'];
const PRESENSI_ALASAN_MIN   = 5;
const PRESENSI_ALASAN_MAKS  = 300;

function koreksi_siapkan_tabel(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS presensi_koreksi (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            presensi_id INT UNSIGNED NULL,
            siswa_id INT UNSIGNED NOT NULL,
            tanggal DATE NOT NULL,
            aksi ENUM('ubah','tambah') NOT NULL,
            status_lama VARCHAR(10) NULL,
            status_baru VARCHAR(10) NOT NULL,
            jam_lama TIME NULL,
            jam_baru TIME NOT NULL,
            alasan VARCHAR(300) NOT NULL,
            diubah_oleh INT UNSIGNED NULL,
            diubah_nama VARCHAR(100) NULL,
            diubah_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_koreksi_siswa (siswa_id, tanggal),
            KEY idx_koreksi_waktu (diubah_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('koreksi_siapkan_tabel: ' . $e->getMessage());
        return $ok = false;
    }
}

/** Validasi status + jam + alasan. @return array{galat:?string,jam:string,alasan:string} */
function koreksi_validasi(string $status, string $jam, string $alasan): array
{
    $alasan = mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $alasan)), 0, PRESENSI_ALASAN_MAKS);
    $jam = trim($jam);
    if (!in_array($status, PRESENSI_STATUS_LIST, true)) return ['galat' => 'Status tidak valid.', 'jam' => '', 'alasan' => $alasan];
    if (mb_strlen($alasan) < PRESENSI_ALASAN_MIN) return ['galat' => 'Alasan perubahan wajib diisi (minimal ' . PRESENSI_ALASAN_MIN . ' karakter).', 'jam' => '', 'alasan' => $alasan];
    if ($status === 'HADIR' || $status === 'TERLAMBAT') {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $jam)) return ['galat' => 'Jam masuk wajib diisi dengan format JJ:MM untuk status Hadir/Terlambat.', 'jam' => '', 'alasan' => $alasan];
        $jam = substr($jam, 0, 5) . ':00';
    } else {
        $jam = '00:00:00';          // Izin/Sakit/Alpa tidak memakai jam (sama seperti baris otomatis)
    }
    return ['galat' => null, 'jam' => $jam, 'alasan' => $alasan];
}

function koreksi_label_status(string $s): string
{
    return ['HADIR' => 'Hadir', 'TERLAMBAT' => 'Terlambat', 'IZIN' => 'Izin', 'SAKIT' => 'Sakit', 'ALPA' => 'Alpa'][$s] ?? $s;
}

function koreksi_catat(PDO $db, ?int $presensiId, int $siswaId, string $tanggal, string $aksi, ?string $statusLama, string $statusBaru, ?string $jamLama, string $jamBaru, string $alasan, array $admin): void
{
    $db->prepare('INSERT INTO presensi_koreksi (presensi_id, siswa_id, tanggal, aksi, status_lama, status_baru, jam_lama, jam_baru, alasan, diubah_oleh, diubah_nama)
                  VALUES (:p, :s, :t, :a, :sl, :sb, :jl, :jb, :al, :o, :n)')
       ->execute([':p' => $presensiId, ':s' => $siswaId, ':t' => $tanggal, ':a' => $aksi, ':sl' => $statusLama, ':sb' => $statusBaru,
                  ':jl' => $jamLama, ':jb' => $jamBaru, ':al' => $alasan, ':o' => $admin['id'] ?? null, ':n' => izin_nama_admin($admin)]);
}

function koreksi_beri_tahu(int $siswaId, string $tanggal, ?string $lama, string $baru, string $alasan): void
{
    $tgl = date('d/m/Y', strtotime($tanggal));
    $isi = $lama !== null
        ? "Status presensi $tgl diubah petugas dari " . koreksi_label_status($lama) . ' menjadi ' . koreksi_label_status($baru) . '. Alasan: ' . $alasan
        : "Petugas mencatat presensi Anda tanggal $tgl dengan status " . koreksi_label_status($baru) . '. Alasan: ' . $alasan;
    portal_notif_tambah($siswaId, 'presensi_diubah', 'Status presensi diperbarui petugas', $isi, 'riwayat');
}

/** Ubah status/jam satu baris presensi. @return array{ok:bool,pesan?:string,nama?:string,nis?:string,tanggal?:string,lama?:string,baru?:string} */
function presensi_ubah_status(int $presensiId, string $statusBaru, string $jam, string $alasan, array $admin): array
{
    if (!koreksi_siapkan_tabel()) return ['ok' => false, 'pesan' => 'Tabel audit koreksi belum bisa dibuat. Jalankan database/migration_presensi_koreksi.sql.'];
    $v = koreksi_validasi($statusBaru, $jam, $alasan);
    if ($v['galat']) return ['ok' => false, 'pesan' => $v['galat']];

    $db = getDB();
    try {
        $db->beginTransaction();
        $st = $db->prepare('SELECT p.*, s.nama, s.nis FROM presensi p JOIN siswa s ON s.id = p.siswa_id WHERE p.id = :id FOR UPDATE');
        $st->execute([':id' => $presensiId]);
        $p = $st->fetch();
        if (!$p) { $db->rollBack(); return ['ok' => false, 'pesan' => 'Data presensi tidak ditemukan (mungkin sudah dihapus).']; }
        if ($p['status'] === $statusBaru && substr((string) $p['jam_masuk'], 0, 8) === $v['jam']) {
            $db->rollBack();
            return ['ok' => false, 'pesan' => 'Tidak ada perubahan: status dan jam sama dengan data saat ini.'];
        }
        $db->prepare("UPDATE presensi SET status = :s, jam_masuk = :j, metode = 'MANUAL' WHERE id = :id")
           ->execute([':s' => $statusBaru, ':j' => $v['jam'], ':id' => $presensiId]);

        // Baris izin/sakit yang disetujui lalu diubah menjadi hadir: tandai izin hari itu "dipakai_hadir" (seperti saat siswa scan sendiri).
        if ($p['metode'] === 'PENGAJUAN' && in_array($statusBaru, ['HADIR', 'TERLAMBAT'], true)) {
            try {
                $db->prepare("UPDATE izin_hari h JOIN izin_pengajuan x ON x.id = h.pengajuan_id SET h.hasil = 'dipakai_hadir'
                              WHERE x.siswa_id = :s AND h.tanggal = :t AND h.hasil = 'diterapkan'")
                   ->execute([':s' => $p['siswa_id'], ':t' => $p['tanggal']]);
            } catch (Throwable $e) { /* tabel izin belum ada: tidak masalah */ }
        }
        koreksi_catat($db, $presensiId, (int) $p['siswa_id'], $p['tanggal'], 'ubah', $p['status'], $statusBaru, $p['jam_masuk'], $v['jam'], $v['alasan'], $admin);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('presensi_ubah_status: ' . $e->getMessage());
        return ['ok' => false, 'pesan' => 'Gagal menyimpan perubahan (kesalahan server).'];
    }
    koreksi_beri_tahu((int) $p['siswa_id'], $p['tanggal'], $p['status'], $statusBaru, $v['alasan']);
    return ['ok' => true, 'nama' => $p['nama'], 'nis' => $p['nis'], 'tanggal' => $p['tanggal'], 'lama' => $p['status'], 'baru' => $statusBaru, 'alasan' => $v['alasan']];
}

/** Buat baris presensi manual untuk siswa aktif (dicari lewat NIS/NISN) yang belum punya baris pada tanggal itu. */
function presensi_tambah_manual(string $kunciSiswa, string $tanggal, string $status, string $jam, string $alasan, array $admin): array
{
    if (!koreksi_siapkan_tabel()) return ['ok' => false, 'pesan' => 'Tabel audit koreksi belum bisa dibuat. Jalankan database/migration_presensi_koreksi.sql.'];
    $v = koreksi_validasi($status, $jam, $alasan);
    if ($v['galat']) return ['ok' => false, 'pesan' => $v['galat']];
    $d = DateTime::createFromFormat('Y-m-d', $tanggal);
    if (!$d || $d->format('Y-m-d') !== $tanggal) return ['ok' => false, 'pesan' => 'Tanggal tidak valid.'];
    if ($tanggal > date('Y-m-d')) return ['ok' => false, 'pesan' => 'Tanggal tidak boleh di masa depan.'];
    $kunci = trim($kunciSiswa);
    if ($kunci === '') return ['ok' => false, 'pesan' => 'Isi NIS atau NISN siswa.'];

    $db = getDB();
    $st = $db->prepare("SELECT id, nama, nis, kelas FROM siswa WHERE (nis = :k1 OR nisn = :k2) AND status = 'aktif' LIMIT 2");
    $st->execute([':k1' => $kunci, ':k2' => $kunci]);
    $cari = $st->fetchAll();
    if (count($cari) !== 1) return ['ok' => false, 'pesan' => $cari ? 'NIS/NISN cocok dengan lebih dari satu siswa. Gunakan NIS.' : 'Siswa aktif dengan NIS/NISN tersebut tidak ditemukan.'];
    $s = $cari[0];

    try {
        $db->beginTransaction();
        $ada = $db->prepare('SELECT status FROM presensi WHERE siswa_id = :s AND tanggal = :t FOR UPDATE');
        $ada->execute([':s' => $s['id'], ':t' => $tanggal]);
        if ($row = $ada->fetch()) {
            $db->rollBack();
            return ['ok' => false, 'pesan' => $s['nama'] . ' sudah punya presensi tanggal itu (status ' . koreksi_label_status($row['status']) . '). Gunakan tombol Ubah pada barisnya.'];
        }
        $db->prepare("INSERT INTO presensi (siswa_id, kelas_saat_presensi, tanggal, jam_masuk, status, metode, created_at) VALUES (:s, :k, :t, :j, :st, 'MANUAL', NOW())")
           ->execute([':s' => $s['id'], ':k' => $s['kelas'], ':t' => $tanggal, ':j' => $v['jam'], ':st' => $status]);
        $id = (int) $db->lastInsertId();
        koreksi_catat($db, $id, (int) $s['id'], $tanggal, 'tambah', null, $status, null, $v['jam'], $v['alasan'], $admin);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('presensi_tambah_manual: ' . $e->getMessage());
        return ['ok' => false, 'pesan' => 'Gagal menyimpan presensi (kesalahan server).'];
    }
    koreksi_beri_tahu((int) $s['id'], $tanggal, null, $status, $v['alasan']);
    return ['ok' => true, 'nama' => $s['nama'], 'nis' => $s['nis'], 'tanggal' => $tanggal, 'lama' => null, 'baru' => $status, 'alasan' => $v['alasan']];
}
