<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Survos\PeriodicalGrouping\ColumnGrouper as G;

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$blocks = array_map(static fn(string $id): array => ['id'=>$id,'text'=>$id,'box'=>[0,0,100,100]], ['h','s','b','unrelated','next']);
$article = ['id'=>'a','title'=>'Saved title','blocks'=>array_map(static fn(string $id): array => ['id'=>$id], ['h','s','b','next'])];
$groups = G::saved($blocks, ['articles'=>[$article]]);
check(array_map(static fn(array $g): array => array_column($g['blocks'],'id'), $groups) === [['h','s','b'],['unrelated'],['next']], 'Saved membership stays contiguous');
$review = ['blockIds'=>['h','s','b'],'type'=>'obituary'];
check(G::saved($blocks,['reviewed'=>[$review]])[0]['basis'] === 'reviewed opening', 'Reviewed membership wins');
check(count(G::saved($blocks,['articles'=>[$article,[...$article,'id'=>'other']]])) === 5, 'Ambiguous ownership stays ungrouped');
check(count(G::saved($blocks,['reviewed'=>[['blockIds'=>['h','b']]]])) === 5, 'Noncontiguous review cannot claim intervening source');
$fixtures = json_decode(file_get_contents(__DIR__.'/grouping-fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($fixtures as $fixture) {
    $before = $fixture['blocks'];
    $groups = G::proposed($fixture['blocks'], $fixture);
    $actual = array_values(array_map(static fn(array $g): array => array_column($g['blocks'], 'id'), array_filter($groups, static fn(array $g): bool => $g['basis'] !== null)));
    check($actual === $fixture['expected'], $fixture['name']);
    check(array_merge(...array_column($groups, 'blocks')) === $before, 'Every source block appears once in order');
    check($fixture['blocks'] === $before, 'Source text cannot change');
    foreach ($groups as $group) {
        if ($group['basis'] === null) { continue; }
        check($group['type'] === null, 'Proposals do not classify articles');
        foreach ($group['blocks'] as $block) {
            $covered = [];
            foreach ($group['roles'] as $role) {
                if ($role['id'] === $block['id']) { array_push($covered, ...range($role['start'], $role['end'] - 1)); }
            }
            check($covered === range(0,count(explode("\n",$block['text']))-1), 'Role spans cover every source line exactly once');
        }
    }
}
$embedded = G::proposed($fixtures[3]['blocks'], $fixtures[3])[0];
check(array_slice(array_column($embedded['roles'],'role'),0,3) === ['headline','dateline','body'], 'Embedded heading keeps dateline and body');
check(str_starts_with(G::markdown($embedded), '## ARRIVAL OF THE AFRICA'), 'Markdown retains heading');
$deck = $fixtures[array_key_last($fixtures)];
$group = G::proposed($deck['blocks'], $deck)[0];
check(substr_count(G::markdown($group), '### ') === 1, 'Split deck renders as one subhead');
check(str_contains(G::markdown($group), 'Past Favors. Shame!'), 'Same-line fragment appears on correct line');
$deck['blocks'][2]['box'][1] += 150;
check(!in_array('TB101', array_column(array_filter(G::proposed($deck['blocks'], $deck)[0]['roles'],static fn(array $r): bool => $r['role']==='subhead'),'id'),true), 'Distant fragment is not a deck');
$fixture = $fixtures[0];
$combined = G::combined($fixture['blocks'], $fixture);
check($combined[0]['basis'] === 'proposed opening', 'Combined mode finds proposals');
$fixed = ['id'=>'saved','title'=>'Saved source article','blocks'=>array_map(static fn(array $b): array=>['id'=>$b['id']],$fixture['blocks'])];
check(G::combined($fixture['blocks'], [...$fixture,'articles'=>[$fixed]])[0]['basis'] === 'saved article', 'Saved group wins over proposal');
$cap = [['id'=>'cap','text'=>'A','box'=>[0,0,30,100],'fontSize'=>30],['id'=>'text','text'=>str_repeat('Body text ',20),'box'=>[0,100,850,200],'fontSize'=>9]];
check(count(array_filter(G::proposed($cap,['bodyFont'=>9,'columnWidth'=>900,'columnCenter'=>450]),static fn(array $g):bool=>$g['basis']!==null))===0, 'Drop caps are not headlines');
echo "PHP grouping fixtures, reviewed ownership, role coverage and deck rendering passed.\n";

$sample = json_decode(file_get_contents(__DIR__.'/fixtures/same-line-deck.json'), true, flags: JSON_THROW_ON_ERROR);
$options = ['bodyFont'=>9,'columnWidth'=>876,'columnCenter'=>4445];
$group = G::proposed($sample, $options)[0];
check(array_column($group['blocks'],'id') === array_column($sample,'id'), 'Same-line deck fragment must not strand the body');
check(array_column($group['roles'],'role') === ['headline','subhead','subhead','body'], 'Deck roles retain both fragments');
check(str_contains(G::markdown($group), "Guthrie County's Oldest Citizen And Centenarian Receives A Big Ovation."), 'Fragment joins the correct source line');
$sample[2]['box'][0] += 400;
check(count(G::proposed($sample, $options)[0]['blocks']) === 2, 'Distant side fragment must not join the deck');
