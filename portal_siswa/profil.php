<?php
// /portal/profil — data diri, perangkat presensi, dan akun (notifikasi, ganti password, keluar)
require_once __DIR__ . '/../includes/device_binding.php';
$akun = portal_wajib_login();
$db = getDB();
$sid = (int) $akun['siswa_id'];

$info = (string) ($_SESSION['portal_info'] ?? '');
$galatFlash = (string) ($_SESSION['portal_galat'] ?? '');
unset($_SESSION['portal_info'], $_SESSION['portal_galat']);

$binding = get_device_binding($sid);
$setelan = get_settings();
$bindingAktif = !empty($setelan['device_binding_aktif']);
$wajibKode = !perangkat_boleh_daftar_mandiri($sid);   // true = mode wajib kode: perlu Kode Perangkat dari petugas
$kodePending = portal_kode_perangkat_aktif($sid);     // petugas sudah menerbitkan kode perangkat yang masih berlaku
$dalamApp = request_boleh_daftar_perangkat();         // aplikasi SIAP (Android) atau browser iPhone/iPad
$diIos = request_dari_ios_browser();
$notifBaru = portal_notif_belum_dibaca($sid);
$viaPerangkat = portal_sesi_via_perangkat();

$kata = preg_split('/\s+/', trim((string) $akun['nama']));
$inisial = mb_strtoupper(mb_substr($kata[0] ?? '', 0, 1) . (isset($kata[1]) ? mb_substr($kata[1], 0, 1) : ''));

portal_app_awal('Profil', $akun, 'profil', ['kembali' => '']);
if ($info !== '') portal_alert('ok', $info);
if ($galatFlash !== '') portal_alert('err', $galatFlash);
?>
<section class="pt-card pt-profil-kartu">
  <div class="pt-profil">
    <?php if ($akun['foto'] && is_file(__DIR__ . '/../uploads/siswa/' . $akun['foto'])): ?>
      <img class="pt-avatar" src="<?= e(BASE_URL . '/uploads/siswa/' . rawurlencode($akun['foto'])) ?>" alt="">
    <?php else: ?>
      <span class="pt-avatar"><?= e($inisial) ?></span>
    <?php endif; ?>
    <div>
      <h2><?= e($akun['nama']) ?></h2>
      <div>
        <span class="pt-chip">Kelas <?= e($akun['kelas']) ?></span>
        <?php if (($akun['nis'] ?? '') !== ''): ?><span class="pt-chip">NIS <?= e($akun['nis']) ?></span><?php endif; ?>
        <span class="pt-chip">NISN <?= e($akun['nisn']) ?></span>
        <?php if ($akun['jurusan']): ?><span class="pt-chip"><?= e($akun['jurusan']) ?></span><?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php if ($bindingAktif): ?>
<section class="pt-card" id="kartuPerangkat">
  <h3>Perangkat presensi</h3>
  <?php if ($binding): ?>
    <ul class="pt-list">
      <li><span>Perangkat<br><small><?= e(trim(($binding['os'] ?: '-') . ' · ' . ($binding['browser'] ?: '-'))) ?></small></span>
          <span class="pt-badge <?= $binding['status'] === 'BLOCKED' ? 'ALPA' : 'HADIR' ?>"><?= $binding['status'] === 'BLOCKED' ? 'Diblokir' : 'Terdaftar' ?></span></li>
      <li><span>Terakhir dipakai</span><small><?= e($binding['last_used_at'] ?: '-') ?></small></li>
    </ul>
    <p class="pt-kosong" id="cekPerangkat" style="margin-top:8px" hidden></p>
    <p class="pt-kosong" style="margin-top:8px">Perangkat ini hanya untuk presensi; akun portal tetap bisa dibuka di perangkat lain. Ganti HP atau perangkat tidak terdaftar? Hubungi petugas sekolah.</p>
  <?php else: ?>
    <p class="pt-kosong">Belum ada perangkat terdaftar, jadi presensi belum bisa dilakukan dari HP ini.</p>
    <?php if (!$dalamApp): ?>
      <div class="pt-aturan" style="margin-top:10px">Perangkat hanya bisa didaftarkan dari <b>dalam aplikasi SIAP</b>. Buka aplikasi SIAP di HP Anda, masuk, lalu pilih <b>Daftarkan perangkat ini</b><?= ($wajibKode || $kodePending) ? ' dan isi <b>kode perangkat</b> dari petugas' : '' ?>.</div>
    <?php elseif ($wajibKode && !$kodePending): ?>
      <div class="pt-aturan" style="margin-top:10px">Pendaftaran perangkat memakai <b>kode perangkat</b> dari petugas sekolah. Minta kodenya ke petugas; setelah kode diterbitkan, kolom kode akan muncul di sini. Akun Anda tidak berubah.</div>
    <?php else: ?>
      <form method="post" action="<?= e(portal_url('perangkat')) ?>" data-sekali style="margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="aksi" value="daftar"><input type="hidden" name="device_uuid" class="devuuid" value="">
        <?php $adaKolomKode = ($wajibKode || $kodePending); ?>
        <?php if ($adaKolomKode): ?>
          <?php /* Kolom yang SAMA dengan "Kode aktivasi" di halaman aktivasi (.pt-field + input.kode): kapsul hijau,
                   teks di tengah, huruf besar, otomatis XXXX-XXXX lewat portal.js. */ ?>
          <div class="pt-field">
            <label for="kodePerangkat">Kode perangkat</label>
            <input type="text" id="kodePerangkat" name="kode" class="kode" maxlength="9" placeholder="XXXX-XXXX" autocomplete="off" autocapitalize="characters" spellcheck="false" <?= $wajibKode ? 'required' : '' ?>>
          </div>
          <p class="pt-hint">Kode dari petugas sekolah. Berlaku <?= (int) PORTAL_KODE_PERANGKAT_HARI ?> hari dan hanya untuk satu kali pendaftaran.</p>
        <?php endif; ?>
        <button type="submit" class="pt-btn<?= $adaKolomKode ? '' : ' kecil' ?>"><i class="bi bi-phone"></i> Daftarkan perangkat ini</button>
      </form>
      <p class="pt-kosong" style="margin-top:8px">Gunakan hanya di HP milik Anda sendiri. Satu siswa satu perangkat. Akun Anda tidak berubah.<?= $diIos ? ' Di iPhone, perangkat terdaftar di <b>' . e(nama_browser_sekarang()) . '</b> ini: selalu presensi dari browser yang sama (disarankan Safari), bukan mode Privat.' : '' ?></p>
    <?php endif; ?>
  <?php endif; ?>
</section>
<form id="fCek" method="post" action="<?= e(portal_url('perangkat')) ?>" hidden><?= csrf_field() ?><input type="hidden" name="aksi" value="cek"><input type="hidden" name="device_uuid" class="devuuid" value=""></form>
<script src="<?= BASE_URL ?>/assets/js/device.js"></script>
<script>
(function () {
  var uuid = ''; try { uuid = getOrCreateDeviceUuid(); } catch (e) {}
  document.querySelectorAll('.devuuid').forEach(function (i) { i.value = uuid; });
  var el = document.getElementById('cekPerangkat'); if (!el || !uuid) return;
  fetch(document.getElementById('fCek').action, { method: 'POST', body: new FormData(document.getElementById('fCek')), credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.ok || !d.terdaftar) return;
      if (d.cocok) {   // perangkat ini terdaftar: masuk otomatis berlaku, tombol Keluar tidak diperlukan
        var k = document.getElementById('formKeluar'); if (k) k.hidden = true;
        var n = document.getElementById('catatMasuk'); if (n) n.hidden = false;
      }
      el.hidden = false;
      el.innerHTML = d.cocok ? '<i class="bi bi-check-circle-fill" style="color:#16a34a"></i> Anda sedang memakai perangkat yang terdaftar.'
        : '<i class="bi bi-exclamation-triangle-fill" style="color:#d97706"></i> Penyimpanan di sini <b>berbeda</b> dari perangkat yang terdaftar. Presensi hanya bisa dari perangkat terdaftar (buka aplikasi SIAP di Android, atau browser yang sama di iPhone, pada HP yang didaftarkan).';
    }).catch(function () {});
})();
</script>
<?php endif; ?>

<section class="pt-card">
  <h3>Akun</h3>
  <div class="pt-menu-baris">
    <a href="<?= e(portal_url('notifikasi')) ?>"><i class="bi bi-bell"></i> Notifikasi<?php if ($notifBaru > 0): ?><span class="pt-dot" aria-label="<?= $notifBaru ?> baru"><?= $notifBaru > 9 ? '9+' : $notifBaru ?></span><?php endif; ?><i class="bi bi-chevron-right p"></i></a>
    <a href="<?= e(portal_url('password')) ?>"><i class="bi bi-key"></i> Ganti password<i class="bi bi-chevron-right p"></i></a>
    <?php if (!$viaPerangkat): ?>
    <form method="post" action="<?= e(portal_url('keluar')) ?>" id="formKeluar">
      <?= csrf_field() ?>
      <button type="submit" class="merah"><i class="bi bi-box-arrow-right"></i> Keluar</button>
    </form>
    <?php endif; ?>
  </div>
  <p class="pt-kosong" id="catatMasuk" style="margin-top:10px"<?= $viaPerangkat ? '' : ' hidden' ?>>Perangkat ini terdaftar, jadi Anda masuk otomatis tanpa password. Memakai HP lain? Masuk di sana dengan NISN dan password.</p>
</section>
<?php portal_app_akhir();
