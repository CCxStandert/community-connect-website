<?php
defined( 'ABSPATH' ) || exit;

/**
 * Riders ("Community Connectors"). Private: never exposed through the public API.
 */
class CC_Fleet_Riders {

	public static function table() {
		return CC_Fleet_Install::table( 'riders' );
	}

	public static function get( $id ) {
		global $wpdb;
		if ( ! $id ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
	}

	/**
	 * Same person? Email first; without one, Instagram handle, then phone digits, then exact name.
	 * Many riders in the old app have no email, so matching on email alone would duplicate them.
	 */
	public static function find_match( array $c ) {
		global $wpdb;
		$t = self::table();
		if ( ! empty( $c['email'] ) && ( $r = self::find_by_email( $c['email'] ) ) ) {
			return $r;
		}
		$ig = strtolower( ltrim( (string) ( $c['instagram'] ?? '' ), '@' ) );
		if ( $ig && ( $r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE LOWER(instagram) = %s LIMIT 1", $ig ) ) ) ) {
			return $r;
		}
		$digits = substr( preg_replace( '/\D/', '', (string) ( $c['phone'] ?? '' ) ), -9 );
		if ( strlen( $digits ) >= 9 ) {
			foreach ( $wpdb->get_results( "SELECT * FROM $t WHERE phone <> ''" ) as $r ) {
				if ( substr( preg_replace( '/\D/', '', $r->phone ), -9 ) === $digits && 0 === strcasecmp( trim( $r->name ), trim( (string) ( $c['name'] ?? '' ) ) ) ) {
					return $r; // Phone + name: two riders in the old app share a phone number.
				}
			}
		}
		if ( ! empty( $c['name'] ) && empty( $c['email'] ) && ! $ig && ! $digits ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE name = %s AND email = '' LIMIT 1", $c['name'] ) );
		}
		return null;
	}

	public static function find_by_email( $email ) {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( ! $email ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE LOWER(email) = %s LIMIT 1', $email ) );
	}

	/** Create, or update the existing rider with the same email. Returns rider id. */
	public static function upsert( array $data ) {
		global $wpdb;
		$now   = current_time( 'mysql' );
		$clean = array(
			'name'      => sanitize_text_field( $data['name'] ?? '' ),
			'email'     => sanitize_email( $data['email'] ?? '' ),
			'phone'     => '' === trim( (string) ( $data['phone'] ?? '' ) ) ? '' : CC_Fleet_Phone::clean( sanitize_text_field( $data['phone'] ), $data['phone_cc'] ?? '27' ),
			'instagram' => ltrim( sanitize_text_field( $data['instagram'] ?? '' ), '@' ),
		);
		if ( ! empty( $data['height_cm'] ) ) {
			$clean['height_cm'] = max( 100, min( 230, (int) $data['height_cm'] ) );
		}
		if ( ! empty( $data['consent'] ) ) {
			$clean['consent_at'] = $now;
		}
		$existing = ! empty( $data['id'] ) ? self::get( (int) $data['id'] ) : self::find_match( $clean );
		if ( $existing ) {
			$update = array_filter( $clean, function ( $v ) { return '' !== $v && null !== $v; } );
			$update['updated_at'] = $now;
			$wpdb->update( self::table(), $update, array( 'id' => $existing->id ) );
			return (int) $existing->id;
		}
		$clean['created_at'] = $now;
		$clean['updated_at'] = $now;
		$wpdb->insert( self::table(), $clean );
		return (int) $wpdb->insert_id;
	}

	/** All riders for a dropdown: id => "Name · phone or email". */
	public static function options() {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( 'SELECT id, name, email, phone FROM ' . self::table() . " WHERE name <> '' ORDER BY name ASC" ) as $r ) {
			$out[ (int) $r->id ] = $r->name . ( $r->phone ? ' · ' . CC_Fleet_Phone::pretty( $r->phone ) : ( $r->email ? ' · ' . $r->email : '' ) );
		}
		return $out;
	}

	/** New rider from the backend (no email needed); returns id or WP_Error. */
	public static function create( array $data ) {
		global $wpdb;
		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( strlen( $name ) < 2 ) {
			return new WP_Error( 'cc_name', 'A rider needs a name.' );
		}
		$email = sanitize_email( $data['email'] ?? '' );
		if ( $email && self::find_by_email( $email ) ) {
			return new WP_Error( 'cc_dupe', 'A rider with that email already exists.' );
		}
		$now = current_time( 'mysql' );
		$wpdb->insert( self::table(), array(
			'name' => $name, 'email' => $email, 'instagram' => ltrim( sanitize_text_field( $data['instagram'] ?? '' ), '@' ),
			'phone' => '' === trim( (string) ( $data['phone'] ?? '' ) ) ? '' : CC_Fleet_Phone::clean( sanitize_text_field( $data['phone'] ), $data['phone_cc'] ?? '27' ),
			'height_cm' => absint( $data['height_cm'] ?? 0 ) ? max( 100, min( 230, absint( $data['height_cm'] ) ) ) : null,
			'notes' => sanitize_textarea_field( $data['notes'] ?? '' ), 'created_at' => $now, 'updated_at' => $now,
		) );
		$id = (int) $wpdb->insert_id;
		CC_Fleet_Install::log( 'rider', $id, 'created', 'backend' );
		return $id;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		$allowed = array( 'name', 'email', 'phone', 'instagram', 'height_cm', 'rating', 'notes', 'whatsapp' );
		$update  = array_intersect_key( $data, array_flip( $allowed ) );
		if ( isset( $update['phone'] ) && '' !== $update['phone'] ) {
			$update['phone'] = CC_Fleet_Phone::clean( $update['phone'], $data['phone_cc'] ?? '27' );
		}
		if ( isset( $update['whatsapp'] ) && ! in_array( $update['whatsapp'], array( '', 'yes', 'no' ), true ) ) {
			unset( $update['whatsapp'] );
		}
		$update['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( self::table(), $update, array( 'id' => (int) $id ) );
	}

	public static function search( $term = '', $limit = 50, $offset = 0 ) {
		global $wpdb;
		$t = self::table();
		$l = CC_Fleet_Install::table( 'loans' );
		$where = '1=1';
		$args  = array();
		if ( $term ) {
			$like  = '%' . $wpdb->esc_like( $term ) . '%';
			$where = '(r.name LIKE %s OR r.email LIKE %s OR r.instagram LIKE %s OR r.phone LIKE %s)';
			$args  = array( $like, $like, $like, $like );
		}
		$sql = "SELECT r.*, (SELECT COUNT(*) FROM $l x WHERE x.rider_id = r.id AND x.status IN ('approved','out','returned')) AS loans
			FROM $t r WHERE $where ORDER BY r.name ASC LIMIT %d OFFSET %d";
		$args[] = (int) $limit;
		$args[] = (int) $offset;
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}

	public static function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}
}
