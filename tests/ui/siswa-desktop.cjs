/** Run: NODE_PATH=/tmp/siap-ui-tools/node_modules node tests/ui/siswa-desktop.cjs [output-dir] */
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const out = process.argv[2] || '/tmp/siap-ui-results';
fs.mkdirSync(out, { recursive: true });
const feed = Array.from({ length: 40 }, (_, i) => ({ id: i + 1, nama: `Siswa Pengujian ${i + 1}`, kelas: 'X Uji', jam: '07:00', status: 'HADIR' }));
const data = { success: true, mode: 'kelas', kelas: 'X Uji', waktu: '07:15:00', efektif: true, ditutup: false, batas_absen: '10:00', jam_masuk: '06:30', batas_terlambat: '07:00', binding_aktif: true, gps_aktif: false, total: 40, hadir: 40, tepat: 40, terlambat: 0, tidak_hadir: 0, belum: 0, persen: 100, terbaru: feed, saya: { nama: 'Siswa Pengujian', status: 'HADIR', izin: null, jam: '07:00', bulan: { hadir: 20, terlambat: 0, izin: 2, alfa: 1 } } };
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox'] });
  const checks = [];
  try {
    for (const [width, height] of [[1440,900],[1366,768],[1024,768],[1024,600],[768,1024],[390,844]]) {
      const page = await browser.newPage({ viewport: { width, height } });
      const errors = [];
      await page.clock.setFixedTime(new Date('2026-11-17T07:15:00Z'));
      page.on('pageerror', e => errors.push(e.message));
      let fixture = 'home';
      await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.pathname === '/api/beranda.php') return route.fulfill({ json: data });
        if (url.pathname.startsWith('/api/')) return route.fulfill({ json: { success: true, registered: true, status: 'ACTIVE' } });
        if (url.pathname.startsWith('/assets/')) {
          const file = path.resolve(root, '.' + url.pathname);
          if (file.startsWith(root + '/assets/') && fs.existsSync(file)) return route.fulfill({ path: file });
        }
        if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: execFileSync('php', [path.join(__dirname, 'render-siswa.php'), fixture], { encoding: 'utf8' }) });
        return route.fulfill({ status: 404, body: '' });
      });
      await page.goto('http://siap.test/');
      await page.waitForFunction(() => document.querySelector('#apNama').textContent === 'Siswa');
      const home = await page.evaluate(() => {
        const f = document.querySelector('#feed');
        const tiles = [...document.querySelectorAll('.ap-tiles a')].map(e => {
          const text = document.createRange();
          text.selectNodeContents(e.querySelector('span'));
          const label = text.getBoundingClientRect();
          const icon = e.querySelector(':scope > i');
          const r = icon.getBoundingClientRect();
          const labelObscured = getComputedStyle(icon).display !== 'none' && label.left < r.right && label.right > r.left && label.top < r.bottom && label.bottom > r.top;
          return { width: e.clientWidth, scroll: e.scrollWidth, labelObscured };
        });
        f.scrollTop = f.scrollHeight;
        const last = f.lastElementChild.getBoundingClientRect(), box = f.getBoundingClientRect();
        return { overflow: document.documentElement.scrollWidth > innerWidth, feedReachable: last.bottom <= box.bottom + 1 && last.top >= box.top, tiles, sidebar: getComputedStyle(document.querySelector('.sd-side')).display, nav: getComputedStyle(document.querySelector('.ap-nav')).display, status: document.querySelector('#apStJudul').textContent, hadir: document.querySelector('#apHadir').textContent };
      });
      await page.screenshot({ path: path.join(out, `home-${width}x${height}.png`), fullPage: true });
      checks.push({ page: 'home', width, height, ...home, errors });
      for (fixture of ['riwayat','izin','profil']) {
        await page.goto('http://siap.test/');
        assert.equal(await page.locator('.sd-menu .on').count(), 1);
        assert.equal(await page.locator('.sd-menu .on').textContent(), fixture === 'riwayat' ? 'Riwayat' : fixture === 'izin' ? 'Izin2' : 'Profile');
        if (width === 1366) await page.screenshot({ path: path.join(out, `${fixture}-${width}x${height}.png`), fullPage: true });
        const side = await page.evaluate(() => { const e = document.querySelector('.sd-side'); e.scrollTop = e.scrollHeight; const b = e.getBoundingClientRect(), last = e.querySelector('.sd-keluar').getBoundingClientRect(); return { visible: getComputedStyle(e).display !== 'none', reachable: last.bottom <= b.bottom + 1 && last.top >= b.top }; });
        if (side.visible) assert.equal(side.reachable, true, 'Logout must remain reachable on short desktop');
        checks.push({ page: fixture, width, height, overflow: await page.evaluate(() => document.documentElement.scrollWidth > innerWidth) });
      }
      await page.close();
    }
    fs.writeFileSync(path.join(out, 'checks.json'), JSON.stringify(checks, null, 2));
    console.log(JSON.stringify(checks, null, 2));
    for (const c of checks) {
      assert.equal(c.overflow, false, `${c.page} ${c.width}: horizontal overflow`);
      if (c.page === 'home') {
        assert.equal(c.errors.length, 0, 'JavaScript errors');
        assert.equal(c.feedReachable, true, `${c.width}x${c.height}: last attendance row unreachable`);
        assert.ok(c.tiles.every(t => !t.labelObscured), `${c.width}: label obscured by decoration`);
        assert.equal(c.hadir, '20', 'Monthly count must come from API');
        assert.equal(c.sidebar === 'none', c.width < 1024, 'Sidebar breakpoint');
        assert.equal(c.nav === 'none', c.width >= 1024, 'Mobile navigation breakpoint');
        c.tiles.forEach(t => assert.ok(t.scroll <= t.width + 1, `${c.width}: summary tile overflow`));
      }
    }
    console.log('PASS: student desktop/mobile UI regression checks');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
