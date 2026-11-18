<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/includes/izin.php';
$db = getDB();
$db->exec("INSERT INTO settings (id,nama_sekolah,hari_efektif) VALUES(1,'Sekolah Pengujian','1,2,3,4,5,6,7')");
portal_siapkan_tabel(); izin_siapkan_tabel(); get_settings();
$db->exec("INSERT INTO siswa(id,nis,nisn,nama,kelas,tahun_pelajaran,qr_token) VALUES
 (1,'UJI1','0000000001','Siswa Pengujian','X Uji','2026/2027','UJI1'),
 (2,'UJI2','0000000002','Siswa Pengujian Dua','X Uji','2026/2027','UJI2')");
$q=$db->prepare("INSERT INTO siswa_akun(siswa_id,status,password_hash) VALUES (?,'aktif',?)");
foreach ([1,2] as $sid) $q->execute([$sid,password_hash('UjiAman123!',PASSWORD_DEFAULT)]);
$q=$db->prepare("INSERT INTO device_bindings(siswa_id,device_hash,device_name,browser,os,status) VALUES(?,?,'Perangkat Pengujian','Safari','iOS',?)");
$q->execute([1,build_device_hash('11111111-1111-4111-8111-111111111111'),'ACTIVE']);
$q->execute([2,build_device_hash('22222222-2222-4222-8222-222222222222'),'BLOCKED']);
echo "PASS synthetic accounts, two devices, schema-only disposable DB\n";
