// Offline adapter for the same deterministic rules used by Textsheet. No network/AI.
const fs=require('node:fs'),crypto=require('node:crypto');
const rules=require('../assets/grouping.js');
const hash=value=>crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');
function prepare(input){
 const pages=input.pages.map(page=>{
  const {analysis,blocks}=page,centers=analysis.columnCenters;
  const masthead=new Set(analysis.mastheadBlockIds),program=new Set(analysis.programBlockIds.filter(id=>!masthead.has(id)));
  const columns=centers.map(()=>[]);
  for(const block of blocks){
   if(masthead.has(block.id)||program.has(block.id))continue;
   const center=block.box[0]+block.box[2]/2;
   const column=centers.reduce((best,c,i)=>Math.abs(c-center)<Math.abs(centers[best]-center)?i:best,0);
   columns[column].push(block);
  }
  const groups=[],unassigned=[];
  columns.forEach((members,column)=>{
   members.sort((a,b)=>a.box[1]-b.box[1]||a.box[0]-b.box[0]);
   for(const g of rules.displayColumn(members,{articles:page.articles,reviewed:analysis.reviewedGroups,bodyFont:analysis.bodyFontSize||9,columnWidth:analysis.columnWidth,columnCenter:centers[column]})){
    if(!g.basis){unassigned.push(...g.blocks.map(b=>b.id));continue;}
    const blockIds=g.blocks.map(b=>b.id);
    groups.push({id:'g-'+hash([input.issueId,page.pageIndex,blockIds]).slice(0,24),parentId:'column-'+column,column,
     blockIds,basis:g.basis,title:g.title,type:g.type||null,articleId:g.article?.id||null,
     roles:g.roles||[],evidence:g.evidence||null,markdown:g.roles?rules.markdown(g):null});
   }
  });
  const expected=blocks.map(b=>b.id).sort();
  const covered=[...masthead,...program,...unassigned,...groups.flatMap(g=>g.blockIds)].sort();
  if(new Set(expected).size!==expected.length||JSON.stringify(expected)!==JSON.stringify(covered))
   throw new Error(`Source block coverage changed on page ${page.pageIndex}`);
  const byId=new Map(blocks.map(b=>[b.id,b]));
  for(const group of groups)for(const role of group.roles){
   if(!group.blockIds.includes(role.id)||!Number.isInteger(role.start)||!Number.isInteger(role.end)
      ||role.start<0||role.end<=role.start||role.end>byId.get(role.id).text.split('\n').length)
    throw new Error(`Invalid role span in ${group.id}`);
  }
  return {pageIndex:page.pageIndex,analysis,groups,unassignedBlockIds:unassigned,programBlockIds:[...program],mastheadBlockIds:[...masthead]};
 });
 return {schemaVersion:1,issueId:input.issueId,sourceHash:input.sourceHash,algorithmHash:input.algorithmHash,pages};
}
if(require.main===module){const input=JSON.parse(fs.readFileSync(0,'utf8'));process.stdout.write(JSON.stringify(prepare(input)));}
module.exports={prepare};
