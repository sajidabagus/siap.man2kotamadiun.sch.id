<?php
/**
 * API publik: apakah perangkat/browser ini sudah terdaftar sebagai perangkat siswa?
 * Body: {"device_uuid": "..."}. Hanya mengembalikan status + nama yang disamarkan.
 */
require_once __DIR__ . '/../includes/presensi_core.php';
require_once __DIR__ . '/../includes/ratelimit.php';

// RATE LIMIT (C6): 60 req/menit/IP.
rate_limit_gate('cek_perangkat', 60, 60);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    // D7 (pelengkap pasca-audit): guard is_array/is_string — body JSON bisa
    // bertipe array/angka, dulu TypeError lalu HTTP 500.
    $input = json_decode(file_get_contents('php://input'), true);
    $input = is_array($input) ? $input : [];
    $uuid = is_string($input['device_uuid'] ?? null) ? clean($input['device_uuid']) : '';
    $secret = is_string($input['device_secret'] ?? null) ? clean($input['device_secret']) : '';  // C1
    $settings = get_settings();

    if (empty($settings['device_binding_aktif'])) {
        json_response(['success' => true, 'wajib' => false, 'terdaftar' => true, 'status' => 'TIDAK_WAJIB']);
    }
    if (!is_valid_install_uuid($uuid)) {
        json_response(['success' => false, 'message' => 'Identitas perangkat tidak valid.']);
    }

    $b = get_device_binding_by_hash(array_values(device_hash_variants($uuid, $secret !== '' ? $secret : null)));
    if (!$b) {
        json_response(['success' => true, 'wajib' => true, 'terdaftar' => false, 'status' => 'BELUM']);
    }


    json_response([
        'success' => true, 'wajib' => true,
        'terdaftar' => $b['status'] === 'ACTIVE',
        'status' => $b['status'] === 'ACTIVE' ? 'AKTIF' : 'DIBLOKIR',
    ]);
} catch (Throwable $e) {
    error_log('api/cek_perangkat: ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'Terjadi kesalahan server.'], 500);
}