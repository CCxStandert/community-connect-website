<?php
/**
 * CC Fleet smoke test. Run on a THROWAWAY site only (it deletes fleet data):
 *   wp eval-file wp-content/plugins/cc-fleet/tests/smoke-test.php
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
global $wpdb;
$GLOBALS['cc_pass'] = 0; $GLOBALS['cc_fail'] = 0;
function cc_t( $name, $cond, $extra = '' ) {
	if ( $cond ) { $GLOBALS['cc_pass']++; echo "  ✓ $name\n"; } else { $GLOBALS['cc_fail']++; echo "  ✗ $name" . ( $extra ? " — $extra" : '' ) . "\n"; }
}
$mails = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );
wp_set_current_user( 1 );
add_action( 'doing_it_wrong_run', function ( $f ) { echo '    [doing_it_wrong] ' . $f . ' ← ' . implode( ' ← ', array_slice( array_map( function ( $x ) { return ( $x['class'] ?? '' ) . '::' . $x['function']; }, debug_backtrace() ), 3, 6 ) ) . "\n"; } );

// Clean slate.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%cc_rl_%'" );
$wpdb->query( 'DELETE FROM ' . CC_Fleet_Loans::table() );
$wpdb->query( 'DELETE FROM ' . CC_Fleet_Riders::table() );
foreach ( get_posts( array( 'post_type' => 'cc_bike', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }
CC_Fleet_Settings::update( array( 'buffer_days' => 0, 'same_day_handoff' => 1, 'min_notice_days' => 1, 'max_loan_days' => 45, 'requests_hold_dates' => 0, 'notify_emails' => 'crew@example.org', 'rate_limit_per_hour' => 5, 'review_rider_emails' => 0 ) );
$today = CC_Fleet_Settings::today();
$d = function ( $n ) use ( $today ) { return CC_Fleet_Loans::add_days( $today, $n ); };

echo "Import\n";
$publish = array( 'CC-03', 'CC-04', 'CC-05', 'CC-09', 'CC-11', 'CC-12', 'CC-13', 'CC-06', 'CC-07', 'CC-08' );
$r = CC_Fleet_Import::bikes_csv( CC_FLEET_DIR . 'data/fleet-2027.csv', $publish );
cc_t( '14 bikes created from the sheet', 14 === $r['created'], wp_json_encode( $r ) );
cc_t( '10 bikes on the website', 10 === count( CC_Fleet_Bikes::public_ids() ) );
$r2 = CC_Fleet_Import::bikes_csv( CC_FLEET_DIR . 'data/fleet-2027.csv', $publish );
cc_t( 'Re-import updates in place', 0 === $r2['created'] && 14 === $r2['updated'] );
$b12 = CC_Fleet_Bikes::find_by_cc_id( 'CC-12' );
$b13 = CC_Fleet_Bikes::find_by_cc_id( 'CC-13' );
$b06 = CC_Fleet_Bikes::find_by_cc_id( 'CC-06' );
cc_t( 'CC-12 spec imported', 'HUNT 40 CGR' === CC_Fleet_Bikes::get( $b12, 'wheelset' ) && '56' === CC_Fleet_Bikes::get( $b12, 'size' ) );
cc_t( 'CC-02 date 6/12/2026 read as 12 June', '2026-06-12' === CC_Fleet_Bikes::get( CC_Fleet_Bikes::find_by_cc_id( 'CC-02' ), 'date_added' ) );
cc_t( 'CC-10 (not in fleet) imported as retired + hidden', 'retired' === CC_Fleet_Bikes::get( CC_Fleet_Bikes::find_by_cc_id( 'CC-10' ), 'state' ) );
$pub = CC_Fleet_Bikes::public_data( $b06 );
$keys = wp_list_pluck( $pub['spec'], 'key' );
cc_t( 'Public data hides owner, wear, notes, replace', ! in_array( 'owner', $keys, true ) && ! in_array( 'replace_now', $keys, true ) && ! isset( $pub['owner'] ) && false === strpos( wp_json_encode( $pub ), 'Should get a repaint' ) );
cc_t( 'Size table gives CC-12 rider range', array( 176, 184 ) === $pub['rider_range'] || array( 176, 184 ) === CC_Fleet_Bikes::rider_range( $b12 ) );

echo "Booking rules\n";
$rider = CC_Fleet_Riders::upsert( array( 'name' => 'Test Rider', 'email' => 'rider@example.org', 'phone' => '082 000 0000' ) );
$a = CC_Fleet_Loans::create( array( 'bike_id' => $b12, 'rider_id' => $rider, 'start_date' => $d( 10 ), 'end_date' => $d( 14 ), 'status' => 'approved' ) );
cc_t( 'Approved loan created', is_int( $a ) );
$c = CC_Fleet_Loans::create( array( 'bike_id' => $b12, 'rider_id' => $rider, 'start_date' => $d( 12 ), 'end_date' => $d( 16 ), 'status' => 'approved' ) );
cc_t( 'Overlap is refused', is_wp_error( $c ) && 'cc_conflict' === $c->get_error_code() );
$h = CC_Fleet_Loans::create( array( 'bike_id' => $b12, 'rider_id' => $rider, 'start_date' => $d( 14 ), 'end_date' => $d( 18 ), 'status' => 'approved' ) );
cc_t( 'Same-day handoff allowed (starts the day the other ends)', is_int( $h ) );
$before = CC_Fleet_Loans::create( array( 'bike_id' => $b12, 'rider_id' => $rider, 'start_date' => $d( 6 ), 'end_date' => $d( 10 ), 'status' => 'approved' ) );
cc_t( 'Same-day handoff allowed (ends the day the other starts)', is_int( $before ) );
$two = CC_Fleet_Loans::conflicts( $b12, $d( 13 ), $d( 20 ) );
cc_t( '2-day overlap detected', count( $two ) >= 1 );
CC_Fleet_Settings::update( array( 'same_day_handoff' => 0 ) );
cc_t( 'Handoff day blocked when same-day handoff is off', (bool) CC_Fleet_Loans::conflicts( $b12, $d( 18 ), $d( 20 ) ) );
CC_Fleet_Settings::update( array( 'same_day_handoff' => 1, 'buffer_days' => 1 ) );
cc_t( 'Buffer day blocks the day after a loan', (bool) CC_Fleet_Loans::conflicts( $b12, $d( 19 ), $d( 21 ) ) );
cc_t( 'Buffer: two days later is free', ! CC_Fleet_Loans::conflicts( $b12, $d( 20 ), $d( 22 ) ) );
CC_Fleet_Settings::update( array( 'buffer_days' => 0 ) );
cc_t( 'Forced overlap allowed for admin override', is_int( CC_Fleet_Loans::create( array( 'bike_id' => $b12, 'rider_id' => $rider, 'start_date' => $d( 11 ), 'end_date' => $d( 12 ), 'status' => 'requested' ), true ) ) );
cc_t( 'Requested loans do not block by default', CC_Fleet_Loans::is_available( $b13, $d( 30 ), $d( 31 ) ) );

echo "Lifecycle\n";
$req = CC_Fleet_Loans::create( array( 'bike_id' => $b13, 'rider_id' => $rider, 'start_date' => $d( 30 ), 'end_date' => $d( 32 ), 'status' => 'requested' ) );
cc_t( 'requested → out is not allowed', is_wp_error( CC_Fleet_Loans::set_status( $req, 'out' ) ) );
cc_t( 'requested → approved', true === CC_Fleet_Loans::set_status( $req, 'approved' ) );
cc_t( 'Admin can\'t log a request over an approved loan without override', is_wp_error( CC_Fleet_Loans::create( array( 'bike_id' => $b13, 'rider_id' => $rider, 'start_date' => $d( 31 ), 'end_date' => $d( 33 ), 'status' => 'requested' ) ) ) );
$req2 = CC_Fleet_Loans::create( array( 'bike_id' => $b13, 'rider_id' => $rider, 'start_date' => $d( 31 ), 'end_date' => $d( 33 ), 'status' => 'requested' ), true );
cc_t( 'Approving a clashing request is refused', is_wp_error( CC_Fleet_Loans::set_status( $req2, 'approved' ) ) );
$early = CC_Fleet_Loans::create( array( 'bike_id' => $b06, 'rider_id' => $rider, 'start_date' => $d( -2 ), 'end_date' => $d( 5 ), 'status' => 'approved' ) );
CC_Fleet_Loans::set_status( $early, 'out', false );
cc_t( 'Bike shows as out today', 'out' === CC_Fleet_Bikes::state_now( $b06 ) );
CC_Fleet_Loans::set_status( $early, 'returned', false );
cc_t( 'Early return shortens the loan to today', CC_Fleet_Loans::get( $early )->end_date === $today );
cc_t( 'Bike is available again', 'available' === CC_Fleet_Bikes::state_now( $b06 ) );

echo "Overdue\n";
$b09 = CC_Fleet_Bikes::find_by_cc_id( 'CC-09' );
$od  = CC_Fleet_Loans::create( array( 'bike_id' => $b09, 'rider_id' => $rider, 'start_date' => $d( -10 ), 'end_date' => $d( -3 ), 'status' => 'approved' ), true );
CC_Fleet_Loans::set_status( $od, 'out', false, true );
cc_t( 'Loan past its end and not returned is overdue', CC_Fleet_Loans::is_overdue( CC_Fleet_Loans::get( $od ) ) );
cc_t( 'Overdue bike still blocks today', ! CC_Fleet_Loans::is_available( $b09, $today, $d( 1 ) ) );
cc_t( 'Overdue bike frees tomorrow-onwards only after return', ! CC_Fleet_Loans::is_available( $b09, $today, $today ) && CC_Fleet_Loans::is_available( $b09, $d( 2 ), $d( 3 ) ) );
cc_t( 'Overdue query finds it', 1 === count( CC_Fleet_Loans::query( array( 'overdue' => true ) ) ) );

echo "Workshop state\n";
$b07 = CC_Fleet_Bikes::find_by_cc_id( 'CC-07' );
CC_Fleet_Bikes::set( $b07, 'state', 'workshop' );
cc_t( 'Workshop bike is not bookable', ! CC_Fleet_Loans::is_available( $b07, $d( 5 ), $d( 6 ) ) && 'workshop' === CC_Fleet_Bikes::state_now( $b07 ) );
CC_Fleet_Bikes::set( $b07, 'state', 'active' );

echo "Public API\n";
$call = function ( $method, $route, $params = array() ) {
	$req = new WP_REST_Request( $method, '/cc/v1' . $route );
	// Requests made before the form required everything get valid defaults; tests that check a field pass it explicitly (null = leave it out).
	if ( 'POST' === $method && '/requests' === $route && empty( $params['_raw'] ) ) {
		$params = array_merge( array( 'phone' => '082 555 0101', 'phone_cc' => '27', 'instagram' => 'none', 'height' => 175, 'plan' => 'Test ride', 'consent' => 1 ), $params );
	}
	unset( $params['_raw'] );
	$params = array_filter( $params, function ( $v ) { return null !== $v; } );
	if ( 'POST' === $method ) { $req->set_header( 'content-type', 'application/json' ); $req->set_body( wp_json_encode( $params ) ); }
	else { $req->set_query_params( $params ); }
	return rest_do_request( $req );
};
wp_set_current_user( 0 );
$res = $call( 'GET', '/bikes' );
cc_t( 'GET /bikes returns 10', 200 === $res->get_status() && 10 === count( $res->get_data() ) );
cc_t( 'No private data in /bikes', false === stripos( wp_json_encode( $res->get_data() ), 'Julian' ) && false === stripos( wp_json_encode( $res->get_data() ), 'needs an audit' ) );
cc_t( 'Hidden bike CC-14 is 404', 404 === $call( 'GET', '/bikes/CC-14' )->get_status() );
$av = $call( 'GET', '/bikes/CC-12/availability', array( 'from' => $today, 'to' => $d( 60 ) ) );
$avd = $av->get_data();
cc_t( 'Availability lists booked ranges', 200 === $av->get_status() && count( $avd['booked'] ) >= 3 );
cc_t( 'Availability has no names', false === strpos( wp_json_encode( $avd ), 'Test Rider' ) );
cc_t( 'Availability is not cacheable', 'no-store, max-age=0' === ( $av->get_headers()['Cache-Control'] ?? '' ) );
$mails = array();
$ok = $call( 'POST', '/requests', array( 'bike' => 'CC-05', 'start' => $d( 20 ), 'end' => $d( 23 ), 'name' => 'Naledi K', 'email' => 'naledi@example.org', 'phone' => '0821234567', 'instagram' => '@naledi', 'height' => 168, 'plan' => 'Cederberg overnighter', 'agree' => 1, 'consent' => 1, 't' => time() - 30 ) );
cc_t( 'Valid request → 201', 201 === $ok->get_status(), wp_json_encode( $ok->get_data() ) );
cc_t( 'Rider + crew both emailed', 2 === count( $mails ) && 'naledi@example.org' === $mails[0]['to'] && in_array( 'crew@example.org', (array) $mails[1]['to'], true ) );
$crew_mail = $mails[1]['message'] ?? '';
cc_t( 'Crew email has contact + review link', false !== strpos( $crew_mail, '+27 82 123 4567' ) && false !== strpos( $crew_mail, 'page=cc-fleet-loan' ) );
$dup = $call( 'POST', '/requests', array( 'bike' => 'CC-12', 'start' => $d( 11 ), 'end' => $d( 13 ), 'name' => 'Sam R', 'email' => 'sam@example.org', 'agree' => 1, 't' => time() - 30 ) );
cc_t( 'Request on booked dates → 409 with alternatives', 409 === $dup->get_status() && isset( $dup->get_data()['data']['alternatives'] ) );
$bad = $call( 'POST', '/requests', array( 'bike' => 'CC-05', 'start' => $d( 5 ), 'end' => $d( 2 ), 'name' => 'X', 'email' => 'nope', 't' => time() - 30 ) );
$f   = $bad->get_data()['data']['fields'] ?? array();
cc_t( 'Validation errors per field', 400 === $bad->get_status() && isset( $f['name'], $f['email'], $f['dates'], $f['agree'] ) );
$past = $call( 'POST', '/requests', array( 'bike' => 'CC-05', 'start' => $today, 'end' => $d( 1 ), 'name' => 'Too Soon', 'email' => 'soon@example.org', 'agree' => 1, 't' => time() - 30 ) );
cc_t( 'Minimum notice enforced', 400 === $past->get_status() );
$long = $call( 'POST', '/requests', array( 'bike' => 'CC-05', 'start' => $d( 100 ), 'end' => $d( 160 ), 'name' => 'Long Trip', 'email' => 'long@example.org', 'agree' => 1, 't' => time() - 30 ) );
cc_t( 'Maximum loan length enforced', 400 === $long->get_status() );
$bot = $call( 'POST', '/requests', array( 'bike' => 'CC-05', 'start' => $d( 40 ), 'end' => $d( 41 ), 'name' => 'Bot', 'email' => 'bot@example.org', 'agree' => 1, 'website' => 'http://spam', 't' => time() - 30 ) );
cc_t( 'Honeypot: bot gets a fake OK and nothing is saved', 200 === $bot->get_status() && ! CC_Fleet_Riders::find_by_email( 'bot@example.org' ) );
$fast = $call( 'POST', '/requests', array( 'bike' => 'CC-05', 'start' => $d( 40 ), 'end' => $d( 41 ), 'name' => 'Speedy', 'email' => 'speedy@example.org', 'agree' => 1, 't' => time() ) );
cc_t( 'Form sent in under 3 seconds is refused', 429 === $fast->get_status() );
$any = $call( 'POST', '/requests', array( 'bike' => 'any', 'size' => '54', 'start' => $d( 50 ), 'end' => $d( 52 ), 'name' => 'Any Size', 'email' => 'any@example.org', 'agree' => 1, 't' => time() - 30 ) );
cc_t( '"Any bike in my size" request accepted', 201 === $any->get_status() );
$hits = 0;
for ( $i = 0; $i < 6; $i++ ) {
	$x = $call( 'POST', '/requests', array( 'bike' => 'CC-04', 'start' => $d( 60 + $i * 3 ), 'end' => $d( 61 + $i * 3 ), 'name' => 'Flood ' . $i, 'email' => "flood$i@example.org", 'agree' => 1, 't' => time() - 30 ) );
	if ( 429 === $x->get_status() ) { $hits++; }
}
cc_t( 'Rate limit kicks in', $hits >= 1 );
$fs = $call( 'GET', '/fleet-status' )->get_data();
cc_t( 'Fleet status counts', 10 === $fs['bikes'] && $fs['loans_this_year'] >= 1 );
cc_t( 'Admin API refuses anonymous users', 401 === $call( 'GET', '/admin/loans' )->get_status() );
wp_set_current_user( 1 );
add_action( 'doing_it_wrong_run', function ( $f ) { echo '    [doing_it_wrong] ' . $f . ' ← ' . implode( ' ← ', array_slice( array_map( function ( $x ) { return ( $x['class'] ?? '' ) . '::' . $x['function']; }, debug_backtrace() ), 3, 6 ) ) . "\n"; } );
cc_t( 'Admin API works for admins', 200 === $call( 'GET', '/admin/loans' )->get_status() );

echo "Emails on status change\n";
$mails = array();
$nk = CC_Fleet_Loans::query( array( 'search' => 'Naledi', 'limit' => 1 ) )[0];
CC_Fleet_Loans::set_status( $nk->id, 'approved' );
cc_t( 'Approval email to rider', 1 === count( $mails ) && false !== strpos( $mails[0]['subject'], 'confirmed' ) );

echo "Calendar feed\n";
$ics = CC_Fleet_Ical::build();
cc_t( 'iCal is valid-looking', 0 === strpos( $ics, "BEGIN:VCALENDAR\r\n" ) && false !== strpos( $ics, 'BEGIN:VEVENT' ) && substr_count( $ics, 'BEGIN:VEVENT' ) === substr_count( $ics, 'END:VEVENT' ) );
$long_lines = array_filter( explode( "\r\n", $ics ), function ( $l ) { return strlen( $l ) > 75; } );
cc_t( 'iCal lines folded to 75 octets', ! $long_lines );
cc_t( 'Overdue loan marked in feed', false !== strpos( $ics, '⚠ CC-09' ) );

echo "Daily jobs\n";
$start_today = CC_Fleet_Loans::create( array( 'bike_id' => $b13, 'rider_id' => $rider, 'start_date' => $today, 'end_date' => $d( 1 ), 'status' => 'approved' ) );
$mails = array();
$sum = CC_Fleet_Cron::run();
cc_t( 'Approved loan starting today is now out', 'out' === CC_Fleet_Loans::get( $start_today )->status );
cc_t( 'Return reminder sent for loan due tomorrow', $sum['reminders'] >= 1 );
cc_t( 'Crew digest sent', (bool) array_filter( $mails, function ( $m ) { return 0 === strpos( $m['subject'], 'Fleet today' ); } ) );
$sum2 = CC_Fleet_Cron::run();
cc_t( 'Reminder not sent twice', 0 === $sum2['reminders'] );
cc_t( 'Daily job is scheduled', (bool) wp_next_scheduled( CC_Fleet_Cron::HOOK ) );

echo "Privacy\n";
$exp = CC_Fleet_Privacy::export( 'naledi@example.org' );
cc_t( 'Export returns profile + loans', count( $exp['data'] ) >= 2 );
CC_Fleet_Privacy::erase( 'naledi@example.org' );
$loan_after = CC_Fleet_Loans::get( $nk->id );
cc_t( 'Erase anonymises rider, keeps loan dates', ! CC_Fleet_Riders::find_by_email( 'naledi@example.org' ) && $loan_after && '' === $loan_after->plan );

echo "Admin screens render\n";
require_once CC_FLEET_DIR . "includes/class-admin.php";
require_once CC_FLEET_DIR . "includes/class-records-admin.php";
require_once ABSPATH . "wp-admin/includes/template.php";
require_once ABSPATH . "wp-admin/includes/screen.php";
set_current_screen( "dashboard" );
foreach ( array( 'page_dashboard', 'page_calendar', 'page_loans', 'page_riders', 'page_import', 'page_settings' ) as $p ) {
	ob_start();
	try { CC_Fleet_Admin::$p(); $html = ob_get_clean(); cc_t( "$p renders", strlen( $html ) > 200 ); }
	catch ( Throwable $e ) { ob_end_clean(); cc_t( "$p renders", false, $e->getMessage() ); }
}
$_GET['id'] = $a;
ob_start(); CC_Fleet_Admin::page_loan(); $html = ob_get_clean();
cc_t( 'Loan edit page shows handoff + history', false !== strpos( $html, 'History' ) );
unset( $_GET['id'] );

echo "Front end\n";
$html = do_shortcode( '[cc_fleet]' );
cc_t( '[cc_fleet] renders 10 cards', 10 === substr_count( $html, 'class="cc-bike-card"' ) );
cc_t( '[cc_bike_request] renders form + calendar', false !== strpos( do_shortcode( '[cc_bike_request bike="CC-12"]' ), 'data-cc-calendar' ) );


echo "Review emails, handoff check, one free day\n";
$def = CC_Fleet_Settings::defaults();
cc_t( 'New sites keep one free day between loans', 1 === $def['buffer_days'] && 0 === $def['same_day_handoff'] && 1 === $def['review_rider_emails'] );
CC_Fleet_Settings::update( array( 'buffer_days' => 1, 'same_day_handoff' => 0, 'review_rider_emails' => 1, 'reminder_days_before' => 1, 'dropoff_place' => 'Woodstock Cycleworks' ) );
$b04 = CC_Fleet_Bikes::find_by_cc_id( 'CC-04' );
$rv  = CC_Fleet_Riders::upsert( array( 'name' => 'Review Rider', 'email' => 'review@example.org', 'phone' => '+27820001111' ) );
$rn  = CC_Fleet_Riders::upsert( array( 'name' => 'Next Rider', 'email' => 'next@example.org', 'phone' => '+27820002222' ) );
$la  = CC_Fleet_Loans::create( array( 'bike_id' => $b04, 'rider_id' => $rv, 'start_date' => $d( 300 ), 'end_date' => $d( 303 ), 'status' => 'approved', 'source' => 'admin' ) );
$tight = CC_Fleet_Loans::create( array( 'bike_id' => $b04, 'rider_id' => $rn, 'start_date' => $d( 304 ), 'end_date' => $d( 306 ), 'status' => 'approved', 'source' => 'admin' ) );
cc_t( 'Next loan can’t start the day after a return', is_wp_error( $tight ) );
$lb = CC_Fleet_Loans::create( array( 'bike_id' => $b04, 'rider_id' => $rn, 'start_date' => $d( 305 ), 'end_date' => $d( 307 ), 'status' => 'requested', 'source' => 'admin' ) );
cc_t( 'Next loan fine with one free day in between', is_int( $lb ) );
$mails = array(); CC_Fleet_Emails::$held = 0;
CC_Fleet_Loans::set_status( $lb, 'approved', true );
$draft = CC_Fleet_Emails::$held ? CC_Fleet_Outbox::get( CC_Fleet_Emails::$held ) : null;
cc_t( 'Approval email kept for review, not sent', ! $mails && $draft && 'draft' === $draft->status && 'next@example.org' === $draft->to_email );
cc_t( 'Draft listed on the dashboard', (bool) array_filter( CC_Fleet_Outbox::waiting(), function ( $w ) use ( $draft ) { return (int) $w->id === (int) $draft->id; } ) );
$_GET = array( 'page' => 'cc-fleet-email', 'id' => $draft->id ); ob_start(); CC_Fleet_Outbox::page(); $cp = ob_get_clean();
cc_t( 'Compose screen shows subject, message and preview', false !== strpos( $cp, 'name="subject"' ) && false !== strpos( $cp, 'name="block[' ) && false !== strpos( $cp, 'srcdoc=' ) && false !== strpos( $cp, 'Send to rider' ) );
$ap = new ReflectionMethod( 'CC_Fleet_Outbox', 'apply_edits' ); $ap->setAccessible( true );
$first = array_key_first( array_filter( $draft->msg['blocks'], function ( $b ) { return 'p' === $b[0]; } ) );
$edited = $ap->invoke( null, $draft->msg, array( 'headline' => 'You’re on, Next!', 'block' => array( $first => "Hi Next,\n\nSo happy this worked out." ), 'note' => 'Meet at 8:00 at the shop door.' ) );
$wpdb->update( CC_Fleet_Outbox::table(), array( 'msg' => wp_json_encode( $edited ), 'subject' => 'Bike confirmed (edited)' ), array( 'id' => $draft->id ) );
$sent = CC_Fleet_Outbox::send_row( CC_Fleet_Outbox::get( $draft->id ) );
$m = end( $mails );
cc_t( 'Edited email goes out with the changes', $sent && $m && 'Bike confirmed (edited)' === $m['subject'] && false !== strpos( $m['message'], 'So happy this worked out' ) && false !== strpos( $m['message'], 'Meet at 8:00' ) );
cc_t( 'Sent draft no longer waiting', 'sent' === CC_Fleet_Outbox::get( $draft->id )->status );
$again = $ap->invoke( null, $edited, array( 'note' => 'Changed note' ) );
cc_t( 'Extra note replaced, not duplicated', 1 === count( array_filter( $again['blocks'], function ( $b ) { return ! empty( $b['_note'] ); } ) ) );

// Day-before check: loan due back in two days (reminder tomorrow).
$wpdb->query( $wpdb->prepare( 'UPDATE ' . CC_Fleet_Loans::table() . " SET status = 'returned' WHERE status IN ('approved','out') AND end_date = %s", $d( 2 ) ) );
$b09 = CC_Fleet_Bikes::find_by_cc_id( 'CC-09' );
$lc  = CC_Fleet_Loans::create( array( 'bike_id' => $b09, 'rider_id' => $rv, 'start_date' => $d( -1 ), 'end_date' => $d( 2 ), 'status' => 'approved', 'source' => 'admin' ), true );
CC_Fleet_Loans::set_status( $lc, 'out', false, true );
$ln  = CC_Fleet_Loans::create( array( 'bike_id' => $b09, 'rider_id' => $rn, 'start_date' => $d( 4 ), 'end_date' => $d( 6 ), 'status' => 'approved', 'source' => 'admin' ), true );
CC_Fleet_Loans::update( $lc, array( 'handoff_place' => 'Woodstock Cycleworks', 'handoff_time' => 'Thu 17:30', 'handoff_note' => 'Next brings own pedals.' ), true );
$mails = array();
$sum = CC_Fleet_Cron::run();
$chk = array_values( array_filter( $mails, function ( $x ) { return 0 === strpos( $x['subject'], 'Check tomorrow' ); } ) );
cc_t( 'Crew gets the handoff check the day before the reminder', 1 === count( $chk ) && in_array( 'crew@example.org', (array) $chk[0]['to'], true ) );
cc_t( 'Check lists both riders and the handoff', $chk && false !== strpos( $chk[0]['message'], 'Next Rider' ) && false !== strpos( $chk[0]['message'], 'Woodstock Cycleworks' ) && false !== strpos( $chk[0]['message'], 'Thu 17:30' ) );
$mails = array(); CC_Fleet_Cron::run();
cc_t( 'Check not sent twice', ! array_filter( $mails, function ( $x ) { return 0 === strpos( $x['subject'], 'Check tomorrow' ); } ) );
$rb = CC_Fleet_Emails::build_reminder( CC_Fleet_Loans::get( $lc ) );
cc_t( 'Reminder includes where, when and the note', false !== strpos( CC_Fleet_Emails::render_text( $rb['msg'] ), 'Thu 17:30' ) && false !== strpos( CC_Fleet_Emails::render_text( $rb['msg'] ), 'own pedals' ) );
$sid = CC_Fleet_Outbox::hold( $rb['to'], 'Edited reminder subject', $rb['msg'], array( 'type' => 'reminder', 'loan' => CC_Fleet_Loans::get( $lc ), 'status' => 'scheduled' ) );
$mails = array();
CC_Fleet_Emails::return_reminder( CC_Fleet_Loans::get( $lc ) );
cc_t( 'Saved edited reminder is the one that goes out', 1 === count( $mails ) && 'Edited reminder subject' === $mails[0]['subject'] && 'sent' === CC_Fleet_Outbox::get( $sid )->status );
$_GET = array( 'page' => 'cc-fleet-loan', 'id' => $lc ); ob_start(); CC_Fleet_Admin::page_loan(); $lp = ob_get_clean();
cc_t( 'Loan page has the handoff card', false !== strpos( $lp, 'id="handoff"' ) && false !== strpos( $lp, 'name="handoff_place"' ) );
$old_prev = CC_Fleet_Loans::create( array( 'bike_id' => $b09, 'rider_id' => $rv, 'start_date' => $d( 40 ), 'end_date' => $d( 42 ), 'status' => 'approved', 'source' => 'admin' ), true );
cc_t( 'A loan weeks later isn’t treated as the next handoff', (int) CC_Fleet_Loans::next_loan( CC_Fleet_Loans::get( $ln ) )?->id !== (int) $old_prev );
CC_Fleet_Settings::update( array( 'review_rider_emails' => 0, 'buffer_days' => 0, 'same_day_handoff' => 1 ) );
echo "\n" . $GLOBALS['cc_pass'] . ' passed, ' . $GLOBALS['cc_fail'] . " failed\n";

echo "Backend-only bike (CC-14)\n";
wp_set_current_user( 1 );
$b14 = CC_Fleet_Bikes::find_by_cc_id( 'CC-14' );
cc_t( 'CC-14 imported, active, not on website', $b14 && 'active' === CC_Fleet_Bikes::get( $b14, 'state' ) && '0' === CC_Fleet_Bikes::get( $b14, 'show_on_site' ) );
cc_t( 'CC-14 not in public list', ! in_array( $b14, CC_Fleet_Bikes::public_ids(), true ) );
$l14 = CC_Fleet_Loans::create( array( 'bike_id' => $b14, 'rider_id' => $rider, 'start_date' => $d( 40 ), 'end_date' => $d( 43 ), 'status' => 'approved', 'source' => 'admin' ) );
cc_t( 'Crew can book CC-14 in the backend', is_int( $l14 ) );
cc_t( 'Double-booking rules still apply to CC-14', is_wp_error( CC_Fleet_Loans::create( array( 'bike_id' => $b14, 'rider_id' => $rider, 'start_date' => $d( 41 ), 'end_date' => $d( 42 ), 'status' => 'approved' ) ) ) );
cc_t( 'CC-14 shows in the crew calendar feed', false !== strpos( CC_Fleet_Ical::build(), 'CC-14' ) );
wp_set_current_user( 0 );
delete_transient( 'cc_rl_' . md5( ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt() ) );
$pr = $call( 'POST', '/requests', array( 'bike' => 'CC-14', 'start' => $d( 70 ), 'end' => $d( 71 ), 'name' => 'Outsider', 'email' => 'out@example.org', 'agree' => 1, 't' => time() - 30 ) );
cc_t( 'Riders cannot request CC-14 from the website', 400 === $pr->get_status() && isset( $pr->get_data()['data']['fields']['bike'] ), $pr->get_status() . ' ' . wp_json_encode( $pr->get_data() ) );
cc_t( 'CC-14 not offered as an alternative', ! in_array( 'CC-14', wp_list_pluck( $call( 'GET', '/available', array( 'start' => $d( 70 ), 'end' => $d( 71 ), 'size' => '56' ) )->get_data(), 'cc_id' ), true ) );
cc_t( 'No CC-14 page on the website', 404 === $call( 'GET', '/bikes/CC-14/availability' )->get_status() );
wp_set_current_user( 1 );

echo "Workshop, stock, events, notes\n";
wp_set_current_user( 1 );
$sid = CC_Fleet_Records::save( 'stock', array( 'name' => 'Test tubes', 'category' => 'Tyres', 'quantity' => 3, 'min_qty' => 2 ) );
cc_t( 'Stock item saved', is_int( $sid ) );
cc_t( 'Stock flagged low at 1.5x minimum', 'low' === CC_Fleet_Records::stock_level( CC_Fleet_Records::get( 'stock', $sid ) ) );
CC_Fleet_Records::adjust_stock( $sid, 1 ); CC_Fleet_Records::adjust_stock( $sid, 1 );
cc_t( 'Stock +1 twice -> 5, good', 5 === (int) CC_Fleet_Records::get( 'stock', $sid )->quantity && 'ok' === CC_Fleet_Records::stock_level( CC_Fleet_Records::get( 'stock', $sid ) ) );
for ( $k = 0; $k < 9; $k++ ) { CC_Fleet_Records::adjust_stock( $sid, -1 ); }
cc_t( 'Stock never goes below 0', 0 === (int) CC_Fleet_Records::get( 'stock', $sid )->quantity );
cc_t( 'Missing name is refused', is_wp_error( CC_Fleet_Records::save( 'stock', array( 'name' => '' ) ) ) );
$b12 = CC_Fleet_Bikes::find_by_cc_id( 'CC-12' );
$mid = CC_Fleet_Records::save( 'maintenance', array( 'bike_id' => $b12, 'type' => 'repair', 'status' => 'scheduled', 'work_date' => $d( 0 ), 'cost' => '450,50', 'description' => 'Spoke' ) );
cc_t( 'Workshop job saved with cost', is_int( $mid ) && 450.5 === (float) CC_Fleet_Records::get( 'maintenance', $mid )->cost );
CC_Fleet_Records::sync_bike_state( $mid );
cc_t( 'Due work puts the bike in the workshop', 'workshop' === CC_Fleet_Bikes::get( $b12, 'state' ) );
CC_Fleet_Records::save( 'maintenance', array( 'status' => 'completed' ), $mid ); CC_Fleet_Records::sync_bike_state( $mid );
cc_t( 'Done puts the bike back', 'active' === CC_Fleet_Bikes::get( $b12, 'state' ) );
$eid = CC_Fleet_Records::save( 'events', array( 'name' => 'Smoke Test Gravel Race', 'start_date' => $d( 55 ), 'end_date' => $d( 54 ), 'location' => 'Ceres', 'show_on_site' => 1 ) );
$ev  = CC_Fleet_Records::get( 'events', $eid );
cc_t( 'Event saved, end before start collapses to one day', is_int( $eid ) && null === $ev->end_date );
cc_t( 'Event status from dates: upcoming', 'upcoming' === CC_Fleet_Records::event_status( $ev ) );
$le = CC_Fleet_Loans::create( array( 'bike_id' => $b12, 'rider_id' => $rider, 'start_date' => $d( 53 ), 'end_date' => $d( 57 ), 'status' => 'approved', 'plan' => 'Smoke test gravel race!', 'source' => 'admin' ) );
cc_t( 'Loan auto-linked to the event from its plan', CC_Fleet_Records::auto_link_events() >= 1 && (int) CC_Fleet_Loans::get( $le )->event_id === $eid );
cc_t( 'Bikes attended counted from linked loans', 1 === CC_Fleet_Records::bikes_at( CC_Fleet_Records::get( 'events', $eid ) ) );
cc_t( '[cc_events] shows public events only, no rider names', false !== strpos( do_shortcode( '[cc_events limit="50"]' ), 'Smoke Test Gravel Race' ) && false === strpos( do_shortcode( '[cc_events]' ), 'Naledi' ) );
$nid = CC_Fleet_Records::save( 'notes', array( 'title' => 'Note', 'content' => "a\nb", 'status' => 'nonsense' ) );
cc_t( 'Note saved, unknown status falls back to active', 'active' === CC_Fleet_Records::get( 'notes', $nid )->status );
foreach ( array( 'maintenance', 'stock', 'events', 'notes' ) as $kk ) {
	ob_start();
	try { CC_Fleet_Records_Admin::page_list( $kk ); $html = ob_get_clean(); cc_t( "$kk list renders", strlen( $html ) > 300 ); }
	catch ( Throwable $e ) { ob_end_clean(); cc_t( "$kk list renders", false, $e->getMessage() ); }
}
$_GET = array( 'kind' => 'events', 'id' => $eid ); ob_start(); CC_Fleet_Records_Admin::page_edit(); $html = ob_get_clean(); $_GET = array();
cc_t( 'Event page lists the linked loan', false !== strpos( $html, 'Bikes at this event' ) && false !== strpos( $html, 'CC-12' ) );
CC_Fleet_Records::delete( 'events', $eid );
cc_t( 'Deleting an event unlinks its loans', 0 === (int) CC_Fleet_Loans::get( $le )->event_id );
CC_Fleet_Loans::delete( $le ); CC_Fleet_Records::delete( 'stock', $sid ); CC_Fleet_Records::delete( 'maintenance', $mid ); CC_Fleet_Records::delete( 'notes', $nid );

echo "Designed emails\n";
$mails = array();
$er = CC_Fleet_Riders::upsert( array( 'name' => 'Eve <script>x</script>', 'email' => 'eve@example.org' ) );
$el = CC_Fleet_Loans::create( array( 'bike_id' => CC_Fleet_Bikes::find_by_cc_id( 'CC-07' ), 'rider_id' => $er, 'start_date' => $d( 120 ), 'end_date' => $d( 122 ), 'status' => 'requested', 'source' => 'web', 'plan' => 'Plan & <b>bold</b>' ) );
CC_Fleet_Loans::set_status( $el, 'approved' );
$hdr = implode( '|', (array) ( end( $mails )['headers'] ?? array() ) );
$msg = end( $mails )['message'] ?? '';
cc_t( 'Emails go out as HTML', false !== strpos( $hdr, 'text/html' ) && false !== strpos( $msg, '<table' ) );
cc_t( 'Rider input is escaped in HTML emails', false === strpos( $msg, '<script>' ) && false === strpos( $msg, '<b>bold' ) );
cc_t( 'Logo is a PNG the email can load', false !== strpos( $msg, 'assets/email/logo.png' ) && file_exists( CC_FLEET_DIR . 'assets/email/logo.png' ) );
$txt = CC_Fleet_Emails::render_text( CC_Fleet_Emails::msg_received( array( 'name' => 'A', 'bike' => 'CC-07', 'dates' => 'x', 'plan' => '' ) ) );
cc_t( 'Plain-text copy has the details and no tags', false !== strpos( $txt, 'CC-07' ) && false === strpos( $txt, '<' ) );
CC_Fleet_Settings::update( array( 'email_style' => 'text' ) ); $mails = array();
CC_Fleet_Loans::set_status( $el, 'cancelled' );
cc_t( 'Plain-text setting sends text only', false !== strpos( implode( '|', (array) $mails[0]['headers'] ), 'text/plain' ) && false === strpos( $mails[0]['message'], '<table' ) );
CC_Fleet_Settings::update( array( 'email_style' => 'html' ) );
cc_t( 'Preview page renders all sixteen emails (eight + seven for the waiting list + crew welcome)', 16 === substr_count( CC_Fleet_Emails::preview_page(), '<iframe' ) );
CC_Fleet_Loans::delete( $el );

echo "Spec history + changed dates\n";
wp_set_current_user( 1 );
$sb = CC_Fleet_Bikes::find_by_cc_id( 'CC-09' );
$old_tyres = CC_Fleet_Bikes::get( $sb, 'tyres' );
$_POST = array( 'cc_bike_nonce' => wp_create_nonce( 'cc_bike_save' ), 'cc' => array( 'tyres' => 'Smoke Tyre 45', 'notes' => 'internal only', 'state' => 'active', 'show_on_site' => '1' ),
	'cc_change' => array( 'date' => $d( -2 ), 'type' => 'upgrade', 'by' => 'Nils', 'cost' => '650', 'note' => 'Worn through', 'workshop' => '1' ) );
CC_Fleet_Bikes::save( $sb, get_post( $sb ) );
global $wpdb;
$entry = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT message FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE object_type='bike' AND object_id=%d AND action='spec' ORDER BY id DESC LIMIT 1", $sb ) ), true );
cc_t( 'Spec change logged with date, old and new value', $entry && $d( -2 ) === $entry['date'] && array( 'tyres', $old_tyres, 'Smoke Tyre 45' ) === $entry['changes'][0] && 1 === count( $entry['changes'] ) );
$job = CC_Fleet_Records::query( 'maintenance', array( 'where' => array( 'bike_id' => $sb ), 'limit' => 1, 'orderby' => 'id' ) );
cc_t( 'Spec change added to the workshop log', $job && 650.0 === (float) $job[0]->cost && false !== strpos( $job[0]->description, 'Smoke Tyre 45' ) );
cc_t( 'Last upgraded follows the change date', $d( -2 ) === CC_Fleet_Bikes::get( $sb, 'last_upgraded' ) );
ob_start(); CC_Fleet_Bikes::box_history( get_post( $sb ) ); $hh = ob_get_clean();
cc_t( 'Bike history shows the change once', 1 === substr_count( $hh, 'Smoke Tyre 45' ) && false !== strpos( $hh, '<del>' ) );
$_POST['cc_change'] = array( 'note' => '', 'workshop' => '' ); $_POST['cc']['tyres'] = 'Smoke Tyre 45';
$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE action='spec'" );
CC_Fleet_Bikes::save( $sb, get_post( $sb ) );
cc_t( 'Saving without changes records nothing', $before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE action='spec'" ) );
$_POST = array();
CC_Fleet_Records::delete( 'maintenance', $job[0]->id ); CC_Fleet_Bikes::set( $sb, 'tyres', $old_tyres );
$mails = array();
$dl  = CC_Fleet_Loans::create( array( 'bike_id' => $sb, 'rider_id' => $rider, 'start_date' => $d( 130 ), 'end_date' => $d( 132 ), 'status' => 'approved', 'source' => 'admin' ) );
$blk = CC_Fleet_Loans::create( array( 'bike_id' => $sb, 'rider_id' => $rider, 'start_date' => $d( 135 ), 'end_date' => $d( 137 ), 'status' => 'approved', 'source' => 'admin' ) );
$old = CC_Fleet_Loans::get( $dl );
cc_t( 'Longer loan accepted when free', true === CC_Fleet_Loans::update( $dl, array( 'end_date' => $d( 134 ) ) ) );
cc_t( 'Longer loan refused when it runs into the next one', is_wp_error( CC_Fleet_Loans::update( $dl, array( 'end_date' => $d( 136 ) ) ) ) );
CC_Fleet_Emails::dates_changed( CC_Fleet_Loans::get( $dl ), $old, 'Two more days' );
cc_t( 'Rider emailed the new dates', 1 === count( $mails ) && false !== strpos( $mails[0]['subject'], 'New dates' ) && false !== strpos( $mails[0]['message'], 'More time.' ) );
CC_Fleet_Loans::delete( $dl ); CC_Fleet_Loans::delete( $blk );
$_GET = array( 'id' => 0 ); ob_start(); CC_Fleet_Admin::page_loan(); ob_end_clean(); $_GET = array();

echo "Long-term riders\n";
$ab  = CC_Fleet_Bikes::find_by_cc_id( 'CC-13' );
$fr  = CC_Fleet_Riders::upsert( array( 'name' => 'Frank Longterm', 'email' => 'frank.lt@example.org' ) );
CC_Fleet_Bikes::assign( $ab, $fr, $d( 10 ) );
cc_t( 'Free before the assignment starts', CC_Fleet_Loans::is_available( $ab, $d( 2 ), $d( 4 ) ) );
cc_t( 'Blocked from the start date, with no end', ! CC_Fleet_Loans::is_available( $ab, $d( 12 ), $d( 13 ) ) && ! CC_Fleet_Loans::is_available( $ab, $d( 400 ), $d( 401 ) ) );
$ae = CC_Fleet_Loans::create( array( 'bike_id' => $ab, 'rider_id' => $rider, 'start_date' => $d( 20 ), 'end_date' => $d( 21 ), 'status' => 'approved', 'source' => 'admin' ) );
cc_t( 'Booking refused with a clear reason', is_wp_error( $ae ) && 'cc_assigned' === $ae->get_error_code() );
cc_t( 'Not offered as a free alternative', ! in_array( $ab, CC_Fleet_Loans::available_bikes( $d( 20 ), $d( 21 ) ), true ) );
$rng = CC_Fleet_Loans::booked_ranges( $ab, $d( 0 ), $d( 60 ) );
cc_t( 'Public calendar shows it booked to the end of the range', (bool) array_filter( $rng, function ( $r ) use ( $d ) { return $d( 10 ) === $r['start'] && $d( 60 ) === $r['end']; } ) );
CC_Fleet_Bikes::assign( $ab, $fr, $d( 0 ) );
cc_t( 'Admin status says assigned, website says out', 'assigned' === CC_Fleet_Bikes::state_now( $ab ) && 'out' === CC_Fleet_Bikes::public_data( $ab )['state'] );
$_GET = array( 'start' => $d( -2 ) ); ob_start(); CC_Fleet_Admin::page_calendar(); $cal = ob_get_clean(); $_GET = array();
cc_t( 'Fleet calendar shows the long-term rider', false !== strpos( $cal, 'Frank Longterm · long-term' ) );
CC_Fleet_Bikes::assign( $ab, 0 );
cc_t( 'Removing the assignment frees the bike', CC_Fleet_Loans::is_available( $ab, $d( 20 ), $d( 21 ) ) && 'assigned' !== CC_Fleet_Bikes::state_now( $ab ) );
echo "Parts list re-import\n";
$c3 = CC_Fleet_Bikes::find_by_cc_id( 'CC-03' );
CC_Fleet_Bikes::set( $c3, 'saddle', 'Old saddle' );
$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE action='spec' AND object_id=" . (int) $c3 );
CC_Fleet_Import::bikes_csv( CC_FLEET_DIR . 'data/fleet-2027.csv' );
$last = json_decode( (string) $wpdb->get_var( 'SELECT message FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE action='spec' AND object_id=" . (int) $c3 . ' ORDER BY id DESC LIMIT 1' ), true );
cc_t( 'Re-import updates parts and records what changed', 'Fizik Terra Argo X3' === CC_Fleet_Bikes::get( $c3, 'saddle' ) && $before + 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE action='spec' AND object_id=" . (int) $c3 ) && in_array( array( 'saddle', 'Old saddle', 'Fizik Terra Argo X3' ), $last['changes'], true ) );
CC_Fleet_Import::bikes_csv( CC_FLEET_DIR . 'data/fleet-2027.csv' );
cc_t( 'Importing the same sheet again records nothing new', $before + 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE action='spec' AND object_id=" . (int) $c3 ) );

echo "Required fields, phone numbers, riders\n";
wp_set_current_user( 0 );
delete_transient( 'cc_rl_' . md5( ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt() ) );
$empty = $call( 'POST', '/requests', array( '_raw' => 1, 'bike' => 'CC-05', 'start' => $d( 140 ), 'end' => $d( 141 ), 'name' => 'Empty Fields', 'email' => 'ef@example.org', 'agree' => 1, 't' => time() - 30 ) );
$ef = $empty->get_data()['data']['fields'] ?? array();
cc_t( 'Every field is required (phone, Instagram, height, plan, consent)', 400 === $empty->get_status() && isset( $ef['phone'], $ef['instagram'], $ef['height'], $ef['plan'], $ef['consent'] ) );
$typo = $call( 'POST', '/requests', array( 'bike' => 'CC-05', 'start' => $d( 140 ), 'end' => $d( 141 ), 'name' => 'Typo', 'email' => 'typo@gmai.com', 'agree' => 1, 't' => time() - 30 ) );
cc_t( 'Email typo caught', 400 === $typo->get_status() && false !== strpos( $typo->get_data()['data']['fields']['email'] ?? '', 'gmail.com' ) );
$badp = $call( 'POST', '/requests', array( 'bike' => 'CC-05', 'start' => $d( 140 ), 'end' => $d( 141 ), 'name' => 'Short Phone', 'email' => 'sp@example.org', 'phone' => '082 123', 'agree' => 1, 't' => time() - 30 ) );
cc_t( 'Short phone number refused', 400 === $badp->get_status() && isset( $badp->get_data()['data']['fields']['phone'] ) );
delete_transient( 'cc_rl_' . md5( ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt() ) );
CC_Fleet_Settings::update( array( 'whatsapp_number' => '+27 82 999 0000' ) );
$good = $call( 'POST', '/requests', array( 'bike' => 'CC-05', 'start' => $d( 142 ), 'end' => $d( 143 ), 'name' => 'Lerato Intl', 'email' => 'lerato@example.org', 'phone' => '+49 1577 8944084', 'phone_cc' => '27', 'instagram' => '@lerato.rides', 'height' => 171, 'plan' => 'Overnighter', 'agree' => 1, 'consent' => 1, 't' => time() - 30 ) );
$lr = CC_Fleet_Riders::find_by_email( 'lerato@example.org' );
cc_t( 'Valid request saved with an international phone number', 201 === $good->get_status() && $lr && '+4915778944084' === $lr->phone );
cc_t( 'Response offers a WhatsApp link to the crew number', 0 === strpos( (string) ( $good->get_data()['whatsapp'] ?? '' ), 'https://wa.me/27829990000?text=' ) );
delete_transient( 'cc_rl_' . md5( ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt() ) );
$again = $call( 'POST', '/requests', array( 'bike' => 'CC-05', 'start' => $d( 150 ), 'end' => $d( 151 ), 'name' => 'Lerato Intl', 'email' => 'LERATO@example.org', 'phone' => '0049 1577 8944084', 'instagram' => 'none', 'height' => 171, 'plan' => 'Again', 'agree' => 1, 'consent' => 1, 't' => time() - 30 ) );
cc_t( 'Second request reuses the same rider record', 201 === $again->get_status() && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CC_Fleet_Riders::table() . ' WHERE LOWER(email) = %s', 'lerato@example.org' ) ) );
CC_Fleet_Settings::update( array( 'whatsapp_number' => '' ) );
wp_set_current_user( 1 );
$sl = CC_Fleet_Riders::create( array( 'name' => 'Test Backend', 'phone' => '082 555 0101', 'instagram' => '@testbackend' ) );
cc_t( 'Crew can add a rider without an email', is_int( $sl ) && '+27825550101' === CC_Fleet_Riders::get( $sl )->phone );
cc_t( 'New rider appears in the dropdown', isset( CC_Fleet_Riders::options()[ $sl ] ) && false !== strpos( CC_Fleet_Riders::options()[ $sl ], '+27 82 555 0101' ) );
$_GET = array(); ob_start(); CC_Fleet_Admin::page_loan(); $lf = ob_get_clean();
cc_t( 'Loan form has the rider dropdown with “New rider”', false !== strpos( $lf, 'value="new"' ) && false !== strpos( $lf, 'Test Backend' ) );
cc_t( 'WhatsApp link from a stored number', 'https://wa.me/27825550101' === CC_Fleet_Phone::wa_link( CC_Fleet_Riders::get( $sl )->phone ) );
$wpdb->update( CC_Fleet_Riders::table(), array( 'phone' => '082 637 3964' ), array( 'id' => $sl ) );
CC_Fleet_Install::normalize_phones();
cc_t( 'Old phone numbers rewritten as +27…', '+27826373964' === CC_Fleet_Riders::get( $sl )->phone );
$fh = do_shortcode( '[cc_bike_request bike="CC-12"]' );
cc_t( 'Form marks every field required and has a country code', 8 <= substr_count( $fh, 'aria-required="true"' ) && false !== strpos( $fh, 'name="phone_cc"' ) );

echo "Booking window, events, racing terms, raffle holds\n";
CC_Fleet_Settings::update( array( 'book_until' => '' ) );
$yr = (int) substr( CC_Fleet_Settings::today(), 0, 4 );
cc_t( 'Bookable until 31 Dec next year', ( $yr + 1 ) . '-12-31' === CC_Fleet_Loans::horizon() );
$far = CC_Fleet_Loans::validate_range( ( $yr + 1 ) . '-12-20', ( $yr + 1 ) . '-12-27', true );
$too = CC_Fleet_Loans::validate_range( ( $yr + 2 ) . '-01-02', ( $yr + 2 ) . '-01-04', true );
cc_t( 'December next year is requestable, the year after is not', true === $far && is_wp_error( $too ) );
$b03 = CC_Fleet_Bikes::find_by_cc_id( 'CC-03' );
$av  = $call( 'GET', '/bikes/CC-03/availability', array( 'from' => CC_Fleet_Settings::today(), 'to' => ( $yr + 1 ) . '-12-31' ) );
cc_t( 'Availability covers the whole window', 200 === $av->get_status() && ( $yr + 1 ) . '-12-31' === $av->get_data()['latest'] );
$ev = CC_Fleet_Records::save( 'events', array( 'name' => 'Test Gravel Race', 'type' => 'Gravel race', 'start_date' => $d( 200 ), 'end_date' => $d( 201 ), 'location' => 'Karoo' ) );
$fh = do_shortcode( '[cc_bike_request bike="CC-03"]' );
cc_t( 'Request form lists upcoming events and the racing terms', false !== strpos( $fh, 'Test Gravel Race' ) && false !== strpos( $fh, 'name="race_agree"' ) );
delete_transient( 'cc_rl_' . md5( ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt() ) );
$base = array( 'bike' => 'CC-03', 'start' => $d( 198 ), 'end' => $d( 202 ), 'name' => 'Race Rider', 'email' => 'racer@example.org', 'phone' => '+27820003333', 'instagram' => 'none', 'height' => 160, 'plan' => 'Racing it', 'agree' => 1, 'consent' => 1, 't' => time() - 30, 'event_id' => $ev );
$no  = $call( 'POST', '/requests', $base );
cc_t( 'Event request without racing terms is refused', 400 === $no->get_status() && isset( $no->get_data()['data']['fields']['race_agree'] ) );
$yes = $call( 'POST', '/requests', $base + array( 'race_agree' => 1 ) );
$rl  = $wpdb->get_row( "SELECT * FROM " . CC_Fleet_Loans::table() . " WHERE plan LIKE '[Test Gravel Race]%' ORDER BY id DESC LIMIT 1" );
cc_t( 'With the racing terms it goes through, linked to the event', 201 === $yes->get_status() && $rl && (int) $rl->event_id === (int) $ev && 1 === (int) $rl->race );
$cr = end( $mails );
cc_t( 'Crew email says racing terms accepted', $cr && false !== strpos( $cr['message'], 'racing terms accepted' ) );
$b07 = CC_Fleet_Bikes::find_by_cc_id( 'CC-07' );
$h   = CC_Fleet_Loans::create( array( 'bike_id' => $b07, 'rider_id' => 0, 'start_date' => $d( 198 ), 'end_date' => $d( 202 ), 'status' => 'held', 'source' => 'raffle', 'event_id' => $ev, 'race' => 1, 'plan' => 'Raffle: Test Gravel Race' ) );
cc_t( 'Raffle hold created', is_int( $h ) );
cc_t( 'Raffle bike is not offered for those dates', ! CC_Fleet_Loans::is_available( $b07, $d( 199 ), $d( 200 ) ) && ! in_array( $b07, CC_Fleet_Loans::available_bikes( $d( 199 ), $d( 200 ), '' ), true ) );
$kinds = wp_list_pluck( CC_Fleet_Loans::booked_ranges( $b07, $d( 190 ), $d( 210 ) ), 'kind' );
cc_t( 'Calendar shows it as a raffle bike', in_array( 'reserved', $kinds, true ) );
cc_t( 'Can’t approve a raffle bike before a winner is chosen', is_wp_error( CC_Fleet_Loans::set_status( $h, 'approved', false ) ) );
$winner = CC_Fleet_Riders::upsert( array( 'name' => 'Raffle Winner', 'email' => 'winner@example.org', 'phone' => '+27820004444' ) );
CC_Fleet_Loans::update( $h, array( 'rider_id' => $winner ), true );
cc_t( 'Winner gets the bike', true === CC_Fleet_Loans::set_status( $h, 'approved', false ) );
$h2 = CC_Fleet_Loans::create( array( 'bike_id' => $b07, 'rider_id' => 0, 'start_date' => $d( 230 ), 'end_date' => $d( 232 ), 'status' => 'held', 'source' => 'raffle', 'event_id' => $ev ) );
CC_Fleet_Loans::set_status( $h2, 'cancelled', false );
cc_t( 'Released raffle bike is bookable again', CC_Fleet_Loans::is_available( $b07, $d( 230 ), $d( 232 ) ) );
$_GET = array( 'page' => 'cc-fleet-record', 'kind' => 'events', 'id' => $ev ); ob_start(); CC_Fleet_Records_Admin::page_edit(); $ep = ob_get_clean();
cc_t( 'Event page has the raffle panel', false !== strpos( $ep, 'Hold for the raffle' ) && false !== strpos( $ep, 'Raffle bikes' ) );
if ( file_exists( '/home/claude/events2027/cc-events-2027.csv' ) ) { // Local copy of the 2027 events sheet, when there is one.
	$imp = CC_Fleet_Records::import_csv( 'events', CC_Fleet_Import::read_rows( '/home/claude/events2027/cc-events-2027.csv' ) );
	cc_t( '2027 events CSV imports', ! is_wp_error( $imp ) && ( $imp['created'] ?? 0 ) + ( $imp['skipped'] ?? 0 ) >= 8, wp_json_encode( $imp ) );
}
echo "\n" . $GLOBALS['cc_pass'] . ' passed, ' . $GLOBALS['cc_fail'] . " failed (incl. backend-only checks)\n";
