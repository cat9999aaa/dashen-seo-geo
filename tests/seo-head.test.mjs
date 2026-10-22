import test from 'node:test';
import assert from 'node:assert/strict';
import registration from '../node/server/index.mjs';

test('public SEO action validates route before compiling a read-only query', () => {
  assert.equal(typeof registration.actions?.['plugin.dashen.seo.head'], 'function', 'public SEO action is missing');
  const run=registration.actions['plugin.dashen.seo.head'];
  assert.match(run({path:'/es/article/39356'}), /dashenSeoHead/);
  for(const path of ['/wp-admin','//evil.test/article/9','/article/9?preview=true','/article/9\"}){users{nodes{email}}}','/es/article/0']) {
    assert.throws(()=>run({path}), /Invalid public article path/);
  }
});

test('release manifest authorizes exactly the server action exported by this package', async () => {
  const {readFile}=await import('node:fs/promises');
  const manifest=JSON.parse(await readFile(new URL('../manifest.json',import.meta.url),'utf8'));
  assert.deepEqual(manifest.actions,Object.keys(registration.actions));
});
