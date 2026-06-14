# Jak wdrozyc nowa strone glowna (perki-home)

Plik prawdy: `wp-plugins/perki-home/home.html` (+ wrapper `perki-home.php`).
System "Studio Groove" — te same tokeny co landing lekcji (spojnosc).

## 1. Podglad live pod /start/ (bez ruszania strony glownej)

1. Wgraj wtyczke `perki-home` do `wp-content/plugins/` (przez WP File Manager
   albo recznie — pamietaj: upload zipa przez wp-admin WYCINA pliki .html do 0 B).
2. Aktywuj wtyczke "Perki Home (nowa strona glowna)".
3. Otworz `https://perki.pl/start/` — to nowa strona glowna do akceptacji.

## 2. Aktualizacja tresci (sprawdzona sciezka, bez zipa)

1. Edytuj `home.html`, `git commit` + `git push`.
2. wp-admin > Wtyczki > Edytor plikow wtyczki > perki-home > home.html.
3. W konsoli przegladarki (na stronie edytora):
   `fetch('https://raw.githubusercontent.com/Skillovsky/perki.pl/perki-home-redesign/wp-plugins/perki-home/home.html').then(r=>r.text()).then(t=>{wp.codeEditor||0;document.querySelector('#newcontent').value=t;})`
   (albo wklej recznie tresc), nastepnie "Aktualizuj plik".
4. Podbij `VERSION` w `perki-home.php` (auto-purge LiteSpeed) lub LiteSpeed > Oproznij wszystko.

## 3. Przejecie strony glownej (po akceptacji /start/)

W `perki-home.php` ustaw `const SET_AS_FRONT = true;` i zapisz.
Dokument bedzie serwowany rowniez na `/`. Cofniecie: `false`.
(Alternatywa czystsza: po akceptacji zmien `SLUG` na `''` lub ustaw w WP
Ustawienia > Czytanie pusta strone — ale SET_AS_FRONT wystarcza.)

## Obrazy (folder img/) — WAZNE dla wdrozenia

Strona uzywa zdjec z `wp-plugins/perki-home/img/` przez sciezke
`/wp-content/plugins/perki-home/img/...` (dziala automatycznie, gdy wtyczka jest
w `wp-content/plugins/`). Pliki:
- `kit-pearl-red.jpg`, `kit-premier-black.jpg`, `kit-premier-green.jpg` — REALNE
  zdjecia zestawow Jacke (folder `kontrast/`, prawa CZYSTE, wlasne). Sklep.
- `yt-tuga.jpg`, `yt-bfrt.jpg` — miniatury YouTube (TUGA, Blue Flowers Red Thorns).
UWAGA: upload zipa przez wp-admin moze tknac pliki — najlepiej wgraj `img/` przez
WP File Manager / FTP, albo trzymaj wtyczke w repo i deployuj plikowo.

## Stan tresci

ZROBIONE (realne dane):
- SKLEP: 3 prawdziwe foto zestawow (Pearl Export czerwony, Premier czarny, Premier
  szmaragdowy). CENY (4200/3690/4599) sa PRZYKLADOWE — ZWERYFIKUJ z WooCommerce.
  Docelowo: podmien statyke na WC query/shortcode.
- FILMY: 2 prawdziwe filmy YouTube (lite-embed, klik laduje iframe nocookie).
- WERSJA EN: pelny przelacznik PL/EN (przycisk w nav, localStorage). Tlumaczenia
  przez atrybuty `data-en` / `data-en-html`. Dodajac nowy tekst PL, dodaj `data-en`.

DO UZUPELNIENIA:
- HERO + "O mnie": hero = interaktywny GRYWALNY zestaw (Web Audio) — zostaje.
  Sekcja "O mnie" ma grafike SVG; prawdziwy PORTRET do dorobienia (sesja). UWAGA
  PRAWNA: zdjecia z `jam-roose/` wymagaja zgody fotografa (szpilla@gmail.com) —
  NIE UZYWAC do czasu zgody. Czyste sa tylko `kontrast/` (wlasne Jacke).
- OPINIE: 3 recenzje to placeholdery (Michal/Anna/Piotr, + EN) — wstaw PRAWDZIWE
  z Google (tresc + imie). Nie zmyslamy opinii.
- Statystyki 16/300+/71/4.9 — zweryfikuj.
- Linki nawigacji zakladaja `/lekcje-nowy/`, `/sklep/`, `/gra/`.
