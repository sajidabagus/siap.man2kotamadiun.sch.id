<?php
// Detail siswa — URL: /siswa/{nis}  (router mengisi $_GET['id'] dari NIS)
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/device_binding.php';

$db = getDB();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM siswa WHERE id = :id');
$stmt->execute([':id' => $id]);
$siswa = $stmt->fetch();

if (!$siswa) {
  set_flash('danger', 'Siswa tidak ditemukan.');
  header('Location: ' . modul_url('siswa'));
  exit;
}

$binding = get_device_binding($id);

// Rekap kehadiran bulan berjalan
$awalBulan = date('Y-m-01');
$st = $db->prepare('SELECT status, COUNT(*) c FROM presensi WHERE siswa_id = :id AND tanggal >= :awal GROUP BY status');
$st->execute([':id' => $id, ':awal' => $awalBulan]);
$rekap = ['HADIR' => 0, 'TERLAMBAT' => 0, 'IZIN' => 0, 'SAKIT' => 0, 'ALPA' => 0];
foreach ($st->fetchAll() as $r) $rekap[$r['status']] = (int) $r['c'];

// 15 presensi terakhir
$st = $db->prepare('SELECT tanggal, jam_masuk, status, kelas_saat_presensi, metode FROM presensi
                    WHERE siswa_id = :id ORDER BY tanggal DESC, jam_masuk DESC LIMIT 15');
$st->execute([':id' => $id]);
$riwayat = $st->fetchAll();

$warnaStatus = ['HADIR' => 'success', 'TERLAMBAT' => 'warning', 'IZIN' => 'info', 'SAKIT' => 'primary', 'ALPA' => 'danger'];
$warnaDevice = ['ACTIVE' => 'success', 'TRUSTED' => 'success', 'BLOCKED' => 'danger', 'UNREGISTERED' => 'secondary'];
$aktif = $siswa['status'] === 'aktif';
$inisial = mb_strtoupper(mb_substr((string) $siswa['nama'], 0, 1));
$jk = $siswa['jenis_kelamin'] === 'P' ? 'Perempuan' : 'Laki-laki';
$ttl = trim(($siswa['tempat_lahir'] ?: '') . ($siswa['tanggal_lahir'] ? ($siswa['tempat_lahir'] ? ', ' : '') . format_tanggal_indonesia($siswa['tanggal_lahir']) : ''));
?>

<div class="siap-eyebrow siap-stack-sm">Data &amp; Kelas</div>
<h2 class="siap-h2 mb-3"><i class="bi bi-person-vcard"></i> Detail Siswa</h2>

<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-3 mb-3">
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= e(modul_url('siswa')) ?>" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs"><i class="bi bi-arrow-left"></i> Kembali</a>
    <?php if (can('siswa.ubah')): ?>
    <a href="<?= e(modul_url('siswa_edit', ['nis' => $siswa['nis']])) ?>"
       data-popup="<?= e(modul_url('siswa_edit', ['nis' => $siswa['nis'], 'modal' => 1])) ?>" data-popup-title="Edit Siswa"
       class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs"><i class="bi bi-pencil"></i> Edit</a>
    <?php endif; ?>
    <?php if (can('qr_siswa.lihat')): ?>
    <a href="<?= e(modul_url('qr_generate', ['nis' => $siswa['nis']])) ?>" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs"><i class="bi bi-qr-code"></i> QR Code</a>
    <?php endif; ?>
    <?php if (can('device.lihat')): ?>
    <a href="<?= e(modul_url('device', ['siswa_id' => $id])) ?>" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs"><i class="bi bi-phone"></i> Perangkat</a>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="ps-card ps-card-pad h-100">
      <div class="card-body text-center">
        <?php if ($siswa['foto']): ?>
          <img src="<?= BASE_URL ?>/uploads/siswa/<?= e($siswa['foto']) ?>" alt="" class="rounded-circle mb-3" style="width:110px;height:110px;object-fit:cover">
        <?php else: ?>
          <span class="avatar mx-auto mb-3" style="width:110px;height:110px;font-size:2.4rem"><?= e($inisial) ?></span>
        <?php endif; ?>
        <h5 class="mb-1"><?= e($siswa['nama']) ?></h5>
        <p class="text-muted mb-2">NIS <?= e($siswa['nis']) ?></p>
        <span class="siap-badge siap-badge-<?= $aktif ? 'success' : 'info' ?> siap-mono-xs"><?= $aktif ? 'Aktif' : e(ucfirst((string) $siswa['status'])) ?></span>
        <span class="siap-badge siap-badge-info siap-mono-xs">Kelas <?= e($siswa['kelas']) ?></span>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="ps-card ps-card-pad h-100">
      <div class="card-body">
        <h6 class="mb-3">Data Siswa</h6>
        <dl class="row mb-0 small">
          <dt class="col-sm-4 text-muted fw-normal">NIS</dt><dd class="col-sm-8"><?= e($siswa['nis']) ?></dd>
          <dt class="col-sm-4 text-muted fw-normal">NISN</dt><dd class="col-sm-8"><?= e($siswa['nisn'] ?: '-') ?></dd>
          <dt class="col-sm-4 text-muted fw-normal">Jenis Kelamin</dt><dd class="col-sm-8"><?= e($jk) ?></dd>
          <dt class="col-sm-4 text-muted fw-normal">Tempat, Tgl. Lahir</dt><dd class="col-sm-8"><?= e($ttl !== '' ? $ttl : '-') ?></dd>
          <dt class="col-sm-4 text-muted fw-normal">Kelas</dt><dd class="col-sm-8"><?= e($siswa['kelas']) ?></dd>
          <dt class="col-sm-4 text-muted fw-normal">Jurusan</dt><dd class="col-sm-8"><?= e($siswa['jurusan'] ?: '-') ?></dd>
          <dt class="col-sm-4 text-muted fw-normal">Tahun Pelajaran</dt><dd class="col-sm-8"><?= e($siswa['tahun_pelajaran']) ?></dd>
        </dl>
      </div>
    </div>
  </div>

  <?php if (can('device.lihat')): ?>
  <div class="col-lg-6">
    <div class="ps-card ps-card-pad h-100">
      <div class="card-body">
        <h6 class="mb-3">Perangkat Terdaftar</h6>
        <?php if ($binding): ?>
          <dl class="row mb-0 small">
            <dt class="col-sm-5 text-muted fw-normal">Status</dt>
            <dd class="col-sm-7"><span class="siap-badge siap-badge-<?= $warnaDevice[$binding['status']] ?? 'info' ?> siap-mono-xs"><?= e($binding['status']) ?></span></dd>
            <dt class="col-sm-5 text-muted fw-normal">Perangkat</dt><dd class="col-sm-7"><?= e($binding['device_name'] ?: '-') ?></dd>
            <dt class="col-sm-5 text-muted fw-normal">Browser / OS</dt><dd class="col-sm-7"><?= e(trim(($binding['browser'] ?: '-') . ' / ' . ($binding['os'] ?: '-'))) ?></dd>
            <dt class="col-sm-5 text-muted fw-normal">Terdaftar</dt><dd class="col-sm-7"><?= e($binding['registered_at']) ?></dd>
            <dt class="col-sm-5 text-muted fw-normal">Terakhir dipakai</dt><dd class="col-sm-7"><?= e($binding['last_used_at'] ?: '-') ?></dd>
          </dl>
        <?php else: ?>
          <p class="text-muted small mb-0">Belum ada perangkat terdaftar. Perangkat akan terdaftar saat siswa melakukan presensi pertama atau memakai Kode Pasangan.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="<?= can('device.lihat') ? 'col-lg-6' : 'col-12' ?>">
    <div class="ps-card ps-card-pad h-100">
      <div class="card-body">
        <h6 class="mb-3">Kehadiran Bulan Ini <small class="text-muted fw-normal">(sejak <?= e(format_tanggal_indonesia($awalBulan)) ?>)</small></h6>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($rekap as $k => $n): ?>
            <span class="siap-badge siap-badge-<?= $warnaStatus[$k] ?? 'info' ?> fs-6 fw-normal siap-mono-xs"><?= e(ucfirst(strtolower($k))) ?>: <strong><?= $n ?></strong></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (can('presensi.lihat')): ?>
  <div class="col-12">
    <div class="ps-card ps-card-pad">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="mb-0">Riwayat Absensi Terakhir</h6>
          <a href="<?= e(modul_url('presensi', ['kelas' => $siswa['kelas']])) ?>" class="small text-decoration-none">Lihat semua &rarr;</a>
        </div>
        <?php if ($riwayat): ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Tanggal</th><th>Jam Masuk</th><th>Status</th><th>Kelas</th><th>Metode</th></tr></thead>
            <tbody>
            <?php foreach ($riwayat as $r): ?>
              <tr>
                <td><?= e(format_tanggal_indonesia($r['tanggal'])) ?></td>
                <td><?= e(substr((string) $r['jam_masuk'], 0, 5)) ?></td>
                <td><span class="siap-badge siap-badge-<?= $warnaStatus[$r['status']] ?? 'info' ?> siap-mono-xs"><?= e($r['status']) ?></span></td>
                <td><?= e($r['kelas_saat_presensi']) ?></td>
                <td><?= e($r['metode']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
          <p class="text-muted small mb-0">Belum ada data presensi.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
