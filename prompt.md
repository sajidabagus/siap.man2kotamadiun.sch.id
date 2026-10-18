**Role:** Principal Security Engineer & Lead Software Architect.
**Effort Level:** Maximum (Gunakan penalaran mendalam/Chain-of-Thought yang paling teliti).

**Konteks Tugas:**
Saya sedang mengembangkan aplikasi SIAP (Sistem Presensi Siswa) berbasis PHP Native, MySQL, dengan fitur utama *Device Binding* dan QR Code Dinamis.
Sebelumnya, agen AI lain (GLM) telah melakukan audit awal dan hasilnya disimpan dalam file `laporan.md`. Beberapa poin di laporan tersebut sudah saya kerjakan dan ditandai sebagai "Selesai".

**Instruksi Eksekusi Langkah-demi-Langkah:**

1. **Baca dan Pahami Konteks Saat Ini:**
* Baca file `laporan.md` secara keseluruhan. Pahami poin-poin apa saja yang sudah ditemukan oleh audit sebelumnya, dan perhatikan secara khusus tugas-tugas yang sudah ditandai "Selesai".
* Baca basis kode aplikasi SIAP, khususnya file krusial seperti: `includes/device_binding.php`, `includes/presensi_core.php`, `includes/keamanan.php`, `api/scan.php`, dan `assets/js/device.js`.


2. **Lakukan Analisis Lanjutan (Deep-Dive Analysis):**
* Lakukan audit kode independen. Jangan sekadar mengulang apa yang ada di `laporan.md`.
* Fokus pada *edge cases* (kasus ekstrem) tingkat tinggi, seperti:
* **Race Conditions:** Apa yang terjadi jika ratusan siswa melakukan POST request ke `/api/presensi` di milidetik yang sama? Apakah ada potensi presensi ganda yang lolos dari pengecekan database?
* **Cryptographic Flaws:** Evaluasi implementasi SHA-256 pada UUID. Apakah *salt* yang digunakan (seperti `'v2|'`) sudah cukup kuat terhadap serangan *rainbow table* atau manipulasi memori browser?
* **Bypass Keamanan Tingkat Lanjut:** Cari celah di mana *session hijacking*, XSS pada panel admin, atau manipulasi *cookie* (penggandaan cookie `device_uuid` ke HP lain) bisa terjadi.
* **Optimasi Eksekusi Skrip:** Adakah kueri N+1 pada halaman `/rekap` atau `/siswa` di panel admin yang bisa membebani server/aaPanel?




3. **Bandingkan & Filter:**
* Bandingkan temuan baru Anda dengan isi `laporan.md`.
* **Aturan Mutlak:** JANGAN memasukkan kembali celah atau saran yang sudah dibahas oleh GLM, terlebih lagi yang sudah ditandai "Selesai". Filter temuan Anda agar 100% merupakan hal baru atau pendalaman teknis dari poin yang belum tuntas.


4. **Eksekusi Penulisan (Tulis ke File):**
* Buka dan edit file `laporan.md`.
* Tambahkan *header* baru di bagian paling bawah dokumen bernama: `## 🚀 Audit Lanjutan: DeepSeek 4.1 Flash (Max Effort)`
* Tuliskan hasil temuan baru Anda beserta contoh potongan kode perbaikannya (*code snippet*) di bawah *header* tersebut.
* Pastikan format Markdown tetap rapi dan isi file yang sudah ada sebelumnya TIDAK terhapus atau berubah.