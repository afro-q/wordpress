<?php
/**
 * Plugin Name: My Email Secretary Search Metadata
 * Description: Lightweight search and social metadata for the myemailsecretary.com Multisite blog.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

const MES_SEO_DEFAULT_DESCRIPTION = 'My Email Secretary is an AI optimised email client. It summarises your inbox, reads summaries aloud, and schedules playback for whenever you are available.';

function mes_seo_description(): string
{
    if (is_front_page()) {
        return MES_SEO_DEFAULT_DESCRIPTION;
    }

    if (is_singular()) {
        $post_id = (int) get_queried_object_id();

        $custom = get_post_meta($post_id, '_mes_seo_description', true);
        if ($custom) {
            return wp_strip_all_tags($custom);
        }

        /*
         * Only a hand-written excerpt is trustworthy here. get_the_excerpt() on a
         * page without one synthesises text from the whole block content, which
         * makes a meta description that reads like the entire page.
         */
        if (has_excerpt($post_id)) {
            return wp_strip_all_tags(get_the_excerpt($post_id));
        }
    }

    return MES_SEO_DEFAULT_DESCRIPTION;
}

add_filter('document_title_parts', static function (array $parts): array {
    if (is_singular()) {
        $custom = get_post_meta(get_queried_object_id(), '_mes_seo_title', true);
        if ($custom) {
            $parts['title'] = wp_strip_all_tags($custom);
            unset($parts['site'], $parts['tagline']);
        }
    }
    return $parts;
});

add_action('wp_head', static function (): void {
    if (is_admin() || is_feed() || is_robots()) {
        return;
    }

    $description = mes_seo_description();
    $url = is_singular() ? get_permalink(get_queried_object_id()) : home_url('/');
    $title = wp_get_document_title();

    echo "\n<!-- My Email Secretary search metadata -->\n";
    printf("<meta name=\"description\" content=\"%s\" />\n", esc_attr($description));
    printf("<meta property=\"og:type\" content=\"website\" />\n");
    printf("<meta property=\"og:title\" content=\"%s\" />\n", esc_attr($title));
    printf("<meta property=\"og:description\" content=\"%s\" />\n", esc_attr($description));
    printf("<meta property=\"og:url\" content=\"%s\" />\n", esc_url($url));
    echo "<meta property=\"og:site_name\" content=\"My Email Secretary\" />\n";
    echo "<meta name=\"twitter:card\" content=\"summary\" />\n";
    printf("<meta name=\"theme-color\" content=\"%s\" />\n", esc_attr('#2f4f4f'));

    if (is_front_page()) {
        $schema = [
            '@context'            => 'https://schema.org',
            '@type'               => 'MobileApplication',
            'name'                => 'My Email Secretary',
            'url'                 => home_url('/'),
            'description'         => $description,
            'applicationCategory' => 'ProductivityApplication',
            'operatingSystem'     => 'Android',
            'featureList'         => [
                'AI email summarisation',
                'Voice read-back of email summaries',
                'Scheduling of summary playback',
                'On-device processing',
            ],
            'offers'              => [
                '@type'         => 'Offer',
                'price'         => '0',
                'priceCurrency' => 'USD',
                'availability'  => 'https://schema.org/PreOrder',
            ],
        ];
        printf(
            "<script type=\"application/ld+json\">%s</script>\n",
            wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}, 2);

add_filter('robots_txt', static function (string $output, bool $public): string {
    if (!$public) {
        return $output;
    }

    if (!str_contains($output, 'Sitemap:')) {
        $output .= "\nSitemap: " . home_url('/wp-sitemap.xml') . "\n";
    }
    return $output;
}, 10, 2);
