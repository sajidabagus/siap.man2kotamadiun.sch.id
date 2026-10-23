<?php
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$settings = get_settings();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $gpsAktif = isset($_POST['gps_aktif']) ? 1 : 0;
    $latInput = trim((string) clean($_POST['latitude_sekolah'] ?? ''));
    $lngInput = trim((string) clean($_POST['longitude_sekolah'] ?? ''));
    $latVal = $latInput === '' ? null : (float) $latInput;
    $lngVal = $lngInput === '' ? null : (float) $lngInput;

    // JANGAN biarkan GPS diaktifkan tanpa koordinat sekolah yang valid --
    // kalau ini lolos, koordinat akan tersimpan 0,0 (tengah laut) dan
    // SEMUA siswa akan ditolak presensi tanpa admin sadar kenapa.
    if ($gpsAktif && ($latVal === null || $lngVal === null || ($latVal == 0.0 && $lngVal == 0.0))) {
        $errors[] = 'Validasi lokasi GPS tidak bisa diaktifkan sebelum Latitude & Longitude sekolah diisi dengan benar (gunakan tombol "Ambil Lokasi Saat Ini" atau isi manual dari Google Maps).';
    }

    // Hari efektif presensi (ISO 1=Senin ... 7=Minggu), minimal satu hari.
    $hariPilih = array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['hari_efektif'] ?? [])),
        fn($h) => $h >= 1 && $h <= 7
    )));
    sort($hariPilih);
    if (!$hariPilih) {
        $errors[] = 'Pilih minimal satu hari efektif presensi.';
    }

    if (!$errors) {
    $logo = $settings['logo'] ?? null;
    if (!empty($_FILES['logo']['name'])) {
        try {
            $logo = handle_upload_foto($_FILES['logo'], $logo);
        } catch (RuntimeException $e) {
            set_flash('danger', $e->getMessage());
            header('Location: ' . modul_url('pengaturan'));
            exit;
        }
    } elseif (!empty($_POST['hapus_logo'])) {
        // Hapus file fisik lama juga, bukan cuma referensinya di database.
        if ($logo && file_exists(__DIR__ . '/../uploads/siswa/' . $logo)) {
            @unlink(__DIR__ . '/../uploads/siswa/' . $logo);
        }
        $logo = null;
    }

    $punyaHari = array_key_exists('hari_efektif', $settings);
    // Kolom identitas tambahan (hanya diikutkan bila kolomnya sudah ada di DB)
    $identitas = [];
    foreach (['alamat_sekolah' => 500, 'nama_kepala' => 150] as $kol => $maks) {
        if (array_key_exists($kol, $settings)) {
            $identitas[$kol] = mb_substr(clean($_POST[$kol] ?? ''), 0, $maks);
        }
    }
    $setIdentitas = '';
    foreach ($identitas as $kol => $_) $setIdentitas .= "$kol=:$kol, ";
    $punyaKode = array_key_exists('device_wajib_kode', $settings);
    if ($punyaKode) $setIdentitas .= 'device_wajib_kode=:wajibkode, ';
    $stmt = $db->prepare(
        'UPDATE settings SET ' . ($punyaHari ? 'hari_efektif=:hari, ' : '') . $setIdentitas . 'nama_sekolah=:nama, logo=:logo, tahun_pelajaran=:tahun, jam_masuk=:jam, batas_terlambat=:batas,
         latitude_sekolah=:lat, longitude_sekolah=:lng, radius_meter=:radius, gps_aktif=:gps, validasi_ip_aktif=:ipval,
         device_binding_aktif=:devbind, qr_dinamis_durasi_detik=:qrdur, maks_reset_device_per_bulan=:maksreset,
         pesan_sukses=:pesan, warna_tema=:warna, log_retensi_hari=:logretensi, updated_at=NOW()
         WHERE id = :id'
    );
    $stmt->execute([
        ':nama' => clean($_POST['nama_sekolah'] ?? ''),
        ':logo' => $logo,
        ':tahun' => clean($_POST['tahun_pelajaran'] ?? ''),
        ':jam' => clean($_POST['jam_masuk'] ?? '07:00'),
        ':batas' => clean($_POST['batas_terlambat'] ?? '07:15'),
        ':lat' => $latVal,
        ':lng' => $lngVal,
        ':radius' => (int) ($_POST['radius_meter'] ?? 100),
        ':gps' => $gpsAktif,
        ':ipval' => isset($_POST['validasi_ip_aktif']) ? 1 : 0,
        ':devbind' => isset($_POST['device_binding_aktif']) ? 1 : 0,
        ':qrdur' => (int) ($_POST['qr_dinamis_durasi_detik'] ?? 120),
        ':maksreset' => (int) ($_POST['maks_reset_device_per_bulan'] ?? 2),
        ':pesan' => clean($_POST['pesan_sukses'] ?? ''),
        ':warna' => clean($_POST['warna_tema'] ?? '#0d6efd'),
        ':logretensi' => (int) ($_POST['log_retensi_hari'] ?? 0),
        ':id' => $settings['id'],
    ] + ($punyaHari ? [':hari' => implode(',', $hariPilih)] : [])
      + array_combine(array_map(fn($k) => ":$k", array_keys($identitas)), array_values($identitas))
      + ($punyaKode ? [':wajibkode' => isset($_POST['device_wajib_kode']) ? 1 : 0] : []));

    catat_aktivitas('UBAH', 'pengaturan', 'Menyimpan pengaturan sistem');
    set_flash('success', 'Pengaturan berhasil disimpan.');
    header('Location: ' . modul_url('pengaturan'));
    exit;
    }

    // Ada error validasi -> pertahankan input yg baru diketik admin (jangan
    // reset ke nilai lama) supaya tidak perlu ngetik ulang semua field.
    $settings = array_merge($settings, [
        'nama_sekolah' => clean($_POST['nama_sekolah'] ?? ''),
        'tahun_pelajaran' => clean($_POST['tahun_pelajaran'] ?? ''),
        'alamat_sekolah' => clean($_POST['alamat_sekolah'] ?? ''),
        'nama_kepala' => clean($_POST['nama_kepala'] ?? ''),
        'warna_tema' => clean($_POST['warna_tema'] ?? '#0d6efd'),
        'jam_masuk' => clean($_POST['jam_masuk'] ?? '07:00'),
        'batas_terlambat' => clean($_POST['batas_terlambat'] ?? '07:15'),
        'pesan_sukses' => clean($_POST['pesan_sukses'] ?? ''),
        'gps_aktif' => $gpsAktif,
        'latitude_sekolah' => $latInput,
        'longitude_sekolah' => $lngInput,
        'radius_meter' => (int) ($_POST['radius_meter'] ?? 100),
        'validasi_ip_aktif' => isset($_POST['validasi_ip_aktif']) ? 1 : 0,
        'device_binding_aktif' => isset($_POST['device_binding_aktif']) ? 1 : 0,
        'device_wajib_kode' => isset($_POST['device_wajib_kode']) ? 1 : 0,
        'qr_dinamis_durasi_detik' => (int) ($_POST['qr_dinamis_durasi_detik'] ?? 120),
        'maks_reset_device_per_bulan' => (int) ($_POST['maks_reset_device_per_bulan'] ?? 2),
        'log_retensi_hari' => (int) ($_POST['log_retensi_hari'] ?? 0),
        'hari_efektif' => implode(',', $hariPilih),
    ]);
}
?>
<h4 class="mb-3"><i class="bi bi-gear"></i> Pengaturan</h4>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" data-dirty-guard>
  <?= csrf_field() ?>

  <ul class="nav nav-pills mb-3 flex-nowrap overflow-auto pb-1" id="tabPengaturan" role="tablist" style="white-space:nowrap">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="btn-identitas" data-bs-toggle="pill" data-bs-target="#tab-identitas" type="button" role="tab"><i class="bi bi-building"></i> Identitas Sekolah</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="btn-absensi" data-bs-toggle="pill" data-bs-target="#tab-absensi" type="button" role="tab"><i class="bi bi-qr-code-scan"></i> Pengaturan Absensi</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="btn-log" data-bs-toggle="pill" data-bs-target="#tab-log" type="button" role="tab"><i class="bi bi-shield-check"></i> Log Keamanan</button>
    </li>
  </ul>

  <div class="tab-content">
  <div class="tab-pane fade show active" id="tab-identitas" role="tabpanel">
  <div class="card mb-3">
    <div class="card-header">Identitas Sekolah</div>
    <div class="card-body row g-3">
      <div class="col-md-6">
        <label class="form-label">Nama Sekolah</label>
        <input type="text" name="nama_sekolah" class="form-control" value="<?= e($settings['nama_sekolah']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Tahun Pelajaran</label>
        <input type="text" name="tahun_pelajaran" class="form-control" value="<?= e($settings['tahun_pelajaran']) ?>">
      </div>
      <div class="col-12">
        <label class="form-label">Alamat Sekolah</label>
        <textarea name="alamat_sekolah" class="form-control" rows="2" maxlength="500" placeholder="Jalan, kelurahan/desa, kecamatan, kota, kode pos"><?= e($settings['alamat_sekolah'] ?? '') ?></textarea>
      </div>
      <div class="col-md-6">
        <label class="form-label">Nama Kepala Madrasah/Sekolah</label>
        <input type="text" name="nama_kepala" class="form-control" maxlength="150" value="<?= e($settings['nama_kepala'] ?? '') ?>" placeholder="Nama lengkap beserta gelar">
        <div class="form-text">Tampil pada kop dan kolom tanda tangan hasil cetak.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Warna Tema</label>
        <input type="color" name="warna_tema" class="form-control form-control-color" value="<?= e($settings['warna_tema']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Logo Sekolah</label>
        <?php if (!empty($settings['logo'])): ?>
          <div class="d-flex align-items-center gap-3 mb-2">
            <img id="previewLogo" src="<?= BASE_URL ?>/uploads/siswa/<?= e($settings['logo']) ?>" alt="Logo saat ini"
                 style="width:72px;height:72px;object-fit:contain;border:1px solid #dee2e6;border-radius:8px;background:#fff;padding:4px;">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="hapus_logo" id="hapusLogo" value="1">
              <label class="form-check-label small" for="hapusLogo">Hapus logo ini (tanpa mengganti dengan yang baru)</label>
            </div>
          </div>
        <?php else: ?>
          <img id="previewLogo" src="" alt="" class="d-none mb-2" style="width:72px;height:72px;object-fit:contain;border:1px solid #dee2e6;border-radius:8px;background:#fff;padding:4px;">
          <p class="text-muted small mb-2">Belum ada logo tersimpan.</p>
        <?php endif; ?>
        <input type="file" name="logo" id="inputLogo" class="form-control" accept="image/*" onchange="previewLogoBaru(this)">
        <small class="text-muted">Maks 2MB, format JPG/PNG/WEBP. Biarkan kosong kalau tidak ingin mengganti logo.</small>
      </div>
    </div>
  </div>

  </div>

  <div class="tab-pane fade" id="tab-absensi" role="tabpanel">
  <div class="card mb-3">
    <div class="card-header">Jam & Status Kehadiran</div>
    <div class="card-body row g-3">
      <div class="col-md-4">
        <label class="form-label">Jam Masuk</label>
        <input type="time" name="jam_masuk" class="form-control" value="<?= e(substr($settings['jam_masuk'],0,5)) ?>">
        <small class="text-muted">Presensi ditolak jika dilakukan sebelum jam ini (mencegah scan jauh di luar jam sekolah).</small>
      </div>
      <div class="col-md-4">
        <label class="form-label">Batas Terlambat</label>
        <input type="time" name="batas_terlambat" class="form-control" value="<?= e(substr($settings['batas_terlambat'],0,5)) ?>">
        <small class="text-muted">Presensi setelah jam ini otomatis berstatus TERLAMBAT.</small>
      </div>
      <div class="col-md-4">
        <label class="form-label">Pesan Presensi Berhasil</label>
        <input type="text" name="pesan_sukses" class="form-control" value="<?= e($settings['pesan_sukses']) ?>">
        <small class="text-muted">Ditampilkan ke siswa di halaman hasil presensi setelah berhasil.</small>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">Geolocation / GPS</div>
    <div class="card-body row g-3">
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="gps_aktif" id="gpsAktif" <?= $settings['gps_aktif']?'checked':'' ?>>
          <label class="form-check-label" for="gpsAktif">Aktifkan validasi lokasi GPS</label>
        </div>
        <?php if (empty($settings['latitude_sekolah']) && empty($settings['longitude_sekolah'])): ?>
          <p class="small text-danger mt-1 mb-0" id="peringatanKoordinat">
            <i class="bi bi-exclamation-triangle"></i> Koordinat sekolah belum diisi — GPS tidak bisa diaktifkan sebelum ini diisi.
          </p>
        <?php endif; ?>
      </div>
      <div class="col-md-4">
        <label class="form-label">Latitude Sekolah</label>
        <input type="text" name="latitude_sekolah" id="latSekolah" class="form-control" value="<?= e($settings['latitude_sekolah']) ?>" placeholder="mis. -7.5311234">
      </div>
      <div class="col-md-4">
        <label class="form-label">Longitude Sekolah</label>
        <input type="text" name="longitude_sekolah" id="lngSekolah" class="form-control" value="<?= e($settings['longitude_sekolah']) ?>" placeholder="mis. 111.5231234">
      </div>
      <div class="col-md-4">
        <label class="form-label">Radius (meter)</label>
        <input type="number" name="radius_meter" class="form-control" value="<?= e($settings['radius_meter']) ?>">
      </div>
      <div class="col-12">
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="ambilLokasiSaatIni()" id="btnAmbilLokasi">
          <i class="bi bi-geo-alt"></i> Ambil Lokasi Saat Ini
        </button>
        <span class="text-muted small ms-2">Berdiri di area sekolah lalu klik tombol ini untuk otomatis mengisi koordinat di atas (perlu izin lokasi browser).</span>
        <div id="statusLokasi" class="small mt-1"></div>
      </div>
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="validasi_ip_aktif" id="ipAktif" <?= $settings['validasi_ip_aktif']?'checked':'' ?>>
          <label class="form-check-label" for="ipAktif">Catat & tampilkan validasi IP (informatif)</label>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">Device Binding & QR Dinamis</div>
    <div class="card-body row g-3">
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="device_binding_aktif" id="devbind" <?= $settings['device_binding_aktif']?'checked':'' ?>>
          <label class="form-check-label" for="devbind"><strong>Aktifkan Device Binding</strong> (1 siswa = 1 perangkat)</label>
        </div>
      </div>
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="device_wajib_kode" id="devkode" <?= !empty($settings['device_wajib_kode']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="devkode"><strong>Wajib kode aktivasi dari petugas</strong> untuk mendaftarkan perangkat (lebih aman dari pendaftaran otomatis)</label>
        </div>
        <div class="form-text">Bila aktif, perangkat siswa tidak lagi terdaftar otomatis saat scan pertama maupun lewat tombol di portal. Satu-satunya jalan adalah kode aktivasi yang diterbitkan petugas di menu Akun Siswa: siswa memasukkan kode di aplikasi SIAP dan akun aktif sekaligus perangkat terdaftar. Untuk ganti HP, gunakan tombol Ganti HP di Akun Siswa (kode baru otomatis terbit).</div>
      </div>
      <div class="col-md-6">
        <label class="form-label">Durasi Token QR Gerbang (detik)</label>
        <input type="number" name="qr_dinamis_durasi_detik" class="form-control" value="<?= e($settings['qr_dinamis_durasi_detik']) ?>">
        <small class="text-muted">QR yang ditampilkan di monitor sekolah berganti otomatis setiap durasi ini.</small>
      </div>
      <div class="col-md-6">
        <label class="form-label">Maksimal Reset Perangkat per Siswa / Bulan</label>
        <input type="number" name="maks_reset_device_per_bulan" class="form-control" value="<?= e($settings['maks_reset_device_per_bulan']) ?>">
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">Hari Efektif Presensi</div>
    <div class="card-body">
      <p class="text-muted small mb-2">Presensi hanya bisa dilakukan pada hari yang dicentang. Di luar itu (mis. Sabtu &amp; Minggu) siswa tidak bisa absen. Ubah sesuai kebutuhan sekolah.</p>
      <?php
      $hariAktif = array_values(array_filter(array_map('intval', explode(',', (string) ($settings['hari_efektif'] ?? '1,2,3,4,5')))));
      foreach (NAMA_HARI as $n => $namaHari): ?>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="checkbox" name="hari_efektif[]" id="hari<?= $n ?>" value="<?= $n ?>" <?= in_array($n, $hariAktif, true) ? 'checked' : '' ?>>
          <label class="form-check-label" for="hari<?= $n ?>"><?= $namaHari ?></label>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  </div>

  <div class="tab-pane fade" id="tab-log" role="tabpanel">
  <div class="card mb-3">
    <div class="card-header">Log Keamanan</div>
    <div class="card-body row g-3">
      <div class="col-md-6">
        <label class="form-label">Auto-reset Log Keamanan</label>
        <select name="log_retensi_hari" class="form-select">
          <?php
          $opsiRetensi = [0 => 'Nonaktif (simpan selamanya)', 30 => '1 bulan', 90 => '3 bulan', 180 => '6 bulan', 365 => '1 tahun'];
          $retensiSekarang = (int) ($settings['log_retensi_hari'] ?? 0);
          if (!isset($opsiRetensi[$retensiSekarang])) $opsiRetensi[$retensiSekarang] = $retensiSekarang . ' hari';
          foreach ($opsiRetensi as $hari => $label): ?>
            <option value="<?= $hari ?>" <?= $retensiSekarang === $hari ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="text-muted">Log yang lebih tua dari jangka waktu ini akan otomatis terhapus. Dicek setiap ada admin membuka halaman (maks 1x/hari), tidak perlu cron job.</small>
      </div>
      <div class="col-md-6 d-flex align-items-end">
        <p class="text-muted small mb-0">
          <?php if (!empty($settings['log_terakhir_dibersihkan'])): ?>
            Terakhir dibersihkan otomatis: <strong><?= e(date('d M Y H:i', strtotime($settings['log_terakhir_dibersihkan']))) ?></strong>
          <?php else: ?>
            Belum pernah dibersihkan otomatis.
          <?php endif; ?>
        </p>
      </div>
    </div>
  </div>

  </div>
  </div>

  <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Simpan Pengaturan</button>
</form>

<script>
function previewLogoBaru(input) {
  if (!input.files || !input.files[0]) return;
  const img = document.getElementById('previewLogo');
  img.src = URL.createObjectURL(input.files[0]);
  img.classList.remove('d-none');
  // Kalau sedang pilih file baru, batalkan centang "hapus logo" biar tidak bentrok maksud.
  const hapus = document.getElementById('hapusLogo');
  if (hapus) hapus.checked = false;
}

function ambilLokasiSaatIni() {
  const status = document.getElementById('statusLokasi');
  const btn = document.getElementById('btnAmbilLokasi');
  if (!navigator.geolocation) {
    status.innerHTML = '<span class="text-danger">Browser ini tidak mendukung geolocation.</span>';
    return;
  }
  btn.disabled = true;
  status.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm"></span> Mengambil lokasi...</span>';

  navigator.geolocation.getCurrentPosition(
    function (pos) {
      document.getElementById('latSekolah').value = pos.coords.latitude.toFixed(7);
      document.getElementById('lngSekolah').value = pos.coords.longitude.toFixed(7);
      const akurasi = Math.round(pos.coords.accuracy);
      status.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Lokasi terisi (akurasi kira-kira ' + akurasi + ' m).</span>' +
        (akurasi > 50 ? ' <span class="text-warning">Akurasi kurang presisi — coba di luar ruangan/dekat jendela untuk hasil lebih akurat.</span>' : '');
      const peringatan = document.getElementById('peringatanKoordinat');
      if (peringatan) peringatan.remove();
      btn.disabled = false;
    },
    function (err) {
      status.innerHTML = '<span class="text-danger">Gagal mengambil lokasi: ' + (err.message || 'izin lokasi ditolak/tidak tersedia') + '.</span>';
      btn.disabled = false;
    },
    { enableHighAccuracy: true, timeout: 10000 }
  );
}
</script>

<script>
// Dijalankan setelah halaman selesai dimuat (bootstrap dimuat di footer).
document.addEventListener('DOMContentLoaded', function () {
  var KEY = 'pengaturan_tab';
  var tombol = document.querySelectorAll('#tabPengaturan [data-bs-toggle="pill"]');
  function tampil(id) {
    var el = document.getElementById(id);
    if (el) bootstrap.Tab.getOrCreateInstance(el).show();
  }
  // Pulihkan tab terakhir (mis. setelah simpan / error validasi)
  var simpan = null;
  try { simpan = sessionStorage.getItem(KEY); } catch (e) {}
  if (simpan) tampil(simpan);
  tombol.forEach(function (t) {
    t.addEventListener('shown.bs.tab', function () {
      try { sessionStorage.setItem(KEY, t.id); } catch (e) {}
    });
  });
  // Field wajib yang tidak valid di tab tersembunyi: buka tab-nya dulu
  document.querySelector('form[data-dirty-guard]').addEventListener('invalid', function (ev) {
    var pane = ev.target.closest('.tab-pane');
    if (pane && !pane.classList.contains('active')) {
      tampil('btn-' + pane.id.replace('tab-', ''));
    }
  }, true);
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>