#!/usr/bin/env bash
# Szybki audyt produkcji perki.pl po wdrozeniu (curl, bez zaleznosci).
# Uzycie: tools/audit-live.sh [https://perki.pl]
# Sprawdza: statusy i czasy, cache LiteSpeed (hit przy 2. zadaniu), kompresje, naglowki,
# JSON-LD, llms.txt, robots.txt (boty AI + sitemap), sitemap, endpoint ping, zasoby wtyczki.
set -uo pipefail
B="${1:-https://perki.pl}"
P="/wp-content/plugins/perki-lekcje-jasny/assets"
fail=0
ok(){ printf '  OK   %s\n' "$*"; }
bad(){ printf '  FAIL %s\n' "$*"; fail=$((fail+1)); }
hdr(){ curl -s -o /dev/null -D - -H 'Accept-Encoding: br, gzip' "$1" | tr -d '\r'; }

echo "== Strony (status, TTFB, rozmiar)"
for u in / /lekcje-nowy/ /gra/ /twoj-profil-instruktora/ /llms.txt /robots.txt /perki-sitemap.xml; do
  r=$(curl -s -o /dev/null -w '%{http_code} %{time_starttransfer} %{size_download}' "$B$u")
  set -- $r; [ "$1" = 200 ] && ok "$u $1 TTFB ${2}s ${3}B" || bad "$u -> $1"
done

echo "== Landing: cache LiteSpeed i naglowki"
hdr "$B/lekcje-nowy/" >/dev/null
h=$(hdr "$B/lekcje-nowy/")
grep -qi '^x-litespeed-cache: hit' <<<"$h" && ok "x-litespeed-cache: hit" || bad "brak cache hit (sprawdz LSCache dla /lekcje-nowy/)"
grep -qiE '^content-encoding: (br|gzip)' <<<"$h" && ok "kompresja" || bad "brak kompresji br/gzip"
grep -qi '^x-perki-headless: 1' <<<"$h" && ok "headless" || bad "brak X-Perki-Headless (wtyczka nieaktywna?)"
grep -qi '^x-content-type-options: nosniff' <<<"$h" && ok "nosniff" || bad "brak nosniff"

echo "== Landing: tresc"
html=$(curl -s "$B/lekcje-nowy/")
grep -q 'application/ld+json' <<<"$html" && ok "JSON-LD obecny" || bad "brak JSON-LD"
grep -q 'window.PERKI_CFG' <<<"$html" && ok "konfiguracja formularza" || bad "brak PERKI_CFG"
grep -q 'PERKI:HEAD' <<<"$html" && bad "znacznik HEAD niepodmieniony" || ok "znacznik podmieniony"
grep -q 'fonts.googleapis.com' <<<"$html" && bad "zewnetrzne Google Fonts" || ok "fonty lokalne"
grep -q 'id="bookForm"' <<<"$html" && ok "formularz 1.2.x" || bad "stary formularz (cache? wersja?)"

echo "== Zasoby wtyczki"
for f in fonts/inter-pl.woff2 fonts/bricolage-pl.woff2 img/perki-kit-960.avif img/perki-kit-960.webp img/og-perki-lekcje.jpg img/favicon.svg; do
  s=$(curl -s -o /dev/null -w '%{http_code} %{size_download}' "$B$P/$f"); set -- $s
  [ "$1" = 200 ] && [ "$2" -gt 300 ] && ok "$f ${2}B" || bad "$f -> $1 ${2}B"
done

echo "== robots.txt / llms.txt / sitemap"
rb=$(curl -s "$B/robots.txt")
for ua in OAI-SearchBot Claude-SearchBot PerplexityBot; do grep -q "User-agent: $ua" <<<"$rb" && ok "robots: $ua" || bad "robots: brak $ua (fizyczny robots.txt albo wtyczka SEO nadpisuje?)"; done
grep -q 'perki-sitemap.xml' <<<"$rb" && ok "robots: sitemap" || bad "robots: brak perki-sitemap.xml"
curl -s "$B/llms.txt" | head -1 | grep -q '^# perki.pl' && ok "llms.txt nasz" || bad "llms.txt obcy lub brak"
curl -s "$B/perki-sitemap.xml" | grep -q '<loc>' && ok "sitemap" || bad "sitemap"

echo "== REST rezerwacji (bez wysylania prosby)"
for ep in lekcja-ping gra-ping; do
  curl -s -o /dev/null "$B/wp-json/perki/v1/$ep"   # 1. zadanie moze zapelnic cache...
  p=$(curl -s -D - "$B/wp-json/perki/v1/$ep" | tr -d '\r')   # ...2. nie moze byc z cache
  grep -q '"nonce"' <<<"$p" && ok "$ep zwraca nonce" || bad "$ep (WAF?)"
  grep -qi '^x-litespeed-cache: hit' <<<"$p" && bad "$ep z cache LiteSpeed: nonce wspolny, po 24 h formularz padnie" || ok "$ep nie z cache"
done
c=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' "$B/wp-json/perki/v1/prosba")
[ "$c" = 403 ] && ok "POST bez nonce -> 403" || bad "POST bez nonce -> $c"

echo "== Boty AI (WAF nie powinien ich blokowac)"
for ua in 'OAI-SearchBot/1.0; +https://openai.com/searchbot' 'Claude-SearchBot' 'PerplexityBot/1.0'; do
  c=$(curl -s -o /dev/null -w '%{http_code}' -A "Mozilla/5.0 (compatible; $ua)" "$B/lekcje-nowy/")
  [ "$c" = 200 ] && ok "UA $ua -> 200" || bad "UA $ua -> $c (WAF?)"
done

echo; [ $fail -eq 0 ] && echo "WYNIK: wszystko OK" || echo "WYNIK: $fail problem(y)"
exit $fail
