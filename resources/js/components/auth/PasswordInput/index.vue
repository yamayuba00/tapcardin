<script setup>
// Input password dengan tombol tampil/sembunyi.
import { ref } from 'vue'
import { Eye, EyeOff } from 'lucide-vue-next'
import AppInput from '@/components/ui/AppInput/index.vue'

const model = defineModel({ type: String, default: '' })
defineProps({ autocomplete: { type: String, default: 'current-password' }, invalid: Boolean, placeholder: String })
const show = ref(false)
</script>

<template>
  <div class="relative">
    <AppInput v-model="model" :type="show ? 'text' : 'password'" :autocomplete="autocomplete" :placeholder="placeholder" class="pr-12" :invalid="invalid" v-bind="$attrs" />
    <button type="button" class="cursor-pointer absolute inset-y-0 right-0 grid w-12 place-items-center text-muted hover:text-fg" :aria-label="show ? 'Sembunyikan password' : 'Tampilkan password'" @click="show = !show">
      <component :is="show ? EyeOff : Eye" class="size-5" />
    </button>
  </div>
</template>
