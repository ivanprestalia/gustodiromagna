<?php
/**
 * Comando WP-CLI: prove invio minimale (WordPress wp_mail + mail() PHP).
 *
 * @package GD_B2B
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * @param array<int, mixed> $args        Argomenti posizionali (non usati).
 * @param array<string, mixed> $assoc_args Opzioni `--key=value`.
 * @return void
 */
function gd_b2b_cli_mail_test_dispatch( $_args, $assoc_args ) {
	unset( $_args );
	$assoc_args = is_array( $assoc_args ) ? $assoc_args : array();

	$to = isset( $assoc_args['to'] ) ? sanitize_email( (string) $assoc_args['to'] ) : '';
	if ( '' === $to || ! is_email( $to ) ) {
		$to = sanitize_email( (string) get_option( 'admin_email' ) );
	}
	if ( '' === $to || ! is_email( $to ) ) {
		\WP_CLI::error( __( 'Destinatario non valido: usa --to=email@valido.com oppure imposta admin_email.', 'gd-b2b' ) );
	}

	if ( isset( $assoc_args['show-sendmail-path'] ) && 'yes' === (string) $assoc_args['show-sendmail-path'] ) {
		$path = ini_get( 'sendmail_path' );
		\WP_CLI::log( 'sendmail_path=' . ( is_string( $path ) ? $path : '(null)' ) );
	}

	$subj_mail = '[GD-B2B test] wp_mail ' . gmdate( 'c' );
	$body_mail = "Test wp_mail()\nUTC: " . gmdate( 'c' ) . "\nSitename: " . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . "\n";

	$wp_err = '';
	$cap_fail = static function ( $failure ) use ( &$wp_err ) {
		if ( $failure instanceof WP_Error ) {
			$wp_err = implode( ' ', $failure->get_error_messages() );
		}
	};
	add_action( 'wp_mail_failed', $cap_fail, 10, 1 );
	$wp_ok = wp_mail( $to, $subj_mail, $body_mail );
	remove_action( 'wp_mail_failed', $cap_fail, 10 );

	\WP_CLI::log( 'wp_mail() … ' . ( $wp_ok ? 'OK (true)' : 'FALLITO (false)' ) );
	if ( $wp_err !== '' ) {
		\WP_CLI::log( '  wp_mail_failed: ' . $wp_err );
	}

	$php_subj = '[GD-B2B test] PHP mail() ' . gmdate( 'c' );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.mail_mail_mail -- diagnostica WP-CLI
	$raw_ok = @mail( $to, $php_subj, "Test funzione PHP mail()\nUTC: " . gmdate( 'c' ) . "\n" );

	\WP_CLI::log( 'mail() … ' . ( $raw_ok ? 'OK (true)' : 'FALLITO (false)' ) );

	if ( ! $wp_ok && ! $raw_ok ) {
		\WP_CLI::error( __( 'Entrambi i test falliti. Controllare MTA/sendmail e i log di sistema.', 'gd-b2b' ) );
	}

	\WP_CLI::success( sprintf( /* translators: %s: email */ __( 'Completato. Controlla posta e spam: %s', 'gd-b2b' ), $to ) );
}

\WP_CLI::add_command(
	'gd-b2b mail-test',
	'gd_b2b_cli_mail_test_dispatch',
	array(
		'shortdesc' => 'Invio di prova (wp_mail + mail PHP) per diagnosticare la posta sul server.',
		'synopsis'  => array(
			array(
				'type'        => 'assoc',
				'name'        => 'to',
				'optional'    => true,
				'description' => 'Destinatario (default admin_email)',
			),
			array(
				'type'        => 'assoc',
				'name'        => 'show-sendmail-path',
				'optional'    => true,
				'description' => 'yes = stampa sendmail_path',
			),
		),
	)
);
