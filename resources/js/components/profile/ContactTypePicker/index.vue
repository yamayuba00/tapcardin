<script setup>
// Pilih jenis kontak baru: grid per grup (Kontak utama, Pesan, Sosial, Bisnis, Toko, Pembayaran, Lainnya) + cari.
import { computed, ref } from 'vue'
import { Search } from 'lucide-vue-next'
import AppModal from '@/components/ui/AppModal/index.vue'
import { contactIcon } from '@/lib/contactIcons'

const props = defineProps({ types: { type: Array, required: true } })
const emit = defineEmits(['pick', 'close'])
const q = ref('')
const groups = computed(() => {
  const s = q.value.trim().toLowerCase()
  const out = []
  for (const t of props.types) {
    if (s && !`${t.label} ${t.group_name}`.toLowerCase().includes(s)) continue
    let g = out.find((x) => x.key === t.group)
    if (!g) out.push((g = { key: t.group, name: t.group_name, items: [] }))
    g.items.push(t)
  }
  return out
})
</script>

<template>
  <AppModal title="Tambah kontak atau tautan" description="Pilih jenisnya — isian & ikon menyesuaikan otomatis." size="lg" @close="emit('close')">
    <div class="relative">
      <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
      <input v-model="q" type="search" autofocus placeholder="Cari, mis. Instagram, Shopee, rekening…" aria-label="Cari jenis kontak"
        class="h-11 w-full rounded-md border border-line-strong bg-bg pr-3 pl-10 text-fg outline-none focus:border-accent" />
    </div>
    <section v-for="g in groups" :key="g.key" class="grid gap-2">
      <h3 class="label">{{ g.name }}</h3>
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
        <button
          v-for="t in g.items" :key="t.key" type="button"
          class="flex min-h-12 cursor-pointer items-center gap-3 rounded-md border border-line px-3 text-left text-sm hover:border-accent hover:bg-surface-2"
          @click="emit('pick', t)"
        >
          <component :is="contactIcon(t.icon)" class="size-5 flex-none text-accent-text" aria-hidden="true" />
          <span class="truncate">{{ t.label }}</span>
        </button>
      </div>
    </section>
    <p v-if="!groups.length" class="py-6 text-center text-muted">Tidak ada jenis yang cocok.</p>
  </AppModal>
</template>
