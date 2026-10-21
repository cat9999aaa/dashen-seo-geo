<?php
namespace {
define('DASHEN_SEO_GEO_TEST', true);
class WP_Error {
    private string $message;
    public function __construct($code = '', $message = '') { $this->message = (string) $message; }
    public function get_error_message(): string { return $this->message; }
}
$GLOBALS['http'] = ['code' => 200, 'body' => 'RIFF....WEBP', 'error' => null];
$GLOBALS['last_http'] = null;
function wp_json_encode($data) { return json_encode($data); }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_remote_post($url, $args = []) {
    $GLOBALS['last_http'] = ['url' => $url, 'args' => $args];
    if (!empty($GLOBALS['http_queue']) && is_array($GLOBALS['http_queue'])) {
        $GLOBALS['http'] = array_shift($GLOBALS['http_queue']);
    }
    if (!empty($GLOBALS['http']['error'])) {
        return new WP_Error('http', (string) $GLOBALS['http']['error']);
    }
    return [
        'body' => $GLOBALS['http']['body'],
        'response' => ['code' => $GLOBALS['http']['code']],
        'headers' => $GLOBALS['http']['headers'] ?? [],
    ];
}
function wp_remote_retrieve_response_code($response): int {
    return (int) ($response['response']['code'] ?? 0);
}
function wp_remote_retrieve_body($response): string {
    return (string) ($response['body'] ?? '');
}
function wp_remote_retrieve_header($response, $header) {
    $headers = $response['headers'] ?? [];
    return $headers[$header] ?? $headers[strtolower((string) $header)] ?? '';
}
function check($condition, $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

putenv('EVA_API_TOKEN');
putenv('EVA_COVER_API_BASE');
require __DIR__ . '/../wordpress/eva-api.php';

check(\Dashen\SeoGeo\Eva\api_format_id('product') === 'square', 'product maps to square');
check(\Dashen\SeoGeo\Eva\api_format_id('article') === 'article', 'article stays article');
check(\Dashen\SeoGeo\Eva\api_format_id('promo') === 'promo', 'promo stays promo');
check(\Dashen\SeoGeo\Eva\api_base() === 'https://eva.dashen.wang/api/v1', 'default API base');

$payload = \Dashen\SeoGeo\Eva\cover_payload([
    'format' => 'product',
    'themeId' => 'mono',
    'content' => [
        'title' => '个人工作流诊断',
        'subtitle' => '带着现有工作流先确认范围与费用',
        'date' => '2026.10.07',
        'author' => 'AI最严厉的父亲',
        'site' => 'dashen.wang',
        'mark' => '',
    ],
]);
check($payload['formatId'] === 'square', 'payload formatId');
check($payload['output'] === 'webp', 'payload webp');
check($payload['quality'] === 80, 'payload quality');
check($payload['scale'] === 1, 'payload scale');
check($payload['content']['mark'] === '', 'payload mark empty');
try {
    \Dashen\SeoGeo\Eva\cover_payload(['content' => ['title' => '']]);
    check(false, 'empty title must throw');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), '标题为空'), 'empty title message');
}

$target = sys_get_temp_dir() . '/dashen-eva-api-' . bin2hex(random_bytes(4)) . '.webp';
try {
    \Dashen\SeoGeo\Eva\fetch_cover(['content' => ['title' => '测试']], $target);
    check(false, 'missing token must throw');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'EVA_API_TOKEN'), 'token error');
    check(!is_file($target), 'failed request must not write file');
}

putenv('EVA_API_TOKEN=test-token');
$GLOBALS['http'] = ['code' => 503, 'body' => '{"error":"busy"}', 'error' => null];
try {
    \Dashen\SeoGeo\Eva\fetch_cover(['content' => ['title' => '测试']], $target);
    check(false, 'HTTP error must throw');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'HTTP 503'), 'http status in error');
    check(!is_file($target), 'non-webp must not write file');
}

@unlink($target);
$GLOBALS['http_queue'] = [
    ['code' => 429, 'body' => '{"error":"rate"}', 'headers' => ['retry-after' => '1']],
    ['code' => 503, 'body' => '<!DOCTYPE html>'],
    ['code' => 200, 'body' => 'RIFF....WEBP'],
];
\Dashen\SeoGeo\Eva\fetch_cover(['format' => 'article', 'content' => ['title' => '测试']], $target);
check(is_file($target) && file_get_contents($target) === 'RIFF....WEBP', 'webp written after 429 retry');
check(str_ends_with((string) ($GLOBALS['last_http']['url'] ?? ''), '/covers'), 'covers endpoint');
$headers = $GLOBALS['last_http']['args']['headers'] ?? [];
check(($headers['Authorization'] ?? '') === 'Bearer test-token', 'bearer token');
check(str_starts_with((string) ($headers['Idempotency-Key'] ?? ''), 'wp-'), 'idempotency key');
@unlink($target);
echo "PASS eva HTTP cover client, product→square, fail closed without local raster\n";
}
