<?php
/** Fixture tampilan saja: tidak membuka DB/sesi produksi atau memuat config/database.php. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('APP_ROUTER', true);
define('BASE_URL', 'http://siap.test');
function get_settings(): array { return ['nama_sekolah' => 'Sekolah Pengujian', 'logo' => '']; }
function is_hari_efektif(): bool { return true; }
function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function favicon_tag(): string { return ''; }
function portal_url(string $path = '', array $params = []): string { return BASE_URL . '/portal' . ($path ? '/' . $path : '') . ($params ? '?' . http_build_query($params) : ''); }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="test-only">'; }
function portal_notif_belum_dibaca(int $sid): int { return 2; }
function portal_scan_status(int $sid): array { return ['aktif' => true, 'alasan' => '']; }
function portal_sesi_via_perangkat(): bool { return false; }
function portal_siswa_sekarang(): ?array { return null; }
$root = dirname(__DIR__, 2);
if (($argv[1] ?? 'home') === 'home') {
    require $root . '/beranda.php';
} else {
    require $root . '/portal_siswa/_layout.php';
    $page = $argv[1];
    portal_app_awal(ucfirst($page), ['siswa_id' => 1, 'nama' => 'Siswa Pengujian'], $page);
    echo '<section class="pt-card"><h2>Konten pengujian ' . e($page) . '</h2><p>Fixture kerangka; bukan data sekolah.</p></section>';
    portal_app_akhir();
}
