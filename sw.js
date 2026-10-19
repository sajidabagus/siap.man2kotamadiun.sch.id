/**
 * Service worker SIAP — "Kartu Absen Digital".
 *
 * Strategi (sengaja minimal & aman):
 * - HANYA GET same-origin di bawah folder assets/ yang di-cache
 *   (stale-while-revalidate) + manifest & ikon PWA saat instalasi.
 * - HTML dinamis, API, dan endpoint PHP SELALU lewat jaringan (tidak
 *   pernah di-cache) supaya data presensi tidak pernah basi/tercampur.
 * - Bump CACHE bila daftar PRECACHE berubah; perubahan isi berkas
 *   otomatis ter-update lewat stale-while-revalidate tanpa bump.
 */
const CACHE = 'siap-static-v2';

const ASSET_PREFIX = new URL('assets/', self.registration.scope).pathname;
const scopeUrl = (path) => new URL(path, self.registration.scope).toString();

const PRECACHE = [
  'assets/css/vendor/bootstrap.min.css',
  'assets/css/vendor/bootstrap-icons.min.css',
  'assets/css/style.css',
  'assets/css/presensi.css',
  'assets/js/vendor/html5-qrcode.min.js',
  'assets/js/vendor/qrcode.js',
  'assets/js/device.js',
  'assets/js/pwa.js',
  'assets/img/icon-192.png',
  'assets/img/icon-512.png',
  'manifest.json',
];

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    // allSettled: satu berkas gagal (mis. 404) tidak menggagalkan instalasi.
    await Promise.allSettled(PRECACHE.map((path) => cache.add(scopeUrl(path))));
    self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys.filter((k) => k.startsWith('siap-') && k !== CACHE).map((k) => caches.delete(k))
    );
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;        // CDN/eksternal: biarkan jaringan
  if (!url.pathname.startsWith(ASSET_PREFIX)) return;     // HTML/API dinamis: biarkan jaringan

  event.respondWith(staleWhileRevalidate(req, url));
});

async function staleWhileRevalidate(req, url) {
  const cache = await caches.open(CACHE);
  // D5: permintaan ber-query versi (?v=filemtime) memakai kunci URL LENGKAP;
  // kalau dinormalkan ke pathname saja, cache-busting filemtime dilumpuhkan
  // dan klien PWA terus disajikan device.js lama. Tanpa query: normalisasi
  // seperti semula agar tidak menumpuk entri ganda.
  const key = url.search ? req.url : url.origin + url.pathname;
  const cached = await cache.match(key);
  const fresh = fetch(req)
    .then((res) => {
      if (res && res.ok) cache.put(key, res.clone());
      return res;
    })
    .catch(() => null);

  if (cached) return cached; // sajikan cepat; pembaruan jalan di belakang
  const res = await fresh;
  return res || new Response('', { status: 504, statusText: 'Aset tidak tersedia offline' });
}
