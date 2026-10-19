<?php
// Halaman cetak Rekap Kehadiran Bulanan (semua siswa, tanpa paginasi).
require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = getDB();
$settings = get_settings();

$bulan = min(12, max(1, (int) ($_GET['bulan'] ?? date('n'))));
$tahun = (int) ($_GET['tahun'] ?? date('Y'));
$kelas = clean($_GET['kelas'] ?? '');

$namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// Rekap per siswa (semua siswa aktif pada filter terpilih)
$sql = "SELECT s.nisn, s.nama,
        COALESCE(
          (SELECT p2.kelas_saat_presensi FROM presensi p2
           WHERE p2.siswa_id = s.id AND MONTH(p2.tanggal)=:bulan2 AND YEAR(p2.tanggal)=:tahun2
           ORDER BY p2.tanggal DESC LIMIT 1),
          s.kelas
        ) AS kelas_bulan_ini,
        SUM(p.status IN ('HADIR','TERLAMBAT')) hadir, SUM(p.status='TERLAMBAT') terlambat,
        SUM(p.status='IZIN') izin, SUM(p.status='SAKIT') sakit, SUM(p.status='ALPA') alpa
        FROM siswa s LEFT JOIN presensi p ON p.siswa_id = s.id AND MONTH(p.tanggal)=:bulan AND YEAR(p.tanggal)=:tahun
        WHERE s.status='aktif'" . ($kelas !== '' ? ' AND s.kelas = :kelas' : '') . "
        GROUP BY s.id ORDER BY s.kelas, s.nama";
$params = [':bulan' => $bulan, ':tahun' => $tahun, ':bulan2' => $bulan, ':tahun2' => $tahun];
if ($kelas !== '') $params[':kelas'] = $kelas;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rekapSiswa = $stmt->fetchAll();

// Ringkasan per kelas (snapshot kelas saat presensi)
$stmtK = $db->prepare(
    "SELECT p.kelas_saat_presensi AS kelas, COUNT(DISTINCT p.siswa_id) total_siswa,
     SUM(p.status IN ('HADIR','TERLAMBAT')) hadir, SUM(p.status='TERLAMBAT') terlambat,
     SUM(p.status='IZIN') izin, SUM(p.status='SAKIT') sakit, SUM(p.status='ALPA') alpa
     FROM presensi p WHERE MONTH(p.tanggal)=:bulan AND YEAR(p.tanggal)=:tahun"
    . ($kelas !== '' ? ' AND p.kelas_saat_presensi = :kelas' : '') .
    " GROUP BY p.kelas_saat_presensi ORDER BY p.kelas_saat_presensi"
);
$stmtK->execute($kelas !== '' ? [':bulan' => $bulan, ':tahun' => $tahun, ':kelas' => $kelas] : [':bulan' => $bulan, ':tahun' => $tahun]);
$rekapKelas = $stmtK->fetchAll();

$logo = $settings['logo'] ?? '';
$logoUrl = ($logo !== '' && is_file(__DIR__ . '/../uploads/siswa/' . $logo)) ? BASE_URL . '/uploads/siswa/' . rawurlencode($logo) : null;
$judulKelas = $kelas !== '' ? 'Kelas ' . $kelas : 'Semua Kelas';
$tot = ['hadir' => 0, 'terlambat' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rekap Kehadiran <?= e($namaBulan[$bulan] . ' ' . $tahun . ' - ' . $judulKelas) ?></title>
<?= favicon_tag() ?>
<style>
  * { box-sizing: border-box; }
  body { font-family: "Segoe UI", Arial, sans-serif; font-size: 12px; color: var(--siap-ink); margin: 0; background: var(--siap-bg); }
  .toolbar { position: sticky; top: 0; background: #fff; border-bottom: 1px solid var(--siap-line); padding: 8px 16px; display: flex; gap: 8px; align-items: center; }
  .toolbar button, .toolbar a { font: inherit; padding: 6px 14px; border: 1px solid var(--siap-blue); background: var(--siap-blue); color: #fff; border-radius: 6px; cursor: pointer; text-decoration: none; }
  .toolbar a { background: #fff; color: var(--siap-blue); }
  .halaman { width: 210mm; max-width: 100%; margin: 12px auto; background: #fff; padding: 15mm; }
  .kop { display: flex; align-items: center; gap: 14px; border-bottom: 3px double #000; padding-bottom: 8px; margin-bottom: 12px; }
  .kop img { width: 64px; height: 64px; object-fit: contain; }
  .kop h1 { font-size: 16px; margin: 0; text-transform: uppercase; }
  .kop p { margin: 2px 0 0; font-size: 11px; }
  h2 { font-size: 14px; text-align: center; margin: 0 0 2px; text-transform: uppercase; }
  .sub { text-align: center; margin: 0 0 12px; }
  h3 { font-size: 12px; margin: 14px 0 4px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #000; padding: 3px 5px; }
  th { background: var(--siap-line); text-align: center; }
  td.c { text-align: center; }
  tfoot td { font-weight: 700; background: var(--siap-bg); }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
  .catatan { font-size: 10.5px; margin-top: 6px; }
  .ttd { display: flex; justify-content: flex-end; margin-top: 28px; page-break-inside: avoid; }
  .ttd div { width: 220px; text-align: center; }
  .ttd .spasi { height: 64px; }
  @page { size: A4 portrait; margin: 0; }
  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .halaman { margin: 0; width: auto; padding: 15mm; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <button type="button" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs" onclick="window.print()">Cetak</button>
  <a href="javascript:window.close()" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs">Tutup</a>
  <span style="color:var(--siap-muted)">Rekap <?= e($namaBulan[$bulan] . ' ' . $tahun) ?> — <?= e($judulKelas) ?></span>
</div>

<div class="halaman">
  <div class="kop">
    <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="Logo"><?php endif; ?>
    <div>
      <h1><?= e($settings['nama_sekolah'] ?? 'Sekolah') ?></h1>
      <?php if (!empty($settings['alamat_sekolah'])): ?><p><?= nl2br(e($settings['alamat_sekolah'])) ?></p><?php endif; ?>
      <p>Tahun Pelajaran <?= e($settings['tahun_pelajaran'] ?? '-') ?></p>
    </div>
  </div>

  <h2>Rekap Kehadiran Siswa Bulanan</h2>
  <p class="sub">Bulan <?= e($namaBulan[$bulan] . ' ' . $tahun) ?> — <?= e($judulKelas) ?></p>

  <h3>Ringkasan Per Kelas</h3>
  <table>
    <thead>
      <tr><th>Kelas</th><th>Jumlah Siswa</th><th>Hadir</th><th>Terlambat</th><th>Izin</th><th>Sakit</th><th>Alpa</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rekapKelas as $k): ?>
        <tr>
          <td><?= e($k['kelas']) ?></td>
          <td class="c"><?= (int) $k['total_siswa'] ?></td>
          <td class="c"><?= (int) $k['hadir'] ?></td>
          <td class="c"><?= (int) $k['terlambat'] ?></td>
          <td class="c"><?= (int) $k['izin'] ?></td>
          <td class="c"><?= (int) $k['sakit'] ?></td>
          <td class="c"><?= (int) $k['alpa'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rekapKelas): ?><tr><td colspan="7" class="c">Belum ada data presensi pada bulan ini.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <h3>Rekap Per Siswa</h3>
  <table>
    <thead>
      <tr><th style="width:30px">No</th><th>NISN</th><th>Nama</th><th>Kelas</th><th>Hadir</th><th>Terlambat</th><th>Izin</th><th>Sakit</th><th>Alpa</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rekapSiswa as $i => $r):
        foreach ($tot as $key => $_) $tot[$key] += (int) $r[$key]; ?>
        <tr>
          <td class="c"><?= $i + 1 ?></td>
          <td><?= e($r['nisn'] ?: '-') ?></td>
          <td><?= e($r['nama']) ?></td>
          <td class="c"><?= e($r['kelas_bulan_ini']) ?></td>
          <td class="c"><?= (int) $r['hadir'] ?></td>
          <td class="c"><?= (int) $r['terlambat'] ?></td>
          <td class="c"><?= (int) $r['izin'] ?></td>
          <td class="c"><?= (int) $r['sakit'] ?></td>
          <td class="c"><?= (int) $r['alpa'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rekapSiswa): ?><tr><td colspan="9" class="c">Tidak ada data siswa.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($rekapSiswa): ?>
    <tfoot>
      <tr>
        <td colspan="4" class="c">Total</td>
        <td class="c"><?= $tot['hadir'] ?></td><td class="c"><?= $tot['terlambat'] ?></td>
        <td class="c"><?= $tot['izin'] ?></td><td class="c"><?= $tot['sakit'] ?></td><td class="c"><?= $tot['alpa'] ?></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>
  <p class="catatan">Keterangan: kolom <strong>Hadir</strong> sudah termasuk siswa yang terlambat; kolom <strong>Terlambat</strong> adalah bagian dari Hadir.
    Dicetak pada <?= date('d') . ' ' . $namaBulan[(int) date('n')] . ' ' . date('Y, H:i') ?>.</p>

  <div class="ttd">
    <div>
      Mengetahui,<br>Kepala Madrasah
      <div class="spasi"></div>
      <?php if (!empty($settings['nama_kepala'])): ?><strong style="text-decoration:underline"><?= e($settings['nama_kepala']) ?></strong><?php else: ?>(............................................)<?php endif; ?>
    </div>
  </div>
</div>

<?= script_log_cetak() ?>
<script>setTimeout(function () { window.print(); }, 400);</script>
</body>
</html>