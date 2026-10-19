<?php
// Papan Kehadiran Langsung: ringkasan & daftar siswa yang baru hadir, diperbarui otomatis.
// ?data=1 -> keluaran JSON untuk pembaruan berkala (polling).

if (isset($_GET['data'])) {
  require_once __DIR__ . '/../includes/auth.php';
  require_login();
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');

  $db = getDB();
  $kelas = clean($_GET['kelas'] ?? '');
  $hadirSql = "p.status IN ('HADIR','TERLAMBAT')";
  $filterK = $kelas !== '' ? ' AND p.kelas_saat_presensi = :k' : '';
  $par = $kelas !== '' ? [':k' => $kelas] : [];

  $st = $db->prepare("SELECT COUNT(*) FROM siswa WHERE status = 'aktif'" . ($kelas !== '' ? ' AND kelas = :k' : ''));
  $st->execute($par);
  $total = (int) $st->fetchColumn();

  $st = $db->prepare(
    "SELECT SUM(p.status = 'HADIR') hadir, SUM(p.status = 'TERLAMBAT') terlambat,
            SUM(p.status IN ('IZIN','SAKIT','ALPA')) tidak_hadir
     FROM presensi p WHERE p.tanggal = CURDATE()" . $filterK
  );
  $st->execute($par);
  $r = $st->fetch() ?: [];
  $hadirTepat = (int) ($r['hadir'] ?? 0);
  $terlambat = (int) ($r['terlambat'] ?? 0);
  $tidakHadir = (int) ($r['tidak_hadir'] ?? 0);
  $hadir = $hadirTepat + $terlambat;

  $st = $db->prepare(
    "SELECT p.id, s.nama, p.kelas_saat_presensi kelas, p.jam_masuk, p.status
     FROM presensi p JOIN siswa s ON s.id = p.siswa_id
     WHERE p.tanggal = CURDATE() AND $hadirSql" . $filterK . "
     ORDER BY p.jam_masuk DESC, p.id DESC LIMIT 20"
  );
  $st->execute($par);
  $terbaru = array_map(fn($x) => [
    'id' => (int) $x['id'], 'nama' => $x['nama'], 'kelas' => $x['kelas'],
    'jam' => substr((string) $x['jam_masuk'], 0, 5), 'status' => $x['status'],
  ], $st->fetchAll());

  // Per jenjang (X, XI, XII, ...)
  $jenjang = function (string $k): string {
    $k = strtoupper(trim($k));
    if (preg_match('/^(XII|XI|X)(?![A-Z])/', $k, $m)) return $m[1];
    $t = preg_split('/[\s\-_.]+/', $k)[0] ?? '';
    return $t !== '' ? $t : 'LAINNYA';
  };
  $perJenjang = [];
  foreach ($db->query("SELECT kelas, COUNT(*) c FROM siswa WHERE status = 'aktif' GROUP BY kelas")->fetchAll() as $x) {
    $j = $jenjang((string) $x['kelas']);
    $perJenjang[$j]['total'] = ($perJenjang[$j]['total'] ?? 0) + (int) $x['c'];
  }
  foreach ($db->query("SELECT p.kelas_saat_presensi kelas, COUNT(*) c FROM presensi p WHERE p.tanggal = CURDATE() AND $hadirSql GROUP BY p.kelas_saat_presensi")->fetchAll() as $x) {
    $j = $jenjang((string) $x['kelas']);
    $perJenjang[$j]['hadir'] = ($perJenjang[$j]['hadir'] ?? 0) + (int) $x['c'];
  }
  $urut = ['X' => 1, 'XI' => 2, 'XII' => 3];
  uksort($perJenjang, fn($a, $b) => ($urut[$a] ?? 99) <=> ($urut[$b] ?? 99) ?: strcmp($a, $b));
  $jenjangOut = [];
  foreach ($perJenjang as $j => $v) {
    $jenjangOut[] = ['jenjang' => $j, 'total' => (int) ($v['total'] ?? 0), 'hadir' => (int) ($v['hadir'] ?? 0)];
  }

  $cfg = get_settings();
  echo json_encode([
    'success' => true,
    'jam_masuk' => substr((string) ($cfg['jam_masuk'] ?? ''), 0, 5),
    'batas_terlambat' => substr((string) ($cfg['batas_terlambat'] ?? ''), 0, 5),
    'waktu' => date('H:i:s'),
    'efektif' => is_hari_efektif(),
    'total' => $total, 'hadir' => $hadir, 'tepat' => $hadirTepat, 'terlambat' => $terlambat,
    'tidak_hadir' => $tidakHadir,
    'belum' => max(0, $total - $hadir - $tidakHadir),
    'persen' => $total ? (int) round($hadir / $total * 100) : 0,
    'terbaru' => $terbaru, 'jenjang' => $jenjangOut,
  ]);
  exit;
}

require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$kelasList = $db->query("SELECT DISTINCT kelas FROM siswa WHERE status = 'aktif' ORDER BY kelas")->fetchAll(PDO::FETCH_COLUMN);
$kelasPilih = clean($_GET['kelas'] ?? '');
?>
<style>
  :root {
    --bp-biru: var(--siap-blue);
    --bp-navy: var(--siap-ink);
    --bp-abu: var(--siap-muted);
    --bp-garis: var(--siap-line);
    --bp-hijau: var(--siap-success-text);
    --bp-oranye: var(--siap-warning-text);
    --bp-merah: var(--siap-danger-text);
  }
  .bp-wrap { color: var(--siap-body); }
  .bp-wrap:fullscreen { background-color: var(--siap-bg); padding: 28px; overflow: auto; }
  .bp-card { background: rgba(255,255,255,0.9); border: 1px solid var(--siap-line); border-radius: var(--siap-radius-window); box-shadow: var(--siap-shadow-card); }
  .bp-hero { padding: 22px; margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 18px; align-items: center; justify-content: space-between; }
  .bp-jam { font-size: 2.6rem; font-weight: 800; letter-spacing: .02em; font-variant-numeric: tabular-nums; line-height: 1; color: var(--siap-ink); }
  .bp-tgl { color: var(--siap-muted); font-weight: 600; margin-top: 6px; }
  .bp-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
  .bp-chip { display: inline-flex; align-items: center; gap: 7px; padding: 5px 12px; border-radius: 999px; font-size: .8rem; font-weight: 700; border: 1px solid var(--siap-line); background: #fff; color: var(--siap-body); }
  .bp-chip.ok { background: var(--siap-success-bg); border-color: var(--siap-success-border); color: var(--siap-success-text); } .bp-chip.tutup { background: var(--siap-danger-bg); border-color: var(--siap-danger-border); color: var(--siap-danger-text); }
  .bp-aksi { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
  .bp-grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 16px; }
  @media (max-width: 760px) { .bp-grid { grid-template-columns: 1fr; } .bp-jam { font-size: 2.2rem; } }
  .bp-panel { padding: 20px 22px; }
  .bp-panel h2 { font-size: 1rem; font-weight: 800; margin: 0 0 2px; color: var(--siap-ink); }
  .bp-panel .ket { color: var(--siap-muted); font-size: .84rem; margin-bottom: 14px; }
  .bp-donut-box { display: flex; flex-direction: column; align-items: center; gap: 16px; }
  .bp-donut { position: relative; width: 210px; height: 210px; }
  .bp-donut svg { width: 100%; height: 100%; transform: rotate(-90deg); }
  .bp-donut circle { fill: none; stroke-width: 26; transition: stroke-dasharray .8s ease, stroke-dashoffset .8s ease; }
  .bp-donut .tengah { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
  .bp-donut .persen { font-size: 2.6rem; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; color: var(--siap-ink); }
  .bp-donut .persen small { font-size: 1.2rem; font-weight: 700; }
  .bp-donut .lbl { color: var(--siap-muted); font-weight: 700; font-size: .8rem; letter-spacing: .08em; text-transform: uppercase; margin-top: 4px; }
  .bp-legend { width: 100%; display: grid; gap: 8px; }
  .bp-lg { display: flex; align-items: center; gap: 10px; font-size: .92rem; }
  .bp-lg .dot { width: 11px; height: 11px; border-radius: 50%; flex: none; }
  .bp-lg .n { margin-left: auto; font-weight: 800; font-variant-numeric: tabular-nums; }
  .bp-lg .p { width: 48px; text-align: right; color: var(--siap-muted); font-variant-numeric: tabular-nums; }
  .bp-live { display: inline-flex; align-items: center; gap: 7px; padding: 4px 11px; border-radius: 999px; background: var(--siap-success-bg); color: var(--siap-success-text); font-weight: 800; font-size: .72rem; letter-spacing: .08em; text-transform: uppercase; }
  .bp-live::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; animation: bp-kedip 1.4s infinite; }
  .bp-live.putus { background: #f1f3f8; color: var(--siap-muted); } .bp-live.putus::before { animation: none; }
  @keyframes bp-kedip { 0%,100% { opacity: 1; } 50% { opacity: .3; } }
  #bpFeed { max-height: 460px; overflow-y: auto; margin: 0 -22px; scrollbar-width: none; }
  #bpFeed::-webkit-scrollbar { display: none; }
  .bp-wrap:fullscreen #bpFeed { max-height: calc(100vh - 380px); }
  .bp-fi { display: flex; align-items: center; gap: 13px; padding: 11px 22px; border-bottom: 1px solid var(--siap-line); }
  .bp-fi:last-child { border-bottom: 0; }
  .bp-fi .av { width: 40px; height: 40px; border-radius: 50%; flex: none; display: grid; place-items: center; color: #fff; font-weight: 700; font-size: .82rem; }
  .bp-fi .nm { font-weight: 700; line-height: 1.2; color: var(--siap-ink); } .bp-fi .kl { color: var(--siap-muted); font-size: .82rem; }
  .bp-fi .wk { margin-left: auto; text-align: right; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--siap-ink); }
  .bp-fi .wk small { display: block; font-size: .72rem; font-weight: 700; }
  .bp-fi .wk .h { color: var(--siap-success-text); } .bp-fi .wk .t { color: var(--siap-warning-text); }
  .bp-fi.baru { animation: bp-baru 3s ease-out; }
  @keyframes bp-baru { 0% { background: var(--siap-success-bg); transform: translateY(-8px); opacity: 0; } 12% { opacity: 1; transform: none; } 100% { background: transparent; } }
  .bp-kosong { padding: 34px 22px; text-align: center; color: var(--siap-muted); }
  .bp-sect { margin: 22px 2px 12px; }
  .bp-sect h2 { font-size: 1rem; font-weight: 800; margin: 0; color: var(--siap-ink); }
  .bp-sect .ket { color: var(--siap-muted); font-size: .84rem; margin: 2px 0 0; }
  .bp-jj { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
  .bp-jt { --ac: var(--siap-blue); position: relative; display: flex; align-items: center; gap: 18px; padding: 20px 22px; border-radius: var(--siap-radius-card); overflow: hidden;
           border: 1px solid var(--siap-line); border-radius: var(--siap-radius-window); background: linear-gradient(135deg, color-mix(in srgb, var(--ac) 9%, #fff), #fff 70%); box-shadow: var(--siap-shadow-card); }
  .bp-jt .ring { position: relative; width: 92px; height: 92px; flex: none; }
  .bp-jt .ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
  .bp-jt .ring circle { fill: none; stroke-width: 9; }
  .bp-jt .ring .dasar { stroke: color-mix(in srgb, var(--ac) 14%, var(--siap-line)); }
  .bp-jt .ring .isi { stroke: var(--ac); stroke-linecap: round; transition: stroke-dasharray .9s cubic-bezier(.4, 0, .2, 1); }
  .bp-jt .ring .pr { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--siap-ink); }
  .bp-jt .ring .pr small { font-size: .7rem; font-weight: 700; margin-left: 1px; color: var(--siap-muted); }
  .bp-jt .lbl { font-size: .72rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--ac); }
  .bp-jt .angka { font-size: 1.9rem; font-weight: 800; line-height: 1.1; margin-top: 2px; font-variant-numeric: tabular-nums; color: var(--siap-ink); }
  .bp-jt .angka span { font-size: 1rem; font-weight: 600; color: var(--siap-muted); }
  .bp-jt .sub { font-size: .82rem; color: var(--siap-muted); font-weight: 600; margin-top: 2px; }
</style>

<div class="bp-wrap" id="papan">

  <div class="bp-card bp-hero">
    <div>
      <div class="bp-jam" id="bpJam">--:--:--</div>
      <div class="bp-tgl" id="bpTgl">&nbsp;</div>
      <div class="bp-chips">
        <span class="bp-chip" id="bpBuka">memuat…</span>
        <span class="bp-chip" id="bpJamKerja" style="display:none"></span>
      </div>
    </div>
    <div class="bp-aksi">
      <select id="filterKelas" class="form-select" style="width:auto" aria-label="Filter kelas">
        <option value="">Semua Kelas</option>
        <?php foreach ($kelasList as $k): ?><option value="<?= e($k) ?>" <?= $kelasPilih === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?>
      </select>
      <button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" id="btnLayar" title="Layar penuh"><i class="bi bi-arrows-fullscreen"></i> Layar penuh</button>
    </div>
  </div>

  <div class="bp-grid">
    <div class="bp-card bp-panel">
      <h2>Kehadiran Hari Ini</h2>
      <div class="ket" id="bpKetDonut">dari seluruh siswa aktif</div>
      <div class="bp-donut-box">
        <div class="bp-donut">
          <svg viewBox="0 0 200 200" id="bpSvg"><circle cx="100" cy="100" r="80" stroke="#e9edf3" stroke-dasharray="502.65 502.65"/></svg>
          <div class="tengah"><div class="persen"><span id="bpPersen">0</span><small>%</small></div><div class="lbl">Hadir</div></div>
        </div>
        <div class="bp-legend">
          <div class="bp-lg"><span class="dot" style="background:var(--bp-hijau)"></span>Tepat waktu<span class="n" id="bpNTepat">0</span><span class="p" id="bpPTepat">0%</span></div>
          <div class="bp-lg"><span class="dot" style="background:var(--bp-oranye)"></span>Terlambat<span class="n" id="bpNTerlambat">0</span><span class="p" id="bpPTerlambat">0%</span></div>
          <div class="bp-lg"><span class="dot" style="background:var(--bp-merah)"></span>Izin / Sakit / Alpa<span class="n" id="bpNTidak">0</span><span class="p" id="bpPTidak">0%</span></div>
          <div class="bp-lg"><span class="dot" style="background:#cbd3e1"></span>Belum presensi<span class="n" id="bpNBelum">0</span><span class="p" id="bpPBelum">0%</span></div>
        </div>
      </div>
    </div>

    <div class="bp-card bp-panel">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:2px">
        <h2 style="margin:0">Absensi Terbaru</h2>
        <span class="bp-live" id="bpLive" style="margin-left:auto">LIVE</span>
      </div>
      <div class="ket" id="bpWaktuUpdate">memuat…</div>
      <div id="bpFeed"><div class="bp-kosong">Memuat data…</div></div>
    </div>
  </div>

  <div class="bp-sect">
    <h2>Per Jenjang</h2>
    <div class="ket">Kehadiran hari ini menurut jenjang kelas</div>
  </div>
  <div class="bp-jj" id="bpJenjang"><div class="bp-kosong" style="grid-column:1/-1">Memuat data…</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var URL_DATA = <?= json_encode(modul_url('papan', ['data' => 1])) ?>;
  var $ = function (id) { return document.getElementById(id); };
  var HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
  var BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
  var WARNA = ['#20b2a0', '#4a9be8', '#a855c8', '#f59f0b', '#e5484d', '#2fb344', '#1d6fd8', '#8b5cf6'];
  var C = 2 * Math.PI * 80;
  var sudahAda = null, memuat = false;
  var filter = $('filterKelas');

  function tick() {
    var d = new Date(), p = function (n) { return String(n).padStart(2, '0'); };
    $('bpJam').textContent = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    $('bpTgl').textContent = HARI[d.getDay()] + ', ' + d.getDate() + ' ' + BULAN[d.getMonth()] + ' ' + d.getFullYear();
  }
  tick(); setInterval(tick, 1000);

  function hash(s) { var h = 0; for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0; return h; }
  function pct(n, t) { return t ? Math.round(n / t * 100) : 0; }

  function gambarDonut(d) {
    var svg = $('bpSvg'); svg.textContent = '';
    var seg = [{ n: d.tepat, c: '#2fb344' }, { n: d.terlambat, c: '#f08c1a' }, { n: d.tidak_hadir, c: '#e03e4a' }, { n: d.belum, c: '#cbd3e1' }];
    var total = d.total || 0, mulai = 0, ns = 'http://www.w3.org/2000/svg';
    if (!total) { seg = [{ n: 1, c: '#e9edf3' }]; total = 1; }
    seg.forEach(function (s) {
      if (s.n <= 0) return;
      var len = s.n / total * C, c = document.createElementNS(ns, 'circle');
      c.setAttribute('cx', 100); c.setAttribute('cy', 100); c.setAttribute('r', 80); c.setAttribute('stroke', s.c);
      c.style.strokeDasharray = len + ' ' + (C - len); c.style.strokeDashoffset = -mulai;
      svg.appendChild(c); mulai += len;
    });
  }

  function gambarFeed(d) {
    var feed = $('bpFeed'); feed.textContent = '';
    if (!d.terbaru.length) {
      var k = document.createElement('div'); k.className = 'bp-kosong';
      k.textContent = d.efektif ? 'Belum ada siswa yang presensi hari ini.' : 'Hari ini bukan hari efektif presensi.';
      feed.appendChild(k);
    }
    var set = {};
    d.terbaru.forEach(function (x) {
      set[x.id] = true;
      var it = document.createElement('div');
      it.className = 'bp-fi' + (sudahAda !== null && !sudahAda[x.id] ? ' baru' : '');
      var kata = x.nama.trim().split(/\s+/);
      var av = document.createElement('span'); av.className = 'av';
      av.style.background = WARNA[hash(x.nama) % WARNA.length];
      av.textContent = ((kata[0] || '')[0] || '') + ((kata[1] || '')[0] || '');
      var info = document.createElement('div');
      var n = document.createElement('div'); n.className = 'nm'; n.textContent = x.nama;
      var kl = document.createElement('div'); kl.className = 'kl'; kl.textContent = 'Kelas ' + x.kelas;
      info.appendChild(n); info.appendChild(kl);
      var wk = document.createElement('div'); wk.className = 'wk'; wk.textContent = x.jam;
      var sm = document.createElement('small'); sm.className = x.status === 'TERLAMBAT' ? 't' : 'h';
      sm.textContent = x.status === 'TERLAMBAT' ? 'Terlambat' : 'Tepat waktu';
      wk.appendChild(sm);
      it.appendChild(av); it.appendChild(info); it.appendChild(wk);
      feed.appendChild(it);
    });
    sudahAda = set;
  }

  var WARNA_JJ = ['#1f6feb', '#1fa58f', '#e8a200', '#a855c8', '#e5484d'];
  var RJ = 2 * Math.PI * 38;
  var petaJenjang = {};
  function buatTile(nama, idx) {
    var ns = 'http://www.w3.org/2000/svg';
    var t = document.createElement('div'); t.className = 'bp-jt';
    t.style.setProperty('--ac', WARNA_JJ[idx % WARNA_JJ.length]);
    var ring = document.createElement('div'); ring.className = 'ring';
    var svg = document.createElementNS(ns, 'svg'); svg.setAttribute('viewBox', '0 0 92 92');
    var dasar = document.createElementNS(ns, 'circle'); dasar.setAttribute('class', 'dasar'); dasar.setAttribute('cx', 46); dasar.setAttribute('cy', 46); dasar.setAttribute('r', 38);
    var isi = document.createElementNS(ns, 'circle'); isi.setAttribute('class', 'isi'); isi.setAttribute('cx', 46); isi.setAttribute('cy', 46); isi.setAttribute('r', 38);
    isi.style.strokeDasharray = '0 ' + RJ;
    svg.appendChild(dasar); svg.appendChild(isi);
    var pr = document.createElement('div'); pr.className = 'pr';
    var angkaPr = document.createElement('span'); var sm = document.createElement('small'); sm.textContent = '%';
    pr.appendChild(angkaPr); pr.appendChild(sm);
    ring.appendChild(svg); ring.appendChild(pr);
    var info = document.createElement('div');
    var lbl = document.createElement('div'); lbl.className = 'lbl'; lbl.textContent = 'Kelas ' + nama;
    var angka = document.createElement('div'); angka.className = 'angka';
    var hadirEl = document.createElement('b'); var totalEl = document.createElement('span');
    angka.appendChild(hadirEl); angka.appendChild(totalEl);
    var sub = document.createElement('div'); sub.className = 'sub'; sub.textContent = 'siswa hadir';
    info.appendChild(lbl); info.appendChild(angka); info.appendChild(sub);
    t.appendChild(ring); t.appendChild(info);
    return { el: t, isi: isi, pr: angkaPr, hadir: hadirEl, total: totalEl };
  }
  function gambarJenjang(d) {
    var box = $('bpJenjang');
    if (!d.jenjang.length) {
      petaJenjang = {}; box.textContent = '';
      var e = document.createElement('div'); e.className = 'bp-kosong'; e.style.gridColumn = '1/-1'; e.textContent = 'Belum ada data.'; box.appendChild(e); return;
    }
    if (!Object.keys(petaJenjang).length) box.textContent = '';
    var ada = {};
    d.jenjang.forEach(function (j, i) {
      ada[j.jenjang] = true;
      var t = petaJenjang[j.jenjang];
      if (!t) { t = petaJenjang[j.jenjang] = buatTile(j.jenjang, i); box.appendChild(t.el); }
      var persen = pct(j.hadir, j.total);
      t.hadir.textContent = j.hadir; t.total.textContent = ' / ' + j.total; t.pr.textContent = persen;
      var len = persen / 100 * RJ;
      requestAnimationFrame(function () { t.isi.style.strokeDasharray = len + ' ' + (RJ - len); });
    });
    Object.keys(petaJenjang).forEach(function (k) { if (!ada[k]) { petaJenjang[k].el.remove(); delete petaJenjang[k]; } });
  }

  function render(d) {
    $('bpPersen').textContent = d.persen;
    $('bpKetDonut').textContent = 'dari ' + d.total + ' siswa aktif' + (filter.value ? ' (kelas ' + filter.value + ')' : '');
    [['Tepat', d.tepat], ['Terlambat', d.terlambat], ['Tidak', d.tidak_hadir], ['Belum', d.belum]].forEach(function (r) {
      $('bpN' + r[0]).textContent = r[1]; $('bpP' + r[0]).textContent = pct(r[1], d.total) + '%';
    });
    gambarDonut(d); gambarFeed(d); gambarJenjang(d);
    var cb = $('bpBuka'); cb.className = 'bp-chip ' + (d.efektif ? 'ok' : 'tutup');
    cb.textContent = d.efektif ? 'Presensi dibuka hari ini' : 'Hari ini libur — presensi ditutup';
    if (d.jam_masuk) { var cj = $('bpJamKerja'); cj.style.display = ''; cj.textContent = 'Masuk ' + d.jam_masuk + (d.batas_terlambat ? ' · terlambat setelah ' + d.batas_terlambat : ''); }
    $('bpWaktuUpdate').textContent = 'Diperbarui ' + d.waktu;
    $('bpLive').classList.remove('putus'); $('bpLive').textContent = 'LIVE';
  }

  function muat() {
    if (memuat || document.hidden) return;
    memuat = true;
    fetch(URL_DATA + (filter.value ? '&kelas=' + encodeURIComponent(filter.value) : ''), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) { if (d.success) render(d); })
      .catch(function () { $('bpLive').classList.add('putus'); $('bpLive').textContent = 'TERPUTUS'; })
      .then(function () { memuat = false; });
  }

  filter.addEventListener('change', function () { sudahAda = null; muat(); });
  document.addEventListener('visibilitychange', function () { if (!document.hidden) muat(); });
  $('btnLayar').addEventListener('click', function () {
    var el = $('papan');
    if (document.fullscreenElement) document.exitFullscreen(); else if (el.requestFullscreen) el.requestFullscreen();
  });
  muat(); setInterval(muat, 8000);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>