<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/device_binding.php';

$db = getDB();

// --- AKSI: blokir / aktifkan / reset ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_require();
  if (!can('device.kelola')) {
    set_flash('danger', 'Anda tidak memiliki izin untuk mengelola perangkat.');
    header('Location: ' . modul_url('device'));
    exit;
  }
  $aksi = clean($_POST['aksi'] ?? '');
  $siswaId = (int) ($_POST['siswa_id'] ?? 0);
  $admin = current_admin();

  if ($aksi === 'blokir') {
    set_device_status($siswaId, 'BLOCKED');
    set_flash('warning', 'Perangkat siswa telah diblokir.');
  } elseif ($aksi === 'aktifkan') {
    set_device_status($siswaId, 'ACTIVE');
    set_flash('success', 'Perangkat siswa telah diaktifkan kembali.');
  } elseif ($aksi === 'reset') {
    // Persetujuan wajib: tanpa centang "setuju" dari popup konfirmasi, reset DITOLAK.
    if (empty($_POST['setuju'])) {
      set_flash('warning', 'Reset dibatalkan: persetujuan pada popup peringatan belum diberikan. Tidak ada perangkat yang diubah.');
    } else {
      $reason = clean($_POST['reason'] ?? 'Direset oleh admin');
      $res = reset_device($siswaId, $admin['id'], $reason);
      set_flash($res['success'] ? 'success' : 'danger', $res['message']);
    }
  } elseif ($aksi === 'reset_massal') {
    $reason = mb_substr(clean($_POST['reason'] ?? ''), 0, 200);
    $target = ambil_target_reset_massal(clean($_POST['sasaran'] ?? ''));
    // Daftar siswa yang dicentang dikirim sebagai satu teks "1,2,3" (menghindari batas max_input_vars PHP).
    $idsPilih = array_slice(array_values(array_unique(array_filter(
      array_map('intval', explode(',', (string) ($_POST['siswa_ids'] ?? ''))), fn($v) => $v > 0
    ))), 0, 20000);
    // Persetujuan wajib: operator harus mengetik RESET pada popup konfirmasi akhir.
    if (clean($_POST['konfirmasi'] ?? '') !== 'RESET') {
      set_flash('warning', 'Reset massal dibatalkan: kata konfirmasi "RESET" tidak diketik. Tidak ada perangkat yang diubah.');
    } elseif ($reason === '') {
      set_flash('danger', 'Alasan reset massal wajib diisi.');
    } elseif ($target === null) {
      set_flash('danger', 'Sasaran tidak valid. Pilih kelas, jenjang, atau pilihan manual yang tersedia.');
    } elseif (!$idsPilih) {
      set_flash('danger', 'Belum ada siswa yang dicentang pada checklist. Pilih minimal satu siswa.');
    } else {
      // Hanya siswa yang DICENTANG dan memang berperangkat pada sasaran ini yang diproses.
      $idsFinal = array_values(array_intersect($idsPilih, $target['siswa_ids']));
      $termasukBlokir = !empty($_POST['termasuk_blokir']);
      $res = $idsFinal
        ? reset_device_massal($idsFinal, (int) $admin['id'], $reason, $termasukBlokir)
        : ['success' => false, 'message' => 'Tidak ada siswa terpilih yang memiliki perangkat terdaftar pada sasaran ini.'];
      if (!$res['success']) {
        set_flash('danger', $res['message']);
      } else {
        $pesan = "Reset massal {$target['label']} selesai: {$res['direset']} perangkat direset (dari " . count($idsFinal) . " siswa yang dicentang).";
        if ($res['dilewati_blokir'] > 0) $pesan .= " {$res['dilewati_blokir']} perangkat berstatus BLOCKED dilewati.";
        catat_aktivitas('RESET', 'device', "Reset perangkat massal ({$target['label']}, " . count($idsFinal) . " siswa dicentang): {$res['direset']} perangkat direset"
          . ($res['dilewati_blokir'] > 0 ? ", {$res['dilewati_blokir']} diblokir dilewati" : '') . ". Alasan: {$reason}");
        set_flash($res['direset'] > 0 ? 'success' : 'warning', $res['direset'] > 0 ? $pesan : 'Tidak ada perangkat yang direset. ' . $pesan);
      }
    }
  }
  header('Location: ' . modul_url('device'));
  exit;
}

$q = clean($_GET['q'] ?? '');
$kelas = clean($_GET['kelas'] ?? '');
$jenjang = clean($_GET['jenjang'] ?? '');
$statusFilter = clean($_GET['status'] ?? '');
$focusSiswaId = (int) ($_GET['siswa_id'] ?? 0);

$kelasList = getDB()->query('SELECT DISTINCT kelas FROM siswa ORDER BY kelas')->fetchAll(PDO::FETCH_COLUMN);

$where = [];
$params = [];
if ($q !== '') {
  $where[] = '(s.nama LIKE :q1 OR s.nisn LIKE :q2 OR s.nis LIKE :q3)';
  $params[':q1'] = $params[':q2'] = $params[':q3'] = "%$q%";
}
if ($kelas !== '') {
  $where[] = 's.kelas = :kelas';
  $params[':kelas'] = $kelas;
}
if ($jenjang !== '') {
  $kelasJenjang = array_values(array_filter($kelasList, fn($k) => ambil_jenjang((string) $k) === $jenjang));
  if ($kelasJenjang) {
    $ph = [];
    foreach ($kelasJenjang as $i => $k) { $ph[] = ':kj' . $i; $params[':kj' . $i] = $k; }
    $where[] = 's.kelas IN (' . implode(',', $ph) . ')';
  } else {
    $where[] = '1 = 0';
  }
}
if ($statusFilter !== '') {
  $where[] = 'db.status = :status';
  $params[':status'] = $statusFilter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$db = getDB();

$perPage = get_page_size();
$page = get_current_page();

// Jika datang dari link "lihat perangkat siswa X" (siswa.php) tanpa page eksplisit,
// otomatis lompat ke halaman yang memuat siswa tsb supaya highlight-nya tetap terlihat.
if ($focusSiswaId > 0 && !isset($_GET['page'])) {
  $idsStmt = $db->prepare(
    "SELECT s.id FROM siswa s LEFT JOIN device_bindings db ON db.siswa_id = s.id
         $whereSql ORDER BY (db.id IS NULL), db.last_used_at DESC, s.nama"
  );
  $idsStmt->execute($params);
  $ids = $idsStmt->fetchAll(PDO::FETCH_COLUMN);
  $pos = array_search($focusSiswaId, $ids, true);
  if ($pos !== false) {
    $page = intdiv($pos, $perPage) + 1;
  }
}

$stmtCount = $db->prepare("SELECT COUNT(*) c FROM siswa s LEFT JOIN device_bindings db ON db.siswa_id = s.id $whereSql");
$stmtCount->execute($params);
$totalData = (int) $stmtCount->fetch()['c'];
$totalPages = max(1, (int) ceil($totalData / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT s.id AS siswa_id, s.nisn, s.nama, s.kelas, s.status AS siswa_status, db.id AS binding_id, db.device_name, db.browser, db.os,
        db.ip_address, db.status AS device_status, db.registered_at, db.last_used_at
        FROM siswa s LEFT JOIN device_bindings db ON db.siswa_id = s.id
        $whereSql
        ORDER BY (db.id IS NULL), db.last_used_at DESC, s.nama
        LIMIT " . $perPage . " OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$daftar = $stmt->fetchAll();

// Semua id (lintas halaman) pada hasil filter yang bisa direset: siswa AKTIF yang punya perangkat.
// Dipakai tombol "Pilih semua hasil filter" pada checklist tabel.
$idsFilter = [];
if (can('device.kelola')) {
  $kondisiReset = $whereSql ? ($whereSql . " AND db.id IS NOT NULL AND s.status = 'aktif'") : "WHERE db.id IS NOT NULL AND s.status = 'aktif'";
  $stIds = $db->prepare("SELECT s.id FROM siswa s LEFT JOIN device_bindings db ON db.siswa_id = s.id $kondisiReset LIMIT 20000");
  $stIds->execute($params);
  $idsFilter = array_map('intval', $stIds->fetchAll(PDO::FETCH_COLUMN));
}
$perJenjangFilter = kelompokkan_per_jenjang($kelasList);
$settings = get_settings();
$extraParams = ['q' => $q, 'kelas' => $kelas, 'jenjang' => $jenjang, 'status' => $statusFilter, 'siswa_id' => $focusSiswaId ?: null, 'limit' => $perPage];
$extraParams = array_filter($extraParams, fn($v) => $v !== null && $v !== '');

// ---- Data untuk modal Reset Massal (hanya siswa aktif yang punya perangkat) ----
$bolehKelola = can('device.kelola');
$massal = [];          // 'kelas:X-A' / 'jenjang:XI' / 'manual' => ['label', 'total', 'blokir']
$daftarReset = [];     // ringkas: [id, nama, nisn, kelas, jenjang, blocked(0/1), nama_perangkat]
$kelasAktif = [];
if ($bolehKelola) {
  $kelasAktif = $db->query("SELECT DISTINCT kelas FROM siswa WHERE status = 'aktif' ORDER BY kelas")->fetchAll(PDO::FETCH_COLUMN);
  $rowsReset = $db->query(
    "SELECT s.id, s.nama, s.nisn, s.nis, s.kelas, db.status AS dstatus, db.device_name
     FROM siswa s JOIN device_bindings db ON db.siswa_id = s.id
     WHERE s.status = 'aktif' ORDER BY s.kelas, s.nama"
  )->fetchAll();
  foreach ($kelasAktif as $k) {
    $massal['kelas:' . $k] = ['label' => 'Kelas ' . $k, 'total' => 0, 'blokir' => 0];
    $jj = ambil_jenjang((string) $k);
    if (!isset($massal['jenjang:' . $jj])) $massal['jenjang:' . $jj] = ['label' => 'Jenjang ' . $jj, 'total' => 0, 'blokir' => 0];
  }
  $massal['manual'] = ['label' => 'Pilihan manual', 'total' => 0, 'blokir' => 0];
  $massal['terpilih'] = ['label' => 'Siswa terpilih dari tabel', 'total' => 0, 'blokir' => 0];
  foreach ($rowsReset as $r) {
    $jj = ambil_jenjang((string) $r['kelas']);
    $blk = $r['dstatus'] === 'BLOCKED' ? 1 : 0;
    $daftarReset[] = [(int) $r['id'], $r['nama'], $r['nisn'] ?: ($r['nis'] ?: '-'), $r['kelas'], $jj, $blk, (string) $r['device_name']];
    foreach (['kelas:' . $r['kelas'], 'jenjang:' . $jj, 'manual', 'terpilih'] as $kunci) {
      $massal[$kunci]['total']++;
      $massal[$kunci]['blokir'] += $blk;
    }
  }
}
$perJenjangAktif = kelompokkan_per_jenjang($kelasAktif);
$sasaranAwal = ($kelas !== '' && isset($massal['kelas:' . $kelas])) ? 'kelas:' . $kelas
  : (($jenjang !== '' && isset($massal['jenjang:' . $jenjang])) ? 'jenjang:' . $jenjang : '');
?>
<div class="siap-eyebrow siap-stack-sm">Sistem &amp; Keamanan</div>
<h2 class="siap-h2 mb-3"><i class="bi bi-phone"></i> Manajemen Perangkat</h2>

<div class="tbl-card">
  <div class="tbl-head d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h6>Perangkat siswa</h6>
      <div class="sub">Cari, pantau, reset, dan kelola perangkat yang terdaftar.</div>
    </div>
    <?php if ($bolehKelola): ?>
    <?php endif; ?>
  </div>
  <div class="tbl-tools">
    <form method="get" data-live data-live-target="#hasil-live"><?= hidden_modul() ?>
      <?= render_per_page_field($perPage, false) ?>
      <div class="tbl-search">
        <i class="bi bi-search"></i>
        <input type="text" name="q" class="form-control" placeholder="Cari nama atau NISN..." value="<?= e($q) ?>" autocomplete="off">
      </div>
      <select name="jenjang" class="form-select">
        <option value="">Semua Jenjang</option>
        <?php foreach (array_keys($perJenjangFilter) as $j): ?><option value="<?= e($j) ?>" <?= $jenjang === $j ? 'selected' : '' ?>>Jenjang <?= e($j) ?></option><?php endforeach; ?>
      </select>
      <select name="kelas" class="form-select">
          <option value="">Semua Kelas</option>
          <?php foreach ($kelasList as $k): ?><option value="<?= e($k) ?>" <?= $kelas === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?>
        </select>
              <select name="status" class="form-select">
          <option value="">Semua Status Perangkat</option>
          <option value="ACTIVE" <?= $statusFilter === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
          <option value="BLOCKED" <?= $statusFilter === 'BLOCKED' ? 'selected' : '' ?>>BLOCKED</option>
        </select>
      <?php if ($bolehKelola): ?>
        <button type="button" id="btnResetTerpilih" class="siap-btn-ios17 siap-btn-ios17-blue" data-bs-toggle="modal" data-bs-target="#resetMassalModal" disabled
                title="Reset perangkat siswa yang dicentang pada tabel" style="width:auto">
          <i class="bi bi-arrow-repeat"></i> Reset <span class="siap-badge siap-badge-info ms-1" id="btnResetN">0</span>
        </button>
      <?php endif; ?>
    </form>
    <?php if ($bolehKelola): ?>
      <!-- Bilah pilihan (di luar #hasil-live supaya tidak tergantikan saat pencarian langsung) -->
      <div id="barPilih" class="siap-note siap-note-warning py-2 px-3 small mb-0 w-100 d-none">
        <b id="barN">0</b> siswa dipilih untuk direset.
        <button type="button" id="barSemuaFilter" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs p-0 ms-2 d-none text-decoration-none" style="padding:0">Pilih semua <span id="barM"></span> hasil filter</button>
        <button type="button" id="barKosong" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs p-0 ms-2 text-decoration-none" style="padding:0">Kosongkan pilihan</button>
      </div>
    <?php endif; ?>
  </div>
  <div id="hasil-live">
  <?php if ($bolehKelola): ?><script type="application/json" id="dataFilter"><?= json_encode($idsFilter, JSON_HEX_TAG | JSON_HEX_AMP) ?></script><?php endif; ?>
  <div class="table-responsive">
    <table class="table tbl tbl-wrap tbl-fit align-middle">
      <thead>
        <tr>
          <?php if ($bolehKelola): ?><th style="width:36px"><input type="checkbox" class="form-check-input js-pilih-halaman" title="Pilih semua di halaman ini" aria-label="Pilih semua di halaman ini"></th><?php endif; ?>
          <th>Siswa</th>
          <th>Kelas</th>
          <th class="d-none d-md-table-cell">Device</th>
          <th class="d-none d-lg-table-cell">Terdaftar</th>
          <th>Terakhir Digunakan</th>
          <th>Status</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($daftar as $d): ?>
          <tr class="<?= $d['siswa_id'] === $focusSiswaId ? 'table-warning' : '' ?>">
            <?php if ($bolehKelola): ?>
              <td>
                <?php if ($d['binding_id'] && $d['siswa_status'] === 'aktif'): ?>
                  <input type="checkbox" class="form-check-input js-pilih-baris" value="<?= (int) $d['siswa_id'] ?>" aria-label="Pilih <?= e($d['nama']) ?>">
                <?php endif; ?>
              </td>
            <?php endif; ?>
            <td><?= e($d['nama']) ?><?= $d['siswa_status'] !== 'aktif' ? ' <span class="siap-badge siap-badge-info siap-mono-xs">nonaktif</span>' : '' ?><br><small class="text-muted">NISN: <?= e($d['nisn'] ?: '-') ?></small></td>
            <td><?= e($d['kelas']) ?></td>
            <td class="d-none d-md-table-cell"><?= e($d['device_name'] ?? '-') ?></td>
            <td class="d-none d-lg-table-cell"><?= $d['registered_at'] ? e(date('d/m/Y', strtotime($d['registered_at']))) . '<br><small class="text-muted">' . e(date('H:i', strtotime($d['registered_at']))) . '</small>' : '-' ?></td>
            <td><?= $d['last_used_at'] ? e(date('d/m/Y', strtotime($d['last_used_at']))) . '<br><small class="text-muted">' . e(date('H:i', strtotime($d['last_used_at']))) . '</small>' : '-' ?></td>
            <td>
              <?php if (!$d['binding_id']): ?>
                <span class="siap-badge siap-badge-info siap-mono-xs">UNREGISTERED</span>
              <?php else: ?>
                <span class="siap-badge siap-badge-<?= $d['device_status'] === 'ACTIVE' ? 'success' : 'danger' ?> siap-mono-xs"><?= e($d['device_status']) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex flex-wrap gap-1">
              <?php if (!can('device.kelola')): ?><span class="text-muted small">-</span><?php else: ?>
              <?php if ($d['binding_id']): ?>
                <form method="post" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="siswa_id" value="<?= $d['siswa_id'] ?>">
                  <?php if ($d['device_status'] === 'ACTIVE'): ?>
                    <button type="submit" name="aksi" value="blokir" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" onclick="return confirm(<?= e(json_encode('Blokir perangkat ' . $d['nama'] . '?', JSON_UNESCAPED_UNICODE)) ?>)">Blokir</button>
                  <?php else: ?>
                    <button type="submit" name="aksi" value="aktifkan" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs">Aktifkan</button>
                  <?php endif; ?>
                </form>
                <button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" data-bs-toggle="modal" data-bs-target="#resetModal<?= $d['siswa_id'] ?>">Reset</button>

                <div class="modal fade" id="resetModal<?= $d['siswa_id'] ?>" tabindex="-1">
                  <div class="modal-dialog">
                    <div class="modal-content">
                      <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="siswa_id" value="<?= $d['siswa_id'] ?>">
                        <div class="modal-header" style="background:var(--siap-danger-text);color:#fff;border-radius:var(--siap-radius-window) var(--siap-radius-window) 0 0">
                          <h6 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-1"></i> Peringatan: Reset Perangkat</h6>
                          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                        </div>
                        <div class="modal-body">
                          <div class="siap-note siap-note-danger" style="margin-bottom:1rem"><i class="bi bi-exclamation-triangle-fill"></i><div>Perangkat terdaftar milik <b><?= e($d['nama']) ?></b> akan <b>dihapus</b> dan tidak dapat dipulihkan. Siswa harus mendaftarkan perangkat baru pada presensi berikutnya, dan reset ini dihitung dalam batas reset bulanan.</div></div>
                          <label class="siap-label siap-label-ios17">Alasan reset</label>
                          <input type="text" name="reason" class="siap-input siap-input-ios17 mb-3" maxlength="200" placeholder="Perangkat hilang / rusak / ganti perangkat" required>
                          <div class="form-check">
                            <input class="form-check-input js-setuju-reset" type="checkbox" name="setuju" value="1" id="setuju<?= (int) $d['siswa_id'] ?>" required>
                            <label class="form-check-label small" for="setuju<?= (int) $d['siswa_id'] ?>">
                              Saya memahami risikonya dan <b>menyetujui</b> perangkat siswa ini dihapus.
                            </label>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" data-bs-dismiss="modal">Batal</button>
                          <button type="submit" name="aksi" value="reset" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs js-kirim-reset" disabled>Ya, Reset Perangkat</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              <?php else: ?>
                <a href="#" data-popup="<?= e(modul_url('kode_pasangan', ['siswa_id' => (int) $d['siswa_id']])) ?>" data-popup-title="Kode Pasangan — <?= e($d['nama']) ?>" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs"><i class="bi bi-key"></i> Kode Pasangan</a>
              <?php endif; ?>
              <?php endif; ?>
            </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$daftar): ?><tr>
            <td colspan="10" class="text-center text-muted py-4"><?= ($q !== '' || $kelas !== '' || $jenjang !== '' || $statusFilter !== '') ? 'Tidak ada perangkat/siswa yang cocok dengan pencarian.' : 'Tidak ada data.' ?></td>
          </tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <div class="tbl-foot">
      <span class="text-muted small">Menampilkan <?= count($daftar) ?> dari <?= $totalData ?> data (halaman <?= $page ?> dari <?= $totalPages ?>)</span>
      <?= render_pagination($page, $totalPages, $extraParams) ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<?php if ($bolehKelola): ?>
<!-- Modal Reset Massal (di luar #hasil-live agar tidak ikut tergantikan saat pencarian langsung) -->
<div class="modal fade" id="resetMassalModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" id="formResetMassal">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="reset_massal">
        <div class="modal-header" id="rmHeader">
          <h6 class="modal-title" id="rmJudul"><i class="bi bi-arrow-repeat"></i> Reset Perangkat Massal</h6>
          <button type="button" class="btn-close" id="rmTutup" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
        <!-- LANGKAH 1: pilih sasaran -->
        <div id="rmLangkah1">
        <div class="modal-body">
          <p class="small text-muted mb-3">Pilih kelas/jenjang, lalu <b>centang siswa yang perangkatnya mau direset</b>. Siswa mendaftarkan perangkat baru pada presensi berikutnya. Hanya siswa aktif yang punya perangkat terdaftar yang muncul.</p>

          <label class="form-label small">Sasaran</label>
          <select name="sasaran" id="rmSasaran" class="form-select mb-2" required>
            <option value="">— Pilih kelas atau jenjang —</option>
            <optgroup label="Per jenjang">
              <?php foreach (array_keys($perJenjangAktif) as $j): ?>
                <option value="jenjang:<?= e($j) ?>" <?= $sasaranAwal === 'jenjang:' . $j ? 'selected' : '' ?>>Seluruh jenjang <?= e($j) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php foreach ($perJenjangAktif as $j => $daftarKelas): ?>
              <optgroup label="Kelas <?= e($j) ?>">
                <?php foreach ($daftarKelas as $k): ?>
                  <option value="kelas:<?= e($k) ?>" <?= $sasaranAwal === 'kelas:' . $k ? 'selected' : '' ?>><?= e($k) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
            <optgroup label="Lainnya">
              <option value="terpilih">Siswa yang dicentang di tabel</option>
              <option value="manual">Pilih manual dari semua siswa (cari &amp; centang)</option>
            </optgroup>
          </select>

          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="termasuk_blokir" value="1" id="rmBlokir">
            <label class="form-check-label small" for="rmBlokir">Sertakan perangkat berstatus <b>BLOCKED</b> (jika tidak dicentang, perangkat yang diblokir tidak bisa dipilih dan dibiarkan)</label>
          </div>

          <!-- Checklist siswa -->
          <div id="rmDaftarWrap" class="d-none mb-2">
            <input type="text" id="rmCari" class="siap-input siap-input-ios17 mb-2" placeholder="Cari nama atau NISN di daftar..." autocomplete="off">
            <div class="d-flex justify-content-between align-items-center mb-1 small">
              <span id="rmHitung" class="text-muted"></span>
              <span>
                <button type="button" id="rmPilihSemua" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs p-0 me-3 text-decoration-none" style="padding:0">Pilih semua</button>
                <button type="button" id="rmKosongkan" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs p-0 text-decoration-none" style="padding:0">Kosongkan</button>
              </span>
            </div>
            <div id="rmDaftar" class="border rounded bg-white" style="max-height:250px;overflow:auto"></div>
            <div class="form-text">"Pilih semua" dan "Kosongkan" hanya berlaku untuk siswa yang sedang tampil (sesuai pencarian).</div>
          </div>
          <input type="hidden" name="siswa_ids" id="rmIds" value="">
          <div id="rmRingkasan" class="siap-note siap-note-info py-2 small mb-3">Pilih sasaran untuk menampilkan daftar siswa.</div>

          <label class="form-label small">Alasan reset</label>
          <input type="text" name="reason" class="form-control" maxlength="200" placeholder="Mis. Awal semester ganjil 2026/2027" required>
          <div class="form-text">Reset massal tidak mengurangi jatah reset bulanan masing-masing siswa. Setiap siswa tetap tercatat di log reset.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" data-bs-dismiss="modal">Batal</button>
          <button type="button" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs" id="rmLanjut" disabled>Lanjut ke Konfirmasi <i class="bi bi-arrow-right"></i></button>
        </div>
        </div>

        <!-- LANGKAH 2: peringatan & persetujuan akhir -->
        <div id="rmLangkah2" class="d-none">
          <div class="modal-body">
            <div class="siap-note siap-note-danger">
              <div class="fw-bold mb-1"><i class="bi bi-exclamation-octagon-fill"></i> Tindakan ini berbahaya dan TIDAK DAPAT DIBATALKAN</div>
              Perangkat terdaftar akan <b>dihapus</b>. Setiap siswa terkait harus mendaftarkan perangkat baru sebelum bisa presensi lagi.
            </div>
            <table class="table table-sm small mb-3">
              <tr><th style="width:38%">Sasaran</th><td id="rmKonSasaran"></td></tr>
              <tr><th>Siswa dipilih</th><td id="rmKonSiswa"></td></tr>
              <tr><th>Perangkat dihapus</th><td id="rmKonJumlah" class="fw-bold text-danger"></td></tr>
              <tr><th>Perangkat BLOCKED</th><td id="rmKonBlokir"></td></tr>
              <tr><th>Alasan</th><td id="rmKonAlasan"></td></tr>
            </table>
            <label class="form-label small" for="rmKonfirmasi">Untuk menyetujui, ketik <b class="text-danger">RESET</b> (huruf besar) lalu tekan tombol:</label>
            <input type="text" name="konfirmasi" id="rmKonfirmasi" class="siap-input siap-input-ios17" autocomplete="off" placeholder="RESET">
          </div>
          <div class="modal-footer">
            <button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" id="rmKembali"><i class="bi bi-arrow-left"></i> Kembali</button>
            <button type="submit" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs" id="rmKirim" disabled>Ya, Reset Sekarang</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  // ===== Reset INDIVIDUAL: tombol baru aktif setelah kotak persetujuan dicentang =====
  // (delegasi event, karena baris tabel diganti saat pencarian langsung)
  function sinkronIndividual(cb) {
    var f = cb.closest('form'), btn = f && f.querySelector('.js-kirim-reset');
    if (btn) btn.disabled = !cb.checked;
  }
  document.addEventListener('change', function (e) {
    if (e.target.classList && e.target.classList.contains('js-setuju-reset')) sinkronIndividual(e.target);
  });
  document.addEventListener('hidden.bs.modal', function (e) {
    e.target.querySelectorAll && e.target.querySelectorAll('.js-setuju-reset').forEach(function (cb) {
      cb.checked = false; sinkronIndividual(cb);
    });
  });

  // ===== Reset MASSAL: 2 langkah (checklist siswa -> peringatan + ketik RESET) =====
  var meta = <?= json_encode($massal, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var semua = <?= json_encode($daftarReset, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>.map(function (r) {
    return { id: r[0], nama: r[1], nisn: r[2], kelas: r[3], jenjang: r[4], b: r[5] === 1, dev: r[6] };
  });
  var $ = function (id) { return document.getElementById(id); };
  var form = $('formResetMassal'), sel = $('rmSasaran'), box = $('rmRingkasan'), cek = $('rmBlokir');
  if (!form || !sel) return;
  var langkah = 1;
  var dipilih = new Set();

  // ===== Checklist di TABEL: centang siswa, lalu tekan tombol Reset di samping filter =====
  // Pilihan disimpan di sessionStorage agar tidak hilang saat pindah halaman (pagination),
  // saat pencarian langsung mengganti isi tabel, atau saat halaman dimuat ulang.
  var KUNCI = 'siapabsen.device.dipilih';
  var pilihanTabel = new Set();
  var adaPerangkat = new Set(semua.map(function (x) { return x.id; }));   // hanya siswa aktif berperangkat
  function simpanPilihan() { try { sessionStorage.setItem(KUNCI, JSON.stringify(Array.from(pilihanTabel))); } catch (err) {} }
  try {
    JSON.parse(sessionStorage.getItem(KUNCI) || '[]').forEach(function (id) { if (adaPerangkat.has(id)) pilihanTabel.add(id); });
  } catch (err) {}
  simpanPilihan();   // buang id yang sudah tidak berperangkat (mis. baru direset)
  function idsFilter() {
    var el = $('dataFilter');
    try { return el ? JSON.parse(el.textContent) : []; } catch (err) { return []; }
  }
  function terapkanTabel() {
    var baris = document.querySelectorAll('.js-pilih-baris'), n = 0;
    baris.forEach(function (c) { c.checked = pilihanTabel.has(parseInt(c.value, 10)); if (c.checked) n++; });
    var h = document.querySelector('.js-pilih-halaman');
    if (h) { h.disabled = baris.length === 0; h.checked = baris.length > 0 && n === baris.length; h.indeterminate = n > 0 && n < baris.length; }
    var total = pilihanTabel.size, f = idsFilter();
    $('btnResetN').textContent = total;
    $('btnResetTerpilih').disabled = total === 0;
    $('barPilih').classList.toggle('d-none', total === 0);
    $('barN').textContent = total;
    $('barM').textContent = f.length;
    $('barSemuaFilter').classList.toggle('d-none', !f.some(function (id) { return !pilihanTabel.has(id); }));
  }
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t.classList) return;
    if (t.classList.contains('js-pilih-baris')) {
      var id = parseInt(t.value, 10);
      if (t.checked) pilihanTabel.add(id); else pilihanTabel.delete(id);
    } else if (t.classList.contains('js-pilih-halaman')) {
      document.querySelectorAll('.js-pilih-baris').forEach(function (c) {
        var id2 = parseInt(c.value, 10);
        if (t.checked) pilihanTabel.add(id2); else pilihanTabel.delete(id2);
      });
    } else return;
    simpanPilihan(); terapkanTabel();
  });
  $('barSemuaFilter').addEventListener('click', function () {
    idsFilter().forEach(function (id) { if (adaPerangkat.has(id)) pilihanTabel.add(id); });
    simpanPilihan(); terapkanTabel();
  });
  $('barKosong').addEventListener('click', function () { pilihanTabel.clear(); simpanPilihan(); terapkanTabel(); });
  $('btnResetTerpilih').addEventListener('click', function () {
    // Bootstrap membuka popup; di sini sasaran diisi otomatis dengan siswa yang dicentang.
    sel.value = 'terpilih';
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    langkah = 1;
  });
  var kartu = document.querySelector('.tbl-card');
  if (kartu) new MutationObserver(function () { terapkanTabel(); }).observe(kartu, { childList: true });  // #hasil-live diganti
  terapkanTabel();

  function layak() {            // siswa pada sasaran yang sedang dipilih
    var v = sel.value;
    if (v === 'manual') return semua;
    if (v === 'terpilih') return semua.filter(function (x) { return pilihanTabel.has(x.id); });
    if (v.indexOf('kelas:') === 0) { var k = v.slice(6); return semua.filter(function (x) { return x.kelas === k; }); }
    if (v.indexOf('jenjang:') === 0) { var j = v.slice(8); return semua.filter(function (x) { return x.jenjang === j; }); }
    return [];
  }
  function bolehPilih(x) { return !x.b || cek.checked; }
  function terlihat() {         // layak + cocok dengan kolom cari
    var q = $('rmCari').value.trim().toLowerCase();
    return layak().filter(function (x) {
      return !q || x.nama.toLowerCase().indexOf(q) !== -1 || String(x.nisn).toLowerCase().indexOf(q) !== -1;
    });
  }
  function teks(el, t) { el.textContent = t; return el; }

  function sinkron() {
    var L = layak(), d = meta[sel.value];
    $('rmIds').value = Array.from(dipilih).join(',');
    var blokirLayak = L.filter(function (x) { return x.b; }).length;
    $('rmHitung').textContent = 'Dipilih ' + dipilih.size + ' dari ' + L.filter(bolehPilih).length + ' perangkat yang bisa direset';
    if (!d) {
      box.className = 'alert alert-secondary py-2 small mb-3';
      box.textContent = 'Pilih sasaran untuk menampilkan daftar siswa.';
    } else {
      var t = d.label + ': ' + L.length + ' perangkat terdaftar';
      if (blokirLayak > 0) t += cek.checked ? ' (termasuk ' + blokirLayak + ' BLOCKED)' : ', ' + blokirLayak + ' BLOCKED dilewati';
      box.className = 'alert py-2 small mb-3 ' + (dipilih.size > 0 ? 'alert-warning' : 'alert-secondary');
      box.textContent = t + '. Akan direset: ' + dipilih.size + ' perangkat.';
    }
    $('rmLanjut').disabled = dipilih.size === 0;
  }

  function gambar() {
    var wadah = $('rmDaftar'), T = terlihat();
    wadah.textContent = '';
    if (!T.length) {
      wadah.appendChild(teks(document.createElement('div'), 'Tidak ada siswa berperangkat yang cocok.')).className = 'p-3 text-center text-muted small';
    }
    T.forEach(function (x) {
      var baris = document.createElement('label');
      baris.className = 'd-flex align-items-start gap-2 px-2 py-1 border-bottom mb-0' + (bolehPilih(x) ? '' : ' opacity-50');
      baris.style.cursor = bolehPilih(x) ? 'pointer' : 'not-allowed';
      var c = document.createElement('input');
      c.type = 'checkbox'; c.className = 'form-check-input mt-1 flex-shrink-0 rm-cek';
      c.value = x.id; c.checked = dipilih.has(x.id); c.disabled = !bolehPilih(x);
      var isi = document.createElement('span'); isi.className = 'small flex-grow-1';
      var nama = teks(document.createElement('span'), x.nama); nama.className = 'fw-semibold';
      var info = teks(document.createElement('span'), 'NISN ' + x.nisn + ' · ' + x.kelas + (x.dev ? ' · ' + x.dev : ''));
      info.className = 'text-muted';
      isi.appendChild(nama); isi.appendChild(document.createElement('br')); isi.appendChild(info);
      baris.appendChild(c); baris.appendChild(isi);
      if (x.b) {
        var lb = teks(document.createElement('span'), cek.checked ? 'BLOCKED' : 'BLOCKED – dilewati');
        lb.className = 'siap-badge siap-badge-danger align-self-center'; baris.appendChild(lb);
      }
      wadah.appendChild(baris);
    });
    sinkron();
  }

  function pilihSasaran() {
    dipilih.clear();
    $('rmCari').value = '';
    $('rmDaftarWrap').classList.toggle('d-none', !sel.value);
    if (sel.value && sel.value !== 'manual') layak().forEach(function (x) { if (bolehPilih(x)) dipilih.add(x.id); });
    gambar();
  }

  function tampil(n) {
    langkah = n;
    $('rmLangkah1').classList.toggle('d-none', n !== 1);
    $('rmLangkah2').classList.toggle('d-none', n !== 2);
    $('rmHeader').className = 'modal-header' + (n === 2 ? ' bg-danger text-white' : '');
    $('rmTutup').className = 'btn-close' + (n === 2 ? ' btn-close-white' : '');
    $('rmJudul').textContent = n === 2 ? 'Peringatan: Konfirmasi Reset Massal' : 'Reset Perangkat Massal';
    if (n === 2) { $('rmKonfirmasi').value = ''; $('rmKirim').disabled = true; setTimeout(function () { $('rmKonfirmasi').focus(); }, 150); }
  }
  function lanjut() {
    if (!form.reportValidity()) return;          // sasaran & alasan wajib terisi
    var d = meta[sel.value]; if (!d || dipilih.size === 0) return;
    var terpilih = layak().filter(function (x) { return dipilih.has(x.id); });
    var nm = terpilih.slice(0, 5).map(function (x) { return x.nama; }).join(', ');
    if (terpilih.length > 5) nm += ', dan ' + (terpilih.length - 5) + ' siswa lainnya';
    var blokirIkut = terpilih.filter(function (x) { return x.b; }).length;
    var blokirLewat = layak().filter(function (x) { return x.b; }).length;
    $('rmKonSasaran').textContent = d.label;
    $('rmKonSiswa').textContent = nm;
    $('rmKonJumlah').textContent = dipilih.size + ' perangkat';
    $('rmKonBlokir').textContent = cek.checked ? (blokirIkut ? blokirIkut + ' ikut dihapus' : '-') : (blokirLewat ? blokirLewat + ' dilewati (tidak dihapus)' : '-');
    $('rmKonAlasan').textContent = form.elements['reason'].value;
    tampil(2);
  }

  sel.addEventListener('change', pilihSasaran);
  cek.addEventListener('change', function () {
    layak().forEach(function (x) {
      if (!x.b) return;
      if (!cek.checked) dipilih.delete(x.id);
      else if (sel.value !== 'manual') dipilih.add(x.id);   // kelas/jenjang: ikut tercentang; manual: pilih sendiri
    });
    gambar();
  });
  $('rmCari').addEventListener('input', gambar);
  $('rmCari').addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
  $('rmDaftar').addEventListener('change', function (e) {
    var c = e.target;
    if (!c.classList || !c.classList.contains('rm-cek')) return;
    var id = parseInt(c.value, 10);
    if (c.checked) dipilih.add(id); else dipilih.delete(id);
    sinkron();
  });
  $('rmPilihSemua').addEventListener('click', function () { terlihat().forEach(function (x) { if (bolehPilih(x)) dipilih.add(x.id); }); gambar(); });
  $('rmKosongkan').addEventListener('click', function () { terlihat().forEach(function (x) { dipilih.delete(x.id); }); gambar(); });
  $('rmLanjut').addEventListener('click', lanjut);
  $('rmKembali').addEventListener('click', function () { tampil(1); });
  $('rmKonfirmasi').addEventListener('input', function () { $('rmKirim').disabled = this.value.trim() !== 'RESET'; });

  form.addEventListener('submit', function (e) {
    // Enter di langkah 1 tidak boleh mengirim form: lanjutkan ke konfirmasi saja.
    if (langkah !== 2) { e.preventDefault(); lanjut(); return; }
    if ($('rmKonfirmasi').value.trim() !== 'RESET' || dipilih.size === 0) { e.preventDefault(); return; }
    if (sel.value === 'terpilih') { try { sessionStorage.removeItem(KUNCI); } catch (err) {} }   // pilihan dipakai, kosongkan
  });
  $('resetMassalModal').addEventListener('hidden.bs.modal', function () { tampil(1); });
  if (sel.value) pilihSasaran(); else sinkron();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>