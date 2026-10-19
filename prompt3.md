Role: Lead QA Automation Engineer & Principal Security Tester.
Effort Level: Maximum (Fokus pada Integration & Regression Testing).

Konteks Tugas:
Kamu sebelumnya telah membantu saya mengaudit dan mengeksekusi perbaikan untuk aplikasi presensi SIAP (Sistem Presensi Siswa). Saat ini, seluruh poin audit dari laporan.md (C1-C7 dan D1-D9) telah selesai diimplementasikan. Karena kita telah melakukan perubahan masif pada logika inti (seperti rate-limiting ganda, HMAC v3, transisi file cache-busting, dan penguncian transaksi DB), risiko terjadinya bug regresi (fitur lama yang rusak karena kode baru) sangat tinggi.

Instruksi Eksekusi:

Analisis Regresi & Interaksi Kode (Cross-Feature Review):

Periksa kembali kode sumber aplikasi SIAP saat ini. Fokus pada interaksi antar fitur yang baru diperbaiki.

Contoh analisis: Apakah kewajiban GPS untuk QR Kelas (D2) menyebabkan dead-end (halaman macet) di frontend jika browser siswa menolak izin lokasi? Apakah blok try/catch PDO 23000 pada saat race condition presensi (C5) bentrok dengan rate-limiter APCu yang baru dipasang (D4)?

Apakah cache-busting (D5) sudah benar-benar menyelesaikan masalah sinkronisasi secret untuk fitur HMAC v3 (D1)?

Cari Celah Logika Baru (Blind Spots):

Lakukan evaluasi terhadap file-file krusial (includes/ratelimit.php, includes/device_binding.php, api/presensi.php, api/scan.php).

Adakah kesalahan penanganan error (seperti HTTP 500 yang masih lolos) atau logika fallback yang gagal jika environment (seperti APCu) tidak tersedia di server?

Format Laporan Akhir (Testing Report):

JANGAN mengulang temuan yang sudah diperbaiki di tahap C1-C7 atau D1-D9.

Jika kamu menemukan celah baru atau bug regresi akibat perbaikan kita, buatkan daftar "Bug Regresi & Temuan Post-Audit" beserta solusi kodenya.

Jika kamu merasa kode saat ini sudah 100% solid dan aman dari sisi server-side, buatkan daftar "Skenario Pengujian Manual (End-to-End Test Plan)" sebanyak 5 poin krusial yang harus saya uji secara manual langsung di browser/HP (misalnya skenario uji coba mematikan GPS, atau uji coba manipulasi cookie).