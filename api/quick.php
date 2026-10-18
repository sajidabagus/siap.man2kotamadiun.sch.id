<?php
/**
 * API: Presensi kilat — SATU request untuk perangkat yang sudah terdaftar.
 *
 * Menggabungkan identifikasi device + seluruh validasi + simpan presensi
 * (identify_siswa_by_device + proses_simpan_presensi) dalam satu POST,
 * memangkas alur lama:
 *   presensi/index.php -> api/scan.php (device) -> konfirmasi.php
 *   -> api/scan.php (preview) -> api/presensi.php
 * menjadi satu panggilan saja saat jam puncak (06:50-07:00).
 *
 * siswa_id TIDAK PERNAH diambil dari body request — selalu hasil identifikasi
 * device hash di server (pola sama dengan api/scan.php mode=device).
 * Perangkat yang belum dikenali tetap diarahkan ke alur lama lewat kode
 * NOT_FOUND (scan QR pribadi -> konfirmasi.php), jadi tidak ada perubahan
 * keamanan: gerbang/kelas + device binding + (opsional) GPS tetap berlaku.
 */

require_once __DIR__ . '/../includes/presensi_core.php';
require_once __DIR__ . '/../includes/ratelimit.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

// D7: input JSON bisa bertipe apa saja (array/angka) walau fields diduga
// string — guard is_array/is_string agar tidak TypeError -> HTTP 500.
$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$deviceUuid = is_string($input['device_uuid'] ?? null) ? clean($input['device_uuid']) : '';

// RATE LIMIT (C6+D4): berbagi ember yang SAMA dengan api/presensi.php —
// dua lapis: 6/menit/device (ketat) + 300/menit/IP (longgar) supaya siswa di
// belakang satu IP NAT/CGNAT sekolah tidak saling memblokir saat jam puncak.
rate_limit_identitas_gate('presensi', $deviceUuid, 6, 300, 60);

$deviceSecret = is_string($input['device_secret'] ?? null) ? clean($input['device_secret']) : '';
$gateToken = is_string($input['gate_token'] ?? null) ? clean($input['gate_token']) : ($_SESSION['gate_token'] ?? '');
$lat = isset($input['latitude']) && $input['latitude'] !== null ? (float) $input['latitude'] : null;
$lng = isset($input['longitude']) && $input['longitude'] !== null ? (float) $input['longitude'] : null;

// 1. Gerbang/kelas harus valid sejak awal (murah, sebelum kueri lain).
if ($gateToken === '' || !validate_qr_dinamis($gateToken)) {
    json_response(['success' => false, 'code' => 'GATE_INVALID', 'message' => 'Sesi QR gerbang tidak valid/kedaluwarsa. Silakan scan ulang.']);
}

// 2. Identifikasi perangkat (paritas penuh dengan api/scan.php mode=device).
if (!is_valid_install_uuid($deviceUuid)) {
    json_response(['success' => false, 'code' => 'DEVICE_INVALID', 'message' => 'Identitas perangkat tidak valid.']);
}
$siswa = identify_siswa_by_device($deviceUuid, $deviceSecret !== '' ? $deviceSecret : null);

if (!$siswa) {
    json_response(['success' => true, 'code' => 'NOT_FOUND', 'message' => 'Perangkat belum dikenali.']);
}
if ($siswa['status'] !== 'aktif') {
    json_response(['success' => true, 'code' => 'INACTIVE', 'message' => 'Status siswa tidak aktif.']);
}
if ($siswa['device_status'] === 'BLOCKED') {
    json_response(['success' => true, 'code' => 'BLOCKED', 'message' => 'Perangkat diblokir.']);
}

// Penanda sesi (dipakai halaman "sudah presensi"; pola sama dgn api/scan.php).
$_SESSION['identified_siswa_id'] = (int) $siswa['id'];
$_SESSION['identified_via'] = 'device';

// 3. Seluruh validasi + simpan (gerbang ulang, kelas, device binding, ganda,
//    hari efektif, jam buka, GPS) memakai inti yang SAMA dengan api/presensi.php.
$result = proses_simpan_presensi((int) $siswa['id'], $gateToken, $deviceUuid, $lat, $lng, $deviceSecret !== '' ? $deviceSecret : null);

// 4. Bersihkan sesi (pola api/presensi.php). Khusus ALREADY_PRESENT, sisakan
//    identified_siswa_id supaya berhasil.php?status=already bisa menampilkan
//    data presensi yang sudah tercatat.
if ($result['success']) {
    $_SESSION['presensi_result'] = $result['data'] + ['message' => $result['message']];
    unset($_SESSION['identified_siswa_id'], $_SESSION['identified_via'], $_SESSION['gate_token'], $_SESSION['gate_verified_at']);
} elseif (($result['code'] ?? '') === 'ALREADY_PRESENT') {
    unset($_SESSION['identified_via'], $_SESSION['gate_token'], $_SESSION['gate_verified_at']);
} else {
    unset($_SESSION['identified_siswa_id'], $_SESSION['identified_via'], $_SESSION['gate_token'], $_SESSION['gate_verified_at']);
}

json_response($result);
