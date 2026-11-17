const {request}=require('playwright');const assert=require('node:assert/strict');
(async()=>{
const base=process.env.SIAP_TEST_URL;
const owner=await request.newContext({baseURL:base}), other=await request.newContext({baseURL:base}), admin=await request.newContext({baseURL:base}), anon=await request.newContext({baseURL:base}), viewer=await request.newContext({baseURL:base});
try{
const o=await(await owner.get('/_test/student-session')).json();
await other.get('/_test/student-session?other=1');
const a=await(await admin.get('/_test/admin-session')).json();
const v=await(await viewer.get('/_test/admin-session?viewer=1')).json();
const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Jakarta'}).format(new Date());
const photo={name:'dokumen.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jKX0AAAAASUVORK5CYII=','base64')};
const form={jenis:'dispensasi',mode_durasi:'jam',mulai:today,jam_mulai:'13:00',jam_selesai:'14:00',alasan:'Kegiatan lomba pengujian dokumen dispensasi',lampiran:photo};
let bad=await owner.post('/portal/izin/baru',{multipart:{...form,csrf_token:'wrong'},maxRedirects:0});assert.equal(bad.status(),200);assert((await bad.text()).includes('Sesi halaman kedaluwarsa'));
let valid=await owner.post('/portal/izin/baru',{multipart:{...form,csrf_token:o.csrf},maxRedirects:0});assert.equal(valid.status(),302,await valid.text());let match=valid.headers().location.match(/izin\/(\d+)/);assert(match,JSON.stringify(valid.headers()));let id=match[1];
let row=async()=>await(await anon.get('/_test/row?id='+id)).json();assert.equal((await row()).status,'menunggu');
let doc=await owner.get(`/portal/izin/${id}/lampiran`);assert.equal(doc.status(),200);assert(doc.headers()['content-type'].startsWith('image/'));
let noDoc=await other.get(`/portal/izin/${id}/lampiran`);assert.equal(noDoc.status(),404);
let direct=await anon.get(`/panel/izin_lampiran.php?id=${id}`,{maxRedirects:0});assert.equal(direct.status(),302);assert(direct.headers().location.includes('login'));
await anon.post('/izin/'+id,{form:{aksi:'setujui',csrf_token:a.csrf},maxRedirects:0});assert.equal((await row()).status,'menunggu');
await viewer.post('/izin/'+id,{form:{aksi:'setujui',csrf_token:v.csrf},maxRedirects:0});assert.equal((await row()).status,'menunggu');
await admin.post('/izin/'+id,{form:{aksi:'setujui',csrf_token:'wrong'},maxRedirects:0});assert.equal((await row()).status,'menunggu');
let approve=await admin.post('/izin/'+id,{form:{aksi:'setujui',csrf_token:a.csrf,catatan:'Dokumen kegiatan telah diperiksa'},maxRedirects:0});assert.equal(approve.status(),302,await approve.text());assert.equal((await row()).status,'disetujui');
let detail=await owner.get('/portal/izin/'+id);assert((await detail.text()).includes('13:00'));
let csv=await admin.get('/absensi/ekspor?type=csv&tanggal='+today);let text=await csv.text();assert(text.includes('Dispensasi (kegiatan/durasi'));assert(text.includes('13:00'));assert(text.includes('HADIR'));assert(!text.includes('Fatal error'),text);
for (let path of ['/absensi?','absensi/cetak?','/rekap?','/rekap/cetak?']) {let rep=await admin.get('/'+path.replace(/^\//,'')+'tanggal='+today);assert.equal(rep.status(),200,path);let body=await rep.text();assert(body.includes('Dispensasi disetujui'),path);assert(!body.includes('Fatal error'),path);}
let history=await owner.get('/portal/riwayat');assert((await history.text()).includes('Dispensasi disetujui'));
const tomorrow = new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Jakarta'}).format(new Date(Date.now()+86400000));
let full = await owner.post('/portal/izin/baru',{multipart:{...form,mode_durasi:'sehari',mulai:tomorrow,csrf_token:o.csrf},maxRedirects:0});
assert.equal(full.status(),302,await full.text());let fid=full.headers().location.match(/izin\/(\d+)/)[1];
await admin.post('/izin/'+fid,{form:{aksi:'setujui',csrf_token:a.csrf,catatan:'Kegiatan sehari telah diperiksa'},maxRedirects:0});
let fullcsv=await(await admin.get('/absensi/ekspor?type=csv&tanggal='+tomorrow)).text();
assert(fullcsv.includes('Dispensasi:'));assert(!fullcsv.includes('HADIR'));assert(!fullcsv.includes('PENGAJUAN'));
await admin.post('/izin/'+fid,{form:{aksi:'cabut',csrf_token:a.csrf,catatan:'Kegiatan besok dibatalkan'},maxRedirects:0});
await admin.post('/izin/'+id,{form:{aksi:'cabut',csrf_token:a.csrf,catatan:'Kegiatan pengujian dibatalkan'},maxRedirects:0});assert.equal((await row()).status,'dicabut');
console.log('PASS actual routes: student CSRF+multipart, ownership document, anonymous direct-document guard, admin CSRF/auth/permission approval+revoke, student history/detail, daily/monthly/print reports and CSV overlay with HADIR preserved');
}finally{await owner.dispose();await other.dispose();await admin.dispose();await anon.dispose();await viewer.dispose();}
})().catch(e=>{console.error(e);process.exitCode=1;});
