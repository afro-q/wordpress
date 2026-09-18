<?php
/**
 * Plugin Name: Equinilet Search Metadata
 * Description: Lightweight search and social metadata for the equinilet.com Multisite blog.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

function eq_seo_description(): string
{
    if (is_singular()) {
        $custom = get_post_meta(get_queried_object_id(), '_eq_seo_description', true);
        if ($custom) {
            return wp_strip_all_tags($custom);
        }

        $excerpt = get_the_excerpt(get_queried_object_id());
        if ($excerpt) {
            return wp_strip_all_tags($excerpt);
        }
    }

    return 'Equinilet is an agentic software development consultancy helping teams build production AI agents, adopt agentic workflows and modernise software systems.';
}

add_filter('document_title_parts', static function (array $parts): array {
    if (is_singular()) {
        $custom = get_post_meta(get_queried_object_id(), '_eq_seo_title', true);
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

    $description = eq_seo_description();
    $url = is_singular() ? get_permalink(get_queried_object_id()) : home_url('/');
    $title = wp_get_document_title();
    $type = is_single() ? 'article' : 'website';

    echo "\n<!-- Equinilet search metadata -->\n";
    printf("<meta name=\"description\" content=\"%s\" />\n", esc_attr($description));
    printf("<meta property=\"og:type\" content=\"%s\" />\n", esc_attr($type));
    printf("<meta property=\"og:title\" content=\"%s\" />\n", esc_attr($title));
    printf("<meta property=\"og:description\" content=\"%s\" />\n", esc_attr($description));
    printf("<meta property=\"og:url\" content=\"%s\" />\n", esc_url($url));
    echo "<meta property=\"og:site_name\" content=\"Equinilet\" />\n";
    echo "<meta name=\"twitter:card\" content=\"summary\" />\n";

    if (is_front_page()) {
        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'ProfessionalService',
            'name'        => 'Equinilet',
            'url'         => home_url('/'),
            'description' => $description,
            'knowsAbout'  => [
                'Agentic software development',
                'AI agents',
                'LangGraph',
                'LangChain',
                'Legacy system migration',
                'Automated software testing',
                'Customer support automation',
            ],
            'serviceType' => [
                'Agentic software development consulting',
                'AI agent implementation',
                'Engineering team upskilling',
                'Agentic workflow adoption',
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
