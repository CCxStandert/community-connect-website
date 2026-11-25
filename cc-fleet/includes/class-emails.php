<?php
defined( 'ABSPATH' ) || exit;

/**
 * Emails for every step of a loan, plus the crew's new-request alert and daily digest.
 *
 * Each email is described once as a list of blocks (headline, details box, steps, buttons…)
 * and rendered twice: email-safe HTML in the Community Connect look (600px table layout,
 * inline styles, PNG logo) and a plain-text copy for clients that prefer it.
 * Copy can still be changed with the 'cc_fleet_email' filter without touching the plugin.
 */
class CC_Fleet_Emails {

	const INK = '#141217', PAPER = '#F4EEE4', SAND = '#D3C8B4', PINK = '#FFB1EC', CREAM = '#EDE3E8', MUTED = '#5B5560';
	const SANS = "'Inter Tight', Helvetica, Arial, sans-serif";
	const MONO = "'IBM Plex Mono', Menlo, Consolas, monospace";

	public static function init_hooks() {
		add_action( 'cc_fleet_loan_created', array( __CLASS__, 'on_created' ) );
		add_action( 'cc_fleet_loan_status', array( __CLASS__, 'on_status' ), 10, 4 );
	}

	/* =========================================================
	 * Sending
	 * ========================================================= */

	/**
	 * @param string|string[] $to
	 * @param array           $msg kicker, headline, subline, preheader, blocks[], footer (rider|crew)
	 */
	/** Id of the draft the last call kept back for review (0 if it was sent). */
	public static $held = 0;
	private static $bypass = false;

	private static function send( $to, $subject, array $msg, $context = array() ) {
		$msg = self::with_my_rides( $msg, $context );
		if ( ! self::$bypass && class_exists( 'CC_Fleet_Outbox' ) && CC_Fleet_Outbox::should_hold( $context ) && ! empty( $to ) ) {
			self::$held = CC_Fleet_Outbox::hold( $to, $subject, $msg, $context );
			if ( self::$held ) {
				return 'held';
			}
		}
		$text = self::render_text( $msg );
		$html = self::render_html( $msg, $subject );
		$mail = apply_filters( 'cc_fleet_email', array( 'to' => $to, 'subject' => $subject, 'body' => $text, 'html' => $html ), $context );
		if ( empty( $mail['to'] ) ) {
			return false;
		}
		$from_name = CC_Fleet_Settings::get( 'from_name' );
		$as_html   = 'text' !== CC_Fleet_Settings::get( 'email_style' ) && ! empty( $mail['html'] );
		$headers   = array( 'Content-Type: ' . ( $as_html ? 'text/html' : 'text/plain' ) . '; charset=UTF-8' );
		$reply     = self::admin_recipients();
		if ( $reply ) {
			$headers[] = 'Reply-To: ' . $from_name . ' <' . $reply[0] . '>';
		}
		// Sender name for this plugin's emails only; a mail plugin that forces its own name still wins.
		$name_filter = function () use ( $from_name ) {
			return $from_name;
		};
		// The plain-text copy rides along as the multipart alternative.
		$alt = function ( $phpmailer ) use ( $mail ) {
			$phpmailer->AltBody = $mail['body'];
		};
		if ( $from_name ) {
			add_filter( 'wp_mail_from_name', $name_filter, 5 );
		}
		// Sender address for bike emails only (shop and WordPress emails keep the site's default).
		$from_email  = (string) CC_Fleet_Settings::get( 'from_email' );
		$addr_filter = function () use ( $from_email ) {
			return $from_email;
		};
		if ( is_email( $from_email ) ) {
			add_filter( 'wp_mail_from', $addr_filter, 5 );
		}
		if ( $as_html ) {
			add_action( 'phpmailer_init', $alt );
		}
		$sent = wp_mail( $mail['to'], $mail['subject'], $as_html ? $mail['html'] : $mail['body'], $headers );
		remove_filter( 'wp_mail_from_name', $name_filter, 5 );
		remove_filter( 'wp_mail_from', $addr_filter, 5 );
		remove_action( 'phpmailer_init', $alt );
		return $sent;
	}

	/** Rider emails about a loan get a "See your booking" button to their My rides page. */
	private static function with_my_rides( array $msg, $context ) {
		$loan = $context['loan'] ?? null;
		if ( 'crew' === ( $msg['footer'] ?? 'rider' ) || ! is_object( $loan ) || ! class_exists( 'CC_Fleet_Me' ) || ! empty( $msg['my_rides'] ) ) {
			return $msg;
		}
		$rider = CC_Fleet_Riders::get( $loan->rider_id );
		$url   = $rider ? CC_Fleet_Me::url( $rider ) : '';
		if ( ! $url ) {
			return $msg;
		}
		$open             = in_array( $loan->status, array( 'requested', 'waiting', 'approved', 'out' ), true );
		$msg['blocks'][]  = array( 'buttons', array( array( $open ? 'See your booking' : 'Your rides', $url, true ) ) );
		$msg['blocks'][]  = array( 'small', 'Your private link: bookings, pick-up details and faster requests next time. Don’t forward it.' );
		$msg['my_rides']  = 1;
		return $msg;
	}

	/** "Email me my link" from the My rides page. */
	public static function my_rides_link( $rider, $url ) {
		return self::send_now( $rider->email, 'Your Community Connect link', array(
			'kicker' => 'My rides', 'headline' => 'Here’s your link, ' . self::first_name( $rider->name ) . '.', 'subline' => 'Bookings, pick-up details, faster requests.',
			'preheader' => 'Tap to see your bookings', 'footer' => 'rider', 'my_rides' => 1,
			'blocks' => array(
				array( 'buttons', array( array( 'Open my rides', $url, true ) ) ),
				array( 'small', 'The link signs you in on the device you open it on, for 30 days. Didn’t ask for this? Ignore it; nothing changes.' ),
			),
		), array( 'type' => 'my_rides_link' ) );
	}

	/** Send exactly this message now, skipping the review step (used by the compose screen). */
	public static function send_now( $to, $subject, array $msg, $context = array() ) {
		self::$bypass = true;
		$sent         = self::send( $to, $subject, $msg, $context );
		self::$bypass = false;
		return $sent;
	}

	public static function admin_recipients() {
		return array_values( array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) CC_Fleet_Settings::get( 'notify_emails' ) ) ) ) );
	}

	public static function nice_date( $d ) {
		return wp_date( 'D j M Y', strtotime( $d . ' 12:00:00' ) );
	}

	private static function short_date( $d ) {
		return wp_date( 'D j M', strtotime( $d . ' 12:00:00' ) );
	}

	private static function dates( $loan ) {
		return self::short_date( $loan->start_date ) . ' → ' . self::nice_date( $loan->end_date );
	}

	/** The bike as riders know it (model, size, name). The Bike ID is only for the crew. */
	private static function bike_name( $loan ) {
		return $loan->bike_id ? CC_Fleet_Bikes::public_label( $loan->bike_id ) : 'Any bike in size ' . ( $loan->requested_size ?: '?' );
	}

	/** Short public name ("Kettensäge 56") for sentences, or '' when no bike is set. */
	private static function bike_short( $loan ) {
		return $loan->bike_id ? CC_Fleet_Bikes::public_short( $loan->bike_id ) : '';
	}

	/** Crew emails keep the Bike ID: "CC-12 Kettensäge 56". */
	private static function crew_bike( $loan ) {
		return $loan->bike_id ? CC_Fleet_Bikes::label( $loan->bike_id ) : 'Any bike in size ' . ( $loan->requested_size ?: '?' );
	}

	private static function first_name( $name ) {
		return trim( strtok( (string) $name, ' ' ) ) ?: (string) $name;
	}

	private static function fleet_url() {
		$id = (int) get_option( 'cc_fleet_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/fleet/' );
	}

	private static function bike_url( $bike_id ) {
		return $bike_id && '1' === CC_Fleet_Bikes::get( $bike_id, 'show_on_site' ) ? get_permalink( $bike_id ) : self::fleet_url();
	}

	private static function crew_mailto( $subject ) {
		$to = self::admin_recipients()[0] ?? get_option( 'admin_email' );
		return 'mailto:' . $to . '?subject=' . rawurlencode( $subject );
	}

	/** Google Calendar "add event" link: works on phones and desktop without an attachment. */
	private static function calendar_link( $loan ) {
		$end = CC_Fleet_Loans::add_days( $loan->end_date, 1 );
		return add_query_arg( array(
			'action'  => 'TEMPLATE',
			'text'    => rawurlencode( 'Bike: ' . self::bike_name( $loan ) ),
			'dates'   => str_replace( '-', '', $loan->start_date ) . '/' . str_replace( '-', '', $end ),
			'details' => rawurlencode( 'Community Connect loan. ' . ( $loan->plan ?: '' ) ),
		), 'https://calendar.google.com/calendar/render' );
	}

	/** Link that opens a WhatsApp chat with the crew, pre-filled with the request reference. Empty if no crew number is set. */
	public static function whatsapp_confirm_link( $loan_id ) {
		$crew = CC_Fleet_Phone::clean( (string) CC_Fleet_Settings::get( 'whatsapp_number' ) );
		$loan = CC_Fleet_Loans::get( $loan_id );
		if ( ! $crew || ! $loan ) {
			return '';
		}
		$r = CC_Fleet_Riders::get( $loan->rider_id );
		return CC_Fleet_Phone::wa_link( $crew, sprintf( 'Hi Community Connect, it’s %s. Confirming my bike request #%d (%s, %s).', $r ? self::first_name( $r->name ) : 'me', $loan->id, self::bike_name( $loan ), self::dates( $loan ) ) );
	}

	private static function next_loan( $loan ) {
		return CC_Fleet_Loans::next_loan( $loan );
	}

	private static function event_name( $loan ) {
		if ( empty( $loan->event_id ) || ! class_exists( 'CC_Fleet_Records' ) ) {
			return '';
		}
		$e = CC_Fleet_Records::get( 'events', (int) $loan->event_id );
		return $e ? (string) $e->name : '';
	}

	private static function rider_sign() {
		return array( 'footer' => 'rider' );
	}

	/* =========================================================
	 * Triggers
	 * ========================================================= */

	public static function on_created( $loan_id ) {
		$loan = CC_Fleet_Loans::get( $loan_id );
		if ( ! $loan || 'web' !== $loan->source || 'waiting' === $loan->status ) {
			return;
		}
		$rider = CC_Fleet_Riders::get( $loan->rider_id );
		if ( $rider && $rider->email ) {
			self::send( $rider->email, 'We got your bike request', self::msg_received( array(
				'name' => self::first_name( $rider->name ), 'bike' => self::bike_name( $loan ), 'dates' => self::dates( $loan ), 'plan' => $loan->plan, 'pedals' => CC_Fleet_Loans::pedals_label( $loan ),
				'whatsapp' => 'yes' === $rider->whatsapp ? '' : self::whatsapp_confirm_link( $loan->id ),
			) ), array( 'type' => 'request_received', 'loan' => $loan ) );
		}
		$admin = self::admin_recipients();
		if ( $admin ) {
			$free  = $loan->bike_id ? array() : array_map( array( 'CC_Fleet_Bikes', 'label' ), CC_Fleet_Loans::available_bikes( $loan->start_date, $loan->end_date, $loan->requested_size ) );
			$clash = $loan->bike_id ? CC_Fleet_Loans::conflicts( $loan->bike_id, $loan->start_date, $loan->end_date, $loan->id, true ) : array();
			$past  = $rider ? max( 0, CC_Fleet_Loans::count( array( 'rider_id' => $rider->id ) ) - 1 ) : 0;
			self::send( $admin, 'New bike request: ' . ( $loan->bike_id ? CC_Fleet_Bikes::get( $loan->bike_id, 'cc_id' ) : 'size ' . $loan->requested_size ), self::msg_crew_request( array(
				'name' => $rider->name ?? 'Unknown', 'height' => $rider->height_cm ?? '', 'email' => $rider->email ?? '', 'phone' => CC_Fleet_Phone::pretty( $rider->phone ?? '' ), 'instagram' => $rider->instagram ?? '',
				'wa' => CC_Fleet_Phone::wa_link( $rider->phone ?? '', 'Hi ' . self::first_name( $rider->name ?? '' ) . ', it’s Community Connect about your bike request.' ), 'wa_status' => $rider->whatsapp ?? '',
				'bike' => self::crew_bike( $loan ), 'any' => ! $loan->bike_id, 'size' => $loan->requested_size, 'dates' => self::dates( $loan ), 'plan' => $loan->plan,
				'history' => $past ? sprintf( _n( '%d loan before', '%d loans before', $past ), $past ) . ( ! empty( $rider->notes ) ? ' · has notes' : '' ) : 'First request',
				'free' => $free, 'clashes' => count( $clash ), 'link' => admin_url( 'admin.php?page=cc-fleet-loan&id=' . $loan->id ),
				'race' => ! empty( $loan->race ), 'event' => self::event_name( $loan ),
			) ), array( 'type' => 'admin_new_request', 'loan' => $loan ) );
		}
	}

	/** The confirmation ("You're on") for a loan as it is now. Also rebuilt when the crew changes the dates before sending. */
	public static function build_confirmed( $loan, $old = '' ) {
		$rider = CC_Fleet_Riders::get( $loan->rider_id );
		$name  = $rider ? self::first_name( $rider->name ) : '';
		$pick  = CC_Fleet_Loans::pickup( $loan );
		$prev  = 'rider' === $pick['mode'] ? $pick['prev'] : null;
		$from  = '';
		if ( $prev ) {
			$p    = CC_Fleet_Riders::get( $prev->rider_id );
			$from = $p ? self::first_name( $p->name ) . ', ' . self::short_date( $prev->end_date ) : '';
		}
		$photo = $loan->bike_id ? get_the_post_thumbnail_url( $loan->bike_id, 'large' ) : '';
		return self::msg_confirmed( array(
			'name' => $name, 'bike' => self::bike_name( $loan ), 'dates' => self::dates( $loan ), 'from' => $from, 'from_first' => $from ? strtok( $from, ',' ) : '',
			'return' => self::nice_date( $loan->end_date ), 'photo' => $photo, 'calendar' => self::calendar_link( $loan ), 'bike_url' => self::bike_url( $loan->bike_id ),
			'agreement' => CC_Fleet_Landing::agreement_url(), 'handoff_notes' => CC_Fleet_Settings::get( 'handoff_notes' ),
			'place' => $loan->handoff_place ?? '', 'time' => $loan->handoff_time ?? '', 'hnote' => $loan->handoff_note ?? '',
			'race' => ! empty( $loan->race ) ? CC_Fleet_Settings::get( 'race_terms' ) : '', 'event' => self::event_name( $loan ),
			'pressure' => class_exists( 'CC_Fleet_Pressure' ) ? CC_Fleet_Pressure::line_for_loan( $loan ) : '', 'pressure_note' => class_exists( 'CC_Fleet_Pressure' ) ? CC_Fleet_Pressure::advice( $loan ) : '',
			'wl' => 'offered' === $old, 'cancel' => CC_Fleet_Waitlist::url( $loan, 'cancel' ),
			'pick_place' => 'address' === $pick['mode'] ? $pick['place'] : '', 'pick_contact' => 'address' === $pick['mode'] ? $pick['contact'] : '', 'pick_time' => 'address' === $pick['mode'] ? $pick['time'] : '',
		) );
	}

	public static function on_status( $loan_id, $status, $old, $notify ) {
		if ( 'waiting' === $status || ( in_array( $old, array( 'waiting', 'offered' ), true ) && 'approved' !== $status ) ) {
			return; // CC_Fleet_Waitlist tells the rider; a yes gets the normal confirmation.
		}
		if ( ! $notify ) {
			return;
		}
		$loan  = CC_Fleet_Loans::get( $loan_id );
		$rider = $loan ? CC_Fleet_Riders::get( $loan->rider_id ) : null;
		if ( ! $rider || ! $rider->email ) {
			return;
		}
		$name = self::first_name( $rider->name );
		switch ( $status ) {
			case 'approved':
				self::send( $rider->email, "You're on — bike confirmed", self::build_confirmed( $loan, $old ), array( 'type' => 'approved', 'loan' => $loan ) );
				break;
			case 'declined':
				$size   = $loan->bike_id ? CC_Fleet_Bikes::get( $loan->bike_id, 'size' ) : $loan->requested_size;
				$free   = array_values( array_diff( CC_Fleet_Loans::available_bikes( $loan->start_date, $loan->end_date, $size ), array( (int) $loan->bike_id ) ) );
				$busy   = $loan->bike_id && CC_Fleet_Loans::conflicts( $loan->bike_id, $loan->start_date, $loan->end_date, $loan->id, false );
				$freeds = array();
				foreach ( array_slice( $free, 0, 3 ) as $b ) {
					$freeds[] = array( CC_Fleet_Bikes::public_label( $b ), self::bike_url( $b ) );
				}
				$later = '';
				if ( $busy ) {
					$len = CC_Fleet_Loans::days_between( $loan->start_date, $loan->end_date );
					for ( $o = 1; $o <= 60 && ! $later; $o++ ) {
						$s = CC_Fleet_Loans::add_days( $loan->start_date, $o );
						if ( CC_Fleet_Loans::is_available( $loan->bike_id, $s, CC_Fleet_Loans::add_days( $s, $len ) ) ) {
							$later = $s;
						}
					}
				}
				self::send( $rider->email, 'About your bike request', self::msg_declined( array(
					'name' => $name, 'bike' => self::bike_name( $loan ), 'cc' => self::bike_short( $loan ), 'dates' => self::dates( $loan ),
					'free' => $freeds, 'busy' => (bool) $busy, 'later' => $later ? self::short_date( $later ) : '', 'later_url' => self::bike_url( $loan->bike_id ),
				) ), array( 'type' => 'declined', 'loan' => $loan ) );
				break;
			case 'cancelled':
				self::send( $rider->email, 'Your bike booking was cancelled', array(
					'kicker' => 'Bike booking · cancelled', 'headline' => 'Booking cancelled.', 'subline' => 'The dates are free again.',
					'preheader' => self::bike_name( $loan ) . ', ' . self::dates( $loan ),
					'blocks' => array(
						array( 'p', "Hi $name,\n\nThis booking has been cancelled. If that's unexpected, just reply and we'll sort it out." ),
						array( 'details', 'Cancelled', array( array( 'Bike', self::bike_name( $loan ) ), array( 'Dates', self::dates( $loan ) ) ) ),
						array( 'buttons', array( array( 'Find another bike', self::fleet_url(), true ) ) ),
					),
				) + self::rider_sign(), array( 'type' => 'cancelled', 'loan' => $loan ) );
				break;
			case 'returned':
				$ig = CC_Fleet_Settings::get( 'instagram_handle' );
				self::send( $rider->email, 'Thanks for bringing it back', array(
					'kicker' => 'Bike · back home', 'headline' => 'Thanks for looking after it.', 'subline' => 'Every ride feeds the next one.',
					'preheader' => 'Got photos, a story or a GPX file?',
					'blocks' => array(
						array( 'p', "Hi $name,\n\nThe bike is back. Got photos, a story or a GPX file? Reply to this email" . ( $ig ? " or tag @$ig" : '' ) . '. It helps the next person who shows up.' ),
						array( 'buttons', array( array( 'Borrow again', self::fleet_url(), true ) ) ),
					),
				) + self::rider_sign(), array( 'type' => 'returned', 'loan' => $loan ) );
				break;
		}
	}

	/** After the crew changed a loan's dates with the rider. */
	public static function dates_changed( $loan, $old, $note = '' ) {
		$rider = CC_Fleet_Riders::get( $loan->rider_id );
		if ( ! $rider || ! $rider->email ) {
			return false;
		}
		$longer = $loan->end_date > $old->end_date;
		return self::send( $rider->email, 'New dates for your bike: ' . self::dates( $loan ), self::msg_dates( array(
			'name' => self::first_name( $rider->name ), 'bike' => self::bike_name( $loan ), 'old' => self::dates( $old ), 'new' => self::dates( $loan ),
			'longer' => $longer, 'note' => $note, 'calendar' => self::calendar_link( $loan ),
		) ), array( 'type' => 'dates_changed', 'loan' => $loan ) );
	}

	public static function msg_dates( $f ) {
		$blocks = array(
			array( 'p', "Hi {$f['name']},\n\nAs agreed, your loan now runs " . $f['new'] . '.' ),
			array( 'details', 'Updated loan', array_values( array_filter( array( array( 'Bike', $f['bike'] ), array( 'Was', $f['old'] ), array( 'Now', $f['new'] ), $f['note'] ? array( 'Note', $f['note'] ) : null ) ) ) ),
			array( 'buttons', array( array( 'Update my calendar', $f['calendar'], true ) ) ),
			array( 'small', 'Something not right? Reply to this email and we’ll fix it.' ),
		);
		return array(
			'kicker' => 'Bike loan · new dates', 'headline' => $f['longer'] ? 'More time.' : 'New dates.', 'subline' => $f['longer'] ? 'Enjoy the extra days.' : 'Thanks for letting us know.',
			'preheader' => $f['bike'] . ', now ' . $f['new'], 'blocks' => $blocks,
		) + self::rider_sign();
	}

	/** The return reminder for a loan as data (to, subject, msg), without sending it. */
	public static function build_reminder( $loan ) {
		$rider = CC_Fleet_Riders::get( $loan->rider_id );
		if ( ! $rider || ! $rider->email ) {
			return null;
		}
		$next  = $loan->bike_id ? self::next_loan( $loan ) : null;
		$nr    = $next ? CC_Fleet_Riders::get( $next->rider_id ) : null;
		$days  = max( 0, (int) CC_Fleet_Settings::get( 'reminder_days_before' ) );
		$sendd = max( CC_Fleet_Settings::today(), CC_Fleet_Loans::add_days( $loan->end_date, -$days ) );
		$gap   = CC_Fleet_Loans::days_between( $sendd, $loan->end_date );
		return array(
			'to'      => $rider->email,
			'subject' => 'Bike due back ' . self::nice_date( $loan->end_date ),
			'msg'     => self::msg_reminder( array(
				'name' => self::first_name( $rider->name ), 'bike' => self::bike_name( $loan ), 'cc' => self::bike_short( $loan ) ?: 'The bike',
				'due' => self::nice_date( $loan->end_date ), 'next' => $nr ? self::first_name( $nr->name ) : '',
				'when' => 1 === $gap ? 'tomorrow' : ( 0 === $gap ? 'today' : 'on ' . self::short_date( $loan->end_date ) ), 'dropoff' => CC_Fleet_Settings::get( 'dropoff_place' ) ?: CC_Fleet_Settings::get( 'base_address' ),
				'place' => $loan->handoff_place ?? '', 'time' => $loan->handoff_time ?? '', 'hnote' => $loan->handoff_note ?? '',
				'report' => self::crew_mailto( 'Problem with ' . self::bike_name( $loan ) ), 'extend' => self::crew_mailto( 'One more day with ' . self::bike_name( $loan ) ),
			) ),
		);
	}

	/** Sends the crew's edited version if one was saved, otherwise the standard reminder. */
	public static function return_reminder( $loan ) {
		$saved = class_exists( 'CC_Fleet_Outbox' ) ? CC_Fleet_Outbox::scheduled( $loan->id ) : null;
		if ( $saved ) {
			return CC_Fleet_Outbox::send_row( $saved );
		}
		$b = self::build_reminder( $loan );
		return $b ? self::send( $b['to'], $b['subject'], $b['msg'], array( 'type' => 'reminder', 'loan' => $loan ) ) : false;
	}

	/**
	 * Morning check for the crew, the day before return reminders go out: who hands over to whom,
	 * where and when, so it can be agreed with both riders before the reminder repeats it.
	 */
	public static function crew_check( array $loans ) {
		$admin = self::admin_recipients();
		if ( ! $admin || ! $loans ) {
			return false;
		}
		$days  = max( 0, (int) CC_Fleet_Settings::get( 'reminder_days_before' ) );
		$items = array();
		foreach ( $loans as $l ) {
			$r    = CC_Fleet_Riders::get( $l->rider_id );
			$next = $l->bike_id ? self::next_loan( $l ) : null;
			$nr   = $next ? CC_Fleet_Riders::get( $next->rider_id ) : null;
			$items[] = array(
				'bike'      => self::crew_bike( $l ),
				'rider'     => $r ? $r->name : '?',
				'rider_c'   => $r ? trim( CC_Fleet_Phone::pretty( $r->phone ) . ( $r->email ? ' · ' . $r->email : '' ), ' ·' ) : '',
				'rider_wa'  => $r ? CC_Fleet_Phone::wa_link( $r->phone, 'Hi ' . self::first_name( $r->name ) . ', it’s Community Connect. Quick check about returning ' . self::bike_name( $l ) . ' on ' . self::short_date( $l->end_date ) . '.' ) : '',
				'due'       => self::nice_date( $l->end_date ),
				'next'      => $nr ? $nr->name . ' · from ' . self::short_date( $next->start_date ) : '',
				'next_c'    => $nr ? trim( CC_Fleet_Phone::pretty( $nr->phone ) . ( $nr->email ? ' · ' . $nr->email : '' ), ' ·' ) : '',
				'next_wa'   => $nr ? CC_Fleet_Phone::wa_link( $nr->phone, 'Hi ' . self::first_name( $nr->name ) . ', it’s Community Connect about collecting ' . self::bike_name( $l ) . '.' ) : '',
				'place'     => trim( (string) ( $l->handoff_place ?? '' ) ),
				'time'      => trim( (string) ( $l->handoff_time ?? '' ) ),
				'note'      => trim( (string) ( $l->handoff_note ?? '' ) ),
				'pressure'  => $next && class_exists( 'CC_Fleet_Pressure' ) ? CC_Fleet_Pressure::line_for_loan( $next ) : '',
				'pedals'    => $next ? CC_Fleet_Loans::pedals_label( $next ) : '',
				'dropoff'   => CC_Fleet_Settings::get( 'dropoff_place' ) ?: CC_Fleet_Settings::get( 'base_address' ),
				'edited'    => class_exists( 'CC_Fleet_Outbox' ) && CC_Fleet_Outbox::scheduled( $l->id ),
				'edit'      => admin_url( 'admin.php?page=cc-fleet-loan&id=' . $l->id . '#handoff' ),
			);
		}
		$send_on = self::short_date( CC_Fleet_Loans::add_days( CC_Fleet_Settings::today(), 1 ) );
		return self::send( $admin, 'Check tomorrow’s handoffs: ' . count( $items ) . ' reminder' . ( count( $items ) > 1 ? 's' : '' ), self::msg_crew_check( array( 'items' => $items, 'send_on' => $send_on, 'days' => $days ) ), array( 'type' => 'crew_check' ) );
	}

	public static function msg_crew_check( $f ) {
		$blocks = array( array( 'p', 'Tomorrow morning these riders get their return reminder. Check the handoff with both sides today, so the reminder says the right thing.' ) );
		foreach ( $f['items'] as $it ) {
			$where = trim( $it['place'] . ( $it['time'] ? ' · ' . $it['time'] : '' ), ' ·' );
			$rows  = array(
				array( 'Rider now', $it['rider'] . ( $it['rider_c'] ? ' · ' . $it['rider_c'] : '' ) ),
				array( 'Due back', $it['due'] ),
				$it['next'] ? array( 'Next rider', $it['next'] . ( $it['next_c'] ? ' · ' . $it['next_c'] : '' ) ) : array( 'Next rider', 'Nobody yet: back to ' . ( $it['dropoff'] ?: 'the crew' ) ),
				array( 'Handoff', $where ?: 'Not agreed yet' ),
			);
			if ( $it['note'] ) {
				$rows[] = array( 'Note', $it['note'] );
			}
			if ( ! empty( $it['pedals'] ) ) {
				$rows[] = array( 'Pedals for ' . strtok( $it['next'], ' ' ), $it['pedals'] );
			}
			if ( ! empty( $it['pressure'] ) ) {
				$rows[] = array( 'Tyres for ' . strtok( $it['next'], ' ' ), $it['pressure'] );
			}
			$rows[]   = array( 'Reminder', $it['edited'] ? 'Your edited version' : 'Standard text' );
			$blocks[] = array( 'details', $it['bike'], $rows );
			$blocks[] = array( 'buttons', array_values( array_filter( array(
				array( 'Edit handoff & reminder', $it['edit'], true ),
				$it['rider_wa'] ? array( 'WhatsApp ' . strtok( $it['rider'], ' ' ), $it['rider_wa'], false ) : null,
				$it['next_wa'] ? array( 'WhatsApp ' . strtok( $it['next'], ' ' ), $it['next_wa'], false ) : null,
			) ) ) );
		}
		$blocks[] = array( 'small', 'Nothing to change? Do nothing: the reminders go out by themselves tomorrow morning. One free day stays between loans for washing, small fixes and late returns.' );
		return array(
			'kicker' => 'Crew · handoff check', 'headline' => 'Check tomorrow’s handoffs.', 'subline' => count( $f['items'] ) . ' reminder' . ( count( $f['items'] ) > 1 ? 's go' : ' goes' ) . ' out ' . ( $f['send_on'] ? $f['send_on'] : 'tomorrow' ) . '.',
			'preheader' => implode( ', ', wp_list_pluck( $f['items'], 'bike' ) ), 'blocks' => $blocks, 'footer' => 'crew',
		);
	}

	public static function daily_digest( array $overdue, array $starting, array $pending ) {
		$admin = self::admin_recipients();
		$work  = array();
		$low   = array();
		if ( class_exists( 'CC_Fleet_Records' ) ) {
			$work = CC_Fleet_Records::query( 'maintenance', array( 'where' => array( 'status' => 'scheduled' ), 'to' => CC_Fleet_Settings::today(), 'order' => 'ASC', 'limit' => 20 ) );
			$low  = CC_Fleet_Records::low_stock();
		}
		// Raffle bikes whose event is within a week and still have no winner.
		$today = CC_Fleet_Settings::today();
		$holds = array_values( array_filter( CC_Fleet_Loans::query( array( 'status' => 'held', 'limit' => 100, 'order' => 'ASC' ) ), function ( $l ) use ( $today ) {
			return ! $l->rider_id && $l->start_date <= CC_Fleet_Loans::add_days( $today, 7 );
		} ) );
		if ( ! $admin || ( ! $overdue && ! $starting && ! $pending && ! $work && ! $holds ) ) {
			return false;
		}
		$who = function ( $l ) {
			$r = CC_Fleet_Riders::get( $l->rider_id );
			return $r ? self::first_name( $r->name ) : '?';
		};
		$cc = function ( $l ) {
			return $l->bike_id ? CC_Fleet_Bikes::get( $l->bike_id, 'cc_id' ) : 'size ' . $l->requested_size;
		};
		$facts = array(
			'today'    => wp_date( 'D j M' ),
			'overdue'  => array_map( function ( $l ) use ( $who, $cc ) { return array( $cc( $l ), $who( $l ) . ' · due ' . self::short_date( $l->end_date ) ); }, $overdue ),
			'handoffs' => array_map( function ( $l ) use ( $who, $cc ) { return array( self::short_date( $l->start_date ), $cc( $l ) . ' → ' . $who( $l ) ); }, $starting ),
			'pending'  => array_merge(
				array_map( function ( $l ) use ( $who, $cc ) { return array( 'Waiting', $who( $l ) . ' · ' . $cc( $l ) . ' · ' . self::short_date( $l->start_date ) ); }, $pending ),
				array_map( function ( $l ) use ( $cc ) { return array( 'Raffle', $cc( $l ) . ' · ' . ( self::event_name( $l ) ?: 'event' ) . ' · no winner yet, ' . self::short_date( $l->start_date ) ); }, $holds )
			),
			'work'     => array_map( function ( $m ) { return array( 'Workshop', ( $m->bike_id ? CC_Fleet_Bikes::get( $m->bike_id, 'cc_id' ) : '' ) . ' · ' . wp_trim_words( (string) $m->description, 8 ) ); }, $work ),
			'low'      => array_map( function ( $s ) { return array( 'Low stock', $s->name . ' · ' . (int) $s->quantity . ' left' ); }, array_slice( $low, 0, 5 ) ),
			'link'     => admin_url( 'admin.php?page=cc-fleet' ),
		);
		return self::send( $admin, 'Fleet today: ' . count( $overdue ) . ' overdue, ' . count( $pending ) . ' to review', self::msg_digest( $facts ), array( 'type' => 'digest' ) );
	}

	/* =========================================================
	 * Waiting list (see CC_Fleet_Waitlist)
	 * ========================================================= */

	private static function rider_of( $loan ) {
		$r = $loan ? CC_Fleet_Riders::get( $loan->rider_id ) : null;
		return $r && $r->email ? $r : null;
	}

	/** Free bikes in the same size for these dates, as buttons (at most 2). */
	private static function free_buttons( $loan, $skip = 0 ) {
		$size = $loan->bike_id ? CC_Fleet_Bikes::get( $loan->bike_id, 'size' ) : $loan->requested_size;
		$out  = array();
		foreach ( array_slice( array_values( array_diff( CC_Fleet_Loans::available_bikes( $loan->start_date, $loan->end_date, $size ), array( (int) $skip ) ) ), 0, 2 ) as $b ) {
			$out[] = array( CC_Fleet_Bikes::public_short( $b ) . ' is free for your dates', self::bike_url( $b ), false );
		}
		return $out;
	}

	public static function wl_joined( $loan, $type = 'wl_joined' ) {
		$r = self::rider_of( $loan );
		if ( ! $r ) {
			return false;
		}
		return self::send( $r->email, 'You’re on the waiting list: ' . ( self::bike_short( $loan ) ?: 'size ' . $loan->requested_size ), self::msg_wl_joined( array(
			'name' => self::first_name( $r->name ), 'bike' => self::bike_name( $loan ), 'cc' => self::bike_short( $loan ), 'dates' => self::dates( $loan ),
			'place' => CC_Fleet_Waitlist::position( $loan ), 'hours' => CC_Fleet_Waitlist::offer_hours(), 'free' => self::free_buttons( $loan, $loan->bike_id ), 'leave' => CC_Fleet_Waitlist::url( $loan, 'leave' ),
		) ), array( 'type' => $type, 'loan' => $loan ) );
	}

	public static function msg_wl_joined( $f ) {
		$what = $f['cc'] ? $f['cc'] . ' is already booked' : 'Every bike in that size is booked';
		return array(
			'kicker' => 'Waiting list · ' . ( $f['cc'] ?: 'any bike' ), 'headline' => 'You’re in line.', 'subline' => 'We’ll shout if it frees up.',
			'preheader' => $f['bike'] . ', ' . $f['dates'] . ' · number ' . $f['place'] . ' in line',
			'blocks' => array(
				array( 'p', "Hi {$f['name']},\n\n$what for {$f['dates']}, so we’ve put you on the waiting list. Plans change and bikes come back early. When that happens, we go down the list." ),
				array( 'details', 'Your spot', array( array( 'Bike', $f['bike'] ), array( 'Dates', $f['dates'] ), array( 'In line', 'Number ' . $f['place'] ) ) ),
				array( 'p', "If it frees up, you get an email with a yes and a no button and {$f['hours']} hours to answer. Until then nothing is held for you, so feel free to grab another bike or other dates in the meantime." ),
				array( 'buttons', array_merge( $f['free'], array( array( 'Take me off the list', $f['leave'], false ) ) ) ),
			),
		) + self::rider_sign();
	}

	/** @return bool|string 'held' when kept back for the crew to check first. */
	public static function wl_offer( $loan, $type = 'wl_offer', $short = false ) {
		$r = self::rider_of( $loan );
		if ( ! $r ) {
			return false;
		}
		$by = wp_date( 'D j M, H:i', time() + CC_Fleet_Waitlist::offer_hours() * HOUR_IN_SECONDS );
		return self::send( $r->email, 'It’s free: ' . self::bike_short( $loan ) . ', ' . self::dates( $loan ), self::msg_wl_offer( array(
			'name' => self::first_name( $r->name ), 'bike' => self::bike_name( $loan ), 'dates' => self::dates( $loan ), 'by' => $by, 'hours' => CC_Fleet_Waitlist::offer_hours(),
			'short' => $short, 'yes' => CC_Fleet_Waitlist::url( $loan, 'yes' ), 'no' => CC_Fleet_Waitlist::url( $loan, 'no' ), 'photo' => get_the_post_thumbnail_url( $loan->bike_id, 'large' ) ?: '',
		) ), array( 'type' => $type, 'loan' => $loan ) );
	}

	public static function msg_wl_offer( $f ) {
		$intro = $f['short']
			? "Someone’s plans changed and {$f['bike']} is free for {$f['dates']}. That’s soon, so we’ve let a few people on the list know. First yes gets it."
			: "Someone’s plans changed and {$f['bike']} is free for {$f['dates']}. You were next in line, so it’s yours if you want it.";
		$blocks = array();
		if ( ! empty( $f['photo'] ) ) {
			$blocks[] = array( 'image', $f['photo'], $f['bike'] );
		}
		$blocks[] = array( 'p', "Hi {$f['name']},\n\n$intro" );
		$blocks[] = array( 'details', 'The offer', array( array( 'Bike', $f['bike'] ), array( 'Dates', $f['dates'] ), array( 'Answer by', $f['by'] ) ) );
		$blocks[] = array( 'p', $f['short'] ? 'Tap yes as soon as you can.' : "We’re holding it for you for {$f['hours']} hours. After that it goes to the next person on the list." );
		$blocks[] = array( 'buttons', array( array( 'Yes, I’ll take it', $f['yes'], true ), array( 'No, pass it on', $f['no'], false ) ) );
		$blocks[] = array( 'small', 'Same deal as always: you bring it back the way you got it.' );
		return array(
			'kicker' => 'Waiting list · good news', 'headline' => 'It’s free.', 'subline' => 'Still want it?',
			'preheader' => $f['bike'] . ', ' . $f['dates'] . ' · answer by ' . $f['by'], 'blocks' => $blocks,
		) + self::rider_sign();
	}

	/** The offer went to someone quicker, or ran out. The rider stays on the list. */
	public static function wl_gone( $loan, $why = 'lapsed', $bike = '' ) {
		$r = self::rider_of( $loan );
		if ( ! $r ) {
			return false;
		}
		return self::send( $r->email, 'About ' . ( $bike ?: self::bike_name( $loan ) ), self::msg_wl_gone( array(
			'name' => self::first_name( $r->name ), 'bike' => $bike ?: self::bike_name( $loan ), 'dates' => self::dates( $loan ), 'why' => $why, 'hours' => CC_Fleet_Waitlist::offer_hours(),
			'fleet' => self::fleet_url(), 'leave' => CC_Fleet_Waitlist::url( $loan, 'leave' ),
		) ), array( 'type' => 'wl_gone', 'loan' => $loan ) );
	}

	public static function msg_wl_gone( $f ) {
		$what = 'quicker' === $f['why']
			? "{$f['bike']} for {$f['dates']} went to someone who said yes first this time."
			: "We didn’t hear back within {$f['hours']} hours, so we passed {$f['bike']} for {$f['dates']} on.";
		return array(
			'kicker' => 'Waiting list · update', 'headline' => 'Not this round.', 'subline' => 'You’re still in line.',
			'preheader' => $f['bike'] . ', ' . $f['dates'],
			'blocks' => array(
				array( 'p', "Hi {$f['name']},\n\n$what No stress, you’re still on the list for any other openings." ),
				array( 'buttons', array( array( 'See what’s free', $f['fleet'], true ), array( 'Take me off the list', $f['leave'], false ) ) ),
			),
		) + self::rider_sign();
	}

	/** The start day came and the bike never freed up. */
	public static function wl_closed( $loan ) {
		$r = self::rider_of( $loan );
		if ( ! $r ) {
			return false;
		}
		$later = '';
		if ( $loan->bike_id ) {
			$len = CC_Fleet_Loans::days_between( $loan->start_date, $loan->end_date );
			for ( $o = 1; $o <= 60 && ! $later; $o++ ) {
				$st = CC_Fleet_Loans::add_days( CC_Fleet_Settings::today(), $o );
				if ( CC_Fleet_Loans::is_available( $loan->bike_id, $st, CC_Fleet_Loans::add_days( $st, $len ) ) ) {
					$later = $st;
				}
			}
		}
		return self::send( $r->email, 'Your waiting list spot: ' . self::bike_name( $loan ), self::msg_wl_closed( array(
			'name' => self::first_name( $r->name ), 'bike' => self::bike_name( $loan ), 'cc' => self::bike_short( $loan ), 'dates' => self::dates( $loan ),
			'later' => $later ? self::short_date( $later ) : '', 'later_url' => self::bike_url( $loan->bike_id ), 'fleet' => self::fleet_url(),
		) ), array( 'type' => 'wl_closed', 'loan' => $loan ) );
	}

	public static function msg_wl_closed( $f ) {
		$buttons = array();
		if ( $f['later'] ) {
			$buttons[] = array( 'Request ' . $f['cc'] . ' from ' . $f['later'], $f['later_url'], true );
		}
		$buttons[] = array( 'See the fleet', $f['fleet'], ! $f['later'] );
		return array(
			'kicker' => 'Waiting list · closed', 'headline' => 'Not this time.', 'subline' => 'Let’s find you another ride.',
			'preheader' => $f['bike'] . ', ' . $f['dates'],
			'blocks' => array(
				array( 'p', "Hi {$f['name']},\n\n{$f['bike']} stayed booked for {$f['dates']}, so we’ve closed your spot on the list. Sorry it didn’t work out." . ( $f['later'] ? " {$f['cc']} is free again from {$f['later']}." : '' ) ),
				array( 'buttons', $buttons ),
			),
		) + self::rider_sign();
	}

	/** To a rider who cancelled with the "Can't make it?" button. */
	public static function wl_thanks( $loan ) {
		$r = self::rider_of( $loan );
		if ( ! $r ) {
			return false;
		}
		return self::send( $r->email, 'Booking cancelled: thanks for the heads-up', self::msg_wl_thanks( array(
			'name' => self::first_name( $r->name ), 'bike' => self::bike_name( $loan ), 'dates' => self::dates( $loan ), 'fleet' => self::fleet_url(),
		) ), array( 'type' => 'wl_thanks', 'loan' => $loan ) );
	}

	public static function msg_wl_thanks( $f ) {
		return array(
			'kicker' => 'Bike booking · cancelled', 'headline' => 'Thanks for the heads-up.', 'subline' => 'Someone else gets to ride.',
			'preheader' => $f['bike'] . ', ' . $f['dates'],
			'blocks' => array(
				array( 'p', "Hi {$f['name']},\n\nWe’ve freed {$f['bike']} for {$f['dates']}. Telling us early means someone on the waiting list gets to ride, so thank you. Hope to see you on one soon." ),
				array( 'buttons', array( array( 'Find another bike', $f['fleet'], true ) ) ),
			),
		) + self::rider_sign();
	}

	/** Crew: a bike is free for riders on the list. */
	public static function wl_crew_spot( $bike_id, array $cands, $freed = null ) {
		$admin = self::admin_recipients();
		if ( ! $admin || ! $cands ) {
			return false;
		}
		$cc  = CC_Fleet_Bikes::get( $bike_id, 'cc_id' );
		$why = '';
		if ( $freed ) {
			$now  = CC_Fleet_Loans::get( $freed->id );
			$verb = ! $now ? 'gave up' : ( 'returned' === $now->status ? 'brought back early' : ( in_array( $now->status, array( 'cancelled', 'declined' ), true ) ? 'cancelled' : 'changed the dates of' ) );
			$p    = CC_Fleet_Riders::get( $freed->rider_id );
			$why  = ( $p ? $p->name : 'A rider' ) . ' ' . $verb . ' ' . $cc . ', ' . self::dates( $freed ) . '.';
		}
		$rows = array();
		foreach ( array_slice( $cands, 0, 6 ) as $i => $l ) {
			$r      = CC_Fleet_Riders::get( $l->rider_id );
			$h      = CC_Fleet_Waitlist::history( (int) $l->rider_id );
			$rows[] = array( '#' . ( $i + 1 ), ( $r ? $r->name : '?' ) . ' · ' . self::dates( $l ) . ' · asked ' . mysql2date( 'j M', $l->created_at ) . ' · ' . ( $h['count'] ? $h['count'] . ' loan' . ( 1 === $h['count'] ? '' : 's' ) . ', last ' . mysql2date( 'M Y', $h['last'] ) : 'first loan' ) . ( (int) $l->event_id > 0 ? ' · for an event' : '' ) );
		}
		$first = $cands[0];
		$short = CC_Fleet_Waitlist::is_short_notice( $first );
		return self::send( $admin, 'Spot open: ' . $cc . ', ' . count( $cands ) . ' waiting', self::msg_wl_crew_spot( array(
			'cc' => $cc, 'bike' => CC_Fleet_Bikes::label( $bike_id ), 'why' => $why, 'rows' => $rows, 'n' => count( $cands ),
			'first' => CC_Fleet_Waitlist::rider_first( $first ), 'hours' => CC_Fleet_Waitlist::offer_hours(), 'short' => $short && count( $cands ) > 1 ? min( CC_Fleet_Waitlist::short_count(), count( $cands ) ) : 0,
			'offer' => admin_url( 'admin.php?page=cc-fleet-loan&id=' . $first->id ), 'list' => admin_url( 'admin.php?page=cc-fleet#waitlist' ),
		) ), array( 'type' => 'wl_crew_spot' ) );
	}

	public static function msg_wl_crew_spot( $f ) {
		$blocks   = array();
		$blocks[] = array( 'p', ( $f['why'] ? $f['why'] . ' ' : '' ) . $f['n'] . ' rider' . ( 1 === $f['n'] ? '' : 's' ) . ' on the waiting list fit' . ( 1 === $f['n'] ? 's' : '' ) . ' ' . $f['bike'] . ' now, in this order:' );
		$blocks[] = array( 'details', 'In line', $f['rows'] );
		$blocks[] = array( 'callout', array( $f['short']
			? 'Suggested: it starts soon, so offer it to the top ' . $f['short'] . ' at once (on the waiting list page). First yes gets it.'
			: 'Suggested: ' . $f['first'] . ', first in line. They get ' . $f['hours'] . ' hours to say yes, then it moves on by itself.' ), 'cream' );
		$blocks[] = array( 'buttons', array_values( array_filter( array( $f['short'] ? null : array( 'Offer to ' . $f['first'], $f['offer'], true ), array( 'Open the waiting list', $f['list'], (bool) $f['short'] ) ) ) ) );
		$blocks[] = array( 'small', 'Nobody you want to offer it to? Do nothing: the dates are already free for new requests.' );
		return array(
			'kicker' => 'Crew · waiting list', 'headline' => 'Spot open: ' . $f['cc'] . '.', 'subline' => $f['n'] . ' waiting.',
			'preheader' => $f['first'] . ' is first in line', 'blocks' => $blocks, 'footer' => 'crew',
		);
	}

	/** Crew: welcome to a new Fleet Manager, with their set-password link. */
	public static function crew_welcome( WP_User $u, $set_url, $login_url ) {
		$first = self::first_name( $u->display_name ?: $u->user_login );
		return self::send( $u->user_email, 'Welcome to the crew: your Fleet Manager login', self::msg_crew_welcome( array(
			'name' => $first, 'user' => $u->user_login, 'set' => $set_url, 'login' => $login_url,
		) ), array( 'type' => 'crew_welcome' ) );
	}

	public static function msg_crew_welcome( $f ) {
		return array(
			'kicker' => 'Crew · welcome', 'headline' => 'Welcome to the crew, ' . $f['name'] . '.', 'subline' => 'Your Fleet Manager login is ready.',
			'preheader' => 'Set your password and log in to Fleet Manager',
			'blocks' => array(
				array( 'p', "Hi {$f['name']},\n\nThe new Community Connect website is up, and you now have your own login. As a Fleet Manager you see everything for the bikes in one place: bike requests, loans and handoffs, the fleet calendar, riders, the waiting list, workshop jobs, stock and events." ),
				array( 'details', 'Your login', array( array( 'Username', $f['user'] ), array( 'Login page', preg_replace( '#^https?://#', '', $f['login'] ) ) ) ),
				array( 'steps', 'Getting started', array(
					'Tap “Set my password” below and choose your own password.',
					'Log in and open Fleet Manager in the menu on the left.',
					'Have a look around: the Dashboard shows what needs doing today.',
				) ),
				array( 'buttons', array( array( 'Set my password', $f['set'], true ), array( 'Go to the login page', $f['login'], false ) ) ),
				array( 'small', 'The set-password link works for 24 hours. If it has run out, use “Lost your password?” on the login page with this email address. Questions? Just reply to this email.' ),
			),
			'footer' => 'crew',
		);
	}

	/** Crew: one short update (a yes, a no, an offer that ran out). */
	public static function wl_crew_note( $subject, $headline, array $lines, $link, $label ) {
		$admin = self::admin_recipients();
		if ( ! $admin ) {
			return false;
		}
		return self::send( $admin, $subject, self::msg_wl_crew_note( array( 'headline' => $headline, 'lines' => $lines, 'link' => $link, 'label' => $label ) ), array( 'type' => 'wl_crew_note' ) );
	}

	/**
	 * Rider-to-rider handoff intro. $role 'give' (has the bike, hands it on) or 'get' (receives it).
	 * $share: both agreed to share name + WhatsApp; otherwise the crew connects them.
	 */
	public static function handoff_intro( $to, $other, $loan, $role, $bike, $window, array $check, $share ) {
		if ( ! $to || ! $to->email ) {
			return false;
		}
		$first = self::first_name( $to->name );
		$them  = self::first_name( $other->name );
		$give  = 'give' === $role;
		$wa    = $share && $other->phone ? CC_Fleet_Phone::wa_link( $other->phone, 'Hi ' . $them . ', it’s ' . $first . ' from Community Connect, about handing over ' . $bike . '.' ) : '';
		$rows  = array( array( 'Bike', $bike ), array( $give ? 'Hand it to' : 'Get it from', $them . ( $share && $other->phone ? ' · ' . $other->phone : '' ) ), array( 'When', $window ) );
		foreach ( $check as $c ) {
			$rows[] = array( 'Check', $c );
		}
		$blocks = array(
			array( 'p', $give
				? "{$them} has {$bike} after you, so you hand it straight over: no trip to our base. Agree a time and place that suits you both."
				: "{$bike} comes straight from {$them}, who has it until then. Agree a time and place that suits you both." ),
			array( 'details', 'The handover', $rows ),
		);
		$blocks[] = $wa ? array( 'buttons', array( array( 'WhatsApp ' . $them, $wa, true ) ) ) : array( 'callout', array( 'We’ll put you in touch on WhatsApp shortly.' ), 'cream' );
		$blocks[] = array( 'small', $give ? 'Hand it over clean-ish, with anything that came with it. Something wrong with the bike? Tell us before the handover.' : 'Have a quick look together: brakes, tyres, gears. Anything off? Tell us right away, before you ride.' );
		return self::send( $to->email, ( $give ? 'Hand ' . $bike . ' to ' . $them : 'Your bike comes from ' . $them ), array(
			'kicker' => 'Rider to rider', 'headline' => $give ? "Hand it straight to {$them}." : "Meet {$them}.", 'subline' => $window,
			'preheader' => $give ? 'Hand ' . $bike . ' to ' . $them : $bike . ' comes from ' . $them, 'blocks' => $blocks, 'footer' => 'rider',
		), array( 'type' => 'handoff_intro', 'loan' => $loan ) );
	}

	/** The receiving rider: the handover from the rider before is off; we'll confirm a pick-up. */
	public static function handoff_off( $loan, $rider ) {
		return self::send( $rider->email, 'Change of plan for your pick-up', array(
			'kicker' => 'Pick-up', 'headline' => 'Change of plan, ' . self::first_name( $rider->name ) . '.', 'subline' => self::dates( $loan ),
			'preheader' => 'Your bike won’t come from the rider before',
			'blocks' => array( array( 'p', 'Your bike won’t come straight from the rider before after all. Your booking still stands: we’ll confirm where to pick it up shortly.' ) ),
			'footer' => 'rider',
		), array( 'type' => 'handoff_off', 'loan' => $loan ) );
	}

	public static function msg_wl_crew_note( $f ) {
		return array(
			'kicker' => 'Crew', 'headline' => $f['headline'], 'subline' => '',
			'preheader' => $f['lines'][0] ?? '',
			'blocks' => array( array( 'p', implode( "\n\n", $f['lines'] ) ), array( 'buttons', array( array( $f['label'], $f['link'], true ) ) ) ),
			'footer' => 'crew',
		);
	}

	/* =========================================================
	 * Messages (facts in, blocks out). Shared by real sends and the preview.
	 * ========================================================= */

	public static function msg_received( $f ) {
		return array(
			'kicker' => 'Bike request · received', 'headline' => 'We got your request.', 'subline' => 'Now we check the dates.',
			'preheader' => $f['bike'] . ', ' . $f['dates'],
			'blocks' => array(
				array( 'p', "Hi {$f['name']},\n\nThanks for asking. We'll look at the calendar and come back to you within a couple of days, usually sooner." ),
				array( 'details', 'Your request', array( array( 'Bike', $f['bike'] ), array( 'Dates', $f['dates'] ), array( 'Plan', $f['plan'] ?: '—' ) ) ),
				array( 'steps', 'What happens next', array(
					'We check who has the bike before you, and pick one that fits if you asked for any.',
					'You get a yes (with the bike and the handoff) or a suggestion for other dates.',
					'The last rider usually hands it over. We connect you on WhatsApp.',
				) ),
				array( 'buttons', array_values( array_filter( array( ! empty( $f['whatsapp'] ) ? array( 'Say hi on WhatsApp', $f['whatsapp'], true ) : null, array( 'See the fleet', self::fleet_url(), false ) ) ) ) ),
				array( 'small', ( ! empty( $f['whatsapp'] ) ? "We arrange handoffs on WhatsApp. Saying hi there confirms we've got the right number.\n\n" : '' ) . "Plans changed? Just reply to this email and we'll free the dates for someone else." ),
			),
		) + self::rider_sign();
	}

	public static function msg_confirmed( $f ) {
		$pick_at = ! $f['from'] && ! empty( $f['pick_place'] );
		$handoff = $f['from'] ? array(
			'We’ll put you and ' . $f['from_first'] . ' in touch on WhatsApp to agree a time.',
			'Check tyres, brakes and gears together at the handoff.',
			'Wash it before you pass it on. The next rider will thank you.',
		) : ( $pick_at ? array(
			'Collect it at ' . $f['pick_place'] . ( ! empty( $f['pick_time'] ) ? ', ' . $f['pick_time'] . '.' : '.' ) . ( ! empty( $f['pick_contact'] ) ? ' ' . ( ! empty( $f['pick_time'] ) ? 'Running late? Message ' : 'Message ' ) . $f['pick_contact'] . ( ! empty( $f['pick_time'] ) ? '.' : ' to agree a time.' ) : ( ! empty( $f['pick_time'] ) ? '' : ' We’ll message you on WhatsApp to agree a time.' ) ),
			'Check tyres, brakes and gears when you collect it.',
			'Wash it before you pass it on. The next rider will thank you.',
		) : array(
			'We’ll be in touch on WhatsApp to agree where and when you collect it.',
			'Check tyres, brakes and gears when you collect it.',
			'Wash it before you pass it on. The next rider will thank you.',
		) );
		$note = array();
		if ( $f['handoff_notes'] ) {
			$note[] = $f['handoff_notes'];
		}
		if ( $f['agreement'] ) {
			$note[] = array( 'Rider agreement: you accepted it with your request. ', 'Read it again', $f['agreement'] );
		}
		$blocks = array();
		if ( $f['photo'] ) {
			$blocks[] = array( 'image', $f['photo'], $f['bike'] );
		}
		$blocks[] = array( 'p', "Hi {$f['name']}," . ( ! empty( $f['wl'] ) ? "\n\nGood things come to those who wait. Here's your bike." : '' ) );
		$blocks[] = array( 'details', 'Your loan', array(
			array( 'Bike', $f['bike'] ), array( 'Dates', $f['dates'] ), array( 'Pick-up', $f['from'] ? 'From ' . $f['from'] : ( $pick_at ? $f['pick_place'] . ( ! empty( $f['pick_time'] ) ? ' · ' . $f['pick_time'] : '' ) : 'From the crew' ) ),
		) );
		if ( $pick_at && ! empty( $f['pick_contact'] ) ) {
			$blocks[ count( $blocks ) - 1 ][2][] = array( 'Contact', $f['pick_contact'] );
		}
		$blocks[ count( $blocks ) - 1 ][2][] = array( 'Return', $f['return'] );
		$where = trim( ( $f['place'] ?? '' ) . ( ! empty( $f['time'] ) ? ' · ' . $f['time'] : '' ), ' ·' );
		if ( $where ) {
			$blocks[ count( $blocks ) - 1 ][2][] = array( 'Handoff', $where );
		}
		if ( ! empty( $f['hnote'] ) ) {
			$blocks[ count( $blocks ) - 1 ][2][] = array( 'Note', $f['hnote'] );
		}
		if ( ! empty( $f['pressure'] ) ) {
			$blocks[] = array( 'details', 'Tyre pressure for you', array( array( 'Start with', $f['pressure'] ), array( 'Why', $f['pressure_note'] ?? '' ) ) );
		}
		$blocks[] = array( 'steps', 'Before you ride', $handoff );
		if ( ! empty( $f['race'] ) ) {
			$note[] = 'Racing ' . ( ! empty( $f['event'] ) ? $f['event'] : 'with it' ) . ': ' . $f['race'];
		}
		if ( $note ) {
			$blocks[] = array( 'callout', $note, 'pink' );
		}
		$blocks[] = array( 'buttons', array_values( array_filter( array( array( 'Add to my calendar', $f['calendar'], true ), array( 'Bike details & size', $f['bike_url'], false ), ! empty( $f['cancel'] ) ? array( 'Can’t make it?', $f['cancel'], false ) : null ) ) ) );
		if ( ! empty( $f['cancel'] ) ) {
			$blocks[] = array( 'small', 'Plans changed? Tap “Can’t make it?” as early as you can, so someone on the waiting list gets to ride instead.' );
		}
		return array(
			'kicker' => 'Bike request · confirmed', 'headline' => 'You’re on.', 'subline' => 'The bike is yours for those dates.',
			'preheader' => $f['bike'] . ', ' . $f['dates'], 'blocks' => $blocks,
		) + self::rider_sign();
	}

	public static function msg_reminder( $f ) {
		$where = $f['next'] ? $f['next'] . ' · we’ve put you in touch' : ( $f['dropoff'] ?: 'We’ll message you where to drop it' );
		return array(
			'kicker' => 'Return reminder', 'headline' => 'Back ' . ( $f['when'] ?? 'soon' ) . '.', 'subline' => 'Hope it was a good one.',
			'preheader' => $f['cc'] . ' is due back ' . $f['due'],
			'blocks' => array(
				array( 'p', "Hi {$f['name']},\n\n{$f['cc']} is due back on {$f['due']}. Here's where it goes next:" ),
				array( 'details', 'Handing on', array_values( array_filter( array(
					array( 'Bike', $f['bike'] ), array( 'Due', $f['due'] ), array( $f['next'] ? 'Next rider' : 'Drop at', $where ),
					! empty( $f['place'] ) || ! empty( $f['time'] ) ? array( 'Where & when', trim( ( $f['place'] ?? '' ) . ( ! empty( $f['time'] ) ? ' · ' . $f['time'] : '' ), ' ·' ) ) : null,
					! empty( $f['hnote'] ) ? array( 'Note', $f['hnote'] ) : null,
					$f['next'] && $f['dropoff'] && empty( $f['place'] ) ? array( 'Or drop at', $f['dropoff'] ) : null,
				) ) ) ),
				array( 'steps', 'A quick checklist', array(
					'Give it a wash and a chain wipe.',
					'Note anything that broke or feels off. It helps the workshop.',
					'Snap a photo at the handoff and share it with us on Instagram.',
				) ),
				array( 'buttons', array( array( 'Report damage or a problem', $f['report'], false ), array( 'Ask for one more day', $f['extend'], false ) ) ),
			),
		) + self::rider_sign();
	}

	public static function msg_declined( $f ) {
		$why    = ! empty( $f['busy'] ) ? ( $f['cc'] ?: 'That bike' ) . ' is already out for ' . $f['dates'] . '.' : 'We can’t make ' . $f['dates'] . ' work this time.';
		$blocks = array( array( 'p', "Hi {$f['name']},\n\n" . $why . ( $f['free'] ? ' These bikes are free for your dates:' : ' Reply to this email and we’ll look for another bike or other dates with you.' ) ) );
		if ( $f['free'] ) {
			$blocks[] = array( 'buttons', array_map( function ( $b ) { return array( $b[0] . ' · free', $b[1], false ); }, $f['free'] ) );
		}
		if ( $f['later'] ) {
			$blocks[] = array( 'p', 'Or keep ' . $f['cc'] . ' and shift your dates: it’s free from ' . $f['later'] . '.' );
			$blocks[] = array( 'buttons', array( array( 'Request ' . $f['cc'] . ' from ' . $f['later'], $f['later_url'], true ) ) );
		}
		$blocks[] = array( 'small', 'Requests for the same bike and dates are first come, first served. Reply if you’d like us to keep you in mind.' );
		return array(
			'kicker' => 'Bike request · update', 'headline' => 'Not this time.', 'subline' => $f['free'] || $f['later'] ? 'But maybe one of these.' : 'But let’s find something.',
			'preheader' => 'About ' . $f['bike'] . ', ' . $f['dates'], 'blocks' => $blocks,
		) + self::rider_sign();
	}

	public static function msg_crew_request( $f ) {
		$rows = array(
			array( 'Rider', $f['name'] . ( $f['height'] ? ' · ' . (int) $f['height'] . ' cm' : '' ) ),
			array( 'Contact', trim( $f['email'] . ( $f['phone'] ? ' · ' . $f['phone'] : '' ), ' ·' ) ?: '—' ),
		);
		if ( $f['instagram'] ) {
			$rows[] = array( 'Instagram', '@' . ltrim( $f['instagram'], '@' ) );
		}
		$rows   = array_merge( $rows, array( array( 'Bike', $f['bike'] ), array( 'Dates', $f['dates'] ), array( 'Plan', $f['plan'] ?: '—' ) ), ! empty( $f['pedals'] ) ? array( array( 'Pedals', $f['pedals'] ) ) : array(), array( array( 'History', $f['history'] ) ) );
		if ( ! empty( $f['race'] ) ) {
			$rows[] = array( 'Event', ( $f['event'] ?: 'A race we don’t list' ) . ' · racing terms accepted' );
		}
		$blocks = array( array( 'details', 'Request', $rows ) );
		if ( $f['any'] ) {
			$blocks[] = array( 'callout', array( $f['free'] ? 'Free in size ' . $f['size'] . ': ' . implode( ' and ', $f['free'] ) . '. Pick one when you approve.' : 'Nothing in size ' . $f['size'] . ' is free for these dates.' ), 'cream' );
		} elseif ( $f['clashes'] ) {
			$blocks[] = array( 'callout', array( 'Overlaps ' . $f['clashes'] . ' other request or loan on this bike. Check before you approve.' ), 'pink' );
		}
		$blocks[] = array( 'buttons', array_values( array_filter( array( array( 'Review in Fleet Manager', $f['link'], true ), ! empty( $f['wa'] ) ? array( 'Chat on WhatsApp', $f['wa'], false ) : null ) ) ) );
		$blocks[] = array( 'small', ( 'yes' === ( $f['wa_status'] ?? '' ) ? 'WhatsApp: confirmed earlier. ' : 'WhatsApp: not confirmed yet. If “Chat on WhatsApp” says the number isn’t on WhatsApp, ask them by email. ' ) . 'You get this because you’re on the crew list in Fleet Manager → Settings.' );
		return array(
			'kicker' => 'Crew · new request', 'headline' => 'New request: ' . ( $f['any'] ? 'size ' . $f['size'] : $f['bike'] ) . '.', 'subline' => self::first_name( $f['name'] ) . ', ' . $f['dates'] . '.',
			'preheader' => $f['name'] . ' · ' . $f['bike'], 'blocks' => $blocks, 'footer' => 'crew',
		);
	}

	public static function msg_digest( $f ) {
		$bits = array();
		foreach ( array( 'handoffs' => '%d handoff', 'overdue' => '%d overdue', 'pending' => '%d request' ) as $k => $label ) {
			$n = count( $f[ $k ] );
			if ( $n ) {
				$bits[] = sprintf( $label, $n ) . ( $n > 1 && 'overdue' !== $k ? 's' : '' );
			}
		}
		$blocks = array();
		if ( $f['overdue'] ) {
			$blocks[] = array( 'details', 'Chase first', array_map( function ( $r ) { return array( 'Overdue', $r[0] . ' · ' . $r[1] ); }, $f['overdue'] ) );
		}
		if ( $f['handoffs'] ) {
			$blocks[] = array( 'details', 'Handoffs', $f['handoffs'] );
		}
		if ( $f['pending'] ) {
			$blocks[] = array( 'details', 'Requests to answer', $f['pending'] );
		}
		if ( $f['work'] || $f['low'] ) {
			$blocks[] = array( 'details', 'Bikes & parts', array_merge( $f['work'], $f['low'] ) );
		}
		$blocks[] = array( 'buttons', array( array( 'Open the dashboard', $f['link'], true ) ) );
		return array(
			'kicker' => 'Crew · today, ' . $f['today'], 'headline' => 'Your day.', 'subline' => ( $bits ? implode( ', ', $bits ) : 'All quiet' ) . '.',
			'preheader' => implode( ', ', $bits ), 'blocks' => $blocks, 'footer' => 'crew',
		);
	}

	/* =========================================================
	 * Rendering
	 * ========================================================= */

	private static function e( $s ) {
		return esc_html( (string) $s );
	}

	/** Paragraph text: escaped, blank lines become paragraph breaks. */
	private static function para( $s ) {
		return nl2br( self::e( $s ) );
	}

	public static function render_html( array $m, $subject = '' ) {
		$ink   = self::INK;
		$paper = self::PAPER;
		$sans  = self::SANS;
		$mono  = self::MONO;
		$logo  = CC_FLEET_URL . 'assets/email/logo.png';
		$icon  = CC_FLEET_URL . 'assets/email/icon-paper.png';
		$rows  = '';
		foreach ( $m['blocks'] as $b ) {
			$rows .= self::block_html( $b );
		}
		$crew   = 'crew' === ( $m['footer'] ?? 'rider' );
		$sign   = $crew ? 'Fleet Manager · ' . CC_Fleet_Settings::get( 'from_name' ) : 'Ride hard, look after it, pass it on.';
		$pre    = self::e( $m['preheader'] ?? '' );
		$kicker = ! empty( $m['kicker'] ) ? '<tr><td class="cc-kicker" style="background:' . self::PINK . ';padding:11px 16px;text-align:center;font:500 12px/1 ' . $mono . ';letter-spacing:0.12em;text-transform:uppercase;color:' . $ink . '">' . self::e( $m['kicker'] ) . '</td></tr>' : '';
		$sub    = ! empty( $m['subline'] ) ? '<p style="margin:2px 0 0;font:400 30px/1.12 ' . $sans . ';letter-spacing:-0.015em;color:' . $ink . '" class="cc-sub">' . self::e( $m['subline'] ) . '</p>' : '';
		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>' . self::e( $subject ) . '</title>'
			. '<link href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;700;800&amp;family=IBM+Plex+Mono:wght@500&amp;display=swap" rel="stylesheet">'
			. '<style>body{margin:0;padding:0;background:' . $paper . '}a{color:' . $ink . '}@media (max-width:620px){.cc-wrap{width:100%!important}.cc-pad{padding-left:20px!important;padding-right:20px!important}.cc-h1{font-size:28px!important}.cc-sub{font-size:24px!important}.cc-k{display:block!important;width:auto!important;padding-bottom:2px!important}.cc-v{display:block!important}}</style></head>'
			. '<body style="margin:0;padding:0;background:' . $paper . '">'
			. '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:' . $paper . '">' . $pre . '&#8199;&#65279;&#847;&#8199;&#65279;&#847;</div>'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $paper . '"><tr><td align="center" style="padding:0">'
			. '<table role="presentation" class="cc-wrap" width="600" cellspacing="0" cellpadding="0" border="0" style="width:600px;max-width:600px;background:' . $paper . '">'
			. '<tr><td align="center" style="padding:34px 20px 30px"><img src="' . esc_url( $logo ) . '" width="170" height="68" alt="Community Connect" style="display:block;width:170px;height:68px;border:0"></td></tr>'
			. $kicker
			. '<tr><td class="cc-pad" style="padding:32px 40px 0"><h1 class="cc-h1" style="margin:0;font:800 36px/1.08 ' . $sans . ';letter-spacing:-0.02em;color:' . $ink . '">' . self::e( $m['headline'] ) . '</h1>' . $sub . '</td></tr>'
			. $rows
			. '<tr><td class="cc-pad" style="padding:36px 40px 40px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $ink . '"><tr><td align="center" style="padding:36px 24px;text-align:center">'
			. '<img src="' . esc_url( $icon ) . '" width="56" height="56" alt="" style="display:block;margin:0 auto 14px;width:56px;height:56px;border:0">'
			. '<p style="margin:0 0 12px;font:700 22px/1.15 ' . $sans . ';letter-spacing:-0.02em;color:' . $paper . '">' . self::e( $sign ) . '</p>'
			. '<p style="margin:0 0 10px;font:400 14px/1.45 ' . $sans . ';color:' . $paper . '">Community Connect NPC · Cape Town<br><span style="font:500 11px/1.4 ' . $mono . ';letter-spacing:0.04em">Reg. 2025/713989/08</span></p>'
			. '<p style="margin:0;font:400 11px/1.5 ' . $sans . ';color:' . self::SAND . '">' . ( $crew ? 'Sent by the CC Fleet plugin.' : 'Reply to this email to reach the crew.' ) . '</p>'
			. '</td></tr></table></td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	private static function block_html( array $b ) {
		$ink  = self::INK;
		$sans = self::SANS;
		$mono = self::MONO;
		$pad  = 'class="cc-pad" style="padding:%spx 40px 0"';
		switch ( $b[0] ) {
			case 'p':
				return '<tr><td ' . sprintf( $pad, 20 ) . '><p style="margin:0;font:400 16px/1.5 ' . $sans . ';color:' . $ink . '">' . self::para( $b[1] ) . '</p></td></tr>';
			case 'small':
				return '<tr><td ' . sprintf( $pad, 20 ) . '><p style="margin:0;font:400 12px/1.5 ' . $sans . ';color:' . self::MUTED . '">' . self::para( $b[1] ) . '</p></td></tr>';
			case 'image':
				return '<tr><td style="padding:24px 0 0"><img src="' . esc_url( $b[1] ) . '" width="600" alt="' . esc_attr( $b[2] ) . '" style="display:block;width:100%;max-width:600px;height:auto;border:0"></td></tr>';
			case 'details':
				$rows = '';
				foreach ( $b[2] as $r ) {
					$rows .= '<tr><td class="cc-k" width="96" valign="top" style="width:96px;padding:9px 12px 9px 0;border-top:1px solid ' . self::SAND . ';font:500 11px/1.5 ' . $mono . ';letter-spacing:0.1em;text-transform:uppercase;color:' . self::MUTED . '">' . self::e( $r[0] ) . '</td><td class="cc-v" valign="top" style="padding:9px 0;border-top:1px solid ' . self::SAND . ';font:500 15px/1.4 ' . $sans . ';color:' . $ink . '">' . self::e( $r[1] ) . '</td></tr>';
				}
				return '<tr><td ' . sprintf( $pad, 24 ) . '><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1.5px solid ' . $ink . ';background:#FFFFFF"><tr><td style="padding:14px 16px 6px"><p style="margin:0 0 8px;font:700 15px/1.2 ' . $sans . ';color:' . $ink . '">' . self::e( $b[1] ) . '</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $rows . '</table></td></tr></table></td></tr>';
			case 'steps':
				$rows = '';
				foreach ( $b[2] as $i => $t ) {
					$rows .= '<tr><td width="40" valign="top" style="width:40px;padding:0 0 14px"><div style="width:26px;height:26px;border-radius:13px;background:' . $ink . ';color:' . self::PAPER . ';text-align:center;font:500 12px/26px ' . $mono . '">' . ( $i + 1 ) . '</div></td><td valign="top" style="padding:3px 0 14px;font:400 15px/1.45 ' . $sans . ';color:' . $ink . '">' . self::e( $t ) . '</td></tr>';
				}
				return '<tr><td ' . sprintf( $pad, 28 ) . '><p style="margin:0 0 12px;font:700 15px/1.2 ' . $sans . ';color:' . $ink . '">' . self::e( $b[1] ) . '</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $rows . '</table></td></tr>';
			case 'callout':
				$bg   = 'cream' === ( $b[2] ?? 'pink' ) ? self::CREAM : self::PINK;
				$html = array();
				foreach ( (array) $b[1] as $part ) {
					$html[] = is_array( $part ) ? self::e( $part[0] ) . '<a href="' . esc_url( $part[2] ) . '" style="color:' . $ink . ';font-weight:700">' . self::e( $part[1] ) . '</a>.' : self::e( $part );
				}
				return '<tr><td ' . sprintf( $pad, 24 ) . '><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td style="background:' . $bg . ';padding:14px 16px;font:400 14px/1.45 ' . $sans . ';color:' . $ink . '">' . implode( '<br>', $html ) . '</td></tr></table></td></tr>';
			case 'buttons':
				$out = '';
				foreach ( $b[1] as $btn ) {
					$fill = ! empty( $btn[2] );
					$out .= '<tr><td style="padding:0 0 12px"><a href="' . esc_url( $btn[1] ) . '" style="display:block;border:1.5px solid ' . $ink . ';background:' . ( $fill ? $ink : 'transparent' ) . ';color:' . ( $fill ? self::PAPER : $ink ) . ';text-decoration:none;text-align:center;padding:17px 12px;font:500 13px/1.2 ' . $sans . ';letter-spacing:0.06em;text-transform:uppercase">' . self::e( $btn[0] ) . '</a></td></tr>';
				}
				return '<tr><td ' . sprintf( $pad, 24 ) . '><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $out . '</table></td></tr>';
		}
		return '';
	}

	public static function render_text( array $m ) {
		$out = array( strtoupper( $m['kicker'] ?? '' ), trim( $m['headline'] . ' ' . ( $m['subline'] ?? '' ) ) );
		foreach ( $m['blocks'] as $b ) {
			switch ( $b[0] ) {
				case 'p':
				case 'small':
					$out[] = $b[1];
					break;
				case 'details':
					$out[] = strtoupper( $b[1] ) . "\n" . implode( "\n", array_map( function ( $r ) { return str_pad( $r[0] . ':', 12 ) . $r[1]; }, $b[2] ) );
					break;
				case 'steps':
					$out[] = strtoupper( $b[1] ) . "\n" . implode( "\n", array_map( function ( $t, $i ) { return ( $i + 1 ) . '. ' . $t; }, $b[2], array_keys( $b[2] ) ) );
					break;
				case 'callout':
					$out[] = implode( "\n", array_map( function ( $p ) { return is_array( $p ) ? $p[0] . $p[1] . ': ' . $p[2] : $p; }, (array) $b[1] ) );
					break;
				case 'buttons':
					$out[] = implode( "\n", array_map( function ( $btn ) { return $btn[0] . ': ' . $btn[1]; }, $b[1] ) );
					break;
			}
		}
		$out[] = 'crew' === ( $m['footer'] ?? 'rider' ) ? '— Fleet Manager · ' . CC_Fleet_Settings::get( 'from_name' ) : "Ride hard, look after it, pass it on.\n— " . CC_Fleet_Settings::get( 'from_name' );
		return implode( "\n\n", array_filter( $out, 'strlen' ) );
	}

	/* =========================================================
	 * Preview (Fleet Manager → Settings → Preview the emails)
	 * ========================================================= */

	public static function samples() {
		$cal = 'https://calendar.google.com/calendar/render?action=TEMPLATE';
		return array(
			'Rider · request received' => array( 'We got your bike request', self::msg_received( array( 'name' => 'Naledi', 'bike' => 'Any bike in size 58', 'dates' => 'Sat 5 Dec → Mon 7 Dec 2026', 'plan' => 'Overnighter to Greyton', 'whatsapp' => CC_Fleet_Settings::get( 'whatsapp_number' ) ? CC_Fleet_Phone::wa_link( CC_Fleet_Settings::get( 'whatsapp_number' ), 'Hi' ) : '' ) ) ),
			'Rider · confirmed'        => array( "You're on — bike confirmed", self::msg_confirmed( array( 'name' => 'Naledi', 'bike' => 'Erdgeschoss 58 · Mos Def', 'dates' => 'Sat 5 Dec → Mon 7 Dec 2026', 'from' => 'Thandi, Fri 4 Dec', 'from_first' => 'Thandi', 'return' => 'Mon 7 Dec 2026', 'photo' => '', 'calendar' => $cal, 'bike_url' => self::fleet_url(), 'cancel' => home_url( '/' ), 'agreement' => CC_Fleet_Landing::agreement_url() ?: home_url( '/' ), 'handoff_notes' => CC_Fleet_Settings::get( 'handoff_notes' ) ?: '[Handoff note from Settings, e.g. “Pedals: bring your own.”]', 'pressure' => 'Front 32 psi (2.2 bar) · Rear 34 psi (2.3 bar)', 'pressure_note' => 'Starting point for 68 kg on 45 mm tubeless tyres, mixed gravel. Loose or rough: 2–3 psi lower. Mostly tar: 3–5 psi higher. Check before every ride.' ) ) ),
			'Rider · return reminder'  => array( 'Bike due back Mon 7 Dec 2026', self::msg_reminder( array( 'name' => 'Naledi', 'bike' => 'Erdgeschoss 58 · Mos Def', 'cc' => 'Erdgeschoss 58', 'due' => 'Mon 7 Dec 2026', 'when' => 'tomorrow', 'next' => 'Sipho', 'dropoff' => CC_Fleet_Settings::get( 'dropoff_place' ), 'report' => self::crew_mailto( 'Problem' ), 'extend' => self::crew_mailto( 'One more day' ) ) ) ),
			'Rider · not this time'    => array( 'About your bike request', self::msg_declined( array( 'name' => 'Naledi', 'bike' => 'Kettensäge 56 · Team', 'cc' => 'Kettensäge 56', 'dates' => 'Sat 5 Dec → Mon 7 Dec 2026', 'free' => array( array( 'Erdgeschoss 56 · Pang! Pink', self::fleet_url() ), array( 'Kettensäge 54 · Give ’em hell caramel', self::fleet_url() ) ), 'busy' => true, 'later' => 'Thu 10 Dec', 'later_url' => self::fleet_url() ) ) ),
			'Crew · new request'       => array( 'New bike request: size 58', self::msg_crew_request( array( 'name' => 'Naledi Khumalo', 'height' => 176, 'email' => 'naledi@example.org', 'phone' => '+27 82 000 0000', 'instagram' => 'naledi.rides', 'wa' => 'https://wa.me/27820000000', 'wa_status' => '', 'bike' => 'Any bike in size 58', 'any' => true, 'size' => '58', 'dates' => 'Sat 5 Dec → Mon 7 Dec 2026', 'plan' => 'Overnighter to Greyton', 'history' => '3 loans before', 'free' => array( 'CC-08 Erdgeschoss 58', 'CC-13 Kettensäge 58' ), 'clashes' => 0, 'link' => admin_url( 'admin.php?page=cc-fleet' ) ) ) ),
			'Rider · new dates'        => array( 'New dates for your bike: Sat 5 Dec → Wed 9 Dec 2026', self::msg_dates( array( 'name' => 'Naledi', 'bike' => 'Erdgeschoss 58 · Mos Def', 'old' => 'Sat 5 Dec → Mon 7 Dec 2026', 'new' => 'Sat 5 Dec → Wed 9 Dec 2026', 'longer' => true, 'note' => 'Two more days for the Baviaans detour', 'calendar' => 'https://calendar.google.com/calendar/render?action=TEMPLATE' ) ) ),
			'Crew · handoff check'     => array( 'Check tomorrow’s handoffs: 2 reminders', self::msg_crew_check( array( 'send_on' => 'Sun 6 Dec', 'days' => 1, 'items' => array(
				array( 'bike' => 'CC-08 Erdgeschoss 58', 'rider' => 'Naledi Khumalo', 'rider_c' => '+27 82 000 0000', 'rider_wa' => 'https://wa.me/27820000000', 'due' => 'Mon 7 Dec 2026', 'next' => 'Sipho Dlamini · from Wed 9 Dec', 'next_c' => '+27 83 000 0000', 'next_wa' => 'https://wa.me/27830000000', 'place' => 'Woodstock Cycleworks', 'time' => 'Tue 8 Dec, 17:30', 'note' => 'Sipho brings his own pedals.', 'dropoff' => '', 'edited' => false, 'edit' => admin_url( 'admin.php?page=cc-fleet' ) ),
				array( 'bike' => 'CC-05 Kettensäge 54', 'rider' => 'Gabri Moreau', 'rider_c' => '+27 84 000 0000', 'rider_wa' => 'https://wa.me/27840000000', 'due' => 'Mon 7 Dec 2026', 'next' => '', 'next_c' => '', 'next_wa' => '', 'place' => '', 'time' => '', 'note' => '', 'dropoff' => CC_Fleet_Settings::get( 'dropoff_place' ), 'edited' => true, 'edit' => admin_url( 'admin.php?page=cc-fleet' ) ),
			) ) ) ),
			'Rider · waiting list'     => array( 'You’re on the waiting list: Kettensäge 56', self::msg_wl_joined( array( 'name' => 'Naledi', 'bike' => 'Kettensäge 56 · Team', 'cc' => 'Kettensäge 56', 'dates' => 'Sat 5 Dec → Mon 7 Dec 2026', 'place' => 2, 'hours' => 24, 'free' => array( array( 'Erdgeschoss 56 is free for your dates', self::fleet_url(), false ) ), 'leave' => home_url( '/' ) ) ) ),
			'Rider · it’s free (offer)' => array( 'It’s free: Kettensäge 56, Sat 5 Dec → Mon 7 Dec 2026', self::msg_wl_offer( array( 'name' => 'Naledi', 'bike' => 'Kettensäge 56 · Team', 'dates' => 'Sat 5 Dec → Mon 7 Dec 2026', 'by' => 'Wed 2 Dec, 15:30', 'hours' => 24, 'short' => false, 'yes' => home_url( '/' ), 'no' => home_url( '/' ), 'photo' => '' ) ) ),
			'Rider · not this round'   => array( 'About Kettensäge 56 · Team', self::msg_wl_gone( array( 'name' => 'Naledi', 'bike' => 'Kettensäge 56 · Team', 'dates' => 'Sat 5 Dec → Mon 7 Dec 2026', 'why' => 'lapsed', 'hours' => 24, 'fleet' => self::fleet_url(), 'leave' => home_url( '/' ) ) ) ),
			'Rider · list closed'      => array( 'Your waiting list spot: Kettensäge 56 · Team', self::msg_wl_closed( array( 'name' => 'Naledi', 'bike' => 'Kettensäge 56 · Team', 'cc' => 'Kettensäge 56', 'dates' => 'Sat 5 Dec → Mon 7 Dec 2026', 'later' => 'Thu 10 Dec', 'later_url' => self::fleet_url(), 'fleet' => self::fleet_url() ) ) ),
			'Rider · thanks for the heads-up' => array( 'Booking cancelled: thanks for the heads-up', self::msg_wl_thanks( array( 'name' => 'Thandi', 'bike' => 'Kettensäge 56 · Team', 'dates' => 'Sat 5 Dec → Mon 7 Dec 2026', 'fleet' => self::fleet_url() ) ) ),
			'Crew · spot open'         => array( 'Spot open: CC-12, 2 waiting', self::msg_wl_crew_spot( array( 'cc' => 'CC-12', 'bike' => 'CC-12 Kettensäge 56', 'why' => 'Thandi Mokoena cancelled CC-12, Sat 5 Dec → Mon 7 Dec 2026.', 'n' => 2, 'rows' => array( array( '#1', 'Naledi Khumalo · Sat 5 Dec → Mon 7 Dec 2026 · asked 20 Nov · first loan' ), array( '#2', 'Luke Peters · Sat 5 Dec → Sun 6 Dec 2026 · asked 21 Nov · 3 loans, last Aug 2026' ) ), 'first' => 'Naledi', 'hours' => 24, 'short' => 0, 'offer' => admin_url( 'admin.php?page=cc-fleet' ), 'list' => admin_url( 'admin.php?page=cc-fleet#waitlist' ) ) ) ),
			'Crew · welcome (new login)' => array( 'Welcome to the crew: your Fleet Manager login', self::msg_crew_welcome( array( 'name' => 'Kgotso', 'user' => 'kgotso', 'set' => wp_login_url(), 'login' => wp_login_url() ) ) ),
			'Crew · waiting list note' => array( 'Naledi didn’t answer: CC-12', self::msg_wl_crew_note( array( 'headline' => 'No answer in time.', 'lines' => array( 'Naledi didn’t say yes to CC-12 Kettensäge 56 within 24 hours. They stay on the list.', 'Offered to Luke, next in line.' ), 'link' => admin_url( 'admin.php?page=cc-fleet#waitlist' ), 'label' => 'Open the waiting list' ) ) ),
			'Crew · daily digest'      => array( 'Fleet today: 1 overdue, 2 to review', self::msg_digest( array( 'today' => 'Fri 4 Dec', 'overdue' => array( array( 'CC-03', 'Camryn · due Tue 1 Dec' ) ), 'handoffs' => array( array( 'Fri 4 Dec', 'CC-08 → Naledi' ), array( 'Sat 5 Dec', 'CC-05 → Gabri' ) ), 'pending' => array( array( 'Waiting', 'Naledi · size 58 · Sat 5 Dec' ), array( 'Waiting', 'Luke · CC-07 · Sat 12 Dec' ) ), 'work' => array( array( 'Workshop', 'CC-12 · spoke' ) ), 'low' => array( array( 'Low stock', 'SRAM metal sintered brake pads · 3 left' ) ), 'link' => admin_url( 'admin.php?page=cc-fleet' ) ) ) ),
		);
	}

	public static function preview_page() {
		$out = '<!doctype html><html><head><meta charset="utf-8"><title>CC Fleet · email preview</title><style>body{margin:0;background:#ddd;font:14px/1.4 system-ui,sans-serif}.bar{padding:16px 24px;background:#141217;color:#F4EEE4}.grid{display:flex;flex-wrap:wrap;gap:32px;padding:32px}.m{background:#fff}.m h2{margin:0;padding:10px 14px;font-size:13px;border-bottom:1px solid #ddd}.m p{margin:0;padding:6px 14px;color:#555;font-size:12px}iframe{display:block;width:600px;height:1400px;border:0}</style></head><body><div class="bar">Email preview with sample data. Real emails use the loan’s details.</div><div class="grid">';
		foreach ( self::samples() as $label => $s ) {
			$out .= '<div class="m"><h2>' . esc_html( $label ) . '</h2><p>Subject: ' . esc_html( $s[0] ) . '</p><iframe title="' . esc_attr( $label ) . '" srcdoc="' . esc_attr( self::render_html( $s[1], $s[0] ) ) . '"></iframe></div>';
		}
		return $out . '</div></body></html>';
	}
}
CC_Fleet_Emails::init_hooks();
