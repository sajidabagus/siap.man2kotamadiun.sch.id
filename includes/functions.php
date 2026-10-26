<?php

/**
 * Fungsi-fungsi bantu umum yang dipakai di seluruh aplikasi.
 */

require_once __DIR__ . '/compat.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/keamanan.php';

// ---------------------------------------------------------------------
// OUTPUT & INPUT SAFETY
// ---------------------------------------------------------------------
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function clean(?string $value): string
{
    return trim($value ?? '');
}

// ---------------------------------------------------------------------
// PAGINATION
// ---------------------------------------------------------------------
define('DEFAULT_PAGE_SIZE', 10);

/**
 * Ambil nomor halaman aktif dari query string, minimal 1.
 * $paramName memungkinkan beberapa tabel dengan pagination berbeda di 1 halaman
 * (misalnya "page" utk tabel pertama, "page2" utk tabel kedua).
 */
/** Jumlah baris per halaman yang dipilih pengguna (?limit=...), hanya nilai yang diizinkan. */
const OPSI_PER_HALAMAN = [10, 20, 50, 100];
function get_page_size(): int
{
    $n = (int) ($_GET['limit'] ?? $_GET['per_page'] ?? DEFAULT_PAGE_SIZE);   // per_page = nama lama, tetap diterima
    return in_array($n, OPSI_PER_HALAMAN, true) ? $n : DEFAULT_PAGE_SIZE;
}

/**
 * Pilihan "[10 v] data per halaman". Form GET sendiri yang membawa filter aktif ($hidden)
 * dan halaman kembali ke 1 saat jumlahnya diubah.
 */
function render_per_page_field(int $current, bool $submitOnChange = true): string
{
    $html = '<label class="per-page"><select name="limit" class="form-select form-select-sm"'
        . ($submitOnChange ? ' onchange="this.form.submit()"' : '') . ' aria-label="Jumlah data per halaman">';
    foreach (OPSI_PER_HALAMAN as $o) {
        $html .= '<option value="' . $o . '"' . ($o === $current ? ' selected' : '') . '>' . $o . '</option>';
    }
    return $html . '</select><span>data per halaman</span></label>';
}

function render_per_page(int $current, array $hidden = []): string
{
    $html = '<form method="get" class="per-page-form">';
    $html .= hidden_modul();
    foreach ($hidden as $k => $v) {
        if ($v === '' || $v === null || in_array($k, ['page', 'limit', 'per_page', 'modul'], true)) continue;
        $html .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
    }
    return $html . render_per_page_field($current) . '</form>';
}

function get_current_page(string $paramName = 'page'): int
{
    $page = (int) ($_GET[$paramName] ?? 1);
    return max(1, $page);
}

/**
 * ROUTER MODUL: semua halaman admin dibuka lewat index.php?modul=nama_modul
 * Daftar putih (whitelist) modul => file di folder panel/.
 */
const DAFTAR_MODUL = [
    'dashboard',
    'siswa',
    'siswa_detail',
    'akun_siswa',
    'izin',
    'izin_detail',
    'izin_lampiran',
    'siswa_tambah',
    'siswa_edit',
    'siswa_hapus',
    'siswa_import',
    'master_kelas',
    'kenaikan_kelas',
    'pindah_kelas',
    'qr_siswa',
    'qr_print',
    'qr_generate',
    'qr_gerbang',
    'qr_kelas',
    'qr_kelas_print',
    'presensi',
    'presensi_export',
    'presensi_print',
    'rekap',
    'rekap_print',
    'device',
    'log_security',
    'log_aktivitas',
    'log_cetak',
    'pengaturan',
    'profil',
    'pengguna',
    'hak_akses',
    'papan',
];

// ---------------------------------------------------------------------
// ROLE & HAK AKSES
// Role "admin" selalu punya semua izin. Role lain (mis. operator) diatur lewat menu Hak Akses.
// ---------------------------------------------------------------------
const DAFTAR_ROLE = ['admin' => 'Administrator', 'operator' => 'Operator'];

/** Daftar izin per kelompok: kunci => keterangan. */
const DAFTAR_IZIN = [
    'Dashboard' => [
        'dashboard.lihat' => 'Melihat dashboard',
    ],
    'Data Siswa' => [
        'siswa.lihat'  => 'Melihat data siswa',
        'siswa.tambah' => 'Menambah siswa',
        'siswa.ubah'   => 'Mengubah data siswa',
        'siswa.hapus'  => 'Menghapus siswa',
        'siswa.impor'  => 'Import siswa (Excel/CSV)',
    ],
    'Pengajuan Izin' => [
        'izin.lihat'  => 'Melihat pengajuan izin/sakit siswa & lampiran surat (data kesehatan)',
        'izin.proses' => 'Menyetujui, menolak, mencabut pengajuan & menghapus lampiran lama',
    ],
    'Pindah Kelas' => [
        'pindah_kelas.lihat'  => 'Melihat pengajuan pindah kelas dari siswa',
        'pindah_kelas.proses' => 'Menyetujui atau menolak pengajuan pindah kelas',
    ],
    'Akun Siswa' => [
        'akun_siswa.lihat'  => 'Melihat daftar akun portal siswa',
        'akun_siswa.kelola' => 'Menerbitkan kode aktivasi, reset, blokir akun siswa & membuka/menutup portal',
    ],
    'Kelas' => [
        'kelas.lihat'     => 'Melihat Master Kelas',
        'kelas.ubah'      => 'Mengganti nama kelas',
        'kenaikan.proses' => 'Memproses Kenaikan Kelas',
    ],
    'QR Siswa' => [
        'qr_siswa.lihat'    => 'Melihat QR Siswa',
        'qr_siswa.cetak'    => 'Mencetak QR Siswa',
        'qr_siswa.generate' => 'Regenerate QR per siswa',
    ],
    'QR Gerbang' => [
        'qr_gerbang.lihat' => 'Melihat QR Gerbang',
    ],
    'QR Kelas' => [
        'qr_kelas.lihat'    => 'Melihat QR Kelas',
        'qr_kelas.cetak'    => 'Mencetak QR Kelas',
        'qr_kelas.generate' => 'Regenerate QR Kelas',
    ],
    'Presensi' => [
        'presensi.lihat'  => 'Melihat data presensi',
        'papan.lihat'     => 'Melihat Papan Kehadiran Langsung',
        'presensi.ekspor' => 'Ekspor presensi (CSV/Excel)',
        'presensi.cetak'  => 'Mencetak data presensi',
        'presensi.ubah'   => 'Mengubah / menambah status presensi siswa secara manual (tercatat di log, siswa diberi notifikasi)',
    ],
    'Rekap' => [
        'rekap.lihat' => 'Melihat rekap kehadiran',
        'rekap.cetak' => 'Mencetak rekap kehadiran',
    ],
    'Perangkat' => [
        'device.lihat'  => 'Melihat manajemen perangkat',
        'device.kelola' => 'Blokir, aktifkan, dan reset perangkat',
    ],
    'Log' => [
        'log_security.lihat'  => 'Melihat Log Keamanan',
        'log_aktivitas.lihat' => 'Melihat Log Aktivitas',
    ],
    'Sistem' => [
        'pengaturan.kelola' => 'Mengubah Pengaturan sistem',
        'pengguna.kelola'   => 'Mengelola pengguna & hak akses (khusus Administrator)',
    ],
];

/** Izin bawaan untuk role Operator saat tabel hak akses pertama kali dibuat. */
const IZIN_DEFAULT_OPERATOR = [
    'dashboard.lihat',
    'siswa.lihat',
    'siswa.tambah',
    'siswa.ubah',
    'pindah_kelas.lihat',
    'pindah_kelas.proses',
    'kelas.lihat',
    'qr_siswa.lihat',
    'qr_siswa.cetak',
    'qr_gerbang.lihat',
    'qr_kelas.lihat',
    'qr_kelas.cetak',
    'presensi.lihat',
    'presensi.ekspor',
    'presensi.cetak',
    'presensi.ubah',
    'rekap.lihat',
    'rekap.cetak',
    'device.lihat',
    'papan.lihat',
];

/** Izin yang dibutuhkan untuk membuka tiap modul (null = semua pengguna yang login). */
const MODUL_IZIN = [
    'dashboard' => 'dashboard.lihat',
    'siswa' => 'siswa.lihat',
    'siswa_detail' => 'siswa.lihat',
    'akun_siswa' => 'akun_siswa.lihat',
    'izin' => 'izin.lihat',
    'izin_detail' => 'izin.lihat',
    'izin_lampiran' => 'izin.lihat',
    'siswa_tambah' => 'siswa.tambah',
    'siswa_edit' => 'siswa.ubah',
    'siswa_hapus' => 'siswa.hapus',
    'siswa_import' => 'siswa.impor',
    'master_kelas' => 'kelas.lihat',
    'kenaikan_kelas' => 'kenaikan.proses',
    'pindah_kelas' => 'pindah_kelas.lihat',
    'qr_siswa' => 'qr_siswa.lihat',
    'qr_generate' => 'qr_siswa.lihat',
    'qr_print' => 'qr_siswa.cetak',
    'qr_gerbang' => 'qr_gerbang.lihat',
    'qr_kelas' => 'qr_kelas.lihat',
    'qr_kelas_print' => 'qr_kelas.cetak',
    'papan' => 'papan.lihat',
    'presensi' => 'presensi.lihat',
    'presensi_export' => 'presensi.ekspor',
    'presensi_print' => 'presensi.cetak',
    'rekap' => 'rekap.lihat',
    'rekap_print' => 'rekap.cetak',
    'device' => 'device.lihat',
    'log_security' => 'log_security.lihat',
    'log_aktivitas' => 'log_aktivitas.lihat',
    'pengaturan' => 'pengaturan.kelola',
    'pengguna' => 'pengguna.kelola',
    'hak_akses' => 'pengguna.kelola',
    'log_cetak' => null,
    'profil' => null,
];

/** "ABHISTA KURNIA AKBAR" -> "Abhista K. A." (untuk tampilan publik; nama lengkap tidak ditampilkan). */
function samarkan_nama(string $nama): string
{
    $kata = preg_split('/\s+/', trim($nama)) ?: [];
    if (!$kata || $kata[0] === '') return '-';
    $hasil = mb_convert_case(mb_strtolower($kata[0], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    foreach (array_slice($kata, 1) as $k) $hasil .= ' ' . mb_strtoupper(mb_substr($k, 0, 1, 'UTF-8'), 'UTF-8') . '.';
    return $hasil;
}

/**
 * Penyamaran ketat untuk tampilan publik: "ABHISTA KURNIA AKBAR" -> "Abh*** K. A."
 * (3 huruf pertama nama depan + bintang, sisanya hanya inisial).
 */
function samarkan_nama_ketat(string $nama): string
{
    $kata = preg_split('/\s+/', trim($nama)) ?: [];
    if (!$kata || $kata[0] === '') return '-';
    $depan = mb_convert_case(mb_strtolower($kata[0], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    $hasil = mb_strlen($depan, 'UTF-8') > 3 ? mb_substr($depan, 0, 3, 'UTF-8') . '***' : mb_substr($depan, 0, 1, 'UTF-8') . '**';
    foreach (array_slice($kata, 1) as $k) $hasil .= ' ' . mb_strtoupper(mb_substr($k, 0, 1, 'UTF-8'), 'UTF-8') . '.';
    return $hasil;
}

/**
 * PETA URL BERSIH: nama modul => pola path.
 * "{nis}" = NIS siswa (bukan id database), mis. /siswa/2026001/edit.
 * Pola statis (siswa/tambah) selalu diprioritaskan di atas pola ber-{nis}.
 * JANGAN memakai nama folder nyata (api, assets, config, includes, panel, presensi,
 * uploads, database) sebagai segmen pertama — itu milik file/folder di server.
 */
const RUTE_MODUL = [
    'dashboard'       => 'dashboard',
    'siswa'           => 'siswa',
    'siswa_tambah'    => 'siswa/tambah',
    'siswa_import'    => 'siswa/import',
    'siswa_hapus'     => 'siswa/hapus',
    'akun_siswa'      => 'akun-siswa',
    'izin'            => 'izin',
    'izin_detail'     => 'izin/{id}',
    'izin_lampiran'   => 'izin/{id}/lampiran',
    'siswa_detail'    => 'siswa/{nis}',
    'siswa_edit'      => 'siswa/{nis}/edit',
    'qr_generate'     => 'siswa/{nis}/qr',
    'master_kelas'    => 'kelas',
    'kenaikan_kelas'  => 'kelas/kenaikan',
    'pindah_kelas'    => 'kelas/pindah',
    'qr_siswa'        => 'qr/siswa',
    'qr_print'        => 'qr/siswa/cetak',
    'qr_kelas'        => 'qr/kelas',
    'qr_kelas_print'  => 'qr/kelas/cetak',
    'qr_gerbang'      => 'qr/gerbang',
    'presensi'        => 'absensi',
    'presensi_export' => 'absensi/ekspor',
    'presensi_print'  => 'absensi/cetak',
    'rekap'           => 'rekap',
    'rekap_print'     => 'rekap/cetak',
    'papan'           => 'papan',
    'device'          => 'perangkat',
    'log_security'    => 'log/keamanan',
    'log_aktivitas'   => 'log/aktivitas',
    'log_cetak'       => 'log/cetak',
    'pengaturan'      => 'pengaturan',
    'profil'          => 'profil',
    'pengguna'        => 'pengguna',
    'hak_akses'       => 'pengguna/hak-akses',
];

/** NIS siswa dari id database (di-cache per request). null bila tidak ada. */
function nis_dari_id(int $id): ?string
{
    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $st = getDB()->prepare('SELECT nis FROM siswa WHERE id = :id LIMIT 1');
        $st->execute([':id' => $id]);
        $v = $st->fetchColumn();
        $cache[$id] = ($v === false || $v === null) ? null : (string) $v;
    }
    return $cache[$id];
}

/** id database dari NIS. null bila tidak ada. */
function id_dari_nis(string $nis): ?int
{
    $st = getDB()->prepare('SELECT id FROM siswa WHERE nis = :nis LIMIT 1');
    $st->execute([':nis' => $nis]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? null : (int) $v;
}

/** Query string RFC3986 (spasi = %20), tanpa parameter kosong. */
function bangun_query(array $params): string
{
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return $params ? http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';
}

/**
 * Cocokkan path (tanpa slash di awal/akhir, mis. "siswa/2026001/edit") ke modul.
 * @return array{0:string,1:array}|null  [modul, ['nis' => ...]] atau null bila tidak ada.
 */
function cocokkan_rute(string $path): ?array
{
    static $urut = null;
    if ($urut === null) {
        $urut = RUTE_MODUL;
        // statis dulu, baru yang ber-{nis} (uasort stabil di PHP 8)
        uasort($urut, fn($a, $b) => substr_count($a, '{') <=> substr_count($b, '{'));
    }
    $seg = $path === '' ? [] : explode('/', $path);
    foreach ($urut as $modul => $pola) {
        $ps = explode('/', $pola);
        if (count($ps) !== count($seg)) continue;
        $params = [];
        foreach ($ps as $i => $p) {
            if ($p === '{nis}') {
                $nis = rawurldecode($seg[$i]);
                if ($nis === '') continue 2;
                $params['nis'] = $nis;
            } elseif ($p === '{id}') {
                if (!ctype_digit($seg[$i]) || strlen($seg[$i]) > 10) continue 2;
                $params['id'] = (int) $seg[$i];
            } elseif ($p !== $seg[$i]) {
                continue 2;
            }
        }
        return [$modul, $params];
    }
    return null;
}

/**
 * URL bersih halaman panel.
 *   modul_url('siswa')                                  -> /siswa
 *   modul_url('siswa', ['kelas' => 'X B', 'limit' => 20]) -> /siswa?kelas=X%20B&limit=20
 *   modul_url('siswa_edit', ['nis' => '2026001'])        -> /siswa/2026001/edit
 * Untuk modul ber-{nis}, boleh memberi ['id' => id_db]; NIS dicari otomatis.
 * Hasil TIDAK pernah berisi "?" bila tanpa parameter, jadi tambahan manual
 * ('&x=1') tidak boleh dipakai — kirim lewat $params.
 */
function modul_url(string $modul, array $params = []): string
{
    $pola = RUTE_MODUL[$modul] ?? null;
    if ($pola !== null && strpos($pola, '{nis}') !== false) {
        $nis = isset($params['nis']) ? (string) $params['nis'] : '';
        if ($nis === '' && !empty($params['id'])) {
            $nis = (string) nis_dari_id((int) $params['id']);
            if ($nis !== '') unset($params['id']);
        }
        unset($params['nis']);
        if ($nis === '') $pola = null;   // NIS tidak diketahui -> bentuk lama (router tetap melayani)
        else $pola = str_replace('{nis}', rawurlencode($nis), $pola);
    }
    if ($pola !== null && strpos($pola, '{id}') !== false) {
        $idUrl = (int) ($params['id'] ?? 0);
        unset($params['id']);
        $pola = $idUrl > 0 ? str_replace('{id}', (string) $idUrl, $pola) : null;   // tanpa id -> bentuk lama (id=0)
        if ($pola === null) $params['id'] = 0;
    }
    if ($pola === null) {
        $q = bangun_query(['modul' => $modul] + $params);
        return BASE_URL . '/index.php?' . $q;
    }
    $q = bangun_query($params);
    return BASE_URL . '/' . $pola . ($q !== '' ? '?' . $q : '');
}

/** <input hidden modul> hanya untuk URL bentuk lama (?modul=...); URL bersih tidak membutuhkannya. */
function hidden_modul(): string
{
    $m = (string) ($_GET['modul'] ?? '');
    return $m === '' ? '' : '<input type="hidden" name="modul" value="' . e($m) . '">';
}

/**
 * Render kontrol pagination Bootstrap. $extraParams berisi query string lain
 * yang perlu dipertahankan saat pindah halaman (misalnya filter kelas/status).
 */
function render_pagination(int $page, int $totalPages, array $extraParams = [], string $paramName = 'page'): string
{
    if ($totalPages <= 1) return '';

    $buildUrl = function (int $p) use ($extraParams, $paramName): string {
        $params = array_merge($extraParams, [$paramName => $p]);
        // URL bentuk lama (?modul=...) masih butuh modul; URL bersih tidak.
        if (!empty($_GET['modul'])) $params = ['modul' => (string) $_GET['modul']] + $params;
        return '?' . bangun_query($params);
    };

    // flex-wrap + pagination-sm: tidak meluber di layar HP. Teks "Sebelumnya/Berikutnya"
    // hanya tampil di layar >= md; di HP cukup ikon panah. Nomor yang jauh dari halaman
    // aktif disembunyikan di layar sempit.
    $html = '<nav aria-label="Navigasi halaman"><ul class="pagination pagination-sm justify-content-center flex-wrap mb-0" style="gap:2px 0">';

    $html .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '">'
        . '<a class="page-link text-nowrap" href="' . e($buildUrl(max(1, $page - 1))) . '" aria-label="Sebelumnya"><i class="bi bi-chevron-left"></i><span class="d-none d-md-inline ms-1">Sebelumnya</span></a></li>';

    // Tampilkan maksimal 5 nomor halaman di sekitar halaman aktif.
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);

    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . e($buildUrl(1)) . '">1</a></li>';
        if ($start > 2) $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $jauh = abs($i - $page) >= 2 ? ' d-none d-sm-block' : '';
        $html .= '<li class="page-item' . ($i === $page ? ' active' : '') . $jauh . '">'
            . '<a class="page-link" href="' . e($buildUrl($i)) . '">' . $i . '</a></li>';
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        $html .= '<li class="page-item"><a class="page-link" href="' . e($buildUrl($totalPages)) . '">' . $totalPages . '</a></li>';
    }

    $html .= '<li class="page-item' . ($page >= $totalPages ? ' disabled' : '') . '">'
        . '<a class="page-link text-nowrap" href="' . e($buildUrl(min($totalPages, $page + 1))) . '" aria-label="Berikutnya"><span class="d-none d-md-inline me-1">Berikutnya</span><i class="bi bi-chevron-right"></i></a></li>';

    $html .= '</ul></nav>';
    return $html;
}

// ---------------------------------------------------------------------
// CSRF PROTECTION
// ---------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_require(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!csrf_verify($token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Sesi tidak valid (CSRF). Muat ulang halaman.']));
    }
}

// ---------------------------------------------------------------------
// TOKEN GENERATOR (siswa qr_token, qr_dinamis token, device uuid)
// ---------------------------------------------------------------------
function generate_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes)); // 64 hex char utk 32 byte
}

// ---------------------------------------------------------------------
// IP ADDRESS
// ---------------------------------------------------------------------
function get_client_ip(): string
{
    // REMOTE_ADDR tidak bisa dipalsukan klien. Header X-Forwarded-For/Client-IP HANYA dipercaya bila permintaan datang
    // dari proxy lokal/jaringan privat (mis. reverse proxy aaPanel/Cloudflare tunnel di mesin yang sama). Sebelumnya
    // header itu selalu dipercaya, sehingga pembatas percobaan login per-IP bisa diakali dengan header palsu.
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote === '' || !filter_var($remote, FILTER_VALIDATE_IP)) return '0.0.0.0';
    $dariProxy = !filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($dariProxy) {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'] as $key) {
            if (empty($_SERVER[$key])) continue;
            $ip = trim(explode(',', (string) $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $remote;
}

// ---------------------------------------------------------------------
// GEO DISTANCE (Haversine, meter)
// ---------------------------------------------------------------------
function distance_meter(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371000; // meter
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

// ---------------------------------------------------------------------
// SETTINGS (cache ringan per-request)
// ---------------------------------------------------------------------
function get_settings(): array
{
    static $settings = null;
    if ($settings === null) {
        $stmt = getDB()->query('SELECT * FROM settings ORDER BY id ASC LIMIT 1');
        $settings = $stmt->fetch() ?: [];

        // Auto-migrasi kolom tambahan di tabel settings bila belum ada.
        // Butuh hak ALTER; kalau gagal, fitur memakai nilai default dan SQL manual
        // ada di folder database/ (migrasi_hari_efektif.sql, migrasi_identitas_sekolah.sql).
        if ($settings) {
            $kolomBaru = [
                'hari_efektif'   => "VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5'",
                'device_wajib_kode' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'alamat_sekolah' => 'TEXT NULL',
                'nama_kepala'    => 'VARCHAR(150) NULL',
                'portal_siswa_aktif' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'batas_absen'    => "TIME NOT NULL DEFAULT '10:00:00'",   // scan masuk ditutup + Alpa otomatis setelah jam ini
                'alpa_otomatis_terakhir' => 'DATE NULL',                 // penanda proses Alpa otomatis (maks 1x/hari)
            ];
            $berubah = false;
            foreach ($kolomBaru as $kolom => $definisi) {
                if (array_key_exists($kolom, $settings)) continue;
                try {
                    getDB()->exec("ALTER TABLE settings ADD COLUMN $kolom $definisi");
                    $berubah = true;
                } catch (Throwable $e) {
                    // dibiarkan: dipakai nilai default
                }
            }
            if ($berubah) {
                $settings = getDB()->query('SELECT * FROM settings ORDER BY id ASC LIMIT 1')->fetch() ?: $settings;
            }
        }
    }
    return $settings;
}

const NAMA_HARI = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

/** Daftar hari efektif presensi (angka ISO 1-7). Default Senin-Jumat. */
function get_hari_efektif(): array
{
    $raw = (string) (get_settings()['hari_efektif'] ?? '1,2,3,4,5');
    $hari = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $raw)),
        fn($h) => $h >= 1 && $h <= 7
    )));
    sort($hari);
    return $hari ?: [1, 2, 3, 4, 5];
}

/** Apakah tanggal (Y-m-d, default hari ini) termasuk hari efektif presensi? */
function is_hari_efektif(?string $tanggal = null): bool
{
    $n = (int) date('N', $tanggal ? strtotime($tanggal) : time());
    return in_array($n, get_hari_efektif(), true);
}

/** Teks ringkas hari efektif, mis. "Senin, Selasa, Rabu, Kamis, Jumat". */
function label_hari_efektif(): string
{
    return implode(', ', array_map(fn($h) => NAMA_HARI[$h], get_hari_efektif()));
}

/**
 * Tag <link rel="icon"> untuk tab browser. Memakai logo sekolah dari Pengaturan
 * bila ada; kalau belum diunggah, memakai ikon bawaan (SVG).
 */
function favicon_tag(): string
{
    $logo = get_settings()['logo'] ?? '';
    $path = __DIR__ . '/../uploads/siswa/' . $logo;
    if ($logo !== '' && is_file($path)) {
        $ext = strtolower(pathinfo($logo, PATHINFO_EXTENSION));
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'][$ext] ?? 'image/png';
        $href = BASE_URL . '/uploads/siswa/' . rawurlencode($logo) . '?v=' . filemtime($path);
        return '<link rel="icon" type="' . $mime . '" href="' . e($href) . '">' . "\n"
            . '<link rel="apple-touch-icon" href="' . e($href) . '">';
    }
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#0d6efd"/>'
        . '<path d="M18 33l10 10 19-22" fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    return '<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,' . rawurlencode($svg) . '">';
}

// ---------------------------------------------------------------------
// SECURITY LOG
// ---------------------------------------------------------------------
function security_log(?int $siswaId, string $event, ?string $deviceHash = null, ?string $keterangan = null): void
{
    $stmt = getDB()->prepare(
        'INSERT INTO security_logs (siswa_id, event, device_hash, ip_address, user_agent, keterangan, created_at)
         VALUES (:siswa_id, :event, :device_hash, :ip, :ua, :ket, NOW())'
    );
    $stmt->execute([
        ':siswa_id'    => $siswaId,
        ':event'       => $event,
        ':device_hash' => $deviceHash,
        ':ip'          => get_client_ip(),
        ':ua'          => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ':ket'         => $keterangan,
    ]);
}

// ---------------------------------------------------------------------
// PEMBATAS PERCOBAAN LOGIN (anti brute force)
// Dibatasi per USERNAME (ketat) dan per IP (longgar) dalam jendela waktu tertentu.
// Tabel dibuat otomatis; bila gagal dibuat/dibaca, login tetap berjalan (fail-open)
// dan error dicatat di error_log agar admin tidak terkunci karena masalah database.
// ---------------------------------------------------------------------
const LOGIN_MAKS_GAGAL_USER = 5;    // gagal per username
const LOGIN_MAKS_GAGAL_IP   = 20;   // gagal per IP
const LOGIN_JENDELA_DETIK   = 900;  // 15 menit

function login_siapkan_tabel(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        getDB()->exec('CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_la_user (username, created_at),
            INDEX idx_la_ip (ip_address, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        return $ok = true;
    } catch (Throwable $e) {
        error_log('login_siapkan_tabel: ' . $e->getMessage());
        return $ok = false;
    }
}

function login_normalisasi_username(string $username): string
{
    return mb_strtolower(mb_substr(trim($username), 0, 50, 'UTF-8'), 'UTF-8');
}

/** Sisa detik penguncian (0 = boleh mencoba login). */
function login_kunci_sisa(string $username): int
{
    if (!login_siapkan_tabel()) return 0;
    try {
        $db = getDB();
        $w  = LOGIN_JENDELA_DETIK;
        $hitung = function (string $kolom, string $nilai, int $maks) use ($db, $w): int {
            // $kolom, $w, $maks selalu konstanta dari kode ini (bukan input pengguna).
            $st = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM login_attempts
                                WHERE $kolom = :v AND created_at > (NOW() - INTERVAL $w SECOND)
                                ORDER BY id DESC LIMIT $maks");
            $st->execute([':v' => $nilai]);
            $umur = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($umur) < $maks) return 0;
            return max(1, $w - (int) end($umur)); // terkunci sampai kegagalan ke-$maks keluar dari jendela
        };
        return max(
            $hitung('username', login_normalisasi_username($username), LOGIN_MAKS_GAGAL_USER),
            $hitung('ip_address', get_client_ip(), LOGIN_MAKS_GAGAL_IP)
        );
    } catch (Throwable $e) {
        error_log('login_kunci_sisa: ' . $e->getMessage());
        return 0;
    }
}

function login_catat_gagal(string $username): void
{
    if (!login_siapkan_tabel()) return;
    try {
        $db = getDB();
        $db->prepare('INSERT INTO login_attempts (username, ip_address, created_at) VALUES (:u, :ip, NOW())')
            ->execute([':u' => login_normalisasi_username($username), ':ip' => substr(get_client_ip(), 0, 45)]);
        if (random_int(1, 20) === 1) { // bersih-bersih data lama sesekali
            $db->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
        }
    } catch (Throwable $e) {
        error_log('login_catat_gagal: ' . $e->getMessage());
    }
}

function login_bersihkan(string $username): void
{
    if (!login_siapkan_tabel()) return;
    try {
        getDB()->prepare('DELETE FROM login_attempts WHERE username = :u')
            ->execute([':u' => login_normalisasi_username($username)]);
    } catch (Throwable $e) {
        error_log('login_bersihkan: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// LOG AKTIVITAS ADMIN (terpisah dari Log Keamanan)
// Log Keamanan  = kejadian sistem/siswa (presensi, device, percobaan ditolak, login gagal)
// Log Aktivitas = tindakan admin (tambah, ubah, hapus, impor, ekspor, cetak, dst)
// ---------------------------------------------------------------------
const AKSI_AKTIVITAS = ['TAMBAH', 'UBAH', 'HAPUS', 'IMPOR', 'EKSPOR', 'CETAK', 'GENERATE', 'RESET', 'LOGIN', 'LOGOUT'];

/** Buat tabel activity_logs bila belum ada; pada pembuatan pertama, pindahkan log aktivitas admin lama dari security_logs. */
function buat_tabel_aktivitas(): void
{
    $db = getDB();
    $sudahAda = (bool) $db->query("SHOW TABLES LIKE 'activity_logs'")->fetchColumn();
    if ($sudahAda) return;

    $db->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_id INT UNSIGNED DEFAULT NULL,
        admin_nama VARCHAR(100) DEFAULT NULL,
        aksi VARCHAR(20) NOT NULL,
        modul VARCHAR(40) NOT NULL,
        keterangan VARCHAR(500) DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_act_aksi (aksi),
        INDEX idx_act_modul (modul),
        INDEX idx_act_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Pindahkan riwayat lama (tindakan admin) yang sebelumnya tercampur di security_logs.
    try {
        $events = "'KENAIKAN_KELAS','KELAS_DIGANTI_NAMA','ADMIN_ACCOUNT_UPDATED','SISWA_PINDAH_KELAS','QR_KELAS_REGENERATED','DEVICE_RESET'";
        $db->exec("INSERT INTO activity_logs (aksi, modul, keterangan, ip_address, user_agent, created_at)
            SELECT CASE event WHEN 'QR_KELAS_REGENERATED' THEN 'GENERATE' WHEN 'DEVICE_RESET' THEN 'RESET' ELSE 'UBAH' END,
                   CASE event WHEN 'KENAIKAN_KELAS' THEN 'kenaikan_kelas' WHEN 'KELAS_DIGANTI_NAMA' THEN 'master_kelas'
                        WHEN 'ADMIN_ACCOUNT_UPDATED' THEN 'profil' WHEN 'SISWA_PINDAH_KELAS' THEN 'siswa_edit'
                        WHEN 'QR_KELAS_REGENERATED' THEN 'qr_kelas' ELSE 'device' END,
                   keterangan, ip_address, user_agent, created_at
            FROM security_logs WHERE event IN ($events)");
        $db->exec("DELETE FROM security_logs WHERE event IN ($events)");
    } catch (Throwable $e) {
        error_log('Migrasi log aktivitas: ' . $e->getMessage());
    }
}

/** "Nama (NIS)" siswa untuk keterangan log. */
function nama_siswa_log(int $siswaId): string
{
    $st = getDB()->prepare('SELECT nama, nisn FROM siswa WHERE id = :id');
    $st->execute([':id' => $siswaId]);
    $r = $st->fetch();
    return $r ? $r['nama'] . ' (NISN ' . ($r['nisn'] ?: '-') . ')' : 'siswa ID ' . $siswaId;
}

/** Catat tindakan admin. Tidak pernah menghentikan alur aplikasi bila gagal. */
function catat_aktivitas(string $aksi, string $modul, string $keterangan = ''): void
{
    $admin = function_exists('current_admin') ? current_admin() : null;
    $params = [
        ':admin_id' => $admin['id'] ?? ($_SESSION['admin_id'] ?? null),
        ':nama'     => $admin ? ($admin['nama'] ?: $admin['username']) : null,
        ':aksi'     => strtoupper($aksi),
        ':modul'    => $modul,
        ':ket'      => mb_substr($keterangan, 0, 500),
        ':ip'       => get_client_ip(),
        ':ua'       => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ];
    $sql = 'INSERT INTO activity_logs (admin_id, admin_nama, aksi, modul, keterangan, ip_address, user_agent, created_at)
            VALUES (:admin_id, :nama, :aksi, :modul, :ket, :ip, :ua, NOW())';
    try {
        getDB()->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        try {
            buat_tabel_aktivitas();
            getDB()->prepare($sql)->execute($params);
        } catch (Throwable $e2) {
            error_log('catat_aktivitas gagal: ' . $e2->getMessage());
        }
    }
}

/** Skrip pencatat "CETAK": terkirim otomatis saat dialog cetak (window.print / Ctrl+P) dibuka. */
function script_log_cetak(): string
{
    return '<script>(function(){var t=0;window.addEventListener("beforeprint",function(){var n=Date.now();if(n-t<3000)return;t=n;'
        . 'var p=new URLSearchParams(location.search);'
        . 'var b=new URLSearchParams({csrf_token:' . json_encode(csrf_token()) . ',modul:p.get("modul")||"",judul:document.title,kelas:p.get("kelas")||"",id:p.get("id")||""});'
        . 'fetch(' . json_encode(modul_url('log_cetak')) . ',{method:"POST",body:b,credentials:"same-origin",keepalive:true});});})();</script>';
}

/**
 * Auto-reset (retensi) Log Keamanan. Dijalankan di includes/header.php,
 * jadi tercek setiap kali ada admin membuka halaman -- TAPI query
 * penghapusan sungguhan HANYA dijalankan maks 1x/hari (dicek via kolom
 * log_terakhir_dibersihkan) supaya tidak membebani database di setiap
 * request. Tidak butuh cron job terpisah di hosting.
 *
 * log_retensi_hari = 0 berarti fitur ini NONAKTIF (log disimpan selamanya).
 */
function jalankan_pembersihan_log_otomatis(): void
{
    $settings = get_settings();
    $retensiHari = (int) ($settings['log_retensi_hari'] ?? 0);
    if ($retensiHari <= 0) return; // fitur nonaktif

    $terakhir = $settings['log_terakhir_dibersihkan'] ?? null;
    if ($terakhir !== null && strtotime($terakhir) > strtotime('-1 day')) {
        return; // sudah dicek dalam 24 jam terakhir, lewati dulu
    }

    $db = getDB();
    $db->prepare('DELETE FROM security_logs WHERE created_at < (NOW() - INTERVAL :hari DAY)')
        ->execute([':hari' => $retensiHari]);
    try {
        $db->prepare('DELETE FROM activity_logs WHERE created_at < (NOW() - INTERVAL :hari DAY)')
            ->execute([':hari' => $retensiHari]);
    } catch (Throwable $e) {
        // tabel log aktivitas belum dibuat: abaikan
    }
    $db->prepare('UPDATE settings SET log_terakhir_dibersihkan = NOW() WHERE id = :id')
        ->execute([':id' => $settings['id']]);
}

// ---------------------------------------------------------------------
// BATAS ABSEN MASUK + ALPA OTOMATIS (tanpa cron)
// ---------------------------------------------------------------------
/** Jam batas absen masuk (HH:MM:SS). Setelah jam ini scan masuk ditutup dan siswa yang belum absen dicatat ALPA. */
function batas_absen(): string
{
    $b = (string) (get_settings()['batas_absen'] ?? '');
    return preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $b) ? substr($b . ':00', 0, 8) : '10:00:00';
}

/** Apakah absen masuk hari ini sudah ditutup (lewat batas absen)? */
function absen_sudah_ditutup(?string $jam = null): bool
{
    return ($jam ?? date('H:i:s')) >= batas_absen();
}

/**
 * Alpa otomatis: untuk setiap hari efektif yang batas absennya sudah lewat, siswa aktif yang belum punya baris
 * presensi dicatat ALPA (metode 'OTOMATIS', jam 00:00:00). Siswa dengan izin/sakit DISETUJUI yang mencakup tanggal
 * itu dilewati. Dipicu lazily oleh request panel/portal/beranda; kerja sungguhan maks 1x per hari (kolom
 * settings.alpa_otomatis_terakhir diklaim secara atomik). Bila beberapa hari tidak ada yang membuka situs,
 * hari-hari efektif yang terlewat (maks 7 hari) ikut dikejar. Aman dijalankan ulang (UNIQUE siswa_id+tanggal + NOT EXISTS).
 * Tidak pernah melempar error ke halaman pemanggil.
 */
function jalankan_alpa_otomatis(): void
{
    static $sudah = false;
    if ($sudah) return;
    $sudah = true;
    try {
        $settings = get_settings();
        if (!$settings || !array_key_exists('alpa_otomatis_terakhir', $settings)) return; // kolom belum bisa dibuat
        $hariIni = date('Y-m-d');
        $tutup = absen_sudah_ditutup();
        $sampai = $tutup ? $hariIni : date('Y-m-d', strtotime('-1 day'));
        $terakhir = (string) ($settings['alpa_otomatis_terakhir'] ?? '');
        if ($terakhir !== '' && $terakhir >= $sampai) return;               // sudah diproses

        $db = getDB();
        // Klaim atomik: hanya satu request yang memproses (request lain bersamaan langsung keluar).
        $klaim = $db->prepare('UPDATE settings SET alpa_otomatis_terakhir = :s WHERE id = :id
                               AND (alpa_otomatis_terakhir IS NULL OR alpa_otomatis_terakhir < :s2)');
        $klaim->execute([':s' => $sampai, ':s2' => $sampai, ':id' => $settings['id']]);
        if ($klaim->rowCount() !== 1) return;

        // Pertama kali fitur aktif: hanya proses hari ini (tidak menulis Alpa untuk masa lalu).
        $mulai = $terakhir === '' ? $sampai : date('Y-m-d', strtotime($terakhir . ' +1 day'));
        $batasMundur = date('Y-m-d', strtotime($sampai . ' -6 days'));
        if ($mulai < $batasMundur) $mulai = $batasMundur;

        $izinAda = false;
        try {
            $izinAda = (bool) $db->query("SHOW TABLES LIKE 'izin_pengajuan'")->fetchColumn();
        } catch (Throwable $e) {
        }
        $sql = "INSERT IGNORE INTO presensi (siswa_id, kelas_saat_presensi, tanggal, jam_masuk, status, metode, created_at)
                SELECT s.id, s.kelas, :t, '00:00:00', 'ALPA', 'OTOMATIS', NOW() FROM siswa s
                WHERE s.status = 'aktif' AND DATE(s.created_at) <= :t2
                  AND NOT EXISTS (SELECT 1 FROM presensi p WHERE p.siswa_id = s.id AND p.tanggal = :t3)
                  -- Pengaman hari libur nasional/tanggal merah (belum ada tabel libur): hari tanpa satu pun scan
                  -- kehadiran dianggap libur sehingga tidak ada yang dicatat Alpa.
                  AND EXISTS (SELECT 1 FROM presensi x WHERE x.tanggal = :t6 AND x.metode NOT IN ('PENGAJUAN','OTOMATIS'))"
            . ($izinAda ? " AND NOT EXISTS (SELECT 1 FROM izin_pengajuan i WHERE i.siswa_id = s.id AND i.status = 'disetujui'
                              AND i.tanggal_mulai <= :t4 AND i.tanggal_selesai >= :t5)" : '');
        $ins = $db->prepare($sql);
        $total = 0;
        for ($t = $mulai; $t <= $sampai; $t = date('Y-m-d', strtotime($t . ' +1 day'))) {
            if (!is_hari_efektif($t)) continue;
            $ins->execute([':t' => $t, ':t2' => $t, ':t3' => $t, ':t6' => $t] + ($izinAda ? [':t4' => $t, ':t5' => $t] : []));
            $total += $ins->rowCount();
        }
        if ($total > 0 && function_exists('catat_aktivitas')) {
            try {
                catat_aktivitas('SISTEM', 'presensi', "Alpa otomatis: $total siswa dicatat ALPA ($mulai s.d. $sampai).");
            } catch (Throwable $e) {
            }
        }
    } catch (Throwable $e) {
        error_log('jalankan_alpa_otomatis: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// STATUS PRESENSI OTOMATIS BERDASARKAN JAM SERVER
// ---------------------------------------------------------------------
function tentukan_status_presensi(string $jamSekarangHms): string
{
    $settings = get_settings();
    $batas = $settings['batas_terlambat'] ?? '07:15:00';
    return ($jamSekarangHms <= $batas) ? 'HADIR' : 'TERLAMBAT';
}

// ---------------------------------------------------------------------
// JSON RESPONSE HELPER (untuk endpoint di /api)
// ---------------------------------------------------------------------
function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// ---------------------------------------------------------------------
// FLASH MESSAGE (session-based, sekali tampil)
// ---------------------------------------------------------------------
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// ---------------------------------------------------------------------
// FORMAT TANGGAL INDONESIA
// ---------------------------------------------------------------------
function format_tanggal_indonesia(string $ymd): string
{
    $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = strtotime($ymd);
    if (!$ts) return $ymd;
    return (int) date('j', $ts) . ' ' . $namaBulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/**
 * Ambil jenjang (X, XI, XII) dari nama kelas, mis. "X A" -> "X", "XI-A" -> "XI",
 * "XII IPA 2" -> "XII". Dicek dari yang terpanjang dulu (XII) supaya "XI"/"X"
 * tidak salah cocok dgn awalan "XII".
 */
function ambil_jenjang(string $kelas): string
{
    $kelas = trim($kelas);
    if (preg_match('/^XII\b/i', $kelas)) return 'XII';
    if (preg_match('/^XI\b/i', $kelas)) return 'XI';
    if (preg_match('/^X\b/i', $kelas)) return 'X';
    return 'Lainnya';
}

/**
 * Kelompokkan daftar kelas berdasarkan jenjangnya. Return array asosiatif
 * ['X' => [...kelas...], 'XI' => [...], 'XII' => [...], 'Lainnya' => [...]]
 * hanya berisi jenjang yang memang ada datanya, urut X -> XI -> XII -> Lainnya.
 */
function kelompokkan_per_jenjang(array $kelasList): array
{
    $urutan = ['X', 'XI', 'XII', 'Lainnya'];
    $hasil = [];
    foreach ($kelasList as $k) {
        $hasil[ambil_jenjang($k)][] = $k;
    }
    $terurut = [];
    foreach ($urutan as $j) {
        if (!empty($hasil[$j])) $terurut[$j] = $hasil[$j];
    }
    return $terurut;
}

// ---------------------------------------------------------------------
// KOMPRESI GAMBAR UNGGAHAN (foto profil, logo, lampiran izin berupa foto)
// Gambar apa pun ukurannya (hingga UNGGAH_MAKS_MENTAH) diperkecil otomatis dengan GD: sisi terpanjang dibatasi,
// JPEG 85% (kualitas tetap baik untuk foto), orientasi EXIF dikoreksi, PNG bertransparansi tetap PNG.
// Tanpa ekstensi GD: perilaku lama (batas ukuran apa adanya).
// ---------------------------------------------------------------------
const UNGGAH_MAKS_MENTAH = 15 * 1024 * 1024;   // gambar mentah terbesar yang masih diterima untuk dikompres
const UNGGAH_MAKS_PIKSEL = 40000000;           // 40 megapiksel (mencegah kehabisan memori)
const FOTO_MAKS_SISI     = 1024;               // px, sisi terpanjang foto profil/logo
const FOTO_TARGET_BYTE   = 614400;             // 600 KB: bila lebih besar, kualitas diturunkan bertahap (min. 65%)

function gd_kompres_tersedia(): bool
{
    return extension_loaded('gd') && function_exists('imagecreatefromjpeg') && function_exists('imagejpeg') && function_exists('imagecreatetruecolor');
}

/** Imagick (ekstensi "imagemagick" di aaPanel) sebagai cadangan bila GD tidak ada. */
function imagick_kompres_tersedia(): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = class_exists('Imagick') && in_array('JPEG', Imagick::queryFormats('JPEG'), true) && in_array('PNG', Imagick::queryFormats('PNG'), true); }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Apakah server bisa mengompres gambar (GD atau Imagick)? */
function kompres_tersedia(): bool
{
    return gd_kompres_tersedia() || imagick_kompres_tersedia();
}

/** Nama mesin kompresi yang dipakai: 'gd', 'imagick', atau '' (tidak ada). */
function kompres_mesin(): string
{
    return gd_kompres_tersedia() ? 'gd' : (imagick_kompres_tersedia() ? 'imagick' : '');
}

/** Pastikan memori cukup untuk memproses gambar $w x $h (GD ~ 5 byte/piksel + salinan). */
function gd_siapkan_memori(int $w, int $h): bool
{
    $perlu = (int) ($w * $h * 5.5) + 48 * 1048576;
    $ini = trim((string) ini_get('memory_limit'));
    if ($ini === '-1') return true;
    $angka = (float) $ini;
    $unit = strtolower(substr($ini, -1));
    $byte = (int) ($unit === 'g' ? $angka * 1073741824 : ($unit === 'm' ? $angka * 1048576 : ($unit === 'k' ? $angka * 1024 : $angka)));
    if ($byte >= $perlu) return true;
    return @ini_set('memory_limit', (string) ((int) ceil($perlu / 1048576) + 16) . 'M') !== false;
}

/**
 * Perkecil + kompres gambar JPEG/PNG/WEBP. File sumber tidak diubah.
 * @return array{path:string,mime:string,ext:string,size:int}|null  null = tidak perlu/tidak bisa dikompres (pakai file asli)
 *         File hasil ada di folder sementara; pemanggil wajib memindahkan atau menghapusnya.
 */
function kompres_gambar_file(string $src, string $mime, int $maksSisi, int $kualitas = 85, int $targetByte = 0): ?array
{
    if (!kompres_tersedia()) return null;
    $info = @getimagesize($src);
    if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > UNGGAH_MAKS_PIKSEL) return null;
    if (!gd_kompres_tersedia()) return kompres_gambar_imagick($src, $mime, $maksSisi, $kualitas, $targetByte);   // GD tidak ada -> Imagick
    if (!gd_siapkan_memori((int) $info[0], (int) $info[1])) return null;

    switch ($mime) {
        case 'image/jpeg': $im = @imagecreatefromjpeg($src); break;
        case 'image/png':  $im = @imagecreatefrompng($src); break;
        case 'image/webp': $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false; break;
        default: $im = false;
    }
    if (!$im) return null;
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);

    // Koreksi orientasi foto dari kamera HP (EXIF) agar tidak tampil miring.
    $diputar = false;
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $ex = @exif_read_data($src);
        $sudut = [3 => 180, 6 => -90, 8 => 90][(int) ($ex['Orientation'] ?? 1)] ?? 0;
        if ($sudut !== 0 && ($r = imagerotate($im, $sudut, 0))) { imagedestroy($im); $im = $r; $diputar = true; }
    }

    $w = imagesx($im);
    $h = imagesy($im);
    $skala = min(1.0, $maksSisi / max($w, $h));
    if ($skala < 1.0) {
        $nw = max(1, (int) round($w * $skala));
        $nh = max(1, (int) round($h * $skala));
        $baru = imagecreatetruecolor($nw, $nh);
        imagealphablending($baru, false);
        imagesavealpha($baru, true);
        imagefilledrectangle($baru, 0, 0, $nw, $nh, imagecolorallocatealpha($baru, 255, 255, 255, 127));
        imagecopyresampled($baru, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $im = $baru; $w = $nw; $h = $nh;
    }

    // Transparansi (PNG/WEBP)? Bila ada, tetap PNG; bila tidak, JPEG (jauh lebih kecil untuk foto).
    $transparan = false;
    if ($mime !== 'image/jpeg') {
        $langkah = max(1, (int) floor(max($w, $h) / 96));
        for ($y = 0; $y < $h && !$transparan; $y += $langkah) {
            for ($x = 0; $x < $w; $x += $langkah) {
                if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 8) { $transparan = true; break; }
            }
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'kmp');
    if ($tmp === false) { imagedestroy($im); return null; }
    if ($transparan) {
        imagesavealpha($im, true);
        $ok = imagepng($im, $tmp, 9);
        $mimeOut = 'image/png'; $ext = 'png';
    } else {
        $rata = imagecreatetruecolor($w, $h);                 // ratakan ke latar putih (menghindari latar hitam di JPEG)
        imagefill($rata, 0, 0, imagecolorallocate($rata, 255, 255, 255));
        imagecopy($rata, $im, 0, 0, 0, 0, $w, $h);
        imagedestroy($im);
        $im = $rata;
        imageinterlace($im, true);
        $q = max(40, min(95, $kualitas));
        $ok = imagejpeg($im, $tmp, $q);
        while ($ok && $targetByte > 0 && $q > 65 && filesize($tmp) > $targetByte) { $q -= 10; clearstatcache(true, $tmp); $ok = imagejpeg($im, $tmp, max(65, $q)); }
        $mimeOut = 'image/jpeg'; $ext = 'jpg';
    }
    imagedestroy($im);
    clearstatcache(true, $tmp);
    $ukuran = (int) filesize($tmp);
    if (!$ok || $ukuran <= 0) { @unlink($tmp); return null; }
    // Tidak diperkecil/diputar dan hasilnya tidak lebih kecil: pakai file asli apa adanya.
    if ($skala >= 1.0 && !$diputar && $mimeOut === $mime && $ukuran >= (int) filesize($src)) { @unlink($tmp); return null; }
    return ['path' => $tmp, 'mime' => $mimeOut, 'ext' => $ext, 'size' => $ukuran];
}

/** Versi Imagick dari kompres_gambar_file() (hasil dan aturan sama). */
function kompres_gambar_imagick(string $src, string $mime, int $maksSisi, int $kualitas, int $targetByte): ?array
{
    $tmp = tempnam(sys_get_temp_dir(), 'kmp');
    if ($tmp === false) return null;
    $im = null;
    try {
        $im = new Imagick();
        $im->readImage($src . '[0]');                          // hanya frame pertama
        if ($im->getImageColorspace() === Imagick::COLORSPACE_CMYK) $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);

        // Koreksi orientasi EXIF.
        $diputar = false;
        $sudut = [Imagick::ORIENTATION_BOTTOMRIGHT => 180, Imagick::ORIENTATION_RIGHTTOP => 90, Imagick::ORIENTATION_LEFTBOTTOM => -90][$im->getImageOrientation()] ?? 0;
        if ($sudut !== 0) { $im->rotateImage(new ImagickPixel('none'), $sudut); $diputar = true; }
        $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);

        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $skala = min(1.0, $maksSisi / max($w, $h));
        if ($skala < 1.0) {
            $w = max(1, (int) round($w * $skala));
            $h = max(1, (int) round($h * $skala));
            $im->resizeImage($w, $h, Imagick::FILTER_LANCZOS, 1);
        }

        // Transparan (PNG/WEBP)? Tetap PNG; bila tidak, JPEG.
        $transparan = false;
        if ($mime !== 'image/jpeg' && $im->getImageAlphaChannel()) {
            $t = clone $im;
            $t->thumbnailImage(48, 48);
            for ($y = 0; $y < $t->getImageHeight() && !$transparan; $y++) {
                for ($x = 0; $x < $t->getImageWidth(); $x++) {
                    if ($t->getImagePixelColor($x, $y)->getColorValue(Imagick::COLOR_ALPHA) < 0.97) { $transparan = true; break; }
                }
            }
            $t->clear();
        }

        if ($transparan) {
            $im->setImageFormat('png');
            $im->setOption('png:compression-level', '9');
            $im->stripImage();
            $blob = $im->getImageBlob();
            $mimeOut = 'image/png'; $ext = 'png';
        } else {
            $bg = new Imagick();                                // ratakan ke latar putih
            $bg->newImage($w, $h, new ImagickPixel('white'));
            $bg->compositeImage($im, Imagick::COMPOSITE_OVER, 0, 0);
            $im->clear();
            $im = $bg;
            $im->setImageFormat('jpeg');
            $im->setImageCompression(Imagick::COMPRESSION_JPEG);
            $im->setInterlaceScheme(Imagick::INTERLACE_PLANE);
            $im->stripImage();
            $q = max(40, min(95, $kualitas));
            while (true) {
                $im->setImageCompressionQuality($q);
                $blob = $im->getImageBlob();
                if ($targetByte <= 0 || strlen($blob) <= $targetByte || $q <= 65) break;
                $q = max(65, $q - 10);
            }
            $mimeOut = 'image/jpeg'; $ext = 'jpg';
        }
        $im->clear();
        $ukuran = strlen($blob);
        if ($ukuran <= 0 || file_put_contents($tmp, $blob) === false) { @unlink($tmp); return null; }
        if ($skala >= 1.0 && !$diputar && $mimeOut === $mime && $ukuran >= (int) filesize($src)) { @unlink($tmp); return null; }
        return ['path' => $tmp, 'mime' => $mimeOut, 'ext' => $ext, 'size' => $ukuran];
    } catch (Throwable $e) {
        if ($im instanceof Imagick) { try { $im->clear(); } catch (Throwable $x) { } }
        @unlink($tmp);
        error_log('kompres_gambar_imagick: ' . $e->getMessage());
        return null;
    }
}

function handle_upload_foto(array $file, ?string $oldFile = null): ?string
{
    if (empty($file['name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return $oldFile; // tidak upload baru, pertahankan lama
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('Ukuran foto melebihi batas yang diterima server. Pilih foto yang lebih kecil.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload gagal (kode error: ' . $file['error'] . ')');
    }

    $gd = kompres_tersedia();
    $batasAkhir = 2 * 1024 * 1024; // 2MB: batas file yang disimpan
    if ($file['size'] > ($gd ? UNGGAH_MAKS_MENTAH : $batasAkhir)) {
        throw new RuntimeException($gd ? 'Ukuran foto maksimal 15MB.' : 'Ukuran foto maksimal 2MB.');
    }

    if (!is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException('File tidak valid.');
    }
    $mime = deteksi_mime_file((string) $file['tmp_name']);   // tanpa ketergantungan wajib pada ekstensi fileinfo

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Format foto harus JPG, PNG, atau WEBP.');
    }

    $img = @getimagesize($file['tmp_name']);
    if (!$img || $img[0] < 1 || $img[1] < 1) {
        throw new RuntimeException('File gambar tidak valid.');
    }

    $ext = $allowed[$mime];
    $dir = __DIR__ . '/../uploads/siswa';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Folder uploads/siswa tidak bisa dibuat. Periksa izin tulis folder uploads.');
    }

    // Kompres otomatis (perkecil + JPEG 85%); bila tidak bisa, pakai file asli selama <= 2MB.
    $k = kompres_gambar_file((string) $file['tmp_name'], $mime, FOTO_MAKS_SISI, 85, FOTO_TARGET_BYTE);
    if ($k) {
        if ($k['size'] > $batasAkhir) {
            @unlink($k['path']);
            throw new RuntimeException('Foto masih terlalu besar setelah dikompres. Pilih foto lain.');
        }
        $ext = $k['ext'];
    } elseif ($file['size'] > $batasAkhir) {
        throw new RuntimeException('Ukuran foto maksimal 2MB.');
    }

    $filename = 'siswa_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = __DIR__ . '/../uploads/siswa/' . $filename;

    if ($k) {
        $dipindah = @rename($k['path'], $dest) || (@copy($k['path'], $dest) && @unlink($k['path']));
        if (!$dipindah) { @unlink($k['path']); throw new RuntimeException('Gagal menyimpan file foto.'); }
        @chmod($dest, 0644);
    } elseif (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Gagal menyimpan file foto.');
    }

    if ($oldFile && file_exists(__DIR__ . '/../uploads/siswa/' . $oldFile)) {
        @unlink(__DIR__ . '/../uploads/siswa/' . $oldFile);
    }

    return $filename;
}

// ---------------------------------------------------------------------
// DEVICE INFO PARSER SEDERHANA (dari User-Agent)
// ---------------------------------------------------------------------
function parse_user_agent(string $ua): array
{
    $browser = 'Unknown';
    $os = 'Unknown';

    // Browser di iPhone/iPad memakai token sendiri (CriOS/FxiOS/EdgiOS/OPiOS) dan semuanya berisi "Safari/".
    if (preg_match('/Edg(e|iOS|A)?\//i', $ua)) $browser = 'Edge';
    elseif (preg_match('/OPR\/|OPiOS\//i', $ua)) $browser = 'Opera';
    elseif (preg_match('/CriOS\//i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/FxiOS\//i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/Chrome\//i', $ua) && !preg_match('/Chromium/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/Firefox\//i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/Safari\//i', $ua)) $browser = 'Safari';

    if (preg_match('/Android/i', $ua)) $os = 'Android';
    elseif (preg_match('/iPhone|iPad|iOS/i', $ua)) $os = 'iOS';
    elseif (preg_match('/Windows/i', $ua)) $os = 'Windows';
    elseif (preg_match('/Mac OS X/i', $ua)) $os = 'macOS';
    elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';

    return ['browser' => $browser, 'os' => $os];
}
