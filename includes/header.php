<?php
require_once __DIR__ . '/auth.php';
require_login();
$admin = current_admin();
$settings = get_settings();
$flash = get_flash();
$current = defined('MODUL_FILE') ? MODUL_FILE : basename($_SERVER['PHP_SELF']);

// Auto-reset Log Keamanan (kalau diaktifkan di Pengaturan) -- aman dipanggil
// di setiap request krn ada throttle internal (maks jalan 1x/hari).
jalankan_pembersihan_log_otomatis();

/**
 * Kelompok halaman per menu, supaya menu tetap ter-highlight aktif
 * walau sedang berada di halaman turunannya (tambah/edit/hapus/dsb),
 * bukan hanya saat persis di halaman utamanya.
 */
$menuGroups = [
    'dashboard.php'     => ['dashboard.php'],
    'siswa.php'          => ['siswa.php', 'siswa_detail.php', 'siswa_tambah.php', 'siswa_edit.php', 'siswa_hapus.php', 'siswa_import.php'],
    'master_kelas.php'   => ['master_kelas.php'],
    'kenaikan_kelas.php' => ['kenaikan_kelas.php'],
    'qr_siswa.php'       => ['qr_siswa.php', 'qr_print.php', 'qr_generate.php'],
    'qr_gerbang.php'     => ['qr_gerbang.php'],
    'qr_kelas.php'       => ['qr_kelas.php'],
    'presensi.php'       => ['presensi.php', 'presensi_export.php'],
    'papan.php'          => ['papan.php'],
    'kode_massal.php'    => ['kode_massal.php'],
    'rekap.php'          => ['rekap.php'],
    'device.php'         => ['device.php'],
    'security_log.php'   => ['security_log.php'],
    'log_aktivitas.php'  => ['log_aktivitas.php'],
    'pengguna.php'       => ['pengguna.php'],
    'hak_akses.php'      => ['hak_akses.php'],
    'pengaturan.php'     => ['pengaturan.php'],
];

function is_menu_active(string $menuKey, array $groups, string $current): bool
{
    return in_array($current, $groups[$menuKey] ?? [$menuKey], true);
}

// Label halaman & grup (eyebrow) untuk breadcrumb di topbar dan teks kecil di atas judul
$labelCrumb = [
  'dashboard' => 'Dashboard', 'siswa' => 'Data Siswa', 'siswa_tambah' => 'Tambah Siswa', 'siswa_edit' => 'Edit Siswa',
  'siswa_import' => 'Import Siswa', 'master_kelas' => 'Master Kelas', 'kenaikan_kelas' => 'Kenaikan Kelas',
  'qr_siswa' => 'QR Siswa', 'qr_generate' => 'QR Siswa', 'qr_kelas' => 'QR Kelas', 'qr_gerbang' => 'QR Gerbang',
  'presensi' => 'Data Presensi', 'rekap' => 'Rekap Bulanan', 'device' => 'Manajemen Perangkat',
  'security_log' => 'Log Keamanan', 'log_aktivitas' => 'Log Aktivitas', 'pengaturan' => 'Pengaturan', 'profil' => 'Profil Akun',
  'pengguna' => 'Pengguna', 'hak_akses' => 'Hak Akses',
  'papan' => 'Papan Kehadiran', 'kode_massal' => 'Kode Pasangan',
];
$grupHalaman = [
  'dashboard' => 'Ringkasan',
  'siswa' => 'Data & Kelas', 'siswa_tambah' => 'Data & Kelas', 'siswa_edit' => 'Data & Kelas', 'siswa_import' => 'Data & Kelas',
  'master_kelas' => 'Data & Kelas', 'kenaikan_kelas' => 'Data & Kelas',
  'qr_siswa' => 'QR Code', 'qr_generate' => 'QR Code', 'qr_kelas' => 'QR Code', 'qr_gerbang' => 'QR Code',
  'presensi' => 'Presensi', 'rekap' => 'Presensi',
  'device' => 'Sistem & Keamanan', 'security_log' => 'Sistem & Keamanan', 'log_aktivitas' => 'Sistem & Keamanan',
  'pengaturan' => 'Sistem & Keamanan', 'profil' => 'Akun',
  'pengguna' => 'Sistem & Keamanan', 'hak_akses' => 'Sistem & Keamanan',
  'papan' => 'Presensi', 'kode_massal' => 'Sistem & Keamanan',
];
$modulAktif = defined('MODUL_FILE') ? basename(MODUL_FILE, '.php') : '';
$namaAdminHdr = trim((string) ($admin['nama'] ?? ''));
$inisialAdmin = mb_strtoupper(mb_substr($namaAdminHdr !== '' ? $namaAdminHdr : (string) ($admin['username'] ?? 'A'), 0, 1));

// Siapkan item menu navigasi topbar (key => label, ikon, modul)
// Hanya ditampilkan jika user punya akses ke minimal satu modul dalam grup.
$navItems = [];
$canSee = function(string $modul): bool { return can_modul($modul); };

if ($canSee('dashboard')) $navItems[] = ['key' => 'dashboard.php', 'label' => 'Beranda', 'icon' => 'bi-house-door', 'groups' => ['dashboard.php']];
if (can_any_modul(['siswa', 'master_kelas', 'kenaikan_kelas'])) $navItems[] = ['key' => 'siswa.php', 'label' => 'Siswa', 'icon' => 'bi-people', 'groups' => ['siswa.php', 'siswa_detail.php', 'siswa_tambah.php', 'siswa_edit.php', 'siswa_hapus.php', 'siswa_import.php', 'master_kelas.php', 'kenaikan_kelas.php']];
if (can_any_modul(['qr_siswa', 'qr_gerbang', 'qr_kelas'])) $navItems[] = ['key' => 'qr_siswa.php', 'label' => 'QR Code', 'icon' => 'bi-qr-code', 'groups' => ['qr_siswa.php', 'qr_print.php', 'qr_generate.php', 'qr_gerbang.php', 'qr_kelas.php', 'qr_kelas_print.php']];
if (can_any_modul(['presensi', 'papan', 'rekap'])) $navItems[] = ['key' => 'presensi.php', 'label' => 'Presensi', 'icon' => 'bi-calendar-check', 'groups' => ['presensi.php', 'presensi_export.php', 'papan.php', 'rekap.php', 'rekap_print.php']];
if (can_any_modul(['device', 'kode_massal', 'security_log', 'log_aktivitas', 'pengguna', 'hak_akses', 'pengaturan'])) $navItems[] = ['key' => 'pengaturan.php', 'label' => 'Sistem', 'icon' => 'bi-gear', 'groups' => ['device.php', 'kode_massal.php', 'security_log.php', 'log_aktivitas.php', 'pengguna.php', 'hak_akses.php', 'pengaturan.php', 'kode_pasangan.php']];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($settings['nama_sekolah'] ?? 'Presensi Siswa') ?> — Admin</title>
<?= favicon_tag() ?>
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap.min.css?v=<?= @filemtime(__DIR__ . '/../assets/css/vendor/bootstrap.min.css') ?: time() ?>" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap-icons.min.css?v=<?= @filemtime(__DIR__ . '/../assets/css/vendor/bootstrap-icons.min.css') ?: time() ?>" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>" rel="stylesheet">
<style>:root{--tema:<?= e($settings['warna_tema'] ?? '#0d6efd') ?>;}</style>
</head>
<body class="siap-page">
  <!-- Glow biru puncak halaman -->
  <div class="siap-glow-top"></div>

  <!-- Topbar / Nav sticky (gaya iOS 17: radius besar, shadow halus, border lembut) -->
  <nav class="siap-nav siap-card-ios17">
    <div class="siap-shell d-flex align-items-center justify-content-between flex-wrap gap-2">
      <!-- Brand -->
      <a href="<?= modul_url('dashboard') ?>" class="siap-brand d-flex align-items-center gap-2 text-decoration-none">
        <i class="bi bi-qr-code-scan siap-tile"></i>
        <div>
          <div class="siap-brand-title"><?= e($settings['nama_sekolah'] ?? 'Presensi Siswa') ?></div>
          <div class="siap-brand-sub">Sistem Presensi Siswa</div>
        </div>
      </a>

      <!-- Menu desktop (tampilkan hanya pada layar besar) -->
      <div class="d-none d-lg-flex align-items-center gap-1">
        <?php foreach ($navItems as $item): $active = is_menu_active($item['key'], $item['groups'], $current); ?>
          <a href="<?= modul_url(basename($item['key'], '.php')) ?>" class="siap-nav-link <?= $active ? 'active' : '' ?> d-flex align-items-center gap-2 text-decoration-none">
            <i class="bi <?= e($item['icon']) ?>"></i>
            <?= e($item['label']) ?>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Kanan: hamburger (mobile) + dropdown profil -->
      <div class="d-flex align-items-center gap-2">
        <!-- Hamburger menu (mobile only) -->
        <button class="siap-menu-btn" id="btnHamburger" aria-label="Buka menu" aria-expanded="false" aria-controls="drawerMobile">
          <i class="bi bi-list"></i>
        </button>

        <!-- Dropdown profil -->
        <div class="dropdown">
          <button type="button" class="siap-user-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu akun">
            <span class="siap-avatar"><?= e($inisialAdmin) ?></span>
            <span class="d-none d-sm-block">
              <span class="d-block siap-mono-xs siap-label"><?= e($admin['nama'] ?? '') ?></span>
              <span class="siap-copy-sm"><?= e($admin['role'] ?? '') ?></span>
            </span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li class="dropdown-header siap-eyebrow text-dark">Akun</li>
            <li><a class="dropdown-item siap-copy-sm d-flex align-items-center gap-2" href="<?= modul_url('profil') ?>"><i class="bi bi-person"></i> Profil Akun</a></li>
            <?php if (can('pengaturan.kelola')): ?><li><a class="dropdown-item siap-copy-sm d-flex align-items-center gap-2" href="<?= modul_url('pengaturan') ?>"><i class="bi bi-gear"></i> Pengaturan</a></li><?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item siap-copy-sm d-flex align-items-center gap-2 text-danger" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right"></i> Keluar</a></li>
          </ul>
        </div>
      </div>
    </div>
  </nav>

  <!-- Drawer mobile (overlay) -->
  <div class="offcanvas offcanvas-start siap-drawer" id="drawerMobile" tabindex="-1">
    <div class="offcanvas-header siap-panel-blue siap-panel-pad">
      <h5 class="offcanvas-title siap-h3">Menu</h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Tutup"></button>
    </div>
    <div class="offcanvas-body py-3">
      <ul class="nav flex-column siap-stack">
        <?php foreach ($navItems as $item): $active = is_menu_active($item['key'], $item['groups'], $current); ?>
          <li class="nav-item mb-2">
            <a href="<?= modul_url(basename($item['key'], '.php')) ?>" class="siap-nav-link d-flex align-items-center gap-2 text-decoration-none <?= $active ? 'active' : '' ?> w-100">
              <i class="bi <?= e($item['icon']) ?>"></i>
              <?= e($item['label']) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="siap-stack mt-4 pt-3 border-top">
        <a href="<?= BASE_URL ?>/logout.php" class="siap-btn siap-btn-danger w-100 siap-mono-xs">Keluar</a>
      </div>
    </div>
  </div>

  <!-- Konten utama -->
  <main class="siap-shell siap-main content-scroll">
    <?php if ($flash): ?>
      <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show siap-card siap-card-pad" role="alert">
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>
    <?php if ($modulAktif !== '' && isset($grupHalaman[$modulAktif])): ?>
      <div class="siap-eyebrow siap-stack-sm"><?= e($grupHalaman[$modulAktif]) ?></div>
    <?php endif; ?>