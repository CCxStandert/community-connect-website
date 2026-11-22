<?php
/**
 * Cookie consent + Google Analytics 4 (Consent Mode v2, "basic").
 *
 * - Settings → General → "Google Analytics 4 Measurement ID" (G-XXXXXXXXXX). Empty = no analytics and no banner.
 * - Google's script only loads after a visitor presses "Accept". Before that, and with "Essential only",
 *   nothing is sent to Google. All consent types start as denied; only analytics_storage is ever granted.
 * - Never loads for logged-in admins, shop managers or Fleet Manager crew, so our own visits don't count.
 * - Events (sent only once analytics has loaded): view_item, add_to_cart, purchase (once per order),
 *   sponsorship_selected, outbound_click, bike_request and waitlist_join.
 */
defined( 'ABSPATH' ) || exit;

function cc_ga_id() {
	$id = strtoupper( trim( (string) get_option( 'cc_ga4_id', '' ) ) );
	return preg_match( '/^G-[A-Z0-9]{4,}$/', $id ) ? $id : '';
}

/** Our own visits don't count: admins, shop managers and Fleet Manager crew. */
function cc_ga_excluded() {
	return is_user_logged_in() && ( current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_cc_fleet' ) );
}

/* ---------- Settings → General ---------- */

add_action( 'admin_init', function () {
	register_setting( 'general', 'cc_ga4_id', array(
		'type'              => 'string',
		'sanitize_callback' => function ( $v ) {
			$v = strtoupper( trim( (string) $v ) );
			return preg_match( '/^G-[A-Z0-9]{4,}$/', $v ) ? $v : '';
		},
		'default'           => '',
	) );
	add_settings_field( 'cc_ga4_id', '<label for="cc_ga4_id">Google Analytics 4 Measurement ID</label>', function () {
		printf( '<input type="text" id="cc_ga4_id" name="cc_ga4_id" value="%s" class="regular-text code" placeholder="G-XXXXXXXXXX"><p class="description">From Google Analytics → Admin → Data streams. Leave empty to switch analytics (and the cookie notice) off. It only runs for visitors who press “Accept”, and never for logged-in crew.</p>', esc_attr( get_option( 'cc_ga4_id', '' ) ) );
	}, 'general' );
} );

/* ---------- Front end ---------- */

/** Consent defaults go first in <head>, before anything else could talk to Google. */
add_action( 'wp_head', function () {
	$id = cc_ga_id();
	if ( ! $id ) {
		return;
	}
	$cfg = array(
		'id'      => cc_ga_excluded() ? '' : $id, // Crew: banner still works, analytics never loads.
		'privacy' => get_privacy_policy_url(),
	);
	echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
		. "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'granted',security_storage:'granted'});"
		. 'window.CCConsent=' . wp_json_encode( $cfg ) . ';</script>' . "\n";
}, 0 );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! cc_ga_id() ) {
		return;
	}
	wp_enqueue_script( 'cc-consent', get_theme_file_uri( 'assets/js/consent.js' ), array(), CC_THEME_VERSION, true );
	$events = cc_ga_page_events();
	if ( $events ) {
		wp_add_inline_script( 'cc-consent', 'window.CCEvents=' . wp_json_encode( $events ) . ';', 'before' );
	}
} );

/** The notice: a small bar at the bottom, hidden until the script knows there's no choice yet. */
add_action( 'wp_footer', function () {
	if ( ! cc_ga_id() ) {
		return;
	}
	$privacy = get_privacy_policy_url();
	echo '<div class="cc-consent" data-cc-consent role="region" aria-label="Cookie notice" hidden><div class="cc-consent__in">'
		. '<p>We use essential cookies to keep your cart working. With your OK we also use Google Analytics to count visits, so we can show supporters how far the project reaches. No ads, no selling data.'
		. ( $privacy ? ' <a href="' . esc_url( $privacy ) . '">Privacy policy</a>' : '' ) . '</p>'
		. '<div class="cc-consent__btns"><button type="button" class="cc-consent__no" data-cc-consent-choice="essential">Essential only</button><button type="button" class="cc-consent__yes" data-cc-consent-choice="all">Accept</button></div>'
		. '</div></div>'; // phpcs:ignore -- static markup, escaped URL.
}, 5 );

/* ---------- Events that need server data ---------- */

/** GA4 item for a product (sku, name, category, price). */
function cc_ga_item( WC_Product $p, $price = null, $qty = 1 ) {
	$parent = $p->get_parent_id() ? wc_get_product( $p->get_parent_id() ) : $p;
	$cats   = wp_get_post_terms( $parent->get_id(), 'product_cat', array( 'fields' => 'names' ) );
	$item   = array(
		'item_id'       => $p->get_sku() ?: (string) $p->get_id(),
		'item_name'     => $parent->get_name(),
		'item_category' => $cats ? $cats[0] : '',
		'price'         => (float) ( null === $price ? $p->get_price() : $price ),
		'quantity'      => (int) $qty,
	);
	if ( $p->get_parent_id() ) {
		$item['item_variant'] = wc_get_formatted_variation( $p, true, false, false );
	}
	return $item;
}

/** view_item on product pages; purchase on the order-received page, once per order. */
function cc_ga_page_events() {
	if ( ! function_exists( 'is_product' ) || cc_ga_excluded() ) {
		return array();
	}
	$out = array();
	if ( is_product() ) {
		$p = wc_get_product( get_queried_object_id() );
		if ( $p ) {
			$price = cc_is_sponsorship( $p ) ? 0 : (float) $p->get_price();
			$out[] = array( 'view_item', array( 'currency' => get_woocommerce_currency(), 'value' => $price, 'items' => array( cc_ga_item( $p, $price ) ) ) );
		}
	}
	if ( is_order_received_page() ) {
		$order_id = absint( get_query_var( 'order-received' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		$key      = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $order && hash_equals( $order->get_order_key(), $key ) && ! $order->get_meta( '_cc_ga_sent' ) && ! $order->has_status( array( 'failed', 'cancelled' ) ) ) {
			$items = array();
			foreach ( $order->get_items() as $line ) {
				$prod = $line->get_product();
				if ( $prod ) {
					$items[] = cc_ga_item( $prod, $order->get_item_subtotal( $line, false, false ), $line->get_quantity() );
				}
			}
			$out[] = array( 'purchase', array(
				'transaction_id' => (string) $order->get_order_number(),
				'currency'       => $order->get_currency(),
				'value'          => (float) $order->get_total(),
				'shipping'       => (float) $order->get_shipping_total(),
				'items'          => $items,
			) );
			// Counted once, even if the page is reloaded (and even if this visitor said "Essential only").
			$order->update_meta_data( '_cc_ga_sent', current_time( 'mysql' ) );
			$order->save();
		}
	}
	return $out;
}
