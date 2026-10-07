<?php
namespace Dashen\SeoGeo;

/**
 * Close anonymous reads through gqPage.single and GuaqiObject.seo.
 *
 * WPGraphQL 2.23.1 graphql_pre_resolve_field receives a unique stdClass
 * sentinel. The field resolver runs only when this filter returns that same
 * object. Any other return value, including null, replaces the resolver.
 * Throwing UserError 404 also skips it. Denials and authorized unpublished previews set an uncacheable request flag.
 * graphql_request_results at priority 30 then sets
 * extensions.gqPageCache.cacheable to false. MeiliOptionalRead writes
 * cacheable=true at priority 20 for ordinary business 404s; those stay
 * cacheable when this flag is clear.
 *
 * gqPage.single has no lang argument. post_type other than page is 404,
 * including published posts, docs, products, attachments, and trash 367.
 * A page whose status is not publish is 404 unless the viewer is the author
 * or has manage_options. Statuses include draft, pending, private, future,
 * auto-draft, trash, inherit, and custom statuses.
 *
 * GuaqiObject.seo is a separate field (id, postType, pageType, lang,
 * fullPath, authOnly). For pageType=single, a non-publish id returns the
 * native route array {statusCode:404} before Seo::init, so title, description,
 * single, and author are never built. Published rows still use the native
 * resolver, including a type-mismatch 301.
 */

function reset_draft_acl(): void
{
    $GLOBALS['dashen_seo_geo_draft_denied'] = false;
    $GLOBALS['dashen_seo_geo_private_read'] = false;
}

function mark_draft_acl_denied(): void
{
    $GLOBALS['dashen_seo_geo_draft_denied'] = true;
    $GLOBALS['dashen_seo_geo_private_read'] = true;
}

function draft_acl_was_denied(): bool
{
    return !empty($GLOBALS['dashen_seo_geo_draft_denied']);
}

function draft_acl_viewer_id(): int
{
    $guaqi_id = (int) ($GLOBALS['guaqi_current_user_data']['id'] ?? 0);
    if ($guaqi_id > 0) {
        return $guaqi_id;
    }
    return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
}

function draft_acl_can_manage_options(): bool
{
    return function_exists('current_user_can') && current_user_can('manage_options');
}

function draft_acl_can_preview(object $post, int $viewer_id, bool $can_manage): bool
{
    $author_id = (int) ($post->post_author ?? 0);
    return ($viewer_id > 0 && $author_id > 0 && $viewer_id === $author_id) || $can_manage;
}

function draft_acl_not_found_status(): array
{
    return ['statusCode' => 404, 'messages' => ['Not Found']];
}

/** gqPage.single: non-page is always denied; unpublished page needs a preview cap. */
function draft_acl_should_deny_page_single(string $type_name, string $field_key, $args, $post, int $viewer_id, bool $can_manage): bool
{
    if (!in_array($type_name, ['gqPage', 'GqPage'], true) || $field_key !== 'single' || !is_array($args)) {
        return false;
    }
    if (!isset($args['articleID']) || $args['articleID'] === '' || $args['articleID'] === null || (string) $args['articleID'] === '0') {
        return false;
    }
    if (!is_object($post)) {
        return false;
    }
    if ((string) ($post->post_type ?? '') !== 'page') {
        return true;
    }
    if ((string) ($post->post_status ?? '') === 'publish') {
        return false;
    }
    return !draft_acl_can_preview($post, $viewer_id, $can_manage);
}

/**
 * SEO single of a non-publish row. Null means the native Seo::init must run.
 * The returned array is the route 404 and must be returned from pre-resolve.
 */
function draft_acl_seo_preresolve($args, int $viewer_id, bool $can_manage): ?array
{
    if (!is_array($args) || (string) ($args['pageType'] ?? '') !== 'single') {
        return null;
    }
    $id = (int) ($args['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $post = get_post($id);
    if (!is_object($post) || (string) ($post->post_status ?? '') === 'publish') {
        return null;
    }
    $GLOBALS['dashen_seo_geo_private_read'] = true;
    if (draft_acl_can_preview($post, $viewer_id, $can_manage)) {
        return null;
    }
    return draft_acl_not_found_status();
}

function guard_graphql_pre_resolve($nil, $source = null, $args = null, $context = null, $info = null, $type_name = '', $field_key = '', $field = null, $field_resolver = null)
{
    $type_name = (string) $type_name;
    $field_key = (string) $field_key;
    if ($type_name === 'GuaqiObject' && $field_key === 'seo') {
        $denial = draft_acl_seo_preresolve(is_array($args) ? $args : [], draft_acl_viewer_id(), draft_acl_can_manage_options());
        if ($denial !== null) {
            mark_draft_acl_denied();
            return $denial;
        }
        return $nil;
    }

    $post = null;
    if (in_array($type_name, ['gqPage', 'GqPage'], true) && $field_key === 'single' && is_array($args) && isset($args['articleID']) && $args['articleID'] !== '' && $args['articleID'] !== null && (string) $args['articleID'] !== '0') {
        $post = get_post($args['articleID']);
    }
    if (is_object($post) && (string) ($post->post_status ?? '') !== 'publish') {
        $GLOBALS['dashen_seo_geo_private_read'] = true;
    }
    if (draft_acl_should_deny_page_single($type_name, $field_key, $args, $post, draft_acl_viewer_id(), draft_acl_can_manage_options())) {
        mark_draft_acl_denied();
        if (class_exists('GraphQL\\Error\\UserError')) {
            throw new \GraphQL\Error\UserError('404');
        }
        throw new \RuntimeException('404');
    }
    return $nil;
}

/** Backstop if Seo::init already ran. Pre-resolve is what stops the resolver. */
function guard_unpublished_seo_result($result, $args = null)
{
    if (!is_array($result) || (int) ($result['statusCode'] ?? 0) === 404) {
        return $result;
    }
    $id = (int) ($result['single']['databaseId'] ?? 0);
    if ($id <= 0 && is_array($args)) {
        $id = (int) ($args['id'] ?? 0);
    }
    if ($id <= 0) {
        return $result;
    }
    $post = get_post($id);
    if (!is_object($post) || (string) ($post->post_status ?? '') === 'publish') {
        return $result;
    }
    $GLOBALS['dashen_seo_geo_private_read'] = true;
    if (draft_acl_can_preview($post, draft_acl_viewer_id(), draft_acl_can_manage_options())) {
        return $result;
    }
    mark_draft_acl_denied();
    return draft_acl_not_found_status();
}

/** Priority 30: never share unpublished previews or denials in public cache. */
function mark_denied_page_uncacheable($response)
{
    if (!draft_acl_was_denied() && empty($GLOBALS['dashen_seo_geo_private_read'])) {
        return $response;
    }
    $policy = ['version' => 1, 'cacheable' => false];
    if (is_array($response)) {
        if (!isset($response['extensions']) || !is_array($response['extensions'])) {
            $response['extensions'] = [];
        }
        $response['extensions']['gqPageCache'] = $policy;
        return $response;
    }
    if (is_object($response)) {
        if (!isset($response->extensions) || !is_array($response->extensions)) {
            $response->extensions = [];
        }
        $response->extensions['gqPageCache'] = $policy;
    }
    return $response;
}

add_action('graphql_before_execute', __NAMESPACE__ . '\\reset_draft_acl');
add_filter('graphql_pre_resolve_field', __NAMESPACE__ . '\\guard_graphql_pre_resolve', 10, 9);
add_filter('guaqi_seo_post_single', __NAMESPACE__ . '\\guard_unpublished_seo_result', 10, 2);
add_filter('guaqi_auth_page_seo', __NAMESPACE__ . '\\guard_unpublished_seo_result', 10, 2);
add_filter('graphql_request_results', __NAMESPACE__ . '\\mark_denied_page_uncacheable', 30, 1);
