<?php
/**
 * Product page: [cc_product].
 * Standert's product page: photos on the left (swipe on phones), name with its subtitle lines,
 * price, a "Shipping & Delivery" pill, the short description with "Read more", a boxed size picker
 * with "What's my size?", and one big "Add to cart". The info drawer has tabs for shipping, sizing and details.
 *
 * The form is a normal WooCommerce add-to-cart form (it works without JavaScript); shop.js turns the
 * pills into a live variation picker and adds to the cart drawer without reloading.
 */
defined( 'ABSPATH' ) || exit;

add_shortcode( 'cc_product', 'cc_product_render' );

/** The attribute that gets colour swatches instead of pills. */
function cc_is_colour_attribute( $taxonomy ) {
	return (bool) preg_match( '/colou?r/i', $taxonomy );
}

/** Size table rows from the "Shop extras" box: "Chest: 120, 125, 130" → [label, [values]]. */
function cc_size_table( WC_Product $p ) {
	$rows = array();
	foreach ( preg_split( '/\r?\n/', (string) get_post_meta( $p->get_id(), '_cc_size_table', true ) ) as $line ) {
		if ( false === strpos( $line, ':' ) ) {
			continue;
		}
		[ $label, $vals ] = array_map( 'trim', explode( ':', $line, 2 ) );
		if ( '' !== $label && '' !== $vals ) {
			$rows[] = array( $label, array_map( 'trim', explode( ',', $vals ) ) );
		}
	}
	return $rows;
}

/** Variation data for the picker: attributes, stock, price, photo. */
function cc_variation_data( WC_Product_Variable $p ) {
	$out = array();
	foreach ( $p->get_available_variations() as $v ) {
		$out[] = array(
			'id'    => (int) $v['variation_id'],
			'attrs' => $v['attributes'],
			'stock' => (bool) $v['is_in_stock'],
			'buy'   => (bool) $v['is_purchasable'],
			'price' => wp_strip_all_tags( $v['price_html'] ?: $p->get_price_html() ),
			'img'   => ! empty( $v['image_id'] ) && (int) $v['image_id'] !== (int) $p->get_image_id() ? array( 'src' => $v['image']['src'] ?? '', 'srcset' => $v['image']['srcset'] ?? '', 'id' => (int) $v['image_id'] ) : null,
		);
	}
	return $out;
}

function cc_product_render() {
	global $product;
	$p = $product instanceof WC_Product ? $product : wc_get_product( get_the_ID() );
	if ( ! $p ) {
		return '';
	}
	$sponsor  = cc_is_sponsorship( $p );
	$lines    = cc_product_subtitle_lines( $p );
	$images   = array_values( array_unique( array_filter( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) ) ) );
	$variable = $p->is_type( 'variable' );
	$attrs    = $variable ? $p->get_variation_attributes() : array();
	$table    = cc_size_table( $p );
	$fit      = trim( (string) get_post_meta( $p->get_id(), '_cc_fit', true ) );
	$has_size = $variable && isset( $attrs['pa_size'] );
	$desc     = trim( (string) $p->get_description() );
	$short    = trim( wp_strip_all_tags( (string) $p->get_short_description() ) );
	$in_stock = $p->is_in_stock();
	ob_start();
	?>
	<section class="cc-pdp<?php echo $sponsor ? ' cc-pdp--fund' : ''; ?>">
		<div class="cc-pdp__gallery" data-cc-gallery>
			<div class="cc-pdp__track">
				<?php if ( $images ) : ?>
					<?php foreach ( $images as $i => $id ) : ?>
						<figure class="cc-pdp__slide"><?php echo wp_get_attachment_image( $id, 'woocommerce_single', false, array( 'loading' => $i ? 'lazy' : 'eager', 'fetchpriority' => $i ? 'auto' : 'high', 'sizes' => '(min-width: 980px) 50vw, 100vw', 'data-cc-main' => $i ? null : '1' ) ); ?></figure>
					<?php endforeach; ?>
				<?php else : ?>
					<figure class="cc-pdp__slide cc-pdp__slide--ph"><span><?php echo $sponsor ? esc_html( cc_money( 500 ) ) : 'photo to come'; ?></span></figure>
				<?php endif; ?>
			</div>
			<?php if ( count( $images ) > 1 ) : ?>
				<div class="cc-pdp__dots"><?php foreach ( $images as $i => $id ) : ?><button type="button" aria-label="Photo <?php echo (int) $i + 1; ?>"<?php echo $i ? '' : ' aria-current="true"'; ?>></button><?php endforeach; ?></div>
			<?php endif; ?>
		</div>

		<div class="cc-pdp__info">
			<p class="cc-pdp__crumbs"><a href="<?php echo esc_url( cc_shop_url() ); ?>">Shop</a> / <a href="<?php echo esc_url( cc_shop_url( $sponsor ? CC_SPONSOR_CAT : CC_APPAREL_CAT ) ); ?>"><?php echo $sponsor ? 'Sponsor a Rider' : 'Apparel &amp; Gear'; ?></a></p>
			<h1 class="cc-pdp__name"><b><?php echo esc_html( $p->get_name() ); ?></b><?php foreach ( $lines as $l ) : ?><span><?php echo esc_html( $l ); ?></span><?php endforeach; ?></h1>
			<?php if ( ! $sponsor ) : ?>
				<p class="cc-pdp__price"><span data-cc-price><?php echo wp_kses_post( $p->get_price_html() ); ?></span> <small>excl. shipping</small></p>
				<p><button type="button" class="cc-info-pill" data-cc-sheet="shipping"><span aria-hidden="true">i</span> Shipping &amp; Delivery</button></p>
			<?php endif; ?>
			<?php if ( $short || $desc ) : ?>
				<div class="cc-pdp__desc">
					<p><?php echo esc_html( $short ?: wp_trim_words( wp_strip_all_tags( $desc ), 40 ) ); ?></p>
					<?php if ( $desc ) : ?><button type="button" class="cc-link" data-cc-sheet="details">Read more</button><?php endif; ?>
				</div>
			<?php endif; ?>

			<form class="cc-buy" novalidate method="post" action="<?php echo esc_url( get_permalink( $p->get_id() ) ); ?>" data-cc-buy data-product="<?php echo (int) $p->get_id(); ?>"<?php echo $variable ? ' data-variations="' . esc_attr( wp_json_encode( cc_variation_data( $p ) ) ) . '"' : ''; ?><?php echo $sponsor ? ' data-sponsor="1"' : ''; ?>>
				<?php foreach ( $attrs as $tax => $options ) : ?>
					<?php
					$label  = wc_attribute_label( $tax, $p );
					$terms  = taxonomy_exists( $tax ) ? wc_get_product_terms( $p->get_id(), $tax, array( 'fields' => 'all' ) ) : array();
					$colour = cc_is_colour_attribute( $tax );
					$states = 'pa_size' === $tax ? wp_list_pluck( cc_size_states( $p ), 'in_stock', 'slug' ) : array();
					?>
					<fieldset class="cc-pick<?php echo $colour ? ' cc-pick--swatch' : ''; ?>" data-cc-attr="attribute_<?php echo esc_attr( sanitize_title( $tax ) ); ?>">
						<legend><?php echo esc_html( $label ); ?> <span class="cc-pick__chosen" data-cc-chosen></span></legend>
						<div class="cc-pick__opts">
							<?php foreach ( $terms ?: array_map( function ( $o ) { return (object) array( 'slug' => $o, 'name' => $o, 'term_id' => 0 ); }, $options ) as $t ) : ?>
								<?php
								if ( ! in_array( $t->slug, $options, true ) ) {
									continue;
								}
								$gone = isset( $states[ $t->slug ] ) && ! $states[ $t->slug ];
								$hex  = $colour && $t->term_id ? (string) get_term_meta( $t->term_id, 'cc_swatch', true ) : '';
								?>
								<label class="cc-opt<?php echo $gone ? ' is-gone' : ''; ?>"<?php echo $hex ? ' style="--sw:' . esc_attr( $hex ) . '"' : ''; ?>>
									<input type="radio" name="attribute_<?php echo esc_attr( sanitize_title( $tax ) ); ?>" value="<?php echo esc_attr( $t->slug ); ?>" data-name="<?php echo esc_attr( $t->name ); ?>"<?php echo $gone ? ' disabled' : ''; ?> required>
									<span><?php echo $colour ? '<i aria-hidden="true"></i><em>' . esc_html( $t->name ) . '</em>' : esc_html( $t->name ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<?php if ( 'pa_size' === $tax && ( $table || $fit ) ) : ?>
							<button type="button" class="cc-info-pill" data-cc-sheet="size"><span aria-hidden="true">i</span> What’s my size?</button>
						<?php endif; ?>
					</fieldset>
				<?php endforeach; ?>

				<?php if ( $sponsor ) : ?>
					<?php echo cc_sponsor_fields( $p ); // phpcs:ignore -- escaped inside. ?>
				<?php endif; ?>

				<input type="hidden" name="add-to-cart" value="<?php echo (int) $p->get_id(); ?>">
				<input type="hidden" name="product_id" value="<?php echo (int) $p->get_id(); ?>">
				<?php if ( $variable ) : ?><input type="hidden" name="variation_id" value="" data-cc-variation><?php endif; ?>
				<input type="hidden" name="quantity" value="1">
				<button type="submit" class="cc-buy__btn" data-cc-buy-btn<?php echo $in_stock ? '' : ' disabled'; ?> data-label="<?php echo $sponsor ? 'Sponsor now' : 'Add to cart'; ?>">
					<?php echo $in_stock ? ( $sponsor ? 'Sponsor now' : ( $has_size ? 'Choose a size' : 'Add to cart' ) ) : 'Sold out'; ?>
				</button>
				<p class="cc-buy__msg" data-cc-msg role="status" aria-live="polite"></p>
			</form>
		</div>
	</section>

	<div class="cc-sheet" data-cc-sheet-panel hidden>
		<div class="cc-sheet__box" role="dialog" aria-modal="true" aria-labelledby="cc-sheet-title">
			<div class="cc-sheet__head">
				<h2 id="cc-sheet-title"><?php echo esc_html( $p->get_name() ); ?></h2>
				<button type="button" class="cc-x" data-cc-sheet-close aria-label="Close">×</button>
			</div>
			<div class="cc-tabs" role="tablist">
				<?php if ( ! $sponsor ) : ?><button type="button" role="tab" data-tab="shipping">Shipping &amp; Delivery</button><?php endif; ?>
				<?php if ( $table || $fit ) : ?><button type="button" role="tab" data-tab="size">What’s my size?</button><?php endif; ?>
				<?php if ( $desc ) : ?><button type="button" role="tab" data-tab="details">Details</button><?php endif; ?>
			</div>
			<?php if ( ! $sponsor ) : ?>
				<div class="cc-tab" role="tabpanel" data-panel="shipping">
					<p><?php echo esc_html( cc_shipping_note() ); ?></p>
					<p>Changed your mind? You can send things back within 7 days of receiving them. <a href="<?php echo esc_url( get_permalink( wc_terms_and_conditions_page_id() ) ?: home_url( '/terms/' ) ); ?>">Shop terms &amp; returns</a></p>
				</div>
			<?php endif; ?>
			<?php if ( $table || $fit ) : ?>
				<div class="cc-tab" role="tabpanel" data-panel="size">
					<?php if ( $fit ) : ?><p><?php echo nl2br( esc_html( $fit ) ); ?></p><?php endif; ?>
					<?php if ( $table ) : ?>
						<?php $sizes = wp_list_pluck( cc_size_states( $p ), 'name' ); ?>
						<table class="cc-size-table">
							<thead><tr><th></th><?php foreach ( $sizes as $s ) : ?><th><?php echo esc_html( $s ); ?></th><?php endforeach; ?></tr></thead>
							<tbody><?php foreach ( $table as $row ) : ?><tr><th><?php echo esc_html( $row[0] ); ?></th><?php foreach ( $row[1] as $val ) : ?><td><?php echo esc_html( $val ); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
						</table>
						<p class="cc-muted">All values in cm, measured on the garment laid flat, not on your body.</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( $desc ) : ?>
				<div class="cc-tab cc-tab--text" role="tabpanel" data-panel="details"><?php echo wp_kses_post( wpautop( $desc ) ); ?></div>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/** The block template renders [cc_product] outside the loop: set up the product first. */
add_action( 'wp', function () {
	if ( is_product() ) {
		$GLOBALS['product'] = wc_get_product( get_queried_object_id() );
	}
} );
