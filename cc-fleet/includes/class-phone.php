<?php
defined( 'ABSPATH' ) || exit;

/**
 * Phone numbers in one international format (E.164, e.g. +27821234567), so WhatsApp links
 * work and the same person is recognised however they typed their number.
 */
class CC_Fleet_Phone {

	/** Every country with its calling code: [name, code], alphabetical. */
	public static function all() {
		return array(
			array( 'Afghanistan', '93' ),
			array( 'Albania', '355' ),
			array( 'Algeria', '213' ),
			array( 'Andorra', '376' ),
			array( 'Angola', '244' ),
			array( 'Anguilla', '1' ),
			array( 'Antigua and Barbuda', '1' ),
			array( 'Argentina', '54' ),
			array( 'Armenia', '374' ),
			array( 'Aruba', '297' ),
			array( 'Australia', '61' ),
			array( 'Austria', '43' ),
			array( 'Azerbaijan', '994' ),
			array( 'Bahamas', '1' ),
			array( 'Bahrain', '973' ),
			array( 'Bangladesh', '880' ),
			array( 'Barbados', '1' ),
			array( 'Belarus', '375' ),
			array( 'Belgium', '32' ),
			array( 'Belize', '501' ),
			array( 'Benin', '229' ),
			array( 'Bermuda', '1' ),
			array( 'Bhutan', '975' ),
			array( 'Bolivia', '591' ),
			array( 'Bosnia and Herzegovina', '387' ),
			array( 'Botswana', '267' ),
			array( 'Brazil', '55' ),
			array( 'Brunei', '673' ),
			array( 'Bulgaria', '359' ),
			array( 'Burkina Faso', '226' ),
			array( 'Burundi', '257' ),
			array( 'Cambodia', '855' ),
			array( 'Cameroon', '237' ),
			array( 'Canada', '1' ),
			array( 'Cape Verde', '238' ),
			array( 'Cayman Islands', '1' ),
			array( 'Central African Republic', '236' ),
			array( 'Chad', '235' ),
			array( 'Chile', '56' ),
			array( 'China', '86' ),
			array( 'Colombia', '57' ),
			array( 'Comoros', '269' ),
			array( 'Congo', '242' ),
			array( 'Congo (DRC)', '243' ),
			array( 'Cook Islands', '682' ),
			array( 'Costa Rica', '506' ),
			array( 'Côte d’Ivoire', '225' ),
			array( 'Croatia', '385' ),
			array( 'Cuba', '53' ),
			array( 'Curaçao', '599' ),
			array( 'Cyprus', '357' ),
			array( 'Czech Republic', '420' ),
			array( 'Denmark', '45' ),
			array( 'Djibouti', '253' ),
			array( 'Dominica', '1' ),
			array( 'Dominican Republic', '1' ),
			array( 'Ecuador', '593' ),
			array( 'Egypt', '20' ),
			array( 'El Salvador', '503' ),
			array( 'Equatorial Guinea', '240' ),
			array( 'Eritrea', '291' ),
			array( 'Estonia', '372' ),
			array( 'Eswatini', '268' ),
			array( 'Ethiopia', '251' ),
			array( 'Falkland Islands', '500' ),
			array( 'Faroe Islands', '298' ),
			array( 'Fiji', '679' ),
			array( 'Finland', '358' ),
			array( 'France', '33' ),
			array( 'French Guiana', '594' ),
			array( 'French Polynesia', '689' ),
			array( 'Gabon', '241' ),
			array( 'Gambia', '220' ),
			array( 'Georgia', '995' ),
			array( 'Germany', '49' ),
			array( 'Ghana', '233' ),
			array( 'Gibraltar', '350' ),
			array( 'Greece', '30' ),
			array( 'Greenland', '299' ),
			array( 'Grenada', '1' ),
			array( 'Guadeloupe', '590' ),
			array( 'Guam', '1' ),
			array( 'Guatemala', '502' ),
			array( 'Guinea', '224' ),
			array( 'Guinea-Bissau', '245' ),
			array( 'Guyana', '592' ),
			array( 'Haiti', '509' ),
			array( 'Honduras', '504' ),
			array( 'Hong Kong', '852' ),
			array( 'Hungary', '36' ),
			array( 'Iceland', '354' ),
			array( 'India', '91' ),
			array( 'Indonesia', '62' ),
			array( 'Iran', '98' ),
			array( 'Iraq', '964' ),
			array( 'Ireland', '353' ),
			array( 'Israel', '972' ),
			array( 'Italy', '39' ),
			array( 'Jamaica', '1' ),
			array( 'Japan', '81' ),
			array( 'Jordan', '962' ),
			array( 'Kazakhstan', '7' ),
			array( 'Kenya', '254' ),
			array( 'Kiribati', '686' ),
			array( 'Kosovo', '383' ),
			array( 'Kuwait', '965' ),
			array( 'Kyrgyzstan', '996' ),
			array( 'Laos', '856' ),
			array( 'Latvia', '371' ),
			array( 'Lebanon', '961' ),
			array( 'Lesotho', '266' ),
			array( 'Liberia', '231' ),
			array( 'Libya', '218' ),
			array( 'Liechtenstein', '423' ),
			array( 'Lithuania', '370' ),
			array( 'Luxembourg', '352' ),
			array( 'Macau', '853' ),
			array( 'Madagascar', '261' ),
			array( 'Malawi', '265' ),
			array( 'Malaysia', '60' ),
			array( 'Maldives', '960' ),
			array( 'Mali', '223' ),
			array( 'Malta', '356' ),
			array( 'Marshall Islands', '692' ),
			array( 'Martinique', '596' ),
			array( 'Mauritania', '222' ),
			array( 'Mauritius', '230' ),
			array( 'Mayotte', '262' ),
			array( 'Mexico', '52' ),
			array( 'Micronesia', '691' ),
			array( 'Moldova', '373' ),
			array( 'Monaco', '377' ),
			array( 'Mongolia', '976' ),
			array( 'Montenegro', '382' ),
			array( 'Montserrat', '1' ),
			array( 'Morocco', '212' ),
			array( 'Mozambique', '258' ),
			array( 'Myanmar', '95' ),
			array( 'Namibia', '264' ),
			array( 'Nauru', '674' ),
			array( 'Nepal', '977' ),
			array( 'Netherlands', '31' ),
			array( 'New Caledonia', '687' ),
			array( 'New Zealand', '64' ),
			array( 'Nicaragua', '505' ),
			array( 'Niger', '227' ),
			array( 'Nigeria', '234' ),
			array( 'North Korea', '850' ),
			array( 'North Macedonia', '389' ),
			array( 'Norway', '47' ),
			array( 'Oman', '968' ),
			array( 'Pakistan', '92' ),
			array( 'Palau', '680' ),
			array( 'Palestine', '970' ),
			array( 'Panama', '507' ),
			array( 'Papua New Guinea', '675' ),
			array( 'Paraguay', '595' ),
			array( 'Peru', '51' ),
			array( 'Philippines', '63' ),
			array( 'Poland', '48' ),
			array( 'Portugal', '351' ),
			array( 'Puerto Rico', '1' ),
			array( 'Qatar', '974' ),
			array( 'Réunion', '262' ),
			array( 'Romania', '40' ),
			array( 'Russia', '7' ),
			array( 'Rwanda', '250' ),
			array( 'Saint Helena', '290' ),
			array( 'Saint Kitts and Nevis', '1' ),
			array( 'Saint Lucia', '1' ),
			array( 'Saint Vincent and the Grenadines', '1' ),
			array( 'Samoa', '685' ),
			array( 'San Marino', '378' ),
			array( 'São Tomé and Príncipe', '239' ),
			array( 'Saudi Arabia', '966' ),
			array( 'Senegal', '221' ),
			array( 'Serbia', '381' ),
			array( 'Seychelles', '248' ),
			array( 'Sierra Leone', '232' ),
			array( 'Singapore', '65' ),
			array( 'Slovakia', '421' ),
			array( 'Slovenia', '386' ),
			array( 'Solomon Islands', '677' ),
			array( 'Somalia', '252' ),
			array( 'South Africa', '27' ),
			array( 'South Korea', '82' ),
			array( 'South Sudan', '211' ),
			array( 'Spain', '34' ),
			array( 'Sri Lanka', '94' ),
			array( 'Sudan', '249' ),
			array( 'Suriname', '597' ),
			array( 'Sweden', '46' ),
			array( 'Switzerland', '41' ),
			array( 'Syria', '963' ),
			array( 'Taiwan', '886' ),
			array( 'Tajikistan', '992' ),
			array( 'Tanzania', '255' ),
			array( 'Thailand', '66' ),
			array( 'Timor-Leste', '670' ),
			array( 'Togo', '228' ),
			array( 'Tonga', '676' ),
			array( 'Trinidad and Tobago', '1' ),
			array( 'Tunisia', '216' ),
			array( 'Turkey', '90' ),
			array( 'Turkmenistan', '993' ),
			array( 'Turks and Caicos Islands', '1' ),
			array( 'Tuvalu', '688' ),
			array( 'Uganda', '256' ),
			array( 'Ukraine', '380' ),
			array( 'United Arab Emirates', '971' ),
			array( 'United Kingdom', '44' ),
			array( 'United States', '1' ),
			array( 'Uruguay', '598' ),
			array( 'Uzbekistan', '998' ),
			array( 'Vanuatu', '678' ),
			array( 'Vatican City', '39' ),
			array( 'Venezuela', '58' ),
			array( 'Vietnam', '84' ),
			array( 'Virgin Islands (UK)', '1' ),
			array( 'Virgin Islands (US)', '1' ),
			array( 'Yemen', '967' ),
			array( 'Zambia', '260' ),
			array( 'Zimbabwe', '263' ),
		);
	}

	/** Exact national-number lengths where we know them; everywhere else 4–14 digits. */
	private static function lengths() {
		return array(
			'27' => array( 9, 9 ), '264' => array( 8, 9 ), '267' => array( 7, 8 ), '263' => array( 9, 9 ), '258' => array( 8, 9 ),
			'266' => array( 8, 8 ), '268' => array( 8, 8 ), '260' => array( 9, 9 ), '254' => array( 9, 9 ), '234' => array( 10, 10 ),
			'49' => array( 6, 13 ), '44' => array( 9, 10 ), '31' => array( 9, 9 ), '32' => array( 8, 9 ), '33' => array( 9, 9 ),
			'43' => array( 6, 13 ), '41' => array( 9, 9 ), '39' => array( 6, 11 ), '34' => array( 9, 9 ), '351' => array( 9, 9 ),
			'353' => array( 7, 9 ), '45' => array( 8, 8 ), '46' => array( 7, 9 ), '47' => array( 8, 8 ), '48' => array( 9, 9 ),
			'420' => array( 9, 9 ), '1' => array( 10, 10 ), '61' => array( 9, 9 ), '64' => array( 8, 10 ), '55' => array( 10, 11 ),
			'54' => array( 10, 11 ), '52' => array( 10, 10 ), '91' => array( 10, 10 ), '86' => array( 11, 11 ), '81' => array( 9, 10 ),
		);
	}

	/** Code => [label, national digits min, max], for every calling code. */
	public static function countries() {
		static $out = null;
		if ( null === $out ) {
			$out = array();
			$len = self::lengths();
			foreach ( self::all() as $c ) {
				list( $name, $code ) = $c;
				if ( isset( $out[ $code ] ) ) {
					$out[ $code ][0] = '1' === $code ? 'US / Canada' : 'valid';
					continue;
				}
				$out[ $code ] = array( $name, $len[ $code ][0] ?? 4, $len[ $code ][1] ?? 14 );
			}
		}
		return $out;
	}

	/**
	 * @param string $raw     What the person typed. A leading + or 00 means it already has a country code.
	 * @param string $cc      Country code picked next to it (digits), default South Africa.
	 * @return string|WP_Error +<digits>, or an error that can be shown to the person.
	 */
	public static function normalize( $raw, $cc = '27' ) {
		$raw = trim( (string) $raw );
		$cc  = preg_replace( '/\D/', '', (string) $cc ) ?: '27';
		if ( '' === $raw ) {
			return new WP_Error( 'cc_phone', 'Please add a phone number.' );
		}
		if ( preg_match( '/[a-z]/i', $raw ) ) {
			return new WP_Error( 'cc_phone', 'Use digits only, e.g. 82 123 4567.' );
		}
		$digits = preg_replace( '/\D/', '', $raw );
		if ( 0 === strpos( $raw, '+' ) || 0 === strpos( $digits, '00' ) ) {
			$full  = ltrim( 0 === strpos( $digits, '00' ) ? substr( $digits, 2 ) : $digits, '0' );
			$known = false;
			foreach ( array( 3, 2, 1 ) as $len ) {
				if ( isset( self::countries()[ substr( $full, 0, $len ) ] ) ) {
					$cc     = substr( $full, 0, $len );
					$digits = substr( $full, $len );
					$known  = true;
					break;
				}
			}
			if ( ! $known ) {
				// A country we don't list: accept any plausible international number.
				return strlen( $full ) >= 8 && strlen( $full ) <= 15 ? '+' . $full : new WP_Error( 'cc_phone', 'That number looks incomplete.' );
			}
			if ( '39' !== $cc ) {
				$digits = ltrim( $digits, '0' ); // +27 082… typed with the trunk zero.
			}
		} else {
			$info = self::countries()[ $cc ] ?? array( '', 6, 13 );
			// Typed with the country code but no +, e.g. 27821234567.
			if ( 0 === strpos( $digits, $cc ) && strlen( $digits ) - strlen( $cc ) >= $info[1] && strlen( $digits ) - strlen( $cc ) <= $info[2] && strlen( $digits ) > $info[2] ) {
				$digits = substr( $digits, strlen( $cc ) );
			}
			// National trunk zero: 082 123 4567 -> 82 123 4567 (Italy keeps it).
			if ( '39' !== $cc ) {
				$digits = ltrim( $digits, '0' );
			}
		}
		$info = self::countries()[ $cc ] ?? array( '', 6, 13 );
		$n    = strlen( $digits );
		if ( $n < $info[1] || $n > $info[2] || strlen( $cc . $digits ) > 15 ) {
			return new WP_Error( 'cc_phone', sprintf( 'That doesn’t look like a %s number. Check the country code and the digits.', $info[0] ?: 'valid' ) );
		}
		return '+' . $cc . $digits;
	}

	/** Lenient version for old data: returns the clean number, or the input unchanged if it can't be read. */
	public static function clean( $raw, $cc = '27' ) {
		$n = self::normalize( $raw, $cc );
		return is_wp_error( $n ) ? trim( (string) $raw ) : $n;
	}

	public static function is_e164( $s ) {
		return (bool) preg_match( '/^\+\d{8,15}$/', (string) $s );
	}

	/** WhatsApp "click to chat": opens the chat if the number has WhatsApp, says so if not. */
	public static function wa_link( $e164, $text = '' ) {
		if ( ! self::is_e164( $e164 ) ) {
			return '';
		}
		return 'https://wa.me/' . ltrim( $e164, '+' ) . ( '' !== $text ? '?text=' . rawurlencode( $text ) : '' );
	}

	/** +27821234567 -> +27 82 123 4567 for reading. */
	public static function pretty( $e164 ) {
		if ( ! self::is_e164( $e164 ) ) {
			return (string) $e164;
		}
		$d = ltrim( $e164, '+' );
		foreach ( array( 3, 2, 1 ) as $len ) {
			if ( isset( self::countries()[ substr( $d, 0, $len ) ] ) ) {
				$cc  = substr( $d, 0, $len );
				$nat = substr( $d, $len );
				$pattern = 11 === strlen( $nat ) ? '/^(\d{2})(\d{5})(\d{4})$/' : ( 9 === strlen( $nat ) ? '/^(\d{2})(\d{3})(\d{4})$/' : ( 10 === strlen( $nat ) ? '/^(\d{3})(\d{3})(\d{4})$/' : '/^(\d{3})(\d{3})(\d+)$/' ) );
				return '+' . $cc . ' ' . trim( preg_replace( $pattern, '$1 $2 $3', $nat ) );
			}
		}
		return $e164;
	}

	/** <select> of every country code: South Africa first, then A–Z. Shows "+55 Brazil" so the code stays visible when narrow. */
	public static function select( $name, $current = '27', $attrs = '' ) {
		$current = (string) $current;
		$opt     = function ( $code, $label, $sel ) {
			return sprintf( '<option value="%s"%s>+%s %s</option>', esc_attr( $code ), $sel ? ' selected' : '', esc_html( $code ), esc_html( $label ) );
		};
		$out  = '<select name="' . esc_attr( $name ) . '" ' . $attrs . '>';
		$out .= $opt( '27', 'South Africa', '27' === $current );
		$out .= '<option disabled>──────────</option>';
		$done = '27' === $current;
		foreach ( self::all() as $c ) {
			if ( 'South Africa' === $c[0] ) {
				continue;
			}
			$sel  = ! $done && $c[1] === $current;
			$done = $done || $sel;
			$out .= $opt( $c[1], $c[0], $sel );
		}
		return $out . '</select>';
	}

	/** Email domain exists and takes mail; catches common typos like gmai.com. */
	public static function email_problem( $email ) {
		if ( ! is_email( $email ) ) {
			return 'Please enter a valid email.';
		}
		$domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );
		$typos  = array( 'gmai.com' => 'gmail.com', 'gmial.com' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gmail.con' => 'gmail.com', 'hotmal.com' => 'hotmail.com', 'hotmail.co' => 'hotmail.com', 'outlok.com' => 'outlook.com', 'yahoo.co' => 'yahoo.com', 'icloud.co' => 'icloud.com', 'iclod.com' => 'icloud.com' );
		if ( isset( $typos[ $domain ] ) ) {
			return 'Did you mean @' . $typos[ $domain ] . '?';
		}
		static $dns_works = null;
		if ( null === $dns_works ) {
			// Only judge domains when the server can resolve a known one; never block people because DNS is down.
			$dns_works = function_exists( 'checkdnsrr' ) && @checkdnsrr( 'gmail.com', 'MX' ); // phpcs:ignore
		}
		if ( $dns_works && apply_filters( 'cc_fleet_check_email_dns', true ) ) {
			if ( ! checkdnsrr( $domain, 'MX' ) && ! checkdnsrr( $domain, 'A' ) ) {
				return 'We can’t find the mail server for @' . $domain . '. Please check the address.';
			}
		}
		return '';
	}
}
