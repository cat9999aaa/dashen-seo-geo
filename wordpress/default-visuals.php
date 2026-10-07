<?php
namespace Dashen\SeoGeo;
/** Preserve uploaded pictures; replace only Guaqi's default seeds and stock covers. */
function apply_default_visuals($user_id): void {
    $defaults = get_option('dashen_default_visuals', []);
    foreach (['avatar', 'cover'] as $kind) {
        $value = (string) get_user_meta((int) $user_id, 'guaqi_' . $kind, true);
        $is_default = $value === '' || ($kind === 'avatar' && preg_match('/^[a-zA-Z]{10}$/D', $value))
            || ($kind === 'cover' && preg_match('~^/cover/\d+\.jpg$~D', $value));
        $id = (int) ($defaults[$kind] ?? 0);
        if ($is_default && $id && wp_attachment_is_image($id)) update_user_meta((int) $user_id, 'guaqi_' . $kind, $id);
    }
}
add_action('user_register', __NAMESPACE__ . '\\apply_default_visuals', 30);
add_action('graphql_user_object_mutation_update_additional_data', __NAMESPACE__ . '\\apply_default_visuals', 30);
function refresh_default_visuals($meta_id, $user_id, $meta_key, $value): void {
    if (in_array($meta_key, ['guaqi_avatar', 'guaqi_cover'], true)) apply_default_visuals($user_id);
}
add_action('added_user_meta', __NAMESPACE__ . '\\refresh_default_visuals', 30, 4);
add_action('updated_user_meta', __NAMESPACE__ . '\\refresh_default_visuals', 30, 4);
// Public default assets belong to the site, not each member. Translate their IDs
// into Guaqi's permitted reset input; native user/target capability checks remain.
add_filter('graphql_mutation_input', static function ($input, $context, $info, $name) {
    if ($name !== 'updateUser' || !is_array($input)) return $input;
    $defaults = get_option('dashen_default_visuals', []);
    if (!empty($defaults['cover']) && isset($input['cover']) && (string) $input['cover'] === (string) $defaults['cover']) $input['cover'] = '/cover/1.jpg';
    if (!empty($defaults['avatar']) && isset($input['avatar']) && (string) $input['avatar'] === (string) $defaults['avatar']) $input['avatar'] = 'DashenUser';
    return $input;
}, 10, 4);
