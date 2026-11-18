const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const base=process.env.SIAP_TEST_URL,out=process.env.SIAP_TEST_OUT,root=path.resolve(__dirname,'../..');
const A='11111111-1111-4111-8111-111111111111',B='22222222-2222-4222-8222-222222222222',C='33333333-3333-4333-8333-333333333333';
(async()=>{
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',args:['--no-sandbox','--disable-features=ViewTransitionOnNavigation']});
const results=[];
try{
for(const width of [390,1366]){
 const ctx=await browser.newContext({baseURL:base,reducedMotion:'reduce',viewport:{width,height:width===390?844:900},
  userAgent:'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1'});
 const page=await ctx.newPage();const errors=[],transitionWarnings=[];
 page.on('pageerror',e=>{
  // Chromium navigation to an intercepted synthetic landing can abort a native
  // cross-document transition. Keep this exact browser warning in the evidence;
  // do not hide application exceptions.
  if(e.message==='Transition was aborted because of invalid state. ViewTransition opt-in disabled')transitionWarnings.push(e.message);
  else errors.push(e.message);
 });
 let mode='network',before=false,requests=0,resolves=0;
 await page.route('**/*',async route=>{
  const u=new URL(route.request().url());
  if(u.origin!==base)return route.abort();
  if(u.pathname==='/api/identitas_perangkat.php')resolves++;
  if(u.pathname==='/portal/masuk-perangkat'){
   requests++;
   if(mode==='network')return route.abort();
  }
  if(u.pathname==='/portal'&&u.searchParams.has('_g'))return route.fulfill({contentType:'text/html',body:'<h1>Portal Pengujian — berhasil masuk</h1>'});
  if(before&&u.pathname==='/login.php'){
   const r=await ctx.request.get('/portal/_safari/before-login');
   return route.fulfill({response:r});
  }
  if(before&&u.pathname==='/assets/js/device.js'&&process.env.SIAP_SAFARI_BEFORE_DEVICE)
   return route.fulfill({path:process.env.SIAP_SAFARI_BEFORE_DEVICE,contentType:'text/javascript'});
  return route.continue();
 });
 async function identity(ls,ck){
  await ctx.clearCookies();
  await page.goto(base+'/portal/_safari/status');
  await page.evaluate(value=>localStorage.setItem('presensi_device_uuid',value),ls);
  await ctx.clearCookies();
  await ctx.addCookies([{name:'presensi_device_uuid',value:ck,url:base}]);
 }
 await identity(A,A);
 if(process.env.SIAP_SAFARI_BEFORE_GATE&&process.env.SIAP_SAFARI_BEFORE_LOGIN){
  before=true;await page.goto(base+'/portal/_safari/before');
  await page.waitForURL('**/login.php');await page.locator('#btnMasuk').waitFor();
  await page.screenshot({path:path.join(out,`before-${width}.png`),fullPage:true});
  before=false;
 }
 requests=0;
 await page.goto(base+'/portal/gerbang');
 await page.locator('#deviceAutoRetry').waitFor({state:'visible'});
 assert.equal(requests,2);
 assert((await page.locator('#deviceAutoStatus').textContent()).includes('Koneksi gagal'));
 assert.equal(new URL(page.url()).pathname,'/portal/gerbang');
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
 await page.locator('#deviceAutoRetry').focus();
 assert.equal(await page.evaluate(()=>document.activeElement.id),'deviceAutoRetry');
 await page.screenshot({path:path.join(out,`after-${width}.png`),fullPage:true});
 // Double click retry cannot open concurrent batches.
 await page.evaluate(()=>{const b=document.getElementById('deviceAutoRetry');b.click();b.click();});
 await page.waitForFunction(()=>!document.getElementById('deviceAutoRetry').hidden);
 assert.equal(requests,4);
 mode='live';await page.locator('#deviceAutoRetry').click();
 await page.waitForURL('**/portal?_g=1');
 assert.equal(requests,5);
 // Existing session is irrelevant to endpoint candidate selection; fresh context cookies
 // with conflicting JS storage must validate and converge before auto-login.
 await identity(B,A);resolves=0;requests=0;
 await page.goto(base+'/portal/gerbang');await page.waitForURL('**/portal?_g=1');
 assert.equal(resolves,1);assert.equal(requests,1);
 assert.equal(await page.evaluate(()=>localStorage.getItem('presensi_device_uuid')),A);
 assert((await ctx.cookies()).some(c=>c.name==='presensi_device_uuid'&&c.value===A));
 // Unknown device -> ordinary password form and a rejection status, not network error.
 await identity(C,C);requests=0;
 await page.goto(base+'/portal/gerbang');await page.waitForURL('**/login.php');
 await page.waitForFunction(()=>document.getElementById('deviceAutoStatus')?.textContent.includes('tidak tersedia'));
 assert.equal(await page.locator('#deviceAutoRetry').isVisible(),false);
 assert.equal(await page.locator('#btnMasuk').isEnabled(),true);
 // A network error on the real login page leaves password login usable.
 mode='network';requests=0;await identity(A,A);
 await page.goto(base+'/login.php');await page.locator('#deviceAutoRetry').waitFor({state:'visible'});
 assert.equal(requests,2);assert.equal(await page.locator('#btnMasuk').isEnabled(),true);
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
 await page.locator('#deviceAutoRetry').scrollIntoViewIfNeeded();
 const button=await page.locator('#deviceAutoRetry').boundingBox();assert(button&&button.width>40);
 await page.screenshot({path:path.join(out,`login-after-${width}.png`),fullPage:true});
 // Activation form uses the same readiness barrier, not a fresh UUID.
 mode='live';await identity(B,A);resolves=0;
 await page.goto(base+'/portal/aktivasi');
 await page.waitForFunction(()=>document.getElementById('device_uuid')?.value==='11111111-1111-4111-8111-111111111111');
 assert.equal(resolves,1);
 // A failed conflict check must keep device registration unsubmitted and both
 // storage copies unchanged. Account-only activation remains a separate choice.
 await identity(B,A);mode='network';
 await page.route('**/api/identitas_perangkat.php',route=>route.abort());
 await page.goto(base+'/portal/aktivasi');
 await page.locator('#deviceIdentityIssue').waitFor();
 assert.equal(await page.locator('#device_uuid').inputValue(),'');
 assert.equal(await page.evaluate(()=>localStorage.getItem('presensi_device_uuid')),B);
 assert((await ctx.cookies()).some(c=>c.name==='presensi_device_uuid'&&c.value===A));
 assert.equal(await page.evaluate(()=>{
   const form=document.getElementById('device_uuid').form;
   const choice=form.querySelector('input[name="daftar_perangkat"]');
   if(choice)choice.checked=true;
   return form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
 }),false,'guard blocks unresolved device registration');
 assert.equal(await page.evaluate(()=>{
   const form=document.getElementById('device_uuid').form;
   form.querySelector('input[name="daftar_perangkat"]').checked=false;
   return form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
 }),true,'account-only activation remains available');
 await page.unroute('**/api/identitas_perangkat.php');
 assert.deepEqual(errors,[]);
 results.push({width,networkRetries:2,manualRetryRace:true,recovery:true,conflictResolved:true,passwordFormUsable:true,activationConflictGuard:true,overflow:false,errors,transitionWarnings});
 await ctx.close();
}
fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify(results,null,2));
console.log('PASS Chromium390/1366 actual PHP gate/login: before/after synthetic, network bounded retry/status, double-click races, manual recovery, live conflict resolution, unknown rejection/password usable, no overflow/JS errors');
}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
