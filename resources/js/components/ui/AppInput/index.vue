<script setup>
// DBD §11 Input — tinggi 48, isian ≥ 16 px, batas --line-strong; error = garis --danger.
defineOptions({ inheritAttrs: false })
const model = defineModel({ type: [String, Number], default: '' })
defineProps({ invalid: Boolean, code: Boolean })
</script>

<template>
  <input
    v-model="model"
    v-bind="$attrs"
    :aria-invalid="invalid || undefined"
    :class="[
      'min-h-12 w-full rounded-md border bg-surface px-4 py-3 text-base text-fg placeholder:text-muted disabled:opacity-60',
      invalid ? 'border-danger' : 'border-line-strong',
      code ? 'text-center font-mono text-[22px] font-medium tracking-[.3em] uppercase' : '',
    ]"
  />
</template>
