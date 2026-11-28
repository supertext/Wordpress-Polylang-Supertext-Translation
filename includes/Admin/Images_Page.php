<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Content\Images;
use Supertext\Polylang\Content\Post_Links;
use WP_Post;
use WP_Query;

/**
 * "Images" submenu under Supertext: the images of the translations Supertext wrote, the
 * original's beside the translation's, with their status (Content\Images) and *Mark as
 * checked* for the ones to check.
 *
 * @since 1.3.0
 */
class Images_Page {
	/**
	 * Submenu slug.
	 *
	 * @var string
	 */
	const SLUG = 'supertext-polylang-images';

	/**
	 * admin-post action: mark an image as checked.
	 *
	 * @var string
	 */
	const CHECKED_ACTION = 'supertext_polylang_image_checked';

	/** Translations per page. */
	const PER_PAGE = 20;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 12 );
		add_action( 'admin_post_' . self::CHECKED_ACTION, array( self::class, 'handle_checked' ) );
	}

	/**
	 * Registers the submenu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			Page::SLUG,
			__( 'Images', 'supertext-polylang' ),
			__( 'Images', 'supertext-polylang' ),
			'edit_posts',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Marks an image of a translation as checked: the image it has now is its own (or the
	 * original's, if it's the same).
	 *
	 * @return void
	 */
	public static function handle_checked(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$slot    = isset( $_POST['slot'] ) ? absint( $_POST['slot'] ) : 0;
		check_admin_referer( self::CHECKED_ACTION . '_' . $post_id . '_' . $slot );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this post.', 'supertext-polylang' ) );
		}
		$post    = get_post( $post_id );
		$records = $post instanceof WP_Post ? get_post_meta( $post_id, Post_Links::META, true ) : null;
		if ( $post instanceof WP_Post && is_array( $records ) ) {
			foreach ( $records as $i => $record ) {
				if ( is_array( $record ) && (int) ( $record['slot'] ?? -1 ) === $slot ) {
					$src = Images::at( (string) $post->post_content, $slot ) ?? (string) ( $record['src'] ?? '' );
					unset( $record['reason'] );
					$record['src']    = $src;
					$record['status'] = Images::identity( $src ) === Images::identity( (string) ( $record['originalSrc'] ?? '' ) ) ? Images::SAME : Images::OWN;
					$records[ $i ]    = $record;
				}
			}
			Post_Links::save_records( $post_id, array_values( $records ) );
		}
		$filter = isset( $_POST['filter'] ) ? sanitize_key( wp_unslash( $_POST['filter'] ) ) : '';
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'filter' => $filter, 'checked' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$filter = isset( $_GET['filter'] ) && 'check' === $_GET['filter'] ? 'check' : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$done   = isset( $_GET['checked'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$meta = array(
			'key'     => Post_Links::META,
			'compare' => 'EXISTS',
		);
		if ( 'check' === $filter ) {
			$meta = array(
				'key'     => Post_Links::META,
				'value'   => '"check"',
				'compare' => 'LIKE',
			);
		}
		$query = new WP_Query(
			array(
				'post_type'        => 'any',
				'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'meta_query'       => array( $meta ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an admin list.
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'posts_per_page'   => self::PER_PAGE,
				'paged'            => $paged,
				'suppress_filters' => true,
			)
		);
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<div class="wrap supertext-admin">
			<h1><?php esc_html_e( 'Images', 'supertext-polylang' ); ?></h1>
			<p><?php esc_html_e( 'The images of the translations Supertext wrote. Translating again keeps a translation’s own image (such as a localized screenshot) as long as the original’s image in that place doesn’t change. If the original’s image was replaced, the translation gets the new one and it is marked “To check”: replace it in the translation if it needs a localized version, then click “Mark as checked”.', 'supertext-polylang' ); ?></p>
			<?php if ( $done ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Marked as checked.', 'supertext-polylang' ); ?></p></div>
			<?php endif; ?>
			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( $base ); ?>" <?php echo '' === $filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'All', 'supertext-polylang' ); ?></a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'filter', 'check', $base ) ); ?>" <?php echo 'check' === $filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'To check', 'supertext-polylang' ); ?></a></li>
			</ul>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Translation', 'supertext-polylang' ); ?></th>
						<th scope="col" style="width:150px"><?php esc_html_e( 'Original’s image', 'supertext-polylang' ); ?></th>
						<th scope="col" style="width:150px"><?php esc_html_e( 'Translation’s image', 'supertext-polylang' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'supertext-polylang' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$rows = 0;
				foreach ( $query->posts as $post ) {
					if ( ! $post instanceof WP_Post ) {
						continue;
					}
					$records = get_post_meta( $post->ID, Post_Links::META, true );
					foreach ( is_array( $records ) ? $records : array() as $record ) {
						if ( ! is_array( $record ) || ( 'check' === $filter && Images::CHECK !== ( $record['status'] ?? '' ) ) ) {
							continue;
						}
						++$rows;
						self::row( $post, $record, $filter );
					}
				}
				if ( 0 === $rows ) :
					?>
					<tr><td colspan="4"><?php echo 'check' === $filter ? esc_html__( 'No images to check.', 'supertext-polylang' ) : esc_html__( 'No translated images yet.', 'supertext-polylang' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			<?php
			if ( $query->max_num_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					(string) paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%', '' === $filter ? $base : add_query_arg( 'filter', $filter, $base ) ),
							'format'  => '',
							'current' => $paged,
							'total'   => (int) $query->max_num_pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * One image row.
	 *
	 * @param WP_Post              $post   The translation.
	 * @param array<string, mixed> $record Its image record.
	 * @param string               $filter The list's filter.
	 * @return void
	 */
	private static function row( WP_Post $post, array $record, string $filter ): void {
		$status  = (string) ( $record['status'] ?? Images::SAME );
		$labels  = array(
			Images::SAME  => __( 'Same as the original', 'supertext-polylang' ),
			Images::OWN   => __( 'Own image', 'supertext-polylang' ),
			Images::CHECK => __( 'To check', 'supertext-polylang' ),
		);
		$reasons = array(
			Images::ORIGINAL_CHANGED => __( 'The original’s image was replaced; the translation had its own and now has the new one.', 'supertext-polylang' ),
			Images::NO_RECORD        => __( 'Translated before Supertext tracked images; the translation’s own image was kept.', 'supertext-polylang' ),
			Images::LAYOUT_CHANGED   => __( 'Images were added or removed in the translation only; it now has the original’s images.', 'supertext-polylang' ),
		);
		$language = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post->ID, 'name' ) : '';
		$edit     = get_edit_post_link( $post->ID, 'url' );
		$slot     = (int) ( $record['slot'] ?? 0 );
		?>
		<tr>
			<td>
				<strong><a href="<?php echo esc_url( (string) $edit ); ?>"><?php echo esc_html( '' !== $post->post_title ? $post->post_title : __( '(no title)', 'supertext-polylang' ) ); ?></a></strong>
				<?php if ( '' !== $language ) : ?>
					<br /><span class="description"><?php echo esc_html( $language ); ?></span>
				<?php endif; ?>
				<br /><span class="description">
					<?php
					/* translators: %d: number of the image in the content. */
					echo esc_html( sprintf( __( 'Image %d', 'supertext-polylang' ), $slot + 1 ) );
					?>
				</span>
			</td>
			<td><?php self::thumb( (string) ( $record['originalSrc'] ?? '' ) ); ?></td>
			<td><?php self::thumb( (string) ( $record['src'] ?? '' ) ); ?></td>
			<td>
				<strong><?php echo esc_html( $labels[ $status ] ?? $status ); ?></strong>
				<?php if ( isset( $record['reason'], $reasons[ $record['reason'] ] ) ) : ?>
					<br /><span class="description"><?php echo esc_html( $reasons[ $record['reason'] ] ); ?></span>
				<?php endif; ?>
				<?php if ( Images::CHECK === $status && current_user_can( 'edit_post', $post->ID ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px">
						<?php wp_nonce_field( self::CHECKED_ACTION . '_' . $post->ID . '_' . $slot ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::CHECKED_ACTION ); ?>" />
						<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>" />
						<input type="hidden" name="slot" value="<?php echo esc_attr( (string) $slot ); ?>" />
						<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>" />
						<button type="submit" class="button"><?php esc_html_e( 'Mark as checked', 'supertext-polylang' ); ?></button>
					</form>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * A small preview of an image.
	 *
	 * @param string $src Image address.
	 * @return void
	 */
	private static function thumb( string $src ): void {
		if ( '' === $src ) {
			echo '&mdash;';
			return;
		}
		printf(
			'<a href="%1$s" target="_blank" rel="noopener"><img src="%1$s" alt="" style="max-width:140px;max-height:90px;border:1px solid #dcdcde" /></a>',
			esc_url( $src )
		);
	}
}
