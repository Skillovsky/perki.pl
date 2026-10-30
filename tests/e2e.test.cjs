// E2E w Chromium: rezerwacja end-to-end, walidacja, kalendarz, responsywnosc, a11y (axe WCAG 2.2 AA).
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const { startServer } = require('./_server.cjs');

const ART = path.join(__dirname, 'artifacts');
fs.mkdirSync(ART, { recursive: true });
let S, browser;
test.before(async () => { S = await startServer(8792); browser = await chromium.launch(); });
test.after(async () => { await browser.close(); S.stop(); });

async function open(opts = {}) {
  const ctx = await browser.newContext({ locale: 'pl-PL', timezoneId: 'Europe/Warsaw', viewport: { width: 1280, height: 900 }, ...opts });
  const page = await ctx.newPage();
  const problems = [];
  page.on('console', m => { if (m.type() === 'error') problems.push('console: ' + m.text()); });
  page.on('pageerror', e => problems.push('pageerror: ' + e.message));
  page.on('response', r => { if (r.status() >= 400 && !r.url().includes('/wp-json/')) problems.push(`${r.status()} ${r.url()}`); });
  await page.goto(S.base + '/lekcje-nowy/', { waitUntil: 'networkidle' });
  return { ctx, page, problems };
}

async function pickFirstSlot(page) {
  await page.locator('#booking').scrollIntoViewIfNeeded();
  for (let i = 0; i < 3; i++) {
    const day = page.locator('#cal button:not(:disabled)').first();
    if (await day.count()) { await day.click(); break; }
    await page.click('#calNext');
  }
  await page.locator('#slotRow button:not(:disabled)').first().click();
}

test('rezerwacja end-to-end z cennika: karnet 8, online, 60 min', async () => {
  await S.reset();
  const { ctx, page, problems } = await open();
  await page.click('[data-plan="karnet8"] [data-produkt]');
  assert.equal(await page.inputValue('#bfProd'), 'karnet8');
  await page.check('input[name=forma][value=online]', { force: true });
  await page.check('input[name=len][value="60"]', { force: true });
  assert.equal(await page.isVisible('#lenHint'), true);
  await pickFirstSlot(page);
  assert.match(await page.textContent('#pick'), /Online \(Google Meet\), 60 min/);
  await page.fill('#bfName', 'Kasia');
  await page.fill('#bfEmail', 'kasia@example.com');
  await page.fill('#bfMsg', 'Córka 9 lat');
  await page.click('#bookCta');
  await page.waitForSelector('.done');
  assert.match(await page.textContent('.done'), /Dzięki, Kasia! Prośba dotarła\./);
  assert.match(await page.textContent('.done'), /Karnet 8 lekcji/);
  assert.equal(await page.evaluate(() => document.activeElement.id), 'doneH', 'fokus na potwierdzeniu');
  const st = await S.state();
  assert.equal(st.posts.length, 1);
  const m = Object.values(st.meta)[0];
  assert.equal(m._pl_forma, 'Online (Google Meet)');
  assert.match(m._pl_termin_iso, /^\d{4}-\d{2}-\d{2}T\d{2}:00$/);
  assert.equal(m._pl_wiadomosc, 'Córka 9 lat');
  assert.equal(st.mails.length, 2);
  await page.screenshot({ path: path.join(ART, 'booking-success.png') });
  assert.deepEqual(problems, []);
  await ctx.close();
});

test('walidacja: pusty formularz pokazuje bledy przy polach i podsumowanie, nic nie wysyla', async () => {
  await S.reset();
  const { ctx, page } = await open();
  await page.click('#bookCta');
  assert.equal(await page.isVisible('#errSum'), true);
  assert.equal(await page.getAttribute('#bfName', 'aria-invalid'), 'true');
  assert.equal(await page.getAttribute('#bfEmail', 'aria-invalid'), 'true');
  assert.match(await page.textContent('#termErr'), /Wybierz dzień i godzinę/);
  assert.equal(await page.evaluate(() => document.activeElement.id), 'errSum');
  await page.fill('#bfEmail', 'zly');
  await page.fill('#bfName', 'A');
  await page.click('#bookCta');
  assert.match(await page.textContent('#bfEmailErr'), /poprawny e-mail/);
  assert.equal((await S.state()).posts.length, 0);
  await ctx.close();
});

test('blad serwera jest pokazany uczciwie, formularz zachowuje dane (WCAG 3.3.7)', async () => {
  await S.reset();
  const { ctx, page } = await open();
  await page.route('**/wp-json/perki/v1/prosba', r => r.fulfill({ status: 429, contentType: 'application/json', body: JSON.stringify({ code: 'pl_throttle', message: 'Za dużo prób. Spróbuj ponownie za chwilę albo zadzwoń.', data: { status: 429, field: '' } }) }));
  await pickFirstSlot(page);
  await page.fill('#bfName', 'Jan'); await page.fill('#bfEmail', 'jan@example.com');
  await page.click('#bookCta');
  await page.waitForFunction(() => document.getElementById('errSum').classList.contains('on'));
  assert.match(await page.textContent('#errSum'), /Za dużo prób/);
  assert.equal(await page.inputValue('#bfName'), 'Jan');
  assert.equal(await page.isEnabled('#bookCta'), true);
  await ctx.close();
});

test('voucher: wysylka bez terminu', async () => {
  await S.reset();
  const { ctx, page } = await open();
  await page.click('.price-note [data-produkt="voucher"]');
  assert.equal(await page.isVisible('#termOpt'), true);
  await page.fill('#bfName', 'Piotr'); await page.fill('#bfEmail', 'piotr@example.com');
  await page.click('#bookCta');
  await page.waitForSelector('.done');
  assert.match(await page.textContent('.done'), /Voucher/);
  assert.doesNotMatch(await page.textContent('.done'), /45 min/);
  await ctx.close();
});

test('przegladarka w innej strefie (Nowy Jork): termin z UI przechodzi walidacje serwera (Warszawa)', async () => {
  await S.reset();
  const { ctx, page } = await open({ timezoneId: 'America/New_York' });
  await pickFirstSlot(page);
  await page.fill('#bfName', 'Tom'); await page.fill('#bfEmail', 'tom@example.com');
  await page.click('#bookCta');
  await page.waitForSelector('.done', { timeout: 5000 });
  await ctx.close();
});

test('odrzucony nonce: jeden automatyczny ponowny ping i sukces', async () => {
  await S.reset();
  const { ctx, page } = await open();
  let pings = 0;
  await page.route('**/wp-json/perki/v1/lekcja-ping*', async r => {
    pings++;
    if (pings === 1) return r.fulfill({ status: 200, contentType: 'application/json', body: '{"nonce":"przeterminowany"}' });
    return r.continue();
  });
  await pickFirstSlot(page);
  await page.fill('#bfName', 'Ewa'); await page.fill('#bfEmail', 'ewa@example.com');
  await page.click('#bookCta');
  await page.waitForSelector('.done', { timeout: 5000 });
  assert.equal(pings, 2);
  await ctx.close();
});

test('voucher ukrywa pola lekcji (forma, dlugosc) i pokazuje wskazowke', async () => {
  const { ctx, page } = await open();
  await page.selectOption('#bfProd', 'voucher');
  assert.equal(await page.isVisible('#voucherHint'), true);
  assert.equal(await page.isVisible('input[name=len]'), false);
  await page.selectOption('#bfProd', 'lekcja');
  assert.equal(await page.isVisible('#voucherHint'), false);
  await ctx.close();
});

test('kalendarz: niedziele i przeszlosc zablokowane, nawigacja miesiecy w horyzoncie', async () => {
  const { ctx, page } = await open();
  assert.equal(await page.isDisabled('#calPrev'), true);
  const labels = await page.$$eval('#cal button', bs => bs.map(b => [b.getAttribute('aria-label'), b.disabled]));
  for (const [l, dis] of labels) if (/^niedziela/.test(l)) assert.equal(dis, true, l);
  const m0 = await page.textContent('#monthLabel');
  await page.click('#calNext');
  assert.notEqual(await page.textContent('#monthLabel'), m0);
  assert.equal(await page.isEnabled('#calPrev'), true);
  let guard = 0; while (await page.isEnabled('#calNext') && guard++ < 6) await page.click('#calNext');
  assert.ok(guard <= 3, 'horyzont 60 dni = maks. 3 przejscia');
  await ctx.close();
});

test('stary termin dzis po godzinie granicznej jest zablokowany (min. wyprzedzenie)', async () => {
  const { ctx, page } = await open();
  const r = await page.evaluate(() => {
    const t = document.querySelector('#cal button[aria-current=date]');
    return t ? { disabled: t.disabled } : null;
  });
  if (r && !r.disabled) {
    await page.click('#cal button[aria-current=date]');
    const enabled = await page.$$eval('#slotRow button:not(:disabled)', bs => bs.map(b => parseInt(b.textContent, 10)));
    const minH = new Date(Date.now() + 12 * 3600e3);
    for (const h of enabled) assert.ok(h >= minH.getHours() || minH.getDate() !== new Date().getDate(), 'godzina ' + h);
  }
  await ctx.close();
});

for (const [w, h] of [[320, 640], [375, 812], [768, 1024], [1024, 768], [1440, 900]]) {
  test(`responsywnosc ${w}px: brak poziomego scrolla, zrzut ekranu`, async () => {
    const { ctx, page, problems } = await open({ viewport: { width: w, height: h }, isMobile: w < 800, hasTouch: w < 800 });
    const sw = await page.evaluate(() => document.documentElement.scrollWidth);
    assert.ok(sw <= w, `scrollWidth ${sw} > ${w}`);
    const small = await page.$$eval('a,button,input,select,textarea,summary', els => els.filter(e => {
      const r = e.getBoundingClientRect(); if (!r.width || !r.height) return false;
      const cs = getComputedStyle(e); if (cs.visibility === 'hidden' || e.closest('[hidden],.hp,.sr')) return false;
      if (e.matches('a') && e.closest('p,li,address,.reviews-note,.price-note')) return false; // linki w tekscie: wyjatek 2.5.8
      return r.width < 24 || r.height < 24;
    }).map(e => e.outerHTML.slice(0, 80)));
    assert.deepEqual(small, [], 'cele dotyku < 24px (WCAG 2.5.8)');
    await page.screenshot({ path: path.join(ART, `full-${w}.png`), fullPage: true });
    assert.deepEqual(problems, []);
    await ctx.close();
  });
}

test('menu mobilne: otwiera sie, zamyka po kliknieciu linku i Escape', async () => {
  const { ctx, page } = await open({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true });
  await page.click('.burger');
  assert.equal(await page.getAttribute('.burger', 'aria-expanded'), 'true');
  assert.equal(await page.isVisible('#mnav'), true);
  await page.click('#mnav a[href="#cennik"]');
  assert.equal(await page.getAttribute('.burger', 'aria-expanded'), 'false');
  await page.click('.burger'); await page.keyboard.press('Escape');
  assert.equal(await page.isVisible('#mnav'), false);
  await ctx.close();
});

test('axe-core: zero naruszen WCAG 2.2 A/AA (desktop i mobile, takze stan bledow)', async () => {
  const axe = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
  for (const vp of [{ width: 1280, height: 900 }, { width: 375, height: 812 }]) {
    const { ctx, page } = await open({ viewport: vp, reducedMotion: 'reduce' });
    await page.click('#bookCta'); // stan z bledami tez musi byc dostepny
    await page.addScriptTag({ content: axe });
    const res = await page.evaluate(() => axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] }));
    const v = res.violations.map(x => `${x.id}: ${x.nodes.slice(0, 3).map(n => n.target.join(' ')).join(' | ')}`);
    assert.deepEqual(v, [], `viewport ${vp.width}`);
    await ctx.close();
  }
});

test('wydajnosc (lab): LCP, CLS, waga strony', async () => {
  const { ctx, page } = await open({ viewport: { width: 375, height: 812 }, isMobile: true });
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: 1.6e6 / 8, uploadThroughput: 750e3 / 8 });
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
  let bytes = 0; page.on('response', async r => { try { bytes += (await r.body()).length; } catch (e) {} });
  await page.goto(S.base + '/lekcje-nowy/?cold=1', { waitUntil: 'load' });
  const m = await page.evaluate(() => new Promise(res => {
    let lcp = 0, cls = 0;
    new PerformanceObserver(l => l.getEntries().forEach(e => { lcp = e.startTime; })).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver(l => l.getEntries().forEach(e => { if (!e.hadRecentInput) cls += e.value; })).observe({ type: 'layout-shift', buffered: true });
    setTimeout(() => res({ lcp, cls }), 2500);
  }));
  fs.writeFileSync(path.join(ART, 'perf.json'), JSON.stringify({ ...m, bytes }, null, 2));
  assert.ok(m.lcp < 2500, `LCP ${Math.round(m.lcp)} ms (Slow 4G, CPU x4)`);
  assert.ok(m.cls < 0.1, `CLS ${m.cls}`);
  assert.ok(bytes < 400 * 1024, `waga ${Math.round(bytes / 1024)} KB`);
  await ctx.close();
});
