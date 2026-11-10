<?php
/**
 * AGENDA SEKOLAH — jadwal absensi sesuai kegiatan, agar siswa tidak tercatat Alpa secara keliru.
 *
 *   Hari libur / tanggal merah ........ presensi ditutup, TIDAK menghitung Alpa
 *   Ujian madrasah .................... mengikuti jadwal absensi ujian (jam buka / batas terlambat / batas absen sendiri)
 *   Kegiatan sekolah di luar kelas .... menggunakan jadwal khusus (jam sendiri)
 *
 * Agenda berlaku untuk semua kelas atau kelas tertentu. Satu pintu: jadwal_hari($tanggal, $kelas) — dipakai oleh
 * scan presensi, penutupan absen, penentuan terlambat, dan Alpa otomatis. Prioritas bila agenda bertumpuk:
 * (1) libur selalu menang, (2) agenda khusus kelas didahulukan dari agenda semua kelas, (3) ujian di atas kegiatan,
 * (4) yang terbaru menang.
 * Ujian/kegiatan juga membuka presensi di hari yang biasanya non-efektif (mis. ujian di hari Sabtu).
 *
 * Tabel dibuat otomatis; SQL manual: database/migration_agenda_sekolah.sql
 */

const AGENDA_JENIS = [
    'libur' => [
        'label' => 'Hari libur nasional / tanggal merah',
        'singkat' => 'Libur',
        'efek' => 'Tidak menghitung Alpa (presensi ditutup)',
        'ikon' => 'calendar-x',
        'warna' => 'danger',
        'urut' => 3,
    ],
    'ujian' => [
        'label' => 'Ujian madrasah',
        'singkat' => 'Ujian',
        'efek' => 'Mengikuti jadwal absensi ujian',
        'ikon' => 'pencil-square',
        'warna' => 'primary',
        'urut' => 2,
    ],
    'kegiatan' => [
        'label' => 'Kegiatan sekolah di luar kelas',
        'singkat' => 'Kegiatan',
        'efek' => 'Menggunakan jadwal khusus',
        'ikon' => 'geo-alt',
        'warna' => 'warning',
        'urut' => 1,
    ],
];
const AGENDA_JUDUL_MIN = 3;
const AGENDA_JUDUL_MAKS = 120;
const AGENDA_DURASI_MAKS_HARI = 120;

function agenda_siapkan_tabel(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS agenda_sekolah (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            judul VARCHAR(120) NOT NULL,
            jenis ENUM('libur','ujian','kegiatan') NOT NULL,
            tanggal_mulai DATE NOT NULL,
            tanggal_selesai DATE NOT NULL,
            kelas VARCHAR(600) NULL,
            jam_buka TIME NULL,
            batas_terlambat TIME NULL,
            batas_absen TIME NULL,
            catatan VARCHAR(300) NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            dibuat_oleh VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_agenda_tanggal (tanggal_mulai, tanggal_selesai),
            KEY idx_agenda_aktif (aktif)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('agenda_siapkan_tabel: ' . $e->getMessage());
        return $ok = false;
    }
}

/** Semua agenda aktif (di-cache per request). Tidak pernah melempar error: tabel belum ada = tidak ada agenda. */
function agenda_semua(bool $segarkan = false): array
{
    static $cache = null;
    if ($segarkan) $cache = null;
    if ($cache !== null) return $cache;
    try {
        $cache = getDB()->query('SELECT * FROM agenda_sekolah WHERE aktif = 1 ORDER BY id DESC')->fetchAll();
    } catch (Throwable $e) {
        $cache = [];
        if (agenda_siapkan_tabel()) {
            try {
                $cache = getDB()->query('SELECT * FROM agenda_sekolah WHERE aktif = 1 ORDER BY id DESC')->fetchAll();
            } catch (Throwable $e2) {
                $cache = [];
            }
        }
    }
    return $cache;
}

/** @return string[] daftar kelas dari kolom CSV (kosong = semua kelas) */
function agenda_kelas_daftar(array $row): array
{
    $raw = trim((string) ($row['kelas'] ?? ''));
    if ($raw === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($k) => $k !== ''));
}

/** Waktu 'HH:MM' / 'HH:MM:SS' -> 'HH:MM:SS'; selain itu $default. */
function agenda_waktu($t, string $default = ''): string
{
    $t = trim((string) $t);
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $t)) return substr($t . ':00', 0, 8);
    return $default;
}

/**
 * Agenda pemenang untuk tanggal & kelas tertentu (atau null).
 * $kelas null = pertanyaan "global": hanya agenda untuk SEMUA kelas yang dihitung.
 */
function agenda_untuk(string $tanggal, ?string $kelas = null): ?array
{
    $menang = null;
    $skorMenang = [-1, -1, -1, -1];
    foreach (agenda_semua() as $a) {
        if ($tanggal < $a['tanggal_mulai'] || $tanggal > $a['tanggal_selesai']) continue;
        $daftar = agenda_kelas_daftar($a);
        $khusus = $daftar ? 1 : 0;
        if ($khusus) {
            if ($kelas === null || !in_array($kelas, $daftar, true)) continue;
        }
        // Prioritas: libur selalu menang (aman: tidak ada Alpa keliru) > agenda khusus kelas > ujian di atas kegiatan > yang terbaru.
        $skor = [$a['jenis'] === 'libur' ? 1 : 0, $khusus, AGENDA_JENIS[$a['jenis']]['urut'] ?? 0, (int) $a['id']];
        if ($skor > $skorMenang) { $skorMenang = $skor; $menang = $a; }
    }
    return $menang;
}

/** Apakah ada agenda ujian/kegiatan (untuk kelas mana pun) yang membuka presensi pada tanggal ini? Dipakai halaman scan agar tidak menutup kamera di hari non-efektif bila ada kelas yang jadwalnya terbuka. */
function agenda_ada_pembukaan(string $tanggal): bool
{
    foreach (agenda_semua() as $a) {
        if ($a['jenis'] !== 'libur' && $tanggal >= $a['tanggal_mulai'] && $tanggal <= $a['tanggal_selesai']) return true;
    }
    return false;
}

/** Apakah ada agenda (jenis apa pun) yang berlaku untuk kelas TERTENTU saja pada tanggal ini? Untuk efisiensi job Alpa. */
function agenda_ada_khusus_kelas(string $tanggal): bool
{
    foreach (agenda_semua() as $a) {
        if ($tanggal >= $a['tanggal_mulai'] && $tanggal <= $a['tanggal_selesai'] && agenda_kelas_daftar($a)) return true;
    }
    return false;
}

/**
 * Jadwal absensi efektif untuk tanggal & kelas.
 * @return array{efektif:bool,jenis:string,agenda:?array,judul:string,ket:string,jam_buka:string,batas_terlambat:string,batas_absen:string}
 *   jenis: normal | mingguan (hari non-efektif menurut Pengaturan) | libur | ujian | kegiatan
 */
function jadwal_hari(?string $tanggal = null, ?string $kelas = null): array
{
    $tanggal = $tanggal ?: date('Y-m-d');
    $s = get_settings();
    $buka = agenda_waktu($s['jam_masuk'] ?? '', '');
    $terlambat = agenda_waktu($s['batas_terlambat'] ?? '', '07:15:00');
    $absen = agenda_waktu($s['batas_absen'] ?? '', '10:00:00');

    $hasil = [
        'efektif' => in_array((int) date('N', strtotime($tanggal)), get_hari_efektif(), true),
        'jenis' => 'normal', 'agenda' => null, 'judul' => '', 'ket' => '',
        'jam_buka' => $buka, 'batas_terlambat' => $terlambat, 'batas_absen' => $absen,
    ];
    if (!$hasil['efektif']) $hasil['jenis'] = 'mingguan';

    $a = agenda_untuk($tanggal, $kelas);
    if (!$a) return $hasil;

    $hasil['agenda'] = $a;
    $hasil['jenis'] = $a['jenis'];
    $hasil['judul'] = (string) $a['judul'];
    $hasil['ket'] = AGENDA_JENIS[$a['jenis']]['singkat'] . ': ' . $a['judul'];
    if ($a['jenis'] === 'libur') {
        $hasil['efektif'] = false;
        return $hasil;
    }
    $hasil['efektif'] = true;                                         // ujian/kegiatan membuka presensi walau hari non-efektif
    $hasil['jam_buka'] = agenda_waktu($a['jam_buka'] ?? '', $buka);
    $hasil['batas_terlambat'] = agenda_waktu($a['batas_terlambat'] ?? '', $terlambat);
    $hasil['batas_absen'] = agenda_waktu($a['batas_absen'] ?? '', $absen);
    return $hasil;
}

/** Ringkasan agenda untuk API/UI (null bila hari biasa). */
function agenda_ringkas(?string $tanggal = null, ?string $kelas = null): ?array
{
    $j = jadwal_hari($tanggal, $kelas);
    if (!$j['agenda']) return null;
    return ['jenis' => $j['jenis'], 'label' => AGENDA_JENIS[$j['jenis']]['singkat'], 'judul' => $j['judul'], 'efek' => AGENDA_JENIS[$j['jenis']]['efek']];
}

/** Semua jam batas absen yang berlaku di tanggal itu (default + agenda yang menyentuh tanggal tsb), untuk job Alpa. @return string[] */
function agenda_batas_hari(string $tanggal): array
{
    $j = jadwal_hari($tanggal, null);
    $set = [$j['batas_absen']];
    foreach (agenda_semua() as $a) {
        if ($a['jenis'] === 'libur') continue;
        if ($tanggal < $a['tanggal_mulai'] || $tanggal > $a['tanggal_selesai']) continue;
        $set[] = agenda_waktu($a['batas_absen'] ?? '', $j['batas_absen']);
    }
    $set = array_values(array_unique($set));
    sort($set);
    return $set;
}

/** Daftar kelas aktif untuk pilihan agenda. @return string[] */
function agenda_daftar_kelas(): array
{
    return getDB()->query("SELECT DISTINCT kelas FROM siswa WHERE status = 'aktif' AND kelas <> '' ORDER BY kelas")->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Validasi isian agenda.
 * @return array{galat:?string,data:array}
 */
function agenda_validasi(array $in): array
{
    $judul = mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) ($in['judul'] ?? ''))), 0, AGENDA_JUDUL_MAKS);
    $jenis = (string) ($in['jenis'] ?? '');
    $mulai = (string) ($in['tanggal_mulai'] ?? '');
    $selesai = (string) ($in['tanggal_selesai'] ?? '') !== '' ? (string) $in['tanggal_selesai'] : $mulai;
    $catatan = mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) ($in['catatan'] ?? ''))), 0, 300);
    $semua = ($in['cakupan'] ?? 'semua') !== 'kelas';
    $data = ['judul' => $judul, 'jenis' => $jenis, 'tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai, 'kelas' => null,
             'jam_buka' => null, 'batas_terlambat' => null, 'batas_absen' => null, 'catatan' => $catatan !== '' ? $catatan : null];
    $err = fn(string $m) => ['galat' => $m, 'data' => $data];

    if (mb_strlen($judul) < AGENDA_JUDUL_MIN) return $err('Nama agenda wajib diisi (minimal ' . AGENDA_JUDUL_MIN . ' karakter).');
    if (!isset(AGENDA_JENIS[$jenis])) return $err('Pilih jenis agenda.');
    foreach (['mulai' => $mulai, 'selesai' => $selesai] as $nama => $t) {
        $d = DateTime::createFromFormat('Y-m-d', $t);
        if (!$d || $d->format('Y-m-d') !== $t) return $err('Tanggal ' . $nama . ' tidak valid.');
    }
    if ($selesai < $mulai) return $err('Tanggal selesai tidak boleh sebelum tanggal mulai.');
    if ((strtotime($selesai) - strtotime($mulai)) / 86400 + 1 > AGENDA_DURASI_MAKS_HARI) return $err('Rentang agenda maksimal ' . AGENDA_DURASI_MAKS_HARI . ' hari. Pecah menjadi beberapa agenda.');

    if (!$semua) {
        $pilih = array_values(array_unique(array_filter(array_map(fn($k) => trim((string) $k), (array) ($in['kelas'] ?? [])), fn($k) => $k !== '')));
        if (!$pilih) return $err('Pilih minimal satu kelas, atau ubah cakupan menjadi "Semua kelas".');
        $ada = agenda_daftar_kelas();
        foreach ($pilih as $k) if (!in_array($k, $ada, true)) return $err('Kelas "' . $k . '" tidak dikenal.');
        $csv = implode(',', $pilih);
        if (mb_strlen($csv) > 600) return $err('Terlalu banyak kelas dipilih. Gunakan cakupan "Semua kelas" atau pecah agenda.');
        $data['kelas'] = $csv;
    }

    if ($jenis !== 'libur') {
        $buka = agenda_waktu($in['jam_buka'] ?? '');
        $terlambat = agenda_waktu($in['batas_terlambat'] ?? '');
        $absen = agenda_waktu($in['batas_absen'] ?? '');
        if ($buka === '' || $terlambat === '' || $absen === '') return $err('Isi jam presensi dibuka, batas terlambat, dan batas absen (format JJ:MM).');
        if (!($buka <= $terlambat && $terlambat <= $absen)) return $err('Urutan jam harus: presensi dibuka ≤ batas terlambat ≤ batas absen.');
        $data['jam_buka'] = $buka; $data['batas_terlambat'] = $terlambat; $data['batas_absen'] = $absen;
    }
    return ['galat' => null, 'data' => $data];
}

/** Agenda lain yang bertumpuk (tanggal & cakupan kelas beririsan) — untuk peringatan di UI. @return array[] */
function agenda_bentrok(array $data, ?int $kecualiId = null): array
{
    $kelasBaru = $data['kelas'] ? array_filter(explode(',', (string) $data['kelas'])) : [];
    $hasil = [];
    foreach (agenda_semua(true) as $a) {
        if ($kecualiId !== null && (int) $a['id'] === $kecualiId) continue;
        if ($a['tanggal_selesai'] < $data['tanggal_mulai'] || $a['tanggal_mulai'] > $data['tanggal_selesai']) continue;
        $kelasLama = agenda_kelas_daftar($a);
        if ($kelasBaru && $kelasLama && !array_intersect($kelasBaru, $kelasLama)) continue;
        $hasil[] = $a;
    }
    return $hasil;
}

/** Simpan (id null = baru). @return array{ok:bool,pesan?:string,id?:int} */
function agenda_simpan(?int $id, array $data, array $admin): array
{
    if (!agenda_siapkan_tabel()) return ['ok' => false, 'pesan' => 'Tabel agenda belum bisa dibuat. Jalankan database/migration_agenda_sekolah.sql.'];
    $db = getDB();
    $nama = izin_nama_admin_aman($admin);
    try {
        if ($id === null) {
            $db->prepare('INSERT INTO agenda_sekolah (judul, jenis, tanggal_mulai, tanggal_selesai, kelas, jam_buka, batas_terlambat, batas_absen, catatan, dibuat_oleh)
                          VALUES (:j, :je, :m, :s, :k, :b, :t, :a, :c, :o)')
               ->execute([':j' => $data['judul'], ':je' => $data['jenis'], ':m' => $data['tanggal_mulai'], ':s' => $data['tanggal_selesai'], ':k' => $data['kelas'],
                          ':b' => $data['jam_buka'], ':t' => $data['batas_terlambat'], ':a' => $data['batas_absen'], ':c' => $data['catatan'], ':o' => $nama]);
            $id = (int) $db->lastInsertId();
        } else {
            $u = $db->prepare('UPDATE agenda_sekolah SET judul=:j, jenis=:je, tanggal_mulai=:m, tanggal_selesai=:s, kelas=:k, jam_buka=:b, batas_terlambat=:t, batas_absen=:a, catatan=:c WHERE id=:id');
            $u->execute([':j' => $data['judul'], ':je' => $data['jenis'], ':m' => $data['tanggal_mulai'], ':s' => $data['tanggal_selesai'], ':k' => $data['kelas'],
                         ':b' => $data['jam_buka'], ':t' => $data['batas_terlambat'], ':a' => $data['batas_absen'], ':c' => $data['catatan'], ':id' => $id]);
        }
        agenda_semua(true);
        return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) {
        error_log('agenda_simpan: ' . $e->getMessage());
        return ['ok' => false, 'pesan' => 'Gagal menyimpan agenda (kesalahan server).'];
    }
}

function agenda_hapus(int $id): array
{
    try {
        $st = getDB()->prepare('SELECT * FROM agenda_sekolah WHERE id = :id');
        $st->execute([':id' => $id]);
        $a = $st->fetch();
        if (!$a) return ['ok' => false, 'pesan' => 'Agenda tidak ditemukan.'];
        getDB()->prepare('DELETE FROM agenda_sekolah WHERE id = :id')->execute([':id' => $id]);
        agenda_semua(true);
        return ['ok' => true, 'agenda' => $a];
    } catch (Throwable $e) {
        error_log('agenda_hapus: ' . $e->getMessage());
        return ['ok' => false, 'pesan' => 'Gagal menghapus agenda.'];
    }
}

/**
 * Hapus catatan ALPA otomatis (bukan yang dicatat/diubah petugas) pada rentang tanggal & kelas — dipakai saat
 * hari libur dijadwalkan terlambat setelah Alpa sudah terlanjur tercatat.
 */
function agenda_hapus_alpa_otomatis(string $mulai, string $selesai, ?string $kelasCsv): int
{
    $sql = "DELETE FROM presensi WHERE tanggal BETWEEN :m AND :s AND status = 'ALPA' AND metode = 'OTOMATIS'";
    $par = [':m' => $mulai, ':s' => $selesai];
    if ($kelasCsv !== null && $kelasCsv !== '') {
        $ph = [];
        foreach (array_values(array_filter(explode(',', $kelasCsv))) as $i => $k) { $ph[] = ':k' . $i; $par[':k' . $i] = $k; }
        $sql .= ' AND kelas_saat_presensi IN (' . implode(',', $ph) . ')';
    }
    $st = getDB()->prepare($sql);
    $st->execute($par);
    return $st->rowCount();
}

function izin_nama_admin_aman(array $admin): string
{
    if (function_exists('izin_nama_admin')) return (string) izin_nama_admin($admin);
    return (string) ($admin['nama'] ?? $admin['username'] ?? 'Admin');
}

/** Agenda terdekat (hari ini s.d. $hari ke depan) untuk dashboard. @return array[] */
function agenda_mendatang(int $hari = 30, int $batas = 5): array
{
    $hariIni = date('Y-m-d');
    $sampai = date('Y-m-d', strtotime("+$hari days"));
    $hasil = array_values(array_filter(agenda_semua(), fn($a) => $a['tanggal_selesai'] >= $hariIni && $a['tanggal_mulai'] <= $sampai));
    usort($hasil, fn($x, $y) => [$x['tanggal_mulai'], $x['id']] <=> [$y['tanggal_mulai'], $y['id']]);
    return array_slice($hasil, 0, $batas);
}

/** "29 Okt 2026" atau "29 Okt – 2 Nov 2026" */
function agenda_label_tanggal(string $mulai, string $selesai): string
{
    $bln = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $f = fn(string $t, bool $tahun) => (int) date('j', strtotime($t)) . ' ' . $bln[(int) date('n', strtotime($t))] . ($tahun ? ' ' . date('Y', strtotime($t)) : '');
    if ($mulai === $selesai) return $f($mulai, true);
    $sama = date('Y', strtotime($mulai)) === date('Y', strtotime($selesai));
    return $f($mulai, !$sama) . ' – ' . $f($selesai, true);
}
