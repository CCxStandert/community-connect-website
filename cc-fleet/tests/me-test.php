<?php
/**
 * My rides + returning riders. Run: wp eval-file tests/me-test.php
 */
global $wpdb;
$GLOBALS['me_pass'] = 0; $GLOBALS['me_fail'] = 0;
function me_t( $n, $c, $x = '' ) { if ( $c ) { $GLOBALS['me_pass']++; echo "  ✓ $n\n"; } else { $GLOBALS['me_fail']++; echo "  ✗ $n $x\n"; } }
$call = function ( $m, $path, $body = array() ) { $r = new WP_REST_Request( $m, '/cc/v1' . $path ); foreach ( $body as $k => $v ) { $r->set_param( $k, $v ); } return rest_do_request( $r ); };
$today = CC_Fleet_Settings::today();
$d = function ( $n ) use ( $today ) { return CC_Fleet_Loans::add_days( $today, $n ); };
add_filter( 'pre_wp_mail', function ( $r, $a ) { $GLOBALS['me_mail'][] = $a; return true; }, 10, 2 );

CC_Fleet_Install::maybe_upgrade();
echo "Setup\n";
me_t( 'My rides page exists', (bool) get_page_by_path( 'my-rides' ) );
$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . CC_Fleet_Riders::table() );
me_t( 'Rider columns added', ! array_diff( array( 'access_token', 'access_until', 'agreed_version', 'agreed_at' ), $cols ) );

$o = get_option( 'cc_fleet_settings' ); $keep_review = $o['review_rider_emails'] ?? 0; $o['review_rider_emails'] = 0; $keep_rate = $o['rate_limit_per_hour'] ?? 5; $o['rate_limit_per_hour'] = 500; update_option( 'cc_fleet_settings', $o );
$free = CC_Fleet_Loans::available_bikes( $d( 160 ), $d( 179 ) );
$bike = $free ? ( is_object( $free[0] ) ? $free[0]->ID : (int) $free[0] ) : 0;
$cc = CC_Fleet_Bikes::get( $bike, 'cc_id' );
$email = 'me-test-' . wp_generate_password( 6, false ) . '@example.org';
$base = array( 'bike' => $cc, 'start' => $d( 160 ), 'end' => $d( 162 ), 'name' => 'Thandi Me', 'email' => $email, 'phone' => '82 123 4567', 'phone_cc' => '27', 'instagram' => '@thandi', 'height' => 168, 'pedals' => 'need', 'plan' => 'Cederberg loop', 'consent' => 1, 'agree' => 1, 't' => time() - 30 );

echo "First request\n";
$GLOBALS['me_mail'] = array();
$r1 = $call( 'POST', '/requests', $base );
me_t( 'Request accepted', ! empty( $r1->get_data()['ok'] ), wp_json_encode( $r1->get_data() ) );
$rider = CC_Fleet_Riders::find_by_email( $email );
me_t( 'Agreement version remembered', $rider && CC_Fleet_Me::AGREEMENT === $rider->agreed_version );
$rx = array_values( array_filter( $GLOBALS['me_mail'], function ( $m ) use ( $email ) { return in_array( $email, (array) $m['to'], true ); } ) );
me_t( 'Rider email has the See your booking link', $rx && false !== strpos( $rx[0]['message'], '/my-rides/?k=' ) );
$rider = CC_Fleet_Riders::find_by_email( $email );
me_t( 'Token created and valid', strlen( $rider->access_token ) >= 24 && CC_Fleet_Me::rider_for( $rider->access_token ) );
me_t( 'Wrong token rejected', null === CC_Fleet_Me::rider_for( 'x' . substr( $rider->access_token, 1 ) ) );

echo "Signed out\n";
unset( $_COOKIE[ CC_Fleet_Me::COOKIE ] );
$me = $call( 'GET', '/me' );
me_t( '/me without cookie says signed out', false === $me->get_data()['signed_in'] );
$no = $call( 'POST', '/requests', array_merge( $base, array( 'agree' => '', 'consent' => '', 'start' => $d( 170 ), 'end' => $d( 171 ) ) ) );
me_t( 'Not signed in: agreement still required', 400 === $no->get_status() && isset( $no->get_data()['data']['fields']['agree'] ) );

echo "Signed in\n";
$_COOKIE[ CC_Fleet_Me::COOKIE ] = $rider->access_token;
$me = $call( 'GET', '/me' )->get_data();
me_t( '/me returns details for the form', ! empty( $me['signed_in'] ) && 'Thandi Me' === $me['name'] && 168 === (int) $me['height'] && 'need' === $me['pedals'] && $me['agreed'] && '27' === $me['phone_cc'], wp_json_encode( $me ) );
$r2 = $call( 'POST', '/requests', array_merge( $base, array( 'agree' => '', 'consent' => '', 'start' => $d( 175 ), 'end' => $d( 176 ) ) ) );
me_t( 'Signed in + agreed: request goes through without ticking again', ! empty( $r2->get_data()['ok'] ), wp_json_encode( $r2->get_data() ) );
$other = $call( 'POST', '/requests', array_merge( $base, array( 'agree' => '', 'consent' => '', 'email' => 'someone-else@example.org', 'start' => $d( 178 ), 'end' => $d( 179 ) ) ) );
me_t( 'Signed in but a different email: agreement required', isset( $other->get_data()['data']['fields']['agree'] ), wp_json_encode( $other->get_data() ) );

echo "Page\n";
$html = do_shortcode( '[cc_my_rides]' );
me_t( 'Page greets the rider and lists bookings', false !== strpos( $html, 'Hi Thandi' ) && substr_count( $html, 'cc-me__card' ) >= 2 );
$loans = CC_Fleet_Loans::query( array( 'rider_id' => $rider->id, 'orderby' => 'id', 'order' => 'ASC' ) );
$l1 = $loans[0]; $l2 = $loans[1];

echo "Change + cancel\n";
$GLOBALS['me_mail'] = array();
$ch = $call( 'POST', '/me/loans/' . $l1->id . '/change', array( 'start' => $d( 161 ), 'end' => $d( 163 ), 'note' => 'One day later' ) );
me_t( 'Change request goes to the crew, dates unchanged', 200 === $ch->get_status() && CC_Fleet_Loans::get( $l1->id )->start_date === $l1->start_date && count( $GLOBALS['me_mail'] ) >= 1 );
CC_Fleet_Loans::set_status( $l1->id, 'approved', false, true );
$c1 = $call( 'POST', '/me/loans/' . $l1->id . '/cancel', array( 'note' => 'Sick' ) );
me_t( 'Confirmed: cancel only asks the crew', 200 === $c1->get_status() && 'approved' === CC_Fleet_Loans::get( $l1->id )->status );
$c2 = $call( 'POST', '/me/loans/' . $l2->id . '/cancel' );
me_t( 'Requested: cancel cancels', 200 === $c2->get_status() && 'cancelled' === CC_Fleet_Loans::get( $l2->id )->status );
$foreign = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CC_Fleet_Loans::table() . ' WHERE rider_id <> %d ORDER BY id DESC LIMIT 1', $rider->id ) );
$cf = $call( 'POST', '/me/loans/' . $foreign . '/cancel' );
me_t( 'Someone else’s booking: refused', 404 === $cf->get_status() );
$html = do_shortcode( '[cc_my_rides]' );
me_t( 'Confirmed card shows pick-up + calendar', false !== strpos( $html, 'Pick-up' ) && false !== strpos( $html, 'Add to calendar' ) );

echo "Email me my link\n";
unset( $_COOKIE[ CC_Fleet_Me::COOKIE ] );
$GLOBALS['me_mail'] = array();
delete_transient( 'cc_me_em_' . md5( strtolower( $email ) ) ); delete_transient( 'cc_me_ip_' . substr( md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ), 0, 12 ) );
$lk = $call( 'POST', '/me/link', array( 'email' => $email ) );
$unk = $call( 'POST', '/me/link', array( 'email' => 'nobody-' . time() . '@example.org' ) );
me_t( 'Same answer for known and unknown address', $lk->get_data()['message'] === $unk->get_data()['message'] );
me_t( 'Link email sent only to the known rider', 1 === count( $GLOBALS['me_mail'] ) && false !== strpos( $GLOBALS['me_mail'][0]['message'], '/my-rides/?k=' ), wp_json_encode( wp_list_pluck( $GLOBALS['me_mail'], 'subject' ) ) );

echo "Expiry\n";
$wpdb->update( CC_Fleet_Riders::table(), array( 'access_until' => $d( -40 ) ), array( 'id' => $rider->id ) );
$wpdb->update( CC_Fleet_Loans::table(), array( 'start_date' => $d( -80 ), 'end_date' => $d( -70 ) ), array( 'rider_id' => $rider->id ) );
me_t( 'Link expires 30 days after the last loan', null === CC_Fleet_Me::rider_for( $rider->access_token ) );

// Clean up.
$wpdb->delete( CC_Fleet_Loans::table(), array( 'rider_id' => $rider->id ) );
$wpdb->delete( CC_Fleet_Riders::table(), array( 'id' => $rider->id ) );
$o = get_option( 'cc_fleet_settings' ); $o['review_rider_emails'] = $keep_review; $o['rate_limit_per_hour'] = $keep_rate; update_option( 'cc_fleet_settings', $o );
echo "\n" . $GLOBALS['me_pass'] . ' passed, ' . $GLOBALS['me_fail'] . " failed\n";
