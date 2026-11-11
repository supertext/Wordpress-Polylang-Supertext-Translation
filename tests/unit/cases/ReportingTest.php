<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use PHPUnit\Framework\TestCase;
use Supertext\Polylang\PluginHealth\KeyCheck;
use Supertext\Polylang\Reporting\Reporting;
use Supertext\Polylang\Sdk\Http\HttpRequest;
use Supertext\Polylang\Sdk\Http\HttpResponse;
use Supertext\Polylang\Sdk\Http\HttpTransport;
use Supertext\Polylang\Sdk\Http\TransportException;
use Supertext\Polylang\Sdk\Supertext;

/**
 * The Commando Center glue, with a fake transport: never reaches the live center.
 */
class ReportingTest extends TestCase {
	const REPORT_URL = 'http://127.0.0.1:9/report';
	const API        = 'https://api.example.test/v1/';
	const KEY        = 'secret-key-123';
	const ORDERS     = 'https://orders.example.test/';
	const ORDER_KEY  = 'order-secret-456';
	const VIBEBOOST  = 'https://shots.example.test/api/screenshot';
	const SHOTS_KEY  = 'vibe_secret789';

	/** @var array<int, HttpRequest> */
	private $requests = array();

	/** @var int */
	private $features_status = 200;

	/** @var bool */
	private $center_down = false;

	/** @var string|null */
	private $stored = null;

	/** @var int */
	private $orders_status = 200;

	/** @var bool */
	private $orders_down = false;

	/** @var array{0: int, 1: string}|null */
	private $vibeboost = null;

	private function transport(): HttpTransport {
		return new class( $this ) implements HttpTransport {
			private $test;

			public function __construct( $test ) {
				$this->test = $test;
			}

			public function send( HttpRequest $request ): HttpResponse {
				return $this->test->answer( $request );
			}
		};
	}

	/** @internal */
	public function answer( HttpRequest $request ): HttpResponse {
		$this->requests[] = $request;
		if ( self::REPORT_URL === $request->url ) {
			if ( $this->center_down ) {
				throw new TransportException( 'connection refused' );
			}
			return new HttpResponse( 202, '{"ok":true}', array() );
		}
		if ( str_starts_with( $request->url, self::ORDERS . 'api/v1/util/ordertype/' ) ) {
			if ( $this->orders_down ) {
				throw new TransportException( 'timeout' );
			}
			return new HttpResponse( $this->orders_status, '[]', array() );
		}
		if ( str_starts_with( $request->url, self::VIBEBOOST ) ) {
			if ( null === $this->vibeboost ) {
				throw new TransportException( 'timeout' );
			}
			return new HttpResponse( $this->vibeboost[0], $this->vibeboost[1], array() );
		}
		if ( str_starts_with( $request->url, self::API . 'features' ) ) {
			return new HttpResponse( $this->features_status, 401 === $this->features_status ? '{"error_code":"UNAUTHORIZED"}' : '[]', array() );
		}
		return new HttpResponse( 404, '', array() );
	}

	private function reporting( bool $enabled = true, string $version = '1.0.0', ?string $site = 'https://www.example.ch/' ): Reporting {
		return new Reporting(
			enabled: $enabled,
			site_url: $site,
			plugin_version: $version,
			system_version: '6.9',
			callback_url: null !== $site ? $site . 'wp-json/supertext/v1/ping' : null,
			api_base_url: self::API,
			transport: $this->transport(),
			load: fn() => $this->stored,
			save: function ( string $json ) {
				$this->stored = $json;
			},
			report_url: self::REPORT_URL,
			now: static fn() => 1791900000.0,
			orders_base_url: self::ORDERS,
			orders_service_id: 7,
			screenshots_endpoint: self::VIBEBOOST,
		);
	}

	/** @return array<string, string> */
	private static function both_keys( string $ai = self::KEY, string $email = 'shop@example.ch', string $order_key = self::ORDER_KEY ): array {
		return Reporting::keys( $ai, $email, $order_key );
	}

	/** @return array<int, array<string, mixed>> */
	private function reports(): array {
		$out = array();
		foreach ( $this->requests as $request ) {
			if ( self::REPORT_URL === $request->url ) {
				$out[] = json_decode( (string) $request->body, true );
			}
		}
		return $out;
	}

	public function test_first_save_reports_installed_with_the_key_check_and_never_the_key(): void {
		$result = $this->reporting()->after_settings_saved( self::KEY );

		$this->assertNotNull( $result );
		$this->assertSame( KeyCheck::OK, $result->check->status );
		$this->assertSame( 'installed', $result->event );
		$this->assertTrue( $result->sent );

		$report = $this->reports()[0];
		$this->assertSame( 'supertext-wordpress-polylang', $report['plugin']['name'] );
		$this->assertSame( '1.0.0', $report['plugin']['version'] );
		$this->assertSame( array( 'name' => 'WordPress', 'version' => '6.9' ), $report['system'] );
		$this->assertSame( 'https://www.example.ch/', $report['site_url'] );
		$this->assertSame( 'https://www.example.ch/wp-json/supertext/v1/ping', $report['callback']['url'] );
		$this->assertSame( 'ok', $report['api_key_check']['status'] );
		$this->assertTrue( $report['api_key_configured'] );
		$this->assertSame( Supertext::SDK_VERSION, $report['sdk_version'] );
		foreach ( $this->requests as $request ) {
			if ( self::REPORT_URL === $request->url ) {
				$this->assertStringNotContainsString( self::KEY, (string) $request->body );
			}
		}
		$this->assertStringNotContainsString( self::KEY, (string) $this->stored );
	}

	public function test_the_key_check_is_the_sdks_against_the_chosen_environment(): void {
		$this->reporting( false )->after_settings_saved( self::KEY );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'GET', $this->requests[0]->method );
		$this->assertStringStartsWith( self::API . 'features', $this->requests[0]->url );
		$this->assertStringContainsString( 'supertext-wordpress-polylang/1.0.0', $this->requests[0]->headers['User-Agent'] ?? '' );
	}

	public function test_state_stored_by_the_sdk_0_2_reporter_is_kept(): void {
		// What the Supertext SDK 0.2 reporter stored before the switch to Plugin Health.
		$fingerprint  = hash( 'sha256', self::KEY );
		$this->stored = '{"reportedVersions":"1.0.0|6.9|' . PHP_VERSION . '","reportedKeyStatus":"ok","lastFailureAt":null,'
			. '"keyCheck":{"status":"ok","checkedAt":"2026-10-13T08:00:00Z","httpStatus":200,"errorCode":null,"fingerprint":"' . $fingerprint . '"}}';

		$this->assertSame( '2026-10-13T08:00:00Z', $this->reporting()->last_check()->checkedAt );
		$this->assertNull( $this->reporting()->on_request( self::KEY )->event, 'no new installed report after the update' );
		$this->assertSame( array(), $this->requests );

		$this->reporting()->after_settings_saved( self::KEY );
		$this->assertSame( 'settings_saved', $this->reports()[0]['event'] );
		$this->assertSame(
			array( 'reportedVersions', 'reportedKeyStatus', 'lastFailureAt', 'keyCheck', 'keyChecks', 'reportedKeyStatuses' ),
			array_keys( json_decode( (string) $this->stored, true ) ),
			'same stored format'
		);
	}

	public function test_second_save_reports_settings_saved(): void {
		$this->reporting()->after_settings_saved( self::KEY );
		$result = $this->reporting()->after_settings_saved( self::KEY );

		$this->assertSame( 'settings_saved', $result->event );
	}

	public function test_on_request_reports_only_upgrades(): void {
		$this->reporting()->after_settings_saved( self::KEY );
		$this->requests = array();

		$this->assertNull( $this->reporting()->on_request( self::KEY )->event, 'nothing changed' );
		$this->assertSame( array(), $this->requests, 'the same key is not checked again' );

		$result = $this->reporting( true, '1.1.0' )->on_request( self::KEY );
		$this->assertSame( 'upgraded', $result->event );
	}

	public function test_rejected_key_and_authentication_failure(): void {
		$this->features_status = 401;
		$result                = $this->reporting()->after_settings_saved( self::KEY );
		$this->assertSame( KeyCheck::INVALID, $result->check->status );

		$this->features_status = 200;
		$this->reporting()->test_connection( self::KEY );
		$result = $this->reporting()->authentication_failed( self::KEY, 403 );
		$this->assertSame( KeyCheck::INVALID, $result->check->status );
		$this->assertSame( 403, $result->check->httpStatus );
		$this->assertSame( 'api_key_check', $result->event );
	}

	public function test_missing_key_is_checked_without_a_request_to_supertext(): void {
		$result = $this->reporting()->after_settings_saved( '' );

		$this->assertSame( KeyCheck::MISSING, $result->check->status );
		foreach ( $this->requests as $request ) {
			$this->assertSame( self::REPORT_URL, $request->url );
		}
	}

	public function test_off_means_no_report_but_the_key_check_still_runs(): void {
		$result = $this->reporting( false )->after_settings_saved( self::KEY );

		$this->assertSame( KeyCheck::OK, $result->check->status );
		$this->assertFalse( $result->sent );
		$this->assertSame( array(), $this->reports() );
	}

	public function test_without_a_site_url_nothing_is_reported(): void {
		$this->reporting( true, '1.0.0', null )->after_settings_saved( self::KEY );

		$this->assertSame( array(), $this->reports() );
	}

	public function test_unreachable_center_never_throws_and_is_not_retried_within_the_hour(): void {
		$this->center_down = true;
		$result            = $this->reporting()->after_settings_saved( self::KEY );
		$this->assertFalse( $result->sent );

		$this->requests = array();
		$this->reporting( true, '1.1.0' )->on_request( self::KEY );
		$this->assertSame( array(), $this->reports(), 'no new attempt for an hour' );
	}

	public function test_unreadable_state_is_empty(): void {
		$this->stored = '{not json';
		$this->assertNull( $this->reporting()->last_check() );

		$this->reporting()->after_settings_saved( self::KEY );
		$this->assertSame( KeyCheck::OK, $this->reporting()->last_check()->status );
	}

	public function test_ping(): void {
		$ok = Reporting::ping( 'abcdefghijklmnop', true );
		$this->assertSame( 200, $ok->status );
		$this->assertSame( array( 'ok' => true, 'plugin' => 'supertext-wordpress-polylang', 'challenge' => 'abcdefghijklmnop' ), json_decode( $ok->body, true ) );
		$this->assertSame( 'no-store', $ok->headers['Cache-Control'] );

		$this->assertSame( 400, Reporting::ping( '<script>', true )->status );
		$this->assertSame( 400, Reporting::ping( null, true )->status );
		$this->assertSame( 404, Reporting::ping( 'abcdefghijklmnop', false )->status );
	}

	public function test_environment_wins_over_the_setting(): void {
		putenv( 'SUPERTEXT_VERSION_REPORTS=0' );
		try {
			$this->assertFalse( Reporting::enabled( true ) );
			$this->assertTrue( Reporting::forced_by_environment() );
		} finally {
			putenv( 'SUPERTEXT_VERSION_REPORTS' );
		}
		$this->assertTrue( Reporting::enabled( null ), 'on by default' );
		$this->assertFalse( Reporting::enabled( false ) );
		$this->assertFalse( Reporting::forced_by_environment() );
	}

	public function test_keys_always_report_professional_orders_missing_until_both_credentials_are_set(): void {
		$this->assertSame( array( Reporting::KEY_AI, Reporting::KEY_ORDERS ), array_keys( Reporting::keys( self::KEY, ' ', '' ) ) );
		$this->assertSame( '', Reporting::keys( self::KEY, ' ', '' )[ Reporting::KEY_ORDERS ], 'none set: missing' );
		$this->assertSame( '', Reporting::keys( self::KEY, 'shop@example.ch', '' )[ Reporting::KEY_ORDERS ], 'half the credentials: missing' );
		$this->assertNotSame( '', Reporting::keys( self::KEY, 'shop@example.ch', self::ORDER_KEY )[ Reporting::KEY_ORDERS ] );
	}

	public function test_both_keys_are_checked_and_reported_by_name_and_never_sent(): void {
		$result = $this->reporting()->after_settings_saved( self::both_keys() );

		$this->assertSame( KeyCheck::OK, $result->checks[ Reporting::KEY_AI ]->status );
		$this->assertSame( KeyCheck::OK, $result->checks[ Reporting::KEY_ORDERS ]->status );
		$report = $this->reports()[0];
		$this->assertSame( array( 'AI translation', 'Professional orders' ), array_column( $report['api_keys'], 'name' ) );
		$this->assertArrayNotHasKey( 'api_key_check', $report );

		$orders = array_values( array_filter( $this->requests, static fn( $r ) => str_starts_with( $r->url, self::ORDERS ) ) );
		$this->assertCount( 1, $orders );
		$this->assertSame( 'GET', $orders[0]->method );
		$this->assertSame( self::ORDERS . 'api/v1/util/ordertype/7', $orders[0]->url );
		$this->assertSame( 'Basic ' . base64_encode( 'shop@example.ch:' . self::ORDER_KEY ), $orders[0]->headers['Authorization'] );
		foreach ( $this->requests as $request ) {
			if ( self::REPORT_URL === $request->url ) {
				$this->assertStringNotContainsString( self::ORDER_KEY, (string) $request->body );
				$this->assertStringNotContainsString( 'shop@example.ch', (string) $request->body );
			}
		}
		$this->assertStringNotContainsString( self::ORDER_KEY, (string) $this->stored );
		$this->assertStringNotContainsString( 'shop@example.ch', (string) $this->stored );
		$this->assertSame( KeyCheck::OK, $this->reporting()->last_check( Reporting::KEY_ORDERS )->status );
	}

	public function test_vibeboost_check_results_without_capturing_anything(): void {
		$this->vibeboost = array( 500, '{"error":"Screenshot failed"}' );
		$check           = $this->reporting()->check_screenshots( self::SHOTS_KEY );
		$this->assertSame( KeyCheck::OK, $check->status, 'key accepted, the capture of the .invalid address failed' );
		$request = end( $this->requests );
		$this->assertSame( 'Bearer ' . self::SHOTS_KEY, $request->headers['Authorization'] );
		$this->assertStringContainsString( 'url=' . rawurlencode( Reporting::SCREENSHOTS_PROBE_URL ), $request->url );
		$this->assertStringContainsString( 'fullPage=true', $request->url );

		$this->vibeboost = array( 401, '{"error":"Full-page capture is a Pro feature."}' );
		$this->assertSame( KeyCheck::INVALID, $this->reporting()->check_screenshots( self::SHOTS_KEY )->status );
		$this->vibeboost = array( 429, '{}' );
		$this->assertSame( KeyCheck::OK, $this->reporting()->check_screenshots( self::SHOTS_KEY )->status );
		$this->vibeboost = array( 502, 'Bad gateway' );
		$this->assertSame( KeyCheck::UNREACHABLE, $this->reporting()->check_screenshots( self::SHOTS_KEY )->status );
		$this->vibeboost = array( 400, '{}' );
		$this->assertSame( KeyCheck::ERROR, $this->reporting()->check_screenshots( self::SHOTS_KEY )->status );
		$this->vibeboost = null;
		$this->assertSame( KeyCheck::UNREACHABLE, $this->reporting()->check_screenshots( self::SHOTS_KEY )->status );
		$this->requests = array();
		$this->assertSame( KeyCheck::MISSING, $this->reporting()->check_screenshots( ' ' )->status );
		$this->assertSame( array(), $this->requests, 'no key: no request' );
	}

	public function test_the_vibeboost_key_is_reported_by_name_and_never_sent(): void {
		$this->vibeboost = array( 500, '{"error":"Screenshot failed"}' );
		$result          = $this->reporting()->after_settings_saved( Reporting::keys( self::KEY, '', '', self::SHOTS_KEY ) );

		$this->assertSame( KeyCheck::OK, $result->checks[ Reporting::KEY_SCREENSHOTS ]->status );
		$this->assertSame( array( 'AI translation', 'Professional orders', 'Page screenshots (VibeBoost)' ), array_column( $this->reports()[0]['api_keys'], 'name' ) );
		foreach ( $this->requests as $request ) {
			if ( self::REPORT_URL === $request->url ) {
				$this->assertStringNotContainsString( self::SHOTS_KEY, (string) $request->body );
			}
		}
		$this->assertStringNotContainsString( self::SHOTS_KEY, (string) $this->stored );
		$this->assertArrayNotHasKey( Reporting::KEY_SCREENSHOTS, Reporting::keys( self::KEY, '', '' ), 'not used: not reported' );
	}

	public function test_orders_check_results(): void {
		$this->orders_status = 401;
		$this->assertSame( KeyCheck::INVALID, $this->reporting()->check_orders( 'shop@example.ch', self::ORDER_KEY )->status );
		$this->orders_status = 503;
		$this->assertSame( KeyCheck::UNREACHABLE, $this->reporting()->check_orders( 'shop@example.ch', self::ORDER_KEY )->status );
		$this->orders_status = 404;
		$check = $this->reporting()->check_orders( 'shop@example.ch', self::ORDER_KEY );
		$this->assertSame( KeyCheck::ERROR, $check->status );
		$this->assertSame( 404, $check->httpStatus );
		$this->orders_down = true;
		$this->assertSame( KeyCheck::UNREACHABLE, $this->reporting()->check_orders( 'shop@example.ch', self::ORDER_KEY )->status );
		$this->requests = array();
		$this->assertSame( KeyCheck::MISSING, $this->reporting()->check_orders( '', self::ORDER_KEY )->status );
		$this->assertSame( array(), $this->requests, 'missing credentials: no request' );
	}

	public function test_a_changed_order_key_is_checked_again_and_a_rejected_order_marks_only_that_key(): void {
		$this->reporting()->after_settings_saved( self::both_keys() );
		$this->requests = array();
		$this->assertNull( $this->reporting()->on_request( self::both_keys() )->event );
		$this->assertSame( array(), $this->requests );

		$this->orders_status = 401;
		$result              = $this->reporting()->on_request( self::both_keys( self::KEY, 'shop@example.ch', 'new-key' ) );
		$this->assertSame( 'api_key_check', $result->event );
		$this->assertSame( KeyCheck::INVALID, $result->checks[ Reporting::KEY_ORDERS ]->status );
		$this->assertSame( KeyCheck::OK, $result->checks[ Reporting::KEY_AI ]->status );

		$this->orders_status = 200;
		$this->reporting()->test_connection( self::both_keys() );
		$result = $this->reporting()->authentication_failed( self::both_keys(), 403, Reporting::KEY_ORDERS );
		$this->assertSame( KeyCheck::INVALID, $result->checks[ Reporting::KEY_ORDERS ]->status );
		$this->assertSame( KeyCheck::OK, $result->checks[ Reporting::KEY_AI ]->status );
	}

	public function test_a_single_key_state_counts_as_the_ai_key(): void {
		$this->reporting()->after_settings_saved( self::KEY );
		$this->assertSame( array( Reporting::KEY_AI ), array_keys( $this->reporting()->last_checks() ) );
		$this->assertNull( $this->reporting()->last_check( Reporting::KEY_ORDERS ) );

		$result = $this->reporting()->on_request( self::both_keys() );
		$this->assertSame( 'api_key_check', $result->event, 'switching to named keys is reported' );
	}

	public function test_send_extensions(): void {
		$this->reporting()->after_settings_saved( self::both_keys() );
		$result = $this->reporting()->send_extensions(
			array(
				array( 'name' => 'Polylang Pro', 'version' => '3.7.2', 'active' => true, 'type' => 'plugin' ),
				array( 'name' => 'Twenty Twenty-Five', 'version' => '1.2', 'active' => false, 'type' => 'theme' ),
			),
			array( array( 'code' => 'de_CH', 'supertext' => 'de-CH' ), array( 'code' => 'en_IE' ) )
		);
		$this->assertTrue( $result->sent );
		$this->assertSame( 'extensions', $result->event );
		$report = $this->reports()[1];
		$this->assertSame( 'extensions', $report['event'] );
		$this->assertSame( 'Polylang Pro', $report['extensions'][0]['name'] );
		$this->assertFalse( $report['extensions'][1]['active'] );
		$this->assertArrayHasKey( 'api_keys', $report );
		$this->assertSame( array( array( 'code' => 'de_CH', 'supertext' => 'de-CH' ), array( 'code' => 'en_IE', 'supertext' => null ) ), $report['languages'] );

		// Other reports never carry the languages.
		$this->reporting()->report_check();
		$this->assertArrayNotHasKey( 'languages', $this->reports()[2] );

		$this->requests = array();
		$this->assertFalse( $this->reporting( false )->send_extensions( array( array( 'name' => 'X' ) ) )->sent );
		$this->assertSame( array(), $this->reports(), 'reporting off: nothing is sent' );
	}
}
