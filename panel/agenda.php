<?php

/**
 * Agenda Sekolah — jadwalkan libur, ujian madrasah, dan kegiatan di luar kelas agar siswa tidak tercatat Alpa secara keliru.
 * Logika jadwal: includes/agenda.php (jadwal_hari). Halaman ini hanya antarmuka admin/petugas.
 */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/izin.php';

$bolehKelola = can('agenda.kelola');
$admin = current_admin() ?: [];

if (!agenda_siapkan_tabel()) {
  echo '<div class="alert alert-danger">Tabel agenda belum bisa dibuat otomatis. Jalankan <code>database/migration_agenda_sekolah.sql</code> di phpMyAdmin.</div>';
  require_once __DIR__ . '/../includes/footer.php';
  return;
}

// ---------------------------------------------------------------------
// AKSI (POST): simpan / hapus
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $balik = modul_url('agenda');
  if (!$bolehKelola) {
    set_flash('danger', 'Anda tidak memiliki izin untuk mengelola agenda sekolah.');
  } elseif (!csrf_verify($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.');
  } else {
    $aksi = (string) ($_POST['aksi'] ?? '');
    if ($aksi === 'simpan') {
      $id = (int) ($_POST['id'] ?? 0) ?: null;
      $v = agenda_validasi($_POST);
      if ($v['galat']) {
        set_flash('danger', $v['galat']);
      } else {
        $d = $v['data'];
        $bentrok = agenda_bentrok($d, $id);
        $h = agenda_simpan($id, $d, $admin);
        if ($h['ok']) {
          $label = AGENDA_JENIS[$d['jenis']]['singkat'];
          $cakupan = $d['kelas'] ? 'kelas ' . $d['kelas'] : 'semua kelas';
          catat_aktivitas($id ? 'UBAH' : 'TAMBAH', 'agenda', ($id ? 'Mengubah' : 'Menambah') . " agenda $label \"{$d['judul']}\" (" . agenda_label_tanggal($d['tanggal_mulai'], $d['tanggal_selesai']) . ", $cakupan)");
          $pesan = "Agenda \"{$d['judul']}\" disimpan.";
          // Libur yang dijadwalkan terlambat: bersihkan Alpa otomatis yang sudah terlanjur tercatat pada rentang itu.
          if ($d['jenis'] === 'libur' && !empty($_POST['hapus_alpa']) && $d['tanggal_mulai'] <= date('Y-m-d')) {
            $n = agenda_hapus_alpa_otomatis($d['tanggal_mulai'], min($d['tanggal_selesai'], date('Y-m-d')), $d['kelas']);
            if ($n > 0) {
              catat_aktivitas('HAPUS', 'agenda', "Menghapus $n catatan Alpa otomatis karena libur \"{$d['judul']}\"");
              $pesan .= " $n catatan Alpa otomatis pada tanggal itu dihapus.";
            }
          }
          if ($bentrok) {
            $pesan .= ' Catatan: bertumpuk dengan ' . count($bentrok) . ' agenda lain; prioritas: libur selalu menang, lalu agenda khusus kelas, lalu ujian di atas kegiatan.';
          }
          set_flash('success', $pesan);
        } else {
          set_flash('danger', $h['pesan']);
        }
      }
    } elseif ($aksi === 'hapus') {
      $h = agenda_hapus((int) ($_POST['id'] ?? 0));
      if ($h['ok']) {
        $a = $h['agenda'];
        catat_aktivitas('HAPUS', 'agenda', "Menghapus agenda \"{$a['judul']}\" (" . agenda_label_tanggal($a['tanggal_mulai'], $a['tanggal_selesai']) . ')');
        set_flash('success', "Agenda \"{$a['judul']}\" dihapus.");
      } else {
        set_flash('danger', $h['pesan']);
      }
    }
  }
  header('Location: ' . $balik);
  exit;
}

// ---------------------------------------------------------------------
// DATA
// ---------------------------------------------------------------------
$db = getDB();
$tampil = (string) ($_GET['tampil'] ?? 'aktif');
if (!in_array($tampil, ['aktif', 'lewat', 'semua'], true)) $tampil = 'aktif';
$jenisF = (string) ($_GET['jenis'] ?? '');
if ($jenisF !== '' && !isset(AGENDA_JENIS[$jenisF])) $jenisF = '';
$hariIni = date('Y-m-d');

$where = ['aktif = 1'];
$par = [];
if ($tampil === 'aktif') {
  $where[] = 'tanggal_selesai >= :h';
  $par[':h'] = $hariIni;
}
if ($tampil === 'lewat') {
  $where[] = 'tanggal_selesai < :h';
  $par[':h'] = $hariIni;
}
if ($jenisF !== '') {
  $where[] = 'jenis = :j';
  $par[':j'] = $jenisF;
}
$urut = $tampil === 'lewat' ? 'tanggal_mulai DESC, id DESC' : 'tanggal_mulai ASC, id ASC';
$st = $db->prepare('SELECT * FROM agenda_sekolah WHERE ' . implode(' AND ', $where) . " ORDER BY $urut LIMIT 300");
$st->execute($par);
$daftar = $st->fetchAll();

$jdHariIni = jadwal_hari($hariIni, null);
$cfg = get_settings();
$bawaan = [
  'buka' => substr(agenda_waktu($cfg['jam_masuk'] ?? '', '06:00:00'), 0, 5),
  'terlambat' => substr(agenda_waktu($cfg['batas_terlambat'] ?? '', '07:15:00'), 0, 5),
  'absen' => substr(agenda_waktu($cfg['batas_absen'] ?? '', '10:00:00'), 0, 5),
];

// Pengelompokan kelas per tingkat (X / XI / XII / lainnya) untuk pilihan cakupan.
$semuaKelas = agenda_daftar_kelas();
$kelompok = [];
foreach ($semuaKelas as $k) {
  $t = preg_match('/^\s*(XII|XI|X)(?![A-Za-z])/i', $k, $m) ? strtoupper($m[1]) : 'Lainnya';
  $kelompok[$t][] = $k;
}
uksort($kelompok, fn($a, $b) => (array_search($a, ['X', 'XI', 'XII', 'Lainnya']) <=> array_search($b, ['X', 'XI', 'XII', 'Lainnya'])));

$statusAgenda = function (array $a) use ($hariIni): array {
  if ($a['tanggal_selesai'] < $hariIni) return ['Selesai', 'secondary'];
  if ($a['tanggal_mulai'] <= $hariIni) return ['Berlangsung', 'success'];
  $selisih = (int) ((strtotime($a['tanggal_mulai']) - strtotime($hariIni)) / 86400);
  return [$selisih === 1 ? 'Besok' : "$selisih hari lagi", 'info'];
};
$jsonAgenda = fn(array $a) => e(json_encode([
  'id' => (int) $a['id'],
  'judul' => $a['judul'],
  'jenis' => $a['jenis'],
  'mulai' => $a['tanggal_mulai'],
  'selesai' => $a['tanggal_selesai'],
  'kelas' => agenda_kelas_daftar($a),
  'buka' => substr((string) $a['jam_buka'], 0, 5),
  'terlambat' => substr((string) $a['batas_terlambat'], 0, 5),
  'absen' => substr((string) $a['batas_absen'], 0, 5),
  'catatan' => (string) $a['catatan'],
], JSON_UNESCAPED_UNICODE));
$mendatang = agenda_mendatang(60, 1);
?>
<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-calendar-event"></i> Agenda Sekolah</h4>
    <div class="small text-muted">Jadwalkan libur, ujian, dan kegiatan agar siswa tidak tercatat Alpa secara keliru.</div>
  </div>
  <?php if ($bolehKelola): ?>
    <button type="button" class="btn btn-primary" id="btnTambahAgenda"><i class="bi bi-plus-lg"></i> Tambah Agenda</button>
  <?php endif; ?>
</div>

<div class="alert alert-<?= $jdHariIni['agenda'] ? ($jdHariIni['jenis'] === 'libur' ? 'danger' : 'primary') : 'light border' ?> d-flex align-items-center gap-2 py-2">
  <i class="bi bi-<?= $jdHariIni['agenda'] ? AGENDA_JENIS[$jdHariIni['jenis']]['ikon'] : 'calendar-check' ?> fs-5"></i>
  <div class="small">
    <b>Hari ini (<?= e(NAMA_HARI[(int) date('N')] . ', ' . agenda_label_tanggal($hariIni, $hariIni)) ?>):</b>
    <?php if ($jdHariIni['agenda']): ?>
      <?= e($jdHariIni['ket']) ?> — <?= e(AGENDA_JENIS[$jdHariIni['jenis']]['efek']) ?><?php if ($jdHariIni['jenis'] !== 'libur'): ?>
      (presensi <?= e(substr($jdHariIni['jam_buka'], 0, 5)) ?>, terlambat setelah <?= e(substr($jdHariIni['batas_terlambat'], 0, 5)) ?>, ditutup <?= e(substr($jdHariIni['batas_absen'], 0, 5)) ?>)<?php endif; ?>.
    <?php elseif (!$jdHariIni['efektif']): ?>
      bukan hari efektif (<?= e(NAMA_HARI[(int) date('N')]) ?>); presensi ditutup.
    <?php else: ?>
      hari efektif biasa — presensi <?= e($bawaan['buka']) ?>, terlambat setelah <?= e($bawaan['terlambat']) ?>, ditutup <?= e($bawaan['absen']) ?>.
    <?php endif; ?>
  </div>
</div>

<style>
  #hasil-live .tbl th,
  #hasil-live .tbl td {
    padding-left: 14px;
    padding-right: 14px;
  }
</style>
<div class="tbl-card">
  <div class="tbl-tools">
    <form method="get" data-live data-live-target="#hasil-live"><?= hidden_modul() ?>
      <select name="tampil" class="form-select">
        <option value="aktif" <?= $tampil === 'aktif' ? 'selected' : '' ?>>Berlangsung &amp; mendatang</option>
        <option value="lewat" <?= $tampil === 'lewat' ? 'selected' : '' ?>>Sudah lewat</option>
        <option value="semua" <?= $tampil === 'semua' ? 'selected' : '' ?>>Semua</option>
      </select>
      <select name="jenis" class="form-select">
        <option value="">Semua jenis</option>
        <?php foreach (AGENDA_JENIS as $kode => $j): ?><option value="<?= $kode ?>" <?= $jenisF === $kode ? 'selected' : '' ?>><?= e($j['singkat']) ?></option><?php endforeach; ?>
      </select>
      <a href="<?= e(modul_url('agenda')) ?>" class="btn btn-light border" title="Hapus filter"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
    </form>
  </div>
  <div id="hasil-live">
    <div class="table-responsive">
      <table class="table tbl align-middle">
        <thead>
          <tr>
            <th>Agenda</th>
            <th>Tanggal &amp; status</th>
            <th>Kelas</th>
            <th>Jadwal absensi</th><?php if ($bolehKelola): ?><th class="text-end" style="width:1%"></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($daftar as $a):
            $j = AGENDA_JENIS[$a['jenis']];
            [$stLabel, $stWarna] = $statusAgenda($a);
            $kls = agenda_kelas_daftar($a);
            $hari = (int) ((strtotime($a['tanggal_selesai']) - strtotime($a['tanggal_mulai'])) / 86400) + 1;
          ?>
            <tr>
              <td>
                <div class="d-flex align-items-start gap-2">
                  <span class="badge bg-<?= $j['warna'] ?> mt-1"><i class="bi bi-<?= $j['ikon'] ?>"></i></span>
                  <div style="min-width:160px;max-width:230px">
                    <div class="fw-semibold"><?= e($a['judul']) ?></div>
                    <div class="small text-muted"><?= e($j['singkat']) ?><?= $a['catatan'] ? ' · ' . e($a['catatan']) : '' ?></div>
                  </div>
                </div>
              </td>
              <td class="text-nowrap"><?= e(agenda_label_tanggal($a['tanggal_mulai'], $a['tanggal_selesai'])) ?><div class="small text-muted"><?= $hari ?> hari · <span class="badge bg-<?= $stWarna ?>"><?= e($stLabel) ?></span></div>
              </td>
              <td><?php if (!$kls): ?>Semua kelas<?php else: ?><span title="<?= e(implode(', ', $kls)) ?>"><?= count($kls) <= 2 ? e(implode(', ', $kls)) : e(implode(', ', array_slice($kls, 0, 2))) . ' +' . (count($kls) - 2) ?></span><?php endif; ?></td>
              <td class="small">
                <?php if ($a['jenis'] === 'libur'): ?>
                  <div class="text-nowrap"><i class="bi bi-slash-circle text-danger"></i> Presensi ditutup</div>
                  <div class="text-muted text-nowrap">tidak menghitung Alpa</div>
                <?php else: ?>
                  <div class="text-nowrap">Buka <b><?= e(substr((string) $a['jam_buka'], 0, 5)) ?></b> – tutup <b><?= e(substr((string) $a['batas_absen'], 0, 5)) ?></b></div>
                  <div class="text-muted text-nowrap">terlambat setelah <?= e(substr((string) $a['batas_terlambat'], 0, 5)) ?></div>
                <?php endif; ?>
              </td>
              <?php if ($bolehKelola): ?>
                <td class="text-end text-nowrap">
                  <button type="button" class="btn btn-sm btn-outline-primary btn-ubah-agenda" data-agenda="<?= $jsonAgenda($a) ?>"><i class="bi bi-pencil"></i> Ubah</button>
                  <form method="post" class="d-inline" onsubmit="return confirm('Hapus agenda &quot;<?= e(addslashes($a['judul'])) ?>&quot;? Jadwal absensi kembali normal pada tanggal itu.')">
                    <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" title="Hapus"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$daftar): ?>
            <tr>
              <td colspan="<?= $bolehKelola ? 5 : 4 ?>" class="text-center text-muted py-4">
                <?= $tampil === 'aktif' ? 'Belum ada agenda mendatang. Klik Tambah Agenda untuk menjadwalkan libur, ujian, atau kegiatan agar siswa tidak tercatat Alpa.' : 'Tidak ada agenda yang cocok.' ?>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="tbl-foot"><span class="text-muted small"><?= count($daftar) ?> agenda</span></div>
  </div>
</div>

<?php if ($bolehKelola): ?>
  <div class="modal fade" id="modalAgenda" tabindex="-1" aria-labelledby="judulAgenda" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <form method="post" class="modal-content" id="formAgenda">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="simpan">
        <input type="hidden" name="id" id="agId" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="judulAgenda">Tambah agenda</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
        <div class="modal-body">
          <label class="form-label">Jenis agenda</label>
          <div class="row g-2 mb-3" id="agJenis">
            <?php foreach (AGENDA_JENIS as $kode => $j): ?>
              <div class="col-md-4">
                <input type="radio" class="btn-check" name="jenis" id="agJ_<?= $kode ?>" value="<?= $kode ?>" required>
                <label class="btn btn-outline-<?= $j['warna'] === 'warning' ? 'dark' : $j['warna'] ?> w-100 text-start h-100" for="agJ_<?= $kode ?>">
                  <div class="fw-semibold"><i class="bi bi-<?= $j['ikon'] ?>"></i> <?= e($j['singkat']) ?></div>
                  <div class="small"><?= e($j['label']) ?></div>
                  <div class="small opacity-75"><?= e($j['efek']) ?></div>
                </label>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="mb-3">
            <label class="form-label" for="agJudul">Nama agenda <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="judul" id="agJudul" maxlength="<?= AGENDA_JUDUL_MAKS ?>" required placeholder="Mis. Ujian Madrasah Semester Ganjil / Libur Maulid Nabi / Outing Class XI">
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label" for="agMulai">Tanggal mulai</label><input type="date" class="form-control" name="tanggal_mulai" id="agMulai" required></div>
            <div class="col-6"><label class="form-label" for="agSelesai">Tanggal selesai</label><input type="date" class="form-control" name="tanggal_selesai" id="agSelesai" required></div>
          </div>

          <div class="mb-3">
            <label class="form-label">Berlaku untuk</label>
            <div class="mb-2">
              <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="cakupan" id="agCakSemua" value="semua" checked><label class="form-check-label" for="agCakSemua">Semua kelas</label></div>
              <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="cakupan" id="agCakKelas" value="kelas"><label class="form-check-label" for="agCakKelas">Kelas tertentu</label></div>
            </div>
            <div id="agKelasBox" class="border rounded p-2 d-none" style="max-height:200px;overflow:auto">
              <?php foreach ($kelompok as $tingkat => $list): ?>
                <div class="mb-2">
                  <div class="form-check"><input class="form-check-input ag-tingkat" type="checkbox" id="agT_<?= e($tingkat) ?>" data-tingkat="<?= e($tingkat) ?>"><label class="form-check-label fw-semibold" for="agT_<?= e($tingkat) ?>"><?= $tingkat === 'Lainnya' ? 'Lainnya' : 'Tingkat ' . e($tingkat) ?> <span class="text-muted fw-normal">(<?= count($list) ?> kelas)</span></label></div>
                  <div class="d-flex flex-wrap gap-2 ps-4">
                    <?php foreach ($list as $i => $k): ?>
                      <div class="form-check"><input class="form-check-input ag-kelas" type="checkbox" name="kelas[]" value="<?= e($k) ?>" id="agK_<?= e($tingkat) . $i ?>" data-tingkat="<?= e($tingkat) ?>"><label class="form-check-label" for="agK_<?= e($tingkat) . $i ?>"><?= e($k) ?></label></div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (!$kelompok): ?><div class="text-muted small">Belum ada data kelas.</div><?php endif; ?>
            </div>
          </div>

          <div id="agJam" class="border rounded p-3 mb-3 d-none">
            <div class="fw-semibold mb-2"><i class="bi bi-alarm"></i> Jadwal absensi khusus <span class="small fw-normal text-muted">(menggantikan jadwal biasa pada tanggal ini)</span></div>
            <div class="row g-2">
              <div class="col-4"><label class="form-label small" for="agBuka">Presensi dibuka</label><input type="time" class="form-control" name="jam_buka" id="agBuka"></div>
              <div class="col-4"><label class="form-label small" for="agTerlambat">Terlambat setelah</label><input type="time" class="form-control" name="batas_terlambat" id="agTerlambat"></div>
              <div class="col-4"><label class="form-label small" for="agAbsen">Absen ditutup (Alpa)</label><input type="time" class="form-control" name="batas_absen" id="agAbsen"></div>
            </div>
            <div class="form-text">Presensi juga dibuka pada hari yang biasanya libur (mis. ujian di hari Sabtu). Siswa yang tidak absen sampai jam ditutup dicatat Alpa.</div>
          </div>

          <div id="agLiburInfo" class="alert alert-danger py-2 small d-none">
            <i class="bi bi-slash-circle"></i> Presensi ditutup dan <b>tidak ada siswa yang dicatat Alpa</b> pada tanggal ini.
            <div class="form-check mt-1" id="agHapusAlpaBox"><input class="form-check-input" type="checkbox" name="hapus_alpa" value="1" id="agHapusAlpa" checked><label class="form-check-label" for="agHapusAlpa">Hapus catatan Alpa otomatis yang sudah terlanjur tercatat pada tanggal ini</label></div>
          </div>

          <div>
            <label class="form-label" for="agCatatan">Catatan (opsional)</label>
            <input type="text" class="form-control" name="catatan" id="agCatatan" maxlength="300" placeholder="Mis. sesuai SK Kementerian Agama / lokasi kegiatan">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
          <button class="btn btn-primary"><i class="bi bi-check2-circle"></i> Simpan agenda</button>
        </div>
      </form>
    </div>
  </div>
  <script>
    (function() {
      var $ = function(id) {
        return document.getElementById(id);
      };
      var BAWAAN = <?= json_encode($bawaan) ?>,
        HARI_INI = '<?= $hariIni ?>';

      function aturJenis() {
        var j = (document.querySelector('input[name=jenis]:checked') || {}).value || '';
        var khusus = j === 'ujian' || j === 'kegiatan';
        $('agJam').classList.toggle('d-none', !khusus);
        ['agBuka', 'agTerlambat', 'agAbsen'].forEach(function(i) {
          $(i).required = khusus;
        });
        $('agLiburInfo').classList.toggle('d-none', j !== 'libur');
        $('agHapusAlpaBox').classList.toggle('d-none', !($('agMulai').value && $('agMulai').value <= HARI_INI));
        if (khusus && !$('agBuka').value) {
          $('agBuka').value = BAWAAN.buka;
          $('agTerlambat').value = BAWAAN.terlambat;
          $('agAbsen').value = BAWAAN.absen;
        }
      }

      function aturCakupan() {
        $('agKelasBox').classList.toggle('d-none', !$('agCakKelas').checked);
      }

      function sinkronTingkat() {
        document.querySelectorAll('.ag-tingkat').forEach(function(t) {
          var anak = document.querySelectorAll('.ag-kelas[data-tingkat="' + t.dataset.tingkat + '"]');
          t.checked = anak.length > 0 && Array.prototype.every.call(anak, function(c) {
            return c.checked;
          });
        });
      }

      function buka(data, judul) {
        $('formAgenda').reset();
        $('agId').value = data.id || '';
        $('judulAgenda').textContent = judul;
        $('agJudul').value = data.judul || '';
        $('agMulai').value = data.mulai || HARI_INI;
        $('agSelesai').value = data.selesai || data.mulai || HARI_INI;
        document.querySelectorAll('input[name=jenis]').forEach(function(r) {
          r.checked = r.value === (data.jenis || '');
        });
        var adaKelas = data.kelas && data.kelas.length;
        $('agCakKelas').checked = !!adaKelas;
        $('agCakSemua').checked = !adaKelas;
        document.querySelectorAll('.ag-kelas').forEach(function(c) {
          c.checked = adaKelas && data.kelas.indexOf(c.value) > -1;
        });
        $('agBuka').value = data.buka || '';
        $('agTerlambat').value = data.terlambat || '';
        $('agAbsen').value = data.absen || '';
        $('agCatatan').value = data.catatan || '';
        sinkronTingkat();
        aturCakupan();
        aturJenis();
        bootstrap.Modal.getOrCreateInstance($('modalAgenda')).show();
      }
      document.querySelectorAll('input[name=jenis]').forEach(function(r) {
        r.addEventListener('change', aturJenis);
      });
      $('agMulai').addEventListener('change', function() {
        if (!$('agSelesai').value || $('agSelesai').value < $('agMulai').value) $('agSelesai').value = $('agMulai').value;
        aturJenis();
      });
      $('agCakSemua').addEventListener('change', aturCakupan);
      $('agCakKelas').addEventListener('change', aturCakupan);
      document.querySelectorAll('.ag-tingkat').forEach(function(t) {
        t.addEventListener('change', function() {
          document.querySelectorAll('.ag-kelas[data-tingkat="' + t.dataset.tingkat + '"]').forEach(function(c) {
            c.checked = t.checked;
          });
        });
      });
      document.querySelectorAll('.ag-kelas').forEach(function(c) {
        c.addEventListener('change', sinkronTingkat);
      });
      document.addEventListener('click', function(e) {
        var u = e.target.closest('.btn-ubah-agenda'),
          t = e.target.closest('#btnTambahAgenda');
        if (u) {
          buka(JSON.parse(u.dataset.agenda), 'Ubah agenda');
        } else if (t) {
          buka({}, 'Tambah agenda');
        }
      });
    })();
  </script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>