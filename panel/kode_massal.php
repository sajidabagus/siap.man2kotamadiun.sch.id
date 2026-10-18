<?php
// Kode Pasangan Massal: buat kode pendaftaran perangkat untuk banyak siswa sekaligus
// (per kelas / jenjang / semua / daftar NIS-NISN), lalu cetak, unduh, atau salin untuk dibagikan.
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/device_binding.php';
siapkan_tabel_kode_pasangan();

$db = getDB();
$admin = current_admin();

$opsiMasa = [
  '10' => '10 menit', '30' => '30 menit', '60' => '1 jam', '360' => '6 jam',
  '1440' => '1 hari', '10080' => '7 hari (maksimum)',
];
$masaPilih = '1440';
$sasaran = ['jenjang' => '', 'kelas' => '', 'daftar' => ''];
$sasaranPilih = 'semua';
$hasil = null;
$errors = [];

// ---------- Batalkan semua kode aktif ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'batalkan') {
  csrf_require();
  $st = $db->prepare('UPDATE device_pairing_codes SET used_at = NOW() WHERE used_at IS NULL');
  $st->execute();
  catat_aktivitas('HAPUS', 'kode_massal', 'Membatalkan semua Kode Pasangan yang belum terpakai (' . $st->rowCount() . ' kode)');
  set_flash('success', $st->rowCount() . ' kode yang belum terpakai telah dibatalkan.');
  header('Location: ' . modul_url('kode_massal'));
  exit;
}

// ---------- Buat kode ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'buat') {
  csrf_require();
  $masaPilih = isset($opsiMasa[$_POST['masa'] ?? '']) ? (string) $_POST['masa'] : '1440';
  $menit = (int) $masaPilih;
  $sasaranPilih = (string) ($_POST['sasaran'] ?? 'semua');
  if ($sasaranPilih === 'daftar') {
    $sasaran['daftar'] = trim((string) ($_POST['daftar'] ?? ''));
    if ($sasaran['daftar'] === '') $errors[] = 'Tempel minimal satu NIS/NISN.';
  } elseif (strncmp($sasaranPilih, 'jenjang:', 8) === 0) {
    $sasaran['jenjang'] = clean(substr($sasaranPilih, 8));
  } elseif (strncmp($sasaranPilih, 'kelas:', 6) === 0) {
    $sasaran['kelas'] = clean(substr($sasaranPilih, 6));
  }

  $siswa = [];
  $tidakDitemukan = [];
  if ($errors) {
    // sasaran tidak valid: tidak ada yang diproses
  } elseif ($sasaran['daftar'] !== '') {
    $tokens = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', $sasaran['daftar']))));
    if (count($tokens) > 2000) $errors[] = 'Maksimal 2000 NIS/NISN per proses.';
    foreach (array_slice($tokens, 0, 2000) as $t) {
      $st = $db->prepare("SELECT id, nis, nisn, nama, kelas FROM siswa WHERE (nis = :a OR nisn = :b) AND status = 'aktif' LIMIT 1");
      $st->execute([':a' => $t, ':b' => $t]);
      $r = $st->fetch();
      if ($r) $siswa[$r['id']] = $r; else $tidakDitemukan[] = $t;
    }
  } else {
    $sql = "SELECT id, nis, nisn, nama, kelas FROM siswa WHERE status = 'aktif'";
    $par = [];
    if ($sasaran['kelas'] !== '') { $sql .= ' AND kelas = :k'; $par[':k'] = $sasaran['kelas']; }
    $sql .= ' ORDER BY kelas, nama';
    $st = $db->prepare($sql);
    $st->execute($par);
    foreach ($st->fetchAll() as $r) {
      if ($sasaran['kelas'] === '' && $sasaran['jenjang'] !== '' && ambil_jenjang($r['kelas']) !== $sasaran['jenjang']) continue;
      $siswa[$r['id']] = $r;
    }
  }
  if (!$siswa && !$errors) $errors[] = 'Tidak ada siswa yang cocok dengan pilihan Anda.';

  if (!$errors) {
    // Siswa yang sudah punya perangkat terdaftar dilewati
    $sudahPunya = [];
    foreach (array_chunk(array_keys($siswa), 500) as $chunk) {
      $in = implode(',', array_fill(0, count($chunk), '?'));
      $st = $db->prepare("SELECT DISTINCT siswa_id FROM device_bindings WHERE siswa_id IN ($in)");
      $st->execute($chunk);
      foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $sudahPunya[(int) $id] = true;
    }
    $target = array_values(array_filter(array_keys($siswa), fn($id) => !isset($sudahPunya[(int) $id])));
    $kodeMap = $target ? buat_kode_pasangan_massal($target, (int) ($admin['id'] ?? 0), $menit) : [];

    $baris = [];
    foreach ($kodeMap as $id => $kode) {
      $s = $siswa[$id];
      $baris[] = ['nis' => $s['nis'], 'nisn' => $s['nisn'] ?? '', 'nama' => $s['nama'], 'kelas' => $s['kelas'], 'kode' => $kode];
    }
    usort($baris, fn($a, $b) => strcmp($a['kelas'] . $a['nama'], $b['kelas'] . $b['nama']));

    $masaTeks = $opsiMasa[$masaPilih];
    $hasil = [
      'baris' => $baris, 'dilewati' => count($sudahPunya), 'tidak_ditemukan' => $tidakDitemukan,
      'masa' => $masaTeks, 'tanpa_batas' => $menit === null,
      'sampai' => $menit === null ? null : date('d/m/Y H:i', time() + $menit * 60),
    ];
    if ($baris) {
      $cakupan = $sasaran['daftar'] !== '' ? 'daftar NIS/NISN' : ($sasaran['kelas'] !== '' ? 'kelas ' . $sasaran['kelas'] : ($sasaran['jenjang'] !== '' ? 'jenjang ' . $sasaran['jenjang'] : 'semua siswa'));
      catat_aktivitas('GENERATE', 'kode_massal', 'Membuat ' . count($baris) . ' Kode Pasangan massal (' . $cakupan . ', berlaku: ' . $masaTeks . ')');
    }
  }
}

// ---------- Data pendukung tampilan ----------
$kelasList = $db->query("SELECT DISTINCT kelas FROM siswa WHERE status = 'aktif' ORDER BY kelas")->fetchAll(PDO::FETCH_COLUMN);
$jenjangList = array_values(array_unique(array_map('ambil_jenjang', $kelasList)));
$aktifCount = (int) $db->query("SELECT COUNT(DISTINCT siswa_id) FROM device_pairing_codes WHERE used_at IS NULL AND expires_at > NOW()")->fetchColumn();
$settingsKode = get_settings();
?>
<h4 class="mb-3"><i class="bi bi-key"></i> Kode Pasangan</h4>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
<?php endif; ?>

<?php if (!device_wajib_kode_aktif($settingsKode)): ?>
  <div class="text-muted small mb-3"><i class="bi bi-info-circle"></i> Mode <strong>Wajib Kode Pasangan</strong> belum aktif. <a href="<?= modul_url('pengaturan') ?>">Aktifkan di Pengaturan</a> agar kode ini diperlukan.</div>
<?php endif; ?>

<div class="tbl-card mb-3">
  <div class="tbl-head">
    <h6>Buat kode pasangan</h6>
    <div class="sub">Satu kode per siswa, sekali pakai. Siswa mendaftar lewat <em>Daftarkan Perangkat</em> dengan NIS/NISN dan kodenya.</div>
  </div>
  <div class="p-3 p-md-4">
    <form method="post" class="row g-3 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="buat">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Untuk</label>
        <select name="sasaran" id="selSasaran" class="form-select">
          <option value="semua">Semua siswa</option>
          <optgroup label="Jenjang">
            <?php foreach ($jenjangList as $j): ?><option value="jenjang:<?= e($j) ?>" <?= $sasaranPilih === 'jenjang:' . $j ? 'selected' : '' ?>>Jenjang <?= e($j) ?></option><?php endforeach; ?>
          </optgroup>
          <optgroup label="Kelas">
            <?php foreach ($kelasList as $k): ?><option value="kelas:<?= e($k) ?>" <?= $sasaranPilih === 'kelas:' . $k ? 'selected' : '' ?>>Kelas <?= e($k) ?></option><?php endforeach; ?>
          </optgroup>
          <option value="daftar" <?= $sasaranPilih === 'daftar' ? 'selected' : '' ?>>Daftar NIS/NISN…</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Berlaku</label>
        <select name="masa" class="form-select">
          <?php foreach ($opsiMasa as $v => $t): ?><option value="<?= e((string) $v) ?>" <?= (string) $masaPilih === (string) $v ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="siap-btn siap-btn-blue siap-mono-xs w-100"><i class="bi bi-key"></i> Buat</button>
      </div>
      <div class="col-12 d-none" id="boxDaftar">
        <textarea name="daftar" class="form-control" rows="3" placeholder="Tempel NIS/NISN, satu per baris"><?= e($sasaran['daftar']) ?></textarea>
      </div>
    </form>
  </div>
</div>

<?php if ($hasil): ?>
  <div class="tbl-card mb-3" id="hasilKode">
    <div class="tbl-head d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h6><?= count($hasil['baris']) ?> kode dibuat</h6>
        <div class="sub">
          <?= $hasil['tanpa_batas'] ? 'Tanpa batas waktu' : 'Berlaku sampai ' . e($hasil['sampai']) ?> &middot; sekali pakai
          <?php if ($hasil['dilewati']): ?>&middot; <?= (int) $hasil['dilewati'] ?> dilewati (sudah punya perangkat)<?php endif; ?>
        </div>
      </div>
      <?php if ($hasil['baris']): ?>
      <div class="d-flex flex-wrap gap-2">
        <button type="button" class="siap-btn siap-btn-ghost siap-mono-xs btn-sm" onclick="salinSemua(this)"><i class="bi bi-clipboard"></i> Salin</button>
        <button type="button" class="siap-btn siap-btn-ghost siap-mono-xs btn-sm" onclick="unduhExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button type="button" class="siap-btn siap-btn-blue siap-mono-xs btn-sm" onclick="cetakKode()"><i class="bi bi-printer"></i> Cetak</button>
      </div>
      <?php endif; ?>
    </div>

    <div class="px-3 pt-3 small text-muted">
      <i class="bi bi-exclamation-circle"></i> Kode hanya tampil di halaman ini. Cetak atau simpan sekarang.
      <?php if ($hasil['tidak_ditemukan']): ?>
        <div class="text-danger mt-1">Tidak ditemukan (<?= count($hasil['tidak_ditemukan']) ?>): <?= e(implode(', ', array_slice($hasil['tidak_ditemukan'], 0, 30))) ?><?= count($hasil['tidak_ditemukan']) > 30 ? ', …' : '' ?></div>
      <?php endif; ?>
    </div>

    <?php if ($hasil['baris']): ?>
    <div class="table-responsive">
      <table class="table tbl tbl-wrap tbl-fit align-middle" id="tabelKode">
        <thead><tr><th style="width:1%">No</th><th>NISN / NIS</th><th>Nama</th><th>Kelas</th><th>Kode</th></tr></thead>
        <tbody>
          <?php foreach ($hasil['baris'] as $i => $b): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td><?= e($b['nisn'] !== '' && $b['nisn'] !== null ? $b['nisn'] : $b['nis']) ?></td>
              <td class="fw-semibold"><?= e($b['nama']) ?></td>
              <td><span class="pill"><?= e($b['kelas']) ?></span></td>
              <td><span style="font-family:ui-monospace,Consolas,monospace;font-weight:800;letter-spacing:.12em;font-size:1.05rem"><?= e(substr($b['kode'], 0, 3) . ' ' . substr($b['kode'], 3)) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="p-4 text-center text-muted">Tidak ada kode yang dibuat (semua siswa terpilih sudah punya perangkat).</div>
    <?php endif; ?>
  </div>

  <script type="application/json" id="dataKode"><?= json_encode([
    'sekolah' => $settingsKode['nama_sekolah'] ?? '', 'alamat' => $settingsKode['alamat_sekolah'] ?? '',
    'logo' => (!empty($settingsKode['logo']) && is_file(__DIR__ . '/../uploads/siswa/' . $settingsKode['logo'])) ? BASE_URL . '/uploads/siswa/' . rawurlencode($settingsKode['logo']) : '',
    'tanggal' => date('d/m/Y H:i'), 'situs' => BASE_URL . '/',
    'masa' => $hasil['tanpa_batas'] ? 'Tanpa batas waktu' : 'Sampai ' . $hasil['sampai'],
    'baris' => array_map(fn($b) => [$b['nama'], $b['kelas'], $b['kode']], $hasil['baris']),
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>

<?php if ($aktifCount > 0): ?>
  <form method="post" class="d-flex flex-wrap justify-content-between align-items-center gap-2 small text-muted px-1" onsubmit="return confirm('Batalkan SEMUA kode pasangan yang belum terpakai?')">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="batalkan">
    <span><strong><?= $aktifCount ?></strong> siswa masih punya kode aktif.</span>
    <button type="submit" class="siap-btn siap-btn-danger siap-mono-xs btn-sm">Batalkan semua kode</button>
  </form>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Kotak NIS/NISN hanya muncul bila memilih "Daftar NIS/NISN…"
  var sel = document.getElementById('selSasaran'), box = document.getElementById('boxDaftar');
  function togel() { if (sel && box) box.classList.toggle('d-none', sel.value !== 'daftar'); }
  if (sel) { sel.addEventListener('change', togel); togel(); }

  var raw = document.getElementById('dataKode');
  if (!raw) return;
  var d = JSON.parse(raw.textContent);
  var HEAD = ['No', 'Nama', 'Kelas', 'Kode Pasangan'];   // NIS/NISN sengaja tidak disertakan: siswa mengisi sendiri
  var esc = function (x) { return String(x).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  var fmt = function (k) { return k.slice(0, 3) + ' ' + k.slice(3); };
  var baris = d.baris.map(function (b, i) { return [String(i + 1), b[0], b[1], fmt(b[2])]; });

  /* ---------- SALIN: tabel (HTML) + teks bertab sebagai cadangan ---------- */
  function htmlTabel() {
    var th = 'style="border:1px solid #000;padding:4px 8px;background:#e9ecef;font-weight:bold;text-align:left"';
    var td = 'style="border:1px solid #000;padding:4px 8px;mso-number-format:\'@\'"';   // teks: NISN berawalan 0 tidak hilang di Excel
    return '<table style="border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:11pt"><thead><tr>'
      + HEAD.map(function (h) { return '<th ' + th + '>' + esc(h) + '</th>'; }).join('') + '</tr></thead><tbody>'
      + baris.map(function (r) {
          return '<tr>' + r.map(function (c, i) { return '<td ' + td + '>' + (i === 3 ? '<b style="font-family:Consolas,monospace;letter-spacing:1px">' + esc(c) + '</b>' : esc(c)) + '</td>'; }).join('') + '</tr>';
        }).join('') + '</tbody></table>';
  }
  window.salinSemua = function (btn) {
    var tsv = HEAD.join('\t') + '\n' + baris.map(function (r) { return r.join('\t'); }).join('\n');
    var selesai = function () { var l = btn.innerHTML; btn.innerHTML = '<i class="bi bi-check2"></i> Tabel tersalin'; setTimeout(function () { btn.innerHTML = l; }, 2000); };
    if (window.ClipboardItem && navigator.clipboard && navigator.clipboard.write) {
      navigator.clipboard.write([new ClipboardItem({
        'text/html': new Blob([htmlTabel()], { type: 'text/html' }),
        'text/plain': new Blob([tsv], { type: 'text/plain' })
      })]).then(selesai).catch(function () { navigator.clipboard.writeText(tsv).then(selesai); });
    } else {
      navigator.clipboard.writeText(tsv).then(selesai);
    }
  };

  /* ---------- EXCEL (.xlsx) dibuat langsung di browser, tanpa pustaka luar ---------- */
  var enc = new TextEncoder();
  var crcTabel = (function () { var t = [], c, n, k; for (n = 0; n < 256; n++) { c = n; for (k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
  function crc32(u8) { var c = 0xFFFFFFFF; for (var i = 0; i < u8.length; i++) c = crcTabel[(c ^ u8[i]) & 255] ^ (c >>> 8); return (c ^ 0xFFFFFFFF) >>> 0; }
  function buatZip(files) {
    var bagian = [], pusat = [], offset = 0;
    var u16 = function (v) { return [v & 255, (v >>> 8) & 255]; };
    var u32 = function (v) { return [v & 255, (v >>> 8) & 255, (v >>> 16) & 255, (v >>> 24) & 255]; };
    files.forEach(function (f) {
      var nama = enc.encode(f.name), data = enc.encode(f.data), crc = crc32(data);
      var lokal = [].concat([0x50, 0x4b, 3, 4], u16(20), u16(0x0800), u16(0), u16(0), u16(0x21), u32(crc), u32(data.length), u32(data.length), u16(nama.length), u16(0));
      bagian.push(new Uint8Array(lokal), nama, data);
      pusat.push(new Uint8Array([].concat([0x50, 0x4b, 1, 2], u16(20), u16(20), u16(0x0800), u16(0), u16(0), u16(0x21), u32(crc), u32(data.length), u32(data.length), u16(nama.length), u16(0), u16(0), u16(0), u16(0), u32(0), u32(offset))), nama);
      offset += lokal.length + nama.length + data.length;
    });
    var ukuranPusat = pusat.reduce(function (a, x) { return a + x.length; }, 0);
    var akhir = new Uint8Array([].concat([0x50, 0x4b, 5, 6], u16(0), u16(0), u16(files.length), u16(files.length), u32(ukuranPusat), u32(offset), u16(0)));
    return new Blob(bagian.concat(pusat, [akhir]), { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
  }
  var kolom = function (i) { return String.fromCharCode(65 + i); };
  var sel = function (ref, teks, gaya) { return '<c r="' + ref + '" s="' + gaya + '" t="inlineStr"><is><t xml:space="preserve">' + esc(teks) + '</t></is></c>'; };

  window.unduhExcel = function () {
    var rows = '';
    rows += '<row r="1" ht="24" customHeight="1">' + sel('A1', (d.sekolah || '').toUpperCase(), 1) + '</row>';
    rows += '<row r="2">' + sel('A2', 'KODE PASANGAN PERANGKAT', 2) + '</row>';
    rows += '<row r="3">' + sel('A3', d.masa + '  ·  sekali pakai  ·  dicetak ' + d.tanggal, 5) + '</row>';
    rows += '<row r="5" ht="20" customHeight="1">' + HEAD.map(function (h, i) { return sel(kolom(i) + '5', h, 3); }).join('') + '</row>';
    baris.forEach(function (r, n) {
      var no = n + 6;
      rows += '<row r="' + no + '">' + r.map(function (c, i) { return sel(kolom(i) + no, c, i === 3 ? 6 : 4); }).join('') + '</row>';
    });
    var sheet = '\x3c?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      + '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
      + '<cols><col min="1" max="1" width="6" customWidth="1"/><col min="2" max="2" width="40" customWidth="1"/><col min="3" max="3" width="12" customWidth="1"/><col min="4" max="4" width="18" customWidth="1"/></cols>'
      + '<sheetData>' + rows + '</sheetData><mergeCells count="3"><mergeCell ref="A1:D1"/><mergeCell ref="A2:D2"/><mergeCell ref="A3:D3"/></mergeCells>'
      + '<pageSetup orientation="portrait" paperSize="9" fitToHeight="0"/></worksheet>';
    var styles = '\x3c?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      + '<fonts count="5"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font><font><b/><sz val="12"/><color rgb="FF0D6EFD"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="12"/><name val="Consolas"/></font></fonts>'
      + '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE9ECEF"/></patternFill></fill></fills>'
      + '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right><top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border></borders>'
      + '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
      + '<cellXfs count="7"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
      + '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
      + '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
      + '<xf numFmtId="49" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
      + '<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyNumberFormat="1"/>'
      + '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
      + '<xf numFmtId="49" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="center"/></xf></cellXfs>'
      + '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    var blob = buatZip([
      { name: '[Content_Types].xml', data: '\x3c?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>' },
      { name: '_rels/.rels', data: '\x3c?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' },
      { name: 'xl/workbook.xml', data: '\x3c?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Kode Pasangan" sheetId="1" r:id="rId1"/></sheets></workbook>' },
      { name: 'xl/_rels/workbook.xml.rels', data: '\x3c?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' },
      { name: 'xl/styles.xml', data: styles },
      { name: 'xl/worksheets/sheet1.xml', data: sheet }
    ]);
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'kode-pasangan-' + new Date().toISOString().slice(0, 10) + '.xlsx';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
  };

  /* ---------- CETAK: kop surat + tabel rapi ---------- */
  window.cetakKode = function () {
    var w = window.open('', '_blank');
    if (!w) { alert('Izinkan pop-up untuk mencetak.'); return; }
    var rows = baris.map(function (r, i) {
      return '<tr><td class="c">' + esc(r[0]) + '</td><td>' + esc(r[1]) + '</td><td class="c">' + esc(r[2]) + '</td><td class="k">' + esc(r[3]) + '</td></tr>';
    }).join('');
    var logo = d.logo ? '<img src="' + esc(d.logo) + '" alt="">' : '';
    w.document.write('<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Kode Pasangan Perangkat</title><style>'
      + '*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;color:#14213d;margin:0;padding:14mm 14mm 12mm;font-size:11.5px}'
      + '.kop{display:flex;align-items:center;gap:14px;border-bottom:3px double #000;padding-bottom:9px;margin-bottom:12px}'
      + '.kop img{width:62px;height:62px;object-fit:contain}.kop .t{flex:1;text-align:center}'
      + '.kop h1{font-size:17px;margin:0;text-transform:uppercase;letter-spacing:.02em}.kop p{margin:2px 0 0;font-size:11px;color:#333}'
      + 'h2{font-size:14px;text-align:center;margin:0 0 3px;text-transform:uppercase;letter-spacing:.06em}'
      + '.info{display:flex;justify-content:center;gap:18px;flex-wrap:wrap;font-size:11px;color:#444;margin-bottom:10px}.info b{color:#000}'
      + '.cara{border:1px solid #9aa4b5;background:#f6f8fc;border-radius:6px;padding:8px 11px;margin-bottom:10px;line-height:1.5}'
      + '.cara ol{margin:3px 0 0 16px;padding:0}'
      + 'table{width:100%;border-collapse:collapse}th,td{border:1px solid #000;padding:5px 8px;text-align:left}'
      + 'th{background:#e9ecef;font-size:11px;text-transform:uppercase;letter-spacing:.04em}td.c{text-align:center}'
      + 'tbody tr:nth-child(even){background:#fafbfd}td.k{font-family:Consolas,"Courier New",monospace;font-weight:700;font-size:14px;letter-spacing:.14em;text-align:center}'
      + 'thead{display:table-header-group}tr{page-break-inside:avoid}.foot{margin-top:10px;font-size:10px;color:#666;text-align:center}'
      + '@page{size:A4 portrait;margin:0}@media print{*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}</style></head><body>'
      + '<div class="kop">' + logo + '<div class="t"><h1>' + esc(d.sekolah) + '</h1>' + (d.alamat ? '<p>' + esc(d.alamat).replace(/\n/g, '<br>') + '</p>' : '') + '</div>' + (logo ? '<div style="width:62px"></div>' : '') + '</div>'
      + '<h2>Kode Pasangan Perangkat</h2>'
      + '<div class="info"><span>Berlaku: <b>' + esc(d.masa) + '</b></span><span>Jumlah: <b>' + baris.length + ' siswa</b></span><span>Dicetak: <b>' + esc(d.tanggal) + '</b></span><span><b>Sekali pakai</b></span></div>'
      + '<div class="cara"><b>Cara mendaftarkan perangkat:</b><ol><li>Buka <b>' + esc(d.situs) + '</b> di HP Anda.</li><li>Ketuk <b>Daftarkan Perangkat</b>, isi <b>NISN/NIS</b> dan <b>Kode Pasangan</b> milik Anda.</li><li>Ketuk <b>Daftarkan Perangkat Ini</b>. Rahasiakan kode Anda.</li></ol></div>'
      + '<table><thead><tr><th style="width:36px">No</th><th>Nama</th><th style="width:70px">Kelas</th><th style="width:140px">Kode</th></tr></thead><tbody>' + rows + '</tbody></table>'
      + '<div class="foot">SIAP ABSEN &middot; dokumen rahasia, hanya untuk siswa yang bersangkutan</div></body></html>');
    w.document.close(); w.focus(); setTimeout(function () { w.print(); }, 500);
  };
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>