<?php
defined( 'ABSPATH' ) || exit;

/**
 * Phone numbers in one international format (E.164, e.g. +27821234567), so WhatsApp links
 * work and the same person is recognised however they typed their number.
 */
class CC_Fleet_Phone {

	/** Country code => [name, national digits min, max]. South Africa first; the rest are where riders come from. */
	public static function countries() {
		return array(
			'27'  => array( 'South Africa', 9, 9 ),
			'264' => array( 'Namibia', 8, 9 ),
			'267' => array( 'Botswana', 7, 8 ),
			'263' => array( 'Zimbabwe', 9, 9 ),
			'258' => array( 'Mozambique', 8, 9 ),
			'266' => array( 'Lesotho', 8, 8 ),
			'268' => array( 'Eswatini', 8, 8 ),
			'260' => array( 'Zambia', 9, 9 ),
			'254' => array( 'Kenya', 9, 9 ),
			'234' => array( 'Nigeria', 10, 10 ),
			'49'  => array( 'Germany', 6, 13 ),
			'44'  => array( 'United Kingdom', 9, 10 ),
			'31'  => array( 'Netherlands', 9, 9 ),
			'32'  => array( 'Belgium', 8, 9 ),
			'33'  => array( 'France', 9, 9 ),
			'43'  => array( 'Austria', 6, 13 ),
			'41'  => array( 'Switzerland', 9, 9 ),
			'39'  => array( 'Italy', 6, 11 ),
			'34'  => array( 'Spain', 9, 9 ),
			'351' => array( 'Portugal', 9, 9 ),
			'353' => array( 'Ireland', 7, 9 ),
			'45'  => array( 'Denmark', 8, 8 ),
			'46'  => array( 'Sweden', 7, 9 ),
			'47'  => array( 'Norway', 8, 8 ),
			'48'  => array( 'Poland', 9, 9 ),
			'420' => array( 'Czech Republic', 9, 9 ),
			'1'   => array( 'USA / Canada', 10, 10 ),
			'61'  => array( 'Australia', 9, 9 ),
			'64'  => array( 'New Zealand', 8, 10 ),
		);
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
				$pattern = 9 === strlen( $nat ) ? '/^(\d{2})(\d{3})(\d{4})$/' : ( 10 === strlen( $nat ) ? '/^(\d{3})(\d{3})(\d{4})$/' : '/^(\d{3})(\d{3})(\d+)$/' );
				return '+' . $cc . ' ' . trim( preg_replace( $pattern, '$1 $2 $3', $nat ) );
			}
		}
		return $e164;
	}

	/** <select> of country codes, South Africa first. */
	public static function select( $name, $current = '27', $attrs = '' ) {
		$out = '<select name="' . esc_attr( $name ) . '" ' . $attrs . '>';
		foreach ( self::countries() as $cc => $c ) {
			$out .= sprintf( '<option value="%s"%s>+%s %s</option>', esc_attr( $cc ), selected( (string) $current, (string) $cc, false ), esc_html( $cc ), esc_html( $c[0] ) );
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
