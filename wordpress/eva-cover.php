<?php
namespace Dashen\SeoGeo;

require_once __DIR__ . '/eva-svg.php';
require_once __DIR__ . '/eva-raster.php';
require_once __DIR__ . '/eva-comfy.php';

const EVA_LOCK = '_dashen_eva_cover_lock';
const EVA_CRON = 'dashen_seo_geo_generate_cover';
const EVA_BATCH = 'dashen_seo_geo_cover_batch';
const EVA_QUEUE = 'dashen_eva_cover_queue';
const EVA_SKIP_MEDIA = [36, 42, 45, 51, 52];
const EVA_PROMO_MEDIA = [
    54 => ['title' => '自有 AI 转型', 'subtitle' => '自己的模型'],
    55 => ['title' => '教程与下载', 'subtitle' => '自己的工具'],
    56 => ['title' => 'AI Hub', 'subtitle' => 'Skill 与 MCP'],
];

function eva_content_types(): array
{
    return ['post', 'docs', 'product', 'link'];
}

add_action('transition_post_status', __NAMESPACE__ . '\\eva_on_publish', 20, 3);
add_action(EVA_CRON, __NAMESPACE__ . '\\eva_run_cover_job', 10, 1);
add_action(EVA_BATCH, __NAMESPACE__ . '\\eva_run_batch', 10, 0);
add_action('admin_menu', __NAMESPACE__ . '\\eva_admin_menu');
add_action('admin_post_dashen_eva_save', __NAMESPACE__ . '\\eva_admin_save');
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

function eva_apply_cover(int $post_id, bool $replace): void
{
    $post = get_post($post_id);
    if (!$post || (string) $post->post_status !== 'publish') {
        return;
    }
    if (!in_array((string) $post->post_type, eva_content_types(), true)) {
        return;
    }
    if (!$replace && has_post_thumbnail($post_id)) {
        return;
    }
    $id = eva_create_attachment(eva_article_options($post), (int) $post_id, sanitize_title($post->post_title) . '-cover.webp');
    if ($id) {
        set_post_thumbnail($post_id, $id);
    }
}

function eva_start_replace_all(): int
{
    $ids = get_posts([
        'post_type' => eva_content_types(),
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);
    $ids = array_values(array_map('intval', is_array($ids) ? $ids : []));
    update_option(EVA_QUEUE, $ids, false);
    if ($ids && !wp_next_scheduled(EVA_BATCH)) {
        wp_schedule_single_event(time() + 1, EVA_BATCH);
    }
    if (function_exists('spawn_cron')) {
        spawn_cron(time());
    }
    return count($ids);
}

function eva_run_batch(): void
{
    $queue = get_option(EVA_QUEUE, []);
    if (!is_array($queue) || !$queue) {
        return;
    }
    $post_id = (int) array_shift($queue);
    update_option(EVA_QUEUE, array_values($queue), false);
    try {
        eva_apply_cover($post_id, true);
    } catch (\Throwable $error) {
        error_log('dashen eva batch ' . $post_id . ': ' . $error->getMessage());
    }
    if ($queue) {
        wp_schedule_single_event(time() + 2, EVA_BATCH);
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
        }
    }
}

function eva_article_options(object $post): array
{
    $settings = Eva\comfy_settings();
    $options = [
        'format' => 'article',
        'backgroundOpacity' => $settings['opacity'],
        'content' => [
            'series' => eva_first_category($post),
            'issue' => '',
            'date' => mysql2date('Y.m.d', $post->post_date, false) ?: gmdate('Y.m.d'),
            'title' => (string) $post->post_title,
            'subtitle' => '',
            'author' => 'AI最严厉的父亲',
            'handle' => '',
            'site' => 'dashen.wang',
        ],
    ];
    $tmp = wp_tempnam('dashen-eva-bg');
    if ($tmp && Eva\fetch_comfy_background(Eva\anime_prompt((string) $post->post_title, 'cover'), $tmp)) {
        $options['backgroundFile'] = $tmp;
    } elseif ($tmp && is_file($tmp)) {
        @unlink($tmp);
    }
    return $options;
}

function eva_promo_options(string $headline, string $kicker): array
{
    $settings = Eva\comfy_settings();
    $options = [
        'format' => 'promo',
        'backgroundOpacity' => $settings['opacity'],
        'content' => [
            'series' => '',
            'issue' => '',
            'date' => '',
            'title' => $headline,
            'subtitle' => $kicker,
            'author' => '',
            'handle' => '',
            'site' => 'dashen.wang',
        ],
    ];
    $tmp = wp_tempnam('dashen-eva-promo-bg');
    if ($tmp && Eva\fetch_comfy_background(Eva\anime_prompt($headline, 'promo'), $tmp)) {
        $options['backgroundFile'] = $tmp;
    } elseif ($tmp && is_file($tmp)) {
        @unlink($tmp);
    }
    return $options;
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
    $tmp = wp_tempnam($filename);
    if (!$tmp) {
        throw new \RuntimeException('无法创建临时封面文件。');
    }
    try {
        Eva\raster_webp($options, $tmp);
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
        return (int) $id;
    } finally {
        if (!empty($options['backgroundFile']) && is_file($options['backgroundFile'])) {
            @unlink($options['backgroundFile']);
        }
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

function eva_overwrite_attachment(int $attachment_id, array $options): void
{
    if (in_array($attachment_id, EVA_SKIP_MEDIA, true)) {
        throw new \RuntimeException('跳过头像、登录图和会员封面。');
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $file = get_attached_file($attachment_id);
    if (!$file) {
        throw new \RuntimeException('推广图附件不存在：' . $attachment_id);
    }
    $tmp = wp_tempnam(basename($file));
    if (!$tmp) {
        throw new \RuntimeException('无法创建临时推广图。');
    }
    try {
        Eva\raster_webp($options, $tmp);
        if (!@copy($tmp, $file)) {
            throw new \RuntimeException('无法覆盖推广图文件。');
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
    } finally {
        if (!empty($options['backgroundFile']) && is_file($options['backgroundFile'])) {
            @unlink($options['backgroundFile']);
        }
        if (is_file($tmp)) {
            @unlink($tmp);
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
    $settings = Eva\comfy_settings();
    $notice = sanitize_text_field((string) ($_GET['dashen_eva'] ?? ''));
    $queued = (int) ($_GET['queued'] ?? 0);
    echo '<div class="wrap"><h1>大神封面</h1>';
    if ($notice === 'saved') {
        echo '<div class="notice notice-success"><p>设置已保存。</p></div>';
    }
    if ($notice === 'promo') {
        echo '<div class="notice notice-success"><p>推广图已写入媒体库。</p></div>';
    }
    if ($notice === 'promo-replaced') {
        echo '<div class="notice notice-success"><p>已按两套叠加替换附件 54 / 55 / 56。</p></div>';
    }
    if ($notice === 'queued') {
        echo '<div class="notice notice-success"><p>已排队替换 ' . (int) $queued . ' 篇内容封面。本机 ComfyUI 一次一张，队列忙时该张退回纯字卡。</p></div>';
    }
    if ($notice === 'error') {
        echo '<div class="notice notice-error"><p>生成失败，请看服务器错误日志。</p></div>';
    }
    echo '<p>文章、文档、商品、链接在<strong>发布后</strong>若没有封面，会自动生成 1200×675 标题图。头像、登录大图、会员个人封面不会动。</p>';
    echo '<p>封面是两套独立产物再叠加：Eva-Ming 字卡一层，EVA 风格动漫一层。插件只做合成。</p>';
    echo '<h2>本机 ComfyUI 动漫层</h2><p>默认开启。关掉则只出字卡。队列忙或失败时仍出纯字封面。</p>';
    if (current_user_can('manage_options')) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dashen_eva_save');
        echo '<input type="hidden" name="action" value="dashen_eva_save"/>';
        echo '<table class="form-table"><tr><th>启用 ComfyUI</th><td><label><input type="checkbox" name="comfyui_enabled" value="1"' . checked($settings['enabled'], true, false) . '/> 用本机 ComfyUI 生成动漫层</label></td></tr>';
        echo '<tr><th>地址</th><td><input class="regular-text" name="comfyui_url" value="' . esc_attr($settings['url']) . '" placeholder="http://127.0.0.1:8188"/></td></tr>';
        echo '<tr><th>动漫层不透明度</th><td><input type="number" min="10" max="60" name="background_opacity" value="' . (int) $settings['opacity'] . '"/> %（默认 40）</td></tr></table>';
        submit_button('保存设置');
        echo '</form>';
        echo '<h2>替换已发布内容封面</h2><p>给每篇已发布的文章/文档/商品/链接各做一张新封面。不改正文、作者。不碰附件 36 / 42 / 45 / 51 / 52。</p>';
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
    } else {
        echo '<p>当前 ComfyUI：' . ($settings['enabled'] ? '已开' : '关闭') . '。</p>';
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

function eva_admin_save(): void
{
    if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'dashen_eva_save')) {
        wp_die('权限不足');
    }
    update_option('dashen_eva_cover', [
        'comfyui_enabled' => !empty($_POST['comfyui_enabled']),
        'comfyui_url' => esc_url_raw((string) ($_POST['comfyui_url'] ?? 'http://127.0.0.1:8188')),
        'background_opacity' => max(10, min(60, (int) ($_POST['background_opacity'] ?? 40))),
    ], false);
    wp_safe_redirect(admin_url('tools.php?page=dashen-eva-cover&dashen_eva=saved'));
    exit;
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
