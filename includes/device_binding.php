<?php
/**
 * =====================================================================
 * MODUL DEVICE BINDING — SATU SISWA SATU PERANGKAT
 * =====================================================================
 * Modul ini TIDAK menerima input mentah "device_id" dari klien sebagai
 * bukti tunggal. Klien (JS) mengirim sebuah UUID instalasi yang
 * tersimpan persisten di localStorage (dibuat sekali oleh browser).
 * Server meng-hash UUID tersebut (SHA-256) untuk dibandingkan.
 *
 * PENTING: User-Agent SENGAJA TIDAK ikut di-hash. UA berubah otomatis setiap
 * Chrome / Android System WebView ter-update (mis. 154.0.8037.106 -> .107),
 * sehingga hash lama tidak cocok lagi dan perangkat yang sah dianggap
 * "belum terdaftar". UUID acak 122-bit sudah cukup sebagai identitas;
 * UA mudah dipalsukan sehingga tidak menambah keamanan nyata.
 * =====================================================================
 */

require_once __DIR__ . '/functions.php';

/** Versi skema hash. Naikkan bila rumus hash berubah lagi. */
const DEVICE_HASH_VERSI = 'v2';

/**
 * Membentuk device_hash HANYA dari UUID instalasi (kiriman klien) — stabil
 * walau browser/WebView ter-update. TIDAK memakai User-Agent.
 *
 * Self-healing: baris binding lama (skema v1 = UUID + User-Agent) otomatis
 * dimigrasikan ke hash v2 lewat kolom device_uuid, sekali per UUID per
 * request, sehingga perangkat yang sudah terdaftar tidak perlu daftar ulang.
 *
 * KEAMANAN: UUID itu kini juga kunci masuk otomatis portal. Karena itu kolom device_uuid tidak lagi diisi untuk
 * pendaftaran baru dan dikosongkan untuk baris lama begitu UUID-nya dikenali (juga saat portal_bersihkan_uuid_lama()
 * berjalan), supaya bocornya database tidak membocorkan kunci masuk siapa pun.
 */
function build_device_hash(string $installUuid): string
{
    $hash = hash('sha256', DEVICE_HASH_VERSI . '|' . $installUuid);
    sinkronkan_hash_perangkat($installUuid, $hash);
    return $hash;
}

/**
 * Migrasi otomatis hash lama (v1) -> v2 untuk perangkat dengan UUID ini.
 * Aman dipanggil berulang: hanya mengubah baris yang hash-nya belum v2,
 * dan hanya berjalan sekali per UUID dalam satu request.
 */
function sinkronkan_hash_perangkat(string $installUuid, string $hashBaru): void
{
    static $sudah = [];
    if (isset($sudah[$installUuid]) || !is_valid_install_uuid($installUuid)) return;
    $sudah[$installUuid] = true;
    try {
        $stmt = getDB()->prepare(
            // Nama parameter TIDAK boleh dipakai dua kali: koneksi memakai prepared statement asli
            // (ATTR_EMULATE_PREPARES=false), sehingga :baru ganda menimbulkan SQLSTATE[HY093].
            'UPDATE device_bindings SET device_hash = :baru, device_uuid = NULL
             WHERE device_uuid = :uuid'
        );
        $stmt->execute([':baru' => $hashBaru, ':uuid' => $installUuid]);
    } catch (PDOException $e) {
        // Bentrok UNIQUE (sangat jarang) -> abaikan; jangan sampai memblokir presensi.
        error_log('sinkronkan_hash_perangkat: ' . $e->getMessage());
    }
}

/**
 * Validasi format UUID v4 sederhana supaya klien tidak bisa mengirim
 * string sembarangan yang terlalu pendek/panjang.
 */
function is_valid_install_uuid(string $uuid): bool
{
    return (bool) preg_match('/^[0-9a-fA-F-]{20,64}$/', $uuid);
}

/**
 * Ambil binding aktif milik seorang siswa (jika ada).
 */
function get_device_binding(int $siswaId): ?array
{
    $stmt = getDB()->prepare('SELECT * FROM device_bindings WHERE siswa_id = :sid LIMIT 1');
    $stmt->execute([':sid' => $siswaId]);
    return $stmt->fetch() ?: null;
}

/**
 * Cari binding berdasarkan device_hash (lintas siswa). Dipakai utk mengecek
 * apakah sebuah perangkat SUDAH dipakai siswa lain sebelum mengizinkannya
 * didaftarkan ke siswa baru — inilah kunci "1 siswa = 1 device" yang
 * sesungguhnya (bukan cuma dicek dari sisi siswa saja).
 */
function get_device_binding_by_hash(string $deviceHash): ?array
{
    $stmt = getDB()->prepare('SELECT * FROM device_bindings WHERE device_hash = :hash LIMIT 1');
    $stmt->execute([':hash' => $deviceHash]);
    return $stmt->fetch() ?: null;
}

/**
 * INTI ALUR DEVICE BINDING.
 *
 * Return array:
 *  [
 *    'allowed' => bool,
 *    'code'    => 'REGISTERED_NEW' | 'MATCH' | 'MISMATCH' | 'BLOCKED' | 'BINDING_DISABLED' | 'DEVICE_ALREADY_BOUND',
 *    'message' => string,
 *  ]
 */
function check_or_register_device(int $siswaId, string $installUuid): array
{
    $settings = get_settings();
    if (empty($settings['device_binding_aktif'])) {
        return ['allowed' => true, 'code' => 'BINDING_DISABLED', 'message' => 'Device binding tidak aktif.'];
    }

    $deviceHash = build_device_hash($installUuid);
    $ua = parse_user_agent($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip = get_client_ip();

    $binding = get_device_binding($siswaId);

    // --- Belum pernah ada device terdaftar untuk siswa ini ---
    if (!$binding) {
        // PENTING: cek dulu apakah device (hash) ini SUDAH dipakai siswa LAIN.
        // Tanpa cek ini, 1 perangkat bisa didaftarkan ke banyak siswa berbeda —
        // celah utama titip-absen yang device binding harusnya mencegah.
        $sudahDipakaiSiswaLain = get_device_binding_by_hash($deviceHash);
        if ($sudahDipakaiSiswaLain && (int) $sudahDipakaiSiswaLain['siswa_id'] !== $siswaId) {
            security_log(
                $siswaId, 'DEVICE_MISMATCH', $deviceHash,
                'Percobaan daftar device yang sudah terikat siswa ID ' . $sudahDipakaiSiswaLain['siswa_id'] . '.'
            );
            return [
                'allowed' => false, 'code' => 'DEVICE_ALREADY_BOUND',
                'message' => 'Perangkat ini sudah terdaftar untuk siswa lain. Satu perangkat hanya dapat digunakan oleh satu siswa. Hubungi admin jika ini keliru.',
            ];
        }

        // Mode "Wajib Kode dari Petugas": perangkat baru TIDAK didaftarkan otomatis saat presensi;
        // harus lewat aktivasi akun dengan kode dari menu Akun Siswa.
        if (!empty($settings['device_wajib_kode'])) {
            return [
                'allowed' => false, 'code' => 'PAIRING_REQUIRED',
                'message' => 'Perangkat ini belum didaftarkan. Minta kode perangkat ke petugas sekolah, lalu buka aplikasi SIAP, masuk ke portal, dan daftarkan perangkat ini dengan kode tersebut.',
            ];
        }

        try {
            $stmt = getDB()->prepare(
                'INSERT INTO device_bindings
                    (siswa_id, device_uuid, device_hash, device_name, browser, os, ip_address, status, registered_at, last_used_at)
                 VALUES
                    (:sid, :uuid, :hash, :name, :browser, :os, :ip, "ACTIVE", NOW(), NOW())'
            );
            $stmt->execute([
                ':sid'     => $siswaId,
                ':uuid'    => null,          // UUID mentah tidak disimpan: yang tersimpan hanya hash (lihat catatan keamanan)
                ':hash'    => $deviceHash,
                ':name'    => $ua['os'] . ' - ' . $ua['browser'],
                ':browser' => $ua['browser'],
                ':os'      => $ua['os'],
                ':ip'      => $ip,
            ]);
        } catch (PDOException $e) {
            // Jaring pengaman terakhir thd race condition (2 request nyaris
            // bersamaan lolos pengecekan di atas sebelum salah satu INSERT).
            // device_hash WAJIB punya UNIQUE index di database (lihat
            // database/database.sql / migration_kunci_device_hash.sql) —
            // tanpa itu, percobaan INSERT kedua bisa lolos begitu saja dan
            // 1 perangkat bisa kepakai 2 siswa berbeda.
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                security_log($siswaId, 'DEVICE_MISMATCH', $deviceHash, 'Race condition: device sudah keburu terikat siswa lain.');
                return [
                    'allowed' => false, 'code' => 'DEVICE_ALREADY_BOUND',
                    'message' => 'Perangkat ini sudah terdaftar untuk siswa lain. Satu perangkat hanya dapat digunakan oleh satu siswa.',
                ];
            }
            throw $e;
        }

        security_log($siswaId, 'DEVICE_REGISTERED', $deviceHash, 'Perangkat baru didaftarkan sebagai perangkat utama.');
        device_cookie_pertahankan($installUuid);
        return ['allowed' => true, 'code' => 'REGISTERED_NEW', 'message' => 'Perangkat berhasil didaftarkan.'];
    }

    // --- Perangkat diblokir admin ---
    if ($binding['status'] === 'BLOCKED') {
        security_log($siswaId, 'DEVICE_BLOCKED', $deviceHash, 'Percobaan presensi dari device yang diblokir.');
        return ['allowed' => false, 'code' => 'BLOCKED', 'message' => 'Perangkat ini diblokir. Silakan hubungi administrator sekolah.'];
    }

    // --- Cocokkan hash ---
    if (hash_equals($binding['device_hash'], $deviceHash)) {
        $upd = getDB()->prepare(
            'UPDATE device_bindings SET last_used_at = NOW(), ip_address = :ip, status = "ACTIVE" WHERE id = :id'
        );
        $upd->execute([':ip' => $ip, ':id' => $binding['id']]);
        security_log($siswaId, 'DEVICE_MATCH', $deviceHash);
        device_cookie_pertahankan($installUuid);
        return ['allowed' => true, 'code' => 'MATCH', 'message' => 'Perangkat sesuai.'];
    }

    // --- Tidak cocok: perangkat lain ---
    security_log($siswaId, 'DEVICE_MISMATCH', $deviceHash, 'Percobaan presensi dari perangkat yang tidak terdaftar.');
    return [
        'allowed' => false,
        'code'    => 'MISMATCH',
        'message' => 'Perangkat tidak terdaftar. Gunakan perangkat yang telah didaftarkan untuk presensi.',
    ];
}

/**
 * Reset device oleh admin. Mengosongkan binding sehingga siswa dapat
 * mendaftarkan perangkat baru pada presensi berikutnya.
 * Membatasi jumlah reset per bulan sesuai pengaturan.
 */
function reset_device(int $siswaId, int $adminId, string $reason): array
{
    $db = getDB();
    $settings = get_settings();
    $maxReset = (int)($settings['maks_reset_device_per_bulan'] ?? 2);

    $count = $db->prepare(
        'SELECT COUNT(*) AS c FROM device_reset_logs
         WHERE siswa_id = :sid AND created_at >= :awal_bulan
           AND (reason IS NULL OR (reason NOT LIKE \'[MASSAL]%\' AND reason NOT LIKE \'[RESET AKUN]%\'))'
    );
    $count->execute([':sid' => $siswaId, ':awal_bulan' => date('Y-m-01 00:00:00')]);
    $used = (int) $count->fetch()['c'];

    if ($used >= $maxReset) {
        return ['success' => false, 'message' => "Batas reset perangkat bulan ini ({$maxReset}x) sudah tercapai."];
    }

    $binding = get_device_binding($siswaId);
    $oldHash = $binding['device_hash'] ?? null;

    $db->beginTransaction();
    try {
        $del = $db->prepare('DELETE FROM device_bindings WHERE siswa_id = :sid');
        $del->execute([':sid' => $siswaId]);

        $log = $db->prepare(
            'INSERT INTO device_reset_logs (siswa_id, old_device_hash, admin_id, reason, created_at)
             VALUES (:sid, :hash, :admin, :reason, NOW())'
        );
        $log->execute([':sid' => $siswaId, ':hash' => $oldHash, ':admin' => $adminId, ':reason' => $reason]);

        $db->commit();
        catat_aktivitas('RESET', 'device', 'Reset perangkat ' . nama_siswa_log($siswaId) . '. Alasan: ' . $reason);
        return ['success' => true, 'message' => 'Perangkat siswa berhasil direset.'];
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Reset device error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Gagal mereset perangkat.'];
    }
}

/**
 * Tentukan sasaran reset massal dari nilai "kelas:X-A", "jenjang:XI", "manual", atau "terpilih".
 * Hanya siswa AKTIF yang punya perangkat terdaftar yang ikut.
 * Return null bila sasaran tidak valid (kelas/jenjang tidak ada).
 * Return: ['label' => 'kelas X-A', 'siswa_ids' => [..], 'diblokir' => n]
 */
function ambil_target_reset_massal(string $sasaran): ?array
{
    $db = getDB();
    $kelasAktif = $db->query("SELECT DISTINCT kelas FROM siswa WHERE status = 'aktif'")->fetchAll(PDO::FETCH_COLUMN);

    if ($sasaran === 'manual' || $sasaran === 'terpilih') {
        // Manual / terpilih dari tabel: semua siswa aktif berperangkat boleh dipilih lewat checklist.
        // Yang benar-benar diproses tetap hanya id yang dicentang (lihat panel/device.php).
        $cocok = fn(string $k): bool => true;
        $label = $sasaran === 'terpilih' ? 'siswa terpilih dari tabel' : 'pilihan manual';
    } elseif (strncmp($sasaran, 'kelas:', 6) === 0) {
        $kelas = trim(substr($sasaran, 6));
        if (!in_array($kelas, $kelasAktif, true)) return null;
        $cocok = fn(string $k): bool => $k === $kelas;
        $label = 'kelas ' . $kelas;
    } elseif (strncmp($sasaran, 'jenjang:', 8) === 0) {
        $jenjang = trim(substr($sasaran, 8));
        if (!in_array($jenjang, array_map('ambil_jenjang', $kelasAktif), true)) return null;
        $cocok = fn(string $k): bool => ambil_jenjang($k) === $jenjang;
        $label = 'jenjang ' . $jenjang;
    } else {
        return null;
    }

    $rows = $db->query(
        "SELECT s.id, s.kelas, db.status
         FROM siswa s JOIN device_bindings db ON db.siswa_id = s.id
         WHERE s.status = 'aktif'"
    )->fetchAll();

    $ids = [];
    $diblokir = 0;
    foreach ($rows as $r) {
        if (!$cocok((string) $r['kelas'])) continue;
        $ids[] = (int) $r['id'];
        if ($r['status'] === 'BLOCKED') $diblokir++;
    }
    return ['label' => $label, 'siswa_ids' => $ids, 'diblokir' => $diblokir];
}

/**
 * Reset perangkat secara massal (aksi administratif).
 * - Tidak memeriksa/menghabiskan batas reset bulanan siswa (log diberi awalan [MASSAL]).
 * - Perangkat berstatus BLOCKED dilewati kecuali $termasukBlokir = true, supaya
 *   pemblokiran yang disengaja tidak terhapus diam-diam.
 * - Semua dalam satu transaksi: gagal sebagian = tidak ada yang berubah.
 * Return: ['success' => bool, 'direset' => n, 'dilewati_blokir' => n, 'message' => '...']
 */
function reset_device_massal(array $siswaIds, int $adminId, string $reason, bool $termasukBlokir = false): array
{
    $siswaIds = array_values(array_unique(array_map('intval', $siswaIds)));
    if (!$siswaIds) {
        return ['success' => false, 'direset' => 0, 'dilewati_blokir' => 0, 'message' => 'Tidak ada perangkat terdaftar pada sasaran ini.'];
    }
    $alasanLog = mb_substr('[MASSAL] ' . $reason, 0, 255);
    $filterStatus = $termasukBlokir ? '' : " AND status <> 'BLOCKED'";

    $db = getDB();
    $direset = 0;
    $dilewati = 0;
    $db->beginTransaction();
    try {
        foreach (array_chunk($siswaIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));

            if (!$termasukBlokir) {
                $st = $db->prepare("SELECT COUNT(*) FROM device_bindings WHERE siswa_id IN ($in) AND status = 'BLOCKED'");
                $st->execute($chunk);
                $dilewati += (int) $st->fetchColumn();
            }

            $log = $db->prepare(
                "INSERT INTO device_reset_logs (siswa_id, old_device_hash, admin_id, reason, created_at)
                 SELECT siswa_id, device_hash, ?, ?, NOW() FROM device_bindings
                 WHERE siswa_id IN ($in)$filterStatus"
            );
            $log->execute(array_merge([$adminId, $alasanLog], $chunk));

            $del = $db->prepare("DELETE FROM device_bindings WHERE siswa_id IN ($in)$filterStatus");
            $del->execute($chunk);
            $direset += $del->rowCount();
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('Reset device massal error: ' . $e->getMessage());
        return ['success' => false, 'direset' => 0, 'dilewati_blokir' => 0, 'message' => 'Gagal mereset perangkat secara massal. Tidak ada data yang diubah.'];
    }

    return ['success' => true, 'direset' => $direset, 'dilewati_blokir' => $dilewati, 'message' => ''];
}

/**
 * Blokir / aktifkan kembali perangkat siswa (dipakai halaman admin).
 */
function set_device_status(int $siswaId, string $status): bool
{
    if (!in_array($status, ['ACTIVE', 'BLOCKED', 'TRUSTED'], true)) return false;
    $stmt = getDB()->prepare('UPDATE device_bindings SET status = :status WHERE siswa_id = :sid');
    $ok = $stmt->execute([':status' => $status, ':sid' => $siswaId]);
    if ($ok) {
        catat_aktivitas('UBAH', 'device', 'Status perangkat ' . nama_siswa_log($siswaId) . " diubah menjadi {$status}");
    }
    return $ok;
}

// ---------------------------------------------------------------------
// PENDAFTARAN PERANGKAT UNTUK SISWA YANG SUDAH TERBUKTI IDENTITASNYA
// (kode aktivasi akun portal, atau login portal). Lihat portal_siswa/aktivasi.php & perangkat.php.
// ---------------------------------------------------------------------

/** True bila permintaan datang dari aplikasi SIAP (WebView), yang menandai dirinya "SIAPApp/x.y" di User-Agent. */
function request_dari_aplikasi_siap(): bool
{
    return stripos((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'SIAPApp/') !== false;
}

/**
 * Browser SUNGGUHAN di iPhone/iPad: Safari ("Version/x ... Safari/") atau Chrome/Firefox/Edge/Opera iOS
 * (CriOS/FxiOS/EdgiOS/OPiOS). Semuanya memakai mesin WebKit milik Apple. WebView di dalam aplikasi lain
 * (Instagram, Facebook, WhatsApp, TikTok, dsb.) TIDAK dihitung karena penyimpanannya terpisah dan hilang
 * bersama aplikasinya.
 * Catatan: iPad modern mengirim User-Agent "Macintosh" (mode situs desktop) sehingga tidak terbaca di sini.
 * Ini pembeda kenyamanan (di mana identitas perangkat boleh dibuat), BUKAN batas keamanan: User-Agent bisa
 * dipalsukan; keamanan tetap pada pengikatan perangkat di server.
 */
function request_dari_ios_browser(): bool
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!preg_match('/\b(iPhone|iPad|iPod)\b/', $ua)) return false;
    if (preg_match('/\b(FBAN|FBAV|FB_IAB|Instagram|Line\/|MicroMessenger|Snapchat|TikTok|musical_ly|BytedanceWebview|GSA\/|Twitter|LinkedInApp|Telegram|WhatsApp)/i', $ua)) return false;
    return (bool) preg_match('/\bSafari\/\d/', $ua) || (bool) preg_match('/\b(CriOS|FxiOS|EdgiOS|OPiOS)\//', $ua);
}

/** Perangkat apple (iPhone/iPad) apa pun, termasuk WebView aplikasi lain. Hanya untuk memilih teks petunjuk. */
function request_dari_perangkat_apple(): bool
{
    return (bool) preg_match('/\b(iPhone|iPad|iPod)\b/', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

/** Boleh mendaftarkan perangkat dari sini? Aplikasi SIAP (Android) ATAU browser iPhone/iPad. */
function request_boleh_daftar_perangkat(): bool
{
    return request_dari_aplikasi_siap() || request_dari_ios_browser();
}

/** Nama browser untuk teks petunjuk (Safari/Chrome/Firefox/Edge/Opera). */
function nama_browser_sekarang(): string
{
    $b = parse_user_agent((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))['browser'] ?? '';
    return ($b === '' || $b === 'Unknown') ? 'browser ini' : $b;
}

/**
 * Safari di iPhone menghapus localStorage dan cookie buatan JavaScript setelah 7 hari pemakaian tanpa interaksi
 * dengan situs (ITP, berlaku juga di Chrome/Firefox iOS karena sama-sama WebKit). Cookie yang dipasang SERVER lewat
 * header HTTP mengikuti masa berlakunya sendiri, sehingga dipakai sebagai cadangan identitas perangkat: bila
 * localStorage dihapus Safari, device.js memulihkan UUID dari cookie ini. Nama cookie sama dengan milik device.js.
 * Hanya dipanggil untuk UUID perangkat yang sah (baru terdaftar / cocok dengan pengikatan di server).
 */
function device_cookie_pertahankan(string $uuid): void
{
    if (!request_dari_ios_browser() || !is_valid_install_uuid($uuid) || headers_sent()) return;
    setcookie('presensi_device_uuid', $uuid, [
        'expires'  => time() + 34560000,                 // 400 hari
        'path'     => '/',
        'secure'   => function_exists('https_aktif') ? https_aktif() : false,
        'httponly' => false,                              // device.js perlu membacanya untuk memulihkan localStorage
        'samesite' => 'Lax',
    ]);
}

/**
 * Daftarkan perangkat untuk siswa yang identitasnya SUDAH terbukti. TIDAK pernah menimpa perangkat
 * yang sudah ada (ganti HP tetap lewat reset oleh petugas) dan menghormati aturan satu perangkat
 * untuk satu siswa. Aman dipanggil di dalam transaksi pemanggil.
 *
 * @return array{status:string}  terdaftar | sudah_sama | sudah_ada_lain | dipakai_siswa_lain |
 *                               diblokir | uuid_tidak_valid | binding_nonaktif
 */
function daftarkan_perangkat_terverifikasi(int $siswaId, string $installUuid, string $via): array
{
    if (empty(get_settings()['device_binding_aktif'])) return ['status' => 'binding_nonaktif'];
    if (!is_valid_install_uuid($installUuid)) return ['status' => 'uuid_tidak_valid'];

    $hash = build_device_hash($installUuid);
    $cekAda = function () use ($siswaId, $hash): ?string {
        $b = get_device_binding($siswaId);
        if (!$b) return null;
        if ($b['status'] === 'BLOCKED') return 'diblokir';
        return hash_equals((string) $b['device_hash'], $hash) ? 'sudah_sama' : 'sudah_ada_lain';
    };

    if (($s = $cekAda()) !== null) {
        if ($s === 'sudah_sama') device_cookie_pertahankan($installUuid);
        return ['status' => $s];
    }

    $lain = get_device_binding_by_hash($hash);
    if ($lain && (int) $lain['siswa_id'] !== $siswaId) {
        security_log($siswaId, 'DEVICE_MISMATCH', $hash, 'Percobaan daftar (' . $via . ') di perangkat milik siswa ID ' . $lain['siswa_id'] . '.');
        return ['status' => 'dipakai_siswa_lain'];
    }

    $ua = parse_user_agent($_SERVER['HTTP_USER_AGENT'] ?? '');
    try {
        getDB()->prepare(
            'INSERT INTO device_bindings (siswa_id, device_uuid, device_hash, device_name, browser, os, ip_address, status, registered_at, last_used_at)
             VALUES (:sid, :uuid, :hash, :name, :browser, :os, :ip, "ACTIVE", NOW(), NOW())'
        )->execute([
            ':sid' => $siswaId, ':uuid' => null, ':hash' => $hash, ':name' => $ua['os'] . ' - ' . $ua['browser'],
            ':browser' => $ua['browser'], ':os' => $ua['os'], ':ip' => get_client_ip(),
        ]);
    } catch (PDOException $e) {
        if ((int) $e->getCode() !== 23000 && !str_contains($e->getMessage(), 'Duplicate entry')) throw $e;
        // Balapan: ada yang keburu mendaftar. Tentukan lagi siapa pemenangnya.
        if (($s = $cekAda()) !== null) return ['status' => $s];
        security_log($siswaId, 'DEVICE_MISMATCH', $hash, 'Balapan pendaftaran (' . $via . '): perangkat sudah terikat siswa lain.');
        return ['status' => 'dipakai_siswa_lain'];
    }

    security_log($siswaId, 'DEVICE_REGISTERED', $hash, 'Perangkat didaftarkan memakai ' . $via . '.');
    // Kode perangkat yang masih tertunda tidak berguna lagi (akun aktif saja; tabel bisa belum ada -> abaikan).
    try {
        getDB()->prepare("UPDATE siswa_akun SET kode_hash = NULL, kode_salt = NULL, kode_berlaku_hingga = NULL, kode_salah = 0, kode_terkunci_hingga = NULL
                          WHERE siswa_id = :sid AND status = 'aktif'")->execute([':sid' => $siswaId]);
    } catch (Throwable $e) {
    }
    device_cookie_pertahankan($installUuid);
    return ['status' => 'terdaftar'];
}

/** Teks untuk siswa (aman ditampilkan) berdasarkan status pendaftaran perangkat. */
const PESAN_PERANGKAT = [
    'terdaftar'          => 'Perangkat ini sudah terdaftar untuk presensi.',
    'sudah_sama'         => 'Perangkat ini sudah terdaftar sebelumnya.',
    'sudah_ada_lain'     => 'Perangkat presensi Anda tetap HP yang sudah terdaftar sebelumnya. Untuk menggantinya, hubungi petugas sekolah.',
    'dipakai_siswa_lain' => 'Perangkat ini tidak dapat didaftarkan. Hubungi petugas sekolah.',
    'diblokir'           => 'Perangkat Anda sedang diblokir. Hubungi petugas sekolah.',
    'uuid_tidak_valid'   => 'Perangkat belum terdaftar karena identitas perangkat tidak terbaca. Pastikan bukan mode Privat, lalu coba lagi dari aplikasi SIAP (Android) atau Safari (iPhone) dan pilih "Daftarkan perangkat ini".',
    'bukan_aplikasi'     => 'Perangkat belum didaftarkan karena Anda membuka lewat browser. Buka aplikasi SIAP, masuk, lalu pilih "Daftarkan perangkat ini".',
    'bukan_browser_ios'  => 'Perangkat belum didaftarkan karena halaman ini dibuka lewat aplikasi/peramban lain. Buka SIAP ABSEN langsung di Safari, masuk, lalu pilih "Daftarkan perangkat ini".',
    'dilewati'           => 'Perangkat belum didaftarkan. Buka aplikasi SIAP, masuk, lalu pilih "Daftarkan perangkat ini" bila ingin presensi dari HP ini.',
    'binding_nonaktif'   => '',
];


/**
 * Bolehkah siswa mendaftarkan perangkat dari portal TANPA kode dari petugas?
 * - Mode wajib kode MATI : boleh (tombol "Daftarkan perangkat ini").
 * - Mode wajib kode AKTIF: tidak; harus memakai KODE PERANGKAT yang diterbitkan petugas di Akun Siswa
 *   (atau kode aktivasi untuk akun yang belum aktif).
 */
function perangkat_boleh_daftar_mandiri(int $siswaId): bool
{
    return empty(get_settings()['device_wajib_kode']);
}

/**
 * Dipakai HANYA oleh "Reset akun" (reset semuanya): lepas perangkat terdaftar siswa dan catat di log reset.
 * Tidak memakai kuota reset perangkat bulanan. Mengembalikan jumlah perangkat yang dilepas.
 */
function lepas_perangkat_reset_akun(int $siswaId, int $adminId): int
{
    $db = getDB();
    $binding = get_device_binding($siswaId);
    if (!$binding) {
        return 0;
    }
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO device_reset_logs (siswa_id, old_device_hash, admin_id, reason, created_at) VALUES (:sid, :h, :a, :r, NOW())')
           ->execute([':sid' => $siswaId, ':h' => $binding['device_hash'] ?? null, ':a' => $adminId, ':r' => '[RESET AKUN] Reset akun portal siswa']);
        $db->prepare('DELETE FROM device_bindings WHERE siswa_id = :sid')->execute([':sid' => $siswaId]);
        $db->commit();
        return 1;
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('Lepas perangkat reset akun: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Kosongkan UUID mentah pada baris yang hash-nya SUDAH v2 (UUID itu tidak dibutuhkan lagi). Baris lama (v1) dibiarkan
 * sampai perangkatnya dipakai sekali (lalu dimigrasikan dan dikosongkan oleh sinkronkan_hash_perangkat). Idempoten dan murah:
 * bila tidak ada yang perlu dibersihkan, hanya satu SELECT. Dijalankan dari portal_siapkan_tabel().
 * @return int jumlah baris yang dibersihkan
 */
function portal_bersihkan_uuid_lama(): int
{
    try {
        $db = getDB();
        if (!$db->query('SELECT 1 FROM device_bindings WHERE device_uuid IS NOT NULL LIMIT 1')->fetchColumn()) return 0;
        $u = $db->prepare("UPDATE device_bindings SET device_uuid = NULL
                           WHERE device_uuid IS NOT NULL AND device_hash = SHA2(CONCAT(:v, '|', device_uuid), 256)");
        $u->execute([':v' => DEVICE_HASH_VERSI]);
        return $u->rowCount();
    } catch (Throwable $e) {
        error_log('portal_bersihkan_uuid_lama: ' . $e->getMessage());
        return 0;
    }
}
