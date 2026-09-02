<?php
/**
 * What a page actually contains, and what its scripts may add later.
 *
 * @package BricksCache
 */

namespace BricksCache\Css;

/**
 * Reading the classes out of the HTML is the easy half. The half that breaks
 * sites is the one that is not in the HTML yet: the class a script adds when
 * the menu opens, when a filter is applied, when WooCommerce drops a notice
 * into the page over AJAX.
 *
 * So the scripts of the page are read too, looking for the places where a
 * class name is handled: classList, jQuery, a selector inside a string, a
 * chunk of HTML in a template literal. It is not proof, it is evidence, and it
 * is combined with a safelist for the cases evidence cannot reach.
 */
final class Vocabulary {

	/**
	 * Classes and ids present in a rendered page.
	 *
	 * @param string $html Rendered HTML.
	 *
	 * @return array<string,bool> Lookup map.
	 */
	public static function from_html( string $html ): array {
		$tokens = [];

		if ( preg_match_all( '/\sclass\s*=\s*(["\'])(.*?)\1/is', $html, $matches ) ) {
			foreach ( $matches[2] as $value ) {
				foreach ( preg_split( '/\s+/', trim( html_entity_decode( $value ) ) ) ?: [] as $class ) {
					if ( '' !== $class ) {
						$tokens[ $class ] = true;
					}
				}
			}
		}

		if ( preg_match_all( '/\sid\s*=\s*(["\'])(.*?)\1/is', $html, $ids ) ) {
			foreach ( $ids[2] as $value ) {
				$value = trim( $value );

				if ( '' !== $value ) {
					$tokens[ $value ] = true;
				}
			}
		}

		return $tokens;
	}

	/**
	 * Class names a script looks likely to add to the page.
	 *
	 * @param string $js Script source.
	 *
	 * @return array<string,bool> Lookup map.
	 */
	public static function from_script( string $js ): array {
		$tokens = [];

		// classList.add( 'a', 'b' ), jQuery addClass( 'a b' ) and friends.
		if ( preg_match_all( '/(?:classList\s*\.\s*(?:add|remove|toggle|contains|replace)|(?:add|remove|toggle|has)Class)\s*\(([^)]*)\)/i', $js, $calls ) ) {
			foreach ( $calls[1] as $arguments ) {
				if ( preg_match_all( '/["\'`]([^"\'`]+)["\'`]/', $arguments, $strings ) ) {
					foreach ( $strings[1] as $value ) {
						foreach ( preg_split( '/\s+/', trim( $value ) ) ?: [] as $class ) {
							if ( '' !== $class && preg_match( '/^[A-Za-z_][\w\-]*$/', $class ) ) {
								$tokens[ $class ] = true;
							}
						}
					}
				}
			}
		}

		// A selector written inside a string: '.foo', "#bar".
		if ( preg_match_all( '/["\'`]\s*([.#][A-Za-z_][\w\-]{1,60})/', $js, $selectors ) ) {
			foreach ( $selectors[1] as $selector ) {
				$tokens[ substr( $selector, 1 ) ] = true;
			}
		}

		// HTML written from JavaScript: class="a b".
		if ( preg_match_all( '/class\s*=\s*\\\\?["\']([^"\'\\\\]+)/i', $js, $markup ) ) {
			foreach ( $markup[1] as $value ) {
				foreach ( preg_split( '/\s+/', trim( $value ) ) ?: [] as $class ) {
					if ( '' !== $class && preg_match( '/^[A-Za-z_][\w\-]*$/', $class ) ) {
						$tokens[ $class ] = true;
					}
				}
			}
		}

		return $tokens;
	}
}
