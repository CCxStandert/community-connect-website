<?php
/**
 * Projects: films, races, repaints and field trips the collective has made.
 *
 * - Post type cc_project at /projects/ (overview) and /projects/<name>/ (one project).
 * - Each project: title, short intro (excerpt), cover photo (featured image or a URL), the details
 *   in the "Project details" box (number, kind, years, partner, credits, film, link, look), and
 *   a free body built from blocks: chapters, photos, videos, quotes.
 * - [cc_projects] lists them (home section and overview); the project page template uses
 *   [cc_project_hero], [cc_project_film] and [cc_project_end].
 * - Films: a YouTube, Vimeo or Bunny Stream link, or an .mp4. Nothing loads from the video host
 *   until someone presses play (privacy, speed).
 *
 * @package community-connect
 */

defined( 'ABSPATH' ) || exit;

const CC_PROJECT = 'cc_project';

require_once __DIR__ . '/story.php';

/** Fields in the "Project details" box: key => [label, type, help]. */
function cc_project_fields() {
	return array(
		'num'        => array( 'Number', 'text', 'Shown as "Project 08". Leave empty for none.' ),
		'kind'       => array( 'Kind', 'select', array( 'Film' => 'Film', 'Race' => 'Race', 'Field project' => 'Field project', 'Repaint' => 'Repaint / collab', 'Launch' => 'Launch film' ) ),
		'years'      => array( 'Year(s)', 'text', 'e.g. 2025 or 2023–2026' ),
		'with'       => array( 'With', 'text', 'Partner or client, e.g. Standert, Tailfin, Koos' ),
		'credits'    => array( 'Credits', 'textarea', 'One per line, "Label: Name", e.g. "Photos: Julian Robinet". Shown in the strip under the title.' ),
		'film'       => array( 'Main film', 'url', 'YouTube, Vimeo or Bunny Stream link (or an .mp4). Plays on the page; nothing loads until someone presses play.' ),
		'film_title' => array( 'Film heading', 'text', 'Default: "The film"' ),
		'hero_video' => array( 'Cover video (.mp4)', 'url', 'Optional short silent loop that plays behind the title instead of the cover photo.' ),
		'hero_url'   => array( 'Cover photo URL', 'url', 'Only if no featured image is set.' ),
		'link'       => array( 'Link', 'url', 'e.g. the event page' ),
		'link_label' => array( 'Link text', 'text', 'e.g. "Enter Road to Desolation"' ),
		'look'       => array( 'Look', 'select', array( 'cc' => 'Community Connect (colour, serif title)', 'standert' => 'Standert (white, black, long read)', 'story' => 'Story (magazine: day openers from GPX, route map, phone tapes)' ) ),
		'color'      => array( 'Card colour', 'select', array( 'pink' => 'Hearts pink', 'ochre' => 'Karoo ochre', 'ink' => 'Ink', 'sand' => 'Sand', 'cream' => 'Cream' ) ),
	);
}

function cc_project_get( $id, $key ) {
	return (string) get_post_meta( $id, '_cc_p_' . $key, true );
}

add_action( 'init', function () {
	register_post_type( CC_PROJECT, array(
		'labels'       => array(
			'name' => 'Projects', 'singular_name' => 'Project', 'add_new_item' => 'Add project', 'edit_item' => 'Edit project',
			'all_items' => 'All projects', 'menu_name' => 'Projects', 'view_item' => 'View project',
		),
		'public'       => true,
		'has_archive'  => 'projects',
		'rewrite'      => array( 'slug' => 'projects', 'with_front' => false ),
		'show_in_rest' => true,
		'menu_icon'    => 'dashicons-format-video',
		'menu_position' => 21,
		'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields' ),
	) );
	foreach ( array_keys( cc_project_fields() ) as $k ) {
		register_post_meta( CC_PROJECT, '_cc_p_' . $k, array(
			'type' => 'string', 'single' => true, 'show_in_rest' => true,
			'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
		) );
	}
} );

/* Flush the URLs once after this arrives, so /projects/ works straight away. */
add_action( 'init', function () {
	if ( get_option( 'cc_projects_rewrite' ) !== '1' ) {
		flush_rewrite_rules( false );
		update_option( 'cc_projects_rewrite', '1' );
	}
}, 99 );

/* Order: the number field first (highest = newest), then menu order. */
add_action( 'pre_get_posts', function ( $q ) {
	if ( ! is_admin() && $q->is_main_query() && $q->is_post_type_archive( CC_PROJECT ) ) {
		$q->set( 'posts_per_page', 50 );
		$q->set( 'orderby', array( 'menu_order' => 'ASC', 'date' => 'DESC' ) );
	}
} );

/* ---------- Admin box ---------- */

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'cc-project', 'Project details', 'cc_project_box', CC_PROJECT, 'normal', 'high' );
} );

function cc_project_box( $post ) {
	wp_nonce_field( 'cc_project_save', 'cc_project_nonce' );
	echo '<table class="form-table" role="presentation">';
	foreach ( cc_project_fields() as $k => $f ) {
		$v    = cc_project_get( $post->ID, $k );
		$name = 'cc_p[' . $k . ']';
		echo '<tr><th><label for="cc_p_' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</label></th><td>';
		if ( 'select' === $f[1] ) {
			echo '<select id="cc_p_' . esc_attr( $k ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( $f[2] as $ok => $ol ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $ok ), selected( $v, $ok, false ), esc_html( $ol ) );
			}
			echo '</select>';
		} elseif ( 'textarea' === $f[1] ) {
			printf( '<textarea id="cc_p_%1$s" name="%2$s" rows="4" class="large-text">%3$s</textarea>', esc_attr( $k ), esc_attr( $name ), esc_textarea( $v ) );
		} else {
			printf( '<input id="cc_p_%1$s" type="%2$s" name="%3$s" value="%4$s" class="regular-text" style="width:100%%">', esc_attr( $k ), 'url' === $f[1] ? 'url' : 'text', esc_attr( $name ), esc_attr( $v ) );
		}
		if ( 'select' !== $f[1] && ! empty( $f[2] ) ) {
			echo '<p class="description">' . esc_html( $f[2] ) . '</p>';
		}
		echo '</td></tr>';
	}
	echo '</table><p class="description">The intro under the title is the <strong>Excerpt</strong> (sidebar). The cover is the <strong>Featured image</strong>. Build the story below with the "Project" patterns: chapter, photo row, quote.</p>';
}

add_action( 'save_post_' . CC_PROJECT, function ( $id ) {
	if ( ! isset( $_POST['cc_project_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['cc_project_nonce'] ), 'cc_project_save' ) || ! current_user_can( 'edit_post', $id ) ) {
		return;
	}
	$in = (array) wp_unslash( $_POST['cc_p'] ?? array() ); // phpcs:ignore -- sanitised per field below.
	foreach ( cc_project_fields() as $k => $f ) {
		if ( ! isset( $in[ $k ] ) ) {
			continue;
		}
		$v = 'url' === $f[1] ? esc_url_raw( trim( $in[ $k ] ) ) : ( 'textarea' === $f[1] ? sanitize_textarea_field( $in[ $k ] ) : sanitize_text_field( $in[ $k ] ) );
		update_post_meta( $id, '_cc_p_' . $k, $v );
	}
} );

/* ---------- Helpers ---------- */

function cc_project_cover( $id, $size = 'large' ) {
	$img = get_the_post_thumbnail_url( $id, $size );
	if ( ! $img ) {
		$img = cc_project_get( $id, 'hero_url' );
	}
	if ( ! $img ) {
		$v = cc_project_video( cc_project_get( $id, 'film' ) );
		$img = $v['thumb'] ?? '';
	}
	return $img;
}

/** "Project 08 · Film · 2025" */
function cc_project_eyebrow( $id, $with_num = true ) {
	$bits = array();
	$num  = cc_project_get( $id, 'num' );
	if ( $with_num && $num ) {
		$bits[] = 'Project ' . $num;
	}
	foreach ( array( 'kind', 'years' ) as $k ) {
		if ( cc_project_get( $id, $k ) ) {
			$bits[] = cc_project_get( $id, $k );
		}
	}
	return implode( ' · ', $bits );
}

/**
 * What kind of video a link is, and how to embed it.
 * Returns [type, embed, thumb] or [].
 */
function cc_project_video( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return array();
	}
	if ( preg_match( '~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([\w-]{11})~', $url, $m ) ) {
		return array( 'type' => 'iframe', 'embed' => 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1&rel=0&modestbranding=1', 'thumb' => 'https://i.ytimg.com/vi/' . $m[1] . '/maxresdefault.jpg', 'watch' => 'https://www.youtube.com/watch?v=' . $m[1] );
	}
	if ( preg_match( '~vimeo\.com/(?:video/)?(\d+)~', $url, $m ) ) {
		return array( 'type' => 'iframe', 'embed' => 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1&dnt=1', 'thumb' => '', 'watch' => 'https://vimeo.com/' . $m[1] );
	}
	// Bunny Stream: iframe.mediadelivery.net/embed/<library>/<video> or /play/<library>/<video>.
	if ( preg_match( '~(?:iframe|player)\.mediadelivery\.net/(?:embed|play)/(\d+)/([\w-]+)~', $url, $m ) ) {
		return array( 'type' => 'iframe', 'embed' => 'https://iframe.mediadelivery.net/embed/' . $m[1] . '/' . $m[2] . '?autoplay=true&preload=true', 'thumb' => '', 'watch' => '' );
	}
	if ( preg_match( '~\.(mp4|webm|mov)(\?|$)~i', $url ) ) {
		return array( 'type' => 'file', 'embed' => $url, 'thumb' => '', 'watch' => '' );
	}
	return array( 'type' => 'iframe', 'embed' => $url, 'thumb' => '', 'watch' => '' );
}

/** A film that loads only when played. */
function cc_project_player( $url, $poster = '', $label = 'Play the film' ) {
	$v = cc_project_video( $url );
	if ( ! $v ) {
		return '';
	}
	$poster = $poster ?: $v['thumb'];
	if ( 'file' === $v['type'] ) {
		return sprintf( '<div class="cc-player cc-player--file"><video controls playsinline preload="none"%s src="%s"></video></div>', $poster ? ' poster="' . esc_url( $poster ) . '"' : '', esc_url( $v['embed'] ) );
	}
	return sprintf(
		'<div class="cc-player" data-embed="%1$s">%2$s<button type="button" class="cc-player__play" aria-label="%3$s"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" fill="currentColor"/></svg><span>%3$s</span></button></div>',
		esc_url( $v['embed'] ),
		$poster ? '<img src="' . esc_url( $poster ) . '" alt="" loading="lazy" decoding="async">' : '',
		esc_html( $label )
	);
}

/* ---------- Lists: home section + /projects/ ---------- */

function cc_projects_query( $limit = -1, $exclude = 0 ) {
	return get_posts( array(
		'post_type' => CC_PROJECT, 'post_status' => 'publish', 'numberposts' => $limit,
		'orderby' => array( 'menu_order' => 'ASC', 'date' => 'DESC' ), 'exclude' => $exclude ? array( $exclude ) : array(),
	) );
}

function cc_project_card( $p, $big = false ) {
	$id  = $p->ID;
	$img = cc_project_cover( $id, $big ? 'large' : 'medium_large' );
	$fs  = function_exists( 'cc_focus_style' ) && has_post_thumbnail( $id ) ? cc_focus_style( get_post_thumbnail_id( $id ) ) : '';
	$has_film = '' !== cc_project_get( $id, 'film' );
	return sprintf(
		'<li class="cc-pcard" data-kind="%1$s"><a href="%2$s"><span class="cc-pcard__img">%3$s%4$s</span><span class="cc-pcard__eye">%5$s</span><b class="cc-pcard__title">%6$s</b><span class="cc-pcard__sub">%7$s</span></a></li>',
		esc_attr( sanitize_title( cc_project_get( $id, 'kind' ) ) ),
		esc_url( get_permalink( $id ) ),
		$img ? '<img src="' . esc_url( $img ) . '" alt="" loading="lazy" decoding="async"' . ( $fs ? ' style="' . esc_attr( $fs ) . '"' : '' ) . '>' : '',
		$has_film ? '<span class="cc-pcard__play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z" fill="currentColor"/></svg></span>' : '',
		esc_html( cc_project_eyebrow( $id, false ) . ( cc_project_get( $id, 'with' ) ? ' · ' . cc_project_get( $id, 'with' ) : '' ) ),
		esc_html( get_the_title( $id ) ),
		esc_html( wp_strip_all_tags( get_the_excerpt( $id ) ) )
	);
}

function cc_project_feature( $p ) {
	$id    = $p->ID;
	$color = cc_project_get( $id, 'color' ) ?: 'pink';
	$tags  = array_filter( array( cc_project_get( $id, 'kind' ), cc_project_get( $id, 'with' ) ) );
	return sprintf(
		'<a class="cc-pfeat max" href="%1$s"><span class="cc-pfeat__txt cc-bg--%2$s"><span class="cc-pfeat__tags">%3$s</span><b class="cc-pfeat__title">%4$s</b><span class="cc-pfeat__sub">%5$s</span><span class="cc-pfeat__more">See the project →</span></span><span class="cc-pfeat__img">%6$s</span></a>',
		esc_url( get_permalink( $id ) ),
		esc_attr( $color ),
		implode( '', array_map( function ( $t ) { return '<span class="st">' . esc_html( $t ) . '</span>'; }, $tags ) ),
		esc_html( get_the_title( $id ) ),
		esc_html( wp_strip_all_tags( get_the_excerpt( $id ) ) ),
		cc_project_cover( $id ) ? '<img src="' . esc_url( cc_project_cover( $id ) ) . '" alt="" loading="lazy" decoding="async">' : ''
	);
}

/**
 * [cc_projects] — home: newest as a big card + the next three, and a link to all.
 * [cc_projects all="1"] — the overview page: filter + every project.
 */
add_shortcode( 'cc_projects', function ( $atts ) {
	$a     = shortcode_atts( array( 'all' => '0' ), $atts );
	$all   = '1' === $a['all'];
	$items = cc_projects_query();
	if ( ! $items ) {
		return '';
	}
	$out = '';
	if ( $all ) {
		$kinds = array();
		foreach ( $items as $p ) {
			$k = cc_project_get( $p->ID, 'kind' );
			if ( $k ) {
				$kinds[ sanitize_title( $k ) ] = cc_project_fields()['kind'][2][ $k ] ?? $k;
			}
		}
		$out .= '<section class="cc-projs cc-projs--all"><div class="cc-projs__intro max"><p class="cc-crumb">The collective&nbsp;&nbsp;/&nbsp;&nbsp;<span>Projects</span></p><h1 class="cc-projs__h1">Projects</h1><p class="cc-projs__lede">Films, races, repaints and long rides. What happens when you share good bikes with people who ride them hard.</p></div>';
		if ( count( $kinds ) > 1 ) {
			$out .= '<div class="chips cc-projs__chips max" role="group" aria-label="Filter projects"><button type="button" class="chip on" data-k="" aria-pressed="true">All</button>';
			foreach ( $kinds as $k => $l ) {
				$out .= '<button type="button" class="chip" data-k="' . esc_attr( $k ) . '" aria-pressed="false">' . esc_html( $l ) . '</button>';
			}
			$out .= '</div>';
		}
		$out .= '<ul class="cc-projs__grid cc-projs__grid--all max">' . implode( '', array_map( 'cc_project_card', $items ) ) . '</ul></section>';
		return $out;
	}
	$first = array_shift( $items );
	$out  .= '<section class="cc-projs sect" id="projects"><div class="in max cc-projs__head"><div><h2 class="h">Projects</h2><p class="sub">Films, races, repaints and long rides from the collective.</p></div><a class="cc-projs__all" href="' . esc_url( get_post_type_archive_link( CC_PROJECT ) ) . '">All projects →</a></div>';
	$out  .= cc_project_feature( $first );
	$out  .= '<ul class="cc-projs__grid max">' . implode( '', array_map( 'cc_project_card', array_slice( $items, 0, 4 ) ) ) . '</ul>';
	$out  .= '<a class="cc-projs__more max" href="' . esc_url( get_post_type_archive_link( CC_PROJECT ) ) . '">All ' . ( count( $items ) + 1 ) . ' projects</a></section>';
	return $out;
} );

/* ---------- One project ---------- */

add_shortcode( 'cc_project_hero', function () {
	$id = get_the_ID();
	if ( ! $id || CC_PROJECT !== get_post_type( $id ) ) {
		return '';
	}
	$look  = cc_project_get( $id, 'look' ) ?: 'cc';
	$cover = cc_project_cover( $id, 'full' );
	$fs    = function_exists( 'cc_focus_style' ) && has_post_thumbnail( $id ) ? cc_focus_style( get_post_thumbnail_id( $id ) ) : '';
	$vid   = cc_project_get( $id, 'hero_video' );
	$media = $vid
		? '<video class="cc-phero__media" autoplay muted loop playsinline preload="metadata"' . ( $cover ? ' poster="' . esc_url( $cover ) . '"' : '' ) . ' src="' . esc_url( $vid ) . '"></video>'
		: ( $cover ? '<img class="cc-phero__media" src="' . esc_url( $cover ) . '" alt="" fetchpriority="high"' . ( $fs ? ' style="' . esc_attr( $fs ) . '"' : '' ) . '>' : '' );
	$intro = has_excerpt( $id ) ? '<p class="cc-phero__intro">' . esc_html( get_the_excerpt( $id ) ) . '</p>' : '';
	$out   = '<header class="cc-phero cc-phero--' . esc_attr( $look ) . '">';
	if ( 'standert' === $look || 'story' === $look ) {
		$out .= '<div class="cc-phero__top max"><p class="cc-phero__eye">' . esc_html( cc_project_eyebrow( $id ) ) . '</p><h1 class="cc-phero__title">' . esc_html( get_the_title( $id ) ) . '</h1>' . $intro . '</div>' . cc_project_credits( $id ) . '<div class="cc-phero__pic">' . $media . '</div>';
	} else {
		$out .= '<div class="cc-phero__pic">' . $media . '</div><div class="cc-phero__over max"><p class="cc-phero__eye">' . esc_html( cc_project_eyebrow( $id ) ) . '</p><h1 class="cc-phero__title">' . esc_html( get_the_title( $id ) ) . '</h1>' . $intro . '</div>';
		$out .= '</header>' . cc_project_credits( $id );
		return $out;
	}
	return $out . '</header>';
} );

function cc_project_credits( $id ) {
	$rows = array();
	if ( cc_project_get( $id, 'with' ) ) {
		$rows[] = array( 'With', cc_project_get( $id, 'with' ) );
	}
	foreach ( preg_split( '/\r?\n/', cc_project_get( $id, 'credits' ) ) as $line ) {
		if ( strpos( $line, ':' ) ) {
			list( $l, $v ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$rows[] = array( $l, $v );
		}
	}
	if ( ! $rows ) {
		return '';
	}
	return '<dl class="cc-pcred cc-pcred--' . esc_attr( cc_project_get( $id, 'look' ) ?: 'cc' ) . '"><div class="cc-pcred__in max">' . implode( '', array_map( function ( $r ) {
		return '<div><dt>' . esc_html( $r[0] ) . '</dt><dd>' . esc_html( $r[1] ) . '</dd></div>';
	}, $rows ) ) . '</div></dl>';
}

add_shortcode( 'cc_project_film', function () {
	$id   = get_the_ID();
	$film = $id ? cc_project_get( $id, 'film' ) : '';
	if ( ! $film ) {
		return '';
	}
	$v     = cc_project_video( $film );
	$title = cc_project_get( $id, 'film_title' ) ?: 'The film';
	return '<section class="cc-pfilm cc-pfilm--' . esc_attr( cc_project_get( $id, 'look' ) ?: 'cc' ) . '" id="film"><div class="max cc-pfilm__in"><p class="cc-pfilm__eye">' . esc_html( $title ) . '</p>'
		. cc_project_player( $film, $v['thumb'] ? '' : cc_project_cover( $id, 'full' ), 'Play ' . strtolower( $title ) )
		. ( ! empty( $v['watch'] ) ? '<a class="cc-pfilm__ext" href="' . esc_url( $v['watch'] ) . '" target="_blank" rel="noopener">Watch on ' . ( false !== strpos( $v['watch'], 'vimeo' ) ? 'Vimeo' : 'YouTube' ) . ' ↗</a>' : '' )
		. '</div></section>';
} );

add_shortcode( 'cc_project_end', function () {
	$id = get_the_ID();
	if ( ! $id || CC_PROJECT !== get_post_type( $id ) ) {
		return '';
	}
	$out  = '';
	$link = cc_project_get( $id, 'link' );
	if ( $link ) {
		$ext  = ! ( 0 === strpos( $link, home_url() ) || 0 === strpos( $link, '/' ) );
		$out .= '<section class="cc-pend"><div class="max cc-pend__in"><p class="cc-pend__eye">' . esc_html( get_the_title( $id ) ) . '</p><a class="cc-pend__btn" href="' . esc_url( $link ) . '"' . ( $ext ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( cc_project_get( $id, 'link_label' ) ?: 'Go to the project page' ) . ( $ext ? ' ↗' : ' →' ) . '</a></div></section>';
	}
	// Previous / next in the list order.
	$all = wp_list_pluck( cc_projects_query(), 'ID' );
	$i   = array_search( $id, $all, true );
	if ( false !== $i && count( $all ) > 1 ) {
		$prev = $all[ ( $i - 1 + count( $all ) ) % count( $all ) ];
		$next = $all[ ( $i + 1 ) % count( $all ) ];
		$out .= sprintf(
			'<nav class="cc-pnav" aria-label="More projects"><div class="max cc-pnav__in"><a href="%1$s"><small>← Previous</small><b>%2$s</b></a><a href="%3$s"><small>Next →</small><b>%4$s</b></a></div><p class="max cc-pnav__all"><a href="%5$s">All projects</a></p></nav>',
			esc_url( get_permalink( $prev ) ), esc_html( get_the_title( $prev ) ), esc_url( get_permalink( $next ) ), esc_html( get_the_title( $next ) ), esc_url( get_post_type_archive_link( CC_PROJECT ) )
		);
	}
	return $out;
} );

/* Body class for the look, so the whole page follows it. */
add_filter( 'body_class', function ( $c ) {
	if ( is_singular( CC_PROJECT ) ) {
		$c[] = 'cc-proj';
		$c[] = 'cc-proj--' . ( cc_project_get( get_queried_object_id(), 'look' ) ?: 'cc' );
	}
	if ( is_post_type_archive( CC_PROJECT ) ) {
		$c[] = 'cc-projects-page';
	}
	return $c;
} );

/* Player + filter: tiny script, only where projects show. */
add_action( 'wp_footer', function () {
	?>
<script>
(function(){
document.addEventListener('click',function(e){
  var b=e.target.closest('.cc-player__play'); if(b){var p=b.closest('.cc-player'),f=document.createElement('iframe');
    f.src=p.dataset.embed;f.allow='autoplay; fullscreen; picture-in-picture; encrypted-media';f.allowFullscreen=true;f.title='Film';p.innerHTML='';p.appendChild(f);p.classList.add('is-on');return;}
  var c=e.target.closest('.cc-projs__chips .chip'); if(c){var bar=c.parentNode,k=c.dataset.k;
    bar.querySelectorAll('.chip').forEach(function(x){var on=x===c;x.classList.toggle('on',on);x.setAttribute('aria-pressed',on?'true':'false');});
    document.querySelectorAll('.cc-projs__grid--all .cc-pcard').forEach(function(li){li.hidden=!!k&&li.dataset.kind!==k;});}
});
})();
</script>
	<?php
}, 30 );

/* ---------- Block patterns for the project body ---------- */

add_action( 'init', function () {
	register_block_pattern_category( 'cc-project', array( 'label' => 'Project' ) );
	$img = get_theme_file_uri( 'assets/images/strip.webp' );
	foreach ( array( 'ink' => 'dark', 'ochre' => 'ochre', 'pink' => 'pink', 'sand' => 'sand', 'paper' => 'light' ) as $c => $label ) {
		register_block_pattern( 'community-connect/project-chapter-' . $c, array(
			'title'      => 'Project chapter (' . $label . ')',
			'categories' => array( 'cc-project' ),
			'postTypes'  => array( CC_PROJECT ),
			'content'    => '<!-- wp:group {"className":"cc-chap cc-chap--' . $c . '","align":"full","layout":{"type":"default"}} --><div class="wp-block-group alignfull cc-chap cc-chap--' . $c . '"><!-- wp:group {"className":"cc-chap__txt","layout":{"type":"default"}} --><div class="wp-block-group cc-chap__txt"><!-- wp:paragraph {"className":"cc-chap__n"} --><p class="cc-chap__n">01 — Chapter</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">A short headline.</h2><!-- /wp:heading --><!-- wp:paragraph --><p>A few sentences about this part of the project.</p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:group {"className":"cc-chap__media","layout":{"type":"default"}} --><div class="wp-block-group cc-chap__media"><!-- wp:image {"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="' . esc_url( $img ) . '" alt=""/></figure><!-- /wp:image --></div><!-- /wp:group --></div><!-- /wp:group -->',
		) );
	}
	register_block_pattern( 'community-connect/project-photos', array(
		'title' => 'Project photo row', 'categories' => array( 'cc-project' ), 'postTypes' => array( CC_PROJECT ),
		'content' => '<!-- wp:gallery {"columns":3,"linkTo":"none","className":"cc-pgal","align":"wide"} --><figure class="wp-block-gallery alignwide has-nested-images columns-3 cc-pgal"></figure><!-- /wp:gallery -->',
	) );
	register_block_pattern( 'community-connect/project-quote', array(
		'title' => 'Project quote', 'categories' => array( 'cc-project' ), 'postTypes' => array( CC_PROJECT ),
		'content' => '<!-- wp:quote {"className":"cc-pquote"} --><blockquote class="wp-block-quote cc-pquote"><!-- wp:paragraph --><p>A line someone said on the ride.</p><!-- /wp:paragraph --><cite>Name</cite></blockquote><!-- /wp:quote -->',
	) );
} );

/* ---------- While no project is public: no links, no overview page ---------- */

function cc_projects_public() {
	static $n = null;
	if ( null === $n ) {
		$n = (int) ( wp_count_posts( CC_PROJECT )->publish ?? 0 );
	}
	return $n > 0;
}

// Menu and footer links to /projects/ disappear until a project is published.
add_filter( 'render_block', function ( $html, $block ) {
	if ( cc_projects_public() || false === strpos( $html, '/projects/' ) ) {
		return $html;
	}
	if ( 'core/navigation-link' === $block['blockName'] && '/projects/' === ( $block['attrs']['url'] ?? '' ) ) {
		return '';
	}
	if ( 'core/paragraph' === $block['blockName'] && preg_match( '~^\s*<p><a href="/projects/">[^<]*</a></p>\s*$~', $html ) ) {
		return '';
	}
	return $html;
}, 10, 2 );

// The overview page is a 404 for visitors until then (logged-in editors still see it).
add_action( 'template_redirect', function () {
	if ( is_post_type_archive( CC_PROJECT ) && ! cc_projects_public() && ! current_user_can( 'edit_posts' ) ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
} );
