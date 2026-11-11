<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-owned settings for the Supertext human / professional translation order
 * API — environment + Basic-auth credentials (account email + Order API Key).
 *
 * Stored in the plugin's own option (not in Polylang's machine-translation
 * settings, which only hold the AI service config). Rendered on the Supertext
 * admin page via the WordPress Settings API.
 *
 * @since 0.5.0
 */
class Settings {
	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'supertext_polylang_settings';

	/**
	 * Settings group / section.
	 *
	 * @var string
	 */
	const GROUP = 'supertext_polylang_settings_group';

	/**
	 * Supertext environment slug => base URL.
	 *
	 * @var array<string, string>
	 */
	const ENVIRONMENTS = array(
		'live'    => 'https://www.supertext.com/',
		'staging' => 'https://staging.supertext.com/',
		'testing' => 'https://testing.supertext.com/',
	);

	/**
	 * Default Supertext ServiceID — the order-type group whose translation types
	 * (BASIC / PREMIUM / CREATIVE) are offered when ordering.
	 *
	 * @var int
	 */
	const DEFAULT_SERVICE_ID = 46;

	/**
	 * Server variables (wp-config.php constant, else environment variable) used while
	 * their field is empty, like SUPERTEXT_API_KEY for the AI key: so a host such as
	 * Railway can provide the credentials without anyone typing them in.
	 *
	 * @var array<string, string>
	 */
	const FALLBACKS = array(
		'human_email'         => 'SUPERTEXT_ACCOUNT_EMAIL',
		'human_api_key'       => 'SUPERTEXT_ORDER_API_KEY',
		'screenshots_api_key' => 'VIBEBOOST_API_KEY',
	);

	/**
	 * Registers the setting.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'register' ) );
	}

	/**
	 * Registers the option with the Settings API.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Default values.
	 *
	 * @return array{environment: string, human_email: string, human_api_key: string}
	 */
	public static function defaults(): array {
		return array(
			'environment'               => 'live',
			'human_email'               => '',
			'human_api_key'             => '',
			'service_id'                => self::DEFAULT_SERVICE_ID,
			'allow_multiple_writebacks' => false,
			'writeback_status'          => 'draft',
			'preview_links_enabled'     => true,
			'screenshots_enabled'       => true,
			'screenshots_api_key'       => '',
			'version_reports'           => true,
		);
	}

	/**
	 * Sanitizes the submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$env = (string) ( $input['environment'] ?? 'live' );
		$out['environment'] = isset( self::ENVIRONMENTS[ $env ] ) ? $env : 'live';

		$out['human_email']   = sanitize_email( (string) ( $input['human_email'] ?? '' ) );
		$out['human_api_key'] = sanitize_text_field( (string) ( $input['human_api_key'] ?? '' ) );

		// A blank or nonsensical ServiceID would empty the translation-type dropdown.
		$service_id        = (int) ( $input['service_id'] ?? 0 );
		$out['service_id'] = $service_id > 0 ? $service_id : self::DEFAULT_SERVICE_ID;

		$out['allow_multiple_writebacks'] = ! empty( $input['allow_multiple_writebacks'] );

		$status                  = (string) ( $input['writeback_status'] ?? 'draft' );
		$out['writeback_status'] = in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';

		$out['preview_links_enabled'] = ! empty( $input['preview_links_enabled'] );
		$out['screenshots_enabled']   = ! empty( $input['screenshots_enabled'] );
		$out['screenshots_api_key']   = sanitize_text_field( (string) ( $input['screenshots_api_key'] ?? '' ) );
		$out['version_reports']       = ! empty( $input['version_reports'] );

		return $out;
	}

	/**
	 * Returns the stored settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Returns the configured environment slug.
	 *
	 * @return string
	 */
	public static function environment(): string {
		$env = self::environment_override() ?? self::get()['environment'];
		return isset( self::ENVIRONMENTS[ $env ] ) ? $env : 'live';
	}

	/**
	 * The environment of professional orders set on the server, which wins over the
	 * setting: SUPERTEXT_HUMAN_ENVIRONMENT, else SUPERTEXT_ENVIRONMENT (live, staging or
	 * testing; wp-config.php constant or environment variable). Null when none is set.
	 *
	 * @return string|null
	 */
	public static function environment_override(): ?string {
		foreach ( array( 'SUPERTEXT_HUMAN_ENVIRONMENT', 'SUPERTEXT_ENVIRONMENT' ) as $name ) {
			$value = strtolower( self::server_value( $name ) );
			if ( isset( self::ENVIRONMENTS[ $value ] ) ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * A server variable: the wp-config.php constant, else the environment variable ('' if neither).
	 *
	 * @param string $name Variable name.
	 * @return string
	 */
	public static function server_value( string $name ): string {
		if ( defined( $name ) ) {
			return trim( (string) constant( $name ) );
		}
		$env = getenv( $name );

		return false === $env ? '' : trim( $env );
	}

	/**
	 * Prints "set by <variable> on the server" under a setting a server variable decides.
	 *
	 * @param string $name Variable name.
	 * @return void
	 */
	public static function print_server_note( string $name ): void {
		printf(
			'<p class="description"><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: %s: name of the server variable, e.g. SUPERTEXT_AI_ENVIRONMENT. */
					__( 'Set by %s on the server; change it there.', 'supertext-polylang' ),
					$name
				)
			)
		);
	}

	/**
	 * Returns the base URL of the configured environment.
	 *
	 * @return string
	 */
	public static function base_url(): string {
		return self::ENVIRONMENTS[ self::environment() ];
	}

	/**
	 * Returns the URL of an order's detail page in the configured environment,
	 * e.g. https://www.supertext.com/en/orders/737522.
	 *
	 * @param int $order_id The order id.
	 * @return string
	 */
	public static function order_url( int $order_id ): string {
		/** @var string $locale */
		$locale = apply_filters( 'supertext_polylang_order_url_locale', 'en' );
		return self::base_url() . $locale . '/orders/' . $order_id;
	}

	/**
	 * Returns the URL of the customer's orders overview in the configured
	 * environment, e.g. https://www.supertext.com/en/orders.
	 *
	 * @return string
	 */
	public static function orders_url(): string {
		/** @var string $locale */
		$locale = apply_filters( 'supertext_polylang_order_url_locale', 'en' );
		return self::base_url() . $locale . '/orders';
	}

	/**
	 * Returns the human/order API account email.
	 *
	 * @return string
	 */
	public static function email(): string {
		return self::resolved( self::get(), 'human_email' );
	}

	/**
	 * A field's value, or its server variable's (FALLBACKS) while the field is empty.
	 *
	 * @param array<string, mixed> $values The settings (stored or being saved).
	 * @param string               $field  human_email, human_api_key or screenshots_api_key.
	 * @return string
	 */
	public static function resolved( array $values, string $field ): string {
		$value = trim( (string) ( $values[ $field ] ?? '' ) );

		return '' !== $value ? $value : self::fallback( $field );
	}

	/**
	 * The server variable's value for a field ('' if it has none or it isn't set).
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	public static function fallback( string $field ): string {
		$name = self::FALLBACKS[ $field ] ?? '';
		if ( '' === $name ) {
			return '';
		}
		return self::server_value( $name );
	}

	/**
	 * Prints "the value from <variable> on the server is used" under a field that is
	 * empty while its server variable is set.
	 *
	 * @param array<string, mixed> $current The stored settings.
	 * @param string               $field   Field name.
	 * @return void
	 */
	private static function print_fallback_note( array $current, string $field ): void {
		if ( '' !== trim( (string) ( $current[ $field ] ?? '' ) ) || '' === self::fallback( $field ) ) {
			return;
		}
		printf(
			'<p class="description"><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: %s: name of the server variable, e.g. SUPERTEXT_ORDER_API_KEY. */
					__( 'The value from %s on the server is used while this field is empty.', 'supertext-polylang' ),
					self::FALLBACKS[ $field ]
				)
			)
		);
	}

	/**
	 * Returns the Order API Key.
	 *
	 * @return string
	 */
	public static function api_key(): string {
		return self::resolved( self::get(), 'human_api_key' );
	}

	/**
	 * Returns the configured Supertext ServiceID (order-type group).
	 *
	 * @return int
	 */
	public static function service_id(): int {
		$service_id = (int) ( self::get()['service_id'] ?? 0 );

		return $service_id > 0 ? $service_id : self::DEFAULT_SERVICE_ID;
	}

	/**
	 * Whether a translation may be written back more than once (re-applied on each
	 * callback). When false, the first write-back wins and later ones are skipped.
	 *
	 * @return bool
	 */
	public static function allow_multiple_writebacks(): bool {
		return (bool) self::get()['allow_multiple_writebacks'];
	}

	/**
	 * The post status applied to a translation when it is written back.
	 *
	 * @return string 'draft' or 'publish'.
	 */
	public static function writeback_status(): string {
		$status = (string) self::get()['writeback_status'];
		return in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';
	}

	/**
	 * Whether the secret-link public preview of unpublished posts/pages is enabled.
	 * Master switch for the per-post preview metabox, the front-end token access, and
	 * the screenshot feature's ability to reach draft pages.
	 *
	 * @return bool
	 */
	public static function preview_links_enabled(): bool {
		return (bool) self::get()['preview_links_enabled'];
	}

	/**
	 * Whether VibeBoost Screenshots is enabled — attach a page screenshot to each
	 * human-translation order for the translator's visual reference.
	 *
	 * @return bool
	 */
	public static function screenshots_enabled(): bool {
		return (bool) self::get()['screenshots_enabled'];
	}

	/**
	 * Returns the VibeBoost Screenshots API key (personal key, prefixed `vibe_`), or
	 * '' when none is configured. Required for the Pro capture options the plugin uses.
	 *
	 * @return string
	 */
	public static function screenshots_api_key(): string {
		return self::resolved( self::get(), 'screenshots_api_key' );
	}

	/**
	 * The "Send version information to Supertext" setting (on by default). The
	 * environment variable SUPERTEXT_VERSION_REPORTS wins over it, see
	 * Reporting\Commando::enabled().
	 *
	 * @return bool
	 */
	public static function version_reports(): bool {
		return (bool) self::get()['version_reports'];
	}

	/**
	 * Tells whether the human/order API is configured (email + key present).
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::email() && '' !== self::api_key();
	}

	/**
	 * Renders the settings form (for the Supertext admin page).
	 *
	 * @return void
	 */
	public static function render_form(): void {
		$current = self::get();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::GROUP ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="supertext-environment"><?php esc_html_e( 'Environment', 'supertext-polylang' ); ?></label></th>
					<td>
						<?php $env_override = self::environment_override(); ?>
						<select name="<?php echo esc_attr( self::OPTION . '[environment]' ); ?>" id="supertext-environment" <?php disabled( null !== $env_override ); ?>>
							<?php
							$labels = array(
								'live'    => __( 'Live (www.supertext.com)', 'supertext-polylang' ),
								'staging' => __( 'Staging (staging.supertext.com)', 'supertext-polylang' ),
								'testing' => __( 'Testing (testing.supertext.com)', 'supertext-polylang' ),
							);
							foreach ( $labels as $value => $label ) :
								?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $env_override ?? $current['environment'], $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Supertext environment used for professional (human) translation orders.', 'supertext-polylang' ); ?></p>
						<?php
						if ( null !== $env_override ) {
							self::print_server_note( '' !== self::server_value( 'SUPERTEXT_HUMAN_ENVIRONMENT' ) && isset( self::ENVIRONMENTS[ strtolower( self::server_value( 'SUPERTEXT_HUMAN_ENVIRONMENT' ) ) ] ) ? 'SUPERTEXT_HUMAN_ENVIRONMENT' : 'SUPERTEXT_ENVIRONMENT' );
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="supertext-email"><?php esc_html_e( 'Account email', 'supertext-polylang' ); ?></label></th>
					<td>
						<input
							name="<?php echo esc_attr( self::OPTION . '[human_email]' ); ?>"
							id="supertext-email"
							type="email"
							autocomplete="off"
							class="regular-text"
							value="<?php echo esc_attr( $current['human_email'] ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'The email (username) of your Supertext account.', 'supertext-polylang' ); ?></p>
						<?php self::print_fallback_note( $current, 'human_email' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="supertext-api-key"><?php esc_html_e( 'Order API Key', 'supertext-polylang' ); ?></label></th>
					<td>
						<input
							name="<?php echo esc_attr( self::OPTION . '[human_api_key]' ); ?>"
							id="supertext-api-key"
							type="password"
							autocomplete="off"
							class="regular-text"
							value="<?php echo esc_attr( $current['human_api_key'] ); ?>"
						/>
						<p class="description">
							<?php
							printf(
								/* translators: 1: link to the Supertext account settings page, 2: link to create a Supertext account (or log in). */
								esc_html__( 'Your Supertext "Order API Key" (used with the account email, via HTTP Basic auth, for order requests). Find it in your %1$s. No Supertext account yet? Create one at %2$s.', 'supertext-polylang' ),
								sprintf(
									'<a href="%s" target="_blank" rel="noopener">%s</a>',
									esc_url( self::base_url() . 'services/customer/accountsettings' ),
									esc_html__( 'Supertext account settings', 'supertext-polylang' )
								),
								sprintf(
									'<a href="%s" target="_blank" rel="noopener">%s</a>',
									esc_url( 'https://www.supertext.com/person/en/account/signin' ),
									esc_html__( 'supertext.com', 'supertext-polylang' )
								)
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link built from escaped parts.
							?>
						</p>
						<?php self::print_fallback_note( $current, 'human_api_key' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="supertext-service-id"><?php esc_html_e( 'ServiceID', 'supertext-polylang' ); ?></label></th>
					<td>
						<input
							name="<?php echo esc_attr( self::OPTION . '[service_id]' ); ?>"
							id="supertext-service-id"
							type="number"
							min="1"
							step="1"
							class="small-text"
							value="<?php echo esc_attr( (string) $current['service_id'] ); ?>"
						/>
						<p class="description">
							<?php
							printf(
								/* translators: %d is the default ServiceID. */
								esc_html__( 'The Supertext ServiceId for your translation services that are offered when ordering a human translation. Default %d. The list is cached for 12 hours.', 'supertext-polylang' ),
								(int) self::DEFAULT_SERVICE_ID
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Write-back', 'supertext-polylang' ); ?></th>
					<td>
						<label for="supertext-allow-multiple">
							<input
								type="checkbox"
								name="<?php echo esc_attr( self::OPTION . '[allow_multiple_writebacks]' ); ?>"
								id="supertext-allow-multiple"
								value="1"
								<?php checked( ! empty( $current['allow_multiple_writebacks'] ) ); ?>
							/>
							<?php esc_html_e( 'Allow multiple write-backs', 'supertext-polylang' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Re-apply the translation each time a completed-order callback arrives. When off, only the first write-back is applied (later callbacks are ignored), protecting manual edits.', 'supertext-polylang' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="supertext-writeback-status"><?php esc_html_e( 'Translation status', 'supertext-polylang' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( self::OPTION . '[writeback_status]' ); ?>" id="supertext-writeback-status">
							<option value="draft" <?php selected( $current['writeback_status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'supertext-polylang' ); ?></option>
							<option value="publish" <?php selected( $current['writeback_status'], 'publish' ); ?>><?php esc_html_e( 'Published', 'supertext-polylang' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Status applied to the translated post when a translation is written back.', 'supertext-polylang' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Secret preview links', 'supertext-polylang' ); ?></th>
					<td>
						<label for="supertext-preview-links">
							<input
								type="checkbox"
								name="<?php echo esc_attr( self::OPTION . '[preview_links_enabled]' ); ?>"
								id="supertext-preview-links"
								value="1"
								<?php checked( ! empty( $current['preview_links_enabled'] ) ); ?>
							/>
							<?php esc_html_e( 'Enable secret public preview links for unpublished posts/pages', 'supertext-polylang' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Master switch for the "Public preview link" box on the post/page editor: a private, expiring URL that shows an unpublished draft without logging in. When off, the box is hidden and any existing links stop working. Page screenshots (below) rely on this to capture draft pages.', 'supertext-polylang' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Page screenshots', 'supertext-polylang' ); ?></th>
					<td>
						<label for="supertext-screenshots">
							<input
								type="checkbox"
								name="<?php echo esc_attr( self::OPTION . '[screenshots_enabled]' ); ?>"
								id="supertext-screenshots"
								value="1"
								<?php checked( ! empty( $current['screenshots_enabled'] ) ); ?>
							/>
							<?php esc_html_e( 'Attach a page screenshot to each human-translation order (VibeBoost Screenshots)', 'supertext-polylang' ); ?>
						</label>
						<p class="description">
							<?php
							printf(
								/* translators: %s is a link to the VibeBoost Screenshots website. */
								esc_html__( 'When enabled, each page sent for human translation is captured through its secret preview link and uploaded to the order as a visual reference for the translator. Powered by %s (still in development); heavier use may require a subscription. Best-effort — an order still goes through if the screenshot cannot be produced.', 'supertext-polylang' ),
								sprintf(
									'<a href="%s" target="_blank" rel="noopener">%s</a>',
									esc_url( 'https://vibeboost.me' ),
									esc_html__( 'VibeBoost Screenshots', 'supertext-polylang' )
								)
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link built from escaped parts.
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="supertext-screenshots-api-key"><?php esc_html_e( 'Screenshot API key', 'supertext-polylang' ); ?></label></th>
					<td>
						<input
							name="<?php echo esc_attr( self::OPTION . '[screenshots_api_key]' ); ?>"
							id="supertext-screenshots-api-key"
							type="password"
							autocomplete="off"
							class="regular-text"
							value="<?php echo esc_attr( $current['screenshots_api_key'] ); ?>"
							placeholder="vibe_…"
						/>
						<p class="description">
							<?php
							printf(
								/* translators: %s is a link to the VibeBoost account/API-keys page. */
								esc_html__( 'Your personal VibeBoost API key (starts with "vibe_"). Required for page screenshots — the capture options the plugin uses need an authenticated key. Create one in your %s.', 'supertext-polylang' ),
								sprintf(
									'<a href="%s" target="_blank" rel="noopener">%s</a>',
									esc_url( 'https://vibeboost.me/account' ),
									esc_html__( 'VibeBoost account', 'supertext-polylang' )
								)
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link built from escaped parts.
							?>
						</p>
						<?php self::print_fallback_note( $current, 'screenshots_api_key' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Version information', 'supertext-polylang' ); ?></th>
					<td>
						<label for="supertext-version-reports">
							<input
								type="checkbox"
								name="<?php echo esc_attr( self::OPTION . '[version_reports]' ); ?>"
								id="supertext-version-reports"
								value="1"
								<?php checked( ! empty( $current['version_reports'] ) ); ?>
							/>
							<?php esc_html_e( 'Send version information to Supertext', 'supertext-polylang' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Sends the site URL and the versions of this plugin, WordPress and the runtime to Supertext when you save these settings and after an update, so Supertext support can see which version you run. No content, no API key and no personal data are sent.', 'supertext-polylang' ); ?>
						</p>
						<?php if ( \Supertext\Polylang\Reporting\Reporting::forced_by_environment() ) : ?>
							<p class="description">
								<strong>
									<?php
									echo esc_html(
										\Supertext\Polylang\Reporting\Commando::enabled()
											? __( 'The environment variable SUPERTEXT_VERSION_REPORTS turns version information on for this server, whatever this setting says.', 'supertext-polylang' )
											: __( 'The environment variable SUPERTEXT_VERSION_REPORTS turns version information off for this server, whatever this setting says.', 'supertext-polylang' )
									);
									?>
								</strong>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'supertext-polylang' ), 'primary st-btn-plainfont' ); ?>
		</form>
		<?php
	}
}
