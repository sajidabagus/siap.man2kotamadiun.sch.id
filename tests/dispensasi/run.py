#!/usr/bin/env python3
"""Isolated MariaDB + PHP HTTP tests. Creates/drops only a random *_test database; never loads production config/data."""
import base64, json, os, re, shutil, socket, subprocess, sys, tempfile, uuid
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
OUT = Path(sys.argv[1] if len(sys.argv) > 1 else '/tmp/dispensasi-results'); OUT.mkdir(parents=True, exist_ok=True)
for tool in ['mariadb', 'php', 'node', 'curl']:
    if not shutil.which(tool): raise SystemExit(f'Missing {tool}; install test prerequisites in a DISPOSABLE worker/container (see tests/dispensasi/README.md).')
DB = 'siap_dispensasi_' + uuid.uuid4().hex[:12] + '_test'
assert re.fullmatch(r'siap_dispensasi_[a-f0-9]{12}_test', DB)
def sql(q): return subprocess.run(['mariadb', '-uroot', '--batch', '--skip-column-names'], input=q, text=True, check=True, capture_output=True).stdout
with tempfile.TemporaryDirectory(prefix='siap-dispensasi-') as temp:
    app = Path(temp)/'app'; app.mkdir()
    for directory in ['includes','portal_siswa','panel','api','assets']:
        shutil.copytree(ROOT/directory, app/directory)
    for f in ROOT.glob('*.php'): shutil.copy2(f,app/f.name)
    (app/'config').mkdir(); (app/'tests').mkdir(); (app/'database').mkdir()
    shutil.copy2(ROOT/'database/migrate_dispensasi.php',app/'database/migrate_dispensasi.php')
    shutil.copy2(Path(__file__).with_name('integration.php'), app/'tests/integration.php')
    config = '''<?php
if (!preg_match('/^siap_dispensasi_[a-f0-9]{12}_test$/D', getenv('SIAP_TEST_DB') ?: '')) throw new RuntimeException('Isolated DB required');
define('BASE_URL',getenv('SIAP_TEST_URL'));date_default_timezone_set('Asia/Jakarta');
function getDB(): PDO { static $db; return $db ??= new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname='.getenv('SIAP_TEST_DB').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_INIT_COMMAND=>"SET time_zone='+07:00'"]); }
function kirim_halaman_error(string $f): void { echo htmlspecialchars($f); }
'''
    (app/'config/database.php').write_text(config)
    # Schema ONLY: skip all INSERT/REPLACE/UPDATE and original configuration/uploads.
    dump = (ROOT/'database/database.sql').read_text()
    schema = re.findall(r'CREATE TABLE.*?;|ALTER TABLE.*?;',dump,re.S|re.I)
    schema = [re.sub(r'AUTO_INCREMENT\s*=\s*\d+','AUTO_INCREMENT=1',s,flags=re.I) for s in schema]
    sql(f'CREATE DATABASE `{DB}` CHARACTER SET utf8mb4;')
    try:
        sql(f'USE `{DB}`; SET FOREIGN_KEY_CHECKS=0;\n'+'\n'.join(schema)+'\nSET FOREIGN_KEY_CHECKS=1;')
        sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
        url=f'http://127.0.0.1:{port}'
        env=os.environ.copy();env.update(SIAP_TEST_DB=DB,SIAP_TEST_URL=url)
        run=subprocess.run(['php',str(app/'tests/integration.php')],env=env,text=True,capture_output=True)
        (OUT/'integration.log').write_text(run.stdout+'\n'+run.stderr)
        print(run.stdout,run.stderr);run.check_returncode()
        for args in [[],['--check'],[]]:
            migration=subprocess.run(['php',str(app/'database/migrate_dispensasi.php'),*args],env=env,text=True,capture_output=True);migration.check_returncode();print(migration.stdout.strip())
        # Test-only local HTTP router: upload goes through real PHP multipart handling.
        router='''<?php
if (PHP_SAPI!=='cli-server' || ($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1') {http_response_code(404);exit;}
require_once __DIR__.'/includes/izin.php';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/_test/ready') {header('Content-Type:application/json');echo json_encode(['ready'=>true]);return;}
if ($path==='/_test/upload') {izin_siapkan_tabel(); header('Content-Type:application/json');echo json_encode(izin_buat(1,$_POST,$_FILES['lampiran']??null));return;}
if ($path==='/_test/form') {require_once __DIR__.'/portal_siswa/_layout.php';require_once __DIR__.'/portal_siswa/_izin_form.php';portal_app_awal('Pengajuan',['siswa_id'=>1,'nama'=>'Siswa Pengujian'],'izin');portal_izin_form(['jenis'=>'','mulai'=>'','selesai'=>'','alasan'=>''],[]);portal_app_akhir();return;}
if ($path==='/_test/decision') {header('Content-Type:application/json');$id=(int)($_POST['id']??0);$admin=['id'=>1,'nama'=>'Admin Pengujian'];$h=($_POST['aksi']??'')==='tolak'?izin_tolak($id,$admin,'Dokumen tidak sesuai'):izin_setujui($id,$admin,'Kegiatan telah diperiksa');echo json_encode($h);return;}
if ($path==='/_test/admin-session') {require_once __DIR__.'/includes/auth.php';$_SESSION['admin_id']=1;if (($_GET['viewer']??'')==='1') {siapkan_hak_akses();getDB()->exec("INSERT IGNORE INTO users (id,nama,username,password,role) VALUES (2,'Peninjau Pengujian','viewer','test-only','viewer_test')");getDB()->exec("INSERT IGNORE INTO role_permissions (role,izin) VALUES ('viewer_test','izin.lihat')");$_SESSION['admin_id']=2;}if (($_GET['noaccess']??'')==='1') {siapkan_hak_akses();getDB()->exec("INSERT IGNORE INTO users (id,nama,username,password,role) VALUES (3,'Tanpa Akses Pengujian','noaccess','test-only','noaccess_test')");$_SESSION['admin_id']=3;}header('Content-Type:application/json');echo json_encode(['csrf'=>csrf_token()]);return;}
if ($path==='/_test/student-session') {portal_mulai_sesi();$sid=($_GET['other']??'')==='1'?2:1;portal_masuk_sesi(portal_cari_akun_id($sid));header('Content-Type:application/json');echo json_encode(['csrf'=>csrf_token()]);return;}
if ($path==='/_test/row') {$q=getDB()->prepare('SELECT id,status,jenis,mode_durasi FROM izin_pengajuan WHERE id=:id');$q->execute([':id'=>(int)($_GET['id']??0)]);header('Content-Type:application/json');echo json_encode($q->fetch());return;}
if (!is_file(__DIR__.$path)) {require __DIR__.'/index.php';return;}
return false;
'''
        (app/'router-test.php').write_text(router)
        log=open(OUT/'php-server.log','w')
        process=subprocess.Popen(['php','-d','upload_max_filesize=20M','-d','post_max_size=25M','-S',f'127.0.0.1:{port}','-t',str(app),str(app/'router-test.php')],env=env,stdout=log,stderr=subprocess.PIPE,text=True)
        try:
            # Block on the actual PHP server startup event, not a sleep/readiness polling loop.
            startup=process.stderr.readline();log.write(startup);log.flush()
            assert 'Development Server' in startup and 'started' in startup,startup
            subprocess.run(['curl','--silent','--show-error',url+'/_test/ready'],check=True,capture_output=True)
            stamp=subprocess.check_output(['php','-r',"date_default_timezone_set('Asia/Jakarta');echo date('Y-m-d');"],text=True)
            def upload(name,content,extra=None):
                png=Path(temp)/name;png.write_bytes(content)
                args=['curl','-sS',url+'/_test/upload','-F','jenis=dispensasi','-F','mode_durasi=jam','-F',f'mulai={stamp}','-F','jam_mulai=10:00','-F','jam_selesai=11:00','-F','alasan=Kegiatan pengujian dokumen dispensasi','-F',f'lampiran=@{png}']
                if extra: args += extra
                return json.loads(subprocess.check_output(args,text=True))
            png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jKX0AAAAASUVORK5CYII=')
            # Readable synthetic document when GD is installed; the 1px fixture remains the no-GD fallback.
            doc=subprocess.run(['php','-r', '$im=function_exists("imagecreatetruecolor")?imagecreatetruecolor(720,400):null;if(!$im)exit(2);$white=imagecolorallocate($im,255,255,255);$ink=imagecolorallocate($im,25,50,85);imagefill($im,0,0,$white);imagerectangle($im,18,18,701,381,$ink);imagestring($im,5,40,50,"DOKUMEN DISPENSASI - CONTOH UJI",$ink);imagestring($im,4,40,110,"SISWA PENGUJIAN / X UJI - BUKAN DATA ASLI",$ink);imagestring($im,4,40,165,"Kegiatan: lomba sekolah (fixture sintetis)",$ink);imagestring($im,4,40,215,"Pukul 10:00 - 11:00 WIB",$ink);imagestring($im,4,40,300,"HANYA UNTUK PENGUJIAN APLIKASI",$ink);imagepng($im);'],capture_output=True)
            if doc.returncode==0: png=doc.stdout
            assert not upload('dokumen.php',b'<?php echo "x";')['ok']
            assert not upload('dokumen.jpg',b'not an image')['ok']
            assert not upload('dokumen.php',png)['ok']
            assert not upload('dokumen.pdf',b'%PDF-1.4\n%%EOF')['ok']
            assert not upload('dokumen.png',b'x'*(16*1024*1024))['ok']
            valid=upload('dokumen.png',png);assert valid['ok'],valid
            fixture=Path(temp)/'fixture.png';fixture.write_bytes(png)
            if doc.returncode==0:
                for extension,code,start,end in [('jpg','imagejpeg','11:00','12:00'),('webp','imagewebp','12:00','13:00')]:
                    rendered=subprocess.check_output(['php','-r',f'$im=imagecreatefrompng($argv[1]);{code}($im);',str(fixture)])
                    accepted=upload('dokumen.'+extension,rendered,['-F','jam_mulai='+start,'-F','jam_selesai='+end]);assert accepted['ok'],accepted
            stored=sql(f'USE `{DB}`; SELECT lampiran FROM izin_pengajuan WHERE id={int(valid["id"])};').strip()
            assert re.fullmatch(r'[a-f0-9]{32}\.(jpg|png|webp)',stored)
            assert (app/'uploads/izin'/stored).stat().st_size<=3145728
            duplicate=upload('dokumen.png',png);assert not duplicate['ok'],duplicate
            (OUT/'http-upload.json').write_text(json.dumps({'valid':valid,'duplicateRejected':not duplicate['ok'],'maliciousRejected':5},indent=2))
            print('PASS HTTP multipart: valid PNG/JPEG/WebP photos (GD), safe random name/size, fake MIME, executable, mismatched extension, PDF, oversized and duplicate')
            http=subprocess.run(['node',str(Path(__file__).with_name('http.cjs'))],env=env | {'SIAP_TEST_URL':url},text=True,capture_output=True)
            (OUT/'http-routes.log').write_text(http.stdout+'\n'+http.stderr);print(http.stdout,http.stderr);http.check_returncode()
            # Browser fixture page uses actual app form, actual new helper, real isolated DB.
            env['SIAP_TEST_OUT']=str(OUT);env['SIAP_TEST_URL']=url;env['SIAP_TEST_PENDING_ID']=str(valid['id'])
            browser=subprocess.run(['node',str(Path(__file__).with_name('browser.cjs'))],env=env,text=True,capture_output=True)
            (OUT/'browser.log').write_text(browser.stdout+'\n'+browser.stderr);print(browser.stdout,browser.stderr);browser.check_returncode()
            if os.environ.get('SIAP_TEST_BEFORE_FORM'):
                shutil.copy2(os.environ['SIAP_TEST_BEFORE_FORM'],app/'portal_siswa/_izin_form.php')
                before=subprocess.run(['node',str(Path(__file__).with_name('browser.cjs'))],env=env | {'SIAP_TEST_BEFORE_ONLY':'1'},text=True,capture_output=True);before.check_returncode()

        finally:
            process.terminate();_,remaining=process.communicate(timeout=15);log.write(remaining or '');log.close()
    finally:
        sql(f'DROP DATABASE `{DB}`;')
print('PASS isolated dispensasi suite; temporary app/database removed')
