<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/qr_dinamis.php';

$db = getDB();
$settings = get_settings();
$kelasFilter = clean($_GET['kelas'] ?? '');
$jenjangFilter = clean($_GET['jenjang'] ?? '');

$kelasList = $db->query('SELECT DISTINCT kelas FROM siswa ORDER BY kelas')->fetchAll(PDO::FETCH_COLUMN);

if ($kelasFilter !== '') {
    $kelasDicetak = [$kelasFilter];
} elseif ($jenjangFilter !== '') {
    $kelasDicetak = array_values(array_filter($kelasList, fn($k) => ambil_jenjang($k) === $jenjangFilter));
} else {
    $kelasDicetak = $kelasList;
}

$logoFile = !empty($settings['logo']) ? $settings['logo'] : null;
$websiteHost = parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost';

// Mode tampilan:
// - 'single' -> 1 kelas dipilih spesifik (dari tombol "Cetak Kartu Ini"):
//               tetap poster besar 1 kelas per halaman seperti sebelumnya.
// - 'grid'   -> "Cetak Semua Jenjang" atau "Cetak Semua Kelas": banyak kelas
//               sekaligus, dicetak sebagai kartu kecil ukuran 6x9 cm (3x3 = 9 kartu/halaman A4) supaya
//               satu halaman bisa memuat banyak kartu (hemat kertas), dengan
//               elemen visual yang sama (badge sekolah, SCAN HERE, bingkai QR
//               kuning, label kelas) hanya diperkecil proporsional.
$mode = ($kelasFilter !== '') ? 'single' : 'grid';

// Ambil (atau buat kalau belum ada) QR PERMANEN utk tiap kelas yang akan dicetak.
// TIDAK pernah generate baru di sini -> poster yg sudah dicetak sebelumnya tetap berlaku.
$dataKelas = [];
foreach ($kelasDicetak as $k) {
    $qr = get_or_create_qr_kelas($k);
    $dataKelas[] = ['kelas' => $k, 'token' => $qr['token']];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Poster QR Kelas</title>
<?= favicon_tag() ?>
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap.min.css?v=<?= @filemtime(__DIR__ . '/../assets/css/vendor/bootstrap.min.css') ?: time() ?>" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap-icons.min.css?v=<?= @filemtime(__DIR__ . '/../assets/css/vendor/bootstrap-icons.min.css') ?: time() ?>" rel="stylesheet">
<style>
@font-face {
  font-family: 'Baloo2Poster';
  font-weight: 800;
  src: url('<?= BASE_URL ?>/assets/css/vendor/fonts/fonts-baloo/baloo-2-latin-800-normal.woff2') format('woff2'),
       url('<?= BASE_URL ?>/assets/css/vendor/fonts/fonts-baloo/baloo-2-latin-800-normal.woff') format('woff');
  font-display: swap;
}
@font-face {
  font-family: 'Baloo2Poster';
  font-weight: 700;
  src: url('<?= BASE_URL ?>/assets/css/vendor/fonts/fonts-baloo/baloo-2-latin-700-normal.woff2') format('woff2'),
       url('<?= BASE_URL ?>/assets/css/vendor/fonts/fonts-baloo/baloo-2-latin-700-normal.woff') format('woff');
  font-display: swap;
}

body { background: var(--siap-bg); font-family: Arial, Helvetica, sans-serif; color: var(--siap-ink); }
.toolbar { padding: 14px 20px; }

.poster {
  position: relative;
  width: 700px;
  height: 990px;
  margin: 0 auto 30px;
  background: #ffffff;
  overflow: hidden;
  border-radius: 6px;
  box-shadow: 0 4px 18px rgba(0,0,0,.12);
  page-break-after: always;
}
.poster-bg { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; }

.poster-content { position: relative; z-index: 1; height: 100%; display: flex; flex-direction: column; align-items: center; }

.header-row { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 46px; }
.dots-grid { display: flex; flex-wrap: wrap; gap: 8px; width: 82px; }
.dots-grid span { width: 10px; height: 10px; border-radius: 50%; background: #1f8f94; display: block; }
.pill-badge {
  background: #1f8f94; color: #fff; font-weight: 700; font-size: 22px;
  padding: 14px 34px; border-radius: 40px; white-space: nowrap;
}

.logo-besar { width: 150px; height: 150px; object-fit: contain; margin-top: 26px; }
.logo-kosong-besar { width: 150px; height: 150px; margin-top: 26px; }

.scan-here {
  font-family: 'Baloo2Poster', Arial, sans-serif;
  font-weight: 800;
  font-size: 76px;
  color: #2a9297;
  text-shadow: 4px 5px 0 rgba(160,175,195,.55);
  letter-spacing: 2px;
  margin-top: 14px;
  line-height: 1;
}

.qr-frame {
  position: relative;
  width: 460px; height: 460px;
  background: #fbdb57;
  border-radius: 44px;
  margin-top: 18px;
  display: flex; align-items: center; justify-content: center;
}
.qr-frame svg.brackets { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
.qr-kelas-item-besar { background: #fff; padding: 10px; border-radius: 10px; }
.qr-kelas-item-besar img { display: block; }

.kelas-besar {
  color: var(--siap-blue); font-weight: 800; font-size: 34px; letter-spacing: 3px;
  margin-top: 44px; font-family: Arial, Helvetica, sans-serif;
}
.website-line {
  color: #fff; font-weight: 700; font-size: 17px; margin-top: 10px;
  display: flex; align-items: center; gap: 8px; margin-bottom: 40px;
}

/* ===== MODE GRID: 9 kartu kecil (3x3) ukuran 6x9 cm per halaman A4 ===== */
/* Dipakai untuk "Cetak Semua Jenjang" & "Cetak Semua Kelas", supaya hemat
   kertas dibanding 1 kelas = 1 halaman penuh. Format visual (badge sekolah,
   SCAN HERE, bingkai kuning, label kelas) sama persis dengan poster besar,
   hanya diperkecil proporsional agar muat di kartu 6x9 cm. */
.mini-page {
  width: 21cm;
  margin: 0 auto 16px;
  background: #fff;
  padding: 0.5cm;
  box-sizing: border-box;
}
.mini-grid {
  display: grid;
  grid-template-columns: repeat(3, 6cm);
  grid-auto-rows: 9cm;
  justify-content: center;
  gap: 0.4cm;
}
.mini-card {
  width: 6cm;
  height: 9cm;
  border: 1px solid var(--siap-line);
  border-radius: 0.25cm;
  background: #fff;
  box-sizing: border-box;
  padding: 0.225cm 0.15cm;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  break-inside: avoid;
  page-break-inside: avoid;
}
.mini-pill {
  background: var(--siap-blue); color: #fff; font-weight: 700; font-size: 9px;
  padding: 3px 12px; border-radius: 20px; white-space: nowrap;
  max-width: 5.4cm; overflow: hidden; text-overflow: ellipsis;
}
.mini-logo { width: 0.98cm; height: 0.98cm; object-fit: contain; margin-top: 0.15cm; }
.mini-scan-here {
  font-family: 'Baloo2Poster', Arial, sans-serif;
  font-weight: 800; font-size: 21px; color: var(--siap-blue);
  letter-spacing: .75px; margin-top: 0.18cm; line-height: 1;
}
.mini-qr-frame {
  position: relative;
  width: 4.05cm; height: 4.05cm;
  background: var(--siap-warning-bg);
  border-radius: 0.45cm;
  margin-top: 0.18cm;
  display: flex; align-items: center; justify-content: center;
}
.mini-qr-frame svg.brackets { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
.qr-kelas-item-mini { background: #fff; padding: 5px; border-radius: 6px; line-height: 0; }
.qr-kelas-item-mini img { display: block; width: 3.15cm; height: 3.15cm; }
.mini-kelas {
  color: var(--siap-blue); font-weight: 800; font-size: 18px; letter-spacing: 1.5px;
  margin-top: 0.225cm; font-family: Arial, Helvetica, sans-serif;
}

@media print {
  @page { size: A4; margin: 0.5cm; }
  .mini-page { width: auto; margin: 0; padding: 0; }
  .mini-card { border-color: #eee; }
}

@media print {
  body { background: #fff; }
  .toolbar, .no-print { display: none !important; }
  .poster { box-shadow: none; margin: 0 auto; }

  /* Paksa browser tetap mencetak warna & elemen grafis (background kuning
     bingkai QR, badge nama sekolah, bracket SVG, dsb) persis seperti di
     layar. Tanpa ini, sebagian browser menghilangkan warna/graphic saat
     mencetak kecuali opsi "Print backgrounds/graphics" dicentang manual. */
  * {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    color-adjust: exact !important;
  }
}
</style>
</head>
<body>

<div class="toolbar d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div class="d-flex gap-2 flex-wrap">
    <form method="get" class="d-flex gap-2"><?= hidden_modul() ?>
      <select name="jenjang" class="siap-input siap-input-ios17 form-select-sm" onchange="this.form.submit()">
        <option value="">-- Per Jenjang --</option>
        <?php foreach (['X', 'XI', 'XII', 'Lainnya'] as $j): ?>
          <option value="<?= e($j) ?>" <?= $jenjangFilter === $j ? 'selected' : '' ?>>Jenjang <?= e($j) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <form method="get" class="d-flex gap-2"><?= hidden_modul() ?>
      <select name="kelas" class="siap-input siap-input-ios17 form-select-sm" onchange="this.form.submit()">
        <option value="">-- Cetak Semua Kelas --</option>
        <?php foreach ($kelasList as $k): ?>
          <option value="<?= e($k) ?>" <?= $kelasFilter === $k ? 'selected' : '' ?>>Kelas <?= e($k) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <a href="<?= modul_url('qr_kelas') ?>" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs btn-sm">Kembali</a>
  </div>
  <button class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Cetak</button>
</div>

<?php if ($mode === 'grid' && !empty($dataKelas)): ?>
  <p class="text-center text-muted small mb-3 no-print">
    <?= count($dataKelas) ?> kartu QR &middot; ukuran kartu 6 &times; 9 cm &middot; 9 kartu/halaman A4 (susunan 3&times;3) &middot; otomatis lanjut ke halaman berikutnya jika tidak muat
  </p>
<?php endif; ?>

<?php if ($mode === 'single'): ?>
  <?php foreach ($dataKelas as $d): ?>
    <div class="poster">
      <div class="poster-content">
        <div class="header-row">
          <div class="dots-grid"><?php for ($i=0;$i<10;$i++) echo '<span></span>'; ?></div>
          <div class="pill-badge"><?= e($settings['nama_sekolah'] ?? 'SEKOLAH') ?></div>
          <div class="dots-grid"><?php for ($i=0;$i<10;$i++) echo '<span></span>'; ?></div>
        </div>

        <?php if ($logoFile): ?>
          <img class="logo-besar" src="<?= BASE_URL ?>/uploads/siswa/<?= e($logoFile) ?>" alt="Logo">
        <?php else: ?>
          <div class="logo-kosong-besar"></div>
        <?php endif; ?>

        <div class="scan-here">SCAN HERE</div>

        <div class="qr-frame">
          <svg class="brackets" viewBox="0 0 460 460">
            <g stroke="#1f8f94" stroke-width="22" stroke-linecap="round" stroke-linejoin="round" fill="none">
              <path d="M55,105 L55,60 Q55,55 60,55 L105,55"/>
              <path d="M355,55 L400,55 Q405,55 405,60 L405,105"/>
              <path d="M55,355 L55,400 Q55,405 60,405 L105,405"/>
              <path d="M405,355 L405,400 Q405,405 400,405 L355,405"/>
            </g>
          </svg>
          <div class="qr-kelas-item-besar" data-content="<?= e(BASE_URL . '/presensi/?gate=' . $d['token']) ?>"></div>
        </div>

        <div class="kelas-besar">KELAS <?= e(strtoupper($d['kelas'])) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
<?php elseif ($mode === 'grid'): ?>
  <div class="mini-page">
    <div class="mini-grid">
      <?php foreach ($dataKelas as $d): ?>
        <div class="mini-card">
          <div class="mini-pill"><?= e($settings['nama_sekolah'] ?? 'SEKOLAH') ?></div>

          <?php if ($logoFile): ?>
            <img class="mini-logo" src="<?= BASE_URL ?>/uploads/siswa/<?= e($logoFile) ?>" alt="Logo">
          <?php endif; ?>

          <div class="mini-scan-here">SCAN HERE</div>

          <div class="mini-qr-frame">
            <svg class="brackets" viewBox="0 0 460 460">
              <g stroke="#1f8f94" stroke-width="26" stroke-linecap="round" stroke-linejoin="round" fill="none">
                <path d="M55,105 L55,60 Q55,55 60,55 L105,55"/>
                <path d="M355,55 L400,55 Q405,55 405,60 L405,105"/>
                <path d="M55,355 L55,400 Q55,405 60,405 L105,405"/>
                <path d="M405,355 L405,400 Q405,405 400,405 L355,405"/>
              </g>
            </svg>
            <div class="qr-kelas-item-mini" data-content="<?= e(BASE_URL . '/presensi/?gate=' . $d['token']) ?>"></div>
          </div>

          <div class="mini-kelas">KELAS <?= e(strtoupper($d['kelas'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if (empty($dataKelas)): ?>
  <p class="text-center text-muted">Tidak ada kelas untuk dicetak.</p>
<?php endif; ?>

<script src="<?= BASE_URL ?>/assets/js/vendor/qrcode.js"></script>
<script>
document.querySelectorAll('.qr-kelas-item-besar').forEach(function (holder) {
  const qr = qrcode(0, 'M');
  qr.addData(holder.dataset.content);
  qr.make();
  holder.innerHTML = qr.createImgTag(7, 0);
});
document.querySelectorAll('.qr-kelas-item-mini').forEach(function (holder) {
  const qr = qrcode(0, 'M');
  qr.addData(holder.dataset.content);
  qr.make();
  // cellSize disesuaikan utk kartu 6x9 cm, lalu discale via CSS
  // (width/height di .qr-kelas-item-mini img) supaya tetap tajam saat dicetak.
  holder.innerHTML = qr.createImgTag(4, 0);
});

<?php if (!empty($dataKelas)): ?>
// Langsung tampilkan dialog cetak begitu halaman ini dibuka (tidak perlu
// klik tombol "Cetak" lagi) — beri jeda sedikit supaya browser sempat
// menggambar semua QR terlebih dahulu sebelum print dialog muncul.
setTimeout(function () { window.print(); }, 300);
<?php endif; ?>
</script>
<?= script_log_cetak() ?>
</body>
</html>