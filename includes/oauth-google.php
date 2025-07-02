<?php
defined('ABSPATH') || exit;

$opts = get_option('common_sso_settings') ?: [];
$client_id     = $opts['google_client_id'] ?? '';
$client_secret = $opts['google_client_secret'] ?? '';
$redirect_uri  = wp_login_url() . '?common_sso=1&provider=google';

function common_sso_get_google_auth_url() {
    $state = wp_create_nonce('google_sso');
    $params = [
        'client_id'     => COMMON_SSO_GOOGLE_CLIENT_ID,
        'redirect_uri'  => COMMON_SSO_GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'access_type'   => 'offline',
        'prompt'        => 'consent'
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

function common_sso_handle_google_callback($code, $state) {
    if (!wp_verify_nonce($state, 'google_sso')) {
        wp_die('Invalid state');
    }

    // Exchange code for token
    $response = wp_remote_post('https://oauth2.googleapis.com/token', [
        'body' => [
            'code'          => $code,
            'client_id'     => COMMON_SSO_GOOGLE_CLIENT_ID,
            'client_secret' => COMMON_SSO_GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => COMMON_SSO_GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code'
        ],
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']
    ]);

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $token = $data['access_token'] ?? null;
    $expires_in = $data['expires_in'] ?? null;

    if (!$token) wp_die('Google token error');

    if ($expires_in) {
        $expiry_timestamp = time() + intval($expires_in);
        add_action('wp_login', function($login, $user) use ($expiry_timestamp) {
            update_user_meta($user->ID, 'common_sso_token_expires', $expiry_timestamp);
        }, 10, 2);
    }

    // Fetch user info
    $userinfo = wp_remote_get('https://www.googleapis.com/oauth2/v3/userinfo', [
        'headers' => ['Authorization' => 'Bearer ' . $token]
    ]);

    $user = json_decode(wp_remote_retrieve_body($userinfo), true);
    $email = $user['email'] ?? null;
    $name  = $user['name'] ?? '';

    if (!$email) wp_die('Failed to fetch Google user');

    common_sso_login_or_register_user($email, $name, 'google');
}
