<?php
/**
 * Checkout and shipping.
 *
 * - Cart and checkout are WooCommerce's classic pages ([woocommerce_cart], [woocommerce_checkout]) in the
 *   theme's page-cart / page-checkout templates, so the field rules below apply and our styles fit.
 * - Shipping (zone "South Africa", made by the store setup): courier R120, free from R2,000, or free
 *   collection at a Cape Town community ride. When free courier applies, the paid courier is hidden.
 * - A cart with only sponsorships needs no shipping (they're Virtual) and asks just for
 *   first name, last name, email and an optional note.
 */
defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	// Nobody needs a company field here.
	unset( $fields['billing']['billing_company'], $fields['shipping']['shipping_company'] );
	if ( isset( $fields['order']['order_comments'] ) ) {
		$fields['order']['order_comments']['label']       = 'Note';
		$fields['order']['order_comments']['placeholder'] = 'Anything we should know? A rider you’d like to support, a delivery tip…';
	}
	if ( cc_cart_only_sponsorships() ) {
		$keep = array( 'billing_first_name', 'billing_last_name', 'billing_email' );
		foreach ( array_keys( $fields['billing'] ) as $k ) {
			if ( ! in_array( $k, $keep, true ) ) {
				unset( $fields['billing'][ $k ] );
			}
		}
		$fields['billing']['billing_email']['priority'] = 25;
		$fields['shipping'] = array();
	}
	return $fields;
}, 20 );

// A donor-only checkout doesn't ask for a country: use the store's (South Africa) for the order.
add_action( 'woocommerce_checkout_create_order', function ( $order ) {
	if ( cc_cart_only_sponsorships() && ! $order->get_billing_country() ) {
		$order->set_billing_country( WC()->countries->get_base_country() );
	}
} );

add_filter( 'woocommerce_cart_needs_shipping_address', function ( $needs ) {
	return cc_cart_only_sponsorships() ? false : $needs;
} );

/** Free courier from R2,000: then hide the paid courier (collection stays an option). */
add_filter( 'woocommerce_package_rates', function ( $rates ) {
	$has_free = false;
	foreach ( $rates as $r ) {
		if ( 'free_shipping' === $r->get_method_id() ) {
			$has_free = true;
		}
	}
	if ( $has_free ) {
		foreach ( $rates as $id => $r ) {
			if ( 'flat_rate' === $r->get_method_id() ) {
				unset( $rates[ $id ] );
			}
		}
	}
	return $rates;
}, 20 );

/** Friendlier wording on the order-received page for donors. */
add_filter( 'woocommerce_thankyou_order_received_text', function ( $text, $order ) {
	if ( ! $order ) {
		return $text;
	}
	foreach ( $order->get_items() as $item ) {
		if ( ! cc_is_sponsorship( $item->get_product_id() ) ) {
			return 'Thank you! Your order is in. We’ll email you when it’s on its way, or when it’s ready to collect.';
		}
	}
	return 'Thank you for keeping the wheels turning. Your sponsorship is in, and a confirmation is on its way to your inbox.';
}, 10, 2 );

/** The terms checkbox, in our words. */
add_filter( 'woocommerce_get_terms_and_conditions_checkbox_text', function () {
	return 'I’ve read and accept the [terms]';
} );

/** No checkout extras we don't use: coupons and account creation stay off (guest checkout). */
add_filter( 'woocommerce_coupons_enabled', '__return_false' );
