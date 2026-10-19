<?php
require_once __DIR__ . '/../includes/presensi_core.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$status = clean($_GET['status'] ?? 'error');
$code = clean($_GET['code'] ?? '');
$msgFromQuery = clean($_GET['msg'] ?? '');

$result = null;
$ringkasan = null;
$sudahAda = false; // true jika status='already' (scan ulang di hari yang sama)

if ($status === 'ok' && !empty($_SESSION['presensi_result'])) {
    $result = $_SESSION['presensi_result'];
    unset($_SESSION['presensi_result']);
    if (!empty($result['siswa_id'])) {
        $ringkasan = get_ringkasan_bulanan_siswa((int) $result['siswa_id'], $result['tanggal'] ?? date('Y-m-d'));
    }
} elseif ($status === 'already' && !empty($_SESSION['identified_siswa_id'])) {
    // Siswa scan ulang di hari yang sama -> tampilkan data presensi yang SUDAH tercatat sebelumnya.
    $siswaId = (int) $_SESSION['identified_siswa_id'];
    $stmt = getDB()->prepare('SELECT * FROM siswa WHERE id = :id');
    $stmt->execute([':id' => $siswaId]);
    $siswa = $stmt->fetch();
    $presensiHariIni = sudah_presensi_hari_ini($siswaId);

    if ($siswa && $presensiHariIni) {
        $sudahAda = true;
        $result = [
            'siswa_id' => $siswaId, 'nama' => $siswa['nama'], 'nis' => $siswa['nis'],
            'kelas' => $siswa['kelas'], 'foto' => $siswa['foto'], 'status_siswa' => $siswa['status'],
            'tanggal' => $presensiHariIni['tanggal'], 'jam' => $presensiHariIni['jam_masuk'],
            'status' => $presensiHariIni['status'], 'device_new' => false,
        ];
        $ringkasan = get_ringkasan_bulanan_siswa($siswaId, $presensiHariIni['tanggal']);
    }
    unset($_SESSION['identified_siswa_id'], $_SESSION['gate_token'], $_SESSION['gate_verified_at']);
}

$pesanKhusus = [
    'MISMATCH'        => '❌ Perangkat tidak terdaftar. Gunakan perangkat yang telah didaftarkan untuk presensi.',
    'DEVICE_ALREADY_BOUND' => '❌ Perangkat ini sudah terdaftar untuk siswa lain. Satu perangkat hanya dapat digunakan oleh satu siswa.',
    'BLOCKED'         => '🚫 Perangkat ini diblokir. Silakan hubungi administrator sekolah.',
    'ALREADY_PRESENT' => '✓ Anda sudah melakukan presensi hari ini.',
    'GATE_INVALID'    => 'Sesi QR gerbang sudah kedaluwarsa. Silakan scan ulang QR di sekolah.',
    'OUT_OF_RANGE'    => 'Presensi hanya dapat dilakukan di area sekolah.',
    'GPS_REQUIRED'    => 'Lokasi perangkat tidak terdeteksi. Aktifkan GPS dan izinkan akses lokasi.',
    'INACTIVE'        => 'Status siswa tidak aktif. Hubungi admin sekolah.',
    'BELUM_WAKTUNYA'  => $msgFromQuery ?: 'Presensi belum dibuka.',
];
$pesan = $pesanKhusus[$code] ?? ($msgFromQuery ?: 'Terjadi kesalahan. Silakan coba lagi.');

// Pesan motivasi berdasarkan persentase kehadiran bulan ini.
function pesan_motivasi(float $persen): array
{
    if ($persen >= 90) return ['Pertahankan konsistensi!', 'Kehadiranmu sangat baik bulan ini. Terus jaga kedisiplinan ya!', 'bi-trophy'];
    if ($persen >= 75) return ['Sudah cukup baik!', 'Sedikit lagi menuju kehadiran sempurna. Semangat terus!', 'bi-graph-up-arrow'];
    return ['Ayo tingkatkan lagi!', 'Jangan lupa presensi tepat waktu setiap hari ya.', 'bi-alarm'];
}
?>
<?php
$settingsHal = get_settings();
$logoFile = $settingsHal['logo'] ?? '';
$logoUrl = ($logoFile !== '' && is_file(__DIR__ . '/../uploads/siswa/' . $logoFile)) ? BASE_URL . '/uploads/siswa/' . rawurlencode($logoFile) : null;
$berhasilTampil = ($status === 'ok' || $status === 'already') && $result;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $berhasilTampil ? ($sudahAda ? 'Sudah Presensi' : 'Presensi Berhasil') : 'Presensi Tidak Berhasil' ?></title>
<?= favicon_tag() ?>
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/presensi.css?v=<?= @filemtime(__DIR__ . '/../assets/css/presensi.css') ?: time() ?>" rel="stylesheet">
</head>
<body class="presensi-page">
<div class="presensi-wrap ps">

  <div class="ps-brand">
    <div class="ps-logo"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt=""><?php else: ?><i class="bi bi-mortarboard-fill"></i><?php endif; ?></div>
    <div>
      <div class="n"><?= e($settingsHal['nama_sekolah'] ?? 'Sekolah') ?></div>
      <div class="s">ABSENSI SISWA</div>
    </div>
  </div>

<?php if ($berhasilTampil): ?>

  <div class="ps-steps">
    <div class="ps-st done"><span>1</span><em>Scan QR Sekolah</em></div>
    <div class="ps-st done"><span>2</span><em>Verifikasi</em></div>
    <div class="ps-st done"><span>3</span><em>Konfirmasi</em></div>
  </div>

  <div class="hs-hero">
    <div class="hs-ring <?= $sudahAda ? 'biru' : 'hijau' ?>">
      <svg viewBox="0 0 100 100" aria-hidden="true">
        <circle class="isi" cx="50" cy="50" r="44"/>
        <circle class="lingkar" cx="50" cy="50" r="46" transform="rotate(-90 50 50)"/>
        <?php if ($sudahAda): ?>
          <path class="centang" d="M50 30 L50 54 M50 68 L50 69"/>
        <?php else: ?>
          <path class="centang" d="M30 52 L44 66 L70 36"/>
        <?php endif; ?>
      </svg>
    </div>
    <?php if ($sudahAda): ?>
      <h1>Anda Sudah Presensi Hari Ini</h1>
      <p>Kehadiran hari ini sudah tercatat sebelumnya, tidak perlu scan ulang.</p>
    <?php else: ?>
      <?php $pesanSuksesTampil = $msgFromQuery !== '' ? $msgFromQuery : 'Terima kasih, kehadiran Anda telah dicatat.'; ?>
      <h1>Presensi Berhasil!</h1>
      <p><?= e($pesanSuksesTampil) ?></p>
    <?php endif; ?>
  </div>

  <?php
    $kata = preg_split('/\s+/', trim((string) $result['nama']));
    $inisial = mb_strtoupper(mb_substr($kata[0] ?? '', 0, 1) . (isset($kata[1]) ? mb_substr($kata[1], 0, 1) : ''));
    $isHadir = $result['status'] === 'HADIR';
  ?>
  <div class="hs-struk">
    <div class="hs-id">
      <?php if (!empty($result['foto'])): ?>
        <img src="<?= BASE_URL ?>/uploads/siswa/<?= e($result['foto']) ?>" class="foto" alt="">
      <?php else: ?>
        <div class="foto"><?= e($inisial) ?></div>
      <?php endif; ?>
      <div>
        <div class="nm"><?= e($result['nama']) ?></div>
        <div class="sb">NIS <?= e($result['nis']) ?> &middot; Kelas <?= e($result['kelas']) ?></div>
      </div>
    </div>
    <hr class="hs-garis">
    <div class="hs-rinci">
      <div class="r"><span><i class="bi bi-calendar3"></i> Tanggal</span><span><?= e(format_tanggal_indonesia($result['tanggal'])) ?></span></div>
      <div class="r"><span><i class="bi bi-clock"></i> Waktu Presensi</span><span><?= e(substr($result['jam'], 0, 5)) ?> <small class="text-muted fw-normal">WIB</small></span></div>
      <div class="r"><span><i class="bi bi-person-check"></i> Status</span>
        <span><span class="hs-lencana <?= $isHadir ? 'hadir' : 'telat' ?>"><i class="bi bi-<?= $isHadir ? 'check-circle-fill' : 'clock-fill' ?>"></i> <?= $isHadir ? 'Tepat Waktu' : 'Terlambat' ?></span></span>
      </div>
    </div>
  </div>

  <?php if (!empty($result['device_new'])): ?>
    <div class="siap-note siap-note-info" style="margin:0 0 14px"><i class="bi bi-phone"></i><div>Perangkat ini kini terdaftar sebagai perangkat presensi utama Anda.</div></div>
  <?php endif; ?>

  <?php if ($ringkasan): ?>
  <div class="hs-kartu">
    <h2>Ringkasan Bulan Ini</h2>
    <div class="sub">Rekap kehadiran Anda (terlambat dihitung hadir).</div>
    <div class="d-flex justify-content-between align-items-end">
      <span class="small text-muted fw-bold">Tingkat kehadiran</span>
      <span class="fw-bold" style="font-size:1.25rem"><?= e((string) round((float) $ringkasan['persentase'], 1)) ?>%</span>
    </div>
    <div class="hs-bar"><span style="width:<?= min(100, max(0, (float) $ringkasan['persentase'])) ?>%"></span></div>
    <div class="hs-grid">
      <div class="hs-stat h"><div class="a"><?= (int) $ringkasan['hadir'] ?></div><div class="l">Hadir<small>termasuk terlambat</small></div></div>
      <div class="hs-stat t"><div class="a"><?= (int) $ringkasan['terlambat'] ?></div><div class="l">Terlambat<small>sudah dihitung hadir</small></div></div>
      <div class="hs-stat x"><div class="a"><?= (int) $ringkasan['tidak_hadir'] ?></div><div class="l">Tidak Hadir</div></div>
      <div class="hs-stat b"><div class="a"><?= (int) $ringkasan['total_hari'] ?></div><div class="l">Total Hari</div></div>
    </div>
  </div>

  <?php [$tipJudul, $tipTeks, $tipIcon] = pesan_motivasi((float) $ringkasan['persentase']); ?>
  <div class="hs-tip">
    <i class="bi <?= e($tipIcon) ?>"></i>
    <div><div class="t"><?= e($tipJudul) ?></div><div class="d"><?= e($tipTeks) ?></div></div>
  </div>
  <?php endif; ?>

  <a href="<?= BASE_URL ?>/" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs"><i class="bi bi-house-door-fill"></i> Selesai</a>

<?php else: ?>

  <div class="hs-hero" style="padding-top:26px">
    <div class="hs-ring merah">
      <svg viewBox="0 0 100 100" aria-hidden="true">
        <circle class="isi" cx="50" cy="50" r="44"/>
        <circle class="lingkar" cx="50" cy="50" r="46" transform="rotate(-90 50 50)"/>
        <path class="centang" d="M35 35 L65 65 M65 35 L35 65" style="stroke-dasharray:100;stroke-dashoffset:100"/>
      </svg>
    </div>
    <h1>Presensi Tidak Berhasil</h1>
  </div>
  <div class="hs-gagal">
    <div class="pesan"><?= e($pesan) ?></div>
    <a href="<?= BASE_URL ?>/presensi/index.php" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs"><i class="bi bi-arrow-repeat"></i> Coba Lagi</a>
    <a href="<?= BASE_URL ?>/" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" style="margin-top:10px">Kembali ke Beranda</a>
  </div>

<?php endif; ?>

  <div class="ps-foot">SIAP ABSEN &copy; <?= date('Y') ?></div>
</div>
</body>
</html>