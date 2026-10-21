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
$product = \Dashen\SeoGeo\Eva\format('product');
check($product['width'] === 1200 && $product['height'] === 1200, 'product size');
$square = \Dashen\SeoGeo\Eva\format('square');
check($square['width'] === 1200 && $square['height'] === 1200, 'square size');

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
    $product_target = $dir . '/product.webp';
    \Dashen\SeoGeo\Eva\raster_webp([
        'format' => 'product',
        'content' => ['title' => '个人工作流诊断', 'subtitle' => '先确认范围与费用', 'site' => 'dashen.wang'],
    ], $product_target);
    $product_probe = new Imagick($product_target);
    check($product_probe->getImageWidth() === 1200 && $product_probe->getImageHeight() === 1200, 'product webp size');
    $product_probe->clear();
    $bg = $dir . '/anime.png';
    $anime = new Imagick();
    $anime->newImage(800, 800, new ImagickPixel('#335577'), 'png');
    $anime->writeImage($bg);
    $anime->clear();
    $stacked = $dir . '/stacked.webp';
    \Dashen\SeoGeo\Eva\raster_webp([
        'format' => 'article',
        'backgroundFile' => $bg,
        'content' => [
            'title' => '当一家公司薄到极致',
            'author' => 'AI最严厉的父亲',
            'site' => 'dashen.wang',
        ],
    ], $stacked);
    $stack_probe = new Imagick($stacked);
    check($stack_probe->getImageWidth() === 1200 && $stack_probe->getImageHeight() === 675, 'stacked size');
    $mid = $stack_probe->getImagePixelColor(2, 337)->getColor();
    check(abs(($mid['r'] ?? 0) - 51) < 12 && abs(($mid['g'] ?? 0) - 85) < 12, 'opaque background not 40% black mix');
    $stack_probe->clear();
    $sq_target = $dir . '/square.webp';
    \Dashen\SeoGeo\Eva\raster_list_square([
        'backgroundFile' => $bg,
        'content' => ['title' => '当一家公司薄到极致', 'subtitle' => '杂文', 'site' => 'dashen.wang'],
    ], $sq_target);
    $sq_probe = new Imagick($sq_target);
    check($sq_probe->getImageWidth() === 1200 && $sq_probe->getImageHeight() === 1200, 'list square size');
    $sq_probe->clear();
    $im = new Imagick();
    $im->newImage(1200, 675, new ImagickPixel('#080806'));
    $font = \Dashen\SeoGeo\Eva\title_font();
    $assert_fits = static function (Imagick $im, string $font, string $text, float $maxW, float $maxSize, float $minSize, string $label): float {
        $size = \Dashen\SeoGeo\Eva\fit_line($im, $font, $text, $maxW, $maxSize, $minSize);
        $draw = new ImagickDraw();
        $draw->setFont($font);
        $draw->setTextEncoding('UTF-8');
        $draw->setFontSize($size);
        $m = $im->queryFontMetrics($draw, $text);
        check(($m['textWidth'] ?? 9999) <= $maxW + 1, $label . ' overflows at ' . $size);
        return $size;
    };
    $short = $assert_fits($im, $font, '薄', 900, 87.75, 28.35, 'short title');
    $long = $assert_fits($im, $font, '当一家公司薄到极致之后还要把标题写得更长', 900, 87.75, 28.35, 'long title');
    check($long < $short, 'long title shrinks');
    $assert_fits($im, $font, str_repeat('标', 40), 936, 57.6, 4.0, '40 CJK');
    $assert_fits($im, $font, str_repeat('标', 80), 936, 57.6, 4.0, '80 CJK');
    $assert_fits($im, $font, str_repeat('标', 120), 936, 57.6, 4.0, '120 CJK');
    $assert_fits($im, $font, 'Supercalifragilisticexpialidocious-' . str_repeat('TitleWord', 12), 936, 57.6, 4.0, 'long English');
    $assert_fits($im, $font, '标题：AI/MCP（测试）—dashen.wang', 936, 57.6, 4.0, 'mixed punctuation');
    $too_long = str_repeat('标题', 500);
    $threw = false;
    try {
        \Dashen\SeoGeo\Eva\fit_line($im, $font, $too_long, 936, 57.6, 4.0);
    } catch (RuntimeException $error) {
        $threw = str_contains($error->getMessage(), '单行无法放入封面安全区');
    }
    check($threw, 'extreme title must fail closed');
    $im->clear();
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
    echo "PASS eva svg and raster sizes\n";
} else {
    echo "PASS eva svg (raster skipped, no Imagick)\n";
}
