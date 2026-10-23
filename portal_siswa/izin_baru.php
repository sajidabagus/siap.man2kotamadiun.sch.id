<?php
// /portal/izin/baru — formulir pengajuan izin/sakit
$akun = portal_wajib_login();
$sid = (int) $akun['siswa_id'];

$in = ['jenis' => '', 'mulai' => '', 'selesai' => '', 'alasan' => ''];
$galat = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $galat['umum'] = 'Ukuran data yang dikirim terlalu besar. Gunakan lampiran maksimal 3 MB.';
    } elseif (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $galat['umum'] = 'Sesi halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } else {
        foreach ($in as $k => $_) $in[$k] = (string) ($_POST[$k] ?? '');
        $hasil = izin_buat($sid, $in, $_FILES['lampiran'] ?? null);
        if ($hasil['ok']) {
            $_SESSION['portal_info'] = 'Pengajuan terkirim. Petugas akan memprosesnya; Anda akan mendapat notifikasi.';
            header('Location: ' . portal_url('izin/' . $hasil['id']));
            exit;
        }
        $galat = $hasil['galat'];
    }
}

$hariIni = date('Y-m-d');
$minTgl = date('Y-m-d', strtotime('-' . IZIN_MUNDUR_HARI . ' days'));
$maxTgl = date('Y-m-d', strtotime('+' . IZIN_MAJU_HARI . ' days'));
$f = fn(string $k) => isset($galat[$k]) ? '<div class="pt-err-f">' . e($galat[$k]) . '</div>' : '';

portal_app_awal('Ajukan Izin / Sakit', $akun, 'izin', ['kembali' => 'izin']);
?>
<section class="pt-card">
  <?php if (isset($galat['umum'])) portal_alert('err', $galat['umum']); ?>
  <div class="pt-aturan">
    <b>Aturan singkat:</b> bisa diajukan maksimal <b><?= IZIN_MUNDUR_HARI ?> hari ke belakang</b> dan <b><?= IZIN_MAJU_HARI ?> hari ke depan</b>;
    satu pengajuan maksimal <b><?= IZIN_MAKS_DURASI ?> hari</b>. <b>Sakit lebih dari 1 hari sekolah wajib melampirkan surat</b> (foto/PDF, maks. 3 MB).
    Tetap berangkat? Presensi hadir tetap berlaku.
  </div>
  <form method="post" action="<?= e(portal_url('izin/baru')) ?>" enctype="multipart/form-data" data-sekali>
    <?= csrf_field() ?>
    <div class="pt-radio" role="radiogroup" aria-label="Jenis pengajuan">
      <label><input type="radio" name="jenis" value="izin" <?= $in['jenis'] === 'izin' ? 'checked' : '' ?> required><span><i class="bi bi-envelope-paper"></i> Izin<small>keperluan keluarga, dll.</small></span></label>
      <label><input type="radio" name="jenis" value="sakit" <?= $in['jenis'] === 'sakit' ? 'checked' : '' ?>><span><i class="bi bi-thermometer-half"></i> Sakit<small>surat bila &gt; 1 hari</small></span></label>
    </div>
    <?= $f('jenis') ?>
    <div class="pt-dua">
      <div class="pt-field"><label for="mulai">Mulai</label>
        <input type="date" id="mulai" name="mulai" value="<?= e($in['mulai'] ?: $hariIni) ?>" min="<?= e($minTgl) ?>" max="<?= e($maxTgl) ?>" required><?= $f('mulai') ?></div>
      <div class="pt-field"><label for="selesai">Sampai (boleh kosong)</label>
        <input type="date" id="selesai" name="selesai" value="<?= e($in['selesai']) ?>" min="<?= e($minTgl) ?>" max="<?= e($maxTgl) ?>"><?= $f('selesai') ?></div>
    </div>
    <div class="pt-field"><label for="alasan">Alasan</label>
      <textarea id="alasan" name="alasan" maxlength="<?= IZIN_ALASAN_MAKS ?>" placeholder="Tuliskan alasan dengan jelas (minimal <?= IZIN_ALASAN_MIN ?> karakter)" required><?= e($in['alasan']) ?></textarea>
      <div class="pt-hitung"><span id="hitung">0</span>/<?= IZIN_ALASAN_MAKS ?></div><?= $f('alasan') ?></div>
    <div class="pt-field"><label for="lampiran">Lampiran surat <span id="wajibLamp" style="color:#b42318"></span></label>
      <input type="file" id="lampiran" name="lampiran" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"><?= $f('lampiran') ?></div>
    <button type="submit" class="pt-btn">Kirim pengajuan</button>
  </form>
</section>
<script>
(function () {
  var a = document.getElementById('alasan'), h = document.getElementById('hitung'), m = document.getElementById('mulai'), s = document.getElementById('selesai'), w = document.getElementById('wajibLamp');
  function hitung() { h.textContent = a.value.length; }
  function wajib() {
    var sakit = document.querySelector('input[name=jenis]:checked');
    var dari = m.value, ke = s.value || m.value;
    var lebih = dari && ke && (new Date(ke) - new Date(dari)) >= 86400000;
    w.textContent = (sakit && sakit.value === 'sakit' && lebih) ? '(wajib untuk sakit lebih dari 1 hari)' : '';
  }
  m.addEventListener('change', function () { if (s.value && s.value < m.value) s.value = m.value; s.min = m.value; wajib(); });
  s.addEventListener('change', wajib); a.addEventListener('input', hitung);
  document.querySelectorAll('input[name=jenis]').forEach(function (r) { r.addEventListener('change', wajib); });
  hitung(); wajib();
})();
</script>
<?php portal_app_akhir();
