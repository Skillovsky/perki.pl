<?php
/**
 * Plugin Name: Perki Home (nowa strona glowna)
 * Description: Nowa, jasna strona glowna perki.pl w systemie "Studio Groove" - hub spinajacy lekcje, sklep, gre Drum Hero, filmy i kontakt. Serwowana headless (pelny dokument, bez motywu i Elementora). Zrodlo prawdy: home.html. Domyslnie pod /start/ do podgladu live; przelacznik na strone glowna w stalej SET_AS_FRONT.
 * Version: 1.0.0
 * Author: perki.pl
 * License: GPL-2.0-or-later
 * Text Domain: perki-home
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Perki_Home {

	const VERSION   = '1.0.0';        // zmiana = auto purge LiteSpeed
	const QV        = 'perki_home';
	const SLUG      = 'start';         // podglad live; ustaw '' + SET_AS_FRONT=true by przejac strone glowna
	const PAGE_FILE = 'home.html';     // jedyne zrodlo prawdy (DRY)

	// Gdy true: dokument serwowany rowniez na stronie glownej ('/'),
	// nadpisujac front WordPressa (po akceptacji wersji /start/).
	const SET_AS_FRONT = false;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_action( 'init', array( __CLASS__, 'maybe_purge_on_update' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_page' ), 0 );
	}

	public static function register_rewrite() {
		if ( '' !== self::SLUG ) {
			add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?' . self::QV . '=1', 'top' );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QV;
		return $vars;
	}

	public static function maybe_purge_on_update() {
		if ( get_option( 'perki_home_ver' ) !== self::VERSION ) {
			update_option( 'perki_home_ver', self::VERSION );
			do_action( 'litespeed_purge_all' );
			flush_rewrite_rules();
		}
	}

	private static function is_front_request() {
		if ( ! self::SET_AS_FRONT ) { return false; }
		if ( is_admin() ) { return false; }
		$path = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH );
		return ( '/' === $path || '' === $path );
	}

	/** home.html to kompletny dokument HTML (wlasny nav i footer) serwowany headless. */
	public static function serve_page() {
		$is_slug  = ( '1' === get_query_var( self::QV ) );
		$is_front = self::is_front_request();
		if ( ! $is_slug && ! $is_front ) { return; }

		if ( $is_slug ) {
			$path = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH );
			if ( ! preg_match( '#^/' . preg_quote( self::SLUG, '#' ) . '/?$#', $path ) ) { return; }
		}

		if ( ! defined( 'LITESPEED_NO_OPTM' ) ) { define( 'LITESPEED_NO_OPTM', true ); }

		$file = plugin_dir_path( __FILE__ ) . self::PAGE_FILE;
		if ( ! file_exists( $file ) ) {
			status_header( 404 );
			exit( 'Brak pliku strony glownej. Zainstaluj wtyczke perki-home ponownie.' );
		}

		// Headless w 100%: czyscimy wszystkie bufory wyjscia, zeby zaden plugin
		// (lazyload, placeholdery obrazow, Autoptimize) nie przerobil dokumentu.
		while ( ob_get_level() > 0 ) { ob_end_clean(); }

		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Perki-Headless: 1' );
		readfile( $file );
		exit;
	}
}

Perki_Home::init();

register_activation_hook( __FILE__, function () {
	Perki_Home::register_rewrite();
	flush_rewrite_rules();
	do_action( 'litespeed_purge_all' );
} );

register_deactivation_hook( __FILE__, function () {
	global $wp_rewrite;
	unset( $wp_rewrite->extra_rules_top['^start/?$'] );
	delete_option( 'perki_home_ver' );
	flush_rewrite_rules();
} );
