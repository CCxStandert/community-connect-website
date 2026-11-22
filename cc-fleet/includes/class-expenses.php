<?php
defined( 'ABSPATH' ) || exit;

/**
 * Project expenses: workshop invoices, parts, builds, rider support.
 *
 * Every expense keeps its invoice file in a private folder (not the Media Library, so it never
 * gets a public address) and can be linked to one or more bikes, which then show it in their
 * history. Invoices can be dropped in bulk: files are matched to expenses by invoice number,
 * anything unknown becomes a new expense to fill in.
 */
class CC_Fleet_Expenses {

	const CATEGORIES = array(
		'repair'   => 'Repair & service',
		'parts'    => 'Parts & components',
		'build'    => 'Bike build',
		'wheels'   => 'Wheels & tyres',
		'support'  => 'Rider support',
		'supplies' => 'Workshop supplies',
		'other'    => 'Other',
	);
	const SUPPLIERS = array( 'Woodstock Cycleworks', 'Hatton’s Cycle Workshop' );
	const CAP       = 'manage_cc_fleet';

	public static function table() {
		return CC_Fleet_Install::table( 'expenses' );
	}

	public static function create_table( $c ) {
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			exp_date date NOT NULL,
			supplier varchar(190) NOT NULL DEFAULT '',
			invoice_no varchar(60) NOT NULL DEFAULT '',
			description text NULL,
			category varchar(20) NOT NULL DEFAULT 'repair',
			bikes varchar(255) NOT NULL DEFAULT '',
			amount decimal(10,2) NOT NULL DEFAULT 0,
			vat decimal(10,2) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'unpaid',
			paid_on date DEFAULT NULL,
			paid_by varchar(190) NOT NULL DEFAULT '',
			recover_amount decimal(10,2) NOT NULL DEFAULT 0,
			recover_from varchar(190) NOT NULL DEFAULT '',
			recovered tinyint(1) NOT NULL DEFAULT 0,
			file varchar(255) NOT NULL DEFAULT '',
			file_name varchar(255) NOT NULL DEFAULT '',
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY exp_date (exp_date),
			KEY invoice (invoice_no)
		) $c;" );
	}

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 25 );
			add_action( 'admin_post_cc_fleet_expense_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'admin_post_cc_fleet_expense_delete', array( __CLASS__, 'handle_delete' ) );
			add_action( 'admin_post_cc_fleet_expense_file', array( __CLASS__, 'handle_file' ) );
			add_action( 'admin_post_cc_fleet_expense_drop', array( __CLASS__, 'handle_drop' ) );
			add_action( 'admin_post_cc_fleet_expense_csv', array( __CLASS__, 'handle_csv' ) );
		}
	}

	/* ---------------- Read ---------------- */

	public static function get( $id ) {
		global $wpdb;
		return $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ) : null;
	}

	/** @param array $f year, supplier, status, category, bike */
	public static function query( array $f = array() ) {
		global $wpdb;
		$w = array( '1=1' );
		$a = array();
		if ( ! empty( $f['year'] ) ) {
			$w[] = 'exp_date BETWEEN %s AND %s';
			$a[] = (int) $f['year'] . '-01-01';
			$a[] = (int) $f['year'] . '-12-31';
		}
		foreach ( array( 'supplier', 'status', 'category' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$w[] = "$k = %s";
				$a[] = $f[ $k ];
			}
		}
		if ( ! empty( $f['bike'] ) ) {
			$w[] = "CONCAT(',', bikes, ',') LIKE %s";
			$a[] = '%,' . (int) $f['bike'] . ',%';
		}
		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $w ) . ' ORDER BY exp_date DESC, id DESC LIMIT 2000';
		return $a ? $wpdb->get_results( $wpdb->prepare( $sql, $a ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
	}

	public static function bike_ids( $e ) {
		return array_values( array_filter( array_map( 'intval', explode( ',', (string) $e->bikes ) ) ) );
	}

	public static function money( $v ) {
		return 'R ' . number_format( (float) $v, 2, '.', ' ' );
	}

	/** "R 2 210,00", "1,529.30", "1.234,56", "2210" → a number. */
	public static function parse_money( $v ) {
		$v = preg_replace( '/[^0-9,.\-]/', '', str_replace( "\xc2\xa0", '', (string) $v ) );
		if ( false !== strpos( $v, ',' ) && false !== strpos( $v, '.' ) ) {
			$v = strrpos( $v, ',' ) > strrpos( $v, '.' ) ? str_replace( array( '.', ',' ), array( '', '.' ), $v ) : str_replace( ',', '', $v );
		} elseif ( false !== strpos( $v, ',' ) ) {
			$v = preg_match( '/,\d{1,2}$/', $v ) ? str_replace( ',', '.', $v ) : str_replace( ',', '', $v );
		}
		return round( (float) $v, 2 );
	}

	/** Invoice numbers compared loosely: "WCW0000414", "wcw0000414", "INV1594-2" → "wcw0000414", "inv1594". */
	public static function norm_invoice( $s ) {
		$s = strtolower( (string) $s );
		if ( preg_match( '/(wcw\s*0*\d+|inv\s*-?\s*\d+)/', $s, $m ) ) {
			$s = $m[1];
		}
		$s = preg_replace( '/[^a-z0-9]/', '', $s );
		return preg_replace( '/^([a-z]+)0*(\d+)$/', '$1$2', $s );
	}

	/* ---------------- Write ---------------- */

	public static function save( array $in, $id = 0 ) {
		global $wpdb;
		$old = $id ? self::get( $id ) : null;
		$num = function ( $k ) use ( $in, $old ) {
			$v = array_key_exists( $k, $in ) ? (string) $in[ $k ] : (string) ( $old->$k ?? '0' );
			return self::parse_money( $v );
		};
		$bikes = $in['bikes'] ?? ( $old ? self::bike_ids( $old ) : array() );
		$bikes = is_array( $bikes ) ? $bikes : explode( ',', (string) $bikes );
		$row   = array(
			'exp_date'       => CC_Fleet_Import::date( (string) ( $in['exp_date'] ?? ( $old->exp_date ?? '' ) ) ) ?: CC_Fleet_Settings::today(),
			'supplier'       => sanitize_text_field( wp_unslash( $in['supplier'] ?? ( $old->supplier ?? '' ) ) ),
			'invoice_no'     => sanitize_text_field( wp_unslash( $in['invoice_no'] ?? ( $old->invoice_no ?? '' ) ) ),
			'description'    => sanitize_textarea_field( wp_unslash( $in['description'] ?? ( $old->description ?? '' ) ) ),
			'category'       => isset( self::CATEGORIES[ $in['category'] ?? '' ] ) ? $in['category'] : ( $old->category ?? 'repair' ),
			'bikes'          => implode( ',', array_unique( array_filter( array_map( 'absint', $bikes ) ) ) ),
			'amount'         => $num( 'amount' ),
			'vat'            => $num( 'vat' ),
			'status'         => in_array( $in['status'] ?? '', array( 'unpaid', 'paid' ), true ) ? $in['status'] : ( $old->status ?? 'unpaid' ),
			'paid_on'        => CC_Fleet_Import::date( (string) ( $in['paid_on'] ?? ( $old->paid_on ?? '' ) ) ) ?: null,
			'paid_by'        => sanitize_text_field( wp_unslash( $in['paid_by'] ?? ( $old->paid_by ?? '' ) ) ),
			'recover_amount' => $num( 'recover_amount' ),
			'recover_from'   => sanitize_text_field( wp_unslash( $in['recover_from'] ?? ( $old->recover_from ?? '' ) ) ),
			'recovered'      => array_key_exists( 'recovered', $in ) ? ( empty( $in['recovered'] ) ? 0 : 1 ) : (int) ( $old->recovered ?? 0 ),
			'notes'          => sanitize_textarea_field( wp_unslash( $in['notes'] ?? ( $old->notes ?? '' ) ) ),
			'updated_at'     => current_time( 'mysql' ),
		);
		if ( 'paid' === $row['status'] && ! $row['paid_on'] ) {
			$row['paid_on'] = CC_Fleet_Settings::today();
		}
		if ( 'unpaid' === $row['status'] ) {
			$row['paid_on'] = null;
		}
		if ( '' === $row['supplier'] && '' === $row['description'] && '' === $row['invoice_no'] && ! $old ) {
			return new WP_Error( 'cc_expense', 'Add at least a supplier, an invoice number or a description.' );
		}
		if ( $old ) {
			if ( false === $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) ) ) {
				return new WP_Error( 'cc_db', 'Could not save: ' . $wpdb->last_error );
			}
		} else {
			$row['created_at'] = $row['updated_at'];
			if ( ! $wpdb->insert( self::table(), $row ) ) {
				return new WP_Error( 'cc_db', 'Could not save: ' . $wpdb->last_error );
			}
			$id = (int) $wpdb->insert_id;
		}
		CC_Fleet_Install::log( 'expense', $id, $old ? 'saved' : 'created', $row['supplier'] . ' ' . $row['invoice_no'] . ' ' . self::money( $row['amount'] ) . ' ' . $row['status'] );
		return (int) $id;
	}

	/* ---------------- Invoice files (private) ---------------- */

	public static function dir() {
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'cc-fleet-private/invoices';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$base = dirname( $dir );
		if ( ! file_exists( $base . '/.htaccess' ) ) {
			@file_put_contents( $base . '/.htaccess', "# Invoices: only through the fleet manager.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
		}
		foreach ( array( $base, $dir ) as $d ) {
			if ( ! file_exists( $d . '/index.php' ) ) {
				@file_put_contents( $d . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore
			}
		}
		return $dir;
	}

	const FILE_TYPES = array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'heic' => 'image/heic', 'webp' => 'image/webp' );

	/** Only real PDFs and photos, up to 20 MB. */
	public static function check_file( $tmp_path, $original_name ) {
		$ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( ! isset( self::FILE_TYPES[ $ext ] ) || ! is_readable( $tmp_path ) || filesize( $tmp_path ) > 20 * MB_IN_BYTES ) {
			return new WP_Error( 'cc_file', 'Invoices can be PDF or a photo (JPG, PNG, HEIC, WebP), up to 20 MB.' );
		}
		if ( 'pdf' === $ext ) {
			$head = (string) file_get_contents( $tmp_path, false, null, 0, 5 ); // phpcs:ignore
			if ( '%PDF-' !== $head ) {
				return new WP_Error( 'cc_file', basename( $original_name ) . ' is not a PDF.' );
			}
		} elseif ( function_exists( 'getimagesize' ) && 'heic' !== $ext && ! @getimagesize( $tmp_path ) ) { // phpcs:ignore
			return new WP_Error( 'cc_file', basename( $original_name ) . ' is not an image.' );
		}
		return true;
	}

	/** Store a file (path on disk) for an expense; replaces an older one. */
	public static function attach( $id, $tmp_path, $original_name ) {
		global $wpdb;
		$ok = self::check_file( $tmp_path, $original_name );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		$name = sprintf( 'exp%d-%s.%s', $id, wp_generate_password( 12, false ), $ext );
		$dest = self::dir() . '/' . $name;
		if ( ! @copy( $tmp_path, $dest ) ) { // phpcs:ignore
			return new WP_Error( 'cc_file', 'Could not store the file.' );
		}
		$old = self::get( $id );
		if ( $old && $old->file && file_exists( self::dir() . '/' . basename( $old->file ) ) ) {
			wp_delete_file( self::dir() . '/' . basename( $old->file ) );
		}
		$wpdb->update( self::table(), array( 'file' => $name, 'file_name' => sanitize_file_name( wp_basename( $original_name ) ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) );
		CC_Fleet_Install::log( 'expense', $id, 'file', sanitize_file_name( wp_basename( $original_name ) ) );
		return true;
	}

	public static function file_url( $e ) {
		return $e->file ? wp_nonce_url( admin_url( 'admin-post.php?action=cc_fleet_expense_file&id=' . (int) $e->id ), 'cc_fleet_expense_file_' . $e->id ) : '';
	}

	public static function handle_file() {
		$id = absint( $_GET['id'] ?? 0 );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'cc_fleet_expense_file_' . $id );
		$e    = self::get( $id );
		$path = $e && $e->file ? self::dir() . '/' . basename( $e->file ) : '';
		if ( ! $path || ! file_exists( $path ) ) {
			wp_die( 'File not found.', 404 );
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		nocache_headers();
		header( 'Content-Type: ' . ( self::FILE_TYPES[ $ext ] ?? 'application/octet-stream' ) );
		header( 'Content-Disposition: inline; filename="' . str_replace( '"', '', $e->file_name ?: basename( $path ) ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore
		exit;
	}

	/**
	 * Bulk drop: PDFs/photos (or a zip of them). Each file is matched to an expense by the invoice
	 * number in its name (WCW0000414, INV1594…). Files that match nothing become new, unpaid
	 * expenses marked "to check", with the file attached.
	 *
	 * @param array $files [[tmp_path, original_name], …]
	 * @return array matched, created, errors
	 */
	public static function drop( array $files ) {
		$res = array( 'matched' => array(), 'created' => array(), 'skipped' => array(), 'errors' => array() );
		$all = self::query();
		foreach ( self::expand_zips( $files, $res ) as $f ) {
			list( $path, $name ) = $f;
			$valid = self::check_file( $path, $name );
			if ( is_wp_error( $valid ) ) {
				$res['errors'][] = $name . ': ' . $valid->get_error_message();
				continue;
			}
			$key = self::norm_invoice( $name );
			$hit = null;
			// From a chat export, only files named like an invoice count (other PDFs are skipped).
			if ( ! empty( $f[2] ) && ! preg_match( '/^(wcw|inv)\d+$/', $key ) ) {
				$res['skipped'][] = $name;
				continue;
			}
			if ( preg_match( '/^(wcw|inv)\d+$/', $key ) ) {
				foreach ( $all as $e ) {
					if ( $e->invoice_no && self::norm_invoice( $e->invoice_no ) === $key ) {
						$hit = $e;
						break;
					}
				}
			}
			if ( ! $hit ) {
				$guess = array(
					'supplier'    => 0 === strpos( $key, 'wcw' ) ? 'Woodstock Cycleworks' : '',
					'invoice_no'  => preg_match( '/^(wcw|inv)\d+$/', $key ) ? strtoupper( $key ) : '',
					'description' => 'To check: ' . sanitize_file_name( $name ),
					'status'      => 'unpaid',
					'notes'       => 'Created from a dropped file. Fill in the date, amount and bikes.',
				);
				$id = self::save( $guess );
				if ( is_wp_error( $id ) ) {
					$res['errors'][] = $name . ': ' . $id->get_error_message();
					continue;
				}
				$hit              = self::get( $id );
				$res['created'][] = $name;
				$all[]            = $hit;
			} else {
				$res['matched'][] = $name . ' → ' . $hit->invoice_no;
			}
			$ok = self::attach( $hit->id, $path, $name );
			if ( is_wp_error( $ok ) ) {
				$res['errors'][] = $name . ': ' . $ok->get_error_message();
			}
		}
		return $res;
	}

	private static function expand_zips( array $files, array &$res ) {
		$out = array();
		foreach ( $files as $f ) {
			if ( 'zip' !== strtolower( pathinfo( $f[1], PATHINFO_EXTENSION ) ) ) {
				$out[] = $f;
				continue;
			}
			if ( ! class_exists( 'ZipArchive' ) ) {
				$res['errors'][] = $f[1] . ': zip files are not supported on this server; drop the PDFs instead.';
				continue;
			}
			$zip = new ZipArchive();
			if ( true !== $zip->open( $f[0] ) ) {
				$res['errors'][] = $f[1] . ': could not open the zip.';
				continue;
			}
			for ( $i = 0; $i < min( $zip->numFiles, 200 ); $i++ ) {
				$n   = $zip->getNameIndex( $i );
				$ext = strtolower( pathinfo( $n, PATHINFO_EXTENSION ) );
				if ( substr( $n, -1 ) === '/' || false !== strpos( $n, '__MACOSX' ) || ! in_array( $ext, array( 'pdf' ), true ) ) {
					continue; // From a chat export only the PDFs are invoices; photos stay out.
				}
				$tmp = wp_tempnam( 'cc-inv' );
				file_put_contents( $tmp, $zip->getFromIndex( $i ) ); // phpcs:ignore
				$out[] = array( $tmp, wp_basename( $n ), true );
			}
			$zip->close();
		}
		return $out;
	}

	public static function handle_drop() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'cc_fleet_expense_drop' );
		$files = array();
		if ( ! empty( $_FILES['invoices']['name'] ) && is_array( $_FILES['invoices']['name'] ) ) {
			foreach ( $_FILES['invoices']['name'] as $i => $n ) {
				if ( UPLOAD_ERR_OK === (int) $_FILES['invoices']['error'][ $i ] && is_uploaded_file( $_FILES['invoices']['tmp_name'][ $i ] ) ) {
					$files[] = array( $_FILES['invoices']['tmp_name'][ $i ], wp_unslash( $n ) );
				}
			}
		}
		$res = self::drop( $files );
		$msg = sprintf( '%d invoice%s attached to their expense, %d new expense%s to check.', count( $res['matched'] ), 1 === count( $res['matched'] ) ? '' : 's', count( $res['created'] ), 1 === count( $res['created'] ) ? '' : 's' );
		if ( $res['skipped'] ) {
			$msg .= ' Skipped (not invoices): ' . implode( ', ', array_slice( $res['skipped'], 0, 5 ) ) . '.';
		}
		if ( $res['errors'] ) {
			$msg .= ' Problems: ' . implode( '; ', array_slice( $res['errors'], 0, 5 ) );
		}
		self::notice( $msg, $res['errors'] ? 'warning' : 'success' );
		wp_safe_redirect( self::url() );
		exit;
	}

	/* ---------------- Admin ---------------- */

	public static function menu() {
		add_submenu_page( 'cc-fleet', 'Expenses', 'Expenses', self::CAP, 'cc-fleet-expenses', array( __CLASS__, 'page' ) );
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'cc-fleet-expenses' ), $args ), admin_url( 'admin.php' ) );
	}

	private static function notice( $msg, $type = 'success' ) {
		set_transient( 'cc_fleet_exp_notice_' . get_current_user_id(), array( $msg, $type ), 120 );
	}

	private static function show_notice() {
		$n = get_transient( 'cc_fleet_exp_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'cc_fleet_exp_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[1] ), esc_html( $n[0] ) );
		}
	}

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['edit'] ) ) {
			self::page_edit( absint( $_GET['edit'] ) );
			return;
		}
		$f = array(
			'year'     => isset( $_GET['year'] ) ? absint( $_GET['year'] ) : (int) wp_date( 'Y' ),
			'supplier' => sanitize_text_field( wp_unslash( $_GET['supplier'] ?? '' ) ),
			'status'   => sanitize_key( $_GET['status'] ?? '' ),
			'category' => sanitize_key( $_GET['category'] ?? '' ),
			'bike'     => absint( $_GET['bike'] ?? 0 ),
		);
		// phpcs:enable
		$rows = self::query( $f );
		echo '<div class="wrap cc-wrap"><h1 class="wp-heading-inline">Expenses</h1> ';
		printf( '<a class="page-title-action" href="%s">+ Add expense</a> <a class="page-title-action" href="%s">Download CSV</a><hr class="wp-header-end">', esc_url( self::url( array( 'edit' => 0 ) ) ), esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'cc_fleet_expense_csv' ), array_filter( $f ) ), admin_url( 'admin-post.php' ) ), 'cc_fleet_expense_csv' ) ) );
		self::show_notice();

		// Totals.
		$total = $unpaid = $vat = $recover = 0;
		$by_sup = $by_cat = $by_bike = array();
		foreach ( $rows as $e ) {
			$total += $e->amount;
			$vat   += $e->vat;
			if ( 'unpaid' === $e->status ) {
				$unpaid += $e->amount;
			}
			if ( $e->recover_amount > 0 && ! $e->recovered ) {
				$recover += $e->recover_amount;
			}
			$by_sup[ $e->supplier ?: '—' ] = ( $by_sup[ $e->supplier ?: '—' ] ?? 0 ) + $e->amount;
			$by_cat[ $e->category ]       = ( $by_cat[ $e->category ] ?? 0 ) + $e->amount;
			$ids = self::bike_ids( $e );
			foreach ( $ids as $b ) {
				$by_bike[ $b ] = ( $by_bike[ $b ] ?? 0 ) + $e->amount / max( 1, count( $ids ) );
			}
		}
		echo '<div class="cc-dash">';
		printf( '<section class="cc-card"><h2>%s</h2><p class="cc-big">%s</p><p class="cc-muted">%d invoices · VAT included %s</p></section>', esc_html( $f['year'] ? 'Spent in ' . $f['year'] : 'Spent, all years' ), esc_html( self::money( $total ) ), count( $rows ), esc_html( self::money( $vat ) ) );
		printf( '<section class="cc-card"><h2>Still to pay</h2><p class="cc-big%s">%s</p><p class="cc-muted">%s</p></section>', $unpaid > 0 ? ' cc-warn' : '', esc_html( self::money( $unpaid ) ), $recover > 0 ? 'To recover from riders: ' . esc_html( self::money( $recover ) ) : 'Nothing to recover from riders.' );
		echo '<section class="cc-card"><h2>By supplier</h2><ul class="cc-list">';
		arsort( $by_sup );
		foreach ( $by_sup as $s => $v ) {
			printf( '<li>%s <span class="cc-muted">%s</span></li>', esc_html( $s ), esc_html( self::money( $v ) ) );
		}
		echo '</ul></section><section class="cc-card"><h2>By kind</h2><ul class="cc-list">';
		arsort( $by_cat );
		foreach ( $by_cat as $c => $v ) {
			printf( '<li>%s <span class="cc-muted">%s</span></li>', esc_html( self::CATEGORIES[ $c ] ?? $c ), esc_html( self::money( $v ) ) );
		}
		echo '</ul></section>';
		if ( $by_bike ) {
			arsort( $by_bike );
			echo '<section class="cc-card"><h2>By bike</h2><ul class="cc-list">';
			foreach ( $by_bike as $b => $v ) {
				printf( '<li><a href="%s">%s</a> <span class="cc-muted">%s</span></li>', esc_url( self::url( array_merge( $f, array( 'bike' => $b ) ) ) ), esc_html( CC_Fleet_Bikes::get( $b, 'cc_id' ) ), esc_html( self::money( $v ) ) );
			}
			echo '</ul><p class="cc-muted">Invoices covering several bikes are split evenly.</p></section>';
		}
		echo '</div>';

		// Drop zone.
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cc-card cc-drop">';
		wp_nonce_field( 'cc_fleet_expense_drop' );
		echo '<input type="hidden" name="action" value="cc_fleet_expense_drop"><h2>Drop invoices</h2><p>PDFs or photos, several at once, or a WhatsApp chat export (.zip). Each file is matched to its expense by the invoice number in the file name (WCW0000414, INV1594…); anything new becomes an expense to fill in. Files are stored privately: only the crew can open them.</p>';
		echo '<p><input type="file" name="invoices[]" multiple accept=".pdf,.jpg,.jpeg,.png,.heic,.webp,.zip" required> <button class="button button-primary">Upload</button></p></form>';

		// Filters.
		$years = range( (int) wp_date( 'Y' ) + 1, 2025 );
		echo '<form method="get" class="cc-filters"><input type="hidden" name="page" value="cc-fleet-expenses">';
		echo '<select name="year"><option value="0">All years</option>';
		foreach ( $years as $y ) {
			printf( '<option value="%d"%s>%d</option>', $y, selected( $f['year'], $y, false ), $y );
		}
		echo '</select> <select name="supplier"><option value="">All suppliers</option>';
		foreach ( array_unique( array_merge( self::SUPPLIERS, wp_list_pluck( self::query(), 'supplier' ) ) ) as $s ) {
			if ( $s ) {
				printf( '<option%s>%s</option>', selected( $f['supplier'], $s, false ), esc_html( $s ) );
			}
		}
		echo '</select> <select name="status"><option value="">Paid &amp; unpaid</option>';
		foreach ( array( 'unpaid' => 'Unpaid', 'paid' => 'Paid' ) as $k => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $f['status'], $k, false ), esc_html( $l ) );
		}
		echo '</select> <select name="category"><option value="">All kinds</option>';
		foreach ( self::CATEGORIES as $k => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $f['category'], $k, false ), esc_html( $l ) );
		}
		echo '</select> <select name="bike"><option value="0">All bikes</option>';
		foreach ( CC_Fleet_Bikes::all_ids() as $b ) {
			printf( '<option value="%d"%s>%s</option>', $b, selected( $f['bike'], $b, false ), esc_html( CC_Fleet_Bikes::label( $b ) ) );
		}
		echo '</select> <button class="button">Filter</button></form>';

		echo '<table class="widefat striped cc-expenses"><thead><tr><th style="width:95px">Date</th><th>Supplier · invoice</th><th>What</th><th>Bikes</th><th style="text-align:right">Amount</th><th>Status</th><th>Invoice</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7" class="cc-empty">No expenses here yet.</td></tr>';
		}
		foreach ( $rows as $e ) {
			$bikes = implode( ', ', array_map( function ( $b ) { return CC_Fleet_Bikes::get( $b, 'cc_id' ); }, self::bike_ids( $e ) ) );
			$st    = 'paid' === $e->status ? '<span class="cc-badge cc-badge--returned">Paid</span>' . ( $e->paid_on ? ' <span class="cc-muted">' . esc_html( wp_date( 'j M', strtotime( $e->paid_on . ' 12:00' ) ) ) . '</span>' : '' ) : '<span class="cc-badge cc-badge--declined">Unpaid</span>';
			if ( $e->recover_amount > 0 ) {
				$st .= '<br><small>' . ( $e->recovered ? '✓ ' : '' ) . 'Recover ' . esc_html( self::money( $e->recover_amount ) ) . ( $e->recover_from ? ' from ' . esc_html( $e->recover_from ) : '' ) . '</small>';
			}
			printf( '<tr><td>%s</td><td><a href="%s"><strong>%s</strong></a><br><span class="cc-muted">%s</span></td><td>%s<br><span class="cc-muted">%s</span></td><td>%s</td><td style="text-align:right;white-space:nowrap">%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( wp_date( 'j M Y', strtotime( $e->exp_date . ' 12:00' ) ) ), esc_url( self::url( array( 'edit' => $e->id ) ) ), esc_html( $e->supplier ?: '—' ), esc_html( $e->invoice_no ),
				esc_html( wp_trim_words( (string) $e->description, 16 ) ), esc_html( self::CATEGORIES[ $e->category ] ?? '' ), esc_html( $bikes ?: '—' ), esc_html( self::money( $e->amount ) ), $st, // phpcs:ignore
				$e->file ? '<a href="' . esc_url( self::file_url( $e ) ) . '" target="_blank" rel="noopener">Open</a>' : '<span class="cc-muted">missing</span>' );
		}
		printf( '</tbody><tfoot><tr><th colspan="4">Total</th><th style="text-align:right;white-space:nowrap">%s</th><th colspan="2"></th></tr></tfoot></table></div>', esc_html( self::money( $total ) ) );
	}

	private static function page_edit( $id ) {
		$e = $id ? self::get( $id ) : null;
		echo '<div class="wrap cc-wrap"><h1>' . esc_html( $e ? trim( $e->supplier . ' ' . $e->invoice_no ) : 'Add expense' ) . '</h1>';
		self::show_notice();
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cc-form">';
		wp_nonce_field( 'cc_fleet_expense_save' );
		printf( '<input type="hidden" name="action" value="cc_fleet_expense_save"><input type="hidden" name="id" value="%d">', (int) $id );
		echo '<datalist id="cc-suppliers">';
		foreach ( array_unique( array_merge( self::SUPPLIERS, wp_list_pluck( self::query(), 'supplier' ) ) ) as $s ) {
			printf( '<option value="%s">', esc_attr( $s ) );
		}
		echo '</datalist><table class="form-table" role="presentation">';
		printf( '<tr><th><label for="cc-e-date">Invoice date</label></th><td><input type="date" id="cc-e-date" name="exp_date" value="%s" required></td></tr>', esc_attr( $e->exp_date ?? CC_Fleet_Settings::today() ) );
		printf( '<tr><th><label for="cc-e-sup">Supplier</label></th><td><input id="cc-e-sup" name="supplier" list="cc-suppliers" class="regular-text" value="%s"> <label>Invoice no. <input name="invoice_no" value="%s" style="width:140px"></label></td></tr>', esc_attr( $e->supplier ?? '' ), esc_attr( $e->invoice_no ?? '' ) );
		printf( '<tr><th><label for="cc-e-desc">What for</label></th><td><textarea id="cc-e-desc" name="description" rows="3" class="large-text">%s</textarea></td></tr>', esc_textarea( $e->description ?? '' ) );
		echo '<tr><th><label for="cc-e-cat">Kind</label></th><td><select id="cc-e-cat" name="category">';
		foreach ( self::CATEGORIES as $k => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $e->category ?? 'repair', $k, false ), esc_html( $l ) );
		}
		echo '</select></td></tr><tr><th>Bikes</th><td><div class="cc-bike-ticks">';
		$sel = $e ? self::bike_ids( $e ) : array();
		foreach ( CC_Fleet_Bikes::all_ids() as $b ) {
			printf( '<label><input type="checkbox" name="bikes[]" value="%d"%s> %s</label> ', $b, checked( in_array( $b, $sel, true ), true, false ), esc_html( CC_Fleet_Bikes::get( $b, 'cc_id' ) ) );
		}
		echo '</div><p class="description">Leave empty for general costs. Linked bikes show this invoice in their history.</p></td></tr>';
		printf( '<tr><th><label for="cc-e-amt">Amount (incl. VAT)</label></th><td>R <input type="text" inputmode="decimal" id="cc-e-amt" name="amount" value="%s" style="width:120px"> &nbsp; VAT included: R <input type="text" inputmode="decimal" name="vat" value="%s" style="width:100px"></td></tr>', esc_attr( $e ? number_format( (float) $e->amount, 2, '.', '' ) : '' ), esc_attr( $e ? number_format( (float) $e->vat, 2, '.', '' ) : '' ) );
		printf( '<tr><th>Paid</th><td><select name="status"><option value="unpaid"%s>Unpaid</option><option value="paid"%s>Paid</option></select> on <input type="date" name="paid_on" value="%s"> by <input name="paid_by" value="%s" placeholder="e.g. Lobster Frame Works" class="regular-text" style="max-width:240px"></td></tr>',
			selected( $e->status ?? 'unpaid', 'unpaid', false ), selected( $e->status ?? '', 'paid', false ), esc_attr( $e->paid_on ?? '' ), esc_attr( $e->paid_by ?? '' ) );
		printf( '<tr><th>Rider pays part</th><td>R <input type="text" inputmode="decimal" name="recover_amount" value="%s" style="width:100px"> from <input name="recover_from" value="%s" placeholder="rider" style="width:200px"> <label><input type="checkbox" name="recovered" value="1"%s> received</label></td></tr>',
			esc_attr( $e && $e->recover_amount > 0 ? number_format( (float) $e->recover_amount, 2, '.', '' ) : '' ), esc_attr( $e->recover_from ?? '' ), checked( (int) ( $e->recovered ?? 0 ), 1, false ) );
		printf( '<tr><th>Invoice file</th><td>%s<input type="file" name="invoice" accept=".pdf,.jpg,.jpeg,.png,.heic,.webp"><p class="description">PDF or photo. Stored privately.</p></td></tr>', $e && $e->file ? '<p><a href="' . esc_url( self::file_url( $e ) ) . '" target="_blank" rel="noopener">' . esc_html( $e->file_name ?: 'Open invoice' ) . '</a> · upload a new one to replace it:</p>' : '' );
		printf( '<tr><th><label for="cc-e-notes">Notes</label></th><td><textarea id="cc-e-notes" name="notes" rows="3" class="large-text">%s</textarea></td></tr>', esc_textarea( $e->notes ?? '' ) );
		echo '</table>';
		submit_button( $e ? 'Save' : 'Add expense' );
		echo '</form>';
		if ( $e ) {
			printf( '<p><a class="submitdelete" style="color:#b32d2e" href="%s" onclick="return confirm(\'Delete this expense and its invoice file?\')">Delete expense</a></p>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cc_fleet_expense_delete&id=' . $e->id ), 'cc_fleet_expense_delete_' . $e->id ) ) );
		}
		printf( '<p><a href="%s">← All expenses</a></p></div>', esc_url( self::url() ) );
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'cc_fleet_expense_save' );
		$id   = absint( $_POST['id'] ?? 0 );
		$in   = $_POST; // phpcs:ignore -- sanitized in save().
		$in['bikes'] = array_map( 'absint', (array) ( $_POST['bikes'] ?? array() ) );
		$in['recovered'] = empty( $_POST['recovered'] ) ? 0 : 1;
		$res  = self::save( $in, $id );
		if ( is_wp_error( $res ) ) {
			self::notice( $res->get_error_message(), 'error' );
			wp_safe_redirect( self::url( array( 'edit' => $id ) ) );
			exit;
		}
		$msg = 'Saved.';
		if ( ! empty( $_FILES['invoice']['tmp_name'] ) && UPLOAD_ERR_OK === (int) $_FILES['invoice']['error'] && is_uploaded_file( $_FILES['invoice']['tmp_name'] ) ) {
			$ok  = self::attach( $res, $_FILES['invoice']['tmp_name'], wp_unslash( $_FILES['invoice']['name'] ) );
			$msg = is_wp_error( $ok ) ? 'Saved, but the file was not: ' . $ok->get_error_message() : 'Saved with the invoice.';
		}
		self::notice( $msg );
		wp_safe_redirect( self::url( array( 'edit' => $res ) ) );
		exit;
	}

	public static function handle_delete() {
		global $wpdb;
		$id = absint( $_GET['id'] ?? 0 );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'cc_fleet_expense_delete_' . $id );
		$e = self::get( $id );
		if ( $e && $e->file ) {
			wp_delete_file( self::dir() . '/' . basename( $e->file ) );
		}
		$wpdb->delete( self::table(), array( 'id' => $id ) );
		CC_Fleet_Install::log( 'expense', $id, 'deleted' );
		self::notice( 'Expense deleted.' );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function handle_csv() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'cc_fleet_expense_csv' );
		$f = array(
			'year'     => absint( $_GET['year'] ?? 0 ),
			'supplier' => sanitize_text_field( wp_unslash( $_GET['supplier'] ?? '' ) ),
			'status'   => sanitize_key( $_GET['status'] ?? '' ),
			'category' => sanitize_key( $_GET['category'] ?? '' ),
			'bike'     => absint( $_GET['bike'] ?? 0 ),
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="cc-expenses-' . ( $f['year'] ?: 'all' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore -- Excel reads UTF-8 with a BOM.
		fputcsv( $out, array( 'Date', 'Supplier', 'Invoice', 'What', 'Kind', 'Bikes', 'Amount incl VAT', 'VAT', 'Status', 'Paid on', 'Paid by', 'Rider pays', 'From', 'Received', 'Notes' ) );
		foreach ( self::query( $f ) as $e ) {
			$cells = array( $e->exp_date, $e->supplier, $e->invoice_no, $e->description, self::CATEGORIES[ $e->category ] ?? $e->category,
				implode( ' ', array_map( function ( $b ) { return CC_Fleet_Bikes::get( $b, 'cc_id' ); }, self::bike_ids( $e ) ) ),
				number_format( (float) $e->amount, 2, '.', '' ), number_format( (float) $e->vat, 2, '.', '' ), $e->status, $e->paid_on, $e->paid_by,
				$e->recover_amount > 0 ? number_format( (float) $e->recover_amount, 2, '.', '' ) : '', $e->recover_from, $e->recovered ? 'yes' : '', $e->notes );
			// Spreadsheet formula injection guard.
			$cells = array_map( function ( $c ) { $c = (string) $c; return preg_match( '/^[=+\-@]/', $c ) && ! is_numeric( $c ) ? "'" . $c : $c; }, $cells );
			fputcsv( $out, $cells );
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}

	/* ---------------- Elsewhere ---------------- */

	/** Rows for the bike's history box. */
	public static function history_items( $bike_id ) {
		$items = array();
		foreach ( self::query( array( 'bike' => $bike_id ) ) as $e ) {
			$items[] = array( 'date' => $e->exp_date, 'sort' => $e->created_at, 'kind' => 'expense', 'e' => $e );
		}
		return $items;
	}

	public static function history_row( $e, $date_html ) {
		$n = count( self::bike_ids( $e ) );
		printf( '<tr><td>%s</td><td><span class="cc-badge">Invoice</span> <a href="%s">%s %s</a> · %s%s <span class="cc-muted">%s%s</span></td><td>%s</td></tr>', $date_html,
			esc_url( self::url( array( 'edit' => $e->id ) ) ), esc_html( $e->supplier ), esc_html( $e->invoice_no ), esc_html( wp_trim_words( (string) $e->description, 14 ) ),
			'', esc_html( self::money( $e->amount ) ), $n > 1 ? ' (shared by ' . $n . ' bikes)' : '', 'paid' === $e->status ? 'Paid' : 'Unpaid' ); // phpcs:ignore
	}

	public static function dashboard_card() {
		$unpaid = self::query( array( 'status' => 'unpaid' ) );
		$year   = self::query( array( 'year' => (int) wp_date( 'Y' ) ) );
		echo '<section class="cc-card"><h2>Expenses</h2>';
		printf( '<p><strong>%s</strong> spent in %s · <a href="%s">all expenses</a></p>', esc_html( self::money( array_sum( wp_list_pluck( $year, 'amount' ) ) ) ), esc_html( wp_date( 'Y' ) ), esc_url( self::url() ) );
		if ( $unpaid ) {
			echo '<ul class="cc-list">';
			foreach ( array_slice( $unpaid, 0, 6 ) as $e ) {
				printf( '<li><a href="%s">%s %s</a> <span class="cc-badge cc-badge--declined">Unpaid</span> <span class="cc-muted">%s · %s</span></li>', esc_url( self::url( array( 'edit' => $e->id ) ) ), esc_html( $e->supplier ), esc_html( $e->invoice_no ), esc_html( self::money( $e->amount ) ), esc_html( wp_date( 'j M', strtotime( $e->exp_date . ' 12:00' ) ) ) );
			}
			echo '</ul>';
		} else {
			echo '<p class="cc-empty">All invoices paid.</p>';
		}
		echo '</section>';
	}
}
