<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a translation's own (localized) images when it is translated again. No WordPress
 * calls (unit-tested on its own).
 *
 * An image's *slot* is its place in the content: the n-th image block (`core/image`, also
 * inside galleries) of block content, or the n-th `<img>` of classic content. Images in other
 * blocks (cover, media & text …) always follow the source, as before: their markup depends on
 * the block's settings. When the plugin writes a translation it records, per slot, the
 * source's image (`originalSrc`) and the translation's (`src`). On the next translation, per slot:
 *
 * - source image unchanged → the translation keeps the image it has now (its own, or the source's);
 * - source image replaced, the translation still had the old one → it gets the new one;
 * - source image replaced, the translation had its own → it gets the new one, marked `check`;
 * - no record (translated before version 1.3) and the translation's image differs → its own is
 *   kept, marked `check`;
 * - the translation's images can't be lined up with the record (images added or removed by
 *   hand) → the source's images; those that change are marked `check`.
 *
 * A kept image block is the translation's own block (image, size, link, settings) with the new
 * alternative text and caption. Images are compared by their file, without size suffix
 * (`photo-1024x768.png` is `photo.png`), query or scheme, so the media translations of WPML and
 * Polylang (the same file) count as the same image.
 *
 * @phpstan-type Record array{slot: int, originalSrc: string, src: string, status: string, reason?: string}
 * @since 1.3.0
 */
final class Images {
	public const SAME  = 'same';
	public const OWN   = 'own';
	public const CHECK = 'check';

	public const ORIGINAL_CHANGED = 'originalChanged';
	public const NO_RECORD        = 'noRecord';
	public const LAYOUT_CHANGED   = 'layoutChanged';

	private const BLOCK = '/<!--\s+wp:image(?:\s+\{.*?\})?\s+-->.*?<!--\s+\/wp:image\s+-->/s';
	private const IMG   = '/<img\b[^>]*>/i';

	/**
	 * The image slots of the content, in order.
	 *
	 * @param string $html Post content.
	 * @return list<array{html: string, src: string, offset: int}>
	 */
	public static function slots( string $html ): array {
		$pattern = str_contains( $html, '<!-- wp:' ) ? self::BLOCK : self::IMG;
		preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE );
		$slots = array();
		foreach ( $matches[0] as $match ) {
			$slots[] = array(
				'html'   => $match[0],
				'src'    => self::src( $match[0] ),
				'offset' => $match[1],
			);
		}

		return $slots;
	}

	/**
	 * An image address reduced to its file, for comparing.
	 *
	 * @param string $src Image address.
	 * @return string
	 */
	public static function identity( string $src ): string {
		$src = html_entity_decode( trim( $src ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$src = (string) preg_replace( '/[?#].*$/s', '', $src );
		$src = (string) preg_replace( '#^(https?:)?//#i', '//', $src );

		return (string) preg_replace( '/(-\d+x\d+|-scaled)(?=\.[a-z0-9]+$)/i', '', $src );
	}

	/**
	 * The record of content written with `$html`, where every image is the source's.
	 *
	 * @param string $html The written content.
	 * @return list<Record>
	 */
	public static function record( string $html ): array {
		$records = array();
		foreach ( self::slots( $html ) as $n => $slot ) {
			$records[] = array(
				'slot'        => $n,
				'originalSrc' => $slot['src'],
				'src'         => $slot['src'],
				'status'      => self::SAME,
			);
		}

		return $records;
	}

	/**
	 * Merges the images of a translation written again: `$html` (the new translation, with the
	 * source's images) gets the translation's own images where the record says so. `$current`
	 * is the translation's content now, `$written` the record of the last translation (null: none).
	 *
	 * @param string            $html    The new translation.
	 * @param string            $current The translation's content before.
	 * @param list<Record>|null $written The record of the last translation.
	 * @return array{html: string, records: list<Record>}
	 */
	public static function merge( string $html, string $current, ?array $written ): array {
		$next   = self::slots( $html );
		$now    = self::slots( $current );
		$before = array();
		foreach ( $written ?? array() as $record ) {
			$before[ (int) $record['slot'] ] = $record;
		}
		$same_layout = null === $written ? count( $next ) === count( $now ) : count( $before ) === count( $now );

		$replace = array();
		$records = array();
		foreach ( $next as $n => $slot ) {
			$original = $slot['src'];
			$own      = $now[ $n ] ?? null;
			$record   = array(
				'slot'        => $n,
				'originalSrc' => $original,
				'src'         => $original,
				'status'      => self::SAME,
			);
			$differs  = null !== $own && self::identity( $own['src'] ) !== self::identity( $original );
			if ( ! $same_layout ) {
				if ( $differs ) {
					$record['status'] = self::CHECK;
					$record['reason'] = self::LAYOUT_CHANGED;
				}
			} elseif ( null === $written ) {
				if ( $differs ) {
					$replace[ $n ] = self::keep( $own['html'], $slot['html'] );
					$record        = array(
						'slot'        => $n,
						'originalSrc' => $original,
						'src'         => $own['src'],
						'status'      => self::CHECK,
						'reason'      => self::NO_RECORD,
					);
				}
			} else {
				$last = $before[ $n ] ?? null;
				if ( null === $last ) {
					// An image added to the source: the translation gets it.
					$records[] = $record;
					continue;
				}
				if ( self::identity( $last['originalSrc'] ) === self::identity( $original ) ) {
					if ( self::CHECK === $last['status'] && null !== $own && self::identity( $own['src'] ) === self::identity( $last['src'] ) ) {
						// Still to check and unchanged since: it stays marked.
						if ( $differs ) {
							$replace[ $n ] = self::keep( $own['html'], $slot['html'] );
						}
						$record = array(
							'slot'        => $n,
							'originalSrc' => $original,
							'src'         => $own['src'],
							'status'      => self::CHECK,
						) + ( isset( $last['reason'] ) ? array( 'reason' => $last['reason'] ) : array() );
					} elseif ( $differs ) {
						$replace[ $n ] = self::keep( $own['html'], $slot['html'] );
						$record        = array(
							'slot'        => $n,
							'originalSrc' => $original,
							'src'         => $own['src'],
							'status'      => self::OWN,
						);
					}
				} elseif ( $differs && self::identity( $own['src'] ) !== self::identity( $last['originalSrc'] ) ) {
					// The source's image was replaced and the translation had its own: the new one, to check.
					$record['status'] = self::CHECK;
					$record['reason'] = self::ORIGINAL_CHANGED;
				}
			}
			$records[] = $record;
		}

		if ( array() !== $replace ) {
			// From the end, so the offsets before stay valid.
			foreach ( array_reverse( $replace, true ) as $n => $replacement ) {
				$html = substr_replace( $html, $replacement, $next[ $n ]['offset'], strlen( $next[ $n ]['html'] ) );
			}
		}

		return array(
			'html'    => $html,
			'records' => $records,
		);
	}

	/**
	 * The image at a slot of the content (for "Mark as checked").
	 *
	 * @param string $html Content.
	 * @param int    $slot Slot.
	 * @return string|null
	 */
	public static function at( string $html, int $slot ): ?string {
		return self::slots( $html )[ $slot ]['src'] ?? null;
	}

	/**
	 * How many records are marked `check`.
	 *
	 * @param mixed $records Records (as stored).
	 * @return int
	 */
	public static function to_check( $records ): int {
		$n = 0;
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			if ( is_array( $record ) && self::CHECK === ( $record['status'] ?? '' ) ) {
				++$n;
			}
		}

		return $n;
	}

	/**
	 * The translation's own image (block or tag) with the alternative text and caption just translated.
	 *
	 * @param string $own        The translation's image block or tag.
	 * @param string $translated The new translation's image block or tag.
	 * @return string
	 */
	private static function keep( string $own, string $translated ): string {
		$own_tag = self::tag( $own );
		$new_tag = self::tag( $translated );
		$alt     = null !== $new_tag ? self::attribute( $new_tag, 'alt' ) : null;
		if ( null !== $own_tag && null !== $alt ) {
			$own = str_replace( $own_tag, self::with_attribute( $own_tag, 'alt', $alt ), $own );
		}
		$caption = '/(<figcaption\b[^>]*>)(.*?)(<\/figcaption>)/is';
		if ( preg_match( $caption, $translated, $new ) && preg_match( $caption, $own ) ) {
			$own = (string) preg_replace_callback( $caption, static fn( array $m ): string => $m[1] . $new[2] . $m[3], $own, 1 );
		}

		return $own;
	}

	/**
	 * The first `<img>` tag of a block or tag.
	 *
	 * @param string $html Block or tag.
	 * @return string|null
	 */
	private static function tag( string $html ): ?string {
		return preg_match( self::IMG, $html, $m ) ? $m[0] : null;
	}

	/**
	 * The `src` of the first image.
	 *
	 * @param string $html Block or tag.
	 * @return string
	 */
	private static function src( string $html ): string {
		$tag = self::tag( $html );

		return null !== $tag ? html_entity_decode( (string) self::attribute( $tag, 'src' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
	}

	/**
	 * An attribute's raw value, null when the tag has none.
	 *
	 * @param string $tag  Tag.
	 * @param string $name Attribute.
	 * @return string|null
	 */
	private static function attribute( string $tag, string $name ): ?string {
		if ( ! preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $m ) ) {
			return null;
		}

		return isset( $m[3] ) && '' !== $m[3] ? $m[3] : ( $m[2] ?? '' );
	}

	/**
	 * The tag with an attribute set to a raw (already escaped) value.
	 *
	 * @param string $tag   Tag.
	 * @param string $name  Attribute.
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function with_attribute( string $tag, string $name, string $value ): string {
		$pattern = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*("[^"]*"|\'[^\']*\')/i';
		$quoted  = ' ' . $name . '="' . str_replace( '"', '&quot;', $value ) . '"';
		if ( preg_match( $pattern, $tag ) ) {
			return (string) preg_replace_callback( $pattern, static fn(): string => $quoted, $tag, 1 );
		}

		return (string) preg_replace( '/^<img\b/i', '<img' . $quoted, $tag, 1 );
	}
}
