import { onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave, useRouter } from 'vue-router'

/**
 * Cegah keluar dari editor saat ada perubahan belum disimpan, dengan dialog aplikasi (LeaveConfirm) — bukan
 * window.confirm. Navigasi ditahan, tujuan disimpan di `pending`; `leave()` melanjutkan ke sana.
 * Menutup tab / reload tetap memakai peringatan bawaan browser (tidak bisa didesain).
 * @param {() => boolean} isDirty
 */
export function useLeaveGuard(isDirty) {
  const router = useRouter()
  const pending = ref(null) // fullPath tujuan yang sedang ditahan
  let bypass = false

  onBeforeRouteLeave((to) => {
    if (bypass || !isDirty()) return true
    pending.value = to.fullPath
    return false
  })

  function stay() {
    pending.value = null
  }
  async function leave() {
    const to = pending.value
    pending.value = null
    if (!to) return
    bypass = true
    try {
      await router.push(to)
    } finally {
      bypass = false
    }
  }

  const onUnload = (e) => { if (isDirty()) e.preventDefault() }
  onMounted(() => window.addEventListener('beforeunload', onUnload))
  onBeforeUnmount(() => window.removeEventListener('beforeunload', onUnload))

  return { pending, stay, leave }
}
