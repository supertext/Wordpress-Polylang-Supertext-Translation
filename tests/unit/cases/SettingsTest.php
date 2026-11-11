<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Supertext\Polylang\Admin\Settings;
use Supertext\Polylang\Tests\TestCase;

class SettingsTest extends TestCase {
	public function test_defaults_enable_preview_links_and_screenshots(): void {
		$defaults = Settings::defaults();

		$this->assertTrue( $defaults['preview_links_enabled'] );
		$this->assertTrue( $defaults['screenshots_enabled'] );
	}

	public function test_sanitize_reads_toggle_checkboxes_on(): void {
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$out = Settings::sanitize(
			array(
				'preview_links_enabled' => '1',
				'screenshots_enabled'   => '1',
			)
		);

		$this->assertTrue( $out['preview_links_enabled'] );
		$this->assertTrue( $out['screenshots_enabled'] );
	}

	public function test_sanitize_treats_absent_checkboxes_as_off(): void {
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		// Unchecked checkboxes are simply absent from the submitted form.
		$out = Settings::sanitize( array() );

		$this->assertFalse( $out['preview_links_enabled'] );
		$this->assertFalse( $out['screenshots_enabled'] );
	}

	public function test_screenshots_enabled_true_when_option_absent(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertTrue( Settings::screenshots_enabled() );
	}

	public function test_screenshots_enabled_false_when_disabled_in_option(): void {
		Functions\when( 'get_option' )->justReturn( array( 'screenshots_enabled' => false ) );

		$this->assertFalse( Settings::screenshots_enabled() );
	}

	public function test_preview_links_enabled_true_when_option_absent(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertTrue( Settings::preview_links_enabled() );
	}

	public function test_preview_links_enabled_false_when_disabled_in_option(): void {
		Functions\when( 'get_option' )->justReturn( array( 'preview_links_enabled' => false ) );

		$this->assertFalse( Settings::preview_links_enabled() );
	}

	public function test_defaults_use_the_default_service_id(): void {
		$this->assertSame( 46, Settings::defaults()['service_id'] );
		$this->assertSame( 46, Settings::DEFAULT_SERVICE_ID );
	}

	public function test_sanitize_keeps_a_custom_service_id(): void {
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$out = Settings::sanitize( array( 'service_id' => '51' ) );

		$this->assertSame( 51, $out['service_id'] );
	}

	#[DataProvider( 'provide_unusable_service_ids' )]
	public function test_sanitize_falls_back_to_the_default_service_id( $input ): void {
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		// A blank or negative ServiceId would leave the dropdown with nothing to offer.
		$out = Settings::sanitize( array( 'service_id' => $input ) );

		$this->assertSame( Settings::DEFAULT_SERVICE_ID, $out['service_id'] );
	}

	public static function provide_unusable_service_ids(): array {
		return array(
			'empty string' => array( '' ),
			'zero'         => array( '0' ),
			'negative'     => array( '-3' ),
			'not a number' => array( 'abc' ),
		);
	}

	public function test_service_id_falls_back_when_the_option_is_absent(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 46, Settings::service_id() );
	}

	public function test_service_id_falls_back_when_the_stored_value_is_empty(): void {
		Functions\when( 'get_option' )->justReturn( array( 'service_id' => 0 ) );

		$this->assertSame( 46, Settings::service_id() );
	}

	public function test_service_id_reads_the_stored_value(): void {
		Functions\when( 'get_option' )->justReturn( array( 'service_id' => 51 ) );

		$this->assertSame( 51, Settings::service_id() );
	}
}
