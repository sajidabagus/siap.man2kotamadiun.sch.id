<?php
// Mode popup (?modal=1): hanya menampilkan formulir (tanpa layout), dimuat lewat AJAX ke dalam modal.
$modal = !empty($_REQUEST['modal']);
if ($modal) {
  require_once __DIR__ . '/../includes/auth.php';
  require_login();
} else {
  require_once __DIR__ . '/../includes/header.php';
}

$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_require();
  $db = getDB();

  $old = [
    'nis' => clean($_POST['nis'] ?? ''),
    'nisn' => clean($_POST['nisn'] ?? ''),
    'nama' => clean($_POST['nama'] ?? ''),
    'jenis_kelamin' => clean($_POST['jenis_kelamin'] ?? 'L'),
    'tempat_lahir' => clean($_POST['tempat_lahir'] ?? ''),
    'tanggal_lahir' => clean($_POST['tanggal_lahir'] ?? ''),
    'kelas' => clean($_POST['kelas'] ?? ''),
    'jurusan' => clean($_POST['jurusan'] ?? ''),
    'tahun_pelajaran' => clean($_POST['tahun_pelajaran'] ?? ''),
    'status' => clean($_POST['status'] ?? 'aktif'),
  ];

  if ($old['nis'] === '') $errors[] = 'NIS wajib diisi.';
  if ($old['nama'] === '') $errors[] = 'Nama wajib diisi.';
  if ($old['kelas'] === '') $errors[] = 'Kelas wajib diisi.';
  if ($old['tahun_pelajaran'] === '') $errors[] = 'Tahun pelajaran wajib diisi.';

  if (!$errors) {
    $db = getDB();
    $cek = $db->prepare('SELECT id FROM siswa WHERE nis = :nis');
    $cek->execute([':nis' => $old['nis']]);
    if ($cek->fetch()) $errors[] = 'NIS "' . $old['nis'] . '" sudah digunakan siswa lain.';

    if ($old['nisn'] !== '') {
      $cekNisn = $db->prepare('SELECT id FROM siswa WHERE nisn = :nisn');
      $cekNisn->execute([':nisn' => $old['nisn']]);
      if ($cekNisn->fetch()) $errors[] = 'NISN "' . $old['nisn'] . '" sudah digunakan siswa lain.';
    }
  }

  $foto = null;
  if (!$errors && !empty($_FILES['foto']['name'])) {
    try {
      $foto = handle_upload_foto($_FILES['foto']);
    } catch (RuntimeException $e) {
      $errors[] = $e->getMessage();
    }
  }

  if (!$errors) {
    // Pastikan qr_token benar-benar unik.
    do {
      $qrToken = generate_token(32);
      $cekToken = $db->prepare('SELECT id FROM siswa WHERE qr_token = :t');
      $cekToken->execute([':t' => $qrToken]);
    } while ($cekToken->fetch());

    $stmt = $db->prepare(
      'INSERT INTO siswa (nis, nisn, nama, jenis_kelamin, tempat_lahir, tanggal_lahir, kelas, jurusan, tahun_pelajaran, foto, qr_token, status, created_at, updated_at)
             VALUES (:nis, :nisn, :nama, :jk, :tempat, :tgl, :kelas, :jurusan, :tahun, :foto, :token, :status, NOW(), NOW())'
    );
    $stmt->execute([
      ':nis' => $old['nis'],
      ':nisn' => $old['nisn'] ?: null,
      ':nama' => $old['nama'],
      ':jk' => $old['jenis_kelamin'],
      ':tempat' => $old['tempat_lahir'] ?: null,
      ':tgl' => $old['tanggal_lahir'] ?: null,
      ':kelas' => $old['kelas'],
      ':jurusan' => $old['jurusan'] ?: null,
      ':tahun' => $old['tahun_pelajaran'],
      ':foto' => $foto,
      ':token' => $qrToken,
      ':status' => $old['status'],
    ]);

    catat_aktivitas('TAMBAH', 'siswa_tambah', 'Menambah siswa ' . ($old['nama'] ?? '') . ' (NISN ' . (($old['nisn'] ?? '') ?: '-') . ', kelas ' . ($old['kelas'] ?? '') . ')');
    set_flash('success', 'Siswa berhasil ditambahkan.');
    if ($modal) { header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit; }
    header('Location: ' . modul_url('siswa'));
    exit;
  }
}
?>
<?php if (!$modal): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-person-plus"></i> Tambah Siswa</h4>
  <a href="<?= modul_url('siswa') ?>" class="siap-btn siap-btn-ghost siap-mono-xs"><i class="bi bi-arrow-left"></i> Kembali ke Data Siswa</a>
</div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul>
  </div>
<?php endif; ?>

<?php if (!$modal): ?><div class="card">
  <div class="card-body"><?php endif; ?>
    <form method="post" enctype="multipart/form-data"<?= $modal ? ' action="' . e(modul_url('siswa_tambah', ['modal' => 1])) . '" data-popup-form' : '' ?>>
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">NIS *</label>
          <input type="text" name="nis" class="form-control" value="<?= e($old['nis'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">NISN *</label>
          <input type="text" name="nisn" class="form-control" value="<?= e($old['nisn'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Nama Lengkap *</label>
          <input type="text" name="nama" class="form-control" value="<?= e($old['nama'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Jenis Kelamin</label>
          <select name="jenis_kelamin" class="form-select">
            <option value="L" <?= ($old['jenis_kelamin'] ?? '') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
            <option value="P" <?= ($old['jenis_kelamin'] ?? '') === 'P' ? 'selected' : '' ?>>Perempuan</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Tempat Lahir</label>
          <input type="text" name="tempat_lahir" class="form-control" value="<?= e($old['tempat_lahir'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Tanggal Lahir</label>
          <input type="date" name="tanggal_lahir" class="form-control" value="<?= e($old['tanggal_lahir'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Kelas *</label>
          <input type="text" name="kelas" class="form-control" placeholder="XI A" value="<?= e($old['kelas'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Jurusan</label>
          <input type="text" name="jurusan" class="form-control" placeholder="IPA" value="<?= e($old['jurusan'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Tahun Pelajaran *</label>
          <input type="text" name="tahun_pelajaran" class="form-control" placeholder="2026/2027" value="<?= e($old['tahun_pelajaran'] ?? '2026/2027') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Foto Siswa</label>
          <input type="file" name="foto" class="form-control" accept=".jpg,.jpeg,.png,.webp">
          <small class="text-muted">Maks 2MB, format JPG/PNG/WEBP.</small>
        </div>
        <div class="col-md-6">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="aktif" <?= ($old['status'] ?? '') === 'aktif' ? 'selected' : '' ?>>Aktif</option>
            <option value="nonaktif" <?= ($old['status'] ?? '') === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
          </select>
        </div>
      </div>
      <div class="mt-4<?= $modal ? ' d-flex justify-content-end gap-2' : '' ?>">
        <?php if ($modal): ?>
          <button type="button" class="siap-btn siap-btn-ghost siap-mono-xs" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="siap-btn siap-btn-blue siap-mono-xs"><i class="bi bi-save"></i> Simpan</button>
        <?php else: ?>
          <button type="submit" class="siap-btn siap-btn-blue siap-mono-xs"><i class="bi bi-save"></i> Simpan</button>
          <a href="<?= modul_url('siswa') ?>" class="siap-btn siap-btn-ghost siap-mono-xs">Batal</a>
        <?php endif; ?>
      </div>
    </form>
<?php if (!$modal): ?>  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?><?php endif; ?>