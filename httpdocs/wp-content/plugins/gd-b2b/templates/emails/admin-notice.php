<?php
/**
 * Admin notice email – inner content.
 *
 * Notifies the site admin about a new B2B company registration.
 * Wrapped by base.php.
 *
 * @package GD_B2B
 * @since   2.0.0
 *
 * @var array $args {
 *     @type string $intro          Intro heading text (already escaped).
 *     @type array  $rows           Array of [ label, value ] pairs for the data table.
 *     @type string $paragraph_html HTML paragraph content after the table (already escaped).
 *     @type string $edit_url       URL to edit the user profile.
 * }
 */

defined( 'ABSPATH' ) || exit;

$intro          = $args['intro'] ?? '';
$rows           = $args['rows'] ?? array();
$paragraph_html = $args['paragraph_html'] ?? '';
$edit_url       = $args['edit_url'] ?? '';
?>
<div style="padding:26px 24px 14px;color:#431407;font:600 17px -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,sans-serif;">
	<?php echo $intro; ?>
</div>

<div style="padding:0 24px 24px;">
	<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
		style="border-collapse:collapse;border-radius:8px;overflow:hidden;border:1px solid #e7e5e4;">
		<?php foreach ( $rows as $i => $pair ) :
			$stripe = $i % 2 === 0 ? '#fafaf9' : '#ffffff';
		?>
		<tr>
			<td style="padding:12px 16px;background:<?php echo $stripe; ?>;color:#78350f;font-weight:700;font-size:14px;line-height:1.4;width:38%;vertical-align:top;border-bottom:1px solid #e7e5e4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,sans-serif;">
				<?php echo esc_html( $pair[0] ); ?>
			</td>
			<td style="padding:12px 16px;background:<?php echo $stripe; ?>;color:#292524;font-size:14px;line-height:1.5;border-bottom:1px solid #e7e5e4;vertical-align:top;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,sans-serif;">
				<?php echo nl2br( esc_html( $pair[1] ) ); ?>
			</td>
		</tr>
		<?php endforeach; ?>
	</table>
</div>

<div style="padding:0 24px 28px;color:#41403f;font:15px/1.65 Georgia,'Times New Roman',Times,serif;">

	<?php echo $paragraph_html; ?>

	<p style="margin:18px 0 8px;">
		<a href="<?php echo esc_url( $edit_url ); ?>"
			style="display:inline-block;padding:11px 20px;background:#15803d;color:#ffffff;text-decoration:none;border-radius:7px;font:600 14px -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,sans-serif;">
			<?php esc_html_e( 'Apri Modifica utente', 'gd-b2b' ); ?>
		</a>
	</p>

	<p style="margin:0;font-size:13px;line-height:1.5;">
		<a href="<?php echo esc_url( $edit_url ); ?>"
			style="color:#92400e;word-break:break-all;">
			<?php echo esc_html( $edit_url ); ?>
		</a>
	</p>

</div>
