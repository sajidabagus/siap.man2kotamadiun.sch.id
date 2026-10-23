<?php
require_once __DIR__ . '/../includes/header.php';

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
            <td><?= in_array($r['metode'], ['PENGAJUAN', 'OTOMATIS'], true) ? '—' : e($r['jam_masuk']) ?></td>
            <td><span class="badge bg-<?= $r['status'] === 'HADIR' ? 'success' : ($r['status'] === 'TERLAMBAT' ? 'warning' : 'secondary') ?>"><?= e($r['status']) ?></span></td>
            <td><?= e($r['metode']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$data): ?><tr>
            <td colspan="8" class="text-center text-muted py-4">Tidak ada data.</td>
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