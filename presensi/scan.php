<?php
require_once __DIR__ . '/../includes/presensi_core.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$token = clean($_GET['token'] ?? '');

// Wajib sudah melalui tahap scan QR gerbang lebih dahulu (Model B).
if (empty($_SESSION['gate_token']) || !validate_qr_dinamis_sesi((string) $_SESSION['gate_token'])) {
    unset($_SESSION['gate_token']);
    header('Location: ' . BASE_URL . '/presensi/index.php');
    exit;
}

if ($token === '') {
    header('Location: ' . BASE_URL . '/presensi/index.php');
    exit;
}

$siswa = identify_siswa_by_token($token);

if (!$siswa) {
    $_SESSION['presensi_error'] = 'QR Code tidak valid.';
    header('Location: ' . BASE_URL . '/presensi/berhasil.php?status=error');
    exit;
}

// Simpan identitas di SESSION (server-trusted), bukan mengandalkan input klien saat simpan nanti.
$_SESSION['identified_siswa_id'] = $siswa['id'];
$_SESSION['identified_via'] = 'personal_qr';

header('Location: ' . BASE_URL . '/presensi/konfirmasi.php');
exit;
