<?php
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (!can('kelas.ubah')) {
        set_flash('danger', 'Anda tidak memiliki izin untuk mengganti nama kelas.');
        header('Location: ' . modul_url('master_kelas'));
        exit;
    }

    $namaLama = clean($_POST['nama_lama'] ?? '');
    $namaBaru = clean($_POST['nama_baru'] ?? '');

    if ($namaLama === '' || $namaBaru === '') {
        $errors[] = 'Nama kelas lama dan baru wajib diisi.';
    } elseif ($namaLama === $namaBaru) {
        $errors[] = 'Nama baru sama dengan nama lama — tidak ada yang diubah.';
    } else {
        $stmtCek = $db->prepare('SELECT COUNT(*) c FROM siswa WHERE kelas = :k');
        $stmtCek->execute([':k' => $namaLama]);
        $jumlahSiswa = (int) $stmtCek->fetch()['c'];

        if ($jumlahSiswa === 0) {
            $errors[] = "Kelas \"$namaLama\" tidak ditemukan.";
        } else {
            $stmtCekTarget = $db->prepare('SELECT COUNT(*) c FROM siswa WHERE kelas = :k');
            $stmtCekTarget->execute([':k' => $namaBaru]);
            $sudahAdaTarget = (int) $stmtCekTarget->fetch()['c'] > 0;

            $db->beginTransaction();
            try {
                // 1) Data siswa -- termasuk yang sudah nonaktif/lulus, supaya
                //    riwayat mereka tetap konsisten memakai nama kelas yang baru.
                $db->prepare('UPDATE siswa SET kelas = :baru, updated_at = NOW() WHERE kelas = :lama')
                   ->execute([':baru' => $namaBaru, ':lama' => $namaLama]);

                // 2) QR Kelas permanen -- supaya QR fisik yang sudah dicetak/
                //    ditempel TETAP BERLAKU (token-nya tidak berubah, cuma
                //    label kelas_target-nya disesuaikan ke nama baru).
                $db->prepare('UPDATE qr_dinamis SET kelas_target = :baru WHERE kelas_target = :lama')
                   ->execute([':baru' => $namaBaru, ':lama' => $namaLama]);

                // 3) Riwayat presensi -- beda dgn Kenaikan Kelas (yg sengaja
                //    TIDAK mengubah riwayat lama krn itu perpindahan siswa
                //    beneran), ganti nama kelas berarti kelasnya SAMA cuma
                //    labelnya dikoreksi/diperbarui, jadi riwayat lama ikut
                //    disesuaikan supaya konsisten di semua laporan.
                $db->prepare('UPDATE presensi SET kelas_saat_presensi = :baru WHERE kelas_saat_presensi = :lama')
                   ->execute([':baru' => $namaBaru, ':lama' => $namaLama]);

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                error_log('master_kelas: ' . $e->getMessage());
                $errors[] = 'Gagal mengganti nama kelas (nama mungkin sudah dipakai). Tidak ada perubahan yang disimpan.';
            }

            if (!$errors) {
                $admin = current_admin();
                $keterangan = "Ganti nama kelas: \"$namaLama\" -> \"$namaBaru\" ($jumlahSiswa siswa)" .
                    ($sudahAdaTarget ? ' — digabung dengan kelas tujuan yang sudah ada' : '') .
                    ' oleh admin ID ' . ($admin['id'] ?? '?');
                catat_aktivitas('UBAH', 'master_kelas', $keterangan);

                set_flash('success', "Kelas \"$namaLama\" berhasil diganti nama menjadi \"$namaBaru\" ($jumlahSiswa siswa).");
                header('Location: ' . modul_url('master_kelas'));
                exit;
            }
        }
    }
}

$dataKelas = $db->query(
    "SELECT kelas,
        COUNT(*) AS total,
        SUM(status = 'aktif') AS aktif,
        SUM(status != 'aktif') AS nonaktif
     FROM siswa GROUP BY kelas ORDER BY kelas"
)->fetchAll();

// Kelompokkan per jenjang (X / XI / XII / Lainnya) supaya bisa ditampilkan
// 3 kolom berdampingan, bukan satu tabel panjang ke bawah.
$perJenjang = ['X' => [], 'XI' => [], 'XII' => [], 'Lainnya' => []];
foreach ($dataKelas as $k) {
    $perJenjang[ambil_jenjang($k['kelas'])][] = $k;
}
?>
<h4 class="mb-3"><i class="bi bi-tags"></i> Master Kelas</h4>

<?php
// Ringkasan jumlah siswa aktif & jumlah kelas per jenjang (X, XI, XII)
$ringkasJenjang = [];
foreach (['X', 'XI', 'XII'] as $j) {
    $ringkasJenjang[$j] = [
        'siswa' => array_sum(array_map(fn($k) => (int) $k['aktif'], $perJenjang[$j])),
        'kelas' => count($perJenjang[$j]),
    ];
}
$warnaJenjangCard = [
    'X'   => ['bg' => '#f0faf8', 'fg' => '#1fa58f'],
    'XI'  => ['bg' => '#eef5ff', 'fg' => '#1f6feb'],
    'XII' => ['bg' => '#fff7e8', 'fg' => '#e8a200'],
];
?>
<style>
  .stat-card { display: flex; align-items: center; gap: 16px; background: #fff; border: 1px solid #e9edf3; border-radius: 14px; padding: 18px 20px; height: 100%; box-shadow: 0 1px 3px rgba(20,33,61,.04); }
  .stat-card .ikon { width: 50px; height: 50px; border-radius: 12px; flex: none; display: inline-flex; align-items: center; justify-content: center; font-size: 20px; }
  .stat-card .label { font-size: .78rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #8a94a6; }
  .stat-card .angka { font-size: 1.9rem; font-weight: 700; line-height: 1.1; color: #14213d; }
  .stat-card .ket { font-size: .85rem; color: #8a94a6; }
</style>
<div class="row g-3 mb-3">
  <?php foreach ($ringkasJenjang as $j => $r): ?>
    <div class="col-12 col-md-4">
      <div class="stat-card">
        <span class="ikon" style="background:<?= $warnaJenjangCard[$j]['bg'] ?>;color:<?= $warnaJenjangCard[$j]['fg'] ?>"><i class="bi bi-people"></i></span>
        <div>
          <div class="label">Siswa Jenjang <?= e($j) ?></div>
          <div class="angka"><?= number_format($r['siswa'], 0, ',', '.') ?></div>
          <div class="ket"><?= (int) $r['kelas'] ?> kelas</div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div>
<?php endif; ?>

<?php if (empty($dataKelas)): ?>
  <div class="card"><div class="card-body">
    <p class="text-muted mb-0">Belum ada data kelas.</p>
  </div></div>
<?php else: ?>
  <?php
  // Baris tabel satu kelas: pil nama kelas, jumlah siswa aktif/nonaktif, tombol ganti nama
  $barisKelas = function (array $daftar): void {
      foreach ($daftar as $k): ?>
        <tr>
          <td><span class="pill fw-semibold"><?= e($k['kelas']) ?></span></td>
          <td>
            <span class="st st-aktif"><?= (int) $k['aktif'] ?> aktif</span>
            <?php if ($k['nonaktif']): ?><span class="text-muted small ms-2"><?= (int) $k['nonaktif'] ?> nonaktif/lulus</span><?php endif; ?>
          </td>
          <td class="text-end">
            <?php if (can('kelas.ubah')): ?>
              <button type="button" class="btn-dots" data-bs-toggle="modal" data-bs-target="#modalGanti"
                      onclick="bukaModalGanti('<?= e($k['kelas'], ENT_QUOTES) ?>')" title="Ganti nama kelas" aria-label="Ganti nama kelas">
                <i class="bi bi-pencil-square"></i>
              </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach;
  };
  ?>
  <div class="row g-3">
    <?php foreach (['X', 'XI', 'XII'] as $jenjang): ?>
      <div class="col-12 col-lg-4">
        <div class="tbl-card h-100">
          <div class="tbl-head d-flex justify-content-between align-items-center">
            <div>
              <h6>Jenjang <?= e($jenjang) ?></h6>
              <div class="sub"><?= array_sum(array_map(fn($k) => (int) $k['aktif'], $perJenjang[$jenjang])) ?> siswa aktif</div>
            </div>
            <span class="pill"><?= count($perJenjang[$jenjang]) ?> kelas</span>
          </div>
          <?php if (empty($perJenjang[$jenjang])): ?>
            <div class="text-muted text-center py-4 small">Belum ada kelas <?= e($jenjang) ?>.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table tbl tbl-wrap tbl-fit align-middle">
                <thead><tr><th>Kelas</th><th>Siswa</th><th style="width:1%"></th></tr></thead>
                <tbody><?php $barisKelas($perJenjang[$jenjang]); ?></tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($perJenjang['Lainnya'])): ?>
    <div class="tbl-card mt-3">
      <div class="tbl-head d-flex justify-content-between align-items-center">
        <div>
          <h6>Lainnya</h6>
          <div class="sub">Nama kelas tidak mengikuti pola X/XI/XII.</div>
        </div>
        <span class="pill"><?= count($perJenjang['Lainnya']) ?> kelas</span>
      </div>
      <div class="table-responsive">
        <table class="table tbl tbl-wrap align-middle">
          <thead><tr><th>Kelas</th><th>Siswa</th><th style="width:1%"></th></tr></thead>
          <tbody><?php $barisKelas($perJenjang['Lainnya']); ?></tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <!-- Modal ganti nama kelas -->
  <div class="modal fade" id="modalGanti" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post" id="formGantiKelas">
          <?= csrf_field() ?>
          <input type="hidden" name="nama_lama" id="inputNamaLama">
          <div class="modal-header">
            <h6 class="modal-title"><i class="bi bi-pencil-square"></i> Ganti Nama Kelas</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="mb-2">Mengganti nama kelas <strong id="labelNamaLama"></strong> menjadi:</p>
            <input type="text" class="form-control" name="nama_baru" id="inputNamaBaru" placeholder="mis. XI-A" required>
            <p id="peringatanGabung" class="text-warning small mt-2 d-none">
              <i class="bi bi-exclamation-triangle"></i> Nama ini sudah dipakai kelas lain — siswa akan otomatis digabung ke kelas tersebut.
            </p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Simpan</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
  const NAMA_KELAS_TERSEDIA = <?= json_encode(array_column($dataKelas, 'kelas'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

  function bukaModalGanti(namaLama) {
    document.getElementById('inputNamaLama').value = namaLama;
    document.getElementById('labelNamaLama').textContent = '"' + namaLama + '"';
    const inputBaru = document.getElementById('inputNamaBaru');
    inputBaru.value = namaLama;
    document.getElementById('peringatanGabung').classList.add('d-none');

    inputBaru.oninput = function () {
      const beda = inputBaru.value.trim() !== namaLama;
      const sudahAda = NAMA_KELAS_TERSEDIA.includes(inputBaru.value.trim());
      document.getElementById('peringatanGabung').classList.toggle('d-none', !(beda && sudahAda));
    };
  }
  </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>