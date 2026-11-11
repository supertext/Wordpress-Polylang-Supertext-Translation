<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Reporting;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\PluginHealth\CallableKeyChecker;
use Supertext\Polylang\PluginHealth\InstallationReporter;
use Supertext\Polylang\PluginHealth\KeyCheck;
use Supertext\Polylang\PluginHealth\PingResponse;
use Supertext\Polylang\PluginHealth\ReporterResult;
use Supertext\Polylang\PluginHealth\ReporterState;
use Supertext\Polylang\Sdk\Http\HttpRequest;
use Supertext\Polylang\Sdk\Http\HttpTransport;
use Supertext\Polylang\Sdk\Http\TransportException;
use Supertext\Polylang\Sdk\Supertext;
use Supertext\Polylang\Sdk\V1\Client;
use Supertext\Polylang\Sdk\V1\Protocol;

/**
 * Version reports to the Supertext Commando Center, the automatic API key check
 * and the ping (Commando-Center-Supertext-Translation → docs/DEVELOPER.md →
 * Report protocol). The plugin has two Supertext keys, reported by name: the AI
 * translation API key (KEY_AI, checked with the SDK's checkApiKey()) and the
 * account email with the Order API Key of professional orders (KEY_ORDERS, checked
 * with a GET of the order types; see orders_key()). Administrators can also send
 * the list of installed plugins and themes with the language mapping (send_extensions()). The protocol is Supertext Plugin Health's InstallationReporter
 * (bundled in includes/PluginHealth), with the Supertext SDK's checkApiKey() as its
 * key check; this class only loads the reporter's state, runs one action and
 * stores the new state.
 * Nothing here ever throws: reporting must never break a save, a page or a
 * translation.
 *
 * Has no WordPress dependency (Commando gives it WordPress' values and storage;
 * unit-tested with a fake transport).
 *
 * @since 0.11.0
 */
final class Reporting {
	/**
	 * The plugin's GitHub repository name, as the center expects it.
	 *
	 * @var string
	 */
	const PLUGIN_NAME = 'supertext-wordpress-polylang';

	/**
	 * The system's name, in its own spelling.
	 *
	 * @var string
	 */
	const SYSTEM_NAME = 'WordPress';

	/**
	 * Names the keys are reported under (the dashboard knows them by these names: never translate or rename them).
	 *
	 * @var string
	 */
	const KEY_AI          = 'AI translation';
	const KEY_ORDERS      = 'Professional orders';
	const KEY_SCREENSHOTS = 'Page screenshots (VibeBoost)';

	/**
	 * Marks the VibeBoost key among the keys the checker gets (see screenshots_key()).
	 *
	 * @var string
	 */
	const SCREENSHOTS_PREFIX = "vibeboost\0";

	/**
	 * Seconds the VibeBoost check may take.
	 *
	 * @var int
	 */
	const SCREENSHOTS_TIMEOUT = 20;

	/**
	 * The address the VibeBoost check asks for: it can't exist (RFC 2606), so a valid key
	 * gets an immediate "Screenshot failed" and nothing is captured.
	 *
	 * @var string
	 */
	const SCREENSHOTS_PROBE_URL = 'https://supertext-key-check.invalid/';

	/**
	 * Marks the professional-orders credentials among the keys the checker gets (see orders_key()).
	 *
	 * @var string
	 */
	const ORDERS_PREFIX = "orders\0";

	/**
	 * Seconds the professional-orders check may take.
	 *
	 * @var int
	 */
	const ORDERS_TIMEOUT = 10;

	/**
	 * Loads the stored state (JSON string, or null).
	 *
	 * @var callable(): (string|null)
	 */
	private $load;

	/**
	 * Stores the state (JSON string).
	 *
	 * @var callable(string): void
	 */
	private $save;

	/**
	 * Wall-clock Unix seconds (tests).
	 *
	 * @var (callable(): float)|null
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param bool                     $enabled        The setting combined with SUPERTEXT_VERSION_REPORTS (see enabled()).
	 * @param string|null              $site_url       The site's base URL; null: nothing is reported (the key check still runs).
	 * @param string                   $plugin_version From the plugin header (SUPERTEXT_POLYLANG_VERSION).
	 * @param string|null              $system_version The WordPress version.
	 * @param string|null              $callback_url   The ping endpoint's absolute URL.
	 * @param string                   $api_base_url   Supertext API base URL for the key check.
	 * @param HttpTransport            $transport      HTTP for the key check and the report (the SDK's interface).
	 * @param callable                 $load           Returns the stored state (JSON) or null.
	 * @param callable                 $save           Stores the state (JSON).
	 * @param string|null              $report_url     Tests: the center's URL (default: SUPERTEXT_REPORT_URL, else Plugin Health's).
	 * @param (callable(): float)|null $now            Tests: wall-clock Unix seconds.
	 * @param string                   $orders_base_url The order API's base URL (with trailing slash) for the professional-orders check.
	 * @param int                      $orders_service_id The ServiceID whose order types the professional-orders check reads.
	 * @param string                   $screenshots_endpoint VibeBoost's capture endpoint for the screenshots key check.
	 */
	public function __construct(
		private readonly bool $enabled,
		private readonly ?string $site_url,
		private readonly string $plugin_version,
		private readonly ?string $system_version,
		private readonly ?string $callback_url,
		private readonly string $api_base_url,
		private readonly HttpTransport $transport,
		callable $load,
		callable $save,
		private readonly ?string $report_url = null,
		?callable $now = null,
		private readonly string $orders_base_url = 'https://www.supertext.com/',
		private readonly int $orders_service_id = 1,
		private readonly string $screenshots_endpoint = 'https://vibeboost.me/api/screenshot'
	) {
		$this->load = $load;
		$this->save = $save;
		$this->now  = $now;
	}

	/**
	 * Whether reports are on: SUPERTEXT_VERSION_REPORTS (0/false/off/no or 1/true/on/yes)
	 * wins over the setting; the setting defaults to on.
	 *
	 * @param bool|null $setting The setting, null when not set.
	 * @return bool
	 */
	public static function enabled( ?bool $setting ): bool {
		return InstallationReporter::reportsEnabled( $setting );
	}

	/**
	 * Whether SUPERTEXT_VERSION_REPORTS on this server overrides the setting.
	 *
	 * @return bool
	 */
	public static function forced_by_environment(): bool {
		return ! InstallationReporter::reportsEnabled( true ) || InstallationReporter::reportsEnabled( false );
	}

	/**
	 * The answer of the public ping endpoint (status, headers and body as Plugin Health returns them).
	 *
	 * @param string|null $challenge The `challenge` query parameter.
	 * @param bool        $enabled   Whether reports are on.
	 * @return PingResponse
	 */
	public static function ping( ?string $challenge, bool $enabled ): PingResponse {
		return InstallationReporter::pingResponse( $challenge, self::PLUGIN_NAME, $enabled );
	}

	/**
	 * The keys as Plugin Health gets them, by name: the AI key, and the professional-orders
	 * credentials (email and Order API Key in one value, see orders_key()) when either is set.
	 *
	 * and the VibeBoost key of page screenshots when it is given (null: page screenshots
	 * are off and no key is set, so it isn't reported).
	 *
	 * @param string      $ai_key          The AI translation API key.
	 * @param string      $orders_email    The account email of professional orders.
	 * @param string      $orders_key      The Order API Key.
	 * @param string|null $screenshots_key The VibeBoost API key ('' = missing), null = not used.
	 * @return array<string, string>
	 */
	public static function keys( #[\SensitiveParameter] string $ai_key, string $orders_email, #[\SensitiveParameter] string $orders_key, #[\SensitiveParameter] ?string $screenshots_key = null ): array {
		$keys = array( self::KEY_AI => $ai_key );
		if ( '' !== trim( $orders_email ) || '' !== trim( $orders_key ) ) {
			$keys[ self::KEY_ORDERS ] = self::orders_key( $orders_email, $orders_key );
		}
		if ( null !== $screenshots_key ) {
			$keys[ self::KEY_SCREENSHOTS ] = self::screenshots_key( $screenshots_key );
		}

		return $keys;
	}

	/**
	 * The VibeBoost key as a key value: '' when missing (the check then says `missing`
	 * without a request), else a marked value the checker recognizes. Never sent as such.
	 *
	 * @param string $api_key The VibeBoost API key.
	 * @return string
	 */
	public static function screenshots_key( #[\SensitiveParameter] string $api_key ): string {
		$api_key = trim( $api_key );

		return '' === $api_key ? '' : self::SCREENSHOTS_PREFIX . $api_key;
	}

	/**
	 * The professional-orders credentials as one key value: '' when the email or the key is
	 * missing (the check then says `missing` without a request), else a marked value the
	 * checker recognizes. Its fingerprint changes with either part; it is never sent.
	 *
	 * @param string $email   The account email.
	 * @param string $api_key The Order API Key.
	 * @return string
	 */
	public static function orders_key( string $email, #[\SensitiveParameter] string $api_key ): string {
		$email   = trim( $email );
		$api_key = trim( $api_key );

		return '' === $email || '' === $api_key ? '' : self::ORDERS_PREFIX . $email . "\0" . $api_key;
	}

	/**
	 * After the settings were saved: checks the keys, reports `installed` or `settings_saved`.
	 *
	 * @param string|array<string, string> $api_key The API key, or the keys by name (keys()).
	 * @return ReporterResult|null
	 */
	public function after_settings_saved( #[\SensitiveParameter] string|array $api_key ): ?ReporterResult {
		return $this->run( static fn( InstallationReporter $r, ReporterState $s ) => $r->afterSettingsSaved( $s, $api_key ) );
	}

	/**
	 * Back-end requests that run the plugin's code: checks a changed key, reports what changed.
	 *
	 * @param string|array<string, string> $api_key The API key, or the keys by name (keys()).
	 * @return ReporterResult|null
	 */
	public function on_request( #[\SensitiveParameter] string|array $api_key ): ?ReporterResult {
		return $this->run( static fn( InstallationReporter $r, ReporterState $s ) => $r->onRequest( $s, $api_key ) );
	}

	/**
	 * A translation got 401/403: the key is invalid (reported once, when the status changes).
	 *
	 * @param string|array<string, string> $api_key     The API key, or the keys by name (keys()).
	 * @param int                          $http_status 401 or 403.
	 * @param string|null                  $key_name    With several keys: which one was rejected.
	 * @return ReporterResult|null
	 */
	public function authentication_failed( #[\SensitiveParameter] string|array $api_key, int $http_status = 401, ?string $key_name = null ): ?ReporterResult {
		return $this->run( static fn( InstallationReporter $r, ReporterState $s ) => $r->authenticationFailed( $s, $api_key, $http_status, $key_name ) );
	}

	/**
	 * *Test connection*: checks the key(s), reports a change.
	 *
	 * @param string|array<string, string> $api_key The API key, or the keys by name (keys()).
	 * @return ReporterResult|null
	 */
	public function test_connection( #[\SensitiveParameter] string|array $api_key ): ?ReporterResult {
		return $this->run( static fn( InstallationReporter $r, ReporterState $s ) => $r->testConnection( $s, $api_key ) );
	}

	/**
	 * Sends a `check` report now (tests, diagnostics).
	 *
	 * @return ReporterResult|null
	 */
	public function report_check(): ?ReporterResult {
		return $this->run( static fn( InstallationReporter $r, ReporterState $s ) => $r->reportCheck( $s ) );
	}

	/**
	 * *Send configuration to Supertext*: sends the list and the language mapping now, in one
	 * report (event `extensions`), only while reports are on.
	 *
	 * @param iterable<mixed> $extensions Entries ['name' => …, 'version' => …, 'active' => …, 'type' => …].
	 * @param iterable<mixed> $languages  Entries ['code' => 'de_CH', 'supertext' => 'de-CH' or null].
	 * @return ReporterResult|null
	 */
	public function send_extensions( iterable $extensions, iterable $languages = array() ): ?ReporterResult {
		$reporter = $this->reporter( static fn(): iterable => $languages );
		try {
			$result = $reporter->sendExtensions( $this->state(), $extensions );
		} catch ( \Throwable $e ) {
			return null;
		}
		$this->store( $result );

		return $result;
	}

	/**
	 * The stored state (empty if none or unreadable).
	 *
	 * @return ReporterState
	 */
	public function state(): ReporterState {
		try {
			return ReporterState::from( ( $this->load )() );
		} catch ( \Throwable $e ) {
			return ReporterState::empty();
		}
	}

	/**
	 * The latest stored check of a key (default: the AI key), for the settings pages.
	 * A state from before the named keys (one key) counts as the AI key's.
	 *
	 * @param string $name KEY_AI or KEY_ORDERS.
	 * @return KeyCheck|null
	 */
	public function last_check( string $name = self::KEY_AI ): ?KeyCheck {
		$state = $this->state();
		if ( null === $state->keyChecks ) {
			return self::KEY_AI === $name ? $state->keyCheck : null;
		}

		return $state->keyChecks[ $name ] ?? null;
	}

	/**
	 * The latest stored checks by name (in the order of keys()).
	 *
	 * @return array<string, KeyCheck>
	 */
	public function last_checks(): array {
		$state = $this->state();
		if ( null === $state->keyChecks ) {
			return null !== $state->keyCheck ? array( self::KEY_AI => $state->keyCheck ) : array();
		}

		return $state->keyChecks;
	}

	/**
	 * Checks the professional-orders credentials: an authenticated GET of the order types
	 * (costs nothing). 2xx ok, 401/403 invalid, no answer or 5xx unreachable, else error.
	 *
	 * @param string $email   The account email.
	 * @param string $api_key The Order API Key.
	 * @return KeyCheck
	 */
	public function check_orders( string $email, #[\SensitiveParameter] string $api_key ): KeyCheck {
		$now         = null !== $this->now ? ( $this->now )() : microtime( true );
		$at          = KeyCheck::timestamp( $now );
		$fingerprint = Protocol::apiKeyFingerprint( self::orders_key( $email, $api_key ) );
		if ( '' === trim( $email ) || '' === trim( $api_key ) ) {
			return new KeyCheck( KeyCheck::MISSING, $at, null, null, '' );
		}
		try {
			$response = $this->transport->send(
				new HttpRequest(
					'GET',
					$this->orders_base_url . 'api/v1/util/ordertype/' . $this->orders_service_id,
					array(
						'Authorization' => 'Basic ' . base64_encode( trim( $email ) . ':' . trim( $api_key ) ),
						'Accept'        => 'application/json',
						'User-Agent'    => self::PLUGIN_NAME . '/' . $this->plugin_version,
					),
					null,
					(float) self::ORDERS_TIMEOUT
				)
			);
		} catch ( TransportException $e ) {
			return new KeyCheck( KeyCheck::UNREACHABLE, $at, null, null, $fingerprint );
		} catch ( \Throwable $e ) {
			return new KeyCheck( KeyCheck::ERROR, $at, null, null, $fingerprint );
		}
		$status = $response->status;
		$result = match ( true ) {
			$status >= 200 && $status < 300 => KeyCheck::OK,
			401 === $status || 403 === $status => KeyCheck::INVALID,
			$status >= 500 => KeyCheck::UNREACHABLE,
			default => KeyCheck::ERROR,
		};

		return new KeyCheck( $result, $at, $status, null, $fingerprint );
	}

	/**
	 * Checks the VibeBoost key without capturing anything: a Pro capture (`fullPage`) of
	 * an address that can't exist. VibeBoost checks the key first: 401/403 invalid; a
	 * valid key gets past it and the capture fails at once ("Screenshot failed", 500),
	 * which counts as ok, like 2xx and 429. No answer or another 5xx: unreachable.
	 *
	 * @param string $api_key The VibeBoost API key.
	 * @return KeyCheck
	 */
	public function check_screenshots( #[\SensitiveParameter] string $api_key ): KeyCheck {
		$now         = null !== $this->now ? ( $this->now )() : microtime( true );
		$at          = KeyCheck::timestamp( $now );
		$fingerprint = Protocol::apiKeyFingerprint( self::screenshots_key( $api_key ) );
		if ( '' === trim( $api_key ) ) {
			return new KeyCheck( KeyCheck::MISSING, $at, null, null, '' );
		}
		$separator = str_contains( $this->screenshots_endpoint, '?' ) ? '&' : '?';
		try {
			$response = $this->transport->send(
				new HttpRequest(
					'GET',
					$this->screenshots_endpoint . $separator . http_build_query(
						array(
							'url'      => self::SCREENSHOTS_PROBE_URL,
							'fullPage' => 'true',
							'width'    => 320,
						)
					),
					array(
						'Authorization' => 'Bearer ' . trim( $api_key ),
						'Accept'        => 'application/json',
						'User-Agent'    => self::PLUGIN_NAME . '/' . $this->plugin_version,
					),
					null,
					(float) self::SCREENSHOTS_TIMEOUT
				)
			);
		} catch ( TransportException $e ) {
			return new KeyCheck( KeyCheck::UNREACHABLE, $at, null, null, $fingerprint );
		} catch ( \Throwable $e ) {
			return new KeyCheck( KeyCheck::ERROR, $at, null, null, $fingerprint );
		}
		$status = $response->status;
		$failed = 500 === $status && str_contains( (string) $response->body, 'Screenshot failed' );
		$result = match ( true ) {
			( $status >= 200 && $status < 300 ) || 429 === $status || $failed => KeyCheck::OK,
			401 === $status || 403 === $status => KeyCheck::INVALID,
			$status >= 500 => KeyCheck::UNREACHABLE,
			default => KeyCheck::ERROR,
		};

		return new KeyCheck( $result, $at, $status, null, $fingerprint );
	}

	/**
	 * Plugin Health's reporter with this installation's values; the key check is the
	 * SDK's, against the Supertext environment chosen in the settings.
	 *
	 * @param (callable(): iterable<mixed>)|null $languages Optional: the language mapping the report carries.
	 * @return InstallationReporter
	 */
	public function reporter( ?callable $languages = null ): InstallationReporter {
		$transport = $this->transport;
		$base_url  = $this->api_base_url;
		$agent     = self::PLUGIN_NAME . '/' . $this->plugin_version;
		$now       = $this->now;

		return new InstallationReporter(
			enabled: $this->enabled && null !== $this->site_url,
			siteUrl: $this->site_url ?? '',
			pluginName: self::PLUGIN_NAME,
			pluginVersion: $this->plugin_version,
			systemName: self::SYSTEM_NAME,
			keyChecker: new CallableKeyChecker(
				function ( #[\SensitiveParameter] string $key ) use ( $base_url, $agent, $transport, $now ) {
					if ( str_starts_with( $key, self::SCREENSHOTS_PREFIX ) ) {
						return $this->check_screenshots( substr( $key, strlen( self::SCREENSHOTS_PREFIX ) ) );
					}
					if ( str_starts_with( $key, self::ORDERS_PREFIX ) ) {
						list( $email, $order_key ) = explode( "\0", substr( $key, strlen( self::ORDERS_PREFIX ) ), 2 ) + array( '', '' );
						return $this->check_orders( $email, $order_key );
					}
					return ( new Client( $key, baseUrl: $base_url, userAgent: $agent, transport: $transport, now: $now ) )->checkApiKey();
				},
				array( Protocol::class, 'apiKeyFingerprint' )
			),
			systemVersion: $this->system_version,
			sdkVersion: Supertext::SDK_VERSION,
			callbackUrl: $this->callback_url,
			reportUrl: $this->report_url,
			transport: new Report_Transport( $transport ),
			now: $this->now,
			languages: $languages,
		);
	}

	/**
	 * Runs one reporter action on the stored state and stores the new state.
	 *
	 * @param \Closure(InstallationReporter, ReporterState): ReporterResult $action The action.
	 * @return ReporterResult|null Null only if the reporter failed unexpectedly.
	 */
	private function run( \Closure $action ): ?ReporterResult {
		try {
			$result = $action( $this->reporter(), $this->state() );
		} catch ( \Throwable $e ) {
			return null;
		}
		$this->store( $result );

		return $result;
	}

	/**
	 * Stores a result's state.
	 *
	 * @param ReporterResult $result The result.
	 * @return void
	 */
	private function store( ReporterResult $result ): void {
		try {
			( $this->save )( (string) json_encode( $result->state, JSON_UNESCAPED_SLASHES ) );
		} catch ( \Throwable $e ) {
			// The state couldn't be stored: it is just empty next time.
			unset( $e );
		}
	}
}
