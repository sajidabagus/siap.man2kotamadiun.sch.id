<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/portal.php';

if (is_logged_in()) {
  header('Location: index.php');
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
            header('Location: index.php?modul=dashboard');
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
  <meta name="theme-color" content="#0b3a27">
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
      --hj-tua: #0f5c3c;
      /* tombol & judul */
      --hj-judul: #1d5a3f;
      --hj-teks: #3f8a69;
      /* teks isian & tautan */
      --hj-redup: #7a9487;
      /* keterangan */
      --hj-isian: #e8efeb;
      /* latar kolom isian */
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
        radial-gradient(60% 45% at 12% 8%, rgba(120, 190, 150, .38), transparent 70%),
        radial-gradient(55% 45% at 92% 92%, rgba(100, 170, 135, .34), transparent 70%),
        #eaf2ed;
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
      box-shadow: 0 30px 60px -28px rgba(14, 60, 40, .55), 0 2px 8px rgba(14, 60, 40, .08);
    }

    /* ---- bagian atas bergambar daun ---- */
    .lg-hero {
      position: relative;
      height: 250px;
      flex: none;
      background: #0b3a27;
    }

    .lg-hero>svg {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      display: block;
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

    /* Logo transparan: tanpa lingkaran/latar putih; bayangan mengikuti bentuk logo agar tetap terbaca di atas gambar daun */
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
      border-color: var(--hj-teks);
      box-shadow: 0 0 0 4px rgba(63, 138, 105, .16);
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
      background: rgba(15, 92, 60, .09);
    }

    .lg-eye:focus-visible {
      outline: 2px solid var(--hj-teks);
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
      background: var(--hj-tua);
      color: #fff;
      font: 700 15px/1 'Montserrat', system-ui, sans-serif;
      cursor: pointer;
      box-shadow: 0 12px 22px -12px rgba(15, 92, 60, .9);
      transition: background .15s, transform .15s;
    }

    .lg-btn:hover {
      background: #0b4a31;
    }

    .lg-btn:active {
      transform: scale(.985);
    }

    .lg-btn:focus-visible {
      outline: 3px solid rgba(63, 138, 105, .5);
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
     Kiri  : panel bermerek (logo, judul, deskripsi) di atas gambar daun
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
        --hj-tua: #114e2e;
        --hj-judul: #114e2e;
        --hj-teks: #167a4a;
        --hj-redup: #6b7280;
        --hj-isian: #e6eee9;
        --hj-hover: #167a4a;
      }

      body.login-page {
        font-family: 'Poppins', 'Montserrat', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
        padding: 0;
        background: #114e2e;
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
        background: #114e2e;
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
        background: #0b3a27;
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
        background: linear-gradient(135deg, rgba(17, 78, 46, .58), rgba(13, 56, 33, .8));
        pointer-events: none;
      }

      .lg-back {
        z-index: 3;
        top: 40px;
        left: 40px;
        width: 45px;
        height: 45px;
        font-size: 18px;
        color: #114e2e;
        box-shadow: 0 4px 15px rgba(0, 0, 0, .2);
        transition: transform .3s, background-color .3s;
      }

      .lg-back:hover {
        color: #114e2e;
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
        color: #55876c;
      }

      .lg-field.pw input {
        padding-right: 62px;
      }

      .lg-field input:focus {
        box-shadow: 0 0 0 4px rgba(22, 122, 74, .1);
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
        box-shadow: 0 10px 20px rgba(17, 78, 46, .2);
        transition: all .3s ease;
      }

      .lg-btn:hover {
        background: var(--hj-hover);
        transform: translateY(-2px);
        box-shadow: 0 12px 25px rgba(17, 78, 46, .3);
      }

      .lg-kaki {
        margin-top: 50px;
        font-size: 14px;
        font-weight: 400;
      }

      .lg-kaki a {
        color: #114e2e;
        font-weight: 700;
        transition: color .2s;
      }

      .lg-kaki a:hover {
        color: var(--hj-hover);
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
      <!-- Latar daun hijau (SVG, tanpa file gambar tambahan) -->
      <svg viewBox="0 0 380 232" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
        <defs>
          <linearGradient id="lgBg" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#072a1c" />
            <stop offset=".55" stop-color="#0d4a31" />
            <stop offset="1" stop-color="#14663f" />
          </linearGradient>
          <linearGradient id="lgDaunA" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#0a3a27" />
            <stop offset="1" stop-color="#1f8454" />
          </linearGradient>
          <linearGradient id="lgDaunB" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#0c4a30" />
            <stop offset="1" stop-color="#2a9a63" />
          </linearGradient>
          <linearGradient id="lgDaunC" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#061f15" />
            <stop offset="1" stop-color="#0f5b3a" />
          </linearGradient>
          <radialGradient id="lgSorot" cx=".5" cy=".38" r=".55">
            <stop offset="0" stop-color="#0b3a27" stop-opacity=".55" />
            <stop offset="1" stop-color="#0b3a27" stop-opacity="0" />
          </radialGradient>
          <!-- bentuk daun + tulang daun; warna isi diberikan lewat <use> -->
          <g id="lgDaun">
            <path d="M0 0 C 46 -74 168 -86 262 -24 C 178 38 66 52 0 0 Z" />
            <path d="M6 -2 Q 120 -34 252 -24" fill="none" stroke="rgba(255,255,255,.14)" stroke-width="2.2" stroke-linecap="round" />
          </g>
        </defs>
        <rect width="380" height="232" fill="url(#lgBg)" />
        <use href="#lgDaun" fill="url(#lgDaunC)" transform="translate(-70 70) rotate(-24) scale(1.35)" />
        <use href="#lgDaun" fill="url(#lgDaunA)" transform="translate(-30 215) rotate(-38) scale(1.25)" />
        <use href="#lgDaun" fill="url(#lgDaunB)" transform="translate(110 -20) rotate(52) scale(1.15)" />
        <use href="#lgDaun" fill="url(#lgDaunC)" transform="translate(420 20) rotate(150) scale(1.3)" />
        <use href="#lgDaun" fill="url(#lgDaunA)" transform="translate(440 190) rotate(205) scale(1.2)" />
        <use href="#lgDaun" fill="url(#lgDaunB)" transform="translate(230 235) rotate(-62) scale(.9)" opacity=".85" />
        <use href="#lgDaun" fill="url(#lgDaunC)" transform="translate(-10 -10) rotate(30) scale(.8)" opacity=".9" />
        <rect width="380" height="232" fill="url(#lgSorot)" />
      </svg>
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
    <script src="<?= BASE_URL ?>/assets/js/device.js"></script>
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