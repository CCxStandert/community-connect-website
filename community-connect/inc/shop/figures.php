<?php
/**
 * Home drawings that link to the shop (".cc-fig" groups): the product's featured image is added
 * inside the drawing and shown on hover. Change the link in the caption and the photo follows.
 *
 * @package community-connect
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'render_block_core/group', function ( $html, $block ) {
	$cls = $block['attrs']['className'] ?? '';
	if ( ! preg_match( '/(^|\s)cc-fig(\s|$)/', $cls ) || false !== strpos( $html, 'cc-fig__hover' ) ) {
		return $html;
	}
	if ( ! preg_match( '/<a\s[^>]*href="([^"]+)"/', $html, $m ) ) {
		return $html;
	}
	$url = html_entity_decode( $m[1] );
	$id  = url_to_postid( 0 === strpos( $url, '/' ) ? home_url( $url ) : $url );
	if ( ! $id || 'product' !== get_post_type( $id ) || ! has_post_thumbnail( $id ) ) {
		return $html;
	}
	$img = get_the_post_thumbnail( $id, 'woocommerce_single', array(
		'class'    => 'cc-fig__hover',
		'alt'      => '',
		'loading'  => 'lazy',
		'decoding' => 'async',
		'sizes'    => '(min-width: 760px) 50vw, 100vw',
	) );
	// Into the drawing's box, after the drawing itself.
	return preg_replace( '/(<div class="wp-block-group art[^"]*"[^>]*>)/', '$1' . $img, $html, 1 );
}, 10, 2 );
