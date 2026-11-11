<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use Brain\Monkey\Functions;
use Supertext\Polylang\PluginHealth\KeyCheck;
use Supertext\Polylang\Admin\Settings;
use Supertext\Polylang\Reporting\Commando;
use Supertext\Polylang\Reporting\Reporting;
use Supertext\Polylang\Sdk\Http\HttpRequest;
use Supertext\Polylang\Sdk\Http\HttpResponse;
use Supertext\Polylang\Sdk\Http\HttpTransport;
use Supertext\Polylang\Tests\TestCase;

/**
 * WordPress side of the Commando Center: options, ping, messages, once-per-request.
 */
class CommandoTest extends TestCase {
	/** @var array<string, mixed> */
	private $options = array();

	/** @var array<int, HttpRequest> */
	public $requests = array();

	protected function setUp(): void {
		parent::setUp();
		putenv( 'SUPERTEXT_REPORT_URL=http://127.0.0.1:9/report' );
		Commando::reset();
		$this->options = array(
			'polylang' => array(
				'machine_translation_services' => array(
					'supertext' => array(
						'api_key'  => 'the-key',
						'endpoint' => '',
					),
				),
			),
		);
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://www.example.ch/' );
		Functions\when( 'rest_url' )->alias( static fn( $path ) => 'https://www.example.ch/wp-json/' . $path );
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );
		Functions\when( 'add_action' )->justReturn( true );

		$test                = $this;
		Commando::$transport = new class( $test ) implements HttpTransport {
			private $test;

			public function __construct( $test ) {
				$this->test = $test;
			}

			public function send( HttpRequest $request ): HttpResponse {
				$this->test->requests[] = $request;
				return str_contains( $request->url, '/report' )
					? new HttpResponse( 202, '{"ok":true}', array() )
					: new HttpResponse( 200, '[]', array() );
			}
		};
	}

	protected function tearDown(): void {
		Commando::$transport = null;
		Commando::reset();
		putenv( 'SUPERTEXT_REPORT_URL' );
		parent::tearDown();
	}

	public function test_after_settings_saved_checks_the_stored_key_and_stores_the_state(): void {
		$check = Commando::after_settings_saved();

		$this->assertSame( KeyCheck::OK, $check->status );
		$this->assertStringStartsWith( 'https://api.supertext.com/v1/features', $this->requests[0]->url );
		$this->assertSame( 'http://127.0.0.1:9/report', $this->requests[1]->url );
		$this->assertIsString( $this->options[ Commando::STATE_OPTION ] );
		$this->assertStringNotContainsString( 'the-key', $this->options[ Commando::STATE_OPTION ] );
		$this->assertSame( KeyCheck::OK, Commando::last_check()->status );

		$this->assertNull( Commando::after_settings_saved(), 'once per request' );
	}

	public function test_the_key_being_saved_and_its_environment_are_used(): void {
		Commando::after_settings_saved( 'new-key', 'https://api.staging.supertext.com/v1/' );

		$this->assertStringStartsWith( 'https://api.staging.supertext.com/v1/features', $this->requests[0]->url );
		$this->assertSame( 'Supertext-Auth-Key new-key', $this->requests[0]->headers['Authorization'] );
	}

	public function test_version_reports_off_sends_no_report(): void {
		$this->options['supertext_polylang_settings'] = array( 'version_reports' => false );

		Commando::after_settings_saved();

		$this->assertCount( 1, $this->requests, 'only the key check' );
	}

	public function test_ping_answers_with_the_sdk_response(): void {
		$request = new class() {
			public function get_param( $name ) {
				return 'challenge' === $name ? 'abcdefghijklmnop' : null;
			}
		};
		$response = Commando::handle_ping( $request );

		$this->assertSame( 200, $response->status );
		$this->assertSame( array( 'ok' => true, 'plugin' => 'supertext-wordpress-polylang', 'challenge' => 'abcdefghijklmnop' ), $response->data );
		$this->assertSame( 'no-store', $response->headers['Cache-Control'] );

		$this->options['supertext_polylang_settings'] = array( 'version_reports' => false );
		$this->assertSame( 404, Commando::handle_ping( $request )->status );
	}

	public function test_messages_carry_both_links_for_invalid_and_missing(): void {
		foreach ( array( KeyCheck::INVALID, KeyCheck::MISSING ) as $status ) {
			$html = Commando::message( new KeyCheck( $status, '2026-10-14T08:00:00Z' ), true );
			$this->assertSame( 'error', $html['type'] );
			$this->assertStringContainsString( 'href="https://www.supertext.com/person/en/account/signin" target="_blank" rel="noopener"', $html['text'] );
			$this->assertStringContainsString( 'href="https://www.supertext.com/en/integrations/api" target="_blank" rel="noopener"', $html['text'] );

			$plain = Commando::message( new KeyCheck( $status, '2026-10-14T08:00:00Z' ), false );
			$this->assertStringNotContainsString( '<a', $plain['text'] );
			$this->assertStringContainsString( 'https://www.supertext.com/en/integrations/api', $plain['text'] );
		}
		$this->assertSame( 'success', Commando::message( new KeyCheck( KeyCheck::OK, '2026-10-14T08:00:00Z' ), true )['type'] );
		$error = Commando::message( new KeyCheck( KeyCheck::ERROR, '2026-10-14T08:00:00Z', 500 ), true );
		$this->assertStringContainsString( '(500)', $error['text'] );
	}

	public function test_polylang_save_without_supertext_change_does_nothing(): void {
		$same = array( 'machine_translation_services' => array( 'supertext' => array( 'api_key' => 'a' ) ) );
		Commando::polylang_options_updated( $same, $same );
		Commando::run_deferred();
		$this->assertSame( array(), $this->requests );

		Commando::polylang_options_updated( $same, array( 'machine_translation_services' => array( 'supertext' => array( 'api_key' => 'b' ) ) ) );
		Commando::run_deferred();
		$this->assertNotEmpty( $this->requests );
	}

	public function test_authentication_failure_wins_over_on_request(): void {
		Commando::after_settings_saved();
		Commando::reset();
		$this->requests = array();

		Commando::authentication_failed( 401 );
		Commando::on_request();
		Commando::run_deferred();

		$this->assertSame( KeyCheck::INVALID, Commando::last_check()->status );
		$this->assertCount( 1, $this->requests, 'the api_key_check report, no request to Supertext' );
	}

	public function test_professional_orders_credentials_are_checked_and_reported_by_name(): void {
		$this->options['supertext_polylang_settings'] = array(
			'human_email'         => 'shop@example.ch',
			'human_api_key'       => 'order-key',
			'screenshots_enabled' => false,
		);

		$checks = Commando::test_connection();

		$this->assertSame( array( Reporting::KEY_AI, Reporting::KEY_ORDERS ), array_keys( $checks ) );
		$this->assertSame( KeyCheck::OK, $checks[ Reporting::KEY_ORDERS ]->status );
		$this->assertSame( 'https://www.supertext.com/api/v1/util/ordertype/' . Settings::service_id(), $this->requests[1]->url );
		$this->assertSame( KeyCheck::OK, Commando::last_check( Reporting::KEY_ORDERS )->status );
		$this->assertCount( 2, Commando::last_checks() );
	}

	public function test_the_vibeboost_key_is_checked_while_page_screenshots_are_on_or_a_key_is_set(): void {
		$this->options['supertext_polylang_settings'] = array( 'screenshots_enabled' => false );
		$this->assertArrayNotHasKey( Reporting::KEY_SCREENSHOTS, Commando::keys() );

		$this->options['supertext_polylang_settings'] = array( 'screenshots_enabled' => true );
		$this->assertSame( '', Commando::keys()[ Reporting::KEY_SCREENSHOTS ], 'on without a key: reported as missing' );

		$this->options['supertext_polylang_settings'] = array(
			'screenshots_enabled' => false,
			'screenshots_api_key' => 'vibe_abc',
		);
		$this->assertSame( Reporting::SCREENSHOTS_PREFIX . 'vibe_abc', Commando::keys()[ Reporting::KEY_SCREENSHOTS ] );

		$checks = Commando::test_connection();
		$this->assertArrayHasKey( Reporting::KEY_SCREENSHOTS, $checks );
		$vibeboost = array_values( array_filter( $this->requests, static fn( $r ) => str_starts_with( $r->url, 'https://vibeboost.me/api/screenshot?' ) ) );
		$this->assertCount( 1, $vibeboost );
		$this->assertSame( 'Bearer vibe_abc', $vibeboost[0]->headers['Authorization'] );
		$this->assertStringContainsString( 'fullPage=true', $vibeboost[0]->url );
		$this->assertStringContainsString( rawurlencode( Reporting::SCREENSHOTS_PROBE_URL ), $vibeboost[0]->url );
	}

	public function test_server_variables_are_used_while_the_fields_are_empty(): void {
		putenv( 'SUPERTEXT_ACCOUNT_EMAIL=env@example.ch' );
		putenv( 'SUPERTEXT_ORDER_API_KEY=env-order-key' );
		putenv( 'VIBEBOOST_API_KEY=vibe_env' );
		try {
			$this->options['supertext_polylang_settings'] = array( 'human_email' => '', 'screenshots_enabled' => true );
			$this->assertSame( 'env@example.ch', Settings::email() );
			$this->assertSame( 'env-order-key', Settings::api_key() );
			$this->assertSame( 'vibe_env', Settings::screenshots_api_key() );
			$keys = Commando::keys();
			$this->assertSame( Reporting::orders_key( 'env@example.ch', 'env-order-key' ), $keys[ Reporting::KEY_ORDERS ] );
			$this->assertSame( Reporting::screenshots_key( 'vibe_env' ), $keys[ Reporting::KEY_SCREENSHOTS ] );

			// A value in the field wins; the settings being saved fall back the same way.
			$this->options['supertext_polylang_settings'] = array( 'human_email' => 'shop@example.ch' );
			$this->assertSame( 'shop@example.ch', Settings::email() );
			$this->assertSame( Reporting::orders_key( 'shop@example.ch', 'env-order-key' ), Commando::keys( null, array( 'human_email' => 'shop@example.ch' ) )[ Reporting::KEY_ORDERS ] );
		} finally {
			putenv( 'SUPERTEXT_ACCOUNT_EMAIL' );
			putenv( 'SUPERTEXT_ORDER_API_KEY' );
			putenv( 'VIBEBOOST_API_KEY' );
		}
		$this->options['supertext_polylang_settings'] = array();
		$this->assertSame( '', Settings::api_key() );
	}

	public function test_environments_set_on_the_server_win_over_the_settings(): void {
		$this->options['supertext_polylang_settings'] = array( 'environment' => 'live' );
		$this->assertNull( \Supertext\Polylang\Machine_Translation\Client::environment_override() );
		$this->assertSame( 'live', Settings::environment() );
		try {
			putenv( 'SUPERTEXT_AI_ENVIRONMENT=Staging' );
			putenv( 'SUPERTEXT_ENVIRONMENT=testing' );
			$this->assertSame( \Supertext\Polylang\Machine_Translation\Client::ENVIRONMENTS['staging'], \Supertext\Polylang\Machine_Translation\Client::environment_override() );
			$this->assertSame( 'testing', Settings::environment(), 'SUPERTEXT_ENVIRONMENT still works' );
			putenv( 'SUPERTEXT_HUMAN_ENVIRONMENT=staging' );
			$this->assertSame( 'staging', Settings::environment(), 'SUPERTEXT_HUMAN_ENVIRONMENT wins' );
			$this->assertSame( Settings::ENVIRONMENTS['staging'], Settings::base_url() );
			putenv( 'SUPERTEXT_AI_ENVIRONMENT=production' );
			putenv( 'SUPERTEXT_HUMAN_ENVIRONMENT=nonsense' );
			putenv( 'SUPERTEXT_ENVIRONMENT' );
			$this->assertNull( \Supertext\Polylang\Machine_Translation\Client::environment_override(), 'unknown values are ignored' );
			$this->assertSame( 'live', Settings::environment() );
		} finally {
			putenv( 'SUPERTEXT_AI_ENVIRONMENT' );
			putenv( 'SUPERTEXT_HUMAN_ENVIRONMENT' );
			putenv( 'SUPERTEXT_ENVIRONMENT' );
		}
	}

	public function test_vibeboost_messages(): void {
		$this->assertSame( 'success', Commando::message( new KeyCheck( KeyCheck::OK, '2026-10-14T08:00:00Z' ), false, Reporting::KEY_SCREENSHOTS )['type'] );
		foreach ( array( KeyCheck::INVALID, KeyCheck::MISSING ) as $status ) {
			$message = Commando::message( new KeyCheck( $status, '2026-10-14T08:00:00Z' ), false, Reporting::KEY_SCREENSHOTS );
			$this->assertSame( 'warning', $message['type'], 'orders still work without screenshots' );
			$this->assertStringContainsString( 'https://vibeboost.me/account', $message['text'] );
		}
		$this->assertSame( 'Page screenshots (VibeBoost API key)', Commando::key_label( Reporting::KEY_SCREENSHOTS ) );
	}

	public function test_orders_messages_point_to_the_account_settings(): void {
		foreach ( array( KeyCheck::INVALID, KeyCheck::MISSING ) as $status ) {
			$html = Commando::message( new KeyCheck( $status, '2026-10-14T08:00:00Z' ), true, Reporting::KEY_ORDERS );
			$this->assertSame( 'error', $html['type'] );
			$this->assertStringContainsString( 'href="https://www.supertext.com/services/customer/accountsettings" target="_blank" rel="noopener"', $html['text'] );
			$this->assertStringContainsString( 'href="https://www.supertext.com/person/en/account/signin" target="_blank" rel="noopener"', $html['text'] );
			$this->assertStringContainsString( 'Order API Key', $html['text'] );
		}
		$ok = Commando::message( new KeyCheck( KeyCheck::OK, '2026-10-14T08:00:00Z' ), true, Reporting::KEY_ORDERS );
		$this->assertSame( 'success', $ok['type'] );
	}

	public function test_installed_extensions_lists_plugins_and_themes_without_paths(): void {
		$theme = new class() {
			private $data;
			public function __construct( array $data = array( 'Name' => 'Twenty Twenty-Five', 'Version' => '1.2' ) ) {
				$this->data = $data;
			}
			public function get( $key ) {
				return $this->data[ $key ] ?? '';
			}
		};
		Functions\when( 'get_plugins' )->justReturn(
			array(
				'polylang-pro/polylang.php' => array( 'Name' => 'Polylang Pro', 'Version' => '3.7.2' ),
				'akismet/akismet.php'       => array( 'Name' => 'Akismet Anti-spam', 'Version' => '5.3' ),
			)
		);
		Functions\when( 'is_plugin_active' )->alias( static fn( $file ) => 'polylang-pro/polylang.php' === $file );
		Functions\when( 'get_mu_plugins' )->justReturn( array( 'host.php' => array( 'Name' => '', 'Version' => '' ) ) );
		Functions\when( 'get_dropins' )->justReturn( array() );
		Functions\when( 'wp_get_themes' )->justReturn( array( 'twentytwentyfive' => $theme ) );
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		Functions\when( 'get_template' )->justReturn( 'twentytwentyfive' );

		$this->assertSame(
			array(
				array( 'name' => 'Polylang Pro', 'version' => '3.7.2', 'active' => true, 'type' => 'plugin' ),
				array( 'name' => 'Akismet Anti-spam', 'version' => '5.3', 'active' => false, 'type' => 'plugin' ),
				array( 'name' => 'host.php', 'version' => '', 'active' => true, 'type' => 'mu-plugin' ),
				array( 'name' => 'Twenty Twenty-Five', 'version' => '1.2', 'active' => true, 'type' => 'theme' ),
			),
			Commando::installed_extensions()
		);

		// Without Polylang: the plugins only, no languages.
		$this->assertSame( array( 'status' => 'sent', 'count' => 4, 'languages' => 0 ), Commando::send_extensions() );
		$report = json_decode( (string) end( $this->requests )->body, true );
		$this->assertSame( 'extensions', $report['event'] );
		$this->assertCount( 4, $report['extensions'] );
		$this->assertSame( array(), $report['languages'] );

		$this->options['supertext_polylang_settings'] = array( 'version_reports' => false );
		$this->requests                               = array();
		$this->assertSame( array( 'status' => 'off', 'count' => 0, 'languages' => 0 ), Commando::send_extensions() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_language_mapping_sends_each_locale_with_its_supertext_code(): void {
		$lang  = static fn( string $slug, string $locale ) => (object) array( 'slug' => $slug, 'locale' => $locale );
		$model = new class( array( $lang( 'de', 'de_CH' ), $lang( 'fr', 'fr_FR' ), $lang( 'ie', 'en_IE' ), $lang( 'formal', 'de_DE_formal' ) ) ) {
			private $list;
			public function __construct( array $list ) {
				$this->list = $list;
			}
			public function get_languages_list() {
				return $this->list;
			}
		};
		$pll   = (object) array(
			'model'   => $model,
			// The administrator mapped fr to fr-CH; en_IE has no mapping and no default.
			'options' => array( 'machine_translation_services' => array( 'supertext' => array( 'languages' => array( 'fr' => 'fr-CH' ) ) ) ),
		);
		Functions\when( 'PLL' )->justReturn( $pll );

		$this->assertSame(
			array(
				array( 'code' => 'de_CH', 'supertext' => 'de-CH' ),
				array( 'code' => 'fr_FR', 'supertext' => 'fr-CH' ),
				array( 'code' => 'en_IE', 'supertext' => null ),
				array( 'code' => 'de_DE_formal', 'supertext' => 'de-DE' ),
			),
			Commando::language_mapping()
		);

		Functions\when( 'get_plugins' )->justReturn( array() );
		Functions\when( 'get_mu_plugins' )->justReturn( array() );
		Functions\when( 'get_dropins' )->justReturn( array() );
		Functions\when( 'wp_get_themes' )->justReturn( array() );
		Functions\when( 'get_stylesheet' )->justReturn( '' );
		Functions\when( 'get_template' )->justReturn( '' );
		$this->assertSame( array( 'status' => 'sent', 'count' => 0, 'languages' => 4 ), Commando::send_extensions() );
		$report = json_decode( (string) end( $this->requests )->body, true );
		$this->assertSame( 'extensions', $report['event'] );
		$this->assertSame( array( 'code' => 'en_IE', 'supertext' => null ), $report['languages'][2] );
	}

	public function test_extensions_messages(): void {
		$this->assertSame( array( 'type' => 'success', 'text' => 'Sent: 12 plugins and themes, 3 languages.' ), Commando::extensions_message( 'sent', 12, 3 ) );
		$this->assertSame( 'warning', Commando::extensions_message( 'off', 0 )['type'] );
		$this->assertStringContainsString( 'Send version information to Supertext', Commando::extensions_message( 'off', 0 )['text'] );
		$this->assertSame( 'error', Commando::extensions_message( 'failed', 0 )['type'] );
	}
}
