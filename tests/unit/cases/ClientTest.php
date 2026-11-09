<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Supertext\Polylang\Human_Translation\Client;
use Supertext\Polylang\Tests\TestCase;

class ClientTest extends TestCase {
	const BASE  = 'https://staging.supertext.com/';
	const EMAIL = 'tester@supertext.com';
	const KEY   = 'legacy-key';

	/**
	 * The back-end language the stubbed WordPress reports. A test may change it
	 * before acting; the stub is registered once, in setUp.
	 *
	 * @var string
	 */
	protected $locale = 'en_US';

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_user_locale' )->alias( fn() => $this->locale );
		Functions\when( 'get_locale' )->alias( fn() => $this->locale );
	}

	/**
	 * Stubs wp_remote_request to capture the request and return a canned response.
	 *
	 * @param int    $code Response status code.
	 * @param string $body Response body.
	 * @return object Holds ->url, ->method, ->headers, ->body after the call.
	 */
	private function captureRequest( int $code, string $body ) {
		$captured = new \stdClass();
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( $captured, $code, $body ) {
				$captured->url     = $url;
				$captured->method  = $args['method'] ?? '';
				$captured->headers = $args['headers'] ?? array();
				$captured->body    = $args['body'] ?? '';
				return array( 'response' => array( 'code' => $code ), 'body' => $body );
			}
		);
		return $captured;
	}

	public function test_upload_file_returns_highest_document_id(): void {
		$cap    = $this->captureRequest( 200, '[{"Id":111},{"Id":222}]' );
		$client = new Client( self::BASE, self::EMAIL, self::KEY );

		$id = $client->upload_file( '<html>x</html>', 'content.html' );

		$this->assertSame( 222, $id );
		$this->assertSame( self::BASE . 'api/v1/files/files', $cap->url );
		$this->assertSame( 'POST', $cap->method );
		$this->assertSame( 'Basic ' . base64_encode( self::EMAIL . ':' . self::KEY ), $cap->headers['Authorization'] );
		$this->assertStringContainsString( 'multipart/form-data; boundary=', $cap->headers['Content-Type'] );
	}

	public function test_upload_file_defaults_to_original_html_document(): void {
		$cap    = $this->captureRequest( 200, '[{"Id":5}]' );
		$client = new Client( self::BASE, self::EMAIL, self::KEY );

		$client->upload_file( '<html>x</html>', 'content.html' );

		$this->assertStringContainsString( 'name="DocumentTypeId"' . "\r\n\r\n" . '1', $cap->body );
		$this->assertStringContainsString( 'Content-Type: text/html', $cap->body );
		$this->assertStringContainsString( 'filename="content.html"', $cap->body );
	}

	public function test_upload_file_reference_uses_document_type_3_and_content_type(): void {
		$cap    = $this->captureRequest( 200, '[{"Id":999}]' );
		$client = new Client( self::BASE, self::EMAIL, self::KEY );

		$id = $client->upload_file( 'PNGBYTES', 'screenshot.png', 'image/png', 3 );

		$this->assertSame( 999, $id );
		$this->assertStringContainsString( 'name="DocumentTypeId"' . "\r\n\r\n" . '3', $cap->body );
		$this->assertStringContainsString( 'Content-Type: image/png', $cap->body );
		$this->assertStringContainsString( 'filename="screenshot.png"', $cap->body );
		$this->assertStringContainsString( 'PNGBYTES', $cap->body );
	}

	public function test_create_order_posts_json_and_returns_decoded(): void {
		$cap    = $this->captureRequest( 200, '[{"Id":715113,"TargetLang":"de-CH"}]' );
		$client = new Client( self::BASE, self::EMAIL, self::KEY );

		$order  = array( 'DeliveryId' => 1, 'OrderName' => 'Test' );
		$result = $client->create_order( $order );

		$this->assertSame( array( array( 'Id' => 715113, 'TargetLang' => 'de-CH' ) ), $result );
		$this->assertSame( self::BASE . 'api/v1.1/translation/order', $cap->url );
		$this->assertSame( 'POST', $cap->method );
		$this->assertSame( json_encode( $order ), $cap->body );
	}

	public function test_download_file_url_encodes_name_and_returns_body(): void {
		$cap    = $this->captureRequest( 200, '<html>translated</html>' );
		$client = new Client( self::BASE, self::EMAIL, self::KEY );

		$body = $client->download_file( 4439915, 'A B.html' );

		$this->assertSame( '<html>translated</html>', $body );
		$this->assertSame( self::BASE . 'storage/file/4439915/A%20B.html', $cap->url );
		$this->assertSame( 'GET', $cap->method );
	}

	public function test_missing_credentials_returns_error_without_request(): void {
		$called = false;
		Functions\when( 'wp_remote_request' )->alias(
			function () use ( &$called ) {
				$called = true;
				return array( 'response' => array( 'code' => 200 ), 'body' => '[]' );
			}
		);

		$client = new Client( self::BASE, '', '' );
		$result = $client->upload_file( '<html></html>', 'content.html' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertFalse( $called, 'No HTTP request should be made without credentials' );
	}

	#[DataProvider( 'provide_locales' )]
	public function test_requests_ask_for_the_back_end_language( string $locale, string $expected ): void {
		$this->locale = $locale;
		$cap          = $this->captureRequest( 200, '[]' );
		$client       = new Client( self::BASE, self::EMAIL, self::KEY );

		$client->get_order_types( 46 );

		// The API returns the type and delivery names in this language.
		$this->assertSame( $expected, $cap->headers['Accept-Language'] );
	}

	public static function provide_locales(): array {
		return array(
			'language and region' => array( 'de_CH', 'de-CH,de;q=0.9' ),
			'formal variant'      => array( 'de_DE_formal', 'de-DE,de;q=0.9' ),
			'language only'       => array( 'de', 'de' ),
			'empty locale'        => array( '', 'en' ),
		);
	}

	public function test_get_order_types_requests_the_service_id(): void {
		$cap    = $this->captureRequest( 200, '[{"Name":"Translation BASIC","OrderTypeConfigurationId":166,"OrderTypeId":54}]' );
		$client = new Client( self::BASE, self::EMAIL, self::KEY );

		$result = $client->get_order_types( 51 );

		$this->assertSame( self::BASE . 'api/v1/util/ordertype/51', $cap->url );
		$this->assertSame( 'GET', $cap->method );
		$this->assertSame( 166, $result[0]['OrderTypeConfigurationId'] );
	}
}
