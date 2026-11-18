<?php
if (!isset($settings) || !is_array($settings) || !defined('BASE_URL')) { http_response_code(404); exit; }
// Bagian form Pengaturan; auth/izin dan CSRF tetap dimiliki halaman pemanggil.
$jamFields = [
    ['jam_masuk', 'Buka', $settings['jam_masuk'] ?? '07:00'],
    ['batas_absen', 'Tutup', $settings['batas_absen'] ?? '10:00'],
    ['batas_terlambat', 'Terlambat setelah', !isset($settings['terlambat_aktif']) || $settings['terlambat_aktif'] ? ($settings['batas_terlambat'] ?? '07:15') : ''],
    ['pulang_buka', 'Buka', $settings['pulang_buka'] ?? '13:00'],
    ['pulang_tutup', 'Tutup', $settings['pulang_tutup'] ?? '16:00'],
];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/jam-absensi.css?v=<?= filemtime(__DIR__ . '/../assets/css/jam-absensi.css') ?>">
<section class="jam-absensi mb-3" aria-labelledby="jamAbsensiJudul">
  <header><i class="bi bi-clock"></i><div><h5 id="jamAbsensiJudul">Jam absensi</h5><p>Sesi ditentukan otomatis dari jam server saat scan dikonfirmasi. Format 24 jam.</p></div></header>
  <div class="ja-panels">
    <?php foreach (['datang' => [0, 1, 2], 'pulang' => [3, 4]] as $sesi => $indices): ?>
    <section class="ja-panel ja-<?= $sesi ?>" aria-labelledby="ja-<?= $sesi ?>">
      <h6 id="ja-<?= $sesi ?>"><i class="bi bi-<?= $sesi === 'datang' ? 'sun' : 'box-arrow-right' ?>"></i><?= ucfirst($sesi) ?></h6>
      <p><?= $sesi === 'datang' ? 'Scan di luar jendela ini ditolak' : 'Mencatat jam pulang siswa yang sudah datang' ?></p>
      <div class="ja-inputs">
      <?php foreach ($indices as $i): [$name, $label, $value] = $jamFields[$i]; ?>
        <div<?= $i === 2 ? ' class="ja-wide"' : '' ?>>
          <label for="ja-<?= $name ?>"><?= $label ?><?= $i === 2 ? ' <small>kosongkan = tanpa batas terlambat</small>' : '' ?></label>
          <div class="ja-time"><input id="ja-<?= $name ?>" type="text" inputmode="numeric" maxlength="5" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="JJ:MM" name="<?= $name ?>" value="<?= e(substr((string) $value, 0, 5)) ?>" class="form-control"<?= $i === 2 ? '' : ' required' ?> aria-describedby="ja-hint-<?= $sesi ?>"><i class="bi bi-clock" aria-hidden="true"></i></div>
        </div>
      <?php endforeach; ?>
      </div>
      <p id="ja-hint-<?= $sesi ?>" class="ja-hint"><?= $sesi === 'datang' ? 'Scan tepat pada batas terlambat masih tepat waktu. Jam tutup tidak termasuk jendela; Alpa otomatis mengikuti tutup datang.' : 'Jendela pulang harus dimulai setelah datang ditutup. Belum pulang tidak otomatis Alpa.' ?></p>
    </section>
    <?php endforeach; ?>
  </div>
  <section class="ja-timeline" aria-labelledby="jaLinimasa">
    <h6 id="jaLinimasa">Linimasa jendela scan</h6>
    <div id="jaValidation" class="text-danger" role="status"></div>
    <div class="ja-track" id="jaTrack" aria-hidden="true"></div>
    <div class="ja-ticks" id="jaTicks" aria-hidden="true"></div>
    <div class="ja-legend"><span><i class="ja-good"></i>Tepat waktu</span><span><i class="ja-late"></i>Terlambat</span><span><i class="ja-out"></i>Pulang</span><span><i class="ja-closed"></i>Di luar jam: scan ditolak</span></div>
    <div id="jaSummary" class="ja-summary" aria-live="polite"></div>
  </section>
  <p class="ja-foot">Agenda Sekolah tetap dapat mengganti jam datang per kelas. Jika tutup datang agenda beririsan dengan pulang, sesi pulang tanggal/kelas itu ditutup demi keamanan; sesuaikan agenda/jam pulang.</p>
</section>
<script src="<?= BASE_URL ?>/assets/js/jam-absensi.js?v=<?= filemtime(__DIR__ . '/../assets/js/jam-absensi.js') ?>" defer></script>
