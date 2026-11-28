<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use Supertext\Polylang\Content\Images;
use Supertext\Polylang\Content\Links;
use Supertext\Polylang\Tests\TestCase;

/**
 * Links to the site's own pages pointed to their translation, and a translation's own
 * (localized) images kept when it is translated again.
 */
class ImagesLinksTest extends TestCase {
	/** Block content: one paragraph and one image block per image. */
	private static function blocks( string ...$srcs ): string {
		$html = '';
		foreach ( $srcs as $i => $src ) {
			$id    = 10 + $i;
			$html .= "<!-- wp:paragraph -->\n<p>Text $i</p>\n<!-- /wp:paragraph -->\n\n"
				. "<!-- wp:image {\"id\":$id,\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"$src\" alt=\"Bild $i\" class=\"wp-image-$id\"/><figcaption class=\"wp-element-caption\">Legende $i</figcaption></figure>\n<!-- /wp:image -->\n\n";
		}

		return $html;
	}

	private static function srcs( string $html ): array {
		return array_column( Images::slots( $html ), 'src' );
	}

	private static function statuses( array $records ): array {
		return array_map( static fn( $r ) => array( $r['status'], $r['reason'] ?? null ), $records );
	}

	public function test_slots_are_image_blocks_or_classic_img_tags(): void {
		$this->assertSame( array( 'https://x.test/a.png', 'https://x.test/b.png' ), self::srcs( self::blocks( 'https://x.test/a.png', 'https://x.test/b.png' ) ) );
		$this->assertSame( array( '/c.png' ), self::srcs( '<p>x</p><img src="/c.png" alt="">' ), 'classic content: every <img>' );
		$cover = "<!-- wp:cover {\"url\":\"/d.png\"} -->\n<div class=\"wp-block-cover\"><img src=\"/d.png\"/></div>\n<!-- /wp:cover -->";
		$this->assertSame( array(), self::srcs( $cover ), 'images in other blocks follow the source' );
		$this->assertSame( '//x.test/wp-content/uploads/photo.png', Images::identity( 'https://x.test/wp-content/uploads/photo-1024x768.png?v=2' ) );
		$this->assertSame( Images::identity( 'http://x.test/a-scaled.jpg' ), Images::identity( 'https://x.test/a.jpg' ) );
	}

	public function test_keeps_the_translations_own_image_block_with_the_new_alt_and_caption(): void {
		$written = Images::record( self::blocks( '/a.png', '/b.png' ) );
		$current = str_replace( array( 'src="/a.png"', 'wp-image-10', '"id":10', 'Legende 0' ), array( 'src="/a-de.png"', 'wp-image-99', '"id":99', 'alte Legende' ), self::blocks( '/a.png', '/b.png' ) );
		$new     = str_replace( array( 'Bild 0', 'Legende 0' ), array( 'Neues Bild', 'Neue Legende' ), self::blocks( '/a.png', '/b.png' ) );
		$merged  = Images::merge( $new, $current, $written );
		$this->assertSame( array( array( 'own', null ), array( 'same', null ) ), self::statuses( $merged['records'] ) );
		$this->assertStringContainsString( '<!-- wp:image {"id":99,"sizeSlug":"large"} -->', $merged['html'], 'the block settings are the translation\'s' );
		$this->assertStringContainsString( '<img src="/a-de.png" alt="Neues Bild" class="wp-image-99"/>', $merged['html'] );
		$this->assertStringContainsString( 'Neue Legende', $merged['html'] );
		$this->assertStringNotContainsString( 'alte Legende', $merged['html'] );
		$this->assertSame( array( '/a-de.png', '/b.png' ), self::srcs( $merged['html'] ) );
	}

	public function test_a_replaced_original_image_comes_through_and_is_marked_when_the_translation_had_its_own(): void {
		$written = Images::record( self::blocks( '/a.png', '/b.png' ) );
		$merged  = Images::merge( self::blocks( '/a2.png', '/b2.png' ), self::blocks( '/a.png', '/b-de.png' ), $written );
		$this->assertSame( array( array( 'same', null ), array( 'check', 'originalChanged' ) ), self::statuses( $merged['records'] ) );
		$this->assertSame( array( '/a2.png', '/b2.png' ), self::srcs( $merged['html'] ) );
		$this->assertSame( 1, Images::to_check( $merged['records'] ) );
	}

	public function test_stays_marked_until_checked(): void {
		$written = array( array( 'slot' => 0, 'originalSrc' => '/b2.png', 'src' => '/b2.png', 'status' => 'check', 'reason' => 'originalChanged' ) );
		$merged  = Images::merge( self::blocks( '/b2.png' ), self::blocks( '/b2.png' ), $written );
		$this->assertSame( array( array( 'check', 'originalChanged' ) ), self::statuses( $merged['records'] ) );
	}

	public function test_without_a_record_the_translations_image_is_kept_and_marked(): void {
		$merged = Images::merge( self::blocks( '/a.png' ), self::blocks( '/a-de.png' ), null );
		$this->assertSame( array( array( 'check', 'noRecord' ) ), self::statuses( $merged['records'] ) );
		$this->assertSame( array( '/a-de.png' ), self::srcs( $merged['html'] ) );
	}

	public function test_images_added_or_removed_in_the_translation_only_give_the_originals(): void {
		$written = Images::record( self::blocks( '/a.png' ) );
		$merged  = Images::merge( self::blocks( '/a.png' ), self::blocks( '/a-de.png', '/extra.png' ), $written );
		$this->assertSame( array( array( 'check', 'layoutChanged' ) ), self::statuses( $merged['records'] ) );
		$this->assertSame( array( '/a.png' ), self::srcs( $merged['html'] ) );
	}

	public function test_an_image_added_to_the_original_appears_and_classic_tags_keep_their_own_src(): void {
		$written = Images::record( '<p>x</p><img src="/a.png" alt="A" width="300">' );
		$merged  = Images::merge( '<p>y</p><img src="/a.png" alt="Neu" width="300"><img src="/n.png" alt="N">', '<p>x</p><img src="/a-de.png" alt="A" width="500">', $written );
		$this->assertSame( array( 'own', 'same' ), array_column( $merged['records'], 'status' ) );
		$this->assertStringContainsString( '<img src="/a-de.png" alt="Neu" width="500">', $merged['html'] );
		$this->assertStringContainsString( '/n.png', $merged['html'] );
	}

	public function test_links_to_own_pages_point_to_their_translation(): void {
		$map     = array(
			'https://x.test/about/' => 'https://x.test/de/ueber-uns/',
			'/contact/'             => 'https://x.test/de/kontakt/',
		);
		$resolve = static fn( string $href ): ?string => $map[ $href ] ?? null;
		$html    = '<p><a href="https://x.test/about/#team">Team</a>, <a href=\'/contact/?ref=1\'>Kontakt</a>, '
			. '<a href="https://other.test/about/">x</a>, <a href="#top">top</a>, <a href="mailto:a@x.test">mail</a>, <a href="https://x.test/missing/">m</a></p>';
		$out     = Links::localize( $html, $resolve );
		$this->assertStringContainsString( '<a href="https://x.test/de/ueber-uns/#team">', $out, 'anchor kept' );
		$this->assertStringContainsString( "<a href='https://x.test/de/kontakt/?ref=1'>", $out, 'query kept, quotes kept' );
		$this->assertStringContainsString( 'https://other.test/about/', $out );
		$this->assertStringContainsString( 'href="#top"', $out );
		$this->assertStringContainsString( 'mailto:a@x.test', $out );
		$this->assertStringContainsString( 'https://x.test/missing/', $out, 'no translation: unchanged' );
		$this->assertSame( '<p>no links</p>', Links::localize( '<p>no links</p>', $resolve ) );
	}
}
