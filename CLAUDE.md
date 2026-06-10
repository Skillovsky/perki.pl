# CLAUDE.md - Perki Drum Hero / gamifikacja perki.pl

## Czym jest ten projekt
Grywalizowana warstwa edukacyjna dla perki.pl (szkola perkusyjna Jacka, Poznan/Gniezno):
mini-gra perkusyjna w przegladarce (klawiatura/dotyk) + system poziomow, XP i nagrod
odblokowywanych po lekcjach. Gra jest jednoczesnie lead magnetem (darmowa, na landingu)
i narzedziem utrwalania materialu z kursu wideo (deep-link ?level=N po kazdej lekcji).

## Stan na dzis (2026-06-10)
- GRA OPUBLIKOWANA: https://perki.pl/gra/ (deep-link: https://perki.pl/gra/?level=N).
- Zrodlo prawdy gry: `wp-plugins/perki-gra/perki-drum-hero.html` W TYM REPO.
  Kopia w ~/Downloads to archiwum; zmiany robimy tu i republikujemy.
- Wtyczka `wp-plugins/perki-gra/perki-gra.php` (aktywna na produkcji):
  serwuje gre pod /gra/ (rewrite, pelny ekran, bez motywu), REST GET /perki/v1/gra-ping
  (nonce) + POST /perki/v1/wynik (CPT `wynik_gry` + wp_mail, honeypot, throttle per IP,
  limit payloadu, wymagany Content-Type: application/json). Lejek: formularz
  "Zapisz wynik w rankingu" (name+email) na ekranie wynikow.
- Przebieg wdrozenia i pulapki srodowiska: patrz `DEPLOY-NOTES-2026-06-10-gra.md`
  (WAZNE: upload zipa wycina pliki .html do 0 B; tresc gry wgrywa sie przez
  Edytor plikow wtyczki; optymalizator JS przepisuje inline script na data:base64+defer,
  gra na to odporna).
- NOWY KIERUNEK (decyzje Jacke 2026-06-10):
  1. Strona i gra maja byc JASNE, lekkie, nowoczesne (ciemna wersja = tymczasowa).
  2. Perkusja w grze fotorealistyczna jak w VST (EZdrummer): zdjecie zestawu Jacke
     z hotspotami. Jacke robi zdjecie wg `INSTRUKCJA-FOTO-ZESTAWU.md`.
  3. Dzwieki: sample CC0/CC-BY (Hydrogen/DrumGizmo) przez silnik manifest.json,
     potem podmiana na nagrania Jacke (spec nizej, `przygotuj_sample.py` gotowy).
  4. Cel strony: zacheta do lekcji 1:1 + sprzedaz kursow online (WooCommerce juz jest).

## Srodowisko perki.pl (z wdrozen, zweryfikowane 2026-06-10)
- WordPress self-hosted + LiteSpeed + Autoptimize + WAF. Konektor WordPress.com NIE dziala.
- WAF blokuje uwierzytelniony zewnetrzny REST; publiczne endpointy + wzorzec
  nonce-z-GET dzialaja (perki-instruktor i perki-gra uzywaja go produkcyjnie).
- Wdrozenia: zip przez wp-admin > Wtyczki > Wyslij wtyczke, ALE pliki .html w zipie
  laduja na dysku jako 0 B (prawdopodobnie skaner bezpieczenstwa). Obejscie ktore
  dziala: tresc .html wkleja sie przez wp-admin > Wtyczki > Edytor plikow wtyczki.
- Na serwerze jest tez WP File Manager (alternatywna droga do plikow).
- Po kazdej republikacji: LiteSpeed Purge All (wtyczka robi to sama przy zmianie
  stalej VERSION w perki-gra.php).

## Zasady pracy (twarde)
- DRY: jedna wiedza = jedno zrodlo prawdy. Gra zyje w jednym pliku HTML w tym repo.
- Bez em-dashy i en-dashy w jakichkolwiek tresciach.
- Zasada malych krokow: kazda zmiana w UX ma dawac uczniowi szybkie poczucie progresu.
- Decyzje zalezne od srodowiska podejmujesz sam po inspekcji, nie odsylaj pytan,
  chyba ze blokuje Cie brak dostepu.
- Mobile first: uczniowie to czesto dzieci z telefonem.
- Zdjecia z jam-roose (fot. Szpilla) NIE moga byc publikowane do czasu pisemnej zgody
  fotografa. Zdjecia z kontrast/ = wlasne Jacke, bez formalnosci.

## Architektura gry (sekcje w perki-drum-hero.html)
- KONFIG WDROZENIA: `PERKI_API` + `PERKI_PING` (nonce), deep-link `?level=N`.
- AUDIO: obiekt `DRUMS` (kick, snare, hihat, crash, ride, tom1, tom2, ftom),
  funkcja `play(name)`. To miejsce podmiany na silnik sample'owy.
- KEYMAP/PADS: F hi-hat, J werbel, SPACJA stopa, K crash, D/S/A tomy, L ride.
- SONGS: nowy utwor = jeden obiekt `{id, title, desc, bpm, bars, notes}`.
- HIGHWAY: canvas, 4 tory, okna trafien 80 ms perfect / 180 ms good; dotyk = tap w tor.
- Postep gracza w localStorage (klucz `perkiDrumHero`).

## Roadmapa (kolejnosc)
1. ~~Publikacja gry + zapis wynikow i lejek~~ WYKONANE 2026-06-10. <- bylo
2. JASNY redesign: landing sprzedazowy (lekcje + kursy) + jasna skorka gry. <- TERAZ
3. Fotorealistyczny interfejs gry (zdjecie zestawu Jacke + hotspoty, wzor EZdrummer).
4. Silnik sample'owy: CC0/CC-BY od razu, nagrania Jacke po sesji (spec nizej).
5. LMS: LearnDash/Tutor LMS + GamiPress; kurs "Pierwszy Groove"; po lekcji N gra ?level=N.
6. Retencja: streaki, ranking tygodniowy, wyzwania, mail przez n8n.
7. Premium: subskrypcja + feedback wideo od Jacka.

## Specyfikacja sampli (Jacke nagrywa sam: AKG Drum Set Premium,
## Scarlett 18i20, Logic Pro)
- Artykulacje: kick, snare, hihat (closed), hihat_open (opcjonalnie), crash, ride,
  tom1, tom2, ftom.
- Na artykulacje: 3 warstwy velocity (p / mf / ff) x 3 round robiny = 9 plikow.
  Minimum startowe: 2x2 = 4 pliki.
- Nazewnictwo: `kick_v1_rr1.ogg` ... `kick_v3_rr3.ogg` (v1 najcichsze).
- Format finalny: OGG Vorbis q5, 44.1 kHz; przyciete do transjentu, fade out ogona,
  zachowane RELATYWNE glosnosci miedzy warstwami (bez normalizacji per plik).
  Cel: cala paczka < 10 MB. Crash/ride moga byc stereo (z overheadow), reszta mono.
- `przygotuj_sample.py` w tym repo: konwersja WAV -> OGG + automatyczny
  `samples/manifest.json` (format: {"kick": ["kick_v1_rr1.ogg", ...], ...}).
- LICENCJA: NIE wolno osadzac na publicznej stronie sampli z EZdrummer/Toontrack ani
  innych komercyjnych VST (EULA zabrania redystrybucji surowych sampli). Wlasne
  nagrania Jacke = pelne prawa + autentyczny branding ("grasz na moim zestawie").
  Awaryjnie: biblioteki na licencjach CC0/CC-BY (np. zestawy DrumGizmo, Hydrogen)
  z podaniem atrybucji w stopce gry.

## Identyfikatory
- Strona: https://perki.pl (WP self-hosted)
- Gra: https://perki.pl/gra/ ; wyniki w kokpicie: menu "Wyniki gry" (CPT wynik_gry)
- Strona instruktora (niepubliczna w nawigacji): https://perki.pl/twoj-profil-instruktora/
- Repo: https://github.com/Skillovsky/perki.pl
- n8n: https://jacke.app.n8n.cloud (dostepne, ale NIE wciagaj go tam, gdzie WP wystarczy)
