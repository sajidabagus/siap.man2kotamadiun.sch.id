<?php
require_once __DIR__ . '/../includes/header.php';

$db = getDB();


$totalSiswa = (int) $db->query('SELECT COUNT(*) c FROM siswa WHERE status = "aktif"')->fetch()['c'];

$today = date('Y-m-d');

$stmt = $db->prepare('SELECT status, COUNT(*) c FROM presensi WHERE tanggal = :t GROUP BY status');
$stmt->execute([':t' => $today]);
$rekapHariIni = ['HADIR' => 0, 'TERLAMBAT' => 0, 'IZIN' => 0, 'SAKIT' => 0, 'ALPA' => 0];
foreach ($stmt->fetchAll() as $row) $rekapHariIni[$row['status']] = (int) $row['c'];

$sudahPresensi = array_sum($rekapHariIni);
// Siswa TERLAMBAT tetap dihitung HADIR (datang, hanya telat).
$hadirTotal = $rekapHariIni['HADIR'] + $rekapHariIni['TERLAMBAT'];
$belumPresensi = max(0, $totalSiswa - $sudahPresensi);

// 7 hari terakhir untuk grafik, ditumpuk per jenjang kelas (X, XI, XII, ...)
function jenjang_dari_kelas(string $kelas): string
{
  $k = strtoupper(trim($kelas));
  if (preg_match('/^(XII|XI|X)(?![A-Z])/', $k, $m)) return $m[1];
  $tok = preg_split('/[\s\-_.]+/', $k)[0] ?? '';
  return $tok !== '' ? $tok : 'LAINNYA';
}
$stmt = $db->prepare(
  "SELECT tanggal, kelas_saat_presensi kelas, COUNT(*) c FROM presensi
   WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND status IN ('HADIR','TERLAMBAT')
   GROUP BY tanggal, kelas_saat_presensi"
);
$stmt->execute();
$perJenjang = []; // [jenjang][tanggal] => jumlah hadir
foreach ($stmt->fetchAll() as $r) {
  $j = jenjang_dari_kelas((string) $r['kelas']);
  $perJenjang[$j][$r['tanggal']] = ($perJenjang[$j][$r['tanggal']] ?? 0) + (int) $r['c'];
}
$urutJenjang = ['X' => 1, 'XI' => 2, 'XII' => 3];
uksort($perJenjang, fn($a, $b) => ($urutJenjang[$a] ?? 99) <=> ($urutJenjang[$b] ?? 99) ?: strcmp($a, $b));

$labels7 = [];
$tanggal7 = [];
for ($i = 6; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime("-$i day"));
  $tanggal7[] = $d;
  $labels7[] = date('d/m', strtotime($d));
}
$seriesJenjang = [];
foreach ($perJenjang as $j => $perTgl) {
  $seriesJenjang[] = ['jenjang' => $j, 'data' => array_map(fn($d) => (int) ($perTgl[$d] ?? 0), $tanggal7)];
}

// Rekap per kelas hari ini
$stmt = $db->prepare(
  "SELECT s.kelas, COALESCE(SUM(p.status IN ('HADIR','TERLAMBAT')), 0) hadir, (SELECT COUNT(*) FROM siswa s2 WHERE s2.kelas = s.kelas AND s2.status='aktif') total
     FROM siswa s LEFT JOIN presensi p ON p.siswa_id = s.id AND p.tanggal = :t
     WHERE s.status = 'aktif' GROUP BY s.kelas ORDER BY s.kelas"
);
$stmt->execute([':t' => $today]);
$rekapKelas = $stmt->fetchAll();
$cfg = get_settings();
$tidakHadir = $rekapHariIni['IZIN'] + $rekapHariIni['SAKIT'] + $rekapHariIni['ALPA'];
$persenHadir = $totalSiswa ? (int) round($hadirTotal / $totalSiswa * 100) : 0;
$efektifHariIni = is_hari_efektif();
$warnaPersen = $persenHadir >= 90 ? '#2fb344' : ($persenHadir >= 70 ? '#d9631e' : ($persenHadir >= 50 ? '#e8a200' : '#e03e4a'));

// Total siswa aktif per jenjang
$jenjangTotal = [];
foreach ($db->query("SELECT kelas, COUNT(*) c FROM siswa WHERE status='aktif' GROUP BY kelas")->fetchAll() as $r) {
  $j = jenjang_dari_kelas((string) $r['kelas']);
  $jenjangTotal[$j] = ($jenjangTotal[$j] ?? 0) + (int) $r['c'];
}
uksort($jenjangTotal, fn($a, $b) => ($urutJenjang[$a] ?? 99) <=> ($urutJenjang[$b] ?? 99) ?: strcmp($a, $b));
$warnaJenjang = ['#8ebfff', '#8ae3aa', '#fbe07d', '#f5a3b5', '#c4b0f5', '#9fdde6'];
$ringJenjang = []; $mulai = 0; $i = 0;
foreach ($jenjangTotal as $j => $n) {
  $akhir = $mulai + ($totalSiswa ? $n / $totalSiswa * 100 : 0);
  $ringJenjang[] = $warnaJenjang[$i % count($warnaJenjang)] . ' ' . round($mulai, 2) . '% ' . round($akhir, 2) . '%';
  $mulai = $akhir; $i++;
}
$ringJenjangCss = $ringJenjang ? implode(', ', $ringJenjang) : '#e9edf5 0 100%';
$tglHariIni = NAMA_HARI[(int) date('N')] . ', ' . date('j') . ' ' . ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][(int) date('n')] . ' ' . date('Y');
$namaAdmin = (current_admin()['nama'] ?? '') ?: 'Admin';
?>

<style>
  .dash-hero { border: 0; border-radius: 16px; overflow: hidden; box-shadow: 0 2px 10px rgba(20,33,61,.06); }
  .dash-hero-top { background: linear-gradient(135deg, #2bb673, #46d08c); color: #fff; padding: 26px 26px 96px; position: relative; }
  .dash-hero-top h3 { font-weight: 700; margin: 0 0 4px; }
  .dash-hero-top p { margin: 0; opacity: .95; }
  .dash-hero-top p b { border-bottom: 2px solid rgba(255,255,255,.7); }
  .dash-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; padding: 0 26px 26px; margin-top: -68px; position: relative; }
  .dash-tile { background: #fafbfc; border: 1px solid #eef1f6; border-radius: 12px; padding: 16px 18px; }
  .dash-tile i { font-size: 22px; }
  .dash-tile .angka { font-size: 2rem; font-weight: 700; color: #3b4560; line-height: 1.1; margin-top: 10px; }
  .dash-tile .ket { color: #9aa3b5; font-size: .92rem; }
  .dash-card { border: 1px solid #eef1f6; border-radius: 16px; overflow: hidden; box-shadow: 0 2px 10px rgba(20,33,61,.04); }
  .dash-card h6 { font-weight: 700; color: #1b2437; margin: 0; }
  .dash-card .sub { color: #9aa3b5; font-size: .85rem; font-weight: 600; }
  /* Seragamkan elemen di dalam kartu dashboard dengan gaya baru */
  .dash-card .card-body { padding: 1.25rem 1.35rem; }
  .dash-card h6 { font-size: 1rem; }
  .dash-card .btn, .pesan-banner .btn { border-radius: 10px; font-weight: 600; }
  .dash-card .btn-group .btn { border-radius: 10px; }
  .dash-card .btn-group .btn:first-child { border-top-right-radius: 0; border-bottom-right-radius: 0; }
  .dash-card .btn-group .btn:last-child { border-top-left-radius: 0; border-bottom-left-radius: 0; }
  .dash-card .btn-light { background: #f4f6fa; border-color: #f4f6fa; color: #3b4560; }
  .dash-card .btn-light:hover { background: #e9edf5; border-color: #e9edf5; }
  .dash-card .badge { border-radius: 8px; font-weight: 600; }
  .dash-card .badge.rounded-pill { border-radius: 999px; }
  .dash-card .list-group { border-radius: 12px; }
  .dash-card .list-group-item { border-color: #eef1f6; }
  .dash-card .table > :not(caption) > * > * { border-color: #eef1f6; }
  .dash-card thead th { color: #9aa3b5; font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
  .ring { --p: 0; --c: #0d6efd; width: 96px; height: 96px; border-radius: 50%; flex: none; display: grid; place-items: center;
          background: conic-gradient(var(--c) calc(var(--p) * 1%), #e9edf5 0); }
  .ring::before { content: ""; grid-area: 1/1; width: 74px; height: 74px; border-radius: 50%; background: #fff; }
  .ring span { grid-area: 1/1; z-index: 1; font-weight: 700; font-size: 1.25rem; color: #1b2437; }
  .ring-multi { background: conic-gradient(var(--segmen)); }
  .meta-list { list-style: none; padding: 0; margin: 0; font-size: .92rem; color: #5b6580; }
  .meta-list li { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; font-weight: 600; }
  .meta-list .dot { width: 9px; height: 9px; border-radius: 50%; flex: none; }
  .pesan-banner { background: #1c2f5a; color: #fff; border-radius: 16px; padding: 26px 28px; position: relative; overflow: hidden; }
  .pesan-banner h5 { font-weight: 700; line-height: 1.4; margin-bottom: 8px; }
  .pesan-banner .hl { color: #ff3b6b; border-bottom: 2px solid #b0294d; padding-bottom: 1px; }
  .pesan-banner p { color: #c9d3ea; margin-bottom: 16px; }
  .pesan-banner .bg-icon { position: absolute; right: -10px; bottom: -22px; font-size: 130px; color: rgba(255,255,255,.07); }
  .btn-pink { background: #ff2d63; color: #fff; border: 0; } .btn-pink:hover { background: #e8235a; color: #fff; }
  .btn-slate { background: #3a4d78; color: #fff; border: 0; } .btn-slate:hover { background: #46598a; color: #fff; }
  .info-item { display: flex; gap: 12px; align-items: flex-start; padding: 9px 0; border-bottom: 1px dashed #e6eaf2; }
  .info-item:last-child { border-bottom: 0; }
  .info-item i { font-size: 18px; margin-top: 1px; }
  @media (max-width: 575px) { .dash-hero-top { padding: 22px 20px 90px; } .dash-tiles { padding: 0 16px 20px; gap: 10px; } .dash-tile { padding: 14px; } }
</style>

<h4 class="mb-3"><i class="bi bi-speedometer2"></i> Dashboard</h4>

<div class="row g-3 mb-3">
  <!-- KIRI: presensi hari ini -->
  <div class="col-lg-5">
    <div class="card dash-hero h-100">
      <div class="dash-hero-top">
        <h3>Presensi Hari Ini</h3>
        <p><?= e($tglHariIni) ?></p>
        <p>Sudah presensi: <b><?= (int) $sudahPresensi ?></b> dari <?= (int) $totalSiswa ?> siswa</p>
      </div>
      <div class="dash-tiles">
        <div class="dash-tile"><i class="bi bi-check-circle-fill" style="color:#2fb344"></i><div class="angka"><?= (int) $hadirTotal ?></div><div class="ket">Hadir</div></div>
        <div class="dash-tile"><i class="bi bi-clock-fill" style="color:#f08c1a"></i><div class="angka"><?= (int) $rekapHariIni['TERLAMBAT'] ?></div><div class="ket">Terlambat</div></div>
        <div class="dash-tile"><i class="bi bi-hourglass-split" style="color:#6c7a96"></i><div class="angka"><?= (int) $belumPresensi ?></div><div class="ket">Belum Presensi</div></div>
        <div class="dash-tile"><i class="bi bi-x-circle-fill" style="color:#e03e4a"></i><div class="angka"><?= (int) $tidakHadir ?></div><div class="ket">Tidak Hadir <span style="font-size:.75rem">(izin, sakit, alpa)</span></div></div>
      </div>
    </div>
  </div>

  <!-- KANAN: 2 kotak atas + 2 kotak pesan -->
  <div class="col-lg-7 d-flex flex-column gap-3">
    <div class="row g-3">
      <div class="col-md-6">
        <div class="card dash-card h-100"><div class="card-body">
          <h6>Total Siswa</h6>
          <div class="sub mb-3">Siswa aktif per jenjang</div>
          <div class="d-flex align-items-center gap-3">
            <div class="ring ring-multi" style="--segmen: <?= e($ringJenjangCss) ?>"><span><?= (int) $totalSiswa ?></span></div>
            <ul class="meta-list">
              <?php $ix = 0; foreach ($jenjangTotal as $j => $n): ?>
                <li><span class="dot" style="background:<?= e($warnaJenjang[$ix++ % count($warnaJenjang)]) ?>"></span> Kelas <?= e($j) ?>: <?= (int) $n ?></li>
              <?php endforeach; ?>
              <?php if (!$jenjangTotal): ?><li>Belum ada siswa aktif</li><?php endif; ?>
            </ul>
          </div>
          <a href="<?= modul_url('siswa') ?>" class="btn btn-sm btn-light mt-3">Data Siswa</a>
        </div></div>
      </div>
      <div class="col-md-6">
        <div class="card dash-card h-100"><div class="card-body">
          <h6>Persentase Kehadiran</h6>
          <div class="sub mb-3">Hari ini (terlambat dihitung hadir)</div>
          <div class="d-flex align-items-center gap-3">
            <div class="ring" style="--p: <?= (int) $persenHadir ?>; --c: <?= e($warnaPersen) ?>"><span><?= (int) $persenHadir ?>%</span></div>
            <ul class="meta-list">
              <li><span class="dot" style="background:#2fb344"></span> Hadir: <?= (int) $hadirTotal ?></li>
              <li><span class="dot" style="background:#e03e4a"></span> Tidak hadir: <?= (int) $tidakHadir ?></li>
              <li><span class="dot" style="background:#9aa3b5"></span> Belum: <?= (int) $belumPresensi ?></li>
            </ul>
          </div>
          <a href="<?= modul_url('presensi') ?>" class="btn btn-sm btn-light mt-3">Lihat Presensi</a>
        </div></div>
      </div>
    </div>

    <div class="row g-3 flex-grow-1">
      <!-- Pesan 1: sambutan & ringkasan -->
      <div class="col-md-7">
        <div class="pesan-banner h-100">
          <i class="bi bi-chat-heart bg-icon"></i>
          <h5>Halo, <?= e($namaAdmin) ?>!
            <?php if (!$efektifHariIni): ?>
              Hari ini <span class="hl"><?= e(NAMA_HARI[(int) date('N')]) ?></span> bukan hari efektif presensi.
            <?php else: ?>
              Sudah <span class="hl"><?= (int) $hadirTotal ?> siswa</span> hadir hari ini<?= $belumPresensi > 0 ? ', dan ' . (int) $belumPresensi . ' siswa belum presensi.' : '.' ?>
            <?php endif; ?>
          </h5>
          <p><?= $efektifHariIni ? 'Pantau kehadiran secara langsung dan tindak lanjuti siswa yang belum presensi.' : 'Presensi siswa ditutup. Hari efektif: ' . e(label_hari_efektif()) . '.' ?></p>
          <a href="<?= modul_url('presensi') ?>" class="btn btn-pink btn-sm px-3">Lihat Presensi</a>
          <a href="<?= modul_url('rekap') ?>" class="btn btn-slate btn-sm px-3">Rekap Bulanan</a>
        </div>
      </div>
      <!-- Pesan 2: info & pengingat -->
      <div class="col-md-5">
        <div class="card dash-card h-100"><div class="card-body">
          <h6 class="mb-2">Info &amp; Pengingat</h6>
          <div class="info-item"><i class="bi bi-alarm text-primary"></i><div class="small">Presensi mulai <b><?= e(substr((string) ($cfg['jam_masuk'] ?? '07:00'), 0, 5)) ?></b>, terlambat setelah <b><?= e(substr((string) ($cfg['batas_terlambat'] ?? '07:15'), 0, 5)) ?></b>.</div></div>
          <div class="info-item"><i class="bi bi-geo-alt <?= !empty($cfg['gps_aktif']) ? 'text-success' : 'text-secondary' ?>"></i><div class="small">Validasi lokasi GPS <?= !empty($cfg['gps_aktif']) ? '<b>aktif</b>' : '<b>nonaktif</b>' ?>.</div></div>
          <div class="info-item"><i class="bi bi-phone <?= !empty($cfg['device_binding_aktif']) ? 'text-success' : 'text-secondary' ?>"></i><div class="small">Device binding <?= !empty($cfg['device_binding_aktif']) ? '<b>aktif</b>' : '<b>nonaktif</b>' ?>.</div></div>
        </div></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card dash-card h-100">
      <div class="card-body d-flex flex-column">
        <h6>Kehadiran 7 Hari Terakhir</h6>
        <div class="sub mb-2">Per jenjang kelas</div>
        <div class="flex-grow-1 position-relative" style="min-height:260px">
          <canvas id="chart7Hari"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card dash-card h-100">
      <div class="card-body">
        <h6>Rekap Per Kelas</h6>
        <div class="sub mb-2">Hari ini (terlambat dihitung hadir)</div>
        <div class="table-responsive">
        <table class="table table-sm mb-2" id="tblRekapKelas">
          <thead>
            <tr>
              <th>Kelas</th>
              <th>Hadir</th>
              <th>Total</th>
              <th>%</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rekapKelas as $k): ?>
              <tr>
                <td><?= e($k['kelas']) ?></td>
                <td><?= (int)$k['hadir'] ?></td>
                <td><?= (int)$k['total'] ?></td>
                <?php
                  $pct = $k['total'] ? (int) round($k['hadir'] / $k['total'] * 100) : 0;
                  $kelasPct = $pct >= 90 ? 'pct-hijau' : ($pct >= 70 ? 'pct-oranye' : ($pct >= 50 ? 'pct-kuning' : 'pct-merah'));
                ?>
                <td><span class="pct-badge <?= $kelasPct ?>"><?= $pct ?>%</span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2" id="pagerRekapKelas">
          <span class="text-muted small" id="infoRekapKelas"></span>
          <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-secondary" id="prevRekapKelas" aria-label="Sebelumnya"><i class="bi bi-chevron-left"></i></button>
            <button type="button" class="btn btn-outline-secondary" id="nextRekapKelas" aria-label="Berikutnya"><i class="bi bi-chevron-right"></i></button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
// ===== Histori absensi terbaru & rekor absensi =====
$stHist = $db->query(
    "SELECT p.tanggal, p.jam_masuk, p.status, p.metode, p.kelas_saat_presensi AS kelas, s.nama
     FROM presensi p JOIN siswa s ON s.id = p.siswa_id
     ORDER BY p.tanggal DESC, p.jam_masuk DESC, p.id DESC LIMIT 10"
);
$histori = $stHist->fetchAll();

$awalBulan = date('Y-m-01');
$hadirSql = "p.status IN ('HADIR','TERLAMBAT')";

// Rekor 1: hari dengan kehadiran terbanyak sepanjang waktu
$rekorHari = $db->query("SELECT p.tanggal, COUNT(*) c FROM presensi p WHERE $hadirSql GROUP BY p.tanggal ORDER BY c DESC, p.tanggal DESC LIMIT 1")->fetch();

// Rekor 2: presensi tercepat hari ini
$stCepat = $db->prepare("SELECT p.jam_masuk, s.nama, p.kelas_saat_presensi AS kelas FROM presensi p JOIN siswa s ON s.id = p.siswa_id WHERE p.tanggal = :t AND $hadirSql ORDER BY p.jam_masuk ASC LIMIT 1");
$stCepat->execute([':t' => $today]);
$tercepat = $stCepat->fetch();

// Rekor 3: 5 siswa paling rajin bulan ini (hadir terbanyak, terlambat lebih sedikit menang)
$stRajin = $db->prepare(
    "SELECT s.nama, p.kelas_saat_presensi AS kelas, COUNT(*) hadir, SUM(p.status='TERLAMBAT') telat
     FROM presensi p JOIN siswa s ON s.id = p.siswa_id
     WHERE p.tanggal >= :awal AND $hadirSql
     GROUP BY s.id, s.nama, p.kelas_saat_presensi ORDER BY hadir DESC, telat ASC, s.nama ASC LIMIT 5"
);
$stRajin->execute([':awal' => $awalBulan]);
$siswaRajin = $stRajin->fetchAll();

// Rekor 4: 3 kelas dengan persentase kehadiran terbaik bulan ini
$stHariAktif = $db->prepare('SELECT COUNT(DISTINCT tanggal) FROM presensi WHERE tanggal >= :awal');
$stHariAktif->execute([':awal' => $awalBulan]);
$hariAktifBulan = (int) $stHariAktif->fetchColumn();
$kelasTerbaik = [];
if ($hariAktifBulan > 0) {
    $stKT = $db->prepare(
        "SELECT k.kelas, k.jml, COALESCE(h.hadir, 0) hadir
         FROM (SELECT kelas, COUNT(*) jml FROM siswa WHERE status='aktif' GROUP BY kelas) k
         LEFT JOIN (SELECT p.kelas_saat_presensi kelas, COUNT(*) hadir FROM presensi p
                    WHERE p.tanggal >= :awal AND $hadirSql GROUP BY p.kelas_saat_presensi) h ON h.kelas = k.kelas"
    );
    $stKT->execute([':awal' => $awalBulan]);
    foreach ($stKT->fetchAll() as $k) {
        $k['persen'] = $k['jml'] > 0 ? round($k['hadir'] / ($k['jml'] * $hariAktifBulan) * 100, 1) : 0;
        $kelasTerbaik[] = $k;
    }
    usort($kelasTerbaik, fn($a, $b) => $b['persen'] <=> $a['persen']);
    $kelasTerbaik = array_slice($kelasTerbaik, 0, 3);
}
$bulanIndo = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$warnaStatus = ['HADIR' => 'success', 'TERLAMBAT' => 'warning', 'IZIN' => 'info', 'SAKIT' => 'info', 'ALPA' => 'danger'];
?>
<div class="row g-3 mt-1">
  <div class="col-lg-8">
    <div class="card dash-card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="mb-0"><i class="bi bi-clock-history"></i> Histori Absensi Terbaru</h6>
          <a href="<?= modul_url('presensi') ?>" class="small">Lihat semua &raquo;</a>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Waktu</th><th>Nama</th><th>Kelas</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($histori as $h): ?>
                <tr>
                  <td class="text-nowrap small"><?= e(date('d/m', strtotime($h['tanggal']))) ?> <span class="text-muted"><?= $h['metode'] === 'PENGAJUAN' ? '—' : e(substr((string) $h['jam_masuk'], 0, 5)) ?></span></td>
                  <td class="small"><?= e($h['nama']) ?></td>
                  <td class="small text-nowrap"><?= e($h['kelas']) ?></td>
                  <td><span class="badge bg-<?= $warnaStatus[$h['status']] ?? 'secondary' ?>"><?= e($h['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$histori): ?><tr><td colspan="4" class="text-center text-muted py-3">Belum ada data absensi.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card dash-card h-100">
      <div class="card-body">
        <h6 class="mb-3"><i class="bi bi-trophy"></i> Rekor Absensi</h6>

        <div class="d-flex align-items-start gap-3 mb-3">
          <div class="fs-4 text-warning"><i class="bi bi-calendar-star"></i></div>
          <div>
            <div class="small text-muted">Kehadiran terbanyak dalam sehari</div>
            <?php if ($rekorHari): ?>
              <strong><?= (int) $rekorHari['c'] ?> siswa</strong>
              <span class="text-muted small">— <?= e(date('d', strtotime($rekorHari['tanggal'])) . ' ' . $bulanIndo[(int) date('n', strtotime($rekorHari['tanggal']))] . ' ' . date('Y', strtotime($rekorHari['tanggal']))) ?></span>
            <?php else: ?><span class="text-muted small">Belum ada data</span><?php endif; ?>
          </div>
        </div>

        <div class="d-flex align-items-start gap-3 mb-3">
          <div class="fs-4 text-primary"><i class="bi bi-lightning-charge"></i></div>
          <div>
            <div class="small text-muted">Presensi tercepat hari ini</div>
            <?php if ($tercepat): ?>
              <strong><?= e($tercepat['nama']) ?></strong>
              <span class="text-muted small">— <?= e($tercepat['kelas']) ?>, <?= e(substr((string) $tercepat['jam_masuk'], 0, 5)) ?></span>
            <?php else: ?><span class="text-muted small">Belum ada yang presensi hari ini</span><?php endif; ?>
          </div>
        </div>

        <div class="small text-muted mb-1"><i class="bi bi-star"></i> Siswa paling rajin — <?= e($bulanIndo[(int) date('n')]) ?></div>
        <ol class="list-group list-group-numbered mb-3">
          <?php foreach ($siswaRajin as $r): ?>
            <li class="list-group-item d-flex justify-content-between align-items-start py-1">
              <div class="ms-2 me-auto small"><?= e($r['nama']) ?> <span class="text-muted">(<?= e($r['kelas']) ?>)</span></div>
              <span class="badge bg-success rounded-pill"><?= (int) $r['hadir'] ?>x hadir</span>
            </li>
          <?php endforeach; ?>
          <?php if (!$siswaRajin): ?><li class="list-group-item small text-muted">Belum ada data bulan ini.</li><?php endif; ?>
        </ol>

        <div class="small text-muted mb-1"><i class="bi bi-people"></i> Kelas dengan kehadiran terbaik — <?= e($bulanIndo[(int) date('n')]) ?></div>
        <ol class="list-group list-group-numbered">
          <?php foreach ($kelasTerbaik as $k): ?>
            <li class="list-group-item d-flex justify-content-between align-items-start py-1">
              <div class="ms-2 me-auto small"><?= e($k['kelas']) ?></div>
              <span class="badge bg-primary rounded-pill"><?= e($k['persen']) ?>%</span>
            </li>
          <?php endforeach; ?>
          <?php if (!$kelasTerbaik): ?><li class="list-group-item small text-muted">Belum ada data bulan ini.</li><?php endif; ?>
        </ol>
      </div>
    </div>
  </div>
</div>

<style>
  /* Persentase rekap per kelas: titik berwarna + angka tebal (hijau / oranye / kuning / merah) */
  .pct-badge { display: inline-flex; align-items: center; gap: 8px; font-weight: 700; font-size: 1.05rem; white-space: nowrap; }
  .pct-badge::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: currentColor; flex: none; }
  .pct-hijau  { color: #2fb344; }
  .pct-oranye { color: #d9631e; }
  .pct-kuning { color: #e8a200; }
  .pct-merah  { color: #e03e4a; }
</style>
<script>
// Paginasi rekap per kelas (di sisi browser, tanpa reload). Jumlah baris per
// halaman menyesuaikan tinggi layar: layar kecil = sedikit, layar besar = lebih banyak.
(function () {
  var rows = Array.prototype.slice.call(document.querySelectorAll('#tblRekapKelas tbody tr'));
  var info = document.getElementById('infoRekapKelas');
  var prev = document.getElementById('prevRekapKelas');
  var next = document.getElementById('nextRekapKelas');
  var page = 1;

  function perPage() {
    var n = Math.floor((window.innerHeight - 460) / 38);
    return Math.max(4, Math.min(12, n));
  }

  function render() {
    var size = perPage();
    var pages = Math.max(1, Math.ceil(rows.length / size));
    page = Math.min(Math.max(page, 1), pages);
    var start = (page - 1) * size;
    rows.forEach(function (r, i) { r.style.display = (i >= start && i < start + size) ? '' : 'none'; });
    info.textContent = rows.length
      ? (start + 1) + '–' + Math.min(start + size, rows.length) + ' dari ' + rows.length + ' kelas'
      : 'Belum ada data kelas';
    prev.disabled = page <= 1;
    next.disabled = page >= pages;
    document.getElementById('pagerRekapKelas').style.display = pages > 1 ? '' : 'none';
  }

  prev.addEventListener('click', function () { page--; render(); });
  next.addEventListener('click', function () { page++; render(); });
  window.addEventListener('resize', render);
  render();
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
  (function () {
    var labels = <?= json_encode($labels7) ?>;
    var series = <?= json_encode($seriesJenjang) ?>;
    // Warna pastel bertumpuk (bawah -> atas): biru, hijau, kuning, lalu cadangan
    var warna = ['#8ebfff', '#8ae3aa', '#fbe07d', '#f5a3b5', '#c4b0f5', '#9fdde6'];
    var datasets = series.map(function (s, i) {
      return {
        label: 'Kelas ' + s.jenjang,
        data: s.data,
        fill: true,
        backgroundColor: warna[i % warna.length],
        borderColor: warna[i % warna.length],
        borderWidth: 0,
        tension: .4,
        pointRadius: 0,
        pointHoverRadius: 5,
        pointHoverBackgroundColor: '#fff',
        pointHoverBorderWidth: 2,
        stack: 'hadir'
      };
    });
    // Garis vertikal putus-putus yang mengikuti kursor/jari saat menyentuh grafik
    var garisPengikut = {
      id: 'garisPengikut',
      afterDatasetsDraw: function (chart) {
        var tt = chart.tooltip;
        if (!tt || !tt.getActiveElements().length) return;
        var x = tt.getActiveElements()[0].element.x;
        var a = chart.chartArea, ctx = chart.ctx;
        ctx.save();
        ctx.beginPath();
        ctx.setLineDash([4, 4]);
        ctx.lineWidth = 1;
        ctx.strokeStyle = 'rgba(27,36,55,.75)';
        ctx.moveTo(x, a.top);
        ctx.lineTo(x, a.bottom);
        ctx.stroke();
        ctx.restore();
      }
    };
    new Chart(document.getElementById('chart7Hari'), {
      type: 'line',
      plugins: [garisPengikut],
      data: { labels: labels, datasets: datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, color: '#6b7690' } },
          tooltip: {
            usePointStyle: true,
            boxPadding: 4,
            padding: 10,
            cornerRadius: 8,
            callbacks: {
              footer: function (items) {
                var t = items.reduce(function (a, it) { return a + it.parsed.y; }, 0);
                return 'Total hadir: ' + t;
              }
            }
          }
        },
        scales: {
          x: { grid: { display: false }, border: { display: false }, ticks: { color: '#8a94a6' } },
          y: {
            stacked: true,
            beginAtZero: true,
            border: { display: false },
            grid: { color: 'rgba(138,148,166,.25)', borderDash: [4, 4] },
            ticks: { color: '#8a94a6', precision: 0 }
          }
        }
      }
    });
  })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>