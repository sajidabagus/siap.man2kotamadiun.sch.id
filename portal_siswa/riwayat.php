<?php
// /portal/riwayat — riwayat absensi per bulan. Hanya status yang ada di data (tidak ada jam pulang, tidak ada hari "ditebak").
$akun = portal_wajib_login();
$sid = (int) $akun['siswa_id'];

$bulan = portal_bulan_valid((string) ($_GET['bulan'] ?? ''));
$status = strtoupper((string) ($_GET['status'] ?? ''));
if (!isset(PORTAL_LABEL_STATUS[$status])) $status = '';

$daftar = portal_riwayat_bulan($sid, $bulan, $status);
$rekap = portal_rekap_bulan($sid, $bulan);
$bulanList = portal_bulan_tersedia($sid);
if (!in_array($bulan, $bulanList, true)) { $bulanList[] = $bulan; rsort($bulanList); }
$zona = portal_zona_label();
$ikon = ['HADIR' => 'check-lg', 'TERLAMBAT' => 'clock-history', 'IZIN' => 'file-earmark-text-fill', 'SAKIT' => 'file-earmark-medical-fill', 'ALPA' => 'x-lg'];
$q = fn(array $x): string => portal_url('riwayat', array_filter($x, fn($v) => $v !== ''));

$aksi = '<button type="button" class="pt-bar-btn" id="btnFilter" aria-label="Filter status" aria-expanded="' . ($status !== '' ? 'true' : 'false') . '" aria-controls="panelFilter"><i class="bi bi-funnel' . ($status !== '' ? '-fill' : '') . '"></i></button>';
portal_app_awal('Riwayat Absensi', $akun, 'riwayat', ['kembali' => '', 'aksi' => $aksi]);
?>
<form method="get" action="<?= e(portal_url('riwayat')) ?>" class="pt-bulan">
  <label class="pt-pilih"><i class="bi bi-calendar3"></i>
    <select name="bulan" onchange="this.form.submit()" aria-label="Pilih bulan">
      <?php foreach ($bulanList as $b): ?><option value="<?= e($b) ?>" <?= $b === $bulan ? 'selected' : '' ?>><?= e(portal_bulan_teks($b)) ?></option><?php endforeach; ?>
    </select><i class="bi bi-chevron-down pt-caret"></i>
  </label>
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <noscript><button type="submit" class="pt-btn kecil">Tampilkan</button></noscript>
</form>

<div class="pt-filter" id="panelFilter"<?= $status === '' ? ' hidden' : '' ?>>
  <a href="<?= e($q(['bulan' => $bulan])) ?>" class="<?= $status === '' ? 'on' : '' ?>">Semua</a>
  <?php foreach (PORTAL_LABEL_STATUS as $k => $label): ?>
    <a href="<?= e($q(['bulan' => $bulan, 'status' => $k])) ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= e($label) ?> (<?= (int) $rekap[$k] ?>)</a>
  <?php endforeach; ?>
</div>

<div class="pt-ringkas-baris" aria-label="Ringkasan <?= e(portal_bulan_teks($bulan)) ?>">
  <?php foreach (PORTAL_LABEL_STATUS as $k => $label): if ($rekap[$k] > 0): ?><span><?= e($label) ?> <b><?= (int) $rekap[$k] ?></b></span><?php endif; endforeach; ?>
  <?php if (array_sum($rekap) === 0): ?><span>Belum ada catatan pada bulan ini</span><?php endif; ?>
</div>

<?php if ($daftar): ?>
<div class="pt-daftar">
  <?php foreach ($daftar as $r):
    $t = strtotime($r['tanggal']);
    $jam = substr((string) $r['jam_masuk'], 0, 5);
    // Tampilan seperti desain: Hadir -> kolom Masuk & Pulang (Pulang "-" karena saat ini hanya absen masuk);
    // Izin/Sakit -> judul "Izin" + Keterangan (Izin/Sakit); Alfa -> tanpa rincian.
    $judul = PORTAL_LABEL_STATUS[$r['status']] ?? $r['status'];
    $kolom = null; $rinci = null;
    if (in_array($r['status'], ['HADIR', 'TERLAMBAT'], true)) {
        if ($r['metode'] !== 'PENGAJUAN' && $jam !== '' && $jam !== '00:00') $kolom = [['Masuk', $jam . ' ' . $zona], ['Pulang', '-']];
        else $rinci = ['Keterangan', $r['metode'] === 'PENGAJUAN' ? 'Pengajuan disetujui' : 'Dicatat petugas'];
    } elseif (in_array($r['status'], ['IZIN', 'SAKIT'], true)) {
        $judul = 'Izin';
        $rinci = ['Keterangan', PORTAL_LABEL_STATUS[$r['status']] . ($r['metode'] === 'PENGAJUAN' ? '' : ' (dicatat petugas)')];
    } elseif ($r['status'] === 'ALPA') {
        $judul = 'Alfa';
    }
  ?>
  <article class="pt-hari pt-c-<?= e($r['status']) ?>">
    <div class="tgl"><b><?= (int) date('j', $t) ?></b><span><?= e(PORTAL_BULAN_PENDEK[(int) date('n', $t)]) ?></span><small><?= e(PORTAL_HARI_PENDEK[(int) date('N', $t)]) ?></small></div>
    <div class="isi">
      <strong><?= e($judul) ?></strong>
      <?php if ($kolom): ?><div class="r2"><?php foreach ($kolom as $k): ?><div class="r"><?= e($k[0]) ?><b><?= e($k[1]) ?></b></div><?php endforeach; ?></div><?php endif; ?>
      <?php if ($rinci): ?><div class="r"><?= e($rinci[0]) ?><b><?= e($rinci[1]) ?></b></div><?php endif; ?>
    </div>
    <div class="ikon" aria-hidden="true"><i class="bi bi-<?= e($ikon[$r['status']] ?? 'check-lg') ?>"></i></div>
  </article>
  <?php endforeach; ?>
</div>
<?php else: ?>
  <section class="pt-card"><p class="pt-kosong"><?= $status !== '' ? 'Tidak ada catatan ' . e(strtolower(PORTAL_LABEL_STATUS[$status])) . ' pada ' . e(portal_bulan_teks($bulan)) . '.' : 'Belum ada data presensi pada ' . e(portal_bulan_teks($bulan)) . '.' ?></p></section>
<?php endif; ?>
<script>
(function () {
  var b = document.getElementById('btnFilter'), p = document.getElementById('panelFilter');
  if (!b || !p) return;
  b.addEventListener('click', function () { var buka = p.hidden; p.hidden = !buka; b.setAttribute('aria-expanded', buka ? 'true' : 'false'); });
})();
</script>
<?php portal_app_akhir();
