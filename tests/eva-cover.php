<?php
namespace {
define('DASHEN_SEO_GEO_TEST', true);
$GLOBALS['hooks'] = [];
$GLOBALS['thumbs'] = [];
$GLOBALS['meta'] = [];
$GLOBALS['cron'] = [];
$GLOBALS['posts'] = [];
$GLOBALS['options'] = [];

function add_action($name, $callback, $priority = 10, $accepted_args = 1): void
{
    $GLOBALS['hooks'][$name] = $callback;
}
function add_filter($name, $callback, $priority = 10, $accepted_args = 1): void {}
function add_image_size($name, $width, $height, $crop = false): void
{
    $GLOBALS['image_sizes'][$name] = [(int) $width, (int) $height, (bool) $crop];
}
function has_post_thumbnail(int $id): bool { return !empty($GLOBALS['thumbs'][$id]); }
function get_post_thumbnail_id($id = 0): int { return (int) ($GLOBALS['thumbs'][$id] ?? 0); }
function mysql2date($format, $date, $translate = true) { return '2026.10.07'; }
function get_post_meta(int $id, string $key, bool $single = false) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta(int $id, string $key, $value): void { $GLOBALS['meta'][$id][$key] = $value; }
function delete_post_meta(int $id, string $key): void { unset($GLOBALS['meta'][$id][$key]); }
function wp_next_scheduled($hook, $args = []) { return false; }
function wp_schedule_single_event($timestamp, $hook, $args = []): void { $GLOBALS['cron'][] = ['hook' => $hook, 'args' => $args]; }
function spawn_cron($time = null): void {}
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_attached_file($id) { return $GLOBALS['files'][$id] ?? ''; }
function wp_get_attachment_url($id) { return $GLOBALS['urls'][$id] ?? ''; }
function wp_get_attachment_metadata($id) { return $GLOBALS['attmeta'][$id] ?? []; }
function wp_update_attachment_metadata($id, $meta) { $GLOBALS['attmeta'][$id] = $meta; }
function attachment_url_to_postid($url) { return (int) ($GLOBALS['url_ids'][$url] ?? 0); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = true): void { $GLOBALS['options'][$key] = $value; }
function wp_strip_all_tags($text) { return trim(strip_tags((string) $text)); }
function get_object_taxonomies($post, $output = 'names') {
    if (is_object($post) && ($post->post_type ?? '') === 'product') {
        return [(object) ['hierarchical' => true, 'name' => 'product_cat']];
    }
    if (is_object($post) && ($post->post_type ?? '') === 'post') {
        return [(object) ['hierarchical' => true, 'name' => 'category']];
    }
    return [];
}
function get_the_terms($post, $taxonomy) {
    if ($taxonomy === 'product_cat') {
        return [(object) ['name' => '诊断']];
    }
    if ($taxonomy === 'category') {
        return [(object) ['name' => '杂文']];
    }
    return false;
}
function check($condition, $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require __DIR__ . '/../wordpress/eva-cover.php';

check(isset($GLOBALS['hooks']['transition_post_status']), 'publish hook missing');
check(isset($GLOBALS['hooks'][\Dashen\SeoGeo\EVA_CRON]), 'cron hook missing');
check(isset($GLOBALS['hooks'][\Dashen\SeoGeo\EVA_BATCH]), 'batch hook missing');

$post = (object) ['ID' => 49, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => '测试文章', 'post_date' => '2026-10-07 12:00:00'];
$GLOBALS['hooks']['transition_post_status']('publish', 'draft', $post);
check(($GLOBALS['cron'][0]['args'][0] ?? null) === 49, 'did not queue article');

$GLOBALS['cron'] = [];
$GLOBALS['thumbs'][49] = 50;
\Dashen\SeoGeo\eva_queue_cover(49);
check($GLOBALS['cron'] === [], 'queued over existing cover');

$GLOBALS['meta'] = [];
$GLOBALS['cron'] = [];
\Dashen\SeoGeo\eva_queue_cover(49, true);
check(($GLOBALS['cron'][0]['args'][0] ?? null) === 49, 'did not queue replace');

$GLOBALS['thumbs'] = [];
$GLOBALS['meta'] = [];
$GLOBALS['cron'] = [];
$page = (object) ['ID' => 8, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => '关于'];
$GLOBALS['hooks']['transition_post_status']('publish', 'draft', $page);
check($GLOBALS['cron'] === [], 'queued a page');

foreach (['docs' => 138, 'product' => 311, 'link' => 305] as $type => $id) {
    $GLOBALS['cron'] = [];
    $GLOBALS['meta'] = [];
    $item = (object) ['ID' => $id, 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $type, 'post_date' => '2026-10-07 12:00:00'];
    $GLOBALS['hooks']['transition_post_status']('publish', 'draft', $item);
    check(($GLOBALS['cron'][0]['args'][0] ?? null) === $id, 'did not queue ' . $type);
}

$GLOBALS['hooks']['transition_post_status']('publish', 'publish', $post);
check(\Dashen\SeoGeo\eva_can_write_cover(false, []) === true, 'new post may write text card');
check(\Dashen\SeoGeo\eva_can_write_cover(true, []) === true, 'replace without anime may write text card');
check(\Dashen\SeoGeo\eva_can_write_cover(true, ['animeLayer' => true]) === true, 'replace with anime may write');
check(\Dashen\SeoGeo\eva_is_protected_attachment(50) === true, 'default thumb 50 is protected');
check(\Dashen\SeoGeo\eva_is_protected_attachment(36) === true, 'skip media is protected');
check(\Dashen\SeoGeo\eva_is_protected_attachment(54) === false, 'promo 54 is writable');
$product = (object) ['ID' => 311, 'post_type' => 'product', 'post_title' => '个人工作流诊断', 'post_excerpt' => "带着现有工作流先确认范围与费用\n第二行"];
check(\Dashen\SeoGeo\eva_subtitle($product) === '带着现有工作流先确认范围与费用', 'product excerpt subtitle');
$article = (object) ['ID' => 9, 'post_type' => 'post', 'post_title' => '测试', 'post_excerpt' => '摘要不应优先'];
check(\Dashen\SeoGeo\eva_subtitle($article) === '杂文', 'article category subtitle');
$GLOBALS['options'][\Dashen\SeoGeo\EVA_QUEUE] = [9, 49];
check(\Dashen\SeoGeo\eva_start_replace_all() === 2, 'existing queue must not be overwritten');
check($GLOBALS['options'][\Dashen\SeoGeo\EVA_QUEUE] === [9, 49], 'queue ids changed');
check(($GLOBALS['image_sizes']['dashen_sq'] ?? null) === [1200, 1200, false], 'native dashen_sq size missing');
check(\Dashen\SeoGeo\EVA_LIST_THUMB_PX === 1200, 'list thumb must be 1200 for mobile 1:1 cards');
$thumb_meta = \Dashen\SeoGeo\eva_list_thumb_meta('/tmp/cover-dashen-sq.webp');
check(($thumb_meta['file'] ?? '') === 'cover-dashen-sq.webp', 'list thumb file');
check(($thumb_meta['width'] ?? 0) === 1200 && ($thumb_meta['height'] ?? 0) === 1200, 'list thumb pixels');

$dir = sys_get_temp_dir() . '/dashen-eva-thumb-' . bin2hex(random_bytes(4));
mkdir($dir);
$cover = $dir . '/pytorch-cover.webp';
$square = $dir . '/pytorch-cover-dashen-sq.webp';
file_put_contents($cover, 'wide');
file_put_contents($square, 'sq');
check(\Dashen\SeoGeo\eva_square_file_beside($cover) === $square, 'sibling dashen-sq wins');
check(\Dashen\SeoGeo\eva_square_file_beside($dir . '/missing.webp') === '', 'missing file is empty');
$extra = \Dashen\SeoGeo\eva_with_link_site_icon(['site_icon' => 'https://old.example/cover.webp', 'keep' => '1'], 'https://i.dashen.wang/new.webp');
check(($extra['site_icon'] ?? '') === 'https://i.dashen.wang/new.webp', 'link extra site_icon updates');
check(($extra['keep'] ?? '') === '1', 'link extra other keys stay');
$GLOBALS['posts'][305] = (object) ['ID' => 305, 'post_type' => 'link', 'post_status' => 'publish', 'post_title' => 'PyTorch'];
$GLOBALS['thumbs'][305] = 772;
$GLOBALS['files'][772] = $cover;
$GLOBALS['urls'][772] = 'https://i.dashen.wang/pytorch-cover-1.webp';
$GLOBALS['meta'][305]['_guaqi_link_extra'] = ['site_icon' => 'https://i.dashen.wang/old-cover.webp'];
$GLOBALS['url_ids']['https://i.dashen.wang/old-cover.webp'] = 671;
$GLOBALS['files'][671] = $dir . '/old-cover.webp';
file_put_contents($GLOBALS['files'][671], 'old');
check(\Dashen\SeoGeo\eva_sync_link_site_icon(305) === true, 'link site_icon sync');
check(($GLOBALS['meta'][305]['_guaqi_link_extra']['site_icon'] ?? '') === 'https://i.dashen.wang/pytorch-cover-1.webp', 'link extra points at featured');
check((int) ($GLOBALS['meta'][305]['_guaqi_link_site_icon_id'] ?? 0) === 772, 'split site_icon id');
check(is_file($dir . '/old-cover-dashen-sq.webp'), 'old icon got a square sibling');
check(\Dashen\SeoGeo\eva_sync_link_site_icon(49) === false, 'article does not rewrite site_icon');
$GLOBALS['posts'][311] = (object) ['ID' => 311, 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => '个人工作流诊断'];
\Dashen\SeoGeo\eva_rebuild_product_cards(311);
\Dashen\SeoGeo\eva_rebuild_product_cards(49);
foreach (glob($dir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($dir);

$article_opts = \Dashen\SeoGeo\eva_article_options($article);
check(($article_opts['format'] ?? '') === 'article', 'article format');
check(empty($article_opts['backgroundFile']), 'article must not attach Comfy background');
check(($article_opts['content']['mark'] ?? 'x') === '', 'article mark stays empty');
$product_opts = \Dashen\SeoGeo\eva_article_options($product);
check(($product_opts['format'] ?? '') === 'product', 'product format');
check(empty($product_opts['backgroundFile']), 'product must not attach Comfy background');
$promo_opts = \Dashen\SeoGeo\eva_promo_options('自有 AI 转型', '自己的模型');
check(($promo_opts['format'] ?? '') === 'promo', 'promo format');
check(($promo_opts['content']['date'] ?? 'x') === '', 'promo hides date');

$GLOBALS['posts'][49] = $post;
$GLOBALS['thumbs'][49] = 50;
\Dashen\SeoGeo\eva_apply_cover(49, true);
check(($GLOBALS['thumbs'][49] ?? 0) === 50, 'protected default thumb 50 must stay');

echo "PASS eva publish queue for article/docs/product/link, skip cover and page, replace override\n";
}
