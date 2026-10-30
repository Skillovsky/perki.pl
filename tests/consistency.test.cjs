// Straznik DRY: business.json (dane maszynowe) zgodny z widoczna trescia landing.html.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const DIR = path.resolve(__dirname, '../wp-plugins/perki-lekcje-jasny');
const html = fs.readFileSync(path.join(DIR, 'landing.html'), 'utf8');
const biz = JSON.parse(fs.readFileSync(path.join(DIR, 'business.json'), 'utf8'));
const php = fs.readFileSync(path.join(DIR, 'perki-lekcje-jasny.php'), 'utf8');
const pl = n => (Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ','));

test('ceny w cenniku i w formularzu = business.json', () => {
  for (const o of biz.oferta) {
    const opt = html.match(new RegExp(`<option value="${o.id}">([^<]+)</option>`));
    assert.ok(opt, `brak opcji ${o.id} w formularzu`);
    assert.ok(opt[1].includes(`${o.cena} zł`), `opcja ${o.id}: "${opt[1]}" nie zawiera ${o.cena} zł`);
    if (o.id === 'voucher') continue;
    const card = html.match(new RegExp(`data-plan="${o.id}" data-cena="(\\d+)"[\\s\\S]*?</div>\\s*</div>`));
    assert.ok(card, `brak karty cennika ${o.id}`);
    assert.equal(Number(card[1]), o.cena, `data-cena ${o.id}`);
    assert.ok(card[0].includes(pl(o.cena / o.lekcje)), `karta ${o.id}: brak ceny za lekcje ${pl(o.cena / o.lekcje)}`);
    if (o.lekcje > 1) {
      const single = biz.oferta.find(x => x.id === 'lekcja').cena;
      assert.ok(card[0].includes(`Oszczędzasz ${single * o.lekcje - o.cena} zł`), `karta ${o.id}: zla kwota oszczednosci`);
    }
  }
});

test('FAQ z cenami zgodne z business.json', () => {
  const faq = html.match(/<section id="faq"[\s\S]*?<\/section>/)[0];
  for (const o of biz.oferta.filter(x => x.lekcje > 1)) assert.ok(faq.includes(`${o.cena} zł`), `FAQ: brak ${o.cena} zł`);
});

test('telefon, e-mail i adresy w HTML = business.json', () => {
  assert.ok(html.includes(`tel:${biz.telefon}`));
  assert.ok(html.includes(biz.telefon_wyswietlany));
  assert.ok(html.includes(biz.email));
  for (const l of biz.lokalizacje) assert.ok(html.includes(l.ulica), l.ulica);
  assert.ok(html.includes(`${biz.zasada_zmiany_terminu_h} h przed`));
});

test('canonical i og:url wskazuja SLUG z wtyczki', () => {
  const slug = php.match(/const SLUG\s*=\s*'([^']+)'/)[1];
  assert.ok(html.includes(`<link rel="canonical" href="https://perki.pl/${slug}/">`));
  assert.ok(html.includes(`<meta property="og:url" content="https://perki.pl/${slug}/">`));
});

test('znacznik wstrzykiwania HEAD jest w <head>', () => {
  const head = html.slice(0, html.indexOf('</head>'));
  assert.ok(head.includes('<!--PERKI:HEAD-->'));
});

test('bez em/en-dashy w tresciach (zasada projektu)', () => {
  for (const [name, t] of [['landing.html', html], ['business.json', JSON.stringify(biz)], ['php', php]]) {
    assert.ok(!/[–—]/.test(t), `${name} zawiera em/en-dash`);
  }
});

test('kazdy lokalny zasob z landingu istnieje na dysku', () => {
  const refs = new Set([...html.matchAll(/\/wp-content\/plugins\/perki-lekcje-jasny\/(assets\/[^\s"')]+)/g)].map(m => m[1]));
  assert.ok(refs.size >= 10);
  for (const r of refs) assert.ok(fs.existsSync(path.join(DIR, r)), `brak pliku ${r}`);
  assert.ok(fs.existsSync(path.join(DIR, biz.obraz.replace(/^.*\/perki-lekcje-jasny\//, ''))), 'obraz OG z business.json');
});

test('godziny rezerwacji i dlugosci z business.json maja sens', () => {
  const r = biz.rezerwacja;
  assert.ok(r.godzina_od < r.godzina_do && r.godzina_od >= 0 && r.godzina_do <= 23);
  assert.ok(r.dni_tygodnia.every(d => d >= 1 && d <= 7));
  for (const len of r.dlugosci_min) assert.ok(html.includes(`name="len" value="${len}"`), `brak opcji dlugosci ${len}`);
  const offered = [...html.matchAll(/name="len" value="(\d+)"/g)].map(m => +m[1]);
  assert.deepEqual(offered, r.dlugosci_min, 'formularz = business.json');
  for (const m of html.matchAll(/(\d+) min/g)) assert.ok(r.dlugosci_min.includes(+m[1]), `tekst obiecuje ${m[0]}, ktorej nie da sie zarezerwowac`);
});
