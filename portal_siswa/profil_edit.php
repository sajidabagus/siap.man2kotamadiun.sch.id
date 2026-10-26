<?php
// /portal/profil/edit — siswa mengganti foto profil (langsung berlaku) dan mengajukan pindah kelas (perlu persetujuan petugas/admin).
// Hanya dua hal ini yang bisa diubah siswa sendiri; data diri lain tetap dikelola petugas.
require_once __DIR__ . '/../includes/pindah_kelas.php';
$akun = portal_wajib_login();
$sid = (int) $akun['siswa_id'];

if (!pindah_siapkan_tabel()) {
    portal_halaman_pesan(503, 'Fitur belum siap', 'Pengajuan pindah kelas belum dapat dipakai saat ini. Silakan hubungi petugas sekolah.');
}

$info = (string) ($_SESSION['portal_info'] ?? '');
$galatFlash = (string) ($_SESSION['portal_galat'] ?? '');
unset($_SESSION['portal_info'], $_SESSION['portal_galat']);

$galat = [];
$in = ['kelas' => '', 'alasan' => ''];
$kembali = portal_url('profil/edit');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $galat['foto'] = 'Ukuran foto terlalu besar untuk diterima server. Pilih foto yang lebih kecil.';
    } elseif (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $galat['umum'] = 'Sesi halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } else {
        $aksi = (string) ($_POST['aksi'] ?? '');
        if ($aksi === 'foto') {
            $h = pindah_foto_ganti($sid, $_FILES['foto'] ?? null);
            if ($h['ok']) { $_SESSION['portal_info'] = $h['pesan']; header('Location: ' . $kembali); exit; }
            $galat['foto'] = $h['pesan'];
        } elseif ($aksi === 'pindah') {
            $in['kelas'] = (string) ($_POST['kelas'] ?? '');
            $in['alasan'] = (string) ($_POST['alasan'] ?? '');
            $h = pindah_buat($sid, $in['kelas'], $in['alasan']);
            if ($h['ok']) {
                $_SESSION['portal_info'] = 'Pengajuan pindah kelas terkirim. Petugas akan memprosesnya; Anda akan mendapat notifikasi.';
                header('Location: ' . $kembali);
                exit;
            }
            $galat = $h['galat'];
        } elseif ($aksi === 'batal') {
            $h = pindah_batalkan_siswa((int) ($_POST['id'] ?? 0), $sid);
            $_SESSION[$h['ok'] ? 'portal_info' : 'portal_galat'] = $h['ok'] ? 'Pengajuan pindah kelas dibatalkan.' : $h['pesan'];
            header('Location: ' . $kembali);
            exit;
        }
    }
}

$menunggu = pindah_menunggu_siswa($sid);
$riwayat = pindah_riwayat_siswa($sid, 3);
$daftarKelas = pindah_daftar_kelas((string) $akun['kelas']);
$f = fn(string $k) => isset($galat[$k]) ? '<div class="pt-err-f">' . e($galat[$k]) . '</div>' : '';

$kata = preg_split('/\s+/', trim((string) $akun['nama']));
$inisial = mb_strtoupper(mb_substr($kata[0] ?? '', 0, 1) . (isset($kata[1]) ? mb_substr($kata[1], 0, 1) : ''));
$adaFoto = $akun['foto'] && is_file(__DIR__ . '/../uploads/siswa/' . $akun['foto']);

portal_app_awal('Edit Profil', $akun, 'profil', ['kembali' => 'profil']);
if ($info !== '') portal_alert('ok', $info);
if ($galatFlash !== '') portal_alert('err', $galatFlash);
if (isset($galat['umum'])) portal_alert('err', $galat['umum']);
?>
<section class="pt-card">
  <h3>Foto profil</h3>
  <form method="post" action="<?= e(portal_url('profil/edit')) ?>" enctype="multipart/form-data" data-sekali>
    <?= csrf_field() ?><input type="hidden" name="aksi" value="foto">
    <div class="pe-foto-baris">
      <?php if ($adaFoto): ?>
        <img class="pe-avatar" id="peAvatar" src="<?= e(BASE_URL . '/uploads/siswa/' . rawurlencode($akun['foto'])) ?>" alt="Foto profil saat ini">
      <?php else: ?>
        <span class="pe-avatar" id="peAvatar"><?= e($inisial) ?></span>
      <?php endif; ?>
      <div class="pe-foto-aksi">
        <label class="pt-btn garis kecil" for="peFoto"><i class="bi bi-camera"></i> <?= $adaFoto ? 'Ganti foto' : 'Tambah foto' ?></label>
        <input type="file" id="peFoto" name="foto" accept="image/jpeg,image/png,image/webp" data-kompres="1024" data-kompres-target="614400" hidden>
        <small id="peNama" class="pt-hint">JPG, PNG, atau WEBP · ukuran apa pun, dikompres otomatis</small>
      </div>
    </div>
    <?= $f('foto') ?>
    <div class="pt-err-f" id="peGalat" role="alert" hidden></div>
    <button type="submit" class="pt-btn" id="peSimpan" disabled><i class="bi bi-check2-circle"></i> Simpan foto</button>
  </form>
</section>

<section class="pt-card">
  <h3>Pindah kelas</h3>
  <div class="pt-aturan">
    Kelas Anda sekarang: <b><?= e($akun['kelas']) ?></b>. Pengajuan pindah kelas <b>harus disetujui petugas atau admin</b>;
    kelas baru berlaku setelah disetujui, dan Anda akan mendapat notifikasi.
  </div>

  <?php if ($menunggu): ?>
    <ul class="pt-list">
      <li><span>Pengajuan <small><?= e($menunggu['kelas_asal']) ?> → <b><?= e($menunggu['kelas_tujuan']) ?></b> · <?= e(date('d/m/Y H:i', strtotime($menunggu['diajukan_at']))) ?></small></span>
          <span class="pt-st menunggu">Menunggu</span></li>
      <li><span>Alasan<br><small><?= e($menunggu['alasan']) ?></small></span></li>
    </ul>
    <form method="post" action="<?= e(portal_url('profil/edit')) ?>" data-sekali style="margin-top:12px">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="batal"><input type="hidden" name="id" value="<?= (int) $menunggu['id'] ?>">
      <button type="submit" class="pt-btn garis"><i class="bi bi-x-circle"></i> Batalkan pengajuan</button>
    </form>
  <?php elseif (!$daftarKelas): ?>
    <p class="pt-kosong">Belum ada kelas lain yang tersedia sebagai tujuan.</p>
  <?php else: ?>
    <form method="post" action="<?= e(portal_url('profil/edit')) ?>" data-sekali>
      <?= csrf_field() ?><input type="hidden" name="aksi" value="pindah">
      <div class="pt-field"><label for="peKelas">Kelas tujuan</label>
        <select id="peKelas" name="kelas" required>
          <option value="">— Pilih kelas —</option>
          <?php foreach ($daftarKelas as $k): ?>
            <option value="<?= e($k) ?>" <?= $in['kelas'] === $k ? 'selected' : '' ?>><?= e($k) ?></option>
          <?php endforeach; ?>
        </select><?= $f('kelas') ?></div>
      <div class="pt-field"><label for="peAlasan">Alasan pindah</label>
        <textarea id="peAlasan" name="alasan" maxlength="<?= PINDAH_ALASAN_MAKS ?>" placeholder="Tuliskan alasan dengan jelas (minimal <?= PINDAH_ALASAN_MIN ?> karakter)" required><?= e($in['alasan']) ?></textarea>
        <?= $f('alasan') ?></div>
      <button type="submit" class="pt-btn"><i class="bi bi-send"></i> Kirim pengajuan</button>
    </form>
  <?php endif; ?>

  <?php if ($riwayat): ?>
    <h3 style="margin-top:18px">Pengajuan sebelumnya</h3>
    <ul class="pt-list">
      <?php foreach ($riwayat as $r): ?>
        <li><span><?= e($r['kelas_asal']) ?> → <b><?= e($r['kelas_tujuan']) ?></b>
            <small><br><?= e(date('d/m/Y', strtotime($r['diajukan_at']))) ?><?= $r['catatan_petugas'] ? ' · Catatan petugas: ' . e($r['catatan_petugas']) : '' ?></small></span>
            <span class="pt-st <?= e($r['status']) ?>"><?= e(PINDAH_STATUS_LABEL[$r['status']] ?? $r['status']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<script>
(function () {
  var inp = document.getElementById('peFoto'), simpan = document.getElementById('peSimpan'),
      nama = document.getElementById('peNama'), galat = document.getElementById('peGalat'), av = document.getElementById('peAvatar');
  if (!inp) return;
  inp.addEventListener('change', function () {
    var f = inp.files && inp.files[0];
    galat.hidden = true; simpan.disabled = true;
    if (!f) return;
    if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { galat.textContent = 'Format foto harus JPG, PNG, atau WEBP.'; galat.hidden = false; inp.value = ''; return; }
    if (f.size > 15 * 1024 * 1024) { galat.textContent = 'Ukuran foto maksimal 15 MB.'; galat.hidden = false; inp.value = ''; return; }
    nama.textContent = f.name;
    simpan.disabled = false;
    var url = URL.createObjectURL(f);
    if (av.tagName === 'IMG') { av.src = url; }
    else { var img = document.createElement('img'); img.className = 'pe-avatar'; img.id = 'peAvatar'; img.alt = 'Pratinjau foto'; img.src = url; av.replaceWith(img); av = img; }
  });
})();
</script>
<?php portal_app_akhir();
