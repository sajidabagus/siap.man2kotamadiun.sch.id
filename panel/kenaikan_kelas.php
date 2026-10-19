<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

/**
 * Tebak nama kelas tujuan berikutnya secara SEDERHANA (X -> XI -> XII),
 * hanya sebagai SARAN AWAL yang bisa diubah admin -- BUKAN otomatis
 * diterapkan begitu saja, krn banyak sekolah punya penamaan kelas custom
 * (mis. "X IPA 1" -> "XI IPA 1") yang tidak bisa ditebak 100% benar.
 */
function saran_kelas_berikutnya(string $kelas): string
{
    if (preg_match('/^X(\D.*)?$/i', $kelas, $m) && !preg_match('/^XI/i', $kelas)) {
        return 'XI' . ($m[1] ?? '');
    }
    if (preg_match('/^XI(\D.*)?$/i', $kelas, $m) && !preg_match('/^XII/i', $kelas)) {
        return 'XII' . ($m[1] ?? '');
    }
    return ''; // XII atau pola tak dikenali -> kosongkan, biar admin isi manual/pilih Luluskan
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $aksiPerKelas = $_POST['aksi'] ?? [];
    $targetPerKelas = $_POST['target'] ?? [];
    $admin = current_admin();
    $ringkasan = [];

    $db->beginTransaction();
    try {
        foreach ($aksiPerKelas as $kelasAsal => $aksi) {
            $kelasAsal = clean($kelasAsal);
            if ($kelasAsal === '') continue;

            $stmtJumlah = $db->prepare("SELECT COUNT(*) c FROM siswa WHERE kelas = :k AND status = 'aktif'");
            $stmtJumlah->execute([':k' => $kelasAsal]);
            $jumlahSiswa = (int) $stmtJumlah->fetch()['c'];
            if ($jumlahSiswa === 0) continue; // kelas kosong, tidak ada yg diproses

            if ($aksi === 'pindah') {
                $target = clean($targetPerKelas[$kelasAsal] ?? '');
                if ($target === '' || $target === $kelasAsal) continue;

                $stmt = $db->prepare("UPDATE siswa SET kelas = :target, updated_at = NOW() WHERE kelas = :asal AND status = 'aktif'");
                $stmt->execute([':target' => $target, ':asal' => $kelasAsal]);

                $keterangan = "Kenaikan/pindah kelas massal: \"$kelasAsal\" -> \"$target\" ($jumlahSiswa siswa) oleh admin ID " . ($admin['id'] ?? '?');
                catat_aktivitas('UBAH', 'kenaikan_kelas', $keterangan);
                $ringkasan[] = "$kelasAsal → $target ($jumlahSiswa siswa)";

            } elseif ($aksi === 'lulus') {
                $stmt = $db->prepare("UPDATE siswa SET status = 'nonaktif', updated_at = NOW() WHERE kelas = :asal AND status = 'aktif'");
                $stmt->execute([':asal' => $kelasAsal]);

                $keterangan = "Kelulusan/nonaktifkan massal: kelas \"$kelasAsal\" ($jumlahSiswa siswa) oleh admin ID " . ($admin['id'] ?? '?');
                catat_aktivitas('UBAH', 'kenaikan_kelas', $keterangan);
                $ringkasan[] = "$kelasAsal → Lulus/Nonaktif ($jumlahSiswa siswa)";
            }
            // aksi === 'tetap' atau nilai lain -> lewati, tidak ada perubahan
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        set_flash('danger', 'Gagal memproses kenaikan kelas: ' . $e->getMessage());
        header('Location: ' . modul_url('kenaikan_kelas'));
        exit;
    }

    if (empty($ringkasan)) {
        set_flash('warning', 'Tidak ada perubahan yang diterapkan (semua kelas dibiarkan tetap, atau kelas tujuan kosong/sama).');
    } else {
        set_flash('success', 'Kenaikan kelas berhasil diproses: ' . implode('; ', $ringkasan) . '.');
    }
    header('Location: ' . modul_url('siswa'));
    exit;
}

// --- Tampilkan form pemetaan ---
$jumlahPerKelas = $db->query(
    'SELECT kelas, COUNT(*) AS jumlah FROM siswa WHERE status = "aktif" GROUP BY kelas'
)->fetchAll(PDO::FETCH_KEY_PAIR);
$kelasList = array_keys($jumlahPerKelas);
sort($kelasList);
$perJenjang = kelompokkan_per_jenjang($kelasList);
$totalSiswaAktif = array_sum($jumlahPerKelas);
?>
<div class="siap-eyebrow siap-stack-sm">Data &amp; Kelas</div>
<h2 class="siap-h2 mb-3"><i class="bi bi-arrow-up-square"></i> Kenaikan Kelas</h2>

<?php if (empty($kelasList)): ?>
  <div class="ps-card"><div class="ps-body text-center">
    <p class="siap-copy-sm mb-0">Belum ada data siswa aktif.</p>
  </div></div>
<?php else: ?>
  <form method="post" id="formKenaikan">
    <?= csrf_field() ?>

    <div class="promo-kelas-list">
    <?php foreach ($perJenjang as $jenjang => $daftarKelasJenjang): ?>
      <div class="promo-jenjang ps-card mb-4">
        <div class="card-header promo-jenjang-head">
          <h6 class="mb-0">Jenjang <?= e($jenjang) ?> <span class="text-muted fw-normal">(<?= count($daftarKelasJenjang) ?> kelas)</span></h6>
          <button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs text-decoration-none p-0" onclick="terapkanSaranJenjang('<?= e($jenjang) ?>')" style="padding:0.25rem 0.625rem;font-size:0.875rem">
            <i class="bi bi-magic"></i> Terapkan saran ke semua
          </button>
        </div>
        <div class="card-body">
        <div class="promo-grid" data-jenjang="<?= e($jenjang) ?>">
          <?php foreach ($daftarKelasJenjang as $k): ?>
            <?php $saran = saran_kelas_berikutnya($k); $hash = md5($k); $inputId = 'target_' . $hash; ?>
            <div class="promo-card" data-kelas="<?= e($k) ?>" data-saran="<?= e($saran) ?>">
              <div class="promo-card-head">
                <span class="promo-kelas-name"><?= e($k) ?></span>
                <span class="promo-siswa-count"><?= (int) $jumlahPerKelas[$k] ?> siswa</span>
              </div>

              <div class="btn-group w-100 promo-btn-group" role="group">
                <input type="radio" class="btn-check" autocomplete="off" name="aksi[<?= e($k) ?>]" id="tetap_<?= $hash ?>" value="tetap" checked
                       onchange="ubahAksiKartu('<?= $hash ?>', 'tetap')">
                <label class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" for="tetap_<?= $hash ?>" title="Tidak ada perubahan" style="padding:0.375rem 0.5rem"><i class="bi bi-dash-lg"></i></label>

                <input type="radio" class="btn-check" autocomplete="off" name="aksi[<?= e($k) ?>]" id="pindah_<?= $hash ?>" value="pindah"
                       onchange="ubahAksiKartu('<?= $hash ?>', 'pindah')">
                <label class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs" for="pindah_<?= $hash ?>" title="Naik/pindah kelas" style="padding:0.375rem 0.5rem"><i class="bi bi-arrow-right"></i></label>

                <input type="radio" class="btn-check" autocomplete="off" name="aksi[<?= e($k) ?>]" id="lulus_<?= $hash ?>" value="lulus"
                       onchange="ubahAksiKartu('<?= $hash ?>', 'lulus')">
                <label class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" for="lulus_<?= $hash ?>" title="Luluskan/nonaktifkan" style="padding:0.375rem 0.5rem"><i class="bi bi-mortarboard"></i></label>
              </div>

              <div class="promo-target-wrap d-none" id="wrap_<?= $hash ?>">
                <input type="text" class="siap-input siap-input-ios17" name="target[<?= e($k) ?>]" id="<?= $inputId ?>"
                       value="<?= e($saran) ?>" placeholder="mis. XI-A">
              </div>
              <div class="promo-status text-muted" id="status_<?= $hash ?>">Tidak ada perubahan</div>
            </div>
          <?php endforeach; ?>
        </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>

    <div class="promo-summary-bar">
      <div>
        <strong id="ringkasJumlahKelas">0</strong> kelas akan diproses
        <span class="text-muted">&middot; <span id="ringkasJumlahSiswa">0</span> siswa terdampak</span>
        <span class="text-muted">&middot; total <?= $totalSiswaAktif ?> siswa aktif di <?= count($kelasList) ?> kelas</span>
      </div>
      <button type="button" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs" data-bs-toggle="modal" data-bs-target="#modalKonfirmasi" onclick="siapkanRingkasan()">
        <i class="bi bi-arrow-up-square"></i> Proses Kenaikan Kelas
      </button>
    </div>
  </form>

  <!-- Modal konfirmasi: wajib ditinjau dulu sebelum benar-benar diproses,
       krn ini aksi massal yg memindahkan/menonaktifkan banyak siswa sekaligus. -->
  <div class="modal fade" id="modalKonfirmasi" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title"><i class="bi bi-exclamation-triangle text-warning"></i> Konfirmasi Kenaikan Kelas</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>Perubahan berikut akan diterapkan ke <strong>seluruh siswa aktif</strong> di kelas terkait:</p>
          <ul id="daftarRingkasan" class="mb-3"></ul>
          <p id="pesanTanpaPerubahan" class="text-muted d-none">Tidak ada perubahan yang dipilih — semua kelas dibiarkan "Tidak ada perubahan".</p>
          <div class="siap-note siap-note-warning small mb-0">Aksi ini tidak bisa di-undo otomatis. Pastikan pemetaan di atas sudah benar sebelum melanjutkan.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="siap-btn-ios17 siap-btn-ios17-ghost siap-mono-xs" data-bs-dismiss="modal">Batal, Cek Lagi</button>
          <button type="button" class="siap-btn-ios17 siap-btn-ios17-blue siap-mono-xs" onclick="document.getElementById('formKenaikan').submit()">
            <i class="bi bi-check2-circle"></i> Ya, Proses Sekarang
          </button>
        </div>
      </div>
    </div>
  </div>

  <style>
  /* Jarak cadangan di bawah daftar kartu kelas, supaya baris kartu paling
     bawah tidak ketutupan bar ringkasan yang menempel (sticky) di bawah
     layar saat di-scroll -- tanpa ini, kartu terakhir bertabrakan dgn bar. */
  .promo-kelas-list { padding-bottom: 84px; }

  .promo-jenjang.card { border: 1px solid var(--siap-line); border-radius: var(--siap-radius-window); overflow: hidden; box-shadow: var(--siap-shadow-card); }
  .promo-jenjang-head {
    display: flex; justify-content: space-between; align-items: center;
    margin: 0; padding: 14px 22px; background: #fff; border-bottom: 1px solid var(--siap-line);
  }
  .promo-jenjang .card-body { padding: 22px; background: var(--siap-bg); }
  @media (max-width: 575px) { .promo-jenjang-head { padding: 12px 16px; } .promo-jenjang .card-body { padding: 16px; } }
  .promo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 14px;
  }
  .promo-card {
    background: rgba(255,255,255,0.9);
    border: 1px solid var(--siap-line);
    border-radius: var(--siap-radius-window);
    padding: 1rem;
    transition: box-shadow .15s ease, border-color .15s ease;
  }
  .promo-card:hover { box-shadow: 0 6px 18px rgba(20,33,61,.08); border-color: var(--siap-blue); }
  .promo-card-head {
    display: flex; justify-content: space-between; align-items: baseline;
    margin-bottom: 10px;
  }
  .promo-kelas-name { font-weight: 700; font-size: 16px; color: var(--siap-ink); }
  .promo-siswa-count { font-size: 12px; color: var(--siap-muted); }
  .promo-btn-group .btn { padding: 6px 0; font-size: 14px; }
  .promo-target-wrap { margin-top: 10px; }
  .promo-status {
    margin-top: 8px; font-size: 12px; font-weight: 600;
  }
  .promo-status.status-pindah { color: var(--siap-blue); }
  .promo-status.status-lulus { color: var(--siap-success-text); }

  .promo-summary-bar {
    position: sticky; bottom: 12px;
    background: #fff; border: 1px solid var(--siap-line); border-radius: var(--siap-radius-window);
    padding: 14px 18px; margin-top: 8px;
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
    box-shadow: 0 4px 20px rgba(20,33,61,.15);
    z-index: 5; /* tetap di atas kartu kalau msh ada sisa overlap krn ukuran layar */
  }
  </style>

  <script>
  const SARAN_STATUS_LABEL = { tetap: 'Tidak ada perubahan', pindah: 'Naik/pindah kelas', lulus: 'Luluskan / nonaktifkan' };

  function ubahAksiKartu(hash, aksi) {
    const input = document.getElementById('target_' + hash);
    const wrap = document.getElementById('wrap_' + hash);
    const status = document.getElementById('status_' + hash);

    wrap.classList.toggle('d-none', aksi !== 'pindah');
    input.disabled = aksi !== 'pindah';
    if (aksi === 'pindah') input.focus();

    status.textContent = SARAN_STATUS_LABEL[aksi] || '';
    status.className = 'promo-status text-muted' + (aksi !== 'tetap' ? ' status-' + aksi : '');

    perbaruiRingkasanBar();
  }

  function terapkanSaranJenjang(jenjang) {
    document.querySelectorAll(`.promo-grid[data-jenjang="${CSS.escape(jenjang)}"] .promo-card`).forEach(function (card) {
      const saran = card.dataset.saran;
      if (!saran) return; // mis. jenjang XII tanpa saran otomatis -> lewati, biar admin pilih manual (Luluskan?)
      const hash = card.querySelector('input[type=text]')?.id.replace('target_', '');
      if (!hash) return;
      document.getElementById('pindah_' + hash).checked = true;
      document.getElementById('target_' + hash).value = saran;
      ubahAksiKartu(hash, 'pindah');
    });
  }

  function perbaruiRingkasanBar() {
    let jumlahKelas = 0, jumlahSiswa = 0;
    document.querySelectorAll('.promo-card').forEach(function (card) {
      const checked = card.querySelector('input[type=radio]:checked');
      if (!checked || checked.value === 'tetap') return;
      jumlahKelas++;
      jumlahSiswa += parseInt(card.querySelector('.promo-siswa-count').textContent, 10) || 0;
    });
    document.getElementById('ringkasJumlahKelas').textContent = jumlahKelas;
    document.getElementById('ringkasJumlahSiswa').textContent = jumlahSiswa;
  }

  function siapkanRingkasan() {
    const daftar = document.getElementById('daftarRingkasan');
    const pesanKosong = document.getElementById('pesanTanpaPerubahan');
    daftar.innerHTML = '';
    let ada = false;

    document.querySelectorAll('input[type=radio]:checked').forEach(function (radio) {
      if (radio.value === 'tetap') return;
      const namaField = radio.name; // aksi[KELAS]
      const kelasAsal = namaField.slice(5, -1); // buang "aksi[" di depan dan "]" di belakang
      const li = document.createElement('li');
      ada = true;
      if (radio.value === 'pindah') {
        const input = radio.closest('.promo-card').querySelector('input[type=text]');
        const target = input ? input.value.trim() : '';
        li.textContent = kelasAsal + ' → ' + (target || '(kosong, tidak akan diproses)');
      } else if (radio.value === 'lulus') {
        li.textContent = kelasAsal + ' → Lulus / Nonaktif';
      }
      daftar.appendChild(li);
    });

    pesanKosong.classList.toggle('d-none', ada);
  }
  </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>