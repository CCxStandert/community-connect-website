<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings, stored as one option.
 */
class CC_Fleet_Settings {

	const OPTION = 'cc_fleet_settings';

	public static function defaults() {
		return array(
			'notify_emails'        => get_option( 'admin_email' ),
			'from_name'            => 'Community Connect',
			'buffer_days'          => 1,       // Free days after each loan (washing, small fixes, late returns).
			'same_day_handoff'     => 0,       // Only used with 0 buffer days: a loan may start on the day the previous one ends.
			'min_notice_days'      => 1,       // Requests must start at least this many days from today.
			'max_loan_days'        => 45,
			'max_advance_days'     => 365,     // Not used since 0.6: see book_until.
			'book_until'           => '',      // Last bookable day (Y-m-d). Empty: 31 Dec of next year.
			'requests_hold_dates'  => 0,       // If 1, pending requests also block the public calendar.
			'reminder_days_before' => 1,
			'ical_key'             => '',
			'rate_limit_per_hour'  => 5,
			'size_heights'         => "48:152-160\n50:158-166\n52:164-172\n54:170-178\n56:176-184\n58:182-191\n60:188-196",
			'agreement_url'        => '',
			'privacy_url'          => '',
			'race_terms'           => 'Racing is harder on a bike than riding. Any damage that happens while racing, crashes included, is yours to cover: we get it fixed at Woodstock Cycleworks and share the invoice, no markup.',
			'handoff_notes'        => '',      // Shown in the confirmation email, e.g. the pedals rule.
			'dropoff_place'        => '',      // Where bikes go back when no next rider is waiting.
			'instagram_handle'     => 'cc_x_standert',
			'whatsapp_number'      => '',      // The crew's WhatsApp, +27…; riders confirm their number by messaging it.
			'email_style'          => 'html',  // html or text.
			'review_rider_emails'  => 1,       // Approve/decline/cancel/return/date emails open for editing before they go out.
			'delete_data_on_uninstall' => 0,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function update( array $values ) {
		$all = self::all();
		foreach ( $values as $k => $v ) {
			if ( array_key_exists( $k, $all ) ) {
				$all[ $k ] = $v;
			}
		}
		update_option( self::OPTION, $all, false );
	}

	/** @return array<int,array{0:int,1:int}> size => [min_cm, max_cm] */
	public static function size_heights() {
		$out = array();
		foreach ( preg_split( '/\r?\n/', (string) self::get( 'size_heights' ) ) as $line ) {
			if ( preg_match( '/^\s*(\d+)\s*:\s*(\d+)\s*-\s*(\d+)/', $line, $m ) ) {
				$out[ (int) $m[1] ] = array( (int) $m[2], (int) $m[3] );
			}
		}
		return $out;
	}

	public static function today() {
		return wp_date( 'Y-m-d' );
	}
}
