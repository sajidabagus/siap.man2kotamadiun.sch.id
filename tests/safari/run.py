#!/usr/bin/env python3
"""Schema-only, random disposable DB; never reads production config or INSERT data."""
import os, re, shutil, socket, subprocess, sys, tempfile, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
OUT=Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/safari-results');OUT.mkdir(parents=True,exist_ok=True)
for tool in ['php','mariadb','node','curl']:
    if not shutil.which(tool): raise SystemExit(f'Missing {tool}; use DISPOSABLE container/worker.')
DB='siap_safari_'+uuid.uuid4().hex[:12]+'_test'
def sql(text):
    return subprocess.run(['mariadb','-uroot','--batch','--skip-column-names'],input=text,text=True,check=True,capture_output=True).stdout
with tempfile.TemporaryDirectory(prefix='siap-safari-') as tmp:
    app=Path(tmp)/'app';app.mkdir()
    for name in ['includes','portal_siswa','panel','api','assets']:
        shutil.copytree(ROOT/name,app/name)
    for f in ROOT.glob('*.php'):shutil.copy2(f,app/f.name)
    (app/'database').mkdir();shutil.copy2(ROOT/'database/migrate_dispensasi.php',app/'database/migrate_dispensasi.php')
    (app/'config').mkdir()
    config="""<?php
if(!preg_match('/^siap_safari_[a-f0-9]{12}_test$/D',getenv('SIAP_TEST_DB')?:''))throw new RuntimeException('Isolated DB required');
define('BASE_URL',getenv('SIAP_TEST_URL'));date_default_timezone_set('Asia/Jakarta');
function getDB():PDO{static $db;return $db??=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname='.getenv('SIAP_TEST_DB').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
function kirim_halaman_error(string $f):void{echo htmlspecialchars($f);}
"""
    (app/'config/database.php').write_text(config)
    schema=re.findall(r'CREATE TABLE.*?;|ALTER TABLE.*?;',(ROOT/'database/database.sql').read_text(),re.S|re.I)
    schema=[re.sub(r'AUTO_INCREMENT\s*=\s*\d+','AUTO_INCREMENT=1',s,flags=re.I) for s in schema]
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
    url=f'http://127.0.0.1:{port}'
    env=os.environ|{'SIAP_TEST_DB':DB,'SIAP_TEST_URL':url,'SIAP_TEST_OUT':str(OUT)}
    sql(f'CREATE DATABASE `{DB}` CHARACTER SET utf8mb4;')
    try:
        sql(f'USE `{DB}`;SET FOREIGN_KEY_CHECKS=0;\n'+'\n'.join(schema)+'\nSET FOREIGN_KEY_CHECKS=1;')
        shutil.copy2(Path(__file__).with_name('fixture.php'),app/'fixture.php')
        setup=subprocess.run(['php',str(app/'fixture.php')],env=env,text=True,capture_output=True)
        (OUT/'db-setup.log').write_text(setup.stdout+setup.stderr);setup.check_returncode()
        for key,target in [('SIAP_SAFARI_BEFORE_GATE','portal_siswa/_before.php'),('SIAP_SAFARI_BEFORE_LOGIN','_before-login.php')]:
            if env.get(key):shutil.copy2(env[key],app/target)
        router="""<?php
if(PHP_SAPI!=='cli-server'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(strpos($path,'/portal/_safari/')===0){
 require_once __DIR__.'/includes/portal.php';portal_siapkan_tabel();portal_mulai_sesi();
 header('Content-Type:application/json');header('Cache-Control:no-store');
 if($path==='/portal/_safari/control'){
  $x=json_decode(file_get_contents('php://input'),true);$state=$x['state']??'active';$db=getDB();
  $db->exec("UPDATE settings SET device_binding_aktif=1;UPDATE siswa SET status='aktif';UPDATE siswa_akun SET status='aktif',password_sementara=0;UPDATE device_bindings SET status=IF(siswa_id=1,'ACTIVE','BLOCKED')");
  if($state==='blocked')$db->exec("UPDATE device_bindings SET status='BLOCKED'");
  if($state==='both')$db->exec("UPDATE device_bindings SET status='ACTIVE'");
  if($state==='account')$db->exec("UPDATE siswa_akun SET status='nonaktif' WHERE siswa_id=1");
  if($state==='student')$db->exec("UPDATE siswa SET status='nonaktif' WHERE id=1");
  if($state==='temporary')$db->exec("UPDATE siswa_akun SET password_sementara=1 WHERE siswa_id=1");
  if($state==='disabled')$db->exec("UPDATE settings SET device_binding_aktif=0");
  if($state==='version')$db->exec("UPDATE siswa_akun SET sesi_versi=sesi_versi+1 WHERE siswa_id=1");
  if($state==='idle'&&isset($_SESSION['portal']))$_SESSION['portal']['aktif']=time()-7201;
  echo json_encode(['ok'=>true,'bindings'=>(int)$db->query('SELECT COUNT(*) FROM device_bindings')->fetchColumn()]);return;
 }
 if($path==='/portal/_safari/before'){header('Content-Type:text/html');require __DIR__.'/portal_siswa/_layout.php';require __DIR__.'/portal_siswa/_before.php';return;}
 if($path==='/portal/_safari/before-login'){header('Content-Type:text/html');require __DIR__.'/_before-login.php';return;}
 $a=portal_siswa_sekarang();echo json_encode(['ok'=>true,'loggedIn'=>(bool)$a,'via'=>portal_sesi_via_perangkat(),'full'=>portal_sesi_penuh(),'idle'=>PORTAL_IDLE_DETIK,'lifetime'=>session_get_cookie_params()['lifetime'],'csrf'=>csrf_token()]);return;
}
if(!is_file(__DIR__.$path)){require __DIR__.'/index.php';return;}return false;
"""
        (app/'router.php').write_text(router)
        log=open(OUT/'php-server.log','w')
        proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app),str(app/'router.php')],env=env,stdout=log,stderr=subprocess.PIPE,text=True)
        try:
            startup=proc.stderr.readline();log.write(startup);log.flush()
            assert 'started' in startup,startup
            for name in ['http.cjs','browser.cjs']:
                run=subprocess.run(['node',str(Path(__file__).with_name(name))],env=env,text=True,capture_output=True)
                (OUT/(name+'.log')).write_text(run.stdout+'\n'+run.stderr)
                print(run.stdout,run.stderr);run.check_returncode()
        finally:
            proc.terminate();_,remaining=proc.communicate(timeout=15);log.write(remaining or '');log.close()
    finally:sql(f'DROP DATABASE `{DB}`;')
print('PASS isolated Safari HTTP/browser suite; app/database removed')
