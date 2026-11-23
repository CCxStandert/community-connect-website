<?php
defined( 'ABSPATH' ) || exit;

/**
 * Daily jobs: auto-start approved loans, return reminders, overdue digest.
 * On Host Europe, trigger WordPress cron from a real server cron job (see README) so it runs even without visitors.
 */
class CC_Fleet_Cron {

	const HOOK = 'cc_fleet_daily';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$tomorrow_7am = ( new DateTimeImmutable( 'tomorrow 07:00', wp_timezone() ) )->getTimestamp();
			wp_schedule_event( $tomorrow_7am, 'daily', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** @return array summary, for logs and tests */
	public static function run() {
		$today   = CC_Fleet_Settings::today();
		$summary = array( 'started' => 0, 'reminders' => 0, 'overdue' => 0 );

		// 1. Approved loans whose start day has come are now out (the handoff happens on the day).
		foreach ( CC_Fleet_Loans::query( array( 'status' => 'approved', 'limit' => 500, 'order' => 'ASC' ) ) as $l ) {
			if ( $l->start_date <= $today ) {
				if ( true === CC_Fleet_Loans::set_status( $l->id, 'out', false ) ) {
					$summary['started']++;
				}
			}
		}

		// Workshop: bikes with work due today go into the workshop.
		CC_Fleet_Records::daily();

		// 2. Return reminders.
		$days = max( 0, (int) CC_Fleet_Settings::get( 'reminder_days_before' ) );
		$due  = CC_Fleet_Loans::add_days( $today, $days );
		foreach ( CC_Fleet_Loans::query( array( 'status' => 'out', 'limit' => 500 ) ) as $l ) {
			// Due on the reminder day, or a missed one (end date still to come): it goes out now.
			if ( ! $l->reminder_sent && $l->end_date <= $due && $l->end_date >= $today ) {
				if ( CC_Fleet_Emails::return_reminder( $l ) ) {
					global $wpdb;
					$wpdb->update( CC_Fleet_Loans::table(), array( 'reminder_sent' => 1 ), array( 'id' => $l->id ) );
					$summary['reminders']++;
					CC_Fleet_Install::log( 'loan', $l->id, 'email sent', 'reminder' );
				}
			}
		}

		// 2b. The day before reminders go out: the crew checks handoff details with both riders.
		$check = CC_Fleet_Loans::add_days( $today, $days + 1 );
		$todo  = array();
		foreach ( CC_Fleet_Loans::query( array( 'status' => array( 'approved', 'out' ), 'limit' => 500 ) ) as $l ) {
			if ( $l->end_date === $check && ! $l->reminder_sent && empty( $l->check_sent ) ) {
				$todo[] = $l;
			}
		}
		$summary['checks'] = count( $todo );
		if ( $todo && CC_Fleet_Emails::crew_check( $todo ) ) {
			global $wpdb;
			foreach ( $todo as $l ) {
				$wpdb->update( CC_Fleet_Loans::table(), array( 'check_sent' => 1 ), array( 'id' => $l->id ) );
			}
		}

		// 3. Digest for the crew: overdue, upcoming handoffs, pending requests.
		$overdue  = CC_Fleet_Loans::query( array( 'overdue' => true, 'limit' => 200 ) );
		$starting = CC_Fleet_Loans::query( array( 'status' => 'approved', 'from' => $today, 'to' => CC_Fleet_Loans::add_days( $today, 3 ), 'limit' => 200, 'order' => 'ASC' ) );
		$starting = array_filter( $starting, function ( $l ) use ( $today ) { return $l->start_date >= $today; } );
		$pending  = CC_Fleet_Loans::query( array( 'status' => 'requested', 'limit' => 200, 'order' => 'ASC', 'orderby' => 'created_at' ) );
		$summary['overdue'] = count( $overdue );
		CC_Fleet_Emails::daily_digest( $overdue, array_values( $starting ), $pending );

		CC_Fleet_Install::log( 'cron', 0, 'daily', wp_json_encode( $summary ) );
		return $summary;
	}
}
