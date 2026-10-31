<script setup>
// U-02 — daftar kartu. Kartu digital (tanpa kartu fisik) selalu di atas dengan tombol Bagikan; bila belum ada
// di workspace ini (mis. workspace perusahaan), tawarkan pembuatannya — gratis dan tidak memakai kuota kartu aktif.
import { ref } from 'vue'
import { Plus, Share2, Smartphone } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import ClaimCardForm from '@/components/cards/ClaimCardForm/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppEmpty from '@/components/ui/AppEmpty/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import CardPreview from '@/components/common/CardPreview/index.vue'
import CardVisual from '@/components/cards/CardVisual/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { cardsApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { relative } from '@/lib/format'

const { data: cards, meta, error, reload } = useAsync(() => cardsApi.list())
const adding = ref(false)
const { busy, run } = useAction()
async function createDigital() {
  const res = await run(() => cardsApi.createDigital(), 'Kartu digital siap dibagikan.').catch(() => null)
  if (res) reload()
}
</script>

<template>
  <PageHeader eyebrow="Kartu" title="Kartu saya">
    <AppButton @click="adding = true"><Plus class="size-5" /> Tambah kartu fisik</AppButton>
  </PageHeader>
  <p v-if="error" class="text-danger" role="alert">{{ error.message }}</p>

  <div v-if="!cards && !error" class="grid gap-3 xl:grid-cols-2"><AppSkeleton v-for="i in 2" :key="i" class="!h-28" /></div>

  <template v-else-if="cards">
    <div v-if="meta && !meta.has_digital" class="flex flex-wrap items-center gap-4 rounded-md border border-line-strong bg-surface-2 px-5 py-4">
      <Smartphone class="size-6 flex-none text-accent-text" aria-hidden="true" />
      <div class="grid min-w-0 flex-1 gap-0.5">
        <span class="font-semibold">Belum punya kartu fisik, atau kartunya ketinggalan?</span>
        <span class="text-sm text-muted">Buat kartu digital: bio link sendiri, QR di layar, dan tautan untuk dibagikan. Gratis, tidak memakai kuota kartu.</span>
      </div>
      <AppButton :loading="busy" @click="createDigital"><Smartphone class="size-5" /> Buat kartu digital</AppButton>
    </div>

    <AppEmpty v-if="!cards.length" text="Belum ada kartu. Tempel kartu baru ke ponsel Anda, atau masukkan kode yang tercetak di kartu.">
      <AppButton @click="adding = true"><Plus class="size-5" /> Tambah kartu fisik</AppButton>
    </AppEmpty>

    <!-- baris ringkas: thumbnail kecil + info + aksi; dua kolom di layar lebar -->
    <div v-else class="grid gap-3 xl:grid-cols-2">
      <article
        v-for="c in cards" :key="c.token"
        class="relative grid grid-cols-[132px_minmax(0,1fr)] items-center gap-4 rounded-md border bg-surface p-3 hover:bg-surface-2 sm:grid-cols-[168px_minmax(0,1fr)_auto]"
        :class="c.form === 'digital' ? 'border-line-strong' : 'border-line'"
      >
        <CardVisual v-if="c.form === 'digital'" :token="c.token" compact />
        <CardPreview v-else small :name="c.profile?.display_name" :label="c.label || c.short_code" />
        <div class="grid min-w-0 gap-1.5">
          <RouterLink :to="`/cards/${c.token}`" class="truncate font-medium text-fg no-underline after:absolute after:inset-0">{{ c.label || 'Kartu ' + c.short_code }}</RouterLink>
          <div class="flex flex-wrap items-center gap-1.5">
            <AppBadge :tone="c.tone">{{ c.status_label }}</AppBadge>
            <AppBadge v-if="c.form === 'digital'" tone="info"><Smartphone class="size-3.5" /> Digital</AppBadge>
          </div>
          <span class="truncate text-sm text-muted">{{ c.profile?.display_name ?? '—' }} · tap terakhir {{ relative(c.last_tap_at) }}</span>
        </div>
        <div v-if="c.status === 'active'" class="relative z-10 col-span-2 flex sm:col-span-1">
          <AppButton :variant="c.form === 'digital' ? 'primary' : 'secondary'" :to="`/cards/${c.token}/share`"><Share2 class="size-5" /> Bagikan</AppButton>
        </div>
      </article>
    </div>
  </template>

  <AppModal v-if="adding" title="Tambah kartu fisik" @close="adding = false">
    <ClaimCardForm @cancel="adding = false" />
  </AppModal>
</template>
