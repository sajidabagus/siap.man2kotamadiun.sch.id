<?php
// /portal/aktivasi — NISN + kode dari petugas + password baru (+ mendaftarkan perangkat bila dari aplikasi SIAP)
if (portal_siswa_sekarang()) {
  header('Location: ' . portal_url());
  exit;
}

$galat = '';
$nisn = portal_nisn((string) ($_GET['nisn'] ?? ''));
$dalamApp = request_boleh_daftar_perangkat();   // aplikasi SIAP (Android) atau browser iPhone/iPad
$diIos = request_dari_ios_browser();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $nisn = portal_nisn((string) ($_POST['nisn'] ?? ''));
  $kode = (string) ($_POST['kode'] ?? '');
  $pw1 = (string) ($_POST['password'] ?? '');

  if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    $galat = 'Sesi halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.';
  } elseif ($nisn === '' || trim($kode) === '' || $pw1 === '') {
    $galat = 'Lengkapi NISN, kode aktivasi, dan password.';
  } else {
    // Di dalam aplikasi SIAP: perangkat didaftarkan bila kotak dicentang. Di browser biasa: akun saja
    // (yang terdaftar harus penyimpanan APLIKASI, bukan Chrome), siswa diberi petunjuk lanjutan.
    $mauDaftar = $dalamApp ? isset($_POST['daftar_perangkat']) : true;
    $hasil = portal_aktivasi($nisn, $kode, $pw1, (string) ($_POST['device_uuid'] ?? ''), $mauDaftar);
    if ($hasil['ok']) {
      // SATU TINDAKAN: kode terbukti benar -> akun aktif + perangkat presensi terdaftar (bila dari aplikasi SIAP)
      // + langsung masuk ke portal. Perangkat terdaftar hanya untuk PRESENSI; akun boleh dipakai di perangkat lain.
      $akunBaru = portal_cari_akun_id((int) $hasil['akun_id']);
      if ($akunBaru) {
        portal_masuk_sesi($akunBaru);
        $p = (string) $hasil['perangkat'];
        $pesan = trim('Akun berhasil diaktifkan. ' . (PESAN_PERANGKAT[$p] ?? ''));
        $_SESSION[in_array($p, ['terdaftar', 'sudah_sama', 'binding_nonaktif'], true) ? 'portal_info' : 'portal_galat'] = $pesan;
        header('Location: ' . portal_url());
        exit;
      }
      // Cadangan: bila sesi gagal dibuat, akun tetap aktif; arahkan ke halaman masuk seperti semula.
      header('Location: ' . portal_login_url(['aktif' => 1, 'p' => $hasil['perangkat']]));
      exit;
    }
    $galat = $hasil['pesan'];
  }
}

portal_auth_awal('Aktivasi Akun', 'Aktivasi Akun', 'Isi kode dari petugas dan buat password baru');
if ($galat !== '') portal_alert('err', $galat);
elseif (($_GET['dari'] ?? '') === 'perangkat') portal_alert('info', 'HP ini sudah terdaftar untuk presensi, tetapi akun portal Anda belum diaktifkan. Minta kode aktivasi ke petugas sekolah, lalu isi formulir ini sekali saja. Setelah itu Riwayat, Izin, dan Profil terbuka otomatis dari HP ini.');
?>
<form method="post" action="<?= e(portal_url('aktivasi')) ?>" data-sekali autocomplete="off">
  <?= csrf_field() ?>
  <div class="pt-field">
    <label for="nisn">NISN</label>
    <input type="text" id="nisn" name="nisn" value="<?= e($nisn) ?>" inputmode="numeric" maxlength="30" placeholder="Nomor Induk Siswa Nasional" required <?= $nisn === '' ? 'autofocus' : '' ?>>
  </div>
  <div class="pt-field">
    <label for="kode">Kode aktivasi</label>
    <input type="text" id="kode" name="kode" class="kode" maxlength="9" placeholder="XXXX-XXXX" autocapitalize="characters" spellcheck="false" required <?= $nisn !== '' ? 'autofocus' : '' ?>>
  </div>
  <div class="pt-field pw">
    <label for="password">Password baru</label>
    <input type="password" id="password" name="password" autocomplete="new-password" placeholder="Minimal <?= PORTAL_PASSWORD_MIN ?> karakter" required>
    <button type="button" class="pt-eye" data-pw-toggle="password" aria-label="Tampilkan password"><i class="bi bi-eye-slash"></i></button>
  </div>
  <p class="pt-hint">Minimal <?= PORTAL_PASSWORD_MIN ?> karakter, bukan NISN atau tanggal lahir.</p>
  <input type="hidden" name="device_uuid" id="device_uuid" value="">
  <?php if ($dalamApp): ?>
    <label class="pt-cek"><input type="checkbox" name="daftar_perangkat" value="1" checked>
      <span><b>Jadikan HP ini perangkat presensi saya</b><small>Hapus centang bila bukan HP milik Anda sendiri.</small></span></label>
    <?php if ($diIos): ?>
      <p class="pt-catat"><i class="bi bi-info-circle"></i><span>Di iPhone, buka SIAP ABSEN selalu dengan browser yang sama (disarankan <b>Safari</b>, bukan mode Privat).</span></p>
    <?php endif; ?>
  <?php else: ?>
    <p class="pt-catat"><i class="bi bi-info-circle"></i><span>Daftarkan perangkat presensi dari <b>aplikasi SIAP</b>.</span></p>
  <?php endif; ?>
  <button type="submit" class="pt-btn">Aktifkan Akun</button>
</form>
<script src="<?= BASE_URL ?>/assets/js/device.js?v=<?= @filemtime(__DIR__ . '/../assets/js/device.js') ?: 0 ?>"></script>
<script>
  (function() {
    var f = document.getElementById('device_uuid');

    function isi() {
      try {
        f.value = getOrCreateDeviceUuid();
      } catch (e) {}
    }
    isi();
    f.form.addEventListener('submit', isi);
  })();
</script>
<p class="pt-kaki">Sudah punya password? <a href="<?= e(portal_login_url()) ?>">Masuk</a></p>
<?php portal_auth_akhir();
