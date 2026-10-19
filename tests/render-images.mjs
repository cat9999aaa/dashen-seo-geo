import assert from 'node:assert/strict';
import { mkdtemp, cp, readFile, writeFile, rm, mkdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';

// Run against the actual official SDK and native components, not a mock HTML hook.
const host = process.env.GUAQI_CLIENT_ROOT || '/mnt/workspace/客户A社群网站/site/node/client';
const requireHost = createRequire(join(host, 'package.json'));
const Vue = requireHost('vue');
const renderer = requireHost('vue/server-renderer');
const { parse, compileScript } = requireHost('vue/compiler-sfc');
const { load } = requireHost('cheerio');
const { default: AdmZip } = await import(pathToFileURL(requireHost.resolve('adm-zip')).href);
const { buildRuntimePlugin } = await import(pathToFileURL(join(host, 'scripts/runtime-plugin-sdk.mjs')).href);
const { transformRuntimePluginAutoComponents } = await import(pathToFileURL(join(host, 'scripts/runtime-plugin-component-transform.mjs')).href);
const directory = resolve(import.meta.dirname, '..');
const work = await mkdtemp(join(tmpdir(), 'dashen-image-alt-'));
try {
  const source = join(work, 'source');
  await cp(directory, source, { recursive: true, filter: p => !p.includes('/dist/') });
  const manifest = JSON.parse(await readFile(join(source, 'manifest.json'), 'utf8'));
  manifest.componentReplacements = [
    { target: 'components/Image', component: 'src/AccessibleImage.vue' },
    { target: 'components/CommonAvatar', component: 'src/AccessibleAvatar.vue' },
  ];
  await writeFile(join(source, 'manifest.json'), JSON.stringify(manifest));
  const zip = await buildRuntimePlugin(source, { outputDirectory: join(work, 'build') });
  const unpacked = join(work, 'unpacked');
  new AdmZip(zip).extractAllTo(unpacked);
  globalThis.__GUAQI_RUNTIME_PLUGIN_V3_SERVER__ = { Vue, VueServerRenderer: renderer };
  const registration = (await import(pathToFileURL(join(unpacked, 'node/server/index.mjs')).href)).default;
  assert.deepEqual(Object.keys(registration.components).sort(), ['components/CommonAvatar', 'components/Image']);

  // Compile the real native components with Vue's official compiler.
  const componentFiles = {
    Image: 'layers/base/app/components/Image.vue',
    'common/avatar': 'layers/base/app/components/common/avatar.vue',
  };
  const originals = {};
  for (const [name, relative] of Object.entries(componentFiles)) {
    const filename = join(host, relative);
    const { descriptor } = parse(await readFile(filename, 'utf8'), { filename });
    let compiled = compileScript(descriptor, { id: name, inlineTemplate: true }).content;
    compiled = compiled.replace(/from ["']vue["']/g, `from ${JSON.stringify(pathToFileURL(requireHost.resolve('vue/dist/vue.runtime.esm-bundler.js')).href)}`);
    compiled = compiled.replace(/from ["']\.\.\/\.\.\/\.\.\/\.\.\/\.\.\/config\/moduleUnit.mjs["']/, `from ${JSON.stringify(pathToFileURL(join(host, 'config/moduleUnit.mjs')).href)}`);
    if (name === 'common/avatar') compiled = `import { computed, useAttrs } from ${JSON.stringify(pathToFileURL(requireHost.resolve('vue/dist/vue.runtime.esm-bundler.js')).href)};\n` + compiled;
    // Use the same CJS Vue runtime the server bridge exposes.
    compiled = compiled.replaceAll(pathToFileURL(requireHost.resolve('vue/dist/vue.runtime.esm-bundler.js')).href, pathToFileURL(join(host, 'node_modules/vue/index.mjs')).href);
    const file = join(work, name.replaceAll('/', '-') + '.mjs');
    await writeFile(file, compiled);
    originals[name] = (await import(pathToFileURL(file).href)).default;
  }
  const ElAvatar = requireHost('element-plus').ElAvatar;
  async function render(target, attrs) {
    const app = Vue.createSSRApp({ render: () => Vue.h(registration.components[target], attrs) });
    // Sign-in and ordinary static components receive no plugin runtime injection.
    app.component('el-avatar', ElAvatar);
    return renderer.renderToString(app);
  }
  const imgs = html => load(html)('img').toArray();
  for (const target of ['components/Image', 'components/CommonAvatar']) {
    for (const attrs of [{}, { alt: '' }, { alt: '作者 "大神" <图> & 内容' }]) {
      const html = await render(target, { src: 'https://example.test/image.webp', ...attrs });
      const nodes = imgs(html);
      assert.equal(nodes.length, 1, html);
      assert.equal(nodes[0].attribs.alt, attrs.alt ?? '');
      assert.equal(nodes[0].attribs.src, 'https://example.test/image.webp');
    }
  }
  const compiledHost = transformRuntimePluginAutoComponents('_resolveComponent("Image"); _resolveComponent("CommonAvatar")', {
    rootDir: host,
    id: join(host, 'layers/base/app/components/example.vue'),
    mode: 'server', resolverPath: 'native-resolver',
    components: [
      { pascalName: 'Image', filePath: join(host, componentFiles.Image) },
      { pascalName: 'CommonAvatar', filePath: join(host, componentFiles['common/avatar']) },
    ],
  });
  assert.ok(compiledHost.code.includes('components/Image'));
  assert.ok(compiledHost.code.includes('components/CommonAvatar'));
  console.log('PASS: official SDK package, native SSR Image/CommonAvatar, escaping, explicit alt preservation, official static replacement targets.');
} finally {
  delete globalThis.__GUAQI_RUNTIME_PLUGIN_V3_SERVER__;
  await rm(work, { recursive: true, force: true });
}
