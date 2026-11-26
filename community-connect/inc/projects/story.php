<?php
/**
 * Stories: the magazine layout for long ride stories (look "Story").
 *
 * The system, in four kinds of media with fixed jobs:
 * - Still  — good photographs (film, Ricoh, drone). Core image / gallery blocks. They carry the place.
 * - Tape   — 5–10 s phone clips, muted loops in a black "tape" frame with a sound button: [cc_story_tape].
 *            They carry the moment. Never full width: lo-fi footage stays honest at half size.
 * - Reel   — the long film (the project's "Main film"). It carries the whole trip, once.
 * - Line   — the GPX: [cc_story_route] (all days on one map) and [cc_story_day n="4"] (a day opener with
 *            distance, climbing, high point, elevation profile and the shape of the day). They carry the effort.
 * Words: body paragraphs, voices (quote block, style "Voice", cite = who said it) and margin notes
 * (paragraph with the class cc-snote). A sticky rail follows the reader: which day, how far into it.
 *
 * Route data: upload one GPX per day in the "Project details" box. It is reduced to ~180 map points and
 * ~160 profile points per day and stored as JSON in _cc_p_days.
 *
 * @package community-connect
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Route data ---------- */

function cc_story_days( $id = 0 ) {
	static $cache = array();
	$id = $id ?: get_the_ID();
	if ( ! isset( $cache[ $id ] ) ) {
		$d             = json_decode( (string) get_post_meta( $id, '_cc_p_days', true ), true );
		$cache[ $id ] = is_array( $d ) ? array_values( $d ) : array();
	}
	return $cache[ $id ];
}

add_action( 'init', function () {
	register_post_meta( CC_PROJECT, '_cc_p_days', array(
		'type' => 'string', 'single' => true, 'show_in_rest' => true,
		'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
	) );
} );

/** Elevation scale shared by every day of a story, so the profiles compare honestly. */
function cc_story_scale( $days ) {
	$lo = PHP_INT_MAX;
	$hi = 0;
	foreach ( $days as $d ) {
		$lo = min( $lo, (int) ( $d['min'] ?? 0 ) );
		$hi = max( $hi, (int) ( $d['max'] ?? 0 ) );
	}
	return array( max( 0, floor( $lo / 100 ) * 100 - 300 ), ceil( $hi / 100 ) * 100 + 100 );
}

function cc_story_n( $x ) {
	return number_format( (float) $x, 0, '.', ',' );
}

/** Haversine metres. */
function cc_story_dist( $a, $b ) {
	$r  = 6371000;
	$p1 = deg2rad( $a[0] );
	$p2 = deg2rad( $b[0] );
	$dp = $p2 - $p1;
	$dl = deg2rad( $b[1] - $a[1] );
	$h  = sin( $dp / 2 ) ** 2 + cos( $p1 ) * cos( $p2 ) * sin( $dl / 2 ) ** 2;
	return 2 * $r * asin( min( 1, sqrt( $h ) ) );
}

/** One GPX file → one day (numbers, map line, profile). */
function cc_story_parse_gpx( $file ) {
	$prev = libxml_use_internal_errors( true );
	$xml  = simplexml_load_file( $file, 'SimpleXMLElement', LIBXML_NONET );
	libxml_use_internal_errors( $prev );
	if ( ! $xml ) {
		return null;
	}
	$xml->registerXPathNamespace( 'g', $xml->getNamespaces()[''] ?? 'http://www.topografix.com/GPX/1/1' );
	$nodes = $xml->xpath( '//g:trkpt' ) ?: ( $xml->xpath( '//g:rtept' ) ?: array() );
	$pts   = array();
	foreach ( $nodes as $n ) {
		$pts[] = array( (float) $n['lat'], (float) $n['lon'], isset( $n->ele ) ? (float) $n->ele : null );
	}
	if ( count( $pts ) < 2 ) {
		return null;
	}
	$cum = array( 0 );
	for ( $i = 1, $c = count( $pts ); $i < $c; $i++ ) {
		$cum[] = $cum[ $i - 1 ] + cc_story_dist( $pts[ $i - 1 ], $pts[ $i ] );
	}
	$total = end( $cum );
	// Climbing with a 5 m dead band (planned-route elevation is smooth; recorded tracks are noisy).
	$gain = 0;
	$ref  = null;
	$max  = -INF;
	$min  = INF;
	$maxi = 0;
	foreach ( $pts as $i => $p ) {
		if ( null === $p[2] ) {
			continue;
		}
		if ( $p[2] > $max ) {
			$max  = $p[2];
			$maxi = $i;
		}
		$min = min( $min, $p[2] );
		if ( null === $ref ) {
			$ref = $p[2];
		} elseif ( $p[2] > $ref + 5 ) {
			$gain += $p[2] - $ref;
			$ref   = $p[2];
		} elseif ( $p[2] < $ref - 5 ) {
			$ref = $p[2];
		}
	}
	$sample = function ( $n, $with_ele ) use ( $pts, $cum, $total ) {
		$out = array();
		$j   = 0;
		for ( $k = 0; $k < $n; $k++ ) {
			$t = $total * $k / ( $n - 1 );
			while ( $j < count( $cum ) - 2 && $cum[ $j + 1 ] < $t ) {
				$j++;
			}
			$p     = $pts[ $j ];
			$out[] = $with_ele ? array( round( $t / 1000, 2 ), (int) round( (float) $p[2] ) ) : array( round( $p[0], 5 ), round( $p[1], 5 ) );
		}
		return $out;
	};
	return array(
		'km'      => round( $total / 1000, 1 ),
		'gain'    => (int) round( $gain ),
		'max'     => is_finite( $max ) ? (int) round( $max ) : 0,
		'min'     => is_finite( $min ) ? (int) round( $min ) : 0,
		'maxAt'   => array( $pts[ $maxi ][0], $pts[ $maxi ][1] ),
		'line'    => $sample( 180, false ),
		'profile' => $sample( 160, true ),
	);
}

/* "Story route" part of the Project details box. */
add_action( 'post_edit_form_tag', function ( $post ) {
	if ( $post && CC_PROJECT === $post->post_type ) {
		echo ' enctype="multipart/form-data"';
	}
} );

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'cc-story-route', 'Story route (GPX)', function ( $post ) {
		$days  = cc_story_days( $post->ID );
		$lines = array_map( function ( $d ) {
			return implode( ' | ', array( $d['title'] ?? '', $d['date'] ?? '', $d['start'] ?? '', $d['end'] ?? '', $d['summary'] ?? '' ) );
		}, $days );
		echo '<p>For the <strong>Story</strong> look. Upload one GPX per day, in order (choosing files replaces the route). Then put <code>[cc_story_route]</code> where the map goes and <code>[cc_story_day n="1"]</code> at the start of each day.</p>';
		echo '<p><input type="file" name="cc_story_gpx[]" accept=".gpx,application/gpx+xml" multiple></p>';
		if ( $days ) {
			$km = array_sum( array_column( $days, 'km' ) );
			echo '<p class="description">Now: ' . count( $days ) . ' days, ' . esc_html( cc_story_n( $km ) ) . ' km, ' . esc_html( cc_story_n( array_sum( array_column( $days, 'gain' ) ) ) ) . ' m climbing.</p>';
		}
		echo '<p><label for="cc_story_labels"><strong>Day labels</strong> — one line per day: Title | Date | From | To | One-line summary</label></p>';
		printf( '<textarea id="cc_story_labels" name="cc_story_labels" rows="%d" class="large-text code">%s</textarea>', max( 4, count( $days ) + 1 ), esc_textarea( implode( "\n", $lines ) ) );
	}, CC_PROJECT, 'normal', 'default' );
} );

add_action( 'save_post_' . CC_PROJECT, function ( $id ) {
	if ( ! isset( $_POST['cc_project_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['cc_project_nonce'] ), 'cc_project_save' ) || ! current_user_can( 'edit_post', $id ) ) {
		return;
	}
	$days = cc_story_days( $id );
	if ( ! empty( $_FILES['cc_story_gpx']['tmp_name'] ) && is_array( $_FILES['cc_story_gpx']['tmp_name'] ) ) {
		$new = array();
		foreach ( $_FILES['cc_story_gpx']['tmp_name'] as $i => $tmp ) {
			if ( $tmp && is_uploaded_file( $tmp ) && ! $_FILES['cc_story_gpx']['error'][ $i ] ) {
				$d = cc_story_parse_gpx( $tmp );
				if ( $d ) {
					$new[] = array_merge( $days[ count( $new ) ] ?? array(), $d );
				}
			}
		}
		if ( $new ) {
			$days = $new;
		}
	}
	if ( isset( $_POST['cc_story_labels'] ) ) {
		$rows = preg_split( '/\r?\n/', trim( sanitize_textarea_field( wp_unslash( $_POST['cc_story_labels'] ) ) ) );
		foreach ( $days as $i => &$d ) {
			$c = array_map( 'trim', explode( '|', $rows[ $i ] ?? '' ) );
			foreach ( array( 'title', 'date', 'start', 'end', 'summary' ) as $j => $k ) {
				if ( isset( $c[ $j ] ) && '' !== $c[ $j ] ) {
					$d[ $k ] = $c[ $j ];
				}
			}
			$d['day'] = $i + 1;
		}
		unset( $d );
	}
	if ( $days ) {
		update_post_meta( $id, '_cc_p_days', wp_slash( wp_json_encode( $days ) ) );
	}
}, 20 );

/* ---------- [cc_story_day n="1"] ---------- */

add_shortcode( 'cc_story_day', function ( $atts ) {
	$a    = shortcode_atts( array( 'n' => 1 ), $atts );
	$days = cc_story_days();
	$n    = max( 1, (int) $a['n'] );
	$d    = $days[ $n - 1 ] ?? null;
	if ( ! $d ) {
		return '';
	}
	$pad          = sprintf( '%02d', $n );
	list( $lo, $hi ) = cc_story_scale( $days );
	$p            = $d['profile'];
	$kmax         = max( 0.1, end( $p )[0] );
	$X            = function ( $k ) use ( $kmax ) { return round( $k / $kmax * 1000, 1 ); };
	$Y            = function ( $e ) use ( $lo, $hi ) { return round( 200 - ( $e - $lo ) / ( $hi - $lo ) * 190, 1 ); };
	$line         = '';
	$pk           = $p[0];
	foreach ( $p as $i => $q ) {
		$line .= ( $i ? 'L' : 'M' ) . $X( $q[0] ) . ' ' . $Y( $q[1] );
		if ( $q[1] > $pk[1] ) {
			$pk = $q;
		}
	}
	// Shape of the day, north up.
	$L   = $d['line'];
	$k   = cos( deg2rad( $L[0][0] ) );
	$xs  = array_map( function ( $q ) use ( $k ) { return $q[1] * $k; }, $L );
	$ys  = array_map( function ( $q ) { return -$q[0]; }, $L );
	$s   = 104 / max( 1e-9, max( max( $xs ) - min( $xs ), max( $ys ) - min( $ys ) ) );
	$ox  = ( 120 - ( max( $xs ) - min( $xs ) ) * $s ) / 2;
	$oy  = ( 120 - ( max( $ys ) - min( $ys ) ) * $s ) / 2;
	$T   = function ( $i ) use ( $xs, $ys, $s, $ox, $oy ) { return array( round( $ox + ( $xs[ $i ] - min( $xs ) ) * $s, 1 ), round( $oy + ( $ys[ $i ] - min( $ys ) ) * $s, 1 ) ); };
	$rt  = '';
	foreach ( $L as $i => $q ) {
		$t   = $T( $i );
		$rt .= ( $i ? 'L' : 'M' ) . $t[0] . ' ' . $t[1];
	}
	$a0   = $T( 0 );
	$a1   = $T( count( $L ) - 1 );
	$year = preg_match( '/\d{4}/', cc_project_get( get_the_ID(), 'years' ), $m ) ? ' ' . $m[0] : '';
	$stat = function ( $label, $v, $unit, $cls = '' ) {
		return '<div' . ( $cls ? ' class="' . $cls . '"' : '' ) . '><dt>' . $label . '</dt><dd>' . esc_html( $v ) . '<small> ' . $unit . '</small></dd></div>';
	};
	return '<section class="cc-sday" id="day-' . $n . '" data-day="' . $n . '">'
		. '<div class="cc-sday__l"><p class="cc-sday__meta"><span>[Day ' . $pad . ' / ' . sprintf( '%02d', count( $days ) ) . ']</span><span>' . esc_html( ( $d['date'] ?? '' ) . $year ) . '</span></p>'
		. '<b class="cc-sday__n" aria-hidden="true">' . $pad . '</b>'
		. '<h2 class="cc-sday__t"><span class="screen-reader-text">Day ' . $n . ': </span>' . esc_html( $d['title'] ?? '' ) . '</h2>'
		. '<p class="cc-sday__ft">' . esc_html( ( $d['start'] ?? '' ) . ' → ' . ( $d['end'] ?? '' ) ) . '</p></div>'
		. '<div class="cc-sday__r"><dl class="cc-sday__st">' . $stat( 'Distance', round( $d['km'] ), 'km' ) . $stat( 'Climbing', cc_story_n( $d['gain'] ), 'm' ) . $stat( 'High point', cc_story_n( $d['max'] ), 'm', 'is-hi' ) . '</dl>'
		. '<div class="cc-sday__viz"><svg class="cc-sday__prof" viewBox="0 0 1000 200" preserveAspectRatio="none" role="img" aria-label="Elevation profile: ' . esc_attr( cc_story_n( $p[0][1] ) ) . ' m to ' . esc_attr( cc_story_n( end( $p )[1] ) ) . ' m, high point ' . esc_attr( cc_story_n( $d['max'] ) ) . ' m">'
		. '<path d="' . $line . 'L1000 200L0 200Z" fill="#D3C8B4"/><path d="' . $line . '" fill="none" stroke="#141217" stroke-width="2.5" vector-effect="non-scaling-stroke"/>'
		. '<line x1="' . $X( $pk[0] ) . '" x2="' . $X( $pk[0] ) . '" y1="' . $Y( $pk[1] ) . '" y2="200" stroke="#A56C12" stroke-width="1.5" vector-effect="non-scaling-stroke"/></svg>'
		. '<svg class="cc-sday__shape" viewBox="0 0 120 120" role="img" aria-label="Shape of the day\'s route"><path d="' . $rt . '" fill="none" stroke="#141217" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/><circle cx="' . $a0[0] . '" cy="' . $a0[1] . '" r="4" fill="#F4EEE4" stroke="#141217" stroke-width="2"/><circle cx="' . $a1[0] . '" cy="' . $a1[1] . '" r="4" fill="#141217"/></svg></div>'
		. '<p class="cc-sday__ax"><span>0 km · ' . cc_story_n( $p[0][1] ) . ' m</span><span>GPX · ' . cc_story_n( $lo ) . '–' . cc_story_n( $hi ) . ' m scale</span><span>' . round( $d['km'] ) . ' km · ' . cc_story_n( end( $p )[1] ) . ' m</span></p>'
		. '</div></section>';
} );

/* ---------- [cc_story_route] ---------- */

add_shortcode( 'cc_story_route', function ( $atts ) {
	$a    = shortcode_atts( array( 'peak' => '' ), $atts );
	$days = cc_story_days();
	if ( ! $days ) {
		return '';
	}
	$k  = cos( deg2rad( $days[0]['line'][0][0] ) );
	$x0 = $y0 = INF;
	$x1 = $y1 = -INF;
	foreach ( $days as $d ) {
		foreach ( $d['line'] as $q ) {
			$x0 = min( $x0, $q[1] * $k );
			$x1 = max( $x1, $q[1] * $k );
			$y0 = min( $y0, -$q[0] );
			$y1 = max( $y1, -$q[0] );
		}
	}
	$s  = min( 920 / max( 1e-9, $x1 - $x0 ), 520 / max( 1e-9, $y1 - $y0 ) );
	$ox = 40 + ( 920 - ( $x1 - $x0 ) * $s ) / 2;
	$oy = 90;
	$H  = (int) ceil( ( $y1 - $y0 ) * $s + 180 );
	$T  = function ( $q ) use ( $k, $x0, $y0, $s, $ox, $oy ) { return array( round( $ox + ( $q[1] * $k - $x0 ) * $s, 1 ), round( $oy + ( -$q[0] - $y0 ) * $s, 1 ) ); };
	$paths = $towns = $list = '';
	$hiDay = 0;
	foreach ( $days as $i => $d ) {
		$dd = '';
		foreach ( $d['line'] as $j => $q ) {
			$t   = $T( $q );
			$dd .= ( $j ? 'L' : 'M' ) . $t[0] . ' ' . $t[1];
		}
		$paths .= '<path class="cc-sroute__p" data-day="' . ( $i + 1 ) . '" d="' . $dd . '"/>';
		$list  .= '<li><a href="#day-' . ( $i + 1 ) . '" data-day="' . ( $i + 1 ) . '" data-sel="' . esc_attr( '[Day ' . sprintf( '%02d', $i + 1 ) . ' · ' . ( $d['date'] ?? '' ) . ']|' . ( $d['start'] ?? '' ) . ' → ' . ( $d['end'] ?? '' ) . '|' . round( $d['km'] ) . ' km · ' . cc_story_n( $d['gain'] ) . ' m up · high ' . cc_story_n( $d['max'] ) . ' m' ) . '"><span>' . sprintf( '%02d', $i + 1 ) . '</span><b>' . esc_html( $d['title'] ?? '' ) . '</b><span>' . round( $d['km'] ) . ' km</span></a></li>';
		if ( $d['max'] > ( $days[ $hiDay ]['max'] ?? 0 ) ) {
			$hiDay = $i;
		}
	}
	$stops = array_map( function ( $d ) { return array( $d['line'][0], $d['start'] ?? '' ); }, $days );
	$last  = end( $days );
	$stops[] = array( end( $last['line'] ), $last['end'] ?? '' );
	$seen  = array();
	foreach ( $stops as $st ) {
		if ( isset( $seen[ $st[1] ] ) ) {
			continue;
		}
		$seen[ $st[1] ] = 1;
		$t     = $T( $st[0] );
		// Which side of the town is free? Sum where the track goes within ~40 px and label the emptier side.
		$up = $dn = $lf = $rg = 0;
		foreach ( $days as $d ) {
			foreach ( $d['line'] as $q ) {
				$u = $T( $q );
				$dx = $u[0] - $t[0];
				$dy = $u[1] - $t[1];
				if ( abs( $dx ) < 90 && abs( $dy ) < 40 && ( $dx || $dy ) ) {
					$dy < 0 ? $up++ : $dn++;
					$dx < 0 ? $lf++ : $rg++;
				}
			}
		}
		$below  = $up > $dn;
		$left   = $t[0] > 820 || ( $rg > $lf * 1.5 && $t[0] > 200 );
		$towns .= '<circle cx="' . $t[0] . '" cy="' . $t[1] . '" r="5" class="cc-sroute__town"/><text x="' . ( $t[0] + ( $left ? -10 : 10 ) ) . '" y="' . ( $t[1] + ( $below ? 22 : -10 ) ) . '"' . ( $left ? ' text-anchor="end"' : '' ) . ' class="cc-sroute__lbl">' . esc_html( strtoupper( $st[1] ) ) . '</text>';
	}
	$hd   = $days[ $hiDay ];
	$pt   = $T( $hd['maxAt'] ?? $hd['line'][0] );
	$peak = $a['peak'] ?: cc_story_n( $hd['max'] ) . ' m';
	$bar  = round( 24 + $s * ( 50 / 111.32 ), 1 );
	$tk   = array_sum( array_column( $days, 'km' ) );
	$tg   = array_sum( array_column( $days, 'gain' ) );
	$first = $days[0]['start'] ?? '';
	$all   = '[The whole route · ' . count( $days ) . ' days]|' . $first . ' → ' . ( $last['end'] ?? '' ) . '|' . cc_story_n( $tk ) . ' km · ' . cc_story_n( $tg ) . ' m up';
	$sel   = explode( '|', $all );
	return '<section class="cc-sroute" aria-label="The route"><div class="cc-sroute__map"><svg viewBox="0 0 1000 ' . $H . '" role="img" aria-label="' . esc_attr( 'The ' . count( $days ) . ' days of the route, drawn from GPX, ' . $first . ' to ' . ( $last['end'] ?? '' ) ) . '">'
		. '<text x="24" y="40" class="cc-sroute__cap">[ROUTE · ' . count( $days ) . ' GPX TRACKS · N ↑]</text>'
		. $paths . $towns
		. '<path d="M' . $pt[0] . ' ' . ( $pt[1] - 4 ) . ' l-7 -12 h14 z" fill="#A56C12"/><text x="' . $pt[0] . '" y="' . ( $pt[1] - 26 ) . '" text-anchor="middle" class="cc-sroute__peak">' . esc_html( $peak ) . '</text>'
		. '<line x1="24" x2="' . $bar . '" y1="' . ( $H - 28 ) . '" y2="' . ( $H - 28 ) . '" stroke="#141217" stroke-width="2"/><text x="24" y="' . ( $H - 40 ) . '" class="cc-sroute__cap">0</text><text x="' . $bar . '" y="' . ( $H - 40 ) . '" text-anchor="end" class="cc-sroute__cap">50 km</text>'
		. '</svg></div><div class="cc-sroute__side"><div class="cc-sroute__sel" data-all="' . esc_attr( $all ) . '"><span>' . esc_html( $sel[0] ) . '</span><b>' . esc_html( $sel[1] ) . '</b><span>' . esc_html( $sel[2] ) . '</span></div>'
		. '<ol class="cc-sroute__list">' . $list . '</ol><p class="cc-sroute__note">Planned route from GPX. Point at a day to light it up; tap to jump to it.</p></div></section>';
} );

/* ---------- [cc_story_tape src="" poster="" label="" meta="" caption="" side="right"] ---------- */

add_shortcode( 'cc_story_tape', function ( $atts ) {
	$a = shortcode_atts( array( 'src' => '', 'poster' => '', 'label' => '', 'meta' => '', 'caption' => '', 'side' => 'right' ), $atts );
	if ( ! $a['src'] ) {
		return '';
	}
	return '<figure class="cc-stape cc-stape--' . ( 'left' === $a['side'] ? 'left' : 'right' ) . '"><div class="cc-stape__in"><div class="cc-stape__frame">'
		. '<video src="' . esc_url( $a['src'] ) . '"' . ( $a['poster'] ? ' poster="' . esc_url( $a['poster'] ) . '"' : '' ) . ' muted loop playsinline preload="none"></video>'
		. '<button type="button" class="cc-stape__snd" aria-pressed="false" aria-label="Turn sound on"><svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><path d="M2 5h3l4-3v10L5 9H2z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path class="x" d="M10.5 5l3 4m0-4l-3 4" stroke="currentColor" stroke-width="1.4"/><path class="w" d="M11 4.5c1.2 1.4 1.2 3.6 0 5" fill="none" stroke="currentColor" stroke-width="1.4"/></svg><span>Sound</span></button>'
		. '<p class="cc-stape__bar"><span><i>●</i> ' . esc_html( $a['label'] ) . '</span><span>' . esc_html( $a['meta'] ) . '</span></p></div>'
		. ( $a['caption'] ? '<figcaption>' . esc_html( $a['caption'] ) . '</figcaption>' : '' ) . '</div></figure>';
} );

/* ---------- Editor styles: Voice quote ---------- */

add_action( 'init', function () {
	register_block_style( 'core/quote', array( 'name' => 'voice', 'label' => 'Voice' ) );
	register_block_style( 'core/paragraph', array( 'name' => 'note', 'label' => 'Margin note' ) );
} );

/* ---------- Rail + tapes + route hover (only on story pages) ---------- */

add_action( 'wp_footer', function () {
	if ( ! is_singular( CC_PROJECT ) || 'story' !== cc_project_get( get_queried_object_id(), 'look' ) ) {
		return;
	}
	$days = cc_story_days( get_queried_object_id() );
	if ( $days ) {
		list( $lo, $hi ) = cc_story_scale( $days );
		$rail = array( 'lo' => $lo, 'hi' => $hi, 'days' => array_map( function ( $d ) {
			$p = array();
			foreach ( $d['profile'] as $i => $q ) {
				if ( 0 === $i % 3 || $i === count( $d['profile'] ) - 1 ) {
					$p[] = $q;
				}
			}
			return array( 't' => $d['title'] ?? '', 'km' => $d['km'], 'p' => $p );
		}, $days ) );
		echo '<script type="application/json" id="cc-story-data">' . wp_json_encode( $rail ) . '</script>';
	}
	?>
<script>
(function(){
var data=null; try{data=JSON.parse((document.getElementById('cc-story-data')||{}).textContent||'null');}catch(e){}
var calm=window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches;
/* Tapes: play while on screen, muted; one sound at a time. */
var tapes=[].slice.call(document.querySelectorAll('.cc-stape'));
function setSound(t,on){var v=t.querySelector('video'),b=t.querySelector('.cc-stape__snd');v.muted=!on;b.setAttribute('aria-pressed',on?'true':'false');b.setAttribute('aria-label',on?'Turn sound off':'Turn sound on');b.querySelector('span').textContent=on?'On':'Sound';t.classList.toggle('is-loud',on);}
tapes.forEach(function(t){var v=t.querySelector('video'),b=t.querySelector('.cc-stape__snd');
  b.addEventListener('click',function(){var on=v.muted;tapes.forEach(function(o){if(o!==t)setSound(o,false);});setSound(t,on);var p=v.play();if(p&&p.catch)p.catch(function(){});});
  if(calm){v.controls=true;}
});
if('IntersectionObserver' in window&&!calm){var io=new IntersectionObserver(function(es){es.forEach(function(e){var v=e.target;if(e.isIntersecting){v.preload='auto';var p=v.play();if(p&&p.catch)p.catch(function(){});}else{v.pause();}});},{threshold:.35});
  tapes.forEach(function(t){io.observe(t.querySelector('video'));});}
/* Route map: light up a day. */
var rm=document.querySelector('.cc-sroute');
if(rm){var sel=rm.querySelector('.cc-sroute__sel'),all=sel.dataset.all;
  function show(n,txt){rm.classList.toggle('has-sel',!!n);rm.querySelectorAll('[data-day]').forEach(function(el){el.classList.toggle('on',el.dataset.day===String(n));});
    var s=(txt||all).split('|');sel.children[0].textContent=s[0];sel.children[1].textContent=s[1];sel.children[2].textContent=s[2];
    var p=rm.querySelector('.cc-sroute__p.on');if(p)p.parentNode.insertBefore(p,p.parentNode.querySelector('.cc-sroute__town'));}
  rm.querySelectorAll('.cc-sroute__list a').forEach(function(a){a.addEventListener('mouseenter',function(){show(a.dataset.day,a.dataset.sel);});a.addEventListener('focus',function(){show(a.dataset.day,a.dataset.sel);});});
  rm.querySelectorAll('.cc-sroute__p').forEach(function(p){p.addEventListener('mouseenter',function(){var a=rm.querySelector('.cc-sroute__list a[data-day="'+p.dataset.day+'"]');show(p.dataset.day,a&&a.dataset.sel);});
    p.addEventListener('click',function(){location.hash='day-'+p.dataset.day;});});
  rm.addEventListener('mouseleave',function(){show(0);});
}
/* Rail: which day, how far into it. */
var heads=[].slice.call(document.querySelectorAll('.cc-sday'));
if(!data||!heads.length)return;
var end=document.querySelector('.cc-pbody'),N=data.days.length,lo=data.lo,hi=data.hi;
var r=document.createElement('aside');r.className='cc-srail';r.setAttribute('aria-label','Where you are in the story');
var ticks='';for(var i=1;i<=N;i++)ticks+='<a href="#day-'+i+'" aria-label="Day '+i+': '+data.days[i-1].t.replace(/"/g,'&quot;')+'"></a>';
r.innerHTML='<p class="cc-srail__k">[Day <span class="n1">01</span> / '+('0'+N).slice(-2)+']</p><b class="cc-srail__n">01</b><p class="cc-srail__t"></p>'
 +'<svg class="cc-srail__p" viewBox="0 0 200 90" preserveAspectRatio="none" aria-hidden="true"><path class="a" fill="#D3C8B4"/><path class="l" fill="none" stroke="#141217" stroke-width="1.6" vector-effect="non-scaling-stroke"/><line class="m" y1="0" y2="90" stroke="#FFB1EC" stroke-width="3" vector-effect="non-scaling-stroke"/></svg>'
 +'<p class="cc-srail__km"></p><nav class="cc-srail__ticks">'+ticks+'</nav><span class="cc-srail__bar"><i></i></span>';
document.body.appendChild(r);
var cur=-1,el={n:r.querySelector('.cc-srail__n'),n1:r.querySelector('.n1'),t:r.querySelector('.cc-srail__t'),a:r.querySelector('.a'),l:r.querySelector('.l'),m:r.querySelector('.m'),km:r.querySelector('.cc-srail__km'),bar:r.querySelector('.cc-srail__bar i'),ticks:r.querySelectorAll('.cc-srail__ticks a')};
function draw(i){var d=data.days[i],P=d.p,K=P[P.length-1][0]||1,s='';P.forEach(function(q,j){s+=(j?'L':'M')+(q[0]/K*200).toFixed(1)+' '+(90-(q[1]-lo)/(hi-lo)*86).toFixed(1);});
  el.l.setAttribute('d',s);el.a.setAttribute('d',s+'L200 90L0 90Z');var p=('0'+(i+1)).slice(-2);el.n.textContent=p;el.n1.textContent=p;el.t.textContent=d.t;
  el.ticks.forEach(function(a,j){a.classList.toggle('on',j===i);a.classList.toggle('done',j<i);});}
var tick=false;
function upd(){tick=false;var mid=innerHeight*.45,i=-1;for(var j=0;j<heads.length;j++){if(heads[j].getBoundingClientRect().top<mid)i=j;}
  var eb=end.getBoundingClientRect().bottom;var on=i>=0&&eb>innerHeight*.6&&heads[i].getBoundingClientRect().bottom<innerHeight*.25;r.classList.toggle('is-on',on);if(!on)return;
  var n=+heads[i].dataset.day-1;if(n!==cur){cur=n;draw(n);}
  var top=heads[i].getBoundingClientRect().top,nx=heads[i+1]?heads[i+1].getBoundingClientRect().top:eb-innerHeight*.6+mid,f=Math.max(0,Math.min(1,(mid-top)/Math.max(1,nx-top)));
  var d=data.days[n];el.m.setAttribute('x1',(f*200).toFixed(1));el.m.setAttribute('x2',(f*200).toFixed(1));el.km.textContent=Math.round(f*d.km)+' / '+Math.round(d.km)+' km';el.bar.style.width=(f*100).toFixed(1)+'%';}
addEventListener('scroll',function(){if(!tick){tick=true;requestAnimationFrame(upd);}},{passive:true});addEventListener('resize',upd);upd();
})();
</script>
	<?php
}, 31 );

/* ---------- One-off: bring a story's media in from a zip link (admins) ---------- */

add_action( 'rest_api_init', function () {
	register_rest_route( 'cc/v1', '/story-media', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'upload_files' ) && current_user_can( 'manage_options' ); },
		'callback'            => 'cc_story_media_rest',
	) );
} );

function cc_story_media_rest( WP_REST_Request $r ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$slug = sanitize_key( $r['slug'] ?: 'story' );
	$up   = wp_upload_dir();
	$dir  = trailingslashit( $up['basedir'] ) . 'cc-story-in/' . $slug;
	$opt  = 'cc_story_media_' . $slug;
	$map  = get_option( $opt, array() );
	$step = (string) $r['step'];
	if ( 'fetch' === $step ) {
		$url = esc_url_raw( (string) $r['url'] );
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return new WP_Error( 'cc_url', 'An https link is needed.', array( 'status' => 400 ) );
		}
		$tmp = download_url( $url, 600 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$fh  = fopen( $tmp, 'rb' );
		$sig = fread( $fh, 4 );
		fclose( $fh );
		if ( "PK\x03\x04" !== $sig ) {
			$size = filesize( $tmp );
			wp_delete_file( $tmp );
			return new WP_Error( 'cc_zip', 'That link did not return a zip (' . $size . ' bytes).', array( 'status' => 400 ) );
		}
		WP_Filesystem();
		wp_mkdir_p( $dir );
		$res = unzip_file( $tmp, $dir );
		wp_delete_file( $tmp );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
	}
	if ( 'import' === $step || 'fetch' === $step ) {
		$files = is_dir( $dir ) ? array_values( array_filter( scandir( $dir ), function ( $f ) { return preg_match( '/^[a-z0-9-]+\.(jpg|jpeg|png|webp|mp4)$/', $f ); } ) ) : array();
		$todo  = array_values( array_diff( $files, array_keys( $map ) ) );
		$done  = 0;
		$start = microtime( true );
		foreach ( $todo as $f ) {
			if ( 'import' !== $step || microtime( true ) - $start > 20 ) {
				break;
			}
			$copy = wp_tempnam( $f );
			copy( "$dir/$f", $copy );
			$id = media_handle_sideload( array( 'name' => $f, 'tmp_name' => $copy ), (int) $r['post'], preg_replace( '/\.\w+$/', '', $f ) );
			if ( is_wp_error( $id ) ) {
				wp_delete_file( $copy );
				return new WP_Error( 'cc_side', $f . ': ' . $id->get_error_message(), array( 'status' => 500 ) );
			}
			$map[ $f ] = array( 'id' => $id, 'url' => wp_get_attachment_url( $id ) );
			update_option( $opt, $map, false );
			$done++;
		}
		return array( 'files' => count( $files ), 'imported' => count( $map ), 'left' => count( $todo ) - $done, 'map' => $map );
	}
	if ( 'clean' === $step ) {
		WP_Filesystem();
		global $wp_filesystem;
		$wp_filesystem->delete( $dir, true );
		return array( 'cleaned' => true, 'map' => $map );
	}
	return new WP_Error( 'cc_step', 'step must be fetch, import or clean', array( 'status' => 400 ) );
}
