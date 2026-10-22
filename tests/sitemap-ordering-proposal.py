"""Replay the real plain-bundle renderer against frozen public XML; no network."""
from pathlib import Path
from tempfile import TemporaryDirectory
from urllib.parse import urlsplit
import argparse, hashlib, json, re, subprocess, xml.etree.ElementTree as ET

ap=argparse.ArgumentParser();ap.add_argument('--evidence',type=Path,required=True);ap.add_argument('--source-json',type=Path,required=True);ap.add_argument('--patch',type=Path);ap.add_argument('--report',type=Path);a=ap.parse_args()
N='{http://www.sitemaps.org/schemas/sitemap/0.9}';X='{http://www.w3.org/1999/xhtml}';origin='https://dashen.wang';default='zh-hans'
col=json.loads((a.evidence/'collection.json').read_text())
for name,digest in col['files_sha256'].items():assert hashlib.sha256((a.evidence/name).read_bytes()).hexdigest()==digest,name
source=json.loads(a.source_json.read_text());fragment=source['renderer_fragment']
assert hashlib.sha256(fragment.encode()).hexdigest()==source['renderer_fragment_sha256']
assert source['bundle_sha256']=='c2645bdedb260e1df2ad2ef386ba42bf7d8ed31dc32218ecf007ab25c5fc6782'
index=ET.parse(a.evidence/'maps/sitemap-index.xml').getroot();names=['sitemap-index.xml']+[e.findtext(N+'loc').rsplit('/',1)[-1]for e in index]
assert len(names)==53
static_name='sitemap-users-1.xml'
assert hashlib.sha256((a.evidence/'maps'/static_name).read_bytes()).hexdigest()==source['static_user_control']['public_xml_sha256']
names.remove(static_name)
inputs=[]
for name in names:
 raw=(a.evidence/'maps'/name).read_bytes();root=ET.fromstring(raw);rows=[]
 for row in root:
  item={'url':row.findtext(N+'loc').removeprefix(origin),'lastmod':row.findtext(N+'lastmod') or '', 'ids':[]}
  if root.tag==N+'urlset':
   for link in row.findall(X+'link'):
    if link.attrib['hreflang']=='x-default':continue
    path=link.attrib['href'].removeprefix(origin);first=path.lstrip('/').split('/',1)[0]
    lang=first if first in ['en','es','ja','ko','zh-hant','zh-hans']else default
    slug=path[len('/'+lang):]if lang!=default else path
    item['ids'].append({'lang':lang,'locale':link.attrib['hreflang'],'slug':slug})
  rows.append(item)
 inputs.append({'name':name,'type':'index'if root.tag==N+'sitemapindex'else name[len('sitemap-'):-4],'res':{'data':{'guaqi':{'sitemap':{'currentLang':default,'list':rows}}}}})
node=r'''
let raw='';for await(const part of process.stdin)raw+=part;
const {source,escape,inputs}=JSON.parse(raw);
const escapeXml=new Function(`${escape};return escapeXml`)();
const render=new Function('res','type','origin','defaultLang','escapeXml',`let str='';${source};return str;`);
process.stdout.write(JSON.stringify(inputs.map(x=>({name:x.name,xml:render(x.res,x.type,'https://dashen.wang','zh-hans',escapeXml)}))));
'''
def render(text):
 r=subprocess.run(['node','--input-type=module','-e',node],input=json.dumps({'source':text,'escape':source['escape_xml_function'],'inputs':inputs}),text=True,capture_output=True,check=True);return json.loads(r.stdout)
def nodes(xml):
 root=ET.fromstring(xml);return (root.tag,tuple(root.attrib.items()),[[ (x.tag,tuple(x.attrib.items()),x.text or '')for x in row]for row in root])
# Reconstruction is accepted only if the original renderer exactly replays all HTTP bytes.
old=render(fragment)
for item in old:assert item['xml'].encode()==(a.evidence/'maps'/item['name']).read_bytes(),('native replay differs from frozen HTTP bytes',item['name'])
with TemporaryDirectory(prefix='dashen-ordering-')as tmp:
 tmp=Path(tmp);candidate=fragment
 if a.patch:
  (tmp/'renderer-fragment.js').write_text(fragment)
  p=subprocess.run(['patch','--batch','--forward','-p1','-d',str(tmp),'-i',str(a.patch.resolve())],text=True,capture_output=True);assert p.returncode==0,p.stdout+p.stderr
  candidate=(tmp/'renderer-fragment.js').read_text()
 rendered=render(candidate);rendered.append({'name':static_name,'xml':(a.evidence/'maps'/static_name).read_text()});passed=0;rows=0;values=0;results=[]
 for item in rendered:
  name=item['name'];before=(a.evidence/'maps'/name).read_bytes();after=item['xml'].encode();bp=nodes(before);cp=nodes(after)
  assert len(before)==len(after),('byte count changed',name)
  assert bp[:2]==cp[:2],('root namespace/attrs changed',name)
  assert re.search(rb'<(?:urlset|sitemapindex)\b[^>]*>',before).group()==re.search(rb'<(?:urlset|sitemapindex)\b[^>]*>',after).group(),('raw namespace declaration changed',name)
  assert len(bp[2])==len(cp[2]),('URL count changed',name)
  for oldrow,newrow in zip(bp[2],cp[2]):
   assert sorted(oldrow)==sorted(newrow),('URL/date/alternate/attribute changed',name)
   assert oldrow[0]==newrow[0],('loc changed/moved',name)
   values+=len(oldrow)
  # Raw element bytes, attributes, escaping and field values are preserved exactly.
  token=rb'<loc>[^<]*</loc>|<lastmod>[^<]*</lastmod>|<xhtml:link\b[^>]*?/>'
  assert sorted(re.findall(token,before))==sorted(re.findall(token,after)),('raw field token changed',name)
  if name=='sitemap-index.xml':assert before==after,'index changed'
  path=tmp/name;path.write_bytes(after)
  schema='combined-index.xsd'if name=='sitemap-index.xml'else'combined-sitemap.xsd'
  result=subprocess.run(['xmllint','--nonet','--noout','--schema',str(a.evidence/'schemas'/schema),str(path)],capture_output=True,text=True)
  passed+=result.returncode==0;rows+=len(cp[2]);results.append({'name':name,'xsd_pass':result.returncode==0,'before_sha256':hashlib.sha256(before).hexdigest(),'after_sha256':hashlib.sha256(after).hexdigest(),'bytes':len(after)})
 # Static control is outside this dynamic renderer and must remain unchanged/valid.
 p=a.evidence/'maps/sitemap-pages.xml';r=subprocess.run(['xmllint','--nonet','--noout','--schema',str(a.evidence/'schemas/sitemap.xsd'),str(p)],capture_output=True);assert r.returncode==0
 report={'offline_only':True,'production_bundle_sha256':source['bundle_sha256'],'exact_original_http_replay':len(old),'dynamic_children':51,'dynamic_children_xsd_pass':passed-2,'static_user_child_xsd_pass':results[-1]['xsd_pass'],'all_children':52,'all_children_xsd_pass':passed-1,'index_xsd_pass':results[0]['xsd_pass'],'static_control_xsd_pass':True,'row_count_including_index':rows,'field_count':values,'raw_tokens_url_date_namespace_count_and_length_preserved':True,'patched':bool(a.patch),'files':results}
 if a.report:a.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
 print(json.dumps({k:v for k,v in report.items()if k!='files'}))
 assert passed==53,('child XSD failures',53-passed)
