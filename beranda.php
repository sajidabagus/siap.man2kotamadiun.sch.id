<?php
/**
 * HALAMAN DEPAN PUBLIK (tanpa login)
 * Ringkasan kehadiran hari ini (diagram lingkaran + persentase), histori absensi realtime,
 * tombol Scan Absen (mengecek apakah perangkat sudah terdaftar) dan pendaftaran perangkat
 * (kode pasangan atau scan QR pasangan). Dimuat oleh index.php bila belum login.
 */
$settings = get_settings();
$namaSekolah = $settings['nama_sekolah'] ?? 'Sekolah';
$logo = $settings['logo'] ?? '';
$logoUrl = ($logo !== '' && is_file(__DIR__ . '/uploads/siswa/' . $logo)) ? BASE_URL . '/uploads/siswa/' . rawurlencode($logo) : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($namaSekolah) ?> — Absensi Siswa</title>
<?= favicon_tag() ?>
<link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
<meta name="theme-color" content="#020617">
<script src="<?= BASE_URL ?>/assets/js/pwa.js?v=<?= @filemtime(__DIR__ . '/assets/js/pwa.js') ?: time() ?>" defer></script>
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap.min.css?v=<?= @filemtime(__DIR__ . '/assets/css/vendor/bootstrap.min.css') ?: time() ?>" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap-icons.min.css?v=<?= @filemtime(__DIR__ . '/assets/css/vendor/bootstrap-icons.min.css') ?: time() ?>" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?: time() ?>" rel="stylesheet">
<style>
  /* Override halaman publik beranda — pakai variabel desain SIAP (--siap-*) */
  body.siap-page.login-page { padding: 1rem 0.75rem; }
  body.siap-page { padding: 1rem 0.75rem; }

  .bd-shell { max-width: var(--siap-shell-narrow-max); margin: 0 auto; }

  .bd-topbar {
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap;
  }
  .bd-brand { display: flex; align-items: center; gap: 0.75rem; text-decoration: none; }
  .bd-brand .siap-tile { width: 2.75rem; height: 2.75rem; }
  .bd-brand-text .siap-h1 { margin: 0; font-size: 1.25rem; letter-spacing: -0.03em; }
  .bd-brand-text .siap-copy-sm { margin: 0; }

  .bd-btn-masuk {
    border: 1px solid var(--siap-line);
    background: #fff;
    color: var(--siap-ink);
    padding: 0.5rem 1rem;
    border-radius: var(--siap-radius-pill);
    font-size: 0.875rem;
    font-weight: 500;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
  }
  .bd-btn-masuk:hover { border-color: var(--siap-blue); color: var(--siap-blue); }

  .bd-hero {
    background: #fff;
    border: 1px solid var(--siap-line);
    border-radius: var(--siap-radius-window);
    box-shadow: var(--siap-shadow-card);
    padding: 1.5rem 1.25rem;
    margin-bottom: 1rem;
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: center;
    justify-content: space-between;
  }
  .bd-hero-info .siap-h1 { margin: 0 0 0.25rem; font-size: 2.25rem; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; }
  .bd-hero-info .siap-copy-sm { margin: 0 0 0.75rem; color: var(--siap-muted); }
  .bd-hero-info .bd-chips { display: flex; flex-wrap: wrap; gap: 0.5rem; }
  .bd-chip {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.375rem 0.875rem;
    border-radius: var(--siap-radius-pill);
    font-size: 0.75rem;
    font-weight: 600;
    border: 1px solid var(--siap-line);
    background: #fff;
    color: var(--siap-body);
  }
  .bd-chip.ok { background: var(--siap-success-bg); border-color: var(--siap-success-border); color: var(--siap-success-text); }
  .bd-chip.tutup { background: var(--siap-danger-bg); border-color: var(--siap-danger-border); color: var(--siap-danger-text); }

  .bd-aksi {
    display: flex; flex-direction: column; gap: 0.5rem;
    min-width: 220px; flex: 1 1 220px;
  }
  .bd-btn {
    display: inline-flex; align-items: center; justify-content: center;
    gap: 0.5rem; width: 100%;
    min-height: var(--siap-tap-target);
    border-radius: var(--siap-radius-pill);
    font-weight: 600;
    transition: all 0.2s;
  }
  .bd-btn-blue {
    background: linear-gradient(135deg, var(--siap-blue-start) 0%, var(--siap-blue) 45%, var(--siap-ink2) 100%);
    border: none; color: #fff;
    box-shadow: var(--siap-shadow-btn-blue);
  }
  .bd-btn-blue:hover { transform: translateY(-1px); box-shadow: 0 16px 32px -10px rgba(37, 99, 235, 0.65); }
  .bd-btn-ghost {
    border: 1px solid var(--siap-line);
    background: #fff; color: var(--siap-ink);
  }
  .bd-btn-ghost:hover { border-color: var(--siap-blue); color: var(--siap-blue); }

  .bd-grid {
    display: grid;
    grid-template-columns: 1fr 1.2fr;
    gap: 1rem;
  }
  @media (max-width: 768px) { .bd-grid { grid-template-columns: 1fr; } }

  .bd-card {
    background: #fff;
    border: 1px solid var(--siap-line);
    border-radius: var(--siap-radius-window);
    box-shadow: var(--siap-shadow-card);
    padding: 1.25rem 1rem;
  }
  .bd-eyebrow { display: block; margin-bottom: 0.25rem; }
  .bd-h2 { margin: 0 0 0.5rem; font-size: 1rem; letter-spacing: -0.02em; }
  .bd-ket { color: var(--siap-muted); font-size: 0.8125rem; margin-bottom: 1rem; }

  /* Donut */
  .bd-donut-box { display: flex; flex-direction: column; align-items: center; gap: 1rem; }
  .bd-donut { position: relative; width: 180px; height: 180px; }
  .bd-donut svg { width: 100%; height: 100%; transform: rotate(-90deg); }
  .bd-donut circle { fill: none; stroke-width: 20; transition: stroke-dasharray 0.8s ease, stroke-dashoffset 0.8s ease; }
  .bd-donut .tengah { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
  .bd-donut .persen { font-size: 2rem; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; color: var(--siap-ink); }
  .bd-donut .persen small { font-size: 1rem; font-weight: 700; }
  .bd-donut .lbl { color: var(--siap-muted); font-weight: 700; font-size: 0.75rem; letter-spacing: 0.08em; text-transform: uppercase; margin-top: 4px; }

  .bd-legend { width: 100%; display: grid; gap: 0.5rem; }
  .bd-lg { display: flex; align-items: center; gap: 0.75rem; font-size: 0.875rem; }
  .bd-lg .dot { width: 9px; height: 9px; border-radius: 50%; flex: none; }
  .bd-lg .n { margin-left: auto; font-weight: 800; font-variant-numeric: tabular-nums; }
  .bd-lg .p { width: 44px; text-align: right; color: var(--siap-muted); font-variant-numeric: tabular-nums; }

  /* Live badge */
  .bd-live {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.375rem 0.875rem;
    border-radius: var(--siap-radius-pill);
    background: var(--siap-success-bg);
    color: var(--siap-success-text);
    font-weight: 800;
    font-size: 0.7rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-left: auto;
  }
  .bd-live::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; animation: kedip 1.4s infinite; }
  .bd-live.putus { background: #f1f3f8; color: var(--siap-muted); }
  .bd-live.putus::before { animation: none; }
  @keyframes kedip { 0%,100% { opacity: 1; } 50% { opacity: 0.3; } }

  /* Feed */
  #bdFeed { max-height: 420px; overflow-y: auto; margin: 0 -1rem; scrollbar-width: none; }
  #bdFeed::-webkit-scrollbar { display: none; }
  #bdFeed.bd-kelas { scrollbar-width: thin; scrollbar-color: #cbd3e1 transparent; }
  #bdFeed.bd-kelas::-webkit-scrollbar { display: block; width: 6px; }
  #bdFeed.bd-kelas::-webkit-scrollbar-thumb { background: #cbd3e1; border-radius: 6px; }
  .bd-fi { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; border-bottom: 1px solid #f1f3f8; }
  .bd-fi:last-child { border-bottom: 0; }
  .bd-fi .av { width: 34px; height: 34px; border-radius: 50%; flex: none; display: grid; place-items: center; color: #fff; font-weight: 700; font-size: 0.75rem; }
  .bd-fi .nm { font-weight: 700; line-height: 1.2; color: var(--siap-ink); }
  .bd-fi .kl { color: var(--siap-muted); font-size: 0.75rem; }
  .bd-fi .wk { margin-left: auto; text-align: right; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--siap-ink); }
  .bd-fi .wk small { display: block; font-size: 0.7rem; font-weight: 700; }
  .bd-fi .wk .h { color: var(--siap-success-text); }
  .bd-fi .wk .t { color: var(--siap-warning-text); }
  .bd-fi.baru { animation: baru 3s ease-out; }
  @keyframes baru { 0% { background: var(--siap-success-bg); transform: translateY(-8px); opacity: 0; } 12% { opacity: 1; transform: none; } 100% { background: transparent; } }
  .bd-kosong { padding: 2rem 1rem; text-align: center; color: var(--siap-muted); }

  .bd-foot { text-align: center; color: var(--siap-muted); font-size: 0.75rem; letter-spacing: 0.08em; margin-top: 1.5rem; }

  /* Modal overlay */
  .bd-ov { position: fixed; inset: 0; z-index: 3000; background: rgba(15, 23, 42, 0.55); display: none; align-items: center; justify-content: center; padding: 1rem; }
  .bd-ov.buka { display: flex; }
  .bd-kotak { background: #fff; border-radius: var(--siap-radius-window); width: 100%; max-width: 440px; padding: 1.5rem; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35); max-height: 92vh; overflow-y: auto; animation: muncul 0.18s ease-out; }
  @keyframes muncul { from { opacity: 0; transform: translateY(10px) scale(0.98); } to { opacity: 1; transform: none; } }
  .bd-kotak h3 { font-size: 1.125rem; font-weight: 800; margin: 0 0 0.5rem; }
  .bd-kotak .siap-eyebrow { margin-bottom: 0.5rem; }
  .bd-tengah { text-align: center; }
  .bd-tengah .ik { font-size: 2.5rem; line-height: 1; margin-bottom: 0.5rem; }
  .bd-kotak p.siap-copy-sm { margin: 0.5rem 0 1rem; line-height: 1.55; color: var(--siap-body); }
  .bd-baris-tombol { display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap; }
  .bd-tab {
    display: grid; grid-template-columns: 1fr 1fr; gap: 0.375rem;
    background: #f1f3f8; padding: 0.375rem; border-radius: var(--siap-radius-mini); margin: 1rem 0 1rem;
  }
  .bd-tab button {
    border: 0; background: transparent; font: inherit; font-weight: 600;
    padding: 0.625rem; border-radius: var(--siap-radius-mini); cursor: pointer;
    color: var(--siap-muted); display: flex; align-items: center; justify-content: center; gap: 0.5rem;
  }
  .bd-tab button.aktif { background: #fff; color: var(--siap-ink2); box-shadow: 0 1px 4px rgba(15, 23, 42, 0.08); border-radius: var(--siap-radius-window); }
  .bd-field { margin-bottom: 1rem; position: relative; }
  .bd-field .siap-label { margin-bottom: 0.375rem; }
  .bd-field .siap-input { min-height: var(--siap-tap-target); padding: 0.625rem 1rem; }
  .bd-field.pw .siap-input { padding-right: 2.75rem; }
  .bd-eye {
    position: absolute; right: 0.5rem; top: 50%; transform: translateY(-50%);
    width: 2.25rem; height: 2.25rem; border: 0; border-radius: var(--siap-radius-pill);
    background: transparent; color: var(--siap-muted); font-size: 0.95rem; cursor: pointer;
    display: grid; place-items: center;
  }
  .bd-eye:hover { color: var(--siap-ink); background: rgba(59, 130, 246, 0.06); }
  .bd-eye:focus-visible { outline: 2px solid var(--siap-blue); outline-offset: 1px; }
  .bd-err { color: var(--siap-danger-text); font-size: 0.75rem; margin-top: 0.5rem; display: none; }
  .bd-err.tampil { display: block; }
  .bd-info {
    background: var(--siap-info-bg); border: 1px solid var(--siap-info-border);
    border-radius: 14px; color: var(--siap-info-text);
    padding: 0.75rem 1rem; font-size: 0.8125rem; line-height: 1.5; margin-top: 1rem; text-align: left;
  }
  #bdQrBox { width: 100%; min-height: 200px; border-radius: var(--siap-radius-mini); overflow: hidden; background: #0f172a; display: grid; place-items: center; color: var(--siap-faint); text-align: center; padding: 0.75rem; }
  #bdQrBox video { border-radius: var(--siap-radius-mini); }
  .bd-tutup-x {
    border: 0; background: #f1f3f8; width: 2.5rem; height: 2.5rem; border-radius: var(--siap-radius-mini);
    cursor: pointer; font-size: 1rem; color: var(--siap-muted); flex: none;
  }
  .bd-hide { display: none !important; }
  @media (max-width: 480px) { .siap-input { font-size: 16px; } }
  @media (prefers-reduced-motion: reduce) { .bd-card, .bd-btn-blue { transition: none !important; } }
</style>
</head>
<body class="siap-page">
  <div class="siap-glow-top"></div>

  <div class="bd-shell">
    <div class="bd-topbar">
      <a href="index.php" class="bd-brand">
        <div class="siap-tile"><i class="bi bi-qr-code-scan" aria-hidden="true"></i></div>
        <div class="bd-brand-text">
          <div class="siap-h1"><?= e($namaSekolah) ?></div>
          <div class="siap-copy-sm">Absensi Siswa</div>
        </div>
      </a>
      <a href="<?= BASE_URL ?>/login.php" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs"><i class="bi bi-box-arrow-in-right"></i> Masuk</a>
    </div>

    <div class="bd-hero">
      <div class="bd-hero-info">
        <div class="siap-h1" id="jam">--:--:--</div>
        <div class="siap-copy-sm" id="tgl">&nbsp;</div>
        <div class="bd-chips">
          <span class="bd-chip" id="chipBuka">memuat…</span>
          <span class="bd-chip" id="chipJam" style="display:none"></span>
          <span class="bd-chip" id="chipSaya" style="display:none"></span>
        </div>
      </div>
      <div class="bd-aksi">
        <button type="button" class="siap-btn-ios17 siap-btn-ios17-blue" id="btnScan" style="width:100%"><i class="bi bi-qr-code-scan"></i> Scan Absen</button>
        <div class="siap-copy-sm" id="statusHp" style="text-align:center">Memeriksa perangkat…</div>
        <button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost bd-hide" id="btnDaftar" style="width:100%"><i class="bi bi-phone"></i> Daftarkan Perangkat</button>
      </div>
    </div>

    <div class="bd-grid">
      <div class="bd-card">
        <span class="bd-eyebrow siap-eyebrow">Kehadiran Hari Ini</span>
        <h2 class="siap-h2 bd-h2" id="jdlDonut">Kehadiran Hari Ini</h2>
        <p class="siap-copy-sm bd-ket" id="ketDonut">dari seluruh siswa aktif</p>
        <div class="bd-donut-box">
          <div class="bd-donut">
            <svg viewBox="0 0 200 200" id="svgDonut">
              <circle cx="100" cy="100" r="80" stroke="#e9edf3" stroke-dasharray="502.65 502.65"/>
            </svg>
            <div class="tengah">
              <div class="persen"><span id="persen">0</span><small>%</small></div>
              <div class="lbl">Hadir</div>
            </div>
          </div>
          <div class="bd-legend">
            <div class="bd-lg"><span class="dot" style="background:var(--siap-success-text)"></span>Tepat waktu<span class="n" id="nTepat">0</span><span class="p" id="pTepat">0%</span></div>
            <div class="bd-lg"><span class="dot" style="background:var(--siap-warning-text)"></span>Terlambat<span class="n" id="nTerlambat">0</span><span class="p" id="pTerlambat">0%</span></div>
            <div class="bd-lg"><span class="dot" style="background:var(--siap-danger-text)"></span>Izin / Sakit / Alpa<span class="n" id="nTidak">0</span><span class="p" id="pTidak">0%</span></div>
            <div class="bd-lg"><span class="dot" style="background:#cbd3e1"></span>Belum presensi<span class="n" id="nBelum">0</span><span class="p" id="pBelum">0%</span></div>
          </div>
        </div>
      </div>

      <div class="bd-card">
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.25rem">
          <h2 class="siap-h2 bd-h2" id="jdlFeed" style="margin:0">Absensi Terbaru</h2>
          <span class="bd-live" id="liveBadge">LIVE</span>
        </div>
        <p class="siap-copy-sm bd-ket" id="waktuUpdate">memuat…</p>
        <div id="bdFeed"><div class="bd-kosong">Memuat data…</div></div>
      </div>
    </div>

    <div class="bd-foot">SIAP ABSEN &copy; <?= date('Y') ?></div>
  </div>

<!-- Dialog: Perangkat belum terdaftar -->
<div class="bd-ov" id="mdBelum" role="dialog" aria-modal="true">
  <div class="bd-kotak bd-tengah">
    <div class="ik"><i class="bi bi-phone"></i></div>
    <h3 id="belumJudul">Perangkat Belum Terdaftar</h3>
    <p class="siap-copy-sm" id="belumIsi">Untuk absensi, perangkat ini harus terlebih dahulu dihubungkan dengan akun siswa.</p>
    <div class="bd-baris-tombol">
      <button type="button" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs" id="belumDaftar"><i class="bi bi-phone"></i> Daftarkan Perangkat</button>
      <button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" id="belumBatal">Batal</button>
    </div>
  </div>
</div>

<!-- Dialog: pendaftaran perangkat -->
<div class="bd-ov" id="mdDaftar" role="dialog" aria-modal="true" aria-labelledby="dfJudul">
  <div class="bd-kotak">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.75rem">
      <div>
        <div class="siap-eyebrow">Pendaftaran Perangkat</div>
        <h3 id="dfJudul">Daftarkan Perangkat</h3>
      </div>
      <button type="button" class="bd-tutup-x" id="dfTutup" aria-label="Tutup"><i class="bi bi-x-lg"></i></button>
    </div>

    <div id="dfForm">
      <div class="bd-tab" role="tablist">
        <button type="button" id="tabKode" class="aktif"><i class="bi bi-123"></i> Pakai Kode</button>
        <button type="button" id="tabQr"><i class="bi bi-qr-code"></i> Pakai QR</button>
      </div>

      <div id="paneKode">
        <p class="siap-copy-sm" style="margin:0 0 0.5rem">Minta admin membuat <strong>Kode Pasangan 6 angka</strong>. Berlaku 10 menit dan hanya bisa dipakai sekali.</p>
        <div class="bd-field">
          <label for="inNis" class="siap-label">NIS / NISN</label>
          <input class="siap-input" id="inNis" inputmode="numeric" autocomplete="off" placeholder="Nomor induk siswa">
        </div>
        <div class="bd-field pw">
          <label for="inKode" class="siap-label">Kode Pasangan</label>
          <input class="siap-input kode" id="inKode" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="••••••">
          <button type="button" class="bd-eye" id="toggleKode" aria-label="Tampilkan kode"><i class="bi bi-eye-slash-fill"></i></button>
        </div>
        <div class="bd-err" id="errKode"></div>
        <button type="button" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs" id="btnKirim" style="width:100%;margin-top:1rem" disabled><i class="bi bi-phone"></i> Daftarkan Perangkat Ini</button>
      </div>

      <div id="paneQr" class="bd-hide">
        <p class="siap-copy-sm" style="margin:0 0 0.75rem">Arahkan kamera ke <strong>QR Pasangan</strong> yang ditampilkan admin.</p>
        <div id="bdQrBox"><div><i class="bi bi-camera" style="font-size:2rem"></i><div style="margin-top:0.5rem">Kamera belum aktif</div></div></div>
        <div class="bd-err" id="errQr"></div>
        <button type="button" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs" id="btnKamera" style="width:100%;margin-top:0.75rem"><i class="bi bi-camera-video"></i> Aktifkan Kamera</button>
        <div class="bd-info">Bisa juga memindai QR Pasangan dengan <strong>kamera bawaan perangkat</strong>, lalu ketuk <em>Daftarkan Perangkat Ini</em>.</div>
      </div>
    </div>

    <div id="dfOk" class="bd-tengah bd-hide">
      <div class="ik" style="color:var(--siap-success-text)"><i class="bi bi-check-circle-fill"></i></div>
      <h3>Perangkat Berhasil Didaftarkan</h3>
      <p style="font-weight:800;margin-top:0.5rem" id="okNama"></p>
      <p style="color:var(--siap-muted);font-size:0.875rem" id="okKelas"></p>
      <div class="bd-info">Sekarang tekan <strong>Scan Absen</strong> untuk presensi. Gunakan <strong>browser yang sama</strong> dengan yang Anda pakai sekarang.</div>
      <button type="button" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs" id="dfSelesai" style="width:100%;margin-top:1rem">Selesai</button>
    </div>
  </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/vendor/html5-qrcode.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/device.js?v=<?= @filemtime(__DIR__ . '/assets/js/device.js') ?: time() ?>"></script>
<script>
(function () {
  var BASE = <?= json_encode(BASE_URL) ?>;
  var $ = function (id) { return document.getElementById(id); };
  var HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
  var BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
  var WARNA = ['#20b2a0', '#4a9be8', '#a855c8', '#f59f0b', '#e5484d', '#2fb344', '#1d6fd8', '#8b5cf6'];
  var C = 2 * Math.PI * 80;
  var uuid = getOrCreateDeviceUuid();
  var statusHp = null;
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
      { n: d.tepat, c: 'var(--siap-success-text)' }, { n: d.terlambat, c: 'var(--siap-warning-text)' },
      { n: d.tidak_hadir, c: 'var(--siap-danger-text)' }, { n: d.belum, c: '#cbd3e1' }
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
    var feed = $('bdFeed'), posisi = feed.scrollTop;
    feed.textContent = '';
    feed.classList.toggle('bd-kelas', d.mode === 'kelas');
    if (!d.terbaru.length) {
      var k = document.createElement('div'); k.className = 'bd-kosong';
      k.textContent = d.efektif ? 'Belum ada siswa yang presensi hari ini.' : 'Hari ini bukan hari efektif presensi.';
      feed.appendChild(k);
    }
    var set = {};
    d.terbaru.forEach(function (x) {
      set[x.id] = true;
      var it = document.createElement('div');
      it.className = 'bd-fi' + (sudahAda !== null && !sudahAda[x.id] ? ' baru' : '');
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
    $('persen').textContent = d.persen;
    var modeKelas = d.mode === 'kelas';
    $('jdlDonut').textContent = modeKelas ? 'Kehadiran Kelas ' + d.kelas : 'Kehadiran Hari Ini';
    $('jdlFeed').textContent = modeKelas ? 'Absensi Kelas ' + d.kelas : 'Absensi Terbaru';
    $('ketDonut').textContent = modeKelas ? 'hari ini, dari ' + d.total + ' siswa kelas ' + d.kelas : 'dari ' + d.total + ' siswa aktif';
    var cs = $('chipSaya');
    if (modeKelas && d.saya) {
      cs.style.display = '';
      cs.className = 'bd-chip ' + (d.saya.status ? (d.saya.status === 'TERLAMBAT' ? 'tutup' : 'ok') : '');
      cs.textContent = d.saya.nama + ' · ' + (d.saya.status ? 'sudah presensi ' + d.saya.jam + (d.saya.status === 'TERLAMBAT' ? ' (terlambat)' : '') : 'belum presensi hari ini');
    } else { cs.style.display = 'none'; }
    [['Tepat', d.tepat], ['Terlambat', d.terlambat], ['Tidak', d.tidak_hadir], ['Belum', d.belum]].forEach(function (r) {
      $('n' + r[0]).textContent = r[1]; $('p' + r[0]).textContent = pct(r[1], d.total) + '%';
    });
    gambarDonut(d); gambarFeed(d);
    var cb = $('chipBuka');
    cb.className = 'bd-chip ' + (d.efektif ? 'ok' : 'tutup');
    cb.textContent = d.efektif ? 'Presensi dibuka hari ini' : 'Hari ini libur — presensi ditutup';
    if (d.jam_masuk) { var cj = $('chipJam'); cj.style.display = ''; cj.textContent = 'Masuk ' + d.jam_masuk + (d.batas_terlambat ? ' · terlambat setelah ' + d.batas_terlambat : ''); }
    $('waktuUpdate').textContent = (modeKelas ? d.terbaru.length + ' siswa sudah presensi' + (d.terbaru.length > 5 ? ' · gulir untuk melihat semua' : '') + ' · ' : '') + 'Diperbarui ' + d.waktu;
    $('liveBadge').classList.remove('putus'); $('liveBadge').textContent = 'LIVE';
  }

  var memuat = false;
  function muat() {
    if (memuat || document.hidden) return;
    memuat = true;
    fetch(BASE + '/api/beranda.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ device_uuid: uuid, device_secret: (typeof getOrCreateDeviceSecret === 'function') ? getOrCreateDeviceSecret() : '' }), cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) { if (d.success) render(d); })
      .catch(function () { $('liveBadge').classList.add('putus'); $('liveBadge').textContent = 'TERPUTUS'; })
      .then(function () { memuat = false; });
  }
  muat(); setInterval(muat, 8000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) muat(); });

  /* ---------- status perangkat ---------- */
  function cekPerangkat() {
    var el = $('statusHp');
    el.className = 'siap-copy-sm'; el.innerHTML = '<i class="bi bi-hourglass-split"></i> Memeriksa perangkat…';
    return fetch(BASE + '/api/cek_perangkat.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ device_uuid: uuid, device_secret: (typeof getOrCreateDeviceSecret === 'function') ? getOrCreateDeviceSecret() : '' })
    }).then(function (r) { return r.json(); }).then(function (d) {
      statusHp = d;
      $('btnDaftar').classList.toggle('bd-hide', !(d.success && d.status === 'BELUM'));
      if (!d.success) { $('btnDaftar').classList.remove('bd-hide'); el.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Gagal memeriksa perangkat'; return; }
      if (d.status === 'TIDAK_WAJIB') { el.innerHTML = '<i class="bi bi-check-circle-fill"></i> Pendaftaran perangkat tidak diwajibkan'; }
      else if (d.status === 'AKTIF') { el.innerHTML = '<i class="bi bi-check-circle-fill"></i> Perangkat terdaftar'; }
      else if (d.status === 'DIBLOKIR') { el.innerHTML = '<i class="bi bi-slash-circle-fill"></i> Perangkat ini diblokir admin'; }
      else { el.innerHTML = '<i class="bi bi-exclamation-circle-fill"></i> Perangkat belum terdaftar'; }
    }).catch(function () { statusHp = null; $('btnDaftar').classList.remove('bd-hide'); el.innerHTML = '<i class="bi bi-wifi-off"></i> Tidak dapat memeriksa perangkat'; });
  }
  cekPerangkat();

  /* ---------- dialog ---------- */
  function buka(id) { $(id).classList.add('buka'); }
  function tutup(id) { $(id).classList.remove('buka'); }
  ['mdBelum', 'mdDaftar'].forEach(function (id) {
    $(id).addEventListener('click', function (e) { if (e.target === $(id)) tutup(id); });
  });

  $('btnScan').addEventListener('click', function () {
    var go = function () {
      var d = statusHp;
      if (d && d.success && d.status === 'DIBLOKIR') {
        $('belumJudul').textContent = 'Perangkat Diblokir';
        $('belumIsi').textContent = 'Perangkat ini diblokir oleh admin. Hubungi admin sekolah untuk membukanya.';
        $('belumDaftar').classList.add('bd-hide'); buka('mdBelum'); return;
      }
      if (d && d.success && !d.terdaftar) {
        $('belumJudul').textContent = 'Perangkat Belum Terdaftar';
        $('belumIsi').textContent = 'Untuk absensi, perangkat ini harus terlebih dahulu dihubungkan dengan akun siswa.';
        $('belumDaftar').classList.remove('bd-hide'); buka('mdBelum'); return;
      }
      window.location.href = BASE + '/presensi/index.php';
    };
    if (statusHp === null) cekPerangkat().then(go); else go();
  });
  $('belumBatal').addEventListener('click', function () { tutup('mdBelum'); });
  $('belumDaftar').addEventListener('click', function () { tutup('mdBelum'); bukaDaftar(); });
  $('btnDaftar').addEventListener('click', bukaDaftar);

  /* ---------- pendaftaran perangkat ---------- */
  var scanner = null;
  function bukaDaftar() {
    $('dfForm').classList.remove('bd-hide'); $('dfOk').classList.add('bd-hide');
    pilihTab('kode'); buka('mdDaftar');
    setTimeout(function () { $('inNis').focus(); }, 150);
  }
  function hentikanKamera() {
    if (scanner) { var s = scanner; scanner = null; s.stop().then(function () { s.clear(); }).catch(function () {}); }
    $('bdQrBox').innerHTML = '<div><i class="bi bi-camera" style="font-size:2rem"></i><div style="margin-top:0.5rem">Kamera belum aktif</div></div>';
    $('btnKamera').classList.remove('bd-hide');
  }
  function tutupDaftar() { hentikanKamera(); tutup('mdDaftar'); }
  $('dfTutup').addEventListener('click', tutupDaftar);
  $('dfSelesai').addEventListener('click', function () { tutupDaftar(); cekPerangkat().then(function () { sudahAda = null; muat(); }); });

  function pilihTab(t) {
    var kode = t === 'kode';
    $('tabKode').classList.toggle('aktif', kode); $('tabQr').classList.toggle('aktif', !kode);
    $('paneKode').classList.toggle('bd-hide', !kode); $('paneQr').classList.toggle('bd-hide', kode);
    if (kode) hentikanKamera();
  }
  $('tabKode').addEventListener('click', function () { pilihTab('kode'); });
  $('tabQr').addEventListener('click', function () { pilihTab('qr'); });

  function berhasil(res) {
    hentikanKamera();
    $('okNama').textContent = (res.data && res.data.nama) || '';
    $('okKelas').textContent = (res.data && res.data.kelas) ? 'Kelas ' + res.data.kelas : '';
    $('dfForm').classList.add('bd-hide'); $('dfOk').classList.remove('bd-hide');
  }
  function kirim(body, errEl, tombol, labelAwal) {
    body.device_uuid = uuid;
    body.device_secret = (typeof getOrCreateDeviceSecret === 'function') ? getOrCreateDeviceSecret() : undefined;
    errEl.classList.remove('tampil');
    return fetch(BASE + '/api/pasangan.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) { berhasil(res); return true; }
        errEl.textContent = res.message || 'Gagal mendaftarkan perangkat.'; errEl.classList.add('tampil'); return false;
      })
      .catch(function () { errEl.textContent = 'Gagal terhubung ke server. Coba lagi.'; errEl.classList.add('tampil'); return false; });
  }

  // -- jalur kode --
  var inNis = $('inNis'), inKode = $('inKode'), btnKirim = $('btnKirim');
  function cekForm() { btnKirim.disabled = !(inNis.value.trim().length >= 4 && inKode.value.length === 6); $('errKode').classList.remove('tampil'); }
  inNis.addEventListener('input', cekForm);
  inKode.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); cekForm(); });
  btnKirim.addEventListener('click', function () {
    var label = btnKirim.innerHTML;
    btnKirim.disabled = true; btnKirim.textContent = 'Memeriksa…';
    kirim({ nis: inNis.value.trim(), kode: inKode.value }, $('errKode')).then(function (ok) {
      if (!ok) { btnKirim.innerHTML = label; cekForm(); }
    });
  });

  // toggle password/kode visibility
  var tgKode = document.getElementById('toggleKode');
  if (tgKode && inKode) {
    tgKode.addEventListener('click', function () {
      var show = inKode.type === 'password';
      inKode.type = show ? 'text' : 'password';
      tgKode.innerHTML = '<i class="bi bi-eye' + (show ? '-fill' : '-slash-fill') + '"></i>';
      tgKode.setAttribute('aria-label', show ? 'Sembunyikan kode' : 'Tampilkan kode');
      inKode.focus();
    });
  }

  // -- jalur QR (kamera di halaman ini) --
  function ambilToken(teks) {
    var m = String(teks).match(/[?&]t=([0-9a-f]{32})/i) || String(teks).match(/\b([0-9a-f]{32})\b/i);
    return m ? m[1].toLowerCase() : null;
  }
  $('btnKamera').addEventListener('click', function () {
    var err = $('errQr'); err.classList.remove('tampil');
    if (typeof Html5Qrcode === 'undefined') { err.textContent = 'Pemindai QR tidak tersedia. Gunakan kamera bawaan perangkat atau kode.'; err.classList.add('tampil'); return; }
    $('bdQrBox').innerHTML = '';
    $('btnKamera').classList.add('bd-hide');
    scanner = new Html5Qrcode('bdQrBox');
    var selesai = false;
    scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 220, height: 220 } }, function (teks) {
      if (selesai) return;
      var token = ambilToken(teks);
      if (!token) { err.textContent = 'QR ini bukan QR Pasangan. Gunakan QR dari admin.'; err.classList.add('tampil'); return; }
      selesai = true;
      kirim({ token: token }, err).then(function (ok) { if (!ok) { selesai = false; hentikanKamera(); } });
    }, function () {}).catch(function () {
      scanner = null;
      $('bdQrBox').innerHTML = '<div><i class="bi bi-camera-video-off" style="font-size:2rem"></i><div style="margin-top:0.5rem">Kamera tidak dapat diakses</div></div>';
      $('btnKamera').classList.remove('bd-hide');
      err.textContent = 'Izinkan akses kamera pada browser, atau pakai kode / kamera bawaan perangkat.'; err.classList.add('tampil');
    });
  });
})();
</script>
</body>
</html>