<?php
/**
 * Plugin Name: Perki Lekcje (jasny landing)
 * Description: Jasny, sprzedazowy landing lekcji perkusji pod adresem /lekcje-nowy/ serwowany headless (pelny dokument, bez motywu i Elementora). Zrodla prawdy: landing.html (tresc) i business.json (dane firmy). Przyjmuje prosby o termin (CPT "prosba_lekcja" + mail do Jacke + potwierdzenie do ucznia). Serwuje tez /llms.txt, /perki-sitemap.xml i dopisuje boty AI do robots.txt.
 * Version: 1.2.1
 * Author: perki.pl
 * License: GPL-2.0-or-later
 * Text Domain: perki-lekcje-jasny
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Perki_Lekcje_Jasny {

	const VERSION   = '1.2.1';            // zmiana (lub edycja landing.html/business.json) = auto purge LiteSpeed
	const QV        = 'perki_lekcje_jasny';
	const SLUG      = 'lekcje-nowy';      // docelowo mozna przelaczyc na 'lekcje'
	const PAGE_FILE = 'landing.html';     // jedyne zrodlo prawdy tresci (DRY)
	const DATA_FILE = 'business.json';    // jedyne zrodlo prawdy danych firmy (DRY)
	const HEAD_MARK = '<!--PERKI:HEAD-->';

	const CPT          = 'prosba_lekcja';
	const META         = '_pl_';
	const NS           = 'perki/v1';
	const THROTTLE_MAX = 20;              // backstop antyspamowy per IP
	const THROTTLE_WIN = 3600;
	const MAX_BODY     = 8192;
	const CACHE_TTL    = 604800;          // LiteSpeed: 7 dni, purge przy kazdej zmianie plikow
	const CONFIRM_DAY  = 50;              // maks. potwierdzen do uczniow dziennie (cala strona)

	/** Awaryjne reguly rezerwacji, gdy business.json jest uszkodzony (formularz dalej dziala). */
	const DEFAULT_REZ = array(
		'dni_tygodnia' => array( 1, 2, 3, 4, 5, 6 ), 'godzina_od' => 12, 'godzina_do' => 20,
		'dlugosci_min' => array( 45, 60, 90 ), 'horyzont_dni' => 60, 'min_wyprzedzenie_h' => 12,
	);

	const FORMY = array( 'stacjonarnie' => 'Stacjonarnie (Poznań)', 'online' => 'Online (Google Meet)' );

	/** Boty AI, ktore zapraszamy wprost (cytowanie w odpowiedziach i wyszukiwarkach AI). */
	const AI_BOTS = array(
		'OAI-SearchBot', 'ChatGPT-User', 'GPTBot',
		'Claude-SearchBot', 'Claude-User', 'ClaudeBot',
		'PerplexityBot', 'Perplexity-User',
		'Google-Extended', 'Applebot-Extended',
		'DuckAssistBot', 'MistralAI-User', 'meta-externalagent',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_purge_on_update' ) );
		add_action( 'init', array( __CLASS__, 'serve_text_endpoints' ), 100 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_page' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 99, 2 );

		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'admin_column_value' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_notices', array( __CLASS__, 'health_notice' ) );
	}

	/**
	 * Samodiagnostyka po wdrozeniu: na tym hostingu upload zipa potrafi wyzerowac pliki .html
	 * (DEPLOY-NOTES). Pokazujemy adminowi, czego brakuje, zamiast cicho serwowac pusta strone.
	 */
	public static function health_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) { return; }
		$need = array(
			self::PAGE_FILE                  => 20000,
			self::DATA_FILE                  => 500,
			'assets/fonts/inter-pl.woff2'    => 10000,
			'assets/img/perki-kit-960.avif'  => 5000,
			'assets/img/og-perki-lekcje.jpg' => 5000,
		);
		$bad = array();
		if ( null === self::business_raw_error() ) { $bad[] = self::DATA_FILE . ' (niepoprawny JSON: ' . json_last_error_msg() . ')'; }
		foreach ( $need as $f => $min ) {
			$size = @filesize( self::path( $f ) );
			if ( ! $size || $size < $min ) { $bad[] = $f . ' (' . (int) $size . ' B)'; }
		}
		if ( $bad ) {
			echo '<div class="notice notice-error"><p><b>Perki Lekcje:</b> brakuje plikow, sa puste albo uszkodzone: '
				. esc_html( implode( ', ', $bad ) )
				. '. Wgraj je ponownie (Wtyczki &gt; Edytor plikow wtyczki dla .html/.json, zip dla obrazow i fontow).</p></div>';
		}
	}

	/* -------------------------------------------------------------- DANE */

	private static function path( $file ) {
		return plugin_dir_path( __FILE__ ) . $file;
	}

	/** Surowe dekodowanie business.json: tablica albo null (brak pliku / zly JSON). */
	private static function business_raw_error() {
		$raw = @file_get_contents( self::path( self::DATA_FILE ) );
		$b   = $raw ? json_decode( $raw, true ) : null;
		return is_array( $b ) ? $b : null;
	}

	/** business.json jako tablica (cache na czas zadania); reguly rezerwacji zawsze kompletne. */
	public static function business() {
		static $b = null;
		if ( null === $b ) {
			$b = self::business_raw_error();
			if ( null === $b ) { $b = array(); }
			$b['rezerwacja'] = array_merge( self::DEFAULT_REZ, isset( $b['rezerwacja'] ) && is_array( $b['rezerwacja'] ) ? $b['rezerwacja'] : array() );
		}
		return $b;
	}

	private static function page_url() {
		return home_url( '/' . self::SLUG . '/' );
	}

	/** Odcisk wersji: stala + czasy modyfikacji plikow tresci (edycja w Edytorze wtyczki tez czysci cache). */
	private static function fingerprint() {
		return self::VERSION . '|' . @filemtime( self::path( self::PAGE_FILE ) ) . '|' . @filemtime( self::path( self::DATA_FILE ) );
	}

	/** FAQ wyciagane z WIDOCZNEGO landing.html (jedno zrodlo prawdy dla FAQPage i llms.txt). */
	public static function faq_from_html( $html ) {
		$out = array();
		// Tylko sekcja #faq (inne <details>, np. klauzula RODO w formularzu, to nie FAQ).
		if ( preg_match( '#<section[^>]*\bid="faq"[^>]*>(.*?)</section>#su', $html, $sec ) ) { $html = $sec[1]; }
		if ( preg_match_all( '#<details[^>]*>\s*<summary[^>]*>(.*?)</summary>(.*?)</details>#su', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$q = trim( html_entity_decode( wp_strip_all_tags( $row[1] ), ENT_QUOTES, 'UTF-8' ) );
				$a = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $row[2] ), ENT_QUOTES, 'UTF-8' ) ) );
				if ( '' !== $q && '' !== $a ) { $out[] = array( 'q' => $q, 'a' => $a ); }
			}
		}
		return $out;
	}

	private static function fmt_price( $v ) {
		return ( floor( $v ) == $v ) ? (string) (int) $v : number_format( (float) $v, 2, ',', '' );
	}

	/** schema.org JSON-LD (@graph) z business.json + FAQ z landingu. */
	public static function jsonld( $html ) {
		$b    = self::business();
		$home = isset( $b['url'] ) ? $b['url'] : home_url( '/' );
		$page = self::page_url();
		$org  = $home . '#szkola';
		$per  = $home . '#nauczyciel';

		$addr = array();
		foreach ( (array) ( isset( $b['lokalizacje'] ) ? $b['lokalizacje'] : array() ) as $l ) {
			$addr[] = array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $l['ulica'],
				'addressLocality' => $l['miasto'],
				'addressRegion'   => $l['region'],
				'addressCountry'  => $l['kraj'],
			);
		}

		$offers = array();
		$prices = array();
		foreach ( (array) ( isset( $b['oferta'] ) ? $b['oferta'] : array() ) as $o ) {
			$per_lesson = $o['cena'] / max( 1, (int) $o['lekcje'] );
			$prices[]   = $per_lesson;
			$offer      = array(
				'@type'         => 'Offer',
				'name'          => $o['nazwa'],
				'price'         => (string) $o['cena'],
				'priceCurrency' => isset( $b['waluta'] ) ? $b['waluta'] : 'PLN',
				'url'           => $page . '#cennik',
				'availability'  => 'https://schema.org/InStock',
				'itemOffered'   => array(
					'@type'       => 'Service',
					'name'        => $o['nazwa'] . ' gry na perkusji',
					'description' => sprintf( '%d x %d min, lekcja 1 na 1 w Poznaniu lub online', (int) $o['lekcje'], (int) $b['lekcja_min'] ),
				),
			);
			if ( ! empty( $o['od'] ) ) {
				$offer['priceSpecification'] = array( '@type' => 'PriceSpecification', 'minPrice' => (string) $o['cena'], 'priceCurrency' => $offer['priceCurrency'] );
			}
			$offers[] = $offer;
		}

		$graph = array(
			array(
				'@type'       => array( 'LocalBusiness', 'EducationalOrganization' ),
				'@id'         => $org,
				'name'        => $b['nazwa'],
				'description' => $b['opis'],
				'url'         => $page,
				'telephone'   => $b['telefon'],
				'email'       => $b['email'],
				'image'       => $b['obraz'],
				'logo'        => $b['logo'],
				'address'     => $addr,
				'priceRange'  => $prices ? self::fmt_price( min( $prices ) ) . '-' . self::fmt_price( max( $prices ) ) . ' zł za lekcję' : null,
				'currenciesAccepted' => isset( $b['waluta'] ) ? $b['waluta'] : 'PLN',
				'paymentAccepted'    => 'BLIK, Przelewy24, karta płatnicza',
				'areaServed'  => array(
					array( '@type' => 'City', 'name' => 'Poznań' ),
					array( '@type' => 'Country', 'name' => 'Polska' ),
				),
				'knowsLanguage' => 'pl',
				'founder'     => array( '@id' => $per ),
				'employee'    => array( '@id' => $per ),
				'sameAs'      => isset( $b['profile'] ) ? $b['profile'] : array(),
				'hasOfferCatalog' => array(
					'@type'           => 'OfferCatalog',
					'name'            => 'Lekcje gry na perkusji',
					'itemListElement' => $offers,
				),
			),
			array(
				'@type'    => 'Person',
				'@id'      => $per,
				'name'     => $b['nauczyciel']['imie'],
				'jobTitle' => $b['nauczyciel']['rola'],
				'memberOf' => array( '@type' => 'MusicGroup', 'name' => $b['nauczyciel']['zespol'] ),
				'worksFor' => array( '@id' => $org ),
				'knowsAbout' => array( 'perkusja', 'gra na perkusji', 'groove', 'improwizacja' ),
				'sameAs'   => isset( $b['profile'] ) ? $b['profile'] : array(),
			),
		);

		$webpage = array(
			'@type'        => array( 'WebPage' ),
			'@id'          => $page . '#strona',
			'url'          => $page,
			'name'         => self::title_from_html( $html ),
			'inLanguage'   => isset( $b['jezyk'] ) ? $b['jezyk'] : 'pl-PL',
			'about'        => array( '@id' => $org ),
			'primaryImageOfPage' => $b['obraz'],
			'dateModified' => gmdate( 'c', (int) @filemtime( self::path( self::PAGE_FILE ) ) ),
		);
		$faq = self::faq_from_html( $html );
		if ( $faq ) {
			$webpage['@type'][]    = 'FAQPage';
			$webpage['mainEntity'] = array_map( function ( $f ) {
				return array(
					'@type'          => 'Question',
					'name'           => $f['q'],
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $f['a'] ),
				);
			}, $faq );
		}
		$graph[] = $webpage;

		$graph = array_map( function ( $node ) { return array_filter( $node, function ( $v ) { return null !== $v; } ); }, $graph );

		return array( '@context' => 'https://schema.org', '@graph' => $graph );
	}

	private static function title_from_html( $html ) {
		return preg_match( '#<title>(.*?)</title>#su', $html, $m ) ? html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) : '';
	}

	/** Konfiguracja frontu (formularz) z business.json, bez duplikowania w HTML. */
	public static function front_config() {
		$b = self::business();
		$privacy = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		return array(
			'api'         => array(
				'prosba' => rest_url( self::NS . '/prosba' ),
				'ping'   => rest_url( self::NS . '/lekcja-ping' ),
			),
			'rezerwacja'  => $b['rezerwacja'],
			'tz'          => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'Europe/Warsaw',
			'oferta'      => isset( $b['oferta'] ) ? $b['oferta'] : array(),
			'telefon'     => isset( $b['telefon_wyswietlany'] ) ? $b['telefon_wyswietlany'] : '',
			'prywatnosc'  => $privacy ? $privacy : '',
		);
	}

	/** Dokument landingu z wstrzyknietym JSON-LD i konfiguracja (w miejscu znacznika HEAD_MARK). */
	public static function render_landing() {
		$html = (string) @file_get_contents( self::path( self::PAGE_FILE ) );
		if ( '' === $html ) { return ''; }
		$flags  = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
		$inject = '<script type="application/ld+json">' . wp_json_encode( self::jsonld( $html ), $flags ) . '</script>' . "\n"
			. '<script>window.PERKI_CFG=' . wp_json_encode( self::front_config(), $flags ) . ';</script>';
		return false !== strpos( $html, self::HEAD_MARK )
			? str_replace( self::HEAD_MARK, $inject, $html )
			: str_replace( '</head>', $inject . "\n</head>", $html );
	}

	/* ------------------------------------------------------------- STRONA */

	public static function register_rewrite() {
		add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?' . self::QV . '=1', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QV;
		return $vars;
	}

	public static function maybe_purge_on_update() {
		$fp = self::fingerprint();
		if ( get_option( 'perki_lekcje_jasny_ver' ) !== $fp ) {
			update_option( 'perki_lekcje_jasny_ver', $fp );
			do_action( 'litespeed_purge_all' );
			flush_rewrite_rules();
		}
	}

	private static function common_headers( $ctype ) {
		status_header( 200 );
		header( 'Content-Type: ' . $ctype );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Cache-Control: public, max-age=600' );
		// LSCache po stronie serwera: strona statyczna, nonce pobierany osobnym GET (no-store).
		header( 'X-LiteSpeed-Cache-Control: public,max-age=' . self::CACHE_TTL );
		header( 'X-LiteSpeed-Tag: perki_lekcje' );
	}

	/** Landing to kompletny dokument HTML (wlasny nav i footer) serwowany headless. */
	public static function serve_page() {
		if ( '1' !== get_query_var( self::QV ) ) { return; }

		$path = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH );
		if ( ! preg_match( '#^/' . preg_quote( self::SLUG, '#' ) . '/?$#', $path ) ) { return; }

		if ( ! defined( 'LITESPEED_NO_OPTM' ) ) { define( 'LITESPEED_NO_OPTM', true ); }

		$html = self::render_landing();
		if ( '' === $html ) {
			status_header( 404 );
			exit( 'Brak pliku landingu. Zainstaluj wtyczke perki-lekcje-jasny ponownie.' );
		}

		// Headless w 100%: czyscimy wszystkie bufory wyjscia, zeby zaden plugin
		// (lazyload, placeholdery obrazow, Autoptimize) nie przerobil dokumentu.
		while ( ob_get_level() > 0 ) { ob_end_clean(); }

		self::common_headers( 'text/html; charset=UTF-8' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()' );
		header( 'X-Perki-Headless: 1' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- zaufany plik wtyczki
		exit;
	}

	/** /llms.txt i /perki-sitemap.xml: dopasowanie po sciezce (niezalezne od rewrite i redirect_canonical). */
	public static function serve_text_endpoints() {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		$path = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH );
		$home = rtrim( (string) parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( $home . '/llms.txt' === $path ) {
			$out = self::llms_txt();
			$ct  = 'text/plain; charset=UTF-8';
		} elseif ( $home . '/perki-sitemap.xml' === $path ) {
			$out = self::sitemap_xml();
			$ct  = 'application/xml; charset=UTF-8';
		} else {
			return;
		}
		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		self::common_headers( $ct );
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- generowane z zaufanych plikow wtyczki
		exit;
	}

	/** llms.txt wg llmstxt.org: H1, blockquote z podsumowaniem, fakty, sekcje linkow. */
	public static function llms_txt() {
		$b    = self::business();
		$html = (string) @file_get_contents( self::path( self::PAGE_FILE ) );
		$page = self::page_url();
		$L    = array();

		$L[] = '# ' . $b['nazwa'];
		$L[] = '';
		$L[] = '> ' . $b['opis'];
		$L[] = '';
		$L[] = '## Najważniejsze fakty';
		$L[] = '';
		$L[] = '- Nauczyciel: ' . $b['nauczyciel']['imie'] . ' (' . implode( '; ', $b['nauczyciel']['fakty'] ) . ')';
		$locs = array();
		foreach ( $b['lokalizacje'] as $l ) { $locs[] = $l['ulica'] . ', ' . $l['miasto']; }
		$L[] = '- Gdzie: ' . implode( ' oraz ', $locs ) . '; albo online przez ' . $b['online'];
		$L[] = '- Forma: lekcje indywidualne 1 na 1, ' . (int) $b['lekcja_min'] . ' min, dla dzieci od 7 lat, dorosłych, wracających po przerwie i zaawansowanych';
		foreach ( $b['oferta'] as $o ) {
			$per = $o['cena'] / max( 1, (int) $o['lekcje'] );
			$L[] = sprintf( '- Cena: %s: %s%s zł%s', $o['nazwa'], empty( $o['od'] ) ? '' : 'od ', self::fmt_price( $o['cena'] ), $o['lekcje'] > 1 ? ' (' . self::fmt_price( $per ) . ' zł za lekcję)' : '' );
		}
		$L[] = '- Zmiana terminu: bezpłatnie do ' . (int) $b['zasada_zmiany_terminu_h'] . ' h przed lekcją';
		$r   = $b['rezerwacja'];
		$L[] = sprintf( '- Rezerwacja: formularz na stronie (%s, godziny %d:00-%d:00), potwierdzenie terminu i link do płatności BLIK, Przelewy24 lub kartą', self::days_label( $r['dni_tygodnia'] ), (int) $r['godzina_od'], (int) $r['godzina_do'] );
		$L[] = '- Kontakt: tel. ' . $b['telefon_wyswietlany'] . ', e-mail ' . $b['email'];
		$L[] = '';
		$L[] = '## Strony';
		$L[] = '';
		$L[] = '- [Lekcje gry na perkusji w Poznaniu i online](' . $page . '): oferta, cennik, rezerwacja terminu, FAQ';
		$L[] = '- [Perki Drum Hero](' . home_url( '/gra/' ) . '): darmowa gra perkusyjna w przeglądarce do nauki rytmu (klawiatura i dotyk)';
		$L[] = '- [Sklep perkusyjny](' . home_url( '/' ) . '): sprzęt i akcesoria perkusyjne';
		$faq = self::faq_from_html( $html );
		if ( $faq ) {
			$L[] = '';
			$L[] = '## FAQ';
			foreach ( $faq as $f ) {
				$L[] = '';
				$L[] = '### ' . $f['q'];
				$L[] = '';
				$L[] = $f['a'];
			}
		}
		$L[] = '';
		$L[] = '## Optional';
		$L[] = '';
		foreach ( $b['profile'] as $p ) { $L[] = '- [' . preg_replace( '#^https?://(www\.)?#', '', $p ) . '](' . $p . ')'; }
		return implode( "\n", $L ) . "\n";
	}

	/** [1,2,3,4,5,6] -> "poniedziałek-sobota"; [1,3,5] -> "poniedziałek, środa, piątek". */
	public static function days_label( $days ) {
		$n    = array( 1 => 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota', 'niedziela' );
		$days = array_values( array_unique( array_map( 'intval', (array) $days ) ) );
		sort( $days );
		$runs = array();
		foreach ( $days as $d ) {
			if ( $runs && end( $runs )[1] === $d - 1 ) { $runs[ count( $runs ) - 1 ][1] = $d; } else { $runs[] = array( $d, $d ); }
		}
		return implode( ', ', array_map( function ( $r ) use ( $n ) {
			if ( $r[0] === $r[1] ) { return $n[ $r[0] ]; }
			return $n[ $r[0] ] . ( $r[1] - $r[0] > 1 ? '-' : ', ' ) . $n[ $r[1] ];
		}, $runs ) );
	}

	public static function sitemap_xml() {
		$items = array(
			array( self::page_url(), (int) @filemtime( self::path( self::PAGE_FILE ) ), '0.9' ),
			array( home_url( '/gra/' ), 0, '0.6' ),
		);
		$x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $items as $it ) {
			$x .= '  <url><loc>' . esc_url( $it[0] ) . '</loc>' . ( $it[1] ? '<lastmod>' . gmdate( 'Y-m-d', $it[1] ) . '</lastmod>' : '' ) . '<priority>' . $it[2] . '</priority></url>' . "\n";
		}
		return $x . '</urlset>' . "\n";
	}

	/** robots.txt (wirtualny WP): zaproszenie botow AI + nasza mapa. Fizyczny plik robots.txt ma pierwszenstwo. */
	public static function robots_txt( $output, $public ) {
		if ( ! $public ) { return $output; }
		// Bot, ktory znajdzie wlasna grupe, ignoruje "User-agent: *" (RFC 9309), wiec kopiujemy jej reguly.
		$rules = array();
		$in    = false;
		foreach ( preg_split( '/\r?\n/', (string) $output ) as $line ) {
			$t = trim( $line );
			if ( preg_match( '/^user-agent:\s*(.+)$/i', $t, $m ) ) { $in = ( '*' === trim( $m[1] ) ); continue; }
			if ( $in && preg_match( '/^(allow|disallow|crawl-delay):/i', $t ) ) { $rules[] = $t; }
		}
		if ( ! $rules ) { $rules[] = 'Allow: /'; }
		$o  = rtrim( (string) $output ) . "\n\n# perki.pl: wyszukiwarki i asystenci AI moga czytac i cytowac strone\n";
		foreach ( self::AI_BOTS as $ua ) { $o .= 'User-agent: ' . $ua . "\n"; }
		$o .= implode( "\n", array_unique( $rules ) ) . "\n\n";
		$o .= 'Sitemap: ' . home_url( '/perki-sitemap.xml' ) . "\n";
		return $o;
	}

	/* ---------------------------------------------------------------- CPT */

	public static function register_cpt() {
		register_post_type( self::CPT, array(
			'labels' => array(
				'name'          => 'Prosby o lekcje',
				'singular_name' => 'Prosba o lekcje',
				'menu_name'     => 'Prosby o lekcje',
				'all_items'     => 'Wszystkie prosby',
				'edit_item'     => 'Prosba o lekcje',
				'search_items'  => 'Szukaj prosb',
				'not_found'     => 'Brak prosb',
			),
			'public'             => false,   // dane kontaktowe = PII
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => false,
			'menu_icon'          => 'dashicons-calendar-alt',
			'menu_position'      => 25,
			'capability_type'    => 'post',
			'supports'           => array( 'title' ),
		) );
	}

	/* -------------------------------------------------------------- ROUTES */

	public static function register_routes() {
		register_rest_route( self::NS, '/lekcja-ping', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function () {
				// LiteSpeed domyslnie cache'uje REST (7 dni): wspolny, przeterminowany nonce = 403 dla wszystkich.
				do_action( 'litespeed_control_set_nocache', 'perki nonce' );
				$res = new WP_REST_Response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ), 200 );
				$res->header( 'Cache-Control', 'no-store, private' );
				$res->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
				return $res;
			},
		) );

		register_rest_route( self::NS, '/prosba', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'handle_prosba' ),
		) );
	}

	/** IPv6: throttle per /64 (jeden klient ma zwykle cala podsiec). */
	private static function ip_bucket( $ip ) {
		if ( false !== strpos( $ip, ':' ) && false !== ( $bin = @inet_pton( $ip ) ) ) {
			return bin2hex( substr( $bin, 0, 8 ) ) . '::/64';
		}
		return $ip;
	}

	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return sanitize_text_field( $ip );
	}

	private static function err( $code, $msg, $status, $field = '' ) {
		return new WP_Error( $code, $msg, array( 'status' => $status, 'field' => $field ) );
	}

	/**
	 * Termin: ISO (Y-m-d + HH:MM) walidowany wg business.json (dni, godziny, horyzont, wyprzedzenie).
	 * Tekst nie-ISO (stary front w cache) przyjmujemy jako opis, zeby nie zgubic leada w trakcie wdrozenia.
	 * Zwraca array( etykieta, iso ) albo WP_Error.
	 */
	public static function validate_term( $date, $time, $required ) {
		$r = self::business();
		$r = $r['rezerwacja'];
		if ( '' === $date ) {
			return $required ? self::err( 'pl_term', 'Wybierz dzień i godzinę lekcji.', 400, 'data' ) : array( '', '' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return array( trim( $date . ' ' . $time ), '' ); // zgodnosc wsteczna
		}
		if ( ! $required && '' === $time ) {
			return array( '', '' ); // voucher: termin opcjonalny, sam dzien bez godziny pomijamy
		}
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $tm ) ) {
			return self::err( 'pl_time', 'Wybierz godzinę lekcji.', 400, 'godzina' );
		}
		$tz = wp_timezone();
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . sprintf( '%02d:%02d', $tm[1], $tm[2] ), $tz );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
			return self::err( 'pl_date', 'Niepoprawna data.', 400, 'data' );
		}
		$now  = new DateTimeImmutable( 'now', $tz );
		$lead = isset( $r['min_wyprzedzenie_h'] ) ? (int) $r['min_wyprzedzenie_h'] : 0;
		$hor  = isset( $r['horyzont_dni'] ) ? (int) $r['horyzont_dni'] : 60;
		$dow  = (int) $dt->format( 'N' );
		$h    = (int) $tm[1];
		if ( $dt < $now->modify( '+' . $lead . ' hours' ) ) {
			return self::err( 'pl_past', 'Ten termin jest za blisko albo już minął. Wybierz późniejszy.', 400, 'data' );
		}
		if ( $dt > $now->setTime( 23, 59 )->modify( '+' . $hor . ' days' ) ) {
			return self::err( 'pl_far', 'Ten termin jest zbyt odległy. Wybierz bliższy.', 400, 'data' );
		}
		if ( ! empty( $r['dni_tygodnia'] ) && ! in_array( $dow, array_map( 'intval', $r['dni_tygodnia'] ), true ) ) {
			return self::err( 'pl_dow', 'W ten dzień tygodnia nie prowadzę lekcji.', 400, 'data' );
		}
		if ( isset( $r['godzina_od'], $r['godzina_do'] ) && ( $h < (int) $r['godzina_od'] || $h > (int) $r['godzina_do'] ) ) {
			return self::err( 'pl_hour', 'Wybierz godzinę z listy.', 400, 'godzina' );
		}
		$label = function_exists( 'wp_date' ) ? wp_date( 'l, j F Y, H:i', $dt->getTimestamp(), $tz ) : $dt->format( 'Y-m-d H:i' );
		return array( $label, $dt->format( 'Y-m-d\TH:i' ) );
	}

	public static function handle_prosba( WP_REST_Request $request ) {

		$ctype = (string) $request->get_header( 'content_type' );
		if ( false === stripos( $ctype, 'application/json' ) ) {
			return self::err( 'pl_ctype', 'Wymagany Content-Type: application/json.', 415 );
		}
		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY ) {
			return self::err( 'pl_big', 'Wiadomość jest za długa.', 413 );
		}

		$hp = (string) $request->get_param( '_hp' );
		if ( '' !== trim( $hp ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'id' => 0 ), 200 );
		}

		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( empty( $nonce ) ) { $nonce = (string) $request->get_param( '_wpnonce' ); }
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return self::err( 'pl_nonce', 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.', 403 );
		}

		$tkey = 'perki_lekcja_thr_' . md5( self::ip_bucket( self::client_ip() ) );
		$cnt  = (int) get_transient( $tkey );
		if ( $cnt >= self::THROTTLE_MAX ) {
			return self::err( 'pl_throttle', 'Za dużo prób. Spróbuj ponownie za chwilę albo zadzwoń.', 429 );
		}
		set_transient( $tkey, $cnt + 1, self::THROTTLE_WIN ); // liczymy proby, nie tylko sukcesy

		$b     = self::business();
		$name  = mb_substr( sanitize_text_field( (string) $request->get_param( 'name' ) ), 0, 80 );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone = mb_substr( sanitize_text_field( (string) $request->get_param( 'phone' ) ), 0, 30 );
		$prod  = mb_substr( sanitize_text_field( (string) $request->get_param( 'produkt' ) ), 0, 40 );
		$len   = absint( $request->get_param( 'len' ) );
		$date  = mb_substr( sanitize_text_field( (string) $request->get_param( 'data' ) ), 0, 40 );
		$time  = mb_substr( sanitize_text_field( (string) $request->get_param( 'godzina' ) ), 0, 10 );
		$mode  = sanitize_key( (string) $request->get_param( 'forma' ) );
		$msg   = mb_substr( sanitize_textarea_field( (string) $request->get_param( 'wiadomosc' ) ), 0, 600 );

		if ( '' === $name ) {
			return self::err( 'pl_name', 'Podaj imię.', 400, 'name' );
		}
		if ( ! is_email( $email ) ) {
			return self::err( 'pl_email', 'Podaj poprawny e-mail.', 400, 'email' );
		}
		if ( '' !== $phone && ! preg_match( '/^[0-9 +()\-]{7,30}$/', $phone ) ) {
			return self::err( 'pl_phone', 'Numer telefonu wygląda na niepoprawny.', 400, 'phone' );
		}

		// Produkt: id z business.json albo (stary front) dowolna nazwa.
		$prod_name = $prod;
		foreach ( (array) ( isset( $b['oferta'] ) ? $b['oferta'] : array() ) as $o ) {
			if ( $o['id'] === $prod || $o['nazwa'] === $prod ) { $prod_name = $o['nazwa']; $prod = $o['id']; }
		}
		$allowed_len = isset( $b['rezerwacja']['dlugosci_min'] ) ? array_map( 'intval', $b['rezerwacja']['dlugosci_min'] ) : array();
		if ( $len && $allowed_len && ! in_array( $len, $allowed_len, true ) ) { $len = 0; }
		if ( ! isset( self::FORMY[ $mode ] ) ) { $mode = ''; }

		$term = self::validate_term( $date, $time, 'voucher' !== $prod );
		if ( is_wp_error( $term ) ) { return $term; }
		list( $term_label, $term_iso ) = $term;

		$title   = sprintf( '%s, %s (%s)', $name, $term_label ? $term_label : 'termin do ustalenia', $prod_name ? $prod_name : 'lekcja' );
		$post_id = wp_insert_post( array(
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			'post_title'  => wp_strip_all_tags( $title ),
		), true );
		if ( is_wp_error( $post_id ) ) {
			return self::err( 'pl_save', 'Nie udało się zapisać prośby. Zadzwoń, proszę.', 500 );
		}

		$meta = array(
			'name' => $name, 'email' => $email, 'phone' => $phone, 'produkt' => $prod_name,
			'len' => 'voucher' === $prod ? 0 : $len, 'data' => $term_label, 'termin_iso' => $term_iso,
			'forma' => ( $mode && 'voucher' !== $prod ) ? self::FORMY[ $mode ] : '', 'wiadomosc' => $msg,
		);
		foreach ( $meta as $k => $v ) { update_post_meta( $post_id, self::META . $k, $v ); }

		$mail_ok = self::notify_admin( $post_id, $meta );
		$copy_ok = self::confirm_student( $meta, $prod, (bool) $term_iso );

		return new WP_REST_Response( array( 'ok' => true, 'id' => $post_id, 'termin' => $term_label, 'mail' => (bool) $mail_ok, 'kopia' => (bool) $copy_ok ), 200 );
	}

	private static function summary_lines( $m ) {
		$l = array( 'Pakiet: ' . ( $m['produkt'] ? $m['produkt'] : 'lekcja' ) );
		if ( $m['len'] ) { $l[] = 'Długość: ' . $m['len'] . ' min'; }
		$l[] = 'Preferowany termin: ' . ( $m['data'] ? $m['data'] : '(do ustalenia)' );
		if ( $m['forma'] ) { $l[] = 'Forma: ' . $m['forma']; }
		return $l;
	}

	private static function notify_admin( $post_id, $m ) {
		$to    = get_option( 'admin_email' );
		$edit  = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		$subj  = 'Perki: nowa prośba o lekcję od ' . $m['name'];
		$lines = array_merge(
			array(
				'Nowa prośba o termin lekcji z perki.pl/' . self::SLUG . '/',
				'Podgląd w panelu: ' . $edit,
				'',
				'Uczeń: ' . $m['name'],
				'E-mail: ' . $m['email'] . ' (Odpowiedz = mail do ucznia)',
				'Telefon: ' . ( $m['phone'] ? $m['phone'] : '(brak)' ),
			),
			self::summary_lines( $m ),
			array(
				'Wiadomość: ' . ( $m['wiadomosc'] ? $m['wiadomosc'] : '(brak)' ),
				'',
				'Potwierdź termin i wyślij uczniowi link do płatności (BLIK/Przelewy24/karta).',
			)
		);
		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'Reply-To: ' . self::mail_name( $m['name'] ) . ' <' . $m['email'] . '>',
		);
		return wp_mail( $to, $subj, implode( "\n", $lines ), $headers );
	}

	/**
	 * Potwierdzenie przyjecia prosby do ucznia (Odpowiedz trafia do Jacke).
	 * Antyspam: mail idzie na adres podany w formularzu, wiec NIE zawiera zadnego tekstu od
	 * nadawcy (ani imienia, ani wiadomosci): tylko nazwe pakietu z business.json i termin
	 * zwalidowany przez serwer. Limity: 1 mail / adres / 24 h i CONFIRM_DAY dziennie na strone.
	 */
	private static function confirm_student( $m, $prod_id, $term_valid ) {
		$rkey = 'perki_lekcja_to_' . md5( strtolower( $m['email'] ) );
		$dkey = 'perki_lekcja_conf_' . gmdate( 'Ymd' );
		$day  = (int) get_transient( $dkey );
		if ( get_transient( $rkey ) || $day >= self::CONFIRM_DAY ) { return false; }
		set_transient( $rkey, 1, DAY_IN_SECONDS );
		set_transient( $dkey, $day + 1, DAY_IN_SECONDS );

		$b     = self::business();
		$reply = isset( $b['email'] ) ? $b['email'] : get_option( 'admin_email' );
		$h     = isset( $b['zasada_zmiany_terminu_h'] ) ? (int) $b['zasada_zmiany_terminu_h'] : 24;
		$known = '';
		foreach ( (array) ( isset( $b['oferta'] ) ? $b['oferta'] : array() ) as $o ) {
			if ( $o['id'] === $prod_id ) { $known = $o['nazwa']; }
		}
		$lines = array( 'Cześć,', '', 'dziękuję za prośbę o lekcję perkusji na perki.pl.', '' );
		if ( $known ) { $lines[] = 'Pakiet: ' . $known; }
		if ( $m['len'] ) { $lines[] = 'Długość: ' . (int) $m['len'] . ' min'; }
		$lines[] = 'Preferowany termin: ' . ( $term_valid ? $m['data'] : 'do ustalenia' );
		if ( $m['forma'] ) { $lines[] = 'Forma: ' . $m['forma']; }
		$lines = array_merge( $lines, array(
			'',
			'voucher' === $prod_id
				? 'Co dalej: odezwę się w sprawie vouchera i wyślę link do płatności (BLIK, Przelewy24 albo karta).'
				: 'Co dalej: sprawdzę dostępność, potwierdzę termin i wyślę link do płatności (BLIK, Przelewy24 albo karta). Termin jest Twój po opłaceniu. Zmienisz go bezpłatnie do ' . $h . ' h przed lekcją.',
			'',
			'Masz pytanie? Odpowiedz na tego maila albo zadzwoń: ' . ( isset( $b['telefon_wyswietlany'] ) ? $b['telefon_wyswietlany'] : '' ) . '.',
			'W międzyczasie możesz poćwiczyć rytm w darmowej grze: ' . home_url( '/gra/' ),
			'',
			'Do usłyszenia,',
			'Jacke, perki.pl',
			'',
			'Ta wiadomość jest odpowiedzią na formularz na stronie ' . self::page_url() . '. Jeśli to nie Ty, zignoruj ją.',
		) );
		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'Reply-To: perki.pl <' . $reply . '>',
		);
		return wp_mail( $m['email'], 'perki.pl: mam Twoją prośbę o lekcję', implode( "\n", $lines ), $headers );
	}

	/** Nazwa do naglowka maila: bez znakow, ktore moglyby wstrzyknac naglowki. */
	private static function mail_name( $name ) {
		return trim( preg_replace( '/[^\p{L}\p{N} .\-]/u', '', $name ) );
	}

	/* ------------------------------------------------------------- ADMIN UI */

	public static function admin_columns( $cols ) {
		return array(
			'cb'        => isset( $cols['cb'] ) ? $cols['cb'] : '',
			'title'     => 'Prosba',
			'pl_email'  => 'E-mail',
			'pl_phone'  => 'Telefon',
			'pl_prod'   => 'Pakiet',
			'pl_term'   => 'Preferowany termin',
			'pl_forma'  => 'Forma',
			'date'      => 'Zgloszono',
		);
	}

	public static function admin_column_value( $col, $post_id ) {
		$map = array( 'pl_email' => 'email', 'pl_phone' => 'phone', 'pl_prod' => 'produkt', 'pl_forma' => 'forma' );
		if ( isset( $map[ $col ] ) ) { echo esc_html( get_post_meta( $post_id, self::META . $map[ $col ], true ) ); return; }
		if ( 'pl_term' === $col ) {
			echo esc_html( get_post_meta( $post_id, self::META . 'data', true ) );
		}
	}

	public static function meta_box() {
		add_meta_box( 'perki_prosba_box', 'Dane prosby', array( __CLASS__, 'render_meta_box' ), self::CPT, 'normal', 'high' );
	}

	public static function render_meta_box( $post ) {
		$labels = array(
			'name' => 'Imie', 'email' => 'E-mail', 'phone' => 'Telefon', 'produkt' => 'Pakiet',
			'len' => 'Dlugosc (min)', 'data' => 'Preferowany termin', 'forma' => 'Forma', 'wiadomosc' => 'Wiadomosc',
		);
		echo '<table class="widefat striped"><tbody>';
		foreach ( $labels as $k => $label ) {
			$v = get_post_meta( $post->ID, self::META . $k, true );
			echo '<tr><th style="width:200px;text-align:left">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $v ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}

Perki_Lekcje_Jasny::init();

register_activation_hook( __FILE__, function () {
	Perki_Lekcje_Jasny::register_cpt();
	Perki_Lekcje_Jasny::register_rewrite();
	flush_rewrite_rules();
	do_action( 'litespeed_purge_all' );
} );

register_deactivation_hook( __FILE__, function () {
	global $wp_rewrite;
	unset( $wp_rewrite->extra_rules_top['^lekcje-nowy/?$'] );
	delete_option( 'perki_lekcje_jasny_ver' );
	flush_rewrite_rules();
} );
