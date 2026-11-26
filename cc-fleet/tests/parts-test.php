<?php
/**
 * Wheels & tyres, drivetrain care and tyre pressure. THROWAWAY site only:
 *   wp eval-file wp-content/plugins/cc-fleet/tests/parts-test.php
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$GLOBALS['pt_pass'] = 0; $GLOBALS['pt_fail'] = 0;
function pt( $name, $cond, $extra = '' ) {
	if ( $cond ) { $GLOBALS['pt_pass']++; echo "  ✓ $name\n"; } else { $GLOBALS['pt_fail']++; echo "  ✗ $name" . ( $extra ? " — $extra" : '' ) . "\n"; }
}
wp_set_current_user( 1 );
global $wpdb;

// Two fresh bikes with parts.
$mk = function ( $cc, $size ) {
	$id = wp_insert_post( array( 'post_type' => 'cc_bike', 'post_status' => 'publish', 'post_title' => "$cc Test $size" ) );
	CC_Fleet_Bikes::set( $id, 'cc_id', $cc );
	CC_Fleet_Bikes::set( $id, 'size', $size );
	CC_Fleet_Bikes::set( $id, 'state', 'active' );
	return $id;
};
$a = $mk( 'ZZ-01', '54' );
$b = $mk( 'ZZ-02', '50' );
$wa = CC_Fleet_Parts::save( array( 'type' => 'wheelset', 'name' => 'Test Wheels A', 'size' => '700c', 'detail' => 'XDR' ) );
$wb = CC_Fleet_Parts::save( array( 'type' => 'wheelset', 'name' => 'Test Wheels B', 'size' => '700c', 'detail' => 'XDR' ) );
$ta = CC_Fleet_Parts::save( array( 'type' => 'tyres', 'name' => 'Test Tyres A', 'size' => '45 mm' ) );
pt( 'Codes are given automatically', preg_match( '/^WS-\d\d$/', CC_Fleet_Parts::get( $wa )->code ) && preg_match( '/^TY-\d\d$/', CC_Fleet_Parts::get( $ta )->code ) );
pt( 'Tyre width is stored as a number', '45' === CC_Fleet_Parts::get( $ta )->size );
CC_Fleet_Parts::assign( $wa, $a );
CC_Fleet_Parts::assign( $wb, $b );
CC_Fleet_Parts::assign( $ta, $a );
pt( 'Fitting a part fills the bike spec', 'Test Wheels A' === CC_Fleet_Bikes::get( $a, 'wheelset' ) && '700c' === CC_Fleet_Bikes::get( $a, 'wheel_size' ) && '45' === CC_Fleet_Bikes::get( $a, 'tyre_size' ) );
pt( 'First fit from the shelf leaves the bike active', 'active' === CC_Fleet_Bikes::get( $a, 'state' ) );

// Damage needs a note.
$e = CC_Fleet_Parts::save( array( 'damaged' => 1, 'damage_note' => '' ), $wb );
pt( 'Damaged without a note is refused', is_wp_error( $e ) );
CC_Fleet_Parts::save( array( 'damaged' => 1, 'damage_note' => 'Rear rim cracked' ), $wb );
pt( 'Damage is stored with its note', 1 === (int) CC_Fleet_Parts::get( $wb )->damaged && 'Rear rim cracked' === CC_Fleet_Parts::get( $wb )->damage_note );
pt( 'Damaged part shows on the damaged list', in_array( (int) $wb, array_map( 'intval', wp_list_pluck( CC_Fleet_Parts::damaged(), 'id' ) ), true ) );
pt( 'Label warns about damage', false !== strpos( CC_Fleet_Parts::label( CC_Fleet_Parts::get( $wb ) ), 'damaged' ) );

// Move B's wheels to A through the bike editor save.
$_POST = array(
	'cc_bike_nonce' => wp_create_nonce( 'cc_bike_save' ),
	'cc'            => array( 'state' => 'active', 'show_on_site' => '1' ),
	'cc_part'       => array( 'wheelset' => (string) $wb, 'tyres' => (string) $ta ),
	'cc_change'     => array( 'date' => CC_Fleet_Settings::today(), 'type' => 'repair', 'note' => 'Swap test', 'workshop' => '1' ),
);
CC_Fleet_Bikes::save( $a, get_post( $a ) );
pt( 'Part is on the new bike', (int) CC_Fleet_Parts::get( $wb )->bike_id === (int) $a );
pt( 'Only one wheelset per bike', 1 === count( array_filter( CC_Fleet_Parts::all( 'wheelset' ), function ( $p ) use ( $a ) { return (int) $p->bike_id === (int) $a; } ) ) );
pt( 'The replaced wheelset goes to the shelf', 0 === (int) CC_Fleet_Parts::get( $wa )->bike_id );
pt( 'New bike spec follows the part', 'Test Wheels B' === CC_Fleet_Bikes::get( $a, 'wheelset' ) );
pt( 'Bike that lost its wheels goes to the workshop', 'workshop' === CC_Fleet_Bikes::get( $b, 'state' ) );
pt( 'Its wheelset field is emptied', '' === (string) CC_Fleet_Bikes::get( $b, 'wheelset' ) );
$job = CC_Fleet_Records::query( 'maintenance', array( 'where' => array( 'bike_id' => $b, 'status' => 'scheduled' ), 'limit' => 5 ) );
pt( 'A scheduled job asks for a replacement', $job && false !== strpos( $job[0]->description, 'needs a wheelset' ) && false !== strpos( $job[0]->description, '[wheels]' ), $job ? $job[0]->description : 'no job' );
pt( 'The bike that got damaged wheels goes to the workshop too', 'workshop' === CC_Fleet_Bikes::get( $a, 'state' ) );
$hist = $wpdb->get_var( $wpdb->prepare( 'SELECT message FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE object_type='bike' AND object_id=%d AND action='spec' ORDER BY id DESC LIMIT 1", $b ) );
pt( 'Both bikes get a history entry', $hist && false !== strpos( $hist, 'moved to ZZ-01' ) && $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE object_type='bike' AND object_id=%d AND action='spec'", $a ) ) > 0 );
pt( 'Part keeps its own history', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE object_type='part' AND object_id=%d AND action='moved'", $wb ) ) >= 2 );
// Finishing the job puts the bike back.
CC_Fleet_Records::save( 'maintenance', array( 'status' => 'completed' ), $job[0]->id );
CC_Fleet_Records::sync_bike_state( $job[0]->id );
pt( 'Completing the job makes the bike active again', 'active' === CC_Fleet_Bikes::get( $b, 'state' ) );
// Saving the same part again changes nothing.
$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE object_type='part'" );
CC_Fleet_Bikes::save( $a, get_post( $a ) );
pt( 'Re-saving the bike does not move anything', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE object_type='part'" ) === $before );
// Repairing clears the flag.
CC_Fleet_Parts::save( array( 'damaged' => 0 ), $wb );
pt( 'Unticking damage clears it', 0 === (int) CC_Fleet_Parts::get( $wb )->damaged && '' === (string) CC_Fleet_Parts::get( $wb )->damage_note );
// Retiring takes it off the bike.
CC_Fleet_Parts::save( array( 'status' => 'retired' ), $ta );
pt( 'Retired tyres come off the bike', 0 === (int) CC_Fleet_Parts::get( $ta )->bike_id && '' === (string) CC_Fleet_Bikes::get( $a, 'tyres' ) );

// Tyres travel with their wheels: the KS50 / KS54 case.
$c  = $mk( 'ZZ-03', '50' );
$d  = $mk( 'ZZ-04', '54' );
$wc = CC_Fleet_Parts::save( array( 'type' => 'wheelset', 'name' => 'Test Wheels C', 'size' => '700c', 'detail' => 'XDR' ) );
$tc = CC_Fleet_Parts::save( array( 'type' => 'tyres', 'name' => 'Test Tyres C', 'size' => '45' ) );
$wd = CC_Fleet_Parts::save( array( 'type' => 'wheelset', 'name' => 'Test Wheels D', 'size' => '700c', 'detail' => 'XDR' ) );
$td = CC_Fleet_Parts::save( array( 'type' => 'tyres', 'name' => 'Test Tyres D', 'size' => '40' ) );
CC_Fleet_Parts::assign( $wc, $c ); CC_Fleet_Parts::assign( $tc, $c );
CC_Fleet_Parts::assign( $wd, $d ); CC_Fleet_Parts::assign( $td, $d );
pt( 'Tyres fitted to a bike sit on its wheelset', (int) $wc === (int) CC_Fleet_Parts::get( $tc )->wheelset_id && (int) $tc === (int) CC_Fleet_Parts::tyres_on( $wc )->id );
pt( 'Wheelset label shows its tyres', false !== strpos( CC_Fleet_Parts::label( CC_Fleet_Parts::get( $wd ) ), 'Test Tyres D' ) );
CC_Fleet_Parts::save( array( 'damaged' => 1, 'damage_note' => 'Rear rim cracked' ), $wc );
CC_Fleet_Bikes::set( $c, 'state', 'workshop' );
CC_Fleet_Parts::$messages = array();
// Editor on ZZ-03: pick ZZ-04's wheels, "Swap" chosen, tyres untouched (as if JS were off).
$_POST = array(
	'cc_bike_nonce' => wp_create_nonce( 'cc_bike_save' ),
	'cc'            => array( 'state' => 'workshop', 'show_on_site' => '1' ),
	'cc_part'       => array( 'wheelset' => (string) $wd, 'tyres' => (string) $tc ),
	'cc_part_was'   => array( 'wheelset' => (string) $wc, 'tyres' => (string) $tc ),
	'cc_part_swap'  => '1',
	'cc_change'     => array( 'date' => CC_Fleet_Settings::today(), 'type' => 'repair', 'note' => 'Needed for a rider', 'workshop' => '0' ),
);
CC_Fleet_Bikes::save( $c, get_post( $c ) );
pt( 'Swap: ZZ-03 gets ZZ-04’s wheels and tyres', (int) $c === (int) CC_Fleet_Parts::get( $wd )->bike_id && (int) $c === (int) CC_Fleet_Parts::get( $td )->bike_id );
pt( 'Swap: ZZ-04 gets ZZ-03’s wheels and tyres', (int) $d === (int) CC_Fleet_Parts::get( $wc )->bike_id && (int) $d === (int) CC_Fleet_Parts::get( $tc )->bike_id );
pt( 'Spec fields follow on both bikes', 'Test Wheels D' === CC_Fleet_Bikes::get( $c, 'wheelset' ) && '40' === CC_Fleet_Bikes::get( $c, 'tyre_size' ) && 'Test Tyres C' === CC_Fleet_Bikes::get( $d, 'tyres' ) && '45' === CC_Fleet_Bikes::get( $d, 'tyre_size' ) );
pt( 'Good wheels make ZZ-03 active again', 'active' === CC_Fleet_Bikes::get( $c, 'state' ) );
pt( 'Damaged wheels send ZZ-04 to the workshop', 'workshop' === CC_Fleet_Bikes::get( $d, 'state' ) );
$jd = CC_Fleet_Records::query( 'maintenance', array( 'where' => array( 'bike_id' => $d, 'status' => 'scheduled' ), 'limit' => 5 ) );
pt( 'ZZ-04 gets one job naming the damage', 1 === count( $jd ) && false !== strpos( $jd[0]->description, 'Rear rim cracked' ), wp_json_encode( wp_list_pluck( $jd, 'description' ) ) );
$hd = $wpdb->get_var( $wpdb->prepare( 'SELECT message FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE object_type='bike' AND object_id=%d AND action='spec' ORDER BY id DESC LIMIT 1", $d ) );
pt( 'ZZ-04 history says it was swapped with ZZ-03', $hd && false !== strpos( $hd, 'swapped with ZZ-03' ) );
$notice = get_transient( 'cc_fleet_wheels_notice_' . get_current_user_id() );
pt( 'The editor gets a notice about ZZ-04', $notice && false !== strpos( implode( ' ', $notice ), 'ZZ-04 is now' ), wp_json_encode( $notice ) );
delete_transient( 'cc_fleet_wheels_notice_' . get_current_user_id() );
// Wheels repaired, swap back in one step (the list page's form).
CC_Fleet_Parts::save( array( 'damaged' => 0 ), $wc );
$r = CC_Fleet_Parts::swap( $d, $c );
pt( 'Swap back: each bike has its own wheels and tyres again', (int) $d === (int) CC_Fleet_Parts::get( $wd )->bike_id && (int) $d === (int) CC_Fleet_Parts::get( $td )->bike_id && (int) $c === (int) CC_Fleet_Parts::get( $wc )->bike_id && (int) $c === (int) CC_Fleet_Parts::get( $tc )->bike_id );
pt( 'The wheels job is closed and ZZ-04 is active', 'active' === CC_Fleet_Bikes::get( $d, 'state' ) && ! CC_Fleet_Records::query( 'maintenance', array( 'where' => array( 'bike_id' => $d, 'status' => 'scheduled' ), 'limit' => 5 ) ) );
pt( 'Swapping a bike with itself is refused', is_wp_error( CC_Fleet_Parts::swap( $c, $c ) ) );
CC_Fleet_Parts::$messages = array();
// "Just take them": ZZ-04 takes ZZ-03's wheels; tyres come along; ZZ-03 is left with nothing.
$_POST = array(
	'cc_bike_nonce' => wp_create_nonce( 'cc_bike_save' ),
	'cc'            => array( 'state' => 'active', 'show_on_site' => '1' ),
	'cc_part'       => array( 'wheelset' => (string) $wc, 'tyres' => (string) $tc ),
	'cc_part_was'   => array( 'wheelset' => (string) $wd, 'tyres' => (string) $td ),
	'cc_part_swap'  => '0',
	'cc_change'     => array( 'date' => CC_Fleet_Settings::today(), 'type' => 'repair', 'note' => '', 'workshop' => '0' ),
);
CC_Fleet_Bikes::save( $d, get_post( $d ) );
pt( 'Take: wheels and their tyres move together', (int) $d === (int) CC_Fleet_Parts::get( $wc )->bike_id && (int) $d === (int) CC_Fleet_Parts::get( $tc )->bike_id );
pt( 'Take: the old wheels go to the shelf with their tyres', 0 === (int) CC_Fleet_Parts::get( $wd )->bike_id && 0 === (int) CC_Fleet_Parts::get( $td )->bike_id && (int) $wd === (int) CC_Fleet_Parts::get( $td )->wheelset_id );
pt( 'Take: ZZ-03 goes to the workshop needing wheels and tyres', 'workshop' === CC_Fleet_Bikes::get( $c, 'state' ) && '' === (string) CC_Fleet_Bikes::get( $c, 'tyres' ) );
// Fitting the spare wheels from the shelf brings their tyres and finishes ZZ-03's job.
CC_Fleet_Parts::assign( $wd, $c );
pt( 'Spare wheels arrive with their tyres', (int) $c === (int) CC_Fleet_Parts::get( $td )->bike_id && 'Test Tyres D' === CC_Fleet_Bikes::get( $c, 'tyres' ) );
pt( 'ZZ-03 is active again once it has wheels', 'active' === CC_Fleet_Bikes::get( $c, 'state' ) );
// Upgrade: loose tyres on a bike get mounted.
$wpdb->update( CC_Fleet_Parts::table(), array( 'wheelset_id' => 0 ), array( 'id' => $td ) );
CC_Fleet_Parts::mount_existing();
pt( 'Upgrade mounts tyres on their bike’s wheels', (int) $wd === (int) CC_Fleet_Parts::get( $td )->wheelset_id );
foreach ( array( $c, $d ) as $bk ) {
	foreach ( CC_Fleet_Records::query( 'maintenance', array( 'where' => array( 'bike_id' => $bk ), 'limit' => 50 ) ) as $m ) { CC_Fleet_Records::delete( 'maintenance', $m->id ); }
	wp_delete_post( $bk, true );
}

// Drivetrain care.
CC_Fleet_Bikes::set( $a, 'dt_checked', '' );
pt( 'A bike never checked is due', (bool) CC_Fleet_Care::due( $a ) );
CC_Fleet_Care::save( $a, array( 'date' => CC_Fleet_Settings::today(), 'clean' => 1, 'lube' => 1, 'check' => 1, 'wear' => '0.25' ) );
pt( 'Clean + lube + check clears what is due', array() === CC_Fleet_Care::due( $a ), implode( ', ', CC_Fleet_Care::due( $a ) ) );
CC_Fleet_Care::save( $a, array( 'wear' => '0.5' ) );
pt( 'Chain wear 0.5 % asks for a new chain', false !== strpos( implode( ' ', CC_Fleet_Care::due( $a ) ), 'Replace chain' ) );
$chain = CC_Fleet_Records::save( 'stock', array( 'name' => 'Test chain 12s', 'category' => 'Drivetrain', 'quantity' => 3 ) );
CC_Fleet_Care::save( $a, array( 'replace' => 1, 'stock' => $chain ) );
pt( 'Replacing the chain from stock takes one out', 2 === (int) CC_Fleet_Records::get( 'stock', $chain )->quantity );
pt( 'A new chain resets wear to 0', '0' === (string) CC_Fleet_Bikes::get( $a, 'dt_wear' ) && array() === CC_Fleet_Care::due( $a ) );
CC_Fleet_Bikes::set( $a, 'dt_returned', CC_Fleet_Loans::add_days( CC_Fleet_Settings::today(), 1 ) );
pt( 'A returned loan makes a check due', false !== strpos( implode( ' ', CC_Fleet_Care::due( $a ) ), 'after loan' ) );
CC_Fleet_Bikes::set( $a, 'dt_lubed', CC_Fleet_Loans::add_days( CC_Fleet_Settings::today(), -30 ) );
pt( 'Lube is due after 21 days', false !== strpos( implode( ' ', CC_Fleet_Care::due( $a ) ), 'Lube' ) );
pt( 'Care shows in the bike history', count( CC_Fleet_Care::history_items( $a ) ) >= 3 );

// Tyre pressure.
$p75 = CC_Fleet_Pressure::recommend( 75, 45 );
$p60 = CC_Fleet_Pressure::recommend( 60, 45 );
$p90 = CC_Fleet_Pressure::recommend( 90, 45 );
$w40 = CC_Fleet_Pressure::recommend( 75, 40 );
pt( '75 kg on 45 mm lands in the usual gravel range', $p75 && $p75['rear_psi'] >= 30 && $p75['rear_psi'] <= 38, wp_json_encode( $p75 ) );
pt( 'Heavier rider → more pressure', $p90['rear_psi'] > $p75['rear_psi'] && $p75['rear_psi'] > $p60['rear_psi'] );
pt( 'Narrower tyre → more pressure', $w40['rear_psi'] > $p75['rear_psi'] );
pt( 'Front is a little lower than rear', $p75['front_psi'] < $p75['rear_psi'] );
pt( 'Bar matches psi', abs( $p75['rear_bar'] - $p75['rear_psi'] / 14.5 ) < 0.1 );
pt( 'Nonsense weight gives no advice', null === CC_Fleet_Pressure::recommend( 5, 45 ) && null === CC_Fleet_Pressure::recommend( 75, 0 ) );
// Through a loan.
$rid = CC_Fleet_Riders::create( array( 'name' => 'Pressure Test', 'email' => 'pressure@example.org', 'weight_kg' => 75 ) );
CC_Fleet_Parts::assign( CC_Fleet_Parts::save( array( 'type' => 'tyres', 'name' => 'Test Tyres B', 'size' => '45' ) ), $a );
$loan = (object) array( 'rider_id' => $rid, 'bike_id' => $a );
pt( 'Rider weight is stored', 75 === (int) CC_Fleet_Riders::get( $rid )->weight_kg );
pt( 'Loan gets a pressure line', false !== strpos( CC_Fleet_Pressure::line_for_loan( $loan ), 'Rear ' . $p75['rear_psi'] . ' psi' ), CC_Fleet_Pressure::line_for_loan( $loan ) );
$msg = CC_Fleet_Emails::msg_confirmed( array( 'name' => 'P', 'bike' => 'ZZ-01', 'dates' => 'x', 'from' => '', 'from_first' => '', 'return' => 'x', 'photo' => '', 'calendar' => 'x', 'bike_url' => 'x', 'agreement' => '', 'handoff_notes' => '', 'pressure' => CC_Fleet_Pressure::line_for_loan( $loan ), 'pressure_note' => CC_Fleet_Pressure::advice( $loan ) ) );
pt( 'Confirmation email carries the pressure', false !== strpos( wp_json_encode( $msg['blocks'] ), 'Tyre pressure for you' ) );
// REST form accepts and validates weight.
$req = new WP_REST_Request( 'POST', '/cc/v1/requests' );
$req->set_body_params( array( 'weight' => 7 ) );
$res = rest_do_request( $req );
$body = wp_json_encode( $res->get_data() );
pt( 'Form rejects an impossible weight', false !== strpos( $body, 'weight' ), substr( $body, 0, 200 ) );

// Clean up.
foreach ( array( $a, $b ) as $bk ) { wp_delete_post( $bk, true ); }
$wpdb->query( 'DELETE FROM ' . CC_Fleet_Parts::table() . " WHERE name LIKE 'Test %'" );
CC_Fleet_Records::delete( 'stock', $chain );
CC_Fleet_Riders::delete( $rid );
echo "\n" . $GLOBALS['pt_pass'] . ' passed, ' . $GLOBALS['pt_fail'] . " failed\n";
