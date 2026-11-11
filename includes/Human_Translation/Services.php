<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Human_Translation;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Admin\Settings;

/**
 * The human translation product types ("Translation type" dropdown) offered by the
 * configured Supertext ServiceId.
 *
 * The list is loaded from `GET api/v1/util/ordertype/{ServiceId}` and cached in a
 * transient, so the dropdowns never pay for an HTTP round-trip on every admin page
 * load. Because the list gates ordering, a failed fetch must not empty the dropdown:
 * {@see Services::FALLBACK} (the three types of the default ServiceId 46) is used
 * instead, cached briefly so the next page load retries soon.
 *
 * Entries are keyed by `OrderTypeConfigurationId` — the id sent as the order's
 * `OrderTypeConfigurationId` and the quote's `OrderTypeConfigurationId` — and carry
 * the matching `order_type_id` (the quote's `OrderTypeId`).
 *
 * @since 0.11.0
 */
class Services {
	/** Transient key prefix (one entry per environment + ServiceId). */
	const CACHE_PREFIX = 'supertext_polylang_order_types_';

	/** How long a successful fetch is cached (12 hours). */
	const CACHE_TTL = 43200;

	/** How long a failed fetch is cached (5 minutes) before retrying. */
	const CACHE_TTL_FAILED = 300;

	/**
	 * Fallback product types, used when the API cannot be reached.
	 *
	 * These are the three types of the default ServiceId 46. Keyed by
	 * OrderTypeConfigurationId; `order_type_id` is the matching OrderTypeId.
	 *
	 * @var array<int, array{label: string, order_type_id: int, description: string, short: string}>
	 */
	const FALLBACK = array(
		166 => array(
			'label'         => 'Translation BASIC',
			'order_type_id' => 54,
			'description'   => '',
			'short'         => 'For general texts with a limited reach',
		),
		167 => array(
			'label'         => 'Translation PREMIUM',
			'order_type_id' => 55,
			'description'   => '',
			'short'         => 'For demanding content where every word counts',
		),
		168 => array(
			'label'         => 'Translation CREATIVE',
			'order_type_id' => 56,
			'description'   => '',
			'short'         => 'For creative content, newsletters, campaigns, etc.',
		),
	);

	/**
	 * Per-request memo, so repeated lookups (one per Orders-page row) don't re-read
	 * the transient or re-run the filter. Keyed by cache key.
	 *
	 * @var array<string, array>
	 */
	private static $memo = array();

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Saving the settings may change the environment or the ServiceId: drop the
		// cached list for the old *and* the new combination.
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'on_settings_saved' ), 10, 2 );
	}

	/**
	 * Returns the available product types, keyed by OrderTypeConfigurationId.
	 *
	 * @return array<int, array{label: string, order_type_id: int, description: string, short: string}>
	 */
	public static function all(): array {
		$service_id = Settings::service_id();
		$key        = self::cache_key( $service_id );

		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		$cached = get_transient( $key );

		if ( is_array( $cached ) && ! empty( $cached ) ) {
			$services = $cached;
		} elseif ( ! Settings::is_configured() ) {
			// No credentials: the API would reject us anyway. Offer the fallback
			// without caching it, so the real list is fetched as soon as the
			// credentials are saved.
			$services = self::FALLBACK;
		} else {
			$client  = new Client();
			$fetched = $client->get_order_types( $service_id );
			$parsed  = is_array( $fetched ) ? self::parse( $fetched ) : array();

			if ( ! empty( $parsed ) ) {
				$services = $parsed;
				set_transient( $key, $services, self::CACHE_TTL );
			} else {
				$services = self::FALLBACK;
				set_transient( $key, $services, self::CACHE_TTL_FAILED );
			}
		}

		/**
		 * Filters the human translation product types offered in the order dropdowns.
		 *
		 * A callback must keep the entry shape: an array keyed by
		 * OrderTypeConfigurationId, each entry with `label`, `order_type_id`,
		 * `description` and `short`.
		 *
		 * @param array $services   Entries keyed by OrderTypeConfigurationId, each
		 *                          with `label`, `order_type_id`, `description`, `short`.
		 * @param int   $service_id The Supertext ServiceId the list was loaded for.
		 */
		self::$memo[ $key ] = apply_filters( 'supertext_polylang_human_services', $services, $service_id );

		return self::$memo[ $key ];
	}

	/**
	 * Normalizes an `api/v1/util/ordertype/{id}` response into our internal shape.
	 *
	 * Entries without a usable OrderTypeConfigurationId are skipped; the API's order
	 * is preserved (it is the order Supertext wants them presented in).
	 *
	 * @param array $raw The decoded API response (a list of objects).
	 * @return array<int, array{label: string, order_type_id: int, description: string, short: string}>
	 */
	public static function parse( array $raw ): array {
		$out = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$config_id = (int) ( $entry['OrderTypeConfigurationId'] ?? 0 );
			if ( $config_id <= 0 ) {
				continue;
			}

			$name = trim( (string) ( $entry['Name'] ?? '' ) );

			$out[ $config_id ] = array(
				'label'         => '' !== $name ? $name : (string) $config_id,
				'order_type_id' => (int) ( $entry['OrderTypeId'] ?? 0 ),
				'description'   => trim( (string) ( $entry['Description'] ?? '' ) ),
				'short'         => trim( (string) ( $entry['ShortDescription'] ?? '' ) ),
			);
		}

		return $out;
	}

	/**
	 * Whether the given OrderTypeConfigurationId is offered (order/quote whitelist).
	 *
	 * @param int $config_id OrderTypeConfigurationId.
	 * @return bool
	 */
	public static function exists( int $config_id ): bool {
		return isset( self::all()[ $config_id ] );
	}

	/**
	 * Returns the display label for an OrderTypeConfigurationId (falls back to the id).
	 *
	 * @param int $config_id OrderTypeConfigurationId.
	 * @return string
	 */
	public static function label( int $config_id ): string {
		$services = self::all();

		if ( isset( $services[ $config_id ]['label'] ) ) {
			return (string) $services[ $config_id ]['label'];
		}

		// An order placed with a type that is no longer offered (or a changed
		// ServiceId) still has to render on the Orders page.
		return (string) ( self::FALLBACK[ $config_id ]['label'] ?? $config_id );
	}

	/**
	 * Returns the Supertext OrderTypeId for an OrderTypeConfigurationId, or 0.
	 *
	 * @param int $config_id OrderTypeConfigurationId.
	 * @return int
	 */
	public static function order_type_id( int $config_id ): int {
		$services = self::all();

		return (int) ( $services[ $config_id ]['order_type_id'] ?? self::FALLBACK[ $config_id ]['order_type_id'] ?? 0 );
	}

	/**
	 * Drops the cached list, so the next read re-fetches it.
	 *
	 * @param int|null    $service_id ServiceId to flush (default: the configured one).
	 * @param string|null $env        Environment slug (default: the configured one).
	 * @return void
	 */
	public static function flush( ?int $service_id = null, ?string $env = null ): void {
		self::$memo = array();

		delete_transient( self::cache_key( null !== $service_id ? $service_id : Settings::service_id(), $env ) );
	}

	/**
	 * Flushes the cache for both the previous and the new settings.
	 *
	 * @param mixed $old_value The settings before the save.
	 * @param mixed $value     The settings after the save.
	 * @return void
	 */
	public static function on_settings_saved( $old_value, $value ): void {
		foreach ( array( $old_value, $value ) as $settings ) {
			if ( ! is_array( $settings ) ) {
				continue;
			}

			$service_id = (int) ( $settings['service_id'] ?? 0 );
			$env        = (string) ( $settings['environment'] ?? '' );

			if ( $service_id > 0 && '' !== $env ) {
				self::flush( $service_id, $env );
			}
		}
	}

	/**
	 * Builds the transient key for an environment + ServiceId pair.
	 *
	 * The language is part of the key: the API returns the type names and
	 * descriptions in the language of the Accept-Language header, so a German and
	 * a French admin must not share one cached list.
	 *
	 * @param int         $service_id The Supertext ServiceId.
	 * @param string|null $env        Environment slug (default: the configured one).
	 * @return string
	 */
	private static function cache_key( int $service_id, ?string $env = null ): string {
		// The primary language tag of the header, e.g. `de-ch` out of `de-CH,de;q=0.9`.
		$language = strtolower( (string) strtok( Client::accept_language(), ',' ) );

		return self::CACHE_PREFIX . ( null !== $env ? $env : Settings::environment() ) . '_' . $service_id . '_' . $language;
	}
}
