<?php
/** Kerangka HTML portal siswa (dipakai semua halaman portal). */

function portal_head(string $judul): void
{
    $s = get_settings();
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#114e2e">
<title><?= e($judul) ?> — Portal Siswa <?= e($s['nama_sekolah'] ?? '') ?></title>
<?= favicon_tag() ?>
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/portal.css?v=<?= @filemtime(__DIR__ . '/../assets/css/portal.css') ?: time() ?>" rel="stylesheet">
</head>
<?php
}

/**
 * Alamat "Home" untuk menu bawah & panah kembali. Sesi hasil masuk otomatis (perangkat terdaftar) kembali ke beranda
 * utama "/" (tampilan siswa tanpa login), bukan ke /portal, supaya siswa tidak berpindah ke beranda lain.
 */
function portal_url_home(): string
{
    return (function_exists('portal_sesi_via_perangkat') && portal_sesi_via_perangkat()) ? BASE_URL . '/' : portal_url();
}

/** Halaman masuk/aktivasi: panel merek + panel formulir. */
function portal_auth_awal(string $judul, string $heading, string $sub = '', bool $tombolKembali = true): void
{
    $s = get_settings();
    $nama = (string) ($s['nama_sekolah'] ?? 'Sekolah');
    $logo = portal_logo_url();
    portal_head($judul);
    ?>
<body class="pt-body">
<main class="pt-auth">
  <section class="pt-brand">
    <?php if ($tombolKembali): ?><a class="pt-back" href="<?= BASE_URL ?>/" aria-label="Kembali ke beranda"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
    <div class="pt-logo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><i class="bi bi-person-badge"></i><?php endif; ?></div>
    <h2>Portal Siswa</h2>
    <p class="pt-sekolah"><?= e($nama) ?></p>
    <p class="pt-uraian">Layanan mandiri siswa <?= e($nama) ?>. Pantau kehadiran Anda dan, segera, ajukan izin atau sakit langsung dari sini.</p>
    <div class="pt-garis"></div>
  </section>
  <section class="pt-form">
    <div class="pt-form-in">
      <h1><?= e($heading) ?></h1>
      <p class="pt-sub"><?= e($sub) ?></p>
<?php
}

function portal_auth_akhir(): void
{
    $s = get_settings();
    ?>
      <p class="pt-hak"><?= e($s['nama_sekolah'] ?? '') ?> · SIAP ABSEN © <?= date('Y') ?></p>
    </div>
  </section>
</main>
<script src="<?= BASE_URL ?>/assets/js/portal.js"></script>
</body>
</html>
<?php
}

function portal_alert(string $jenis, string $pesan): void
{
    $ikon = ['err' => 'exclamation-octagon-fill', 'ok' => 'check-circle-fill', 'info' => 'info-circle-fill'][$jenis] ?? 'info-circle-fill';
    echo '<div class="pt-alert ' . e($jenis) . '" role="alert"><i class="bi bi-' . $ikon . '"></i><span>' . e($pesan) . '</span></div>';
}

/** Halaman pesan sederhana (portal ditutup / belum siap / tidak ditemukan). */
function portal_halaman_pesan(int $kode, string $judul, string $pesan): void
{
    http_response_code($kode);
    portal_auth_awal($judul, $judul, '');
    portal_alert('info', $pesan);
    echo '<a class="pt-btn garis" href="' . e(BASE_URL) . '/"><i class="bi bi-house"></i> Ke beranda</a>';
    portal_auth_akhir();
    exit;
}

/**
 * Halaman setelah masuk: bilah judul + isi + menu bawah (Home, Riwayat, [Scan], Izin, Profil).
 * $aktif: beranda|riwayat|izin|profil. $opsi: 'kembali' => sub-halaman portal untuk tombol panah kembali (tanpa = tidak ada),
 * 'judul' => judul bilah (bawaan: $judul), 'aksi' => HTML tombol di kanan bilah (dari halaman sendiri, bukan input pengguna),
 * 'tanpa_header' => true untuk Home (membuat sapaannya sendiri).
 */
function portal_app_awal(string $judul, array $akun, string $aktif = 'beranda', array $opsi = []): void
{
    $GLOBALS['PORTAL_AKTIF'] = $aktif;
    $GLOBALS['PORTAL_NOTIF'] = portal_notif_belum_dibaca((int) $akun['siswa_id']);
    portal_head($judul);
    ?>
<body class="pt-body pt-app pt-v2">
<?php if (empty($opsi['tanpa_header'])): ?>
<header class="pt-bar">
  <?php if (isset($opsi['kembali'])): ?>
    <a class="pt-bar-btn" href="<?= e((string) $opsi['kembali'] === '' ? portal_url_home() : portal_url((string) $opsi['kembali'])) ?>" aria-label="Kembali"><i class="bi bi-arrow-left"></i></a>
  <?php else: ?><span class="pt-bar-btn kosong" aria-hidden="true"></span><?php endif; ?>
  <h1><?= e((string) ($opsi['judul'] ?? $judul)) ?></h1>
  <div class="pt-bar-aksi"><?= $opsi['aksi'] ?? '' ?></div>
</header>
<?php endif; ?>
<main class="pt-wrap pt-main">
<?php
}

function portal_app_akhir(): void
{
    $aktif = (string) ($GLOBALS['PORTAL_AKTIF'] ?? '');
    $baru = (int) ($GLOBALS['PORTAL_NOTIF'] ?? 0);
    $on = fn(string $k): string => $aktif === $k ? ' class="on" aria-current="page"' : '';
    ?>
</main>
<nav class="pt-tab" aria-label="Menu utama">
  <a href="<?= e(portal_url_home()) ?>"<?= $on('beranda') ?>><i class="bi bi-house-door-fill"></i><span>Home</span></a>
  <a href="<?= e(portal_url('riwayat')) ?>"<?= $on('riwayat') ?>><i class="bi bi-journal-text"></i><span>Riwayat</span></a>
  <a class="pt-scan" href="<?= e(BASE_URL) ?>/presensi/" aria-label="Scan QR untuk absen"><i class="bi bi-qr-code-scan"></i></a>
  <a href="<?= e(portal_url('izin')) ?>"<?= $on('izin') ?>><i class="bi bi-file-earmark-text-fill"></i><span>Izin</span><?php if ($baru > 0): ?><b class="pt-dot-tab" aria-label="<?= $baru ?> notifikasi baru"><?= $baru > 9 ? '9+' : $baru ?></b><?php endif; ?></a>
  <a href="<?= e(portal_url('profil')) ?>"<?= $on('profil') ?>><i class="bi bi-person-circle"></i><span>Profil</span></a>
</nav>
<script src="<?= BASE_URL ?>/assets/js/portal.js"></script>
</body>
</html>
<?php
}
