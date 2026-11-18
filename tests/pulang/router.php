<?php
if(PHP_SAPI!=='cli-server'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(strpos($path,'/_pulang/')===0){
 require_once __DIR__.'/includes/izin.php';
 $db=getDB();
 if($path==='/_pulang/control'){
  $x=json_decode(file_get_contents('php://input'),true)?:[];
  $state=$x['state']??'arrival';
  $m=(int)date('H')*60+(int)date('i');
  if($m<20||$m>1400)throw new RuntimeException('Run wall-clock HTTP tests between 00:20–23:20 Asia/Jakarta.');
  $tm=fn($n)=>sprintf('%02d:%02d:00',intdiv($n,60),$n%60);
  $db->exec("DELETE FROM presensi;DELETE FROM izin_pengajuan;UPDATE device_bindings SET status='ACTIVE';UPDATE settings SET gps_aktif=0,device_binding_aktif=1,terlambat_aktif=1,alpa_otomatis_terakhir=NULL,alpa_otomatis_jam=NULL");
  $depart=in_array($state,['departure','missing','double','leave','sick','full','partial','alpa','gps','blocked','holiday'],true);
  $a=$depart?$m-15:$m-5;$b=$depart?$m-10:$m+5;$l=$state==='late'?$m-1:$m+2;$c=$depart?$m-3:$m+10;$d=$depart?$m+5:$m+15;
  if($state==='outside'){$a=$m-15;$b=$m-10;$c=$m+5;$d=$m+15;}
  $q=$db->prepare('UPDATE settings SET jam_masuk=?,batas_absen=?,batas_terlambat=?,pulang_buka=?,pulang_tutup=?,terlambat_aktif=?');
  $q->execute([$tm($a),$tm($b),$tm($depart?$b-1:$l),$tm($c),$tm($d),$state==='no_late'?0:1]);
  if($depart&&$state!=='missing'){
    $q=$db->prepare("INSERT INTO presensi(siswa_id,kelas_saat_presensi,tanggal,jam_masuk,status,metode,jam_pulang)VALUES(1,'X Uji',?,?,?,?,?)");
    $q->execute([date('Y-m-d'),$tm($m-12),$state==='alpa'?'ALPA':'HADIR',$state==='alpa'?'OTOMATIS':'QR',$state==='double'?$tm($m-1):null]);
  }
  // Existing no-scan-day holiday safety requires at least one genuine arrival before auto-Alpa.
  if($state==='missing'){
    $q=$db->prepare("INSERT INTO presensi(siswa_id,kelas_saat_presensi,tanggal,jam_masuk,status,metode)VALUES(2,'X Uji',?,?,'HADIR','QR')");
    $q->execute([date('Y-m-d'),$tm($m-12)]);
  }
  if(in_array($state,['leave','sick','full','partial','arrival_leave','arrival_sick','arrival_pending','arrival_partial'],true)){
    $jenis=in_array($state,['leave','arrival_leave','arrival_pending'],true)?'izin':(in_array($state,['sick','arrival_sick'],true)?'sakit':'dispensasi');
    $q=$db->prepare("INSERT INTO izin_pengajuan(siswa_id,jenis,mode_durasi,tanggal_mulai,tanggal_selesai,alasan,status)VALUES(1,?,?,?,?,?,?)");
    $q->execute([$jenis,in_array($state,['partial','arrival_partial'],true)?'jam':'sehari',date('Y-m-d'),date('Y-m-d'),'Kegiatan pengujian sintetis',$state==='arrival_pending'?'menunggu':'disetujui']);
  }
  if($state==='gps')$db->exec("UPDATE settings SET gps_aktif=1,latitude_sekolah=-7,longitude_sekolah=111,radius_meter=50");
  if($state==='blocked')$db->exec("UPDATE device_bindings SET status='BLOCKED' WHERE siswa_id=1");
  if($state==='holiday')$db->exec("UPDATE settings SET hari_efektif='".((int)date('N')%7+1)."'");
  else $db->exec("UPDATE settings SET hari_efektif='1,2,3,4,5,6,7'");
  header('Content-Type:application/json');echo json_encode(['ok'=>true]);return;
 }
 if($path==='/_pulang/row'){header('Content-Type:application/json');echo json_encode($db->query("SELECT * FROM presensi WHERE siswa_id=1 AND tanggal='".date('Y-m-d')."'")->fetch());return;}
 if($path==='/_pulang/gate'){
  require_once __DIR__.'/includes/qr_dinamis.php';$gate=generate_qr_dinamis('Pengujian');
  header('Content-Type:application/json');echo json_encode($gate);return;
 }
 if($path==='/_pulang/admin'){
  require_once __DIR__.'/includes/auth.php';$_SESSION['admin_id']=1;
  header('Content-Type:application/json');echo json_encode(['csrf'=>csrf_token()]);return;
 }
 if($path==='/_pulang/student'){
  portal_mulai_sesi();portal_masuk_sesi(portal_cari_akun_id(1));
  header('Content-Type:application/json');echo json_encode(['csrf'=>csrf_token()]);return;
 }
 if($path==='/_pulang/alpa'){jalankan_alpa_otomatis();echo 'ok';return;}
 if($path==='/_pulang/before-settings'){require __DIR__.'/panel/_before-settings.php';return;}
 if($path==='/_pulang/before-home'){define('APP_ROUTER',true);require __DIR__.'/_before-home.php';return;}
 http_response_code(404);return;
}
if(is_dir(__DIR__.$path)&&is_file(rtrim(__DIR__.$path,'/').'/index.php')){require rtrim(__DIR__.$path,'/').'/index.php';return;}
if(!is_file(__DIR__.$path)){require __DIR__.'/index.php';return;}return false;
