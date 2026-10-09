<?php

/**
 * Plugin Name: BBQuality Pitboard Media Bridge
 * Description: Beveiligde media-only uitbreiding naast BBQuality Connect. Geen productwijzigingen.
 * Version: 1.0.0
 * License: GPLv2 or later
 */
if (! defined('ABSPATH')) {
    exit;
}

final class BBQuality_Pitboard_Media_Bridge
{
    public static function install(): void
    {
        add_role('pitboard_media', 'Pitboard — alleen media', ['read' => true, 'upload_files' => true, 'pitboard_media_bridge' => true]);
    }

    public static function register(): void
    {
        $permission = static fn () => current_user_can('upload_files') && current_user_can('pitboard_media_bridge');
        register_rest_route('bbquality-connect/v2', '/connection', ['methods' => 'GET', 'permission_callback' => $permission,
            'callback' => static fn () => rest_ensure_response(['protocol' => 2, 'username' => wp_get_current_user()->user_login,
                'can_upload' => true, 'site_url' => untrailingslashit(home_url()), 'plugin_version' => '1.0.0'])]);
        register_rest_route('bbquality-connect/v2', '/media', [
            ['methods' => 'GET', 'permission_callback' => $permission, 'callback' => [self::class, 'lookup']],
            ['methods' => 'POST', 'permission_callback' => $permission, 'callback' => [self::class, 'upload']],
        ]);
    }

    private static function reference($request)
    {
        $ref = (string) $request->get_param('source_ref');
        if (! preg_match('/^pitboard:[a-f0-9-]{36}:[a-f0-9-]{36}:[0-9]+:v[0-9]+$/D', $ref)) {
            return new WP_Error('invalid_reference', 'Ongeldige bronverwijzing.', ['status' => 400]);
        }

        return $ref;
    }

    private static function find(string $reference): int
    {
        global $wpdb;
        // Include trash: retries must not silently recreate deliberately removed media.
        $ids = get_posts(['post_type' => 'attachment', 'post_status' => ['inherit', 'private', 'trash'], 'numberposts' => 1,
            'fields' => 'ids', 'meta_key' => '_pitboard_reference', 'meta_value' => $reference]);

        // The slug is part of the posts INSERT itself. Recover even when a worker died
        // before meta_input finished: that partial attachment must block a second upload.
        return (int) ($ids[0] ?? $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_name = %s LIMIT 1",
            'pitboard-'.hash('sha256', $reference)
        )));
    }

    private static function result(int $id, string $status = 'exists', bool $filenameUnchanged = false)
    {
        if ((int) get_post_field('post_author', $id) !== get_current_user_id() || get_post_status($id) === 'trash') {
            return new WP_Error('unavailable', 'Deze bronverwijzing is niet beschikbaar voor deze gebruiker.', ['status' => 409]);
        }

        return rest_ensure_response(['found' => true, 'attachment_id' => $id, 'url' => wp_get_attachment_url($id),
            'revision' => (int) get_post_meta($id, '_pitboard_revision', true), 'status' => $status,
            'filename_unchanged' => $filenameUnchanged]);
    }

    public static function lookup($request)
    {
        $reference = self::reference($request);
        if (is_wp_error($reference)) {
            return $reference;
        }
        $id = self::find($reference);

        return $id ? self::result($id) : rest_ensure_response(['found' => false, 'revision' => 0]);
    }

    public static function upload($request)
    {
        global $wpdb;
        $reference = self::reference($request);
        if (is_wp_error($reference)) {
            return $reference;
        }
        if ($request->get_param('product_id') || $request->get_param('placement') || $request->get_param('source') !== 'pitboard') {
            return new WP_Error('media_only', 'Alleen upload naar de mediatheek is toegestaan.', ['status' => 400]);
        }
        // DB advisory locks are atomic across PHP workers and released on connection loss.
        // No expiring option/cache lock: expiry during an upload could create duplicates.
        $lock = 'pitboard:'.substr(hash('sha256', $wpdb->prefix.$reference), 0, 48);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            return new WP_Error('busy', 'Deze foto wordt verwerkt. Probeer later opnieuw.', ['status' => 409]);
        }
        try {
            return self::lockedUpload($request, $reference);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function lockedUpload($request, string $reference)
    {
        $fields = [];
        foreach (['filename', 'alt', 'title', 'caption', 'description'] as $key) {
            $fields[$key] = sanitize_textarea_field((string) $request->get_param($key));
        }
        $fields['filename'] = sanitize_file_name($fields['filename']);
        if (! $fields['alt'] || ! $fields['title'] || ! preg_match('/^[a-z0-9-]+\.webp$/D', $fields['filename'])) {
            return new WP_Error('metadata_required', 'Een geldige WebP-bestandsnaam, alt-tekst en titel zijn verplicht.', ['status' => 400]);
        }
        $files = $request->get_file_params();
        $file = $files['file'] ?? null;
        if (! $file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ! is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('no_file', 'Geen geldige upload ontvangen.', ['status' => 400]);
        }
        if (filesize($file['tmp_name']) > 15 * 1024 * 1024) {
            return new WP_Error('too_big', 'Maximaal 15 MB per foto.', ['status' => 413]);
        }
        $image = @getimagesize($file['tmp_name']);
        if (! $image || ($image['mime'] ?? '') !== 'image/webp') {
            return new WP_Error('bad_type', 'Alleen echte WebP-afbeeldingen zijn toegestaan.', ['status' => 415]);
        }
        $hash = hash_file('sha256', $file['tmp_name']);
        if (! hash_equals($hash, (string) $request->get_param('content_hash'))) {
            return new WP_Error('bad_hash', 'De foto is niet volledig ontvangen.', ['status' => 400]);
        }

        return self::persist($file, $reference, $fields, $hash, (int) $request->get_param('expected_revision'));
    }

    private static function persist(array $file, string $reference, array $fields, string $hash, int $expectedRevision)
    {
        $id = self::find($reference);
        if ($id) {
            $existing = self::result($id);
            if (is_wp_error($existing)) {
                return $existing;
            }
            if (! hash_equals((string) get_post_meta($id, '_pitboard_content_hash', true), $hash)) {
                return new WP_Error('different_image', 'Een gewijzigde foto moet een nieuwe versie krijgen.', ['status' => 409]);
            }
            $previous = get_post_meta($id, '_pitboard_fields', true);
            // Preserve edits made manually in WordPress; require explicit resolution there.
            foreach (['alt' => get_post_meta($id, '_wp_attachment_image_alt', true), 'title' => get_post_field('post_title', $id),
                'caption' => get_post_field('post_excerpt', $id), 'description' => get_post_field('post_content', $id)] as $key => $value) {
                if ($value !== ($previous[$key] ?? '')) {
                    return new WP_Error('manual_edit', 'De SEO is handmatig gewijzigd in WordPress. Los dit eerst daar op.', ['status' => 409]);
                }
            }
            if ($previous === $fields) {
                return self::result($id, 'exists', basename(get_attached_file($id)) !== $fields['filename']);
            }
            if ($expectedRevision !== (int) get_post_meta($id, '_pitboard_revision', true)) {
                return new WP_Error('stale_revision', 'De SEO is ondertussen gewijzigd.', ['status' => 409]);
            }
            $saved = wp_update_post(wp_slash(['ID' => $id, 'post_title' => $fields['title'],
                'post_excerpt' => $fields['caption'], 'post_content' => $fields['description']]), true);
            if (is_wp_error($saved)) {
                return new WP_Error('save_failed', 'SEO opslaan is niet bevestigd.', ['status' => 500]);
            }
            update_post_meta($id, '_wp_attachment_image_alt', wp_slash($fields['alt']));
            foreach (['alt' => get_post_meta($id, '_wp_attachment_image_alt', true), 'title' => get_post_field('post_title', $id),
                'caption' => get_post_field('post_excerpt', $id), 'description' => get_post_field('post_content', $id)] as $key => $value) {
                if ($value !== $fields[$key]) {
                    return new WP_Error('metadata_incomplete', 'De gewijzigde SEO is niet volledig opgeslagen.', ['status' => 500]);
                }
            }
            update_post_meta($id, '_pitboard_fields', wp_slash($fields));
            update_post_meta($id, '_pitboard_revision', 1 + (int) get_post_meta($id, '_pitboard_revision', true));

            return self::result($id, 'updated', basename(get_attached_file($id)) !== $fields['filename']);
        }

        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
        }
        $file['name'] = $fields['filename'];
        // Store the reference with the attachment INSERT, BEFORE slow thumbnail generation.
        // A worker crash afterwards is recovered by lookup, never by uploading another file.
        $id = media_handle_sideload($file, 0, null, wp_slash([
            'post_title' => $fields['title'], 'post_excerpt' => $fields['caption'], 'post_content' => $fields['description'],
            'post_author' => get_current_user_id(), 'meta_input' => [
                '_pitboard_reference' => $reference, '_pitboard_content_hash' => $hash,
                '_pitboard_fields' => $fields, '_pitboard_revision' => 1, '_wp_attachment_image_alt' => $fields['alt'],
            ],
            'post_name' => 'pitboard-'.hash('sha256', $reference),
        ]));
        if (is_wp_error($id)) {
            return new WP_Error('upload_failed', 'WordPress kon de upload niet bevestigen.', ['status' => 500]);
        }

        // Confirm the actual saved fields, not just a successful attachment INSERT.
        foreach (['alt' => get_post_meta($id, '_wp_attachment_image_alt', true), 'title' => get_post_field('post_title', $id),
            'caption' => get_post_field('post_excerpt', $id), 'description' => get_post_field('post_content', $id)] as $key => $value) {
            if ($value !== $fields[$key]) {
                return new WP_Error('metadata_incomplete', 'Upload ontvangen, maar SEO is niet volledig opgeslagen.', ['status' => 500]);
            }
        }

        return self::result((int) $id, 'created', basename(get_attached_file($id)) !== $fields['filename']);
    }
}

register_activation_hook(__FILE__, [BBQuality_Pitboard_Media_Bridge::class, 'install']);
add_action('rest_api_init', [BBQuality_Pitboard_Media_Bridge::class, 'register']);
