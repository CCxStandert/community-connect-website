<?php
/**
 * Small security hardening for the site (from the November 2026 audit).
 *
 * - The REST users list is only for logged-in editors: visitors can't read the admin username.
 * - Author archives (/?author=1) go to the homepage, for the same reason.
 * - XML-RPC is off (nothing on this site uses it: no Jetpack, no app publishing).
 * - Security headers on every WordPress response (HSTS, nosniff, frame and referrer rules).
 * - Once per version: removes readme.html / license.txt and redirects the old static pages.
 *
 * @package CC_Fleet
 */

defined( 'ABSPATH' ) || exit;

class CC_Fleet_Hardening {

	/** Old static pages in the site root and where they live now. */
	const REDIRECTS = array(
		'/index-old.html'        => '/',
		'/lesotho-longshot.html' => '/our-story/',
	);

	public static function init() {
		add_filter( 'rest_endpoints', array( __CLASS__, 'hide_users' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_author_archives' ), 1 );
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array' );
		add_filter( 'wp_headers', array( __CLASS__, 'drop_pingback' ) );
		add_action( 'send_headers', array( __CLASS__, 'headers' ) );
		add_action( 'login_init', array( __CLASS__, 'headers' ) );
		add_action( 'admin_init', array( __CLASS__, 'headers' ) );
		add_action( 'admin_init', array( __CLASS__, 'once' ) );
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			status_header( 403 );
			exit;
		}
	}

	/** Visitors don't get /wp/v2/users; logged-in users (the block editor) still do. */
	public static function hide_users( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	public static function no_author_archives() {
		if ( is_author() || isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	public static function drop_pingback( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	public static function headers() {
		if ( headers_sent() ) {
			return;
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()' );
		if ( is_ssl() ) {
			// Six months, this host only (no subdomains, no preload): safe to undo.
			header( 'Strict-Transport-Security: max-age=15552000' );
		}
	}

	/** File tidy-up, once per plugin version (WordPress updates put readme.html back). */
	public static function once() {
		if ( get_option( 'cc_fleet_hardened' ) === CC_FLEET_VERSION || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		update_option( 'cc_fleet_hardened', CC_FLEET_VERSION, false );
		foreach ( array( 'readme.html', 'license.txt', 'wp-config-sample.php' ) as $f ) {
			if ( is_file( ABSPATH . $f ) && is_writable( ABSPATH . $f ) ) {
				@unlink( ABSPATH . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		self::root_redirects();
	}

	/**
	 * WordPress lives in /new/, the old static pages sit one level up. A marked block in that
	 * folder's .htaccess sends them to their new homes (Apache only; left alone if not writable).
	 */
	public static function root_redirects() {
		$root = trailingslashit( dirname( untrailingslashit( ABSPATH ) ) );
		$home = untrailingslashit( home_url() );
		$need = false;
		foreach ( array_keys( self::REDIRECTS ) as $from ) {
			if ( is_file( $root . ltrim( $from, '/' ) ) ) {
				$need = true;
			}
		}
		if ( ! $need ) {
			return;
		}
		$file = $root . '.htaccess';
		if ( ( is_file( $file ) && ! is_writable( $file ) ) || ( ! is_file( $file ) && ! is_writable( $root ) ) ) {
			return;
		}
		$lines = array( '<IfModule mod_alias.c>' );
		foreach ( self::REDIRECTS as $from => $to ) {
			$lines[] = 'Redirect 301 ' . $from . ' ' . $home . $to;
		}
		$lines[] = '</IfModule>';
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		insert_with_markers( $file, 'CC Fleet redirects', $lines );
	}
}
