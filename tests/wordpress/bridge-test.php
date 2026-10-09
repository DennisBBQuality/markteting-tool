<?php

// Isolated contract tests. Never load WordPress or access its database/customer data.
define('ABSPATH', __DIR__.'/not-a-wordpress-site/');
class WP_Error
{
    public function __construct(public string $code, public string $message, public array $data) {}
}
function is_wp_error($x)
{
    return $x instanceof WP_Error;
}
function rest_ensure_response($x)
{
    return $x;
}
function register_activation_hook(...$args) {}
function add_action(...$args) {}
function register_rest_route($namespace, $route, $args)
{
    $GLOBALS['routes'][$route] = $args;
}
function add_role($id, $label, $caps)
{
    $GLOBALS['role'] = $caps;
}
function current_user_can($cap)
{
    return in_array($cap, $GLOBALS['caps']);
}
function get_current_user_id()
{
    return 7;
}
function get_posts($args)
{
    return array_keys(array_filter($GLOBALS['posts'], fn ($p) => ($p['meta_input']['_pitboard_reference'] ?? '') === $args['meta_value']));
}
function get_post_meta($id, $key, $single)
{
    return $GLOBALS['posts'][$id]['meta_input'][$key] ?? '';
}
function update_post_meta($id, $key, $value)
{
    if (($GLOBALS['fail_alt'] ?? false) && $key === '_wp_attachment_image_alt') {
        return false;
    }
    $GLOBALS['posts'][$id]['meta_input'][$key] = $value;
}
function get_post_field($key, $id)
{
    return $GLOBALS['posts'][$id][$key] ?? '';
}
function get_post_status($id)
{
    return $GLOBALS['posts'][$id]['post_status'] ?? 'inherit';
}
function get_attached_file($id)
{
    return '/test/'.$GLOBALS['posts'][$id]['filename'];
}
function wp_get_attachment_url($id)
{
    return 'https://bbquality.test/uploads/'.$GLOBALS['posts'][$id]['filename'];
}
function wp_slash($x)
{
    return $x;
}
function wp_update_post($data, $error)
{
    $id = $data['ID'];
    $GLOBALS['posts'][$id] = array_merge($GLOBALS['posts'][$id], $data);

    return $id;
}
function media_handle_sideload($file, $parent, $description, $post)
{
    $GLOBALS['uploads']++;
    if ($parent !== 0) {
        throw new RuntimeException('Must never place media on a product');
    }
    $id = count($GLOBALS['posts']) + 1;
    $GLOBALS['posts'][$id] = [...$post, 'filename' => $file['name']];

    return $id;
}
function sanitize_textarea_field($x)
{
    return strip_tags(trim($x));
}
function sanitize_file_name($x)
{
    return basename($x);
}
$wpdb = new class
{
    public $prefix = 'test_';

    public $posts = 'test_posts';

    public $busy = false;

    public $releases = 0;

    public function prepare($sql, $value)
    {
        return [$sql, $value];
    }

    public function get_var($query)
    {
        if (str_contains($query[0], 'GET_LOCK')) {
            return $this->busy ? 0 : 1;
        }
        if (str_contains($query[0], 'RELEASE_LOCK')) {
            $this->releases++;

            return 1;
        }
        foreach ($GLOBALS['posts'] as $id => $post) {
            if (($post['post_name'] ?? '') === $query[1]) {
                return $id;
            }
        }

        return null;
    }
};
$GLOBALS['posts'] = [];
$GLOBALS['uploads'] = 0;
$GLOBALS['caps'] = [];
require __DIR__.'/../../integrations/wordpress/pitboard-media-bridge/pitboard-media-bridge.php';
$checks = 0;
function check($truth, $label)
{
    global $checks;
    $checks++;
    if (! $truth) {
        throw new RuntimeException($label);
    }
}
function request(array $data)
{
    return new class($data)
    {
        public function __construct(private array $data) {}

        public function get_param($key)
        {
            return $this->data[$key] ?? null;
        }

        public function get_file_params()
        {
            return [];
        }
    };
}
$ref = 'pitboard:11111111-1111-1111-1111-111111111111:22222222-2222-2222-2222-222222222222:1:v1';
$fields = ['filename' => 'test-foto.webp', 'alt' => 'TEST foto', 'title' => 'TEST', 'caption' => '', 'description' => 'TEST beschrijving'];
$hash = str_repeat('a', 64);
$persist = new ReflectionMethod(BBQuality_Pitboard_Media_Bridge::class, 'persist');
$save = fn ($f, $h, $rev) => $persist->invoke(null, ['name' => 'test.webp'], $ref, $f, $h, $rev);
BBQuality_Pitboard_Media_Bridge::install();
check($GLOBALS['role'] === ['read' => true, 'upload_files' => true, 'pitboard_media_bridge' => true], 'Least-privilege role');
BBQuality_Pitboard_Media_Bridge::register();
check(! $GLOBALS['routes']['/media'][1]['permission_callback'](), 'No anonymous uploads');
$GLOBALS['caps'] = ['upload_files'];
check(! $GLOBALS['routes']['/media'][1]['permission_callback'](), 'Custom capability required');
$GLOBALS['caps'][] = 'pitboard_media_bridge';
check($GLOBALS['routes']['/media'][1]['permission_callback'](), 'Role can upload');
check(BBQuality_Pitboard_Media_Bridge::lookup(request(['source_ref' => 'bad']))->data['status'] === 400, 'Invalid reference');
$wpdb->busy = true;
check(BBQuality_Pitboard_Media_Bridge::upload(request(['source_ref' => $ref, 'source' => 'pitboard']))->data['status'] === 409, 'Atomic concurrent exclusion');
check($GLOBALS['uploads'] === 0, 'Busy lock never uploads');
$wpdb->busy = false;
check(BBQuality_Pitboard_Media_Bridge::upload(request(['source_ref' => $ref, 'source' => 'pitboard', 'product_id' => 5]))->data['status'] === 400, 'Product placement prohibited');
check(BBQuality_Pitboard_Media_Bridge::upload(request(['source_ref' => $ref, 'source' => 'pitboard', ...$fields]))->data['status'] === 400, 'Missing upload rejected');
check($wpdb->releases === 1, 'Lock released on validation error');
$first = $save($fields, $hash, 0);
check($first['status'] === 'created', 'Initial creation');
$retry = $save($fields, $hash, 0);
check($retry['attachment_id'] === $first['attachment_id'] && $GLOBALS['uploads'] === 1, 'Retry no duplicate');
check($save($fields, str_repeat('b', 64), 1)->data['status'] === 409, 'Immutable image bytes');
$changed = [...$fields, 'alt' => 'Nieuwe TEST alt'];
check($save($changed, $hash, 0)->data['status'] === 409, 'Stale revision blocked');
$updated = $save($changed, $hash, 1);
check($updated['revision'] === 2 && $GLOBALS['uploads'] === 1, 'SEO update same attachment');
$renamed = [...$changed, 'filename' => 'andere-naam.webp'];
check($save($renamed, $hash, 2)['filename_unchanged'] === true, 'Preserve existing file URL');
$GLOBALS['fail_alt'] = true;
check($save([...$renamed, 'alt' => 'Niet opgeslagen'], $hash, 3)->data['status'] === 500, 'Failed metadata write is not confirmed');
check(get_post_meta(1, '_pitboard_revision', true) === 3, 'Failed metadata write does not advance revision');
$GLOBALS['fail_alt'] = false;
update_post_meta(1, '_wp_attachment_image_alt', 'Handmatig aangepast');
check($save($renamed, $hash, 3)->data['status'] === 409, 'Manual edits protected even on identical retry');
$GLOBALS['posts'][1]['post_author'] = 8;
check(BBQuality_Pitboard_Media_Bridge::lookup(request(['source_ref' => $ref]))->data['status'] === 409, 'Other users media protected');
$GLOBALS['posts'][1]['post_author'] = 7;
$GLOBALS['posts'][1]['post_status'] = 'trash';
check(BBQuality_Pitboard_Media_Bridge::lookup(request(['source_ref' => $ref]))->data['status'] === 409, 'Trash not recreated');
$GLOBALS['posts'][1]['post_status'] = 'inherit';
unset($GLOBALS['posts'][1]['meta_input']);
check($save($fields, $hash, 0)->data['status'] === 409 && $GLOBALS['uploads'] === 1, 'Crash before metadata cannot create duplicate');
echo "WordPress bridge: $checks checks passed (isolated stubs, no WordPress database).\n";
