# Wdrozenie perki-lekcje-jasny 1.2.x + hotfix perki-gra 1.1.1 (2026-10-30)

## PILNE (dotyczy tego, co JUZ dziala na produkcji)
Przeglad adwersaryjny wykazal: LiteSpeed Cache domyslnie cache'uje REST API (7 dni).
Endpointy z nonce (`/wp-json/perki/v1/gra-ping` w grze 1.1.0 i `/lekcja-ping`
w landingu 1.1.1) nie wysylaly sygnalu no-cache dla LiteSpeed, wiec wszyscy
dostaja ten sam nonce, a po ok. 24 h zapis wyniku gry / prosba o lekcje konczy sie
403 "Cookie check failed" az do najblizszego Purge All.
- Naprawa gry: wgraj `wp-plugins/perki-gra/perki-gra.php` (1.1.1) przez Edytor
  plikow wtyczki (tylko ten jeden plik; .html gry bez zmian).
- Naprawa landingu: wdrozenie 1.2.1 ponizej.
- Doraznie, do czasu wdrozenia: LiteSpeed Cache > Oproznij wszystko albo
  w ustawieniach LiteSpeed > Cache > "Cache REST API" = OFF.

STAN: kod gotowy i przetestowany (CI zielone), NIEWDROZONY. Sesja, ktora go
przygotowala, nie miala dostepu sieciowego do perki.pl (polityka sieci kontenera),
wiec wdrozenie i audyt produkcji robi Jacke w przegladarce albo kolejna sesja
z perki.pl dopuszczonym w ustawieniach sieci srodowiska.

## Co sie zmienia na produkcji
- /lekcje-nowy/: nowy formularz rezerwacji (kalendarz z miesiacami, forma,
  dlugosc, pakiet, wiadomosc, walidacja, ekran potwierdzenia), uczciwe copy
  (prosba o termin + link do platnosci), sekcja "Gdzie gramy", rozszerzone FAQ.
- Mail do Jacke ma Reply-To = uczen (Odpowiedz trafia do ucznia).
- NOWE: mail potwierdzajacy do ucznia (Reply-To = jackeperki@gmail.com). Antyspam:
  bez zadnego tekstu od nadawcy, max 1 na adres na dobe i 50 dziennie na strone.
- IP nie jest juz zapisywany w prosbach ani wysylany mailem (RODO); throttle na hashu.
- NOWE: /llms.txt, /perki-sitemap.xml, boty AI dopisane do wirtualnego robots.txt.
- NOWE: JSON-LD (LocalBusiness + EducationalOrganization, Person, cennik, FAQPage).
- Fonty i obrazy z katalogu wtyczki (assets/), zero Google Fonts.
- LiteSpeed cache'uje landing (naglowek X-LiteSpeed-Cache-Control) i czysci cache
  sam przy KAZDEJ zmianie landing.html/business.json (takze z Edytora wtyczki).

## Wdrozenie (ok. 5 min)
1. Pobierz zip: GitHub > Actions > "Testy i paczka wtyczki" > ostatni zielony run
   > artefakt `perki-lekcje-jasny-zip` (albo lokalnie: `tools/build-zip.sh`).
2. wp-admin > Wtyczki > Dodaj nowa > Wyslij wtyczke > wybierz zip >
   "Zastap obecna wersja wgrana" (WP pokaze porownanie 1.1.1 -> 1.2.x).
3. Kokpit: jesli wtyczka pokaze czerwony komunikat "brakuje plikow albo sa puste"
   (znana pulapka hostingu: .html wyzerowany przy uploadzie), otworz
   Wtyczki > Edytor plikow wtyczki > Perki Lekcje > landing.html i wklej tresc
   z repo (raw: https://raw.githubusercontent.com/Skillovsky/perki.pl/<galaz>/wp-plugins/perki-lekcje-jasny/landing.html).
4. LiteSpeed Cache > Oproznij wszystko (wtyczka robi to sama, to tylko pewnik).
5. Audyt: `tools/audit-live.sh https://perki.pl` (z maszyny z dostepem) albo recznie:
   - https://perki.pl/lekcje-nowy/ : formularz z kalendarzem, wyslij testowa prosbe
     (sprawdz 2 maile: do Ciebie i do "ucznia"), potem usun wpis w "Prosby o lekcje".
   - https://perki.pl/llms.txt i https://perki.pl/robots.txt (sekcja botow AI).
   - https://search.google.com/test/rich-results i https://validator.schema.org na /lekcje-nowy/.
   - PageSpeed Insights (mobile) na /lekcje-nowy/: cel LCP < 2,5 s, CLS < 0,1.

## Rzeczy do sprawdzenia w kokpicie (zalezne od srodowiska)
- Jesli w katalogu glownym istnieje FIZYCZNY robots.txt lub llms.txt (np. z Yoast
  albo File Managera), ma pierwszenstwo przed wtyczka. Wtedy dopisz recznie sekcje
  botow AI z `perki-lekcje-jasny.php` (stala AI_BOTS) albo usun plik fizyczny.
- WAF: boty AI (OAI-SearchBot, Claude-SearchBot, PerplexityBot) musza dostawac 200.
  audit-live.sh to sprawdza. Jesli 403, dodaj wyjatek w WAF.
- LiteSpeed: /wp-json/perki/v1/lekcja-ping NIE moze byc cache'owany (zwraca no-store;
  audit-live.sh sprawdza). "Drop Query String": utm_*, fbclid, gclid.
- Poczta: wysylka z WordPressa trafia czesto do spamu. Ustaw SPF/DKIM/DMARC dla
  perki.pl albo wtyczke SMTP (np. przez konto Google Workspace/Gmail).
- Polityka prywatnosci: jesli w Ustawieniach > Prywatnosc jest wskazana strona,
  formularz sam do niej linkuje (pod klauzula RODO).

## Do decyzji Jacke (nie zgadywalem)
1. Docelowy adres: /lekcje-nowy/ vs /lekcje/ (stala SLUG + canonical w landing.html;
   test spojnosci pilnuje zgodnosci). Po zmianie: przekierowanie 301 ze starego adresu.
2. Abonament miesieczny (135 zl/lekcja) ZDJETY ze strony: nie dalo sie go zamowic
   ani poznac warunkow. Wroci jako pozycja w business.json + opcja formularza, gdy
   beda warunki (ile lekcji w miesiacu, wypowiedzenie).
3. Prawdziwe opinie uczniow: sekcje z placeholderami USUNIETO (gwiazdki bez opinii =
   ryzyko wprowadzania w blad). Gdy beda 3+ prawdziwe opinie (najlepiej z Google),
   wracaja jako sekcja + AggregateRating w business.json.
4. Zdjecie Jacke przy bebnach do sekcji "Twoj nauczyciel" (teraz zdjecie zestawu).
5. Regulamin + prawo odstapienia (14 dni) dla karnetow kupowanych online: nalezy
   do etapu platnosci (link/WooCommerce), nie do formularza prosby. Szczegoly
   i zrodla: docs/RESEARCH-2026-10-30-booking-legal-a11y.md. Warto przejrzec z prawnikiem.
6. Profil Firmy w Google + Bing Places + Apple Maps dla obu adresow (najwiekszy
   wplyw na widocznosc lokalna i w odpowiedziach AI; NAP identyczny jak w business.json).

## Zmiana danych firmy (DRY)
- Ceny, adresy, telefon, godziny rezerwacji: `business.json` (JSON-LD, llms.txt,
  formularz biora dane stad) + widoczny tekst w `landing.html`.
- `cd tests && npm test` pilnuje, zeby oba miejsca sie zgadzaly (CI robi to przy pushu).
- FAQ: tylko w landing.html (sekcja #faq); FAQPage i llms.txt generuja sie z niego.

## Przeglad adwersaryjny (2026-10-30)
4 perspektywy (rezerwacja, bezpieczenstwo/WordPress, SEO/LLM, UX/copy), kazde
znalezisko weryfikowane przez niezaleznego sceptyka: 21 potwierdzonych, 3 odrzucone.
Wszystkie potwierdzone naprawione w 1.2.1 i pokryte testami (41 testow), m.in.:
nonce bez cache LiteSpeed + automatyczny ponowny ping, kalendarz w czasie Warszawy
niezaleznie od strefy przegladarki, odswiezanie dostepnosci po powrocie do karty,
reguly awaryjne przy uszkodzonym business.json (+ ostrzezenie w kokpicie), robots.txt
z grupa AI dziedziczaca reguly `User-agent: *`, flush rewrite na wp_loaded,
voucher bez pol lekcji, uczciwe teksty (bez "gwarancji", 120 min i abonamentu).
