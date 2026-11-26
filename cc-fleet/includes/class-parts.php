<?php
defined( 'ABSPATH' ) || exit;

/**
 * Parts that move between bikes: wheelsets and tyre sets.
 *
 * Each part has a code (WS-01, TY-01), lives on at most one bike (or on the shelf), and can be
 * flagged as damaged with a note. Tyres are mounted on a wheelset (wheelset_id) and travel with
 * it: move or swap a wheelset and its tyres go along. After every move, wheels_check() looks at
 * each bike involved: no wheels, or damaged wheels/tyres → a scheduled "[wheels]" workshop job and
 * "In the workshop"; good wheels again → that job is closed and the bike is active again.
 * The bike's own spec fields (Wheelset, Wheel size, Freehub, Tyres, Tyre size) are kept in step
 * with the parts, so the public bike page and the history keep working as before.
 */
class CC_Fleet_Parts {

	const TYPES = array(
		'wheelset' => array( 'label' => 'Wheelset', 'plural' => 'Wheelsets', 'prefix' => 'WS', 'size' => 'Wheel size', 'size_ph' => '700c', 'detail' => 'Freehub', 'detail_ph' => 'XDR' ),
		'tyres'    => array( 'label' => 'Tyres', 'plural' => 'Tyres', 'prefix' => 'TY', 'size' => 'Width (mm)', 'size_ph' => '45', 'detail' => 'Setup', 'detail_ph' => 'Tubeless' ),
	);

	/** Bike spec fields each part type fills: bike field => part column. */
	const BIKE_FIELDS = array(
		'wheelset' => array( 'wheelset' => 'name', 'wheel_size' => 'size', 'freehub' => 'detail' ),
		'tyres'    => array( 'tyres' => 'name', 'tyre_size' => 'size' ),
	);

	public static function table() {
		return CC_Fleet_Install::table( 'parts' );
	}

	public static function create_table( $c ) {
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL DEFAULT 'wheelset',
			code varchar(20) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			size varchar(40) NOT NULL DEFAULT '',
			detail varchar(120) NOT NULL DEFAULT '',
			bike_id bigint(20) unsigned NOT NULL DEFAULT 0,
			wheelset_id bigint(20) unsigned NOT NULL DEFAULT 0,
			damaged tinyint(1) NOT NULL DEFAULT 0,
			damage_note text NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY type_bike (type,bike_id),
			KEY code (code)
		) $c;" );
	}

	/* ---------------- Read ---------------- */

	public static function get( $id ) {
		global $wpdb;
		return $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ) : null;
	}

	public static function all( $type = '', $with_retired = false ) {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( $type ) {
			$where[] = 'type = %s';
			$args[]  = $type;
		}
		if ( ! $with_retired ) {
			$where[] = "status <> 'retired'";
		}
		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY type ASC, code ASC';
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
		usort( $rows, function ( $a, $b ) { return strcmp( $a->type, $b->type ) ?: strnatcmp( $a->code, $b->code ); } );
		return $rows;
	}

	public static function on_bike( $bike_id, $type ) {
		global $wpdb;
		return $bike_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE bike_id = %d AND type = %s AND status <> 'retired' ORDER BY id ASC LIMIT 1", (int) $bike_id, $type ) ) : null;
	}

	public static function damaged() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . " WHERE damaged = 1 AND status <> 'retired' ORDER BY type, code" ); // phpcs:ignore
	}

	/** The tyres mounted on a wheelset (or null). */
	public static function tyres_on( $wheelset_id ) {
		global $wpdb;
		return $wheelset_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE type = 'tyres' AND wheelset_id = %d AND status <> 'retired' ORDER BY id ASC LIMIT 1", (int) $wheelset_id ) ) : null;
	}

	/** "WS-03 HUNT 40 CGR + TY-02 Schwalbe G-One RS" — what moves when the wheels move. */
	public static function pair_label( $ws ) {
		if ( ! $ws ) {
			return 'no wheels';
		}
		$t = self::tyres_on( $ws->id );
		return $ws->code . ' ' . $ws->name . ( $t ? ' + ' . $t->code . ' ' . $t->name : '' );
	}

	public static function next_code( $type ) {
		global $wpdb;
		$prefix = self::TYPES[ $type ]['prefix'];
		$codes  = $wpdb->get_col( $wpdb->prepare( 'SELECT code FROM ' . self::table() . ' WHERE type = %s', $type ) );
		$max    = 0;
		foreach ( $codes as $c ) {
			if ( preg_match( '/(\d+)$/', $c, $m ) ) {
				$max = max( $max, (int) $m[1] );
			}
		}
		return sprintf( '%s-%02d', $prefix, $max + 1 );
	}

	/** "WS-03 · HUNT 40 CGR · 700c". */
	public static function label( $p, $with_place = false ) {
		$s = $p->code . ' · ' . $p->name . ( '' !== $p->size ? ' · ' . $p->size . ( 'tyres' === $p->type && is_numeric( $p->size ) ? ' mm' : '' ) : '' );
		if ( 'wheelset' === $p->type ) {
			$t  = self::tyres_on( $p->id );
			$s .= $t ? ' + ' . $t->code . ' ' . $t->name . ( (int) $t->damaged ? ' ⚠' : '' ) : ' (no tyres)';
		}
		if ( $with_place ) {
			$s .= $p->bike_id ? ' — on ' . CC_Fleet_Bikes::get( $p->bike_id, 'cc_id' ) : ' — spare';
		}
		if ( (int) $p->damaged ) {
			$s .= ' ⚠ damaged';
		}
		if ( 'retired' === $p->status ) {
			$s .= ' (retired)';
		}
		return $s;
	}

	/* ---------------- Write ---------------- */

	/** Create or update the part's own details (not where it is: that's assign()). Returns id or WP_Error. */
	public static function save( array $in, $id = 0 ) {
		global $wpdb;
		$old  = $id ? self::get( $id ) : null;
		$type = $old ? $old->type : ( isset( self::TYPES[ $in['type'] ?? '' ] ) ? $in['type'] : '' );
		if ( ! $type ) {
			return new WP_Error( 'cc_part', 'Choose wheelset or tyres.' );
		}
		$row = array(
			'name'        => sanitize_text_field( wp_unslash( $in['name'] ?? ( $old->name ?? '' ) ) ),
			'size'        => sanitize_text_field( wp_unslash( $in['size'] ?? ( $old->size ?? '' ) ) ),
			'detail'      => sanitize_text_field( wp_unslash( $in['detail'] ?? ( $old->detail ?? '' ) ) ),
			'damaged'     => array_key_exists( 'damaged', $in ) ? ( empty( $in['damaged'] ) ? 0 : 1 ) : (int) ( $old->damaged ?? 0 ),
			'damage_note' => sanitize_textarea_field( wp_unslash( $in['damage_note'] ?? ( $old->damage_note ?? '' ) ) ),
			'status'      => in_array( $in['status'] ?? '', array( 'active', 'retired' ), true ) ? $in['status'] : ( $old->status ?? 'active' ),
			'notes'       => sanitize_textarea_field( wp_unslash( $in['notes'] ?? ( $old->notes ?? '' ) ) ),
			'updated_at'  => current_time( 'mysql' ),
		);
		if ( 'tyres' === $type ) {
			$row['size'] = preg_replace( '/\s*mm$/i', '', $row['size'] );
		}
		if ( '' === $row['name'] ) {
			return new WP_Error( 'cc_part', 'Give the part a name, e.g. HUNT 40 CGR.' );
		}
		if ( $row['damaged'] && '' === trim( $row['damage_note'] ) ) {
			return new WP_Error( 'cc_part', 'Say what is damaged, so it can be fixed or replaced.' );
		}
		if ( ! $row['damaged'] && $old && (int) $old->damaged ) {
			$row['damage_note'] = ''; // Fixed: clear the note (the log keeps it).
		}
		if ( $old ) {
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
			if ( (int) $old->damaged !== $row['damaged'] ) {
				CC_Fleet_Install::log( 'part', $id, $row['damaged'] ? 'damaged' : 'repaired', $row['damaged'] ? $row['damage_note'] : (string) $old->damage_note );
			}
			if ( 'retired' === $row['status'] && 'retired' !== $old->status ) {
				if ( $old->bike_id ) {
					self::assign( $id, 0, array( 'note' => 'Part retired' ) );
				}
				if ( 'tyres' === $type ) {
					self::mount( $id, 0 );
				} else {
					$wpdb->update( self::table(), array( 'wheelset_id' => 0 ), array( 'wheelset_id' => (int) $id ) ); // Its tyres become loose spares.
				}
			}
		} else {
			$row['type']       = $type;
			$row['code']       = sanitize_text_field( $in['code'] ?? '' ) ?: self::next_code( $type );
			$row['created_at'] = $row['updated_at'];
			$wpdb->insert( self::table(), $row );
			$id = (int) $wpdb->insert_id;
			CC_Fleet_Install::log( 'part', $id, 'created', $row['code'] . ' ' . $row['name'] . ( $row['damaged'] ? ' — damaged: ' . $row['damage_note'] : '' ) );
		}
		// Keep the bike's spec text in step with the part's name and size.
		$p = self::get( $id );
		if ( $p && $p->bike_id ) {
			self::sync_bike( (int) $p->bike_id, $p->type );
		}
		return (int) $id;
	}

	/** Move one part (no checks, no bike history): set bike, log it. */
	private static function put( $p, $bike_id, $why = '' ) {
		global $wpdb;
		$from = (int) $p->bike_id;
		if ( $from === (int) $bike_id ) {
			return;
		}
		$wpdb->update( self::table(), array( 'bike_id' => (int) $bike_id, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $p->id ) );
		$where = function ( $b ) { return $b ? CC_Fleet_Bikes::get( $b, 'cc_id' ) : 'spare'; };
		CC_Fleet_Install::log( 'part', $p->id, 'moved', $where( $from ) . ' → ' . $where( $bike_id ) . ( '' !== $why ? ' · ' . $why : '' ) );
	}

	/** Mount tyres on a wheelset (0 = loose). */
	private static function mount( $tyres_id, $wheelset_id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'wheelset_id' => (int) $wheelset_id ), array( 'id' => (int) $tyres_id ) );
	}

	/** What a bike has now, to compare after a move. */
	public static function snapshot( $bike_id ) {
		$ws = self::on_bike( $bike_id, 'wheelset' );
		$ty = self::on_bike( $bike_id, 'tyres' );
		return array( 'ws' => $ws ? (int) $ws->id : 0, 'ty' => $ty ? (int) $ty->id : 0, 'damaged' => ( $ws && (int) $ws->damaged ) || ( $ty && (int) $ty->damaged ) );
	}

	/**
	 * Put a part on a bike (or on the shelf with $bike_id = 0).
	 * - A wheelset takes its tyres along. The bike's previous wheelset (and its tyres) goes to the shelf.
	 * - Tyres are mounted on the bike's wheelset; the bike's previous tyres go to the shelf.
	 * - Every bike involved is checked afterwards (wheels_check): missing or damaged → workshop.
	 *
	 * @param array $ctx date, note, by — for the history entries; quiet_target: the caller records
	 *                   the receiving bike's history itself (the bike editor does).
	 * @return array Bike ids that lost the part (for messages).
	 */
	public static function assign( $part_id, $bike_id, array $ctx = array() ) {
		$p = self::get( $part_id );
		if ( ! $p ) {
			return array();
		}
		$bike_id = (int) $bike_id;
		$from    = (int) $p->bike_id;
		if ( $from === $bike_id ) {
			if ( $bike_id ) {
				self::sync_bike( $bike_id, $p->type );
			}
			return array();
		}
		$bikes  = array_values( array_filter( array( $from, $bike_id ) ) );
		$before = array();
		foreach ( $bikes as $bk ) {
			$before[ $bk ] = self::snapshot( $bk );
		}
		$name = function ( $b ) { return $b ? CC_Fleet_Bikes::get( $b, 'cc_id' ) : 'the shelf'; };
		if ( 'wheelset' === $p->type ) {
			$tyres = self::tyres_on( $p->id );
			if ( $bike_id ) {
				$cur = self::on_bike( $bike_id, 'wheelset' );
				if ( $cur && (int) $cur->id !== (int) $p->id ) {
					self::put( $cur, 0, 'Taken off ' . $name( $bike_id ) );
				}
				// Tyres left on the bike that aren't on the incoming wheels go to the shelf with their wheels.
				$ty = self::on_bike( $bike_id, 'tyres' );
				if ( $ty && ( ! $tyres || (int) $ty->id !== (int) $tyres->id ) ) {
					self::put( $ty, 0, 'Taken off ' . $name( $bike_id ) . ( $cur ? ' with ' . $cur->code : '' ) );
				}
			}
			self::put( $p, $bike_id, (string) ( $ctx['note'] ?? '' ) );
			if ( $tyres ) {
				self::put( $tyres, $bike_id, 'With ' . $p->code );
			}
		} else {
			if ( $bike_id ) {
				$cur = self::on_bike( $bike_id, 'tyres' );
				if ( $cur && (int) $cur->id !== (int) $p->id ) {
					self::put( $cur, 0, 'Taken off ' . $name( $bike_id ) );
					self::mount( $cur->id, 0 );
				}
			}
			$ws = $bike_id ? self::on_bike( $bike_id, 'wheelset' ) : null;
			self::mount( $p->id, $ws ? $ws->id : 0 );
			self::put( $p, $bike_id, (string) ( $ctx['note'] ?? '' ) );
		}
		$date = CC_Fleet_Import::date( $ctx['date'] ?? '' ) ?: CC_Fleet_Settings::today();
		$what = 'wheelset' === $p->type ? self::pair_label( self::get( $p->id ) ) : $p->code . ' ' . $p->name;
		foreach ( $bikes as $bk ) {
			$changes = array_merge( self::sync_bike( $bk, 'wheelset' ), self::sync_bike( $bk, 'tyres' ) );
			if ( $bk === $from ) {
				$note = $what . ' moved to ' . $name( $bike_id );
			} elseif ( empty( $ctx['quiet_target'] ) ) {
				$note = $what . ' fitted' . ( $from ? ', from ' . $name( $from ) : ', from the shelf' );
			} else {
				$note = '';
			}
			if ( '' !== $note ) {
				CC_Fleet_Bikes::record_change( $bk, $changes, array( 'date' => $date, 'type' => 'moved', 'by' => $ctx['by'] ?? '', 'note' => $note . ( ! empty( $ctx['note'] ) ? ' · ' . sanitize_text_field( $ctx['note'] ) : '' ), 'workshop' => false ) );
			}
			self::wheels_check( $bk, $before[ $bk ], $date );
		}
		return $from ? array( $from ) : array();
	}

	/**
	 * Swap wheels (with their tyres) between two bikes in one go.
	 * Typical: a rider needs CC-09, its wheels are damaged → swap with CC-11; CC-09 is ready,
	 * CC-11 goes to the workshop with the damaged wheels.
	 *
	 * @return array|WP_Error [bike_id => 'workshop'|'active'|''] what happened to each bike's status.
	 */
	public static function swap( $bike_a, $bike_b, array $ctx = array() ) {
		$bike_a = (int) $bike_a;
		$bike_b = (int) $bike_b;
		if ( ! $bike_a || ! $bike_b || $bike_a === $bike_b ) {
			return new WP_Error( 'cc_part', 'Choose two different bikes.' );
		}
		$wa = self::on_bike( $bike_a, 'wheelset' );
		$wb = self::on_bike( $bike_b, 'wheelset' );
		if ( ! $wa && ! $wb ) {
			return new WP_Error( 'cc_part', 'Neither bike has a wheelset to swap.' );
		}
		$before = array( $bike_a => self::snapshot( $bike_a ), $bike_b => self::snapshot( $bike_b ) );
		// Tyres on a bike that aren't mounted on its wheels (older data) are mounted now, so they travel along.
		foreach ( array( $bike_a => $wa, $bike_b => $wb ) as $bk => $ws ) {
			$ty = self::on_bike( $bk, 'tyres' );
			if ( $ws && $ty && ! self::tyres_on( $ws->id ) ) {
				self::mount( $ty->id, $ws->id );
			}
		}
		$ta  = $wa ? self::tyres_on( $wa->id ) : null;
		$tb  = $wb ? self::tyres_on( $wb->id ) : null;
		$ca  = CC_Fleet_Bikes::get( $bike_a, 'cc_id' );
		$cb  = CC_Fleet_Bikes::get( $bike_b, 'cc_id' );
		$why = 'Swapped ' . $ca . ' ⇄ ' . $cb;
		foreach ( array( array( $wa, $ta, $bike_b ), array( $wb, $tb, $bike_a ) ) as $m ) {
			if ( $m[0] ) {
				self::put( $m[0], $m[2], $why );
			}
			if ( $m[1] ) {
				self::put( $m[1], $m[2], $why );
			}
		}
		$date = CC_Fleet_Import::date( $ctx['date'] ?? '' ) ?: CC_Fleet_Settings::today();
		$out  = array();
		self::say( sprintf( 'Wheels swapped: %s now has %s; %s now has %s.', $ca, self::pair_label( self::get( $wb->id ?? 0 ) ), $cb, self::pair_label( self::get( $wa->id ?? 0 ) ) ) );
		foreach ( array( $bike_a => $cb, $bike_b => $ca ) as $bk => $other ) {
			$changes = array_merge( self::sync_bike( $bk, 'wheelset' ), self::sync_bike( $bk, 'tyres' ) );
			$got     = self::pair_label( self::on_bike( $bk, 'wheelset' ) );
			CC_Fleet_Bikes::record_change( $bk, $changes, array( 'date' => $date, 'type' => 'moved', 'by' => $ctx['by'] ?? '', 'note' => 'Wheels & tyres swapped with ' . $other . ': now ' . $got . ( ! empty( $ctx['note'] ) ? ' · ' . sanitize_text_field( $ctx['note'] ) : '' ), 'workshop' => false ) );
			$out[ $bk ] = self::wheels_check( $bk, $before[ $bk ], $date );
		}
		return $out;
	}

	/** Open (scheduled) workshop jobs this class made for a bike's wheels. */
	private static function wheel_jobs( $bike_id ) {
		if ( ! class_exists( 'CC_Fleet_Records' ) ) {
			return array();
		}
		return array_values( array_filter( CC_Fleet_Records::query( 'maintenance', array( 'where' => array( 'bike_id' => $bike_id, 'status' => 'scheduled' ), 'limit' => 50 ) ), function ( $m ) {
			$d = (string) $m->description;
			return false !== strpos( $d, '[wheels]' ) || 0 === strpos( $d, 'Needs wheelset' ) || 0 === strpos( $d, 'Needs tyres' );
		} ) );
	}

	/**
	 * After a move: is this bike rideable as far as wheels go?
	 * Problem (wheels or tyres gone, or damaged) → one open "[wheels]" job + "In the workshop".
	 * Fine → close that job; if the wheels were the reason it was in the workshop, make it active.
	 *
	 * @param array $before snapshot() from before the move.
	 * @return string 'workshop', 'active' or '' (status unchanged).
	 */
	public static function wheels_check( $bike_id, array $before, $date = '' ) {
		$ws    = self::on_bike( $bike_id, 'wheelset' );
		$ty    = self::on_bike( $bike_id, 'tyres' );
		$probs = array();
		if ( ! $ws && $before['ws'] ) {
			$probs[] = 'needs a wheelset';
		} elseif ( $ws && (int) $ws->damaged ) {
			$probs[] = $ws->code . ' ' . $ws->name . ' damaged: ' . $ws->damage_note;
		}
		if ( ! $ty && $before['ty'] ) {
			$probs[] = 'needs tyres';
		} elseif ( $ty && (int) $ty->damaged ) {
			$probs[] = $ty->code . ' ' . $ty->name . ' damaged: ' . $ty->damage_note;
		}
		$jobs  = self::wheel_jobs( $bike_id );
		$state = CC_Fleet_Bikes::get( $bike_id, 'state' );
		if ( $probs ) {
			if ( ! class_exists( 'CC_Fleet_Records' ) ) {
				return '';
			}
			$desc = 'Wheels: ' . implode( '; ', $probs ) . '. [wheels]';
			if ( $jobs ) {
				if ( $jobs[0]->description !== $desc ) {
					CC_Fleet_Records::save( 'maintenance', array( 'description' => $desc ), $jobs[0]->id );
				}
			} else {
				CC_Fleet_Records::save( 'maintenance', array( 'bike_id' => $bike_id, 'type' => 'repair', 'status' => 'scheduled', 'work_date' => $date ?: CC_Fleet_Settings::today(), 'description' => $desc ) );
			}
			if ( 'active' === $state ) {
				CC_Fleet_Bikes::set( $bike_id, 'state', 'workshop' );
				self::say( CC_Fleet_Bikes::get( $bike_id, 'cc_id' ) . ' is now “In the workshop”: ' . implode( '; ', $probs ) . '.' );
				return 'workshop';
			}
			return '';
		}
		foreach ( $jobs as $j ) {
			CC_Fleet_Records::save( 'maintenance', array( 'status' => 'completed', 'description' => $j->description . "\nSorted: good wheels fitted." ), $j->id );
		}
		if ( 'workshop' === $state && ( $jobs || $before['damaged'] ) ) {
			$open = class_exists( 'CC_Fleet_Records' ) ? CC_Fleet_Records::query( 'maintenance', array( 'where' => array( 'bike_id' => $bike_id, 'status' => 'scheduled' ), 'to' => CC_Fleet_Settings::today(), 'limit' => 1 ) ) : array();
			if ( ! $open ) {
				CC_Fleet_Bikes::set( $bike_id, 'state', 'active' );
				self::say( CC_Fleet_Bikes::get( $bike_id, 'cc_id' ) . ' has good wheels again and is active.' );
				return 'active';
			}
		}
		return '';
	}

	/** Upgrade: tyres already on a bike are mounted on that bike's wheelset. */
	public static function mount_existing() {
		foreach ( self::all( 'tyres' ) as $t ) {
			if ( $t->bike_id && ! (int) $t->wheelset_id ) {
				$ws = self::on_bike( $t->bike_id, 'wheelset' );
				if ( $ws && ! self::tyres_on( $ws->id ) ) {
					self::mount( $t->id, $ws->id );
				}
			}
		}
	}

	/**
	 * Copy the bike's current part (or nothing) into its spec fields.
	 * @return array Changes [[field, old, new], …].
	 */
	public static function sync_bike( $bike_id, $type ) {
		$p       = self::on_bike( $bike_id, $type );
		$changes = array();
		foreach ( self::BIKE_FIELDS[ $type ] as $field => $col ) {
			$new = $p ? (string) $p->$col : '';
			$old = (string) CC_Fleet_Bikes::get( $bike_id, $field );
			if ( trim( $old ) !== trim( $new ) ) {
				$changes[] = array( $field, $old, $new );
				CC_Fleet_Bikes::set( $bike_id, $field, $new );
			}
		}
		return $changes;
	}

	/** What the bike editor needs to fill in the spec fields for a chosen part (or none). */
	public static function bike_values( $part_id, $type ) {
		$p   = $part_id ? self::get( $part_id ) : null;
		$out = array();
		foreach ( self::BIKE_FIELDS[ $type ] as $field => $col ) {
			$out[ $field ] = $p && $p->type === $type ? (string) $p->$col : '';
		}
		return $out;
	}

	/**
	 * First run: turn every bike's wheelset and tyres text into parts on that bike.
	 * Safe to run again: bikes that already have a part of that type are skipped.
	 */
	public static function migrate_from_bikes() {
		$made = 0;
		foreach ( CC_Fleet_Bikes::all_ids() as $bike ) {
			foreach ( self::BIKE_FIELDS as $type => $map ) {
				$name = trim( (string) CC_Fleet_Bikes::get( $bike, array_key_first( $map ) ) );
				if ( '' === $name || self::on_bike( $bike, $type ) ) {
					continue;
				}
				$in = array( 'type' => $type );
				foreach ( $map as $field => $col ) {
					$in[ $col ] = (string) CC_Fleet_Bikes::get( $bike, $field );
				}
				$id = self::save( $in );
				if ( ! is_wp_error( $id ) ) {
					global $wpdb;
					$wpdb->update( self::table(), array( 'bike_id' => $bike ), array( 'id' => $id ) );
					$made++;
				}
			}
		}
		self::mount_existing();
		CC_Fleet_Install::log( 'parts', 0, 'migrated', $made . ' parts created from the bikes' );
		return $made;
	}

	public static function maybe_migrate() {
		if ( get_option( 'cc_fleet_migrate_parts' ) ) {
			delete_option( 'cc_fleet_migrate_parts' );
			self::migrate_from_bikes();
		}
	}

	/* ---------------- Bike editor ---------------- */

	/** What happened to other bikes during this request, shown once as a notice. */
	public static $messages = array();

	private static function say( $msg ) {
		self::$messages[] = $msg;
	}

	/** <select> for the bike editor. Wheelsets show their tyres; tyres follow the wheelset. */
	public static function select( $bike_id, $type ) {
		$cur  = self::on_bike( $bike_id, $type );
		$html = sprintf( '<input type="hidden" name="cc_part_was[%s]" value="%d">', esc_attr( $type ), $cur ? (int) $cur->id : 0 );
		$html .= sprintf( '<select name="cc_part[%s]" class="widefat cc-part-select" data-cc-type="%s" data-cc-from="%d">', esc_attr( $type ), esc_attr( $type ), $cur ? (int) $cur->id : 0 );
		$html .= '<option value="0">— None —</option>';
		foreach ( self::all( $type ) as $p ) {
			$t = 'wheelset' === $type ? self::tyres_on( $p->id ) : null;
			$html .= sprintf( '<option value="%d" data-bike="%s" data-tyres="%d"%s>%s</option>', (int) $p->id, esc_attr( $p->bike_id && (int) $p->bike_id !== (int) $bike_id ? CC_Fleet_Bikes::get( $p->bike_id, 'cc_id' ) : '' ), $t ? (int) $t->id : 0, selected( $cur && (int) $cur->id === (int) $p->id, true, false ), esc_html( self::label( $p, (int) $p->bike_id !== (int) $bike_id ) ) );
		}
		$html .= '</select>';
		if ( $cur && (int) $cur->damaged ) {
			$html .= '<span class="cc-part-damage">⚠ Damaged: ' . esc_html( $cur->damage_note ) . '</span>';
		}
		if ( 'wheelset' === $type ) {
			$mine = $cur ? self::pair_label( $cur ) : '';
			$html .= '<span class="cc-part-swap" hidden>';
			$html .= sprintf( '<label><input type="radio" name="cc_part_swap" value="1" checked> <strong>Swap</strong>: <span class="cc-swap-bike"></span> gets this bike’s %s</label><br>', esc_html( $mine ) );
			$html .= '<label><input type="radio" name="cc_part_swap" value="0"> <strong>Just take them</strong>: <span class="cc-swap-bike"></span> is left without wheels</label>';
			$html .= '</span>';
			$html .= '<small class="cc-muted cc-part-help">Tyres move with the wheels.</small>';
		}
		$html .= '<small class="cc-part-warn" hidden></small>';
		$html .= sprintf( ' <a href="%s" class="cc-muted">Manage %s</a>', esc_url( admin_url( 'admin.php?page=cc-fleet-parts&type=' . $type ) ), esc_html( strtolower( self::TYPES[ $type ]['plural'] ) ) );
		return $html;
	}

	public static function editor_js() {
		return <<<'JS'
jQuery(function($){
  var tyres=function(){ return $('.cc-part-select[data-cc-type="tyres"]'); };
  var warn=function(sel){
    var o=sel.find(':selected'), from=o.data('bike'), w=sel.siblings('.cc-part-warn'), sw=sel.siblings('.cc-part-swap');
    var isWs=sel.data('cc-type')==='wheelset', hasOwn=+sel.data('cc-from')>0;
    sw.find('.cc-swap-bike').text(from||'');
    if(isWs && from && hasOwn){
      sw.prop('hidden',false);
      var take=sw.find('input[value="0"]').is(':checked');
      w.text(take ? from+' goes to “In the workshop” until it gets wheels.' : 'Damaged wheels send their new bike to the workshop; good wheels make a bike ready again.').prop('hidden',false);
    } else {
      sw.prop('hidden',true);
      if(from){ w.text('Moves from '+from+' — '+from+' goes to “In the workshop” until it gets a replacement.').prop('hidden',false); } else { w.prop('hidden',true); }
    }
  };
  $(document).on('change','.cc-part-select',function(){
    var sel=$(this); warn(sel);
    if(sel.data('cc-type')==='wheelset'){
      // The tyres on the chosen wheels come along.
      var t=tyres(); if(t.length){ t.val(String(sel.find(':selected').data('tyres')||0)); warn(t); t.siblings('.cc-part-warn').prop('hidden',true); }
    }
  });
  $(document).on('change','input[name="cc_part_swap"]',function(){ warn($('.cc-part-select[data-cc-type="wheelset"]')); });
});
JS;
	}

	/** Bike editor: notice about the other bikes a move touched. */
	public static function editor_notice() {
		$key = 'cc_fleet_wheels_notice_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( $n ) {
			delete_transient( $key );
			printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( implode( ' ', (array) $n ) ) );
		}
	}

	/** Keep the messages for the next screen (after the editor's redirect). */
	public static function keep_messages() {
		if ( self::$messages ) {
			set_transient( 'cc_fleet_wheels_notice_' . get_current_user_id(), self::$messages, 120 );
			self::$messages = array();
		}
	}

	/* ---------------- Admin page: Fleet Manager → Parts ---------------- */

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 99 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
			add_action( 'admin_post_cc_fleet_part_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'admin_post_cc_fleet_wheel_swap', array( __CLASS__, 'handle_swap' ) );
			add_action( 'admin_notices', array( __CLASS__, 'editor_notice' ) );
		}
	}

	public static function menu() {
		$n = count( self::damaged() );
		add_submenu_page( 'cc-fleet', 'Wheels & tyres', 'Wheels & tyres' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' ), 'manage_cc_fleet', 'cc-fleet-parts', array( __CLASS__, 'page' ) );
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'cc-fleet-parts' ), $args ), admin_url( 'admin.php' ) );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_cc_fleet' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['edit'] ) || isset( $_GET['new'] ) ) {
			self::page_edit( absint( $_GET['edit'] ?? 0 ), sanitize_key( $_GET['new'] ?? '' ) );
			return;
		}
		$type = isset( self::TYPES[ $_GET['type'] ?? '' ] ) ? sanitize_key( $_GET['type'] ) : 'wheelset';
		$show = sanitize_key( $_GET['show'] ?? 'active' );
		// phpcs:enable
		echo '<div class="wrap cc-wrap"><h1 class="wp-heading-inline">Wheels &amp; tyres</h1> ';
		printf( '<a class="page-title-action" href="%s">+ Add %s</a><hr class="wp-header-end">', esc_url( self::url( array( 'new' => $type ) ) ), esc_html( strtolower( self::TYPES[ $type ]['label'] ) ) );
		self::notice();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::TYPES as $k => $t ) {
			$count = count( self::all( $k ) );
			printf( '<a class="nav-tab%s" href="%s">%s <span class="count">(%d)</span></a>', $k === $type ? ' nav-tab-active' : '', esc_url( self::url( array( 'type' => $k ) ) ), esc_html( $t['plural'] ), $count );
		}
		echo '</nav>';
		self::swap_form();
		printf( '<p class="subsubsub"><a href="%s"%s>In use &amp; spare</a> | <a href="%s"%s>Damaged</a> | <a href="%s"%s>Retired</a></p><br class="clear">',
			esc_url( self::url( array( 'type' => $type ) ) ), 'active' === $show ? ' class="current"' : '',
			esc_url( self::url( array( 'type' => $type, 'show' => 'damaged' ) ) ), 'damaged' === $show ? ' class="current"' : '',
			esc_url( self::url( array( 'type' => $type, 'show' => 'retired' ) ) ), 'retired' === $show ? ' class="current"' : '' );
		$rows = array_filter( self::all( $type, true ), function ( $p ) use ( $show ) {
			if ( 'retired' === $show ) {
				return 'retired' === $p->status;
			}
			if ( 'retired' === $p->status ) {
				return false;
			}
			return 'damaged' === $show ? (int) $p->damaged : true;
		} );
		$t = self::TYPES[ $type ];
		$with = 'wheelset' === $type ? 'Tyres on it' : 'On wheelset';
		echo '<table class="widefat striped cc-parts"><thead><tr><th style="width:80px">Code</th><th>' . esc_html( $t['label'] ) . '</th><th>' . esc_html( $t['size'] ) . '</th><th>' . esc_html( $t['detail'] ) . '</th><th>' . esc_html( $with ) . '</th><th>On bike</th><th>Condition</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7" class="cc-empty">Nothing here.</td></tr>';
		}
		foreach ( $rows as $p ) {
			$mate = 'wheelset' === $type ? self::tyres_on( $p->id ) : ( (int) $p->wheelset_id ? self::get( $p->wheelset_id ) : null );
			$mate = $mate ? sprintf( '<a href="%s">%s</a>%s', esc_url( self::url( array( 'edit' => $mate->id ) ) ), esc_html( $mate->code . ' ' . $mate->name ), (int) $mate->damaged ? ' <span class="cc-badge cc-badge--declined">Damaged</span>' : '' ) : '<span class="cc-muted">—</span>';
			$bike = $p->bike_id ? sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $p->bike_id ) ), esc_html( CC_Fleet_Bikes::label( $p->bike_id ) ) ) : '<span class="cc-badge">Spare</span>';
			$cond = (int) $p->damaged ? '<span class="cc-badge cc-badge--declined">Damaged</span> ' . esc_html( $p->damage_note ) : '<span class="cc-muted">OK</span>';
			printf( '<tr><td><strong><a href="%s">%s</a></strong></td><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( self::url( array( 'edit' => $p->id ) ) ), esc_html( $p->code ), esc_url( self::url( array( 'edit' => $p->id ) ) ), esc_html( $p->name ), esc_html( $p->size ), esc_html( $p->detail ), $mate, $bike, $cond ); // phpcs:ignore
		}
		echo '</tbody></table>';
		echo '<p class="cc-muted">A part can be on one bike at a time, and tyres stay on their wheelset: move or swap the wheels and the tyres go along. A bike left without wheels, or given damaged ones, goes to “In the workshop” with a job; a bike that gets good wheels back is active again.</p></div>';
	}

	/** "Swap wheels" card: two bikes, one button. */
	private static function swap_form() {
		$bikes = array_filter( CC_Fleet_Bikes::all_ids(), function ( $b ) { return 'retired' !== CC_Fleet_Bikes::get( $b, 'state' ); } );
		$opts  = '<option value="0">— Bike —</option>';
		foreach ( $bikes as $b ) {
			$ws    = self::on_bike( $b, 'wheelset' );
			$opts .= sprintf( '<option value="%d">%s — %s</option>', (int) $b, esc_html( CC_Fleet_Bikes::label( $b ) ), esc_html( $ws ? self::pair_label( $ws ) . ( (int) $ws->damaged ? ' ⚠ damaged' : '' ) : 'no wheels' ) );
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cc-card cc-swap" style="margin:16px 0;padding:12px 16px;max-width:980px">';
		wp_nonce_field( 'cc_fleet_wheel_swap' );
		echo '<input type="hidden" name="action" value="cc_fleet_wheel_swap">';
		echo '<h2 style="margin:0 0 8px">Swap wheels between two bikes</h2>';
		printf( '<p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0"><select name="a" required>%1$s</select> <span aria-hidden="true">⇄</span> <select name="b" required>%1$s</select> <input type="text" name="note" placeholder="Why? e.g. CC-09 needed for Paige" style="min-width:220px"> <button class="button button-primary">Swap wheels &amp; tyres</button></p>', $opts ); // phpcs:ignore -- escaped above.
		echo '<p class="description" style="margin:6px 0 0">Wheels and their tyres change places. Whichever bike ends up with damaged wheels goes to the workshop; a bike that gets good wheels back is ready again.</p>';
		echo '</form>';
	}

	public static function handle_swap() {
		if ( ! current_user_can( 'manage_cc_fleet' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'cc_fleet_wheel_swap' );
		$res = self::swap( absint( $_POST['a'] ?? 0 ), absint( $_POST['b'] ?? 0 ), array( 'note' => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ) ) );
		$msg = is_wp_error( $res ) ? array( $res->get_error_message(), 'error' ) : array( implode( ' ', self::$messages ), 'success' );
		self::$messages = array();
		set_transient( 'cc_fleet_parts_notice_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( wp_get_referer() ?: self::url() );
		exit;
	}

	private static function notice() {
		$n = get_transient( 'cc_fleet_parts_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'cc_fleet_parts_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[1] ), esc_html( $n[0] ) );
		}
	}

	private static function page_edit( $id, $new_type ) {
		$p    = $id ? self::get( $id ) : null;
		$type = $p ? $p->type : ( isset( self::TYPES[ $new_type ] ) ? $new_type : 'wheelset' );
		$t    = self::TYPES[ $type ];
		echo '<div class="wrap cc-wrap"><h1>' . esc_html( $p ? $p->code . ' · ' . $p->name : 'Add ' . strtolower( $t['label'] ) ) . '</h1>';
		self::notice();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cc-form">';
		wp_nonce_field( 'cc_fleet_part_save' );
		printf( '<input type="hidden" name="action" value="cc_fleet_part_save"><input type="hidden" name="id" value="%d"><input type="hidden" name="type" value="%s">', (int) $id, esc_attr( $type ) );
		echo '<table class="form-table" role="presentation">';
		printf( '<tr><th><label for="cc-p-name">%s</label></th><td><input id="cc-p-name" class="regular-text" name="name" value="%s" required placeholder="%s"></td></tr>', esc_html( $t['label'] ), esc_attr( $p->name ?? '' ), 'wheelset' === $type ? 'e.g. HUNT 40 CGR' : 'e.g. Pirelli Cinturato Gravel M' );
		printf( '<tr><th><label for="cc-p-size">%s</label></th><td><input id="cc-p-size" name="size" value="%s" placeholder="%s"></td></tr>', esc_html( $t['size'] ), esc_attr( $p->size ?? '' ), esc_attr( $t['size_ph'] ) );
		printf( '<tr><th><label for="cc-p-detail">%s</label></th><td><input id="cc-p-detail" name="detail" value="%s" placeholder="%s"></td></tr>', esc_html( $t['detail'] ), esc_attr( $p->detail ?? '' ), esc_attr( $t['detail_ph'] ) );
		echo '<tr><th><label for="cc-p-bike">On bike</label></th><td><select id="cc-p-bike" name="bike_id"><option value="0">— Spare / on the shelf —</option>';
		foreach ( CC_Fleet_Bikes::all_ids() as $b ) {
			$has = self::on_bike( $b, $type );
			$tag = $has && ( ! $p || (int) $has->id !== (int) $p->id ) ? ' (has ' . $has->code . ', which goes to spare)' : '';
			printf( '<option value="%d"%s>%s%s</option>', (int) $b, selected( (int) ( $p->bike_id ?? 0 ), (int) $b, false ), esc_html( CC_Fleet_Bikes::label( $b ) ), esc_html( $tag ) );
		}
		echo '</select><p class="description">' . ( 'wheelset' === $type ? 'Its tyres come along. ' : 'Fitted on that bike’s wheelset. ' ) . 'A bike left without, or given damaged parts, goes “In the workshop” with a job. To swap two bikes’ wheels in one go, use “Swap wheels” on the list.</p></td></tr>';
		printf( '<tr><th>Condition</th><td><label><input type="checkbox" name="damaged" value="1" id="cc-p-dmg"%s> Damaged</label><p><textarea name="damage_note" rows="3" class="large-text" placeholder="What is damaged? e.g. rear rim cracked at the valve, front hub bearings rough">%s</textarea></p><p class="description">Untick once it’s fixed; the note stays in the history below.</p></td></tr>', checked( (int) ( $p->damaged ?? 0 ), 1, false ), esc_textarea( $p->damage_note ?? '' ) );
		printf( '<tr><th><label for="cc-p-status">Status</label></th><td><select id="cc-p-status" name="status"><option value="active"%s>In use / spare</option><option value="retired"%s>Retired (worn out, sold, binned)</option></select></td></tr>', selected( $p->status ?? 'active', 'active', false ), selected( $p->status ?? '', 'retired', false ) );
		printf( '<tr><th><label for="cc-p-notes">Notes</label></th><td><textarea id="cc-p-notes" name="notes" rows="3" class="large-text">%s</textarea></td></tr>', esc_textarea( $p->notes ?? '' ) );
		echo '</table>';
		submit_button( $p ? 'Save' : 'Add' );
		echo '</form>';
		if ( $p ) {
			global $wpdb;
			$log = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE object_type = 'part' AND object_id = %d ORDER BY id DESC LIMIT 50", $p->id ) );
			if ( $log ) {
				echo '<h2>History</h2><table class="widefat striped" style="max-width:820px"><tbody>';
				foreach ( $log as $e ) {
					$u = $e->user_id ? get_userdata( $e->user_id ) : null;
					printf( '<tr><td style="width:140px">%s</td><td><strong>%s</strong> %s</td><td style="width:140px">%s</td></tr>', esc_html( wp_date( 'j M Y', strtotime( $e->created_at ) ) ), esc_html( ucfirst( $e->action ) ), esc_html( (string) $e->message ), esc_html( $u ? $u->display_name : '' ) );
				}
				echo '</tbody></table>';
			}
		}
		printf( '<p><a href="%s">← All %s</a></p></div>', esc_url( self::url( array( 'type' => $type ) ) ), esc_html( strtolower( $t['plural'] ) ) );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_cc_fleet' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'cc_fleet_part_save' );
		$id  = absint( $_POST['id'] ?? 0 );
		$res = self::save( $_POST, $id ); // phpcs:ignore -- sanitized in save().
		$key = 'cc_fleet_parts_notice_' . get_current_user_id();
		if ( is_wp_error( $res ) ) {
			set_transient( $key, array( $res->get_error_message(), 'error' ), 60 );
			wp_safe_redirect( add_query_arg( $id ? array( 'edit' => $id ) : array( 'new' => sanitize_key( $_POST['type'] ?? 'wheelset' ) ), self::url() ) );
			exit;
		}
		$msg  = 'Saved.';
		$p    = self::get( $res );
		$bike = absint( $_POST['bike_id'] ?? 0 );
		if ( 'retired' !== $p->status && (int) $p->bike_id !== $bike ) {
			self::assign( $res, $bike );
			$msg  = $bike ? 'Fitted to ' . CC_Fleet_Bikes::get( $bike, 'cc_id' ) . ( 'wheelset' === $p->type && self::tyres_on( $p->id ) ? ', with its tyres' : ( 'tyres' === $p->type && self::on_bike( $bike, 'wheelset' ) ? ', on ' . self::on_bike( $bike, 'wheelset' )->code : '' ) ) . '.' : 'Moved to the shelf.';
			$msg .= self::$messages ? ' ' . implode( ' ', self::$messages ) : '';
			self::$messages = array();
		}
		set_transient( $key, array( $msg, 'success' ), 60 );
		wp_safe_redirect( self::url( array( 'edit' => $res ) ) );
		exit;
	}

	/** Dashboard card: damaged parts and spares. */
	public static function dashboard_card() {
		$dmg    = self::damaged();
		$spares = array_filter( self::all(), function ( $p ) { return ! $p->bike_id; } );
		echo '<section class="cc-card"><h2>Wheels &amp; tyres</h2>';
		if ( $dmg ) {
			echo '<ul class="cc-list">';
			foreach ( $dmg as $p ) {
				printf( '<li><a href="%s">%s</a> <span class="cc-badge cc-badge--declined">Damaged</span> <span class="cc-muted">%s · %s</span></li>', esc_url( self::url( array( 'edit' => $p->id ) ) ), esc_html( $p->code . ' ' . $p->name ), esc_html( $p->bike_id ? CC_Fleet_Bikes::get( $p->bike_id, 'cc_id' ) : 'spare' ), esc_html( wp_trim_words( (string) $p->damage_note, 10 ) ) );
			}
			echo '</ul>';
		} else {
			echo '<p class="cc-empty">Nothing damaged.</p>';
		}
		if ( $spares ) {
			printf( '<p class="cc-muted">Spare: %s</p>', esc_html( implode( ', ', array_map( function ( $p ) { return $p->code . ' ' . $p->name; }, $spares ) ) ) );
		}
		echo '</section>';
	}
}

/**
 * Drivetrain care: drivetrains stay with their bike, what changes is their condition.
 * Each bike keeps when it was last cleaned, lubed/waxed and checked, and the last chain-wear
 * reading. A check is due after every returned loan and at least every 30 days; lube every 21.
 * Chains, cassettes and chainrings are Stock items: replacing one takes it out of stock.
 */
class CC_Fleet_Care {

	const LUBE_DAYS  = 21;
	const CHECK_DAYS = 30;
	const WEAR       = array( '0' => '0 % (new)', '0.25' => '0.25 %', '0.5' => '0.5 % — replace chain (12-speed)', '0.75' => '0.75 % — replace chain, check cassette', '1' => '1 % — replace chain + cassette' );

	public static function init() {
		add_action( 'cc_fleet_loan_status', array( __CLASS__, 'after_loan' ), 10, 3 );
	}

	/** A bike that comes back from a loan needs a drivetrain check. */
	public static function after_loan( $loan_id, $status, $old = '' ) {
		if ( 'returned' !== $status ) {
			return;
		}
		$l = CC_Fleet_Loans::get( $loan_id );
		if ( $l && $l->bike_id ) {
			CC_Fleet_Bikes::set( $l->bike_id, 'dt_returned', CC_Fleet_Settings::today() );
		}
	}

	private static function days_since( $date ) {
		return $date ? (int) floor( ( strtotime( CC_Fleet_Settings::today() ) - strtotime( $date ) ) / DAY_IN_SECONDS ) : null;
	}

	/** @return string[] What is due on this bike's drivetrain (empty = nothing). */
	public static function due( $bike_id ) {
		$out     = array();
		$checked = (string) CC_Fleet_Bikes::get( $bike_id, 'dt_checked' );
		$lubed   = (string) CC_Fleet_Bikes::get( $bike_id, 'dt_lubed' );
		$back    = (string) CC_Fleet_Bikes::get( $bike_id, 'dt_returned' );
		$wear    = (string) CC_Fleet_Bikes::get( $bike_id, 'dt_wear' );
		if ( '' !== $wear && (float) $wear >= 0.5 ) {
			$out[] = 'Replace chain (wear ' . $wear . ' %)';
		}
		if ( $back && ( ! $checked || $back > $checked ) ) {
			$out[] = 'Check after loan (back ' . wp_date( 'j M', strtotime( $back . ' 12:00' ) ) . ')';
		} elseif ( ! $checked || self::days_since( $checked ) >= self::CHECK_DAYS ) {
			$out[] = $checked ? 'Check (last ' . self::days_since( $checked ) . ' days ago)' : 'First check';
		}
		if ( ! $lubed || self::days_since( $lubed ) >= self::LUBE_DAYS ) {
			$out[] = $lubed ? 'Lube/wax (last ' . self::days_since( $lubed ) . ' days ago)' : 'Lube/wax';
		}
		return $out;
	}

	/** Bike editor box. */
	public static function box( $post ) {
		$id   = $post->ID;
		$fmt  = function ( $d ) {
			return $d ? esc_html( wp_date( 'j M Y', strtotime( $d . ' 12:00' ) ) ) . ' <span class="cc-muted">(' . self::days_since( $d ) . ' d)</span>' : '<span class="cc-muted">never</span>';
		};
		$wear = (string) CC_Fleet_Bikes::get( $id, 'dt_wear' );
		$due  = self::due( $id );
		echo '<div class="cc-care">';
		printf( '<p class="cc-care__now">Cleaned: %s · Lubed/waxed: %s · Checked: %s · Chain wear: <strong>%s</strong></p>', $fmt( CC_Fleet_Bikes::get( $id, 'dt_cleaned' ) ), $fmt( CC_Fleet_Bikes::get( $id, 'dt_lubed' ) ), $fmt( CC_Fleet_Bikes::get( $id, 'dt_checked' ) ), '' === $wear ? '—' : esc_html( $wear . ' %' ) ); // phpcs:ignore
		if ( $due ) {
			echo '<p class="cc-care__due">Due: ' . esc_html( implode( ' · ', $due ) ) . '</p>';
		}
		echo '<div class="cc-record-change__row">';
		printf( '<label>Date<br><input type="date" name="cc_care[date]" value="%s"></label>', esc_attr( CC_Fleet_Settings::today() ) );
		echo '<fieldset><legend>Done</legend><label><input type="checkbox" name="cc_care[clean]" value="1"> Cleaned</label> <label><input type="checkbox" name="cc_care[lube]" value="1"> Lubed / waxed</label> <label><input type="checkbox" name="cc_care[check]" value="1"> Checked</label></fieldset>';
		echo '<label>Chain wear (checker)<br><select name="cc_care[wear]"><option value="">— not measured —</option>';
		foreach ( self::WEAR as $k => $l ) {
			printf( '<option value="%s">%s</option>', esc_attr( $k ), esc_html( $l ) );
		}
		echo '</select></label></div>';
		$stock = class_exists( 'CC_Fleet_Records' ) ? CC_Fleet_Records::query( 'stock', array( 'orderby' => 'name', 'order' => 'ASC', 'limit' => 300 ) ) : array();
		echo '<p><label><input type="checkbox" name="cc_care[replace]" value="1"> Replaced a part</label> <select name="cc_care[stock]"><option value="0">— taken from stock: choose —</option>';
		foreach ( $stock as $s ) {
			printf( '<option value="%d">%s (%d left)</option>', (int) $s->id, esc_html( $s->name . ( $s->category ? ' · ' . $s->category : '' ) ), (int) $s->quantity );
		}
		echo '</select> <span class="cc-muted">Takes one out of Stock. A new chain sets the wear to 0 %.</span></p>';
		echo '<p><label>Note<br><input type="text" class="widefat" name="cc_care[note]" placeholder="e.g. Rewaxed both chains; slight cassette wear on the 10t"></label></p>';
		echo '<p class="cc-muted">Saved with the bike (Save / Update). Due after every returned loan, every ' . (int) self::CHECK_DAYS . ' days for a check and every ' . (int) self::LUBE_DAYS . ' days for lube/wax.</p></div>';
	}

	/** Save from the bike editor. */
	public static function save( $bike_id, array $c ) {
		$did = array_values( array_intersect( array( 'clean', 'lube', 'check', 'replace' ), array_keys( array_filter( $c ) ) ) );
		$wear = isset( $c['wear'] ) && '' !== $c['wear'] && isset( self::WEAR[ (string) $c['wear'] ] ) ? (string) $c['wear'] : '';
		$note = sanitize_text_field( $c['note'] ?? '' );
		if ( ! $did && '' === $wear && '' === $note ) {
			return;
		}
		$date  = CC_Fleet_Import::date( $c['date'] ?? '' ) ?: CC_Fleet_Settings::today();
		$stock = absint( $c['stock'] ?? 0 );
		$item  = $stock && class_exists( 'CC_Fleet_Records' ) ? CC_Fleet_Records::get( 'stock', $stock ) : null;
		$map   = array( 'clean' => 'dt_cleaned', 'lube' => 'dt_lubed', 'check' => 'dt_checked' );
		foreach ( $map as $k => $field ) {
			if ( in_array( $k, $did, true ) && ( '' === (string) CC_Fleet_Bikes::get( $bike_id, $field ) || $date >= CC_Fleet_Bikes::get( $bike_id, $field ) ) ) {
				CC_Fleet_Bikes::set( $bike_id, $field, $date );
			}
		}
		if ( in_array( 'replace', $did, true ) && $item && false !== stripos( $item->name . ' ' . $item->category, 'chain' ) && false === stripos( $item->name, 'ring' ) ) {
			$wear = '0';
		}
		if ( '' !== $wear ) {
			CC_Fleet_Bikes::set( $bike_id, 'dt_wear', $wear );
			// Measuring wear is a check.
			if ( '' === (string) CC_Fleet_Bikes::get( $bike_id, 'dt_checked' ) || $date >= CC_Fleet_Bikes::get( $bike_id, 'dt_checked' ) ) {
				CC_Fleet_Bikes::set( $bike_id, 'dt_checked', $date );
			}
		}
		if ( in_array( 'replace', $did, true ) && $item ) {
			CC_Fleet_Records::adjust_stock( $item->id, -1, 'Fitted to ' . CC_Fleet_Bikes::get( $bike_id, 'cc_id' ) );
		}
		$labels = array( 'clean' => 'Cleaned', 'lube' => 'Lubed/waxed', 'check' => 'Checked', 'replace' => 'Replaced' . ( $item ? ': ' . $item->name : ' a part' ) );
		$what   = array_map( function ( $k ) use ( $labels ) { return $labels[ $k ]; }, $did );
		if ( '' !== $wear ) {
			$what[] = 'Chain wear ' . $wear . ' %';
		}
		CC_Fleet_Install::log( 'bike', $bike_id, 'care', wp_json_encode( array( 'date' => $date, 'did' => $did, 'wear' => $wear, 'note' => $note, 'stock' => $item ? $item->name : '', 'text' => implode( ' · ', $what ) ) ) );
	}

	/** History rows for the bike's history box. */
	public static function history_items( $bike_id ) {
		global $wpdb;
		$items = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE object_type = 'bike' AND object_id = %d AND action = 'care' ORDER BY id DESC LIMIT 100", $bike_id ) ) as $e ) {
			$d = json_decode( (string) $e->message, true );
			if ( is_array( $d ) ) {
				$items[] = array( 'date' => $d['date'] ?? substr( $e->created_at, 0, 10 ), 'sort' => $e->created_at, 'kind' => 'care', 'd' => $d, 'user' => $e->user_id );
			}
		}
		return $items;
	}

	/** Dashboard card: bikes whose drivetrain needs attention. */
	public static function dashboard_card() {
		$rows = array();
		foreach ( CC_Fleet_Bikes::all_ids() as $b ) {
			if ( 'retired' === CC_Fleet_Bikes::get( $b, 'state' ) ) {
				continue;
			}
			$due = self::due( $b );
			if ( $due ) {
				$rows[] = array( $b, $due );
			}
		}
		echo '<section class="cc-card"><h2>Drivetrain due</h2>';
		if ( $rows ) {
			echo '<ul class="cc-list">';
			foreach ( array_slice( $rows, 0, 12 ) as $r ) {
				$urgent = false !== strpos( implode( ' ', $r[1] ), 'Replace' );
				printf( '<li><a href="%s#cc_bike_care">%s</a> <span class="%s">%s</span></li>', esc_url( get_edit_post_link( $r[0] ) ), esc_html( CC_Fleet_Bikes::get( $r[0], 'cc_id' ) ), $urgent ? 'cc-badge cc-badge--declined' : 'cc-muted', esc_html( implode( ' · ', $r[1] ) ) );
			}
			echo '</ul>';
			if ( count( $rows ) > 12 ) {
				echo '<p class="cc-muted">+ ' . ( count( $rows ) - 12 ) . ' more</p>';
			}
		} else {
			echo '<p class="cc-empty">All drivetrains are looked after.</p>';
		}
		echo '</section>';
	}
}

/**
 * Starting tyre pressure for gravel tubeless, from rider weight and tyre width.
 *
 * Calibrated to published manufacturer charts (Vittoria, Zipp, ENVE): pressure rises about
 * 0.32 psi per kg of system weight (rider + ~11.5 kg of bike, bottles and tools) and falls
 * with tyre width (about width^-0.9). Front runs ~5 % under the rear. It is a starting point:
 * lower for loose or rough gravel, higher for mostly tar, never outside the tyre/rim limits.
 */
class CC_Fleet_Pressure {

	const BIKE_KG = 11.5;

	/** @return array|null front_psi, rear_psi, front_bar, rear_bar, width, weight */
	public static function recommend( $rider_kg, $width_mm ) {
		$rider_kg = (float) $rider_kg;
		$width    = (float) preg_replace( '/[^0-9.]/', '', (string) $width_mm );
		if ( $rider_kg < 30 || $rider_kg > 200 || $width < 28 || $width > 65 ) {
			return null;
		}
		$system = $rider_kg + self::BIKE_KG;
		$rear40 = 27.0 + 0.32 * ( $system - 50 );            // Rear psi on a 40 mm tyre.
		$rear   = $rear40 * pow( 40 / $width, 0.9 );
		$rear   = max( 20, min( 55, $rear ) );
		$front  = max( 19, $rear * 0.95 );
		return array(
			'front_psi' => (int) round( $front ),
			'rear_psi'  => (int) round( $rear ),
			'front_bar' => round( $front / 14.504, 1 ),
			'rear_bar'  => round( $rear / 14.504, 1 ),
			'width'     => (int) round( $width ),
			'weight'    => (int) round( $rider_kg ),
		);
	}

	/** The bike's tyre width: from its tyres part, else its spec field. */
	public static function width_for_bike( $bike_id ) {
		$p = class_exists( 'CC_Fleet_Parts' ) ? CC_Fleet_Parts::on_bike( $bike_id, 'tyres' ) : null;
		$w = $p ? $p->size : CC_Fleet_Bikes::get( $bike_id, 'tyre_size' );
		return (float) preg_replace( '/[^0-9.]/', '', (string) $w );
	}

	/** One line for emails and the loan page, or '' when weight or width is unknown. */
	public static function line_for_loan( $loan ) {
		$r = CC_Fleet_Riders::get( $loan->rider_id );
		$p = $r && ! empty( $r->weight_kg ) ? self::recommend( $r->weight_kg, self::width_for_bike( $loan->bike_id ) ) : null;
		if ( ! $p ) {
			return '';
		}
		return sprintf( 'Front %d psi (%s bar) · Rear %d psi (%s bar)', $p['front_psi'], number_format( $p['front_bar'], 1 ), $p['rear_psi'], number_format( $p['rear_bar'], 1 ) );
	}

	/** Short explanation under the numbers. */
	public static function advice( $loan ) {
		$r = CC_Fleet_Riders::get( $loan->rider_id );
		$w = self::width_for_bike( $loan->bike_id );
		return sprintf( 'Starting point for %s kg on %s mm tubeless tyres, mixed gravel. Loose or rough: 2–3 psi lower. Mostly tar: 3–5 psi higher. Check before every ride.', $r && $r->weight_kg ? (int) $r->weight_kg : '?', $w ? (int) $w : '?' );
	}
}
