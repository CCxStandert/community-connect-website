<?php
defined( 'ABSPATH' ) || exit;

/**
 * The home page: a full-page template ("CC Landing") with the fleet, events, the deal and the
 * request form, so everything happens on one site. The page is created on update and, when the
 * site still shows its latest posts on the front, made the home page.
 */
class CC_Fleet_Landing {

	const TEMPLATE = 'cc-landing';

	public static function init() {
		add_filter( 'theme_page_templates', array( __CLASS__, 'register' ) );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 100 );
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 99 );
		add_action( 'template_redirect', array( __CLASS__, 'old_address' ), 1 );
		// The live parts of the home page, for the Community Connect theme (or any page): each is a shortcode.
		foreach ( array( 'cc_home_fleet' => 'fleet', 'cc_how_loop' => 'loop', 'cc_events_list' => 'events', 'cc_numbers' => 'stats', 'cc_who_pays' => 'game' ) as $tag => $part ) {
			add_shortcode( $tag, function () use ( $part ) {
				return self::part( $part );
			} );
		}
	}

	/** True when the Community Connect theme is active: it draws the page, the plugin only fills in the live parts. */
	public static function theme_active() {
		return 'community-connect' === get_template();
	}

	/** One live part of the home page (fleet finder + grid, loop animation, events, numbers, the game). */
	public static function part( $name ) {
		$f = CC_FLEET_DIR . 'assets/landing/parts/' . sanitize_key( $name ) . '.html';
		if ( ! file_exists( $f ) ) {
			return '';
		}
		self::enqueue_components();
		$html = (string) file_get_contents( $f ); // phpcs:ignore
		if ( 'stats' === $name ) {
			$st   = self::stats();
			$html = preg_replace_callback( '/(<b id="(sLoans|sRiders|sEvents)">)[^<]*(<\/b>)/', function ( $m ) use ( $st ) {
				$k = array( 'sLoans' => 'loans', 'sRiders' => 'riders', 'sEvents' => 'events' )[ $m[2] ];
				return $m[1] . (int) $st[ $k ] . $m[3];
			}, $html );
		}
		return '<div class="cc-part cc-part--' . esc_attr( $name ) . '">' . $html . '</div>';
	}

	/** Styles + script for the live parts, with their data. Safe to call more than once. */
	public static function enqueue_components() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		if ( ! self::theme_active() ) {
			wp_enqueue_style( 'cc-landing-fonts', 'https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&family=EB+Garamond:ital@0;1&family=IBM+Plex+Mono:wght@400;500&family=Reenie+Beanie&display=swap', array(), null ); // phpcs:ignore
		}
		wp_enqueue_style( 'cc-landing', CC_FLEET_URL . 'assets/landing.css', array(), CC_FLEET_VERSION );
		wp_enqueue_script( 'cc-landing', CC_FLEET_URL . 'assets/landing.js', array(), CC_FLEET_VERSION, true );
		wp_add_inline_script( 'cc-landing', 'window.CCLanding=' . wp_json_encode( self::data() ) . ';', 'before' );
		if ( class_exists( 'CC_Fleet_Frontend' ) ) {
			CC_Fleet_Frontend::enqueue_public();
		}
	}

	/**
	 * The site used to live at /new/. Since it moved to the main address, send old links there
	 * (community-connect.cc/new/fleet/cc-12/ → community-connect.cc/fleet/cc-12/). The admin stays at /new/wp-admin.
	 */
	public static function old_address() {
		if ( ! is_404() ) {
			return;
		}
		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/';
		$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ); // phpcs:ignore
		if ( '/' === $home && preg_match( '#^/new(/.*)?$#', $path, $m ) ) {
			$rest  = $m[1] ?? '/';
			$query = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY ); // phpcs:ignore
			wp_safe_redirect( home_url( $rest ) . ( $query ? '?' . $query : '' ), 301 );
			exit;
		}
	}

	/** Deferred from the plugin update (see CC_Fleet_Install::maybe_upgrade). */
	public static function maybe_install() {
		if ( get_option( 'cc_fleet_install_landing' ) ) {
			delete_option( 'cc_fleet_install_landing' );
			self::install();
		}
	}

	public static function register( $templates ) {
		$templates[ self::TEMPLATE ] = 'CC Landing (full page)';
		return $templates;
	}

	public static function is_landing() {
		return is_page() && self::TEMPLATE === get_page_template_slug( get_queried_object_id() );
	}

	public static function template( $template ) {
		// With the Community Connect theme the theme's own front page (editable in the Site Editor) is used.
		return self::is_landing() && ! self::theme_active() ? CC_FLEET_DIR . 'templates/landing.php' : $template;
	}

	public static function assets() {
		if ( ! self::is_landing() || self::theme_active() ) {
			return;
		}
		// The design brings its own type, colours and layout: leave the theme's styles out on this page.
		$keep = array( 'admin-bar', 'dashicons', 'cc-fleet', 'cc-landing', 'cc-landing-fonts' );
		foreach ( wp_styles()->queue as $h ) {
			if ( ! in_array( $h, $keep, true ) ) {
				wp_dequeue_style( $h );
			}
		}
		wp_enqueue_style( 'cc-landing-fonts', 'https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;700;800&family=EB+Garamond:ital@0;1&family=IBM+Plex+Mono:wght@400;500&family=Reenie+Beanie&display=swap', array(), null ); // phpcs:ignore
		wp_enqueue_style( 'cc-landing', CC_FLEET_URL . 'assets/landing.css', array( 'cc-landing-fonts' ), CC_FLEET_VERSION );
		wp_enqueue_script( 'cc-landing', CC_FLEET_URL . 'assets/landing.js', array(), CC_FLEET_VERSION, true );
		wp_add_inline_script( 'cc-landing', 'window.CCLanding=' . wp_json_encode( self::data() ) . ';', 'before' );
	}

	/** Everything the page script needs, rendered server side so the page works without extra calls. */
	public static function data() {
		$bikes = array();
		foreach ( CC_Fleet_Bikes::public_ids() as $id ) {
			$b       = CC_Fleet_Bikes::public_data( $id );
			$bikes[] = array(
				'cc' => $b['cc_id'], 'name' => $b['name'], 'model' => $b['model'], 'size' => $b['size'], 'cat' => $b['category'],
				'range' => $b['rider_range'], 'state' => $b['state'], 'url' => $b['url'], 'image' => $b['image'],
			);
		}
		$events = array();
		if ( class_exists( 'CC_Fleet_Frontend' ) ) {
			foreach ( CC_Fleet_Frontend::upcoming_events() as $e ) {
				// Every event ticked "Show on the website": races, social rides, bikepacking…
				$cats     = CC_Fleet_Records::event_cats( $e );
				$events[] = array( 'name' => $e->name, 'start' => $e->start_date, 'end' => $e->end_date ?: $e->start_date, 'where' => (string) $e->location, 'cats' => $cats, 'labels' => array_map( function ( $k ) { return CC_Fleet_Records::EVENT_CATS[ $k ]; }, $cats ), 'link' => (string) $e->link, 'when' => (string) ( $e->schedule ?? '' ) );
			}
		}
		return array(
			'bikes' => $bikes, 'events' => $events, 'horizon' => CC_Fleet_Loans::horizon(), 'icon' => self::svg( 'icon' ),
			'brand' => CC_Fleet_Bikes::brand_svg( 'cc-brand' ), // Shown on the cards instead of the internal Bike ID.
			'api' => esc_url_raw( rest_url( CC_Fleet_Rest::NS ) ), 'live' => true,
			'img' => CC_FLEET_URL . 'assets/landing/img',
			'stats' => self::stats(),
		);
	}

	/**
	 * The three numbers on the home page, for the last 365 days (up to today):
	 * loans that ran in that time, the Connectors who rode them, and the races and rides held.
	 */
	public static function stats() {
		global $wpdb;
		$to    = CC_Fleet_Settings::today();
		$from  = wp_date( 'Y-m-d', strtotime( $to . ' -364 days' ) );
		$loans = CC_Fleet_Loans::table();
		$ok    = "status IN ('approved','out','returned') AND start_date <= %s AND end_date >= %s";
		$ev    = CC_Fleet_Records::table( 'events' );
		return array(
			'year'   => wp_date( 'Y' ),
			'from'   => $from,
			'loans'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $loans WHERE $ok", $to, $from ) ),
			'riders' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT rider_id) FROM $loans WHERE rider_id > 0 AND $ok", $to, $from ) ),
			'events' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ev WHERE cancelled = 0 AND start_date <= %s AND COALESCE(end_date, start_date) >= %s", $to, $from ) ),
		);
	}

	/** The agreement link riders see: the setting, or the agreement on the home page. */
	public static function agreement_url() {
		$set = (string) CC_Fleet_Settings::get( 'agreement_url' );
		if ( $set ) {
			return $set;
		}
		return get_option( 'cc_fleet_landing_id' ) ? home_url( '/#agreement' ) : '';
	}

	public static function svg( $which ) {
		$f = CC_FLEET_DIR . 'assets/landing/' . ( 'logo' === $which ? 'logo' : 'icon' ) . '.svg.inner';
		return file_exists( $f ) ? (string) file_get_contents( $f ) : ''; // phpcs:ignore
	}

	public static function agreement() {
		$f = CC_FLEET_DIR . 'assets/landing/agreement.html';
		return file_exists( $f ) ? (string) file_get_contents( $f ) : ''; // phpcs:ignore
	}

	/**
	 * Create the "Borrow a bike" page with this template. Make it the home page if the site
	 * still shows latest posts on the front (a static front page someone chose is left alone).
	 */
	public static function install() {
		if ( ! did_action( 'init' ) ) {
			update_option( 'cc_fleet_install_landing', 1, false );
			return 0;
		}
		$id = (int) get_option( 'cc_fleet_landing_id' );
		if ( ! $id || ! get_post( $id ) ) {
			$id = wp_insert_post( array(
				'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Borrow a bike',
				'post_name' => 'borrow', 'post_content' => '<!-- The CC Landing template draws this page. Edit the design in the CC Fleet plugin. -->',
			) );
			if ( is_wp_error( $id ) || ! $id ) {
				return 0;
			}
			update_option( 'cc_fleet_landing_id', (int) $id, false );
		}
		update_post_meta( $id, '_wp_page_template', self::TEMPLATE );
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', (int) $id );
		}
		// The agreement now lives on the home page; an empty setting follows the site address if it moves.
		if ( false !== strpos( (string) CC_Fleet_Settings::get( 'agreement_url' ), '#agreement' ) ) {
			CC_Fleet_Settings::update( array( 'agreement_url' => '' ) );
		}
		return (int) $id;
	}
}
