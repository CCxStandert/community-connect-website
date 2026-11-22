<?php
/**
 * WP Admin bits for the shop pages:
 * - "Shop page extras" box on each product: subtitle lines, fit note, size table, sponsorship amounts.
 * - A "Swatch colour" on every colour attribute term (Products → Attributes → Colour → terms).
 */
defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes_product', function () {
	add_meta_box( 'cc-shop-extras', 'Shop page extras', 'cc_shop_extras_box', 'product', 'normal', 'high' );
} );

function cc_shop_extras_box( $post ) {
	wp_nonce_field( 'cc_shop_extras', 'cc_shop_extras_nonce' );
	$f = function ( $k ) use ( $post ) {
		return (string) get_post_meta( $post->ID, $k, true );
	};
	?>
	<p><label><b>Lines under the name</b> (one per line, e.g. the colour and the edition)<br>
		<textarea name="cc_subtitle" rows="2" class="widefat" placeholder="Sand&#10;Gravel Hearts Club"><?php echo esc_textarea( $f( '_cc_subtitle' ) ); ?></textarea></label></p>
	<p><label><b>Fit note</b> (shown under “What’s my size?”)<br>
		<textarea name="cc_fit" rows="3" class="widefat" placeholder="Unisex, boxy and oversized. Runs one size small."><?php echo esc_textarea( $f( '_cc_fit' ) ); ?></textarea></label></p>
	<p><label><b>Size table</b> (one measurement per line, values in the order of the sizes, in cm)<br>
		<textarea name="cc_size_table" rows="4" class="widefat" placeholder="Chest: 120, 125, 130, 135&#10;Body length: 65, 67, 69, 71"><?php echo esc_textarea( $f( '_cc_size_table' ) ); ?></textarea></label></p>
	<p><label><b>Sponsorship amounts</b> (only for “Sponsor a Rider” products; the middle one is pre-selected)<br>
		<input type="text" name="cc_amounts" class="regular-text" value="<?php echo esc_attr( $f( '_cc_amounts' ) ); ?>" placeholder="150, 500, 1000"></label></p>
	<?php
}

add_action( 'save_post_product', function ( $post_id ) {
	if ( ! isset( $_POST['cc_shop_extras_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['cc_shop_extras_nonce'] ), 'cc_shop_extras' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	foreach ( array( 'cc_subtitle', 'cc_fit', 'cc_size_table', 'cc_amounts' ) as $k ) {
		if ( isset( $_POST[ $k ] ) ) {
			update_post_meta( $post_id, '_' . $k, sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) );
		}
	}
} );

/* ---------- Swatch colours on colour attribute terms ---------- */

add_action( 'admin_init', function () {
	foreach ( wc_get_attribute_taxonomy_names() as $tax ) {
		if ( ! cc_is_colour_attribute( $tax ) ) {
			continue;
		}
		add_action( $tax . '_add_form_fields', function () {
			echo '<div class="form-field"><label for="cc_swatch">Swatch colour</label><input type="color" name="cc_swatch" id="cc_swatch" value="#141217"><p>The dot shown on the product page.</p></div>';
		} );
		add_action( $tax . '_edit_form_fields', function ( $term ) {
			printf( '<tr class="form-field"><th><label for="cc_swatch">Swatch colour</label></th><td><input type="color" name="cc_swatch" id="cc_swatch" value="%s"><p class="description">The dot shown on the product page.</p></td></tr>', esc_attr( get_term_meta( $term->term_id, 'cc_swatch', true ) ?: '#141217' ) );
		} );
		$save = function ( $term_id ) {
			if ( isset( $_POST['cc_swatch'] ) && current_user_can( 'manage_product_terms' ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- the term screens check their own nonce.
				update_term_meta( $term_id, 'cc_swatch', sanitize_hex_color( wp_unslash( $_POST['cc_swatch'] ) ) ); // phpcs:ignore
			}
		};
		add_action( 'created_' . $tax, $save );
		add_action( 'edited_' . $tax, $save );
	}
} );
