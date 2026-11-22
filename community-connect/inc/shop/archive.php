<?php
/**
 * Shop page and categories: [cc_shop].
 * Standert's collection layout: a big title and a few warm lines, filter pills (All / Apparel & Gear /
 * Sponsor a Rider) with the product count, then a hairline grid of products.
 * Filters are plain links (/shop/?cat=sponsor-a-rider), so every view can be shared.
 */
defined( 'ABSPATH' ) || exit;

add_shortcode( 'cc_shop', 'cc_shop_render' );

/** The filter pills: All plus the two categories we sell in. */
function cc_shop_filters() {
	return array(
		''             => 'All',
		CC_APPAREL_CAT => 'Apparel & Gear',
		CC_SPONSOR_CAT => 'Sponsor a Rider',
	);
}

function cc_shop_current_cat() {
	if ( is_product_category() ) {
		return get_queried_object()->slug ?? '';
	}
	$cat = sanitize_title( wp_unslash( $_GET['cat'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	return array_key_exists( $cat, cc_shop_filters() ) ? $cat : '';
}

function cc_shop_render() {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return '';
	}
	$cat   = cc_shop_current_cat();
	$args  = array( 'status' => 'publish', 'limit' => 100, 'orderby' => 'menu_order', 'order' => 'ASC', 'visibility' => 'catalog' );
	if ( $cat ) {
		$args['category'] = array( $cat );
	}
	$products = wc_get_products( $args );
	$titles   = array( '' => 'Shop', CC_APPAREL_CAT => 'Apparel & Gear', CC_SPONSOR_CAT => 'Sponsor a Rider' );
	$intros   = array(
		''             => 'Merch from our races and rides, and ways to keep the bikes rolling. Everything you spend here goes back into Community Connect: the fleet, the overnights and the riders who need a hand to join.',
		CC_APPAREL_CAT => 'Tees, long sleeves and hoodies from Road to Desolation and our friends at Standert. Small runs, so when a size is gone, it’s gone.',
		CC_SPONSOR_CAT => 'Put your money where the miles are. Pick a fund and an amount: R150, R500, R1,000 or whatever feels right.',
	);
	ob_start();
	?>
	<section class="cc-shop">
		<header class="cc-shop__head">
			<h1 class="cc-shop__title"><?php echo esc_html( $titles[ $cat ] ?? single_term_title( '', false ) ); ?></h1>
			<p class="cc-shop__intro"><?php echo esc_html( $intros[ $cat ] ?? wp_strip_all_tags( term_description() ) ); ?></p>
		</header>
		<div class="cc-shop__bar">
			<nav class="cc-shop__pills" aria-label="Filter the shop">
				<?php foreach ( cc_shop_filters() as $slug => $label ) : ?>
					<a class="cc-pill<?php echo $slug === $cat ? ' is-on' : ''; ?>" href="<?php echo esc_url( cc_shop_url( $slug ) ); ?>"<?php echo $slug === $cat ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<p class="cc-shop__count"><?php echo esc_html( sprintf( _n( '%d product', '%d products', count( $products ) ), count( $products ) ) ); ?></p>
		</div>
		<?php if ( $products ) : ?>
			<ul class="cc-grid">
				<?php foreach ( $products as $p ) : ?>
					<li><?php echo cc_shop_card( $p ); // phpcs:ignore -- escaped inside. ?></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="cc-shop__empty">Nothing here yet. Check back soon, or <a href="<?php echo esc_url( cc_shop_url() ); ?>">see everything</a>.</p>
		<?php endif; ?>
	</section>
	<?php
	return ob_get_clean();
}

/** One product tile: name and subtitle lines on top, the photo, then price and sizes. */
function cc_shop_card( WC_Product $p ) {
	$url     = get_permalink( $p->get_id() );
	$img     = $p->get_image_id();
	$sponsor = cc_is_sponsorship( $p );
	$sizes   = cc_size_states( $p );
	$lines   = cc_product_subtitle_lines( $p );
	ob_start();
	?>
	<a class="cc-card<?php echo $sponsor ? ' cc-card--fund' : ''; ?><?php echo $p->is_in_stock() ? '' : ' is-out'; ?>" href="<?php echo esc_url( $url ); ?>">
		<span class="cc-card__name"><b><?php echo esc_html( $p->get_name() ); ?></b><?php foreach ( $lines as $l ) : ?><span><?php echo esc_html( $l ); ?></span><?php endforeach; ?></span>
		<span class="cc-card__pic">
			<?php if ( cc_is_preorder( $p ) ) : ?><span class="cc-badge">Pre-order</span><?php endif; ?>
			<?php if ( $img ) : ?>
				<?php echo wp_get_attachment_image( $img, 'woocommerce_single', false, array( 'loading' => 'lazy', 'alt' => $p->get_name(), 'sizes' => '(min-width: 980px) 33vw, (min-width: 600px) 50vw, 100vw' ) ); ?>
			<?php else : ?>
				<span class="cc-card__ph" aria-hidden="true"><?php echo $sponsor ? 'R' : 'photo to come'; ?></span>
			<?php endif; ?>
		</span>
		<span class="cc-card__foot">
			<span class="cc-card__price">
				<?php if ( $sponsor ) : ?>
					<?php echo esc_html( 'From ' . cc_money( cc_shop_min_amount() ) ); ?><small>any amount you like</small>
				<?php else : ?>
					<?php echo wp_kses_post( $p->get_price_html() ); ?><small><?php echo $p->is_in_stock() ? 'excl. shipping' : 'sold out'; ?></small>
				<?php endif; ?>
			</span>
			<span class="cc-card__row">
				<?php if ( $sizes ) : ?>
					<span class="cc-card__sizes" aria-label="Sizes">
						<?php foreach ( $sizes as $s ) : ?>
							<span class="<?php echo $s['in_stock'] ? '' : 'is-gone'; ?>"<?php echo $s['in_stock'] ? '' : ' title="Sold out"'; ?>><?php echo esc_html( $s['name'] ); ?></span>
						<?php endforeach; ?>
					</span>
				<?php elseif ( $sponsor ) : ?>
					<span class="cc-card__sizes"><span>Sponsor</span></span>
				<?php else : ?>
					<span class="cc-card__sizes"></span>
				<?php endif; ?>
				<span class="cc-card__plus" aria-hidden="true">+</span>
			</span>
		</span>
	</a>
	<?php
	return ob_get_clean();
}
