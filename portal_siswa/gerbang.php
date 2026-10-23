<?php
// /portal/gerbang — dipanggil bila belum ada sesi. Mencoba MASUK OTOMATIS lewat perangkat terdaftar (tanpa password).
// Browser membaca UUID perangkatnya (tidak dibuat baru), mengirimnya ke /portal/masuk-perangkat, lalu diteruskan ke halaman
// tujuan. Gagal / belum terdaftar / tanpa JavaScript -> halaman login seperti biasa.
$lanjut = portal_lanjut_aman((string) ($_GET['lanjut'] ?? ''));
$tujuan = portal_url($lanjut, ['_g' => 1]);      // _g: tanda "gerbang sudah dilewati" agar tidak berputar bila cookie ditolak
$login = portal_login_url();

if (portal_siswa_sekarang()) { header('Location: ' . portal_url($lanjut)); exit; }

portal_head('Memeriksa perangkat');
$logo = portal_logo_url();
?>
<body class="pt-body pt-gerbang">
<noscript><meta http-equiv="refresh" content="0;url=<?= e($login) ?>"></noscript>
<main class="pt-gerbang-in" aria-live="polite">
  <div class="pt-gerbang-logo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><i class="bi bi-person-badge"></i><?php endif; ?></div>
  <div class="pt-spin" aria-hidden="true"></div>
  <p>Memeriksa perangkat…</p>
  <a class="pt-gerbang-link" href="<?= e($login) ?>">Masuk dengan password</a>
</main>
<script src="<?= BASE_URL ?>/assets/js/device.js"></script>
<script>
(function () {
  var tujuan = <?= json_encode($tujuan) ?>, login = <?= json_encode($login) ?>, api = <?= json_encode(portal_url('masuk-perangkat')) ?>;
  var selesai = false;
  function ke(u) { if (selesai) return; selesai = true; location.replace(u); }
  var uuid = '';
  try { uuid = getDeviceUuidIfExists(); } catch (e) {}
  if (!uuid) { ke(login); return; }                       // belum pernah dipakai di perangkat ini: langsung login
  setTimeout(function () { ke(login); }, 8000);           // jaring pengaman bila jaringan macet
  fetch(api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ uuid: uuid }) })
    .then(function (r) { return r.json(); })
    .then(function (d) { ke(d && d.ok ? tujuan : login); })
    .catch(function () { ke(login); });
})();
</script>
</body>
</html>
