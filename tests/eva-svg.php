<?php
require __DIR__ . '/../wordpress/eva-svg.php';
require __DIR__ . '/../wordpress/eva-raster.php';

function check($condition, $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$article = \Dashen\SeoGeo\Eva\format('article');
check($article['width'] === 1200 && $article['height'] === 675, 'article size');
$promo = \Dashen\SeoGeo\Eva\format('promo');
check($promo['width'] === 1280 && $promo['height'] === 320, 'promo size');

$lines = \Dashen\SeoGeo\Eva\split_title('当一家公司薄到极致', 15, 3);
check(count($lines) >= 1 && count($lines) <= 3, 'title lines');

$svg = \Dashen\SeoGeo\Eva\build_svg([
    'format' => 'article',
    'content' => [
        'series' => '杂文',
        'date' => '2026.10.07',
        'title' => '当一家公司薄到极致',
        'subtitle' => '',
        'author' => 'AI最严厉的父亲',
        'handle' => '',
        'site' => 'dashen.wang',
    ],
]);
check(str_contains($svg, 'width="1200"'), 'svg width');
check(str_contains($svg, 'height="675"'), 'svg height');
check(str_contains($svg, '当一家公司'), 'svg title');
check(str_contains($svg, 'dashen.wang'), 'svg site');
check(!str_contains($svg, 'Vol.01'), 'no demo issue');
check(!str_contains($svg, '@dashen_wang'), 'no handle');

$promo_svg = \Dashen\SeoGeo\Eva\build_svg([
    'format' => 'promo',
    'content' => ['title' => '技能工坊', 'subtitle' => '自己的工具', 'site' => 'dashen.wang'],
]);
check(str_contains($promo_svg, 'width="1280"') && str_contains($promo_svg, 'height="320"'), 'promo svg');
check(str_contains($promo_svg, '技能工坊'), 'promo title');

$font = \Dashen\SeoGeo\Eva\title_font();
check(str_contains($font, 'Eva-Ming-SC-v0.1.otf'), 'packed Eva-Ming missing');
check(is_readable($font), 'Eva-Ming unreadable');

if (class_exists('Imagick')) {
    $dir = sys_get_temp_dir() . '/dashen-eva-' . bin2hex(random_bytes(3));
    mkdir($dir);
    $target = $dir . '/cover.webp';
    \Dashen\SeoGeo\Eva\raster_webp([
        'format' => 'article',
        'content' => [
            'series' => '杂文',
            'date' => '2026.10.07',
            'title' => '当一家公司薄到极致',
            'author' => 'AI最严厉的父亲',
            'site' => 'dashen.wang',
        ],
    ], $target);
    check(is_file($target), 'webp written');
    $probe = new Imagick($target);
    check($probe->getImageWidth() === 1200 && $probe->getImageHeight() === 675, 'webp size');
    check(strtoupper($probe->getImageFormat()) === 'WEBP', 'webp mime');
    $probe->clear();
    $promo_target = $dir . '/promo.webp';
    \Dashen\SeoGeo\Eva\raster_webp([
        'format' => 'promo',
        'content' => ['title' => '技能工坊', 'site' => 'dashen.wang'],
    ], $promo_target);
    $promo_probe = new Imagick($promo_target);
    check($promo_probe->getImageWidth() === 1280 && $promo_probe->getImageHeight() === 320, 'promo webp size');
    $promo_probe->clear();
    $bg = $dir . '/anime.png';
    $anime = new Imagick();
    $anime->newImage(800, 800, new ImagickPixel('#335577'), 'png');
    $anime->writeImage($bg);
    $anime->clear();
    $stacked = $dir . '/stacked.webp';
    \Dashen\SeoGeo\Eva\raster_webp([
        'format' => 'article',
        'backgroundOpacity' => 40,
        'backgroundFile' => $bg,
        'content' => [
            'title' => '当一家公司薄到极致',
            'author' => 'AI最严厉的父亲',
            'site' => 'dashen.wang',
        ],
    ], $stacked);
    $stack_probe = new Imagick($stacked);
    check($stack_probe->getImageWidth() === 1200 && $stack_probe->getImageHeight() === 675, 'stacked size');
    $stack_probe->clear();
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
    echo "PASS eva svg and raster sizes\n";
} else {
    echo "PASS eva svg (raster skipped, no Imagick)\n";
}
