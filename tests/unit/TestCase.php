<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case: sets up Brain Monkey and common WP function stubs.
 */
abstract class TestCase extends BaseTestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, $flags = 0, $depth = 512 ) => json_encode( $data, $flags, $depth ) );
		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => $value );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => trim( strip_tags( (string) $s ) ) );
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, "/\\" ) . '/' );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Invokes a private/protected static method.
	 *
	 * @param string $class  Fully-qualified class name.
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	protected static function callPrivate( string $class, string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $class, $method );
		return $ref->invokeArgs( null, $args );
	}
}
