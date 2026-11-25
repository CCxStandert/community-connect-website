<?php
defined( 'ABSPATH' ) || exit;

/**
 * Loans: the single source of truth for who has which bike, when.
 * The public calendar, live bike status and all reminders are derived from this table.
 *
 * Lifecycle: requested -> approved -> out -> returned
 *            requested -> declined | cancelled ; approved -> cancelled
 * Dates are inclusive calendar days (Y-m-d) in the site's timezone.
 */
class CC_Fleet_Loans {

	const STATUSES = array(
		'requested' => 'Requested',
		'approved'  => 'Approved',
		'out'       => 'Out',
		'returned'  => 'Returned',
		'declined'  => 'Declined',
		'cancelled' => 'Cancelled',
		'held'      => 'Raffle hold',
		'waiting'   => 'Waiting list',
		'offered'   => 'Offered',
	);

	/** Which status changes are allowed. */
	const TRANSITIONS = array(
		'requested' => array( 'approved', 'declined', 'cancelled', 'waiting' ),
		'approved'  => array( 'out', 'cancelled', 'requested' ),
		'out'       => array( 'returned' ),
		'returned'  => array( 'out' ),
		'declined'  => array( 'requested' ),
		'cancelled' => array( 'requested' ),
		'held'      => array( 'approved', 'cancelled' ),
		'waiting'   => array( 'offered', 'cancelled', 'declined', 'requested' ),
		'offered'   => array( 'approved', 'waiting', 'cancelled' ),
	);

	public static function table() {
		return CC_Fleet_Install::table( 'loans' );
	}

	public static function blocking_statuses( $include_requested = false ) {
		$s = array( 'approved', 'out', 'held', 'offered' );
		if ( $include_requested ) {
			$s[] = 'requested';
		}
		return $s;
	}

	public static function get( $id ) {
		global $wpdb;
		if ( ! is_numeric( $id ) || (int) $id <= 0 ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
	}

	public static function get_by_token( $token ) {
		global $wpdb;
		if ( strlen( (string) $token ) < 20 ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', $token ) );
	}

	/* ---------------- Dates ---------------- */

	public static function valid_date( $d ) {
		if ( ! is_string( $d ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
			return false;
		}
		[ $y, $m, $day ] = array_map( 'intval', explode( '-', $d ) );
		return checkdate( $m, $day, $y );
	}

	public static function add_days( $date, $n ) {
		return ( new DateTimeImmutable( $date ) )->modify( ( $n >= 0 ? '+' : '' ) . (int) $n . ' days' )->format( 'Y-m-d' );
	}

	public static function days_between( $a, $b ) {
		return (int) ( new DateTimeImmutable( $a ) )->diff( new DateTimeImmutable( $b ) )->format( '%r%a' );
	}

	/** An open loan past its end date keeps the bike blocked until it is marked returned. */
	public static function effective_end( $loan ) {
		$today = CC_Fleet_Settings::today();
		if ( 'out' === $loan->status && $loan->end_date < $today ) {
			return $today;
		}
		return $loan->end_date;
	}

	public static function is_overdue( $loan ) {
		return 'out' === $loan->status && $loan->end_date < CC_Fleet_Settings::today();
	}

	/**
	 * Validate a requested date range.
	 * @return true|WP_Error
	 */
	public static function validate_range( $start, $end, $public = true ) {
		if ( ! self::valid_date( $start ) || ! self::valid_date( $end ) ) {
			return new WP_Error( 'cc_dates', 'Please choose valid dates.' );
		}
		if ( $end < $start ) {
			return new WP_Error( 'cc_dates', 'The end date is before the start date.' );
		}
		if ( $public ) {
			$s     = CC_Fleet_Settings::all();
			$today = CC_Fleet_Settings::today();
			if ( self::days_between( $today, $start ) < (int) $s['min_notice_days'] ) {
				return new WP_Error( 'cc_dates', sprintf( 'Requests need at least %d day(s) notice.', (int) $s['min_notice_days'] ) );
			}
			if ( $end > self::horizon() ) {
				return new WP_Error( 'cc_dates', sprintf( 'We take requests up to %s. Ask us about later dates.', wp_date( 'j M Y', strtotime( self::horizon() . ' 12:00' ) ) ) );
			}
			if ( self::days_between( $start, $end ) + 1 > (int) $s['max_loan_days'] ) {
				return new WP_Error( 'cc_dates', sprintf( 'Loans are limited to %d days. Ask us if you need longer.', (int) $s['max_loan_days'] ) );
			}
		}
		return true;
	}

	/**
	 * Last day riders can book: Settings → "Bookable until", or 31 December of next year.
	 * On 21 Nov 2026 that is 31 Dec 2027.
	 */
	public static function horizon() {
		$set = (string) CC_Fleet_Settings::get( 'book_until' );
		if ( self::valid_date( $set ) && $set > CC_Fleet_Settings::today() ) {
			return $set;
		}
		return ( (int) substr( CC_Fleet_Settings::today(), 0, 4 ) + 1 ) . '-12-31';
	}

	/* ---------------- Availability ---------------- */

	/**
	 * Loans on this bike that clash with [start, end].
	 * Same-day handoff (on by default): a loan may start on the day another ends.
	 * Buffer days extend each existing loan's end.
	 */
	public static function conflicts( $bike_id, $start, $end, $exclude_id = 0, $include_requested = false ) {
		global $wpdb;
		$s        = CC_Fleet_Settings::all();
		$buffer   = max( 0, (int) $s['buffer_days'] );
		$same_day = ! empty( $s['same_day_handoff'] );
		$statuses = self::blocking_statuses( $include_requested );
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$latest   = self::add_days( $end, $buffer );
		$args     = array_merge( array( (int) $bike_id, (int) $exclude_id ), $statuses, array( $latest ) );
		$rows     = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " WHERE bike_id = %d AND id <> %d AND status IN ($in) AND start_date <= %s ORDER BY start_date",
			$args
		) );
		$out = array();
		foreach ( $rows as $l ) {
			$l_start = $l->start_date;
			$l_end   = self::add_days( self::effective_end( $l ), $buffer );
			$o_start = max( $start, $l_start );
			$o_end   = min( $latest, $l_end ); // Buffer applies after both loans.
			if ( $o_start > $o_end ) {
				continue; // No overlap.
			}
			$overlap_days = self::days_between( $o_start, $o_end ) + 1;
			// Handoff exemption only without buffer days, and never for a bike that is overdue (it isn't back yet).
			$handoff_ok = $same_day && 0 === $buffer && ! self::is_overdue( $l );
			if ( 1 === $overlap_days && $handoff_ok && ( $l_end === $start || $latest === $l_start ) && $l_start !== $l_end && $start !== $end ) {
				continue; // Handoff day: one returns, the next collects.
			}
			$out[] = $l;
		}
		// A long-term rider blocks the bike from their start date, open-ended.
		$a = CC_Fleet_Bikes::assignment( $bike_id );
		if ( $a && $end >= $a['since'] ) {
			$out[] = (object) array( 'id' => 0, 'bike_id' => (int) $bike_id, 'rider_id' => $a['rider_id'], 'start_date' => $a['since'], 'end_date' => '9999-12-31', 'status' => 'assigned', 'plan' => 'Long-term: ' . $a['name'] );
		}
		return $out;
	}

	/** Friendlier error when the clash is a long-term assignment. */
	private static function clash_error( array $clash ) {
		foreach ( $clash as $c ) {
			if ( 'assigned' === $c->status ) {
				$a = CC_Fleet_Bikes::assignment( $c->bike_id );
				return new WP_Error( 'cc_assigned', 'This bike is assigned long-term to ' . ( $a['name'] ?? 'a rider' ) . '. Remove that on the bike’s page first, or save anyway.', array( 'status' => 409, 'conflicts' => array() ) );
			}
		}
		return null;
	}

	public static function is_available( $bike_id, $start, $end, $exclude_id = 0 ) {
		if ( 'active' !== CC_Fleet_Bikes::get( $bike_id, 'state' ) ) {
			return false;
		}
		return ! self::conflicts( $bike_id, $start, $end, $exclude_id, (bool) CC_Fleet_Settings::get( 'requests_hold_dates' ) );
	}

	public static function is_booked_on( $bike_id, $date ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " WHERE bike_id = %d AND status IN ('approved','out') AND start_date <= %s",
			$bike_id, $date
		) );
		foreach ( $rows as $l ) {
			if ( self::effective_end( $l ) >= $date ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Booked ranges for the public calendar. No names, no notes.
	 * @return array<int,array{start:string,end:string,kind:string}>
	 */
	public static function booked_ranges( $bike_id, $from, $to ) {
		global $wpdb;
		$hold     = (bool) CC_Fleet_Settings::get( 'requests_hold_dates' );
		$statuses = self::blocking_statuses( $hold );
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$rows     = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " WHERE bike_id = %d AND status IN ($in) AND start_date <= %s ORDER BY start_date",
			array_merge( array( (int) $bike_id ), $statuses, array( $to ) )
		) );
		$buffer = max( 0, (int) CC_Fleet_Settings::get( 'buffer_days' ) );
		$out    = array();
		foreach ( $rows as $l ) {
			$end = self::add_days( self::effective_end( $l ), $buffer );
			if ( $end < $from ) {
				continue;
			}
			// The free day sits on both sides of a loan: a new loan has to end (plus its own free day)
			// before this one starts, so the day(s) just before a booking can't be booked either.
			$out[] = array(
				'start' => max( self::add_days( $l->start_date, -$buffer ), $from ),
				'end'   => min( $end, $to ),
				'kind'  => 'requested' === $l->status ? 'pending' : ( 'held' === $l->status ? 'reserved' : 'booked' ),
			);
		}
		$a = CC_Fleet_Bikes::assignment( $bike_id );
		if ( $a && $a['since'] <= $to ) {
			$out[] = array( 'start' => max( $a['since'], $from ), 'end' => $to, 'kind' => 'booked' );
		}
		return $out;
	}

	/** Active, published bikes free for the whole range, optionally filtered by size. */
	public static function available_bikes( $start, $end, $size = '' ) {
		$ids = array();
		foreach ( CC_Fleet_Bikes::public_ids() as $id ) {
			if ( $size && (string) CC_Fleet_Bikes::get( $id, 'size' ) !== (string) $size ) {
				continue;
			}
			if ( self::is_available( $id, $start, $end ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/* ---------------- Write ---------------- */

	/**
	 * Create a loan.
	 * @param array $data bike_id, rider_id, start_date, end_date, status, plan, notes, source, requested_size, handoff_from
	 * @param bool  $force Skip the clash check (admin override).
	 * @return int|WP_Error
	 */
	public static function create( array $data, $force = false ) {
		global $wpdb;
		$status = $data['status'] ?? 'requested';
		if ( ! isset( self::STATUSES[ $status ] ) ) {
			return new WP_Error( 'cc_status', 'Unknown status.' );
		}
		$bike_id = (int) ( $data['bike_id'] ?? 0 );
		if ( $bike_id && CC_Fleet_Bikes::CPT !== get_post_type( $bike_id ) ) {
			return new WP_Error( 'cc_bike', 'Unknown bike.' );
		}
		$valid = self::validate_range( $data['start_date'] ?? '', $data['end_date'] ?? '', false );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( $bike_id && ! $force && in_array( $status, self::blocking_statuses( true ), true ) ) {
			$clash = self::conflicts( $bike_id, $data['start_date'], $data['end_date'], 0, false );
			if ( $clash ) {
				return self::clash_error( $clash ) ?: new WP_Error( 'cc_conflict', 'This bike is already booked for some of those dates.', array( 'status' => 409, 'conflicts' => wp_list_pluck( $clash, 'id' ) ) );
			}
		}
		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( self::table(), array(
			'bike_id'        => $bike_id,
			'rider_id'       => (int) ( $data['rider_id'] ?? 0 ),
			'start_date'     => $data['start_date'],
			'end_date'       => $data['end_date'],
			'status'         => $status,
			'plan'           => sanitize_textarea_field( $data['plan'] ?? '' ),
			'notes'          => sanitize_textarea_field( $data['notes'] ?? '' ),
			'source'         => sanitize_key( $data['source'] ?? 'admin' ),
			'event_id'       => (int) ( $data['event_id'] ?? 0 ),
			'race'           => empty( $data['race'] ) ? 0 : 1,
			'handoff_from'   => ! empty( $data['handoff_from'] ) ? (int) $data['handoff_from'] : null,
			'requested_size' => sanitize_text_field( $data['requested_size'] ?? '' ),
			'pedals'         => in_array( $data['pedals'] ?? '', array( 'need', 'own' ), true ) ? $data['pedals'] : '',
			'token'          => wp_generate_password( 32, false ),
			'created_at'     => $now,
			'updated_at'     => $now,
		) );
		if ( ! $ok ) {
			return new WP_Error( 'cc_db', 'Could not save the loan.' );
		}
		$id = (int) $wpdb->insert_id;
		CC_Fleet_Install::log( 'loan', $id, 'created', $status );
		do_action( 'cc_fleet_loan_created', $id );
		return $id;
	}

	/**
	 * Update fields. Changing bike or dates re-checks for clashes unless forced.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data, $force = false ) {
		global $wpdb;
		$loan = self::get( $id );
		if ( ! $loan ) {
			return new WP_Error( 'cc_missing', 'Loan not found.' );
		}
		$fields = array_intersect_key( $data, array_flip( array( 'bike_id', 'rider_id', 'start_date', 'end_date', 'plan', 'notes', 'handoff_from', 'requested_size', 'event_id', 'handoff_place', 'handoff_time', 'handoff_note', 'race', 'pickup_mode', 'pickup_place', 'pickup_contact', 'pickup_time', 'pedals' ) ) );
		$merged = (object) array_merge( (array) $loan, $fields );
		$valid  = self::validate_range( $merged->start_date, $merged->end_date, false );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( $merged->bike_id && ! $force && in_array( $loan->status, self::blocking_statuses( true ), true ) ) {
			$clash = self::conflicts( $merged->bike_id, $merged->start_date, $merged->end_date, $id, false );
			if ( $clash ) {
				return self::clash_error( $clash ) ?: new WP_Error( 'cc_conflict', 'This bike is already booked for some of those dates.', array( 'conflicts' => wp_list_pluck( $clash, 'id' ) ) );
			}
		}
		$fields['updated_at'] = current_time( 'mysql' );
		if ( isset( $fields['end_date'] ) && $fields['end_date'] !== $loan->end_date ) {
			$fields['reminder_sent'] = 0;
		}
		$wpdb->update( self::table(), $fields, array( 'id' => (int) $id ) );
		CC_Fleet_Install::log( 'loan', $id, 'updated' );
		do_action( 'cc_fleet_loan_updated', (int) $id, $loan );
		return true;
	}

	/**
	 * Move a loan through its lifecycle. Approving re-checks clashes.
	 * @return true|WP_Error
	 */
	public static function set_status( $id, $status, $notify = true, $force = false ) {
		global $wpdb;
		$loan = self::get( $id );
		if ( ! $loan ) {
			return new WP_Error( 'cc_missing', 'Loan not found.' );
		}
		if ( $loan->status === $status ) {
			return true;
		}
		if ( ! $force && ! in_array( $status, self::TRANSITIONS[ $loan->status ] ?? array(), true ) ) {
			return new WP_Error( 'cc_transition', sprintf( 'A %s loan cannot be marked %s.', $loan->status, $status ) );
		}
		if ( 'offered' === $loan->status && 'approved' === $status && class_exists( 'CC_Fleet_Waitlist' ) ) {
			CC_Fleet_Waitlist::release_others( $loan );
		}
		if ( in_array( $status, array( 'approved', 'out' ), true ) ) {
			if ( ! $loan->bike_id ) {
				return new WP_Error( 'cc_bike', 'Assign a bike before approving.' );
			}
			if ( 'held' === $loan->status && ! $loan->rider_id ) {
				return new WP_Error( 'cc_winner', 'Choose the raffle winner as the rider and save, then approve.' );
			}
			$clash = $force ? array() : self::conflicts( $loan->bike_id, $loan->start_date, $loan->end_date, $id, false );
			if ( $clash ) {
				return self::clash_error( $clash ) ?: new WP_Error( 'cc_conflict', 'Another approved loan overlaps these dates.' );
			}
		}
		$update = array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) );
		if ( 'waiting' === $status && empty( $loan->waitlisted_at ) ) {
			$update['waitlisted_at'] = current_time( 'mysql' );
		}
		if ( 'offered' !== $status ) {
			$update['offer_until'] = null; // An open offer ends with any other status.
		}
		if ( 'returned' === $status && $loan->end_date > CC_Fleet_Settings::today() ) {
			$update['end_date'] = CC_Fleet_Settings::today(); // Early return frees the remaining days.
		}
		$wpdb->update( self::table(), $update, array( 'id' => (int) $id ) );
		CC_Fleet_Install::log( 'loan', $id, 'status', $loan->status . ' → ' . $status );
		do_action( 'cc_fleet_loan_status', $id, $status, $loan->status, $notify );
		return true;
	}

	public static function delete( $id ) {
		global $wpdb;
		CC_Fleet_Install::log( 'loan', $id, 'deleted' );
		return $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/* ---------------- Read ---------------- */

	/**
	 * @param array $a bike_id, rider_id, status (string|array), from, to (date overlap), overdue (bool),
	 *                 search, limit, offset, order (ASC|DESC), orderby
	 */
	public static function query( array $a = array() ) {
		global $wpdb;
		[ $where, $args ] = self::where( $a );
		$order   = 'ASC' === strtoupper( $a['order'] ?? 'DESC' ) ? 'ASC' : 'DESC';
		$orderby = in_array( $a['orderby'] ?? '', array( 'start_date', 'end_date', 'created_at', 'id' ), true ) ? $a['orderby'] : 'start_date';
		$limit   = max( 1, min( 1000, (int) ( $a['limit'] ?? 100 ) ) );
		$offset  = max( 0, (int) ( $a['offset'] ?? 0 ) );
		$sql     = 'SELECT l.* FROM ' . self::table() . ' l LEFT JOIN ' . CC_Fleet_Riders::table() . " r ON r.id = l.rider_id WHERE $where ORDER BY l.$orderby $order, l.id $order LIMIT %d OFFSET %d";
		$args[]  = $limit;
		$args[]  = $offset;
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	}

	public static function count( array $a = array() ) {
		global $wpdb;
		[ $where, $args ] = self::where( $a );
		$sql = 'SELECT COUNT(*) FROM ' . self::table() . ' l LEFT JOIN ' . CC_Fleet_Riders::table() . " r ON r.id = l.rider_id WHERE $where";
		return (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $sql ) );
	}

	private static function where( array $a ) {
		global $wpdb;
		$w    = array( '1=1' );
		$args = array();
		if ( ! empty( $a['bike_id'] ) ) {
			$w[]    = 'l.bike_id = %d';
			$args[] = (int) $a['bike_id'];
		}
		if ( ! empty( $a['rider_id'] ) ) {
			$w[]    = 'l.rider_id = %d';
			$args[] = (int) $a['rider_id'];
		}
		if ( ! empty( $a['status'] ) ) {
			$st     = array_values( array_intersect( (array) $a['status'], array_keys( self::STATUSES ) ) );
			$st     = $st ?: array( '__none__' );
			$w[]    = 'l.status IN (' . implode( ',', array_fill( 0, count( $st ), '%s' ) ) . ')';
			$args   = array_merge( $args, $st );
		}
		if ( ! empty( $a['from'] ) && ! empty( $a['to'] ) ) {
			$w[]    = 'l.start_date <= %s AND l.end_date >= %s';
			$args[] = $a['to'];
			$args[] = $a['from'];
		}
		if ( ! empty( $a['overdue'] ) ) {
			$w[]    = "l.status = 'out' AND l.end_date < %s";
			$args[] = CC_Fleet_Settings::today();
		}
		if ( ! empty( $a['search'] ) ) {
			$like   = '%' . $wpdb->esc_like( $a['search'] ) . '%';
			$w[]    = '(r.name LIKE %s OR r.email LIKE %s OR l.plan LIKE %s)';
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
		}
		return array( implode( ' AND ', $w ), $args );
	}

	public static function status_badge( $loan ) {
		$s     = self::is_overdue( $loan ) ? 'overdue' : $loan->status;
		$label = 'overdue' === $s ? 'Overdue' : ( self::STATUSES[ $s ] ?? $s );
		return sprintf( '<span class="cc-badge cc-badge--%s">%s</span>', esc_attr( $s ), esc_html( $label ) );
	}

	/** How far apart two loans can be and still count as a rider-to-rider handoff. */
	public static function handoff_window() {
		return max( 0, (int) CC_Fleet_Settings::get( 'buffer_days' ) ) + 2;
	}

	/** The loan that hands a bike to this one: the latest loan on the same bike ending just before it starts. */
	public static function previous_loan( $loan ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " WHERE bike_id = %d AND id <> %d AND status IN ('approved','out') AND end_date <= %s AND end_date >= %s ORDER BY end_date DESC LIMIT 1",
			$loan->bike_id, $loan->id, $loan->start_date, self::add_days( $loan->start_date, -self::handoff_window() )
		) );
	}

	/**
	 * Where this rider collects the bike: from the rider before ('rider') or at an address ('address').
	 * Saved choices win; otherwise the rider before if there is one, and our base when the loan starts
	 * more than 7 days out with nobody before.
	 */
	public static function pickup( $loan ) {
		$prev  = $loan->bike_id ? self::previous_loan( $loan ) : null;
		$base  = (string) CC_Fleet_Settings::get( 'base_address' );
		$mode  = (string) ( $loan->pickup_mode ?? '' );
		if ( 'rider' === $mode && ! $prev ) {
			$mode = '';
		}
		if ( '' === $mode ) {
			$far   = $loan->start_date > self::add_days( CC_Fleet_Settings::today(), 7 );
			$place = $prev ? '' : ( $far ? $base : '' );
			return array(
				'mode' => $prev ? 'rider' : 'address', 'place' => $place, 'contact' => $place && $place === $base ? (string) CC_Fleet_Settings::get( 'base_contact' ) : '',
				'time' => '', 'prev' => $prev, 'saved' => false,
			);
		}
		return array(
			'mode' => $mode, 'place' => (string) $loan->pickup_place, 'contact' => (string) $loan->pickup_contact,
			'time' => (string) $loan->pickup_time, 'prev' => $prev, 'saved' => true,
		);
	}

	/** The loan this one hands the bike to: the next loan on the same bike starting soon after it ends. */
	public static function next_loan( $loan ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " WHERE bike_id = %d AND id <> %d AND status IN ('approved','out') AND start_date >= %s AND start_date <= %s ORDER BY start_date ASC LIMIT 1",
			$loan->bike_id, $loan->id, $loan->end_date, self::add_days( $loan->end_date, self::handoff_window() )
		) );
	}

	/** "Needs SPD pedals" / "Brings own pedals" / '' — what the rider said about pedals. */
	public static function pedals_label( $loan ) {
		$p = (string) ( $loan->pedals ?? '' );
		return 'need' === $p ? 'Needs SPD pedals' : ( 'own' === $p ? 'Brings own pedals' : '' );
	}
}
