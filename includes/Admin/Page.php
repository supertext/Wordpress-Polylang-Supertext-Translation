<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Human_Translation\Callback;
use Supertext\Polylang\Machine_Translation\Service;
use Supertext\Polylang\Reporting\Commando;
use Supertext\Polylang\Sdk\V1\SupertextException;

/**
 * The "Supertext" admin pages: Settings (with the status panel merged in),
 * Orders and Debug.
 *
 * @since 0.2.0
 */
class Page {
	/**
	 * Top-level / Settings page slug.
	 *
	 * @var string
	 */
	const SLUG = 'supertext-polylang';

	/**
	 * Settings page slug. Kept as an alias of {@see self::SLUG} for back-compat
	 * now that Status is merged into the (top-level) Settings page.
	 *
	 * @var string
	 */
	const SETTINGS_SLUG = self::SLUG;

	/**
	 * Debug page slug.
	 *
	 * @var string
	 */
	const DEBUG_SLUG = 'supertext-polylang-debug';

	/**
	 * admin-post action name for applying the patch.
	 *
	 * @var string
	 */
	const PATCH_ACTION = 'supertext_polylang_apply_patch';

	/**
	 * admin-post action name for clearing the callback log.
	 *
	 * @var string
	 */
	const CLEAR_LOG_ACTION = 'supertext_polylang_clear_callback_log';

	/**
	 * Transient key for one-off admin notices.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT = 'supertext_polylang_notice';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 10 );
		add_action( 'admin_menu', array( self::class, 'register_debug_menu' ), 12 );
		add_action( 'admin_post_' . self::PATCH_ACTION, array( self::class, 'handle_patch' ) );
		add_action( 'admin_post_' . self::CLEAR_LOG_ACTION, array( self::class, 'handle_clear_log' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_style' ) );
	}

	/**
	 * Enqueues the Supertext admin stylesheet on every Supertext screen.
	 *
	 * @return void
	 */
	public static function enqueue_style(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'supertext-polylang-admin',
			plugins_url( 'assets/css/supertext-admin.css', SUPERTEXT_POLYLANG_FILE ),
			array( 'dashicons' ),
			SUPERTEXT_POLYLANG_VERSION
		);
	}

	/**
	 * Registers the top-level menu and its Settings submenu.
	 *
	 * Status is merged into the Settings page, so the top-level page *is* the
	 * Settings page (the first submenu is just renamed to "Settings").
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'Supertext', 'supertext-polylang' ),
			__( 'Supertext', 'supertext-polylang' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render_settings' ),
			self::menu_icon(),
			81
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'supertext-polylang' ),
			__( 'Settings', 'supertext-polylang' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render_settings' )
		);
	}

	/**
	 * Registers the Debug submenu (later, so it appears after Orders).
	 *
	 * @return void
	 */
	public static function register_debug_menu(): void {
		add_submenu_page(
			self::SLUG,
			__( 'Debug', 'supertext-polylang' ),
			__( 'Debug', 'supertext-polylang' ),
			'manage_options',
			self::DEBUG_SLUG,
			array( self::class, 'render_debug' )
		);
	}

	/**
	 * Returns the admin-menu icon (base64 SVG data URI).
	 *
	 * @return string
	 */
	private static function menu_icon(): string {
		if ( defined( 'SUPERTEXT_POLYLANG_DIR' ) ) {
			$svg = SUPERTEXT_POLYLANG_DIR . 'assets/Supertext_S_Glow_White_RGB.svg';
			if ( is_readable( $svg ) ) {
				$data = file_get_contents( $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( is_string( $data ) && '' !== $data ) {
					return 'data:image/svg+xml;base64,' . base64_encode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				}
			}
		}

		return 'dashicons-translation';
	}

	/**
	 * Handles the "Patch Polylang" form submission.
	 *
	 * @return void
	 */
	public static function handle_patch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-polylang' ) );
		}
		check_admin_referer( self::PATCH_ACTION );

		$result = Patch::apply();

		$notice = is_wp_error( $result )
			? array( 'type' => 'error', 'text' => $result->get_error_message() )
			: array( 'type' => 'success', 'text' => __( 'Polylang was patched successfully. Supertext can now register as a machine-translation service.', 'supertext-polylang' ) );

		set_transient( self::NOTICE_TRANSIENT . '_' . get_current_user_id(), $notice, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) );
		exit;
	}

	/**
	 * Clears the recorded callback log.
	 *
	 * @return void
	 */
	public static function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-polylang' ) );
		}
		check_admin_referer( self::CLEAR_LOG_ACTION );

		Callback::clear_log();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::DEBUG_SLUG ) );
		exit;
	}

	/**
	 * Returns the URL of Polylang's settings page.
	 *
	 * @return string
	 */
	private static function polylang_settings_url(): string {
		return admin_url( 'admin.php?page=mlang_settings' );
	}

	/**
	 * Prints the page header (logo + title).
	 *
	 * @param string $title Page title.
	 * @return void
	 */
	public static function hero( string $title, string $subtitle = '' ): void {
		$logo = defined( 'SUPERTEXT_POLYLANG_FILE' ) ? plugins_url( 'assets/Supertext_S_Glow_Light_Green_RGB.png', SUPERTEXT_POLYLANG_FILE ) : '';
		?>
		<div class="st-hero">
			<?php if ( $logo ) : ?>
				<span class="st-hero__logo"><img src="<?php echo esc_url( $logo ); ?>" width="34" height="34" alt="" /></span>
			<?php endif; ?>
			<div class="st-hero__text">
				<h1 class="st-hero__title"><?php echo esc_html( $title ); ?></h1>
				<?php if ( '' !== $subtitle ) : ?>
					<p class="st-hero__subtitle"><?php echo esc_html( $subtitle ); ?></p>
				<?php endif; ?>
			</div>
			<span class="st-hero__version">
				<?php
				/* translators: %s is the plugin version. */
				printf( esc_html__( 'Version %s', 'supertext-polylang' ), esc_html( defined( 'SUPERTEXT_POLYLANG_VERSION' ) ? SUPERTEXT_POLYLANG_VERSION : '' ) );
				?>
			</span>
		</div>
		<?php
	}

	/**
	 * Prints the status panel (Polylang / patch / service health).
	 *
	 * @return void
	 */
	private static function render_status_panel(): void {
		$polylang = Patch::polylang_available();
		$patched  = $polylang && Patch::is_patched();
		$active   = self::service_is_active();
		?>
		<div class="st-card">
			<div class="st-card__head">
				<span class="st-tile"><span class="dashicons <?php echo esc_attr( $polylang && $patched && $active ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?>"></span></span>
				<div>
					<h2 class="st-card__title"><?php esc_html_e( 'Status', 'supertext-polylang' ); ?></h2>
					<p class="st-card__subtitle">
						<?php
						if ( $polylang && $patched && $active ) {
							esc_html_e( 'Everything is connected and running.', 'supertext-polylang' );
						} else {
							esc_html_e( 'Not everything is set up yet: see the red rows below.', 'supertext-polylang' );
						}
						?>
					</p>
				</div>
			</div>
			<div class="st-rows">
				<?php
				self::status_row(
					__( 'Polylang Pro (Machine Translation)', 'supertext-polylang' ),
					$polylang,
					$polylang ? __( 'Active', 'supertext-polylang' ) : __( 'Not active', 'supertext-polylang' )
				);
				self::status_row(
					__( 'Polylang patched (pll_mt_services filter)', 'supertext-polylang' ),
					$patched,
					$patched ? __( 'Patched', 'supertext-polylang' ) : __( 'Not patched', 'supertext-polylang' )
				);
				self::status_row(
					__( 'Supertext service configured & active', 'supertext-polylang' ),
					$active,
					$active ? __( 'Active', 'supertext-polylang' ) : __( 'Inactive (enter an API key in Polylang settings)', 'supertext-polylang' )
				);

				self::render_key_check_row();
				self::render_language_rows();

				$reports = Commando::enabled();
				self::state_row(
					__( 'Send version information to Supertext', 'supertext-polylang' ),
					$reports ? 'ok' : 'off',
					$reports ? __( 'On', 'supertext-polylang' ) : __( 'Off', 'supertext-polylang' )
				);
				self::render_send_extensions_row();

				// Optional features & third-party integrations (a disabled feature is a
				// choice, not an error — shown neutral rather than red).
				printf(
					'<div class="st-subhead">%s</div>',
					esc_html__( 'Features & integrations', 'supertext-polylang' )
				);

				$preview_on = Settings::preview_links_enabled();
				self::state_row(
					__( 'Secret preview links', 'supertext-polylang' ),
					$preview_on ? 'ok' : 'off',
					$preview_on ? __( 'On', 'supertext-polylang' ) : __( 'Off', 'supertext-polylang' )
				);

				$shots_on = Settings::screenshots_enabled();
				if ( ! $shots_on ) {
					self::state_row( __( 'Page screenshots (VibeBoost)', 'supertext-polylang' ), 'off', __( 'Off', 'supertext-polylang' ) );
				} elseif ( '' === Settings::screenshots_api_key() ) {
					self::state_row( __( 'Page screenshots (VibeBoost)', 'supertext-polylang' ), 'warn', __( 'On — but no VibeBoost API key is set, so captures will fail', 'supertext-polylang' ) );
				} elseif ( $preview_on ) {
					self::state_row( __( 'Page screenshots (VibeBoost)', 'supertext-polylang' ), 'ok', __( 'On', 'supertext-polylang' ) );
				} else {
					self::state_row( __( 'Page screenshots (VibeBoost)', 'supertext-polylang' ), 'warn', __( 'On — but Secret preview links are off, so drafts cannot be captured', 'supertext-polylang' ) );
				}

				foreach ( Integrations::supported() as $slug => $info ) {
					if ( Integrations::enabled( $slug ) ) {
						$state = 'ok';
						$text  = __( 'Detected & enabled', 'supertext-polylang' );
					} elseif ( ! empty( $info['detected'] ) ) {
						$state = 'warn';
						$text  = __( 'Active — click “Detect plugins” to enable', 'supertext-polylang' );
					} else {
						$state = 'off';
						$text  = __( 'Not detected', 'supertext-polylang' );
					}
					/* translators: %s is the plugin name (e.g. Gravity Forms). */
					self::state_row( sprintf( __( 'Integration: %s', 'supertext-polylang' ), $info['label'] ), $state, $text );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Prints the latest automatic API key check (with its message) and the
	 * *Test connection* button.
	 *
	 * @return void
	 */
	private static function render_key_check_row(): void {
		$checks = Commando::last_checks();
		if ( array() === $checks ) {
			self::state_row( __( 'API key check', 'supertext-polylang' ), 'off', __( 'Not checked yet', 'supertext-polylang' ) );
		}
		foreach ( $checks as $name => $check ) {
			$message = Commando::message( $check, true, $name );
			$state   = 'success' === $message['type'] ? 'ok' : ( 'error' === $message['type'] ? 'bad' : 'warn' );
			/* translators: %s: which key, e.g. "AI translation (API key)". */
			self::state_row( sprintf( __( 'API key check: %s', 'supertext-polylang' ), Commando::key_label( $name ) ), $state, Commando::checked_at( $check ) );
			printf(
				'<div class="st-row"><span class="st-row__label">%s</span></div>',
				wp_kses( $message['text'], array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) )
			);
		}
		?>
		<div class="st-row">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Commando::TEST_ACTION ); ?>" />
				<?php wp_nonce_field( Commando::TEST_ACTION ); ?>
				<?php submit_button( __( 'Test connection', 'supertext-polylang' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints the *Send plugins and languages to Supertext* button with its help text.
	 *
	 * @return void
	 */
	private static function render_send_extensions_row(): void {
		?>
		<div class="st-row">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Commando::EXTENSIONS_ACTION ); ?>" />
				<?php wp_nonce_field( Commando::EXTENSIONS_ACTION ); ?>
				<?php submit_button( __( 'Send plugins and languages to Supertext', 'supertext-polylang' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Sends Supertext the plugins and themes installed on this site (name, version, active or not) and its languages with the Supertext language each one is translated into, so Supertext support can help. Nothing else is sent.', 'supertext-polylang' ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints one row per Polylang language with its Supertext language, or that it
	 * isn't mapped (translations into or from it stop with unsupported_language).
	 *
	 * @return void
	 */
	private static function render_language_rows(): void {
		// Service implements a Polylang Pro interface: loading it without Pro is a fatal error.
		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) || ! Patch::polylang_available() ) {
			return;
		}
		$service = PLL()->options['machine_translation_services'][ Service::get_slug() ] ?? array();
		$mapping = is_array( $service ) && isset( $service['languages'] ) && is_array( $service['languages'] ) ? $service['languages'] : array();

		printf( '<div class="st-subhead">%s</div>', esc_html__( 'Languages (Supertext language mapping)', 'supertext-polylang' ) );
		foreach ( PLL()->model->get_languages_list() as $language ) {
			$label = $language->name . ' (' . $language->locale . ')';
			try {
				$code = Service::resolve_target( $language, $mapping );
				self::state_row( $label, 'ok', $code );
			} catch ( SupertextException $e ) {
				self::state_row( $label, 'bad', __( 'Not mapped: choose its Supertext language in the Polylang settings', 'supertext-polylang' ) );
			}
		}
	}

	/**
	 * Prints a tri-state status row (for optional features / integrations, where
	 * "off" is a neutral choice rather than an error).
	 *
	 * @param string $label Row label.
	 * @param string $state One of 'ok' (green ✓), 'warn' (amber ⚠), 'off' (grey •).
	 * @param string $text  Status text.
	 * @return void
	 */
	private static function state_row( string $label, string $state, string $text ): void {
		$map = array(
			'ok'   => array( '', 'dashicons-yes' ),
			'warn' => array( '--warn', 'dashicons-warning' ),
			'off'  => array( '--off', 'dashicons-minus' ),
			'bad'  => array( '--bad', 'dashicons-no-alt' ),
		);
		list( $modifier, $icon ) = $map[ $state ] ?? $map['off'];
		printf(
			'<div class="st-row"><span class="st-row__label">%s</span><span class="st-badge st-badge%s"><span class="dashicons %s"></span>%s</span></div>',
			esc_html( $label ),
			esc_attr( $modifier ),
			esc_attr( $icon ),
			esc_html( $text )
		);
	}

	/**
	 * Settings page: status panel, setup (patch + configure) and human
	 * translation services.
	 *
	 * @return void
	 */
	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$polylang = Patch::polylang_available();
		$patched  = $polylang && Patch::is_patched();

		// One-off notice from the patch handler.
		$notice_key = self::NOTICE_TRANSIENT . '_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		$tested     = Commando::take_test_result();
		foreach ( $tested ?? array() as $name => $check ) {
			$message = Commando::message( $check, true, $name );
			printf(
				'<div class="notice notice-%s is-dismissible"><p><strong>%s:</strong> %s</p></div>',
				esc_attr( $message['type'] ),
				esc_html( Commando::key_label( $name ) ),
				wp_kses( $message['text'], array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) )
			);
		}
		$sent = Commando::take_extensions_result();
		if ( null !== $sent ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $sent['type'] ), esc_html( $sent['text'] ) );
		}
		if ( is_array( $notice ) ) {
			delete_transient( $notice_key );
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
				esc_html( $notice['text'] )
			);
		}
		?>
		<div class="wrap supertext-admin st-narrow">
			<?php
			self::hero(
				__( 'Supertext for Polylang', 'supertext-polylang' ),
				__( 'Machine & professional translation, wired straight into Polylang.', 'supertext-polylang' )
			);
			self::render_status_panel();
			?>

			<div class="st-card">
				<div class="st-card__head">
					<span class="st-tile"><span class="dashicons dashicons-admin-tools"></span></span>
					<div>
						<h2 class="st-card__title"><?php esc_html_e( 'Setup', 'supertext-polylang' ); ?></h2>
						<p class="st-card__subtitle"><?php esc_html_e( 'Two steps to get translations flowing.', 'supertext-polylang' ); ?></p>
					</div>
				</div>

				<div class="st-step">
					<span class="st-step__num">1</span>
					<div class="st-step__body">
						<div class="st-step__title"><?php esc_html_e( 'Patch Polylang', 'supertext-polylang' ); ?></div>
						<p><?php esc_html_e( 'Polylang Pro keeps its translation services in a fixed list. This adds a small filter so Supertext can register itself. It backs up the original file and is safe to run again after a Polylang update.', 'supertext-polylang' ); ?></p>
						<?php if ( ! $polylang ) : ?>
							<p><em><?php esc_html_e( 'Activate Polylang Pro first.', 'supertext-polylang' ); ?></em></p>
						<?php elseif ( $patched ) : ?>
							<div class="st-step__actions">
								<span class="st-inline-ok st-inline-ok--btn-h"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'Polylang is already patched.', 'supertext-polylang' ); ?></span>
								<?php self::patch_form( __( 'Re-apply patch', 'supertext-polylang' ), 'secondary' ); ?>
							</div>
						<?php else : ?>
							<?php self::patch_form( __( 'Patch Polylang', 'supertext-polylang' ), 'primary' ); ?>
						<?php endif; ?>
					</div>
				</div>

				<div class="st-step">
					<span class="st-step__num">2</span>
					<div class="st-step__body">
						<div class="st-step__title"><?php esc_html_e( 'Configure the AI service', 'supertext-polylang' ); ?></div>
						<p><?php esc_html_e( 'Enable Machine Translation, choose Supertext, enter your API key, and map your languages in the Polylang settings.', 'supertext-polylang' ); ?></p>
						<p>
							<?php
							printf(
								/* translators: 1: link to create a Supertext account (or log in), 2: link to the Supertext API integrations page. */
								esc_html__( 'No Supertext account yet? Create one at %1$s. Generate your API key at %2$s (requires the Admin role).', 'supertext-polylang' ),
								sprintf(
									'<a href="%s" target="_blank" rel="noopener">%s</a>',
									esc_url( 'https://www.supertext.com/person/en/account/signin' ),
									esc_html__( 'supertext.com', 'supertext-polylang' )
								),
								sprintf(
									'<a href="%s" target="_blank" rel="noopener">%s</a>',
									esc_url( 'https://www.supertext.com/en/integrations/api' ),
									esc_html__( 'supertext.com → Integrations → API', 'supertext-polylang' )
								)
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link built from escaped parts.
							?>
						</p>
						<a class="button button-primary st-cta-wide st-btn-plainfont" href="<?php echo esc_url( self::polylang_settings_url() ); ?>">
							<?php esc_html_e( 'Open Polylang → Languages → Settings → Machine Translation → Supertext', 'supertext-polylang' ); ?>
						</a>
					</div>
				</div>
			</div>

			<div class="st-card">
				<div class="st-card__head">
					<span class="st-tile"><span class="dashicons dashicons-groups"></span></span>
					<div>
						<h2 class="st-card__title"><?php esc_html_e( 'Translation Services (human)', 'supertext-polylang' ); ?></h2>
						<p class="st-card__subtitle"><?php esc_html_e( 'Professional (human) translation orders use a separate credential from the AI translation API key. Enter your Supertext account email and Order API Key below.', 'supertext-polylang' ); ?></p>
					</div>
				</div>
				<?php
				settings_errors( Settings::GROUP );
				Settings::render_form();
				?>
			</div>

			<div class="st-card">
				<?php Integrations::render_section(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Debug page: order callback log.
	 *
	 * @return void
	 */
	public static function render_debug(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap supertext-admin st-narrow">
			<?php
			self::hero(
				__( 'Supertext Debug', 'supertext-polylang' ),
				__( 'Order-callback log & diagnostics.', 'supertext-polylang' )
			);
			?>
			<div class="st-card">
				<?php self::render_callback_log(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the recorded order-callback payloads.
	 *
	 * @return void
	 */
	private static function render_callback_log(): void {
		$entries = Callback::get_log();
		?>
		<h2 style="margin-top:1em;"><?php esc_html_e( 'Order callbacks (debug)', 'supertext-polylang' ); ?></h2>
		<p class="description" style="max-width:640px;">
			<?php esc_html_e( 'The most recent payload Supertext POSTed to the callback URL is recorded here, so we can see exactly what the system sends when an order completes.', 'supertext-polylang' ); ?>
		</p>
		<p><code><?php echo esc_html( Callback::url() ); ?></code></p>

		<?php if ( empty( $entries ) ) : ?>
			<p><em><?php esc_html_e( 'No callbacks recorded yet.', 'supertext-polylang' ); ?></em></p>
		<?php else : ?>
			<?php foreach ( $entries as $entry ) : ?>
				<div style="margin:1em 0;padding:8px 12px;border:1px solid #dcdcde;background:#fff;max-width:900px;">
					<p style="margin:0 0 6px;">
						<strong><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></strong>
						— <?php echo esc_html( (string) ( $entry['method'] ?? '' ) ); ?>
						<?php echo esc_html( (string) ( $entry['content_type'] ?? '' ) ); ?>
					</p>
					<pre style="white-space:pre-wrap;word-break:break-word;max-height:320px;overflow:auto;margin:0;"><?php echo esc_html( self::pretty_json( (string) ( $entry['body'] ?? '' ) ) ); ?></pre>
				</div>
			<?php endforeach; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_LOG_ACTION ); ?>" />
				<?php wp_nonce_field( self::CLEAR_LOG_ACTION ); ?>
				<?php submit_button( __( 'Clear callback log', 'supertext-polylang' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Pretty-prints a JSON string if possible.
	 *
	 * @param string $raw Raw body.
	 * @return string
	 */
	private static function pretty_json( string $raw ): string {
		$decoded = json_decode( $raw, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return $raw;
		}
		$pretty = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false !== $pretty ? $pretty : $raw;
	}

	/**
	 * Renders the patch submission form.
	 *
	 * @param string $label       Button label.
	 * @param string $button_type 'primary' or 'secondary'.
	 * @return void
	 */
	private static function patch_form( string $label, string $button_type ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::PATCH_ACTION ); ?>" />
			<?php wp_nonce_field( self::PATCH_ACTION ); ?>
			<?php submit_button( $label, $button_type, 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Prints a status table row with a coloured dot.
	 *
	 * @param string $label Row label.
	 * @param bool   $ok    Whether the state is good.
	 * @param string $text  Status text.
	 * @return void
	 */
	private static function status_row( string $label, bool $ok, string $text ): void {
		printf(
			'<div class="st-row"><span class="st-row__label">%s</span><span class="st-badge %s"><span class="dashicons %s"></span>%s</span></div>',
			esc_html( $label ),
			esc_attr( $ok ? '' : 'st-badge--bad' ),
			esc_attr( $ok ? 'dashicons-yes' : 'dashicons-no-alt' ),
			esc_html( $text )
		);
	}

	/**
	 * Tells whether the Supertext service is registered and active.
	 *
	 * @return bool
	 */
	private static function service_is_active(): bool {
		if ( ! Patch::polylang_available() ) {
			return false;
		}

		$factory = '\WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory';
		if ( ! in_array( Service::class, $factory::get_classnames(), true ) ) {
			return false;
		}

		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return false;
		}

		$instance = new Service(
			PLL()->options['machine_translation_services'][ Service::get_slug() ] ?? array(),
			PLL()->model
		);

		return $instance->is_active();
	}
}
