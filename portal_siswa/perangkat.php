<?php
/**
 * /portal/perangkat (hanya POST)
 *   aksi=daftar : daftarkan perangkat ini untuk presensi (siswa sudah login = identitas terbukti).
 *                 Hanya dari dalam aplikasi SIAP. Bila petugas mewajibkan kode, perlu Kode Perangkat dari menu Akun Siswa.
 *   aksi=cek    : apakah penyimpanan perangkat ini = perangkat yang terdaftar? (JSON; untuk menunjukkan
 *                 jebakan "terdaftar di Chrome tetapi presensi di aplikasi").
 */
$akun = portal_wajib_login();
$sid = (int) $akun['siswa_id'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Location: ' . portal_url()); exit; }

$aksi = (string) ($_POST['aksi'] ?? '');
$uuid = (string) ($_POST['device_uuid'] ?? '');

if ($aksi === 'cek') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['ok' => false]); exit; }
    $b = get_device_binding($sid);
    $cocok = ($b && is_valid_install_uuid($uuid)) ? hash_equals((string) $b['device_hash'], build_device_hash($uuid)) : false;
    if ($cocok) device_cookie_pertahankan($uuid);   // iPhone: perpanjang cadangan identitas dari server
    echo json_encode(['ok' => true, 'terdaftar' => (bool) $b, 'cocok' => $cocok]);
    exit;
}

$pesan = ''; $berhasil = false;
if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    $pesan = 'Sesi halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.';
} elseif ($aksi !== 'daftar') {
    $pesan = 'Permintaan tidak dikenal.';
} elseif (!request_boleh_daftar_perangkat()) {
    $pesan = PESAN_PERANGKAT[request_dari_perangkat_apple() ? 'bukan_browser_ios' : 'bukan_aplikasi'];
} elseif (trim((string) ($_POST['kode'] ?? '')) !== '') {
    // Kode perangkat dari petugas (Akun Siswa): mendaftarkan perangkat TANPA mengubah akun.
    $h = portal_daftar_perangkat_dengan_kode((int) $akun['id'], (string) $_POST['kode'], $uuid);
    $pesan = $h['pesan'];
    $berhasil = $h['ok'];
} elseif (!perangkat_boleh_daftar_mandiri($sid)) {
    $pesan = 'Pendaftaran perangkat memerlukan kode perangkat dari petugas sekolah. Minta kodenya, lalu isi pada kartu Perangkat presensi.';
} else {
    $h = daftarkan_perangkat_terverifikasi($sid, $uuid, 'login portal siswa');
    $pesan = PESAN_PERANGKAT[$h['status']] ?: 'Pendaftaran perangkat tidak diperlukan.';
    $berhasil = in_array($h['status'], ['terdaftar', 'sudah_sama'], true);
}
$_SESSION[$berhasil ? 'portal_info' : 'portal_galat'] = $pesan;
header('Location: ' . portal_url('profil') . '#kartuPerangkat');
exit;
