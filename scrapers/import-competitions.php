<?php
/**
 * Imports competition records from a JSON file as DRAFT posts.
 *
 *   kubectl -n wordpress exec -i deploy/wordpress -- php < import-competitions.php
 *
 * Reads /tmp/competitions-import.json (array of records with keys matching
 * ccomp_upsert_competition: title, start, end, deadline, location, url,
 * rules_url, rounds, description, contact_name, contact_email,
 * prior_editions, streams, source, source_url).
 */

error_reporting(E_ALL);
require '/var/www/html/wp-load.php';

if (get_current_blog_id() !== 1) {
    fwrite(STDERR, 'Refusing to modify a non-primary site (blog ' . get_current_blog_id() . ").\n");
    exit(1);
}

$path = '/tmp/competitions-import.json';
if (!is_readable($path)) {
    fwrite(STDERR, "Cannot read {$path}\n");
    exit(1);
}

$records = json_decode((string) file_get_contents($path), true);
if (!is_array($records)) {
    fwrite(STDERR, "Invalid JSON in {$path}\n");
    exit(1);
}

$created = 0;
$updated = 0;
$skipped = 0;

foreach ($records as $record) {
    $title = trim((string) ($record['title'] ?? ''));
    if ($title === '') {
        $skipped++;
        continue;
    }

    // Existing post (for update detection) by source_url or slug.
    $existing_id = 0;
    $source_url = trim((string) ($record['source_url'] ?? ''));
    if ($source_url !== '') {
        $found = get_posts([
            'post_type'      => 'competition',
            'post_status'    => ['draft', 'publish', 'pending', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_competition_source_url',
            'meta_value'     => $source_url,
        ]);
        if ($found) {
            $existing_id = (int) $found[0];
        }
    }
    if (!$existing_id) {
        $existing = get_page_by_path(sanitize_title($title), OBJECT, 'competition');
        if ($existing) {
            $existing_id = (int) $existing->ID;
        }
    }

    $id = ccomp_upsert_competition($record, 'draft');
    if ($id <= 0) {
        $skipped++;
        fwrite(STDERR, "  SKIP (insert failed): {$title}\n");
        continue;
    }

    $existing_id ? $updated++ : $created++;
    echo sprintf("  %s post %d  %s\n", $existing_id ? 'UPD' : 'NEW', $id, $title);

    // Throttle so a full re-run does not trip the pod's liveness probe.
    usleep(150000);
}

printf(
    "DONE: created=%d updated=%d skipped=%d\n",
    $created,
    $updated,
    $skipped
);
