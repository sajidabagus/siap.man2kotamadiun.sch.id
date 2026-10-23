<?php
// /portal/izin/{id}/lampiran — hanya pemilik pengajuan
$akun = portal_wajib_login(true);   // lampiran bisa berisi data kesehatan: sesi masuk-otomatis harus membuktikan password
$row = izin_ambil($izinId);
if (!$row || (int) $row['siswa_id'] !== (int) $akun['siswa_id'] || !$row['lampiran']) portal_halaman_pesan(404, 'Lampiran tidak ditemukan', 'Lampiran yang Anda cari tidak ada.');
izin_stream_lampiran($row);
