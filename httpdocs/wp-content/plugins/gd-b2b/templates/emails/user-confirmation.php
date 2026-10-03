<?php
/**
 * User confirmation email – inner content.
 *
 * Sent to the customer right after company registration to confirm
 * the request has been received and is pending review.
 * Wrapped by base.php.
 *
 * @package GD_B2B
 * @since   2.0.0
 *
 * @var array $args (empty – static content only)
 */

defined( 'ABSPATH' ) || exit;
?>
<div style="padding:26px 24px 28px;">

	<p style="margin:0 0 14px;color:#431407;font:600 17px -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,sans-serif;">
		<?php esc_html_e( 'Abbiamo ricevuto la tua richiesta', 'gd-b2b' ); ?>
	</p>

	<p style="margin:0 0 12px;color:#41403f;font:15px/1.65 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,sans-serif;">
		<?php esc_html_e(
			'Abbiamo ricevuto la tua richiesta di registrazione profilo commerciale (B2B). Riceverai un\'email quando sarà stata esaminata e il tuo account sarà stato abilitato.',
			'gd-b2b'
		); ?>
	</p>

	<p style="margin:0;color:#57534e;font:15px/1.65 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,sans-serif;">
		<?php esc_html_e( 'Fino ad allora non è possibile effettuare acquisti sul sito.', 'gd-b2b' ); ?>
	</p>

</div>
