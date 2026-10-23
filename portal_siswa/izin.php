<?php
// /portal/izin — daftar pengajuan izin/sakit milik siswa
$akun = portal_wajib_login();
$sid = (int) $akun['siswa_id'];
$db = getDB();

$info = (string) ($_SESSION['portal_info'] ?? '');
unset($_SESSION['portal_info']);

$perHalaman = 10;
$hal = max(1, (int) ($_GET['page'] ?? 1));
$st = $db->prepare('SELECT COUNT(*) FROM izin_pengajuan WHERE siswa_id = :s');
$st->execute([':s' => $sid]);
$total = (int) $st->fetchColumn();
$totalHal = max(1, (int) ceil($total / $perHalaman));
$hal = min($hal, $totalHal);
$st = $db->prepare('SELECT * FROM izin_pengajuan WHERE siswa_id = :s ORDER BY id DESC LIMIT ' . $perHalaman . ' OFFSET ' . (($hal - 1) * $perHalaman));
$st->execute([':s' => $sid]);
$daftar = $st->fetchAll();
$menunggu = (int) $db->query('SELECT COUNT(*) FROM izin_pengajuan WHERE siswa_id = ' . $sid . " AND status = 'menunggu'")->fetchColumn();

portal_app_awal('Izin / Sakit', $akun, 'izin');
if ($info !== '') portal_alert('ok', $info);
?>
<div class="pt-judul-halaman" style="justify-content:flex-end">
  <a class="pt-btn kecil" href="<?= e(portal_url('izin/baru')) ?>"><i class="bi bi-plus-lg"></i> Ajukan baru</a>
</div>
<?php if ($menunggu > 0): ?>
  <div class="pt-alert info" role="status"><i class="bi bi-hourglass-split"></i><span>Ada <?= $menunggu ?> pengajuan yang menunggu persetujuan petugas.</span></div>
<?php endif; ?>

<?php if ($daftar): ?>
<div class="pt-grid">
  <?php foreach ($daftar as $r): ?>
  <a class="pt-item" href="<?= e(portal_url('izin/' . (int) $r['id'])) ?>">
    <div class="r1">
      <span class="tgl"><?= e(izin_teks_rentang($r['tanggal_mulai'], $r['tanggal_selesai'])) ?></span>
      <span class="pt-st <?= e($r['status']) ?>"><?= e(IZIN_STATUS_LABEL[$r['status']] ?? $r['status']) ?></span>
    </div>
    <div class="als"><span class="pt-jenis <?= e($r['jenis']) ?>"><?= e(izin_label_jenis($r['jenis'])) ?></span> <?= e($r['alasan']) ?></div>
    <div class="ft">Diajukan <?= e(date('d/m/Y H:i', strtotime($r['diajukan_at']))) ?><?= $r['lampiran'] ? ' · <i class="bi bi-paperclip"></i> ada lampiran' : '' ?></div>
  </a>
  <?php endforeach; ?>
</div>
<?php if ($totalHal > 1): ?>
<div class="pt-menu" style="justify-content:center">
  <?php if ($hal > 1): ?><a class="pt-btn kecil garis" href="<?= e(portal_url('izin', ['page' => $hal - 1])) ?>">&laquo; Sebelumnya</a><?php endif; ?>
  <span class="pt-kosong" style="align-self:center">Halaman <?= $hal ?> / <?= $totalHal ?></span>
  <?php if ($hal < $totalHal): ?><a class="pt-btn kecil garis" href="<?= e(portal_url('izin', ['page' => $hal + 1])) ?>">Berikutnya &raquo;</a><?php endif; ?>
</div>
<?php endif; ?>
<?php else: ?>
<section class="pt-card"><p class="pt-kosong">Belum ada pengajuan. Tidak masuk sekolah? Ajukan izin atau sakit di sini, tidak perlu lewat kertas.</p></section>
<?php endif; ?>
<?php portal_app_akhir();
