<?php
namespace Dashen\SeoGeo;

/** Check records without changing authors, translations or visibility. */
function inspect_post_records(array $posts, callable $has_language, callable $has_author): array
{
    $issues = [];
    foreach ($posts as $post) {
        $id = (int) $post->ID;
        $problems = [];
        $author = (int) $post->post_author;
        if ($author <= 0 || !$has_author($author)) $problems[] = 'missing_author';
        if (!$has_language($id)) $problems[] = 'missing_language';
        if ($problems) {
            $issues[] = [
                'id' => $id,
                'title' => (string) $post->post_title,
                'problems' => $problems,
            ];
        }
    }
    return ['checked' => count($posts), 'issues' => $issues];
}

/** GuaQi MCP read-only audit for the currently assigned WordPress frontend. */
function audit_published_articles(array $args = []): array
{
    if (!class_exists('GUAQI\\Langs\\Action')) {
        throw new \RuntimeException('GuaQi language API is unavailable.');
    }
    $posts = get_posts([
        'post_type' => 'post',
        'post_status' => 'publish',
        'numberposts' => -1,
        'suppress_filters' => true,
    ]);
    return inspect_post_records(
        $posts,
        static function (int $id): bool {
            return (bool) \GUAQI\Langs\Action::get_lang_row(['id' => $id, 'type' => 'post_post']);
        },
        static function (int $id): bool { return (bool) get_user_by('id', $id); }
    );
}
