<?php
// Popup: membuat Kode Pasangan 6 angka agar siswa dapat mendaftarkan perangkatnya (dimuat lewat AJAX).
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/device_binding.php';

$db = getDB();
$siswaId = (int) ($_REQUEST['siswa_id'] ?? 0);
$st = $db->prepare('SELECT id, nama, nis, kelas FROM siswa WHERE id = :id');
$st->execute([':id' => $siswaId]);
$siswa = $st->fetch();
if (!$siswa) {
  echo '<div class="siap-note siap-note-danger mb-0">Siswa tidak ditemukan.</div>';
  exit;
}

$binding = get_device_binding($siswaId);
$settings = get_settings();
$aksiUrl = e(modul_url('kode_pasangan', ['siswa_id' => (int) $siswaId]));
$hasil = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$binding) {
  csrf_require();
  $admin = current_admin();
  $hasil = buat_kode_pasangan($siswaId, (int) ($admin['id'] ?? 0));
  catat_aktivitas('GENERATE', 'kode_pasangan', 'Membuat Kode Pasangan untuk ' . nama_siswa_log($siswaId));
}
?>
<div class="d-flex align-items-center gap-3 mb-3">
  <span class="avatar" style="width:46px;height:46px"><?= e(mb_strtoupper(mb_substr($siswa['nama'], 0, 1))) ?></span>
  <div>
    <div class="fw-bold"><?= e($siswa['nama']) ?></div>
    <div class="text-muted small">NIS <?= e($siswa['nis']) ?> &middot; Kelas <?= e($siswa['kelas']) ?></div>
  </div>
</div>

<?php if ($binding): ?>
  <div class="siap-note siap-note-warning mb-3">
    Siswa ini <strong>sudah memiliki perangkat terdaftar</strong>. Untuk mendaftarkan perangkat baru, reset perangkatnya dulu di
    <a href="<?= e(modul_url('device', ['siswa_id' => (int) $siswaId])) ?>">Manajemen Perangkat</a>, lalu buat kode di sini.
  </div>
  <div class="d-flex justify-content-end"><button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" data-bs-dismiss="modal">Tutup</button></div>

<?php elseif ($hasil): ?>
  <?php $tampil = substr($hasil['kode'], 0, 3) . ' ' . substr($hasil['kode'], 3); ?>
  <div class="text-center mb-2">
    <span class="siap-badge siap-badge-info siap-mono-xs" style="font-size:.85rem">
      <i class="bi bi-hourglass-split"></i> Berlaku <span data-countdown="<?= (int) strtotime($hasil['berlaku_sampai']) ?>">--:--</span>
      &middot; sekali pakai
    </span>
  </div>
  <div class="row g-3 align-items-stretch">
    <div class="col-md-6">
      <div class="ps-card ps-card-pad h-100 text-center">
        <div class="text-muted small fw-bold mb-2">CARA 1 &mdash; SCAN QR</div>
        <div data-qr="<?= e($hasil['url']) ?>" data-qr-lib="<?= e(BASE_URL) ?>/assets/js/vendor/qrcode.js" style="min-height:190px" class="d-flex align-items-center justify-content-center"></div>
        <div class="small text-muted mt-2">Siswa scan dengan kamera perangkatnya, lalu ketuk <em>Daftarkan Perangkat Ini</em>.</div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="ps-card ps-card-pad h-100 text-center d-flex flex-column justify-content-center">
        <div class="text-muted small fw-bold mb-2">CARA 2 &mdash; KODE 6 ANGKA</div>
        <div class="fw-bold" style="font-size:2.3rem; letter-spacing:.16em; font-family:ui-monospace,Consolas,monospace; color:#14213d"><?= e($tampil) ?></div>
        <div class="small text-muted mt-2">Di halaman presensi siswa, ketuk <em>Daftarkan Perangkat</em>, lalu isi <strong>NIS</strong> dan kode ini.</div>
      </div>
    </div>
  </div>
  <div class="siap-note siap-note-info mt-3 mb-3">
    Pilih <strong>salah satu</strong> cara. Begitu salah satunya dipakai, cara lainnya langsung hangus. Kode dan QR ini hanya tampil sekali di layar ini.
  </div>
  <div class="d-flex justify-content-end gap-2">
    <button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" onclick="navigator.clipboard && navigator.clipboard.writeText('<?= e($hasil['kode']) ?>'); this.textContent='Kode tersalin ✓'"><i class="bi bi-clipboard"></i> Salin kode</button>
    <button type="button" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs" data-bs-dismiss="modal">Selesai</button>
  </div>

<?php else: ?>
  <?php if (!device_wajib_kode_aktif($settings)): ?>
    <div class="siap-note siap-note-warning small">
      Mode <strong>Wajib Kode Pasangan</strong> belum aktif, sehingga perangkat siswa masih terdaftar otomatis saat scan pertama.
      Aktifkan di <a href="<?= modul_url('pengaturan') ?>">Pengaturan → Pengaturan Absensi → Device Binding</a> agar QR/kode ini dipakai.
    </div>
  <?php endif; ?>
  <p class="mb-3">Buat <strong>QR Pasangan dan Kode 6 angka</strong> agar siswa dapat mendaftarkan perangkatnya (siswa memilih salah satu cara). Berlaku <?= (int) KODE_PASANGAN_MENIT ?> menit dan hanya bisa dipakai sekali. Yang lama dan belum terpakai akan dibatalkan.</p>
  <form method="post" action="<?= $aksiUrl ?>" data-popup-form>
    <?= csrf_field() ?>
    <input type="hidden" name="siswa_id" value="<?= (int) $siswaId ?>">
    <div class="d-flex justify-content-end gap-2">
      <button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs"><i class="bi bi-key"></i> Buat QR &amp; Kode</button>
    </div>
  </form>
<?php endif; ?>