<?php
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$logoFile = !empty($settings['logo']) ? $settings['logo'] : null;

// Hitung jumlah siswa aktif per kelas, supaya kartu kelas bisa menampilkan
// jumlah siswanya tanpa query berulang per kelas.
$jumlahPerKelas = $db->query(
    'SELECT kelas, COUNT(*) AS jumlah FROM siswa WHERE status = "aktif" GROUP BY kelas'
)->fetchAll(PDO::FETCH_KEY_PAIR);

$kelasList = array_keys($jumlahPerKelas);
sort($kelasList);
$perJenjang = kelompokkan_per_jenjang($kelasList);
$totalSiswa = array_sum($jumlahPerKelas);

// Data MINIMAL semua siswa aktif, dikirim sbg JSON ringan (BUKAN di-render
// jadi ratusan/ribuan elemen kartu HTML + QR code langsung saat halaman
// dibuka -- itu penyebab halaman ini kerasa lambat sebelumnya). Kartu &
// QR code baru benar-benar dibuat oleh JS, LAZY, hanya utk siswa yang
// tersaring, tepat saat admin klik salah satu tombol "Cetak ...".
$dataSiswaAktif = array_map(fn($s) => [
    'n' => $s['nama'], 'nis' => $s['nis'], 'k' => $s['kelas'],
    'j' => ambil_jenjang($s['kelas']), 't' => $s['qr_token'],
], $db->query('SELECT nama, nis, kelas, qr_token FROM siswa WHERE status = "aktif" ORDER BY kelas, nama')->fetchAll());
?>
<div class="konten-biasa" id="kontenBiasaSiswa">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-qr-code"></i> QR Siswa</h4>
    <?php if (can('qr_siswa.cetak')): ?><button type="button" class="siap-btn siap-btn-ghost siap-mono-xs" onclick="previewCetakSiswa(null, null)"><i class="bi bi-printer"></i> Cetak Semua Siswa</button><?php endif; ?>
  </div>

  <?php if (empty($kelasList)): ?>
    <div class="card"><div class="card-body">
      <p class="text-muted mb-0">Belum ada data siswa. Tambahkan siswa terlebih dahulu di menu Data Siswa.</p>
    </div></div>
  <?php else: ?>
    <?php foreach ($perJenjang as $jenjang => $daftarKelasJenjang): ?>
      <?php $jumlahSiswaJenjang = array_sum(array_map(fn($k) => $jumlahPerKelas[$k] ?? 0, $daftarKelasJenjang)); ?>
      <div class="tbl-card mb-3">
        <div class="tbl-head d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <h6>Jenjang <?= e($jenjang) ?></h6>
            <div class="sub"><?= count($daftarKelasJenjang) ?> kelas &middot; <?= $jumlahSiswaJenjang ?> siswa</div>
          </div>
          <?php if (can('qr_siswa.cetak')): ?><button type="button" class="siap-btn siap-btn-ghost siap-mono-xs btn-sm" onclick="previewCetakSiswa('jenjang', '<?= e($jenjang) ?>')">
            <i class="bi bi-printer"></i> Cetak Semua Jenjang <?= e($jenjang) ?>
          </button><?php endif; ?>
        </div>
        <div class="p-3">
          <div class="row g-3 kelas-grid-5">
            <?php foreach ($daftarKelasJenjang as $k): ?>
              <?php $bisaCetak = can('qr_siswa.cetak'); ?>
              <div class="col-kelas">
                <?php if ($bisaCetak): ?><a href="#" onclick="previewCetakSiswa('kelas', '<?= e($k) ?>'); return false;" class="text-decoration-none"><?php else: ?><div><?php endif; ?>
                  <div class="card kelas-card h-100 text-center">
                    <div class="card-body">
                      <div class="siswa-thumb mx-auto mb-2"><i class="bi bi-qr-code"></i></div>
                      <h6 class="mb-0 text-dark">Kelas <?= e($k) ?></h6>
                      <small class="text-muted"><?= (int) ($jumlahPerKelas[$k] ?? 0) ?> siswa<?= $bisaCetak ? ' &middot; Cetak QR' : '' ?></small>
                    </div>
                  </div>
                <?php if ($bisaCetak): ?></a><?php else: ?></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <p class="text-muted small">Total keseluruhan: <?= $totalSiswa ?> siswa aktif.</p>
  <?php endif; ?>
</div>

<!-- ===== Toolbar preview cetak (tersembunyi, muncul setelah pilih cetak) === -->
<div id="previewToolbarSiswa" class="preview-toolbar d-none no-print">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <strong><i class="bi bi-eye"></i> Preview cetak</strong>
      <span class="text-muted">— <span id="jumlahPreviewSiswa">0</span> kartu QR siswa akan dicetak (ukuran 6&times;9 cm)</span>
    </div>
    <div class="d-flex gap-2">
      <button type="button" class="siap-btn siap-btn-ghost siap-mono-xs btn-sm" onclick="tutupPreviewSiswa()">
        <i class="bi bi-arrow-left"></i> Kembali
      </button>
      <?php if (can('qr_siswa.cetak')): ?><button type="button" class="siap-btn siap-btn-blue siap-mono-xs btn-sm" onclick="window.print()">
        <i class="bi bi-printer"></i> Cetak Sekarang
      </button><?php endif; ?>
    </div>
  </div>
</div>

<!-- ===== Area cetak =====================================================
     Kartu QR 6x9 cm (format sama persis dgn versi sebelumnya: badge
     sekolah, SCAN HERE, bingkai kuning, nama+NIS+kelas) dibangun secara
     LAZY oleh JS -- HANYA utk siswa yang tersaring (semua/jenjang/kelas),
     PERSIS saat admin klik salah satu tombol "Cetak ...". Sebelumnya
     SEMUA siswa aktif langsung dirender + di-generate QR-nya saat halaman
     dibuka, itulah yang bikin halaman ini kerasa berat/lambat. ========= -->
<div id="areaCetakSiswa" class="cetak-area">
  <div class="halaman-a4">
    <div class="mini-grid" id="miniGridSiswa"></div>
  </div>
</div>

<!-- Data siswa (ringan, tanpa markup kartu) utk dipakai JS membangun kartu
     sesuai kebutuhan. type="application/json" supaya browser TIDAK
     mencoba mengeksekusinya sbg JS/HTML, cuma teks data biasa. -->
<script type="application/json" id="dataSiswaAktif"><?= json_encode($dataSiswaAktif, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

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
  border-radius: var(--siap-radius-card);
  border: 1px solid var(--siap-line);
  transition: box-shadow .15s ease, transform .15s ease;
}
.kelas-card:hover {
  box-shadow: 0 8px 20px rgba(0,0,0,.08);
  transform: translateY(-3px);
  border-color: var(--siap-blue);
}
.siswa-thumb {
  width: 74px; height: 74px; border-radius: 10px;
  background: #fff; border: 1px solid var(--siap-line);
  display: flex; align-items: center; justify-content: center; overflow: hidden;
  font-size: 28px; color: var(--siap-blue);
}

/* ===== Preview cetak di layar (SEBELUM klik "Cetak Sekarang") ========= */
.preview-toolbar {
  position: sticky; top: 0; z-index: 20; background: #fff3cd; border: 1px solid #ffe69c;
  border-radius: 10px; padding: 12px 16px; margin-bottom: 16px;
}
.cetak-area { display: none; }
/* Saat mode preview AKTIF (bukan lagi sedang print fisik), area cetak
   ditampilkan langsung di halaman dgn scroll, dibungkus tampilan abu-abu
   spy jelas ini "mode preview", supaya admin bisa cek dulu sebelum print
   sungguhan -- terutama krn data siswa bisa banyak sekali. */
.cetak-area.preview-aktif {
  display: block;
  background: #eef0f2;
  padding: 20px 0;
  border-radius: 10px;
  max-height: 75vh;
  overflow-y: auto;
}
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

  /* Sembunyikan sidebar/topbar/konten browse SELAMA proses print saja --
     di layar semua tetap tampil normal seperti biasa, menu tidak hilang. */
  .sidebar, .topbar, .konten-biasa, .no-print { display: none !important; }
  .content { margin: 0 !important; padding: 0 !important; }
  .cetak-area {
    display: block !important;
    background: none !important; padding: 0 !important;
    max-height: none !important; overflow: visible !important;
  }
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
const DATA_SISWA = JSON.parse(document.getElementById('dataSiswaAktif').textContent);
const NAMA_SEKOLAH = <?= json_encode($settings['nama_sekolah'] ?? 'SEKOLAH') ?>;
const LOGO_HTML = <?= json_encode($logoFile ? '<img class="mini-logo" src="' . BASE_URL . '/uploads/siswa/' . e($logoFile) . '" alt="Logo">' : '') ?>;
const SVG_BRACKETS = `<svg class="brackets" viewBox="0 0 460 460">
  <g stroke="var(--siap-blue)" stroke-width="26" stroke-linecap="round" stroke-linejoin="round" fill="none">
   
  </g>
</svg>`;

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// Bangun HTML 1 kartu (TANPA QR code dulu -- QR-nya di-generate belakangan
// per elemen, lihat generateQrUntukKartuBaru()) dari data siswa ringan.
function buatKartuHTML(s) {
  const isiUrl = <?= json_encode(BASE_URL . '/presensi/scan.php?token=') ?> + s.t;
  return `<div class="mini-card" data-kelas="${escapeHtml(s.k)}" data-jenjang="${escapeHtml(s.j)}">
    <div class="mini-pill">${escapeHtml(NAMA_SEKOLAH)}</div>
    ${LOGO_HTML}
    <div class="mini-scan-here">SCAN HERE</div>
    <div class="mini-qr-frame">${SVG_BRACKETS}<div class="qr-item-mini" data-content="${escapeHtml(isiUrl)}"></div></div>
    <div class="mini-nama">${escapeHtml(s.n)}</div>
    <div class="mini-detail">NIS: ${escapeHtml(s.nis)} &middot; Kelas ${escapeHtml(s.k)}</div>
  </div>`;
}

// Menampilkan PREVIEW kartu QR siswa yang tersaring (semua / per jenjang /
// per kelas) LANGSUNG di halaman ini -- BUKAN langsung window.print() --
// krn data siswa bisa banyak sekali, admin perlu kesempatan mengecek dulu
// sebelum benar-benar mencetak. Tombol "Cetak Sekarang" di toolbar preview
// yang baru memicu window.print().
//
// Kartu + QR code HANYA dibuat di sini, utk siswa yang tersaring saja --
// BUKAN semuanya di-generate sejak halaman dibuka. Ini yang bikin halaman
// terasa ringan meski data siswa banyak: buka halaman = cepat (cuma data
// JSON kecil), generate kartu = hanya sebesar yang benar-benar mau dicetak.
function previewCetakSiswa(filterType, filterValue) {
  const terpilih = DATA_SISWA.filter(s => !filterType || s[filterType === 'jenjang' ? 'j' : 'k'] === filterValue);

  const grid = document.getElementById('miniGridSiswa');
  grid.innerHTML = `<p class="text-center text-muted py-5 w-100">
    <span class="spinner-border spinner-border-sm me-2"></span> Menyiapkan ${terpilih.length} kartu QR...
  </p>`;

  document.getElementById('kontenBiasaSiswa').classList.add('d-none');
  document.getElementById('previewToolbarSiswa').classList.remove('d-none');
  document.getElementById('areaCetakSiswa').classList.add('preview-aktif');
  document.getElementById('jumlahPreviewSiswa').textContent = terpilih.length;
  window.scrollTo({ top: 0, behavior: 'instant' });

  // Jeda 1 frame supaya browser sempat menampilkan indikator loading di
  // atas dulu SEBELUM proses generate (yg bisa berat kalau jumlahnya
  // besar) benar-benar mulai jalan dan membekukan sesaat.
  requestAnimationFrame(() => {
    setTimeout(() => {
      grid.innerHTML = terpilih.map(buatKartuHTML).join('');
      grid.querySelectorAll('.qr-item-mini').forEach(function (holder) {
        const qr = qrcode(0, 'M');
        qr.addData(holder.dataset.content);
        qr.make();
        holder.innerHTML = qr.createImgTag(4, 0);
      });
    }, 30);
  });
}

// Kembali dari preview ke tampilan browse biasa (tanpa mencetak apa pun).
// Kosongkan lagi grid-nya supaya kartu yg sudah dibuat tidak menumpuk
// membebani memori kalau admin bolak-balik preview beberapa kali.
function tutupPreviewSiswa() {
  document.getElementById('kontenBiasaSiswa').classList.remove('d-none');
  document.getElementById('previewToolbarSiswa').classList.add('d-none');
  document.getElementById('areaCetakSiswa').classList.remove('preview-aktif');
  document.getElementById('miniGridSiswa').innerHTML = '';
}

// Setelah dialog print ditutup (baik jadi dicetak atau dibatalkan),
// otomatis kembali ke tampilan browse biasa.
window.addEventListener('afterprint', tutupPreviewSiswa);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>