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
jalankan_alpa_otomatis();

/**
 * Kelompok halaman per menu sidebar, supaya menu tetap ter-highlight aktif
 * walau sedang berada di halaman turunannya (tambah/edit/hapus/dsb),
 * bukan hanya saat persis di halaman utamanya.
 */
$menuGroups = [
  'dashboard.php'     => ['dashboard.php'],
  'akun_siswa.php'     => ['akun_siswa.php'],
  'izin.php'           => ['izin.php', 'izin_detail.php', 'izin_lampiran.php'],
  'siswa.php'          => ['siswa.php', 'siswa_detail.php', 'siswa_tambah.php', 'siswa_edit.php', 'siswa_hapus.php', 'siswa_import.php'],
  'master_kelas.php'   => ['master_kelas.php'],
  'kenaikan_kelas.php' => ['kenaikan_kelas.php'],
  'qr_siswa.php'       => ['qr_siswa.php', 'qr_print.php', 'qr_generate.php'],
  'qr_gerbang.php'     => ['qr_gerbang.php'],
  'qr_kelas.php'       => ['qr_kelas.php'],
  'presensi.php'       => ['presensi.php', 'presensi_export.php'],
  'papan.php'          => ['papan.php'],
  'rekap.php'          => ['rekap.php'],
  'device.php'         => ['device.php'],
  'log_security.php'   => ['log_security.php'],
  'log_aktivitas.php'  => ['log_aktivitas.php'],
  'pengguna.php'       => ['pengguna.php'],
  'hak_akses.php'      => ['hak_akses.php'],
  'pengaturan.php'     => ['pengaturan.php'],
];

function is_menu_active(string $menuKey, array $groups, string $current): bool
{
  return in_array($current, $groups[$menuKey] ?? [$menuKey], true);
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($settings['nama_sekolah'] ?? 'Presensi Siswa') ?> — Admin</title>
  <?= favicon_tag() ?>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>" rel="stylesheet">
  <style>
    :root {
      --tema: <?= e($settings['warna_tema'] ?? '#0d6efd') ?>;
    }
  </style>
  <script>
    try {
      if (localStorage.getItem('sidebar_collapsed') === '1') document.documentElement.classList.add('sidebar-collapsed');
    } catch (e) {}
  </script>
</head>

<body>
  <div class="d-flex app-shell">
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    <nav class="sidebar" id="sidebar">
      <div class="sidebar-brand">
        <i class="bi bi-qr-code-scan"></i>
        <span><?= e($settings['nama_sekolah'] ?? 'Presensi Siswa') ?></span>
      </div>
      <div class="sidebar-nav-scroll">
        <ul class="nav flex-column">
          <?php if (can_modul('dashboard')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('dashboard.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('dashboard') ?>"><i><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="1.15em" height="1.15em" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect width="7" height="9" x="3" y="3" rx="1.8" />
                    <rect width="7" height="5" x="14" y="3" rx="1.8" />
                    <rect width="7" height="9" x="14" y="12" rx="1.8" />
                    <rect width="7" height="5" x="3" y="16" rx="1.8" />
                  </svg></i> Dashboard</a></li><?php endif; ?>
        </ul>

        <?php if (can_any_modul(['siswa', 'akun_siswa', 'master_kelas', 'kenaikan_kelas'])): ?>
          <div class="sidebar-section-title">Data &amp; Kelas</div>
          <ul class="nav flex-column">
            <?php if (can_modul('siswa')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('siswa.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('siswa') ?>"><i class="bi bi-people"></i> Data Siswa</a></li><?php endif; ?>
            <?php if (can_modul('akun_siswa')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('akun_siswa.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('akun_siswa') ?>"><i class="bi bi-person-lock"></i> Akun Siswa</a></li><?php endif; ?>
            <?php if (can_modul('master_kelas')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('master_kelas.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('master_kelas') ?>"><i class="bi bi-tags"></i> Master Kelas</a></li><?php endif; ?>
            <?php if (can_modul('kenaikan_kelas')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('kenaikan_kelas.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('kenaikan_kelas') ?>"><i class="bi bi-arrow-up-square"></i> Kenaikan Kelas</a></li><?php endif; ?>
          </ul>
        <?php endif; ?>

        <?php if (can_any_modul(['qr_siswa', 'qr_gerbang', 'qr_kelas'])): ?>
          <div class="sidebar-section-title">QR Code</div>
          <ul class="nav flex-column">
            <?php if (can_modul('qr_siswa')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('qr_siswa.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('qr_siswa') ?>"><i class="bi bi-qr-code"></i> QR Code Siswa</a></li><?php endif; ?>
            <?php if (can_modul('qr_gerbang')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('qr_gerbang.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('qr_gerbang') ?>"><i class="bi bi-signpost-2"></i> QR Gerbang</a></li><?php endif; ?>
            <?php if (can_modul('qr_kelas')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('qr_kelas.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('qr_kelas') ?>"><i class="bi bi-easel2"></i> QR Kelas</a></li><?php endif; ?>
          </ul>
        <?php endif; ?>

        <?php if (can_any_modul(['presensi', 'papan', 'rekap', 'izin'])): ?>
          <div class="sidebar-section-title">Presensi</div>
          <ul class="nav flex-column">
            <?php if (can_modul('presensi')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('presensi.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('presensi') ?>"><i class="bi bi-calendar-check"></i> Data Presensi</a></li><?php endif; ?>
            <?php if (can_modul('papan')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('papan.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('papan') ?>"><i class="bi bi-broadcast"></i> Papan Langsung</a></li><?php endif; ?>
            <?php if (can_modul('rekap')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('rekap.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('rekap') ?>"><i class="bi bi-bar-chart"></i> Rekap Kehadiran</a></li><?php endif; ?>
            <?php if (can_modul('izin')): require_once __DIR__ . '/izin.php';
              $nIzin = izin_menunggu_jumlah(); ?><li class="nav-item"><a class="nav-link <?= is_menu_active('izin.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('izin') ?>"><i class="bi bi-envelope-paper"></i> Pengajuan Izin<?php if ($nIzin > 0): ?> <span class="badge rounded-pill bg-warning text-dark ms-1" title="Menunggu persetujuan"><?= $nIzin > 99 ? '99+' : $nIzin ?></span><?php endif; ?></a></li><?php endif; ?>
          </ul>
        <?php endif; ?>

        <?php if (can_any_modul(['device', 'log_security', 'log_aktivitas', 'pengguna', 'hak_akses', 'pengaturan'])): ?>
          <div class="sidebar-section-title">Sistem &amp; Keamanan</div>
          <ul class="nav flex-column">
            <?php if (can_modul('device')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('device.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('device') ?>"><i class="bi bi-phone"></i> Manajemen Perangkat</a></li><?php endif; ?>
            <?php if (can_modul('log_security')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('log_security.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('log_security') ?>"><i class="bi bi-shield-exclamation"></i> Log Keamanan</a></li><?php endif; ?>
            <?php if (can_modul('log_aktivitas')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('log_aktivitas.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('log_aktivitas') ?>"><i class="bi bi-clock-history"></i> Log Aktivitas</a></li><?php endif; ?>
            <?php if (can_modul('pengguna')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('pengguna.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('pengguna') ?>"><i class="bi bi-person-badge"></i> Pengguna</a></li><?php endif; ?>
            <?php if (can_modul('hak_akses')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('hak_akses.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('hak_akses') ?>"><i class="bi bi-shield-lock"></i> Hak Akses</a></li><?php endif; ?>
            <?php if (can_modul('pengaturan')): ?><li class="nav-item"><a class="nav-link <?= is_menu_active('pengaturan.php', $menuGroups, $current) ? 'active' : '' ?>" href="<?= modul_url('pengaturan') ?>"><i class="bi bi-gear"></i> Pengaturan</a></li><?php endif; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="sidebar-footer">
        <ul class="nav flex-column">
          <li class="nav-item"><a class="nav-link text-danger" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right"></i> Keluar</a></li>
        </ul>
      </div>
    </nav>

    <?php
    // Label halaman & grup (eyebrow) untuk breadcrumb di topbar dan teks kecil di atas judul
    $labelCrumb = [
      'dashboard' => 'Dashboard',
      'siswa' => 'Data Siswa',
      'siswa_tambah' => 'Tambah Siswa',
      'siswa_edit' => 'Edit Siswa',
      'siswa_import' => 'Import Siswa',
      'akun_siswa' => 'Akun Siswa',
      'master_kelas' => 'Master Kelas',
      'kenaikan_kelas' => 'Kenaikan Kelas',
      'qr_siswa' => 'QR Siswa',
      'qr_generate' => 'QR Siswa',
      'qr_kelas' => 'QR Kelas',
      'qr_gerbang' => 'QR Gerbang',
      'presensi' => 'Data Presensi',
      'rekap' => 'Rekap Bulanan',
      'izin' => 'Pengajuan Izin',
      'device' => 'Manajemen Perangkat',
      'log_security' => 'Log Keamanan',
      'log_aktivitas' => 'Log Aktivitas',
      'pengaturan' => 'Pengaturan',
      'profil' => 'Profil Akun',
      'pengguna' => 'Pengguna',
      'hak_akses' => 'Hak Akses',
      'papan' => 'Papan Kehadiran',
    ];
    $grupHalaman = [
      'dashboard' => 'Ringkasan',
      'siswa' => 'Data & Kelas',
      'siswa_tambah' => 'Data & Kelas',
      'siswa_edit' => 'Data & Kelas',
      'siswa_import' => 'Data & Kelas',
      'akun_siswa' => 'Data & Kelas',
      'master_kelas' => 'Data & Kelas',
      'kenaikan_kelas' => 'Data & Kelas',
      'qr_siswa' => 'QR Code',
      'qr_generate' => 'QR Code',
      'qr_kelas' => 'QR Code',
      'qr_gerbang' => 'QR Code',
      'presensi' => 'Presensi',
      'rekap' => 'Presensi',
      'izin' => 'Presensi',
      'device' => 'Sistem & Keamanan',
      'log_security' => 'Sistem & Keamanan',
      'log_aktivitas' => 'Sistem & Keamanan',
      'pengaturan' => 'Sistem & Keamanan',
      'profil' => 'Akun',
      'pengguna' => 'Sistem & Keamanan',
      'hak_akses' => 'Sistem & Keamanan',
      'papan' => 'Presensi',
    ];
    $modulAktif = defined('MODUL_FILE') ? basename(MODUL_FILE, '.php') : '';
    $namaAdminHdr = trim((string) ($admin['nama'] ?? ''));
    $inisialAdmin = mb_strtoupper(mb_substr($namaAdminHdr !== '' ? $namaAdminHdr : (string) ($admin['username'] ?? 'A'), 0, 1));
    ?>
    <main class="content flex-grow-1">
      <nav class="navbar navbar-light bg-white border-bottom topbar">
        <div class="container-fluid">
          <button class="btn btn-sm btn-outline-secondary" id="toggleSidebar" type="button" title="Menu" aria-label="Buka/tutup menu"><i class="bi bi-list"></i></button>
          <?php if ($modulAktif !== '' && isset($labelCrumb[$modulAktif])): ?>
            <nav class="topbar-crumb" aria-label="breadcrumb">
              <a href="<?= modul_url('dashboard') ?>" class="d-none d-sm-inline">Beranda</a>
              <?php if ($modulAktif !== 'dashboard'): ?>
                <i class="bi bi-chevron-right sep d-none d-sm-inline"></i>
              <?php endif; ?>
              <span class="now"><?= e($labelCrumb[$modulAktif]) ?></span>
            </nav>
          <?php endif; ?>
          <?php
          $notifIzin = [];
          $nNotif = 0;
          if (can_modul('izin')) {
            require_once __DIR__ . '/izin.php';
            $nNotif = izin_menunggu_jumlah();
            $notifIzin = $nNotif > 0 ? izin_menunggu_terbaru(6) : [];
          }
          ?>
          <?php if (can_modul('izin')): ?>
            <div class="dropdown ms-auto me-1">
              <button type="button" class="topbar-bell" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Notifikasi" title="Notifikasi">
                <i class="bi bi-bell"></i>
                <?php if ($nNotif > 0): ?><span class="dot"><?= $nNotif > 99 ? '99+' : $nNotif ?></span><?php endif; ?>
              </button>
              <div class="dropdown-menu dropdown-menu-end menu-notif">
                <div class="menu-notif-head"><span>Notifikasi</span><?php if ($nNotif > 0): ?><span class="badge rounded-pill bg-warning text-dark"><?= $nNotif ?> menunggu</span><?php endif; ?></div>
                <?php if (!$notifIzin): ?>
                  <div class="menu-notif-kosong"><i class="bi bi-bell-slash"></i><span>Tidak ada notifikasi baru</span></div>
                  <?php else: foreach ($notifIzin as $nz): ?>
                    <a class="menu-notif-item" href="<?= e(modul_url('izin_detail', ['id' => (int) $nz['id']])) ?>">
                      <span class="ic <?= $nz['jenis'] === 'sakit' ? 'sakit' : 'izin' ?>"><i class="bi <?= $nz['jenis'] === 'sakit' ? 'bi-bandaid' : 'bi-envelope-paper' ?>"></i></span>
                      <span class="tx">
                        <span class="jd"><?= e($nz['nama']) ?></span>
                        <span class="ds">Mengajukan <?= $nz['jenis'] === 'sakit' ? 'sakit' : 'izin' ?> · <?= e(date('d/m', strtotime($nz['tanggal_mulai']))) ?><?= $nz['tanggal_selesai'] !== $nz['tanggal_mulai'] ? '–' . e(date('d/m', strtotime($nz['tanggal_selesai']))) : '' ?></span>
                        <span class="wk"><?= e(date('d/m/Y H:i', strtotime($nz['diajukan_at']))) ?></span>
                      </span>
                    </a>
                <?php endforeach;
                endif; ?>
                <a class="menu-notif-foot" href="<?= e(modul_url('izin', ['status' => 'menunggu'])) ?>">Lihat semua pengajuan</a>
              </div>
            </div>
          <?php endif; ?>
          <div class="dropdown <?= can_modul('izin') ? '' : 'ms-auto' ?>">
            <button type="button" class="topbar-user" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Menu akun">
              <span class="topbar-user-info d-none d-md-block text-end">
                <span class="nama"><?= e($admin['nama'] ?? '') ?></span>
                <span class="peran"><?= e($admin['role'] ?? '') ?></span>
              </span>
              <span class="avatar"><?= e($inisialAdmin) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end menu-user">
              <li class="menu-user-head">
                <span class="avatar"><?= e($inisialAdmin) ?></span>
                <span class="info">
                  <span class="nama"><?= e($admin['nama'] ?? '') ?></span>
                  <span class="peran"><?= e($admin['role'] ?? '') ?></span>
                </span>
              </li>
              <li>
                <hr class="dropdown-divider">
              </li>
              <li><a class="dropdown-item" href="<?= modul_url('profil') ?>"><i class="bi bi-person"></i> Profil Akun</a></li>
              <?php if (can('pengaturan.kelola')): ?><li><a class="dropdown-item" href="<?= modul_url('pengaturan') ?>"><i class="bi bi-gear"></i> Pengaturan</a></li><?php endif; ?>
              <li>
                <hr class="dropdown-divider">
              </li>
              <li><a class="dropdown-item item-keluar" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right"></i> Keluar</a></li>
            </ul>
          </div>
        </div>
      </nav>
      <div class="container-fluid py-4 content-scroll">
        <?php if ($flash): ?>
          <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        <?php if ($modulAktif !== '' && isset($grupHalaman[$modulAktif])): ?>
          <div class="page-eyebrow"><?= e($grupHalaman[$modulAktif]) ?></div>
        <?php endif; ?>