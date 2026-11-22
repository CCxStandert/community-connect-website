<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin screens for Workshop, Stock, Events and Notes, plus the dashboard panels and the
 * events row on the fleet calendar. Same rules as the rest: nonce + manage_cc_fleet on every write.
 */
class CC_Fleet_Records_Admin {

	const CAP = 'manage_cc_fleet';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		foreach ( array( 'record_save', 'record_delete', 'stock_adjust', 'maint_done', 'record_import', 'events_link', 'note_archive', 'raffle_hold' ) as $a ) {
			add_action( 'admin_post_cc_fleet_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	public static function menu() {
		$pages = array( 'cc-fleet-workshop' => array( 'Workshop', 'maintenance' ), 'cc-fleet-stock' => array( 'Stock', 'stock' ), 'cc-fleet-events' => array( 'Events', 'events' ), 'cc-fleet-notes' => array( 'Notes', 'notes' ) );
		$low   = count( CC_Fleet_Records::low_stock() );
		foreach ( $pages as $slug => $p ) {
			$label = $p[0] . ( 'stock' === $p[1] && $low ? ' <span class="awaiting-mod">' . (int) $low . '</span>' : '' );
			add_submenu_page( 'cc-fleet', $p[0], $label, self::CAP, $slug, function () use ( $p ) {
				self::page_list( $p[1] );
			} );
		}
		add_submenu_page( 'cc-fleet', 'Edit', '', self::CAP, 'cc-fleet-record', array( __CLASS__, 'page_edit' ) );
	}

	/* ---------------- Helpers ---------------- */

	private static function url( $page, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private static function list_page( $kind ) {
		return array( 'maintenance' => 'cc-fleet-workshop', 'stock' => 'cc-fleet-stock', 'events' => 'cc-fleet-events', 'notes' => 'cc-fleet-notes' )[ $kind ];
	}

	private static function action_url( $action, $args, $nonce ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'cc_fleet_' . $action ), $args ), admin_url( 'admin-post.php' ) ), $nonce );
	}

	private static function guard( $nonce ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( $nonce );
	}

	private static function back( $url, $msg, $type = 'success' ) {
		set_transient( 'cc_fleet_notice_' . get_current_user_id(), array( $msg, $type ), 60 );
		wp_safe_redirect( $url );
		exit;
	}

	private static function notices() {
		$n = get_transient( 'cc_fleet_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'cc_fleet_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[1] ), wp_kses_post( $n[0] ) );
		}
	}

	private static function tabs( $page, $tabs, $current, $extra = array() ) {
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $tabs as $k => $label ) {
			$links[] = sprintf( '<li><a href="%s"%s>%s</a>', esc_url( self::url( $page, array_merge( $extra, array( 'tab' => $k ) ) ) ), $k === $current ? ' class="current"' : '', esc_html( $label ) );
		}
		echo implode( ' | </li>', $links ) . '</li></ul>'; // phpcs:ignore
	}

	private static function search_box( $page, $tab, $value ) {
		printf( '<form class="cc-search" method="get"><input type="hidden" name="page" value="%s"><input type="hidden" name="tab" value="%s"><input type="search" name="s" value="%s" placeholder="Search"> <button class="button">Search</button></form>',
			esc_attr( $page ), esc_attr( $tab ), esc_attr( $value ) );
	}

	private static function nice( $d ) {
		return $d ? wp_date( 'j M Y', strtotime( $d . ' 12:00' ) ) : '—';
	}

	private static function bike_link( $bike_id ) {
		if ( ! $bike_id || ! get_post( $bike_id ) ) {
			return '—';
		}
		return '<a href="' . esc_url( get_edit_post_link( $bike_id ) ) . '">' . esc_html( CC_Fleet_Bikes::label( $bike_id ) ) . '</a>';
	}

	/* ---------------- List pages ---------------- */

	public static function page_list( $kind ) {
		$page   = self::list_page( $kind );
		$titles = array( 'maintenance' => 'Workshop', 'stock' => 'Stock', 'events' => 'Events & races', 'notes' => 'Notes' );
		$new    = array( 'maintenance' => '+ Schedule work', 'stock' => '+ Add item', 'events' => '+ Add event', 'notes' => '+ Add note' );
		echo '<div class="wrap cc-wrap"><h1 class="wp-heading-inline">' . esc_html( $titles[ $kind ] ) . '</h1> ';
		printf( '<a class="page-title-action" href="%s">%s</a>', esc_url( self::url( 'cc-fleet-record', array( 'kind' => $kind ) ) ), esc_html( $new[ $kind ] ) );
		echo '<hr class="wp-header-end">';
		self::notices();
		call_user_func( array( __CLASS__, 'list_' . $kind ) );
		echo '</div>';
	}

	private static function list_maintenance() {
		$tab    = sanitize_key( $_GET['tab'] ?? 'scheduled' );
		$bike   = (int) ( $_GET['bike'] ?? 0 );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		self::tabs( 'cc-fleet-workshop', array( 'scheduled' => 'Scheduled', 'completed' => 'Done', 'all' => 'All' ), $tab );
		self::search_box( 'cc-fleet-workshop', $tab, $search );
		$where = $bike ? array( 'bike_id' => $bike ) : array();
		if ( 'all' !== $tab ) {
			$where['status'] = 'scheduled' === $tab ? 'scheduled' : 'completed';
		}
		$rows = CC_Fleet_Records::query( 'maintenance', array( 'where' => $where, 'search' => $search, 'order' => 'scheduled' === $tab ? 'ASC' : 'DESC', 'limit' => 300 ) );
		if ( ! $rows ) {
			echo '<p class="cc-empty">Nothing here.</p>';
			return;
		}
		$total = array_sum( wp_list_pluck( $rows, 'cost' ) );
		echo '<table class="widefat striped cc-table"><thead><tr><th>Date</th><th>Bike</th><th>Type</th><th>Work</th><th>By</th><th>Cost</th><th>Status</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $m ) {
			$due  = 'scheduled' === $m->status && $m->work_date && $m->work_date <= CC_Fleet_Settings::today();
			$done = 'scheduled' === $m->status ? sprintf( '<a class="button button-primary" href="%s">Done</a> ', esc_url( self::action_url( 'maint_done', array( 'id' => $m->id ), 'cc_fleet_maint_done_' . $m->id ) ) ) : '';
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><span class="cc-badge cc-badge--%s">%s</span></td><td class="cc-actions">%s<a class="button" href="%s">Edit</a></td></tr>',
				esc_html( self::nice( $m->work_date ) ), self::bike_link( (int) $m->bike_id ), esc_html( CC_Fleet_Records::MAINT_TYPES[ $m->type ] ?? $m->type ),
				nl2br( esc_html( wp_trim_words( (string) $m->description, 30 ) ) ), esc_html( $m->technician ), $m->cost > 0 ? 'R' . esc_html( number_format_i18n( (float) $m->cost ) ) : '—',
				$due ? 'overdue' : esc_attr( $m->status ), $due ? 'Due' : esc_html( ucfirst( $m->status ) ), $done, esc_url( self::url( 'cc-fleet-record', array( 'kind' => 'maintenance', 'id' => $m->id ) ) ) ); // phpcs:ignore
		}
		printf( '</tbody><tfoot><tr><th colspan="5">%d jobs</th><th>R%s</th><th colspan="2"></th></tr></tfoot></table>', count( $rows ), esc_html( number_format_i18n( $total ) ) );
	}

	private static function list_stock() {
		$cat    = sanitize_text_field( wp_unslash( $_GET['cat'] ?? '' ) );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$all    = CC_Fleet_Records::query( 'stock', array( 'orderby' => 'name', 'order' => 'ASC', 'limit' => 1000 ) );
		$cats   = array_unique( array_filter( wp_list_pluck( $all, 'category' ) ) );
		sort( $cats );
		echo '<ul class="subsubsub"><li><a href="' . esc_url( self::url( 'cc-fleet-stock' ) ) . '"' . ( $cat ? '' : ' class="current"' ) . '>All (' . count( $all ) . ')</a></li>';
		foreach ( $cats as $c ) {
			printf( ' | <li><a href="%s"%s>%s</a></li>', esc_url( self::url( 'cc-fleet-stock', array( 'cat' => $c ) ) ), $c === $cat ? ' class="current"' : '', esc_html( $c ) );
		}
		echo '</ul>';
		self::search_box( 'cc-fleet-stock', '', $search );
		$rows = CC_Fleet_Records::query( 'stock', array( 'where' => $cat ? array( 'category' => $cat ) : array(), 'search' => $search, 'orderby' => 'name', 'order' => 'ASC', 'limit' => 1000 ) );
		if ( ! $rows ) {
			echo '<p class="cc-empty">Nothing here.</p>';
			return;
		}
		echo '<table class="widefat striped cc-table"><thead><tr><th style="width:24px"></th><th>Item</th><th>Category</th><th>In stock</th><th>Minimum</th><th>Level</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $s ) {
			$lvl = CC_Fleet_Records::stock_level( $s );
			$adj = function ( $d ) use ( $s ) {
				return sprintf( '<a class="button button-small" href="%s" aria-label="%s">%s</a>', esc_url( self::action_url( 'stock_adjust', array( 'id' => $s->id, 'delta' => $d ), 'cc_fleet_stock_adjust_' . $s->id ) ), $d > 0 ? 'Add one' : 'Use one', $d > 0 ? '+1' : '−1' );
			};
			printf( '<tr><td><span class="cc-dot" style="background:%s"></span></td><td><strong>%s</strong>%s</td><td>%s</td><td class="cc-qty">%s <strong>%d</strong> %s</td><td>%d</td><td><span class="cc-badge cc-badge--stock-%s">%s</span></td><td><a class="button" href="%s">Edit</a></td></tr>',
				esc_attr( $s->color ?: '#D3C8B4' ), esc_html( $s->name ), $s->notes ? '<br><small class="cc-muted">' . esc_html( wp_trim_words( $s->notes, 14 ) ) . '</small>' : '', esc_html( $s->category ),
				$adj( -1 ), (int) $s->quantity, $adj( 1 ), (int) $s->min_qty, esc_attr( $lvl ), esc_html( array( 'ok' => 'Good', 'low' => 'Low', 'out' => 'Out' )[ $lvl ] ),
				esc_url( self::url( 'cc-fleet-record', array( 'kind' => 'stock', 'id' => $s->id ) ) ) ); // phpcs:ignore
		}
		echo '</tbody></table><p class="cc-muted">−1 / +1 are logged with your name, so you can see who took what.</p>';
	}

	private static function list_events() {
		$tab  = sanitize_key( $_GET['tab'] ?? 'upcoming' );
		$view = sanitize_key( $_GET['view'] ?? 'list' );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		printf( '<p><a class="button%s" href="%s">List</a> <a class="button%s" href="%s">Month</a></p>', 'list' === $view ? ' button-primary' : '', esc_url( self::url( 'cc-fleet-events' ) ), 'month' === $view ? ' button-primary' : '', esc_url( self::url( 'cc-fleet-events', array( 'view' => 'month' ) ) ) );
		if ( 'month' === $view ) {
			self::events_month();
			return;
		}
		self::tabs( 'cc-fleet-events', array( 'upcoming' => 'Upcoming', 'ongoing' => 'On now', 'completed' => 'Done', 'cancelled' => 'Cancelled', 'all' => 'All' ), $tab );
		self::search_box( 'cc-fleet-events', $tab, $search );
		$rows = CC_Fleet_Records::query( 'events', array( 'search' => $search, 'order' => in_array( $tab, array( 'upcoming', 'ongoing' ), true ) ? 'ASC' : 'DESC', 'limit' => 500 ) );
		$rows = array_values( array_filter( $rows, function ( $e ) use ( $tab ) { return 'all' === $tab || CC_Fleet_Records::event_status( $e ) === $tab; } ) );
		if ( ! $rows ) {
			echo '<p class="cc-empty">Nothing here.</p>';
			return;
		}
		echo '<table class="widefat striped cc-table"><thead><tr><th>Date</th><th>Event</th><th>Location</th><th>Bikes</th><th>Status</th><th>Website</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $e ) {
			$st = CC_Fleet_Records::event_status( $e );
			printf( '<tr><td>%s%s</td><td><strong>%s</strong><br><small class="cc-muted">%s</small></td><td>%s</td><td>%d</td><td><span class="cc-badge cc-badge--ev-%s">%s</span></td><td>%s</td><td><a class="button" href="%s">Open</a></td></tr>',
				esc_html( self::nice( $e->start_date ) ), $e->end_date ? ' → ' . esc_html( self::nice( $e->end_date ) ) : '', esc_html( $e->name ), esc_html( $e->type . ( $e->link ? ' · ' : '' ) ) . ( $e->link ? '<a href="' . esc_url( $e->link ) . '" target="_blank" rel="noopener">link</a>' : '' ),
				esc_html( $e->location ), CC_Fleet_Records::bikes_at( $e ), esc_attr( $st ), esc_html( array( 'upcoming' => 'Upcoming', 'ongoing' => 'On now', 'completed' => 'Done', 'cancelled' => 'Cancelled' )[ $st ] ),
				(int) $e->show_on_site ? '✓' : '—', esc_url( self::url( 'cc-fleet-record', array( 'kind' => 'events', 'id' => $e->id ) ) ) ); // phpcs:ignore
		}
		echo '</tbody></table><p class="cc-muted">Status follows the dates automatically. Bikes = loans linked to the event (set on the loan, or found from the loan’s plan).</p>';
	}

	/** Month grid: events plus how many bikes are out each day. */
	private static function events_month() {
		$m     = isset( $_GET['m'] ) && preg_match( '/^\d{4}-\d{2}$/', $_GET['m'] ) ? sanitize_text_field( $_GET['m'] ) : wp_date( 'Y-m' );
		$first = $m . '-01';
		$last  = wp_date( 'Y-m-t', strtotime( $first . ' 12:00' ) );
		$prev  = wp_date( 'Y-m', strtotime( $first . ' 12:00 -1 month' ) );
		$next  = wp_date( 'Y-m', strtotime( $first . ' 12:00 +1 month' ) );
		$today = CC_Fleet_Settings::today();
		printf( '<h2><a class="button" href="%s">‹</a> %s <a class="button" href="%s">›</a></h2>', esc_url( self::url( 'cc-fleet-events', array( 'view' => 'month', 'm' => $prev ) ) ), esc_html( wp_date( 'F Y', strtotime( $first . ' 12:00' ) ) ), esc_url( self::url( 'cc-fleet-events', array( 'view' => 'month', 'm' => $next ) ) ) );
		$events = CC_Fleet_Records::query( 'events', array( 'from' => $first, 'to' => $last, 'order' => 'ASC', 'limit' => 200 ) );
		$loans  = CC_Fleet_Loans::query( array( 'status' => array( 'approved', 'out', 'returned' ), 'from' => $first, 'to' => $last, 'limit' => 1000 ) );
		$lead   = (int) wp_date( 'N', strtotime( $first . ' 12:00' ) ) - 1;
		echo '<div class="cc-month"><div class="cc-month__dow">Mon</div><div class="cc-month__dow">Tue</div><div class="cc-month__dow">Wed</div><div class="cc-month__dow">Thu</div><div class="cc-month__dow">Fri</div><div class="cc-month__dow">Sat</div><div class="cc-month__dow">Sun</div>';
		for ( $i = 0; $i < $lead; $i++ ) {
			echo '<div class="cc-month__day is-blank"></div>';
		}
		for ( $d = $first; $d <= $last; $d = CC_Fleet_Loans::add_days( $d, 1 ) ) {
			$out = count( array_filter( $loans, function ( $l ) use ( $d ) { return $l->start_date <= $d && $l->end_date >= $d; } ) );
			printf( '<div class="cc-month__day%s"><span class="cc-month__n">%s</span>%s', $d === $today ? ' is-today' : '', esc_html( wp_date( 'j', strtotime( $d . ' 12:00' ) ) ), $out ? '<span class="cc-month__bikes" title="Bikes on loan">' . (int) $out . ' 🚲</span>' : '' );
			foreach ( $events as $e ) {
				if ( $e->start_date <= $d && CC_Fleet_Records::event_end( $e ) >= $d ) {
					printf( '<a class="cc-month__ev cc-badge--ev-%s" href="%s">%s</a>', esc_attr( CC_Fleet_Records::event_status( $e ) ), esc_url( self::url( 'cc-fleet-record', array( 'kind' => 'events', 'id' => $e->id ) ) ), esc_html( $e->name ) );
				}
			}
			echo '</div>';
		}
		echo '</div>';
	}

	private static function list_notes() {
		$tab    = sanitize_key( $_GET['tab'] ?? 'active' );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		self::tabs( 'cc-fleet-notes', array( 'active' => 'Active', 'archived' => 'Archived', 'all' => 'All' ), $tab );
		self::search_box( 'cc-fleet-notes', $tab, $search );
		$rows = CC_Fleet_Records::query( 'notes', array( 'where' => 'all' === $tab ? array() : array( 'status' => $tab ), 'search' => $search, 'orderby' => 'updated_at', 'limit' => 300 ) );
		if ( ! $rows ) {
			echo '<p class="cc-empty">Nothing here.</p>';
			return;
		}
		echo '<div class="cc-notes">';
		foreach ( $rows as $n ) {
			$toggle = 'active' === $n->status ? 'Archive' : 'Restore';
			printf( '<div class="cc-note"><h3><a href="%s">%s</a></h3><p class="cc-muted">%s · updated %s</p><div class="cc-note__body">%s</div>%s<p><a class="button button-small" href="%s">%s</a></p></div>',
				esc_url( self::url( 'cc-fleet-record', array( 'kind' => 'notes', 'id' => $n->id ) ) ), esc_html( $n->title ), esc_html( $n->category ?: 'General' ), esc_html( self::nice( substr( $n->updated_at, 0, 10 ) ) ),
				nl2br( esc_html( (string) $n->content ) ), $n->image_url ? '<p><a href="' . esc_url( $n->image_url ) . '" target="_blank" rel="noopener">Image</a></p>' : '',
				esc_url( self::action_url( 'note_archive', array( 'id' => $n->id ), 'cc_fleet_note_archive_' . $n->id ) ), esc_html( $toggle ) ); // phpcs:ignore
		}
		echo '</div>';
	}

	/* ---------------- Edit page ---------------- */

	public static function page_edit() {
		$kind = sanitize_key( $_GET['kind'] ?? '' );
		if ( ! isset( CC_Fleet_Records::kinds()[ $kind ] ) ) {
			wp_die( 'Unknown section.' );
		}
		$id   = (int) ( $_GET['id'] ?? 0 );
		$rec  = $id ? CC_Fleet_Records::get( $kind, $id ) : null;
		$name = array( 'maintenance' => 'Workshop job', 'stock' => 'Stock item', 'events' => 'Event', 'notes' => 'Note' )[ $kind ];
		echo '<div class="wrap cc-wrap"><h1>' . esc_html( $rec ? $name . ': ' . ( $rec->name ?? $rec->title ?? ( 'maintenance' === $kind ? CC_Fleet_Bikes::label( $rec->bike_id ) : '' ) ) : 'New ' . strtolower( $name ) ) . '</h1>';
		printf( '<p><a href="%s">← Back to the list</a></p>', esc_url( self::url( self::list_page( $kind ) ) ) );
		self::notices();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cc_fleet_record_save' );
		printf( '<input type="hidden" name="action" value="cc_fleet_record_save"><input type="hidden" name="kind" value="%s"><input type="hidden" name="id" value="%d">', esc_attr( $kind ), $id );
		echo '<table class="form-table"><tbody>';
		foreach ( CC_Fleet_Records::fields( $kind ) as $key => $f ) {
			$v = $rec ? $rec->$key : ( isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : self::default_value( $kind, $key ) );
			echo '<tr><th><label for="f-' . esc_attr( $key ) . '">' . esc_html( $f[0] ) . '</label></th><td>';
			self::field( $key, $f, $v );
			if ( isset( $f[2] ) && is_string( $f[2] ) ) {
				echo '<p class="description">' . esc_html( $f[2] ) . '</p>';
			}
			echo '</td></tr>';
		}
		if ( 'maintenance' === $kind ) {
			echo '<tr><th>Bike</th><td><label><input type="checkbox" name="to_workshop" value="1"' . checked( ! $rec || ( $rec->bike_id && 'workshop' === CC_Fleet_Bikes::get( $rec->bike_id, 'state' ) ), true, false ) . '> Take the bike off the website while the work is due (it comes back when you press Done)</label></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( $rec ? 'Save' : 'Create' );
		echo '</form>';
		if ( $rec && 'events' === $kind ) {
			self::raffle_panel( $rec );
			self::event_loans_panel( $rec );
		}
		if ( $rec && 'stock' === $kind ) {
			self::history( 'stock', $rec->id );
		}
		if ( $rec ) {
			printf( '<p><a class="cc-delete" href="%s" onclick="return confirm(\'Delete permanently? This cannot be undone.\')">Delete permanently</a></p>',
				esc_url( self::action_url( 'record_delete', array( 'kind' => $kind, 'id' => $rec->id ), 'cc_fleet_record_delete_' . $kind . $rec->id ) ) );
		}
		echo '</div>';
	}

	private static function default_value( $kind, $key ) {
		$d = array( 'work_date' => CC_Fleet_Settings::today(), 'status' => 'notes' === $kind ? 'active' : 'scheduled', 'type' => 'events' === $kind ? 'Gravel' : 'service', 'color' => '#D3C8B4', 'quantity' => 0, 'min_qty' => 1 );
		return $d[ $key ] ?? '';
	}

	private static function field( $key, $f, $v ) {
		$id = 'f-' . $key;
		switch ( $f[1] ) {
			case 'bike':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" required><option value="">Choose a bike</option>';
				foreach ( CC_Fleet_Bikes::all_ids() as $b ) {
					printf( '<option value="%d"%s>%s</option>', $b, selected( (int) $v, $b, false ), esc_html( CC_Fleet_Bikes::label( $b ) ) );
				}
				echo '</select>';
				break;
			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '">';
				foreach ( $f[2] as $k => $l ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( (string) $v, (string) $k, false ), esc_html( $l ) );
				}
				echo '</select>';
				break;
			case 'textarea':
				printf( '<textarea id="%s" name="%s" rows="6" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $key ), esc_textarea( (string) $v ) );
				break;
			case 'checkbox':
				printf( '<input type="checkbox" id="%s" name="%s" value="1"%s>', esc_attr( $id ), esc_attr( $key ), checked( (int) $v, 1, false ) );
				break;
			case 'number':
				printf( '<input type="number" id="%s" name="%s" value="%s" step="%s" style="width:120px">', esc_attr( $id ), esc_attr( $key ), esc_attr( null === $v || '' === $v ? '' : (string) ( 'cost' === $key ? $v : (int) $v ) ), 'cost' === $key ? '0.01' : '1' );
				break;
			default:
				printf( '<input type="%s" id="%s" name="%s" value="%s" class="%s">', esc_attr( $f[1] ), esc_attr( $id ), esc_attr( $key ), esc_attr( (string) $v ), 'color' === $f[1] || 'date' === $f[1] ? '' : 'regular-text' );
		}
	}

	/**
	 * Raffle holds: bikes kept back for an organiser's raffle (bike, entry, sometimes kit and nutrition).
	 * They block the calendar like a loan, show as "raffle bike" on the website, and wait for the winner.
	 */
	private static function raffle_panel( $e ) {
		$holds = array_values( array_filter( CC_Fleet_Records::event_loans( $e->id ), function ( $l ) { return 'held' === $l->status; } ) );
		$from  = CC_Fleet_Loans::add_days( $e->start_date, -2 );
		$to    = CC_Fleet_Loans::add_days( $e->end_date ?: $e->start_date, 1 );
		echo '<div class="cc-card cc-raffle"><h2>Raffle bikes</h2>';
		echo '<p class="cc-muted">Keep bikes back for a raffle with the organiser. They stay blocked until you give one to the winner or release it. Riders see them as “raffle bike” in the calendar.</p>';
		if ( $holds ) {
			echo '<table class="widefat striped cc-table"><thead><tr><th>Bike</th><th>Dates</th><th>Winner</th><th></th></tr></thead><tbody>';
			foreach ( $holds as $l ) {
				$r   = $l->rider_id ? CC_Fleet_Riders::get( $l->rider_id ) : null;
				$url = add_query_arg( array( 'page' => 'cc-fleet-loan', 'id' => $l->id ), admin_url( 'admin.php' ) );
				$rel = wp_nonce_url( admin_url( 'admin-post.php?action=cc_fleet_loan_status&id=' . $l->id . '&to=cancelled' ), 'cc_fleet_loan_status_' . $l->id );
				printf( '<tr><td>%s</td><td>%s → %s</td><td>%s</td><td><a class="button button-small" href="%s">%s</a> <a class="button button-small" href="%s" onclick="return confirm(\'Release this bike? It becomes bookable again.\')">Release</a></td></tr>',
					self::bike_link( (int) $l->bike_id ), esc_html( self::nice( $l->start_date ) ), esc_html( self::nice( $l->end_date ) ), esc_html( $r->name ?? 'Not drawn yet' ),
					esc_url( $url ), $r ? 'Give to winner' : 'Add the winner', esc_url( $rel ) );
			}
			echo '</tbody></table>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cc-raffle-form">';
		wp_nonce_field( 'cc_fleet_raffle_hold_' . $e->id );
		printf( '<input type="hidden" name="action" value="cc_fleet_raffle_hold"><input type="hidden" name="event_id" value="%d">', (int) $e->id );
		echo '<fieldset><legend>Hold bikes</legend><p class="cc-raffle-bikes">';
		foreach ( CC_Fleet_Bikes::public_ids() as $b ) {
			$free = CC_Fleet_Loans::is_available( $b, $from, $to );
			printf( '<label><input type="checkbox" name="bikes[]" value="%d"%s> %s%s</label> ', $b, $free ? '' : ' disabled', esc_html( CC_Fleet_Bikes::label( $b ) ), $free ? '' : ' <span class="cc-muted">(booked)</span>' );
		}
		echo '</p></fieldset>';
		printf( '<p><label>From <input type="date" name="from" value="%s" required></label> <label>Until <input type="date" name="to" value="%s" required></label> <button class="button button-primary">Hold for the raffle</button></p>', esc_attr( $from ), esc_attr( $to ) );
		echo '</form></div>';
	}

	public static function handle_raffle_hold() {
		$eid = (int) ( $_POST['event_id'] ?? 0 );
		self::guard( 'cc_fleet_raffle_hold_' . $eid );
		$e    = CC_Fleet_Records::get( 'events', $eid );
		$back = add_query_arg( array( 'page' => 'cc-fleet-record', 'kind' => 'events', 'id' => $eid ), admin_url( 'admin.php' ) );
		$from = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
		$to   = sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) );
		if ( ! $e ) {
			self::back( $back, 'Unknown event.', 'error' );
		}
		$made = 0;
		$fail = array();
		foreach ( array_map( 'intval', (array) ( $_POST['bikes'] ?? array() ) ) as $b ) {
			$res = CC_Fleet_Loans::create( array( 'bike_id' => $b, 'rider_id' => 0, 'start_date' => $from, 'end_date' => $to, 'status' => 'held', 'source' => 'raffle', 'event_id' => $eid, 'race' => 1, 'plan' => 'Raffle: ' . $e->name ) );
			if ( is_wp_error( $res ) ) {
				$fail[] = CC_Fleet_Bikes::get( $b, 'cc_id' );
			} else {
				$made++;
			}
		}
		self::back( $back, $made ? sprintf( '%d bike%s held for the raffle.', $made, 1 === $made ? '' : 's' ) . ( $fail ? ' Not held (booked): ' . esc_html( implode( ', ', $fail ) ) . '.' : '' ) : 'No bikes held. Tick at least one free bike.', $made ? 'success' : 'error' );
	}

	private static function event_loans_panel( $e ) {
		$loans = CC_Fleet_Records::event_loans( $e->id );
		echo '<h2>Bikes at this event</h2>';
		printf( '<p><a class="button" href="%s">+ Loan for this event</a> <a class="button" href="%s">Find loans that mention it</a></p>',
			esc_url( add_query_arg( array( 'page' => 'cc-fleet-loan', 'event_id' => $e->id, 'start' => CC_Fleet_Loans::add_days( $e->start_date, -2 ) ), admin_url( 'admin.php' ) ) ),
			esc_url( self::action_url( 'events_link', array( 'id' => $e->id ), 'cc_fleet_events_link' ) ) );
		if ( ! $loans ) {
			echo '<p class="cc-empty">No loans linked yet.</p>';
			return;
		}
		echo '<table class="widefat striped cc-table"><thead><tr><th>Bike</th><th>Rider</th><th>Dates</th><th>Status</th></tr></thead><tbody>';
		foreach ( $loans as $l ) {
			$r = CC_Fleet_Riders::get( $l->rider_id );
			printf( '<tr><td>%s</td><td>%s</td><td><a href="%s">%s → %s</a></td><td>%s</td></tr>', self::bike_link( (int) $l->bike_id ), esc_html( $r->name ?? '?' ),
				esc_url( add_query_arg( array( 'page' => 'cc-fleet-loan', 'id' => $l->id ), admin_url( 'admin.php' ) ) ), esc_html( $l->start_date ), esc_html( $l->end_date ), CC_Fleet_Loans::status_badge( $l ) ); // phpcs:ignore
		}
		echo '</tbody></table>';
	}

	private static function history( $type, $id ) {
		global $wpdb;
		$log = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CC_Fleet_Install::table( 'log' ) . ' WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 30', $type, $id ) );
		if ( ! $log ) {
			return;
		}
		echo '<h2>History</h2><ul class="cc-log">';
		foreach ( $log as $e ) {
			$u = $e->user_id ? get_userdata( $e->user_id ) : null;
			printf( '<li><code>%s</code> %s %s <span class="cc-muted">%s</span></li>', esc_html( $e->created_at ), esc_html( $e->action ), esc_html( (string) $e->message ), esc_html( $u ? $u->display_name : '—' ) );
		}
		echo '</ul>';
	}

	/* ---------------- Handlers ---------------- */

	public static function handle_record_save() {
		self::guard( 'cc_fleet_record_save' );
		$kind = sanitize_key( $_POST['kind'] ?? '' );
		if ( ! isset( CC_Fleet_Records::kinds()[ $kind ] ) ) {
			wp_die( 'Unknown section.' );
		}
		$id  = (int) ( $_POST['id'] ?? 0 );
		$res = CC_Fleet_Records::save( $kind, $_POST, $id ); // phpcs:ignore -- sanitised per field in save().
		if ( is_wp_error( $res ) ) {
			self::back( wp_get_referer() ?: self::url( self::list_page( $kind ) ), esc_html( $res->get_error_message() ), 'error' );
		}
		if ( 'maintenance' === $kind ) {
			$m = CC_Fleet_Records::get( 'maintenance', $res );
			if ( ! empty( $_POST['to_workshop'] ) ) {
				CC_Fleet_Records::sync_bike_state( $res );
			} elseif ( $m && 'workshop' === CC_Fleet_Bikes::get( $m->bike_id, 'state' ) ) {
				CC_Fleet_Bikes::set( $m->bike_id, 'state', 'active' );
			}
		}
		self::back( self::url( 'cc-fleet-record', array( 'kind' => $kind, 'id' => $res ) ), 'Saved.' );
	}

	public static function handle_record_delete() {
		$kind = sanitize_key( $_GET['kind'] ?? '' );
		$id   = (int) ( $_GET['id'] ?? 0 );
		self::guard( 'cc_fleet_record_delete_' . $kind . $id );
		if ( isset( CC_Fleet_Records::kinds()[ $kind ] ) ) {
			CC_Fleet_Records::delete( $kind, $id );
		}
		self::back( self::url( self::list_page( $kind ) ), 'Deleted.' );
	}

	public static function handle_stock_adjust() {
		$id = (int) ( $_GET['id'] ?? 0 );
		self::guard( 'cc_fleet_stock_adjust_' . $id );
		$delta = (int) ( $_GET['delta'] ?? 0 ) > 0 ? 1 : -1;
		CC_Fleet_Records::adjust_stock( $id, $delta );
		$item = CC_Fleet_Records::get( 'stock', $id );
		self::back( wp_get_referer() ?: self::url( 'cc-fleet-stock' ), esc_html( sprintf( '%s: now %d.', $item->name ?? 'Item', $item->quantity ?? 0 ) ) );
	}

	public static function handle_maint_done() {
		$id = (int) ( $_GET['id'] ?? 0 );
		self::guard( 'cc_fleet_maint_done_' . $id );
		CC_Fleet_Records::save( 'maintenance', array( 'status' => 'completed' ), $id );
		CC_Fleet_Records::sync_bike_state( $id );
		self::back( wp_get_referer() ?: self::url( 'cc-fleet-workshop' ), 'Marked as done. If the bike was in the workshop, it’s back on the website.' );
	}

	public static function handle_note_archive() {
		$id = (int) ( $_GET['id'] ?? 0 );
		self::guard( 'cc_fleet_note_archive_' . $id );
		$n = CC_Fleet_Records::get( 'notes', $id );
		if ( $n ) {
			CC_Fleet_Records::save( 'notes', array( 'status' => 'active' === $n->status ? 'archived' : 'active' ), $id );
		}
		self::back( wp_get_referer() ?: self::url( 'cc-fleet-notes' ), 'Updated.' );
	}

	public static function handle_events_link() {
		self::guard( 'cc_fleet_events_link' );
		$n = CC_Fleet_Records::auto_link_events();
		self::back( wp_get_referer() ?: self::url( 'cc-fleet-events' ), sprintf( '%d loan(s) linked to events from their plans.', $n ) );
	}

	public static function handle_record_import() {
		self::guard( 'cc_fleet_import' );
		$kind = sanitize_key( $_POST['kind'] ?? '' );
		$file = ! empty( $_FILES['csv']['tmp_name'] ) && is_uploaded_file( $_FILES['csv']['tmp_name'] ) ? $_FILES['csv']['tmp_name'] : ''; // phpcs:ignore
		if ( ! isset( CC_Fleet_Records::kinds()[ $kind ] ) || ! $file ) {
			self::back( add_query_arg( 'page', 'cc-fleet-import', admin_url( 'admin.php' ) ), 'Choose what to import and a CSV file.', 'error' );
		}
		$rows = CC_Fleet_Import::read_rows( $file );
		$res  = is_wp_error( $rows ) ? array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array( $rows->get_error_message() ) ) : CC_Fleet_Records::import_csv( $kind, $rows );
		$msg  = sprintf( '%s: %d added, %d already there.', CC_Fleet_Records::kinds()[ $kind ], $res['created'], $res['skipped'] ) . ( isset( $res['linked'] ) ? sprintf( ' %d loans linked to events.', $res['linked'] ) : '' );
		if ( $res['errors'] ) {
			$msg .= '<br>' . implode( '<br>', array_map( 'esc_html', array_slice( $res['errors'], 0, 20 ) ) );
		}
		self::back( add_query_arg( 'page', 'cc-fleet-import', admin_url( 'admin.php' ) ), $msg, $res['errors'] ? 'warning' : 'success' );
	}

	/* ---------------- Import card (on the Import page) ---------------- */

	public static function import_card() {
		echo '<form class="cc-card" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cc_fleet_import' );
		echo '<input type="hidden" name="action" value="cc_fleet_record_import"><h2>Workshop, stock, events, notes</h2>';
		echo '<p>One CSV per section, with the field names as columns (e.g. stock: <code>name, category, quantity, min_qty, color</code>; events: <code>name, type, start_date, end_date, location, description, image_url, link</code>; workshop: <code>bike_id (CC-12), type, status, work_date, cost, technician, description</code>; notes: <code>title, category, content, status</code>). Rows already there are skipped. Importing events also links loans that mention them.</p>';
		echo '<p><select name="kind" required><option value="">What is in the file?</option>';
		foreach ( CC_Fleet_Records::kinds() as $k => $l ) {
			printf( '<option value="%s">%s</option>', esc_attr( $k ), esc_html( $l ) );
		}
		echo '</select></p><p><input type="file" name="csv" accept=".csv,text/csv" required></p>';
		submit_button( 'Import', 'secondary', 'submit', false );
		echo '</form>';
	}

	/* ---------------- Dashboard ---------------- */

	public static function dashboard_panels() {
		$today = CC_Fleet_Settings::today();
		$year  = (int) ( $_GET['year'] ?? wp_date( 'Y' ) );
		// Bookings per month (loans that start in the month; cancelled/declined left out).
		$counts = array_fill( 1, 12, 0 );
		foreach ( CC_Fleet_Loans::query( array( 'status' => array( 'approved', 'out', 'returned' ), 'from' => "$year-01-01", 'to' => "$year-12-31", 'limit' => 2000 ) ) as $l ) {
			if ( substr( $l->start_date, 0, 4 ) === (string) $year ) {
				$counts[ (int) substr( $l->start_date, 5, 2 ) ]++;
			}
		}
		$max = max( 1, max( $counts ) );
		echo '<div class="cc-dash">';
		printf( '<section class="cc-card"><h2>Loans per month · %d <small><a href="%s">‹</a> <a href="%s">›</a></small></h2><div class="cc-bars">', $year, esc_url( add_query_arg( 'year', $year - 1 ) ), esc_url( add_query_arg( 'year', $year + 1 ) ) );
		foreach ( $counts as $mo => $n ) {
			printf( '<div class="cc-bar" title="%d loans"><span style="height:%d%%"></span><b>%d</b><i>%s</i></div>', $n, (int) round( 100 * $n / $max ), $n, esc_html( wp_date( 'M', mktime( 12, 0, 0, $mo, 1, $year ) ) ) );
		}
		echo '</div></section>';

		$events = array_slice( array_values( array_filter( CC_Fleet_Records::query( 'events', array( 'from' => $today, 'order' => 'ASC', 'limit' => 20 ) ), function ( $e ) { return ! (int) $e->cancelled; } ) ), 0, 5 );
		echo '<section class="cc-card"><h2>Next events</h2>';
		if ( $events ) {
			echo '<ul class="cc-list">';
			foreach ( $events as $e ) {
				printf( '<li><a href="%s">%s</a> <span class="cc-muted">%s · %s · %d bikes</span></li>', esc_url( self::url( 'cc-fleet-record', array( 'kind' => 'events', 'id' => $e->id ) ) ), esc_html( $e->name ), esc_html( self::nice( $e->start_date ) ), esc_html( $e->location ), CC_Fleet_Records::bikes_at( $e ) );
			}
			echo '</ul>';
		} else {
			echo '<p class="cc-empty">No events coming up. <a href="' . esc_url( self::url( 'cc-fleet-record', array( 'kind' => 'events' ) ) ) . '">Add one</a></p>';
		}
		echo '</section>';

		$work = CC_Fleet_Records::query( 'maintenance', array( 'where' => array( 'status' => 'scheduled' ), 'order' => 'ASC', 'limit' => 6 ) );
		echo '<section class="cc-card"><h2>Workshop</h2>';
		if ( $work ) {
			echo '<ul class="cc-list">';
			foreach ( $work as $m ) {
				printf( '<li>%s <span class="cc-muted">%s · %s</span> %s</li>', self::bike_link( (int) $m->bike_id ), esc_html( CC_Fleet_Records::MAINT_TYPES[ $m->type ] ?? $m->type ), esc_html( self::nice( $m->work_date ) ), esc_html( wp_trim_words( (string) $m->description, 10 ) ) ); // phpcs:ignore
			}
			echo '</ul>';
		} else {
			echo '<p class="cc-empty">Nothing scheduled.</p>';
		}
		echo '</section>';

		$low = CC_Fleet_Records::low_stock();
		echo '<section class="cc-card"><h2>Stock running low</h2>';
		if ( $low ) {
			echo '<ul class="cc-list">';
			foreach ( $low as $s ) {
				printf( '<li><a href="%s">%s</a> <span class="cc-muted">%d left (min %d)</span></li>', esc_url( self::url( 'cc-fleet-record', array( 'kind' => 'stock', 'id' => $s->id ) ) ), esc_html( $s->name ), (int) $s->quantity, (int) $s->min_qty );
			}
			echo '</ul>';
		} else {
			echo '<p class="cc-empty">All good.</p>';
		}
		echo '</section>';
		if ( class_exists( 'CC_Fleet_Care' ) ) {
			CC_Fleet_Care::dashboard_card();
			CC_Fleet_Parts::dashboard_card();
		}
		if ( class_exists( 'CC_Fleet_Expenses' ) ) {
			CC_Fleet_Expenses::dashboard_card();
		}
		echo '</div>';
	}

	/** One row above the bikes on the fleet calendar. */
	public static function calendar_events_row( $start, $days ) {
		$end    = CC_Fleet_Loans::add_days( $start, $days - 1 );
		$events = CC_Fleet_Records::query( 'events', array( 'from' => $start, 'to' => $end, 'order' => 'ASC', 'limit' => 100 ) );
		$html   = '<tr class="cc-tl-events"><th class="cc-tl-bike">Events</th>';
		$i      = 0;
		while ( $i < $days ) {
			$d   = CC_Fleet_Loans::add_days( $start, $i );
			$hit = null;
			foreach ( $events as $e ) {
				if ( $e->start_date <= $d && CC_Fleet_Records::event_end( $e ) >= $d ) {
					$hit = $e;
					break;
				}
			}
			if ( $hit ) {
				// Run until the event ends or another one starts, whichever comes first.
				$stop = CC_Fleet_Records::event_end( $hit );
				$also = array();
				foreach ( $events as $o ) {
					if ( $o->id === $hit->id ) {
						continue;
					}
					if ( $o->start_date > $d && $o->start_date <= $stop ) {
						$stop = CC_Fleet_Loans::add_days( $o->start_date, -1 );
					}
					if ( $o->start_date <= $d && CC_Fleet_Records::event_end( $o ) >= $d ) {
						$also[] = $o->name;
					}
				}
				$span  = min( $days - $i, CC_Fleet_Loans::days_between( $d, $stop ) + 1 );
				$label = $hit->name . ( $also ? ' + ' . implode( ' + ', $also ) : '' );
				$html .= sprintf( '<td colspan="%d" class="cc-tl-ev cc-badge--ev-%s"><a href="%s" title="%s">%s</a></td>', $span, esc_attr( CC_Fleet_Records::event_status( $hit ) ), esc_url( self::url( 'cc-fleet-record', array( 'kind' => 'events', 'id' => $hit->id ) ) ), esc_attr( $label . ' · ' . $hit->location ), esc_html( $label ) );
				$i    += $span;
			} else {
				$html .= '<td></td>';
				$i++;
			}
		}
		return $html . '</tr>';
	}
}
