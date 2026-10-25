<?php

/**
 * Kerangka DESKTOP (>= 1024px) tampilan siswa: sidebar kiri putih (logo, menu, motto) + topbar (lonceng, profil).
 * Hanya tampil di layar lebar lewat assets/css/siswa-desktop.css; di HP/tablet elemen ini disembunyikan
 * (menu bawah .ap-nav tetap dipakai). Tombol Keluar tetap di halaman Profil (perlu POST + CSRF). Dipakai oleh portal siswa, beranda perangkat terdaftar, dan halaman Scan (presensi).
 */

function siswa_desktop_logo(): ?string
{
  $logo = (string) (get_settings()['logo'] ?? '');
  return ($logo !== '' && is_file(__DIR__ . '/../uploads/siswa/' . $logo)) ? BASE_URL . '/uploads/siswa/' . rawurlencode($logo) : null;
}

/** $aktif: beranda|riwayat|izin|profil|scan. $homeUrl: alamat Home (beda untuk perangkat terdaftar). */
function siswa_desktop_sidebar(string $aktif, string $homeUrl, int $notif = 0): void
{
  $s = get_settings();
  $logo = siswa_desktop_logo();
  $menu = [
    'beranda' => [$homeUrl, 'house-door-fill', 'Home'],
    'riwayat' => [BASE_URL . '/portal/riwayat', 'journal-text', 'Riwayat'],
    'izin'    => [BASE_URL . '/portal/izin', 'file-earmark-text', 'Izin'],
    'profil'  => [BASE_URL . '/portal/profil', 'person', 'Profile'],
    'scan'    => [BASE_URL . '/presensi/', 'qr-code-scan', 'Scan QR Absen'],
  ];
?>
  <aside class="sd-side" aria-label="Menu siswa (desktop)">
    <div class="sd-brand">
      <span class="sd-logo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><i class="bi bi-mortarboard-fill"></i><?php endif; ?></span>
      <span><b><?= e((string) ($s['nama_sekolah'] ?? 'Sekolah')) ?></b><small>Sistem Informasi Siswa</small></span>
    </div>
    <nav class="sd-menu">
      <?php $scan = $GLOBALS['PORTAL_SCAN'] ?? ['aktif' => true, 'alasan' => '']; ?>
      <?php foreach ($menu as $k => [$url, $ikon, $label]): ?>
        <?php if ($k === 'scan' && !$scan['aktif'] && $aktif !== 'scan'): ?>
          <a class="mati" role="button" aria-disabled="true" tabindex="-1" title="<?= e($scan['alasan']) ?>"><i class="bi bi-<?= $ikon ?>"></i><span><?= $label ?></span></a>
        <?php continue;
        endif; ?>
        <a href="<?= e($url) ?>" <?= $aktif === $k ? ' class="on" aria-current="page"' : '' ?>><i class="bi bi-<?= $ikon ?>"></i><span><?= $label ?></span><?php if ($k === 'izin' && $notif > 0): ?><b class="sd-badge"><?= $notif > 9 ? '9+' : $notif ?></b><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="sd-motto"><i class="bi bi-mortarboard"></i>
      <p>Berakhlak Mulia, Terampil, Berprestasi, dan Berbudaya Lingkungan</p>
    </div>
  </aside>
<?php
}

/** Topbar kanan atas: lonceng (titik merah bila ada notifikasi baru) + pill profil dengan menu turun. $idNama: id span nama (diisi JS). */
function siswa_desktop_topbar(string $nama, ?string $foto, int $notif = 0, string $idNama = ''): void
{
  $kata = preg_split('/\s+/', trim($nama)) ?: [];
  $depan = $nama === '' ? '' : mb_convert_case(mb_strtolower((string) ($kata[0] ?? '')), MB_CASE_TITLE, 'UTF-8');
?>
  <div class="sd-top">
    <a class="sd-bell" href="<?= e(BASE_URL) ?>/portal/notifikasi" aria-label="Notifikasi<?= $notif > 0 ? ', ' . $notif . ' baru' : '' ?>"><i class="bi bi-bell"></i><?php if ($notif > 0): ?><b></b><?php endif; ?></a>
    <details class="sd-prof">
      <summary><span class="sd-av"><?php if ($foto): ?><img src="<?= e($foto) ?>" alt=""><?php else: ?><i class="bi bi-person-fill"></i><?php endif; ?></span>
        <span<?= $idNama !== '' ? ' id="' . e($idNama) . '"' : '' ?>><?= e($depan !== '' ? $depan : 'Siswa') ?></span><i class="bi bi-chevron-down"></i>
      </summary>
      <div class="sd-drop">
        <a href="<?= e(BASE_URL) ?>/portal/profil"><i class="bi bi-person"></i> Profil</a>
        <a href="<?= e(BASE_URL) ?>/portal/password"><i class="bi bi-key"></i> Ganti Password</a>
        <a href="<?= e(BASE_URL) ?>/portal/notifikasi"><i class="bi bi-bell"></i> Notifikasi</a>
      </div>
    </details>
  </div>
<?php
}
