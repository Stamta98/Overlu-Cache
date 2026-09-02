<?php
/**
 * Reads what a CSS selector needs in order to match. Pure PHP.
 *
 * @package BricksCache
 */

namespace BricksCache\Css;

/**
 * Deciding whether a rule is used comes down to one question: which classes
 * and ids does this selector require the page to have?
 *
 * Everything else is deliberately ignored. A selector made only of tags,
 * pseudo-classes or attributes is always kept: those are cheap, they are the
 * resets a page depends on, and guessing wrong about them breaks layouts for
 * no gain. The weight of a stylesheet is in the class rules — three icon fonts
 * on this site are 121 KB of `.fa-*`, `.ion-*` and `.ti-*`.
 */
final class Selectors {

	/**
	 * Classes and ids a selector needs, without the leading . or #.
	 *
	 * @param string $selector One selector, not a list.
	 *
	 * @return string[]
	 */
	public static function required_tokens( string $selector ): array {
		$selector = self::strip_strings( $selector );

		// :not() means "when this is absent", so its contents are not a
		// requirement. :is(), :where() and :has() match if any of their
		// arguments do, so requiring all of them would remove rules that are
		// perfectly in use. Both are dropped from the requirement list.
		$selector = self::strip_functional_pseudos( $selector );

		$tokens = [];

		if ( preg_match_all( '/\.((?:[A-Za-z0-9_\-]|\\\\.)+)/', $selector, $classes ) ) {
			foreach ( $classes[1] as $class ) {
				$tokens[] = stripslashes( $class );
			}
		}

		if ( preg_match_all( '/#([A-Za-z0-9_\-]+)/', $selector, $ids ) ) {
			foreach ( $ids[1] as $id ) {
				$tokens[] = $id;
			}
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Whether a page containing these tokens can possibly match the selector.
	 *
	 * @param string                $selector  One selector.
	 * @param array<string,bool>    $vocabulary Tokens present in the page, as a lookup map.
	 * @param string[]              $safelist  Patterns that are always kept.
	 */
	public static function is_used( string $selector, array $vocabulary, array $safelist = [] ): bool {
		$selector = trim( $selector );

		if ( '' === $selector ) {
			return false;
		}

		$tokens = self::required_tokens( $selector );

		if ( [] === $tokens ) {
			// Tags, pseudo-elements, attributes, :root, *: always kept.
			return true;
		}

		foreach ( $tokens as $token ) {
			if ( isset( $vocabulary[ $token ] ) ) {
				continue;
			}

			if ( self::in_safelist( $token, $safelist ) ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Split a selector list on the commas that separate selectors, ignoring
	 * the commas inside :is(), :not(), attribute values and strings.
	 *
	 * @param string $list Selector list.
	 *
	 * @return string[]
	 */
	public static function split_list( string $list ): array {
		$parts   = [];
		$current = '';
		$depth   = 0;
		$bracket = 0;
		$quote   = '';
		$length  = strlen( $list );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $list[ $i ];

			if ( '' !== $quote ) {
				$current .= $char;

				if ( $char === $quote && '\\' !== ( $list[ $i - 1 ] ?? '' ) ) {
					$quote = '';
				}

				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote    = $char;
				$current .= $char;

				continue;
			}

			if ( '(' === $char ) {
				++$depth;
			}

			if ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
			}

			if ( '[' === $char ) {
				++$bracket;
			}

			if ( ']' === $char ) {
				$bracket = max( 0, $bracket - 1 );
			}

			if ( ',' === $char && 0 === $depth && 0 === $bracket ) {
				$parts[] = trim( $current );
				$current = '';

				continue;
			}

			$current .= $char;
		}

		if ( '' !== trim( $current ) ) {
			$parts[] = trim( $current );
		}

		return $parts;
	}

	/**
	 * Whether a token is protected by the safelist. Patterns may end in *.
	 *
	 * @param string   $token    Class or id.
	 * @param string[] $safelist Patterns.
	 */
	public static function in_safelist( string $token, array $safelist ): bool {
		foreach ( $safelist as $pattern ) {
			$pattern = trim( (string) $pattern );

			if ( '' === $pattern ) {
				continue;
			}

			if ( str_ends_with( $pattern, '*' ) ) {
				if ( str_starts_with( $token, substr( $pattern, 0, -1 ) ) ) {
					return true;
				}

				continue;
			}

			if ( $pattern === $token ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Replace quoted strings with placeholders so their contents are not read
	 * as classes.
	 *
	 * @param string $selector Selector.
	 */
	private static function strip_strings( string $selector ): string {
		return (string) preg_replace( '/(["\'])(?:\\\\.|(?!\1).)*\1/', '""', $selector );
	}

	/**
	 * Remove the contents of the pseudo-classes whose arguments are not a
	 * requirement.
	 *
	 * @param string $selector Selector.
	 */
	private static function strip_functional_pseudos( string $selector ): string {
		$pattern = '/:(?:not|is|where|has|matches|any)\(/i';

		while ( preg_match( $pattern, $selector, $match, PREG_OFFSET_CAPTURE ) ) {
			$start = (int) $match[0][1];
			$open  = $start + strlen( $match[0][0] ) - 1;
			$depth = 0;
			$end   = null;

			for ( $i = $open, $length = strlen( $selector ); $i < $length; $i++ ) {
				if ( '(' === $selector[ $i ] ) {
					++$depth;
				}

				if ( ')' === $selector[ $i ] ) {
					--$depth;

					if ( 0 === $depth ) {
						$end = $i;

						break;
					}
				}
			}

			if ( null === $end ) {
				return substr( $selector, 0, $start );
			}

			$selector = substr( $selector, 0, $start ) . ' ' . substr( $selector, $end + 1 );
		}

		return $selector;
	}
}
