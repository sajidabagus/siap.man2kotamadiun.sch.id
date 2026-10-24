<?php
// Mode popup (?modal=1): hanya menampilkan formulir (tanpa layout), dimuat lewat AJAX ke dalam modal.
$modal = !empty($_REQUEST['modal']);
if ($modal) {
  require_once __DIR__ . '/../includes/auth.php';
  require_login();
} else {
  require_once __DIR__ . '/../includes/header.php';
}

$id = (int)($_GET['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare('SELECT * FROM siswa WHERE id = :id');
$stmt->execute([':id' => $id]);
$siswa = $stmt->fetch();

if (!$siswa) {
  if ($modal) {
    echo '<div class="alert alert-danger mb-0">Siswa tidak ditemukan.</div>';
    exit;
  }
  set_flash('danger', 'Siswa tidak ditemukan.');
  header('Location: ' . modul_url('siswa'));
  exit;
}

$errors = [];
$kelasAsli = $siswa['kelas']; // simpan kelas SEBELUM diedit, utk deteksi & catat perpindahan

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_require();

  $kelasInput = clean($_POST['kelas'] ?? '');
  if ($kelasInput === '__baru__') {
    $kelasInput = clean($_POST['kelas_baru'] ?? '');
  }

  $data = [
    'nis' => clean($_POST['nis'] ?? ''),
    'nisn' => clean($_POST['nisn'] ?? ''),
    'nama' => clean($_POST['nama'] ?? ''),
    'jenis_kelamin' => clean($_POST['jenis_kelamin'] ?? 'L'),
    'tempat_lahir' => clean($_POST['tempat_lahir'] ?? ''),
    'tanggal_lahir' => clean($_POST['tanggal_lahir'] ?? ''),
    'kelas' => $kelasInput,
    'jurusan' => clean($_POST['jurusan'] ?? ''),
    'tahun_pelajaran' => clean($_POST['tahun_pelajaran'] ?? ''),
    'status' => clean($_POST['status'] ?? 'aktif'),
  ];

  if ($data['nis'] === '') $errors[] = 'NIS wajib diisi.';
  if ($data['nama'] === '') $errors[] = 'Nama wajib diisi.';
  if ($data['kelas'] === '') $errors[] = 'Kelas wajib diisi.';

  if (!$errors) {
    $cek = $db->prepare('SELECT id FROM siswa WHERE nis = :nis AND id != :id');
    $cek->execute([':nis' => $data['nis'], ':id' => $id]);
    if ($cek->fetch()) $errors[] = 'NIS "' . $data['nis'] . '" sudah digunakan siswa lain.';

    if ($data['nisn'] !== '') {
      $cekNisn = $db->prepare('SELECT id FROM siswa WHERE nisn = :nisn AND id != :id');
      $cekNisn->execute([':nisn' => $data['nisn'], ':id' => $id]);
      if ($cekNisn->fetch()) $errors[] = 'NISN "' . $data['nisn'] . '" sudah digunakan siswa lain.';
    }
  }

  $foto = $siswa['foto'];
  if (!$errors && !empty($_FILES['foto']['name'])) {
    try {
      $foto = handle_upload_foto($_FILES['foto'], $siswa['foto']);
    } catch (RuntimeException $e) {
      $errors[] = $e->getMessage();
    }
  }

  if (!$errors) {
    $stmt = $db->prepare(
      'UPDATE siswa SET nis=:nis, nisn=:nisn, nama=:nama, jenis_kelamin=:jk, tempat_lahir=:tempat,
             tanggal_lahir=:tgl, kelas=:kelas, jurusan=:jurusan, tahun_pelajaran=:tahun, foto=:foto,
             status=:status, updated_at=NOW() WHERE id=:id'
    );
    $stmt->execute([
      ':nis' => $data['nis'],
      ':nisn' => $data['nisn'] ?: null,
      ':nama' => $data['nama'],
      ':jk' => $data['jenis_kelamin'],
      ':tempat' => $data['tempat_lahir'] ?: null,
      ':tgl' => $data['tanggal_lahir'] ?: null,
      ':kelas' => $data['kelas'],
      ':jurusan' => $data['jurusan'] ?: null,
      ':tahun' => $data['tahun_pelajaran'],
      ':foto' => $foto,
      ':status' => $data['status'],
      ':id' => $id,
    ]);

    $ketLog = "Mengubah data siswa {$data['nama']} (NISN " . ($data['nisn'] !== '' ? $data['nisn'] : '-') . ")";
    if ($kelasAsli !== $data['kelas']) {
      $ketLog .= " — pindah kelas \"$kelasAsli\" -> \"{$data['kelas']}\"";
    }
    catat_aktivitas('UBAH', 'siswa_edit', $ketLog);

    set_flash('success', 'Data siswa berhasil diperbarui.');
    if ($modal) {
      header('Content-Type: application/json');
      echo json_encode(['ok' => true]);
      exit;
    }
    header('Location: ' . modul_url('siswa'));
    exit;
  }
  $siswa = array_merge($siswa, $data);
}

$kelasList = $db->query('SELECT DISTINCT kelas FROM siswa ORDER BY kelas')->fetchAll(PDO::FETCH_COLUMN);
?>
<?php if (!$modal): ?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-pencil-square"></i> Edit Siswa</h4>
    <a href="<?= modul_url('siswa') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali ke Data Siswa</a>
  </div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul>
  </div>
<?php endif; ?>

<?php if (!$modal): ?><div class="card">
    <div class="card-body"><?php endif; ?>
    <form method="post" enctype="multipart/form-data" data-dirty-guard<?= $modal ? ' action="' . e(modul_url('siswa_edit', ['id' => (int) $id, 'modal' => 1])) . '" data-popup-form' : '' ?>>
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-3 text-center">
          <?php if ($siswa['foto']): ?>
            <img src="<?= BASE_URL ?>/uploads/siswa/<?= e($siswa['foto']) ?>" class="rounded-circle mb-2" width="100" height="100" style="object-fit:cover">
          <?php else: ?>
            <i class="bi bi-person-circle display-1 text-secondary"></i>
          <?php endif; ?>
        </div>
        <div class="col-md-9">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">NIS *</label>
              <input type="text" name="nis" class="form-control" value="<?= e($siswa['nis']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">NISN *</label>
              <input type="text" name="nisn" class="form-control" value="<?= e($siswa['nisn']) ?>" required>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Nama Lengkap *</label>
          <input type="text" name="nama" class="form-control" value="<?= e($siswa['nama']) ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Jenis Kelamin</label>
          <select name="jenis_kelamin" class="form-select">
            <option value="L" <?= $siswa['jenis_kelamin'] === 'L' ? 'selected' : '' ?>>Laki-laki</option>
            <option value="P" <?= $siswa['jenis_kelamin'] === 'P' ? 'selected' : '' ?>>Perempuan</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Tempat Lahir</label>
          <input type="text" name="tempat_lahir" class="form-control" value="<?= e($siswa['tempat_lahir']) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Tanggal Lahir</label>
          <input type="date" name="tanggal_lahir" class="form-control" value="<?= e($siswa['tanggal_lahir']) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Kelas *</label>
          <select name="kelas" id="selectKelas" class="form-select" required onchange="document.getElementById('wrapKelasBaru').classList.toggle('d-none', this.value !== '__baru__')">
            <?php $kelasSekarang = $siswa['kelas'];
            $adaDiList = in_array($kelasSekarang, $kelasList, true); ?>
            <?php foreach ($kelasList as $k): ?>
              <option value="<?= e($k) ?>" <?= $kelasSekarang === $k ? 'selected' : '' ?>><?= e($k) ?></option>
            <?php endforeach; ?>
            <?php if (!$adaDiList && $kelasSekarang !== ''): ?>
              <option value="<?= e($kelasSekarang) ?>" selected><?= e($kelasSekarang) ?> (kelas ini sudah tidak ada siswa lain)</option>
            <?php endif; ?>
            <option value="__baru__">+ Kelas baru...</option>
          </select>
          <div id="wrapKelasBaru" class="d-none mt-2">
            <input type="text" name="kelas_baru" class="form-control" placeholder="Ketik nama kelas baru, mis. XI-A">
          </div>
          <div class="form-text">Mengubah kelas akan tercatat di Log Keamanan. Riwayat presensi lama siswa tidak akan berubah.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Jurusan</label>
          <input type="text" name="jurusan" class="form-control" value="<?= e($siswa['jurusan']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Ganti Foto</label>
          <input type="file" name="foto" class="form-control" accept=".jpg,.jpeg,.png,.webp">
        </div>
        <div class="col-md-6">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="aktif" <?= $siswa['status'] === 'aktif' ? 'selected' : '' ?>>Aktif</option>
            <option value="nonaktif" <?= $siswa['status'] === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
          </select>
        </div>
      </div>
      <div class="mt-4<?= $modal ? ' d-flex justify-content-end gap-2' : '' ?>">
        <?php if ($modal): ?>
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Simpan Perubahan</button>
        <?php else: ?>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Simpan Perubahan</button>
          <a href="<?= modul_url('siswa') ?>" class="btn btn-outline-secondary">Batal</a>
        <?php endif; ?>
      </div>
    </form>
    <?php if (!$modal): ?>
    </div>
  </div>

  <?php require_once __DIR__ . '/../includes/footer.php'; ?><?php endif; ?>