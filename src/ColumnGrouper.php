<?php

declare(strict_types=1);

namespace Survos\PeriodicalGrouping;

/** Source-preserving column rules shared by batch production and server-rendered previews. */
final class ColumnGrouper
{
    public static function saved(array $blocks, array $options = []): array
    {
        $articles = $options['articles'] ?? [];
        $reviewed = $options['reviewed'] ?? [];
        $membership = [];
        foreach ($articles as $index => $article) {
            foreach ($article['blocks'] as $ref) {
                $membership[$ref['id']] = array_key_exists($ref['id'], $membership) ? null : $index;
            }
        }
        $result = [];
        for ($i = 0; $i < count($blocks);) {
            $first = $blocks[$i];
            $review = null;
            foreach ($reviewed as $candidate) {
                if (count($candidate['blockIds']) > 1
                    && array_column(array_slice($blocks, $i, count($candidate['blockIds'])), 'id') === $candidate['blockIds']) {
                    $review = $candidate;
                    break;
                }
            }
            if ($review !== null) {
                $result[] = ['blocks' => array_slice($blocks, $i, count($review['blockIds'])), 'basis' => 'reviewed opening',
                    'title' => self::compact($first['text']), 'type' => $review['type'] ?? null, 'article' => null];
                $i += count($review['blockIds']);
                continue;
            }
            $index = $membership[$first['id']] ?? null;
            $article = $index !== null ? $articles[$index] : null;
            $members = [$first];
            if ($article !== null) {
                while (isset($blocks[$i + count($members)])) {
                    $next = $blocks[$i + count($members)];
                    if (($membership[$next['id']] ?? null) !== $index
                        || in_array($next['id'], array_map(static fn(array $g) => $g['blockIds'][0], $reviewed), true)) {
                        break;
                    }
                    $members[] = $next;
                }
            }
            $result[] = ['blocks' => $members, 'basis' => count($members) > 1 ? 'saved article' : null,
                'title' => ($article['title'] ?? null) ?: self::compact($first['text']),
                'type' => $article['type'] ?? null, 'article' => $article];
            $i += count($members);
        }
        return $result;
    }

    public static function proposed(array $blocks, array $options = []): array
    {
        $font = $options['bodyFont'] ?? 9;
        $width = $options['columnWidth'] ?? 900;
        $center = $options['columnCenter'] ?? 450;
        $groups = [];
        for ($i = 0; $i < count($blocks);) {
            $first = $blocks[$i];
            $kind = self::lead($first, $font, $width, $center);
            $members = [$first]; $roles = [];
            if ($kind === null) { $groups[] = self::single($first); ++$i; continue; }
            $lines = explode("\n", $first['text']);
            $roles[] = self::role($first, 'headline', 0, $kind === 'embedded' ? 1 : count($lines));
            if ($kind === 'embedded') {
                $start = 1;
                if (preg_match('/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2}\b/i', $lines[1])) {
                    $roles[] = self::role($first, 'dateline', 1, 2); $start = 2;
                }
                $roles[] = self::role($first, 'body', $start, count($lines));
            } else {
                $next = $blocks[$i + 1] ?? null;
                if ($next !== null && self::adjacent($first, $next, $width) && self::body($next, $width)) {
                    $subhead = count(explode("\n", $next['text'])) <= 3 && self::length($next['text']) <= 200
                        && ($next['fontSize'] ?? 0) > $font * 1.15 && $next['fontSize'] < ($first['fontSize'] ?? 0);
                    if ($subhead) {
                        $deck = [$next]; $cursor = $i + 2;
                        while (isset($blocks[$cursor]) && count($deck) < 4) {
                            $fragment = $blocks[$cursor]; $previous = $deck[array_key_last($deck)];
                            $gap = $fragment['box'][1] - $previous['box'][1] - $previous['box'][3];
                            $centered = abs($fragment['box'][0] + $fragment['box'][2] / 2 - $center) < $width * .14;
                            $beside = count(explode("\n", $fragment['text'])) === 1
                                && $fragment['box'][0] >= $previous['box'][0] + $previous['box'][2]
                                && $fragment['box'][0] - $previous['box'][0] - $previous['box'][2] < $width * .08
                                && abs($fragment['box'][1] - $previous['box'][1]) < self::lineHeight($previous) * .35;
                            if (self::length($fragment['text']) > 200 || count(explode("\n", $fragment['text'])) > 3
                                || abs(($fragment['fontSize'] ?? 0) - $next['fontSize']) > $font * .12
                                || (!$beside && (!$centered || $gap < -self::lineHeight($previous) * .5 || $gap > self::lineHeight($previous) * .8))) {
                                break;
                            }
                            $deck[] = $fragment; ++$cursor;
                        }
                        $following = $blocks[$cursor] ?? null;
                        if ($following !== null && self::adjacent($deck[array_key_last($deck)], $following, $width)
                            && self::body($following, $width) && (($following['fontSize'] ?? null) ?: $font) <= $font * 1.15) {
                            foreach ($deck as $fragment) {
                                $members[] = $fragment;
                                $roles[] = self::role($fragment, 'subhead');
                            }
                            $next = $following;
                        }
                    }
                    if (self::lead($next, $font, $width, $center) === null || count($members) > 1) {
                        $members[] = $next; $roles[] = self::role($next, 'body');
                    }
                }
            }
            if (!in_array('body', array_column($roles, 'role'), true)) { $groups[] = self::single($first); ++$i; continue; }
            $previous = $members[array_key_last($members)];
            while (isset($blocks[$i + count($members)])) {
                $next = $blocks[$i + count($members)];
                if (self::lead($next, $font, $width, $center) !== null || !self::body($next, $width)
                    || abs((($next['fontSize'] ?? null) ?: $font) - (($previous['fontSize'] ?? null) ?: $font)) > $font * .12
                    || !self::adjacent($previous, $next, $width, .055)
                    || $next['box'][1] - $previous['box'][1] - $previous['box'][3] > self::lineHeight($previous) * .6) {
                    break;
                }
                $members[] = $next; $roles[] = self::role($next, 'body'); $previous = $next;
            }
            $groups[] = ['blocks' => $members, 'basis' => 'proposed opening',
                'title' => $kind === 'embedded' ? $lines[0] : preg_replace('/\s+/u', ' ', $first['text']),
                'type' => null, 'article' => null, 'roles' => $roles,
                'evidence' => match ($kind) {
                    'embedded' => 'Leading all-caps line inside body block',
                    'centered' => 'Short centered title above aligned body',
                    default => 'Display-size heading above aligned text',
                }];
            $i += count($members);
        }
        return $groups;
    }

    public static function combined(array $blocks, array $options = []): array
    {
        $saved = self::saved($blocks, $options); $result = [];
        for ($i = 0; $i < count($saved);) {
            if ($saved[$i]['basis'] !== null) { $result[] = $saved[$i++]; continue; }
            $run = [];
            while ($i < count($saved) && $saved[$i]['basis'] === null) { array_push($run, ...$saved[$i++]['blocks']); }
            array_push($result, ...self::proposed($run, $options));
        }
        foreach ($result as &$group) {
            if ($group['basis'] !== null || count($group['blocks']) !== 1) { continue; }
            $articles = array_values(array_filter($options['articles'] ?? [], static fn(array $article): bool =>
                in_array($group['blocks'][0]['id'], array_column($article['blocks'], 'id'), true)));
            if (count($articles) === 1) {
                $group = [...$group, 'basis' => 'saved article', 'title' => $articles[0]['title'],
                    'type' => $articles[0]['type'] ?? null, 'article' => $articles[0]];
            }
        }
        unset($group);
        return $result;
    }

    public static function markdown(array $group): string
    {
        $blocks = array_column($group['blocks'], null, 'id'); $parts = [];
        foreach ($group['roles'] ?? [] as $role) {
            $block = $blocks[$role['id']];
            $lines = array_slice(explode("\n", $block['text']), $role['start'], $role['end'] - $role['start']);
            foreach ($lines as $j => &$line) {
                if ($j > 0 && in_array($j + $role['start'], $block['paragraphStarts'] ?? [], true)) { $line = "\n\n".$line; }
            }
            unset($line);
            $text = trim(implode(' ', $lines));
            $index = array_key_last($parts);
            if ($role['role'] === 'subhead' && $index !== null && $parts[$index]['role'] === 'subhead') {
                $first = $blocks[$parts[$index]['source']];
                $beside = $block['box'][0] >= $first['box'][0] + $first['box'][2]
                    && abs($block['box'][1] - $first['box'][1]) < self::lineHeight($first) * .35;
                if ($beside) {
                    $opening = trim(explode("\n", $first['text'])[0]);
                    $parts[$index]['text'] = substr($parts[$index]['text'], 0, strlen($opening)).' '.$text.substr($parts[$index]['text'], strlen($opening));
                } else { $parts[$index]['text'] .= ' '.$text; }
            } else { $parts[] = ['role' => $role['role'], 'text' => $text, 'source' => $role['id']]; }
        }
        return implode("\n\n", array_map(static fn(array $part): string =>
            match ($part['role']) { 'headline' => '## ', 'subhead' => '### ', default => '' }.$part['text'], $parts));
    }

    private static function lead(array $block, float $font, float $width, float $center): ?string
    {
        $text = $block['text']; $lines = explode("\n", $text);
        if (count($lines) >= 4 && self::caps($lines[0]) && self::length($lines[0]) <= 100
            && self::letters(implode(' ', array_slice($lines, 1))) >= 50) { return 'embedded'; }
        if (self::length(trim($text)) > 150 || count($lines) > 3 || self::letters($text) < 4) { return null; }
        $centered = abs($block['box'][0] + $block['box'][2] / 2 - $center) < $width * .14;
        $narrow = $block['box'][2] < $width * .72;
        $titleCase = true;
        foreach (preg_split('/\s+/u', trim($text)) as $word) {
            if (preg_match('/^\p{L}/u', $word) && !preg_match('/^\p{Lu}/u', $word)) { $titleCase = false; break; }
        }
        if (($block['fontSize'] ?? 0) >= $font * 1.5 && ($centered || self::caps($text))) { return 'display'; }
        return $centered && $narrow && count($lines) <= 2 && (self::caps($text) || $titleCase) ? 'centered' : null;
    }

    private static function adjacent(array $a, array $b, float $width, float $factor = .18): bool
    {
        $gap = $b['box'][1] - $a['box'][1] - $a['box'][3];
        $overlap = min($a['box'][0] + $a['box'][2], $b['box'][0] + $b['box'][2]) - max($a['box'][0], $b['box'][0]);
        return $gap >= -min($a['box'][3], $b['box'][3]) * .1 && $gap <= $width * $factor
            && $overlap >= min($a['box'][2], $b['box'][2]) * .65;
    }

    private static function body(array $block, float $width): bool { return self::letters($block['text']) >= 40 && $block['box'][2] >= $width * .55; }
    private static function letters(string $text): int { return preg_match_all('/\p{L}/u', $text); }
    private static function caps(string $text): bool { return self::letters($text) >= 4 && $text === mb_strtoupper($text); }
    // Preserve the previous UTF-16 character thresholds, including supplementary characters.
    private static function length(string $text): int { return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2); }
    private static function lineHeight(array $block): float { return $block['box'][3] / count(explode("\n", $block['text'])); }
    private static function compact(string $text): string { return trim(preg_replace('/\s+/u', ' ', $text)); }
    private static function single(array $block): array { return ['blocks' => [$block], 'basis' => null, 'title' => $block['text'], 'article' => null]; }
    private static function role(array $block, string $role, int $start = 0, ?int $end = null): array
    {
        return ['id' => $block['id'], 'role' => $role, 'start' => $start, 'end' => $end ?? count(explode("\n", $block['text']))];
    }
}
