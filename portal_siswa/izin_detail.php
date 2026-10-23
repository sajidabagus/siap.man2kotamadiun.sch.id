<?php
// /portal/izin/{id} — detail pengajuan; siswa dapat membatalkan selama masih menunggu
$akun = portal_wajib_login();
$sid = (int) $akun['siswa_id'];
$row = izin_ambil($izinId);
if (!$row || (int) $row['siswa_id'] !== $sid) portal_halaman_pesan(404, 'Pengajuan tidak ditemukan', 'Pengajuan yang Anda cari tidak ada.');

$galat = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $galat = 'Sesi halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif (($_POST['aksi'] ?? '') === 'batal') {
        $h = izin_batalkan_siswa($izinId, $sid);
        if ($h['ok']) { $_SESSION['portal_info'] = 'Pengajuan dibatalkan.'; header('Location: ' . portal_url('izin')); exit; }
        $galat = $h['pesan'];
        $row = izin_ambil($izinId);
    }
}
$info = (string) ($_SESSION['portal_info'] ?? '');
unset($_SESSION['portal_info']);

$ringkas = ['diterapkan' => 0, 'sudah_ada' => 0, 'dipakai_hadir' => 0, 'dicabut' => 0];
$q = getDB()->prepare('SELECT hasil, COUNT(*) c FROM izin_hari WHERE pengajuan_id = :p GROUP BY hasil');
$q->execute([':p' => $izinId]);
foreach ($q->fetchAll() as $x) $ringkas[$x['hasil']] = (int) $x['c'];

portal_app_awal('Detail Pengajuan', $akun, 'izin', ['kembali' => 'izin']);
if ($info !== '') portal_alert('ok', $info);
if ($galat !== '') portal_alert('err', $galat);
?>
<section class="pt-card">
  <dl class="pt-dl">
    <dt>Status</dt><dd><span class="pt-st <?= e($row['status']) ?>"><?= e(IZIN_STATUS_LABEL[$row['status']] ?? $row['status']) ?></span></dd>
    <dt>Jenis</dt><dd><span class="pt-jenis <?= e($row['jenis']) ?>"><?= e(izin_label_jenis($row['jenis'])) ?></span></dd>
    <dt>Tanggal</dt><dd><?= e(izin_teks_rentang($row['tanggal_mulai'], $row['tanggal_selesai'])) ?></dd>
    <dt>Diajukan</dt><dd><?= e(date('d/m/Y H:i', strtotime($row['diajukan_at']))) ?></dd>
    <?php if ($row['diproses_at']): ?>
    <dt><?= $row['status'] === 'dibatalkan' ? 'Dibatalkan' : 'Diproses' ?></dt>
    <dd><?= e(date('d/m/Y H:i', strtotime($row['diproses_at']))) ?><?= $row['status'] !== 'dibatalkan' && $row['diproses_nama'] ? ' oleh ' . e($row['diproses_nama']) : '' ?></dd>
    <?php endif; ?>
  </dl>
  <h3 style="margin-top:18px">Alasan</h3>
  <p class="pt-alasan"><?= e($row['alasan']) ?></p>

  <?php if ($row['catatan_petugas']): ?>
    <h3 style="margin-top:18px">Catatan petugas</h3>
    <div class="pt-catatan <?= in_array($row['status'], ['ditolak', 'dicabut'], true) ? 'tolak' : '' ?>"><?= e($row['catatan_petugas']) ?></div>
  <?php endif; ?>

  <?php if ($row['status'] === 'disetujui'): ?>
    <h3 style="margin-top:18px">Pengaruh ke presensi</h3>
    <p class="pt-kosong">Tercatat <?= e(strtolower(izin_label_jenis($row['jenis']))) ?> pada <b><?= $ringkas['diterapkan'] + $ringkas['dipakai_hadir'] ?></b> hari sekolah<?= $ringkas['sudah_ada'] ? '; ' . $ringkas['sudah_ada'] . ' hari dilewati karena presensi sudah tercatat' : '' ?><?= $ringkas['dipakai_hadir'] ? '; ' . $ringkas['dipakai_hadir'] . ' hari Anda tetap hadir sehingga tercatat HADIR' : '' ?>.</p>
  <?php endif; ?>

  <?php if ($row['lampiran']): ?>
    <h3 style="margin-top:18px">Lampiran</h3>
    <div class="pt-lampiran">
      <?php if ($row['lampiran_tipe'] === 'application/pdf'): ?>
        <a class="pt-btn kecil garis" href="<?= e(portal_url('izin/' . $izinId . '/lampiran')) ?>"><i class="bi bi-file-earmark-pdf"></i> Unduh <?= e($row['lampiran_nama'] ?: 'lampiran.pdf') ?></a>
      <?php else: ?>
        <img src="<?= e(portal_url('izin/' . $izinId . '/lampiran')) ?>" alt="Lampiran">
      <?php endif; ?>
    </div>
  <?php elseif ($row['lampiran_dihapus_at']): ?>
    <p class="pt-kosong" style="margin-top:14px"><i class="bi bi-paperclip"></i> Lampiran sudah dihapus sesuai kebijakan penyimpanan.</p>
  <?php endif; ?>

  <?php if ($row['status'] === 'menunggu'): ?>
    <form method="post" action="<?= e(portal_url('izin/' . $izinId)) ?>" style="margin-top:20px" onsubmit="return confirm('Batalkan pengajuan ini?')">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="batal">
      <button type="submit" class="pt-btn kecil bahaya"><i class="bi bi-x-circle"></i> Batalkan pengajuan</button>
    </form>
  <?php endif; ?>
</section>
<?php portal_app_akhir();
