<?php
namespace Dashen\SeoGeo\Eva;

/**
 * HTTP client for eva.dashen.wang. Token lives in the environment, never in git.
 * Callers must not fall back to local Imagick if this request fails.
 */
function api_base(): string
{
    $from_env = getenv('EVA_COVER_API_BASE');
    if (is_string($from_env) && $from_env !== '') {
        return rtrim($from_env, '/');
    }
    if (defined('EVA_COVER_API_BASE') && EVA_COVER_API_BASE) {
        return rtrim((string) EVA_COVER_API_BASE, '/');
    }
    return 'https://eva.dashen.wang/api/v1';
}

function api_token(): string
{
    $token = getenv('EVA_API_TOKEN');
    if (is_string($token) && $token !== '') {
        return $token;
    }
    if (defined('EVA_API_TOKEN') && EVA_API_TOKEN) {
        return (string) EVA_API_TOKEN;
    }
    return '';
}

function api_format_id(string $format): string
{
    if ($format === 'product') {
        return 'square';
    }
    if ($format === 'promo' || $format === 'article' || $format === 'square') {
        return $format;
    }
    return 'article';
}

function cover_payload(array $options): array
{
    $content = is_array($options['content'] ?? null) ? $options['content'] : [];
    $title = trim((string) ($content['title'] ?? ''));
    if ($title === '') {
        throw new \RuntimeException('封面标题为空。');
    }
    return [
        'formatId' => api_format_id((string) ($options['format'] ?? 'article')),
        'themeId' => (string) ($options['themeId'] ?? 'mono'),
        'output' => 'webp',
        'quality' => 80,
        'scale' => 1,
        'locale' => 'zh-CN',
        'content' => [
            'series' => (string) ($content['series'] ?? ''),
            'issue' => (string) ($content['issue'] ?? ''),
            'date' => (string) ($content['date'] ?? ''),
            'title' => $title,
            'subtitle' => (string) ($content['subtitle'] ?? ''),
            'author' => (string) ($content['author'] ?? ''),
            'handle' => (string) ($content['handle'] ?? ''),
            'site' => (string) ($content['site'] ?? ''),
            'mark' => (string) ($content['mark'] ?? ''),
        ],
    ];
}

function fetch_cover(array $options, string $target): void
{
    $token = api_token();
    if ($token === '') {
        throw new \RuntimeException('缺少 EVA_API_TOKEN，无法向 eva.dashen.wang 请求封面。');
    }
    $payload = cover_payload($options);
    $url = api_base() . '/covers';
    $encoded = wp_json_encode($payload) ?: $payload['content']['title'];
    $key = 'wp-' . sha1($encoded);
    $attempts = 0;
    while (true) {
        $attempts++;
        $body = wp_remote_post($url, [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'image/webp',
                'Idempotency-Key' => $key,
            ],
            'body' => $encoded,
        ]);
        if (is_wp_error($body)) {
            throw new \RuntimeException('eva.dashen.wang 请求失败：' . $body->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($body);
        $bytes = (string) wp_remote_retrieve_body($body);
        if (($code === 429 || $code === 503) && $attempts < 6) {
            $wait = min(15, 2 ** $attempts);
            if ($code === 429 && function_exists('wp_remote_retrieve_header')) {
                $wait = max(1, min(15, (int) wp_remote_retrieve_header($body, 'retry-after') ?: $wait));
            }
            sleep($wait);
            continue;
        }
        if ($code < 200 || $code >= 300 || $bytes === '' || strncmp($bytes, 'RIFF', 4) !== 0) {
            throw new \RuntimeException('eva.dashen.wang 未返回 WebP（HTTP ' . $code . '）。');
        }
        if (file_put_contents($target, $bytes) === false) {
            throw new \RuntimeException('无法写入封面 WebP。');
        }
        return;
    }
}
