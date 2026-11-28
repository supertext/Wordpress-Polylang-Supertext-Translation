<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Human_Translation;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Content\Post_Links;
use DOMDocument;
use DOMXPath;
use WP_Post;
use PLL_Export_Container;
use PLL_Export_Data_From_Posts;
use Supertext\Polylang\Admin\Settings;
use Supertext\Polylang\Machine_Translation\Client as MT_Client;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Data;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Processor;

/**
 * Writes a completed human-translation order back into the target post.
 *
 * Hooked on the callback's `supertext_polylang_order_completed` action: downloads
 * the Final file(s), parses them by `data-pll-id` (= base64 translation context),
 * re-runs the export to get the matching entries, fills in the translations, and
 * saves through Polylang's MT Processor — the same path the AI translation uses,
 * so the YOOtheme/ACF write-back integrations apply automatically.
 *
 * @since 0.7.0
 */
class Writeback {
	/**
	 * Registers the hook.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'supertext_polylang_order_completed', array( self::class, 'process' ), 10, 5 );
	}

	/**
	 * Processes a completed order callback.
	 *
	 * @param int[]  $order_ids Completed order id(s).
	 * @param int    $post_id   Source post id (from ReferenceData).
	 * @param string $lang      Target language slug (from ReferenceData).
	 * @param mixed  $body      Raw decoded callback body (the order object).
	 * @param string $type      Entity type; this writer only handles 'post'.
	 * @return void
	 */
	public static function process( $order_ids, $post_id, $lang, $body, $type = 'post' ): void {
		if ( 'post' !== $type ) {
			return;
		}
		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return;
		}

		$polylang = PLL();
		$model    = $polylang->model;
		$post     = get_post( (int) $post_id );
		$target   = $model->get_language( $lang );

		if ( ! $post instanceof WP_Post || ! $target ) {
			self::debug_log( sprintf( 'writeback aborted: post %d or language %s not found', (int) $post_id, $lang ) );
			return;
		}

		// Respect the "allow multiple write-backs" setting: if disabled and this
		// post/language was already written back, ignore subsequent callbacks.
		if ( ! Settings::allow_multiple_writebacks() && get_post_meta( (int) $post_id, '_supertext_order_completed_' . $lang, true ) ) {
			self::debug_log( sprintf( 'writeback skipped: post %d (%s) already written and multiple write-backs disabled', (int) $post_id, $lang ) );
			return;
		}

		$files = self::final_files( $body );
		if ( empty( $files ) ) {
			self::debug_log( 'writeback: no Final files in callback, nothing to do' );
			return;
		}

		// Download + parse the translated file(s) into a context => translation map.
		$client = new Client();
		$map    = array();
		foreach ( $files as $file ) {
			$html = $client->download_file( (int) $file['Id'], (string) $file['Name'] );
			if ( is_wp_error( $html ) ) {
				self::debug_log( sprintf( 'writeback: failed to download file %d: %s', (int) $file['Id'], $html->get_error_message() ) );
				continue;
			}
			$map += self::parse_html( $html );
		}

		if ( empty( $map ) ) {
			self::debug_log( 'writeback: no translatable strings parsed from the downloaded file(s)' );
			return;
		}

		$saved  = self::save_map( $post, $target, $map );
		$filled = $saved['filled'];
		foreach ( $saved['errors'] as $error ) {
			self::debug_log( 'writeback save errors: ' . $error );
		}

		// Apply the configured status to the translated post. Processor::save() always
		// saves as draft, so publish here if the setting says so.
		if ( 'publish' === Settings::writeback_status() ) {
			$tr_id = (int) $model->post->get_translation( (int) $post_id, $lang );
			if ( $tr_id ) {
				wp_update_post( array( 'ID' => $tr_id, 'post_status' => 'publish' ) );
			}
		}

		self::debug_log( sprintf( 'writeback done: post=%d lang=%s filled=%d status=%s', (int) $post_id, $lang, $filled, Settings::writeback_status() ) );

		// Update the order registry status (from the callback body if present).
		$status = is_array( $body ) && ! empty( $body['Status'] ) ? (string) $body['Status'] : 'Completed';
		foreach ( (array) $order_ids as $oid ) {
			Orders::update( (int) $oid, array( 'status' => $status, 'completed_at' => gmdate( 'c' ) ) );
		}

		update_post_meta(
			(int) $post_id,
			'_supertext_order_completed_' . $lang,
			wp_json_encode(
				array(
					'order_ids'   => array_map( 'intval', (array) $order_ids ),
					'file_ids'    => array_map( static fn( $f ) => (int) $f['Id'], $files ),
					'strings'     => $filled,
					'completed_at' => gmdate( 'c' ),
				)
			)
		);

		// Delivery is done: drop the open-order lock so this page + language can be
		// ordered again without a manual Reset on the Orders page. The
		// `_supertext_order_completed_<lang>` marker set above still guards against a
		// re-sent callback for THIS order overwriting the result.
		delete_post_meta( (int) $post_id, '_supertext_order_' . $lang );
	}

	/**
	 * Writes translations into a post's translation: rebuilds the export container
	 * for the source post (Polylang's export pipeline, as for AI translation), fills
	 * in the translations by translation context and saves through Polylang's MT
	 * processor, which creates or updates the linked translation (as a draft) and
	 * runs the YOOtheme/ACF write-back integrations. Used by human-translation
	 * orders and by translation file imports.
	 *
	 * @since 0.11.0
	 *
	 * @param WP_Post               $post   Source post.
	 * @param \PLL_Language         $target Target language.
	 * @param array<string, string> $map    Translation context => translated string.
	 * @return array{filled: int, errors: string[], images_to_check: int}
	 */
	public static function save_map( WP_Post $post, $target, array $map ): array {
		$polylang = PLL();
		$model    = $polylang->model;

		// Links to the site's own pages → their translation; the translation's own images stay.
		$source = $model->post->get_language( $post->ID );
		$slug   = is_object( $target ) && isset( $target->slug ) ? (string) $target->slug : '';
		$map    = Post_Links::localize_texts( $map, $source ? (string) $source->slug : '', $slug );
		$before = '' !== $slug ? Post_Links::snapshot( $post->ID, $slug ) : null;

		$curlang_backup    = $polylang->curlang;
		$polylang->curlang = null;

		$container = new PLL_Export_Container( Data::class );
		$export    = new PLL_Export_Data_From_Posts( $model );
		$export->send_to_export( $container, array( $post ), $target, array( 'include_translated_items' => true ) );

		$filled = 0;
		foreach ( $container as $data ) {
			if ( ! $data instanceof Data ) {
				continue;
			}
			foreach ( $data->get() as $entities ) {
				foreach ( $entities as $translations ) {
					foreach ( $translations->entries as $entry ) {
						if ( isset( $map[ $entry->context ] ) ) {
							$entry->translations = array( $map[ $entry->context ] );
							$filled++;
						}
					}
				}
			}
		}

		// Polylang's create_post_translation() uses get_default_post_to_edit(), an
		// admin-only function that isn't loaded during a REST callback. Load it so
		// saving works outside wp-admin.
		if ( ! function_exists( 'get_default_post_to_edit' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		// Save through the MT processor (creates/updates the linked translation,
		// runs the YOOtheme/ACF write-back). The client is unused by save().
		$processor = new Processor( $polylang, new MT_Client( array() ) );
		$result    = $processor->save( $container );

		$polylang->curlang = $curlang_backup;

		$errors = array();
		if ( is_wp_error( $result ) && $result->has_errors() ) {
			$errors[] = $result->get_error_message();
		}

		return array(
			'filled'          => $filled,
			'errors'          => $errors,
			'images_to_check' => array() === $errors && '' !== $slug ? Post_Links::merge( $post->ID, $slug, $before ) : 0,
		);
	}

	/**
	 * Returns the Final (translated) files from the callback body.
	 *
	 * @param mixed $body Decoded callback body.
	 * @return array[] List of { Id, Name } file descriptors.
	 */
	private static function final_files( $body ): array {
		$files = array();

		if ( ! is_array( $body ) || empty( $body['Files'] ) || ! is_array( $body['Files'] ) ) {
			return $files;
		}

		foreach ( $body['Files'] as $file ) {
			if ( ! is_array( $file ) || ! isset( $file['Id'] ) ) {
				continue;
			}
			if ( ( $file['DocumentType'] ?? '' ) !== 'Final' ) {
				continue;
			}
			$files[] = array(
				'Id'   => (int) $file['Id'],
				'Name' => (string) ( $file['Name'] ?? ( $file['Id'] . '.html' ) ),
			);
		}

		return $files;
	}

	/**
	 * Parses translated HTML into a map of translation context => translated string.
	 *
	 * @param string $html Translated HTML document.
	 * @return array<string, string>
	 */
	private static function parse_html( string $html ): array {
		$out = array();

		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//div[@data-pll-id]' );
		if ( false === $nodes ) {
			return $out;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}

			$context = base64_decode( $node->getAttribute( 'data-pll-id' ), true );
			if ( false === $context ) {
				continue;
			}

			$inner = '';
			foreach ( $node->childNodes as $child ) {
				$inner .= (string) $dom->saveHTML( $child );
			}

			$out[ $context ] = trim( $inner );
		}

		return $out;
	}

	/**
	 * Logs a diagnostic line when WP_DEBUG is on.
	 *
	 * @param string $message The message.
	 * @return void
	 */
	private static function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[supertext-polylang][writeback] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
