<?php
namespace {
define('DASHEN_SEO_GEO_TEST', true);
$GLOBALS['hooks'] = [];
$GLOBALS['thumbs'] = [];
$GLOBALS['meta'] = [];
$GLOBALS['cron'] = [];
$GLOBALS['posts'] = [];

function add_action($name, $callback, $priority = 10, $accepted_args = 1): void
{
    $GLOBALS['hooks'][$name] = $callback;
}
function add_filter($name, $callback, $priority = 10, $accepted_args = 1): void {}
function has_post_thumbnail(int $id): bool { return !empty($GLOBALS['thumbs'][$id]); }
function get_post_meta(int $id, string $key, bool $single = false) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta(int $id, string $key, $value): void { $GLOBALS['meta'][$id][$key] = $value; }
function delete_post_meta(int $id, string $key): void { unset($GLOBALS['meta'][$id][$key]); }
function wp_next_scheduled($hook, $args = []) { return false; }
function wp_schedule_single_event($timestamp, $hook, $args = []): void { $GLOBALS['cron'][] = ['hook' => $hook, 'args' => $args]; }
function spawn_cron($time = null): void {}
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_option($key, $default = false) { return $default; }
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
echo "PASS eva publish queue for article/docs/product/link, skip cover and page, replace override\n";
}
