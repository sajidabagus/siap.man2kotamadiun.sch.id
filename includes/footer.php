</div>
  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: time() ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/kompres-gambar.js?v=<?= @filemtime(__DIR__ . '/../assets/js/kompres-gambar.js') ?: time() ?>"></script>
<?= script_log_cetak() ?>
</body>
</html>