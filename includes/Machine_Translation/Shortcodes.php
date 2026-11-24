<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Machine_Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps shortcodes out of the translation: `[wpforms id="2784" title="false"]` would come
 * back as `[wpforms id=”2784” title=”false”]` (typographic quotes), which WordPress can't
 * parse, so the form disappears from the translated page. Before a text goes to Supertext
 * each shortcode becomes an empty `<span data-st-sc="N"></span>`; afterwards the original
 * shortcode is put back exactly as it was. A shortcode whose placeholder got lost is added
 * at the end of the text, so it still works.
 *
 * @since 1.0.2
 */
final class Shortcodes {
	/** A shortcode tag: `[name …]`, `[/name]`, also `[[escaped]]`; the name is group 1. */
	private const SHORTCODE = '/\[\[?\/?([a-zA-Z][\w-]*)(?:\s[^\[\]]*)?\]\]?/';

	/** A placeholder as it may come back (attributes reordered, self-closing). */
	private const PLACEHOLDER = '/<span\b[^>]*?\bdata-st-sc="(\d+)"[^>]*?(?:\/>|>\s*<\/span>)/';

	/**
	 * Replaces the shortcodes of an HTML text with placeholders.
	 *
	 * @param string $html HTML.
	 * @return array{0: string, 1: string[]} The text to send and the shortcodes, by placeholder number.
	 */
	public static function protect( string $html ): array {
		$codes = array();
		$text  = (string) preg_replace_callback(
			self::SHORTCODE,
			static function ( array $m ) use ( &$codes ): string {
				// Only shortcodes WordPress knows: "[in CHF]" is text.
				if ( ! shortcode_exists( $m[1] ) ) {
					return $m[0];
				}
				$codes[] = $m[0];
				return '<span data-st-sc="' . ( count( $codes ) - 1 ) . '"></span>';
			},
			$html
		);

		return array( $text, $codes );
	}

	/**
	 * Puts the shortcodes back into a translation.
	 *
	 * @param string   $html  Translated HTML with placeholders.
	 * @param string[] $codes Shortcodes from protect().
	 * @return string
	 */
	public static function restore( string $html, array $codes ): string {
		if ( array() === $codes ) {
			return $html;
		}
		$used = array();
		$html = (string) preg_replace_callback(
			self::PLACEHOLDER,
			static function ( array $m ) use ( $codes, &$used ): string {
				$n = (int) $m[1];
				if ( ! isset( $codes[ $n ] ) ) {
					return '';
				}
				$used[ $n ] = true;
				return $codes[ $n ];
			},
			$html
		);
		$missing = array_diff_key( $codes, $used );

		return array() === $missing ? $html : rtrim( $html ) . ' ' . implode( ' ', $missing );
	}
}
