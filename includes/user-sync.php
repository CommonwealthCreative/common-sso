<?php
defined('ABSPATH') || exit;

function common_sso_login_or_register_user($email, $name, $provider) {
    $user = get_user_by('email', $email);

    if ($user) {
        // ✅ Existing user — log them in
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
    } else {
        // 🆕 New user — register as WooCommerce customer
        $username = sanitize_user(current(explode('@', $email)));
        $password = wp_generate_password();

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            wp_die('User creation failed: ' . $user_id->get_error_message());
        }

        // Set name + customer role
        wp_update_user([
            'ID'           => $user_id,
            'display_name' => $name
        ]);
        $u = new WP_User($user_id);
        $u->set_role('customer');

        // Track login source
        update_user_meta($user_id, 'common_sso_provider', $provider);

        // Log in
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
    }

    // ✅ Use custom redirect if set
    $opts = get_option('common_sso_settings') ?: [];
    $redirect_url = !empty($opts['redirect_url']) ? esc_url_raw($opts['redirect_url']) : wc_get_page_permalink('myaccount');

    wp_redirect($redirect_url);
    exit;
}
