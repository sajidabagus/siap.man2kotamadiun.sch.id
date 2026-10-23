<?php
/**
 * Router portal siswa — dipanggil dari index.php untuk semua URL /portal/...
 *   /portal            beranda siswa (wajib masuk)
 *   /portal/masuk      (lama) dialihkan ke halaman login tunggal /login.php
 *   /portal/aktivasi   aktivasi akun dengan kode dari petugas
 *   /portal/izin            daftar pengajuan izin/sakit   ·  /portal/izin/baru  ajukan
 *   /portal/izin/{id}       detail & batalkan             ·  /portal/izin/{id}/lampiran
 *   /portal/notifikasi      notifikasi status pengajuan
 *   /portal/password   ganti password
 *   /portal/keluar     keluar (POST)
 *   /portal/riwayat    riwayat absensi per bulan  ·  /portal/profil  profil, perangkat, akun
 *   /portal/gerbang    (tanpa sesi) mencoba masuk otomatis lewat perangkat terdaftar  ·  /portal/masuk-perangkat  (POST)
 *   /portal/verifikasi minta password sebelum aksi sensitif pada sesi masuk-otomatis
 * Sesi portal TERPISAH dari sesi admin (lihat includes/portal.php).
 */
require_once __DIR__ . '/../includes/izin.php';
require_once __DIR__ . '/_layout.php';

$sub = trim(substr((string) ($pathPortal ?? 'portal'), strlen('portal')), '/');

$GLOBALS['PORTAL_SUB'] = $sub;   // dipakai portal_wajib_login() (penegakan ganti password sementara)

// Halaman masuk hanya SATU (/login.php untuk petugas dan siswa); alamat lama tetap hidup.
if ($sub === 'masuk') { header('Location: ' . portal_login_url(), true, 301); exit; }

portal_mulai_sesi();
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

if (!portal_siapkan_tabel() || !izin_siapkan_tabel()) {
    portal_halaman_pesan(503, 'Portal belum siap', 'Portal siswa belum dapat dipakai saat ini. Silakan hubungi petugas sekolah.');
}
if (!portal_aktif()) {
    portal_halaman_pesan(503, 'Portal sedang ditutup', 'Portal siswa sedang tidak dibuka. Silakan coba lagi nanti atau hubungi petugas sekolah.');
}

$halaman = ['' => 'beranda', 'aktivasi' => 'aktivasi', 'password' => 'password', 'keluar' => 'keluar',
            'izin' => 'izin', 'izin/baru' => 'izin_baru', 'notifikasi' => 'notifikasi', 'perangkat' => 'perangkat',
            'riwayat' => 'riwayat', 'profil' => 'profil', 'gerbang' => 'gerbang', 'masuk-perangkat' => 'masuk_perangkat',
            'verifikasi' => 'verifikasi'];
$izinId = 0;
if (preg_match('#^izin/(\d{1,10})(?:/(lampiran))?$#', $sub, $m)) {
    $izinId = (int) $m[1];
    $halaman[$sub] = (($m[2] ?? '') === 'lampiran') ? 'izin_lampiran' : 'izin_detail';
}

if (!isset($halaman[$sub])) {
    portal_halaman_pesan(404, 'Halaman tidak ditemukan', 'Alamat yang Anda buka tidak ada di portal siswa.');
}
require __DIR__ . '/' . $halaman[$sub] . '.php';
