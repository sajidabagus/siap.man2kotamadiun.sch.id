<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/portal.php';

if (is_logged_in()) {
  header('Location: ' . BASE_URL . '/');
  exit;
}

$error = '';
$info = '';
if (($_GET['aktif'] ?? '') === '1') {
  $p = (string) ($_GET['p'] ?? '');
  $info = 'Akun berhasil diaktifkan. ' . (PESAN_PERANGKAT[$p] ?? '') . ' Silakan masuk dengan NISN dan password baru Anda.';
  $info = trim((string) preg_replace('/\s+/', ' ', $info));
} elseif (($_GET['keluar'] ?? '') === '1') $info = 'Anda sudah keluar.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    $error = 'Sesi tidak valid, silakan coba lagi.';
  } else {
    $username = clean($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
      $error = 'Username dan password wajib diisi.';
    } else {
      // SATU halaman masuk untuk semua. Bila ada PETUGAS (admin/operator) dengan username ini,
      // alurnya login petugas. Bila tidak ada, input dianggap NISN siswa dan masuk ke Portal
      // Siswa (sesi TERPISAH). Pesan galat dan jeda waktu kedua jalur sengaja dibuat sama,
      // supaya tidak ada yang bisa menebak username petugas dari perbedaan respons.
      $cekPetugas = getDB()->prepare('SELECT 1 FROM users WHERE username = :u LIMIT 1');
      $cekPetugas->execute([':u' => $username]);

      if ($cekPetugas->fetchColumn()) {
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
            header('Location: ' . modul_url('dashboard'));
            exit;
          } else {
            login_catat_gagal($username);
            security_log(null, 'ADMIN_LOGIN_FAILED', null, 'Login admin gagal untuk username: ' . substr($username, 0, 50));
            $error = 'Username atau password salah.';
            usleep(300000); // mitigasi brute force sederhana
          }
        }
      } else {
        $nisn = portal_nisn($username);
        if ($nisn !== '' && portal_aktif() && portal_siapkan_tabel()) {
          $hasilSiswa = portal_coba_login($nisn, $password);
        } else {
          password_verify($password, PORTAL_DUMMY_HASH);   // samakan waktu respons
          $hasilSiswa = ['ok' => false];
        }

        if (!empty($hasilSiswa['ok'])) {
          session_write_close();                 // tutup sesi petugas; portal memakai sesi sendiri
          portal_mulai_sesi();
          portal_masuk_sesi($hasilSiswa['akun']);
          header('Location: ' . portal_url());
          exit;
        }
        $error = !empty($hasilSiswa['terkunci'])
          ? 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . (int) ceil($hasilSiswa['sisa'] / 60) . ' menit.'
          : 'Username atau password salah.';
        usleep(300000);
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
  <meta name="theme-color" content="#0b2f73">
  <title>Masuk — <?= e($namaSekolah) ?></title>
  <?= favicon_tag() ?>
  <link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap.min.css" rel="stylesheet">
  <link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">
  <!-- Poppins hanya dipakai tampilan desktop; di HP tidak memblokir render -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet" media="(min-width: 900px)">
  <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
  <style>
    :root {
      --hj-tua: #111111;
      /* tombol & judul */
      --hj-judul: #111111;
      --hj-teks: #6b7280;
      /* teks isian & tautan */
      --hj-redup: #6b7280;
      /* keterangan */
      --hj-isian: #eceef1;
      /* latar kolom isian */

      /* Biru hanya untuk tombol Masuk; elemen lain hitam/abu-abu netral */
      --btn-biru: #123f9e;
      --btn-biru-hover: #0d3380;
      --fokus-biru: #2f6fd6;
      /* garis tepi saat kolom diklik/disorot */
      --fokus-cahaya: rgba(47, 111, 214, .24);
      /* cahaya biru di sekeliling kolom yang aktif */

      /* Pola geometris Islam (bintang segi-8 / khatam), SVG inline: gelap = garis terang di atas biru tua; terang = garis biru tipis di atas latar muda */
      --pola-gelap: url("data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='64'%20height='64'%20viewBox='0%200%2064%2064'%3E%3Cpolygon%20points='32.00,0.00%2041.37,9.37%2054.63,9.37%2054.63,22.63%2064.00,32.00%2054.63,41.37%2054.63,54.63%2041.37,54.63%2032.00,64.00%2022.63,54.63%209.37,54.63%209.37,41.37%200.00,32.00%209.37,22.63%209.37,9.37%2022.63,9.37'%20fill='%23ffffff'%20fill-opacity='0.05'%20stroke='%23ffffff'%20stroke-opacity='0.3'%20stroke-width='1.2'%20stroke-linejoin='round'/%3E%3Cpolygon%20points='39.35,14.26%2049.74,24.65%2049.74,39.35%2039.35,49.74%2024.65,49.74%2014.26,39.35%2014.26,24.65%2024.65,14.26'%20fill='none'%20stroke='%23ffffff'%20stroke-opacity='0.18'%20stroke-width='1'/%3E%3Cpolygon%20points='32.00,17.28%2036.31,21.59%2042.41,21.59%2042.41,27.69%2046.72,32.00%2042.41,36.31%2042.41,42.41%2036.31,42.41%2032.00,46.72%2027.69,42.41%2021.59,42.41%2021.59,36.31%2017.28,32.00%2021.59,27.69%2021.59,21.59%2027.69,21.59'%20fill='none'%20stroke='%23ffffff'%20stroke-opacity='0.18'%20stroke-width='1'%20stroke-linejoin='round'/%3E%3Ccircle%20cx='32.0'%20cy='32.0'%20r='2.88'%20fill='%23ffffff'%20fill-opacity='0.3'/%3E%3Cpolygon%20points='0,-10.88%2010.88,0%200,10.88%20-10.88,0'%20fill='%23ffffff'%20fill-opacity='0.05'%20stroke='%23ffffff'%20stroke-opacity='0.18'%20stroke-width='1'/%3E%3Cpolygon%20points='0,-4.8%204.8,0%200,4.8%20-4.8,0'%20fill='%23ffffff'%20fill-opacity='0.1'/%3E%3Cpolygon%20points='64,-10.88%2074.88,0%2064,10.88%2053.12,0'%20fill='%23ffffff'%20fill-opacity='0.05'%20stroke='%23ffffff'%20stroke-opacity='0.18'%20stroke-width='1'/%3E%3Cpolygon%20points='64,-4.8%2068.8,0%2064,4.8%2059.2,0'%20fill='%23ffffff'%20fill-opacity='0.1'/%3E%3Cpolygon%20points='0,53.12%2010.88,64%200,74.88%20-10.88,64'%20fill='%23ffffff'%20fill-opacity='0.05'%20stroke='%23ffffff'%20stroke-opacity='0.18'%20stroke-width='1'/%3E%3Cpolygon%20points='0,59.2%204.8,64%200,68.8%20-4.8,64'%20fill='%23ffffff'%20fill-opacity='0.1'/%3E%3Cpolygon%20points='64,53.12%2074.88,64%2064,74.88%2053.12,64'%20fill='%23ffffff'%20fill-opacity='0.05'%20stroke='%23ffffff'%20stroke-opacity='0.18'%20stroke-width='1'/%3E%3Cpolygon%20points='64,59.2%2068.8,64%2064,68.8%2059.2,64'%20fill='%23ffffff'%20fill-opacity='0.1'/%3E%3C/svg%3E");
      --pola-terang: url("data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='64'%20height='64'%20viewBox='0%200%2064%2064'%3E%3Cpolygon%20points='32.00,0.00%2041.37,9.37%2054.63,9.37%2054.63,22.63%2064.00,32.00%2054.63,41.37%2054.63,54.63%2041.37,54.63%2032.00,64.00%2022.63,54.63%209.37,54.63%209.37,41.37%200.00,32.00%209.37,22.63%209.37,9.37%2022.63,9.37'%20fill='%231c4fa8'%20fill-opacity='0.035'%20stroke='%231c4fa8'%20stroke-opacity='0.16'%20stroke-width='1.2'%20stroke-linejoin='round'/%3E%3Cpolygon%20points='39.35,14.26%2049.74,24.65%2049.74,39.35%2039.35,49.74%2024.65,49.74%2014.26,39.35%2014.26,24.65%2024.65,14.26'%20fill='none'%20stroke='%231c4fa8'%20stroke-opacity='0.1'%20stroke-width='1'/%3E%3Cpolygon%20points='32.00,17.28%2036.31,21.59%2042.41,21.59%2042.41,27.69%2046.72,32.00%2042.41,36.31%2042.41,42.41%2036.31,42.41%2032.00,46.72%2027.69,42.41%2021.59,42.41%2021.59,36.31%2017.28,32.00%2021.59,27.69%2021.59,21.59%2027.69,21.59'%20fill='none'%20stroke='%231c4fa8'%20stroke-opacity='0.1'%20stroke-width='1'%20stroke-linejoin='round'/%3E%3Ccircle%20cx='32.0'%20cy='32.0'%20r='2.88'%20fill='%231c4fa8'%20fill-opacity='0.16'/%3E%3Cpolygon%20points='0,-10.88%2010.88,0%200,10.88%20-10.88,0'%20fill='%231c4fa8'%20fill-opacity='0.035'%20stroke='%231c4fa8'%20stroke-opacity='0.1'%20stroke-width='1'/%3E%3Cpolygon%20points='0,-4.8%204.8,0%200,4.8%20-4.8,0'%20fill='%231c4fa8'%20fill-opacity='0.08'/%3E%3Cpolygon%20points='64,-10.88%2074.88,0%2064,10.88%2053.12,0'%20fill='%231c4fa8'%20fill-opacity='0.035'%20stroke='%231c4fa8'%20stroke-opacity='0.1'%20stroke-width='1'/%3E%3Cpolygon%20points='64,-4.8%2068.8,0%2064,4.8%2059.2,0'%20fill='%231c4fa8'%20fill-opacity='0.08'/%3E%3Cpolygon%20points='0,53.12%2010.88,64%200,74.88%20-10.88,64'%20fill='%231c4fa8'%20fill-opacity='0.035'%20stroke='%231c4fa8'%20stroke-opacity='0.1'%20stroke-width='1'/%3E%3Cpolygon%20points='0,59.2%204.8,64%200,68.8%20-4.8,64'%20fill='%231c4fa8'%20fill-opacity='0.08'/%3E%3Cpolygon%20points='64,53.12%2074.88,64%2064,74.88%2053.12,64'%20fill='%231c4fa8'%20fill-opacity='0.035'%20stroke='%231c4fa8'%20stroke-opacity='0.1'%20stroke-width='1'/%3E%3Cpolygon%20points='64,59.2%2068.8,64%2064,68.8%2059.2,64'%20fill='%231c4fa8'%20fill-opacity='0.08'/%3E%3C/svg%3E");
    }

    /* Halaman dikunci: tidak bisa digeser/ditarik ke samping maupun bounce, seperti halaman 404.
     Hanya akan bisa scroll vertikal bila layar terlalu pendek untuk memuat form (mis. saat keyboard terbuka). */
    html {
      height: 100%;
      overflow: hidden;
      overscroll-behavior: none;
      -webkit-text-size-adjust: 100%;
    }

    body.login-page {
      position: fixed;
      inset: 0;
      min-height: 0;
      overflow-x: hidden;
      overflow-y: auto;
      overscroll-behavior: none;
      touch-action: pan-y;
      padding: 20px 16px;
      display: flex;
      align-items: flex-start;
      justify-content: center;
      font-family: 'Montserrat', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
      background:
        radial-gradient(60% 45% at 12% 8%, rgba(96, 150, 235, .34), transparent 70%),
        radial-gradient(55% 45% at 92% 92%, rgba(60, 115, 215, .28), transparent 70%),
        var(--pola-terang) 0 0 / 64px 64px,
        #e6eefb;
    }

    .lg-card,
    .lg-card *,
    .lg-card *::before,
    .lg-card *::after {
      box-sizing: border-box;
    }

    /* ---- kartu ---- */
    .lg-card {
      margin: auto;
      width: 100%;
      max-width: 380px;
      display: flex;
      flex-direction: column;
      min-height: 600px;
      min-height: min(660px, calc(100svh - 40px));
      background: #fff;
      border-radius: 30px;
      overflow: hidden;
      box-shadow: 0 30px 60px -28px rgba(10, 45, 110, .55), 0 2px 8px rgba(10, 45, 110, .08);
    }

    /* ---- bagian atas bergambar pola geometris Islam biru ---- */
    .lg-hero {
      position: relative;
      height: 250px;
      flex: none;
      background:
        radial-gradient(60% 70% at 50% 40%, rgba(5, 22, 66, .55), transparent 75%),
        var(--pola-gelap) 0 0 / 56px 56px,
        linear-gradient(135deg, #061a45, #0b3a8f 55%, #1f6fe0);
    }


    .lg-back {
      position: absolute;
      z-index: 2;
      top: 16px;
      left: 16px;
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: #fff;
      color: var(--hj-tua);
      display: grid;
      place-items: center;
      text-decoration: none;
      font-size: 15px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, .25);
      transition: transform .15s;
      touch-action: manipulation;
      -webkit-tap-highlight-color: transparent;
      cursor: pointer;
    }

    /* Area sentuh diperluas (34px tampak -> ~50px sentuh) supaya mudah ditekan di HP */
    .lg-back::after {
      content: "";
      position: absolute;
      inset: -8px;
      border-radius: 50%;
    }

    .lg-back:hover {
      color: var(--hj-tua);
      transform: translateX(-2px);
    }

    .lg-back:active {
      transform: scale(.94);
    }

    .lg-back:focus-visible {
      outline: 3px solid #fff;
      outline-offset: 2px;
    }

    .lg-logo {
      position: absolute;
      left: 0;
      right: 0;
      top: 0;
      bottom: 76px;
      display: flex;
      align-items: center;
      justify-content: center;
      pointer-events: none;
    }

    /* Logo transparan: tanpa lingkaran/latar putih; bayangan mengikuti bentuk logo agar tetap terbaca di atas pola biru */
    .lg-logo img {
      width: 88px;
      height: 88px;
      object-fit: contain;
      background: transparent;
      border: 0;
      border-radius: 0;
      padding: 0;
      box-shadow: none;
      filter: drop-shadow(0 4px 10px rgba(0, 0, 0, .4));
    }

    .lg-logo i {
      font-size: 64px;
      line-height: 1;
      color: #fff;
      filter: drop-shadow(0 6px 14px rgba(0, 0, 0, .35));
    }

    /* ---- bagian putih dengan lengkung kiri-atas ---- */
    .lg-body {
      position: relative;
      z-index: 1;
      flex: 1;
      display: flex;
      flex-direction: column;
      margin-top: -76px;
      background: #fff;
      border-top-left-radius: 76px;
      padding: 34px 28px 20px;
    }

    .lg-judul {
      margin: 0;
      text-align: center;
      font-size: 28px;
      font-weight: 800;
      letter-spacing: -.2px;
      color: var(--hj-judul);
      line-height: 1.15;
    }

    .lg-sub {
      margin: 6px 0 0;
      text-align: center;
      font-size: 13px;
      font-weight: 600;
      color: var(--hj-redup);
      line-height: 1.4;
    }

    .lg-form {
      flex: 1;
      display: flex;
      flex-direction: column;
      margin: 28px 0 0;
    }

    .lg-err {
      display: flex;
      gap: 8px;
      align-items: flex-start;
      margin: 0 0 14px;
      padding: 10px 14px;
      border-radius: 16px;
      background: #fdecec;
      color: #b42318;
      font-size: 12.5px;
      font-weight: 600;
      line-height: 1.45;
    }

    .lg-err i {
      margin-top: 1px;
    }

    .lg-ok {
      display: flex;
      gap: 8px;
      align-items: flex-start;
      margin: 0 0 14px;
      padding: 10px 14px;
      border-radius: 16px;
      background: #e3f4ea;
      color: #1a7f4b;
      font-size: 12.5px;
      font-weight: 600;
      line-height: 1.45;
    }

    .lg-ok i {
      margin-top: 1px;
    }

    .lg-ok.netral {
      background: #eef4ff;
      color: #1d4ed8;
    }

    .lg-field {
      position: relative;
      margin-bottom: 14px;
    }

    .lg-field input {
      display: block;
      width: 100%;
      height: 48px;
      padding: 0 20px;
      border: 2px solid transparent;
      border-radius: 999px;
      outline: none;
      background: var(--hj-isian);
      color: var(--hj-judul);
      font: 600 14.5px/1 'Montserrat', system-ui, sans-serif;
      transition: border-color .18s, background .18s, box-shadow .18s;
    }

    .lg-field input::placeholder {
      color: var(--hj-teks);
      opacity: 1;
      font-weight: 600;
    }

    .lg-field input:focus {
      background: #fff;
      border-color: var(--fokus-biru);
      box-shadow: 0 0 0 4px var(--fokus-cahaya), 0 8px 22px -10px rgba(47, 111, 214, .55);
    }

    .lg-field.pw input {
      padding-right: 52px;
    }

    .lg-field input:-webkit-autofill {
      -webkit-box-shadow: 0 0 0 40px var(--hj-isian) inset;
      -webkit-text-fill-color: var(--hj-judul);
    }

    .lg-field input::-ms-reveal,
    .lg-field input::-ms-clear {
      display: none;
    }

    .lg-field input::-webkit-credentials-auto-fill-button,
    .lg-field input::-webkit-contacts-auto-fill-button {
      visibility: hidden;
      display: none !important;
      pointer-events: none;
    }

    .lg-eye {
      position: absolute;
      right: 8px;
      top: 50%;
      transform: translateY(-50%);
      width: 36px;
      height: 36px;
      border: 0;
      border-radius: 50%;
      background: transparent;
      color: var(--hj-tua);
      font-size: 17px;
      cursor: pointer;
    }

    .lg-eye:hover {
      background: rgba(17, 24, 39, .07);
    }

    .lg-eye:focus-visible {
      outline: 2px solid var(--fokus-biru);
      outline-offset: 1px;
    }

    .lg-ruang {
      flex: 1;
      min-height: 22px;
    }

    .lg-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      width: 100%;
      height: 50px;
      border: 0;
      border-radius: 999px;
      background: var(--btn-biru);
      color: #fff;
      font: 700 15px/1 'Montserrat', system-ui, sans-serif;
      cursor: pointer;
      box-shadow: 0 12px 22px -12px rgba(18, 63, 158, .9);
      transition: background .15s, transform .15s;
    }

    .lg-btn:hover {
      background: #0d3380;
    }

    .lg-btn:active {
      transform: scale(.985);
    }

    .lg-btn:focus-visible {
      outline: 3px solid rgba(47, 111, 214, .5);
      outline-offset: 2px;
    }

    .lg-btn:disabled {
      opacity: .8;
      cursor: wait;
    }

    .lg-kaki {
      margin: 14px 0 0;
      text-align: center;
      font-size: 11.5px;
      font-weight: 600;
      color: var(--hj-redup);
      line-height: 1.6;
    }

    .lg-kaki a {
      color: var(--hj-tua);
      font-weight: 700;
      text-decoration: none;
    }

    .lg-kaki a:hover {
      text-decoration: underline;
    }

    .lg-hak {
      display: block;
      margin-top: 2px;
      font-size: 10.5px;
      letter-spacing: .04em;
      opacity: .85;
    }

    @media (max-width: 480px) {
      .lg-field input {
        font-size: 16px;
      }
    }

    /* cegah auto-zoom iOS saat fokus */

    /* ---- HP: tampilan penuh layar, tanpa latar belakang/kartu mengambang ---- */
    @media (max-width: 600px) {
      body.login-page {
        padding: 0;
        background: #fff;
        align-items: flex-start;
      }

      /* Tinggi layar dikunci saat halaman dibuka (--tinggi, diisi JS) agar tata letak TIDAK bergeser/terhimpit
       ketika keyboard HP muncul. Tanpa JS, memakai tinggi layar biasa. */
      body.login-page.tinggi-tetap {
        bottom: auto;
        height: var(--tinggi);
      }

      .lg-card {
        transition: transform .18s ease;
        will-change: auto;
      }

      .lg-card {
        margin: 0;
        max-width: none;
        border-radius: 0;
        box-shadow: none;
        min-height: 100vh;
        min-height: var(--tinggi, 100vh);
      }

      .lg-hero {
        height: clamp(250px, 38vh, 340px);
        height: clamp(250px, calc(var(--tinggi, 100vh) * .38), 340px);
      }

      .lg-back {
        top: calc(16px + env(safe-area-inset-top, 0px));
        z-index: 5;
      }

      .lg-logo {
        top: env(safe-area-inset-top, 0px);
      }

      .lg-body {
        padding: 34px 28px calc(22px + env(safe-area-inset-bottom, 0px));
      }
    }

    @media (prefers-reduced-motion: reduce) {

      .lg-back,
      .lg-btn,
      .lg-field input,
      .lg-card {
        transition: none !important;
      }
    }

    /* =====================================================================
     DESKTOP (>= 900px): tata letak dua kolom (split-screen)
     Kiri  : panel bermerek (logo, judul, deskripsi) di atas pola geometris Islam biru
     Kanan : panel putih berlengkung berisi formulir masuk
     Di bawah 900px semua aturan ini tidak berlaku (tampilan HP tetap seperti semula).
     ===================================================================== */
    .lg-info {
      display: none;
    }

    @keyframes lgUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }

      to {
        opacity: 1;
        transform: none;
      }
    }

    @keyframes lgFade {
      from {
        opacity: 0;
      }

      to {
        opacity: 1;
      }
    }

    @media (min-width: 900px) {
      :root {
        --hj-tua: #111111;
        --hj-judul: #111111;
        --hj-teks: #6b7280;
        --hj-redup: #6b7280;
        --hj-isian: #eceef1;
        --hj-hover: #0d3380;
      }

      body.login-page {
        font-family: 'Poppins', 'Montserrat', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
        padding: 0;
        background: #0a2a63;
        align-items: stretch;
      }

      .lg-card {
        position: relative;
        margin: 0;
        max-width: none;
        width: 100%;
        flex-direction: row;
        min-height: 100vh;
        border-radius: 0;
        box-shadow: none;
        background: #0a2a63;
        overflow: hidden;
      }

      /* ---- panel kiri: menutup seluruh kartu; lengkung panel kanan "membuka" gambar ini ---- */
      .lg-hero {
        position: absolute;
        inset: 0;
        height: auto;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 40px max(40%, 440px) 40px 40px;
        color: #fff;
        background:
          radial-gradient(45% 55% at 38% 50%, rgba(5, 22, 66, .5), transparent 75%),
          var(--pola-gelap) 0 0 / 96px 96px,
          linear-gradient(135deg, #061a45, #0b3a8f 55%, #1f6fe0);
      }

      .lg-hero::before {
        content: '';
        position: absolute;
        z-index: 1;
        top: -10%;
        left: -10%;
        width: 300px;
        height: 300px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255, 255, 255, .12) 0%, transparent 70%);
        pointer-events: none;
      }

      .lg-hero::after {
        content: '';
        position: absolute;
        z-index: 1;
        inset: 0;
        background: linear-gradient(135deg, rgba(11, 58, 143, .12), rgba(6, 26, 69, .45));
        pointer-events: none;
      }

      .lg-back {
        z-index: 3;
        top: 40px;
        left: 40px;
        width: 45px;
        height: 45px;
        font-size: 18px;
        color: #111111;
        box-shadow: 0 4px 15px rgba(0, 0, 0, .2);
        transition: transform .3s, background-color .3s;
      }

      .lg-back:hover {
        color: #111111;
        background: #f3f4f6;
        transform: translateX(-5px);
      }

      .lg-logo {
        position: relative;
        z-index: 2;
        inset: auto;
        display: flex;
        margin-bottom: 25px;
      }

      .lg-logo img {
        width: 120px;
        height: 120px;
        filter: drop-shadow(0 10px 15px rgba(0, 0, 0, .3));
      }

      .lg-logo i {
        font-size: 96px;
        filter: drop-shadow(0 10px 15px rgba(0, 0, 0, .3));
      }

      .lg-info {
        display: block;
        position: relative;
        z-index: 2;
        max-width: 400px;
        text-align: center;
        animation: lgFade 1s ease-out both;
      }

      .lg-info h2 {
        margin: 0 0 15px;
        font-size: 28px;
        font-weight: 700;
        letter-spacing: .5px;
        color: #fff;
      }

      .lg-info p {
        margin: 0 0 30px;
        font-size: 15px;
        line-height: 1.6;
        color: #d1d5db;
      }

      .lg-garis {
        width: 60px;
        height: 4px;
        margin: 0 auto;
        border-radius: 2px;
        background: #facc15;
      }

      /* ---- panel kanan: formulir ---- */
      .lg-body {
        position: relative;
        z-index: 2;
        flex: none;
        width: 40%;
        min-width: 440px;
        min-height: 100vh;
        margin: 0 0 0 auto;
        padding: 60px clamp(32px, 4.5vw, 80px);
        border-top-left-radius: 60px;
        justify-content: center;
        align-items: center;
        box-shadow: -10px 0 30px rgba(0, 0, 0, .1);
      }

      .lg-body>* {
        width: 100%;
        max-width: 420px;
      }

      .lg-judul {
        text-align: left;
        font-size: 38px;
        font-weight: 700;
        letter-spacing: 0;
        line-height: 1.2;
        margin-bottom: 5px;
      }

      .lg-sub {
        text-align: left;
        margin: 0;
        font-size: 15px;
        font-weight: 500;
      }

      .lg-form {
        flex: none;
        margin-top: 35px;
      }

      .lg-err,
      .lg-ok {
        padding: 12px 18px;
        font-size: 13.5px;
        border-radius: 18px;
      }

      .lg-field {
        margin-bottom: 20px;
      }

      .lg-field input {
        height: 58px;
        padding: 0 25px;
        font-family: inherit;
        font-size: 15px;
        font-weight: 600;
      }

      .lg-field input::placeholder {
        color: #6b7280;
      }

      .lg-field.pw input {
        padding-right: 62px;
      }

      .lg-field input:focus {
        box-shadow: 0 0 0 4px var(--fokus-cahaya), 0 8px 22px -10px rgba(47, 111, 214, .55);
      }

      .lg-eye {
        right: 18px;
        width: 40px;
        height: 40px;
        font-size: 18px;
      }

      .lg-ruang {
        flex: none;
        min-height: 0;
        height: 15px;
      }

      .lg-btn {
        height: 58px;
        font-family: inherit;
        font-size: 16px;
        font-weight: 700;
        box-shadow: 0 10px 20px rgba(15, 63, 158, .25);
        transition: all .3s ease;
      }

      .lg-btn:hover {
        background: var(--btn-biru-hover);
        transform: translateY(-2px);
        box-shadow: 0 12px 25px rgba(15, 63, 158, .35);
      }

      .lg-kaki {
        margin-top: 50px;
        font-size: 14px;
        font-weight: 400;
      }

      .lg-kaki a {
        color: #111111;
        font-weight: 700;
        transition: color .2s;
      }

      .lg-kaki a:hover {
        color: #000;
        text-decoration: none;
      }

      .lg-hak {
        margin-top: 15px;
        font-size: 12px;
        font-weight: 500;
        letter-spacing: .5px;
        text-transform: uppercase;
        color: #9ca3af;
        opacity: 1;
      }

      /* animasi masuk berurutan */
      .lg-a {
        animation: lgUp .6s both;
      }

      .lg-a1 {
        animation-delay: .1s;
      }

      .lg-a2 {
        animation-delay: .2s;
      }

      .lg-a3 {
        animation-delay: .3s;
      }

      .lg-a4 {
        animation-delay: .4s;
      }
    }

    /* layar desktop pendek: judul sedikit dirampingkan agar formulir tetap muat tanpa menggulir */
    @media (min-width: 900px) and (max-height: 640px) {
      .lg-body {
        padding-top: 32px;
        padding-bottom: 32px;
      }

      .lg-judul {
        font-size: 32px;
      }

      .lg-form {
        margin-top: 24px;
      }

      .lg-kaki {
        margin-top: 28px;
      }
    }

    @media (prefers-reduced-motion: reduce) {

      .lg-a,
      .lg-info {
        animation: none !important;
      }
    }
  </style>
</head>

<body class="login-page">
  <main class="lg-card">

    <div class="lg-hero">
      <!-- Latar: pola geometris Islam biru (CSS: --pola-gelap), tanpa file gambar tambahan -->
      <a href="<?= BASE_URL ?>/" class="lg-back" id="btnKembali" title="Kembali" aria-label="Kembali"><i class="bi bi-chevron-left"></i></a>
      <div class="lg-logo"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="Logo <?= e($namaSekolah) ?>"><?php else: ?><i class="bi bi-qr-code-scan" aria-hidden="true"></i><?php endif; ?></div>

      <!-- Hanya tampil di desktop (>= 900px) -->
      <div class="lg-info">
        <h2>Portal Absensi Digital</h2>
        <p>Sistem Informasi Terpadu <?= e($namaSekolah) ?>. Tingkatkan kedisiplinan dan kinerja melalui pemantauan kehadiran yang akurat secara real&#8209;time.</p>
        <div class="lg-garis"></div>
      </div>
    </div>

    <div class="lg-body">
      <h1 class="lg-judul lg-a lg-a1">Selamat Datang</h1>
      <p class="lg-sub lg-a lg-a1">Masuk ke akun Absensi Anda</p>

      <form method="post" autocomplete="off" class="lg-form">
        <?= csrf_field() ?>
        <?php if ($error): ?>
          <div class="lg-err" role="alert"><i class="bi bi-exclamation-circle-fill"></i><span><?= e($error) ?></span></div>
        <?php elseif ($info): ?>
          <div class="lg-ok" role="status"><i class="bi bi-check-circle-fill"></i><span><?= e($info) ?></span></div>
        <?php endif; ?>
        <?php if (!portal_aktif()): ?>
          <div class="lg-ok netral" role="note"><i class="bi bi-info-circle-fill"></i><span>Portal siswa sedang ditutup sementara. Petugas tetap dapat masuk.</span></div>
        <?php endif; ?>
        <div class="lg-field lg-a lg-a2">
          <input type="text" name="username" id="username" placeholder="Username / NISN" aria-label="Username atau NISN" autocomplete="username" required autocapitalize="none" spellcheck="false">
        </div>
        <div class="lg-field pw lg-a lg-a3">
          <input type="password" name="password" id="password" placeholder="Password" aria-label="Password" required>
          <button type="button" class="lg-eye" id="togglePw" aria-label="Tampilkan password"><i class="bi bi-eye-slash-fill"></i></button>
        </div>
        <div class="lg-ruang"></div>
        <button type="submit" class="lg-btn lg-a lg-a4" id="btnMasuk">Masuk</button>
      </form>

      <p class="lg-kaki lg-a lg-a4">
        Siswa belum punya password? <a href="<?= e(portal_url('aktivasi')) ?>">Aktivasi akun</a><br>
        <a href="<?= BASE_URL ?>/">Kembali ke beranda</a>
        <span class="lg-hak"><?= e($namaSekolah) ?> · SIAP ABSEN &copy; <?= date('Y') ?></span>
      </p>
    </div>

  </main>
  <script>
    (function() {
      // Tombol kembali (#btnKembali) sengaja TIDAK dicegat skrip: ia hanya tautan biasa ke beranda ("/"),
      // sehingga satu kali sentuh langsung membuka beranda (bukan halaman sebelumnya / riwayat browser).

      // Kunci tinggi tampilan di HP: ukuran diambil saat keyboard BELUM muncul, lalu tidak ikut mengecil
      // ketika keyboard terbuka. Hanya diperbarui bila lebar layar berubah (layar diputar) atau tinggi benar-benar membesar.
      var hp = window.matchMedia('(max-width: 600px)');
      var root = document.documentElement,
        tinggi = 0,
        lebar = 0;

      function kunci() {
        if (!hp.matches) {
          document.body.classList.remove('tinggi-tetap');
          root.style.removeProperty('--tinggi');
          tinggi = lebar = 0;
          return;
        }
        var h = window.innerHeight,
          w = window.innerWidth;
        var aktif = document.activeElement,
          mengetik = aktif && (aktif.tagName === 'INPUT' || aktif.tagName === 'TEXTAREA');
        if (tinggi && w === lebar && (h <= tinggi || mengetik)) return; // keyboard muncul/menutup: abaikan
        tinggi = h;
        lebar = w;
        root.style.setProperty('--tinggi', h + 'px');
        document.body.classList.add('tinggi-tetap');
      }
      kunci();
      window.addEventListener('resize', kunci);
      hp.addEventListener && hp.addEventListener('change', function() {
        tinggi = 0;
        kunci();
      });

      // Pengaman: tata letak tetap diam saat keyboard muncul. Hanya bila kolom yang sedang diketik tertutup keyboard
      // (layar pendek), kartu digeser naik SEPERLUNYA saja agar kolom itu terlihat; kembali normal saat keyboard menutup.
      var vv = window.visualViewport,
        kartu = document.querySelector('.lg-card'),
        geserNow = 0;

      function jagaTerlihat() {
        if (!vv || !hp.matches) return;
        var a = document.activeElement,
          geser = 0;
        var keyboardBuka = tinggi && (tinggi - vv.height) > 120;
        if (keyboardBuka && a && a.tagName === 'INPUT') {
          var bawahAsli = a.getBoundingClientRect().bottom - geserNow; // posisi tanpa geseran
          var batas = vv.offsetTop + vv.height; // tepi bawah area yang terlihat
          geser = Math.min(Math.max(0, Math.ceil(bawahAsli + 14 - batas)), 220);
        }
        if (geser !== geserNow) {
          geserNow = geser;
          kartu.style.transform = geser ? 'translateY(' + (-geser) + 'px)' : '';
        }
      }
      if (vv) {
        vv.addEventListener('resize', jagaTerlihat);
        vv.addEventListener('scroll', jagaTerlihat);
      }
      document.addEventListener('focusin', function() {
        setTimeout(jagaTerlihat, 60);
        setTimeout(jagaTerlihat, 350);
      });
      document.addEventListener('focusout', function() {
        setTimeout(jagaTerlihat, 60);
      });

      // Fokus otomatis ke username hanya di perangkat dengan mouse (desktop); di HP keyboard tidak boleh muncul sendiri.
      if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        document.getElementById('username').focus();
      }

      var pw = document.getElementById('password'),
        tg = document.getElementById('togglePw');
      tg.addEventListener('click', function() {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        tg.innerHTML = '<i class="bi bi-eye' + (show ? '-fill' : '-slash-fill') + '"></i>';
        tg.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
        pw.focus();
      });
      document.querySelector('form').addEventListener('submit', function() {
        var b = document.getElementById('btnMasuk');
        setTimeout(function() {
          b.disabled = true;
          b.innerHTML = '<span class="spinner-border spinner-border-sm"></span>Memproses...';
        }, 0);
      });
    })();
  </script>
  <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['keluar'] ?? '') !== '1' && ($_GET['aktif'] ?? '') !== '1'): ?>
    <script src="<?= BASE_URL ?>/assets/js/device.js?v=<?= @filemtime(__DIR__ . '/assets/js/device.js') ?: 0 ?>"></script>
    <script>
      // Perangkat siswa yang sudah terdaftar masuk otomatis (tanpa password). Gagal/belum terdaftar: formulir ini tetap dipakai.
      (function() {
        try {
          var u = getDeviceUuidIfExists();
          if (!u) return;
          fetch(<?= json_encode(portal_url('masuk-perangkat')) ?>, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json'
              },
              body: JSON.stringify({
                uuid: u
              })
            })
            .then(function(r) {
              return r.json();
            })
            .then(function(d) {
              if (d && d.ok) location.replace(<?= json_encode(portal_url('', ['_g' => 1])) ?>);
            })
            .catch(function() {});
        } catch (e) {}
      })();
    </script>
  <?php endif; ?>
</body>

</html>