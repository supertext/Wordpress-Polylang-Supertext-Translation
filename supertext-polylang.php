<?php
/**
 * Plugin Name:       Supertext for Polylang
 * Plugin URI:        https://github.com/Supertext/supertext-wordpress-polylang
 * Description:       Adds Supertext as a native machine-translation and human translation service in Polylang Pro.
 * Version:           0.14.0
 * Requires PHP:      8.1
 * Author:            Supertext
 * Author URI:        https://www.supertext.com
 * Text Domain:       supertext-polylang
 * License:           GPL-2.0-or-later
 *
 * @package Supertext_Polylang
 */

defined( 'ABSPATH' ) || exit;

define( 'SUPERTEXT_POLYLANG_VERSION', '0.14.0' );
define( 'SUPERTEXT_POLYLANG_FILE', __FILE__ );
define( 'SUPERTEXT_POLYLANG_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Minimal PSR-4 autoloader for the `Supertext\Polylang\` namespace.
 *
 * Classes are only ever loaded lazily — `Service` is not touched until Polylang
 * itself calls into it via the `pll_mt_services` filter, by which point
 * Polylang Pro's interfaces are guaranteed to be loaded.
 */
spl_autoload_register(
	function ( string $class ): void {
		$prefix = 'Supertext\\Polylang\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = SUPERTEXT_POLYLANG_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/**
 * Registers the Supertext machine-translation service with Polylang.
 *
 * Polylang Pro keeps the list of MT services in a hardcoded const
 * (`Machine_Translation\Factory::SERVICES`). For this filter to fire, Polylang's
 * `Factory::get_classnames()` must return `apply_filters( 'pll_mt_services', self::SERVICES )`
 * instead of `self::SERVICES`. See README.md ("Polylang patch") for the one-line change.
 *
 * That single seam feeds three consumers at once: the service picker, the option
 * defaults, and the strict option storage schema — so our `supertext` options
 * actually persist.
 *
 * @param string[] $services List of service class names.
 * @return string[]
 */
add_filter(
	'pll_mt_services',
	function ( array $services ): array {
		$services[] = \Supertext\Polylang\Machine_Translation\Service::class;
		return $services;
	}
);

/**
 * Loads the plugin's own translations (languages/supertext-polylang-<locale>.mo:
 * German, French, Italian). A regional locale without its own file uses its
 * language's (de_CH, de_DE_formal → de_DE; fr_CH → fr_FR; it_CH → it_IT), so the
 * interface follows each user's back-end language. A translation installed in
 * wp-content/languages/plugins wins.
 */
add_action(
	'init',
	function (): void {
		$locale = determine_locale();
		$dir    = SUPERTEXT_POLYLANG_DIR . 'languages/';
		$files  = array( $dir . 'supertext-polylang-' . $locale . '.mo' );
		$base   = array(
			'de' => 'de_DE',
			'fr' => 'fr_FR',
			'it' => 'it_IT',
		);
		$lang   = strtolower( substr( $locale, 0, 2 ) );
		if ( isset( $base[ $lang ] ) ) {
			$files[] = $dir . 'supertext-polylang-' . $base[ $lang ] . '.mo';
		}
		foreach ( $files as $file ) {
			if ( is_readable( $file ) ) {
				load_textdomain( 'supertext-polylang', $file, $locale );
				return;
			}
		}
	}
);

// Only Supertext's own language codes are saved in the language mapping (validated server-side).
// The closure keeps Service unloaded without Polylang Pro (it implements Polylang Pro's interface).
add_filter(
	'pre_update_option_polylang',
	function ( $value ) {
		if ( ! interface_exists( 'WP_Syntex\\Polylang_Pro\\Modules\\Machine_Translation\\Services\\Service_Interface' ) ) {
			return $value;
		}
		return \Supertext\Polylang\Machine_Translation\Service::sanitize_saved_options( $value );
	}
);

// Version reports, the automatic API key check and the public ping (Supertext Commando Center).
\Supertext\Polylang\Reporting\Commando::init();

// Register the "Supertext" admin page (status, Polylang settings link, Patch button).
\Supertext\Polylang\Admin\Page::init();

/**
 * Adds a "Settings" link to the plugin's row on the Plugins screen.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( SUPERTEXT_POLYLANG_FILE ),
	function ( array $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . \Supertext\Polylang\Admin\Page::SETTINGS_SLUG ) ),
			esc_html__( 'Settings', 'supertext-polylang' )
		);
		array_unshift( $links, $settings );
		return $links;
	}
);

// Register the plugin's own settings (human/order API credentials + environment).
\Supertext\Polylang\Admin\Settings::init();

// Register the "Orders" submenu (list / refresh / cancel human-translation orders).
\Supertext\Polylang\Admin\Orders_Page::init();

// Register the "Tools" submenu (ACF field translation overview, …).
\Supertext\Polylang\Admin\Tools_Page::init();

// Register the "Translation files" submenu and the "Export for translation" row action (XLIFF / HTML export and import).
\Supertext\Polylang\Admin\Files_Page::init();

// Plugin detection (Settings-page button) and the enabled third-party integrations.
\Supertext\Polylang\Admin\Integrations::init();

// Gravity Forms integration: render-time translation injection + the (conditional)
// "Gravity Forms" submenu. Both guard themselves on the integration being enabled.
\Supertext\Polylang\Integrations\GravityForms\Integration::init();
\Supertext\Polylang\Integrations\GravityForms\Admin_Page::init();

// Register Gravity Forms strings with Polylang so their translations live in — and
// can be edited from — Languages → String translations.
\Supertext\Polylang\Integrations\GravityForms\Strings::init();

// General "String Translation" page over all Polylang strings (Supertext submenu).
\Supertext\Polylang\Admin\String_Translations_Page::init();

// Human translation product types, loaded from the configured Supertext ServiceID
// (flushes its cache when the settings change).
\Supertext\Polylang\Human_Translation\Services::init();

// Human (professional) translation of arbitrary Polylang strings: order + callback
// writeback into Polylang.
\Supertext\Polylang\Human_Translation\Human_Strings::init();

// Add the "Supertext AI/Human Translation" bulk actions to the posts list table.
\Supertext\Polylang\Admin\Bulk_Actions::init();

// Register the REST callback Supertext calls when a human-translation order is done.
\Supertext\Polylang\Human_Translation\Callback::init();

// Write completed human-translation orders back into the target posts.
\Supertext\Polylang\Human_Translation\Writeback::init();

// Block deleting a source post while it has an in-progress human-translation order.
\Supertext\Polylang\Human_Translation\Source_Lock::init();

// Translate YOOtheme Pro page-builder layouts field-by-field (instead of as a JSON blob).
\Supertext\Polylang\Integrations\YooTheme\Integration::init();

// Secret-link public preview of unpublished posts/pages (on/off + expiry per post).
\Supertext\Polylang\Preview\Draft_Preview::init();

/**
 * Paints the full-colour Supertext logo over the block-editor MT-service icon.
 *
 * Polylang's block editor renders the service icon as a single monochrome SVG
 * path (it only accepts `path_d`), so a colour PNG can't be passed through. Our
 * Service tags that `<svg>` with `.supertext-mt-icon`; here we set the logo as its
 * background and hide the fallback path — entirely within our plugin, no JS patch.
 */
add_action(
	'enqueue_block_editor_assets',
	function (): void {
		$logo = plugins_url( 'assets/icon-v2-128.png', SUPERTEXT_POLYLANG_FILE );
		$css  = sprintf(
			'svg.supertext-mt-icon{background:url("%s") center/contain no-repeat !important;}svg.supertext-mt-icon > *{display:none !important;}',
			esc_url( $logo )
		);
		wp_register_style( 'supertext-polylang-editor', false );
		wp_enqueue_style( 'supertext-polylang-editor' );
		wp_add_inline_style( 'supertext-polylang-editor', $css );
	}
);

/**
 * Warns in the admin if Polylang Pro is missing or has not been patched to expose
 * the `pll_mt_services` filter (without it, this plugin silently does nothing).
 */
add_action(
	'admin_notices',
	function (): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Polylang Pro present?
		if ( ! class_exists( '\WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory' ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Supertext for Polylang requires Polylang Pro (with the Machine Translation module) to be active.', 'supertext-polylang' )
			);
			return;
		}

		// Patch applied? The filter is only honoured if our service shows up in the factory list.
		if ( ! in_array(
			\Supertext\Polylang\Machine_Translation\Service::class,
			\WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory::get_classnames(),
			true
		) ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Supertext for Polylang: Polylang Pro is active but has not been patched to expose the "pll_mt_services" filter, so Supertext cannot register as a translation service yet.', 'supertext-polylang' ),
				esc_url( admin_url( 'admin.php?page=' . \Supertext\Polylang\Admin\Page::SLUG ) ),
				esc_html__( 'Patch Polylang now →', 'supertext-polylang' )
			);
		}
	}
);
