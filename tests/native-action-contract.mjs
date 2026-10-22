import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {resolve,join} from 'node:path';
import {pathToFileURL} from 'node:url';
import {createRequire} from 'node:module';
import sourceRegistration from '../node/server/index.mjs';

// Execute the retained native registry itself. No reimplementation of assertDeclared.
// The component placeholders isolate action registration; actual compiled components
// and native customCode SSR/hydration/SPA have their separate seo-runtime check.
const nativeRoot=process.env.GUAQI_SOURCE_ROOT;
if(!nativeRoot)throw new Error('Set GUAQI_SOURCE_ROOT to the retained licensed native client.');
const registryPath=join(resolve(nativeRoot),'layers/base/server/runtime-plugins/registry.js');
const {createRuntimePluginRegistry}=await import(pathToFileURL(registryPath).href);
const {parseRuntimePluginSourceManifest}=await import(pathToFileURL(join(resolve(nativeRoot),'layers/base/server/runtime-plugins/manifest.js')).href);
const sdkRoot=process.env.GUAQI_CLIENT_ROOT||nativeRoot;
const {createRuntimePluginProductionManifest}=await import(pathToFileURL(join(resolve(sdkRoot),'scripts/runtime-plugin-sdk.mjs')).href);
const sourceManifest=parseRuntimePluginSourceManifest(JSON.parse(await readFile(new URL('../manifest.json',import.meta.url),'utf8')));
const clientEntries=Object.fromEntries(sourceManifest.componentReplacements.map(({target},i)=>[target,{entry:`node/client/components/${i}/index.js`}]));
let manifest=createRuntimePluginProductionManifest(sourceManifest,clientEntries);
let server={...sourceRegistration,components:Object.fromEntries(manifest.componentReplacements.map(({target})=>[target,{name:target}]))};
const builtRoot=process.env.GUAQI_TEST_PACKAGE_ROOT;
if(builtRoot){
  manifest=JSON.parse(await readFile(join(builtRoot,'manifest.json'),'utf8'));
  assert.equal(manifest.version,sourceManifest.version,'fixed ZIP does not match current release version');
  assert.deepEqual(manifest.actions,sourceManifest.actions);
  const requireHost=createRequire(join(resolve(sdkRoot),'package.json'));
  globalThis.__GUAQI_RUNTIME_PLUGIN_V3_SERVER__={Vue:requireHost('vue'),VueServerRenderer:requireHost('vue/server-renderer')};
  server=(await import(pathToFileURL(join(builtRoot,manifest.serverEntry)).href)).default;
}
const key=`${manifest.id}@${manifest.version}+${'a'.repeat(64)}`;
const create=(candidate,registration=server)=>createRuntimePluginRegistry({
  loadPackage:async requested=>{
    assert.equal(requested,key);
    return {packageKey:key,manifest:candidate,server:registration};
  },
});
const host=create(manifest);
const prepared=await host.prepareIsolated({frontend:'dashen.wang',generation:168,packageKeys:[key]});
assert.deepEqual(prepared.packageFailures,[],'native registry refused current SEO package');
assert.deepEqual(prepared.snapshot.packageKeys,[key]);
assert.equal(prepared.snapshot.actions['plugin.dashen.seo.head'].owner.packageKey,key);
assert.match(prepared.snapshot.actions['plugin.dashen.seo.head'].run({path:'/es/article/39352'}),/dashenSeoHead/);
assert.equal(host.snapshot('dashen.wang').generation,0,'prepare unexpectedly activated package');
const active=host.activate(prepared.id);
assert.equal(active.generation,168);
assert.equal(typeof host.snapshot('dashen.wang').actions['plugin.dashen.seo.head'].run,'function');

// The real host must catch both the original missing declaration and the inverse.
const absent={...manifest,actions:[]};
await assert.rejects(create(absent).prepare({frontend:'dashen.wang',generation:168,packageKeys:[key]}),/server action is undeclared: plugin\.dashen\.seo\.head/);
await assert.rejects(create(manifest,{...server,actions:{}}).prepare({frontend:'dashen.wang',generation:168,packageKeys:[key]}),/action registration is missing: plugin\.dashen\.seo\.head/);
delete globalThis.__GUAQI_RUNTIME_PLUGIN_V3_SERVER__;
console.log('PASS actual native registry prepareIsolated/activate; executable SEO action; missing declaration and registration rejected');
