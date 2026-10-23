<?php
/**
 * API: Daftarkan perangkat dengan salah satu dari dua cara:
 *   - {"token": "...",  "device_uuid": "..."}  -> QR Pasangan (siswa dikenali dari token)
 *   - {"kode":  "123456", "device_uuid": "..."} -> Kode 6 angka (siswa dari SESSION hasil scan QR gerbang)
 * siswa_id TIDAK pernah diambil dari body request.
 */

require_once __DIR__ . '/../includes/presensi_core.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

try {
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$deviceUuid = clean($input['device_uuid'] ?? '');

// Batasi percobaan per sesi: maksimal 8 kali dalam 10 menit
$sekarang = time();
$_SESSION['pair_try'] = array_values(array_filter($_SESSION['pair_try'] ?? [], fn($t) => $t > $sekarang - 600));
if (count($_SESSION['pair_try']) >= 8) {
    json_response(['success' => false, 'code' => 'TOO_MANY', 'message' => 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.']);
}
$_SESSION['pair_try'][] = $sekarang;

// ---- Jalur QR Pasangan ----
if (!empty($input['token'])) {
    $token = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $input['token']));
    json_response(daftarkan_dengan_token($token, $deviceUuid));
}

// ---- Jalur NIS + Kode 6 angka (tombol "Daftarkan Perangkat" di halaman utama presensi) ----
$nisInput = preg_replace('/\s+/', '', clean($input['nis'] ?? ''));
if ($nisInput !== '' && !empty($input['kode'])) {
    $st = getDB()->prepare("SELECT id, nama, kelas FROM siswa WHERE (nis = :n1 OR nisn = :n2) AND status = 'aktif' LIMIT 1");
    $st->execute([':n1' => $nisInput, ':n2' => $nisInput]);
    $s = $st->fetch();
    if (!$s) {
        // Pesan sengaja umum agar NIS yang valid tidak bisa ditebak
        security_log(null, 'DEVICE_PAIRING_FAILED', null, 'Pendaftaran perangkat dengan NIS tidak dikenal.');
        json_response(['success' => false, 'code' => 'KODE_SALAH', 'message' => 'NIS atau kode tidak valid.']);
    }
    $hasil = daftarkan_dengan_kode((int) $s['id'], preg_replace('/\D/', '', (string) $input['kode']), $deviceUuid);
    if (!empty($hasil['success'])) $hasil['data'] = ['nama' => $s['nama'], 'kelas' => $s['kelas']];
    json_response($hasil);
}

// ---- Jalur Kode 6 angka (siswa dari SESSION hasil scan QR gerbang) ----
$siswaId = (int) ($_SESSION['identified_siswa_id'] ?? 0);
if (!$siswaId) {
    json_response(['success' => false, 'code' => 'NO_SESSION', 'message' => 'Sesi identifikasi tidak ditemukan. Silakan scan ulang.']);
}
$gateToken = (string) ($_SESSION['gate_token'] ?? '');
if ($gateToken === '' || !validate_qr_dinamis_sesi($gateToken)) {
    json_response(['success' => false, 'code' => 'GATE_INVALID', 'message' => 'Sesi QR sudah kedaluwarsa. Silakan scan ulang QR di sekolah.']);
}
$kode = preg_replace('/\D/', '', (string) ($input['kode'] ?? ''));
json_response(daftarkan_dengan_kode($siswaId, $kode, $deviceUuid));
} catch (Throwable $e) {
    error_log('api/pasangan: ' . $e->getMessage());
    json_response(['success' => false, 'code' => 'SERVER_ERROR', 'message' => 'Terjadi kesalahan di server. Coba lagi atau hubungi admin.'], 500);
}