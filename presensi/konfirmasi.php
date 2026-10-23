<?php
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['identified_siswa_id'])) {
    header('Location: ' . BASE_URL . '/presensi/index.php');
    exit;
}

$stmt = getDB()->prepare('SELECT * FROM siswa WHERE id = :id');
$stmt->execute([':id' => $_SESSION['identified_siswa_id']]);
$siswa = $stmt->fetch();

if (!$siswa) {
    unset($_SESSION['identified_siswa_id']);
    header('Location: ' . BASE_URL . '/presensi/index.php');
    exit;
}

$settings = get_settings();

// QR Kelas (kelas_target terisi) = presensi otomatis tanpa tombol konfirmasi bila perangkat sudah terdaftar.
// QR Gerbang (kelas_target kosong) tetap memakai tombol konfirmasi.
require_once __DIR__ . '/../includes/qr_dinamis.php';
$gateSesi = (string) ($_SESSION['gate_token'] ?? '');
$infoGate = $gateSesi !== '' ? get_active_qr_dinamis($gateSesi) : null;
$autoKonfirmasi = $infoGate && !empty($infoGate['kelas_target']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Konfirmasi Presensi</title>
<?= favicon_tag() ?>
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/presensi.css?v=<?= @filemtime(__DIR__ . '/../assets/css/presensi.css') ?: time() ?>" rel="stylesheet">
</head>
<body class="presensi-page">
<?php
  $logoFile = $settings['logo'] ?? '';
  $logoUrl = ($logoFile !== '' && is_file(__DIR__ . '/../uploads/siswa/' . $logoFile)) ? BASE_URL . '/uploads/siswa/' . rawurlencode($logoFile) : null;
  $kata = preg_split('/\s+/', trim((string) $siswa['nama']));
  $inisial = mb_strtoupper(mb_substr($kata[0] ?? '', 0, 1) . (isset($kata[1]) ? mb_substr($kata[1], 0, 1) : ''));
  $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
  $tglIni = NAMA_HARI[(int) date('N')] . ', ' . date('j') . ' ' . $namaBulan[(int) date('n')] . ' ' . date('Y');
?>
<div class="presensi-wrap ps">

  <div class="ps-brand">
    <div class="ps-logo"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt=""><?php else: ?><i class="bi bi-mortarboard-fill"></i><?php endif; ?></div>
    <div>
      <div class="n"><?= e($settings['nama_sekolah'] ?? 'Sekolah') ?></div>
      <div class="s">ABSENSI SISWA</div>
    </div>
    <a href="<?= BASE_URL ?>/" class="ps-home" title="Beranda" aria-label="Beranda"><i class="bi bi-house"></i></a>
  </div>

  <div class="ps-steps">
    <div class="ps-st done"><span>1</span><em>Scan QR Sekolah</em></div>
    <div class="ps-st done"><span>2</span><em>Verifikasi</em></div>
    <div class="ps-st now"><span>3</span><em>Konfirmasi</em></div>
  </div>

  <div id="loadingBox" class="ps-card">
    <div class="ps-center">
      <div class="spinner-border text-primary"></div>
      <p>Memeriksa data...</p>
    </div>
  </div>

  <div id="cardBox" class="ps-card d-none">
    <div class="ps-profile">
      <?php if ($siswa['foto']): ?>
        <img src="<?= BASE_URL ?>/uploads/siswa/<?= e($siswa['foto']) ?>" class="ps-foto" alt="">
      <?php else: ?>
        <div class="ps-foto"><?= e($inisial) ?></div>
      <?php endif; ?>
      <h3><?= e($siswa['nama']) ?></h3>
      <div class="ps-chips">
        <span class="ps-chip">NIS <?= e($siswa['nis']) ?></span>
        <span class="ps-chip">Kelas <?= e($siswa['kelas']) ?></span>
        <span class="ps-chip ok">Aktif</span>
      </div>
    </div>

    <div class="ps-info">
      <div class="r"><span>Tanggal</span><span><?= e($tglIni) ?></span></div>
      <div class="r"><span>Waktu</span><span id="jamSekarang">--:--:--</span></div>
    </div>

    <div class="ps-aksi">
      <div id="autoBox" class="ps-note ok d-none" style="align-items:center">
        <span class="spinner-border spinner-border-sm text-success flex-none"></span>
        <div><strong>Perangkat terverifikasi.</strong><br>Mencatat presensi Anda…<?= !empty($settings['gps_aktif']) ? ' Izinkan akses lokasi bila diminta.' : '' ?></div>
      </div>
      <div id="warnBox" class="ps-note warn d-none">
        <i class="bi bi-phone"></i>
        <div><strong>Perangkat baru terdeteksi.</strong><br>Perangkat ini akan didaftarkan sebagai perangkat utama Anda. Setelah didaftarkan, presensi hanya dapat dilakukan dari perangkat ini.</div>
      </div>
      <div id="mismatchBox" class="ps-note danger d-none">
        <i class="bi bi-x-octagon-fill"></i>
        <div><strong>Perangkat tidak dikenali</strong><br>Perangkat ini bukan perangkat yang terdaftar untuk siswa tersebut. Silakan gunakan perangkat yang telah didaftarkan.</div>
      </div>
      <div id="claimedBox" class="ps-note danger d-none">
        <i class="bi bi-x-octagon-fill"></i>
        <div><strong>Perangkat sudah dipakai siswa lain</strong><br>Satu perangkat hanya boleh dipakai satu siswa. Hubungi admin sekolah kalau menurut Anda ini keliru.</div>
      </div>
      <div id="blockedBox" class="ps-note danger d-none">
        <i class="bi bi-slash-circle-fill"></i>
        <div>Perangkat ini diblokir. Silakan hubungi administrator sekolah.</div>
      </div>
      <div id="errBox" class="ps-note danger d-none"></div>

      <div id="pairBox" class="d-none">
        <div class="ps-note info">
          <i class="bi bi-key-fill"></i>
          <div><strong>Perangkat ini belum terdaftar.</strong><br>Belum punya akun aktif? Minta <strong>kode aktivasi</strong> ke petugas, lalu masukkan di halaman aktivasi pada <strong>aplikasi SIAP</strong> (Android) atau <strong>Safari</strong> (iPhone); akun aktif dan perangkat terdaftar sekaligus. Sudah punya akun? Minta <strong>kode perangkat</strong> ke petugas, lalu masuk ke portal di aplikasi SIAP (Android) atau Safari (iPhone) dan pilih <strong>Daftarkan perangkat ini</strong>.</div>
        </div>
        <a href="<?= BASE_URL ?>/portal/aktivasi" class="ps-btn utama" style="margin-top:14px"><i class="bi bi-phone"></i> <span>AKTIVASI AKUN &amp; DAFTARKAN PERANGKAT</span></a>
      </div>

      <button id="btnConfirm" class="ps-btn hijau d-none" style="margin-top:16px">
        <i class="bi bi-check-circle-fill"></i> <span id="btnLabel">KONFIRMASI PRESENSI</span>
      </button>
      <a href="<?= BASE_URL ?>/presensi/index.php" class="ps-btn polos" id="btnBack" style="margin-top:10px">Kembali</a>
    </div>
  </div>

  <div class="ps-foot">SIAP ABSEN &copy; <?= date('Y') ?></div>
</div>

<script src="<?= BASE_URL ?>/assets/js/device.js"></script>
<script>
const deviceUuid = getOrCreateDeviceUuid();
const autoKonfirmasi = <?= $autoKonfirmasi ? 'true' : 'false' ?>;
const gpsAktif = <?= !empty($settings['gps_aktif']) ? 'true' : 'false' ?>;

// Jam berjalan pada kartu konfirmasi
(function () {
  var el = document.getElementById('jamSekarang');
  function t() { var d = new Date(), p = function (n) { return String(n).padStart(2, '0'); }; if (el) el.textContent = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()); }
  t(); setInterval(t, 1000);
})();

function showBox(id) { document.getElementById(id).classList.remove('d-none'); }
function hideLoading() {
  document.getElementById('loadingBox').classList.add('d-none');
  document.getElementById('cardBox').classList.remove('d-none');
}

fetch('<?= BASE_URL ?>/api/scan.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ mode: 'preview', device_uuid: deviceUuid })
})
.then(r => r.json())
.then(res => {
  hideLoading();
  if (!res.success) {
    document.getElementById('errBox').textContent = res.message || 'Terjadi kesalahan.';
    showBox('errBox');
    return;
  }
  if (res.code === 'ALREADY_PRESENT') {
    window.location.href = '<?= BASE_URL ?>/presensi/berhasil.php?status=already';
    return;
  }
  if (res.device_state === 'BLOCKED') { showBox('blockedBox'); return; }
  if (res.device_state === 'MISMATCH') { showBox('mismatchBox'); return; }
  if (res.device_state === 'CLAIMED_BY_OTHER') { showBox('claimedBox'); return; }
  if (res.device_state === 'NEED_PAIRING') {
    showBox('pairBox');
    return;
  }
  const bindingMati = res.binding_aktif === false;   // pendaftaran perangkat tidak diwajibkan
  if (res.device_state === 'NEW' && !bindingMati) {
    showBox('warnBox');
    document.getElementById('btnLabel').textContent = 'DAFTARKAN PERANGKAT & PRESENSI';
  } else if (autoKonfirmasi && (res.device_state === 'MATCH' || bindingMati)) {
    // QR Kelas + perangkat terverifikasi (atau binding dimatikan): langsung catat presensi tanpa tombol
    showBox('autoBox');
    document.getElementById('btnBack').classList.add('d-none');
    document.getElementById('btnConfirm').click();
    return;
  }
  showBox('btnConfirm');
})
.catch(() => {
  hideLoading();
  document.getElementById('errBox').textContent = 'Gagal terhubung ke server.';
  showBox('errBox');
});

document.getElementById('btnConfirm').addEventListener('click', function () {
  this.disabled = true;
  this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menyimpan...';

  function submitPresensi(lat, lng) {
    fetch('<?= BASE_URL ?>/api/presensi.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ device_uuid: deviceUuid, latitude: lat, longitude: lng })
    })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        window.location.href = '<?= BASE_URL ?>/presensi/berhasil.php?status=ok&msg=' + encodeURIComponent(res.message || '');
      } else {
        window.location.href = '<?= BASE_URL ?>/presensi/berhasil.php?status=error&code=' + encodeURIComponent(res.code || '') + '&msg=' + encodeURIComponent(res.message || '');
      }
    })
    .catch(() => {
      window.location.href = '<?= BASE_URL ?>/presensi/berhasil.php?status=error&msg=' + encodeURIComponent('Gagal terhubung ke server.');
    });
  }

  if (gpsAktif && navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      pos => submitPresensi(pos.coords.latitude, pos.coords.longitude),
      () => submitPresensi(null, null),
      { timeout: 15000, enableHighAccuracy: true, maximumAge: 0 }
    );
  } else {
    submitPresensi(null, null);
  }
});
</script>
</body>
</html>