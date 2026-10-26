<?php
/**
 * PENGAJUAN PINDAH KELAS (siswa) + GANTI FOTO PROFIL
 *
 * Alur: siswa mengajukan kelas tujuan lewat /portal/profil/edit -> petugas/admin menyetujui atau menolak
 * di panel (modul "pindah_kelas") -> bila disetujui, siswa.kelas diganti dan siswa mendapat notifikasi.
 * Riwayat presensi TIDAK diubah (presensi menyimpan kelas_saat_presensi sendiri).
 * Foto profil boleh diganti langsung oleh siswa (tidak perlu persetujuan).
 *
 * Tabel dibuat otomatis; SQL manual: database/migration_pindah_kelas.sql
 */
require_once __DIR__ . '/izin.php';   // portal_notif_tambah(), izin_nama_admin(), tabel notifikasi

const PINDAH_ALASAN_MIN   = 5;
const PINDAH_ALASAN_MAKS  = 300;
const PINDAH_CATATAN_MAKS = 300;
const PINDAH_STATUS_LABEL = ['menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak', 'dibatalkan' => 'Dibatalkan'];

function pindah_siapkan_tabel(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db = getDB();
        if (!(bool) $db->query("SHOW TABLES LIKE 'pindah_kelas_pengajuan'")->fetchColumn()) {
            $db->exec("CREATE TABLE IF NOT EXISTS pindah_kelas_pengajuan (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                siswa_id INT UNSIGNED NOT NULL,
                kelas_asal VARCHAR(20) NOT NULL,
                kelas_tujuan VARCHAR(20) NOT NULL,
                alasan VARCHAR(300) NOT NULL,
                status ENUM('menunggu','disetujui','ditolak','dibatalkan') NOT NULL DEFAULT 'menunggu',
                diajukan_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                diproses_oleh INT UNSIGNED NULL,
                diproses_nama VARCHAR(100) NULL,
                diproses_at DATETIME NULL,
                catatan_petugas VARCHAR(300) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_pk_status (status, diajukan_at),
                KEY idx_pk_siswa (siswa_id, status),
                CONSTRAINT fk_pindah_siswa FOREIGN KEY (siswa_id) REFERENCES siswa (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        return $ok = true;
    } catch (Throwable $e) {
        error_log('pindah_siapkan_tabel: ' . $e->getMessage());
        return $ok = false;
    }
}

/** Kelas yang bisa dipilih sebagai tujuan: kelas yang punya siswa aktif, selain kelas siswa sendiri. @return string[] */
function pindah_daftar_kelas(string $kelasSekarang): array
{
    $rows = getDB()->query("SELECT DISTINCT kelas FROM siswa WHERE status = 'aktif' AND kelas <> '' ORDER BY kelas")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter($rows, fn($k) => (string) $k !== $kelasSekarang));
}

function pindah_ambil(int $id): ?array
{
    $st = getDB()->prepare('SELECT p.*, s.nama, s.nis, s.kelas AS kelas_sekarang, s.status AS siswa_status
                            FROM pindah_kelas_pengajuan p JOIN siswa s ON s.id = p.siswa_id WHERE p.id = :id');
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

function pindah_menunggu_siswa(int $siswaId): ?array
{
    $st = getDB()->prepare("SELECT * FROM pindah_kelas_pengajuan WHERE siswa_id = :s AND status = 'menunggu' ORDER BY id DESC LIMIT 1");
    $st->execute([':s' => $siswaId]);
    return $st->fetch() ?: null;
}

/** Pengajuan terakhir yang sudah diproses (disetujui/ditolak) — untuk ditampilkan di profil siswa. */
function pindah_riwayat_siswa(int $siswaId, int $batas = 3): array
{
    $st = getDB()->prepare("SELECT * FROM pindah_kelas_pengajuan WHERE siswa_id = :s AND status <> 'menunggu' ORDER BY id DESC LIMIT " . max(1, $batas));
    $st->execute([':s' => $siswaId]);
    return $st->fetchAll();
}

function pindah_menunggu_jumlah(): int
{
    try {
        if (!pindah_siapkan_tabel()) return 0;
        return (int) getDB()->query("SELECT COUNT(*) FROM pindah_kelas_pengajuan WHERE status = 'menunggu'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function pindah_bersihkan(string $t, int $maks): string
{
    return mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $t)), 0, $maks);
}

/** Siswa mengajukan pindah kelas. @return array{ok:bool,id?:int,galat?:array<string,string>} */
function pindah_buat(int $siswaId, string $tujuan, string $alasan): array
{
    $db = getDB();
    $st = $db->prepare('SELECT id, nama, nis, kelas, status FROM siswa WHERE id = :id');
    $st->execute([':id' => $siswaId]);
    $s = $st->fetch();
    if (!$s || $s['status'] !== 'aktif') return ['ok' => false, 'galat' => ['umum' => 'Akun siswa tidak aktif.']];

    $tujuan = pindah_bersihkan($tujuan, 20);
    $alasan = pindah_bersihkan($alasan, PINDAH_ALASAN_MAKS);
    $galat = [];
    if ($tujuan === '') $galat['kelas'] = 'Pilih kelas tujuan.';
    elseif ($tujuan === $s['kelas']) $galat['kelas'] = 'Kelas tujuan sama dengan kelas Anda sekarang.';
    elseif (!in_array($tujuan, pindah_daftar_kelas((string) $s['kelas']), true)) $galat['kelas'] = 'Kelas tujuan tidak tersedia.';
    if (mb_strlen($alasan) < PINDAH_ALASAN_MIN) $galat['alasan'] = 'Alasan wajib diisi (minimal ' . PINDAH_ALASAN_MIN . ' karakter).';
    if (pindah_menunggu_siswa($siswaId)) $galat['umum'] = 'Masih ada pengajuan pindah kelas yang menunggu persetujuan. Batalkan dulu bila ingin mengubahnya.';
    if ($galat) return ['ok' => false, 'galat' => $galat];

    $db->prepare('INSERT INTO pindah_kelas_pengajuan (siswa_id, kelas_asal, kelas_tujuan, alasan) VALUES (:s, :a, :t, :al)')
       ->execute([':s' => $siswaId, ':a' => $s['kelas'], ':t' => $tujuan, ':al' => $alasan]);
    $id = (int) $db->lastInsertId();
    security_log($siswaId, 'PINDAH_KELAS_DIAJUKAN', null, 'Mengajukan pindah kelas ' . $s['kelas'] . ' → ' . $tujuan);
    return ['ok' => true, 'id' => $id];
}

function pindah_batalkan_siswa(int $id, int $siswaId): array
{
    $u = getDB()->prepare("UPDATE pindah_kelas_pengajuan SET status = 'dibatalkan' WHERE id = :id AND siswa_id = :s AND status = 'menunggu'");
    $u->execute([':id' => $id, ':s' => $siswaId]);
    if ($u->rowCount() !== 1) return ['ok' => false, 'pesan' => 'Pengajuan sudah diproses atau tidak ditemukan.'];
    security_log($siswaId, 'PINDAH_KELAS_DIBATALKAN', null, 'Membatalkan pengajuan pindah kelas #' . $id);
    return ['ok' => true];
}

/** Setujui: ganti siswa.kelas (dan jurusan bila kelas tujuan seragam), lalu beri tahu siswa. */
function pindah_setujui(int $id, array $admin, string $catatan = ''): array
{
    $db = getDB();
    $catatan = pindah_bersihkan($catatan, PINDAH_CATATAN_MAKS);
    try {
        $db->beginTransaction();
        $st = $db->prepare('SELECT p.*, s.nama, s.nis, s.kelas AS kelas_sekarang, s.status AS siswa_status
                            FROM pindah_kelas_pengajuan p JOIN siswa s ON s.id = p.siswa_id WHERE p.id = :id FOR UPDATE');
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        if (!$r) { $db->rollBack(); return ['ok' => false, 'pesan' => 'Pengajuan tidak ditemukan.']; }
        if ($r['status'] !== 'menunggu') { $db->rollBack(); return ['ok' => false, 'pesan' => 'Pengajuan sudah diproses (status: ' . (PINDAH_STATUS_LABEL[$r['status']] ?? $r['status']) . ').']; }
        if ($r['siswa_status'] !== 'aktif') { $db->rollBack(); return ['ok' => false, 'pesan' => 'Siswa sudah nonaktif.']; }
        if ($r['kelas_sekarang'] !== $r['kelas_asal']) {
            $db->rollBack();
            return ['ok' => false, 'pesan' => 'Kelas siswa sudah berubah sejak pengajuan (sekarang ' . $r['kelas_sekarang'] . '). Tolak pengajuan ini.'];
        }
        $ada = $db->prepare("SELECT COUNT(*) FROM siswa WHERE kelas = :k AND status = 'aktif'");
        $ada->execute([':k' => $r['kelas_tujuan']]);
        if ((int) $ada->fetchColumn() === 0) { $db->rollBack(); return ['ok' => false, 'pesan' => 'Kelas tujuan "' . $r['kelas_tujuan'] . '" tidak ditemukan lagi. Tolak pengajuan ini.']; }

        // Jurusan ikut kelas tujuan bila seluruh siswa aktif di kelas itu memakai satu jurusan yang sama.
        $jr = $db->prepare("SELECT DISTINCT jurusan FROM siswa WHERE kelas = :k AND status = 'aktif' AND jurusan IS NOT NULL AND jurusan <> ''");
        $jr->execute([':k' => $r['kelas_tujuan']]);
        $jurusan = $jr->fetchAll(PDO::FETCH_COLUMN);
        if (count($jurusan) === 1) {
            $db->prepare('UPDATE siswa SET kelas = :k, jurusan = :j WHERE id = :id')->execute([':k' => $r['kelas_tujuan'], ':j' => $jurusan[0], ':id' => $r['siswa_id']]);
        } else {
            $db->prepare('UPDATE siswa SET kelas = :k WHERE id = :id')->execute([':k' => $r['kelas_tujuan'], ':id' => $r['siswa_id']]);
        }
        $db->prepare("UPDATE pindah_kelas_pengajuan SET status = 'disetujui', diproses_oleh = :a, diproses_nama = :n, diproses_at = NOW(), catatan_petugas = :c WHERE id = :id")
           ->execute([':a' => $admin['id'] ?? null, ':n' => izin_nama_admin($admin), ':c' => $catatan ?: null, ':id' => $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('pindah_setujui: ' . $e->getMessage());
        return ['ok' => false, 'pesan' => 'Gagal menyetujui pengajuan (kesalahan server).'];
    }
    $isi = 'Pindah kelas ' . $r['kelas_asal'] . ' → ' . $r['kelas_tujuan'] . ' disetujui. Kelas Anda sekarang ' . $r['kelas_tujuan'] . '.';
    if ($catatan !== '') $isi .= ' Catatan petugas: ' . $catatan;
    portal_notif_tambah((int) $r['siswa_id'], 'pindah_disetujui', 'Pindah kelas disetujui', $isi, 'profil/edit');
    return ['ok' => true, 'nama' => $r['nama'], 'nis' => $r['nis'], 'dari' => $r['kelas_asal'], 'ke' => $r['kelas_tujuan']];
}

function pindah_tolak(int $id, array $admin, string $catatan): array
{
    $catatan = pindah_bersihkan($catatan, PINDAH_CATATAN_MAKS);
    if (mb_strlen($catatan) < 5) return ['ok' => false, 'pesan' => 'Alasan penolakan wajib diisi (minimal 5 karakter) agar siswa tahu sebabnya.'];
    $r = pindah_ambil($id);
    if (!$r) return ['ok' => false, 'pesan' => 'Pengajuan tidak ditemukan.'];
    $u = getDB()->prepare("UPDATE pindah_kelas_pengajuan SET status = 'ditolak', diproses_oleh = :a, diproses_nama = :n, diproses_at = NOW(), catatan_petugas = :c WHERE id = :id AND status = 'menunggu'");
    $u->execute([':a' => $admin['id'] ?? null, ':n' => izin_nama_admin($admin), ':c' => $catatan, ':id' => $id]);
    if ($u->rowCount() !== 1) return ['ok' => false, 'pesan' => 'Pengajuan sudah diproses (status: ' . (PINDAH_STATUS_LABEL[$r['status']] ?? $r['status']) . ').'];
    portal_notif_tambah((int) $r['siswa_id'], 'pindah_ditolak', 'Pindah kelas ditolak',
        'Pindah kelas ' . $r['kelas_asal'] . ' → ' . $r['kelas_tujuan'] . ' ditolak. Alasan: ' . $catatan, 'profil/edit');
    return ['ok' => true, 'nama' => $r['nama'], 'nis' => $r['nis'], 'dari' => $r['kelas_asal'], 'ke' => $r['kelas_tujuan']];
}

/** Siswa mengganti foto profilnya sendiri (langsung berlaku). @return array{ok:bool,pesan:string} */
function pindah_foto_ganti(int $siswaId, ?array $file): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || empty($file['name'])) {
        return ['ok' => false, 'pesan' => 'Pilih foto terlebih dahulu.'];
    }
    $st = getDB()->prepare("SELECT foto FROM siswa WHERE id = :id AND status = 'aktif'");
    $st->execute([':id' => $siswaId]);
    $row = $st->fetch();
    if (!$row) return ['ok' => false, 'pesan' => 'Akun siswa tidak aktif.'];
    try {
        $baru = handle_upload_foto($file, $row['foto'] ?: null);   // validasi MIME/ukuran (2 MB) + hapus foto lama
    } catch (RuntimeException $e) {
        return ['ok' => false, 'pesan' => $e->getMessage()];
    }
    getDB()->prepare('UPDATE siswa SET foto = :f WHERE id = :id')->execute([':f' => $baru, ':id' => $siswaId]);
    security_log($siswaId, 'FOTO_PROFIL_DIUBAH', null, 'Siswa mengganti foto profil');
    return ['ok' => true, 'pesan' => 'Foto profil berhasil diperbarui.'];
}
