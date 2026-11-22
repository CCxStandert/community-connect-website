<?php
/**
 * Search and sharing (theme 1.7.0).
 *
 * - A meta description on every page (home: Settings → General; anything else: the "Search & sharing"
 *   box on the page, or its short text).
 * - Link previews (Open Graph + X/Twitter cards) with a 1200×630 image: the page's own share image,
 *   a product card generated from the product photo, or the default sunset card.
 * - Structured data for Google: the organisation, the site, products (price, stock, pre-order),
 *   breadcrumbs, and our own group rides as events.
 * - Sitemap without user pages; cart, checkout and account stay out of search.
 *
 * Steps aside when Yoast SEO or Rank Math is active, so there are never two sets of tags.
 *
 * @package community-connect
 */

defined( 'ABSPATH' ) || exit;

function cc_seo_off() {
	return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' );
}

const CC_SEO_HOME_DESC = 'A bicycle collective in Cape Town. Borrow one of our Standert bikes for free, join our group rides and race gravel events with us. Not a rental shop, just friends sharing really good bikes.';

/* ---------- Settings → General: home description ---------- */
add_action( 'admin_init', function () {
	register_setting( 'general', 'cc_seo_home_desc', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ) );
	add_settings_field( 'cc_seo_home_desc', 'Home page description (Google &amp; link previews)', function () {
		printf( '<textarea name="cc_seo_home_desc" rows="3" class="large-text" maxlength="300" placeholder="%s">%s</textarea><p class="description">About 150 characters. Empty uses the text shown as the placeholder.</p>', esc_attr( CC_SEO_HOME_DESC ), esc_textarea( (string) get_option( 'cc_seo_home_desc' ) ) );
	}, 'general' );
} );

/* ---------- "Search & sharing" box on pages, posts, products and bikes ---------- */
add_action( 'add_meta_boxes', function () {
	foreach ( array( 'page', 'post', 'product', 'cc_bike' ) as $type ) {
		add_meta_box( 'cc-seo', 'Search & sharing', 'cc_seo_box', $type, 'side', 'low' );
	}
} );
function cc_seo_box( $post ) {
	wp_nonce_field( 'cc_seo', 'cc_seo_nonce' );
	$d = (string) get_post_meta( $post->ID, '_cc_seo_desc', true );
	$i = (string) get_post_meta( $post->ID, '_cc_seo_image', true );
	echo '<p><label><b>Description</b> (Google and link previews, about 150 characters)<br><textarea name="cc_seo_desc" rows="4" class="widefat" maxlength="300">' . esc_textarea( $d ) . '</textarea></label></p>';
	echo '<p class="description" style="margin-top:-6px">Empty: the short description or the first lines of the page.</p>';
	echo '<p><label><b>Share image URL</b> (1200 × 630 works best)<br><input type="url" name="cc_seo_image" class="widefat" value="' . esc_attr( $i ) . '"></label></p>';
	echo '<p class="description" style="margin-top:-6px">Empty: the product or featured photo, or the Community Connect card.</p>';
}
add_action( 'save_post', function ( $post_id ) {
	if ( ! isset( $_POST['cc_seo_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['cc_seo_nonce'] ), 'cc_seo' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	update_post_meta( $post_id, '_cc_seo_desc', sanitize_textarea_field( wp_unslash( $_POST['cc_seo_desc'] ?? '' ) ) );
	update_post_meta( $post_id, '_cc_seo_image', esc_url_raw( wp_unslash( $_POST['cc_seo_image'] ?? '' ) ) );
} );

/* ---------- What a page says about itself ---------- */

function cc_seo_clip( $text, $max = 158 ) {
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( (string) $text ) ) ) );
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut = mb_substr( $text, 0, $max - 1 );
	$cut = preg_replace( '/\s+\S*$/u', '', $cut );
	return rtrim( $cut, " ,;:–-" ) . '…';
}

function cc_seo_description() {
	$id = is_singular() ? get_queried_object_id() : 0;
	if ( $id && ( $own = (string) get_post_meta( $id, '_cc_seo_desc', true ) ) ) {
		return cc_seo_clip( $own );
	}
	if ( is_front_page() ) {
		return cc_seo_clip( get_option( 'cc_seo_home_desc' ) ?: CC_SEO_HOME_DESC, 300 );
	}
	if ( function_exists( 'is_shop' ) && is_shop() ) {
		return 'Merch from our races and rides, and ways to keep the bikes rolling. Everything you spend goes back into Community Connect: the fleet, the overnights and the riders who need a hand to join.';
	}
	if ( is_tax( 'product_cat' ) ) {
		$t = get_queried_object();
		return cc_seo_clip( $t->description ?: $t->name . ' from the Community Connect shop. Everything you spend goes back into the collective.' );
	}
	if ( $id ) {
		if ( 'product' === get_post_type( $id ) && function_exists( 'wc_get_product' ) && ( $p = wc_get_product( $id ) ) ) {
			return cc_seo_clip( $p->get_short_description() ?: $p->get_description() );
		}
		$post = get_post( $id );
		return cc_seo_clip( has_excerpt( $id ) ? $post->post_excerpt : $post->post_content );
	}
	return cc_seo_clip( get_bloginfo( 'description' ) ?: CC_SEO_HOME_DESC );
}

/** [url, width, height, alt] of the image for link previews. */
function cc_seo_image() {
	$id = is_singular() ? get_queried_object_id() : 0;
	if ( $id && ( $own = (string) get_post_meta( $id, '_cc_seo_image', true ) ) ) {
		return array( $own, 0, 0, get_the_title( $id ) );
	}
	if ( $id && 'product' === get_post_type( $id ) && ( $att = get_post_thumbnail_id( $id ) ) ) {
		$card = cc_seo_card( (int) $att );
		if ( $card ) {
			return array( $card, 1200, 630, get_the_title( $id ) );
		}
	}
	if ( $id && ( $att = get_post_thumbnail_id( $id ) ) && ! is_front_page() ) {
		$card = cc_seo_card( (int) $att, true );
		if ( $card ) {
			return array( $card, 1200, 630, get_the_title( $id ) );
		}
	}
	return array( get_theme_file_uri( 'assets/images/share-default.jpg' ), 1200, 630, 'Community Connect × Standert: we share bikes. A bicycle collective in Cape Town.' );
}

/**
 * A 1200×630 JPG made from an image, cached in uploads/cc-share/.
 * Products: the photo on the shop's grey, with room around it ($fill false).
 * Photos: cropped to fill the card ($fill true).
 */
function cc_seo_card( $att, $fill = false ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return '';
	}
	$src = get_attached_file( $att );
	$big = image_get_intermediate_size( $att, 'large' );
	if ( $big && ! empty( $big['path'] ) ) {
		$try = trailingslashit( wp_get_upload_dir()['basedir'] ) . $big['path'];
		$src = file_exists( $try ) ? $try : $src;
	}
	if ( ! $src || ! file_exists( $src ) ) {
		return '';
	}
	$up   = wp_get_upload_dir();
	$name = 'cc-share/' . $att . '-' . ( $fill ? 'f' : 'p' ) . '-' . filemtime( $src ) . '.jpg';
	$path = trailingslashit( $up['basedir'] ) . $name;
	$url  = trailingslashit( $up['baseurl'] ) . $name;
	if ( file_exists( $path ) ) {
		return $url;
	}
	$img = @imagecreatefromstring( (string) file_get_contents( $src ) ); // phpcs:ignore
	if ( ! $img ) {
		return '';
	}
	wp_mkdir_p( dirname( $path ) );
	$w  = imagesx( $img );
	$h  = imagesy( $img );
	$cv = imagecreatetruecolor( 1200, 630 );
	imagefill( $cv, 0, 0, imagecolorallocate( $cv, 0xED, 0xED, 0xEC ) );
	if ( $fill ) {
		$s  = max( 1200 / $w, 630 / $h );
		$sw = (int) round( 1200 / $s );
		$sh = (int) round( 630 / $s );
		imagecopyresampled( $cv, $img, 0, 0, (int) ( ( $w - $sw ) / 2 ), (int) ( ( $h - $sh ) / 2 ), 1200, 630, $sw, $sh );
	} else {
		$s  = min( 1000 / $w, 540 / $h );
		$dw = (int) round( $w * $s );
		$dh = (int) round( $h * $s );
		// White-backed photos melt into the grey (multiply); transparent PNGs keep their own whites.
		$alpha = ( imagecolorat( $img, 0, 0 ) >> 24 ) > 0;
		if ( ! $alpha && defined( 'IMG_EFFECT_MULTIPLY' ) ) {
			imagelayereffect( $cv, IMG_EFFECT_MULTIPLY );
		} else {
			imagealphablending( $cv, true );
		}
		imagecopyresampled( $cv, $img, (int) ( ( 1200 - $dw ) / 2 ), (int) ( ( 630 - $dh ) / 2 ), 0, 0, $dw, $dh, $w, $h );
	}
	imageinterlace( $cv, true );
	$ok = imagejpeg( $cv, $path, 86 );
	imagedestroy( $cv );
	imagedestroy( $img );
	return $ok ? $url : '';
}

/* ---------- <head> ---------- */
add_action( 'wp_head', function () {
	if ( cc_seo_off() || is_404() || is_search() ) {
		return;
	}
	$desc  = cc_seo_description();
	$title = wp_get_document_title();
	$url   = is_front_page() ? home_url( '/' ) : ( wp_get_canonical_url() ?: home_url( add_query_arg( array() ) ) );
	if ( function_exists( 'is_shop' ) && is_shop() ) {
		$url = get_permalink( wc_get_page_id( 'shop' ) ) ?: home_url( '/shop/' );
	} elseif ( is_tax() ) {
		$url = get_term_link( get_queried_object() );
	}
	list( $img, $iw, $ih, $alt ) = cc_seo_image();
	$type = is_singular( 'product' ) ? 'product' : ( is_singular( 'post' ) ? 'article' : 'website' );
	$m    = array(
		array( 'name', 'description', $desc ),
		array( 'property', 'og:site_name', 'Community Connect' ),
		array( 'property', 'og:locale', 'en_ZA' ),
		array( 'property', 'og:type', $type ),
		array( 'property', 'og:title', is_front_page() ? 'Community Connect × Standert: we share bikes' : $title ),
		array( 'property', 'og:description', $desc ),
		array( 'property', 'og:url', $url ),
		array( 'property', 'og:image', $img ),
		array( 'property', 'og:image:alt', $alt ),
		array( 'name', 'twitter:card', 'summary_large_image' ),
		array( 'name', 'twitter:title', is_front_page() ? 'Community Connect × Standert: we share bikes' : $title ),
		array( 'name', 'twitter:description', $desc ),
		array( 'name', 'twitter:image', $img ),
	);
	if ( $iw ) {
		$m[] = array( 'property', 'og:image:width', (string) $iw );
		$m[] = array( 'property', 'og:image:height', (string) $ih );
	}
	if ( 'product' === $type && function_exists( 'wc_get_product' ) && ( $p = wc_get_product( get_queried_object_id() ) ) && ! cc_seo_is_fund( $p ) ) {
		$m[] = array( 'property', 'product:price:amount', (string) $p->get_price() );
		$m[] = array( 'property', 'product:price:currency', get_woocommerce_currency() );
	}
	echo "\n<!-- Community Connect: search & sharing -->\n";
	foreach ( $m as $x ) {
		if ( '' !== (string) $x[2] ) {
			printf( '<meta %s="%s" content="%s">' . "\n", $x[0], esc_attr( $x[1] ), esc_attr( $x[2] ) ); // phpcs:ignore
		}
	}
	if ( ( function_exists( 'is_shop' ) && is_shop() ) || is_tax( 'product_cat' ) ) {
		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $url ) );
	}
	foreach ( cc_seo_schema() as $graph ) {
		echo '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}
}, 2 );

function cc_seo_is_fund( $p ) {
	return function_exists( 'cc_is_sponsorship' ) && cc_is_sponsorship( $p );
}

/* ---------- Structured data ---------- */
function cc_seo_schema() {
	$home = home_url( '/' );
	$org  = array(
		'@type' => 'NGO', '@id' => $home . '#org', 'name' => 'Community Connect', 'legalName' => 'Community Connect NPC',
		'url' => $home, 'logo' => get_theme_file_uri( 'assets/images/cc-icon.png' ),
		'description' => 'A bicycle collective in Cape Town that shares Standert bikes for free.',
		'email' => 'contact@community-connect.cc',
		'address' => array( '@type' => 'PostalAddress', 'addressLocality' => 'Cape Town', 'addressRegion' => 'Western Cape', 'addressCountry' => 'ZA' ),
		'sameAs' => array( 'https://www.instagram.com/cc_x_standert/', 'https://www.strava.com/clubs/cc_x_standert' ),
	);
	$out = array();
	if ( is_front_page() ) {
		$graph = array( $org, array( '@type' => 'WebSite', '@id' => $home . '#site', 'url' => $home, 'name' => 'Community Connect', 'publisher' => array( '@id' => $home . '#org' ), 'inLanguage' => 'en-ZA' ) );
		foreach ( cc_seo_ride_events() as $e ) {
			$graph[] = $e;
		}
		$out[] = array( '@context' => 'https://schema.org', '@graph' => $graph );
	}
	if ( is_singular( 'product' ) && function_exists( 'wc_get_product' ) && ( $p = wc_get_product( get_queried_object_id() ) ) && ! cc_seo_is_fund( $p ) ) {
		$pre   = function_exists( 'cc_is_preorder' ) && cc_is_preorder( $p );
		$avail = $pre ? 'PreOrder' : ( $p->is_in_stock() ? 'InStock' : 'OutOfStock' );
		$imgs  = array_values( array_filter( array_map( function ( $a ) { return wp_get_attachment_image_url( $a, 'large' ); }, array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) ) ) );
		$offer = array( '@type' => 'Offer', 'url' => get_permalink( $p->get_id() ), 'priceCurrency' => get_woocommerce_currency(), 'price' => (string) $p->get_price(), 'availability' => 'https://schema.org/' . $avail, 'seller' => array( '@id' => $home . '#org' ),
			'shippingDetails' => array( '@type' => 'OfferShippingDetails', 'shippingDestination' => array( '@type' => 'DefinedRegion', 'addressCountry' => 'ZA' ), 'shippingRate' => array( '@type' => 'MonetaryAmount', 'value' => '120', 'currency' => 'ZAR' ) ) );
		$prod  = array( '@type' => 'Product', 'name' => $p->get_name(), 'description' => cc_seo_description(), 'sku' => $p->get_sku(), 'image' => $imgs, 'brand' => array( '@type' => 'Brand', 'name' => 'Community Connect × Standert' ), 'offers' => $offer );
		$crumbs = array( array( 'Shop', get_permalink( wc_get_page_id( 'shop' ) ) ) );
		$cats   = get_the_terms( $p->get_id(), 'product_cat' );
		if ( $cats && ! is_wp_error( $cats ) ) {
			$crumbs[] = array( $cats[0]->name, get_term_link( $cats[0] ) );
		}
		$crumbs[] = array( $p->get_name(), get_permalink( $p->get_id() ) );
		$list     = array();
		foreach ( $crumbs as $i => $c ) {
			$list[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1] );
		}
		$out[] = array( '@context' => 'https://schema.org', '@graph' => array( $prod, array( '@type' => 'BreadcrumbList', 'itemListElement' => $list ), $org ) );
	}
	return $out;
}

/** Our own dated group rides (FAR Inverted and the like) as events. Other organisers' races are left to them. */
function cc_seo_ride_events() {
	if ( ! class_exists( 'CC_Fleet_Frontend' ) || ! class_exists( 'CC_Fleet_Records' ) ) {
		return array();
	}
	$out = array();
	foreach ( CC_Fleet_Frontend::upcoming_events() as $e ) {
		$cats = CC_Fleet_Records::event_cats( $e );
		if ( ! in_array( 'group', $cats, true ) || CC_Fleet_Records::is_regular( $e ) || count( $out ) >= 10 ) {
			continue;
		}
		$out[] = array(
			'@type' => 'SportsEvent', 'name' => $e->name, 'startDate' => $e->start_date, 'endDate' => $e->end_date ?: $e->start_date,
			'eventStatus' => 'https://schema.org/EventScheduled', 'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'location' => array( '@type' => 'Place', 'name' => $e->location ?: 'Cape Town', 'address' => array( '@type' => 'PostalAddress', 'addressLocality' => 'Cape Town', 'addressCountry' => 'ZA' ) ),
			'description' => cc_seo_clip( $e->description ?: 'A Community Connect group ride.' ),
			'organizer' => array( '@id' => home_url( '/' ) . '#org' ), 'url' => home_url( '/#events' ),
			'image' => get_theme_file_uri( 'assets/images/share-default.jpg' ),
			'isAccessibleForFree' => true,
		);
	}
	return $out;
}

/* ---------- Robots and sitemap ---------- */
add_filter( 'wp_robots', function ( $robots ) {
	$private = function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() );
	if ( $private || is_search() ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
	} elseif ( get_option( 'blog_public' ) ) {
		$robots['max-image-preview'] = 'large';
	}
	return $robots;
} );
// No user pages in the sitemap (they'd show login names).
add_filter( 'wp_sitemaps_add_provider', function ( $provider, $name ) {
	return 'users' === $name ? false : $provider;
}, 10, 2 );
add_filter( 'wp_sitemaps_posts_query_args', function ( $args, $type ) {
	if ( 'page' === $type && function_exists( 'wc_get_page_id' ) ) {
		$args['post__not_in'] = array_filter( array( wc_get_page_id( 'cart' ), wc_get_page_id( 'checkout' ), wc_get_page_id( 'myaccount' ) ) );
	}
	return $args;
}, 10, 2 );

/* ---------- One main heading on the home page ---------- */
// The first hero headline is the page's <h1>, also when the hero was edited in the Site Editor.
add_filter( 'render_block_core/heading', function ( $html ) {
	static $done = false;
	if ( $done || ! is_front_page() || false === strpos( $html, 'cc-hero-title' ) ) {
		return $html;
	}
	$done = true;
	return preg_replace( array( '/<h2(\s|>)/', '/<\/h2>/' ), array( '<h1$1', '</h1>' ), $html, 1 );
} );
