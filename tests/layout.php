<?php
require dirname(__DIR__).'/vendor/autoload.php';
use Survos\PeriodicalGrouping\LayoutAnalyzer;
$blocks=[];
for($column=0;$column<6;$column++){
    for($row=0;$row<3;$row++){
        $blocks[]=['id'=>"c{$column}-{$row}",'text'=>str_repeat('Body text ',30),'box'=>[100+$column*900,1500+$row*300,800,250],'fontSize'=>9];
    }
}
$blocks[]=['id'=>'headline','text'=>'Real title','box'=>[105,1400,700,90],'fontSize'=>21];
$profile=LayoutAnalyzer::analyze($blocks,5600,'example',1);
if($profile['columnCount']!==6||$profile['bodyFontSize']!==9.0||!in_array(21.0,$profile['displayFontSizes'],true)){throw new RuntimeException('Stored grid/font analysis failed');}
if($profile['mastheadBlockIds']!==[]||$profile['mastheadStatus']!=='not applicable'){throw new RuntimeException('Later page acquired a masthead');}
if(count($profile['slots'])!==count($blocks)){throw new RuntimeException('A block lost its grid assignment');}
$reviews=json_decode(file_get_contents(dirname(__DIR__).'/resources/reviewed-profiles.json'), true, flags: JSON_THROW_ON_ERROR);
$review=$reviews['sn88059762-1856-08-28-ed-1'][0];
$sample=[
    ['id'=>'TB155','text'=>'E','box'=>[5156,392,128,196],'fontSize'=>33],
    ['id'=>'TB131','text'=>'OTTUMWA, IOWA, AUGUST 28, 1856.','box'=>[2298,1159,1998,104],'fontSize'=>17],
    ['id'=>'TB46','text'=>'Business Cards','box'=>[263,1325,577,104],'fontSize'=>17],
];
$first=LayoutAnalyzer::analyze($sample,6924,'sn88059762-1856-08-28-ed-1',0,$review);
if($first['mastheadBlockIds']!==['TB131','TB155'] || $first['mastheadStatus']!=='reviewed'){throw new RuntimeException('Reviewed masthead membership failed');}
if(count($first['slots'])!==3){throw new RuntimeException('Masthead separation removed source blocks');}
$later=LayoutAnalyzer::analyze($sample,6924,'sn88059762-1856-08-28-ed-1',1,$review);
if($later['mastheadBlockIds']!==[]){throw new RuntimeException('Reviewed masthead leaked to later page');}
$header = [
    ['id'=>'title','text'=>'Audubon','box'=>[621,321,1682,495],'fontSize'=>82],
    ['id'=>'date','text'=>'EXIRA, IOWA, THURSDAY, JULY 6, 1911.','box'=>[2058,1077,3548,115],'fontSize'=>17],
];
$inferred = LayoutAnalyzer::analyze([...$blocks, ...$header],6175,'unreviewed',0);
if($inferred['mastheadBlockIds']!==['title','date'] || $inferred['mastheadRegion']!=[0,0,6175,1192]){throw new RuntimeException('Title/date inference failed or swallowed article blocks');}
$headlineOnly = LayoutAnalyzer::analyze([...$blocks,$header[0]],6175,'unreviewed',0);
if($headlineOnly['mastheadBlockIds']!==[]){throw new RuntimeException('Large headline alone became masthead');}
$nextPage = LayoutAnalyzer::analyze([...$blocks,...$header],6175,'unreviewed',1);
if($nextPage['mastheadBlockIds']!==[]){throw new RuntimeException('Inferred masthead leaked to later page');}
// May 11: source OCR omitted the newspaper title entirely.
$publisherHeader = [
    ['id'=>'TB1','text'=>'25 YEARS OW','box'=>[510,1209,784,104],'fontSize'=>18],
    ['id'=>'TB45','text'=>'W. J. Lancelot, Editor W. H. Lancelot, Publisher','box'=>[2212,1048,1883,79],'fontSize'=>14],
    ['id'=>'TB62','text'=>'EXIKA, IOWA, THURSDAY, MAY 11, 1911. $1.00 PER YEAR','box'=>[2143,1209,3497,104],'fontSize'=>18],
    ['id'=>'TB76','text'=>'WEDDING ANNIVERSARY','box'=>[4019,1381,873,128],'fontSize'=>22],
];
$missingTitle=LayoutAnalyzer::analyze([...$blocks,...$publisherHeader],6196,'may11',0);
if($missingTitle['mastheadBlockIds']!==['TB1','TB45','TB62']){throw new RuntimeException('Publisher/date masthead lost or swallowed article');}
$dateOnly=LayoutAnalyzer::analyze([...$blocks,$publisherHeader[2]],6196,'dateOnly',0);
if($dateOnly['mastheadBlockIds']!==[]){throw new RuntimeException('Date alone became masthead');}
echo "Textsheet layout checks passed\n";

// Real fragmented Iowa front page: body columns establish the header boundary.
$iowa=json_decode(file_get_contents(__DIR__.'/fixtures/iowa-front-page.json'),true,flags:JSON_THROW_ON_ERROR);
$analyzeIowa=static fn(array $blocks,int $page=0) => LayoutAnalyzer::analyze($blocks,$iowa['width'],$iowa['issueId'],$page,[],null,$iowa['height']);
$original=$iowa['blocks'];
$geometry=$analyzeIowa($original);
$expected=['TB1','TB2','TB3','TB17','TB18','TB110','TB170','TB171','TB219','TB235','TB241','TB242','TB243','TB244','TB245','TB246','TB247'];
if($geometry['mastheadBlockIds']!==$expected || $geometry['mastheadRegion']!=[0,0,$iowa['width'],772.0]){throw new RuntimeException('Fragmented Iowa masthead was not separated at aligned column tops');}
if($original!==$iowa['blocks'] || count($geometry['slots'])!==count($original)){throw new RuntimeException('Geometry inference changed source evidence');}
if(in_array('TB19',$geometry['mastheadBlockIds'],true)){throw new RuntimeException('Boundary-crossing block was swallowed');}
if($analyzeIowa($original,1)['mastheadBlockIds']!==[]){throw new RuntimeException('Geometric header leaked to an interior page');}
$heading=['id'=>'opening-heading','text'=>'A new story','box'=>[1082,735,948,25],'fontSize'=>20];
$protected=$analyzeIowa([...$original,$heading]);
if(in_array('opening-heading',$protected['mastheadBlockIds'],true) || $protected['mastheadRegion'][3]!==735.0){throw new RuntimeException('Attached editorial headline was swallowed');}
$staggered=array_map(static function($b) use($geometry) {
    $b['box'][1]+=$geometry['slots'][$b['id']]['column']*400;
    return $b;
},$original);
if($analyzeIowa($staggered)['mastheadBlockIds']!==[]){throw new RuntimeException('Unaligned columns established a false masthead');}
$prepared=Survos\PeriodicalGrouping\PageGrouper::prepare($iowa['issueId'],[...$iowa,'analysis'=>$geometry]);
foreach($prepared['groups'] as $group){
    if(array_intersect($expected,$group['blockIds'])){throw new RuntimeException('Masthead fragment entered an editorial group');}
}
echo "Iowa geometric masthead, opening protection, later-page and source coverage checks passed\n";
