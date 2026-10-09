const assert=require('node:assert/strict');
const {prepare}=require('../bin/prepare.cjs');
const {storedColumn}=require('../assets/grouping.js');
const page={pageIndex:0,articles:[],analysis:{columnCenters:[450],columnWidth:900,bodyFontSize:9,mastheadBlockIds:['m'],programBlockIds:['m'],reviewedGroups:[]},blocks:[
 {id:'m',text:'PAPER',box:[0,0,900,50],fontSize:32},
 {id:'h',text:'A HEADLINE',box:[200,100,500,50],fontSize:18},
 {id:'b',text:'This is the first sentence of a readable story. More of the source text belongs to this story.',box:[0,160,900,100],fontSize:9},
 {id:'u',text:'?',box:[0,900,10,20],fontSize:9}
]};
const input={issueId:'test',sourceHash:'x',algorithmHash:'y',pages:[page]};
const first=prepare(input);assert.deepEqual(first,prepare(input));
const p=first.pages[0];assert.equal(p.groups.length,1);assert.deepEqual(p.mastheadBlockIds,['m']);assert.deepEqual(p.programBlockIds,[]);
assert.deepEqual(p.groups[0].blockIds,['h','b']);assert.deepEqual(p.unassignedBlockIds,['u']);
const rendered=storedColumn(page.blocks.slice(1),p);assert.equal(rendered.length,2);
assert.deepEqual(rendered.flatMap(g=>g.blocks.map(b=>b.id)),['h','b','u']);
assert.ok(p.groups[0].markdown.startsWith('## A HEADLINE'));
console.log('Preparation is repeatable; masthead/source coverage and stored rendering preserved.');
