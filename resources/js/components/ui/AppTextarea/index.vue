<script setup>
defineOptions({ inheritAttrs: false })
const model = defineModel({ type: String, default: '' })
defineProps({ invalid: Boolean })
</script>

<template>
  <textarea
    v-model="model"
    v-bind="$attrs"
    :class="['min-h-24 w-full resize-y rounded-md border bg-surface px-4 py-3 text-base text-fg placeholder:text-muted', invalid ? 'border-danger' : 'border-line-strong']"
  ></textarea>
</template>
