<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/qr_dinamis.php';

$settings = get_settings();
$lokasi = clean($_GET['lokasi'] ?? 'Gerbang Utama');
$qr = generate_qr_dinamis($lokasi);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="<?= (int)$qr['durasi_detik'] ?>">
<title>QR Gerbang — <?= e($lokasi) ?></title>
<?= favicon_tag() ?>
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap.min.css?v=<?= @filemtime(__DIR__ . '/../assets/css/vendor/bootstrap.min.css') ?: time() ?>" rel="stylesheet">
<style>
  body { background:var(--siap-bg); color:var(--siap-ink); height:100vh; display:flex; align-items:center; justify-content:center; }
  .box { text-align:center; }
  #qrHolder { background:#fff; padding:16px; border-radius:12px; display:inline-flex; }
  #countdown { font-size: 2rem; font-weight:bold; }
</style>
</head>
<body>
  <div class="box">
    <h2><?= e($settings['nama_sekolah'] ?? '') ?></h2>
    <p class="mb-1 fs-5">Scan QR ini dengan perangkat Anda di halaman presensi (<?= e($lokasi) ?>)</p>
    <div id="qrHolder" class="my-3 d-flex justify-content-center"></div>
    <div id="countdown"><?= (int)$qr['durasi_detik'] ?></div>
    <p class="text-muted small">Kode ini otomatis berganti secara berkala untuk keamanan.</p>
    <div class="no-print mt-3">
      <a href="<?= e(modul_url('qr_gerbang', ['lokasi' => $lokasi])) ?>" class="siap-btn siap-btn-ghost siap-mono-xs btn-sm">Muat Ulang Sekarang</a>
    </div>
  </div>

<script src="<?= BASE_URL ?>/assets/js/vendor/qrcode.js"></script>
<script>
const content = <?= json_encode(BASE_URL . '/presensi/?gate=' . $qr['token']) ?>;
const qr = qrcode(0, 'M');
qr.addData(content);
qr.make();
document.getElementById('qrHolder').innerHTML = qr.createImgTag(7, 8);

let sisa = <?= (int)$qr['durasi_detik'] ?>;
const el = document.getElementById('countdown');
setInterval(function () {
  sisa--;
  if (sisa <= 0) { location.reload(); return; }
  el.textContent = sisa;
}, 1000);
</script>
</body>
</html>