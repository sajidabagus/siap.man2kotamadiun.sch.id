<?php
// Halaman cetak Data Presensi (semua baris sesuai filter, tanpa paginasi, tanpa sidebar).
require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = getDB();
$settings = get_settings();

$tanggal = clean($_GET['tanggal'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) $tanggal = date('Y-m-d');
$kelas = clean($_GET['kelas'] ?? '');
$jurusan = clean($_GET['jurusan'] ?? '');
$status = clean($_GET['status'] ?? '');

$where = ['p.tanggal = :tanggal'];
$params = [':tanggal' => $tanggal];
if ($kelas !== '') { $where[] = 'p.kelas_saat_presensi = :kelas'; $params[':kelas'] = $kelas; }
if ($jurusan !== '') { $where[] = 's.jurusan = :jurusan'; $params[':jurusan'] = $jurusan; }
if ($status !== '') { $where[] = 'p.status = :status'; $params[':status'] = $status; }

$stmt = $db->prepare(
    'SELECT s.nisn, s.nama, s.jurusan, p.kelas_saat_presensi AS kelas, p.jam_masuk, p.status, p.metode
     FROM presensi p JOIN siswa s ON s.id = p.siswa_id
     WHERE ' . implode(' AND ', $where) . ' ORDER BY p.kelas_saat_presensi, s.nama'
);
$stmt->execute($params);
$data = $stmt->fetchAll();

$rekap = ['HADIR' => 0, 'TERLAMBAT' => 0, 'IZIN' => 0, 'SAKIT' => 0, 'ALPA' => 0];
foreach ($data as $r) if (isset($rekap[$r['status']])) $rekap[$r['status']]++;
$hadirTotal = $rekap['HADIR'] + $rekap['TERLAMBAT']; // terlambat tetap dihitung hadir

$namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$ts = strtotime($tanggal);
$tglIndo = NAMA_HARI[(int) date('N', $ts)] . ', ' . date('j', $ts) . ' ' . $namaBulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);

$filter = [];
if ($kelas !== '') $filter[] = 'Kelas ' . $kelas;
if ($jurusan !== '') $filter[] = 'Jurusan ' . $jurusan;
if ($status !== '') $filter[] = 'Status ' . $status;
$filterTxt = $filter ? implode(' • ', $filter) : 'Semua Kelas';

$logo = $settings['logo'] ?? '';
$logoUrl = ($logo !== '' && is_file(__DIR__ . '/../uploads/siswa/' . $logo)) ? BASE_URL . '/uploads/siswa/' . rawurlencode($logo) : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Presensi <?= e($tanggal) ?></title>
<?= favicon_tag() ?>
<style>
  * { box-sizing: border-box; }
  body { font-family: "Segoe UI", Arial, sans-serif; font-size: 12px; color: #000; margin: 0; background: #f1f3f5; }
  .toolbar { position: sticky; top: 0; background: #fff; border-bottom: 1px solid #ddd; padding: 8px 16px; display: flex; gap: 8px; align-items: center; }
  .toolbar button, .toolbar a { font: inherit; padding: 6px 14px; border: 1px solid #0d6efd; background: #0d6efd; color: #fff; border-radius: 6px; cursor: pointer; text-decoration: none; }
  .toolbar a { background: #fff; color: #0d6efd; }
  .halaman { width: 210mm; max-width: 100%; margin: 12px auto; background: #fff; padding: 15mm; }
  .kop { display: flex; align-items: center; gap: 14px; border-bottom: 3px double #000; padding-bottom: 8px; margin-bottom: 12px; }
  .kop img { width: 64px; height: 64px; object-fit: contain; }
  .kop h1 { font-size: 16px; margin: 0; text-transform: uppercase; }
  .kop p { margin: 2px 0 0; font-size: 11px; }
  h2 { font-size: 14px; text-align: center; margin: 0 0 2px; text-transform: uppercase; }
  .sub { text-align: center; margin: 0 0 12px; }
  .ringkas { display: flex; gap: 6px; margin-bottom: 10px; }
  .ringkas div { flex: 1; border: 1px solid #000; padding: 4px 6px; text-align: center; }
  .ringkas b { display: block; font-size: 15px; }
  table.data { width: 100%; border-collapse: collapse; }
  .data th, .data td { border: 1px solid #000; padding: 3px 5px; }
  .data th { background: #e9ecef; text-align: center; }
  .data td.c { text-align: center; }
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
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <button type="button" onclick="window.print()">Cetak</button>
  <a href="javascript:window.close()">Tutup</a>
  <span style="color:#6c757d"><?= e($tglIndo) ?> — <?= e($filterTxt) ?></span>
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

  <h2>Data Presensi Siswa</h2>
  <p class="sub"><?= e($tglIndo) ?><br><?= e($filterTxt) ?></p>

  <div class="ringkas">
    <div><b><?= count($data) ?></b>Total</div>
    <div><b><?= $hadirTotal ?></b>Hadir</div>
    <div><b><?= $rekap['TERLAMBAT'] ?></b>Terlambat</div>
    <div><b><?= $rekap['IZIN'] ?></b>Izin</div>
    <div><b><?= $rekap['SAKIT'] ?></b>Sakit</div>
    <div><b><?= $rekap['ALPA'] ?></b>Alpa</div>
  </div>

  <table class="data">
    <thead>
      <tr><th style="width:30px">No</th><th>NISN</th><th>Nama</th><th>Kelas</th><th>Jam</th><th>Status</th><th>Metode</th></tr>
    </thead>
    <tbody>
      <?php foreach ($data as $i => $r): ?>
        <tr>
          <td class="c"><?= $i + 1 ?></td>
          <td><?= e($r['nisn'] ?: '-') ?></td>
          <td><?= e($r['nama']) ?></td>
          <td class="c"><?= e($r['kelas']) ?></td>
          <td class="c"><?= in_array($r['metode'], ['PENGAJUAN', 'OTOMATIS'], true) ? '—' : e(substr((string) $r['jam_masuk'], 0, 5)) ?></td>
          <td class="c"><?= e($r['status']) ?></td>
          <td class="c"><?= e($r['metode']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$data): ?><tr><td colspan="7" class="c">Tidak ada data presensi.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <p class="catatan">Keterangan: <strong>Hadir</strong> sudah termasuk siswa yang terlambat (<strong>Terlambat</strong> adalah bagian dari Hadir).
    Dicetak pada <?= date('j') . ' ' . $namaBulan[(int) date('n')] . ' ' . date('Y, H:i') ?>.</p>

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