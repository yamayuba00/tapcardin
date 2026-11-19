<script setup>
// Daftar notifikasi (lonceng & halaman Notifikasi): ikon per kelompok, teks, waktu relatif, tautan tujuan.
// Membuka satu notifikasi = tandai terbaca. `filter` = kelompok (tap | lead | billing | order | system) atau ''.
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Bell, CreditCard, Nfc, Package, TriangleAlert, UserPlus } from 'lucide-vue-next'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import { useNotificationStore } from '@/stores/notifications'
import { group, link, message } from '@/lib/notifications'
import { relative } from '@/lib/format'

const props = defineProps({ filter: { type: String, default: '' }, compact: Boolean })
const emit = defineEmits(['navigate'])
const store = useNotificationStore()
const router = useRouter()
const ICON = { tap: Nfc, lead: UserPlus, billing: CreditCard, order: Package, system: Bell }

const rows = computed(() => store.items
  .filter((n) => !props.filter || group(n.type) === props.filter)
  .map((n) => ({ ...n, text: message(n.type, n.data) ?? 'Pemberitahuan', to: link(n.type, n.data), icon: n.type === 'lost_card_tapped' ? TriangleAlert : ICON[group(n.type)] })))

onMounted(() => store.load())

function open(n) {
  store.markRead(n.id)
  if (!n.to) return
  emit('navigate')
  router.push(n.to)
}
</script>

<template>
  <div class="grid gap-1">
    <template v-if="!store.loaded">
      <AppSkeleton v-for="i in 4" :key="i" class="!h-16" />
    </template>
    <p v-else-if="!rows.length" class="px-3 py-10 text-center text-sm text-muted">
      {{ filter ? 'Belum ada notifikasi jenis ini.' : 'Belum ada notifikasi. Saat kartu Anda di-tap atau ada lead baru, kabarnya muncul di sini.' }}
    </p>
    <ul v-else class="grid gap-0.5" aria-label="Notifikasi">
      <li v-for="n in rows" :key="n.id">
        <button
          type="button"
          :class="['flex w-full cursor-pointer items-start gap-3 rounded-md px-3 py-3 text-left hover:bg-surface-2', n.read ? '' : 'bg-surface-2/60']"
          @click="open(n)"
        >
          <span :class="['mt-0.5 grid size-9 flex-none place-items-center rounded-full bg-surface-2', n.type === 'lost_card_tapped' ? 'text-danger' : 'text-accent-text']" aria-hidden="true">
            <component :is="n.icon" class="size-[18px]" />
          </span>
          <span class="grid min-w-0 flex-1 gap-0.5">
            <span :class="['text-sm leading-snug', n.read ? 'text-muted' : 'font-medium text-fg', compact ? 'line-clamp-2' : '']">{{ n.text }}</span>
            <span class="font-mono text-xs text-muted">{{ relative(n.at) }}</span>
          </span>
          <span v-if="!n.read" class="mt-2 size-2 flex-none rounded-full bg-accent" aria-label="Belum dibaca"></span>
        </button>
      </li>
    </ul>
    <button
      v-if="store.loaded && store.hasMore && rows.length" type="button" :disabled="store.loading"
      class="mx-auto mt-1 cursor-pointer rounded-sm px-3 py-2 text-sm text-accent-text hover:bg-surface-2 disabled:opacity-50" @click="store.load(true)"
    >{{ store.loading ? 'Memuat…' : 'Muat lebih banyak' }}</button>
  </div>
</template>
