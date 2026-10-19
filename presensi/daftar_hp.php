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
<link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/presensi.css?v=<?= @filemtime(__DIR__ . '/../assets/css/presensi.css') ?: time() ?>" rel="stylesheet">
</head>
<body class="presensi-page">
<div class="presensi-wrap ps">
  <div class="ps-card">
    <div class="ps-body text-center">

      <div id="stAwal" class="ps-center">
        <div class="ps-head">
          <span class="ik"><i class="bi bi-phone"></i></span>
          <div>
            <h2>Daftarkan Perangkat Ini</h2>
            <p class="siap-copy-sm"><?= e($settings['nama_sekolah'] ?? 'Sekolah') ?></p>
          </div>
        </div>
        <?php if ($token === ''): ?>
          <div class="siap-note siap-note-danger" style="margin-top:1rem;text-align:left"><i class="bi bi-exclamation-circle-fill"></i><div>QR Pasangan tidak valid. Minta admin membuat yang baru.</div></div>
        <?php else: ?>
          <div class="siap-note siap-note-warning" style="margin-top:1rem;text-align:left"><i class="bi bi-info-circle-fill"></i><div>📱 Perangkat ini akan didaftarkan sebagai <strong>perangkat utama</strong> Anda. Setelah itu, presensi hanya dapat dilakukan dari perangkat dan <strong>browser</strong> ini.</div></div>
          <button id="btnDaftar" class="siap-btn-ios17 siap-btn-ios17-blue w-100 mt-3"><i class="bi bi-check-circle-fill"></i> Daftarkan Perangkat Ini</button>
        <?php endif; ?>
      </div>

      <div id="stProses" class="d-none ps-center py-3">
        <div class="spinner-border" style="color:var(--siap-blue)" role="status"></div>
        <p class="siap-copy-sm mt-2 mb-0">Mendaftarkan perangkat...</p>
      </div>

      <div id="stOk" class="d-none ps-center">
        <div class="siap-note siap-note-success" style="display:inline-flex;align-items:center;gap:0.5rem;margin-bottom:1rem"><i class="bi bi-check-circle-fill" style="font-size:2rem;color:var(--siap-success-text)"></i><div><div class="siap-h3 mb-0">Perangkat Berhasil Didaftarkan</div><div class="siap-copy-sm mb-0" id="okNama"></div></div></div>
        <p class="siap-copy-sm mb-1" id="okKelas"></p>
        <div class="siap-note siap-note-info" style="margin-top:1rem;text-align:left"><i class="bi bi-phone"></i><div>Sekarang scan QR gerbang/kelas di sekolah untuk presensi. Gunakan <strong>browser yang sama</strong> dengan yang Anda pakai sekarang.</div></div>
        <a href="<?= BASE_URL ?>/presensi/index.php" class="siap-btn-ios17 siap-btn-ios17-blue w-100 mt-3">Ke Halaman Presensi</a>
      </div>

      <div id="stGagal" class="d-none ps-center">
        <div class="siap-note siap-note-danger" style="display:inline-flex;align-items:center;gap:0.5rem;margin-bottom:1rem"><i class="bi bi-x-circle-fill" style="font-size:2rem;color:var(--siap-danger-text)"></i><div><div class="siap-h3 mb-0">Gagal Mendaftarkan</div></div></div>
        <div class="siap-note siap-note-danger" id="gagalPesan" style="margin-top:0.5rem;text-align:left"></div>
        <a href="<?= BASE_URL ?>/presensi/index.php" class="siap-btn-ios17 siap-btn-ios17-ghost w-100 mt-3">Kembali</a>
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