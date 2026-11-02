import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Muat ulang data berkala (bawaan tiap 60 detik) + tombol "Muat ulang". Berhenti saat tab tidak terlihat
 * dan langsung menyegarkan begitu pengguna kembali bila sudah lewat satu periode.
 * @param {(opts: {silent: boolean}) => Promise<unknown>} fn
 * @param {number} [every] milidetik
 */
export function useAutoRefresh(fn, every = 60_000) {
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
  })
  onBeforeUnmount(() => {
    clearInterval(timer)
    document.removeEventListener('visibilitychange', onVisible)
  })

  /** Tandai data baru dimuat dari luar (mis. ganti filter) agar jam "diperbarui" ikut maju. */
  const touch = () => (lastAt.value = new Date())

  return { lastAt, refreshing, refresh: () => refresh(false), touch, every }
}
