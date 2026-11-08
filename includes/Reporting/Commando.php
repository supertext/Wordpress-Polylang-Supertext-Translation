<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Reporting;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Admin\Settings;
use Supertext\Polylang\Machine_Translation\Wp_Http_Transport;
use Supertext\Polylang\PluginHealth\KeyCheck;
use Supertext\Polylang\PluginHealth\ReporterResult;
use Supertext\Polylang\Sdk\Http\HttpTransport;
use Supertext\Polylang\Sdk\V1\Protocol;
use WP_REST_Request;
use WP_REST_Response;

/**
 * WordPress' side of the Commando Center (version reports, API key check, ping):
 * the site URL, the WordPress and plugin versions, the setting, and the
 * reporter's state in the option `supertext_polylang_reporter` (not autoloaded).
 *
 * - after_settings_saved(): Polylang's machine-translation settings save (the
 *   Supertext section calls it from Settings::is_api_key_valid(), or the
 *   `update_option_polylang` hook when the Supertext options changed without it)
 *   and the save of the Supertext settings page.
 * - on_request(): the Supertext pages, Polylang's settings page and every AI
 *   translation, once per request, on `shutdown` (after fastcgi_finish_request()
 *   where available). Never on front-end views.
 * - authentication_failed(): an AI translation was rejected with 401/403.
 * - test_connection(): the *Test connection* button on the Supertext page.
 * - send_extensions(): the *Send installed plugins to Supertext* button there.
 * - The public ping: GET /wp-json/supertext/v1/ping.
 *
 * Two keys are reported by name (Reporting::keys()): the AI translation key
 * (Polylang's Supertext service options) and, once either is set, the account
 * email and Order API Key of professional orders (the Supertext settings page).
 *
 * Nothing here throws into a request (Reporting catches everything).
 *
 * @since 0.11.0
 */
class Commando {
	/**
	 * Option holding the reporter's JSON state.
	 *
	 * @var string
	 */
	const STATE_OPTION = 'supertext_polylang_reporter';

	/**
	 * REST namespace and route of the ping.
	 *
	 * @var string
	 */
	const PING_NAMESPACE = 'supertext/v1';
	const PING_ROUTE     = '/ping';

	/**
	 * admin-post action of *Test connection*.
	 *
	 * @var string
	 */
	const TEST_ACTION = 'supertext_polylang_test_connection';

	/**
	 * Transient (per user) holding the *Test connection* result for the next page view.
	 *
	 * @var string
	 */
	const TEST_TRANSIENT = 'supertext_polylang_key_check';

	/**
	 * admin-post action of *Send installed plugins to Supertext*.
	 *
	 * @var string
	 */
	const EXTENSIONS_ACTION = 'supertext_polylang_send_extensions';

	/**
	 * Transient (per user) holding that button's result for the next page view.
	 *
	 * @var string
	 */
	const EXTENSIONS_TRANSIENT = 'supertext_polylang_extensions_sent';

	/**
	 * Tests only: the transport for the check and the report.
	 *
	 * @var HttpTransport|null
	 */
	public static $transport = null;

	/**
	 * The action that runs on `shutdown`, if any.
	 *
	 * @var (\Closure(): mixed)|null
	 */
	private static $deferred = null;

	/**
	 * Whether the deferred action is authentication_failed (wins over on_request).
	 *
	 * @var bool
	 */
	private static $deferred_has_priority = false;

	/**
	 * Whether after_settings_saved already ran in this request.
	 *
	 * @var bool
	 */
	private static $saved = false;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_ping' ) );
		add_action( 'admin_init', array( self::class, 'maybe_on_request' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( self::class, 'handle_test_connection' ) );
		add_action( 'admin_post_' . self::EXTENSIONS_ACTION, array( self::class, 'handle_send_extensions' ) );
		add_action( 'update_option_polylang', array( self::class, 'polylang_options_updated' ), 10, 2 );
		add_filter( 'pre_update_option_' . Settings::OPTION, array( self::class, 'own_settings_saved' ), 10, 2 );
	}

	/**
	 * Whether version reports are on (the setting; SUPERTEXT_VERSION_REPORTS wins).
	 *
	 * @param bool|null $setting The setting to use instead of the stored one (a save in progress).
	 * @return bool
	 */
	public static function enabled( ?bool $setting = null ): bool {
		return Reporting::enabled( $setting ?? Settings::version_reports() );
	}

	/**
	 * The Supertext service options stored by Polylang (api_key, endpoint, languages, …).
	 *
	 * @return array<string, mixed>
	 */
	public static function service_options(): array {
		$options = get_option( 'polylang' );
		$service = is_array( $options ) ? ( $options['machine_translation_services']['supertext'] ?? array() ) : array();

		return is_array( $service ) ? $service : array();
	}

	/**
	 * The site's base URL (home_url()), null if it isn't an absolute http(s) URL.
	 *
	 * @return string|null
	 */
	public static function site_url(): ?string {
		$url = (string) home_url( '/' );

		return preg_match( '#^https?://[^/]#i', $url ) ? $url : null;
	}

	/**
	 * The ping endpoint's absolute URL.
	 *
	 * @return string
	 */
	public static function ping_url(): string {
		return (string) rest_url( self::PING_NAMESPACE . self::PING_ROUTE );
	}

	/**
	 * The glue with WordPress' values.
	 *
	 * @param string|null $endpoint        The API base URL to check against (a save in progress); null: the stored one.
	 * @param bool|null   $enabled         The version reports setting (a save in progress); null: the stored one.
	 * @param string|null $orders_base_url The order API's base URL (a save in progress); null: the stored environment's.
	 * @return Reporting
	 */
	public static function reporting( ?string $endpoint = null, ?bool $enabled = null, ?string $orders_base_url = null ): Reporting {
		$site_url = self::site_url();
		$service  = self::service_options();
		$endpoint = $endpoint ?? (string) ( $service['endpoint'] ?? '' );
		$endpoint = '' !== trim( $endpoint ) ? $endpoint : Protocol::LIVE_BASE_URL;
		/** @var string $endpoint */
		$endpoint = apply_filters( 'supertext_polylang_endpoint', $endpoint, $service );

		return new Reporting(
			enabled: self::enabled( $enabled ),
			site_url: $site_url,
			plugin_version: defined( 'SUPERTEXT_POLYLANG_VERSION' ) ? (string) SUPERTEXT_POLYLANG_VERSION : 'dev',
			system_version: (string) get_bloginfo( 'version' ),
			callback_url: null !== $site_url ? self::ping_url() : null,
			api_base_url: trailingslashit( $endpoint ),
			transport: self::$transport ?? new Wp_Http_Transport( '' ),
			load: static function (): ?string {
				$value = get_option( self::STATE_OPTION, null );
				return is_string( $value ) ? $value : null;
			},
			save: static function ( string $json ): void {
				update_option( self::STATE_OPTION, $json, false );
			},
			orders_base_url: $orders_base_url ?? Settings::base_url(),
			orders_service_id: Settings::service_id(),
		);
	}

	/**
	 * The stored API key (Polylang's Supertext service options).
	 *
	 * @return string
	 */
	public static function api_key(): string {
		return (string) ( self::service_options()['api_key'] ?? '' );
	}

	/**
	 * The keys by name (Reporting::keys()): the AI key and the professional-orders credentials.
	 *
	 * @param string|null               $ai_key The AI key being saved; null: the stored one.
	 * @param array<string, mixed>|null $own    The Supertext settings being saved; null: the stored ones.
	 * @return array<string, string>
	 */
	public static function keys( ?string $ai_key = null, ?array $own = null ): array {
		return Reporting::keys(
			$ai_key ?? self::api_key(),
			null !== $own ? (string) ( $own['human_email'] ?? '' ) : Settings::email(),
			null !== $own ? (string) ( $own['human_api_key'] ?? '' ) : Settings::api_key()
		);
	}

	/**
	 * When the key was last checked, as shown next to the check ("checked 8 November 2026 10:53").
	 * Lives here, not in the MT settings, so pages that work without Polylang Pro can use it.
	 *
	 * @param KeyCheck $check The check.
	 * @return string
	 */
	public static function checked_at( KeyCheck $check ): string {
		$time = strtotime( $check->checkedAt );
		if ( false === $time ) {
			return $check->checkedAt;
		}
		/* translators: %s: date and time of the last API key check. */
		return sprintf( __( 'checked %s', 'supertext-polylang' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) );
	}

	/**
	 * Settings saved: checks the AI key and reports `installed` or `settings_saved`.
	 * Runs at most once per request.
	 *
	 * @param string|null $api_key  The AI key being saved; null: the stored one.
	 * @param string|null $endpoint The API base URL being saved; null: the stored one.
	 * @param bool|null   $enabled  The version reports setting being saved; null: the stored one.
	 * @return KeyCheck|null The AI key's check (null if it already ran in this request or the reporter failed).
	 */
	public static function after_settings_saved( ?string $api_key = null, ?string $endpoint = null, ?bool $enabled = null ): ?KeyCheck {
		$result = self::save_and_report( self::keys( $api_key ), $endpoint, $enabled );

		return null !== $result ? self::check_of( $result, Reporting::KEY_AI ) : null;
	}

	/**
	 * Settings saved: checks every key and reports, at most once per request.
	 *
	 * @param array<string, string> $keys            The keys by name.
	 * @param string|null           $endpoint        The API base URL being saved; null: the stored one.
	 * @param bool|null             $enabled         The version reports setting being saved; null: the stored one.
	 * @param string|null           $orders_base_url The order API's base URL being saved; null: the stored one.
	 * @return ReporterResult|null Null if it already ran in this request or the reporter failed.
	 */
	private static function save_and_report( array $keys, ?string $endpoint = null, ?bool $enabled = null, ?string $orders_base_url = null ): ?ReporterResult {
		if ( self::$saved ) {
			return null;
		}
		self::$saved = true;
		try {
			$result = self::reporting( $endpoint, $enabled, $orders_base_url )->after_settings_saved( $keys );
		} catch ( \Throwable $e ) {
			self::debug( $e->getMessage() );
			return null;
		}
		// The save itself replaces the request's report.
		self::$deferred = null;

		return $result instanceof ReporterResult ? $result : null;
	}

	/**
	 * One key's check in a result (a result of one key: its check).
	 *
	 * @param ReporterResult $result The result.
	 * @param string         $name   The key's name.
	 * @return KeyCheck|null
	 */
	private static function check_of( ReporterResult $result, string $name ): ?KeyCheck {
		return null !== $result->checks ? ( $result->checks[ $name ] ?? null ) : $result->check;
	}

	/**
	 * Back-end requests that run the plugin's code: checks a changed key and reports
	 * upgrades, once, on `shutdown`.
	 *
	 * @return void
	 */
	public static function on_request(): void {
		self::defer( static fn() => self::reporting()->on_request( self::keys() ), false );
	}

	/**
	 * A translation or an order was rejected with 401/403.
	 *
	 * @param int    $http_status 401 or 403.
	 * @param string $key_name    Reporting::KEY_AI (an AI translation) or Reporting::KEY_ORDERS (an order request).
	 * @return void
	 */
	public static function authentication_failed( int $http_status = 401, string $key_name = Reporting::KEY_AI ): void {
		$status = in_array( $http_status, array( 401, 403 ), true ) ? $http_status : 401;
		self::defer( static fn() => self::reporting()->authentication_failed( self::keys(), $status, $key_name ), true );
	}

	/**
	 * *Test connection*: checks every key (and reports if a status changed).
	 *
	 * @return array<string, KeyCheck> The checks by name.
	 */
	public static function test_connection(): array {
		try {
			$result = self::reporting()->test_connection( self::keys() );
		} catch ( \Throwable $e ) {
			$result = null;
		}
		if ( $result instanceof ReporterResult && null !== $result->checks ) {
			return $result->checks;
		}

		return array( Reporting::KEY_AI => new KeyCheck( KeyCheck::ERROR, KeyCheck::timestamp( microtime( true ) ) ) );
	}

	/**
	 * The latest stored check of a key (default: the AI key).
	 *
	 * @param string $name Reporting::KEY_AI or Reporting::KEY_ORDERS.
	 * @return KeyCheck|null
	 */
	public static function last_check( string $name = Reporting::KEY_AI ): ?KeyCheck {
		try {
			return self::reporting()->last_check( $name );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The latest stored checks by name.
	 *
	 * @return array<string, KeyCheck>
	 */
	public static function last_checks(): array {
		try {
			return self::reporting()->last_checks();
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * A key's name as the interface shows it (the reported names stay English).
	 *
	 * @param string $name Reporting::KEY_AI or Reporting::KEY_ORDERS.
	 * @return string
	 */
	public static function key_label( string $name ): string {
		return Reporting::KEY_ORDERS === $name
			? __( 'Professional translation (account email and Order API Key)', 'supertext-polylang' )
			: __( 'AI translation (API key)', 'supertext-polylang' );
	}

	/**
	 * The plugins (also must-use plugins and drop-ins) and themes installed on this site,
	 * for *Send installed plugins to Supertext*: name, version, active or not, type.
	 * Nothing else (no paths, settings or licence keys).
	 *
	 * @return array<int, array{name: string, version: string, active: bool, type: string}>
	 */
	public static function installed_extensions(): array {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$list = array();
		$add  = static function ( string $type, array $plugins, ?callable $active ) use ( &$list ): void {
			foreach ( $plugins as $file => $data ) {
				$name   = trim( (string) ( $data['Name'] ?? '' ) );
				$list[] = array(
					'name'    => '' !== $name ? $name : (string) $file,
					'version' => (string) ( $data['Version'] ?? '' ),
					'active'  => null === $active ? true : (bool) $active( (string) $file ),
					'type'    => $type,
				);
			}
		};
		if ( function_exists( 'get_plugins' ) ) {
			$add( 'plugin', get_plugins(), 'is_plugin_active' );
		}
		if ( function_exists( 'get_mu_plugins' ) ) {
			$add( 'mu-plugin', get_mu_plugins(), null );
		}
		if ( function_exists( 'get_dropins' ) ) {
			$add( 'drop-in', get_dropins(), null );
		}
		if ( function_exists( 'wp_get_themes' ) ) {
			$active = array( (string) get_stylesheet(), (string) get_template() );
			foreach ( wp_get_themes() as $slug => $theme ) {
				$list[] = array(
					'name'    => (string) $theme->get( 'Name' ),
					'version' => (string) $theme->get( 'Version' ),
					'active'  => in_array( (string) $slug, $active, true ),
					'type'    => 'theme',
				);
			}
		}

		return $list;
	}

	/**
	 * *Send installed plugins to Supertext*: sends the list now.
	 *
	 * @return array{status: string, count: int} status: sent, failed or off.
	 */
	public static function send_extensions(): array {
		if ( ! self::enabled() || null === self::site_url() ) {
			return array( 'status' => 'off', 'count' => 0 );
		}
		try {
			$list   = self::installed_extensions();
			$result = self::reporting()->send_extensions( $list );
			$count  = count( \Supertext\Polylang\PluginHealth\InstallationReporter::normalizeExtensions( $list ) );
		} catch ( \Throwable $e ) {
			self::debug( $e->getMessage() );
			$result = null;
			$count  = 0;
		}

		return array(
			'status' => $result instanceof ReporterResult && $result->sent ? 'sent' : 'failed',
			'count'  => $count,
		);
	}

	/**
	 * *Send installed plugins to Supertext* (admin-post): sends, keeps the result for the next page view.
	 *
	 * @return void
	 */
	public static function handle_send_extensions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-polylang' ) );
		}
		check_admin_referer( self::EXTENSIONS_ACTION );

		set_transient( self::EXTENSIONS_TRANSIENT . '_' . get_current_user_id(), self::send_extensions(), 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=supertext-polylang' ) );
		exit;
	}

	/**
	 * The notice for *Send installed plugins to Supertext*'s result kept for this user, once.
	 *
	 * @return array{type: string, text: string}|null
	 */
	public static function take_extensions_result(): ?array {
		$key   = self::EXTENSIONS_TRANSIENT . '_' . get_current_user_id();
		$value = get_transient( $key );
		if ( ! is_array( $value ) ) {
			return null;
		}
		delete_transient( $key );

		return self::extensions_message( (string) ( $value['status'] ?? '' ), (int) ( $value['count'] ?? 0 ) );
	}

	/**
	 * The notice for a result of *Send installed plugins to Supertext*.
	 *
	 * @param string $status sent, failed or off.
	 * @param int    $count  How many plugins and themes were sent.
	 * @return array{type: string, text: string}
	 */
	public static function extensions_message( string $status, int $count ): array {
		if ( 'sent' === $status ) {
			return array(
				'type' => 'success',
				/* translators: %d: how many plugins and themes were sent. */
				'text' => sprintf( __( 'Sent: %d plugins and themes.', 'supertext-polylang' ), $count ),
			);
		}
		if ( 'off' === $status ) {
			return array(
				'type' => 'warning',
				'text' => sprintf(
					/* translators: %s: the name of the setting "Send version information to Supertext". */
					__( 'Turn on “%s” to send the list.', 'supertext-polylang' ),
					__( 'Send version information to Supertext', 'supertext-polylang' )
				),
			);
		}

		return array(
			'type' => 'error',
			'text' => __( 'The list couldn\'t be sent. Try again later.', 'supertext-polylang' ),
		);
	}

	/**
	 * `admin_init`: on_request() on the Supertext pages and Polylang's settings page.
	 *
	 * @return void
	 */
	public static function maybe_on_request(): void {
		if ( wp_doing_ajax() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which page is shown.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( 0 === strpos( $page, 'supertext-polylang' ) || 'mlang_settings' === $page ) {
			self::on_request();
		}
	}

	/**
	 * `update_option_polylang`: the Supertext service options changed. Runs
	 * after_settings_saved() on `shutdown` unless the save already ran it
	 * (Settings::is_api_key_valid()).
	 *
	 * @param mixed $old_value The previous value.
	 * @param mixed $value     The new value.
	 * @return void
	 */
	public static function polylang_options_updated( $old_value, $value ): void {
		$old = is_array( $old_value ) ? ( $old_value['machine_translation_services']['supertext'] ?? null ) : null;
		$new = is_array( $value ) ? ( $value['machine_translation_services']['supertext'] ?? null ) : null;
		if ( $old === $new || self::$saved ) {
			return;
		}
		self::defer(
			static function () {
				self::after_settings_saved();
				return null;
			},
			true
		);
	}

	/**
	 * `pre_update_option_supertext_polylang_settings`: the Supertext settings page was
	 * saved (options.php). Runs the key check with the new version reports setting and
	 * shows its result. Returns the value unchanged.
	 *
	 * @param mixed $value     The new (sanitized) value.
	 * @param mixed $old_value The previous value.
	 * @return mixed
	 */
	public static function own_settings_saved( $value, $old_value ) {
		unset( $old_value );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php has verified the nonce.
		if ( ! isset( $_POST['option_page'] ) || Settings::GROUP !== $_POST['option_page'] ) {
			return $value;
		}
		$enabled = is_array( $value ) && array_key_exists( 'version_reports', $value ) ? (bool) $value['version_reports'] : null;
		$env     = is_array( $value ) ? (string) ( $value['environment'] ?? '' ) : '';
		$base    = Settings::ENVIRONMENTS[ $env ] ?? null;
		$result  = self::save_and_report( self::keys( null, is_array( $value ) ? $value : null ), null, $enabled, $base );
		if ( null !== $result && function_exists( 'add_settings_error' ) ) {
			foreach ( self::checks_of( $result ) as $name => $check ) {
				$message = self::message( $check, true, $name );
				add_settings_error( Settings::GROUP, 'supertext_key_check_' . sanitize_key( $name ), self::key_label( $name ) . ': ' . $message['text'], $message['type'] );
			}
		}

		return $value;
	}

	/**
	 * *Test connection* (admin-post): runs the check, keeps the result for the next page view.
	 *
	 * @return void
	 */
	public static function handle_test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-polylang' ) );
		}
		check_admin_referer( self::TEST_ACTION );

		set_transient( self::TEST_TRANSIENT . '_' . get_current_user_id(), array_map( static fn( KeyCheck $c ) => $c->toArray(), self::test_connection() ), 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=supertext-polylang' ) );
		exit;
	}

	/**
	 * The *Test connection* result kept for this user, once: the checks by name.
	 *
	 * @return array<string, KeyCheck>|null
	 */
	public static function take_test_result(): ?array {
		$key   = self::TEST_TRANSIENT . '_' . get_current_user_id();
		$value = get_transient( $key );
		if ( ! is_array( $value ) ) {
			return null;
		}
		delete_transient( $key );
		$checks = array();
		foreach ( $value as $name => $check ) {
			$checks[ (string) $name ] = KeyCheck::from( $check );
		}

		return $checks;
	}

	/**
	 * The checks of a result by name (a result of one key: the AI key's).
	 *
	 * @param ReporterResult $result The result.
	 * @return array<string, KeyCheck>
	 */
	private static function checks_of( ReporterResult $result ): array {
		if ( null !== $result->checks ) {
			return $result->checks;
		}

		return null !== $result->check ? array( Reporting::KEY_AI => $result->check ) : array();
	}

	/**
	 * Registers the public ping route.
	 *
	 * @return void
	 */
	public static function register_ping(): void {
		register_rest_route(
			self::PING_NAMESPACE,
			self::PING_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_ping' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The ping: Plugin Health's answer, unchanged (200 with the challenge, 400 for a bad
	 * challenge, 404 while version reports are off), never cached.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public static function handle_ping( $request ): WP_REST_Response {
		$challenge = $request->get_param( 'challenge' );
		$ping      = Reporting::ping( is_string( $challenge ) ? $challenge : null, self::enabled() );
		$response  = new WP_REST_Response( json_decode( $ping->body, true ), $ping->status );
		foreach ( $ping->headers as $name => $value ) {
			$response->header( $name, $value );
		}

		return $response;
	}

	/**
	 * The message for a key check (the Commando Center's five texts).
	 *
	 * @param KeyCheck $check The check.
	 * @param bool     $html  Links as `<a>` (new tab), else bare URLs in brackets.
	 * @param string   $key   Which key: Reporting::KEY_AI (default) or Reporting::KEY_ORDERS (own texts for rejected and missing).
	 * @return array{type: string, text: string} `type`: success, error or warning (WordPress notice types).
	 */
	public static function message( KeyCheck $check, bool $html, string $key = Reporting::KEY_AI ): array {
		$account = 'https://www.supertext.com/person/en/account/signin';
		$api     = 'https://www.supertext.com/en/integrations/api';
		$link    = static fn( string $url, string $text ): string => $html
			? sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $url ), esc_html( $text ) )
			: $text . ' (' . $url . ')';
		$account_link = $link( $account, 'supertext.com' );
		$api_link     = $link( $api, 'supertext.com → Integrations → API' );

		if ( Reporting::KEY_ORDERS === $key && in_array( $check->status, array( KeyCheck::INVALID, KeyCheck::MISSING ), true ) ) {
			$settings_link = $link( Settings::base_url() . 'services/customer/accountsettings', __( 'Supertext account settings', 'supertext-polylang' ) );

			return array(
				'type' => 'error',
				'text' => KeyCheck::INVALID === $check->status
					? sprintf(
						/* translators: 1: "Supertext account settings" (a link), 2: "supertext.com" (a link to create an account). */
						__( 'Supertext rejected the account email or the Order API Key. Check both; you find the Order API Key in your %1$s. No Supertext account yet? Create one at %2$s.', 'supertext-polylang' ),
						$settings_link,
						$account_link
					)
					: sprintf(
						/* translators: 1: "Supertext account settings" (a link), 2: "supertext.com" (a link to create an account). */
						__( 'Enter both the account email and the Order API Key for professional translations; you find the Order API Key in your %1$s. No Supertext account yet? Create one at %2$s.', 'supertext-polylang' ),
						$settings_link,
						$account_link
					),
			);
		}

		switch ( $check->status ) {
			case KeyCheck::OK:
				return array(
					'type' => 'success',
					'text' => __( 'API key checked: the connection to Supertext works.', 'supertext-polylang' ),
				);
			case KeyCheck::INVALID:
				return array(
					'type' => 'error',
					'text' => sprintf(
						/* translators: 1: "supertext.com → Integrations → API" (a link), 2: "supertext.com" (a link to create an account). */
						__( 'Supertext rejected the API key. Check that you copied the whole key, or generate a new one at %1$s (requires the Admin role). No Supertext account yet? Create one at %2$s.', 'supertext-polylang' ),
						$api_link,
						$account_link
					),
				);
			case KeyCheck::MISSING:
				return array(
					'type' => 'error',
					'text' => sprintf(
						/* translators: 1: "supertext.com" (a link to create an account), 2: "supertext.com → Integrations → API" (a link). */
						__( 'No API key is set yet. No Supertext account yet? Create one at %1$s. Generate your API key at %2$s (requires the Admin role).', 'supertext-polylang' ),
						$account_link,
						$api_link
					),
				);
			case KeyCheck::UNREACHABLE:
				return array(
					'type' => 'warning',
					'text' => __( 'The API key couldn\'t be checked because Supertext can\'t be reached. The settings are saved; check again later.', 'supertext-polylang' ),
				);
			default:
				return array(
					'type' => 'warning',
					'text' => str_replace(
						'{status}',
						null !== $check->httpStatus ? (string) $check->httpStatus : '?',
						__( 'The API key couldn\'t be checked: Supertext answered with an error ({status}). The settings are saved; check again later.', 'supertext-polylang' )
					),
				);
		}
	}

	/**
	 * Runs an action once on `shutdown` (authentication_failed wins over on_request).
	 *
	 * @param \Closure(): mixed $action   The action.
	 * @param bool              $priority Whether it replaces a pending lower-priority action.
	 * @return void
	 */
	private static function defer( \Closure $action, bool $priority ): void {
		if ( null !== self::$deferred && self::$deferred_has_priority && ! $priority ) {
			return;
		}
		$first                       = null === self::$deferred;
		self::$deferred              = $action;
		self::$deferred_has_priority = $priority;
		if ( $first && function_exists( 'add_action' ) ) {
			add_action( 'shutdown', array( self::class, 'run_deferred' ), 100 );
		}
	}

	/**
	 * `shutdown`: runs the pending action after the response was sent where possible.
	 *
	 * @return void
	 */
	public static function run_deferred(): void {
		$action         = self::$deferred;
		self::$deferred = null;
		if ( null === $action ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			fastcgi_finish_request();
		}
		try {
			$action();
		} catch ( \Throwable $e ) {
			self::debug( $e->getMessage() );
		}
	}

	/**
	 * Logs at debug level only (never the API key: the SDK keeps it out of messages).
	 *
	 * @param string $message The message.
	 * @return void
	 */
	private static function debug( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[supertext-polylang] version report skipped: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Tests only: forgets this request's state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$deferred              = null;
		self::$deferred_has_priority = false;
		self::$saved                 = false;
	}
}
