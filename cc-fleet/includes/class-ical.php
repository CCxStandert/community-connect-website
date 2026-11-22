<?php
defined( 'ABSPATH' ) || exit;

/**
 * Private iCal feed for Google Calendar / Apple Calendar.
 * URL: https://your-site/?cc_fleet_ics=KEY            (whole fleet)
 *      https://your-site/?cc_fleet_ics=KEY&bike=CC-12 (one bike)
 * The key lives in Fleet Manager → Settings; change it to revoke old links.
 */
class CC_Fleet_Ical {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 0 );
	}

	public static function url( $bike = '' ) {
		$args = array( 'cc_fleet_ics' => CC_Fleet_Settings::get( 'ical_key' ) );
		if ( $bike ) {
			$args['bike'] = $bike;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	public static function maybe_serve() {
		if ( empty( $_GET['cc_fleet_ics'] ) ) {
			return;
		}
		$key = (string) CC_Fleet_Settings::get( 'ical_key' );
		if ( ! $key || ! hash_equals( $key, sanitize_text_field( wp_unslash( $_GET['cc_fleet_ics'] ) ) ) ) {
			status_header( 403 );
			exit;
		}
		$bike_id = ! empty( $_GET['bike'] ) ? CC_Fleet_Bikes::find_by_cc_id( sanitize_text_field( wp_unslash( $_GET['bike'] ) ) ) : 0;
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="cc-fleet.ics"' );
		echo self::build( $bike_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- iCal text, escaped per RFC 5545 below.
		exit;
	}

	public static function build( $bike_id = 0 ) {
		$today = CC_Fleet_Settings::today();
		$loans = CC_Fleet_Loans::query( array(
			'bike_id' => $bike_id, 'status' => array( 'requested', 'approved', 'out', 'returned' ),
			'from' => CC_Fleet_Loans::add_days( $today, -60 ), 'to' => CC_Fleet_Loans::add_days( $today, 400 ),
			'limit' => 1000, 'order' => 'ASC',
		) );
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Community Connect//CC Fleet//EN', 'CALSCALE:GREGORIAN',
			'X-WR-CALNAME:' . self::esc( 'CC Fleet' . ( $bike_id ? ' · ' . CC_Fleet_Bikes::get( $bike_id, 'cc_id' ) : '' ) ) );
		foreach ( $loans as $l ) {
			$r      = CC_Fleet_Riders::get( $l->rider_id );
			$bike   = $l->bike_id ? CC_Fleet_Bikes::get( $l->bike_id, 'cc_id' ) : 'Size ' . $l->requested_size;
			$prefix = 'requested' === $l->status ? '? ' : ( CC_Fleet_Loans::is_overdue( $l ) ? '⚠ ' : '' );
			$end    = CC_Fleet_Loans::add_days( CC_Fleet_Loans::effective_end( $l ), 1 ); // DTEND is exclusive for all-day events.
			$lines  = array_merge( $lines, array(
				'BEGIN:VEVENT',
				'UID:cc-loan-' . $l->id . '@' . $host,
				'DTSTAMP:' . gmdate( 'Ymd\THis\Z', strtotime( $l->updated_at ) ),
				'DTSTART;VALUE=DATE:' . str_replace( '-', '', $l->start_date ),
				'DTEND;VALUE=DATE:' . str_replace( '-', '', $end ),
				'SUMMARY:' . self::esc( $prefix . $bike . ' → ' . ( $r->name ?? '?' ) ),
				'DESCRIPTION:' . self::esc( 'Status: ' . $l->status . "\n" . ( $l->plan ?: '' ) . "\n" . admin_url( 'admin.php?page=cc-fleet-loan&id=' . $l->id ) ),
				'STATUS:' . ( 'requested' === $l->status ? 'TENTATIVE' : 'CONFIRMED' ),
				'TRANSP:TRANSPARENT',
				'END:VEVENT',
			) );
		}
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( array( __CLASS__, 'fold' ), $lines ) ) . "\r\n";
	}

	private static function esc( $s ) {
		return str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\;', '\,', '\n', '\n' ), (string) $s );
	}

	/** Lines longer than 75 octets are folded (RFC 5545 §3.1). */
	private static function fold( $line ) {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out = '';
		while ( strlen( $line ) > 75 ) {
			$cut = 75;
			while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
				$cut--; // Never split a UTF-8 character.
			}
			$out .= substr( $line, 0, $cut ) . "\r\n ";
			$line = substr( $line, $cut );
		}
		return $out . $line;
	}
}
