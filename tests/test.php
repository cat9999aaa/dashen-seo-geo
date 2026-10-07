<?php
// Isolated WordPress contract test. Run with PHP + Imagick/WebP.
class WP_Error {
    private string $message;
    public function __construct(string $code, string $message) { $this->message = $message; }
    public function get_error_message(): string { return $this->message; }
}
$GLOBALS['hooks'] = [];
$GLOBALS['posts'] = [];
$GLOBALS['meta'] = [];
function add_filter($name, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['hooks'][$name] = $callback; }
function add_action($name, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['hooks'][$name] = $callback; }
function is_wp_error($x): bool { return $x instanceof WP_Error; }
function wp_unique_filename($dir, $name): string { $path = $name; $i = 1; while (file_exists($dir . '/' . $path)) $path = pathinfo($name, PATHINFO_FILENAME) . '-' . $i++ . '.' . pathinfo($name, PATHINFO_EXTENSION); return $path; }
function wp_get_image_editor($file) { return new TestEditor($file); }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_meta($id, $key, $single) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function metadata_exists($type, $id, $key) { return array_key_exists($key, $GLOBALS['meta'][$id] ?? []); }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; }
function sanitize_text_field($s) { return trim(strip_tags($s)); }
class TestEditor {
    private Imagick $image;
    private int $quality = 80;
    function __construct($file) { $this->image = new Imagick($file); }
    function set_quality($quality) { $this->quality = $quality; }
    function save($path, $mime = null) { $this->image->setImageFormat('webp'); $this->image->setImageCompressionQuality($this->quality); $this->image->writeImage($path); return ['path' => $path, 'mime-type' => 'image/webp']; }
}
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
define('DASHEN_SEO_GEO_TEST', true);
require __DIR__ . '/../wordpress/plugin.php';
check(isset($GLOBALS['hooks']['wp_handle_upload']), 'upload hook missing');
check(isset($GLOBALS['hooks']['wp_handle_sideload']), 'sideload hook missing');
check(isset($GLOBALS['hooks']['add_attachment']), 'attachment hook missing');
$dir = sys_get_temp_dir() . '/dashen-image-test-' . bin2hex(random_bytes(4));
mkdir($dir);
try {
    $src = $dir . '/good-name.png';
    $im = new Imagick(); $im->newImage(100, 100, new ImagickPixel('transparent'), 'png'); $im->writeImage($src);
    $converted = $GLOBALS['hooks']['wp_handle_upload'](['file' => $src, 'url' => 'https://example.test/good-name.png', 'type' => 'image/png'], 'upload');
    check(($converted['type'] ?? '') === 'image/webp', 'MIME not webp');
    check(str_ends_with($converted['file'] ?? '', '.webp'), 'path not webp');
    check(!file_exists($src), 'original retained');
    check(file_exists($converted['file']), 'webp missing');
    check((new Imagick($converted['file']))->getImageFormat() === 'WEBP', 'signature not webp');
    $sideloadSource = $dir . '/sideload.png';
    $im->writeImage($sideloadSource);
    $sideloaded = $GLOBALS['hooks']['wp_handle_sideload'](['file' => $sideloadSource, 'url' => 'https://example.test/sideload.png', 'type' => 'image/png'], 'sideload');
    check(($sideloaded['type'] ?? '') === 'image/webp', 'sideload MIME not webp');
    check(!file_exists($sideloadSource), 'sideload original retained');
    check(file_exists($sideloaded['file']), 'sideload WebP missing');
    $bad = $dir . '/broken.jpg'; file_put_contents($bad, 'not an image');
    $error = $GLOBALS['hooks']['wp_handle_upload'](['file' => $bad, 'url' => 'https://example.test/broken.jpg', 'type' => 'image/jpeg'], 'upload');
    check(!empty($error['error']), 'invalid image accepted');
    check(!file_exists($bad), 'failed original retained');
    $gif = $dir . '/anim.gif'; file_put_contents($gif, 'GIF89a');
    $rejected = $GLOBALS['hooks']['wp_handle_upload'](['file' => $gif, 'url' => 'https://example.test/anim.gif', 'type' => 'image/gif'], 'upload');
    check(!empty($rejected['error']) && !file_exists($gif), 'GIF not rejected and cleaned');
    $GLOBALS['posts'][1] = (object)['post_mime_type' => 'image/webp', 'post_title' => '企业 AI 转型路线图'];
    $GLOBALS['hooks']['add_attachment'](1);
    check(($GLOBALS['meta'][1]['_wp_attachment_image_alt'] ?? '') === '企业 AI 转型路线图', 'alt missing');
    $GLOBALS['posts'][2] = (object)['post_mime_type' => 'image/webp', 'post_title' => 'IMG_1234'];
    $GLOBALS['hooks']['add_attachment'](2);
    check(!isset($GLOBALS['meta'][2]['_wp_attachment_image_alt']), 'invented alt from camera filename');
    $GLOBALS['meta'][1]['_wp_attachment_image_alt'] = '人工描述';
    $GLOBALS['hooks']['add_attachment'](1);
    check($GLOBALS['meta'][1]['_wp_attachment_image_alt'] === '人工描述', 'overwrote manual alt');
    echo "PASS upload and sideload conversion, failure cleanup, unsupported format, alt preservation\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) @unlink($file);
    @rmdir($dir);
}
