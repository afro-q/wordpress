<?php
/**
 * Plugin Name: Competition Calendar
 * Description: Piano-competition calendar for "Musings and delights" (qmorake.com): an events post type, an ICS subscription feed, and a FullCalendar month view.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

const CCOMP_POST_TYPE = 'competition';
const CCOMP_ICAL_QV   = 'competition_ical';
const CCOMP_ICAL_SLUG = 'competitions.ics';
const CCOMP_VERSION    = '1.0.0';

/* ------------------------------------------------------------------ events */

function ccomp_normalize_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    try {
        $d = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $d->format('Y-m-d');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * All published competitions, ordered by start date.
 *
 * @return array<int, array{id:int,title:string,start:string,end:string,location:string,url:string,description:string}>
 */
function ccomp_competitions(): array
{
    $posts = get_posts([
        'post_type'      => CCOMP_POST_TYPE,
        'post_status'    => 'publish',
        'numberposts'    => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    $out = [];
    foreach ($posts as $post) {
        $start = ccomp_normalize_date((string) get_post_meta($post->ID, '_competition_start', true));
        if ($start === '') {
            continue;
        }

        $end = ccomp_normalize_date((string) get_post_meta($post->ID, '_competition_end', true));
        if ($end === '' || $end < $start) {
            $end = $start;
        }

        $out[] = [
            'id'          => (int) $post->ID,
            'title'       => get_the_title($post),
            'start'       => $start,
            'end'         => $end,
            'location'    => trim((string) get_post_meta($post->ID, '_competition_location', true)),
            'url'         => trim((string) get_post_meta($post->ID, '_competition_url', true)),
            'description' => trim(wp_strip_all_tags($post->post_content)),
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return strcmp($a['start'], $b['start']) ?: strcmp($a['title'], $b['title']);
    });

    return $out;
}

/* ------------------------------------------------------------- post type */

add_action('init', static function (): void {
    register_post_type(CCOMP_POST_TYPE, [
        'labels' => [
            'name'          => 'Competitions',
            'singular_name' => 'Competition',
            'menu_name'     => 'Competitions',
            'all_items'     => 'All Competitions',
            'add_new_item'  => 'Add Competition',
            'edit_item'     => 'Edit Competition',
            'search_items'  => 'Search Competitions',
            'not_found'     => 'No competitions yet.',
        ],
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'menu_position'       => 25,
        'menu_icon'           => 'dashicons-calendar-alt',
        'supports'            => ['title', 'editor'],
        'show_in_rest'        => false,
        'rewrite'             => false,
        'query_var'           => false,
        'has_archive'         => false,
        'exclude_from_search' => true,
    ]);
});

/* -------------------------------------------------------------- meta box */

add_action('add_meta_boxes', static function (): void {
    add_meta_box('ccomp_details', 'Competition details', 'ccomp_meta_box', CCOMP_POST_TYPE, 'normal', 'high');
});

function ccomp_meta_box(WP_Post $post): void
{
    wp_nonce_field('ccomp_save', 'ccomp_nonce');

    $start    = (string) get_post_meta($post->ID, '_competition_start', true);
    $end      = (string) get_post_meta($post->ID, '_competition_end', true);
    $location = (string) get_post_meta($post->ID, '_competition_location', true);
    $url      = (string) get_post_meta($post->ID, '_competition_url', true);
    ?>
    <p>
        <label for="ccomp_start"><strong>Start date</strong></label><br />
        <input type="date" id="ccomp_start" name="ccomp_start" value="<?php echo esc_attr($start); ?>" required />
    </p>
    <p>
        <label for="ccomp_end"><strong>End date</strong> <span class="description">(leave empty for a single-day event)</span></label><br />
        <input type="date" id="ccomp_end" name="ccomp_end" value="<?php echo esc_attr($end); ?>" />
    </p>
    <p>
        <label for="ccomp_location"><strong>Location</strong></label><br />
        <input type="text" id="ccomp_location" name="ccomp_location" value="<?php echo esc_attr($location); ?>" class="large-text" placeholder="Warsaw, Poland" />
    </p>
    <p>
        <label for="ccomp_url"><strong>Competition website</strong></label><br />
        <input type="url" id="ccomp_url" name="ccomp_url" value="<?php echo esc_attr($url); ?>" class="large-text" placeholder="https://example.com" />
    </p>
    <?php
}

add_action('save_post_' . CCOMP_POST_TYPE, static function (int $post_id): void {
    if (!isset($_POST['ccomp_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['ccomp_nonce'])), 'ccomp_save')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $start = ccomp_normalize_date(sanitize_text_field(wp_unslash($_POST['ccomp_start'] ?? '')));
    $end   = ccomp_normalize_date(sanitize_text_field(wp_unslash($_POST['ccomp_end'] ?? '')));
    $location = sanitize_text_field(wp_unslash($_POST['ccomp_location'] ?? ''));
    $url      = esc_url_raw(wp_unslash($_POST['ccomp_url'] ?? ''));

    if ($start !== '') {
        update_post_meta($post_id, '_competition_start', $start);
    } else {
        delete_post_meta($post_id, '_competition_start');
    }

    if ($end !== '') {
        update_post_meta($post_id, '_competition_end', $end);
    } else {
        delete_post_meta($post_id, '_competition_end');
    }

    update_post_meta($post_id, '_competition_location', $location);
    update_post_meta($post_id, '_competition_url', $url);
});

/* ------------------------------------------------------- admin list view */

add_filter('manage_' . CCOMP_POST_TYPE . '_posts_columns', static function (array $columns): array {
    $out = [];
    foreach ($columns as $key => $label) {
        if ($key === 'date') {
            $out['ccomp_dates']    = 'Dates';
            $out['ccomp_location'] = 'Location';
        }
        $out[$key] = $label;
    }
    return $out;
});

add_action('manage_' . CCOMP_POST_TYPE . '_posts_custom_column', static function (string $column, int $post_id): void {
    if ($column === 'ccomp_dates') {
        $start = (string) get_post_meta($post_id, '_competition_start', true);
        $end   = (string) get_post_meta($post_id, '_competition_end', true);
        if ($start === '') {
            echo '—';
            return;
        }
        $start_f = ccomp_format_date($start);
        $end_f   = ($end !== '' && $end !== $start) ? ccomp_format_date($end) : '';
        echo esc_html($end_f ? $start_f . ' → ' . $end_f : $start_f);
        return;
    }

    if ($column === 'ccomp_location') {
        $location = trim((string) get_post_meta($post_id, '_competition_location', true));
        echo $location !== '' ? esc_html($location) : '—';
    }
}, 10, 2);

function ccomp_format_date(string $ymd): string
{
    try {
        return (new DateTimeImmutable($ymd, new DateTimeZone('UTC')))->format('j M Y');
    } catch (Throwable $e) {
        return $ymd;
    }
}

/* ---------------------------------------------------------------- ICS feed */

add_action('init', static function (): void {
    add_rewrite_rule('^' . CCOMP_ICAL_SLUG . '$', 'index.php?' . CCOMP_ICAL_QV . '=1', 'top');
});

add_filter('query_vars', static function (array $vars): array {
    $vars[] = CCOMP_ICAL_QV;
    return $vars;
});

// Serve the ICS before WordPress's canonical redirect (priority 10) gets a
// chance to add a trailing slash to the .ics URL.
add_action('template_redirect', static function (): void {
    if (get_query_var(CCOMP_ICAL_QV)) {
        ccomp_output_ical();
    }
}, 0);

add_filter('redirect_canonical', static function ($redirect_url) {
    if (get_query_var(CCOMP_ICAL_QV)) {
        return false;
    }
    return $redirect_url;
});

function ccomp_date_to_ical(string $ymd): string
{
    try {
        return (new DateTimeImmutable($ymd, new DateTimeZone('UTC')))->format('Ymd');
    } catch (Throwable $e) {
        return '';
    }
}

function ccomp_date_plus_one_day(string $ymd): string
{
    try {
        return (new DateTimeImmutable($ymd, new DateTimeZone('UTC')))->modify('+1 day')->format('Ymd');
    } catch (Throwable $e) {
        return '';
    }
}

/** Exclusive end date for an all-day event, as Y-m-d (the day after the last day). */
function ccomp_end_exclusive(string $end_ymd): string
{
    try {
        return (new DateTimeImmutable($end_ymd, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
    } catch (Throwable $e) {
        return $end_ymd;
    }
}

function ccomp_ical_escape(string $value): string
{
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
    $value = str_replace([',', ';'], ['\\,', '\\;'], $value);
    return $value;
}

function ccomp_ical_fold(string $line): string
{
    if (strlen($line) <= 75) {
        return $line;
    }

    $parts = [];
    while (strlen($line) > 75) {
        $chunk = mb_strcut($line, 0, 75, 'UTF-8');
        if ($chunk === '') {
            $chunk = substr($line, 0, 75);
        }
        $parts[] = $chunk;
        $line    = substr($line, strlen($chunk));
    }
    if ($line !== '') {
        $parts[] = $line;
    }

    return implode("\r\n ", $parts);
}

function ccomp_output_ical(): void
{
    $site_name = (string) get_bloginfo('name');
    $now       = gmdate('Ymd\THis\Z');

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Musings and delights//Competition Calendar//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . ccomp_ical_escape($site_name . ' — Piano Competitions'),
        'X-WR-CALDESC:' . ccomp_ical_escape('Piano competitions gathered on ' . $site_name . '.'),
        'X-WR-TIMEZONE:UTC',
        'REFRESH-INTERVAL;VALUE=DURATION:P1D',
    ];

    foreach (ccomp_competitions() as $event) {
        $dtstart = ccomp_date_to_ical($event['start']);
        $dtend   = ccomp_date_plus_one_day($event['end']);
        if ($dtstart === '' || $dtend === '') {
            continue;
        }

        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:competition-' . $event['id'] . '@qmorake.com';
        $lines[] = 'DTSTAMP:' . $now;
        $lines[] = 'DTSTART;VALUE=DATE:' . $dtstart;
        $lines[] = 'DTEND;VALUE=DATE:' . $dtend;
        $lines[] = 'SUMMARY:' . ccomp_ical_escape($event['title']);
        if ($event['location'] !== '') {
            $lines[] = 'LOCATION:' . ccomp_ical_escape($event['location']);
        }
        $link = $event['url'] !== '' ? $event['url'] : home_url('/competition-calendar/');
        $lines[] = 'URL:' . ccomp_ical_escape($link);
        if ($event['description'] !== '') {
            $lines[] = 'DESCRIPTION:' . ccomp_ical_escape($event['description']);
        }
        $lines[] = 'END:VEVENT';
    }

    $lines[] = 'END:VCALENDAR';

    $body = '';
    foreach ($lines as $line) {
        $body .= ccomp_ical_fold($line) . "\r\n";
    }

    nocache_headers();
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="piano-competitions.ics"');
    header('X-Robots-Tag: noindex, nofollow');
    echo $body;
    exit;
}

/* ---------------------------------------------------------- calendar view */

add_shortcode('competition_calendar', static function (): string {
    ccomp_enqueue_assets();
    return ccomp_markup();
});

function ccomp_ical_url(): string
{
    return home_url('/' . CCOMP_ICAL_SLUG);
}

function ccomp_webcal_url(): string
{
    return preg_replace('#^https?#i', 'webcal', ccomp_ical_url());
}

function ccomp_events_json(): string
{
    $events = [];
    foreach (ccomp_competitions() as $event) {
        $events[] = [
            'id'            => (string) $event['id'],
            'title'         => $event['title'],
            'start'         => $event['start'],
            'end'           => ccomp_end_exclusive($event['end']),
            'allDay'        => true,
            'url'           => $event['url'] !== '' ? $event['url'] : null,
            'extendedProps' => [
                'location' => $event['location'],
            ],
        ];
    }

    return (string) wp_json_encode($events);
}

function ccomp_enqueue_assets(): void
{
    $base = plugin_dir_url(__FILE__) . 'assets/';

    wp_enqueue_style('comp-cal', $base . 'competition-calendar.css', [], CCOMP_VERSION);
    wp_enqueue_script('fullcalendar-core', $base . 'fullcalendar.core.min.js', [], '6.1.15', true);
    wp_enqueue_script('fullcalendar-daygrid', $base . 'fullcalendar.daygrid.min.js', ['fullcalendar-core'], '6.1.15', true);
    wp_enqueue_script('fullcalendar-list', $base . 'fullcalendar.list.min.js', ['fullcalendar-core'], '6.1.15', true);

    $init = <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('competition-calendar');
    if (!el) return;

    // FullCalendar v6 global bundles auto-register themselves on
    // FullCalendar.globalPlugins when the daygrid/list scripts load, so the
    // `plugins` option must be omitted (passing the module globals throws
    // "e is not iterable").
    var calendar = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        firstDay: 1,
        height: 'auto',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,dayGridWeek,listMonth'
        },
        buttonText: { today: 'Today', month: 'Month', week: 'Week', list: 'Agenda' },
        dayMaxEvents: true,
        displayEventTime: false,
        events: %s,
        eventDidMount: function (info) {
            var location = info.event.extendedProps.location;
            if (location) {
                info.el.setAttribute('title', info.event.title + ' — ' + location);
            }
        },
        eventClick: function (info) {
            var url = info.event.url;
            if (url) {
                info.jsEvent.preventDefault();
                window.open(url, '_blank', 'noopener');
            }
        }
    });

    calendar.render();
});
JS;

    wp_add_inline_script('fullcalendar-list', sprintf($init, ccomp_events_json()), 'after');
}

function ccomp_markup(): string
{
    $webcal  = ccomp_webcal_url();
    $ical    = ccomp_ical_url();

    return sprintf(
        '<div class="comp-cal">
            <div class="comp-cal__bar">
                <a class="comp-cal__btn" href="%1$s" title="Subscribe in your calendar app">&#65291; Add to your calendar</a>
                <span class="comp-cal__note">Subscribe once and it updates automatically. To add it in Google Calendar, Apple Calendar or Outlook, paste this address as a calendar subscription:</span>
                <code class="comp-cal__url"><a href="%2$s">%3$s</a></code>
            </div>
            <div id="competition-calendar" class="comp-cal__grid"></div>
        </div>',
        esc_url($webcal),
        esc_url($ical),
        esc_html($ical)
    );
}
