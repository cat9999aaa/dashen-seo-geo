<?php
namespace Dashen\SeoGeo;

require_once __DIR__ . '/eva-api.php';

const EVA_LOCK = '_dashen_eva_cover_lock';
const EVA_CRON = 'dashen_seo_geo_generate_cover';
const EVA_BATCH = 'dashen_seo_geo_cover_batch';
const EVA_QUEUE = 'dashen_eva_cover_queue';
const EVA_SKIP_MEDIA = [36, 42, 45, 51, 52];
const EVA_DEFAULT_THUMB = 50;
const EVA_PROMO_MEDIA = [
    54 => ['title' => '自有 AI 转型', 'subtitle' => '自己的模型'],
    55 => ['title' => '教程与下载', 'subtitle' => '自己的工具'],
    56 => ['title' => 'AI Hub', 'subtitle' => 'Skill 与 MCP'],
];
const EVA_LIST_THUMB_PX = 1200;

function eva_content_types(): array
{
    return ['post', 'docs', 'product', 'link'];
}

function eva_temp_file(string $prefix): string
{
    if (defined('ABSPATH')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (\function_exists('wp_tempnam')) {
        $tmp = \wp_tempnam($prefix);
        if (is_string($tmp) && $tmp !== '') {
            return $tmp;
        }
    }
    $tmp = \tempnam(\sys_get_temp_dir(), preg_replace('/[^A-Za-z0-9_-]/', '', $prefix) ?: 'dashen-eva');
    if (!$tmp) {
        throw new \RuntimeException('无法创建临时封面文件。');
    }
    return $tmp;
}

add_action('transition_post_status', __NAMESPACE__ . '\\eva_on_publish', 20, 3);
add_action(EVA_CRON, __NAMESPACE__ . '\\eva_run_cover_job', 10, 1);
add_action(EVA_BATCH, __NAMESPACE__ . '\\eva_run_batch', 10, 0);
add_filter('wp_generate_attachment_metadata', __NAMESPACE__ . '\\eva_keep_square_metadata', 20, 2);
if (\function_exists('add_image_size')) {
    add_image_size('dashen_sq', 1200, 1200, false);
}
add_action('admin_menu', __NAMESPACE__ . '\\eva_admin_menu');
add_action('admin_post_dashen_eva_promo', __NAMESPACE__ . '\\eva_admin_promo');
add_action('admin_post_dashen_eva_replace', __NAMESPACE__ . '\\eva_admin_replace');

function eva_on_publish($new_status, $old_status, $post): void
{
    if ((string) $new_status !== 'publish' || (string) $old_status === 'publish' || !is_object($post)) {
        return;
    }
    if (!in_array((string) $post->post_type, eva_content_types(), true)) {
        return;
    }
    eva_queue_cover((int) $post->ID, false);
}

function eva_queue_cover(int $post_id, bool $replace = false): void
{
    if ($post_id <= 0) {
        return;
    }
    if (!$replace && has_post_thumbnail($post_id)) {
        return;
    }
    if ((string) get_post_meta($post_id, EVA_LOCK, true) !== '') {
        return;
    }
    update_post_meta($post_id, EVA_LOCK, (string) time());
    if ($replace) {
        update_post_meta($post_id, '_dashen_eva_cover_replace', '1');
    }
    if (!wp_next_scheduled(EVA_CRON, [$post_id])) {
        wp_schedule_single_event(time() + 1, EVA_CRON, [$post_id]);
    }
    if (function_exists('spawn_cron')) {
        spawn_cron(time());
    }
}

function eva_run_cover_job(int $post_id): void
{
    try {
        eva_apply_cover($post_id, (string) get_post_meta($post_id, '_dashen_eva_cover_replace', true) === '1');
    } finally {
        delete_post_meta($post_id, EVA_LOCK);
        delete_post_meta($post_id, '_dashen_eva_cover_replace');
    }
}

function eva_can_write_cover(bool $replace, array $options): bool
{
    return true;
}

function eva_is_protected_attachment(int $attachment_id): bool
{
    return $attachment_id === EVA_DEFAULT_THUMB || in_array($attachment_id, EVA_SKIP_MEDIA, true);
}

function eva_excerpt_line(object $post): string
{
    $excerpt = trim(wp_strip_all_tags((string) ($post->post_excerpt ?? '')));
    $lines = preg_split("/\r?\n/", $excerpt) ?: [];
    $first = trim((string) ($lines[0] ?? ''));
    if ($first === '') {
        return '';
    }
    if (function_exists('mb_substr') && function_exists('mb_strlen')) {
        return mb_strlen($first) > 32 ? mb_substr($first, 0, 32) : $first;
    }
    return strlen($first) > 32 ? substr($first, 0, 32) : $first;
}

function eva_subtitle(object $post): string
{
    if ((string) ($post->post_type ?? '') === 'product') {
        return eva_excerpt_line($post);
    }
    $cat = eva_first_category($post);
    return $cat !== '' ? $cat : eva_excerpt_line($post);
}

function eva_bust_thumb_lite(int $post_id): void
{
    if ($post_id <= 0) {
        return;
    }
    if (class_exists('GUAQI\\Common\\PostThumbLiteCache')) {
        \GUAQI\Common\PostThumbLiteCache::clear($post_id);
    } else {
        $keys = function_exists('get_post_custom_keys') ? get_post_custom_keys($post_id) : [];
        foreach (is_array($keys) ? $keys : [] as $key) {
            if (strpos((string) $key, '_guaqi_thumb_lite_') === 0) {
                delete_post_meta($post_id, (string) $key);
            }
        }
    }
    delete_post_meta($post_id, '_guaqi_cache_product_card_thumbs_lite_v1');
}

function eva_emit_post_cache(int $post_id): void
{
    if ($post_id <= 0 || !class_exists('GUAQI\\Cache\\Events')) {
        return;
    }
    \GUAQI\Cache\Events::emitPost('post.updated', $post_id);
}

function eva_square_file_beside(string $file): string
{
    if ($file === '' || !is_file($file)) {
        return '';
    }
    $square = dirname($file) . DIRECTORY_SEPARATOR . pathinfo($file, PATHINFO_FILENAME) . '-dashen-sq.webp';
    if (is_file($square)) {
        return $square;
    }
    $info = @getimagesize($file);
    $w = is_array($info) ? (int) ($info[0] ?? 0) : 0;
    $h = is_array($info) ? (int) ($info[1] ?? 0) : 0;
    if ($w === EVA_LIST_THUMB_PX && $h === EVA_LIST_THUMB_PX) {
        return $file;
    }
    return '';
}

function eva_square_path_for_attachment(int $attachment_id): string
{
    if ($attachment_id <= 0 || eva_is_protected_attachment($attachment_id)) {
        return '';
    }
    $file = function_exists('get_attached_file') ? get_attached_file($attachment_id) : '';
    return is_string($file) ? eva_square_file_beside($file) : '';
}

function eva_with_link_site_icon(array $extra, string $url): array
{
    $extra['site_icon'] = $url;
    return $extra;
}

function eva_link_site_icon_attachment_id(int $post_id): int
{
    if ($post_id <= 0) {
        return 0;
    }
    if (class_exists('GUAQI\\Common\\Link')) {
        return (int) \GUAQI\Common\Link::get_link_site_icon_attachment_id($post_id);
    }
    $extra = get_post_meta($post_id, '_guaqi_link_extra', true);
    if (is_array($extra) && array_key_exists('site_icon', $extra)) {
        $value = $extra['site_icon'];
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_array($value) && isset($value['id'])) {
            return (int) $value['id'];
        }
        if (is_string($value) && $value !== '' && function_exists('attachment_url_to_postid')) {
            return (int) attachment_url_to_postid($value);
        }
    }
    return (int) get_post_meta($post_id, '_guaqi_link_site_icon_id', true);
}

function eva_bind_square_copy(int $attachment_id, string $square_path): void
{
    if ($attachment_id <= 0 || eva_is_protected_attachment($attachment_id) || !is_file($square_path)) {
        return;
    }
    $file = function_exists('get_attached_file') ? get_attached_file($attachment_id) : '';
    if (!is_string($file) || $file === '') {
        return;
    }
    $dest = dirname($file) . DIRECTORY_SEPARATOR . pathinfo($file, PATHINFO_FILENAME) . '-dashen-sq.webp';
    if ($dest !== $square_path && !is_file($dest) && !@copy($square_path, $dest)) {
        return;
    }
    if (is_file($dest)) {
        eva_bind_square_thumbnail($attachment_id, $dest, true);
    }
}

function eva_rebuild_product_cards(int $post_id): void
{
    $post = get_post($post_id);
    if (!$post || (string) ($post->post_type ?? '') !== 'product') {
        return;
    }
    $class = 'GUAQI\\GraphQL\\Querys\\Common\\Module\\Product';
    if (!class_exists($class)) {
        return;
    }
    try {
        $ref = new \ReflectionClass($class);
        if (!$ref->hasMethod('rebuild_product_graphql_caches')) {
            return;
        }
        $method = $ref->getMethod('rebuild_product_graphql_caches');
        $method->setAccessible(true);
        $method->invoke(null, $post_id);
    } catch (\Throwable $error) {
        error_log('dashen eva product card rebuild ' . $post_id . ': ' . $error->getMessage());
    }
}

function eva_sync_link_site_icon(int $post_id): bool
{
    $post = get_post($post_id);
    if (!$post || (string) ($post->post_type ?? '') !== 'link') {
        return false;
    }
    $thumb_id = function_exists('get_post_thumbnail_id') ? (int) get_post_thumbnail_id($post_id) : 0;
    $square = eva_square_path_for_attachment($thumb_id);
    if ($square === '') {
        return false;
    }
    $url = function_exists('wp_get_attachment_url') ? wp_get_attachment_url($thumb_id) : '';
    if (!is_string($url) || $url === '') {
        return false;
    }
    $old_icon_id = eva_link_site_icon_attachment_id($post_id);
    $extra = get_post_meta($post_id, '_guaqi_link_extra', true);
    if (!is_array($extra)) {
        $extra = [];
    }
    update_post_meta($post_id, '_guaqi_link_extra', eva_with_link_site_icon($extra, $url));
    update_post_meta($post_id, '_guaqi_link_site_icon_id', $thumb_id);
    if ($old_icon_id > 0 && $old_icon_id !== $thumb_id) {
        eva_bind_square_copy($old_icon_id, $square);
    }
    return true;
}

function eva_refresh_list_thumbnails(): array
{
    $done = 0;
    $skip = 0;
    $types = eva_content_types();
    $query = new \WP_Query([
        'post_type' => $types,
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
    ]);
    $ids = is_array($query->posts) ? array_map('intval', $query->posts) : [];
    foreach ($ids as $post_id) {
        $thumb_id = (int) get_post_thumbnail_id($post_id);
        $square = eva_square_path_for_attachment($thumb_id);
        if ($square === '') {
            $skip++;
            continue;
        }
        eva_bind_square_thumbnail($thumb_id, $square, basename($square) !== basename((string) get_attached_file($thumb_id)));
        eva_sync_link_site_icon($post_id);
        eva_rebuild_product_cards($post_id);
        eva_bust_thumb_lite($post_id);
        eva_emit_post_cache($post_id);
        $done++;
    }
    return ['done' => $done, 'skip' => $skip];
}

function eva_write_square_sizes(int $attachment_id, array $options): void
{
    if ($attachment_id <= 0 || eva_is_protected_attachment($attachment_id)) {
        return;
    }
    $format = (string) ($options['format'] ?? 'article');
    if ($format === 'product' || $format === 'promo') {
        return;
    }
    if (!function_exists('get_attached_file')) {
        return;
    }
    $file = get_attached_file($attachment_id);
    if (!is_string($file) || $file === '' || !is_file($file)) {
        return;
    }
    $dir = dirname($file);
    $base = pathinfo($file, PATHINFO_FILENAME);
    $square_path = $dir . DIRECTORY_SEPARATOR . $base . '-dashen-sq.webp';
    $source = (string) ($options['_squareFile'] ?? '');
    if ($source === '' || !is_file($source)) {
        throw new \RuntimeException('缺少列表方图。');
    }
    if (!@copy($source, $square_path) || !is_file($square_path)) {
        throw new \RuntimeException('无法写入列表方图。');
    }
    eva_bind_square_thumbnail($attachment_id, $square_path, true);
}

function eva_list_thumb_meta(string $file): array
{
    return [
        'file' => basename($file),
        'width' => EVA_LIST_THUMB_PX,
        'height' => EVA_LIST_THUMB_PX,
        'mime-type' => 'image/webp',
    ];
}

function eva_bind_square_thumbnail(int $attachment_id, string $square_path, bool $is_list_square = false): void
{
    if ($attachment_id <= 0 || eva_is_protected_attachment($attachment_id) || !is_file($square_path)) {
        return;
    }
    $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($attachment_id) : [];
    if (!is_array($meta)) {
        $meta = [];
    }
    if (!isset($meta['sizes']) || !is_array($meta['sizes'])) {
        $meta['sizes'] = [];
    }
    $entry = eva_list_thumb_meta($square_path);
    $meta['sizes']['thumbnail'] = $entry;
    if ($is_list_square) {
        $meta['sizes']['dashen_sq'] = $entry;
        update_post_meta($attachment_id, '_dashen_eva_square', '1');
    }
    if (function_exists('wp_update_attachment_metadata')) {
        wp_update_attachment_metadata($attachment_id, $meta);
    }
}

function eva_keep_square_metadata($metadata, $attachment_id)
{
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0 || (string) get_post_meta($attachment_id, '_dashen_eva_square', true) !== '1') {
        return $metadata;
    }
    if (!is_array($metadata)) {
        $metadata = [];
    }
    if (!isset($metadata['sizes']) || !is_array($metadata['sizes'])) {
        $metadata['sizes'] = [];
    }
    $file = function_exists('get_attached_file') ? get_attached_file($attachment_id) : '';
    if (!is_string($file) || $file === '' || !is_file($file)) {
        return $metadata;
    }
    $dir = dirname($file);
    $base = pathinfo($file, PATHINFO_FILENAME);
    $square_path = $dir . DIRECTORY_SEPARATOR . $base . '-dashen-sq.webp';
    if (!is_file($square_path)) {
        return $metadata;
    }
    $entry = eva_list_thumb_meta($square_path);
    $metadata['sizes']['dashen_sq'] = $entry;
    $metadata['sizes']['thumbnail'] = $entry;
    return $metadata;
}

function eva_requeue_cover(int $post_id): void
{
    $retries = (int) get_post_meta($post_id, '_dashen_eva_retry', true);
    if ($retries >= 8) {
        error_log('dashen eva stopped retrying cover ' . $post_id);
        return;
    }
    update_post_meta($post_id, '_dashen_eva_retry', $retries + 1);
    $queue = get_option(EVA_QUEUE, []);
    if (!is_array($queue)) {
        $queue = [];
    }
    if (!in_array($post_id, array_map('intval', $queue), true)) {
        $queue[] = $post_id;
        update_option(EVA_QUEUE, array_values($queue), false);
    }
}

function eva_apply_cover(int $post_id, bool $replace): void
{
    $post = get_post($post_id);
    if (!$post || (string) $post->post_status !== 'publish') {
        return;
    }
    if (!in_array((string) $post->post_type, eva_content_types(), true)) {
        return;
    }
    $thumb_id = function_exists('get_post_thumbnail_id') ? (int) get_post_thumbnail_id($post_id) : 0;
    if ($thumb_id > 0 && eva_is_protected_attachment($thumb_id)) {
        return;
    }
    if (!$replace && $thumb_id > 0) {
        return;
    }
    $options = eva_article_options($post);
    if ($replace && $thumb_id > 0) {
        eva_overwrite_attachment($thumb_id, $options);
        $id = $thumb_id;
    } else {
        $id = eva_create_attachment($options, (int) $post_id, sanitize_title($post->post_title) . '-cover.webp');
    }
    if ($id <= 0) {
        throw new \RuntimeException('封面附件写入失败 ' . $post_id);
    }
    set_post_thumbnail($post_id, $id);
    delete_post_meta($post_id, '_dashen_eva_retry');
    eva_sync_link_site_icon($post_id);
    eva_rebuild_product_cards($post_id);
    eva_bust_thumb_lite($post_id);
    eva_emit_post_cache($post_id);
}

function eva_start_replace_all(): int
{
    $existing = get_option(EVA_QUEUE, []);
    if (is_array($existing) && $existing) {
        return count(array_map('intval', $existing));
    }
    global $wpdb;
    $types = "'" . implode("','", array_map('esc_sql', eva_content_types())) . "'";
    $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($types) ORDER BY ID ASC");
    $ids = array_values(array_map('intval', is_array($ids) ? $ids : []));
    update_option(EVA_QUEUE, $ids, false);
    if (!defined('DASHEN_EVA_INLINE') && $ids && !wp_next_scheduled(EVA_BATCH)) {
        wp_schedule_single_event(time() + 1, EVA_BATCH);
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
        }
    }
    return count($ids);
}

function eva_run_batch(): void
{
    $queue = get_option(EVA_QUEUE, []);
    if (!is_array($queue) || !$queue) {
        return;
    }
    $post_id = (int) $queue[0];
    if ($post_id <= 0) {
        array_shift($queue);
        update_option(EVA_QUEUE, array_values($queue), false);
        return;
    }
    try {
        eva_apply_cover($post_id, true);
        $queue = get_option(EVA_QUEUE, []);
        if (is_array($queue) && (int) ($queue[0] ?? 0) === $post_id) {
            array_shift($queue);
            update_option(EVA_QUEUE, array_values($queue), false);
        }
    } catch (\Throwable $error) {
        error_log('dashen eva batch ' . $post_id . ': ' . $error->getMessage());
        $queue = get_option(EVA_QUEUE, []);
        if (!is_array($queue)) {
            $queue = [];
        }
        if ((int) ($queue[0] ?? 0) === $post_id) {
            array_shift($queue);
        }
        update_option(EVA_QUEUE, array_values($queue), false);
        eva_requeue_cover($post_id);
    }
    $left = get_option(EVA_QUEUE, []);
    if (is_array($left) && $left && !defined('DASHEN_EVA_INLINE')) {
        wp_schedule_single_event(time() + 4, EVA_BATCH);
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
        }
    }
}

function eva_article_options(object $post): array
{
    $is_product = (string) $post->post_type === 'product';
    return [
        'format' => $is_product ? 'product' : 'article',
        'themeId' => 'mono',
        'content' => [
            'series' => eva_first_category($post),
            'issue' => '',
            'date' => mysql2date('Y.m.d', (string) ($post->post_date ?? ''), false) ?: gmdate('Y.m.d'),
            'title' => (string) $post->post_title,
            'subtitle' => eva_subtitle($post),
            'author' => 'AI最严厉的父亲',
            'handle' => '',
            'site' => 'dashen.wang',
            'mark' => '',
        ],
    ];
}

function eva_promo_options(string $headline, string $kicker): array
{
    return [
        'format' => 'promo',
        'themeId' => 'mono',
        'content' => [
            'series' => '',
            'issue' => '',
            'date' => '',
            'title' => $headline,
            'subtitle' => $kicker,
            'author' => '',
            'handle' => '',
            'site' => 'dashen.wang',
            'mark' => '',
        ],
    ];
}

function eva_fetch_cover_pair(array $options): array
{
    $main = eva_temp_file('dashen-eva-main');
    Eva\fetch_cover($options, $main);
    $square = '';
    $format = (string) ($options['format'] ?? 'article');
    if ($format !== 'product' && $format !== 'promo') {
        $square = eva_temp_file('dashen-eva-sq');
        $sq = $options;
        $sq['format'] = 'square';
        Eva\fetch_cover($sq, $square);
    }
    return [$main, $square];
}

function eva_first_category(object $post): string
{
    $taxes = get_object_taxonomies($post, 'objects');
    foreach ($taxes as $tax) {
        if (empty($tax->hierarchical)) {
            continue;
        }
        $terms = get_the_terms($post, $tax->name);
        if (!is_array($terms) || !$terms) {
            continue;
        }
        $name = trim((string) $terms[0]->name);
        if ($name !== '') {
            return $name;
        }
    }
    return '';
}

function eva_create_attachment(array $options, int $parent_id, string $filename): int
{
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    [$tmp, $square] = eva_fetch_cover_pair($options);
    try {
        $id = media_handle_sideload([
            'name' => $filename,
            'tmp_name' => $tmp,
            'error' => 0,
            'size' => filesize($tmp) ?: 0,
        ], $parent_id, null, [
            'post_title' => (string) ($options['content']['title'] ?? '封面'),
        ]);
        if (is_wp_error($id)) {
            throw new \RuntimeException($id->get_error_message());
        }
        $title = (string) ($options['content']['title'] ?? '');
        if ($title !== '') {
            update_post_meta((int) $id, '_wp_attachment_image_alt', sanitize_text_field($title));
        }
        if ($square !== '') {
            $options['_squareFile'] = $square;
            eva_write_square_sizes((int) $id, $options);
        } elseif ((string) ($options['format'] ?? '') === 'product') {
            $file = get_attached_file((int) $id);
            if (is_string($file) && is_file($file)) {
                eva_bind_square_thumbnail((int) $id, $file, false);
            }
        }
        if ($parent_id > 0) {
            eva_bust_thumb_lite($parent_id);
        }
        return (int) $id;
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
        if ($square !== '' && is_file($square)) {
            @unlink($square);
        }
    }
}

function eva_overwrite_attachment(int $attachment_id, array $options): void
{
    if (eva_is_protected_attachment($attachment_id)) {
        throw new \RuntimeException('跳过默认特色图、头像、登录图和会员封面。');
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $file = get_attached_file($attachment_id);
    if (!$file) {
        throw new \RuntimeException('推广图附件不存在：' . $attachment_id);
    }
    [$tmp, $square] = eva_fetch_cover_pair($options);
    try {
        if (!@copy($tmp, $file)) {
            throw new \RuntimeException('无法覆盖封面文件。');
        }
        $meta = wp_generate_attachment_metadata($attachment_id, $file);
        if ($meta) {
            wp_update_attachment_metadata($attachment_id, $meta);
        }
        $title = (string) ($options['content']['title'] ?? '');
        if ($title !== '') {
            wp_update_post(['ID' => $attachment_id, 'post_title' => $title]);
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($title));
        }
        if ($square !== '') {
            $options['_squareFile'] = $square;
            eva_write_square_sizes($attachment_id, $options);
        } elseif ((string) ($options['format'] ?? '') === 'product') {
            eva_bind_square_thumbnail($attachment_id, $file, false);
        }
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
        if ($square !== '' && is_file($square)) {
            @unlink($square);
        }
    }
}

function eva_replace_promo_attachments(): void
{
    foreach (EVA_PROMO_MEDIA as $id => $copy) {
        eva_overwrite_attachment((int) $id, eva_promo_options($copy['title'], $copy['subtitle']));
    }
}

function eva_admin_menu(): void
{
    add_management_page('大神封面', '大神封面', 'upload_files', 'dashen-eva-cover', __NAMESPACE__ . '\\eva_admin_page');
}

function eva_admin_page(): void
{
    if (!current_user_can('upload_files')) {
        return;
    }
    $notice = sanitize_text_field((string) ($_GET['dashen_eva'] ?? ''));
    $queued = (int) ($_GET['queued'] ?? 0);
    echo '<div class="wrap"><h1>大神封面</h1>';
    if ($notice === 'promo') {
        echo '<div class="notice notice-success"><p>推广图已写入媒体库。</p></div>';
    }
    if ($notice === 'promo-replaced') {
        echo '<div class="notice notice-success"><p>已用 eva.dashen.wang 覆盖附件 54 / 55 / 56。</p></div>';
    }
    if ($notice === 'queued') {
        echo '<div class="notice notice-success"><p>已排队替换 ' . (int) $queued . ' 篇内容封面。封面由 eva.dashen.wang HTTP API 生成。失败保留原图。不覆盖附件 50。</p></div>';
    }
    if ($notice === 'error') {
        echo '<div class="notice notice-error"><p>生成失败，请看服务器错误日志。原图未改。</p></div>';
    }
    echo '<p>文章、文档、链接发布后若没有封面，向 eva.dashen.wang 请求 1200×675 主图和 1200×1200 列表方图；商品请求 1200×1200。请求失败时保留原图，不回退本地字卡。头像、登录大图、会员个人封面和默认特色图附件 50 不会动。</p>';
    if (current_user_can('manage_options')) {
        echo '<h2>替换已发布内容封面</h2><p>给每篇已发布的文章/文档/商品/链接各做一张新封面。不改正文、作者。不碰附件 36 / 42 / 45 / 50 / 51 / 52。已有非保护特色图原地覆盖。</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dashen_eva_replace');
        echo '<input type="hidden" name="action" value="dashen_eva_replace"/>';
        echo '<input type="hidden" name="scope" value="covers"/>';
        submit_button('排队替换全部内容封面', 'secondary');
        echo '</form>';
        echo '<h2>替换推广图 54 / 55 / 56</h2><p>覆盖这三张 1280×320 附件文件，页面里已引用的地址保持不变。</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dashen_eva_replace');
        echo '<input type="hidden" name="action" value="dashen_eva_replace"/>';
        echo '<input type="hidden" name="scope" value="promo"/>';
        submit_button('替换三张推广图', 'secondary');
        echo '</form>';
    }
    echo '<h2>临时生成一张推广图</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('dashen_eva_promo');
    echo '<input type="hidden" name="action" value="dashen_eva_promo"/>';
    echo '<table class="form-table"><tr><th>主句</th><td><input class="regular-text" name="headline" required maxlength="40" placeholder="技能工坊"/></td></tr>';
    echo '<tr><th>副句（可空）</th><td><input class="regular-text" name="kicker" maxlength="20"/></td></tr></table>';
    submit_button('生成推广图');
    echo '</form></div>';
}

function eva_admin_promo(): void
{
    if (!current_user_can('upload_files') || !wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'dashen_eva_promo')) {
        wp_die('权限不足');
    }
    $headline = sanitize_text_field((string) ($_POST['headline'] ?? ''));
    $kicker = sanitize_text_field((string) ($_POST['kicker'] ?? ''));
    if ($headline === '') {
        wp_safe_redirect(admin_url('tools.php?page=dashen-eva-cover&dashen_eva=error'));
        exit;
    }
    try {
        eva_create_attachment(eva_promo_options($headline, $kicker), 0, sanitize_title($headline) . '-promo.webp');
        wp_safe_redirect(admin_url('tools.php?page=dashen-eva-cover&dashen_eva=promo'));
        exit;
    } catch (\Throwable $error) {
        error_log('dashen eva promo: ' . $error->getMessage());
        wp_safe_redirect(admin_url('tools.php?page=dashen-eva-cover&dashen_eva=error'));
        exit;
    }
}

function eva_admin_replace(): void
{
    if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'dashen_eva_replace')) {
        wp_die('权限不足');
    }
    $scope = sanitize_text_field((string) ($_POST['scope'] ?? ''));
    try {
        if ($scope === 'covers') {
            $n = eva_start_replace_all();
            wp_safe_redirect(admin_url('tools.php?page=dashen-eva-cover&dashen_eva=queued&queued=' . (int) $n));
            exit;
        }
        if ($scope === 'promo') {
            eva_replace_promo_attachments();
            wp_safe_redirect(admin_url('tools.php?page=dashen-eva-cover&dashen_eva=promo-replaced'));
            exit;
        }
    } catch (\Throwable $error) {
        error_log('dashen eva replace: ' . $error->getMessage());
    }
    wp_safe_redirect(admin_url('tools.php?page=dashen-eva-cover&dashen_eva=error'));
    exit;
}
