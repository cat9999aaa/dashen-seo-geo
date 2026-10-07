<?php
namespace GraphQL\Error {
    class UserError extends \RuntimeException {}
}
namespace {
// Isolated security contract. No WordPress, no network, no orders.
define('DASHEN_SEO_GEO_TEST', true);

$GLOBALS['hooks'] = [];
$GLOBALS['posts'] = [];
$GLOBALS['guaqi_current_user_data'] = ['id' => 0];
$GLOBALS['can_manage'] = false;
$GLOBALS['get_post_calls'] = 0;

function add_filter($name, $callback, $priority = 10, $accepted_args = 1): void
{
    $GLOBALS['hooks'][$name] = ['callback' => $callback, 'priority' => $priority, 'accepted_args' => $accepted_args];
}
function add_action($name, $callback, $priority = 10, $accepted_args = 1): void
{
    $GLOBALS['hooks'][$name] = ['callback' => $callback, 'priority' => $priority, 'accepted_args' => $accepted_args];
}
function get_post($id)
{
    $GLOBALS['get_post_calls']++;
    return $GLOBALS['posts'][(int) $id] ?? null;
}
function get_current_user_id(): int
{
    return (int) ($GLOBALS['guaqi_current_user_data']['id'] ?? 0);
}
function current_user_can(string $cap): bool
{
    return $cap === 'manage_options' && !empty($GLOBALS['can_manage']);
}
function check($condition, $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function row(int $id, string $status, string $type, int $author): object
{
    return (object) [
        'ID' => $id,
        'post_status' => $status,
        'post_type' => $type,
        'post_author' => $author,
        'post_title' => 'SECRET ' . $id,
        'post_content' => 'FULLTEXT ' . $id,
    ];
}

class WP_Post extends stdClass {}
    require __DIR__ . '/../wordpress/plugin.php';

    check(($GLOBALS['hooks']['graphql_pre_resolve_field']['accepted_args'] ?? 0) === 9, 'pre-resolve must take the sentinel and field identity');
    check(($GLOBALS['hooks']['graphql_request_results']['priority'] ?? 0) === 30, 'cache override must be after Meili priority 20');

    $GLOBALS['posts'] = [
        2 => row(2, 'trash', 'page', 1),
        3 => row(3, 'draft', 'page', 1),
        5 => row(5, 'auto-draft', 'post', 1),
        8 => row(8, 'publish', 'page', 1),
        9 => row(9, 'publish', 'post', 1),
        36 => row(36, 'inherit', 'attachment', 1),
        49 => row(49, 'publish', 'post', 1),
        138 => row(138, 'publish', 'docs', 1),
        209 => row(209, 'publish', 'dsh_skill', 1),
        290 => row(290, 'publish', 'community', 1),
        305 => row(305, 'publish', 'link', 1),
        311 => row(311, 'publish', 'product', 1),
        367 => row(367, 'trash', 'post', 4),
        472 => row(472, 'draft', 'page', 7),
        474 => row(474, 'pending', 'page', 7),
    ];

    $nil = new stdClass();
    $leaked = false;
    $resolver = static function () use (&$leaked) {
        $leaked = true;
        return ['title' => 'SECRET', 'description' => 'FULLTEXT', 'single' => ['databaseId' => 1], 'author' => ['name' => 'SECRET']];
    };

    function guard($nil, string $type, string $field, $args, $resolver)
    {
        return \Dashen\SeoGeo\guard_graphql_pre_resolve($nil, null, $args, null, null, $type, $field, null, $resolver);
    }

    function assert_page_404($nil, $args, $resolver, string $label): void
    {
        global $leaked;
        $leaked = false;
        \Dashen\SeoGeo\reset_draft_acl();
        try {
            guard($nil, 'gqPage', 'single', $args, $resolver);
            throw new RuntimeException($label . ' resolved');
        } catch (GraphQL\Error\UserError $error) {
            check($error->getMessage() === '404', $label . ' is not UserError 404');
        }
        check($leaked === false, $label . ' called the field resolver');
        check(\Dashen\SeoGeo\draft_acl_was_denied(), $label . ' did not flag the request');
    }

    $anonymous = ['id' => 0];
    $GLOBALS['guaqi_current_user_data'] = $anonymous;
    $GLOBALS['can_manage'] = false;

    \Dashen\SeoGeo\reset_draft_acl();
    try {
        guard($nil, 'GqPage', 'single', ['articleID' => 3], $resolver);
        throw new RuntimeException('WPGraphQL ucfirst type bypassed the guard');
    } catch (GraphQL\Error\UserError $error) {
        check($error->getMessage() === '404', 'canonical GraphQL type denies draft');
    }
    foreach ([2, 3, '3', 472, 474] as $id) {
        $GLOBALS['posts'][3]->post_status = 'draft';
        $GLOBALS['posts'][472]->post_status = 'draft';
        assert_page_404($nil, ['articleID' => $id], $resolver, 'unpublished page ' . $id);
    }
    foreach (['pending', 'private', 'future', 'auto-draft', 'trash', 'inherit', 'custom-hidden'] as $status) {
        $GLOBALS['posts'][3]->post_status = $status;
        assert_page_404($nil, ['articleID' => 3], $resolver, 'page status ' . $status);
    }
    $GLOBALS['posts'][3]->post_status = 'draft';
    foreach ([5, 9, 36, 49, 138, 209, 290, 305, 311, 367] as $id) {
        assert_page_404($nil, ['articleID' => $id], $resolver, 'non-page ' . $id);
    }

    \Dashen\SeoGeo\reset_draft_acl();
    $leaked = false;
    $published = guard($nil, 'gqPage', 'single', ['articleID' => 8], $resolver);
    check($published === $nil && $leaked === false && !\Dashen\SeoGeo\draft_acl_was_denied(), 'published page 8 must keep the native resolver and stay cacheable');

    $GLOBALS['guaqi_current_user_data'] = ['id' => 7];
    \Dashen\SeoGeo\reset_draft_acl();
    $author = guard($nil, 'gqPage', 'single', ['articleID' => 472, 'lang' => 'en'], $resolver);
    check($author === $nil && !\Dashen\SeoGeo\draft_acl_was_denied(), 'page author preview continues');
    $author_cache = \Dashen\SeoGeo\mark_denied_page_uncacheable(['extensions' => ['gqPageCache' => ['version' => 1, 'cacheable' => true]]]);
    check($author_cache['extensions']['gqPageCache']['cacheable'] === false, 'author preview must never enter public cache');
    assert_page_404($nil, ['articleID' => 367], $resolver, 'author of another type');

    $GLOBALS['guaqi_current_user_data'] = ['id' => 2];
    $GLOBALS['can_manage'] = true;
    \Dashen\SeoGeo\reset_draft_acl();
    $admin = guard($nil, 'gqPage', 'single', ['articleID' => 472], $resolver);
    check($admin === $nil, 'manage_options can preview a draft page');
    $admin_cache = \Dashen\SeoGeo\mark_denied_page_uncacheable(['extensions' => ['gqPageCache' => ['version' => 1, 'cacheable' => true]]]);
    check($admin_cache['extensions']['gqPageCache']['cacheable'] === false, 'admin preview must never enter public cache');
    assert_page_404($nil, ['articleID' => 49], $resolver, 'manage_options still cannot read a post through page.single');

    $GLOBALS['can_manage'] = false;
    $GLOBALS['guaqi_current_user_data'] = $anonymous;
    $calls = $GLOBALS['get_post_calls'];
    $other = guard($nil, 'gqArticle', 'single', ['articleID' => 367], $resolver);
    check($other === $nil && $GLOBALS['get_post_calls'] === $calls, 'gqArticle.single is not wrapped');

    $seo_args = [
        ['postType' => 'page', 'pageType' => 'single', 'id' => 3, 'lang' => 'zh-hans', 'fullPath' => '/page/3', 'authOnly' => false],
        ['postType' => 'page', 'pageType' => 'single', 'id' => 2, 'lang' => 'zh-hans', 'fullPath' => '/page/2', 'authOnly' => false],
        ['postType' => 'page', 'pageType' => 'single', 'id' => 472, 'lang' => 'en', 'fullPath' => '/en/page/472', 'authOnly' => false],
        ['postType' => 'page', 'pageType' => 'single', 'id' => 472, 'lang' => 'en', 'fullPath' => '/en/page/472', 'authOnly' => true],
        ['postType' => 'article', 'pageType' => 'single', 'id' => 367, 'lang' => 'zh-hans', 'fullPath' => '/article/367', 'authOnly' => false],
    ];
    foreach ($seo_args as $args) {
        \Dashen\SeoGeo\reset_draft_acl();
        $leaked = false;
        $seo = guard($nil, 'GuaqiObject', 'seo', $args, $resolver);
        $encoded = json_encode($seo);
        check(is_array($seo) && ($seo['statusCode'] ?? 0) === 404, 'SEO did not route 404 for ' . $args['id']);
        check($leaked === false, 'SEO resolver ran for ' . $args['id']);
        check(!isset($seo['title'], $seo['description'], $seo['single'], $seo['author']), 'SEO denial still has public fields');
        check(!str_contains((string) $encoded, 'SECRET') && !str_contains((string) $encoded, 'FULLTEXT'), 'SEO denial contains source text');
        check(\Dashen\SeoGeo\draft_acl_was_denied(), 'SEO denial did not flag cache');
    }

    \Dashen\SeoGeo\reset_draft_acl();
    $leaked = false;
    $public_seo = guard($nil, 'GuaqiObject', 'seo', [
        'postType' => 'page', 'pageType' => 'single', 'id' => 8, 'lang' => 'zh-hans', 'fullPath' => '/page/8', 'authOnly' => false,
    ], $resolver);
    check($public_seo === $nil && $leaked === false && !\Dashen\SeoGeo\draft_acl_was_denied(), 'published page SEO stays on the native resolver');
    $canonical = guard($nil, 'GuaqiObject', 'seo', [
        'postType' => 'page', 'pageType' => 'single', 'id' => 49, 'lang' => 'zh-hans', 'fullPath' => '/page/49', 'authOnly' => false,
    ], $resolver);
    check($canonical === $nil && !\Dashen\SeoGeo\draft_acl_was_denied(), 'published type mismatch keeps the native 301 path');

    $GLOBALS['guaqi_current_user_data'] = ['id' => 7];
    \Dashen\SeoGeo\reset_draft_acl();
    $author_seo = guard($nil, 'GuaqiObject', 'seo', [
        'postType' => 'page', 'pageType' => 'single', 'id' => 472, 'lang' => 'en', 'fullPath' => '/en/page/472', 'authOnly' => true,
    ], $resolver);
    check($author_seo === $nil, 'author SEO preview still reaches Seo::init');
    $seo_cache = \Dashen\SeoGeo\mark_denied_page_uncacheable(['extensions' => ['gqPageCache' => ['version' => 1, 'cacheable' => true]]]);
    check($seo_cache['extensions']['gqPageCache']['cacheable'] === false, 'SEO author preview must never enter public cache');

    $built = \Dashen\SeoGeo\guard_unpublished_seo_result([
        'title' => 'SECRET 367',
        'description' => 'FULLTEXT 367',
        'author' => ['name' => 'SECRET'],
        'single' => ['databaseId' => 367],
    ], ['id' => 367, 'postType' => 'article', 'pageType' => 'single']);
    check(($built['statusCode'] ?? 0) === 404 && !isset($built['title'], $built['single'], $built['author']), 'result backstop still strips a built payload');

    $GLOBALS['guaqi_current_user_data'] = $anonymous;
    \Dashen\SeoGeo\reset_draft_acl();
    $ordinary = \Dashen\SeoGeo\mark_denied_page_uncacheable([
        'data' => ['guaqi' => ['page' => ['single' => ['header' => ['title' => '关于大神']]]]],
        'extensions' => ['gqPageCache' => ['version' => 1, 'cacheable' => true]],
    ]);
    check($ordinary['extensions']['gqPageCache']['cacheable'] === true, 'public response must stay cacheable');

    assert_page_404($nil, ['articleID' => 367], $resolver, 'cache flag setup');
    $denied = \Dashen\SeoGeo\mark_denied_page_uncacheable([
        'data' => ['guaqi' => ['page' => ['single' => null]]],
        'errors' => [['message' => '404']],
        'extensions' => ['gqPageCache' => ['version' => 1, 'cacheable' => true]],
    ]);
    check($denied['extensions']['gqPageCache'] === ['version' => 1, 'cacheable' => false], 'denied request must override Meili cacheable true');
    check(!str_contains(json_encode($denied), 'FULLTEXT'), 'denied response has no body');

    echo "PASS page type gate, unpublished preview, SEO pre-resolve 404, cacheable override\n";
}
