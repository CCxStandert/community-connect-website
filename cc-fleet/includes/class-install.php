<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tables, roles and upgrades.
 *
 * Bikes are a custom post type (public pages, images, editing).
 * Riders, loans and the event log are custom tables: private data, fast date-range queries.
 */
class CC_Fleet_Install {

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'cc_' . $name;
	}

	public static function activate() {
		self::create_tables();
		self::add_roles();
		$settings = CC_Fleet_Settings::all();
		if ( empty( $settings['ical_key'] ) ) {
			CC_Fleet_Settings::update( array( 'ical_key' => wp_generate_password( 24, false ) ) );
		}
		self::create_fleet_page();
		CC_Fleet_Landing::install();
		CC_Fleet_Bikes::register_post_type();
		flush_rewrite_rules();
		CC_Fleet_Cron::schedule();
		update_option( 'cc_fleet_db_version', CC_FLEET_DB_VERSION, false );
	}

	/** A normal, editable page at /fleet/ with the grid and an "any bike" request form. */
	public static function create_fleet_page() {
		if ( get_option( 'cc_fleet_page_id' ) && get_post( (int) get_option( 'cc_fleet_page_id' ) ) ) {
			return;
		}
		$existing = get_page_by_path( 'fleet' );
		if ( $existing ) {
			update_option( 'cc_fleet_page_id', $existing->ID, false );
			return;
		}
		$id = wp_insert_post( array(
			'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Fleet', 'post_name' => 'fleet',
			'post_content' => "<!-- wp:paragraph --><p>Free to borrow. Pick a bike, tell us your plan, and the last rider usually hands it over.</p><!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->[cc_fleet]<!-- /wp:shortcode -->\n\n<!-- wp:heading --><h2 class=\"wp-block-heading\">Not sure which bike?</h2><!-- /wp:heading -->\n\n<!-- wp:shortcode -->[cc_bike_request bike=\"any\"]<!-- /wp:shortcode -->",
		) );
		if ( $id && ! is_wp_error( $id ) ) {
			update_option( 'cc_fleet_page_id', $id, false );
		}
	}

	public static function deactivate() {
		CC_Fleet_Cron::unschedule();
		wp_clear_scheduled_hook( CC_Fleet_Feeds::HOOK );
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		$from = (int) get_option( 'cc_fleet_db_version' );
		if ( get_option( 'cc_fleet_db_version' ) !== CC_FLEET_DB_VERSION ) {
			self::create_tables();
			self::add_roles();
			if ( $from && $from < 3 ) {
				self::normalize_phones();
			}
			if ( $from && $from < 4 ) {
				self::one_free_day();
			}
			if ( $from < 5 ) {
				// Creating a page needs WordPress fully loaded (rewrite rules exist from 'init' on):
				// this runs on plugins_loaded, so only leave a note and do it on init.
				update_option( 'cc_fleet_install_landing', 1, false );
			}
			if ( $from && $from < 8 ) {
				CC_Fleet_Records::migrate_event_cats(); // Events: one type → several categories.
			}
			if ( $from && $from < 11 ) {
				update_option( 'cc_fleet_migrate_slugs', 1, false ); // Bike pages: /fleet/cc-12/ → /fleet/kettensage-56/ (done on init).
			}
			if ( $from < 6 ) {
				// Wheelsets and tyres become parts: done on init, when bikes can be queried.
				update_option( 'cc_fleet_migrate_parts', 1, false );
			}
			update_option( 'cc_fleet_db_version', CC_FLEET_DB_VERSION, false );
		}
	}

	/**
	 * v0.5: always one free day between loans, and the agreement on the new home page.
	 * Existing sites keep a longer buffer if they had one.
	 */
	public static function one_free_day() {
		$s   = CC_Fleet_Settings::all();
		$set = array( 'buffer_days' => max( 1, (int) $s['buffer_days'] ), 'same_day_handoff' => 0 );
		CC_Fleet_Settings::update( $set );
		self::log( 'settings', 0, 'upgrade', 'One free day between loans' );
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		$riders = self::table( 'riders' );
		$loans  = self::table( 'loans' );
		$log    = self::table( 'log' );

		dbDelta( "CREATE TABLE $riders (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			instagram varchar(120) NOT NULL DEFAULT '',
			height_cm smallint(5) unsigned DEFAULT NULL,
			weight_kg smallint(5) unsigned DEFAULT NULL,
			rating tinyint(3) unsigned DEFAULT NULL,
			whatsapp varchar(12) NOT NULL DEFAULT '',
			notes text NULL,
			consent_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email)
		) $c;" );

		dbDelta( "CREATE TABLE $loans (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bike_id bigint(20) unsigned NOT NULL DEFAULT 0,
			rider_id bigint(20) unsigned NOT NULL DEFAULT 0,
			start_date date NOT NULL,
			end_date date NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'requested',
			plan text NULL,
			notes text NULL,
			source varchar(20) NOT NULL DEFAULT 'web',
			handoff_from bigint(20) unsigned DEFAULT NULL,
			requested_size varchar(10) NOT NULL DEFAULT '',
			token varchar(40) NOT NULL DEFAULT '',
			reminder_sent tinyint(1) NOT NULL DEFAULT 0,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			race tinyint(1) NOT NULL DEFAULT 0,
			handoff_place varchar(190) NOT NULL DEFAULT '',
			handoff_time varchar(80) NOT NULL DEFAULT '',
			handoff_note text NULL,
			check_sent tinyint(1) NOT NULL DEFAULT 0,
			waitlisted_at datetime DEFAULT NULL,
			wl_any tinyint(1) NOT NULL DEFAULT 0,
			offer_until datetime DEFAULT NULL,
			offer_group varchar(40) NOT NULL DEFAULT '',
			offer_lapsed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY bike_dates (bike_id,start_date,end_date),
			KEY status (status),
			KEY rider (rider_id),
			KEY event (event_id)
		) $c;" );

		dbDelta( "CREATE TABLE $log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(20) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(40) NOT NULL DEFAULT '',
			message text NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY object (object_type,object_id)
		) $c;" );

		CC_Fleet_Records::create_tables( $c );
		CC_Fleet_Parts::create_table( $c );
		CC_Fleet_Expenses::create_table( $c );
		CC_Fleet_Outbox::create_table();
	}

	public static function add_roles() {
		$caps = array( 'read' => true, 'manage_cc_fleet' => true, 'upload_files' => true,
			'edit_cc_bikes' => true, 'edit_others_cc_bikes' => true, 'publish_cc_bikes' => true,
			'read_private_cc_bikes' => true, 'delete_cc_bikes' => true, 'edit_published_cc_bikes' => true,
			'delete_published_cc_bikes' => true );
		if ( ! get_role( 'cc_fleet_manager' ) ) {
			add_role( 'cc_fleet_manager', 'Fleet Manager', $caps );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( $caps as $cap => $grant ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/** One-off: rewrite riders' phone numbers as +<country><number> (South Africa when no code was given). Unreadable ones stay as they were. */
	public static function normalize_phones() {
		global $wpdb;
		$t       = CC_Fleet_Riders::table();
		$changed = 0;
		foreach ( $wpdb->get_results( "SELECT id, phone FROM $t WHERE phone <> ''" ) as $r ) {
			$clean = CC_Fleet_Phone::clean( $r->phone );
			if ( $clean !== $r->phone ) {
				$wpdb->update( $t, array( 'phone' => CC_Fleet_Phone::is_e164( $clean ) ? $clean : ( preg_match( '/^(n\/?a|-|none)$/i', trim( $r->phone ) ) ? '' : $r->phone ) ), array( 'id' => $r->id ) );
				$changed++;
			}
		}
		self::log( 'riders', 0, 'phones', $changed . ' phone numbers rewritten in international format' );
		return $changed;
	}

	public static function log( $type, $id, $action, $message = '' ) {
		global $wpdb;
		$wpdb->insert( self::table( 'log' ), array(
			'object_type' => $type, 'object_id' => (int) $id, 'action' => $action,
			'message' => $message, 'user_id' => get_current_user_id(), 'created_at' => current_time( 'mysql' ),
		) );
	}
}
