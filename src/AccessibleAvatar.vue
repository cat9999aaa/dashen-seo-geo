<template>
  <span v-bind="outerAttrs" :class="['el-avatar', `el-avatar--${shape}`, typeof size === 'string' ? `el-avatar--${size}` : '']" :style="[attrs.style, sizeStyle]">
    <img v-if="(src || srcSet) && !failed" :src="src" :srcset="srcSet" :alt="alt" :style="{ objectFit: fit }" @error="onError">
    <slot v-else />
  </span>
</template>
<script setup>
import { computed, ref, useAttrs, watch } from 'vue';
defineOptions({ inheritAttrs: false });
const props = defineProps({
  src: { type: String, default: '' }, srcSet: String,
  alt: { type: String, default: '' }, fit: { type: String, default: 'cover' },
  shape: { type: String, default: 'circle' }, size: [Number, String],
});
const emit = defineEmits(['error']);
const attrs = useAttrs();
const failed = ref(false);
const outerAttrs = computed(() => { const { style, ...rest } = attrs; return rest; });
const sizeStyle = computed(() => {
  if (typeof props.size !== 'number') return undefined;
  if (!Number.isFinite(props.size) || props.size < 0) throw new TypeError('avatar size must be a non-negative finite number');
  return { '--el-avatar-size': props.size === 0 ? '0' : `calc(var(--gq-module-unit, 1rem) * ${props.size / 16})` };
});
watch(() => props.src, () => { failed.value = false; });
function onError(event) { failed.value = true; emit('error', event); }
</script>
