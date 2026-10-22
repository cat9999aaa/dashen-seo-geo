<?php
namespace Dashen\SeoGeo;

if (!defined('ABSPATH') && !defined('DASHEN_SEO_GEO_TEST')) {
    return;
}

/** Add missing image alternatives at render time; never rewrite stored content. */
function rendered_content_image_alt($content)
{
    if (!is_string($content) || stripos($content, '<img') === false || !class_exists('WP_HTML_Tag_Processor')) {
        return $content;
    }
    $html = new \WP_HTML_Tag_Processor($content);
    while ($html->next_tag(['tag_name' => 'IMG'])) {
        // null means absent; preserve authored empty and boolean attributes too.
        if ($html->get_attribute('alt') !== null) {
            continue;
        }
        $alt = '';
        $class = $html->get_attribute('class');
        if (is_string($class) && preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class, $match)) {
            $stored = get_post_meta((int) $match[1], '_wp_attachment_image_alt', true);
            if (is_string($stored)) {
                $alt = wp_strip_all_tags($stored);
            }
        }
        if ($alt === '') {
            $title = $html->get_attribute('title');
            if (is_string($title)) {
                $alt = wp_strip_all_tags($title);
            }
        }
        $html->set_attribute('alt', $alt);
    }
    return $html->get_updated_html();
}

add_filter('the_content', __NAMESPACE__ . '\\rendered_content_image_alt', 20);
