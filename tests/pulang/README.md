# Regresi absen pulang

Lihat `docs/absen-pulang.md` untuk aturan, migrasi, prasyarat dan batas pengujian.

```sh
NODE_PATH=/tmp/siap-ui-tools/node_modules python3 tests/pulang/run.py /tmp/pulang-results
```

Jalankan hanya pada worker/container disposable dengan MariaDB root lokal untuk database tes acak. Tidak memakai config/data produksi atau INSERT dump. `fixture.php` hanya CLI dan `router.php` hanya PHP development server loopback, bukan endpoint aplikasi produksi.

Tes mencakup boundary fungsi asli; migrasi check/idempoten/legacy; PHP HTTP datang/pulang/reject/izin/dispensasi/Alpa/GPS/perangkat; UI pengaturan+timeline dan form save/CSRF; scan-identify-preview-confirm-result asli; portal/history; laporan/cetak/CSV/Excel dan browser390/1366.
Semua akun, dokumen, UUID dan data screenshot sintetis. Tidak mengklaim iPhone/kamera fisik atau load test konkurensi.
