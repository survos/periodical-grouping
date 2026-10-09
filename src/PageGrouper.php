<?php

declare(strict_types=1);

namespace Survos\PeriodicalGrouping;

/** Assigns columns and records every source block exactly once. */
final class PageGrouper
{
    public static function prepare(string $issueId, array $page, string $mode = 'combined'): array
    {
        if (!in_array($mode, ['combined', 'saved', 'proposed'], true)) { throw new \InvalidArgumentException('Unknown grouping mode.'); }
        $analysis = $page['analysis']; $blocks = $page['blocks']; $centers = $analysis['columnCenters'];
        $masthead = $analysis['mastheadBlockIds'];
        $program = array_values(array_diff($analysis['programBlockIds'], $masthead));
        $columns = array_fill(0, count($centers), []);
        foreach ($blocks as $block) {
            if (in_array($block['id'], $masthead, true) || in_array($block['id'], $program, true)) { continue; }
            $center = $block['box'][0] + $block['box'][2] / 2;
            $column = 0;
            foreach ($centers as $i => $candidate) {
                if (abs($candidate - $center) < abs($centers[$column] - $center)) { $column = $i; }
            }
            $columns[$column][] = $block;
        }
        $groups = []; $unassigned = [];
        foreach ($columns as $column => $members) {
            usort($members, static fn(array $a, array $b): int => ($a['box'][1] <=> $b['box'][1]) ?: ($a['box'][0] <=> $b['box'][0]));
            $options = ['articles' => $page['articles'] ?? [], 'reviewed' => $analysis['reviewedGroups'],
                'bodyFont' => $analysis['bodyFontSize'] ?: 9, 'columnWidth' => $analysis['columnWidth'], 'columnCenter' => $centers[$column]];
            foreach (ColumnGrouper::$mode($members, $options) as $group) {
                $ids = array_column($group['blocks'], 'id');
                if ($group['basis'] === null) { array_push($unassigned, ...$ids); continue; }
                // Match the historical JSON encoding so existing bookmarks keep their g-* IDs.
                $identity = json_encode([$issueId, $page['pageIndex'], $ids], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
                $groups[] = ['id' => 'g-'.substr(hash('sha256', $identity), 0, 24), 'parentId' => 'column-'.$column,
                    'column' => $column, 'blockIds' => $ids, 'basis' => $group['basis'], 'title' => $group['title'],
                    'type' => ($group['type'] ?? null) ?: null, 'articleId' => $group['article']['id'] ?? null,
                    'roles' => $group['roles'] ?? [], 'evidence' => $group['evidence'] ?? null,
                    'markdown' => isset($group['roles']) ? ColumnGrouper::markdown($group) : null];
            }
        }
        $expected = array_column($blocks, 'id');
        $covered = [...$masthead, ...$program, ...$unassigned];
        $byId = array_column($blocks, null, 'id');
        foreach ($groups as $group) {
            array_push($covered, ...$group['blockIds']);
            foreach ($group['roles'] as $role) {
                if (!in_array($role['id'], $group['blockIds'], true) || !is_int($role['start']) || !is_int($role['end'])
                    || $role['start'] < 0 || $role['end'] <= $role['start']
                    || $role['end'] > count(explode("\n", $byId[$role['id']]['text']))) {
                    throw new \UnexpectedValueException('Invalid role span in '.$group['id']);
                }
            }
        }
        sort($expected); sort($covered);
        if (count(array_unique($expected)) !== count($expected) || $expected !== $covered) {
            throw new \UnexpectedValueException('Source block coverage changed on page '.$page['pageIndex']);
        }
        return ['pageIndex' => $page['pageIndex'], 'analysis' => $analysis, 'groups' => $groups,
            'unassignedBlockIds' => $unassigned, 'programBlockIds' => $program, 'mastheadBlockIds' => $masthead];
    }
}
