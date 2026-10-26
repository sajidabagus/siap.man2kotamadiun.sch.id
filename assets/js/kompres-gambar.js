/*
 * Kompresi foto otomatis di browser SEBELUM diunggah.
 * Pasang atribut pada <input type="file">:
 *   data-kompres="1024"            -> sisi terpanjang maksimal (px); kosong = 1280
 *   data-kompres-target="614400"   -> target ukuran (byte); kualitas diturunkan bertahap bila lebih besar (min. 65%)
 * Hanya JPG/PNG/WEBP yang diproses; PDF dan jenis lain dibiarkan apa adanya. PNG bertransparansi tetap PNG.
 * Foto dari HP berukuran belasan MB biasanya menjadi 100-400 KB tanpa penurunan kualitas yang terlihat.
 * Bila kompresi gagal / tidak didukung browser, file asli dipakai (server tetap memeriksa dan mengompres ulang).
 */
(function () {
  'use strict';

  var SISI_BAWAAN = 1280, KUALITAS_AWAL = 0.86, KUALITAS_MIN = 0.65, LEWATI_DI_BAWAH = 150 * 1024;
  var TIPE = /^image\/(jpeg|png|webp)$/i;

  function adaDataTransfer() {
    try { return typeof DataTransfer !== 'undefined' && !!new DataTransfer().items; } catch (e) { return false; }
  }

  function muatViaImg(file) {
    return new Promise(function (ok, gagal) {
      var u = URL.createObjectURL(file), im = new Image();
      im.onload = function () { URL.revokeObjectURL(u); ok(im); };
      im.onerror = function () { URL.revokeObjectURL(u); gagal(new Error('gambar tidak bisa dibaca')); };
      im.src = u;
    });
  }

  // createImageBitmap + imageOrientation menghormati EXIF (foto kamera HP tidak miring); cadangan: <img>.
  function muat(file) {
    if (window.createImageBitmap) {
      return createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () { return muatViaImg(file); });
    }
    return muatViaImg(file);
  }

  function enkode(canvas, tipe, kualitas) {
    return new Promise(function (ok) { canvas.toBlob(function (b) { ok(b); }, tipe, kualitas); });
  }

  function adaTransparan(ctx, w, h) {
    var d = ctx.getImageData(0, 0, w, h).data;
    for (var i = 3; i < d.length; i += 8) { if (d[i] < 250) return true; }   // cek tiap 2 piksel
    return false;
  }

  function kompres(file, maksSisi, targetByte) {
    return muat(file).then(function (src) {
      var lebar = src.naturalWidth || src.width, tinggi = src.naturalHeight || src.height;
      var skala = Math.min(1, maksSisi / Math.max(lebar, tinggi));
      var w = Math.max(1, Math.round(lebar * skala)), h = Math.max(1, Math.round(tinggi * skala));
      var canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      var ctx = canvas.getContext('2d');
      ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(src, 0, 0, w, h);
      if (src.close) src.close();

      var transparan = file.type !== 'image/jpeg' && adaTransparan(ctx, w, h);
      var tipe = transparan ? 'image/png' : 'image/jpeg';
      if (!transparan) {          // ratakan ke latar putih agar JPEG tidak berlatar hitam
        ctx.globalCompositeOperation = 'destination-over';
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h);
      }

      function coba(q) {
        return enkode(canvas, tipe, tipe === 'image/png' ? undefined : q).then(function (blob) {
          if (blob && tipe !== 'image/png' && targetByte && blob.size > targetByte && q - 0.1 >= KUALITAS_MIN) return coba(q - 0.1);
          return blob;
        });
      }
      return coba(KUALITAS_AWAL).then(function (blob) {
        // Tidak diperkecil dan hasilnya tidak lebih kecil -> pakai file asli.
        if (!blob || (skala >= 1 && blob.size >= file.size && tipe === file.type)) return file;
        var nama = (file.name || 'foto').replace(/\.[^.]+$/, '') + (tipe === 'image/png' ? '.png' : '.jpg');
        return new File([blob], nama, { type: tipe, lastModified: Date.now() });
      });
    });
  }

  // Tangkap 'change' pada fase capture: tahan dulu, kompres, ganti file, lalu kirim ulang 'change' ke penanganan halaman.
  document.addEventListener('change', function (e) {
    var inp = e.target;
    if (!inp || inp.tagName !== 'INPUT' || inp.type !== 'file' || !inp.hasAttribute('data-kompres')) return;
    if (inp._kompresSelesai) { inp._kompresSelesai = false; return; }          // 'change' hasil kirim ulang: biarkan lewat
    var f = inp.files && inp.files[0];
    if (!f || !TIPE.test(f.type) || f.size <= LEWATI_DI_BAWAH || !adaDataTransfer()) return;

    e.stopImmediatePropagation();
    var maks = parseInt(inp.getAttribute('data-kompres'), 10) || SISI_BAWAAN;
    var target = parseInt(inp.getAttribute('data-kompres-target'), 10) || 0;
    function lanjut(hasil) {
      try {
        if (hasil && hasil !== f) { var dt = new DataTransfer(); dt.items.add(hasil); inp.files = dt.files; }
      } catch (err) { /* pakai file asli */ }
      inp._kompresSelesai = true;
      inp.dispatchEvent(new Event('change', { bubbles: true }));
    }
    inp.classList.add('sedang-kompres');
    kompres(f, maks, target).then(lanjut, function () { lanjut(f); }).then(function () { inp.classList.remove('sedang-kompres'); });
  }, true);

  window.kompresGambar = kompres;
})();
