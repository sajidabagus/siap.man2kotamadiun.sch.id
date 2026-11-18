const assert = require('node:assert/strict'), B=process.env.SIAP_TEST_URL;
const UUID='11111111-1111-4111-8111-111111111111';
let cookie='';
async function req(path,body,raw=false){let r=await fetch(B+path,{method:body?'POST':'GET',headers:{...(body?{'Content-Type':'application/json'}:{}),cookie},body:body?JSON.stringify(body):undefined,redirect:'manual'});let sc=r.headers.getSetCookie();if(sc.length)cookie=sc.map(v=>v.split(';')[0]).join('; ');let t=await r.text();return raw?{r,t}:JSON.parse(t);}
async function state(s){await req('/_pulang/control',{state:s});}
async function scan(){let gate=await req('/_pulang/gate');let x=await req('/api/scan.php',{mode:'device',gate_token:gate.token,device_uuid:UUID});return {gate:gate.token,x};}
async function submit(gate,extra={}){return req('/api/presensi.php',{gate_token:gate,device_uuid:UUID,...extra});}
(async()=>{
 let count=0;
 for(const [s,ok,status] of [['arrival',true,'HADIR'],['late',true,'TERLAMBAT'],['no_late',true,'HADIR'],['arrival_leave',false],['arrival_sick',false],['arrival_pending',false],['arrival_partial',true,'HADIR'],['departure',true,'HADIR'],['partial',true,'HADIR'],['missing',false],['double',false],['leave',false],['sick',false],['full',false],['alpa',false],['outside',false],['gps',false],['blocked',false],['holiday',false]]){
  await state(s);let {gate,x}=await scan();
  if(['blocked','holiday','alpa'].includes(s)){
   assert.notEqual(x.code,'MATCH');
   await req('/presensi/index.php?gate='+gate,null,true);await req('/presensi/scan.php?token=UJI1',null,true);
   assert.equal((await submit(gate)).success,false,'final route cannot bypass '+s);
   console.log('PASS preflight AND final server rejection '+s);count++;continue;
  }
  assert.equal(x.code,'MATCH',JSON.stringify(x));
  let result=await submit(gate,{sesi:'pulang',jam:'13:00'});assert.equal(result.success,ok,JSON.stringify({s,result}));if(status)assert.equal(result.data.status,status);
  let row=await req('/_pulang/row');
  if(['departure','partial'].includes(s)){assert.equal(result.data.sesi,'pulang');assert.ok(row.jam_pulang);assert.equal(row.metode,'QR');let old=row.jam_masuk;let again=await scan();assert.equal((await submit(again.gate)).success,false);assert.equal((await req('/_pulang/row')).jam_masuk,old);}
  if(['arrival','late','no_late'].includes(s)){assert.equal(result.data.sesi,'datang');assert.equal(row.jam_pulang,null);}
  console.log('PASS actual PHP HTTP '+s);count++;
 }
 for (const [coords,ok] of [[{latitude:91,longitude:111},false],[{latitude:-1,longitude:100},false],[{latitude:-7,longitude:111},true]]) {
  await state('gps');const g=await scan();assert.equal((await submit(g.gate,coords)).success,ok);
 }
 console.log('PASS final departure GPS invalid/outside/inside radius');
 await state('departure');await scan();assert.equal((await submit('invalid-gate')).code,'GATE_INVALID');
 console.log('PASS final departure invalid QR rejected');
 await state('departure');await req('/_pulang/alpa',null,true);let row=await req('/_pulang/row');assert.equal(row.status,'HADIR');
 let feed=await req('/api/beranda.php',{device_uuid:UUID});assert.ok(feed.saya.scan.aktif);assert.equal(feed.saya.scan.sesi,'pulang');
 console.log('PASS Alpa cutoff preserves present and departure eligibility');
 await state('missing');await req('/_pulang/alpa',null,true);assert.equal((await req('/_pulang/row')).status,'ALPA');
 console.log('PASS Alpa generated from arrival close, not departure close');
 await state('departure');let {gate}=await scan();assert.equal((await submit(gate)).success,true);
 feed=await req('/api/beranda.php',{device_uuid:UUID});assert.ok(feed.saya.jam_pulang);assert.equal(feed.saya.pulang_bulan,1);assert.equal(feed.saya.scan.aktif,false);
 console.log('PASS own feed departure/month count and duplicate blocking');
 await req('/_pulang/admin');
 for(let path of ['/absensi','/absensi/cetak','/rekap','/rekap/cetak','/absensi/ekspor?type=csv','/absensi/ekspor?type=excel']){let {r,t}=await req(path,null,true);assert.equal(r.status,200,path);assert.match(t,/Pulang|pulang/,path);assert.ok(!/Fatal error|Warning:|Notice:/.test(t),path);console.log('PASS report '+path);}
 let admin=await req('/_pulang/admin');
 let page=await req('/pengaturan',null,true);assert.match(page.t,/Jam absensi/);
 assert.equal((await req('/panel/_jam_absensi.php',null,true)).r.status,404,'partial is not a direct endpoint');
 let form=new URLSearchParams({csrf_token:admin.csrf,jam_masuk:'08:00',batas_absen:'07:00',batas_terlambat:'',pulang_buka:'13:00',pulang_tutup:'16:00','hari_efektif[]':'1'});
 let r=await fetch(B+'/pengaturan',{method:'POST',headers:{cookie,'Content-Type':'application/x-www-form-urlencoded'},body:form,redirect:'manual'});let text=await r.text();assert.match(text,/Buka datang harus sebelum tutup datang/);
 console.log('PASS server rejects invalid windows even with direct POST');
 form.set('jam_masuk','05:40');form.set('batas_absen','09:00');form.set('nama_sekolah','Sekolah Pengujian');form.set('tahun_pelajaran','2026/2027');
 r=await fetch(B+'/pengaturan',{method:'POST',headers:{cookie,'Content-Type':'application/x-www-form-urlencoded'},body:form,redirect:'manual'});
 assert.equal(r.status,302,'valid settings saved');
 page=await req('/pengaturan',null,true);assert.match(page.t,/name="batas_terlambat" value=""/);assert.match(page.t,/name="pulang_buka" value="13:00"/);
 form.set('csrf_token','invalid');
 r=await fetch(B+'/pengaturan',{method:'POST',headers:{cookie,'Content-Type':'application/x-www-form-urlencoded'},body:form,redirect:'manual'});assert.equal(r.status,403);
 form.set('csrf_token',admin.csrf);form.set('pulang_buka[]','13:00');
 r=await fetch(B+'/pengaturan',{method:'POST',headers:{cookie,'Content-Type':'application/x-www-form-urlencoded'},body:form,redirect:'manual'});text=await r.text();assert.equal(r.status,200);assert.match(text,/bukan daftar/);assert.ok(!/Fatal error|Warning:|Notice:/.test(text));
 console.log('PASS settings persistence, optional late restored blank, and CSRF rejection');
 console.log(`PASS ${count} attendance cases plus Alpa/feed/report/settings HTTP`);
})().catch(e=>{console.error(e);process.exit(1)});
