<?php
/**
 * Community Connect theme.
 *
 * Design: "Community Connect — Mobile 2027" (Standert's skeleton, our own skin).
 * Everything on the pages is made of normal blocks you can edit in Appearance → Editor.
 * The live parts (fleet finder and grid, the loop animation, events, numbers, the "who pays?" game
 * and the request form) come from the CC Fleet plugin as shortcodes.
 */
defined( 'ABSPATH' ) || exit;

define( 'CC_THEME_VERSION', wp_get_theme( 'community-connect' )->get( 'Version' ) ?: '1.0.0' );

// Legal pages (imprint, privacy policy), the cookie notice and Google Analytics 4 (see inc/legal/).
require_once get_theme_file_path( 'inc/legal/legal.php' );
require_once get_theme_file_path( 'inc/legal/consent.php' );
require_once get_theme_file_path( 'inc/seo/seo.php' );
// Auto crop: a focus point per photo, used wherever photos are cropped to fit (see inc/media/focus.php).
require_once get_theme_file_path( 'inc/media/focus.php' );
// Projects: films, races, repaints, field trips (see inc/projects/projects.php).
require_once get_theme_file_path( 'inc/projects/projects.php' );

// The shop: only when WooCommerce is active, so the site never depends on it (see inc/shop/shop.php).
if ( class_exists( 'WooCommerce' ) ) {
	require_once get_theme_file_path( 'inc/shop/shop.php' );
	require_once get_theme_file_path( 'inc/shop/figures.php' );
} else {
	// No WooCommerce, no shop: keep the header's "Shop" link out of sight.
	add_filter( 'render_block_core/navigation-link', function ( $html, $block ) {
		return preg_match( '#^/shop/?$#', (string) ( $block['attrs']['url'] ?? '' ) ) ? '' : $html;
	}, 10, 2 );
}

add_action( 'after_setup_theme', function () {
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/css/theme.css' );
	add_theme_support( 'post-thumbnails' );
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'community-connect', get_theme_file_uri( 'assets/css/theme.css' ), array(), CC_THEME_VERSION );
	wp_enqueue_script( 'community-connect', get_theme_file_uri( 'assets/js/theme.js' ), array(), CC_THEME_VERSION, true );
	wp_enqueue_style( 'cc-projects', get_theme_file_uri( 'assets/css/projects.css' ), array( 'community-connect' ), CC_THEME_VERSION );
	// The plugin's styles for its live parts (fleet grid, events, game…) are reused everywhere.
	if ( defined( 'CC_FLEET_URL' ) ) {
		wp_enqueue_style( 'cc-landing', CC_FLEET_URL . 'assets/landing.css', array(), defined( 'CC_FLEET_VERSION' ) ? CC_FLEET_VERSION : null );
	}
} );

// The plugin's component styles are written for body.cc-landing.
add_filter( 'body_class', function ( $classes ) {
	$classes[] = 'cc-landing';
	return $classes;
} );

add_action( 'init', function () {
	register_block_pattern_category( 'community-connect', array( 'label' => 'Community Connect' ) );
	// Styles you can pick in the editor's "Styles" panel.
	register_block_style( 'core/paragraph', array( 'name' => 'mono-label', 'label' => 'Mono label' ) );
	register_block_style( 'core/paragraph', array( 'name' => 'hand-note', 'label' => 'Hand note' ) );
	register_block_style( 'core/paragraph', array( 'name' => 'story', 'label' => 'Story (serif)' ) );
	register_block_style( 'core/paragraph', array( 'name' => 'pill-tag', 'label' => 'Pill tag' ) );
	register_block_style( 'core/group', array( 'name' => 'tiles', 'label' => 'Hairline tiles' ) );
	register_block_style( 'core/button', array( 'name' => 'pink', 'label' => 'Hearts Pink' ) );
	register_block_style( 'core/button', array( 'name' => 'line', 'label' => 'Outline' ) );
} );

/**
 * Shortcode blocks inside patterns are rendered after WordPress has already run the template's shortcodes,
 * so run them here. Without the plugin, show a short note instead of raw [cc_…] text.
 */
add_filter( 'render_block_core/shortcode', function ( $html ) {
	if ( ! defined( 'CC_FLEET_VERSION' ) && preg_match( '/\[cc_[a-z_]+\]/', $html ) ) {
		return current_user_can( 'activate_plugins' ) ? '<p class="cc-missing">Activate the CC Fleet plugin to show this part.</p>' : '';
	}
	return do_shortcode( shortcode_unautop( $html ) );
} );

/**
 * On activation: use the CC icon as the site logo and browser icon if none is set yet,
 * so the header shows the round icon next to the name. Both can be changed in the editor.
 */
add_action( 'after_switch_theme', function () {
	if ( get_theme_mod( 'custom_logo' ) && get_option( 'site_icon' ) ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$tmp = wp_tempnam( 'cc-icon.png' );
	if ( ! $tmp || ! copy( get_theme_file_path( 'assets/images/cc-icon.png' ), $tmp ) ) {
		return;
	}
	$id = media_handle_sideload( array( 'name' => 'cc-icon.png', 'tmp_name' => $tmp ), 0, 'Community Connect icon' );
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp ); // phpcs:ignore
		return;
	}
	if ( ! get_theme_mod( 'custom_logo' ) ) {
		set_theme_mod( 'custom_logo', $id );
		update_option( 'site_logo', $id );
	}
	if ( ! get_option( 'site_icon' ) ) {
		update_option( 'site_icon', $id );
	}
} );

/**
 * Speed: start downloading the first hero photo straight away on the home page.
 */
add_action( 'wp_head', function () {
	if ( is_front_page() ) {
		printf( '<link rel="preload" as="image" href="%s" type="image/webp" fetchpriority="high">' . "\n", esc_url( get_theme_file_uri( 'assets/images/hero-ghc-2026.webp' ) ) );
	}
}, 1 );
