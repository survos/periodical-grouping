<?php

declare(strict_types=1);

namespace Survos\PeriodicalGrouping;

use Symfony\Component\Process\Process;

/** Offline producer. Persistence, publication, search and article eligibility belong to callers. */
final class GroupingEngine
{
    public function __construct(private readonly string $node = 'node', private readonly float $timeout = 120.0) {}

    /**
     * Each page supplies pageIndex, width, height, blocks and optional articles/review/measuredBottom.
     * Blocks use original-pixel box [x,y,width,height], stable id and unmodified OCR text.
     * The caller hashes the retained source layouts, before any display transformations.
     */
    public function prepare(array $input): array
    {
        if (!is_string($input['issueId'] ?? null) || $input['issueId'] === ''
            || !is_string($input['sourceHash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input['sourceHash'])
            || !is_array($input['pages'] ?? null) || !array_is_list($input['pages']) || $input['pages'] === []) {
            throw new \InvalidArgumentException('An issueId, SHA-256 sourceHash and nonempty page list are required.');
        }
        $pages = []; $seen = [];
        foreach ($input['pages'] as $page) {
            $index = $page['pageIndex'] ?? null;
            if (!is_int($index) || $index < 0 || isset($seen[$index])) {
                throw new \InvalidArgumentException('Page indexes must be unique nonnegative integers.');
            }
            $seen[$index] = true;
            foreach (['width', 'height'] as $dimension) {
                if (!is_numeric($page[$dimension] ?? null) || !is_finite((float) $page[$dimension]) || $page[$dimension] <= 0) {
                    throw new \InvalidArgumentException("Page $index requires a positive $dimension in original pixels.");
                }
            }
            self::validateBlocks($page['blocks'] ?? null, $index);
            $profiles = json_decode(file_get_contents(dirname(__DIR__).'/resources/reviewed-profiles.json'), true, flags: JSON_THROW_ON_ERROR);
            $review = $page['review'] ?? $profiles[$input['issueId']][$index] ?? [];
            // A review made against another scan must not be silently reapplied.
            if (($review['pageWidth'] ?? null) != $page['width'] || ($review['pageHeight'] ?? null) != $page['height']) {
                $review = [];
            }
            // Only algorithm inputs enter the fingerprint; crop URLs and UI metadata do not.
            $blocks = array_map(static fn(array $b): array => [
                'id' => $b['id'], 'text' => $b['text'], 'box' => $b['box'],
                'fontSize' => $b['fontSize'] ?? null, 'paragraphStarts' => $b['paragraphStarts'] ?? [],
            ], $page['blocks']);
            $analysis = LayoutAnalyzer::analyze($blocks, (float) $page['width'], $input['issueId'], $index,
                $review, isset($page['measuredBottom']) ? (float) $page['measuredBottom'] : null, (float) $page['height']);
            $articles = array_map(static fn(array $a): array => [
                'id' => $a['id'], 'title' => $a['title'], 'type' => $a['type'] ?? null,
                'blocks' => array_map(static fn(array $ref): array => ['id' => $ref['id']], $a['blocks']),
            ], $page['articles'] ?? []);
            usort($articles, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
            $pages[] = ['pageIndex' => $index, 'blocks' => $blocks, 'articles' => $articles, 'analysis' => $analysis];
        }
        usort($pages, static fn(array $a, array $b): int => $a['pageIndex'] <=> $b['pageIndex']);
        $prepared = ['issueId' => $input['issueId'], 'sourceHash' => $input['sourceHash'],
            'algorithmHash' => self::algorithmHash(), 'pages' => $pages];
        // Covers reviews, measured geometry and legacy memberships as well as source OCR.
        $prepared['inputHash'] = hash('sha256', json_encode($prepared, JSON_THROW_ON_ERROR));
        $process = new Process([$this->node, dirname(__DIR__).'/bin/prepare.cjs']);
        $process->setInput(json_encode($prepared, JSON_THROW_ON_ERROR));
        $process->setTimeout($this->timeout);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $result['inputHash'] = $prepared['inputHash'];
        if (isset($input['folioCode'])) { $result['folioCode'] = $input['folioCode']; }
        return $result;
    }

    /** Shared cache identity; no application files, paths, timestamps or network state. */
    public static function algorithmHash(): string
    {
        $root = dirname(__DIR__);
        return hash('sha256', implode('', array_map(static fn(string $path): string => file_get_contents($root.'/'.$path), [
            'src/GroupingEngine.php', 'src/LayoutAnalyzer.php', 'assets/grouping.js', 'bin/prepare.cjs', 'resources/reviewed-profiles.json',
        ])));
    }

    private static function validateBlocks(mixed $blocks, int $pageIndex): void
    {
        if (!is_array($blocks) || !array_is_list($blocks)) {
            throw new \InvalidArgumentException("Page $pageIndex requires a block list (empty is allowed).");
        }
        $ids = [];
        foreach ($blocks as $block) {
            $id = $block['id'] ?? null;
            if (!is_string($id) || $id === '' || isset($ids[$id]) || !is_string($block['text'] ?? null)) {
                throw new \InvalidArgumentException("Page $pageIndex requires unique string block IDs and string text.");
            }
            $ids[$id] = true;
            $box = $block['box'] ?? null;
            if (!is_array($box) || !array_is_list($box) || count($box) !== 4
                || count(array_filter($box, static fn($n) => (is_int($n) || is_float($n)) && is_finite((float) $n))) !== 4
                || $box[2] <= 0 || $box[3] <= 0) {
                throw new \InvalidArgumentException("Block $id requires a finite original-pixel box with positive width and height.");
            }
        }
    }
}
