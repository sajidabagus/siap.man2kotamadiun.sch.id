<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Sesi tidak valid, silakan coba lagi.';
    } else {
        $username = clean($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Username dan password wajib diisi.';
        } else {
            $sisaKunci = login_kunci_sisa($username);
            if ($sisaKunci > 0) {
                // Terlalu banyak percobaan gagal: jangan periksa password sama sekali.
                security_log(null, 'ADMIN_LOGIN_LOCKED', null, 'Login diblokir sementara untuk username: ' . substr($username, 0, 50));
                $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . (int) ceil($sisaKunci / 60) . ' menit.';
                usleep(300000);
            } else {
                $stmt = getDB()->prepare('SELECT * FROM users WHERE username = :u AND status = "aktif" LIMIT 1');
                $stmt->execute([':u' => $username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = $user['id'];
                    $_SESSION['last_regen'] = time();
                    login_bersihkan($username);
                    catat_aktivitas('LOGIN', 'login', 'Login berhasil: ' . $user['username']);
                    if ($password === 'admin123') {
                        set_flash('warning', 'Password Anda masih password bawaan (admin123). Segera ganti di menu Profil Akun.');
                    }
                    header('Location: index.php');
                    exit;
                } else {
                    login_catat_gagal($username);
                    security_log(null, 'ADMIN_LOGIN_FAILED', null, 'Login admin gagal untuk username: ' . substr($username, 0, 50));
                    $error = 'Username atau password salah.';
                    usleep(300000); // mitigasi brute force sederhana
                }
            }
        }
    }
}

$settings = get_settings();
$namaSekolah = $settings['nama_sekolah'] ?? 'Presensi Siswa';
$logo = $settings['logo'] ?? '';
$logoUrl = ($logo !== '' && is_file(__DIR__ . '/uploads/siswa/' . $logo)) ? BASE_URL . '/uploads/siswa/' . rawurlencode($logo) : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=overlays-content">
<meta name="theme-color" content="#020617">
<title>Masuk — <?= e($namaSekolah) ?></title>
<?= favicon_tag() ?>
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap.min.css?v=<?= @filemtime(__DIR__ . '/assets/css/vendor/bootstrap.min.css') ?: time() ?>" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap-icons.min.css?v=<?= @filemtime(__DIR__ . '/assets/css/vendor/bootstrap-icons.min.css') ?: time() ?>" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?: time() ?>" rel="stylesheet">
<style>
  /* Override halaman login: latar halaman pakai --siap-bg, form terpusat */
  body.login-page {
    padding: 1.5rem 1rem;
    overscroll-behavior: none;
  }
  .login-shell {
    max-width: var(--siap-shell-narrow-max, 42rem);
    margin: 0 auto;
  }
  .login-card {
    background: #fff;
    border-radius: var(--siap-radius-window);
    border: 1px solid var(--siap-line);
    box-shadow: var(--siap-shadow-card);
    overflow: hidden;
  }
  .login-brand {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 1.5rem 1rem 1rem;
    background: var(--siap-panel-blue-bg, #eff6ff);
  }
  .login-brand .siap-tile {
    width: 3rem;
    height: 3rem;
    border-radius: var(--siap-radius-card);
    background: #fff;
    border: 1px solid var(--siap-line);
    display: grid;
    place-items: center;
    color: var(--siap-blue);
    font-size: 1.75rem;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
  }
  .login-brand-text .siap-h1 { margin: 0; font-size: 1.5rem; letter-spacing: -0.03em; }
  .login-brand-text .siap-copy-sm { margin: 0; }

  .login-body { padding: 1.5rem 1rem 1rem; }
  .login-eyebrow { text-align: center; margin-bottom: 0.25rem; }
  .login-title { text-align: center; margin: 0 0 0.5rem; letter-spacing: -0.03em; }
  .login-subtitle { text-align: center; margin: 0 0 1.25rem; }

  .login-field { margin-bottom: 1rem; position: relative; }
  .login-field .siap-input { height: auto; min-height: var(--siap-tap-target); padding: 0.625rem 1rem; }
  .login-field.pw .siap-input { padding-right: 2.75rem; }
  .login-eye {
    position: absolute; right: 0.5rem; top: 50%; transform: translateY(-50%);
    width: 2.25rem; height: 2.25rem; border: 0; border-radius: var(--siap-radius-pill);
    background: transparent; color: var(--siap-muted); font-size: 0.95rem; cursor: pointer;
    display: grid; place-items: center;
  }
  .login-eye:hover { color: var(--siap-ink); background: rgba(59, 130, 246, 0.06); border-color: var(--siap-line); }
  .login-eye:focus-visible { outline: 2px solid var(--siap-blue); outline-offset: 1px; }

  .login-actions { display: flex; flex-direction: column; gap: 0.75rem; margin-top: 0.25rem; }
  .login-btn-masuk {
    width: 100%;
    min-height: var(--siap-tap-target);
    font-weight: 600;
  }

  .login-footer { margin-top: 1.5rem; text-align: center; }
  .login-footer .siap-copy-sm { margin: 0.25rem 0 0; }

  @media (max-width: 480px) { .siap-input { font-size: 16px; } } /* cegah auto-zoom iOS saat fokus */
  @media (prefers-reduced-motion: reduce) { .login-card, .siap-btn-blue { transition: none !important; } }
</style>
</head>
<body class="siap-page login-page">
  <div class="siap-glow-ios17"></div>

  <div class="siap-shell-narrow login-shell">
    <div class="siap-card-ios17 login-card">
      <div class="login-brand">
        <div class="siap-tile"><i class="bi bi-qr-code-scan" aria-hidden="true"></i></div>
        <div class="login-brand-text">
          <div class="siap-h1"><?= e($namaSekolah) ?></div>
          <div class="siap-copy-sm">Sistem Presensi Siswa</div>
        </div>
      </div>

      <div class="login-body">
        <div class="login-eyebrow"><span class="siap-eyebrow">Masuk ke akun Anda</span></div>
        <h1 class="siap-h1 login-title">Selamat Datang</h1>
        <p class="siap-copy-sm login-subtitle">Masuk ke akun Absensi Anda</p>

        <form method="post" autocomplete="off" class="siap-stack">
          <?= csrf_field() ?>
          <?php if ($error): ?>
            <div class="siap-note siap-note-red" role="alert">
              <i class="bi bi-exclamation-circle-fill"></i>
              <span><?= e($error) ?></span>
            </div>
          <?php endif; ?>

          <div class="login-field">
            <label for="username" class="siap-label">Username</label>
            <input type="text" name="username" id="username" class="siap-input" placeholder="Masukkan username" aria-label="Username" required autocapitalize="none" spellcheck="false">
          </div>

          <div class="login-field pw">
            <label for="password" class="siap-label">Password</label>
            <input type="password" name="password" id="password" class="siap-input" placeholder="Masukkan password" aria-label="Password" required>
            <button type="button" class="login-eye" id="togglePw" aria-label="Tampilkan password"><i class="bi bi-eye-slash-fill"></i></button>
          </div>

          <div class="login-actions">
            <button type="submit" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs" id="btnMasuk">Masuk</button>
          </div>
        </form>

        <div class="login-footer">
          <p class="siap-copy-sm">
            <a href="index.php" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" style="padding: 0; background: none; border: none;">← Kembali ke beranda</a>
          </p>
          <p class="siap-copy-sm"><?= e($namaSekolah) ?> · SIAP ABSEN &copy; <?= date('Y') ?></p>
        </div>
      </div>
    </div>
  </div>

<script>
  (function () {
    var btnKembali = document.querySelector('.login-footer a[href="index.php"]');
    if (btnKembali) {
      btnKembali.addEventListener('click', function (e) {
        try {
          var ref = document.referrer ? new URL(document.referrer) : null;
          if (ref && ref.origin === location.origin && ref.pathname !== location.pathname && history.length > 1) {
            e.preventDefault();
            history.back();
          }
        } catch (err) { /* biarkan tautan ke beranda berjalan */ }
      });
    }

    // Fokus otomatis ke username hanya di perangkat dengan mouse (desktop); di HP keyboard tidak boleh muncul sendiri.
    if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
      var el = document.getElementById('username');
      if (el) el.focus();
    }

    var pw = document.getElementById('password'), tg = document.getElementById('togglePw');
    if (pw && tg) {
      tg.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        tg.innerHTML = '<i class="bi bi-eye' + (show ? '-fill' : '-slash-fill') + '"></i>';
        tg.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
        pw.focus();
      });
    }

    document.querySelector('form').addEventListener('submit', function () {
      var b = document.getElementById('btnMasuk');
      if (b) {
        setTimeout(function () { b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm"></span>Memproses...'; }, 0);
      }
    });
  })();
</script>
</body>
</html>