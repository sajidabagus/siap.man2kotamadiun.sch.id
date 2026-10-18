<?php
/**
 * API: Daftarkan perangkat dengan salah satu dari dua cara:
 *   - {"token": "...",  "device_uuid": "..."}  -> QR Pasangan (siswa dikenali dari token)
 *   - {"kode":  "123456", "device_uuid": "..."} -> Kode 6 angka (siswa dari SESSION hasil scan QR gerbang)
 * siswa_id TIDAK pernah diambil dari body request.
 */

require_once __DIR__ . '/../includes/presensi_core.php';
require_once __DIR__ . '/../includes/ratelimit.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

// D3: batas server-side (dulu hanya $_SESSION['pair_try'] — bisa dilewati
// total dengan tidak mengirim cookie sesi).
rate_limit_gate('pasangan', 10, 60);

try {
// D7: guard tipe — JSON bisa mengirim array/angka utk field yang diduga string;
// tanpa ini clean()/preg_replace() melempar TypeError (500), bukan error ramah.
$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$deviceUuid = is_string($input['device_uuid'] ?? null) ? clean($input['device_uuid']) : '';
$deviceSecret = is_string($input['device_secret'] ?? null) ? clean($input['device_secret']) : '';  // C1: HMAC secret v3 (boleh kosong saat transisi)
$kodeInput = is_string($input['kode'] ?? null) ? preg_replace('/\D/', '', $input['kode']) : '';

// Batasi percobaan per sesi: maksimal 8 kali dalam 10 menit
$sekarang = time();
$_SESSION['pair_try'] = array_values(array_filter($_SESSION['pair_try'] ?? [], fn($t) => $t > $sekarang - 600));
if (count($_SESSION['pair_try']) >= 8) {
    json_response(['success' => false, 'code' => 'TOO_MANY', 'message' => 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.']);
}
$_SESSION['pair_try'][] = $sekarang;

// ---- Jalur QR Pasangan ----
$token = is_string($input['token'] ?? null) ? strtolower(preg_replace('/[^0-9a-fA-F]/', '', $input['token'])) : '';
if ($token !== '') {
    json_response(daftarkan_dengan_token($token, $deviceUuid, $deviceSecret !== '' ? $deviceSecret : null));
}

// ---- Jalur NIS + Kode 6 angka (tombol "Daftarkan Perangkat" di halaman utama presensi) ----
$nisInput = is_string($input['nis'] ?? null) ? preg_replace('/\s+/', '', clean($input['nis'])) : '';
if ($nisInput !== '' && $kodeInput !== '') {
    // D3: jalur NIS bisa dienumerasi dari luar sekolah -> batas lebih ketat.
    rate_limit_gate('pasangan_nis', 5, 300);
    $st = getDB()->prepare("SELECT id, nama, kelas FROM siswa WHERE (nis = :n1 OR nisn = :n2) AND status = 'aktif' LIMIT 1");
    $st->execute([':n1' => $nisInput, ':n2' => $nisInput]);
    $s = $st->fetch();
    if (!$s) {
        // Pesan sengaja umum agar NIS yang valid tidak bisa ditebak
        security_log(null, 'DEVICE_PAIRING_FAILED', null, 'Pendaftaran perangkat dengan NIS tidak dikenal.');
        json_response(['success' => false, 'code' => 'KODE_SALAH', 'message' => 'NIS atau kode tidak valid.']);
    }
    $hasil = daftarkan_dengan_kode((int) $s['id'], $kodeInput, $deviceUuid, $deviceSecret !== '' ? $deviceSecret : null);
    if (!empty($hasil['success'])) $hasil['data'] = ['nama' => $s['nama'], 'kelas' => $s['kelas']];
    json_response($hasil);
}

// ---- Jalur Kode 6 angka (siswa dari SESSION hasil scan QR gerbang) ----
$siswaId = (int) ($_SESSION['identified_siswa_id'] ?? 0);
if (!$siswaId) {
    json_response(['success' => false, 'code' => 'NO_SESSION', 'message' => 'Sesi identifikasi tidak ditemukan. Silakan scan ulang.']);
}
$gateToken = (string) ($_SESSION['gate_token'] ?? '');
if ($gateToken === '' || !validate_qr_dinamis($gateToken)) {
    json_response(['success' => false, 'code' => 'GATE_INVALID', 'message' => 'Sesi QR sudah kedaluwarsa. Silakan scan ulang QR di sekolah.']);
}
$kode = $kodeInput;
json_response(daftarkan_dengan_kode($siswaId, $kode, $deviceUuid, $deviceSecret !== '' ? $deviceSecret : null));
} catch (Throwable $e) {
    error_log('api/pasangan: ' . $e->getMessage());
    json_response(['success' => false, 'code' => 'SERVER_ERROR', 'message' => 'Terjadi kesalahan di server. Coba lagi atau hubungi admin.'], 500);
}