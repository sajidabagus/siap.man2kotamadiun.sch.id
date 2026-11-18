const { chromium }=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
const B=process.env.SIAP_TEST_URL,O=process.env.SIAP_TEST_OUT,UUID='11111111-1111-4111-8111-111111111111';
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',args:['--no-sandbox']});
 let results=[];
 try{
 for(const w of [390,1366]){
  const context=await browser.newContext({viewport:{width:w,height:900},reducedMotion:'reduce',locale:'id-ID'});
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await context.request.get(B+'/_pulang/admin');await page.goto(B+'/pengaturan');
  await page.locator('[data-bs-target="#tab-absensi"],[href="#tab-absensi"]').click();
  await page.locator('#ja-jam_masuk').fill('05:40');await page.locator('#ja-batas_absen').fill('09:00');await page.locator('#ja-batas_terlambat').fill('07:00');await page.locator('#ja-pulang_buka').fill('13:00');await page.locator('#ja-pulang_tutup').fill('16:00');
  await assert.doesNotReject(()=>page.locator('#jaSummary').getByText(/Tepat waktu sampai 07:00/).waitFor());
  assert.equal(await page.locator('#jaTrack span').count(),3);
  await page.locator('#ja-pulang_buka').fill('08:30');await page.locator('#jaValidation').getByText(/setelah datang ditutup/).waitFor();
  await page.locator('#ja-pulang_buka').fill('13:00');await page.locator('#ja-batas_terlambat').fill('');assert.equal(await page.locator('#jaTrack span').count(),2);
  await page.locator('#ja-pulang_buka').fill('1300');assert.equal(await page.locator('#ja-pulang_buka').inputValue(),'13:00');
  await page.locator('#ja-pulang_buka').fill('25:00');assert.notEqual(await page.locator('#jaValidation').textContent(),'');
  await page.locator('#ja-pulang_buka').fill('13:00');
  await page.locator('#ja-batas_terlambat').fill('07:00');
  await page.locator('#ja-batas_terlambat').press('Tab');
  assert.notEqual(await page.evaluate(()=>document.activeElement.tagName),'BODY');
  await page.evaluate(()=>{document.activeElement.blur();window.scrollTo(0,0);});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'settings no horizontal overflow');
  fs.writeFileSync(O+`/settings-bounds-${w}.json`,JSON.stringify(await page.locator('.jam-absensi').boundingBox()));
  await page.screenshot({path:O+`/settings-after-${w}.png`,fullPage:true});
  if(process.env.SIAP_PULANG_BEFORE){await page.goto(B+'/_pulang/before-settings');await page.locator('[data-bs-target="#tab-absensi"],[href="#tab-absensi"]').click();await page.evaluate(()=>window.scrollTo(0,0));fs.writeFileSync(O+`/settings-before-bounds-${w}.json`,JSON.stringify(await page.locator('#tab-absensi .card').first().boundingBox()));await page.screenshot({path:O+`/settings-before-${w}.png`,fullPage:true});}
  await context.close();
  const student=await browser.newContext({viewport:{width:w,height:900},reducedMotion:'reduce',locale:'id-ID'});
  await student.addInitScript(uuid=>{localStorage.setItem('presensi_device_uuid',uuid);document.cookie='presensi_device_uuid='+uuid+'; path=/';},UUID);
  // Use actual device key names, not guessed fixtures.
  const sp=await student.newPage();sp.on('pageerror',e=>errors.push(e.message));
  await student.request.post(B+'/_pulang/control',{data:{state:'departure'}});
  await student.request.get(B+'/_pulang/student');
  const gate=await(await student.request.get(B+'/_pulang/gate')).json();
  await sp.goto(B+'/presensi/?gate='+gate.token);
  try { await sp.waitForURL('**/presensi/konfirmasi.php',{timeout:10000}); }
  catch(e) { fs.writeFileSync(O+'/scan-debug.json',JSON.stringify({url:sp.url(),errors,body:await sp.locator('body').innerText()},null,2));await sp.screenshot({path:O+'/scan-debug.png'});throw e; }
  await sp.locator('#btnConfirm').waitFor({state:'visible'});
  await sp.locator('#btnConfirm').click();
  await sp.waitForURL('**/presensi/berhasil.php?status=ok*');
  await sp.getByText('Absen pulang berhasil',{exact:true}).waitFor();
  await sp.goto(B+'/');await sp.locator('#apStPulang').getByText(/Pulang: \d/).waitFor();
  assert.match(await sp.locator('#apPulangBulan').textContent(),/1 hari/);
  assert.equal(await sp.locator('#apAbsen').isDisabled(),true);
  await sp.screenshot({path:O+`/home-after-${w}.png`,fullPage:true});
  await sp.goto(B+'/portal/riwayat');await sp.getByText(/Pulang tercatat:/).waitFor();await sp.screenshot({path:O+`/history-after-${w}.png`,fullPage:true});
  if(process.env.SIAP_PULANG_BEFORE){await sp.goto(B+'/_pulang/before-home');await sp.locator('#apNama').getByText('Siswa',{exact:true}).waitFor();await sp.screenshot({path:O+`/home-before-${w}.png`,fullPage:true});}
  const overflow=await sp.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);assert.equal(overflow,false);
  // Native ViewTransition aborts from cross-document navigation may occur; never hide app exceptions.
  const native=errors.filter(e=>e.includes('ViewTransition'));const appErrors=errors.filter(e=>!e.includes('ViewTransition'));assert.deepEqual(appErrors,[]);
  results.push({width:w,settingsTimeline:true,actualScanConfirmDeparture:true,homeHistory:true,overflow,nativeViewTransition:native});await student.close();
 }
 }finally{await browser.close();}
 fs.writeFileSync(O+'/browser-results.json',JSON.stringify(results,null,2));console.log('PASS Chromium 390/1366 actual settings/live timeline, scan-confirm departure, home/history and no overflow');
})().catch(e=>{console.error(e);process.exit(1)});
