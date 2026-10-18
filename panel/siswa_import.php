<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/xlsx_reader.php';

$hasil = null;
$errors = [];

/**
 * Proses satu baris data (array kolom index 0..8) menjadi INSERT siswa.
 * Dipakai bersama oleh alur CSV maupun XLSX agar aturan validasinya konsisten.
 *
 * Return array detail (BUKAN cuma bool) supaya baris yang dilewati bisa
 * dilaporkan dgn jelas ke admin -- termasuk NIS/NISN mana yang bentrok --
 * bukan cuma angka "sekian baris dilewati" tanpa keterangan.
 *   ['status' => 'sukses'|'gagal', 'alasan' => string, 'nis' => string]
 *
 * $cekNis dan $cekNisn mengecek baik data yang SUDAH ada di database
 * MAUPUN baris lain dalam file yang sama yang baru saja disimpan pada
 * iterasi sebelumnya (krn cek dijalankan tepat sebelum INSERT tiap baris).
 */
function proses_baris_import_siswa(array $row, int $nomorBaris, PDOStatement $insert, PDOStatement $cekNis, PDOStatement $cekNisn): array
{
  if (count($row) < 7 || trim((string) ($row[0] ?? '')) === '') {
    return ['status' => 'gagal', 'alasan' => "Baris $nomorBaris: data tidak lengkap (kolom wajib kosong).", 'nis' => ''];
  }

  [$nis, $nisn, $nama, $jk, $tempat, $tgl, $kelas, $jurusan, $tahun] = array_pad(array_values($row), 9, '');
  $nis = trim((string) $nis);
  $nisn = trim((string) $nisn);
  $nama = trim((string) $nama);
  $kelas = trim((string) $kelas);
  $tgl = xlsx_normalize_possible_date(trim((string) $tgl)); // aman dipanggil utk CSV juga (tak berefek pada teks biasa)

  if ($nis === '' || $nama === '' || $kelas === '') {
    return ['status' => 'gagal', 'alasan' => "Baris $nomorBaris (NIS \"$nis\"): data tidak lengkap — NIS/nama/kelas ada yang kosong.", 'nis' => $nis];
  }

  $cekNis->execute([':nis' => $nis]);
  if ($cekNis->fetch()) {
    return ['status' => 'gagal', 'alasan' => "Baris $nomorBaris: NIS \"$nis\" duplikat (sudah dipakai siswa lain).", 'nis' => $nis];
  }

  if ($nisn !== '') {
    $cekNisn->execute([':nisn' => $nisn]);
    if ($cekNisn->fetch()) {
      return ['status' => 'gagal', 'alasan' => "Baris $nomorBaris (NIS \"$nis\"): NISN \"$nisn\" duplikat (sudah dipakai siswa lain).", 'nis' => $nis];
    }
  }

  $insert->execute([
    ':nis' => $nis,
    ':nisn' => $nisn ?: null,
    ':nama' => $nama,
    ':jk' => in_array(strtoupper((string) $jk), ['L', 'P']) ? strtoupper((string) $jk) : 'L',
    ':tempat' => $tempat ?: null,
    ':tgl' => $tgl ?: null,
    ':kelas' => $kelas,
    ':jurusan' => $jurusan ?: null,
    ':tahun' => $tahun ?: (get_settings()['tahun_pelajaran'] ?? '2026/2027'),
    ':token' => generate_token(32),
  ]);
  return ['status' => 'sukses', 'alasan' => '', 'nis' => $nis];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_require();

  if (empty($_FILES['file']['name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = 'Silakan pilih file CSV atau XLSX terlebih dahulu.';
  } else {
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ['csv', 'xlsx'], true)) {
      $errors[] = 'Format file tidak didukung. Gunakan file .csv atau .xlsx.';
    } else {
      $db = getDB();
      $insert = $db->prepare(
        'INSERT INTO siswa (nis, nisn, nama, jenis_kelamin, tempat_lahir, tanggal_lahir, kelas, jurusan, tahun_pelajaran, qr_token, status, created_at, updated_at)
                 VALUES (:nis, :nisn, :nama, :jk, :tempat, :tgl, :kelas, :jurusan, :tahun, :token, "aktif", NOW(), NOW())'
      );
      $cekNis = $db->prepare('SELECT id FROM siswa WHERE nis = :nis');
      $cekNisn = $db->prepare('SELECT id FROM siswa WHERE nisn = :nisn');
      $sukses = 0;
      $dilewati = []; // daftar alasan per baris yg dilewati, ditampilkan detail ke admin

      if ($ext === 'xlsx') {
        try {
          $rows = read_xlsx_first_sheet($_FILES['file']['tmp_name']);
        } catch (RuntimeException $e) {
          $errors[] = $e->getMessage();
          $rows = [];
        }

        // Lewati baris pertama (header)
        array_shift($rows);

        $nomorBaris = 1; // baris 1 = header, jadi data dimulai dari baris 2
        foreach ($rows as $row) {
          $nomorBaris++;
          if (empty($row)) continue; // baris benar-benar kosong di Excel, tidak dihitung apa-apa
          $hasilBaris = proses_baris_import_siswa($row, $nomorBaris, $insert, $cekNis, $cekNisn);
          if ($hasilBaris['status'] === 'sukses') $sukses++;
          else $dilewati[] = $hasilBaris['alasan'];
        }
      } else { // csv
        $handle = fopen($_FILES['file']['tmp_name'], 'r');
        if ($handle === false) {
          $errors[] = 'Gagal membuka file CSV.';
        } else {
          fgetcsv($handle); // lewati baris header
          $nomorBaris = 1;
          while (($row = fgetcsv($handle)) !== false) {
            $nomorBaris++;
            $hasilBaris = proses_baris_import_siswa($row, $nomorBaris, $insert, $cekNis, $cekNisn);
            if ($hasilBaris['status'] === 'sukses') $sukses++;
            else $dilewati[] = $hasilBaris['alasan'];
          }
          fclose($handle);
        }
      }

      if (!$errors) {
        $hasil = ['sukses' => $sukses, 'dilewati' => $dilewati];
        catat_aktivitas('IMPOR', 'siswa_import', 'Impor siswa dari file ' . substr(basename($_FILES['file']['name'] ?? ''), 0, 100) . ': ' . $sukses . ' berhasil, ' . count($dilewati) . ' dilewati');
      }
    }
  }
}
?>
<style>
  .im-dz { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; text-align: center; cursor: pointer;
           border: 2px dashed var(--siap-line); border-radius: 16px; padding: 34px 18px; background: var(--siap-bg); transition: border-color .15s, background .15s; }
  .im-dz:hover, .im-dz.seret { border-color: var(--siap-blue); background: var(--siap-info-bg); }
  .im-dz > i { font-size: 2.6rem; color: var(--siap-blue); line-height: 1; }
  .im-dz .t { font-weight: 800; color: var(--siap-ink); margin-top: 6px; }
  .im-dz .s { color: var(--siap-muted); font-size: .86rem; }
  .im-dz .nama { margin-top: 10px; font-weight: 700; color: var(--siap-success-text); background: var(--siap-success-bg); border-radius: 999px; padding: 4px 14px; display: none; word-break: break-all; }
  .im-hasil { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .im-tile { border-radius: 14px; padding: 16px; text-align: center; }
  .im-tile .a { font-size: 2rem; font-weight: 800; line-height: 1.1; }
  .im-tile .l { font-size: .84rem; font-weight: 700; }
  .im-tile.ok { background: var(--siap-success-bg); color: var(--siap-success-text); } .im-tile.lewat { background: var(--siap-warning-bg); color: var(--siap-warning-text); } .im-tile.nol { background: var(--siap-bg); color: var(--siap-muted); }
  .im-kolom { display: flex; flex-wrap: wrap; gap: 7px; }
  .im-kolom span { font-family: ui-monospace, Consolas, monospace; font-size: .82rem; padding: 3px 10px; border-radius: 8px; background: var(--siap-bg); color: var(--siap-body); }
  .im-kolom span.wajib { background: var(--siap-info-bg); color: var(--siap-blue); font-weight: 700; }
  .im-kolom span.wajib::after { content: " *"; color: var(--siap-danger-text); }
  .im-cat { margin: 0; padding-left: 18px; color: var(--siap-muted); font-size: .88rem; display: grid; gap: 6px; }
  details.im-detail summary { cursor: pointer; color: var(--siap-blue); font-weight: 600; font-size: .9rem; }
  details.im-detail summary::-webkit-details-marker { display: none; }
  @media (max-width: 575px) { .im-dz { padding: 26px 14px; } }
</style>

<h4 class="mb-3"><i class="bi bi-upload"></i> Import Siswa</h4>

<?php if ($errors): ?>
  <div class="alert alert-danger"><?php foreach ($errors as $err) echo e($err) . '<br>'; ?></div>
<?php endif; ?>

<?php if ($hasil): ?>
  <div class="tbl-card mb-3">
    <div class="p-3 p-md-4">
      <div class="im-hasil">
        <div class="im-tile ok"><div class="a"><?= (int) $hasil['sukses'] ?></div><div class="l">Siswa berhasil ditambahkan</div></div>
        <div class="im-tile <?= count($hasil['dilewati']) ? 'lewat' : 'nol' ?>"><div class="a"><?= count($hasil['dilewati']) ?></div><div class="l">Baris dilewati</div></div>
      </div>
      <?php if (!empty($hasil['dilewati'])): ?>
        <details class="im-detail mt-3">
          <summary><i class="bi bi-chevron-down"></i> Lihat rincian baris yang dilewati</summary>
          <ul class="small mt-2 mb-0" style="max-height:260px;overflow-y:auto">
            <?php foreach ($hasil['dilewati'] as $alasan): ?><li><?= e($alasan) ?></li><?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="tbl-card h-100">
      <div class="tbl-head">
        <h6>Unggah file</h6>
        <div class="sub">Format .csv atau .xlsx, maksimal data sesuai batas server.</div>
      </div>
      <div class="p-3 p-md-4">
        <form method="post" enctype="multipart/form-data" id="formImport">
          <?= csrf_field() ?>
          <label class="im-dz" id="dz" for="berkas">
            <i class="bi bi-cloud-arrow-up"></i>
            <div class="t">Klik untuk memilih file</div>
            <div class="s">atau seret file ke sini</div>
            <div class="nama" id="namaBerkas"></div>
          </label>
          <input type="file" name="file" id="berkas" accept=".csv,.xlsx" required hidden>
          <button type="submit" class="siap-btn siap-btn-blue siap-mono-xs w-100 mt-3" id="btnImport" disabled><i class="bi bi-upload"></i> Import Sekarang</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="tbl-card h-100">
      <div class="tbl-head">
        <h6>Format kolom</h6>
        <div class="sub">Baris pertama harus header (dilewati otomatis).</div>
      </div>
      <div class="p-3 p-md-4">
        <div class="im-kolom mb-3">
          <span class="wajib">nis</span><span>nisn</span><span class="wajib">nama</span><span>jenis_kelamin</span><span>tempat_lahir</span>
          <span>tanggal_lahir</span><span class="wajib">kelas</span><span>jurusan</span><span>tahun_pelajaran</span>
        </div>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <button type="button" class="siap-btn siap-btn-ghost siap-mono-xs btn-sm" id="btnTemplate"><i class="bi bi-download"></i> Unduh template</button>
        </div>
        <ul class="im-cat">
          <li><span class="text-danger">*</span> wajib diisi, kolom lain boleh kosong.</li>
          <li>NIS/NISN yang sudah ada otomatis dilewati dan tidak menimpa data lama.</li>
          <li>.xlsx: hanya sheet pertama yang dibaca. .csv: simpan sebagai <strong>UTF-8</strong>.</li>
        </ul>
        <details class="im-detail mt-3">
          <summary><i class="bi bi-chevron-down"></i> Lihat contoh baris</summary>
          <div class="table-responsive mt-2">
            <table class="table table-sm table-bordered small mb-0">
              <tbody>
                <tr><th>nis</th><td>231011</td></tr><tr><th>nisn</th><td>0051234571</td></tr><tr><th>nama</th><td>Contoh Nama</td></tr>
                <tr><th>jenis_kelamin</th><td>L</td></tr><tr><th>tempat_lahir</th><td>Jember</td></tr><tr><th>tanggal_lahir</th><td>2009-01-01</td></tr>
                <tr><th>kelas</th><td>XI-A</td></tr><tr><th>jurusan</th><td>IPA</td></tr><tr><th>tahun_pelajaran</th><td>2026/2027</td></tr>
              </tbody>
            </table>
          </div>
        </details>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var input = document.getElementById('berkas'), dz = document.getElementById('dz'),
      nama = document.getElementById('namaBerkas'), btn = document.getElementById('btnImport');
  function tampil() {
    var f = input.files && input.files[0];
    nama.style.display = f ? 'block' : 'none';
    nama.textContent = f ? f.name : '';
    btn.disabled = !f;
  }
  input.addEventListener('change', tampil);
  ['dragenter', 'dragover'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('seret'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('seret'); }); });
  dz.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; tampil(); }
  });
  document.getElementById('formImport').addEventListener('submit', function () {
    setTimeout(function () { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Mengimpor…'; }, 0);
  });
  document.getElementById('btnTemplate').addEventListener('click', function () {
    var csv = '\ufeffnis,nisn,nama,jenis_kelamin,tempat_lahir,tanggal_lahir,kelas,jurusan,tahun_pelajaran\r\n231011,0051234571,Contoh Nama,L,Jember,2009-01-01,XI-A,IPA,2026/2027\r\n';
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    a.download = 'template-import-siswa.csv';
    document.body.appendChild(a); a.click(); a.remove();
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>