<?php
defined('ABSPATH') || exit;

$opts = get_option('common_sso_settings') ?: [];
$client_id     = $opts['linkedin_client_id'] ?? '';
$client_secret = $opts['linkedin_client_secret'] ?? '';
$redirect_uri  = wp_login_url() . '?common_sso=1&provider=linkedin';



function common_sso_get_linkedin_auth_url() {
    $state = wp_create_nonce('linkedin_sso');
    $params = [
        'response_type' => 'code',
        'client_id'     => COMMON_SSO_LINKEDIN_CLIENT_ID,
        'redirect_uri'  => COMMON_SSO_LINKEDIN_REDIRECT_URI,
        'scope'         => 'r_liteprofile r_emailaddress',
        'state'         => $state
    ];
    return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query($params);
}

function common_sso_handle_linkedin_callback($code, $state) {
    if (!wp_verify_nonce($state, 'linkedin_sso')) {
        wp_die('Invalid state');
    }

    // Exchange code for token
    $response = wp_remote_post('https://www.linkedin.com/oauth/v2/accessToken', [
        'body' => [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => COMMON_SSO_LINKEDIN_REDIRECT_URI,
            'client_id'     => COMMON_SSO_LINKEDIN_CLIENT_ID,
            'client_secret' => COMMON_SSO_LINKEDIN_CLIENT_SECRET,
        ]
    ]);

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $token = $data['access_token'] ?? null;
    $expires_in = $data['expires_in'] ?? null;

if ($expires_in) {
    $expiry_timestamp = time() + intval($expires_in);
    // User gets logged in below, so we can now store this against their ID
    add_action('wp_login', function($login, $user) use ($expiry_timestamp) {
        update_user_meta($user->ID, 'common_sso_token_expires', $expiry_timestamp);
    }, 10, 2);
}

    if (!$token) wp_die('LinkedIn token error');

    // Fetch profile
    $profile = wp_remote_get('https://api.linkedin.com/v2/me', [
        'headers' => ['Authorization' => 'Bearer ' . $token]
    ]);
    $profile_data = json_decode(wp_remote_retrieve_body($profile), true);

    // Fetch email
    $email = wp_remote_get('https://api.linkedin.com/v2/emailAddress?q=members&projection=(elements*(handle~))', [
        'headers' => ['Authorization' => 'Bearer ' . $token]
    ]);
    $email_data = json_decode(wp_remote_retrieve_body($email), true);

    $user_email = $email_data['elements'][0]['handle~']['emailAddress'] ?? null;
    $first_name = $profile_data['localizedFirstName'] ?? '';
    $last_name  = $profile_data['localizedLastName'] ?? '';
    $name       = trim("$first_name $last_name");

    if (!$user_email) wp_die('Failed to fetch LinkedIn email');

    common_sso_login_or_register_user($user_email, $name, 'linkedin');
}
