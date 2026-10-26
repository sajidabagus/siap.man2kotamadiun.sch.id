<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/presensi_koreksi.php';

$bolehUbah = can('presensi.ubah');

// ---------------------------------------------------------------------
// AKSI (POST): ubah status presensi / tambah presensi manual
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  parse_str((string) ($_POST['kembali'] ?? ''), $kb);
  $balik = [];
  foreach (['tanggal', 'kelas', 'jurusan', 'status', 'limit', 'page'] as $k) if (isset($kb[$k]) && is_scalar($kb[$k]) && (string) $kb[$k] !== '') $balik[$k] = (string) $kb[$k];
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

$tanggal = clean($_GET['tanggal'] ?? date('Y-m-d'));
$kelas = clean($_GET['kelas'] ?? '');
$jurusan = clean($_GET['jurusan'] ?? '');
$status = clean($_GET['status'] ?? '');

$where = ['p.tanggal = :tanggal'];
$params = [':tanggal' => $tanggal];
if ($kelas !== '') {
  // Pakai snapshot kelas SAAT presensi itu terjadi, BUKAN kelas siswa yang
  // sekarang -- supaya laporan tanggal lama tetap akurat walau siswa sudah
  // naik/pindah kelas sejak saat itu.
  $where[] = 'p.kelas_saat_presensi = :kelas';
  $params[':kelas'] = $kelas;
}
if ($jurusan !== '') {
  $where[] = 's.jurusan = :jurusan';
  $params[':jurusan'] = $jurusan;
}
if ($status !== '') {
  $where[] = 'p.status = :status';
  $params[':status'] = $status;
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

$sql = "SELECT p.*, s.nisn, s.nama, p.kelas_saat_presensi AS kelas, s.jurusan
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
$jurusanList = $db->query('SELECT DISTINCT jurusan FROM siswa WHERE jurusan IS NOT NULL ORDER BY jurusan')->fetchAll(PDO::FETCH_COLUMN);

$queryString = bangun_query(compact('tanggal', 'kelas', 'jurusan', 'status'));
$extraParams = compact('tanggal', 'kelas', 'jurusan', 'status') + ['limit' => $perPage];
?>
<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-3 mb-3">
  <h4 class="mb-0"><i class="bi bi-calendar-check"></i> Data Presensi</h4>
  <div class="d-flex gap-2">
    <?php if ($bolehUbah): ?>
    <button type="button" class="btn btn-primary" id="btnTambahPresensi"><i class="bi bi-plus-lg"></i> <span>Tambah <span class="d-none d-sm-inline">manual</span></span></button>
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

<div class="tbl-card">
  <div class="tbl-head">
    <h6>Data presensi</h6>
    <div class="sub">Daftar presensi siswa sesuai filter di atas.</div>
  </div>
  <div class="tbl-tools no-print">
    <form method="get" data-live data-live-target="#hasil-live" id="formFilter"><?= hidden_modul() ?>
      <?= render_per_page_field($perPage, false) ?>
        <input type="date" name="tanggal" class="form-control" value="<?= e($tanggal) ?>">
        <select name="kelas" class="form-select">
          <option value="">Semua Kelas</option>
          <?php foreach ($kelasList as $k): ?><option value="<?= e($k) ?>" <?= $kelas === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?>
        </select>
        <select name="jurusan" class="form-select">
          <option value="">Semua Jurusan</option>
          <?php foreach ($jurusanList as $j): ?><option value="<?= e($j) ?>" <?= $jurusan === $j ? 'selected' : '' ?>><?= e($j) ?></option><?php endforeach; ?>
        </select>
        <select name="status" class="form-select">
          <option value="">Semua Status</option>
          <?php foreach (['HADIR', 'TERLAMBAT', 'IZIN', 'SAKIT', 'ALPA'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
          <a href="<?= modul_url('presensi') ?>" class="btn btn-light border" title="Hapus semua filter"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
    </form>
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
          <th>Jam</th>
          <th>Status</th>
          <th>Metode</th>
          <?php if ($bolehUbah): ?><th class="no-print" style="width:1%"></th><?php endif; ?>
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
            <td><span class="badge bg-<?= $r['status'] === 'HADIR' ? 'success' : ($r['status'] === 'TERLAMBAT' ? 'warning' : 'secondary') ?>"><?= e($r['status']) ?></span></td>
            <td><?= e($r['metode']) ?><?= $r['metode'] === 'MANUAL' ? ' <i class="bi bi-pencil-square text-primary" title="Diubah/ditambah manual oleh petugas (lihat Log Aktivitas)"></i>' : '' ?></td>
            <?php if ($bolehUbah): ?>
            <td class="no-print text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-primary btn-ubah-presensi" data-id="<?= (int) $r['id'] ?>" data-nama="<?= e($r['nama']) ?>" data-kelas="<?= e($r['kelas']) ?>" data-tanggal="<?= e($r['tanggal']) ?>" data-status="<?= e($r['status']) ?>" data-jam="<?= e(substr((string) $r['jam_masuk'], 0, 5) === '00:00' ? '' : substr((string) $r['jam_masuk'], 0, 5)) ?>"><i class="bi bi-pencil"></i> Ubah</button>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$data): ?><tr>
            <td colspan="<?= $bolehUbah ? 9 : 8 ?>" class="text-center text-muted py-4">Tidak ada data.</td>
          </tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <div class="tbl-foot no-print">
      <span class="text-muted small">Menampilkan <?= count($data) ?> dari <?= $totalData ?> data (halaman <?= $page ?> dari <?= $totalPages ?>)</span>
      <?= render_pagination($page, $totalPages, $extraParams) ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php if ($bolehUbah): ?>
  <div class="modal fade" id="modalPresensi" tabindex="-1" aria-labelledby="judulPresensi" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" class="modal-content" id="formPresensi">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" id="pAksi" value="ubah">
        <input type="hidden" name="id" id="pId" value="">
        <input type="hidden" name="kembali" value="<?= e(bangun_query(compact('tanggal', 'kelas', 'jurusan', 'status') + ['limit' => $perPage, 'page' => $page])) ?>">
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
    (function () {
      var $ = function (id) { return document.getElementById(id); };
      function aturJam() {
        var perlu = $('pStatus').value === 'HADIR' || $('pStatus').value === 'TERLAMBAT';
        $('pJam').disabled = !perlu; $('pJam').required = perlu;
        if (!perlu) $('pJam').value = '';
        $('pJamBantu').style.visibility = perlu ? 'hidden' : 'visible';
      }
      function buka() { bootstrap.Modal.getOrCreateInstance($('modalPresensi')).show(); }
      $('pStatus').addEventListener('change', aturJam);
      document.addEventListener('click', function (e) {
        var u = e.target.closest('.btn-ubah-presensi'), t = e.target.closest('#btnTambahPresensi');
        if (!u && !t) return;
        $('formPresensi').reset();
        if (u) {
          $('pAksi').value = 'ubah'; $('pId').value = u.dataset.id; $('judulPresensi').textContent = 'Ubah status presensi';
          $('pBlokTambah').classList.add('d-none'); $('pSiswa').required = false; $('pTanggal').required = false;
          $('pInfo').innerHTML = '<b></b> &middot; <span></span> &middot; <span></span>';
          $('pInfo').children[0].textContent = u.dataset.nama; $('pInfo').children[1].textContent = u.dataset.kelas; $('pInfo').children[2].textContent = u.dataset.tanggal;
          $('pStatus').value = u.dataset.status; $('pJam').value = u.dataset.jam || '';
        } else {
          $('pAksi').value = 'tambah'; $('pId').value = ''; $('judulPresensi').textContent = 'Tambah presensi manual';
          $('pBlokTambah').classList.remove('d-none'); $('pSiswa').required = true; $('pTanggal').required = true;
          $('pInfo').textContent = 'Untuk siswa yang belum punya data presensi pada tanggal tersebut. Bila sudah ada, gunakan tombol Ubah pada barisnya.';
          var tg = document.querySelector('#formFilter [name=tanggal]'); $('pTanggal').value = (tg && tg.value && tg.value <= '<?= date('Y-m-d') ?>') ? tg.value : '<?= date('Y-m-d') ?>';
          $('pStatus').value = 'HADIR';
        }
        aturJam(); buka();
      });
    })();
  </script>
<?php endif; ?>
