<?php
/**
 * PENGAJUAN IZIN / SAKIT + NOTIFIKASI PORTAL
 *
 * Alur: siswa mengajukan (portal) -> petugas piket menyetujui/menolak (panel) -> bila disetujui,
 * hari efektif dalam rentang ditulis ke tabel `presensi` (status IZIN/SAKIT, metode 'PENGAJUAN'),
 * sehingga rekap, dashboard, dan laporan yang sudah ada otomatis ikut tanpa diubah.
 *
 * Aturan (konstanta di bawah): maksimal mundur 3 hari, maju 30 hari, rentang 14 hari, 5 pengajuan
 * menunggu sekaligus, satu pengajuan aktif per tanggal, sakit > 1 hari efektif wajib surat.
 * Bila siswa yang izinnya disetujui tetap presensi hadir, presensi HADIR yang berlaku (lihat
 * proses_simpan_presensi) dan izin hari itu ditandai "dipakai_hadir".
 */
require_once __DIR__ . '/portal.php';

const IZIN_MUNDUR_HARI         = 3;
const IZIN_MAJU_HARI           = 30;
const IZIN_MAKS_DURASI         = 14;     // hari kalender
const IZIN_MAKS_MENUNGGU       = 5;
const IZIN_ALASAN_MIN          = 10;
const IZIN_ALASAN_MAKS         = 500;
const IZIN_CATATAN_MAKS        = 300;
const IZIN_LAMPIRAN_MAKS       = 3145728; // 3 MB
const IZIN_RETENSI_LAMPIRAN    = 90;      // hari (usulan bawaan tombol pembersihan)
const IZIN_LAMPIRAN_TIPE       = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

const IZIN_STATUS_LABEL = ['menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak', 'dibatalkan' => 'Dibatalkan', 'dicabut' => 'Dicabut'];

// ---------------------------------------------------------------------
// TABEL (dibuat otomatis; SQL manual: database/migration_izin.sql)
// ---------------------------------------------------------------------
function izin_siapkan_tabel(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db = getDB();
        $ada = fn(string $t): bool => (bool) $db->query("SHOW TABLES LIKE '$t'")->fetchColumn();

        if (!$ada('izin_pengajuan')) {
            $db->exec("CREATE TABLE IF NOT EXISTS izin_pengajuan (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                siswa_id INT UNSIGNED NOT NULL,
                jenis ENUM('izin','sakit') NOT NULL,
                tanggal_mulai DATE NOT NULL,
                tanggal_selesai DATE NOT NULL,
                alasan VARCHAR(500) NOT NULL,
                lampiran VARCHAR(80) NULL,
                lampiran_nama VARCHAR(120) NULL,
                lampiran_tipe VARCHAR(40) NULL,
                lampiran_dihapus_at DATETIME NULL,
                status ENUM('menunggu','disetujui','ditolak','dibatalkan','dicabut') NOT NULL DEFAULT 'menunggu',
                diajukan_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                diproses_oleh INT UNSIGNED NULL,
                diproses_nama VARCHAR(100) NULL,
                diproses_at DATETIME NULL,
                catatan_petugas VARCHAR(300) NULL,
                hari_diterapkan SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_iz_status (status, diajukan_at),
                KEY idx_iz_siswa (siswa_id, tanggal_mulai),
                KEY idx_iz_tanggal (tanggal_mulai, tanggal_selesai),
                CONSTRAINT fk_izin_siswa FOREIGN KEY (siswa_id) REFERENCES siswa (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!$ada('izin_hari')) {
            $db->exec("CREATE TABLE IF NOT EXISTS izin_hari (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pengajuan_id INT UNSIGNED NOT NULL,
                tanggal DATE NOT NULL,
                hasil ENUM('diterapkan','sudah_ada','dipakai_hadir','dicabut') NOT NULL,
                UNIQUE KEY uniq_izin_hari (pengajuan_id, tanggal),
                CONSTRAINT fk_izin_hari FOREIGN KEY (pengajuan_id) REFERENCES izin_pengajuan (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!$ada('portal_notifikasi')) {
            $db->exec("CREATE TABLE IF NOT EXISTS portal_notifikasi (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                siswa_id INT UNSIGNED NOT NULL,
                jenis VARCHAR(30) NOT NULL,
                judul VARCHAR(120) NOT NULL,
                isi VARCHAR(400) NOT NULL,
                tautan VARCHAR(120) NULL,
                dibaca_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_notif_siswa (siswa_id, dibaca_at, id),
                CONSTRAINT fk_notif_siswa FOREIGN KEY (siswa_id) REFERENCES siswa (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        return $ok = true;
    } catch (Throwable $e) {
        error_log('izin_siapkan_tabel: ' . $e->getMessage());
        return $ok = false;
    }
}

// ---------------------------------------------------------------------
// TANGGAL
// ---------------------------------------------------------------------
function izin_tanggal_valid(string $s): ?string
{
    $s = trim($s);
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}

/** Semua tanggal (Y-m-d) dari $a sampai $b, inklusif. */
function izin_rentang(string $a, string $b): array
{
    $out = [];
    $d = new DateTime($a);
    $e = new DateTime($b);
    while ($d <= $e && count($out) < 400) { $out[] = $d->format('Y-m-d'); $d->modify('+1 day'); }
    return $out;
}

function izin_efektif_dalam(array $tanggal): array
{
    return array_values(array_filter($tanggal, fn($t) => is_hari_efektif($t)));
}

function izin_teks_rentang(string $a, string $b): string
{
    return $a === $b ? format_tanggal_indonesia($a) : format_tanggal_indonesia($a) . ' s.d. ' . format_tanggal_indonesia($b);
}

function izin_label_jenis(string $j): string
{
    return $j === 'sakit' ? 'Sakit' : 'Izin';
}

// ---------------------------------------------------------------------
// LAMPIRAN (surat dokter/orang tua). Disimpan dengan nama acak di uploads/izin/, TIDAK bisa
// dibuka langsung dari web; hanya lewat skrip yang memeriksa hak akses.
// ---------------------------------------------------------------------
function izin_dir(): string
{
    $dir = __DIR__ . '/../uploads/izin';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    return $dir;
}

/** Periksa file unggahan (belum dipindah). @return array{ok:bool,ada:bool,pesan?:string,mime?:string,ext?:string,asli?:string} */
function izin_periksa_lampiran(?array $f): array
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'ada' => false];
    $err = (int) $f['error'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['ok' => false, 'ada' => true, 'pesan' => 'Ukuran file melebihi batas server. Maksimal 3 MB.'];
    if ($err !== UPLOAD_ERR_OK) return ['ok' => false, 'ada' => true, 'pesan' => 'File gagal diunggah. Coba lagi.'];
    if (!is_uploaded_file((string) $f['tmp_name'])) return ['ok' => false, 'ada' => true, 'pesan' => 'File tidak valid.'];
    if ((int) $f['size'] > IZIN_LAMPIRAN_MAKS) return ['ok' => false, 'ada' => true, 'pesan' => 'Ukuran file maksimal 3 MB.'];
    if ((int) $f['size'] <= 0) return ['ok' => false, 'ada' => true, 'pesan' => 'File kosong.'];

    $mime = deteksi_mime_file((string) $f['tmp_name']);   // finfo -> mime_content_type -> magic bytes (lihat includes/compat.php)
    if (!isset(IZIN_LAMPIRAN_TIPE[$mime])) return ['ok' => false, 'ada' => true, 'pesan' => 'Jenis file tidak diizinkan. Gunakan foto (JPG/PNG/WEBP) atau PDF.'];
    if ($mime === 'application/pdf') {
        $h = (string) file_get_contents($f['tmp_name'], false, null, 0, 5);
        if ($h !== '%PDF-') return ['ok' => false, 'ada' => true, 'pesan' => 'File PDF tidak valid.'];
    } else {
        $info = @getimagesize($f['tmp_name']);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] > 12000 || $info[1] > 12000) return ['ok' => false, 'ada' => true, 'pesan' => 'File gambar tidak valid.'];
    }
    $asli = preg_replace('/[^\w .\-()]/u', '', basename((string) $f['name']));
    $asli = mb_substr(trim((string) $asli), 0, 80);
    if ($asli === '') $asli = 'lampiran.' . IZIN_LAMPIRAN_TIPE[$mime];
    return ['ok' => true, 'ada' => true, 'mime' => $mime, 'ext' => IZIN_LAMPIRAN_TIPE[$mime], 'asli' => $asli];
}

/** Pindahkan file yang sudah lolos izin_periksa_lampiran. @return string|null nama tersimpan */
function izin_pindah_lampiran(array $f, array $cek): ?string
{
    $nama = bin2hex(random_bytes(16)) . '.' . $cek['ext'];
    $tujuan = izin_dir() . '/' . $nama;
    if (!move_uploaded_file($f['tmp_name'], $tujuan)) return null;
    @chmod($tujuan, 0640);
    return $nama;
}

function izin_hapus_file(?string $nama): void
{
    if (!$nama || !preg_match('/^[0-9a-f]{32}\.(jpg|png|webp|pdf)$/', $nama)) return;
    $p = izin_dir() . '/' . $nama;
    if (is_file($p)) @unlink($p);
}

/** Kirim lampiran ke browser. Pemanggil WAJIB sudah memastikan hak akses. */
function izin_stream_lampiran(array $row): void
{
    $nama = (string) ($row['lampiran'] ?? '');
    $mime = (string) ($row['lampiran_tipe'] ?? '');
    if (!preg_match('/^[0-9a-f]{32}\.(jpg|png|webp|pdf)$/', $nama) || !isset(IZIN_LAMPIRAN_TIPE[$mime])) { http_response_code(404); echo 'Lampiran tidak ada.'; exit; }
    $p = izin_dir() . '/' . $nama;
    if (!is_file($p)) { http_response_code(404); echo 'Lampiran tidak ditemukan.'; exit; }

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($p));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
    $unduh = $mime === 'application/pdf';          // PDF diunduh (bukan dibuka inline) agar skrip di dalamnya tidak berjalan
    header('Content-Disposition: ' . ($unduh ? 'attachment' : 'inline') . '; filename="lampiran-' . (int) $row['id'] . '.' . IZIN_LAMPIRAN_TIPE[$mime] . '"');
    readfile($p);
    exit;
}

// ---------------------------------------------------------------------
// PENGAJUAN
// ---------------------------------------------------------------------
function izin_ambil(int $id): ?array
{
    $st = getDB()->prepare('SELECT p.*, s.nama, s.nis, s.nisn, s.kelas, s.jurusan, s.foto, s.status AS siswa_status
                            FROM izin_pengajuan p JOIN siswa s ON s.id = p.siswa_id WHERE p.id = :id');
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

function tgl_indo_pendek(string $t): string
{
    $b = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $x = strtotime($t);
    return $x ? date('j', $x) . ' ' . $b[(int) date('n', $x)] . ' ' . date('Y', $x) : $t;
}

/**
 * Validasi isi pengajuan. @return array{galat:array,data:array}
 * Kunci galat: jenis, mulai, selesai, alasan, lampiran, umum.
 */
function izin_validasi(int $siswaId, array $in): array
{
    $g = [];
    $db = getDB();

    $jenis = (string) ($in['jenis'] ?? '');
    if (!in_array($jenis, ['izin', 'sakit'], true)) $g['jenis'] = 'Pilih jenis pengajuan: izin atau sakit.';

    $mulai = izin_tanggal_valid((string) ($in['mulai'] ?? ''));
    if (!$mulai) $g['mulai'] = 'Tanggal mulai tidak valid.';
    $selRaw = trim((string) ($in['selesai'] ?? ''));
    $selesai = $selRaw === '' ? $mulai : izin_tanggal_valid($selRaw);
    if (!$selesai) $g['selesai'] = 'Tanggal selesai tidak valid.';

    $alasan = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) ($in['alasan'] ?? '')));
    $len = mb_strlen($alasan);
    if ($len < IZIN_ALASAN_MIN) $g['alasan'] = 'Alasan minimal ' . IZIN_ALASAN_MIN . ' karakter.';
    elseif ($len > IZIN_ALASAN_MAKS) $g['alasan'] = 'Alasan maksimal ' . IZIN_ALASAN_MAKS . ' karakter.';

    $efektif = [];
    if ($mulai && $selesai) {
        $batasAwal  = date('Y-m-d', strtotime('-' . IZIN_MUNDUR_HARI . ' days'));
        $batasAkhir = date('Y-m-d', strtotime('+' . IZIN_MAJU_HARI . ' days'));
        if ($selesai < $mulai) {
            $g['selesai'] = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
        } elseif ($mulai < $batasAwal) {
            $g['mulai'] = 'Pengajuan hanya bisa untuk maksimal ' . IZIN_MUNDUR_HARI . ' hari ke belakang.';
        } elseif ($selesai > $batasAkhir) {
            $g['selesai'] = 'Pengajuan hanya bisa untuk maksimal ' . IZIN_MAJU_HARI . ' hari ke depan.';
        } else {
            $rentang = izin_rentang($mulai, $selesai);
            if (count($rentang) > IZIN_MAKS_DURASI) {
                $g['selesai'] = 'Satu pengajuan maksimal ' . IZIN_MAKS_DURASI . ' hari. Pecah menjadi beberapa pengajuan.';
            } else {
                $efektif = izin_efektif_dalam($rentang);
                if (!$efektif) {
                    $g['mulai'] = 'Rentang tanggal itu tidak mencakup hari efektif sekolah (' . label_hari_efektif() . ').';
                } else {
                    $st = $db->prepare("SELECT tanggal FROM presensi WHERE siswa_id = :s AND tanggal BETWEEN :a AND :b AND metode <> 'PENGAJUAN' ORDER BY tanggal");
                    $st->execute([':s' => $siswaId, ':a' => $mulai, ':b' => $selesai]);
                    $sudah = $st->fetchAll(PDO::FETCH_COLUMN);
                    if ($sudah) $g['mulai'] = 'Anda sudah absen pada ' . tgl_indo_pendek($sudah[0]) . ', jadi tidak bisa mengajukan izin/sakit untuk tanggal itu. Pilih tanggal lain.';

                    $st = $db->prepare("SELECT COUNT(*) FROM izin_pengajuan WHERE siswa_id = :s AND status IN ('menunggu','disetujui')
                                        AND tanggal_mulai <= :b AND tanggal_selesai >= :a");
                    $st->execute([':s' => $siswaId, ':a' => $mulai, ':b' => $selesai]);
                    if ((int) $st->fetchColumn() > 0) $g['mulai'] = 'Pada rentang tanggal itu sudah ada pengajuan lain yang menunggu atau sudah disetujui.';
                }
            }
        }
    }

    $st = $db->prepare("SELECT COUNT(*) FROM izin_pengajuan WHERE siswa_id = :s AND status = 'menunggu'");
    $st->execute([':s' => $siswaId]);
    if ((int) $st->fetchColumn() >= IZIN_MAKS_MENUNGGU) $g['umum'] = 'Anda sudah memiliki ' . IZIN_MAKS_MENUNGGU . ' pengajuan yang menunggu. Tunggu diproses atau batalkan salah satunya.';

    return ['galat' => $g, 'data' => [
        'jenis' => $jenis, 'mulai' => $mulai, 'selesai' => $selesai, 'alasan' => $alasan,
        'efektif' => $efektif, 'lampiran_wajib' => ($jenis === 'sakit' && count($efektif) > 1),
    ]];
}

/** Ajukan izin/sakit. @return array{ok:bool,id?:int,galat?:array} */
function izin_buat(int $siswaId, array $in, ?array $file): array
{
    $db = getDB();
    $cek = izin_periksa_lampiran($file);
    $tersimpan = null;
    try {
        $db->beginTransaction();
        $db->prepare('SELECT id FROM siswa WHERE id = :id FOR UPDATE')->execute([':id' => $siswaId]);   // serialkan pengajuan per siswa

        $v = izin_validasi($siswaId, $in);
        $g = $v['galat']; $d = $v['data'];
        if (!$cek['ok']) $g['lampiran'] = $cek['pesan'];
        elseif ($d['lampiran_wajib'] && !$cek['ada']) $g['lampiran'] = 'Sakit lebih dari 1 hari wajib melampirkan surat dokter/keterangan (foto atau PDF).';
        if ($g) { $db->rollBack(); return ['ok' => false, 'galat' => $g]; }

        $asli = null; $tipe = null;
        if ($cek['ada']) {
            $tersimpan = izin_pindah_lampiran($file, $cek);
            if (!$tersimpan) { $db->rollBack(); return ['ok' => false, 'galat' => ['lampiran' => 'Lampiran gagal disimpan di server.']]; }
            $asli = $cek['asli']; $tipe = $cek['mime'];
        }
        $db->prepare('INSERT INTO izin_pengajuan (siswa_id, jenis, tanggal_mulai, tanggal_selesai, alasan, lampiran, lampiran_nama, lampiran_tipe)
                      VALUES (:s, :j, :a, :b, :al, :l, :ln, :lt)')
           ->execute([':s' => $siswaId, ':j' => $d['jenis'], ':a' => $d['mulai'], ':b' => $d['selesai'], ':al' => $d['alasan'], ':l' => $tersimpan, ':ln' => $asli, ':lt' => $tipe]);
        $id = (int) $db->lastInsertId();
        $db->commit();
        return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        izin_hapus_file($tersimpan);
        error_log('izin_buat: ' . $e->getMessage());
        return ['ok' => false, 'galat' => ['umum' => 'Terjadi kesalahan saat menyimpan pengajuan. Coba lagi.']];
    }
}

/** Siswa membatalkan pengajuannya sendiri selama masih menunggu. */
function izin_batalkan_siswa(int $id, int $siswaId): array
{
    $db = getDB();
    $row = izin_ambil($id);
    if (!$row || (int) $row['siswa_id'] !== $siswaId) return ['ok' => false, 'pesan' => 'Pengajuan tidak ditemukan.'];
    $u = $db->prepare("UPDATE izin_pengajuan SET status = 'dibatalkan', diproses_at = NOW(), diproses_nama = 'Siswa' WHERE id = :id AND siswa_id = :s AND status = 'menunggu'");
    $u->execute([':id' => $id, ':s' => $siswaId]);
    if ($u->rowCount() !== 1) return ['ok' => false, 'pesan' => 'Pengajuan sudah diproses petugas dan tidak bisa dibatalkan lagi.'];
    izin_hapus_file($row['lampiran']);
    $db->prepare('UPDATE izin_pengajuan SET lampiran = NULL, lampiran_tipe = NULL, lampiran_dihapus_at = NOW() WHERE id = :id AND lampiran IS NOT NULL')->execute([':id' => $id]);
    return ['ok' => true];
}

// ---------------------------------------------------------------------
// KEPUTUSAN PETUGAS
// ---------------------------------------------------------------------
function izin_nama_admin(array $admin): string
{
    return mb_substr((string) (($admin['nama'] ?? '') ?: ($admin['username'] ?? 'Petugas')), 0, 100);
}

function izin_catatan(string $c): string
{
    return mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $c)), 0, IZIN_CATATAN_MAKS);
}

/** Setujui + tulis presensi IZIN/SAKIT untuk hari efektif yang belum punya presensi. */
function izin_setujui(int $id, array $admin, string $catatan = ''): array
{
    $db = getDB();
    try {
        $db->beginTransaction();
        $st = $db->prepare('SELECT p.*, s.kelas, s.status AS siswa_status FROM izin_pengajuan p JOIN siswa s ON s.id = p.siswa_id WHERE p.id = :id FOR UPDATE');
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        if (!$r) { $db->rollBack(); return ['ok' => false, 'pesan' => 'Pengajuan tidak ditemukan.']; }
        if ($r['status'] !== 'menunggu') { $db->rollBack(); return ['ok' => false, 'pesan' => 'Pengajuan sudah diproses (status: ' . (IZIN_STATUS_LABEL[$r['status']] ?? $r['status']) . ').']; }
        if ($r['siswa_status'] !== 'aktif') { $db->rollBack(); return ['ok' => false, 'pesan' => 'Siswa sudah nonaktif.']; }

        $status = $r['jenis'] === 'sakit' ? 'SAKIT' : 'IZIN';
        $ins = $db->prepare("INSERT INTO presensi (siswa_id, kelas_saat_presensi, tanggal, jam_masuk, status, metode, created_at)
                             VALUES (:s, :k, :t, '00:00:00', :st, 'PENGAJUAN', NOW()) ON DUPLICATE KEY UPDATE id = id");
        $hari = $db->prepare('INSERT INTO izin_hari (pengajuan_id, tanggal, hasil) VALUES (:p, :t, :h) ON DUPLICATE KEY UPDATE hasil = VALUES(hasil)');
        $terapkan = 0; $lewati = 0;
        foreach (izin_efektif_dalam(izin_rentang($r['tanggal_mulai'], $r['tanggal_selesai'])) as $t) {
            $ins->execute([':s' => $r['siswa_id'], ':k' => $r['kelas'], ':t' => $t, ':st' => $status]);
            $baru = $ins->rowCount() === 1;
            $baru ? $terapkan++ : $lewati++;
            $hari->execute([':p' => $id, ':t' => $t, ':h' => $baru ? 'diterapkan' : 'sudah_ada']);
        }
        $db->prepare("UPDATE izin_pengajuan SET status = 'disetujui', diproses_oleh = :a, diproses_nama = :n, diproses_at = NOW(),
                      catatan_petugas = :c, hari_diterapkan = :h WHERE id = :id")
           ->execute([':a' => $admin['id'] ?? null, ':n' => izin_nama_admin($admin), ':c' => izin_catatan($catatan) ?: null, ':h' => $terapkan, ':id' => $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('izin_setujui: ' . $e->getMessage());
        return ['ok' => false, 'pesan' => 'Gagal menyetujui pengajuan (kesalahan server).'];
    }

    $isi = izin_label_jenis($r['jenis']) . ' ' . izin_teks_rentang($r['tanggal_mulai'], $r['tanggal_selesai']) . ' disetujui.';
    if (izin_catatan($catatan) !== '') $isi .= ' Catatan petugas: ' . izin_catatan($catatan);
    portal_notif_tambah((int) $r['siswa_id'], 'izin_disetujui', 'Pengajuan disetujui', $isi, 'izin/' . $id);
    return ['ok' => true, 'diterapkan' => $terapkan, 'dilewati' => $lewati, 'siswa_id' => (int) $r['siswa_id'], 'jenis' => $r['jenis'], 'rentang' => izin_teks_rentang($r['tanggal_mulai'], $r['tanggal_selesai'])];
}

function izin_tolak(int $id, array $admin, string $catatan): array
{
    $catatan = izin_catatan($catatan);
    if (mb_strlen($catatan) < 5) return ['ok' => false, 'pesan' => 'Alasan penolakan wajib diisi (minimal 5 karakter) agar siswa tahu sebabnya.'];
    $r = izin_ambil($id);
    if (!$r) return ['ok' => false, 'pesan' => 'Pengajuan tidak ditemukan.'];
    $u = getDB()->prepare("UPDATE izin_pengajuan SET status = 'ditolak', diproses_oleh = :a, diproses_nama = :n, diproses_at = NOW(), catatan_petugas = :c
                           WHERE id = :id AND status = 'menunggu'");
    $u->execute([':a' => $admin['id'] ?? null, ':n' => izin_nama_admin($admin), ':c' => $catatan, ':id' => $id]);
    if ($u->rowCount() !== 1) return ['ok' => false, 'pesan' => 'Pengajuan sudah diproses (status: ' . (IZIN_STATUS_LABEL[$r['status']] ?? $r['status']) . ').'];
    portal_notif_tambah((int) $r['siswa_id'], 'izin_ditolak', 'Pengajuan ditolak',
        izin_label_jenis($r['jenis']) . ' ' . izin_teks_rentang($r['tanggal_mulai'], $r['tanggal_selesai']) . ' ditolak. Alasan: ' . $catatan, 'izin/' . $id);
    return ['ok' => true, 'siswa_id' => (int) $r['siswa_id'], 'jenis' => $r['jenis'], 'rentang' => izin_teks_rentang($r['tanggal_mulai'], $r['tanggal_selesai'])];
}

/** Cabut persetujuan: hapus baris presensi PENGAJUAN yang dibuat oleh pengajuan ini (presensi asli tidak pernah disentuh). */
function izin_cabut(int $id, array $admin, string $catatan): array
{
    $catatan = izin_catatan($catatan);
    if (mb_strlen($catatan) < 5) return ['ok' => false, 'pesan' => 'Alasan pencabutan wajib diisi (minimal 5 karakter).'];
    $db = getDB();
    try {
        $db->beginTransaction();
        $st = $db->prepare('SELECT * FROM izin_pengajuan WHERE id = :id FOR UPDATE');
        $st->execute([':id' => $id]);
        $r = $st->fetch();
        if (!$r) { $db->rollBack(); return ['ok' => false, 'pesan' => 'Pengajuan tidak ditemukan.']; }
        if ($r['status'] !== 'disetujui') { $db->rollBack(); return ['ok' => false, 'pesan' => 'Hanya pengajuan yang sudah disetujui yang bisa dicabut.']; }

        $hari = $db->prepare("SELECT tanggal FROM izin_hari WHERE pengajuan_id = :p AND hasil = 'diterapkan'");
        $hari->execute([':p' => $id]);
        $hapus = $db->prepare("DELETE FROM presensi WHERE siswa_id = :s AND tanggal = :t AND metode = 'PENGAJUAN' AND status IN ('IZIN','SAKIT')");
        $dicabut = 0;
        foreach ($hari->fetchAll(PDO::FETCH_COLUMN) as $t) { $hapus->execute([':s' => $r['siswa_id'], ':t' => $t]); $dicabut += $hapus->rowCount(); }
        $db->prepare("UPDATE izin_hari SET hasil = 'dicabut' WHERE pengajuan_id = :p AND hasil = 'diterapkan'")->execute([':p' => $id]);
        $db->prepare("UPDATE izin_pengajuan SET status = 'dicabut', diproses_oleh = :a, diproses_nama = :n, diproses_at = NOW(), catatan_petugas = :c WHERE id = :id")
           ->execute([':a' => $admin['id'] ?? null, ':n' => izin_nama_admin($admin), ':c' => $catatan, ':id' => $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('izin_cabut: ' . $e->getMessage());
        return ['ok' => false, 'pesan' => 'Gagal mencabut persetujuan (kesalahan server).'];
    }
    portal_notif_tambah((int) $r['siswa_id'], 'izin_dicabut', 'Persetujuan dicabut',
        izin_label_jenis($r['jenis']) . ' ' . izin_teks_rentang($r['tanggal_mulai'], $r['tanggal_selesai']) . ' dicabut petugas. Alasan: ' . $catatan, 'izin/' . $id);
    return ['ok' => true, 'dicabut' => $dicabut, 'siswa_id' => (int) $r['siswa_id']];
}

// ---------------------------------------------------------------------
// RINGKASAN & PEMBERSIHAN
// ---------------------------------------------------------------------
/** Daftar pengajuan menunggu terbaru untuk lonceng notifikasi panel. */
function izin_menunggu_terbaru(int $batas = 6): array
{
    try {
        if (!izin_siapkan_tabel()) return [];
        $st = getDB()->query("SELECT p.id, p.jenis, p.tanggal_mulai, p.tanggal_selesai, p.diajukan_at, s.nama
            FROM izin_pengajuan p JOIN siswa s ON s.id = p.siswa_id
            WHERE p.status = 'menunggu' ORDER BY p.diajukan_at DESC, p.id DESC LIMIT " . max(1, min(20, $batas)));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('izin_menunggu_terbaru: ' . $e->getMessage());
        return [];
    }
}

function izin_menunggu_jumlah(): int
{
    static $n = null;
    if ($n !== null) return $n;
    try {
        if (!izin_siapkan_tabel()) return $n = 0;
        return $n = (int) getDB()->query("SELECT COUNT(*) FROM izin_pengajuan WHERE status = 'menunggu'")->fetchColumn();
    } catch (Throwable $e) {
        error_log('izin_menunggu_jumlah: ' . $e->getMessage());
        return $n = 0;
    }
}

/** Hapus FILE lampiran dari pengajuan yang sudah diproses lebih dari $hari hari (data pengajuan tetap). @return int jumlah file dihapus */
function izin_hapus_lampiran_lama(int $hari): int
{
    $hari = max(7, min(3650, $hari));
    $db = getDB();
    $st = $db->query("SELECT id, lampiran FROM izin_pengajuan WHERE lampiran IS NOT NULL AND status <> 'menunggu'
                      AND diproses_at < (NOW() - INTERVAL $hari DAY)");
    $n = 0;
    $u = $db->prepare('UPDATE izin_pengajuan SET lampiran = NULL, lampiran_tipe = NULL, lampiran_dihapus_at = NOW() WHERE id = :id');
    foreach ($st->fetchAll() as $r) { izin_hapus_file($r['lampiran']); $u->execute([':id' => $r['id']]); $n++; }
    return $n;
}

// ---------------------------------------------------------------------
// NOTIFIKASI PORTAL
// ---------------------------------------------------------------------
function portal_notif_tambah(int $siswaId, string $jenis, string $judul, string $isi, ?string $tautan = null): void
{
    try {
        $db = getDB();
        $db->prepare('INSERT INTO portal_notifikasi (siswa_id, jenis, judul, isi, tautan) VALUES (:s, :j, :ju, :i, :t)')
           ->execute([':s' => $siswaId, ':j' => mb_substr($jenis, 0, 30), ':ju' => mb_substr($judul, 0, 120), ':i' => mb_substr($isi, 0, 400), ':t' => $tautan]);
        // simpan maksimal 100 notifikasi terbaru per siswa
        $db->prepare('DELETE FROM portal_notifikasi WHERE siswa_id = :s AND id <= COALESCE((SELECT x.id FROM (SELECT id FROM portal_notifikasi WHERE siswa_id = :s2 ORDER BY id DESC LIMIT 1 OFFSET 100) x), 0)')
           ->execute([':s' => $siswaId, ':s2' => $siswaId]);
    } catch (Throwable $e) {
        error_log('portal_notif_tambah: ' . $e->getMessage());   // gagal notifikasi tidak boleh menggagalkan keputusan petugas
    }
}

function portal_notif_belum_dibaca(int $siswaId): int
{
    try {
        $st = getDB()->prepare('SELECT COUNT(*) FROM portal_notifikasi WHERE siswa_id = :s AND dibaca_at IS NULL');
        $st->execute([':s' => $siswaId]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function portal_notif_daftar(int $siswaId, int $batas = 50): array
{
    $st = getDB()->prepare('SELECT * FROM portal_notifikasi WHERE siswa_id = :s ORDER BY id DESC LIMIT ' . max(1, min(100, $batas)));
    $st->execute([':s' => $siswaId]);
    return $st->fetchAll();
}

function portal_notif_tandai_dibaca(int $siswaId): void
{
    getDB()->prepare('UPDATE portal_notifikasi SET dibaca_at = NOW() WHERE siswa_id = :s AND dibaca_at IS NULL')->execute([':s' => $siswaId]);
}
