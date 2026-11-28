<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Content;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Admin\Settings;
use WP_Post;

/**
 * What Supertext does to links and images in translations:
 *
 * - Links (setting *Point links to the translated pages*): links to the site's own posts and
 *   pages in the translated texts point to their translation (Links). Applied to the texts
 *   before Polylang writes them: in the AI client (editor and bulk action) and in
 *   Human_Translation\Writeback::save_map() (orders and imported files).
 * - Images: a translation written again keeps its own images (Images). Polylang rebuilds the
 *   content from the original, so the translation's content is read before the save
 *   (snapshot()) and merged after it (merge()); the record is the post meta `_supertext_images`.
 *
 * @since 1.3.0
 */
final class Post_Links {
	/** Post meta of a translation: the image record of the last translation (Images). */
	public const META = '_supertext_images';

	/**
	 * Localizes the links of translated texts.
	 *
	 * @param array<int|string, string> $texts       Translated texts (HTML).
	 * @param string                    $source_slug Polylang slug of the source language.
	 * @param string                    $target_slug Polylang slug of the target language.
	 * @return array<int|string, string>
	 */
	public static function localize_texts( array $texts, string $source_slug, string $target_slug ): array {
		if ( '' === $source_slug || '' === $target_slug || $source_slug === $target_slug || ! function_exists( 'pll_get_post' ) || ! Settings::translate_links() ) {
			return $texts;
		}
		$resolve = self::resolver( $source_slug, $target_slug );
		foreach ( $texts as $key => $text ) {
			if ( false !== stripos( (string) $text, 'href' ) ) {
				$texts[ $key ] = Links::localize( (string) $text, $resolve );
			}
		}

		return $texts;
	}

	/**
	 * The translation of a post into a language as it is before Supertext writes it again.
	 *
	 * @param int    $source_id   Source post.
	 * @param string $target_slug Polylang slug of the target language.
	 * @return array{id: int, content: string, written: list<array<string, mixed>>|null}|null Null: no translation yet.
	 */
	public static function snapshot( int $source_id, string $target_slug ): ?array {
		$id   = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $source_id, $target_slug ) : 0;
		$post = $id > 0 && $id !== $source_id ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$written = get_post_meta( $id, self::META, true );

		return array(
			'id'      => $id,
			'content' => (string) $post->post_content,
			'written' => is_array( $written ) ? array_values( $written ) : null,
		);
	}

	/**
	 * After Polylang wrote a translation: keeps its own images (with the snapshot taken
	 * before) and stores the image record. Returns how many images are to check.
	 *
	 * @param int                                                                         $source_id   Source post.
	 * @param string                                                                      $target_slug Polylang slug of the target language.
	 * @param array{id: int, content: string, written: list<array<string, mixed>>|null}|null $before      snapshot() before the save.
	 * @return int
	 */
	public static function merge( int $source_id, string $target_slug, ?array $before ): int {
		$id   = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $source_id, $target_slug ) : 0;
		$post = $id > 0 && $id !== $source_id ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post ) {
			return 0;
		}
		$content = (string) $post->post_content;
		if ( null === $before || $before['id'] !== $id ) {
			$records = Images::record( $content );
		} else {
			$merged  = Images::merge( $content, $before['content'], $before['written'] );
			$records = $merged['records'];
			if ( $merged['html'] !== $content ) {
				wp_update_post(
					wp_slash(
						array(
							'ID'           => $id,
							'post_content' => $merged['html'],
						)
					)
				);
			}
		}
		self::save_records( $id, $records );

		return Images::to_check( $records );
	}

	/**
	 * Stores the image record of a translation.
	 *
	 * @param int                        $post_id Translation.
	 * @param list<array<string, mixed>> $records Records.
	 * @return void
	 */
	public static function save_records( int $post_id, array $records ): void {
		if ( array() === $records ) {
			delete_post_meta( $post_id, self::META );
			return;
		}
		update_post_meta( $post_id, self::META, wp_slash( $records ) );
	}

	/**
	 * The address of the same page in the target language, for Links::localize(): the
	 * permalink of the post's translation, the target language's home page for the home page,
	 * null for other websites and for posts without a translation into the target language.
	 *
	 * @param string $source_slug Polylang slug of the source language.
	 * @param string $target_slug Polylang slug of the target language.
	 * @return callable(string): ?string
	 */
	public static function resolver( string $source_slug, string $target_slug ): callable {
		$cache = array();

		return static function ( string $href ) use ( $source_slug, $target_slug, &$cache ): ?string {
			if ( array_key_exists( $href, $cache ) ) {
				return $cache[ $href ];
			}
			$url    = self::absolute( $href );
			$result = null;
			if ( null !== $url ) {
				$homes = array( untrailingslashit( (string) home_url( '/' ) ) );
				if ( function_exists( 'pll_home_url' ) ) {
					$homes[] = untrailingslashit( (string) pll_home_url( $source_slug ) );
				}
				if ( in_array( untrailingslashit( $url ), $homes, true ) ) {
					$result = function_exists( 'pll_home_url' ) ? (string) pll_home_url( $target_slug ) : null;
				} else {
					$id = (int) url_to_postid( $url );
					if ( $id > 0 && function_exists( 'pll_get_post_language' ) && pll_get_post_language( $id ) === $source_slug ) {
						$translation = (int) pll_get_post( $id, $target_slug );
						if ( $translation > 0 && $translation !== $id ) {
							$link   = get_permalink( $translation );
							$result = is_string( $link ) && '' !== $link ? $link : null;
						}
					}
				}
			}
			$cache[ $href ] = $result;

			return $result;
		};
	}

	/**
	 * A link's absolute address on this site, null for other websites.
	 *
	 * @param string $href Link without query and anchor.
	 * @return string|null
	 */
	private static function absolute( string $href ): ?string {
		$home = wp_parse_url( (string) home_url( '/' ) );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return null;
		}
		$origin = ( $home['scheme'] ?? 'https' ) . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		if ( str_starts_with( $href, '//' ) ) {
			$href = ( $home['scheme'] ?? 'https' ) . ':' . $href;
		} elseif ( str_starts_with( $href, '/' ) ) {
			return $origin . $href;
		}
		$parts = wp_parse_url( $href );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}
		$host = strtolower( (string) $parts['host'] );
		$own  = strtolower( (string) $home['host'] );
		if ( $host !== $own && 'www.' . $host !== $own && $host !== 'www.' . $own ) {
			return null;
		}
		if ( ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
			return null;
		}

		return $origin . ( $parts['path'] ?? '/' );
	}
}
