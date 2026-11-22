<?php
/**
 * Our regular rides, put on the events calendar once (cc-fleet 0.16.0).
 * After that they are ordinary events: edit, cancel or delete them under Fleet Manager → Events.
 *
 * @package CC_Fleet
 */

defined( 'ABSPATH' ) || exit;

class CC_Fleet_Rides {

	const OPTION = 'cc_fleet_rides_seeded';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_seed' ), 30 );
	}

	public static function maybe_seed() {
		if ( get_option( self::OPTION ) || ! class_exists( 'CC_Fleet_Records' ) ) {
			return;
		}
		update_option( self::OPTION, current_time( 'mysql' ), false );
		self::seed();
	}

	/** The rides, as event rows. Dates for FAR Inverted count back from the Cycle Tour (Sun 14 Mar 2027). */
	public static function rides() {
		$far_about = 'Cape Town’s fastest group ride: segment-based racing up and down some of the city’s most contested segments, building up to the Cape Town Cycle Tour. It’s not a race. But it is. Advanced road riders only, drop ride.';
		$rows      = array(
			array(
				'name' => 'Gravel group ride', 'type' => array( 'group', 'social', 'gravel' ), 'start_date' => '2026-12-01',
				'schedule' => 'Monthly · a weekend day', 'location' => 'Cape Town',
				'description' => 'Beginner friendly and open to anyone: bring your own bike or borrow one of ours. Mixed terrain, starting on gravel, so a gravel bike with decent tyres is best. Cape Town is windy and the route is exposed, so we announce the day in the week before, once we’ve seen the forecast.',
				'link' => 'https://www.instagram.com/p/DTz3bJBDWDI/',
			),
			array(
				'name' => 'The Weekend Ender', 'type' => array( 'group', 'social', 'gravel', 'mtb' ), 'start_date' => '2026-11-27',
				'schedule' => 'Every Friday · sunrise', 'location' => 'The Blockhouse, Cape Town',
				'description' => 'A social hill climb up to the Blockhouse to watch the sun come up from one of the best spots on the mountain. Gravel or mountain bike.',
				'link' => 'https://www.instagram.com/p/Dfr72VQjeEy/',
			),
			array(
				'name' => 'Social weekends 2027', 'type' => array( 'getaway', 'social' ), 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
				'schedule' => '2027 · more soon', 'location' => 'Away from Cape Town',
				'description' => 'Weekends away: we travel somewhere new to ride, or go to an event together. New formats for 2027, more information coming soon.',
				'link' => 'https://www.instagram.com/cc_x_standert/',
			),
		);
		$far = array( '2026-12-29', '2027-01-12', '2027-01-26', '2027-02-09', '2027-02-23', '2027-03-09' );
		foreach ( $far as $i => $d ) {
			$last   = count( $far ) - 1 === $i;
			$rows[] = array(
				'name' => 'FAR Inverted #' . ( $i + 1 ) . ( $last ? ' · Cycle Tour week' : '' ), 'type' => array( 'group', 'road' ), 'start_date' => $d,
				'location' => 'Cape Town', 'description' => $far_about . ( $last ? ' The last one, on the Tuesday morning of Cycle Tour week.' : '' ),
				'link' => 'https://www.instagram.com/p/DTf0fqLiLVq/',
			);
		}
		return $rows;
	}

	/** Adds the rides that aren't on the calendar yet (same name and date). Returns how many were added. */
	public static function seed() {
		global $wpdb;
		$t     = CC_Fleet_Records::table( 'events' );
		$added = 0;
		foreach ( self::rides() as $r ) {
			$have = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE name = %s AND start_date = %s", $r['name'], $r['start_date'] ) ); // phpcs:ignore
			if ( $have ) {
				continue;
			}
			$r['show_on_site'] = 1;
			$res               = CC_Fleet_Records::save( 'events', $r );
			if ( ! is_wp_error( $res ) ) {
				$added++;
			}
		}
		if ( $added ) {
			CC_Fleet_Install::log( 'event', 0, 'rides_added', 'Added our regular rides to the calendar (' . $added . ').' );
		}
		return $added;
	}
}
