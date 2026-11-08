<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Human_Translation;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Admin\Settings;
use WP_Error;

/**
 * Client for Supertext's human / professional translation Order API.
 *
 * Authenticates with HTTP Basic (account email + Order API Key) against the
 * configured environment. Implements the two calls needed to place an order:
 *   1. upload the HTML document  (POST api/v1/files/files)
 *   2. create the order          (POST api/v1.1/translation/order)
 *
 * The response/callback handling is intentionally out of scope for now.
 *
 * @since 0.5.0
 */
class Client {
	/**
	 * Environment base URL (trailing slash).
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Account email (Basic auth username).
	 *
	 * @var string
	 */
	private $email;

	/**
	 * Order API Key (Basic auth password).
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor. Credentials default to the plugin settings; they can be injected
	 * (e.g. for testing).
	 *
	 * @param string|null $base    Base URL (with trailing slash).
	 * @param string|null $email   Account email.
	 * @param string|null $api_key Order API Key.
	 */
	public function __construct( ?string $base = null, ?string $email = null, ?string $api_key = null ) {
		$this->base    = null !== $base ? $base : Settings::base_url();
		$this->email   = null !== $email ? $email : Settings::email();
		$this->api_key = null !== $api_key ? $api_key : Settings::api_key();
	}

	/**
	 * Uploads a document to a Supertext file store.
	 *
	 * Defaults describe the original (source) HTML document to translate. Pass a
	 * different content type and `DocumentTypeId` to attach other material — e.g. a
	 * page screenshot as a reference file (`image/png`, DocumentTypeId `3`).
	 *
	 * @param string $content          The file content (binary or text).
	 * @param string $filename         The file name (with the right extension).
	 * @param string $content_type     The MIME type (default `text/html`).
	 * @param int    $document_type_id Supertext DocumentTypeId (1 = original, 3 = reference).
	 * @return int|WP_Error The Supertext DocumentId on success.
	 */
	public function upload_file( string $content, string $filename, string $content_type = 'text/html', int $document_type_id = 1 ) {
		list( $boundary, $body ) = $this->build_multipart(
			array(
				'ElementId'      => '0',
				'ElementTypeId'  => '2',
				'DocumentTypeId' => (string) $document_type_id,
			),
			array(
				'name'     => 'file',
				'filename' => $filename,
				'type'     => $content_type,
				'content'  => $content,
			)
		);

		$response = $this->request(
			'POST',
			'api/v1/files/files',
			array(
				'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		// The upload endpoint returns a JSON array of document objects (one per
		// uploaded file). Pick the document with the highest Id (the one we just added).
		$document_id = 0;
		if ( is_array( $data ) ) {
			if ( isset( $data['Id'] ) ) {
				$document_id = (int) $data['Id']; // Defensive: single-object response.
			} else {
				foreach ( $data as $item ) {
					if ( is_array( $item ) && isset( $item['Id'] ) ) {
						$document_id = max( $document_id, (int) $item['Id'] );
					}
				}
			}
		}

		if ( $document_id <= 0 ) {
			return new WP_Error(
				'supertext_no_document_id',
				sprintf(
					/* translators: %s is the raw API response. */
					__( 'Supertext did not return a document id for the uploaded file. Response: %s', 'supertext-polylang' ),
					mb_substr( wp_strip_all_tags( $raw ), 0, 200 )
				)
			);
		}

		return $document_id;
	}

	/**
	 * Creates a translation order.
	 *
	 * @param array $order The order body (see Bulk_Actions for the assembled fields).
	 * @return array|WP_Error The decoded response on success.
	 */
	public function create_order( array $order ) {
		$response = $this->request(
			'POST',
			'api/v1.1/translation/order',
			array(
				'headers' => array( 'Content-Type' => 'application/json; charset=UTF-8' ),
				'body'    => wp_json_encode( $order ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Requests a price quote for already-uploaded content.
	 *
	 * @param array $body The quote body (OrderTypeConfigurationId, OrderTypeId,
	 *                    SourceLang, TargetLanguages, Files, …).
	 * @return array|WP_Error The decoded quote on success.
	 */
	public function get_quote( array $body ) {
		$response = $this->request(
			'POST',
			'api/v1/translation/quote',
			array(
				'headers' => array( 'Content-Type' => 'application/json; charset=UTF-8' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Fetches the translation product types (order-type configurations) offered for
	 * a Supertext ServiceID.
	 *
	 * `GET api/v1/util/ordertype/{service_id}` returns a flat list of objects, each
	 * carrying `Name`, `Description`, `ShortDescription`,
	 * `OrderTypeConfigurationId` and `OrderTypeId`.
	 *
	 * Uses a short timeout: this feeds an admin dropdown that has a built-in
	 * fallback, so it must never stall the posts list for long.
	 *
	 * @param int $service_id The Supertext ServiceID (order-type group).
	 * @return array|WP_Error The decoded list on success.
	 */
	public function get_order_types( int $service_id ) {
		$response = $this->request(
			'GET',
			'api/v1/util/ordertype/' . $service_id,
			array( 'headers' => array( 'Content-Type' => 'application/json; charset=UTF-8' ) ),
			15
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Fetches an order (with its current status and files).
	 *
	 * @param int $order_id The order id.
	 * @return array|WP_Error
	 */
	public function get_order( int $order_id ) {
		$response = $this->request(
			'GET',
			'api/v1/order/' . $order_id,
			array( 'headers' => array( 'Content-Type' => 'application/json; charset=UTF-8' ) )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Downloads a translated file by its document id.
	 *
	 * @param int    $file_id The Supertext file/document id.
	 * @param string $name    The file name (as given in the callback).
	 * @return string|WP_Error The file contents (HTML) on success.
	 */
	public function download_file( int $file_id, string $name ) {
		$response = $this->request( 'GET', 'storage/file/' . $file_id . '/' . rawurlencode( $name ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * The Accept-Language header sent with every order-API request.
	 *
	 * The API answers in the requested language, which is what makes the order
	 * dropdowns readable: the translation types of `util/ordertype` (`Name`,
	 * `Description`) and the delivery options of `translation/quote` (`Name`) come
	 * back localized, as do its error messages.
	 *
	 * Built from the back-end language of the current user (the same language the
	 * plugin's own strings are shown in), as BCP-47 with the bare language as a
	 * fallback: `de_CH` becomes `de-CH,de;q=0.9`.
	 *
	 * @return string
	 */
	public static function accept_language(): string {
		$locale = function_exists( 'get_user_locale' ) ? (string) get_user_locale() : (string) get_locale();
		$parts  = explode( '_', $locale );
		$lang   = strtolower( $parts[0] );
		$region = ( isset( $parts[1] ) && 1 === preg_match( '/^[A-Z]{2}$/', $parts[1] ) ) ? $parts[1] : '';

		if ( '' === $lang ) {
			$lang = 'en';
		}

		$value = '' !== $region ? $lang . '-' . $region . ',' . $lang . ';q=0.9' : $lang;

		/**
		 * Filters the Accept-Language header sent to the Supertext order API.
		 *
		 * @param string $value  Header value, e.g. `de-CH,de;q=0.9`.
		 * @param string $locale The WordPress locale it was built from.
		 */
		return (string) apply_filters( 'supertext_polylang_accept_language', $value, $locale );
	}

	/**
	 * Performs an authenticated request against the human/order API.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint Endpoint relative to the environment base.
	 * @param array  $args     Request args (headers, body). Must NOT contain
	 *                         `timeout` — the args are merged recursively, so a
	 *                         second scalar would turn it into an array. Pass
	 *                         $timeout instead.
	 * @param int    $timeout  Request timeout in seconds.
	 * @return array|WP_Error
	 */
	private function request( string $method, string $endpoint, array $args = array(), int $timeout = 60 ) {
		if ( '' === $this->email || '' === $this->api_key ) {
			return new WP_Error( 'supertext_human_not_configured', __( 'The Supertext human-translation credentials are not configured.', 'supertext-polylang' ) );
		}

		$url  = $this->base . ltrim( $endpoint, '/' );
		$args = array_merge_recursive(
			array(
				'method'  => $method,
				'timeout' => $timeout,
				'headers' => array(
					'Authorization'   => 'Basic ' . base64_encode( $this->email . ':' . $this->api_key ),
					'Accept'          => 'application/json',
					'Accept-Language' => self::accept_language(),
				),
			),
			$args
		);

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->debug_log( sprintf( '%s %s -> transport error: %s', $method, $url, $response->get_error_message() ) );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$resp_body = (string) wp_remote_retrieve_body( $response );

		if ( 401 === $code || 403 === $code ) {
			// Tell the Commando Center the credentials were rejected (once, when the status changes).
			\Supertext\Polylang\Reporting\Commando::authentication_failed( $code, \Supertext\Polylang\Reporting\Reporting::KEY_ORDERS );
		}
		if ( $code < 200 || $code >= 300 ) {
			$this->debug_log( sprintf( '%s %s -> HTTP %d | %s', $method, $url, $code, substr( $resp_body, 0, 1000 ) ) );
			$detail = wp_strip_all_tags( trim( $resp_body ) );
			return new WP_Error(
				'supertext_human_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: request URL, 3: server message. */
					__( 'Supertext order API returned status %1$d for %2$s. %3$s', 'supertext-polylang' ),
					$code,
					$url,
					'' !== $detail ? mb_substr( $detail, 0, 200 ) : ''
				)
			);
		}

		return $response;
	}

	/**
	 * Builds a multipart/form-data body.
	 *
	 * @param array<string, string> $fields Text fields.
	 * @param array                 $file   { name, filename, type, content }.
	 * @return array{0: string, 1: string} Boundary and body.
	 */
	private function build_multipart( array $fields, array $file ): array {
		$boundary = 'supertext-' . md5( uniqid( '', true ) );
		$eol      = "\r\n";
		$body     = '';

		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$body .= $value . $eol;
		}

		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="' . $file['name'] . '"; filename="' . $file['filename'] . '"' . $eol;
		$body .= 'Content-Type: ' . $file['type'] . $eol . $eol;
		$body .= $file['content'] . $eol;
		$body .= '--' . $boundary . '--' . $eol;

		return array( $boundary, $body );
	}

	/**
	 * Logs a diagnostic line when WP_DEBUG is on.
	 *
	 * @param string $message The message.
	 * @return void
	 */
	private function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[supertext-polylang][human] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
