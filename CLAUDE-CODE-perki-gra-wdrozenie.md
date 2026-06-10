# Brief dla Claude Code: wdrożenie gry Perki Drum Hero na perki.pl

Przeczytaj najpierw `CLAUDE.md` (kontekst, zasady, architektura). Ten brief to zadanie nr 1 z roadmapy.

## Cel
Opublikować grę pod adresem `https://perki.pl/gra/` (lub najbliższym osiągalnym), podpiąć zapis wyników po stronie WordPress i podlinkować grę w nawigacji. Gra ma działać na desktopie i telefonie.

## Plik źródłowy (jedyne źródło prawdy)
- `perki-drum-hero.html` - kompletny, samodzielny dokument HTML (style + skrypt + audio w jednym). Fonty ładowane z Google Fonts, zero innych zależności.

## Zadanie 0: rozpoznanie środowiska
Sprawdź, jaką masz realną ścieżkę do perki.pl: WP-CLI / SSH / FTP / zalogowana sesja kokpitu / REST z application password. Wybierz najprostszą działającą i odnotuj wybór w podsumowaniu. Konektor WordPress.com pomiń (brak Jetpacka).

## Zadanie 1: publikacja gry
Gra jest pełnym dokumentem `<html>`, więc NIE wklejaj jej w blok "Własny kod HTML" bez adaptacji. Kolejność preferencji:

1. **Plik statyczny w webroot** (masz dostęp do plików): umieść jako `/gra/index.html`. Najczystsze: pełny ekran, zero konfliktów z motywem, natychmiastowe ładowanie.
2. **Iframe**: wgraj plik na serwer (np. `wp-content/uploads/gra/perki-drum-hero.html`; jeśli upload .html zablokowany, użyj FTP/SSH albo zarejestruj wyjątek), potem utwórz stronę WP "Gra" z iframe na pełną szerokość, `min-height: 90vh`, `allow="autoplay"`.
3. **Adaptacja do snippetu** (ostateczność, brak dostępu do plików): przeskopuj style pod jeden kontener id, usuń `<html>/<head>/<body>` i osadź w bloku HTML, wzorem strony instruktora.

## Zadanie 2: zapis wyników + lejek
W pliku gry na początku skryptu jest `var PERKI_API = ""`. Po ukończeniu utworu gra robi `POST` JSON: `{song, title, score, acc, stars, xp}`.

1. Zarejestruj endpoint `POST /wp-json/perki/v1/wynik` (mały plugin lub functions.php):
   - zapis jako CPT `wynik_gry` (pola jako post meta) + `wp_mail()` z powiadomieniem na adres admina,
   - sanityzacja wszystkich pól, walidacja typów, limit wielkości payloadu,
   - ochrona przed spamem: rate limit per IP (transient) + odrzucanie żądań bez `Content-Type: application/json`,
   - `permission_callback => '__return_true'` (endpoint publiczny), ale loguj IP.
2. Wpisz pełny URL endpointu w `PERKI_API` i zrepublikuj plik gry.
3. Rozszerzenie lejka (zrób, jeśli czas pozwala): w ekranie wyników gry dodaj pole "Zapisz wynik w rankingu - podaj imię i e-mail" wysyłane tym samym endpointem (pola `name`, `email`, walidacja po stronie WP). To jest źródło leadów. Zmianę wprowadź w pliku źródłowym gry, zgodnie z DRY.

## Zadanie 3: widoczność
- Dodaj "Gra" do głównej nawigacji perki.pl.
- Meta title/description strony: gra perkusyjna online za darmo, graj na perkusji klawiaturą, nauka perkusji przez zabawę (dobierz naturalne brzmienie, bez upychania fraz).
- Sprawdź, czy strona instruktora `https://perki.pl/twoj-profil-instruktora/` nadal działa; niczego na niej nie zmieniaj.

## Test akceptacyjny
1. Otwórz opublikowany URL na desktopie: klawisze F/J/SPACJA/K grają dźwięki, level 1 "Puls serca" da się ukończyć, ekran wyników się pokazuje.
2. Otwórz na telefonie (lub emulacji): pady reagują na dotyk, canvas mieści się w ekranie.
3. Ukończ utwór przy ustawionym `PERKI_API`: nowy wpis CPT `wynik_gry` widoczny w kokpicie, mail dotarł.
4. Deep-link `https://perki.pl/gra/?level=3` startuje od razu utwór "Stadion" po pierwszym dotknięciu/klawiszu.

## Zasady
- DRY: wszystkie zmiany w grze robisz w `perki-drum-hero.html` i republikujesz ten sam plik. Żadnych rozjechanych kopii.
- Bez em-dashy i en-dashy w treściach.
- Nie ruszaj sklepu ani pozostałych stron perki.pl.

## Definicja ukończenia
- Wybrana i uzasadniona metoda publikacji; gra dostępna pod zwróconym URL.
- Endpoint wyników działa, testowy wynik zapisany po stronie WP.
- Link "Gra" w nawigacji.
- Krótkie podsumowanie: co wybrałeś, dlaczego, co zostało na fazę 2 (silnik sample'owy, patrz CLAUDE.md).
