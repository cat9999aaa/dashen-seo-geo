<?php
namespace Dashen\SeoGeo\Eva;

/** Two independent layers, then overlay: EVA title card + optional anime. */

function packed_font_dir(): string
{
    return dirname(__DIR__) . '/assets/fonts';
}

function title_font(): string
{
    $otf = packed_font_dir() . '/Eva-Ming-SC-v0.1.otf';
    if (is_readable($otf)) {
        return $otf;
    }
    throw new \RuntimeException('缺少打包的 Eva-Ming 字体：' . $otf);
}

function label_font(): string
{
    foreach ([
        '/usr/share/fonts/TTF/DejaVuSansMono-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSansMono-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf',
        '/usr/share/fonts/noto/NotoSansMono-Bold.ttf',
    ] as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }
    return title_font();
}

function raster_webp(array $options, string $target): void
{
    if (!class_exists(\Imagick::class)) {
        throw new \RuntimeException('需要 Imagick 才能生成封面。');
    }
    $card = card($options);
    $theme = $card['theme'];
    $layout = $card['layout'];
    $w = (int) $layout['width'];
    $h = (int) $layout['height'];

    $canvas = new \Imagick();
    $canvas->newImage($w, $h, new \ImagickPixel($theme['base']));
    $canvas->setImageFormat('webp');

    $anime = raster_anime_layer((string) ($options['backgroundFile'] ?? ''), $w, $h);
    if ($anime) {
        apply_layer_opacity($anime, max(0, min(100, (int) $card['backgroundOpacity'])) / 100);
        $canvas->compositeImage($anime, \Imagick::COMPOSITE_OVER, 0, 0);
        $anime->clear();
    }

    $title = raster_title_layer($card);
    $canvas->compositeImage($title, \Imagick::COMPOSITE_OVER, 0, 0);
    $title->clear();

    $canvas->setImageCompressionQuality(82);
    $canvas->writeImage($target);
    $canvas->clear();
    if (!is_file($target)) {
        throw new \RuntimeException('无法写入封面 WebP。');
    }
}

/** Independent anime raster cropped to the cover size. */
function raster_anime_layer(string $path, int $width, int $height): ?\Imagick
{
    if ($path === '' || !is_readable($path)) {
        return null;
    }
    $bg = new \Imagick($path);
    $bg->cropThumbnailImage($width, $height);
    return $bg;
}

/** Independent EVA title card: chrome + Eva-Ming text on a transparent field. */
function raster_title_layer(array $card): \Imagick
{
    $theme = $card['theme'];
    $layout = $card['layout'];
    $w = (int) $layout['width'];
    $h = (int) $layout['height'];
    $layer = new \Imagick();
    $layer->newImage($w, $h, new \ImagickPixel('transparent'));
    $layer->setImageFormat('png');
    if (defined('\Imagick::ALPHACHANNEL_SET')) {
        $layer->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
    }

    $draw = new \ImagickDraw();
    $draw->setStrokeColor(new \ImagickPixel($theme['accent']));
    $draw->setFillColor(new \ImagickPixel('transparent'));
    $draw->setStrokeWidth(1.5);
    $ei = $layout['edgeInset'];
    $draw->line($ei, $layout['railTop'], $w - $ei, $layout['railTop']);
    $draw->line($ei, $layout['railBottom'], $w - $ei, $layout['railBottom']);
    $outer = crisp($ei * 0.42);
    $cut = (int) round(min($w, $h) * 0.055);
    $draw->setStrokeOpacity(0.72);
    $draw->polyline([
        ['x' => $outer, 'y' => $outer],
        ['x' => $w - $outer - $cut, 'y' => $outer],
        ['x' => $w - $outer, 'y' => $outer + $cut],
        ['x' => $w - $outer, 'y' => $h - $outer],
        ['x' => $outer, 'y' => $h - $outer],
        ['x' => $outer, 'y' => $outer],
    ]);
    $layer->drawImage($draw);

    $title_font = title_font();
    $mono = label_font();
    $c = $card['content'];
    $ci = $layout['contentInset'];
    annotate($layer, $c['series'], $ci, $card['seriesY'], $layout['labelFontSize'], $theme['accent'], $mono, 'left');
    annotate($layer, $c['date'], $w - $ci, $card['seriesY'], $layout['labelFontSize'], $theme['accent'], $mono, 'right');
    foreach ($card['lines'] as $i => $line) {
        annotate($layer, $line, $w / 2, $card['firstY'] + $i * $card['lineHeight'], $card['titleSize'], $theme['accent'], $title_font, 'center');
    }
    if ($c['subtitle'] !== '') {
        annotate($layer, $c['subtitle'], $w / 2, $card['subtitleY'], $card['subtitleSize'], $theme['accent'], $mono, 'center');
    }
    annotate($layer, $c['author'], $ci, $card['authorY'], $layout['labelFontSize'], $theme['accent'], $mono, 'left');
    $brand = implode(' ／ ', array_filter([$c['handle'], $c['site']], 'strlen'));
    annotate($layer, $brand, $w - $ci, $card['authorY'], $layout['labelFontSize'], $theme['accent'], $mono, 'right');
    return $layer;
}

function apply_layer_opacity(\Imagick $image, float $opacity): void
{
    if (defined('\Imagick::ALPHACHANNEL_SET')) {
        $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
    }
    try {
        $image->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $opacity, \Imagick::CHANNEL_ALPHA);
    } catch (\Throwable $ignored) {
        if (method_exists($image, 'setImageOpacity')) {
            $image->setImageOpacity($opacity);
        }
    }
}

function annotate(\Imagick $canvas, string $text, float $x, float $y, float $size, string $color, string $font, string $align): void
{
    $text = trim($text);
    if ($text === '') {
        return;
    }
    $draw = new \ImagickDraw();
    if ($font !== '') {
        $draw->setFont($font);
    }
    $draw->setTextEncoding('UTF-8');
    $draw->setFontSize($size);
    $draw->setFillColor(new \ImagickPixel($color));
    $draw->setGravity(\Imagick::GRAVITY_NORTHWEST);
    $metrics = $canvas->queryFontMetrics($draw, $text);
    $px = $x;
    if ($align === 'center') {
        $px = $x - ($metrics['textWidth'] ?? 0) / 2;
    } elseif ($align === 'right') {
        $px = $x - ($metrics['textWidth'] ?? 0);
    }
    $canvas->annotateImage($draw, $px, $y, 0, $text);
}
