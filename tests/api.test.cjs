// Testy REST + tekstowych endpointow: prawdziwy kod wtyczki (PHP) na stubie WordPressa.
const test = require('node:test');
const assert = require('node:assert/strict');
const { startServer } = require('./_server.cjs');

let S;
test.before(async () => { S = await startServer(8791); });
test.after(() => S.stop());
test.beforeEach(() => S.reset());

// Daty liczone w strefie Europe/Warsaw (jak serwer).
function warsawDate(offsetDays, wantDow) {
  const d = new Date(Date.now() + offsetDays * 864e5);
  for (let i = 0; i < 8; i++) {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Warsaw', year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'short' }).formatToParts(d);
    const g = t => parts.find(p => p.type === t).value;
    const dow = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 }[g('weekday')];
    if (!wantDow || wantDow(dow)) return `${g('year')}-${g('month')}-${g('day')}`;
    d.setTime(d.getTime() + 864e5);
  }
}
const workday = (off = 3) => warsawDate(off, d => d <= 6);
const sunday = () => warsawDate(2, d => d === 7);

async function nonce() { return (await (await fetch(S.base + '/wp-json/perki/v1/lekcja-ping')).json()).nonce; }
async function post(body, { n, ctype = 'application/json', raw } = {}) {
  const headers = { 'Content-Type': ctype };
  if (n !== null) headers['X-WP-Nonce'] = n || await nonce();
  const r = await fetch(S.base + '/wp-json/perki/v1/prosba', { method: 'POST', headers, body: raw ?? JSON.stringify(body) });
  return { status: r.status, j: await r.json() };
}
const OK = () => ({ name: 'Ola', email: 'ola@example.com', phone: '+48 600 100 200', produkt: 'karnet8', len: 60, forma: 'online', data: workday(), godzina: '15:00', wiadomosc: 'Gram 2 lata', _hp: '' });

test('ping: nonce + Cache-Control no-store', async () => {
  const r = await fetch(S.base + '/wp-json/perki/v1/lekcja-ping');
  assert.equal(r.status, 200);
  assert.match(r.headers.get('cache-control'), /no-store/);
  assert.ok((await r.json()).nonce);
});

test('poprawna prosba: zapis CPT, meta ISO, 2 maile z Reply-To', async () => {
  const { status, j } = await post(OK());
  assert.equal(status, 200, JSON.stringify(j));
  assert.equal(j.ok, true);
  assert.ok(j.id >= 1000);
  assert.match(j.termin, /, 15:00$/);
  const st = await S.state();
  const m = st.meta[j.id];
  assert.equal(m._pl_produkt, 'Karnet 8 lekcji');
  assert.equal(m._pl_forma, 'Online (Google Meet)');
  assert.equal(m._pl_len, 60);
  assert.equal(m._pl_termin_iso, OK().data + 'T15:00');
  assert.equal(st.mails.length, 2);
  const [admin, student] = st.mails;
  assert.equal(admin.to, 'admin@perki.test');
  assert.ok(admin.headers.includes('Reply-To: Ola <ola@example.com>'));
  assert.match(admin.body, /Wiadomość: Gram 2 lata/);
  assert.ok(!/IP:/.test(admin.body), 'bez IP w mailu (RODO)');
  assert.equal(m._pl_ip, undefined, 'bez IP w meta');
  assert.equal(student.to, 'ola@example.com');
  assert.ok(!student.body.includes('Ola') && !student.body.includes('Gram 2 lata'), 'zero tekstu od nadawcy w mailu do adresata (antyspam)');
  assert.match(student.body, /Pakiet: Karnet 8 lekcji\nDługość: 60 min\nPreferowany termin: .+, 15:00\nForma: Online/);
  assert.ok(student.headers.some(h => h.startsWith('Reply-To: perki.pl <jackeperki@gmail.com>')));
  assert.match(student.body, /link do płatności/);
});

test('walidacja terminu: przeszlosc, niedziela, za daleko, zla godzina, brak terminu', async () => {
  const cases = [
    [{ data: warsawDate(-1) }, 'pl_past'],
    [{ data: sunday() }, 'pl_dow'],
    [{ data: workday(200) }, 'pl_far'],
    [{ godzina: '23:00' }, 'pl_hour'],
    [{ godzina: '9:00' }, 'pl_hour'],
    [{ data: '2026-02-31' }, 'pl_date'],
    [{ data: '', godzina: '' }, 'pl_term'],
    [{ godzina: 'abc' }, 'pl_time'],
  ];
  for (const [patch, code] of cases) {
    const { status, j } = await post({ ...OK(), ...patch });
    assert.equal(status, 400, `${JSON.stringify(patch)} -> ${JSON.stringify(j)}`);
    assert.equal(j.code, code, JSON.stringify(patch));
    assert.ok(j.message && j.data.field, 'komunikat i pole dla frontu');
  }
  assert.equal((await S.state()).posts.length, 0, 'nic nie zapisano');
});

test('voucher bez terminu (takze sam dzien bez godziny) jest OK, bez formy/dlugosci', async () => {
  const { status, j } = await post({ ...OK(), produkt: 'voucher', data: '', godzina: '' });
  assert.equal(status, 200, JSON.stringify(j));
  const r2 = await post({ ...OK(), email: 'inny@example.com', produkt: 'voucher', godzina: '' });
  assert.equal(r2.status, 200, JSON.stringify(r2.j));
  const st = await S.state();
  assert.equal(st.meta[j.id]._pl_forma, '');
  assert.equal(st.meta[j.id]._pl_len, 0);
  assert.match(st.mails[1].body, /w sprawie vouchera/);
});

test('potwierdzenia do ucznia: max 1 na adres na dobe (antyspam), prosby dalej zapisywane', async () => {
  for (let i = 0; i < 3; i++) assert.equal((await post(OK())).status, 200);
  const st = await S.state();
  assert.equal(st.posts.length, 3);
  assert.equal(st.mails.filter(m => m.to === 'ola@example.com').length, 1);
  assert.equal(st.mails.filter(m => m.to === 'admin@perki.test').length, 3);
});

test('ping: sygnal no-cache dla LiteSpeed (inaczej wspolny, przeterminowany nonce)', async () => {
  const r = await fetch(S.base + '/wp-json/perki/v1/lekcja-ping?t=1');
  assert.equal(r.headers.get('x-litespeed-cache-control'), 'no-cache');
  assert.ok((await S.state()).actions.includes('litespeed_control_set_nocache'));
});

test('uszkodzony business.json: rezerwacje dzialaja na regulach awaryjnych', async () => {
  const fs = require('node:fs'), path = require('node:path');
  const f = path.resolve(__dirname, '../wp-plugins/perki-lekcje-jasny/business.json');
  const orig = fs.readFileSync(f, 'utf8');
  try {
    fs.writeFileSync(f, orig.replace('"nazwa"', ',"nazwa"'));
    const { status, j } = await post(OK());
    assert.equal(status, 200, JSON.stringify(j));
    const html = await (await fetch(S.base + '/lekcje-nowy/')).text();
    assert.match(html, /"rezerwacja":\{"dni_tygodnia":\[1,2,3,4,5,6\],"godzina_od":12/);
    assert.equal((await post({ ...OK(), data: sunday() })).j.code, 'pl_dow', 'walidacja dalej dziala');
  } finally { fs.writeFileSync(f, orig); }
});

test('zgodnosc wsteczna: stary front (tekstowa data, nazwa produktu) nie gubi leada', async () => {
  const { status, j } = await post({ name: 'Jan', email: 'jan@example.com', phone: '', produkt: 'Karnet 8 lekcji', len: 45, data: 'piątek, 6 listopada', godzina: '14:00', forma: '', _hp: '' });
  assert.equal(status, 200, JSON.stringify(j));
  const m = (await S.state()).meta[j.id];
  assert.equal(m._pl_data, 'piątek, 6 listopada 14:00');
  const [admin, student] = (await S.state()).mails;
  assert.match(admin.body, /Preferowany termin: piątek, 6 listopada 14:00\n/, 'godzina nie powtorzona');
  assert.match(student.body, /Preferowany termin: do ustalenia/, 'niezwalidowany tekst nie trafia do adresata');
  assert.equal(m._pl_produkt, 'Karnet 8 lekcji');
});

test('dane kontaktowe: imie, e-mail, telefon', async () => {
  assert.equal((await post({ ...OK(), name: '' })).j.code, 'pl_name');
  assert.equal((await post({ ...OK(), email: 'zly@' })).j.code, 'pl_email');
  assert.equal((await post({ ...OK(), phone: 'zadzwon<script>' })).j.code, 'pl_phone');
});

test('nieznane wartosci forma/len sa neutralizowane, nie psuja zapisu', async () => {
  const { status, j } = await post({ ...OK(), forma: 'kosmos', len: 999 });
  assert.equal(status, 200);
  const m = (await S.state()).meta[j.id];
  assert.equal(m._pl_forma, '');
  assert.equal(m._pl_len, 0);
});

test('ochrony: nonce 403, Content-Type 415, payload 413, honeypot cichy', async () => {
  assert.equal((await post(OK(), { n: null })).status, 403);
  assert.equal((await post(OK(), { n: 'zly' })).status, 403);
  assert.equal((await post(OK(), { ctype: 'text/plain' })).status, 415);
  assert.equal((await post(null, { raw: JSON.stringify({ ...OK(), wiadomosc: 'x'.repeat(9000) }) })).status, 413);
  const hp = await post({ ...OK(), _hp: 'bot' });
  assert.deepEqual([hp.status, hp.j.id], [200, 0]);
  const st = await S.state();
  assert.equal(st.posts.length, 0);
  assert.equal(st.mails.length, 0);
});

test('throttle: 21. prosba z IP dostaje 429', async () => {
  const n = await nonce();
  for (let i = 0; i < 20; i++) assert.equal((await post(OK(), { n })).status, 200);
  const r = await post(OK(), { n });
  assert.equal(r.status, 429);
});

test('wstrzykiwanie naglowkow maila przez imie jest niemozliwe', async () => {
  const { status } = await post({ ...OK(), name: 'Ola\r\nBcc: x@evil.test' });
  assert.equal(status, 200);
  const admin = (await S.state()).mails[0];
  assert.ok(admin.headers.every(h => !/[\r\n]/.test(h)));
  assert.ok(!/Bcc:/.test(admin.headers.join('|')), 'brak naglowka Bcc');
});

test('landing: naglowki cache i bezpieczenstwa, JSON-LD i konfiguracja wstrzykniete', async () => {
  const r = await fetch(S.base + '/lekcje-nowy/');
  assert.equal(r.status, 200);
  assert.match(r.headers.get('x-litespeed-cache-control'), /public,max-age=\d+/);
  assert.equal(r.headers.get('x-content-type-options'), 'nosniff');
  const html = await r.text();
  assert.ok(!html.includes('<!--PERKI:HEAD-->'));
  const ld = JSON.parse(html.match(/<script type="application\/ld\+json">(.*?)<\/script>/s)[1]);
  const types = ld['@graph'].flatMap(n => [].concat(n['@type']));
  for (const t of ['LocalBusiness', 'EducationalOrganization', 'Person', 'WebPage', 'FAQPage']) assert.ok(types.includes(t), t);
  const page = ld['@graph'].find(n => [].concat(n['@type']).includes('FAQPage'));
  const visibleQ = [...html.match(/<section id="faq"[\s\S]*?<\/section>/)[0].matchAll(/<summary>/g)].length;
  assert.equal(page.mainEntity.length, visibleQ, 'FAQPage = widoczne FAQ');
  const org = ld['@graph'][0];
  assert.equal(org.hasOfferCatalog.itemListElement.length, 5);
  assert.equal(org.address.length, 2);
  assert.match(html, /window\.PERKI_CFG=\{"api":\{"prosba":"http:\/\/127\.0\.0\.1:8791\/wp-json\/perki\/v1\/prosba"/);
  assert.ok(!/<\/script>/.test(JSON.stringify(ld)), 'JSON-LD bezpieczny');
});

test('llms.txt, robots.txt, perki-sitemap.xml', async () => {
  const l = await fetch(S.base + '/llms.txt');
  assert.equal(l.status, 200);
  assert.match(l.headers.get('content-type'), /text\/plain/);
  const lt = await l.text();
  assert.match(lt, /^# perki\.pl/);
  assert.match(lt, /\n> /);
  assert.match(lt, /Karnet 8 lekcji: 1080 zł \(135 zł za lekcję\)/);
  assert.match(lt, /### Ile kosztuje lekcja perkusji\?/);
  assert.ok(!lt.includes('Jak przetwarzam Twoje dane'), 'RODO to nie FAQ');
  const rb = await (await fetch(S.base + '/robots.txt')).text();
  for (const ua of ['OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot', 'Google-Extended']) assert.ok(rb.includes('User-agent: ' + ua), ua);
  assert.ok(!rb.includes('User-agent: Bingbot'), 'zwykle wyszukiwarki zostaja w grupie *');
  const ai = rb.slice(rb.indexOf('User-agent: OAI-SearchBot'));
  assert.match(ai, /Disallow: \/wp-admin\/\nAllow: \/wp-admin\/admin-ajax\.php/, 'grupa AI dziedziczy reguly z *');
  assert.match(lt, /poniedziałek-sobota, godziny 12:00-20:00/);
  assert.match(rb, /Sitemap: http:\/\/127\.0\.0\.1:8791\/perki-sitemap\.xml/);
  assert.match(rb, /User-agent: \*\nDisallow: \/wp-admin\//, 'oryginal zachowany');
  const sm = await (await fetch(S.base + '/perki-sitemap.xml')).text();
  assert.match(sm, /<loc>http:\/\/127\.0\.0\.1:8791\/lekcje-nowy\/<\/loc><lastmod>\d{4}-\d{2}-\d{2}<\/lastmod>/);
});

test('auto purge LiteSpeed przy zmianie odcisku wersji', async () => {
  await fetch(S.base + '/lekcje-nowy/');
  const st = await S.state();
  assert.ok(st.actions.includes('litespeed_purge_all'));
  assert.match(st.options.perki_lekcje_jasny_ver, /^1\.\d+\.\d+\|\d+\|\d+$/);
  await S.reset(); // opcje wyczyszczone -> znowu purge; bez resetu: brak purge
  await fetch(S.base + '/lekcje-nowy/');
  const before = (await S.state()).actions.filter(a => a === 'litespeed_purge_all').length;
  await fetch(S.base + '/lekcje-nowy/');
  const after = (await S.state()).actions.filter(a => a === 'litespeed_purge_all').length;
  assert.equal(after, before, 'bez zmian plikow nie czyscimy cache przy kazdym zadaniu');
});
