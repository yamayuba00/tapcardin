import { ref } from 'vue'
import { useToastStore } from '@/stores/toast'

/**
 * Status muat data standar untuk halaman: `data`, `loading`, `error`, `reload()`.
 * @template T
 * @param {() => Promise<{data: T}>} fn
 * @param {{immediate?: boolean}} [opts]
 */
export function useAsync(fn, { immediate = true } = {}) {
  const data = ref(null)
  const meta = ref(null)
  const loading = ref(false)
  const error = ref(null)

  async function reload() {
    loading.value = true
    error.value = null
    try {
      const res = await fn()
      data.value = res?.data ?? null
      meta.value = res?.meta ?? null
    } catch (e) {
      error.value = e
    } finally {
      loading.value = false
    }
    return data.value
  }

  if (immediate) reload()
  return { data, meta, loading, error, reload }
}

/**
 * Jalankan aksi dengan status `busy` dan toast otomatis.
 * @param {string} [okMessage]
 */
export function useAction() {
  const busy = ref(false)
  const toast = useToastStore()

  async function run(fn, okMessage) {
    busy.value = true
    try {
      const res = await fn()
      if (okMessage) toast.ok(okMessage)
      return res
    } catch (e) {
      toast.error(e.message)
      throw e
    } finally {
      busy.value = false
    }
  }
  return { busy, run }
}
