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
		// Only days that have a map line and a profile; anything else would break the drawings.
		$cache[ $id ] = is_array( $d ) ? array_values( array_filter( $d, function ( $x ) {
			return is_array( $x ) && ! empty( $x['line'][1] ) && ! empty( $x['profile'][1] ) && isset( $x['km'], $x['gain'], $x['max'] );
		} ) ) : array();
	}
	return $cache[ $id ];
}

add_action( 'init', function () {
	register_post_meta( CC_PROJECT, '_cc_p_days', array(
		'type' => 'string', 'single' => true, 'show_in_rest' => true,
		'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
	) );
} );

/**
 * Elevation scale for one day, the way Strava does it: fitted to that ride's own lowest and highest
 * point (not to the trip, not from zero), with a little headroom, and never less than 300 m tall so a
 * flat day doesn't look like an Alpine pass. Returns [bottom, top, tick step].
 */
function cc_story_day_scale( $d ) {
	$min  = (float) ( $d['min'] ?? 0 );
	$max  = (float) ( $d['max'] ?? 0 );
	foreach ( (array) ( $d['profile'] ?? array() ) as $q ) {
		$min = min( $min ?: $q[1], $q[1] );
		$max = max( $max, $q[1] );
	}
	$span = max( 300, $max - $min );
	$mid  = ( $max + $min ) / 2;
	$lo   = $mid - $span / 2 - $span * .08;
	$hi   = $mid + $span / 2 + $span * .14;
	$step = 100;
	foreach ( array( 50, 100, 200, 250, 500, 1000 ) as $st ) {
		if ( ( $hi - $lo ) / $st <= 4 ) {
			$step = $st;
			break;
		}
	}
	return array( floor( $lo / 10 ) * 10, ceil( $hi / 10 ) * 10, $step );
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
	list( $lo, $hi, $step ) = cc_story_day_scale( $d );
	$p            = $d['profile'];
	$kmax         = max( 0.1, end( $p )[0] );
	$X            = function ( $k ) use ( $kmax ) { return round( $k / $kmax * 1000, 1 ); };
	$Y            = function ( $e ) use ( $lo, $hi ) { return round( 200 - ( $e - $lo ) / ( $hi - $lo ) * 200, 1 ); };
	$line         = '';
	$pk           = $p[0];
	foreach ( $p as $i => $q ) {
		$line .= ( $i ? 'L' : 'M' ) . $X( $q[0] ) . ' ' . $Y( $q[1] );
		if ( $q[1] > $pk[1] ) {
			$pk = $q;
		}
	}
	// Height lines, labelled, so each day's own scale reads honestly.
	$grid = $ticks = '';
	for ( $e = ceil( $lo / $step ) * $step; $e < $hi; $e += $step ) {
		$y      = $Y( $e );
		$grid  .= '<line x1="0" x2="1000" y1="' . $y . '" y2="' . $y . '" class="g"/>';
		$ticks .= '<span style="top:' . round( $y / 2, 2 ) . '%">' . cc_story_n( $e ) . '</span>';
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
		. '<div class="cc-sday__viz"><div class="cc-sday__pw"><svg class="cc-sday__prof" viewBox="0 0 1000 200" preserveAspectRatio="none" role="img" aria-label="Elevation profile: ' . esc_attr( cc_story_n( $p[0][1] ) ) . ' m to ' . esc_attr( cc_story_n( end( $p )[1] ) ) . ' m, high point ' . esc_attr( cc_story_n( $d['max'] ) ) . ' m">'
		. $grid . '<path d="' . $line . 'L1000 200L0 200Z" fill="#D3C8B4" fill-opacity=".85"/><path d="' . $line . '" fill="none" stroke="#141217" stroke-width="2.5" vector-effect="non-scaling-stroke"/>'
		. '<line x1="' . $X( $pk[0] ) . '" x2="' . $X( $pk[0] ) . '" y1="' . $Y( $pk[1] ) . '" y2="200" stroke="#A56C12" stroke-width="1.5" vector-effect="non-scaling-stroke"/></svg><p class="cc-sday__yt" aria-hidden="true">' . $ticks . '</p></div>'
		. '<svg class="cc-sday__shape" viewBox="0 0 120 120" role="img" aria-label="Shape of the day\'s route"><path d="' . $rt . '" fill="none" stroke="#141217" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/><circle cx="' . $a0[0] . '" cy="' . $a0[1] . '" r="4" fill="#F4EEE4" stroke="#141217" stroke-width="2"/><circle cx="' . $a1[0] . '" cy="' . $a1[1] . '" r="4" fill="#141217"/></svg></div>'
		. '<p class="cc-sday__ax"><span>0 km · ' . cc_story_n( $p[0][1] ) . ' m</span><span>Height in m · scaled to this day</span><span>' . round( $d['km'] ) . ' km · ' . cc_story_n( end( $p )[1] ) . ' m</span></p>'
		. '</div></section>';
} );

/* ---------- [cc_story_route] ---------- */

/** Country, province and river outlines (Natural Earth, public domain), simplified to ~1 km. */
function cc_story_geo() {
	static $g = null;
	if ( null === $g ) {
		$g = json_decode( (string) file_get_contents( get_theme_file_path( 'assets/geo/southern-africa.json' ) ), true ) ?: array();
	}
	return $g;
}

/** Point [lon, lat] inside a ring of [lon, lat]? */
function cc_story_inside( $x, $y, $ring ) {
	$in = false;
	for ( $i = 0, $j = count( $ring ) - 1, $n = count( $ring ); $i < $n; $j = $i++ ) {
		if ( ( $ring[ $i ][1] > $y ) !== ( $ring[ $j ][1] > $y ) && $x < ( $ring[ $j ][0] - $ring[ $i ][0] ) * ( $y - $ring[ $i ][1] ) / ( $ring[ $j ][1] - $ring[ $i ][1] ) + $ring[ $i ][0] ) {
			$in = ! $in;
		}
	}
	return $in;
}

function cc_story_country_at( $lat, $lon, $countries ) {
	// Smallest first: Lesotho sits inside South Africa's outline, so the enclave must win.
	static $sorted = null;
	if ( null === $sorted ) {
		$sorted = $countries;
		$area   = function ( $c ) {
			$a = 0;
			foreach ( $c['r'] as $ring ) {
				$xs = array_column( $ring, 0 );
				$ys = array_column( $ring, 1 );
				$a += ( max( $xs ) - min( $xs ) ) * ( max( $ys ) - min( $ys ) );
			}
			return $a;
		};
		usort( $sorted, function ( $x, $y ) use ( $area ) { return $area( $x ) <=> $area( $y ); } );
	}
	foreach ( $sorted as $c ) {
		foreach ( $c['r'] as $ring ) {
			if ( cc_story_inside( $lon, $lat, $ring ) ) {
				return $c['iso'];
			}
		}
	}
	return '';
}

add_shortcode( 'cc_story_route', function ( $atts ) {
	$a    = shortcode_atts( array( 'peak' => '' ), $atts );
	$days = cc_story_days();
	if ( ! $days ) {
		return '';
	}
	$geo  = cc_story_geo();
	$ctry = $geo['countries'] ?? array();

	// Which countries does the ride pass through, and where does it cross a border?
	$seq = array();
	foreach ( $days as $i => $d ) {
		foreach ( $d['line'] as $j => $q ) {
			$seq[] = array( $q, cc_story_country_at( $q[0], $q[1], $ctry ), $i );
		}
	}
	$home    = $seq[0][1];
	$visited = array();
	$cross   = array();
	$prev    = $home;
	foreach ( $seq as $k => $pt ) {
		if ( '' === $pt[1] ) {
			continue;
		}
		$visited[ $pt[1] ] = true;
		if ( $pt[1] !== $prev ) {
			$cross[] = array( $seq[ $k - 1 ][0], $pt[0], $pt[2], $pt[1], $prev );
			$prev    = $pt[1];
		}
	}
	$focus = array_diff( array_keys( $visited ), array( $home ) );

	// Frame: the route plus every country it enters (so you see it was crossed), with a margin.
	$lo_x = $lo_y = INF;
	$hi_x = $hi_y = -INF;
	$grow = function ( $lon, $lat ) use ( &$lo_x, &$lo_y, &$hi_x, &$hi_y ) {
		$lo_x = min( $lo_x, $lon );
		$hi_x = max( $hi_x, $lon );
		$lo_y = min( $lo_y, $lat );
		$hi_y = max( $hi_y, $lat );
	};
	foreach ( $seq as $pt ) {
		$grow( $pt[0][1], $pt[0][0] );
	}
	foreach ( $ctry as $c ) {
		if ( in_array( $c['iso'], $focus, true ) ) {
			foreach ( $c['r'] as $ring ) {
				foreach ( $ring as $q ) {
					$grow( $q[0], $q[1] );
				}
			}
		}
	}
	$mx   = ( $hi_x - $lo_x ) * .08;
	$my   = ( $hi_y - $lo_y ) * .14;
	$lo_x -= $mx;
	$hi_x += $mx;
	$lo_y -= $my;
	$hi_y += $my;
	$k  = cos( deg2rad( ( $lo_y + $hi_y ) / 2 ) );
	$W  = 1000;
	$s  = $W / ( ( $hi_x - $lo_x ) * $k );
	$H  = (int) ceil( ( $hi_y - $lo_y ) * $s );
	$P  = function ( $lon, $lat ) use ( $lo_x, $hi_y, $k, $s ) { return array( round( ( $lon - $lo_x ) * $k * $s, 1 ), round( ( $hi_y - $lat ) * $s, 1 ) ); };
	$T  = function ( $q ) use ( $P ) { return $P( $q[1], $q[0] ); }; // route points are [lat, lon]
	$ring_d = function ( $ring, $close = true ) use ( $P ) {
		$d = '';
		foreach ( $ring as $i => $q ) {
			$t  = $P( $q[0], $q[1] );
			$d .= ( $i ? 'L' : 'M' ) . $t[0] . ' ' . $t[1];
		}
		return $d . ( $close ? 'Z' : '' );
	};
	$inframe = function ( $lon, $lat ) use ( $lo_x, $hi_x, $lo_y, $hi_y ) { return $lon > $lo_x && $lon < $hi_x && $lat > $lo_y && $lat < $hi_y; };

	// Base map.
	$base = $labels = '';
	foreach ( $geo['regions'] ?? array() as $r ) {
		foreach ( $r['r'] as $ring ) {
			$base .= '<path class="cc-smap__prov" d="' . $ring_d( $ring ) . '"/>';
		}
	}
	foreach ( $geo['rivers'] ?? array() as $r ) {
		foreach ( $r['r'] as $ring ) {
			$base .= '<path class="cc-smap__river" d="' . $ring_d( $ring, false ) . '"/>';
		}
	}
	foreach ( $ctry as $c ) {
		$f = in_array( $c['iso'], $focus, true );
		foreach ( $c['r'] as $ring ) {
			$base .= '<path class="cc-smap__ctry' . ( $f ? ' is-focus' : '' ) . '" d="' . $ring_d( $ring ) . '"/>';
		}
	}
	foreach ( $geo['regions'] ?? array() as $r ) {
		if ( $inframe( $r['c'][0], $r['c'][1] ) ) {
			$t       = $P( $r['c'][0], $r['c'][1] );
			$labels .= '<text x="' . $t[0] . '" y="' . $t[1] . '" text-anchor="middle" class="cc-smap__reg">' . esc_html( strtoupper( $r['n'] ) ) . '</text>';
		}
	}

	// Route, towns, crossings, peak.
	$paths = $towns = $list = $marks = '';
	$hiDay = 0;
	foreach ( $days as $i => $d ) {
		$dd = '';
		foreach ( $d['line'] as $j => $q ) {
			$t   = $T( $q );
			$dd .= ( $j ? 'L' : 'M' ) . $t[0] . ' ' . $t[1];
		}
		$paths .= '<path class="cc-sroute__halo" d="' . $dd . '"/><path class="cc-sroute__p" data-day="' . ( $i + 1 ) . '" d="' . $dd . '"/>';
		$list  .= '<li><a href="#day-' . ( $i + 1 ) . '" data-day="' . ( $i + 1 ) . '" data-sel="' . esc_attr( '[Day ' . sprintf( '%02d', $i + 1 ) . ' · ' . ( $d['date'] ?? '' ) . ']|' . ( $d['start'] ?? '' ) . ' → ' . ( $d['end'] ?? '' ) . '|' . round( $d['km'] ) . ' km · ' . cc_story_n( $d['gain'] ) . ' m up · high ' . cc_story_n( $d['max'] ) . ' m' ) . '"><span>' . sprintf( '%02d', $i + 1 ) . '</span><b>' . esc_html( $d['title'] ?? '' ) . '</b><span>' . round( $d['km'] ) . ' km</span></a></li>';
		if ( $d['max'] > ( $days[ $hiDay ]['max'] ?? 0 ) ) {
			$hiDay = $i;
		}
	}
	// Label placement: every label tries 8 spots around its point and takes the one that covers the
	// fewest route points and earlier labels.
	$occ = array();
	foreach ( $seq as $pt ) {
		$u     = $T( $pt[0] );
		$occ[] = array( $u[0] - 3, $u[1] - 3, $u[0] + 3, $u[1] + 3 );
	}
	$hits  = function ( $bx ) use ( &$occ ) {
		$n = 0;
		foreach ( $occ as $o ) {
			if ( $bx[0] < $o[2] && $bx[2] > $o[0] && $bx[1] < $o[3] && $bx[3] > $o[1] ) {
				$n++;
			}
		}
		return $n;
	};
	$place = function ( $x, $y, $text, $cw = 7.9, $h = 14, $gap = 10 ) use ( &$occ, $hits, $W, $H ) {
		$w    = strlen( $text ) * $cw;
		$best = null;
		foreach ( array( array( 1, -1 ), array( 1, 1 ), array( -1, -1 ), array( -1, 1 ), array( 0, -1 ), array( 0, 1 ), array( 1, 0 ), array( -1, 0 ) ) as $o ) {
			$lx = 0 === $o[0] ? $x - $w / 2 : ( $o[0] > 0 ? $x + $gap : $x - $gap - $w );
			$ly = 0 === $o[1] ? $y - $h / 2 : ( $o[1] > 0 ? $y + $gap : $y - $gap - $h );
			$bx = array( $lx, $ly, $lx + $w, $ly + $h );
			$sc = $hits( $bx ) * 10 + ( $lx < 4 || $lx + $w > $W - 4 || $ly < 40 || $ly + $h > $H - 40 ? 50 : 0 ) + ( 0 === $o[0] || 0 === $o[1] ? 1 : 0 );
			if ( null === $best || $sc < $best[0] ) {
				$best = array( $sc, $bx );
			}
		}
		$occ[] = array( $best[1][0] - 4, $best[1][1] - 4, $best[1][2] + 4, $best[1][3] + 4 );
		return array( round( $best[1][0], 1 ), round( $best[1][3] - 3, 1 ) ); // text x (start), baseline y
	};

	$stops   = array_map( function ( $d ) { return array( $d['line'][0], $d['start'] ?? '' ); }, $days );
	$last    = end( $days );
	$stops[] = array( end( $last['line'] ), $last['end'] ?? '' );
	$seen    = array();
	$tpts    = array();
	foreach ( $stops as $st ) {
		if ( isset( $seen[ $st[1] ] ) ) {
			continue;
		}
		$seen[ $st[1] ] = 1;
		$t      = $T( $st[0] );
		$tpts[] = array( $t, $st[1] );
		$occ[]  = array( $t[0] - 7, $t[1] - 7, $t[0] + 7, $t[1] + 7 );
		$towns .= '<circle cx="' . $t[0] . '" cy="' . $t[1] . '" r="5" class="cc-sroute__town"/>';
	}
	$xpts = array();
	foreach ( $cross as $c ) {
		$t      = $T( array( ( $c[0][0] + $c[1][0] ) / 2, ( $c[0][1] + $c[1][1] ) / 2 ) );
		$xpts[] = array( $t, $c );
		$occ[]  = array( $t[0] - 8, $t[1] - 8, $t[0] + 8, $t[1] + 8 );
		$marks .= '<rect class="cc-smap__xb" x="' . ( $t[0] - 6 ) . '" y="' . ( $t[1] - 6 ) . '" width="12" height="12"/>';
	}
	$hd   = $days[ $hiDay ];
	$pt   = $T( $hd['maxAt'] ?? $hd['line'][0] );
	$peak = $a['peak'] ?: cc_story_n( $hd['max'] ) . ' m';
	$occ[] = array( $pt[0] - 8, $pt[1] - 18, $pt[0] + 8, $pt[1] );
	$marks .= '<path d="M' . $pt[0] . ' ' . ( $pt[1] - 4 ) . ' l-7 -12 h14 z" fill="#141217"/>';
	// Peak note first (biggest), then towns, then crossings.
	$pl     = $place( $pt[0], $pt[1] - 10, $peak, 10.5, 22, 8 );
	$marks .= '<text x="' . $pl[0] . '" y="' . $pl[1] . '" class="cc-sroute__peak">' . esc_html( $peak ) . '</text>';
	foreach ( $tpts as $tp ) {
		$pl     = $place( $tp[0][0], $tp[0][1], strtoupper( $tp[1] ) );
		$towns .= '<text x="' . $pl[0] . '" y="' . $pl[1] . '" class="cc-sroute__lbl">' . esc_html( strtoupper( $tp[1] ) ) . '</text>';
	}
	foreach ( $xpts as $xp ) {
		$into   = in_array( $xp[1][3], $focus, true );
		$txt    = '[' . ( $into ? 'IN' : 'OUT' ) . ' · DAY ' . sprintf( '%02d', $xp[1][2] + 1 ) . ']';
		$pl     = $place( $xp[0][0], $xp[0][1], $txt, 7.3, 13 );
		$marks .= '<text x="' . $pl[0] . '" y="' . $pl[1] . '" class="cc-smap__xt">' . esc_html( $txt ) . '</text>';
	}
	$names = array();
	foreach ( $ctry as $c ) {
		$names[ $c['iso'] ] = $c['n'];
	}
	// The crossed country's name: the emptiest spot inside it.
	foreach ( $ctry as $c ) {
		if ( ! in_array( $c['iso'], $focus, true ) ) {
			continue;
		}
		$nm   = strtoupper( $c['n'] );
		$bw   = strlen( $nm ) * 36;
		$best = null;
		foreach ( $c['r'] as $ring ) {
			$xs = array_column( $ring, 0 );
			$ys = array_column( $ring, 1 );
			for ( $gx = 1; $gx < 14; $gx++ ) {
				for ( $gy = 1; $gy < 14; $gy++ ) {
					$lon = min( $xs ) + ( max( $xs ) - min( $xs ) ) * $gx / 14;
					$lat = min( $ys ) + ( max( $ys ) - min( $ys ) ) * $gy / 14;
					if ( ! cc_story_inside( $lon, $lat, $ring ) ) {
						continue;
					}
					$t  = $P( $lon, $lat );
					$bx = array( $t[0] - $bw / 2, $t[1] - 34, $t[0] + $bw / 2, $t[1] + 4 );
					$sc = $hits( $bx ) + ( $bx[0] < 0 || $bx[2] > $W ? 99 : 0 );
					// Keep the word inside the country: corners outside cost.
					foreach ( array( array( $bx[0], $bx[1] ), array( $bx[2], $bx[1] ), array( $bx[0], $bx[3] ), array( $bx[2], $bx[3] ) ) as $cn ) {
						$sc += cc_story_inside( $lo_x + $cn[0] / ( $k * $s ), $hi_y - $cn[1] / $s, $ring ) ? 0 : 3;
					}
					if ( null === $best || $sc < $best[0] ) {
						$best = array( $sc, $t );
					}
				}
			}
		}
		if ( $best ) {
			$labels .= '<text x="' . $best[1][0] . '" y="' . $best[1][1] . '" text-anchor="middle" class="cc-smap__big">' . esc_html( $nm ) . '</text>';
		}
	}
	$km50 = 50 / ( 111.32 * $k ) * $k * $s; // 50 km in px
	$tk   = array_sum( array_column( $days, 'km' ) );
	$tg   = array_sum( array_column( $days, 'gain' ) );
	$first = $days[0]['start'] ?? '';
	$all   = '[The whole route · ' . count( $days ) . ' days]|' . $first . ' → ' . ( $last['end'] ?? '' ) . '|' . cc_story_n( $tk ) . ' km · ' . cc_story_n( $tg ) . ' m up';
	$sel   = explode( '|', $all );
	$nb    = count( $cross );

	// The whole route as one height profile, day by day (scaled to the trip's own range, like Strava does per activity).
	$all_p = array();
	$off   = 0;
	foreach ( $days as $i => $d ) {
		foreach ( $d['profile'] as $q ) {
			$all_p[] = array( $off + $q[0], $q[1], $i );
		}
		$off += end( $d['profile'] )[0];
	}
	list( $elo, $ehi, $estep ) = cc_story_day_scale( array( 'profile' => array_map( function ( $q ) { return array( $q[0], $q[1] ); }, $all_p ) ) );
	$EX    = function ( $km ) use ( $off ) { return round( $km / max( 1, $off ) * 1000, 1 ); };
	$EY    = function ( $e ) use ( $elo, $ehi ) { return round( 160 - ( $e - $elo ) / ( $ehi - $elo ) * 160, 1 ); };
	$segs  = $seps = $dlab = $grid = $ticks = '';
	$top   = array( 0, 0 );
	foreach ( $days as $i => $d ) {
		$pts = array_values( array_filter( $all_p, function ( $q ) use ( $i ) { return $q[2] === $i; } ) );
		$ln  = '';
		foreach ( $pts as $j => $q ) {
			$ln .= ( $j ? 'L' : 'M' ) . $EX( $q[0] ) . ' ' . $EY( $q[1] );
			if ( $q[1] > $top[1] ) {
				$top = array( $q[0], $q[1] );
			}
		}
		$x0    = $EX( $pts[0][0] );
		$x1    = $EX( end( $pts )[0] );
		$segs .= '<path class="cc-sroute__e" data-day="' . ( $i + 1 ) . '" d="' . $ln . 'L' . $x1 . ' 160L' . $x0 . ' 160Z"/>';
		$seps .= $i ? '<line x1="' . $x0 . '" x2="' . $x0 . '" y1="0" y2="160" class="s"/>' : '';
		$dlab .= '<a href="#day-' . ( $i + 1 ) . '" data-day="' . ( $i + 1 ) . '" style="left:' . round( $x0 / 10, 2 ) . '%;width:' . round( ( $x1 - $x0 ) / 10, 2 ) . '%" aria-label="' . esc_attr( 'Day ' . ( $i + 1 ) . ': ' . ( $d['title'] ?? '' ) ) . '">' . sprintf( '%02d', $i + 1 ) . '</a>';
	}
	$eline = '';
	foreach ( $all_p as $j => $q ) {
		$eline .= ( $j ? 'L' : 'M' ) . $EX( $q[0] ) . ' ' . $EY( $q[1] );
	}
	for ( $e = ceil( $elo / $estep ) * $estep; $e < $ehi; $e += $estep ) {
		$grid  .= '<line x1="0" x2="1000" y1="' . $EY( $e ) . '" y2="' . $EY( $e ) . '" class="g"/>';
		$ticks .= '<span style="top:' . round( $EY( $e ) / 1.6, 2 ) . '%">' . cc_story_n( $e ) . '</span>';
	}
	$elev = '<div class="cc-sroute__elev"><p class="cc-sroute__elab"><span>[Height · the whole route]</span><span>' . cc_story_n( $off ) . ' km · high ' . esc_html( $a['peak'] ?: cc_story_n( $top[1] ) . ' m' ) . '</span></p>'
		. '<div class="cc-sroute__ew"><svg viewBox="0 0 1000 160" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( 'Height profile of the whole route, ' . cc_story_n( $off ) . ' km, from ' . cc_story_n( $all_p[0][1] ) . ' m to a high point of ' . cc_story_n( $top[1] ) . ' m' ) . '">'
		. $grid . $segs . '<path d="' . $eline . '" fill="none" stroke="#141217" stroke-width="2" vector-effect="non-scaling-stroke"/>' . $seps
		. '<line x1="' . $EX( $top[0] ) . '" x2="' . $EX( $top[0] ) . '" y1="' . $EY( $top[1] ) . '" y2="160" stroke="#141217" stroke-width="1.5" stroke-dasharray="3 3" vector-effect="non-scaling-stroke"/></svg>'
		. '<p class="cc-sday__yt" aria-hidden="true">' . $ticks . '</p></div><nav class="cc-sroute__days" aria-label="Days">' . $dlab . '</nav></div>';

	return '<section class="cc-sroute" aria-label="The route"><div class="cc-sroute__map"><svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . esc_attr( 'Map: the ' . count( $days ) . ' days of the route from ' . $first . ' to ' . ( $last['end'] ?? '' ) . ( $focus ? ', across ' . implode( ', ', array_map( function ( $iso ) use ( $names ) { return $names[ $iso ] ?? $iso; }, $focus ) ) : '' ) ) . '">'
		. '<defs><pattern id="cc-hatch" width="7" height="7" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><line x1="0" y1="0" x2="0" y2="7" stroke="#141217" stroke-width="1.1"/></pattern></defs>'
		. '<g>' . $base . $labels . $paths . $marks . $towns
		. '</g>'
		. '</svg><p class="cc-sroute__mcap">[Route · ' . count( $days ) . ' GPX tracks' . ( $nb ? ' · ' . $nb . ' border crossings' : '' ) . ' · N ↑]</p>'
		. '<p class="cc-sroute__scale" data-px="' . round( $km50, 1 ) . '" data-w="' . $W . '"><i style="width:' . round( $km50 / $W * 100, 2 ) . '%"></i><span>0</span><span>50 km</span></p>'
		. '</div><div class="cc-sroute__side"><div class="cc-sroute__sel" data-all="' . esc_attr( $all ) . '"><span>' . esc_html( $sel[0] ) . '</span><b>' . esc_html( $sel[1] ) . '</b><span>' . esc_html( $sel[2] ) . '</span></div>'
		. '<ol class="cc-sroute__list">' . $list . '</ol><p class="cc-sroute__note">Planned route from GPX. Hatched: the country we crossed. Point at a day to light it up; tap to jump to it.</p></div>' . $elev . '</section>';
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
		$rail = array( 'days' => array_map( function ( $d ) {
			list( $lo, $hi ) = cc_story_day_scale( $d );
			$p = array();
			foreach ( $d['profile'] as $i => $q ) {
				if ( 0 === $i % 3 || $i === count( $d['profile'] ) - 1 ) {
					$p[] = $q;
				}
			}
			return array( 't' => $d['title'] ?? '', 'km' => $d['km'], 'p' => $p, 'lo' => $lo, 'hi' => $hi );
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
    var p=rm.querySelector('.cc-sroute__p.on');if(p)p.parentNode.insertBefore(p,p.parentNode.querySelector('.cc-smap__x')||p.parentNode.querySelector('.cc-sroute__town'));}
  rm.querySelectorAll('.cc-sroute__list a').forEach(function(a){a.addEventListener('mouseenter',function(){show(a.dataset.day,a.dataset.sel);});a.addEventListener('focus',function(){show(a.dataset.day,a.dataset.sel);});});
  rm.querySelectorAll('.cc-sroute__e,.cc-sroute__days a').forEach(function(p){p.addEventListener('mouseenter',function(){var a=rm.querySelector('.cc-sroute__list a[data-day="'+p.dataset.day+'"]');show(p.dataset.day,a&&a.dataset.sel);});
    if(p.tagName==='path')p.addEventListener('click',function(){location.hash='day-'+p.dataset.day;});});
  rm.querySelectorAll('.cc-sroute__p').forEach(function(p){p.addEventListener('mouseenter',function(){var a=rm.querySelector('.cc-sroute__list a[data-day="'+p.dataset.day+'"]');show(p.dataset.day,a&&a.dataset.sel);});
    p.addEventListener('click',function(){location.hash='day-'+p.dataset.day;});});
  rm.addEventListener('mouseleave',function(){show(0);});
  var sc=rm.querySelector('.cc-sroute__scale'),sv=rm.querySelector('.cc-sroute__map svg');
  function bar(){if(!sc||!sv)return;var b=sv.getBoundingClientRect(),vb=sv.viewBox.baseVal,k=Math.min(b.width/vb.width,b.height/vb.height);sc.querySelector('i').style.width=(+sc.dataset.px*k).toFixed(1)+'px';}
  bar();addEventListener('resize',bar);
}
/* Rail: which day, how far into it. */
var heads=[].slice.call(document.querySelectorAll('.cc-sday'));
if(!data||!heads.length)return;
var end=document.querySelector('.cc-pbody'),N=data.days.length;
var r=document.createElement('aside');r.className='cc-srail';r.setAttribute('aria-label','Where you are in the story');
var ticks='';for(var i=1;i<=N;i++)ticks+='<a href="#day-'+i+'" aria-label="Day '+i+': '+data.days[i-1].t.replace(/"/g,'&quot;')+'"></a>';
r.innerHTML='<p class="cc-srail__k">[Day <span class="n1">01</span> / '+('0'+N).slice(-2)+']</p><b class="cc-srail__n">01</b><p class="cc-srail__t"></p>'
 +'<svg class="cc-srail__p" viewBox="0 0 200 90" preserveAspectRatio="none" aria-hidden="true"><path class="a" fill="#D3C8B4"/><path class="l" fill="none" stroke="#141217" stroke-width="1.6" vector-effect="non-scaling-stroke"/><line class="m" y1="0" y2="90" stroke="#FFB1EC" stroke-width="3" vector-effect="non-scaling-stroke"/></svg>'
 +'<p class="cc-srail__km"></p><nav class="cc-srail__ticks">'+ticks+'</nav><span class="cc-srail__bar"><i></i></span>';
document.body.appendChild(r);
var cur=-1,el={n:r.querySelector('.cc-srail__n'),n1:r.querySelector('.n1'),t:r.querySelector('.cc-srail__t'),a:r.querySelector('.a'),l:r.querySelector('.l'),m:r.querySelector('.m'),km:r.querySelector('.cc-srail__km'),bar:r.querySelector('.cc-srail__bar i'),ticks:r.querySelectorAll('.cc-srail__ticks a')};
function draw(i){var d=data.days[i],P=d.p,lo=d.lo,hi=d.hi,K=P[P.length-1][0]||1,s='';P.forEach(function(q,j){s+=(j?'L':'M')+(q[0]/K*200).toFixed(1)+' '+(90-(q[1]-lo)/(hi-lo)*86).toFixed(1);});
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
