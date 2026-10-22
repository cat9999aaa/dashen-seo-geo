import hashlib
import importlib.util
from pathlib import Path
import unittest

spec=importlib.util.spec_from_file_location('sitemap_alias_plan',Path(__file__).resolve().parents[1]/'sitemap-alias-plan.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
class PlanTests(unittest.TestCase):
 def setUp(self):
  self.old_digest=module.BEFORE
  rules='\n'.join(f'    location = {s} {{ return 301 https://dashen.wang{t}; }}' for s,t in module.ALIASES.items()).encode()
  # Includes the same aliases in HTTP plus an unrelated HTTPS vhost.
  self.before=(b'server {\n listen 80;\n'+rules+b'\n}\nserver {\n listen 443 ssl; server_name www.dashen.wang;\n}\n'+module.ANCHOR+rules+b'\n    location = /wp-sitemap-posts-page-1.xml { return 301 https://dashen.wang/sitemap-pages.xml; }\n    location = /robots.txt { alias /srv/dashen/seo/robots.txt; }\n}\n')
  module.BEFORE=hashlib.sha256(self.before).hexdigest()
 def tearDown(self):module.BEFORE=self.old_digest
 def test_only_existing_six_https_aliases(self):
  result=module.project(self.before);offset=self.before.index(module.ANCHOR)
  self.assertEqual(result[:offset],self.before[:offset])
  self.assertEqual(result.count(b'location ='),self.before.count(b'location ='))
  self.assertEqual(result.count(b'rewrite ^ '),6)
  self.assertIn(b'/wp-sitemap-posts-page-1.xml { return 301',result)
  self.assertIn(b'location = /robots.txt { alias /srv/dashen/seo/robots.txt; }',result)
 def test_drift_and_repeat_refused(self):
  for value in (self.before+b'# foreign\n',module.project(self.before)):
   with self.assertRaisesRegex(ValueError,'SHA256'):module.project(value)
 def test_even_rehashed_missing_alias_refused(self):
  value=self.before.replace(b'location = /post-sitemap.xml',b'location = /different.xml')
  module.BEFORE=hashlib.sha256(value).hexdigest()
  with self.assertRaisesRegex(ValueError,'existing HTTPS alias'):module.project(value)
 def test_duplicate_scope_refused(self):
  value=self.before+module.ANCHOR+b'}\n';module.BEFORE=hashlib.sha256(value).hexdigest()
  with self.assertRaisesRegex(ValueError,'unique HTTPS'):module.project(value)
if __name__=='__main__':unittest.main()
