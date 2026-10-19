<?php
/**
 * API: Identifikasi siswa (READ-ONLY).
 * mode = "device"  -> identifikasi otomatis dari device_hash (dipakai presensi/index.php)
 * mode = "preview" -> siswa sudah diketahui dari SESSION (via scan token), cek status device saja
 *                      tanpa mendaftarkan apa pun (dipakai presensi/konfirmasi.php)
 *
 * PENTING: mode "preview" TIDAK menerima siswa_id dari body request — selalu
 * dari SESSION yang sudah divalidasi oleh presensi/scan.php, agar klien tidak
 * bisa mengirim siswa_id sembarangan.
 */

require_once __DIR__ . '/../includes/presensi_core.php';
require_once __DIR__ . '/../includes/ratelimit.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

// D7: input JSON bisa bertipe apa saja (array/angka) walau fields diduga
// string — guard is_array/is_string agar tidak TypeError -> HTTP 500.
$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$mode = is_string($input['mode'] ?? null) ? $input['mode'] : '';
$deviceUuid = is_string($input['device_uuid'] ?? null) ? clean($input['device_uuid']) : '';

// D4: dua lapis — 20/menit/device (ketat) + 600/menit/IP (longgar); jaring IP
// lama 30/menit membuat satu IP NAT sekolah kehabisan kuota saat jam puncak.
rate_limit_identitas_gate('scan', $deviceUuid, 20, 600, 60);

$deviceSecret = is_string($input['device_secret'] ?? null) ? clean($input['device_secret']) : '';  // C1: HMAC secret v3
$gateToken = is_string($input['gate_token'] ?? null) ? clean($input['gate_token']) : ($_SESSION['gate_token'] ?? '');

if ($gateToken === '' || !validate_qr_dinamis($gateToken)) {
    json_response(['success' => false, 'code' => 'GATE_INVALID', 'message' => 'Sesi QR gerbang tidak valid/kedaluwarsa.']);
}

if ($mode === 'device') {
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

    // Simpan identifikasi di session agar konfirmasi.php bisa dipercaya.
    $_SESSION['identified_siswa_id'] = $siswa['id'];
    $_SESSION['identified_via'] = 'device';

    json_response(['success' => true, 'code' => 'MATCH', 'message' => 'Perangkat dikenali.', 'data' => [
        'nama' => $siswa['nama'], 'nis' => $siswa['nis'], 'kelas' => $siswa['kelas'],
    ]]);
}

if ($mode === 'preview') {
    $siswaId = (int) ($_SESSION['identified_siswa_id'] ?? 0);
    if (!$siswaId) {
        json_response(['success' => false, 'code' => 'NO_SESSION', 'message' => 'Sesi identifikasi tidak ditemukan.']);
    }
    if (!is_valid_install_uuid($deviceUuid)) {
        json_response(['success' => false, 'code' => 'DEVICE_INVALID', 'message' => 'Identitas perangkat tidak valid.']);
    }

    $stmt = getDB()->prepare('SELECT * FROM siswa WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $siswaId]);
    $siswa = $stmt->fetch();
    if (!$siswa) json_response(['success' => false, 'code' => 'NOT_FOUND', 'message' => 'Siswa tidak ditemukan.']);

    $sudah = sudah_presensi_hari_ini($siswaId);
    if ($sudah) {
        json_response(['success' => true, 'code' => 'ALREADY_PRESENT', 'data' => [
            'nama' => $siswa['nama'], 'jam' => $sudah['jam_masuk'], 'status' => $sudah['status'],
        ]]);
    }

    $binding = get_device_binding($siswaId);
    // D1: cocokkan SEMUA varian hash (v3 + v2) ke KEDUA kolom binding —
    // samakan dengan check_or_register_device() supaya preview tidak pernah
    // menampilkan MISMATCH untuk klien ber-secret yang sah (regresi C1).
    $varian = device_hash_variants($deviceUuid, $deviceSecret !== '' ? $deviceSecret : null);
    $haV3 = $varian['v3'] ?? null;
    $settings = get_settings();

    if (empty($settings['device_binding_aktif'])) {
        $deviceState = 'NEW';
    } elseif (!$binding) {
        // Siswa ini belum pernah daftar device -> cek dulu apakah device ini
        // SUDAH dipakai siswa lain, sebelum menganggapnya "device baru".
        $sudahDipakaiSiswaLain = get_device_binding_by_hash(array_values($varian));
        if ($sudahDipakaiSiswaLain && (int) $sudahDipakaiSiswaLain['siswa_id'] !== $siswaId) {
            $deviceState = 'CLAIMED_BY_OTHER';
        } else {
            // Mode "Wajib Kode Pasangan" (default aktif): HP baru harus didaftarkan dengan kode dari admin
            $deviceState = device_wajib_kode_aktif($settings) ? 'NEED_PAIRING' : 'NEW';
        }
    } elseif ($binding['status'] === 'BLOCKED') {
        $deviceState = 'BLOCKED';
    } elseif (!empty($binding['device_hash_v3'])) {
        // Baris sudah skema v3: wajib hash v3 cocok; UUID tanpa secret
        // (atau secret salah) TETAP MISMATCH — konsisten dengan keputusan
        // check_or_register_device().
        $deviceState = ($haV3 !== null && hash_equals((string) $binding['device_hash_v3'], $haV3))
            ? 'MATCH' : 'MISMATCH';
    } elseif (binding_cocok($binding, $varian)) {
        // Baris legacy (v2): cocok varian apa pun. Bila klien ber-secret,
        // binding akan di-upgrade di proses final -> preview MATCH.
        $deviceState = 'MATCH';
    } else {
        $deviceState = 'MISMATCH';
    }

    json_response(['success' => true, 'code' => 'OK', 'device_state' => $deviceState,
        'binding_aktif' => !empty($settings['device_binding_aktif']), 'data' => [
        'nama' => $siswa['nama'], 'nis' => $siswa['nis'], 'kelas' => $siswa['kelas'],
        'foto' => $siswa['foto'],
    ]]);
}

json_response(['success' => false, 'code' => 'BAD_REQUEST', 'message' => 'Mode tidak dikenali.'], 400);