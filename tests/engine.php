<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Survos\PeriodicalGrouping\GroupingEngine;

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$input = ['issueId' => 'test', 'sourceHash' => str_repeat('a', 64), 'pages' => [
    ['pageIndex' => 0, 'width' => 1000, 'height' => 2000, 'blocks' => [
        ['id' => 'h', 'text' => 'A HEADLINE', 'box' => [250,100,500,50], 'fontSize' => 18],
        ['id' => 'b', 'text' => str_repeat('This is a sentence in the story. ', 10), 'box' => [50,160,900,400], 'fontSize' => 9],
        ['id' => 'u', 'text' => '?', 'box' => [0,1000,10,20]],
    ]],
]];
$engine = new GroupingEngine();
$first = $engine->prepare($input);
check($first === $engine->prepare($input), 'Repeated preparation must be identical');
check($first['pages'][0]['groups'][0]['blockIds'] === ['h','b'], 'Headline and body must group');
check($first['pages'][0]['unassignedBlockIds'] === ['u'], 'Loose source text must survive');
$decorated = $input;
$decorated['storageContext'] = ['collectionId'=>'other','database'=>'example'];
$decorated['pages'][0]['blocks'][0]['crop'] = 'https://example.invalid/crop';
$decorated['pages'][0]['analysis'] = ['mastheadBlockIds' => ['h','b']];
check($first === $engine->prepare($decorated), 'Storage context, UI metadata and cached analysis cannot change the result');
$reviewed = $input;
$reviewed['pages'][0]['review'] = ['pageWidth'=>1000,'pageHeight'=>2000,'basis'=>'reviewed','mastheadBlockIds'=>['h']];
$second = $engine->prepare($reviewed);
check($first['inputHash'] !== $second['inputHash'], 'Review changes must alter the input fingerprint');
check($second['pages'][0]['mastheadBlockIds'] === ['h'], 'Matching review must apply');
$reviewed['pages'][0]['review']['pageWidth'] = 999;
check($first === $engine->prepare($reviewed), 'Wrong-size reviews must not apply');
foreach (['duplicateBlock', 'duplicatePage', 'missingBox', 'badHash', 'missingWidth'] as $case) {
    $bad = $input;
    match ($case) {
        'duplicateBlock' => $bad['pages'][0]['blocks'][] = $bad['pages'][0]['blocks'][0],
        'duplicatePage' => $bad['pages'][] = $bad['pages'][0],
        'missingBox' => $bad['pages'][0]['blocks'][0]['box'] = null,
        'badHash' => $bad['sourceHash'] = 'wrong',
        'missingWidth' => $bad['pages'][0]['width'] = null,
    };
    try { $engine->prepare($bad); throw new RuntimeException("Accepted $case"); }
    catch (InvalidArgumentException) {}
}
echo "Engine identity, provenance, source coverage and invalid-input checks passed.\n";

$fixture = json_decode(file_get_contents(__DIR__.'/fixtures/cordele-1926-09-12.json'), true, flags: JSON_THROW_ON_ERROR);
$expected = json_decode(file_get_contents(__DIR__.'/fixtures/cordele-expected.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = $engine->prepare($fixture)['pages'];
foreach ($actual as &$page) {
    unset($page['analysis']);
    foreach ($page['groups'] as &$group) {
        $group = array_intersect_key($group, array_flip(['id','blockIds','roles','articleId','basis']));
    }
    unset($group);
}
unset($page);
check($actual == $expected, 'Cordele memberships, stable IDs and source spans must remain unchanged');
echo "Cordele: 8 pages and 150 groups match the retained regression fixture.\n";
