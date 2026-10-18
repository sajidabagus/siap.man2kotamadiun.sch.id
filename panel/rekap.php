<?php
require_once __DIR__ . '/../includes/header.php';

$bulan = (int) ($_GET['bulan'] ?? date('n'));
$tahun = (int) ($_GET['tahun'] ?? date('Y'));
$kelas = clean($_GET['kelas'] ?? '');

$db = getDB();
// Daftar kelas utk dropdown filter: gabungan kelas yang ADA SEKARANG +
// kelas yang PERNAH tercatat di presensi (supaya kelas lama yang siswanya
// sudah semua naik/lulus tetap bisa dipilih utk lihat riwayatnya).
$kelasList = $db->query(
    'SELECT kelas FROM siswa
     UNION
     SELECT kelas_saat_presensi FROM presensi
     ORDER BY kelas'
)->fetchAll(PDO::FETCH_COLUMN);

$where = ['MONTH(p.tanggal) = :bulan', 'YEAR(p.tanggal) = :tahun'];
$params = [':bulan' => $bulan, ':tahun' => $tahun];
if ($kelas !== '') {
  $where[] = 's.kelas = :kelas';
  $params[':kelas'] = $kelas;
}
$whereSql = implode(' AND ', $where);

// Rekap per siswa dalam bulan terpilih
$perPage = get_page_size();
$page = get_current_page();

$stmtCount = $db->prepare(
  "SELECT COUNT(*) c FROM siswa s WHERE s.status='aktif'" . ($kelas !== '' ? ' AND s.kelas = :kelas' : '')
);
$stmtCount->execute($kelas !== '' ? [':kelas' => $kelas] : []);
$totalData = (int) $stmtCount->fetch()['c'];
$totalPages = max(1, (int) ceil($totalData / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// "kelas_bulan_ini" = kelas siswa PADA BULAN YANG DILIHAT (diambil dari
// snapshot presensi bulan itu), fallback ke kelas siswa SEKARANG kalau
// tidak ada presensi sama sekali bulan itu (mis. siswa baru/tidak masuk
// sekali pun). Supaya kalau siswa sudah naik/pindah kelas SETELAH bulan
// yang dilihat, laporan bulan lama tetap menampilkan kelas yg benar saat
// itu -- bukan kelas siswa yg terbaru.
$sql = "SELECT s.id, s.nisn, s.nama, s.kelas,
        COALESCE(
          (SELECT p2.kelas_saat_presensi FROM presensi p2
           WHERE p2.siswa_id = s.id AND MONTH(p2.tanggal)=:bulan2 AND YEAR(p2.tanggal)=:tahun2
           ORDER BY p2.tanggal DESC LIMIT 1),
          s.kelas
        ) AS kelas_bulan_ini,
        SUM(p.status IN ('HADIR','TERLAMBAT')) hadir, SUM(p.status='TERLAMBAT') terlambat,
        SUM(p.status='IZIN') izin, SUM(p.status='SAKIT') sakit, SUM(p.status='ALPA') alpa,
        COUNT(p.id) total_masuk
        FROM siswa s LEFT JOIN presensi p ON p.siswa_id = s.id AND MONTH(p.tanggal)=:bulan AND YEAR(p.tanggal)=:tahun
        WHERE s.status='aktif'" . ($kelas !== '' ? ' AND s.kelas = :kelas' : '') . "
        GROUP BY s.id ORDER BY s.kelas, s.nama
        LIMIT " . $perPage . " OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params + [':bulan2' => $bulan, ':tahun2' => $tahun]);
$rekapSiswa = $stmt->fetchAll();

// Rekap ringkas per kelas -- dihitung dari SNAPSHOT kelas_saat_presensi
// (bukan kelas siswa sekarang), supaya akurat secara historis: laporan
// bulan lalu tidak berubah walau siswanya sudah naik/pindah kelas sejak
// saat itu. "Jumlah Siswa" di sini berarti jumlah siswa BERBEDA yang
// tercatat presensi di kelas tsb bulan ini (bukan jumlah siswa yang
// SEKARANG terdaftar di kelas itu).
$sqlKelas = "SELECT p.kelas_saat_presensi AS kelas, COUNT(DISTINCT p.siswa_id) total_siswa,
             SUM(p.status IN ('HADIR','TERLAMBAT')) hadir, SUM(p.status='TERLAMBAT') terlambat, SUM(p.status='ALPA') alpa
             FROM presensi p
             WHERE MONTH(p.tanggal)=:bulan AND YEAR(p.tanggal)=:tahun
             GROUP BY p.kelas_saat_presensi ORDER BY p.kelas_saat_presensi";
$stmtK = $db->prepare($sqlKelas);
$stmtK->execute([':bulan' => $bulan, ':tahun' => $tahun]);
$rekapKelas = $stmtK->fetchAll();

$namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$extraParams = ['bulan' => $bulan, 'tahun' => $tahun, 'kelas' => $kelas, 'limit' => $perPage];
?>
<h4 class="mb-3"><i class="bi bi-bar-chart"></i> Rekap Kehadiran Bulanan</h4>

<div class="tbl-card">
  <div class="tbl-head">
    <h6>Rekap kehadiran bulanan</h6>
    <div class="sub">Ringkasan per kelas dan rekap per siswa pada bulan terpilih (terlambat dihitung hadir).</div>
  </div>
  <div class="tbl-tools">
    <form method="get" id="formRekap" data-live data-live-target="#hasil-live"><?= hidden_modul() ?>
      <?= render_per_page_field($perPage, false) ?>
      <select name="bulan" class="form-select">
        <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === $bulan ? 'selected' : '' ?>><?= $namaBulan[$m] ?></option><?php endfor; ?>
      </select>
      <input type="number" name="tahun" class="form-control" value="<?= $tahun ?>" aria-label="Tahun">
      <select name="kelas" class="form-select">
        <option value="">Semua Kelas</option>
        <?php foreach ($kelasList as $k): ?><option value="<?= e($k) ?>" <?= $kelas === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?>
      </select>
      <?php if (can('rekap.cetak')): ?>
      <button type="button" class="siap-btn siap-btn-ghost siap-mono-xs" data-open-form="<?= modul_url('rekap_print') ?>" title="Cetak rekap bulan ini (semua siswa)"><i class="bi bi-printer"></i> Cetak</button>
      <?php endif; ?>
    </form>
  </div>
  <div id="hasil-live">
    <div class="tbl-subhead">Ringkasan Per Kelas — <?= $namaBulan[$bulan] ?> <?= $tahun ?><span class="sub">Dihitung dari kelas siswa <strong>saat presensi terjadi</strong> bulan ini — tetap akurat walau siswa sudah naik/pindah kelas setelahnya.</span></div>
    <div class="table-responsive">
    <table class="table tbl align-middle">
      <thead>
        <tr>
          <th>Kelas</th>
          <th>Jumlah Siswa</th>
          <th>Hadir<br><small class="fw-normal text-muted">(incl. terlambat)</small></th>
          <th>Terlambat<br><small class="fw-normal text-muted">(bagian dari hadir)</small></th>
          <th>Alpa</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rekapKelas as $k): ?>
          <tr>
            <td><?= e($k['kelas']) ?></td>
            <td><?= (int)$k['total_siswa'] ?></td>
            <td><?= (int)$k['hadir'] ?></td>
            <td><?= (int)$k['terlambat'] ?></td>
            <td><?= (int)$k['alpa'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="tbl-subhead">Rekap per siswa</div>
    <div class="table-responsive">
    <table class="table tbl align-middle">
      <thead>
        <tr>
          <th>No</th>
          <th>NISN</th>
          <th>Nama</th>
          <th>Kelas</th>
          <th>Hadir<br><small class="fw-normal text-muted">(incl. terlambat)</small></th>
          <th>Terlambat<br><small class="fw-normal text-muted">(bagian dari hadir)</small></th>
          <th>Izin</th>
          <th>Sakit</th>
          <th>Alpa</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rekapSiswa as $i => $r): ?>
          <tr>
            <td><?= $offset + $i + 1 ?></td>
            <td><?= e($r['nisn'] ?: '-') ?></td>
            <td><?= e($r['nama']) ?></td>
            <td><?= e($r['kelas_bulan_ini']) ?></td>
            <td><?= (int)$r['hadir'] ?></td>
            <td><?= (int)$r['terlambat'] ?></td>
            <td><?= (int)$r['izin'] ?></td>
            <td><?= (int)$r['sakit'] ?></td>
            <td><?= (int)$r['alpa'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php if ($totalPages > 1): ?>
    <div class="tbl-foot">
      <span class="text-muted small">Menampilkan <?= count($rekapSiswa) ?> dari <?= $totalData ?> siswa (halaman <?= $page ?> dari <?= $totalPages ?>)</span>
      <?= render_pagination($page, $totalPages, $extraParams) ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>