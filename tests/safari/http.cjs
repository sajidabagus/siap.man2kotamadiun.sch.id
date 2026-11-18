const {request}=require('playwright'),assert=require('node:assert/strict');
const base=process.env.SIAP_TEST_URL;
const A='11111111-1111-4111-8111-111111111111',B='22222222-2222-4222-8222-222222222222',C='33333333-3333-4333-8333-333333333333';
(async()=>{
const c=await request.newContext({baseURL:base,userAgent:'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1'});
const status=async()=>await(await c.get('/portal/_safari/status')).json();
const state=async x=>{let d=await(await c.post('/portal/_safari/control',{data:{state:x}})).json();assert.equal(d.bindings,2);};
const resolve=async(candidates,extra={})=>c.post('/api/identitas_perangkat.php',{data:{candidates},...extra});
const login=async uuid=>await(await c.post('/portal/masuk-perangkat',{data:{uuid}})).json();
try{
assert.equal((await c.get('/api/identitas_perangkat.php')).status(),405);
assert.equal((await resolve([A,B],{headers:{Origin:'https://evil.test'}})).status(),403);
for(const candidates of [null,[],[A],[A,A],[A,{}],[A,B,C],['bad',A]])assert.deepEqual(await(await resolve(candidates)).json(),{ok:false});
for(const [candidates,index] of [[[A,B],0],[[B,A],1],[[C,A],1]]){
 const r=await resolve(candidates);assert.equal(r.headers()['set-cookie'],undefined,'validation must not change backup cookies');
 assert.deepEqual(await r.json(),{ok:true,index});
}
assert.equal((await status()).loggedIn,false,'validation must not create login');
assert.equal((await login(B)).ok,false,'resolver must not activate a blocked candidate');
await state('both');assert.deepEqual(await(await resolve([A,B])).json(),{ok:false});
await state('blocked');assert.deepEqual(await(await resolve([A,B])).json(),{ok:false});assert.equal((await login(A)).ok,false);
await state('active');assert.equal((await login(A)).ok,true);
let s=await status();assert.equal(s.loggedIn,true);assert.equal(s.via,true);assert.equal(s.full,false);assert.equal(s.idle,7200);assert.equal(s.lifetime,0);
const cookies=await c.storageState();assert(cookies.cookies.some(x=>x.name==='presensi_device_uuid'&&x.value===A),'server iOS cookie refresh');
let denied=await c.get('/portal/password',{maxRedirects:0});assert.equal(denied.status(),302);assert(denied.headers().location.includes('/verifikasi'));
await state('idle');assert.equal((await status()).loggedIn,false);assert.equal((await login(A)).ok,true);
await state('version');assert.equal((await status()).loggedIn,false);assert.equal((await login(A)).ok,true);
for(const x of ['account','student','temporary','disabled']){
 await state(x);assert.equal((await login(A)).ok,false,x);
}
await state('active');assert.equal((await login(C)).ok,false);assert.equal((await login(A)).ok,true);
s=await status();
await c.post('/portal/keluar',{form:{csrf_token:'wrong'},maxRedirects:0});assert.equal((await status()).loggedIn,true);
s=await status();let logout=await c.post('/portal/keluar',{form:{csrf_token:s.csrf},maxRedirects:0});assert.equal(logout.status(),302);assert(logout.headers().location.includes('keluar=1'));assert.equal((await status()).loggedIn,false);
const manual=await(await c.get('/login.php?keluar=1')).text();assert(!manual.includes('startDeviceAutoLogin({'),'manual logout must suppress auto-login');
const active=await(await c.get('/login.php?aktif=1')).text();assert(!active.includes('startDeviceAutoLogin({'));
// A different browser can still log in with password, without registering a new device.
const other=await request.newContext({baseURL:base});
try{
 let page=await(await other.get('/login.php?keluar=1')).text();
 const csrf=page.match(/name="csrf_token" value="([^"]+)"/)[1];
 let logged=await other.post('/login.php',{form:{username:'0000000001',password:'UjiAman123!',csrf_token:csrf},maxRedirects:0});
 assert.equal(logged.status(),302);let os=await(await other.get('/portal/_safari/status')).json();assert(os.loggedIn);assert(!os.via);assert(os.full);
}finally{await other.dispose();}
await state('active');
console.log('PASS HTTP/DB: generic resolver POST+Origin+input, single ACTIVE selection/no cookie/login/create/reset, two-active/blocked rejected; iOS server cookie, idle7200/session-cookie0, version/account/student/temp/binding checks, sensitive password guard, CSRF/manual logout, password login other browser');
}finally{await c.dispose();}
})().catch(e=>{console.error(e);process.exitCode=1;});
