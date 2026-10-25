<?php

/**
 * PORTAL SISWA — akun, aktivasi, dan sesi siswa.
 *
 * TERPISAH TOTAL dari admin:
 *  - Sesi sendiri (nama cookie SIAP_SISWA, path /portal): cookie admin tidak pernah dibaca
 *    portal, dan cookie portal tidak pernah dikirim ke halaman admin.
 *  - Tabel sendiri (siswa_akun), BUKAN tabel users milik admin -> tidak ada jalan naik ke panel admin.
 *  - Pembatas percobaan sendiri (portal_attempts) agar kegagalan siswa di Wi-Fi sekolah
 *    (satu IP publik) tidak ikut mengunci login admin.
 *
 * Username = NISN (dibaca dari tabel siswa, jadi selalu mengikuti NISN terkini).
 * Siswa TIDAK mendaftar sendiri: admin menerbitkan kode aktivasi sekali pakai, lalu siswa
 * menetapkan password sendiri. Password tidak pernah diketahui admin.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/device_binding.php';

const PORTAL_SESI_NAMA        = 'SIAP_SISWA';
const PORTAL_KODE_HARI        = 7;                                 // masa berlaku kode aktivasi
const PORTAL_KODE_PANJANG     = 8;
const PORTAL_KODE_ALFABET     = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // tanpa I, L, O, 0, 1 (mudah tertukar)
const PORTAL_KODE_PERANGKAT_HARI = 3;                             // masa berlaku kode perangkat (akun yang SUDAH aktif)
const PORTAL_KODE_MAKS_SALAH  = 5;                                 // salah ketik kode -> dikunci sementara
const PORTAL_KODE_KUNCI_MENIT = 15;
const PORTAL_PASSWORD_MIN     = 8;
const PORTAL_MAKS_GAGAL_NISN  = 5;                                 // gagal login per NISN / 15 menit
const PORTAL_MAKS_GAGAL_IP    = 100;                               // longgar: Wi-Fi sekolah = 1 IP untuk banyak siswa
const PORTAL_JENDELA_DETIK    = 900;
const PORTAL_IDLE_DETIK       = 7200;                              // 2 jam tanpa aktivitas -> keluar
const PORTAL_DUMMY_HASH       = '$2y$10$oC/uUCVb5K6tvr9/kKopY.B3ajcc2Gto/s28srtKZOUAdsHWdwxQy';                  // pembanding agar waktu respons NISN tak dikenal = NISN dikenal

// ---------------------------------------------------------------------
// URL & SESI
// ---------------------------------------------------------------------
/** Halaman masuk TUNGGAL (petugas & siswa). Siswa dibedakan otomatis dari username/NISN yang diketik. */
function portal_login_url(array $query = []): string
{
    $q = bangun_query($query);
    return BASE_URL . '/login.php' . ($q !== '' ? '?' . $q : '');
}

function portal_url(string $sub = '', array $query = []): string
{
    $q = bangun_query($query);
    return BASE_URL . '/portal' . ($sub !== '' ? '/' . $sub : '') . ($q !== '' ? '?' . $q : '');
}

/** Cookie hanya dikirim untuk path /portal (menghormati BASE_URL bila aplikasi di subfolder). */
function portal_path_cookie(): string
{
    return rtrim((string) (parse_url(BASE_URL, PHP_URL_PATH) ?? ''), '/') . '/portal';
}

function portal_mulai_sesi(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() === PORTAL_SESI_NAMA) return;
        session_write_close();                    // jangan pernah berbagi sesi dengan admin
    }
    session_name(PORTAL_SESI_NAMA);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => portal_path_cookie(),
        'secure'   => https_aktif(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------------
// TABEL (dibuat otomatis seperti login_attempts; SQL manual: database/migration_akun_siswa.sql)
// ---------------------------------------------------------------------
/** Apakah kolom penanda password sementara tersedia (diisi oleh portal_siapkan_tabel). */
function portal_kolom_sementara(?bool $set = null): bool
{
    static $ada = false;
    if ($set !== null) $ada = $set;
    return $ada;
}

function portal_siapkan_tabel(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db = getDB();
        if (!$db->query("SHOW TABLES LIKE 'siswa_akun'")->fetchColumn()) {
            $db->exec("CREATE TABLE IF NOT EXISTS siswa_akun (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                siswa_id INT UNSIGNED NOT NULL,
                password_hash VARCHAR(255) NULL,
                status ENUM('menunggu','aktif','nonaktif') NOT NULL DEFAULT 'menunggu',
                kode_hash VARCHAR(128) NULL,
                kode_salt VARCHAR(32) NULL,
                kode_berlaku_hingga DATETIME NULL,
                kode_salah INT UNSIGNED NOT NULL DEFAULT 0,
                kode_terkunci_hingga DATETIME NULL,
                sesi_versi INT UNSIGNED NOT NULL DEFAULT 1,
                dibuat_oleh INT UNSIGNED NULL,
                login_terakhir DATETIME NULL,
                password_diubah DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_akun_siswa (siswa_id),
                CONSTRAINT fk_akun_siswa FOREIGN KEY (siswa_id) REFERENCES siswa (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!$db->query("SHOW TABLES LIKE 'portal_attempts'")->fetchColumn()) {
            $db->exec("CREATE TABLE IF NOT EXISTS portal_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                kunci VARCHAR(60) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_pa_kunci (kunci, created_at),
                INDEX idx_pa_ip (ip_address, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        // Penanda "password sementara" (hasil reset admin): siswa WAJIB menggantinya saat masuk. Ditambahkan otomatis di
        // sini (awal permintaan, di luar transaksi) karena ALTER TABLE memaksa commit implisit di MySQL/MariaDB.
        try {
            if (!$db->query("SHOW COLUMNS FROM siswa_akun LIKE 'password_sementara'")->fetchColumn()) {
                $db->exec('ALTER TABLE siswa_akun ADD COLUMN password_sementara TINYINT(1) NOT NULL DEFAULT 0 AFTER password_diubah');
            }
            portal_kolom_sementara(true);
        } catch (Throwable $e) {
            error_log('portal_siapkan_tabel (password_sementara): ' . $e->getMessage());
            portal_kolom_sementara(false);          // fitur "wajib ganti" nonaktif; reset/ganti password tetap jalan
        }
        // UUID perangkat kini juga kunci masuk otomatis: pastikan UUID mentah tidak tersisa di database.
        if (function_exists('portal_bersihkan_uuid_lama')) portal_bersihkan_uuid_lama();
        return $ok = true;
    } catch (Throwable $e) {
        error_log('portal_siapkan_tabel: ' . $e->getMessage());
        return $ok = false;
    }
}

/** Kolom pengaturan portal dibuat otomatis oleh get_settings(); bila belum ada dianggap AKTIF. */
function portal_aktif(): bool
{
    $s = get_settings();
    return !array_key_exists('portal_siswa_aktif', $s) || (int) $s['portal_siswa_aktif'] === 1;
}

// ---------------------------------------------------------------------
// PEMBATAS PERCOBAAN (per kunci + per IP). Gagal -> catat; berhasil -> bersihkan.
// ---------------------------------------------------------------------
function portal_kunci_sisa(string $kunci): int
{
    try {
        $db = getDB();
        $w  = PORTAL_JENDELA_DETIK;
        $hitung = function (string $kolom, string $nilai, int $maks) use ($db, $w): int {
            // $kolom, $w, $maks adalah konstanta dari kode ini (bukan input pengguna).
            $st = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM portal_attempts
                                WHERE $kolom = :v AND created_at > (NOW() - INTERVAL $w SECOND)
                                ORDER BY id DESC LIMIT $maks");
            $st->execute([':v' => $nilai]);
            $umur = $st->fetchAll(PDO::FETCH_COLUMN);
            return count($umur) < $maks ? 0 : max(1, $w - (int) end($umur));
        };
        return max($hitung('kunci', $kunci, PORTAL_MAKS_GAGAL_NISN), $hitung('ip_address', get_client_ip(), PORTAL_MAKS_GAGAL_IP));
    } catch (Throwable $e) {
        error_log('portal_kunci_sisa: ' . $e->getMessage());
        return 0;
    }
}

function portal_catat_gagal(string $kunci): void
{
    try {
        $db = getDB();
        $db->prepare('INSERT INTO portal_attempts (kunci, ip_address, created_at) VALUES (:k, :ip, NOW())')
            ->execute([':k' => substr($kunci, 0, 60), ':ip' => substr(get_client_ip(), 0, 45)]);
        if (random_int(1, 20) === 1) $db->exec('DELETE FROM portal_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    } catch (Throwable $e) {
        error_log('portal_catat_gagal: ' . $e->getMessage());
    }
}

function portal_bersihkan_gagal(string $kunci): void
{
    try {
        getDB()->prepare('DELETE FROM portal_attempts WHERE kunci = :k')->execute([':k' => substr($kunci, 0, 60)]);
    } catch (Throwable $e) {
        error_log('portal_bersihkan_gagal: ' . $e->getMessage());
    }
}

function portal_menit_teks(int $detik): string
{
    $m = (int) ceil($detik / 60);
    return $m . ' menit';
}

// ---------------------------------------------------------------------
// NISN, KODE AKTIVASI, PASSWORD
// ---------------------------------------------------------------------
function portal_nisn(string $input): string
{
    return substr((string) preg_replace('/[^0-9A-Za-z]/', '', trim($input)), 0, 30);
}

/** Kode acak berbasis CSPRNG, mis. "K7M29QXA". */
function portal_kode_acak(): string
{
    $a = PORTAL_KODE_ALFABET;
    $n = strlen($a);
    $k = '';
    for ($i = 0; $i < PORTAL_KODE_PANJANG; $i++) $k .= $a[random_int(0, $n - 1)];
    return $k;
}

/** Tampilan "K7M2-9QXA". */
function portal_kode_tampil(string $kode): string
{
    return substr($kode, 0, 4) . '-' . substr($kode, 4);
}

/** Input siswa dirapikan: huruf besar, tanpa spasi/tanda hubung. */
function portal_kode_normalisasi(string $input): string
{
    return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $input));
}

/** PBKDF2 + garam per baris: kode berumur 7 hari tidak boleh mudah dibongkar bila basis data bocor. */
function portal_hash_kode(string $kode, string $salt): string
{
    return hash_pbkdf2('sha256', $kode, $salt, 10000, 64);
}

const PORTAL_PASSWORD_UMUM = [
    '12345678',
    '123456789',
    '1234567890',
    '11111111',
    '00000000',
    '87654321',
    'password',
    'password1',
    'password123',
    'qwerty123',
    'qwertyui',
    'abcd1234',
    'abc12345',
    'iloveyou',
    'sekolah123',
    'madrasah123',
    'siswa123',
    'admin123'
];

/** Pesan galat bila password tidak memenuhi syarat; null = lolos. */
function portal_validasi_password(string $pw, array $akun): ?string
{
    if (mb_strlen($pw) < PORTAL_PASSWORD_MIN) return 'Password minimal ' . PORTAL_PASSWORD_MIN . ' karakter.';
    if (strlen($pw) > 72) return 'Password maksimal 72 karakter.';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw)) return 'Password harus mengandung huruf dan angka.';
    $l = mb_strtolower($pw);
    if (count(array_unique(mb_str_split($l))) < 3) return 'Password terlalu mudah ditebak.';
    if (in_array($l, PORTAL_PASSWORD_UMUM, true)) return 'Password terlalu umum, pilih yang lain.';
    foreach ([$akun['nisn'] ?? '', $akun['nis'] ?? ''] as $rahasia) {
        if ($rahasia !== '' && $l === mb_strtolower((string) $rahasia)) return 'Password tidak boleh sama dengan NISN atau NIS.';
    }
    return null;
}

// ---------------------------------------------------------------------
// AKUN
// ---------------------------------------------------------------------
const PORTAL_AKUN_SELECT = "SELECT a.*, s.nama, s.nis, s.nisn, s.kelas, s.jurusan, s.foto, s.status AS siswa_status,
        (a.kode_berlaku_hingga IS NOT NULL AND a.kode_berlaku_hingga >= NOW()) AS kode_valid,
        GREATEST(0, COALESCE(TIMESTAMPDIFF(SECOND, NOW(), a.kode_terkunci_hingga), 0)) AS kunci_sisa
    FROM siswa_akun a JOIN siswa s ON s.id = a.siswa_id";

/** Cari akun lewat NISN. null bila tidak ada ATAU NISN dipakai lebih dari satu akun (ambigu -> ditolak). */
function portal_cari_akun_nisn(string $nisn): ?array
{
    if ($nisn === '') return null;
    $st = getDB()->prepare(PORTAL_AKUN_SELECT . ' WHERE s.nisn = :n LIMIT 2');
    $st->execute([':n' => $nisn]);
    $rows = $st->fetchAll();
    return count($rows) === 1 ? $rows[0] : null;
}

function portal_cari_akun_id(int $akunId): ?array
{
    $st = getDB()->prepare(PORTAL_AKUN_SELECT . ' WHERE a.id = :id');
    $st->execute([':id' => $akunId]);
    return $st->fetch() ?: null;
}

/** Siswa boleh dibuatkan akun? Syarat: aktif, NISN terisi, dan NISN unik di antara siswa. */
function portal_cek_layak(int $siswaId): array
{
    $st = getDB()->prepare('SELECT status, nisn FROM siswa WHERE id = :id');
    $st->execute([':id' => $siswaId]);
    $s = $st->fetch();
    if (!$s) return [false, 'Siswa tidak ditemukan'];
    if ($s['status'] !== 'aktif') return [false, 'Siswa nonaktif'];
    $nisn = trim((string) $s['nisn']);
    if ($nisn === '') return [false, 'NISN belum diisi'];
    $c = getDB()->prepare('SELECT COUNT(*) FROM siswa WHERE nisn = :n');
    $c->execute([':n' => $nisn]);
    if ((int) $c->fetchColumn() > 1) return [false, 'NISN dipakai lebih dari satu siswa'];
    return [true, ''];
}

/**
 * Terbitkan kode aktivasi baru. Bila akun sudah aktif, ini = RESET (password lama dihapus,
 * semua sesi lama tidak berlaku). Akun yang diblokir tidak diubah.
 * @return array{ok:bool,alasan?:string,kode?:string,kode_tampil?:string,berlaku?:string,reset?:bool}
 */
function portal_terbitkan_kode(int $siswaId, ?int $adminId): array
{
    [$layak, $alasan] = portal_cek_layak($siswaId);
    if (!$layak) return ['ok' => false, 'alasan' => $alasan];

    $db = getDB();
    $st = $db->prepare('SELECT status FROM siswa_akun WHERE siswa_id = :id');
    $st->execute([':id' => $siswaId]);
    $ada = $st->fetch();
    if ($ada && $ada['status'] === 'nonaktif') return ['ok' => false, 'alasan' => 'Akun sedang diblokir (aktifkan dulu)'];

    $kode = portal_kode_acak();
    $salt = bin2hex(random_bytes(8));
    $hari = (int) PORTAL_KODE_HARI;
    $db->prepare("INSERT INTO siswa_akun (siswa_id, status, kode_hash, kode_salt, kode_berlaku_hingga, kode_salah, kode_terkunci_hingga, dibuat_oleh)
                  VALUES (:sid, 'menunggu', :h, :s, NOW() + INTERVAL $hari DAY, 0, NULL, :adm)
                  ON DUPLICATE KEY UPDATE status = 'menunggu', password_hash = NULL, kode_hash = VALUES(kode_hash),
                      kode_salt = VALUES(kode_salt), kode_berlaku_hingga = VALUES(kode_berlaku_hingga), kode_salah = 0,
                      kode_terkunci_hingga = NULL, dibuat_oleh = VALUES(dibuat_oleh), sesi_versi = sesi_versi + 1" . (portal_kolom_sementara() ? ', password_sementara = 0' : ''))
        ->execute([':sid' => $siswaId, ':h' => portal_hash_kode($kode, $salt), ':s' => $salt, ':adm' => $adminId]);

    $b = $db->prepare('SELECT kode_berlaku_hingga FROM siswa_akun WHERE siswa_id = :id');
    $b->execute([':id' => $siswaId]);
    return [
        'ok' => true,
        'kode' => $kode,
        'kode_tampil' => portal_kode_tampil($kode),
        'berlaku' => (string) $b->fetchColumn(),
        'reset' => (bool) $ada
    ];
}

/**
 * KODE PERANGKAT: kode sekali pakai untuk mendaftarkan perangkat baru siswa yang akunnya SUDAH aktif dan
 * perangkat lamanya sudah dilepas (Ganti HP). Akun TIDAK disentuh: password, status, dan sesi login tetap.
 * Memakai kolom kode_* yang kosong pada akun aktif (tanpa perubahan skema). Tidak bisa dipakai untuk aktivasi
 * (aktivasi hanya menerima akun berstatus menunggu) dan sebaliknya.
 * @return array{ok:bool,alasan?:string,kode?:string,kode_tampil?:string,berlaku?:string}
 */
function portal_terbitkan_kode_perangkat(int $siswaId, ?int $adminId): array
{
    [$layak, $alasan] = portal_cek_layak($siswaId);
    if (!$layak) return ['ok' => false, 'alasan' => $alasan];

    $db = getDB();
    $st = $db->prepare('SELECT status FROM siswa_akun WHERE siswa_id = :id');
    $st->execute([':id' => $siswaId]);
    $status = $st->fetchColumn();
    if ($status !== 'aktif') return ['ok' => false, 'alasan' => 'Akun belum aktif. Pakai kode aktivasi (Buat kode / Kode baru).'];
    if (get_device_binding($siswaId)) {
        return ['ok' => false, 'alasan' => 'Siswa ini masih punya perangkat terdaftar. Gunakan Ganti HP lebih dulu.'];
    }

    $kode = portal_kode_acak();
    $salt = bin2hex(random_bytes(8));
    $hari = (int) PORTAL_KODE_PERANGKAT_HARI;
    $u = $db->prepare("UPDATE siswa_akun SET kode_hash = :h, kode_salt = :s, kode_berlaku_hingga = NOW() + INTERVAL $hari DAY,
            kode_salah = 0, kode_terkunci_hingga = NULL, dibuat_oleh = :adm
        WHERE siswa_id = :sid AND status = 'aktif'");
    $u->execute([':h' => portal_hash_kode($kode, $salt), ':s' => $salt, ':adm' => $adminId, ':sid' => $siswaId]);
    if ($u->rowCount() !== 1) return ['ok' => false, 'alasan' => 'Akun tidak ditemukan atau berubah status.'];

    $b = $db->prepare('SELECT kode_berlaku_hingga FROM siswa_akun WHERE siswa_id = :id');
    $b->execute([':id' => $siswaId]);
    return ['ok' => true, 'kode' => $kode, 'kode_tampil' => portal_kode_tampil($kode), 'berlaku' => (string) $b->fetchColumn()];
}

/** Ada kode perangkat yang belum dipakai dan masih berlaku untuk siswa ini? (hanya akun aktif) */
function portal_kode_perangkat_aktif(int $siswaId): bool
{
    try {
        $st = getDB()->prepare("SELECT 1 FROM siswa_akun WHERE siswa_id = :id AND status = 'aktif'
                                AND kode_hash IS NOT NULL AND kode_berlaku_hingga >= NOW() LIMIT 1");
        $st->execute([':id' => $siswaId]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Siswa memasukkan kode perangkat di portal (dari aplikasi SIAP) -> perangkat didaftarkan. Pengamanan sama dengan
 * aktivasi: kode di-hash, kedaluwarsa, 5 salah = terkunci, pesan galat seragam. Kode TIDAK habis bila pendaftaran
 * gagal karena alasan lain (mis. HP itu milik siswa lain), hanya bila perangkat benar-benar terdaftar.
 * @return array{ok:bool,pesan:string}
 */
function portal_daftar_perangkat_dengan_kode(int $akunId, string $kodeInput, string $uuid): array
{
    $umum = 'Kode perangkat tidak valid atau sudah kedaluwarsa. Minta kode baru ke petugas sekolah.';
    $akun = portal_cari_akun_id($akunId);
    if (!$akun || $akun['status'] !== 'aktif' || $akun['siswa_status'] !== 'aktif') return ['ok' => false, 'pesan' => $umum];

    $siswaId = (int) $akun['siswa_id'];
    $kunci = 'kdev:' . $siswaId;
    $sisa = portal_kunci_sisa($kunci);
    if ($sisa > 0) return ['ok' => false, 'pesan' => 'Terlalu banyak percobaan. Coba lagi dalam ' . portal_menit_teks($sisa) . '.'];

    if (!$akun['kode_hash'] || !(int) $akun['kode_valid']) {
        portal_catat_gagal($kunci);
        return ['ok' => false, 'pesan' => $umum];
    }
    if ((int) $akun['kunci_sisa'] > 0) {
        return ['ok' => false, 'pesan' => 'Terlalu banyak salah kode. Coba lagi dalam ' . portal_menit_teks((int) $akun['kunci_sisa']) . ', atau minta kode baru ke petugas.'];
    }

    $db = getDB();
    if (!hash_equals((string) $akun['kode_hash'], portal_hash_kode(portal_kode_normalisasi($kodeInput), (string) $akun['kode_salt']))) {
        $db->prepare('UPDATE siswa_akun SET kode_salah = kode_salah + 1 WHERE id = :id')->execute([':id' => $akun['id']]);
        $baru = $db->prepare('SELECT kode_salah FROM siswa_akun WHERE id = :id');
        $baru->execute([':id' => $akun['id']]);
        if ((int) $baru->fetchColumn() >= PORTAL_KODE_MAKS_SALAH) {
            $m = (int) PORTAL_KODE_KUNCI_MENIT;
            $db->prepare("UPDATE siswa_akun SET kode_salah = 0, kode_terkunci_hingga = NOW() + INTERVAL $m MINUTE WHERE id = :id")->execute([':id' => $akun['id']]);
            security_log($siswaId, 'PORTAL_AKTIVASI_GAGAL', null, 'Kode perangkat terkunci ' . $m . ' menit setelah ' . PORTAL_KODE_MAKS_SALAH . ' kode salah');
        }
        portal_catat_gagal($kunci);
        security_log($siswaId, 'PORTAL_AKTIVASI_GAGAL', null, 'Kode perangkat salah');
        return ['ok' => false, 'pesan' => $umum];
    }

    // Kode benar -> daftarkan perangkat. Akun (password/status/sesi) tidak diubah sama sekali.
    $h = daftarkan_perangkat_terverifikasi($siswaId, $uuid, 'kode perangkat');
    $status = (string) $h['status'];
    $pesan = PESAN_PERANGKAT[$status] ?? '';
    if (in_array($status, ['terdaftar', 'sudah_sama'], true)) {
        $db->prepare("UPDATE siswa_akun SET kode_hash = NULL, kode_salt = NULL, kode_berlaku_hingga = NULL, kode_salah = 0, kode_terkunci_hingga = NULL
                      WHERE id = :id AND status = 'aktif'")->execute([':id' => $akun['id']]);
        portal_bersihkan_gagal($kunci);
        return ['ok' => true, 'pesan' => $pesan !== '' ? $pesan : 'Perangkat ini sudah terdaftar untuk presensi.'];
    }
    return ['ok' => false, 'pesan' => $pesan !== '' ? $pesan : $umum];
}

/**
 * Aktivasi: NISN + kode + password baru. Semua kegagalan identitas memakai pesan yang sama
 * supaya NISN yang punya akun tidak bisa ditebak-tebak.
 * Perangkat didaftarkan (bila diminta, dari dalam aplikasi SIAP, dan identitas perangkat valid) dalam TRANSAKSI
 * yang sama dengan penetapan password, dan HANYA setelah kode terbukti benar dan password lolos syarat.
 * Gagal mendaftarkan perangkat tidak membatalkan aktivasi akun.
 * @return array{ok:bool,pesan?:string,galat_password?:bool,perangkat?:string,akun_id?:int}
 */
function portal_aktivasi(string $nisn, string $kodeInput, string $password, ?string $uuidPerangkat = null, bool $mauDaftarPerangkat = false): array
{
    $umum = 'NISN atau kode aktivasi tidak valid, atau sudah kedaluwarsa. Hubungi petugas sekolah bila perlu kode baru.';
    $kunci = 'aktivasi:' . $nisn;

    $sisa = portal_kunci_sisa($kunci);
    if ($sisa > 0) return ['ok' => false, 'pesan' => 'Terlalu banyak percobaan. Coba lagi dalam ' . portal_menit_teks($sisa) . '.'];

    $akun = portal_cari_akun_nisn($nisn);
    if (!$akun || $akun['status'] !== 'menunggu' || $akun['siswa_status'] !== 'aktif' || !$akun['kode_hash'] || !(int) $akun['kode_valid']) {
        portal_catat_gagal($kunci);
        security_log($akun ? (int) $akun['siswa_id'] : null, 'PORTAL_AKTIVASI_GAGAL', null, 'Kode/NISN tidak valid. NISN ' . $nisn);
        return ['ok' => false, 'pesan' => $umum];
    }
    if ((int) $akun['kunci_sisa'] > 0) {
        return ['ok' => false, 'pesan' => 'Terlalu banyak salah kode. Coba lagi dalam ' . portal_menit_teks((int) $akun['kunci_sisa']) . ', atau minta kode baru ke petugas.'];
    }

    $kode = portal_kode_normalisasi($kodeInput);
    if (!hash_equals((string) $akun['kode_hash'], portal_hash_kode($kode, (string) $akun['kode_salt']))) {
        $db = getDB();
        $db->prepare('UPDATE siswa_akun SET kode_salah = kode_salah + 1 WHERE id = :id')->execute([':id' => $akun['id']]);
        $baru = $db->prepare('SELECT kode_salah FROM siswa_akun WHERE id = :id');
        $baru->execute([':id' => $akun['id']]);
        if ((int) $baru->fetchColumn() >= PORTAL_KODE_MAKS_SALAH) {
            $m = (int) PORTAL_KODE_KUNCI_MENIT;
            $db->prepare("UPDATE siswa_akun SET kode_salah = 0, kode_terkunci_hingga = NOW() + INTERVAL $m MINUTE WHERE id = :id")->execute([':id' => $akun['id']]);
            security_log((int) $akun['siswa_id'], 'PORTAL_AKTIVASI_GAGAL', null, 'Terkunci ' . $m . ' menit setelah ' . PORTAL_KODE_MAKS_SALAH . ' kode salah');
        }
        portal_catat_gagal($kunci);
        security_log((int) $akun['siswa_id'], 'PORTAL_AKTIVASI_GAGAL', null, 'Kode salah');
        return ['ok' => false, 'pesan' => $umum];
    }

    // Kode benar -> baru periksa syarat password (salah password tidak menghabiskan kode).
    $galat = portal_validasi_password($password, $akun);
    if ($galat !== null) return ['ok' => false, 'pesan' => $galat, 'galat_password' => true];

    $db = getDB();
    try {
        $db->beginTransaction();
        $u = $db->prepare("UPDATE siswa_akun SET password_hash = :p, status = 'aktif', kode_hash = NULL, kode_salt = NULL,
                kode_berlaku_hingga = NULL, kode_salah = 0, kode_terkunci_hingga = NULL, password_diubah = NOW(), sesi_versi = sesi_versi + 1" . (portal_kolom_sementara() ? ', password_sementara = 0' : '') . "
            WHERE id = :id AND status = 'menunggu'");
        $u->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $akun['id']]);
        if ($u->rowCount() !== 1) {
            $db->rollBack();
            return ['ok' => false, 'pesan' => $umum];
        }

        if (!$mauDaftarPerangkat) $perangkat = 'dilewati';
        elseif (!request_boleh_daftar_perangkat()) $perangkat = request_dari_perangkat_apple() ? 'bukan_browser_ios' : 'bukan_aplikasi';
        else $perangkat = daftarkan_perangkat_terverifikasi((int) $akun['siswa_id'], (string) $uuidPerangkat, 'kode aktivasi akun portal')['status'];
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('portal_aktivasi: ' . $e->getMessage());
        return ['ok' => false, 'pesan' => 'Terjadi kesalahan di server. Kode Anda belum terpakai, silakan coba lagi.'];
    }

    portal_bersihkan_gagal($kunci);
    security_log((int) $akun['siswa_id'], 'PORTAL_AKTIVASI_OK', null, 'Akun portal diaktifkan (perangkat: ' . $perangkat . ')');
    return ['ok' => true, 'perangkat' => $perangkat, 'akun_id' => (int) $akun['id']];
}

/** @return array{ok:bool,pesan?:string,akun?:array} */
function portal_coba_login(string $nisn, string $password): array
{
    $kunci = 'masuk:' . $nisn;
    $sisa = portal_kunci_sisa($kunci);
    if ($sisa > 0) {
        security_log(null, 'PORTAL_LOGIN_TERKUNCI', null, 'NISN ' . $nisn);
        return ['ok' => false, 'terkunci' => true, 'sisa' => $sisa, 'pesan' => 'Terlalu banyak percobaan. Coba lagi dalam ' . portal_menit_teks($sisa) . '.'];
    }

    $akun = portal_cari_akun_nisn($nisn);
    // Selalu menjalankan password_verify (walau akun tidak ada) agar waktu respons tidak membocorkan NISN.
    $benar = password_verify($password, $akun['password_hash'] ?? PORTAL_DUMMY_HASH);
    if ($akun && $benar && $akun['status'] === 'aktif' && $akun['siswa_status'] === 'aktif' && $akun['password_hash']) {
        portal_bersihkan_gagal($kunci);
        if (password_needs_rehash((string) $akun['password_hash'], PASSWORD_DEFAULT)) {
            getDB()->prepare('UPDATE siswa_akun SET password_hash = :p WHERE id = :id')
                ->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $akun['id']]);
        }
        return ['ok' => true, 'akun' => $akun];
    }

    portal_catat_gagal($kunci);
    security_log($akun ? (int) $akun['siswa_id'] : null, 'PORTAL_LOGIN_GAGAL', null, 'NISN ' . $nisn);
    return ['ok' => false, 'pesan' => 'Username atau password salah.'];
}

// ---------------------------------------------------------------------
// SESI SISWA
// ---------------------------------------------------------------------
function portal_masuk_sesi(array $akun, bool $viaPerangkat = false): void
{
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['portal'] = [
        'akun_id'       => (int) $akun['id'],
        'sesi_versi'    => (int) $akun['sesi_versi'],
        'aktif'         => time(),
        'via_perangkat' => $viaPerangkat,      // true = masuk otomatis (tanpa password) lewat perangkat terdaftar
    ];
    getDB()->prepare('UPDATE siswa_akun SET login_terakhir = NOW() WHERE id = :id')->execute([':id' => $akun['id']]);
}

function portal_keluar_sesi(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

/** Siswa yang sedang login (divalidasi ulang ke database di setiap permintaan), atau null. */
function portal_siswa_sekarang(): ?array
{
    static $cache = null, $sudah = false;
    if ($sudah) return $cache;
    $sudah = true;

    $s = $_SESSION['portal'] ?? null;
    if (!is_array($s) || empty($s['akun_id'])) return $cache = null;
    if (time() - (int) ($s['aktif'] ?? 0) > PORTAL_IDLE_DETIK) {
        unset($_SESSION['portal']);
        return $cache = null;
    }

    $akun = portal_cari_akun_id((int) $s['akun_id']);
    if (!$akun || $akun['status'] !== 'aktif' || $akun['siswa_status'] !== 'aktif' || (int) $akun['sesi_versi'] !== (int) ($s['sesi_versi'] ?? 0)) {
        unset($_SESSION['portal']);                 // diblokir / nonaktif / password direset -> sesi lama mati
        return $cache = null;
    }
    $_SESSION['portal']['aktif'] = time();
    return $cache = $akun;
}

/**
 * Wajib sudah masuk. Belum masuk -> GERBANG: dicoba masuk otomatis lewat perangkat terdaftar (tanpa password); bila
 * gagal, diteruskan ke halaman login. $perluPassword = aksi sensitif (ganti password, unduh lampiran): sesi yang
 * berasal dari masuk otomatis harus membuktikan password dulu (berlaku PORTAL_PENUH_DETIK).
 */
function portal_wajib_login(bool $perluPassword = false): array
{
    $sub = (string) ($GLOBALS['PORTAL_SUB'] ?? '');
    $akun = portal_siswa_sekarang();
    if (!$akun) {
        // Gerbang sudah dilewati (parameter _g) tapi tetap tanpa sesi => cegah putaran tak berujung: langsung ke login.
        if (isset($_GET['_g'])) {
            header('Location: ' . portal_login_url());
            exit;
        }
        $lanjut = portal_lanjut_aman($sub);
        header('Location: ' . portal_url('gerbang', $lanjut !== '' ? ['lanjut' => $lanjut] : []));
        exit;
    }
    // Password sementara dari admin: wajib diganti dulu. Hanya halaman ganti password & keluar yang boleh dibuka.
    if (!empty($akun['password_sementara']) && !in_array($sub, ['password', 'keluar'], true)) {
        header('Location: ' . portal_url('password'));
        exit;
    }
    if ($perluPassword && !portal_sesi_penuh()) {
        $lanjut = portal_lanjut_aman($sub);
        header('Location: ' . portal_url('verifikasi', $lanjut !== '' ? ['lanjut' => $lanjut] : []));
        exit;
    }
    return $akun;
}

function portal_logo_url(): ?string
{
    $logo = (string) (get_settings()['logo'] ?? '');
    return ($logo !== '' && is_file(__DIR__ . '/../uploads/siswa/' . $logo)) ? BASE_URL . '/uploads/siswa/' . rawurlencode($logo) : null;
}

// ---------------------------------------------------------------------
// ADMIN: ganti / reset password akun siswa yang sudah diaktifkan
// ---------------------------------------------------------------------

/** Password sementara acak yang mudah dibaca (tanpa 0/O/1/l/I). Selalu lolos portal_validasi_password. */
function portal_buat_password_sementara(array $akun): string
{
    $huruf = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
    $angka = '23456789';
    for ($i = 0; $i < 20; $i++) {
        $c = [];
        for ($j = 0; $j < 7; $j++) $c[] = $huruf[random_int(0, strlen($huruf) - 1)];
        for ($j = 0; $j < 3; $j++) $c[] = $angka[random_int(0, strlen($angka) - 1)];
        for ($k = count($c) - 1; $k > 0; $k--) {
            $r = random_int(0, $k);
            [$c[$k], $c[$r]] = [$c[$r], $c[$k]];
        }
        $pw = implode('', $c);
        if (portal_validasi_password($pw, $akun) === null) return $pw;
    }
    return 'Tmp' . random_int(1000, 9999) . bin2hex(random_bytes(2));
}

/**
 * Admin menetapkan password akun siswa yang SUDAH diaktifkan (status aktif/diblokir).
 * Semua sesi login siswa itu keluar, kunci percobaan login dibersihkan. $sementara = siswa wajib menggantinya saat
 * masuk berikutnya (bila kolom penanda tersedia). Password tidak pernah dicatat di log.
 * @return array{ok:bool,pesan?:string}
 */
function portal_admin_set_password(int $akunId, string $password, bool $sementara, ?int $adminId, string $jenis = 'ganti'): array
{
    if (!portal_siapkan_tabel()) return ['ok' => false, 'pesan' => 'Portal belum siap.'];
    $akun = portal_cari_akun_id($akunId);
    if (!$akun || empty($akun['password_hash']) || !in_array($akun['status'], ['aktif', 'nonaktif'], true)) {
        return ['ok' => false, 'pesan' => 'Akun siswa belum diaktifkan atau tidak ditemukan.'];
    }
    if (($g = portal_validasi_password($password, $akun)) !== null) return ['ok' => false, 'pesan' => $g];

    $flag = portal_kolom_sementara() ? ', password_sementara = ' . ($sementara ? 1 : 0) : '';
    $u = getDB()->prepare("UPDATE siswa_akun SET password_hash = :p, password_diubah = NOW(), sesi_versi = sesi_versi + 1$flag
                           WHERE id = :id AND password_hash IS NOT NULL");
    $u->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $akunId]);
    if ($u->rowCount() !== 1) return ['ok' => false, 'pesan' => 'Password tidak dapat disimpan. Coba lagi.'];

    portal_bersihkan_gagal('masuk:' . $akun['nisn']);      // siswa tidak ikut terkunci karena salah ketik sebelumnya
    portal_bersihkan_gagal('ganti:' . $akunId);
    security_log(
        (int) $akun['siswa_id'],
        'PORTAL_PASSWORD_ADMIN',
        null,
        'Password ' . ($jenis === 'reset' ? 'direset' : 'diganti') . ' oleh admin' . ($sementara && portal_kolom_sementara() ? ' (wajib diganti siswa)' : '') . ($adminId ? ' #' . $adminId : '')
    );
    return ['ok' => true];
}

/** Admin mereset password: dibuat password sementara acak yang wajib diganti siswa. @return array{ok:bool,pesan?:string,password?:string,akun?:array,wajib_ganti?:bool} */
function portal_admin_reset_password(int $akunId, ?int $adminId): array
{
    if (!portal_siapkan_tabel()) return ['ok' => false, 'pesan' => 'Portal belum siap.'];
    $akun = portal_cari_akun_id($akunId);
    if (!$akun) return ['ok' => false, 'pesan' => 'Akun siswa tidak ditemukan.'];
    $pw = portal_buat_password_sementara($akun);
    $h = portal_admin_set_password($akunId, $pw, true, $adminId, 'reset');
    if (!$h['ok']) return $h;
    return ['ok' => true, 'password' => $pw, 'akun' => $akun, 'wajib_ganti' => portal_kolom_sementara()];
}

// ---------------------------------------------------------------------
// MASUK OTOMATIS LEWAT PERANGKAT TERDAFTAR
// ---------------------------------------------------------------------
const PORTAL_PENUH_DETIK = 900;   // setelah membuktikan password, aksi sensitif terbuka selama 15 menit

/** Sesi ini hasil masuk otomatis (tanpa password)? */
function portal_sesi_via_perangkat(): bool
{
    return !empty($_SESSION['portal']['via_perangkat']);
}

/** Sesi boleh melakukan aksi sensitif? Masuk dengan password = ya. Masuk otomatis = hanya bila baru membuktikan password. */
function portal_sesi_penuh(): bool
{
    if (!portal_sesi_via_perangkat()) return true;
    return (int) ($_SESSION['portal']['penuh_hingga'] ?? 0) >= time();
}

/** Alamat tujuan setelah gerbang/verifikasi: hanya halaman portal yang dikenal (tanpa pengalihan ke situs lain). */
function portal_lanjut_aman(string $sub): string
{
    $sub = trim($sub, '/');
    return preg_match('#^(riwayat|profil|izin|izin/baru|notifikasi|password|izin/\d{1,10}(/lampiran)?)$#', $sub) ? $sub : '';
}

/**
 * Masuk otomatis: UUID perangkat harus terikat (ACTIVE) ke siswa yang akunnya aktif. Syarat tambahan:
 *  - pengikatan perangkat aktif di Pengaturan, portal dibuka;
 *  - akun bukan password sementara (yang itu WAJIB masuk dengan password dulu lalu menggantinya);
 *  - perangkat tidak diblokir. Ganti HP oleh petugas langsung memutus masuk otomatis.
 * UUID acak 122-bit dan di server hanya tersimpan hash-nya (UUID mentah dikosongkan, lihat portal_bersihkan_uuid_lama), jadi mencoba-coba tidak mungkin dan bocornya database tidak membocorkan kunci masuk; sengaja TIDAK ada kunci
 * percobaan per IP karena satu WiFi sekolah dipakai banyak siswa (HP yang belum terdaftar tidak boleh menguncinya).
 * @return array{ok:bool, perlu?:string}
 */
function portal_masuk_perangkat(string $uuid): array
{
    $gagal = ['ok' => false];
    if (!portal_siapkan_tabel() || !portal_aktif()) return $gagal;
    if (empty(get_settings()['device_binding_aktif'])) return $gagal;
    if (!is_valid_install_uuid($uuid)) return $gagal;
    $b = get_device_binding_by_hash(build_device_hash($uuid));
    if (!$b || ($b['status'] ?? '') !== 'ACTIVE') return $gagal;

    $st = getDB()->prepare('SELECT id FROM siswa_akun WHERE siswa_id = :s LIMIT 1');
    $st->execute([':s' => (int) $b['siswa_id']]);
    $akunId = (int) $st->fetchColumn();
    $akun = $akunId > 0 ? portal_cari_akun_id($akunId) : null;
    // Perangkat bisa terdaftar lewat presensi QR tanpa siswa pernah mengaktifkan akun portal. Dulu kasus ini jatuh ke
    // halaman login (yang tidak bisa dipakai karena belum ada password); sekarang gerbang diarahkan ke aktivasi.
    if (!$akun || empty($akun['password_hash'])) {
        return ($akun === null || $akun['status'] !== 'nonaktif') ? ['ok' => false, 'perlu' => 'aktivasi'] : $gagal;
    }
    if ($akun['status'] !== 'aktif' || $akun['siswa_status'] !== 'aktif') return $gagal;
    if (!empty($akun['password_sementara'])) return ['ok' => false, 'perlu' => 'login'];
    portal_mulai_sesi();
    portal_masuk_sesi($akun, true);
    return ['ok' => true];
}

// ---------------------------------------------------------------------
// DATA RIWAYAT & RINGKASAN (halaman Home & Riwayat)
// ---------------------------------------------------------------------
const PORTAL_LABEL_STATUS  = ['HADIR' => 'Hadir', 'TERLAMBAT' => 'Terlambat', 'IZIN' => 'Izin', 'SAKIT' => 'Sakit', 'ALPA' => 'Alpa'];
const PORTAL_HARI_PANJANG  = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
const PORTAL_HARI_PENDEK   = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];
const PORTAL_BULAN_PANJANG = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
const PORTAL_BULAN_PENDEK  = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];

/** "Senin, 25 Mei 2026" */
function portal_tanggal_panjang(string $ymd): string
{
    $t = strtotime($ymd);
    return PORTAL_HARI_PANJANG[(int) date('N', $t)] . ', ' . (int) date('j', $t) . ' ' . PORTAL_BULAN_PANJANG[(int) date('n', $t)] . ' ' . date('Y', $t);
}

/** "Mei 2026" dari "2026-05" */
function portal_bulan_teks(string $ym): string
{
    return PORTAL_BULAN_PANJANG[(int) substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
}

/** WIB / WITA / WIT menurut zona waktu aplikasi. */
function portal_zona_label(): string
{
    return ['Asia/Jakarta' => 'WIB', 'Asia/Makassar' => 'WITA', 'Asia/Jayapura' => 'WIT'][date_default_timezone_get()] ?? date('T');
}

/** Bulan "YYYY-MM" yang valid dan tidak lebih dari bulan ini; selain itu bulan ini. */
function portal_bulan_valid(string $ym): string
{
    $skrg = date('Y-m');
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym) || $ym > $skrg || $ym < '2000-01') return $skrg;
    return $ym;
}

/** Baris presensi siswa pada tanggal tertentu (satu baris per hari), atau null. */
function portal_presensi_hari(int $siswaId, string $ymd): ?array
{
    $st = getDB()->prepare('SELECT tanggal, jam_masuk, status, metode FROM presensi WHERE siswa_id = :s AND tanggal = :t ORDER BY id DESC LIMIT 1');
    $st->execute([':s' => $siswaId, ':t' => $ymd]);
    return $st->fetch() ?: null;
}

/** Jumlah per status dalam satu bulan. @return array<string,int> */
function portal_rekap_bulan(int $siswaId, string $ym): array
{
    $awal = $ym . '-01';
    $akhir = date('Y-m-d', strtotime($awal . ' +1 month'));
    $st = getDB()->prepare('SELECT status, COUNT(*) c FROM presensi WHERE siswa_id = :s AND tanggal >= :a AND tanggal < :b GROUP BY status');
    $st->execute([':s' => $siswaId, ':a' => $awal, ':b' => $akhir]);
    $r = array_fill_keys(array_keys(PORTAL_LABEL_STATUS), 0);
    foreach ($st->fetchAll() as $row) if (isset($r[$row['status']])) $r[$row['status']] = (int) $row['c'];
    return $r;
}

/** Riwayat satu bulan (terbaru dulu), opsional difilter status. Hanya data yang ada; tidak ada hari yang "ditebak". */
function portal_riwayat_bulan(int $siswaId, string $ym, string $status = ''): array
{
    $awal = $ym . '-01';
    $akhir = date('Y-m-d', strtotime($awal . ' +1 month'));
    $sql = 'SELECT tanggal, jam_masuk, status, metode FROM presensi WHERE siswa_id = :s AND tanggal >= :a AND tanggal < :b';
    $p = [':s' => $siswaId, ':a' => $awal, ':b' => $akhir];
    if (isset(PORTAL_LABEL_STATUS[$status])) {
        $sql .= ' AND status = :st';
        $p[':st'] = $status;
    }
    $st = getDB()->prepare($sql . ' ORDER BY tanggal DESC, id DESC');
    $st->execute($p);
    return $st->fetchAll();
}

/** Daftar bulan "YYYY-MM" untuk pemilih bulan: dari bulan presensi tertua (maks. 24 bulan) sampai bulan ini, terbaru dulu. */
function portal_bulan_tersedia(int $siswaId): array
{
    $st = getDB()->prepare('SELECT MIN(tanggal) FROM presensi WHERE siswa_id = :s');
    $st->execute([':s' => $siswaId]);
    $min = (string) $st->fetchColumn();
    $awal = $min !== '' ? substr($min, 0, 7) : date('Y-m');
    $batas = date('Y-m', strtotime(date('Y-m-01') . ' -23 months'));
    if ($awal < $batas) $awal = $batas;
    $out = [];
    for ($t = strtotime(date('Y-m-01')); date('Y-m', $t) >= $awal; $t = strtotime(date('Y-m-01', $t) . ' -1 month')) {
        $out[] = date('Y-m', $t);
    }
    return $out;
}
