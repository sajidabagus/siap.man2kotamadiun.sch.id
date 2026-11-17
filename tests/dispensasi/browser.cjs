const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox'],
  });
  try {
    for (const [width, height] of [[390, 844], [1366, 768]]) {
    const page = await browser.newPage({ viewport: { width, height }, locale: 'id-ID' });
      const errors = [];
      page.on('pageerror', e => errors.push(e.message));
      await page.goto(process.env.SIAP_TEST_URL + '/_test/form');
      if (process.env.SIAP_TEST_BEFORE_ONLY === '1') {
        await page.screenshot({ path: process.env.SIAP_TEST_OUT + `/form-before-${width}.png`, fullPage: true });
        await page.close(); continue;
      }
      await page.evaluate(() => scrollTo(0, 0));
      await page.screenshot({ path: process.env.SIAP_TEST_OUT + `/form-awal-${width}.png`, fullPage: true });
      await page.locator('label').filter({ has: page.locator('[name=jenis][value=dispensasi]') }).click();
      await page.selectOption('[name=mode_durasi]', 'jam');
      assert(await page.isVisible('#jamDispensasi'));
      assert.equal(await page.getAttribute('#lampiran', 'required'), '');
      assert(await page.isDisabled('#selesai'));
      await page.fill('#jamMulai', '11:00');
      await page.fill('#jamSelesai', '10:00');
      assert.equal(await page.$eval('#jamSelesai', e => e.checkValidity()), false);
      await page.fill('#jamSelesai', '12:00');
      assert.equal(await page.$eval('#jamSelesai', e => e.checkValidity()), true);
      await page.evaluate(() => { document.activeElement.blur(); scrollTo(0, 0); });
      await page.screenshot({ path: process.env.SIAP_TEST_OUT + `/form-jam-${width}.png`, fullPage: true });
      await page.selectOption('[name=mode_durasi]', 'beberapa_hari');
      assert(!(await page.isDisabled('#selesai')));
      assert(!(await page.isVisible('#jamDispensasi')));
      assert.equal(await page.$eval('#selesai', e => e.required), true);
      assert.equal(await page.textContent('#labelSelesai'), 'Tanggal akhir (inklusif, wajib)');
      await page.evaluate(() => { document.activeElement.blur(); scrollTo(0, 0); });
      await page.screenshot({ path: process.env.SIAP_TEST_OUT + `/form-hari-${width}.png`, fullPage: true });
      await page.selectOption('[name=mode_durasi]', 'sehari');
      assert(!(await page.isVisible('#jamDispensasi')));
      assert(await page.isDisabled('#selesai'));
      await page.locator('label').filter({ has: page.locator('[name=jenis][value=sakit]') }).click();
      assert(!(await page.isVisible('#durasiDispensasi')));
      assert.equal(await page.$eval('#lampiran', e => e.required), false);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
      assert.deepEqual(errors, []);
      console.log(`PASS browser ${width}: three duration modes, required photo, time validation, legacy sick form, no overflow/JS errors`);
      await page.close();
    }
    if (process.env.SIAP_TEST_BEFORE_ONLY === '1') return;
    const page = await browser.newPage({ viewport: { width: 1366, height: 900 }, locale: 'id-ID' });
    await page.request.get(process.env.SIAP_TEST_URL + '/_test/admin-session');
    await page.goto(process.env.SIAP_TEST_URL + '/izin/' + process.env.SIAP_TEST_PENDING_ID);
    await page.screenshot({ path: process.env.SIAP_TEST_OUT + '/admin-detail-1366.png', fullPage: true });
    await page.request.get(process.env.SIAP_TEST_URL + '/_test/student-session');
    await page.goto(process.env.SIAP_TEST_URL + '/portal/izin/' + process.env.SIAP_TEST_PENDING_ID);
    await page.screenshot({ path: process.env.SIAP_TEST_OUT + '/siswa-detail-1366.png', fullPage: true });
    await page.close();
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
