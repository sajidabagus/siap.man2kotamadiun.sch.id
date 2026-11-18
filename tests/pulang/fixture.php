<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/includes/izin.php';
$db = getDB();
$db->exec("INSERT INTO settings(id,nama_sekolah,hari_efektif) VALUES(1,'Sekolah Pengujian','1,2,3,4,5,6,7')");
portal_siapkan_tabel(); izin_siapkan_tabel(); get_settings();
$db->exec("INSERT INTO siswa(id,nis,nisn,nama,kelas,tahun_pelajaran,qr_token,created_at) VALUES
 (1,'UJI1','0000000001','Siswa Pengujian','X Uji','2026/2027','UJI1',NOW()),
 (2,'UJI2','0000000002','Siswa Pengujian Dua','X Uji','2026/2027','UJI2',NOW())");
$db->exec("INSERT INTO users(id,nama,username,password,role) VALUES(1,'Admin Pengujian','testadmin','test-only','admin')");
$q=$db->prepare("INSERT INTO siswa_akun(siswa_id,status,password_hash) VALUES(?,'aktif',?)");
foreach([1,2] as $id) $q->execute([$id,password_hash('UjiAman123!',PASSWORD_DEFAULT)]);
$q=$db->prepare("INSERT INTO device_bindings(siswa_id,device_hash,device_name,browser,os,status) VALUES(?,?,'Perangkat Uji','Chromium','Linux','ACTIVE')");
foreach([1,2] as $id) $q->execute([$id,build_device_hash(str_repeat((string)$id,8).'-'.str_repeat((string)$id,4).'-4'.str_repeat((string)$id,3).'-8'.str_repeat((string)$id,3).'-'.str_repeat((string)$id,12))]);
// No data inserted by original dump; no production config/data used.
require_once __DIR__ . '/includes/presensi_core.php';
$j=['efektif'=>true,'jam_buka'=>'05:40:00','batas_absen'=>'09:00:00','batas_terlambat'=>'07:00:00','pulang_buka'=>'13:00:00','pulang_tutup'=>'16:00:00'];
function cek($ok,$s){if(!$ok)throw new RuntimeException($s);echo "PASS $s\n";}
foreach(['05:39:59'=>null,'05:40:00'=>'datang','07:00:00'=>'datang','08:59:59'=>'datang','09:00:00'=>null,'12:59:59'=>null,'13:00:00'=>'pulang','15:59:59'=>'pulang','16:00:00'=>null] as $t=>$expected) cek(presensi_sesi($j,$t)===$expected,"sesi boundary $t");
cek(presensi_status_datang($j,'07:00:00')==='HADIR','tepat batas terlambat tetap HADIR');
cek(presensi_status_datang($j,'07:00:01')==='TERLAMBAT','satu detik setelah batas TERLAMBAT');
$j['batas_terlambat']='';cek(presensi_status_datang($j,'08:59:59')==='HADIR','late optional');
$j['batas_absen']='14:00:00';cek(presensi_sesi($j,'14:30:00')===null,'agenda overlap fails closed');
$base=['jam_masuk'=>'05:40','batas_absen'=>'09:00','batas_terlambat'=>'07:00','pulang_buka'=>'13:00','pulang_tutup'=>'16:00'];
cek(!jam_absensi_validasi($base),'valid settings');
foreach([['jam_masuk'=>'09:00'],['batas_terlambat'=>'09:00'],['pulang_buka'=>'09:00'],['pulang_tutup'=>'12:00'],['jam_masuk'=>'25:00'],['pulang_buka'=>'']] as $x) cek((bool)jam_absensi_validasi(array_replace($base,$x)),'invalid settings '.json_encode($x));
cek(!jam_absensi_validasi(array_replace($base,['batas_terlambat'=>''])),'valid no late limit');
echo "PASS fixture and boundary tests\n";
