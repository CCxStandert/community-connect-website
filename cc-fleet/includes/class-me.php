<?php
/**
 * "My rides": a rider's private page, reached through a link in their emails — no accounts, no passwords.
 *
 * - Each rider has a secret access token. Links look like /my-rides/?k=TOKEN. Opening one signs the
 *   rider in on that device (a cookie, 30 days) and drops the token from the address bar.
 * - The link works until 30 days after the rider's last loan ends, or 30 days after we emailed it.
 * - The page lists upcoming bookings (status, where to pick up and return, add to calendar,
 *   WhatsApp the crew, ask to change dates, cancel) and past rides ("Ride this bike again").
 * - Signed in, the request form fills itself in and skips the agreement they already accepted.
 * - Not signed in: "Email me my link" sends a fresh link to an address we know (same answer either way).
 *
 * @package cc-fleet
 */

defined( 'ABSPATH' ) || exit;

class CC_Fleet_Me {

	const COOKIE    = 'cc_rides';
	const PAGE_SLUG = 'my-rides';
	/** Bump when the borrowing agreement changes, so everyone ticks it again. */
	const AGREEMENT = '2026-11b'; // b: consent now includes sharing first name + WhatsApp with the rider before/after.

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'sign_in_from_link' ), 1 );
		add_shortcode( 'cc_my_rides', array( __CLASS__, 'shortcode' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'noindex' ) );
	}

	/* ---------- Tokens ---------- */

	/** The rider's link, creating the token if needed and keeping it valid for 30 more days. */
	public static function url( $rider ) {
		if ( ! $rider || empty( $rider->id ) ) {
			return '';
		}
		$token = self::ensure_token( $rider );
		return add_query_arg( 'k', $token, self::page_url() );
	}

	public static function page_url() {
		$p = get_page_by_path( self::PAGE_SLUG );
		return $p ? get_permalink( $p ) : home_url( '/' . self::PAGE_SLUG . '/' );
	}

	public static function ensure_token( $rider ) {
		global $wpdb;
		$token = (string) ( $rider->access_token ?? '' );
		if ( strlen( $token ) < 24 ) {
			$token = wp_generate_password( 32, false );
		}
		$until = max( (string) ( $rider->access_until ?? '' ), CC_Fleet_Loans::add_days( CC_Fleet_Settings::today(), 30 ), self::last_end( $rider->id ) );
		$wpdb->update( CC_Fleet_Riders::table(), array( 'access_token' => $token, 'access_until' => $until ), array( 'id' => (int) $rider->id ) );
		return $token;
	}

	/** Last loan end + 30 days (or '' with no loans). */
	private static function last_end( $rider_id ) {
		global $wpdb;
		$end = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(end_date) FROM ' . CC_Fleet_Loans::table() . " WHERE rider_id = %d AND status NOT IN ('declined')", $rider_id ) );
		return $end ? CC_Fleet_Loans::add_days( $end, 30 ) : '';
	}

	/** Rider for a token, or null when unknown or expired. */
	public static function rider_for( $token ) {
		global $wpdb;
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		if ( strlen( $token ) < 24 ) {
			return null;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CC_Fleet_Riders::table() . ' WHERE access_token = %s', $token ) );
		if ( ! $r ) {
			return null;
		}
		$until = max( (string) $r->access_until, self::last_end( $r->id ) );
		return $until >= CC_Fleet_Settings::today() ? $r : null;
	}

	/** The rider signed in on this device (cookie), or null. */
	private static $cache = array();
	public static function current() {
		$tok = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( ! array_key_exists( $tok, self::$cache ) ) {
			self::$cache[ $tok ] = '' === $tok ? null : self::rider_for( $tok );
		}
		return self::$cache[ $tok ];
	}

	private static function set_cookie( $value, $expires ) {
		setcookie( self::COOKIE, $value, array(
			'expires' => $expires, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN ?: '',
			'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax',
		) );
		if ( COOKIEPATH !== SITECOOKIEPATH ) { // WordPress in a sub-folder: the REST API lives under the site path.
			setcookie( self::COOKIE, $value, array( 'expires' => $expires, 'path' => SITECOOKIEPATH, 'domain' => COOKIE_DOMAIN ?: '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		}
	}

	/** /my-rides/?k=… → signed in on this device, then the clean address. ?out=1 signs out. */
	public static function sign_in_from_link() {
		if ( ! is_page( self::PAGE_SLUG ) ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		if ( isset( $_GET['out'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::set_cookie( '', time() - 3600 );
			wp_safe_redirect( add_query_arg( 'signed-out', '1', self::page_url() ) );
			exit;
		}
		if ( isset( $_GET['k'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$r = self::rider_for( sanitize_text_field( wp_unslash( $_GET['k'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( $r ) {
				self::set_cookie( $r->access_token, time() + 30 * DAY_IN_SECONDS );
				wp_safe_redirect( self::page_url() );
			} else {
				wp_safe_redirect( add_query_arg( 'expired', '1', self::page_url() ) );
			}
			exit;
		}
	}

	public static function noindex( $robots ) {
		if ( is_page( self::PAGE_SLUG ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/** Creates the My rides page once. */
	public static function ensure_page() {
		if ( ! did_action( 'init' ) ) { // Upgrades run before WordPress is fully loaded; make the page once it is.
			add_action( 'init', array( __CLASS__, 'ensure_page' ), 30 );
			return;
		}
		if ( get_page_by_path( self::PAGE_SLUG ) ) {
			return;
		}
		$id = wp_insert_post( array(
			'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'My rides', 'post_name' => self::PAGE_SLUG,
			'post_content' => "<!-- wp:shortcode -->\n[cc_my_rides]\n<!-- /wp:shortcode -->",
		) );
		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_wp_page_template', 'page-wide' ); // Full width, no page title (the page has its own).
		}
	}

	/** True when this rider has already accepted the current borrowing agreement. */
	public static function agreed( $rider ) {
		return $rider && self::AGREEMENT === (string) ( $rider->agreed_version ?? '' );
	}

	public static function mark_agreed( $rider_id ) {
		global $wpdb;
		$wpdb->update( CC_Fleet_Riders::table(), array( 'agreed_version' => self::AGREEMENT, 'agreed_at' => current_time( 'mysql' ) ), array( 'id' => (int) $rider_id ) );
	}

	/** Ticked the consent that includes sharing first name + WhatsApp with the rider before/after. */
	public static function mark_share( $rider_id ) {
		global $wpdb;
		$wpdb->update( CC_Fleet_Riders::table(), array( 'share_ok' => current_time( 'mysql' ) ), array( 'id' => (int) $rider_id ) );
	}

	/* ---------- REST ---------- */

	public static function routes() {
		$ns = CC_Fleet_REST::NS;
		register_rest_route( $ns, '/me', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'api_me' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/me/link', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'api_link' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/me/loans/(?P<id>\d+)/cancel', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'api_cancel' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/me/loans/(?P<id>\d+)/change', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'api_change' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/me/loans/(?P<id>\d+)/calendar', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'api_ics' ), 'permission_callback' => '__return_true' ) );
	}

	private static function json( $data, $status = 200 ) {
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'private, no-store, max-age=0' );
		return $r;
	}

	/** What the request form needs to fill itself in. */
	public static function api_me() {
		$r = self::current();
		if ( ! $r ) {
			return self::json( array( 'signed_in' => false ) );
		}
		global $wpdb;
		$last = $wpdb->get_row( $wpdb->prepare( 'SELECT pedals FROM ' . CC_Fleet_Loans::table() . " WHERE rider_id = %d AND pedals <> '' ORDER BY id DESC LIMIT 1", $r->id ) );
		list( $cc, $local ) = self::split_phone( (string) $r->phone );
		return self::json( array(
			'signed_in' => true,
			'name' => $r->name, 'email' => $r->email, 'phone' => $local, 'phone_cc' => $cc, 'instagram' => $r->instagram ? '@' . ltrim( $r->instagram, '@' ) : 'none',
			'height' => (int) $r->height_cm ?: '', 'weight' => (int) $r->weight_kg ?: '', 'pedals' => $last ? $last->pedals : '',
			'agreed' => self::agreed( $r ), 'agreed_on' => self::agreed( $r ) ? mysql2date( 'j M Y', $r->agreed_at ) : '',
			'my_rides' => self::page_url(),
		) );
	}

	/** "+27 82 123 4567" → ['27', '82 123 4567'] (best effort for the form's two fields). */
	private static function split_phone( $phone ) {
		$d = preg_replace( '/[^0-9+]/', '', $phone );
		if ( preg_match( '/^\+(27|49|44|1|31|33|41|43|61|64|263|264|267|266|268)(\d+)$/', $d, $m ) ) {
			return array( $m[1], $m[2] );
		}
		return array( '27', ltrim( $d, '+' ) );
	}

	/** Email a fresh link to a known rider. Always the same answer, so it can't be used to look people up. */
	public static function api_link( WP_REST_Request $req ) {
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		$ok    = array( 'ok' => true, 'message' => 'If we know that address, your link is on its way. Check your inbox (and spam).' );
		if ( ! is_email( $email ) ) {
			return self::json( array( 'ok' => false, 'message' => 'That email address doesn’t look right.' ), 400 );
		}
		$ip  = substr( md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ), 0, 12 );
		$ipk = 'cc_me_ip_' . $ip;
		$n   = (int) get_transient( $ipk );
		if ( $n >= 8 ) {
			return self::json( array( 'ok' => false, 'message' => 'Too many tries. Please wait an hour.' ), 429 );
		}
		set_transient( $ipk, $n + 1, HOUR_IN_SECONDS );
		$ek = 'cc_me_em_' . md5( strtolower( $email ) );
		if ( get_transient( $ek ) ) {
			return self::json( $ok );
		}
		set_transient( $ek, 1, 2 * MINUTE_IN_SECONDS );
		$r = CC_Fleet_Riders::find_by_email( $email );
		if ( $r ) {
			CC_Fleet_Emails::my_rides_link( $r, self::url( $r ) );
		}
		return self::json( $ok );
	}

	/** The signed-in rider's own loan, or an error. */
	private static function own_loan( WP_REST_Request $req ) {
		$r = self::current();
		if ( ! $r ) {
			return new WP_Error( 'cc_signed_out', 'Open the link from your email again.', array( 'status' => 401 ) );
		}
		$l = CC_Fleet_Loans::get( (int) $req['id'] );
		if ( ! $l || (int) $l->rider_id !== (int) $r->id ) {
			return new WP_Error( 'cc_not_found', 'That booking isn’t yours.', array( 'status' => 404 ) );
		}
		return array( $r, $l );
	}

	/** Not confirmed yet → cancelled straight away. Confirmed or out → the crew gets the message. */
	public static function api_cancel( WP_REST_Request $req ) {
		$own = self::own_loan( $req );
		if ( is_wp_error( $own ) ) {
			return $own;
		}
		list( $r, $l ) = $own;
		$note  = trim( sanitize_textarea_field( (string) $req->get_param( 'note' ) ) );
		$label = self::bike_label( $l ) . ', ' . self::dates( $l );
		if ( in_array( $l->status, array( 'requested', 'waiting' ), true ) ) {
			CC_Fleet_Loans::set_status( $l->id, 'cancelled', true, true );
			CC_Fleet_Install::log( 'loan', $l->id, 'cancelled by rider', $note );
			CC_Fleet_Emails::wl_crew_note( $r->name . ' cancelled a request', $r->name . ' cancelled their request', array_filter( array( $label, $note ? '“' . $note . '”' : '' ) ), admin_url( 'admin.php?page=cc-fleet-loan&id=' . $l->id ), 'Open the loan' );
			return self::json( array( 'ok' => true, 'status' => 'cancelled', 'message' => 'Cancelled. Thanks for letting us know.' ) );
		}
		if ( in_array( $l->status, array( 'approved', 'out' ), true ) ) {
			CC_Fleet_Install::log( 'loan', $l->id, 'rider asked to cancel', $note );
			CC_Fleet_Emails::wl_crew_note( $r->name . ' wants to cancel', $r->name . ' wants to cancel a confirmed booking', array_filter( array( $label, $note ? '“' . $note . '”' : '', 'Cancel it in Fleet Manager once you’ve spoken.' ) ), admin_url( 'admin.php?page=cc-fleet-loan&id=' . $l->id ), 'Open the loan' );
			return self::json( array( 'ok' => true, 'status' => $l->status, 'message' => 'We’ve told the crew. Someone will confirm with you shortly.' ) );
		}
		return new WP_Error( 'cc_closed', 'This booking can’t be cancelled any more.', array( 'status' => 409 ) );
	}

	/** A date change is a request to the crew, never automatic. */
	public static function api_change( WP_REST_Request $req ) {
		$own = self::own_loan( $req );
		if ( is_wp_error( $own ) ) {
			return $own;
		}
		list( $r, $l ) = $own;
		$s = sanitize_text_field( (string) $req->get_param( 'start' ) );
		$e = sanitize_text_field( (string) $req->get_param( 'end' ) );
		$range = CC_Fleet_Loans::validate_range( $s, $e, true );
		if ( is_wp_error( $range ) ) {
			return new WP_Error( 'cc_dates', $range->get_error_message(), array( 'status' => 400 ) );
		}
		if ( ! in_array( $l->status, array( 'requested', 'waiting', 'approved' ), true ) ) {
			return new WP_Error( 'cc_closed', 'This booking can’t be changed any more.', array( 'status' => 409 ) );
		}
		$note = trim( sanitize_textarea_field( (string) $req->get_param( 'note' ) ) );
		$free = ! $l->bike_id || CC_Fleet_Loans::is_available( $l->bike_id, $s, $e, $l->id );
		$want = self::fmt( $s ) . ' – ' . self::fmt( $e );
		CC_Fleet_Install::log( 'loan', $l->id, 'rider asked for new dates', $want . ( $note ? ' · ' . $note : '' ) );
		CC_Fleet_Emails::wl_crew_note( $r->name . ' asks for new dates', $r->name . ' asks to change dates', array_filter( array(
			self::bike_label( $l ) . ', now ' . self::dates( $l ), 'Wants: ' . $want . ( $free ? ' (the bike is free then)' : ' (the bike is booked on some of those days)' ), $note ? '“' . $note . '”' : '',
			'Change them on the loan page; the rider gets the new dates by email.',
		) ), admin_url( 'admin.php?page=cc-fleet-loan&id=' . $l->id ), 'Open the loan' );
		return self::json( array( 'ok' => true, 'message' => 'Sent to the crew. You’ll get an email when the dates are changed.' . ( $free ? '' : ' Heads-up: the bike is booked on some of those days.' ) ) );
	}

	/** Add to calendar: one all-day event covering the loan. */
	public static function api_ics( WP_REST_Request $req ) {
		$own = self::own_loan( $req );
		if ( is_wp_error( $own ) ) {
			return $own;
		}
		list( , $l ) = $own;
		$p    = CC_Fleet_Loans::pickup( $l );
		$desc = 'Community Connect bike loan: ' . self::bike_label( $l ) . '\\nPick-up: ' . self::pickup_text( $p ) . '\\nReturn: ' . self::return_text( $l ) . '\\nYour booking: ' . self::page_url();
		$ics  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Community Connect//Fleet//EN\r\nCALSCALE:GREGORIAN\r\nBEGIN:VEVENT\r\n"
			. 'UID:cc-loan-' . $l->id . '@' . wp_parse_url( home_url(), PHP_URL_HOST ) . "\r\n"
			. 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ) . "\r\n"
			. 'DTSTART;VALUE=DATE:' . str_replace( '-', '', $l->start_date ) . "\r\n"
			. 'DTEND;VALUE=DATE:' . str_replace( '-', '', CC_Fleet_Loans::add_days( $l->end_date, 1 ) ) . "\r\n"
			. 'SUMMARY:' . self::ics_text( 'Bike: ' . self::bike_label( $l ) ) . "\r\n"
			. 'DESCRIPTION:' . self::ics_text( $desc ) . "\r\n"
			. ( 'address' === $p['mode'] && $p['place'] ? 'LOCATION:' . self::ics_text( $p['place'] ) . "\r\n" : '' )
			. "END:VEVENT\r\nEND:VCALENDAR\r\n";
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="community-connect-bike.ics"' );
		header( 'Cache-Control: private, no-store' );
		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private static function ics_text( $s ) {
		return str_replace( array( ',', ';', "\n" ), array( '\\,', '\\;', '\\n' ), (string) $s );
	}

	/* ---------- Text helpers ---------- */

	private static function fmt( $d ) {
		return $d ? date_i18n( 'D j M Y', strtotime( $d ) ) : '';
	}

	public static function dates( $l ) {
		return self::fmt( $l->start_date ) . ' – ' . self::fmt( $l->end_date );
	}

	public static function bike_label( $l ) {
		return $l->bike_id ? CC_Fleet_Bikes::public_label( $l->bike_id ) : 'A size ' . $l->requested_size . ' bike';
	}

	private static function pickup_text( $p ) {
		if ( 'rider' === $p['mode'] && $p['prev'] ) {
			$prev = CC_Fleet_Riders::get( $p['prev']->rider_id );
			return 'From the rider before you' . ( $prev ? ' (' . strtok( $prev->name, ' ' ) . ')' : '' ) . ', we connect you on WhatsApp';
		}
		$bits = array_filter( array( $p['place'], $p['contact'], $p['time'] ) );
		return $bits ? implode( ' · ', $bits ) : 'We’ll confirm where with you';
	}

	private static function return_text( $l ) {
		$next = $l->bike_id ? CC_Fleet_Loans::next_loan( $l ) : null;
		if ( $next ) {
			return 'Hand it to the next rider, we connect you on WhatsApp';
		}
		$base = (string) CC_Fleet_Settings::get( 'base_address' );
		return $base ? 'Back to our base: ' . $base : 'We’ll confirm where with you';
	}

	/* ---------- The page ---------- */

	public static function shortcode() {
		wp_enqueue_style( 'cc-fleet-me', CC_FLEET_URL . 'assets/me.css', array(), CC_FLEET_VERSION );
		wp_enqueue_script( 'cc-fleet-me', CC_FLEET_URL . 'assets/me.js', array(), CC_FLEET_VERSION, true );
		wp_localize_script( 'cc-fleet-me', 'CCMe', array( 'api' => esc_url_raw( rest_url( CC_Fleet_REST::NS ) ), 'today' => CC_Fleet_Settings::today(), 'horizon' => CC_Fleet_Loans::horizon() ) );
		$r = self::current();
		if ( ! $r ) {
			return self::signed_out();
		}
		$today = CC_Fleet_Settings::today();
		$loans = CC_Fleet_Loans::query( array( 'rider_id' => $r->id, 'limit' => 200, 'orderby' => 'start_date', 'order' => 'ASC' ) );
		$up    = array_values( array_filter( $loans, function ( $l ) use ( $today ) {
			return in_array( $l->status, array( 'requested', 'waiting', 'approved', 'out' ), true ) && ( $l->end_date >= $today || 'out' === $l->status );
		} ) );
		$past  = array_reverse( array_values( array_filter( $loans, function ( $l ) use ( $up ) {
			return 'returned' === $l->status || ( in_array( $l->status, array( 'approved', 'out' ), true ) && ! in_array( $l, $up, true ) );
		} ) ) );
		$wa    = preg_replace( '/[^0-9]/', '', (string) CC_Fleet_Settings::get( 'whatsapp_number' ) );

		ob_start();
		?>
		<section class="cc-me">
			<div class="cc-me__head max">
				<p class="cc-me__crumb">Community Connect&nbsp;&nbsp;/&nbsp;&nbsp;<span>My rides</span></p>
				<h1 class="cc-me__h1">Hi <?php echo esc_html( strtok( $r->name, ' ' ) ); ?>.</h1>
				<p class="cc-me__lede"><?php echo $up ? 'Here’s what’s coming up.' : 'Nothing booked right now.'; ?> <a href="<?php echo esc_url( home_url( '/#fleet' ) ); ?>">Borrow a bike</a></p>
			</div>

			<div class="cc-me__list max">
			<?php if ( ! $up ) : ?>
				<p class="cc-me__empty">When you request a bike, it shows up here with everything you need for the pick-up.</p>
			<?php endif; ?>
			<?php foreach ( $up as $l ) : echo self::card( $l, $wa ); endforeach; // phpcs:ignore ?>
			</div>

			<?php if ( $past ) : ?>
			<h2 class="cc-me__h2 max">Past rides</h2>
			<ul class="cc-me__past max">
				<?php foreach ( array_slice( $past, 0, 20 ) as $l ) : ?>
				<li>
					<span class="cc-me__pdate"><?php echo esc_html( date_i18n( 'M Y', strtotime( $l->start_date ) ) ); ?></span>
					<span class="cc-me__pbike"><b><?php echo esc_html( self::bike_label( $l ) ); ?></b><small><?php echo esc_html( self::dates( $l ) ); ?></small></span>
					<?php if ( $l->bike_id && 'active' === CC_Fleet_Bikes::get( $l->bike_id, 'state' ) ) : ?>
					<a class="cc-me__btn" href="<?php echo esc_url( add_query_arg( 'again', '1', get_permalink( $l->bike_id ) ) . '#request' ); ?>">Ride it again</a>
					<?php endif; ?>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>

			<p class="cc-me__foot max">Signed in on this device as <?php echo esc_html( $r->email ); ?>. <a href="<?php echo esc_url( add_query_arg( 'out', '1', self::page_url() ) ); ?>" data-cc-forget>Sign out on this device</a></p>
		</section>
		<?php
		return ob_get_clean();
	}

	private static function card( $l, $wa ) {
		$status = array(
			'requested' => array( 'Requested', 'We’ll confirm by email, usually within a day.' ),
			'waiting'   => array( 'Waiting list', 'We’ll email you if it frees up.' ),
			'approved'  => array( 'Confirmed', 'You’re on. Details below.' ),
			'out'       => array( 'Out with you', 'Enjoy the ride. Bring it back with a story.' ),
		)[ $l->status ] ?? array( ucfirst( $l->status ), '' );
		$p     = CC_Fleet_Loans::pickup( $l );
		$img   = $l->bike_id ? get_the_post_thumbnail_url( $l->bike_id, 'medium_large' ) : '';
		$event = ( $l->event_id > 0 && class_exists( 'CC_Fleet_Records' ) ) ? CC_Fleet_Records::get( 'events', (int) $l->event_id ) : null;
		$rows  = array();
		if ( in_array( $l->status, array( 'approved', 'out' ), true ) ) {
			if ( 'approved' === $l->status ) {
				$rows[] = array( 'Pick-up', self::pickup_text( $p ) );
			}
			$rows[] = array( 'Return', self::return_text( $l ) );
		}
		if ( $event ) {
			$rows[] = array( 'Event', $event->name );
		}
		if ( CC_Fleet_Loans::pedals_label( $l ) ) {
			$rows[] = array( 'Pedals', CC_Fleet_Loans::pedals_label( $l ) );
		}
		if ( $l->plan ) {
			$rows[] = array( 'Your plan', wp_trim_words( $l->plan, 30 ) );
		}
		// Rider-to-rider: who it comes from / goes to, with a WhatsApp button when both agreed to share.
		$partner = array();
		if ( class_exists( 'CC_Fleet_Handoff' ) ) {
			$me  = CC_Fleet_Riders::get( $l->rider_id );
			$giv = 'approved' === $l->status ? CC_Fleet_Handoff::giver( $l ) : null;
			$rec = CC_Fleet_Handoff::receiver( $l );
			foreach ( array( array( $giv, 'Pick-up', 'Straight from %s, until %s. Agree a time together.', 'end_date' ), array( $rec, 'Return', 'Hand it straight to %s, who has it from %s. Agree a time together.', 'start_date' ) ) as $x ) {
				if ( ! $x[0] ) {
					continue;
				}
				$o    = CC_Fleet_Riders::get( $x[0]->rider_id );
				$name = $o ? strtok( $o->name, ' ' ) : 'the other rider';
				foreach ( $rows as $i => $row ) {
					if ( $row[0] === $x[1] ) {
						unset( $rows[ $i ] );
					}
				}
				array_unshift( $rows, array( $x[1], sprintf( $x[2], $name, self::fmt( $x[0]->{$x[3]} ) ) ) );
				if ( $o && $o->phone && CC_Fleet_Handoff::may_share( $o ) && CC_Fleet_Handoff::may_share( $me ) ) {
					$partner[] = array( 'WhatsApp ' . $name, CC_Fleet_Phone::wa_link( $o->phone, 'Hi ' . $name . ', it’s ' . strtok( $me->name, ' ' ) . ' from Community Connect, about the handover of ' . self::bike_label( $l ) . '.' ) );
				}
			}
			$rows = array_values( $rows );
		}
		$can_change = in_array( $l->status, array( 'requested', 'waiting', 'approved' ), true );
		$wa_link    = $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode( 'Hi! About my bike ' . self::bike_label( $l ) . ', ' . self::dates( $l ) . ': ' ) : '';
		ob_start();
		?>
		<article class="cc-me__card is-<?php echo esc_attr( $l->status ); ?>" data-loan="<?php echo (int) $l->id; ?>">
			<div class="cc-me__pic"><?php echo $img ? '<img src="' . esc_url( $img ) . '" alt="">' : '<span>' . esc_html( $l->bike_id ? CC_Fleet_Bikes::get( $l->bike_id, 'size' ) : $l->requested_size ) . '</span>'; ?></div>
			<div class="cc-me__body">
				<p class="cc-me__status"><span class="cc-me__pill"><?php echo esc_html( $status[0] ); ?></span><?php echo esc_html( $status[1] ); ?></p>
				<h2 class="cc-me__bike"><?php echo esc_html( self::bike_label( $l ) ); ?></h2>
				<p class="cc-me__dates"><?php echo esc_html( self::dates( $l ) ); ?></p>
				<?php if ( $rows ) : ?>
				<dl class="cc-me__rows">
					<?php foreach ( $rows as $row ) : ?><div><dt><?php echo esc_html( $row[0] ); ?></dt><dd><?php echo esc_html( $row[1] ); ?></dd></div><?php endforeach; ?>
				</dl>
				<?php endif; ?>
				<div class="cc-me__acts">
					<?php if ( in_array( $l->status, array( 'approved', 'out' ), true ) ) : ?>
					<a class="cc-me__btn" href="<?php echo esc_url( rest_url( CC_Fleet_REST::NS . '/me/loans/' . $l->id . '/calendar' ) ); ?>">Add to calendar</a>
					<?php endif; ?>
					<?php foreach ( $partner as $pb ) : ?><a class="cc-me__btn cc-me__btn--fill" href="<?php echo esc_url( $pb[1] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $pb[0] ); ?></a><?php endforeach; ?>
					<?php if ( $wa_link ) : ?><a class="cc-me__btn" href="<?php echo esc_url( $wa_link ); ?>" target="_blank" rel="noopener">WhatsApp the crew</a><?php endif; ?>
					<?php if ( $can_change ) : ?><button type="button" class="cc-me__btn" data-act="change">Change dates</button><?php endif; ?>
					<?php if ( 'out' !== $l->status ) : ?><button type="button" class="cc-me__btn cc-me__btn--quiet" data-act="cancel"><?php echo in_array( $l->status, array( 'approved' ), true ) ? 'Ask to cancel' : 'Cancel request'; ?></button><?php endif; ?>
				</div>
				<form class="cc-me__form" data-form="change" hidden>
					<p class="cc-me__ftitle">Which dates instead?</p>
					<div class="cc-me__dates2"><label>From<input type="date" name="start" value="<?php echo esc_attr( $l->start_date ); ?>" required></label><label>Until<input type="date" name="end" value="<?php echo esc_attr( $l->end_date ); ?>" required></label></div>
					<label>Anything we should know? <small>optional</small><textarea name="note" rows="2"></textarea></label>
					<button type="submit" class="cc-me__btn cc-me__btn--fill">Send to the crew</button>
					<p class="cc-me__fnote">The crew checks the bike and confirms by email. Nothing changes until then.</p>
				</form>
				<form class="cc-me__form" data-form="cancel" hidden>
					<p class="cc-me__ftitle"><?php echo 'approved' === $l->status ? 'Ask the crew to cancel this booking?' : 'Cancel this request?'; ?></p>
					<label>Why? <small>optional, helps us</small><textarea name="note" rows="2"></textarea></label>
					<button type="submit" class="cc-me__btn cc-me__btn--fill"><?php echo 'approved' === $l->status ? 'Ask to cancel' : 'Yes, cancel it'; ?></button>
				</form>
				<p class="cc-me__msg" role="status" hidden></p>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}

	private static function signed_out() {
		// phpcs:disable WordPress.Security.NonceVerification
		$note = isset( $_GET['expired'] ) ? 'That link has expired. Get a fresh one below.' : ( isset( $_GET['signed-out'] ) ? 'Signed out on this device.' : '' );
		// phpcs:enable
		ob_start();
		?>
		<section class="cc-me cc-me--out">
			<div class="cc-me__head max">
				<p class="cc-me__crumb">Community Connect&nbsp;&nbsp;/&nbsp;&nbsp;<span>My rides</span></p>
				<h1 class="cc-me__h1">My rides</h1>
				<p class="cc-me__lede">Your bookings, pick-up details and past rides. No password: we email you a private link.</p>
			</div>
			<form class="cc-me__link max" data-cc-link>
				<?php if ( $note ) : ?><p class="cc-me__note"><?php echo esc_html( $note ); ?></p><?php endif; ?>
				<label for="cc-me-email">The email you borrowed with</label>
				<div class="cc-me__linkrow"><input id="cc-me-email" type="email" name="email" autocomplete="email" required placeholder="you@example.com"><button type="submit" class="cc-me__btn cc-me__btn--fill">Email me my link</button></div>
				<p class="cc-me__msg" role="status" hidden></p>
				<p class="cc-me__fnote">The link works on this device for 30 days. First time borrowing? <a href="<?php echo esc_url( home_url( '/#fleet' ) ); ?>">Pick a bike</a>.</p>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}
}
