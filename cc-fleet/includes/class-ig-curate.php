<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ambassadors on Instagram: their latest posts land in an inbox (Fleet Manager → Instagram), and the crew
 * picks which ones show in the "On Instagram" section, mixed with our own posts by date.
 *
 * - Ambassadors with a business or creator account: read by username through Meta's Business Discovery
 *   (same token as our own feed, no App Review needed).
 * - Anyone (also personal accounts) who tags @cc_x_standert in a photo: read through our account's "tags".
 * Featured images are copied to this site (640 px WebP) so they load fast and stay after Instagram's links expire.
 */
class CC_Fleet_IG_Curate {

	const OPT      = 'cc_fleet_ig_curate';
	const PAGE     = 'cc-fleet-instagram';
	const PER_USER = 12;

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 41 );
			foreach ( array( 'add', 'remove', 'auto', 'feature', 'unfeature', 'hide', 'check' ) as $a ) {
				add_action( 'admin_post_cc_fleet_igc_' . $a, array( __CLASS__, 'handle' ) );
			}
		}
	}

	/* ---------- storage ---------- */

	public static function data() {
		$d = get_option( self::OPT, array() );
		return array(
			'ambassadors' => (array) ( $d['ambassadors'] ?? array() ),
			'posts'       => (array) ( $d['posts'] ?? array() ),
			'tags'        => (array) ( $d['tags'] ?? array() ),
			'last'        => (int) ( $d['last'] ?? 0 ),
		);
	}

	private static function save( array $d ) {
		update_option( self::OPT, $d, false );
	}

	public static function clean_handle( $h ) {
		$h = trim( (string) $h );
		if ( preg_match( '#instagram\.com/([A-Za-z0-9._]+)#', $h, $m ) ) {
			$h = $m[1];
		}
		return strtolower( preg_replace( '/[^A-Za-z0-9._]/', '', ltrim( $h, '@' ) ) );
	}

	public static function new_count() {
		$n = 0;
		foreach ( self::data()['posts'] as $p ) {
			$n += 'new' === ( $p['status'] ?? '' ) ? 1 : 0;
		}
		return $n;
	}

	/* ---------- fetching ---------- */

	/** The token and our account ID, when the connection can read other accounts. */
	private static function auth() {
		$ig = CC_Fleet_Feeds::ig();
		if ( ! $ig['token'] || ! $ig['ig_id'] ) {
			return new WP_Error( 'cc_igc', 'Connect Instagram first (Fleet Manager → Connections), with the Instagram account ID filled in.' );
		}
		return $ig;
	}

	private static function graph( $path, array $q, $token ) {
		$q['access_token'] = $token;
		$res = wp_remote_get( 'https://graph.facebook.com/' . $path . '?' . http_build_query( $q, '', '&', PHP_QUERY_RFC3986 ), array( 'timeout' => 20 ) );
		$j   = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $j ) || isset( $j['error'] ) ) {
			return new WP_Error( 'cc_igc', is_wp_error( $res ) ? $res->get_error_message() : ( $j['error']['message'] ?? 'Instagram did not answer.' ) );
		}
		return $j;
	}

	/** Pull every ambassador's latest posts and the posts we're tagged in. New ones wait in the inbox. */
	public static function fetch() {
		$auth = self::auth();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$d      = self::data();
		$fields = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp';
		$added  = 0;
		foreach ( $d['ambassadors'] as $handle => $a ) {
			$j = self::graph( $auth['ig_id'], array( 'fields' => 'business_discovery.username(' . $handle . '){username,name,profile_picture_url,media.limit(' . self::PER_USER . '){' . $fields . '}}' ), $auth['token'] );
			if ( is_wp_error( $j ) ) {
				$msg = $j->get_error_message();
				if ( false !== stripos( $msg, 'cannot be found' ) || false !== stripos( $msg, 'business' ) || false !== stripos( $msg, 'Invalid user id' ) ) {
					$msg = 'Not found as a business or creator account. Ask them to switch (free, in Instagram settings), or to tag @' . ( CC_Fleet_Feeds::ig()['username'] ?: 'cc_x_standert' ) . ' in their photos.';
				}
				$d['ambassadors'][ $handle ]['result'] = $msg;
				continue;
			}
			$bd = (array) ( $j['business_discovery'] ?? array() );
			$d['ambassadors'][ $handle ]['name']   = sanitize_text_field( (string) ( $bd['name'] ?? '' ) );
			$d['ambassadors'][ $handle ]['pic']    = esc_url_raw( (string) ( $bd['profile_picture_url'] ?? '' ) );
			$n = 0;
			foreach ( (array) ( $bd['media']['data'] ?? array() ) as $m ) {
				$added += self::take( $d, $m, $handle, 'amb', ! empty( $a['auto'] ) ) ? 1 : 0;
				$n++;
			}
			$d['ambassadors'][ $handle ]['result'] = $n . ' recent posts';
			$d['ambassadors'][ $handle ]['last']   = time();
		}
		// Posts where someone tagged our account in the photo (works for personal accounts too).
		$t = self::graph( $auth['ig_id'] . '/tags', array( 'fields' => $fields . ',username', 'limit' => 25 ), $auth['token'] );
		if ( is_wp_error( $t ) ) {
			$d['tags'] = array( 'result' => 'Tagged posts: ' . $t->get_error_message(), 'last' => time() );
		} else {
			$tn = 0;
			foreach ( (array) ( $t['data'] ?? array() ) as $m ) {
				$who    = self::clean_handle( $m['username'] ?? '' );
				$auto   = $who && ! empty( $d['ambassadors'][ $who ]['auto'] );
				$added += self::take( $d, $m, $who, 'tag', $auto ) ? 1 : 0;
				$tn++;
			}
			$d['tags'] = array( 'result' => $tn . ' posts tag us', 'last' => time() );
		}
		$d['last'] = time();
		self::prune( $d );
		self::save( $d );
		return $added;
	}

	/** Add or refresh one post. Returns true when it is new. */
	private static function take( array &$d, array $m, $handle, $source, $auto ) {
		$id = preg_replace( '/[^0-9_]/', '', (string) ( $m['id'] ?? '' ) );
		if ( ! $id ) {
			return false;
		}
		$remote = 'VIDEO' === ( $m['media_type'] ?? '' ) ? ( $m['thumbnail_url'] ?? '' ) : ( $m['media_url'] ?? ( $m['thumbnail_url'] ?? '' ) );
		$row    = array(
			'id'        => $id,
			'user'      => $handle,
			'source'    => $source,
			'caption'   => wp_strip_all_tags( (string) ( $m['caption'] ?? '' ) ),
			'permalink' => esc_url_raw( (string) ( $m['permalink'] ?? '' ) ),
			'time'      => (string) ( $m['timestamp'] ?? '' ),
			'type'      => (string) ( $m['media_type'] ?? 'IMAGE' ),
			'remote'    => esc_url_raw( (string) $remote ),
		);
		if ( isset( $d['posts'][ $id ] ) ) {
			$d['posts'][ $id ] = array_merge( $d['posts'][ $id ], array_filter( $row ), array( 'status' => $d['posts'][ $id ]['status'], 'img' => $d['posts'][ $id ]['img'] ?? '' ) );
			return false;
		}
		$row['status'] = 'new';
		$row['img']    = '';
		$row['found']  = time();
		$d['posts'][ $id ] = $row;
		if ( $auto ) {
			$img = self::copy_image( $row );
			if ( $img ) {
				$d['posts'][ $id ]['status'] = 'featured';
				$d['posts'][ $id ]['img']    = $img;
			}
		}
		return true;
	}

	/** Keep featured posts; of the rest, each account's newest 24 and nothing older than 6 months. */
	private static function prune( array &$d ) {
		$by = array();
		uasort( $d['posts'], function ( $a, $b ) { return strcmp( (string) $b['time'], (string) $a['time'] ); } );
		foreach ( $d['posts'] as $id => $p ) {
			if ( 'featured' === $p['status'] ) {
				continue;
			}
			$k        = $p['user'] ?: '_';
			$by[ $k ] = ( $by[ $k ] ?? 0 ) + 1;
			if ( $by[ $k ] > 24 || ( $p['time'] && strtotime( $p['time'] ) < time() - 183 * DAY_IN_SECONDS ) ) {
				unset( $d['posts'][ $id ] );
			}
		}
	}

	/** Save a featured post's picture here. Returns its URL, or '' when Instagram doesn't hand it out. */
	private static function copy_image( array $p ) {
		if ( empty( $p['remote'] ) ) {
			return '';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'cc-instagram';
		wp_mkdir_p( $dir );
		$tmp = download_url( $p['remote'], 20 );
		if ( is_wp_error( $tmp ) ) {
			return '';
		}
		$ed = wp_get_image_editor( $tmp );
		if ( is_wp_error( $ed ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return '';
		}
		$ed->resize( 640, 640, true );
		$ed->set_quality( 78 );
		$name  = 'amb-' . $p['id'] . '.webp';
		$saved = $ed->save( $dir . '/' . $name, 'image/webp' );
		if ( is_wp_error( $saved ) ) {
			$name  = 'amb-' . $p['id'] . '.jpg';
			$saved = $ed->save( $dir . '/' . $name, 'image/jpeg' );
		}
		@unlink( $tmp ); // phpcs:ignore
		return is_wp_error( $saved ) ? '' : trailingslashit( $up['baseurl'] ) . 'cc-instagram/' . $name;
	}

	private static function drop_image( array $p ) {
		if ( ! empty( $p['img'] ) ) {
			$up = wp_upload_dir();
			wp_delete_file( trailingslashit( $up['basedir'] ) . 'cc-instagram/' . basename( (string) wp_parse_url( $p['img'], PHP_URL_PATH ) ) );
		}
	}

	/** Featured posts, shaped like our own feed's posts, for the website section. */
	public static function featured() {
		$out = array();
		foreach ( self::data()['posts'] as $p ) {
			if ( 'featured' === $p['status'] && ! empty( $p['img'] ) ) {
				$out[] = array( 'id' => $p['id'], 'permalink' => $p['permalink'], 'caption' => $p['caption'], 'time' => $p['time'], 'type' => $p['type'], 'img' => $p['img'], 'by' => $p['user'] );
			}
		}
		return $out;
	}

	/* ---------- admin ---------- */

	public static function menu() {
		$n = self::new_count();
		add_submenu_page( 'cc-fleet', 'Instagram', 'Instagram' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' ), CC_Fleet_Admin::CAP, self::PAGE, array( __CLASS__, 'page' ) );
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	private static function act( $a, $args = array(), $label = '', $class = 'button' ) {
		$u = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'cc_fleet_igc_' . $a ), $args ), admin_url( 'admin-post.php' ) ), 'cc_fleet_igc' );
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $u ) . '">' . esc_html( $label ) . '</a>';
	}

	public static function page() {
		if ( ! current_user_can( CC_Fleet_Admin::CAP ) ) {
			return;
		}
		$d    = self::data();
		$tab  = sanitize_key( $_GET['tab'] ?? 'new' );
		$auth = self::auth();
		echo '<div class="wrap cc-wrap"><h1>Instagram</h1>';
		$n = get_transient( 'cc_fleet_igc_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'cc_fleet_igc_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[1] ), esc_html( $n[0] ) );
		}
		echo '<p>Posts from our ambassadors, and from anyone who tags @' . esc_html( CC_Fleet_Feeds::ig()['username'] ?: 'cc_x_standert' ) . ' in a photo, arrive here every few hours. <strong>Feature</strong> the ones that should show in “On Instagram” on the home page, mixed with our own posts by date.</p>';
		if ( is_wp_error( $auth ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $auth->get_error_message() ) . '</p></div>';
		}

		// Ambassadors.
		echo '<h2>Ambassadors</h2><table class="widefat striped" style="max-width:980px"><thead><tr><th>Account</th><th>Last check</th><th>Feature new posts automatically</th><th></th></tr></thead><tbody>';
		if ( ! $d['ambassadors'] ) {
			echo '<tr><td colspan="4">No ambassadors yet. Add their Instagram handle below.</td></tr>';
		}
		foreach ( $d['ambassadors'] as $h => $a ) {
			printf(
				'<tr><td>%s<a href="%s" target="_blank" rel="noopener"><strong>@%s</strong></a>%s</td><td>%s<br><small>%s</small></td><td>%s</td><td>%s</td></tr>',
				! empty( $a['pic'] ) ? '<img src="' . esc_url( $a['pic'] ) . '" alt="" width="28" height="28" style="border-radius:50%;vertical-align:middle;margin-right:8px">' : '',
				esc_url( 'https://www.instagram.com/' . $h . '/' ), esc_html( $h ), ! empty( $a['name'] ) ? ' <span class="description">' . esc_html( $a['name'] ) . '</span>' : '',
				esc_html( ! empty( $a['last'] ) ? wp_date( 'j M, H:i', $a['last'] ) : 'not yet' ), esc_html( $a['result'] ?? '' ),
				self::act( 'auto', array( 'h' => $h ), ! empty( $a['auto'] ) ? 'On: turn off' : 'Off: turn on', 'button-link' ),
				self::act( 'remove', array( 'h' => $h ), 'Remove', 'button-link-delete' )
			);
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0 6px;display:flex;gap:8px;align-items:center">';
		wp_nonce_field( 'cc_fleet_igc' );
		echo '<input type="hidden" name="action" value="cc_fleet_igc_add"><label for="cc-igc-h" class="screen-reader-text">Instagram handle</label><input id="cc-igc-h" name="h" placeholder="@handle or profile link" class="regular-text"> <button class="button button-primary">Add ambassador</button></form>';
		echo '<p class="description">Their account must be a business or creator account (free to switch in Instagram settings) for us to read it. Personal accounts can still appear: ask them to tag @' . esc_html( CC_Fleet_Feeds::ig()['username'] ?: 'cc_x_standert' ) . ' in the photo.' . ( ! empty( $d['tags']['result'] ) ? ' <em>' . esc_html( $d['tags']['result'] ) . '.</em>' : '' ) . '</p>';
		echo '<p>' . self::act( 'check', array(), 'Check for new posts now' ) . ' <span class="description">Last check: ' . esc_html( $d['last'] ? wp_date( 'j M, H:i', $d['last'] ) : 'not yet' ) . '</span></p>'; // phpcs:ignore

		// Posts.
		$counts = array( 'new' => 0, 'featured' => 0, 'hidden' => 0 );
		foreach ( $d['posts'] as $p ) {
			$counts[ $p['status'] ] = ( $counts[ $p['status'] ] ?? 0 ) + 1;
		}
		echo '<h2 class="nav-tab-wrapper" style="margin-top:24px">';
		foreach ( array( 'new' => 'To review', 'featured' => 'On the website', 'hidden' => 'Not shown' ) as $k => $l ) {
			printf( '<a class="nav-tab%s" href="%s">%s <span class="count">(%d)</span></a>', $tab === $k ? ' nav-tab-active' : '', esc_url( self::url( array( 'tab' => $k ) ) ), esc_html( $l ), (int) $counts[ $k ] );
		}
		echo '</h2>';
		$list = array_filter( $d['posts'], function ( $p ) use ( $tab ) { return $p['status'] === $tab; } );
		uasort( $list, function ( $a, $b ) { return strcmp( (string) $b['time'], (string) $a['time'] ); } );
		if ( ! $list ) {
			echo '<p>' . esc_html( 'new' === $tab ? 'Nothing new to review.' : 'Nothing here yet.' ) . '</p>';
		}
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px;max-width:1200px">';
		foreach ( $list as $p ) {
			$img = $p['img'] ?: $p['remote'];
			$btn = 'featured' === $p['status'] ? self::act( 'unfeature', array( 'id' => $p['id'] ), 'Take off the website' )
				: self::act( 'feature', array( 'id' => $p['id'] ), 'Feature', 'button button-primary' ) . ' ' . ( 'hidden' === $p['status'] ? '' : self::act( 'hide', array( 'id' => $p['id'] ), 'Skip' ) );
			printf(
				'<div style="background:#fff;border:1px solid #c3c4c7"><a href="%s" target="_blank" rel="noopener" style="display:block;aspect-ratio:1;background:#f0f0f1">%s</a><div style="padding:10px 12px"><strong>@%s</strong>%s<br><small>%s</small><p style="margin:6px 0 10px;font-size:13px;line-height:1.35;max-height:5.4em;overflow:hidden">%s</p>%s</div></div>',
				esc_url( $p['permalink'] ),
				$img ? '<img src="' . esc_url( $img ) . '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block" referrerpolicy="no-referrer">' : '<span style="display:flex;height:100%;align-items:center;justify-content:center">Open on Instagram</span>',
				esc_html( $p['user'] ?: 'unknown' ), 'tag' === $p['source'] ? ' <span class="description">tagged us</span>' : '',
				esc_html( ( $p['time'] ? wp_date( 'j M Y', strtotime( $p['time'] ) ) : '' ) . ( 'VIDEO' === $p['type'] ? ' · video' : ( 'CAROUSEL_ALBUM' === $p['type'] ? ' · several photos' : '' ) ) ),
				esc_html( wp_trim_words( $p['caption'], 30, '…' ) ), $btn // phpcs:ignore
			);
		}
		echo '</div></div>';
	}

	public static function handle() {
		if ( ! current_user_can( CC_Fleet_Admin::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'cc_fleet_igc' );
		$a   = str_replace( 'cc_fleet_igc_', '', sanitize_key( $_REQUEST['action'] ?? '' ) );
		$d   = self::data();
		$h   = self::clean_handle( wp_unslash( $_REQUEST['h'] ?? '' ) );
		$id  = preg_replace( '/[^0-9_]/', '', (string) ( $_REQUEST['id'] ?? '' ) );
		$msg = array( 'Done.', 'success' );
		$tab = 'new';
		switch ( $a ) {
			case 'add':
				if ( ! $h ) {
					$msg = array( 'Type an Instagram handle.', 'error' );
					break;
				}
				$d['ambassadors'][ $h ] = $d['ambassadors'][ $h ] ?? array( 'auto' => 0, 'added' => time() );
				self::save( $d );
				$r   = self::fetch();
				$d   = self::data();
				$msg = array( '@' . $h . ' added. ' . ( $d['ambassadors'][ $h ]['result'] ?? '' ), false !== strpos( (string) ( $d['ambassadors'][ $h ]['result'] ?? '' ), 'recent posts' ) ? 'success' : 'warning' );
				break;
			case 'remove':
				unset( $d['ambassadors'][ $h ] );
				foreach ( $d['posts'] as $pid => $p ) {
					if ( $p['user'] === $h && 'amb' === $p['source'] && 'featured' !== $p['status'] ) {
						unset( $d['posts'][ $pid ] );
					}
				}
				self::save( $d );
				$msg = array( '@' . $h . ' removed. Posts already on the website stay until you take them off.', 'success' );
				break;
			case 'auto':
				if ( isset( $d['ambassadors'][ $h ] ) ) {
					$d['ambassadors'][ $h ]['auto'] = empty( $d['ambassadors'][ $h ]['auto'] ) ? 1 : 0;
					self::save( $d );
					$msg = array( $d['ambassadors'][ $h ]['auto'] ? 'New posts from @' . $h . ' now go on the website straight away.' : 'New posts from @' . $h . ' wait for review again.', 'success' );
				}
				break;
			case 'check':
				$r   = self::fetch();
				$msg = is_wp_error( $r ) ? array( $r->get_error_message(), 'error' ) : array( $r ? $r . ' new posts to review.' : 'No new posts.', 'success' );
				break;
			case 'feature':
				if ( isset( $d['posts'][ $id ] ) ) {
					$img = $d['posts'][ $id ]['img'] ?: self::copy_image( $d['posts'][ $id ] );
					if ( ! $img ) {
						// Instagram's picture links expire: fetch fresh ones once and try again.
						self::fetch();
						$d   = self::data();
						$img = isset( $d['posts'][ $id ] ) ? self::copy_image( $d['posts'][ $id ] ) : '';
					}
					if ( $img ) {
						$d['posts'][ $id ]['status'] = 'featured';
						$d['posts'][ $id ]['img']    = $img;
						self::save( $d );
						$msg = array( 'On the website now.', 'success' );
					} else {
						$msg = array( 'Instagram didn’t hand out this picture (some reels can’t be downloaded). Pick another post.', 'error' );
					}
				}
				$tab = 'featured';
				break;
			case 'unfeature':
			case 'hide':
				if ( isset( $d['posts'][ $id ] ) ) {
					self::drop_image( $d['posts'][ $id ] );
					$d['posts'][ $id ]['img']    = '';
					$d['posts'][ $id ]['status'] = 'hidden';
					self::save( $d );
					$msg = array( 'unfeature' === $a ? 'Taken off the website.' : 'Skipped.', 'success' );
				}
				$tab = 'unfeature' === $a ? 'featured' : 'new';
				break;
		}
		set_transient( 'cc_fleet_igc_notice_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( self::url( array( 'tab' => $tab ) ) );
		exit;
	}
}
