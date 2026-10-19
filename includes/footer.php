  </main>

  <!-- Footer visual iOS 17: latar --siap-bg, tipografi muted, spacing luas -->
  <footer class="siap-section-ios17 siap-pb-4 siap-pb-lg-5" style="background-color: var(--siap-bg);">
    <div class="siap-shell text-center">
      <div class="siap-eyebrow">Sistem Presensi Siswa</div>
      <div class="siap-h4 siap-mono-xs"><?= e($settings['nama_sekolah'] ?? 'Presensi Siswa') ?> &copy; <?= date('Y') ?></div>
    </div>
  </footer>

<script src="<?= BASE_URL ?>/assets/js/vendor/bootstrap.bundle.min.js?v=<?= @filemtime(__DIR__ . '/../assets/js/vendor/bootstrap.bundle.min.js') ?: time() ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: time() ?>"></script>
<script>
  // Toggle drawer mobile (offcanvas)
  const btnHamburger = document.getElementById('btnHamburger');
  if (btnHamburger) {
    btnHamburger.addEventListener('click', function () {
      const drawer = document.getElementById('drawerMobile');
      if (drawer) {
        const bsOffcanvas = bootstrap.Offcanvas.getInstance(drawer) || new bootstrap.Offcanvas(drawer);
        bsOffcanvas.toggle();
        btnHamburger.setAttribute('aria-expanded', bsOffcanvas._isShown() ? 'true' : 'false');
      }
    });
  }
</script>
<?= script_log_cetak() ?>
</body>
</html>