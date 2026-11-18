#!/usr/bin/env python3
"""Disposable worker only: real schema/PHP HTTP/MariaDB, no production INSERT/config."""
import os,re,shutil,socket,subprocess,sys,tempfile,uuid,threading
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
OUT=Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/pulang-results');OUT.mkdir(parents=True,exist_ok=True)
for name in ['php','mariadb','node','curl']:
 if not shutil.which(name):raise SystemExit('Missing '+name+'; install prerequisites in disposable worker.')
DB='siap_pulang_'+uuid.uuid4().hex[:12]+'_test'
def sql(q):return subprocess.run(['mariadb','-uroot','--batch','--skip-column-names'],input=q,text=True,check=True,capture_output=True).stdout
with tempfile.TemporaryDirectory(prefix='siap-pulang-') as tmp:
 app=Path(tmp)/'app';app.mkdir()
 for n in ['includes','portal_siswa','panel','api','assets','presensi']:shutil.copytree(ROOT/n,app/n)
 for f in ROOT.glob('*.php'):shutil.copy2(f,app/f.name)
 (app/'database').mkdir()
 for f in ROOT.glob('database/migrate_*.php'):shutil.copy2(f,app/'database'/f.name)
 (app/'config').mkdir()
 (app/'config/database.php').write_text("""<?php
if(!preg_match('/^siap_pulang_[a-f0-9]{12}_test$/D',getenv('SIAP_TEST_DB')?:''))throw new RuntimeException('Isolated DB required');
define('BASE_URL',getenv('SIAP_TEST_URL'));date_default_timezone_set('Asia/Jakarta');
function getDB():PDO{static $db;return $db??=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname='.getenv('SIAP_TEST_DB').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_INIT_COMMAND=>"SET time_zone='+07:00'"]);}
function kirim_halaman_error(string $f):void{echo htmlspecialchars($f);}
""")
 schema=re.findall(r'CREATE TABLE.*?;|ALTER TABLE.*?;',(ROOT/'database/database.sql').read_text(),re.S|re.I)
 schema=[re.sub(r'AUTO_INCREMENT\s*=\s*\d+','AUTO_INCREMENT=1',s,flags=re.I) for s in schema]
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
 env=os.environ|{'SIAP_TEST_DB':DB,'SIAP_TEST_URL':f'http://127.0.0.1:{port}','SIAP_TEST_OUT':str(OUT)}
 sql(f'CREATE DATABASE `{DB}` CHARACTER SET utf8mb4;')
 try:
  sql(f'USE `{DB}`;SET FOREIGN_KEY_CHECKS=0;\n'+'\n'.join(schema)+'\nSET FOREIGN_KEY_CHECKS=1;')
  for src,dst in [('fixture.php','fixture.php'),('router.php','router.php')]:shutil.copy2(Path(__file__).with_name(src),app/dst)
  # Exercise upgrading an old installation with existing attendance, then run twice/check.
  sql(f"USE `{DB}`;ALTER TABLE presensi DROP COLUMN jam_pulang;ALTER TABLE settings DROP COLUMN pulang_buka,DROP COLUMN pulang_tutup,DROP COLUMN terlambat_aktif;")
  sql(f"USE `{DB}`;INSERT INTO siswa(id,nis,nisn,nama,kelas,tahun_pelajaran,qr_token)VALUES(99,'LEGACY99','0000000099','Legacy Pengujian','X Uji','2026/2027','LEGACY99');INSERT INTO presensi(siswa_id,kelas_saat_presensi,tanggal,jam_masuk,status,metode)VALUES(99,'X Uji','2026-11-01','07:00','HADIR','QR');")
  dry=subprocess.run(['php',str(app/'database/migrate_absen_pulang.php'),'--check'],env=env,text=True,capture_output=True)
  assert dry.returncode==1 and 'BELUM ADA' in dry.stderr
  assert sql(f"USE `{DB}`;SHOW COLUMNS FROM presensi LIKE 'jam_pulang';").strip()==''
  print('PASS migration --check reports actionable missing columns without writing')
  for args in [[],['--check'],[]]:
   r=subprocess.run(['php',str(app/'database/migrate_absen_pulang.php'),*args],env=env,text=True,capture_output=True);print(r.stdout,r.stderr);r.check_returncode()
  assert sql(f"USE `{DB}`;SELECT CONCAT(status,'|',jam_masuk,'|',IFNULL(jam_pulang,'NULL')) FROM presensi WHERE siswa_id=99;").strip()=='HADIR|07:00:00|NULL'
  sql(f"USE `{DB}`;DELETE FROM presensi WHERE siswa_id=99;DELETE FROM siswa WHERE id=99;")
  print('PASS legacy arrival survives additive migration with NULL departure')
  r=subprocess.run(['php',str(app/'fixture.php')],env=env,text=True,capture_output=True);(OUT/'fixture.log').write_text(r.stdout+r.stderr);print(r.stdout,r.stderr);r.check_returncode()
  if os.environ.get('SIAP_PULANG_BEFORE'):
   baseline=Path(os.environ['SIAP_PULANG_BEFORE'])
   shutil.copy2(baseline/'pengaturan.php',app/'panel/_before-settings.php')
   shutil.copy2(baseline/'beranda.php',app/'_before-home.php')
  log=open(OUT/'php-server.log','w')
  proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app),str(app/'router.php')],env=env,stdout=log,stderr=subprocess.PIPE,text=True)
  try:
   startup=proc.stderr.readline();log.write(startup);log.flush();assert 'started' in startup,startup
   # Drain continuously: a full stderr pipe otherwise blocks the PHP server mid-browser test.
   drain=threading.Thread(target=shutil.copyfileobj,args=(proc.stderr,log),daemon=True);drain.start()
   for n in ['http.cjs','browser.cjs']:
    r=subprocess.run(['node',str(Path(__file__).with_name(n))],env=env,text=True,capture_output=True);(OUT/(n+'.log')).write_text(r.stdout+r.stderr);print(r.stdout,r.stderr);r.check_returncode()
  finally:
   proc.terminate();proc.wait(timeout=15);drain.join(timeout=5);log.close()
 finally:sql(f'DROP DATABASE `{DB}`;')
print('PASS isolated pulang suite; temporary database/app removed')
