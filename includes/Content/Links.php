<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Links in translated HTML that point to the site's own pages, pointed to the same page in
 * the target language (setting *Point links to the translated pages*). No WordPress calls:
 * the lookup is passed in (Post_Links::resolver()), so it is unit-tested on its own.
 *
 * Every `href` is handed to the resolver without its query and anchor; when it returns an
 * address, the link gets that address with the original query and anchor. Empty links,
 * anchors on the same page, `mailto:`, `tel:` and `javascript:` links are left alone.
 *
 * @since 1.3.0
 */
final class Links {
	private const HREF = '/(\shref\s*=\s*)(["\'])([^"\']*)\2/i';

	/**
	 * Rewrites the links of the HTML.
	 *
	 * @param string                    $html    Translated HTML.
	 * @param callable(string): ?string $resolve The address of the same page in the target language (null: leave the link).
	 * @return string
	 */
	public static function localize( string $html, callable $resolve ): string {
		if ( false === stripos( $html, 'href' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			self::HREF,
			static function ( array $m ) use ( $resolve ): string {
				$href = html_entity_decode( $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( '' === trim( $href ) || str_starts_with( $href, '#' ) || preg_match( '/^\s*(mailto|tel|javascript|data):/i', $href ) ) {
					return $m[0];
				}
				$suffix = '';
				if ( preg_match( '/^([^?#]*)([?#].*)$/s', $href, $parts ) ) {
					$href   = $parts[1];
					$suffix = $parts[2];
				}
				if ( '' === $href ) {
					return $m[0];
				}
				$url = $resolve( $href );
				if ( null === $url || '' === $url || $url === $href ) {
					return $m[0];
				}

				return $m[1] . $m[2] . htmlspecialchars( $url . $suffix, ENT_QUOTES | ENT_HTML5, 'UTF-8', false ) . $m[2];
			},
			$html
		);
	}
}
