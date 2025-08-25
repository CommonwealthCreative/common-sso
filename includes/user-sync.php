<?php
/**
 * User sync and SSO login handler
 */
defined('ABSPATH') || exit;

/**
 * Log in or register a user via SSO (LinkedIn or Google), then send a notification email.
 *
 * @param string $email    User's email address
 * @param string $name     Full name of the user
 * @param string $provider SSO provider key ('linkedin' or 'google')
 */
function common_sso_login_or_register_user($email, $name, $provider) {
    // Try to find existing user
    $user = get_user_by('email', $email);

    if ($user) {
        // Existing user — log them in
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
        $user_id = $user->ID;
    } else {
        // New user — register as WooCommerce customer
        $username = sanitize_user(current(explode('@', $email)));
        $password = wp_generate_password();

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            wp_die('User creation failed: ' . esc_html($user_id->get_error_message()));
        }

        // Set display name and customer role
        wp_update_user([
            'ID'           => $user_id,
            'display_name' => sanitize_text_field($name),
        ]);
        $u = new WP_User($user_id);
        $u->set_role('customer');

        // Track login source
        update_user_meta($user_id, 'common_sso_provider', sanitize_text_field($provider));

        // Log them in
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
    }

    // ─── Send HTML Email Notification ───
    $subject = 'Congrats on getting your Brand Strategy Workbook started';
    // Replace placeholder [your-name] with actual name
    $body = str_replace('[your-name]', esc_html($name), <<<HTML
<table width="600" cellpadding="0" cellspacing="0" style="margin:0 auto;padding:70px 0;width:100%;max-width:600px">
  <tr><td align="center" valign="top">
    <table width="100%" style="background-color:#fff;border:1px solid #dedede;border-radius:3px">
      <tr><td align="center" style="background-color:#202123;color:#ebebeb;padding:36px 48px;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;border-radius:3px 3px 0 0">
        <h1 style="font-size:30px;font-weight:300;line-height:150%;margin:0;text-align:left;color:#5bdeb8">Thanks for Starting the Workbook</h1>
      </td></tr>
      <tr><td style="padding:48px 48px 32px;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:14px;line-height:150%;text-align:left">
        <p>Hi [your-name]!</p>
        <p>Thanks for diving into the Brand Strategy Workbook—taking the time to get clear on your brand is a powerful first step.</p>
        <p>We’ll be in touch soon to explore how we can support you further and help bring your strategy to life.</p>
        <p>In the meantime, if you’d like your own copy of the workbook, it’s available here:</p>
        <p>👉 <a href="https://thecommonwealthcreative.com/shop/downloads/brand-strategy-workbook/">Get the Workbook</a></p>
        <p>Thank you again,<br><strong>Matthew Thomas Small</strong><br>Founder &amp; CEO</p>
        <p>804-424-1348 // <a href="mailto:hi@thecommonwealthcreative.com">hi@thecommonwealthcreative.com</a></p>
        <p><a href="https://cal.com/hello.mattsmall/introduction-with-matt-small-commonwealth-creative">Schedule An Introduction</a></p>
      </td></tr>
      <tr><td align="center" style="padding:24px 0;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:12px;line-height:150%;text-align:center;color:#3c3c3c">
        <a href="https://thecommonwealthcreative.com"><img src="https://mcusercontent.com/3296da0ee4531d137b7a132be/images/1646a1a6-766a-b674-3ab6-797db06403bb.png" alt="The Commonwealth Creative" width="150" style="max-width:100%;height:auto;border-radius:0;margin:0 auto;display:block"></a>
        <p style="margin:16px 0 0">Virginia Created // The Commonwealth Creative — Made with <img src="https://s.w.org/images/core/emoji/15.0.3/72x72/1f49a.png" alt="💚" style="height:1em;max-height:1em;vertical-align:text-bottom"> in the Commonwealth of Virginia</p>
      </td></tr>
    </table>
  </td></tr>
</table>
HTML
    );
    // Set HTML headers
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    wp_mail($email, $subject, $body, $headers);

    // ─── Redirect ───
    $opts = get_option('common_sso_settings') ?: [];
    $redirect_url = !empty($opts['redirect_url'])
        ? esc_url_raw($opts['redirect_url'])
        : wc_get_page_permalink('myaccount');

    wp_redirect($redirect_url);
    exit;
}
