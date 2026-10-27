<?php

defined('ABSPATH') or die;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables in view files
// Inline styles are intentional: email clients do not load external stylesheets.
$href   = esc_url($url);
$plain  = esc_html(esc_url_raw($url));
$home   = esc_url(home_url('/'));
$accent = '#2271b1';
?>
<div style="margin:0;padding:24px;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;">
    <tr><td style="padding:28px;">
      <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#111827;"><?php esc_html_e('Add your past orders to your account', 'fluentform'); ?></h1>
      <p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#374151;"><?php esc_html_e('You asked to add your past guest orders to this account. Click below while signed in to that account to add them. The link only works for that account and expires in 24 hours.', 'fluentform'); ?></p>
      <table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:8px;background:<?php echo esc_attr($accent); ?>;">
        <a href="<?php echo $href; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url above ?>" style="display:inline-block;padding:12px 24px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;"><?php esc_html_e('Add my past orders', 'fluentform'); ?></a>
      </td></tr></table>
      <p style="margin:22px 0 6px;font-size:13px;color:#6b7280;"><?php esc_html_e('Or paste this link into your browser:', 'fluentform'); ?></p>
      <p style="margin:0 0 22px;font-size:13px;word-break:break-all;"><a href="<?php echo $href; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url above ?>" style="color:<?php echo esc_attr($accent); ?>;"><?php echo $plain; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html above ?></a></p>
      <p style="margin:0;font-size:13px;line-height:1.6;color:#9ca3af;"><?php esc_html_e('If you did not request this, you can safely ignore this email.', 'fluentform'); ?></p>
    </td></tr>
    <tr><td style="padding:16px 28px;border-top:1px solid #f3f4f6;font-size:12px;color:#9ca3af;">
      <?php
        // translators: %s is a link to the site name.
        printf(esc_html__('This message was sent by %s', 'fluentform'), '<a href="' . $home . '" style="color:' . esc_attr($accent) . ';text-decoration:none;">' . esc_html($site_name) . '</a>'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- parts escaped above
      ?>
    </td></tr>
  </table>
</div>
