<?php
// Exercise the genuine WordPress HTML token processor with isolated WP helpers.
define('DASHEN_SEO_GEO_TEST', true);
$root = getenv('WP_TEST_ROOT') ?: '/mnt/workspace/客户A社群网站/site/wordpress';
function add_filter(...$args) {}
function get_post_meta($id, $key, $single) { return $id === 42 ? '作者 "大神" & <b>照片</b>' : ''; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function wp_has_noncharacters($value) { return false; }
function wp_kses_uri_attributes() { return []; }
function _doing_it_wrong(...$args) { throw new RuntimeException('WP processor misuse'); }
function __($value) { return $value; }
require $root . '/wp-includes/class-wp-token-map.php';
require $root . '/wp-includes/html-api/html5-named-character-references.php';
foreach (['attribute-token', 'text-replacement', 'span', 'token', 'doctype-info', 'decoder', 'tag-processor'] as $part) {
    require $root . '/wp-includes/html-api/class-wp-html-' . $part . '.php';
}
require __DIR__ . '/../wordpress/image-alt.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$unchanged = '<script>const fake = "<img src=x>";</script><style>.x:after{content:"<img>"}</style><script type="application/ld+json">{"x":"<img>"}</script><!-- <img> -->';
$input = $unchanged . '<img src="one>two.webp" class="wp-image-42"><img alt="" src=x><IMG ALT=original src=y><img title="安全 &quot;文字&quot; &amp; 描述"><img src="filename-not-a-description.webp">';
$output = \Dashen\SeoGeo\rendered_content_image_alt($input);
check(str_starts_with($output, $unchanged), 'Scripts, styles, JSON and comments must remain byte-identical');
check(str_contains($output, '<img alt="" src=x>'), 'Explicit empty alt preserved');
check(str_contains($output, '<IMG ALT=original src=y>'), 'Existing uppercase alt preserved');
$processor = new WP_HTML_Tag_Processor($output);
$alternatives = [];
while ($processor->next_tag('IMG')) { $alternatives[] = $processor->get_attribute('alt'); }
check($alternatives === ['作者 "大神" & 照片', '', 'original', '安全 "文字" & 描述', ''], 'Real attachment alt, authored title, safe escaping, no filename fiction');
check(\Dashen\SeoGeo\rendered_content_image_alt($output) === $output, 'Idempotent');
check(str_contains(\Dashen\SeoGeo\rendered_content_image_alt('<IMG SRC=x>'), 'alt=""'), 'Uppercase IMG is processed');
$actual = getenv('IMAGE_ALT_CAPTURE');
if ($actual) {
    $capture = file_get_contents($actual);
    $updated = \Dashen\SeoGeo\rendered_content_image_alt($capture);
    $p = new WP_HTML_Tag_Processor($updated);
    $count = 0;
    while ($p->next_tag('IMG')) {
        check($p->get_attribute('alt') !== null, 'Captured image still lacks alt');
        $count++;
    }
    check($count > 0, 'Capture contains images');
    echo "Capture tokenizer coverage: $count images. This is a content-filter unit check, not a global SSR hook.\n";
}
echo "PASS: WordPress token processor, existing alternatives, real media metadata, escaping, raw-text preservation, idempotence.\n";
