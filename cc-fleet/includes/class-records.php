<?php
defined( 'ABSPATH' ) || exit;

/**
 * Workshop, Stock, Events and Notes: the rest of the old app.
 *
 * Each is a small private table described by a field list, so the admin screens, saving,
 * CSV import and validation all come from one definition.
 */
class CC_Fleet_Records {

	const MAINT_TYPES  = array( 'inspection' => 'Inspection', 'repair' => 'Repair', 'service' => 'Service', 'cleaning' => 'Cleaning', 'upgrade' => 'Upgrade' );
	const EVENT_TYPES  = array( 'Gravel', 'Gravel Adventure', 'Group ride', 'Getaway', 'Road', 'Bikepacking', 'Other' );
	const NOTE_STATUS  = array( 'active' => 'Active', 'archived' => 'Archived' );

	/** Field definitions: key => [label, type, options/extra]. */
	public static function fields( $kind ) {
		switch ( $kind ) {
			case 'maintenance':
				return array(
					'bike_id'     => array( 'Bike', 'bike' ),
					'type'        => array( 'Type', 'select', self::MAINT_TYPES ),
					'status'      => array( 'Status', 'select', array( 'scheduled' => 'Scheduled', 'completed' => 'Completed' ) ),
					'work_date'   => array( 'Date', 'date' ),
					'cost'        => array( 'Cost (R)', 'number' ),
					'technician'  => array( 'Technician / shop', 'text' ),
					'description' => array( 'What was done / needs doing', 'textarea' ),
				);
			case 'stock':
				return array(
					'name'      => array( 'Item', 'text' ),
					'category'  => array( 'Category', 'text' ),
					'quantity'  => array( 'Quantity', 'number' ),
					'min_qty'   => array( 'Minimum level', 'number', 'Flagged as low when stock is at or below 1.5× this' ),
					'color'     => array( 'Colour tag', 'color' ),
					'image_url' => array( 'Image URL', 'url' ),
					'notes'     => array( 'Notes', 'textarea' ),
				);
			case 'events':
				return array(
					'name'         => array( 'Event', 'text' ),
					'type'         => array( 'Type', 'select', array_combine( self::EVENT_TYPES, self::EVENT_TYPES ) ),
					'start_date'   => array( 'Date', 'date' ),
					'end_date'     => array( 'Until (multi-day)', 'date', 'Leave empty for a one-day event' ),
					'location'     => array( 'Location', 'text' ),
					'cancelled'    => array( 'Cancelled', 'checkbox' ),
					'bikes_count'  => array( 'Bikes attended', 'number', 'Filled in automatically from linked loans; type a number to override' ),
					'description'  => array( 'Description', 'textarea' ),
					'image_url'    => array( 'Image URL', 'url' ),
					'link'         => array( 'Link (race page, Instagram, Strava)', 'url' ),
					'show_on_site' => array( 'Show on the website', 'checkbox' ),
				);
			case 'notes':
				return array(
					'title'     => array( 'Title', 'text' ),
					'category'  => array( 'Category', 'text' ),
					'content'   => array( 'Note', 'textarea' ),
					'image_url' => array( 'Image URL', 'url' ),
					'status'    => array( 'Status', 'select', self::NOTE_STATUS ),
				);
		}
		return array();
	}

	public static function init() {
		add_shortcode( 'cc_events', array( __CLASS__, 'shortcode_events' ) );
	}

	/**
	 * [cc_events show="upcoming|past|all" limit="6"]: events ticked "Show on the website".
	 * Only public fields are printed: no riders, no loans.
	 */
	public static function shortcode_events( $atts ) {
		$a     = shortcode_atts( array( 'show' => 'upcoming', 'limit' => 6 ), $atts, 'cc_events' );
		$today = CC_Fleet_Settings::today();
		$args  = array( 'where' => array( 'show_on_site' => 1, 'cancelled' => 0 ), 'limit' => max( 1, min( 50, (int) $a['limit'] ) ) );
		if ( 'upcoming' === $a['show'] ) {
			$args += array( 'from' => $today, 'order' => 'ASC' );
		} elseif ( 'past' === $a['show'] ) {
			$args += array( 'to' => CC_Fleet_Loans::add_days( $today, -1 ), 'order' => 'DESC' );
		}
		$rows = self::query( 'events', $args );
		if ( ! $rows ) {
			return '<p class="cc-events-empty">Nothing on the calendar right now. Follow along on Instagram.</p>';
		}
		CC_Fleet_Frontend::register_assets();
		wp_enqueue_style( 'cc-fleet' );
		$html = '<ul class="cc-events">';
		foreach ( $rows as $e ) {
			$img   = preg_match( '/\.(jpe?g|png|webp|gif)(\?|$)/i', $e->image_url ) ? '<img src="' . esc_url( $e->image_url ) . '" alt="" loading="lazy">' : '';
			$when  = wp_date( 'j M', strtotime( $e->start_date . ' 12:00' ) ) . ( $e->end_date ? '–' . wp_date( 'j M', strtotime( $e->end_date . ' 12:00' ) ) : '' );
			$html .= sprintf( '<li class="cc-event">%s<div class="cc-event__body"><p class="cc-event__meta">%s · %s · %s</p><h3 class="cc-event__name">%s</h3><p>%s</p>%s</div></li>',
				$img, esc_html( $when ), esc_html( $e->location ), esc_html( $e->type ), esc_html( $e->name ), esc_html( wp_trim_words( (string) $e->description, 28 ) ),
				$e->link ? '<a class="cc-event__link" href="' . esc_url( $e->link ) . '" target="_blank" rel="noopener">More →</a>' : '' );
		}
		return $html . '</ul>';
	}

	public static function kinds() {
		return array( 'maintenance' => 'Workshop', 'stock' => 'Stock', 'events' => 'Events', 'notes' => 'Notes' );
	}

	public static function table( $kind ) {
		return CC_Fleet_Install::table( $kind );
	}

	/* ---------------- Install ---------------- */

	public static function create_tables( $c ) {
		$m = self::table( 'maintenance' );
		$s = self::table( 'stock' );
		$e = self::table( 'events' );
		$n = self::table( 'notes' );
		dbDelta( "CREATE TABLE $m (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bike_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(20) NOT NULL DEFAULT 'service',
			status varchar(20) NOT NULL DEFAULT 'scheduled',
			work_date date DEFAULT NULL,
			cost decimal(10,2) NOT NULL DEFAULT 0,
			technician varchar(190) NOT NULL DEFAULT '',
			description text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY bike (bike_id),
			KEY status (status)
		) $c;" );
		dbDelta( "CREATE TABLE $s (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			category varchar(120) NOT NULL DEFAULT '',
			quantity int(11) NOT NULL DEFAULT 0,
			min_qty int(11) NOT NULL DEFAULT 0,
			color varchar(9) NOT NULL DEFAULT '',
			image_url varchar(500) NOT NULL DEFAULT '',
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY category (category)
		) $c;" );
		dbDelta( "CREATE TABLE $e (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			type varchar(60) NOT NULL DEFAULT 'Gravel',
			start_date date NOT NULL,
			end_date date DEFAULT NULL,
			location varchar(190) NOT NULL DEFAULT '',
			cancelled tinyint(1) NOT NULL DEFAULT 0,
			bikes_count smallint(5) unsigned DEFAULT NULL,
			description text NULL,
			image_url varchar(500) NOT NULL DEFAULT '',
			link varchar(500) NOT NULL DEFAULT '',
			show_on_site tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY dates (start_date)
		) $c;" );
		dbDelta( "CREATE TABLE $n (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(190) NOT NULL DEFAULT '',
			category varchar(120) NOT NULL DEFAULT '',
			content text NULL,
			image_url varchar(500) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) $c;" );
	}

	/* ---------------- Read / write ---------------- */

	public static function get( $kind, $id ) {
		global $wpdb;
		if ( ! isset( self::kinds()[ $kind ] ) || ! is_numeric( $id ) ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( $kind ) . ' WHERE id = %d', (int) $id ) );
	}

	/**
	 * @param array $args where (assoc, exact match), search, from/to (date column range), order, limit
	 */
	public static function query( $kind, array $args = array() ) {
		global $wpdb;
		$t      = self::table( $kind );
		$where  = array( '1=1' );
		$params = array();
		foreach ( (array) ( $args['where'] ?? array() ) as $col => $val ) {
			if ( isset( self::fields( $kind )[ $col ] ) || 'id' === $col ) {
				if ( is_array( $val ) ) {
					$where[] = "`$col` IN (" . implode( ',', array_fill( 0, count( $val ), '%s' ) ) . ')';
					$params  = array_merge( $params, $val );
				} else {
					$where[]  = "`$col` = %s";
					$params[] = $val;
				}
			}
		}
		$dcol = self::date_col( $kind );
		if ( $dcol && ! empty( $args['from'] ) ) {
			$where[]  = 'events' === $kind ? 'COALESCE(end_date, start_date) >= %s' : "`$dcol` >= %s";
			$params[] = $args['from'];
		}
		if ( $dcol && ! empty( $args['to'] ) ) {
			$where[]  = "`$dcol` <= %s";
			$params[] = $args['to'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like  = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$cols  = array_keys( array_filter( self::fields( $kind ), function ( $f ) { return in_array( $f[1], array( 'text', 'textarea' ), true ); } ) );
			$where[] = '(' . implode( ' OR ', array_map( function ( $c ) { return "`$c` LIKE %s"; }, $cols ) ) . ')';
			$params  = array_merge( $params, array_fill( 0, count( $cols ), $like ) );
		}
		$order = 'ASC' === strtoupper( $args['order'] ?? '' ) ? 'ASC' : 'DESC';
		$by    = $args['orderby'] ?? ( $dcol ?: 'updated_at' );
		$by    = preg_replace( '/[^a-z_]/', '', $by );
		$limit = max( 1, min( 1000, (int) ( $args['limit'] ?? 200 ) ) );
		$sql   = "SELECT * FROM $t WHERE " . implode( ' AND ', $where ) . " ORDER BY `$by` $order, id $order LIMIT $limit";
		return $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
	}

	public static function date_col( $kind ) {
		return array( 'maintenance' => 'work_date', 'events' => 'start_date' )[ $kind ] ?? '';
	}

	/** Validate + store. Returns id or WP_Error. */
	public static function save( $kind, array $in, $id = 0 ) {
		global $wpdb;
		$row = array();
		foreach ( self::fields( $kind ) as $key => $f ) {
			if ( 'checkbox' === $f[1] ) {
				$row[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
				continue;
			}
			if ( ! array_key_exists( $key, $in ) ) {
				continue;
			}
			$v = is_string( $in[ $key ] ) ? trim( wp_unslash( $in[ $key ] ) ) : $in[ $key ];
			switch ( $f[1] ) {
				case 'textarea': $v = sanitize_textarea_field( $v ); break;
				case 'url':      $v = esc_url_raw( $v ); break;
				// Cost, quantity and minimum are NOT NULL columns: an empty field is 0 (MySQL strict mode refuses NULL there).
				case 'number':   $v = '' === $v || null === $v ? ( in_array( $key, array( 'cost', 'quantity', 'min_qty' ), true ) ? 0 : null ) : ( 'cost' === $key ? round( (float) str_replace( ',', '.', (string) $v ), 2 ) : (int) $v ); break;
				case 'date':     $v = CC_Fleet_Import::date( (string) $v ) ?: null; break;
				case 'bike':     $v = (int) $v; break;
				case 'color':    $v = preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $v ) ? $v : ''; break;
				case 'select':   $v = isset( $f[2][ $v ] ) ? $v : array_key_first( $f[2] ); break;
				default:         $v = sanitize_text_field( $v );
			}
			$row[ $key ] = $v;
		}
		// Required fields.
		$need = array( 'maintenance' => 'bike_id', 'stock' => 'name', 'events' => 'name', 'notes' => 'title' )[ $kind ];
		if ( empty( $row[ $need ] ) && ! ( $id && ! array_key_exists( $need, $row ) ) ) {
			return new WP_Error( 'cc_required', self::fields( $kind )[ $need ][0] . ' is required.' );
		}
		if ( 'events' === $kind ) {
			if ( empty( $row['start_date'] ) && ! $id ) {
				return new WP_Error( 'cc_required', 'Date is required.' );
			}
			if ( ! empty( $row['end_date'] ) && ! empty( $row['start_date'] ) && $row['end_date'] < $row['start_date'] ) {
				$row['end_date'] = $row['start_date'];
			}
			if ( isset( $row['end_date'] ) && $row['end_date'] === ( $row['start_date'] ?? null ) ) {
				$row['end_date'] = null;
			}
		}
		if ( 'maintenance' === $kind && empty( $row['work_date'] ) && ! $id ) {
			$row['work_date'] = CC_Fleet_Settings::today();
		}
		$row['updated_at'] = current_time( 'mysql' );
		if ( $id ) {
			$wpdb->update( self::table( $kind ), $row, array( 'id' => (int) $id ) );
		} else {
			$row['created_at'] = $row['updated_at'];
			if ( false === $wpdb->insert( self::table( $kind ), $row ) || ! $wpdb->insert_id ) {
				return new WP_Error( 'cc_db', 'Could not save: ' . ( $wpdb->last_error ?: 'database error' ) );
			}
			$id = (int) $wpdb->insert_id;
		}
		CC_Fleet_Install::log( $kind, $id, 'saved' );
		return (int) $id;
	}

	public static function delete( $kind, $id ) {
		global $wpdb;
		if ( 'events' === $kind ) {
			$wpdb->update( CC_Fleet_Loans::table(), array( 'event_id' => 0 ), array( 'event_id' => (int) $id ) );
		}
		$wpdb->delete( self::table( $kind ), array( 'id' => (int) $id ) );
		CC_Fleet_Install::log( $kind, $id, 'deleted' );
	}

	/* ---------------- Stock helpers ---------------- */

	/** ok / low / out. */
	public static function stock_level( $item ) {
		if ( (int) $item->quantity <= 0 ) {
			return 'out';
		}
		// Like the old app: flag it while there is still a little buffer (up to 1.5x the minimum).
		return (int) $item->min_qty > 0 && (int) $item->quantity <= 1.5 * (int) $item->min_qty ? 'low' : 'ok';
	}

	public static function adjust_stock( $id, $delta, $reason = '' ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'stock' ) . ' SET quantity = GREATEST(0, quantity + %d), updated_at = %s WHERE id = %d', (int) $delta, current_time( 'mysql' ), (int) $id ) );
		CC_Fleet_Install::log( 'stock', $id, $delta > 0 ? 'added' : 'used', sprintf( '%+d %s', $delta, $reason ) );
	}

	public static function low_stock() {
		return array_values( array_filter( self::query( 'stock', array( 'orderby' => 'name', 'order' => 'ASC', 'limit' => 500 ) ), function ( $i ) {
			return 'ok' !== self::stock_level( $i );
		} ) );
	}

	/* ---------------- Event helpers ---------------- */

	public static function event_end( $e ) {
		return $e->end_date ?: $e->start_date;
	}

	/** Worked out from the dates, so it never goes stale: upcoming / ongoing / completed / cancelled. */
	public static function event_status( $e ) {
		if ( (int) $e->cancelled ) {
			return 'cancelled';
		}
		$today = CC_Fleet_Settings::today();
		if ( $e->start_date > $today ) {
			return 'upcoming';
		}
		return self::event_end( $e ) >= $today ? 'ongoing' : 'completed';
	}

	public static function event_loans( $event_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CC_Fleet_Loans::table() . " WHERE event_id = %d AND status NOT IN ('declined') ORDER BY start_date ASC", (int) $event_id ) );
	}

	public static function bikes_at( $e ) {
		if ( null !== $e->bikes_count && '' !== $e->bikes_count ) {
			return (int) $e->bikes_count;
		}
		return count( array_filter( self::event_loans( $e->id ), function ( $l ) { return 'cancelled' !== $l->status; } ) );
	}

	/**
	 * Link loans to events when the loan's plan names the event and the loan covers its dates
	 * (with a few days either side for travel). Only fills loans that have no event yet.
	 */
	public static function auto_link_events() {
		global $wpdb;
		$linked = 0;
		$norm   = function ( $s ) {
			$s = strtolower( remove_accents( (string) $s ) );
			$s = preg_replace( '/\b(the|race|20\d\d|powered by.*|bicycle|cancelled)\b/', ' ', $s );
			$s = str_replace( array( 'tanqua', 'riebeeck', 'malmsbury', 'gallow ', 'canolla' ), array( 'tankwa', 'riebeek', 'malmesbury', 'gallows ', 'canola' ), $s );
			return trim( preg_replace( '/[^a-z0-9#]+/', ' ', $s ) );
		};
		foreach ( self::query( 'events', array( 'limit' => 1000 ) ) as $e ) {
			$key = $norm( $e->name );
			$key = preg_replace( '/^gravel group ride (#\d).*$/', 'ggr $1', $key );
			if ( strlen( $key ) < 4 ) {
				continue;
			}
			// The loan must overlap the event, give or take three days for travel.
			$latest_start = CC_Fleet_Loans::add_days( self::event_end( $e ), 3 );
			$earliest_end = CC_Fleet_Loans::add_days( $e->start_date, -3 );
			$loans = $wpdb->get_results( $wpdb->prepare( 'SELECT id, plan FROM ' . CC_Fleet_Loans::table() . ' WHERE event_id = 0 AND start_date <= %s AND end_date >= %s', $latest_start, $earliest_end ) );
			foreach ( $loans as $l ) {
				$plan = $norm( $l->plan );
				$plan = preg_replace( '/ccxstandert ggr (#?\d)/', 'ggr #$1', $plan );
				$plan = str_replace( '##', '#', $plan );
				$words = array_filter( explode( ' ', $key ), function ( $w ) { return strlen( $w ) > 2 || '#' === $w[0]; } );
				$hit   = $words && count( array_filter( $words, function ( $w ) use ( $plan ) { return false !== strpos( ' ' . $plan . ' ', ' ' . $w ); } ) ) === count( $words );
				if ( $hit ) {
					$wpdb->update( CC_Fleet_Loans::table(), array( 'event_id' => $e->id ), array( 'id' => $l->id ) );
					$linked++;
				}
			}
		}
		return $linked;
	}

	/* ---------------- Maintenance helpers ---------------- */

	/** Scheduled work that is due puts the bike in the workshop; finishing it puts the bike back. */
	public static function sync_bike_state( $maint_id ) {
		$m = self::get( 'maintenance', $maint_id );
		if ( ! $m || ! $m->bike_id ) {
			return;
		}
		$state = CC_Fleet_Bikes::get( $m->bike_id, 'state' );
		if ( 'scheduled' === $m->status && $m->work_date && $m->work_date <= CC_Fleet_Settings::today() && 'active' === $state ) {
			CC_Fleet_Bikes::set( $m->bike_id, 'state', 'workshop' );
		}
		if ( 'completed' === $m->status && 'workshop' === $state ) {
			$open = self::query( 'maintenance', array( 'where' => array( 'bike_id' => $m->bike_id, 'status' => 'scheduled' ), 'to' => CC_Fleet_Settings::today(), 'limit' => 1 ) );
			if ( ! $open ) {
				CC_Fleet_Bikes::set( $m->bike_id, 'state', 'active' );
			}
		}
	}

	/** Daily: put bikes with due work in the workshop. */
	public static function daily() {
		foreach ( self::query( 'maintenance', array( 'where' => array( 'status' => 'scheduled' ), 'to' => CC_Fleet_Settings::today(), 'limit' => 200 ) ) as $m ) {
			self::sync_bike_state( $m->id );
		}
	}

	/* ---------------- CSV import ---------------- */

	/** Same columns as the field keys; old-app spellings are accepted too. */
	public static function import_csv( $kind, $rows ) {
		$res     = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() );
		$aliases = array( 'date' => array( 'maintenance' => 'work_date', 'events' => 'start_date' ), 'bike' => 'bike_id', 'min' => 'min_qty', 'minimum' => 'min_qty', 'qty' => 'quantity', 'by' => 'technician', 'title' => array( 'notes' => 'title' ) );
		foreach ( $rows as $i => $r ) {
			$in = array();
			foreach ( $r as $k => $v ) {
				$k = strtolower( trim( (string) $k ) );
				$a = $aliases[ $k ] ?? $k;
				$a = is_array( $a ) ? ( $a[ $kind ] ?? $k ) : $a;
				$in[ $a ] = trim( (string) $v );
			}
			if ( 'maintenance' === $kind ) {
				$in['bike_id'] = CC_Fleet_Bikes::find_by_cc_id( $in['bike_id'] ?? '' ) ?: 0;
				$in['type']    = strtolower( $in['type'] ?? 'service' );
				$in['status']  = strtolower( $in['status'] ?? 'completed' );
			}
			if ( 'notes' === $kind ) {
				$in['status'] = strtolower( $in['status'] ?? 'active' );
			}
			if ( 'events' === $kind ) {
				$in['cancelled']    = ! empty( $in['cancelled'] ) && ! in_array( strtolower( $in['cancelled'] ), array( '0', 'no', 'false' ), true );
				$in['show_on_site'] = ! empty( $in['show_on_site'] ) && ! in_array( strtolower( $in['show_on_site'] ), array( '0', 'no', 'false' ), true );
			}
			$dupe = self::find_duplicate( $kind, $in );
			if ( $dupe ) {
				$res['skipped']++;
				continue;
			}
			$id = self::save( $kind, $in );
			if ( is_wp_error( $id ) ) {
				$res['errors'][] = 'Row ' . ( $i + 2 ) . ': ' . $id->get_error_message();
				continue;
			}
			$res['created']++;
		}
		if ( 'events' === $kind ) {
			$res['linked'] = self::auto_link_events();
			// Keep a typed-in bike count only where it says more than the linked loans do.
			global $wpdb;
			foreach ( self::query( 'events', array( 'limit' => 1000 ) ) as $e ) {
				if ( null !== $e->bikes_count && count( array_filter( self::event_loans( $e->id ), function ( $l ) { return 'cancelled' !== $l->status; } ) ) >= (int) $e->bikes_count ) {
					$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'events' ) . ' SET bikes_count = NULL WHERE id = %d', $e->id ) );
				}
			}
		}
		CC_Fleet_Install::log( 'import', 0, $kind, wp_json_encode( array_diff_key( $res, array( 'errors' => 1 ) ) ) );
		return $res;
	}

	/** Importing the same file twice adds nothing. */
	private static function find_duplicate( $kind, array $in ) {
		global $wpdb;
		$t = self::table( $kind );
		switch ( $kind ) {
			case 'stock':
				return $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE name = %s LIMIT 1", $in['name'] ?? '' ) );
			case 'events':
				return $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE name = %s AND start_date = %s LIMIT 1", $in['name'] ?? '', CC_Fleet_Import::date( $in['start_date'] ?? '' ) ) );
			case 'notes':
				return $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE LOWER(title) = LOWER(%s) AND content = %s LIMIT 1", $in['title'] ?? '', sanitize_textarea_field( $in['content'] ?? '' ) ) );
			case 'maintenance':
				return $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE bike_id = %d AND work_date = %s AND description = %s LIMIT 1", (int) ( $in['bike_id'] ?? 0 ), CC_Fleet_Import::date( $in['work_date'] ?? '' ), sanitize_textarea_field( $in['description'] ?? '' ) ) );
		}
		return null;
	}
}
