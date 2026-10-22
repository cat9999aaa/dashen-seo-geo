import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFile,writeFile,mkdtemp,rm} from 'node:fs/promises';
import {join,resolve} from 'node:path';
import {tmpdir} from 'node:os';
import {pathToFileURL} from 'node:url';
import {createHead as createServerHead,renderSSRHead} from '@unhead/vue/server';
import {createHead as createClientHead,renderDOMHead} from '@unhead/vue/client';

const host=process.env.GUAQI_CLIENT_ROOT;
if(!host)throw new Error('Set GUAQI_CLIENT_ROOT to the existing licensed native client.');
const nativeSource=process.env.GUAQI_SOURCE_ROOT;
if(!nativeSource)throw new Error('Set GUAQI_SOURCE_ROOT to the matching retained official client source.');
const requireHost=createRequire(join(host,'package.json'));
const {JSDOM}=await import('jsdom');
const dom=new JSDOM('<html><head></head><body><main id="app"></main></body></html>',{url:'https://dashen.wang/article/9'});
for(const key of ['window','document','HTMLElement','Element','SVGElement','Node'])globalThis[key]=dom.window[key];
const Vue=requireHost('vue');
const renderer=requireHost('vue/server-renderer');
const {parse,compileScript}=requireHost('vue/compiler-sfc');
const {default:AdmZip}=await import(pathToFileURL(requireHost.resolve('adm-zip')).href);
const {buildRuntimePlugin}=await import(pathToFileURL(join(host,'scripts/runtime-plugin-sdk.mjs')).href);
const origin='https://dashen.wang';
const links=[{hreflang:'zh-CN',href:origin+'/article/9'},{hreflang:'en-US',href:origin+'/en/article/39342'},{hreflang:'es-ES',href:origin+'/es/article/39356'},{hreflang:'x-default',href:origin+'/article/9'}];
const group=path=>({path,canonical:origin+path,alternates:links});
const response=path=>({data:{guaqi:{common:{module:{dashenSeoHead:JSON.stringify(group(path))}}}}});
const nativeTags=path=>({link:[{rel:'canonical',href:origin+path}],meta:[{property:'og:site_name',content:'大神网'}],script:[{type:'application/ld+json',innerHTML:JSON.stringify({'@context':'https://schema.org','@graph':[{'@type':'Article','@id':path+'/#article',author:{'@type':'Person',name:'大神',url:origin+'/profile/3'},publisher:{'@id':origin+'/#organization'},articleSection:[''],mainEntity:{'@id':path+'/#faq'}},{'@type':'FAQPage','@id':path+'/#faq',mainEntity:[]}]})}]});
const work=await mkdtemp(join(tmpdir(),'dashen-seo-runtime-'));
try{
 const zip=process.env.GUAQI_TEST_ZIP||await buildRuntimePlugin(resolve(import.meta.dirname,'..'),{outputDirectory:join(work,'build')});
 new AdmZip(zip).extractAllTo(join(work,'package'));
 globalThis.__GUAQI_RUNTIME_PLUGIN_V3_SERVER__={Vue,VueServerRenderer:renderer};
 const registration=(await import(pathToFileURL(join(work,'package/node/server/index.mjs')).href)).default;
 const SeoHead=registration.components['components/CommonCustomCode'];
 assert.ok(SeoHead,'SDK omitted native replacement');
 // Compile the actual original component and its original helper; only Nuxt context is supplied by the test harness.
 const filename=join(nativeSource,'layers/base/app/components/common/customCode.vue');
 const {descriptor}=parse(await readFile(filename,'utf8'),{filename});
 let source=compileScript(descriptor,{id:'native-custom-code',inlineTemplate:true}).content;
 source=source.replaceAll('from "vue"',`from ${JSON.stringify(pathToFileURL(join(host,'node_modules/vue/index.mjs')).href)}`)
  .replace('"../../../shared/customCode.js"',JSON.stringify(pathToFileURL(join(nativeSource,'layers/base/shared/customCode.js')).href));
 source=`const useNuxtApp=()=>globalThis.__seoNuxt;const useRoute=()=>globalThis.__seoRoute;const useHead=input=>globalThis.__seoHead.push(typeof input==='function'?input():input);\n`+source;
 await writeFile(join(work,'native.mjs'),source);
 const Native=(await import(pathToFileURL(join(work,'native.mjs')).href)).default;
 const route=Vue.shallowRef({path:'/article/9',meta:{pageSettings:{locale:'zh-hans'}}});
 let calls=0;
 const runtime={router:{currentRoute:route},components:{resolve:name=>{assert.equal(name,'common/customCode');return Native;}},actions:{invoke:async(_action,{path})=>{calls++;return response(path);}}};
 function setup(head,component=SeoHead,server=true){
  globalThis.__seoHead=head;globalThis.__seoRoute=route.value;
  globalThis.__seoNuxt={$serverSettings:{_customCode:{version:1,head:[{tag:'style',attrs:{id:'native-custom-style'},content:'.preserve-native{color:red}'}]}}};
  const app=(server?Vue.createSSRApp:Vue.createApp)({render:()=>Vue.h(component)});app.use(head);app.config.globalProperties.$runtimePluginHost=runtime;
  if(server)app.config.globalProperties.$nuxt={ssrContext:{event:{$fetch:async(url,options)=>{
   assert.equal(url,'/api/request');assert.equal(options.headers.host,'dashen.wang');
   assert.equal(options.body.action,'plugin.dashen.seo.head');calls++;return response(options.body.data.path);
  }}}};
  return app;
 }
 const serverHead=createServerHead();serverHead.push(nativeTags(route.value.path));
 await renderer.renderToString(setup(serverHead));
 const {headTags}=renderSSRHead(serverHead);
 const parsed=new JSDOM('<html><head>'+headTags+'</head><body></body></html>').window.document;
 assert.equal(parsed.querySelectorAll('link[hreflang]').length,4,headTags);
 assert.equal(parsed.querySelector('style#native-custom-style').textContent,'.preserve-native{color:red}');
 assert.deepEqual(JSON.parse(parsed.querySelector('#dashen-seo-metadata').textContent),group('/article/9'));
 const graph=JSON.parse(parsed.querySelector('script[type="application/ld+json"]').textContent)['@graph'];
 assert.equal(graph.find(x=>x['@type']==='Organization').name,'大神网');
 assert.equal(graph[0]['@id'],origin+'/article/9/#article');assert.equal(calls,1);
 assert.equal(graph[0].mainEntity['@id'],graph.find(n=>n['@type']==='FAQPage')['@id'],'SSR removed a valid native FAQ reference');
 console.log('PASS official SDK + original native customCode + Unhead SSR initial HTML');

 document.head.innerHTML=headTags;
 const builtManifest=JSON.parse(await readFile(join(work,'package/manifest.json'),'utf8'));
 let clientRegistration;
 globalThis.__GUAQI_RUNTIME_PLUGIN_V3__={Vue,register:entry=>{clientRegistration=entry;}};
 await import(pathToFileURL(join(work,'package',builtManifest.clientComponents['components/CommonCustomCode'].entry)).href);
 const ClientSeo=clientRegistration.components['components/CommonCustomCode'];
 const clientHead=createClientHead({document});const nativeEntry=clientHead.push(nativeTags('/article/9'));
 const app=setup(clientHead,ClientSeo,false);app.mount(document.getElementById('app'));
 await Vue.nextTick();renderDOMHead(clientHead,{document});
 assert.equal(calls,1,'hydration repeated the SSR metadata request');
 assert.equal(document.querySelectorAll('link[hreflang]').length,4,document.head.innerHTML);
 const pending=new Map();runtime.actions.invoke=(_name,{path})=>new Promise(resolve=>pending.set(path,resolve));
 route.value={path:'/en/article/39342',meta:{pageSettings:{locale:'en'}}};nativeEntry.patch(nativeTags(route.value.path));await Vue.nextTick();
 route.value={path:'/article/76',meta:{pageSettings:{locale:'zh-hans'}}};nativeEntry.patch(nativeTags(route.value.path));await Vue.nextTick();
 pending.get('/en/article/39342')(response('/en/article/39342'));await Promise.resolve();await Vue.nextTick();renderDOMHead(clientHead,{document});
 assert.equal(document.querySelectorAll('link[hreflang]').length,0,'stale navigation leaked previous translation links');
 pending.get('/article/76')({data:{guaqi:{common:{module:{dashenSeoHead:'null'}}}}});await Promise.resolve();await Vue.nextTick();renderDOMHead(clientHead,{document});
 assert.equal(document.querySelectorAll('link[hreflang]').length,0);
 assert.equal(document.querySelector('style#native-custom-style').textContent,'.preserve-native{color:red}');
 app.unmount();console.log('PASS hydration seed, SPA stale-response protection and native custom code preservation');
}finally{delete globalThis.__GUAQI_RUNTIME_PLUGIN_V3_SERVER__;await rm(work,{recursive:true,force:true});}
