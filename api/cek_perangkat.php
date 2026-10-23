<?php
/**
 * API publik: apakah perangkat/browser ini sudah terdaftar sebagai perangkat siswa?
 * Body: {"device_uuid": "..."}. Hanya mengembalikan status + nama yang disamarkan.
 */
require_once __DIR__ . '/../includes/presensi_core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $uuid = clean($input['device_uuid'] ?? '');
    $settings = get_settings();

    if (empty($settings['device_binding_aktif'])) {
        json_response(['success' => true, 'wajib' => false, 'terdaftar' => true, 'status' => 'TIDAK_WAJIB']);
    }
    if (!is_valid_install_uuid($uuid)) {
        json_response(['success' => false, 'message' => 'Identitas perangkat tidak valid.']);
    }

    $b = get_device_binding_by_hash(build_device_hash($uuid));
    if (!$b) {
        json_response(['success' => true, 'wajib' => true, 'terdaftar' => false, 'status' => 'BELUM']);
    }

    if ($b['status'] === 'ACTIVE') device_cookie_pertahankan($uuid);   // iPhone: perpanjang cadangan identitas dari server

    json_response([
        'success' => true, 'wajib' => true,
        'terdaftar' => $b['status'] === 'ACTIVE',
        'status' => $b['status'] === 'ACTIVE' ? 'AKTIF' : 'DIBLOKIR',
    ]);
} catch (Throwable $e) {
    error_log('api/cek_perangkat: ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'Terjadi kesalahan server.'], 500);
}