<?php

/**
 * Pengajuan Izin — antrean persetujuan izin/sakit siswa untuk petugas piket.
 * Disetujui -> hari efektif ditulis ke presensi (IZIN/SAKIT) dan siswa mendapat notifikasi.
 */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/izin.php';

$db = getDB();
$bolehProses = can('izin.proses');
$admin = current_admin() ?: [];

if (!izin_siapkan_tabel()) {
  echo '<div class="alert alert-danger">Tabel pengajuan belum bisa dibuat otomatis. Jalankan <code>database/migration_izin.sql</code> di phpMyAdmin.</div>';
  require_once __DIR__ . '/../includes/footer.php';
  return;
}

function izin_url_kembali(): string
{
  parse_str((string) ($_POST['kembali'] ?? ''), $kb);
  $boleh = [];
  foreach (['status', 'jenis', 'kelas', 'dari', 'sampai', 'q', 'limit', 'page'] as $k) if (isset($kb[$k]) && is_scalar($kb[$k])) $boleh[$k] = (string) $kb[$k];
  return modul_url('izin', $boleh);
}

// ---------------------------------------------------------------------
// AKSI (POST)
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  if (!$bolehProses) {
    set_flash('danger', 'Anda tidak memiliki izin untuk aksi ini.');
    header('Location: ' . izin_url_kembali());
    exit;
  }
  if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.');
    header('Location: ' . izin_url_kembali());
    exit;
  }
  $aksi = (string) ($_POST['aksi'] ?? '');

  if ($aksi === 'setujui_terpilih') {
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))))), 0, 100);
    $ok = 0;
    $gagal = 0;
    $hari = 0;
    foreach ($ids as $id) {
      $h = izin_setujui($id, $admin);
      if ($h['ok']) {
        $ok++;
        $hari += $h['diterapkan'];
      } else $gagal++;
    }
    if ($ok) catat_aktivitas('UBAH', 'izin', "Menyetujui $ok pengajuan izin/sakit sekaligus ($hari hari presensi ditulis)");
    set_flash($gagal ? 'warning' : 'success', "$ok pengajuan disetujui" . ($gagal ? ", $gagal dilewati (sudah diproses atau tidak valid)." : '.'));
  } elseif ($aksi === 'bersihkan_lampiran') {
    $n = izin_hapus_lampiran_lama((int) ($_POST['hari'] ?? IZIN_RETENSI_LAMPIRAN));
    catat_aktivitas('HAPUS', 'izin', "Menghapus $n berkas lampiran pengajuan yang sudah lama diproses");
    set_flash('success', "$n berkas lampiran dihapus. Data pengajuan tetap tersimpan.");
  }
  header('Location: ' . izin_url_kembali());
  exit;
}

// ---------------------------------------------------------------------
// DAFTAR
// ---------------------------------------------------------------------
$statusF = (string) ($_GET['status'] ?? 'menunggu');
if ($statusF !== 'semua' && !isset(IZIN_STATUS_LABEL[$statusF])) $statusF = 'menunggu';
$jenisF = in_array($_GET['jenis'] ?? '', ['izin', 'sakit', 'dispensasi'], true) ? $_GET['jenis'] : '';
$kelas = clean($_GET['kelas'] ?? '');
$q = clean($_GET['q'] ?? '');
$dari = izin_tanggal_valid((string) ($_GET['dari'] ?? '')) ?: '';
$sampai = izin_tanggal_valid((string) ($_GET['sampai'] ?? '')) ?: '';

$where = [];
$params = [];
if ($statusF !== 'semua') {
  $where[] = 'p.status = :st';
  $params[':st'] = $statusF;
}
if ($jenisF !== '') {
  $where[] = 'p.jenis = :j';
  $params[':j'] = $jenisF;
}
if ($kelas !== '') {
  $where[] = 's.kelas = :k';
  $params[':k'] = $kelas;
}
if ($dari !== '') {
  $where[] = 'p.tanggal_selesai >= :d';
  $params[':d'] = $dari;
}
if ($sampai !== '') {
  $where[] = 'p.tanggal_mulai <= :sd';
  $params[':sd'] = $sampai;
}
if ($q !== '') {
  $where[] = '(s.nama LIKE :q1 OR s.nis LIKE :q2 OR s.nisn LIKE :q3)';
  $params[':q1'] = "%$q%";
  $params[':q2'] = "%$q%";
  $params[':q3'] = "%$q%";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$urut = $statusF === 'menunggu' ? 'p.diajukan_at ASC, p.id ASC' : 'p.id DESC';     // antrean: yang paling lama menunggu di atas

$perPage = get_page_size();
$page = get_current_page();
$c = $db->prepare("SELECT COUNT(*) FROM izin_pengajuan p JOIN siswa s ON s.id = p.siswa_id $whereSql");
$c->execute($params);
$totalData = (int) $c->fetchColumn();
$totalPages = max(1, (int) ceil($totalData / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$st = $db->prepare("SELECT p.*, s.nama, s.nis, s.kelas, TIMESTAMPDIFF(HOUR, p.diajukan_at, NOW()) AS jam_tunggu
                    FROM izin_pengajuan p JOIN siswa s ON s.id = p.siswa_id $whereSql ORDER BY $urut LIMIT $perPage OFFSET $offset");
$st->execute($params);
$daftar = $st->fetchAll();

$awalBulan = date('Y-m-01');
$ringkas = [
  'menunggu'  => (int) $db->query("SELECT COUNT(*) FROM izin_pengajuan WHERE status = 'menunggu'")->fetchColumn(),
  'disetujui' => (int) $db->query("SELECT COUNT(*) FROM izin_pengajuan WHERE status = 'disetujui' AND diproses_at >= '$awalBulan'")->fetchColumn(),
  'ditolak'   => (int) $db->query("SELECT COUNT(*) FROM izin_pengajuan WHERE status = 'ditolak' AND diproses_at >= '$awalBulan'")->fetchColumn(),
  'lampiran'  => (int) $db->query('SELECT COUNT(*) FROM izin_pengajuan WHERE lampiran IS NOT NULL')->fetchColumn(),
];
$kelasList = $db->query("SELECT DISTINCT kelas FROM siswa WHERE status = 'aktif' ORDER BY kelas")->fetchAll(PDO::FETCH_COLUMN);

$extraParams = ['status' => $statusF, 'jenis' => $jenisF, 'kelas' => $kelas, 'dari' => $dari, 'sampai' => $sampai, 'q' => $q, 'limit' => $perPage];
$kembali = http_build_query(array_filter($extraParams + ['page' => $page], fn($v) => $v !== '' && $v !== null));
$dariN = $totalData ? $offset + 1 : 0;
$sampaiN = $offset + count($daftar);
$warnaStatus = ['menunggu' => 'warning', 'disetujui' => 'success', 'ditolak' => 'danger', 'dibatalkan' => 'secondary', 'dicabut' => 'dark'];
$tunggu = function (int $jam): string {
  if ($jam < 1) return 'baru saja';
  if ($jam < 24) return $jam . ' jam lalu';
  return floor($jam / 24) . ' hari lalu';
};
?>
<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-envelope-paper"></i> Pengajuan Izin, Sakit &amp; Dispensasi</h4>
</div>

<style>
  /* Kotak ringkasan: bersih, tanpa warna, dengan ikon */
  .iz-stat {
    display: flex;
    align-items: center;
    gap: .9rem;
    height: 100%;
    padding: .9rem 1.1rem;
    border-radius: 14px;
    background: #fff;
    border: 1px solid #e5e7eb;
    color: #212529;
    text-decoration: none;
    box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
    transition: box-shadow .15s ease, border-color .15s ease;
  }

  a.iz-stat:hover {
    color: #212529;
    border-color: #cbd5e1;
    box-shadow: 0 4px 12px rgba(15, 23, 42, .1);
  }

  .iz-stat.iz-active {
    border-color: #0d6efd;
    box-shadow: 0 0 0 3px rgba(13, 110, 253, .15);
  }

  .iz-stat .iz-icon {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: #f1f3f5;
    color: #495057;
    font-size: 1.35rem;
  }

  .iz-stat .iz-num {
    font-size: 1.75rem;
    font-weight: 800;
    line-height: 1.1;
  }

  .iz-stat .iz-label {
    font-size: .85rem;
    color: #6c757d;
    margin-top: .15rem;
  }

  /* Bar filter: sama dengan halaman Data Presensi */
  .pf-filter {
    display: grid !important;
    grid-template-columns: auto auto auto auto auto auto minmax(200px, 1fr) auto;
    align-items: center;
    gap: .75rem !important;
    width: 100%;
  }

  .pf-filter>* {
    margin: 0 !important;
    min-width: 0;
  }

  .pf-filter .form-select,
  .pf-filter .form-control {
    width: 100% !important;
    flex: none !important;
    max-width: none !important;
  }

  .pf-filter .pf-pp {
    display: flex;
    align-items: center;
    gap: .5rem;
    white-space: nowrap;
  }

  .pf-filter .pf-pp .form-select {
    width: auto !important;
  }

  .pf-filter .pf-sel {
    min-width: 140px;
  }

  .pf-filter .pf-tgl {
    min-width: 140px;
  }

  .pf-filter .pf-btn {
    white-space: nowrap;
  }

  .pf-filter .pf-search {
    position: relative;
  }

  .pf-filter .pf-search .bi-search {
    position: absolute;
    top: 50%;
    left: .85rem;
    transform: translateY(-50%);
    color: #6c757d;
    pointer-events: none;
  }

  .pf-filter .pf-search .form-control {
    padding-left: 2.4rem;
  }

  @media (max-width: 1399.98px) {
    .pf-filter {
      grid-template-columns: repeat(3, 1fr);
    }

    .pf-filter .pf-pp,
    .pf-filter .pf-search {
      grid-column: 1 / -1;
    }
  }

  @media (max-width: 575.98px) {
    .pf-filter {
      grid-template-columns: 1fr 1fr;
    }
  }
</style>

<div class="row g-3 mb-3">
  <?php foreach ([['menunggu', '', 'Menunggu persetujuan', $ringkas['menunggu'], 'bi-hourglass-split'], ['disetujui', '', 'Disetujui bulan ini', $ringkas['disetujui'], 'bi-check-circle'], ['ditolak', '', 'Ditolak bulan ini', $ringkas['ditolak'], 'bi-x-circle']] as [$k, $warna, $l, $n, $ikon]): ?>
    <div class="col-6 col-md-3">
      <a class="iz-stat <?= $statusF === $k ? 'iz-active' : '' ?>" href="<?= e(modul_url('izin', ['status' => $k])) ?>">
        <span class="iz-icon"><i class="bi <?= $ikon ?>"></i></span>
        <div>
          <div class="iz-num"><?= $n ?></div>
          <div class="iz-label"><?= e($l) ?></div>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
  <div class="col-6 col-md-3">
    <div class="iz-stat">
      <span class="iz-icon"><i class="bi bi-paperclip"></i></span>
      <div>
        <div class="iz-num"><?= $ringkas['lampiran'] ?></div>
        <div class="iz-label">Lampiran tersimpan</div>
      </div>
    </div>
  </div>
</div>

<!-- Kartu filter -->
<div class="tbl-card mb-3">
  <div class="p-3">
    <form method="get" data-live data-live-target="#hasil-live" id="formFilter" class="pf-filter"><?= hidden_modul() ?>
      <div class="pf-pp"><?= render_per_page_field($perPage, false) ?></div>
      <select name="status" class="form-select pf-sel">
        <?php foreach (['menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak', 'dibatalkan' => 'Dibatalkan siswa', 'dicabut' => 'Dicabut', 'semua' => 'Semua status'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
      <select name="jenis" class="form-select pf-sel">
        <option value="">Semua jenis pengajuan</option>
        <option value="izin" <?= $jenisF === 'izin' ? 'selected' : '' ?>>Izin</option>
        <option value="dispensasi" <?= $jenisF === 'dispensasi' ? 'selected' : '' ?>>Dispensasi</option>
        <option value="sakit" <?= $jenisF === 'sakit' ? 'selected' : '' ?>>Sakit</option>
      </select>
      <select name="kelas" class="form-select pf-sel">
        <option value="">Semua Kelas</option>
        <?php foreach ($kelasList as $k): ?><option value="<?= e($k) ?>" <?= $kelas === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?>
      </select>
      <input type="date" name="dari" class="form-control pf-tgl" value="<?= e($dari) ?>" title="Tanggal izin dari" aria-label="Tanggal izin dari">
      <input type="date" name="sampai" class="form-control pf-tgl" value="<?= e($sampai) ?>" title="Tanggal izin sampai" aria-label="Tanggal izin sampai">
      <div class="pf-search">
        <i class="bi bi-search"></i>
        <input type="search" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Cari nama siswa / NIS..." autocomplete="off">
      </div>
      <a href="<?= e(modul_url('izin')) ?>" class="btn btn-primary pf-btn" title="Hapus semua filter">Reset</a>
    </form>
  </div>
</div>

<div class="tbl-card">
  <?php if ($bolehProses && $statusF === 'menunggu' && $daftar): ?>
    <form method="post" id="formPilih" class="px-3 pt-3 pb-2 d-flex flex-wrap gap-2 align-items-center" onsubmit="return pilihAda()">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="setujui_terpilih"><input type="hidden" name="kembali" value="<?= e($kembali) ?>">
      <button class="btn btn-sm btn-success"><i class="bi bi-check2-all"></i> Setujui yang dicentang</button>
      <span class="small text-muted" id="infoPilih">Untuk menolak atau memberi catatan, buka <b>Tinjau</b>.</span>
    </form>
  <?php endif; ?>

  <div id="hasil-live">
    <div class="table-responsive">
      <table class="table tbl align-middle">
        <thead>
          <tr>
            <?php if ($bolehProses && $statusF === 'menunggu'): ?><th style="width:1%"><input type="checkbox" class="form-check-input" id="cekSemua" aria-label="Pilih semua"></th><?php endif; ?>
            <th>Siswa</th>
            <th>Jenis</th>
            <th>Tanggal</th>
            <th>Alasan</th>
            <th>Status</th>
            <th>Diajukan</th>
            <th style="width:1%"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($daftar as $r): $hariN = count(izin_efektif_dalam(izin_rentang($r['tanggal_mulai'], $r['tanggal_selesai']))); ?>
            <tr>
              <?php if ($bolehProses && $statusF === 'menunggu'): ?>
                <td><?php if ($r['status'] === 'menunggu'): ?><input type="checkbox" class="form-check-input cek" name="ids[]" form="formPilih" value="<?= (int) $r['id'] ?>" aria-label="Pilih <?= e($r['nama']) ?>"><?php endif; ?></td>
              <?php endif; ?>
              <td>
                <div class="fw-semibold"><?= e($r['nama']) ?></div>
                <div class="small text-muted"><?= e($r['kelas']) ?></div>
                <div class="small text-muted">NIS <?= e($r['nis']) ?></div>
              </td>
              <td><span class="badge bg-<?= $r['jenis'] === 'sakit' ? 'primary' : 'info' ?>"><?= e(izin_label_jenis($r['jenis'])) ?></span></td>
              <td class="text-nowrap"><?php if ($r['tanggal_mulai'] === $r['tanggal_selesai']): ?><?= e(tgl_indo_pendek($r['tanggal_mulai'])) ?><?php else: ?><?= e(tgl_indo_pendek($r['tanggal_mulai'])) ?> –<br><?= e(tgl_indo_pendek($r['tanggal_selesai'])) ?><?php endif; ?><div class="small text-muted"><?= $hariN ?> hari sekolah</div>
              </td>
              <td style="max-width:180px">
                <div class="text-truncate" title="<?= e($r['alasan']) ?>"><?= e($r['alasan']) ?></div><?php if ($r['lampiran']): ?><div class="small text-muted"><i class="bi bi-paperclip"></i> ada lampiran</div><?php endif; ?>
              </td>
              <td><span class="badge bg-<?= $warnaStatus[$r['status']] ?? 'secondary' ?>"><?= e(IZIN_STATUS_LABEL[$r['status']] ?? $r['status']) ?></span></td>
              <td class="small text-muted text-nowrap"><?= e(date('d/m H:i', strtotime($r['diajukan_at']))) ?><div><?= $r['status'] === 'menunggu' ? e($tunggu((int) $r['jam_tunggu'])) : '' ?></div>
              </td>
              <td class="text-end"><a class="btn btn-sm btn-outline-primary text-nowrap" href="<?= e(modul_url('izin_detail', ['id' => (int) $r['id']])) ?>">Tinjau</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$daftar): ?><tr>
              <td colspan="8" class="text-center text-muted py-4"><?= $statusF === 'menunggu' ? 'Tidak ada pengajuan yang menunggu. 🎉' : 'Tidak ada data yang cocok.' ?></td>
            </tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="tbl-foot">
      <span class="text-muted small">Menampilkan <?= $dariN ?>–<?= $sampaiN ?> dari <?= $totalData ?> pengajuan</span>
      <?php if ($totalPages > 1): ?><?= render_pagination($page, $totalPages, $extraParams) ?><?php endif; ?>
    </div>
  </div>
</div>

<?php if ($bolehProses): ?>
  <div class="card mt-3">
    <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
      <div class="small"><strong><i class="bi bi-shield-lock"></i> Penyimpanan lampiran.</strong> Surat dokter adalah data kesehatan. Hapus berkas lampiran dari pengajuan yang sudah lama selesai (data pengajuan tetap ada).</div>
      <form method="post" class="d-flex gap-2 align-items-center" onsubmit="return confirm('Hapus berkas lampiran yang sudah diproses lebih dari ' + this.hari.value + ' hari? Tindakan ini tidak bisa dibatalkan.')">
        <?= csrf_field() ?><input type="hidden" name="aksi" value="bersihkan_lampiran"><input type="hidden" name="kembali" value="<?= e($kembali) ?>">
        <span class="small text-nowrap">Lebih dari</span>
        <input type="number" name="hari" class="form-control form-control-sm" value="<?= IZIN_RETENSI_LAMPIRAN ?>" min="7" max="3650" style="width:90px">
        <span class="small">hari</span>
        <button class="btn btn-sm btn-outline-danger text-nowrap"><i class="bi bi-trash"></i> Hapus lampiran lama</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<script>
  function pilihAda() {
    var n = document.querySelectorAll('.cek:checked').length;
    if (!n) {
      alert('Centang dulu pengajuan yang akan disetujui.');
      return false;
    }
    return confirm('Setujui ' + n + ' pengajuan? Presensi izin/sakit akan langsung ditulis dan siswa diberi tahu.');
  }
  (function() {
    function perbarui() {
      var n = document.querySelectorAll('.cek:checked').length,
        el = document.getElementById('infoPilih');
      if (el) el.innerHTML = n ? n + ' pengajuan dipilih' : 'Untuk menolak atau memberi catatan, buka <b>Tinjau</b>.';
    }
    document.addEventListener('change', function(e) {
      if (e.target.id === 'cekSemua') document.querySelectorAll('.cek').forEach(function(c) {
        c.checked = e.target.checked;
      });
      if (e.target.classList && (e.target.classList.contains('cek') || e.target.id === 'cekSemua')) perbarui();
    });
  })();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>