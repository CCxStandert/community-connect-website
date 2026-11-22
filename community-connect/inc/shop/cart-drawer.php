<?php
/**
 * Header cart button + slide-over cart.
 *
 * [cc_cart_button] sits in the header (parts/header.html). The drawer is printed once in the footer.
 * shop.js talks to three small endpoints on WooCommerce's own AJAX address (?wc-ajax=…):
 *   cc_cart  → the drawer's current contents
 *   cc_add   → add from a product page (sizes, colours and sponsorship amounts included)
 *   cc_qty   → change a quantity (0 removes)
 * Each answers with the same state: item count, the drawer's HTML and the subtotal.
 */
defined( 'ABSPATH' ) || exit;

add_shortcode( 'cc_cart_button', function () {
	if ( ! cc_shop_visible() || ! WC()->cart ) {
		return '';
	}
	$n = WC()->cart->get_cart_contents_count();
	return '<button type="button" class="cc-cartbtn" data-cc-cart-open aria-controls="cc-cart" aria-label="Cart, ' . (int) $n . ' item' . ( 1 === $n ? '' : 's' ) . '">'
		. '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M5 8h14l-1.2 12H6.2L5 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>'
		. '<span class="cc-cartbtn__n" data-cc-cart-count' . ( $n ? '' : ' hidden' ) . '>' . (int) $n . '</span></button>';
} );

/** The drawer's inside: items, subtotal, shipping note, checkout. */
function cc_cart_drawer_html() {
	$cart = WC()->cart;
	if ( ! $cart || $cart->is_empty() ) {
		return '<div class="cc-cart__empty"><p>Your cart is empty.</p><a class="cc-btn" href="' . esc_url( cc_shop_url() ) . '">Go to the shop</a></div>';
	}
	ob_start();
	echo '<ul class="cc-cart__items">';
	foreach ( $cart->get_cart() as $key => $item ) {
		$p = $item['data'];
		if ( ! $p ) {
			continue;
		}
		$parent = $p->get_parent_id() ?: $p->get_id();
		$img    = $p->get_image_id() ?: get_post_thumbnail_id( $parent );
		$bits   = array();
		if ( ! empty( $item['variation'] ) ) {
			foreach ( $item['variation'] as $att => $val ) {
				$tax  = str_replace( 'attribute_', '', $att );
				$term = taxonomy_exists( $tax ) ? get_term_by( 'slug', $val, $tax ) : null;
				$bits[] = wc_attribute_label( $tax ) . ': ' . ( $term ? $term->name : $val );
			}
		}
		$sponsor = ! empty( $item['cc_amount'] );
		if ( $sponsor ) {
			$bits[] = 'Sponsorship';
		}
		printf(
			'<li class="cc-cart__item" data-key="%1$s"><a class="cc-cart__img" href="%2$s">%3$s</a><div class="cc-cart__txt"><a href="%2$s"><b>%4$s</b></a><small>%5$s</small><div class="cc-cart__row">%6$s<span class="cc-cart__price">%7$s</span></div></div></li>',
			esc_attr( $key ),
			esc_url( get_permalink( $parent ) ),
			$img ? wp_get_attachment_image( $img, 'woocommerce_gallery_thumbnail', false, array( 'alt' => '' ) ) : '<span class="cc-cart__ph"></span>',
			esc_html( get_the_title( $parent ) ),
			esc_html( implode( ' · ', $bits ) ),
			$p->is_sold_individually()
				? '<button type="button" class="cc-link" data-cc-qty="0">Remove</button>'
				: '<span class="cc-step"><button type="button" data-cc-qty="' . (int) ( $item['quantity'] - 1 ) . '" aria-label="One less">−</button><span>' . (int) $item['quantity'] . '</span><button type="button" data-cc-qty="' . (int) ( $item['quantity'] + 1 ) . '" aria-label="One more">+</button></span>',
			wp_kses_post( $cart->get_product_subtotal( $p, $item['quantity'] ) )
		);
	}
	echo '</ul>';
	$ships = $cart->needs_shipping();
	printf(
		'<div class="cc-cart__foot"><p class="cc-cart__sum"><span>Subtotal</span><b>%s</b></p><p class="cc-muted">%s</p><a class="cc-btn cc-btn--ink" href="%s">Checkout</a><a class="cc-link" href="%s">View cart</a></div>',
		wp_kses_post( $cart->get_cart_subtotal() ),
		$ships ? esc_html( 'Courier ' . cc_money( (float) get_option( 'cc_shop_courier', 120 ) ) . ', free from ' . cc_money( (float) get_option( 'cc_shop_free_from', 2000 ) ) . ', or collect for free at a community ride.' ) : 'Nothing to ship: sponsorships go straight to work.',
		esc_url( wc_get_checkout_url() ),
		esc_url( wc_get_cart_url() )
	);
	return ob_get_clean();
}

function cc_cart_state( $extra = array() ) {
	return array_merge( array(
		'count' => WC()->cart ? WC()->cart->get_cart_contents_count() : 0,
		'html'  => cc_cart_drawer_html(),
	), $extra );
}

add_action( 'wp_footer', function () {
	if ( ! cc_shop_visible() || ! WC()->cart || is_checkout() ) {
		return;
	}
	echo '<div class="cc-cart" id="cc-cart" data-cc-cart hidden><div class="cc-cart__shade" data-cc-cart-close></div>'
		. '<aside class="cc-cart__panel" role="dialog" aria-modal="true" aria-labelledby="cc-cart-title"><div class="cc-cart__head"><h2 id="cc-cart-title">Your cart</h2><button type="button" class="cc-x" data-cc-cart-close aria-label="Close the cart">×</button></div>'
		. '<div class="cc-cart__body" data-cc-cart-body>' . cc_cart_drawer_html() . '</div></aside></div>'; // phpcs:ignore -- built from escaped parts.
} );

add_action( 'wc_ajax_cc_cart', function () {
	wp_send_json( cc_cart_state() );
} );

add_action( 'wc_ajax_cc_qty', function () {
	$key = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$qty = max( 0, (int) ( $_POST['qty'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( $key && WC()->cart->get_cart_item( $key ) ) {
		$qty ? WC()->cart->set_quantity( $key, $qty, true ) : WC()->cart->remove_cart_item( $key );
	}
	wp_send_json( cc_cart_state() );
} );

add_action( 'wc_ajax_cc_add', function () {
	// phpcs:disable WordPress.Security.NonceVerification -- adding to your own cart, like WooCommerce's add_to_cart.
	$product_id   = absint( $_POST['product_id'] ?? 0 );
	$variation_id = absint( $_POST['variation_id'] ?? 0 );
	$qty          = max( 1, (int) ( $_POST['quantity'] ?? 1 ) );
	$product      = wc_get_product( $variation_id ?: $product_id );
	if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
		wp_send_json( cc_cart_state( array( 'ok' => false, 'message' => 'That product isn’t available.' ) ) );
	}
	$variation = array();
	if ( $variation_id ) {
		foreach ( $product->get_variation_attributes() as $att => $val ) {
			$posted            = isset( $_POST[ $att ] ) ? wc_clean( wp_unslash( $_POST[ $att ] ) ) : '';
			$variation[ $att ] = '' !== $val ? $val : $posted;
		}
	}
	// phpcs:enable
	wc_clear_notices();
	$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $qty, $variation_id, $variation );
	$key    = $passed ? WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $variation ) : false;
	$errors = wp_list_pluck( wc_get_notices( 'error' ), 'notice' );
	wc_clear_notices();
	if ( $key ) {
		do_action( 'woocommerce_ajax_added_to_cart', $product_id );
	}
	wp_send_json( cc_cart_state( array(
		'ok'      => (bool) $key,
		'message' => $key ? 'Added to your cart.' : wp_strip_all_tags( implode( ' ', $errors ) ?: 'Sorry, that didn’t work. Please try again.' ),
	) ) );
} );
