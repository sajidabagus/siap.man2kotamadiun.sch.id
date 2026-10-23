<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/qr_dinamis.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$settings = get_settings();
$gate = clean($_GET['gate'] ?? '');
$errorGate = '';

if ($gate !== '') {
    if (validate_qr_dinamis($gate)) {
        $_SESSION['gate_token'] = $gate;
        $_SESSION['gate_verified_at'] = time();
    } else {
        $errorGate = 'QR gerbang tidak valid atau sudah kedaluwarsa. Silakan scan ulang.';
        unset($_SESSION['gate_token']);
    }
}

// Anggap gate token kedaluwarsa jika lebih dari 5 menit sejak diverifikasi di sesi ini
$gateValidInSession = !empty($_SESSION['gate_token']) && validate_qr_dinamis_sesi((string) $_SESSION['gate_token']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Absensi Siswa — <?= e($settings['nama_sekolah'] ?? '') ?></title>
<?= favicon_tag() ?>
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/presensi.css?v=<?= @filemtime(__DIR__ . '/../assets/css/presensi.css') ?: time() ?>" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/siswa-desktop.css?v=<?= @filemtime(__DIR__ . '/../assets/css/siswa-desktop.css') ?: time() ?>" rel="stylesheet">
</head>
<body class="presensi-page">
<?php require_once __DIR__ . '/../includes/siswa_desktop.php'; siswa_desktop_sidebar('scan', BASE_URL . '/portal'); ?>
<div class="presensi-wrap ps">
  <?php
    $logoFile = $settings['logo'] ?? '';
    $logoUrl = ($logoFile !== '' && is_file(__DIR__ . '/../uploads/siswa/' . $logoFile)) ? BASE_URL . '/uploads/siswa/' . rawurlencode($logoFile) : null;
  ?>
  <div class="ps-brand">
    <div class="ps-logo"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt=""><?php else: ?><i class="bi bi-mortarboard-fill"></i><?php endif; ?></div>
    <div>
      <div class="n"><?= e($settings['nama_sekolah'] ?? 'Sekolah') ?></div>
      <div class="s">ABSENSI SISWA</div>
    </div>
    <a href="<?= BASE_URL ?>/" class="ps-home" title="Beranda" aria-label="Beranda"><i class="bi bi-house"></i></a>
  </div>

  <div class="ps-steps">
    <div class="ps-st <?= $gateValidInSession ? 'done' : 'now' ?>"><span>1</span><em>Scan QR Sekolah</em></div>
    <div class="ps-st <?= $gateValidInSession ? 'now' : '' ?>"><span>2</span><em>Verifikasi</em></div>
    <div class="ps-st"><span>3</span><em>Konfirmasi</em></div>
  </div>

  <?php if ($errorGate): ?>
    <div class="ps-note danger" style="margin-top:0;margin-bottom:14px"><i class="bi bi-exclamation-triangle-fill"></i><div><?= e($errorGate) ?></div></div>
  <?php endif; ?>

  <?php if (!$gateValidInSession): ?>
    <div class="ps-card">
      <div class="ps-head">
        <span class="ik"><i class="bi bi-qr-code-scan"></i></span>
        <div><h2>Scan QR Sekolah</h2><p>Arahkan kamera ke QR Code yang ditampilkan di layar sekolah.</p></div>
      </div>
      <div class="ps-body">
        <div id="readerWrap" class="cam">
          <div id="reader" style="width:100%"></div>
          <span class="c tl"></span><span class="c tr"></span><span class="c bl"></span><span class="c br"></span><span class="garis"></span>
        </div>
        <div id="processingBox1" class="d-none ps-proses">
          <div class="spinner-border text-primary mb-2" role="status"></div>
          <div>QR terdeteksi, memproses...</div>
        </div>
        <p id="scanStatus" class="ps-status">Mengaktifkan kamera...</p>
        <ul class="ps-tips">
          <li><i class="bi bi-camera"></i><span>Izinkan akses kamera pada browser saat diminta.</span></li>
          <li><i class="bi bi-geo-alt"></i><span>Aktifkan lokasi (GPS) bila diminta, presensi dilakukan di area sekolah.</span></li>
          <li><i class="bi bi-brightness-high"></i><span>Pastikan QR terlihat jelas dan tidak silau.</span></li>
        </ul>
      </div>
    </div>
  <?php else: ?>
    <div class="ps-card">
      <div class="ps-center" id="autoCheckBox">
        <div class="spinner-border text-primary" role="status"></div>
        <p>Mengidentifikasi perangkat...</p>
      </div>

      <div class="d-none" id="needPersonalBox">
        <div class="ps-head">
          <span class="ik"><i class="bi bi-person-vcard"></i></span>
          <div><h2>Scan QR Pribadi</h2><p>Perangkat ini belum dikenali. Scan <strong>QR Code pribadi</strong> Anda (kartu siswa) untuk melanjutkan.</p></div>
        </div>
        <div class="ps-body">
          <div id="reader2Wrap" class="cam">
            <div id="reader2" style="width:100%"></div>
            <span class="c tl"></span><span class="c tr"></span><span class="c bl"></span><span class="c br"></span><span class="garis"></span>
          </div>
          <div id="processingBox2" class="d-none ps-proses">
            <div class="spinner-border text-primary mb-2" role="status"></div>
            <div>QR terdeteksi, memproses...</div>
          </div>
        </div>
      </div>

      <div class="d-none ps-center" id="blockedBox">
        <i class="bi bi-slash-circle" style="font-size:2.6rem;color:var(--ps-merah)"></i>
        <p style="color:var(--ps-navy);font-weight:700;margin-top:10px">Perangkat ini diblokir</p>
        <p style="margin-top:4px">Silakan hubungi administrator sekolah.</p>
      </div>
    </div>
  <?php endif; ?>

  <div class="ps-alt d-none" id="altDaftar">
    <a class="ps-btn polos" href="<?= BASE_URL ?>/portal/aktivasi"><i class="bi bi-phone"></i> Aktivasi Akun &amp; Daftarkan Perangkat</a>
    <div class="ket">Perangkat belum terdaftar? Minta kode aktivasi ke petugas, lalu masukkan di halaman aktivasi pada aplikasi SIAP (Android) atau Safari (iPhone). Akun aktif dan perangkat terdaftar sekaligus.</div>
  </div>
  <div class="ps-foot">SIAP ABSEN &copy; <?= date('Y') ?></div>
</div>

<style>
  .ov-daftar { position: fixed; inset: 0; z-index: 2000; background: rgba(15, 23, 42, .55); display: flex; align-items: center; justify-content: center; padding: 18px; }
  .ov-daftar.d-none { display: none !important; }
  .ov-kotak { background: #fff; border-radius: 16px; width: 100%; max-width: 420px; padding: 22px; box-shadow: 0 20px 50px rgba(0,0,0,.3); max-height: 92vh; overflow-y: auto; }
</style>

<div hidden>
</div>

<script src="<?= BASE_URL ?>/assets/js/vendor/html5-qrcode.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/device.js?v=<?= @filemtime(__DIR__ . '/../assets/js/device.js') ?: 0 ?>"></script>
<script>
function extractGateToken(text) {
  try {
    const url = new URL(text);
    return url.searchParams.get('gate');
  } catch (e) { return null; }
}

<?php if (!$gateValidInSession): ?>
// --- STEP 1: scan QR gerbang ---
const statusEl = document.getElementById('scanStatus');
const readerWrap = document.getElementById('readerWrap');
const processingBox1 = document.getElementById('processingBox1');
let scanner1Handled = false; // cegah callback dipanggil berulang stlh QR pertama terdeteksi

function tampilkanMemproses1() {
  readerWrap.classList.add('d-none');
  statusEl.classList.add('d-none');
  processingBox1.classList.remove('d-none');
}

const scanner1 = new Html5Qrcode('reader');
Html5Qrcode.getCameras().then(cams => {
  if (!cams || !cams.length) { statusEl.textContent = 'Kamera tidak ditemukan.'; return; }
  scanner1.start({ facingMode: 'environment' }, { fps: 10, qrbox: 230 }, (decodedText) => {
    if (scanner1Handled) return;
    const gate = extractGateToken(decodedText);
    if (gate) {
      scanner1Handled = true;
      tampilkanMemproses1(); // langsung ganti tampilan SEBELUM stop() dipanggil, tidak ada jeda kosong
      scanner1.stop().then(() => { window.location.href = '<?= BASE_URL ?>/presensi/?gate=' + encodeURIComponent(gate); });
    }
  }, () => {}).catch(err => { statusEl.textContent = 'Gagal membuka kamera: ' + err; });
}).catch(() => { statusEl.textContent = 'Tidak dapat mengakses kamera pada perangkat ini.'; });

<?php else: ?>
// --- STEP 2: auto-identify by device ---
const deviceUuid = getOrCreateDeviceUuid();

fetch('<?= BASE_URL ?>/api/scan.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ mode: 'device', device_uuid: deviceUuid, gate_token: <?= json_encode($_SESSION['gate_token']) ?> })
})
.then(r => r.json())
.then(res => {
  document.getElementById('autoCheckBox').classList.add('d-none');
  if (res.code === 'MATCH') {
    window.location.href = '<?= BASE_URL ?>/presensi/konfirmasi.php';
  } else if (res.code === 'BLOCKED') {
    document.getElementById('blockedBox').classList.remove('d-none');
  } else {
    // NEW / NOT_FOUND -> minta scan QR pribadi
    document.getElementById('needPersonalBox').classList.remove('d-none');
    const reader2Wrap = document.getElementById('reader2Wrap');
    const processingBox2 = document.getElementById('processingBox2');
    let scanner2Handled = false;

    const scanner2 = new Html5Qrcode('reader2');
    Html5Qrcode.getCameras().then(cams => {
      if (!cams || !cams.length) return;
      scanner2.start({ facingMode: 'environment' }, { fps: 10, qrbox: 230 }, (decodedText) => {
        if (scanner2Handled) return;
        try {
          const url = new URL(decodedText);
          scanner2Handled = true;
          reader2Wrap.classList.add('d-none'); // langsung ganti tampilan SEBELUM stop() dipanggil
          processingBox2.classList.remove('d-none');
          scanner2.stop().then(() => { window.location.href = url.toString(); });
        } catch (e) {}
      }, () => {});
    });
  }
})
.catch(() => { document.getElementById('autoCheckBox').innerHTML = '<p class="text-danger">Gagal terhubung ke server. Coba lagi.</p>'; });
<?php endif; ?>

// Tombol "Daftarkan Perangkat" hanya tampil bila perangkat ini BELUM terdaftar.
(function () {
  const alt = document.getElementById('altDaftar');
  if (!alt) return;
  fetch('<?= BASE_URL ?>/api/cek_perangkat.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ device_uuid: getOrCreateDeviceUuid() })
  })
  .then(r => r.json())
  .then(d => { if (!d.success || d.status === 'BELUM') alt.classList.remove('d-none'); })
  .catch(() => alt.classList.remove('d-none'));   // gagal memeriksa: tetap beri jalan untuk mendaftar
})();
</script>
</body>
</html>