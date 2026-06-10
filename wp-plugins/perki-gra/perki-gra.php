<?php
/**
 * Plugin Name: Perki Gra (Drum Hero)
 * Description: Serwuje gre Perki Drum Hero pod adresem /gra/ (pelny ekran, bez motywu) i zapisuje wyniki graczy WEWNATRZ WordPress jako CPT "wynik_gry" + powiadomienie mailem. Endpointy: GET /perki/v1/gra-ping (nonce), POST /perki/v1/wynik.
 * Version: 1.1.0
 * Author: perki.pl
 * License: GPL-2.0-or-later
 * Text Domain: perki-gra
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Perki_Gra {

	const CPT       = 'wynik_gry';
	const META      = '_pg_';            // prefix metadanych pol
	const NS        = 'perki/v1';
	const QV        = 'perki_gra';       // query var strony /gra/
	const GAME_FILE = 'perki-drum-hero.html'; // jedyne zrodlo prawdy gry (DRY)

	const VERSION      = '1.1.0'; // zmiana wersji = automatyczny purge cache LiteSpeed
	const THROTTLE_MAX = 120;   // backstop antyspamowy per IP, nie limiter UX (utwor trwa ~30-40 s)
	const THROTTLE_WIN = 3600;  // okno throttlingu w sekundach (1h)
	const MAX_BODY     = 8192;  // limit wielkosci payloadu JSON w bajtach

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_action( 'init', array( __CLASS__, 'maybe_purge_on_update' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_game' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Panel admina: czytelna lista wynikow i podglad wpisu.
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'admin_column_value' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
	}

	/* ----------------------------------------------------------------- CPT */

	public static function register_cpt() {
		register_post_type( self::CPT, array(
			'labels' => array(
				'name'          => 'Wyniki gry',
				'singular_name' => 'Wynik gry',
				'menu_name'     => 'Wyniki gry',
				'all_items'     => 'Wszystkie wyniki',
				'edit_item'     => 'Wynik gry',
				'search_items'  => 'Szukaj wynikow',
				'not_found'     => 'Brak wynikow',
			),
			'public'             => false,   // wyniki moga zawierac imie i e-mail (PII)
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => false,   // nie wystawiamy wynikow publicznie przez REST
			'menu_icon'          => 'dashicons-awards',
			'menu_position'      => 27,
			'capability_type'    => 'post',
			'supports'           => array( 'title' ),
		) );
	}

	/* ------------------------------------------------------- STRONA /gra/ */

	public static function register_rewrite() {
		add_rewrite_rule( '^gra/?$', 'index.php?' . self::QV . '=1', 'top' );
	}

	/** Republikacja pliku gry (nowa wersja wtyczki) musi wyczyscic cache LiteSpeed. */
	public static function maybe_purge_on_update() {
		if ( get_option( 'perki_gra_ver' ) !== self::VERSION ) {
			update_option( 'perki_gra_ver', self::VERSION );
			do_action( 'litespeed_purge_all' );
			flush_rewrite_rules();
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QV;
		return $vars;
	}

	/** Gra to kompletny dokument HTML, wiec podajemy go wprost, bez motywu. */
	public static function serve_game() {
		if ( '1' !== get_query_var( self::QV ) ) { return; }

		// Tylko sciezka /gra/ (query var jest publiczny, ?perki_gra=1 na innych URL ignorujemy).
		$path = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH );
		if ( ! preg_match( '#^/gra/?$#', $path ) ) { return; }

		// Inline skrypt gry nie moze przejsc przez Page Optimization LiteSpeed
		// (minify/defer/delay JS zabija deep-link ?level=N); page cache zostaje.
		if ( ! defined( 'LITESPEED_NO_OPTM' ) ) { define( 'LITESPEED_NO_OPTM', true ); }

		$file = plugin_dir_path( __FILE__ ) . self::GAME_FILE;
		if ( ! file_exists( $file ) ) {
			status_header( 404 );
			exit( 'Brak pliku gry. Zainstaluj wtyczke perki-gra ponownie.' );
		}

		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Frame-Options: SAMEORIGIN' ); // osadzanie po lekcjach kursu = ta sama domena
		readfile( $file );
		exit;
	}

	/* -------------------------------------------------------------- ROUTES */

	public static function register_routes() {
		// Swiezy nonce pobierany tuz przed wysylka wyniku (omija pelne cache strony).
		// Nazwa /gra-ping, zeby nie kolidowac z /ping wtyczki perki-instruktor.
		register_rest_route( self::NS, '/gra-ping', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function () {
				$res = new WP_REST_Response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ), 200 );
				$res->header( 'Cache-Control', 'no-store' );
				return $res;
			},
		) );

		// Przyjecie wyniku gry (takze z imieniem i e-mailem z formularza rankingu).
		register_rest_route( self::NS, '/wynik', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true', // endpoint publiczny; zabezpieczenia ponizej
			'callback'            => array( __CLASS__, 'handle_wynik' ),
		) );
	}

	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return sanitize_text_field( $ip );
	}

	public static function handle_wynik( WP_REST_Request $request ) {

		// 1) Tylko application/json (odrzucamy formularze spambotow i preflight-smieci).
		$ctype = (string) $request->get_header( 'content_type' );
		if ( false === stripos( $ctype, 'application/json' ) ) {
			return new WP_Error( 'perki_ctype', 'Wymagany Content-Type: application/json.', array( 'status' => 415 ) );
		}

		// 2) Limit wielkosci payloadu.
		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY ) {
			return new WP_Error( 'perki_too_big', 'Payload za duzy.', array( 'status' => 413 ) );
		}

		// 3) Honeypot: prawdziwy gracz nie wypelni ukrytego pola _hp.
		$hp = (string) $request->get_param( '_hp' );
		if ( '' !== trim( $hp ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'id' => 0 ), 200 ); // cicho udajemy sukces
		}

		// 4) Nonce 'wp_rest' (pobrany przez /gra-ping). Dziala tez dla wylogowanych.
		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( empty( $nonce ) ) { $nonce = (string) $request->get_param( '_wpnonce' ); }
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'perki_bad_nonce', 'Sesja wygasla. Odswiez strone gry i sprobuj ponownie.', array( 'status' => 403 ) );
		}

		// 5) Throttling per IP (transient).
		$ip    = self::client_ip();
		$tkey  = 'perki_gra_thr_' . md5( $ip );
		$count = (int) get_transient( $tkey );
		if ( $count >= self::THROTTLE_MAX ) {
			return new WP_Error( 'perki_throttle', 'Za duzo zapisow. Sprobuj ponownie za godzine.', array( 'status' => 429 ) );
		}

		// 6) Walidacja typow + sanityzacja. Liczby przycinamy do sensownych zakresow.
		$song  = absint( $request->get_param( 'song' ) );
		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$score = absint( $request->get_param( 'score' ) );
		$acc   = absint( $request->get_param( 'acc' ) );
		$stars = absint( $request->get_param( 'stars' ) );
		$xp    = absint( $request->get_param( 'xp' ) );

		if ( $song < 1 || $song > 99 ) {
			return new WP_Error( 'perki_song', 'Nieprawidlowy numer utworu.', array( 'status' => 400 ) );
		}
		$title = mb_substr( $title, 0, 120 );
		$score = min( $score, 1000000 );
		$acc   = min( $acc, 100 );
		$stars = min( $stars, 3 );
		$xp    = min( $xp, 10000000 );

		// 7) Pola rankingu (lejek): oba albo zadne; e-mail musi byc poprawny.
		$name  = mb_substr( sanitize_text_field( (string) $request->get_param( 'name' ) ), 0, 80 );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$is_lead = ( '' !== $name || '' !== (string) $request->get_param( 'email' ) );
		if ( $is_lead && ( '' === $name || ! is_email( $email ) ) ) {
			return new WP_Error( 'perki_lead', 'Podaj imie i poprawny e-mail.', array( 'status' => 400 ) );
		}

		// 8) Zapis jako CPT + metadane.
		$label = $is_lead ? $name : 'Anonim';
		$post_title = sprintf( 'LVL %d: %s, %d pkt, %d gwiazdki (%s)', $song, $title, $score, $stars, $label );
		$post_id = wp_insert_post( array(
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			'post_title'  => wp_strip_all_tags( $post_title ),
		), true );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'perki_save', 'Nie udalo sie zapisac wyniku.', array( 'status' => 500 ) );
		}

		$meta = array(
			'song' => $song, 'tytul' => $title, 'score' => $score, 'acc' => $acc,
			'stars' => $stars, 'xp' => $xp, 'name' => $name, 'email' => $email,
			'lead' => $is_lead ? 'tak' : 'nie', 'ip' => $ip,
		);
		foreach ( $meta as $k => $v ) { update_post_meta( $post_id, self::META . $k, $v ); }

		// 9) Throttle ++.
		set_transient( $tkey, $count + 1, self::THROTTLE_WIN );

		// 10) Powiadomienie mailem do admina. Lead: zawsze. Wynik anonimowy:
		// najwyzej 1 mail na godzine per IP (gracz konczy utwor co ~30 s, bez
		// tego limitu kazda sesja gry zalewa skrzynke admina).
		$mkey = 'perki_gra_mail_' . md5( $ip );
		if ( $is_lead || false === get_transient( $mkey ) ) {
			if ( ! $is_lead ) { set_transient( $mkey, 1, HOUR_IN_SECONDS ); }
			self::notify_admin( $post_id, $meta );
		}

		return new WP_REST_Response( array( 'ok' => true, 'id' => $post_id ), 200 );
	}

	private static function notify_admin( $post_id, $m ) {
		$to   = get_option( 'admin_email' );
		$edit = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

		if ( 'tak' === $m['lead'] ) {
			$subject = 'Perki Drum Hero: nowy gracz w rankingu: ' . $m['name'];
		} else {
			$subject = sprintf( 'Perki Drum Hero: nowy wynik LVL %d (%d pkt)', $m['song'], $m['score'] );
		}

		$lines   = array();
		$lines[] = 'Nowy wynik z gry Perki Drum Hero (perki.pl/gra/)';
		$lines[] = 'Podglad w panelu: ' . $edit;
		$lines[] = '';
		$lines[] = 'Utwor: LVL ' . $m['song'] . ': ' . $m['tytul'];
		$lines[] = 'Wynik: ' . $m['score'] . ' pkt';
		$lines[] = 'Celnosc: ' . $m['acc'] . '%';
		$lines[] = 'Gwiazdki: ' . $m['stars'] . '/3';
		$lines[] = 'XP gracza: ' . $m['xp'];
		$lines[] = 'Gracz: ' . ( 'tak' === $m['lead'] ? $m['name'] . ' <' . $m['email'] . '>' : '(anonimowy)' );
		$lines[] = 'IP: ' . $m['ip'];

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/* ------------------------------------------------------------- ADMIN UI */

	public static function admin_columns( $cols ) {
		return array(
			'cb'          => isset( $cols['cb'] ) ? $cols['cb'] : '',
			'title'       => 'Wynik',
			'pg_gracz'    => 'Gracz',
			'pg_email'    => 'E-mail',
			'pg_score'    => 'Punkty',
			'pg_acc'      => 'Celnosc',
			'pg_stars'    => 'Gwiazdki',
			'date'        => 'Data',
		);
	}

	public static function admin_column_value( $col, $post_id ) {
		$map = array(
			'pg_gracz' => 'name', 'pg_email' => 'email', 'pg_score' => 'score',
			'pg_acc' => 'acc', 'pg_stars' => 'stars',
		);
		if ( ! isset( $map[ $col ] ) ) { return; }
		$v = get_post_meta( $post_id, self::META . $map[ $col ], true );
		if ( 'pg_acc' === $col && '' !== $v )   { $v .= '%'; }
		if ( 'pg_stars' === $col && '' !== $v ) { $v = str_repeat( '*', (int) $v ); }
		if ( 'pg_gracz' === $col && '' === $v ) { $v = '(anonimowy)'; }
		echo esc_html( $v );
	}

	public static function meta_box() {
		add_meta_box( 'perki_wynik_box', 'Dane wyniku', array( __CLASS__, 'render_meta_box' ), self::CPT, 'normal', 'high' );
	}

	public static function render_meta_box( $post ) {
		$labels = array(
			'song' => 'Poziom (LVL)', 'tytul' => 'Utwor', 'score' => 'Punkty', 'acc' => 'Celnosc (%)',
			'stars' => 'Gwiazdki (0-3)', 'xp' => 'XP gracza', 'name' => 'Imie', 'email' => 'E-mail',
			'lead' => 'Zapis do rankingu', 'ip' => 'IP',
		);
		echo '<table class="widefat striped"><tbody>';
		foreach ( $labels as $k => $label ) {
			$v = get_post_meta( $post->ID, self::META . $k, true );
			echo '<tr><th style="width:200px;text-align:left;vertical-align:top">' . esc_html( $label ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}

Perki_Gra::init();

register_activation_hook( __FILE__, function () {
	Perki_Gra::register_cpt();
	Perki_Gra::register_rewrite();
	flush_rewrite_rules();
	do_action( 'litespeed_purge_all' ); // jesli LiteSpeed aktywny, czysci cache po wdrozeniu
} );

register_deactivation_hook( __FILE__, function () {
	// Regula zostala dodana na init tego requestu; usuwamy ja przed flushem,
	// inaczej /gra/ po deaktywacji serwowaloby strone glowna zamiast 404.
	global $wp_rewrite;
	unset( $wp_rewrite->extra_rules_top['^gra/?$'] );
	delete_option( 'perki_gra_ver' );
	flush_rewrite_rules();
} );
