const assert=require('node:assert/strict');
const {groupColumn}=require('../assets/grouping.js');
const blocks=['h','s','b','unrelated','next'].map(id=>({id,text:id,box:[0,0,100,100]}));
const article={id:'a',title:'Saved title',blocks:[{id:'h'},{id:'s'},{id:'b'},{id:'next'}]};
let groups=groupColumn(blocks,{articles:[article]});
assert.deepEqual(groups.map(g=>g.blocks.map(b=>b.id)),[['h','s','b'],['unrelated'],['next']]);
assert.deepEqual(groups.flatMap(g=>g.blocks),blocks);
groups=groupColumn(blocks,{reviewed:[{blockIds:['h','s','b'],type:'obituary'}]});
assert.equal(groups[0].basis,'reviewed opening');
assert.equal(groups[0].type,'obituary');
assert.equal(groups[1].basis,null);
assert.equal(groupColumn(blocks,{reviewed:[{blockIds:['h','b']}]}).length,5);
assert.equal(groupColumn(blocks,{articles:[article,{...article,id:'other'}]}).length,5);
assert.equal(groupColumn(blocks).length,5); // Typography alone must not manufacture attachments.
console.log('Grouping checks passed');

const {proposeColumn,markdown}=require('../assets/grouping.js');
for(const sample of require('./grouping-fixtures.json')){
    const before=JSON.stringify(sample.blocks),groups=proposeColumn(sample.blocks,sample);
    assert.deepEqual(groups.filter(g=>g.basis).map(g=>g.blocks.map(b=>b.id)),sample.expected,sample.name);
    assert.deepEqual(groups.flatMap(g=>g.blocks),sample.blocks,'Every source block appears once');
    assert.equal(JSON.stringify(sample.blocks),before,'Source remains unchanged');
    for(const group of groups.filter(g=>g.basis)){
        assert.equal(group.type,null,'Do not classify proposed groups');
        for(const b of group.blocks){
            const covered=group.roles.filter(p=>p.id===b.id).flatMap(p=>Array.from({length:p.end-p.start},(_,i)=>p.start+i));
            assert.deepEqual(covered,b.text.split('\n').map((_,i)=>i),'Roles cover every source line exactly once');
        }
    }
}
const embedded=require('./grouping-fixtures.json')[3];
const opening=proposeColumn(embedded.blocks,embedded)[0];
assert.deepEqual(opening.roles.slice(0,3).map(r=>r.role),['headline','dateline','body']);
assert.match(markdown(opening),/^## ARRIVAL OF THE AFRICA/);
assert.equal(proposeColumn([{id:'cap',text:'A',box:[0,0,30,100],fontSize:30},{id:'text',text:'Body text '.repeat(20),box:[0,100,850,200],fontSize:9}],{bodyFont:9,columnWidth:900,columnCenter:450}).filter(g=>g.basis).length,0,'A drop cap is not a headline');
console.log('Fixed proposal cases and source conservation checks passed');
const {displayColumn}=require('../assets/grouping.js');
const fixture=require('./grouping-fixtures.json')[0];
assert.deepEqual(displayColumn(fixture.blocks,fixture).filter(g=>g.basis).map(g=>g.blocks.map(b=>b.id)),fixture.expected);
const fixed={blocks:fixture.blocks.map(b=>({id:b.id})),title:'Reviewed source article'};
assert.equal(displayColumn(fixture.blocks,{...fixture,articles:[fixed]})[0].basis,'saved article');
assert.deepEqual(displayColumn(fixture.blocks,{...fixture,articles:[fixed]}).flatMap(g=>g.blocks),fixture.blocks);
console.log('Combined view preserves saved groups and exposes provisional openings');

const splitDeck=require('./grouping-fixtures.json').at(-1);
const deckGroup=proposeColumn(splitDeck.blocks,splitDeck)[0];
assert.equal((markdown(deckGroup).match(/^### /gm)||[]).length,1);
assert.match(markdown(deckGroup),/Past Favors\. Shame!/);
assert.deepEqual(deckGroup.roles.filter(r=>r.role==='subhead').map(r=>r.id),['TB100','TB101']);
const separate=JSON.parse(JSON.stringify(splitDeck));
separate.blocks[2].box[1]+=150;
assert.ok(!proposeColumn(separate.blocks,separate)[0].roles.some(r=>r.id==='TB101'&&r.role==='subhead'),'Distant short heading must not be merged into deck');

const {sourceRegion}=require('../assets/grouping.js');
assert.deepEqual(sourceRegion([{box:[100,100,200,100]},{box:[100,300,200,100]}],1000,1000),{left:100,top:100,width:200,height:300,widthPercent:20,heightPercent:30});
assert.equal(sourceRegion([{box:[-10,-10,1010,1020]}],1000,1000).heightPercent,100);

// Same-line deck fragment must not strand the body (Audubon, June 29, 1911).
{
const sample=[{"id": "TB87", "text": "CENTENNIAL BIRTHDAY", "box": [4007, 2963, 869, 128], "fontSize": 22}, {"id": "TB88", "text": "Guthrie County's Oldest Citizen\nCentenarian Receives A\nBig Ovation.", "box": [4007, 3135, 741, 184], "fontSize": 12}, {"id": "TB99", "text": "And", "box": [4787, 3135, 87, 73], "fontSize": 12}, {"id": "TB100", "text": "Last Saturday the oitizens of Guthrie\nCenter almost to a unit turned out to\nbestow their congratulations on Mr.\nMartholomew Dunley who celebrated\nhis one hundredth birthday anniversary\non that day. All business housss\nwere closed on proclamation of Mayor\nHolsman and all did honor to the occasion.\nGuthrie County will soon\nhave another Centenarian, Mr. Lewis\nof Menlo whose next birthday we are\ninformed will be his one hundredth on\nwhich occasion Menlo will be right to\nthe front in observing.", "box": [4011, 3352, 877, 772], "fontSize": 9}];
const api=require("../assets/grouping.js");
const result=api.proposeColumn(sample,{bodyFont:9,columnWidth:876,columnCenter:4445});
assert.deepEqual(result[0].blocks.map(b=>b.id),sample.map(b=>b.id));
assert.deepEqual(result[0].roles.map(r=>r.role),["headline","subhead","subhead","body"]);
assert.match(api.markdown(result[0]), /### Guthrie County\'s Oldest Citizen And Centenarian Receives A Big Ovation\./);
const distant=sample.map(b=>({...b,box:[...b.box]}));distant[2].box[0]+=400;
assert.equal(api.proposeColumn(distant,{bodyFont:9,columnWidth:876,columnCenter:4445})[0].blocks.length,2);
}
