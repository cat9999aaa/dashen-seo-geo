<?php
/**
 * 大神 Seo geo: image processing for the GuaQi runtime plugin loader.
 * Loaded only while this GuaQi package is assigned to a frontend.
 */

namespace Dashen\SeoGeo;

if (!defined('ABSPATH') && !defined('DASHEN_SEO_GEO_TEST')) {
    return;
}

require_once __DIR__ . '/seo-audit.php';
require_once __DIR__ . '/default-visuals.php';
require_once __DIR__ . '/draft-acl.php';
require_once __DIR__ . '/eva-cover.php';

add_filter('wp_handle_upload', __NAMESPACE__ . '\\convert_upload', 20, 2);
add_filter('wp_handle_sideload', __NAMESPACE__ . '\\convert_upload', 20, 2);
add_action('add_attachment', __NAMESPACE__ . '\\fill_image_alt');
if (class_exists('GUAQI\\Common\\Mcp\\Registry')) {
    \GUAQI\Common\Mcp\Registry::registerTool('dashen.seo_audit', [
        'description' => 'Read-only check of published articles for a valid author and GuaQi language relation, required by Markdown export and sitemap.',
        'capability' => 'edit_posts',
        'inputSchema' => ['properties' => []],
    ], __NAMESPACE__ . '\\audit_published_articles');
    \GUAQI\Common\Mcp\Registry::registerTool('dashen.upload_webp', [
        'description' => 'Upload one JPEG, PNG or WebP image as a WebP WordPress attachment without retaining the original.',
        'capability' => 'upload_files',
        'inputSchema' => [
            'properties' => [
                'filename' => ['type' => 'string'],
                'mimeType' => ['type' => 'string'],
                'base64' => ['type' => 'string'],
                'title' => ['type' => 'string'],
            ],
            'required' => ['filename', 'mimeType', 'base64'],
        ],
    ], __NAMESPACE__ . '\\upload_mcp_image');
}

/** Return the only persistent image file as WebP, or an upload error. */
function convert_upload(array $upload, string $context = 'upload'): array
{
    if (!empty($upload['error']) || empty($upload['file']) || empty($upload['type'])) {
        return $upload;
    }
    $type = (string) $upload['type'];
    if (strpos($type, 'image/') !== 0) {
        return $upload;
    }
    $source = (string) $upload['file'];
    if (!is_file($source)) {
        return failed_upload($upload, '上传的图片文件不存在。');
    }
    if ($type === 'image/webp') {
        $info = @getimagesize($source);
        if (!is_array($info) || ($info['mime'] ?? '') !== 'image/webp') {
            return failed_upload($upload, 'WebP 图片无效。');
        }
        return $upload;
    }
    if (!in_array($type, ['image/jpeg', 'image/png'], true)) {
        return failed_upload($upload, '当前只接受 JPEG、PNG 或 WebP 图片。');
    }

    $directory = dirname($source);
    $base = pathinfo($source, PATHINFO_FILENAME);
    $filename = wp_unique_filename($directory, $base . '.webp');
    $target = $directory . DIRECTORY_SEPARATOR . $filename;
    try {
        $temporary = $directory . DIRECTORY_SEPARATOR . '.dashen-' . bin2hex(random_bytes(12)) . '.webp';
        $editor = wp_get_image_editor($source);
        if (is_wp_error($editor)) {
            throw new \RuntimeException($editor->get_error_message());
        }
        $editor->set_quality(80);
        $saved = $editor->save($temporary, 'image/webp');
        if (is_wp_error($saved)) {
            throw new \RuntimeException($saved->get_error_message());
        }
        $actual = is_array($saved) ? ($saved['path'] ?? $temporary) : $temporary;
        if ($actual !== $temporary || !is_file($temporary)) {
            throw new \RuntimeException('WebP 编码文件不存在。');
        }
        $info = @getimagesize($temporary);
        if (!is_array($info) || ($info['mime'] ?? '') !== 'image/webp') {
            throw new \RuntimeException('WebP 编码结果无效。');
        }
        if (!@rename($temporary, $target)) {
            throw new \RuntimeException('无法保存 WebP 图片。');
        }
        if (!@unlink($source)) {
            @unlink($target);
            throw new \RuntimeException('无法删除原图片，上传已取消。');
        }
        $upload['file'] = $target;
        $upload['url'] = preg_replace('~[^/]+$~', rawurlencode($filename), (string) $upload['url']);
        $upload['type'] = 'image/webp';
        return $upload;
    } catch (\Throwable $error) {
        if (isset($temporary) && is_file($temporary)) @unlink($temporary);
        if (isset($target) && is_file($target)) @unlink($target);
        return failed_upload($upload, '图片转换为 WebP 失败：' . $error->getMessage());
    }
}

function failed_upload(array $upload, string $message): array
{
    if (!empty($upload['file']) && is_file($upload['file'])) {
        @unlink($upload['file']);
    }
    return ['error' => $message];
}

/** Fill a useful native alt only when no alt field was supplied. */
function fill_image_alt(int $attachment_id): void
{
    $post = get_post($attachment_id);
    if (!$post || strpos((string) $post->post_mime_type, 'image/') !== 0) return;
    if (metadata_exists('post', $attachment_id, '_wp_attachment_image_alt')) return;
    $title = trim((string) $post->post_title);
    if ($title === '' || preg_match('/^(?:img|dsc|dcim|pxl|image|screenshot|微信图片)[-_\s]*[0-9a-f_\-.]+$/iu', $title)) return;
    $alt = preg_replace('/[_-]+/u', ' ', $title);
    $alt = trim((string) preg_replace('/\s+/u', ' ', (string) $alt));
    if ($alt !== '') update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
}

/** Use the standard sideload path for Pi and other MCP clients. */
function upload_mcp_image(array $args): array
{
    $filename = sanitize_file_name((string) ($args['filename'] ?? ''));
    $mime = (string) ($args['mimeType'] ?? '');
    $encoded = (string) ($args['base64'] ?? '');
    if ($filename === '' || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new \InvalidArgumentException('只接受 JPEG、PNG 或 WebP 图片。');
    }
    if (strpos($encoded, 'base64,') !== false) {
        $encoded = substr($encoded, strpos($encoded, 'base64,') + 7);
    }
    if (strlen($encoded) > 14 * 1024 * 1024) {
        throw new \InvalidArgumentException('图片超过 10 MB 限制。');
    }
    $bytes = base64_decode($encoded, true);
    if ($bytes === false || $bytes === '' || strlen($bytes) > 10 * 1024 * 1024) {
        throw new \InvalidArgumentException('图片数据无效或超过 10 MB。');
    }
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info) || ($info['mime'] ?? '') !== $mime) {
        throw new \InvalidArgumentException('图片内容与 MIME 类型不一致。');
    }
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $temporary = wp_tempnam($filename);
    if (!$temporary || file_put_contents($temporary, $bytes) !== strlen($bytes)) {
        if ($temporary && is_file($temporary)) @unlink($temporary);
        throw new \RuntimeException('无法写入临时图片。');
    }
    try {
        $title = sanitize_text_field((string) ($args['title'] ?? ''));
        $post_data = $title !== '' ? ['post_title' => $title] : [];
        $id = media_handle_sideload([
            'name' => $filename,
            'tmp_name' => $temporary,
            'error' => 0,
            'size' => strlen($bytes),
        ], 0, null, $post_data);
        if (is_wp_error($id)) {
            throw new \RuntimeException($id->get_error_message());
        }
        return [
            'id' => (int) $id,
            'url' => (string) wp_get_attachment_url($id),
            'mimeType' => (string) get_post_mime_type($id),
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
        ];
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
}
