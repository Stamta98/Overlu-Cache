<?php
/**
 * Removes the rules a page cannot use. Pure PHP.
 *
 * @package BricksCache
 */

namespace BricksCache\Css;

/**
 * The scanner walks the stylesheet once and rebuilds it, dropping style rules
 * whose classes and ids are nowhere in the page.
 *
 * Everything that is not a plain style rule is kept untouched: @font-face,
 * @keyframes, @property, @page. They are small, and deciding they are unused
 * requires reading the declarations that reference them, which is exactly the
 * kind of cleverness that removes a font from a working site. Conditional
 * groups — @media, @supports, @container, @layer — are walked into, and only
 * disappear when everything inside them did.
 */
final class Purger {

	/**
	 * At-rules whose body is a list of style rules.
	 *
	 * @var string[]
	 */
	private const GROUPS = [ 'media', 'supports', 'container', 'layer', 'scope', 'document' ];

	/**
	 * Filter a stylesheet against the vocabulary of a page.
	 *
	 * @param string             $css        Stylesheet.
	 * @param array<string,bool> $vocabulary Tokens present in the page.
	 * @param string[]           $safelist   Patterns always kept.
	 *
	 * @return array{css:string,kept:int,removed:int}
	 */
	public static function purge( string $css, array $vocabulary, array $safelist = [] ): array {
		$stats = [
			'kept'    => 0,
			'removed' => 0,
		];

		$out = self::walk( $css, $vocabulary, $safelist, $stats );

		return [
			'css'     => trim( $out ),
			'kept'    => $stats['kept'],
			'removed' => $stats['removed'],
		];
	}

	/**
	 * Every class and id any selector in this stylesheet asks for.
	 *
	 * The purged result only depends on which of these the page has, so this
	 * list is what turns "the vocabulary of this page" into a short signature
	 * that pages of the same kind share.
	 *
	 * @param string $css Stylesheet.
	 *
	 * @return string[] Sorted, unique.
	 */
	public static function index( string $css ): array {
		$tokens = [];
		$stats  = [
			'kept'    => 0,
			'removed' => 0,
		];

		self::walk(
			$css,
			[],
			[],
			$stats,
			static function ( string $selector_list ) use ( &$tokens ): void {
				foreach ( Selectors::split_list( $selector_list ) as $selector ) {
					foreach ( Selectors::required_tokens( $selector ) as $token ) {
						$tokens[ $token ] = true;
					}
				}
			}
		);

		$list = array_keys( $tokens );
		sort( $list );

		return $list;
	}

	/**
	 * Walk one level of the stylesheet.
	 *
	 * @param string                $css        Stylesheet or block body.
	 * @param array<string,bool>    $vocabulary Tokens present in the page.
	 * @param string[]              $safelist   Patterns always kept.
	 * @param array<string,int>     $stats      Counters, by reference.
	 * @param callable|null         $collector  Called with every selector list found.
	 */
	private static function walk( string $css, array $vocabulary, array $safelist, array &$stats, ?callable $collector = null ): string {
		$length = strlen( $css );
		$out    = '';
		$i      = 0;

		while ( $i < $length ) {
			// Skip whitespace between rules.
			while ( $i < $length && ctype_space( $css[ $i ] ) ) {
				++$i;
			}

			if ( $i >= $length ) {
				break;
			}

			// A comment survives as it is: it is either a licence or debug help.
			if ( '/' === $css[ $i ] && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end  = strpos( $css, '*/', $i + 2 );
				$end  = false === $end ? $length : $end + 2;
				$out .= substr( $css, $i, $end - $i );
				$i    = $end;

				continue;
			}

			$prelude_end = self::find_block_start( $css, $i );

			if ( null === $prelude_end ) {
				// Trailing junk, or a statement without a block.
				$out .= substr( $css, $i );

				break;
			}

			[ $stop, $char ] = $prelude_end;

			$prelude = trim( substr( $css, $i, $stop - $i ) );

			if ( ';' === $char ) {
				// @import, @charset, @layer a, b;
				$out .= $prelude . ';';
				$i    = $stop + 1;

				continue;
			}

			$block_end = self::find_block_end( $css, $stop );
			$body      = substr( $css, $stop + 1, $block_end - $stop - 1 );
			$i         = $block_end + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$name = strtolower( (string) preg_replace( '/^@([a-z\-]+).*$/is', '$1', $prelude ) );

				if ( in_array( $name, self::GROUPS, true ) ) {
					$inner = self::walk( $body, $vocabulary, $safelist, $stats, $collector );

					if ( '' !== trim( $inner ) ) {
						$out .= $prelude . '{' . $inner . '}';
					}

					continue;
				}

				$out .= $prelude . '{' . $body . '}';

				continue;
			}

			if ( null !== $collector ) {
				$collector( $prelude );

				$out .= $prelude . '{' . $body . '}';

				continue;
			}

			$kept = [];

			foreach ( Selectors::split_list( $prelude ) as $selector ) {
				if ( Selectors::is_used( $selector, $vocabulary, $safelist ) ) {
					$kept[] = $selector;
				}
			}

			if ( [] === $kept ) {
				++$stats['removed'];

				continue;
			}

			++$stats['kept'];

			$out .= implode( ',', $kept ) . '{' . $body . '}';
		}

		return $out;
	}

	/**
	 * Position of the { that opens the next block, or of the ; that ends a
	 * blockless statement.
	 *
	 * @param string $css   Stylesheet.
	 * @param int    $start Offset to start from.
	 *
	 * @return array{0:int,1:string}|null
	 */
	private static function find_block_start( string $css, int $start ): ?array {
		$length = strlen( $css );
		$quote  = '';

		for ( $i = $start; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( '' !== $quote ) {
				if ( $char === $quote && '\\' !== ( $css[ $i - 1 ] ?? '' ) ) {
					$quote = '';
				}

				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;

				continue;
			}

			if ( '{' === $char || ';' === $char ) {
				return [ $i, $char ];
			}
		}

		return null;
	}

	/**
	 * Position of the } that closes the block opened at $open.
	 *
	 * @param string $css  Stylesheet.
	 * @param int    $open Offset of the opening brace.
	 */
	private static function find_block_end( string $css, int $open ): int {
		$length = strlen( $css );
		$depth  = 0;
		$quote  = '';

		for ( $i = $open; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( '' !== $quote ) {
				if ( $char === $quote && '\\' !== ( $css[ $i - 1 ] ?? '' ) ) {
					$quote = '';
				}

				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;

				continue;
			}

			if ( '{' === $char ) {
				++$depth;
			}

			if ( '}' === $char ) {
				--$depth;

				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return $length - 1;
	}
}
