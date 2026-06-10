# Wdrozenie Perki Drum Hero na perki.pl (2026-06-10)

## AKTUALIZACJA: v2 (1.1.0) wdrozona tego samego dnia
- JASNA, nowoczesna skorka (decyzja Jacke: jasno, lekko) + FOTOREALISTYCZNY
  zestaw Jacke w wolnej grze: zdjecie (Media Library: perki-kit.jpg, att. 4492)
  z 10 klikalnymi strefami, fallback do klasycznych padow.
- Zrodlo zdjecia: IMG_5598.HEIC od Jacke (perspektywa gracza); obrobiony kadr
  w repo: wp-plugins/perki-gra/assets/perki-kit.jpg. Docelowo warto powtorzyc
  zdjecie z doswietlona prawa strona (ride/UFIP w cieniu).
- Drugi audyt adwersaryjny: 5 majorow naprawionych (kontrast WCAG --muted i nut
  na jasnym torze, touch-action vs double-tap zoom iOS, aspect-ratio obrazka vs
  hotspoty na 3G, specyficznosc #kit.hidden vs .nofoto) + 6 minorow.
- Sciezka wdrozenia tresci, ktora dziala najlepiej: commit do repo -> push ->
  w edytorze wtyczki fetch z raw.githubusercontent.com -> setValue -> zapis
  (zero recznego wklejania; raw ma CORS *). Upload obrazow: Media Library przez
  fetch blob + async-upload.php z wpUploaderInit.multipart_params.
- Do skasowania w mediach: duplikat perki-kit-1.jpg (att. 4493).


## Co wdrozono
- Wtyczka `perki-gra` 1.0.0 (z `wp-plugins/perki-gra/`): aktywna na produkcji.
- Gra dostepna: https://perki.pl/gra/ oraz deep-link https://perki.pl/gra/?level=N
- Endpointy: GET /wp-json/perki/v1/gra-ping (nonce), POST /wp-json/perki/v1/wynik
- Wyniki: kokpit > "Wyniki gry" (CPT wynik_gry, niepubliczny) + mail do admina
  (lead: zawsze; wynik anonimowy: max 1 mail/h per IP).

## Metoda publikacji (Zadanie 0 briefu: wybor i uzasadnienie)
Wybrano: wtyczka WP serwujaca gre przez rewrite `/gra/` + REST (wzorzec perki-instruktor).
- WP-CLI/SSH/FTP: brak dostepow na tej maszynie.
- REST z Application Password: WAF blokuje (potwierdzone wczesniej).
- Pozostala zalogowana sesja wp-admin w Chrome i ona wystarczyla.
Wtyczka > plik statyczny w webroot, bo: brak FTP, a wtyczka daje tez endpoint
wynikow, CPT, purge cache przy aktualizacji i przezyje aktualizacje motywu.

## PULAPKI tego hostingu (WAZNE przy kolejnych wdrozeniach)
1. **Zip przez "Wyslij wtyczke" wycina .html do 0 B.** PHP rozpakowal sie poprawnie,
   `perki-drum-hero.html` wyladowal na dysku PUSTY (bajty zipa byly identyczne
   lokalnie i w uploadzie - zweryfikowano md5). Prawdopodobnie skaner/sanityzacja
   na serwerze. OBEJSCIE KTORE DZIALA: wp-admin > Wtyczki > Edytor plikow wtyczki >
   otworz perki-gra/perki-drum-hero.html > wklej tresc > Zaktualizuj plik.
   (Alternatywa na przyszlosc: WP File Manager, jest zainstalowany.)
2. **Optymalizator JS (Autoptimize/LiteSpeed) przepisuje inline `<script>`**
   na `<script defer src="data:text/javascript;base64,...">`. Gra jest na to
   odporna (skrypt i tak czeka na DOM). Po kazdej zmianie ustawien optymalizacji
   przetestowac /gra/?level=3.
3. **Po republikacji gry trzeba czyscic cache LiteSpeed.** Wtyczka robi
   Purge All sama, gdy zmieni sie stala `VERSION` w perki-gra.php - przy kazdej
   zmianie gry PODBIJ WERSJE. Recznie: pasek admina > LiteSpeed > Oproznij wszystko.

## Procedura republikacji gry (DRY)
1. Edytuj `wp-plugins/perki-gra/perki-drum-hero.html` w repo.
2. Podbij `VERSION` w `perki-gra.php`.
3. wp-admin > Wtyczki > Edytor plikow wtyczki > perki-gra > perki-drum-hero.html >
   wklej nowa tresc > Zaktualizuj plik. (Przy zmianie .php: tak samo dla perki-gra.php.)
4. Pasek admina > LiteSpeed Cache > Oproznij wszystko.
5. Test: /gra/ laduje sie z nowa wersja, /gra/?level=3 startuje Stadion po
   pierwszym kliknieciu/klawiszu.

## Testy akceptacyjne (wykonane 2026-06-10)
- [x] /gra/: 200, pelny HTML, tytul SEO "Perki Drum Hero: darmowa gra perkusyjna online"
- [x] Desktop: klawisze graja, level 1 "Puls serca" ukonczony (auto-test), ekran wynikow
- [x] Mobile (emulacja 375px lokalnie): pady dotykowe dzialaja, canvas miesci sie,
      dotyk torow w trybie Utwory dziala, karta wynikow przewijalna
- [x] POST /wynik z nonce: {"ok":true,"id":4472}; lead: {"ok":true,"id":4473}
- [x] Ochrony: bez nonce 403, bez Content-Type JSON 415
- [x] Deep-link /gra/?level=3: 200, startuje "Stadion (We Will Rock)" po interakcji
- [x] https://perki.pl/twoj-profil-instruktora/ nadal dziala (200), nietkniety
- [ ] Mail do admina: do potwierdzenia w skrzynce (wpisy CPT #4472/#4473 powstaly;
      sprawdz folder spam, nadawca = WordPress perki.pl)
- [ ] Link "Gra" w glownej nawigacji (patrz nizej)

## Co zostalo (rzeczy po stronie wp-admin / Jacke)
1. **Nawigacja**: dodac "Gra" -> https://perki.pl/gra/ w glownym menu
   (Wyglad > Menu albo edytor naglowka motywu; na 2026-06-10 nieklikniete,
   bo trwa redesign na jasno - decyzja: dodac przy publikacji jasnej wersji,
   zeby nie promowac ciemnej tymczasowki w menu).
2. Testowe wpisy CPT #4472, #4473 ("Test Claude", test@perki.pl) mozna usunac
   z kokpitu po obejrzeniu.
3. Faza 2: jasny redesign + fotorealistyczny kit (INSTRUKCJA-FOTO-ZESTAWU.md)
   + sample CC0 -> nagrania Jacke.
