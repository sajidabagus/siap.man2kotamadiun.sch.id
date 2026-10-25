<?php
// /portal/password — ganti password (butuh password lama)
$akun = portal_wajib_login(true);   // ganti password: sesi masuk-otomatis harus membuktikan password dulu
$wajibGanti = !empty($akun['password_sementara']);   // password sementara dari petugas: wajib diganti
$galat = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $lama = (string) ($_POST['lama'] ?? '');
  $baru = (string) ($_POST['password'] ?? '');
  $ulang = (string) ($_POST['password2'] ?? '');

  if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    $galat = 'Sesi halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.';
  } elseif (portal_kunci_sisa('ganti:' . $akun['id']) > 0) {
    $galat = 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.';
  } elseif (!password_verify($lama, (string) $akun['password_hash'])) {
    portal_catat_gagal('ganti:' . $akun['id']);
    $galat = 'Password lama salah.';
  } elseif ($baru !== $ulang) {
    $galat = 'Konfirmasi password belum sama.';
  } elseif ($baru === $lama) {
    $galat = 'Password baru harus berbeda dari yang lama.';
  } elseif (($g = portal_validasi_password($baru, $akun)) !== null) {
    $galat = $g;
  } else {
    $u = getDB()->prepare('UPDATE siswa_akun SET password_hash = :p, password_diubah = NOW(), sesi_versi = sesi_versi + 1'
      . (portal_kolom_sementara() ? ', password_sementara = 0' : '') . ' WHERE id = :id');
    $u->execute([':p' => password_hash($baru, PASSWORD_DEFAULT), ':id' => $akun['id']]);
    portal_bersihkan_gagal('ganti:' . $akun['id']);
        security_log((int) $akun['siswa_id'], 'PORTAL_PASSWORD_DIGANTI', null, 'Password diganti oleh siswa');
    // Semua sesi lain (HP/komputer lain) otomatis keluar; sesi ini dipertahankan.
    $_SESSION['portal']['sesi_versi'] = (int) $akun['sesi_versi'] + 1;
    $_SESSION['portal_info'] = 'Password berhasil diganti.';
    header('Location: ' . portal_url());
    exit;
  }
}

portal_app_awal('Ganti Password', $akun, 'profil', $wajibGanti ? [] : ['kembali' => 'profil']);
?>
<section class="pt-card" style="max-width:520px;margin:0 auto;width:100%">
  <h3><?= $wajibGanti ? 'Buat password baru' : 'Ganti password' ?></h3>
  <?php if ($wajibGanti) portal_alert('info', 'Password Anda adalah password sementara dari petugas sekolah. Buat password baru sekarang untuk melanjutkan.'); ?>
  <?php if ($galat !== '') portal_alert('err', $galat); ?>
  <form method="post" action="<?= e(portal_url('password')) ?>" data-sekali autocomplete="off">
    <?= csrf_field() ?>
    <div class="pt-field pw">
      <label for="lama">Password lama</label>
      <input type="password" id="lama" name="lama" autocomplete="current-password" required autofocus>
      <button type="button" class="pt-eye" data-pw-toggle="lama" aria-label="Tampilkan password"><i class="bi bi-eye-slash"></i></button>
    </div>
    <div class="pt-field pw">
      <label for="password">Password baru</label>
      <input type="password" id="password" name="password" autocomplete="new-password" placeholder="Minimal <?= PORTAL_PASSWORD_MIN ?> karakter" required>
      <button type="button" class="pt-eye" data-pw-toggle="password" aria-label="Tampilkan password"><i class="bi bi-eye-slash"></i></button>
    </div>
    <p class="pt-hint">Gunakan huruf dan angka. Perangkat lain yang sedang masuk akan otomatis keluar.</p>
    <div class="pt-field pw">
      <label for="password2">Ulangi password baru</label>
      <input type="password" id="password2" name="password2" autocomplete="new-password" required>
    </div>
    <p class="pt-hint" id="cocok"></p>
    <div class="pt-menu">
      <button type="submit" class="pt-btn kecil" style="margin-top:6px">Simpan password</button>
      <?php if (!$wajibGanti): ?><a class="pt-btn kecil garis" style="margin-top:6px" href="<?= e(portal_url()) ?>">Batal</a><?php endif; ?>
    </div>
  </form>
</section>
<?php portal_app_akhir();
