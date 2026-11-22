<?php
/**
 * Newsletter sign-up (cc-fleet 0.17.0).
 *
 * The list lives on this site: [cc_newsletter] collects email, name and interests, a confirmation
 * email makes it a double opt-in (POPIA: proof of consent, nothing sent before a yes), and every email
 * carries an unsubscribe link. Fleet Manager → Newsletter shows the list, exports it as CSV and, with a
 * Brevo API key, copies confirmed subscribers into a Brevo list to send the newsletter from there.
 *
 * @package CC_Fleet
 */

defined( 'ABSPATH' ) || exit;

class CC_Fleet_Newsletter {

	const OPT       = 'cc_fleet_newsletter';
	const INTERESTS = array(
		'rides'    => 'Group rides',
		'far'      => 'FAR Inverted',
		'events'   => 'Races & events',
		'weekends' => 'Social weekends',
		'fleet'    => 'New bikes in the fleet',
		'shop'     => 'Kit & shop drops',
	);
	const CONSENT = 'I agree to receive the Community Connect newsletter by email. It’s only sent when there’s actual news, and I can unsubscribe with one click in every email.';

	public static function init() {
		add_shortcode( 'cc_newsletter', array( __CLASS__, 'shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_post_nopriv_cc_nl_subscribe', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_post_cc_nl_subscribe', array( __CLASS__, 'handle_post' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_links' ), 1 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 45 );
			foreach ( array( 'settings', 'export', 'sync', 'delete' ) as $a ) {
				add_action( 'admin_post_cc_nl_' . $a, array( __CLASS__, 'admin_' . $a ) );
			}
		}
	}

	public static function table() {
		return CC_Fleet_Install::table( 'subscribers' );
	}

	public static function create_table( $c ) {
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL DEFAULT '',
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			interests varchar(190) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			token varchar(64) NOT NULL DEFAULT '',
			consent text NULL,
			source varchar(60) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			confirmed_at datetime DEFAULT NULL,
			unsubscribed_at datetime DEFAULT NULL,
			synced_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY token (token),
			KEY status (status)
		) $c;" );
	}

	public static function settings() {
		return wp_parse_args( get_option( self::OPT, array() ), array( 'brevo_key' => '', 'brevo_list' => '', 'last_sync' => '', 'last_result' => '' ) );
	}

	/* =========================================================
	 * Sign-up
	 * ========================================================= */

	/**
	 * Adds or refreshes a sign-up and sends the confirmation email.
	 * Returns 'check' (email sent), 'already' (confirmed before) or a WP_Error.
	 */
	public static function subscribe( array $in, $source = 'web' ) {
		global $wpdb;
		$email = sanitize_email( (string) ( $in['email'] ?? '' ) );
		$first = sanitize_text_field( (string) ( $in['first_name'] ?? '' ) );
		$last  = sanitize_text_field( (string) ( $in['last_name'] ?? '' ) );
		$ints  = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['interests'] ?? array() ) ), array_keys( self::INTERESTS ) ) );
		$errors = array();
		if ( ! is_email( $email ) ) {
			$errors['email'] = 'Please enter a valid email address.';
		}
		if ( '' === $first ) {
			$errors['first_name'] = 'Please tell us your first name.';
		}
		if ( empty( $in['consent'] ) ) {
			$errors['consent'] = 'Please tick the box so we may email you.';
		}
		if ( $errors ) {
			return new WP_Error( 'cc_nl_invalid', 'Please check the form.', array( 'fields' => $errors ) );
		}
		$email = strtolower( $email );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s', $email ) ); // phpcs:ignore
		if ( $row && 'confirmed' === $row->status ) {
			// Keep their latest name and interests; no second email.
			$wpdb->update( self::table(), array( 'first_name' => $first, 'last_name' => $last, 'interests' => implode( ',', $ints ) ), array( 'id' => $row->id ) );
			return 'already';
		}
		$token = wp_generate_password( 32, false, false );
		$data  = array(
			'email' => $email, 'first_name' => $first, 'last_name' => $last, 'interests' => implode( ',', $ints ),
			'status' => 'pending', 'token' => $token, 'source' => sanitize_key( $source ),
			'consent' => self::CONSENT . ' [' . current_time( 'mysql' ) . ', ' . self::ip_hash() . ']',
		);
		if ( $row ) {
			$wpdb->update( self::table(), $data + array( 'unsubscribed_at' => null ), array( 'id' => $row->id ) );
		} else {
			$wpdb->insert( self::table(), $data + array( 'created_at' => current_time( 'mysql' ) ) );
		}
		self::send_confirm( $email, $first, $token );
		return 'check';
	}

	/** A short, salted hash of the visitor's address: enough to spot abuse, not to identify anyone. */
	private static function ip_hash() {
		return substr( hash_hmac( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), wp_salt( 'nonce' ) ), 0, 12 ); // phpcs:ignore
	}

	/** At most 6 sign-ups per hour from one address. */
	private static function rate_ok() {
		$k = 'cc_nl_rate_' . self::ip_hash();
		$n = (int) get_transient( $k );
		if ( $n >= 6 ) {
			return false;
		}
		set_transient( $k, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	public static function link( $what, $token ) {
		return add_query_arg( array( 'cc_nl' => $what, 't' => $token ), home_url( '/' ) );
	}

	public static function send_confirm( $email, $first, $token ) {
		return CC_Fleet_Emails::send_now( $email, 'Confirm your Community Connect newsletter sign-up', self::msg_confirm( array(
			'name' => $first, 'confirm' => self::link( 'confirm', $token ), 'unsub' => self::link( 'unsub', $token ),
		) ), array( 'type' => 'nl_confirm' ) );
	}

	public static function msg_confirm( $f ) {
		return array(
			'kicker' => 'Newsletter', 'headline' => 'One tap and you’re in, ' . $f['name'] . '.', 'subline' => 'Confirm your email address.',
			'preheader' => 'Confirm your sign-up to the Community Connect newsletter',
			'blocks' => array(
				array( 'p', "Hi {$f['name']},\n\nThanks for signing up. Please confirm that this is your email address and that you want our newsletter: rides, events, new bikes in the fleet. Only when there’s actual news." ),
				array( 'buttons', array( array( 'Yes, sign me up', $f['confirm'], true ) ) ),
				array( 'small', 'Didn’t sign up? Ignore this email and you won’t hear from us. Changed your mind later? Every newsletter has an unsubscribe link, or use this one: ' . $f['unsub'] ),
			),
		);
	}

	/** Confirm / unsubscribe links from the emails. */
	public static function handle_links() {
		$what = sanitize_key( (string) ( $_GET['cc_nl'] ?? '' ) ); // phpcs:ignore
		$tok  = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $_GET['t'] ?? '' ) ); // phpcs:ignore
		if ( ! in_array( $what, array( 'confirm', 'unsub' ), true ) || strlen( $tok ) < 20 ) {
			return;
		}
		global $wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', $tok ) ); // phpcs:ignore
		$flag = 'invalid';
		if ( $row && 'confirm' === $what ) {
			if ( 'confirmed' !== $row->status ) {
				$wpdb->update( self::table(), array( 'status' => 'confirmed', 'confirmed_at' => current_time( 'mysql' ), 'unsubscribed_at' => null ), array( 'id' => $row->id ) );
				self::brevo_push( (int) $row->id );
			}
			$flag = 'confirmed';
		} elseif ( $row && 'unsub' === $what ) {
			$wpdb->update( self::table(), array( 'status' => 'unsubscribed', 'unsubscribed_at' => current_time( 'mysql' ) ), array( 'id' => $row->id ) );
			self::brevo_unsub( $row->email );
			$flag = 'bye';
		}
		wp_safe_redirect( add_query_arg( 'nl', $flag, home_url( '/' ) ) . '#newsletter' );
		exit;
	}

	/* =========================================================
	 * Form + endpoints
	 * ========================================================= */

	public static function routes() {
		register_rest_route( CC_Fleet_Rest::NS, '/newsletter', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest' ), 'permission_callback' => '__return_true',
		) );
	}

	public static function rest( WP_REST_Request $r ) {
		$p = $r->get_json_params() ?: $r->get_body_params();
		if ( ! empty( $p['website'] ) ) {
			return array( 'ok' => true, 'state' => 'check' ); // Honeypot: look fine, do nothing.
		}
		if ( ! self::rate_ok() ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many sign-ups from here. Please try again in an hour.' ), 429 );
		}
		$res = self::subscribe( (array) $p );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $res->get_error_message(), 'fields' => $res->get_error_data()['fields'] ?? array() ), 400 );
		}
		return array( 'ok' => true, 'state' => $res, 'message' => self::message( $res ) );
	}

	/** Without JavaScript the form posts here and comes back with ?nl=… */
	public static function handle_post() {
		$p     = wp_unslash( $_POST ); // phpcs:ignore
		$state = 'check';
		if ( empty( $p['website'] ) ) {
			$state = self::rate_ok() ? self::subscribe( (array) $p ) : 'limit';
			$state = is_wp_error( $state ) ? 'error' : $state;
		}
		wp_safe_redirect( add_query_arg( 'nl', $state, wp_get_referer() ?: home_url( '/' ) ) . '#newsletter' );
		exit;
	}

	public static function message( $state ) {
		return array(
			'check'     => 'Nearly there: we’ve sent you an email. Tap the link in it to confirm.',
			'already'   => 'You’re already on the list. We’ve kept your latest details.',
			'confirmed' => 'You’re in. Thanks for signing up!',
			'bye'       => 'You’re unsubscribed. No more newsletters from us.',
			'invalid'   => 'That link didn’t work any more. Sign up again below.',
			'error'     => 'Please fill in your email, first name and tick the box.',
			'limit'     => 'Too many sign-ups from here. Please try again in an hour.',
		)[ $state ] ?? '';
	}

	public static function shortcode() {
		$state = sanitize_key( (string) ( $_GET['nl'] ?? '' ) ); // phpcs:ignore
		$msg   = self::message( $state );
		$api   = esc_url( rest_url( CC_Fleet_Rest::NS . '/newsletter' ) );
		ob_start();
		?>
		<form class="cc-nl" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-api="<?php echo $api; // phpcs:ignore ?>" novalidate>
			<input type="hidden" name="action" value="cc_nl_subscribe">
			<p class="cc-nl__msg" role="status" aria-live="polite"<?php echo $msg ? '' : ' hidden'; ?>><?php echo esc_html( $msg ); ?></p>
			<div class="cc-nl__fields">
				<label class="cc-nl__f cc-nl__f--wide"><span>Email</span><input type="email" name="email" autocomplete="email" placeholder="Your email address" required></label>
				<label class="cc-nl__f"><span>First name</span><input type="text" name="first_name" autocomplete="given-name" placeholder="First name" required></label>
				<label class="cc-nl__f"><span>Last name <small>optional</small></span><input type="text" name="last_name" autocomplete="family-name" placeholder="Last name"></label>
			</div>
			<fieldset class="cc-nl__ints"><legend>I’m into (tick all that apply)</legend>
				<?php foreach ( self::INTERESTS as $k => $l ) : ?>
					<label><input type="checkbox" name="interests[]" value="<?php echo esc_attr( $k ); ?>"> <span><?php echo esc_html( $l ); ?></span></label>
				<?php endforeach; ?>
			</fieldset>
			<label class="cc-nl__hp" aria-hidden="true">Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
			<label class="cc-nl__consent"><input type="checkbox" name="consent" value="1" required> <span><?php echo esc_html( self::CONSENT ); ?> Details in our <a href="<?php echo esc_url( get_privacy_policy_url() ?: home_url( '/privacy-policy/' ) ); ?>">privacy policy</a>.</span></label>
			<button type="submit" class="cc-nl__btn">Subscribe</button>
		</form>
		<?php
		if ( ! has_action( 'wp_footer', array( __CLASS__, 'footer_script' ) ) ) {
			add_action( 'wp_footer', array( __CLASS__, 'footer_script' ), 30 );
		}
		// One line: the shortcode block runs wpautop, which would put <br>s into a multi-line form.
		return preg_replace( '/>\s+</', '><', trim( (string) ob_get_clean() ) );
	}

	/** Sends the form without leaving the page (the plain form still works without JavaScript). */
	public static function footer_script() {
		?>
		<script>
		(function () {
			if (!window.fetch) return;
			document.querySelectorAll('form.cc-nl').forEach(function (f) {
			f.addEventListener('submit', function (e) {
				e.preventDefault();
				var fd = new FormData(f), msg = f.querySelector('.cc-nl__msg'), btn = f.querySelector('button');
				var data = { email: fd.get('email'), first_name: fd.get('first_name'), last_name: fd.get('last_name'), interests: fd.getAll('interests[]'), consent: fd.get('consent') ? 1 : 0, website: fd.get('website') };
				btn.disabled = true;
				fetch(f.dataset.api, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
					.then(function (r) { return r.json(); })
					.then(function (r) {
						var fields = r.fields || {};
						msg.textContent = r.ok ? r.message : (Object.keys(fields).map(function (k) { return fields[k]; }).join(' ') || r.message);
						msg.hidden = false; msg.classList.toggle('is-error', !r.ok);
						if (r.ok) { f.classList.add('is-done'); document.dispatchEvent(new CustomEvent('cc:newsletter', { detail: { interests: data.interests } })); }
					})
					.catch(function () { msg.textContent = 'Something went wrong. Please try again.'; msg.hidden = false; msg.classList.add('is-error'); })
					.finally(function () { btn.disabled = false; });
			});
			});
		})();
		</script>
		<?php
	}

	/* =========================================================
	 * Brevo (optional): confirmed subscribers go into a Brevo list
	 * ========================================================= */

	private static function brevo( $method, $path, $body = null ) {
		$s = self::settings();
		if ( ! $s['brevo_key'] ) {
			return new WP_Error( 'cc_nl_nokey', 'No Brevo API key.' );
		}
		$args = array(
			'method' => $method, 'timeout' => 15,
			'headers' => array( 'api-key' => $s['brevo_key'], 'accept' => 'application/json', 'content-type' => 'application/json' ),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res  = wp_remote_request( 'https://api.brevo.com/v3' . $path, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 300 ) {
			$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			return new WP_Error( 'cc_nl_brevo', 'Brevo ' . $code . ': ' . ( $j['message'] ?? 'error' ) );
		}
		return true;
	}

	public static function brevo_push( $id ) {
		global $wpdb;
		$s   = self::settings();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore
		if ( ! $row || ! $s['brevo_key'] || 'confirmed' !== $row->status ) {
			return false;
		}
		$body = array( 'email' => $row->email, 'attributes' => array( 'FIRSTNAME' => $row->first_name, 'LASTNAME' => $row->last_name ), 'updateEnabled' => true, 'emailBlacklisted' => false );
		if ( (int) $s['brevo_list'] ) {
			$body['listIds'] = array( (int) $s['brevo_list'] );
		}
		$res = self::brevo( 'POST', '/contacts', $body );
		if ( true === $res ) {
			$wpdb->update( self::table(), array( 'synced_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		}
		return $res;
	}

	public static function brevo_unsub( $email ) {
		if ( ! self::settings()['brevo_key'] ) {
			return false;
		}
		return self::brevo( 'PUT', '/contacts/' . rawurlencode( $email ), array( 'emailBlacklisted' => true ) );
	}

	/* =========================================================
	 * Admin: Fleet Manager → Newsletter
	 * ========================================================= */

	public static function menu() {
		add_submenu_page( 'cc-fleet', 'Newsletter', 'Newsletter', 'manage_options', 'cc-fleet-newsletter', array( __CLASS__, 'page' ) );
	}

	public static function counts() {
		global $wpdb;
		$out = array( 'confirmed' => 0, 'pending' => 0, 'unsubscribed' => 0 );
		foreach ( (array) $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . self::table() . ' GROUP BY status' ) as $r ) { // phpcs:ignore
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$s    = self::settings();
		$c    = self::counts();
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC LIMIT 500' ); // phpcs:ignore
		$note = get_transient( 'cc_nl_notice_' . get_current_user_id() );
		delete_transient( 'cc_nl_notice_' . get_current_user_id() );
		echo '<div class="wrap"><h1>Newsletter</h1>';
		if ( $note ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $note ) . '</p></div>';
		}
		printf( '<p><b>%d confirmed</b> · %d waiting for their confirmation · %d unsubscribed. Only confirmed subscribers may get the newsletter.</p>', (int) $c['confirmed'], (int) $c['pending'], (int) $c['unsubscribed'] );
		echo '<p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cc_nl_export' ), 'cc_nl_export' ) ) . '">Export confirmed (CSV)</a></p>';

		echo '<h2>Send with Brevo</h2><p>Paste an API key from Brevo (Settings → SMTP &amp; API → API keys) and the ID of the list to fill. Every confirmed subscriber is copied there, and unsubscribes are passed on. Write and send the newsletter in Brevo.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cc_nl_settings">';
		wp_nonce_field( 'cc_nl_settings' );
		echo '<table class="form-table"><tr><th>API key</th><td><input type="password" name="brevo_key" class="regular-text" autocomplete="off" placeholder="' . esc_attr( $s['brevo_key'] ? '•••• saved (' . substr( $s['brevo_key'], -4 ) . ')' : 'xkeysib-…' ) . '"><p class="description">Leave empty to keep the saved one.</p></td></tr>';
		echo '<tr><th>List ID</th><td><input type="number" name="brevo_list" value="' . esc_attr( $s['brevo_list'] ) . '" class="small-text"></td></tr></table>';
		submit_button( 'Save' );
		echo '</form>';
		if ( $s['brevo_key'] ) {
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cc_nl_sync' ), 'cc_nl_sync' ) ) . '">Copy all confirmed subscribers to Brevo now</a> ';
			echo $s['last_sync'] ? '<span class="description">Last: ' . esc_html( $s['last_sync'] . ' · ' . $s['last_result'] ) . '</span>' : '';
			echo '</p>';
		}

		echo '<h2>Subscribers</h2><table class="widefat striped"><thead><tr><th>Email</th><th>Name</th><th>Interests</th><th>Status</th><th>Signed up</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">No sign-ups yet.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$ints = array_map( function ( $k ) { return self::INTERESTS[ $k ] ?? $k; }, array_filter( explode( ',', $r->interests ) ) );
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s%s</td><td>%s</td><td><a href="%s" onclick="return confirm(\'Delete this subscriber for good?\')">Delete</a></td></tr>',
				esc_html( $r->email ), esc_html( trim( $r->first_name . ' ' . $r->last_name ) ), esc_html( implode( ', ', $ints ) ),
				esc_html( ucfirst( $r->status ) ), $r->synced_at ? ' <span class="description">· in Brevo</span>' : '',
				esc_html( mysql2date( 'j M Y', $r->created_at ) ),
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cc_nl_delete&id=' . (int) $r->id ), 'cc_nl_delete_' . (int) $r->id ) ) );
		}
		echo '</tbody></table></div>';
	}

	private static function back( $note ) {
		set_transient( 'cc_nl_notice_' . get_current_user_id(), $note, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=cc-fleet-newsletter' ) );
		exit;
	}

	public static function admin_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cc_nl_settings' ) ) {
			wp_die( 'Not allowed.' );
		}
		$s   = self::settings();
		$key = trim( sanitize_text_field( wp_unslash( $_POST['brevo_key'] ?? '' ) ) ); // phpcs:ignore
		if ( $key ) {
			$s['brevo_key'] = $key;
		}
		$s['brevo_list'] = (string) absint( $_POST['brevo_list'] ?? 0 ); // phpcs:ignore
		update_option( self::OPT, $s, false );
		self::back( 'Saved.' );
	}

	public static function admin_sync() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cc_nl_sync' ) ) {
			wp_die( 'Not allowed.' );
		}
		global $wpdb;
		$ok  = 0;
		$err = '';
		foreach ( (array) $wpdb->get_col( 'SELECT id FROM ' . self::table() . " WHERE status = 'confirmed'" ) as $id ) { // phpcs:ignore
			$r = self::brevo_push( (int) $id );
			if ( true === $r ) {
				$ok++;
			} elseif ( is_wp_error( $r ) ) {
				$err = $r->get_error_message();
			}
		}
		$s                = self::settings();
		$s['last_sync']   = current_time( 'mysql' );
		$s['last_result'] = $ok . ' copied' . ( $err ? ' · ' . $err : '' );
		update_option( self::OPT, $s, false );
		self::back( 'Brevo: ' . $s['last_result'] );
	}

	public static function admin_delete() {
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cc_nl_delete_' . $id ) ) {
			wp_die( 'Not allowed.' );
		}
		global $wpdb;
		$email = $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore
		if ( $email ) {
			self::brevo_unsub( $email );
			$wpdb->delete( self::table(), array( 'id' => $id ) );
		}
		self::back( $email ? 'Deleted ' . $email . '.' : 'Not found.' );
	}

	public static function admin_export() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cc_nl_export' ) ) {
			wp_die( 'Not allowed.' );
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT email, first_name, last_name, interests, confirmed_at FROM ' . self::table() . " WHERE status = 'confirmed' ORDER BY confirmed_at" ); // phpcs:ignore
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=newsletter-' . wp_date( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'EMAIL', 'FIRSTNAME', 'LASTNAME', 'INTERESTS', 'CONFIRMED' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, array( $r->email, $r->first_name, $r->last_name, $r->interests, $r->confirmed_at ) );
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}
}
