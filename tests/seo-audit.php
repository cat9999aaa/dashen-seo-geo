<?php
// Published article metadata must remain discoverable by GuaQi sitemap and Markdown.
define('DASHEN_SEO_GEO_TEST', true);
function add_filter($name, $callback, $priority = 10, $accepted_args = 1) {}
function add_action($name, $callback, $priority = 10, $accepted_args = 1) {}
require __DIR__ . '/../wordpress/plugin.php';

$posts = [
    (object) ['ID' => 9, 'post_author' => 3, 'post_title' => '正常文章'],
    (object) ['ID' => 10, 'post_author' => 0, 'post_title' => '缺作者与语言'],
    (object) ['ID' => 11, 'post_author' => 2, 'post_title' => '缺语言'],
];
$result = \Dashen\SeoGeo\inspect_post_records(
    $posts,
    static fn(int $id): bool => $id === 9,
    static fn(int $id): bool => in_array($id, [2, 3], true),
);
if (($result['checked'] ?? null) !== 3) throw new RuntimeException('wrong post count');
if (($result['issues'] ?? null) !== [
    ['id' => 10, 'title' => '缺作者与语言', 'problems' => ['missing_author', 'missing_language']],
    ['id' => 11, 'title' => '缺语言', 'problems' => ['missing_language']],
]) throw new RuntimeException('wrong audit issues: '.json_encode($result));
echo "PASS published article author and language audit\n";
