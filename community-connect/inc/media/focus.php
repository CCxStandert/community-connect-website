<?php
/**
 * Auto crop: every photo gets a focus point (where the subject is), so when a photo is
 * cropped to fit a box (square shop tile, 4:3 bike card, phone vs desktop), the crop keeps
 * the subject in view instead of always cutting around the middle.
 *
 * - Found automatically on upload (and for older photos the first time they're shown,
 *   plus a background job that works through the library).
 * - Can be set by hand: Media → the photo → "Focus point": click on the subject.
 * - Output: each image carries --fx / --fy (percent); every cover-cropped image uses them
 *   as object-position, whatever the screen size.
 *
 * @package community-connect
 */

defined( 'ABSPATH' ) || exit;

const CC_FOCUS_META = '_cc_focus'; // "x,y" in percent, e.g. "38,42"
const CC_FOCUS_SRC  = '_cc_focus_src'; // auto | manual

/** The focus point of an image as [x, y] percent, finding it when missing (a few per page view). */
function cc_focus_get( $id, $compute = true ) {
	static $budget = 6;
	$id = (int) $id;
	if ( ! $id || ! wp_attachment_is_image( $id ) ) {
		return null;
	}
	$v = (string) get_post_meta( $id, CC_FOCUS_META, true );
	if ( '' === $v && $compute && $budget > 0 ) {
		$budget--;
		$v = cc_focus_auto_save( $id );
	}
	if ( ! preg_match( '/^(\d{1,3}(?:\.\d+)?),(\d{1,3}(?:\.\d+)?)$/', $v, $m ) ) {
		return null;
	}
	return array( min( 100, (float) $m[1] ), min( 100, (float) $m[2] ) );
}

function cc_focus_auto_save( $id ) {
	$p = cc_focus_detect( $id );
	$v = $p ? round( $p[0], 1 ) . ',' . round( $p[1], 1 ) : '50,50';
	update_post_meta( $id, CC_FOCUS_META, $v );
	update_post_meta( $id, CC_FOCUS_SRC, 'auto' );
	return $v;
}

/** Path of a small rendition of the image (fast to read), or the original. */
function cc_focus_file( $id ) {
	$file = get_attached_file( $id );
	foreach ( array( 'medium', 'medium_large' ) as $size ) {
		$s = image_get_intermediate_size( $id, $size );
		if ( $s && ! empty( $s['path'] ) ) {
			$up = wp_get_upload_dir();
			$f  = trailingslashit( $up['basedir'] ) . $s['path'];
			if ( file_exists( $f ) ) {
				return $f;
			}
		}
	}
	return $file && file_exists( $file ) ? $file : '';
}

/**
 * Where the subject probably is. Looks at a 64px version of the photo and weighs each spot by
 * detail (edges), colour, and skin tones (people), with a gentle pull to the middle. The focus is
 * the centre of the strongest areas.
 */
function cc_focus_detect( $id ) {
	if ( ! function_exists( 'imagecreatefromstring' ) ) {
		return null;
	}
	$f = cc_focus_file( $id );
	if ( ! $f || filesize( $f ) > 25 * MB_IN_BYTES ) {
		return null;
	}
	$src = @imagecreatefromstring( (string) file_get_contents( $f ) ); // phpcs:ignore
	if ( ! $src ) {
		return null;
	}
	$w0 = imagesx( $src );
	$h0 = imagesy( $src );
	$k  = 64 / max( $w0, $h0 );
	$w  = max( 8, (int) round( $w0 * $k ) );
	$h  = max( 8, (int) round( $h0 * $k ) );
	$im = imagecreatetruecolor( $w, $h );
	imagealphablending( $im, false );
	imagecopyresampled( $im, $src, 0, 0, 0, 0, $w, $h, $w0, $h0 );
	imagedestroy( $src );

	$lum = $rgb = $sat = $skin = $alpha = array();
	$mr = $mg = $mb = $n = 0;
	for ( $y = 0; $y < $h; $y++ ) {
		for ( $x = 0; $x < $w; $x++ ) {
			$c = imagecolorat( $im, $x, $y );
			$a = ( $c >> 24 ) & 0x7F;
			$r = ( $c >> 16 ) & 0xFF;
			$g = ( $c >> 8 ) & 0xFF;
			$b = $c & 0xFF;
			$i = $y * $w + $x;
			$lum[ $i ]   = 0.299 * $r + 0.587 * $g + 0.114 * $b;
			$rgb[ $i ]   = array( $r, $g, $b );
			$mx          = max( $r, $g, $b );
			$mn          = min( $r, $g, $b );
			$sat[ $i ]   = $mx ? ( $mx - $mn ) / $mx : 0;
			$skin[ $i ]  = ( $r > 95 && $g > 40 && $b > 20 && $r > $g && $r > $b && ( $r - $g ) > 15 && ( $mx - $mn ) > 15 ) ? 1 : 0;
			$alpha[ $i ] = $a > 100 ? 0 : 1; // transparent background counts for nothing
			if ( $alpha[ $i ] ) {
				$mr += $r; $mg += $g; $mb += $b; $n++;
			}
		}
	}
	imagedestroy( $im );
	if ( ! $n ) {
		return null;
	}
	$mr /= $n; $mg /= $n; $mb /= $n;

	// How much each spot stands out: detail, colour, difference from the overall picture, skin.
	$e = array_fill( 0, $w * $h, 0.0 );
	for ( $y = 1; $y < $h - 1; $y++ ) {
		for ( $x = 1; $x < $w - 1; $x++ ) {
			$L  = function ( $dx, $dy ) use ( $lum, $w, $x, $y ) { return $lum[ ( $y + $dy ) * $w + $x + $dx ]; };
			$gx = -$L( -1, -1 ) - 2 * $L( -1, 0 ) - $L( -1, 1 ) + $L( 1, -1 ) + 2 * $L( 1, 0 ) + $L( 1, 1 );
			$gy = -$L( -1, -1 ) - 2 * $L( 0, -1 ) - $L( 1, -1 ) + $L( -1, 1 ) + 2 * $L( 0, 1 ) + $L( 1, 1 );
			$i  = $y * $w + $x;
			list( $r, $g, $b ) = $rgb[ $i ];
			$dist  = sqrt( ( $r - $mr ) ** 2 + ( $g - $mg ) ** 2 + ( $b - $mb ) ** 2 ) / 441;
			$nx    = ( $x / ( $w - 1 ) ) - 0.5;
			$ny    = ( $y / ( $h - 1 ) ) - 0.5;
			$prior = 0.8 + 0.2 * exp( -( $nx * $nx + $ny * $ny ) / 0.2 );
			$e[ $i ] = ( sqrt( $gx * $gx + $gy * $gy ) / 3 + 20 * $sat[ $i ] + 35 * $dist + 40 * $skin[ $i ] ) * $prior * $alpha[ $i ];
		}
	}

	// The busiest area about a third of the picture wide and high (summed-area table) ...
	$S = array_fill( 0, ( $w + 1 ) * ( $h + 1 ), 0.0 );
	for ( $y = 0; $y < $h; $y++ ) {
		for ( $x = 0; $x < $w; $x++ ) {
			$v = $e[ $y * $w + $x ];
			$S[ ( $y + 1 ) * ( $w + 1 ) + $x + 1 ] = $v * $v + $S[ $y * ( $w + 1 ) + $x + 1 ] + $S[ ( $y + 1 ) * ( $w + 1 ) + $x ] - $S[ $y * ( $w + 1 ) + $x ];
		}
	}
	$bw   = max( 3, (int) round( $w * 0.35 ) );
	$bh   = max( 3, (int) round( $h * 0.35 ) );
	$best = -1;
	$bx   = $by = 0;
	for ( $y = 0; $y + $bh <= $h; $y++ ) {
		for ( $x = 0; $x + $bw <= $w; $x++ ) {
			$sum = $S[ ( $y + $bh ) * ( $w + 1 ) + $x + $bw ] - $S[ $y * ( $w + 1 ) + $x + $bw ] - $S[ ( $y + $bh ) * ( $w + 1 ) + $x ] + $S[ $y * ( $w + 1 ) + $x ];
			if ( $sum > $best ) {
				$best = $sum; $bx = $x; $by = $y;
			}
		}
	}
	if ( $best <= 0 ) {
		return null;
	}
	// ... and the weighted centre inside it.
	$sx = $sy = $sw = 0.0;
	for ( $y = $by; $y < $by + $bh; $y++ ) {
		for ( $x = $bx; $x < $bx + $bw; $x++ ) {
			$wt  = $e[ $y * $w + $x ] ** 2;
			$sx += $x * $wt;
			$sy += $y * $wt;
			$sw += $wt;
		}
	}
	// A nudge, not a jump: the guess is pulled 40% back to the middle, so a wrong guess never crops badly.
	$fx = 100 * ( $sx / $sw ) / ( $w - 1 );
	$fy = 100 * ( $sy / $sw ) / ( $h - 1 );
	return array( 50 + 0.6 * ( $fx - 50 ), 50 + 0.6 * ( $fy - 50 ) );
}

function cc_focus_style( $id ) {
	$p = cc_focus_get( $id );
	return $p ? sprintf( '--fx:%s%%;--fy:%s%%', round( $p[0], 1 ), round( $p[1], 1 ) ) : '';
}

/* New uploads: find the focus once the sizes exist. A new file (edited / replaced) resets an automatic one. */
add_filter( 'wp_generate_attachment_metadata', function ( $meta, $id ) {
	if ( 'manual' !== get_post_meta( $id, CC_FOCUS_SRC, true ) && wp_attachment_is_image( $id ) ) {
		delete_post_meta( $id, CC_FOCUS_META );
		add_action( 'shutdown', function () use ( $id ) { cc_focus_auto_save( $id ); } );
	}
	return $meta;
}, 20, 2 );

/* Images printed by the theme and plugin. */
add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $att ) {
	$s = cc_focus_style( $att->ID );
	if ( $s ) {
		$attr['style'] = trim( ( $attr['style'] ?? '' ) . ';' . $s, ';' );
	}
	return $attr;
}, 10, 2 );

/* Images inside pages and posts (image and cover blocks). */
add_filter( 'wp_content_img_tag', function ( $html, $context, $id ) {
	if ( ! $id || false !== strpos( $html, '--fx' ) ) {
		return $html;
	}
	$s = cc_focus_style( $id );
	if ( ! $s ) {
		return $html;
	}
	$t = new WP_HTML_Tag_Processor( $html );
	if ( $t->next_tag( 'img' ) ) {
		$t->set_attribute( 'style', trim( (string) $t->get_attribute( 'style' ) . ';' . $s, ';' ) );
		return $t->get_updated_html();
	}
	return $html;
}, 10, 3 );

/* Older photos: worked through in the background, 25 at a time. */
add_action( 'init', function () {
	if ( get_option( 'cc_focus_backfill_done' ) !== '1' && ! wp_next_scheduled( 'cc_focus_backfill' ) ) {
		wp_schedule_single_event( time() + 60, 'cc_focus_backfill' );
	}
} );
add_action( 'cc_focus_backfill', function () {
	$ids = get_posts( array(
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'post_status'    => 'inherit',
		'posts_per_page' => 25,
		'fields'         => 'ids',
		'meta_query'     => array( array( 'key' => CC_FOCUS_META, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore
	) );
	foreach ( $ids as $id ) {
		cc_focus_auto_save( $id );
	}
	if ( count( $ids ) < 25 ) {
		update_option( 'cc_focus_backfill_done', '1', false );
	} else {
		wp_schedule_single_event( time() + 30, 'cc_focus_backfill' );
	}
} );

/* Media library: "Focus point" field — click the subject on the preview. */
add_filter( 'attachment_fields_to_edit', function ( $fields, $post ) {
	if ( ! wp_attachment_is_image( $post->ID ) ) {
		return $fields;
	}
	$p    = cc_focus_get( $post->ID ) ?: array( 50, 50 );
	$src  = wp_get_attachment_image_url( $post->ID, 'medium' );
	$man  = 'manual' === get_post_meta( $post->ID, CC_FOCUS_SRC, true );
	$name = 'attachments[' . $post->ID . '][cc_focus]';
	$fields['cc_focus'] = array(
		'label' => 'Focus point',
		'input' => 'html',
		'html'  => sprintf(
			'<div class="cc-focus" data-id="%1$d"><div class="cc-focus__pic" style="position:relative;display:inline-block;max-width:100%%;cursor:crosshair;line-height:0"><img src="%2$s" alt="" style="max-width:100%%;height:auto;display:block"><span class="cc-focus__dot" style="position:absolute;left:%3$s%%;top:%4$s%%;width:18px;height:18px;margin:-9px 0 0 -9px;border:2px solid #fff;border-radius:50%%;background:#FF4FA3;box-shadow:0 0 0 1px #141217;pointer-events:none"></span></div>'
			. '<input type="hidden" class="cc-focus__val" name="%5$s" id="%5$s" value="%3$s,%4$s">'
			. '<p class="description" style="margin-top:6px">Click on the subject. When this photo is cropped (shop tiles, phones, cards) the crop keeps this spot in view. <span class="cc-focus__state">%6$s</span>%7$s</p></div>',
			$post->ID,
			esc_url( $src ),
			esc_attr( round( $p[0], 1 ) ),
			esc_attr( round( $p[1], 1 ) ),
			esc_attr( $name ),
			$man ? 'Set by hand.' : 'Found automatically.',
			$man ? ' <a href="#" class="cc-focus__auto">Find it automatically again</a>' : ''
		),
	);
	return $fields;
}, 10, 2 );

add_filter( 'attachment_fields_to_save', function ( $post, $att ) {
	if ( ! isset( $att['cc_focus'] ) ) {
		return $post;
	}
	$id = (int) $post['ID'];
	if ( 'auto' === $att['cc_focus'] ) {
		cc_focus_auto_save( $id );
	} elseif ( preg_match( '/^(\d{1,3}(?:\.\d+)?),(\d{1,3}(?:\.\d+)?)$/', $att['cc_focus'], $m ) ) {
		$v = min( 100, (float) $m[1] ) . ',' . min( 100, (float) $m[2] );
		if ( $v !== (string) get_post_meta( $id, CC_FOCUS_META, true ) ) {
			update_post_meta( $id, CC_FOCUS_META, $v );
			update_post_meta( $id, CC_FOCUS_SRC, 'manual' );
		}
	}
	return $post;
}, 10, 2 );

add_action( 'admin_enqueue_scripts', function () {
	wp_register_script( 'cc-focus', '', array( 'jquery' ), CC_THEME_VERSION, true );
	wp_enqueue_script( 'cc-focus' );
	wp_add_inline_script( 'cc-focus', <<<'JS'
jQuery(function ($) {
	$(document).on('click', '.cc-focus__pic', function (e) {
		var r = this.getBoundingClientRect();
		var x = Math.max(0, Math.min(100, (e.clientX - r.left) / r.width * 100)).toFixed(1);
		var y = Math.max(0, Math.min(100, (e.clientY - r.top) / r.height * 100)).toFixed(1);
		var box = $(this).closest('.cc-focus');
		box.find('.cc-focus__dot').css({ left: x + '%', top: y + '%' });
		box.find('.cc-focus__state').text('Set by hand.');
		box.find('.cc-focus__val').val(x + ',' + y).trigger('change');
	});
	$(document).on('click', '.cc-focus__auto', function (e) {
		e.preventDefault();
		var box = $(this).closest('.cc-focus');
		box.find('.cc-focus__state').text('Will be found automatically when saved.');
		$(this).remove();
		box.find('.cc-focus__val').val('auto').trigger('change');
	});
});
JS
	);
} );

/*
 * Cover-cropped images use the focus point. Photos shown whole (shop products on their tile,
 * drawings, the bike gallery on desktop) stay centred.
 */
add_action( 'wp_head', function () {
	echo '<style id="cc-focus">img[style*="--fx"]{object-position:var(--fx) var(--fy)}'
		. '.cc-card__pic img,.cc-pdp__slide img,.cc-cart__img img,.drawings img{object-position:50% 50%}'
		. '.cc-card--fund .cc-card__pic img,.cc-pdp--fund .cc-pdp__slide img{object-position:var(--fx,50%) var(--fy,50%)}'
		. '@media (min-width:900px){.cc-gal__slide img{object-position:50% 50%}}</style>' . "\n";
}, 20 );
