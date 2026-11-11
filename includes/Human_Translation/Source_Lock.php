<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Human_Translation;

defined( 'ABSPATH' ) || exit;

use WP_Post;

/**
 * Protects a source post from deletion while it has an in-progress human
 * translation order.
 *
 * Deleting (or trashing) the source before the order's callback writes the
 * translation back would orphan the order and break the write-back, so we block
 * deletion for as long as an order is open. Editing the source stays fully
 * allowed — only `delete_post` is denied, never `edit_post`.
 *
 * "Open" matches the Orders page: recorded, not yet completed, not cancelled.
 *
 * @since 0.9.0
 */
class Source_Lock {
	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'map_meta_cap', array( self::class, 'block_delete_cap' ), 10, 4 );
		add_filter( 'pre_trash_post', array( self::class, 'block_trash' ), 10, 2 );
		add_filter( 'pre_delete_post', array( self::class, 'block_delete' ), 10, 3 );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
	}

	/**
	 * Denies the `delete_post` capability for a protected source post, which hides
	 * the Trash/Delete actions everywhere (row actions, editor, bulk, REST) while
	 * leaving editing untouched.
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     The meta capability being checked.
	 * @param int      $user_id The user id (unused).
	 * @param array    $args    Arguments; $args[0] is the post id for delete_post.
	 * @return string[]
	 */
	public static function block_delete_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'delete_post' !== $cap || empty( $args[0] ) ) {
			return $caps;
		}

		if ( self::has_open_order( (int) $args[0] ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Backstop: prevents trashing a protected source even via code paths that don't
	 * go through capability checks.
	 *
	 * @param bool|null $trash Short-circuit value (null = proceed).
	 * @param WP_Post   $post  The post about to be trashed.
	 * @return bool|null `false` to block, otherwise the unchanged value.
	 */
	public static function block_trash( $trash, $post ) {
		if ( $post instanceof WP_Post && self::has_open_order( (int) $post->ID ) ) {
			return false;
		}
		return $trash;
	}

	/**
	 * Backstop: prevents (force-)deleting a protected source.
	 *
	 * @param WP_Post|false|null $delete       Short-circuit value (null = proceed).
	 * @param WP_Post            $post         The post about to be deleted.
	 * @param bool               $force_delete Whether this bypasses the trash.
	 * @return WP_Post|false|null `false` to block, otherwise the unchanged value.
	 */
	public static function block_delete( $delete, $post, $force_delete ) {
		if ( $post instanceof WP_Post && self::has_open_order( (int) $post->ID ) ) {
			return false;
		}
		return $delete;
	}

	/**
	 * Shows an info notice on the edit screen explaining why deletion is disabled.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $post_id <= 0 || ! self::has_open_order( $post_id ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html__( 'This page has a Supertext human translation order in progress, so it can\'t be deleted until the translation is delivered. You can still edit it.', 'supertext-polylang' )
		);
	}

	/**
	 * Tells whether the given post is the source of an open human translation order.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function has_open_order( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		foreach ( Orders::all() as $order ) {
			if ( (int) ( $order['post_id'] ?? 0 ) !== $post_id ) {
				continue;
			}
			$completed = ! empty( $order['completed_at'] );
			$cancelled = 'Cancelled' === ( $order['status'] ?? '' );
			if ( ! $completed && ! $cancelled ) {
				return true;
			}
		}

		return false;
	}
}
