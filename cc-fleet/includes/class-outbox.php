<?php
defined( 'ABSPATH' ) || exit;

/**
 * Rider emails the crew can read and change before they go out.
 *
 * With "Review rider emails before sending" on (Settings), an approve, decline, cancel,
 * return or date change doesn't email the rider straight away: the email is kept as a draft
 * and the crew member lands on a compose screen with the subject and text editable and a
 * preview. Send, or don't email at all.
 *
 * Return reminders go out on their own from the daily job. The crew can open one ahead of
 * time (from the day-before check email or the loan page), edit it and save it: the daily
 * job then sends that version instead.
 */
class CC_Fleet_Outbox {

	// Waiting list: the crew checks an offer they make and a request they move to the list.
	// Offers that move on by themselves, and answers to the rider's own clicks, go straight out.
	const REVIEW_TYPES = array( 'approved', 'declined', 'cancelled', 'returned', 'dates_changed', 'wl_offer', 'wl_joined' );
	const LABELS       = array(
		'approved' => 'Confirmation', 'declined' => 'Not this time', 'cancelled' => 'Cancelled', 'returned' => 'Thanks for returning',
		'dates_changed' => 'New dates', 'reminder' => 'Return reminder', 'wl_offer' => 'Waiting list offer', 'wl_joined' => 'On the waiting list',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_cc_fleet_email_draft', array( __CLASS__, 'handle_draft' ) );
		add_action( 'admin_post_cc_fleet_email_prepare', array( __CLASS__, 'handle_prepare' ) );
		add_action( 'admin_head', function () {
			echo '<style>#adminmenu a[href$="page=cc-fleet-email"]{display:none}</style>';
		} );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'cc_fleet_outbox';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			loan_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(30) NOT NULL DEFAULT '',
			to_email varchar(190) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			msg longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			sent_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY loan_type (loan_id,type),
			KEY status (status)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	public static function menu() {
		add_submenu_page( 'cc-fleet', 'Email to rider', '', CC_Fleet_Admin::CAP, 'cc-fleet-email', array( __CLASS__, 'page' ) );
	}

	/* ---------------- Holding ---------------- */

	public static function should_hold( array $context ) {
		return ! empty( CC_Fleet_Settings::get( 'review_rider_emails' ) )
			&& in_array( $context['type'] ?? '', self::REVIEW_TYPES, true );
	}

	/** Keep an email as a draft. One open draft per loan and kind: a newer one replaces it. */
	public static function hold( $to, $subject, array $msg, array $context ) {
		global $wpdb;
		$loan_id = ! empty( $context['loan'] ) ? (int) $context['loan']->id : 0;
		$type    = (string) ( $context['type'] ?? '' );
		$status  = $context['status'] ?? 'draft';
		if ( $loan_id ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'replaced', updated_at = %s WHERE loan_id = %d AND type = %s AND status IN ('draft','scheduled')", current_time( 'mysql' ), $loan_id, $type ) );
		}
		$now = current_time( 'mysql' );
		$wpdb->insert( self::table(), array(
			'loan_id' => $loan_id, 'type' => $type, 'to_email' => is_array( $to ) ? implode( ',', $to ) : (string) $to, 'subject' => $subject,
			'msg' => wp_json_encode( $msg ), 'status' => $status, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
		) );
		$id = (int) $wpdb->insert_id;
		CC_Fleet_Install::log( 'loan', $loan_id, 'email held', $type );
		return $id;
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		if ( $row ) {
			$row->msg = json_decode( (string) $row->msg, true ) ?: array();
		}
		return $row;
	}

	public static function waiting() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT id, loan_id, type, to_email, subject, created_at FROM ' . self::table() . " WHERE status = 'draft' ORDER BY created_at ASC LIMIT 50" );
	}

	/** An edited reminder saved for the daily job, if there is one. */
	public static function scheduled( $loan_id, $type = 'reminder' ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE loan_id = %d AND type = %s AND status = 'scheduled' ORDER BY id DESC LIMIT 1", $loan_id, $type ) );
		return $id ? self::get( $id ) : null;
	}

	public static function mark( $id, $status ) {
		global $wpdb;
		$data = array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) );
		if ( 'sent' === $status ) {
			$data['sent_at'] = current_time( 'mysql' );
		}
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	public static function send_row( $row ) {
		$loan = $row->loan_id ? CC_Fleet_Loans::get( $row->loan_id ) : null;
		$sent = CC_Fleet_Emails::send_now( $row->to_email, $row->subject, $row->msg, array( 'type' => $row->type, 'loan' => $loan, 'edited' => true ) );
		if ( $sent ) {
			self::mark( $row->id, 'sent' );
			CC_Fleet_Install::log( 'loan', (int) $row->loan_id, 'email sent', $row->type );
			do_action( 'cc_fleet_email_sent', $row );
		}
		return $sent;
	}

	/* ---------------- Editing ---------------- */

	/** The parts of a message a person can rewrite: headline, subline and the text paragraphs. */
	private static function text_blocks( array $msg ) {
		$out = array();
		foreach ( (array) ( $msg['blocks'] ?? array() ) as $i => $b ) {
			if ( in_array( $b[0] ?? '', array( 'p', 'small' ), true ) && is_string( $b[1] ?? null ) ) {
				$out[ $i ] = $b;
			}
		}
		return $out;
	}

	private static function apply_edits( array $msg, array $in ) {
		foreach ( array( 'headline', 'subline' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$msg[ $k ] = sanitize_text_field( $in[ $k ] );
			}
		}
		foreach ( self::text_blocks( $msg ) as $i => $b ) {
			if ( isset( $in['block'][ $i ] ) ) {
				$text = trim( sanitize_textarea_field( $in['block'][ $i ] ) );
				if ( '' === $text ) {
					unset( $msg['blocks'][ $i ] );
				} else {
					$msg['blocks'][ $i ][1] = $text;
				}
			}
		}
		// The extra note sits just above the buttons, in the pink callout style.
		$note = trim( sanitize_textarea_field( $in['note'] ?? '' ) );
		$msg['blocks'] = array_values( array_filter( $msg['blocks'], function ( $b ) { return empty( $b['_note'] ); } ) );
		if ( '' !== $note ) {
			$pos = count( $msg['blocks'] );
			foreach ( $msg['blocks'] as $i => $b ) {
				if ( 'buttons' === $b[0] ) {
					$pos = $i;
					break;
				}
			}
			array_splice( $msg['blocks'], $pos, 0, array( array( 'callout', array( $note ), 'pink', '_note' => 1 ) ) );
		}
		return $msg;
	}

	private static function current_note( array $msg ) {
		foreach ( (array) ( $msg['blocks'] ?? array() ) as $b ) {
			if ( ! empty( $b['_note'] ) ) {
				return (string) ( $b[1][0] ?? '' );
			}
		}
		return '';
	}

	public static function compose_url( $id, $back = '' ) {
		return add_query_arg( array_filter( array( 'page' => 'cc-fleet-email', 'id' => (int) $id, 'back' => $back ? rawurlencode( $back ) : '' ) ), admin_url( 'admin.php' ) );
	}

	public static function prepare_url( $loan_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=cc_fleet_email_prepare&loan=' . (int) $loan_id ), 'cc_fleet_email_prepare_' . (int) $loan_id );
	}

	public static function page() {
		$id  = (int) ( $_GET['id'] ?? 0 );
		$row = $id ? self::get( $id ) : null;
		echo '<div class="wrap cc-wrap cc-compose"><h1>Email to the rider</h1>';
		if ( ! $row ) {
			echo '<p>That email isn’t here any more.</p></div>';
			return;
		}
		$back = isset( $_GET['back'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['back'] ) ) ) : ( $row->loan_id ? admin_url( 'admin.php?page=cc-fleet-loan&id=' . $row->loan_id ) : admin_url( 'admin.php?page=cc-fleet' ) );
		$n    = get_transient( 'cc_fleet_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'cc_fleet_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $n[1] ), wp_kses_post( $n[0] ) );
		}
		$loan  = $row->loan_id ? CC_Fleet_Loans::get( $row->loan_id ) : null;
		$label = self::LABELS[ $row->type ] ?? $row->type;
		$open  = in_array( $row->status, array( 'draft', 'scheduled' ), true );
		if ( 'reminder' === $row->type && $loan ) {
			$days = max( 0, (int) CC_Fleet_Settings::get( 'reminder_days_before' ) );
			$goes = CC_Fleet_Loans::add_days( $loan->end_date, -$days );
			echo '<p class="cc-muted">' . esc_html( $label ) . ' for loan #' . (int) $loan->id . '. It goes out by itself on the morning of <strong>' . esc_html( CC_Fleet_Emails::nice_date( $goes ) ) . '</strong>. Save your changes and that version is sent instead.</p>';
		} else {
			echo '<p class="cc-muted">' . esc_html( $label ) . ( $loan ? ' for loan #' . (int) $loan->id : '' ) . '. Nothing has gone out yet. Change what you like, then send it.</p>';
		}
		if ( ! $open ) {
			echo '<div class="notice notice-info inline"><p>This email is <strong>' . esc_html( $row->status ) . '</strong>' . ( $row->sent_at ? ' (' . esc_html( $row->sent_at ) . ')' : '' ) . '.</p></div>';
		}
		echo '<div class="cc-compose-grid"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cc-card">';
		wp_nonce_field( 'cc_fleet_email_draft_' . $row->id );
		printf( '<input type="hidden" name="action" value="cc_fleet_email_draft"><input type="hidden" name="id" value="%d"><input type="hidden" name="back" value="%s">', (int) $row->id, esc_attr( $back ) );
		if ( $open && 'approved' === $row->type && $loan && 'approved' === $loan->status ) {
			$asked = $row->msg['_asked'] ?? null;
			echo '<div class="cc-dates-box"><p class="cc-dates-box__h"><strong>Dates</strong>' . ( $asked ? ' <span class="cc-muted">· the rider asked for ' . esc_html( CC_Fleet_Emails::nice_date( $asked[0] ) . ' → ' . CC_Fleet_Emails::nice_date( $asked[1] ) ) . '</span>' : ' <span class="cc-muted">· confirm fewer days than asked? Change them here before you send.</span>' ) . '</p>';
			printf( '<p class="cc-dates-box__row"><label>From<br><input type="date" name="start_date" value="%s" min="%s"></label> <label>Until<br><input type="date" name="end_date" value="%s" min="%s"></label> <button class="button" name="op" value="dates">Change the dates</button></p>',
				esc_attr( $loan->start_date ), esc_attr( CC_Fleet_Settings::today() ), esc_attr( $loan->end_date ), esc_attr( CC_Fleet_Settings::today() ) );
			echo '<p class="cc-dates-box__f"><label><input type="checkbox" name="force" value="1"> Save even if it overlaps another loan</label></p>';
			echo '<p class="cc-muted cc-dates-box__f">The loan, the calendar and the email’s details, calendar link and return date all follow. Your changes to the text below are kept.</p></div>';
		}
		printf( '<p><label>To<br><input type="email" name="to_email" value="%s" class="widefat" required %s></label></p>', esc_attr( $row->to_email ), $open ? '' : 'disabled' );
		printf( '<p><label>Subject<br><input type="text" name="subject" value="%s" class="widefat" required %s></label></p>', esc_attr( $row->subject ), $open ? '' : 'disabled' );
		printf( '<p><label>Headline<br><input type="text" name="headline" value="%s" class="widefat" %s></label></p>', esc_attr( $row->msg['headline'] ?? '' ), $open ? '' : 'disabled' );
		printf( '<p><label>Line under the headline<br><input type="text" name="subline" value="%s" class="widefat" %s></label></p>', esc_attr( $row->msg['subline'] ?? '' ), $open ? '' : 'disabled' );
		$k = 0;
		foreach ( self::text_blocks( $row->msg ) as $i => $b ) {
			$k++;
			$rows = max( 3, min( 10, substr_count( $b[1], "\n" ) + (int) ( strlen( $b[1] ) / 70 ) + 2 ) );
			printf( '<p><label>%s<br><textarea name="block[%d]" rows="%d" class="widefat" %s>%s</textarea></label></p>', 'small' === $b[0] ? 'Small print' : ( 1 === $k ? 'Message' : 'Paragraph ' . $k ), (int) $i, (int) $rows, $open ? '' : 'disabled', esc_textarea( $b[1] ) );
		}
		printf( '<p><label>Extra note (optional, shown in a pink box above the buttons)<br><textarea name="note" rows="3" class="widefat" placeholder="e.g. Sipho will meet you at the Woodstock Cycleworks door at 8:00." %s>%s</textarea></label></p>', $open ? '' : 'disabled', esc_textarea( self::current_note( $row->msg ) ) );
		echo '<p class="cc-muted">The details box, checklist and buttons come from the loan and stay as they are. Empty a paragraph to leave it out.</p>';
		if ( $open ) {
			echo '<p class="cc-compose-actions">';
			if ( 'reminder' === $row->type ) {
				echo '<button class="button button-primary" name="op" value="schedule">Save for the reminder</button> <button class="button" name="op" value="preview">Update preview</button> <button class="button" name="op" value="send">Send it now instead</button> <button class="button-link cc-danger" name="op" value="skip">Use the standard reminder</button>';
			} else {
				echo '<button class="button button-primary" name="op" value="send">Send to rider</button> <button class="button" name="op" value="preview">Update preview</button> <button class="button-link cc-danger" name="op" value="skip" onclick="return confirm(\'Don’t email the rider about this?\')">Don’t email</button>';
			}
			echo '</p>';
		}
		echo '<p><a href="' . esc_url( $back ) . '">← Back</a></p></form>';
		echo '<div class="cc-compose-preview"><p class="cc-muted">Preview</p><iframe title="Email preview" srcdoc="' . esc_attr( CC_Fleet_Emails::render_html( $row->msg, $row->subject ) ) . '"></iframe></div></div></div>';
	}

	public static function handle_draft() {
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( ! current_user_can( CC_Fleet_Admin::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'cc_fleet_email_draft_' . $id );
		$row  = self::get( $id );
		$in   = wp_unslash( $_POST );
		$back = esc_url_raw( $in['back'] ?? '' ) ?: admin_url( 'admin.php?page=cc-fleet' );
		if ( ! $row || ! in_array( $row->status, array( 'draft', 'scheduled' ), true ) ) {
			self::notice( 'That email was already dealt with.', 'warning' );
			wp_safe_redirect( $back );
			exit;
		}
		$op  = sanitize_key( $in['op'] ?? 'preview' );
		$msg = self::apply_edits( $row->msg, $in );
		$to  = sanitize_email( $in['to_email'] ?? $row->to_email ) ?: $row->to_email;
		global $wpdb;
		$wpdb->update( self::table(), array(
			'to_email' => $to, 'subject' => sanitize_text_field( $in['subject'] ?? $row->subject ) ?: $row->subject,
			'msg' => wp_json_encode( $msg ), 'updated_at' => current_time( 'mysql' ),
		), array( 'id' => $id ) );
		$row = self::get( $id );
		switch ( $op ) {
			case 'dates':
				self::change_dates( $row, $in, $back );
				exit;
			case 'send':
				if ( self::send_row( $row ) ) {
					if ( 'reminder' === $row->type && $row->loan_id ) {
						$wpdb->update( CC_Fleet_Loans::table(), array( 'reminder_sent' => 1 ), array( 'id' => (int) $row->loan_id ) );
					}
					self::notice( 'Sent to ' . esc_html( $row->to_email ) . '.' );
					wp_safe_redirect( $back );
				} else {
					self::notice( 'The email didn’t go out. Check the mail settings (WP Mail SMTP) and try again.', 'error' );
					wp_safe_redirect( self::compose_url( $id, $back ) );
				}
				exit;
			case 'schedule':
				self::mark( $id, 'scheduled' );
				self::notice( 'Saved. This version goes out with the reminder.' );
				wp_safe_redirect( $back );
				exit;
			case 'skip':
				self::mark( $id, 'skipped' );
				CC_Fleet_Install::log( 'loan', (int) $row->loan_id, 'email skipped', $row->type );
				do_action( 'cc_fleet_email_skipped', $row );
				self::notice( 'reminder' === $row->type ? 'The standard reminder will go out as usual.' : ( 'wl_offer' === $row->type ? 'OK, no offer sent. The rider is back on the waiting list.' : 'OK, the rider wasn’t emailed.' ) );
				wp_safe_redirect( $back );
				exit;
			default:
				self::notice( 'Preview updated. Nothing sent yet.', 'info' );
				wp_safe_redirect( self::compose_url( $id, $back ) );
				exit;
		}
	}

	/**
	 * Confirm other dates than the rider asked for: update the loan, then rebuild the confirmation
	 * with the new dates, keeping the crew's text (headline, line, paragraphs, note).
	 */
	private static function change_dates( $row, array $in, $back ) {
		global $wpdb;
		$here = self::compose_url( $row->id, $back );
		$loan = $row->loan_id ? CC_Fleet_Loans::get( $row->loan_id ) : null;
		if ( 'approved' !== $row->type || ! $loan || 'approved' !== $loan->status ) {
			self::notice( 'The dates can only be changed here before a confirmed bike goes out.', 'warning' );
			wp_safe_redirect( $here );
			exit;
		}
		$start = sanitize_text_field( $in['start_date'] ?? '' );
		$end   = sanitize_text_field( $in['end_date'] ?? '' );
		if ( $start === $loan->start_date && $end === $loan->end_date ) {
			self::notice( 'Those are already the dates.', 'info' );
			wp_safe_redirect( $here );
			exit;
		}
		$res = CC_Fleet_Loans::update( $loan->id, array( 'start_date' => $start, 'end_date' => $end ), ! empty( $in['force'] ) );
		if ( is_wp_error( $res ) ) {
			$msg = esc_html( $res->get_error_message() );
			foreach ( (array) ( $res->get_error_data()['conflicts'] ?? array() ) as $cid ) {
				$c   = CC_Fleet_Loans::get( $cid );
				$cr  = $c ? CC_Fleet_Riders::get( $c->rider_id ) : null;
				$msg .= sprintf( '<br>Clashes with %s, %s → %s. Pick other dates, or tick “Save even if it overlaps”.', esc_html( $cr->name ?? 'another rider' ), esc_html( $c->start_date ?? '' ), esc_html( $c->end_date ?? '' ) );
			}
			self::notice( $msg, 'error' );
			wp_safe_redirect( $here );
			exit;
		}
		CC_Fleet_Install::log( 'loan', $loan->id, 'dates', sprintf( 'Confirmed for %s → %s (asked %s → %s)', $start, $end, $loan->start_date, $loan->end_date ) );
		$asked = $row->msg['_asked'] ?? array( $loan->start_date, $loan->end_date );
		$fresh = CC_Fleet_Loans::get( $loan->id );
		$new   = CC_Fleet_Emails::build_confirmed( $fresh );
		// Carry the crew's wording over, paragraph by paragraph.
		$old_t = array_values( self::text_blocks( $row->msg ) );
		$new_i = array_keys( self::text_blocks( $new ) );
		if ( count( $old_t ) === count( $new_i ) ) {
			foreach ( $new_i as $k => $i ) {
				$new['blocks'][ $i ][1] = $old_t[ $k ][1];
			}
		}
		$new['headline'] = $row->msg['headline'] ?? $new['headline'];
		$new['subline']  = $row->msg['subline'] ?? $new['subline'];
		$note            = self::current_note( $row->msg );
		$shorter         = $start > $asked[0] || $end < $asked[1];
		if ( '' === $note && $shorter ) {
			$note = sprintf( 'Heads-up: we can confirm the bike for %s, not the whole time you asked for. If that doesn’t work for you, just reply.', CC_Fleet_Emails::nice_date( $start ) . ' → ' . CC_Fleet_Emails::nice_date( $end ) );
		}
		$new           = self::apply_edits( $new, array( 'note' => $note ) );
		$new['_asked'] = $asked;
		$wpdb->update( self::table(), array( 'msg' => wp_json_encode( $new ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row->id ) );
		self::notice( sprintf( 'Dates changed to %s → %s. The email below now shows them%s. Nothing sent yet.', esc_html( CC_Fleet_Emails::nice_date( $start ) ), esc_html( CC_Fleet_Emails::nice_date( $end ) ), $shorter && '' === self::current_note( $row->msg ) ? ', with a note in the pink box (change or delete it)' : '' ), 'success' );
		wp_safe_redirect( $here );
		exit;
	}

	/** Open the upcoming return reminder for a loan, to read or change it ahead of time. */
	public static function handle_prepare() {
		$loan_id = (int) ( $_GET['loan'] ?? 0 );
		if ( ! current_user_can( CC_Fleet_Admin::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'cc_fleet_email_prepare_' . $loan_id );
		$back = admin_url( 'admin.php?page=cc-fleet-loan&id=' . $loan_id );
		$have = self::scheduled( $loan_id );
		if ( $have ) {
			wp_safe_redirect( self::compose_url( $have->id, $back ) );
			exit;
		}
		$loan  = CC_Fleet_Loans::get( $loan_id );
		$built = $loan ? CC_Fleet_Emails::build_reminder( $loan ) : null;
		if ( ! $built ) {
			self::notice( 'This loan has no rider email to remind.', 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$id = self::hold( $built['to'], $built['subject'], $built['msg'], array( 'type' => 'reminder', 'loan' => $loan, 'status' => 'draft' ) );
		wp_safe_redirect( self::compose_url( $id, $back ) );
		exit;
	}

	private static function notice( $text, $type = 'success' ) {
		set_transient( 'cc_fleet_notice_' . get_current_user_id(), array( $text, $type ), 60 );
	}

	/** Dashboard card: drafts nobody has sent or skipped yet. */
	public static function dashboard_panel() {
		$rows = self::waiting();
		if ( ! $rows ) {
			return;
		}
		echo '<h2>Emails waiting for you</h2><table class="widefat striped cc-table"><thead><tr><th>Email</th><th>To</th><th>Subject</th><th>Since</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a class="button button-small" href="%s">Check &amp; send</a></td></tr>',
				esc_html( self::LABELS[ $r->type ] ?? $r->type ), esc_html( $r->to_email ), esc_html( $r->subject ), esc_html( wp_date( 'j M, H:i', strtotime( $r->created_at ) ) ), esc_url( self::compose_url( $r->id ) ) );
		}
		echo '</tbody></table>';
	}
}
