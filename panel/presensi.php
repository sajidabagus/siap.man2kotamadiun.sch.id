<?php
require_once __DIR__ . '/../includes/izin.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/presensi_koreksi.php';

$bolehUbah = can('presensi.ubah');

// ---------------------------------------------------------------------
// AKSI (POST): ubah status presensi / tambah presensi manual
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  parse_str((string) ($_POST['kembali'] ?? ''), $kb);
  $balik = [];
  foreach (['tanggal', 'kelas', 'status', 'q', 'limit', 'page'] as $k) if (isset($kb[$k]) && is_scalar($kb[$k]) && (string) $kb[$k] !== '') $balik[$k] = (string) $kb[$k];
  $urlBalik = modul_url('presensi', $balik);
  if (!$bolehUbah) {
    set_flash('danger', 'Anda tidak memiliki izin untuk mengubah status presensi.');
  } elseif (!csrf_verify($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.');
  } else {
    $admin = current_admin() ?: [];
    $aksi = (string) ($_POST['aksi'] ?? '');
    if ($aksi === 'ubah') {
      $h = presensi_ubah_status((int) ($_POST['id'] ?? 0), (string) ($_POST['status_baru'] ?? ''), (string) ($_POST['jam'] ?? ''), (string) ($_POST['alasan'] ?? ''), $admin);
    } elseif ($aksi === 'tambah') {
      $h = presensi_tambah_manual((string) ($_POST['siswa'] ?? ''), (string) ($_POST['tanggal_baru'] ?? ''), (string) ($_POST['status_baru'] ?? ''), (string) ($_POST['jam'] ?? ''), (string) ($_POST['alasan'] ?? ''), $admin);
    } else {
      $h = ['ok' => false, 'pesan' => 'Aksi tidak dikenal.'];
    }
    if ($h['ok']) {
      $ket = $h['lama'] !== null
        ? "Mengubah presensi {$h['nama']} (NIS {$h['nis']}) tanggal {$h['tanggal']}: {$h['lama']} → {$h['baru']}. Alasan: {$h['alasan']}"
        : "Menambah presensi manual {$h['nama']} (NIS {$h['nis']}) tanggal {$h['tanggal']}: {$h['baru']}. Alasan: {$h['alasan']}";
      catat_aktivitas('UBAH', 'presensi', $ket);
      set_flash('success', $h['lama'] !== null
        ? "Status presensi {$h['nama']} diubah: " . koreksi_label_status($h['lama']) . ' → ' . koreksi_label_status($h['baru']) . '.'
        : "Presensi {$h['nama']} dicatat: " . koreksi_label_status($h['baru']) . '.');
    } else {
      set_flash('danger', $h['pesan']);
    }
  }
  header('Location: ' . $urlBalik);
  exit;
}

// ---------------------------------------------------------------------
// FILTER (rentang tanggal, kelas, status, pencarian)
// ---------------------------------------------------------------------
$tanggal = clean($_GET['tanggal'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) $tanggal = date('Y-m-d');
$kelas = clean($_GET['kelas'] ?? '');
$status = clean($_GET['status'] ?? '');
$q = clean($_GET['q'] ?? '');

$where = ['p.tanggal = :tanggal'];
$params = [':tanggal' => $tanggal];
if ($kelas !== '') {
  // Pakai snapshot kelas SAAT presensi itu terjadi, BUKAN kelas siswa yang
  // sekarang -- supaya laporan tanggal lama tetap akurat walau siswa sudah
  // naik/pindah kelas sejak saat itu.
  $where[] = 'p.kelas_saat_presensi = :kelas';
  $params[':kelas'] = $kelas;
}
if ($status !== '') {
  $where[] = 'p.status = :status';
  $params[':status'] = $status;
}
if ($q !== '') {
  // Placeholder dibuat unik karena PDO (tanpa emulate prepares) tidak
  // mengizinkan nama placeholder yang sama dipakai berulang.
  $where[] = '(s.nama LIKE :q1 OR s.nis LIKE :q2 OR s.nisn LIKE :q3)';
  $like = '%' . $q . '%';
  $params[':q1'] = $like;
  $params[':q2'] = $like;
  $params[':q3'] = $like;
}
$whereSql = implode(' AND ', $where);

$db = getDB();

$perPage = get_page_size();
$page = get_current_page();
$stmtCount = $db->prepare("SELECT COUNT(*) c FROM presensi p JOIN siswa s ON s.id = p.siswa_id WHERE $whereSql");
$stmtCount->execute($params);
$totalData = (int) $stmtCount->fetch()['c'];
$totalPages = max(1, (int) ceil($totalData / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT p.*, s.nisn, s.nama, p.kelas_saat_presensi AS kelas
        FROM presensi p JOIN siswa s ON s.id = p.siswa_id
        WHERE $whereSql ORDER BY p.jam_masuk LIMIT " . $perPage . " OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll();

// Daftar kelas utk dropdown filter: gabungan kelas yang ADA SEKARANG +
// kelas yang PERNAH tercatat di presensi (supaya kelas lama yang siswanya
// sudah semua naik/lulus tetap bisa dipilih utk lihat riwayatnya).
$kelasList = $db->query(
  'SELECT kelas FROM siswa
     UNION
     SELECT kelas_saat_presensi FROM presensi
     ORDER BY kelas'
)->fetchAll(PDO::FETCH_COLUMN);

$queryString = bangun_query(compact('tanggal', 'kelas', 'status', 'q'));
$extraParams = compact('tanggal', 'kelas', 'status', 'q') + ['limit' => $perPage];
?>
<style>
  /* Grid + !important: supaya tidak ditimpa CSS global (.tbl-tools form, .form-select, dst) */
  .pf-filter {
    display: grid !important;
    grid-template-columns: auto auto auto auto auto minmax(200px, 1fr) auto;
    align-items: end;
    gap: .75rem !important;
    width: 100%;
  }

  .pf-filter>* {
    margin: 0 !important;
    min-width: 0;
  }

  .pf-filter .form-select,
  .pf-filter .form-control {
    width: 100% !important;
    flex: none !important;
    max-width: none !important;
  }

  .pf-filter .pf-pp {
    display: flex;
    align-items: center;
    gap: .5rem;
    white-space: nowrap;
  }

  .pf-filter .pf-pp .form-select {
    width: auto !important;
  }

  .pf-filter .pf-kelas,
  .pf-filter .pf-status {
    min-width: 160px;
  }

  .pf-filter .pf-btn {
    white-space: nowrap;
  }

  @media (max-width: 1199.98px) {
    .pf-filter {
      grid-template-columns: 1fr 1fr;
    }

    .pf-filter .pf-icon {
      display: none;
    }

    .pf-filter .pf-pp,
    .pf-filter .pf-daterange,
    .pf-filter .pf-search {
      grid-column: 1 / -1;
    }
  }

  .pf-filter .pf-icon {
    font-size: 1.75rem;
    line-height: 1;
    color: var(--bs-primary, #0d6efd);
    align-self: end;
    padding-bottom: .35rem;
  }

  .pf-filter .pf-label {
    display: block;
    font-weight: 600;
    font-size: .85rem;
    margin-bottom: .25rem;
  }

  .pf-filter .pf-range {
    display: flex;
    align-items: center;
    gap: .5rem;
  }

  .pf-filter .pf-range .form-control {
    min-width: 150px;
    width: auto !important;
  }

  .pf-filter .pf-search {
    position: relative;
  }

  .pf-filter .pf-search .bi-search {
    position: absolute;
    top: 50%;
    left: .85rem;
    transform: translateY(-50%);
    color: #6c757d;
    pointer-events: none;
  }

  .pf-filter .pf-search .form-control {
    padding-left: 2.4rem;
  }

  .pf-card-title {
    display: flex;
    align-items: center;
    gap: .6rem;
    font-weight: 700;
  }

  .pf-card-title i {
    color: var(--bs-primary, #0d6efd);
    font-size: 1.4rem;
  }

  .pf-badge {
    display: inline-block;
    padding: .2rem .75rem;
    border-radius: 999px;
    font-size: .8rem;
    font-weight: 600;
  }

  .pf-badge.HADIR {
    background: #d1f3e0;
    color: #146c43;
  }

  .pf-badge.TERLAMBAT {
    background: #ffe5b8;
    color: #a45a00;
  }

  .pf-badge.IZIN {
    background: #d6e6ff;
    color: #0a58ca;
  }

  .pf-badge.SAKIT {
    background: #ffd6d9;
    color: #b02a37;
  }

  .pf-badge.ALPA {
    background: #e2e3e5;
    color: #41464b;
  }

  .pf-dispen .tbl-head .sub {
    font-size: .85rem;
    color: #6c757d;
  }

  .pf-dispen p {
    margin: 0;
    padding: .75rem 1rem;
    font-size: .85rem;
    color: #6c757d;
  }
</style>

<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-3 mb-3">
  <div>
    <h3 class="mb-1 fw-bold">Data Presensi</h3>
  </div>
  <div class="d-flex gap-2">
    <?php if ($bolehUbah): ?>
      <button type="button" class="btn btn-primary" id="btnTambahPresensi"><i class="bi bi-plus-lg"></i> <span>Tambah <span class="d-none d-sm-inline">Manual</span></span></button>
    <?php endif; ?>
    <?php if (can('presensi.ekspor')): ?>
      <a href="<?= e(modul_url('presensi_export', ['type' => 'csv'])) ?><?= $queryString !== '' ? '&amp;' . e($queryString) : '' ?>" data-live-href="<?= e(modul_url('presensi_export', ['type' => 'csv'])) ?>" data-live-from="#formFilter" class="btn btn-outline-success flex-fill flex-md-grow-0 text-nowrap d-inline-flex align-items-center justify-content-center gap-2">
        <i class="bi bi-filetype-csv"></i><span><span class="d-none d-sm-inline">Export </span>CSV</span>
      </a>
    <?php endif; ?>
    <?php if (can('presensi.ekspor')): ?>
      <a href="<?= e(modul_url('presensi_export', ['type' => 'excel'])) ?><?= $queryString !== '' ? '&amp;' . e($queryString) : '' ?>" data-live-href="<?= e(modul_url('presensi_export', ['type' => 'excel'])) ?>" data-live-from="#formFilter" class="btn btn-outline-success flex-fill flex-md-grow-0 text-nowrap d-inline-flex align-items-center justify-content-center gap-2">
        <i class="bi bi-file-earmark-excel"></i><span><span class="d-none d-sm-inline">Export </span>Excel</span>
      </a>
    <?php endif; ?>
    <?php if (can('presensi.cetak')): ?>
      <a href="<?= modul_url('presensi_print') ?><?= $queryString !== '' ? '?' . e($queryString) : '' ?>" data-live-href="<?= modul_url('presensi_print') ?>" data-live-from="#formFilter" target="_blank" class="btn btn-outline-secondary flex-fill flex-md-grow-0 text-nowrap d-inline-flex align-items-center justify-content-center gap-2">
        <i class="bi bi-printer"></i><span>Print</span>
      </a>
    <?php endif; ?>
  </div>
</div>

<!-- Kartu filter -->
<div class="tbl-card mb-3 no-print">
  <div class="p-3">
    <form method="get" data-live data-live-target="#hasil-live" id="formFilter" class="pf-filter"><?= hidden_modul() ?>
      <div class="pf-pp"><?= render_per_page_field($perPage, false) ?></div>

      <i class=""></i>
      <div class="pf-daterange">
        <input type="date" name="tanggal" id="fTanggal" class="form-control" value="<?= e($tanggal) ?>">
      </div>
      <select name="kelas" class="form-select pf-kelas">
        <option value="">Semua Kelas</option>
        <?php foreach ($kelasList as $k): ?><option value="<?= e($k) ?>" <?= $kelas === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?>
      </select>
      <select name="status" class="form-select pf-status">
        <option value="">Semua Status</option>
        <?php foreach (['HADIR', 'TERLAMBAT', 'IZIN', 'SAKIT', 'ALPA'] as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst(strtolower($s)) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="pf-search">
        <i class="bi bi-search"></i>
        <input type="search" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Cari nama siswa / NIS..." autocomplete="off">
      </div>
      <a href="<?= modul_url('presensi') ?>" class="btn btn-primary pf-btn" title="Hapus semua filter">Reset</a>
    </form>
  </div>
</div>

<!-- Kartu data presensi -->
<div class="tbl-card">
  <div class="tbl-head">
    <h6 class="pf-card-title mb-0"><i class="bi bi-calendar-check"></i> Data Presensi</h6>
  </div>
  <div id="hasil-live">
    <div class="table-responsive">
      <table class="table tbl align-middle">
        <thead>
          <tr>
            <th>No</th>
            <th>NISN</th>
            <th>Nama</th>
            <th>Kelas</th>
            <th>Tanggal</th>
            <th>Jam Masuk</th>
            <th>Pulang</th>
            <th>Status</th>
            <th>Metode</th>
            <?php if ($bolehUbah): ?><th class="no-print">Aksi</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data as $i => $r): ?>
            <tr>
              <td><?= $offset + $i + 1 ?></td>
              <td><?= e($r['nisn'] ?: '-') ?></td>
              <td><?= e($r['nama']) ?></td>
              <td><?= e($r['kelas']) ?></td>
              <td><?= e($r['tanggal']) ?></td>
              <td><?= (in_array($r['metode'], ['PENGAJUAN', 'OTOMATIS'], true) || $r['jam_masuk'] === '00:00:00') ? '—' : e($r['jam_masuk']) ?></td>
              <td><?= in_array($r['status'], ['HADIR', 'TERLAMBAT'], true) && !empty($r['jam_pulang']) ? e(substr($r['jam_pulang'], 0, 5)) : '—' ?></td>
              <td><span class="pf-badge <?= e($r['status']) ?>"><?= e(ucfirst(strtolower($r['status']))) ?></span></td>
              <td><?= e($r['metode']) ?><?= $r['metode'] === 'MANUAL' ? ' <i class="bi bi-pencil-square text-primary" title="Diubah/ditambah manual oleh petugas (lihat Log Aktivitas)"></i>' : '' ?></td>
              <?php if ($bolehUbah): ?>
                <td class="no-print text-nowrap">
                  <button type="button" class="btn btn-sm btn-light border rounded-pill px-3 btn-ubah-presensi" title="Ubah status presensi" aria-label="Ubah status presensi <?= e($r['nama']) ?>" data-id="<?= (int) $r['id'] ?>" data-nama="<?= e($r['nama']) ?>" data-kelas="<?= e($r['kelas']) ?>" data-tanggal="<?= e($r['tanggal']) ?>" data-status="<?= e($r['status']) ?>" data-jam="<?= e(substr((string) $r['jam_masuk'], 0, 5) === '00:00' ? '' : substr((string) $r['jam_masuk'], 0, 5)) ?>"><i class="bi bi-three-dots"></i></button>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$data): ?><tr>
              <td colspan="<?= $bolehUbah ? 10 : 9 ?>" class="text-center text-muted py-4">Tidak ada data.</td>
            </tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalData > 0): ?>
      <div class="tbl-foot no-print">
        <span class="text-muted small">Menampilkan <?= $offset + 1 ?> – <?= $offset + count($data) ?> dari <?= number_format($totalData, 0, ',', '.') ?> data</span>
        <?php if ($totalPages > 1): ?>
          <?= render_pagination($page, $totalPages, $extraParams) ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
// Kartu dispensasi: output fungsi lama ditangkap, lalu tabelnya diberi class yang sama
// dengan tabel presensi (table tbl align-middle) dan dibungkus kartu yang sama.
ob_start();
dispensasi_tabel_laporan(dispensasi_daftar_hari($tanggal, $tanggal, 0, $kelas, ''));
$htmlDispensasi = trim((string) ob_get_clean());
if ($htmlDispensasi !== '') {
  // Judul bawaan fungsi dipindah ke keterangan kecil di header kartu
  $judulAsli = '';
  if (preg_match('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $htmlDispensasi, $mh)) {
    $judulAsli = trim(strip_tags($mh[1]));
    $htmlDispensasi = preg_replace('/<h[1-6][^>]*>.*?<\/h[1-6]>/is', '', $htmlDispensasi, 1);
  }
  // Samakan class tabel dengan tabel presensi
  $htmlDispensasi = preg_replace_callback('/<table\b([^>]*)>/i', function ($m) {
    $attr = preg_replace('/\s(class|border|cellpadding|cellspacing|style)\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $m[1]);
    return '<table' . $attr . ' class="table tbl align-middle mb-0">';
  }, $htmlDispensasi);
?>
  <div class="tbl-card pf-dispen mt-3">
    <div class="tbl-head">
      <h6 class="pf-card-title mb-0"><i class="bi bi-shield-check" style="color:#6f42c1"></i> Dispensasi Disetujui</h6>
      <?php if ($judulAsli !== ''): ?><div class="sub"><?= e($judulAsli) ?></div><?php endif; ?>
    </div>
    <div class="table-responsive"><?= $htmlDispensasi ?></div>
  </div>
<?php } ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php if ($bolehUbah): ?>
  <div class="modal fade" id="modalPresensi" tabindex="-1" aria-labelledby="judulPresensi" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" class="modal-content" id="formPresensi">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" id="pAksi" value="ubah">
        <input type="hidden" name="id" id="pId" value="">
        <input type="hidden" name="kembali" value="<?= e(bangun_query(compact('tanggal', 'kelas', 'status', 'q') + ['limit' => $perPage, 'page' => $page])) ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="judulPresensi">Ubah status presensi</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
        <div class="modal-body">
          <div id="pInfo" class="mb-3 small text-muted"></div>
          <div id="pBlokTambah" class="row g-2 mb-2 d-none">
            <div class="col-7"><label class="form-label" for="pSiswa">NIS atau NISN siswa</label><input type="text" class="form-control" name="siswa" id="pSiswa" maxlength="30" autocomplete="off"></div>
            <div class="col-5"><label class="form-label" for="pTanggal">Tanggal</label><input type="date" class="form-control" name="tanggal_baru" id="pTanggal" max="<?= date('Y-m-d') ?>"></div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-7">
              <label class="form-label" for="pStatus">Status baru</label>
              <select class="form-select" name="status_baru" id="pStatus" required>
                <?php foreach (PRESENSI_STATUS_LIST as $st): ?><option value="<?= $st ?>"><?= e(koreksi_label_status($st)) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-5">
              <label class="form-label" for="pJam">Jam masuk</label>
              <input type="time" class="form-control" name="jam" id="pJam">
            </div>
          </div>
          <div class="form-text mb-2" id="pJamBantu">Jam hanya dipakai untuk status Hadir / Terlambat.</div>
          <label class="form-label" for="pAlasan">Alasan perubahan <span class="text-danger">*</span></label>
          <textarea class="form-control" name="alasan" id="pAlasan" rows="3" maxlength="<?= PRESENSI_ALASAN_MAKS ?>" minlength="<?= PRESENSI_ALASAN_MIN ?>" required placeholder="Mis. HP siswa rusak, hadir sesuai konfirmasi wali kelas"></textarea>
          <div class="form-text">Perubahan tercatat di Log Aktivitas dan siswa menerima notifikasi di portalnya.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
          <button class="btn btn-primary"><i class="bi bi-check2-circle"></i> Simpan</button>
        </div>
      </form>
    </div>
  </div>
  <script>
    (function() {
      var $ = function(id) {
        return document.getElementById(id);
      };

      function aturJam() {
        var perlu = $('pStatus').value === 'HADIR' || $('pStatus').value === 'TERLAMBAT';
        $('pJam').disabled = !perlu;
        $('pJam').required = perlu;
        if (!perlu) $('pJam').value = '';
        $('pJamBantu').style.visibility = perlu ? 'hidden' : 'visible';
      }

      function buka() {
        bootstrap.Modal.getOrCreateInstance($('modalPresensi')).show();
      }
      $('pStatus').addEventListener('change', aturJam);
      document.addEventListener('click', function(e) {
        var u = e.target.closest('.btn-ubah-presensi'),
          t = e.target.closest('#btnTambahPresensi');
        if (!u && !t) return;
        $('formPresensi').reset();
        if (u) {
          $('pAksi').value = 'ubah';
          $('pId').value = u.dataset.id;
          $('judulPresensi').textContent = 'Ubah status presensi';
          $('pBlokTambah').classList.add('d-none');
          $('pSiswa').required = false;
          $('pTanggal').required = false;
          $('pInfo').innerHTML = '<b></b> &middot; <span></span> &middot; <span></span>';
          $('pInfo').children[0].textContent = u.dataset.nama;
          $('pInfo').children[1].textContent = u.dataset.kelas;
          $('pInfo').children[2].textContent = u.dataset.tanggal;
          $('pStatus').value = u.dataset.status;
          $('pJam').value = u.dataset.jam || '';
        } else {
          $('pAksi').value = 'tambah';
          $('pId').value = '';
          $('judulPresensi').textContent = 'Tambah presensi manual';
          $('pBlokTambah').classList.remove('d-none');
          $('pSiswa').required = true;
          $('pTanggal').required = true;
          $('pInfo').textContent = 'Untuk siswa yang belum punya data presensi pada tanggal tersebut. Bila sudah ada, gunakan tombol Ubah pada barisnya.';
          var tg = document.querySelector('#formFilter [name=tanggal]');
          $('pTanggal').value = (tg && tg.value && tg.value <= '<?= date('Y-m-d') ?>') ? tg.value : '<?= date('Y-m-d') ?>';
          $('pStatus').value = 'HADIR';
        }
        aturJam();
        buka();
      });
    })();
  </script>
<?php endif; ?>