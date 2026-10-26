<?php
/**
 * Session bootstrap + guard akses admin.
 * Wajib di-include paling atas pada setiap halaman admin.
 */

// Header keamanan + parameter cookie sesi (httponly, samesite, secure bila HTTPS).
require_once __DIR__ . '/keamanan.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/functions.php';

function is_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    // Akses langsung ke /panel/xxx diarahkan ke URL bersih modul tsb (GET saja).
    if (!defined('APP_ROUTER') && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/panel/') !== false) {
        $modul = basename($_SERVER['SCRIPT_NAME'], '.php');
        if (in_array($modul, DAFTAR_MODUL, true)) {
            parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $qp);
            unset($qp['modul']);
            header('Location: ' . modul_url($modul, $qp), true, 302);
            exit;
        }
    }
    // Regenerasi id sesi berkala untuk mencegah session fixation.
    if (empty($_SESSION['last_regen']) || (time() - $_SESSION['last_regen']) > 900) {
        session_regenerate_id(true);
        $_SESSION['last_regen'] = time();
    }

    // Akun sudah dihapus / dinonaktifkan -> sesi lama tidak boleh dipakai lagi.
    if (!current_admin()) {
        unset($_SESSION['admin_id']);
        session_regenerate_id(true);
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    // PENEGAKAN IZIN DI SEMUA JALUR. Sebelumnya izin hanya dicek di index.php (router),
    // sehingga POST langsung ke /panel/xxx.php melewati pengecekan izin role.
    $modul = modul_saat_ini();
    if ($modul !== null) {
        if (!in_array($modul, DAFTAR_MODUL, true)) tolak_akses();
        $izin = MODUL_IZIN[$modul] ?? null;
        if ($izin !== null && !can($izin)) tolak_akses();
    }
}

/** Nama modul yang sedang dijalankan (via router atau akses langsung ke /panel/xxx.php), null bila bukan halaman panel. */
function modul_saat_ini(): ?string
{
    if (defined('MODUL_FILE')) return basename((string) MODUL_FILE, '.php');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($script, '/panel/') !== false) return basename($script, '.php');
    return null;
}

/** Hentikan request dengan 403 (format menyesuaikan: popup/modal atau halaman penuh). */
function tolak_akses(): void
{
    http_response_code(403);
    if (!empty($_REQUEST['modal'])) {
        echo '<div class="alert alert-danger mb-0">Anda tidak memiliki izin untuk aksi ini.</div>';
        exit;
    }
    kirim_halaman_error('403.html');
    exit;
}

function current_admin(): ?array
{
    if (!is_logged_in()) return null;
    static $admin = null;
    if ($admin === null) {
        $stmt = getDB()->prepare('SELECT id, nama, username, role FROM users WHERE id = :id AND status = \'aktif\' LIMIT 1');
        $stmt->execute([':id' => $_SESSION['admin_id']]);
        $admin = $stmt->fetch() ?: null;
    }
    return $admin;
}

// ---------------------------------------------------------------------
// HAK AKSES (role & permission)
// ---------------------------------------------------------------------

/** Menyiapkan tabel role_permissions & kolom role (sekali per sesi). */
function siapkan_hak_akses(): void
{
    if (!empty($_SESSION['hak_akses_siap'])) return;
    $db = getDB();
    try {
        $kol = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        if ($kol && stripos((string) $kol['Type'], 'enum(') === 0 && stripos((string) $kol['Type'], 'operator') === false) {
            $db->exec("ALTER TABLE users MODIFY role VARCHAR(30) NOT NULL DEFAULT 'operator'");
        }
        $ada = (bool) $db->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn();
        if (!$ada) {
            $db->exec("CREATE TABLE role_permissions (
                role VARCHAR(30) NOT NULL,
                izin VARCHAR(60) NOT NULL,
                PRIMARY KEY (role, izin)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ins = $db->prepare('INSERT INTO role_permissions (role, izin) VALUES (:r, :i)');
            foreach (IZIN_DEFAULT_OPERATOR as $i) $ins->execute([':r' => 'operator', ':i' => $i]);
        }
        // Migrasi: izin QR lama (qr.lihat/cetak/generate) dipecah menjadi QR Siswa, QR Gerbang, dan QR Kelas
        $peta = [
            'qr.lihat'    => ['qr_siswa.lihat', 'qr_gerbang.lihat', 'qr_kelas.lihat'],
            'qr.cetak'    => ['qr_siswa.cetak', 'qr_kelas.cetak'],
            'qr.generate' => ['qr_siswa.generate', 'qr_kelas.generate'],
        ];
        $lama = $db->query("SELECT role, izin FROM role_permissions WHERE izin IN ('qr.lihat','qr.cetak','qr.generate')")->fetchAll();
        if ($lama) {
            $ins = $db->prepare('INSERT IGNORE INTO role_permissions (role, izin) VALUES (:r, :i)');
            foreach ($lama as $row) {
                foreach ($peta[$row['izin']] as $baru) $ins->execute([':r' => $row['role'], ':i' => $baru]);
            }
            $db->exec("DELETE FROM role_permissions WHERE izin IN ('qr.lihat','qr.cetak','qr.generate')");
        }
        // Migrasi: izin lama "log_keamanan.lihat" diganti nama menjadi "log_security.lihat" (sejajar dengan "log_aktivitas.lihat")
        $db->exec("INSERT IGNORE INTO role_permissions (role, izin) SELECT role, 'log_security.lihat' FROM role_permissions WHERE izin = 'log_keamanan.lihat'");
        $db->exec("DELETE FROM role_permissions WHERE izin = 'log_keamanan.lihat'");
        // Migrasi sekali: izin baru "papan.lihat" untuk role yang sudah boleh melihat presensi
        $sudahPapan = (bool) $db->query("SELECT 1 FROM role_permissions WHERE role = '_meta' AND izin = 'migrasi_papan' LIMIT 1")->fetchColumn();
        if (!$sudahPapan) {
            $db->exec("INSERT IGNORE INTO role_permissions (role, izin) SELECT role, 'papan.lihat' FROM role_permissions WHERE izin = 'presensi.lihat'");
            $db->exec("INSERT IGNORE INTO role_permissions (role, izin) VALUES ('_meta', 'migrasi_papan')");
        }
        // Migrasi sekali: izin baru "pindah_kelas.lihat/proses" untuk role yang sudah boleh mengubah data siswa
        $sudahPindah = (bool) $db->query("SELECT 1 FROM role_permissions WHERE role = '_meta' AND izin = 'migrasi_pindah_kelas' LIMIT 1")->fetchColumn();
        if (!$sudahPindah) {
            $db->exec("INSERT IGNORE INTO role_permissions (role, izin) SELECT role, 'pindah_kelas.lihat' FROM role_permissions WHERE izin = 'siswa.ubah'");
            $db->exec("INSERT IGNORE INTO role_permissions (role, izin) SELECT role, 'pindah_kelas.proses' FROM role_permissions WHERE izin = 'siswa.ubah'");
            $db->exec("INSERT IGNORE INTO role_permissions (role, izin) VALUES ('_meta', 'migrasi_pindah_kelas')");
        }
        $_SESSION['hak_akses_siap'] = true;
    } catch (Throwable $e) {
        error_log('siapkan_hak_akses: ' . $e->getMessage());
    }
}

/** Himpunan izin milik pengguna yang sedang login. ['*' => true] untuk admin. */
function izin_pengguna(): array
{
    static $set = null;
    if ($set !== null) return $set;
    $set = [];
    $admin = current_admin();
    if (!$admin) return $set;
    if (($admin['role'] ?? '') === 'admin') return $set = ['*' => true];

    siapkan_hak_akses();
    try {
        $st = getDB()->prepare('SELECT izin FROM role_permissions WHERE role = :r');
        $st->execute([':r' => $admin['role']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i) $set[$i] = true;
    } catch (Throwable $e) {
        foreach (IZIN_DEFAULT_OPERATOR as $i) $set[$i] = true;
    }
    return $set;
}

function can(string $izin): bool
{
    $set = izin_pengguna();
    if (isset($set['*'])) return true;
    if ($izin === 'pengguna.kelola') return false; // khusus administrator
    return isset($set[$izin]);
}

function can_modul(string $modul): bool
{
    $izin = MODUL_IZIN[$modul] ?? null;
    return $izin === null || can($izin);
}

function can_any_modul(array $moduls): bool
{
    foreach ($moduls as $m) if (can_modul($m)) return true;
    return false;
}
