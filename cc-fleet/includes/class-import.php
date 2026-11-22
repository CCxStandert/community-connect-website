<?php
defined( 'ABSPATH' ) || exit;

/**
 * CSV import for bikes (the FLEET tab of the parts-list sheet) and loans (from the old app).
 * Bikes are matched on "Bike ID", so re-importing an updated sheet updates bikes in place.
 */
class CC_Fleet_Import {

	/**
	 * @param string $file    Path to a CSV with the sheet's header row.
	 * @param array  $publish Bike IDs to show on the website (others are imported hidden).
	 * @return array{created:int,updated:int,skipped:int,errors:string[]}
	 */
	public static function bikes_csv( $file, array $publish = array() ) {
		$res  = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() );
		$rows = self::read_csv( $file );
		if ( is_wp_error( $rows ) ) {
			$res['errors'][] = $rows->get_error_message();
			return $res;
		}
		$map     = array();
		foreach ( CC_Fleet_Bikes::fields() as $key => $f ) {
			$map[ self::norm( $f[3] ) ] = $key;
		}
		$publish = array_map( 'strtoupper', array_map( 'trim', $publish ) );
		foreach ( $rows as $i => $row ) {
			$data = array();
			foreach ( $row as $header => $value ) {
				$k = $map[ self::norm( $header ) ] ?? null;
				if ( $k ) {
					$data[ $k ] = trim( (string) $value );
				}
			}
			$cc_id = strtoupper( $data['cc_id'] ?? '' );
			if ( ! preg_match( '/^[A-Z]{2}-\d+$/', $cc_id ) ) {
				$res['skipped']++;
				continue;
			}
			$data['cc_id']         = $cc_id;
			$data['date_added']    = self::date( $data['date_added'] ?? '' );
			$data['last_upgraded'] = self::date( $data['last_upgraded'] ?? '' );
			$title   = trim( $cc_id . ' ' . ( $data['model'] ?? '' ) . ' ' . ( $data['size'] ?? '' ) );
			$post_id   = CC_Fleet_Bikes::find_by_cc_id( $cc_id );
			$was_there = (bool) $post_id;
			if ( $post_id ) {
				wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
				$res['updated']++;
			} else {
				$post_id = wp_insert_post( array(
					'post_type' => CC_Fleet_Bikes::CPT, 'post_status' => 'publish',
					'post_title' => $title, 'post_name' => sanitize_title( $cc_id ),
				), true );
				if ( is_wp_error( $post_id ) ) {
					$res['errors'][] = "$cc_id: " . $post_id->get_error_message();
					continue;
				}
				$in_fleet = stripos( $data['sheet_status'] ?? '', 'not in fleet' ) === false;
				CC_Fleet_Bikes::set( $post_id, 'state', $in_fleet ? 'active' : 'retired' );
				CC_Fleet_Bikes::set( $post_id, 'show_on_site', in_array( $cc_id, $publish, true ) ? '1' : '0' );
				$res['created']++;
			}
			$changes = array();
			$tracked = CC_Fleet_Bikes::tracked_fields();
			foreach ( $data as $k => $v ) {
				$v   = sanitize_text_field( $v );
				$old = (string) CC_Fleet_Bikes::get( $post_id, $k );
				if ( $was_there && in_array( $k, $tracked, true ) && trim( $old ) !== trim( $v ) ) {
					$changes[] = array( $k, $old, $v );
				}
				CC_Fleet_Bikes::set( $post_id, $k, $v );
			}
			if ( $changes ) {
				// Shows in the bike's History; not counted as workshop work.
				CC_Fleet_Bikes::record_change( $post_id, $changes, array( 'date' => CC_Fleet_Settings::today(), 'type' => 'upgrade', 'note' => 'Updated from the parts list', 'workshop' => false ) );
			}
		}
		CC_Fleet_Install::log( 'import', 0, 'bikes', wp_json_encode( $res ) );
		return $res;
	}

	/**
	 * Loans CSV with headers: bike_id (CC-xx), name, email, phone, instagram, start, end, status, plan, notes
	 * Old loans are imported without clash checks or emails.
	 */
	public static function loans_csv( $file ) {
		global $wpdb;
		$res  = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'riders' => 0, 'errors' => array() );
		$rows = self::read_csv( $file );
		if ( is_wp_error( $rows ) ) {
			$res['errors'][] = $rows->get_error_message();
			return $res;
		}
		foreach ( $rows as $i => $r ) {
			$r       = array_change_key_case( array_map( 'trim', $r ), CASE_LOWER );
			$bike_id = CC_Fleet_Bikes::find_by_cc_id( $r['bike_id'] ?? '' );
			$start   = self::date( $r['start'] ?? '' );
			$end     = self::date( $r['end'] ?? '' );
			if ( '' === ( $r['bike_id'] ?? '' ) && '' === ( $r['start'] ?? '' ) && '' !== ( $r['name'] ?? '' ) ) {
				self::rider( $r ); // Rider without a loan in this file.
				$res['riders']++;
				continue;
			}
			if ( ! $bike_id || ! $start || ! $end ) {
				$res['skipped']++;
				$res['errors'][] = 'Row ' . ( $i + 2 ) . ': missing bike or dates';
				continue;
			}
			$rider_id = self::rider( $r );
			$status = strtolower( $r['status'] ?? 'returned' );
			$status = array( 'active' => 'out', 'upcoming' => 'approved', 'completed' => 'returned' )[ $status ] ?? $status;
			// Running the same file twice must not double the history.
			$dupe = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CC_Fleet_Loans::table() . ' WHERE bike_id = %d AND rider_id = %d AND start_date = %s AND end_date = %s LIMIT 1', $bike_id, $rider_id, $start, $end ) );
			if ( $dupe ) {
				$res['skipped']++;
				continue;
			}
			$id     = CC_Fleet_Loans::create( array(
				'bike_id' => $bike_id, 'rider_id' => $rider_id, 'start_date' => $start, 'end_date' => $end,
				'status' => isset( CC_Fleet_Loans::STATUSES[ $status ] ) ? $status : 'returned',
				'plan' => $r['plan'] ?? '', 'notes' => $r['notes'] ?? '', 'source' => 'import',
			), true );
			if ( is_wp_error( $id ) ) {
				$res['errors'][] = 'Row ' . ( $i + 2 ) . ': ' . $id->get_error_message();
				continue;
			}
			// History came from elsewhere: no automatic reminder emails for these.
			$wpdb->update( CC_Fleet_Loans::table(), array( 'reminder_sent' => 1 ), array( 'id' => $id ) );
			$res['created']++;
		}
		CC_Fleet_Install::log( 'import', 0, 'loans', wp_json_encode( array_diff_key( $res, array( 'errors' => 1 ) ) ) );
		return $res;
	}

	/**
	 * Merge riders that are clearly the same person: same name (ignoring case and spaces) and no
	 * conflicting email/Instagram/phone. Loans move to the oldest record; the empty copies are removed.
	 * Also stops automatic reminder emails for imported history.
	 * @return array{merged:int,removed:int,quiet:int}
	 */
	public static function tidy() {
		global $wpdb;
		$t      = CC_Fleet_Riders::table();
		$loans  = CC_Fleet_Loans::table();
		$out    = array( 'merged' => 0, 'removed' => 0, 'quiet' => 0 );
		$groups = array();
		foreach ( $wpdb->get_results( "SELECT * FROM $t ORDER BY id ASC" ) as $r ) {
			$groups[ strtolower( preg_replace( '/\s+/', '', $r->name ) ) ][] = $r;
		}
		foreach ( $groups as $rows ) {
			if ( count( $rows ) < 2 ) {
				continue;
			}
			$keep = array_shift( $rows );
			foreach ( $rows as $dup ) {
				$clash = false;
				foreach ( array( 'email', 'instagram' ) as $f ) {
					if ( $keep->$f && $dup->$f && strtolower( $keep->$f ) !== strtolower( $dup->$f ) ) {
						$clash = true;
					}
				}
				$pk = substr( preg_replace( '/\D/', '', $keep->phone ), -9 );
				$pd = substr( preg_replace( '/\D/', '', $dup->phone ), -9 );
				if ( $pk && $pd && $pk !== $pd ) {
					$clash = true;
				}
				if ( $clash ) {
					continue; // Same name, different person: leave both.
				}
				$wpdb->update( $loans, array( 'rider_id' => $keep->id ), array( 'rider_id' => $dup->id ) );
				$fill = array();
				foreach ( array( 'email', 'phone', 'instagram', 'height_cm', 'rating' ) as $f ) {
					if ( empty( $keep->$f ) && ! empty( $dup->$f ) ) {
						$fill[ $f ] = $dup->$f;
						$keep->$f   = $dup->$f;
					}
				}
				$notes = trim( implode( "\n", array_unique( array_filter( array( (string) $keep->notes, (string) $dup->notes ) ) ) ) );
				if ( $notes !== (string) $keep->notes ) {
					$fill['notes'] = $notes;
					$keep->notes   = $notes;
				}
				if ( $fill ) {
					$wpdb->update( $t, $fill, array( 'id' => $keep->id ) );
				}
				$wpdb->delete( $t, array( 'id' => $dup->id ) );
				$out['removed']++;
			}
			$out['merged']++;
		}
		$out['quiet'] = (int) $wpdb->query( "UPDATE $loans SET reminder_sent = 1 WHERE source = 'import' AND reminder_sent = 0" );
		CC_Fleet_Install::log( 'import', 0, 'tidy', wp_json_encode( $out ) );
		return $out;
	}

	/** Riders-only CSV: name, email, phone, instagram, notes. */
	public static function riders_csv( $file ) {
		$res  = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() );
		$rows = self::read_csv( $file );
		if ( is_wp_error( $rows ) ) {
			$res['errors'][] = $rows->get_error_message();
			return $res;
		}
		foreach ( $rows as $r ) {
			$r = array_change_key_case( array_map( 'trim', $r ), CASE_LOWER );
			if ( '' === ( $r['name'] ?? '' ) ) {
				$res['skipped']++;
				continue;
			}
			$r['rider_notes'] = $r['notes'] ?? '';
			$before = CC_Fleet_Riders::count();
			self::rider( $r );
			CC_Fleet_Riders::count() > $before ? $res['created']++ : $res['updated']++;
		}
		return $res;
	}

	/** Find or create the rider for a CSV row; add any notes once. */
	private static function rider( array $r ) {
		$email = sanitize_email( $r['email'] ?? '' );
		$id    = CC_Fleet_Riders::upsert( array(
			'name' => $r['name'] ?? '', 'email' => is_email( $email ) ? $email : '', 'phone' => $r['phone'] ?? '', 'instagram' => $r['instagram'] ?? '',
		) );
		$note = trim( (string) ( $r['rider_notes'] ?? '' ) );
		if ( $id && '' !== $note ) {
			$cur = (string) ( CC_Fleet_Riders::get( $id )->notes ?? '' );
			if ( false === strpos( $cur, $note ) ) {
				CC_Fleet_Riders::update( $id, array( 'notes' => trim( $cur . "\n" . $note ) ) );
			}
		}
		return $id;
	}

	public static function read_rows( $file ) {
		return self::read_csv( $file );
	}

	private static function read_csv( $file ) {
		if ( ! is_readable( $file ) ) {
			return new WP_Error( 'cc_csv', 'File not readable.' );
		}
		$fh = fopen( $file, 'r' );
		$first = fgets( $fh );
		$delim = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';
		rewind( $fh );
		$header = fgetcsv( $fh, 0, $delim );
		if ( ! $header ) {
			return new WP_Error( 'cc_csv', 'Empty file.' );
		}
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] ); // Strip BOM.
		$rows      = array();
		while ( ( $line = fgetcsv( $fh, 0, $delim ) ) !== false ) {
			if ( count( array_filter( $line, function ( $c ) { return null !== $c && '' !== $c; } ) ) === 0 ) {
				continue;
			}
			$rows[] = array_combine( $header, array_pad( array_slice( $line, 0, count( $header ) ), count( $header ), '' ) );
		}
		fclose( $fh );
		return $rows;
	}

	private static function norm( $s ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $s ) );
	}

	/** Accepts 2026-11-20, 20/11/2026, 11/20/2026 (US if day > 12 impossible), 2026-Nov-06. */
	public static function date( $s ) {
		$s = trim( (string) $s );
		if ( '' === $s ) {
			return '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) ) {
			return $s;
		}
		if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m ) ) {
			[ , $a, $b, $y ] = $m;
			// Sheets exports here are month/day (e.g. 6/12/2026 = 12 June); swap only when impossible.
			[ $mo, $d ] = (int) $a > 12 ? array( $b, $a ) : array( $a, $b );
			return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
		}
		$t = strtotime( $s );
		return $t ? gmdate( 'Y-m-d', $t ) : '';
	}
}
