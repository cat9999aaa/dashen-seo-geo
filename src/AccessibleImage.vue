<template>
  <div
    :class="['el-image overflow-hidden inline-block', attrs.class]"
    :style="attrs.style"
  >
    <img
      v-if="src && !failed"
      v-bind="imageAttrs"
      decoding="async"
      :loading="nativeLoading"
      :fetchpriority="fetchPriority"
      :src="src"
      :style="{ objectFit: fit }"
      class="el-image__inner fade-in-image bg-[--el-color-info-light-10] block w-full h-full"
      @error="failed = true"
    >
    <div
      v-else
      class="el-image__error flex items-center justify-center w-full h-full bg-[--el-color-info-light-10]"
    >
      <i class="i-ph-image-thin opacity-30 gq-gs"></i>
    </div>
  </div>
</template>
<script setup>
import { computed, ref, useAttrs, watch, onMounted, onUnmounted } from "vue";

import { observeAvatarAlternatives } from '../node/avatar-alternatives.mjs';
let stopAvatarObserver;
onMounted(() => { stopAvatarObserver = observeAvatarAlternatives(document); });
onUnmounted(() => stopAvatarObserver?.());
defineOptions({ inheritAttrs: false });

const props = defineProps({
  src: {
    type: String,
    default: "",
  },
  fit: {
    type: String,
    default: "cover",
  },
  priority: {
    type: Boolean,
    default: false,
  },
  lazy: {
    type: Boolean,
    default: true,
  },
});

const failed = ref(false);
const attrs = useAttrs();

const imageAttrs = computed(() => {
  const { class: _class, style: _style, ...rest } = attrs;
  return { alt: '', ...rest };
});

const nativeLoading = computed(() =>
  props.priority ? "eager" : props.lazy ? "lazy" : undefined
);

const fetchPriority = computed(() => (props.priority ? "high" : undefined));

watch(
  () => props.src,
  () => {
    failed.value = false;
  }
);
</script>
