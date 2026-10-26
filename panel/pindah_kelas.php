<?php

/**
 * Pengajuan Pindah Kelas — antrean persetujuan untuk admin / petugas.
 * Disetujui -> siswa.kelas diganti dan siswa mendapat notifikasi di portal; riwayat presensi tidak diubah.
 */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/pindah_kelas.php';

$db = getDB();
$bolehProses = can('pindah_kelas.proses');
$admin = current_admin() ?: [];

if (!pindah_siapkan_tabel()) {
  echo '<div class="alert alert-danger">Tabel pengajuan belum bisa dibuat otomatis. Jalankan <code>database/migration_pindah_kelas.sql</code> di phpMyAdmin.</div>';
  require_once __DIR__ . '/../includes/footer.php';
  return;
}

function pindah_url_kembali(): string
{
  parse_str((string) ($_POST['kembali'] ?? ''), $kb);
  $boleh = [];
  foreach (['status', 'kelas', 'q', 'limit', 'page'] as $k) if (isset($kb[$k]) && is_scalar($kb[$k])) $boleh[$k] = (string) $kb[$k];
  return modul_url('pindah_kelas', $boleh);
}

// ---------------------------------------------------------------------
// AKSI (POST)
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  if (!$bolehProses) {
    set_flash('danger', 'Anda tidak memiliki izin untuk memproses pengajuan pindah kelas.');
    header('Location: ' . pindah_url_kembali());
    exit;
  }
  if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.');
    header('Location: ' . pindah_url_kembali());
    exit;
  }
  $aksi = (string) ($_POST['aksi'] ?? '');
  $id = (int) ($_POST['id'] ?? 0);

  if ($aksi === 'setujui') {
    $h = pindah_setujui($id, $admin, (string) ($_POST['catatan'] ?? ''));
    if ($h['ok']) {
      catat_aktivitas('UBAH', 'pindah_kelas', "Menyetujui pindah kelas {$h['nama']} (NIS {$h['nis']}): {$h['dari']} → {$h['ke']}");
      set_flash('success', "{$h['nama']} dipindahkan ke kelas {$h['ke']}.");
    } else {
      set_flash('danger', $h['pesan']);
    }
  } elseif ($aksi === 'tolak') {
    $h = pindah_tolak($id, $admin, (string) ($_POST['catatan'] ?? ''));
    if ($h['ok']) {
      catat_aktivitas('UBAH', 'pindah_kelas', "Menolak pindah kelas {$h['nama']} (NIS {$h['nis']}): {$h['dari']} → {$h['ke']}");
      set_flash('success', 'Pengajuan ditolak dan siswa diberi tahu.');
    } else {
      set_flash('danger', $h['pesan']);
    }
  }
  header('Location: ' . pindah_url_kembali());
  exit;
}

// ---------------------------------------------------------------------
// DAFTAR
// ---------------------------------------------------------------------
$statusF = (string) ($_GET['status'] ?? 'menunggu');
if ($statusF !== 'semua' && !isset(PINDAH_STATUS_LABEL[$statusF])) $statusF = 'menunggu';
$kelas = clean($_GET['kelas'] ?? '');
$q = clean($_GET['q'] ?? '');

$where = [];
$params = [];
if ($statusF !== 'semua') {
  $where[] = 'p.status = :st';
  $params[':st'] = $statusF;
}
if ($kelas !== '') {
  $where[] = '(p.kelas_asal = :k1 OR p.kelas_tujuan = :k2)';
  $params[':k1'] = $kelas;
  $params[':k2'] = $kelas;
}
if ($q !== '') {
  $where[] = '(s.nama LIKE :q1 OR s.nis LIKE :q2 OR s.nisn LIKE :q3)';
  $params[':q1'] = "%$q%";
  $params[':q2'] = "%$q%";
  $params[':q3'] = "%$q%";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$urut = $statusF === 'menunggu' ? 'p.diajukan_at ASC, p.id ASC' : 'p.id DESC';

$perPage = get_page_size();
$page = get_current_page();
$c = $db->prepare("SELECT COUNT(*) FROM pindah_kelas_pengajuan p JOIN siswa s ON s.id = p.siswa_id $whereSql");
$c->execute($params);
$totalData = (int) $c->fetchColumn();
$totalPages = max(1, (int) ceil($totalData / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$st = $db->prepare("SELECT p.*, s.nama, s.nis, s.kelas AS kelas_sekarang, TIMESTAMPDIFF(HOUR, p.diajukan_at, NOW()) AS jam_tunggu
                    FROM pindah_kelas_pengajuan p JOIN siswa s ON s.id = p.siswa_id $whereSql ORDER BY $urut LIMIT $perPage OFFSET $offset");
$st->execute($params);
$daftar = $st->fetchAll();

$awalBulan = date('Y-m-01');
$ringkas = [
  'menunggu'  => (int) $db->query("SELECT COUNT(*) FROM pindah_kelas_pengajuan WHERE status = 'menunggu'")->fetchColumn(),
  'disetujui' => (int) $db->query("SELECT COUNT(*) FROM pindah_kelas_pengajuan WHERE status = 'disetujui' AND diproses_at >= '$awalBulan'")->fetchColumn(),
  'ditolak'   => (int) $db->query("SELECT COUNT(*) FROM pindah_kelas_pengajuan WHERE status = 'ditolak' AND diproses_at >= '$awalBulan'")->fetchColumn(),
];
$kelasList = $db->query("SELECT DISTINCT kelas FROM siswa WHERE status = 'aktif' ORDER BY kelas")->fetchAll(PDO::FETCH_COLUMN);

$extraParams = ['status' => $statusF, 'kelas' => $kelas, 'q' => $q, 'limit' => $perPage];
$kembali = http_build_query(array_filter($extraParams + ['page' => $page], fn($v) => $v !== '' && $v !== null));
$dariN = $totalData ? $offset + 1 : 0;
$sampaiN = $offset + count($daftar);
$warnaStatus = ['menunggu' => 'warning', 'disetujui' => 'success', 'ditolak' => 'danger', 'dibatalkan' => 'secondary'];
$tunggu = function (int $jam): string {
  if ($jam < 1) return 'baru saja';
  if ($jam < 24) return $jam . ' jam lalu';
  return floor($jam / 24) . ' hari lalu';
};
?>
<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-arrow-left-right"></i> Pengajuan Pindah Kelas</h4>
</div>

<div class="row g-2 mb-3">
  <?php foreach ([['menunggu', 'warning', 'Menunggu persetujuan', $ringkas['menunggu']], ['disetujui', 'success', 'Disetujui bulan ini', $ringkas['disetujui']], ['ditolak', 'danger', 'Ditolak bulan ini', $ringkas['ditolak']]] as [$k, $w, $l, $n]): ?>
    <div class="col-6 col-md-3">
      <a class="card text-decoration-none h-100 <?= $statusF === $k ? 'border-primary' : '' ?>" href="<?= e(modul_url('pindah_kelas', ['status' => $k])) ?>">
        <div class="card-body py-2 px-3">
          <div class="fs-4 fw-bold text-<?= $w ?>"><?= $n ?></div>
          <div class="small text-muted"><?= e($l) ?></div>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<div class="tbl-card">
  <div class="tbl-tools">
    <form method="get" data-live data-live-target="#hasil-live"><?= hidden_modul() ?>
      <?= render_per_page_field($perPage, false) ?>
      <div class="tbl-search"><i class="bi bi-search"></i><input type="text" name="q" class="form-control" placeholder="Cari nama/NIS/NISN..." value="<?= e($q) ?>"></div>
      <select name="status" class="form-select">
        <?php foreach (['menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak', 'dibatalkan' => 'Dibatalkan siswa', 'semua' => 'Semua status'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
      <select name="kelas" class="form-select">
        <option value="">Semua Kelas</option>
        <?php foreach ($kelasList as $k): ?><option value="<?= e($k) ?>" <?= $kelas === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?>
      </select>
      <a href="<?= e(modul_url('pindah_kelas')) ?>" class="btn btn-light border" title="Hapus filter"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
    </form>
  </div>

  <div id="hasil-live">
    <div class="table-responsive">
      <table class="table tbl align-middle">
        <thead>
          <tr>
            <th>Siswa</th>
            <th>Pindah</th>
            <th>Alasan</th>
            <th>Status</th>
            <th>Diajukan</th>
            <th style="width:1%"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($daftar as $r): ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= e($r['nama']) ?></div>
                <div class="small text-muted">NIS <?= e($r['nis']) ?></div>
              </td>
              <td class="text-nowrap"><?= e($r['kelas_asal']) ?> <i class="bi bi-arrow-right"></i> <strong><?= e($r['kelas_tujuan']) ?></strong>
                <?php if ($r['status'] === 'menunggu' && $r['kelas_sekarang'] !== $r['kelas_asal']): ?><div class="small text-danger">Kelas siswa kini <?= e($r['kelas_sekarang']) ?></div><?php endif; ?>
              </td>
              <td style="max-width:240px">
                <div class="text-truncate" title="<?= e($r['alasan']) ?>"><?= e($r['alasan']) ?></div>
                <?php if ($r['catatan_petugas']): ?><div class="small text-muted text-truncate" title="<?= e($r['catatan_petugas']) ?>">Catatan: <?= e($r['catatan_petugas']) ?></div><?php endif; ?>
              </td>
              <td><span class="badge bg-<?= $warnaStatus[$r['status']] ?? 'secondary' ?>"><?= e(PINDAH_STATUS_LABEL[$r['status']] ?? $r['status']) ?></span>
                <?php if ($r['diproses_nama']): ?><div class="small text-muted"><?= e($r['diproses_nama']) ?></div><?php endif; ?></td>
              <td class="small text-muted text-nowrap"><?= e(date('d/m H:i', strtotime($r['diajukan_at']))) ?><div><?= $r['status'] === 'menunggu' ? e($tunggu((int) $r['jam_tunggu'])) : '' ?></div>
              </td>
              <td class="text-end text-nowrap">
                <?php if ($bolehProses && $r['status'] === 'menunggu'): ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('Pindahkan <?= e(addslashes($r['nama'])) ?> dari <?= e(addslashes($r['kelas_asal'])) ?> ke <?= e(addslashes($r['kelas_tujuan'])) ?>?')">
                    <?= csrf_field() ?><input type="hidden" name="aksi" value="setujui"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="kembali" value="<?= e($kembali) ?>">
                    <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Setujui</button>
                  </form>
                  <button type="button" class="btn btn-sm btn-outline-danger btn-tolak" data-id="<?= (int) $r['id'] ?>" data-nama="<?= e($r['nama']) ?>" data-pindah="<?= e($r['kelas_asal'] . ' → ' . $r['kelas_tujuan']) ?>"><i class="bi bi-x-lg"></i> Tolak</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$daftar): ?><tr>
              <td colspan="6" class="text-center text-muted py-4"><?= $statusF === 'menunggu' ? 'Tidak ada pengajuan yang menunggu. 🎉' : 'Tidak ada data yang cocok.' ?></td>
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
  <div class="modal fade" id="modalTolak" tabindex="-1" aria-labelledby="judulTolak" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="aksi" value="tolak"><input type="hidden" name="id" id="tolakId" value=""><input type="hidden" name="kembali" value="<?= e($kembali) ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="judulTolak">Tolak pengajuan pindah kelas</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2"><strong id="tolakNama"></strong> · <span id="tolakPindah"></span></p>
          <label class="form-label" for="tolakCatatan">Alasan penolakan <span class="text-danger">*</span></label>
          <textarea class="form-control" id="tolakCatatan" name="catatan" rows="3" maxlength="<?= PINDAH_CATATAN_MAKS ?>" minlength="5" required placeholder="Jelaskan alasan agar siswa paham (min. 5 karakter)"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
          <button class="btn btn-danger"><i class="bi bi-x-lg"></i> Tolak pengajuan</button>
        </div>
      </form>
    </div>
  </div>
  <script>
    document.addEventListener('click', function(e) {
      var b = e.target.closest('.btn-tolak');
      if (!b) return;
      document.getElementById('tolakId').value = b.dataset.id;
      document.getElementById('tolakNama').textContent = b.dataset.nama;
      document.getElementById('tolakPindah').textContent = b.dataset.pindah;
      document.getElementById('tolakCatatan').value = '';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTolak')).show();
    });
  </script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
