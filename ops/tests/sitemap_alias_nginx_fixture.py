#!/usr/bin/env python3
"""Owned network-none nginx1.24 fixture; never contacts production or CMS copies."""
import argparse
import hashlib
import json
import pathlib
import re
import shutil
import subprocess
import tempfile
import time
import uuid

ALIASES = {
 '/sitemap.xml':'/sitemap-index.xml', '/wp-sitemap.xml':'/sitemap-index.xml',
 '/post-sitemap.xml':'/sitemap-index.xml',
 **{f'/wp-sitemap-posts-post-{i}.xml':f'/sitemap-posts-post-{i}.xml' for i in (1,2,3)}
}
IMAGE='dashen-recovery-runtime:ubuntu24-php83-20261022'
def run(args, **kw):return subprocess.run(args,capture_output=True,**kw)
def digest(b):return hashlib.sha256(b).hexdigest()
def exercise(private, replacement=None):
 name='seo-alias-fixture-'+uuid.uuid4().hex[:12]
 with tempfile.TemporaryDirectory(prefix='seo-alias-owned-') as folder:
  owned=pathlib.Path(folder);etc=owned/'etc';fixtures=owned/'fixture';fs=owned/'fs'
  shutil.copytree(private/'source/etc/nginx',etc/'nginx')
  fixtures.mkdir();(fixtures/'maps').mkdir();(fs/'srv/dashen/seo').mkdir(parents=True)
  for p in (private/'maps').glob('*.xml'):
   shutil.copyfile(p,fixtures/'maps'/p.name);(fixtures/'maps'/p.name).chmod(0o644)
  shutil.copyfile(private/'maps/sitemap-pages.xml',fs/'srv/dashen/seo/sitemap-pages.xml')
  (fs/'srv/dashen/seo/robots.txt').write_text('User-agent: *\nAllow: /\n\nSitemap: https://dashen.wang/sitemap-index.xml\nSitemap: https://dashen.wang/sitemap-pages.xml\n')
  if replacement:(etc/'nginx/sites-enabled/dashen').write_bytes(replacement.read_bytes())
  # Only isolated TLS key/cert paths differ from the actual exported config tree.
  for p in (etc/'nginx').rglob('*'):
   if p.is_file():
    text=p.read_text()
    text=re.sub(r'(?m)^(\s*ssl_certificate\s+)[^;]+;',r'\1/fixture/cert.pem;',text)
    text=re.sub(r'(?m)^(\s*ssl_certificate_key\s+)[^;]+;',r'\1/fixture/key.pem;',text)
    p.write_text(text)
  cp=run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
          '-subj','/CN=isolated-fixture.invalid','-keyout',str(fixtures/'key.pem'),'-out',str(fixtures/'cert.pem')])
  assert cp.returncode==0,'fixture certificate failed'
  mock=['server {','listen 127.0.0.1:3000;','server_name localhost;']
  for p in (fixtures/'maps').glob('*.xml'):
   mock += [f'location = /{p.name} {{ alias /fixture/maps/{p.name}; default_type application/xml;',
            'add_header X-Fixture-Args $args always; }']
  mock+=['location / { return 404; }','}']
  (etc/'nginx/conf.d/zz-isolated-canonical-fixture.conf').write_text('\n'.join(mock)+'\n')
  base=['docker','run','--rm','--network','none','--read-only',
        '--tmpfs','/run','--tmpfs','/var/log/nginx','--tmpfs','/var/lib/nginx','--tmpfs','/tmp',
        '--mount',f'type=bind,src={etc}/nginx,dst=/etc/nginx,readonly',
        '--mount',f'type=bind,src={fixtures},dst=/fixture,readonly',
        '--mount',f'type=bind,src={fs}/srv,dst=/srv,readonly','--entrypoint','nginx']
  check=run([*base,IMAGE,'-t']);assert check.returncode==0,check.stderr.decode(errors='replace')
  try:
   cp=run([*base,'-d','--name',name,IMAGE,'-g','daemon off;']);assert cp.returncode==0,'owned nginx start failed'
   def get(host,path,tls=True):
    port='443' if tls else '80';scheme='https' if tls else 'http'
    cp=run(['docker','exec',name,'curl','-sS','--insecure','--noproxy','*','--http1.1',
            '--max-time','5','--resolve',f'{host}:{port}:127.0.0.1','-D','/dev/stderr',
            '-w','%{stderr}\nSTATUS%{http_code}',f'{scheme}://{host}{path}'])
    head,sep,status=cp.stderr.decode(errors='replace').rpartition('STATUS');assert sep and cp.returncode==0,'owned request failed'
    headers={}
    for line in head.splitlines():
     if ':' in line:
      k,v=line.split(':',1)
      if k.lower() in ('location','content-type','x-fixture-args'):headers[k.lower()]=v.strip()
    return {'status':int(status),'sha256':digest(cp.stdout),'bytes':len(cp.stdout),'headers':headers}
   for _ in range(40):
    cp=run(['docker','exec',name,'curl','-sk','--noproxy','*','--max-time','1','https://127.0.0.1/robots.txt'])
    if cp.returncode==0:break
    time.sleep(.1)
   else:raise AssertionError('owned nginx readiness failed')
   rows=[]
   for alias,target in ALIASES.items():
    expected=digest((private/'maps'/target.lstrip('/')).read_bytes())
    for query in ('','?v=1&next=%2Fprivate%3Fx%3D1'):
     r=get('dashen.wang',alias+query);r.update({'host':'dashen.wang','path':alias+query,'target':target,
       'matchesCanonicalBytes':r['sha256']==expected,'expectedSHA256':expected});rows.append(r)
   negatives=[]
   for path in ['/sitemap.xml/extra','/sitemap.xmlx','/nested/sitemap.xml','/wp-sitemap-posts-post-4.xml',
                '/wp-sitemap-posts-page-1.xml','/post-sitemap3.xml']:
    r=get('dashen.wang',path);r.update({'host':'dashen.wang','path':path});negatives.append(r)
   for host,tls in [('dashen.wang',False),('www.dashen.wang',True),('i.dashen.wang',True),('pan.dashen.wang',True)]:
    r=get(host,'/sitemap.xml',tls);r.update({'host':host,'tls':tls,'path':'/sitemap.xml'});negatives.append(r)
   controls=[]
   for path in ['/robots.txt','/sitemap-index.xml','/sitemap-posts-post-1.xml','/sitemap-pages.xml']:
    r=get('dashen.wang',path);r['path']=path;controls.append(r)
   return {'nginxVersion':'1.24.0 (Ubuntu)','nginxConfigTest':'PASS','isolation':{'network':'none','hostPorts':False,'tlsFixtureOnly':True,'canonicalBackend':'public XML fixture, no Node/PHP/database'},'aliasRequests':rows,'negativeRequests':negatives,'controls':controls,'allAliases200SameBytes':all(r['status']==200 and r['matchesCanonicalBytes'] for r in rows)}
  finally:run(['docker','rm','-f',name])

if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--private-source',type=pathlib.Path,required=True)
 p.add_argument('--candidate',type=pathlib.Path);p.add_argument('--output',type=pathlib.Path,required=True)
 p.add_argument('--baseline',type=pathlib.Path)
 p.add_argument('--expect-fixed',action='store_true');args=p.parse_args()
 result=exercise(args.private_source,args.candidate);args.output.write_text(json.dumps(result,indent=2)+'\n')
 print(json.dumps({'nginxConfigTest':result['nginxConfigTest'],'aliasRequests':len(result['aliasRequests']),
                   'aliases200SameBytes':result['allAliases200SameBytes'],'negativeRequests':len(result['negativeRequests'])}))
 if args.expect_fixed:
  assert result['allAliases200SameBytes'],'existing submitted aliases must return200/canonical bytes'
  assert all(r['headers'].get('x-fixture-args')=='v=1&next=%2Fprivate%3Fx%3D1' for r in result['aliasRequests'] if '?' in r['path']),'query forwarding changed'
  if args.baseline:
   baseline=json.loads(args.baseline.read_text())
   assert baseline['negativeRequests']==result['negativeRequests'],'unrelated path/host routing changed'
   assert baseline['controls']==result['controls'],'canonical sitemap/robots changed'
