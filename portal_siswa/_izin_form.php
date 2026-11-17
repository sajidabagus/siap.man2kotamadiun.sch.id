<?php
/**
 * Formulir pengajuan izin/sakit (dipakai /portal/izin dan /portal/izin/baru). Selalu dikirim ke /portal/izin/baru,
 * yang memvalidasi lewat izin_buat() (backend yang sama seperti sebelumnya).
 * $in: nilai isian sebelumnya, $galat: pesan galat per kolom.
 */
function portal_izin_form(array $in, array $galat): void
{
    $in += ['mode_durasi' => 'sehari', 'jam_mulai' => '', 'jam_selesai' => ''];
    $hariIni = date('Y-m-d');
    $minTgl = date('Y-m-d', strtotime('-' . IZIN_MUNDUR_HARI . ' days'));
    $maxTgl = date('Y-m-d', strtotime('+' . IZIN_MAJU_HARI . ' days'));
    $f = fn(string $k) => isset($galat[$k]) ? '<div class="pt-err-f">' . e($galat[$k]) . '</div>' : '';
    ?>
<section class="pt-card" id="formIzin">
  <h3>Ajukan izin / sakit / dispensasi</h3>
  <?php if (isset($galat['umum'])) portal_alert('err', $galat['umum']); ?>
  <div class="pt-aturan">
    <b>Aturan singkat:</b> bisa diajukan maksimal <b><?= IZIN_MUNDUR_HARI ?> hari ke belakang</b> dan <b><?= IZIN_MAJU_HARI ?> hari ke depan</b>;
    satu pengajuan maksimal <b><?= IZIN_MAKS_DURASI ?> hari</b>. <b>Sakit lebih dari 1 hari sekolah wajib melampirkan surat</b> (foto atau PDF; foto dikompres otomatis, PDF maks. 3 MB).
    <b>Dispensasi wajib keterangan kegiatan dan foto dokumen.</b> Dispensasi jam tidak menggantikan absen masuk. Dispensasi satu/beberapa hari yang disetujui membebaskan Alpa otomatis pada hari efektif tercakup, tanpa menghapus presensi asli.
  </div>
  <form method="post" action="<?= e(portal_url('izin/baru')) ?>" enctype="multipart/form-data" data-sekali>
    <?= csrf_field() ?>
    <div class="pt-radio pt-radio-pengajuan" role="radiogroup" aria-label="Jenis pengajuan">
      <label><input type="radio" name="jenis" value="izin" <?= $in['jenis'] === 'izin' ? 'checked' : '' ?> required><span><i class="bi bi-envelope-paper"></i> Izin<small>keperluan keluarga, dll.</small></span></label>
      <label><input type="radio" name="jenis" value="sakit" <?= $in['jenis'] === 'sakit' ? 'checked' : '' ?>><span><i class="bi bi-thermometer-half"></i> Sakit<small>surat bila &gt; 1 hari</small></span></label>
      <label><input type="radio" name="jenis" value="dispensasi" <?= $in['jenis'] === 'dispensasi' ? 'checked' : '' ?>><span><i class="bi bi-person-workspace"></i> Dispensasi<small>kegiatan + foto dokumen</small></span></label>
    </div>
    <?= $f('jenis') ?>
    <div id="durasiDispensasi" class="pt-field"<?= $in['jenis'] === 'dispensasi' ? '' : ' hidden' ?>>
      <label for="modeDurasi">Durasi dispensasi</label>
      <select id="modeDurasi" name="mode_durasi">
        <?php foreach (DISPENSASI_MODE as $k => $label): ?><option value="<?= e($k) ?>" <?= $in['mode_durasi'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select><?= $f('mode_durasi') ?>
      <p class="pt-kosong">Rentang jam berlaku pada satu tanggal. Beberapa hari dihitung inklusif, hanya hari efektif kelas yang diterapkan.</p>
      <div class="pt-dua" id="jamDispensasi">
        <div><label for="jamMulai">Pukul mulai</label><input type="time" id="jamMulai" name="jam_mulai" value="<?= e($in['jam_mulai']) ?>"><?= $f('jam_mulai') ?></div>
        <div><label for="jamSelesai">Pukul selesai</label><input type="time" id="jamSelesai" name="jam_selesai" value="<?= e($in['jam_selesai']) ?>"><?= $f('jam_selesai') ?></div>
      </div>
    </div>
    <div class="pt-dua">
      <div class="pt-field"><label for="mulai">Mulai</label>
        <input type="date" id="mulai" name="mulai" value="<?= e($in['mulai'] ?: $hariIni) ?>" min="<?= e($minTgl) ?>" max="<?= e($maxTgl) ?>" required><?= $f('mulai') ?></div>
      <div class="pt-field"><label for="selesai" id="labelSelesai">Sampai (boleh kosong)</label>
        <input type="date" id="selesai" name="selesai" value="<?= e($in['selesai']) ?>" min="<?= e($minTgl) ?>" max="<?= e($maxTgl) ?>"><?= $f('selesai') ?></div>
    </div>
    <div class="pt-field"><label for="alasan" id="labelAlasan">Alasan / keterangan kegiatan</label>
      <textarea id="alasan" name="alasan" maxlength="<?= IZIN_ALASAN_MAKS ?>" placeholder="Tuliskan alasan dengan jelas (minimal <?= IZIN_ALASAN_MIN ?> karakter)" required><?= e($in['alasan']) ?></textarea>
      <div class="pt-hitung"><span id="hitung">0</span>/<?= IZIN_ALASAN_MAKS ?></div><?= $f('alasan') ?></div>
    <div class="pt-field"><label for="lampiran">Lampiran surat <span id="wajibLamp" style="color:#b42318"></span></label>
      <div class="pt-unggah" id="unggah" data-maks="<?= (int) IZIN_LAMPIRAN_MAKS ?>">
        <input type="file" id="lampiran" name="lampiran" class="pt-unggah-input" data-kompres="1800" data-kompres-target="2621440" accept="image/*,.jpg,.jpeg,.png,.webp,.pdf,application/pdf" aria-describedby="lampiranInfo">
        <label for="lampiran" class="pt-unggah-zona" id="unggahZona">
          <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
          <span class="j">Pilih file / foto surat</span>
          <span class="k" id="lampiranInfo">Foto (JPG/PNG/WEBP, dikompres otomatis) atau PDF · PDF maks. <?= (int) round(IZIN_LAMPIRAN_MAKS / 1048576) ?> MB</span>
        </label>
        <div class="pt-unggah-pilih" id="unggahPilih" hidden>
          <div class="pv" id="unggahPv"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i></div>
          <div class="nm"><b id="unggahNama"></b><small id="unggahUkuran"></small></div>
          <label for="lampiran" class="pt-unggah-btn">Ganti</label>
          <button type="button" class="pt-unggah-btn hapus" id="unggahHapus" aria-label="Hapus lampiran"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="pt-err-f" id="unggahGalat" role="alert" hidden></div>
      </div><?= $f('lampiran') ?></div>
    <button type="submit" class="pt-btn">Kirim pengajuan</button>
  </form>
</section>
<script>
(function () {
  var a = document.getElementById('alasan'), h = document.getElementById('hitung'), m = document.getElementById('mulai'), s = document.getElementById('selesai'), w = document.getElementById('wajibLamp');
  function hitung() { h.textContent = a.value.length; }
  var mode = document.getElementById('modeDurasi'), jamMulai = document.getElementById('jamMulai'), jamSelesai = document.getElementById('jamSelesai');
  function wajib() {
    var jenis = document.querySelector('input[name=jenis]:checked');
    var disp = jenis && jenis.value === 'dispensasi', jam = disp && mode.value === 'jam';
    var beberapa = !disp || mode.value === 'beberapa_hari';
    document.getElementById('durasiDispensasi').hidden = !disp;
    mode.disabled = !disp;
    document.getElementById('jamDispensasi').hidden = !jam;
    [jamMulai, jamSelesai].forEach(function (el) { el.disabled = !jam; el.required = jam; });
    s.disabled = !beberapa; s.required = disp && beberapa;
    document.getElementById('labelSelesai').textContent = disp ? 'Tanggal akhir (inklusif, wajib)' : 'Sampai (boleh kosong)';
    document.getElementById('lampiranInfo').textContent = disp ? 'Foto dokumen JPG/PNG/WEBP · dikompres otomatis, hasil maks. 3 MB · bukan PDF' : 'Foto JPG/PNG/WEBP (dikompres otomatis) atau PDF maks. 3 MB';
    s.closest('.pt-field').hidden = !beberapa;
    if (disp && !beberapa) s.value = m.value;
    var dari = m.value, ke = s.value || m.value;
    var lebih = dari && ke && (new Date(ke) - new Date(dari)) >= 86400000;
    w.textContent = disp ? '(wajib: foto dokumen dispensasi)' : ((jenis && jenis.value === 'sakit' && lebih) ? '(wajib untuk sakit lebih dari 1 hari sekolah)' : '');
    var file = document.getElementById('lampiran');
    file.required = !!disp;
    file.accept = disp ? 'image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp' : 'image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf';
    file.setCustomValidity(disp && file.files && file.files[0] && /\.pdf$/i.test(file.files[0].name) ? 'Dispensasi membutuhkan foto dokumen, bukan PDF.' : '');
    document.getElementById('labelAlasan').textContent = disp ? 'Keterangan kegiatan (wajib)' : 'Alasan';
    jamSelesai.setCustomValidity(jam && jamMulai.value && jamSelesai.value && jamSelesai.value <= jamMulai.value ? 'Jam selesai harus setelah jam mulai.' : '');
  }
  mode.addEventListener('change', wajib); jamMulai.addEventListener('input', wajib); jamSelesai.addEventListener('input', wajib);
  m.addEventListener('change', function () { if (s.value && s.value < m.value) s.value = m.value; s.min = m.value; wajib(); });
  s.addEventListener('change', wajib); a.addEventListener('input', hitung);
  document.querySelectorAll('input[name=jenis]').forEach(function (r) { r.addEventListener('change', wajib); });
  hitung(); wajib();
  var fi = document.getElementById('lampiran'), zona = document.getElementById('unggahZona'), pilih = document.getElementById('unggahPilih'),
      pv = document.getElementById('unggahPv'), nm = document.getElementById('unggahNama'), uk = document.getElementById('unggahUkuran'),
      gl = document.getElementById('unggahGalat'), maks = +document.getElementById('unggah').dataset.maks, url = null;
  var tipeOk = /^(image\/(jpeg|png|webp)|application\/pdf)$/i, extOk = /\.(jpe?g|png|webp|pdf)$/i;
  function ukuran(b) { return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  function kosong(pesan) {
    fi.value = ''; fi.setCustomValidity(''); if (url) { URL.revokeObjectURL(url); url = null; }
    pilih.hidden = true; zona.hidden = false;
    gl.textContent = pesan || ''; gl.hidden = !pesan;
  }
  fi.addEventListener('change', function () {
    var f = fi.files && fi.files[0];
    if (!f) { kosong(); return; }
    if (!(tipeOk.test(f.type) || (!f.type && extOk.test(f.name)))) { kosong('Jenis file tidak diizinkan. Gunakan foto (JPG/PNG/WEBP) atau PDF.'); return; }
    if (f.size > maks) { kosong('Ukuran file ' + ukuran(f.size) + ', maksimal ' + ukuran(maks) + (/^application\/pdf$/i.test(f.type) ? ' untuk PDF. Kecilkan PDF lalu pilih lagi.' : '. Pilih foto lain.')); return; }
    wajib();
    if (!fi.checkValidity()) { kosong('Dispensasi wajib memakai foto JPG/PNG/WEBP.'); fi.setCustomValidity(''); return; }
    gl.hidden = true; nm.textContent = (f.name.length > 32 ? f.name.slice(0, 18) + '…' + f.name.slice(-11) : f.name); nm.title = f.name; uk.textContent = ukuran(f.size);
    if (url) URL.revokeObjectURL(url);
    pv.innerHTML = '';
    if (/^image\//.test(f.type)) { url = URL.createObjectURL(f); var im = new Image(); im.alt = ''; im.src = url; pv.appendChild(im); }
    else pv.innerHTML = '<i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>';
    zona.hidden = true; pilih.hidden = false;
  });
  document.getElementById('unggahHapus').addEventListener('click', function () { kosong(); fi.focus(); });
})();
</script>
<?php
}
