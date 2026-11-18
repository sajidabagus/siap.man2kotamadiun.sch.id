/**
 * Identitas browser, bukan hardware ID. Cadangan localStorage + cookie 400 hari.
 * Konflik TIDAK diselesaikan dengan menimpa salah satu: server memilih tepat satu
 * binding ACTIVE. Pemanggil async harus menunggu prepareDeviceIdentity().
 * UUID adalah kredensial; jangan mencatat/menampilkannya.
 */
var DEVICE_UUID_KEY = 'presensi_device_uuid';
var DEVICE_UUID_RE = /^[0-9a-fA-F-]{20,64}$/;
var deviceIdentityFlight = null;
// Ambil base dari URL script agar instalasi subfolder tetap bekerja.
var deviceIdentityBase = (function () {
  var s = document.currentScript;
  return s && s.src ? s.src.split('/assets/js/device.js')[0] : '';
})();

function readDeviceIdentity() {
  var ls = '', ck = '';
  try { ls = localStorage.getItem(DEVICE_UUID_KEY) || ''; } catch (e) {}
  try {
    var m = document.cookie.match(/(?:^|; )presensi_device_uuid=([^;]*)/);
    ck = m ? decodeURIComponent(m[1]) : '';
  } catch (e) {}
  return { ls: DEVICE_UUID_RE.test(ls) ? ls : '', ck: DEVICE_UUID_RE.test(ck) ? ck : '' };
}

function deviceIdentityError(code) {
  var e = new Error(code);
  e.code = code;
  return e;
}

function storeDeviceIdentity(uuid) {
  var state = readDeviceIdentity();
  if (state.ls !== uuid) {
    try { localStorage.setItem(DEVICE_UUID_KEY, uuid); } catch (e) {}
  }
  if (state.ck !== uuid) {
    try {
      document.cookie = DEVICE_UUID_KEY + '=' + encodeURIComponent(uuid) +
        '; max-age=34560000; path=/; SameSite=Lax' +
        (location.protocol === 'https:' ? '; Secure' : '');
    } catch (e) {}
  }
  state = readDeviceIdentity();
  if ((state.ls !== uuid && state.ck !== uuid) ||
      (state.ls && state.ck && state.ls !== state.ck)) throw deviceIdentityError('storage');
  return uuid;
}

/** Kompatibilitas sinkron: konflik dikosongkan, tidak pernah ditimpa/didaftar baru. */
function getDeviceUuidIfExists() {
  var state = readDeviceIdentity();
  return state.ls && state.ck && state.ls !== state.ck ? '' : (state.ls || state.ck);
}

function getOrCreateDeviceUuid() {
  var state = readDeviceIdentity();
  if (state.ls && state.ck && state.ls !== state.ck) return '';
  return storeDeviceIdentity(state.ls || state.ck ||
    ((window.crypto && crypto.randomUUID) ? crypto.randomUUID() : fallbackUuid()));
}

function fallbackUuid() {
  var bytes = new Uint8Array(16);
  if (!window.crypto || !crypto.getRandomValues) throw deviceIdentityError('storage');
  crypto.getRandomValues(bytes);
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  var h = Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
  return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
}

/**
 * Maksimal dua usaha otomatis, timeout mencakup body JSON. Race membuang hasil
 * terlambat walau transport tidak menghormati abort. Hanya transport/5xx/429
 * dicoba ulang; penolakan perangkat (ok:false) bukan kegagalan jaringan.
 */
async function deviceJsonRequest(url, body, options) {
  options = options || {};
  var attempts = Math.max(1, Math.min(2, options.attempts || 2));
  for (var attempt = 1; attempt <= attempts; attempt++) {
    if (options.onAttempt) options.onAttempt(attempt);
    var controller = new AbortController(), timer;
    try {
      return await Promise.race([
        (async function () {
          var r = await fetch(url, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            signal: controller.signal,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
          });
          if (!r.ok) throw deviceIdentityError(r.status >= 500 || r.status === 429 ? 'network' : 'rejected');
          var data;
          try { data = await r.json(); } catch (e) { throw deviceIdentityError('network'); }
          if (!data || typeof data.ok !== 'boolean' ||
              (options.validate && !options.validate(data))) throw deviceIdentityError('network');
          return data;
        })(),
        new Promise(function (_, reject) {
          timer = setTimeout(function () {
            controller.abort();
            reject(deviceIdentityError('network'));
          }, options.timeout || 8000);
        })
      ]);
    } catch (e) {
      if (e.code === 'rejected') throw e;
      if (attempt === attempts) throw deviceIdentityError('network');
    } finally {
      clearTimeout(timer);
      controller.abort();
    }
  }
}

async function prepareDeviceIdentity(options) {
  options = options || {};
  if (deviceIdentityFlight) {
    await deviceIdentityFlight;
    return options.create === false ? getDeviceUuidIfExists() : getOrCreateDeviceUuid();
  }
  deviceIdentityFlight = (async function () {
    var before = readDeviceIdentity();
    if (before.ls && before.ck && before.ls !== before.ck) {
      var resolveOptions = Object.assign({}, options, {
        validate: function (data) { return !data.ok || data.index === 0 || data.index === 1; }
      });
      var data = await deviceJsonRequest(deviceIdentityBase + '/api/identitas_perangkat.php',
        { candidates: [before.ls, before.ck] }, resolveOptions);
      if (!data.ok || (data.index !== 0 && data.index !== 1)) throw deviceIdentityError('conflict');
      var now = readDeviceIdentity();
      // Tab lain dapat memperbarui identitas ketika jaringan masih berjalan.
      if (now.ls !== before.ls || now.ck !== before.ck) throw deviceIdentityError('changed');
      return storeDeviceIdentity(data.index === 0 ? before.ls : before.ck);
    }
    if (!before.ls && !before.ck && options.create === false) return '';
    return getOrCreateDeviceUuid();
  })();
  try { return await deviceIdentityFlight; }
  finally { deviceIdentityFlight = null; }
}

/** UI kegagalan identitas untuk scan/pendaftaran: jangan lanjut dengan UUID baru. */
function showDeviceIdentityError(error) {
  var box = document.getElementById('deviceIdentityIssue');
  if (!box) {
    box = document.createElement('div');
    box.id = 'deviceIdentityIssue';
    box.className = 'alert alert-warning';
    box.setAttribute('role', 'alert');
    (document.querySelector('main') || document.body).prepend(box);
  }
  box.textContent = error.code === 'network'
    ? 'Koneksi gagal. Identitas perangkat belum dapat diperiksa. Data perangkat tidak diubah. '
    : 'Identitas perangkat belum dapat dipastikan. Jangan hapus data browser atau daftar ulang perangkat. ';
  var button = document.createElement('button');
  button.type = 'button';
  button.className = 'btn btn-primary';
  button.textContent = 'Coba lagi';
  button.onclick = function () { location.reload(); };
  box.appendChild(button);
}

/** Pendaftaran tidak boleh tersubmit ketika resolusi belum selesai/gagal. */
function guardDeviceIdentityForms() {
  document.addEventListener('submit', function (event) {
    var field = event.target.querySelector('input[name="device_uuid"]');
    var choice = event.target.querySelector('input[name="daftar_perangkat"]');
    // Aktivasi akun tanpa opsi/centang perangkat tetap sah di browser lain.
    var registering = choice ? choice.checked : (field && field.id !== 'device_uuid');
    if (field && registering && (!field.value || field.value !== getDeviceUuidIfExists())) {
      event.preventDefault();
      event.stopImmediatePropagation();
      showDeviceIdentityError(deviceIdentityError('pending'));
    }
  }, true);
}

/**
 * Satu controller untuk halaman gerbang dan login. Tombol retry tidak boleh
 * membuat request paralel; penolakan berbeda dari gangguan koneksi.
 */
function startDeviceAutoLogin(options) {
  var busy = false, finished = false;
  var status = document.getElementById(options.statusId);
  var retry = document.getElementById(options.retryId);
  function text(value) { if (status) { status.hidden = false; status.textContent = value; } }
  async function run() {
    if (busy || finished) return;
    busy = true;
    if (retry) { retry.hidden = true; retry.disabled = true; }
    text('Memeriksa perangkat…');
    try {
      var uuid = await prepareDeviceIdentity({ create: false });
      if (finished) return;
      if (!uuid) {
        finished = true;
        text('Perangkat belum dikenali. Silakan masuk dengan password.');
        if (options.onRejected) options.onRejected({});
        return;
      }
      var data = await deviceJsonRequest(options.api, { uuid: uuid }, {
        onAttempt: function (n) {
          text(n === 1 ? 'Memeriksa perangkat…' : 'Koneksi terganggu. Mencoba sekali lagi…');
        }
      });
      if (finished) return;
      finished = true;
      if (data.ok) {
        text('Perangkat dikenali. Membuka portal…');
        options.onSuccess();
      } else {
        text('Masuk otomatis tidak tersedia. Silakan masuk dengan password.');
        if (options.onRejected) options.onRejected(data);
      }
    } catch (e) {
      if (finished) return;
      if (e.code === 'network') text('Koneksi gagal. Perangkat belum dapat diperiksa. Coba lagi atau masuk dengan password.');
      else if (e.code === 'rejected') text('Masuk otomatis tidak tersedia. Silakan masuk dengan password.');
      else text('Identitas perangkat belum dapat dipastikan. Data perangkat tidak diubah. Coba lagi atau masuk dengan password.');
      if (retry) retry.hidden = false;
    } finally {
      busy = false;
      if (retry) retry.disabled = false;
      var spin = document.querySelector('.pt-spin');
      if (spin) spin.hidden = true;
    }
  }
  if (options.cancelOnSubmit) {
    var form = document.querySelector('form');
    if (form) form.addEventListener('submit', function () { finished = true; }, true);
  }
  if (retry) retry.addEventListener('click', run);
  var ready = run();
  return { retry: run, ready: ready };
}
