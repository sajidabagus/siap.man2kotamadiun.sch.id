/* Registrasi service worker (PWA "Kartu Absen Digital").
   URL sw.js diturunkan dari lokasi skrip ini sendiri (assets/js/pwa.js -> /sw.js)
   supaya tetap benar walau aplikasi dipasang di sub-folder. */
(function () {
  if (!('serviceWorker' in navigator) || !document.currentScript) return;
  var swUrl;
  try {
    swUrl = new URL('../../sw.js', document.currentScript.src).href;
  } catch (e) {
    return;
  }
  window.addEventListener('load', function () {
    navigator.serviceWorker.register(swUrl).catch(function () {
      /* diamkan: PWA hanya bonus, aplikasi tetap jalan tanpa service worker */
    });
  });
})();
