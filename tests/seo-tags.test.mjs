import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
const target = new URL('../node/seo-head.mjs', import.meta.url);
const api = existsSync(target) ? await import(target) : {};
const origin='https://dashen.wang';
const group={path:'/article/9',canonical:origin+'/article/9',alternates:[{hreflang:'zh-CN',href:origin+'/article/9'},{hreflang:'en-US',href:origin+'/en/article/39342'},{hreflang:'x-default',href:origin+'/article/9'}]};
const ld=()=>({'@context':'https://schema.org','@graph':[{'@type':'Article','@id':'/article/9/#article',author:{'@type':'Person',name:'大神',url:origin+'/profile/3'},publisher:{'@id':origin+'/#organization'},articleSection:[''],mainEntity:{'@id':'/article/9/#faq'}},{'@type':'FAQPage','@id':origin+'/article/9/#faq',mainEntity:[]}]});
const tags=()=>[{tag:'link',props:{rel:'canonical',href:origin+'/article/9'}},{tag:'meta',props:{property:'og:site_name',content:'大神网'}},{tag:'script',props:{type:'application/ld+json'},innerHTML:JSON.stringify(ld())}];
test('head repair supplies reciprocal published languages and resolvable schema entities',()=>{
 assert.equal(typeof api.repairSeoTags,'function','native head repair is missing');
 const out=api.repairSeoTags(tags(),{path:'/article/9',metadata:group});
 assert.deepEqual(api.translationHeadLinks(group,'/article/9').map(a=>[a.hreflang,a.href]),group.alternates.map(a=>[a.hreflang,a.href]));
 const graph=JSON.parse(out.find(t=>t.tag==='script').innerHTML)['@graph'];
 assert.equal(graph[0]['@id'],origin+'/article/9/#article');
 assert.equal(graph[0].mainEntity['@id'],graph[1]['@id']);
 assert.equal('articleSection' in graph[0],false);
 assert.equal(graph.find(n=>n['@id']===origin+'/#organization').name,'大神网');
 assert.equal(graph[0].author['@type'],'Person');
});
test('localized home uses itself without adding fabricated language equivalents',()=>{
 const inTags=[{tag:'link',props:{rel:'canonical',href:origin}},{tag:'meta',props:{property:'og:url',content:origin}},{tag:'script',props:{type:'application/ld+json'},innerHTML:JSON.stringify({'@graph':[{'@type':'Organization','@id':origin+'/#organization',name:'UME'}]})},{tag:'meta',props:{property:'og:site_name',content:'大神网'}}];
 const out=api.repairSeoTags(inTags,{path:'/en',locale:'en'});
 assert.equal(out[0].props.href,origin+'/en');assert.equal(out[1].props.content,origin+'/en');
 assert.equal(JSON.parse(out[2].innerHTML)['@graph'][0].name,'大神网');
 assert.equal(out.filter(t=>t.props.hreflang).length,0);
});
test('stale metadata, foreign hosts, untrusted URLs and unrelated custom JSON are preserved safely',()=>{
 const custom={tag:'script',props:{type:'application/ld+json',id:'custom'},innerHTML:'{"@type":"Organization","name":"Actual custom publisher"}'};
 const source=[...tags(),custom];const before=JSON.stringify(source);
 const out=api.repairSeoTags(source,{path:'/article/76',metadata:group});
 assert.equal(api.translationHeadLinks(group,'/article/76').length,0);assert.equal(JSON.stringify(source),before);
 assert.equal(out.at(-1).innerHTML,custom.innerHTML);
 const foreign=[{tag:'link',props:{rel:'canonical',href:'https://other.example/article/9'}}];
 assert.deepEqual(api.repairSeoTags(foreign,{path:'/article/9',metadata:group}),foreign);
 const bad={...group,alternates:[{hreflang:'en-US',href:'javascript:alert(1)'}]};
 assert.equal(api.translationHeadLinks(bad,'/article/9').length,0);
});
test('native relative FAQ entities are normalized before checking article references',()=>{
 const input=tags();const value=ld();value['@graph'][1]['@id']='/article/9/#faq';
 input[2].innerHTML=JSON.stringify(value);
 const graph=JSON.parse(api.repairSeoTags(input)[2].innerHTML)['@graph'];
 assert.equal(graph[1]['@id'],origin+'/article/9/#faq');
 assert.equal(graph[0].mainEntity?.['@id'],graph[1]['@id'],'valid native FAQ reference was removed');
});
test('publisher ID is opaque and does not permit rewriting a Person named UME',()=>{
 const input=tags();const value=ld();value['@graph'].push({'@type':'Person','@id':origin+'/#organization',name:'UME'});
 input[2].innerHTML=JSON.stringify(value);
 const graph=JSON.parse(api.repairSeoTags(input)[2].innerHTML)['@graph'];
 assert.equal(graph.find(n=>n['@type']==='Person').name,'UME');
});
