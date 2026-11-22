<?php
/** Small helpers shared by the shop parts. */
defined( 'ABSPATH' ) || exit;

/** Is the shop open to the public? (WooCommerce "Coming soon" for store pages = not yet.) */
function cc_shop_is_live() {
	return ! ( 'yes' === get_option( 'woocommerce_coming_soon' ) && 'yes' === get_option( 'woocommerce_store_pages_only' ) );
}

/** Show shop links and the cart: when live, or to people who manage the shop. */
function cc_shop_visible() {
	return cc_shop_is_live() || current_user_can( 'manage_woocommerce' );
}

function cc_shop_min_amount() {
	return max( 1, (int) get_option( 'cc_shop_min_amount', 50 ) );
}

function cc_is_sponsorship( $product ) {
	$product = $product instanceof WC_Product ? $product : wc_get_product( $product );
	if ( ! $product ) {
		return false;
	}
	$id = $product->get_parent_id() ?: $product->get_id();
	return has_term( CC_SPONSOR_CAT, 'product_cat', $id );
}

/** "R1,300" with WooCommerce's own currency settings, as plain text. */
function cc_money( $amount ) {
	return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
}

/** Lines under the product name, e.g. "Sand" / "Gravel Hearts Club" (Shop extras box). */
function cc_product_subtitle_lines( $product ) {
	$raw = (string) get_post_meta( $product->get_id(), '_cc_subtitle', true );
	return array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', $raw ) ) ) );
}

/** The theme's main shop page URL, with an optional category filter. */
function cc_shop_url( $cat = '' ) {
	$url = wc_get_page_permalink( 'shop' );
	return $cat ? add_query_arg( 'cat', $cat, $url ) : $url;
}

/**
 * Sizes of a variable product in their natural order, each with whether any variation of it is in stock.
 * @return array<int,array{slug:string,name:string,in_stock:bool}>
 */
function cc_size_states( WC_Product $product, $taxonomy = 'pa_size' ) {
	if ( ! $product->is_type( 'variable' ) ) {
		return array();
	}
	$attrs = $product->get_variation_attributes();
	if ( empty( $attrs[ $taxonomy ] ) ) {
		return array();
	}
	$stock = array();
	foreach ( $product->get_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v || ! $v->is_purchasable() || 'publish' !== get_post_status( $vid ) ) {
			continue;
		}
		$slug = (string) ( $v->get_attributes()[ $taxonomy ] ?? '' ); // Term slug; '' means "any size".
		if ( '' === $slug ) {
			continue;
		}
		$stock[ $slug ] = ( $stock[ $slug ] ?? false ) || $v->is_in_stock();
	}
	$out   = array();
	$terms = wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'all' ) ); // Sorted by the attribute's order.
	foreach ( $terms as $t ) {
		if ( in_array( $t->slug, $attrs[ $taxonomy ], true ) ) {
			$out[] = array( 'slug' => $t->slug, 'name' => $t->name, 'in_stock' => ! empty( $stock[ $t->slug ] ) );
		}
	}
	return $out;
}

/** Image URL for a product or attachment at a size, or ''. */
function cc_img( $attachment_id, $size = 'woocommerce_single' ) {
	$src = $attachment_id ? wp_get_attachment_image_url( $attachment_id, $size ) : '';
	return $src ?: '';
}

/** The plain-language shipping note used on product pages and in the cart. */
function cc_shipping_note() {
	$free = (float) get_option( 'cc_shop_free_from', 2000 );
	return sprintf(
		'Courier anywhere in South Africa for %1$s, free from %2$s. Or collect for free at one of our community rides in Cape Town. Orders are packed by hand by our volunteers.',
		cc_money( (float) get_option( 'cc_shop_courier', 120 ) ),
		cc_money( $free )
	);
}

/** Pre-order: the product can be ordered now and ships when it arrives (tick it in “Shop page extras”). */
function cc_is_preorder( $p ) {
	$p = $p instanceof WC_Product ? $p : wc_get_product( $p );
	if ( ! $p ) {
		return false;
	}
	$id = $p->get_parent_id() ?: $p->get_id();
	return 'yes' === get_post_meta( $id, '_cc_preorder', true );
}

