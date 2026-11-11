<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * "Tools" submenu under Supertext.
 *
 * First tool: a list of every ACF field with its Polylang translation setting
 * (Translate / Translate Once / Copy Once / Synchronize / Ignore), so you can see
 * at a glance which fields are translated. Read-only for now.
 *
 * @since 0.3.0
 */
class Tools_Page {
	/**
	 * Submenu slug.
	 *
	 * @var string
	 */
	const SLUG = 'supertext-polylang-tools';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 11 );
	}

	/**
	 * Registers the submenu (after Orders).
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			Page::SLUG,
			__( 'Tools', 'supertext-polylang' ),
			__( 'Tools', 'supertext-polylang' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renders the Tools page (currently the ACF fields overview).
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap supertext-admin">
			<?php
			Page::hero(
				__( 'Supertext Tools', 'supertext-polylang' ),
				__( 'Field-translation overview & utilities.', 'supertext-polylang' )
			);
			?>
			<div class="st-card">
				<div class="st-card__head">
					<span class="st-tile"><span class="dashicons dashicons-feedback"></span></span>
					<div>
						<h2 class="st-card__title"><?php esc_html_e( 'ACF fields', 'supertext-polylang' ); ?></h2>
						<p class="st-card__subtitle"><?php esc_html_e( 'Every ACF field and whether Polylang translates it.', 'supertext-polylang' ); ?></p>
					</div>
				</div>
				<?php self::render_acf_fields(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Lists every ACF field and its Polylang translation setting.
	 *
	 * @return void
	 */
	private static function render_acf_fields(): void {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'Advanced Custom Fields is not active, so there are no ACF fields to show.', 'supertext-polylang' )
			);
			return;
		}

		$groups = acf_get_field_groups();
		if ( empty( $groups ) ) {
			printf( '<p>%s</p>', esc_html__( 'No ACF field groups found.', 'supertext-polylang' ) );
			return;
		}
		?>
		<p class="description" style="max-width:720px;">
			<?php esc_html_e( 'Translation setting per ACF field, as configured for Polylang. “Translate” / “Translate once” fields are sent for translation; “Copy”, “Synchronize” and “Ignore” fields are not. Set this in the ACF field group editor (each field\'s “Translation” option).', 'supertext-polylang' ); ?>
		</p>
		<table class="widefat striped" style="max-width:960px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Field group', 'supertext-polylang' ); ?></th>
					<th><?php esc_html_e( 'Field', 'supertext-polylang' ); ?></th>
					<th><?php esc_html_e( 'Name', 'supertext-polylang' ); ?></th>
					<th><?php esc_html_e( 'Type', 'supertext-polylang' ); ?></th>
					<th><?php esc_html_e( 'Translatable', 'supertext-polylang' ); ?></th>
					<th><?php esc_html_e( 'Setting', 'supertext-polylang' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $groups as $group ) {
					$fields = acf_get_fields( $group['key'] );
					if ( empty( $fields ) ) {
						continue;
					}
					self::render_field_rows( $fields, (string) $group['title'] );
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders table rows for a set of ACF fields, recursing into sub fields.
	 *
	 * @param array  $fields      ACF field definitions.
	 * @param string $group_title Owning field group title.
	 * @param int    $depth       Nesting depth (for indenting sub fields).
	 * @return void
	 */
	private static function render_field_rows( array $fields, string $group_title, int $depth = 0 ): void {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$setting     = self::translation_setting( $field );
			$translatable = self::is_translatable( $setting['value'] );
			$indent      = str_repeat( '— ', max( 0, $depth ) );
			?>
			<tr>
				<td><?php echo esc_html( 0 === $depth ? $group_title : '' ); ?></td>
				<td><?php echo esc_html( $indent . (string) ( $field['label'] ?? '' ) ); ?></td>
				<td><code><?php echo esc_html( (string) ( $field['name'] ?? '' ) ); ?></code></td>
				<td><?php echo esc_html( (string) ( $field['type'] ?? '' ) ); ?></td>
				<td>
					<?php if ( null === $setting['value'] ) : ?>
						<span style="color:#787c82;">—</span>
					<?php elseif ( $translatable ) : ?>
						<span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Yes', 'supertext-polylang' ); ?></span>
					<?php else : ?>
						<span style="color:#d63638;">&#10007; <?php esc_html_e( 'No', 'supertext-polylang' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php echo esc_html( null === $setting['value'] ? '' : (string) $setting['value'] ); ?>
					<?php if ( '' !== $setting['key'] ) : ?>
						<code style="color:#787c82;font-size:11px;">(<?php echo esc_html( $setting['key'] ); ?>)</code>
					<?php endif; ?>
				</td>
			</tr>
			<?php
			// Recurse into container fields (repeater / group / flexible content layouts).
			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				self::render_field_rows( $field['sub_fields'], $group_title, $depth + 1 );
			}
			if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $layout ) {
					if ( ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						self::render_field_rows( $layout['sub_fields'], $group_title, $depth + 1 );
					}
				}
			}
		}
	}

	/**
	 * Finds the Polylang translation setting stored on an ACF field.
	 *
	 * The exact key varies by Polylang version, so we probe the known candidates and
	 * then fall back to any field key that looks Polylang-related. Returns the key it
	 * was read from (for confirmation) and the raw value (null if none found).
	 *
	 * @param array $field ACF field definition.
	 * @return array{key: string, value: string|null}
	 */
	private static function translation_setting( array $field ): array {
		$candidates = array( 'translations', 'pll_translations', 'polylang_translations', 'pll_copy' );
		foreach ( $candidates as $key ) {
			if ( isset( $field[ $key ] ) && is_scalar( $field[ $key ] ) ) {
				return array( 'key' => $key, 'value' => (string) $field[ $key ] );
			}
		}

		foreach ( $field as $key => $value ) {
			if ( is_scalar( $value ) && preg_match( '/pll|polylang/i', (string) $key ) ) {
				return array( 'key' => (string) $key, 'value' => (string) $value );
			}
		}

		return array( 'key' => '', 'value' => null );
	}

	/**
	 * Interprets a Polylang ACF setting value as translatable or not.
	 *
	 * @param string|null $value Raw setting value.
	 * @return bool
	 */
	private static function is_translatable( $value ): bool {
		return is_string( $value ) && false !== stripos( $value, 'translate' );
	}
}
