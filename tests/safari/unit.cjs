/** Pure JS regression; no real cookies, DB or private data. */
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../assets/js/device.js'), 'utf8');
const A = '11111111-1111-4111-8111-111111111111';
const B = '22222222-2222-4222-8222-222222222222';
const C = '33333333-3333-4333-8333-333333333333';
const response = (data, status = 200) => ({ok: status >= 200 && status < 300, status, json: async () => data});
function environment({ls = A, ck = A, blocked = false, fetcher, fastTimer = false} = {}) {
  let l = ls, c = ck, calls = [], formSubmit, retryClick, redirects = 0;
  const status = {hidden: true, textContent: ''};
  const retry = {hidden: true, disabled: false, addEventListener(t, fn) {retryClick = fn;}};
  const context = {
    AbortController, Uint8Array, clearTimeout,
    setTimeout: (fn, ms) => setTimeout(fn, fastTimer ? 5 : ms),
    window: {crypto: {randomUUID: () => C}},
    crypto: {randomUUID: () => C},
    location: {protocol: 'https:'},
    localStorage: {
      getItem() {if (blocked) throw Error('blocked'); return l;},
      setItem(k, v) {if (blocked) throw Error('blocked'); l = v;}
    },
    document: {
      currentScript: {src: 'https://siap.test/sub/assets/js/device.js?v=1'},
      get cookie() {return c ? 'presensi_device_uuid=' + c : '';},
      set cookie(v) {if (!blocked) c = v.split(';')[0].split('=')[1];},
      getElementById(id) {return id === 'status' ? status : retry;},
      querySelector(sel) {return sel === 'form' ? {addEventListener(t, fn) {formSubmit = fn;}} : null;}
    },
    fetch: async (url, options) => {
      calls.push({url, body: JSON.parse(options.body)});
      return fetcher ? fetcher(url, options, calls.length) : response({ok: true});
    }
  };
  vm.createContext(context); vm.runInContext(source, context);
  return {context, status, retry, calls, values: () => ({ls: l, ck: c}),
    change: (x, y) => {l = x; c = y;},
    start(extra = {}) {return context.startDeviceAutoLogin({
      api: '/portal/masuk-perangkat', statusId: 'status', retryId: 'retry',
      onSuccess: () => {redirects++;}, ...extra
    });},
    submit: () => formSubmit(), redirects: () => redirects};
}
let count = 0;
async function check(name, fn) {await fn(); count++; console.log('PASS ' + name);}
(async () => {
  await check('all PHP identity consumers await the readiness barrier', async () => {
    const root=require('node:path').resolve(__dirname,'../..');
    for (const file of ['beranda.php','presensi/index.php','presensi/konfirmasi.php','presensi/daftar_hp.php','portal_siswa/profil.php','portal_siswa/aktivasi.php']) {
      const php=fs.readFileSync(require('node:path').join(root,file),'utf8');
      assert(php.indexOf('await prepareDeviceIdentity();')<php.indexOf('getOrCreateDeviceUuid()'),file);
      assert(php.includes('showDeviceIdentityError(error)'),file);
    }
  });
  await check('unchanged identity, no network; one-copy recovery both directions', async () => {
    for (const [ls, ck] of [[A,A],['',A],[A,'']]) {
      const e=environment({ls,ck});
      assert.equal(await e.context.prepareDeviceIdentity(),A);
      assert.equal(e.values().ls,A); assert.equal(e.values().ck,A); assert.equal(e.calls.length,0);
    }
  });
  await check('missing read-only identity does not create; explicit create persists new UUID', async () => {
    const e=environment({ls:'',ck:''});
    assert.equal(await e.context.prepareDeviceIdentity({create:false}), '');
    assert.equal(e.values().ls,'');
    assert.equal(await e.context.prepareDeviceIdentity(),C);
  });
  await check('sync conflict helpers fail closed without overwriting either copy', async () => {
    const e=environment({ls:B,ck:A});
    assert.equal(e.context.getDeviceUuidIfExists(),'');
    assert.equal(e.context.getOrCreateDeviceUuid(),'');
    assert.deepEqual(e.values(),{ls:B,ck:A});
  });
  await check('server selects cookie or localStorage candidate before synchronization; subfolder URL', async () => {
    for (const index of [0,1]) {
      const e=environment({ls:B,ck:A,fetcher:()=>response({ok:true,index})});
      assert.equal(await e.context.prepareDeviceIdentity(), index===0?B:A);
      assert.equal(e.values().ls,index===0?B:A);assert.equal(e.values().ck,index===0?B:A);
      assert.equal(e.calls[0].url,'https://siap.test/sub/api/identitas_perangkat.php');
      assert.deepEqual(e.calls[0].body.candidates,[B,A]);
    }
  });
  await check('unknown/blocked/two-active conflict rejects without creating/overwriting', async () => {
    const e=environment({ls:B,ck:A,fetcher:()=>response({ok:false})});
    await assert.rejects(e.context.prepareDeviceIdentity(),{code:'conflict'});
    assert.deepEqual(e.values(),{ls:B,ck:A});assert.equal(e.calls.length,1);
  });
  await check('conflict network failure preserves backup, retries at most twice', async () => {
    const e=environment({ls:B,ck:A,fetcher:()=>{throw Error('offline');}});
    await assert.rejects(e.context.prepareDeviceIdentity(),{code:'network'});
    assert.deepEqual(e.values(),{ls:B,ck:A});assert.equal(e.calls.length,2);
  });
  await check('malformed conflict response retries and never writes an unverified candidate', async () => {
    const e=environment({ls:B,ck:A,fetcher:()=>response({ok:true,index:9})});
    await assert.rejects(e.context.prepareDeviceIdentity(),{code:'network'});
    assert.equal(e.calls.length,2);assert.deepEqual(e.values(),{ls:B,ck:A});
  });
  await check('blocked storage/no existing identity refuses ephemeral registration', async () => {
    const e=environment({ls:'',ck:'',blocked:true});
    await assert.rejects(e.context.prepareDeviceIdentity(),{code:'storage'});
    assert.equal(await e.context.prepareDeviceIdentity({create:false}),'');
  });
  await check('blocked localStorage still reads persistent cookie', async () => {
    const e=environment({ck:A,blocked:true});
    assert.equal(await e.context.prepareDeviceIdentity({create:false}),A);
  });
  await check('concurrent identity consumers share validation request', async () => {
    const e=environment({ls:B,ck:A,fetcher:()=>response({ok:true,index:1})});
    assert.deepEqual(await Promise.all([e.context.prepareDeviceIdentity(),e.context.prepareDeviceIdentity()]),[A,A]);
    assert.equal(e.calls.length,1);
  });
  await check('cross-tab identity change during validation is not overwritten', async () => {
    let done;const e=environment({ls:B,ck:A,fetcher:()=>new Promise(r=>{done=r;})});
    const pending=e.context.prepareDeviceIdentity();e.change(C,C);done(response({ok:true,index:1}));
    await assert.rejects(pending,{code:'changed'});assert.deepEqual(e.values(),{ls:C,ck:C});
  });
  for (const [name,fetcher] of [
    ['offline',()=>{throw Error('offline');}],
    ['HTTP503',()=>response({},503)],
    ['HTTP429',()=>response({},429)],
    ['malformed JSON',()=>({ok:true,status:200,json:async()=>{throw Error('json');}})],
    ['invalid schema',()=>response({success:true})],
    ['timeout ignores abort',()=>new Promise(()=>{})]
  ]) {
    await check(name+' is network failure, bounded retry + retry control, no redirect', async () => {
      const e=environment({fetcher,fastTimer:true});await e.start().ready;
      assert.equal(e.calls.length,2);assert.equal(e.redirects(),0);
      assert(e.status.textContent.includes('Koneksi gagal'));assert.equal(e.retry.hidden,false);
    });
  }
  await check('HTTP403 is rejected, not an endless network retry', async () => {
    const e=environment({fetcher:()=>response({ok:false},403)});await e.start().ready;
    assert.equal(e.calls.length,1);assert(e.status.textContent.includes('tidak tersedia'));
  });
  await check('credential rejection is distinct from network and is not retried', async () => {
    let rejected=0;const e=environment({fetcher:()=>response({ok:false})});
    await e.start({onRejected:()=>rejected++}).ready;
    assert.equal(e.calls.length,1);assert.equal(rejected,1);assert.equal(e.retry.hidden,true);
  });
  await check('transient failure then success redirects once with two requests', async () => {
    const e=environment({fetcher:(u,o,n)=>n===1?response({},503):response({ok:true})});
    const c=e.start();await c.ready;await c.retry();assert.equal(e.calls.length,2);assert.equal(e.redirects(),1);
  });
  await check('manual retry races share one login attempt, never parallel redirects', async () => {
    let done;const e=environment({fetcher:()=>new Promise(r=>{done=r;})});
    const c=e.start();await new Promise(setImmediate);
    await Promise.all([c.retry(),c.retry()]);
    assert.equal(e.calls.length,1);done(response({ok:true}));await c.ready;assert.equal(e.redirects(),1);
  });
  await check('manual password submission wins over late auto-login success', async () => {
    let done;const e=environment({fetcher:()=>new Promise(r=>{done=r;})});
    const c=e.start({cancelOnSubmit:true});await new Promise(setImmediate);
    e.submit();done(response({ok:true}));await c.ready;assert.equal(e.redirects(),0);
  });
  await check('late timed-out response cannot redirect or defeat a later manual retry', async () => {
    const pending=[];let recover=false;
    const e=environment({fastTimer:true,fetcher:()=>recover?response({ok:true}):new Promise(r=>pending.push(r))});
    const c=e.start();await c.ready;assert.equal(e.calls.length,2);
    recover=true;await c.retry();assert.equal(e.redirects(),1);
    pending.forEach(resolve=>resolve(response({ok:true})));await new Promise(setImmediate);
    assert.equal(e.redirects(),1);
  });
  console.log(`${count} groups PASS; synthetic Node VM, not physical Safari.`);
})().catch(e=>{console.error(e);process.exitCode=1;});
