<?php
// /portal/masuk-perangkat — POST JSON {"uuid": "..."} -> membuat sesi portal bila UUID milik perangkat terdaftar.
// Tanpa CSRF token karena penyerang tidak mungkin mengisi UUID milik korban (UUID hanya ada di penyimpanan browser korban);
// pemeriksaan Origin menutup sisanya. Respons tidak membedakan alasan gagal.
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $hostOrigin = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $hostSaya = strtolower((string) parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    if ($hostOrigin === '' || $hostOrigin !== $hostSaya) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }
}

$data = json_decode((string) file_get_contents('php://input'), true);
$uuid = is_array($data) ? (string) ($data['uuid'] ?? '') : (string) ($_POST['uuid'] ?? '');

$h = portal_masuk_perangkat($uuid);
if ($h['ok']) {
    require_once __DIR__ . '/../includes/device_binding.php';
    device_cookie_pertahankan($uuid);        // iPhone: perpanjang cadangan identitas dari server
}
// 'perlu' hanya terisi bila UUID memang milik perangkat terdaftar yang AKTIF (pemiliknya sendiri), jadi tidak membocorkan
// apa pun kepada orang lain: 'aktivasi' = siswa belum punya akun portal, 'login' = wajib masuk dengan password dulu.
echo json_encode(['ok' => (bool) $h['ok'], 'perlu' => (string) ($h['perlu'] ?? '')]);
