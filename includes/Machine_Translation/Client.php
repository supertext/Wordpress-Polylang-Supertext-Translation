<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Machine_Translation;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Reporting\Commando;
use Supertext\Polylang\Sdk\V1\Client as SdkClient;
use Supertext\Polylang\Sdk\V1\Segment;
use Supertext\Polylang\Sdk\V1\SupertextException;
use Translations;
use PLL_Language;
use WP_Error;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Clients\Client_Interface;

/**
 * Machine translation client: Supertext (AI file translation).
 *
 * Polylang's MT contract is synchronous (`translate()` must return the filled
 * Translations object), but Supertext's file endpoint is asynchronous. We bridge
 * the two by submitting one HTML file per entity, polling until it is `done`, and
 * mapping the translated HTML back — all within a single (blocking) call.
 *
 * The protocol (upload, poll, download, delete, auth header, HTTP 429 retries,
 * language codes, one `data-st-id` element per string) is the Supertext PHP SDK's,
 * bundled in includes/Sdk; HTTP goes through WordPress (Wp_Http_Transport).
 *
 * File translation (not the text endpoint) is used deliberately: it accepts up to
 * 1,000,000 characters in a single request, so a whole post goes in one call
 * instead of being chopped into many requests that would hit the text endpoint's
 * 10k-char / 5-req-per-second limits.
 *
 * @see https://www.supertext.com/de-CH/dokumentation/api
 *
 * @since 0.1.0
 */
class Client implements Client_Interface {
	/**
	 * Default API base URL (with trailing slash).
	 *
	 * @var string
	 */
	const DEFAULT_ROUTE = 'https://api.supertext.com/v1/';

	/**
	 * Selectable AI API environments => base URL.
	 *
	 * @var array<string, string>
	 */
	const ENVIRONMENTS = array(
		'live'    => 'https://api.supertext.com/v1/',
		'staging' => 'https://api.staging.supertext.com/v1/',
		'testing' => 'https://api.testing.supertext.com/v1/',
	);

	/**
	 * Supertext's review service (the translation proxy of Supertext Localization Review).
	 * It speaks the same API v1 and forwards to the live Supertext API with the plugin's
	 * key, keeping every translation so reviewers can correct them on the website. Used for
	 * target languages with *Translation review* on.
	 *
	 * @var string
	 */
	const REVIEW_URL = 'https://localizationreview-production.up.railway.app/v1/';

	/**
	 * The Supertext API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Base route (with trailing slash).
	 *
	 * @var string
	 */
	private $route;

	/**
	 * Map of Polylang language slug => Supertext language code.
	 *
	 * @var array<string, string>
	 */
	private $languages;

	/**
	 * Map of Polylang language slug => politeness (`more`, `less`, '' = automatic).
	 *
	 * @var array<string, string>
	 */
	private $politeness;

	/**
	 * Map of Polylang language slug => Translation review on.
	 *
	 * @var array<string, bool>
	 */
	private $review;

	/**
	 * Base route of the review service (with trailing slash), '' when review is off
	 * everywhere (no review URL, or the same URL as the API).
	 *
	 * @var string
	 */
	private $review_route;

	/**
	 * The AI environment set on the server, which wins over the setting:
	 * SUPERTEXT_AI_ENVIRONMENT (live, staging or testing; wp-config.php constant or
	 * environment variable) as its API base URL. Null when it isn't set.
	 *
	 * @return string|null
	 */
	public static function environment_override(): ?string {
		$value = strtolower( \Supertext\Polylang\Admin\Settings::server_value( 'SUPERTEXT_AI_ENVIRONMENT' ) );

		return self::ENVIRONMENTS[ $value ] ?? null;
	}

	/**
	 * Constructor.
	 *
	 * @param array $options {
	 *     The service options.
	 *
	 *     @type string $api_key   The Supertext API key.
	 *     @type string $endpoint  Optional. Base URL of the API. Defaults to Supertext.
	 *     @type array  $languages  Optional. Map of Polylang language slug => Supertext code.
	 *     @type array  $politeness Optional. Map of Polylang language slug => `more`, `less` or ''.
	 *     @type array  $review     Optional. Map of Polylang language slug => Translation review on.
	 *     @type string $review_url Optional. The review service (default: self::review_url()); '' = review off.
	 * }
	 */
	public function __construct( array $options ) {
		$this->api_key   = (string) preg_replace( '/^\s*Supertext-Auth-Key\s+/i', '', trim( (string) ( $options['api_key'] ?? '' ) ) );
		$this->languages  = isset( $options['languages'] ) && is_array( $options['languages'] ) ? $options['languages'] : array();
		$this->politeness = isset( $options['politeness'] ) && is_array( $options['politeness'] ) ? $options['politeness'] : array();

		$endpoint = self::environment_override() ?? ( ! empty( $options['endpoint'] ) ? $options['endpoint'] : self::DEFAULT_ROUTE );
		/** @var string $endpoint */
		$endpoint    = apply_filters( 'supertext_polylang_endpoint', $endpoint, $options );
		$this->route = trailingslashit( $endpoint );

		$this->review = array();
		foreach ( ( isset( $options['review'] ) && is_array( $options['review'] ) ? $options['review'] : array() ) as $slug => $on ) {
			$this->review[ (string) $slug ] = (bool) $on;
		}
		$review_url         = trim( (string) ( array_key_exists( 'review_url', $options ) ? $options['review_url'] : self::review_url() ) );
		$this->review_route = '' === $review_url || rtrim( $review_url, '/' ) === rtrim( $this->route, '/' ) ? '' : trailingslashit( $review_url );
	}

	/**
	 * The review service for languages with *Translation review* on: the `SUPERTEXT_REVIEW_URL`
	 * constant (wp-config.php), else the environment variable of that name, else Supertext's
	 * review service (self::REVIEW_URL); then the `supertext_polylang_review_endpoint` filter.
	 * '' turns review off for every language.
	 *
	 * @return string
	 */
	public static function review_url(): string {
		if ( defined( 'SUPERTEXT_REVIEW_URL' ) ) {
			$url = (string) constant( 'SUPERTEXT_REVIEW_URL' );
		} else {
			$env = getenv( 'SUPERTEXT_REVIEW_URL' );
			$url = false === $env ? self::REVIEW_URL : $env;
		}

		/**
		 * Filters the review service's base URL ('' = Translation review off for every language).
		 *
		 * @param string $url The review service's base URL.
		 */
		return trim( (string) apply_filters( 'supertext_polylang_review_endpoint', $url ) );
	}

	/**
	 * The base route for translations into a language: the review service when its
	 * *Translation review* is on (same key, same protocol), else the API.
	 *
	 * @param PLL_Language $language The target language.
	 * @return string
	 */
	public function route_for( PLL_Language $language ): string {
		return '' !== $this->review_route && ! empty( $this->review[ $language->slug ] ) ? $this->review_route : $this->route;
	}

	/**
	 * The target code for a Polylang language: the settings' mapping, else an exact
	 * match of its locale (see Language_Codes; no default regions).
	 *
	 * @param PLL_Language $language The language.
	 * @return string
	 * @throws SupertextException `unsupported_language` when the language is neither mapped nor supported.
	 */
	private function target_code( PLL_Language $language ): string {
		return Service::resolve_target( $language, $this->languages );
	}

	/**
	 * The source code for a Polylang language ('' for auto-detection).
	 *
	 * @param PLL_Language|null $language The language, null for auto-detection.
	 * @return string
	 * @throws SupertextException `unsupported_language`.
	 */
	private function source_code( $language ): string {
		if ( ! $language instanceof PLL_Language ) {
			return '';
		}
		// One mapping serves both directions: the language's target code in its source form (de-CH → de).
		try {
			$target = Service::resolve_target( $language, $this->languages );
		} catch ( SupertextException $e ) {
			return Language_Codes::source( $language->locale );
		}

		return Language_Codes::source( $language->locale, array( $language->locale => $target ) );
	}

	/**
	 * Translates a Translations object in place and returns it.
	 *
	 * @param Translations      $translations    Translations object.
	 * @param PLL_Language      $target_language Target language.
	 * @param PLL_Language|null $source_language Source language, null for auto-detection.
	 * @return Translations|WP_Error
	 */
	public function translate( Translations $translations, PLL_Language $target_language, $source_language = null ) {
		// Collect the source strings in a stable order.
		$entries = array_values( $translations->entries );
		$sources = array();
		foreach ( $entries as $i => $entry ) {
			$sources[ $i ] = (string) $entry->singular;
		}

		if ( empty( $sources ) ) {
			return $translations;
		}

		$translated = $this->translate_strings( $sources, $target_language, $source_language );
		if ( is_wp_error( $translated ) ) {
			return $translated;
		}

		foreach ( $entries as $i => $entry ) {
			$entry->translations = array( $translated[ $i ] ?? '' );
		}

		return $translations;
	}

	/**
	 * Translates a set of strings via the AI file endpoint, preserving the caller's
	 * keys.
	 *
	 * A reusable entry point for integrations (e.g. Gravity Forms) that live outside
	 * Polylang's post/term translation pipeline. Returns the translations keyed the
	 * same as `$sources`.
	 *
	 * @param array<int|string, string> $sources         Strings to translate.
	 * @param PLL_Language               $target_language Target language.
	 * @param PLL_Language|null          $source_language Source language (null = auto-detect).
	 * @return array<int|string, string>|WP_Error Translations keyed like `$sources`.
	 */
	public function translate_strings( array $sources, PLL_Language $target_language, $source_language = null ) {
		$sources = array_map( 'strval', $sources );
		if ( empty( $sources ) ) {
			return array();
		}

		// Nothing is sent for a language without a Supertext code: the message names it
		// and where to map it.
		try {
			$target_code = $this->target_code( $target_language );
		} catch ( SupertextException $e ) {
			return self::to_wp_error( $e, $target_language );
		}
		try {
			$source_code = $this->source_code( $source_language );
		} catch ( SupertextException $e ) {
			return self::to_wp_error( $e, $source_language instanceof PLL_Language ? $source_language : null );
		}

		$politeness = Language_Codes::politeness(
			$target_code,
			(string) ( $this->politeness[ $target_language->slug ] ?? '' ),
			$target_language->locale
		);

		// Languages with Translation review go through the review service; upload, status,
		// download and delete all run on that one SDK client.
		return $this->translate_segments( $sources, $target_code, $source_code, $politeness, $this->route_for( $target_language ) );
	}

	/**
	 * Translates keyed strings (HTML) with the Supertext SDK and returns them under
	 * the same keys.
	 *
	 * @param array<int|string, string> $sources     Strings to translate.
	 * @param string                    $target_lang Target code (regional).
	 * @param string                    $source_lang Source code, empty for auto-detection.
	 * @param string                    $politeness  `default`, `more`, or `less`.
	 * @param string                    $route       Base URL: the API or the review service.
	 * @return array<int|string, string>|WP_Error
	 */
	private function translate_segments( array $sources, string $target_lang, string $source_lang, string $politeness, string $route ) {
		$segments = array();
		foreach ( $sources as $key => $source ) {
			$segments[ $key ] = Segment::html( (string) $source );
		}

		/** @var int $timeout */
		$timeout = max( 1, (int) apply_filters( 'supertext_polylang_poll_timeout', 180 ) );

		// Best-effort: give PHP enough time to finish the blocking poll.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $timeout + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		try {
			$result = $this->sdk( $timeout, $route )->translateTexts( $segments, $target_lang, '' !== $source_lang ? $source_lang : null, $politeness );
		} catch ( SupertextException $e ) {
			// A key rejected through the review service isn't the key check's result (the check
			// always goes directly to Supertext), so only a direct rejection is reported.
			if ( SupertextException::AUTHENTICATION_FAILURE === $e->errorCode && $route === $this->route ) {
				// Tell the Commando Center the key was rejected (once, when its status changes).
				Commando::authentication_failed( in_array( (int) $e->status, array( 401, 403 ), true ) ? (int) $e->status : 401 );
			} else {
				Commando::on_request();
			}
			return self::to_wp_error( $e );
		}
		Commando::on_request();

		return $result;
	}

	/**
	 * The SDK client for one call. The key check always goes directly to Supertext.
	 *
	 * @param int         $timeout Seconds to wait for a translation.
	 * @param string|null $route   Base URL (the review service for review languages), null = the API.
	 * @return SdkClient
	 */
	private function sdk( int $timeout = 180, ?string $route = null ): SdkClient {
		/** @var int $interval */
		$interval = max( 1, (int) apply_filters( 'supertext_polylang_poll_interval', 2 ) );

		return new SdkClient(
			$this->api_key,
			baseUrl: $route ?? $this->route,
			pollInterval: (float) $interval,
			timeout: (float) max( $interval, $timeout ),
			requestTimeout: 30.0,
			userAgent: 'supertext-wordpress-polylang/' . ( defined( 'SUPERTEXT_POLYLANG_VERSION' ) ? SUPERTEXT_POLYLANG_VERSION : 'dev' ),
			transport: new Wp_Http_Transport( $this->api_key ),
		);
	}

	/**
	 * Turns an SDK error into the WP_Error this plugin has always returned
	 * (`supertext_<code>`, a translatable message, the API's detail appended).
	 * Messages are plain text (editors see them in notices that escape HTML), so
	 * links are bare URLs.
	 *
	 * @param SupertextException $e        The SDK error.
	 * @param PLL_Language|null  $language The language an `unsupported_language` error is about, if known.
	 * @return WP_Error
	 */
	public static function to_wp_error( SupertextException $e, $language = null ): WP_Error {
		$key_links = ' ' . sprintf(
			/* translators: 1: URL to create a Supertext account (or log in), 2: URL of the Supertext API integrations page. */
			__( 'No Supertext account yet? Create one at %1$s. Generate your API key at %2$s (supertext.com → Integrations → API; requires the Admin role).', 'supertext-polylang' ),
			'https://www.supertext.com/person/en/account/signin',
			'https://www.supertext.com/en/integrations/api'
		);

		switch ( $e->errorCode ) {
			case SupertextException::MISSING_API_KEY:
				$errcode = 'supertext_authentication_failure';
				$message = __( 'No Supertext API key is set. Enter it under Languages → Settings → Machine translation → Supertext.', 'supertext-polylang' ) . $key_links;
				break;
			case SupertextException::AUTHENTICATION_FAILURE:
				$errcode = 'supertext_authentication_failure';
				$message = __( 'Authentication failure. Please check your Supertext API key.', 'supertext-polylang' ) . $key_links;
				break;
			case SupertextException::UNSUPPORTED_LANGUAGE:
				$errcode = 'supertext_unsupported_language';
				$code    = (string) ( $e->targetLang ?? $e->sourceLang ?? '' );
				$name    = $language instanceof PLL_Language ? $language->name . ' (' . $language->locale . ')' : ( '' !== $code ? $code : '?' );
				$message = sprintf(
					/* translators: %s is a language name and locale, e.g. "Deutsch (de_DE_formal)". */
					__( '%s has no Supertext language, so nothing was sent to Supertext. An administrator can choose it in the drop-down for this language under Languages → Settings → Machine translation → Supertext → Language mapping.', 'supertext-polylang' ),
					$name
				);
				break;
			case SupertextException::UNSUPPORTED_FEATURE:
				$errcode = 'supertext_unsupported_feature';
				$message = sprintf(
					/* translators: %s is the name of the option, e.g. "politeness". */
					__( 'Supertext offers this language pair, but not with the option “%s”. An administrator can reset the formality of this language under Languages → Settings → Machine translation → Supertext → Language mapping.', 'supertext-polylang' ),
					'' !== (string) $e->feature ? (string) $e->feature : '?'
				);
				break;
			case SupertextException::INVALID_LANGUAGE_PAIR:
				$errcode = 'supertext_invalid_language_pair';
				$message = sprintf(
					/* translators: %1$s is the source language code, %2$s the target language code. */
					__( 'Supertext does not support this language pair (%1$s → %2$s). Check the Supertext language mapping in the settings.', 'supertext-polylang' ),
					'' !== (string) $e->sourceLang ? (string) $e->sourceLang : '?',
					'' !== (string) $e->targetLang ? (string) $e->targetLang : '?'
				);
				break;
			case SupertextException::NOT_FOUND:
				$errcode = 'supertext_not_found';
				$message = __( 'The requested Supertext resource was not found.', 'supertext-polylang' );
				break;
			case SupertextException::PAYLOAD_TOO_LARGE:
				$errcode = 'supertext_payload_too_large';
				$message = __( 'The document is too large for Supertext to translate.', 'supertext-polylang' );
				break;
			case SupertextException::TOO_MANY_REQUESTS:
				$errcode = 'supertext_too_many_requests';
				$message = __( 'Too many requests to Supertext. Please try again shortly.', 'supertext-polylang' );
				break;
			case SupertextException::SERVICE_UNAVAILABLE:
				$errcode = 'supertext_service_unavailable';
				$message = __( 'Supertext service unavailable.', 'supertext-polylang' );
				break;
			case SupertextException::TRANSPORT_ERROR:
				$errcode = 'supertext_transport_error';
				$message = __( 'Could not reach Supertext.', 'supertext-polylang' );
				break;
			case SupertextException::NO_FILE_ID:
				$errcode = 'supertext_no_file_id';
				$message = __( 'Supertext did not return a file id.', 'supertext-polylang' );
				break;
			case SupertextException::TRANSLATION_ERROR:
				$errcode = 'supertext_translation_error';
				$message = __( 'Supertext failed to translate the document.', 'supertext-polylang' );
				break;
			case SupertextException::QUOTA_EXCEEDED:
				$errcode = 'supertext_quota_exceeded';
				$message = __( 'Your Supertext translation limit is exceeded. Please upgrade your subscription.', 'supertext-polylang' );
				break;
			case SupertextException::FILE_DELETED:
				$errcode = 'supertext_file_deleted';
				$message = __( 'The Supertext translation file was deleted before it could be downloaded.', 'supertext-polylang' );
				break;
			case SupertextException::TIMEOUT:
				$errcode = 'supertext_timeout';
				$message = __( 'Timed out waiting for the Supertext translation to finish.', 'supertext-polylang' );
				break;
			case SupertextException::INCOMPLETE_RESPONSE:
				$errcode = 'supertext_incomplete_response';
				$message = __( 'The Supertext translation is incomplete.', 'supertext-polylang' );
				break;
			default:
				$errcode = 'supertext_unexpected_status_code';
				/* translators: %d is an HTTP status code. */
				$message = sprintf( __( 'Supertext sent an unexpected status code %d.', 'supertext-polylang' ), (int) $e->status );
		}

		// Surface the server's own message (helps diagnose 404s / bad requests).
		$detail = (string) $e->detail;
		if ( '' !== $detail ) {
			$message .= ' — ' . $detail;
		}

		return new WP_Error( $errcode, $message );
	}

	/**
	 * Tells whether the API key is valid by calling the (cost-free) features endpoint.
	 *
	 * @return WP_Error An empty WP_Error if valid, a filled WP_Error otherwise.
	 */
	public function is_api_key_valid(): WP_Error {
		try {
			$this->sdk()->validateApiKey();
		} catch ( SupertextException $e ) {
			return self::to_wp_error( $e );
		}

		return new WP_Error();
	}

	/**
	 * Returns current translation usage.
	 *
	 * The Supertext API exposes no character-usage endpoint, so this reports zeros
	 * (the value is only used by Polylang's optional consumption widget).
	 *
	 * @return array{character_count: int, character_limit: int}
	 */
	public function get_usage() {
		return array(
			'character_count' => 0,
			'character_limit' => 0,
		);
	}
}
