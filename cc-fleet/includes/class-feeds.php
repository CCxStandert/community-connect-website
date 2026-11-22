<?php
defined( 'ABSPATH' ) || exit;

/**
 * Outside feeds for the website:
 * - Strava: the club's group events become events on the page (hourly).
 * - Instagram: the latest posts, with their images kept on this site, for the "On Instagram" section.
 *
 * Settings and status: Fleet Manager → Connections. Tokens are stored as options (never autoloaded)
 * and only ever shown masked.
 */
class CC_Fleet_Feeds {

	const HOOK      = 'cc_fleet_feeds';
	const OPT_STRAVA = 'cc_fleet_strava';
	const OPT_IG     = 'cc_fleet_instagram';
	const OPT_POSTS  = 'cc_fleet_ig_posts';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_event( time() + 300, 'hourly', self::HOOK );
			}
		} );
		add_shortcode( 'cc_instagram', array( __CLASS__, 'sc_instagram' ) );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
			foreach ( array( 'save', 'strava_connect', 'strava_callback', 'strava_disconnect', 'sync', 'ig_disconnect' ) as $a ) {
				add_action( 'admin_post_cc_fleet_feeds_' . $a, array( __CLASS__, 'handle_' . $a ) );
			}
		}
	}

	/** Hourly: Strava every hour, Instagram every 3 hours, token refresh when due. */
	public static function run() {
		$out = array();
		if ( self::strava_ready() ) {
			$out['strava'] = self::strava_sync();
		}
		$ig = get_option( self::OPT_IG, array() );
		if ( ! empty( $ig['token'] ) && ( empty( $ig['fetched'] ) || time() - (int) $ig['fetched'] > 3 * HOUR_IN_SECONDS ) ) {
			$out['instagram'] = self::ig_fetch();
		}
		return $out;
	}

	/* =========================================================
	 * Strava
	 * ========================================================= */

	public static function strava() {
		return wp_parse_args( get_option( self::OPT_STRAVA, array() ), array( 'client_id' => '', 'client_secret' => '', 'access' => '', 'refresh' => '', 'expires' => 0, 'athlete' => '', 'club_id' => '', 'club_name' => '', 'clubs' => array(), 'last' => '', 'last_result' => '' ) );
	}

	private static function strava_save( array $s ) {
		update_option( self::OPT_STRAVA, $s, false );
	}

	public static function strava_ready() {
		$s = self::strava();
		return $s['refresh'] && $s['club_id'];
	}

	/** The API base: the new address from 4 Jan 2027 (Strava changelog), with the old one as fallback. */
	private static function strava_bases() {
		$new = 'https://api-v3.strava.com';
		$old = 'https://www.strava.com/api/v3';
		return CC_Fleet_Settings::today() >= '2027-01-04' ? array( $new, $old ) : array( $old, $new );
	}

	private static function strava_redirect() {
		return admin_url( 'admin-post.php?action=cc_fleet_feeds_strava_callback' );
	}

	/** A valid access token, refreshed when it is about to run out. */
	private static function strava_token() {
		$s = self::strava();
		if ( $s['access'] && (int) $s['expires'] > time() + 120 ) {
			return $s['access'];
		}
		if ( ! $s['refresh'] || ! $s['client_id'] || ! $s['client_secret'] ) {
			return new WP_Error( 'cc_strava', 'Strava is not connected.' );
		}
		$res = wp_remote_post( 'https://www.strava.com/oauth/token', array( 'timeout' => 15, 'body' => array(
			'client_id' => $s['client_id'], 'client_secret' => $s['client_secret'], 'grant_type' => 'refresh_token', 'refresh_token' => $s['refresh'],
		) ) );
		$j = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $j['access_token'] ) ) {
			return new WP_Error( 'cc_strava', 'Strava refused the refresh: reconnect under Fleet Manager → Connections.' );
		}
		$s['access']  = $j['access_token'];
		$s['refresh'] = $j['refresh_token'] ?? $s['refresh'];
		$s['expires'] = (int) ( $j['expires_at'] ?? time() + 3600 );
		self::strava_save( $s );
		return $s['access'];
	}

	private static function strava_get( $path, $args = array() ) {
		$token = self::strava_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$last = null;
		foreach ( self::strava_bases() as $base ) {
			$res  = wp_remote_get( add_query_arg( $args, $base . $path ), array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ) ) );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			if ( 200 === $code ) {
				return json_decode( wp_remote_retrieve_body( $res ), true );
			}
			$last = is_wp_error( $res ) ? $res->get_error_message() : 'Strava answered ' . $code . '.';
			if ( in_array( $code, array( 401, 403, 429 ), true ) ) {
				break; // Not a base-URL problem.
			}
		}
		return new WP_Error( 'cc_strava', $last ?: 'Strava did not answer.' );
	}

	/** Categories from a group event's title and text: social ride unless it says otherwise. */
	public static function strava_cats( $title, $desc = '' ) {
		$t    = strtolower( $title . ' ' . $desc );
		$cats = array();
		if ( preg_match( '/\brace\b|racing/', $t ) ) {
			$cats[] = 'race';
		}
		if ( preg_match( '/overnight|overnighter|bivvy|sleep ?out|camp(ing)? night/', $t ) ) {
			$cats[] = 'overnight';
		} elseif ( preg_match( '/bikepack|bike-pack|multi-?day/', $t ) ) {
			$cats[] = 'bikepacking';
		}
		if ( ! $cats || ! in_array( 'race', $cats, true ) ) {
			array_unshift( $cats, 'social' );
		}
		if ( false !== strpos( $t, 'gravel' ) ) {
			$cats[] = 'gravel';
		} elseif ( preg_match( '/\broad\b/', $t ) ) {
			$cats[] = 'road';
		}
		return array_values( array_unique( $cats ) );
	}

	/**
	 * Copy the club's upcoming group events into Events. One event per Strava event, on its next
	 * date (recurring rides move along). Changes made here to categories or "show on the website"
	 * are kept; title, date, place and link follow Strava.
	 */
	public static function strava_sync() {
		global $wpdb;
		$s    = self::strava();
		$list = self::strava_get( '/clubs/' . rawurlencode( $s['club_id'] ) . '/group_events', array( 'upcoming' => 'true', 'per_page' => 50 ) );
		$s    = self::strava(); // Re-read: the request may have refreshed the token.
		if ( is_wp_error( $list ) ) {
			$s['last'] = current_time( 'mysql' );
			$s['last_result'] = 'Error: ' . $list->get_error_message();
			self::strava_save( $s );
			return $list;
		}
		$t     = CC_Fleet_Records::table( 'events' );
		$tz    = wp_timezone();
		$res   = array( 'created' => 0, 'updated' => 0, 'skipped' => 0 );
		$today = CC_Fleet_Settings::today();
		foreach ( (array) $list as $ev ) {
			if ( empty( $ev['id'] ) || ! empty( $ev['private'] ) ) {
				$res['skipped']++;
				continue;
			}
			$next = '';
			foreach ( (array) ( $ev['upcoming_occurrences'] ?? array() ) as $occ ) {
				$dt = date_create_immutable( (string) $occ );
				if ( $dt ) {
					$local = $dt->setTimezone( $tz );
					if ( $local->format( 'Y-m-d' ) >= $today ) {
						$next = $local;
						break;
					}
				}
			}
			if ( ! $next ) {
				$res['skipped']++;
				continue;
			}
			$src   = 'strava:' . $ev['id'];
			$title = sanitize_text_field( (string) ( $ev['title'] ?? 'Club ride' ) );
			$desc  = trim( wp_strip_all_tags( (string) ( $ev['description'] ?? '' ) ) );
			$row   = array(
				'name'        => $title,
				'start_date'  => $next->format( 'Y-m-d' ),
				'end_date'    => null,
				'location'    => sanitize_text_field( (string) ( $ev['address'] ?? '' ) ),
				'link'        => esc_url_raw( 'https://www.strava.com/clubs/' . rawurlencode( (string) ( $ev['club_id'] ?? $s['club_id'] ) ) . '/group_events/' . rawurlencode( (string) $ev['id'] ) ),
				'description' => sanitize_textarea_field( $next->format( 'D j M, H:i' ) . ( ! empty( $ev['skill_levels'] ) ? ' · ' . self::strava_level( $ev['skill_levels'] ) : '' ) . "\n" . wp_trim_words( $desc, 60 ) . "\n(From the Strava club)" ),
				'updated_at'  => current_time( 'mysql' ),
			);
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE source = %s LIMIT 1", $src ) );
			if ( $id ) {
				$wpdb->update( $t, $row, array( 'id' => $id ) );
				$res['updated']++;
			} else {
				$row += array( 'type' => implode( ',', self::strava_cats( $title, $desc ) ), 'show_on_site' => 1, 'cancelled' => 0, 'source' => $src, 'created_at' => current_time( 'mysql' ) );
				$wpdb->insert( $t, $row );
				CC_Fleet_Install::log( 'events', (int) $wpdb->insert_id, 'strava', $title . ' ' . $row['start_date'] );
				$res['created']++;
			}
		}
		// Events taken off Strava (or made private) leave the website too; the row stays for the records.
		$seen = array_map( function ( $ev ) { return 'strava:' . ( $ev['id'] ?? '' ); }, array_filter( (array) $list, function ( $ev ) { return empty( $ev['private'] ); } ) );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, source FROM $t WHERE source LIKE %s AND start_date >= %s AND show_on_site = 1", 'strava:%', $today ) ) as $old ) {
			if ( ! in_array( $old->source, $seen, true ) ) {
				$wpdb->update( $t, array( 'show_on_site' => 0, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $old->id ) );
				$res['hidden'] = ( $res['hidden'] ?? 0 ) + 1;
			}
		}
		$s['last']        = current_time( 'mysql' );
		$s['last_result'] = sprintf( '%d new, %d updated, %d skipped', $res['created'], $res['updated'], $res['skipped'] ) . ( ! empty( $res['hidden'] ) ? ', ' . $res['hidden'] . ' no longer on Strava (hidden)' : '' );
		self::strava_save( $s );
		return $res;
	}

	private static function strava_level( $l ) {
		return array( 1 => 'Casual / no drop', 2 => 'Tempo', 4 => 'Hammerfest' )[ (int) $l ] ?? 'All levels';
	}

	/* =========================================================
	 * Instagram
	 * ========================================================= */

	public static function ig() {
		return wp_parse_args( get_option( self::OPT_IG, array() ), array( 'token' => '', 'token_set' => 0, 'expires' => 0, 'refreshed' => 0, 'fetched' => 0, 'last_result' => '', 'username' => '', 'ig_id' => '', 'api' => '' ) );
	}

	/**
	 * Two kinds of token: an Instagram-login token (IGAA…, renew every 60 days, graph.instagram.com)
	 * or, for newer Meta apps, a Facebook / system-user token (EAA…, often never expires, graph.facebook.com
	 * with the Instagram account's ID).
	 */
	public static function ig_is_fb( $token ) {
		return '' !== $token && 0 !== strpos( $token, 'IG' );
	}

	/** The Instagram business account ID for a Facebook token: saved, or found through the Pages the token can see. */
	private static function ig_find_id( array $ig ) {
		if ( $ig['ig_id'] ) {
			return $ig['ig_id'];
		}
		foreach ( array( 'me/accounts?fields=instagram_business_account{id,username}&limit=50', 'me?fields=instagram_business_account{id,username}' ) as $q ) {
			$res = wp_remote_get( 'https://graph.facebook.com/' . $q . '&access_token=' . rawurlencode( $ig['token'] ), array( 'timeout' => 15 ) );
			$j   = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
			$rows = isset( $j['data'] ) ? $j['data'] : ( $j ? array( $j ) : array() );
			foreach ( (array) $rows as $row ) {
				if ( ! empty( $row['instagram_business_account']['id'] ) ) {
					return preg_replace( '/[^0-9]/', '', $row['instagram_business_account']['id'] );
				}
			}
		}
		return '';
	}

	/** Refresh the long-lived token when it is older than a day and has under 30 days left. */
	private static function ig_refresh_if_due( array $ig ) {
		if ( 'fb' === $ig['api'] || ( '' === $ig['api'] && 0 === strpos( $ig['token'], 'EAA' ) ) ) {
			return $ig; // Facebook / system-user tokens are not renewed this way.
		}
		$age  = time() - (int) max( $ig['refreshed'], $ig['token_set'] );
		$left = (int) $ig['expires'] ? (int) $ig['expires'] - time() : 0;
		if ( $age < DAY_IN_SECONDS || ( (int) $ig['expires'] && $left > 30 * DAY_IN_SECONDS ) ) {
			return $ig;
		}
		$res = wp_remote_get( add_query_arg( array( 'grant_type' => 'ig_refresh_token', 'access_token' => $ig['token'] ), 'https://graph.instagram.com/refresh_access_token' ), array( 'timeout' => 15 ) );
		$j   = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! empty( $j['access_token'] ) ) {
			$ig['token']     = $j['access_token'];
			$ig['expires']   = time() + (int) ( $j['expires_in'] ?? 60 * DAY_IN_SECONDS );
			$ig['refreshed'] = time();
		}
		return $ig;
	}

	/** Latest posts, images saved here (640 px WebP) so they load fast and never expire. */
	public static function ig_fetch() {
		$ig = self::ig_refresh_if_due( self::ig() );
		if ( ! $ig['token'] ) {
			return new WP_Error( 'cc_ig', 'No Instagram token.' );
		}
		// Try each way Meta serves an account's posts; remember the one that works.
		$fields = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,username';
		$tries  = array();
		if ( 'fb' === $ig['api'] && $ig['ig_id'] ) {
			$tries[] = array( 'fb', 'https://graph.facebook.com/' . rawurlencode( $ig['ig_id'] ) . '/media' );
		}
		$tries[] = array( 'ig', 'https://graph.instagram.com/me/media' );
		if ( $ig['ig_id'] ) {
			$tries[] = array( 'fb', 'https://graph.facebook.com/' . rawurlencode( $ig['ig_id'] ) . '/media' );
			$tries[] = array( 'ig', 'https://graph.instagram.com/' . rawurlencode( $ig['ig_id'] ) . '/media' );
		}
		$tries[] = array( 'find', '' );
		$j      = null;
		$errors = array();
		foreach ( $tries as $try ) {
			list( $api, $endpoint ) = $try;
			if ( 'find' === $api ) {
				$found = self::ig_find_id( array_merge( $ig, array( 'ig_id' => '' ) ) );
				if ( ! $found ) {
					continue;
				}
				$ig['ig_id'] = $found;
				$api         = 'fb';
				$endpoint    = 'https://graph.facebook.com/' . rawurlencode( $found ) . '/media';
			}
			$res = wp_remote_get( add_query_arg( array( 'fields' => $fields, 'limit' => 12, 'access_token' => $ig['token'] ), $endpoint ), array( 'timeout' => 20 ) );
			$out = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
			if ( isset( $out['data'] ) && is_array( $out['data'] ) ) {
				$j         = $out;
				$ig['api'] = $api;
				break;
			}
			$errors[] = wp_parse_url( $endpoint, PHP_URL_HOST ) . ': ' . ( is_wp_error( $res ) ? $res->get_error_message() : ( $out['error']['message'] ?? 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );
		}
		$ig['fetched'] = time();
		if ( ! $j ) {
			$ig['last_result'] = 'Error: Instagram did not give the posts (' . implode( ' | ', array_unique( $errors ) ) . ')';
			update_option( self::OPT_IG, $ig, false );
			return new WP_Error( 'cc_ig', $ig['last_result'] );
		}
		if ( ! $j['data'] ) {
			$ig['last_result'] = 'Connected, but the account has no posts yet.';
			update_option( self::OPT_IG, $ig, false );
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$up   = wp_upload_dir();
		$dir  = trailingslashit( $up['basedir'] ) . 'cc-instagram';
		$base = trailingslashit( $up['baseurl'] ) . 'cc-instagram';
		wp_mkdir_p( $dir );
		$posts = array();
		$keep  = array();
		foreach ( $j['data'] as $m ) {
			if ( count( $posts ) >= 8 ) {
				break;
			}
			$src = 'VIDEO' === ( $m['media_type'] ?? '' ) ? ( $m['thumbnail_url'] ?? '' ) : ( $m['media_url'] ?? '' );
			if ( ! $src || empty( $m['id'] ) ) {
				continue;
			}
			$name = 'ig-' . preg_replace( '/[^0-9_]/', '', (string) $m['id'] ) . '.webp';
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				$tmp = download_url( $src, 20 );
				if ( is_wp_error( $tmp ) ) {
					continue;
				}
				$ed = wp_get_image_editor( $tmp );
				if ( is_wp_error( $ed ) ) {
					@unlink( $tmp ); // phpcs:ignore
					continue;
				}
				$ed->resize( 640, 640, true );
				$ed->set_quality( 78 );
				$saved = $ed->save( $path, 'image/webp' );
				@unlink( $tmp ); // phpcs:ignore
				if ( is_wp_error( $saved ) ) {
					// Server without WebP support: keep a JPEG instead.
					$name  = str_replace( '.webp', '.jpg', $name );
					$path  = $dir . '/' . $name;
					$ed2   = wp_get_image_editor( download_url( $src, 20 ) );
					if ( is_wp_error( $ed2 ) || is_wp_error( $ed2->resize( 640, 640, true ) ) || is_wp_error( $ed2->save( $path, 'image/jpeg' ) ) ) {
						continue;
					}
				}
			}
			$keep[]  = basename( $path );
			$posts[] = array(
				'id'        => (string) $m['id'],
				'permalink' => esc_url_raw( (string) ( $m['permalink'] ?? '' ) ),
				'caption'   => wp_strip_all_tags( (string) ( $m['caption'] ?? '' ) ),
				'time'      => (string) ( $m['timestamp'] ?? '' ),
				'type'      => (string) ( $m['media_type'] ?? 'IMAGE' ),
				'img'       => $base . '/' . basename( $path ),
			);
			if ( ! empty( $m['username'] ) ) {
				$ig['username'] = sanitize_user( $m['username'] );
			}
		}
		// Tidy up images of posts that dropped off the list.
		foreach ( (array) glob( $dir . '/ig-*' ) as $f ) {
			if ( ! in_array( basename( $f ), $keep, true ) ) {
				wp_delete_file( $f );
			}
		}
		update_option( self::OPT_POSTS, $posts, true );
		$ig['last_result'] = count( $posts ) . ' posts';
		update_option( self::OPT_IG, $ig, false );
		return count( $posts );
	}

	/** [cc_instagram count="4"]: the whole section, or nothing when there are no posts. */
	public static function sc_instagram( $atts ) {
		$a     = shortcode_atts( array( 'count' => 4, 'title' => 'On Instagram', 'handle' => '' ), $atts, 'cc_instagram' );
		$posts = array_slice( (array) get_option( self::OPT_POSTS, array() ), 0, max( 1, min( 8, (int) $a['count'] ) ) );
		if ( ! $posts ) {
			return '';
		}
		$ig     = self::ig();
		$handle = $a['handle'] ?: ( $ig['username'] ?: ( CC_Fleet_Settings::get( 'instagram_handle' ) ?: 'cc_x_standert' ) );
		$profile = 'https://www.instagram.com/' . rawurlencode( ltrim( $handle, '@' ) ) . '/';
		$h  = '<section class="sect cc-part cc-part--ig" aria-labelledby="instagram"><div class="in max igh"><div><h2 class="h" id="instagram">' . esc_html( $a['title'] ) . '</h2>';
		$h .= '<p class="sub">What the bikes have been up to lately.</p></div>';
		$h .= '<a class="pillbtn line" href="' . esc_url( $profile ) . '" target="_blank" rel="noopener">@' . esc_html( ltrim( $handle, '@' ) ) . ' ↗</a></div>';
		$h .= '<ul class="iggrid max">';
		foreach ( $posts as $i => $p ) {
			$when = $p['time'] ? wp_date( 'j M Y', strtotime( $p['time'] ) ) : '';
			$cap  = wp_trim_words( preg_replace( '/\s+/', ' ', $p['caption'] ), 18, '…' );
			$h   .= sprintf(
				'<li><a class="igpost" href="%s" target="_blank" rel="noopener"><span class="igimg"><img src="%s" alt="%s" loading="%s" decoding="async" width="640" height="640">%s</span><span class="igmeta"><time class="mono" datetime="%s">[%s]</time><span class="igcap">%s</span></span></a></li>',
				esc_url( $p['permalink'] ), esc_url( $p['img'] ), esc_attr( wp_trim_words( $p['caption'], 14, '' ) ?: 'Instagram post by @' . $handle ), $i < 2 ? 'eager' : 'lazy',
				'VIDEO' === $p['type'] ? '<span class="igbadge" aria-label="Video">▶</span>' : ( 'CAROUSEL_ALBUM' === $p['type'] ? '<span class="igbadge" aria-label="Several photos">⧉</span>' : '' ),
				esc_attr( $p['time'] ), esc_html( $when ), esc_html( $cap )
			);
		}
		return $h . '</ul></section>';
	}

	/* =========================================================
	 * Admin: Fleet Manager → Connections
	 * ========================================================= */

	public static function menu() {
		add_submenu_page( 'cc-fleet', 'Connections', 'Connections', 'manage_options', 'cc-fleet-connections', array( __CLASS__, 'page' ) );
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'cc-fleet-connections' ), $args ), admin_url( 'admin.php' ) );
	}

	private static function back( $msg, $type = 'success' ) {
		set_transient( 'cc_fleet_feeds_notice_' . get_current_user_id(), array( $msg, $type ), 60 );
		wp_safe_redirect( self::url() );
		exit;
	}

	private static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( $nonce );
	}

	private static function mask( $v ) {
		return $v ? '•••••••• ' . substr( $v, -4 ) : '';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$n = get_transient( 'cc_fleet_feeds_notice_' . get_current_user_id() );
		echo '<div class="wrap cc-wrap"><h1>Connections</h1>';
		if ( $n ) {
			delete_transient( 'cc_fleet_feeds_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[1] ), esc_html( $n[0] ) );
		}
		$s  = self::strava();
		$ig = self::ig();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cc_fleet_feeds_save' );
		echo '<input type="hidden" name="action" value="cc_fleet_feeds_save">';

		// Strava.
		echo '<h2>Strava club events</h2><p>Group events from the Community Connect club on Strava appear under Events and on the website, as social rides unless the title says race or overnight. Checked every hour.</p>';
		echo '<table class="form-table" role="presentation">';
		printf( '<tr><th><label for="cc-sid">Client ID</label></th><td><input id="cc-sid" name="strava[client_id]" value="%s" class="regular-text" inputmode="numeric"></td></tr>', esc_attr( $s['client_id'] ) );
		printf( '<tr><th><label for="cc-ssec">Client Secret</label></th><td><input id="cc-ssec" type="password" name="strava[client_secret]" class="regular-text" autocomplete="new-password" placeholder="%s"><p class="description">From <a href="https://www.strava.com/settings/api" target="_blank" rel="noopener">strava.com/settings/api</a>. Set “Authorization Callback Domain” there to <code>%s</code>. Leave empty to keep the saved one.</p></td></tr>', esc_attr( $s['client_secret'] ? self::mask( $s['client_secret'] ) . ' saved' : '' ), esc_html( wp_parse_url( admin_url(), PHP_URL_HOST ) ) );
		echo '<tr><th>Account</th><td>';
		if ( $s['refresh'] ) {
			printf( '<p>Connected as <strong>%s</strong>. <a href="%s">Disconnect</a></p>', esc_html( $s['athlete'] ?: 'your Strava account' ), esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cc_fleet_feeds_strava_disconnect' ), 'cc_fleet_feeds_strava_disconnect' ) ) );
		} elseif ( $s['client_id'] && $s['client_secret'] ) {
			printf( '<p><a class="button button-primary" href="%s">Connect Strava</a> <span class="description">Sign in with an account that is a member (ideally an admin) of the club.</span></p>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cc_fleet_feeds_strava_connect' ), 'cc_fleet_feeds_strava_connect' ) ) );
		} else {
			echo '<p class="description">Save the Client ID and Secret first, then connect.</p>';
		}
		echo '</td></tr>';
		if ( $s['refresh'] ) {
			echo '<tr><th><label for="cc-sclub">Club</label></th><td><select id="cc-sclub" name="strava[club_id]"><option value="">Choose…</option>';
			foreach ( (array) $s['clubs'] as $c ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $c['id'] ), selected( (string) $s['club_id'], (string) $c['id'], false ), esc_html( $c['name'] . ( ! empty( $c['url'] ) ? ' (' . $c['url'] . ')' : '' ) ) );
			}
			echo '</select></td></tr>';
			printf( '<tr><th>Last check</th><td>%s %s</td></tr>', esc_html( $s['last'] ? wp_date( 'j M, H:i', strtotime( $s['last'] ) ) : 'not yet' ), esc_html( $s['last_result'] ? '· ' . $s['last_result'] : '' ) );
		}
		echo '</table>';

		// Instagram.
		echo '<h2>Instagram</h2><p>The latest posts show in the “On Instagram” section of the home page, in the site’s style. The images are copied to this site so they load fast. Checked every 3 hours; the token renews itself.</p>';
		echo '<table class="form-table" role="presentation">';
		printf( '<tr><th><label for="cc-igt">Access token</label></th><td><input id="cc-igt" type="password" name="ig_token" class="large-text" autocomplete="new-password" placeholder="%s"><p class="description">The token from your Meta app for @cc_x_standert: a system-user token (newer apps, “API setup with Unified login”) or an Instagram-login token. The account must be a business or creator account. Leave empty to keep the saved one.</p></td></tr>', esc_attr( $ig['token'] ? self::mask( $ig['token'] ) . ' saved' : '' ) );
		printf( '<tr><th><label for="cc-igid">Instagram account ID</label></th><td><input id="cc-igid" name="ig_id" value="%s" class="regular-text" inputmode="numeric"><p class="description">Optional. Only needed if the site can’t find the account by itself (the long number Meta shows for the Instagram account).</p></td></tr>', esc_attr( $ig['ig_id'] ) );
		if ( $ig['token'] ) {
			printf( '<tr><th>Status</th><td>%s%s · %s <a href="%s">Remove</a></td></tr>', $ig['username'] ? '@' . esc_html( $ig['username'] ) . ' · ' : '', esc_html( $ig['expires'] ? 'token valid until ' . wp_date( 'j M Y', $ig['expires'] ) : 'token does not expire' ), esc_html( $ig['last_result'] ?: 'not fetched yet' ), esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cc_fleet_feeds_ig_disconnect' ), 'cc_fleet_feeds_ig_disconnect' ) ) );
		}
		echo '</table>';
		submit_button( 'Save' );
		echo '</form>';
		printf( '<p><a class="button" href="%s">Check Strava and Instagram now</a></p>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cc_fleet_feeds_sync' ), 'cc_fleet_feeds_sync' ) ) );
		$posts = (array) get_option( self::OPT_POSTS, array() );
		if ( $posts ) {
			echo '<h3>On the website now</h3><div style="display:flex;gap:8px;flex-wrap:wrap">';
			foreach ( array_slice( $posts, 0, 8 ) as $p ) {
				printf( '<a href="%s" target="_blank" rel="noopener"><img src="%s" alt="" width="96" height="96" style="object-fit:cover"></a>', esc_url( $p['permalink'] ), esc_url( $p['img'] ) );
			}
			echo '</div>';
		}
		echo '</div>';
	}

	public static function handle_save() {
		self::guard( 'cc_fleet_feeds_save' );
		$in = wp_unslash( $_POST );
		$s  = self::strava();
		$st = (array) ( $in['strava'] ?? array() );
		if ( isset( $st['client_id'] ) ) {
			$s['client_id'] = preg_replace( '/[^0-9]/', '', (string) $st['client_id'] );
		}
		if ( ! empty( $st['client_secret'] ) ) {
			$s['client_secret'] = preg_replace( '/[^A-Za-z0-9]/', '', (string) $st['client_secret'] );
		}
		if ( isset( $st['club_id'] ) ) {
			$s['club_id'] = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $st['club_id'] );
			foreach ( (array) $s['clubs'] as $c ) {
				if ( (string) $c['id'] === $s['club_id'] ) {
					$s['club_name'] = $c['name'];
				}
			}
		}
		self::strava_save( $s );
		$msg = 'Saved.';
		if ( isset( $in['ig_id'] ) ) {
			$ig = self::ig();
			$new_id = preg_replace( '/[^0-9]/', '', (string) $in['ig_id'] );
			if ( $new_id !== $ig['ig_id'] ) {
				$ig['ig_id'] = $new_id;
				update_option( self::OPT_IG, $ig, false );
				if ( $ig['token'] && empty( $in['ig_token'] ) ) {
					$r   = self::ig_fetch();
					$msg = is_wp_error( $r ) ? 'Saved, but Instagram said: ' . $r->get_error_message() : 'Instagram: ' . $r . ' posts on the website.';
				}
			}
		}
		if ( ! empty( $in['ig_token'] ) ) {
			$ig              = self::ig();
			$ig['token']     = preg_replace( '/[^A-Za-z0-9_\-|.]/', '', (string) $in['ig_token'] );
			$ig['token_set'] = time();
			$ig['expires']   = 0 === strpos( $ig['token'], 'EAA' ) ? 0 : time() + 60 * DAY_IN_SECONDS;
			$ig['api']       = '';
			$ig['refreshed'] = 0;
			update_option( self::OPT_IG, $ig, false );
			$r   = self::ig_fetch();
			$msg = is_wp_error( $r ) ? 'Token saved, but Instagram said: ' . $r->get_error_message() : 'Instagram connected: ' . $r . ' posts on the website.';
		}
		if ( self::strava_ready() && isset( $st['club_id'] ) ) {
			$r    = self::strava_sync();
			$msg .= is_wp_error( $r ) ? ' Strava: ' . $r->get_error_message() : sprintf( ' Strava: %d new events, %d updated.', $r['created'], $r['updated'] );
		}
		self::back( $msg, false !== strpos( $msg, 'said' ) || false !== strpos( $msg, 'Strava answered' ) ? 'warning' : 'success' );
	}

	public static function handle_strava_connect() {
		self::guard( 'cc_fleet_feeds_strava_connect' );
		$s     = self::strava();
		$state = wp_generate_password( 20, false );
		set_transient( 'cc_fleet_strava_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );
		wp_redirect( add_query_arg( array( // phpcs:ignore WordPress.Security.SafeRedirect -- Strava's own sign-in page.
			'client_id' => rawurlencode( $s['client_id'] ), 'redirect_uri' => rawurlencode( self::strava_redirect() ), 'response_type' => 'code',
			'approval_prompt' => 'auto', 'scope' => 'read', 'state' => $state,
		), 'https://www.strava.com/oauth/authorize' ) );
		exit;
	}

	public static function handle_strava_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$state = get_transient( 'cc_fleet_strava_state_' . get_current_user_id() );
		delete_transient( 'cc_fleet_strava_state_' . get_current_user_id() );
		if ( ! $state || ! hash_equals( $state, (string) ( $_GET['state'] ?? '' ) ) ) {
			self::back( 'The Strava sign-in expired. Try Connect again.', 'error' );
		}
		if ( empty( $_GET['code'] ) ) {
			self::back( 'Strava did not give access (' . sanitize_text_field( wp_unslash( $_GET['error'] ?? 'cancelled' ) ) . ').', 'error' );
		}
		$s   = self::strava();
		$res = wp_remote_post( 'https://www.strava.com/oauth/token', array( 'timeout' => 15, 'body' => array(
			'client_id' => $s['client_id'], 'client_secret' => $s['client_secret'], 'code' => sanitize_text_field( wp_unslash( $_GET['code'] ) ), 'grant_type' => 'authorization_code',
		) ) );
		$j = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $j['refresh_token'] ) ) {
			self::back( 'Strava refused the connection: check the Client ID and Secret.', 'error' );
		}
		$s['access']  = $j['access_token'];
		$s['refresh'] = $j['refresh_token'];
		$s['expires'] = (int) $j['expires_at'];
		$s['athlete'] = trim( ( $j['athlete']['firstname'] ?? '' ) . ' ' . ( $j['athlete']['lastname'] ?? '' ) );
		self::strava_save( $s );
		$clubs = self::strava_get( '/athlete/clubs', array( 'per_page' => 100 ) );
		if ( ! is_wp_error( $clubs ) ) {
			$s['clubs'] = array_map( function ( $c ) { return array( 'id' => (string) $c['id'], 'name' => (string) $c['name'], 'url' => (string) ( $c['url'] ?? '' ) ); }, (array) $clubs );
			foreach ( $s['clubs'] as $c ) {
				if ( 'cc_x_standert' === strtolower( $c['url'] ) && ! $s['club_id'] ) {
					$s['club_id']   = $c['id'];
					$s['club_name'] = $c['name'];
				}
			}
			self::strava_save( $s );
		}
		$msg = 'Strava connected.';
		if ( $s['club_id'] ) {
			$r    = self::strava_sync();
			$msg .= is_wp_error( $r ) ? ' ' . $r->get_error_message() : sprintf( ' Found the club “%s”: %d events added.', $s['club_name'], $r['created'] );
		} else {
			$msg .= ' Now choose the club below and save.';
		}
		self::back( $msg );
	}

	public static function handle_strava_disconnect() {
		self::guard( 'cc_fleet_feeds_strava_disconnect' );
		$s = self::strava();
		$s = array_merge( $s, array( 'access' => '', 'refresh' => '', 'expires' => 0, 'athlete' => '', 'clubs' => array() ) );
		self::strava_save( $s );
		self::back( 'Strava disconnected. Events already copied stay in the list.' );
	}

	public static function handle_ig_disconnect() {
		self::guard( 'cc_fleet_feeds_ig_disconnect' );
		delete_option( self::OPT_IG );
		update_option( self::OPT_POSTS, array(), true );
		self::back( 'Instagram removed. The section is hidden until a new token is saved.' );
	}

	public static function handle_sync() {
		self::guard( 'cc_fleet_feeds_sync' );
		$ig = self::ig();
		if ( $ig['token'] ) {
			$ig['fetched'] = 0;
			update_option( self::OPT_IG, $ig, false );
		}
		$r   = self::run();
		$msg = array();
		if ( isset( $r['strava'] ) ) {
			$msg[] = is_wp_error( $r['strava'] ) ? 'Strava: ' . $r['strava']->get_error_message() : sprintf( 'Strava: %d new, %d updated.', $r['strava']['created'], $r['strava']['updated'] );
		}
		if ( isset( $r['instagram'] ) ) {
			$msg[] = is_wp_error( $r['instagram'] ) ? 'Instagram: ' . $r['instagram']->get_error_message() : 'Instagram: ' . $r['instagram'] . ' posts.';
		}
		self::back( $msg ? implode( ' ', $msg ) : 'Nothing is connected yet.' );
	}
}
