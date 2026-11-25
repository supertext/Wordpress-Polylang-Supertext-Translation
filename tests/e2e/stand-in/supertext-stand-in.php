<?php
/**
 * Plugin Name: Supertext API stand-in (tests only)
 * Description: Answers the Supertext AI file translation API (api.supertext.com/v1) inside WordPress with real translations of the demo's sample content, so tests and screenshots need no API key. Never on a real site.
 *
 * Copy to wp-content/mu-plugins/. Texts it doesn't know come back unchanged. Like the real
 * API it rejects bare targets and regional sources (INVALID_LANGUAGE_PAIR). Version reports
 * to Supertext Plugin Health are answered here too, so a test site never shows up there.
 * With the option `supertext_stand_in_live_ai` on (the docs screenshots in CI, with the secret
 * SUPERTEXT_API_KEY), AI translation requests go to the real API, so the guides show real
 * translations; orders and version reports are still answered here.
 *
 * @package Supertext_Polylang
 */

/**
 * Translations of the demo's sample content (demo/docker/setup.php) by target language.
 *
 * @return array<string, array<string, string>>
 */
function supertext_stand_in_dictionary(): array {
	return array(
		'de' => array(
			'Welcome to the Supertext demo' => 'Willkommen bei der Supertext-Demo',
			'This WordPress site runs Polylang and the Supertext plugin. Write in English, then let Supertext translate the page into German, French and Italian — with AI in seconds, or by professional translators.' => 'Diese WordPress-Website läuft mit Polylang und dem Supertext-Plugin. Schreiben Sie auf Englisch und lassen Sie die Seite von Supertext ins Deutsche, Französische und Italienische übersetzen – mit KI in Sekunden oder von professionellen Übersetzerinnen und Übersetzern.',
			'Why Supertext?' => 'Warum Supertext?',
			'Swiss quality since 2005' => 'Schweizer Qualität seit 2005',
			'AI translation trained on your terminology' => 'KI-Übersetzung, trainiert mit Ihrer Terminologie',
			'Human review when it matters' => 'Menschliche Prüfung, wenn es darauf ankommt',
			'About us' => 'Über uns',
			'Contact' => 'Kontakt',
			'Stay in the loop' => 'Bleiben Sie auf dem Laufenden',
			'Subscribe to our newsletter and get translation tips once a month.' => 'Abonnieren Sie unseren Newsletter und erhalten Sie einmal im Monat Übersetzungstipps.',
			// Demo form (WPForms Multilingual string package).
			'Newsletter' => 'Newsletter',
			'Subscribe' => 'Abonnieren',
			'Sending...' => 'Wird gesendet …',
			'Name' => 'Name',
			'Your name' => 'Ihr Name',
			'We never share your address.' => 'Wir geben Ihre Adresse nie weiter.',
			'Email' => 'E-Mail',
			'Free magazine by post' => 'Kostenloses Magazin per Post',
			'What would you like to receive?' => 'Was möchten Sie erhalten?',
			'Thank you! Please confirm your address in the e-mail we just sent you.' => 'Vielen Dank! Bitte bestätigen Sie Ihre Adresse in der E-Mail, die wir Ihnen soeben gesendet haben.',
		),
		'fr' => array(
			'Welcome to the Supertext demo' => 'Bienvenue dans la démo Supertext',
			'This WordPress site runs Polylang and the Supertext plugin. Write in English, then let Supertext translate the page into German, French and Italian — with AI in seconds, or by professional translators.' => 'Ce site WordPress fonctionne avec Polylang et le plugin Supertext. Rédigez en anglais, puis laissez Supertext traduire la page en allemand, en français et en italien – par l’IA en quelques secondes ou par des traducteurs professionnels.',
			'Why Supertext?' => 'Pourquoi Supertext ?',
			'Swiss quality since 2005' => 'Qualité suisse depuis 2005',
			'AI translation trained on your terminology' => 'Traduction IA entraînée sur votre terminologie',
			'Human review when it matters' => 'Relecture humaine quand c’est important',
			'About us' => 'À propos',
			'Contact' => 'Contact',
			'Stay in the loop' => 'Restez informé',
			'Subscribe to our newsletter and get translation tips once a month.' => 'Abonnez-vous à notre newsletter et recevez des conseils de traduction une fois par mois.',
			// Demo form (WPForms Multilingual string package).
			'Newsletter' => 'Newsletter',
			'Subscribe' => 'S’abonner',
			'Sending...' => 'Envoi en cours…',
			'Name' => 'Nom',
			'Your name' => 'Votre nom',
			'We never share your address.' => 'Nous ne communiquons jamais votre adresse.',
			'Email' => 'E-mail',
			'Free magazine by post' => 'Magazine gratuit par la poste',
			'What would you like to receive?' => 'Que souhaitez-vous recevoir ?',
			'Thank you! Please confirm your address in the e-mail we just sent you.' => 'Merci ! Veuillez confirmer votre adresse dans l’e-mail que nous venons de vous envoyer.',
		),
		'it' => array(
			'Welcome to the Supertext demo' => 'Benvenuti nella demo di Supertext',
			'This WordPress site runs Polylang and the Supertext plugin. Write in English, then let Supertext translate the page into German, French and Italian — with AI in seconds, or by professional translators.' => 'Questo sito WordPress funziona con Polylang e il plugin Supertext. Scriva in inglese e lasci che Supertext traduca la pagina in tedesco, francese e italiano – con l’IA in pochi secondi o da traduttori professionisti.',
			'Why Supertext?' => 'Perché Supertext?',
			'Swiss quality since 2005' => 'Qualità svizzera dal 2005',
			'AI translation trained on your terminology' => 'Traduzione IA addestrata sulla Sua terminologia',
			'Human review when it matters' => 'Revisione umana quando conta',
			'About us' => 'Chi siamo',
			'Contact' => 'Contatti',
			'Stay in the loop' => 'Resti aggiornato',
			'Subscribe to our newsletter and get translation tips once a month.' => 'Si abboni alla nostra newsletter e riceva consigli di traduzione una volta al mese.',
			// Demo form (WPForms Multilingual string package).
			'Newsletter' => 'Newsletter',
			'Subscribe' => 'Iscriviti',
			'Sending...' => 'Invio in corso…',
			'Name' => 'Nome',
			'Your name' => 'Il suo nome',
			'We never share your address.' => 'Non condividiamo mai il suo indirizzo.',
			'Email' => 'E-mail',
			'Free magazine by post' => 'Rivista gratuita per posta',
			'What would you like to receive?' => 'Che cosa desidera ricevere?',
			'Thank you! Please confirm your address in the e-mail we just sent you.' => 'Grazie! Confermi il suo indirizzo nell’e-mail che le abbiamo appena inviato.',
		),
	);
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( str_starts_with( (string) $url, 'https://pluginhealth.supertext.com/' ) ) {
			return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array( 'content-type' => 'application/json' ), 'body' => '{"ok":true}', 'cookies' => array() );
		}
		// Order API (professional translation): the translation types, which the key check reads.
		if ( preg_match( '#^https://[a-z.]*supertext\.(?:com|ch)/api/v1/util/ordertype/#', (string) $url ) ) {
			$types = array(
				array( 'Name' => 'Translation', 'Description' => 'Professional translation by a native speaker.', 'ShortDescription' => 'Translation', 'OrderTypeConfigurationId' => 1, 'OrderTypeId' => 6 ),
				array( 'Name' => 'Translation + proofreading', 'Description' => 'Translation, checked by a second translator.', 'ShortDescription' => 'Translation + proofreading', 'OrderTypeConfigurationId' => 2, 'OrderTypeId' => 7 ),
			);
			return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( $types ), 'cookies' => array() );
		}
		// Order API: file upload, price quote and order creation (no order is placed anywhere).
		if ( preg_match( '#^https://[a-z.]*supertext\.(?:com|ch)/api/(v1/files/files|v1/translation/quote|v1\.1/translation/order)#', (string) $url, $om ) ) {
			$ok = static fn( $body ) => array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( $body ), 'cookies' => array() );
			if ( 'v1/files/files' === $om[1] ) {
				return $ok( array( array( 'Id' => 4711 ) ) );
			}
			if ( 'v1/translation/quote' === $om[1] ) {
				return $ok(
					array(
						'Currency'       => 'CHF',
						'CurrencySymbol' => 'CHF',
						'WordCount'      => 42,
						'Options'        => array(
							array(
								'DeliveryOptions' => array(
									array( 'DeliveryId' => 1, 'Name' => '24 hours', 'Price' => 39.0, 'DeliveryDate' => gmdate( 'c', time() + DAY_IN_SECONDS ), 'IsDefaultDeliveryOption' => false ),
									array( 'DeliveryId' => 3, 'Name' => '3 days', 'Price' => 24.0, 'DeliveryDate' => gmdate( 'c', time() + 3 * DAY_IN_SECONDS ), 'IsDefaultDeliveryOption' => true ),
								),
							),
						),
					)
				);
			}
			return $ok( array( array( 'Id' => 900000 + wp_rand( 1, 99999 ) ) ) );
		}
		if ( ! preg_match( '#^https://api(?:\.staging|\.testing)?\.supertext\.com/v1/(.*)$#', (string) $url, $m ) ) {
			return $pre;
		}
		// Real AI translations (docs screenshots with the live key): let the request through.
		if ( get_option( 'supertext_stand_in_live_ai' ) ) {
			return $pre;
		}
		$path   = $m[1];
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$json   = static fn( int $code, $body ) => array(
			'response' => array( 'code' => $code, 'message' => '' ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'cookies'  => array(),
		);

		if ( 'features' === $path ) {
			return $json( 200, array( 'source_languages' => array( 'en', 'de', 'fr', 'it' ), 'target_languages' => array( 'en-US', 'de-DE', 'de-CH', 'fr-FR', 'fr-CH', 'it-IT', 'it-CH' ) ) );
		}
		if ( 'translate/ai/file' === $path && 'POST' === $method ) {
			$body = (string) ( $args['body'] ?? '' );
			preg_match( '#name="target_lang"\s*\r?\n\r?\n([^\r\n]+)#', $body, $t );
			preg_match( '#name="source_lang"\s*\r?\n\r?\n([^\r\n]+)#', $body, $s );
			$target = trim( $t[1] ?? '' );
			$source = trim( $s[1] ?? '' );
			if ( ! str_contains( $target, '-' ) || str_contains( $source, '-' ) ) {
				return $json( 400, array( 'error' => array( 'code' => 'INVALID_LANGUAGE_PAIR', 'message' => "Invalid language pair: $source → $target" ) ) );
			}
			preg_match( '#<!DOCTYPE.*</html>#si', $body, $doc );
			$html  = $doc[0] ?? '';
			$words = supertext_stand_in_dictionary()[ strtolower( substr( $target, 0, 2 ) ) ] ?? array();
			uksort( $words, static fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
			foreach ( $words as $from => $to ) {
				$html = str_replace( array( '>' . $from . '<', '>' . esc_html( $from ) . '<' ), '>' . esc_html( $to ) . '<', $html );
			}
			$id = 'stand-in-' . md5( $html . microtime() );
			set_transient( 'supertext_stand_in_' . $id, $html, HOUR_IN_SECONDS );
			return $json( 200, array( 'file_id' => $id ) );
		}
		if ( preg_match( '#^translate/ai/file/([^/]+)(/status|/translation)?$#', $path, $f ) ) {
			$html = get_transient( 'supertext_stand_in_' . $f[1] );
			if ( false === $html ) {
				return $json( 404, array( 'error' => array( 'code' => 'NOT_FOUND', 'message' => 'File not found' ) ) );
			}
			if ( '/status' === ( $f[2] ?? '' ) ) {
				return $json( 200, array( 'status' => 'done' ) );
			}
			if ( '/translation' === ( $f[2] ?? '' ) ) {
				return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array( 'content-type' => 'text/html' ), 'body' => $html, 'cookies' => array() );
			}
			if ( 'DELETE' === $method ) {
				delete_transient( 'supertext_stand_in_' . $f[1] );
				return $json( 204, '' );
			}
		}

		return $json( 404, array( 'error' => array( 'code' => 'NOT_FOUND', 'message' => 'Not in the stand-in' ) ) );
	},
	10,
	3
);
