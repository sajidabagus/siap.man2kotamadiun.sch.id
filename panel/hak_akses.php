<?php
require_once __DIR__ . '/../includes/header.php';
siapkan_hak_akses();

$db = getDB();
$rolesEditable = array_diff(array_keys(DAFTAR_ROLE), ['admin']); // admin selalu penuh & terkunci
$semuaIzin = [];
foreach (DAFTAR_IZIN as $grup => $daftar) foreach ($daftar as $k => $_) $semuaIzin[$k] = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_require();
  $ringkas = [];
  try {
    $db->beginTransaction();
    foreach ($rolesEditable as $role) {
      $pilih = array_values(array_filter((array) ($_POST['izin'][$role] ?? []), fn($i) => isset($semuaIzin[$i]) && $i !== 'pengguna.kelola'));
      $db->prepare('DELETE FROM role_permissions WHERE role = :r')->execute([':r' => $role]);
      $ins = $db->prepare('INSERT INTO role_permissions (role, izin) VALUES (:r, :i)');
      foreach (array_unique($pilih) as $i) $ins->execute([':r' => $role, ':i' => $i]);
      $ringkas[] = DAFTAR_ROLE[$role] . ': ' . count($pilih) . ' izin';
    }
    $db->commit();
    catat_aktivitas('UBAH', 'hak_akses', 'Memperbarui hak akses — ' . implode('; ', $ringkas));
    set_flash('success', 'Hak akses berhasil disimpan.');
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    set_flash('danger', 'Gagal menyimpan hak akses.');
  }
  header('Location: ' . modul_url('hak_akses'));
  exit;
}

// izin yang dimiliki tiap role editable
$dimiliki = [];
foreach ($rolesEditable as $role) {
  $st = $db->prepare('SELECT izin FROM role_permissions WHERE role = :r');
  $st->execute([':r' => $role]);
  $dimiliki[$role] = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
}
?>
<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-3 mb-3">
  <h4 class="mb-0"><i class="bi bi-shield-lock"></i> Hak Akses</h4>
  <div class="d-flex gap-2">
    <a href="<?= modul_url('pengguna') ?>" class="btn btn-outline-secondary flex-fill flex-md-grow-0 text-nowrap d-inline-flex align-items-center justify-content-center gap-2"><i class="bi bi-person-badge"></i><span>Pengguna</span></a>
  </div>
</div>

<form method="post" data-dirty-guard>
  <?= csrf_field() ?>
  <div class="tbl-card">
    <div class="tbl-head">
      <h6>Matriks hak akses</h6>
      <div class="sub">Centang fungsi yang boleh digunakan tiap role. Administrator selalu memiliki semua akses.</div>
    </div>
    <div class="table-responsive">
      <table class="table tbl tbl-wrap tbl-fit align-middle">
        <thead>
          <tr>
            <th>Fungsi</th>
            <th class="text-center" style="width:120px">Administrator</th>
            <?php foreach ($rolesEditable as $role): ?><th class="text-center" style="width:120px"><?= e(DAFTAR_ROLE[$role]) ?></th><?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php $gi = 0; foreach (DAFTAR_IZIN as $grup => $daftar): $gi++; ?>
            <tr class="baris-grup">
              <td class="fw-bold" style="background:#fafbfc"><?= e($grup) ?></td>
              <td class="text-center" style="background:#fafbfc"></td>
              <?php foreach ($rolesEditable as $role): ?>
                <td class="text-center" style="background:#fafbfc">
                  <button type="button" class="btn btn-link btn-sm p-0 pilih-grup" data-grup="<?= $gi ?>" data-role="<?= e($role) ?>">pilih semua</button>
                </td>
              <?php endforeach; ?>
            </tr>
            <?php foreach ($daftar as $kode => $ket): $khususAdmin = $kode === 'pengguna.kelola'; ?>
              <tr>
                <td><?= e($ket) ?></td>
                <td class="text-center"><input class="form-check-input" type="checkbox" checked disabled></td>
                <?php foreach ($rolesEditable as $role): ?>
                  <td class="text-center">
                    <?php if ($khususAdmin): ?>
                      <i class="bi bi-lock text-muted" title="Khusus Administrator"></i>
                    <?php else: ?>
                      <input class="form-check-input cek-izin" type="checkbox" name="izin[<?= e($role) ?>][]" value="<?= e($kode) ?>"
                             data-grup="<?= $gi ?>" data-role="<?= e($role) ?>" <?= isset($dimiliki[$role][$kode]) ? 'checked' : '' ?>>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="tbl-foot">
      <span class="text-muted small">Perubahan berlaku pada login berikutnya atau saat halaman dimuat ulang.</span>
      <button type="submit" class="siap-btn siap-btn-blue siap-mono-xs"><i class="bi bi-save"></i> Simpan Hak Akses</button>
    </div>
  </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.pilih-grup').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var sel = '.cek-izin[data-grup="' + btn.dataset.grup + '"][data-role="' + btn.dataset.role + '"]';
      var boxes = document.querySelectorAll(sel);
      var semua = Array.prototype.every.call(boxes, function (b) { return b.checked; });
      boxes.forEach(function (b) { b.checked = !semua; });
      btn.textContent = semua ? 'pilih semua' : 'kosongkan';
      boxes[0] && boxes[0].dispatchEvent(new Event('change', { bubbles: true }));
    });
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
