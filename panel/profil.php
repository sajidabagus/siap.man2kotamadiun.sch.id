<?php
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$admin = current_admin();

$stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute([':id' => $admin['id']]);
$user = $stmt->fetch();

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $nama = clean($_POST['nama'] ?? '');
    $username = clean($_POST['username'] ?? '');
    $passwordSaatIni = $_POST['password_saat_ini'] ?? '';
    $passwordBaru = $_POST['password_baru'] ?? '';
    $passwordKonfirmasi = $_POST['password_konfirmasi'] ?? '';

    // --- Validasi dasar ---
    if ($nama === '') $errors[] = 'Nama wajib diisi.';
    if ($username === '') $errors[] = 'Username wajib diisi.';
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {
        $errors[] = 'Username hanya boleh berisi huruf, angka, titik, dan underscore (tanpa spasi).';
    }

    // Username tidak boleh dipakai admin lain
    if (!$errors) {
        $cek = $db->prepare('SELECT id FROM users WHERE username = :u AND id != :id');
        $cek->execute([':u' => $username, ':id' => $admin['id']]);
        if ($cek->fetch()) $errors[] = 'Username sudah digunakan akun lain.';
    }

    // --- Konfirmasi password saat ini WAJIB benar untuk perubahan apa pun ---
    if (!$errors) {
        if ($passwordSaatIni === '') {
            $errors[] = 'Masukkan password Anda saat ini untuk konfirmasi perubahan.';
        } elseif (!password_verify($passwordSaatIni, $user['password'])) {
            $errors[] = 'Password saat ini salah.';
        }
    }

    // --- Password baru bersifat opsional: kalau diisi, wajib valid & cocok ---
    $gantiPassword = ($passwordBaru !== '' || $passwordKonfirmasi !== '');
    if (!$errors && $gantiPassword) {
        if (strlen($passwordBaru) < 6) {
            $errors[] = 'Password baru minimal 6 karakter.';
        } elseif ($passwordBaru !== $passwordKonfirmasi) {
            $errors[] = 'Konfirmasi password baru tidak cocok.';
        }
    }

    if (!$errors) {
        if ($gantiPassword) {
            $stmt = $db->prepare(
                'UPDATE users SET nama = :nama, username = :username, password = :password, updated_at = NOW() WHERE id = :id'
            );
            $stmt->execute([
                ':nama' => $nama,
                ':username' => $username,
                ':password' => password_hash($passwordBaru, PASSWORD_DEFAULT),
                ':id' => $admin['id'],
            ]);
        } else {
            $stmt = $db->prepare('UPDATE users SET nama = :nama, username = :username, updated_at = NOW() WHERE id = :id');
            $stmt->execute([':nama' => $nama, ':username' => $username, ':id' => $admin['id']]);
        }

        catat_aktivitas('UBAH', 'profil', 'Memperbarui profil akun ' . $username . ($gantiPassword ? ' dan mengganti password' : ''));
        set_flash('success', 'Profil berhasil diperbarui.' . ($gantiPassword ? ' Password baru sudah aktif.' : ''));
        header('Location: ' . modul_url('profil'));
        exit;
    }

    // Supaya form tetap menampilkan input terakhir jika gagal validasi
    $user['nama'] = $nama;
    $user['username'] = $username;
}
?>
<style>
  .pf-card { background: #fff; border: 1px solid var(--siap-line); border-radius: var(--siap-radius-card); box-shadow: var(--siap-shadow-card); overflow: hidden; }
  .pf-hero { text-align: center; padding: 32px 24px 24px; }
  .pf-avatar { width: 120px; height: 120px; border-radius: 50%; margin: 0 auto 18px; display: flex; align-items: center; justify-content: center;
               font-size: 3rem; font-weight: 700; color: #fff; background: linear-gradient(135deg, var(--siap-blue-start), var(--siap-blue-end)); box-shadow: 0 10px 24px -10px rgba(37, 99, 235, 0.55); }
  .pf-nama { font-size: 1.25rem; font-weight: 700; color: var(--siap-ink); line-height: 1.25; word-break: break-word; }
  .pf-sub { color: var(--siap-muted); margin-top: 2px; text-transform: capitalize; }
  .pf-chip { display: inline-flex; align-items: center; gap: 6px; margin-top: 14px; padding: 4px 12px; border-radius: 999px; font-size: .82rem; font-weight: 600; background: var(--siap-success-bg); color: var(--siap-success-text); border: 1px solid var(--siap-success-border); }
  .pf-chip::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
  .pf-chip.mati { background: var(--siap-bg); color: var(--siap-muted); }
  .pf-info { border-top: 1px solid var(--siap-line); padding: 18px 24px; }
  .pf-info .row-info { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; font-size: .9rem; }
  .pf-info .row-info span:first-child { color: var(--siap-muted); }
  .pf-info .row-info span:last-child { color: var(--siap-ink); font-weight: 500; text-align: right; }
  .pf-head { padding: 18px 24px; border-bottom: 1px solid var(--siap-line); }
  .pf-head h6 { margin: 0; font-weight: 700; color: var(--siap-ink); }
  .pf-head .sub { color: var(--siap-muted); font-size: .86rem; }
  .pf-body { padding: 22px 24px; }
  .pf-body .form-label { font-weight: 500; color: var(--siap-ink); font-size: .9rem; }
  .pf-sect { margin: 26px 0 14px; padding-top: 22px; border-top: 1px solid var(--siap-line); }
  .pf-sect h6 { margin: 0; font-weight: 700; color: var(--siap-ink); }
  .pf-sect .sub { color: var(--siap-muted); font-size: .86rem; }
  .pf-foot { display: flex; justify-content: flex-end; gap: 10px; padding-top: 20px; margin-top: 22px; border-top: 1px solid var(--siap-line); }
  .pf-foot .btn { border-radius: 8px; font-weight: 600; }
  @media (max-width: 575px) { .pf-body, .pf-head { padding-left: 16px; padding-right: 16px; } .pf-foot .btn { flex: 1; } }
</style>
<?php
$inisialProfil = mb_strtoupper(mb_substr(trim((string) $user['nama']) !== '' ? trim((string) $user['nama']) : (string) $user['username'], 0, 1));
$tglIndo = function ($v) { return $v ? date('d/m/Y H:i', strtotime($v)) : '-'; };
$aktifAkun = ($user['status'] ?? '') === 'aktif';
?>
<h4 class="mb-3"><i class="bi bi-person-gear"></i> Profil Saya</h4>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="pf-card h-100">
            <div class="pf-hero">
                <div class="pf-avatar"><?= e($inisialProfil) ?></div>
                <div class="pf-nama"><?= e($user['nama']) ?></div>
                <div class="pf-sub"><?= e($user['role']) ?> &middot; @<?= e($user['username']) ?></div>
                <span class="pf-chip <?= $aktifAkun ? '' : 'mati' ?>"><?= $aktifAkun ? 'Akun aktif' : e(ucfirst((string) $user['status'])) ?></span>
            </div>
            <div class="pf-info">
                <div class="row-info"><span>Role</span><span style="text-transform:capitalize"><?= e($user['role']) ?></span></div>
                <div class="row-info"><span>Akun dibuat</span><span><?= e($tglIndo($user['created_at'])) ?></span></div>
                <div class="row-info"><span>Terakhir diperbarui</span><span><?= e($tglIndo($user['updated_at'])) ?></span></div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="pf-card">
            <div class="pf-head">
                <h6>Informasi akun</h6>
                <div class="sub">Perbarui nama, username, dan password Anda.</div>
            </div>
            <div class="pf-body">
                <form method="post" autocomplete="off" data-dirty-guard>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nama Lengkap</label>
                            <input type="text" name="nama" class="form-control" value="<?= e($user['nama']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" value="<?= e($user['username']) ?>" required>
                        </div>
                    </div>

                    <div class="pf-sect">
                        <h6>Password</h6>
                        <div class="sub">Kosongkan password baru bila hanya ingin mengubah nama atau username.</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Password Saat Ini <span class="text-danger">*</span></label>
                            <input type="password" name="password_saat_ini" class="form-control" required>
                            <div class="form-text">Wajib diisi untuk mengonfirmasi setiap perubahan.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password Baru</label>
                            <input type="password" name="password_baru" class="form-control" placeholder="Minimal 6 karakter">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Konfirmasi Password Baru</label>
                            <input type="password" name="password_konfirmasi" class="form-control">
                        </div>
                    </div>

                    <div class="pf-foot">
                        <a href="<?= modul_url('profil') ?>" class="siap-btn siap-btn-ghost siap-mono-xs">Batal</a>
                        <button type="submit" class="siap-btn siap-btn-blue siap-mono-xs"><i class="bi bi-save"></i> Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>