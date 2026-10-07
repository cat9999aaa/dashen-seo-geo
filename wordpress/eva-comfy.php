<?php
namespace Dashen\SeoGeo\Eva;

/** Optional local ComfyUI anime layer. Fail open to a text-only title card. */

function comfy_settings(): array
{
    $saved = get_option('dashen_eva_cover', []);
    if (!is_array($saved)) {
        $saved = [];
    }
    $enabled = array_key_exists('comfyui_enabled', $saved)
        ? !empty($saved['comfyui_enabled'])
        : true;
    return [
        'enabled' => $enabled,
        'url' => rtrim((string) ($saved['comfyui_url'] ?? 'http://127.0.0.1:8188'), '/'),
        'opacity' => max(10, min(60, (int) ($saved['background_opacity'] ?? 40))),
    ];
}

function anime_prompt(string $topic, string $shape = 'cover'): string
{
    $frame = $shape === 'promo'
        ? '宽幅横构图，电影宽银幕分镜'
        : '十六比九横构图，电影分镜';
    return '新世纪福音战士EVA风格赛璐璐动漫插画，冷色机械与有机混合，暗调，'
        . $frame
        . '，只有画面，不要任何文字、字母、数字、标志、水印、签名或字幕。主题：'
        . $topic;
}

function comfy_queue_busy($queue): bool
{
    if (!is_array($queue)) {
        return false;
    }
    $running = $queue['queue_running'] ?? [];
    $pending = $queue['queue_pending'] ?? [];
    return (is_array($running) && $running !== []) || (is_array($pending) && $pending !== []);
}

function comfy_lock()
{
    $dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : sys_get_temp_dir();
    $path = rtrim((string) $dir, '/') . '/dashen-eva-comfy.lock';
    $handle = fopen($path, 'c');
    if (!$handle) {
        return null;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return null;
    }
    return $handle;
}

function fetch_comfy_background(string $prompt, string $destination): bool
{
    $settings = comfy_settings();
    if (!$settings['enabled'] || $settings['url'] === '') {
        return false;
    }
    $lock = comfy_lock();
    if (!$lock) {
        error_log('dashen eva comfy lock failed');
        return false;
    }
    try {
        return fetch_comfy_background_locked($settings['url'], $prompt, $destination);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function fetch_comfy_background_locked(string $base, string $prompt, string $destination): bool
{
    $started = time();
    $reachable = false;
    while (true) {
        $queue = comfy_json($base . '/queue', null, 8);
        if ($queue) {
            $reachable = true;
            if (!comfy_queue_busy($queue)) {
                break;
            }
        } elseif (!$reachable && (time() - $started) > 60) {
            error_log('dashen eva comfy unreachable at ' . $base);
            return false;
        }
        sleep(3);
    }
    $graph = comfy_graph($prompt);
    $submitted = comfy_json($base . '/prompt', ['prompt' => $graph, 'client_id' => 'dashen-seo-geo'], 20);
    $id = (string) ($submitted['prompt_id'] ?? '');
    if ($id === '') {
        error_log('dashen eva comfy submit returned no prompt_id');
        return false;
    }
    $deadline = time() + 1800;
    $image = null;
    while (time() < $deadline) {
        $history = comfy_json($base . '/history/' . rawurlencode($id), null, 15);
        $entry = $history[$id] ?? null;
        if (is_array($entry)) {
            foreach (($entry['outputs'] ?? []) as $node) {
                $images = $node['images'] ?? [];
                if ($images) {
                    $image = $images[0];
                    break 2;
                }
            }
        }
        sleep(2);
    }
    if (!is_array($image) || empty($image['filename'])) {
        error_log('dashen eva comfy timed out waiting for ' . $id);
        return false;
    }
    $query = http_build_query([
        'filename' => $image['filename'],
        'subfolder' => $image['subfolder'] ?? '',
        'type' => $image['type'] ?? 'output',
    ]);
    $bytes = comfy_bytes($base . '/view?' . $query, 30);
    if ($bytes === '') {
        return false;
    }
    return file_put_contents($destination, $bytes) === strlen($bytes);
}

function comfy_graph(string $prompt): array
{
    $negative = '文字，字母，数字，标志，水印，签名，标题，字幕，用户界面，二维码，照片，写实，真人';
    return [
        '1' => ['class_type' => 'UnetLoaderGGUF', 'inputs' => ['unet_name' => 'qwen-image-2.1-UC-Q4_K_M.gguf']],
        '2' => ['class_type' => 'CLIPLoader', 'inputs' => ['clip_name' => 'qwen3vl_8b_int8_convrot.safetensors', 'type' => 'qwen_image', 'device' => 'default']],
        '3' => ['class_type' => 'VAELoader', 'inputs' => ['vae_name' => 'qwen_image_2.1_vae_bf16.safetensors']],
        '4' => ['class_type' => 'TextEncodeQwenImage21', 'inputs' => [
            'clip' => ['2', 0], 'vae' => ['3', 0], 'prompt' => $prompt, 'negative_prompt' => $negative, 'resolution' => 768,
        ]],
        '5' => ['class_type' => 'KSampler', 'inputs' => [
            'model' => ['1', 0], 'seed' => random_int(1, 999999), 'steps' => 20, 'cfg' => 1, 'sampler_name' => 'euler',
            'scheduler' => 'simple', 'positive' => ['4', 0], 'negative' => ['4', 1], 'latent_image' => ['4', 2], 'denoise' => 1,
        ]],
        '6' => ['class_type' => 'VAEDecode', 'inputs' => ['samples' => ['5', 0], 'vae' => ['3', 0]]],
        '7' => ['class_type' => 'SaveImage', 'inputs' => ['images' => ['6', 0], 'filename_prefix' => 'dashen-eva-bg']],
    ];
}

function comfy_json(string $url, ?array $payload, int $timeout): array
{
    $args = ['timeout' => $timeout, 'headers' => ['Accept' => 'application/json']];
    if ($payload !== null) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode($payload);
        $response = wp_remote_post($url, $args);
    } else {
        $response = wp_remote_get($url, $args);
    }
    if (is_wp_error($response)) {
        return [];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return [];
    }
    $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
    return is_array($decoded) ? $decoded : [];
}

function comfy_bytes(string $url, int $timeout): string
{
    $response = wp_remote_get($url, ['timeout' => $timeout]);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        return '';
    }
    return (string) wp_remote_retrieve_body($response);
}
