# Wdrozenie jasnego landingu lekcji na perki.pl (2026-06-10)

## Co wdrozono
- Wtyczka `perki-lekcje-jasny` 1.1.1 (aktywna). Landing LIVE: https://perki.pl/lekcje-nowy/
- Serwowany HEADLESS: rewrite /lekcje-nowy/ -> readfile(landing.html) + exit, z
  CZYSZCZENIEM buforow wyjscia (`while(ob_get_level()) ob_end_clean()`), wiec zaden
  plugin (lazyload, placeholdery obrazow, Autoptimize) nie dotyka HTML. Naglowek
  kontrolny: `X-Perki-Headless: 1`. Zero motywu, zero Elementora, 0 obcych skryptow.
- Endpointy: GET /wp-json/perki/v1/lekcja-ping (nonce), POST /wp-json/perki/v1/prosba
  (CPT `prosba_lekcja` -> kokpit "Prosby o lekcje" + mail do admina; nonce-z-GET,
  honeypot, throttle, JSON-only, credentials omit).

## System projektowy "Studio Groove" (z researchu liderow)
- Paleta w :root: --bg #FFFCF6, --accent #F2641E (blacha), --trust #15324B, --gold,
  surfaces/borders/states. Akcent oszczednie (CTA/badge/numery).
- Fonty: Bricolage Grotesque (700/800) + Inter (400/500/600).
- Sekcje: hero 7/5, pasek liczb, nauczyciel+odznaki, dla kogo, 3 kroki, cennik
  (Karnet 8 wyrozniony, cena/lekcje + przekreslona baza + "Oszczedzasz"), gwarancja,
  opinie (placeholdery), booking (kalendarz+godziny+forma jako PROSBA o termin),
  FAQ (<details>), finalne CTA, granatowa stopka.
- Lekcja 45 min. ZERO "bez zobowiazan" (sprzeczne z regula 24h) -> "zaczynasz od
  jednej lekcji" / "bez karnetu na start".
- Mikrointerakcje (beat CTA, scroll-reveal, liczniki) z prefers-reduced-motion.
  REVEAL = progresywne ulepszanie: tresc nad ekranem widoczna OD RAZU (klasa .pre
  tylko ponizej ekranu), wiec brak mignięcia pustki gdy JS odroczony.

## Pulapki/decyzje wdrozeniowe (potwierdzone)
- Zip wtyczki: tym razem landing.html NIE wyzerowal sie (54 KB w edytorze). Mimo to
  najlepsza i sprawdzona sciezka: commit+push -> w edytorze wtyczki fetch z
  raw.githubusercontent.com (CORS *) -> setValue -> zapis. Pasta recznego base64 do
  JS jest zawodna (ucinanie); jesli trzeba, zip transportujemy przez commit do repo
  i fetch w przegladarce (potem usuwamy plik z repo).
- Obrazy: bez czyszczenia buforow plugin optymalizujacy podmienial hero na
  placeholder 1x1 (naturalWidth=1). Czyszczenie buforow rozwiazalo (full 1600x984).
- Po kazdej zmianie tresci: bump stalej VERSION (auto purge) + LiteSpeed Purge All.

## Testy (wykonane 2026-06-10)
- [x] /lekcje-nowy/ 200, 1.2 s, headless, hero+cennik+booking render, foto 1600x984
- [x] Endpoint prosby: ping->nonce->POST = {"ok":true,"id":...}; bez nonce 403; bez JSON 415
- [x] Booking front-end: kalendarz (dni robocze), godziny 12-20, formularz prosby
- [x] Mobile sticky CTA, reveal nie chowa tresci nad ekranem
- [x] /twoj-profil-instruktora/ 200, /gra/ 200 (nietkniete)

## Co zostalo (kolejne moduly)
1. Produkty WooCommerce (lekcja/karnety/vouchery) + Stripe checkout = platnosc potwierdza termin.
2. Kalendarz Google: wpis po zaplacie (OAuth jednorazowy - WYMAGA autoryzacji Jacke).
3. Reschedule do 24h przed (link tokenowy).
4. Link "Lekcje" w glownej nawigacji -> /lekcje-nowy/ (decyzja: czy zastapic stary ciemny /lekcje).
5. Jacke: prawdziwe opinie uczniow (teraz placeholdery), ew. liczba lekcji / ocena Google do paska dowodu.
6. Usunac testowe wpisy "Prosby o lekcje" i "Wyniki gry" z kokpitu.
