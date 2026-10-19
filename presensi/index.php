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
$gateValidInSession = !empty($_SESSION['gate_token']) && (time() - ($_SESSION['gate_verified_at'] ?? 0) < 300)
    && validate_qr_dinamis($_SESSION['gate_token']);

// D2: alur presensi kilat wajib mengambil lokasi juga saat gate berupa QR
// KELAS (token permanen), bukan hanya ketika gps_aktif sekolah menyala.
$infoGateSesi = $gateValidInSession ? get_active_qr_dinamis((string) $_SESSION['gate_token']) : null;
$wajibGpsJs = !empty($settings['gps_aktif']) || ($infoGateSesi && !empty($infoGateSesi['kelas_target']));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Absensi Siswa — <?= e($settings['nama_sekolah'] ?? '') ?></title>
<?= favicon_tag() ?>
<link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
<meta name="theme-color" content="<?= e($settings['warna_tema'] ?? '#0d6efd') ?>">
<script src="<?= BASE_URL ?>/assets/js/pwa.js?v=<?= @filemtime(__DIR__ . '/../assets/js/pwa.js') ?: time() ?>" defer></script>
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/presensi.css?v=<?= @filemtime(__DIR__ . '/../assets/css/presensi.css') ?: time() ?>" rel="stylesheet">
</head>
<body class="presensi-page">
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
    <div class="siap-note siap-note-danger" style="margin-top:0;margin-bottom:14px"><i class="bi bi-exclamation-triangle-fill"></i><div><?= e($errorGate) ?></div></div>
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
    <button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" id="btnBukaDaftar"><i class="bi bi-phone"></i> Daftarkan Perangkat</button>
    <div class="ket">Perangkat belum terdaftar? Masukkan Kode Pasangan dari admin, atau scan QR Pasangan dengan kamera perangkat.</div>
  </div>
  <div class="ps-foot">SIAP ABSEN &copy; <?= date('Y') ?></div>
</div>

<!-- Daftarkan perangkat dengan Kode Pasangan -->
<div id="ovDaftar" class="ov-daftar d-none" role="dialog" aria-modal="true" aria-labelledby="ovJudul">
  <div class="siap-card siap-card-pad ov-kotak">
    <div class="d-flex justify-content-between align-items-start mb-2">
      <div>
        <div class="siap-eyebrow" style="letter-spacing:.08em">Pendaftaran Perangkat</div>
        <h5 class="siap-h4 mb-0" id="ovJudul">Daftarkan Perangkat</h5>
      </div>
      <button type="button" class="btn-close" id="btnTutupDaftar" aria-label="Tutup"></button>
    </div>

    <div id="ovForm">
      <p class="siap-copy-sm" style="margin-bottom:0.75rem">Minta admin membuat <strong>Kode Pasangan 6 angka</strong> untuk Anda. Kode berlaku 10 menit dan hanya bisa dipakai sekali.</p>
      <div class="bd-field siap-stack-sm">
        <label for="inNis" class="siap-label">NIS / NISN</label>
        <input type="text" id="inNis" class="siap-input mb-3" inputmode="numeric" autocomplete="off" placeholder="contoh: 1311357700022xxxxx">
      </div>
      <div class="bd-field siap-stack-sm pw">
        <label for="inKode" class="siap-label">Kode Pasangan</label>
        <input type="text" id="inKode" class="siap-input text-center fw-bold" inputmode="numeric" maxlength="6" autocomplete="one-time-code"
               style="letter-spacing:.45em; font-size:1.5rem" placeholder="••••••">
      </div>
      <div id="ovErr" class="siap-note siap-note-red siap-copy-sm mt-2 d-none"></div>
      <button type="button" id="btnKirimDaftar" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs w-100 mt-3" disabled><i class="bi bi-phone"></i> Daftarkan Perangkat Ini</button>
    </div>

    <div id="ovOk" class="siap-stack text-center d-none">
      <i class="bi bi-check-circle-fill siap-h1 text-success"></i>
      <h5 class="siap-h3 mt-2 mb-1">Perangkat Berhasil Didaftarkan</h5>
      <p class="fw-bold mb-0" id="ovNama"></p>
      <p class="siap-copy-sm" id="ovKelas"></p>
      <div class="siap-note siap-note-info siap-copy-sm">Sekarang scan QR gerbang/kelas di sekolah untuk presensi. Gunakan <strong>browser yang sama</strong> dengan yang Anda pakai sekarang.</div>
      <button type="button" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs w-100" id="btnSelesaiDaftar">Selesai</button>
    </div>
  </div>
</div>

<style>
  .ov-daftar { position: fixed; inset: 0; z-index: 2000; background: rgba(15, 23, 42, 0.55); display: flex; align-items: center; justify-content: center; padding: 1rem; }
  .ov-daftar.d-none { display: none !important; }
  .ov-kotak { max-width: 42rem; max-height: 92vh; overflow-y: auto; }
</style>

<div hidden>
</div>

<script src="<?= BASE_URL ?>/assets/js/vendor/html5-qrcode.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/device.js?v=<?= @filemtime(__DIR__ . '/../assets/js/device.js') ?: time() ?>"></script>
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
// --- STEP 2: presensi kilat — identify + validasi + simpan dalam SATU request ---
const deviceUuid = getOrCreateDeviceUuid();
const gpsAktif = <?= $wajibGpsJs ? 'true' : 'false' ?>;
const autoBox = document.getElementById('autoCheckBox');

function mulaiScanPribadi() {
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

function kirimPresensiKilat(lat, lng) {
  fetch('<?= BASE_URL ?>/api/quick.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ device_uuid: deviceUuid, device_secret: (typeof getOrCreateDeviceSecret === 'function') ? getOrCreateDeviceSecret() : '', gate_token: <?= json_encode($_SESSION['gate_token']) ?>, latitude: lat, longitude: lng })
  })
  .then(r => r.json())
  .then(res => {
    autoBox.classList.add('d-none');
    if (res.success && res.code === 'OK') {
      window.location.href = '<?= BASE_URL ?>/presensi/berhasil.php?status=ok&msg=' + encodeURIComponent(res.message || '');
      return;
    }
    if (res.code === 'ALREADY_PRESENT') {
      window.location.href = '<?= BASE_URL ?>/presensi/berhasil.php?status=already';
      return;
    }
    if (res.code === 'BLOCKED') {
      document.getElementById('blockedBox').classList.remove('d-none');
      return;
    }
    if (res.code === 'NOT_FOUND' || res.code === 'INACTIVE') {
      // Perangkat belum dikenali -> alur lama: scan QR pribadi.
      mulaiScanPribadi();
      return;
    }
    // Kegagalan lain (GATE_INVALID / GPS_REQUIRED / OUT_OF_RANGE / KELAS_MISMATCH / MISMATCH / dll)
    window.location.href = '<?= BASE_URL ?>/presensi/berhasil.php?status=error&code=' + encodeURIComponent(res.code || '') + '&msg=' + encodeURIComponent(res.message || '');
  })
  .catch(() => {
    autoBox.innerHTML = '<p class="text-danger">Gagal terhubung ke server. Coba lagi.</p>';
  });
}

// Jalankan langsung setelah QR gerbang/kelas terverifikasi di sesi ini.
if (gpsAktif && navigator.geolocation) {
  const p = autoBox.querySelector('p');
  if (p) p.textContent = 'Mengambil lokasi & mencatat presensi...';
  navigator.geolocation.getCurrentPosition(
    pos => kirimPresensiKilat(pos.coords.latitude, pos.coords.longitude),
    () => kirimPresensiKilat(null, null),
    { timeout: 15000, enableHighAccuracy: true, maximumAge: 0 }
  );
} else {
  kirimPresensiKilat(null, null);
}
<?php endif; ?>

// Tombol "Daftarkan Perangkat" hanya tampil bila perangkat ini BELUM terdaftar.
(function () {
  const alt = document.getElementById('altDaftar');
  if (!alt) return;
  fetch('<?= BASE_URL ?>/api/cek_perangkat.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ device_uuid: getOrCreateDeviceUuid(), device_secret: (typeof getOrCreateDeviceSecret === 'function') ? getOrCreateDeviceSecret() : '' })
  })
  .then(r => r.json())
  .then(d => { if (!d.success || d.status === 'BELUM') alt.classList.remove('d-none'); })
  .catch(() => alt.classList.remove('d-none'));   // gagal memeriksa: tetap beri jalan untuk mendaftar
})();

// --- Daftarkan perangkat dengan Kode Pasangan (NIS + kode 6 angka) ---
(function () {
  const ov = document.getElementById('ovDaftar');
  const inNis = document.getElementById('inNis');
  const inKode = document.getElementById('inKode');
  const btnKirim = document.getElementById('btnKirimDaftar');
  const err = document.getElementById('ovErr');
  const labelAwal = btnKirim.innerHTML;

  function cek() { btnKirim.disabled = !(inNis.value.trim().length >= 4 && inKode.value.length === 6); err.classList.add('d-none'); }
  function buka() { ov.classList.remove('d-none'); setTimeout(function () { inNis.focus(); }, 100); }
  function tutup() { ov.classList.add('d-none'); }

  document.getElementById('btnBukaDaftar').addEventListener('click', buka);
  document.getElementById('btnTutupDaftar').addEventListener('click', tutup);
  document.getElementById('btnSelesaiDaftar').addEventListener('click', function () { window.location.reload(); });
  ov.addEventListener('click', function (e) { if (e.target === ov) tutup(); });
  inNis.addEventListener('input', cek);
  inKode.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); cek(); });

  btnKirim.addEventListener('click', function () {
    btnKirim.disabled = true;
    btnKirim.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Memeriksa...';
    fetch('<?= BASE_URL ?>/api/pasangan.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ nis: inNis.value.trim(), kode: inKode.value, device_uuid: getOrCreateDeviceUuid(), device_secret: (typeof getOrCreateDeviceSecret === 'function') ? getOrCreateDeviceSecret() : '' })
    })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        document.getElementById('ovNama').textContent = (res.data && res.data.nama) || '';
        document.getElementById('ovKelas').textContent = (res.data && res.data.kelas) ? 'Kelas ' + res.data.kelas : '';
        document.getElementById('ovForm').classList.add('d-none');
        document.getElementById('ovOk').classList.remove('d-none');
      } else {
        err.textContent = res.message || 'Gagal mendaftarkan perangkat.';
        err.classList.remove('d-none');
        btnKirim.innerHTML = labelAwal;
        btnKirim.disabled = !(inNis.value.trim().length >= 4 && inKode.value.length === 6);
      }
    })
    .catch(() => {
      err.textContent = 'Gagal terhubung ke server. Coba lagi.';
      err.classList.remove('d-none');
      btnKirim.innerHTML = labelAwal;
      btnKirim.disabled = false;
    });
  });
})();
</script>
</body>
</html>