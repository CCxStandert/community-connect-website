<?php
// Run: wp eval-file. Mocks Strava + Instagram HTTP.
global $pass, $fail; $pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } }
CC_Fleet_Install::maybe_upgrade();
global $wpdb; $t = CC_Fleet_Records::table( 'events' );
$wpdb->query( "DELETE FROM $t WHERE source LIKE 'strava:%'" );
$today = CC_Fleet_Settings::today();
$d1 = gmdate( 'Y-m-d\T05:30:00\Z', strtotime( $today . ' +5 days' ) );
$d0 = gmdate( 'Y-m-d\T05:30:00\Z', strtotime( $today . ' -7 days' ) );
$d2 = gmdate( 'Y-m-d\T16:00:00\Z', strtotime( $today . ' +12 days' ) );
$GLOBALS['strava_list'] = array(
	array( 'id' => 111, 'club_id' => 999, 'title' => 'Sunday Social Gravel Spin', 'description' => 'No drop, coffee after', 'address' => 'Woodstock, Cape Town', 'skill_levels' => 1, 'upcoming_occurrences' => array( $d0, $d1 ), 'private' => false ),
	array( 'id' => 222, 'club_id' => 999, 'title' => 'Cederberg Overnighter', 'description' => 'Bring a bivvy', 'address' => 'Algeria campsite', 'upcoming_occurrences' => array( $d2 ) ),
	array( 'id' => 333, 'club_id' => 999, 'title' => 'Secret', 'private' => true, 'upcoming_occurrences' => array( $d2 ) ),
);
$GLOBALS['calls'] = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$GLOBALS['calls'][] = $url;
	$r = function ( $body, $code = 200 ) { return array( 'headers' => array(), 'body' => is_string( $body ) ? $body : wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => 'x' ), 'cookies' => array(), 'filename' => null ); };
	if ( false !== strpos( $url, 'oauth/token' ) ) { return $r( array( 'access_token' => 'AT2', 'refresh_token' => 'RT2', 'expires_at' => time() + 21600 ) ); }
	if ( false !== strpos( $url, '/group_events' ) ) { return $r( $GLOBALS['strava_list'] ); }
	if ( false !== strpos( $url, 'refresh_access_token' ) ) { return $r( array( 'access_token' => 'IGT2', 'expires_in' => 5184000 ) ); }
	if ( false !== strpos( $url, 'graph.instagram.com' ) && false !== strpos( $url, 'access_token=EAA' ) ) { return $r( array( 'error' => array( 'message' => 'Invalid OAuth access token' ) ), 400 ); }
	if ( false !== strpos( $url, 'graph.instagram.com/me/media' ) ) {
		return $r( array( 'data' => array(
			array( 'id' => '1801', 'caption' => "Kettensäge 54 out on the Cederberg loop with Sikhulile. Big smiles, bigger climbs. #gravel", 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/ig1.jpg', 'permalink' => 'https://www.instagram.com/p/AAA/', 'timestamp' => '2026-11-20T08:00:00+0000', 'username' => 'cc_x_standert' ),
			array( 'id' => '1802', 'caption' => 'Workshop day', 'media_type' => 'VIDEO', 'thumbnail_url' => 'https://cdn.example/ig2.jpg', 'media_url' => 'https://cdn.example/v.mp4', 'permalink' => 'https://www.instagram.com/reel/BBB/', 'timestamp' => '2026-11-18T08:00:00+0000' ),
			array( 'id' => '1803', 'caption' => 'Three bikes ready', 'media_type' => 'CAROUSEL_ALBUM', 'media_url' => 'https://cdn.example/ig1.jpg', 'permalink' => 'https://www.instagram.com/p/CCC/', 'timestamp' => '2026-11-15T08:00:00+0000' ),
			array( 'id' => '1804', 'caption' => '', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/ig2.jpg', 'permalink' => 'https://www.instagram.com/p/DDD/', 'timestamp' => '2026-11-10T08:00:00+0000' ),
		) ) );
	}
	if ( false !== strpos( $url, 'graph.facebook.com/me/accounts' ) ) { return $r( array( 'data' => array( array( 'id' => '55' ), array( 'id' => '56', 'instagram_business_account' => array( 'id' => '17841400000000001' ) ) ) ) ); }
	if ( false !== strpos( $url, 'graph.facebook.com/17841400000000001/media' ) ) { return $r( array( 'data' => array( array( 'id' => '1901', 'caption' => 'From the FB graph', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/ig1.jpg', 'permalink' => 'https://www.instagram.com/p/EEE/', 'timestamp' => '2026-11-21T08:00:00+0000', 'username' => 'cc_x_standert' ) ) ) ); }
	if ( 0 === strpos( $url, 'https://cdn.example/' ) ) {
		copy( '/tmp/' . basename( $url ), $args['filename'] );
		return $r( '' );
	}
	return $r( array( 'message' => 'nope' ), 404 );
}, 10, 3 );

update_option( CC_Fleet_Feeds::OPT_STRAVA, array( 'client_id' => '123', 'client_secret' => 'abc', 'access' => 'AT1', 'refresh' => 'RT1', 'expires' => time() - 10, 'club_id' => '999' ), false );
$r = CC_Fleet_Feeds::strava_sync();
ok( ! is_wp_error( $r ), 'sync ok ' . ( is_wp_error( $r ) ? $r->get_error_message() : '' ) );
ok( 2 === $r['created'] && 1 === $r['skipped'], 'created 2 skipped 1: ' . wp_json_encode( $r ) );
$s = CC_Fleet_Feeds::strava();
ok( 'AT2' === $s['access'] && 'RT2' === $s['refresh'], 'token refreshed' );
$e1 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE source=%s", 'strava:111' ) );
ok( $e1 && $e1->start_date === gmdate( 'Y-m-d', strtotime( $today . ' +5 days' ) ), 'next occurrence date ' . ( $e1->start_date ?? '' ) );
ok( $e1 && 'group,social,gravel' === $e1->type, 'cats social,gravel: ' . ( $e1->type ?? '' ) );
ok( $e1 && 1 == $e1->show_on_site && false !== strpos( $e1->link, 'group_events/111' ), 'shown + link' );
ok( $e1 && false !== strpos( $e1->description, 'Casual' ), 'level in desc' );
$e2 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE source=%s", 'strava:222' ) );
ok( $e2 && 'group,social,overnight' === $e2->type, 'overnight cats: ' . ( $e2->type ?? '' ) );
ok( ! CC_Fleet_Records::is_race( $e2 ), 'not race' );
$wpdb->update( $t, array( 'type' => 'overnight,bikepacking' ), array( 'id' => $e2->id ) );
$GLOBALS['strava_list'][1]['title'] = 'Cederberg Overnighter (new date)';
$GLOBALS['calls'] = array();
$r = CC_Fleet_Feeds::strava_sync();
ok( 2 === $r['updated'] && 0 === $r['created'], 'updated 2' );
$e2 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id=%d", $e2->id ) );
ok( 'overnight,bikepacking' === $e2->type && false !== strpos( $e2->name, 'new date' ), 'cats kept, title updated' );
ok( ! preg_grep( '/oauth\/token/', $GLOBALS['calls'] ), 'no refresh while valid' );
array_splice( $GLOBALS['strava_list'], 1, 1 );
$r = CC_Fleet_Feeds::strava_sync();
ok( 1 === ( $r['hidden'] ?? 0 ), 'hidden 1' );
ok( 0 == $wpdb->get_var( $wpdb->prepare( "SELECT show_on_site FROM $t WHERE id=%d", $e2->id ) ), 'hidden in db' );
ok( array( 'group', 'race', 'gravel' ) === CC_Fleet_Feeds::strava_cats( 'Gravel Race' ), 'race cats ' . implode( ',', CC_Fleet_Feeds::strava_cats( 'Gravel Race' ) ) );
ok( array( 'group', 'social', 'bikepacking' ) === CC_Fleet_Feeds::strava_cats( 'Two-day bikepacking trip' ), 'bikepacking ' . implode( ',', CC_Fleet_Feeds::strava_cats( 'Two-day bikepacking trip' ) ) );
ok( array( 'group', 'social', 'road' ) === CC_Fleet_Feeds::strava_cats( 'Road coffee ride' ), 'road' );
$names = wp_list_pluck( CC_Fleet_Landing::data()['events'] ?? array(), 'name' );
ok( in_array( 'Sunday Social Gravel Spin', $names, true ), 'landing has strava event: ' . implode( '|', $names ) );

delete_option( CC_Fleet_Feeds::OPT_POSTS );
ok( '' === do_shortcode( '[cc_instagram]' ), 'empty without posts' );
update_option( CC_Fleet_Feeds::OPT_IG, array( 'token' => 'IGT1', 'token_set' => time() - 40 * DAY_IN_SECONDS, 'expires' => time() + 20 * DAY_IN_SECONDS ), false );
$n = CC_Fleet_Feeds::ig_fetch();
ok( 4 === $n, 'ig 4 posts: ' . ( is_wp_error( $n ) ? $n->get_error_message() : $n ) );
$ig = CC_Fleet_Feeds::ig();
ok( 'IGT2' === $ig['token'] && $ig['expires'] > time() + 50 * DAY_IN_SECONDS, 'ig token refreshed' );
ok( 'cc_x_standert' === $ig['username'], 'username' );
$up = wp_upload_dir();
$f = $up['basedir'] . '/cc-instagram/ig-1801.webp';
ok( file_exists( $f ), 'webp saved' );
$sz = @getimagesize( $f ); ok( $sz && 640 === $sz[0] && 640 === $sz[1], 'cropped 640' );
$h = do_shortcode( '[cc_instagram]' );
ok( false !== strpos( $h, 'class="iggrid' ) && 4 === substr_count( $h, '<li>' ), 'grid 4' );
ok( false !== strpos( $h, 'aria-label="Video"' ) && false !== strpos( $h, 'aria-label="Several photos"' ), 'badges' );
ok( false !== strpos( $h, 'instagram.com/cc_x_standert/' ), 'profile link' );
ok( false !== strpos( $h, '[20 Nov 2026]' ), 'date' );
$GLOBALS['calls'] = array();
update_option( CC_Fleet_Feeds::OPT_IG, array_merge( CC_Fleet_Feeds::ig(), array( 'refreshed' => time() - 3600 ) ), false );
CC_Fleet_Feeds::ig_fetch();
ok( ! preg_grep( '/refresh_access_token/', $GLOBALS['calls'] ), 'no ig refresh when fresh' );
ok( ! preg_grep( '/cdn\.example/', $GLOBALS['calls'] ), 'images cached' );
touch( $up['basedir'] . '/cc-instagram/ig-999.webp' );
CC_Fleet_Feeds::ig_fetch();
ok( ! file_exists( $up['basedir'] . '/cc-instagram/ig-999.webp' ), 'stale removed' );
// System-user (Facebook) token.
$GLOBALS['calls'] = array();
update_option( CC_Fleet_Feeds::OPT_IG, array( 'token' => 'EAAsystemtoken', 'token_set' => time() - 40 * DAY_IN_SECONDS, 'expires' => 0 ), false );
$n = CC_Fleet_Feeds::ig_fetch();
ok( 1 === $n, 'fb token fetch: ' . ( is_wp_error( $n ) ? $n->get_error_message() : $n ) );
ok( '17841400000000001' === CC_Fleet_Feeds::ig()['ig_id'], 'ig id found' );
ok( ! preg_grep( '/refresh_access_token/', $GLOBALS['calls'] ), 'no refresh for fb token' );
ok( false !== strpos( do_shortcode( '[cc_instagram]' ), 'From the FB graph' ), 'fb post shown' );
// Nothing works → Meta's message is shown.
update_option( CC_Fleet_Feeds::OPT_IG, array( 'token' => 'EAAbad', 'token_set' => time() ), false );
remove_all_filters( 'pre_http_request' );
add_filter( 'pre_http_request', function () { return array( 'headers' => array(), 'body' => '{"error":{"message":"Bad token xyz"}}', 'response' => array( 'code' => 400, 'message' => 'x' ), 'cookies' => array(), 'filename' => null ); } );
$n = CC_Fleet_Feeds::ig_fetch();
ok( is_wp_error( $n ) && false !== strpos( $n->get_error_message(), 'Bad token xyz' ), 'error shown: ' . ( is_wp_error( $n ) ? $n->get_error_message() : '' ) );
echo "feeds-test: $pass passed, $fail failed\n";
