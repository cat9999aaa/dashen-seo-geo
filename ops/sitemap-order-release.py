#!/usr/bin/env python3
"""One reviewed native XML expression; preserve original bundle and code archive."""
import argparse,datetime,fcntl,hashlib,json,os,re,stat,subprocess,time
from pathlib import Path
TARGET=Path('/srv/dashen/node/current/server/chunks/_/nitro.mjs')
EXPECTED='c2645bdedb260e1df2ad2ef386ba42bf7d8ed31dc32218ecf007ab25c5fc6782'
OLD=b'''                <loc>${loc}</loc>${hreflangs.join("")}${xDefault}
                ${lastmod ? `<lastmod>${lastmod}</lastmod>` : ""}'''
NEW=b'''                <loc>${loc}</loc>
                ${lastmod ? `<lastmod>${lastmod}</lastmod>` : ""}${hreflangs.join("")}${xDefault}'''
def digest(b):return hashlib.sha256(b).hexdigest()
def command(args):
 p=subprocess.run(args,capture_output=True)
 if p.returncode:raise ValueError('private command failed')
 return p.stdout

def changed(before):
 if digest(before)!=EXPECTED or before.count(OLD)!=1:raise ValueError('reviewed source identity mismatch')
 after=before.replace(OLD,NEW)
 if len(after)!=len(before):raise ValueError('unexpected renderer length change')
 return after

def write(path,raw):
 with os.fdopen(os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600),'wb')as f:f.write(raw);f.flush();os.fsync(f.fileno())
def record(path,value):write(path,(json.dumps(value,indent=2)+'\n').encode())
def options():
 return command(['mysql','-N','-B','dashen_wp','-e',"SELECT option_name,SHA2(option_value,256) FROM wp_options WHERE option_name IN ('guaqi_runtime_plugin_assignments_v3','guaqi_settings','guaqi_page_settings_dashen.wang','dashen_enhancer_home') ORDER BY option_name"]).decode().splitlines()
def swap(raw,oldsha,meta):
 if TARGET.is_symlink() or digest(TARGET.read_bytes())!=oldsha:raise ValueError('live bundle drift')
 tmp=TARGET.with_name('.sitemap-order-'+os.urandom(8).hex()+'.mjs')
 try:
  write(tmp,raw);os.chown(tmp,meta['uid'],meta['gid']);os.chmod(tmp,meta['mode'])
  if digest(TARGET.read_bytes())!=oldsha:raise ValueError('bundle changed before swap')
  os.replace(tmp,TARGET)
  fd=os.open(TARGET.parent,os.O_RDONLY|os.O_DIRECTORY)
  try:os.fsync(fd)
  finally:os.close(fd)
 finally:
  if tmp.exists():tmp.unlink()
def health():
 for attempt in range(15):
  checks=[]
  for path in ['/','/sitemap-posts-post-1.xml']:
   p=subprocess.run(['curl','--silent','--resolve','dashen.wang:443:127.0.0.1','--max-time','5','--output','/dev/null','--write-out','%{http_code}','https://dashen.wang'+path],capture_output=True)
   checks.append(p.returncode==0 and p.stdout==b'200')
  if all(checks):return True
  time.sleep(1)
 return False

def restore_known(before,p):
 observed=digest(TARGET.read_bytes());sync_verified=True
 if observed==p['after_sha256']:
  try:swap(before,p['after_sha256'],p)
  except Exception:
   # An atomic rename may already have happened before its directory sync failed.
   # Restore service from known old bytes, while retaining the durability limit.
   if digest(TARGET.read_bytes())!=EXPECTED:raise
   sync_verified=False
 elif observed!=EXPECTED:raise ValueError('foreign bundle blocks restoration')
 # The original bytes may already be restored after a failed service restart.
 # Retrying restart is safe; no foreign source or CMS state is overwritten.
 command(['systemctl','restart','dashen-node.service'])
 if not health():raise ValueError('restored application health failed')
 return {'restored':True,'healthy':True,'directory_sync_verified':sync_verified}

def attempt_name(prefix):return prefix+'-'+str(time.time_ns())+'-'+os.urandom(4).hex()+'.json'

def run(mode,d):
 if os.geteuid()!=0 or d.parent!=Path('/root/dashen-backups') or not re.fullmatch('sitemap-order-[0-9TZ]+',d.name):raise ValueError('production private scope required')
 if mode=='prepare':
  before=TARGET.read_bytes();after=changed(before);s=TARGET.stat()
  if not stat.S_ISREG(s.st_mode) or TARGET.is_symlink():raise ValueError('regular bundle required')
  d.mkdir(mode=0o700);write(d/'before.mjs',before);write(d/'after.mjs',after)
  command(['/usr/local/bin/node','--check',str(d/'after.mjs')])
  command(['tar','--exclude=./.data','-C','/srv/dashen/node/current','-czf',str(d/'node-code-before.tar.gz'),'.'])
  if TARGET.read_bytes()!=before:raise ValueError('source changed during code backup')
  plan={'before_sha256':digest(before),'after_sha256':digest(after),'code_archive_sha256':digest((d/'node-code-before.tar.gz').read_bytes()),'options':options(),'uid':s.st_uid,'gid':s.st_gid,'mode':stat.S_IMODE(s.st_mode),'created_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'scope':'loc,lastmod,xhtml order only; code archive excludes live .data'}
  record(d/'plan.json',plan);return {'prepared':True,**{k:v for k,v in plan.items()if k.endswith('sha256')},'production_changed':False}
 if d.is_symlink() or stat.S_IMODE(d.stat().st_mode)!=0o700:raise ValueError('private directory required')
 p=json.loads((d/'plan.json').read_text());before=(d/'before.mjs').read_bytes();after=(d/'after.mjs').read_bytes()
 if digest(before)!=EXPECTED or changed(before)!=after or digest(after)!=p['after_sha256']:raise ValueError('private candidate changed')
 if options()!=p['options']:raise ValueError('assignment or CMS configuration drift')
 if mode=='apply':
  if os.environ.get('DSH_SITEMAP_ORDER_APPLY')!='1':raise ValueError('explicit apply required')
  record(d/'apply-attempt.json',{'at':time.time()})
  try:
   swap(after,EXPECTED,p)
   command(['systemctl','restart','dashen-node.service'])
   if not health():raise ValueError('loopback application health failed')
   if options()!=p['options']:raise ValueError('assignment or CMS drift after restart')
  except BaseException as failure:
   record(d/attempt_name('apply-failure'),{'type':type(failure).__name__,'live_sha256':digest(TARGET.read_bytes())})
   try:
    recovered=restore_known(before,p);record(d/'automatic-rollback.json',recovered)
   except Exception as recovery:
    record(d/attempt_name('automatic-rollback-failure'),{'type':type(recovery).__name__,'live_sha256':digest(TARGET.read_bytes())})
   raise
  result={'applied':True,'bundle_sha256':digest(TARGET.read_bytes()),'healthy':True,'assignment_and_cms_unchanged':True};record(d/'applied.json',result);return result
 if mode=='verify':
  if not (d/'applied.json').is_file() or digest(TARGET.read_bytes())!=p['after_sha256'] or not health():raise ValueError('deployed bundle or health mismatch')
  return {'verified':True,'bundle_sha256':p['after_sha256'],'assignment_and_cms_unchanged':True,'google_processing':'not_proven_by_xml_fix'}
 if mode=='rollback':
  if os.environ.get('DSH_SITEMAP_ORDER_ROLLBACK')!='1':raise ValueError('explicit rollback required')
  record(d/attempt_name('rollback-attempt'),{'at':time.time(),'live_sha256':digest(TARGET.read_bytes())})
  try:
   recovered=restore_known(before,p);record(d/attempt_name('rollback-result'),recovered);return recovered
  except Exception as failure:
   record(d/attempt_name('rollback-failure'),{'type':type(failure).__name__,'live_sha256':digest(TARGET.read_bytes())});raise
 raise ValueError('unknown mode')
if __name__=='__main__':
 os.umask(0o077);a=argparse.ArgumentParser();a.add_argument('mode',choices=['prepare','apply','verify','rollback']);a.add_argument('--release-dir',type=Path,required=True);v=a.parse_args()
 lock=Path('/srv/dashen/node/.dashen-node-release.lock')
 with lock.open('a')as f:
  fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)
  try:print(json.dumps(run(v.mode,v.release_dir)))
  except Exception:print(json.dumps({'ok':False,'reason':'guarded native sitemap operation failed; inspect private release state'}));raise SystemExit(1)
