<?php
/** Dispensasi adalah overlay kegiatan, bukan pengganti status HADIR/IZIN/SAKIT. */
const DISPENSASI_MODE = ['jam' => 'Rentang jam', 'sehari' => 'Satu hari penuh', 'beberapa_hari' => 'Beberapa hari'];

function dispensasi_migrasi(PDO $db): void
{
    $kolom = $db->query("SHOW COLUMNS FROM izin_pengajuan WHERE Field = 'jenis'")->fetch(PDO::FETCH_ASSOC);
    if (strpos((string) $kolom['Type'], "'dispensasi'") === false) {
        // Pertahankan seluruh nilai enum lama/custom; hanya tambahkan jenis baru.
        $tipe = (string) $kolom['Type'];
        if (!preg_match('/^enum\(.+\)$/i', $tipe)) throw new RuntimeException('Kolom jenis pengajuan bukan ENUM yang didukung.');
        $null = ($kolom['Null'] ?? 'NO') === 'YES' ? ' NULL' : ' NOT NULL';
        $default = $kolom['Default'] !== null ? ' DEFAULT ' . $db->quote((string) $kolom['Default']) : '';
        $db->exec('ALTER TABLE izin_pengajuan MODIFY jenis ' . substr($tipe, 0, -1) . ",'dispensasi') " . $null . $default);
    }
    foreach ([
        'mode_durasi' => "ENUM('jam','sehari','beberapa_hari') NOT NULL DEFAULT 'sehari'",
        'jam_mulai' => 'TIME NULL', 'jam_selesai' => 'TIME NULL',
    ] as $nama => $tipe) {
        if (!$db->query("SHOW COLUMNS FROM izin_pengajuan WHERE Field = '$nama'")->fetch()) {
            try { $db->exec("ALTER TABLE izin_pengajuan ADD COLUMN $nama $tipe"); }
            catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) !== 1060) throw $e; }
        }
    }
    if (!$db->query("SHOW COLUMNS FROM izin_hari WHERE Field = 'kelas_dispensasi'")->fetch()) {
        try { $db->exec('ALTER TABLE izin_hari ADD COLUMN kelas_dispensasi VARCHAR(100) NULL'); }
        catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) !== 1060) throw $e; }
    }
}

function dispensasi_jam_valid(string $jam): bool
{
    return (bool) preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $jam);
}

/** Validasi murni durasi; server tidak mempercayai field tersembunyi dari browser. */
function dispensasi_validasi_durasi(array $in): array
{
    $mode = (string) ($in['mode_durasi'] ?? '');
    $g = [];
    if (!isset(DISPENSASI_MODE[$mode])) $g['mode_durasi'] = 'Pilih durasi dispensasi.';
    $a = trim((string) ($in['mulai'] ?? ''));
    $b = trim((string) ($in['selesai'] ?? ''));
    $jm = trim((string) ($in['jam_mulai'] ?? ''));
    $js = trim((string) ($in['jam_selesai'] ?? ''));
    if ($mode !== 'beberapa_hari') $b = $a;
    elseif ($b === '') $g['selesai'] = 'Tanggal akhir wajib diisi untuk beberapa hari.';
    if ($mode === 'jam') {
        if (!dispensasi_jam_valid($jm)) $g['jam_mulai'] = 'Jam mulai tidak valid (HH:MM).';
        if (!dispensasi_jam_valid($js)) $g['jam_selesai'] = 'Jam selesai tidak valid (HH:MM).';
        if (!$g && $js <= $jm) $g['jam_selesai'] = 'Jam selesai harus setelah jam mulai pada tanggal yang sama.';
    } else { $jm = $js = null; }
    return ['galat' => $g, 'data' => ['mode_durasi' => $mode, 'mulai' => $a, 'selesai' => $b, 'jam_mulai' => $jm, 'jam_selesai' => $js]];
}

/** Interval [mulai,selesai); dua kegiatan boleh bersambung tanpa beririsan. */
function dispensasi_beririsan(array $baru, array $lama): bool
{
    if ($baru['mulai'] > $lama['tanggal_selesai'] || $baru['selesai'] < $lama['tanggal_mulai']) return false;
    if (($lama['jenis'] ?? '') !== 'dispensasi' || ($baru['mode_durasi'] ?? '') !== 'jam' || ($lama['mode_durasi'] ?? '') !== 'jam') return true;
    return $baru['jam_mulai'] < substr((string) $lama['jam_selesai'], 0, 5) && $baru['jam_selesai'] > substr((string) $lama['jam_mulai'], 0, 5);
}

function dispensasi_teks_durasi(array $r): string
{
    if (($r['jenis'] ?? '') !== 'dispensasi') return izin_teks_rentang($r['tanggal_mulai'], $r['tanggal_selesai']);
    $teks = izin_teks_rentang($r['tanggal_mulai'], $r['tanggal_selesai']);
    return $teks . (($r['mode_durasi'] ?? '') === 'jam'
        ? ' · ' . substr((string) $r['jam_mulai'], 0, 5) . '–' . substr((string) $r['jam_selesai'], 0, 5) . ' ' . portal_zona_label()
        : ' · ' . (($r['mode_durasi'] ?? '') === 'beberapa_hari' ? 'beberapa hari (inklusif)' : 'satu hari penuh'));
}

/** Disetujui pemilik: hanya dispensasi sehari/beberapa hari membebaskan Alpa otomatis. */
function dispensasi_bebas_alpa(array $r): bool
{
    return ($r['jenis'] ?? '') === 'dispensasi' && in_array($r['mode_durasi'] ?? '', ['sehari', 'beberapa_hari'], true);
}

function dispensasi_setujui(int $id, array $admin, string $catatan): array
{
    $db = getDB();
    try {
        $db->beginTransaction();
        $st = $db->prepare('SELECT p.*, s.kelas, s.status siswa_status FROM izin_pengajuan p JOIN siswa s ON s.id=p.siswa_id WHERE p.id=:id FOR UPDATE');
        $st->execute([':id' => $id]); $r = $st->fetch();
        if (!$r || $r['jenis'] !== 'dispensasi' || $r['status'] !== 'menunggu' || $r['siswa_status'] !== 'aktif') {
            $db->rollBack(); return ['ok' => false, 'pesan' => 'Pengajuan tidak tersedia, sudah diproses, atau siswa nonaktif.'];
        }
        $hari = $db->prepare("INSERT INTO izin_hari (pengajuan_id,tanggal,hasil,kelas_dispensasi) VALUES (:p,:t,'diterapkan',:k)");
        $hapusAlpa = $db->prepare("DELETE FROM presensi WHERE siswa_id=:s AND tanggal=:t AND status='ALPA' AND metode='OTOMATIS'");
        $n = 0;
        foreach (izin_rentang($r['tanggal_mulai'], $r['tanggal_selesai']) as $t) {
            if (!jadwal_hari($t, (string) $r['kelas'])['efektif']) continue;
            $snapshot = $db->prepare('SELECT kelas_saat_presensi FROM presensi WHERE siswa_id=:s AND tanggal=:t');
            $snapshot->execute([':s' => $r['siswa_id'], ':t' => $t]);
            $hari->execute([':p' => $id, ':t' => $t, ':k' => $snapshot->fetchColumn() ?: $r['kelas']]); $n++;
            if (dispensasi_bebas_alpa($r)) $hapusAlpa->execute([':s' => $r['siswa_id'], ':t' => $t]);
        }
        if (!$n) { $db->rollBack(); return ['ok' => false, 'pesan' => 'Tidak ada hari efektif untuk kelas siswa pada rentang ini.']; }
        $db->prepare("UPDATE izin_pengajuan SET status='disetujui',diproses_oleh=:a,diproses_nama=:n,diproses_at=NOW(),catatan_petugas=:c,hari_diterapkan=:h WHERE id=:id")
           ->execute([':a' => $admin['id'] ?? null, ':n' => izin_nama_admin($admin), ':c' => izin_catatan($catatan) ?: null, ':h' => $n, ':id' => $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('dispensasi_setujui: ' . $e->getMessage());
        return ['ok' => false, 'pesan' => 'Gagal menyetujui dispensasi (kesalahan server).'];
    }
    portal_notif_tambah((int) $r['siswa_id'], 'izin_disetujui', 'Dispensasi disetujui', 'Dispensasi ' . dispensasi_teks_durasi($r) . ' disetujui.' . ($catatan !== '' ? ' Catatan: ' . izin_catatan($catatan) : ''), 'izin/' . $id);
    return ['ok' => true, 'diterapkan' => $n, 'dilewati' => 0, 'siswa_id' => (int) $r['siswa_id'], 'jenis' => 'dispensasi', 'rentang' => dispensasi_teks_durasi($r)];
}

function dispensasi_cabut(int $id, array $admin, string $catatan): array
{
    $db = getDB();
    try {
        $db->beginTransaction();
        $st = $db->prepare('SELECT * FROM izin_pengajuan WHERE id=:id FOR UPDATE'); $st->execute([':id' => $id]); $r = $st->fetch();
        if (!$r || $r['jenis'] !== 'dispensasi' || $r['status'] !== 'disetujui') { $db->rollBack(); return ['ok' => false, 'pesan' => 'Dispensasi sudah diproses atau tidak ditemukan.']; }
        $db->prepare("UPDATE izin_pengajuan SET status='dicabut',diproses_oleh=:a,diproses_nama=:n,diproses_at=NOW(),catatan_petugas=:c WHERE id=:id")
           ->execute([':a' => $admin['id'] ?? null, ':n' => izin_nama_admin($admin), ':c' => $catatan, ':id' => $id]);
        if (dispensasi_bebas_alpa($r)) {
            // Evaluasi kebijakan yang SAMA dengan auto-Alpa: hari efektif, lewat cutoff,
            // siswa aktif & sudah terdaftar, ada scan asli pada hari itu, tanpa presensi asli/pembebasan lain.
            $qHari = $db->prepare("SELECT tanggal,kelas_dispensasi FROM izin_hari WHERE pengajuan_id=:id AND hasil<>'dicabut'");
            $qHari->execute([':id' => $id]);
            $alpa = $db->prepare("INSERT IGNORE INTO presensi (siswa_id,kelas_saat_presensi,tanggal,jam_masuk,status,metode)
                SELECT s.id,:k,:t,'00:00:00','ALPA','OTOMATIS' FROM siswa s
                WHERE s.id=:s AND s.status='aktif' AND DATE(s.created_at)<=:t2
                AND NOT EXISTS (SELECT 1 FROM presensi x WHERE x.siswa_id=s.id AND x.tanggal=:t3)
                AND EXISTS (SELECT 1 FROM presensi x WHERE x.tanggal=:t4 AND x.metode NOT IN ('PENGAJUAN','OTOMATIS'))
                AND NOT EXISTS (SELECT 1 FROM izin_pengajuan i WHERE i.siswa_id=s.id AND i.status='disetujui'
                    AND i.tanggal_mulai<=:t5 AND i.tanggal_selesai>=:t6
                    AND (i.jenis IN ('izin','sakit') OR (i.jenis='dispensasi' AND i.mode_durasi IN ('sehari','beberapa_hari'))))");
            foreach ($qHari->fetchAll() as $h) {
                $t = $h['tanggal']; $kelas = (string) $h['kelas_dispensasi'];
                if ($t > date('Y-m-d') || !is_hari_efektif($t, $kelas)) continue;
                if ($t === date('Y-m-d') && !absen_sudah_ditutup(null, $t, $kelas)) continue;
                $alpa->execute([':s' => $r['siswa_id'], ':k' => $kelas, ':t' => $t, ':t2' => $t, ':t3' => $t, ':t4' => $t, ':t5' => $t, ':t6' => $t]);
            }
        }
        $db->prepare("UPDATE izin_hari SET hasil='dicabut' WHERE pengajuan_id=:id")->execute([':id' => $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('dispensasi_cabut: ' . $e->getMessage()); return ['ok' => false, 'pesan' => 'Pencabutan dispensasi gagal.'];
    }
    portal_notif_tambah((int) $r['siswa_id'], 'izin_dicabut', 'Dispensasi dicabut', 'Dispensasi ' . dispensasi_teks_durasi($r) . ' dicabut. Alasan: ' . $catatan, 'izin/' . $id);
    return ['ok' => true, 'dicabut' => (int) $r['hari_diterapkan'], 'siswa_id' => (int) $r['siswa_id']];
}

/** Satu baris per kegiatan/hari efektif yang disetujui; presensi asli tetap terpisah. */
function dispensasi_daftar_hari(string $awal, string $akhir, int $siswaId = 0, string $kelas = '', string $jurusan = ''): array
{
    if (!izin_siapkan_tabel()) return [];
    $p = [':a' => $awal, ':b' => $akhir];
    $where = '';
    if ($siswaId > 0) { $where .= ' AND p.siswa_id=:s'; $p[':s'] = $siswaId; }
    if ($kelas !== '') { $where .= ' AND h.kelas_dispensasi=:k'; $p[':k'] = $kelas; }
    if ($jurusan !== '') { $where .= ' AND s.jurusan=:jr'; $p[':jr'] = $jurusan; }
    $q = getDB()->prepare("SELECT p.*, h.tanggal, h.kelas_dispensasi, s.nama, s.nisn, s.jurusan
        FROM izin_pengajuan p JOIN izin_hari h ON h.pengajuan_id=p.id JOIN siswa s ON s.id=p.siswa_id
        WHERE p.jenis='dispensasi' AND p.status='disetujui' AND h.hasil<>'dicabut' AND h.tanggal BETWEEN :a AND :b $where
        ORDER BY h.tanggal, h.kelas_dispensasi, s.nama, p.jam_mulai, p.id");
    $q->execute($p); return $q->fetchAll();
}

function dispensasi_tabel_laporan(array $daftar): void
{
    echo '<section class="mt-3"><h3>Dispensasi disetujui (terpisah dari hitungan hadir/izin/sakit)</h3>';
    if (!$daftar) { echo '<p>Tidak ada dispensasi pada filter ini.</p></section>'; return; }
    echo '<div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Tanggal</th><th>Nama / Kelas</th><th>Durasi</th><th>Kegiatan</th></tr></thead><tbody>';
    foreach ($daftar as $r) echo '<tr><td>' . e(tgl_indo_pendek($r['tanggal'])) . '</td><td>' . e($r['nama']) . ' / ' . e($r['kelas_dispensasi']) . '</td><td>' . e(dispensasi_teks_durasi($r)) . '</td><td>' . e($r['alasan']) . '</td></tr>';
    echo '</tbody></table></div><p>Daftar kegiatan tidak dijumlahkan dengan kehadiran; siswa yang tetap hadir dapat tercatat pada keduanya.</p></section>';
}
