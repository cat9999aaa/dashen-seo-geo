<?php
namespace {
function get_option($key, $default = false) { return $default; }
function wp_json_encode($data) { return json_encode($data); }
function wp_remote_get($url, $args = []) { return new WP_Error('skip'); }
function wp_remote_post($url, $args = []) { return new WP_Error('skip'); }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code($response): int { return 0; }
function wp_remote_retrieve_body($response): string { return ''; }
class WP_Error {}
function check($condition, $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require __DIR__ . '/../wordpress/eva-comfy.php';

check(\Dashen\SeoGeo\Eva\comfy_queue_busy([]) === false, 'empty queue is idle');
check(\Dashen\SeoGeo\Eva\comfy_queue_busy(['queue_running' => [], 'queue_pending' => []]) === false, 'cleared queue is idle');
check(\Dashen\SeoGeo\Eva\comfy_queue_busy(['queue_running' => [['x']], 'queue_pending' => []]) === true, 'running is busy');
check(\Dashen\SeoGeo\Eva\comfy_queue_busy(['queue_running' => [], 'queue_pending' => [['y']]]) === true, 'pending is busy');
$settings = \Dashen\SeoGeo\Eva\comfy_settings();
check($settings['enabled'] === true, 'comfy default on');
check($settings['opacity'] === 40, 'opacity default 40');
echo "PASS eva comfy queue wait helpers\n";
}
