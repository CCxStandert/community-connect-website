<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bikes: custom post type "cc_bike" with the full spec from the fleet sheet as post meta.
 */
class CC_Fleet_Bikes {

	const CPT = 'cc_bike';

	/**
	 * Field registry. key => [label, group, public?, sheet header]
	 * Public fields can appear on the website; the rest stay in the admin.
	 */
	public static function fields() {
		return array(
			'cc_id'            => array( 'Bike ID', 'frame', true, 'Bike ID' ),
			'model'            => array( 'Model', 'frame', true, 'Model' ),
			'model_year'       => array( 'Model year', 'frame', true, 'Model year' ),
			'size'             => array( 'Size', 'frame', true, 'Size' ),
			'category'         => array( 'Category', 'frame', true, 'Category' ),
			'colourway'        => array( 'Colourway', 'frame', true, 'Colourway' ),
			'owner'            => array( 'Owner', 'internal', false, 'Owner' ),
			'sheet_status'     => array( 'Sheet status', 'internal', false, 'Status' ),
			'sheet_rider'      => array( 'Assigned rider (sheet)', 'internal', false, 'Rider' ),
			'frame_condition'  => array( 'Frame condition', 'internal', false, 'Frame condition' ),
			'frame_notes'      => array( 'Frame notes', 'internal', false, 'Frame notes' ),
			'ask_2027'         => array( '2027 ask from Standert', 'internal', false, '2027 ask from Standert' ),
			'date_added'       => array( 'Date added', 'internal', false, 'Date added' ),
			'last_upgraded'    => array( 'Last upgraded', 'internal', false, 'Last upgraded' ),
			'tyre_clearance'   => array( 'Tyre clearance (mm)', 'wheels', true, 'Tyre clearance (mm)' ),
			'wheel_size'       => array( 'Wheel size', 'wheels', true, 'Wheel size' ),
			'wheelset'         => array( 'Wheelset', 'wheels', true, 'Wheelset (current)' ),
			'wheelset_incoming'=> array( 'Wheelset (incoming)', 'internal', false, 'Wheelset (incoming)' ),
			'freehub'          => array( 'Freehub', 'wheels', false, 'Freehub' ),
			'tyres'            => array( 'Tyres', 'wheels', true, 'Tyres' ),
			'tyre_size'        => array( 'Tyre size (mm)', 'wheels', true, 'Tyre size (mm)' ),
			'groupset'         => array( 'Groupset', 'drivetrain', true, 'Groupset' ),
			'brakes'           => array( 'Brakes', 'drivetrain', true, 'Brakes' ),
			'chainring'        => array( 'Chainring', 'drivetrain', true, 'Chainring' ),
			'cassette'         => array( 'Cassette', 'drivetrain', true, 'Cassette' ),
			'chain'            => array( 'Chain', 'drivetrain', false, 'Chain' ),
			'stem'             => array( 'Stem', 'cockpit', true, 'Stem' ),
			'handlebar'        => array( 'Handlebar', 'cockpit', true, 'Handlebar' ),
			'bar_tape'         => array( 'Bar tape', 'cockpit', false, 'Bar tape' ),
			'seatpost'         => array( 'Seatpost', 'cockpit', true, 'Seatpost' ),
			'saddle'           => array( 'Saddle', 'cockpit', true, 'Saddle' ),
			'replace_now'      => array( 'Replace now', 'internal', false, 'Replace now' ),
			'monitor'          => array( 'Monitor', 'internal', false, 'Monitor' ),
			'spec_complete'    => array( 'Spec complete', 'internal', false, 'Spec complete' ),
			'notes'            => array( 'Internal notes', 'internal', false, 'Notes' ),
		);
	}

	/** Website-only fields (not in the sheet). */
	public static function site_fields() {
		return array(
			'state'        => 'active',  // active | workshop | soon | retired
			'show_on_site' => '0',
			'public_name'  => '',
			'public_blurb' => '',
			'public_tag'   => '',
			'photos'       => '',
			'rider_min'    => '',
			'rider_max'    => '',
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'init', function () {
			if ( get_option( 'cc_fleet_migrate_slugs' ) ) {
				delete_option( 'cc_fleet_migrate_slugs' );
				self::migrate_slugs();
			}
			if ( get_option( 'cc_fleet_add_soon_bikes' ) ) {
				delete_option( 'cc_fleet_add_soon_bikes' );
				self::add_soon_bikes();
			}
		}, 30 );
		// On the website a bike is called by its model, size and name; the Bike ID stays in the admin.
		if ( ! is_admin() ) {
			add_filter( 'the_title', array( __CLASS__, 'public_title' ), 10, 2 );
			add_filter( 'single_post_title', array( __CLASS__, 'public_title' ), 10, 2 );
			add_filter( 'document_title_parts', function ( $parts ) {
				if ( is_singular( self::CPT ) ) {
					$parts['title'] = self::public_label( get_queried_object_id() );
				}
				return $parts;
			} );
		}
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . self::CPT, array( __CLASS__, 'meta_boxes' ) );
			add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'photo_assets' ) );
			add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
			add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		}
	}

	/** @param int|WP_Post $post */
	public static function public_title( $title, $post = 0 ) {
		$id = $post instanceof WP_Post ? $post->ID : (int) $post;
		return $id && self::CPT === get_post_type( $id ) ? self::public_label( $id ) : $title;
	}

	public static function register_post_type() {
		register_post_type( self::CPT, array(
			'labels' => array(
				'name' => 'Bikes', 'singular_name' => 'Bike', 'add_new_item' => 'Add bike',
				'edit_item' => 'Edit bike', 'all_items' => 'All bikes', 'menu_name' => 'Bikes',
			),
			'public'          => true,
			'show_in_menu'    => 'cc-fleet',
			'show_in_rest'    => true,
			'has_archive'     => false, // /fleet/ is a normal page (created on activation) so the design team can edit it.
			'rewrite'         => array( 'slug' => 'fleet', 'with_front' => false ),
			'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'capability_type' => array( 'cc_bike', 'cc_bikes' ),
			'map_meta_cap'    => true,
			'menu_icon'       => 'dashicons-admin-site-alt3',
		) );
	}

	public static function register_meta() {
		foreach ( array_keys( self::fields() + self::site_fields() ) as $key ) {
			register_post_meta( self::CPT, '_cc_' . $key, array(
				'type' => 'string', 'single' => true, 'show_in_rest' => false,
				'auth_callback' => function () { return current_user_can( 'manage_cc_fleet' ); },
			) );
		}
	}

	public static function get( $post_id, $key ) {
		$val = get_post_meta( $post_id, '_cc_' . $key, true );
		if ( '' === $val && isset( self::site_fields()[ $key ] ) ) {
			return self::site_fields()[ $key ];
		}
		return $val;
	}

	public static function set( $post_id, $key, $value ) {
		update_post_meta( $post_id, '_cc_' . $key, is_string( $value ) ? trim( $value ) : $value );
	}

	/**
	 * v0.22: the two new Kieswerks (52 and 56) arriving in 2027 go on the website as "Coming soon":
	 * shown with the fleet, not bookable. Switch them to Active on their page when they arrive.
	 */
	public static function add_soon_bikes() {
		$like = self::find_by_cc_id( 'CC-04' ); // Kieswerk 52: same model, same category.
		foreach ( array( array( 'CC-15', '52' ), array( 'CC-16', '56' ) ) as $b ) {
			if ( self::find_by_cc_id( $b[0] ) ) {
				continue;
			}
			$id = wp_insert_post( array( 'post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => 'Kieswerk ' . $b[1] . ' (2027)', 'post_name' => 'kieswerk-' . $b[1] . '-2027' ) );
			if ( ! $id || is_wp_error( $id ) ) {
				continue;
			}
			self::set( $id, 'cc_id', $b[0] );
			self::set( $id, 'model', 'Kieswerk' );
			self::set( $id, 'model_year', '2027' );
			self::set( $id, 'size', $b[1] );
			self::set( $id, 'category', $like ? (string) self::get( $like, 'category' ) : '' );
			self::set( $id, 'public_name', 'New for 2027' );
			self::set( $id, 'state', 'soon' );
			self::set( $id, 'show_on_site', '1' );
		}
	}

	public static function find_by_cc_id( $cc_id ) {
		$q = get_posts( array(
			'post_type' => self::CPT, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids',
			'meta_key' => '_cc_cc_id', 'meta_value' => strtoupper( trim( $cc_id ) ),
		) );
		return $q ? (int) $q[0] : 0;
	}

	/** Bikes shown on the website, ordered by Bike ID. */
	public static function public_ids() {
		$ids = get_posts( array(
			'post_type' => self::CPT, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids',
			'meta_key' => '_cc_show_on_site', 'meta_value' => '1',
		) );
		usort( $ids, function ( $a, $b ) { return strnatcmp( self::get( $a, 'cc_id' ), self::get( $b, 'cc_id' ) ); } );
		return array_map( 'intval', $ids );
	}

	public static function all_ids() {
		$ids = get_posts( array( 'post_type' => self::CPT, 'post_status' => array( 'publish', 'draft', 'private' ), 'numberposts' => -1, 'fields' => 'ids' ) );
		usort( $ids, function ( $a, $b ) { return strnatcmp( self::get( $a, 'cc_id' ), self::get( $b, 'cc_id' ) ); } );
		return array_map( 'intval', $ids );
	}

	public static function rider_range( $post_id ) {
		$min = (int) self::get( $post_id, 'rider_min' );
		$max = (int) self::get( $post_id, 'rider_max' );
		if ( $min && $max ) {
			return array( $min, $max );
		}
		$sizes = CC_Fleet_Settings::size_heights();
		$size  = (int) self::get( $post_id, 'size' );
		return $sizes[ $size ] ?? null;
	}

	/** Crew label with the internal Bike ID, e.g. "CC-12 Kettensäge 56". Admin, crew emails and the calendar feed only. */
	public static function label( $post_id ) {
		return trim( self::get( $post_id, 'cc_id' ) . ' ' . self::get( $post_id, 'model' ) . ' ' . self::get( $post_id, 'size' ) );
	}

	/**
	 * What riders see. The Bike ID (CC-12) is internal; the public name is the model and size,
	 * plus the bike's own name (Pang! Pink) where two bikes need telling apart.
	 */
	public static function public_short( $post_id ) {
		return trim( self::get( $post_id, 'model' ) . ' ' . self::get( $post_id, 'size' ) );
	}

	public static function public_label( $post_id ) {
		$name = trim( (string) ( self::get( $post_id, 'public_name' ) ?: self::get( $post_id, 'colourway' ) ) );
		return self::public_short( $post_id ) . ( '' !== $name ? ' · ' . $name : '' );
	}

	/** The Standert logo, inline so it takes the text colour. Shown where riders used to see the Bike ID. */
	public static function brand_svg( $class = 'cc-brand' ) {
		static $svg = null;
		if ( null === $svg ) {
			$svg = trim( (string) file_get_contents( CC_FLEET_DIR . 'assets/brand/standert.svg' ) ); // phpcs:ignore
		}
		return str_replace( '<svg ', '<svg class="' . esc_attr( $class ) . '" ', $svg );
	}

	/** Public web address part for a bike: model and size, never the Bike ID. */
	public static function public_slug( $post_id ) {
		return sanitize_title( self::public_short( $post_id ) );
	}

	/**
	 * v0.15: bike pages move from /fleet/cc-12/ to /fleet/kettensage-56/.
	 * WordPress keeps the old address and redirects it, so shared links keep working.
	 */
	public static function migrate_slugs() {
		foreach ( self::all_ids() as $id ) {
			$post = get_post( $id );
			$cc   = sanitize_title( (string) self::get( $id, 'cc_id' ) );
			if ( $post && '' !== $cc && $post->post_name === $cc && '' !== self::public_short( $id ) ) {
				wp_update_post( array( 'ID' => $id, 'post_name' => self::public_slug( $id ) ) );
			}
		}
	}

	/**
	 * Live state for the website: available | out | workshop | retired.
	 */
	/**
	 * A bike given to one rider long-term (their own or a team bike): blocked for everyone else
	 * from that date on, with no end date. Returns null when not assigned.
	 * @return array{rider_id:int,since:string,name:string}|null
	 */
	public static function assignment( $post_id ) {
		$rid = (int) self::get( $post_id, 'assigned_rider' );
		if ( ! $rid ) {
			return null;
		}
		$r = CC_Fleet_Riders::get( $rid );
		return array( 'rider_id' => $rid, 'since' => self::get( $post_id, 'assigned_since' ) ?: '2000-01-01', 'name' => $r ? $r->name : 'a rider' );
	}

	public static function state_now( $post_id ) {
		$state = self::get( $post_id, 'state' );
		if ( 'active' !== $state ) {
			return $state;
		}
		$a = self::assignment( $post_id );
		if ( $a && $a['since'] <= CC_Fleet_Settings::today() ) {
			return 'assigned';
		}
		return CC_Fleet_Loans::is_booked_on( $post_id, CC_Fleet_Settings::today() ) ? 'out' : 'available';
	}

	/** Data safe to publish: no owner, wear, notes or rider names. */
	public static function public_data( $post_id ) {
		$spec = array();
		foreach ( self::fields() as $key => $f ) {
			if ( $f[2] && ! in_array( $key, array( 'cc_id', 'model', 'model_year', 'size', 'category', 'colourway' ), true ) ) {
				$v = self::get( $post_id, $key );
				if ( '' !== $v ) {
					$spec[] = array( 'key' => $key, 'label' => $f[0], 'value' => $v );
				}
			}
		}
		$range  = self::rider_range( $post_id );
		$photos = self::photo_ids( $post_id );
		$img    = $photos ? wp_get_attachment_image_url( $photos[0], 'large' ) : '';
		return array(
			'id'          => $post_id,
			'cc_id'       => self::get( $post_id, 'cc_id' ),
			'name'        => self::get( $post_id, 'public_name' ) ?: self::get( $post_id, 'colourway' ),
			'model'       => self::get( $post_id, 'model' ),
			'model_year'  => self::get( $post_id, 'model_year' ),
			'size'        => self::get( $post_id, 'size' ),
			'category'    => self::get( $post_id, 'category' ),
			'colourway'   => self::get( $post_id, 'colourway' ),
			'blurb'       => self::get( $post_id, 'public_blurb' ),
			'tag'         => self::get( $post_id, 'public_tag' ),
			'photos'      => $photos,
			'rider_range' => $range,
			'state'       => 'assigned' === self::state_now( $post_id ) ? 'out' : self::state_now( $post_id ),
			'image'       => $img ?: null,
			'url'         => get_permalink( $post_id ),
			'spec'        => $spec,
		);
	}

	/* ---------------- Admin: edit screen ---------------- */

	/** Photo IDs in order: the Photos box first, then the featured image if it isn't in there. */
	public static function photo_ids( $post_id ) {
		$ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) self::get( $post_id, 'photos' ) ) ), 'wp_attachment_is_image' ) );
		$thumb = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb && ! in_array( $thumb, $ids, true ) ) {
			$ids[] = $thumb;
		}
		return $ids;
	}

	public static function photo_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::CPT !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_add_inline_script( 'jquery-ui-sortable', self::photo_js() . ( class_exists( 'CC_Fleet_Parts' ) ? CC_Fleet_Parts::editor_js() : '' ) );
		wp_add_inline_style( 'wp-admin', '.cc-photos{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:0 0 10px;padding:0;list-style:none}.cc-photos li{position:relative;margin:0;cursor:move}.cc-photos img{display:block;width:100%;aspect-ratio:1;object-fit:cover;border-radius:3px}.cc-photos li:first-child img{outline:2px solid #2271b1}.cc-photos button{position:absolute;top:2px;right:2px;width:20px;height:20px;padding:0;line-height:18px;border:0;border-radius:50%;background:#000a;color:#fff;cursor:pointer}.cc-part-damage{display:block;margin-top:4px;color:#b32d2e;font-weight:600}.cc-part-warn{display:block;margin-top:4px;color:#996800}.cc-derived{background:#f6f7f7}.cc-care__due{color:#b32d2e;font-weight:600}.cc-care fieldset{display:inline-block;border:0;margin:0;padding:0}.cc-care legend{padding:0}' );
	}

	private static function photo_js() {
		return <<<'JS'
jQuery(function($){
  // Changes in the bike boxes should always enable Save in the block editor.
  $(document).on('change input','[name^="cc["],[name^="cc_change["]',function(){
    try{ var ed=wp.data&&wp.data.dispatch('core/editor'); if(ed&&!wp.data.select('core/editor').isEditedPostDirty()){ ed.editPost({cc_touched:String(Date.now())}); } }catch(e){}
  });
  var box=$('#cc-photos-box'); if(!box.length) return;
  var list=box.find('.cc-photos'), field=box.find('input[name="cc[photos]"]');
  function sync(){ field.val(list.children().map(function(){return $(this).data('id');}).get().join(',')).trigger('change'); box.find('.cc-photos-empty').toggle(!list.children().length); }
  function add(id,url){ list.append($('<li>').attr('data-id',id).append($('<img alt="">').attr('src',url)).append($('<button type="button" aria-label="Remove photo">×</button>'))); }
  list.sortable({update:sync});
  list.on('click','button',function(){ $(this).parent().remove(); sync(); });
  var frame;
  box.find('.cc-photos-add').on('click',function(e){ e.preventDefault();
    if(!frame){ frame=wp.media({title:'Bike photos',button:{text:'Add to bike'},library:{type:'image'},multiple:'add'});
      frame.on('select',function(){ frame.state().get('selection').each(function(a){ a=a.toJSON(); if(!list.children('[data-id="'+a.id+'"]').length){ var s=a.sizes||{}; add(a.id,(s.medium||s.thumbnail||s.full||a).url); } }); sync(); }); }
    frame.open(); });
});
JS;
	}

	public static function box_photos( $post ) {
		$ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) self::get( $post->ID, 'photos' ) ) ), 'wp_attachment_is_image' ) );
		echo '<div id="cc-photos-box"><ul class="cc-photos">';
		foreach ( $ids as $a ) {
			printf( '<li data-id="%d">%s<button type="button" aria-label="Remove photo">×</button></li>', (int) $a, wp_get_attachment_image( $a, 'thumbnail' ) );
		}
		echo '</ul>';
		printf( '<p class="cc-photos-empty"%s><small>No photos yet: the bike page shows a coloured block with the size.</small></p>', $ids ? ' style="display:none"' : '' );
		printf( '<input type="hidden" name="cc[photos]" value="%s">', esc_attr( implode( ',', $ids ) ) );
		echo '<p><button type="button" class="button cc-photos-add">Add photos</button></p>';
		echo '<p><small>The first photo leads the slider on the bike page and shows on the fleet cards. Drag to reorder, × to remove. Uploads are resized and turned into WebP automatically. Then click Save.</small></p></div>';
	}

	public static function meta_boxes() {
		add_meta_box( 'cc_bike_photos', 'Photos', array( __CLASS__, 'box_photos' ), self::CPT, 'side', 'high' );
		add_meta_box( 'cc_bike_site', 'Website', array( __CLASS__, 'box_site' ), self::CPT, 'side', 'high' );
		add_meta_box( 'cc_bike_spec', 'Spec & parts', array( __CLASS__, 'box_spec' ), self::CPT, 'normal', 'high' );
		if ( class_exists( 'CC_Fleet_Care' ) ) {
			add_meta_box( 'cc_bike_care', 'Drivetrain care', array( 'CC_Fleet_Care', 'box' ), self::CPT, 'normal', 'high' );
		}
		add_meta_box( 'cc_bike_loans', 'Loans', array( __CLASS__, 'box_loans' ), self::CPT, 'normal', 'default' );
		add_meta_box( 'cc_bike_history', 'History: what was done to this bike', array( __CLASS__, 'box_history' ), self::CPT, 'normal', 'default' );
	}

	public static function box_site( $post ) {
		wp_nonce_field( 'cc_bike_save', 'cc_bike_nonce' );
		$state = self::get( $post->ID, 'state' );
		echo '<p><label><strong>State</strong><br><select name="cc[state]" style="width:100%">';
		foreach ( array( 'active' => 'Active — can be borrowed', 'workshop' => 'In the workshop', 'soon' => 'Coming soon — shown, not bookable yet', 'retired' => 'Retired' ) as $k => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $state, $k, false ), esc_html( $l ) );
		}
		echo '</select></label></p>';
		printf( '<p><label><input type="checkbox" name="cc[show_on_site]" value="1"%s> Show on the website</label><br><small>Unticked = backend only: the crew can still book, service and track it, but riders can\'t see or request it.</small></p>', checked( self::get( $post->ID, 'show_on_site' ), '1', false ) );
		printf( '<p><label>Public name<br><input type="text" class="widefat" name="cc[public_name]" value="%s" placeholder="Defaults to the colourway"></label></p>', esc_attr( self::get( $post->ID, 'public_name' ) ) );
		printf( '<p><label>Tag<br><input type="text" class="widefat" name="cc[public_tag]" value="%s" placeholder="e.g. Repainted 2026"></label><br><small>Optional pink label next to the status.</small></p>', esc_attr( self::get( $post->ID, 'public_tag' ) ) );
		printf( '<p><label>Public blurb<br><textarea class="widefat" rows="3" name="cc[public_blurb]">%s</textarea></label></p>', esc_textarea( self::get( $post->ID, 'public_blurb' ) ) );
		printf( '<p>Rider height (cm)<br><input type="number" name="cc[rider_min]" value="%s" style="width:45%%" placeholder="min"> – <input type="number" name="cc[rider_max]" value="%s" style="width:45%%" placeholder="max"><br><small>Leave empty to use the size table in Settings.</small></p>',
			esc_attr( self::get( $post->ID, 'rider_min' ) ), esc_attr( self::get( $post->ID, 'rider_max' ) ) );
		$a = self::assignment( $post->ID );
		echo '<hr><p><label><strong>Long-term rider</strong><br><select name="cc[assigned_rider]" style="width:100%"><option value="">— None: free to borrow —</option>';
		global $wpdb;
		foreach ( $wpdb->get_results( 'SELECT id, name FROM ' . CC_Fleet_Riders::table() . " WHERE name <> '' ORDER BY name ASC" ) as $r ) {
			printf( '<option value="%d"%s>%s</option>', (int) $r->id, selected( $a['rider_id'] ?? 0, (int) $r->id, false ), esc_html( $r->name ) );
		}
		echo '</select></label></p>';
		printf( '<p><label>Since<br><input type="date" name="cc[assigned_since]" value="%s" style="width:100%%"></label><br><small>From this date the bike is blocked on every calendar, with no end date, until you set this back to None.</small></p>', esc_attr( $a['since'] ?? CC_Fleet_Settings::today() ) );
		echo '<p><small>Live status: <strong>' . esc_html( self::state_now( $post->ID ) ) . '</strong></small></p>';
	}

	public static function box_spec( $post ) {
		$groups = array( 'frame' => 'Frame', 'drivetrain' => 'Drivetrain & brakes', 'wheels' => 'Wheels & tyres', 'cockpit' => 'Cockpit & contact points', 'internal' => 'Internal (never shown publicly)' );
		$types  = class_exists( 'CC_Fleet_Records' ) ? CC_Fleet_Records::MAINT_TYPES : array( 'upgrade' => 'Upgrade', 'repair' => 'Repair', 'service' => 'Service' );
		echo '<div class="cc-record-change"><strong>Changing parts?</strong> Edit the fields below, then say when and what happened. Every changed field is saved to the bike’s history with this date.';
		echo '<div class="cc-record-change__row">';
		printf( '<label>Date<br><input type="date" name="cc_change[date]" value="%s"></label>', esc_attr( CC_Fleet_Settings::today() ) );
		echo '<label>What kind of work<br><select name="cc_change[type]">';
		foreach ( $types as $k => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $k, 'upgrade', false ), esc_html( $l ) );
		}
		echo '</select></label>';
		echo '<label>By<br><input type="text" name="cc_change[by]" placeholder="e.g. Woodstock Cycleworks - Nils"></label>';
		echo '<label>Cost (R)<br><input type="number" step="0.01" name="cc_change[cost]" style="width:110px"></label>';
		echo '</div><p><label>Note<br><input type="text" class="widefat" name="cc_change[note]" placeholder="e.g. New tyres before Road to Desolation; old ones worn through"></label></p>';
		echo '<p><label><input type="checkbox" name="cc_change[workshop]" value="1" checked> Add to the workshop log</label> <span class="cc-muted">Untick when you’re only fixing a typo.</span></p></div>';
		echo '<div class="cc-spec-grid">';
		foreach ( $groups as $g => $title ) {
			echo '<fieldset class="cc-spec-group"><legend>' . esc_html( $title ) . '</legend>';
			foreach ( self::fields() as $key => $f ) {
				if ( $f[1] !== $g ) {
					continue;
				}
				$long = in_array( $key, array( 'notes', 'frame_notes' ), true );
				printf( '<p><label>%s%s<br>', esc_html( $f[0] ), $f[2] ? '' : ' <span class="cc-private" title="Internal">●</span>' );
				$part_type = array( 'wheelset' => 'wheelset', 'tyres' => 'tyres' )[ $key ] ?? '';
				$derived   = in_array( $key, array( 'wheel_size', 'freehub', 'tyre_size' ), true ) && class_exists( 'CC_Fleet_Parts' );
				if ( $part_type && class_exists( 'CC_Fleet_Parts' ) ) {
					echo CC_Fleet_Parts::select( $post->ID, $part_type ); // phpcs:ignore -- escaped inside.
				} elseif ( $derived ) {
					printf( '<input type="text" class="widefat cc-derived" value="%s" readonly title="Comes from the %s part">', esc_attr( self::get( $post->ID, $key ) ), 'tyre_size' === $key ? 'tyres' : 'wheelset' );
				} elseif ( $long ) {
					printf( '<textarea class="widefat" rows="2" name="cc[%s]">%s</textarea>', esc_attr( $key ), esc_textarea( self::get( $post->ID, $key ) ) );
				} else {
					printf( '<input type="text" class="widefat" name="cc[%s]" value="%s">', esc_attr( $key ), esc_attr( self::get( $post->ID, $key ) ) );
				}
				echo '</label></p>';
			}
			echo '</fieldset>';
		}
		echo '</div>';
	}

	public static function box_loans( $post ) {
		$loans = CC_Fleet_Loans::query( array( 'bike_id' => $post->ID, 'limit' => 15, 'order' => 'DESC' ) );
		printf( '<p><a class="button" href="%s">+ New loan for this bike</a></p>', esc_url( admin_url( 'admin.php?page=cc-fleet-loan&bike_id=' . $post->ID ) ) );
		if ( ! $loans ) {
			echo '<p>No loans yet.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Dates</th><th>Rider</th><th>Status</th><th>Plan</th></tr></thead><tbody>';
		foreach ( $loans as $l ) {
			$r = CC_Fleet_Riders::get( $l->rider_id );
			printf( '<tr><td><a href="%s">%s → %s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( admin_url( 'admin.php?page=cc-fleet-loan&id=' . $l->id ) ),
				esc_html( $l->start_date ), esc_html( $l->end_date ), esc_html( $r->name ?? '—' ),
				CC_Fleet_Loans::status_badge( $l ), esc_html( wp_trim_words( (string) $l->plan, 8 ) ) );
		}
		echo '</tbody></table>';
	}

	/** Dated timeline: workshop jobs and every recorded spec change, newest first. */
	public static function box_history( $post ) {
		global $wpdb;
		$items = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CC_Fleet_Install::table( 'log' ) . " WHERE object_type = 'bike' AND object_id = %d AND action = 'spec' ORDER BY id DESC LIMIT 200", $post->ID ) ) as $e ) {
			$d = json_decode( (string) $e->message, true );
			if ( is_array( $d ) ) {
				$items[] = array( 'date' => $d['date'] ?? substr( $e->created_at, 0, 10 ), 'sort' => $e->created_at, 'kind' => 'spec', 'd' => $d, 'user' => $e->user_id );
			}
		}
		if ( class_exists( 'CC_Fleet_Records' ) ) {
			foreach ( CC_Fleet_Records::query( 'maintenance', array( 'where' => array( 'bike_id' => $post->ID ), 'limit' => 200 ) ) as $m ) {
				if ( false !== strpos( (string) $m->description, '[spec-change]' ) ) {
					continue; // Shown through its spec entry.
				}
				$items[] = array( 'date' => $m->work_date, 'sort' => $m->created_at, 'kind' => 'job', 'm' => $m );
			}
		}
		if ( class_exists( 'CC_Fleet_Care' ) ) {
			$items = array_merge( $items, CC_Fleet_Care::history_items( $post->ID ) );
		}
		if ( class_exists( 'CC_Fleet_Expenses' ) ) {
			$items = array_merge( $items, CC_Fleet_Expenses::history_items( $post->ID ) );
		}
		usort( $items, function ( $a, $b ) { return strcmp( $b['date'] . $b['sort'], $a['date'] . $a['sort'] ); } );
		printf( '<p><a class="button" href="%s">+ Workshop job for this bike</a></p>', esc_url( admin_url( 'admin.php?page=cc-fleet-record&kind=maintenance&bike_id=' . $post->ID ) ) );
		if ( ! $items ) {
			echo '<p class="cc-empty">Nothing recorded yet. Change a part above and it shows up here with its date.</p>';
			return;
		}
		$labels = self::fields();
		echo '<table class="widefat striped cc-history"><thead><tr><th style="width:110px">Date</th><th>What was done</th><th style="width:160px">By</th></tr></thead><tbody>';
		foreach ( $items as $it ) {
			$date = esc_html( wp_date( 'j M Y', strtotime( $it['date'] . ' 12:00' ) ) );
			if ( 'spec' === $it['kind'] ) {
				$d     = $it['d'];
				$lines = '';
				foreach ( (array) ( $d['changes'] ?? array() ) as $c ) {
					$lines .= sprintf( '<li><strong>%s:</strong> <del>%s</del> → %s</li>', esc_html( $labels[ $c[0] ][0] ?? $c[0] ), esc_html( '' === (string) $c[1] ? '(empty)' : $c[1] ), esc_html( '' === (string) $c[2] ? '(empty)' : $c[2] ) );
				}
				$u    = $it['user'] ? get_userdata( $it['user'] ) : null;
				$type = $d['workshop'] ? ( class_exists( 'CC_Fleet_Records' ) ? ( CC_Fleet_Records::MAINT_TYPES[ $d['type'] ] ?? $d['type'] ) : $d['type'] ) : ( 'moved' === ( $d['type'] ?? '' ) ? 'Part moved' : 'Spec corrected' );
				printf( '<tr><td>%s</td><td><span class="cc-badge">%s</span> %s%s%s</td><td>%s</td></tr>', $date, esc_html( $type ), esc_html( $d['note'] ?? '' ),
					! empty( $d['cost'] ) ? ' <span class="cc-muted">· R' . esc_html( number_format_i18n( (float) $d['cost'] ) ) . '</span>' : '', $lines ? '<ul class="cc-changes">' . $lines . '</ul>' : '',
					esc_html( trim( ( $d['by'] ?? '' ) . ( $u ? ' (' . $u->display_name . ')' : '' ) ) ) );
			} elseif ( 'expense' === $it['kind'] ) {
				CC_Fleet_Expenses::history_row( $it['e'], $date ); // phpcs:ignore -- escaped inside.
			} elseif ( 'care' === $it['kind'] ) {
				$d = $it['d'];
				$u = $it['user'] ? get_userdata( $it['user'] ) : null;
				printf( '<tr><td>%s</td><td><span class="cc-badge">Drivetrain</span> %s%s</td><td>%s</td></tr>', $date, esc_html( $d['text'] ?? '' ), ! empty( $d['note'] ) ? ' <span class="cc-muted">· ' . esc_html( $d['note'] ) . '</span>' : '', esc_html( $u ? $u->display_name : '' ) );
			} else {
				$m = $it['m'];
				printf( '<tr><td>%s</td><td><span class="cc-badge">%s</span> <a href="%s">%s</a>%s%s</td><td>%s</td></tr>', $date, esc_html( CC_Fleet_Records::MAINT_TYPES[ $m->type ] ?? $m->type ),
					esc_url( admin_url( 'admin.php?page=cc-fleet-record&kind=maintenance&id=' . $m->id ) ), esc_html( wp_trim_words( (string) $m->description, 24 ) ),
					$m->cost > 0 ? ' <span class="cc-muted">· R' . esc_html( number_format_i18n( (float) $m->cost ) ) . '</span>' : '', 'scheduled' === $m->status ? ' <span class="cc-badge cc-badge--scheduled">Scheduled</span>' : '', esc_html( $m->technician ) );
			}
		}
		echo '</tbody></table>';
	}

	/** Spec fields that count as parts: everything except bookkeeping columns from the sheet. */
	public static function assign( $post_id, $rider_id, $since = '' ) {
		self::set( $post_id, 'assigned_rider', $rider_id ? (int) $rider_id : '' );
		self::set( $post_id, 'assigned_since', $rider_id ? ( $since ?: CC_Fleet_Settings::today() ) : '' );
	}

	public static function tracked_fields() {
		return array_diff( array_keys( self::fields() ), array( 'cc_id', 'owner', 'sheet_status', 'sheet_rider', 'date_added', 'last_upgraded', 'spec_complete', 'notes', 'frame_notes', 'replace_now', 'monitor', 'ask_2027' ) );
	}

	/**
	 * Record a dated change: which fields went from what to what, plus a workshop entry.
	 * @param array $changes [[field, old, new], …]
	 * @param array $c date, type, by, cost, note, workshop (bool)
	 */
	public static function record_change( $post_id, array $changes, array $c ) {
		$date = CC_Fleet_Import::date( $c['date'] ?? '' ) ?: CC_Fleet_Settings::today();
		$note = sanitize_text_field( $c['note'] ?? '' );
		if ( ! $changes && '' === $note ) {
			return;
		}
		$entry = array( 'date' => $date, 'type' => sanitize_key( $c['type'] ?? 'upgrade' ), 'by' => sanitize_text_field( $c['by'] ?? '' ), 'cost' => round( (float) str_replace( ',', '.', (string) ( $c['cost'] ?? 0 ) ), 2 ), 'note' => $note, 'workshop' => ! empty( $c['workshop'] ), 'changes' => $changes );
		CC_Fleet_Install::log( 'bike', $post_id, 'spec', wp_json_encode( $entry ) );
		if ( $entry['workshop'] && class_exists( 'CC_Fleet_Records' ) ) {
			$labels = self::fields();
			$what   = array_map( function ( $ch ) use ( $labels ) { return ( $labels[ $ch[0] ][0] ?? $ch[0] ) . ': ' . ( '' === (string) $ch[1] ? '(empty)' : $ch[1] ) . ' → ' . $ch[2]; }, $changes );
			CC_Fleet_Records::save( 'maintenance', array(
				'bike_id' => $post_id, 'type' => $entry['type'], 'status' => 'completed', 'work_date' => $date, 'cost' => (string) $entry['cost'], 'technician' => $entry['by'],
				'description' => trim( $note . ( $what ? "\n" . implode( "\n", $what ) : '' ) . "\n[spec-change]" ),
			) );
			if ( $changes && ( '' === (string) self::get( $post_id, 'last_upgraded' ) || $date >= CC_Fleet_Import::date( (string) self::get( $post_id, 'last_upgraded' ) ) ) ) {
				self::set( $post_id, 'last_upgraded', $date );
			}
		}
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['cc_bike_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['cc_bike_nonce'] ), 'cc_bike_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in      = isset( $_POST['cc'] ) && is_array( $_POST['cc'] ) ? wp_unslash( $_POST['cc'] ) : array();
		// Wheelset and tyres come from the parts drop-downs: fill the spec fields from the chosen part,
		// so the change is recorded like any other, then move the parts afterwards.
		$parts = array();
		if ( class_exists( 'CC_Fleet_Parts' ) && isset( $_POST['cc_part'] ) && is_array( $_POST['cc_part'] ) ) {
			$was = isset( $_POST['cc_part_was'] ) && is_array( $_POST['cc_part_was'] ) ? array_map( 'absint', wp_unslash( $_POST['cc_part_was'] ) ) : array();
			foreach ( CC_Fleet_Parts::TYPES as $ptype => $pdef ) {
				if ( isset( $_POST['cc_part'][ $ptype ] ) ) {
					$parts[ $ptype ] = absint( $_POST['cc_part'][ $ptype ] );
				}
			}
			// Tyres travel with their wheels: new wheels and untouched tyres → the tyres on those wheels.
			if ( isset( $parts['wheelset'], $parts['tyres'], $was['wheelset'], $was['tyres'] ) && $parts['wheelset'] !== $was['wheelset'] && $parts['tyres'] === $was['tyres'] ) {
				$t               = $parts['wheelset'] ? CC_Fleet_Parts::tyres_on( $parts['wheelset'] ) : null;
				$parts['tyres']  = $t ? (int) $t->id : 0;
			}
			foreach ( $parts as $ptype => $pid ) {
				$in = array_merge( $in, CC_Fleet_Parts::bike_values( $pid, $ptype ) );
			}
		}
		$tracked = self::tracked_fields();
		$changes = array();
		foreach ( array_keys( self::fields() ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$val = in_array( $key, array( 'notes', 'frame_notes' ), true ) ? sanitize_textarea_field( $in[ $key ] ) : sanitize_text_field( $in[ $key ] );
				if ( 'cc_id' === $key ) {
					$val = strtoupper( $val );
				}
				$old = (string) self::get( $post_id, $key );
				if ( in_array( $key, $tracked, true ) && trim( $old ) !== trim( $val ) && 'auto-draft' !== $post->post_status ) {
					$changes[] = array( $key, $old, $val );
				}
				self::set( $post_id, $key, $val );
			}
		}
		$change = isset( $_POST['cc_change'] ) && is_array( $_POST['cc_change'] ) ? wp_unslash( $_POST['cc_change'] ) : array();
		self::record_change( $post_id, $changes, $change );
		// Status first: moving wheels below may change it (damaged wheels → workshop, good wheels → active).
		self::set( $post_id, 'state', in_array( $in['state'] ?? '', array( 'active', 'workshop', 'soon', 'retired' ), true ) ? $in['state'] : 'active' );
		$ctx = array( 'date' => $change['date'] ?? '', 'by' => $change['by'] ?? '', 'quiet_target' => true );
		foreach ( $parts as $ptype => $pid ) {
			$cur = CC_Fleet_Parts::on_bike( $post_id, $ptype );
			if ( $pid && ( ! $cur || (int) $cur->id !== $pid ) ) {
				$p     = CC_Fleet_Parts::get( $pid );
				$other = $p ? (int) $p->bike_id : 0;
				if ( 'wheelset' === $ptype && $cur && $other && $other !== (int) $post_id && ! empty( $_POST['cc_part_swap'] ) ) {
					CC_Fleet_Parts::swap( $post_id, $other, $ctx );
				} else {
					CC_Fleet_Parts::assign( $pid, $post_id, $ctx );
				}
			} elseif ( ! $pid && $cur ) {
				CC_Fleet_Parts::assign( $cur->id, 0, array( 'note' => 'Taken off ' . self::get( $post_id, 'cc_id' ) ) );
			}
		}
		if ( class_exists( 'CC_Fleet_Parts' ) ) {
			CC_Fleet_Parts::keep_messages();
		}
		if ( class_exists( 'CC_Fleet_Care' ) && isset( $_POST['cc_care'] ) && is_array( $_POST['cc_care'] ) ) {
			CC_Fleet_Care::save( $post_id, wp_unslash( $_POST['cc_care'] ) );
		}
		self::set( $post_id, 'show_on_site', empty( $in['show_on_site'] ) ? '0' : '1' );
		self::set( $post_id, 'public_name', sanitize_text_field( $in['public_name'] ?? '' ) );
		self::set( $post_id, 'public_blurb', sanitize_textarea_field( $in['public_blurb'] ?? '' ) );
		self::set( $post_id, 'public_tag', sanitize_text_field( $in['public_tag'] ?? '' ) );
		if ( isset( $in['photos'] ) ) {
			$ids = array_filter( array_map( 'absint', explode( ',', (string) $in['photos'] ) ), function ( $a ) {
				return $a && wp_attachment_is_image( $a );
			} );
			self::set( $post_id, 'photos', implode( ',', array_unique( $ids ) ) );
		}
		self::set( $post_id, 'rider_min', absint( $in['rider_min'] ?? 0 ) ?: '' );
		self::set( $post_id, 'rider_max', absint( $in['rider_max'] ?? 0 ) ?: '' );
		if ( array_key_exists( 'assigned_rider', $in ) ) {
			$before = (int) self::get( $post_id, 'assigned_rider' );
			$rid    = absint( $in['assigned_rider'] );
			$since  = CC_Fleet_Import::date( sanitize_text_field( $in['assigned_since'] ?? '' ) ) ?: CC_Fleet_Settings::today();
			self::assign( $post_id, $rid, $since );
			if ( $before !== $rid ) {
				$r = $rid ? CC_Fleet_Riders::get( $rid ) : null;
				CC_Fleet_Install::log( 'bike', $post_id, 'assigned', $rid ? 'Long-term rider: ' . ( $r->name ?? $rid ) . ' since ' . $since : 'Long-term rider removed' );
			}
		}
		CC_Fleet_Install::log( 'bike', $post_id, 'saved' );
	}

	public static function columns( $cols ) {
		$new = array( 'cb' => $cols['cb'], 'title' => 'Bike', 'cc_size' => 'Size', 'cc_cat' => 'Category', 'cc_state' => 'Live status', 'cc_web' => 'On site', 'cc_replace' => 'Replace now' );
		return $new;
	}

	public static function column( $col, $post_id ) {
		switch ( $col ) {
			case 'cc_size':
				echo esc_html( self::get( $post_id, 'size' ) );
				break;
			case 'cc_cat':
				echo esc_html( self::get( $post_id, 'category' ) );
				break;
			case 'cc_state':
				$s = self::state_now( $post_id );
				$a = 'assigned' === $s ? self::assignment( $post_id ) : null;
				printf( '<span class="cc-badge cc-badge--%s">%s</span>', esc_attr( $s ), esc_html( $a ? 'Assigned · ' . strtok( $a['name'], ' ' ) : ucfirst( $s ) ) );
				break;
			case 'cc_web':
				echo '1' === self::get( $post_id, 'show_on_site' ) ? '✓ Website' : 'Backend only';
				break;
			case 'cc_replace':
				echo esc_html( self::get( $post_id, 'replace_now' ) ?: '—' );
				break;
		}
	}
}
