<?php
/**
 * Plugin Name: Equinilet Contact
 * Description: Two contact paths for equinilet.com: an enquiry form stored in WordPress and a Calendly 30-minute booking embed.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

const EQ_ENQUIRY_TYPE = 'eq_enquiry';
const EQ_NONCE_ACTION = 'eq_contact_submit';
const EQ_CALENDLY_OPTION = 'eq_calendly_url';
const EQ_MESSAGE_MAX = 5000;
const EQ_RATE_LIMIT = 4;               // submissions allowed per window, per IP
const EQ_RATE_WINDOW = HOUR_IN_SECONDS;

/**
 * Enquiries live in the database and are read in wp-admin; nothing is emailed out.
 */
add_action('init', static function (): void {
    register_post_type(EQ_ENQUIRY_TYPE, [
        'labels' => [
            'name'          => 'Enquiries',
            'singular_name' => 'Enquiry',
            'menu_name'     => 'Enquiries',
            'all_items'     => 'All Enquiries',
            'edit_item'     => 'Enquiry',
            'search_items'  => 'Search Enquiries',
            'not_found'     => 'No enquiries yet.',
        ],
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'menu_position'       => 26,
        'menu_icon'           => 'dashicons-email-alt',
        'supports'            => ['title', 'editor'],
        'has_archive'         => false,
        'exclude_from_search' => true,
        'publicly_queryable'  => false,
        'map_meta_cap'        => true,
        'capability_type'     => 'post',
        // Enquiries arrive from the form; nobody authors them by hand.
        'capabilities'        => ['create_posts' => 'do_not_allow'],
    ]);
});

function eq_contact_redirect(string $redirect, string $status): void
{
    $target = add_query_arg('eq', $status, $redirect);
    wp_safe_redirect($target . '#eq-contact-form', 303);
    exit;
}

/**
 * Handles the public form POST. Registered for logged-out and logged-in visitors.
 */
function eq_contact_handle_submission(): void
{
    $redirect = wp_validate_redirect(
        isset($_POST['eq_redirect']) ? wp_unslash($_POST['eq_redirect']) : '',
        home_url('/contact/')
    );

    $nonce = isset($_POST['_eq_nonce']) ? sanitize_text_field(wp_unslash($_POST['_eq_nonce'])) : '';
    if (!wp_verify_nonce($nonce, EQ_NONCE_ACTION)) {
        eq_contact_redirect($redirect, 'expired');
    }

    // Bots fill hidden fields; humans never see this one.
    if (!empty($_POST['eq_website'])) {
        eq_contact_redirect($redirect, 'sent');
    }

    $ip_key = 'eq_contact_' . md5(
        isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown'
    );
    $attempts = (int) get_transient($ip_key);
    if ($attempts >= EQ_RATE_LIMIT) {
        eq_contact_redirect($redirect, 'throttled');
    }

    $email = sanitize_email(wp_unslash($_POST['eq_email'] ?? ''));
    $message = sanitize_textarea_field(wp_unslash($_POST['eq_message'] ?? ''));

    if (!is_email($email)) {
        eq_contact_redirect($redirect, 'email');
    }
    if (mb_strlen(trim($message)) < 10) {
        eq_contact_redirect($redirect, 'message');
    }

    $message = mb_substr($message, 0, EQ_MESSAGE_MAX);
    set_transient($ip_key, $attempts + 1, EQ_RATE_WINDOW);

    $enquiry_id = wp_insert_post(wp_slash([
        'post_type'    => EQ_ENQUIRY_TYPE,
        'post_status'  => 'publish',
        'post_title'   => sprintf('%s — %s', $email, wp_date('j M Y H:i')),
        'post_content' => $message,
        'meta_input'   => [
            '_eq_email'      => $email,
            '_eq_unread'     => 1,
            '_eq_user_agent' => substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300),
            '_eq_ip_hash'    => $ip_key,
        ],
    ]), true);

    if (is_wp_error($enquiry_id)) {
        eq_contact_redirect($redirect, 'error');
    }

    eq_contact_redirect($redirect, 'sent');
}
add_action('admin_post_nopriv_eq_contact', 'eq_contact_handle_submission');
add_action('admin_post_eq_contact', 'eq_contact_handle_submission');

function eq_contact_notice(): string
{
    $status = isset($_GET['eq']) ? sanitize_key(wp_unslash($_GET['eq'])) : '';
    $messages = [
        'sent'      => ['ok', 'Thank you — your request has been received. Expect a reply within one business day.'],
        'email'     => ['error', 'That email address did not look valid. Please check it and try again.'],
        'message'   => ['error', 'Please add a little more detail about what you are trying to do.'],
        'throttled' => ['error', 'Several requests have already been sent from this connection. Please try again later or book a call instead.'],
        'expired'   => ['error', 'The form session expired. Please submit again.'],
        'error'     => ['error', 'Something went wrong saving your request. Please book a call instead.'],
    ];

    if (!isset($messages[$status])) {
        return '';
    }

    [$kind, $text] = $messages[$status];
    return sprintf(
        '<p class="eq-form-notice eq-form-notice--%s" role="status">%s</p>',
        esc_attr($kind),
        esc_html($text)
    );
}

add_shortcode('equinilet_contact_form', static function (): string {
    $redirect = get_permalink() ?: home_url('/contact/');

    return sprintf(
        '<div class="eq-form-wrap" id="eq-contact-form">%s
<form class="eq-form" method="post" action="%s">
<input type="hidden" name="action" value="eq_contact" />
<input type="hidden" name="eq_redirect" value="%s" />
%s
<p class="eq-field"><label for="eq-email">Your email</label>
<input type="email" id="eq-email" name="eq_email" required autocomplete="email" placeholder="you@company.com" /></p>
<p class="eq-field"><label for="eq-message">What are you trying to do?</label>
<textarea id="eq-message" name="eq_message" rows="6" required maxlength="%d" placeholder="The workflow, system or delivery bottleneck you have in mind — and anything about your stack or timeline that helps."></textarea></p>
<p class="eq-field eq-field--hidden" aria-hidden="true"><label for="eq-website">Leave this field empty</label>
<input type="text" id="eq-website" name="eq_website" tabindex="-1" autocomplete="off" /></p>
<p class="eq-form-actions"><button type="submit" class="wp-element-button">Send the details</button></p>
</form></div>',
        eq_contact_notice(),
        esc_url(admin_url('admin-post.php')),
        esc_url($redirect),
        wp_nonce_field(EQ_NONCE_ACTION, '_eq_nonce', true, false),
        EQ_MESSAGE_MAX
    );
});

add_shortcode('equinilet_calendly', static function (): string {
    $url = get_option(EQ_CALENDLY_OPTION, '');
    if (!$url) {
        return '<p class="eq-meta">Scheduling link coming soon — send your details instead and we will offer times.</p>';
    }

    $embed = add_query_arg(
        [
            'hide_gdpr_banner' => 1,
            'primary_color'    => '2d63f0',
            'background_color' => 'ffffff',
            'text_color'       => '101b22',
        ],
        $url
    );

    wp_enqueue_script(
        'calendly-widget',
        'https://assets.calendly.com/assets/external/widget.js',
        [],
        null,
        true
    );

    return sprintf(
        '<div class="eq-calendly"><div class="calendly-inline-widget" data-url="%s" data-auto-load="true"></div>
<noscript><p class="eq-meta"><a href="%s">Open the booking page</a> to pick a time.</p></noscript></div>',
        esc_url($embed),
        esc_url($url)
    );
});

/**
 * Surface the sender's email in the enquiry list, since the title can be truncated.
 */
add_filter('manage_' . EQ_ENQUIRY_TYPE . '_posts_columns', static function (array $columns): array {
    $reordered = [];
    foreach ($columns as $key => $label) {
        if ($key === 'date') {
            $reordered['eq_email'] = 'Email';
        }
        $reordered[$key] = $label;
    }
    return $reordered;
});

add_action('manage_' . EQ_ENQUIRY_TYPE . '_posts_custom_column', static function (string $column, int $post_id): void {
    if ($column !== 'eq_email') {
        return;
    }
    $email = (string) get_post_meta($post_id, '_eq_email', true);
    if (!$email) {
        echo '—';
        return;
    }
    printf('<a href="mailto:%1$s">%1$s</a>', esc_attr($email));
}, 10, 2);

function eq_contact_unread_ids(): array
{
    return get_posts([
        'post_type'      => EQ_ENQUIRY_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'fields'         => 'ids',
        'meta_key'       => '_eq_unread',
        'meta_value'     => 1,
    ]);
}

/**
 * Nothing is emailed, so the admin menu carries the unread count instead.
 */
add_action('admin_menu', static function (): void {
    global $menu;

    $unread = count(eq_contact_unread_ids());
    if (!$unread) {
        return;
    }

    foreach ($menu as $position => $item) {
        if (($item[2] ?? '') === 'edit.php?post_type=' . EQ_ENQUIRY_TYPE) {
            $menu[$position][0] .= sprintf(
                ' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
                $unread
            );
            break;
        }
    }
}, 100);

add_action('load-post.php', static function (): void {
    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if ($post_id && get_post_type($post_id) === EQ_ENQUIRY_TYPE) {
        delete_post_meta($post_id, '_eq_unread');
    }
});
