<?php
/**
 * Legal pages: Imprint (/imprint/), Privacy policy (/privacy-policy/) and, with the shop,
 * Shop terms & returns (/terms/).
 *
 * They're normal WordPress pages using the "Legal page" template (narrow, numbered sections),
 * created once as drafts from inc/legal/*.html for the crew to check and publish.
 * The footer links ([cc_legal_links]) only show pages that are published.
 */
defined( 'ABSPATH' ) || exit;

/** Create the drafts once (and again if someone deleted them). Never overwrites a published page. */
add_action( 'admin_init', function () {
	if ( get_option( 'cc_legal_pages_v1' ) || ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	cc_legal_create_pages();
	update_option( 'cc_legal_pages_v1', 1, false );
} );

function cc_legal_create_pages() {
	$made = array();
	$urls = array(
		'{{privacy}}' => home_url( '/privacy-policy/' ),
		'{{terms}}'   => home_url( '/terms/' ),
	);
	foreach ( array( 'imprint' => 'Imprint', 'privacy-policy' => 'Privacy policy' ) as $slug => $title ) {
		$file    = get_theme_file_path( 'inc/legal/' . ( 'privacy-policy' === $slug ? 'privacy' : $slug ) . '.html' );
		$content = strtr( (string) file_get_contents( $file ), $urls ); // phpcs:ignore
		$page    = get_page_by_path( $slug );
		if ( $page && 'publish' === $page->post_status ) {
			continue; // Someone already published their own: leave it alone.
		}
		// WordPress makes a draft "Privacy Policy" with its own sample text on install: ours replaces it.
		$is_wp_sample = $page && false !== strpos( (string) $page->post_content, 'Suggested text' );
		if ( $page && ! $is_wp_sample && 'draft' === $page->post_status && '' !== trim( $page->post_content ) ) {
			continue; // A draft someone is already writing.
		}
		$args = array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content, 'page_template' => 'page-legal' );
		if ( $page ) {
			$args['ID'] = $page->ID;
		}
		$id = wp_insert_post( $args );
		if ( $id && ! is_wp_error( $id ) ) {
			$made[] = $title;
			if ( 'privacy-policy' === $slug ) {
				update_option( 'wp_page_for_privacy_policy', $id );
			}
		}
	}
	// The shop terms page (made by the store setup) uses the same template.
	$terms = get_page_by_path( 'terms' );
	if ( $terms && ! get_page_template_slug( $terms ) ) {
		update_post_meta( $terms->ID, '_wp_page_template', 'page-legal' );
	}
	return $made;
}

/** Footer: links to the published legal pages, plus "Cookie preferences" when analytics is set up. */
add_shortcode( 'cc_legal_links', function () {
	$links = array();
	foreach ( array( 'imprint' => 'Imprint', 'privacy-policy' => 'Privacy', 'terms' => 'Shop terms' ) as $slug => $label ) {
		$page = get_page_by_path( $slug );
		if ( $page && 'publish' === $page->post_status ) {
			$links[] = '<a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html( $label ) . '</a>';
		}
	}
	if ( function_exists( 'cc_ga_id' ) && cc_ga_id() ) {
		$links[] = '<a href="#cookie-preferences" data-cc-consent-open>Cookie preferences</a>';
	}
	return $links ? '<p class="fine cc-legal-links">' . implode( ' · ', $links ) . '</p>' : '';
} );
