<?php
/**
 * Activation email – inner content.
 *
 * Notifies the customer that their B2B profile has been activated.
 * Wrapped by base.php.
 *
 * @package GD_B2B
 * @since   2.0.0
 *
 * @var array $args {
 *     @type string $name     Customer display name (already escaped).
 *     @type string $shop_url Shop page URL.
 * }
 */

defined( 'ABSPATH' ) || exit;

$name     = $args['name'] ?? '';
$shop_url = $args['shop_url'] ?? '';
?>
<div style="padding:26px 24px 28px;">

	<p style="margin:0 0 12px;color:#431407;font:600 17px -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,sans-serif;">
		<?php
		printf(
			/* translators: %s: customer display name */
			esc_html__( 'Ciao %s,', 'gd-b2b' ),
			$name
		);
		?>
	</p>

	<p style="margin:0 0 14px;color:#41403f;font:15px/1.65 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,sans-serif;">
		<?php esc_html_e(
			'Il tuo profilo commerciale (B2B) è stato attivato: puoi accedere al negozio e procedere con gli acquisti secondo le condizioni previste per il tuo account.',
			'gd-b2b'
		); ?>
	</p>

	<p style="margin:20px 0 8px;">
		<a href="<?php echo esc_url( $shop_url ); ?>"
			style="display:inline-block;padding:11px 20px;background:#15803d;color:#ffffff;text-decoration:none;border-radius:7px;font:600 14px -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,sans-serif;">
			<?php esc_html_e( 'Vai al negozio', 'gd-b2b' ); ?>
		</a>
	</p>

	<p style="margin:0;font-size:13px;line-height:1.5;color:#78716c;">
		<a href="<?php echo esc_url( $shop_url ); ?>"
			style="color:#92400e;word-break:break-all;">
			<?php echo esc_html( $shop_url ); ?>
		</a>
	</p>

</div>
