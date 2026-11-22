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

	/** FAR Inverted: weekly on Tuesdays from 19 Jan 2027, the last one in Cycle Tour week (changed in 0.16.1). */
	const FAR_DATES = array( '2027-01-19', '2027-01-26', '2027-02-02', '2027-02-09', '2027-02-16', '2027-02-23', '2027-03-02', '2027-03-09' );
	const FAR_OLD   = array( '2026-12-29', '2027-01-12' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_seed' ), 30 );
		add_action( 'init', array( __CLASS__, 'maybe_far_weekly' ), 31 );
		add_action( 'init', array( __CLASS__, 'maybe_v2' ), 32 );
	}

	/**
	 * 0.16.2: FAR Inverted is a race (racing terms apply). The Gravel group ride, the Weekend Ender
	 * and Social weekends 2027 leave the calendar: their dates come from the Strava club instead.
	 */
	public static function maybe_v2() {
		if ( get_option( 'cc_fleet_rides_v2' ) || ! get_option( self::OPTION ) ) {
			return;
		}
		update_option( 'cc_fleet_rides_v2', current_time( 'mysql' ), false );
		global $wpdb;
		$t = CC_Fleet_Records::table( 'events' );
		$gone = $wpdb->query( "DELETE FROM $t WHERE schedule <> '' AND source = '' AND name IN ('Gravel group ride','The Weekend Ender','Social weekends 2027')" ); // phpcs:ignore
		foreach ( (array) $wpdb->get_results( "SELECT id, name, type FROM $t WHERE name LIKE 'FAR Inverted%'" ) as $r ) { // phpcs:ignore
			$cats = CC_Fleet_Records::event_cats( $r );
			if ( ! in_array( 'race', $cats, true ) ) {
				$cats[] = 'race';
				$wpdb->update( $t, array( 'type' => implode( ',', $cats ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $r->id ) );
			}
		}
		CC_Fleet_Install::log( 'event', 0, 'rides_v2', 'FAR Inverted marked as a race; ' . (int) $gone . ' regular rides removed (they come from Strava).' );
	}

	/**
	 * 0.16.1: FAR Inverted goes weekly. Cancels the 29 Dec and 12 Jan rides, renumbers the rest by date
	 * and adds the missing Tuesdays. Runs once.
	 */
	public static function maybe_far_weekly() {
		if ( get_option( 'cc_fleet_far_weekly' ) || ! get_option( self::OPTION ) ) {
			return;
		}
		update_option( 'cc_fleet_far_weekly', current_time( 'mysql' ), false );
		self::far_weekly();
	}

	public static function far_weekly() {
		global $wpdb;
		$t    = CC_Fleet_Records::table( 'events' );
		$rows = $wpdb->get_results( "SELECT * FROM $t WHERE name LIKE 'FAR Inverted%'" ); // phpcs:ignore
		$by   = array();
		foreach ( $rows as $r ) {
			if ( in_array( $r->start_date, self::FAR_OLD, true ) ) {
				if ( ! (int) $r->cancelled ) {
					CC_Fleet_Records::save( 'events', array( 'cancelled' => 1, 'show_on_site' => (int) $r->show_on_site ), (int) $r->id );
				}
				continue;
			}
			$by[ $r->start_date ] = $r;
		}
		$n     = count( self::FAR_DATES );
		foreach ( self::FAR_DATES as $i => $d ) {
			$last = $n - 1 === $i;
			$name = 'FAR Inverted #' . ( $i + 1 ) . ( $last ? ' · Cycle Tour week' : '' );
			$row  = array(
				'name' => $name, 'type' => array( 'group', 'race', 'road' ), 'start_date' => $d, 'location' => 'Cape Town', 'show_on_site' => 1,
				'description' => self::far_about() . ( $last ? ' The last one, on the Tuesday morning of Cycle Tour week.' : '' ),
				'link' => 'https://www.instagram.com/p/DTf0fqLiLVq/',
			);
			if ( isset( $by[ $d ] ) ) {
				CC_Fleet_Records::save( 'events', array( 'name' => $name, 'show_on_site' => (int) $by[ $d ]->show_on_site ), (int) $by[ $d ]->id );
			} else {
				CC_Fleet_Records::save( 'events', $row );
			}
		}
		CC_Fleet_Install::log( 'event', 0, 'far_weekly', 'FAR Inverted is weekly from 19 Jan 2027: 29 Dec and 12 Jan cancelled.' );
	}

	public static function far_about() {
		return 'Cape Town’s fastest group ride: segment-based racing up and down some of the city’s most contested segments, weekly on Tuesdays building up to the Cape Town Cycle Tour. It’s not a race. But it is. Advanced road riders only, drop ride.';
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
		$far_about = self::far_about();
		// The Gravel group ride, the Weekend Ender and social weekends come from the Strava club (Fleet Manager → Connections).
		$rows      = array();
		$far = self::FAR_DATES;
		foreach ( $far as $i => $d ) {
			$last   = count( $far ) - 1 === $i;
			$rows[] = array(
				'name' => 'FAR Inverted #' . ( $i + 1 ) . ( $last ? ' · Cycle Tour week' : '' ), 'type' => array( 'group', 'race', 'road' ), 'start_date' => $d,
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
