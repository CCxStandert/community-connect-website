<?php
/**
 * Waiting list test. Run on a THROWAWAY site only (it deletes fleet data), after smoke-test.php has imported the bikes:
 *   wp eval-file wp-content/plugins/cc-fleet/tests/waitlist-test.php
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
global $wpdb;
$GLOBALS['cc_pass'] = 0; $GLOBALS['cc_fail'] = 0;
function cc_w( $name, $cond, $extra = '' ) {
	if ( $cond ) { $GLOBALS['cc_pass']++; echo "  ✓ $name\n"; } else { $GLOBALS['cc_fail']++; echo "  ✗ $name" . ( $extra ? " — $extra" : '' ) . "\n"; }
}
$mails = array();
$all_mails = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$mails, &$all_mails ) { $mails[] = $atts; $all_mails[] = $atts; return true; }, 10, 2 );
$subjects = function () use ( &$mails ) { return implode( ' | ', wp_list_pluck( $mails, 'subject' ) ); };
$mailed   = function ( $to, $needle ) use ( &$mails ) {
	foreach ( $mails as $m ) {
		if ( false !== strpos( (string) ( is_array( $m['to'] ) ? implode( ',', $m['to'] ) : $m['to'] ), $to ) && false !== strpos( $m['subject'], $needle ) ) {
			return $m;
		}
	}
	return null;
};
wp_set_current_user( 1 );

$wpdb->query( 'DELETE FROM ' . CC_Fleet_Loans::table() );
$wpdb->query( 'DELETE FROM ' . CC_Fleet_Riders::table() );
$wpdb->query( 'DELETE FROM ' . CC_Fleet_Outbox::table() );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%cc_wl_spot_%' OR option_name LIKE '_transient%cc_rl_%'" );
CC_Fleet_Settings::update( array( 'buffer_days' => 1, 'same_day_handoff' => 0, 'min_notice_days' => 1, 'requests_hold_dates' => 0, 'notify_emails' => 'crew@example.org', 'review_rider_emails' => 0, 'waitlist_offer_hours' => 24, 'waitlist_short_hours' => 48, 'waitlist_short_count' => 3, 'rate_limit_per_hour' => 50 ) );
$today = CC_Fleet_Settings::today();
$d     = function ( $n ) use ( $today ) { return CC_Fleet_Loans::add_days( $today, $n ); };
$b12   = CC_Fleet_Bikes::find_by_cc_id( 'CC-12' );
$b07   = CC_Fleet_Bikes::find_by_cc_id( 'CC-07' );
if ( ! $b12 ) {
	echo "Run smoke-test.php first (it imports the bikes).\n";
	return;
}
update_post_meta( $b12, '_cc_state', 'active' );
update_post_meta( $b07, '_cc_state', 'active' );
$size = CC_Fleet_Bikes::get( $b12, 'size' );

$rider = function ( $name, $height = 180 ) {
	return CC_Fleet_Riders::upsert( array( 'name' => $name, 'email' => strtolower( strtok( $name, ' ' ) ) . '@example.org', 'phone' => '+2782' . wp_rand( 1000000, 9999999 ), 'height_cm' => $height ) );
};
$thandi = $rider( 'Thandi Mokoena' );
$naledi = $rider( 'Naledi Khumalo' );
$luke   = $rider( 'Luke Peters' );
$amy    = $rider( 'Amy Jacobs' );
$tall   = $rider( 'Tall Tom', 200 );

echo "Joining\n";
$booked = CC_Fleet_Loans::create( array( 'bike_id' => $b12, 'rider_id' => $thandi, 'start_date' => $d( 10 ), 'end_date' => $d( 13 ), 'status' => 'approved' ) );
$req    = new WP_REST_Request( 'POST', '/cc/v1/requests' );
$base   = array( 'name' => 'Naledi Khumalo', 'email' => 'naledi@example.org', 'phone' => '82 555 0101', 'phone_cc' => '27', 'instagram' => 'none', 'height' => 180, 'plan' => 'Overnighter to Greyton', 'agree' => 1, 'consent' => 1, 'pedals' => 'need', 'bike' => 'CC-12', 'start' => $d( 10 ), 'end' => $d( 12 ) );
$req->set_body_params( $base );
$r = rest_do_request( $req );
cc_w( 'Booked bike: 409 that offers the waiting list', 409 === $r->get_status() && ! empty( $r->as_error()->get_error_data()['waitlist'] ), wp_json_encode( $r->get_data() ) );
$mails = array();
$req->set_body_params( $base + array( 'waitlist' => 1 ) );
$r = rest_do_request( $req );
cc_w( 'Joining from the form: 201 and on the list', 201 === $r->get_status() && ! empty( $r->get_data()['waitlist'] ), wp_json_encode( $r->get_data() ) );
$wn = CC_Fleet_Loans::query( array( 'status' => 'waiting', 'limit' => 5 ) );
cc_w( 'A waiting place was made, not a request', 1 === count( $wn ) && 0 === CC_Fleet_Loans::count( array( 'status' => 'requested' ) ) );
$naledi_wait = (int) $wn[0]->id;
cc_w( 'Rider got "You’re on the waiting list"', (bool) $mailed( 'naledi@', 'waiting list' ), $subjects() );
cc_w( 'Crew got no new-request alert for it', ! $mailed( 'crew@', 'New bike request' ), $subjects() );
cc_w( 'Waiting doesn’t block the calendar', ! array_filter( CC_Fleet_Loans::booked_ranges( $b12, $d( 0 ), $d( 30 ) ), function ( $x ) use ( $d ) { return $x['start'] === $d( 10 ) && $x['end'] === $d( 12 ); } ) );
$r = rest_do_request( $req );
cc_w( 'Joining twice keeps one place', 1 === CC_Fleet_Loans::count( array( 'status' => 'waiting' ) ) && ! empty( $r->get_data()['waitlist'] ) );
cc_w( 'Naledi is number 1', 1 === CC_Fleet_Waitlist::position( CC_Fleet_Loans::get( $naledi_wait ) ) );

// Luke asked the day before (older), Amy asked for an event, Tom is too tall for the bike.
$luke_wait = CC_Fleet_Loans::create( array( 'bike_id' => $b12, 'rider_id' => $luke, 'start_date' => $d( 11 ), 'end_date' => $d( 12 ), 'status' => 'waiting', 'source' => 'admin' ) );
$wpdb->update( CC_Fleet_Loans::table(), array( 'created_at' => wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'id' => $luke_wait ) );
$amy_wait = CC_Fleet_Loans::create( array( 'bike_id' => $b12, 'rider_id' => $amy, 'start_date' => $d( 10 ), 'end_date' => $d( 11 ), 'status' => 'waiting', 'source' => 'admin', 'event_id' => 999 ) );
$tom_wait = CC_Fleet_Loans::create( array( 'bike_id' => $b12, 'rider_id' => $tall, 'start_date' => $d( 10 ), 'end_date' => $d( 11 ), 'status' => 'waiting', 'source' => 'admin' ) );
cc_w( 'Nobody fits while the bike is booked', array() === CC_Fleet_Waitlist::candidates( $b12 ) );

echo "Moving a request to the list\n";
$req2 = CC_Fleet_Loans::create( array( 'bike_id' => $b12, 'rider_id' => $amy, 'start_date' => $d( 12 ), 'end_date' => $d( 13 ), 'status' => 'requested', 'source' => 'admin' ), true );
$mails = array();
cc_w( 'Request → waiting list', true === CC_Fleet_Loans::set_status( $req2, 'waiting' ) );
cc_w( 'They were emailed', (bool) $mailed( 'amy@', 'waiting list' ), $subjects() );
CC_Fleet_Loans::set_status( $req2, 'cancelled', false );

echo "Someone backs out\n";
$mails = array();
$res   = CC_Fleet_Waitlist::back_out( CC_Fleet_Loans::get( $booked ) );
cc_w( '"Can’t make it" cancels the booking', true === $res && 'cancelled' === CC_Fleet_Loans::get( $booked )->status );
cc_w( 'Thandi got "thanks for the heads-up"', (bool) $mailed( 'thandi@', 'heads-up' ), $subjects() );
$spot = $mailed( 'crew@', 'Spot open: CC-12' );
cc_w( 'Crew got "Spot open"', (bool) $spot, $subjects() );
$cands = CC_Fleet_Waitlist::candidates( $b12 );
$order = array_map( 'intval', wp_list_pluck( $cands, 'id' ) );
cc_w( 'Too tall rider is left out', ! in_array( $tom_wait, $order, true ) );
cc_w( 'Order: event rider, then the earlier ask, then the rest', array( $amy_wait, $luke_wait, $naledi_wait ) === $order, wp_json_encode( $order ) );
cc_w( 'Crew email lists the event rider first', $spot && strpos( $spot['message'], 'Amy Jacobs' ) < strpos( $spot['message'], 'Luke Peters' ) );
CC_Fleet_Waitlist::spot_check( $b12 );
cc_w( 'Same spot isn’t emailed twice', 1 === count( array_filter( $mails, function ( $m ) { return false !== strpos( $m['subject'], 'Spot open' ); } ) ) );

echo "Offer, review and the clock\n";
CC_Fleet_Settings::update( array( 'review_rider_emails' => 1 ) );
CC_Fleet_Emails::$held = 0;
$mails = array();
cc_w( 'Crew offers to Amy', true === CC_Fleet_Waitlist::offer( $amy_wait, $b12 ) );
$amy_l = CC_Fleet_Loans::get( $amy_wait );
cc_w( 'Amy is "offered" and the dates are held', 'offered' === $amy_l->status && ! CC_Fleet_Loans::is_available( $b12, $d( 10 ), $d( 11 ) ) );
cc_w( 'With review on, the offer waits for the crew (no clock yet)', CC_Fleet_Emails::$held && ! $amy_l->offer_until && ! $mailed( 'amy@', 'free' ) );
$row = CC_Fleet_Outbox::get( CC_Fleet_Emails::$held );
CC_Fleet_Outbox::send_row( $row );
$amy_l = CC_Fleet_Loans::get( $amy_wait );
cc_w( 'Sending it starts the 24 hours', $amy_l->offer_until && abs( strtotime( $amy_l->offer_until ) - strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS ) < 120, (string) $amy_l->offer_until );
$offer_mail = $mailed( 'amy@', 'It’s free' );
cc_w( 'Offer email has yes and no links', $offer_mail && false !== strpos( $offer_mail['message'], 'cc_wl=yes' ) && false !== strpos( $offer_mail['message'], 'cc_wl=no' ) );
cc_w( 'Nobody else fits while Amy decides', array() === array_values( array_filter( CC_Fleet_Waitlist::candidates( $b12 ), function ( $l ) use ( $d ) { return $l->start_date <= $d( 11 ); } ) ) );
CC_Fleet_Settings::update( array( 'review_rider_emails' => 0 ) );

echo "No answer in time\n";
$wpdb->update( CC_Fleet_Loans::table(), array( 'offer_until' => wp_date( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $amy_wait ) );
$mails = array();
$sum   = CC_Fleet_Waitlist::hourly();
$amy_l = CC_Fleet_Loans::get( $amy_wait );
cc_w( 'Amy steps back onto the list', 'waiting' === $amy_l->status && $amy_l->offer_lapsed_at, wp_json_encode( $sum ) );
cc_w( 'Amy got "Not this round"', (bool) $mailed( 'amy@', 'About ' . CC_Fleet_Bikes::public_short( $b12 ) ), $subjects() );
$luke_l = CC_Fleet_Loans::get( $luke_wait );
cc_w( 'It moved on to Luke by itself', 'offered' === $luke_l->status && $luke_l->offer_until, $luke_l->status );
cc_w( 'Luke got the offer straight away', (bool) $mailed( 'luke@', 'It’s free' ), $subjects() );
cc_w( 'Crew got the note', (bool) $mailed( 'crew@', 'didn’t answer' ), $subjects() );

echo "Rider pages\n";
// The pages themselves end with exit, so they're checked over HTTP (see the README).
$url = function ( $action, $loan_id ) { return CC_Fleet_Waitlist::url( CC_Fleet_Loans::get( $loan_id ), $action ); };
cc_w( 'Links carry the loan token', false !== strpos( $url( 'yes', $luke_wait ), CC_Fleet_Loans::get( $luke_wait )->token ) );

echo "Yes\n";
$mails = array();
cc_w( 'Luke says yes', true === CC_Fleet_Waitlist::accept( CC_Fleet_Loans::get( $luke_wait ) ) );
cc_w( 'Luke’s loan is approved', 'approved' === CC_Fleet_Loans::get( $luke_wait )->status );
$conf = $mailed( 'luke@', 'bike confirmed' );
cc_w( 'Luke got the normal confirmation, with the waiting-list line', $conf && false !== strpos( $conf['message'], 'Good things come' ), $subjects() );
cc_w( 'Confirmation has "Can’t make it?"', $conf && false !== strpos( $conf['message'], 'cc_wl=cancel' ) );
cc_w( 'Crew got "said yes"', (bool) $mailed( 'crew@', 'said yes' ), $subjects() );
cc_w( 'A second yes is refused', is_wp_error( CC_Fleet_Waitlist::accept( CC_Fleet_Loans::get( $luke_wait ) ) ) );

echo "No\n";
// Luke backs out again: Naledi (10–12) and Amy (10–11) fit; Amy missed an offer in the last 48 h so it goes to Naledi.
CC_Fleet_Waitlist::back_out( CC_Fleet_Loans::get( $luke_wait ) );
$mails = array();
$msg   = CC_Fleet_Waitlist::next_in_line( $b12 );
cc_w( 'Moving on skips the rider who just missed one', 'offered' === CC_Fleet_Loans::get( $naledi_wait )->status, $msg );
$mails = array();
cc_w( 'Naledi passes', true === CC_Fleet_Waitlist::pass( CC_Fleet_Loans::get( $naledi_wait ) ) );
cc_w( 'Passing takes her off the list', 'cancelled' === CC_Fleet_Loans::get( $naledi_wait )->status );
cc_w( 'Crew told: nobody else (Amy missed one recently)', (bool) $mailed( 'crew@', 'passed on CC-12' ) && 'waiting' === CC_Fleet_Loans::get( $amy_wait )->status, $subjects() );

echo "Short notice: first yes wins\n";
$wpdb->query( 'DELETE FROM ' . CC_Fleet_Loans::table() );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%cc_wl_spot_%'" );
$x1 = CC_Fleet_Loans::create( array( 'bike_id' => $b12, 'rider_id' => $naledi, 'start_date' => $d( 1 ), 'end_date' => $d( 2 ), 'status' => 'waiting', 'source' => 'admin' ) );
$x2 = CC_Fleet_Loans::create( array( 'bike_id' => $b12, 'rider_id' => $luke, 'start_date' => $d( 1 ), 'end_date' => $d( 3 ), 'status' => 'waiting', 'source' => 'admin' ) );
$x3 = CC_Fleet_Loans::create( array( 'bike_id' => 0, 'requested_size' => $size, 'rider_id' => $amy, 'start_date' => $d( 1 ), 'end_date' => $d( 2 ), 'status' => 'waiting', 'source' => 'admin' ) );
cc_w( 'Starts within 48 hours = short notice', CC_Fleet_Waitlist::is_short_notice( CC_Fleet_Loans::get( $x1 ) ) );
cc_w( '"Any bike in this size" counts for CC-12', in_array( $x3, array_map( 'intval', wp_list_pluck( CC_Fleet_Waitlist::candidates( $b12 ), 'id' ) ), true ) );
$mails = array();
cc_w( 'Offered to all three at once', 3 === CC_Fleet_Waitlist::offer_group( $b12, CC_Fleet_Waitlist::candidates( $b12 ) ) );
cc_w( 'All three got the "first yes" offer', 3 === count( array_filter( $mails, function ( $m ) { return false !== strpos( $m['message'], 'First yes gets it' ); } ) ), $subjects() );
$mails = array();
cc_w( 'Luke says yes first', true === CC_Fleet_Waitlist::accept( CC_Fleet_Loans::get( $x2 ) ) );
cc_w( 'The others step back onto the list', 'waiting' === CC_Fleet_Loans::get( $x1 )->status && 'waiting' === CC_Fleet_Loans::get( $x3 )->status );
cc_w( 'The "any size" rider is free of CC-12 again', 0 === (int) CC_Fleet_Loans::get( $x3 )->bike_id );
cc_w( 'They were told someone was quicker', (bool) $mailed( 'naledi@', 'About ' . CC_Fleet_Bikes::public_short( $b12 ) ) && (bool) $mailed( 'amy@', 'About ' . CC_Fleet_Bikes::public_short( $b12 ) ), $subjects() );
cc_w( 'A late yes is refused', is_wp_error( CC_Fleet_Waitlist::accept( CC_Fleet_Loans::get( $x1 ) ) ) );

echo "The list closes on the start day\n";
$wpdb->update( CC_Fleet_Loans::table(), array( 'start_date' => $today ), array( 'id' => $x1 ) );
$mails = array();
CC_Fleet_Waitlist::hourly();
cc_w( 'Closed', 'declined' === CC_Fleet_Loans::get( $x1 )->status );
cc_w( 'Naledi got "Not this time"', (bool) $mailed( 'naledi@', 'waiting list spot' ), $subjects() );

echo "Crew screens and previews\n";
$_GET = array( 'page' => 'cc-fleet' );
ob_start();
CC_Fleet_Waitlist::dashboard_panel();
$panel = ob_get_clean();
cc_w( 'Dashboard has the waiting list', false !== strpos( $panel, 'id="waitlist"' ) && false !== strpos( $panel, 'Everyone waiting' ) );
$prev = CC_Fleet_Emails::preview_page();
cc_w( 'Email preview shows the 7 waiting-list emails', 7 === preg_match_all( '/(waiting list|it’s free|not this round|list closed|heads-up|spot open)/i', implode( ' ', array_keys( CC_Fleet_Emails::samples() ) ) ) );
cc_w( 'Each sample renders', false === strpos( $prev, 'Warning' ) && false === strpos( $prev, 'Notice' ) );

echo "Bike IDs stay internal\n";
$leaks = array();
foreach ( $all_mails as $m ) {
	$to = is_array( $m['to'] ) ? implode( ',', $m['to'] ) : (string) $m['to'];
	if ( false === strpos( $to, 'crew@' ) && preg_match( '/\bCC-\d+/', $m['subject'] . ' ' . wp_strip_all_tags( $m['message'] ) ) ) {
		$leaks[] = $m['subject'];
	}
}
cc_w( 'No rider email shows a Bike ID', ! $leaks, implode( ' | ', $leaks ) );
$crew_ids = array_filter( $all_mails, function ( $m ) { return false !== strpos( is_array( $m['to'] ) ? implode( ',', $m['to'] ) : (string) $m['to'], 'crew@' ) && preg_match( '/\bCC-\d+/', $m['subject'] ); } );
cc_w( 'Crew emails keep the Bike ID', (bool) $crew_ids );
foreach ( CC_Fleet_Emails::samples() as $label => $smp ) {
	if ( 0 === strpos( $label, 'Rider' ) && preg_match( '/\bCC-\d+/', $smp[0] . ' ' . wp_strip_all_tags( CC_Fleet_Emails::render_html( $smp[1], $smp[0] ) ) ) ) {
		$leaks[] = $label;
	}
}
cc_w( 'No rider preview shows a Bike ID', ! $leaks, implode( ' | ', $leaks ) );
$grid = do_shortcode( '[cc_fleet]' );
cc_w( 'Fleet cards show the Standert logo, no Bike ID', false !== strpos( $grid, 'aria-label="Standert"' ) && ! preg_match( '/\bCC-\d+/', wp_strip_all_tags( $grid ) ) && ! preg_match( '/data-bike="CC-/', $grid ) );
$land = CC_Fleet_Landing::data();
cc_w( 'Home page cards get the logo', false !== strpos( $land['brand'], '<svg' ) );
$form = do_shortcode( '[cc_bike_request bike="CC-12"]' );
cc_w( 'Request form carries no Bike ID', ! preg_match( '/CC-\d+/', $form ) && false !== strpos( $form, 'Request this bike' ) );
cc_w( 'Bike page title is the public name', CC_Fleet_Bikes::public_label( $b12 ) === CC_Fleet_Bikes::public_title( get_the_title( $b12 ), $b12 ) );
wp_update_post( array( 'ID' => $b12, 'post_name' => 'cc-12' ) );
CC_Fleet_Bikes::migrate_slugs();
cc_w( 'Bike address moves to model and size', sanitize_title( CC_Fleet_Bikes::public_short( $b12 ) ) === get_post_field( 'post_name', $b12 ) );
cc_w( 'Old address is kept for the redirect', in_array( 'cc-12', get_post_meta( $b12, '_wp_old_slug' ), true ) );

echo "\n" . $GLOBALS['cc_pass'] . ' passed, ' . $GLOBALS['cc_fail'] . " failed\n";
