Role: UI/UX Project Manager & Frontend Architect.
Mode: Workspace Analysis & Planning (Open Code).

Konteks Tugas:
Kita akan melakukan redesain total antarmuka aplikasi SIAP menggunakan Bootstrap 5 dan HTML Native, dengan berpatokan penuh pada spesifikasi di file desain.md. Sebelum mengeksekusi kode, kita membutuhkan roadmap atau daftar tugas (To-Do List) yang jelas mengenai halaman/file apa saja yang harus dirombak.

Instruksi Eksekusi Langkah-demi-Langkah:

Pahami Referensi Desain:

Baca file desain.md untuk memahami skala perombakan (apakah hanya warna, atau sampai ke struktur komponen form, tabel, dan card).

Pindai Struktur Proyek SIAP:

Analisis direktori aplikasi SIAP saat ini. Identifikasi semua file .php yang memiliki antarmuka pengguna (UI) yang perlu disesuaikan dengan desain baru.

Kelompokkan file-file tersebut ke dalam kategori logis. Contoh:

Fondasi Global: includes/header.php, includes/footer.php, file CSS utama.

Autentikasi & Publik: login.php, beranda.php, halaman-halaman di folder presensi/ (seperti index.php, konfirmasi.php).

Panel Admin: dashboard.php, halaman master di folder panel/ (seperti siswa.php, qr_gerbang.php, dll).

Perbarui Dokumen Laporan (Write to File):

Buka file laporan.md yang sudah ada (file tempat kita menulis hasil audit keamanan sebelumnya).

Tambahkan header baru di bagian paling bawah dokumen: ## 🎨 Roadmap Redesain UI (Adaptasi SIPIKET).

Tuliskan hasil analisismu menjadi To-Do List (menggunakan format checkbox Markdown - [ ]) yang terstruktur berdasarkan kategori file di atas.

Berikan estimasi singkat atau catatan khusus di samping nama file (misal: - [ ] login.php (Fokus memusatkan form dan mengubah warna tombol primary)).

Laporan & Jeda Mutlak (Stop & Ask):

Berikan saya ringkasan singkat ada berapa total file yang masuk ke dalam roadmap redesain.

WAJIB BERHENTI. Jangan melakukan perubahan kode HTML/CSS pada file apa pun di tahap ini.

Akhiri responsmu dengan: "Roadmap redesain UI telah berhasil ditambahkan ke dalam file laporan.md. Silakan periksa daftar tugas tersebut. Jika setuju, beri perintah 'Mulai kerjakan tugas pertama' untuk mengeksekusi perombakan file per file."