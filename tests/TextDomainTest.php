<?php
/**
 * Every string is in the family's text domain.
 *
 * @package   SugarCommerce\Dates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Dates\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A string in any other text domain is never translated: the language packs
 * a plugin loads are all `sugarcommerce`. Read from the source, so a string
 * no test happens to reach is checked too.
 */
final class TextDomainTest extends TestCase {

	/**
	 * Gettext functions and where their domain argument sits.
	 */
	private const DOMAIN_ARGUMENT = array(
		'__'           => 1,
		'_e'           => 1,
		'esc_html__'   => 1,
		'esc_html_e'   => 1,
		'esc_attr__'   => 1,
		'esc_attr_e'   => 1,
		'_x'           => 2,
		'_ex'          => 2,
		'esc_html_x'   => 2,
		'esc_attr_x'   => 2,
		'_n_noop'      => 2,
		'_n'           => 3,
		'_nx_noop'     => 3,
		'_nx'          => 4,
	);

	/**
	 * Strings that are core's own and so carry no domain.
	 */
	private const CORE_STRINGS = array( "'put your unique phrase here'" );

	public function test_every_string_is_in_the_sugarcommerce_domain(): void {
		$calls = 0;
		$wrong = array();
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src' ) );

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			foreach ( self::calls( (string) file_get_contents( $file->getPathname() ) ) as list( $function, $args, $line ) ) {
				++$calls;
				$domain = $args[ self::DOMAIN_ARGUMENT[ $function ] ] ?? null;

				if ( null === $domain && in_array( $args[0] ?? '', self::CORE_STRINGS, true ) ) {
					continue;
				}

				if ( "'sugarcommerce'" !== $domain ) {
					$wrong[] = $file->getFilename() . ':' . $line . ' ' . $function . '() in ' . ( $domain ?? 'no domain' );
				}
			}
		}

		$this->assertGreaterThan( 0, $calls, 'The scan found the strings.' );
		$this->assertSame( array(), $wrong );
	}

	/**
	 * Gettext calls in a file, each with its arguments as source text.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array<int, array{0: string, 1: string[], 2: int}>
	 */
	private static function calls( string $source ): array {
		$tokens = token_get_all( $source );
		$calls  = array();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( self::DOMAIN_ARGUMENT[ $token[1] ] ) ) {
				continue;
			}

			// A method or a declaration of the same name is not a call.
			$before = $i - 1;

			while ( $before >= 0 && is_array( $tokens[ $before ] ) && T_WHITESPACE === $tokens[ $before ][0] ) {
				--$before;
			}

			if ( $before >= 0 && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
				continue;
			}

			$open = $i + 1;

			while ( $open < $count && is_array( $tokens[ $open ] ) && T_WHITESPACE === $tokens[ $open ][0] ) {
				++$open;
			}

			if ( '(' !== ( $tokens[ $open ] ?? null ) ) {
				continue;
			}

			$args    = array( '' );
			$depth   = 0;
			$current = 0;

			for ( $j = $open + 1; $j < $count; $j++ ) {
				$part = $tokens[ $j ];
				$text = is_array( $part ) ? $part[1] : $part;

				if ( is_array( $part ) && in_array( $part[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}

				if ( in_array( $text, array( '(', '[', '{' ), true ) || ( is_array( $part ) && in_array( $part[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					++$depth;
				} elseif ( in_array( $text, array( ')', ']', '}' ), true ) ) {
					if ( 0 === $depth ) {
						break;
					}

					--$depth;
				} elseif ( ',' === $text && 0 === $depth ) {
					$args[] = '';
					++$current;
					continue;
				}

				$args[ $current ] .= $text;
			}

			$calls[] = array( $token[1], array_values( array_filter( array_map( 'trim', $args ), static fn( $arg ) => '' !== $arg ) ), $token[2] );
		}

		return $calls;
	}
}
