<?php
/**
 * PHPUnit bootstrap.
 *
 * @package SugarCommerce\Dates
 */

declare( strict_types=1 );

/**
 * Stubbed options.
 *
 * @var array<string, mixed>
 */
$GLOBALS['du_options'] = array(
	'date_format' => 'F j, Y',
	'time_format' => 'g:i a',
	'timezone'    => 'Europe/London',
);

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * Core's date formatter: a real timestamp, shown in a timezone.
	 *
	 * No translating here either; what matters is that the moment is not
	 * shifted by hand and the zone is the site's unless one is given.
	 *
	 * @param string            $format    Date format.
	 * @param int|null          $timestamp Unix time.
	 * @param DateTimeZone|null $timezone  Zone, or null for the site's.
	 *
	 * @return string
	 */
	function wp_date( string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null ) {
		return ( new DateTimeImmutable( '@' . ( $timestamp ?? time() ) ) )
			->setTimezone( $timezone ?? wp_timezone() )
			->format( $format );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Core's translator, translating nothing.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 *
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Core's plural chooser, with English's one rule.
	 *
	 * @param string $single One.
	 * @param string $plural Any other number.
	 * @param int    $number The number.
	 * @param string $domain Text domain.
	 *
	 * @return string
	 */
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * Core's number formatter, in English.
	 *
	 * @param float $number   The number.
	 * @param int   $decimals Decimal places.
	 *
	 * @return string
	 */
	function number_format_i18n( $number, int $decimals = 0 ): string {
		return number_format( (float) $number, $decimals );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Core's escaping for text.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Core's escaping for an attribute.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Read a stubbed option.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Fallback.
	 *
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) {
		return $GLOBALS['du_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	/**
	 * The site's timezone.
	 *
	 * @return DateTimeZone
	 */
	function wp_timezone(): DateTimeZone {
		return new DateTimeZone( $GLOBALS['du_options']['timezone'] ?? 'UTC' );
	}
}

if ( ! function_exists( 'date_i18n' ) ) {
	/**
	 * Core's translated date formatter.
	 *
	 * The stub does no translating -- there is nothing to translate to in a
	 * suite -- but it takes the same arguments, which is what matters: core
	 * expects a timestamp already shifted into the site's offset.
	 *
	 * @param string $format    Date format.
	 * @param int    $timestamp Shifted timestamp.
	 *
	 * @return string
	 */
	function date_i18n( string $format, int $timestamp ): string {
		return gmdate( $format, $timestamp );
	}
}

/**
 * Put the stubbed options back.
 *
 * @return void
 */
function du_reset_globals(): void {
	$GLOBALS['du_options'] = array(
		'date_format' => 'F j, Y',
		'time_format' => 'g:i a',
		'timezone'    => 'Europe/London',
	);
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Core's error object, as much of it as a parser returns.
	 */
	class WP_Error {

		/**
		 * The error.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( private string $code = '', private string $message = '', private mixed $data = '' ) {}

		/**
		 * The code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * The message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * The data.
		 *
		 * @return mixed
		 */
		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
