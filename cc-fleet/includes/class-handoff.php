<?php
/**
 * Rider-to-rider handoffs: a bike goes straight from one rider to the next.
 *
 * - The receiving loan stores handoff_from = the giver's loan id. Set when a loan with pick-up
 *   "from the rider before" is confirmed, or with "Hand straight to …" on the giver's loan page.
 * - Linking introduces both riders by email (names, WhatsApp, the swap window, what to check).
 *   Numbers are only shared when both riders agreed to it; otherwise the crew gets both numbers.
 * - "Handed over" closes the giver's loan (returned) and starts the receiver's (out) in one go.
 * - Changed dates or a cancellation that break the chain unlink the pair and tell the crew and,
 *   if still on, the receiving rider.
 *
 * @package cc-fleet
 */

defined( 'ABSPATH' ) || exit;

class CC_Fleet_Handoff {

	public static function init() {
		add_action( 'cc_fleet_loan_status', array( __CLASS__, 'on_status' ), 20, 4 );
		add_action( 'cc_fleet_loan_updated', array( __CLASS__, 'on_updated' ), 20, 2 );
		add_action( 'cc_fleet_email_sent', array( __CLASS__, 'on_email_sent' ) );
		add_action( 'admin_post_cc_fleet_handed_over', array( __CLASS__, 'handle_handed_over' ) );
		add_action( 'admin_post_cc_fleet_handoff_link', array( __CLASS__, 'handle_link' ) );
		add_action( 'admin_post_cc_fleet_handoff_unlink', array( __CLASS__, 'handle_unlink' ) );
	}

	/* ---------- Queries ---------- */

	/** The loan handing its bike to this one, if linked. */
	public static function giver( $loan ) {
		return ! empty( $loan->handoff_from ) ? CC_Fleet_Loans::get( (int) $loan->handoff_from ) : null;
	}

	/** The loan this one hands its bike to, if linked. */
	public static function receiver( $loan ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CC_Fleet_Loans::table() . " WHERE handoff_from = %d AND status IN ('approved','out') ORDER BY start_date ASC LIMIT 1", $loan->id ) );
	}

	/** Can these two be linked: same bike, giver ends right before the receiver starts, both on. */
	public static function fits( $giver, $receiver ) {
		if ( ! $giver || ! $receiver || ! $giver->bike_id || (int) $giver->bike_id !== (int) $receiver->bike_id || (int) $giver->id === (int) $receiver->id ) {
			return false;
		}
		if ( ! in_array( $giver->status, array( 'approved', 'out' ), true ) || ! in_array( $receiver->status, array( 'requested', 'approved' ), true ) ) {
			return false;
		}
		$prev = CC_Fleet_Loans::previous_loan( $receiver );
		return $prev && (int) $prev->id === (int) $giver->id;
	}

	/** Has the rider agreed that we share first name + WhatsApp with the rider they hand to / get from. */
	public static function may_share( $rider ) {
		return $rider && ! empty( $rider->share_ok );
	}

	/* ---------- Link / unlink ---------- */

	public static function link( $receiver_id, $giver_id, $by = 'crew' ) {
		global $wpdb;
		$rec = CC_Fleet_Loans::get( $receiver_id );
		$giv = CC_Fleet_Loans::get( $giver_id );
		if ( ! self::fits( $giv, $rec ) ) {
			return new WP_Error( 'cc_handoff', 'These two bookings don’t follow each other on the same bike.' );
		}
		if ( (int) $rec->handoff_from === (int) $giv->id ) {
			return true;
		}
		$wpdb->update( CC_Fleet_Loans::table(), array( 'handoff_from' => (int) $giv->id, 'pickup_mode' => 'rider' ), array( 'id' => (int) $rec->id ) );
		$gr = CC_Fleet_Riders::get( $giv->rider_id );
		$rr = CC_Fleet_Riders::get( $rec->rider_id );
		CC_Fleet_Install::log( 'loan', $rec->id, 'handoff linked', 'from ' . ( $gr->name ?? '' ) . ' (loan ' . $giv->id . ')' );
		CC_Fleet_Install::log( 'loan', $giv->id, 'handoff linked', 'to ' . ( $rr->name ?? '' ) . ' (loan ' . $rec->id . ')' );
		if ( 'approved' === $rec->status ) {
			self::maybe_introduce( CC_Fleet_Loans::get( $rec->id ) );
		}
		return true;
	}

	public static function unlink( $receiver, $why = '' ) {
		global $wpdb;
		if ( ! $receiver || empty( $receiver->handoff_from ) ) {
			return;
		}
		$giv = CC_Fleet_Loans::get( (int) $receiver->handoff_from );
		$wpdb->update( CC_Fleet_Loans::table(), array( 'handoff_from' => 0, 'pickup_mode' => '' ), array( 'id' => (int) $receiver->id ) );
		CC_Fleet_Install::log( 'loan', $receiver->id, 'handoff unlinked', $why );
		if ( $giv ) {
			CC_Fleet_Install::log( 'loan', $giv->id, 'handoff unlinked', $why );
		}
		$rr = CC_Fleet_Riders::get( $receiver->rider_id );
		$gr = $giv ? CC_Fleet_Riders::get( $giv->rider_id ) : null;
		CC_Fleet_Emails::wl_crew_note(
			'Handoff off: ' . CC_Fleet_Bikes::label( $receiver->bike_id ),
			'The rider-to-rider handoff is off',
			array_filter( array(
				CC_Fleet_Bikes::label( $receiver->bike_id ) . ': ' . ( $gr->name ?? 'rider before' ) . ' → ' . ( $rr->name ?? 'next rider' ),
				$why,
				'approved' === $receiver->status ? 'Set a pick-up place for ' . ( $rr->name ?? 'the next rider' ) . ' on their loan page.' : '',
			) ),
			admin_url( 'admin.php?page=cc-fleet-loan&id=' . $receiver->id ), 'Open the loan'
		);
		if ( 'approved' === $receiver->status && $rr && $rr->email ) {
			CC_Fleet_Emails::handoff_off( $receiver, $rr );
		}
	}

	/* ---------- Hooks ---------- */

	public static function on_status( $id, $status, $old, $notify ) {
		$loan = CC_Fleet_Loans::get( $id );
		if ( ! $loan ) {
			return;
		}
		// Confirmed with pick-up "from the rider before": link them.
		if ( 'approved' === $status && $loan->bike_id && empty( $loan->handoff_from ) ) {
			$p = CC_Fleet_Loans::pickup( $loan );
			if ( 'rider' === $p['mode'] && $p['prev'] ) {
				self::link( $loan->id, $p['prev']->id, 'confirm' );
			}
		}
		if ( in_array( $status, array( 'cancelled', 'declined' ), true ) ) {
			if ( ! empty( $loan->handoff_from ) ) {
				self::unlink( $loan, ( CC_Fleet_Riders::get( $loan->rider_id )->name ?? 'The next rider' ) . '’s booking was ' . $status . '.' );
			}
			$rec = self::receiver( $loan );
			if ( $rec ) {
				self::unlink( $rec, ( CC_Fleet_Riders::get( $loan->rider_id )->name ?? 'The rider before' ) . '’s booking was ' . $status . ', so the bike won’t come from them.' );
			}
		}
	}

	public static function on_updated( $id, $before ) {
		$loan = CC_Fleet_Loans::get( $id );
		if ( ! $loan || ( $before && $before->start_date === $loan->start_date && $before->end_date === $loan->end_date && (int) $before->bike_id === (int) $loan->bike_id ) ) {
			return;
		}
		if ( ! empty( $loan->handoff_from ) && ! self::fits( CC_Fleet_Loans::get( (int) $loan->handoff_from ), $loan ) ) {
			self::unlink( $loan, 'The dates or bike changed, so the two bookings no longer follow each other.' );
		}
		global $wpdb;
		$recs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CC_Fleet_Loans::table() . ' WHERE handoff_from = %d', $loan->id ) );
		foreach ( $recs as $rec ) {
			if ( ! self::fits( $loan, $rec ) ) {
				self::unlink( $rec, 'The dates or bike changed, so the two bookings no longer follow each other.' );
			}
		}
	}

	/* ---------- Emails ---------- */

	/**
	 * Introduce them now, unless the receiver's confirmation is still waiting for the crew's review:
	 * then the intro follows right after that confirmation is sent.
	 */
	public static function maybe_introduce( $rec ) {
		global $wpdb;
		if ( ! $rec || empty( $rec->handoff_from ) || 'approved' !== $rec->status ) {
			return;
		}
		$done = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE object_type = 'loan' AND object_id = %d AND action = 'handoff intro sent' AND message LIKE %s", $rec->id, 'from loan ' . (int) $rec->handoff_from . '%' ) );
		if ( $done ) {
			return;
		}
		if ( class_exists( 'CC_Fleet_Outbox' ) && CC_Fleet_Outbox::should_hold( array( 'type' => 'approved', 'loan' => $rec ) ) ) {
			$open = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CC_Fleet_Outbox::table() . " WHERE loan_id = %d AND type = 'approved' AND status IN ('draft','scheduled')", $rec->id ) );
			if ( $open ) {
				return; // on_email_sent picks it up.
			}
		}
		$giv = CC_Fleet_Loans::get( (int) $rec->handoff_from );
		if ( $giv ) {
			self::introduce( $rec, $giv );
			CC_Fleet_Install::log( 'loan', $rec->id, 'handoff intro sent', 'from loan ' . $giv->id );
		}
	}

	public static function on_email_sent( $row ) {
		if ( 'approved' === ( $row->type ?? '' ) && ! empty( $row->loan_id ) ) {
			self::maybe_introduce( CC_Fleet_Loans::get( (int) $row->loan_id ) );
		}
	}

	/** Both riders: who, when, how to reach each other, what to check. */
	public static function introduce( $rec, $giv ) {
		$rr = CC_Fleet_Riders::get( $rec->rider_id );
		$gr = CC_Fleet_Riders::get( $giv->rider_id );
		if ( ! $rr || ! $gr ) {
			return;
		}
		$share = self::may_share( $rr ) && self::may_share( $gr );
		$bike  = CC_Fleet_Bikes::public_label( $rec->bike_id );
		$check = array_filter( array(
			class_exists( 'CC_Fleet_Pressure' ) ? CC_Fleet_Pressure::line_for_loan( $rec ) : '',
			CC_Fleet_Loans::pedals_label( $rec ) ? strtok( $rr->name, ' ' ) . ': ' . strtolower( CC_Fleet_Loans::pedals_label( $rec ) ) : '',
		) );
		$window = CC_Fleet_Emails::nice_date( $giv->end_date ) . ( $giv->end_date !== $rec->start_date ? ' – ' . CC_Fleet_Emails::nice_date( $rec->start_date ) : '' );
		CC_Fleet_Emails::handoff_intro( $gr, $rr, $giv, 'give', $bike, $window, $check, $share );
		CC_Fleet_Emails::handoff_intro( $rr, $gr, $rec, 'get', $bike, $window, $check, $share );
		if ( ! $share ) {
			CC_Fleet_Emails::wl_crew_note(
				'Connect them: ' . $bike, 'Put these two in touch',
				array( $bike . ': ' . $gr->name . ' (' . $gr->phone . ') → ' . $rr->name . ' (' . $rr->phone . ')', 'Swap: ' . $window, 'One of them hasn’t agreed to share their number yet, so the intro email didn’t include it. A quick WhatsApp group does the job.' ),
				admin_url( 'admin.php?page=cc-fleet-loan&id=' . $rec->id ), 'Open the loan'
			);
		}
	}

	/* ---------- Admin ---------- */

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_cc_fleet' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( $fallback, $msg, $type = 'success' ) {
		set_transient( 'cc_fleet_notice_' . get_current_user_id(), array( $msg, $type ), 60 );
		$ref = wp_get_referer();
		wp_safe_redirect( $ref ? $ref : $fallback );
		exit;
	}

	/** Giver loan id → giver returned + receiver out. */
	public static function handle_handed_over() {
		$id = (int) ( $_POST['id'] ?? $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		self::guard( 'cc_fleet_handed_over_' . $id );
		$giv = CC_Fleet_Loans::get( $id );
		$rec = $giv ? self::receiver( $giv ) : null;
		$url = admin_url( 'admin.php?page=cc-fleet' );
		if ( ! $giv || ! $rec ) {
			self::back( $url, 'That handoff isn’t linked any more.', 'warning' );
		}
		CC_Fleet_Loans::set_status( $giv->id, 'returned', true, true );
		CC_Fleet_Loans::set_status( $rec->id, 'out', false, true );
		CC_Fleet_Install::log( 'loan', $giv->id, 'handed over', 'to loan ' . $rec->id );
		CC_Fleet_Install::log( 'loan', $rec->id, 'handed over', 'from loan ' . $giv->id );
		$gr = CC_Fleet_Riders::get( $giv->rider_id );
		$rr = CC_Fleet_Riders::get( $rec->rider_id );
		self::back( $url, 'Handed over: ' . CC_Fleet_Bikes::label( $giv->bike_id ) . ' is now with ' . ( $rr->name ?? 'the next rider' ) . ', and ' . ( $gr->name ?? 'the rider before' ) . '’s loan is closed.' );
	}

	public static function handle_link() {
		$id = (int) ( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		self::guard( 'cc_fleet_handoff_link_' . $id );
		$giv = CC_Fleet_Loans::get( $id );
		$nxt = $giv ? CC_Fleet_Loans::next_loan( $giv ) : null;
		$url = admin_url( 'admin.php?page=cc-fleet-loan&id=' . $id );
		$res = $nxt ? self::link( $nxt->id, $giv->id, 'crew' ) : new WP_Error( 'cc', 'No confirmed booking follows this one on the same bike.' );
		if ( is_wp_error( $res ) ) {
			self::back( $url, $res->get_error_message(), 'error' );
		}
		self::back( $url, 'Linked. Both riders got an introduction.' );
	}

	public static function handle_unlink() {
		$id = (int) ( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		self::guard( 'cc_fleet_handoff_unlink_' . $id );
		$rec = CC_Fleet_Loans::get( $id );
		self::unlink( $rec, 'Unlinked by the crew.' );
		self::back( admin_url( 'admin.php?page=cc-fleet-loan&id=' . $id ), 'Handoff unlinked. Set a pick-up place for this rider.' );
	}

	public static function handed_over_button( $giver, $small = true ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">' . wp_nonce_field( 'cc_fleet_handed_over_' . $giver->id, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="cc_fleet_handed_over"><input type="hidden" name="id" value="' . (int) $giver->id . '">'
			. '<button class="button button-primary' . ( $small ? ' button-small' : '' ) . '" onclick="return confirm(\'Bike handed over? This closes the first loan and marks the bike out with the next rider.\')">Handed over</button></form>';
	}

	/** Dashboard: linked pairs whose swap is within two weeks. */
	public static function dashboard_panel() {
		global $wpdb;
		$today = CC_Fleet_Settings::today();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			'SELECT r.id AS rid, g.id AS gid FROM ' . CC_Fleet_Loans::table() . ' r JOIN ' . CC_Fleet_Loans::table() . " g ON g.id = r.handoff_from
			 WHERE r.status = 'approved' AND g.status IN ('approved','out') AND g.end_date <= %s ORDER BY g.end_date ASC LIMIT 30",
			CC_Fleet_Loans::add_days( $today, 14 )
		) );
		if ( ! $rows ) {
			return;
		}
		echo '<h2>Rider-to-rider handoffs</h2><table class="widefat striped cc-due"><tbody>';
		foreach ( $rows as $row ) {
			$g  = CC_Fleet_Loans::get( $row->gid );
			$r  = CC_Fleet_Loans::get( $row->rid );
			$gr = CC_Fleet_Riders::get( $g->rider_id );
			$rr = CC_Fleet_Riders::get( $r->rider_id );
			$wa = function ( $x ) {
				return $x && $x->phone && CC_Fleet_Phone::wa_link( $x->phone ) ? ' · <a href="' . esc_url( CC_Fleet_Phone::wa_link( $x->phone ) ) . '" target="_blank" rel="noopener">WhatsApp</a>' : '';
			};
			printf(
				'<tr><td><strong>%s</strong></td><td><a href="%s">%s</a>%s → <a href="%s">%s</a>%s</td><td>Swap %s</td><td>%s</td></tr>',
				esc_html( CC_Fleet_Bikes::label( $g->bike_id ) ),
				esc_url( admin_url( 'admin.php?page=cc-fleet-loan&id=' . $g->id ) ), esc_html( $gr->name ?? 'Rider' ), $wa( $gr ), // phpcs:ignore
				esc_url( admin_url( 'admin.php?page=cc-fleet-loan&id=' . $r->id ) ), esc_html( $rr->name ?? 'Rider' ), $wa( $rr ), // phpcs:ignore
				esc_html( CC_Fleet_Emails::nice_date( $g->end_date ) . ( $g->end_date !== $r->start_date ? ' – ' . CC_Fleet_Emails::nice_date( $r->start_date ) : '' ) ),
				'out' === $g->status ? self::handed_over_button( $g ) : '<span class="cc-muted">Once ' . esc_html( strtok( $gr->name ?? 'the rider', ' ' ) ) . ' has it</span>' // phpcs:ignore
			);
		}
		echo '</tbody></table>';
	}

	/** Loan page: who it comes from / goes to, with link, unlink and handed over. */
	public static function loan_panel( $loan ) {
		if ( ! $loan->bike_id ) {
			return;
		}
		$giv = self::giver( $loan );
		$rec = self::receiver( $loan );
		$nxt = $rec ? null : CC_Fleet_Loans::next_loan( $loan );
		if ( ! $giv && ! $rec && ! ( $nxt && self::fits( $loan, $nxt ) && empty( $nxt->handoff_from ) ) ) {
			return;
		}
		echo '<div class="cc-card" id="rider-handoff"><h2>Rider-to-rider handoff</h2>';
		if ( $giv ) {
			$gr = CC_Fleet_Riders::get( $giv->rider_id );
			printf( '<p>Gets the bike straight from <a href="%s"><strong>%s</strong></a> (until %s).</p>', esc_url( admin_url( 'admin.php?page=cc-fleet-loan&id=' . $giv->id ) ), esc_html( $gr->name ?? 'the rider before' ), esc_html( CC_Fleet_Emails::nice_date( $giv->end_date ) ) );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'cc_fleet_handoff_unlink_' . $loan->id, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="cc_fleet_handoff_unlink"><input type="hidden" name="id" value="' . (int) $loan->id . '"><button class="button">Unlink (collect elsewhere)</button></form>';
		}
		if ( $rec ) {
			$rr = CC_Fleet_Riders::get( $rec->rider_id );
			printf( '<p>Hands the bike straight to <a href="%s"><strong>%s</strong></a> (from %s).</p>', esc_url( admin_url( 'admin.php?page=cc-fleet-loan&id=' . $rec->id ) ), esc_html( $rr->name ?? 'the next rider' ), esc_html( CC_Fleet_Emails::nice_date( $rec->start_date ) ) );
			echo 'out' === $loan->status ? '<p>' . self::handed_over_button( $loan, false ) . ' <span class="cc-muted">Closes this loan and marks the bike out with ' . esc_html( strtok( $rr->name ?? 'them', ' ' ) ) . '.</span></p>' : '<p class="cc-muted">“Handed over” shows here once this rider has the bike.</p>'; // phpcs:ignore
		}
		if ( $nxt && ! $rec && empty( $nxt->handoff_from ) && self::fits( $loan, $nxt ) ) {
			$nr = CC_Fleet_Riders::get( $nxt->rider_id );
			printf( '<p><strong>%s</strong> has this bike next, from %s.</p>', esc_html( $nr->name ?? 'Someone' ), esc_html( CC_Fleet_Emails::nice_date( $nxt->start_date ) ) );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'cc_fleet_handoff_link_' . $loan->id, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="cc_fleet_handoff_link"><input type="hidden" name="id" value="' . (int) $loan->id . '"><button class="button button-primary">Hand straight to ' . esc_html( strtok( $nr->name ?? 'them', ' ' ) ) . '</button> <span class="cc-muted">Both get an intro email.</span></form>';
		}
		echo '</div>';
	}
}
