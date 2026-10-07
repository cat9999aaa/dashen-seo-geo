<?php
namespace Dashen\SeoGeo\Eva;

/** EVA title-card SVG for Dashen covers. No WordPress. */

function formats(): array
{
    return [
        'article' => ['id' => 'article', 'width' => 1200, 'height' => 675],
        'promo' => ['id' => 'promo', 'width' => 1280, 'height' => 320],
    ];
}

function format(string $id): array
{
    $all = formats();
    return $all[$id] ?? $all['article'];
}

function theme_mono(): array
{
    return ['base' => '#080806', 'accent' => '#e9e4d8', 'signal' => '#b9d82e', 'alert' => '#d5472b'];
}

function crisp(float $value): float
{
    return round($value) + 0.5;
}

function token_units(string $token): float
{
    $units = 0.0;
    foreach (preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY) as $char) {
        if ($char === ' ') {
            $units += 0.34;
        } elseif (preg_match('/[\x{3400}-\x{9fff}\x{f900}-\x{faff}]/u', $char)) {
            $units += 1;
        } elseif (preg_match('/[A-Z]/', $char)) {
            $units += 0.78;
        } elseif (preg_match('/[a-z0-9]/', $char)) {
            $units += 0.64;
        } else {
            $units += 0.52;
        }
    }
    return $units;
}

function split_title(string $title, int $max_units, int $max_lines): array
{
    $paragraphs = array_values(array_filter(array_map('trim', preg_split("/\r?\n/", $title) ?: []), 'strlen'));
    if (!$paragraphs) {
        return [''];
    }
    $lines = [];
    foreach ($paragraphs as $paragraph) {
        preg_match_all('/[\x{3400}-\x{9fff}\x{f900}-\x{faff}]|[A-Za-z0-9]+(?:[-_.\/][A-Za-z0-9]+)*|\s+|./u', $paragraph, $tokens);
        $current = '';
        $current_units = 0.0;
        foreach ($tokens[0] as $token) {
            if (trim($token) === '') {
                if ($current !== '' && !str_ends_with($current, ' ')) {
                    $current .= ' ';
                }
                continue;
            }
            $pieces = token_units($token) > $max_units ? break_token($token, $max_units) : [$token];
            foreach ($pieces as $piece) {
                $piece_units = token_units($piece);
                $joiner = ($current !== '' && !str_ends_with($current, ' ') && preg_match('/^[A-Za-z0-9]/', $piece)) ? ' ' : '';
                $next = $current_units + token_units($joiner) + $piece_units;
                if ($current !== '' && $next > $max_units) {
                    $lines[] = trim($current);
                    $current = $piece;
                    $current_units = $piece_units;
                } else {
                    $current .= $joiner . $piece;
                    $current_units = $next;
                }
            }
        }
        if (trim($current) !== '') {
            $lines[] = trim($current);
        }
    }
    return array_slice($lines, 0, $max_lines) ?: [''];
}

function break_token(string $token, int $max_units): array
{
    $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY);
    $pieces = [];
    $current = '';
    $units = 0.0;
    foreach ($chars as $char) {
        $next = $units + token_units($char);
        if ($current !== '' && $next > $max_units) {
            $pieces[] = $current;
            $current = $char;
            $units = token_units($char);
            continue;
        }
        $current .= $char;
        $units = $next;
    }
    if ($current !== '') {
        $pieces[] = $current;
    }
    return $pieces;
}

function estimate_width(string $text, float $size): float
{
    $width = 0.0;
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
        if (preg_match('/[\x{3400}-\x{9fff}\x{f900}-\x{faff}]/u', $char)) {
            $width += $size;
        } elseif (preg_match('/[A-Z]/', $char)) {
            $width += $size * 0.73;
        } elseif (preg_match('/[a-z0-9]/', $char)) {
            $width += $size * 0.58;
        } elseif ($char === ' ') {
            $width += $size * 0.28;
        } else {
            $width += $size * 0.5;
        }
    }
    return $width;
}

function fit_size(string $text, float $size, float $max): float
{
    $width = estimate_width($text, $size);
    return $width > $max ? floor($size * ($max / $width) * 0.96) : $size;
}

function layout(array $format): array
{
    $w = (int) $format['width'];
    $h = (int) $format['height'];
    $ar = $w / $h;
    $wide = $ar >= 2;
    $extra_wide = $ar >= 2.2;
    $compact = $h < 500;
    $edge = crisp($w * ($extra_wide ? 0.038 : 0.05));
    $content = crisp($edge + $w * 0.05);
    if ($compact) {
        $title = (int) round($h * 0.228);
    } elseif ($extra_wide) {
        $title = (int) round($h * 0.148);
    } elseif ($h >= 960) {
        $title = (int) round($h * 0.136);
    } else {
        $title = (int) round($h * 0.128);
    }
    return [
        'width' => $w,
        'height' => $h,
        'edgeInset' => $edge,
        'contentInset' => $content,
        'railTop' => crisp($h * ($compact ? 0.158 : 0.148)),
        'railBottom' => crisp($h * ($compact ? 0.842 : 0.852)),
        'titleFontSize' => $title,
        'subtitleFontSize' => (int) round($title * 0.32),
        'labelFontSize' => max(16, (int) round($title * 0.185)),
        'cautionFontSize' => max(12, (int) round($title * 0.11)),
        'titleLetterSpacing' => $title >= 140 ? 6 : ($title >= 100 ? 4 : 3),
        'subtitleLetterSpacing' => 3,
        'maxTitleLines' => $compact ? 3 : 4,
        'maxLineUnits' => $compact ? 13 : ($extra_wide ? 18 : ($wide ? 16 : 15)),
    ];
}

function hex_alpha(string $hex, float $alpha): string
{
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return sprintf('rgba(%d,%d,%d,%.3f)', $r, $g, $b, $alpha);
}

function xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function ticker(int $width): string
{
    return str_repeat('DASHEN TITLE // 研究・判断・构建 // SYSTEM READY // ', (int) ceil($width / 360) + 2);
}

function card(array $options): array
{
    $format = format((string) ($options['format'] ?? 'article'));
    $theme = theme_mono();
    $layout = layout($format);
    $w = $layout['width'];
    $h = $layout['height'];
    $content = $options['content'] ?? [];
    $raw_title = trim((string) ($content['title'] ?? '')) ?: '—';
    $max_lines = min(3, (int) $layout['maxTitleLines']);
    $lines = split_title($raw_title, (int) $layout['maxLineUnits'], $max_lines);
    $longest = $lines[0] ?? '';
    foreach ($lines as $line) {
        if (estimate_width($line, $layout['titleFontSize']) > estimate_width($longest, $layout['titleFontSize'])) {
            $longest = $line;
        }
    }
    $penalty = count($lines) === 3 ? 0.78 : (count($lines) === 2 ? 0.9 : 1);
    $content_width = $w - $layout['contentInset'] * 2;
    $title_size = fit_size($longest, round($layout['titleFontSize'] * $penalty), $content_width);
    $line_height = (int) round($title_size * 1.12);
    $subtitle = trim((string) ($content['subtitle'] ?? ''));
    $subtitle_size = $subtitle !== ''
        ? fit_size($subtitle, round($title_size * 0.31), $content_width * 0.86)
        : round($title_size * 0.31);
    $center_y = ($layout['railTop'] + $layout['railBottom']) / 2;
    $subtitle_gap = $subtitle !== '' ? (int) round($subtitle_size * 1.65) : 0;
    $block = count($lines) * $line_height + $subtitle_gap;
    $first_y = $center_y - $block / 2 + $title_size * 0.78;
    return [
        'format' => $format,
        'theme' => $theme,
        'layout' => $layout,
        'content' => [
            'series' => trim((string) ($content['series'] ?? '')),
            'issue' => trim((string) ($content['issue'] ?? '')),
            'date' => trim((string) ($content['date'] ?? '')),
            'title' => $raw_title,
            'subtitle' => $subtitle,
            'author' => trim((string) ($content['author'] ?? '')),
            'handle' => trim((string) ($content['handle'] ?? '')),
            'site' => trim((string) ($content['site'] ?? '')),
        ],
        'lines' => $lines,
        'titleSize' => $title_size,
        'lineHeight' => $line_height,
        'subtitleSize' => $subtitle_size,
        'firstY' => $first_y,
        'subtitleY' => $first_y + (count($lines) - 1) * $line_height + $subtitle_gap,
        'seriesY' => ($layout['edgeInset'] + $layout['railTop']) / 2,
        'authorY' => ($layout['railBottom'] + $h - $layout['edgeInset']) / 2,
        'centerY' => $center_y,
        'backgroundUrl' => (string) ($options['backgroundUrl'] ?? ''),
        'backgroundOpacity' => (int) ($options['backgroundOpacity'] ?? 40),
    ];
}

function build_svg(array $options): string
{
    $card = card($options);
    $theme = $card['theme'];
    $layout = $card['layout'];
    $c = $card['content'];
    $w = $layout['width'];
    $h = $layout['height'];
    $ei = $layout['edgeInset'];
    $ci = $layout['contentInset'];
    $soft = hex_alpha($theme['accent'], 0.72);
    $faint = hex_alpha($theme['accent'], 0.18);
    $hair = hex_alpha($theme['accent'], 0.1);
    $outer = crisp($ei * 0.42);
    $cut = (int) round(min($w, $h) * 0.055);
    $opacity = max(0, min(100, $card['backgroundOpacity'])) / 100;
    $bg = $card['backgroundUrl'] !== ''
        ? '<image href="' . xml($card['backgroundUrl']) . '" x="0" y="0" width="' . $w . '" height="' . $h . '" preserveAspectRatio="xMidYMid slice" opacity="' . sprintf('%.2f', $opacity) . '"/>'
        : '';
    $title_spans = '';
    foreach ($card['lines'] as $i => $line) {
        $title_spans .= '<tspan x="' . ($w / 2) . '" y="' . ($card['firstY'] + $i * $card['lineHeight']) . '">' . xml($line) . '</tspan>';
    }
    $series = implode(' ／ ', array_filter([$c['series'], $c['issue']], 'strlen'));
    $brand = implode(' ／ ', array_filter([$c['handle'], $c['site']], 'strlen'));
    $subtitle = $c['subtitle'] !== ''
        ? '<text x="' . ($w / 2) . '" y="' . $card['subtitleY'] . '" class="minor" text-anchor="middle" font-size="' . $card['subtitleSize'] . '" letter-spacing="' . $layout['subtitleLetterSpacing'] . '">' . xml($c['subtitle']) . '</text>'
        : '';
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg">'
        . '<defs><clipPath id="dashen-cut"><polygon points="' . $outer . ',' . $outer . ' ' . ($w - $outer - $cut) . ',' . $outer . ' ' . ($w - $outer) . ',' . ($outer + $cut) . ' ' . ($w - $outer) . ',' . ($h - $outer) . ' ' . $outer . ',' . ($h - $outer) . '"/></clipPath>'
        . '<pattern id="dashen-grid" width="32" height="32" patternUnits="userSpaceOnUse"><path d="M32 0H0V32" fill="none" stroke="' . $hair . '" stroke-width="1"/></pattern>'
        . '<style>.title{font-family:\'Eva Ming SC\',\'Eva-Ming-SC\',serif;font-weight:400;fill:' . $theme['accent'] . ';text-anchor:middle}.label{font-family:ui-monospace,monospace;font-weight:700;fill:' . $theme['accent'] . '}.minor{font-family:ui-monospace,monospace;font-weight:600;fill:' . $soft . '}.ticker{font-family:ui-monospace,monospace;font-weight:700;fill:' . $faint . '}</style></defs>'
        . '<rect width="' . $w . '" height="' . $h . '" fill="' . $theme['base'] . '"/>' . $bg
        . '<rect x="' . $outer . '" y="' . $outer . '" width="' . ($w - $outer * 2) . '" height="' . ($h - $outer * 2) . '" fill="url(#dashen-grid)" opacity=".55" clip-path="url(#dashen-cut)"/>'
        . '<polygon points="' . $outer . ',' . $outer . ' ' . ($w - $outer - $cut) . ',' . $outer . ' ' . ($w - $outer) . ',' . ($outer + $cut) . ' ' . ($w - $outer) . ',' . ($h - $outer) . ' ' . $outer . ',' . ($h - $outer) . '" fill="none" stroke="' . $soft . '" stroke-width="1.5"/>'
        . '<line x1="' . $ei . '" y1="' . $layout['railTop'] . '" x2="' . ($w - $ei) . '" y2="' . $layout['railTop'] . '" stroke="' . $theme['accent'] . '" stroke-width="1.5"/>'
        . '<line x1="' . $ei . '" y1="' . $layout['railBottom'] . '" x2="' . ($w - $ei) . '" y2="' . $layout['railBottom'] . '" stroke="' . $theme['accent'] . '" stroke-width="1.5"/>'
        . '<text x="' . $ci . '" y="' . $layout['railTop'] . '" class="ticker" font-size="' . $layout['cautionFontSize'] . '">' . xml(ticker($w)) . '</text>'
        . '<text x="' . $ci . '" y="' . $card['seriesY'] . '" dominant-baseline="central" class="label" font-size="' . $layout['labelFontSize'] . '">' . xml($series) . '</text>'
        . '<text x="' . ($w - $ci) . '" y="' . $card['seriesY'] . '" dominant-baseline="central" text-anchor="end" class="minor" font-size="' . $layout['labelFontSize'] . '">' . xml($c['date']) . '</text>'
        . '<text class="title" font-size="' . $card['titleSize'] . '">' . $title_spans . '</text>' . $subtitle
        . '<text x="' . $ci . '" y="' . $card['authorY'] . '" dominant-baseline="central" class="label" font-size="' . $layout['labelFontSize'] . '">' . xml($c['author']) . '</text>'
        . '<text x="' . ($w - $ci) . '" y="' . $card['authorY'] . '" dominant-baseline="central" text-anchor="end" class="minor" font-size="' . $layout['labelFontSize'] . '">' . xml($brand) . '</text>'
        . '</svg>';
}
