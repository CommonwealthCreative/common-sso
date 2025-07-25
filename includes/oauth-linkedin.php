<?php
/**
 * OAuth handlers for LinkedIn SSO
 */
defined('ABSPATH') || exit;

// We only declare functions here; constants (CLIENT_ID, CLIENT_SECRET, REDIRECT_URI) are defined in the main plugin on plugins_loaded.

/**
 * Build the LinkedIn OAuth 2.0 authorization URL with the approved OIDC scopes.
 *
 * @return string
 */
function common_sso_get_linkedin_auth_url() {
    $state = wp_create_nonce('linkedin_sso');
    $params = [
        'response_type' => 'code',
        'client_id'     => COMMON_SSO_LINKEDIN_CLIENT_ID,
        'redirect_uri'  => COMMON_SSO_LINKEDIN_REDIRECT_URI,
        'scope'         => 'openid profile email',
        'state'         => $state,
    ];
    return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query($params);
}

/**
 * Handle the LinkedIn OAuth callback: exchange code for token, fetch user info, then log in or create the WP user.
 *
 * @param string $code  Authorization code from LinkedIn
 * @param string $state Nonce/state parameter to verify
 */
function common_sso_handle_linkedin_callback($code, $state) {
    if (!wp_verify_nonce($state, 'linkedin_sso')) {
        wp_die('Invalid state');
    }

    // 1) Exchange the authorization code for an access token
    $token_response = wp_remote_post(
        'https://www.linkedin.com/oauth/v2/accessToken',
        [
            'body' => [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => COMMON_SSO_LINKEDIN_REDIRECT_URI,
                'client_id'     => COMMON_SSO_LINKEDIN_CLIENT_ID,
                'client_secret' => COMMON_SSO_LINKEDIN_CLIENT_SECRET,
            ],
        ]
    );

    $token_data = json_decode(wp_remote_retrieve_body($token_response), true);
    if (empty($token_data['access_token'])) {
        wp_die('LinkedIn token error: ' . esc_html($token_data['error_description'] ?? 'Unknown error'));
    }
    $access_token = $token_data['access_token'];

    // Optional: store token expiry timestamp for later use
    if (!empty($token_data['expires_in'])) {
        $expiry_ts = time() + intval($token_data['expires_in']);
        add_action('wp_login', function($login, $user) use ($expiry_ts) {
            update_user_meta($user->ID, 'common_sso_token_expires', $expiry_ts);
        }, 10, 2);
    }

    // 2) Fetch the user info via the OIDC userinfo endpoint
    $userinfo_response = wp_remote_get(
        'https://api.linkedin.com/v2/userinfo',
        [ 'headers' => [ 'Authorization' => 'Bearer ' . $access_token ] ]
    );

    $userinfo = json_decode(wp_remote_retrieve_body($userinfo_response), true);
    if (empty($userinfo['email'])) {
        wp_die('Failed to fetch LinkedIn email');
    }

    // Sanitize and extract the returned data
    $email      = sanitize_email($userinfo['email']);
    $first_name = sanitize_text_field($userinfo['given_name'] ?? '');
    $last_name  = sanitize_text_field($userinfo['family_name'] ?? '');
    $full_name  = trim("{$first_name} {$last_name}");

    // 3) Hand off to the shared user-registration/login helper
    common_sso_login_or_register_user($email, $full_name, 'linkedin');
}
