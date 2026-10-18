<?php
// Manajemen pengguna (admin & operator). Form tambah/edit dimuat sebagai popup (?modal=1).
$modal = !empty($_REQUEST['modal']);
if ($modal) {
  require_once __DIR__ . '/../includes/auth.php';
  require_login();
} else {
  require_once __DIR__ . '/../includes/header.php';
}
siapkan_hak_akses();

$db = getDB();
$saya = current_admin();
$errors = [];

// ---------- HAPUS ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hapus') {
  csrf_require();
  $id = (int) ($_POST['id'] ?? 0);
  $st = $db->prepare('SELECT * FROM users WHERE id = :id');
  $st->execute([':id' => $id]);
  $target = $st->fetch();
  if (!$target) {
    set_flash('danger', 'Pengguna tidak ditemukan.');
  } elseif ($id === (int) $saya['id']) {
    set_flash('danger', 'Anda tidak bisa menghapus akun Anda sendiri.');
  } else {
    try {
      $db->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);
      catat_aktivitas('HAPUS', 'pengguna', 'Menghapus pengguna ' . $target['nama'] . ' (@' . $target['username'] . ', ' . $target['role'] . ')');
      set_flash('success', 'Pengguna "' . $target['nama'] . '" berhasil dihapus.');
    } catch (Throwable $e) {
      set_flash('danger', 'Pengguna tidak dapat dihapus karena memiliki riwayat. Nonaktifkan saja.');
    }
  }
  header('Location: ' . modul_url('pengguna'));
  exit;
}

// ---------- FORM (popup) ----------
if ($modal) {
  $id = (int) ($_REQUEST['id'] ?? 0);
  $user = null;
  if ($id > 0) {
    $st = $db->prepare('SELECT * FROM users WHERE id = :id');
    $st->execute([':id' => $id]);
    $user = $st->fetch();
    if (!$user) { echo '<div class="alert alert-danger mb-0">Pengguna tidak ditemukan.</div>'; exit; }
  }
  $dirSendiri = $user && (int) $user['id'] === (int) $saya['id'];
  $data = [
    'nama' => $user['nama'] ?? '', 'username' => $user['username'] ?? '',
    'role' => $user['role'] ?? 'operator', 'status' => $user['status'] ?? 'aktif',
  ];

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan') {
    csrf_require();
    $data['nama'] = clean($_POST['nama'] ?? '');
    $data['username'] = trim((string) ($_POST['username'] ?? ''));
    $data['role'] = clean($_POST['role'] ?? '');
    $data['status'] = clean($_POST['status'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if ($dirSendiri) { $data['role'] = $user['role']; $data['status'] = $user['status']; } // cegah mengunci diri sendiri

    if ($data['nama'] === '') $errors[] = 'Nama wajib diisi.';
    if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $data['username'])) $errors[] = 'Username 3–50 karakter: huruf, angka, titik, atau garis bawah.';
    if (!isset(DAFTAR_ROLE[$data['role']])) $errors[] = 'Role tidak valid.';
    if (!in_array($data['status'], ['aktif', 'nonaktif'], true)) $errors[] = 'Status tidak valid.';
    if (!$user && strlen($password) < 6) $errors[] = 'Password wajib diisi minimal 6 karakter.';
    if ($user && $password !== '' && strlen($password) < 6) $errors[] = 'Password baru minimal 6 karakter.';
    if (!$errors) {
      $st = $db->prepare('SELECT id FROM users WHERE username = :u AND id <> :id');
      $st->execute([':u' => $data['username'], ':id' => $id]);
      if ($st->fetch()) $errors[] = 'Username sudah dipakai pengguna lain.';
    }

    if (!$errors) {
      if ($user) {
        $sql = 'UPDATE users SET nama=:n, username=:u, role=:r, status=:s' . ($password !== '' ? ', password=:p' : '') . ' WHERE id=:id';
        $par = [':n' => $data['nama'], ':u' => $data['username'], ':r' => $data['role'], ':s' => $data['status'], ':id' => $id];
        if ($password !== '') $par[':p'] = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare($sql)->execute($par);
        catat_aktivitas('UBAH', 'pengguna', 'Mengubah pengguna ' . $data['nama'] . ' (@' . $data['username'] . ', ' . $data['role'] . ')' . ($password !== '' ? ' dan mengganti password' : ''));
        set_flash('success', 'Pengguna berhasil diperbarui.');
      } else {
        $db->prepare('INSERT INTO users (nama, username, password, role, status) VALUES (:n, :u, :p, :r, :s)')
           ->execute([':n' => $data['nama'], ':u' => $data['username'], ':p' => password_hash($password, PASSWORD_DEFAULT), ':r' => $data['role'], ':s' => $data['status']]);
        catat_aktivitas('TAMBAH', 'pengguna', 'Menambah pengguna ' . $data['nama'] . ' (@' . $data['username'] . ', ' . $data['role'] . ')');
        set_flash('success', 'Pengguna berhasil ditambahkan.');
      }
      header('Content-Type: application/json');
      echo json_encode(['ok' => true]);
      exit;
    }
  }
  ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
  <?php endif; ?>
  <form method="post" autocomplete="off" action="<?= e(modul_url('pengguna', ['modal' => 1, 'id' => (int) $id])) ?>" data-popup-form<?= $user ? ' data-dirty-guard' : '' ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="simpan">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
        <input type="text" name="nama" class="form-control" value="<?= e($data['nama']) ?>" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Username <span class="text-danger">*</span></label>
        <input type="text" name="username" class="form-control" value="<?= e($data['username']) ?>" required autocapitalize="none" spellcheck="false">
      </div>
      <div class="col-md-6">
        <label class="form-label">Role</label>
        <select name="role" class="form-select" <?= $dirSendiri ? 'disabled' : '' ?>>
          <?php foreach (DAFTAR_ROLE as $kode => $nama): ?>
            <option value="<?= e($kode) ?>" <?= $data['role'] === $kode ? 'selected' : '' ?>><?= e($nama) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Status</label>
        <select name="status" class="form-select" <?= $dirSendiri ? 'disabled' : '' ?>>
          <option value="aktif" <?= $data['status'] === 'aktif' ? 'selected' : '' ?>>Aktif</option>
          <option value="nonaktif" <?= $data['status'] === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
        </select>
      </div>
      <?php if ($dirSendiri): ?>
        <div class="col-12"><div class="form-text mt-0">Role dan status akun Anda sendiri tidak dapat diubah dari sini.</div></div>
      <?php endif; ?>
      <div class="col-12">
        <label class="form-label">Password <?= $user ? '' : '<span class="text-danger">*</span>' ?></label>
        <input type="password" name="password" class="form-control" placeholder="<?= $user ? 'Kosongkan jika tidak diganti' : 'Minimal 6 karakter' ?>" <?= $user ? '' : 'required' ?>>
      </div>
    </div>
    <div class="mt-4 d-flex justify-content-end gap-2">
      <button type="button" class="siap-btn siap-btn-ghost siap-mono-xs" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="siap-btn siap-btn-blue siap-mono-xs"><i class="bi bi-save"></i> <?= $user ? 'Simpan Perubahan' : 'Simpan' ?></button>
    </div>
  </form>
  <?php
  exit;
}

// ---------- DAFTAR ----------
$users = $db->query('SELECT id, nama, username, role, status, created_at FROM users ORDER BY role, nama')->fetchAll();
$warnaAvatar = ['#20b2a0', '#4a9be8', '#a855c8', '#f59f0b', '#e5484d', '#2fb344', '#1d6fd8', '#8b5cf6'];
?>
<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-3 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-badge"></i> Pengguna</h4>
  <div class="d-flex gap-2">
    <a href="<?= modul_url('hak_akses') ?>" class="siap-btn siap-btn-ghost siap-mono-xs flex-fill flex-md-grow-0 text-nowrap d-inline-flex align-items-center justify-content-center gap-2"><i class="bi bi-shield-lock"></i><span>Hak Akses</span></a>
    <a href="<?= e(modul_url('pengguna', ['modal' => 1])) ?>" data-popup="<?= e(modul_url('pengguna', ['modal' => 1])) ?>" data-popup-title="Tambah Pengguna" class="siap-btn siap-btn-blue siap-mono-xs flex-fill flex-md-grow-0 text-nowrap d-inline-flex align-items-center justify-content-center gap-2"><i class="bi bi-plus-circle"></i><span>Tambah Pengguna</span></a>
  </div>
</div>

<div class="tbl-card">
  <div class="tbl-head">
    <h6>Daftar pengguna</h6>
    <div class="sub">Akun yang dapat masuk ke sistem beserta role-nya.</div>
  </div>
  <div class="table-responsive">
    <table class="table tbl align-middle">
      <thead><tr><th>Pengguna</th><th>Username</th><th>Role</th><th>Status</th><th>Dibuat</th><th style="width:1%"></th></tr></thead>
      <tbody>
        <?php foreach ($users as $u):
          $kata = preg_split('/\s+/', trim((string) $u['nama']));
          $inisial = mb_strtoupper(mb_substr($kata[0] ?? '', 0, 1) . (isset($kata[1]) ? mb_substr($kata[1], 0, 1) : ''));
          $warna = $warnaAvatar[crc32((string) $u['nama']) % count($warnaAvatar)];
          $aktif = $u['status'] === 'aktif';
          $diriSendiri = (int) $u['id'] === (int) $saya['id'];
        ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-3">
              <span class="avatar-sm" style="background:<?= $warna ?>"><?= e($inisial) ?></span>
              <span class="nama"><?= e($u['nama']) ?><?= $diriSendiri ? ' <span class="text-muted small">(Anda)</span>' : '' ?></span>
            </div>
          </td>
          <td>@<?= e($u['username']) ?></td>
          <td><span class="pill"><?= e(DAFTAR_ROLE[$u['role']] ?? ucfirst((string) $u['role'])) ?></span></td>
          <td><span class="st <?= $aktif ? 'st-aktif' : 'st-lain' ?>"><?= $aktif ? 'Aktif' : 'Nonaktif' ?></span></td>
          <td><?= e(date('d/m/Y', strtotime($u['created_at']))) ?></td>
          <td class="text-end">
            <div class="dropdown">
              <button class="btn-dots" type="button" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Aksi"><i class="bi bi-three-dots"></i></button>
              <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><a class="dropdown-item" href="<?= e(modul_url('pengguna', ['modal' => 1, 'id' => (int) $u['id']])) ?>" data-popup="<?= e(modul_url('pengguna', ['modal' => 1, 'id' => (int) $u['id']])) ?>" data-popup-title="Edit Pengguna"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                <?php if (!$diriSendiri): ?>
                  <li><hr class="dropdown-divider"></li>
                  <li>
                    <form method="post" class="m-0" onsubmit="return confirm(<?= e(json_encode('Hapus pengguna ' . $u['nama'] . '?', JSON_UNESCAPED_UNICODE)) ?>)">
                      <?= csrf_field() ?>
                      <input type="hidden" name="aksi" value="hapus">
                      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                      <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Hapus</button>
                    </form>
                  </li>
                <?php endif; ?>
              </ul>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
