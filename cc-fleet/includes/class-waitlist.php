<?php
defined( 'ABSPATH' ) || exit;

/**
 * Waiting list: riders whose dates clash can wait for a bike to free up.
 *
 * A place on the list is a loan with status "waiting" (it never blocks the calendar).
 * When a loan is cancelled, shortened or returned early, the riders who now fit are
 * suggested to the crew in order. An offer ("offered", which does block the dates)
 * gives the rider a set number of hours to say yes; no answer or a no moves it to the
 * next rider by itself. Close to the start date it goes to several riders at once
 * and the first yes gets the bike.
 *
 * Order: riders going to one of our events first, then by the day they asked,
 * then someone who hasn't borrowed in 3 months, then the exact time they asked.
 */
class CC_Fleet_Waitlist {

	const HOOK = 'cc_fleet_waitlist_hourly';

	public static function init() {
		add_action( 'cc_fleet_loan_created', array( __CLASS__, 'on_created' ), 5 );
		add_action( 'cc_fleet_loan_status', array( __CLASS__, 'on_status' ), 20, 4 );
		add_action( 'cc_fleet_loan_updated', array( __CLASS__, 'on_updated' ), 10, 2 );
		add_action( 'cc_fleet_email_sent', array( __CLASS__, 'on_email_sent' ) );
		add_action( 'cc_fleet_email_skipped', array( __CLASS__, 'on_email_skipped' ) );
		add_action( self::HOOK, array( __CLASS__, 'hourly' ) );
		add_action( 'template_redirect', array( __CLASS__, 'rider_page' ), 1 );
		add_action( 'admin_post_cc_fleet_wl', array( __CLASS__, 'handle_admin' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	/* ---------------- Settings ---------------- */

	public static function offer_hours() {
		return max( 1, (int) CC_Fleet_Settings::get( 'waitlist_offer_hours' ) ?: 24 );
	}

	public static function short_hours() {
		return max( 0, (int) CC_Fleet_Settings::get( 'waitlist_short_hours' ) );
	}

	public static function short_count() {
		return max( 1, (int) CC_Fleet_Settings::get( 'waitlist_short_count' ) ?: 3 );
	}

	/** The loan starts so soon that one rider at a time would take too long. */
	public static function is_short_notice( $loan ) {
		$start = strtotime( $loan->start_date . ' 08:00:00' );
		return $start - current_time( 'timestamp' ) < self::short_hours() * HOUR_IN_SECONDS;
	}

	/* ---------------- Rider links ---------------- */

	public static function url( $loan, $action ) {
		return add_query_arg( array( 'cc_wl' => $action, 't' => $loan->token ), home_url( '/' ) );
	}

	/* ---------------- Reading the list ---------------- */

	/** Everyone waiting, oldest first. */
	public static function waiting( $limit = 200 ) {
		return CC_Fleet_Loans::query( array( 'status' => 'waiting', 'order' => 'ASC', 'orderby' => 'created_at', 'limit' => $limit ) );
	}

	public static function offers() {
		return CC_Fleet_Loans::query( array( 'status' => 'offered', 'order' => 'ASC', 'orderby' => 'start_date', 'limit' => 100 ) );
	}

	/** Has this rider got a bike that's overdue right now? */
	private static function has_overdue( $rider_id ) {
		if ( ! $rider_id ) {
			return false;
		}
		foreach ( CC_Fleet_Loans::query( array( 'rider_id' => $rider_id, 'overdue' => true, 'limit' => 1 ) ) as $l ) {
			return true;
		}
		return false;
	}

	private static function size_fits( $bike_id, $rider ) {
		if ( ! $rider || ! $rider->height_cm ) {
			return true;
		}
		$range = CC_Fleet_Bikes::rider_range( $bike_id );
		return ! $range || ( (int) $rider->height_cm >= $range[0] && (int) $rider->height_cm <= $range[1] );
	}

	/** Loans this rider had that went ahead (not counting waiting-list places and requests). */
	public static function history( $rider_id ) {
		$loans = $rider_id ? CC_Fleet_Loans::query( array( 'rider_id' => $rider_id, 'status' => array( 'approved', 'out', 'returned' ), 'limit' => 200, 'orderby' => 'end_date' ) ) : array();
		return array( 'count' => count( $loans ), 'last' => $loans ? $loans[0]->end_date : '' );
	}

	/** Whether a waiting rider is "fresh": never borrowed, or not in the last 3 months. */
	private static function is_fresh( $rider_id ) {
		$h = self::history( $rider_id );
		return ! $h['last'] || $h['last'] < CC_Fleet_Loans::add_days( CC_Fleet_Settings::today(), -90 );
	}

	/** Suggested order: event riders, then the day they asked, then fresh riders, then the exact time. */
	public static function rank( array $loans ) {
		$keyed = array();
		foreach ( $loans as $l ) {
			$keyed[] = array( $l, array(
				(int) $l->event_id > 0 ? 0 : 1,
				substr( (string) $l->created_at, 0, 10 ),
				self::is_fresh( (int) $l->rider_id ) ? 0 : 1,
				(string) $l->created_at,
				(int) $l->id,
			) );
		}
		usort( $keyed, function ( $a, $b ) { return $a[1] <=> $b[1]; } );
		return array_map( function ( $k ) { return $k[0]; }, $keyed );
	}

	/**
	 * Waiting riders who could have this bike now, in the suggested order.
	 * @param bool $fresh_round Skip riders whose offer ran out in the last 48 hours (used when moving on by itself).
	 */
	public static function candidates( $bike_id, $fresh_round = false ) {
		$bike_id = (int) $bike_id;
		if ( ! $bike_id || 'active' !== CC_Fleet_Bikes::get( $bike_id, 'state' ) ) {
			return array();
		}
		$today = CC_Fleet_Settings::today();
		$size  = (string) CC_Fleet_Bikes::get( $bike_id, 'size' );
		$cut   = wp_date( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ); // Site time, like the loan columns.
		$out   = array();
		foreach ( self::waiting() as $l ) {
			$mine = (int) $l->bike_id === $bike_id || ( ! (int) $l->bike_id && '' !== $size && (string) $l->requested_size === $size );
			if ( ! $mine || $l->start_date < $today ) {
				continue;
			}
			if ( $fresh_round && $l->offer_lapsed_at && $l->offer_lapsed_at > $cut ) {
				continue;
			}
			$rider = CC_Fleet_Riders::get( $l->rider_id );
			if ( ! self::size_fits( $bike_id, $rider ) || self::has_overdue( (int) $l->rider_id ) ) {
				continue;
			}
			if ( ! CC_Fleet_Loans::is_available( $bike_id, $l->start_date, $l->end_date, (int) $l->id ) ) {
				continue;
			}
			$out[] = $l;
		}
		return self::rank( $out );
	}

	/** Place in line among riders waiting for the same bike (or size) on overlapping dates. */
	public static function position( $loan ) {
		$same = array();
		foreach ( self::waiting() as $l ) {
			$match = $loan->bike_id ? (int) $l->bike_id === (int) $loan->bike_id : ( ! (int) $l->bike_id && $l->requested_size === $loan->requested_size );
			if ( $match && $l->start_date <= $loan->end_date && $l->end_date >= $loan->start_date ) {
				$same[] = $l;
			}
		}
		$i = array_search( (int) $loan->id, array_map( 'intval', wp_list_pluck( self::rank( $same ), 'id' ) ), true );
		return false === $i ? 1 : $i + 1;
	}

	/* ---------------- Joining ---------------- */

	public static function on_created( $loan_id ) {
		$loan = CC_Fleet_Loans::get( $loan_id );
		if ( ! $loan || 'waiting' !== $loan->status ) {
			return;
		}
		self::mark_joined( $loan );
		CC_Fleet_Emails::wl_joined( CC_Fleet_Loans::get( $loan_id ), 'web' === $loan->source ? 'wl_joined_web' : 'wl_joined' );
	}

	private static function mark_joined( $loan ) {
		global $wpdb;
		$wpdb->update( CC_Fleet_Loans::table(), array(
			'waitlisted_at' => $loan->waitlisted_at ?: current_time( 'mysql' ),
			'wl_any'        => (int) $loan->bike_id ? 0 : 1,
			'offer_group'   => '',
		), array( 'id' => (int) $loan->id ) );
	}

	/** The same rider already waits for this bike (or size) on these dates. */
	public static function find_duplicate( $rider_id, $bike_id, $size, $start, $end ) {
		foreach ( CC_Fleet_Loans::query( array( 'rider_id' => $rider_id, 'status' => array( 'waiting', 'offered' ), 'limit' => 50 ) ) as $l ) {
			$same = $bike_id ? (int) $l->bike_id === (int) $bike_id : (string) $l->requested_size === (string) $size;
			if ( $same && $l->start_date === $start && $l->end_date === $end ) {
				return (int) $l->id;
			}
		}
		return 0;
	}

	/* ---------------- Something freed up ---------------- */

	public static function on_status( $loan_id, $status, $old, $notify ) {
		$loan = CC_Fleet_Loans::get( $loan_id );
		if ( ! $loan ) {
			return;
		}
		if ( 'waiting' === $status && 'requested' === $old ) {
			self::mark_joined( $loan );
			if ( $notify ) {
				CC_Fleet_Emails::wl_joined( CC_Fleet_Loans::get( $loan_id ), 'wl_joined' );
			}
			return;
		}
		// An offer that ends moves on through next_in_line() or the crew, so it isn't a new spot here.
		$freed = ( in_array( $old, array( 'approved', 'held', 'requested' ), true ) && in_array( $status, array( 'cancelled', 'declined', 'waiting' ), true ) )
			|| 'returned' === $status;
		if ( $freed && $loan->bike_id ) {
			self::spot_check( (int) $loan->bike_id, $loan );
		}
	}

	public static function on_updated( $loan_id, $before ) {
		$loan = CC_Fleet_Loans::get( $loan_id );
		if ( ! $loan || ! in_array( $loan->status, array( 'approved', 'out', 'held', 'offered' ), true ) ) {
			return;
		}
		$shorter = $loan->start_date > $before->start_date || $loan->end_date < $before->end_date || (int) $loan->bike_id !== (int) $before->bike_id;
		if ( $shorter && $before->bike_id ) {
			self::spot_check( (int) $before->bike_id, $before );
		}
	}

	/**
	 * Tell the crew who could have the bike now. Once per bike and set of riders a day,
	 * so a busy morning doesn't fill the inbox.
	 */
	public static function spot_check( $bike_id, $freed = null ) {
		$cands = self::candidates( $bike_id );
		if ( ! $cands ) {
			return 0;
		}
		$key = 'cc_wl_spot_' . md5( $bike_id . ':' . implode( ',', wp_list_pluck( $cands, 'id' ) ) );
		if ( get_transient( $key ) ) {
			return 0;
		}
		set_transient( $key, 1, 20 * HOUR_IN_SECONDS );
		CC_Fleet_Emails::wl_crew_spot( $bike_id, $cands, $freed );
		return count( $cands );
	}

	/* ---------------- Offers ---------------- */

	/**
	 * Offer the bike to a waiting rider.
	 * @param string $how 'crew' (email can be reviewed first), 'auto' or 'short' (sent straight away).
	 * @return true|WP_Error
	 */
	public static function offer( $loan_id, $bike_id = 0, $how = 'crew', $group = '' ) {
		global $wpdb;
		$loan = CC_Fleet_Loans::get( $loan_id );
		if ( ! $loan || 'waiting' !== $loan->status ) {
			return new WP_Error( 'cc_wl', 'This rider isn’t on the waiting list any more.' );
		}
		$bike_id = (int) ( $bike_id ?: $loan->bike_id );
		if ( ! $bike_id ) {
			return new WP_Error( 'cc_wl', 'Pick the bike to offer.' );
		}
		// In a short-notice round the riders' dates overlap each other on purpose: offer_group() checked them all first.
		if ( '' === $group && ! CC_Fleet_Loans::is_available( $bike_id, $loan->start_date, $loan->end_date, (int) $loan->id ) ) {
			return new WP_Error( 'cc_wl', CC_Fleet_Bikes::get( $bike_id, 'cc_id' ) . ' is still booked for some of these dates.' );
		}
		$wpdb->update( CC_Fleet_Loans::table(), array( 'bike_id' => $bike_id, 'offer_group' => $group, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $loan->id ) );
		$res = CC_Fleet_Loans::set_status( $loan->id, 'offered', false );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$loan = CC_Fleet_Loans::get( $loan->id );
		$type = 'crew' === $how ? 'wl_offer' : 'wl_offer_auto';
		$sent = CC_Fleet_Emails::wl_offer( $loan, $type, '' !== $group );
		if ( 'held' !== $sent ) {
			self::start_clock( $loan->id );
		}
		CC_Fleet_Install::log( 'loan', $loan->id, 'waiting list', 'Offered ' . CC_Fleet_Bikes::get( $bike_id, 'cc_id' ) . ( 'crew' === $how ? '' : ' (' . $how . ')' ) );
		return true;
	}

	/** Short notice: offer to the first few riders together. First yes wins. */
	public static function offer_group( $bike_id, array $loans ) {
		$group = 'g' . wp_generate_password( 10, false, false );
		$n     = 0;
		$fits  = array_map( 'intval', wp_list_pluck( self::candidates( $bike_id ), 'id' ) );
		$loans = array_values( array_filter( $loans, function ( $l ) use ( $fits ) { return in_array( (int) $l->id, $fits, true ); } ) );
		foreach ( array_slice( $loans, 0, self::short_count() ) as $l ) {
			if ( true === self::offer( $l->id, $bike_id, 'short', $group ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function start_clock( $loan_id ) {
		global $wpdb;
		$until = wp_date( 'Y-m-d H:i:s', time() + self::offer_hours() * HOUR_IN_SECONDS ); // Site time.
		$wpdb->update( CC_Fleet_Loans::table(), array( 'offer_until' => $until ), array( 'id' => (int) $loan_id, 'status' => 'offered' ) );
	}

	/** The crew sent the offer they reviewed: the clock starts now. */
	public static function on_email_sent( $row ) {
		if ( 'wl_offer' === $row->type && $row->loan_id ) {
			self::start_clock( (int) $row->loan_id );
		}
	}

	/** The crew chose not to send the offer: the rider goes back to waiting. */
	public static function on_email_skipped( $row ) {
		$loan = 'wl_offer' === $row->type ? CC_Fleet_Loans::get( $row->loan_id ) : null;
		if ( $loan && 'offered' === $loan->status ) {
			self::step_back( $loan );
		}
	}

	/** Back to waiting, with an "any size" place freed from the bike it was offered. */
	private static function step_back( $loan, $lapsed = false ) {
		global $wpdb;
		CC_Fleet_Loans::set_status( $loan->id, 'waiting', false );
		$data = array( 'offer_group' => '', 'offer_until' => null );
		if ( (int) $loan->wl_any ) {
			$data['bike_id'] = 0;
		}
		if ( $lapsed ) {
			$data['offer_lapsed_at'] = current_time( 'mysql' );
		}
		$wpdb->update( CC_Fleet_Loans::table(), $data, array( 'id' => (int) $loan->id ) );
	}

	/** Someone in a short-notice group said yes: the others step back and are told. */
	public static function release_others( $loan ) {
		if ( '' === (string) $loan->offer_group ) {
			return;
		}
		foreach ( CC_Fleet_Loans::query( array( 'status' => 'offered', 'limit' => 50 ) ) as $o ) {
			if ( (int) $o->id !== (int) $loan->id && $o->offer_group === $loan->offer_group ) {
				self::step_back( $o );
				CC_Fleet_Emails::wl_gone( CC_Fleet_Loans::get( $o->id ), 'quicker', CC_Fleet_Bikes::label( $o->bike_id ) );
			}
		}
	}

	/**
	 * Move on by itself after a no or no answer: the next rider in line (or the next few at short notice).
	 * @return string What happened, for the crew note.
	 */
	public static function next_in_line( $bike_id ) {
		$cands = self::candidates( $bike_id, true );
		if ( ! $cands ) {
			return 'Nobody else on the waiting list fits ' . CC_Fleet_Bikes::get( $bike_id, 'cc_id' ) . ' now. The dates are free for new requests.';
		}
		if ( self::is_short_notice( $cands[0] ) ) {
			$n     = self::offer_group( $bike_id, $cands );
			$names = array_map( array( __CLASS__, 'rider_first' ), array_slice( $cands, 0, $n ) );
			return 'It starts soon, so it went to ' . self::and_list( $names ) . ' together. First yes gets it.';
		}
		$r = self::offer( $cands[0]->id, $bike_id, 'auto' );
		return true === $r ? 'Offered to ' . self::rider_first( $cands[0] ) . ', next in line.' : $r->get_error_message();
	}

	/* ---------------- Rider answers ---------------- */

	/** @return true|WP_Error */
	public static function accept( $loan ) {
		if ( 'offered' !== $loan->status ) {
			return new WP_Error( 'cc_wl', 'gone' );
		}
		CC_Fleet_Emails::$held = 0;
		$res = CC_Fleet_Loans::set_status( $loan->id, 'approved', true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		CC_Fleet_Install::log( 'loan', $loan->id, 'waiting list', 'Rider said yes' );
		$held = CC_Fleet_Emails::$held;
		CC_Fleet_Emails::wl_crew_note(
			self::rider_first( $loan ) . ' said yes to ' . CC_Fleet_Bikes::get( $loan->bike_id, 'cc_id' ),
			self::rider_first( $loan ) . ' said yes.',
			array( CC_Fleet_Bikes::label( $loan->bike_id ) . ' is now theirs for ' . self::dates( $loan ) . '.', $held ? 'Their confirmation is waiting for you: check the handoff and send it.' : 'Their confirmation went out. Set up the handoff on WhatsApp as usual.' ),
			$held ? CC_Fleet_Outbox::compose_url( $held ) : admin_url( 'admin.php?page=cc-fleet-loan&id=' . $loan->id ),
			$held ? 'Check & send the confirmation' : 'Open the loan'
		);
		return true;
	}

	public static function pass( $loan ) {
		if ( 'offered' !== $loan->status ) {
			return new WP_Error( 'cc_wl', 'gone' );
		}
		$bike = (int) $loan->bike_id;
		CC_Fleet_Loans::set_status( $loan->id, 'cancelled', false );
		CC_Fleet_Install::log( 'loan', $loan->id, 'waiting list', 'Rider passed' );
		$what = self::group_still_open( $loan ) ? 'Others in the same short-notice round can still say yes.' : self::next_in_line( $bike );
		CC_Fleet_Emails::wl_crew_note( self::rider_first( $loan ) . ' passed on ' . CC_Fleet_Bikes::get( $bike, 'cc_id' ), self::rider_first( $loan ) . ' passed.', array( self::dates( $loan ) . ' on ' . CC_Fleet_Bikes::label( $bike ) . '.', $what ), admin_url( 'admin.php?page=cc-fleet#waitlist' ), 'Open the waiting list' );
		return true;
	}

	private static function group_still_open( $loan ) {
		if ( '' === (string) $loan->offer_group ) {
			return false;
		}
		foreach ( self::offers() as $o ) {
			if ( (int) $o->id !== (int) $loan->id && $o->offer_group === $loan->offer_group ) {
				return true;
			}
		}
		return false;
	}

	public static function leave( $loan ) {
		if ( ! in_array( $loan->status, array( 'waiting', 'offered' ), true ) ) {
			return new WP_Error( 'cc_wl', 'gone' );
		}
		if ( 'offered' === $loan->status ) {
			return self::pass( $loan );
		}
		CC_Fleet_Loans::set_status( $loan->id, 'cancelled', false );
		CC_Fleet_Install::log( 'loan', $loan->id, 'waiting list', 'Rider left the list' );
		return true;
	}

	/** A rider with a request or confirmed loan can't make it. */
	public static function back_out( $loan ) {
		if ( ! in_array( $loan->status, array( 'requested', 'approved' ), true ) ) {
			return new WP_Error( 'cc_wl', 'gone' );
		}
		$res = CC_Fleet_Loans::set_status( $loan->id, 'cancelled', false ); // Opens the spot for the waiting list (on_status).
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		CC_Fleet_Install::log( 'loan', $loan->id, 'cancelled', 'Rider backed out' );
		CC_Fleet_Emails::wl_thanks( $loan );
		$cands = $loan->bike_id ? self::candidates( (int) $loan->bike_id ) : array();
		if ( ! $cands ) {
			CC_Fleet_Emails::wl_crew_note( self::rider_first( $loan ) . ' cancelled ' . ( $loan->bike_id ? CC_Fleet_Bikes::get( $loan->bike_id, 'cc_id' ) : 'their request' ), self::rider_first( $loan ) . ' can’t make it.',
				array( 'They cancelled ' . self::bike( $loan ) . ', ' . self::dates( $loan ) . '.', 'Nobody on the waiting list fits these dates, so they’re free for new requests.' ),
				admin_url( 'admin.php?page=cc-fleet-loan&id=' . $loan->id ), 'Open the loan' );
		}
		return true;
	}

	/* ---------------- Hourly ---------------- */

	/** @return array summary */
	public static function hourly() {
		$now     = current_time( 'mysql' );
		$today   = CC_Fleet_Settings::today();
		$summary = array( 'lapsed' => 0, 'closed' => 0, 'spots' => 0 );
		// 1. Offers nobody answered in time.
		$bikes = array();
		foreach ( self::offers() as $o ) {
			if ( $o->offer_until && $o->offer_until < $now ) {
				$bikes[ (int) $o->bike_id ][] = self::rider_first( $o );
				self::step_back( $o, true );
				CC_Fleet_Emails::wl_gone( CC_Fleet_Loans::get( $o->id ), 'lapsed', CC_Fleet_Bikes::label( $o->bike_id ) );
				CC_Fleet_Install::log( 'loan', $o->id, 'waiting list', 'Offer ran out' );
				$summary['lapsed']++;
			}
		}
		foreach ( $bikes as $bike_id => $names ) {
			$what = self::next_in_line( $bike_id );
			CC_Fleet_Emails::wl_crew_note( self::and_list( $names ) . ' didn’t answer: ' . CC_Fleet_Bikes::get( $bike_id, 'cc_id' ), 'No answer in time.',
				array( self::and_list( $names ) . ' didn’t say yes to ' . CC_Fleet_Bikes::label( $bike_id ) . ' within ' . self::offer_hours() . ' hours. They stay on the list.', $what ),
				admin_url( 'admin.php?page=cc-fleet#waitlist' ), 'Open the waiting list' );
		}
		// 2. Places whose start day has come: the list closes for them.
		foreach ( self::waiting() as $l ) {
			if ( $l->start_date <= $today ) {
				CC_Fleet_Loans::set_status( $l->id, 'declined', false );
				CC_Fleet_Install::log( 'loan', $l->id, 'waiting list', 'Closed: the bike didn’t free up' );
				CC_Fleet_Emails::wl_closed( CC_Fleet_Loans::get( $l->id ) );
				$summary['closed']++;
			}
		}
		// 3. Bikes someone on the list could have now (back from the workshop, a change nobody flagged…).
		$seen = array();
		foreach ( self::waiting() as $l ) {
			$ids = (int) $l->bike_id ? array( (int) $l->bike_id ) : CC_Fleet_Loans::available_bikes( $l->start_date, $l->end_date, $l->requested_size );
			foreach ( $ids as $b ) {
				if ( empty( $seen[ $b ] ) ) {
					$seen[ $b ]        = 1;
					$summary['spots'] += self::spot_check( $b ) ? 1 : 0;
				}
			}
		}
		if ( array_filter( $summary ) ) {
			CC_Fleet_Install::log( 'cron', 0, 'waiting list', wp_json_encode( $summary ) );
		}
		return $summary;
	}

	/* ---------------- Small helpers ---------------- */

	public static function rider_first( $loan ) {
		$r = CC_Fleet_Riders::get( $loan->rider_id );
		return $r ? ( trim( strtok( (string) $r->name, ' ' ) ) ?: $r->name ) : 'A rider';
	}

	public static function and_list( array $names ) {
		$names = array_values( $names );
		if ( count( $names ) < 2 ) {
			return implode( '', $names );
		}
		return implode( ', ', array_slice( $names, 0, -1 ) ) . ' and ' . end( $names );
	}

	private static function bike( $loan ) {
		return $loan->bike_id ? CC_Fleet_Bikes::label( $loan->bike_id ) : 'a bike in size ' . $loan->requested_size;
	}

	private static function dates( $loan ) {
		return wp_date( 'D j M', strtotime( $loan->start_date . ' 12:00' ) ) . ' → ' . wp_date( 'D j M', strtotime( $loan->end_date . ' 12:00' ) );
	}

	/* ---------------- Rider pages (links in the emails) ---------------- */

	/**
	 * Links open a page with one button; only pressing it does anything,
	 * so mail scanners that open links can't answer for the rider.
	 */
	public static function rider_page() {
		if ( empty( $_GET['cc_wl'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_GET['cc_wl'] ) );
		$loan   = CC_Fleet_Loans::get_by_token( sanitize_text_field( wp_unslash( $_GET['t'] ?? '' ) ) );
		nocache_headers();
		if ( ! $loan || ! in_array( $action, array( 'yes', 'no', 'leave', 'cancel' ), true ) ) {
			self::page( 'Link not found.', 'This link doesn’t work any more. Reply to our last email and we’ll sort it out.' );
		}
		$name  = self::rider_first( $loan );
		$what  = self::bike( $loan ) . ', ' . self::dates( $loan );
		$fleet = (int) get_option( 'cc_fleet_page_id' ) ? get_permalink( (int) get_option( 'cc_fleet_page_id' ) ) : home_url( '/fleet/' );
		$asks  = array(
			'yes'    => array( 'offered', 'It’s free.', "Hi $name, " . self::bike( $loan ) . ' is yours for ' . self::dates( $loan ) . ' if you want it.', 'Yes, I’ll take it' ),
			'no'     => array( 'offered', 'Pass it on?', "No problem, $name. We’ll offer " . self::bike( $loan ) . ' to the next person in line.', 'Pass it on' ),
			'leave'  => array( array( 'waiting', 'offered' ), 'Leave the waiting list?', "You’re waiting for $what. We’ll stop looking for you.", 'Take me off the list' ),
			'cancel' => array( array( 'requested', 'approved' ), 'Can’t make it?', "Thanks for telling us early, $name. We’ll free $what for the next rider.", 'Cancel my booking' ),
		);
		$ask = $asks[ $action ];
		if ( ! in_array( $loan->status, (array) $ask[0], true ) ) {
			$done = array(
				'approved' => array( 'It’s yours already.', 'This bike is booked for you. Check your email for the handoff.' ),
				'out'      => array( 'You’re riding it.', 'The bike is with you. Reply to our emails if anything changes.' ),
				'cancelled' => array( 'All sorted.', 'This one is closed. Want another bike? Have a look at the fleet.' ),
			);
			$m = $done[ $loan->status ] ?? array( 'Too late for this one.', 'This offer isn’t open any more. Have a look at what else is free.' );
			self::page( $m[0], $m[1], array( 'See the fleet', $fleet ) );
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			$until = 'offered' === $loan->status && $loan->offer_until ? ' We’re holding it until ' . mysql2date( 'D j M, H:i', $loan->offer_until ) . '.' : '';
			self::page( $ask[1], $ask[2] . ( 'yes' === $action ? $until : '' ), null, $ask[3] );
		}
		switch ( $action ) {
			case 'yes':
				$r = self::accept( $loan );
				is_wp_error( $r )
					? self::page( 'Too late for this one.', 'Someone else got there first, or the offer ran out. You’re still on the list for other openings.', array( 'See the fleet', $fleet ) )
					: self::page( 'You’re on.', "Brilliant, $name. " . self::bike( $loan ) . ' is yours for ' . self::dates( $loan ) . '. Your confirmation with the handoff comes by email.' );
				break;
			case 'no':
				self::pass( $loan );
				self::page( 'Passed on.', 'Thanks for letting us know. We’ve offered it to the next rider.', array( 'See the fleet', $fleet ) );
				break;
			case 'leave':
				self::leave( $loan );
				self::page( 'You’re off the list.', 'Done. Hope to see you on one of our bikes soon.', array( 'See the fleet', $fleet ) );
				break;
			case 'cancel':
				self::back_out( $loan );
				self::page( 'Thanks for the heads-up.', 'Your booking is cancelled. Telling us early means someone on the waiting list gets to ride.', array( 'See the fleet', $fleet ) );
				break;
		}
	}

	/** A small page in the Community Connect look. Ends the request. */
	private static function page( $headline, $text, $link = null, $button = '' ) {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		$form = $button ? '<form method="post"><button type="submit">' . esc_html( $button ) . '</button></form>' : '';
		$link = $link ? '<p><a class="ghost" href="' . esc_url( $link[1] ) . '">' . esc_html( $link[0] ) . '</a></p>' : '';
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $headline ) . ' · Community Connect</title>'
			. '<link href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;800&display=swap" rel="stylesheet">'
			. '<style>body{margin:0;background:#F4EEE4;color:#141217;font:400 18px/1.45 "Inter Tight",Helvetica,Arial,sans-serif}main{max-width:520px;margin:0 auto;padding:48px 20px}'
			. '.logo{display:block;width:150px;margin:0 0 40px}h1{font-weight:800;font-size:40px;line-height:1.05;letter-spacing:-.02em;margin:0 0 14px}p{margin:0 0 22px}'
			. 'button,.ghost{display:block;width:100%;box-sizing:border-box;padding:17px 12px;border:1.5px solid #141217;font:500 14px/1.2 "Inter Tight",sans-serif;letter-spacing:.06em;text-transform:uppercase;text-align:center;text-decoration:none;cursor:pointer}'
			. 'button{background:#141217;color:#F4EEE4}.ghost{color:#141217;background:transparent}</style></head><body><main>'
			. '<img class="logo" src="' . esc_url( CC_FLEET_URL . 'assets/email/logo.png' ) . '" alt="Community Connect">'
			. '<h1>' . esc_html( $headline ) . '</h1><p>' . esc_html( $text ) . '</p>' . $form . $link . '</main></body></html>';
		exit;
	}

	/* ---------------- Crew: admin actions + dashboard ---------------- */

	public static function action_url( $op, $loan_id, $bike_id = 0 ) {
		return wp_nonce_url( add_query_arg( array_filter( array( 'action' => 'cc_fleet_wl', 'op' => $op, 'id' => (int) $loan_id, 'bike' => (int) $bike_id ) ), admin_url( 'admin-post.php' ) ), 'cc_fleet_wl_' . (int) $loan_id );
	}

	public static function handle_admin() {
		$id = (int) ( $_GET['id'] ?? 0 );
		if ( ! current_user_can( 'manage_cc_fleet' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'cc_fleet_wl_' . $id );
		$op   = sanitize_key( $_GET['op'] ?? '' );
		$bike = (int) ( $_GET['bike'] ?? 0 );
		$loan = CC_Fleet_Loans::get( $id );
		$back = wp_get_referer() ?: admin_url( 'admin.php?page=cc-fleet#waitlist' );
		$msg  = 'Done.';
		$type = 'success';
		CC_Fleet_Emails::$held = 0;
		if ( ! $loan ) {
			$msg  = 'Loan not found.';
			$type = 'error';
		} elseif ( 'offer' === $op ) {
			$r = self::offer( $id, $bike );
			if ( is_wp_error( $r ) ) {
				$msg  = $r->get_error_message();
				$type = 'error';
			} else {
				$msg = 'Offered to ' . self::rider_first( $loan ) . '. They have ' . self::offer_hours() . ' hours to say yes from when the email goes out.';
			}
		} elseif ( 'offer_short' === $op ) {
			$n   = self::offer_group( $bike, self::candidates( $bike ) );
			$msg = $n ? 'Offered to the first ' . $n . ' in line together. First yes gets it.' : 'Nobody on the list fits this bike now.';
		} elseif ( 'withdraw' === $op && 'offered' === $loan->status ) {
			self::step_back( $loan );
			$msg = 'Offer withdrawn. ' . self::rider_first( $loan ) . ' is back on the waiting list (not emailed).';
		} elseif ( 'said_no' === $op && 'offered' === $loan->status ) {
			self::pass( $loan );
			$msg = self::rider_first( $loan ) . ' passed. Moved on to the next rider.';
		} elseif ( 'said_yes' === $op && 'offered' === $loan->status ) {
			$r = self::accept( $loan );
			if ( is_wp_error( $r ) ) {
				$msg  = $r->get_error_message();
				$type = 'error';
			} else {
				$msg = self::rider_first( $loan ) . ' has the bike.';
			}
		} elseif ( 'remove' === $op && 'waiting' === $loan->status ) {
			CC_Fleet_Loans::set_status( $id, 'cancelled', false );
			$msg = self::rider_first( $loan ) . ' is off the waiting list (not emailed).';
		}
		if ( 'error' !== $type && CC_Fleet_Emails::$held ) {
			set_transient( 'cc_fleet_notice_' . get_current_user_id(), array( $msg . ' Now check the email and send it.', 'info' ), 60 );
			wp_safe_redirect( CC_Fleet_Outbox::compose_url( CC_Fleet_Emails::$held, $back ) );
			exit;
		}
		set_transient( 'cc_fleet_notice_' . get_current_user_id(), array( esc_html( $msg ), $type ), 60 );
		wp_safe_redirect( $back );
		exit;
	}

	/** Buttons on a waiting or offered loan (loan page, lists). */
	public static function buttons( $loan ) {
		$html = '';
		if ( 'waiting' === $loan->status ) {
			$bikes = (int) $loan->bike_id ? array( (int) $loan->bike_id ) : CC_Fleet_Loans::available_bikes( $loan->start_date, $loan->end_date, $loan->requested_size );
			$fits  = array();
			foreach ( $bikes as $b ) {
				if ( in_array( (int) $loan->id, array_map( 'intval', wp_list_pluck( self::candidates( $b ), 'id' ) ), true ) ) {
					$fits[] = $b;
				}
			}
			foreach ( $fits as $b ) {
				$html .= sprintf( '<a class="button button-primary" href="%s">Offer %s</a> ', esc_url( self::action_url( 'offer', $loan->id, $b ) ), esc_html( CC_Fleet_Bikes::get( $b, 'cc_id' ) ) );
			}
			if ( ! $fits ) {
				$html .= '<span class="cc-muted">Still booked for these dates.</span> ';
			}
			$html .= sprintf( '<a class="button" href="%s" onclick="return confirm(\'Take them off the waiting list? They won’t be emailed.\')">Take off the list</a>', esc_url( self::action_url( 'remove', $loan->id ) ) );
		} elseif ( 'offered' === $loan->status ) {
			$html .= sprintf( '<a class="button button-primary" href="%s">They said yes</a> <a class="button" href="%s">They said no</a> <a class="button" href="%s">Withdraw offer</a>',
				esc_url( self::action_url( 'said_yes', $loan->id ) ), esc_url( self::action_url( 'said_no', $loan->id ) ), esc_url( self::action_url( 'withdraw', $loan->id ) ) );
		}
		return $html;
	}

	private static function rider_line( $loan ) {
		$r = CC_Fleet_Riders::get( $loan->rider_id );
		$h = self::history( (int) $loan->rider_id );
		$bits = array(
			'asked ' . mysql2date( 'j M', $loan->created_at ),
			$h['count'] ? $h['count'] . ' loan' . ( 1 === $h['count'] ? '' : 's' ) . ', last ' . wp_date( 'M Y', strtotime( $h['last'] ) ) : 'first loan',
		);
		if ( (int) $loan->event_id > 0 ) {
			$bits[] = 'for an event';
		}
		if ( $loan->offer_lapsed_at ) {
			$bits[] = 'missed an offer ' . mysql2date( 'j M', $loan->offer_lapsed_at );
		}
		if ( $r && trim( (string) $r->notes ) ) {
			$bits[] = 'note: ' . wp_trim_words( $r->notes, 10 );
		}
		return '<strong><a href="' . esc_url( admin_url( 'admin.php?page=cc-fleet-loan&id=' . $loan->id ) ) . '">' . esc_html( $r ? $r->name : '?' ) . '</a></strong>' . ( $r && $r->height_cm ? ' · ' . (int) $r->height_cm . ' cm' : '' ) . '<br><small>' . esc_html( implode( ' · ', $bits ) ) . '</small>';
	}

	/** Dashboard: spots open now, offers out, everyone waiting. */
	public static function dashboard_panel() {
		$waiting = self::waiting();
		$offers  = self::offers();
		echo '<h2 id="waitlist">Waiting list</h2>';
		if ( ! $waiting && ! $offers ) {
			echo '<p class="cc-empty">Nobody is waiting.</p>';
			return;
		}
		// Spots someone could have now, per bike.
		$bikes = array();
		foreach ( $waiting as $l ) {
			foreach ( (int) $l->bike_id ? array( (int) $l->bike_id ) : CC_Fleet_Loans::available_bikes( $l->start_date, $l->end_date, $l->requested_size ) as $b ) {
				$bikes[ $b ] = 1;
			}
		}
		foreach ( array_keys( $bikes ) as $b ) {
			$cands = self::candidates( $b );
			if ( ! $cands ) {
				continue;
			}
			$short = self::is_short_notice( $cands[0] );
			echo '<div class="cc-wl-spot"><h3>' . esc_html( CC_Fleet_Bikes::label( $b ) ) . ' is free for ' . count( $cands ) . ' rider' . ( 1 === count( $cands ) ? '' : 's' ) . ' on the list</h3><table class="widefat striped cc-table"><thead><tr><th>#</th><th>Rider</th><th>Dates</th><th>Plan</th><th></th></tr></thead><tbody>';
			foreach ( $cands as $i => $l ) {
				printf( '<tr><td>%d%s</td><td>%s</td><td>%s</td><td>%s</td><td><a class="button %s" href="%s">Offer to %s</a></td></tr>', $i + 1, 0 === $i ? '<br><small>suggested</small>' : '', self::rider_line( $l ), esc_html( self::dates( $l ) ), esc_html( wp_trim_words( (string) $l->plan, 10 ) ), 0 === $i ? 'button-primary' : '', esc_url( self::action_url( 'offer', $l->id, $b ) ), esc_html( self::rider_first( $l ) ) );
			}
			echo '</tbody></table>';
			if ( $short && count( $cands ) > 1 ) {
				printf( '<p>Starts within %d hours. <a class="button" href="%s">Offer to the top %d at once</a> <span class="cc-muted">First yes gets it.</span></p>', self::short_hours(), esc_url( self::action_url( 'offer_short', $cands[0]->id, $b ) ), min( self::short_count(), count( $cands ) ) );
			}
			echo '</div>';
		}
		if ( $offers ) {
			echo '<h3>Offers waiting for an answer</h3><table class="widefat striped cc-table"><thead><tr><th>Rider</th><th>Bike</th><th>Dates</th><th>Answer by</th><th></th></tr></thead><tbody>';
			foreach ( $offers as $o ) {
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="cc-actions">%s</td></tr>', self::rider_line( $o ), esc_html( CC_Fleet_Bikes::label( $o->bike_id ) ), esc_html( self::dates( $o ) ),
					$o->offer_until ? esc_html( mysql2date( 'D j M, H:i', $o->offer_until ) ) . ( $o->offer_group ? '<br><small>short notice, first yes wins</small>' : '' ) : '<em>Email not sent yet</em>', self::buttons( $o ) );
			}
			echo '</tbody></table>';
		}
		if ( $waiting ) {
			echo '<h3>Everyone waiting</h3><table class="widefat striped cc-table"><thead><tr><th>Rider</th><th>Waiting for</th><th>Dates</th><th>Place</th><th></th></tr></thead><tbody>';
			foreach ( $waiting as $l ) {
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td class="cc-actions">%s</td></tr>', self::rider_line( $l ), esc_html( self::bike( $l ) ), esc_html( self::dates( $l ) ), self::position( $l ), self::buttons( $l ) );
			}
			echo '</tbody></table>';
		}
	}
}
