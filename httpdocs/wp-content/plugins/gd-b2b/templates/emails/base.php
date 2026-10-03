<?php
/**
 * Base email wrapper template.
 *
 * Produces a complete HTML document with branded header, content area and footer.
 *
 * @package GD_B2B
 * @since   2.0.0
 *
 * @var array $args {
 *     @type string $inner_html Main content HTML (already escaped where needed).
 *     @type string $brand      Site name (already escaped).
 *     @type string $logo_url   Logo image URL (can be empty).
 * }
 */

defined( 'ABSPATH' ) || exit;

$inner_html  = $args['inner_html'] ?? '';
$brand       = $args['brand'] ?? '';
$logo_url    = $args['logo'] ?? ( $args['logo_url'] ?? '' );
$footer_text = $args['footer_text'] ?? '';

if ( $logo_url !== '' ) {
	$logo_html = '<img src="' . esc_attr( $logo_url ) . '" alt="' . $brand . '"'
		. ' style="display:block;margin:0 auto;max-height:72px;width:auto;max-width:100%;" />';
} else {
	$logo_html = '<span style="display:inline-block;color:#292524;'
		. "font:bold 22px/1.2 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,sans-serif;"
		. 'letter-spacing:-0.02em;">'
		. $brand
		. '</span>';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width,initial-scale=1" />
</head>
<body style="margin:0;background:#f5f5f4;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
	style="margin:0;padding:8px 12px;">
<tr><td align="center">

	<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600"
		style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06);">

		<!-- Header -->
		<tr>
			<td align="center" style="padding:14px 24px 12px;background:#ffffff;">
				<?php echo $logo_html; ?>
			</td>
		</tr>

		<!-- Yellow separator line -->
		<tr>
			<td style="padding:0;font-size:0;line-height:0;">
				<div style="height:2px;background:#fcb900;"></div>
			</td>
		</tr>

		<!-- Content -->
		<tr>
			<td style="padding:0;">
				<?php echo $inner_html; ?>
			</td>
		</tr>

	</table>

	<p style="margin:16px auto 0;max-width:600px;color:#a8a29e;font:12px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,sans-serif;text-align:center;">
		<?php echo $brand; ?>
<?php if ( '' !== $footer_text ) : ?>
		<br /><?php echo nl2br( esc_html( $footer_text ) ); ?>
<?php endif; ?>
	</p>

</td></tr>
</table>
</body>
</html>
