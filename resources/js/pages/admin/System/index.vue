<script setup>
// PL-16 — kesehatan sistem (ambang Redis TRD §3.2) & pengaturan umum.
import { reactive, watch } from 'vue'
import { CircleAlert } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'

const { data } = useAsync(() => adminApi.system())
const { busy, run } = useAction()
const form = reactive({ brand_name: '', support_email: '', support_whatsapp: '' })
watch(data, (d) => d && Object.assign(form, d.settings))

async function save() {
  const res = await run(() => adminApi.saveSettings(form), 'Pengaturan disimpan.').catch(() => null)
  if (res) data.value = res.data
}
</script>

<template>
  <PageHeader title="Kesehatan sistem" />
  <AppSkeleton v-if="!data" class="!h-60" />
  <template v-else>
    <p v-if="data.health.redis_recommended" class="flex gap-3 rounded-md border border-line-strong bg-surface-2 px-4 py-3 text-sm">
      <CircleAlert class="size-5 flex-none text-warn" />
      <span>Ambang skala terlewati. Pertimbangkan memasang Redis: ubah <span class="font-mono">CACHE_STORE</span>, <span class="font-mono">QUEUE_CONNECTION</span>, <span class="font-mono">SESSION_DRIVER</span> ke <span class="font-mono">redis</span> lalu restart worker.</span>
    </p>
    <div class="grid gap-4 md:grid-cols-2">
      <AppPanel title="Pemeriksaan">
        <ul>
          <li v-for="c in data.health.checks" :key="c.label" class="flex items-center gap-3 border-b border-line py-3 last:border-b-0">
            <AppBadge :tone="c.ok ? 'ok' : 'warn'">{{ c.ok ? 'OK' : 'Cek' }}</AppBadge>
            <div class="min-w-0 flex-1"><span class="block font-medium">{{ c.label }}</span><span v-if="!c.ok && c.hint" class="text-sm text-muted">{{ c.hint }}</span></div>
            <span class="font-mono text-sm">{{ c.value }}</span>
          </li>
        </ul>
        <p v-if="data.health.drivers" class="mt-3 text-sm text-muted">
          Driver: cache <span class="font-mono">{{ data.health.drivers.cache }}</span> · queue <span class="font-mono">{{ data.health.drivers.queue }}</span> · session <span class="font-mono">{{ data.health.drivers.session }}</span>
        </p>
      </AppPanel>
      <AppPanel title="Pengaturan umum">
        <form class="grid gap-4" @submit.prevent="save">
          <AppField label="Nama brand"><AppInput v-model="form.brand_name" :disabled="!data.can_manage" required /></AppField>
          <AppField label="Email support"><AppInput v-model="form.support_email" type="email" :disabled="!data.can_manage" /></AppField>
          <AppField label="WhatsApp support"><AppInput v-model="form.support_whatsapp" :disabled="!data.can_manage" /></AppField>
          <div v-if="data.can_manage"><AppButton type="submit" :loading="busy">Simpan</AppButton></div>
        </form>
      </AppPanel>
    </div>
  </template>
</template>
