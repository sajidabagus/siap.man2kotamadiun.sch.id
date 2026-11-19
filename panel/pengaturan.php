<?php
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$settings = get_settings();
$errors = [];

// Kolom identitas: hanya dipakai bila kolomnya sudah ada di tabel settings
// (lihat database/migration_identitas_sekolah.sql).
$kolIdentitas = [
  'alamat_sekolah' => 500,
  'nama_kepala' => 150,
  'npsn' => 20,
  'telepon_sekolah' => 30,
  'kota_sekolah' => 100,
  'email_sekolah' => 150,
  'website_sekolah' => 150,
  'nip_kepala' => 30,
  'instansi_induk' => 150,
  'instansi_kedua' => 150,
];

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

  foreach (['jam_masuk', 'batas_absen', 'batas_terlambat', 'pulang_buka', 'pulang_tutup'] as $kol) {
    if (isset($_POST[$kol]) && !is_string($_POST[$kol])) {
      $errors[] = 'Jam absensi wajib berupa teks JJ:MM, bukan daftar.';
      $_POST[$kol] = '';
    }
  }
  $jamErrors = jam_absensi_validasi($_POST);
  $errors = array_merge($errors, $jamErrors);
  foreach (['pulang_buka', 'pulang_tutup', 'terlambat_aktif'] as $kol) {
    if (!array_key_exists($kol, $settings)) {
      $errors[] = 'Jalankan php database/migrate_absen_pulang.php sebelum menyimpan Jam absensi.';
      break;
    }
  }
  $batasAbsenVal = (string) ($_POST['batas_absen'] ?? '') . ':00';

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
    foreach ($kolIdentitas as $kol => $maks) {
      if (array_key_exists($kol, $settings)) {
        $identitas[$kol] = mb_substr(clean($_POST[$kol] ?? ''), 0, $maks);
      }
    }
    $setIdentitas = '';
    foreach ($identitas as $kol => $_) $setIdentitas .= "$kol=:$kol, ";
    $punyaKode = array_key_exists('device_wajib_kode', $settings);
    $punyaBatasAbsen = array_key_exists('batas_absen', $settings);
    $setIdentitas .= 'pulang_buka=:pbuka, pulang_tutup=:ptutup, terlambat_aktif=:taktif, ';
    if ($punyaBatasAbsen) $setIdentitas .= 'batas_absen=:batasabsen, ';
    $punyaLogoTampil = array_key_exists('logo_tampil', $settings);
    if ($punyaLogoTampil) $setIdentitas .= 'logo_tampil=:logotampil, ';
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
      ':batas' => ($_POST['batas_terlambat'] ?? '') !== '' ? $_POST['batas_terlambat'] : '00:00:00',
      ':pbuka' => $_POST['pulang_buka'],
      ':ptutup' => $_POST['pulang_tutup'],
      ':taktif' => ($_POST['batas_terlambat'] ?? '') !== '' ? 1 : 0,
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
      + ($punyaBatasAbsen ? [':batasabsen' => $batasAbsenVal] : [])
      + ($punyaKode ? [':wajibkode' => isset($_POST['device_wajib_kode']) ? 1 : 0] : [])
      + ($punyaLogoTampil ? [':logotampil' => isset($_POST['logo_tampil']) ? 1 : 0] : []));

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
    'batas_absen' => clean($_POST['batas_absen'] ?? '10:00'),
    'pulang_buka' => clean($_POST['pulang_buka'] ?? ''),
    'pulang_tutup' => clean($_POST['pulang_tutup'] ?? ''),
    'terlambat_aktif' => ($_POST['batas_terlambat'] ?? '') !== '' ? 1 : 0,
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
  // Kolom opsional: hanya diisi ulang bila kolomnya memang ada (supaya field tidak muncul tanpa kolom)
  foreach ($kolIdentitas as $kol => $maks) {
    if (array_key_exists($kol, $settings)) $settings[$kol] = mb_substr(clean($_POST[$kol] ?? ''), 0, $maks);
  }
  if (array_key_exists('logo_tampil', $settings)) $settings['logo_tampil'] = isset($_POST['logo_tampil']) ? 1 : 0;
}
?>
<style>
  /* Kartu bergaya "Identitas sekolah" */
  .pn-card {
    background: #fff;
    border: 1px solid #e6ebf3;
    border-radius: 20px;
    box-shadow: 0 8px 24px -12px rgba(20, 33, 61, .18);
    padding: 22px 24px 24px;
  }

  .pn-head {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 20px;
  }

  .pn-ico {
    flex: 0 0 auto;
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e8f0ff;
    color: #2563eb;
    font-size: 1.35rem;
  }

  .pn-head h6 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 800;
    color: #0f1b3d;
  }

  .pn-head .sub {
    color: #6b7690;
    font-size: .86rem;
    margin-top: 1px;
  }

  .pn-card .form-label {
    font-weight: 600;
    font-size: .9rem;
    color: #1b2748;
    margin-bottom: .35rem;
  }

  .pn-card .form-control,
  .pn-card .form-select {
    min-height: 46px;
    border-radius: 12px;
    border: 1px solid #dde4ef;
    background-color: #f9fbfe;
    padding-left: 14px;
    padding-right: 14px;
  }

  .pn-card textarea.form-control {
    padding-top: 11px;
    padding-bottom: 11px;
  }

  .pn-card .form-control-color {
    width: 100%;
    padding: 6px;
  }

  .pn-card .form-control:focus,
  .pn-card .form-select:focus {
    background-color: #fff;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .15);
  }

  .pn-sep {
    border: 0;
    border-top: 1px solid #edf0f6;
    margin: 6px 0;
  }

  /* Kartu logo */
  .pn-logobox {
    border: 1.5px dashed #cfd9ea;
    background: #f7faff;
    border-radius: 16px;
    min-height: 220px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
  }

  .pn-logobox img {
    max-width: 100%;
    max-height: 200px;
    object-fit: contain;
    border-radius: 8px;
    transition: opacity .15s;
  }

  .pn-logobox img.redup {
    opacity: .3;
  }

  .pn-logo-kosong {
    text-align: center;
    color: #9aa5ba;
  }

  .pn-logo-kosong i {
    font-size: 2.8rem;
    display: block;
  }

  .pn-drop {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    margin-top: 14px;
    padding: 18px 12px;
    border: 1px solid #dde4ef;
    border-radius: 14px;
    background: #fff;
    cursor: pointer;
    text-align: center;
    color: #6b7690;
    font-size: .85rem;
    transition: border-color .15s, background .15s;
  }

  .pn-drop i {
    color: #2563eb;
    font-size: 1.2rem;
  }

  .pn-drop strong {
    color: #0f1b3d;
    font-size: .95rem;
  }

  .pn-drop:hover,
  .pn-drop.over {
    border-color: #2563eb;
    background: #f3f7ff;
  }

  .pn-switch {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    padding-left: 0;
    margin-top: 14px;
  }

  .pn-switch .form-check-input {
    float: none;
    margin: .2rem 0 0;
    width: 2.6rem;
    height: 1.35rem;
    flex: 0 0 auto;
    cursor: pointer;
  }

  .pn-switch .form-check-label {
    cursor: pointer;
  }

  .pn-switch .t {
    display: block;
    font-weight: 600;
    color: #1b2748;
  }

  .pn-switch .s {
    display: block;
    font-size: .82rem;
    color: #7a849a;
  }

  .pn-note {
    margin-top: 14px;
    padding: 12px 14px;
    border-radius: 12px;
    background: #f1f5fb;
    color: #5b667f;
    font-size: .82rem;
  }

  /* Pratinjau kop */
  .pn-kopbox {
    border: 1px dashed #d3dae6;
    border-radius: 16px;
    background: #fff;
    padding: 22px 24px 28px;
    color: #000;
    overflow-x: auto;
  }

  .pn-kop {
    position: relative;
    text-align: center;
    min-height: 40px;
  }

  .pn-kop-logo {
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 76px;
    height: 76px;
    object-fit: contain;
  }

  .pn-kop-teks {
    padding: 0 90px;
  }

  /* Ukuran huruf kop mengikuti dokumen Word: 14 / 12 / 12 / 9 / 9 pt */
  .pn-kop-teks {
    font-family: Arial, Helvetica, sans-serif;
    line-height: 1.25;
  }

  .pn-kop-teks .k1 {
    font-weight: 700;
    font-size: 14pt;
  }

  .pn-kop-teks .k1b,
  .pn-kop-teks .k2 {
    font-weight: 700;
    font-size: 12pt;
  }

  .pn-kop-teks .k3 {
    font-weight: 400;
    font-size: 9pt;
  }

  .pn-kop-garis {
    border-top: 3px solid #000;
    border-bottom: 1px solid #000;
    height: 5px;
    margin: 10px 0 18px;
  }

  .pn-kop-judul {
    text-align: center;
    font-weight: 700;
    font-size: 1.1rem;
    text-decoration: underline;
  }

  .pn-ttd {
    width: 260px;
    max-width: 100%;
    margin: 22px 40px 0 auto;
    text-align: center;
  }

  .pn-ttd-ruang {
    height: 92px;
  }

  .pn-ttd-nama {
    font-weight: 700;
    text-decoration: underline;
  }

  /* Bar simpan melayang di bawah */
  .pn-savebar {
    position: sticky;
    bottom: 12px;
    z-index: 20;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    background: #fff;
    border: 1px solid #e6ebf3;
    border-radius: 16px;
    box-shadow: 0 8px 24px -8px rgba(20, 33, 61, .25);
  }

  .pn-savestatus {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #6b7690;
    font-size: .9rem;
  }

  .pn-savestatus .dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #cbd3e1;
  }

  .pn-savebar.kotor .pn-savestatus {
    color: #8a5a00;
    font-weight: 600;
  }

  .pn-savebar.kotor .dot {
    background: #f59e0b;
  }

  .pn-saveaksi {
    display: flex;
    gap: 10px;
  }

  .pn-saveaksi .btn {
    border-radius: 12px;
    font-weight: 600;
    padding: .6rem 1.2rem;
  }

  /* Kartu logo ikut menempel di atas saat di-scroll (hanya saat tampil berdampingan) */
  @media (min-width: 1200px) and (min-height: 700px) {
    .pn-sticky {
      position: sticky;
      top: 72px;
      /* di bawah header aplikasi */
      align-self: flex-start;
    }

    /* Ringkas supaya seluruh kartu tetap kelihatan dalam layar */
    .pn-sticky .pn-card {
      padding: 16px 18px 18px;
      max-height: calc(100vh - 72px - 104px);
      /* sisakan ruang untuk header & bar simpan */
      overflow-y: auto;
    }

    .pn-sticky .pn-head {
      margin-bottom: 12px;
    }

    .pn-sticky .pn-logobox {
      min-height: 140px;
      padding: 10px;
    }

    .pn-sticky .pn-logobox img {
      max-height: 120px;
    }

    .pn-sticky .pn-drop {
      margin-top: 10px;
      padding: 12px 10px;
    }

    .pn-sticky .pn-switch {
      margin-top: 10px;
    }

    .pn-sticky .pn-note {
      margin-top: 10px;
      padding: 10px 12px;
      font-size: .78rem;
    }
  }

  @media (max-width: 575px) {
    .pn-card {
      padding: 18px 16px 20px;
    }

    .pn-savebar {
      flex-direction: column;
      align-items: stretch;
    }

    .pn-saveaksi .btn {
      flex: 1;
    }

    .pn-kop-teks {
      padding: 0;
    }

    .pn-kop-logo {
      display: none;
    }
  }
</style>
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
      <?php
      $ada = fn($k) => array_key_exists($k, $settings);
      $adaLogo = !empty($settings['logo']);
      $mesinKompres = kompres_mesin();
      ?>
      <div class="row g-3 align-items-start mb-3">
        <!-- Kiri: identitas sekolah -->
        <div class="col-xl-8">
          <div class="pn-card">
            <div class="pn-head">
              <span class="pn-ico"><i class="bi bi-building"></i></span>
              <div>
                <h6>Identitas sekolah</h6>
                <div class="sub">Dipakai pada kop laporan, kartu siswa, dan header aplikasi.</div>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label">Nama sekolah <span class="text-danger">*</span></label>
                <input type="text" name="nama_sekolah" class="form-control" value="<?= e($settings['nama_sekolah']) ?>" required>
              </div>
              <?php if ($ada('instansi_induk')): ?>
                <div class="col-12">
                  <label class="form-label">Instansi induk</label>
                  <input type="text" name="instansi_induk" class="form-control" maxlength="150" value="<?= e($settings['instansi_induk'] ?? '') ?>" placeholder="mis. Dinas Pendidikan dan Kebudayaan Provinsi ...">
                  <div class="form-text">Baris paling atas pada kop laporan. Kosongkan bila tidak diperlukan.</div>
                </div>
              <?php endif; ?>
              <?php if ($ada('instansi_kedua')): ?>
                <div class="col-12">
                  <label class="form-label">Instansi kedua</label>
                  <input type="text" name="instansi_kedua" class="form-control" maxlength="150" value="<?= e($settings['instansi_kedua'] ?? '') ?>" placeholder="mis. KANTOR KEMENTERIAN AGAMA KOTA MADIUN">
                  <div class="form-text">Baris kedua pada kop, di bawah instansi induk (mis. kantor atau dinas). Kosongkan bila tidak diperlukan.</div>
                </div>
              <?php endif; ?>
              <?php if ($ada('npsn') || $ada('telepon_sekolah')): ?>
                <div class="col-md-6">
                  <?php if ($ada('npsn')): ?>
                    <label class="form-label">NPSN</label>
                    <input type="text" name="npsn" class="form-control" maxlength="20" value="<?= e($settings['npsn'] ?? '') ?>">
                  <?php endif; ?>
                </div>
                <div class="col-md-6">
                  <?php if ($ada('telepon_sekolah')): ?>
                    <label class="form-label">Telepon</label>
                    <input type="text" name="telepon_sekolah" class="form-control" maxlength="30" value="<?= e($settings['telepon_sekolah'] ?? '') ?>">
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <div class="col-12">
                <label class="form-label">Alamat</label>
                <textarea name="alamat_sekolah" class="form-control" rows="2" maxlength="500" placeholder="Jalan, kelurahan/desa, kecamatan, kota, kode pos"><?= e($settings['alamat_sekolah'] ?? '') ?></textarea>
              </div>
              <?php if ($ada('kota_sekolah') || $ada('email_sekolah')): ?>
                <div class="col-md-6">
                  <?php if ($ada('kota_sekolah')): ?>
                    <label class="form-label">Kota</label>
                    <input type="text" name="kota_sekolah" class="form-control" maxlength="100" value="<?= e($settings['kota_sekolah'] ?? '') ?>">
                  <?php endif; ?>
                </div>
                <div class="col-md-6">
                  <?php if ($ada('email_sekolah')): ?>
                    <label class="form-label">Email sekolah</label>
                    <input type="email" name="email_sekolah" class="form-control" maxlength="150" value="<?= e($settings['email_sekolah'] ?? '') ?>">
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if ($ada('website_sekolah')): ?>
                <div class="col-12">
                  <label class="form-label">Website</label>
                  <input type="text" name="website_sekolah" class="form-control" maxlength="150" value="<?= e($settings['website_sekolah'] ?? '') ?>" placeholder="mis. sekolah.sch.id">
                </div>
              <?php endif; ?>
              <div class="col-md-6">
                <label class="form-label">Tahun pelajaran</label>
                <input type="text" name="tahun_pelajaran" class="form-control" value="<?= e($settings['tahun_pelajaran']) ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Warna tema</label>
                <input type="color" name="warna_tema" class="form-control form-control-color" value="<?= e($settings['warna_tema']) ?>">
              </div>
              <div class="col-12">
                <hr class="pn-sep">
              </div>
              <div class="col-md-6">
                <label class="form-label">Kepala sekolah</label>
                <input type="text" name="nama_kepala" class="form-control" maxlength="150" value="<?= e($settings['nama_kepala'] ?? '') ?>" placeholder="Nama lengkap beserta gelar">
                <div class="form-text">Tampil pada kop dan kolom tanda tangan hasil cetak.</div>
              </div>
              <?php if ($ada('nip_kepala')): ?>
                <div class="col-md-6">
                  <label class="form-label">NIP kepala sekolah</label>
                  <input type="text" name="nip_kepala" class="form-control" maxlength="30" value="<?= e($settings['nip_kepala'] ?? '') ?>">
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Kanan: logo sekolah (menempel di atas saat halaman di-scroll) -->
        <div class="col-xl-4 pn-sticky">
          <div class="pn-card">
            <div class="pn-head">
              <span class="pn-ico"><i class="bi bi-image"></i></span>
              <div>
                <h6>Logo sekolah</h6>
                <div class="sub">PNG, JPG, GIF, atau WEBP &middot; maks. 2 MB.</div>
              </div>
            </div>

            <div class="pn-logobox">
              <img id="previewLogo" alt="Logo sekolah" class="<?= $adaLogo ? '' : 'd-none' ?>" src="<?= $adaLogo ? e(BASE_URL . '/uploads/siswa/' . $settings['logo']) : '' ?>">
              <div id="logoKosong" class="pn-logo-kosong <?= $adaLogo ? 'd-none' : '' ?>"><i class="bi bi-image"></i>
                <div class="small mt-1">Belum ada logo tersimpan</div>
              </div>
            </div>

            <label class="pn-drop" id="dropLogo" for="inputLogo">
              <i class="bi bi-upload"></i>
              <strong>Pilih gambar</strong>
              <span>atau seret berkas ke sini</span>
            </label>
            <input type="file" name="logo" id="inputLogo" class="d-none" accept="image/*" data-kompres="1024" data-kompres-target="614400" onchange="previewLogoBaru(this)">

            <?php if ($adaLogo): ?>
              <div class="form-check form-switch pn-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="hapus_logo" id="hapusLogo" value="1">
                <label class="form-check-label" for="hapusLogo"><span class="t">Hapus logo saat ini</span><span class="s">Berkas dihapus setelah disimpan.</span></label>
              </div>
            <?php endif; ?>
            <?php if ($ada('logo_tampil')): ?>
              <div class="form-check form-switch pn-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="logo_tampil" id="logoTampil" value="1" <?= !empty($settings['logo_tampil']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="logoTampil"><span class="t">Tampilkan logo</span><span class="s">Header, halaman login, kop laporan, dan kartu siswa.</span></label>
              </div>
            <?php endif; ?>

            <div class="pn-note">
              Disarankan gambar persegi berlatar transparan.<?= $ada('logo_tampil') ? ' Bila &quot;Tampilkan logo&quot; dimatikan, berkas tetap tersimpan.' : '' ?> Gambar besar dikompres otomatis; biarkan kosong bila tidak ingin mengganti logo.
              <div class="mt-1 <?= $mesinKompres ? 'text-success' : 'text-warning' ?>">
                <i class="bi bi-<?= $mesinKompres ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                Kompresi gambar di server: <?= $mesinKompres === 'gd' ? 'aktif (GD)' : ($mesinKompres === 'imagick' ? 'aktif (Imagick)' : 'tidak tersedia &mdash; batas upload 2 MB berlaku jika browser tidak mengompres') ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <?php
      $bulanIndo = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
      $tglKop = (int) date('j') . ' ' . $bulanIndo[(int) date('n')] . ' ' . date('Y');
      $kotaKop = trim((string) ($settings['kota_sekolah'] ?? ''));
      // Baris alamat (9 pt): alamat + telepon; baris kontak (9 pt): NPSN, e-mail, website
      $barisAlamat = trim((string) ($settings['alamat_sekolah'] ?? ''));
      if (trim((string) ($settings['telepon_sekolah'] ?? '')) !== '') $barisAlamat = trim($barisAlamat . ' Tlp. ' . trim($settings['telepon_sekolah']));
      $kontakKop = [];
      if (trim((string) ($settings['npsn'] ?? '')) !== '') $kontakKop[] = 'NPSN : ' . trim($settings['npsn']);
      if (trim((string) ($settings['email_sekolah'] ?? '')) !== '') $kontakKop[] = 'E-mail : ' . trim($settings['email_sekolah']);
      if (trim((string) ($settings['website_sekolah'] ?? '')) !== '') $kontakKop[] = 'Website : ' . trim($settings['website_sekolah']);
      $logoKop = $adaLogo && (!array_key_exists('logo_tampil', $settings) || !empty($settings['logo_tampil']));
      ?>
      <div class="pn-card mb-3">
        <div class="pn-head">
          <span class="pn-ico"><i class="bi bi-eye"></i></span>
          <div>
            <h6>Pratinjau kop dan tanda tangan</h6>
            <div class="sub">Sesuai data yang sudah tersimpan. Simpan dulu untuk melihat perubahan.</div>
          </div>
        </div>
        <div class="pn-kopbox">
          <div class="pn-kop">
            <?php if ($logoKop): ?><img class="pn-kop-logo" src="<?= e(BASE_URL . '/uploads/siswa/' . $settings['logo']) ?>" alt=""><?php endif; ?>
            <div class="pn-kop-teks">
              <?php if (trim((string) ($settings['instansi_induk'] ?? '')) !== ''): ?><div class="k1"><?= e(mb_strtoupper(trim($settings['instansi_induk']))) ?></div><?php endif; ?>
              <?php if (trim((string) ($settings['instansi_kedua'] ?? '')) !== ''): ?><div class="k1b"><?= e(mb_strtoupper(trim($settings['instansi_kedua']))) ?></div><?php endif; ?>
              <div class="k2"><?= e(mb_strtoupper((string) $settings['nama_sekolah'])) ?></div>
              <?php if ($barisAlamat !== ''): ?><div class="k3"><?= e($barisAlamat) ?></div><?php endif; ?>
              <?php if ($kontakKop): ?><div class="k3"><?= implode('&nbsp;&nbsp;', array_map('e', $kontakKop)) ?></div><?php endif; ?>
            </div>
          </div>
          <div class="pn-kop-garis"></div>
          <div class="pn-kop-judul">CONTOH JUDUL LAPORAN</div>
          <div class="text-center">Contoh sub-judul laporan</div>
          <div class="pn-ttd">
            <div><?= e(($kotaKop !== '' ? $kotaKop . ', ' : '') . $tglKop) ?></div>
            <div>Kepala Sekolah,</div>
            <div class="pn-ttd-ruang"></div>
            <div class="pn-ttd-nama"><?= e(trim((string) ($settings['nama_kepala'] ?? '')) !== '' ? $settings['nama_kepala'] : '(nama kepala sekolah)') ?></div>
            <?php if (trim((string) ($settings['nip_kepala'] ?? '')) !== ''): ?><div>NIP. <?= e($settings['nip_kepala']) ?></div><?php endif; ?>
          </div>
        </div>
      </div>

    </div>

    <div class="tab-pane fade" id="tab-absensi" role="tabpanel">
      <?php require __DIR__ . '/_jam_absensi.php'; ?>
      <div class="mb-3"><label class="form-label" for="pesanSukses">Pesan Presensi Berhasil</label><input id="pesanSukses" type="text" name="pesan_sukses" class="form-control" value="<?= e($settings['pesan_sukses']) ?>"></div>

      <div class="pn-card mb-3">
        <div class="pn-head">
          <span class="pn-ico"><i class="bi bi-geo-alt"></i></span>
          <div>
            <h6>Geolocation / GPS</h6>
            <div class="sub">Validasi lokasi presensi berdasarkan koordinat sekolah.</div>
          </div>
        </div>
        <div class="row g-3">
          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="gps_aktif" id="gpsAktif" <?= $settings['gps_aktif'] ? 'checked' : '' ?>>
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
              <input class="form-check-input" type="checkbox" name="validasi_ip_aktif" id="ipAktif" <?= $settings['validasi_ip_aktif'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="ipAktif">Catat & tampilkan validasi IP (informatif)</label>
            </div>
          </div>
        </div>
      </div>

      <div class="pn-card mb-3">
        <div class="pn-head">
          <span class="pn-ico"><i class="bi bi-phone"></i></span>
          <div>
            <h6>Device Binding &amp; QR Dinamis</h6>
            <div class="sub">Pembatasan perangkat siswa dan masa berlaku QR gerbang.</div>
          </div>
        </div>
        <div class="row g-3">
          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="device_binding_aktif" id="devbind" <?= $settings['device_binding_aktif'] ? 'checked' : '' ?>>
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

      <div class="pn-card mb-3">
        <div class="pn-head">
          <span class="pn-ico"><i class="bi bi-calendar-week"></i></span>
          <div>
            <h6>Hari efektif presensi</h6>
            <div class="sub">Hari-hari ketika siswa dapat melakukan presensi.</div>
          </div>
        </div>
        <div>
          <p class="text-muted small mb-2">Presensi hanya bisa dilakukan pada hari yang dicentang. Di luar itu (mis. Sabtu &amp; Minggu) siswa tidak bisa absen. Ubah sesuai kebutuhan sekolah.</p>
          <?php
          $hariAktif = array_values(array_filter(array_map('intval', explode(',', (string) ($settings['hari_efektif'] ?? '1,2,3,4,5')))));
          foreach (NAMA_HARI as $n => $namaHari): ?>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="hari_efektif[]" id="hari<?= $n ?>" value="<?= $n ?>" <?= in_array($n, $hariAktif, true) ? 'checked' : '' ?>>
              <label class="form-check-label" for="hari<?= $n ?>"><?= $namaHari ?></label>
            </div>
          <?php endforeach; ?>
          <div class="w-100 small text-muted mt-2"><i class="bi bi-calendar-event"></i> Ini jadwal mingguan biasa. Untuk <b>hari libur nasional</b>, <b>ujian madrasah</b>, atau <b>kegiatan di luar kelas</b>, jadwalkan di menu <?= can_modul('agenda') ? '<a href="' . e(modul_url('agenda')) . '">Agenda Sekolah</a>' : 'Agenda Sekolah' ?> agar siswa tidak tercatat Alpa.</div>
        </div>
      </div>

    </div>

    <div class="tab-pane fade" id="tab-log" role="tabpanel">
      <div class="pn-card mb-3">
        <div class="pn-head">
          <span class="pn-ico"><i class="bi bi-shield-check"></i></span>
          <div>
            <h6>Log Keamanan</h6>
            <div class="sub">Pembersihan otomatis log yang sudah lama.</div>
          </div>
        </div>
        <div class="row g-3">
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

  <div class="pn-savebar" id="barSimpan">
    <div class="pn-savestatus"><span class="dot" id="barDot"></span><span id="barTeks">Belum ada perubahan</span></div>
    <div class="pn-saveaksi">
      <button type="button" class="btn btn-light border" id="btnBatal" disabled>Batalkan</button>
      <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Simpan Pengaturan</button>
    </div>
  </div>
</form>

<script>
  function previewLogoBaru(input) {
    if (!input.files || !input.files[0]) return;
    const img = document.getElementById('previewLogo');
    img.src = URL.createObjectURL(input.files[0]);
    img.classList.remove('d-none', 'redup');
    const kosong = document.getElementById('logoKosong');
    if (kosong) kosong.classList.add('d-none');
    // Kalau sedang pilih file baru, batalkan centang "hapus logo" biar tidak bentrok maksud.
    const hapus = document.getElementById('hapusLogo');
    if (hapus) hapus.checked = false;
  }

  document.addEventListener('DOMContentLoaded', function() {
    // Bar simpan: tandai bila ada perubahan, tombol Batalkan mengembalikan nilai awal
    var form = document.querySelector('form[data-dirty-guard]');
    var bar = document.getElementById('barSimpan'),
      teks = document.getElementById('barTeks'),
      batal = document.getElementById('btnBatal');
    if (form && bar) {
      var pv0 = document.getElementById('previewLogo'),
        kosong0 = document.getElementById('logoKosong');
      var awalSrc = pv0 ? pv0.getAttribute('src') : null,
        awalPv = pv0 ? pv0.classList.contains('d-none') : true,
        awalKosong = kosong0 ? kosong0.classList.contains('d-none') : false;
      var potret = function() {
        var out = [];
        new FormData(form).forEach(function(v, k) {
          out.push(k + '=' + (v instanceof File ? 'F:' + v.name + ':' + v.size : v));
        });
        return out.join('&');
      };
      var awal = potret();
      var perbarui = function() {
        var kotor = potret() !== awal;
        bar.classList.toggle('kotor', kotor);
        teks.textContent = kotor ? 'Ada perubahan yang belum disimpan' : 'Belum ada perubahan';
        batal.disabled = !kotor;
      };
      form.addEventListener('input', perbarui);
      form.addEventListener('change', perbarui);
      batal.addEventListener('click', function() {
        form.reset();
        if (pv0) {
          if (awalSrc !== null) pv0.setAttribute('src', awalSrc);
          pv0.classList.toggle('d-none', awalPv);
          pv0.classList.remove('redup');
        }
        if (kosong0) kosong0.classList.toggle('d-none', awalKosong);
        setTimeout(perbarui, 0);
      });
    }

    // Seret & lepas berkas logo
    var dz = document.getElementById('dropLogo'),
      inp = document.getElementById('inputLogo');
    if (dz && inp) {
      ['dragenter', 'dragover'].forEach(function(ev) {
        dz.addEventListener(ev, function(e) {
          e.preventDefault();
          dz.classList.add('over');
        });
      });
      ['dragleave', 'drop'].forEach(function(ev) {
        dz.addEventListener(ev, function(e) {
          e.preventDefault();
          dz.classList.remove('over');
        });
      });
      dz.addEventListener('drop', function(e) {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
          inp.files = e.dataTransfer.files;
          inp.dispatchEvent(new Event('change', {
            bubbles: true
          }));
        }
      });
    }
    // Redupkan pratinjau saat "Hapus logo" dicentang
    var hapus = document.getElementById('hapusLogo'),
      pv = document.getElementById('previewLogo');
    if (hapus && pv) hapus.addEventListener('change', function() {
      pv.classList.toggle('redup', hapus.checked);
    });
  });

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
      function(pos) {
        document.getElementById('latSekolah').value = pos.coords.latitude.toFixed(7);
        document.getElementById('lngSekolah').value = pos.coords.longitude.toFixed(7);
        const akurasi = Math.round(pos.coords.accuracy);
        status.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Lokasi terisi (akurasi kira-kira ' + akurasi + ' m).</span>' +
          (akurasi > 50 ? ' <span class="text-warning">Akurasi kurang presisi — coba di luar ruangan/dekat jendela untuk hasil lebih akurat.</span>' : '');
        const peringatan = document.getElementById('peringatanKoordinat');
        if (peringatan) peringatan.remove();
        btn.disabled = false;
      },
      function(err) {
        status.innerHTML = '<span class="text-danger">Gagal mengambil lokasi: ' + (err.message || 'izin lokasi ditolak/tidak tersedia') + '.</span>';
        btn.disabled = false;
      }, {
        enableHighAccuracy: true,
        timeout: 10000
      }
    );
  }
</script>

<script>
  // Dijalankan setelah halaman selesai dimuat (bootstrap dimuat di footer).
  document.addEventListener('DOMContentLoaded', function() {
    var KEY = 'pengaturan_tab';
    var tombol = document.querySelectorAll('#tabPengaturan [data-bs-toggle="pill"]');

    function tampil(id) {
      var el = document.getElementById(id);
      if (el) bootstrap.Tab.getOrCreateInstance(el).show();
    }
    // Pulihkan tab terakhir (mis. setelah simpan / error validasi)
    var simpan = null;
    try {
      simpan = sessionStorage.getItem(KEY);
    } catch (e) {}
    if (simpan) tampil(simpan);
    tombol.forEach(function(t) {
      t.addEventListener('shown.bs.tab', function() {
        try {
          sessionStorage.setItem(KEY, t.id);
        } catch (e) {}
      });
    });
    // Field wajib yang tidak valid di tab tersembunyi: buka tab-nya dulu
    document.querySelector('form[data-dirty-guard]').addEventListener('invalid', function(ev) {
      var pane = ev.target.closest('.tab-pane');
      if (pane && !pane.classList.contains('active')) {
        tampil('btn-' + pane.id.replace('tab-', ''));
      }
    }, true);
  });
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>