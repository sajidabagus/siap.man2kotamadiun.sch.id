<?php
require_once __DIR__ . '/../includes/izin.php';
// Halaman cetak Data Presensi (semua baris sesuai filter, tanpa paginasi, tanpa sidebar).
require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = getDB();
$settings = get_settings();

// ---------------------------------------------------------------------
// FILTER DATA
// ---------------------------------------------------------------------

$tanggal = clean($_GET['tanggal'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
  $tanggal = date('Y-m-d');
}

$kelas = clean($_GET['kelas'] ?? '');
$jurusan = clean($_GET['jurusan'] ?? '');
$status = clean($_GET['status'] ?? '');

$where = ['p.tanggal = :tanggal'];
$params = [':tanggal' => $tanggal];

if ($kelas !== '') {
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

// ---------------------------------------------------------------------
// AMBIL DATA PRESENSI
// ---------------------------------------------------------------------

$stmt = $db->prepare(
  'SELECT
        s.nisn,
        s.nama,
        s.jurusan,
        p.kelas_saat_presensi AS kelas,
        p.jam_masuk,
        p.status,
        p.metode
     FROM presensi p
     JOIN siswa s ON s.id = p.siswa_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY p.kelas_saat_presensi, s.nama'
);

$stmt->execute($params);
$data = $stmt->fetchAll();

// ---------------------------------------------------------------------
// REKAP PRESENSI
// ---------------------------------------------------------------------

$rekap = [
  'HADIR' => 0,
  'TERLAMBAT' => 0,
  'IZIN' => 0,
  'SAKIT' => 0,
  'ALPA' => 0
];

foreach ($data as $r) {
  if (isset($rekap[$r['status']])) {
    $rekap[$r['status']]++;
  }
}

// Terlambat tetap dihitung sebagai hadir.
$hadirTotal = $rekap['HADIR'] + $rekap['TERLAMBAT'];

// ---------------------------------------------------------------------
// FORMAT TANGGAL INDONESIA
// ---------------------------------------------------------------------

$namaBulan = [
  '',
  'Januari',
  'Februari',
  'Maret',
  'April',
  'Mei',
  'Juni',
  'Juli',
  'Agustus',
  'September',
  'Oktober',
  'November',
  'Desember'
];

$ts = strtotime($tanggal);

$tglIndo = NAMA_HARI[(int) date('N', $ts)] .
  ', ' . date('j', $ts) .
  ' ' . $namaBulan[(int) date('n', $ts)] .
  ' ' . date('Y', $ts);

// ---------------------------------------------------------------------
// KETERANGAN FILTER
// ---------------------------------------------------------------------

$filter = [];

if ($kelas !== '') {
  $filter[] = 'Kelas ' . $kelas;
}

if ($jurusan !== '') {
  $filter[] = 'Jurusan ' . $jurusan;
}

if ($status !== '') {
  $filter[] = 'Status ' . $status;
}

$filterTxt = $filter ? implode(' • ', $filter) : 'Semua Kelas';

// ---------------------------------------------------------------------
// IDENTITAS SEKOLAH
// ---------------------------------------------------------------------

$logo = $settings['logo'] ?? '';

$logoUrl = (
  $logo !== '' &&
  is_file(__DIR__ . '/../uploads/siswa/' . $logo)
)
  ? BASE_URL . '/uploads/siswa/' . rawurlencode($logo)
  : null;

// Nomor telepon sekolah.
// Mendukung beberapa kemungkinan nama field pengaturan.
$telepon = $settings['telepon_sekolah']
  ?? $settings['nomor_telepon']
  ?? $settings['telepon']
  ?? '';

// Website sekolah.
$website = $settings['website_sekolah']
  ?? $settings['alamat_web']
  ?? $settings['website']
  ?? '';

// Nama kementerian.
$namaKementerian = 'KEMENTERIAN AGAMA REPUBLIK INDONESIA';

?>
<!DOCTYPE html>
<html lang="id">

<head>

  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title>Data Presensi <?= e($tanggal) ?></title>

  <?= favicon_tag() ?>

  <style>
    /* =========================================================
   PENGATURAN DASAR
========================================================= */

    * {
      box-sizing: border-box;
    }

    body {
      font-family: "Segoe UI", Arial, sans-serif;
      font-size: 12px;
      color: #000;
      margin: 0;
      background: #f1f3f5;
    }

    /* =========================================================
   TOOLBAR
========================================================= */

    .toolbar {
      position: sticky;
      top: 0;
      background: #fff;
      border-bottom: 1px solid #ddd;
      padding: 8px 16px;
      display: flex;
      gap: 8px;
      align-items: center;
      z-index: 100;
    }

    .toolbar button,
    .toolbar a {
      font: inherit;
      padding: 6px 14px;
      border: 1px solid #0d6efd;
      background: #0d6efd;
      color: #fff;
      border-radius: 6px;
      cursor: pointer;
      text-decoration: none;
    }

    .toolbar a {
      background: #fff;
      color: #0d6efd;
    }

    /* =========================================================
   HALAMAN CETAK
========================================================= */

    .halaman {
      width: 210mm;
      max-width: 100%;
      min-height: 297mm;
      margin: 12px auto;
      background: #fff;
      padding: 15mm;
    }

    /* =========================================================
   KOP SURAT
========================================================= */

    .kop {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;

      min-height: 112px;

      padding: 8px 10px 12px;
      margin-bottom: 14px;

      border-bottom: 3px double #000;
    }

    /* LOGO SEKOLAH */

    .kop img {
      position: absolute;
      left: 8px;
      top: 50%;
      transform: translateY(-50%);

      width: 76px;
      height: 76px;

      object-fit: contain;
    }

    /* IDENTITAS SEKOLAH */

    .kop .identitas {
      width: 100%;
      text-align: center;

      padding-left: 65px;
      padding-right: 65px;
    }

    /* KEMENTERIAN */

    .kop .kementerian {
      font-size: 12px;
      font-weight: 500;
      text-transform: uppercase;

      letter-spacing: 0.5px;

      margin: 0 0 3px;
    }

    /* NAMA SEKOLAH */

    .kop h1 {
      font-size: 19px;
      font-weight: 800;

      text-transform: uppercase;
      letter-spacing: 0.4px;

      line-height: 1.3;

      margin: 2px 0 5px;
    }

    /* ALAMAT */

    .kop .alamat {
      font-size: 10.5px;
      line-height: 1.4;
      margin: 2px 0;
    }

    /* TELEPON DAN WEBSITE */

    .kop .kontak {
      font-size: 10px;
      line-height: 1.4;
      margin: 3px 0 2px;
    }

    /* TAHUN PELAJARAN */

    .kop .tahun {
      font-size: 10px;
      margin: 2px 0 0;
    }

    /* =========================================================
   JUDUL LAPORAN
========================================================= */

    h2 {
      font-size: 14px;
      text-align: center;
      margin: 0 0 2px;
      text-transform: uppercase;
    }

    .sub {
      text-align: center;
      margin: 0 0 16px;
      color: #64748b;
    }

    /* =========================================================
       TABEL DATA - TAMPILAN RINGKAS
    ========================================================= */

    table.data {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      table-layout: auto;
      font-size: 12px;
      color: #64748b;
    }

    .data th,
    .data td {
      border: 0;
      padding: 10px 12px;
      text-align: left;
      vertical-align: middle;
    }

    .data th {
      background: #f4f6fb;
      color: #64748b;
      font-weight: 600;
      white-space: nowrap;
    }

    .data thead th:first-child {
      border-radius: 10px 0 0 10px;
    }

    .data thead th:last-child {
      border-radius: 0 10px 10px 0;
    }

    .data tbody td {
      background: #fff;
      border-bottom: 1px solid #f1f3f8;
    }

    .data tbody tr:last-child td {
      border-bottom: 0;
    }

    .data td.c {
      text-align: left;
    }

    .data .kosong {
      text-align: left;
      color: #64748b;
      padding: 12px;
    }

    thead {
      display: table-header-group;
    }

    tr {
      page-break-inside: avoid;
    }

    /* =========================================================
   CATATAN
========================================================= */

    .catatan {
      font-size: 10.5px;
      margin-top: 6px;
    }

    /* =========================================================
   TANDA TANGAN
========================================================= */

    .ttd {
      display: flex;
      justify-content: flex-end;
      margin-top: 28px;
      page-break-inside: avoid;
    }

    .ttd div {
      width: 220px;
      text-align: center;
    }

    .ttd .spasi {
      height: 64px;
    }

    /* =========================================================
   PENGATURAN KERTAS
========================================================= */

    @page {
      size: A4 portrait;
      margin: 0;
    }

    /* =========================================================
   MODE CETAK
========================================================= */

    @media print {

      body {
        background: #fff;
      }

      .toolbar {
        display: none !important;
      }

      .halaman {
        margin: 0;
        width: 210mm;
        min-height: 297mm;
        padding: 15mm;
      }

      * {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      .kop {
        break-inside: avoid;
      }

    }

    /* =========================================================
   RESPONSIVE
========================================================= */

    @media screen and (max-width: 600px) {

      .halaman {
        padding: 15px;
      }

      .kop img {
        width: 55px;
        height: 55px;
        left: 2px;
      }

      .kop .identitas {
        padding-left: 55px;
        padding-right: 55px;
      }

      .kop h1 {
        font-size: 15px;
      }

      .kop .kementerian {
        font-size: 10px;
      }

      .kop .alamat,
      .kop .kontak,
      .kop .tahun {
        font-size: 9px;
      }

    }
  </style>

</head>

<body>

  <!-- =======================================================
     TOOLBAR
======================================================= -->

  <div class="toolbar">

    <button type="button" onclick="window.print()">
      <i class="bi bi-printer"></i>
      Cetak
    </button>

    <a href="javascript:window.close()">
      <i class="bi bi-x-circle"></i>
      Tutup
    </a>

    <span style="color:#6c757d">
      <?= e($tglIndo) ?> — <?= e($filterTxt) ?>
    </span>

  </div>


  <!-- =======================================================
     HALAMAN LAPORAN
======================================================= -->

  <div class="halaman">

    <!-- ===================================================
         KOP SURAT
    ==================================================== -->

    <div class="kop">

      <?php if ($logoUrl): ?>

        <img
          src="<?= e($logoUrl) ?>"
          alt="Logo Sekolah">

      <?php endif; ?>

      <div class="identitas">

        <!-- KEMENTERIAN -->

        <p class="kementerian">
          <?= e($namaKementerian) ?>
        </p>

        <!-- NAMA SEKOLAH -->

        <h1>
          <?= e($settings['nama_sekolah'] ?? 'NAMA SEKOLAH') ?>
        </h1>

        <!-- ALAMAT SEKOLAH -->

        <?php if (!empty($settings['alamat_sekolah'])): ?>

          <p class="alamat">
            <?= e($settings['alamat_sekolah']) ?>
          </p>

        <?php endif; ?>

        <!-- TELEPON DAN WEBSITE -->

        <?php if ($telepon !== '' || $website !== ''): ?>

          <p class="kontak">

            <?php if ($telepon !== ''): ?>

              Telp. <?= e($telepon) ?>

            <?php endif; ?>

            <?php if ($telepon !== '' && $website !== ''): ?>

              &nbsp; | &nbsp;

            <?php endif; ?>

            <?php if ($website !== ''): ?>

              <?= e($website) ?>

            <?php endif; ?>

          </p>

        <?php endif; ?>

        <!-- TAHUN PELAJARAN -->

        <p class="tahun">
          Tahun Pelajaran
          <?= e($settings['tahun_pelajaran'] ?? '-') ?>
        </p>

      </div>

    </div>


    <!-- ===================================================
         JUDUL LAPORAN
    ==================================================== -->

    <h2>
      Data Presensi Siswa
    </h2>

    <p class="sub">
      <?= e($tglIndo) ?>
      <br>
      <?= e($filterTxt) ?>
    </p>


    <!-- ===================================================
         TABEL PRESENSI
    ==================================================== -->

    <table class="data">

      <thead>

        <tr>
          <th style="width:30px">No</th>
          <th>NISN</th>
          <th>Nama</th>
          <th>Kelas</th>
          <th>Jam</th>
          <th>Status</th>
          <th>Metode</th>
        </tr>

      </thead>

      <tbody>

        <?php foreach ($data as $i => $r): ?>

          <tr>

            <td class="c">
              <?= $i + 1 ?>
            </td>

            <td>
              <?= e($r['nisn'] ?: '-') ?>
            </td>

            <td>
              <?= e($r['nama']) ?>
            </td>

            <td class="c">
              <?= e($r['kelas']) ?>
            </td>

            <td class="c">

              <?= in_array(
                $r['metode'],
                ['PENGAJUAN', 'OTOMATIS'],
                true
              )
                ? '—'
                : e(substr((string) $r['jam_masuk'], 0, 5))
              ?>

            </td>

            <td class="c">
              <?= e($r['status']) ?>
            </td>

            <td class="c">
              <?= e($r['metode']) ?>
            </td>

          </tr>

        <?php endforeach; ?>


        <?php if (!$data): ?>

          <tr>
            <td colspan="7" class="kosong">
              Tidak ada data.
            </td>
          </tr>

        <?php endif; ?>

      </tbody>

    </table>


    <!-- ===================================================
         CATATAN LAPORAN
    ==================================================== -->

    <p class="catatan">

      Keterangan:

      <strong>Hadir</strong> sudah termasuk siswa yang terlambat
      (<strong>Terlambat</strong> adalah bagian dari Hadir).

      Dicetak pada

      <?= date('j') . ' ' .
        $namaBulan[(int) date('n')] . ' ' .
        date('Y, H:i') ?>.

    </p>


    <!-- ===================================================
         TANDA TANGAN KEPALA MADRASAH
    ==================================================== -->

    <?php dispensasi_tabel_laporan(dispensasi_daftar_hari($tanggal, $tanggal, 0, $kelas, $jurusan)); ?>
    <div class="ttd">

      <div>

        Mengetahui,<br>
        Kepala Madrasah

        <div class="spasi"></div>

        <?php if (!empty($settings['nama_kepala'])): ?>

          <strong style="text-decoration:underline">
            <?= e($settings['nama_kepala']) ?>
          </strong>

        <?php else: ?>

          (............................................)

        <?php endif; ?>

      </div>

    </div>

  </div>


  <!-- =======================================================
     LOG CETAK
======================================================= -->

  <?= script_log_cetak() ?>


  <!-- =======================================================
     CETAK OTOMATIS
======================================================= -->

  <script>
    setTimeout(function() {
      window.print();
    }, 400);
  </script>

</body>

</html>