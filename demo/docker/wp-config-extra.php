<?php
/**
 * Demo-only additions to wp-config.php (included before wp-settings.php).
 *
 * Railway terminates TLS in a proxy in front of the container: trust its
 * X-Forwarded-Proto header so WordPress generates https URLs.
 */
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
	$_SERVER['HTTPS'] = 'on';
}

// The public URL comes from the platform (Railway sets RAILWAY_PUBLIC_DOMAIN) or DEMO_SITE_URL.
$supertext_demo_url = getenv( 'DEMO_SITE_URL' );
if ( ! $supertext_demo_url && getenv( 'RAILWAY_PUBLIC_DOMAIN' ) ) {
	$supertext_demo_url = 'https://' . getenv( 'RAILWAY_PUBLIC_DOMAIN' );
}
if ( $supertext_demo_url ) {
	define( 'WP_HOME', rtrim( $supertext_demo_url, '/' ) );
	define( 'WP_SITEURL', rtrim( $supertext_demo_url, '/' ) );
}

// The container is rebuilt on every deploy: no file edits or core updates from wp-admin.
define( 'DISALLOW_FILE_EDIT', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_ENVIRONMENT_TYPE', 'staging' );
