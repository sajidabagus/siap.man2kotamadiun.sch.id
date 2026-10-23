<?php
// /portal/notifikasi — pemberitahuan status pengajuan. Membuka halaman ini menandai semuanya sudah dibaca.
$akun = portal_wajib_login();
$sid = (int) $akun['siswa_id'];
$daftar = portal_notif_daftar($sid, 50);    // diambil SEBELUM ditandai dibaca, agar yang baru tetap tersorot
portal_notif_tandai_dibaca($sid);

portal_app_awal('Notifikasi', $akun, 'beranda', ['kembali' => '']);
?>
<?php if ($daftar): ?>
<div class="pt-grid">
  <?php foreach ($daftar as $n):
    $url = $n['tautan'] && preg_match('#^izin/\d+$#', $n['tautan']) ? portal_url($n['tautan']) : null;
    $tag = $url ? 'a' : 'div'; ?>
  <<?= $tag ?> class="pt-notif <?= $n['dibaca_at'] === null ? 'baru' : '' ?>"<?= $url ? ' href="' . e($url) . '"' : '' ?>>
    <div class="j"><?= $n['dibaca_at'] === null ? '<span class="titik" aria-label="Baru"></span>' : '' ?><?= e($n['judul']) ?></div>
    <div class="i"><?= e($n['isi']) ?></div>
    <div class="w"><?= e(date('d/m/Y H:i', strtotime($n['created_at']))) ?></div>
  </<?= $tag ?>>
  <?php endforeach; ?>
</div>
<?php else: ?>
<section class="pt-card"><p class="pt-kosong">Belum ada notifikasi. Kabar tentang pengajuan izin/sakit Anda akan muncul di sini.</p></section>
<?php endif; ?>
<?php portal_app_akhir();
