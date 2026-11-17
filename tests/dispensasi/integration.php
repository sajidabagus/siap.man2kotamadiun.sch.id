<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/izin.php';
$db = getDB();
function autoBaru(): void { $code = 'require ' . var_export(dirname(__DIR__) . '/includes/izin.php', true) . '; jalankan_alpa_otomatis();'; passthru(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code), $exit); if ($exit) throw new RuntimeException('Auto Alpa child request failed'); }
function cek(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL: ' . $label); echo "PASS $label\n"; }
$db->exec("ALTER TABLE settings ADD COLUMN batas_absen TIME NOT NULL DEFAULT '00:00:01'");
$db->exec("INSERT INTO settings (id,nama_sekolah,hari_efektif) VALUES (1,'Sekolah Pengujian','1,2,3,4,5,6,7')");
$db->exec("INSERT INTO siswa (id,nis,nisn,nama,kelas,tahun_pelajaran,qr_token,created_at) VALUES (1,'TEST1','0000000001','Siswa Pengujian','X Uji','2026/2027','TEST1','2020-01-01'),(2,'TEST2','0000000002','Siswa Pengujian Dua','X Uji','2026/2027','TEST2','2020-01-01')");
$db->exec("INSERT INTO users (id,nama,username,password,role) VALUES (1,'Admin Pengujian','testadmin','test-only','admin')");
cek(izin_siapkan_tabel(), 'schema init');
portal_siapkan_tabel();
$db->exec("INSERT INTO siswa_akun (siswa_id,status,password_hash) VALUES (1,'aktif','test-only'),(2,'aktif','test-only')");
// Simulate the previous schema with real legacy data in THIS disposable DB only.
$db->exec("ALTER TABLE izin_pengajuan DROP COLUMN mode_durasi, DROP COLUMN jam_mulai, DROP COLUMN jam_selesai, MODIFY jenis ENUM('izin','sakit') NOT NULL");
$db->exec("ALTER TABLE izin_hari DROP COLUMN kelas_dispensasi");
$db->prepare("INSERT INTO izin_pengajuan (siswa_id,jenis,tanggal_mulai,tanggal_selesai,alasan,status) VALUES (1,'sakit',:t,:t2,'Data legacy pengujian','ditolak')")->execute([':t'=>date('Y-m-d'),':t2'=>date('Y-m-d')]);
dispensasi_migrasi($db); dispensasi_migrasi($db);
cek($db->query('SELECT jenis FROM izin_pengajuan ORDER BY id LIMIT 1')->fetchColumn()==='sakit','legacy data preserved through enum/additive migration');
$db->exec('DELETE FROM izin_pengajuan');
cek((bool)$db->query("SHOW COLUMNS FROM izin_pengajuan LIKE 'jam_mulai'")->fetch(), 'migration idempotent');
$today=date('Y-m-d');$tomorrow=date('Y-m-d',strtotime('+1 day'));
$input=['jenis'=>'dispensasi','mode_durasi'=>'jam','mulai'=>$today,'selesai'=>$tomorrow,'jam_mulai'=>'10:00','jam_selesai'=>'11:00','alasan'=>'Mengikuti kegiatan lomba sekolah'];
$v=izin_validasi(1,$input);cek(!$v['galat'] && $v['data']['selesai']===$today, 'hour mode normalizes end date');
foreach ([['jam_selesai'=>'09:00'],['jam_selesai'=>'10:00'],['jam_mulai'=>'24:00'],['mode_durasi'=>'invalid'],['alasan'=>'x'],['mulai'=>'2026-02-30']] as $bad) cek((bool)izin_validasi(1,array_merge($input,$bad))['galat'],'invalid duration/date/activity rejected');
cek((bool)izin_validasi(1,array_merge($input,['mode_durasi'=>'beberapa_hari','selesai'=>'']))['galat'],'multiday requires explicit end');
cek((bool)izin_validasi(1,array_merge($input,['mode_durasi'=>'beberapa_hari','selesai'=>date('Y-m-d',strtotime('-1 day'))]))['galat'],'date order validated');
$db->prepare("INSERT INTO presensi (siswa_id,kelas_saat_presensi,tanggal,jam_masuk,status,metode) VALUES (1,'X Uji',:t,'07:00','HADIR','QR')")->execute([':t'=>$today]);
cek(!izin_validasi(1,$input)['galat'],'partial allowed after attendance');
cek((bool)izin_validasi(1,array_merge($input,['jenis'=>'izin']))['galat'],'legacy leave still disallows after attendance');
cek(!izin_buat(1,$input,null)['ok'],'dispensasi photo required');
function draft(array $in,int $sid=1):int { $db=getDB();$db->prepare("INSERT INTO izin_pengajuan (siswa_id,jenis,tanggal_mulai,tanggal_selesai,alasan,mode_durasi,jam_mulai,jam_selesai,lampiran,lampiran_tipe) VALUES (:s,'dispensasi',:a,:b,'Kegiatan pengujian dokumen',:m,:jm,:js,'00000000000000000000000000000000.png','image/png')")->execute([':s'=>$sid,':a'=>$in['mulai'],':b'=>$in['selesai'],':m'=>$in['mode_durasi'],':jm'=>$in['jam_mulai']??null,':js'=>$in['jam_selesai']??null]);return (int)$db->lastInsertId(); }
$id=draft(array_merge($input,['selesai'=>$today]));
cek((bool)izin_validasi(1,$input)['galat'],'overlap pending rejected');
cek(!izin_validasi(1,array_merge($input,['jam_mulai'=>'11:00','jam_selesai'=>'12:00']))['galat'],'adjacent nonoverlap allowed');
$admin=['id'=>1,'nama'=>'Admin Pengujian'];
$before=$db->query('SELECT * FROM presensi ORDER BY id')->fetchAll();
cek(izin_setujui($id,$admin,'Dokumen kegiatan diperiksa')['ok'],'approve partial');
cek(!izin_setujui($id,$admin)['ok'],'double approval rejected');
cek($before===$db->query('SELECT * FROM presensi ORDER BY id')->fetchAll(),'partial preserves original attendance');
cek(count(dispensasi_daftar_hari($today,$today,1))===1,'approved overlay appears');
cek((int)$db->query('SELECT COUNT(*) FROM portal_notifikasi')->fetchColumn()===1,'approval notification');
cek(izin_cabut($id,$admin,'Kegiatan dibatalkan petugas')['ok'],'revoke partial');
cek(!dispensasi_daftar_hari($today,$today,1),'revoked removed from overlay');
$id2=draft(array_merge($input,['selesai'=>$today,'mode_durasi'=>'sehari','jam_mulai'=>null,'jam_selesai'=>null]));
cek(izin_tolak($id2,$admin,'Dokumen belum sesuai')['ok'],'reject with reason');
$id3=draft(array_merge($input,['selesai'=>$today]));
cek(!izin_batalkan_siswa($id3,2)['ok'],'other student cannot cancel');
cek(izin_batalkan_siswa($id3,1)['ok'],'owner can cancel pending');
cek(!izin_setujui($id3,$admin)['ok'],'cancelled cannot approve');
// Full-day/multiday approval must remove only automatic ALPA and exempt future generation.
$full=['jenis'=>'dispensasi','mode_durasi'=>'sehari','mulai'=>$today,'selesai'=>$today,'jam_mulai'=>null,'jam_selesai'=>null];
$db->prepare("INSERT INTO presensi (siswa_id,kelas_saat_presensi,tanggal,jam_masuk,status,metode) VALUES (2,'X Uji',:t,'00:00','ALPA','OTOMATIS')")->execute([':t'=>$today]);
$f=draft($full,2);cek(izin_setujui($f,$admin)['ok'],'full day approval after cutoff');
cek(!(bool)$db->query("SELECT 1 FROM presensi WHERE siswa_id=2 AND tanggal=CURDATE()")->fetchColumn(),'existing automatic ALPA removed');
$db->exec("UPDATE settings SET alpa_otomatis_terakhir=NULL,alpa_otomatis_jam=NULL");autoBaru();
cek(!(bool)$db->query("SELECT 1 FROM presensi WHERE siswa_id=2 AND tanggal=CURDATE()")->fetchColumn(),'approved full day excluded from automatic ALPA');
cek(izin_cabut($f,$admin,'Pembebasan kegiatan dibatalkan')['ok'],'full day revocation');
cek($db->query("SELECT status FROM presensi WHERE siswa_id=2 AND tanggal=CURDATE()")->fetchColumn()==='ALPA','revocation restores automatic ALPA when policy conditions hold');
$hadir=draft($full,1);$qrBefore=$db->query("SELECT * FROM presensi WHERE siswa_id=1 AND tanggal=CURDATE()")->fetch();
cek(izin_setujui($hadir,$admin)['ok'],'full day allowed alongside attendance');
cek($qrBefore===$db->query("SELECT * FROM presensi WHERE siswa_id=1 AND tanggal=CURDATE()")->fetch(),'full day preserves original HADIR byte-for-byte');
cek(izin_cabut($hadir,$admin,'Kegiatan siswa dibatalkan')['ok'],'revoke full day with attendance');
cek($qrBefore===$db->query("SELECT * FROM presensi WHERE siswa_id=1 AND tanggal=CURDATE()")->fetch(),'revocation preserves original HADIR');
$yesterday=date('Y-m-d',strtotime('-1 day'));$twoDays=date('Y-m-d',strtotime('-2 days'));
foreach ([$twoDays,$yesterday] as $t) $db->prepare("INSERT INTO presensi (siswa_id,kelas_saat_presensi,tanggal,jam_masuk,status,metode) VALUES (1,'X Uji',:t,'07:00','HADIR','QR'),(2,'X Uji',:t2,'00:00','ALPA','OTOMATIS')")->execute([':t'=>$t,':t2'=>$t]);
$multi=draft(array_merge($full,['mode_durasi'=>'beberapa_hari','mulai'=>$twoDays,'selesai'=>$yesterday]),2);
cek(izin_setujui($multi,$admin)['ok'],'multiday approved');
cek(count(dispensasi_daftar_hari($twoDays,$yesterday,2))===2,'multiday inclusive two effective days');
cek((int)$db->query("SELECT COUNT(*) FROM presensi WHERE siswa_id=2 AND tanggal<CURDATE()")->fetchColumn()===0,'multiday removes covered automatic ALPA');
cek(izin_cabut($multi,$admin,'Kegiatan multihari dibatalkan')['ok'],'multiday revoked');
cek((int)$db->query("SELECT COUNT(*) FROM presensi WHERE siswa_id=2 AND tanggal<CURDATE() AND status='ALPA'")->fetchColumn()===2,'multiday revoke reevaluates each covered day');
$future=draft(array_merge($full,['mulai'=>$tomorrow,'selesai'=>$tomorrow]),2);
cek(izin_setujui($future,$admin)['ok'] && izin_cabut($future,$admin,'Kegiatan besok dibatalkan')['ok'],'future full day approve revoke');
cek((int)$db->query("SELECT COUNT(*) FROM presensi WHERE tanggal>CURDATE()")->fetchColumn()===0,'future revoke creates no ALPA');
// A timed dispensasi never removes or exempts a full-day ALPA.
$timed=draft(array_merge($input,['selesai'=>$today]),2);cek(izin_setujui($timed,$admin)['ok'],'timed dispensasi for pupil without scan');
cek($db->query("SELECT status FROM presensi WHERE siswa_id=2 AND tanggal=CURDATE()")->fetchColumn()==='ALPA','timed approval does not remove ALPA');
$db->exec("DELETE FROM presensi WHERE siswa_id=2 AND tanggal=CURDATE(); UPDATE settings SET alpa_otomatis_terakhir=NULL,alpa_otomatis_jam=NULL");autoBaru();
cek($db->query("SELECT status FROM presensi WHERE siswa_id=2 AND tanggal=CURDATE()")->fetchColumn()==='ALPA','timed approval does not exclude auto ALPA');
cek(izin_cabut($timed,$admin,'Kegiatan jam dibatalkan')['ok'],'timed revoke');
// Manual ALPA is an original staff decision and must not be deleted.
$db->exec("UPDATE presensi SET metode='MANUAL' WHERE siswa_id=2 AND tanggal=CURDATE()");
$manual=draft($full,2);cek(izin_setujui($manual,$admin)['ok'],'approve full with manual ALPA');
cek($db->query("SELECT metode FROM presensi WHERE siswa_id=2 AND tanggal=CURDATE()")->fetchColumn()==='MANUAL','manual ALPA preserved');
cek(izin_cabut($manual,$admin,'Kegiatan dibatalkan sekolah')['ok'],'manual ALPA revoke');
// Revocation must respect holiday guard, no-scan guard, and class-specific later cutoffs.
$threeDays=date('Y-m-d',strtotime('-3 days'));
$noScan=draft(array_merge($full,['mulai'=>$threeDays,'selesai'=>$threeDays]),2);
cek(izin_setujui($noScan,$admin)['ok'] && izin_cabut($noScan,$admin,'Kegiatan tanpa scan dibatalkan')['ok'],'no-scan day revoke');
$q=$db->prepare('SELECT COUNT(*) FROM presensi WHERE siswa_id=2 AND tanggal=:t');$q->execute([':t'=>$threeDays]);cek((int)$q->fetchColumn()===0,'no-scan holiday guard preserved');
$db->prepare('DELETE FROM presensi WHERE siswa_id=2 AND tanggal=:t')->execute([':t'=>$twoDays]);
$holiday=draft(array_merge($full,['mulai'=>$twoDays,'selesai'=>$twoDays]),2);cek(izin_setujui($holiday,$admin)['ok'],'approve before holiday agenda');
$db->prepare("INSERT INTO agenda_sekolah (judul,jenis,tanggal_mulai,tanggal_selesai) VALUES ('Libur pengujian','libur',:t,:t2)")->execute([':t'=>$twoDays,':t2'=>$twoDays]);agenda_semua(true);
cek(izin_cabut($holiday,$admin,'Hari telah menjadi libur')['ok'],'revoke after holiday agenda');
$q->execute([':t'=>$twoDays]);cek((int)$q->fetchColumn()===0,'holiday creates no ALPA');
$db->exec('DELETE FROM agenda_sekolah');agenda_semua(true);
$db->exec('DELETE FROM presensi WHERE siswa_id=2 AND tanggal=CURDATE()');
$db->prepare("INSERT INTO agenda_sekolah (judul,jenis,tanggal_mulai,tanggal_selesai,kelas,jam_buka,batas_terlambat,batas_absen) VALUES ('Kegiatan pengujian','kegiatan',:t,:t2,'X Uji','00:00','23:58','23:59')")->execute([':t'=>$today,':t2'=>$today]);agenda_semua(true);
$beforeCutoff=draft($full,2);cek(izin_setujui($beforeCutoff,$admin)['ok'] && izin_cabut($beforeCutoff,$admin,'Kegiatan sebelum cutoff dibatalkan')['ok'],'revoke before class cutoff');
$q->execute([':t'=>$today]);cek((int)$q->fetchColumn()===0,'before-cutoff creates no ALPA');
$db->exec('DELETE FROM agenda_sekolah');agenda_semua(true);
// New submissions require documents; pre-existing legacy records without documents remain processable.
$legacy=['jenis'=>'izin','mulai'=>$tomorrow,'selesai'=>$tomorrow,'alasan'=>'Keperluan keluarga pengujian'];
foreach (['izin','sakit','dispensasi'] as $jenis) {
    $missing=izin_buat(2,array_merge($legacy,['jenis'=>$jenis,'mode_durasi'=>'sehari']),null);
    cek(!$missing['ok'] && isset($missing['galat']['lampiran']),$jenis.' single day rejects missing document');
}
$db->prepare("INSERT INTO izin_pengajuan (siswa_id,jenis,tanggal_mulai,tanggal_selesai,alasan) VALUES (2,'izin',:a,:b,'Pengajuan lama tanpa dokumen')")->execute([':a'=>$tomorrow,':b'=>$tomorrow]);
$old=['id'=>(int)$db->lastInsertId()];
cek(izin_ambil($old['id'])['lampiran']===null,'legacy record without document remains readable');
cek(izin_setujui($old['id'],$admin)['ok'],'legacy izin approval');
$ls=$db->prepare('SELECT status FROM presensi WHERE siswa_id=2 AND tanggal=:t');$ls->execute([':t'=>$tomorrow]);cek($ls->fetchColumn()==='IZIN','legacy writes IZIN not dispensasi');
cek(izin_cabut($old['id'],$admin,'Keperluan telah dibatalkan')['ok'],'legacy revoke preserved');
$oldSakit=izin_buat(2,array_merge($legacy,['jenis'=>'sakit','selesai'=>date('Y-m-d',strtotime('+2 days'))]),null);
cek(!$oldSakit['ok'] && isset($oldSakit['galat']['lampiran']),'legacy multi-school-day sickness requires document');
$db->prepare("INSERT INTO izin_pengajuan (siswa_id,jenis,tanggal_mulai,tanggal_selesai,alasan) VALUES (2,'sakit',:a,:b,'Sakit lama tanpa lampiran')")->execute([':a'=>$tomorrow,':b'=>$tomorrow]);
$oldSakitId=(int)$db->lastInsertId();
cek(izin_ambil($oldSakitId)['lampiran']===null && izin_setujui($oldSakitId,$admin)['ok'],'legacy sickness without document remains readable and approvable');
cek(izin_cabut($oldSakitId,$admin,'Pengujian sakit lama selesai')['ok'],'legacy sickness revocation preserved');
// Ensure later HTTP upload has a clear interval and no pending conflicts.
$db->exec("DELETE FROM izin_pengajuan WHERE status <> 'disetujui'");
echo "PASS integration complete\n";
