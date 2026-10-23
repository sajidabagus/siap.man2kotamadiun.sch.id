<?php
/**
 * HALAMAN DEPAN PUBLIK (tanpa login)
 * Ringkasan kehadiran hari ini (diagram lingkaran + persentase), histori absensi realtime,
 * tombol Scan Absen (mengecek apakah perangkat sudah terdaftar) dan pendaftaran perangkat
 * (lewat aktivasi akun dengan kode dari petugas). Dimuat oleh index.php bila belum login.
 */
// Berkas ini HANYA templat halaman depan yang dimuat index.php (alamat "/"). Alamat /beranda bukan halaman:
// bila berkas ini dijalankan langsung (aturan .htaccess memetakan /beranda -> beranda.php), tampilkan 404.
if (!defined('APP_ROUTER')) {
    http_response_code(404);
    readfile(__DIR__ . '/404.html');
    exit;
}
$settings = get_settings();
$namaSekolah = $settings['nama_sekolah'] ?? 'Sekolah';
$logo = $settings['logo'] ?? '';
require_once __DIR__ . '/includes/siswa_desktop.php';
$logoUrl = ($logo !== '' && is_file(__DIR__ . '/uploads/siswa/' . $logo)) ? BASE_URL . '/uploads/siswa/' . rawurlencode($logo) : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($namaSekolah) ?> — Absensi Siswa</title>
<?= favicon_tag() ?>
<meta name="theme-color" content="#f7f8fa">
<style>html{background:#f7f8fa}</style>
<link rel="preload" href="<?= BASE_URL ?>/assets/css/siswa-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/siswa-app.css') ?: time() ?>" as="style">
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/siswa-desktop.css?v=<?= @filemtime(__DIR__ . '/assets/css/siswa-desktop.css') ?: time() ?>" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/siswa-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/siswa-app.css') ?: time() ?>" rel="stylesheet">
<style>
  :root { --biru: #0d6efd; --navy: #14213d; --abu: #8a94a6; --garis: #e9edf3; --hijau: #2fb344; --oranye: #f08c1a; --merah: #e03e4a; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { overscroll-behavior: none; }
  body { font-family: "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif; color: var(--navy);
         background: radial-gradient(900px 420px at 10% -10%, #dbe8ff, transparent 60%), radial-gradient(800px 400px at 100% 0, #e6e0ff, transparent 55%), #f4f6fb;
         min-height: 100vh; -webkit-text-size-adjust: 100%; }
  .wrap { max-width: 980px; margin: 0 auto; padding: 18px 16px 40px; }
  .top { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
  .top .logo { width: 52px; height: 52px; background: transparent; display: grid; place-items: center; font-size: 34px; flex: none; color: var(--biru); }
  .top .logo img { width: 100%; height: 100%; object-fit: contain; background: transparent; border-radius: 0; }
  .top .nama { font-weight: 800; line-height: 1.15; text-transform: uppercase; letter-spacing: .01em; }
  .top .sub { font-size: .78rem; font-weight: 700; letter-spacing: .12em; color: var(--biru); }
  .top .admin { margin-left: auto; font-size: .85rem; font-weight: 600; text-decoration: none; color: var(--navy); border: 1px solid #dfe4ec; background: #fff; padding: 7px 12px; border-radius: 10px; white-space: nowrap; }
  .top .admin:hover { border-color: var(--biru); color: var(--biru); }

  .card { background: #fff; border: 1px solid var(--garis); border-radius: 18px; box-shadow: 0 1px 3px rgba(20,33,61,.05); }
  .hero { padding: 22px; margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 18px; align-items: center; justify-content: space-between; }
  .jam { font-size: 2.6rem; font-weight: 800; letter-spacing: .02em; font-variant-numeric: tabular-nums; line-height: 1; }
  .tgl { color: var(--abu); font-weight: 600; margin-top: 6px; }
  .chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
  .chip { display: inline-flex; align-items: center; gap: 7px; padding: 5px 12px; border-radius: 999px; font-size: .8rem; font-weight: 700; background: #f1f3f8; color: #5b6580; }
  .chip.ok { background: #e8f7ec; color: var(--hijau); } .chip.tutup { background: #fdecee; color: var(--merah); }
  .chip.warn { background: #fff4e5; color: #c46a00; }
  .aksi { display: flex; flex-direction: column; gap: 10px; min-width: 250px; flex: 1 1 250px; max-width: 340px; }
  .btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; border: 0; border-radius: 14px; font: inherit; font-weight: 700; cursor: pointer; padding: 15px 18px; transition: transform .12s, box-shadow .12s, background .12s; text-decoration: none; }
  .btn:active { transform: scale(.98); }
  .btn-scan { background: linear-gradient(135deg, #1f6feb, #5b3df5); color: #fff; font-size: 1.1rem; box-shadow: 0 12px 24px -12px rgba(31,111,235,.9); }
  .btn-scan:hover { box-shadow: 0 16px 28px -12px rgba(31,111,235,1); }
  .btn-ghost { background: #fff; color: var(--navy); border: 1.5px solid #dfe4ec; padding: 12px 18px; }
  .btn-ghost:hover { border-color: var(--biru); color: var(--biru); }
  .btn-primary { background: var(--biru); color: #fff; } .btn-primary:disabled { opacity: .5; cursor: not-allowed; }
  .btn-batal { background: #f1f3f8; color: var(--navy); }
  .status-hp { font-size: .84rem; font-weight: 600; text-align: center; display: flex; align-items: center; justify-content: center; gap: 7px; color: var(--abu); min-height: 20px; }
  .status-hp.ok { color: var(--hijau); } .status-hp.no { color: #c46a00; } .status-hp.blok { color: var(--merah); }

  .grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 16px; }
  @media (max-width: 760px) { .grid { grid-template-columns: 1fr; } .jam { font-size: 2.2rem; } .aksi { max-width: none; } }
  .panel { padding: 20px 22px; }
  .panel h2 { font-size: 1rem; font-weight: 800; margin-bottom: 2px; }
  .panel .ket { color: var(--abu); font-size: .84rem; margin-bottom: 14px; }
  .donut-box { display: flex; flex-direction: column; align-items: center; gap: 16px; }
  .donut { position: relative; width: 210px; height: 210px; }
  .donut svg { width: 100%; height: 100%; transform: rotate(-90deg); }
  .donut circle { fill: none; stroke-width: 26; transition: stroke-dasharray .8s ease, stroke-dashoffset .8s ease; }
  .donut .tengah { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
  .donut .persen { font-size: 2.6rem; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
  .donut .persen small { font-size: 1.2rem; font-weight: 700; }
  .donut .lbl { color: var(--abu); font-weight: 700; font-size: .8rem; letter-spacing: .08em; text-transform: uppercase; margin-top: 4px; }
  .legend { width: 100%; display: grid; gap: 8px; }
  .lg { display: flex; align-items: center; gap: 10px; font-size: .92rem; }
  .lg .dot { width: 11px; height: 11px; border-radius: 50%; flex: none; }
  .lg .n { margin-left: auto; font-weight: 800; font-variant-numeric: tabular-nums; }
  .lg .p { width: 48px; text-align: right; color: var(--abu); font-variant-numeric: tabular-nums; }

  .live { display: inline-flex; align-items: center; gap: 7px; padding: 4px 11px; border-radius: 999px; background: #e8f7ec; color: var(--hijau); font-weight: 800; font-size: .72rem; letter-spacing: .08em; }
  .live::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; animation: kedip 1.4s infinite; }
  .live.putus { background: #f1f3f8; color: var(--abu); } .live.putus::before { animation: none; }
  @keyframes kedip { 0%,100% { opacity: 1; } 50% { opacity: .3; } }
  #feed { max-height: 460px; overflow-y: auto; margin: 0 -22px; scrollbar-width: none; }
  #feed::-webkit-scrollbar { display: none; }
  /* Mode kelas (perangkat terdaftar): semua siswa yang sudah presensi, scrollbar tipis ditampilkan sebagai petunjuk bisa di-scroll */
  #feed.kelas { scrollbar-width: thin; scrollbar-color: #cbd3e1 transparent; }
  #feed.kelas::-webkit-scrollbar { display: block; width: 6px; }
  #feed.kelas::-webkit-scrollbar-thumb { background: #cbd3e1; border-radius: 6px; }
  .fi { display: flex; align-items: center; gap: 13px; padding: 11px 22px; border-bottom: 1px solid #f1f3f8; }
  .fi:last-child { border-bottom: 0; }
  .fi .av { width: 40px; height: 40px; border-radius: 50%; flex: none; display: grid; place-items: center; color: #fff; font-weight: 700; font-size: .82rem; }
  .fi .nm { font-weight: 700; line-height: 1.2; } .fi .kl { color: var(--abu); font-size: .82rem; }
  .fi .wk { margin-left: auto; text-align: right; font-weight: 800; font-variant-numeric: tabular-nums; }
  .fi .wk small { display: block; font-size: .72rem; font-weight: 700; }
  .fi .wk .h { color: var(--hijau); } .fi .wk .t { color: #d9631e; }
  .fi.baru { animation: baru 3s ease-out; }
  @keyframes baru { 0% { background: #e6f7ec; transform: translateY(-8px); opacity: 0; } 12% { opacity: 1; transform: none; } 100% { background: transparent; } }
  .kosong { padding: 34px 22px; text-align: center; color: var(--abu); }
  .foot { text-align: center; color: var(--abu); font-size: .78rem; letter-spacing: .08em; margin-top: 26px; }

  /* modal */
  .ov { position: fixed; inset: 0; z-index: 3000; background: rgba(15,23,42,.55); display: none; align-items: center; justify-content: center; padding: 16px; }
  .ov.buka { display: flex; }
  .kotak { background: #fff; border-radius: 20px; width: 100%; max-width: 440px; padding: 24px; box-shadow: 0 24px 60px rgba(0,0,0,.35); max-height: 94vh; overflow-y: auto; animation: muncul .18s ease-out; }
  @keyframes muncul { from { opacity: 0; transform: translateY(10px) scale(.98); } to { opacity: 1; transform: none; } }
  .kotak h3 { font-size: 1.25rem; font-weight: 800; }
  .kotak .eyebrow { font-size: .72rem; font-weight: 800; letter-spacing: .12em; color: var(--abu); }
  .tengah { text-align: center; }
  .tengah .ik { font-size: 2.8rem; line-height: 1; margin-bottom: 8px; }
  .kotak p.d { color: #5b6580; margin: 8px 0 18px; line-height: 1.55; }
  .baris-tombol { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
  .baris-tombol .btn { padding: 12px 20px; }
  .tab { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; background: #f1f3f8; padding: 5px; border-radius: 14px; margin: 14px 0 18px; }
  .tab button { border: 0; background: transparent; font: inherit; font-weight: 700; padding: 10px; border-radius: 10px; cursor: pointer; color: #5b6580; display: flex; align-items: center; justify-content: center; gap: 8px; }
  .tab button.aktif { background: #fff; color: var(--biru); box-shadow: 0 1px 4px rgba(20,33,61,.12); }
  label.f { display: block; font-size: .78rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: #5b6580; margin: 12px 0 6px; }
  input.in { width: 100%; border: 1.5px solid #dfe4ec; border-radius: 12px; padding: 13px 14px; font: inherit; background: #fff; outline: none; }
  input.in:focus { border-color: var(--biru); box-shadow: 0 0 0 4px rgba(13,110,253,.12); }
  input.kode { text-align: center; font-weight: 800; font-size: 1.6rem; letter-spacing: .45em; }
  .err { color: var(--merah); font-size: .88rem; margin-top: 10px; display: none; }
  .err.tampil { display: block; }
  .info { background: #eaf3ff; color: #1b4f9c; border-radius: 12px; padding: 11px 13px; font-size: .86rem; line-height: 1.5; margin-top: 14px; text-align: left; }
  #qrBox { width: 100%; min-height: 250px; border-radius: 14px; overflow: hidden; background: #0f172a; display: grid; place-items: center; color: #94a3b8; text-align: center; padding: 10px; }
  #qrBox video { border-radius: 14px; }
  .tutup-x { border: 0; background: #f1f3f8; width: 34px; height: 34px; border-radius: 10px; cursor: pointer; font-size: 1rem; color: #5b6580; flex: none; }
  .hide { display: none !important; }

  /* ===== Tampilan siswa (perangkat terdaftar & aktif) — tanpa login ===== */
  .app { display: none; }
  body.mode-siswa .top, body.mode-siswa .hero, body.mode-siswa #panelDonut { display: none; }
  body.mode-siswa .app { display: block; }
  body.mode-siswa .wrap { max-width: 520px; padding-bottom: 110px; }
  @media (min-width: 1024px) { body.mode-siswa .wrap { max-width: 760px; padding: 28px 32px 40px; } }
  body.mode-siswa .grid { display: block; }
  .ap-hello { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin: 4px 0 18px; }
  .ap-hello h1 { font-size: 1.6rem; font-weight: 800; line-height: 1.2; }
  .ap-hello p { color: #5b6580; margin-top: 4px; font-weight: 500; }
  .ap-hello .ap-masuk { flex: none; width: 42px; height: 42px; border-radius: 50%; display: grid; place-items: center; color: var(--navy); background: #fff; border: 1px solid var(--garis); text-decoration: none; font-size: 1.15rem; }
  .ap-jam { background: linear-gradient(135deg, #1f6feb, #2a5bd7); color: #fff; border-radius: 20px; padding: 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; box-shadow: 0 14px 28px -16px rgba(31,111,235,.9); }
  .ap-jam .lbl { font-size: .9rem; opacity: .92; font-weight: 600; }
  .ap-jam .w { font-size: 2.9rem; font-weight: 800; font-variant-numeric: tabular-nums; line-height: 1.1; margin: 6px 0 10px; }
  .ap-jam .w .dt { font-size: 1.35rem; font-weight: 700; opacity: .75; margin-left: 2px; }
  .ap-jam .w small { font-size: .95rem; font-weight: 700; margin-left: 6px; }
  .ap-jam .lok { font-size: .85rem; display: flex; align-items: center; gap: 6px; opacity: .95; }
  .ap-jam svg { width: 112px; height: 112px; flex: none; }
  .ap-absen { width: 100%; margin: 16px 0; padding: 18px; font-size: 1.2rem; background: #2fb344; color: #fff; border-radius: 16px; box-shadow: 0 12px 22px -14px rgba(47,179,68,.95); }
  .ap-absen i { font-size: 1.5rem; }
  .ap-absen:disabled { background: #9fd8ab; box-shadow: none; cursor: default; }
  .ap-card { background: #fff; border: 1px solid var(--garis); border-radius: 18px; padding: 16px; box-shadow: 0 1px 3px rgba(20,33,61,.05); }
  .ap-card h2, .ap-sec h2 { font-size: 1rem; font-weight: 800; }
  .ap-status { display: flex; align-items: center; gap: 14px; margin-top: 12px; text-decoration: none; color: inherit; }
  .ap-status .lg { width: 58px; height: 58px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-size: 1.7rem; flex: none; background: var(--c); }
  .ap-status b { display: block; color: var(--c); font-size: 1.2rem; }
  .ap-status span { display: block; color: #3d4659; font-size: .9rem; margin-top: 2px; }
  .ap-status .ch { margin-left: auto; color: #8a94a6; }
  .ap-sec { display: flex; align-items: center; justify-content: space-between; margin: 20px 0 10px; }
  .ap-sec a { color: var(--biru); font-weight: 600; font-size: .88rem; text-decoration: none; }
  .ap-tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
  .ap-tiles a { position: relative; border-radius: 16px; padding: 14px 12px 40px; text-decoration: none; color: var(--navy); border: 1px solid transparent; }
  .ap-tiles b { display: block; font-size: 1.9rem; font-weight: 800; line-height: 1.1; }
  .ap-tiles span { font-weight: 600; font-size: .92rem; }
  .ap-tiles i { position: absolute; right: 12px; bottom: 10px; font-size: 1.35rem; }
  .ap-tiles .h { background: #e9f7ee; border-color: #cdebd6; } .ap-tiles .h b, .ap-tiles .h i { color: #23913a; }
  .ap-tiles .i { background: #fff5e3; border-color: #f7e2b5; } .ap-tiles .i b, .ap-tiles .i i { color: #d98a00; }
  .ap-tiles .a { background: #fdeced; border-color: #f6cfd2; } .ap-tiles .a b, .ap-tiles .a i { color: #d63a46; }
</style>
</head>
<body>
<script>
/* Tampilkan langsung tampilan terakhir (cuplikan hari yang sama di perangkat ini) agar tidak "meloncat" dari tampilan publik
   ke tampilan siswa saat data baru dimuat. Data segar tetap diambil dan menimpa cuplikan ini. */
window.SIAP_SNAP = null;
try { var _s = JSON.parse(localStorage.getItem('siapBerandaV1') || 'null');
  if (_s && _s.hari === new Date().toDateString() && _s.d) { window.SIAP_SNAP = _s.d; if (_s.d.mode === 'kelas' && _s.d.saya) document.body.classList.add('mode-siswa'); }
} catch (e) {}
</script>
<?php siswa_desktop_sidebar('beranda', BASE_URL . '/'); siswa_desktop_topbar('', null, 0, 'apTopNama'); ?>
<div class="wrap">

  <div class="top">
    <div class="logo"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt=""><?php else: ?><i class="bi bi-qr-code-scan"></i><?php endif; ?></div>
    <div>
      <div class="nama"><?= e($namaSekolah) ?></div>
      <div class="sub">ABSENSI SISWA</div>
    </div>
    <a href="<?= BASE_URL ?>/login.php" class="admin" title="Masuk"><i class="bi bi-box-arrow-in-right"></i> Masuk</a>
  </div>

  <div class="card hero">
    <div>
      <div class="jam" id="jam">--:--:--</div>
      <div class="tgl" id="tgl">&nbsp;</div>
      <div class="chips">
        <span class="chip" id="chipBuka">memuat…</span>
        <span class="chip" id="chipJam" style="display:none"></span>
        <span class="chip" id="chipSaya" style="display:none"></span>
      </div>
    </div>
    <div class="aksi">
      <button type="button" class="btn btn-scan" id="btnScan"><i class="bi bi-qr-code-scan"></i> Scan Absen</button>
      <div class="status-hp" id="statusHp"><i class="bi bi-hourglass-split"></i> Memeriksa perangkat…</div>
      <button type="button" class="btn btn-ghost hide" id="btnDaftar"><i class="bi bi-phone"></i> Daftarkan Perangkat</button>
    </div>
  </div>

  <!-- Tampilan siswa: hanya diisi bila perangkat ini terdaftar & aktif (lihat render()). Semua teks diisi via textContent. -->
  <section class="app" id="appSiswa" aria-label="Absensi saya">
    <header class="ap-hello">
      <div><h1><span class="sd-wave" aria-hidden="true">👋</span>Halo, <span id="apNama"></span> <span class="m-wave">👋</span></h1><p class="sd-sub">Selamat datang kembali di sistem informasi siswa</p><p class="tgl"><i class="bi bi-calendar-check sd-ik"></i><span id="apTgl">&nbsp;</span></p></div>
      <a class="ap-masuk" href="<?= BASE_URL ?>/portal/notifikasi" aria-label="Notifikasi" title="Notifikasi"><i class="bi bi-bell"></i></a>
    </header>
    <div class="ap-jam">
      <div>
        <div class="lbl"><i class="bi bi-clock sd-ik"></i>Jam Sekarang</div>
        <div class="w"><span id="apJam">--:--</span><span class="dt" id="apDetik">:--</span><small>WIB</small></div>
        <div class="sd-tgl"><i class="bi bi-calendar-check"></i> <span id="apTgl2"></span></div>
        <div class="lok" id="apLok"><i class="bi bi-geo-alt-fill"></i> <span>Memeriksa lokasi…</span></div>
      </div>
      <svg viewBox="0 0 120 120" aria-hidden="true" focusable="false">
        <circle cx="60" cy="60" r="58" fill="rgba(255,255,255,.25)"/><circle cx="60" cy="60" r="51" fill="#fff"/>
        <?php for ($i = 0; $i < 60; $i++): $b = $i % 5 === 0; ?><line x1="60" y1="<?= $b ? 13 : 13.5 ?>" x2="60" y2="<?= $b ? 21 : 16.5 ?>" stroke="<?= $b ? '#14213d' : '#c3cad6' ?>" stroke-width="<?= $b ? 2.8 : 1 ?>" stroke-linecap="round" transform="rotate(<?= $i * 6 ?> 60 60)"/><?php endfor; ?>
        <line id="apJarumJam" x1="60" y1="60" x2="60" y2="35" stroke="#14213d" stroke-width="5" stroke-linecap="round"/>
        <line id="apJarumMenit" x1="60" y1="60" x2="60" y2="20" stroke="#14213d" stroke-width="3.6" stroke-linecap="round"/>
        <line id="apJarumDetik" x1="60" y1="68" x2="60" y2="18" stroke="#2f80ff" stroke-width="1.6" stroke-linecap="round"/>
        <circle cx="60" cy="60" r="4.5" fill="#14213d"/><circle cx="60" cy="60" r="1.6" fill="#2f80ff"/>
      </svg>
    </div>
    <button type="button" class="btn ap-absen" id="apAbsen"><i class="bi bi-fingerprint"></i> <span>Absen Masuk</span></button>
    <div class="ap-card">
      <h2>Status Hari Ini</h2>
      <a class="ap-status" id="apStatus" href="<?= BASE_URL ?>/portal/riwayat">
        <span class="lg"><i class="bi bi-hourglass-split" id="apStIkon"></i></span>
        <span><em class="sd-lbl">Status Hari Ini</em><b id="apStJudul">Belum absen</b><span id="apStMasuk">Masuk: -</span><span class="sd-hide">Pulang: -</span></span>
        <span class="sd-detail"><i class="bi bi-clock"></i> Lihat Detail <i class="bi bi-chevron-right"></i></span>
        <i class="bi bi-chevron-right ch"></i>
      </a>
    </div>
    <div class="ap-sec"><h2>Ringkasan Bulan Ini</h2><a href="<?= BASE_URL ?>/portal/riwayat">Lihat Semua <i class="bi bi-chevron-right sd-ik"></i></a></div>
    <div class="ap-tiles">
      <a class="h" href="<?= BASE_URL ?>/portal/riwayat"><em class="sd-bul"><i class="bi bi-check-lg"></i></em><b id="apHadir">0</b><span>Hadir</span><i class="bi bi-calendar-check"></i><s class="sd-ch bi bi-chevron-right"></s></a>
      <a class="i" href="<?= BASE_URL ?>/portal/riwayat"><em class="sd-bul"><i class="bi bi-clock"></i></em><b id="apIzin">0</b><span>Izin / Sakit</span><i class="bi bi-file-earmark-text-fill"></i><s class="sd-ch bi bi-chevron-right"></s></a>
      <a class="a" href="<?= BASE_URL ?>/portal/riwayat"><em class="sd-bul"><i class="bi bi-x-lg"></i></em><b id="apAlfa">0</b><span>Alfa</span><small class="sd-only" id="apAlfaSub">Tidak ada</small><i class="bi bi-calendar-x-fill"></i><s class="sd-ch bi bi-chevron-right"></s></a>
    </div>
  </section>

  <div class="grid">
    <div class="card panel" id="panelDonut">
      <h2 id="jdlDonut">Kehadiran Hari Ini</h2>
      <div class="ket" id="ketDonut">dari seluruh siswa aktif</div>
      <div class="donut-box">
        <div class="donut">
          <svg viewBox="0 0 200 200" id="svgDonut">
            <circle cx="100" cy="100" r="80" stroke="#e9edf3" stroke-dasharray="502.65 502.65"/>
          </svg>
          <div class="tengah"><div class="persen"><span id="persen">0</span><small>%</small></div><div class="lbl">Hadir</div></div>
        </div>
        <div class="legend">
          <div class="lg"><span class="dot" style="background:var(--hijau)"></span>Tepat waktu<span class="n" id="nTepat">0</span><span class="p" id="pTepat">0%</span></div>
          <div class="lg"><span class="dot" style="background:var(--oranye)"></span>Terlambat<span class="n" id="nTerlambat">0</span><span class="p" id="pTerlambat">0%</span></div>
          <div class="lg"><span class="dot" style="background:var(--merah)"></span>Izin / Sakit / Alpa<span class="n" id="nTidak">0</span><span class="p" id="pTidak">0%</span></div>
          <div class="lg"><span class="dot" style="background:#cbd3e1"></span>Belum presensi<span class="n" id="nBelum">0</span><span class="p" id="pBelum">0%</span></div>
        </div>
      </div>
    </div>

    <div class="card panel">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:2px">
        <h2 style="margin:0" id="jdlFeed">Absensi Terbaru</h2>
        <span class="live" id="liveBadge" style="margin-left:auto">LIVE</span>
      </div>
      <div class="ket" id="waktuUpdate">memuat…</div>
      <div id="feed"><div class="kosong">Memuat data…</div></div>
    </div>
  </div>

  <div class="foot">SIAP ABSEN &copy; <?= date('Y') ?></div>
</div>

<nav class="ap-nav" aria-label="Menu siswa">
  <a href="<?= BASE_URL ?>/" class="on" aria-current="page"><i class="bi bi-house-door-fill"></i>Home</a>
  <a href="<?= BASE_URL ?>/portal/riwayat"><i class="bi bi-journal-text"></i>Riwayat</a>
  <a class="scan" id="apNavScan" role="button" aria-label="Scan QR absen"><i class="bi bi-qr-code-scan"></i></a>
  <a href="<?= BASE_URL ?>/portal/izin"><i class="bi bi-file-earmark-text"></i>Izin</a>
  <a href="<?= BASE_URL ?>/portal/profil"><i class="bi bi-person"></i>Profile</a>
</nav>
<?php $vtBase = rtrim((string) (parse_url(BASE_URL, PHP_URL_PATH) ?? ''), '/'); ?>
<script type="speculationrules">{"prerender":[{"where":{"and":[{"selector_matches":".ap-nav a[href], .ap-tiles a, .ap-sec a"},{"href_matches":"<?= $vtBase ?>/portal/(riwayat|izin|profil)"}]},"eagerness":"moderate"}],"prefetch":[{"where":{"and":[{"selector_matches":".ap-nav a[href]"},{"href_matches":"<?= $vtBase ?>/"}]},"eagerness":"moderate"}]}</script>

<!-- Dialog: Perangkat belum terdaftar -->
<div class="ov" id="mdBelum" role="dialog" aria-modal="true">
  <div class="kotak tengah">
    <div class="ik"><i class="bi bi-phone"></i></div>
    <h3 id="belumJudul">Perangkat Belum Terdaftar</h3>
    <p class="d" id="belumIsi">Untuk absensi, perangkat ini harus terlebih dahulu dihubungkan dengan akun siswa.</p>
    <div class="baris-tombol">
      <button type="button" class="btn btn-primary" id="belumDaftar"><i class="bi bi-phone"></i> Daftarkan Perangkat</button>
      <button type="button" class="btn btn-batal" id="belumBatal">Batal</button>
    </div>
  </div>
</div>

<!-- Dialog: pendaftaran perangkat (satu langkah lewat aktivasi akun) -->
<div class="ov" id="mdDaftar" role="dialog" aria-modal="true" aria-labelledby="dfJudul">
  <div class="kotak">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
      <div><div class="eyebrow">PENDAFTARAN PERANGKAT</div><h3 id="dfJudul">Daftarkan Perangkat</h3></div>
      <button type="button" class="tutup-x" id="dfTutup" aria-label="Tutup"><i class="bi bi-x-lg"></i></button>
    </div>
    <p class="d" style="margin:10px 0 4px;color:#5b6580;font-size:.92rem">Perangkat didaftarkan bersamaan dengan <strong>aktivasi akun</strong>. Minta <strong>kode aktivasi</strong> ke petugas sekolah, lalu masukkan di halaman aktivasi pada <strong>aplikasi SIAP</strong> (Android) atau <strong>Safari</strong> (iPhone). Cukup satu kali.</p>
    <a class="btn btn-primary" href="<?= BASE_URL ?>/portal/aktivasi" style="width:100%;margin-top:14px;text-align:center"><i class="bi bi-key"></i> Aktivasi Akun &amp; Daftarkan Perangkat</a>
    <div class="info">Sudah punya akun? Masuk ke portal dari aplikasi SIAP, lalu pilih <strong>Daftarkan perangkat ini</strong> (isi <strong>kode perangkat</strong> bila diminta petugas). Perangkat hanya dipakai untuk presensi; akun tetap bisa dibuka di perangkat lain.</div>
  </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/device.js?v=<?= @filemtime(__DIR__ . '/assets/js/device.js') ?: 0 ?>"></script>
<script>
(function () {
  var BASE = <?= json_encode(BASE_URL) ?>;
  var $ = function (id) { return document.getElementById(id); };
  var HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
  var BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
  var WARNA = ['#20b2a0', '#4a9be8', '#a855c8', '#f59f0b', '#e5484d', '#2fb344', '#1d6fd8', '#8b5cf6'];
  var C = 2 * Math.PI * 80;
  var uuid = getOrCreateDeviceUuid();
  var statusHp = null;      // hasil cek perangkat
  var sudahAda = null;

  /* ---------- jam ---------- */
  function tick() {
    var d = new Date(), p = function (n) { return String(n).padStart(2, '0'); };
    $('jam').textContent = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    $('tgl').textContent = HARI[d.getDay()] + ', ' + d.getDate() + ' ' + BULAN[d.getMonth()] + ' ' + d.getFullYear();
  }
  tick(); setInterval(tick, 1000);

  /* ---------- data kehadiran ---------- */
  function hash(s) { var h = 0; for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0; return h; }
  function pct(n, t) { return t ? Math.round(n / t * 100) : 0; }

  function gambarDonut(d) {
    var svg = $('svgDonut');
    svg.textContent = '';
    var seg = [
      { n: d.tepat, c: '#2fb344' }, { n: d.terlambat, c: '#f08c1a' },
      { n: d.tidak_hadir, c: '#e03e4a' }, { n: d.belum, c: '#cbd3e1' }
    ];
    var total = d.total || 0, mulai = 0, ns = 'http://www.w3.org/2000/svg';
    if (!total) seg = [{ n: 1, c: '#e9edf3' }], total = 1;
    seg.forEach(function (s) {
      if (s.n <= 0) return;
      var len = s.n / total * C;
      var c = document.createElementNS(ns, 'circle');
      c.setAttribute('cx', 100); c.setAttribute('cy', 100); c.setAttribute('r', 80);
      c.setAttribute('stroke', s.c);
      c.style.strokeDasharray = len + ' ' + (C - len);
      c.style.strokeDashoffset = -mulai;
      svg.appendChild(c);
      mulai += len;
    });
  }

  function gambarFeed(d) {
    var feed = $('feed'), posisi = feed.scrollTop;   // jaga posisi scroll agar tidak melompat tiap pembaruan (8 dtk)
    feed.textContent = '';
    feed.classList.toggle('kelas', d.mode === 'kelas');
    if (!d.terbaru.length) {
      var k = document.createElement('div'); k.className = 'kosong';
      k.textContent = d.efektif ? 'Belum ada siswa yang presensi hari ini.' : 'Hari ini bukan hari efektif presensi.';
      feed.appendChild(k);
    }
    var set = {};
    d.terbaru.forEach(function (x) {
      set[x.id] = true;
      var it = document.createElement('div');
      it.className = 'fi' + (sudahAda !== null && !sudahAda[x.id] ? ' baru' : '');
      var kata = x.nama.split(' ');
      var av = document.createElement('span'); av.className = 'av';
      av.style.background = WARNA[hash(x.nama) % WARNA.length];
      av.textContent = ((kata[0] || '')[0] || '') + ((kata[1] || '')[0] || '');
      var info = document.createElement('div');
      var n = document.createElement('div'); n.className = 'nm'; n.textContent = x.nama;
      var kl = document.createElement('div'); kl.className = 'kl'; kl.textContent = 'Kelas ' + x.kelas;
      info.appendChild(n); info.appendChild(kl);
      var wk = document.createElement('div'); wk.className = 'wk'; wk.textContent = x.jam;
      var sm = document.createElement('small'); sm.className = x.status === 'TERLAMBAT' ? 't' : 'h';
      sm.textContent = x.status === 'TERLAMBAT' ? 'Terlambat' : 'Tepat waktu';
      wk.appendChild(sm);
      it.appendChild(av); it.appendChild(info); it.appendChild(wk);
      feed.appendChild(it);
    });
    sudahAda = set;
    feed.scrollTop = posisi;
  }

  function render(d) {
    try { localStorage.setItem('siapBerandaV1', JSON.stringify({ hari: new Date().toDateString(), d: d })); } catch (e) {}
    $('persen').textContent = d.persen;
    var modeKelas = d.mode === 'kelas';
    $('jdlDonut').textContent = modeKelas ? 'Kehadiran Kelas ' + d.kelas : 'Kehadiran Hari Ini';
    $('jdlFeed').textContent = modeKelas ? 'Absensi Kelas ' + d.kelas : 'Absensi Terbaru';
    $('ketDonut').textContent = modeKelas ? 'hari ini, dari ' + d.total + ' siswa kelas ' + d.kelas : 'dari ' + d.total + ' siswa aktif';
    var cs = $('chipSaya');
    if (modeKelas && d.saya) {
      cs.style.display = '';
      cs.className = 'chip ' + (d.saya.status ? (d.saya.status === 'TERLAMBAT' ? 'warn' : 'ok') : '');
      cs.textContent = d.saya.nama + ' · ' + (d.saya.status ? 'sudah presensi ' + d.saya.jam + (d.saya.status === 'TERLAMBAT' ? ' (terlambat)' : '') : (d.saya.izin ? (d.saya.izin === 'SAKIT' ? 'sakit' : 'izin') + ' disetujui hari ini' : 'belum presensi hari ini'));
    } else { cs.style.display = 'none'; }
    [['Tepat', d.tepat], ['Terlambat', d.terlambat], ['Tidak', d.tidak_hadir], ['Belum', d.belum]].forEach(function (r) {
      $('n' + r[0]).textContent = r[1]; $('p' + r[0]).textContent = pct(r[1], d.total) + '%';
    });
    gambarDonut(d); gambarFeed(d); renderSiswa(d);
    var cb = $('chipBuka');
    var tutupJam = d.efektif && d.ditutup;
    cb.className = 'chip ' + (d.efektif && !tutupJam ? 'ok' : 'tutup');
    cb.textContent = !d.efektif ? 'Hari ini libur — presensi ditutup' : (tutupJam ? 'Absen masuk ditutup pukul ' + d.batas_absen : 'Presensi dibuka hari ini' + (d.batas_absen ? ' s.d. ' + d.batas_absen : ''));
    if (d.jam_masuk) { var cj = $('chipJam'); cj.style.display = ''; cj.textContent = 'Masuk ' + d.jam_masuk + (d.batas_terlambat ? ' · terlambat setelah ' + d.batas_terlambat : ''); }
    $('waktuUpdate').textContent = (modeKelas ? d.terbaru.length + ' siswa sudah presensi' + (d.terbaru.length > 5 ? ' · gulir untuk melihat semua' : '') + ' · ' : '') + 'Diperbarui ' + d.waktu;
    $('liveBadge').classList.remove('putus'); $('liveBadge').textContent = 'LIVE';
  }

  /* ---------- tampilan siswa (perangkat terdaftar & aktif) ---------- */
  var lokasiDicek = false;
  function renderSiswa(d) {
    var aktif = d.mode === 'kelas' && !!d.saya;
    document.body.classList.toggle('mode-siswa', aktif);
    if (!aktif) return;
    var s = d.saya, kata = String(s.nama || '').trim().split(/\s+/)[0] || '';
    $('apNama').textContent = kata.charAt(0).toUpperCase() + kata.slice(1).toLowerCase();
    if ($('apTopNama')) $('apTopNama').textContent = $('apNama').textContent || 'Siswa';
    var STATUS = {
      HADIR: ['Hadir', '#2fb344', 'check-lg'], TERLAMBAT: ['Hadir (Terlambat)', '#f08c1a', 'clock-history'],
      IZIN: ['Izin', '#e0a100', 'file-earmark-text-fill'], SAKIT: ['Izin (Sakit)', '#e0a100', 'file-earmark-medical-fill'],
      ALPA: ['Alfa', '#e03e4a', 'x-lg'], BELUM: ['Belum absen', '#8a94a6', 'hourglass-split']
    };
    var kunci = s.status || s.izin || 'BELUM', st = STATUS[kunci] || STATUS.BELUM;
    $('apStatus').style.setProperty('--c', st[1]);
    $('apStJudul').textContent = st[0];
    $('apStIkon').className = 'bi bi-' + st[2];
    $('apStMasuk').textContent = 'Masuk: ' + (s.jam && s.jam !== '00:00' ? s.jam + ' WIB' : '-');
    var sudah = !!(s.status && s.status !== 'ALPA');
    var b = $('apAbsen');
    b.disabled = sudah || !d.efektif;
    b.querySelector('span').textContent = sudah ? 'Sudah Absen Masuk' : (d.efektif ? 'Absen Masuk' : 'Presensi ditutup hari ini');
    if (s.bulan) { $('apHadir').textContent = s.bulan.hadir; $('apIzin').textContent = s.bulan.izin; $('apAlfa').textContent = s.bulan.alfa; if ($('apAlfaSub')) $('apAlfaSub').textContent = +s.bulan.alfa > 0 ? s.bulan.alfa + ' hari' : 'Tidak ada'; }
    if (!lokasiDicek) { lokasiDicek = true; cekLokasi(!!d.gps_aktif); }
  }
  function cekLokasi(wajib) {
    var el = $('apLok').querySelector('span');
    if (!navigator.geolocation) { el.textContent = wajib ? 'Lokasi tidak didukung perangkat' : 'Lokasi tidak diwajibkan'; return; }
    navigator.geolocation.getCurrentPosition(
      function () { el.textContent = 'Lokasi aktif terdeteksi'; },
      function () { el.textContent = wajib ? 'Aktifkan lokasi (GPS) untuk absen' : 'Lokasi tidak aktif'; },
      { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 }
    );
  }
  function jamSiswa() {
    var d = new Date();
    try { d = new Date(d.toLocaleString('en-US', { timeZone: 'Asia/Jakarta' })); } catch (e) {}
    var h = d.getHours(), m = d.getMinutes(), sd = d.getSeconds(), p = function (n) { return String(n).padStart(2, '0'); };
    $('apJam').textContent = p(h) + ':' + p(m);
    $('apTgl').textContent = HARI[d.getDay()] + ', ' + d.getDate() + ' ' + BULAN[d.getMonth()] + ' ' + d.getFullYear();
    if ($('apTgl2')) $('apTgl2').textContent = $('apTgl').textContent;
    $('apJarumJam').setAttribute('transform', 'rotate(' + ((h % 12) * 30 + m * 0.5) + ' 60 60)');
    $('apJarumMenit').setAttribute('transform', 'rotate(' + (m * 6 + sd * 0.1) + ' 60 60)');
    $('apDetik').textContent = ':' + p(sd);
    $('apJarumDetik').setAttribute('transform', 'rotate(' + (sd * 6) + ' 60 60)');
  }
  jamSiswa(); setInterval(jamSiswa, 1000);
  $('apAbsen').addEventListener('click', function () { $('btnScan').click(); });
  $('apNavScan').addEventListener('click', function () { $('btnScan').click(); });

  var memuat = false;
  function muat() {
    if (memuat || document.hidden) return;
    memuat = true;
    fetch(BASE + '/api/beranda.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ device_uuid: uuid }), cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) { if (d.success) render(d); })
      .catch(function () { $('liveBadge').classList.add('putus'); $('liveBadge').textContent = 'TERPUTUS'; })
      .then(function () { memuat = false; });
  }
  if (window.SIAP_SNAP) { try { render(window.SIAP_SNAP); } catch (e) {} }
  muat(); setInterval(muat, 8000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) muat(); });

  /* ---------- status perangkat ---------- */
  function cekPerangkat() {
    var el = $('statusHp');
    el.className = 'status-hp'; el.innerHTML = '<i class="bi bi-hourglass-split"></i> Memeriksa perangkat…';
    return fetch(BASE + '/api/cek_perangkat.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ device_uuid: uuid })
    }).then(function (r) { return r.json(); }).then(function (d) {
      statusHp = d;
      // Tombol Daftarkan Perangkat hanya tampil bila perangkat BELUM terdaftar
      $('btnDaftar').classList.toggle('hide', !(d.success && d.status === 'BELUM'));
      if (!d.success) { $('btnDaftar').classList.remove('hide'); el.className = 'status-hp no'; el.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Gagal memeriksa perangkat'; return; }
      if (d.status === 'TIDAK_WAJIB') { el.className = 'status-hp ok'; el.innerHTML = '<i class="bi bi-check-circle-fill"></i> Pendaftaran perangkat tidak diwajibkan'; }
      else if (d.status === 'AKTIF') { el.className = 'status-hp ok'; el.innerHTML = '<i class="bi bi-check-circle-fill"></i> Perangkat terdaftar'; }
      else if (d.status === 'DIBLOKIR') { el.className = 'status-hp blok'; el.innerHTML = '<i class="bi bi-slash-circle-fill"></i> Perangkat ini diblokir admin'; }
      else { el.className = 'status-hp no'; el.innerHTML = '<i class="bi bi-exclamation-circle-fill"></i> Perangkat belum terdaftar'; }
    }).catch(function () { statusHp = null; $('btnDaftar').classList.remove('hide'); el.className = 'status-hp no'; el.innerHTML = '<i class="bi bi-wifi-off"></i> Tidak dapat memeriksa perangkat'; });
  }
  cekPerangkat();

  /* ---------- dialog ---------- */
  function buka(id) { $(id).classList.add('buka'); }
  function tutup(id) { $(id).classList.remove('buka'); }
  ['mdBelum', 'mdDaftar'].forEach(function (id) {
    $(id).addEventListener('click', function (e) { if (e.target === $(id) && id === 'mdBelum') tutup(id); });
  });

  $('btnScan').addEventListener('click', function () {
    var go = function () {
      var d = statusHp;
      if (d && d.success && d.status === 'DIBLOKIR') {
        $('belumJudul').textContent = 'Perangkat Diblokir';
        $('belumIsi').textContent = 'Perangkat ini diblokir oleh admin. Hubungi admin sekolah untuk membukanya.';
        $('belumDaftar').classList.add('hide'); buka('mdBelum'); return;
      }
      if (d && d.success && !d.terdaftar) {
        $('belumJudul').textContent = 'Perangkat Belum Terdaftar';
        $('belumIsi').textContent = 'Untuk absensi, perangkat ini harus terlebih dahulu dihubungkan dengan akun siswa.';
        $('belumDaftar').classList.remove('hide'); buka('mdBelum'); return;
      }
      window.location.href = BASE + '/presensi/index.php';   // sudah terdaftar (atau tidak diwajibkan)
    };
    if (statusHp === null) cekPerangkat().then(go); else go();
  });
  $('belumBatal').addEventListener('click', function () { tutup('mdBelum'); });
  $('belumDaftar').addEventListener('click', function () { tutup('mdBelum'); bukaDaftar(); });
  $('btnDaftar').addEventListener('click', bukaDaftar);

  /* ---------- pendaftaran perangkat: arahkan ke aktivasi akun ---------- */
  function bukaDaftar() { buka('mdDaftar'); }
  $('dfTutup').addEventListener('click', function () { tutup('mdDaftar'); });
})();
</script>
</body>
</html>