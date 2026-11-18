(function () {
  'use strict';
  var keys = ['jam_masuk', 'batas_absen', 'batas_terlambat', 'pulang_buka', 'pulang_tutup'];
  var fields = keys.map(function (k) { return document.getElementById('ja-' + k); });
  if (fields.some(function (f) { return !f; })) return;
  function min(v) { if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(v)) return NaN; var p = v.split(':'); return +p[0] * 60 + +p[1]; }
  function render() {
    var raw = fields.map(function (f) { return f.value; }), v = raw.map(min), a = v[0], b = v[1], l = v[2], c = v[3], d = v[4];
    var error = [a,b,c,d].some(function (n) { return !Number.isFinite(n); }) ? 'Lengkapi jam buka/tutup datang dan pulang.' :
      a >= b ? 'Buka datang harus sebelum tutup datang.' :
      raw[2] && (!Number.isFinite(l) || l < a || l >= b) ? 'Batas terlambat harus di dalam jendela datang.' :
      c >= d ? 'Buka pulang harus sebelum tutup pulang.' :
      c <= b ? 'Pulang harus dibuka setelah datang ditutup.' : '';
    document.getElementById('jaValidation').textContent = error;
    fields.forEach(function (f) { f.setCustomValidity(''); });
    if (error) fields[4].setCustomValidity(error);
    var track = document.getElementById('jaTrack'), ticks = document.getElementById('jaTicks'), summary = document.getElementById('jaSummary');
    track.textContent = ''; ticks.textContent = ''; summary.textContent = '';
    if (error) return;
    var start = Math.floor(Math.min(300, a) / 60) * 60, end = Math.ceil(Math.max(1020, d) / 60) * 60, range = end - start;
    function segment(x,y,css,text) { if (y <= x) return; var el = document.createElement('span'); el.className = css; el.style.left = (x-start)/range*100+'%'; el.style.width = (y-x)/range*100+'%'; el.textContent = text; track.appendChild(el); }
    segment(a, raw[2] ? l : b, 'ja-good', 'Tepat waktu');
    if (raw[2]) segment(l,b,'ja-late','Terlambat');
    segment(c,d,'ja-out','Pulang');
    for (var m = start; m <= end; m += 60) { var tick = document.createElement('span'); tick.style.left=(m-start)/range*100+'%';tick.textContent = String(Math.floor(m/60)).padStart(2,'0')+':00'; ticks.appendChild(tick); }
    var datang = document.createElement('div'), pulang = document.createElement('div');
    datang.textContent = 'Datang   ' + raw[0] + '–' + raw[1] + (raw[2] ? ' · Tepat waktu sampai ' + raw[2] + ', terlambat setelahnya' : ' · Tanpa batas terlambat');
    pulang.textContent = 'Pulang   ' + raw[3] + '–' + raw[4] + ' · Jam tutup eksklusif';
    summary.append(datang,pulang);
  }
  fields.forEach(function (f) {
    f.addEventListener('input',function () {
      // Keyboard numerik HP: 1300 menjadi 13:00; format tampilan tidak mengikuti AM/PM OS.
      if (/^\d{4}$/.test(f.value)) f.value=f.value.slice(0,2)+':'+f.value.slice(2);
      render();
    });
    f.addEventListener('blur',function () { if (/^\d:\d{2}$/.test(f.value)) f.value='0'+f.value;render(); });
  });
  render();
})();
