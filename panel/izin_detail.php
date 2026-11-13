<?php

/** Tinjau satu pengajuan izin/sakit: baca alasan & lampiran, lalu setujui / tolak / cabut. */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/izin.php';

$db = getDB();
$bolehProses = can('izin.proses');
$admin = current_admin() ?: [];
$id = (int) ($_GET['id'] ?? 0);

if (!izin_siapkan_tabel() || !($row = izin_ambil($id))) {
  set_flash('danger', 'Pengajuan tidak ditemukan.');
  header('Location: ' . modul_url('izin'));
  exit;
}

// ---------------------------------------------------------------------
// KEPUTUSAN (POST)
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $balik = modul_url('izin_detail', ['id' => $id]);

  if (!$bolehProses) {
    set_flash('danger', 'Anda tidak memiliki izin untuk memproses pengajuan.');
    header('Location: ' . $balik);
    exit;
  }

  if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.');
    header('Location: ' . $balik);
    exit;
  }

  $aksi = (string) ($_POST['aksi'] ?? '');
  $catatan = trim((string) ($_POST['catatan'] ?? ''));

  $ringkasLog = $row['nama'] . ' (' . $row['kelas'] . '), ' .
    izin_label_jenis($row['jenis']) . ' ' .
    izin_teks_rentang($row['tanggal_mulai'], $row['tanggal_selesai']);

  if ($aksi === 'setujui') {

    $h = izin_setujui($id, $admin, $catatan);

    if ($h['ok']) {
      catat_aktivitas(
        'UBAH',
        'izin',
        "Menyetujui pengajuan #$id: $ringkasLog ({$h['diterapkan']} hari ditulis ke presensi)"
      );

      set_flash(
        'success',
        'Pengajuan disetujui. ' .
          $h['diterapkan'] .
          ' hari presensi ditulis' .
          ($h['dilewati']
            ? ', ' . $h['dilewati'] . ' hari dilewati karena presensi sudah tercatat.'
            : '.')
      );

      header('Location: ' . modul_url('izin'));
      exit;
    }

    set_flash('danger', $h['pesan']);
  } elseif ($aksi === 'tolak') {

    if (mb_strlen($catatan) < 5) {
      set_flash('danger', 'Alasan penolakan wajib diisi minimal 5 karakter.');
      header('Location: ' . $balik);
      exit;
    }

    $h = izin_tolak($id, $admin, $catatan);

    if ($h['ok']) {
      catat_aktivitas(
        'UBAH',
        'izin',
        "Menolak pengajuan #$id: $ringkasLog"
      );

      set_flash('success', 'Pengajuan ditolak dan siswa sudah diberi tahu.');
      header('Location: ' . modul_url('izin'));
      exit;
    }

    set_flash('danger', $h['pesan']);
  } elseif ($aksi === 'cabut') {

    if (mb_strlen($catatan) < 5) {
      set_flash('danger', 'Alasan pencabutan wajib diisi minimal 5 karakter.');
      header('Location: ' . $balik);
      exit;
    }

    $h = izin_cabut($id, $admin, $catatan);

    if ($h['ok']) {
      catat_aktivitas(
        'UBAH',
        'izin',
        "Mencabut persetujuan #$id: $ringkasLog ({$h['dicabut']} baris presensi dihapus)"
      );

      set_flash(
        'success',
        'Persetujuan dicabut. ' .
          $h['dicabut'] .
          ' baris presensi izin/sakit dihapus dan siswa diberi tahu.'
      );
    } else {
      set_flash('danger', $h['pesan']);
    }
  }

  header('Location: ' . $balik);
  exit;
}

// ---------------------------------------------------------------------
// KONTEKS UNTUK PETUGAS
// ---------------------------------------------------------------------

$efektif = izin_efektif_dalam(
  izin_rentang($row['tanggal_mulai'], $row['tanggal_selesai'])
);

$sid = (int) $row['siswa_id'];

$st = $db->prepare(
  'SELECT tanggal, status, metode
   FROM presensi
   WHERE siswa_id = :s
   ORDER BY tanggal DESC, id DESC
   LIMIT 6'
);

$st->execute([':s' => $sid]);
$riwayat = $st->fetchAll();

$st = $db->prepare(
  "SELECT jenis, COUNT(*) c,
   COALESCE(SUM(hari_diterapkan), 0) h
   FROM izin_pengajuan
   WHERE siswa_id = :s
   AND status = 'disetujui'
   AND tanggal_mulai >= (CURDATE() - INTERVAL 30 DAY)
   GROUP BY jenis"
);

$st->execute([':s' => $sid]);

$sebulan = [
  'izin' => [0, 0],
  'sakit' => [0, 0]
];

foreach ($st->fetchAll() as $x) {
  $sebulan[$x['jenis']] = [
    (int) $x['c'],
    (int) $x['h']
  ];
}

$st = $db->prepare(
  "SELECT COUNT(*)
   FROM izin_pengajuan
   WHERE siswa_id = :s
   AND status = 'menunggu'
   AND id <> :id"
);

$st->execute([
  ':s' => $sid,
  ':id' => $id
]);

$lainMenunggu = (int) $st->fetchColumn();

$st = $db->prepare(
  'SELECT hasil, COUNT(*) c
   FROM izin_hari
   WHERE pengajuan_id = :p
   GROUP BY hasil'
);

$st->execute([':p' => $id]);

$hari = [
  'diterapkan' => 0,
  'sudah_ada' => 0,
  'dipakai_hadir' => 0,
  'dicabut' => 0
];

foreach ($st->fetchAll() as $x) {
  $hari[$x['hasil']] = (int) $x['c'];
}

$warnaStatus = [
  'menunggu' => 'warning',
  'disetujui' => 'success',
  'ditolak' => 'danger',
  'dibatalkan' => 'secondary',
  'dicabut' => 'dark'
];

$warnaPresensi = [
  'HADIR' => 'success',
  'TERLAMBAT' => 'warning',
  'IZIN' => 'info',
  'SAKIT' => 'primary',
  'ALPA' => 'danger'
];

$perluSurat = $row['jenis'] === 'sakit' && count($efektif) > 1;

// ---------------------------------------------------------------------
// REKOMENDASI KALIMAT
// ---------------------------------------------------------------------

if ($row['jenis'] === 'sakit') {

  $rekomendasiSetuju = [
    'disetujui' =>
    'Pengajuan izin sakit telah disetujui. Semoga segera diberikan kesembuhan dan dapat kembali mengikuti kegiatan belajar di sekolah.',

    'bukti' =>
    'Pengajuan izin sakit disetujui berdasarkan alasan dan bukti pendukung yang telah diperiksa. Semoga lekas sembuh.',

    'pemulihan' =>
    'Pengajuan disetujui. Silakan beristirahat dan menjaga kesehatan agar dapat kembali beraktivitas seperti biasa.',

    'singkat' =>
    'Pengajuan sakit disetujui. Semoga lekas sembuh.'
  ];
} else {

  $rekomendasiSetuju = [
    'disetujui' =>
    'Pengajuan izin telah disetujui. Silakan menggunakan waktu izin dengan sebaik-baiknya dan kembali mengikuti kegiatan belajar sesuai jadwal.',

    'bukti' =>
    'Pengajuan izin disetujui berdasarkan alasan dan keterangan yang telah diperiksa.',

    'keperluan' =>
    'Pengajuan izin telah disetujui. Semoga keperluan dapat diselesaikan dengan baik.',

    'singkat' =>
    'Pengajuan izin disetujui.'
  ];
}

$rekomendasiTolak = [
  'lampiran' =>
  'Mohon maaf, pengajuan belum dapat disetujui karena dokumen atau bukti pendukung belum lengkap. Silakan lengkapi persyaratan dan ajukan kembali.',

  'alasan' =>
  'Mohon maaf, pengajuan belum dapat disetujui karena alasan yang disampaikan belum cukup jelas. Silakan berikan keterangan yang lebih lengkap.',

  'aturan' =>
  'Mohon maaf, pengajuan belum dapat disetujui karena belum memenuhi ketentuan yang berlaku di sekolah.',

  'tanggal' =>
  'Mohon maaf, pengajuan belum dapat disetujui karena terdapat ketidaksesuaian tanggal. Silakan periksa dan ajukan kembali dengan tanggal yang benar.',

  'verifikasi' =>
  'Mohon maaf, setelah dilakukan pemeriksaan, pengajuan belum dapat disetujui berdasarkan hasil verifikasi petugas.',

  'singkat' =>
  'Mohon maaf, pengajuan izin/sakit belum dapat disetujui. Silakan menghubungi pihak sekolah untuk mendapatkan informasi lebih lanjut.'
];

?>

<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-2 mb-3">
  <h4 class="mb-0">
    <i class="bi bi-envelope-paper"></i>
    Tinjau Pengajuan #<?= $id ?>
  </h4>

  <a href="<?= e(modul_url('izin')) ?>" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> Kembali ke antrean
  </a>
</div>

<div class="row g-3">

  <div class="col-lg-7">

    <!-- INFORMASI SISWA -->
    <div class="card mb-3">
      <div class="card-body">

        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">

          <div>
            <h5 class="mb-0">
              <a class="text-decoration-none text-reset"
                href="<?= e(modul_url('siswa_detail', ['nis' => $row['nis']])) ?>">
                <?= e($row['nama']) ?>
              </a>
            </h5>

            <div class="text-muted small">
              Kelas <?= e($row['kelas']) ?>
              · NIS <?= e($row['nis']) ?>
              · NISN <?= e($row['nisn'] ?: '-') ?>
            </div>
          </div>

          <span class="badge fs-6 bg-<?= $warnaStatus[$row['status']] ?? 'secondary' ?>">
            <?= e(IZIN_STATUS_LABEL[$row['status']] ?? $row['status']) ?>
          </span>

        </div>

        <dl class="row mb-0 small">

          <dt class="col-sm-4 text-muted fw-normal">Jenis</dt>

          <dd class="col-sm-8">
            <span class="badge bg-<?= $row['jenis'] === 'sakit' ? 'primary' : 'info' ?>">
              <?= e(izin_label_jenis($row['jenis'])) ?>
            </span>
          </dd>

          <dt class="col-sm-4 text-muted fw-normal">Tanggal</dt>

          <dd class="col-sm-8">
            <?= e(izin_teks_rentang($row['tanggal_mulai'], $row['tanggal_selesai'])) ?>
            <span class="text-muted">
              (<?= count($efektif) ?> hari sekolah)
            </span>
          </dd>

          <dt class="col-sm-4 text-muted fw-normal">Diajukan</dt>

          <dd class="col-sm-8">
            <?= e(date('d/m/Y H:i', strtotime($row['diajukan_at']))) ?>
          </dd>

          <?php if ($row['diproses_at']): ?>

            <dt class="col-sm-4 text-muted fw-normal">Diproses</dt>

            <dd class="col-sm-8">
              <?= e(date('d/m/Y H:i', strtotime($row['diproses_at']))) ?>
              oleh <?= e($row['diproses_nama'] ?: '-') ?>
            </dd>

          <?php endif; ?>

        </dl>

        <hr>

        <div class="small text-muted mb-1">Alasan dari siswa</div>

        <div class="p-3 bg-light rounded" style="white-space:pre-wrap">
          <?= e($row['alasan']) ?>
        </div>

        <?php if ($row['catatan_petugas']): ?>

          <div class="small text-muted mt-3 mb-1">Catatan petugas</div>

          <div class="p-3 rounded border-start border-4 <?= in_array($row['status'], ['ditolak', 'dicabut'], true) ? 'border-danger bg-danger-subtle' : 'border-success bg-success-subtle' ?>">
            <?= e($row['catatan_petugas']) ?>
          </div>

        <?php endif; ?>

        <?php if ($row['status'] === 'disetujui' || $row['status'] === 'dicabut'): ?>

          <div class="small text-muted mt-3">
            Dampak ke presensi:
            <b><?= $hari['diterapkan'] ?></b> hari ditulis
            <?= $hari['sudah_ada'] ? ', <b>' . $hari['sudah_ada'] . '</b> dilewati (sudah ada presensi)' : '' ?>
            <?= $hari['dipakai_hadir'] ? ', <b>' . $hari['dipakai_hadir'] . '</b> hari siswa tetap hadir' : '' ?>
            <?= $hari['dicabut'] ? ', <b>' . $hari['dicabut'] . '</b> dicabut' : '' ?>.
          </div>

        <?php endif; ?>

      </div>
    </div>

    <!-- LAMPIRAN -->
    <div class="card">
      <div class="card-body">

        <h6 class="mb-3">
          <i class="bi bi-paperclip"></i> Lampiran
        </h6>

        <?php if ($row['lampiran']): ?>

          <div class="small text-muted mb-2">
            <?= e($row['lampiran_nama'] ?: 'lampiran') ?>
            · membuka lampiran dicatat di Log Keamanan.
          </div>

          <?php if ($row['lampiran_tipe'] === 'application/pdf'): ?>

            <a class="btn btn-outline-primary"
              href="<?= e(modul_url('izin_lampiran', ['id' => $id])) ?>">
              <i class="bi bi-file-earmark-pdf"></i> Unduh PDF
            </a>

          <?php else: ?>

            <a href="<?= e(modul_url('izin_lampiran', ['id' => $id])) ?>"
              target="_blank"
              rel="noopener">

              <img src="<?= e(modul_url('izin_lampiran', ['id' => $id])) ?>"
                alt="Lampiran"
                class="img-fluid rounded border"
                style="max-height:420px">

            </a>

          <?php endif; ?>

        <?php elseif ($row['lampiran_dihapus_at']): ?>

          <div class="text-muted small">
            Berkas lampiran sudah dihapus
            (<?= e(date('d/m/Y', strtotime($row['lampiran_dihapus_at']))) ?>)
            sesuai kebijakan penyimpanan.
          </div>

        <?php else: ?>

          <div class="text-muted small">
            Tidak ada lampiran.
            <?= $perluSurat
              ? ' <span class="text-danger fw-semibold">Seharusnya wajib untuk sakit lebih dari 1 hari.</span>'
              : '' ?>
          </div>

        <?php endif; ?>

      </div>
    </div>

  </div>

  <!-- KOLOM KANAN -->
  <div class="col-lg-5">

    <!-- KONTEKS SISWA -->
    <div class="card mb-3">
      <div class="card-body">

        <h6 class="mb-3">
          <i class="bi bi-person-lines-fill"></i> Konteks siswa
        </h6>

        <div class="small mb-2">
          30 hari terakhir (disetujui):
          <b>Izin <?= $sebulan['izin'][0] ?>x</b>
          (<?= $sebulan['izin'][1] ?> hari)
          ·
          <b>Sakit <?= $sebulan['sakit'][0] ?>x</b>
          (<?= $sebulan['sakit'][1] ?> hari)
        </div>

        <?php if ($lainMenunggu): ?>

          <div class="small text-muted mb-2">
            Siswa ini punya <?= $lainMenunggu ?>
            pengajuan lain yang masih menunggu.
          </div>

        <?php endif; ?>

        <div class="small text-muted mb-1">
          Presensi terakhir
        </div>

        <?php if ($riwayat): ?>

          <ul class="list-unstyled mb-0 small">

            <?php foreach ($riwayat as $r): ?>

              <li class="d-flex justify-content-between py-1 border-bottom">

                <span>
                  <?= e(date('d/m/Y', strtotime($r['tanggal']))) ?>
                </span>

                <span class="badge bg-<?= $warnaPresensi[$r['status']] ?? 'secondary' ?>">
                  <?= e($r['status']) ?>
                  <?= $r['metode'] === 'PENGAJUAN'
                    ? ' · pengajuan'
                    : ($r['metode'] === 'OTOMATIS' ? ' · otomatis' : '') ?>
                </span>

              </li>

            <?php endforeach; ?>

          </ul>

        <?php else: ?>

          <div class="small text-muted">
            Belum ada presensi.
          </div>

        <?php endif; ?>

      </div>
    </div>

    <?php if ($bolehProses && $row['status'] === 'menunggu'): ?>

      <!-- SETUJUI PENGAJUAN -->
      <div class="card mb-3 border-success">

        <div class="card-body">

          <h6 class="mb-2 text-success">
            <i class="bi bi-check-circle"></i>
            Setujui Pengajuan
          </h6>

          <p class="small text-muted">
            Hari sekolah dalam rentang ini akan ditulis sebagai
            <b><?= $row['jenis'] === 'sakit' ? 'SAKIT' : 'IZIN' ?></b>
            di presensi. Hari yang sudah tercatat presensi dilewati.
          </p>

          <form method="post">

            <?= csrf_field() ?>

            <input type="hidden" name="aksi" value="setujui">

            <!-- REKOMENDASI SETUJU -->
            <label class="form-label small fw-semibold">
              <i class="bi bi-lightbulb text-warning"></i>
              Rekomendasi kalimat persetujuan
            </label>

            <select class="form-select form-select-sm mb-2"
              onchange="isiRekomendasi(this, 'catatan-setujui')">

              <option value="">-- Pilih rekomendasi kalimat --</option>

              <?php foreach ($rekomendasiSetuju as $key => $kalimat): ?>

                <option value="<?= e($kalimat) ?>">
                  <?= e(ucfirst($key)) ?>
                </option>

              <?php endforeach; ?>

            </select>

            <textarea
              id="catatan-setujui"
              name="catatan"
              class="form-control mb-2"
              rows="3"
              maxlength="<?= IZIN_CATATAN_MAKS ?>"
              placeholder="Catatan untuk siswa (opsional). Pilih rekomendasi atau tulis sendiri..."></textarea>

            <div class="form-text mb-3">
              Catatan ini akan disampaikan kepada siswa.
              Anda dapat mengubah kalimat sebelum menyetujui.
            </div>

            <button class="btn btn-success w-100"
              onclick="return confirm('Apakah Anda yakin ingin menyetujui pengajuan ini?')">

              <i class="bi bi-check2"></i>
              Setujui Pengajuan

            </button>

          </form>

        </div>
      </div>

      <!-- TOLAK PENGAJUAN -->
      <div class="card border-danger">

        <div class="card-body">

          <h6 class="mb-2 text-danger">
            <i class="bi bi-x-circle"></i>
            Tolak Pengajuan
          </h6>

          <p class="small text-muted">
            Pilih alasan penolakan yang sesuai dengan hasil pemeriksaan.
            Catatan wajib diisi dan akan dibaca siswa.
          </p>

          <form method="post"
            onsubmit="return confirm('Apakah Anda yakin ingin menolak pengajuan ini?')">

            <?= csrf_field() ?>

            <input type="hidden" name="aksi" value="tolak">

            <!-- REKOMENDASI PENOLAKAN -->
            <label class="form-label small fw-semibold">
              <i class="bi bi-lightbulb text-warning"></i>
              Rekomendasi kalimat penolakan
            </label>

            <select class="form-select form-select-sm mb-2"
              onchange="isiRekomendasi(this, 'catatan-tolak')">

              <option value="">-- Pilih alasan penolakan --</option>

              <?php foreach ($rekomendasiTolak as $key => $kalimat): ?>

                <option value="<?= e($kalimat) ?>">
                  <?= e(ucfirst($key)) ?>
                </option>

              <?php endforeach; ?>

            </select>

            <textarea
              id="catatan-tolak"
              name="catatan"
              class="form-control mb-2"
              rows="4"
              maxlength="<?= IZIN_CATATAN_MAKS ?>"
              placeholder="Tuliskan alasan penolakan atau pilih rekomendasi di atas..."
              required
              minlength="5"></textarea>

            <div class="form-text mb-3">
              Pastikan alasan penolakan disampaikan dengan bahasa yang
              sopan, jelas, dan mudah dipahami siswa.
            </div>

            <button class="btn btn-outline-danger w-100">

              <i class="bi bi-x"></i>
              Tolak Pengajuan

            </button>

          </form>

        </div>
      </div>

    <?php elseif ($bolehProses && $row['status'] === 'disetujui'): ?>

      <!-- CABUT PERSETUJUAN -->
      <div class="card border-dark">

        <div class="card-body">

          <h6 class="mb-2">
            <i class="bi bi-arrow-counterclockwise"></i>
            Cabut Persetujuan
          </h6>

          <p class="small text-muted">
            Menghapus baris presensi izin/sakit yang dibuat pengajuan ini.
            Presensi kehadiran asli tidak disentuh.
          </p>

          <form method="post"
            onsubmit="return confirm('Cabut persetujuan ini? Presensi izin/sakit akan dihapus.')">

            <?= csrf_field() ?>

            <input type="hidden" name="aksi" value="cabut">

            <textarea
              name="catatan"
              class="form-control mb-2"
              rows="2"
              maxlength="<?= IZIN_CATATAN_MAKS ?>"
              placeholder="Alasan pencabutan (wajib, akan dibaca siswa)"
              required
              minlength="5"></textarea>

            <button class="btn btn-outline-dark w-100">
              Cabut Persetujuan
            </button>

          </form>

        </div>
      </div>

    <?php elseif (!$bolehProses && $row['status'] === 'menunggu'): ?>

      <div class="alert alert-light border small">
        Anda dapat melihat pengajuan ini, tetapi tidak memiliki izin
        untuk memprosesnya.
      </div>

    <?php endif; ?>

  </div>
</div>

<!-- JAVASCRIPT REKOMENDASI KALIMAT -->
<script>
  function isiRekomendasi(select, textareaId) {
    const textarea = document.getElementById(textareaId);

    if (!textarea) {
      return;
    }

    if (select.value !== '') {
      textarea.value = select.value;
      textarea.focus();
    }
  }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>