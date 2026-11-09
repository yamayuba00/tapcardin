<script setup>
// Identitas workspace non-personal: jenis organisasi + logo. Logo tampil di bio & halaman kartu anggota, dan otomatis
// mengisi slot logo template di Design Studio semua anggota (brand kit workspace).
import { ref } from 'vue'
import { ImageUp, Trash2 } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import { workspaceBrandApi } from '@/api/workspace'
import { useAsync } from '@/composables/useAsync'
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()
const { data: brand } = useAsync(() => workspaceBrandApi.get())
const busy = ref(false)

async function call(fn, message) {
  busy.value = true
  try {
    brand.value = (await fn()).data
    if (message) toast.ok(message)
  } catch (e) { toast.push(e.message) } finally { busy.value = false }
}
function upload(e) {
  const f = e.target.files?.[0]
  e.target.value = ''
  if (f) call(() => workspaceBrandApi.logo(f), 'Logo diunggah — kartu anggota ikut memakai logo ini')
}
</script>

<template>
  <AppPanel title="Identitas organisasi">
    <AppSkeleton v-if="!brand" class="h-32" />
    <div v-else class="grid gap-4">
      <AppField label="Jenis organisasi">
        <AppSelect :model-value="brand.org_kind" :options="brand.org_kinds" :disabled="!brand.can_edit || busy" @update:model-value="(v) => call(() => workspaceBrandApi.update({ org_kind: v }), 'Disimpan')" />
      </AppField>
      <div class="grid gap-2">
        <span class="text-sm font-medium text-muted">Logo {{ brand.org_kind_label.toLowerCase() }}</span>
        <div class="flex flex-wrap items-center gap-3">
          <span class="grid h-20 w-32 place-items-center overflow-hidden rounded-sm border border-line bg-surface-2 p-2">
            <img v-if="brand.logo_url" :src="brand.logo_url" :alt="`Logo ${brand.name}`" class="max-h-full max-w-full object-contain" />
            <span v-else class="text-xs text-muted">Belum ada logo</span>
          </span>
          <template v-if="brand.can_edit">
            <label class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-md border border-line-strong px-4 text-sm font-semibold hover:bg-surface-2" :class="busy && 'pointer-events-none opacity-60'">
              <ImageUp class="size-4" /> {{ brand.logo_url ? 'Ganti logo' : 'Unggah logo' }}
              <input class="sr-only" type="file" accept="image/png,image/jpeg,image/webp" :disabled="busy" @change="upload" />
            </label>
            <AppButton v-if="brand.logo_url" variant="ghost" icon aria-label="Hapus logo" title="Hapus logo" :loading="busy" @click="call(() => workspaceBrandApi.removeLogo(), 'Logo dihapus')"><Trash2 class="size-5" /></AppButton>
          </template>
        </div>
        <span class="text-xs text-muted">PNG transparan ≥ 1000 px. Logo tampil di halaman kartu & bio anggota, dan otomatis terpasang di template kartu bertanda logo.</span>
        <span v-if="!brand.can_edit" class="text-xs text-muted">Hanya admin workspace yang bisa mengganti logo.</span>
      </div>
    </div>
  </AppPanel>
</template>
