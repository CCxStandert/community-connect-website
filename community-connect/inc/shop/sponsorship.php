<?php
/**
 * Sponsorships ("Sponsor a Rider" category): donors pick R150 / R500 / R1,000 or type any amount.
 *
 * The products are Virtual with a normal price (the default amount), so a plain link like
 * /shop/bike-maintenance-fund/?add-to-cart=ID still works. The chosen amount travels with the cart
 * item ('cc_amount'), is checked on the server (at least the minimum, whole rands, a sane maximum)
 * and becomes the item's price in woocommerce_before_calculate_totals.
 */
defined( 'ABSPATH' ) || exit;

const CC_MAX_AMOUNT = 100000;

/** Preset amounts for a fund (Shop extras box), default 150, 500, 1000. */
function cc_sponsor_presets( WC_Product $p ) {
	$raw  = (string) get_post_meta( $p->get_id(), '_cc_amounts', true );
	$vals = array_values( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', $raw ) ) ) );
	return $vals ?: array( 150, 500, 1000 );
}

/** The amount pills and the custom field, inside the product form. */
function cc_sponsor_fields( WC_Product $p ) {
	$presets = cc_sponsor_presets( $p );
	$default = $presets[1] ?? $presets[0];
	$min     = cc_shop_min_amount();
	ob_start();
	?>
	<fieldset class="cc-pick cc-amounts" data-cc-amounts>
		<legend>Your amount</legend>
		<div class="cc-pick__opts">
			<?php foreach ( $presets as $a ) : ?>
				<label class="cc-opt"><input type="radio" name="cc_preset" value="<?php echo (int) $a; ?>"<?php checked( $a, $default ); ?>><span><?php echo esc_html( cc_money( $a ) ); ?></span></label>
			<?php endforeach; ?>
		</div>
		<label class="cc-amount">
			<span>Custom amount (R)</span>
			<input type="number" name="cc_amount" inputmode="numeric" min="<?php echo (int) $min; ?>" max="<?php echo (int) CC_MAX_AMOUNT; ?>" step="1" value="<?php echo (int) $default; ?>" data-cc-amount required>
		</label>
		<p class="cc-muted">From <?php echo esc_html( cc_money( $min ) ); ?>. Paid securely by card or Instant EFT through Yoco. You get an email confirmation; we’re not able to issue Section 18A tax receipts yet.</p>
	</fieldset>
	<?php
	return ob_get_clean();
}

/** Read and check an amount: whole rands between the minimum and the maximum. */
function cc_sponsor_parse_amount( $raw ) {
	$raw = str_replace( array( 'R', 'r', ' ', ',' ), '', (string) $raw );
	if ( '' === $raw || ! is_numeric( $raw ) ) {
		return new WP_Error( 'cc_amount', 'Please enter an amount in rand.' );
	}
	$amount = (int) round( (float) $raw );
	if ( $amount < cc_shop_min_amount() ) {
		return new WP_Error( 'cc_amount', sprintf( 'The smallest amount is %s. Thank you for thinking of us!', cc_money( cc_shop_min_amount() ) ) );
	}
	if ( $amount > CC_MAX_AMOUNT ) {
		return new WP_Error( 'cc_amount', sprintf( 'For amounts over %s, please email us and we’ll set it up with you.', cc_money( CC_MAX_AMOUNT ) ) );
	}
	return $amount;
}

/** The amount sent with this add-to-cart, or the fund's default when there is none (plain links). */
function cc_sponsor_requested_amount( $product_id ) {
	$p   = wc_get_product( $product_id );
	$raw = isset( $_REQUEST['cc_amount'] ) ? wp_unslash( $_REQUEST['cc_amount'] ) : ( isset( $_REQUEST['cc_preset'] ) ? wp_unslash( $_REQUEST['cc_preset'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $raw && $p ) {
		$presets = cc_sponsor_presets( $p );
		return $presets[1] ?? $presets[0];
	}
	return cc_sponsor_parse_amount( $raw );
}

add_filter( 'woocommerce_add_to_cart_validation', function ( $ok, $product_id ) {
	if ( $ok && cc_is_sponsorship( $product_id ) ) {
		$amount = cc_sponsor_requested_amount( $product_id );
		if ( is_wp_error( $amount ) ) {
			wc_add_notice( $amount->get_error_message(), 'error' );
			return false;
		}
	}
	return $ok;
}, 10, 2 );

add_filter( 'woocommerce_add_cart_item_data', function ( $data, $product_id ) {
	if ( cc_is_sponsorship( $product_id ) ) {
		$amount = cc_sponsor_requested_amount( $product_id );
		if ( ! is_wp_error( $amount ) ) {
			$data['cc_amount'] = $amount; // Different amounts stay separate lines.
		}
	}
	return $data;
}, 10, 2 );

add_filter( 'woocommerce_get_cart_item_from_session', function ( $item, $values ) {
	if ( isset( $values['cc_amount'] ) ) {
		$item['cc_amount'] = (int) $values['cc_amount'];
	}
	return $item;
}, 10, 2 );

add_action( 'woocommerce_before_calculate_totals', function ( $cart ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}
	foreach ( $cart->get_cart() as $item ) {
		if ( ! empty( $item['cc_amount'] ) && cc_is_sponsorship( $item['data'] ) ) {
			$item['data']->set_price( (float) $item['cc_amount'] );
		}
	}
}, 20 );

/** One of each fund per order line: a different amount makes its own line, so quantity stays 1. */
add_filter( 'woocommerce_is_sold_individually', function ( $yes, $product ) {
	return $yes || cc_is_sponsorship( $product );
}, 10, 2 );

/** Product price shown on the page and in listings: "From R50". */
add_filter( 'woocommerce_get_price_html', function ( $html, $product ) {
	return cc_is_sponsorship( $product ) && ! is_admin() ? esc_html( 'From ' . cc_money( cc_shop_min_amount() ) ) : $html;
}, 10, 2 );

/** Orders: keep a clear record that this line was a sponsorship and of how much. */
add_action( 'woocommerce_checkout_create_order_line_item', function ( $line, $key, $values ) {
	if ( ! empty( $values['cc_amount'] ) ) {
		$line->add_meta_data( 'Sponsorship', cc_money( (int) $values['cc_amount'] ), true );
	}
}, 10, 3 );

/** "Add to cart" buttons in lists would skip the amount: send people to the fund page instead. */
add_filter( 'woocommerce_product_add_to_cart_url', function ( $url, $product ) {
	return cc_is_sponsorship( $product ) ? get_permalink( $product->get_id() ) : $url;
}, 10, 2 );

/** True when the cart holds only sponsorships (nothing to ship). */
function cc_cart_only_sponsorships() {
	if ( ! WC()->cart || WC()->cart->is_empty() ) {
		return false;
	}
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( ! cc_is_sponsorship( $item['data'] ) ) {
			return false;
		}
	}
	return true;
}
