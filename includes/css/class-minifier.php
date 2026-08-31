<?php
/**
 * CSS minifier and URL rewriter. Pure PHP: no WordPress functions.
 *
 * @package BricksCache
 */

namespace BricksCache\Css;

/**
 * Minifying CSS with a plain regular expression is the classic way to break a
 * site: it eats the space in calc(1px + 2px), the braces inside a quoted
 * string, and the semicolons inside a data URI. So this is a scanner instead —
 * it walks the file once, knows whether it is inside a string, a comment, a
 * url() or a parenthesis, and only removes what is provably whitespace.
 *
 * Inside parentheses nothing is touched beyond collapsing runs of spaces,
 * because that is where calc(), clamp() and media queries live.
 */
final class Minifier {

	/**
	 * Minify a stylesheet.
	 *
	 * @param string $css Stylesheet source.
	 */
	public static function minify( string $css ): string {
		$length      = strlen( $css );
		$out         = '';
		$depth       = 0;
		$i           = 0;
		$was_comment = false;

		while ( $i < $length ) {
			$char = $css[ $i ];
			$next = $css[ $i + 1 ] ?? '';

			// Comments. /*! ... */ is a licence header and stays.
			if ( '/' === $char && '*' === $next ) {
				$end  = strpos( $css, '*/', $i + 2 );
				$end  = false === $end ? $length : $end + 2;
				$body = substr( $css, $i, $end - $i );

				if ( str_starts_with( $body, '/*!' ) ) {
					$out        .= $body;
					$was_comment = true;
				}

				$i = $end;

				continue;
			}

			// Strings are copied verbatim, escapes included.
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				$out  .= $char;
				++$i;

				while ( $i < $length ) {
					$out .= $css[ $i ];

					if ( '\\' === $css[ $i ] && $i + 1 < $length ) {
						$out .= $css[ $i + 1 ];
						$i   += 2;

						continue;
					}

					if ( $quote === $css[ $i ] ) {
						++$i;

						break;
					}

					++$i;
				}

				continue;
			}

			// url(...) without quotes may contain anything but a closing paren.
			if ( ( 'u' === $char || 'U' === $char ) && preg_match( '/\Gurl\(\s*/i', $css, $match, 0, $i ) ) {
				$end = strpos( $css, ')', $i );

				if ( false !== $end ) {
					$inner = trim( substr( $css, $i + strlen( $match[0] ), $end - $i - strlen( $match[0] ) ) );
					$out  .= 'url(' . $inner . ')';
					$i     = $end + 1;

					continue;
				}
			}

			if ( '(' === $char ) {
				++$depth;
				$out .= $char;
				++$i;

				continue;
			}

			if ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
				$out  .= $char;
				++$i;

				continue;
			}

			// Whitespace: collapse, then drop it where it cannot matter.
			if ( self::is_space( $char ) ) {
				while ( $i < $length && self::is_space( $css[ $i ] ) ) {
					++$i;
				}

				$previous = $out === '' ? '' : $out[ strlen( $out ) - 1 ];
				$upcoming = $css[ $i ] ?? '';

				if ( '' === $upcoming || '' === $previous ) {
					continue;
				}

				// Nothing needs a space after a licence header.
				if ( $was_comment ) {
					$was_comment = false;

					continue;
				}

				if ( self::drops_space( $previous, $upcoming, $depth ) ) {
					continue;
				}

				$out .= ' ';

				continue;
			}

			// A semicolon right before a closing brace is redundant.
			if ( ';' === $char ) {
				$rest = ltrim( substr( $css, $i + 1 ) );

				if ( str_starts_with( $rest, '}' ) ) {
					++$i;

					continue;
				}
			}

			$was_comment = false;
			$out        .= $char;
			++$i;
		}

		return trim( $out );
	}

	/**
	 * Turn every relative url() into an absolute one, so a rule keeps finding
	 * its image or font after the file is moved into the cache directory.
	 *
	 * @param string $css      Stylesheet source.
	 * @param string $base_url URL of the directory the stylesheet came from,
	 *                         with a trailing slash.
	 */
	public static function rewrite_urls( string $css, string $base_url ): string {
		return (string) preg_replace_callback(
			'/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
			static function ( array $match ) use ( $base_url ): string {
				$quote = $match[1];
				$url   = trim( $match[2] );

				if ( '' === $url || self::is_absolute_url( $url ) ) {
					return 'url(' . $quote . $url . $quote . ')';
				}

				return 'url(' . $quote . self::resolve( $base_url, $url ) . $quote . ')';
			},
			$css
		);
	}

	/**
	 * Whether a URL needs no rewriting.
	 *
	 * @param string $url URL found inside url().
	 */
	public static function is_absolute_url( string $url ): bool {
		foreach ( [ 'data:', 'http://', 'https://', '//', '/', '#' ] as $prefix ) {
			if ( str_starts_with( $url, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a relative URL against a base, collapsing ../ segments.
	 *
	 * @param string $base_url Directory URL with trailing slash.
	 * @param string $relative Relative URL, possibly with a query or fragment.
	 */
	public static function resolve( string $base_url, string $relative ): string {
		$suffix = '';

		foreach ( [ '?', '#' ] as $separator ) {
			$position = strpos( $relative, $separator );

			if ( false !== $position ) {
				$suffix   = substr( $relative, $position ) . $suffix;
				$relative = substr( $relative, 0, $position );
			}
		}

		$combined = rtrim( $base_url, '/' ) . '/' . ltrim( $relative, './' );

		// Collapse ../ against the base path.
		$parts = explode( '/', $base_url );
		$parts = array_filter( $parts, static fn( $part ) => '' !== $part );
		$parts = array_values( $parts );

		if ( str_contains( $relative, '../' ) ) {
			$scheme = '';

			if ( preg_match( '#^([a-z][a-z0-9+.\-]*://[^/]+)(/.*)?$#i', $base_url, $match ) ) {
				$scheme = $match[1];
				$path   = trim( $match[2] ?? '/', '/' );
				$parts  = '' === $path ? [] : explode( '/', $path );
			}

			foreach ( explode( '/', $relative ) as $segment ) {
				if ( '..' === $segment ) {
					array_pop( $parts );

					continue;
				}

				if ( '.' === $segment || '' === $segment ) {
					continue;
				}

				$parts[] = $segment;
			}

			$combined = ( '' !== $scheme ? $scheme . '/' : '' ) . implode( '/', $parts );
		}

		return $combined . $suffix;
	}

	/**
	 * Whether a character is CSS whitespace.
	 *
	 * @param string $char Single character.
	 */
	private static function is_space( string $char ): bool {
		return ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char || "\f" === $char;
	}

	/**
	 * Whether the whitespace between two characters can be dropped.
	 *
	 * Inside parentheses the answer is almost always no: calc(1px + 2px) needs
	 * its spaces, and so does a media query.
	 *
	 * @param string $previous Character before the whitespace.
	 * @param string $upcoming Character after the whitespace.
	 * @param int    $depth    Parenthesis nesting level.
	 */
	private static function drops_space( string $previous, string $upcoming, int $depth ): bool {
		$safe = [ '{', '}', ';', ':', ',' ];

		if ( in_array( $previous, $safe, true ) || in_array( $upcoming, $safe, true ) ) {
			return true;
		}

		// Padding just inside a parenthesis is never significant, not even in
		// calc(): what matters there is the space around the operator.
		if ( '(' === $previous || ')' === $upcoming ) {
			return true;
		}

		if ( $depth > 0 ) {
			return false;
		}

		// Selector combinators, only outside parentheses.
		$combinators = [ '>', '+', '~' ];

		return in_array( $previous, $combinators, true ) || in_array( $upcoming, $combinators, true );
	}
}
