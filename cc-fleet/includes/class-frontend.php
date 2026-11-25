<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public output. Server-rendered HTML (fast, cacheable) + one small script that loads live
 * availability and sends requests. Styling is minimal on purpose: the theme does the look.
 *
 * Shortcodes (also usable inside GenerateBlocks / any editor):
 *   [cc_fleet]                         grid of bikes on the website
 *   [cc_bike_calendar bike="CC-12"]    availability calendar (date picker)
 *   [cc_bike_request bike="CC-12"]     calendar + request form. bike="any" asks for a size instead.
 *   [cc_fleet_stat key="loans_this_year|bikes|riders|available"]
 * On a single bike page (/fleet/cc-12/) spec, calendar and form are added automatically.
 */
class CC_Fleet_Frontend {

	private static $assets = false;

	public static function init() {
		add_shortcode( 'cc_fleet', array( __CLASS__, 'sc_fleet' ) );
		add_shortcode( 'cc_bike_calendar', array( __CLASS__, 'sc_calendar' ) );
		add_shortcode( 'cc_bike_request', array( __CLASS__, 'sc_request' ) );
		add_shortcode( 'cc_fleet_stat', array( __CLASS__, 'sc_stat' ) );
		add_filter( 'the_content', array( __CLASS__, 'single_bike' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/** Safe to call at any point: block themes render content before wp_enqueue_scripts. */
	private static function register() {
		if ( ! wp_style_is( 'cc-fleet', 'registered' ) ) {
			wp_register_style( 'cc-fleet', CC_FLEET_URL . 'assets/cc-fleet.css', array(), CC_FLEET_VERSION );
		}
		if ( ! wp_script_is( 'cc-fleet', 'registered' ) ) {
			wp_register_script( 'cc-fleet', CC_FLEET_URL . 'assets/cc-fleet.js', array(), CC_FLEET_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
		}
	}

	/** Config travels on the markup too, so it survives any theme or cache plugin. */
	private static function data_attrs() {
		return sprintf( ' data-cc-api="%s" data-cc-today="%s" data-cc-latest="%s" data-cc-usual="%d"', esc_url( rest_url( CC_Fleet_Rest::NS ) ), esc_attr( CC_Fleet_Settings::today() ), esc_attr( CC_Fleet_Loans::horizon() ), self::usual_days() );
	}

	/** Usual loan length in days (what riders are told is normal). */
	public static function usual_days() {
		return max( 1, (int) CC_Fleet_Settings::get( 'usual_loan_days' ) ?: 7 );
	}

	/** One sentence for the forms and pages. */
	public static function usual_note() {
		return sprintf( 'Loans are usually up to %d days. Need it longer, for a race, a trip or a project? Tell us why in your plan: we make exceptions when there’s a need.', self::usual_days() );
	}

	public static function register_assets() {
		self::register();
		if ( is_singular( CC_Fleet_Bikes::CPT ) || is_page( (int) get_option( 'cc_fleet_page_id' ) ) ) {
			self::enqueue();
		}
	}

	/** For other parts of the plugin (the home page parts) that embed the request form. */
	public static function enqueue_public() {
		self::enqueue();
	}

	private static function enqueue() {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		self::register();
		wp_enqueue_style( 'cc-fleet' );
		wp_enqueue_script( 'cc-fleet' );
		wp_add_inline_script( 'cc-fleet', 'window.CCFleet=' . wp_json_encode( array(
			'api'   => esc_url_raw( rest_url( CC_Fleet_Rest::NS ) ),
			'today'  => CC_Fleet_Settings::today(),
			'latest' => CC_Fleet_Loans::horizon(),
		) ) . ';', 'before' );
	}

	private static function state_label( $s ) {
		return array( 'available' => 'Available', 'out' => 'Out riding', 'workshop' => 'Workshop', 'retired' => 'Retired' )[ $s ] ?? ucfirst( $s );
	}

	/* ---------- [cc_fleet] ---------- */

	public static function sc_fleet( $atts ) {
		self::enqueue();
		$atts = shortcode_atts( array( 'category' => '', 'size' => '' ), $atts );
		$ids  = CC_Fleet_Bikes::public_ids();
		ob_start();
		echo '<div class="cc-fleet" data-cc-fleet' . self::data_attrs() . '>'; // phpcs:ignore
		echo '<div class="cc-fleet__grid">';
		foreach ( $ids as $id ) {
			$b = CC_Fleet_Bikes::public_data( $id );
			if ( ( $atts['category'] && strcasecmp( $b['category'], $atts['category'] ) ) || ( $atts['size'] && $b['size'] !== $atts['size'] ) ) {
				continue;
			}
			printf(
				'<a class="cc-bike-card" href="%1$s" data-bike="%2$s" data-size="%3$s" data-category="%4$s">
					<span class="cc-bike-card__top"><span class="cc-bike-card__id">%10$s</span><span class="cc-state cc-state--%5$s" data-cc-state>%6$s</span></span>
					%7$s
					<span class="cc-bike-card__name">%8$s %3$s</span>
					<span class="cc-bike-card__meta">%9$s · %4$s</span>
				</a>',
				esc_url( $b['url'] ), (int) $b['id'], esc_html( $b['size'] ), esc_html( $b['category'] ),
				esc_attr( $b['state'] ), esc_html( self::state_label( $b['state'] ) ),
				$b['image'] ? '<img class="cc-bike-card__img" src="' . esc_url( $b['image'] ) . '" alt="' . esc_attr( $b['model'] . ' ' . $b['size'] . ( $b['name'] ? ', ' . $b['name'] : '' ) ) . '" loading="lazy" decoding="async" width="600" height="450">'
					: '<span class="cc-bike-card__img cc-bike-card__img--empty" aria-hidden="true">' . esc_html( $b['size'] ) . '</span>',
				esc_html( $b['model'] ), esc_html( $b['colourway'] ), CC_Fleet_Bikes::brand_svg( 'cc-brand cc-bike-card__brand' )
			);
		}
		echo '</div></div>';
		return ob_get_clean();
	}

	/* ---------- calendar + form ---------- */

	public static function sc_calendar( $atts ) {
		self::enqueue();
		$atts = shortcode_atts( array( 'bike' => '' ), $atts );
		return sprintf( '<div class="cc-cal" data-cc-calendar' . self::data_attrs() . ' data-bike="%s" aria-live="polite"><p class="cc-cal__loading">Loading availability…</p></div>', esc_attr( self::bike_ref( $atts['bike'] ) ) );
	}

	/** The bike's post ID for the page (the Bike ID stays internal). Shortcodes may still say bike="CC-12". */
	private static function bike_ref( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref || ctype_digit( $ref ) ) {
			return $ref;
		}
		return (string) ( CC_Fleet_Bikes::find_by_cc_id( $ref ) ?: '' );
	}

	public static function sc_request( $atts ) {
		self::enqueue();
		$atts  = shortcode_atts( array( 'bike' => 'any' ), $atts );
		$any   = 'ANY' === strtoupper( (string) $atts['bike'] );
		$bike  = $any ? 'ANY' : self::bike_ref( $atts['bike'] );
		$s     = CC_Fleet_Settings::all();
		$uid   = 'ccr' . wp_rand( 1000, 9999 );
		$sizes = array_keys( CC_Fleet_Settings::size_heights() );
		ob_start();
		?>
		<form class="cc-request" data-cc-request<?php echo self::data_attrs(); // phpcs:ignore ?> novalidate>
			<?php if ( ! $any ) : ?>
				<input type="hidden" name="bike" value="<?php echo esc_attr( $bike ); ?>">
				<?php echo self::sc_calendar( array( 'bike' => $bike ) ); // phpcs:ignore ?>
			<?php else : ?>
				<input type="hidden" name="bike" value="any">
				<label class="cc-field"><span>Size</span>
					<select name="size" required><option value="">Choose…</option>
						<?php foreach ( $sizes as $sz ) : ?><option value="<?php echo esc_attr( $sz ); ?>"><?php echo esc_html( $sz ); ?></option><?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<div class="cc-request__dates">
				<label class="cc-field"><span>From</span><input type="date" name="start" required data-cc-start></label>
				<label class="cc-field"><span>Until</span><input type="date" name="end" required data-cc-end></label>
			</div>
			<p class="cc-error" data-cc-error="dates" hidden></p>
			<p class="cc-hint cc-usual"><?php echo esc_html( self::usual_note() ); ?></p>
			<p class="cc-long" data-cc-long hidden></p>
			<p class="cc-required-note"><span class="cc-req" aria-hidden="true">*</span> Everything is needed so we can reach you and hand the bike over.</p>
			<div class="cc-who__sum" data-cc-who-sum hidden><p><span class="cc-who__k">Requesting as</span> <b data-cc-who-name></b><small data-cc-who-meta></small></p><button type="button" class="cc-who__edit" data-cc-who-edit>Change</button></div>
			<p class="cc-back" data-cc-back>Ridden with us before? <a href="<?php echo esc_url( class_exists( 'CC_Fleet_Me' ) ? CC_Fleet_Me::page_url() : home_url( '/' ) ); ?>">Email me a link</a> and we’ll fill this in for you.</p>
			<div class="cc-who" data-cc-who>
			<label class="cc-field"><span>Your name <span class="cc-req" aria-hidden="true">*</span></span><input type="text" name="name" autocomplete="name" required aria-required="true"></label>
			<p class="cc-error" data-cc-error="name" hidden></p>
			<label class="cc-field"><span>Email <span class="cc-req" aria-hidden="true">*</span></span><input type="email" name="email" autocomplete="email" inputmode="email" required aria-required="true"></label>
			<p class="cc-error" data-cc-error="email" hidden></p>
			<div class="cc-field cc-field--phone">
				<label for="<?php echo esc_attr( $uid ); ?>-cc"><span>WhatsApp number <span class="cc-req" aria-hidden="true">*</span></span></label>
				<div class="cc-phone">
					<?php echo CC_Fleet_Phone::select( 'phone_cc', '27', 'id="' . esc_attr( $uid ) . '-cc" aria-label="Country code" autocomplete="tel-country-code"' ); // phpcs:ignore ?>
					<input type="tel" name="phone" autocomplete="tel" inputmode="tel" placeholder="82 123 4567" required aria-required="true" aria-label="Phone number">
				</div>
				<small class="cc-hint">We arrange handoffs on WhatsApp, so use the number that has it. From abroad? Pick your country, or type the full number starting with +.</small>
			</div>
			<p class="cc-error" data-cc-error="phone" hidden></p>
			<label class="cc-field"><span>Instagram <span class="cc-req" aria-hidden="true">*</span></span><input type="text" name="instagram" placeholder="@yourname, or none" autocapitalize="off" required aria-required="true"></label>
			<p class="cc-error" data-cc-error="instagram" hidden></p>
			<label class="cc-field"><span>Your height (cm) <span class="cc-req" aria-hidden="true">*</span></span><input type="number" name="height" inputmode="numeric" min="120" max="220" required aria-required="true"></label>
			<p class="cc-error" data-cc-error="height" hidden></p>
			<label class="cc-field"><span>Your weight (kg) <small class="cc-opt">optional</small></span><input type="number" name="weight" inputmode="numeric" min="30" max="200"></label>
			<p class="cc-hint">Only used to set your tyre pressure at handoff.</p>
			<p class="cc-error" data-cc-error="weight" hidden></p>
			</div>
			<fieldset class="cc-field cc-field--wide cc-pedals"><legend>Pedals <span class="cc-req" aria-hidden="true">*</span></legend>
				<label class="cc-check"><input type="radio" name="pedals" value="need" required> <span>I need pedals: Shimano SPD (clip-in)</span></label>
				<label class="cc-check"><input type="radio" name="pedals" value="own"> <span>I’ll bring my own pedals</span></label>
				<small class="cc-hint">We lend Shimano SPD clip-in pedals. Riding flats or another system? Bring your own and we’ll fit them at the handoff.</small>
			</fieldset>
			<p class="cc-error" data-cc-error="pedals" hidden></p>
			<?php $events = self::upcoming_events(); ?>
			<label class="cc-field cc-field--wide"><span>Riding an event?</span>
				<select name="event_id" data-cc-event>
					<option value="">No, just riding</option>
					<?php foreach ( $events as $ev ) : ?><option value="<?php echo (int) $ev->id; ?>" data-race="<?php echo CC_Fleet_Records::is_race( $ev ) ? 1 : 0; ?>" <?php if ( ! CC_Fleet_Records::is_regular( $ev ) ) : ?> data-start="<?php echo esc_attr( $ev->start_date ); ?>" data-end="<?php echo esc_attr( $ev->end_date ?: $ev->start_date ); ?>"<?php endif; ?>><?php echo esc_html( $ev->name . ' · ' . self::event_dates( $ev ) ); ?></option><?php endforeach; ?>
					<option value="other" data-race="1">Another race</option>
					<option value="other-ride" data-race="0">Another ride or event (not a race)</option>
				</select>
				<small class="cc-hint">Raffle bikes for events are handed out by the organisers and aren’t on this list.</small>
			</label>
			<p class="cc-error" data-cc-error="event_id" hidden></p>
			<div class="cc-race" data-cc-race hidden>
				<p class="cc-race__terms"><b>Racing terms.</b> <?php echo esc_html( $s['race_terms'] ); ?></p>
				<label class="cc-check"><input type="checkbox" name="race_agree" value="1"> <span>I’m racing on the bike and accept the racing terms. <span class="cc-req" aria-hidden="true">*</span></span></label>
				<p class="cc-error" data-cc-error="race_agree" hidden></p>
			</div>
			<label class="cc-field cc-field--wide"><span>The plan <span class="cc-req" aria-hidden="true">*</span></span><textarea name="plan" rows="3" placeholder="Gravel ride, bikepacking trip, a Tuesday loop…" required aria-required="true"></textarea></label>
			<p class="cc-error" data-cc-error="plan" hidden></p>
			<div class="cc-agree-box" data-cc-agree-box>
			<label class="cc-check"><input type="checkbox" name="agree" value="1" required aria-required="true"> <span>I'll look after the bike and accept the <?php $ag = CC_Fleet_Landing::agreement_url(); echo $ag ? '<a href="' . esc_url( $ag ) . '" target="_blank" rel="noopener">borrowing agreement</a>' : 'borrowing agreement'; ?>. <span class="cc-req" aria-hidden="true">*</span></span></label>
			<p class="cc-error" data-cc-error="agree" hidden></p>
			<label class="cc-check"><input type="checkbox" name="consent" value="1" required aria-required="true"> <span>Community Connect may store my details to arrange this and future loans, and share my first name and WhatsApp number with the rider I hand the bike to or get it from<?php echo $s['privacy_url'] ? ' (<a href="' . esc_url( $s['privacy_url'] ) . '" target="_blank" rel="noopener">privacy</a>)' : ''; ?>. <span class="cc-req" aria-hidden="true">*</span></span></label>
			<p class="cc-error" data-cc-error="consent" hidden></p>
			</div>
			<p class="cc-agreed" data-cc-agreed hidden></p>
			<div class="cc-hp" aria-hidden="true"><label for="<?php echo esc_attr( $uid ); ?>">Website</label><input id="<?php echo esc_attr( $uid ); ?>" type="text" name="website" tabindex="-1" autocomplete="off"></div>
			<input type="hidden" name="t" value="" data-cc-t>
			<p class="cc-error cc-error--form" data-cc-error="form" hidden></p>
			<label class="cc-check cc-remember" data-cc-remember-row><input type="checkbox" name="remember" value="1" data-cc-remember> <span>Remember my details on this device, so next time is quicker</span></label>
			<div class="cc-request__bar">
				<button type="submit" class="cc-button">Request <?php echo $any ? 'a bike' : 'this bike'; ?></button>
			</div>
			<p class="cc-success" data-cc-success hidden></p>
		</form>
		<?php
		return ob_get_clean();
	}

	/** Events riders can pick on the request form: from today to the booking horizon, not cancelled. */
	public static function upcoming_events() {
		if ( ! class_exists( 'CC_Fleet_Records' ) ) {
			return array();
		}
		$min  = CC_Fleet_Loans::add_days( CC_Fleet_Settings::today(), (int) CC_Fleet_Settings::get( 'min_notice_days' ) );
		$once = array_values( array_filter( CC_Fleet_Records::query( 'events', array( 'from' => $min, 'to' => CC_Fleet_Loans::horizon(), 'order' => 'ASC', 'limit' => 60 ) ), function ( $e ) {
			// Strava events taken off the club calendar are hidden (show_on_site 0) but kept for the records.
			return ! (int) $e->cancelled && ! CC_Fleet_Records::is_regular( $e ) && ! ( 0 === strpos( (string) $e->source, 'strava:' ) && ! (int) $e->show_on_site );
		} ) );
		// Regular rides (“Every Friday at sunrise”) first, then the dated events in order.
		return array_merge( CC_Fleet_Records::regular_events( CC_Fleet_Loans::horizon() ), $once );
	}

	public static function event_dates( $e ) {
		if ( CC_Fleet_Records::is_regular( $e ) ) {
			return (string) $e->schedule;
		}
		$a = strtotime( $e->start_date . ' 12:00' );
		$b = $e->end_date && $e->end_date !== $e->start_date ? strtotime( $e->end_date . ' 12:00' ) : 0;
		if ( ! $b ) {
			return wp_date( 'j M Y', $a );
		}
		return wp_date( wp_date( 'M', $a ) === wp_date( 'M', $b ) ? 'j' : 'j M', $a ) . '–' . wp_date( 'j M Y', $b );
	}

	public static function sc_stat( $atts ) {
		$atts = shortcode_atts( array( 'key' => 'bikes' ), $atts );
		$data = CC_Fleet_Rest::fleet_status()->get_data();
		$val  = 'available' === $atts['key'] ? $data['states']['available'] : ( $data[ $atts['key'] ] ?? '' );
		return '<span class="cc-stat">' . esc_html( (string) $val ) . '</span>';
	}

	/* ---------- single bike page ---------- */

	/** Fallback colours for bikes without a photo yet (same as the home page). */
	const COLOURS = array( 'CC-03' => '#CFC8BB', 'CC-04' => '#BDB8AE', 'CC-05' => '#4E6F3A', 'CC-06' => '#E8C33A', 'CC-07' => '#EF6AA9', 'CC-08' => '#2A2830', 'CC-09' => '#2F5C45', 'CC-11' => '#B9783A' );

	/**
	 * Bike page in the Mobile 2027 layout: breadcrumb, model + name, status, "free to borrow",
	 * the size picker across the fleet, the build, the calendar with the request form, and a
	 * sticky "Request bike" bar on phones.
	 */
	public static function single_bike( $content ) {
		if ( ! is_singular( CC_Fleet_Bikes::CPT ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id = get_the_ID();
		if ( '1' !== CC_Fleet_Bikes::get( $id, 'show_on_site' ) ) {
			return $content;
		}
		if ( ! apply_filters( 'cc_fleet_auto_single', true, $id ) ) {
			return $content;
		}
		self::enqueue();
		$b     = CC_Fleet_Bikes::public_data( $id );
		$range = $b['rider_range'] ? sprintf( '≈ %d–%d cm rider height · we check inseam at handoff', $b['rider_range'][0], $b['rider_range'][1] ) : '';
		$fleet = get_permalink( (int) get_option( 'cc_fleet_page_id' ) ) ?: home_url( '/' );
		$open  = in_array( $b['state'], array( 'available', 'out' ), true );
		// Size picker: every size in the fleet, with the bike(s) in it.
		$sizes = array();
		foreach ( CC_Fleet_Bikes::public_ids() as $other ) {
			$sizes[ CC_Fleet_Bikes::get( $other, 'size' ) ][] = $other;
		}
		ksort( $sizes );
		$photos = $b['photos'];
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && 'community-connect' !== get_template() && has_post_thumbnail( $id ) ) {
			$photos = array(); // Another block theme already shows the featured image.
		}
		$spec = wp_list_pluck( $b['spec'], 'value', 'key' );
		$v    = function ( $k, $suffix = '' ) use ( $spec ) {
			return isset( $spec[ $k ] ) && '' !== $spec[ $k ] ? $spec[ $k ] . $suffix : '';
		};
		$join = function ( $parts ) {
			return implode( ' · ', array_filter( $parts ) );
		};
		// The build, grouped like the design: one summary line each, the details underneath.
		$groups = array(
			array( 'Groupset', $join( array( $v( 'groupset' ), $v( 'chainring' ), $v( 'cassette' ) ) ), array( 'groupset', 'chainring', 'cassette', 'brakes' ), 'gear' ),
			array( 'Wheels', $join( array( $v( 'wheelset' ), $v( 'wheel_size' ) ) ), array( 'wheelset', 'wheel_size', 'tyre_clearance' ), 'wheel' ),
			array( 'Tyres', trim( $v( 'tyres' ) . ' ' . $v( 'tyre_size' ) ), array( 'tyres', 'tyre_size' ), 'tyre' ),
			array( 'Cockpit', $v( 'handlebar' ), array( 'handlebar', 'stem' ), 'bar' ),
			array( 'Saddle', $v( 'saddle' ), array( 'saddle', 'seatpost' ), 'saddle' ),
		);
		$labels = wp_list_pluck( $b['spec'], 'label', 'key' );
		$quick  = array_filter( array(
			'Size'           => $b['size'],
			'Rider height'   => $b['rider_range'] ? $b['rider_range'][0] . '–' . $b['rider_range'][1] . ' cm' : '',
			'Category'       => $b['category'],
			'Groupset'       => $v( 'groupset' ),
			'Wheel size'     => $v( 'wheel_size' ),
			'Tyre clearance' => $v( 'tyre_clearance', ' mm' ),
			'Brakes'         => $v( 'brakes' ),
		) );
		$thumb = $b['photos'] ? wp_get_attachment_image( $b['photos'][0], 'thumbnail', false, array( 'class' => 'cc-stickybar__img', 'alt' => '', 'loading' => 'lazy' ) ) : '';
		ob_start();
		?>
		<div class="cc-bike cc-bike--27">
			<?php if ( $photos ) : ?>
				<div class="cc-gal" data-cc-gal>
					<div class="cc-gal__track">
						<?php foreach ( $photos as $i => $pid ) : ?>
							<figure class="cc-gal__slide"><?php echo wp_get_attachment_image( $pid, 'large', false, array( 'sizes' => '(min-width: 900px) 900px, 100vw', 'loading' => $i ? 'lazy' : 'eager', 'fetchpriority' => $i ? 'auto' : 'high', 'alt' => trim( get_post_meta( $pid, '_wp_attachment_image_alt', true ) ) ?: $b['model'] . ' ' . $b['size'] . ', ' . $b['name'] ) ); ?></figure>
						<?php endforeach; ?>
					</div>
					<?php if ( count( $photos ) > 1 ) : ?>
						<div class="cc-gal__dots"><?php foreach ( $photos as $i => $pid ) : ?><button type="button" aria-label="Photo <?php echo (int) $i + 1; ?>"<?php echo $i ? '' : ' aria-current="true"'; ?>></button><?php endforeach; ?></div>
					<?php endif; ?>
				</div>
			<?php elseif ( ! has_post_thumbnail( $id ) || 'community-connect' === get_template() ) : ?>
				<div class="cc-bike__ph" style="background:<?php echo esc_attr( self::COLOURS[ $b['cc_id'] ] ?? '#D3C8B4' ); ?>"><b><?php echo esc_html( $b['size'] ); ?></b><span>photo to come</span></div>
			<?php endif; ?>
			<nav class="cc-crumbs" aria-label="Breadcrumb"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a> / <a href="<?php echo esc_url( home_url( '/#fleet' ) ); ?>">Fleet</a> / <?php echo esc_html( $b['category'] ); ?> / <?php echo esc_html( $b['model'] . ' ' . $b['size'] ); ?></nav>
			<header class="cc-bike__head">
				<p class="cc-bike__brand"><?php echo CC_Fleet_Bikes::brand_svg( 'cc-brand' ); // phpcs:ignore -- our own SVG file. ?></p>
				<h1 class="cc-bike__model"><?php echo esc_html( mb_strtoupper( $b['model'] . ' ' . $b['size'] ) ); ?></h1>
				<p class="cc-bike__name"><?php echo esc_html( $b['name'] ); ?></p>
				<p class="cc-bike__pills"><span class="cc-state cc-state--<?php echo esc_attr( $b['state'] ); ?>"><?php echo esc_html( self::state_label( $b['state'] ) ); ?></span><?php if ( $b['tag'] ) : ?> <span class="cc-state cc-state--tag"><?php echo esc_html( $b['tag'] ); ?></span><?php endif; ?></p>
				<?php if ( $b['blurb'] ) : ?><p class="cc-bike__blurb"><?php echo esc_html( $b['blurb'] ); ?></p><?php endif; ?>
			</header>
			<?php if ( trim( wp_strip_all_tags( $content ) ) ) : ?><div class="cc-bike__content"><?php echo $content; // phpcs:ignore -- the post's own content. ?></div><?php endif; ?>
			<?php if ( $quick ) : ?>
				<details class="cc-quick">
					<summary>Quick specs</summary>
					<dl><?php foreach ( $quick as $k => $val ) : ?><div><dt><?php echo esc_html( $k ); ?></dt><dd><?php echo esc_html( $val ); ?></dd></div><?php endforeach; ?></dl>
				</details>
			<?php endif; ?>
			<div class="cc-free">
				<p class="cc-free__t">Free to borrow</p>
				<p class="cc-free__s">No fees, no deposit · usually up to <?php echo (int) self::usual_days(); ?> days · <a href="<?php echo esc_url( CC_Fleet_Landing::agreement_url() ?: home_url( '/#agreement' ) ); ?>">borrowing agreement</a> applies</p>
				<?php if ( $open ) : ?><a class="cc-free__btn" href="#request">Check dates</a><?php endif; ?>
			</div>
			<div class="cc-bike__hrow"><h2 class="cc-bike__h">Size <span><?php echo esc_html( $b['size'] ); ?></span></h2><a class="cc-bike__fit" href="<?php echo esc_url( home_url( '/#fleet' ) ); ?>">What’s my size?</a></div>
			<ul class="cc-sizes">
				<?php foreach ( $sizes as $sz => $ids ) : ?>
					<?php $this_one = in_array( $id, $ids, true ); $first = $this_one ? $id : $ids[0]; ?>
					<li><a class="<?php echo $this_one ? 'is-this' : ''; ?>" href="<?php echo esc_url( get_permalink( $first ) ); ?>"<?php echo $this_one ? ' aria-current="page"' : ''; ?>><b><?php echo esc_html( $sz ); ?></b><small><?php echo $this_one ? 'this bike' : ( 1 === count( $ids ) ? esc_html( CC_Fleet_Bikes::public_data( $ids[0] )['name'] ?: CC_Fleet_Bikes::get( $ids[0], 'model' ) ) : count( $ids ) . ' bikes' ); ?></small></a></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $range ) : ?><p class="cc-bike__note"><?php echo esc_html( $range ); ?></p><?php endif; ?>
			<?php if ( array_filter( wp_list_pluck( $groups, 1 ) ) ) : ?>
				<h2 class="cc-bike__h">Build</h2>
				<div class="cc-build">
					<?php foreach ( $groups as $g ) : ?>
						<?php if ( '' === $g[1] ) { continue; } ?>
						<details class="cc-build__row">
							<summary><span class="cc-build__ico cc-build__ico--<?php echo esc_attr( $g[3] ); ?>" aria-hidden="true"></span><span class="cc-build__txt"><small><?php echo esc_html( $g[0] ); ?></small><b><?php echo esc_html( $g[1] ); ?></b></span></summary>
							<dl><?php foreach ( $g[2] as $k ) : ?><?php if ( '' !== $v( $k ) ) : ?><div><dt><?php echo esc_html( $labels[ $k ] ?? $k ); ?></dt><dd><?php echo esc_html( $v( $k ) ); ?></dd></div><?php endif; ?><?php endforeach; ?></dl>
						</details>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( $open ) : ?>
				<h2 class="cc-bike__h" id="request">Availability</h2>
				<p class="cc-bike__note">Tap your first day, then your last. Pink days are taken; we keep one free day between loans. Loans are usually up to <?php echo (int) self::usual_days(); ?> days; ask in your plan if you need longer.</p>
				<?php echo self::sc_request( array( 'bike' => (string) $id ) ); // phpcs:ignore ?>
				<div class="cc-stickybar" data-cc-sticky><?php echo $thumb; // phpcs:ignore ?><span><b>Free · <?php echo esc_html( $b['model'] . ' ' . $b['size'] ); ?></b><small><?php echo esc_html( $b['name'] ); ?></small></span><a href="#request">Request bike</a></div>
			<?php else : ?>
				<p class="cc-bike__note">This bike is in the workshop right now. <a href="<?php echo esc_url( $fleet ); ?>">See the other bikes</a>.</p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

}
