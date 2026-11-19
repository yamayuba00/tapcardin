<script setup>
// Lonceng notifikasi: lencana belum dibaca (maks 99+); layar lebar = panel melayang, ponsel = lembar bawah.
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { Bell, CheckCheck, X } from 'lucide-vue-next'
import NotificationList from '@/components/common/NotificationList/index.vue'
import { useNotificationStore } from '@/stores/notifications'

const store = useNotificationStore()
const route = useRoute()
const open = ref(false)
const badge = computed(() => (store.unread ? (store.unread > 99 ? '99+' : String(store.unread)) : null))

const onKey = (e) => e.key === 'Escape' && (open.value = false)
watch(open, (v) => (v ? document.addEventListener('keydown', onKey) : document.removeEventListener('keydown', onKey)))
watch(() => route.fullPath, () => (open.value = false))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <button
    type="button" class="relative grid size-10 flex-none cursor-pointer place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg"
    :aria-label="badge ? `Notifikasi, ${badge} belum dibaca` : 'Notifikasi'" :aria-expanded="open" aria-haspopup="dialog" @click="open = !open"
  >
    <Bell class="size-5" aria-hidden="true" />
    <span v-if="badge" class="absolute top-1 right-0.5 h-4 min-w-4 rounded-full bg-accent px-1 text-center font-mono text-[10px] leading-4 font-semibold text-accent-ink">{{ badge }}</span>
  </button>

  <Teleport to="body">
    <template v-if="open">
      <div class="fixed inset-0 z-50 bg-scrim lg:bg-transparent" aria-hidden="true" @click="open = false"></div>
      <section
        role="dialog" aria-label="Notifikasi"
        class="fixed inset-x-0 bottom-0 z-50 grid max-h-[85dvh] animate-rise grid-rows-[auto_minmax(0,1fr)_auto] rounded-t-lg border-t border-line-strong bg-surface pb-[env(safe-area-inset-bottom)] lg:inset-x-auto lg:top-4 lg:bottom-auto lg:left-[256px] lg:max-h-[min(640px,85vh)] lg:w-[400px] lg:rounded-lg lg:border lg:pb-0"
      >
        <header class="flex items-center gap-2 border-b border-line px-4 py-3">
          <h2 class="flex-1 font-display text-lg font-semibold">Notifikasi</h2>
          <button
            v-if="store.unread" type="button" class="flex cursor-pointer items-center gap-1.5 rounded-sm px-2 py-1.5 text-sm text-accent-text hover:bg-surface-2"
            @click="store.markAllRead()"
          ><CheckCheck class="size-4" /> Tandai semua terbaca</button>
          <button type="button" class="grid size-9 cursor-pointer place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg" aria-label="Tutup" @click="open = false"><X class="size-5" /></button>
        </header>
        <div class="overflow-y-auto overscroll-contain p-2"><NotificationList compact @navigate="open = false" /></div>
        <footer class="border-t border-line px-4 py-2.5 text-center">
          <RouterLink to="/notifications" class="text-sm no-underline hover:underline" @click="open = false">Lihat semua notifikasi</RouterLink>
        </footer>
      </section>
    </template>
  </Teleport>
</template>
