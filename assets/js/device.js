/**
 * Device UUID — dibuat SEKALI per instalasi browser/WebView dan disimpan
 * persisten agar sama di kunjungan berikutnya. Server hanya meng-hash UUID
 * ini (lihat includes/device_binding.php); User-Agent TIDAK ikut dihash,
 * jadi update Chrome/WebView tidak membuat perangkat dianggap baru.
 *
 * Penyimpanan ganda (localStorage + cookie 400 hari): bila salah satunya
 * hilang/dibersihkan, UUID dipulihkan dari yang lain.
 *
 * Catatan jujur: ini BUKAN hardware ID permanen. Jika siswa menghapus data
 * aplikasi/browser (localStorage DAN cookie), memakai mode incognito, ganti
 * browser, atau ganti perangkat, UUID baru akan dibuat dan perangkat dianggap
 * baru oleh sistem.
 */
function getOrCreateDeviceUuid() {
  const KEY = 'presensi_device_uuid';
  const UUID_RE = /^[0-9a-fA-F-]{20,64}$/;

  let ls = null, ck = null;
  try { ls = localStorage.getItem(KEY); } catch (e) {}
  try {
    const m = document.cookie.match(new RegExp('(?:^|; )' + KEY + '=([^;]*)'));
    ck = m ? decodeURIComponent(m[1]) : null;
  } catch (e) {}

  let uuid = (ls && UUID_RE.test(ls)) ? ls : ((ck && UUID_RE.test(ck)) ? ck : null);
  if (!uuid) {
    uuid = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : fallbackUuid();
  }

  // Tulis ulang ke kedua penyimpanan bila ada yang kosong/berbeda
  if (ls !== uuid) { try { localStorage.setItem(KEY, uuid); } catch (e) {} }
  if (ck !== uuid) {
    try {
      document.cookie = KEY + '=' + encodeURIComponent(uuid) +
        '; max-age=34560000; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    } catch (e) {}
  }
  return uuid;
}

function fallbackUuid() {
  // Gunakan CSPRNG bila ada; Math.random hanya cadangan terakhir
  const bytes = new Uint8Array(16);
  if (window.crypto && crypto.getRandomValues) crypto.getRandomValues(bytes);
  else for (let i = 0; i < 16; i++) bytes[i] = Math.random() * 256 | 0;
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const h = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
}

/**
 * C1 — DEVICE SECRET (HMAC v3):
 * Secret 32 byte di-generate sekali per instalasi, disimpan ganda
 * (localStorage + cookie, pola sama dengan UUID), dan selalu dikirim
 * bersama UUID. Server menghitung hash perangkat dari HMAC(uuid, secret):
 * setelah baris binding terupgrade ke skema v3, UUID yang disalin saja
 * TIDAK lagi cukup untuk memakai identitas perangkat.
 */
function getOrCreateDeviceSecret() {
  const KEY = 'presensi_device_secret';
  const RE = /^[0-9a-fA-F]{64}$/;

  let ls = null, ck = null;
  try { ls = localStorage.getItem(KEY); } catch (e) {}
  try {
    const m = document.cookie.match(new RegExp('(?:^|; )' + KEY + '=([^;]*)'));
    ck = m ? decodeURIComponent(m[1]) : null;
  } catch (e) {}

  let secret = (ls && RE.test(ls)) ? ls : ((ck && RE.test(ck)) ? ck : null);
  if (!secret) {
    const b = new Uint8Array(32);
    if (window.crypto && crypto.getRandomValues) crypto.getRandomValues(b);
    else for (let i = 0; i < 32; i++) b[i] = Math.random() * 256 | 0;
    secret = Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
  }

  // Tulis ulang bila ada yang kosong/berbeda
  if (ls !== secret) { try { localStorage.setItem(KEY, secret); } catch (e) {} }
  if (ck !== secret) {
    try {
      document.cookie = KEY + '=' + encodeURIComponent(secret) +
        '; max-age=34560000; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    } catch (e) {}
  }
  return secret;
}