<?php
/**
 * Minimalny WordPress-stub do testow E2E wtyczki perki-lekcje-jasny bez bazy i bez WP.
 * Uruchomienie: php -S 127.0.0.1:8787 -t <docroot> tests/wp-stub/router.php
 * (tests/run.sh sklada docroot: <docroot>/wp-content/plugins/perki-lekcje-jasny -> repo).
 * Stan (opcje, transienty, posty, maile) trzymany w plikach JSON w PERKI_STUB_STATE.
 * Endpointy diagnostyczne tylko dla testow: /__state (GET), /__reset (POST).
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
date_default_timezone_set( 'UTC' );

$STATE_DIR = getenv( 'PERKI_STUB_STATE' ) ?: sys_get_temp_dir() . '/perki-stub';
@mkdir( $STATE_DIR, 0777, true );
$PLUGIN_DIR = getenv( 'PERKI_PLUGIN_DIR' ) ?: dirname( __DIR__, 2 ) . '/wp-plugins/perki-lekcje-jasny';
$HOME = 'http://' . ( $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8787' );

/* ------------------------------------------------------------ static files */
$uri  = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url( $uri, PHP_URL_PATH );
if ( preg_match( '#^/wp-content/#', $path ) && is_file( $_SERVER['DOCUMENT_ROOT'] . $path ) ) {
	return false; // wbudowany serwer PHP serwuje plik
}

/* ------------------------------------------------------------ state */
function stub_load( $k ) { global $STATE_DIR; $f = "$STATE_DIR/$k.json"; return is_file( $f ) ? json_decode( file_get_contents( $f ), true ) : array(); }
function stub_save( $k, $v ) { global $STATE_DIR; file_put_contents( "$STATE_DIR/$k.json", json_encode( $v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); }

if ( '/__reset' === $path ) { foreach ( glob( "$STATE_DIR/*.json" ) as $f ) { unlink( $f ); } header( 'Content-Type: application/json' ); echo '{"ok":true}'; return true; }
if ( '/__state' === $path ) {
	header( 'Content-Type: application/json' );
	echo json_encode( array( 'options' => stub_load( 'options' ), 'posts' => stub_load( 'posts' ), 'meta' => stub_load( 'meta' ), 'mails' => stub_load( 'mails' ), 'actions' => stub_load( 'actions' ) ), JSON_UNESCAPED_UNICODE );
	return true;
}

/* ------------------------------------------------------------ hooks */
$GLOBALS['stub_hooks'] = array();
function add_action( $h, $cb, $prio = 10, $args = 1 ) { $GLOBALS['stub_hooks'][ $h ][ $prio ][] = array( $cb, $args ); return true; }
function add_filter( $h, $cb, $prio = 10, $args = 1 ) { return add_action( $h, $cb, $prio, $args ); }
function do_action( $h, ...$a ) {
	$log = stub_load( 'actions' ); $log[] = $h; stub_save( 'actions', $log );
	if ( empty( $GLOBALS['stub_hooks'][ $h ] ) ) { return; }
	ksort( $GLOBALS['stub_hooks'][ $h ] );
	foreach ( $GLOBALS['stub_hooks'][ $h ] as $cbs ) { foreach ( $cbs as $c ) { call_user_func_array( $c[0], array_slice( $a, 0, $c[1] ) ); } }
}
function apply_filters( $h, $v, ...$a ) {
	if ( empty( $GLOBALS['stub_hooks'][ $h ] ) ) { return $v; }
	ksort( $GLOBALS['stub_hooks'][ $h ] );
	foreach ( $GLOBALS['stub_hooks'][ $h ] as $cbs ) { foreach ( $cbs as $c ) { $v = call_user_func_array( $c[0], array_slice( array_merge( array( $v ), $a ), 0, $c[1] ) ); } }
	return $v;
}
function register_activation_hook( $f, $cb ) {}
function register_deactivation_hook( $f, $cb ) {}

/* ------------------------------------------------------------ core API used by the plugin */
function plugin_dir_path( $f ) { global $PLUGIN_DIR; return rtrim( $PLUGIN_DIR, '/' ) . '/'; }
function home_url( $p = '' ) { global $HOME; return $HOME . ( $p ? '/' . ltrim( $p, '/' ) : '/' ); }
function rest_url( $p = '' ) { return home_url( '/wp-json/' . ltrim( $p, '/' ) ); }
function admin_url( $p = '' ) { return home_url( '/wp-admin/' . ltrim( $p, '/' ) ); }
function get_privacy_policy_url() { return getenv( 'PERKI_PRIVACY' ) ?: ''; }
function is_admin() { return false; }
function get_option( $k, $d = false ) { $o = stub_load( 'options' ); if ( 'admin_email' === $k ) { return 'admin@perki.test'; } if ( 'blog_public' === $k ) { return '1'; } return array_key_exists( $k, $o ) ? $o[ $k ] : $d; }
function update_option( $k, $v ) { $o = stub_load( 'options' ); $o[ $k ] = $v; stub_save( 'options', $o ); return true; }
function delete_option( $k ) { $o = stub_load( 'options' ); unset( $o[ $k ] ); stub_save( 'options', $o ); return true; }
function get_transient( $k ) { $t = stub_load( 'transients' ); return ( isset( $t[ $k ] ) && $t[ $k ]['exp'] > time() ) ? $t[ $k ]['v'] : false; }
function set_transient( $k, $v, $ttl ) { $t = stub_load( 'transients' ); $t[ $k ] = array( 'v' => $v, 'exp' => time() + $ttl ); stub_save( 'transients', $t ); return true; }
function register_post_type( $t, $a ) {}
function add_rewrite_rule( $r, $q, $p ) { $GLOBALS['stub_rewrites'][ $r ] = $q; }
function flush_rewrite_rules() {}
function add_meta_box() {}
function get_query_var( $k ) { return $GLOBALS['stub_qv'][ $k ] ?? ''; }
function status_header( $c ) { http_response_code( $c ); }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $s ) { $s = strip_tags( (string) $s ); $s = preg_replace( '/[\r\n\t ]+/', ' ', $s ); return trim( $s ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $e ) { return preg_replace( '/[^a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~@-]/', '', (string) $e ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function esc_url( $u ) { return htmlspecialchars( $u, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_create_nonce( $a ) { return substr( hash_hmac( 'sha256', $a . floor( time() / 43200 ), 'stub-salt' ), 0, 10 ); }
function wp_verify_nonce( $n, $a ) { return is_string( $n ) && '' !== $n && hash_equals( wp_create_nonce( $a ), $n ) ? 1 : false; }
function wp_timezone() { return new DateTimeZone( 'Europe/Warsaw' ); }
function wp_timezone_string() { return 'Europe/Warsaw'; }
function current_user_can( $c ) { return true; }
function wp_date( $fmt, $ts, $tz ) {
	$d    = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
	$days = array( 'niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota' );
	$mon  = array( 1 => 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia' );
	return strtr( $d->format( str_replace( array( 'l', 'F' ), array( '\\L\\L', '\\M\\M' ), $fmt ) ), array( 'LL' => $days[ (int) $d->format( 'w' ) ], 'MM' => $mon[ (int) $d->format( 'n' ) ] ) );
}
function wp_insert_post( $a, $err = false ) { $p = stub_load( 'posts' ); $id = 1000 + count( $p ); $a['ID'] = $id; $p[] = $a; stub_save( 'posts', $p ); return $id; }
function update_post_meta( $id, $k, $v ) { $m = stub_load( 'meta' ); $m[ $id ][ $k ] = $v; stub_save( 'meta', $m ); }
function get_post_meta( $id, $k, $s = false ) { $m = stub_load( 'meta' ); return $m[ $id ][ $k ] ?? ''; }
function wp_mail( $to, $subj, $body, $headers = array() ) { $m = stub_load( 'mails' ); $m[] = compact( 'to', 'subj', 'body', 'headers' ); stub_save( 'mails', $m ); return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

class WP_Error {
	public $code, $message, $data;
	public function __construct( $c = '', $m = '', $d = '' ) { $this->code = $c; $this->message = $m; $this->data = $d; }
}
class WP_REST_Response {
	public $data, $status, $headers = array();
	public function __construct( $d = null, $s = 200 ) { $this->data = $d; $this->status = $s; }
	public function header( $k, $v ) { $this->headers[ $k ] = $v; }
}
class WP_REST_Request {
	private $headers, $body, $params;
	public function __construct( $headers, $body, $query ) {
		$this->headers = $headers; $this->body = $body;
		$json = json_decode( (string) $body, true );
		$this->params = array_merge( $query, is_array( $json ) ? $json : array() );
	}
	public function get_header( $k ) { $k = strtolower( str_replace( '_', '-', $k ) ); return $this->headers[ $k ] ?? null; }
	public function get_body() { return $this->body; }
	public function get_param( $k ) { return $this->params[ $k ] ?? null; }
}
$GLOBALS['stub_routes'] = array();
function register_rest_route( $ns, $route, $args ) { $GLOBALS['stub_routes'][ '/' . $ns . $route ] = $args; }

/* ------------------------------------------------------------ boot plugin */
require plugin_dir_path( '' ) . 'perki-lekcje-jasny.php';
$_SERVER['REQUEST_URI'] = $uri;
do_action( 'init' ); // moze zakonczyc zadanie (llms.txt, sitemap)
do_action( 'wp_loaded' );

/* ------------------------------------------------------------ dispatch */
if ( 0 === strpos( $path, '/wp-json/' ) ) {
	define( 'REST_REQUEST', true );
	do_action( 'rest_api_init' );
	$route = substr( $path, strlen( '/wp-json' ) );
	$def   = $GLOBALS['stub_routes'][ $route ] ?? null;
	header( 'Content-Type: application/json; charset=UTF-8' );
	if ( ! $def || strtoupper( $_SERVER['REQUEST_METHOD'] ) !== $def['methods'] ) {
		http_response_code( 404 ); echo json_encode( array( 'code' => 'rest_no_route' ) ); return true;
	}
	$hdrs = array();
	foreach ( $_SERVER as $k => $v ) {
		if ( 0 === strpos( $k, 'HTTP_' ) ) { $hdrs[ strtolower( str_replace( '_', '-', substr( $k, 5 ) ) ) ] = $v; }
	}
	if ( isset( $_SERVER['CONTENT_TYPE'] ) ) { $hdrs['content-type'] = $_SERVER['CONTENT_TYPE']; }
	$res = call_user_func( $def['callback'], new WP_REST_Request( $hdrs, file_get_contents( 'php://input' ), $_GET ) );
	if ( $res instanceof WP_Error ) {
		http_response_code( $res->data['status'] ?? 500 );
		echo json_encode( array( 'code' => $res->code, 'message' => $res->message, 'data' => $res->data ), JSON_UNESCAPED_UNICODE );
	} else {
		foreach ( $res->headers as $k => $v ) { header( "$k: $v" ); }
		http_response_code( $res->status );
		echo json_encode( $res->data, JSON_UNESCAPED_UNICODE );
	}
	return true;
}

if ( '/robots.txt' === $path ) {
	header( 'Content-Type: text/plain; charset=UTF-8' );
	echo apply_filters( 'robots_txt', "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: " . home_url( '/wp-sitemap.xml' ) . "\n", '1' );
	return true;
}

foreach ( $GLOBALS['stub_rewrites'] ?? array() as $re => $q ) {
	if ( preg_match( '#' . $re . '#', ltrim( $path, '/' ) ) ) { parse_str( parse_url( $q, PHP_URL_QUERY ), $GLOBALS['stub_qv'] ); }
}
do_action( 'template_redirect' );

if ( '/gra/' === $path || '/' === $path ) { header( 'Content-Type: text/html' ); echo '<!doctype html><title>stub</title><h1>stub ' . htmlspecialchars( $path ) . '</h1>'; return true; }
http_response_code( 404 );
echo 'stub 404';
return true;
