<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API: /wp-json/cc/v1/...
 *
 * Public (no login): bikes, availability, request a bike.
 * Admin (capability manage_cc_fleet): loans list and status changes — ready for a future phone app.
 */
class CC_Fleet_Rest {

	const NS = 'cc/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( self::NS, '/bikes', array(
			'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'bikes' ),
		) );
		register_rest_route( self::NS, '/bikes/(?P<bike>[A-Za-z0-9-]+)', array(
			'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'bike' ),
		) );
		register_rest_route( self::NS, '/bikes/(?P<bike>[A-Za-z0-9-]+)/availability', array(
			'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'availability' ),
			'args' => array( 'from' => array( 'type' => 'string' ), 'to' => array( 'type' => 'string' ) ),
		) );
		register_rest_route( self::NS, '/available', array(
			'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'available' ),
			'args' => array( 'start' => array( 'required' => true ), 'end' => array( 'required' => true ), 'size' => array() ),
		) );
		register_rest_route( self::NS, '/fleet-status', array(
			'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'fleet_status' ),
		) );
		register_rest_route( self::NS, '/requests', array(
			'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'request' ),
		) );
		register_rest_route( self::NS, '/admin/loans', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'admin_loans' ),
			'permission_callback' => function () { return current_user_can( 'manage_cc_fleet' ); },
		) );
		register_rest_route( self::NS, '/admin/loans/(?P<id>\d+)/status', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'admin_status' ),
			'permission_callback' => function () { return current_user_can( 'manage_cc_fleet' ); },
		) );
	}

	private static function resolve_bike( $ref ) {
		$id = ctype_digit( (string) $ref ) ? (int) $ref : CC_Fleet_Bikes::find_by_cc_id( $ref );
		if ( ! $id || CC_Fleet_Bikes::CPT !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) || '1' !== CC_Fleet_Bikes::get( $id, 'show_on_site' ) ) {
			return 0;
		}
		return $id;
	}

	private static function nocache( WP_REST_Response $r ) {
		$r->header( 'Cache-Control', 'no-store, max-age=0' );
		return $r;
	}

	public static function bikes( WP_REST_Request $req ) {
		$out = array();
		foreach ( CC_Fleet_Bikes::public_ids() as $id ) {
			$out[] = CC_Fleet_Bikes::public_data( $id );
		}
		return self::nocache( new WP_REST_Response( $out ) );
	}

	public static function bike( WP_REST_Request $req ) {
		$id = self::resolve_bike( $req['bike'] );
		if ( ! $id ) {
			return new WP_Error( 'cc_not_found', 'Bike not found.', array( 'status' => 404 ) );
		}
		return self::nocache( new WP_REST_Response( CC_Fleet_Bikes::public_data( $id ) ) );
	}

	public static function availability( WP_REST_Request $req ) {
		$id = self::resolve_bike( $req['bike'] );
		if ( ! $id ) {
			return new WP_Error( 'cc_not_found', 'Bike not found.', array( 'status' => 404 ) );
		}
		$today = CC_Fleet_Settings::today();
		$from  = CC_Fleet_Loans::valid_date( $req['from'] ) ? $req['from'] : $today;
		$to    = CC_Fleet_Loans::valid_date( $req['to'] ) ? $req['to'] : CC_Fleet_Loans::add_days( $from, 90 );
		if ( CC_Fleet_Loans::days_between( $from, $to ) > 800 || $to < $from ) {
			return new WP_Error( 'cc_range', 'Range too large.', array( 'status' => 400 ) );
		}
		$s = CC_Fleet_Settings::all();
		return self::nocache( new WP_REST_Response( array(
			'bike'      => CC_Fleet_Bikes::get( $id, 'cc_id' ),
			'state'     => CC_Fleet_Bikes::state_now( $id ),
			'from'      => $from,
			'to'        => $to,
			'today'     => $today,
			'earliest'  => CC_Fleet_Loans::add_days( $today, (int) $s['min_notice_days'] ),
			'latest'    => CC_Fleet_Loans::horizon(),
			'max_days'  => (int) $s['max_loan_days'],
			'same_day_handoff' => (bool) $s['same_day_handoff'],
			'booked'    => 'active' === CC_Fleet_Bikes::get( $id, 'state' ) ? CC_Fleet_Loans::booked_ranges( $id, $from, $to ) : array( array( 'start' => $from, 'end' => $to, 'kind' => 'unavailable' ) ),
		) ) );
	}

	public static function available( WP_REST_Request $req ) {
		$valid = CC_Fleet_Loans::validate_range( $req['start'], $req['end'], false );
		if ( is_wp_error( $valid ) ) {
			$valid->add_data( array( 'status' => 400 ) );
			return $valid;
		}
		$ids = CC_Fleet_Loans::available_bikes( $req['start'], $req['end'], sanitize_text_field( (string) $req['size'] ) );
		return self::nocache( new WP_REST_Response( array_map( array( 'CC_Fleet_Bikes', 'public_data' ), $ids ) ) );
	}

	public static function fleet_status() {
		$counts = array( 'available' => 0, 'out' => 0, 'workshop' => 0, 'retired' => 0 );
		foreach ( CC_Fleet_Bikes::public_ids() as $id ) {
			$s = CC_Fleet_Bikes::state_now( $id );
			$counts[ $s ] = ( $counts[ $s ] ?? 0 ) + 1;
		}
		$year_start = wp_date( 'Y' ) . '-01-01';
		return self::nocache( new WP_REST_Response( array(
			'bikes'           => array_sum( $counts ) - $counts['retired'],
			'states'          => $counts,
			'loans_this_year' => CC_Fleet_Loans::count( array( 'status' => array( 'approved', 'out', 'returned' ), 'from' => $year_start, 'to' => wp_date( 'Y' ) . '-12-31' ) ),
			'riders'          => CC_Fleet_Riders::count(),
		) ) );
	}

	/**
	 * Public bike request. Spam protection: honeypot, minimum fill time, per-IP rate limit.
	 * Body: bike (CC-xx or "any"), size, start, end, name, email, phone, instagram, height, plan, agree, consent, website (honeypot), t (form render time)
	 */
	public static function request( WP_REST_Request $req ) {
		$p = $req->get_json_params() ?: $req->get_body_params();
		$p = is_array( $p ) ? $p : array();

		if ( ! empty( $p['website'] ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 ); // Bot: pretend success.
		}
		if ( ! empty( $p['t'] ) && ( time() - (int) $p['t'] ) < 3 ) {
			return new WP_Error( 'cc_fast', 'That was quick — please try again.', array( 'status' => 429 ) );
		}
		$ip_key = 'cc_rl_' . md5( ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt() );
		$hits   = (int) get_transient( $ip_key );
		if ( $hits >= (int) CC_Fleet_Settings::get( 'rate_limit_per_hour' ) ) {
			return new WP_Error( 'cc_rate', 'Too many requests from your connection. Please email us instead.', array( 'status' => 429 ) );
		}

		$errors = array();
		$name   = sanitize_text_field( $p['name'] ?? '' );
		$email  = sanitize_email( $p['email'] ?? '' );
		$start  = sanitize_text_field( $p['start'] ?? '' );
		$end    = sanitize_text_field( $p['end'] ?? '' );
		if ( strlen( $name ) < 2 ) {
			$errors['name'] = 'Please tell us your name.';
		}
		$email_issue = CC_Fleet_Phone::email_problem( $email );
		if ( $email_issue ) {
			$errors['email'] = $email_issue;
		}
		$phone = CC_Fleet_Phone::normalize( sanitize_text_field( $p['phone'] ?? '' ), sanitize_text_field( $p['phone_cc'] ?? '27' ) );
		if ( is_wp_error( $phone ) ) {
			$errors['phone'] = $phone->get_error_message();
		}
		$insta = trim( sanitize_text_field( $p['instagram'] ?? '' ) );
		if ( '' === $insta ) {
			$errors['instagram'] = 'Your Instagram handle, or write “none”.';
		} elseif ( in_array( strtolower( trim( $insta, '@ .' ) ), array( 'none', 'no', 'n/a', 'na', '-' ), true ) ) {
			$insta = '';
		} elseif ( ! preg_match( '/^@?[A-Za-z0-9._]{1,30}$/', $insta ) ) {
			$errors['instagram'] = 'Just the handle please, e.g. @yourname.';
		}
		$height = (int) ( $p['height'] ?? 0 );
		if ( $height < 120 || $height > 220 ) {
			$errors['height'] = 'Your height in cm helps us pick the right size (120–220).';
		}
		$plan = trim( sanitize_textarea_field( $p['plan'] ?? '' ) );
		if ( strlen( $plan ) < 3 ) {
			$errors['plan'] = 'Tell us briefly what you’re planning.';
		}
		if ( empty( $p['agree'] ) ) {
			$errors['agree'] = 'Please accept the borrowing agreement.';
		}
		if ( empty( $p['consent'] ) ) {
			$errors['consent'] = 'We need to store your details to arrange the loan.';
		}
		// Riding an event: an event from the calendar, or "other" for a race we don't list.
		$event_ref = sanitize_text_field( (string) ( $p['event_id'] ?? '' ) );
		$event_id  = 0;
		$event     = null;
		if ( 'other' === $event_ref ) {
			$event_id = -1;
		} elseif ( (int) $event_ref > 0 && class_exists( 'CC_Fleet_Records' ) ) {
			$event = CC_Fleet_Records::get( 'events', (int) $event_ref );
			if ( $event && ! (int) $event->cancelled ) {
				$event_id = (int) $event->id;
			} else {
				$errors['event_id'] = 'That event isn’t on our calendar any more. Pick another, or “Another race”.';
			}
		}
		if ( $event_id && empty( $p['race_agree'] ) ) {
			$errors['race_agree'] = 'For an event or race, please accept the racing terms.';
		}
		$range = CC_Fleet_Loans::validate_range( $start, $end, true );
		if ( is_wp_error( $range ) ) {
			$errors['dates'] = $range->get_error_message();
		}

		$bike_ref = sanitize_text_field( $p['bike'] ?? 'any' );
		$size     = preg_replace( '/[^0-9]/', '', (string) ( $p['size'] ?? '' ) );
		$bike_id  = 0;
		if ( 'any' !== strtolower( $bike_ref ) ) {
			$bike_id = self::resolve_bike( $bike_ref );
			if ( ! $bike_id ) {
				$errors['bike'] = 'That bike is not available to borrow.';
			}
		} elseif ( ! $size ) {
			$errors['bike'] = 'Pick a bike or a size.';
		}
		if ( $errors ) {
			return new WP_Error( 'cc_invalid', 'Please check the form.', array( 'status' => 400, 'fields' => $errors ) );
		}
		if ( $bike_id && ! CC_Fleet_Loans::is_available( $bike_id, $start, $end ) ) {
			$alts = array_map( function ( $id ) { return CC_Fleet_Bikes::get( $id, 'cc_id' ); },
				CC_Fleet_Loans::available_bikes( $start, $end, CC_Fleet_Bikes::get( $bike_id, 'size' ) ) );
			return new WP_Error( 'cc_conflict', 'Sorry, that bike is booked for some of those dates.', array( 'status' => 409, 'alternatives' => $alts ) );
		}

		$rider_id = CC_Fleet_Riders::upsert( array(
			'name' => $name, 'email' => $email,
			'phone' => $phone, 'instagram' => $insta,
			'height_cm' => $height, 'consent' => true,
		) );
		$loan_id = CC_Fleet_Loans::create( array(
			'bike_id' => $bike_id, 'rider_id' => $rider_id, 'start_date' => $start, 'end_date' => $end,
			'status' => 'requested', 'plan' => ( $event ? '[' . $event->name . '] ' : ( -1 === $event_id ? '[Race] ' : '' ) ) . $plan, 'source' => 'web',
			'event_id' => max( 0, $event_id ), 'race' => $event_id ? 1 : 0,
			'requested_size' => $bike_id ? CC_Fleet_Bikes::get( $bike_id, 'size' ) : $size,
		) );
		if ( is_wp_error( $loan_id ) ) {
			$loan_id->add_data( array( 'status' => 409 ) );
			return $loan_id;
		}
		set_transient( $ip_key, $hits + 1, HOUR_IN_SECONDS );
		$wa = CC_Fleet_Emails::whatsapp_confirm_link( $loan_id );
		return new WP_REST_Response( array(
			'ok'       => true,
			'message'  => "Thanks {$name} — your request is in. We'll confirm by email within a couple of days." . ( $wa ? ' One more thing: tap below to say hi on WhatsApp, so we know we can reach you there.' : '' ),
			'whatsapp' => $wa,
		), 201 );
	}

	public static function admin_loans( WP_REST_Request $req ) {
		$loans = CC_Fleet_Loans::query( array(
			'status' => $req['status'] ? explode( ',', $req['status'] ) : array(),
			'from' => $req['from'], 'to' => $req['to'], 'limit' => $req['per_page'] ?: 100, 'order' => 'ASC',
		) );
		return rest_ensure_response( array_map( function ( $l ) {
			$r = CC_Fleet_Riders::get( $l->rider_id );
			return array( 'id' => (int) $l->id, 'bike' => $l->bike_id ? CC_Fleet_Bikes::get( $l->bike_id, 'cc_id' ) : null,
				'rider' => $r ? array( 'name' => $r->name, 'email' => $r->email, 'phone' => $r->phone, 'instagram' => $r->instagram ) : null,
				'start' => $l->start_date, 'end' => $l->end_date, 'status' => $l->status, 'overdue' => CC_Fleet_Loans::is_overdue( $l ), 'plan' => $l->plan );
		}, $loans ) );
	}

	public static function admin_status( WP_REST_Request $req ) {
		$res = CC_Fleet_Loans::set_status( (int) $req['id'], sanitize_key( $req['status'] ), ! isset( $req['notify'] ) || (bool) $req['notify'] );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 409 ) );
			return $res;
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
