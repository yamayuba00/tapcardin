import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { billingApi } from '@/api/workspace'
import { payState, secondsLeft } from '@/lib/countdown'

/**
 * Pantau satu tagihan: hitung mundur ke `expires_at` (jam server) dan — bila `poll()` true — tanya status ke gateway
 * tiap 4 detik selama masih menunggu. Berhenti saat lunas/kedaluwarsa/keluar halaman; jeda saat tab tidak terlihat.
 * @param {import('vue').Ref<object|null>} inv invoice (diperbarui di tempat)
 * @param {() => boolean} poll
 */
export function usePaymentWatch(inv, poll = () => true) {
  const now = ref(Date.now())
  let fetchedAt = Date.now()
  watch(() => inv.value?.server_now, () => (fetchedAt = Date.now()))

  const left = computed(() => (inv.value ? secondsLeft(inv.value.expires_at, inv.value.server_now, fetchedAt, now.value) : null))
  const state = computed(() => (inv.value ? payState(inv.value.status, left.value) : null))

  const tick = setInterval(() => (now.value = Date.now()), 1000)
  let busy = false
  const timer = setInterval(async () => {
    if (busy || document.hidden || state.value !== 'pending' || !poll()) return
    busy = true
    try {
      inv.value = { ...inv.value, ...(await billingApi.check(inv.value.id)).data }
    } catch { /* coba lagi di putaran berikutnya */ } finally {
      busy = false
    }
  }, 4000)
  onBeforeUnmount(() => {
    clearInterval(tick)
    clearInterval(timer)
  })

  return { left, state }
}
