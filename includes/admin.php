<?php
defined('ABSPATH') || exit;

// Add Common SSO under Settings menu
add_action('admin_menu', function () {
    add_options_page(
        'Common SSO Settings',
        'Common SSO',
        'manage_options',
        'common-sso',
        'common_sso_settings_page'
    );
});

// Register settings, sections, and fields
add_action('admin_init', function () {
    register_setting('common_sso_options', 'common_sso_settings', [
        'sanitize_callback' => function($input) {
            if (!is_array($input)) return [];
            foreach ($input as $key => $value) {
                $value = sanitize_text_field(trim($value));
                if (str_contains($key, 'secret')) {
                    $value = common_sso_encrypt($value);
                }
                $input[$key] = $value;
            }
            return $input;
        }
    ]);

    add_settings_section(
        'common_sso_main',
        'OAuth Credentials',
        null,
        'common-sso'
    );

    $fields = [
        'linkedin_client_id'       => 'LinkedIn Client ID',
        'linkedin_client_secret'   => 'LinkedIn Client Secret',
        'google_client_id'         => 'Google Client ID',
        'google_client_secret'     => 'Google Client Secret',
        'redirect_url'             => 'Post-login Redirect URL',
        'common_sso_cf7_form_id'   => 'Login Form (Contact Form 7)', // ✅ unified key
    ];

    foreach ($fields as $key => $label) {
        add_settings_field($key, $label, function () use ($key, $label) {
            $opts = get_option('common_sso_settings') ?: [];

            // Contact Form 7 dropdown
            if ($key === 'common_sso_cf7_form_id') {
                $forms = get_posts([
                    'post_type'      => 'wpcf7_contact_form',
                    'posts_per_page' => -1,
                ]);

                echo '<select name="common_sso_settings[' . esc_attr($key) . ']">';
                echo '<option value="">— Use Default WP Login Form —</option>';

                foreach ($forms as $form) {
                    $selected = selected($opts[$key] ?? '', $form->ID, false);
                    echo '<option value="' . esc_attr($form->ID) . '" ' . $selected . '>' . esc_html($form->post_title) . '</option>';
                }

                echo '</select>';
                echo '<p class="description">Select a Contact Form 7 form to show instead of the WordPress login form.</p>';
                return;
            }

            // OAuth input fields
            $type  = str_contains($key, 'secret') ? 'password' : 'text';
            $value = $opts[$key] ?? '';
            if (str_contains($key, 'secret') && !empty($value) && function_exists('common_sso_decrypt')) {
                $value = common_sso_decrypt($value);
            }

            echo '<input type="' . esc_attr($type) . '" name="common_sso_settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" class="regular-text">';

            if ($key === 'linkedin_client_secret') {
                echo '<p class="description">Redirect URI: ' . esc_url(wp_login_url('?common_sso=1&provider=linkedin')) . '</p>';
            }
            if ($key === 'google_client_secret') {
                echo '<p class="description">Redirect URI: ' . esc_url(wp_login_url('?common_sso=1&provider=google')) . '</p>';
            }
        }, 'common-sso', 'common_sso_main');
    }
});

// Store token expiration in session
add_action('init', function () {
    if (!session_id()) {
        session_start();
    }

    if (!isset($_SESSION['common_sso_token_expire'])) return;

    $now = time();
    $expire = intval($_SESSION['common_sso_token_expire']);

    if ($now > $expire) {
        wp_logout();
        wp_redirect(wp_login_url());
        exit;
    }
});

// Render the admin settings page
function common_sso_settings_page() {
    ?>
    <div class="wrap">
        <h1>Common SSO Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('common_sso_options');
            do_settings_sections('common-sso');
            submit_button();

            $base_url = wp_login_url();
            ?>
            <hr>
            <h2>Test OAuth Login</h2>
            <p>
                <a class="button button-secondary" href="<?php echo esc_url($base_url . '?common_sso=1&provider=linkedin'); ?>">Test LinkedIn Login</a>
                <a class="button button-secondary" href="<?php echo esc_url($base_url . '?common_sso=1&provider=google'); ?>">Test Google Login</a>
            </p>
            <hr>
            <h2>Recent SSO Activity</h2>
            <pre style="background:#fff; border:1px solid #ccc; padding:10px; max-height:200px; overflow:auto;"><?php
            $log_file = COMMON_SSO_DIR . 'common-sso.log';
            if (file_exists($log_file)) {
                echo esc_html(file_get_contents($log_file));
            } else {
                echo 'No log entries yet.';
            }
            ?></pre>
        </form>
    </div>
    <?php
}
