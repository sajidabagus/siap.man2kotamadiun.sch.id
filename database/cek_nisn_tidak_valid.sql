-- Daftar siswa yang NISN-nya BUKAN tepat 10 digit angka (kosong, kurang, lebih, atau mengandung huruf/spasi).
-- Siswa ini tidak bisa aktivasi/masuk ke portal sampai NISN-nya diperbaiki (lewat Data Siswa > Edit).
-- Hanya membaca data (SELECT), aman dijalankan kapan saja.
SELECT id, nis, nisn, CHAR_LENGTH(IFNULL(nisn, '')) AS panjang, nama, kelas, status
FROM siswa
WHERE nisn IS NULL OR nisn = '' OR nisn NOT REGEXP '^[0-9]{10}$'
ORDER BY kelas, nama;
