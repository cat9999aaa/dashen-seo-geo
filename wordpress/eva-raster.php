<?php
namespace Dashen\SeoGeo\Eva;

/** Two independent layers, then overlay: EVA title card + optional anime. */

function packed_font_dir(): string
{
    $inside = __DIR__ . '/assets/fonts';
    if (is_readable($inside . '/Eva-Ming-SC-v0.1.otf')) {
        return $inside;
    }
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
    $format_id = (string) ($card['format']['id'] ?? 'article');

    $canvas = new \Imagick();
    $canvas->newImage($w, $h, new \ImagickPixel($theme['base']));
    $canvas->setImageFormat('webp');

    $bg_path = (string) ($options['backgroundFile'] ?? '');
    $bg = $format_id === 'product'
        ? contain_on_canvas($bg_path, $w, $h, $theme['base'])
        : cover_fill($bg_path, $w, $h);
    if ($bg) {
        $canvas->compositeImage($bg, \Imagick::COMPOSITE_OVER, 0, 0);
        $bg->clear();
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

/** Article list thumb: same chrome on a dedicated 1200×1200 canvas, not a 16:9 crop. */
function raster_list_square(array $options, string $target): void
{
    $options['format'] = 'square';
    raster_webp($options, $target);
}

function cover_fill(string $path, int $width, int $height): ?\Imagick
{
    if ($path === '' || !is_readable($path)) {
        return null;
    }
    $bg = new \Imagick($path);
    $bg->cropThumbnailImage($width, $height);
    return $bg;
}

function contain_on_canvas(string $path, int $width, int $height, string $canvasColor): ?\Imagick
{
    if ($path === '' || !is_readable($path)) {
        return null;
    }
    $src = new \Imagick($path);
    $src->thumbnailImage($width, $height, true);
    $canvas = new \Imagick();
    $canvas->newImage($width, $height, new \ImagickPixel($canvasColor));
    $x = (int) round(($width - $src->getImageWidth()) / 2);
    $y = (int) round(($height - $src->getImageHeight()) / 2);
    $canvas->compositeImage($src, \Imagick::COMPOSITE_OVER, $x, $y);
    $src->clear();
    return $canvas;
}

/** @deprecated use cover_fill */
function raster_anime_layer(string $path, int $width, int $height): ?\Imagick
{
    return cover_fill($path, $width, $height);
}

function fit_line(\Imagick $im, string $font, string $text, float $maxW, float $maxSize, float $minSize): float
{
    $text = trim($text);
    if ($text === '') {
        return $maxSize;
    }
    $floor = 4.0;
    $lo = $floor;
    $hi = max($maxSize, $floor);
    $best = $floor;
    for ($i = 0; $i < 20; $i++) {
        $mid = ($lo + $hi) / 2;
        $draw = new \ImagickDraw();
        $draw->setFont($font);
        $draw->setTextEncoding('UTF-8');
        $draw->setFontSize($mid);
        $m = $im->queryFontMetrics($draw, $text);
        if (($m['textWidth'] ?? 0) <= $maxW) {
            $best = $mid;
            $lo = $mid;
        } else {
            $hi = $mid;
        }
    }
    $draw = new \ImagickDraw();
    $draw->setFont($font);
    $draw->setTextEncoding('UTF-8');
    $draw->setFontSize($best);
    $final = $im->queryFontMetrics($draw, $text);
    if (($final['textWidth'] ?? 0) > $maxW + 1) {
        throw new \RuntimeException('标题过长，单行无法放入封面安全区。');
    }
    unset($minSize);
    return $best;
}

function hazard_band(int $w, int $h): \Imagick
{
    $im = new \Imagick();
    $im->newImage($w, $h, new \ImagickPixel('#111111'));
    $draw = new \ImagickDraw();
    $draw->setFillColor(new \ImagickPixel('#E6C200'));
    $period = max(16, (int) round($h * 1.7));
    $half = (int) round($period * 0.5);
    for ($x = -$h - $period; $x < $w + $h + $period; $x += $period) {
        $draw->polygon([
            ['x' => $x, 'y' => 0],
            ['x' => $x + $half, 'y' => 0],
            ['x' => $x + $half - $h, 'y' => $h],
            ['x' => $x - $h, 'y' => $h],
        ]);
    }
    $im->drawImage($draw);
    return $im;
}

/** annotate Y with GRAVITY_NORTHWEST is the glyph top, not the baseline. */
function stack_center(\Imagick $im, string $font, string $title, string $sub, float $maxW, float $titleMax, float $titleMin, float $areaTop, float $areaBot, float $gapRatio): array
{
    $titleSize = fit_line($im, $font, $title, $maxW, $titleMax, $titleMin);
    $titleH = $titleSize * 1.08;
    $subSize = 0.0;
    $subH = 0.0;
    if ($sub !== '') {
        $subSize = fit_line($im, $font, $sub, $maxW, $titleSize * 0.36, $titleMin * 0.5);
        $subH = $subSize * 1.08;
    }
    $gap = $sub !== '' ? max($titleSize * $gapRatio, 14) : 0;
    $block = $titleH + ($sub !== '' ? $gap + $subH : 0);
    $top = $areaTop + (($areaBot - $areaTop) - $block) / 2;
    return [
        'titleSize' => $titleSize,
        'subSize' => $subSize,
        'titleY' => $top,
        'subY' => $top + $titleH + $gap,
    ];
}

/** Independent EVA title card: hazard chrome + Eva-Ming on a transparent field. */
function raster_title_layer(array $card): \Imagick
{
    $theme = $card['theme'];
    $layout = $card['layout'];
    $w = (int) $layout['width'];
    $h = (int) $layout['height'];
    $format_id = (string) ($card['format']['id'] ?? 'article');
    $product = $format_id === 'product';
    $promo = $format_id === 'promo';
    $square = $format_id === 'square';
    $layer = new \Imagick();
    $layer->newImage($w, $h, new \ImagickPixel('transparent'));
    $layer->setImageFormat('png');
    if (defined('\Imagick::ALPHACHANNEL_SET')) {
        $layer->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
    }

    if ($product) {
        $band = max(14, (int) round($h * 0.042));
    } elseif ($promo) {
        $band = max(10, (int) round($h * 0.08));
    } elseif ($square) {
        $band = max(14, (int) round($h * 0.042));
    } else {
        $band = max(14, (int) round($h * 0.055));
    }
    $layer->compositeImage(hazard_band($w, $band), \Imagick::COMPOSITE_OVER, 0, 0);
    if (!$product) {
        $layer->compositeImage(hazard_band($w, $band), \Imagick::COMPOSITE_OVER, 0, $h - $band);
        $line = new \ImagickDraw();
        $line->setStrokeColor(new \ImagickPixel($theme['accent']));
        $line->setFillColor(new \ImagickPixel('transparent'));
        $line->setStrokeWidth(max(1, $h / max($h, 1)));
        $inset = $band + (int) round($h * ($promo ? 0.04 : 0.035));
        $line->rectangle($inset, $inset, $w - $inset, $h - $inset);
        $layer->drawImage($line);
        $maxW = $w - $inset * 2 - 16;
        $pad = (int) round($h * ($promo ? 0.02 : 0.04));
        $areaTop = $inset + $pad;
        $areaBot = $h - $inset - $pad;
        $gap = 0.40;
    } else {
        $maxW = (int) round($w * 0.78);
        $footH = (int) round($h * 0.07);
        $areaTop = $band + (int) round($h * 0.08);
        $areaBot = $h - $footH - (int) round($h * 0.06);
        $gap = 0.48;
        $inset = $band;
    }

    $c = $card['content'];
    $font = title_font();
    $title = (string) ($c['title'] ?? '');
    $sub = (string) ($c['subtitle'] ?? '');
    $title_min = $h * ($product ? 0.048 : 0.042);
    $s = stack_center($layer, $font, $title, $sub, $maxW, $h * ($product ? 0.125 : 0.13), $title_min, $areaTop, $areaBot, $gap);
    annotate($layer, $title, $w / 2, $s['titleY'], $s['titleSize'], $theme['accent'], $font, 'center');
    if ($sub !== '') {
        annotate($layer, $sub, $w / 2, $s['subY'], $s['subSize'], $theme['muted'] ?? $theme['accent'], $font, 'center');
    }
    if ($product) {
        $site = (string) ($c['site'] ?? 'dashen.wang');
        annotate($layer, $site, $w / 2, $h - $h * 0.045, max(11, $h * 0.022), $theme['muted'] ?? '#9a9a9a', label_font(), 'center');
    }
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
