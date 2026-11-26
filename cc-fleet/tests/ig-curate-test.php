<?php
// wp eval-file: ambassador inbox with mocked Meta responses.
global $pass, $fail; $pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } }
if ( ! file_exists( '/tmp/ig1.jpg' ) ) { $im = imagecreatetruecolor( 1080, 1350 ); imagejpeg( $im, '/tmp/ig1.jpg' ); }
delete_option( CC_Fleet_IG_Curate::OPT );
update_option( CC_Fleet_Feeds::OPT_IG, array( 'token' => 'EAAtest', 'ig_id' => '1784100', 'api' => 'fb', 'username' => 'cc_x_standert' ), false );
update_option( CC_Fleet_Feeds::OPT_POSTS, array( array( 'id' => '9001', 'permalink' => 'https://www.instagram.com/p/OWN/', 'caption' => 'Our own post', 'time' => '2026-11-20T08:00:00+0000', 'type' => 'IMAGE', 'img' => 'https://example.com/own.webp' ) ), true );
$GLOBALS['calls'] = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$GLOBALS['calls'][] = $url;
	$r = function ( $b, $c = 200 ) { return array( 'headers' => array(), 'body' => is_string( $b ) ? $b : wp_json_encode( $b ), 'response' => array( 'code' => $c, 'message' => 'x' ), 'cookies' => array(), 'filename' => null ); };
	$q = urldecode( $url );
	if ( false !== strpos( $q, 'business_discovery.username(amandla.rides)' ) ) {
		return $r( array( 'business_discovery' => array( 'username' => 'amandla.rides', 'name' => 'Amandla', 'profile_picture_url' => 'https://cdn.example/pic.jpg', 'media' => array( 'data' => array(
			array( 'id' => '501', 'caption' => 'Kettensäge on the Swartberg', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/ig1.jpg', 'permalink' => 'https://www.instagram.com/p/A1/', 'timestamp' => '2026-11-24T08:00:00+0000' ),
			array( 'id' => '502', 'caption' => 'Reel', 'media_type' => 'VIDEO', 'permalink' => 'https://www.instagram.com/reel/A2/', 'timestamp' => '2026-11-22T08:00:00+0000' ),
		) ) ) ) );
	}
	if ( false !== strpos( $q, 'business_discovery.username(personal.pat)' ) ) {
		return $r( array( 'error' => array( 'message' => 'Invalid user id', 'code' => 110 ) ), 400 );
	}
	if ( false !== strpos( $q, '1784100/tags' ) ) {
		return $r( array( 'data' => array( array( 'id' => '601', 'caption' => 'Thanks @cc_x_standert', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/ig1.jpg', 'permalink' => 'https://www.instagram.com/p/T1/', 'timestamp' => '2026-11-25T08:00:00+0000', 'username' => 'personal.pat' ) ) ) );
	}
	if ( 0 === strpos( $url, 'https://cdn.example/' ) ) { copy( '/tmp/ig1.jpg', $args['filename'] ); return $r( '' ); }
	return $r( array( 'error' => array( 'message' => 'unexpected ' . $url ) ), 400 );
}, 10, 3 );

$d = CC_Fleet_IG_Curate::data();
ok( 'amandla.rides' === CC_Fleet_IG_Curate::clean_handle( 'https://www.instagram.com/Amandla.Rides/?hl=en' ), 'handle from link' );
$d['ambassadors'] = array( 'amandla.rides' => array( 'auto' => 0 ), 'personal.pat' => array( 'auto' => 1 ) );
update_option( CC_Fleet_IG_Curate::OPT, $d, false );
$n = CC_Fleet_IG_Curate::fetch();
$d = CC_Fleet_IG_Curate::data();
ok( 3 === $n, 'three new posts: ' . wp_json_encode( $n ) );
ok( 'new' === $d['posts']['501']['status'] && 'new' === $d['posts']['502']['status'], 'ambassador posts wait for review' );
ok( false !== strpos( $d['ambassadors']['personal.pat']['result'], 'business or creator' ), 'personal account explained' );
ok( 'featured' === $d['posts']['601']['status'] && $d['posts']['601']['img'], 'tagged post from auto ambassador featured' );
ok( 'Amandla' === $d['ambassadors']['amandla.rides']['name'], 'name stored' );
ok( 2 === CC_Fleet_IG_Curate::new_count(), 'new count 2' );
// Feature by hand.
$up = wp_upload_dir();
$d['posts']['501']['status'] = 'featured';
$ref = new ReflectionMethod( 'CC_Fleet_IG_Curate', 'copy_image' ); $ref->setAccessible( true );
$d['posts']['501']['img'] = $ref->invoke( null, $d['posts']['501'] );
update_option( CC_Fleet_IG_Curate::OPT, $d, false );
ok( file_exists( $up['basedir'] . '/cc-instagram/amb-501.webp' ), 'image copied' );
ok( '' === $ref->invoke( null, $d['posts']['502'] ), 'reel without picture cannot be featured' );
// Second fetch: nothing new, statuses kept.
$n = CC_Fleet_IG_Curate::fetch(); $d = CC_Fleet_IG_Curate::data();
ok( 0 === $n && 'featured' === $d['posts']['501']['status'], 'refetch keeps status' );
// Our own feed cleanup must not delete ambassador images.
ok( file_exists( $up['basedir'] . '/cc-instagram/amb-501.webp' ), 'still there' );
$h = do_shortcode( '[cc_instagram count="4"]' );
ok( 3 === substr_count( $h, '<li>' ), 'site shows own + 2 featured' );
ok( strpos( $h, 'T1/' ) < strpos( $h, 'A1/' ) && strpos( $h, 'A1/' ) < strpos( $h, 'OWN/' ), 'newest first' );
ok( false !== strpos( $h, 'class="igby">@personal.pat' ), 'handle shown' );
echo "ig-curate-test: $pass passed, $fail failed\n";
