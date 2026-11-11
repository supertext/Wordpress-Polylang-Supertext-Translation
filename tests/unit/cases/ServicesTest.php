<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use Brain\Monkey\Functions;
use Supertext\Polylang\Human_Translation\Services;
use Supertext\Polylang\Tests\TestCase;

class ServicesTest extends TestCase {
	/** A real `api/v1/util/ordertype/46` response (trimmed descriptions). */
	private const API_RESPONSE = array(
		array(
			'Name'                     => 'Translation BASIC',
			'DeliveryOptions'          => null,
			'Description'              => "Machine translation with full post-editing by a qualified translator. \r\n\r\nStraightforward and accurate.",
			'ShortDescription'         => 'For general texts with a limited reach',
			'OrderTypeConfigurationId' => 166,
			'OrderTypeId'              => 54,
		),
		array(
			'Name'                     => 'Translation PREMIUM',
			'DeliveryOptions'          => null,
			'Description'              => 'Specialized translation with a review by a second translator.',
			'ShortDescription'         => 'For demanding content where every word counts',
			'OrderTypeConfigurationId' => 167,
			'OrderTypeId'              => 55,
		),
		array(
			'Name'                     => 'Translation CREATIVE',
			'DeliveryOptions'          => null,
			'Description'              => 'Clever, inventive translation (transcreation).',
			'ShortDescription'         => 'For creative content, newsletters, campaigns, etc.',
			'OrderTypeConfigurationId' => 168,
			'OrderTypeId'              => 56,
		),
	);

	/**
	 * The back-end language the stubbed WordPress reports (the order types are
	 * cached per language). A test may change it before acting.
	 *
	 * @var string
	 */
	protected $locale = 'en_US';

	protected function setUp(): void {
		parent::setUp();

		// The per-request memo is static — clear it so tests don't bleed into each other.
		$memo = new \ReflectionProperty( Services::class, 'memo' );
		$memo->setAccessible( true );
		$memo->setValue( null, array() );

		Functions\when( 'get_user_locale' )->alias( fn() => $this->locale );
		Functions\when( 'get_locale' )->alias( fn() => $this->locale );
	}

	/**
	 * Stubs the stored settings. Call once per test (Brain Monkey replaces the
	 * function, so stubbing the same one twice in a test is a trap).
	 *
	 * @param array $overrides Settings to merge over the configured defaults.
	 * @return void
	 */
	private function stub_settings( array $overrides = array() ): void {
		$settings = array_merge(
			array(
				'environment'   => 'live',
				'human_email'   => 'api@example.com',
				'human_api_key' => 'secret',
				'service_id'    => 46,
			),
			$overrides
		);

		Functions\when( 'get_option' )->justReturn( $settings );
	}

	// --- parse() --------------------------------------------------------------

	public function test_parse_maps_the_api_response_by_configuration_id(): void {
		$parsed = Services::parse( self::API_RESPONSE );

		$this->assertSame( array( 166, 167, 168 ), array_keys( $parsed ) );
		$this->assertSame( 'Translation PREMIUM', $parsed[167]['label'] );
		$this->assertSame( 55, $parsed[167]['order_type_id'] );
		$this->assertSame( 'For demanding content where every word counts', $parsed[167]['short'] );
		$this->assertStringContainsString( 'second translator', $parsed[167]['description'] );
	}

	public function test_parse_skips_entries_without_a_configuration_id(): void {
		$parsed = Services::parse(
			array(
				array( 'Name' => 'No id', 'OrderTypeId' => 99 ),
				array( 'Name' => 'Zero id', 'OrderTypeConfigurationId' => 0 ),
				'not an array',
				array( 'Name' => 'Good', 'OrderTypeConfigurationId' => 170, 'OrderTypeId' => 60 ),
			)
		);

		$this->assertSame( array( 170 ), array_keys( $parsed ) );
	}

	public function test_parse_falls_back_to_the_id_when_the_name_is_missing(): void {
		$parsed = Services::parse( array( array( 'OrderTypeConfigurationId' => 171, 'Name' => '  ' ) ) );

		$this->assertSame( '171', $parsed[171]['label'] );
		$this->assertSame( 0, $parsed[171]['order_type_id'] );
	}

	// --- all() ----------------------------------------------------------------

	public function test_all_returns_the_cached_list_without_an_http_request(): void {
		$this->stub_settings();

		$cached = array( 200 => array( 'label' => 'Cached', 'order_type_id' => 70, 'description' => '', 'short' => '' ) );

		Functions\when( 'get_transient' )->justReturn( $cached );
		Functions\when( 'wp_remote_request' )->alias(
			static function () {
				throw new \RuntimeException( 'No HTTP request expected when the list is cached' );
			}
		);

		$this->assertSame( $cached, Services::all() );
	}

	public function test_all_fetches_parses_and_caches_the_list(): void {
		$this->stub_settings();
		Functions\when( 'get_transient' )->justReturn( false );

		$url = '';
		Functions\when( 'wp_remote_request' )->alias(
			static function ( $request_url, $args ) use ( &$url ) {
				$url = $request_url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( self::API_RESPONSE ),
				);
			}
		);

		$ttl = 0;
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $expiration ) use ( &$ttl ) {
				$ttl = $expiration;
				return true;
			}
		);

		$services = Services::all();

		$this->assertSame( 'https://www.supertext.com/api/v1/util/ordertype/46', $url );
		$this->assertSame( array( 166, 167, 168 ), array_keys( $services ) );
		$this->assertSame( Services::CACHE_TTL, $ttl, 'A successful fetch should be cached for the full TTL' );
	}

	public function test_all_falls_back_to_the_built_in_list_when_the_api_fails(): void {
		$this->stub_settings();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->justReturn(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => 'Internal Server Error',
			)
		);

		$ttl = 0;
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $expiration ) use ( &$ttl ) {
				$ttl = $expiration;
				return true;
			}
		);

		$services = Services::all();

		$this->assertSame( Services::FALLBACK, $services, 'The dropdown must never be empty' );
		$this->assertSame( Services::CACHE_TTL_FAILED, $ttl, 'A failed fetch should be retried soon' );
	}

	public function test_all_falls_back_without_a_request_when_credentials_are_missing(): void {
		$this->stub_settings( array( 'human_email' => '', 'human_api_key' => '' ) );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->alias(
			static function () {
				throw new \RuntimeException( 'No HTTP request expected without credentials' );
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function () {
				throw new \RuntimeException( 'Nothing should be cached without credentials' );
			}
		);

		$this->assertSame( Services::FALLBACK, Services::all() );
	}

	public function test_all_falls_back_when_the_response_has_no_usable_entries(): void {
		$this->stub_settings();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_remote_request' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '[]',
			)
		);

		$this->assertSame( Services::FALLBACK, Services::all() );
	}

	public function test_all_is_memoized_within_a_request(): void {
		$this->stub_settings();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$calls = 0;
		Functions\when( 'wp_remote_request' )->alias(
			static function () use ( &$calls ) {
				++$calls;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( self::API_RESPONSE ),
				);
			}
		);

		Services::all();
		Services::all();
		Services::order_type_id( 166 );

		$this->assertSame( 1, $calls, 'The list should be fetched at most once per request' );
	}

	// --- lookups --------------------------------------------------------------

	public function test_exists_gates_on_the_loaded_list(): void {
		$this->stub_settings();
		Functions\when( 'get_transient' )->justReturn(
			array( 300 => array( 'label' => 'Only one', 'order_type_id' => 80, 'description' => '', 'short' => '' ) )
		);

		$this->assertTrue( Services::exists( 300 ) );
		$this->assertFalse( Services::exists( 166 ), 'A type the ServiceId does not offer must be rejected' );
	}

	public function test_order_type_id_and_label_read_the_loaded_list(): void {
		$this->stub_settings();
		Functions\when( 'get_transient' )->justReturn( Services::parse( self::API_RESPONSE ) );

		$this->assertSame( 56, Services::order_type_id( 168 ) );
		$this->assertSame( 'Translation CREATIVE', Services::label( 168 ) );
	}

	public function test_label_falls_back_for_a_type_no_longer_offered(): void {
		// An order placed earlier (or under another ServiceId) still has to render.
		$this->stub_settings();
		Functions\when( 'get_transient' )->justReturn(
			array( 300 => array( 'label' => 'Only one', 'order_type_id' => 80, 'description' => '', 'short' => '' ) )
		);

		$this->assertSame( 'Translation BASIC', Services::label( 166 ) );
		$this->assertSame( '999', Services::label( 999 ) );
	}

	public function test_the_cached_list_is_per_language(): void {
		// The API localizes the type names, so one admin's German list must not be
		// served to an admin working in French.
		$this->stub_settings();
		$keys = array();
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$keys ) {
				$keys[] = $key;
				return false;
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_remote_request' )->justReturn(
			array( 'response' => array( 'code' => 200 ), 'body' => '[]' )
		);

		$this->locale = 'de_DE';
		Services::all();

		// A different language means a different key, so this re-reads rather than
		// hitting the memo of the call above.
		$this->locale = 'fr_FR';
		Services::all();

		$this->assertSame(
			array( 'supertext_polylang_order_types_live_46_de-de', 'supertext_polylang_order_types_live_46_fr-fr' ),
			$keys
		);
	}
}
