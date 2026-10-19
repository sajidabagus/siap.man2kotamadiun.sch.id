<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/qr_dinamis.php';

$db = getDB();
$kelas = clean($_GET['kelas'] ?? '');

// --- MODE TAMPILKAN QR: kelas sudah dipilih ---
if ($kelas !== '') {

    // Aksi regenerate HANYA dari klik tombol admin (POST + CSRF), tidak pernah otomatis.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate'])) {
        csrf_require();
        if (!can('qr_kelas.generate')) {
            set_flash('danger', 'Anda tidak memiliki izin untuk regenerate QR Kelas.');
            header('Location: ' . modul_url('qr_kelas', ['kelas' => $kelas]));
            exit;
        }
        regenerate_qr_kelas($kelas);
        $admin = current_admin();
        catat_aktivitas('GENERATE', 'qr_kelas', "Regenerate QR kelas $kelas");
        header('Location: ' . modul_url('qr_kelas', ['kelas' => $kelas]));
        exit;
    }

    $settings = get_settings();
    $qr = get_or_create_qr_kelas($kelas);
    $csrfToken = csrf_token(); // butuh session, sudah aktif krn require_login()
    $logoFile = !empty($settings['logo']) ? $settings['logo'] : null;
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
    <meta charset="UTF-8">
    <title>QR Kelas <?= e($kelas) ?></title>
<?= favicon_tag() ?>
    <link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap.min.css?v=<?= @filemtime(__DIR__ . '/../assets/css/vendor/bootstrap.min.css') ?: time() ?>" rel="stylesheet">
    <style>
      @font-face {
        font-family: 'Baloo2Poster';
        font-weight: 800;
        src: url('<?= BASE_URL ?>/assets/css/vendor/fonts/fonts-baloo/baloo-2-latin-800-normal.woff2') format('woff2'),
             url('<?= BASE_URL ?>/assets/css/vendor/fonts/fonts-baloo/baloo-2-latin-800-normal.woff') format('woff');
        font-display: swap;
      }

      body { background:var(--siap-bg); color:var(--siap-ink); min-height:100vh; display:flex; align-items:center; justify-content:center; padding: 24px; }
      .box { text-align:center; max-width: 420px; }
      #qrHolder { background:rgba(255,255,255,0.9); padding:24px; border-radius:var(--siap-radius-window); box-shadow:var(--siap-shadow-card); display:inline-flex; border:1px solid var(--siap-line); }
      .badge-kelas { font-size: 1.1rem; padding: 8px 18px; }

      /* ===== Area cetak (poster, format sama dgn versi cetak lama) =====
         Tersembunyi selagi di layar, HANYA ditampilkan saat print dipicu,
         supaya klik "Cetak Kartu Ini" tetap di halaman ini (tidak pindah
         halaman/tab), sesuai format kartu yang sudah ada. */
      .area-cetak { display: none; }
      .poster {
        position: relative; width: 700px; height: 990px; margin: 0 auto;
        background: #fff; overflow: hidden; border-radius: 6px;
      }
      .poster-content { position: relative; height: 100%; display: flex; flex-direction: column; align-items: center; }
      .header-row { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 46px; }
      .dots-grid { display: flex; flex-wrap: wrap; gap: 8px; width: 82px; }
      .dots-grid span { width: 10px; height: 10px; border-radius: 50%; background: #1f8f94; display: block; }
      .pill-badge { background: #1f8f94; color: #fff; font-weight: 700; font-size: 22px; padding: 14px 34px; border-radius: 40px; white-space: nowrap; }
      .logo-besar { width: 150px; height: 150px; object-fit: contain; margin-top: 26px; }
      .logo-kosong-besar { width: 150px; height: 150px; margin-top: 26px; }
      .scan-here {
        font-family: 'Baloo2Poster', Arial, sans-serif; font-weight: 800; font-size: 76px; color: #2a9297;
        text-shadow: 4px 5px 0 rgba(160,175,195,.55); letter-spacing: 2px; margin-top: 14px; line-height: 1;
      }
      .qr-frame {
        position: relative; width: 460px; height: 460px; background: #fbdb57; border-radius: 44px;
        margin-top: 18px; display: flex; align-items: center; justify-content: center;
      }
      .qr-frame svg.brackets { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
      .qr-kelas-item-besar { background: #fff; padding: 10px; border-radius: 10px; }
      .qr-kelas-item-besar img { display: block; }
      .kelas-besar { color: #2a9297; font-weight: 800; font-size: 34px; letter-spacing: 3px; margin-top: 44px; margin-bottom: 40px; font-family: Arial, Helvetica, sans-serif; }

      @media print {
        @page { size: A4 portrait; margin: 0.5cm; }
        body { background: #fff; }
        body > *:not(.area-cetak) { display: none !important; }
        .area-cetak { display: block !important; }
        * {
          -webkit-print-color-adjust: exact !important;
          print-color-adjust: exact !important;
          color-adjust: exact !important;
        }
      }
    </style>
    </head>
    <body>
      <div class="box">
        <h2><?= e($settings['nama_sekolah'] ?? '') ?></h2>
        <p class="mb-2"><span class="siap-badge siap-badge-info badge-kelas">Kelas <?= e($kelas) ?></span></p>
        <p class="mb-1 fs-5">Scan QR ini dengan perangkat Anda untuk presensi di kelas <?= e($kelas) ?></p>
        <div id="qrHolder" class="my-3 d-flex justify-content-center"></div>
        <p class="text-muted small mb-3">QR permanen untuk kelas ini — hanya berlaku bagi siswa kelas <?= e($kelas) ?>, aman dicetak dan ditempel.</p>

        <div class="d-flex gap-2 justify-content-center mt-3 flex-wrap">
          <a href="<?= modul_url('qr_kelas') ?>" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs btn-sm">Ganti Kelas</a>
          <?php if (can('qr_kelas.cetak')): ?><button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Cetak Kartu Ini</button><?php endif; ?>
          <?php if (can('qr_kelas.generate')): ?><button type="button" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs btn-sm" data-bs-toggle="modal" data-bs-target="#modalRegen" style="background:var(--siap-danger-text);border:none">
            <i class="bi bi-arrow-repeat"></i> Regenerate QR Ini
          </button><?php endif; ?>
        </div>
        <p class="text-muted small mt-2">Dibuat: <?= e($qr['dibuat_at']) ?></p>
      </div>

      <!-- Modal konfirmasi regenerate: sengaja pakai konfirmasi ganda supaya tidak ke-klik tidak sengaja,
           krn regenerate membuat QR lama (yg mungkin sudah dicetak/ditempel) langsung tidak berlaku. -->
      <div class="modal fade" id="modalRegen" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content text-dark">
            <form method="post">
              <?= csrf_field() ?>
              <div class="modal-header">
                <h6 class="modal-title">Regenerate QR Kelas <?= e($kelas) ?>?</h6>
              </div>
              <div class="modal-body">
                <p class="mb-0">QR yang lama (termasuk yang mungkin sudah dicetak/ditempel di kelas) akan
                <strong>langsung tidak berlaku</strong> setelah ini. Pastikan Anda akan mengganti QR fisiknya juga.</p>
              </div>
              <div class="modal-footer">
                <button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" data-bs-dismiss="modal">Batal</button>
                <button type="submit" name="regenerate" value="1" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs" style="background:var(--siap-danger-text);border:none">Ya, Regenerate</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Area cetak: format poster sama persis dgn cetak sebelumnya, hanya
           dimunculkan (display:block) khusus saat proses print (lihat CSS). -->
      <div class="area-cetak">
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
              <div id="qrHolderPoster" class="qr-kelas-item-besar"></div>
            </div>
            <div class="kelas-besar">KELAS <?= e(strtoupper($kelas)) ?></div>
          </div>
        </div>
      </div>

    <script src="<?= BASE_URL ?>/assets/js/vendor/bootstrap.bundle.min.js?v=<?= @filemtime(__DIR__ . '/../assets/js/vendor/bootstrap.bundle.min.js') ?: time() ?>"></script>
    <script src="<?= BASE_URL ?>/assets/js/vendor/qrcode.js"></script>
    <script>
    const content = <?= json_encode(BASE_URL . '/presensi/?gate=' . $qr['token']) ?>;
    const qr = qrcode(0, 'M');
    qr.addData(content);
    qr.make();
    document.getElementById('qrHolder').innerHTML = qr.createImgTag(7, 8);
    document.getElementById('qrHolderPoster').innerHTML = qr.createImgTag(7, 0);
    </script>
    </body>
    </html>
    <?php
    exit;
}

// --- MODE PILIH KELAS: tampilkan dgn layout admin biasa (sidebar) ---
require_once __DIR__ . '/../includes/header.php';

$logoFile = !empty($settings['logo']) ? $settings['logo'] : null;
$kelasList = $db->query('SELECT DISTINCT kelas FROM siswa ORDER BY kelas')->fetchAll(PDO::FETCH_COLUMN);
$perJenjang = kelompokkan_per_jenjang($kelasList);

// D8: satu kueri untuk SELURUH kelas (dulu get_or_create_qr_kelas() per kelas
// = N+1; 30 kelas berarti 30+ kueri tiap halaman dibuka). Ambil token AKTIF
// terbaru per kelas sekaligus (ORDER BY id DESC + ambil pertama = identik
// dengan ORDER BY id DESC LIMIT 1 milik get_or_create). Kelas yang benar-benar
// belum punya QR tetap dibuatkan lewat fallback per kelas (hanya bila kosong).
$tokenPerKelas = [];
$stKelas = $db->query('SELECT kelas_target, token FROM qr_dinamis
                       WHERE kelas_target IS NOT NULL AND status = "AKTIF" AND kadaluarsa_at >= NOW()
                       ORDER BY id DESC');
foreach ($stKelas->fetchAll() as $row) {
    if (!isset($tokenPerKelas[$row['kelas_target']])) $tokenPerKelas[$row['kelas_target']] = $row['token'];
}
foreach ($kelasList as $k) {
    if (!isset($tokenPerKelas[$k])) $tokenPerKelas[$k] = get_or_create_qr_kelas($k)['token'];
}
?>
<div class="siap-eyebrow siap-stack-sm">QR Code</div>
<h2 class="siap-h2 mb-3"><i class="bi bi-easel2"></i> QR Kelas</h2>

<div class="konten-biasa">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    <?php if (can('qr_kelas.cetak')): ?><button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" onclick="cetakKelasGrid(null)"><i class="bi bi-printer"></i> Cetak Semua QR Kelas</button><?php endif; ?>
  </div>

  <?php if (empty($kelasList)): ?>
    <div class="ps-card"><div class="ps-body text-center">
      <p class="siap-copy-sm mb-0">Belum ada data kelas. Tambahkan siswa terlebih dahulu di menu Data Siswa.</p>
    </div></div>
  <?php else: ?>
    <?php foreach ($perJenjang as $jenjang => $daftarKelasJenjang): ?>
      <div class="tbl-card mb-3">
        <div class="tbl-head d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <h6>Jenjang <?= e($jenjang) ?></h6>
            <div class="sub"><?= count($daftarKelasJenjang) ?> kelas</div>
          </div>
          <?php if (can('qr_kelas.cetak')): ?><button type="button" class="siap-btn siap-btn-ghost siap-mono-xs btn-sm" onclick="cetakKelasGrid('<?= e($jenjang) ?>')">
            <i class="bi bi-printer"></i> Cetak Semua Jenjang <?= e($jenjang) ?>
          </button><?php endif; ?>
        </div>
        <div class="p-3">
          <div class="row g-3 kelas-grid-5">
            <?php foreach ($daftarKelasJenjang as $k): ?>
              <div class="col-kelas">
                <a href="<?= e(modul_url('qr_kelas', ['kelas' => $k])) ?>" class="text-decoration-none">
                  <div class="card kelas-card h-100 text-center">
                    <div class="card-body">
                      <div class="qr-thumb mx-auto mb-2" data-content="<?= e(BASE_URL . '/presensi/?gate=' . $tokenPerKelas[$k]) ?>"></div>
                      <h6 class="mb-0 text-dark">Kelas <?= e($k) ?></h6>
                      <small class="text-muted">Lihat<?= can('qr_kelas.cetak') ? ' &amp; Cetak' : '' ?> QR</small>
                    </div>
                  </div>
                </a>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ===== Area cetak (tersembunyi di layar) ==========================
     Berisi kartu QR 6x9 cm utk SEMUA kelas sekaligus (format sama persis
     dgn versi cetak sebelumnya). Tombol "Cetak Semua ..." di atas hanya
     menyaring kartu mana yang ditampilkan (semua / per jenjang) lalu
     memanggil window.print() -- TIDAK pindah halaman, menu/sidebar tetap
     ada, cuma disembunyikan otomatis khusus selagi proses print (lihat
     CSS @media print di bawah), persis seperti tombol Print di halaman
     Data Presensi. ================================================== -->
<div id="areaCetakKelas" class="cetak-area">
  <div class="halaman-a4">
    <div class="mini-grid">
      <?php foreach ($kelasList as $k): ?>
        <div class="mini-card" data-jenjang="<?= e(ambil_jenjang($k)) ?>">
          <div class="mini-pill"><?= e($settings['nama_sekolah'] ?? 'SEKOLAH') ?></div>
          <?php if ($logoFile): ?>
            <img class="mini-logo" src="<?= BASE_URL ?>/uploads/siswa/<?= e($logoFile) ?>" alt="Logo">
          <?php endif; ?>
          <div class="mini-scan-here">SCAN HERE</div>
          <div class="mini-qr-frame">
            <svg class="brackets" viewBox="0 0 460 460">
              <g stroke="#1f8f94" stroke-width="26" stroke-linecap="round" stroke-linejoin="round" fill="none">
               
              </g>
            </svg>
            <div class="qr-kelas-item-mini" data-content="<?= e(BASE_URL . '/presensi/?gate=' . $tokenPerKelas[$k]) ?>"></div>
          </div>
          <div class="mini-kelas">KELAS <?= e(strtoupper($k)) ?></div>
        </div>
      <?php endforeach; ?>
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

.kelas-grid-5 { --kelas-col: 5; }
.col-kelas { width: calc(100% / var(--kelas-col)); }
@media (max-width: 992px) { .col-kelas { width: calc(100% / 3); } }
@media (max-width: 768px) { .col-kelas { width: calc(100% / 2); } }
.kelas-card {
  border-radius: var(--siap-radius-window);
  border: 1px solid var(--siap-line);
  transition: box-shadow .15s ease, transform .15s ease;
}
.kelas-card:hover {
  box-shadow: 0 8px 20px rgba(0,0,0,.08);
  transform: translateY(-3px);
  border-color: #1f8f94;
}
.qr-thumb {
  width: 74px; height: 74px; border-radius: var(--siap-radius-mini);
  background: #fff; border: 1px solid #eef0f2;
  display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.qr-thumb img { width: 100%; height: 100%; object-fit: contain; }

/* ===== Format kartu cetak 6x9 cm (identik dgn versi cetak sebelumnya) === */
.cetak-area { display: none; }
.halaman-a4 { width: 21cm; margin: 0 auto; background: #fff; padding: 0.5cm; box-sizing: border-box; }
.mini-grid { display: grid; grid-template-columns: repeat(3, 6cm); grid-auto-rows: 9cm; justify-content: center; gap: 0.4cm; }
.mini-card {
  width: 6cm; height: 9cm; border: 1px solid #dfe3e7; border-radius: 0.25cm; background: #fff;
  box-sizing: border-box; padding: 0.225cm 0.15cm; display: flex; flex-direction: column; align-items: center; justify-content: center;
  break-inside: avoid; page-break-inside: avoid;
}
.mini-pill {
  background: #1f8f94; color: #fff; font-weight: 700; font-size: 9px; padding: 3px 12px; border-radius: 20px;
  white-space: nowrap; max-width: 5.4cm; overflow: hidden; text-overflow: ellipsis;
}
.mini-logo { width: 0.98cm; height: 0.98cm; object-fit: contain; margin-top: 0.15cm; }
.mini-scan-here {
  font-family: 'Baloo2Poster', Arial, sans-serif; font-weight: 800; font-size: 21px; color: #2a9297;
  letter-spacing: .75px; margin-top: 0.18cm; line-height: 1;
}
.mini-qr-frame {
  position: relative; width: 4.05cm; height: 4.05cm; background: #fbdb57; border-radius: 0.45cm;
  margin-top: 0.18cm; display: flex; align-items: center; justify-content: center;
}
.mini-qr-frame svg.brackets { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
.qr-kelas-item-mini { background: #fff; padding: 5px; border-radius: 6px; line-height: 0; }
.qr-kelas-item-mini img { display: block; width: 3.15cm; height: 3.15cm; }
.mini-kelas { color: #2a9297; font-weight: 800; font-size: 18px; letter-spacing: 1.5px; margin-top: 0.225cm; font-family: Arial, Helvetica, sans-serif; }

@media print {
  @page { size: A4 portrait; margin: 0.5cm; }
  body { background: #fff; }

  /* Sembunyikan sidebar/topbar/konten browse SELAMA proses print saja --
     di layar semua tetap tampil normal seperti biasa, menu tidak hilang. */
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
document.querySelectorAll('.qr-thumb').forEach(function (holder) {
  const qr = qrcode(0, 'M');
  qr.addData(holder.dataset.content);
  qr.make();
  holder.innerHTML = qr.createImgTag(2, 0); // kecil, cukup utk thumbnail kartu
});

document.querySelectorAll('#areaCetakKelas .qr-kelas-item-mini').forEach(function (holder) {
  const qr = qrcode(0, 'M');
  qr.addData(holder.dataset.content);
  qr.make();
  holder.innerHTML = qr.createImgTag(4, 0);
});

// Menyaring kartu mana yang ikut tercetak (semua / per jenjang), lalu
// langsung memicu dialog print di halaman ini juga -- TIDAK ada
// perpindahan halaman/tab sama sekali.
function cetakKelasGrid(jenjang) {
  document.querySelectorAll('#areaCetakKelas .mini-card').forEach(function (el) {
    el.style.display = (!jenjang || el.dataset.jenjang === jenjang) ? '' : 'none';
  });
  window.print();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>