/* Presentation groups: reviewed membership first, then contiguous saved membership. */
function groupColumn(blocks, {articles=[],reviewed=[]}={}){
    const membership=new Map();
    for(const article of articles)for(const ref of article.blocks){
        if(!membership.has(ref.id))membership.set(ref.id,article);
        else membership.set(ref.id,null); // Ambiguous ownership stays ungrouped.
    }
    const result=[];
    for(let i=0;i<blocks.length;){
        const first=blocks[i];
        const review=reviewed.find(g=>g.blockIds.length>1&&g.blockIds.every((id,n)=>blocks[i+n]?.id===id));
        if(review){
            result.push({blocks:blocks.slice(i,i+review.blockIds.length),basis:'reviewed opening',title:first.text.replace(/\s+/g,' ').trim(),type:review.type,article:null});
            i+=review.blockIds.length;continue;
        }
        const article=membership.get(first.id),members=[first];
        if(article){
            while(blocks[i+members.length]&&membership.get(blocks[i+members.length].id)===article
                &&!reviewed.some(g=>g.blockIds[0]===blocks[i+members.length].id))members.push(blocks[i+members.length]);
        }
        result.push({blocks:members,basis:members.length>1?'saved article':null,
            title:article?.title||first.text.replace(/\s+/g,' ').trim(),type:article?.type,article});
        i+=members.length;
    }
    return result;
}
function proposeColumn(blocks,{bodyFont=9,columnWidth=900,columnCenter=450}={}){
    const letters=text=>(text.match(/\p{L}/gu)||[]).length;
    const caps=text=>letters(text)>=4&&text===text.toUpperCase();
    const lineHeight=b=>b.box[3]/Math.max(1,b.text.split('\n').length);
    const short=b=>b.text.trim().length<=150&&b.text.split('\n').length<=3&&letters(b.text)>=4;
    function lead(b){
        const lines=b.text.split('\n');
        // A leading all-caps line inside a longer block is evidence for a
        // proposed heading, not permission to discard the rest of that block.
        if(lines.length>=4&&caps(lines[0])&&lines[0].length<=100&&letters(lines.slice(1).join(' '))>=50)return 'embedded';
        if(!short(b))return null;
        const centered=Math.abs(b.box[0]+b.box[2]/2-columnCenter)<columnWidth*.14;
        const narrow=b.box[2]<columnWidth*.72;
        const titleCase=b.text.trim().split(/\s+/u).every(w=>!/^\p{L}/u.test(w)||/^\p{Lu}/u.test(w));
        if((b.fontSize||0)>=bodyFont*1.5&&(centered||caps(b.text)))return 'display';
        if(centered&&narrow&&b.text.split('\n').length<=2&&(caps(b.text)||titleCase))return 'centered';
        return null;
    }
    const adjacent=(a,b,factor=.18)=>{
        const gap=b.box[1]-a.box[1]-a.box[3];
        const overlap=Math.min(a.box[0]+a.box[2],b.box[0]+b.box[2])-Math.max(a.box[0],b.box[0]);
        return gap>=-Math.min(a.box[3],b.box[3])*.1&&gap<=columnWidth*factor&&overlap>=Math.min(a.box[2],b.box[2])*.65;
    };
    const body=b=>letters(b.text)>=40&&b.box[2]>=columnWidth*.55;
    const groups=[];
    for(let i=0;i<blocks.length;){
        const first=blocks[i],kind=lead(first),members=[first],roles=[];
        if(!kind){groups.push({blocks:members,basis:null,title:first.text,article:null});i++;continue;}
        const lines=first.text.split('\n');
        roles.push({id:first.id,role:'headline',start:0,end:kind==='embedded'?1:lines.length});
        if(kind==='embedded'){
            let start=1;
            if(/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2}\b/i.test(lines[1])){roles.push({id:first.id,role:'dateline',start:1,end:2});start=2;}
            roles.push({id:first.id,role:'body',start,end:lines.length});
        }else{
            let next=blocks[i+1];
            if(next&&adjacent(first,next)&&body(next)){
                const subhead=next.text.split('\n').length<=3&&next.text.length<=200&&next.fontSize>bodyFont*1.15&&next.fontSize<first.fontSize;
                if(subhead){
                    const deck=[next];
                    let cursor=i+2;
                    while(blocks[cursor]&&deck.length<4){
                        const fragment=blocks[cursor],previous=deck.at(-1);
                        const gap=fragment.box[1]-previous.box[1]-previous.box[3];
                        const centered=Math.abs(fragment.box[0]+fragment.box[2]/2-columnCenter)<columnWidth*.14;
                        const beside=fragment.text.split('\n').length===1&&fragment.box[0]>=previous.box[0]+previous.box[2]
                            &&fragment.box[0]-previous.box[0]-previous.box[2]<columnWidth*.08
                            &&Math.abs(fragment.box[1]-previous.box[1])<lineHeight(previous)*.35;
                        if(fragment.text.length>200||fragment.text.split('\n').length>3
                            ||Math.abs(fragment.fontSize-next.fontSize)>bodyFont*.12
                            ||(!beside&&(!centered||gap< -lineHeight(previous)*.5||gap>lineHeight(previous)*.8)))break;
                        deck.push(fragment);cursor++;
                    }
                    const following=blocks[cursor];
                    if(following&&adjacent(deck.at(-1),following)&&body(following)&&(following.fontSize||bodyFont)<=bodyFont*1.15){
                        for(const fragment of deck){members.push(fragment);roles.push({id:fragment.id,role:'subhead',start:0,end:fragment.text.split('\n').length});}
                        next=following;
                    }
                }
                if(!lead(next)||members.length>1){members.push(next);roles.push({id:next.id,role:'body',start:0,end:next.text.split('\n').length});}
            }
        }
        const hasBody=roles.some(r=>r.role==='body');
        if(!hasBody){groups.push({blocks:[first],basis:null,title:first.text,article:null});i++;continue;}
        let previous=members.at(-1),next=blocks[i+members.length];
        while(next&&!lead(next)&&body(next)&&Math.abs((next.fontSize||bodyFont)-(previous.fontSize||bodyFont))<=bodyFont*.12
            &&adjacent(previous,next,.055)&&next.box[1]-previous.box[1]-previous.box[3]<=lineHeight(previous)*.6){
            members.push(next);roles.push({id:next.id,role:'body',start:0,end:next.text.split('\n').length});previous=next;next=blocks[i+members.length];
        }
        groups.push({blocks:members,basis:'proposed opening',title:kind==='embedded'?lines[0]:first.text.replace(/\s+/g,' '),type:null,article:null,roles,
            evidence:kind==='embedded'?'Leading all-caps line inside body block':kind==='centered'?'Short centered title above aligned body':'Display-size heading above aligned text'});
        i+=members.length;
    }
    return groups;
}
// Preserve established groups; propose only within the remaining runs.
function displayColumn(blocks, options={}){
    const saved=groupColumn(blocks,options),result=[];
    for(let i=0;i<saved.length;){
        if(saved[i].basis){result.push(saved[i++]);continue;}
        const run=[];
        while(i<saved.length&&!saved[i].basis)run.push(...saved[i++].blocks);
        result.push(...proposeColumn(run,options));
    }
    // A saved one-block article is still an article. Give proposals first chance
    // to recover an opening, then expose unambiguous saved membership for leftovers.
    return result.map(group=>{
        if(group.basis||group.blocks.length!==1)return group;
        const articles=(options.articles||[]).filter(article=>article.blocks.some(ref=>ref.id===group.blocks[0].id));
        return articles.length===1?{...group,basis:'saved article',title:articles[0].title,type:articles[0].type,article:articles[0]}:group;
    });
}
function storedColumn(blocks, persisted, articles=[]){
    const byId=new Map(blocks.map(b=>[b.id,b])),starts=new Map(),claimed=new Set();
    for(const group of persisted.groups){
        if(!group.blockIds.every(id=>byId.has(id)))continue;
        starts.set(group.blockIds[0],group);
        group.blockIds.forEach(id=>claimed.add(id));
    }
    return blocks.flatMap(block=>{
        const group=starts.get(block.id);
        if(group)return [{...group,blocks:group.blockIds.map(id=>byId.get(id)),article:articles.find(a=>a.id===group.articleId)||null}];
        return claimed.has(block.id)?[]:[{blocks:[block],basis:null,title:block.text,article:null}];
    });
}
function readingParts(group){
    const byId=new Map(group.blocks.map(b=>[b.id,b])),parts=[];
    for(const part of group.roles||[]){
        const b=byId.get(part.id),lines=b.text.split('\n');
        const text=lines.slice(part.start,part.end).map((line,j)=>((b.paragraphStarts||[]).includes(j+part.start)&&j>0?'\n\n':'')+line).join(' ').trim();
        const previous=parts.at(-1);
        if(part.role==='subhead'&&previous?.role==='subhead'){
            previous.sources.push(part);
            // A short OCR fragment beside a deck belongs on that source line,
            // not after the last line of the subhead.
            const first=byId.get(previous.sources[0].id);
            const beside=b.box[0]>=first.box[0]+first.box[2]&&Math.abs(b.box[1]-first.box[1])<first.box[3]/first.text.split('\n').length*.35;
            if(beside){
                const opening=first.text.split('\n')[0].trim();
                previous.text=previous.text.slice(0,opening.length)+' '+text+previous.text.slice(opening.length);
            }else previous.text+=' '+text;
        }else parts.push({role:part.role,text,sources:[part]});
    }
    return parts;
}
function markdown(group){
    return readingParts(group).map(part=>(part.role==='headline'?'## ':part.role==='subhead'?'### ':'')+part.text).join('\n\n');
}
function sourceRegion(blocks,width,height){
    const left=Math.max(0,Math.min(...blocks.map(b=>b.box[0]))),top=Math.max(0,Math.min(...blocks.map(b=>b.box[1])));
    const right=Math.min(width,Math.max(...blocks.map(b=>b.box[0]+b.box[2]))),bottom=Math.min(height,Math.max(...blocks.map(b=>b.box[1]+b.box[3])));
    return {left,top,width:Math.max(0,right-left),height:Math.max(0,bottom-top),widthPercent:Math.max(0,right-left)/width*100,heightPercent:Math.max(0,bottom-top)/height*100};
}
export { storedColumn,groupColumn,proposeColumn,displayColumn,readingParts,markdown,sourceRegion };
