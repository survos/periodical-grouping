<?php

declare(strict_types=1);

namespace Survos\PeriodicalGrouping;

/** Pure layout analysis; all geometry is in original page pixels. */
final class LayoutAnalyzer
{
    public static function analyze(array $blocks, float $pageWidth, string $issueId, int $pageIndex, array $review = [], ?float $measuredBottom = null, ?float $pageHeight = null): array
    {
        $body = array_values(array_filter($blocks, static fn($b) => mb_strlen($b['text']) > 100 && $b['box'][3] > 150));
        $width = self::median(array_column(array_column($body, 'box'), 2)) ?: $pageWidth;
        $clusters = []; $fonts = [];
        foreach ($blocks as $b) {
            if (isset($b['fontSize'])) { $fonts[(string) $b['fontSize']] = ($fonts[(string) $b['fontSize']] ?? 0) + mb_strlen($b['text']); }
        }
        arsort($fonts); $bodyFont = $fonts ? (float) array_key_first($fonts) : null;
        usort($body, static fn($a, $b) => $a['box'][0] <=> $b['box'][0]);
        foreach ($body as $b) {
            if ($b['box'][2] < $width*.65 || $b['box'][2] > $width*1.35) { continue; }
            $center = $b['box'][0] + $b['box'][2]/2; $found = false;
            foreach ($clusters as &$cluster) {
                if (abs(self::median($cluster) - $center) < $width*.4) { $cluster[] = $center; $found = true; break; }
            }
            unset($cluster);
            if (!$found) { $clusters[] = [$center]; }
        }
        $centers = array_map(self::median(...), $clusters); sort($centers);
        if ($centers === []) { $centers = [$pageWidth/2]; }
        $reviewed = $pageIndex === 0 && $review !== [];
        $program = $reviewed ? ($review['programBlockIds'] ?? []) : [];
        $masthead = $reviewed ? ($review['mastheadBlockIds'] ?? array_column(array_filter($blocks, static fn($b) => $b['box'][1] < ($review['mastheadBottom'] ?? 0)), 'id')) : [];
        $masthead = array_values(array_intersect($masthead, array_column($blocks, 'id')));
        $mastheadStatus = $pageIndex === 0 ? ($reviewed ? 'reviewed' : 'not yet located') : 'not applicable';
        $mastheadRegion = $reviewed && isset($review['mastheadBottom']) ? [0, 0, $pageWidth, $review['mastheadBottom']] : null;
        if ($pageIndex === 0 && !$reviewed && $bodyFont !== null) {
            // A wide date strip below oversized title text is a masthead signal;
            // a big headline alone is not. Retain the inferred status for review.
            foreach ($blocks as $date) {
                if ($date['box'][1] > $pageWidth*.3 || $date['box'][2] < $pageWidth*.45
                    || !preg_match('/\b(?:17|18|19|20)\d{2}\b/u', $date['text'])
                    || !preg_match('/\b(?:JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)/iu', $date['text'])) { continue; }
                $title = array_filter($blocks, static fn($b) => ($b['fontSize'] ?? 0) >= $bodyFont*3
                    && $b['box'][2] > $pageWidth*.15 && $b['box'][1]+$b['box'][3] <= $date['box'][1]
                    && preg_match('/\p{L}{4}/u', $b['text']));
                $publisher = array_filter($blocks, static fn($b) => $b['box'][2] > $pageWidth*.2
                    && $b['box'][1]+$b['box'][3] <= $date['box'][1]
                    && $date['box'][1]-($b['box'][1]+$b['box'][3]) < $pageWidth*.05
                    && preg_match('/\beditor\b/iu', $b['text']) && preg_match('/\bpublisher\b/iu', $b['text']));
                if (!$title && !$publisher) { continue; }
                $bottom = $date['box'][1]+$date['box'][3];
                $masthead = array_column(array_filter($blocks, static fn($b) => $b['box'][1]+$b['box'][3] <= $bottom), 'id');
                $mastheadRegion = [0, 0, $pageWidth, $bottom];
                $mastheadStatus = $title ? 'inferred: oversized title above wide date strip' : 'inferred: publisher line above wide date strip';
                break;
            }
        }
        if ($pageIndex === 0 && !$reviewed && $mastheadStatus === 'not yet located' && $pageHeight !== null) {
            $bottom = self::alignedColumnTop($blocks, $body, $centers, $width, $pageHeight);
            if ($bottom !== null) {
                $masthead = array_column(array_filter($blocks, static fn($b) => $b['box'][1]+$b['box'][3] <= $bottom), 'id');
                if ($masthead !== []) {
                    $mastheadRegion = [0, 0, $pageWidth, $bottom];
                    $mastheadStatus = 'inferred: aligned column tops';
                }
            }
        }
        if ($pageIndex === 0 && !$reviewed && $mastheadStatus === 'not yet located' && $measuredBottom !== null) {
            // No hand review and no text-based inference: take the masthead harvest measured from the OCR geometry.
            // The measure finds the title's type; the nameplate usually continues below it (tagline, date strip), often as one
            // wide block, so a block spanning most of the page width that starts inside the masthead belongs to it.
            $wide = array_filter($blocks, static fn($b) => $b['box'][1] < $measuredBottom && $b['box'][2] >= $pageWidth*.7
                && $pageHeight !== null && $b['box'][1]+$b['box'][3] <= $pageHeight*.22);
            $measuredBottom = max([$measuredBottom, ...array_map(static fn($b) => $b['box'][1]+$b['box'][3], $wide)]);
            $mastheadRegion = [0, 0, $pageWidth, $measuredBottom];
            $masthead = array_column(array_filter($blocks, static fn($b) => $b['box'][1] < $measuredBottom && $b['box'][1]+$b['box'][3] <= $measuredBottom*1.02), 'id');
            $mastheadStatus = 'measured: harvest layout stage';
        }
        $slots = [];
        foreach ($blocks as $b) {
            $center = $b['box'][0]+$b['box'][2]/2;
            $distances = array_map(static fn($c) => abs($c-$center), $centers);
            $column = array_search(min($distances), $distances, true);
            $slots[$b['id']] = ['column' => $column, 'span' => in_array($b['id'], $program, true) ? 2 : 1];
        }
        $sizes = array_map('floatval', array_keys($fonts)); sort($sizes);
        return ['columnCount' => count($centers), 'columnCenters' => $centers, 'columnWidth' => $width,
            'bodyFontSize' => $bodyFont, 'displayFontSizes' => array_values(array_filter($sizes, static fn($n) => $bodyFont !== null && $n >= $bodyFont*1.5)),
            'fontSizeBasis' => 'stored OCR sizes; display sizes are candidates, not verified headlines',
            'columnBasis' => $reviewed ? $review['basis'] : 'cached layout estimate; unreviewed',
            'mastheadBlockIds' => array_values($masthead), 'mastheadStatus' => $mastheadStatus, 'mastheadRegion' => $mastheadRegion,
            'mastheadNote' => $reviewed ? ($review['mastheadNote'] ?? '') : '',
            'reviewedGroups' => $reviewed ? ($review['reviewedGroups'] ?? []) : [],
            'programBlockIds' => $program, 'slots' => $slots];
    }

    /** Find the upper content boundary without needing legible masthead text. */
    private static function alignedColumnTop(array $blocks, array $body, array $centers, float $width, float $height): ?float
    {
        if (count($centers) < 3) { return null; }
        $tops = [];
        foreach ($centers as $column => $center) {
            foreach ($body as $block) {
                [$x, $y, $w, $h] = $block['box'];
                if ($w < $width*.65 || $w > $width*1.35 || abs($x+$w/2-$center) > $width*.3) { continue; }
                $tops[$column] = min($tops[$column] ?? INF, $y);
            }
        }
        // A majority of distinct columns must start together, near the page top.
        $required = max(3, (int) ceil(count($centers)*.6));
        $best = [];
        asort($tops);
        foreach ($tops as $top) {
            if ($top < $height*.04 || $top > $height*.25) { continue; }
            $aligned = array_filter($tops, static fn($y) => $y >= $top && $y <= $top+$height*.012);
            if (count($aligned) > count($best)) { $best = $aligned; }
        }
        if (count($best) < $required) { return null; }
        $boundary = (float) min($best);
        // A short headline immediately above body belongs to the column too.
        // Walk upward through attached blocks; never swallow a column opening.
        foreach ($best as $column => $top) {
            do {
                $previous = $top;
                foreach ($blocks as $block) {
                    [$x, $y, $w, $h] = $block['box'];
                    if ($w < $width*.35 || $w > $width*1.35
                        || abs($x+$w/2-$centers[$column]) > $width*.3) { continue; }
                    $gap = $top-($y+$h);
                    if ($y < $top && $gap >= 0 && $gap <= $width*.06) { $top = $y; }
                }
            } while ($top < $previous);
            $boundary = min($boundary, $top);
        }
        return $boundary >= $height*.04 ? $boundary : null;
    }

    private static function median(array $values): float
    {
        if ($values === []) { return 0; } sort($values); return (float) $values[intdiv(count($values), 2)];
    }
}
