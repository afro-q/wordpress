<?php
/**
 * Plugin Name: My Email Secretary Well-Known
 * Description: Serves /.well-known/microsoft-identity-association.json for Windows package identity verification.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

/**
 * The path Microsoft fetches exactly; anything else is left to WordPress.
 */
const MES_MS_ASSOC_PATH = '/.well-known/microsoft-identity-association.json';

/**
 * The Windows application this site vouches for.
 */
const MES_MS_ASSOC_APP_ID = '42329a87-cbb3-41bd-880c-3d74bd72dfdc';

/**
 * Answered from PHP rather than as a static file.
 *
 * qmorake.com, equinilet.com and myemailsecretary.com all share one document
 * root, so a real file at .well-known/ would also answer on the other two and
 * publish this application ID on domains that are not associated with it. This
 * plugin is active on the myemailsecretary.com blog only, so the check below is
 * scoped by which site WordPress resolved for the request.
 *
 * Hooked on init, which runs before canonical redirects, 404 handling and
 * template loading, so the response never touches the theme.
 */
add_action('init', static function (): void {
    if (is_admin()) {
        return;
    }

    $path = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!is_string($path) || rtrim($path, '/') !== MES_MS_ASSOC_PATH) {
        return;
    }

    // Plain, stable JSON: a third-party verifier only needs the structure.
    $payload = [
        'associatedApplications' => [
            ['applicationId' => MES_MS_ASSOC_APP_ID],
        ],
    ];

    // Verifiers may re-check at any time, so never let this be cached stale.
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');

    echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}, 1);
