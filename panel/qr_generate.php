<?php
require_once __DIR__ . '/../includes/header.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

if (($_SERVER['REQUEST_METHOD'] === 'POST') && isset($_POST['regenerate'])) {
  csrf_require();
  if (!can('qr_siswa.generate')) {
    set_flash('danger', 'Anda tidak memiliki izin untuk regenerate QR Siswa.');
    header('Location: ' . modul_url('qr_generate', ['id' => (int) ($_GET['id'] ?? 0)]));
    exit;
  }
  do {
    $newToken = generate_token(32);
    $cek = $db->prepare('SELECT id FROM siswa WHERE qr_token = :t');
    $cek->execute([':t' => $newToken]);
  } while ($cek->fetch());

  $upd = $db->prepare('UPDATE siswa SET qr_token = :t, updated_at = NOW() WHERE id = :id');
  $upd->execute([':t' => $newToken, ':id' => $id]);
  catat_aktivitas('GENERATE', 'qr_generate', 'Regenerate QR siswa ' . nama_siswa_log($id));
  set_flash('success', 'QR Code berhasil di-regenerate. QR Code lama tidak berlaku lagi.');
  header('Location: ' . modul_url('qr_generate', ['id' => $id]));
  exit;
}

$stmt = $db->prepare('SELECT * FROM siswa WHERE id = :id');
$stmt->execute([':id' => $id]);
$siswa = $stmt->fetch();

if (!$siswa) {
  set_flash('danger', 'Siswa tidak ditemukan.');
  header('Location: ' . modul_url('siswa'));
  exit;
}

// Isi QR Code HANYA berupa URL + token — bukan data pribadi siswa.
$qrContent = BASE_URL . '/presensi/scan.php?token=' . $siswa['qr_token'];
$logoFile = !empty($settings['logo']) ? $settings['logo'] : null;
?>
<div class="konten-biasa">
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-qr-code"></i> QR Code Siswa</h4>
  <a href="<?= modul_url('siswa') ?>" class="siap-btn siap-btn-ghost siap-mono-xs"><i class="bi bi-arrow-left"></i> Kembali ke Data Siswa</a>
</div>

<div class="row">
  <div class="col-md-5">
    <div class="card text-center">
      <div class="card-body">
        <div id="qrHolder" class="mb-3 d-flex justify-content-center"></div>
        <h5 class="mb-1"><?= e($siswa['nama']) ?></h5>
        <p class="text-muted mb-2">NIS: <?= e($siswa['nis']) ?> — Kelas <?= e($siswa['kelas']) ?></p>
        <span class="siap-badge siap-badge-<?= $siswa['status'] === 'aktif' ? 'success' : 'info' ?> siap-mono-xs"><?= e($siswa['status']) ?></span>

        <div class="d-flex gap-2 justify-content-center mt-3">
          <button class="siap-btn siap-btn-ghost siap-mono-xs" id="btnDownload"><i class="bi bi-download"></i> Download Gambar</button>
          <?php if (can('qr_siswa.cetak')): ?><button type="button" class="siap-btn siap-btn-ghost siap-mono-xs" onclick="window.print()"><i class="bi bi-printer"></i> Cetak Kartu</button><?php endif; ?>
        </div>
        <?php if (can('qr_siswa.generate')): ?><form method="post" class="mt-3" onsubmit="return confirm('Yakin? QR Code lama tidak akan berlaku lagi.')">
          <?= csrf_field() ?>
          <button type="submit" name="regenerate" value="1" class="siap-btn siap-btn-danger siap-mono-xs btn-sm">
            <i class="bi bi-arrow-repeat"></i> Regenerate QR Code
          </button>
        </form><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-7">
    <div class="card">
      <div class="card-body">
        <h6>Catatan Keamanan</h6>
        <ul class="small text-muted mb-0">
          <li>QR Code ini hanya berisi <em>token acak</em>, bukan NIS/data pribadi siswa secara langsung.</li>
          <li>Jika QR Code hilang/disalahgunakan, gunakan tombol <strong>Regenerate</strong> agar token lama otomatis tidak berlaku.</li>
          <li>Presensi tetap memerlukan perangkat yang sudah terdaftar (device binding) — kepemilikan gambar QR saja tidak cukup untuk presensi dari perangkat lain.</li>
        </ul>
      </div>
    </div>
  </div>
</div>
</div>

<!-- ===== Area cetak (tersembunyi di layar) =============================
     1 kartu QR 6x9 cm utk siswa ini (format sama persis dgn kartu massal).
     Tombol "Cetak Kartu" di atas memanggil window.print() di halaman ini
     juga -- TIDAK pindah halaman, menu/sidebar tetap ada. ================ -->
<div class="cetak-area">
  <div class="halaman-a4">
    <div class="mini-grid">
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
          <div id="qrHolderMini" class="qr-item-mini"></div>
        </div>
        <div class="mini-nama"><?= e($siswa['nama']) ?></div>
        <div class="mini-detail">NIS: <?= e($siswa['nis']) ?> &middot; Kelas <?= e($siswa['kelas']) ?></div>
      </div>
    </div>
  </div>
</div>

<style>
@font-face {
  font-family: 'Baloo2Poster';
  font-weight: 800;
  src: url('<?= BASE_URL ?>/assets/css/vendor/fonts/fonts-baloo/baloo-2-latin-800-normal.woff2') format('woff2'),
       url('<?= BASE_URL ?>/assets/css/vendor/fonts/fonts-baloo/baloo-2-latin-800-normal.woff') format('woff');
  font-display: swap;
}
.cetak-area { display: none; }
.halaman-a4 { width: 21cm; margin: 0 auto; background: #fff; padding: 0.5cm; box-sizing: border-box; }
.mini-grid { display: grid; grid-template-columns: repeat(3, 6cm); grid-auto-rows: 9cm; justify-content: center; gap: 0.4cm; }
.mini-card {
  width: 6cm; height: 9cm; border: 1px solid #dfe3e7; border-radius: 0.25cm; background: #fff;
  box-sizing: border-box; padding: 0.2cm 0.15cm; display: flex; flex-direction: column; align-items: center; justify-content: center;
  break-inside: avoid; page-break-inside: avoid;
}
.mini-pill {
  background: #1f8f94; color: #fff; font-weight: 700; font-size: 9px; padding: 3px 12px; border-radius: 20px;
  white-space: nowrap; max-width: 5.4cm; overflow: hidden; text-overflow: ellipsis;
}
.mini-logo { width: 0.9cm; height: 0.9cm; object-fit: contain; margin-top: 0.12cm; }
.mini-scan-here {
  font-family: 'Baloo2Poster', Arial, sans-serif; font-weight: 800; font-size: 18px; color: #2a9297;
  letter-spacing: .5px; margin-top: 0.15cm; line-height: 1;
}
.mini-qr-frame {
  position: relative; width: 3.9cm; height: 3.9cm; background: #fbdb57; border-radius: 0.45cm;
  margin-top: 0.15cm; display: flex; align-items: center; justify-content: center;
}
.mini-qr-frame svg.brackets { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
.qr-item-mini { background: #fff; padding: 5px; border-radius: 6px; line-height: 0; }
.qr-item-mini img { display: block; width: 3cm; height: 3cm; }
.mini-nama {
  color: #2a9297; font-weight: 800; font-size: 12px; letter-spacing: .3px; margin-top: 0.2cm; line-height: 1.15;
  text-align: center; max-width: 5.6cm; overflow: hidden; text-overflow: ellipsis;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
.mini-detail { color: #888; font-size: 9px; margin-top: 0.08cm; text-align: center; }

@media print {
  @page { size: A4 portrait; margin: 0.5cm; }
  body { background: #fff; }
  .sidebar, .topbar, .konten-biasa, .no-print { display: none !important; }
  .content { margin: 0 !important; padding: 0 !important; }
  .cetak-area { display: block !important; }
  .halaman-a4 { width: auto; margin: 0; padding: 0; }
  * {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    color-adjust: exact !important;
  }
}
</style>

<script src="<?= BASE_URL ?>/assets/js/vendor/qrcode.js"></script>
<script>
  const qrContent = <?= json_encode($qrContent) ?>;

  // Library "qrcode-generator" (vendored lokal, tidak bergantung CDN eksternal).
  const qr = qrcode(0, 'M'); // typeNumber 0 = auto-detect ukuran, level koreksi M
  qr.addData(qrContent);
  qr.make();

  const qrHolder = document.getElementById('qrHolder');
  qrHolder.innerHTML = qr.createImgTag(6, 8); // cellSize=6px, margin=8
  document.getElementById('qrHolderMini').innerHTML = qr.createImgTag(4, 0);

  document.getElementById('btnDownload').addEventListener('click', function() {
    const link = document.createElement('a');
    link.download = 'qr_<?= e($siswa['nis']) ?>.gif';
    link.href = qr.createDataURL(6, 8);
    link.click();
  });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>