<?php
require_once __DIR__ . '/../includes/header.php';

$kelas = clean($_GET['kelas'] ?? '');
$jurusan = clean($_GET['jurusan'] ?? '');
$q = clean($_GET['q'] ?? '');

$where = [];
$params = [];
if ($kelas !== '') { $where[] = 'kelas = :kelas'; $params[':kelas'] = $kelas; }
if ($jurusan !== '') { $where[] = 'jurusan = :jurusan'; $params[':jurusan'] = $jurusan; }
if ($q !== '') { $where[] = '(nama LIKE :q1 OR nis LIKE :q2 OR nisn LIKE :q3)'; $params[':q1'] = "%$q%"; $params[':q2'] = "%$q%"; $params[':q3'] = "%$q%"; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$db = getDB();

$perPage = get_page_size();
$page = get_current_page();
$stmtCount = $db->prepare("SELECT COUNT(*) c FROM siswa $whereSql");
$stmtCount->execute($params);
$totalData = (int) $stmtCount->fetch()['c'];
$totalPages = max(1, (int) ceil($totalData / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT * FROM siswa $whereSql ORDER BY kelas, nama LIMIT " . $perPage . " OFFSET $offset");
$stmt->execute($params);
$daftarSiswa = $stmt->fetchAll();

$kelasList = $db->query('SELECT DISTINCT kelas FROM siswa ORDER BY kelas')->fetchAll(PDO::FETCH_COLUMN);
$jurusanList = $db->query('SELECT DISTINCT jurusan FROM siswa WHERE jurusan IS NOT NULL ORDER BY jurusan')->fetchAll(PDO::FETCH_COLUMN);
$extraParams = ['q' => $q, 'kelas' => $kelas, 'jurusan' => $jurusan, 'limit' => $perPage];
?>

<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-3 mb-3">
  <h4 class="mb-0"><i class="bi bi-people"></i> Data Siswa</h4>
  <div class="d-flex gap-2">
    <?php if (can('siswa.impor')): ?>
    <a href="<?= modul_url('siswa_import') ?>" class="siap-btn siap-btn-ghost siap-mono-xs flex-fill flex-md-grow-0 text-nowrap d-inline-flex align-items-center justify-content-center gap-2">
      <i class="bi bi-upload"></i><span>Import<span class="d-none d-sm-inline"> Excel/CSV</span></span>
    </a>
    <?php endif; ?>
    <?php if (can('siswa.tambah')): ?>
    <a href="<?= modul_url('siswa_tambah') ?>" data-popup="<?= e(modul_url('siswa_tambah', ['modal' => 1])) ?>" data-popup-title="Tambah Siswa" class="siap-btn siap-btn-blue siap-mono-xs flex-fill flex-md-grow-0 text-nowrap d-inline-flex align-items-center justify-content-center gap-2">
      <i class="bi bi-plus-circle"></i><span>Tambah Siswa</span>
    </a>
    <?php endif; ?>
  </div>
</div>

<?php
$bolehUbah = can('siswa.ubah'); $bolehQr = can('qr_siswa.lihat'); $bolehDevice = can('device.lihat'); $bolehHapus = can('siswa.hapus'); $bolehKode = can('device.kelola');
$adaAksi = $bolehUbah || $bolehQr || $bolehDevice || $bolehKode || $bolehHapus;
$warnaAvatar = ['#20b2a0', '#4a9be8', '#a855c8', '#f59f0b', '#e5484d', '#2fb344', '#1d6fd8', '#8b5cf6'];
$adaFilter = ($q !== '' || $kelas !== '' || $jurusan !== '');
$dari = $totalData ? $offset + 1 : 0;
$sampai = $offset + count($daftarSiswa);
?>
<div class="tbl-card">
  <div class="tbl-head">
    <h6>Semua siswa</h6>
    <div class="sub">Cari, saring, dan kelola data siswa.</div>
  </div>

  <div class="tbl-tools">
    <form method="get" data-live data-live-target="#hasil-live"><?= hidden_modul() ?>
      <?= render_per_page_field($perPage, false) ?>
      <div class="tbl-search">
        <i class="bi bi-search"></i>
        <input type="text" name="q" class="form-control" placeholder="Cari nama/NISN..." value="<?= e($q) ?>">
      </div>
      <select name="kelas" class="form-select">
        <option value="">Semua Kelas</option>
        <?php foreach ($kelasList as $k): ?>
          <option value="<?= e($k) ?>" <?= $kelas === $k ? 'selected' : '' ?>><?= e($k) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="jurusan" class="form-select">
        <option value="">Semua Jurusan</option>
        <?php foreach ($jurusanList as $j): ?>
          <option value="<?= e($j) ?>" <?= $jurusan === $j ? 'selected' : '' ?>><?= e($j) ?></option>
        <?php endforeach; ?>
      </select>
      <a href="<?= modul_url('siswa') ?>" class="siap-btn siap-btn-ghost siap-mono-xs" title="Hapus semua filter"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
    </form>
  </div>

  <div id="hasil-live">
  <div class="table-responsive">
    <table class="table tbl align-middle">
      <thead>
        <tr><th>Nama</th><th>NISN</th><th>Kelas</th><th>Jurusan</th><th>Status</th><th style="width:1%"></th></tr>
      </thead>
      <tbody>
        <?php foreach ($daftarSiswa as $s):
          $kata = preg_split('/\s+/', trim((string) $s['nama']));
          $inisial = mb_strtoupper(mb_substr($kata[0] ?? '', 0, 1) . (isset($kata[1]) ? mb_substr($kata[1], 0, 1) : ''));
          $warna = $warnaAvatar[crc32((string) $s['nama']) % count($warnaAvatar)];
          $aktif = $s['status'] === 'aktif';
        ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-3">
              <?php if ($s['foto']): ?>
                <img src="<?= BASE_URL ?>/uploads/siswa/<?= e($s['foto']) ?>" class="avatar-sm" alt="">
              <?php else: ?>
                <span class="avatar-sm" style="background:<?= $warna ?>"><?= e($inisial) ?></span>
              <?php endif; ?>
              <a class="nama text-decoration-none text-reset" href="<?= e(modul_url('siswa_detail', ['nis' => $s['nis']])) ?>"><?= e($s['nama']) ?></a>
            </div>
          </td>
          <td><?= e($s['nisn'] ?: '-') ?></td>
          <td><span class="siap-badge siap-badge-info siap-mono-xs"><?= e($s['kelas']) ?></span></td>
          <td><?= e($s['jurusan'] ?: '-') ?></td>
          <td><span class="st <?= $aktif ? 'st-aktif' : 'st-lain' ?>"><?= $aktif ? 'Aktif' : e(ucfirst((string) $s['status'])) ?></span></td>
          <td class="text-end">
            <?php if ($adaAksi): ?>
            <div class="dropdown">
              <button class="btn-dots" type="button" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Aksi"><i class="bi bi-three-dots"></i></button>
              <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <?php if ($bolehUbah): ?>
                <li><a class="dropdown-item" href="<?= e(modul_url('siswa_edit', ['nis' => $s['nis']])) ?>" data-popup="<?= e(modul_url('siswa_edit', ['nis' => $s['nis'], 'modal' => 1])) ?>" data-popup-title="Edit Siswa"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                <?php endif; ?>
                <?php if ($bolehQr): ?>
                <li><a class="dropdown-item" href="<?= e(modul_url('qr_generate', ['nis' => $s['nis']])) ?>"><i class="bi bi-qr-code me-2"></i>QR Code</a></li>
                <?php endif; ?>
                <?php if ($bolehDevice): ?>
                <li><a class="dropdown-item" href="<?= e(modul_url('device', ['siswa_id' => (int) $s['id']])) ?>"><i class="bi bi-phone me-2"></i>Perangkat</a></li>
                <?php endif; ?>
                <?php if ($bolehKode): ?>
                <li><a class="dropdown-item" href="#" data-popup="<?= e(modul_url('kode_pasangan', ['siswa_id' => (int) $s['id']])) ?>" data-popup-title="Kode Pasangan — <?= e($s['nama']) ?>"><i class="bi bi-key me-2"></i>Kode Pasangan</a></li>
                <?php endif; ?>
                <?php if ($bolehHapus): ?>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <form method="post" action="<?= modul_url('siswa_hapus') ?>" class="m-0"
                        onsubmit="return confirm(<?= e(json_encode('Hapus siswa ' . $s['nama'] . '? Data presensi & device terkait akan ikut terhapus.', JSON_UNESCAPED_UNICODE)) ?>)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Hapus</button>
                  </form>
                </li>
                <?php endif; ?>
              </ul>
            </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($daftarSiswa)): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">Belum ada data siswa.</td></tr>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>