<?php
/**
 * Plugin Name: My Email Secretary Signups
 * Description: Alpha-tester email capture for myemailsecretary.com (stored in WordPress, listed in wp-admin) plus the cookie notice the site needs.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

const MES_SIGNUP_TYPE = 'mes_signup';
const MES_SIGNUP_NONCE = 'mes_signup_submit';
const MES_CONSENT_COOKIE = 'mes_cookie_consent';
const MES_CONSENT_MONTHS = 6;
const MES_SIGNUP_RATE = 4;                 // submissions allowed per window, per IP
const MES_SIGNUP_WINDOW = HOUR_IN_SECONDS;

/**
 * Alpha-tester signups live in the database and are read in wp-admin.
 * WordPress email is unreliable on this cluster, so nothing is sent out.
 */
add_action('init', static function (): void {
    register_post_type(MES_SIGNUP_TYPE, [
        'labels' => [
            'name'          => 'Alpha Testers',
            'singular_name' => 'Alpha Tester',
            'menu_name'     => 'Alpha Testers',
            'all_items'     => 'All Alpha Testers',
            'edit_item'     => 'Alpha Tester',
            'search_items'  => 'Search Alpha Testers',
            'not_found'     => 'No signups yet.',
        ],
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'menu_position'       => 26,
        'menu_icon'           => 'dashicons-email',
        'supports'            => ['title'],
        'has_archive'         => false,
        'exclude_from_search' => true,
        'publicly_queryable'  => false,
        // Signups arrive from the form; nobody authors them by hand.
        'capabilities'        => ['create_posts' => 'do_not_allow'],
        'map_meta_cap'        => true,
    ]);
});

function mes_signup_redirect(string $redirect, string $status): void
{
    $target = add_query_arg('mes', $status, $redirect);
    wp_safe_redirect($target . '#alpha-tester', 303);
    exit;
}

/**
 * Finds an existing signup for an address so repeat submissions stay calm.
 */
function mes_signup_find(string $email): int
{
    $existing = get_posts([
        'post_type'      => MES_SIGNUP_TYPE,
        'post_status'    => ['publish', 'draft', 'pending'],
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_mes_email',
        'meta_value'     => $email,
    ]);

    return $existing ? (int) $existing[0] : 0;
}

/**
 * Handles the public signup POST for logged-out and logged-in visitors.
 */
function mes_signup_handle_submission(): void
{
    $redirect = wp_validate_redirect(
        isset($_POST['mes_redirect']) ? wp_unslash($_POST['mes_redirect']) : '',
        home_url('/')
    );

    $nonce = isset($_POST['_mes_nonce']) ? sanitize_text_field(wp_unslash($_POST['_mes_nonce'])) : '';
    if (!wp_verify_nonce($nonce, MES_SIGNUP_NONCE)) {
        mes_signup_redirect($redirect, 'expired');
    }

    // Bots fill hidden fields; humans never see this one.
    if (!empty($_POST['mes_website'])) {
        mes_signup_redirect($redirect, 'subscribed');
    }

    $ip_key = 'mes_signup_' . md5(
        isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown'
    );
    $attempts = (int) get_transient($ip_key);
    if ($attempts >= MES_SIGNUP_RATE) {
        mes_signup_redirect($redirect, 'throttled');
    }

    $email = sanitize_email(wp_unslash($_POST['mes_email'] ?? ''));
    if (!is_email($email)) {
        mes_signup_redirect($redirect, 'email');
    }

    // Signing up twice is a no-op, not an error.
    if (mes_signup_find($email)) {
        mes_signup_redirect($redirect, 'exists');
    }

    set_transient($ip_key, $attempts + 1, MES_SIGNUP_WINDOW);

    $signup_id = wp_insert_post(wp_slash([
        'post_type'    => MES_SIGNUP_TYPE,
        'post_status'  => 'publish',
        'post_title'   => sprintf('%s — %s', $email, wp_date('j M Y H:i')),
        'post_content' => '',
        'meta_input'   => [
            '_mes_email'      => $email,
            '_mes_unread'     => 1,
            '_mes_source'     => substr(sanitize_text_field(wp_unslash($_POST['mes_source'] ?? 'home')), 0, 60),
            '_mes_user_agent' => substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300),
            '_mes_ip_hash'    => $ip_key,
        ],
    ]), true);

    if (is_wp_error($signup_id)) {
        mes_signup_redirect($redirect, 'error');
    }

    mes_signup_redirect($redirect, 'subscribed');
}
add_action('admin_post_nopriv_mes_signup', 'mes_signup_handle_submission');
add_action('admin_post_mes_signup', 'mes_signup_handle_submission');

function mes_signup_notice(): string
{
    $status = isset($_GET['mes']) ? sanitize_key(wp_unslash($_GET['mes'])) : '';
    $messages = [
        'subscribed' => ['ok', 'You are on the alpha list. We will email you the moment a build is ready for you.'],
        'exists'     => ['ok', 'That address is already on the alpha list — nothing more to do.'],
        'email'      => ['error', 'That email address did not look valid. Please check it and try again.'],
        'throttled'  => ['error', 'Several signups have already come from this connection. Please try again later.'],
        'expired'    => ['error', 'The form session expired. Please submit again.'],
        'error'      => ['error', 'Something went wrong saving your signup. Please try again.'],
    ];

    if (!isset($messages[$status])) {
        return '';
    }

    [$kind, $text] = $messages[$status];
    return sprintf(
        '<p class="mes-form-notice mes-form-notice--%s" role="status">%s</p>',
        esc_attr($kind),
        esc_html($text)
    );
}

add_shortcode('myemailsecretary_signup_form', static function (): string {
    $redirect = get_permalink() ?: home_url('/');

    return sprintf(
        '<div class="mes-signup" id="alpha-tester">%s
<p class="mes-signup__lede">Alpha testers get the first builds, and a direct line to us about what to fix. No marketing email, ever.</p>
<form class="mes-signup__form" method="post" action="%s">
<input type="hidden" name="action" value="mes_signup" />
<input type="hidden" name="mes_redirect" value="%s" />
<input type="hidden" name="mes_source" value="home" />
%s
<label class="mes-signup__label" for="mes-email">Your email</label>
<div class="mes-signup__row">
<input type="email" id="mes-email" class="mes-signup__input" name="mes_email" required autocomplete="email" placeholder="you@example.com" />
<button type="submit" class="mes-signup__button">Sign up</button>
</div>
<p class="mes-field--hidden" aria-hidden="true"><label for="mes-website">Leave this field empty</label>
<input type="text" id="mes-website" name="mes_website" tabindex="-1" autocomplete="off" /></p>
<p class="mes-signup__fineprint">By signing up you agree to our <a href="%s">privacy policy</a>. Unsubscribe any time in one click.</p>
</form></div>',
        mes_signup_notice(),
        esc_url(admin_url('admin-post.php')),
        esc_url($redirect),
        wp_nonce_field(MES_SIGNUP_NONCE, '_mes_nonce', true, false),
        esc_url(home_url('/privacy-policy/'))
    );
});

/**
 * The cookie notice. Dismissal is remembered in a first-party cookie, so the
 * banner is rendered server-side only until the visitor accepts.
 */
function mes_cookie_notice_markup(): string
{
    return '<div class="mes-cookies" id="mes-cookies" role="region" aria-label="Cookie notice" hidden>
<p><strong>This website uses cookies.</strong> We use cookies to analyze website traffic and optimize your website experience. By accepting our use of cookies, your data will be aggregated with all other user data.</p>
<div class="mes-cookies__actions">
<button type="button" class="mes-cookies__accept" onclick="document.cookie=\'' . MES_CONSENT_COOKIE . '=accepted;path=/;max-age=' . (MES_CONSENT_MONTHS * 30 * DAY_IN_SECONDS) . ';samesite=lax\';var n=document.getElementById(\'mes-cookies\');n.hidden=true;n.style.display=\'none\';return false;">Accept</button>
<a class="mes-cookies__link" href="' . esc_url(home_url('/privacy-policy/')) . '">Learn more</a>
</div></div>';
}

add_action('wp_footer', static function (): void {
    if (is_admin() || is_feed()) {
        return;
    }
    if (!empty($_COOKIE[MES_CONSENT_COOKIE])) {
        return;
    }

    echo mes_cookie_notice_markup();
    echo '<script>(function(){var n=document.getElementById("mes-cookies");if(n){n.hidden=false;n.style.display="flex";}})();</script>';
}, 5);

/**
 * Signups are listed by address, since the title is easy to truncate.
 */
add_filter('manage_' . MES_SIGNUP_TYPE . '_posts_columns', static function (array $columns): array {
    $reordered = [];
    foreach ($columns as $key => $label) {
        if ($key === 'date') {
            $reordered['mes_email'] = 'Email';
        }
        $reordered[$key] = $label;
    }
    return $reordered;
});

add_action('manage_' . MES_SIGNUP_TYPE . '_posts_custom_column', static function (string $column, int $post_id): void {
    if ($column !== 'mes_email') {
        return;
    }
    $email = (string) get_post_meta($post_id, '_mes_email', true);
    if (!$email) {
        echo '—';
        return;
    }
    printf('<a href="mailto:%1$s">%1$s</a>', esc_attr($email));
}, 10, 2);

/**
 * Nothing is emailed, so the admin menu carries the unread count instead.
 */
add_action('admin_menu', static function (): void {
    global $menu;

    $unread = get_posts([
        'post_type'      => MES_SIGNUP_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'fields'         => 'ids',
        'meta_key'       => '_mes_unread',
        'meta_value'     => 1,
    ]);

    if (!$unread) {
        return;
    }

    foreach ($menu as $position => $item) {
        if (($item[2] ?? '') === 'edit.php?post_type=' . MES_SIGNUP_TYPE) {
            $menu[$position][0] .= sprintf(
                ' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
                count($unread)
            );
            break;
        }
    }
}, 100);

add_action('load-post.php', static function (): void {
    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if ($post_id && get_post_type($post_id) === MES_SIGNUP_TYPE) {
        delete_post_meta($post_id, '_mes_unread');
    }
});
