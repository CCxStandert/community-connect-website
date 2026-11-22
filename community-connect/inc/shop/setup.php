<?php
/**
 * One-click store setup: WooCommerce → CC store setup → "Set up the store".
 *
 * Safe to run again: settings are re-applied, pages and products are matched (by SKU) and updated,
 * not duplicated, and photos already in the Media Library are reused. The first run puts the shop in
 * "Coming soon" (store pages only) so nobody can order before the Yoco keys are in; later runs leave
 * that switch alone.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Community Connect store setup', 'CC store setup', 'manage_woocommerce', 'cc-shop-setup', 'cc_shop_setup_page' );
}, 60 );

add_action( 'admin_post_cc_shop_setup', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( 'cc_shop_setup' );
	@set_time_limit( 300 ); // phpcs:ignore -- downloading the product photos can take a minute.
	$log = cc_shop_setup( true );
	set_transient( 'cc_shop_setup_log', $log, 600 );
	wp_safe_redirect( admin_url( 'admin.php?page=cc-shop-setup&done=1' ) );
	exit;
} );

function cc_shop_setup_page() {
	$log  = get_transient( 'cc_shop_setup_log' );
	$yoco = class_exists( 'WC_Payment_Gateway' ) && isset( WC()->payment_gateways()->payment_gateways()['class_yoco_wc_payment_gateway'] );
	echo '<div class="wrap"><h1>Community Connect store setup</h1>';
	echo '<p>Sets the store up the way we agreed: rand prices, South Africa only, courier R120 (free from R2,000) or free collection at a community ride, guest checkout, the shop terms page (as a draft), the two categories and the starter products with their photos. You can run it again at any time; nothing is duplicated.</p>';
	if ( $log ) {
		delete_transient( 'cc_shop_setup_log' );
		echo '<div class="notice notice-success"><p><strong>Done.</strong></p><ul style="list-style:disc;margin-left:20px">';
		foreach ( $log as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul></div>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'cc_shop_setup' );
	echo '<input type="hidden" name="action" value="cc_shop_setup"><p><button class="button button-primary button-hero">Set up the store</button></p></form>';
	echo '<h2>Still to do by you</h2><ol>';
	echo '<li>' . ( $yoco ? 'Yoco Payments is installed. ' : 'Install <strong>Yoco Payments</strong> (Plugins → Add New). ' ) . 'Then paste your Yoco keys in WooCommerce → Settings → Payments → Yoco, and make a test payment in test mode.</li>';
	echo '<li>Check the products: stock per size, and the size tables in “Shop page extras”.</li>';
	echo '<li>Read and publish the <strong>Shop terms &amp; returns</strong> page (Pages → Drafts).</li>';
	echo '<li>When you’re ready: WooCommerce → Settings → Site visibility → <strong>Live</strong>. Until then only you see the shop, and the Shop link and cart stay hidden from visitors.</li>';
	echo '</ol></div>';
}

/** Run the whole setup. @return string[] what happened */
function cc_shop_setup( $with_images = true ) {
	$log   = array();
	$first = ! get_option( 'cc_shop_setup_done' );

	// 1. Store settings.
	$opts = array(
		'woocommerce_currency'                         => 'ZAR',
		'woocommerce_currency_pos'                     => 'left',
		'woocommerce_price_thousand_sep'               => ',',
		'woocommerce_price_decimal_sep'                => '.',
		'woocommerce_price_num_decimals'               => '0',
		'woocommerce_default_country'                  => 'ZA:WC',
		'woocommerce_store_city'                       => 'Cape Town',
		'woocommerce_allowed_countries'                => 'specific',
		'woocommerce_specific_allowed_countries'       => array( 'ZA' ),
		'woocommerce_ship_to_countries'                => '',
		'woocommerce_calc_taxes'                       => 'no',
		'woocommerce_enable_coupons'                   => 'no',
		'woocommerce_checkout_phone_field'             => 'required', // The courier needs a number (donor-only checkouts drop it).
		'woocommerce_checkout_company_field'           => 'hidden',
		'woocommerce_checkout_address_2_field'         => 'optional',
		'woocommerce_enable_reviews'                   => 'no',
		'woocommerce_enable_guest_checkout'            => 'yes',
		'woocommerce_enable_checkout_login_reminder'   => 'no',
		'woocommerce_enable_signup_and_login_from_checkout' => 'no',
		'woocommerce_enable_myaccount_registration'    => 'no',
		'woocommerce_enable_ajax_add_to_cart'          => 'yes',
		'woocommerce_cart_redirect_after_add'          => 'no',
		'woocommerce_email_from_name'                  => 'Community Connect',
		'woocommerce_email_base_color'                 => '#141217',
		'woocommerce_email_background_color'           => '#F4EEE4',
		'woocommerce_email_body_background_color'      => '#FFFFFF',
		'woocommerce_email_text_color'                 => '#141217',
		'woocommerce_email_footer_text'                => 'Community Connect NPC · Reg. 2025/713989/08 · Cape Town · contact@community-connect.cc',
		'cc_shop_min_amount'                           => 50,
		'cc_shop_courier'                              => 120,
		'cc_shop_free_from'                            => 2000,
	);
	foreach ( $opts as $k => $v ) {
		update_option( $k, $v );
	}
	$perma                 = (array) get_option( 'woocommerce_permalinks', array() );
	$perma['product_base'] = '/shop';
	update_option( 'woocommerce_permalinks', $perma );
	if ( $first ) {
		update_option( 'woocommerce_coming_soon', 'yes' );
		update_option( 'woocommerce_store_pages_only', 'yes' );
		$log[] = 'Shop set to “Coming soon” (store pages only): visitors can’t order yet.';
	}
	$log[] = 'Store settings: rand without cents (R1,300), South Africa only, no tax, guest checkout, no coupons or reviews, product addresses /shop/[name]/.';

	// 2. Pages.
	foreach ( array( 'cart' => '[woocommerce_cart]', 'checkout' => '[woocommerce_checkout]' ) as $page => $code ) {
		$id = wc_get_page_id( $page );
		if ( $id > 0 && false === strpos( (string) get_post_field( 'post_content', $id ), $code ) ) {
			wp_update_post( array( 'ID' => $id, 'post_content' => "<!-- wp:shortcode -->\n$code\n<!-- /wp:shortcode -->" ) );
			$log[] = ucfirst( $page ) . ' page switched to the classic ' . $page . '.';
		}
	}
	$terms = get_page_by_path( 'terms' );
	if ( ! $terms ) {
		$content = str_replace( '{{privacy}}', esc_url( get_privacy_policy_url() ?: home_url( '/privacy-policy/' ) ), (string) file_get_contents( CC_SHOP_DIR . 'terms-draft.html' ) ); // phpcs:ignore
		$tid     = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Shop terms & returns', 'post_name' => 'terms', 'post_content' => $content, 'page_template' => 'page-legal' ) );
		$log[]   = 'Draft page “Shop terms & returns” created (Pages → Drafts): fill in the [brackets] and publish it.';
	} elseif ( 'publish' === $terms->post_status ) {
		update_option( 'woocommerce_terms_page_id', $terms->ID );
	}

	// 3. Categories and the size attribute.
	$cats = array();
	foreach ( array( CC_APPAREL_CAT => 'Apparel & Gear', CC_SPONSOR_CAT => 'Sponsor a Rider' ) as $slug => $name ) {
		$t            = get_term_by( 'slug', $slug, 'product_cat' );
		$cats[ $slug ] = $t ? (int) $t->term_id : (int) wp_insert_term( $name, 'product_cat', array( 'slug' => $slug ) )['term_id'];
	}
	$sizes = cc_shop_ensure_attribute( 'size', 'Size', array( 'XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL' ) );
	cc_shop_ensure_attribute( 'colour', 'Colour', array() );
	$log[] = 'Categories “Apparel & Gear” and “Sponsor a Rider”, and the Size attribute (XXS to XXL in order).';

	// 4. Products.
	foreach ( cc_shop_starter_products() as $i => $def ) {
		$log[] = cc_shop_upsert_product( $def, $i, $cats, $sizes, $with_images );
	}

	// 5. Shipping.
	$log[] = cc_shop_setup_shipping();

	flush_rewrite_rules( false );
	update_option( 'cc_shop_setup_done', current_time( 'mysql' ) );
	return $log;
}

/** A global attribute (pa_$slug) with these terms in this order. @return array<string,int> name → term id */
function cc_shop_ensure_attribute( $slug, $label, array $terms ) {
	$tax = 'pa_' . $slug;
	if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
		wc_create_attribute( array( 'name' => $label, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ) );
	}
	if ( ! taxonomy_exists( $tax ) ) {
		register_taxonomy( $tax, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ) );
	}
	$out = array();
	foreach ( $terms as $order => $name ) {
		$t = get_term_by( 'name', $name, $tax );
		if ( ! $t ) {
			$r = wp_insert_term( $name, $tax, array( 'slug' => sanitize_title( $name ) ) );
			$t = is_wp_error( $r ) ? null : get_term( $r['term_id'], $tax );
		}
		if ( $t ) {
			update_term_meta( $t->term_id, 'order', $order );
			$out[ $name ] = (int) $t->term_id;
		}
	}
	return $out;
}

/** What we sell at launch. Photos come from roadtodesolation.cc and the theme. */
function cc_shop_starter_products() {
	$rtd  = 'https://roadtodesolation.cc/wp-content/uploads/';
	$fit26 = "Unisex, boxy and oversized, made with Standert.\nThe sizes here are the real fit: the label inside says one size up (XS here has an S label).";
	$fitls = 'Unisex, relaxed and oversized.';
	$theme = get_theme_file_path( 'assets/' );
	return array(
		array(
			'sku' => 'CC-RTD26-HOODIE', 'name' => '’26 RTD Apparel – Hoodie', 'slug' => 'rtd-hoodie-2026', 'price' => 1300,
			'sub' => "Road to Desolation 2026\nMade with Standert", 'fit' => $fit26,
			'short' => 'Our 2026 hoodie, made in collaboration with Standert in a versatile mid-weight cotton blend that’s built to last, wash after wash. Super boxy, oversized fit with a classic front pouch pocket. For work, play and pleasure, with or without a bike.',
			'desc' => "Made in collaboration with Standert for Road to Desolation 2026.\n\nComposition: cotton blend (60% cotton, 40% polyester).\n\nCare: hand wash at 40°C, hang dry, low iron if needed. Do not tumble dry, bleach or dry clean.",
			'sizes' => array( 'XXS', 'XS', 'S', 'M', 'L', 'XL' ), 'imgs' => array( $rtd . 'CC_RTD_Hoodie26_front.png', $rtd . 'CC_RTD_Hoodie26_back.png' ),
		),
		array(
			'sku' => 'CC-RTD26-TEE', 'name' => '’26 RTD Apparel – Tee', 'slug' => 'rtd-tee-2026', 'price' => 450,
			'sub' => "Road to Desolation 2026\nMade with Standert", 'fit' => $fit26,
			'short' => 'Our 2026 unisex tee, made in collaboration with Standert: boxy cut, oversized fit and dropped shoulders, in a medium-weight 100% cotton.',
			'desc' => "Made in collaboration with Standert for Road to Desolation 2026.\n\n100% cotton, mid-weight 180 gsm.",
			'sizes' => array( 'XXS', 'XS', 'S', 'M', 'L', 'XL' ), 'imgs' => array( $rtd . 'RTD-Tshirt-Front.png', $rtd . 'RTD-Tshirt-Back.png' ),
		),
		array(
			'sku' => 'CC-KOOS-GHC-TEE', 'name' => 'Koos – Gravel Hearts Club Tee', 'slug' => 'koos-gravel-hearts-club-tee', 'price' => 600,
			'sub' => "Sand\nGravel Hearts Club", 'fit' => 'Unisex, boxy and oversized.',
			'short' => 'The Gravel Hearts Club tee from our Standert × Koos repaint project, with HUNT: “gravel hearts club” scrawled on the chest, Koos’ pink creature on the back.',
			'desc' => "Part of the Gravel Hearts Club project, where artist Koos repainted two of our Standert bikes.\n\nFront: “gravel hearts club” in Koos’ handwriting. Back: Koos’ pink creature with a flag, Standert × Koos × HUNT.\n\nSand-coloured tee, boxy oversized fit.",
			'sizes' => array( 'XS', 'S', 'M', 'L', 'XL', 'XXL' ), 'imgs' => array( $theme . 'shop/koos-ghc-tee-front.jpg', $theme . 'shop/koos-ghc-tee-back.jpg' ),
		),
		array(
			'sku' => 'CC-RTD24-LS', 'name' => '’24 RTD Apparel – Long sleeve', 'slug' => 'rtd-long-sleeve-2024', 'price' => 400,
			'sub' => "Road to Desolation 2024\nArt by Ben Eagle", 'fit' => $fitls,
			'short' => 'Community driven, locally made. The 2024 long sleeve, designed with illustrator Ben Eagle (aka Ben Veitch) and produced by the ABC Collection in Cape Town.',
			'desc' => "The 2024 long-sleeve design: a collaboration with graphic designer and illustrator Ben Eagle, aka Ben Veitch, known for his colourful, minimalist approach to art. Exclusively produced by the ABC Collection.\n\nA relaxed-fit long sleeve designed to elevate everyday life. Unisex, oversized fit. 100% organic 200 gsm cotton single jersey.\n\nLocally produced in Cape Town, South Africa.",
			'sizes' => array( 'XS', 'S', 'M', 'L', 'XL', 'XXL' ), 'imgs' => array( $rtd . 'RTD24_ap_long-sleve.jpg', $rtd . 'RTD24_ap_long-sleve_back.jpg' ),
		),
		array(
			'sku' => 'CC-RTD23-LS', 'name' => '’23 RTD Apparel – Long sleeve', 'slug' => 'rtd-long-sleeve-2023', 'price' => 400,
			'sub' => "Road to Desolation 2023\nArt by Ben Eagle", 'fit' => $fitls,
			'short' => 'Community driven, locally made. The inaugural 2023 long sleeve, designed with illustrator Ben Eagle (aka Ben Veitch) and produced by the ABC Collection in Cape Town.',
			'desc' => "The inaugural 2023 long-sleeve design: a collaboration with graphic designer and illustrator Ben Eagle, aka Ben Veitch, known for his colourful, minimalist approach to art. Exclusively produced by the ABC Collection.\n\nA relaxed-fit long sleeve designed to elevate everyday life. Unisex, oversized fit. 100% organic 160 gsm cotton single jersey.\n\nLocally produced in Cape Town, South Africa.",
			'sizes' => array( 'XS', 'S', 'M', 'L', 'XL', 'XXL' ), 'imgs' => array( $rtd . 'RTD23_ap_long-sleve.jpg', $rtd . 'RTD23_ap_long-sleve_back.jpg' ),
		),
		array(
			'sku' => 'CC-FUND-BIKES', 'name' => 'Bike Maintenance Fund', 'slug' => 'bike-maintenance-fund', 'price' => 500, 'fund' => true,
			'sub' => 'Keeping the community fleet safe and road-ready.',
			'short' => 'Chains, tyres, brake pads and services at Woodstock Cycleworks: everything that keeps our ten Standerts safe for the next rider. Choose an amount and we’ll put it straight into the workshop.',
			'desc' => "Every bike in our fleet is shared, so every bike works hard. Normal wear is on us: chains, tyres and brake pads that wear out from riding, a puncture on a long day out, a cable that stretches. We service the fleet with our friends at Woodstock Cycleworks.\n\nYour sponsorship pays for exactly that, so the bikes stay free to borrow.",
			'imgs' => array( $theme . 'images/repaint.webp' ),
		),
		array(
			'sku' => 'CC-FUND-OVERNIGHT', 'name' => 'Overnight Ride Rider Support', 'slug' => 'overnight-ride-rider-support', 'price' => 500, 'fund' => true,
			'sub' => 'Helping riders join our overnights and events.',
			'short' => 'Some of the best riders in our community can’t cover the costs of an overnight or a race weekend. Your sponsorship pays for a spot, food, transport or a bed, so nobody stays home for lack of money.',
			'desc' => "Our getaways and events are where the community comes together: overnight trips, races like Road to Desolation and long days out in the Karoo.\n\nThis fund covers what stops people from coming: entries, transport, food and accommodation. We decide together with the crew who it supports, and share where it went.",
			'imgs' => array( $theme . 'images/desolation.webp' ),
		),
		array(
			'sku' => 'CC-FUND-GENERAL', 'name' => 'General Project Support', 'slug' => 'general-project-support', 'price' => 500, 'fund' => true,
			'sub' => 'The day-to-day of Community Connect.',
			'short' => 'Insurance, tools, spare parts, the website and everything else that keeps a free bike collective running. A gift here goes wherever it’s needed most.',
			'desc' => "Community Connect is a registered non-profit run by volunteers. Behind the free bikes are running costs nobody sees: insurance, tools, spares, storage, the website.\n\nA general sponsorship lets us cover them, so the rest of the money can go to riding.",
			'imgs' => array( $theme . 'images/hero-ghc.webp' ),
		),
	);
}

/** Create or update one product from its definition. @return string log line */
function cc_shop_upsert_product( array $d, $order, array $cats, array $sizes, $with_images ) {
	$id      = wc_get_product_id_by_sku( $d['sku'] );
	$fund    = ! empty( $d['fund'] );
	$is_var  = ! empty( $d['sizes'] );
	$product = $id ? wc_get_product( $id ) : ( $is_var ? new WC_Product_Variable() : new WC_Product_Simple() );
	$new     = ! $id;
	if ( $new ) {
		$product->set_sku( $d['sku'] );
		$product->set_slug( $d['slug'] );
		$product->set_status( 'publish' );
	}
	$product->set_name( $d['name'] );
	$product->set_short_description( $d['short'] );
	$product->set_description( $d['desc'] );
	$product->set_menu_order( $order );
	$product->set_category_ids( array( $cats[ $fund ? CC_SPONSOR_CAT : CC_APPAREL_CAT ] ) );
	$product->set_reviews_allowed( false );
	if ( $fund ) {
		$product->set_virtual( true );
		$product->set_regular_price( (string) $d['price'] );
		$product->set_sold_individually( true );
	}
	if ( $is_var ) {
		$att = new WC_Product_Attribute();
		$att->set_id( wc_attribute_taxonomy_id_by_name( 'size' ) );
		$att->set_name( 'pa_size' );
		$att->set_options( array_values( array_intersect_key( $sizes, array_flip( $d['sizes'] ) ) ) );
		$att->set_visible( true );
		$att->set_variation( true );
		$product->set_attributes( array( $att ) );
	}
	$pid = $product->save();
	update_post_meta( $pid, '_cc_subtitle', $d['sub'] ?? '' );
	if ( isset( $d['fit'] ) && ( $new || '' === get_post_meta( $pid, '_cc_fit', true ) ) ) {
		update_post_meta( $pid, '_cc_fit', $d['fit'] );
	}
	if ( $fund && '' === get_post_meta( $pid, '_cc_amounts', true ) ) {
		update_post_meta( $pid, '_cc_amounts', '150, 500, 1000' );
	}
	// Variations: one per size, at the product's price, in stock. Existing ones keep their stock and price.
	$made = 0;
	if ( $is_var ) {
		$have = array();
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v ) {
				$have[ $v->get_attributes()['pa_size'] ?? '' ] = $vid;
			}
		}
		foreach ( $d['sizes'] as $mo => $size ) {
			$slug = sanitize_title( $size );
			if ( isset( $have[ $slug ] ) ) {
				continue;
			}
			$v = new WC_Product_Variation();
			$v->set_parent_id( $pid );
			$v->set_attributes( array( 'pa_size' => $slug ) );
			$v->set_regular_price( (string) $d['price'] );
			$v->set_stock_status( 'instock' );
			$v->set_menu_order( $mo );
			$v->save();
			$made++;
		}
		WC_Product_Variable::sync( $pid );
	}
	// Photos.
	$got = 0;
	if ( $with_images && ! empty( $d['imgs'] ) && ! wc_get_product( $pid )->get_image_id() ) {
		$ids = array();
		foreach ( $d['imgs'] as $src ) {
			$aid = cc_shop_sideload( $src, $pid, $d['name'] );
			if ( $aid ) {
				$ids[] = $aid;
			}
		}
		if ( $ids ) {
			$p = wc_get_product( $pid );
			$p->set_image_id( array_shift( $ids ) );
			$p->set_gallery_image_ids( $ids );
			$p->save();
			$got = count( $ids ) + 1;
		}
	}
	return sprintf( '%s %s%s%s.', $new ? 'Created' : 'Updated', $d['name'], $made ? ", {$made} sizes" : '', $got ? ", {$got} photo" . ( 1 === $got ? '' : 's' ) : '' );
}

/** Put a photo (web address or file in the theme) in the Media Library once; reuse it after. */
function cc_shop_sideload( $src, $post_id, $title ) {
	$found = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_cc_source', 'meta_value' => $src, 'fields' => 'ids', 'numberposts' => 1 ) );
	if ( $found ) {
		return (int) $found[0];
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	if ( preg_match( '#^https?://#', $src ) ) {
		$tmp = download_url( $src, 60 );
	} else {
		$tmp = wp_tempnam( basename( $src ) );
		if ( $tmp && ! copy( $src, $tmp ) ) {
			$tmp = new WP_Error( 'copy' );
		}
	}
	if ( is_wp_error( $tmp ) || ! $tmp ) {
		return 0;
	}
	$aid = media_handle_sideload( array( 'name' => basename( wp_parse_url( $src, PHP_URL_PATH ) ?: $src ), 'tmp_name' => $tmp ), $post_id, $title );
	if ( is_wp_error( $aid ) ) {
		@unlink( $tmp ); // phpcs:ignore
		return 0;
	}
	update_post_meta( $aid, '_cc_source', $src );
	update_post_meta( $aid, '_wp_attachment_image_alt', $title );
	return (int) $aid;
}

/** Zone "South Africa": courier R120, free courier from R2,000, free collection at a community ride. */
function cc_shop_setup_shipping() {
	$zone = null;
	foreach ( WC_Shipping_Zones::get_zones() as $z ) {
		if ( 'South Africa' === $z['zone_name'] ) {
			$zone = new WC_Shipping_Zone( $z['id'] );
		}
	}
	if ( ! $zone ) {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'South Africa' );
		$zone->set_zone_order( 0 );
		$zone->add_location( 'ZA', 'country' );
		$zone->save();
	}
	$want = array(
		'flat_rate'     => array( 'title' => 'Courier (door to door)', 'tax_status' => 'none', 'cost' => '120' ),
		'free_shipping' => array( 'title' => 'Free courier (orders from R2,000)', 'requires' => 'min_amount', 'min_amount' => '2000', 'ignore_discounts' => 'no' ),
		'local_pickup'  => array( 'title' => 'Collect at a community ride in Cape Town (free)', 'tax_status' => 'none', 'cost' => '0' ),
	);
	$have = array();
	foreach ( $zone->get_shipping_methods() as $m ) {
		$have[ $m->id ] = $m->instance_id;
	}
	foreach ( $want as $method => $settings ) {
		$iid = $have[ $method ] ?? $zone->add_shipping_method( $method );
		$m   = WC_Shipping_Zones::get_shipping_method( $iid );
		if ( $m ) {
			update_option( $m->get_instance_option_key(), array_merge( (array) get_option( $m->get_instance_option_key(), array() ), $settings, array( 'enabled' => 'yes' ) ) );
		}
	}
	WC_Cache_Helper::get_transient_version( 'shipping', true );
	return 'Shipping zone “South Africa”: courier R120, free courier from R2,000, free collection at a community ride.';
}

/** Once the terms page is published, it becomes the checkout's terms page. */
add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( 'page' === $post->post_type && 'terms' === $post->post_name && 'publish' === $new ) {
		update_option( 'woocommerce_terms_page_id', $post->ID );
	}
}, 10, 3 );
