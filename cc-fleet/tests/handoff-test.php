<?php
/**
 * Rider-to-rider handoffs. Run: wp eval-file tests/handoff-test.php
 */
global $wpdb;
$GLOBALS['h_pass'] = 0; $GLOBALS['h_fail'] = 0;
function h_t( $n, $c, $x = '' ) { if ( $c ) { $GLOBALS['h_pass']++; echo "  ✓ $n\n"; } else { $GLOBALS['h_fail']++; echo "  ✗ $n $x\n"; } }
add_filter( 'pre_wp_mail', function ( $r, $a ) { $GLOBALS['h_mail'][] = $a; return true; }, 10, 2 );
CC_Fleet_Install::maybe_upgrade();
$o = get_option( 'cc_fleet_settings' ); $keep_review = $o['review_rider_emails'] ?? 0; $o['review_rider_emails'] = 0; update_option( 'cc_fleet_settings', $o );
$today = CC_Fleet_Settings::today();
$d = function ( $n ) use ( $today ) { return CC_Fleet_Loans::add_days( $today, $n ); };
$free = CC_Fleet_Loans::available_bikes( $d( 200 ), $d( 215 ) );
$bike = is_object( $free[0] ) ? $free[0]->ID : (int) $free[0];
$mk = function ( $name, $share ) {
	$id = CC_Fleet_Riders::upsert( array( 'name' => $name, 'email' => sanitize_title( $name ) . '-' . wp_generate_password( 4, false ) . '@example.org', 'phone' => '+2782' . wp_rand( 1000000, 9999999 ), 'consent' => true ) );
	$id = is_object( $id ) ? $id->id : $id;
	if ( $share ) { CC_Fleet_Me::mark_share( $id ); }
	return $id;
};
$thandi = $mk( 'Thandi Giver', true );
$luke   = $mk( 'Luke Receiver', true );
$sam    = $mk( 'Sam Private', false );

echo "Columns\n";
h_t( 'handoff_from + share_ok exist', in_array( 'handoff_from', $wpdb->get_col( 'SHOW COLUMNS FROM ' . CC_Fleet_Loans::table() ), true ) && in_array( 'share_ok', $wpdb->get_col( 'SHOW COLUMNS FROM ' . CC_Fleet_Riders::table() ), true ) );

echo "Link on confirm\n";
$g = CC_Fleet_Loans::create( array( 'bike_id' => $bike, 'rider_id' => $thandi, 'start_date' => $d( 200 ), 'end_date' => $d( 204 ), 'status' => 'approved', 'source' => 'web', 'plan' => 'x' ), true );
$r = CC_Fleet_Loans::create( array( 'bike_id' => $bike, 'rider_id' => $luke, 'start_date' => $d( 205 ), 'end_date' => $d( 208 ), 'status' => 'requested', 'source' => 'web', 'plan' => 'y', 'pedals' => 'need' ), true );
CC_Fleet_Loans::update( $r, array( 'pickup_mode' => 'rider' ), true );
h_t( 'They fit together', CC_Fleet_Handoff::fits( CC_Fleet_Loans::get( $g ), CC_Fleet_Loans::get( $r ) ) );
$GLOBALS['h_mail'] = array();
CC_Fleet_Loans::set_status( $r, 'approved', true, true );
h_t( 'Confirming links them', (int) CC_Fleet_Loans::get( $r )->handoff_from === (int) $g );
$intro = array_values( array_filter( $GLOBALS['h_mail'], function ( $m ) { return false !== stripos( $m['subject'], 'Hand ' ) || false !== stripos( $m['subject'], 'comes from' ); } ) );
h_t( 'Both riders get an intro', 2 === count( $intro ), wp_json_encode( wp_list_pluck( $GLOBALS['h_mail'], 'subject' ) ) );
h_t( 'Intro has a WhatsApp button for the other rider', $intro && false !== strpos( $intro[0]['message'], 'wa.me/' ) );
h_t( 'Receiver intro mentions pedals', (bool) array_filter( $intro, function ( $m ) { return false !== stripos( $m['message'], 'needs spd pedals' ); } ) );
h_t( 'Giver loan knows its receiver', ( CC_Fleet_Handoff::receiver( CC_Fleet_Loans::get( $g ) )->id ?? 0 ) == $r );

echo "My rides\n";
$_COOKIE[ CC_Fleet_Me::COOKIE ] = CC_Fleet_Me::ensure_token( CC_Fleet_Riders::get( $luke ) );
$html = do_shortcode( '[cc_my_rides]' );
h_t( 'Receiver sees "Straight from Thandi" + WhatsApp', false !== strpos( $html, 'Straight from Thandi' ) && false !== strpos( $html, 'WhatsApp Thandi' ) );
unset( $_COOKIE[ CC_Fleet_Me::COOKIE ] );

echo "Handed over\n";
CC_Fleet_Loans::set_status( $g, 'out', false, true );
CC_Fleet_Loans::set_status( $g, 'returned', true, true );
CC_Fleet_Loans::set_status( $r, 'out', false, true );
h_t( 'Giver returned, receiver out', 'returned' === CC_Fleet_Loans::get( $g )->status && 'out' === CC_Fleet_Loans::get( $r )->status );

echo "Unlink on date change\n";
$g2 = CC_Fleet_Loans::create( array( 'bike_id' => $bike, 'rider_id' => $thandi, 'start_date' => $d( 220 ), 'end_date' => $d( 222 ), 'status' => 'approved', 'source' => 'web', 'plan' => 'x' ), true );
$r2 = CC_Fleet_Loans::create( array( 'bike_id' => $bike, 'rider_id' => $sam, 'start_date' => $d( 223 ), 'end_date' => $d( 225 ), 'status' => 'approved', 'source' => 'web', 'plan' => 'y' ), true );
$GLOBALS['h_mail'] = array();
h_t( 'Crew link works', true === CC_Fleet_Handoff::link( $r2, $g2 ) && (int) CC_Fleet_Loans::get( $r2 )->handoff_from === (int) $g2 );
h_t( 'Without consent: no number in rider mail, crew gets both', ! array_filter( $GLOBALS['h_mail'], function ( $m ) { return false !== stripos( $m['subject'], 'Hand ' ) && false !== strpos( $m['message'], 'wa.me/' ); } ) && array_filter( $GLOBALS['h_mail'], function ( $m ) { return 0 === strpos( $m['subject'], 'Connect them' ); } ) );
$GLOBALS['h_mail'] = array();
CC_Fleet_Loans::update( $r2, array( 'start_date' => $d( 240 ), 'end_date' => $d( 242 ) ), true );
h_t( 'Moving the next booking away unlinks', 0 === (int) CC_Fleet_Loans::get( $r2 )->handoff_from );
h_t( 'Crew + rider told', (bool) array_filter( $GLOBALS['h_mail'], function ( $m ) { return 0 === strpos( $m['subject'], 'Handoff off' ); } ) && (bool) array_filter( $GLOBALS['h_mail'], function ( $m ) { return 0 === strpos( $m['subject'], 'Change of plan' ); } ) );

echo "Unlink on cancel\n";
CC_Fleet_Loans::update( $r2, array( 'start_date' => $d( 223 ), 'end_date' => $d( 225 ) ), true );
CC_Fleet_Handoff::link( $r2, $g2 );
CC_Fleet_Loans::set_status( $g2, 'cancelled', false, true );
h_t( 'Giver cancelled unlinks the next rider', 0 === (int) CC_Fleet_Loans::get( $r2 )->handoff_from );

echo "Doesn’t fit\n";
$far = CC_Fleet_Loans::create( array( 'bike_id' => $bike, 'rider_id' => $luke, 'start_date' => $d( 260 ), 'end_date' => $d( 262 ), 'status' => 'approved', 'source' => 'web', 'plan' => 'z' ), true );
h_t( 'Not consecutive → refused', is_wp_error( CC_Fleet_Handoff::link( $far, $g2 ) ) );

foreach ( array( $g, $r, $g2, $r2, $far ) as $id ) { $wpdb->delete( CC_Fleet_Loans::table(), array( 'id' => $id ) ); }
foreach ( array( $thandi, $luke, $sam ) as $id ) { $wpdb->delete( CC_Fleet_Riders::table(), array( 'id' => $id ) ); }
$o = get_option( 'cc_fleet_settings' ); $o['review_rider_emails'] = $keep_review; update_option( 'cc_fleet_settings', $o );
echo "\n" . $GLOBALS['h_pass'] . ' passed, ' . $GLOBALS['h_fail'] . " failed\n";
