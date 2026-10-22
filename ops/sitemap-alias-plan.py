#!/usr/bin/env python3
"""Local exact-byte candidate only. This tool never installs/reloads nginx or SSHs."""
import argparse
import difflib
import hashlib
import json
import os
from pathlib import Path
import stat

BEFORE='8bf9cd76c7e42ef968e88903749aba86373f66629d57ba2073b4bb31213afc3c'
ANCHOR=b'server {\n    listen 443 ssl;\n    listen [::]:443 ssl;\n    server_name dashen.wang;\n'
ALIASES={
 '/sitemap.xml':'/sitemap-index.xml', '/wp-sitemap.xml':'/sitemap-index.xml',
 '/post-sitemap.xml':'/sitemap-index.xml',
 **{f'/wp-sitemap-posts-post-{i}.xml':f'/sitemap-posts-post-{i}.xml' for i in (1,2,3)}
}
def sha(data):return hashlib.sha256(data).hexdigest()
def require(ok,message):
 if not ok:raise ValueError(message)
def project(before):
 require(sha(before)==BEFORE,'unexpected before configuration SHA256')
 require(before.count(ANCHOR)==1,'unique HTTPS main-site scope required')
 offset=before.index(ANCHOR);prefix=before[:offset];tail=before[offset:]
 require(b'\nserver {' not in tail,'unexpected server after pinned main-site scope')
 for source,target in ALIASES.items():
  old=f'    location = {source} {{ return 301 https://dashen.wang{target}; }}'.encode()
  new=f'    location = {source} {{ rewrite ^ {target} last; }}'.encode()
  require(tail.count(old)==1,'exact existing HTTPS alias required: '+source)
  require(new not in tail,'candidate already present')
  tail=tail.replace(old,new,1)
 after=prefix+tail
 require(after.count(b'location = ')==before.count(b'location = '),'location count changed')
 require(after[:offset]==prefix,'non-HTTPS-main scope changed')
 return after
def exclusive(path,data):
 fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
 with os.fdopen(fd,'wb') as handle:handle.write(data)
def main():
 p=argparse.ArgumentParser(description=__doc__);p.add_argument('--before',type=Path,required=True)
 p.add_argument('--out-dir',type=Path,required=True);args=p.parse_args()
 st=args.before.lstat();require(stat.S_ISREG(st.st_mode) and not args.before.is_symlink(),'regular local before file required')
 out=args.out_dir;out.mkdir(mode=0o700);require(out.stat().st_mode&0o777==0o700,'private output directory required')
 before=args.before.read_bytes();after=project(before)
 patch=''.join(difflib.unified_diff(before.decode().splitlines(True),after.decode().splitlines(True),
  fromfile='a/etc/nginx/sites-available/dashen',tofile='b/etc/nginx/sites-available/dashen',n=0))
 exclusive(out/'after.conf',after);exclusive(out/'candidate.patch',patch.encode())
 result={'beforeSHA256':sha(before),'afterSHA256':sha(after),'aliasCount':len(ALIASES),
  'scope':'existing exact alias locations inside HTTPS dashen.wang only',
  'aliases':ALIASES,'newLocations':0,'mode':'local plan only; no production writes/reload'}
 exclusive(out/'plan.json',(json.dumps(result,indent=2)+'\n').encode());print(json.dumps(result))
if __name__=='__main__':main()
