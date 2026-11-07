import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useNotificationStore } from '@/stores/notifications'

/**
 * Muat ulang data berkala (bawaan tiap 60 detik) + tombol "Muat ulang". Berhenti saat tab tidak terlihat
 * dan langsung menyegarkan begitu pengguna kembali bila sudah lewat satu periode.
 * `on` = jenis notifikasi realtime (Reverb/SSE) yang langsung memicu muat ulang, mis. ['lead_new'];
 * beberapa kejadian beruntun digabung (jeda 1,5 detik) agar tidak memuat berkali-kali.
 * @param {(opts: {silent: boolean}) => Promise<unknown>} fn
 * @param {number} [every] milidetik
 * @param {{on?: string[]}} [opts]
 */
export function useAutoRefresh(fn, every = 60_000, { on = [] } = {}) {
  const notifications = useNotificationStore()
  let unsubscribe = null
  let debounce = null
  const lastAt = ref(new Date())
  const refreshing = ref(false)
  let timer = null

  async function refresh(silent = false) {
    if (refreshing.value) return
    refreshing.value = true
    try {
      await fn({ silent })
      lastAt.value = new Date()
    } catch {
      // gagal sementara (offline): coba lagi di putaran berikutnya
    } finally {
      refreshing.value = false
    }
  }

  function onVisible() {
    if (document.visibilityState === 'visible' && Date.now() - lastAt.value.getTime() >= every) refresh(true)
  }

  onMounted(() => {
    timer = setInterval(() => document.visibilityState === 'visible' && refresh(true), every)
    document.addEventListener('visibilitychange', onVisible)
    if (on.length) {
      unsubscribe = notifications.subscribe((type) => {
        if (!on.includes(type)) return
        clearTimeout(debounce)
        debounce = setTimeout(() => refresh(true), 1500)
      })
    }
  })
  onBeforeUnmount(() => {
    clearInterval(timer)
    clearTimeout(debounce)
    unsubscribe?.()
    document.removeEventListener('visibilitychange', onVisible)
  })
  /** Langsung diperbarui saat ada kejadian (realtime tersambung) — untuk teks status. */
  const live = computed(() => on.length > 0 && notifications.mode === 'realtime')

  /** Tandai data baru dimuat dari luar (mis. ganti filter) agar jam "diperbarui" ikut maju. */
  const touch = () => (lastAt.value = new Date())

  return { lastAt, refreshing, refresh: () => refresh(false), touch, every, live }
}
