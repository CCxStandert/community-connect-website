<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fleet Manager admin: Dashboard, Calendar, Loans, Bikes (post type), Riders, Import, Settings.
 * Workshop, Stock, Events and Notes live in CC_Fleet_Records_Admin.
 * Every write goes through admin-post.php with a nonce and the manage_cc_fleet capability.
 */
class CC_Fleet_Admin {

	const CAP = 'manage_cc_fleet';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		// Edit screens stay registered (so WordPress allows them) but out of the menu.
		add_action( 'admin_head', function () {
			echo '<style>#adminmenu a[href$="page=cc-fleet-loan"],#adminmenu a[href$="page=cc-fleet-record"]{display:none}</style>';
		} );
		foreach ( array( 'loan_save', 'loan_status', 'loan_delete', 'loan_dates', 'rider_save', 'import', 'settings_save', 'run_daily', 'email_preview', 'loan_handoff' ) as $a ) {
			add_action( 'admin_post_cc_fleet_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	public static function menu() {
		add_menu_page( 'Fleet Manager', 'Fleet Manager', self::CAP, 'cc-fleet', array( __CLASS__, 'page_dashboard' ), 'dashicons-admin-site-alt3', 3 );
		add_submenu_page( 'cc-fleet', 'Dashboard', 'Dashboard', self::CAP, 'cc-fleet', array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( 'cc-fleet', 'Calendar', 'Calendar', self::CAP, 'cc-fleet-calendar', array( __CLASS__, 'page_calendar' ) );
		$pending = CC_Fleet_Loans::count( array( 'status' => 'requested' ) );
		add_submenu_page( 'cc-fleet', 'Loans', 'Loans' . ( $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '' ), self::CAP, 'cc-fleet-loans', array( __CLASS__, 'page_loans' ) );
		add_submenu_page( 'cc-fleet', 'Loan', '', self::CAP, 'cc-fleet-loan', array( __CLASS__, 'page_loan' ) );
		add_submenu_page( 'cc-fleet', 'Riders', 'Riders', self::CAP, 'cc-fleet-riders', array( __CLASS__, 'page_riders' ) );
		add_submenu_page( 'cc-fleet', 'Import', 'Import', self::CAP, 'cc-fleet-import', array( __CLASS__, 'page_import' ) );
		add_submenu_page( 'cc-fleet', 'Settings', 'Settings', 'manage_options', 'cc-fleet-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( false !== strpos( $hook, 'cc-fleet' ) || ( $screen && CC_Fleet_Bikes::CPT === $screen->post_type ) ) {
			wp_enqueue_style( 'cc-fleet-admin', CC_FLEET_URL . 'assets/admin.css', array(), CC_FLEET_VERSION );
		}
	}

	private static function guard( $nonce_action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( $nonce_action );
	}

	private static function back( $url, $notice, $type = 'success' ) {
		// An email was kept for review: go to it first, then back to where we were.
		if ( 'error' !== $type && class_exists( 'CC_Fleet_Outbox' ) && CC_Fleet_Emails::$held ) {
			$notice = preg_replace( '/ ?(and )?[Tt]he rider was emailed\.?/', '', $notice );
			$notice = rtrim( $notice, '. ' ) . '. Now check the email to the rider and send it.';
			set_transient( 'cc_fleet_notice_' . get_current_user_id(), array( $notice, 'info' ), 60 );
			wp_safe_redirect( CC_Fleet_Outbox::compose_url( CC_Fleet_Emails::$held, $url ) );
			exit;
		}
		set_transient( 'cc_fleet_notice_' . get_current_user_id(), array( $notice, $type ), 60 );
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

	private static function url( $page, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private static function status_buttons( $loan ) {
		$labels = array( 'approved' => 'Approve', 'declined' => 'Decline', 'out' => 'Handed over', 'returned' => 'Returned', 'cancelled' => 'Cancel', 'requested' => 'Back to request', 'waiting' => 'Waiting list' );
		if ( in_array( $loan->status, array( 'waiting', 'offered' ), true ) ) {
			return CC_Fleet_Waitlist::buttons( $loan ) . ( 'waiting' === $loan->status ? ' <a class="button-link" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cc_fleet_loan_status&id=' . $loan->id . '&to=requested' ), 'cc_fleet_loan_status_' . $loan->id ) ) . '">Back to request</a>' : '' );
		}
		$html   = '';
		// "Any bike" request: pick a free bike and approve in one step.
		if ( 'requested' === $loan->status && ! $loan->bike_id ) {
			$fits  = CC_Fleet_Loans::available_bikes( $loan->start_date, $loan->end_date, $loan->requested_size );
			$other = array_diff( CC_Fleet_Loans::available_bikes( $loan->start_date, $loan->end_date ), $fits );
			$html .= '<form class="cc-assign" method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="cc_fleet_loan_status"><input type="hidden" name="id" value="' . (int) $loan->id . '"><input type="hidden" name="to" value="approved">'
				. wp_nonce_field( 'cc_fleet_loan_status_' . $loan->id, '_wpnonce', true, false );
			if ( $fits || $other ) {
				$html .= '<select name="bike_id" aria-label="Bike to give">';
				foreach ( array( 'Free in size ' . $loan->requested_size => $fits, 'Other free bikes' => $other ) as $group => $ids ) {
					if ( $ids ) {
						$html .= '<optgroup label="' . esc_attr( $group ) . '">';
						foreach ( $ids as $b ) {
							$html .= '<option value="' . (int) $b . '">' . esc_html( CC_Fleet_Bikes::label( $b ) ) . '</option>';
						}
						$html .= '</optgroup>';
					}
				}
				$html .= '</select> <button class="button button-primary">Approve with this bike</button>';
			} else {
				$html .= '<span class="cc-warn">No bike is free for these dates.</span>';
			}
			$html .= '</form> ';
		}
		if ( 'held' === $loan->status ) {
			$labels['approved']  = $loan->rider_id ? 'Give it to the winner' : '';
			$labels['cancelled'] = 'Release the bike';
		}
		foreach ( CC_Fleet_Loans::TRANSITIONS[ $loan->status ] ?? array() as $to ) {
			if ( isset( $labels[ $to ] ) && '' === $labels[ $to ] ) {
				continue; // Raffle hold without a winner yet: pick the rider below first.
			}
			if ( 'approved' === $to && ! $loan->bike_id ) {
				continue; // Handled by the picker above.
			}
			if ( 'waiting' === $to && ( $loan->bike_id ? CC_Fleet_Loans::is_available( $loan->bike_id, $loan->start_date, $loan->end_date, $loan->id ) : CC_Fleet_Loans::available_bikes( $loan->start_date, $loan->end_date, $loan->requested_size ) ) ) {
				continue; // The waiting list is for bikes that are taken.
			}
			$url   = wp_nonce_url( admin_url( 'admin-post.php?action=cc_fleet_loan_status&id=' . $loan->id . '&to=' . $to ), 'cc_fleet_loan_status_' . $loan->id );
			$class = in_array( $to, array( 'approved', 'out', 'returned' ), true ) ? 'button button-primary' : 'button';
			$html .= sprintf( '<a class="%s" href="%s">%s</a> ', $class, esc_url( $url ), esc_html( $labels[ $to ] ?? $to ) );
		}
		return $html;
	}

	/**
	 * Rider dropdown with a filter box and "+ New rider" (fields appear when chosen).
	 * Used on the loan form; new riders are created on save.
	 */
	public static function rider_picker( $name, $current = 0, $allow_new = true ) {
		$opts = CC_Fleet_Riders::options();
		$id   = 'cc-rider';
		$html = '<div class="cc-rider-picker"><input type="search" class="cc-rider-filter" placeholder="Type to filter riders…" aria-label="Filter riders" data-for="' . $id . '"> ';
		$html .= '<select id="' . $id . '" name="' . esc_attr( $name ) . '" required><option value="">— Choose a rider —</option>';
		if ( $allow_new ) {
			$html .= '<option value="new"' . ( ! $current && ! $opts ? ' selected' : '' ) . '>+ New rider…</option>';
		}
		foreach ( $opts as $rid => $label ) {
			$html .= sprintf( '<option value="%d"%s>%s</option>', $rid, selected( (int) $current, $rid, false ), esc_html( $label ) );
		}
		$html .= '</select></div>';
		if ( $allow_new ) {
			$html .= '<fieldset class="cc-new-rider" hidden><legend>New rider</legend>'
				. '<p><input type="text" name="new_rider[name]" placeholder="Full name" class="regular-text"></p>'
				. '<p class="cc-phone-row">' . CC_Fleet_Phone::select( 'new_rider[phone_cc]', '27' ) . ' <input type="tel" name="new_rider[phone]" placeholder="WhatsApp number, e.g. 82 123 4567"></p>'
				. '<p><input type="email" name="new_rider[email]" placeholder="Email (optional here)" class="regular-text"> <input type="text" name="new_rider[instagram]" placeholder="Instagram"> <input type="number" name="new_rider[height_cm]" placeholder="Height cm" style="width:110px"></p></fieldset>';
		}
		$html .= '<script>(function(){var s=document.getElementById("' . $id . '");if(!s)return;var f=document.querySelector(".cc-rider-filter[data-for=' . $id . ']"),n=document.querySelector(".cc-new-rider");'
			. 'function t(){if(n){n.hidden=s.value!=="new";var i=n.querySelector("input[name=\'new_rider[name]\']");if(i)i.required=s.value==="new";}}s.addEventListener("change",t);t();'
			. 'if(f)f.addEventListener("input",function(){var q=f.value.toLowerCase();Array.prototype.forEach.call(s.options,function(o){if(o.value&&o.value!=="new")o.hidden=q&&o.text.toLowerCase().indexOf(q)<0;});});})();</script>';
		return $html;
	}

	private static function rider_cell( $loan ) {
		$r = CC_Fleet_Riders::get( $loan->rider_id );
		if ( ! $r ) {
			return '—';
		}
		$bits = array_filter( array( $r->email ? '<a href="mailto:' . esc_attr( $r->email ) . '">' . esc_html( $r->email ) . '</a>' : '', $r->phone ? esc_html( $r->phone ) : '', $r->instagram ? '@' . esc_html( $r->instagram ) : '' ) );
		return '<strong>' . esc_html( $r->name ) . '</strong>' . ( $r->height_cm ? ' · ' . (int) $r->height_cm . ' cm' : '' ) . '<br><small>' . implode( ' · ', $bits ) . '</small>';
	}

	private static function bike_cell( $loan ) {
		if ( ! $loan->bike_id ) {
			return '<em>Any bike · size ' . esc_html( $loan->requested_size ) . '</em>';
		}
		return '<a href="' . esc_url( get_edit_post_link( $loan->bike_id ) ) . '">' . esc_html( CC_Fleet_Bikes::label( $loan->bike_id ) ) . '</a>';
	}

	private static function loan_rows( array $loans, $actions = true ) {
		if ( ! $loans ) {
			echo '<p class="cc-empty">Nothing here.</p>';
			return;
		}
		echo '<table class="widefat striped cc-table"><thead><tr><th>Bike</th><th>Rider</th><th>Dates</th><th>Status</th><th>Plan</th>' . ( $actions ? '<th></th>' : '' ) . '</tr></thead><tbody>';
		foreach ( $loans as $l ) {
			$days  = CC_Fleet_Loans::days_between( $l->start_date, $l->end_date ) + 1;
			$clash = ( 'requested' === $l->status && $l->bike_id ) ? CC_Fleet_Loans::conflicts( $l->bike_id, $l->start_date, $l->end_date, $l->id ) : array();
			printf( '<tr><td>%s</td><td>%s</td><td><a href="%s">%s → %s</a><br><small>%d day%s</small></td><td>%s%s</td><td>%s</td>%s</tr>',
				self::bike_cell( $l ), self::rider_cell( $l ),
				esc_url( self::url( 'cc-fleet-loan', array( 'id' => $l->id ) ) ), esc_html( $l->start_date ), esc_html( $l->end_date ), $days, 1 === $days ? '' : 's',
				CC_Fleet_Loans::status_badge( $l ), $clash ? '<br><span class="cc-warn">Clashes with an approved loan</span>' : '',
				( CC_Fleet_Loans::pedals_label( $l ) ? '<b>' . esc_html( CC_Fleet_Loans::pedals_label( $l ) ) . '</b> · ' : '' ) . esc_html( wp_trim_words( (string) $l->plan, 12 ) ),
				$actions ? '<td class="cc-actions">' . self::status_buttons( $l ) . '</td>' : '' );
		}
		echo '</tbody></table>';
	}

	/* ================= Dashboard ================= */

	public static function page_dashboard() {
		$today    = CC_Fleet_Settings::today();
		$bikes    = CC_Fleet_Bikes::public_ids();
		$states   = array( 'available' => 0, 'out' => 0, 'workshop' => 0 );
		foreach ( $bikes as $id ) {
			$s = CC_Fleet_Bikes::state_now( $id );
			if ( isset( $states[ $s ] ) ) {
				$states[ $s ]++;
			}
		}
		$pending  = CC_Fleet_Loans::query( array( 'status' => 'requested', 'order' => 'ASC', 'orderby' => 'created_at', 'limit' => 50 ) );
		$overdue  = CC_Fleet_Loans::query( array( 'overdue' => true, 'limit' => 50 ) );
		$upcoming = CC_Fleet_Loans::query( array( 'status' => 'approved', 'from' => $today, 'to' => CC_Fleet_Loans::add_days( $today, 14 ), 'order' => 'ASC', 'limit' => 50 ) );
		$out      = CC_Fleet_Loans::query( array( 'status' => 'out', 'order' => 'ASC', 'orderby' => 'end_date', 'limit' => 50 ) );
		$year     = CC_Fleet_Loans::count( array( 'status' => array( 'approved', 'out', 'returned' ), 'from' => wp_date( 'Y' ) . '-01-01', 'to' => wp_date( 'Y' ) . '-12-31' ) );
		echo '<div class="wrap cc-wrap"><h1 class="wp-heading-inline">Fleet Manager</h1> <a class="page-title-action" href="' . esc_url( self::url( 'cc-fleet-loan' ) ) . '">+ New loan</a>';
		self::notices();
		echo '<div class="cc-kpis">';
		foreach ( array(
			array( 'On the website', count( $bikes ) - count( array_filter( $bikes, function ( $id ) { return 'soon' === CC_Fleet_Bikes::get( $id, 'state' ); } ) ) ), array( 'Available now', $states['available'] ), array( 'Out now', $states['out'] ),
			array( 'In the workshop', $states['workshop'] ), array( 'To review', count( $pending ), $pending ? 'pink' : '' ),
			array( 'Overdue', count( $overdue ), $overdue ? 'pink' : '' ), array( 'Waiting list', CC_Fleet_Loans::count( array( 'status' => array( 'waiting', 'offered' ) ) ) ), array( 'Loans in ' . wp_date( 'Y' ), $year ), array( 'Riders', CC_Fleet_Riders::count() ),
		) as $k ) {
			printf( '<div class="cc-kpi %s"><span>%s</span><strong>%s</strong></div>', esc_attr( $k[2] ?? '' ), esc_html( $k[0] ), esc_html( (string) $k[1] ) );
		}
		echo '</div>';
		echo '<h2>Requests to review</h2>';
		self::loan_rows( $pending );
		CC_Fleet_Waitlist::dashboard_panel();
		if ( class_exists( 'CC_Fleet_Outbox' ) ) {
			CC_Fleet_Outbox::dashboard_panel();
		}
		if ( class_exists( 'CC_Fleet_Records_Admin' ) ) {
			CC_Fleet_Records_Admin::dashboard_panels();
		}
		self::due_back_panel( $out, $today );
		CC_Fleet_Handoff::dashboard_panel();
		echo '<h2>Overdue</h2>';
		self::loan_rows( $overdue );
		echo '<h2>Handoffs in the next 14 days</h2>';
		self::loan_rows( $upcoming );
		echo '<h2>Out now</h2>';
		self::loan_rows( $out );
		printf( '<p class="cc-muted">Calendar feed for your phone: <code>%s</code> · <a href="%s">Run daily jobs now</a></p>',
			esc_html( CC_Fleet_Ical::url() ), esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cc_fleet_run_daily' ), 'cc_fleet_run_daily' ) ) );
		echo '</div>';
	}

	/** Bikes due back today, tomorrow or the day after, with whether the rider has been reminded. */
	private static function due_back_panel( array $out, $today ) {
		$soon = array_values( array_filter( $out, function ( $l ) use ( $today ) {
			return $l->end_date >= $today && $l->end_date <= CC_Fleet_Loans::add_days( $today, 2 );
		} ) );
		if ( ! $soon ) {
			return;
		}
		echo '<h2>Due back soon</h2><table class="widefat striped cc-due"><tbody>';
		foreach ( $soon as $l ) {
			$r    = CC_Fleet_Riders::get( $l->rider_id );
			$gap  = (int) round( ( strtotime( $l->end_date ) - strtotime( $today ) ) / DAY_IN_SECONDS );
			$when = 0 === $gap ? 'today' : ( 1 === $gap ? 'tomorrow' : CC_Fleet_Emails::nice_date( $l->end_date ) );
			$wa   = $r && $r->phone ? ' · <a href="' . esc_url( CC_Fleet_Phone::wa_link( $r->phone ) ) . '" target="_blank" rel="noopener">WhatsApp</a>' : '';
			$rem  = $l->reminder_sent ? '<span class="cc-ok">Reminder sent</span>' : '<a class="button button-primary button-small" href="' . esc_url( CC_Fleet_Outbox::prepare_url( $l->id ) ) . '">Send the reminder now</a>' . ( $gap > 0 ? ' <span class="cc-muted">or it goes out tomorrow at 07:00</span>' : '' );
			printf( '<tr><td><a href="%s"><strong>%s</strong></a>%s</td><td>%s</td><td>Due back <strong>%s</strong></td><td>%s</td></tr>',
				esc_url( self::url( 'cc-fleet-loan', array( 'id' => $l->id ) ) ), esc_html( $r->name ?? 'Rider' ), $wa, // phpcs:ignore
				esc_html( CC_Fleet_Bikes::label( $l->bike_id ) ), esc_html( $when ), $rem ); // phpcs:ignore
		}
		echo '</tbody></table>';
	}

	/* ================= Calendar (fleet timeline) ================= */

	public static function page_calendar() {
		$start = isset( $_GET['start'] ) && CC_Fleet_Loans::valid_date( sanitize_text_field( wp_unslash( $_GET['start'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['start'] ) ) : CC_Fleet_Loans::add_days( CC_Fleet_Settings::today(), -3 );
		$days  = 42;
		$end   = CC_Fleet_Loans::add_days( $start, $days - 1 );
		$bikes = CC_Fleet_Bikes::all_ids();
		$today = CC_Fleet_Settings::today();
		echo '<div class="wrap cc-wrap"><h1>Fleet calendar</h1>';
		printf( '<p><a class="button" href="%s">‹ 4 weeks</a> <a class="button" href="%s">Today</a> <a class="button" href="%s">4 weeks ›</a> <span class="cc-muted">Click an empty day to start a loan there.</span></p>',
			esc_url( self::url( 'cc-fleet-calendar', array( 'start' => CC_Fleet_Loans::add_days( $start, -28 ) ) ) ),
			esc_url( self::url( 'cc-fleet-calendar' ) ),
			esc_url( self::url( 'cc-fleet-calendar', array( 'start' => CC_Fleet_Loans::add_days( $start, 28 ) ) ) ) );
		echo '<div class="cc-timeline-wrap"><table class="cc-timeline"><thead><tr><th class="cc-tl-bike">Bike</th>';
		for ( $i = 0; $i < $days; $i++ ) {
			$d   = CC_Fleet_Loans::add_days( $start, $i );
			$dow = wp_date( 'D', strtotime( $d . ' 12:00' ) );
			printf( '<th class="%s"><span>%s</span>%s</th>', $d === $today ? 'is-today' : ( in_array( $dow, array( 'Sat', 'Sun' ), true ) ? 'is-weekend' : '' ), esc_html( substr( $dow, 0, 2 ) ), esc_html( wp_date( 'j', strtotime( $d . ' 12:00' ) ) ) );
		}
		echo '</tr></thead><tbody>';
		if ( class_exists( 'CC_Fleet_Records_Admin' ) ) {
			echo CC_Fleet_Records_Admin::calendar_events_row( $start, $days ); // phpcs:ignore -- escaped inside.
		}
		foreach ( $bikes as $bike_id ) {
			$loans = CC_Fleet_Loans::query( array( 'bike_id' => $bike_id, 'status' => array( 'requested', 'approved', 'out', 'returned', 'held' ), 'from' => $start, 'to' => $end, 'order' => 'ASC', 'limit' => 200 ) );
			$state = CC_Fleet_Bikes::get( $bike_id, 'state' );
			printf( '<tr class="%s"><th class="cc-tl-bike"><a href="%s">%s</a>%s</th>', 'active' === $state ? '' : 'is-inactive', esc_url( get_edit_post_link( $bike_id ) ), esc_html( CC_Fleet_Bikes::label( $bike_id ) ),
				'1' === CC_Fleet_Bikes::get( $bike_id, 'show_on_site' ) ? '' : ' <small>(backend only)</small>' );
			$asg = CC_Fleet_Bikes::assignment( $bike_id );
			$i   = 0;
			while ( $i < $days ) {
				$d    = CC_Fleet_Loans::add_days( $start, $i );
				$hit  = null;
				if ( $asg && $d >= $asg['since'] ) {
					printf( '<td colspan="%d" class="cc-tl-loan cc-tl--assigned"><a href="%s" title="%s">%s</a></td>', $days - $i, esc_url( get_edit_post_link( $bike_id ) ), esc_attr( 'Assigned long-term since ' . $asg['since'] ), esc_html( $asg['name'] . ' · long-term' ) );
					break;
				}
				foreach ( $loans as $l ) {
					if ( $l->start_date <= $d && CC_Fleet_Loans::effective_end( $l ) >= $d && ( 'requested' !== $l->status || ! $hit ) ) {
						$hit = $l;
						if ( 'requested' !== $l->status ) {
							break;
						}
					}
				}
				if ( $hit ) {
					$span = min( $days - $i, CC_Fleet_Loans::days_between( $d, CC_Fleet_Loans::effective_end( $hit ) ) + 1 );
					$r    = CC_Fleet_Riders::get( $hit->rider_id );
					$cls  = CC_Fleet_Loans::is_overdue( $hit ) ? 'overdue' : $hit->status;
					printf( '<td colspan="%d" class="cc-tl-loan cc-tl--%s"><a href="%s" title="%s">%s</a></td>', $span, esc_attr( $cls ),
						esc_url( self::url( 'cc-fleet-loan', array( 'id' => $hit->id ) ) ), esc_attr( $hit->start_date . ' → ' . $hit->end_date . ' · ' . $hit->status ), esc_html( $r->name ?? '?' ) );
					$i += $span;
				} else {
					printf( '<td class="cc-tl-free%s"><a href="%s" aria-label="New loan %s"></a></td>', $d === $today ? ' is-today' : '',
						esc_url( self::url( 'cc-fleet-loan', array( 'bike_id' => $bike_id, 'start' => $d ) ) ), esc_attr( $d ) );
					$i++;
				}
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="cc-legend"><span class="cc-tl--requested">Requested</span> <span class="cc-tl--approved">Approved</span> <span class="cc-tl--out">Out</span> <span class="cc-tl--overdue">Overdue</span> <span class="cc-tl--returned">Returned</span> <span class="cc-tl--assigned">Long-term rider</span></p>';
		echo '</div>';
	}

	/* ================= Loans list ================= */

	public static function page_loans() {
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'active';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$tabs   = array( 'active' => 'Current & upcoming', 'requested' => 'Requests', 'overdue' => 'Overdue', 'returned' => 'Returned', 'closed' => 'Declined / cancelled', 'all' => 'All' );
		$args   = array( 'search' => $search, 'limit' => 50, 'offset' => ( $paged - 1 ) * 50, 'order' => 'ASC' );
		switch ( $status ) {
			case 'active':   $args['status'] = array( 'approved', 'out' ); break;
			case 'requested': $args['status'] = 'requested'; break;
			case 'overdue':  $args['overdue'] = true; break;
			case 'returned': $args['status'] = 'returned'; $args['order'] = 'DESC'; break;
			case 'closed':   $args['status'] = array( 'declined', 'cancelled' ); $args['order'] = 'DESC'; break;
			default:         $args['order'] = 'DESC';
		}
		$loans = CC_Fleet_Loans::query( $args );
		$total = CC_Fleet_Loans::count( $args );
		echo '<div class="wrap cc-wrap"><h1 class="wp-heading-inline">Loans</h1> <a class="page-title-action" href="' . esc_url( self::url( 'cc-fleet-loan' ) ) . '">+ New loan</a>';
		self::notices();
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $tabs as $k => $l ) {
			$links[] = sprintf( '<li><a href="%s"%s>%s</a></li>', esc_url( self::url( 'cc-fleet-loans', array( 'status' => $k ) ) ), $k === $status ? ' class="current"' : '', esc_html( $l ) );
		}
		echo implode( ' | ', $links ) . '</ul>'; // phpcs:ignore
		printf( '<form class="cc-search" method="get"><input type="hidden" name="page" value="cc-fleet-loans"><input type="hidden" name="status" value="%s"><input type="search" name="s" value="%s" placeholder="Rider, email or plan"> <button class="button">Search</button></form>', esc_attr( $status ), esc_attr( $search ) );
		self::loan_rows( $loans );
		if ( $total > 50 ) {
			echo '<p>' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => (int) ceil( $total / 50 ) ) ) . '</p>'; // phpcs:ignore
		}
		echo '</div>';
	}

	/* ================= Loan add / edit ================= */

	public static function page_loan() {
		$id    = (int) ( $_GET['id'] ?? 0 );
		$loan  = $id ? CC_Fleet_Loans::get( $id ) : null;
		$rider = $loan ? CC_Fleet_Riders::get( $loan->rider_id ) : null;
		$v     = array(
			'bike_id'    => $loan->bike_id ?? (int) ( $_GET['bike_id'] ?? 0 ),
			'start_date' => $loan->start_date ?? ( isset( $_GET['start'] ) ? sanitize_text_field( wp_unslash( $_GET['start'] ) ) : CC_Fleet_Settings::today() ),
			'end_date'   => $loan->end_date ?? '',
			'status'     => $loan->status ?? 'approved',
			'plan'       => $loan->plan ?? '',
			'notes'      => $loan->notes ?? '',
		);
		echo '<div class="wrap cc-wrap"><h1>' . ( $loan ? 'Loan #' . (int) $loan->id : 'New loan' ) . '</h1>';
		self::notices();
		if ( $loan ) {
			echo '<p>' . CC_Fleet_Loans::status_badge( $loan ) . ' &nbsp; ' . self::status_buttons( $loan ) . '</p>'; // phpcs:ignore
			if ( $loan->bike_id ) {
				$clash = CC_Fleet_Loans::conflicts( $loan->bike_id, $loan->start_date, $loan->end_date, $loan->id, true );
				foreach ( $clash as $c ) {
					if ( 'assigned' === $c->status ) {
						printf( '<div class="notice notice-warning inline"><p>This bike is assigned long-term (%s). <a href="%s">Change that on the bike’s page.</a></p></div>', esc_html( $c->plan ), esc_url( get_edit_post_link( $loan->bike_id ) ) );
						continue;
					}
					$cr = CC_Fleet_Riders::get( $c->rider_id );
					printf( '<div class="notice notice-warning inline"><p>Overlaps <a href="%s">loan #%d</a> (%s, %s → %s, %s).</p></div>', esc_url( self::url( 'cc-fleet-loan', array( 'id' => $c->id ) ) ), (int) $c->id, esc_html( $cr->name ?? '?' ), esc_html( $c->start_date ), esc_html( $c->end_date ), esc_html( $c->status ) );
				}
				$prev = CC_Fleet_Loans::previous_loan( $loan );
				if ( $prev ) {
					$pr = CC_Fleet_Riders::get( $prev->rider_id );
					printf( '<p class="cc-muted">Handoff from: <a href="%s">%s</a> (returns %s)</p>', esc_url( self::url( 'cc-fleet-loan', array( 'id' => $prev->id ) ) ), esc_html( $pr->name ?? '?' ), esc_html( $prev->end_date ) );
				}
			}
		}
		if ( $loan && in_array( $loan->status, array( 'requested', 'approved', 'out' ), true ) ) {
			self::dates_panel( $loan );
		}
		if ( $loan && in_array( $loan->status, array( 'approved', 'out' ), true ) ) {
			CC_Fleet_Handoff::loan_panel( $loan );
			self::handoff_panel( $loan );
			self::pressure_panel( $loan );
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cc-form">';
		wp_nonce_field( 'cc_fleet_loan_save' );
		echo '<input type="hidden" name="action" value="cc_fleet_loan_save"><input type="hidden" name="id" value="' . (int) $id . '">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="cc-bike">Bike</label></th><td><select id="cc-bike" name="bike_id"><option value="0">— Any bike (decide later) —</option>';
		foreach ( CC_Fleet_Bikes::all_ids() as $b ) {
			printf( '<option value="%d"%s>%s%s%s</option>', $b, selected( (int) $v['bike_id'], $b, false ), esc_html( CC_Fleet_Bikes::label( $b ) ), 'active' === CC_Fleet_Bikes::get( $b, 'state' ) ? '' : ' (' . esc_html( CC_Fleet_Bikes::get( $b, 'state' ) ) . ')', '1' === CC_Fleet_Bikes::get( $b, 'show_on_site' ) ? '' : ' · backend only' );
		}
		echo '</select>' . ( $loan && ! $loan->bike_id ? ' <span class="cc-muted">Requested size: ' . esc_html( $loan->requested_size ) . '</span>' : '' ) . '</td></tr>';
		printf( '<tr><th>Dates</th><td><input type="date" name="start_date" value="%s" required> → <input type="date" name="end_date" value="%s" required></td></tr>', esc_attr( $v['start_date'] ), esc_attr( $v['end_date'] ) );
		if ( ! $loan ) {
			echo '<tr><th><label for="cc-status">Status</label></th><td><select id="cc-status" name="status">';
			foreach ( array( 'approved' => 'Approved', 'out' => 'Out (already handed over)', 'requested' => 'Requested (decide later)' ) as $k => $l ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $v['status'], $k, false ), esc_html( $l ) );
			}
			echo '</select> <label><input type="checkbox" name="notify" value="1" checked> Email the rider</label></td></tr>';
		}
		echo '<tr><th><label for="cc-rider">Rider</label></th><td>';
		echo self::rider_picker( 'rider_id', $rider ? (int) $rider->id : (int) ( $_GET['rider_id'] ?? 0 ), true ); // phpcs:ignore -- escaped inside.
		if ( $rider ) {
			$wa = CC_Fleet_Phone::wa_link( $rider->phone );
			printf( '<p class="cc-muted">%s%s · <a href="%s">Edit %s’s details</a></p>', esc_html( trim( $rider->email . ' · ' . CC_Fleet_Phone::pretty( $rider->phone ), ' ·' ) ),
				$wa ? ' · <a href="' . esc_url( $wa ) . '" target="_blank" rel="noopener">WhatsApp' . ( 'yes' === $rider->whatsapp ? ' ✓' : '' ) . '</a>' : '',
				esc_url( self::url( 'cc-fleet-riders', array( 'edit' => $rider->id ) ) ), esc_html( strtok( $rider->name, ' ' ) ) );
		}
		echo '</td></tr>';
		printf( '<tr><th><label for="cc-plan">Plan</label></th><td><textarea id="cc-plan" name="plan" rows="3" class="large-text">%s</textarea></td></tr>', esc_textarea( $v['plan'] ) );
		$pd = (string) ( $loan->pedals ?? '' );
		printf( '<tr><th><label for="cc-pedals">Pedals</label></th><td><select id="cc-pedals" name="pedals"><option value=""%s>— Not asked —</option><option value="need"%s>Needs Shimano SPD pedals</option><option value="own"%s>Brings own pedals</option></select></td></tr>', selected( $pd, '', false ), selected( $pd, 'need', false ), selected( $pd, 'own', false ) );
		$ev_now = (int) ( $loan->event_id ?? ( $_GET['event_id'] ?? 0 ) );
		echo '<tr><th><label for="cc-event">Event</label></th><td><select id="cc-event" name="event_id"><option value="0">— Not for an event —</option>';
		foreach ( CC_Fleet_Records::query( 'events', array( 'from' => CC_Fleet_Loans::add_days( $v['start_date'] ?: CC_Fleet_Settings::today(), -60 ), 'order' => 'ASC', 'limit' => 60 ) ) as $ev ) {
			printf( '<option value="%d"%s>%s · %s</option>', (int) $ev->id, selected( $ev_now, (int) $ev->id, false ), esc_html( $ev->name ), esc_html( $ev->start_date ) );
		}
		echo '</select> <span class="cc-muted">Counts the bike for that event.</span></td></tr>';
		printf( '<tr><th><label for="cc-notes">Internal notes</label></th><td><textarea id="cc-notes" name="notes" rows="3" class="large-text" placeholder="Damage, handoff details, etc.">%s</textarea></td></tr>', esc_textarea( $v['notes'] ) );
		echo '<tr><th>Clashes</th><td><label><input type="checkbox" name="force" value="1"> Save even if it overlaps another loan</label></td></tr>';
		echo '</tbody></table>';
		submit_button( $loan ? 'Save loan' : 'Create loan' );
		echo '</form>';
		if ( $loan ) {
			printf( '<p><a class="cc-delete" href="%s" onclick="return confirm(\'Delete this loan permanently? This cannot be undone.\')">Delete permanently</a></p>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cc_fleet_loan_delete&id=' . $loan->id ), 'cc_fleet_loan_delete_' . $loan->id ) ) );
			global $wpdb;
			$log = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE object_type = 'loan' AND object_id = %d ORDER BY id DESC LIMIT 20", $loan->id ) );
			if ( $log ) {
				echo '<h2>History</h2><ul class="cc-log">';
				foreach ( $log as $e ) {
					$u = $e->user_id ? get_userdata( $e->user_id ) : null;
					printf( '<li><code>%s</code> %s %s <span class="cc-muted">%s</span></li>', esc_html( $e->created_at ), esc_html( $e->action ), esc_html( (string) $e->message ), esc_html( $u ? $u->display_name : 'website' ) );
				}
				echo '</ul>';
			}
		}
		echo '</div>';
	}

	/** Agreed a different return date with the rider? One click, logged, and the rider can be told. */
	private static function dates_panel( $loan ) {
		$base  = admin_url( 'admin-post.php' );
		$today = CC_Fleet_Settings::today();
		echo '<div class="cc-card cc-dates"><h2>Change the dates</h2><p class="cc-muted">For when you’ve agreed a longer or shorter loan with the rider. Currently <strong>' . esc_html( CC_Fleet_Emails::nice_date( $loan->start_date ) ) . ' → ' . esc_html( CC_Fleet_Emails::nice_date( $loan->end_date ) ) . '</strong>.</p>';
		echo '<form method="post" action="' . esc_url( $base ) . '">';
		wp_nonce_field( 'cc_fleet_loan_dates_' . $loan->id );
		printf( '<input type="hidden" name="action" value="cc_fleet_loan_dates"><input type="hidden" name="id" value="%d">', (int) $loan->id );
		echo '<p class="cc-dates__quick">';
		foreach ( array( 1 => '+1 day', 3 => '+3 days', 7 => '+1 week' ) as $n => $l ) {
			printf( '<button class="button" name="extend" value="%d">%s</button> ', $n, esc_html( $l ) );
		}
		if ( 'out' === $loan->status && $loan->end_date > $today ) {
			echo '<button class="button" name="end_today" value="1">Ends today</button> ';
		}
		echo '</p><p><label>Or new dates: <input type="date" name="start_date" value="' . esc_attr( $loan->start_date ) . '"' . ( 'out' === $loan->status ? ' readonly title="Already handed over"' : '' ) . '> → <input type="date" name="end_date" value="' . esc_attr( $loan->end_date ) . '"></label> <button class="button button-primary" name="custom" value="1">Save new dates</button></p>';
		echo '<p><label>What you agreed (optional)<br><input type="text" name="note" class="large-text" placeholder="e.g. Rider asked for 2 more days, next rider is fine with it" onkeydown="if(event.key===\'Enter\'){event.preventDefault();}"></label></p>';
		echo '<p><label><input type="checkbox" name="notify" value="1" checked> Email the rider the new dates</label> &nbsp; <label><input type="checkbox" name="force" value="1"> Save even if it overlaps another loan</label></p>';
		echo '</form></div>';
	}

	public static function handle_loan_dates() {
		$id = (int) ( $_POST['id'] ?? 0 );
		self::guard( 'cc_fleet_loan_dates_' . $id );
		$loan = CC_Fleet_Loans::get( $id );
		$back = self::url( 'cc-fleet-loan', array( 'id' => $id ) );
		if ( ! $loan ) {
			self::back( self::url( 'cc-fleet-loans' ), 'Loan not found.', 'error' );
		}
		$start = $loan->start_date;
		$end   = $loan->end_date;
		if ( ! empty( $_POST['extend'] ) ) {
			// Overdue loans extend from today, not from the missed date.
			$from = max( $end, 'out' === $loan->status ? CC_Fleet_Settings::today() : $end );
			$end  = CC_Fleet_Loans::add_days( $from, max( 1, min( 60, (int) $_POST['extend'] ) ) );
		} elseif ( ! empty( $_POST['end_today'] ) ) {
			$end = CC_Fleet_Settings::today();
		} else {
			$start = 'out' === $loan->status ? $loan->start_date : sanitize_text_field( wp_unslash( $_POST['start_date'] ?? $start ) );
			$end   = sanitize_text_field( wp_unslash( $_POST['end_date'] ?? $end ) );
		}
		if ( $start === $loan->start_date && $end === $loan->end_date ) {
			self::back( $back, 'Those are already the dates.', 'info' );
		}
		$res = CC_Fleet_Loans::update( $id, array( 'start_date' => $start, 'end_date' => $end ), ! empty( $_POST['force'] ) );
		if ( is_wp_error( $res ) ) {
			$msg = esc_html( $res->get_error_message() );
			foreach ( (array) ( $res->get_error_data()['conflicts'] ?? array() ) as $cid ) {
				$c   = CC_Fleet_Loans::get( $cid );
				$cr  = $c ? CC_Fleet_Riders::get( $c->rider_id ) : null;
				$msg .= sprintf( '<br>Clashes with <a href="%s">%s, %s → %s</a>. Talk to them first, or tick “Save even if it overlaps”.', esc_url( self::url( 'cc-fleet-loan', array( 'id' => $cid ) ) ), esc_html( $cr->name ?? 'another rider' ), esc_html( $c->start_date ?? '' ), esc_html( $c->end_date ?? '' ) );
			}
			self::back( $back, $msg, 'error' );
		}
		$note = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
		CC_Fleet_Install::log( 'loan', $id, 'dates', sprintf( '%s → %s changed to %s → %s%s', $loan->start_date, $loan->end_date, $start, $end, $note ? ' · ' . $note : '' ) );
		$sent = ! empty( $_POST['notify'] ) && CC_Fleet_Emails::dates_changed( CC_Fleet_Loans::get( $id ), $loan, $note );
		self::back( $back, sprintf( 'Dates changed to %s → %s.%s', esc_html( CC_Fleet_Emails::nice_date( $start ) ), esc_html( CC_Fleet_Emails::nice_date( $end ) ), $sent ? ' The rider was emailed.' : '' ) );
	}

	/** Where and when this rider hands the bike on (or collects it). Used in the reminder and the crew check. */
	/** Tyre pressure to set at handoff, from the rider's weight and the bike's tyres. */
	private static function pressure_panel( $loan ) {
		if ( ! class_exists( 'CC_Fleet_Pressure' ) || ! $loan->bike_id ) {
			return;
		}
		$r    = CC_Fleet_Riders::get( $loan->rider_id );
		$line = CC_Fleet_Pressure::line_for_loan( $loan );
		echo '<div class="cc-card" id="pressure"><h2>Tyre pressure at handoff</h2>';
		if ( $line ) {
			echo '<p><strong>' . esc_html( $line ) . '</strong></p><p class="cc-muted">' . esc_html( CC_Fleet_Pressure::advice( $loan ) ) . '</p>';
		} elseif ( $r && empty( $r->weight_kg ) ) {
			printf( '<p class="cc-muted">No weight for %s yet. Add it on the <a href="%s">rider</a> (optional, only for this) to get a pressure here and in the confirmation email.</p>', esc_html( $r->name ), esc_url( self::url( 'cc-fleet-riders', array( 'edit' => $r->id ) ) ) );
		} else {
			echo '<p class="cc-muted">No tyre width on this bike yet: set its tyres under Wheels &amp; tyres.</p>';
		}
		echo '</div>';
	}

	private static function handoff_panel( $loan ) {
		$next  = $loan->bike_id ? CC_Fleet_Loans::next_loan( $loan ) : null;
		$nr    = $next ? CC_Fleet_Riders::get( $next->rider_id ) : null;
		$days  = max( 0, (int) CC_Fleet_Settings::get( 'reminder_days_before' ) );
		$goes  = CC_Fleet_Loans::add_days( $loan->end_date, -$days );
		$saved = class_exists( 'CC_Fleet_Outbox' ) ? CC_Fleet_Outbox::scheduled( $loan->id ) : null;
		echo '<div class="cc-card cc-handoff" id="handoff"><h2>Return handoff</h2>';
		echo '<p class="cc-muted">' . ( $nr ? 'Next rider: <a href="' . esc_url( self::url( 'cc-fleet-loan', array( 'id' => $next->id ) ) ) . '">' . esc_html( $nr->name ) . '</a>, from ' . esc_html( CC_Fleet_Emails::nice_date( $next->start_date ) ) . ( $nr->phone ? ' · <a href="' . esc_url( CC_Fleet_Phone::wa_link( $nr->phone ) ) . '" target="_blank" rel="noopener">WhatsApp</a>' : '' ) . '.' : 'Nobody booked after this loan: the bike comes back to the crew.' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cc_fleet_loan_handoff_' . $loan->id );
		printf( '<input type="hidden" name="action" value="cc_fleet_loan_handoff"><input type="hidden" name="id" value="%d">', (int) $loan->id );
		printf( '<p class="cc-handoff-row"><label>Where<br><input type="text" name="handoff_place" value="%s" placeholder="%s"></label> <label>When<br><input type="text" name="handoff_time" value="%s" placeholder="e.g. Tue 8 Dec, 17:30"></label></p>', esc_attr( $loan->handoff_place ?? '' ), esc_attr( CC_Fleet_Settings::get( 'dropoff_place' ) ?: 'e.g. Woodstock Cycleworks' ), esc_attr( $loan->handoff_time ?? '' ) );
		printf( '<p><label>Note for the rider<br><textarea name="handoff_note" rows="2" class="large-text" placeholder="e.g. Sipho brings his own pedals.">%s</textarea></label></p>', esc_textarea( $loan->handoff_note ?? '' ) );
		echo '<p><button class="button button-primary">Save handoff</button></p></form>';
		if ( $loan->reminder_sent ) {
			echo '<p class="cc-muted">The return reminder has gone out.</p>';
		} else {
			echo '<p class="cc-muted">Return reminder: goes out by itself on ' . esc_html( CC_Fleet_Emails::nice_date( max( CC_Fleet_Settings::today(), $goes ) ) ) . ( $saved ? ', <strong>with your edits</strong>' : '' ) . '. The crew gets a check email the morning before. <a class="button button-small" href="' . esc_url( CC_Fleet_Outbox::prepare_url( $loan->id ) ) . '">' . ( $saved ? 'Change the edited reminder' : 'Read or edit the reminder' ) . '</a></p>';
		}
		echo '</div>';
	}

	public static function handle_loan_handoff() {
		$id = (int) ( $_POST['id'] ?? 0 );
		self::guard( 'cc_fleet_loan_handoff_' . $id );
		$in  = wp_unslash( $_POST );
		$res = CC_Fleet_Loans::update( $id, array(
			'handoff_place' => sanitize_text_field( $in['handoff_place'] ?? '' ),
			'handoff_time'  => sanitize_text_field( $in['handoff_time'] ?? '' ),
			'handoff_note'  => sanitize_textarea_field( $in['handoff_note'] ?? '' ),
		), true );
		$url = self::url( 'cc-fleet-loan', array( 'id' => $id ) ) . '#handoff';
		if ( is_wp_error( $res ) ) {
			self::back( $url, esc_html( $res->get_error_message() ), 'error' );
		}
		$saved = class_exists( 'CC_Fleet_Outbox' ) ? CC_Fleet_Outbox::scheduled( $id ) : null;
		self::back( $url, 'Handoff saved.' . ( $saved ? ' You have an edited reminder saved: check it still matches.' : ' The reminder will include it.' ) );
	}

	public static function handle_loan_save() {
		self::guard( 'cc_fleet_loan_save' );
		$id     = (int) ( $_POST['id'] ?? 0 );
		$force  = ! empty( $_POST['force'] );
		$in     = wp_unslash( $_POST );
		$rid    = 'new' === ( $in['rider_id'] ?? '' ) ? 0 : (int) ( $in['rider_id'] ?? 0 );
		if ( 'new' === ( $in['rider_id'] ?? '' ) ) {
			$new = CC_Fleet_Riders::create( (array) ( $in['new_rider'] ?? array() ) );
			if ( is_wp_error( $new ) ) {
				self::back( wp_get_referer() ?: self::url( 'cc-fleet-loan', array( 'id' => $id ) ), esc_html( $new->get_error_message() ), 'error' );
			}
			$rid = $new;
		} elseif ( ! $rid && ! empty( $in['rider']['name'] ) ) {
			$rid = CC_Fleet_Riders::upsert( (array) $in['rider'] ); // Older forms.
		}
		if ( ! $rid ) {
			self::back( wp_get_referer() ?: self::url( 'cc-fleet-loan', array( 'id' => $id ) ), 'Choose a rider, or “+ New rider”.', 'error' );
		}
		$data = array(
			'bike_id' => (int) ( $in['bike_id'] ?? 0 ), 'rider_id' => $rid,
			'start_date' => sanitize_text_field( $in['start_date'] ?? '' ), 'end_date' => sanitize_text_field( $in['end_date'] ?? '' ),
			'plan' => sanitize_textarea_field( $in['plan'] ?? '' ), 'notes' => sanitize_textarea_field( $in['notes'] ?? '' ),
			'event_id' => (int) ( $in['event_id'] ?? 0 ),
			'pedals' => in_array( $in['pedals'] ?? '', array( 'need', 'own' ), true ) ? $in['pedals'] : '',
		);
		if ( $id ) {
			$res = CC_Fleet_Loans::update( $id, $data, $force );
		} else {
			$status = in_array( $in['status'] ?? '', array( 'approved', 'out', 'requested' ), true ) ? $in['status'] : 'approved';
			$res    = CC_Fleet_Loans::create( array_merge( $data, array( 'status' => 'requested', 'source' => 'admin' ) ), $force );
			if ( ! is_wp_error( $res ) ) {
				$id = $res;
				if ( 'requested' !== $status ) {
					$r2 = CC_Fleet_Loans::set_status( $id, 'approved', ! empty( $in['notify'] ), $force );
					if ( ! is_wp_error( $r2 ) && 'out' === $status ) {
						CC_Fleet_Loans::set_status( $id, 'out', false, $force );
					}
					$res = $r2;
				}
			}
		}
		if ( is_wp_error( $res ) ) {
			self::back( wp_get_referer() ?: self::url( 'cc-fleet-loan', array( 'id' => $id ) ), esc_html( $res->get_error_message() ) . ' Tick “Save even if it overlaps” to override.', 'error' );
		}
		self::back( self::url( 'cc-fleet-loan', array( 'id' => $id ) ), 'Loan saved.' );
	}

	public static function handle_loan_status() {
		$id = (int) ( $_GET['id'] ?? 0 );
		self::guard( 'cc_fleet_loan_status_' . $id );
		$ref  = wp_get_referer() ?: self::url( 'cc-fleet-loans' );
		$bike = (int) ( $_GET['bike_id'] ?? 0 );
		$loan = CC_Fleet_Loans::get( $id );
		if ( $bike && $loan && ! $loan->bike_id ) {
			if ( ! in_array( $bike, CC_Fleet_Bikes::all_ids(), true ) ) {
				self::back( $ref, 'Unknown bike.', 'error' );
			}
			$set = CC_Fleet_Loans::update( $id, array( 'bike_id' => $bike ) );
			if ( is_wp_error( $set ) ) {
				self::back( $ref, esc_html( $set->get_error_message() ), 'error' );
			}
		}
		$res = CC_Fleet_Loans::set_status( $id, sanitize_key( $_GET['to'] ?? '' ) );
		if ( is_wp_error( $res ) ) {
			self::back( $ref, esc_html( $res->get_error_message() ), 'error' );
		}
		$to = sanitize_key( $_GET['to'] ?? '' );
		self::back( $ref, 'waiting' === $to ? 'On the waiting list. The rider was emailed.' : 'Loan updated' . ( in_array( $to, array( 'approved', 'declined', 'cancelled', 'returned' ), true ) ? ' and the rider was emailed.' : '.' ) );
	}

	public static function handle_loan_delete() {
		$id = (int) ( $_GET['id'] ?? 0 );
		self::guard( 'cc_fleet_loan_delete_' . $id );
		CC_Fleet_Loans::delete( $id );
		self::back( self::url( 'cc-fleet-loans' ), 'Loan deleted.' );
	}

	/* ================= Riders ================= */

	public static function page_riders() {
		$edit = sanitize_key( $_GET['edit'] ?? '' );
		echo '<div class="wrap cc-wrap"><h1 class="wp-heading-inline">Riders <span class="cc-muted">(Community Connectors)</span></h1> <a class="page-title-action" href="' . esc_url( self::url( 'cc-fleet-riders', array( 'edit' => 'new' ) ) ) . '">+ Add rider</a><hr class="wp-header-end">';
		self::notices();
		$r = 'new' === $edit ? (object) array( 'id' => 0, 'name' => '', 'email' => '', 'phone' => '', 'instagram' => '', 'height_cm' => '', 'weight_kg' => '', 'rating' => '', 'notes' => '', 'whatsapp' => '' ) : ( (int) $edit ? CC_Fleet_Riders::get( (int) $edit ) : null );
		if ( $r ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cc-form">';
			wp_nonce_field( 'cc_fleet_rider_save' );
			printf( '<input type="hidden" name="action" value="cc_fleet_rider_save"><input type="hidden" name="id" value="%d"><table class="form-table"><tbody>', (int) $r->id );
			printf( '<tr><th><label for="cc-r-name">Name</label></th><td><input type="text" id="cc-r-name" name="name" value="%s" class="regular-text" required></td></tr>', esc_attr( $r->name ) );
			printf( '<tr><th><label for="cc-r-email">Email</label></th><td><input type="email" id="cc-r-email" name="email" value="%s" class="regular-text"></td></tr>', esc_attr( $r->email ) );
			$wa = CC_Fleet_Phone::wa_link( $r->phone );
			printf( '<tr><th><label for="cc-r-phone">WhatsApp / phone</label></th><td>%s <input type="tel" id="cc-r-phone" name="phone" value="%s" placeholder="82 123 4567 or +49…">%s<p class="description">Saved as +country number, so WhatsApp links work. A number starting with + keeps its own country code.</p></td></tr>',
				CC_Fleet_Phone::select( 'phone_cc', '27', 'aria-label="Country code"' ), esc_attr( CC_Fleet_Phone::pretty( $r->phone ) ), $wa ? ' <a class="button" href="' . esc_url( $wa ) . '" target="_blank" rel="noopener">Open WhatsApp chat</a>' : '' );
			echo '<tr><th><label for="cc-r-wa">On WhatsApp?</label></th><td><select id="cc-r-wa" name="whatsapp">';
			foreach ( array( '' => 'Not checked yet', 'yes' => 'Yes, confirmed', 'no' => 'No, not on WhatsApp' ) as $k => $l ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( (string) $r->whatsapp, $k, false ), esc_html( $l ) );
			}
			echo '</select><p class="description">Set to “Yes” once they’ve messaged the crew WhatsApp or you’ve chatted with them there.</p></td></tr>';
			printf( '<tr><th><label for="cc-r-ig">Instagram</label></th><td><input type="text" id="cc-r-ig" name="instagram" value="%s"></td></tr>', esc_attr( $r->instagram ) );
			printf( '<tr><th><label for="cc-r-h">Height (cm)</label></th><td><input type="number" id="cc-r-h" name="height_cm" value="%s" style="width:110px"></td></tr>', esc_attr( (string) $r->height_cm ) );
			printf( '<tr><th><label for="cc-r-w">Weight (kg)</label></th><td><input type="number" id="cc-r-w" name="weight_kg" value="%s" style="width:110px" min="30" max="200"> <span class="description">Optional, only for the tyre pressure at handoff.</span></td></tr>', esc_attr( (string) ( $r->weight_kg ?? '' ) ) );
			printf( '<tr><th><label for="cc-r-rating">Rating (1–5)</label></th><td><input type="number" min="1" max="5" id="cc-r-rating" name="rating" value="%s" style="width:80px"></td></tr>', esc_attr( (string) $r->rating ) );
			printf( '<tr><th><label for="cc-r-notes">Notes</label></th><td><textarea id="cc-r-notes" name="notes" rows="3" class="large-text">%s</textarea></td></tr></tbody></table>', esc_textarea( (string) $r->notes ) );
			submit_button( $r->id ? 'Save rider' : 'Add rider' );
			echo '</form>';
			if ( $r->id ) {
				printf( '<h2>Loans</h2><p><a class="button" href="%s">+ New loan for %s</a></p>', esc_url( self::url( 'cc-fleet-loan', array( 'rider_id' => $r->id ) ) ), esc_html( strtok( $r->name, ' ' ) ) );
				self::loan_rows( CC_Fleet_Loans::query( array( 'rider_id' => $r->id, 'limit' => 100 ) ), false );
			}
			echo '</div>';
			return;
		}
		$s = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		printf( '<form class="cc-search" method="get"><input type="hidden" name="page" value="cc-fleet-riders"><input type="search" name="s" value="%s" placeholder="Name, email, phone, Instagram"> <button class="button">Search</button></form>', esc_attr( $s ) );
		$rows = CC_Fleet_Riders::search( $s, 500 );
		echo '<table class="widefat striped cc-table"><thead><tr><th>Name</th><th>Email</th><th>WhatsApp / phone</th><th>Instagram</th><th>Height</th><th>Loans</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$wa = CC_Fleet_Phone::wa_link( $r->phone );
			$ph = esc_html( CC_Fleet_Phone::pretty( $r->phone ) );
			printf( '<tr><td><a href="%s"><strong>%s</strong></a></td><td>%s</td><td>%s%s</td><td>%s</td><td>%s</td><td>%d</td></tr>', esc_url( self::url( 'cc-fleet-riders', array( 'edit' => $r->id ) ) ),
				esc_html( $r->name ), esc_html( $r->email ), $wa ? '<a href="' . esc_url( $wa ) . '" target="_blank" rel="noopener">' . $ph . '</a>' : $ph,
				'yes' === $r->whatsapp ? ' <span class="cc-wa-yes" title="Confirmed on WhatsApp">✓</span>' : ( 'no' === $r->whatsapp ? ' <span class="cc-muted" title="Not on WhatsApp">(no WhatsApp)</span>' : '' ),
				$r->instagram ? '@' . esc_html( $r->instagram ) : '', $r->height_cm ? (int) $r->height_cm . ' cm' : '', (int) $r->loans );
		}
		echo '</tbody></table></div>';
	}

	public static function handle_rider_save() {
		self::guard( 'cc_fleet_rider_save' );
		$in   = wp_unslash( $_POST );
		$id   = (int) ( $in['id'] ?? 0 );
		$data = array(
			'name' => sanitize_text_field( $in['name'] ?? '' ), 'email' => sanitize_email( $in['email'] ?? '' ),
			'phone' => sanitize_text_field( $in['phone'] ?? '' ), 'phone_cc' => sanitize_text_field( $in['phone_cc'] ?? '27' ),
			'instagram' => ltrim( sanitize_text_field( $in['instagram'] ?? '' ), '@' ),
			'height_cm' => absint( $in['height_cm'] ?? 0 ) ?: null, 'weight_kg' => ( absint( $in['weight_kg'] ?? 0 ) >= 30 && absint( $in['weight_kg'] ?? 0 ) <= 200 ) ? absint( $in['weight_kg'] ) : null, 'rating' => min( 5, absint( $in['rating'] ?? 0 ) ) ?: null,
			'notes' => sanitize_textarea_field( $in['notes'] ?? '' ), 'whatsapp' => sanitize_key( $in['whatsapp'] ?? '' ),
		);
		if ( '' !== $data['phone'] ) {
			$p = CC_Fleet_Phone::normalize( $data['phone'], $data['phone_cc'] );
			if ( is_wp_error( $p ) ) {
				self::back( self::url( 'cc-fleet-riders', array( 'edit' => $id ?: 'new' ) ), esc_html( $p->get_error_message() ), 'error' );
			}
			$data['phone'] = $p;
		}
		if ( ! $id ) {
			$id = CC_Fleet_Riders::create( $data );
			if ( is_wp_error( $id ) ) {
				self::back( self::url( 'cc-fleet-riders', array( 'edit' => 'new' ) ), esc_html( $id->get_error_message() ), 'error' );
			}
			CC_Fleet_Riders::update( $id, array( 'whatsapp' => $data['whatsapp'], 'rating' => $data['rating'] ) );
			self::back( self::url( 'cc-fleet-riders', array( 'edit' => $id ) ), 'Rider added.' );
		}
		unset( $data['phone_cc'] );
		CC_Fleet_Riders::update( $id, $data );
		self::back( self::url( 'cc-fleet-riders', array( 'edit' => $id ) ), 'Rider saved.' );
	}

	/* ================= Import ================= */

	public static function page_import() {
		echo '<div class="wrap cc-wrap"><h1>Import</h1>';
		self::notices();
		$action = esc_url( admin_url( 'admin-post.php' ) );
		echo '<div class="cc-cards">';
		echo '<form class="cc-card" method="post" enctype="multipart/form-data" action="' . $action . '">'; // phpcs:ignore
		wp_nonce_field( 'cc_fleet_import' );
		echo '<input type="hidden" name="action" value="cc_fleet_import"><input type="hidden" name="kind" value="bikes">';
		echo '<h2>Bikes from the parts-list sheet</h2><p>In Google Sheets open the <strong>FLEET</strong> tab → File → Download → CSV. Bikes are matched on “Bike ID”, so re-importing updates them in place. Website settings (show on site, state, blurb) are kept.</p>';
		echo '<p><input type="file" name="csv" accept=".csv,text/csv"></p>';
		echo '<p><label>Show these on the website when first created:<br><input type="text" name="publish" class="large-text" value="CC-03, CC-04, CC-05, CC-06, CC-07, CC-08, CC-09, CC-11, CC-12, CC-13"></label></p>';
		echo '<p><label><input type="checkbox" name="bundled" value="1"> Use the copy bundled with the plugin (FLEET tab, updated 20 Nov 2026, 16:55) instead of an upload</label></p>';
		submit_button( 'Import bikes', 'primary', 'submit', false );
		echo '</form>';
		echo '<form class="cc-card" method="post" enctype="multipart/form-data" action="' . $action . '">'; // phpcs:ignore
		wp_nonce_field( 'cc_fleet_import' );
		echo '<input type="hidden" name="action" value="cc_fleet_import"><input type="hidden" name="kind" value="loans">';
		echo '<h2>Loans from the old app</h2><p>CSV columns: <code>bike_id, name, email, phone, instagram, start, end, status, plan, notes, rider_notes</code>. bike_id is the CC number (CC-12). Status may be Active / Upcoming / Completed or requested / approved / out / returned / cancelled. Riders are matched on email, then Instagram, then phone + name. Rows with a name but no bike or dates add just the rider. No emails are sent (now or as reminders), clashes are not checked, and importing the same file twice skips loans already there.</p>';
		echo '<p><input type="file" name="csv" accept=".csv,text/csv" required></p>';
		submit_button( 'Import loans', 'secondary', 'submit', false );
		echo '</form>';
		echo '<form class="cc-card" method="post" action="' . $action . '">'; // phpcs:ignore
		wp_nonce_field( 'cc_fleet_import' );
		echo '<input type="hidden" name="action" value="cc_fleet_import"><input type="hidden" name="kind" value="tidy">';
		echo '<h2>Tidy up riders</h2><p>Merges riders entered more than once under the same name (when their email, Instagram and phone don\'t contradict each other). Their loans and notes move to one record. Also switches off automatic reminder emails for imported loans.</p>';
		submit_button( 'Merge duplicate riders', 'secondary', 'submit', false );
		echo '</form>';
		if ( class_exists( 'CC_Fleet_Records_Admin' ) ) {
			CC_Fleet_Records_Admin::import_card();
		}
		echo '</div></div>';
	}

	public static function handle_import() {
		self::guard( 'cc_fleet_import' );
		$kind = sanitize_key( $_POST['kind'] ?? '' );
		$file = '';
		if ( 'bikes' === $kind && ! empty( $_POST['bundled'] ) ) {
			$file = CC_FLEET_DIR . 'data/fleet-2027.csv';
		} elseif ( ! empty( $_FILES['csv']['tmp_name'] ) && is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			$file = $_FILES['csv']['tmp_name']; // phpcs:ignore
		}
		if ( 'tidy' === $kind ) {
			$t = CC_Fleet_Import::tidy();
			self::back( self::url( 'cc-fleet-import' ), sprintf( 'Tidied: %d duplicate rider records merged into %d riders. Riders now: %d. Reminders switched off for %d imported loans.', $t['removed'], $t['merged'], CC_Fleet_Riders::count(), $t['quiet'] ) );
		}
		if ( ! $file ) {
			self::back( self::url( 'cc-fleet-import' ), 'Choose a CSV file.', 'error' );
		}
		$res = 'loans' === $kind ? CC_Fleet_Import::loans_csv( $file )
			: CC_Fleet_Import::bikes_csv( $file, array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['publish'] ?? '' ) ) ) ) ) );
		$msg = sprintf( 'Import done: %d created, %d updated, %d skipped.', $res['created'], $res['updated'], $res['skipped'] ) . ( isset( $res['riders'] ) ? sprintf( ' %d riders without a loan added. Riders in total: %d.', $res['riders'], CC_Fleet_Riders::count() ) : '' );
		if ( $res['errors'] ) {
			$msg .= '<br>' . implode( '<br>', array_map( 'esc_html', array_slice( $res['errors'], 0, 20 ) ) );
		}
		self::back( self::url( 'cc-fleet-import' ), $msg, $res['errors'] ? 'warning' : 'success' );
	}

	/* ================= Settings ================= */

	public static function page_settings() {
		$s = CC_Fleet_Settings::all();
		echo '<div class="wrap cc-wrap"><h1>Fleet settings</h1>';
		self::notices();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cc_fleet_settings_save' );
		echo '<input type="hidden" name="action" value="cc_fleet_settings_save"><table class="form-table"><tbody>';
		$fields = array(
			'notify_emails'        => array( 'Crew emails', 'text', 'New requests and the daily digest go here. Separate with commas. The first one is also where riders’ replies go.' ),
			'from_name'            => array( 'Sender name', 'text', '' ),
			'from_email'           => array( 'Sender address', 'text', 'All bike emails come from this address, e.g. bikes@community-connect.cc. Leave empty to use the site’s default. In WP Mail SMTP, “Force From Email” must be off for this to apply.' ),
			'min_notice_days'      => array( 'Minimum notice (days)', 'number', 'How far ahead a request must start.' ),
			'usual_loan_days'      => array( 'Usual loan (days)', 'number', 'What riders are told is normal (we use 7). Longer requests can still be sent, with a reason in their plan, and are marked in the request.' ),
			'max_loan_days'        => array( 'Longest loan (days)', 'number', 'Hard limit: the form refuses anything longer.' ),
			'book_until'           => array( 'Bookable until', 'date', 'Leave empty for 31 December of next year (now ' . CC_Fleet_Loans::horizon() . ').' ),
			'race_terms'           => array( 'Racing terms', 'textarea', 'Riders tick this when they ask for a bike for an event or race.' ),
			'buffer_days'          => array( 'Free days between loans', 'number', 'Days kept free after each loan for washing, small fixes and late returns. We use 1.' ),
			'same_day_handoff'     => array( 'Same-day handoff', 'checkbox', 'Only with 0 free days: a loan may start on the day the previous one ends.' ),
			'requests_hold_dates'  => array( 'Pending requests block dates', 'checkbox', 'If on, unreviewed requests also show as taken on the website.' ),
			'reminder_days_before' => array( 'Return reminder (days before)', 'number', '' ),
			'rate_limit_per_hour'  => array( 'Requests per hour per connection', 'number', 'Spam protection.' ),
			'size_heights'         => array( 'Size → rider height (cm)', 'textarea', 'One per line, e.g. 56:176-184. Approximate; confirm against Standert geometry.' ),
			'agreement_url'        => array( 'Borrowing agreement URL', 'url', '' ),
			'handoff_notes'        => array( 'Handoff note in confirmation emails', 'text', 'One line riders see when a bike is confirmed, e.g. “Pedals: bring your own.”' ),
			'dropoff_place'        => array( 'Drop-off place', 'text', 'Used in return reminders when no next rider is waiting, e.g. “The clubhouse, Sea Point”. Empty: our base.' ),
			'base_address'         => array( 'Our base (address)', 'text', 'Default pick-up when a loan starts more than 7 days out and nobody has the bike before.' ),
			'base_contact'         => array( 'Our base (contact)', 'text', 'Name and number riders call at the base, e.g. “Julian · +27 76 223 0218”.' ),
			'instagram_handle'     => array( 'Instagram handle', 'text', 'Used in emails, without the @.' ),
			'whatsapp_number'      => array( 'Crew WhatsApp number', 'text', 'With country code, e.g. +27 82 123 4567. Riders get a button to message this number after requesting, which confirms their number has WhatsApp.' ),
			'review_rider_emails'  => array( 'Review rider emails before sending', 'checkbox', 'Approve, decline, cancel, return and date-change emails open for you to read and change before they go out.' ),
			'waitlist_offer_hours' => array( 'Waiting list: hours to answer an offer', 'number', 'After this the offer goes to the next rider on the list. We use 24.' ),
			'waitlist_short_hours' => array( 'Waiting list: short notice (hours)', 'number', 'If the loan starts sooner than this, the offer goes to several riders at once and the first yes gets the bike. We use 48.' ),
			'waitlist_short_count' => array( 'Waiting list: riders offered at short notice', 'number', 'We use 3.' ),
			'email_style'          => array( 'Email style', 'select', '' ),
			'privacy_url'          => array( 'Privacy policy URL', 'url', '' ),
			'ical_key'             => array( 'Calendar feed key', 'text', 'Change it to revoke old calendar links.' ),
			'delete_data_on_uninstall' => array( 'Delete all fleet data when the plugin is deleted', 'checkbox', 'Leave off unless you really mean it.' ),
		);
		foreach ( $fields as $k => $f ) {
			echo '<tr><th><label for="cc-' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</label></th><td>';
			if ( 'checkbox' === $f[1] ) {
				printf( '<input type="checkbox" id="cc-%1$s" name="s[%1$s]" value="1"%2$s>', esc_attr( $k ), checked( ! empty( $s[ $k ] ), true, false ) );
			} elseif ( 'select' === $f[1] ) {
				printf( '<select id="cc-%1$s" name="s[%1$s]"><option value="html"%2$s>Designed emails (HTML with a plain-text copy)</option><option value="text"%3$s>Plain text only</option></select> <a href="%4$s" target="_blank" rel="noopener">Preview the emails</a>', esc_attr( $k ), selected( $s[ $k ], 'html', false ), selected( $s[ $k ], 'text', false ), esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cc_fleet_email_preview' ), 'cc_fleet_email_preview' ) ) );
			} elseif ( 'textarea' === $f[1] ) {
				printf( '<textarea id="cc-%1$s" name="s[%1$s]" rows="7" cols="30">%2$s</textarea>', esc_attr( $k ), esc_textarea( $s[ $k ] ) );
			} else {
				printf( '<input type="%3$s" id="cc-%1$s" name="s[%1$s]" value="%2$s" class="regular-text">', esc_attr( $k ), esc_attr( $s[ $k ] ), esc_attr( $f[1] ) );
			}
			echo $f[2] ? '<p class="description">' . esc_html( $f[2] ) . '</p>' : '';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		printf( '<p>Calendar feed (whole fleet): <code>%s</code><br>One bike: add <code>&amp;bike=CC-12</code></p>', esc_html( CC_Fleet_Ical::url() ) );
		submit_button();
		echo '</form></div>';
	}

	public static function handle_settings_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'cc_fleet_settings_save' );
		$in  = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array();
		$out = array();
		foreach ( CC_Fleet_Settings::defaults() as $k => $default ) {
			if ( is_int( $default ) ) {
				$out[ $k ] = in_array( $k, array( 'same_day_handoff', 'requests_hold_dates', 'delete_data_on_uninstall', 'review_rider_emails' ), true ) ? ( empty( $in[ $k ] ) ? 0 : 1 ) : max( 0, (int) ( $in[ $k ] ?? $default ) );
			} elseif ( 'race_terms' === $k ) {
				$out[ $k ] = trim( sanitize_textarea_field( $in[ $k ] ?? '' ) ) ?: $default;
			} elseif ( 'book_until' === $k ) {
				$d         = sanitize_text_field( $in[ $k ] ?? '' );
				$out[ $k ] = CC_Fleet_Loans::valid_date( $d ) ? $d : '';
			} elseif ( 'size_heights' === $k ) {
				$out[ $k ] = sanitize_textarea_field( $in[ $k ] ?? $default );
			} elseif ( in_array( $k, array( 'agreement_url', 'privacy_url' ), true ) ) {
				$out[ $k ] = esc_url_raw( $in[ $k ] ?? '' );
			} elseif ( 'from_email' === $k ) {
				$out[ $k ] = sanitize_email( $in[ $k ] ?? '' );
			} elseif ( 'whatsapp_number' === $k ) {
				$out[ $k ] = '' === trim( (string) ( $in[ $k ] ?? '' ) ) ? '' : CC_Fleet_Phone::clean( sanitize_text_field( $in[ $k ] ) );
			} else {
				$out[ $k ] = sanitize_text_field( $in[ $k ] ?? $default );
			}
		}
		if ( strlen( $out['ical_key'] ) < 16 ) {
			$out['ical_key'] = wp_generate_password( 24, false );
		}
		CC_Fleet_Settings::update( $out );
		self::back( self::url( 'cc-fleet-settings' ), 'Settings saved.' );
	}

	/** Every email with sample data, in the browser, so the crew can check the look. */
	public static function handle_email_preview() {
		self::guard( 'cc_fleet_email_preview' );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo CC_Fleet_Emails::preview_page(); // phpcs:ignore -- built from escaped parts.
		exit;
	}

	public static function handle_run_daily() {
		self::guard( 'cc_fleet_run_daily' );
		$s = CC_Fleet_Cron::run();
		self::back( self::url( 'cc-fleet' ), sprintf( 'Daily jobs ran: %d loans started, %d reminders sent, %d overdue.', $s['started'], $s['reminders'], $s['overdue'] ) );
	}
}
