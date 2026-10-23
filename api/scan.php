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

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$mode = $input['mode'] ?? '';
$deviceUuid = clean($input['device_uuid'] ?? '');
$gateToken = clean($input['gate_token'] ?? ($_SESSION['gate_token'] ?? ''));

if ($gateToken === '' || !validate_qr_dinamis_sesi($gateToken)) {
    json_response(['success' => false, 'code' => 'GATE_INVALID', 'message' => 'Sesi QR gerbang tidak valid/kedaluwarsa.']);
}

if ($mode === 'device') {
    if (!is_valid_install_uuid($deviceUuid)) {
        json_response(['success' => false, 'code' => 'DEVICE_INVALID', 'message' => 'Identitas perangkat tidak valid.']);
    }
    $siswa = identify_siswa_by_device($deviceUuid);

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
    $deviceHash = build_device_hash($deviceUuid);
    $settings = get_settings();

    if (empty($settings['device_binding_aktif'])) {
        $deviceState = 'NEW';
    } elseif (!$binding) {
        // Siswa ini belum pernah daftar device -> cek dulu apakah device ini
        // SUDAH dipakai siswa lain, sebelum menganggapnya "device baru".
        $sudahDipakaiSiswaLain = get_device_binding_by_hash($deviceHash);
        if ($sudahDipakaiSiswaLain && (int) $sudahDipakaiSiswaLain['siswa_id'] !== $siswaId) {
            $deviceState = 'CLAIMED_BY_OTHER';
        } else {
            // Mode "Wajib kode aktivasi": HP baru hanya bisa didaftarkan lewat aktivasi akun (kode dari Akun Siswa)
            $deviceState = !empty($settings['device_wajib_kode']) ? 'NEED_PAIRING' : 'NEW';
        }
    } elseif ($binding['status'] === 'BLOCKED') {
        $deviceState = 'BLOCKED';
    } elseif (hash_equals($binding['device_hash'], $deviceHash)) {
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