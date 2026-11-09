import { onBeforeUnmount, onMounted, ref } from 'vue'

const THRESHOLD = 70 // px tarikan (setelah redaman) untuk memicu refresh
const MAX = 110

/**
 * Tarik ke bawah untuk memuat ulang (ponsel / PWA): app shell men-scroll <main>, bukan window, sehingga
 * pull-to-refresh bawaan browser tidak jalan. Aktif hanya saat konten di posisi paling atas & disentuh jari.
 * @param {import('vue').Ref<HTMLElement|null>} el elemen yang scroll
 * @param {() => Promise<void>|void} onRefresh
 */
export function usePullToRefresh(el, onRefresh) {
  const pull = ref(0)
  const refreshing = ref(false)
  let startY = null

  const start = (e) => {
    if (refreshing.value || e.touches.length !== 1 || el.value.scrollTop > 0 || document.documentElement.hasAttribute('data-sheet-open')) return
    startY = e.touches[0].clientY
  }
  const move = (e) => {
    if (startY === null) return
    const dy = e.touches[0].clientY - startY
    if (dy <= 0 || el.value.scrollTop > 0) {
      pull.value = 0
      return
    }
    pull.value = Math.min(MAX, dy * 0.5) // redaman: terasa "berat" seperti aplikasi asli
    if (e.cancelable) e.preventDefault()
  }
  const end = async () => {
    if (startY === null) return
    startY = null
    if (pull.value >= THRESHOLD) {
      refreshing.value = true
      pull.value = THRESHOLD
      try {
        await onRefresh()
      } finally {
        refreshing.value = false
        pull.value = 0
      }
    } else {
      pull.value = 0
    }
  }

  onMounted(() => {
    const node = el.value
    if (!node || !('ontouchstart' in window)) return
    node.addEventListener('touchstart', start, { passive: true })
    node.addEventListener('touchmove', move, { passive: false })
    node.addEventListener('touchend', end)
    node.addEventListener('touchcancel', end)
  })
  onBeforeUnmount(() => {
    const node = el.value
    if (!node) return
    node.removeEventListener('touchstart', start)
    node.removeEventListener('touchmove', move)
    node.removeEventListener('touchend', end)
    node.removeEventListener('touchcancel', end)
  })

  return { pull, refreshing, threshold: THRESHOLD }
}
