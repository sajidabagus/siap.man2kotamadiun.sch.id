<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$kelas = clean($_GET['kelas'] ?? '');
$jenjang = clean($_GET['jenjang'] ?? '');

if ($id) {
  $stmt = $db->prepare('SELECT * FROM siswa WHERE id = :id');
  $stmt->execute([':id' => $id]);
  $daftar = $stmt->fetchAll();
} elseif ($kelas !== '') {
  $stmt = $db->prepare('SELECT * FROM siswa WHERE kelas = :kelas AND status = "aktif" ORDER BY nama');
  $stmt->execute([':kelas' => $kelas]);
  $daftar = $stmt->fetchAll();
} elseif ($jenjang !== '') {
  $semuaAktif = $db->query('SELECT * FROM siswa WHERE status = "aktif" ORDER BY kelas, nama')->fetchAll();
  $daftar = array_values(array_filter($semuaAktif, fn($s) => ambil_jenjang($s['kelas']) === $jenjang));
} else {
  $daftar = $db->query('SELECT * FROM siswa WHERE status = "aktif" ORDER BY kelas, nama')->fetchAll();
}

$settings = get_settings();
$kelasList = $db->query('SELECT DISTINCT kelas FROM siswa ORDER BY kelas')->fetchAll(PDO::FETCH_COLUMN);
$logoFile = !empty($settings['logo']) ? $settings['logo'] : null;
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cetak Kartu QR Siswa</title>
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

    body {
      background: var(--siap-bg);
      font-family: Arial, Helvetica, sans-serif;
      color: var(--siap-ink);
    }

    /* Format kartu & halaman DISAMAKAN dgn menu QR Kelas: kertas A4, kartu
       6x9 cm tersusun 3x3 (9 kartu/halaman), badge sekolah, teks "SCAN HERE",
       bingkai QR kuning dgn bracket sudut, warna & font senada. */
    .halaman-a4 {
      width: 21cm;
      margin: 0 auto;
      background: #fff;
      padding: 0.5cm;
      box-sizing: border-box;
    }

    .kartu-grid {
      display: grid;
      grid-template-columns: repeat(3, 6cm);
      grid-auto-rows: 9cm;
      justify-content: center;
      gap: 0.4cm;
    }

    .kartu {
      width: 6cm;
      height: 9cm;
      border: 1px solid var(--siap-line);
      border-radius: 0.25cm;
      background: #fff;
      box-sizing: border-box;
      padding: 0.2cm 0.15cm;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      break-inside: avoid;
      page-break-inside: avoid;
    }

    .kartu-pill {
      background: var(--siap-blue);
      color: #fff;
      font-weight: 700;
      font-size: 9px;
      padding: 3px 12px;
      border-radius: 20px;
      white-space: nowrap;
      max-width: 5.4cm;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .kartu-logo {
      width: 0.9cm;
      height: 0.9cm;
      object-fit: contain;
      margin-top: 0.12cm;
    }

    .kartu-scan-here {
      font-family: 'Baloo2Poster', Arial, sans-serif;
      font-weight: 800;
      font-size: 18px;
      color: var(--siap-blue);
      letter-spacing: .5px;
      margin-top: 0.15cm;
      line-height: 1;
    }

    .kartu-qr-frame {
      position: relative;
      width: 3.9cm;
      height: 3.9cm;
      background: var(--siap-warning-bg);
      border-radius: 0.45cm;
      margin-top: 0.15cm;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .kartu-qr-frame svg.brackets {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      stroke: var(--siap-blue);
    }

    .qr-item {
      background: #fff;
      padding: 5px;
      border-radius: 6px;
      line-height: 0;
    }

    /* Ukuran QR DIKUNCI tetap sama utk semua siswa (fixed via CSS), supaya
       tidak berubah-ubah tergantung panjang token QR-nya masing-masing
       (token yang lebih panjang bisa menghasilkan QR dgn jumlah modul
       lebih banyak -> gambar mentahnya beda ukuran kalau tidak dikunci). */
    .qr-item img {
      display: block;
      width: 3cm;
      height: 3cm;
    }

    .kartu-nama {
      color: var(--siap-blue);
      font-weight: 800;
      font-size: 12px;
      letter-spacing: .3px;
      margin-top: 0.2cm;
      line-height: 1.15;
      text-align: center;
      max-width: 5.6cm;
      overflow: hidden;
      text-overflow: ellipsis;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
    }

    .kartu-detail {
      color: var(--siap-muted);
      font-size: 9px;
      margin-top: 0.08cm;
      text-align: center;
    }

    @media print {
      @page {
        size: A4 portrait;
        margin: 0.5cm;
      }

      .no-print {
        display: none !important;
      }

      body {
        background: #fff;
      }

      .halaman-a4 {
        width: auto;
        margin: 0;
        padding: 0;
      }

      * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
      }
    }
  </style>
</head>

<body>
  <div class="container py-4 no-print">
    <div class="d-flex justify-content-between mb-3">
      <form method="get" class="d-flex gap-2"><?= hidden_modul() ?>
        <select name="kelas" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">-- Cetak Massal per Kelas --</option>
          <?php foreach ($kelasList as $k): ?>
            <option value="<?= e($k) ?>" <?= $kelas === $k ? 'selected' : '' ?>><?= e($k) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <button class="siap-btn siap-btn-blue siap-mono-xs btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Cetak</button>
    </div>
    <p class="text-muted small mb-0">
      <?= count($daftar) ?> kartu QR siswa &middot; ukuran kartu 6 &times; 9 cm &middot; 9 kartu/halaman A4 Potrait (susunan 3&times;3) &middot; otomatis lanjut ke halaman berikutnya jika tidak muat
    </p>
  </div>

  <div class="halaman-a4">
    <div class="kartu-grid" id="kartuGrid">
      <?php foreach ($daftar as $i => $s): ?>
        <div class="kartu">
          <div class="kartu-pill"><?= e($settings['nama_sekolah'] ?? 'SEKOLAH') ?></div>

          <?php if ($logoFile): ?>
            <img class="kartu-logo" src="<?= BASE_URL ?>/uploads/siswa/<?= e($logoFile) ?>" alt="Logo">
          <?php endif; ?>

          <div class="kartu-scan-here">SCAN HERE</div>

          <div class="kartu-qr-frame">
            <svg class="brackets" viewBox="0 0 460 460">
              <g stroke="#1f8f94" stroke-width="26" stroke-linecap="round" stroke-linejoin="round" fill="none">
                <path d="M55,105 L55,60 Q55,55 60,55 L105,55" />
                <path d="M355,55 L400,55 Q405,55 405,60 L405,105" />
                <path d="M55,355 L55,400 Q55,405 60,405 L105,405" />
                <path d="M405,355 L405,400 Q405,405 400,405 L355,405" />
              </g>
            </svg>
            <div class="qr-item" data-content="<?= e(BASE_URL . '/presensi/scan.php?token=' . $s['qr_token']) ?>"></div>
          </div>

          <div class="kartu-nama"><?= e($s['nama']) ?></div>
          <div class="kartu-detail">NIS: <?= e($s['nis']) ?> &middot; Kelas <?= e($s['kelas']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (empty($daftar)): ?>
      <p class="text-center text-muted">Tidak ada siswa untuk dicetak.</p>
    <?php endif; ?>
  </div>

  <script src="<?= BASE_URL ?>/assets/js/vendor/qrcode.js"></script>
  <script>
    document.querySelectorAll('.qr-item').forEach(function(holder) {
      const qr = qrcode(0, 'M');
      qr.addData(holder.dataset.content);
      qr.make();
      // cellSize kecil (resolusi sumber cukup tinggi), ukuran tampil final
      // dikunci lewat CSS (.qr-item img) supaya seragam & tajam saat dicetak.
      holder.innerHTML = qr.createImgTag(4, 0);
    });

    <?php if (!empty($daftar)): ?>
    // Langsung tampilkan dialog cetak begitu halaman ini dibuka (tidak perlu
    // klik tombol "Cetak" lagi) — beri jeda sedikit supaya browser sempat
    // menggambar semua QR terlebih dahulu sebelum print dialog muncul.
    setTimeout(function () { window.print(); }, 300);
    <?php endif; ?>
  </script>
<?= script_log_cetak() ?>
</body>

</html>