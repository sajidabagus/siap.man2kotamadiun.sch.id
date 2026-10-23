<?php
// Manajemen pengguna: tab Petugas (admin & operator) dan tab Siswa (akun portal yang sudah diaktifkan; ganti/reset password
// khusus Administrator). Form tambah/edit dimuat sebagai popup (?modal=1).
if (($_POST['aksi'] ?? '') === 'siswa_reset_pw') {      // halaman ini menampilkan password sementara: jangan di-cache
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
}
$modal = !empty($_REQUEST['modal']);
if ($modal) {
  require_once __DIR__ . '/../includes/auth.php';
  require_login();
} else {
  require_once __DIR__ . '/../includes/header.php';
}
siapkan_hak_akses();

require_once __DIR__ . '/../includes/portal.php';
$db = getDB();
$saya = current_admin();
$errors = [];
$tab = (($_GET['tab'] ?? '') === 'siswa') ? 'siswa' : 'petugas';
$adminId = (int) ($saya['id'] ?? 0);

/** Penanda sekali pakai: reset password tidak berjalan dua kali bila halaman hasil di-refresh (F5). */
function pengguna_nonce(): string
{
    if (empty($_SESSION['pengguna_nonce'])) $_SESSION['pengguna_nonce'] = bin2hex(random_bytes(12));
    return $_SESSION['pengguna_nonce'];
}
function pengguna_nonce_pakai(string $n): bool
{
    $ok = !empty($_SESSION['pengguna_nonce']) && hash_equals((string) $_SESSION['pengguna_nonce'], $n);
    unset($_SESSION['pengguna_nonce']);
    return $ok;
}
function pengguna_label_siswa(array $a): string
{
    return trim(($a['nama'] ?? '') . ' (NISN ' . ($a['nisn'] ?? '-') . ')');
}
$slipReset = null;   // diisi setelah reset password siswa berhasil

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

// ---------- RESET PASSWORD SISWA (khusus Administrator) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'siswa_reset_pw' && !$modal) {
  csrf_require();
  $kembaliSiswa = modul_url('pengguna', ['tab' => 'siswa']);
  if (!can('pengguna.kelola')) {
    set_flash('danger', 'Hanya Administrator yang dapat mereset password siswa.');
    header('Location: ' . $kembaliSiswa); exit;
  }
  if (!pengguna_nonce_pakai((string) ($_POST['nonce'] ?? ''))) {
    set_flash('warning', 'Permintaan ini sudah diproses atau halaman sudah kedaluwarsa. Muat ulang halaman bila perlu membuat password sementara lagi.');
    header('Location: ' . $kembaliSiswa); exit;
  }
  $h = portal_admin_reset_password((int) ($_POST['akun_id'] ?? 0), $adminId);
  if (!$h['ok']) {
    set_flash('danger', $h['pesan'] ?? 'Reset password gagal.');
    header('Location: ' . $kembaliSiswa); exit;
  }
  catat_aktivitas('RESET', 'pengguna', 'Mereset password akun siswa ' . pengguna_label_siswa($h['akun']));
  $slipReset = $h;
}

// ---------- GANTI PASSWORD SISWA (popup, khusus Administrator) ----------
if ($modal && (int) ($_REQUEST['akun'] ?? 0) > 0) {
  if (!can('pengguna.kelola')) { echo '<div class="alert alert-danger mb-0">Hanya Administrator yang dapat mengganti password siswa.</div>'; exit; }
  portal_siapkan_tabel();
  $akunId = (int) $_REQUEST['akun'];
  $akunS = portal_cari_akun_id($akunId);
  if (!$akunS || empty($akunS['password_hash']) || !in_array($akunS['status'], ['aktif', 'nonaktif'], true)) {
    echo '<div class="alert alert-danger mb-0">Akun siswa belum diaktifkan atau tidak ditemukan.</div>'; exit;
  }
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'siswa_ganti_pw') {
    csrf_require();
    $pw = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password2'] ?? '');
    $wajib = !empty($_POST['wajib_ganti']);
    if ($pw !== $pw2) {
      $errors[] = 'Konfirmasi password belum sama.';
    } else {
      $h = portal_admin_set_password($akunId, $pw, $wajib, $adminId, 'ganti');
      if (!$h['ok']) {
        $errors[] = $h['pesan'];
      } else {
        catat_aktivitas('UBAH', 'pengguna', 'Mengganti password akun siswa ' . pengguna_label_siswa($akunS) . ($wajib && portal_kolom_sementara() ? ' (wajib diganti siswa)' : ''));
        set_flash('success', 'Password ' . $akunS['nama'] . ' berhasil diganti. Semua perangkat yang sedang masuk dengan akun itu otomatis keluar.');
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
      }
    }
  }
  ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
  <?php endif; ?>
  <form method="post" autocomplete="off" action="<?= e(modul_url('pengguna', ['modal' => 1, 'akun' => $akunId])) ?>" data-popup-form>
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="siswa_ganti_pw">
    <div class="mb-3 small text-muted">Siswa: <strong class="text-body"><?= e($akunS['nama']) ?></strong> · NISN <?= e($akunS['nisn']) ?> · <?= e($akunS['kelas']) ?></div>
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label">Password baru <span class="text-danger">*</span></label>
        <input type="password" name="password" class="form-control" autocomplete="new-password" placeholder="Minimal <?= (int) PORTAL_PASSWORD_MIN ?> karakter, huruf dan angka" required>
      </div>
      <div class="col-12">
        <label class="form-label">Ulangi password baru <span class="text-danger">*</span></label>
        <input type="password" name="password2" class="form-control" autocomplete="new-password" required>
      </div>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="wajib_ganti" value="1" id="wajibGanti" <?= portal_kolom_sementara() ? 'checked' : 'disabled' ?>>
          <label class="form-check-label" for="wajibGanti">Siswa wajib membuat password baru saat masuk berikutnya</label>
        </div>
        <div class="form-text">Disarankan: password ini diketahui petugas. Semua perangkat yang sedang masuk dengan akun siswa ini akan otomatis keluar.<?= portal_kolom_sementara() ? '' : ' (Fitur wajib-ganti belum tersedia di database ini.)' ?></div>
      </div>
    </div>
    <div class="mt-4 d-flex justify-content-end gap-2">
      <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-primary"><i class="bi bi-key"></i> Simpan Password</button>
    </div>
  </form>
  <?php
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
      <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> <?= $user ? 'Simpan Perubahan' : 'Simpan' ?></button>
    </div>
  </form>
  <?php
  exit;
}

// ---------- DAFTAR ----------
$users = $db->query('SELECT id, nama, username, role, status, created_at FROM users ORDER BY role, nama')->fetchAll();
$jumlahPetugas = count($users);
portal_siapkan_tabel();
$jumlahSiswa = (int) $db->query("SELECT COUNT(*) FROM siswa_akun a JOIN siswa s ON s.id = a.siswa_id
                                  WHERE a.password_hash IS NOT NULL AND a.status IN ('aktif','nonaktif') AND s.status = 'aktif'")->fetchColumn();
$warnaAvatar = ['#20b2a0', '#4a9be8', '#a855c8', '#f59f0b', '#e5484d', '#2fb344', '#1d6fd8', '#8b5cf6'];
?>
<?php if ($slipReset): $sa = $slipReset['akun']; ?>
<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-3 mb-3">
  <h4 class="mb-0"><i class="bi bi-key"></i> Password sementara dibuat</h4>
</div>
<div class="tbl-card" style="max-width:640px">
  <div class="tbl-head"><h6>Akun siswa direset</h6><div class="sub">Tampil <strong>sekali</strong> saja. Catat atau cetak sekarang; password ini tidak disimpan dalam bentuk yang bisa dibaca.</div></div>
  <div class="p-4">
    <dl class="row mb-3">
      <dt class="col-sm-4">Siswa</dt><dd class="col-sm-8"><?= e($sa['nama']) ?></dd>
      <dt class="col-sm-4">NISN (username)</dt><dd class="col-sm-8"><?= e($sa['nisn']) ?></dd>
      <dt class="col-sm-4">Kelas</dt><dd class="col-sm-8"><?= e($sa['kelas']) ?></dd>
    </dl>
    <div class="text-center my-3">
      <div class="small text-muted mb-1">Password sementara</div>
      <code id="pwSementara" style="font-size:1.9rem;letter-spacing:.12em;user-select:all"><?= e($slipReset['password']) ?></code>
    </div>
    <div class="d-flex flex-wrap justify-content-center gap-2 mb-3">
      <button type="button" class="btn btn-outline-primary" id="btnSalin"><i class="bi bi-clipboard"></i> Salin</button>
      <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Cetak</button>
      <a class="btn btn-primary" href="<?= e(modul_url('pengguna', ['tab' => 'siswa'])) ?>"><i class="bi bi-arrow-left"></i> Kembali ke daftar siswa</a>
    </div>
    <ul class="small text-muted mb-0">
      <li>Siswa masuk di halaman login dengan <strong>NISN</strong> dan password sementara ini.</li>
      <li><?= !empty($slipReset['wajib_ganti']) ? 'Setelah masuk, siswa <strong>wajib membuat password baru</strong> sebelum bisa memakai portal.' : 'Anjurkan siswa segera mengganti password lewat menu Akun.' ?></li>
      <li>Semua perangkat yang sedang masuk dengan akun ini otomatis keluar. Perangkat presensi yang terdaftar tidak berubah.</li>
    </ul>
  </div>
</div>
<script>
document.getElementById('btnSalin').addEventListener('click', function () {
  var t = document.getElementById('pwSementara').textContent, b = this;
  var ok = function () { b.innerHTML = '<i class="bi bi-check2"></i> Tersalin'; };
  if (navigator.clipboard) { navigator.clipboard.writeText(t).then(ok); }
  else { var r = document.createRange(); r.selectNodeContents(document.getElementById('pwSementara')); var s = getSelection(); s.removeAllRanges(); s.addRange(r); document.execCommand('copy'); ok(); }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; return; endif; ?>

<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-3 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-badge"></i> Pengguna</h4>
  <?php if ($tab === 'petugas'): ?>
  <div class="d-flex gap-2">
    <a href="<?= modul_url('hak_akses') ?>" class="btn btn-outline-secondary flex-fill flex-md-grow-0 text-nowrap d-inline-flex align-items-center justify-content-center gap-2"><i class="bi bi-shield-lock"></i><span>Hak Akses</span></a>
    <a href="<?= e(modul_url('pengguna', ['modal' => 1])) ?>" data-popup="<?= e(modul_url('pengguna', ['modal' => 1])) ?>" data-popup-title="Tambah Pengguna" class="btn btn-primary flex-fill flex-md-grow-0 text-nowrap d-inline-flex align-items-center justify-content-center gap-2"><i class="bi bi-plus-circle"></i><span>Tambah Pengguna</span></a>
  </div>
  <?php endif; ?>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'petugas' ? 'active' : '' ?>" href="<?= e(modul_url('pengguna')) ?>"><i class="bi bi-person-badge"></i> Petugas <span class="badge bg-secondary ms-1"><?= (int) $jumlahPetugas ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'siswa' ? 'active' : '' ?>" href="<?= e(modul_url('pengguna', ['tab' => 'siswa'])) ?>"><i class="bi bi-mortarboard"></i> Siswa <span class="badge bg-secondary ms-1"><?= (int) $jumlahSiswa ?></span></a></li>
</ul>

<?php if ($tab === 'petugas'): ?>
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
<?php else: ?>
<?php
// ---------- TAB SISWA: akun portal yang sudah diaktifkan ----------
$qS = clean($_GET['q'] ?? '');
$kelasS = clean($_GET['kelas'] ?? '');
$kolomSementara = portal_kolom_sementara() ? 'a.password_sementara' : '0';
$whereS = ["a.password_hash IS NOT NULL", "a.status IN ('aktif','nonaktif')", "s.status = 'aktif'"];
$paramS = [];
if ($kelasS !== '') { $whereS[] = 's.kelas = :kelas'; $paramS[':kelas'] = $kelasS; }
if ($qS !== '') { $whereS[] = '(s.nama LIKE :q1 OR s.nis LIKE :q2 OR s.nisn LIKE :q3)'; $paramS[':q1'] = "%$qS%"; $paramS[':q2'] = "%$qS%"; $paramS[':q3'] = "%$qS%"; }
$whereSqlS = 'WHERE ' . implode(' AND ', $whereS);
$perPage = get_page_size();
$page = get_current_page();
$c = $db->prepare("SELECT COUNT(*) FROM siswa_akun a JOIN siswa s ON s.id = a.siswa_id $whereSqlS"); $c->execute($paramS);
$totalData = (int) $c->fetchColumn();
$totalPages = max(1, (int) ceil($totalData / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$stmtS = $db->prepare("SELECT a.id AS akun_id, a.status AS akun_status, a.login_terakhir, a.password_diubah, $kolomSementara AS sementara,
        s.nama, s.nis, s.nisn, s.kelas, dv.status AS dev_status, dv.os AS dev_os, dv.browser AS dev_browser
    FROM siswa_akun a JOIN siswa s ON s.id = a.siswa_id
    LEFT JOIN device_bindings dv ON dv.siswa_id = s.id
    $whereSqlS ORDER BY s.kelas, s.nama LIMIT $perPage OFFSET $offset");
$stmtS->execute($paramS);
$daftarS = $stmtS->fetchAll();
$kelasListS = $db->query("SELECT DISTINCT s.kelas FROM siswa_akun a JOIN siswa s ON s.id = a.siswa_id
                          WHERE a.password_hash IS NOT NULL AND a.status IN ('aktif','nonaktif') AND s.status = 'aktif' ORDER BY s.kelas")->fetchAll(PDO::FETCH_COLUMN);
$extraParams = ['tab' => 'siswa', 'q' => $qS, 'kelas' => $kelasS, 'limit' => $perPage];
$dari = $totalData ? $offset + 1 : 0;
$sampai = $offset + count($daftarS);
$nonce = pengguna_nonce();
?>
<div class="tbl-card">
  <div class="tbl-head">
    <h6>Akun siswa yang sudah aktivasi</h6>
    <div class="sub">Siswa yang sudah mengaktifkan akun portal (NISN adalah username). Administrator dapat mengganti atau mereset password. Kode aktivasi, blokir, dan perangkat dikelola di menu <a href="<?= e(modul_url('akun_siswa')) ?>">Akun Siswa</a>.</div>
  </div>
  <form method="get" class="d-flex flex-wrap gap-2 p-3 pb-0" action="<?= e(modul_url('pengguna')) ?>">
    <input type="hidden" name="tab" value="siswa">
    <input type="search" name="q" value="<?= e($qS) ?>" class="form-control" style="max-width:260px" placeholder="Cari nama / NISN / NIS">
    <select name="kelas" class="form-select" style="max-width:180px">
      <option value="">Semua kelas</option>
      <?php foreach ($kelasListS as $k): ?><option value="<?= e($k) ?>" <?= $kelasS === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?>
    </select>
    <?= render_per_page_field($perPage, false) ?>
    <button class="btn btn-outline-primary"><i class="bi bi-search"></i> Cari</button>
    <?php if ($qS !== '' || $kelasS !== ''): ?><a class="btn btn-light border" href="<?= e(modul_url('pengguna', ['tab' => 'siswa'])) ?>">Reset</a><?php endif; ?>
  </form>
  <div class="px-3 pt-2 small text-muted"><?= $totalData ? "Menampilkan {$dari}–{$sampai} dari {$totalData} akun" : 'Belum ada akun yang sesuai' ?></div>
  <div class="table-responsive">
    <table class="table tbl align-middle">
      <thead><tr><th>Siswa</th><th>NISN (username)</th><th>Role</th><th>Status</th><th>Perangkat presensi</th><th>Terakhir masuk</th><th>Password diubah</th><th style="width:1%"></th></tr></thead>
      <tbody>
        <?php if (!$daftarS): ?><tr><td colspan="8" class="text-center text-muted py-4">Belum ada akun siswa yang sudah aktivasi.</td></tr><?php endif; ?>
        <?php foreach ($daftarS as $r):
          $kata = preg_split('/\s+/', trim((string) $r['nama']));
          $inisial = mb_strtoupper(mb_substr($kata[0] ?? '', 0, 1) . (isset($kata[1]) ? mb_substr($kata[1], 0, 1) : ''));
          $warna = $warnaAvatar[crc32((string) $r['nama']) % count($warnaAvatar)];
          $diblokir = $r['akun_status'] === 'nonaktif';
        ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-3">
              <span class="avatar-sm" style="background:<?= $warna ?>"><?= e($inisial) ?></span>
              <span class="nama"><?= e($r['nama']) ?><br><span class="text-muted small"><?= e($r['kelas']) ?></span></span>
            </div>
          </td>
          <td><?= e($r['nisn']) ?></td>
          <td><span class="pill">Siswa</span></td>
          <td>
            <span class="st <?= $diblokir ? 'st-lain' : 'st-aktif' ?>"><?= $diblokir ? 'Diblokir' : 'Aktif' ?></span>
            <?php if (!empty($r['sementara'])): ?><div class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> Password sementara (wajib diganti)</div><?php endif; ?>
          </td>
          <td class="small">
            <?php if ($r['dev_status'] === 'ACTIVE'): ?><span class="text-success"><i class="bi bi-phone"></i> Terdaftar</span><br><span class="text-muted"><?= e(trim(($r['dev_os'] ?? '') . ' ' . ($r['dev_browser'] ?? ''))) ?></span>
            <?php elseif ($r['dev_status'] === 'BLOCKED'): ?><span class="text-danger"><i class="bi bi-slash-circle"></i> Diblokir</span>
            <?php else: ?><span class="text-muted">Belum ditautkan</span><?php endif; ?>
          </td>
          <td class="small"><?= $r['login_terakhir'] ? e(date('d/m/Y H:i', strtotime($r['login_terakhir']))) : '<span class="text-muted">Belum pernah</span>' ?></td>
          <td class="small"><?= $r['password_diubah'] ? e(date('d/m/Y H:i', strtotime($r['password_diubah']))) : '-' ?></td>
          <td class="text-end">
            <div class="dropdown">
              <button class="btn-dots" type="button" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Aksi"><i class="bi bi-three-dots"></i></button>
              <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><a class="dropdown-item" href="<?= e(modul_url('pengguna', ['modal' => 1, 'akun' => (int) $r['akun_id']])) ?>" data-popup="<?= e(modul_url('pengguna', ['modal' => 1, 'akun' => (int) $r['akun_id']])) ?>" data-popup-title="Ganti Password Siswa"><i class="bi bi-key me-2"></i>Ganti password</a></li>
                <li>
                  <form method="post" class="m-0" action="<?= e(modul_url('pengguna', ['tab' => 'siswa'])) ?>" onsubmit="return confirm(<?= e(json_encode('Reset password ' . $r['nama'] . "?\n\nDibuat password sementara acak yang wajib diganti siswa. Semua perangkat yang sedang masuk dengan akun ini otomatis keluar.", JSON_UNESCAPED_UNICODE)) ?>)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="siswa_reset_pw">
                    <input type="hidden" name="akun_id" value="<?= (int) $r['akun_id'] ?>">
                    <input type="hidden" name="nonce" value="<?= e($nonce) ?>">
                    <button type="submit" class="dropdown-item"><i class="bi bi-arrow-repeat me-2"></i>Reset password</button>
                  </form>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= e(modul_url('akun_siswa', ['q' => $r['nisn']])) ?>"><i class="bi bi-person-lock me-2"></i>Kelola akun &amp; perangkat</a></li>
              </ul>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?><div class="p-3"><?= render_pagination($page, $totalPages, $extraParams) ?></div><?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>