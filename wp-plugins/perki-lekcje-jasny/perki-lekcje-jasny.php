<?php
/**
 * Plugin Name: Perki Lekcje (jasny landing)
 * Description: Jasny, sprzedazowy landing lekcji perkusji pod adresem /lekcje-nowy/ serwowany headless (pelny dokument, bez motywu i Elementora). Zrodlo prawdy: landing.html. Przyjmuje prosby o termin (CPT "prosba_lekcja" + mail). Platnosc Stripe i Kalendarz Google dokladane w kolejnych modulach.
 * Version: 1.1.0
 * Author: perki.pl
 * License: GPL-2.0-or-later
 * Text Domain: perki-lekcje-jasny
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Perki_Lekcje_Jasny {

	const VERSION   = '1.1.0';            // zmiana = auto purge LiteSpeed
	const QV        = 'perki_lekcje_jasny';
	const SLUG      = 'lekcje-nowy';      // docelowo mozna przelaczyc na 'lekcje'
	const PAGE_FILE = 'landing.html';     // jedyne zrodlo prawdy (DRY)

	const CPT          = 'prosba_lekcja';
	const META         = '_pl_';
	const NS           = 'perki/v1';
	const THROTTLE_MAX = 20;              // backstop antyspamowy per IP
	const THROTTLE_WIN = 3600;
	const MAX_BODY     = 8192;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_action( 'init', array( __CLASS__, 'maybe_purge_on_update' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_page' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'admin_column_value' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
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
		if ( get_option( 'perki_lekcje_jasny_ver' ) !== self::VERSION ) {
			update_option( 'perki_lekcje_jasny_ver', self::VERSION );
			do_action( 'litespeed_purge_all' );
			flush_rewrite_rules();
		}
	}

	/** Landing to kompletny dokument HTML (wlasny nav i footer) serwowany headless. */
	public static function serve_page() {
		if ( '1' !== get_query_var( self::QV ) ) { return; }

		$path = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH );
		if ( ! preg_match( '#^/' . preg_quote( self::SLUG, '#' ) . '/?$#', $path ) ) { return; }

		if ( ! defined( 'LITESPEED_NO_OPTM' ) ) { define( 'LITESPEED_NO_OPTM', true ); }

		$file = plugin_dir_path( __FILE__ ) . self::PAGE_FILE;
		if ( ! file_exists( $file ) ) {
			status_header( 404 );
			exit( 'Brak pliku landingu. Zainstaluj wtyczke perki-lekcje-jasny ponownie.' );
		}

		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		readfile( $file );
		exit;
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
				$res = new WP_REST_Response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ), 200 );
				$res->header( 'Cache-Control', 'no-store' );
				return $res;
			},
		) );

		register_rest_route( self::NS, '/prosba', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'handle_prosba' ),
		) );
	}

	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return sanitize_text_field( $ip );
	}

	public static function handle_prosba( WP_REST_Request $request ) {

		$ctype = (string) $request->get_header( 'content_type' );
		if ( false === stripos( $ctype, 'application/json' ) ) {
			return new WP_Error( 'pl_ctype', 'Wymagany Content-Type: application/json.', array( 'status' => 415 ) );
		}
		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY ) {
			return new WP_Error( 'pl_big', 'Payload za duzy.', array( 'status' => 413 ) );
		}

		$hp = (string) $request->get_param( '_hp' );
		if ( '' !== trim( $hp ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'id' => 0 ), 200 );
		}

		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( empty( $nonce ) ) { $nonce = (string) $request->get_param( '_wpnonce' ); }
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'pl_nonce', 'Sesja wygasla. Odswiez strone i sprobuj ponownie.', array( 'status' => 403 ) );
		}

		$ip   = self::client_ip();
		$tkey = 'perki_lekcja_thr_' . md5( $ip );
		$cnt  = (int) get_transient( $tkey );
		if ( $cnt >= self::THROTTLE_MAX ) {
			return new WP_Error( 'pl_throttle', 'Za duzo prob. Sprobuj ponownie za chwile.', array( 'status' => 429 ) );
		}

		$name  = mb_substr( sanitize_text_field( (string) $request->get_param( 'name' ) ), 0, 80 );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone = mb_substr( sanitize_text_field( (string) $request->get_param( 'phone' ) ), 0, 30 );
		$prod  = mb_substr( sanitize_text_field( (string) $request->get_param( 'produkt' ) ), 0, 40 );
		$len   = absint( $request->get_param( 'len' ) );
		$date  = mb_substr( sanitize_text_field( (string) $request->get_param( 'data' ) ), 0, 40 );
		$time  = mb_substr( sanitize_text_field( (string) $request->get_param( 'godzina' ) ), 0, 10 );
		$mode  = mb_substr( sanitize_text_field( (string) $request->get_param( 'forma' ) ), 0, 30 );

		if ( '' === $name || ! is_email( $email ) ) {
			return new WP_Error( 'pl_required', 'Podaj imie i poprawny e-mail.', array( 'status' => 400 ) );
		}
		$len = min( max( $len, 0 ), 240 );

		$title   = sprintf( '%s, %s %s (%s)', $name, $date ? $date : 'termin do ustalenia', $time, $prod ? $prod : 'lekcja' );
		$post_id = wp_insert_post( array(
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			'post_title'  => wp_strip_all_tags( $title ),
		), true );
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'pl_save', 'Nie udalo sie zapisac prosby.', array( 'status' => 500 ) );
		}

		$meta = array(
			'name' => $name, 'email' => $email, 'phone' => $phone, 'produkt' => $prod,
			'len' => $len, 'data' => $date, 'godzina' => $time, 'forma' => $mode, 'ip' => $ip,
		);
		foreach ( $meta as $k => $v ) { update_post_meta( $post_id, self::META . $k, $v ); }

		set_transient( $tkey, $cnt + 1, self::THROTTLE_WIN );
		self::notify_admin( $post_id, $meta );

		return new WP_REST_Response( array( 'ok' => true, 'id' => $post_id ), 200 );
	}

	private static function notify_admin( $post_id, $m ) {
		$to   = get_option( 'admin_email' );
		$edit = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		$subj = 'Perki: nowa prosba o lekcje od ' . $m['name'];
		$lines = array(
			'Nowa prosba o termin lekcji z perki.pl/' . self::SLUG . '/',
			'Podglad w panelu: ' . $edit,
			'',
			'Uczen: ' . $m['name'],
			'E-mail: ' . $m['email'],
			'Telefon: ' . ( $m['phone'] ? $m['phone'] : '(brak)' ),
			'Pakiet: ' . ( $m['produkt'] ? $m['produkt'] : 'lekcja' ),
			'Dlugosc: ' . ( $m['len'] ? $m['len'] . ' min' : '(do ustalenia)' ),
			'Preferowany termin: ' . ( $m['data'] ? $m['data'] : '(do ustalenia)' ) . ' ' . $m['godzina'],
			'Forma: ' . ( $m['forma'] ? $m['forma'] : '(do ustalenia)' ),
			'',
			'Potwierdz termin i wyslij uczniowi link do platnosci (BLIK/Przelewy24/karta).',
			'IP: ' . $m['ip'],
		);
		wp_mail( $to, $subj, implode( "\n", $lines ) );
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
			'date'      => 'Zgloszono',
		);
	}

	public static function admin_column_value( $col, $post_id ) {
		$map = array( 'pl_email' => 'email', 'pl_phone' => 'phone', 'pl_prod' => 'produkt' );
		if ( isset( $map[ $col ] ) ) { echo esc_html( get_post_meta( $post_id, self::META . $map[ $col ], true ) ); return; }
		if ( 'pl_term' === $col ) {
			echo esc_html( trim( get_post_meta( $post_id, self::META . 'data', true ) . ' ' . get_post_meta( $post_id, self::META . 'godzina', true ) ) );
		}
	}

	public static function meta_box() {
		add_meta_box( 'perki_prosba_box', 'Dane prosby', array( __CLASS__, 'render_meta_box' ), self::CPT, 'normal', 'high' );
	}

	public static function render_meta_box( $post ) {
		$labels = array(
			'name' => 'Imie', 'email' => 'E-mail', 'phone' => 'Telefon', 'produkt' => 'Pakiet',
			'len' => 'Dlugosc (min)', 'data' => 'Preferowany dzien', 'godzina' => 'Preferowana godzina',
			'forma' => 'Forma', 'ip' => 'IP',
		);
		echo '<table class="widefat striped"><tbody>';
		foreach ( $labels as $k => $label ) {
			$v = get_post_meta( $post->ID, self::META . $k, true );
			echo '<tr><th style="width:200px;text-align:left">' . esc_html( $label ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
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
