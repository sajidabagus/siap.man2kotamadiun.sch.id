<?php
// /portal — Home: sapaan, jam, tombol scan absen, status hari ini, ringkasan bulan ini
require_once __DIR__ . '/../includes/device_binding.php';
$akun = portal_wajib_login();
// Beranda lama (/portal, tampilan hijau) tidak dipakai bagi siswa berperangkat terdaftar: alihkan ke beranda utama.
if (portal_pakai_beranda_utama()) {
  header('Location: ' . BASE_URL . '/', true, 302);
  exit;
}
$sid = (int) $akun['siswa_id'];

$info = (string) ($_SESSION['portal_info'] ?? '');
$galatFlash = (string) ($_SESSION['portal_galat'] ?? '');
unset($_SESSION['portal_info'], $_SESSION['portal_galat']);

$hariIni = date('Y-m-d');
$bulanIni = date('Y-m');
$p = portal_presensi_hari($sid, $hariIni);
$rekap = portal_rekap_bulan($sid, $bulanIni);
$binding = get_device_binding($sid);
$bindingAktif = !empty(get_settings()['device_binding_aktif']);
$notifBaru = portal_notif_belum_dibaca($sid);
$zona = portal_zona_label();

$kata = preg_split('/\s+/', trim((string) $akun['nama']));
$namaDepan = mb_convert_case(mb_strtolower($kata[0] ?? ''), MB_CASE_TITLE, 'UTF-8');

// Status hari ini (hanya yang ada di data; tidak ada jam pulang)
$kunciStatus = $p['status'] ?? 'BELUM';
$ikonStatus = ['HADIR' => 'check-lg', 'TERLAMBAT' => 'clock-history', 'IZIN' => 'file-earmark-text-fill', 'SAKIT' => 'heart-pulse-fill', 'ALPA' => 'x-lg', 'BELUM' => 'hourglass-split'][$kunciStatus];
$judulStatus = $p ? (PORTAL_LABEL_STATUS[$p['status']] ?? $p['status']) : 'Belum absen';
if (!$p) {
  $subStatus = absen_sudah_ditutup() && is_hari_efektif() ? 'Absen masuk sudah ditutup pukul ' . substr(batas_absen(), 0, 5) . '.' : 'Scan QR absen saat tiba di sekolah.';
} elseif (in_array($p['status'], ['HADIR', 'TERLAMBAT'], true) && $p['metode'] !== 'PENGAJUAN' && substr((string) $p['jam_masuk'], 0, 5) !== '00:00') {
  $subStatus = 'Masuk: ' . substr((string) $p['jam_masuk'], 0, 5) . ' ' . $zona;
} else {
  $subStatus = $p['metode'] === 'PENGAJUAN' ? 'Pengajuan disetujui' : ($p['metode'] === 'OTOMATIS' ? 'Tidak absen sampai batas ' . substr(batas_absen(), 0, 5) : 'Dicatat petugas');
}
$hadir = $rekap['HADIR'] + $rekap['TERLAMBAT'];
$izin = $rekap['IZIN'] + $rekap['SAKIT'];

portal_app_awal('Home', $akun, 'beranda', ['tanpa_header' => true]);
?>
<header class="pt-hello">
  <div>
    <h1><span class="sd-wave" aria-hidden="true">👋</span>Halo, <?= e($namaDepan) ?> <span class="m-wave">👋</span></h1>
    <p class="sd-sub">Selamat datang kembali di sistem informasi siswa</p>
    <p class="tgl"><i class="bi bi-calendar-check sd-ik"></i><?= e(portal_tanggal_panjang($hariIni)) ?></p>
  </div>
  <a class="pt-lonceng" href="<?= e(portal_url('notifikasi')) ?>" aria-label="Notifikasi<?= $notifBaru > 0 ? ', ' . $notifBaru . ' baru' : '' ?>"><i class="bi bi-bell"></i><?php if ($notifBaru > 0): ?><b><?= $notifBaru > 9 ? '9+' : $notifBaru ?></b><?php endif; ?></a>
</header>
<?php
if ($info !== '') portal_alert('ok', $info);
if ($galatFlash !== '') portal_alert('err', $galatFlash);
?>

<section class="pt-jam" aria-label="Jam sekarang">
  <div>
    <div class="lbl"><i class="bi bi-clock sd-ik"></i>Jam Sekarang</div>
    <div class="waktu"><span id="jamTeks">--:--</span><span class="dt" id="jamDetik">:--</span><small><?= e($zona) ?></small></div>
    <div class="ket"><i class="bi bi-calendar-check"></i> <?= e(portal_tanggal_panjang($hariIni)) ?></div>
  </div>
  <svg viewBox="0 0 120 120" aria-hidden="true" focusable="false">
    <circle cx="60" cy="60" r="58" fill="rgba(255,255,255,.25)" />
    <circle cx="60" cy="60" r="51" fill="#fff" />
    <?php for ($i = 0; $i < 60; $i++): $b = $i % 5 === 0; ?>
      <line x1="60" y1="<?= $b ? 13 : 13.5 ?>" x2="60" y2="<?= $b ? 21 : 16.5 ?>" stroke="<?= $b ? '#14213d' : '#c3cad6' ?>" stroke-width="<?= $b ? 2.8 : 1 ?>" stroke-linecap="round" transform="rotate(<?= $i * 6 ?> 60 60)" /><?php endfor; ?>
    <line id="jmJam" x1="60" y1="60" x2="60" y2="35" stroke="#14213d" stroke-width="5" stroke-linecap="round" />
    <line id="jmMenit" x1="60" y1="60" x2="60" y2="20" stroke="#14213d" stroke-width="3.6" stroke-linecap="round" />
    <line id="jmDetik" x1="60" y1="68" x2="60" y2="18" stroke="#2f80ff" stroke-width="1.6" stroke-linecap="round" />
    <circle cx="60" cy="60" r="4.5" fill="#14213d" />
    <circle cx="60" cy="60" r="1.6" fill="#2f80ff" />
  </svg>
</section>

<?php $scan = $GLOBALS['PORTAL_SCAN'] ?? portal_scan_status($sid); ?>
<?php if ($scan['aktif']): ?>
  <a class="pt-absen" href="<?= e(BASE_URL) ?>/presensi/"><i class="bi bi-qr-code-scan"></i> Scan QR Absen</a>
<?php else: ?>
  <span class="pt-absen mati" role="button" aria-disabled="true"><i class="bi bi-qr-code-scan"></i> <?= e($scan['alasan']) ?></span>
<?php endif; ?>

<?php if ($bindingAktif && !$binding): ?>
  <a class="pt-peringatan kuning" href="<?= e(portal_url('profil')) ?>#kartuPerangkat"><i class="bi bi-phone"></i><span>Perangkat presensi belum terdaftar, jadi absen dari HP ini belum bisa. Daftarkan sekarang.</span><i class="bi bi-chevron-right p"></i></a>
<?php elseif ($bindingAktif && $binding && ($binding['status'] ?? '') === 'BLOCKED'): ?>
  <div class="pt-peringatan merah"><i class="bi bi-slash-circle"></i><span>Perangkat presensi Anda diblokir. Hubungi petugas sekolah.</span></div>
<?php endif; ?>

<div class="pt-sec sd-hide">
  <h2>Status Hari Ini</h2>
</div>
<a class="pt-status pt-c-<?= e($kunciStatus) ?>" href="<?= e(portal_url('riwayat', ['bulan' => $bulanIni])) ?>">
  <span class="lingkar" style="background:var(--c)"><i class="bi bi-<?= e($ikonStatus) ?>"></i></span>
  <span class="isi"><em class="sd-lbl">Status Hari Ini</em><b style="color:var(--c)"><?= e($judulStatus) ?></b><span><?= e($subStatus) ?></span></span>
  <span class="sd-detail"><i class="bi bi-clock"></i> Lihat Detail <i class="bi bi-chevron-right"></i></span>
  <i class="bi bi-chevron-right m-ch"></i>
</a>

<div class="pt-sec">
  <h2>Ringkasan Bulan Ini</h2><a href="<?= e(portal_url('riwayat', ['bulan' => $bulanIni])) ?>">Lihat Semua <i class="bi bi-chevron-right sd-ik"></i></a>
</div>
<div class="pt-ringkas">
  <a class="h" href="<?= e(portal_url('riwayat', ['bulan' => $bulanIni])) ?>"><em class="sd-bul"><i class="bi bi-check-lg"></i></em><b><?= $hadir ?></b><span>Hadir</span><?php if ($rekap['TERLAMBAT'] > 0): ?><small><?= $rekap['TERLAMBAT'] ?> terlambat</small><?php else: ?><small class="sd-only">Tepat waktu</small><?php endif; ?><i class="bi bi-calendar-check"></i><s class="sd-ch bi bi-chevron-right"></s></a>
  <a class="i" href="<?= e(portal_url('riwayat', ['bulan' => $bulanIni])) ?>"><em class="sd-bul"><i class="bi bi-clock"></i></em><b><?= $izin ?></b><span>Izin / Sakit</span><small class="sd-only"><?= $rekap['IZIN'] ?> izin · <?= $rekap['SAKIT'] ?> sakit</small><i class="bi bi-file-earmark-text-fill"></i><s class="sd-ch bi bi-chevron-right"></s></a>
  <a class="a" href="<?= e(portal_url('riwayat', ['bulan' => $bulanIni, 'status' => 'ALPA'])) ?>"><em class="sd-bul"><i class="bi bi-x-lg"></i></em><b><?= $rekap['ALPA'] ?></b><span>Alpa</span><small class="sd-only"><?= $rekap['ALPA'] > 0 ? $rekap['ALPA'] . ' hari' : 'Tidak ada' ?></small><i class="bi bi-x-lg"></i><s class="sd-ch bi bi-chevron-right"></s></a>
</div>

<script>
  (function() {
    var teks = document.getElementById('jamTeks'),
      jam = document.getElementById('jmJam'),
      menit = document.getElementById('jmMenit'),
      detikT = document.getElementById('jamDetik'),
      detikJ = document.getElementById('jmDetik');
    if (!teks || !jam || !menit) return;
    var tz = <?= json_encode(date_default_timezone_get()) ?>,
      selisih = <?= (int) time() * 1000 ?> - Date.now(),
      fmt = null;
    try {
      fmt = new Intl.DateTimeFormat('en-GB', {
        timeZone: tz,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
      });
    } catch (e) {}

    function waktu() {
      var d = new Date(Date.now() + selisih),
        h = d.getHours(),
        m = d.getMinutes(),
        s = d.getSeconds();
      if (fmt) {
        fmt.formatToParts(d).forEach(function(x) {
          if (x.type === 'hour') h = parseInt(x.value, 10) % 24;
          if (x.type === 'minute') m = parseInt(x.value, 10);
          if (x.type === 'second') s = parseInt(x.value, 10);
        });
      }
      return [h, m, s];
    }

    function gambar() {
      var t = waktu(),
        h = t[0],
        m = t[1],
        s = t[2];
      teks.textContent = (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
      jam.setAttribute('transform', 'rotate(' + ((h % 12) * 30 + m * 0.5) + ' 60 60)');
      menit.setAttribute('transform', 'rotate(' + (m * 6 + s * 0.1) + ' 60 60)');
      if (detikT) detikT.textContent = ':' + (s < 10 ? '0' : '') + s;
      if (detikJ) detikJ.setAttribute('transform', 'rotate(' + (s * 6) + ' 60 60)');
    }
    gambar();
    setInterval(gambar, 1000);
  })();
</script>
<?php portal_app_akhir();
