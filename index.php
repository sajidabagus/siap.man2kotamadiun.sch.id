<?php

/**
 * ROUTER UTAMA (front controller) — URL bersih:
 *   /dashboard   /siswa   /siswa?q=sajida&kelas=X%20B   /siswa/2026001   /siswa/2026001/edit
 *   /kelas   /pengaturan   /absensi   ... (peta lengkap: RUTE_MODUL di includes/functions.php)
 *
 * Bentuk lama index.php?modul=nama tetap dilayani; permintaan GET-nya dialihkan (301)
 * ke URL bersih supaya bookmark lama tidak rusak.
 */
define('APP_ROUTER', true);

// Tahan seluruh output sampai halaman selesai, supaya header()/redirect di
// halaman admin tetap bisa dipanggil walau header.php sudah menulis HTML.
ob_start();

// Portal siswa (/portal/...) memakai sesi & halaman TERPISAH dari admin, jadi dialihkan
// SEBELUM auth.php dimuat (auth.php memulai sesi admin).
require_once __DIR__ . '/config/database.php';
$pathPortal = path_permintaan();
if ($pathPortal === 'portal' || strpos($pathPortal, 'portal/') === 0) {
    require __DIR__ . '/portal_siswa/router.php';
    if (ob_get_level() > 0) ob_end_flush();
    exit;
}

require_once __DIR__ . '/includes/auth.php';

/** Path permintaan relatif terhadap BASE_URL, tanpa slash di tepi, belum di-decode. */
function path_permintaan(): string
{
    $uri  = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    $base = rtrim((string) (parse_url(BASE_URL, PHP_URL_PATH) ?? ''), '/');
    if ($base !== '' && strpos($uri, $base) === 0) $uri = substr($uri, strlen($base));
    $path = trim($uri, '/');
    // Jaring pengaman: tautan salah bentuk "/siswa&id=5" (tanda & bukan ?) tetap terbaca.
    $pos = strpos($path, '&');
    if ($pos !== false) {
        parse_str(substr($path, $pos + 1), $tambahan);
        $_GET = $_GET + $tambahan;
        $path = substr($path, 0, $pos);
    }
    return in_array($path, ['index.php', 'index'], true) ? '' : $path;
}

function tampil_404(): void
{
    http_response_code(404);
    if (!empty($_REQUEST['modal'])) {
        echo '<div class="alert alert-danger mb-0">Halaman tidak ditemukan.</div>';
        exit;
    }
    kirim_halaman_error('404.html');
    exit;
}

// ---------------------------------------------------------------------
// 1. Tentukan modul dari URL
// ---------------------------------------------------------------------
$modulLama = (string) ($_GET['modul'] ?? '');
$modul = '';
$nisUrl = null;

if ($modulLama !== '') {
    $modul = $modulLama;                         // bentuk lama
} else {
    $path = path_permintaan();
    if ($path !== '') {
        $cocok = cocokkan_rute($path);
        if (!$cocok) tampil_404();                   // alamat yang tidak ada -> halaman 404, login atau belum
        if ($cocok) {
            [$modul, $rp] = $cocok;
            define('ROUTE_BERSIH', true);
            $nisUrl = $rp['nis'] ?? null;
            if (isset($rp['id'])) {
                $_GET['id'] = $rp['id'];
                $_REQUEST['id'] = $rp['id'];
            }
        }
    }
}

// Modul yang tidak dikenal (mis. ?modul=ngawur) juga 404 untuk pengunjung yang belum login. Hanya halaman yang
// BENAR-BENAR ada yang boleh dialihkan ke login; selain itu tidak membocorkan apa pun.
if ($modul !== '' && (!in_array($modul, DAFTAR_MODUL, true) || !is_file(__DIR__ . '/panel/' . $modul . '.php'))) {
    tampil_404();
}

if (!is_logged_in()) {
    if ($modul === '') {
        // Halaman depan publik: ringkasan kehadiran, histori realtime, scan absen & pendaftaran HP
        require __DIR__ . '/beranda.php';
        if (ob_get_level() > 0) ob_end_flush();
        exit;
    }
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if ($modul === '') {
    // Arahkan ke halaman pertama yang boleh diakses pengguna ini
    foreach (['dashboard', 'siswa', 'presensi', 'rekap', 'qr_siswa', 'profil'] as $m) {
        if (can_modul($m)) {
            header('Location: ' . modul_url($m));
            exit;
        }
    }
    header('Location: ' . modul_url('profil'));
    exit;
}

if (!in_array($modul, DAFTAR_MODUL, true) || !is_file(__DIR__ . '/panel/' . $modul . '.php')) {
    tampil_404();
}

// ---------------------------------------------------------------------
// 2. Bentuk lama (GET) -> alihkan permanen ke URL bersih
// ---------------------------------------------------------------------
if ($modulLama !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $sisa = $_GET;
    unset($sisa['modul']);
    $tujuan = modul_url($modul, $sisa);
    // Bila URL bersih tidak bisa dibentuk (mis. id siswa tak ada -> NIS tak diketahui),
    // jangan dialihkan: akan berputar ke dirinya sendiri. Biarkan modulnya yang menjawab.
    if (strpos($tujuan, '/index.php?') === false) {
        header('Location: ' . $tujuan, true, 301);
        exit;
    }
}

// ---------------------------------------------------------------------
// 3. NIS di URL -> id siswa (modul panel tetap membaca $_GET['id'])
// ---------------------------------------------------------------------
if ($nisUrl !== null) {
    $idSiswa = id_dari_nis($nisUrl);
    if ($idSiswa === null) tampil_404();
    $_GET['id'] = $idSiswa;
    $_REQUEST['id'] = $idSiswa;
}

// ---------------------------------------------------------------------
// 4. Cek izin (role & hak akses)
// ---------------------------------------------------------------------
$izinDiperlukan = MODUL_IZIN[$modul] ?? null;
if ($izinDiperlukan !== null && !can($izinDiperlukan)) {
    http_response_code(403);
    if (!empty($_REQUEST['modal'])) {
        echo '<div class="alert alert-danger mb-0">Anda tidak memiliki izin untuk aksi ini.</div>';
        exit;
    }
    kirim_halaman_error('403.html');
    exit;
}

define('MODUL_FILE', $modul . '.php');
require __DIR__ . '/panel/' . $modul . '.php';

if (ob_get_level() > 0) ob_end_flush();
