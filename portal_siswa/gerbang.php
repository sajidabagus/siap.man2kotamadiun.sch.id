<?php
// /portal/gerbang — dipanggil bila belum ada sesi. Mencoba MASUK OTOMATIS lewat perangkat terdaftar (tanpa password).
// Browser membaca UUID perangkatnya (tidak dibuat baru), mengirimnya ke /portal/masuk-perangkat, lalu diteruskan ke halaman
// tujuan. Penolakan -> login; gangguan koneksi -> retry terbatas dan status yang jelas.
$lanjut = portal_lanjut_aman((string) ($_GET['lanjut'] ?? ''));
$tujuan = portal_url($lanjut, ['_g' => 1]);      // _g: tanda "gerbang sudah dilewati" agar tidak berputar bila cookie ditolak
$login = portal_login_url();

if (portal_siswa_sekarang()) { header('Location: ' . portal_url($lanjut)); exit; }

portal_head('Memeriksa perangkat');
$logo = portal_logo_url();
?>
<body class="pt-body pt-gerbang">
<noscript><meta http-equiv="refresh" content="0;url=<?= e($login) ?>"></noscript>
<main class="pt-gerbang-in" aria-live="polite">
  <div class="pt-gerbang-logo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><i class="bi bi-person-badge"></i><?php endif; ?></div>
  <div class="pt-spin" aria-hidden="true"></div>
  <p id="deviceAutoStatus" role="status">Memeriksa perangkat…</p>
  <div class="pt-device-actions">
    <button type="button" class="pt-btn kecil" id="deviceAutoRetry" hidden>Coba lagi</button>
    <a class="pt-gerbang-link" href="<?= e($login) ?>">Masuk dengan password</a>
  </div>
</main>
<script src="<?= BASE_URL ?>/assets/js/device.js?v=<?= @filemtime(__DIR__ . '/../assets/js/device.js') ?: 0 ?>"></script>
<script>
startDeviceAutoLogin({
  api: <?= json_encode(portal_url('masuk-perangkat')) ?>,
  statusId: 'deviceAutoStatus', retryId: 'deviceAutoRetry',
  onSuccess: function () { location.replace(<?= json_encode($tujuan) ?>); },
  onRejected: function (data) {
    location.replace(data.perlu === 'aktivasi'
      ? <?= json_encode(portal_url('aktivasi', ['dari' => 'perangkat'])) ?>
      : <?= json_encode($login) ?>);
  }
});
</script>
</body>
</html>
