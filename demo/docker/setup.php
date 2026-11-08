<?php
/**
 * Demo setup, run with `wp eval-file` on every container start (idempotent):
 * Polylang languages, the demo accounts, the Supertext settings from the variables,
 * and a few English pages and posts to translate.
 *
 * @package Supertext\Polylang
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints a progress line.
 *
 * @param string $msg Message.
 */
function supertext_demo_log( string $msg ): void {
	WP_CLI::log( '[demo] ' . $msg );
}

// ---------------------------------------------------------------------------
// Languages: English (default), German, French, Italian.
// ---------------------------------------------------------------------------
$languages = array(
	array( 'name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'rtl' => false, 'term_group' => 0, 'flag' => 'gb' ),
	array( 'name' => 'Deutsch', 'slug' => 'de', 'locale' => 'de_DE', 'rtl' => false, 'term_group' => 1, 'flag' => 'de' ),
	array( 'name' => 'Français', 'slug' => 'fr', 'locale' => 'fr_FR', 'rtl' => false, 'term_group' => 2, 'flag' => 'fr' ),
	array( 'name' => 'Italiano', 'slug' => 'it', 'locale' => 'it_IT', 'rtl' => false, 'term_group' => 3, 'flag' => 'it' ),
);

if ( function_exists( 'PLL' ) && PLL() && isset( PLL()->model ) ) {
	$model = PLL()->model;
	// Polylang 3.7+ has $model->languages->add(); older versions $model->add_language().
	$languages_api = isset( $model->languages ) && is_object( $model->languages ) && method_exists( $model->languages, 'add' )
		? $model->languages
		: null;

	foreach ( $languages as $lang ) {
		if ( $model->get_language( $lang['slug'] ) ) {
			continue;
		}
		$result = $languages_api ? $languages_api->add( $lang ) : $model->add_language( $lang );
		if ( is_wp_error( $result ) ) {
			supertext_demo_log( 'Could not add language ' . $lang['slug'] . ': ' . $result->get_error_message() );
		} else {
			supertext_demo_log( 'Added language ' . $lang['slug'] );
		}
	}
	if ( method_exists( $model, 'clean_languages_cache' ) ) {
		$model->clean_languages_cache();
	}

	// Default language English; content without a language gets it.
	$options = get_option( 'polylang', array() );
	if ( is_array( $options ) && ( $options['default_lang'] ?? '' ) !== 'en' ) {
		$options['default_lang'] = 'en';
		update_option( 'polylang', $options );
	}
	if ( method_exists( $model, 'set_language_in_mass' ) ) {
		$model->set_language_in_mass( 'en' );
	}
	// Skip Polylang's setup wizard.
	delete_transient( 'pll_activation_redirect' );
	update_option( 'pll_wizard_done', 1 );
} else {
	supertext_demo_log( 'Polylang is not loaded, skipping languages' );
}

// ---------------------------------------------------------------------------
// Accounts from the variables (passwords are reset on every start, so changing a
// variable and redeploying is all it takes).
// ---------------------------------------------------------------------------
/**
 * Creates or updates a demo user.
 *
 * @param string $login    Login name.
 * @param string $email    E-mail.
 * @param string $password Password.
 * @param string $role     Role.
 */
function supertext_demo_user( string $login, string $email, string $password, string $role ): void {
	if ( '' === $email || '' === $password ) {
		return;
	}
	$user = get_user_by( 'email', $email ) ?: get_user_by( 'login', $login );
	if ( $user ) {
		wp_set_password( $password, $user->ID );
		wp_update_user( array( 'ID' => $user->ID, 'user_email' => $email, 'role' => $role ) );
		supertext_demo_log( "Updated $role account $email" );
		return;
	}
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $email,
			'user_pass'  => $password,
			'role'       => $role,
		)
	);
	supertext_demo_log( is_wp_error( $id ) ? "Could not create $email: " . $id->get_error_message() : "Created $role account $email" );
}

supertext_demo_user( 'admin', (string) getenv( 'DEMO_ADMIN_EMAIL' ), (string) getenv( 'DEMO_ADMIN_PASSWORD' ), 'administrator' );
supertext_demo_user( 'editor', (string) getenv( 'DEMO_EDITOR_EMAIL' ), (string) getenv( 'DEMO_EDITOR_PASSWORD' ), 'editor' );

// ---------------------------------------------------------------------------
// Supertext settings: seeded from the variables only while still empty, so changes
// made on the settings page stay.
// ---------------------------------------------------------------------------
$settings = get_option( 'supertext_polylang_settings', array() );
$settings = is_array( $settings ) ? $settings : array();
$changed  = false;
foreach (
	array(
		'human_api_key' => 'SUPERTEXT_API_KEY',
		'human_email'   => 'SUPERTEXT_ACCOUNT_EMAIL',
		'environment'   => 'SUPERTEXT_ENVIRONMENT',
	) as $key => $env
) {
	$value = (string) getenv( $env );
	if ( '' !== $value && empty( $settings[ $key ] ) ) {
		$settings[ $key ] = $value;
		$changed          = true;
	}
}
if ( $changed ) {
	update_option( 'supertext_polylang_settings', $settings );
	supertext_demo_log( 'Seeded the Supertext settings from the variables' );
}

// With Polylang Pro: expose the pll_mt_services filter (the plugin's own patch).
if ( class_exists( '\Supertext\Polylang\Admin\Patch' ) && \Supertext\Polylang\Admin\Patch::polylang_available() && ! \Supertext\Polylang\Admin\Patch::is_patched() ) {
	$patched = \Supertext\Polylang\Admin\Patch::apply();
	supertext_demo_log( is_wp_error( $patched ) ? 'Patch failed: ' . $patched->get_error_message() : 'Patched Polylang Pro (pll_mt_services)' );
}

// ---------------------------------------------------------------------------
// Sample content in English, created once.
// ---------------------------------------------------------------------------
if ( ! get_option( 'supertext_demo_content' ) ) {
	wp_delete_post( 1, true ); // "Hello world!"
	wp_delete_post( 2, true ); // "Sample Page"

	$content = array(
		array(
			'post_type'    => 'page',
			'post_title'   => 'Welcome to the Supertext demo',
			'post_content' => "<!-- wp:paragraph -->\n<p>This WordPress site runs Polylang and the Supertext plugin. Write in English, then let Supertext translate the page into German, French and Italian — with AI in seconds, or by professional translators.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Why Supertext?</h2>\n<!-- /wp:heading -->\n\n<!-- wp:list -->\n<ul class=\"wp-block-list\"><li>Swiss quality since 2005</li><li>AI translation trained on your terminology</li><li>Human review when it matters</li></ul>\n<!-- /wp:list -->",
			'front'        => true,
		),
		array(
			'post_type'    => 'page',
			'post_title'   => 'About us',
			'post_content' => "<!-- wp:paragraph -->\n<p>We are a small team from Zurich that loves good language. Our products help companies speak to their customers in every language they need, without losing their voice.</p>\n<!-- /wp:paragraph -->",
		),
		array(
			'post_type'    => 'page',
			'post_title'   => 'Contact',
			'post_content' => "<!-- wp:paragraph -->\n<p>Questions about the demo? Write to us at hello@supertext.com or call us during office hours. We usually answer within one working day.</p>\n<!-- /wp:paragraph -->",
		),
		array(
			'post_type'    => 'post',
			'post_title'   => 'Five tips for translating your website',
			'post_content' => "<!-- wp:paragraph -->\n<p>Start with the pages your visitors see most. Keep sentences short and clear. Agree on key terms before you translate. Let a native speaker review headlines and calls to action. And plan updates: a translated site is never finished.</p>\n<!-- /wp:paragraph -->",
		),
		array(
			'post_type'    => 'post',
			'post_title'   => 'Swiss German, Standard German or both?',
			'post_content' => "<!-- wp:paragraph -->\n<p>Many Swiss companies write in Standard German with Swiss spelling — no ß, and words like Velo instead of Fahrrad. For marketing, a touch of Swiss German can make all the difference.</p>\n<!-- /wp:paragraph -->",
		),
	);

	foreach ( $content as $item ) {
		$id = wp_insert_post(
			array(
				'post_type'    => $item['post_type'],
				'post_title'   => $item['post_title'],
				'post_content' => $item['post_content'],
				'post_status'  => 'publish',
				'post_author'  => 1,
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			if ( function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $id, 'en' );
			}
			if ( ! empty( $item['front'] ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $id );
			}
		}
	}
	update_option( 'supertext_demo_content', 1 );
	supertext_demo_log( 'Created the sample content' );
}
