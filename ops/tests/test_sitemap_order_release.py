import importlib.util,json,os,stat,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
SOURCE=Path(__file__).resolve().parents[1]/'sitemap-order-release.py'
spec=importlib.util.spec_from_file_location('sitemap_order',SOURCE);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class ReleaseTests(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name);self.d=self.root/'sitemap-order-20261022T000000Z';self.d.mkdir(mode=0o700);self.target=self.root/'nitro.mjs';self.before=b'fixture\n'+m.OLD+b'\n';self.after=self.before.replace(m.OLD,m.NEW);self.target.write_bytes(self.before);s=self.target.stat();self.plan={'after_sha256':m.digest(self.after),'options':['fixture'],'uid':s.st_uid,'gid':s.st_gid,'mode':stat.S_IMODE(s.st_mode)}
  for name,data in [('before.mjs',self.before),('after.mjs',self.after),('plan.json',json.dumps(self.plan).encode())]:(self.d/name).write_bytes(data)
  self.calls=[];original=Path
  self.patches=[patch.object(m,'TARGET',self.target),patch.object(m,'EXPECTED',m.digest(self.before)),patch.object(m,'Path',side_effect=lambda s:self.root if str(s)=='/root/dashen-backups' else original(s)),patch.object(m.os,'geteuid',return_value=0),patch.object(m,'options',return_value=['fixture']),patch.object(m,'health',return_value=True),patch.object(m,'command',side_effect=lambda args:self.calls.append(args) or b''),patch.dict(os.environ,{'DSH_SITEMAP_ORDER_APPLY':'1','DSH_SITEMAP_ORDER_ROLLBACK':'1'})]
  for p in self.patches:p.start()
 def tearDown(self):
  for p in reversed(self.patches):p.stop()
  self.temp.cleanup()
 def test_post_rename_directory_sync_failure_restores_and_restarts(self):
  original=os.fsync
  def fail(fd):
   if stat.S_ISDIR(os.fstat(fd).st_mode):raise OSError('fixture directory sync')
   original(fd)
  with patch.object(m.os,'fsync',side_effect=fail):
   with self.assertRaises(OSError):m.run('apply',self.d)
  self.assertEqual(self.target.read_bytes(),self.before);self.assertEqual(len(self.calls),1)
  r=json.loads((self.d/'automatic-rollback.json').read_text());self.assertTrue(r['restored']);self.assertFalse(r['directory_sync_verified']);self.assertEqual(len(list(self.d.glob('apply-failure-*.json'))),1)
 def test_rollback_transient_restart_is_retryable_with_append_only_evidence(self):
  self.target.write_bytes(self.after);attempts=[]
  def restart(args):
   attempts.append(args)
   if len(attempts)==1:raise ValueError('fixture transient restart')
   return b''
  with patch.object(m,'command',side_effect=restart):
   with self.assertRaises(ValueError):m.run('rollback',self.d)
   history={p.name:m.digest(p.read_bytes())for p in self.d.glob('rollback-*.json')}
   self.assertTrue(m.run('rollback',self.d)['restored'])
  self.assertEqual(len(attempts),2);self.assertEqual(self.target.read_bytes(),self.before)
  for name,digest in history.items():self.assertEqual(m.digest((self.d/name).read_bytes()),digest)
  self.assertEqual(len(list(self.d.glob('rollback-attempt-*.json'))),2)
 def test_foreign_bundle_is_never_overwritten_or_restarted(self):
  self.target.write_bytes(b'foreign')
  with self.assertRaises(ValueError):m.run('rollback',self.d)
  self.assertEqual(self.target.read_bytes(),b'foreign');self.assertFalse(self.calls)
 def test_configuration_drift_stops_before_mutation(self):
  with patch.object(m,'options',return_value=['other']):
   with self.assertRaises(ValueError):m.run('apply',self.d)
  self.assertEqual(self.target.read_bytes(),self.before);self.assertFalse((self.d/'apply-attempt.json').exists())
 def test_health_failure_rolls_back_after_mutation(self):
  with patch.object(m,'health',side_effect=[False,True]):
   with self.assertRaises(ValueError):m.run('apply',self.d)
  self.assertEqual(self.target.read_bytes(),self.before);self.assertEqual(len(self.calls),2)
 def test_successful_apply_verify_and_explicit_rollback(self):
  self.assertTrue(m.run('apply',self.d)['applied']);self.assertTrue(m.run('verify',self.d)['verified']);self.assertTrue(m.run('rollback',self.d)['restored']);self.assertEqual(self.target.read_bytes(),self.before)
if __name__=='__main__':unittest.main()
