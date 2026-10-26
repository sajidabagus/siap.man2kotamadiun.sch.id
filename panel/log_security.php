<?php
require_once __DIR__ . '/../includes/header.php';

$db = getDB();

$event = clean($_GET['event'] ?? '');
$siswaQ = clean($_GET['siswa'] ?? '');
$tanggal = clean($_GET['tanggal'] ?? '');

$where = [];
$params = [];
if ($event !== '') {
  $where[] = 'sl.event = :event';
  $params[':event'] = $event;
}
if ($siswaQ !== '') {
  $where[] = '(s.nama LIKE :q1 OR s.nis LIKE :q2 OR s.nisn LIKE :q3)';
  $params[':q1'] = "%$siswaQ%";
  $params[':q2'] = "%$siswaQ%";
  $params[':q3'] = "%$siswaQ%";
}
if ($tanggal !== '') {
  $where[] = 'DATE(sl.created_at) = :tgl';
  $params[':tgl'] = $tanggal;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$perPage = get_page_size();
$page = get_current_page('page');
$stmtCount = $db->prepare("SELECT COUNT(*) c FROM security_logs sl LEFT JOIN siswa s ON s.id = sl.siswa_id $whereSql");
$stmtCount->execute($params);
$totalData = (int) $stmtCount->fetch()['c'];
$totalPages = max(1, (int) ceil($totalData / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT sl.*, s.nama, s.nisn, s.kelas
        FROM security_logs sl LEFT JOIN siswa s ON s.id = sl.siswa_id
        $whereSql ORDER BY sl.created_at DESC LIMIT " . $perPage . " OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();
$extraParams1 = ['siswa' => $siswaQ, 'event' => $event, 'tanggal' => $tanggal, 'limit' => $perPage];

$eventList = ['DEVICE_REGISTERED', 'DEVICE_MATCH', 'DEVICE_MISMATCH', 'DEVICE_BLOCKED', 'PRESENCE_SUCCESS', 'PRESENCE_REJECTED', 'ADMIN_LOGIN_FAILED', 'DEVICE_PAIRING_FAILED', 'PORTAL_LOGIN_GAGAL', 'PORTAL_LOGIN_TERKUNCI', 'PORTAL_AKTIVASI_OK', 'PORTAL_AKTIVASI_GAGAL', 'PORTAL_PASSWORD_DIGANTI', 'PORTAL_PASSWORD_ADMIN', 'IZIN_LAMPIRAN_DILIHAT', 'PINDAH_KELAS_DIAJUKAN', 'PINDAH_KELAS_DIBATALKAN', 'FOTO_PROFIL_DIUBAH'];

$badgeMap = [
  'DEVICE_REGISTERED' => 'info',
  'DEVICE_MATCH' => 'success',
  'DEVICE_MISMATCH' => 'danger',
  'DEVICE_BLOCKED' => 'dark',
  'ADMIN_LOGIN_FAILED' => 'danger',
  'DEVICE_PAIRING_FAILED' => 'warning',
  'DEVICE_RESET' => 'warning',
  'PRESENCE_SUCCESS' => 'success',
  'PRESENCE_REJECTED' => 'danger',
  'ADMIN_ACCOUNT_UPDATED' => 'primary',
  'PORTAL_LOGIN_GAGAL' => 'danger',
  'PORTAL_LOGIN_TERKUNCI' => 'dark',
  'PORTAL_AKTIVASI_OK' => 'success',
  'PORTAL_AKTIVASI_GAGAL' => 'warning',
  'PORTAL_PASSWORD_DIGANTI' => 'primary',
  'PORTAL_PASSWORD_ADMIN' => 'warning',
  'IZIN_LAMPIRAN_DILIHAT' => 'info',
  'PINDAH_KELAS_DIAJUKAN' => 'primary',
  'PINDAH_KELAS_DIBATALKAN' => 'secondary',
  'FOTO_PROFIL_DIUBAH' => 'info',
  'QR_KELAS_REGENERATED' => 'warning',
  'SISWA_PINDAH_KELAS' => 'info',
  'KENAIKAN_KELAS' => 'primary',
  'KELAS_DIGANTI_NAMA' => 'info',
];

// Log reset perangkat (riwayat administratif terpisah)
$page2 = get_current_page('page2');
$totalReset = (int) $db->query('SELECT COUNT(*) c FROM device_reset_logs')->fetch()['c'];
$totalPagesReset = max(1, (int) ceil($totalReset / $perPage));
$page2 = min($page2, $totalPagesReset);
$offset2 = ($page2 - 1) * $perPage;

$resetLogs = $db->query(
  "SELECT rl.*, s.nama, s.nisn, u.nama AS admin_nama
     FROM device_reset_logs rl JOIN siswa s ON s.id = rl.siswa_id
     LEFT JOIN users u ON u.id = rl.admin_id
     ORDER BY rl.created_at DESC LIMIT " . $perPage . " OFFSET $offset2"
)->fetchAll();
?>
<h4 class="mb-3"><i class="bi bi-shield-exclamation"></i> Log Keamanan</h4>

<ul class="nav nav-pills mb-3 flex-nowrap overflow-auto pb-1" role="tablist" style="white-space:nowrap">
  <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tabSecurity" type="button" role="tab"><i class="bi bi-shield-exclamation"></i> Aktivitas Keamanan</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tabReset" type="button" role="tab"><i class="bi bi-phone"></i> Riwayat Reset Perangkat</button></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="tabSecurity">

    <div class="tbl-card">
      <div class="tbl-head">
        <h6>Kejadian keamanan</h6>
        <div class="sub">Presensi, perangkat, dan percobaan login yang tercatat.</div>
      </div>
      <div class="tbl-tools">
        <form method="get" data-live data-live-target="#hasil-live"><?= hidden_modul() ?>
          <?= render_per_page_field($perPage, false) ?>
          <div class="tbl-search"><i class="bi bi-search"></i><input type="text" name="siswa" class="form-control" placeholder="Cari NISN siswa..." value="<?= e($siswaQ) ?>"></div>
          <select name="event" class="form-select">
            <option value="">Semua Event</option>
            <?php foreach ($eventList as $ev): ?><option value="<?= $ev ?>" <?= $event === $ev ? 'selected' : '' ?>><?= $ev ?></option><?php endforeach; ?>
          </select>
          <input type="date" name="tanggal" class="form-control" value="<?= e($tanggal) ?>">
          <a href="<?= modul_url('log_security') ?>" class="btn btn-light border" title="Hapus semua filter"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
        </form>
      </div>
      <div id="hasil-live">
        <div class="table-responsive">
          <table class="table tbl tbl-wrap tbl-fit tbl-stack align-middle">
            <thead>
              <tr>
                <th>Waktu</th>
                <th>NISN</th>
                <th>Event</th>
                <th>IP</th>
                <th class="d-none d-xl-table-cell">User Agent</th>
                <th>Keterangan</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($logs as $l): ?>
                <tr>
                  <td class="text-nowrap" data-label="Waktu"><?= e(date('d/m/Y', strtotime($l['created_at']))) ?><br><small class="text-muted"><?= e(date('H:i:s', strtotime($l['created_at']))) ?></small></td>
                  <td data-label="NISN"><?= !empty($l['nisn']) ? e($l['nisn']) : '-' ?></td>
                  <td data-label="Event"><span class="badge bg-<?= $badgeMap[$l['event']] ?? 'secondary' ?>" style="white-space:normal; word-break:break-word"><?= e($l['event']) ?></span></td>
                  <td class="small" data-label="IP" style="word-break:break-all"><?= e($l['ip_address']) ?></td>
                  <td class="small text-muted d-none d-xl-table-cell" style="word-break:break-word; max-width:260px;" title="<?= e($l['user_agent']) ?>"><?= e($l['user_agent']) ?></td>
                  <td class="small" data-label="Keterangan"><?= e($l['keterangan']) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$logs): ?><tr class="kosong">
                  <td colspan="6" class="text-center text-muted py-4">Tidak ada log.</td>
                </tr><?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($totalPages > 1): ?>
          <div class="tbl-foot">
            <span class="text-muted small">Menampilkan <?= count($logs) ?> dari <?= $totalData ?> log (halaman <?= $page ?> dari <?= $totalPages ?>)</span>
            <?= render_pagination($page, $totalPages, $extraParams1, 'page') ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="tab-pane fade" id="tabReset">
    <div class="tbl-card">
      <div class="tbl-head">
        <h6>Riwayat reset perangkat</h6>
        <div class="sub">Perangkat siswa yang pernah direset oleh admin.</div>
      </div>
      <div class="table-responsive">
        <table class="table tbl tbl-wrap tbl-fit tbl-stack align-middle">
          <thead>
            <tr>
              <th>Waktu</th>
              <th>NISN</th>
              <th>Alasan</th>
              <th>Direset Oleh</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($resetLogs as $r): ?>
              <tr>
                <td class="text-nowrap" data-label="Waktu"><?= e(date('d/m/Y', strtotime($r['created_at']))) ?><br><small class="text-muted"><?= e(date('H:i:s', strtotime($r['created_at']))) ?></small></td>
                <td data-label="NISN"><?= e($r['nisn'] ?: '-') ?></td>
                <td data-label="Alasan"><?= e($r['reason']) ?></td>
                <td data-label="Direset Oleh"><?= e($r['admin_nama'] ?? '-') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$resetLogs): ?><tr class="kosong">
                <td colspan="4" class="text-center text-muted py-4">Belum ada riwayat reset.</td>
              </tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($totalPagesReset > 1): ?>
        <div class="tbl-foot">
          <span class="text-muted small">Menampilkan <?= count($resetLogs) ?> dari <?= $totalReset ?> data (halaman <?= $page2 ?> dari <?= $totalPagesReset ?>)</span>
          <?= render_pagination($page2, $totalPagesReset, ['limit' => $perPage], 'page2') ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>