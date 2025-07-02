<?php
// Exit if accessed directly
defined('ABSPATH') || exit;

// Listen for SSO callback: ?common_sso=1&provider=linkedin|google
add_action('init', function () {
    if (!isset($_GET['common_sso']) || !isset($_GET['provider'])) return;

    $provider = sanitize_text_field($_GET['provider']);

    // ✅ Log the request at the start
    if (function_exists('common_sso_log')) {
        common_sso_log("SSO callback hit for provider: $provider");
    }

    if (!in_array($provider, ['linkedin', 'google'], true)) {
        if (function_exists('common_sso_log')) {
            common_sso_log("Unsupported provider: $provider");
        }
        wp_die('Unsupported provider');
    }

    if (!isset($_GET['code'])) {
        if (function_exists('common_sso_log')) {
            common_sso_log("Missing authorization code for provider: $provider");
        }
        wp_die('Missing authorization code');
    }

    $code = sanitize_text_field($_GET['code']);
    $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : null;

    $handler = "common_sso_handle_{$provider}_callback";
    if (function_exists($handler)) {
        call_user_func($handler, $code, $state);
    } else {
        if (function_exists('common_sso_log')) {
            common_sso_log("OAuth handler not found for provider: $provider");
        }
        wp_die('OAuth handler not found for provider: ' . esc_html($provider));
    }

    exit;
});

// Helper: Get authorization URL
function common_sso_get_auth_url($provider) {
    $function = "common_sso_get_{$provider}_auth_url";
    if (function_exists($function)) {
        return call_user_func($function);
    }
    return '#';
}

// Add button to WooCommerce My Account page
add_action('woocommerce_account_dashboard', function () {
    $user_id = get_current_user_id();
    if (!$user_id) return;

    $saved_form_url = get_user_meta($user_id, 'common_sso_last_form_url', true);
    if ($saved_form_url) {
        echo '<p><a class="button" href="' . esc_url($saved_form_url) . '">Continue Your Saved Form</a></p>';
    }
});
