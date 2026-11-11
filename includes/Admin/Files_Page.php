<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Files\Imported_Job;
use Supertext\Polylang\Files\Polylang_File_Store;
use Supertext\Polylang\Files\Translation_File_Glue;
use Supertext\Polylang\Files\Translation_File_Glue_Error;
use Supertext\Polylang\Sdk\Files\TranslationFileError;
use WP_Post;

/**
 * "Translation files" submenu under Supertext: *Export for translation* (XLIFF 1.2
 * or HTML, opened from the "Export for translation" row action in the posts and
 * pages lists, next to the Supertext bulk actions) and *Import translation* (file
 * upload, then a summary per post and language). See Translation_File_Glue.
 *
 * Nothing is stored: the download is generated on the fly and the upload is parsed
 * in memory; only the summary is kept for the redirect (a 60-second transient).
 *
 * @since 0.11.0
 */
class Files_Page {
	/**
	 * Submenu slug.
	 *
	 * @var string
	 */
	const SLUG = 'supertext-polylang-files';

	/** admin-post.php actions. */
	const EXPORT_ACTION = 'supertext_polylang_export_file';
	const IMPORT_ACTION = 'supertext_polylang_import_file';

	/** Transient prefix (+ user id) for the summary or error shown after the redirect. */
	const RESULT_TRANSIENT = 'supertext_polylang_files_';

	/**
	 * Registers hooks (only with Polylang Pro, whose export pipeline the files use).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 11 );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( self::class, 'handle_export' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( self::class, 'handle_import' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( self::class, 'row_action' ), 10, 2 );
	}

	/**
	 * Whether Polylang Pro's export pipeline and the Supertext service can be loaded.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return function_exists( 'PLL' ) && class_exists( 'PLL_Export_Container' )
			&& interface_exists( 'WP_Syntex\\Polylang_Pro\\Modules\\Machine_Translation\\Services\\Service_Interface' );
	}

	/**
	 * Registers the submenu (for everyone who can edit posts, like the bulk actions).
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		if ( ! self::available() ) {
			return;
		}
		add_submenu_page(
			Page::SLUG,
			__( 'Translation files', 'supertext-polylang' ),
			__( 'Translation files', 'supertext-polylang' ),
			'edit_posts',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Adds "Export for translation" to a post's row actions.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post    The post.
	 * @return array<string, string>
	 */
	public static function row_action( $actions, $post ) {
		if ( 'trash' === $post->post_status || ! self::available() || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		if ( ! PLL()->model->is_translated_post_type( $post->post_type ) || ! PLL()->model->post->get_language( $post->ID ) ) {
			return $actions;
		}
		$actions['supertext_export'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&post=' . $post->ID ) ),
			esc_html__( 'Export for translation', 'supertext-polylang' )
		);
		return $actions;
	}

	/**
	 * Renders the page: the export form (for ?post=ID), the import form and the last result.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) || ! self::available() ) {
			return;
		}
		$result  = get_transient( self::RESULT_TRANSIENT . get_current_user_id() );
		delete_transient( self::RESULT_TRANSIENT . get_current_user_id() );
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap supertext-admin">
			<?php
			Page::hero(
				__( 'Translation files', 'supertext-polylang' ),
				__( 'Export posts as XLIFF or HTML files for translation tools, translators or Supertext\'s web app, and import the translated files.', 'supertext-polylang' )
			);
			if ( is_array( $result ) ) {
				self::render_result( $result );
			}
			if ( $post_id > 0 ) {
				self::render_export( $post_id );
			}
			self::render_import();
			?>
		</div>
		<?php
	}

	/**
	 * The export form for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function render_export( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$source    = PLL()->model->post->get_language( $post_id );
		$languages = array();
		foreach ( PLL()->model->get_languages_list() as $language ) {
			if ( ! $source || $language->slug !== $source->slug ) {
				$languages[ $language->slug ] = $language->name;
			}
		}
		?>
		<div class="st-card">
			<div class="st-card__head">
				<span class="st-tile"><span class="dashicons dashicons-download"></span></span>
				<div>
					<h2 class="st-card__title"><?php esc_html_e( 'Export for translation', 'supertext-polylang' ); ?></h2>
					<p class="st-card__subtitle"><?php echo esc_html( get_the_title( $post ) . ( $source ? ' (' . $source->name . ')' : '' ) ); ?></p>
				</div>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::EXPORT_ACTION ); ?>">
				<input type="hidden" name="post" value="<?php echo esc_attr( (string) $post_id ); ?>">
				<?php wp_nonce_field( self::EXPORT_ACTION . '_' . $post_id ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'File format', 'supertext-polylang' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="format" value="xliff" checked> <?php esc_html_e( 'XLIFF 1.2 (for translation tools)', 'supertext-polylang' ); ?></label><br>
								<label><input type="radio" name="format" value="html"> <?php esc_html_e( 'HTML (one language per file)', 'supertext-polylang' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Target languages', 'supertext-polylang' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $languages as $slug => $name ) : ?>
									<label><input type="checkbox" name="languages[]" value="<?php echo esc_attr( $slug ); ?>"> <?php echo esc_html( $name ); ?></label><br>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Download file', 'supertext-polylang' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * The import form.
	 *
	 * @return void
	 */
	private static function render_import(): void {
		?>
		<div class="st-card">
			<div class="st-card__head">
				<span class="st-tile"><span class="dashicons dashicons-upload"></span></span>
				<div>
					<h2 class="st-card__title"><?php esc_html_e( 'Import translation', 'supertext-polylang' ); ?></h2>
					<p class="st-card__subtitle"><?php esc_html_e( 'To export a post, use “Export for translation” in the posts or pages list.', 'supertext-polylang' ); ?></p>
				</div>
			</div>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>">
				<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr( (string) Translation_File_Glue::MAX_UPLOAD_BYTES ); ?>">
				<?php wp_nonce_field( self::IMPORT_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="supertext_file"><?php esc_html_e( 'Translated file (XLIFF or HTML)', 'supertext-polylang' ); ?></label></th>
						<td><input type="file" id="supertext_file" name="supertext_file" accept=".xlf,.xliff,.xml,.html,.htm" required></td>
					</tr>
					<tr>
						<th scope="row"></th>
						<td><label><input type="checkbox" name="include_changed" value="1"> <?php esc_html_e( 'Also import texts whose source changed since the export', 'supertext-polylang' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( __( 'Import translation', 'supertext-polylang' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Shows the error or the import summary kept for the redirect.
	 *
	 * @param array<string, mixed> $result The stored result.
	 * @return void
	 */
	private static function render_result( array $result ): void {
		if ( isset( $result['error'] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( (string) $result['error'] ) );
			return;
		}
		foreach ( (array) ( $result['jobs'] ?? array() ) as $job ) {
			$lines = (array) $job['lines'];
			$class = $job['ok'] ? 'notice-success' : 'notice-warning';
			echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>' . esc_html( (string) $job['heading'] ) . '</strong></p><ul>';
			foreach ( $lines as $line ) {
				echo '<li>' . esc_html( (string) $line ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	/**
	 * Sends the exported file (admin-post.php).
	 *
	 * @return void
	 */
	public static function handle_export(): void {
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		check_admin_referer( self::EXPORT_ACTION . '_' . $post_id );
		if ( ! self::available() || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this post.' ), 403 ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress's own message.
		}
		$format    = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : Translation_File_Glue::FORMAT_XLIFF;
		$languages = isset( $_POST['languages'] ) && is_array( $_POST['languages'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['languages'] ) ) : array();
		$back      = admin_url( 'admin.php?page=' . self::SLUG . '&post=' . $post_id );
		try {
			$file = ( new Translation_File_Glue( new Polylang_File_Store() ) )->export( $post_id, $languages, $format );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect_with( array( 'error' => self::argument_message( $e->getMessage() ) ), $back );
			return;
		} catch ( Translation_File_Glue_Error | TranslationFileError $e ) {
			self::redirect_with( array( 'error' => self::error_message( $e ) ), $back );
			return;
		}
		nocache_headers();
		header( 'Content-Type: ' . $file->content_type );
		header( 'Content-Disposition: attachment; filename="' . $file->file_name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $file->content ) );
		echo $file->content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a file download.
		exit;
	}

	/**
	 * Imports the uploaded file (admin-post.php) and redirects to the summary.
	 *
	 * @return void
	 */
	public static function handle_import(): void {
		check_admin_referer( self::IMPORT_ACTION );
		if ( ! self::available() || ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this post.' ), 403 ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress's own message.
		}
		$back   = admin_url( 'admin.php?page=' . self::SLUG );
		$upload = isset( $_FILES['supertext_file'] ) && is_array( $_FILES['supertext_file'] ) ? $_FILES['supertext_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked below, parsed in memory.
		$error  = (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE );
		$size   = (int) ( $upload['size'] ?? 0 );
		$tmp    = (string) ( $upload['tmp_name'] ?? '' );
		if ( in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || $size > Translation_File_Glue::MAX_UPLOAD_BYTES ) {
			self::redirect_with( array( 'error' => self::error_message( new Translation_File_Glue_Error( Translation_File_Glue_Error::TOO_LARGE ) ) ), $back );
			return;
		}
		if ( UPLOAD_ERR_OK !== $error || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			self::redirect_with( array( 'error' => self::error_message( new TranslationFileError( TranslationFileError::INVALID_FILE, 'no upload' ) ) ), $back );
			return;
		}
		try {
			$jobs = ( new Translation_File_Glue( new Polylang_File_Store() ) )->import_file( $tmp, ! empty( $_POST['include_changed'] ) );
		} catch ( Translation_File_Glue_Error | TranslationFileError $e ) {
			self::redirect_with( array( 'error' => self::error_message( $e ) ), $back );
			return;
		}
		$summary = array();
		foreach ( $jobs as $job ) {
			$summary[] = array(
				'heading' => self::heading( $job ),
				'lines'   => self::summary_lines( $job ),
				'ok'      => null === $job->error && array() === $job->messages,
			);
		}
		self::redirect_with( array( 'jobs' => $summary ), $back );
	}

	/**
	 * The heading of a job's summary: post title and target language.
	 *
	 * @param Imported_Job $job The job.
	 * @return string
	 */
	public static function heading( Imported_Job $job ): string {
		if ( null === $job->post_id ) {
			return $job->reference . ' (' . $job->target_language . ')';
		}
		return sprintf( '%s → %s', '' !== $job->title ? $job->title : '#' . $job->post_id, '' !== $job->language_name ? $job->language_name : $job->target_language );
	}

	/**
	 * The lines of a job's summary: its error, or the counts.
	 *
	 * @param Imported_Job $job The job.
	 * @return string[]
	 */
	public static function summary_lines( Imported_Job $job ): array {
		if ( null !== $job->error ) {
			return array( self::error_message( $job->error ) );
		}
		/* translators: %d: number of texts. */
		$lines  = array( sprintf( __( 'Imported: %d', 'supertext-polylang' ), $job->imported ) );
		$counts = array(
			/* translators: %d: number of texts. */
			array( __( 'Skipped, source changed since the export: %d', 'supertext-polylang' ), $job->source_changed ),
			/* translators: %d: number of texts. */
			array( __( 'Not translated in the file: %d', 'supertext-polylang' ), $job->untranslated ),
			/* translators: %d: number of texts. */
			array( __( 'Formatting changed, not imported: %d', 'supertext-polylang' ), $job->invalid ),
			/* translators: %d: number of texts. */
			array( __( 'Not in the file: %d', 'supertext-polylang' ), $job->missing ),
		);
		foreach ( $counts as $count ) {
			if ( $count[1] > 0 ) {
				$lines[] = sprintf( $count[0], $count[1] );
			}
		}
		return array_merge( $lines, $job->messages );
	}

	/**
	 * The message of a translation file error.
	 *
	 * @param \Throwable $e The SDK's TranslationFileError or the plugin's Translation_File_Glue_Error.
	 * @return string
	 */
	public static function error_message( \Throwable $e ): string {
		$code = $e instanceof TranslationFileError ? $e->errorCode : ( $e instanceof Translation_File_Glue_Error ? $e->error_code : '' );
		switch ( $code ) {
			case TranslationFileError::INVALID_FILE:
				return __( 'This file isn\'t a valid XLIFF or HTML translation file.', 'supertext-polylang' );
			case TranslationFileError::MISSING_METADATA:
				return __( 'This file wasn\'t exported by the Supertext plugin, or its header was removed.', 'supertext-polylang' );
			case TranslationFileError::TOO_LARGE:
			case Translation_File_Glue_Error::TOO_LARGE:
				return __( 'The file is too large (at most 10 MB).', 'supertext-polylang' );
			case TranslationFileError::INVALID_JOB:
			case Translation_File_Glue_Error::NOTHING_TO_TRANSLATE:
				return __( 'Nothing to translate.', 'supertext-polylang' );
			case Translation_File_Glue_Error::OTHER_SYSTEM:
				/* translators: %s: name of the other Supertext plugin. */
				return sprintf( __( 'This file belongs to another system (%s).', 'supertext-polylang' ), $e instanceof Translation_File_Glue_Error ? $e->argument : '' );
			case Translation_File_Glue_Error::NOT_FOUND:
				return __( 'The post this file was exported from no longer exists.', 'supertext-polylang' );
			case Translation_File_Glue_Error::NO_LANGUAGE:
				/* translators: %s: language code. */
				return sprintf( __( 'The site has no language %s.', 'supertext-polylang' ), $e instanceof Translation_File_Glue_Error ? $e->argument : '' );
			case Translation_File_Glue_Error::NOT_ALLOWED:
				return __( 'Sorry, you are not allowed to edit this post.' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress's own message.
		}
		return $e->getMessage();
	}

	/**
	 * The message for an invalid export request.
	 *
	 * @param string $reason format, no_language or html_one_language.
	 * @return string
	 */
	private static function argument_message( string $reason ): string {
		if ( 'html_one_language' === $reason ) {
			return __( 'An HTML file holds exactly one target language.', 'supertext-polylang' );
		}
		return __( 'Choose at least one target language.', 'supertext-polylang' );
	}

	/**
	 * Keeps the result for the next page view and redirects there.
	 *
	 * @param array<string, mixed> $result Error or summary.
	 * @param string               $url    Where to go.
	 * @return void
	 */
	private static function redirect_with( array $result, string $url ): void {
		set_transient( self::RESULT_TRANSIENT . get_current_user_id(), $result, 60 );
		wp_safe_redirect( $url );
		exit;
	}
}
