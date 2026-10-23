<?php
/**
 * Akun Siswa — kelola akun portal siswa (username = NISN).
 * Admin TIDAK membuat password: admin hanya menerbitkan KODE AKTIVASI sekali pakai; siswa
 * menetapkan password sendiri di /portal/aktivasi. Kode hanya tampil SEKALI (yang tersimpan
 * hanya hash-nya), jadi cetak/unduh langsung setelah diterbitkan.
 */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/portal.php';

$db = getDB();
$bolehKelola = can('akun_siswa.kelola');
$adminId = (int) (current_admin()['id'] ?? 0);

if (!portal_siapkan_tabel()) {
    echo '<div class="alert alert-danger">Tabel akun siswa belum bisa dibuat otomatis. Jalankan <code>database/migration_akun_siswa.sql</code> di phpMyAdmin.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    return;
}

/** Penanda sekali pakai: mencegah kode diterbitkan ulang tanpa sengaja saat halaman hasil di-refresh (F5). */
function akun_nonce(): string
{
    if (empty($_SESSION['akun_nonce'])) $_SESSION['akun_nonce'] = bin2hex(random_bytes(12));
    return $_SESSION['akun_nonce'];
}
function akun_nonce_pakai(string $n): bool
{
    $ok = !empty($_SESSION['akun_nonce']) && hash_equals((string) $_SESSION['akun_nonce'], $n);
    unset($_SESSION['akun_nonce']);
    return $ok;
}

/** URL daftar (dengan filter terakhir) untuk kembali setelah aksi. Dibangun ulang dari daftar putih. */
function akun_url_kembali(): string
{
    parse_str((string) ($_POST['kembali'] ?? ''), $kb);
    $boleh = [];
    foreach (['q', 'kelas', 'status', 'limit', 'page'] as $k) if (isset($kb[$k]) && is_scalar($kb[$k])) $boleh[$k] = (string) $kb[$k];
    return modul_url('akun_siswa', $boleh);
}

/**
 * Terbitkan kode untuk banyak siswa. Akun yang sudah AKTIF tidak disentuh (reset hanya lewat
 * tombol Reset per siswa) agar kode massal tak tak sengaja menghapus password siswa.
 */
function akun_terbitkan_banyak(array $ids, int $adminId): array
{
    @set_time_limit(180);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $ids = array_slice($ids, 0, 1000);
    $berhasil = []; $dilewati = [];
    if (!$ids) return [$berhasil, $dilewati];

    $in = implode(',', $ids);                // sudah berupa bilangan bulat
    $rows = getDB()->query("SELECT s.id, s.nama, s.kelas, s.nisn, a.status AS akun_status
                            FROM siswa s LEFT JOIN siswa_akun a ON a.siswa_id = s.id
                            WHERE s.id IN ($in) ORDER BY s.kelas, s.nama")->fetchAll();
    foreach ($rows as $r) {
        if ($r['akun_status'] === 'aktif') { $dilewati[] = $r + ['alasan' => 'Sudah aktif (pakai tombol Reset bila lupa password)']; continue; }
        $h = portal_terbitkan_kode((int) $r['id'], $adminId);
        if ($h['ok']) $berhasil[] = $r + ['kode' => $h['kode_tampil'], 'berlaku' => $h['berlaku']];
        else $dilewati[] = $r + ['alasan' => $h['alasan']];
    }
    return [$berhasil, $dilewati];
}

$hasil = null;      // diisi bila ada kode yang baru diterbitkan (ditampilkan sekali)

// ---------------------------------------------------------------------
// AKSI (POST)
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$bolehKelola) { set_flash('danger', 'Anda tidak memiliki izin untuk aksi ini.'); header('Location: ' . akun_url_kembali()); exit; }
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { set_flash('danger', 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.'); header('Location: ' . akun_url_kembali()); exit; }

    $aksi = (string) ($_POST['aksi'] ?? '');
    $sid  = (int) ($_POST['id'] ?? 0);

    if (in_array($aksi, ['kode_satu', 'reset_satu', 'kode_terpilih', 'kode_kelas'], true)) {
        if (!akun_nonce_pakai((string) ($_POST['nonce'] ?? ''))) {
            set_flash('warning', 'Permintaan ini sudah diproses atau halaman sudah kedaluwarsa. Kode lama tidak diubah. Muat ulang halaman bila perlu membuat kode lagi.');
            header('Location: ' . akun_url_kembali()); exit;
        }

        if ($aksi === 'reset_satu') {
            $h = portal_terbitkan_kode($sid, $adminId);
            if ($h['ok']) {
                // RESET = semuanya hilang: password, sesi (lewat portal_terbitkan_kode) DAN perangkat terdaftar.
                // Siswa aktivasi ulang dengan kode ini; perangkat terdaftar lagi saat aktivasi di aplikasi SIAP.
                $perangkatDilepas = lepas_perangkat_reset_akun($sid, $adminId);
                $s = $db->prepare('SELECT id, nama, kelas, nisn FROM siswa WHERE id = :id'); $s->execute([':id' => $sid]);
                $berhasil = [$s->fetch() + ['kode' => $h['kode_tampil'], 'berlaku' => $h['berlaku']]]; $dilewati = [];
                catat_aktivitas('RESET', 'akun_siswa', 'Reset akun portal, perangkat' . ($perangkatDilepas ? ' dilepas' : ' (tidak ada)') . ' & kode baru: ' . nama_siswa_log($sid));
            } else { $berhasil = []; $dilewati = [['nama' => nama_siswa_log($sid), 'kelas' => '', 'alasan' => $h['alasan']]]; }
        } else {
            if ($aksi === 'kode_satu') $ids = [$sid];
            elseif ($aksi === 'kode_terpilih') $ids = (array) ($_POST['ids'] ?? []);
            else {
                $k = clean($_POST['kelas'] ?? '');
                $sql = "SELECT s.id FROM siswa s LEFT JOIN siswa_akun a ON a.siswa_id = s.id
                        WHERE s.status = 'aktif' AND (a.id IS NULL OR a.status = 'menunggu')" . ($k !== '' ? ' AND s.kelas = :k' : '') . ' ORDER BY s.kelas, s.nama';
                $st = $db->prepare($sql); $st->execute($k !== '' ? [':k' => $k] : []);
                $ids = $st->fetchAll(PDO::FETCH_COLUMN);
            }
            [$berhasil, $dilewati] = akun_terbitkan_banyak($ids, $adminId);
            if ($berhasil) {
                $ket = count($berhasil) . ' siswa' . ($aksi === 'kode_kelas' ? ' (' . (clean($_POST['kelas'] ?? '') ?: 'seluruh sekolah') . ')' : '');
                catat_aktivitas('GENERATE', 'akun_siswa', 'Menerbitkan kode aktivasi akun portal: ' . $ket);
            }
        }
        $hasil = ['berhasil' => $berhasil, 'dilewati' => $dilewati];

    } elseif ($aksi === 'ganti_hp') {
        // Siswa ganti HP: HANYA mereset perangkat terdaftar (kuota bulanan berlaku). Akun AKTIF tidak diubah sama
        // sekali (password, status, sesi tetap); siswa mendaftarkan HP baru dari portal (tombol "Daftarkan perangkat
        // ini"). Hanya akun yang MASIH MENUNGGU yang sekalian mendapat kode aktivasi baru (belum ada yang hilang).
        // Menghapus akun + perangkat sekaligus adalah tugas tombol "Reset".
        $st = $db->prepare('SELECT status FROM siswa_akun WHERE siswa_id = :id'); $st->execute([':id' => $sid]);
        $statusAkun = (string) ($st->fetchColumn() ?: '');
        $dev = get_device_binding($sid);
        $perluKode = $statusAkun === 'menunggu';   // akun AKTIF tidak pernah disentuh oleh Ganti HP
        if ($statusAkun === '' || $statusAkun === 'nonaktif') {
            set_flash('danger', 'Akun tidak ditemukan atau sedang diblokir.'); header('Location: ' . akun_url_kembali()); exit;
        }
        if (!$dev || $dev['status'] === 'BLOCKED') {
            set_flash('warning', 'Tidak ada perangkat aktif yang bisa direset untuk siswa ini.'); header('Location: ' . akun_url_kembali()); exit;
        }
        if ($perluKode && !akun_nonce_pakai((string) ($_POST['nonce'] ?? ''))) {
            set_flash('warning', 'Permintaan ini sudah diproses atau halaman sudah kedaluwarsa. Muat ulang halaman bila perlu membuat kode lagi.');
            header('Location: ' . akun_url_kembali()); exit;
        }
        $r = reset_device($sid, $adminId, 'Ganti HP (via Akun Siswa)');
        if (!$r['success']) { set_flash('danger', $r['message']); header('Location: ' . akun_url_kembali()); exit; }

        if (!$perluKode) {
            set_flash('success', 'Perangkat lama direset. Akun dan password siswa TIDAK berubah dan tetap aktif. Klik "Kode perangkat" untuk menerbitkan kode pendaftaran HP baru (wajib bila pendaftaran mandiri dimatikan), lalu minta siswa masuk ke portal dari aplikasi SIAP di HP barunya dan pilih "Daftarkan perangkat ini".');
            header('Location: ' . akun_url_kembali()); exit;
        }
        $h = portal_terbitkan_kode($sid, $adminId);
        if (!$h['ok']) { set_flash('warning', 'Perangkat direset, tetapi kode gagal diterbitkan: ' . $h['alasan']); header('Location: ' . akun_url_kembali()); exit; }
        $s = $db->prepare('SELECT id, nama, kelas, nisn FROM siswa WHERE id = :id'); $s->execute([':id' => $sid]);
        $hasil = ['berhasil' => [$s->fetch() + ['kode' => $h['kode_tampil'], 'berlaku' => $h['berlaku']]], 'dilewati' => []];
        catat_aktivitas('RESET', 'akun_siswa', 'Ganti HP: reset perangkat & kode aktivasi baru: ' . nama_siswa_log($sid));

    } elseif ($aksi === 'kode_perangkat') {
        // Akun AKTIF, perangkat sudah dilepas: terbitkan KODE PERANGKAT. Akun/password/sesi tidak disentuh.
        if (!akun_nonce_pakai((string) ($_POST['nonce'] ?? ''))) {
            set_flash('warning', 'Permintaan ini sudah diproses atau halaman sudah kedaluwarsa. Muat ulang halaman bila perlu membuat kode lagi.');
            header('Location: ' . akun_url_kembali()); exit;
        }
        $h = portal_terbitkan_kode_perangkat($sid, $adminId);
        if (!$h['ok']) { set_flash('warning', $h['alasan']); header('Location: ' . akun_url_kembali()); exit; }
        $s = $db->prepare('SELECT id, nama, kelas, nisn FROM siswa WHERE id = :id'); $s->execute([':id' => $sid]);
        $hasil = ['berhasil' => [$s->fetch() + ['kode' => $h['kode_tampil'], 'berlaku' => $h['berlaku']]], 'dilewati' => [], 'jenis' => 'perangkat'];
        catat_aktivitas('GENERATE', 'akun_siswa', 'Menerbitkan kode perangkat: ' . nama_siswa_log($sid));

    } elseif ($aksi === 'blokir' || $aksi === 'aktifkan') {
        if ($aksi === 'blokir') {
            $db->prepare("UPDATE siswa_akun SET status = 'nonaktif', sesi_versi = sesi_versi + 1 WHERE siswa_id = :id")->execute([':id' => $sid]);
            catat_aktivitas('UBAH', 'akun_siswa', 'Memblokir akun portal: ' . nama_siswa_log($sid));
            set_flash('success', 'Akun diblokir. Sesi yang sedang berjalan langsung berakhir.');
        } else {
            $db->prepare("UPDATE siswa_akun SET status = IF(password_hash IS NULL, 'menunggu', 'aktif') WHERE siswa_id = :id AND status = 'nonaktif'")->execute([':id' => $sid]);
            catat_aktivitas('UBAH', 'akun_siswa', 'Mengaktifkan kembali akun portal: ' . nama_siswa_log($sid));
            set_flash('success', 'Akun diaktifkan kembali.');
        }
        header('Location: ' . akun_url_kembali()); exit;

    } elseif ($aksi === 'portal_toggle') {
        $v = ((int) ($_POST['nilai'] ?? 1)) === 1 ? 1 : 0;
        $db->exec('UPDATE settings SET portal_siswa_aktif = ' . $v . ' ORDER BY id ASC LIMIT 1');
        catat_aktivitas('UBAH', 'akun_siswa', ($v ? 'Membuka' : 'Menutup') . ' portal siswa');
        set_flash('success', $v ? 'Portal siswa dibuka.' : 'Portal siswa ditutup. Siswa tidak bisa masuk sampai dibuka kembali.');
        header('Location: ' . akun_url_kembali()); exit;
    }
}

// ---------------------------------------------------------------------
// HALAMAN HASIL (kode tampil sekali) — menggantikan daftar
// ---------------------------------------------------------------------
if ($hasil !== null) {
    header('Cache-Control: no-store, max-age=0');
    $nSekolah = (string) (get_settings()['nama_sekolah'] ?? '');
    $urlAktivasi = BASE_URL . '/portal/aktivasi';
    $kodePerangkat = ($hasil['jenis'] ?? '') === 'perangkat';
    ?>
<style>
  .slip-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
  .slip { border: 1.5px dashed #94a3b8; border-radius: 12px; padding: 14px 16px; background: #fff; break-inside: avoid; page-break-inside: avoid; }
  .slip .sk { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #64748b; }
  .slip .nm { font-weight: 700; font-size: 15px; margin-top: 4px; line-height: 1.25; }
  .slip .mt { font-size: 12.5px; color: #475569; }
  .slip .kd { margin: 10px 0 6px; font: 700 24px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: .12em; color: #114e2e; background: #eef5f0; border-radius: 8px; padding: 10px 6px; text-align: center; }
  .slip .ft { font-size: 11.5px; color: #475569; }
  @media print {
    body * { visibility: hidden !important; }
    #areaCetak, #areaCetak * { visibility: visible !important; }
    #areaCetak { position: absolute; left: 0; top: 0; width: 100%; }
    .slip-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .no-print { display: none !important; }
  }
</style>

<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-3 mb-3 no-print">
  <h4 class="mb-0"><i class="bi bi-person-lock"></i> <?= $kodePerangkat ? 'Kode Perangkat Diterbitkan' : 'Kode Aktivasi Diterbitkan' ?></h4>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($hasil['berhasil']): ?>
    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Cetak</button>
    <button type="button" class="btn btn-outline-primary" id="btnCsv"><i class="bi bi-filetype-csv"></i> Unduh CSV</button>
    <?php endif; ?>
    <a href="<?= e(akun_url_kembali()) ?>" class="btn btn-outline-secondary"><i class="bi bi-check2"></i> Selesai</a>
  </div>
</div>

<?php if ($hasil['berhasil']): ?>
<div class="alert alert-warning no-print">
  <i class="bi bi-exclamation-triangle-fill"></i>
  <strong>Kode ini hanya tampil sekali.</strong> Cetak atau unduh sekarang; setelah halaman ini ditutup kode tidak bisa dilihat lagi
  (bila hilang, terbitkan kode baru). Setiap kode berlaku <?= $kodePerangkat ? PORTAL_KODE_PERANGKAT_HARI : PORTAL_KODE_HARI ?> hari dan hanya untuk satu siswa.<?= $kodePerangkat ? ' Kode perangkat tidak mengubah akun atau password siswa.' : '' ?>
</div>
<div id="areaCetak">
  <div class="slip-grid">
    <?php foreach ($hasil['berhasil'] as $r): ?>
    <div class="slip" data-kelas="<?= e($r['kelas']) ?>" data-nama="<?= e($r['nama']) ?>" data-nisn="<?= e($r['nisn']) ?>" data-kode="<?= e($r['kode']) ?>" data-berlaku="<?= e(substr($r['berlaku'], 0, 10)) ?>">
      <div class="sk"><?= e($nSekolah) ?> · Portal Siswa</div>
      <div class="nm"><?= e($r['nama']) ?></div>
      <div class="mt">Kelas <?= e($r['kelas']) ?> · NISN <?= e($r['nisn']) ?></div>
      <div class="kd"><?= e($r['kode']) ?></div>
      <div class="ft">Berlaku s.d. <?= e(format_tanggal_indonesia(substr($r['berlaku'], 0, 10))) ?><br><?php if ($kodePerangkat): ?>Buka <strong>aplikasi SIAP</strong> di HP baru, masuk ke portal, isi kode ini pada kartu Perangkat presensi, lalu pilih Daftarkan perangkat ini.<?php else: ?>Buka <strong><?= e($urlAktivasi) ?></strong>, isi NISN &amp; kode ini, lalu buat password sendiri.<?php endif; ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($hasil['dilewati']): ?>
<div class="card mt-3 no-print">
  <div class="card-body">
    <h6 class="mb-2"><i class="bi bi-skip-forward"></i> Dilewati (<?= count($hasil['dilewati']) ?>)</h6>
    <div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>Nama</th><th>Kelas</th><th>Alasan</th></tr></thead>
      <tbody><?php foreach ($hasil['dilewati'] as $r): ?>
        <tr><td><?= e($r['nama']) ?></td><td><?= e($r['kelas'] ?? '') ?></td><td><?= e($r['alasan']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </div>
</div>
<?php endif; ?>

<?php if (!$hasil['berhasil'] && !$hasil['dilewati']): ?>
  <div class="alert alert-info">Tidak ada siswa yang perlu dibuatkan kode.</div>
<?php endif; ?>

<script>
document.getElementById('btnCsv')?.addEventListener('click', function () {
  var baris = [['Kelas', 'Nama', 'NISN', <?= json_encode($kodePerangkat ? 'Kode Perangkat' : 'Kode Aktivasi') ?>, 'Berlaku Hingga']];
  document.querySelectorAll('.slip').forEach(function (s) {
    baris.push([s.dataset.kelas, s.dataset.nama, s.dataset.nisn, s.dataset.kode, s.dataset.berlaku]);
  });
  var csv = '\ufeff' + baris.map(function (r) { return r.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(','); }).join('\r\n');
  var a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  a.download = '<?= $kodePerangkat ? 'kode-perangkat' : 'kode-aktivasi' ?>-' + new Date().toISOString().slice(0, 10) + '.csv';
  document.body.appendChild(a); a.click(); a.remove();
});
</script>
<?php
    require_once __DIR__ . '/../includes/footer.php';
    return;
}

// ---------------------------------------------------------------------
// DAFTAR
// ---------------------------------------------------------------------
$q = clean($_GET['q'] ?? '');
$kelas = clean($_GET['kelas'] ?? '');
$statusF = clean($_GET['status'] ?? '');
$daftarStatus = ['belum' => 'Belum dibuat', 'menunggu' => 'Menunggu aktivasi', 'kedaluwarsa' => 'Kode kedaluwarsa', 'aktif' => 'Aktif', 'nonaktif' => 'Diblokir', 'masalah' => 'NISN kosong / ganda'];
if (!isset($daftarStatus[$statusF])) $statusF = '';

// Status turunan dihitung di SQL agar bisa disaring dan dipaginasi.
$dasar = "SELECT s.id, s.nama, s.nis, s.nisn, s.kelas, s.foto,
        a.id AS akun_id, a.status AS akun_status, a.kode_berlaku_hingga, a.login_terakhir,
        dv.status AS dev_status, dv.os AS dev_os, dv.browser AS dev_browser, dv.last_used_at AS dev_terakhir,
        CASE WHEN s.nisn IS NULL OR TRIM(s.nisn) = '' THEN 'NISN kosong'
             WHEN d.c > 1 THEN CONCAT('NISN dipakai ', d.c, ' siswa') END AS masalah_nisn,
        CASE WHEN s.nisn IS NULL OR TRIM(s.nisn) = '' OR d.c > 1 THEN 'masalah'
             WHEN a.id IS NULL THEN 'belum'
             WHEN a.status = 'nonaktif' THEN 'nonaktif'
             WHEN a.status = 'aktif' THEN 'aktif'
             WHEN a.kode_berlaku_hingga IS NULL OR a.kode_berlaku_hingga < NOW() THEN 'kedaluwarsa'
             ELSE 'menunggu' END AS st
    FROM siswa s
    LEFT JOIN siswa_akun a ON a.siswa_id = s.id
    LEFT JOIN device_bindings dv ON dv.siswa_id = s.id
    LEFT JOIN (SELECT nisn, COUNT(*) c FROM siswa WHERE nisn IS NOT NULL AND TRIM(nisn) <> '' GROUP BY nisn) d ON d.nisn = s.nisn
    WHERE s.status = 'aktif'";

$where = []; $params = [];
if ($kelas !== '') { $where[] = 't.kelas = :kelas'; $params[':kelas'] = $kelas; }
if ($statusF !== '') { $where[] = 't.st = :st'; $params[':st'] = $statusF; }
if ($q !== '') { $where[] = '(t.nama LIKE :q1 OR t.nis LIKE :q2 OR t.nisn LIKE :q3)'; $params[':q1'] = "%$q%"; $params[':q2'] = "%$q%"; $params[':q3'] = "%$q%"; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$perPage = get_page_size();
$page = get_current_page();
$c = $db->prepare("SELECT COUNT(*) FROM ($dasar) t $whereSql"); $c->execute($params);
$totalData = (int) $c->fetchColumn();
$totalPages = max(1, (int) ceil($totalData / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$stmt = $db->prepare("SELECT t.* FROM ($dasar) t $whereSql ORDER BY t.kelas, t.nama LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$daftar = $stmt->fetchAll();

$ringkas = ['belum' => 0, 'menunggu' => 0, 'kedaluwarsa' => 0, 'aktif' => 0, 'nonaktif' => 0, 'masalah' => 0];
foreach ($db->query("SELECT t.st, COUNT(*) c FROM ($dasar) t GROUP BY t.st")->fetchAll() as $r) $ringkas[$r['st']] = (int) $r['c'];
$kelasList = $db->query("SELECT DISTINCT kelas FROM siswa WHERE status = 'aktif' ORDER BY kelas")->fetchAll(PDO::FETCH_COLUMN);

$extraParams = ['q' => $q, 'kelas' => $kelas, 'status' => $statusF, 'limit' => $perPage];
$kembali = http_build_query(array_filter($extraParams + ['page' => $page], fn($v) => $v !== '' && $v !== null));
$portalBuka = portal_aktif();
$nonce = akun_nonce();
$dari = $totalData ? $offset + 1 : 0;
$sampai = $offset + count($daftar);

$badge = [
    'belum' => ['secondary', 'Belum dibuat'], 'menunggu' => ['warning', 'Menunggu aktivasi'], 'kedaluwarsa' => ['danger', 'Kode kedaluwarsa'],
    'aktif' => ['success', 'Aktif'], 'nonaktif' => ['dark', 'Diblokir'], 'masalah' => ['warning', 'Perlu dicek'],
];
$jumlahBelumAktif = $ringkas['belum'] + $ringkas['menunggu'] + $ringkas['kedaluwarsa'];
?>
<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-3 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-lock"></i> Akun Siswa</h4>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <span class="small text-muted">Portal siswa:
      <span class="badge bg-<?= $portalBuka ? 'success' : 'secondary' ?>"><?= $portalBuka ? 'Dibuka' : 'Ditutup' ?></span>
    </span>
    <?php if ($bolehKelola): ?>
    <form method="post" class="d-inline" onsubmit="return confirm('<?= $portalBuka ? 'Tutup portal siswa? Siswa tidak akan bisa masuk sampai dibuka kembali.' : 'Buka portal siswa?' ?>')">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="portal_toggle"><input type="hidden" name="nilai" value="<?= $portalBuka ? 0 : 1 ?>"><input type="hidden" name="kembali" value="<?= e($kembali) ?>">
      <button class="btn btn-sm btn-outline-secondary"><?= $portalBuka ? '<i class="bi bi-lock"></i> Tutup portal' : '<i class="bi bi-unlock"></i> Buka portal' ?></button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="alert alert-light border small d-flex flex-wrap gap-2 align-items-center">
  <i class="bi bi-info-circle"></i>
  <span>Alamat portal siswa: <strong><?= e(BASE_URL) ?>/portal</strong> · Username siswa = <strong>NISN</strong>. Admin hanya menerbitkan <strong>kode aktivasi</strong>; password dibuat sendiri oleh siswa.</span>
</div>

<div class="row g-2 mb-3">
  <?php foreach (['aktif' => ['success', 'Aktif'], 'menunggu' => ['warning', 'Menunggu aktivasi'], 'kedaluwarsa' => ['danger', 'Kode kedaluwarsa'], 'belum' => ['secondary', 'Belum dibuat'], 'nonaktif' => ['dark', 'Diblokir'], 'masalah' => ['warning', 'NISN bermasalah']] as $k => [$warna, $label]): ?>
  <div class="col-6 col-md-2">
    <a class="card text-decoration-none h-100 <?= $statusF === $k ? 'border-primary' : '' ?>" href="<?= e(modul_url('akun_siswa', ['status' => $statusF === $k ? '' : $k, 'kelas' => $kelas, 'limit' => $perPage])) ?>">
      <div class="card-body py-2 px-3"><div class="fs-4 fw-bold text-<?= $warna ?>"><?= $ringkas[$k] ?></div><div class="small text-muted"><?= e($label) ?></div></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($bolehKelola && $jumlahBelumAktif > 0): ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <form method="post" onsubmit="return confirm('Terbitkan kode aktivasi untuk semua siswa yang belum aktif<?= $kelas !== '' ? ' di kelas ' . e($kelas) : ' (seluruh sekolah)' ?>?\n\nKode lama yang belum dipakai akan diganti.')">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="kode_kelas"><input type="hidden" name="nonce" value="<?= e($nonce) ?>"><input type="hidden" name="kelas" value="<?= e($kelas) ?>"><input type="hidden" name="kembali" value="<?= e($kembali) ?>">
    <button class="btn btn-primary"><i class="bi bi-key"></i> Terbitkan kode: semua yang belum aktif<?= $kelas !== '' ? ' di ' . e($kelas) : ' (seluruh sekolah)' ?></button>
  </form>
</div>
<?php endif; ?>

<div class="tbl-card">
  <div class="tbl-head">
    <h6>Daftar akun</h6>
    <div class="sub">Hanya siswa berstatus aktif. Siswa tanpa NISN (atau NISN ganda) tidak bisa dibuatkan akun.</div>
  </div>

  <div class="tbl-tools">
    <form method="get" data-live data-live-target="#hasil-live"><?= hidden_modul() ?>
      <?= render_per_page_field($perPage, false) ?>
      <div class="tbl-search">
        <i class="bi bi-search"></i>
        <input type="text" name="q" class="form-control" placeholder="Cari nama/NIS/NISN..." value="<?= e($q) ?>">
      </div>
      <select name="kelas" class="form-select">
        <option value="">Semua Kelas</option>
        <?php foreach ($kelasList as $k): ?><option value="<?= e($k) ?>" <?= $kelas === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?>
      </select>
      <select name="status" class="form-select">
        <option value="">Semua Status</option>
        <?php foreach ($daftarStatus as $k => $label): ?><option value="<?= e($k) ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
      <a href="<?= e(modul_url('akun_siswa')) ?>" class="btn btn-light border" title="Hapus semua filter"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
    </form>
  </div>

  <?php if ($bolehKelola): ?>
  <form method="post" id="formPilih" class="px-3 pb-2 d-flex flex-wrap gap-2 align-items-center" onsubmit="return pilihAda()">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="kode_terpilih"><input type="hidden" name="nonce" value="<?= e($nonce) ?>"><input type="hidden" name="kembali" value="<?= e($kembali) ?>">
    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-key"></i> Terbitkan kode untuk yang dicentang</button>
    <span class="small text-muted" id="infoPilih"></span>
  </form>
  <?php endif; ?>

  <div id="hasil-live">
  <div class="table-responsive">
    <table class="table tbl align-middle">
      <thead>
        <tr>
          <?php if ($bolehKelola): ?><th style="width:1%"><input type="checkbox" class="form-check-input" id="cekSemua" aria-label="Pilih semua"></th><?php endif; ?>
          <th>Nama</th><th>NISN</th><th>Kelas</th><th>Status akun</th><th>Perangkat</th><th>Terakhir masuk</th><?php if ($bolehKelola): ?><th style="width:1%"></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($daftar as $r):
        [$warna, $label] = $badge[$r['st']];
        $bisaKode = in_array($r['st'], ['belum', 'menunggu', 'kedaluwarsa'], true);
      ?>
        <tr>
          <?php if ($bolehKelola): ?>
          <td><?php if ($bisaKode): ?><input type="checkbox" class="form-check-input cek" name="ids[]" form="formPilih" value="<?= (int) $r['id'] ?>" aria-label="Pilih <?= e($r['nama']) ?>"><?php endif; ?></td>
          <?php endif; ?>
          <td><a class="text-decoration-none text-reset fw-semibold" href="<?= e(modul_url('siswa_detail', ['nis' => $r['nis']])) ?>"><?= e($r['nama']) ?></a></td>
          <td><?= $r['nisn'] !== null && trim((string) $r['nisn']) !== '' ? e($r['nisn']) : '<span class="text-muted">-</span>' ?></td>
          <td><?= e($r['kelas']) ?></td>
          <td>
            <span class="badge bg-<?= $warna ?>"><?= e($label) ?></span>
            <?php if ($r['masalah_nisn']): ?><div class="small text-danger"><?= e($r['masalah_nisn']) ?></div><?php endif; ?>
            <?php if ($r['st'] === 'menunggu' && $r['kode_berlaku_hingga']): ?><div class="small text-muted">s.d. <?= e(format_tanggal_indonesia(substr($r['kode_berlaku_hingga'], 0, 10))) ?></div><?php endif; ?>
          </td>
          <td>
            <?php if ($r['st'] === 'aktif' && !$r['dev_status'] && $r['kode_berlaku_hingga'] && $r['kode_berlaku_hingga'] >= date('Y-m-d H:i:s')): ?>
              <div class="small text-primary"><i class="bi bi-key"></i> Kode perangkat berlaku s.d. <?= e(date('d/m H:i', strtotime($r['kode_berlaku_hingga']))) ?></div>
            <?php endif; ?>
            <?php if ($r['dev_status'] === 'BLOCKED'): ?><span class="badge bg-dark">Diblokir</span>
            <?php elseif ($r['dev_status']): ?><span class="badge bg-success">Terdaftar</span><div class="small text-muted"><?= e(trim(($r['dev_os'] ?: '-') . ' · ' . ($r['dev_browser'] ?: '-'))) ?></div>
            <?php else: ?><span class="badge bg-secondary">Belum</span><?php endif; ?>
          </td>
          <td class="small text-muted"><?= $r['login_terakhir'] ? e(date('d/m/Y H:i', strtotime($r['login_terakhir']))) : '-' ?></td>
          <?php if ($bolehKelola): ?>
          <td class="text-nowrap">
            <?php if ($r['dev_status'] === 'ACTIVE' && in_array($r['st'], ['aktif', 'menunggu', 'kedaluwarsa'], true)): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Ganti HP <?= e(addslashes($r['nama'])) ?>?\n\nHanya PERANGKAT terdaftar yang dilepas; perangkat lama tidak bisa dipakai presensi lagi.<?= $r['st'] === 'aktif' ? '\n\nAkun, password, dan login siswa TIDAK berubah. Siswa mendaftarkan HP baru dari portal (tombol Daftarkan perangkat ini).' : '\n\nAkun belum aktif, jadi kode aktivasi baru akan diterbitkan.' ?>')"><?= csrf_field() ?><input type="hidden" name="aksi" value="ganti_hp"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="nonce" value="<?= e($nonce) ?>"><input type="hidden" name="kembali" value="<?= e($kembali) ?>">
                <button class="btn btn-sm btn-outline-secondary" title="Siswa ganti HP: hanya melepas perangkat terdaftar, akun tidak berubah"><i class="bi bi-phone"></i> Ganti HP</button></form>
            <?php endif; ?>
            <?php if ($bisaKode): ?>
              <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="kode_satu"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="nonce" value="<?= e($nonce) ?>"><input type="hidden" name="kembali" value="<?= e($kembali) ?>">
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-key"></i> <?= $r['st'] === 'belum' ? 'Buat kode' : 'Kode baru' ?></button></form>
            <?php elseif ($r['st'] === 'aktif'): ?>
              <?php if (!$r['dev_status']): ?>
              <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="kode_perangkat"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="nonce" value="<?= e($nonce) ?>"><input type="hidden" name="kembali" value="<?= e($kembali) ?>">
                <button class="btn btn-sm btn-primary" title="Perangkat sudah dilepas: terbitkan kode untuk mendaftarkan HP baru. Akun tidak berubah."><i class="bi bi-phone-vibrate"></i> Kode perangkat</button></form>
              <?php endif; ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Reset akun <?= e(addslashes($r['nama'])) ?>?\n\nSEMUANYA dihapus: password lama, perangkat presensi terdaftar, dan semua login yang sedang berjalan. Siswa harus aktivasi ulang dengan kode baru (perangkat terdaftar lagi saat aktivasi di aplikasi SIAP).')"><?= csrf_field() ?><input type="hidden" name="aksi" value="reset_satu"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="nonce" value="<?= e($nonce) ?>"><input type="hidden" name="kembali" value="<?= e($kembali) ?>">
                <button class="btn btn-sm btn-outline-warning" title="Hapus semuanya (akun + perangkat): lupa password / akun diambil alih"><i class="bi bi-arrow-repeat"></i> Reset</button></form>
              <form method="post" class="d-inline" onsubmit="return confirm('Blokir akun portal <?= e(addslashes($r['nama'])) ?>?')"><?= csrf_field() ?><input type="hidden" name="aksi" value="blokir"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="kembali" value="<?= e($kembali) ?>">
                <button class="btn btn-sm btn-outline-danger" title="Blokir"><i class="bi bi-slash-circle"></i></button></form>
            <?php elseif ($r['st'] === 'nonaktif'): ?>
              <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="aktifkan"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="kembali" value="<?= e($kembali) ?>">
                <button class="btn btn-sm btn-outline-success"><i class="bi bi-check-circle"></i> Aktifkan</button></form>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$daftar): ?>
        <tr><td colspan="<?= $bolehKelola ? 8 : 6 ?>" class="text-center text-muted py-4">Tidak ada data yang cocok.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="tbl-foot">
    <span class="text-muted small">Menampilkan <?= $dari ?>–<?= $sampai ?> dari <?= $totalData ?> siswa</span>
    <?php if ($totalPages > 1): ?><?= render_pagination($page, $totalPages, $extraParams) ?><?php endif; ?>
  </div>
  </div>
</div>

<script>
function pilihAda() {
  var n = document.querySelectorAll('.cek:checked').length;
  if (!n) { alert('Centang dulu siswa yang akan dibuatkan kode.'); return false; }
  return confirm('Terbitkan kode aktivasi untuk ' + n + ' siswa?');
}
(function () {
  function perbarui() {
    var n = document.querySelectorAll('.cek:checked').length, el = document.getElementById('infoPilih');
    if (el) el.textContent = n ? n + ' siswa dipilih' : '';
  }
  document.addEventListener('change', function (e) {
    if (e.target.id === 'cekSemua') document.querySelectorAll('.cek').forEach(function (c) { c.checked = e.target.checked; });
    if (e.target.classList && (e.target.classList.contains('cek') || e.target.id === 'cekSemua')) perbarui();
  });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>