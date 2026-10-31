<script setup>
// DBD §11 Button — primary / secondary / ghost / danger; tinggi 44 (56 untuk lg).
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { LoaderCircle } from 'lucide-vue-next'

const props = defineProps({
  variant: { type: String, default: 'primary' },
  size: { type: String, default: 'md' },
  to: { type: [String, Object], default: null },
  href: { type: String, default: null },
  type: { type: String, default: 'button' },
  block: Boolean,
  icon: Boolean,
  loading: Boolean,
  disabled: Boolean,
})

const variants = {
  primary: 'bg-accent text-accent-ink hover:opacity-90',
  secondary: 'border border-line-strong text-fg hover:bg-surface-2',
  ghost: 'text-accent-text hover:bg-surface-2',
  danger: 'bg-solid-danger text-on-solid hover:opacity-90',
}

const classes = computed(() => [
  'inline-flex cursor-pointer items-center justify-center gap-2 rounded-md font-semibold no-underline transition active:scale-[.98] select-none',
  'disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
  props.size === 'lg' ? 'min-h-14 px-6 text-base' : 'min-h-11 px-5 text-[15px]',
  props.icon ? 'w-11 !px-0' : '',
  props.variant === 'ghost' && !props.icon ? '!px-3' : '',
  props.block ? 'w-full' : '',
  variants[props.variant],
])

const tag = computed(() => (props.to ? RouterLink : props.href ? 'a' : 'button'))
</script>

<template>
  <component
    :is="tag"
    :class="classes"
    :to="to || undefined"
    :href="href || undefined"
    :type="tag === 'button' ? type : undefined"
    :disabled="tag === 'button' ? disabled || loading : undefined"
    :aria-disabled="tag !== 'button' && disabled ? 'true' : undefined"
  >
    <LoaderCircle v-if="loading" class="size-5 animate-spin" aria-hidden="true" />
    <slot />
  </component>
</template>
