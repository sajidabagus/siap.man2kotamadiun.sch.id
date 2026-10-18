<?php
require_once __DIR__ . '/../includes/functions.php';
$settings = get_settings();
$token = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) ($_GET['t'] ?? '')));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<meta name="robots" content="noindex">
<title>Daftarkan Perangkat</title>
<?= favicon_tag() ?>
<link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
<meta name="theme-color" content="<?= e($settings['warna_tema'] ?? '#0d6efd') ?>">
<script src="<?= BASE_URL ?>/assets/js/pwa.js?v=<?= @filemtime(__DIR__ . '/../assets/js/pwa.js') ?: time() ?>" defer></script>
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="presensi-page">
<div class="presensi-wrap">
  <div class="card">
    <div class="card-body text-center">

      <div id="stAwal">
        <i class="bi bi-phone display-4 text-primary"></i>
        <h5 class="mt-2 mb-1">Daftarkan Perangkat Ini</h5>
        <p class="text-muted small mb-3"><?= e($settings['nama_sekolah'] ?? '') ?></p>
        <?php if ($token === ''): ?>
          <div class="alert alert-danger small text-start mb-0">QR Pasangan tidak valid. Minta admin membuat yang baru.</div>
        <?php else: ?>
          <div class="alert alert-warning small text-start">
            📱 Perangkat ini akan didaftarkan sebagai <strong>perangkat utama</strong> Anda. Setelah itu, presensi hanya dapat dilakukan dari perangkat dan <strong>browser</strong> ini.
          </div>
          <button id="btnDaftar" class="btn btn-primary w-100"><i class="bi bi-check-circle"></i> DAFTARKAN PERANGKAT INI</button>
        <?php endif; ?>
      </div>

      <div id="stProses" class="d-none py-3">
        <div class="spinner-border text-primary mb-2"></div>
        <p class="mb-0">Mendaftarkan perangkat...</p>
      </div>

      <div id="stOk" class="d-none">
        <i class="bi bi-check-circle-fill display-4 text-success"></i>
        <h5 class="mt-2 mb-1">Perangkat Berhasil Didaftarkan</h5>
        <p class="mb-1 fw-bold" id="okNama"></p>
        <p class="text-muted small" id="okKelas"></p>
        <div class="alert alert-info small text-start">
          Sekarang scan QR gerbang/kelas di sekolah untuk presensi. Gunakan <strong>browser yang sama</strong> dengan yang Anda pakai sekarang.
        </div>
        <a href="<?= BASE_URL ?>/presensi/index.php" class="btn btn-primary w-100">Ke Halaman Presensi</a>
      </div>

      <div id="stGagal" class="d-none">
        <i class="bi bi-x-circle-fill display-4 text-danger"></i>
        <h5 class="mt-2 mb-2">Gagal Mendaftarkan</h5>
        <div class="alert alert-danger small text-start" id="gagalPesan"></div>
        <a href="<?= BASE_URL ?>/presensi/index.php" class="btn btn-outline-secondary w-100">Kembali</a>
      </div>

    </div>
  </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/device.js?v=<?= @filemtime(__DIR__ . '/../assets/js/device.js') ?: time() ?>"></script>
<script>
const token = <?= json_encode($token) ?>;
function tampil(id) {
  ['stAwal', 'stProses', 'stOk', 'stGagal'].forEach(function (x) { document.getElementById(x).classList.toggle('d-none', x !== id); });
}
const btn = document.getElementById('btnDaftar');
if (btn) btn.addEventListener('click', function () {
  tampil('stProses');
  fetch('<?= BASE_URL ?>/api/pasangan.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token: token, device_uuid: getOrCreateDeviceUuid(), device_secret: (typeof getOrCreateDeviceSecret === 'function') ? getOrCreateDeviceSecret() : '' })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      document.getElementById('okNama').textContent = (res.data && res.data.nama) || '';
      document.getElementById('okKelas').textContent = (res.data && res.data.kelas) ? 'Kelas ' + res.data.kelas : '';
      tampil('stOk');
    } else {
      document.getElementById('gagalPesan').textContent = res.message || 'Terjadi kesalahan.';
      tampil('stGagal');
    }
  })
  .catch(() => {
    document.getElementById('gagalPesan').textContent = 'Gagal terhubung ke server.';
    tampil('stGagal');
  });
});
</script>
</body>
</html>