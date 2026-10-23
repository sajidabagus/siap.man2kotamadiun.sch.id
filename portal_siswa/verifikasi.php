<?php
// /portal/verifikasi — sesi hasil MASUK OTOMATIS (tanpa password) harus membuktikan password sebelum aksi sensitif
// (ganti password, unduh lampiran izin). Berlaku PORTAL_PENUH_DETIK; sesi yang masuk dengan password tidak perlu ini.
$akun = portal_wajib_login();
$lanjut = portal_lanjut_aman((string) ($_GET['lanjut'] ?? $_POST['lanjut'] ?? ''));
if (portal_sesi_penuh()) { header('Location: ' . portal_url($lanjut)); exit; }

$galat = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $galat = 'Sesi halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } else {
        $h = portal_coba_login((string) $akun['nisn'], (string) ($_POST['password'] ?? ''));
        if ($h['ok'] && (int) $h['akun']['id'] === (int) $akun['id']) {
            $_SESSION['portal']['penuh_hingga'] = time() + PORTAL_PENUH_DETIK;
            header('Location: ' . portal_url($lanjut));
            exit;
        }
        $galat = $h['ok'] ? 'Password tidak cocok dengan akun ini.' : ($h['pesan'] ?? 'Password salah.');
        $galat = $galat === 'Username atau password salah.' ? 'Password salah.' : $galat;
    }
}

portal_app_awal('Verifikasi password', $akun, 'profil', ['kembali' => 'profil', 'judul' => 'Verifikasi']);
?>
<section class="pt-card" style="max-width:520px;margin:0 auto;width:100%">
  <h3>Masukkan password Anda</h3>
  <p class="pt-kosong" style="margin-bottom:12px">Anda masuk otomatis lewat perangkat terdaftar. Untuk keamanan, tindakan ini meminta password Anda lebih dulu.</p>
  <?php if ($galat !== '') portal_alert('err', $galat); ?>
  <form method="post" action="<?= e(portal_url('verifikasi')) ?>" data-sekali autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="lanjut" value="<?= e($lanjut) ?>">
    <div class="pt-field">
      <label for="pwVerif">Password</label>
      <input type="password" id="pwVerif" name="password" autocomplete="current-password" required autofocus>
    </div>
    <button type="submit" class="pt-btn"><i class="bi bi-shield-check"></i> Lanjutkan</button>
  </form>
</section>
<?php portal_app_akhir();
