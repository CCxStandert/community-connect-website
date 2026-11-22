<?php
/**
 * Community Connect shop (WooCommerce), built into the theme.
 *
 * Loaded from functions.php only when WooCommerce is active, so the site never breaks without it.
 * WooCommerce keeps doing the work (products, stock, orders, emails, Yoco payments); the theme draws
 * every shop page itself, in the Standert-style layout of the rest of the site:
 *
 *   archive.php       /shop/ and categories: intro, filter pills, hairline grid
 *   single.php        product page: gallery, size pills / swatches, size guide drawer, add to cart
 *   sponsorship.php   "Sponsor a Rider" products: R150 / R500 / R1,000 or any amount from R50
 *   checkout.php      shipping rules, a short donor-only checkout, classic checkout fields
 *   cart-drawer.php   header cart button + slide-over cart (AJAX)
 *   admin.php         extra product fields (subtitle, fit, size table, amounts) and swatch colours
 *   setup.php         one-click store setup: settings, shipping, pages, categories, starter products
 *
 * Shop pages use the theme's block templates (templates/archive-product.html, single-product.html,
 * page-cart.html, page-checkout.html), which hold the [cc_shop] / [cc_product] parts below.
 */
defined( 'ABSPATH' ) || exit;

define( 'CC_SHOP_DIR', get_theme_file_path( 'inc/shop/' ) );
define( 'CC_SPONSOR_CAT', 'sponsor-a-rider' );
define( 'CC_APPAREL_CAT', 'apparel-gear' );

require_once CC_SHOP_DIR . 'helpers.php';
require_once CC_SHOP_DIR . 'archive.php';
require_once CC_SHOP_DIR . 'single.php';
require_once CC_SHOP_DIR . 'sponsorship.php';
require_once CC_SHOP_DIR . 'checkout.php';
require_once CC_SHOP_DIR . 'cart-drawer.php';
require_once CC_SHOP_DIR . 'admin.php';
require_once CC_SHOP_DIR . 'setup.php';

add_action( 'after_setup_theme', function () {
	add_theme_support( 'woocommerce', array(
		'thumbnail_image_width' => 800,
		'single_image_width'    => 1200,
	) );
	// The theme draws its own gallery: no zoom, lightbox or slider scripts.
	remove_theme_support( 'wc-product-gallery-zoom' );
	remove_theme_support( 'wc-product-gallery-lightbox' );
	remove_theme_support( 'wc-product-gallery-slider' );
}, 20 );

// Our styles stay in control: none of WooCommerce's front-end stylesheets.
add_filter( 'woocommerce_enqueue_styles', '__return_empty_array' );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'cc-shop', get_theme_file_uri( 'assets/css/shop.css' ), array( 'community-connect' ), CC_THEME_VERSION );
	wp_enqueue_script( 'cc-shop', get_theme_file_uri( 'assets/js/shop.js' ), array(), CC_THEME_VERSION, true );
	wp_add_inline_script( 'cc-shop', 'window.CCShop=' . wp_json_encode( array(
		'ajax'     => WC_AJAX::get_endpoint( '%%endpoint%%' ),
		'checkout' => wc_get_checkout_url(),
		'minAmount' => cc_shop_min_amount(),
		'currency' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES ),
	) ) . ';', 'before' );
	// WooCommerce's block styles only where blocks are used (never on our shop pages).
	if ( is_woocommerce() || is_cart() || is_checkout() ) {
		wp_dequeue_style( 'wc-blocks-style' );
	}
}, 20 );

add_filter( 'body_class', function ( $classes ) {
	if ( is_woocommerce() || is_cart() || is_checkout() ) {
		$classes[] = 'cc-shop-page';
	}
	return $classes;
} );

/**
 * WooCommerce adds its own account and mini-cart icons next to the menu in block themes.
 * Our header has its own cart button, so those stay out.
 */
add_filter( 'hooked_block_types', function ( $hooked ) {
	return array_values( array_diff( $hooked, array( 'woocommerce/customer-account', 'woocommerce/mini-cart' ) ) );
}, 20 );

// No breadcrumbs, sidebars or "result count" chrome from WooCommerce: our templates draw what's needed.
remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

/**
 * Header: the "Shop" link and cart button only show once the shop is live
 * (WooCommerce → Settings → Site visibility). Crew who can manage the shop always see them.
 */
add_filter( 'render_block_core/navigation-link', function ( $html, $block ) {
	$url = (string) ( $block['attrs']['url'] ?? '' );
	if ( preg_match( '#^/shop/?$#', $url ) && ! cc_shop_visible() ) {
		return '';
	}
	return $html;
}, 10, 2 );
