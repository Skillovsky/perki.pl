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

## Do uzupelnienia po stronie tresci (placeholdery w home.html)

- HERO + sekcja "O mnie": slot `<svg class="hero-art">` -> podmien na
  `<img src="..." alt="Jacke przy perkusji">` (foto z Media Library).
- SKLEP: 3 karty maja sztywne ceny/marki (Yamaha 4599 / Premier 4200 /
  Hayman 3690) i linkuja do `/sklep/`. Podmien na realne, najlepiej hot-produkty
  z WooCommerce (docelowo: shortcode/WC query zamiast statyki).
- OPINIE: 3 recenzje to placeholdery (Michal/Anna/Piotr) — wstaw prawdziwe z Google.
- Statystyki: 16 lat / 300+ lekcji / 71 filmow / 4.9 — zweryfikuj liczby.
- Linki nawigacji zakladaja istnienie `/lekcje-nowy/`, `/sklep/`, `/gra/`.
