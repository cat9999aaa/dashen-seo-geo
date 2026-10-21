<script setup>
import {getCurrentInstance,onMounted,onServerPrefetch,onUnmounted,provide,shallowRef,watch} from 'vue';
import {injectHead} from '@unhead/vue';
import NativeCustomCode from '@guaqi/components/common/customCode';
import {ARTICLE_PATH,encodeJson,repairSeoTags,translationHeadLinks,unwrapHeadMetadata} from '../node/seo-head.mjs';

// Static replacements are outside RuntimePluginsModule's provide boundary.
// Nuxt exposes this host-provided runtime through Vue globalProperties.
const globals=getCurrentInstance()?.appContext.config.globalProperties;
const runtime=globals?.$runtimePluginHost;
if(!runtime)throw new Error('Native runtime plugin host is unavailable');
provide(Symbol.for('guaqi.runtime-plugin.v3'),runtime);
// The client action invoker uses global $fetch. SSR needs the current H3 event
// and explicit host so an internal request cannot select the localhost frontend.
const requestFetch=globals?.$nuxt?.ssrContext?.event?.$fetch;
const head=injectHead();
const metadata=shallowRef(null);
// The seed contains public URLs only and avoids repeating the SSR request at hydration.
if(typeof document!=='undefined'){
  try{const seed=JSON.parse(document.getElementById('dashen-seo-metadata')?.textContent||'null');if(seed?.path===runtime.router.currentRoute.value.path)metadata.value=seed;}catch{}
}
const metadataInput=()=>({link:translationHeadLinks(metadata.value,runtime.router.currentRoute.value.path),script:[{id:'dashen-seo-metadata',type:'application/json',innerHTML:encodeJson(metadata.value)}]});
const metadataEntry=head.push(metadataInput());
let generation=0;
const stop=head.hooks.hook('tags:resolve',context=>{
  const route=runtime.router.currentRoute.value;
  context.tags=repairSeoTags(context.tags,{path:route.path,locale:route.meta?.pageSettings?.locale||'',metadata:metadata.value});
});
async function load(path){
  const ticket=++generation;metadata.value=null;
  metadataEntry.patch(metadataInput());
  if(!ARTICLE_PATH.test(path))return;
  try{
    const response=requestFetch
      ? await requestFetch('/api/request',{method:'POST',headers:{host:'dashen.wang'},body:{action:'plugin.dashen.seo.head',data:{path},renderVersion:runtime.router.currentRoute.value.meta?.pageSettings?.renderVersion||''}})
      : await runtime.actions.invoke('plugin.dashen.seo.head',{path});
    if(ticket===generation&&runtime.router.currentRoute.value.path===path)metadata.value=unwrapHeadMetadata(response);
  }catch{ /* Metadata transport failure leaves the native page usable. */ }
  if(ticket===generation)metadataEntry.patch(metadataInput());
}
onServerPrefetch(()=>load(runtime.router.currentRoute.value.path));
onMounted(()=>{if(metadata.value?.path!==runtime.router.currentRoute.value.path)void load(runtime.router.currentRoute.value.path);});
watch(()=>runtime.router.currentRoute.value.path,path=>{void load(path);});
onUnmounted(()=>{generation++;stop();metadataEntry.dispose();});
</script>
<template><NativeCustomCode /></template>
