<?php
defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    register_rest_route('common-sso/v1', '/save-url', [
        'methods'  => 'POST',
        'callback' => 'common_sso_save_form_url',
        'permission_callback' => '__return_true'
    ]);
});

function common_sso_save_form_url(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_REST_Response(['error' => 'Unauthorized'], 401);

    $data = $request->get_json_params();
    $url = esc_url_raw($data['url'] ?? '');

    if ($url) {
        update_user_meta($user_id, 'common_sso_last_form_url', $url);
        return new WP_REST_Response(['status' => 'success']);
    }

    return new WP_REST_Response(['error' => 'No URL provided'], 400);
}

add_action('rest_api_init', function () {
    register_rest_route('common-sso/v1', '/check-login', [
        'methods'  => 'GET',
        'callback' => function () {
            return ['logged_in' => is_user_logged_in()];
        },
        'permission_callback' => '__return_true'
    ]);
});
