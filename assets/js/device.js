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
 * Baca UUID perangkat yang SUDAH ada tanpa membuat yang baru ('' bila belum ada). Dipakai gerbang masuk otomatis
 * portal: browser yang belum pernah memakai SIAP ABSEN tidak boleh dibuatkan identitas hanya karena memeriksa.
 */
function getDeviceUuidIfExists() {
  const KEY = 'presensi_device_uuid';
  const UUID_RE = /^[0-9a-fA-F-]{20,64}$/;
  let v = '';
  try { v = localStorage.getItem(KEY) || ''; } catch (e) {}
  if (!UUID_RE.test(v)) {
    try {
      const m = document.cookie.match(new RegExp('(?:^|; )' + KEY + '=([^;]*)'));
      v = m ? decodeURIComponent(m[1]) : '';
    } catch (e) { v = ''; }
  }
  return UUID_RE.test(v) ? v : '';
}
