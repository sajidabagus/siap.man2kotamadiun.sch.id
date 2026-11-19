<?php
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
try { buat_tabel_aktivitas(); } catch (Throwable $e) { error_log($e->getMessage()); }

$aksi = clean($_GET['aksi'] ?? '');
$modul = clean($_GET['modul_log'] ?? '');
$q = clean($_GET['q'] ?? '');
$tanggal = clean($_GET['tanggal'] ?? '');

$where = [];
$params = [];
if ($aksi !== '' && in_array($aksi, AKSI_AKTIVITAS, true)) { $where[] = 'aksi = :aksi'; $params[':aksi'] = $aksi; }
if ($modul !== '') { $where[] = 'modul = :modul'; $params[':modul'] = $modul; }
if ($q !== '') { $where[] = '(keterangan LIKE :q1 OR admin_nama LIKE :q2)'; $params[':q1'] = "%$q%"; $params[':q2'] = "%$q%"; }
if ($tanggal !== '') { $where[] = 'DATE(created_at) = :tgl'; $params[':tgl'] = $tanggal; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$perPage = get_page_size();
$page = get_current_page('page');
$stmtCount = $db->prepare("SELECT COUNT(*) c FROM activity_logs $whereSql");
$stmtCount->execute($params);
$totalData = (int) $stmtCount->fetch()['c'];
$totalPages = max(1, (int) ceil($totalData / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT * FROM activity_logs $whereSql ORDER BY created_at DESC, id DESC LIMIT " . $perPage . " OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$daftarModul = $db->query('SELECT DISTINCT modul FROM activity_logs ORDER BY modul')->fetchAll(PDO::FETCH_COLUMN);

$badge = [
    'TAMBAH' => 'success', 'UBAH' => 'primary', 'HAPUS' => 'danger', 'IMPOR' => 'info', 'EKSPOR' => 'info',
    'CETAK' => 'secondary', 'GENERATE' => 'warning', 'RESET' => 'warning', 'LOGIN' => 'dark', 'LOGOUT' => 'dark',
];
$labelModul = [
    'siswa_tambah' => 'Tambah Siswa', 'siswa_edit' => 'Edit Siswa', 'siswa_hapus' => 'Hapus Siswa', 'siswa_import' => 'Import Siswa',
    'master_kelas' => 'Master Kelas', 'kenaikan_kelas' => 'Kenaikan Kelas', 'pindah_kelas' => 'Pindah Kelas', 'qr_siswa' => 'QR Siswa', 'qr_print' => 'Cetak QR',
    'qr_generate' => 'QR Siswa', 'qr_kelas' => 'QR Kelas', 'qr_kelas_print' => 'Cetak QR Kelas', 'presensi' => 'Data Presensi', 'agenda' => 'Agenda Sekolah', 'presensi_export' => 'Ekspor Presensi', 'presensi_print' => 'Cetak Data Presensi',
    'rekap' => 'Rekap', 'rekap_print' => 'Cetak Rekap Bulanan', 'device' => 'Perangkat', 'pengaturan' => 'Pengaturan', 'profil' => 'Profil', 'login' => 'Akun Admin', 'pengguna' => 'Pengguna', 'hak_akses' => 'Hak Akses',
];
$extra = ['aksi' => $aksi, 'modul_log' => $modul, 'q' => $q, 'tanggal' => $tanggal, 'limit' => $perPage];
?>
<h4 class="mb-3"><i class="bi bi-clock-history"></i> Log Aktivitas</h4>

<style>
  /* Bar filter: sama dengan halaman Data Presensi */
  .pf-filter {
    display: grid !important;
    grid-template-columns: auto auto auto auto minmax(200px, 1fr) auto;
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
    min-width: 150px;
  }

  .pf-filter .pf-tgl {
    min-width: 150px;
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

  @media (max-width: 1199.98px) {
    .pf-filter {
      grid-template-columns: 1fr 1fr;
    }

    .pf-filter .pf-pp,
    .pf-filter .pf-search {
      grid-column: 1 / -1;
    }
  }
</style>

<!-- Kartu filter -->
<div class="tbl-card mb-3">
  <div class="p-3">
    <form method="get" data-live data-live-target="#hasil-live" id="formFilter" class="pf-filter"><?= hidden_modul() ?>
      <div class="pf-pp"><?= render_per_page_field($perPage, false) ?></div>
      <input type="date" name="tanggal" class="form-control pf-tgl" value="<?= e($tanggal) ?>" aria-label="Tanggal">
      <select name="aksi" class="form-select pf-sel">
        <option value="">Semua Aksi</option>
        <?php foreach (AKSI_AKTIVITAS as $a): ?><option value="<?= $a ?>" <?= $aksi === $a ? 'selected' : '' ?>><?= $a ?></option><?php endforeach; ?>
      </select>
      <select name="modul_log" class="form-select pf-sel">
        <option value="">Semua Modul</option>
        <?php foreach ($daftarModul as $m): ?><option value="<?= e($m) ?>" <?= $modul === $m ? 'selected' : '' ?>><?= e($labelModul[$m] ?? $m) ?></option><?php endforeach; ?>
      </select>
      <div class="pf-search">
        <i class="bi bi-search"></i>
        <input type="search" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Cari keterangan / nama admin..." autocomplete="off">
      </div>
      <a href="<?= modul_url('log_aktivitas') ?>" class="btn btn-primary pf-btn" title="Hapus semua filter">Reset</a>
    </form>
  </div>
</div>

<div class="tbl-card">
  <div class="tbl-head">
    <h6>Riwayat aktivitas</h6>
    <div class="sub">Tindakan admin yang tercatat di sistem.</div>
  </div>
  <div id="hasil-live">
  <div class="table-responsive">
    <table class="table tbl tbl-wrap align-middle">
      <thead><tr><th>Waktu</th><th>Admin</th><th>Aksi</th><th>Modul</th><th>Keterangan</th><th>IP</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td class="text-nowrap"><?= e($l['created_at']) ?></td>
            <td><?= e($l['admin_nama'] ?? '-') ?></td>
            <td><span class="badge bg-<?= $badge[$l['aksi']] ?? 'secondary' ?>"><?= e($l['aksi']) ?></span></td>
            <td class="small"><?= e($labelModul[$l['modul']] ?? $l['modul']) ?></td>
            <td class="small"><?= e($l['keterangan']) ?></td>
            <td class="small text-muted"><?= e($l['ip_address']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="6" class="text-center text-muted py-4">Belum ada aktivitas tercatat.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
  <div class="tbl-foot">
    <span class="text-muted small">Menampilkan <?= count($logs) ?> dari <?= $totalData ?> aktivitas (halaman <?= $page ?> dari <?= $totalPages ?>)</span>
    <?= render_pagination($page, $totalPages, $extra, 'page') ?>
  </div>
  <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>