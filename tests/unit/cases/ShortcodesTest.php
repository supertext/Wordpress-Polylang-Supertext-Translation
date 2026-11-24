<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use Supertext\Polylang\Machine_Translation\Shortcodes;
use Supertext\Polylang\Tests\TestCase;

/**
 * Shortcodes travel as placeholders and come back exactly as they were.
 */
class ShortcodesTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\Functions\when( 'shortcode_exists' )->alias( static fn( $name ) => in_array( $name, array( 'wpforms', 'caption' ), true ) );
	}

	public function test_shortcodes_become_placeholders(): void {
		list( $text, $codes ) = Shortcodes::protect( 'Subscribe here! [wpforms id="2784" title="false"]' );

		$this->assertSame( 'Subscribe here! <span data-st-sc="0"></span>', $text );
		$this->assertSame( array( '[wpforms id="2784" title="false"]' ), $codes );
	}

	public function test_restore_puts_the_original_back(): void {
		list( , $codes ) = Shortcodes::protect( '[caption id="5"]<img src="a.jpg"> A cat[/caption]' );

		$this->assertSame(
			'[caption id="5"]<img src="a.jpg"> Eine Katze[/caption]',
			Shortcodes::restore( '<span data-st-sc="0"></span><img src="a.jpg"> Eine Katze<span data-st-sc="1" />', $codes )
		);
	}

	public function test_a_lost_placeholder_is_appended(): void {
		list( , $codes ) = Shortcodes::protect( 'Hello [wpforms id="1"]' );

		$this->assertSame( 'Hallo [wpforms id="1"]', Shortcodes::restore( 'Hallo ', $codes ) );
	}

	public function test_text_without_shortcodes_is_unchanged(): void {
		$this->assertSame( array( 'Price [in CHF]: 20', array() ), Shortcodes::protect( 'Price [in CHF]: 20' ) );
		$this->assertSame( 'x', Shortcodes::restore( 'x', array() ) );
	}
}
