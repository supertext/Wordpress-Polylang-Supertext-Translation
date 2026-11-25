<?php
/**
 * Demo setup, second step, run with `wp eval-file` after setup.php on every start (idempotent):
 * with Polylang Pro, turns on Polylang's machine translation with Supertext and the AI key from
 * SUPERTEXT_API_KEY, while the Supertext service has no key yet (a key entered on the settings
 * page stays). A step of its own because Polylang Pro knows Supertext as a service only in a
 * request that starts with Polylang patched, which setup.php does on a new site.
 *
 * @package Supertext_Polylang
 */

defined( 'ABSPATH' ) || exit;

$supertext_demo_key = (string) getenv( 'SUPERTEXT_API_KEY' );
if ( '' === $supertext_demo_key || ! function_exists( 'PLL' ) || ! defined( 'POLYLANG_PRO' ) || ! class_exists( '\Supertext\Polylang\Machine_Translation\Service' ) ) {
	return;
}

$supertext_demo_options = PLL()->options;
if ( ! is_object( $supertext_demo_options ) || ! method_exists( $supertext_demo_options, 'set' ) ) {
	return;
}
$supertext_demo_services = (array) $supertext_demo_options->get( 'machine_translation_services' );
$supertext_demo_slug     = \Supertext\Polylang\Machine_Translation\Service::get_slug();
$supertext_demo_service  = (array) ( $supertext_demo_services[ $supertext_demo_slug ] ?? array() );
if ( '' !== (string) ( $supertext_demo_service['api_key'] ?? '' ) && $supertext_demo_options->get( 'machine_translation_enabled' ) ) {
	return;
}

$supertext_demo_service['api_key']                     = (string) ( $supertext_demo_service['api_key'] ?? '' ) ?: $supertext_demo_key;
$supertext_demo_services[ $supertext_demo_slug ]       = $supertext_demo_service;
$supertext_demo_errors                                 = array(
	$supertext_demo_options->set( 'machine_translation_services', $supertext_demo_services ),
	$supertext_demo_options->set( 'machine_translation_enabled', true ),
);
foreach ( $supertext_demo_errors as $supertext_demo_error ) {
	if ( is_wp_error( $supertext_demo_error ) && $supertext_demo_error->has_errors() ) {
		WP_CLI::warning( '[demo] Machine translation: ' . $supertext_demo_error->get_error_message() );
	}
}
$supertext_demo_options->save();
WP_CLI::log( '[demo] Turned on machine translation with Supertext (key from SUPERTEXT_API_KEY)' );
