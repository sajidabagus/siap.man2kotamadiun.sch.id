<?php
/**
 * /izin/{id}/lampiran — lampiran pengajuan (surat dokter = DATA KESEHATAN).
 * Izin 'izin.lihat' sudah diperiksa router. Setiap pembukaan dicatat di Log Keamanan sebagai jejak audit.
 * Sengaja TIDAK memuat header.php: keluarannya berkas, bukan halaman.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/izin.php';

$row = izin_siapkan_tabel() ? izin_ambil((int) ($_GET['id'] ?? 0)) : null;
if (!$row || !$row['lampiran']) { http_response_code(404); echo 'Lampiran tidak ditemukan.'; exit; }

$admin = current_admin();
security_log((int) $row['siswa_id'], 'IZIN_LAMPIRAN_DILIHAT', null, 'Pengajuan #' . (int) $row['id'] . ' dibuka oleh ' . izin_nama_admin($admin ?: []));
izin_stream_lampiran($row);
