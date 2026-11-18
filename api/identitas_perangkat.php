<?php
/**
 * Pilih satu cadangan UUID yang terbukti terikat ACTIVE saat storage berkonflik.
 * UUID adalah kredensial bearer. Tidak mengungkap siswa, tidak mencatat UUID,
 * tidak membuat/reset binding, tidak login dan tidak memasang cookie sebelum
 * klien mengetahui hasil keputusan yang tidak ambigu.
 */
require_once __DIR__ . '/../includes/device_binding.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$expected = (https_aktif() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
if ($origin !== '' && strcasecmp(rtrim($origin, '/'), $expected) !== 0) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}
$raw = file_get_contents('php://input', false, null, 0, 1025);
$input = strlen($raw) <= 1024 ? json_decode($raw, true) : null;
$candidates = is_array($input) ? ($input['candidates'] ?? null) : null;
if (!is_array($candidates) || array_keys($candidates) !== [0, 1]
    || !is_string($candidates[0]) || !is_string($candidates[1])
    || !is_valid_install_uuid($candidates[0]) || !is_valid_install_uuid($candidates[1])
    || $candidates[0] === $candidates[1]) {
    echo json_encode(['ok' => false]);
    exit;
}
try {
    if (empty(get_settings()['device_binding_aktif'])) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $matches = [];
    foreach ($candidates as $index => $uuid) {
        $binding = get_device_binding_by_hash(build_device_hash($uuid));
        if ($binding && ($binding['status'] ?? '') === 'ACTIVE') $matches[] = $index;
    }
    echo json_encode(count($matches) === 1 ? ['ok' => true, 'index' => $matches[0]] : ['ok' => false]);
} catch (Throwable $e) {
    error_log('Identitas perangkat: pemeriksaan gagal.'); // tidak menyertakan body/token/SQL
    http_response_code(503);
    echo json_encode(['ok' => false]);
}
